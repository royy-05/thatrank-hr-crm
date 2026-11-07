<?php
// app/controllers/ReportsController.php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Config\Database;
use App\Services\ExportService;
use PDO;

class ReportsController {
    public static function index(): void {
        $viewer = AuthMiddleware::requireManagerOrAdmin();
        AuthMiddleware::requirePermission('view_reports');
        $period = $_GET['period'] ?? 'this_week';
        $reportData = self::getReportData($viewer, $period);

        jsonResponse([
            'success' => true,
            'report'  => $reportData,
        ]);
    }

    public static function exportCsv(): void {
        $viewer = AuthMiddleware::requireManagerOrAdmin();
        AuthMiddleware::requirePermission('export_reports');
        $period = $_GET['period'] ?? 'this_week';
        $reportData = self::getReportData($viewer, $period);

        $headers = ['Employee', 'Days', 'Worked', 'Avg per day', 'Breaks', 'Late', 'Overtime'];
        $rows = [];

        foreach ($reportData['employees'] as $e) {
            $rows[] = [
                $e['name'],
                $e['days'],
                $e['worked_str'],
                $e['avg_per_day_str'],
                $e['break_str'],
                $e['late_count'],
                $e['overtime_str'],
            ];
        }

        $filename = 'thatrank_work_hours_' . $period . '_' . date('Ymd_His') . '.csv';
        ExportService::streamCsv($filename, $headers, $rows);
    }

    public static function exportPdf(): void {
        $viewer = AuthMiddleware::requireManagerOrAdmin();
        AuthMiddleware::requirePermission('export_reports');
        $period = $_GET['period'] ?? 'this_week';
        $reportData = self::getReportData($viewer, $period);

        $periodLabel = match ($period) {
            'this_month' => 'This Month (' . date('F Y') . ')',
            'last_month' => 'Last Month (' . date('F Y', strtotime('first day of last month')) . ')',
            default      => 'This Week',
        };

        $headers = ['Employee', 'Days', 'Worked', 'Avg per day', 'Breaks', 'Late', 'Overtime'];
        $rows = [];

        foreach ($reportData['employees'] as $e) {
            $rows[] = [
                $e['name'],
                $e['days'],
                $e['worked_str'],
                $e['avg_per_day_str'],
                $e['break_str'],
                $e['late_count'],
                $e['overtime_str'],
            ];
        }

        $stats = [
            'Team Hours'         => $reportData['summary']['total_hours_str'],
            'Average Per Person' => $reportData['summary']['avg_per_person_str'],
            'Attendance Rate'    => $reportData['summary']['attendance_pct'] . '%',
            'Total Late Marks'   => (string)$reportData['summary']['total_late'],
        ];

        $html = ExportService::renderReportHtml('Work Hours & Attendance Report', $periodLabel, $stats, $headers, $rows);
        $filename = 'thatrank_report_' . $period . '_' . date('Ymd_His') . '.pdf';
        ExportService::streamPdf($filename, $html);
    }

