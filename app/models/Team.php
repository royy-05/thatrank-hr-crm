<?php
// app/models/Team.php

namespace App\Models;

use App\Config\Database;
use App\Services\AuditService;
use PDO;

class Team {
    public static function all(): array {
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT t.*, m.name as manager_name,
                   (SELECT COUNT(*) FROM users u WHERE u.team_id = t.id AND u.status = 'active') as members_count
            FROM teams t
            LEFT JOIN users m ON t.manager_id = m.id
            ORDER BY t.name ASC
        ");
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findById(int $id): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM teams WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public static function create(string $name, ?int $managerId, int $adminId): int {
        $db = Database::getConnection();
        $stmt = $db->prepare("INSERT INTO teams (name, manager_id, created_at) VALUES (:name, :mgr, NOW())");
        $stmt->execute([
            ':name' => trim($name),
            ':mgr'  => $managerId ?: null,
        ]);
        $id = (int)$db->lastInsertId();

        AuditService::log($adminId, 'create_team', 'team', $id, null, [
            'name'       => $name,
            'manager_id' => $managerId
        ]);

        return $id;
    }

    public static function update(int $id, string $name, ?int $managerId, int $adminId): bool {
        $db = Database::getConnection();
        $old = self::findById($id);
        if (!$old) return false;

        $stmt = $db->prepare("UPDATE teams SET name = :name, manager_id = :mgr WHERE id = :id");
        $ok = $stmt->execute([
            ':name' => trim($name),
            ':mgr'  => $managerId ?: null,
            ':id'   => $id,
        ]);

        if ($ok) {
            // Also keep users.department synced
            $sync = $db->prepare("UPDATE users SET department = :name WHERE team_id = :id");
            $sync->execute([':name' => trim($name), ':id' => $id]);

            AuditService::log($adminId, 'update_team', 'team', $id, $old, [
                'name'       => $name,
                'manager_id' => $managerId
            ]);
        }

        return $ok;
    }

    public static function delete(int $id, int $adminId): bool {
        $db = Database::getConnection();
        $old = self::findById($id);
        if (!$old) return false;

        $db->prepare("UPDATE users SET team_id = NULL WHERE team_id = :id")->execute([':id' => $id]);
        $stmt = $db->prepare("DELETE FROM teams WHERE id = :id");
        $ok = $stmt->execute([':id' => $id]);

        if ($ok) {
            AuditService::log($adminId, 'delete_team', 'team', $id, $old, null);
        }
        return $ok;
    }

    public static function assignMember(int $teamId, int $userId, int $adminId): bool {
        $db = Database::getConnection();
        $team = self::findById($teamId);
        if (!$team) return false;

        $stmt = $db->prepare("UPDATE users SET team_id = :tid, department = :dept WHERE id = :uid");
        $ok = $stmt->execute([
            ':tid'  => $teamId,
            ':dept' => $team['name'],
            ':uid'  => $userId,
        ]);

        if ($ok) {
            AuditService::log($adminId, 'assign_team_member', 'user', $userId, null, [
                'team_id'   => $teamId,
                'team_name' => $team['name']
            ]);
        }
        return $ok;
    }
}

