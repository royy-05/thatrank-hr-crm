<?php
// cron/dispatch_notifications.php
// Runs every minute via Hostinger hPanel cron or Linux crontab: * * * * *

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Config\Database;
use App\Services\AlertService;

echo "[" . date('Y-m-d H:i:s') . "] Starting notification dispatcher...\n";

$db = Database::getConnection();

// Pick pending rows due now (up to 50 per minute)
$stmt = $db->prepare("
    SELECT q.*, u.name as user_name, u.email as user_email
    FROM notifications_queue q
    LEFT JOIN users u ON q.user_id = u.id
    WHERE q.status = 'pending' AND q.next_try_at <= NOW()
    ORDER BY q.created_at ASC
    LIMIT 50
");
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($items)) {
    echo "No pending notifications due.\n";
    exit(0);
}

$sentCount = 0;
$retryCount = 0;
$failedCount = 0;

foreach ($items as $item) {
    $itemId = (int)$item['id'];
    $attempts = (int)$item['attempts'] + 1;
    $payload = json_decode($item['payload'], true) ?: [];

    $postData = [
        'event'     => $item['event'],
        'user'      => [
            'id'    => $item['user_id'],
            'name'  => $item['user_name'],
            'email' => $item['user_email'],
        ],
        'details'   => $payload,
        'timestamp' => date('Y-m-d H:i:s'),
    ];

    $success = true;
    $errorMsg = null;

    try {
        // 1. Post to n8n webhook if URL is configured
        $webhookUrl = $_ENV['N8N_WEBHOOK_URL'] ?? null;
        if (!empty($webhookUrl)) {
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($postData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 6,
            ]);
            $res = curl_exec($ch);
            $err = curl_error($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($err || ($httpCode >= 400 && $httpCode !== 0)) {
                $success = false;
                $errorMsg = "n8n error: " . ($err ?: "HTTP $httpCode");
            }
        }

        // 2. Send email notification if relevant
        if ($success && !empty($item['user_email'])) {
            if ($item['event'] === 'pending_approval') {
                $subject = "ThatRank HR: Leave or correction pending your approval";
                $body = "<p>Hello {$item['user_name']},</p><p>You have a new request pending review. Please log in to ThatRank HR to review and take action.</p>";
                AlertService::sendEmail($item['user_email'], $subject, $body);
            } elseif ($item['event'] === 'approval_result') {
                $status = htmlspecialchars($payload['status'] ?? 'processed');
                $subject = "ThatRank HR: Your request has been {$status}";
                $body = "<p>Hello {$item['user_name']},</p><p>Your request has been {$status}. Log in to view details.</p>";
                AlertService::sendEmail($item['user_email'], $subject, $body);
            }
        }
    } catch (\Throwable $e) {
        $success = false;
        $errorMsg = $e->getMessage();
    }

    if ($success) {
        $up = $db->prepare("
            UPDATE notifications_queue 
            SET status = 'sent', sent_at = NOW(), attempts = :att, error_message = NULL
            WHERE id = :id
        ");
        $up->execute([':att' => $attempts, ':id' => $itemId]);
        $sentCount++;
    } else {
        if ($attempts >= 5) {
            $up = $db->prepare("
                UPDATE notifications_queue 
                SET status = 'failed', attempts = :att, error_message = :err
                WHERE id = :id
            ");
            $up->execute([':att' => $attempts, ':err' => $errorMsg, ':id' => $itemId]);
            $failedCount++;
        } else {
            // Exponential backoff: 2m, 4m, 6m, 8m
            $backoffMinutes = $attempts * 2;
            $up = $db->prepare("
                UPDATE notifications_queue 
                SET attempts = :att, 
                    next_try_at = DATE_ADD(NOW(), INTERVAL :mins MINUTE),
                    error_message = :err
                WHERE id = :id
            ");
            $up->execute([
                ':att'  => $attempts,
                ':mins' => $backoffMinutes,
                ':err'  => $errorMsg,
                ':id'   => $itemId,
            ]);
            $retryCount++;
        }
    }
}

echo "Dispatched: {$sentCount} sent, {$retryCount} queued for retry, {$failedCount} failed.\n";

