<?php
// app/models/User.php

namespace App\Models;

use App\Config\Database;
use App\Services\AuditService;
use PDO;

class User {
    public static function findById(int $id): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT u.*, m.name as manager_name, t.name as team_name
            FROM users u
            LEFT JOIN users m ON u.manager_id = m.id
            LEFT JOIN teams t ON u.team_id = t.id
            WHERE u.id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function getActiveSessions(int $userId): array {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT * FROM user_sessions
            WHERE user_id = :uid
            ORDER BY last_seen_at DESC
            LIMIT 10
        ");
        $stmt->execute([':uid' => $userId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function findByEmail(string $email): ?array {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM users WHERE email = :email LIMIT 1");
        $stmt->execute([':email' => trim($email)]);
        $user = $stmt->fetch();
        return $user ?: null;
    }

    public static function authenticate(string $email, string $password): ?array {
        $user = self::findByEmail($email);
        if (!$user) {
            return null;
        }
        if ($user['status'] !== 'active') {
            return null;
        }
        if (password_verify($password, $user['password_hash'])) {
            return $user;
        }
        return null;
    }

    public static function recordConsent(int $userId): bool {
        $db = Database::getConnection();
        $stmt = $db->prepare("UPDATE users SET consent_accepted_at = NOW() WHERE id = :id");
        $res = $stmt->execute([':id' => $userId]);
        if ($res) {
            AuditService::log($userId, 'consent_accepted', 'user', $userId, null, ['consent_accepted_at' => date('Y-m-d H:i:s')]);
        }
        return $res;
    }

    public static function getAll(array $viewer): array {
        $db = Database::getConnection();
        if ($viewer['role'] === 'admin') {
            $sql = "
                SELECT u.id, u.name, u.email, u.role, u.job_title, u.department, u.manager_id,
                       u.avatar_initials, u.avatar_color, u.joined_date, u.status, u.consent_accepted_at,
                       m.name as manager_name
                FROM users u
                LEFT JOIN users m ON u.manager_id = m.id
                ORDER BY u.status ASC, u.name ASC
            ";
            return $db->query($sql)->fetchAll();
        }

        if ($viewer['role'] === 'manager') {
            $stmt = $db->prepare("
                SELECT u.id, u.name, u.email, u.role, u.job_title, u.department, u.manager_id,
                       u.avatar_initials, u.avatar_color, u.joined_date, u.status, u.consent_accepted_at,
                       m.name as manager_name
                FROM users u
                LEFT JOIN users m ON u.manager_id = m.id
                WHERE u.id = :mgr_id1 OR u.manager_id = :mgr_id2
                ORDER BY u.status ASC, u.name ASC
            ");
            $stmt->execute([
                ':mgr_id1' => $viewer['id'],
                ':mgr_id2' => $viewer['id']
            ]);
            return $stmt->fetchAll();
        }

        // Employee: own record only
        $stmt = $db->prepare("
            SELECT u.id, u.name, u.email, u.role, u.job_title, u.department, u.manager_id,
                   u.avatar_initials, u.avatar_color, u.joined_date, u.status, u.consent_accepted_at,
                   m.name as manager_name
            FROM users u
            LEFT JOIN users m ON u.manager_id = m.id
            WHERE u.id = :id
        ");
        $stmt->execute([':id' => $viewer['id']]);
        return $stmt->fetchAll();
    }

    public static function create(array $data, int $actorId): int {
        $db = Database::getConnection();

        // Generate initials
        $nameParts = preg_split('/\s+/', trim($data['name'] ?? ''));
        $initials = '';
        foreach ($nameParts as $part) {
            $initials .= mb_strtoupper(mb_substr($part, 0, 1));
            if (strlen($initials) >= 2) break;
        }

        // Colors palette
        $colors = ['var(--ink)', 'var(--ok)', 'var(--warn)', 'var(--neutral)', 'var(--accent)', 'var(--crit)'];
        $color = $colors[array_rand($colors)];

        $plainPassword = !empty($data['password']) && strlen(trim($data['password'])) >= 1 ? trim($data['password']) : 'password123';
        $passwordHash = password_hash($plainPassword, PASSWORD_BCRYPT);

        $teamId = null;
        if (!empty($data['team_id']) && $data['team_id'] !== 'null' && (int)$data['team_id'] > 0) {
            $teamCheck = $db->prepare("SELECT id FROM teams WHERE id = :tid LIMIT 1");
            $teamCheck->execute([':tid' => (int)$data['team_id']]);
            if ($teamCheck->fetchColumn()) {
                $teamId = (int)$data['team_id'];
            }
        }

        $managerId = null;
        if (!empty($data['manager_id']) && $data['manager_id'] !== 'null' && (int)$data['manager_id'] > 0) {
            $mgrCheck = $db->prepare("SELECT id FROM users WHERE id = :mid LIMIT 1");
            $mgrCheck->execute([':mid' => (int)$data['manager_id']]);
            if ($mgrCheck->fetchColumn()) {
                $managerId = (int)$data['manager_id'];
            }
        }

        $dob = (!empty($data['date_of_birth']) && $data['date_of_birth'] !== 'null') ? $data['date_of_birth'] : null;
        $anniv = (!empty($data['work_anniversary']) && $data['work_anniversary'] !== 'null') ? $data['work_anniversary'] : (!empty($data['joined_date']) ? $data['joined_date'] : date('Y-m-d'));
        $phone = (!empty($data['phone']) && $data['phone'] !== 'null') ? trim($data['phone']) : null;
        $joinedDate = !empty($data['joined_date']) && $data['joined_date'] !== 'null' ? $data['joined_date'] : date('Y-m-d');

        $stmt = $db->prepare("
            INSERT INTO users (name, email, phone, password_hash, role, job_title, department, team_id, manager_id, avatar_initials, avatar_color, joined_date, date_of_birth, work_anniversary, status, must_change_password, created_at)
            VALUES (:name, :email, :phone, :password_hash, :role, :job_title, :department, :team_id, :manager_id, :avatar_initials, :avatar_color, :joined_date, :dob, :anniv, :status, 0, NOW())
        ");

        $params = [
            ':name'            => trim($data['name'] ?? ''),
            ':email'           => trim($data['email'] ?? ''),
            ':phone'           => $phone,
            ':password_hash'   => $passwordHash,
            ':role'            => !empty($data['role']) ? $data['role'] : 'employee',
            ':job_title'       => !empty($data['job_title']) ? $data['job_title'] : null,
            ':department'      => !empty($data['department']) ? $data['department'] : 'Product',
            ':team_id'         => $teamId,
            ':manager_id'      => $managerId,
            ':avatar_initials' => $initials ?: 'TR',
            ':avatar_color'    => $data['avatar_color'] ?? $color,
            ':joined_date'     => $joinedDate,
            ':dob'             => $dob,
            ':anniv'           => $anniv,
            ':status'          => !empty($data['status']) ? $data['status'] : 'active',
        ];

        $stmt->execute($params);
        $newId = (int)$db->lastInsertId();

        // Also initialize leave balances for current year
        $currentYear = (int)date('Y');
        $stmtBal = $db->prepare("
            INSERT INTO leave_balances (user_id, year, casual_total, casual_used, casual_accrued, sick_total, sick_used, sick_accrued, earned_total, earned_used, earned_carried)
            VALUES (:user_id, :year, 12.0, 0.0, 12.0, 6.0, 0.0, 6.0, 15.0, 0.0, 0.0)
            ON DUPLICATE KEY UPDATE updated_at = NOW()
        ");
        $stmtBal->execute([':user_id' => $newId, ':year' => $currentYear]);

        $loggedData = $params;
        unset($loggedData[':password_hash']);
        AuditService::log($actorId, 'employee_create', 'employee', $newId, null, $loggedData);

        return $newId;
    }

    public static function update(int $id, array $data, int $actorId): bool {
        $db = Database::getConnection();
        $old = self::findById($id);
        if (!$old) {
            return false;
        }

        $fields = [];
        $params = [':id' => $id];

        $allowed = ['name', 'email', 'phone', 'role', 'job_title', 'department', 'team_id', 'manager_id', 'status', 'joined_date', 'date_of_birth', 'work_anniversary', 'avatar_color'];
        foreach ($allowed as $f) {
            if (array_key_exists($f, $data)) {
                $val = $data[$f];
                if ($val === '' || $val === 'null' || $val === null) {
                    $val = null;
                } elseif (($f === 'team_id' || $f === 'manager_id') && (int)$val <= 0) {
                    $val = null;
                }
                $fields[] = "`{$f}` = :{$f}";
                $params[":{$f}"] = $val;
            }
        }

        if (!empty($data['password'])) {
            $fields[] = "`password_hash` = :password_hash";
            $params[':password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT);
        }

        if (empty($fields)) {
            return true;
        }

        $sql = "UPDATE users SET " . implode(', ', $fields) . ", updated_at = NOW() WHERE id = :id";
        $stmt = $db->prepare($sql);
        $res = $stmt->execute($params);

        if ($res) {
            $new = self::findById($id);
            unset($old['password_hash'], $new['password_hash']);
            AuditService::log($actorId, 'employee_update', 'employee', $id, $old, $new);
        }

        return $res;
    }

    public static function offboard(int $id, int $actorId): bool {
        $db = Database::getConnection();
        $old = self::findById($id);
        if (!$old) {
            return false;
        }

        $stmt = $db->prepare("UPDATE users SET status = 'offboarded', updated_at = NOW() WHERE id = :id");
        $res = $stmt->execute([':id' => $id]);

        if ($res) {
            AuditService::log($actorId, 'employee_offboard', 'employee', $id, ['status' => $old['status']], ['status' => 'offboarded']);
        }
        return $res;
    }

    public static function getCelebrations(int $daysAhead = 30): array {
        // Returns real birthdays and work anniversaries for today and next 30 days
        $db = Database::getConnection();
        $stmt = $db->query("
            SELECT id, name, email, avatar_initials, avatar_color, department, joined_date, date_of_birth, work_anniversary
            FROM users
            WHERE status = 'active'
        ");
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $today = new \DateTime(date('Y-m-d'));
        $currentYear = (int)$today->format('Y');

        $birthdays = [];
        $anniversaries = [];

        foreach ($users as $u) {
            if (!empty($u['date_of_birth'])) {
                $dob = new \DateTime($u['date_of_birth']);
                $bdayThisYear = new \DateTime("{$currentYear}-" . $dob->format('m-d'));
                if ($bdayThisYear < $today) {
                    $bdayThisYear->modify('+1 year');
                }
                $diff = (int)$today->diff($bdayThisYear)->format('%r%a');
                if ($diff >= 0 && $diff <= $daysAhead) {
                    $birthdays[] = [
                        'user_id'         => (int)$u['id'],
                        'name'            => $u['name'],
                        'avatar_initials' => $u['avatar_initials'],
                        'avatar_color'    => $u['avatar_color'],
                        'department'      => $u['department'],
                        'type'            => 'birthday',
                        'days_away'       => $diff,
                        'is_today'        => ($diff === 0),
                        'date_label'      => ($diff === 0) ? 'Today' : (($diff === 1) ? 'Tomorrow' : $bdayThisYear->format('j M')),
                    ];
                }
            }

            $annivDate = !empty($u['work_anniversary']) ? $u['work_anniversary'] : $u['joined_date'];
            if (!empty($annivDate)) {
                $join = new \DateTime($annivDate);
                $annivThisYear = new \DateTime("{$currentYear}-" . $join->format('m-d'));
                if ($annivThisYear < $today) {
                    $annivThisYear->modify('+1 year');
                }
                $diff = (int)$today->diff($annivThisYear)->format('%r%a');
                $years = (int)$annivThisYear->format('Y') - (int)$join->format('Y');
                if ($diff >= 0 && $diff <= $daysAhead && $years > 0) {
                    $anniversaries[] = [
                        'user_id'         => (int)$u['id'],
                        'name'            => $u['name'],
                        'avatar_initials' => $u['avatar_initials'],
                        'avatar_color'    => $u['avatar_color'],
                        'department'      => $u['department'],
                        'type'            => 'anniversary',
                        'years'           => $years,
                        'days_away'       => $diff,
                        'is_today'        => ($diff === 0),
                        'date_label'      => ($diff === 0) ? 'Today' : (($diff === 1) ? 'Tomorrow' : $annivThisYear->format('j M')),
                    ];
                }
            }
        }

        usort($birthdays, fn($a, $b) => $a['days_away'] <=> $b['days_away']);
        usort($anniversaries, fn($a, $b) => $a['days_away'] <=> $b['days_away']);

        return [
            'birthdays'     => $birthdays,
            'anniversaries' => $anniversaries,
        ];
    }
}