    private static function getReportData(array $viewer, string $period): array {
        $db = Database::getConnection();

        // Calculate date boundaries
        if ($period === 'this_month') {
            $startDate = date('Y-m-01');
            $endDate = date('Y-m-t');
        } elseif ($period === 'last_month') {
            $startDate = date('Y-m-01', strtotime('first day of last month'));
            $endDate = date('Y-m-t', strtotime('last day of last month'));
        } else { // 'this_week'
            $startDate = date('Y-m-d', strtotime('monday this week'));
            $endDate = date('Y-m-d', strtotime('sunday this week'));
        }

        // Filter user IDs based on manager/admin
        $userIds = AuthMiddleware::getAccessibleUserIds($viewer);
        if (empty($userIds)) {
            return [
                'summary'   => ['total_hours_str' => '0h', 'avg_per_person_str' => '0h', 'attendance_pct' => 0, 'total_late' => 0],
                'employees' => []
            ];
        }

        $inClause = implode(',', array_map('intval', $userIds));

        // Fetch users
        $usersStmt = $db->query("SELECT id, name, avatar_initials, avatar_color, department FROM users WHERE id IN ({$inClause}) AND status = 'active' ORDER BY name ASC");
        $users = $usersStmt->fetchAll();

        // Fetch attendance grouped by user
        $sql = "
            SELECT user_id,
                   COUNT(DISTINCT date) as days_count,
                   SUM(work_seconds) as total_work_sec,
                   SUM(break_seconds) as total_break_sec,
                   SUM(is_late) as late_count,
                   SUM(overtime_seconds) as total_overtime_sec
            FROM attendance_records
            WHERE user_id IN ({$inClause})
              AND date BETWEEN :start AND :end
              AND status = 'present'
            GROUP BY user_id
        ";
        $stmt = $db->prepare($sql);
        $stmt->execute([':start' => $startDate, ':end' => $endDate]);
        $records = $stmt->fetchAll(PDO::FETCH_UNIQUE);

        // Fetch break counts from punch_logs
        $bCountStmt = $db->prepare("
            SELECT ar.user_id, COUNT(*) as break_count
            FROM punch_logs pl
            JOIN attendance_records ar ON pl.attendance_record_id = ar.id
            WHERE ar.user_id IN ({$inClause})
              AND ar.date BETWEEN :start AND :end
              AND pl.type = 'break_start'
            GROUP BY ar.user_id
        ");
        $bCountStmt->execute([':start' => $startDate, ':end' => $endDate]);
        $breakCounts = $bCountStmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // Fetch active session elapsed time for today if today is within range
        $todayActiveMap = [];
        $todayDate = date('Y-m-d');
        if ($todayDate >= $startDate && $todayDate <= $endDate) {
            $actStmt = $db->prepare("
                SELECT user_id, current_status, last_status_time
                FROM attendance_records
                WHERE user_id IN ({$inClause})
                  AND date = :today
                  AND current_status IN ('clocked_in', 'on_break')
            ");
            $actStmt->execute([':today' => $todayDate]);
            $todayActiveMap = $actStmt->fetchAll(PDO::FETCH_UNIQUE);
        }

        $employees = [];
        $grandWorkSec = 0;
        $grandBreakSec = 0;
        $grandLate = 0;
        $totalPersonDays = 0;

        foreach ($users as $u) {
            $uid = $u['id'];
            $r = $records[$uid] ?? null;

            $days = $r ? (int)$r['days_count'] : 0;
            $workSec = $r ? (int)$r['total_work_sec'] : 0;
            $breakSec = $r ? (int)$r['total_break_sec'] : 0;
            $lateCount = $r ? (int)$r['late_count'] : 0;
            $otSec = $r ? (int)$r['total_overtime_sec'] : 0;
            $bCount = (int)($breakCounts[$uid] ?? 0);

            // Add live elapsed seconds for active sessions today
            if (isset($todayActiveMap[$uid])) {
                $actRec = $todayActiveMap[$uid];
                $actElapsed = !empty($actRec['last_status_time']) ? max(0, time() - strtotime($actRec['last_status_time'])) : 0;
                if ($actRec['current_status'] === 'clocked_in') {
                    $workSec += $actElapsed;
                } elseif ($actRec['current_status'] === 'on_break') {
                    $breakSec += $actElapsed;
                }
            }

            $grandWorkSec += $workSec;
            $grandBreakSec += $breakSec;
            $grandLate += $lateCount;
            $totalPersonDays += $days;

            $avgSec = $days > 0 ? (int)round($workSec / $days) : 0;

            $workedStr = sprintf('%dh %02dm', floor($workSec / 3600), floor(($workSec % 3600) / 60));
            $avgStr = sprintf('%dh %02dm', floor($avgSec / 3600), floor(($avgSec % 3600) / 60));
            $breakFormatted = self::formatDuration($breakSec);
            $breakStr = $bCount > 0 ? sprintf('%d %s (%s)', $bCount, $bCount === 1 ? 'break' : 'breaks', $breakFormatted) : $breakFormatted;
            $otStr = $otSec > 0 ? sprintf('%dh %02dm', floor($otSec / 3600), floor(($otSec % 3600) / 60)) : '-';

            $anomalies = [];
            if ($lateCount >= 3) {
                $anomalies[] = ['type' => 'frequent_late', 'severity' => 'crit', 'label' => '3+ late'];
            }
            $autoStmt = $db->prepare("SELECT COUNT(*) FROM attendance_records WHERE user_id = :uid AND date BETWEEN :start AND :end AND auto_clocked_out = 1");
            $autoStmt->execute([':uid' => $uid, ':start' => $startDate, ':end' => $endDate]);
            $autoClosedCount = (int)$autoStmt->fetchColumn();
            if ($autoClosedCount > 0) {
                $anomalies[] = ['type' => 'auto_closed', 'severity' => 'warn', 'label' => "{$autoClosedCount} auto closed"];
            }

            $employees[] = [
                'id'              => $uid,
                'name'            => $u['name'],
                'avatar_initials' => $u['avatar_initials'],
                'avatar_color'    => $u['avatar_color'],
                'department'      => $u['department'],
                'days'            => $days,
                'worked_str'      => $workedStr,
                'avg_per_day_str' => $avgStr,
                'break_str'       => $breakStr,
                'break_count'     => $bCount,
                'break_seconds'   => $breakSec,
                'late_count'      => $lateCount,
                'overtime_str'    => $otStr,
                'anomalies'       => $anomalies,
            ];
        }

        $userCount = max(1, count($users));
        $avgPersonSec = (int)round($grandWorkSec / $userCount);

        $summary = [
            'total_hours_str'    => sprintf('%dh', floor($grandWorkSec / 3600)),
            'avg_per_person_str' => sprintf('%dh %02dm', floor($avgPersonSec / 3600), floor(($avgPersonSec % 3600) / 60)),
            'attendance_pct'     => min(100, (int)round(($totalPersonDays / ($userCount * 5)) * 100)),
            'total_late'         => $grandLate,
        ];

        return ['summary' => $summary, 'employees' => $employees];
    }

    public static function formatDuration(int $sec): string {
        if ($sec <= 0) return '0h 00m';
        $h = floor($sec / 3600);
        $m = floor(($sec % 3600) / 60);
        $s = $sec % 60;
        if ($h > 0) {
            return sprintf('%dh %02dm', $h, $m);
        }
        if ($m > 0) {
            return $s > 0 ? sprintf('%dm %02ds', $m, $s) : sprintf('0h %02dm', $m);
        }
        return sprintf('%ds', $s);
    }
}

