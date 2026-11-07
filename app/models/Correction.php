<?php
// app/models/Correction.php

namespace App\Models;

use App\Config\Database;
use App\Services\AuditService;
use PDO;

class Correction {
    public static function submit(int $userId, array $data): int {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO attendance_corrections (user_id, date, requested_in, requested_out, reason, status, created_at)
            VALUES (:uid, :date, :in, :out, :reason, 'pending', NOW())
        ");
        $params = [
            ':uid'    => $userId,
            ':date'   => $data['date'] ?? date('Y-m-d'),
            ':in'     => !empty($data['requested_in']) ? $data['requested_in'] : null,
            ':out'    => !empty($data['requested_out']) ? $data['requested_out'] : null,
            ':reason' => trim($data['reason'] ?? 'Correction request'),
        ];
        $stmt->execute($params);
        $id = (int)$db->lastInsertId();

        AuditService::log($userId, 'correction_submit', 'correction', $id, null, $params);
        return $id;
    }

    public static function getPendingForApprover(array $viewer): array {
        $db = Database::getConnection();
        if ($viewer['role'] === 'admin') {
            $stmt = $db->query("
                SELECT c.*, u.name as employee_name, u.department, u.avatar_initials, u.avatar_color, u.job_title
                FROM attendance_corrections c
                JOIN users u ON c.user_id = u.id
                WHERE c.status = 'pending'
                ORDER BY c.created_at ASC
            ");
            return $stmt->fetchAll();
        }

        if ($viewer['role'] === 'manager') {
            $stmt = $db->prepare("
                SELECT c.*, u.name as employee_name, u.department, u.avatar_initials, u.avatar_color, u.job_title
                FROM attendance_corrections c
                JOIN users u ON c.user_id = u.id
                WHERE c.status = 'pending' AND u.manager_id = :mgr_id
                ORDER BY c.created_at ASC
            ");
            $stmt->execute([':mgr_id' => $viewer['id']]);
            return $stmt->fetchAll();
        }

        return [];
    }

    /**
     * @return array{rows: array, total: int}
     */
    public static function listForUsers(array $userIds, ?string $from, ?string $to, int $limit, int $offset): array {
        if (empty($userIds)) {
            return ['rows' => [], 'total' => 0];
        }

        $db = Database::getConnection();
        $where = ['c.user_id IN (' . implode(',', array_fill(0, count($userIds), '?')) . ')'];
        $params = array_values($userIds);
        if ($from !== null) {
            $where[] = 'c.date >= ?';
            $params[] = $from;
        }
        if ($to !== null) {
            $where[] = 'c.date <= ?';
            $params[] = $to;
        }
        $whereSql = implode(' AND ', $where);

        $countStmt = $db->prepare("SELECT COUNT(*) FROM attendance_corrections c WHERE {$whereSql}");
        $countStmt->execute($params);

        $stmt = $db->prepare("
            SELECT c.*, u.name as employee_name, u.job_title, u.avatar_initials, u.avatar_color, a.name as approver_name
            FROM attendance_corrections c
            JOIN users u ON c.user_id = u.id
            LEFT JOIN users a ON c.actioned_by = a.id
            WHERE {$whereSql}
            ORDER BY c.date DESC, c.created_at DESC
            LIMIT " . (int)$limit . " OFFSET " . (int)$offset);
        $stmt->execute($params);

        return ['rows' => $stmt->fetchAll(PDO::FETCH_ASSOC), 'total' => (int)$countStmt->fetchColumn()];
    }

    public static function action(int $id, string $newStatus, int $actorId, ?string $reason = null): bool {
        if (!in_array($newStatus, ['approved', 'rejected'], true)) {
            return false;
        }

        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM attendance_corrections WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $id]);
        $old = $stmt->fetch();
        if (!$old || $old['status'] !== 'pending') {
            return false;
        }

        $db->beginTransaction();
        try {
            $up = $db->prepare("
                UPDATE attendance_corrections
                SET status = :status, actioned_by = :actor, actioned_at = NOW(), rejection_reason = :reason, updated_at = NOW()
                WHERE id = :id
            ");
            $up->execute([
                ':status' => $newStatus,
                ':actor'  => $actorId,
                ':reason' => $reason,
                ':id'     => $id,
            ]);

            // If approved, update attendance_records
            if ($newStatus === 'approved') {
                $targetUserId = (int)$old['user_id'];
                $targetDate = $old['date'];

                // Calculate revised work seconds
                $inTime = $old['requested_in'];
                $outTime = $old['requested_out'];
                $workSec = 0;
                if ($inTime && $outTime) {
                    $workSec = max(0, strtotime($outTime) - strtotime($inTime) - 1800); // 30 min break assumption if not given
                }

                $recStmt = $db->prepare("
                    INSERT INTO attendance_records (user_id, date, status, first_in, last_out, work_seconds, gross_seconds, current_status, created_at)
                    VALUES (:uid, :date, 'present', :first_in, :last_out, :work_sec, :gross_sec, 'clocked_out', NOW())
                    ON DUPLICATE KEY UPDATE
                        first_in = COALESCE(VALUES(first_in), first_in),
                        last_out = COALESCE(VALUES(last_out), last_out),
                        work_seconds = VALUES(work_seconds),
                        gross_seconds = VALUES(gross_seconds),
                        current_status = 'clocked_out',
                        updated_at = NOW()
                ");
                $grossSec = ($inTime && $outTime) ? max(0, strtotime($outTime) - strtotime($inTime)) : $workSec;
                $recStmt->execute([
                    ':uid'       => $targetUserId,
                    ':date'      => $targetDate,
                    ':first_in'  => $inTime,
                    ':last_out'  => $outTime,
                    ':work_sec'  => $workSec,
                    ':gross_sec' => $grossSec,
                ]);
            }

            $stmt->execute([':id' => $id]);
            $new = $stmt->fetch();

            AuditService::log($actorId, 'correction_' . $newStatus, 'correction', $id, $old, $new);

            $db->commit();
            return true;
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Correction action error: " . $e->getMessage());
            return false;
        }
    }
}
