<?php
// app/models/Setting.php

namespace App\Models;

use App\Config\Database;
use App\Services\AuditService;
use PDO;

class Setting {
    public static function getAllAsMap(): array {
        $db = Database::getConnection();
        $stmt = $db->query("SELECT setting_key, setting_value FROM company_settings");
        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    public static function get(string $key, $default = null) {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT setting_value FROM company_settings WHERE setting_key = :k LIMIT 1");
        $stmt->execute([':k' => $key]);
        $val = $stmt->fetchColumn();
        return $val !== false ? $val : $default;
    }

    public static function updateBatch(array $newSettings, int $actorId): bool {
        $db = Database::getConnection();
        $old = self::getAllAsMap();

        $stmt = $db->prepare("
            INSERT INTO company_settings (setting_key, setting_value, updated_at)
            VALUES (:k, :v, NOW())
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
        ");

        $changedOld = [];
        $changedNew = [];

        foreach ($newSettings as $k => $v) {
            $vStr = (string)$v;
            $oldVal = $old[$k] ?? null;
            if ($oldVal !== $vStr) {
                $changedOld[$k] = $oldVal;
                $changedNew[$k] = $vStr;
                $stmt->execute([':k' => $k, ':v' => $vStr]);
            }
        }

        if (!empty($changedNew)) {
            AuditService::log($actorId, 'settings_update', 'settings', 1, $changedOld, $changedNew);
        }

        return true;
    }
}
