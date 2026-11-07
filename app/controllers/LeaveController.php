<?php
// app/controllers/LeaveController.php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\Leave;

class LeaveController {
    public static function balances(): void {
        $viewer = AuthMiddleware::requireLogin();
        $targetUserId = (int)($_GET['user_id'] ?? $viewer['id']);

        if (!AuthMiddleware::canAccessUser($viewer, $targetUserId)) {
            jsonResponse(['success' => false, 'error' => 'Forbidden: access denied.'], 403);
        }

        $year = (int)($_GET['year'] ?? date('Y'));
        $type = trim($_GET['type'] ?? '');
        $status = trim($_GET['status'] ?? '');

        $balances = Leave::getBalances($targetUserId, $year);
        $history = Leave::getHistory($targetUserId, 50, 0, $type ?: null, $status ?: null, $year ?: null);
        $totalCount = Leave::getCount($targetUserId);
        $types = Leave::getLeaveTypes(true);
        $years = Leave::getAvailableYears($targetUserId);

        jsonResponse([
            'success'     => true,
            'balances'    => $balances,
            'history'     => $history,
            'total_count' => $totalCount,
            'types'       => $types,
            'years'       => $years,
        ]);
    }

    /**
     * Leave requests in a scope, for the Leave screen tables.
     */
    public static function requests(): void {
        $viewer = AuthMiddleware::requireLogin();
        $scope = trim($_GET['scope'] ?? 'me');
        $userParam = $_GET['user_id'] ?? '';
        $userId = ($userParam !== '' && $userParam !== 'all') ? (int)$userParam : null;
        $teamId = (int)($_GET['team_id'] ?? 0) ?: null;
        $userIds = AuthMiddleware::resolveScopeUserIds($viewer, $scope, $userId, $teamId);

        $dateParam = function (string $key): ?string {
            $date = trim($_GET[$key] ?? '');
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
        };
        $perPage = min(100, max(1, (int)($_GET['limit'] ?? 5)));
        $page = max(1, (int)($_GET['page'] ?? 1));

        $result = Leave::listRequests($userIds, [
            'type'           => trim($_GET['type'] ?? ''),
            'status'         => trim($_GET['status'] ?? ''),
            'from'           => $dateParam('from'),
            'to'             => $dateParam('to'),
            'year'           => (int)($_GET['year'] ?? 0),
            'search'         => trim($_GET['q'] ?? ''),
            'exclude_drafts' => $scope !== 'me',
        ], $perPage, ($page - 1) * $perPage);

        jsonResponse([
            'success'  => true,
            'requests' => $result['rows'],
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    public static function types(): void {
        AuthMiddleware::requireLogin();
        $types = Leave::getLeaveTypes(true);
        jsonResponse(['success' => true, 'types' => $types]);
    }

    public static function allTypes(): void {
        AuthMiddleware::requireAdmin();
        $types = Leave::getLeaveTypes(false);
        jsonResponse(['success' => true, 'types' => $types]);
    }

    public static function saveType(): void {
        AuthMiddleware::requireCsrf();
        $admin = AuthMiddleware::requireAdmin();

        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = strtolower(trim($_POST['code'] ?? ''));
        $days = (float)($_POST['days_per_year'] ?? $_POST['yearly_quota'] ?? 0.0);
        $isPaid = !empty($_POST['is_paid']) ? 1 : 0;

        if ($name === '' || $code === '') {
            jsonResponse(['success' => false, 'error' => 'Please provide both leave name and code.'], 400);
        }

        $db = \App\Config\Database::getConnection();
        if ($id > 0) {
            $stmt = $db->prepare("
                UPDATE leave_types
                SET name = :name, days_per_year = :days, is_paid = :paid, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':name' => $name, ':days' => $days, ':paid' => $isPaid, ':id' => $id]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO leave_types (name, code, days_per_year, is_paid, is_active, created_at, updated_at)
                VALUES (:name, :code, :days, :paid, 1, NOW(), NOW())
                ON DUPLICATE KEY UPDATE name = VALUES(name), days_per_year = VALUES(days_per_year), is_paid = VALUES(is_paid)
            ");
            $stmt->execute([':name' => $name, ':code' => $code, ':days' => $days, ':paid' => $isPaid]);
        }

        // Sync leave_balances and company_settings for standard quotas
        if ($code === 'casual') {
            $db->prepare("UPDATE leave_balances SET casual_total = :d1, casual_accrued = :d2 WHERE year = YEAR(CURDATE())")->execute([':d1' => $days, ':d2' => $days]);
            $db->prepare("UPDATE company_settings SET setting_value = :d WHERE setting_key = 'casual_leave_quota'")->execute([':d' => (string)$days]);
        } elseif ($code === 'sick') {
            $db->prepare("UPDATE leave_balances SET sick_total = :d1, sick_accrued = :d2 WHERE year = YEAR(CURDATE())")->execute([':d1' => $days, ':d2' => $days]);
            $db->prepare("UPDATE company_settings SET setting_value = :d WHERE setting_key = 'sick_leave_quota'")->execute([':d' => (string)$days]);
        } elseif ($code === 'earned') {
            $db->prepare("UPDATE leave_balances SET earned_total = :d WHERE year = YEAR(CURDATE())")->execute([':d' => $days]);
            $db->prepare("UPDATE company_settings SET setting_value = :d WHERE setting_key = 'earned_leave_quota'")->execute([':d' => (string)$days]);
        } elseif ($code === 'comp_off') {
            $db->prepare("UPDATE leave_balances SET comp_off_total = :d WHERE year = YEAR(CURDATE())")->execute([':d' => $days]);
        }

        jsonResponse(['success' => true, 'message' => 'Leave type saved successfully.']);
    }

    public static function deleteType(): void {
        AuthMiddleware::requireCsrf();
        AuthMiddleware::requireAdmin();

        $id = (int)($_POST['id'] ?? 0);
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Invalid leave type ID.'], 400);
        }

        $db = \App\Config\Database::getConnection();
        $stmt = $db->prepare("DELETE FROM leave_types WHERE id = :id");
        $stmt->execute([':id' => $id]);

        jsonResponse(['success' => true, 'message' => 'Leave type deleted successfully.']);
    }

    public static function toggleType(): void {
        AuthMiddleware::requireCsrf();
        AuthMiddleware::requireAdmin();

        $id = (int)($_POST['id'] ?? 0);
        $isActive = !empty($_POST['is_active']) ? 1 : 0;
        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Invalid leave type ID.'], 400);
        }

        $db = \App\Config\Database::getConnection();
        $stmt = $db->prepare("UPDATE leave_types SET is_active = :act, updated_at = NOW() WHERE id = :id");
        $stmt->execute([':act' => $isActive, ':id' => $id]);

        jsonResponse(['success' => true, 'message' => 'Leave type status updated.']);
    }

    public static function submitCompOff(): void {
        AuthMiddleware::requireCsrf();
        $user = AuthMiddleware::requireLogin();
        self::rejectAdminRequest($user);

        $date = trim($_POST['worked_date'] ?? '');
        $hours = (float)($_POST['hours_worked'] ?? 8.0);
        $reason = trim($_POST['reason'] ?? '');

        if ($date === '' || $reason === '') {
            jsonResponse(['success' => false, 'error' => 'Please provide the worked date and reason.'], 400);
        }

        $id = Leave::submitCompOff((int)$user['id'], $date, $hours, $reason);
        jsonResponse(['success' => true, 'id' => $id, 'message' => 'Comp-off credit request submitted for manager review.']);
    }

    public static function pendingCompOff(): void {
        $viewer = AuthMiddleware::requireManagerOrAdmin();
        $requests = Leave::getPendingCompOff($viewer);
        jsonResponse(['success' => true, 'requests' => $requests]);
    }

    public static function actionCompOff(): void {
        AuthMiddleware::requireCsrf();
        $viewer = AuthMiddleware::requireManagerOrAdmin();
        AuthMiddleware::requirePermission('approve_compoff');

        $id = (int)($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        if (!$id || !in_array($status, ['approved', 'rejected'], true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid request.'], 400);
        }

        $res = Leave::actionCompOff($id, $status, (int)$viewer['id']);
        if (!$res) {
            jsonResponse(['success' => false, 'error' => 'Could not action comp-off request.'], 400);
        }

        jsonResponse(['success' => true, 'message' => "Comp-off request {$status} successfully."]);
    }

    public static function stats(): void {
        $viewer = AuthMiddleware::requireLogin();
        $targetUserId = (int)($_GET['user_id'] ?? $viewer['id']);
        if (!AuthMiddleware::canAccessUser($viewer, $targetUserId)) {
            jsonResponse(['success' => false, 'error' => 'Forbidden.'], 403);
        }

        $year = !empty($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
        $stats = Leave::getStats($targetUserId, $year);

        jsonResponse([
            'success' => true,
            'stats'   => $stats,
        ]);
    }

    public static function submit(): void {
        AuthMiddleware::requireCsrf();
        $user = AuthMiddleware::requireLogin();
        self::rejectAdminRequest($user);

        $data = [
            'leave_type'    => trim($_POST['leave_type'] ?? 'casual'),
            'start_date'    => trim($_POST['start_date'] ?? date('Y-m-d')),
            'end_date'      => trim($_POST['end_date'] ?? date('Y-m-d')),
            'duration_type' => trim($_POST['duration_type'] ?? 'full_day'),
            'reason'        => trim($_POST['reason'] ?? ''),
            'is_draft'      => !empty($_POST['is_draft']),
        ];

        if ($data['reason'] === '') {
            jsonResponse(['success' => false, 'error' => 'Please provide a short reason for your leave.'], 400);
        }

        // Calculate days between start and end
        $start = strtotime($data['start_date']);
        $end = strtotime($data['end_date']);
        if ($end < $start) {
            jsonResponse(['success' => false, 'error' => 'End date cannot be earlier than start date.'], 400);
        }

        $diffDays = 1 + (int)round(($end - $start) / 86400);
        if ($data['duration_type'] === 'half_day') {
            $diffDays = 0.5;
        } elseif ($data['duration_type'] === 'short_leave') {
            $diffDays = 0.25;
        }
        $data['days_count'] = $diffDays;

        // Verify available leave balance if submitting request
        if (empty($data['is_draft'])) {
            $type = $data['leave_type'];
            $year = (int)date('Y', $start);
            $balances = Leave::getBalances((int)$user['id'], $year);

            $db = \App\Config\Database::getConnection();
            $stmt = $db->prepare("SELECT name, days_per_year, is_paid FROM leave_types WHERE code = :code LIMIT 1");
            $stmt->execute([':code' => $type]);
            $typeInfo = $stmt->fetch(\PDO::FETCH_ASSOC);
            $typeName = $typeInfo['name'] ?? ucfirst($type);

            $avail = 0.0;
            if (!empty($balances['cards'])) {
                foreach ($balances['cards'] as $card) {
                    if ($card['code'] === $type) {
                        $avail = (float)($card['available'] ?? 0.0);
                        break;
                    }
                }
            }

            // Deduct pending requests for the same leave type
            $pStmt = $db->prepare("
                SELECT COALESCE(SUM(days_count), 0)
                FROM leave_requests
                WHERE user_id = :uid AND leave_type = :code AND status = 'pending' AND YEAR(start_date) = :yr
            ");
            $pStmt->execute([':uid' => (int)$user['id'], ':code' => $type, ':yr' => $year]);
            $pendingDays = (float)$pStmt->fetchColumn();
            $effectiveAvail = max(0.0, $avail - $pendingDays);

            if ($diffDays > $effectiveAvail) {
                if ($effectiveAvail <= 0) {
                    jsonResponse([
                        'success' => false,
                        'error'   => "Insufficient leave balance. You have 0 available days for {$typeName}."
                    ], 400);
                } else {
                    jsonResponse([
                        'success' => false,
                        'error'   => "Insufficient leave balance. You requested {$diffDays} day(s), but only have {$effectiveAvail} day(s) available for {$typeName}."
                    ], 400);
                }
            }
        }

        $id = Leave::submitRequest((int)$user['id'], $data);

        // Send email alert to Team Manager and Admin(s)
        if (empty($data['is_draft'])) {
            try {
                \App\Services\MailService::notifyLeaveRequest((int)$user['id'], $data);
            } catch (\Throwable $e) {
                error_log("Failed to send leave notification email: " . $e->getMessage());
            }
        }

        jsonResponse([
            'success' => true,
            'id'      => $id,
            'message' => $data['is_draft'] ? 'Leave saved as draft.' : 'Leave request submitted successfully.'
        ]);
    }

    private static function rejectAdminRequest(array $user): void {
        if ($user['role'] === 'admin') {
            jsonResponse(['success' => false, 'error' => 'Admins do not request leave.'], 403);
        }
    }

    public static function withdraw(): void {
        AuthMiddleware::requireCsrf();
        $user = AuthMiddleware::requireLogin();
        $id = (int)($_POST['id'] ?? 0);

        if (!$id) {
            jsonResponse(['success' => false, 'error' => 'Invalid leave request ID.'], 400);
        }

        $res = Leave::withdrawRequest($id, (int)$user['id']);
        if (!$res) {
            jsonResponse(['success' => false, 'error' => 'Unable to withdraw this request.'], 400);
        }

        jsonResponse(['success' => true, 'message' => 'Leave request withdrawn.']);
    }

    public static function pending(): void {
        $viewer = AuthMiddleware::requireManagerOrAdmin();
        $requests = Leave::getPendingRequestsForApprover($viewer);

        jsonResponse(['success' => true, 'requests' => $requests]);
    }

    public static function action(): void {
        AuthMiddleware::requireCsrf();
        $viewer = AuthMiddleware::requireManagerOrAdmin();
        AuthMiddleware::requirePermission('approve_leaves');

        $id = (int)($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (!$id || !in_array($status, ['approved', 'rejected'], true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid request.'], 400);
        }

        // Check if approver is allowed to action this request
        $db = \App\Config\Database::getConnection();
        $stmt = $db->prepare("SELECT user_id FROM leave_requests WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $targetUserId = (int)$stmt->fetchColumn();

        if (!$targetUserId || !AuthMiddleware::canAccessUser($viewer, $targetUserId)) {
            jsonResponse(['success' => false, 'error' => 'Forbidden: not authorized to action this request.'], 403);
        }

        $res = Leave::actionRequest($id, $status, (int)$viewer['id'], $reason ?: null);
        if (!$res) {
            jsonResponse(['success' => false, 'error' => 'Failed to action leave request.'], 400);
        }

        // Notify employee of approval/rejection via email
        try {
            \App\Services\MailService::notifyLeaveDecision($id, $status, (int)$viewer['id'], $reason ?: null);
        } catch (\Throwable $e) {
            error_log("Failed to send leave decision email: " . $e->getMessage());
        }

        jsonResponse(['success' => true, 'message' => "Leave request {$status} successfully."]);
    }
}

