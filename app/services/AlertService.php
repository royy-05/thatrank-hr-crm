<?php
// app/services/AlertService.php

namespace App\Services;

use App\Config\Database;
use PDO;

class AlertService {
    /**
     * Enqueue a notification into notifications_queue.
     * Never calls n8n or SMTP inline during web requests.
     */
    public static function enqueue(string $event, ?int $userId, array $payload): int {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO notifications_queue (event, user_id, payload, status, attempts, next_try_at, created_at)
            VALUES (:ev, :uid, :payload, 'pending', 0, NOW(), NOW())
        ");
        $stmt->execute([
            ':ev'      => $event,
            ':uid'     => $userId ?: null,
            ':payload' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
        return (int)$db->lastInsertId();
    }

    /**
     * Enqueue late arrival alert.
     */
    public static function lateArrival(int $userId, array $payload): int {
        return self::enqueue('late_arrival', $userId, $payload);
    }

    /**
     * Enqueue pending approval alert for manager.
     */
    public static function pendingApproval(int $managerId, array $payload): int {
        return self::enqueue('pending_approval', $managerId, $payload);
    }

    /**
     * Enqueue approval result alert for employee.
     */
    public static function approvalResult(int $userId, array $payload): int {
        return self::enqueue('approval_result', $userId, $payload);
    }

    /**
     * Enqueue weekly digest alert.
     */
    public static function weeklyDigest(int $userId, array $payload): int {
        return self::enqueue('weekly_digest', $userId, $payload);
    }

    /**
     * Send event payload to n8n webhook on VPS (Called only by background dispatcher cron).
     */
    public static function triggerWebhook(string $event, array $payload): bool {
        $webhookUrl = $_ENV['N8N_WEBHOOK_URL'] ?? null;
        if (empty($webhookUrl)) {
            return false;
        }

        $data = [
            'event'     => $event,
            'timestamp' => date('Y-m-d H:i:s'),
            'payload'   => $payload,
        ];

        $json = json_encode($data);

        $ch = curl_init($webhookUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($err || ($httpCode >= 400 && $httpCode !== 0)) {
            error_log("n8n Webhook failed: " . ($err ?: "HTTP $httpCode"));
            return false;
        }

        return true;
    }

    /**
     * Send email via PHP mail() or configured SMTP (Called only by background dispatcher cron).
     */
    public static function sendEmail(string $to, string $subject, string $bodyHtml): bool {
        $from = $_ENV['SMTP_FROM'] ?? 'no-reply@thatrank.com';
        $fromName = $_ENV['SMTP_NAME'] ?? 'ThatRank HR';

        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            "From: {$fromName} <{$from}>",
            "Reply-To: {$from}",
            'X-Mailer: PHP/' . phpversion(),
        ];

        if (($_ENV['APP_ENV'] ?? 'production') === 'local') {
            error_log("[Local Mail] To: {$to} | Subject: {$subject} | Body: " . substr(strip_tags($bodyHtml), 0, 100));
            return true;
        }

        return mail($to, $subject, $bodyHtml, implode("\r\n", $headers));
    }
}
