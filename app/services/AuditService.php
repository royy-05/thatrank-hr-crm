<?php
// app/services/AuditService.php

namespace App\Services;

use App\Config\Database;
use PDO;

class AuditService {
    /**
     * Log a state-changing action to audit_log.
     */
    public static function log(?int $userId, string $action, string $targetType, ?int $targetId, $oldValues = null, $newValues = null): void {
        try {
            $db = Database::getConnection();
            $stmt = $db->prepare("
                INSERT INTO audit_log (user_id, action, target_type, target_id, old_values, new_values, ip_address, user_agent, created_at)
                VALUES (:user_id, :action, :target_type, :target_id, :old_values, :new_values, :ip_address, :user_agent, NOW())
            ");

            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? 'CLI/System', 0, 255);

            $oldJson = $oldValues !== null ? json_encode($oldValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
            $newJson = $newValues !== null ? json_encode($newValues, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;

            $stmt->execute([
                ':user_id'     => $userId,
                ':action'      => $action,
                ':target_type' => $targetType,
                ':target_id'   => $targetId,
                ':old_values'  => $oldJson,
                ':new_values'  => $newJson,
                ':ip_address'  => $ip,
                ':user_agent'  => $userAgent,
            ]);
        } catch (\Throwable $e) {
            // Never break main flow if audit logging fails, but log to error log
            error_log("Audit log failure: " . $e->getMessage());
        }
    }
}

