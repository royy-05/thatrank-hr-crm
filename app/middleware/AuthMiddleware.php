<?php
// app/middleware/AuthMiddleware.php

namespace App\Middleware;

use App\Config\Database;
use PDO;

class AuthMiddleware {
    /**
     * Get the authenticated user from session.
     */
    public static function user(): ?array {
        if (!empty($_SESSION['user_id'])) {
            return $_SESSION['user'] ?? null;
        }
        return null;
    }

    /**
     * Refresh user in session from DB.
     */
    public static function refreshUser(): ?array {
        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id, name, email, role, job_title, department, manager_id, avatar_initials, avatar_color, status, consent_accepted_at FROM users WHERE id = :id AND status = 'active' LIMIT 1");
        $stmt->execute([':id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            $sid = session_id();
            if ($sid !== '') {
                // If sessions exist in user_sessions table for this user, check validity
                $hasSession = \App\Models\UserSession::isValidSession((int)$user['id'], $sid);
                // If it was signed out from other device
                $hasAny = (bool)$db->query("SELECT 1 FROM user_sessions WHERE user_id = " . (int)$user['id'] . " LIMIT 1")->fetch();
                if ($hasAny && !$hasSession) {
                    unset($_SESSION['user_id'], $_SESSION['user']);
                    return null;
                }
                \App\Models\UserSession::updateLastSeen($sid);
            }
            $_SESSION['user'] = $user;
            return $user;
        }
        // If user no longer exists or inactive, clear session
        unset($_SESSION['user_id'], $_SESSION['user']);
        return null;
    }

    /**
     * Enforce authentication. Returns current user or sends 401 / redirects.
     */
    public static function requireLogin(bool $isApi = true): array {
        $user = self::user();
        if (!$user) {
            if ($isApi) {
                jsonResponse(['success' => false, 'error' => 'Authentication required.'], 401);
            } else {
                header('Location: /?action=login');
                exit;
            }
        }
        return $user;
    }

    /**
     * Enforce CSRF token on all POST / state-changing requests.
     */
    public static function requireCsrf(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
            if (!$token && str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
                $raw = json_decode(file_get_contents('php://input'), true);
                $token = $raw['csrf_token'] ?? null;
            }
            if (!verifyCsrfToken($token)) {
                jsonResponse(['success' => false, 'error' => 'Invalid or expired CSRF security token.'], 403);
            }
        }
    }

    /**
     * Check if user has one of the allowed roles.
     */
    public static function requireRole(array $allowedRoles, bool $isApi = true): array {
        $user = self::requireLogin($isApi);
        if (!in_array($user['role'], $allowedRoles, true)) {
            if ($isApi) {
                jsonResponse(['success' => false, 'error' => 'Forbidden: insufficient role privileges.'], 403);
            } else {
                http_response_code(403);
                echo "Access Denied.";
                exit;
            }
        }
        return $user;
    }

    /**
     * Check if user is an admin.
     */
    public static function requireAdmin(bool $isApi = true): array {
        return self::requireRole(['admin'], $isApi);
    }

    /**
     * Check if user is a manager or admin.
     */
    public static function requireManagerOrAdmin(bool $isApi = true): array {
        return self::requireRole(['manager', 'admin'], $isApi);
    }

    /**
     * Check if user has a specific granular permission.
     */
    public static function requirePermission(string $permissionKey, bool $isApi = true): array {
        $user = self::requireLogin($isApi);
        if (!\App\Models\RolePermission::canUser($user, $permissionKey)) {
            if ($isApi) {
                jsonResponse(['success' => false, 'error' => 'Forbidden: permission denied (' . $permissionKey . ').'], 403);
            } else {
                http_response_code(403);
                echo "Access Denied.";
                exit;
            }
        }
        return $user;
    }

    /**
     * Centralized check: Can viewer access target user's records (attendance, selfies, corrections, leave)?
     * - Admin: all users.
     * - Manager: own records, or users whose manager_id is viewer's id.
     * - Employee: own records only.
     */
    public static function canAccessUser(array $viewer, int $targetUserId): bool {
        if ($viewer['role'] === 'admin') {
            return true;
        }

        if ((int)$viewer['id'] === $targetUserId) {
            return true;
        }

        if ($viewer['role'] === 'manager') {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT COUNT(*) FROM users WHERE id = :target_id AND manager_id = :manager_id");
            $stmt->execute([
                ':target_id'  => $targetUserId,
                ':manager_id' => $viewer['id']
            ]);
            return (int)$stmt->fetchColumn() > 0;
        }

        return false;
    }

    /**
     * Resolve an attendance or leave view to the user IDs the viewer may read,
     * sending a 403 when the scope or filter is outside what their role allows.
     * - me: the viewer.
     * - team: a manager's direct reports (everyone, for an admin).
     * - all: everyone active. Admin only.
     */
    public static function resolveScopeUserIds(array $viewer, string $scope, ?int $userId = null, ?int $teamId = null): array {
        $role = $viewer['role'] ?? 'employee';
        $db = Database::getConnection();

        if ($scope === 'me') {
            if ($userId !== null && $userId !== (int)$viewer['id']) {
                jsonResponse(['success' => false, 'error' => 'Forbidden: access denied.'], 403);
            }
            return [(int)$viewer['id']];
        }

        $isAdmin = $role === 'admin';
        $isManagerTeam = $role === 'manager' && $scope === 'team';
        if (!in_array($scope, ['team', 'all'], true) || (!$isAdmin && !$isManagerTeam)) {
            jsonResponse(['success' => false, 'error' => 'Forbidden: access denied.'], 403);
        }

        if ($isAdmin) {
            if ($userId !== null) {
                return [$userId];
            }
            if ($teamId !== null) {
                $stmt = $db->prepare("SELECT id FROM users WHERE team_id = :tid AND status = 'active'");
                $stmt->execute([':tid' => $teamId]);
            } else {
                $stmt = $db->query("SELECT id FROM users WHERE status = 'active'");
            }
            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        }

        $stmt = $db->prepare("SELECT id FROM users WHERE manager_id = :mid AND status = 'active'");
        $stmt->execute([':mid' => $viewer['id']]);
        $reports = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

        if ($userId === null) {
            return $reports;
        }
        if (!in_array($userId, $reports, true)) {
            jsonResponse(['success' => false, 'error' => 'Forbidden: user not in your team.'], 403);
        }
        return [$userId];
    }

    /**
     * Return list of accessible user IDs for viewer.
     */
    public static function getAccessibleUserIds(array $viewer): array {
        $db = Database::getConnection();
        if ($viewer['role'] === 'admin') {
            $stmt = $db->query("SELECT id FROM users WHERE status != 'offboarded'");
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        if ($viewer['role'] === 'manager') {
            $stmt = $db->prepare("SELECT id FROM users WHERE (id = :mgr_id1 OR manager_id = :mgr_id2) AND status != 'offboarded'");
            $stmt->execute([
                ':mgr_id1' => $viewer['id'],
                ':mgr_id2' => $viewer['id']
            ]);
            return $stmt->fetchAll(PDO::FETCH_COLUMN);
        }

        // Employee sees only themselves
        return [(int)$viewer['id']];
    }
}
