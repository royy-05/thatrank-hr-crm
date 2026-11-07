<?php
// app/controllers/DashboardController.php

namespace App\Controllers;

use App\Config\Database;
use App\Middleware\AuthMiddleware;
use App\Models\Attendance;
use App\Models\Correction;
use App\Models\Heartbeat;
use App\Models\Holiday;
use App\Models\Leave;
use PDO;

class DashboardController {
    /**
     * Role-aware dashboard dispatcher
     */
    public static function index(): void {
        $user = AuthMiddleware::requireLogin();
        $role = $user['role'] ?? 'employee';

        if ($role === 'admin') {
            self::admin();
        } elseif ($role === 'manager') {
            self::manager();
        } else {
            self::employee();
        }
    }

    /**
     * Employee Dashboard API
     */
    public static function employee(): void {
        $user = AuthMiddleware::requireLogin();
        $userId = (int)$user['id'];

        $data = self::getEmployeeDashboardData($userId);

        jsonResponse([
            'success'   => true,
            'role'      => 'employee',
            'dashboard' => $data,
        ]);
    }

    /**
     * Manager Dashboard API (Manager or Admin)
     */
    public static function manager(): void {
        $viewer = AuthMiddleware::requireManagerOrAdmin();
        $userId = (int)$viewer['id'];

        $empData = self::getEmployeeDashboardData($userId);

        // Team stats scoped to direct reports
        $db = Database::getConnection();
        $monday = date('Y-m-d', strtotime('monday this week'));

        // Direct reports status counts via LiveBoard
        $board = Heartbeat::getLiveBoard($viewer);
        $teamCounts = $board['counts'] ?? ['working' => 0, 'idle' => 0, 'break' => 0, 'not_in' => 0];

        // Pending approvals for this manager
        $pendingLeaves = Leave::getPendingRequestsForApprover($viewer);
        $pendingCorrections = Correction::getPendingForApprover($viewer);
        $pendingCount = count($pendingLeaves) + count($pendingCorrections);

        // Team late marks this week
        $lateStmt = $db->prepare("
            SELECT COUNT(*)
            FROM attendance_records ar
            JOIN users u ON ar.user_id = u.id
            WHERE u.manager_id = :mgr_id
              AND ar.date >= :monday
              AND ar.is_late = 1
        ");
        $lateStmt->execute([':mgr_id' => $userId, ':monday' => $monday]);
        $teamLateThisWeek = (int)$lateStmt->fetchColumn();

        $mgrData = array_merge($empData, [
            'team' => [
                'counts'              => $teamCounts,
                'pending_count'       => $pendingCount,
                'pending_leaves'      => array_slice($pendingLeaves, 0, 5),
                'pending_corrections' => array_slice($pendingCorrections, 0, 5),
                'team_late_this_week' => $teamLateThisWeek,
            ],
        ]);

        jsonResponse([
            'success'   => true,
            'role'      => 'manager',
            'dashboard' => $mgrData,
        ]);
    }

    /**
     * Admin Dashboard API (Admin only)
     */
    public static function admin(): void {
        $viewer = AuthMiddleware::requireAdmin();
        $db = Database::getConnection();

        $today = date('Y-m-d');
        $monday = date('Y-m-d', strtotime('monday this week'));

        // 1. Total active employees
        $totalEmp = (int)$db->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();

        // 2. Present today
        $presentStmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM attendance_records WHERE date = :today AND status = 'present'");
        $presentStmt->execute([':today' => $today]);
        $presentToday = (int)$presentStmt->fetchColumn();

        // 3. On leave today
        $leaveStmt = $db->prepare("SELECT COUNT(DISTINCT user_id) FROM leave_requests WHERE :today BETWEEN start_date AND end_date AND status = 'approved'");
        $leaveStmt->execute([':today' => $today]);
        $onLeaveToday = (int)$leaveStmt->fetchColumn();

        $onLeaveListStmt = $db->prepare("
            SELECT u.name, u.avatar_initials, u.avatar_color, lr.leave_type, lr.end_date
            FROM leave_requests lr
            JOIN users u ON lr.user_id = u.id
            WHERE :today BETWEEN lr.start_date AND lr.end_date
              AND lr.status = 'approved'
        ");
        $onLeaveListStmt->execute([':today' => $today]);
        $onLeaveTodayList = $onLeaveListStmt->fetchAll(PDO::FETCH_ASSOC);

        // 4. Pending approvals across company
        $pendingLeaves = (int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE status = 'pending'")->fetchColumn();
        $pendingCorrections = (int)$db->query("SELECT COUNT(*) FROM attendance_corrections WHERE status = 'pending'")->fetchColumn();
        $totalPending = $pendingLeaves + $pendingCorrections;

        // 5. Late marks this week
        $lateStmt = $db->prepare("SELECT COUNT(*) FROM attendance_records WHERE date >= :monday AND is_late = 1");
        $lateStmt->execute([':monday' => $monday]);
        $lateThisWeek = (int)$lateStmt->fetchColumn();

        // 6. Anomaly count today
        $anomStmt = $db->prepare("SELECT COUNT(*) FROM attendance_records WHERE date = :today AND (auto_clocked_out = 1 OR is_late = 1 OR work_seconds = 0)");
        $anomStmt->execute([':today' => $today]);
        $anomalyCount = (int)$anomStmt->fetchColumn();

        // 7. Recent audit entries (latest 5)
        $auditStmt = $db->query("
            SELECT a.id, a.action, a.target_type, a.target_id, a.created_at, u.name as user_name
            FROM audit_log a
            LEFT JOIN users u ON a.user_id = u.id
            ORDER BY a.created_at DESC
            LIMIT 5
        ");
        $recentAudit = $auditStmt->fetchAll();

        // 8. Total teams
        $teamsCount = (int)$db->query("SELECT COUNT(*) FROM teams")->fetchColumn();

        $stats = [
            'total_employees'   => $totalEmp,
            'present_today'     => $presentToday,
            'on_leave_today'    => $onLeaveToday,
            'pending_approvals' => $totalPending,
            'late_marks_week'   => $lateThisWeek,
            'anomaly_count'     => $anomalyCount,
            'teams_count'       => $teamsCount,
        ];

        jsonResponse([
            'success'   => true,
            'role'      => 'admin',
            'dashboard' => array_merge($stats, [
                'stats'               => $stats,
                'on_leave_today_list' => $onLeaveTodayList,
                'recent_audit'        => $recentAudit,
                'date_display'        => date('l, j F Y'),
            ]),
        ]);
    }

    /**
     * Shared Employee Dashboard Data Builder
     */
    private static function getEmployeeDashboardData(int $userId): array {
        $db = Database::getConnection();
        $today = date('Y-m-d');
        $currMonth = date('Y-m');

        $record = Attendance::getTodayRecord($userId);
        $latestPunch = Attendance::getLatestPunch($userId);
        $timeline = Attendance::getPunchTimelineToday($userId);
        $weeklyHours = Attendance::getWeeklyHours($userId);
        $balances = Leave::getBalances($userId);
        $nextHoliday = Holiday::getNextHoliday();

        // Late marks this month for logged-in user
        $lateStmt = $db->prepare("SELECT COUNT(*) FROM attendance_records WHERE user_id = :uid AND is_late = 1 AND date LIKE :m");
        $lateStmt->execute([':uid' => $userId, ':m' => "{$currMonth}%"]);
        $lateMarks = (int)$lateStmt->fetchColumn();

        // Work time strings
        $workSec = (int)($record['effective_work_seconds'] ?? ($record['work_seconds'] ?? 0));
        $todayWorkStr = sprintf('%dh %02dm', floor($workSec / 3600), floor(($workSec % 3600) / 60));

        $startOfWeek = date('Y-m-d', strtotime('monday this week'));
        $endOfWeek = date('Y-m-d', strtotime('sunday this week'));
        $weekStmt = $db->prepare("
            SELECT COALESCE(SUM(work_seconds), 0)
            FROM attendance_records
            WHERE user_id = :uid AND date BETWEEN :start AND :end AND date != :today
        ");
        $weekStmt->execute([':uid' => $userId, ':start' => $startOfWeek, ':end' => $endOfWeek, ':today' => $today]);
        $priorDaysWeekSec = (int)$weekStmt->fetchColumn();
        $weekTotalSec = $priorDaysWeekSec + $workSec;
        $weekTotalStr = sprintf('%dh %02dm', floor($weekTotalSec / 3600), floor(($weekTotalSec % 3600) / 60));

        // Colleagues on leave today
        $leaveColleaguesStmt = $db->prepare("
            SELECT u.name, u.avatar_initials, u.avatar_color, lr.leave_type, lr.end_date
            FROM leave_requests lr
            JOIN users u ON lr.user_id = u.id
            WHERE :today BETWEEN lr.start_date AND lr.end_date
              AND lr.status = 'approved'
              AND u.status = 'active'
            ORDER BY u.name ASC
            LIMIT 5
        ");
        $leaveColleaguesStmt->execute([':today' => $today]);
        $onLeaveToday = $leaveColleaguesStmt->fetchAll();

        // My pending leave requests and corrections
        $myPendingLeaves = (int)$db->query("SELECT COUNT(*) FROM leave_requests WHERE user_id = {$userId} AND status = 'pending'")->fetchColumn();
        $myPendingCorrections = (int)$db->query("SELECT COUNT(*) FROM attendance_corrections WHERE user_id = {$userId} AND status = 'pending'")->fetchColumn();
        $totalPending = $myPendingLeaves + $myPendingCorrections;

        return [
            'today'               => $record,
            'record'              => $record,
            'latest_punch'        => $latestPunch,
            'timeline'            => $timeline,
            'weekly_hours'        => $weeklyHours,
            'balances'            => $balances,
            'next_holiday'        => $nextHoliday,
            'late_marks'          => $lateMarks,
            'today_str'           => $todayWorkStr,
            'week_str'            => $weekTotalStr,
            'hours'               => [
                'today_str' => $todayWorkStr,
                'week_str'  => $weekTotalStr,
            ],
            'pending_requests'    => $totalPending,
            'on_leave_today'      => $onLeaveToday,
            'date_display'        => date('l, j F Y'),
        ];
    }
}

