<?php
// app/controllers/HeartbeatController.php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\Attendance;
use App\Models\Heartbeat;
use App\Config\Database;

class HeartbeatController {
    /**
     * Ultra-lightweight endpoint: guarantees response under 5 KB.
     */
    public static function beat(): void {
        $user = AuthMiddleware::user();
        if (!$user) {
            jsonResponse(['ok' => false], 401);
        }

        $userId = (int)$user['id'];
        $isIdle = !empty($_POST['is_idle']);
        $idleMin = (int)($_POST['idle_minutes'] ?? 0);
        $note = !empty($_POST['note']) ? substr(trim($_POST['note']), 0, 100) : null;

        // Fetch current punch status today
        $rec = Attendance::getTodayRecord($userId);
        $status = 'off';
        if ($rec) {
            if ($rec['current_status'] === 'clocked_in') {
                $status = $isIdle ? 'idle' : 'working';
            } elseif ($rec['current_status'] === 'on_break') {
                $status = 'break';
            }
        }

        Heartbeat::recordBeat($userId, $status, $note, $isIdle, $idleMin);

        // Count online users in team/company (active in last 3 minutes)
        $db = Database::getConnection();
        $onlineStmt = $db->query("
            SELECT COUNT(*) FROM heartbeats
            WHERE last_beat_at >= DATE_SUB(NOW(), INTERVAL 3 MINUTE)
              AND active_status IN ('working', 'break', 'idle')
        ");
        $onlineCount = (int)$onlineStmt->fetchColumn();

        // Count pending approvals for managers/admins
        $pendingCount = 0;
        if ($user['role'] === 'admin') {
            $pStmt = $db->query("
                SELECT (SELECT COUNT(*) FROM leave_requests WHERE status = 'pending') +
                       (SELECT COUNT(*) FROM attendance_corrections WHERE status = 'pending')
            ");
            $pendingCount = (int)$pStmt->fetchColumn();
        } elseif ($user['role'] === 'manager') {
            $pStmt = $db->prepare("
                SELECT (SELECT COUNT(*) FROM leave_requests lr JOIN users u ON lr.user_id = u.id WHERE lr.status = 'pending' AND u.manager_id = :uid1) +
                       (SELECT COUNT(*) FROM attendance_corrections ac JOIN users u ON ac.user_id = u.id WHERE ac.status = 'pending' AND u.manager_id = :uid2)
            ");
            $pStmt->execute([':uid1' => $userId, ':uid2' => $userId]);
            $pendingCount = (int)$pStmt->fetchColumn();
        }

        // Response payload is ~150 bytes, well under 5 KB limit
        jsonResponse([
            'success'       => true,
            'ok'            => true,
            'status'        => $status,
            'work_sec'      => (int)($rec['effective_work_seconds'] ?? 0),
            'online'        => $onlineCount,
            'pending'       => $pendingCount,
            'server_time'   => date('H:i:s'),
        ]);
    }
}
