<?php
// app/controllers/AuthController.php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\User;
use App\Models\UserSession;
use App\Services\AuditService;
use App\Config\Database;

class AuthController {
    public static function login(): void {
        AuthMiddleware::requireCsrf();

        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($email === '' || $password === '') {
            jsonResponse(['success' => false, 'error' => 'Please provide both email and password.'], 400);
        }

        $user = User::authenticate($email, $password);
        if (!$user) {
            jsonResponse(['success' => false, 'error' => 'Invalid email or password.'], 401);
        }

        // Regenerate session id to protect against session fixation
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user'] = $user;

        $screen = trim($_POST['screen'] ?? '');
        $deviceInfo = UserSession::createOrUpdate((int)$user['id'], session_id(), $screen ?: null);

        AuditService::log($user['id'], 'user_login', 'auth', $user['id'], null, [
            'email'   => $email,
            'browser' => $deviceInfo['browser'],
            'os'      => $deviceInfo['os'],
            'is_new'  => $deviceInfo['is_new'],
        ]);

        jsonResponse([
            'success'    => true,
            'user'       => $user,
            'device'     => $deviceInfo,
            'csrf_token' => getCsrfToken(),
            'message'    => 'Signed in successfully.'
        ]);
    }

    public static function logout(): void {
        $user = AuthMiddleware::user();
        if ($user) {
            AuditService::log($user['id'], 'user_logout', 'auth', $user['id'], null, ['email' => $user['email']]);
        }

        $sid = session_id();
        if ($user && $sid) {
            $db = Database::getConnection();
            $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = :uid AND session_id = :sid");
            $stmt->execute([':uid' => $user['id'], ':sid' => $sid]);
        }

        $_SESSION = [];
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
        session_destroy();

        jsonResponse(['success' => true, 'message' => 'Signed out successfully.']);
    }

    public static function devices(): void {
        $user = AuthMiddleware::requireLogin();
        $sessions = UserSession::getActiveSessions((int)$user['id'], session_id());
        jsonResponse([
            'success'  => true,
            'sessions' => $sessions,
        ]);
    }

    public static function signOutOthers(): void {
        AuthMiddleware::requireCsrf();
        $user = AuthMiddleware::requireLogin();
        $count = UserSession::signOutOthers((int)$user['id'], session_id());
        AuditService::log((int)$user['id'], 'signout_other_devices', 'auth', (int)$user['id'], null, [
            'revoked_count' => $count
        ]);
        jsonResponse([
            'success' => true,
            'count'   => $count,
            'message' => 'Signed out of other devices successfully.'
        ]);
    }

    public static function me(): void {
        $user = AuthMiddleware::refreshUser();
        if (!$user) {
            jsonResponse(['success' => true, 'authenticated' => false, 'csrf_token' => getCsrfToken()], 200);
        }

        jsonResponse([
            'success'       => true,
            'authenticated' => true,
            'user'          => $user,
            'csrf_token'    => getCsrfToken(),
        ]);
    }

    public static function consent(): void {
        AuthMiddleware::requireCsrf();
        $user = AuthMiddleware::requireLogin();

        User::recordConsent((int)$user['id']);
        AuthMiddleware::refreshUser();

        jsonResponse(['success' => true, 'message' => 'Consent policy accepted.']);
    }

    public static function forgotPassword(): void {
        AuthMiddleware::requireCsrf();
        $email = trim($_POST['email'] ?? '');
        if ($email === '') {
            jsonResponse(['success' => false, 'error' => 'Please enter your work email.'], 400);
        }

        $user = User::findByEmail($email);
        if ($user) {
            $token = bin2hex(random_bytes(24));
            $db = \App\Config\Database::getConnection();
            $stmt = $db->prepare("INSERT INTO password_resets (email, token, expires_at) VALUES (:email, :token, DATE_ADD(NOW(), INTERVAL 1 HOUR))");
            $stmt->execute([':email' => $email, ':token' => $token]);

            $resetLink = ($_ENV['APP_URL'] ?? 'http://localhost') . "/?action=reset_password&token={$token}";
            \App\Services\AlertService::sendEmail(
                $email,
                "Password Reset - ThatRank HR",
                "<p>Hello {$user['name']},</p><p>Use the following link to reset your password: <a href='{$resetLink}'>{$resetLink}</a></p><p>This link expires in 1 hour.</p>"
            );
        }

        jsonResponse(['success' => true, 'message' => 'If this email exists in our records, a reset link has been sent.']);
    }

    public static function changePassword(): void {
        AuthMiddleware::requireCsrf();
        $user = AuthMiddleware::requireLogin();
        $userId = (int)$user['id'];

        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($newPassword === '' || strlen($newPassword) < 8) {
            jsonResponse(['success' => false, 'error' => 'New password must be at least 8 characters long.'], 400);
        }

        if ($newPassword !== $confirmPassword) {
            jsonResponse(['success' => false, 'error' => 'New password and confirmation do not match.'], 400);
        }

        $db = \App\Config\Database::getConnection();
        $stmt = $db->prepare("SELECT password_hash FROM users WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $userId]);
        $hash = $stmt->fetchColumn();

        if (!$hash || !password_verify($currentPassword, $hash)) {
            jsonResponse(['success' => false, 'error' => 'Current password is incorrect.'], 400);
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $up = $db->prepare("UPDATE users SET password_hash = :hash, must_change_password = 0 WHERE id = :id");
        $up->execute([':hash' => $newHash, ':id' => $userId]);

        \App\Services\AuditService::log($userId, 'password_change', 'user', $userId, ['must_change_password' => 1], ['must_change_password' => 0]);

        if (isset($_SESSION['user'])) {
            $_SESSION['user']['must_change_password'] = 0;
        }

        jsonResponse(['success' => true, 'message' => 'Password updated successfully.']);
    }
}
