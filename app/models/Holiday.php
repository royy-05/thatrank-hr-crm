<?php
// app/models/Holiday.php

namespace App\Models;

use App\Config\Database;
use App\Services\AuditService;
use PDO;

class Holiday {
    public static function getNextHoliday(): ?array {
        $db = Database::getConnection();
        $today = date('Y-m-d');
        $stmt = $db->prepare("
            SELECT * FROM holidays
            WHERE date >= :today
            ORDER BY date ASC
            LIMIT 1
        ");
        $stmt->execute([':today' => $today]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function getAllForYear(?int $year = null): array {
        $db = Database::getConnection();
        $year = $year ?: (int)date('Y');
        $stmt = $db->prepare("SELECT *, DATE_FORMAT(date, '%W, %d %M %Y') as formatted_date FROM holidays WHERE year = :yr ORDER BY date ASC");
        $stmt->execute([':yr' => $year]);
        return $stmt->fetchAll();
    }

    public static function findById(int $id): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM holidays WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public static function create(array $data, int $adminId): int {
        $db = Database::getConnection();
        $year = (int)date('Y', strtotime($data['date']));
        $stmt = $db->prepare("
            INSERT INTO holidays (name, date, year, is_optional, created_at)
            VALUES (:name, :date, :year, :opt, NOW())
        ");
        $stmt->execute([
            ':name' => trim($data['name']),
            ':date' => $data['date'],
            ':year' => $year,
            ':opt'  => !empty($data['is_optional']) ? 1 : 0,
        ]);
        $id = (int)$db->lastInsertId();

        AuditService::log($adminId, 'create_holiday', 'holiday', $id, null, [
            'name'        => $data['name'],
            'date'        => $data['date'],
            'is_optional' => !empty($data['is_optional']),
        ]);

        return $id;
    }

    public static function update(int $id, array $data, int $adminId): bool {
        $db = Database::getConnection();
        $old = self::findById($id);
        if (!$old) return false;

        $year = (int)date('Y', strtotime($data['date']));
        $stmt = $db->prepare("
            UPDATE holidays 
            SET name = :name, date = :date, year = :year, is_optional = :opt
            WHERE id = :id
        ");
        $success = $stmt->execute([
            ':name' => trim($data['name']),
            ':date' => $data['date'],
            ':year' => $year,
            ':opt'  => !empty($data['is_optional']) ? 1 : 0,
            ':id'   => $id,
        ]);

        if ($success) {
            $new = self::findById($id);
            AuditService::log($adminId, 'update_holiday', 'holiday', $id, $old, $new);
        }

        return $success;
    }

    public static function delete(int $id, int $adminId): bool {
        $db = Database::getConnection();
        $old = self::findById($id);
        if (!$old) return false;

        $stmt = $db->prepare("DELETE FROM holidays WHERE id = :id");
        $success = $stmt->execute([':id' => $id]);

        if ($success) {
            AuditService::log($adminId, 'delete_holiday', 'holiday', $id, $old, null);
        }

        return $success;
    }
}
