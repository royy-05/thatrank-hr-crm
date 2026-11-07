<?php
// app/models/UserSession.php

namespace App\Models;

use App\Config\Database;
use PDO;

class UserSession {
    public static function parseUserAgent(?string $ua): array {
        $ua = $ua ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
        $browser = 'Unknown Browser';
        $os = 'Unknown OS';

        // Detect OS
        if (preg_match('/windows|win32|win64/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/macintosh|mac os x/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/iphone|ipad|ipod/i', $ua)) {
            $os = 'iOS';
        } elseif (preg_match('/linux/i', $ua)) {
            $os = 'Linux';
        }

        // Detect Browser
        if (preg_match('/edg\//i', $ua)) {
            $browser = 'Microsoft Edge';
        } elseif (preg_match('/chrome|crios/i', $ua) && !preg_match('/opr|opera/i', $ua)) {
            $browser = 'Google Chrome';
        } elseif (preg_match('/firefox|fxios/i', $ua)) {
            $browser = 'Mozilla Firefox';
        } elseif (preg_match('/safari/i', $ua) && !preg_match('/chrome|crios/i', $ua)) {
            $browser = 'Apple Safari';
        } elseif (preg_match('/opera|opr/i', $ua)) {
            $browser = 'Opera';
        }

        return ['browser' => $browser, 'os' => $os];
    }

    public static function createOrUpdate(int $userId, string $sessionId, ?string $screen = null): array {
        $db = Database::getConnection();
        $parsed = self::parseUserAgent($_SERVER['HTTP_USER_AGENT'] ?? null);
        $browser = $parsed['browser'];
        $os = $parsed['os'];
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';

        // Check if user has previously signed in from this browser and OS
        $check = $db->prepare("
            SELECT id FROM user_sessions 
            WHERE user_id = :uid AND browser = :b AND os = :o
            LIMIT 1
        ");
        $check->execute([':uid' => $userId, ':b' => $browser, ':o' => $os]);
        $hasPrior = (bool)$check->fetch();
        $isNew = $hasPrior ? 0 : 1;

        // Insert new session or update existing session_id
        $stmt = $db->prepare("
            INSERT INTO user_sessions (user_id, session_id, browser, os, screen, ip, is_new, last_seen_at, created_at)
            VALUES (:uid, :sid, :browser, :os, :screen, :ip, :is_new, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                browser = VALUES(browser),
                os = VALUES(os),
                screen = COALESCE(VALUES(screen), screen),
                ip = VALUES(ip),
                last_seen_at = NOW()
        ");
        $stmt->execute([
            ':uid'     => $userId,
            ':sid'     => $sessionId,
            ':browser' => $browser,
            ':os'      => $os,
            ':screen'  => $screen,
            ':ip'      => $ip,
            ':is_new'  => $isNew,
        ]);

        return [
            'browser' => $browser,
            'os'      => $os,
            'ip'      => $ip,
            'is_new'  => (bool)$isNew,
        ];
    }

    public static function updateLastSeen(string $sessionId): void {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE user_sessions SET last_seen_at = NOW() WHERE session_id = :sid");
        $stmt->execute([':sid' => $sessionId]);
    }

    public static function getActiveSessions(int $userId, string $currentSessionId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT id, session_id, browser, os, screen, ip, is_new, 
                   DATE_FORMAT(last_seen_at, '%d %b %Y, %H:%i') as last_seen_formatted,
                   last_seen_at, created_at
            FROM user_sessions
            WHERE user_id = :uid
            ORDER BY last_seen_at DESC
        ");
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $result = [];
        foreach ($rows as $r) {
            $r['is_current'] = ($r['session_id'] === $currentSessionId);
            $r['is_new'] = (bool)$r['is_new'];
            // Do not expose raw session_id
            unset($r['session_id']);
            $result[] = $r;
        }
        return $result;
    }

    public static function signOutOthers(int $userId, string $currentSessionId): int {
        $db = Database::getConnection();
        $stmt = $db->prepare("DELETE FROM user_sessions WHERE user_id = :uid AND session_id != :sid");
        $stmt->execute([':uid' => $userId, ':sid' => $currentSessionId]);
        return $stmt->rowCount();
    }

    public static function isValidSession(int $userId, string $sessionId): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT id FROM user_sessions WHERE user_id = :uid AND session_id = :sid LIMIT 1");
        $stmt->execute([':uid' => $userId, ':sid' => $sessionId]);
        return (bool)$stmt->fetch();
    }
}

