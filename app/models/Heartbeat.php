<?php
// app/models/Heartbeat.php

namespace App\Models;

use App\Config\Database;
use PDO;

class Heartbeat {
    public static function recordBeat(
        int $userId,
        string $status = 'working',
        ?string $note = null,
        bool $isIdle = false,
        int $idleMinutes = 0,
        ?string $device = null
    ): void {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            INSERT INTO heartbeats (user_id, last_beat_at, active_status, active_note, is_idle, idle_minutes, device_info, updated_at)
            VALUES (:uid, NOW(), :status, :note, :idle, :idle_min, :dev, NOW())
            ON DUPLICATE KEY UPDATE
                last_beat_at = NOW(),
                active_status = VALUES(active_status),
                active_note = COALESCE(VALUES(active_note), active_note),
                is_idle = VALUES(is_idle),
                idle_minutes = VALUES(idle_minutes),
                device_info = COALESCE(VALUES(device_info), device_info),
                updated_at = NOW()
        ");
        $stmt->execute([
            ':uid'      => $userId,
            ':status'   => $status,
            ':note'     => $note,
            ':idle'     => $isIdle ? 1 : 0,
            ':idle_min' => $idleMinutes,
            ':dev'      => $device ?? substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    }

    public static function getLiveBoard(array $viewer): array {
        $db = Database::getConnection();
        $today = date('Y-m-d');

        // Apply role filter: Admin sees all; Manager sees own team (users where manager_id = viewer.id OR id = viewer.id)
        $whereUser = "";
        $params = [':today' => $today];

        if ($viewer['role'] === 'manager') {
            $whereUser = "AND (u.id = :mgr_id1 OR u.manager_id = :mgr_id2)";
            $params[':mgr_id1'] = $viewer['id'];
            $params[':mgr_id2'] = $viewer['id'];
        }

        $sql = "
            SELECT u.id, u.name, u.department, u.job_title, u.avatar_initials, u.avatar_color,
                   h.last_beat_at, h.active_status, h.active_note, h.is_idle, h.idle_minutes,
                   ar.id as attendance_record_id, ar.current_status, ar.first_in, ar.work_seconds, 
                   ar.break_seconds, ar.auto_clocked_out, ar.is_late, ar.last_status_time, ar.status as daily_status
            FROM users u
            LEFT JOIN heartbeats h ON u.id = h.user_id
            LEFT JOIN attendance_records ar ON u.id = ar.user_id AND ar.date = :today
            WHERE u.status = 'active' {$whereUser}
            ORDER BY u.name ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $cards = [];
        $counts = ['working' => 0, 'idle' => 0, 'break' => 0, 'offline' => 0, 'not_in' => 0];

        foreach ($rows as $r) {
            $lastBeat = !empty($r['last_beat_at']) ? strtotime($r['last_beat_at']) : 0;
            $secondsSinceBeat = time() - $lastBeat;
            $currentStatus = $r['current_status'] ?? 'clocked_out';

            // Determine status
            $status = 'Not in';
            $pillClass = 'crit';

            if ($r['daily_status'] === 'on_leave') {
                $status = 'Not in';
                $pillClass = 'crit';
                $counts['not_in']++;
            } elseif ($currentStatus === 'clocked_in') {
                if ($secondsSinceBeat > 300) {
                    // Offline when clocked in but tab is closed or disconnected (no heartbeat for 5+ min)
                    $status = 'Offline';
                    $pillClass = 'crit';
                    $counts['offline']++;
                } elseif (!empty($r['is_idle'])) {
                    // Idle when clocked in with tab open but no user activity for 5+ min
                    $mins = !empty($r['idle_minutes']) ? (int)$r['idle_minutes'] : (int)max(5, round($secondsSinceBeat / 60));
                    $status = "Idle {$mins}m";
                    $pillClass = 'neutral';
                    $counts['idle']++;
                } else {
                    $status = 'Working';
                    $pillClass = 'ok';
                    $counts['working']++;
                }
            } elseif ($currentStatus === 'on_break') {
                $status = 'Break';
                $pillClass = 'warn';
                $counts['break']++;
            } else {
                $status = 'Not in';
                $pillClass = 'crit';
                $counts['not_in']++;
            }

            // Calculate today's working time string
            $workSec = (int)($r['work_seconds'] ?? 0);
            if ($currentStatus === 'clocked_in' && !empty($r['last_status_time'])) {
                $elapsed = max(0, time() - strtotime($r['last_status_time']));
                $workSec += $elapsed;
            }
            $hours = floor($workSec / 3600);
            $mins = floor(($workSec % 3600) / 60);
            $timeStr = sprintf('%dh %02dm', $hours, $mins);

            $inStr = !empty($r['first_in']) ? date('g:i', strtotime($r['first_in'])) : null;

            $anomalies = [];
            if (!empty($r['attendance_record_id'])) {
                $anomalies = \App\Services\AnomalyService::detectRecordAnomalies([
                    'id'               => (int)$r['attendance_record_id'],
                    'user_id'          => (int)$r['id'],
                    'date'             => $today,
                    'auto_clocked_out' => $r['auto_clocked_out'],
                    'break_seconds'    => $r['break_seconds'],
                    'is_late'          => $r['is_late'],
                ]);
            }

            $cards[] = [
                'id'              => (int)$r['id'],
                'name'            => $r['name'],
                'department'      => $r['department'],
                'job_title'       => $r['job_title'],
                'avatar_initials' => $r['avatar_initials'],
                'avatar_color'    => $r['avatar_color'],
                'status'          => $status,
                'pill_class'      => $pillClass,
                'first_in'        => $inStr,
                'work_time_str'   => $timeStr,
                'on_leave'        => ($r['daily_status'] === 'on_leave'),
                'anomalies'       => $anomalies,
            ];
        }

        return ['cards' => $cards, 'counts' => $counts];
    }
}
