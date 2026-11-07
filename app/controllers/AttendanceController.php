<?php
// app/controllers/AttendanceController.php

namespace App\Controllers;

use App\Middleware\AuthMiddleware;
use App\Models\Attendance;
use App\Models\Correction;
use App\Models\Leave;
use App\Models\Holiday;

class AttendanceController {
    public static function today(): void {
        $user = AuthMiddleware::requireLogin();
        $userId = (int)$user['id'];

        $record = Attendance::getTodayRecord($userId);
        $timeline = Attendance::getPunchTimelineToday($userId);
        $weeklyHours = Attendance::getWeeklyHours($userId);
        $balances = Leave::getBalances($userId);
        $nextHoliday = Holiday::getNextHoliday();

        // Calculate late marks this month
        $db = \App\Config\Database::getConnection();
        $currMonth = date('Y-m');
        $lateStmt = $db->prepare("SELECT COUNT(*) FROM attendance_records WHERE user_id = :uid AND is_late = 1 AND date LIKE :m");
        $lateStmt->execute([':uid' => $userId, ':m' => "{$currMonth}%"]);
        $lateMarks = (int)$lateStmt->fetchColumn();

        // Format today's stats
        $workSec = (int)($record['effective_work_seconds'] ?? 0);
        $workHrs = floor($workSec / 3600);
        $workMins = floor(($workSec % 3600) / 60);
        $todayWorkStr = sprintf('%dh %02dm', $workHrs, $workMins);

        // Sum weekly work seconds
        $weekTotalSec = 0;
        foreach ($weeklyHours as $w) {
            $weekTotalSec += (int)($w['hours'] * 3600);
        }
        $weekTotalStr = sprintf('%dh %02dm', floor($weekTotalSec / 3600), floor(($weekTotalSec % 3600) / 60));

        $latestPunch = Attendance::getLatestPunch($userId);
        $settings = \App\Models\Setting::getAllAsMap();

        jsonResponse([
            'success'            => true,
            'record'             => $record,
            'latest_punch'       => $latestPunch,
            'timeline'           => $timeline,
            'weekly_hours'       => $weeklyHours,
            'balances'           => $balances,
            'next_holiday'       => $nextHoliday,
            'late_marks'         => $lateMarks,
            'today_str'          => $todayWorkStr,
            'week_str'           => $weekTotalStr,
            'date_display'       => date('l, j F Y'),
            'shift_start_time'   => $settings['shift_start_time'] ?? '10:00:00',
            'shift_end_time'     => $settings['shift_end_time'] ?? '19:00:00',
            'work_week'          => $settings['work_week'] ?? 'Monday to Friday',
            'daily_target_hours' => $settings['daily_target_hours'] ?? '9',
        ]);
    }

    public static function undoPunch(): void {
        AuthMiddleware::requireCsrf();
        $user = AuthMiddleware::requireLogin();
        $res = Attendance::undoLatestPunch((int)$user['id']);
        if (!$res['success']) {
            jsonResponse(['success' => false, 'error' => $res['error'] ?? 'Undo failed.'], 400);
        }
        jsonResponse([
            'success' => true,
            'record'  => $res['record'] ?? null,
            'message' => $res['message'] ?? 'Punch undone successfully.'
        ]);
    }

    public static function editPunch(): void {
        AuthMiddleware::requireCsrf();
        $user = AuthMiddleware::requireLogin();
        $time = trim($_POST['time'] ?? '');
        $note = trim($_POST['note'] ?? '');
        if ($time === '') {
            jsonResponse(['success' => false, 'error' => 'Please provide the updated punch time.'], 400);
        }
        $res = Attendance::editLatestPunch((int)$user['id'], $time, $note ?: null);
        if (!$res['success']) {
            jsonResponse(['success' => false, 'error' => $res['error'] ?? 'Edit failed.'], 400);
        }
        jsonResponse([
            'success' => true,
            'record'  => $res['record'] ?? null,
            'message' => $res['message'] ?? 'Punch updated successfully.'
        ]);
    }

    public static function punch(): void {
        AuthMiddleware::requireCsrf();
        $user = AuthMiddleware::requireLogin();

        // Require consent check
        if (empty($user['consent_accepted_at'])) {
            jsonResponse(['success' => false, 'error' => 'Please accept the data privacy policy before your first punch.'], 403);
        }

        $type = $_POST['type'] ?? 'in';
        if (!in_array($type, ['in', 'break_start', 'break_end', 'out'], true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid punch type.'], 400);
        }

        // Block punches if user is on approved leave today
        $todayRecord = Attendance::getTodayRecord((int)$user['id']);
        if ($todayRecord && ($todayRecord['status'] === 'on_leave' || !empty($todayRecord['is_on_leave']))) {
            jsonResponse(['success' => false, 'error' => 'You are on approved leave today. You cannot record punches while on leave.'], 403);
        }

        $selfie = $_POST['selfie'] ?? null;
        $lat = $_POST['latitude'] ?? null;
        $lng = $_POST['longitude'] ?? null;

        // Mandatory live selfie check
        if (empty($selfie) || !is_string($selfie) || strpos($selfie, 'data:image') !== 0) {
            jsonResponse(['success' => false, 'error' => 'A live selfie is required to record your punch. Please enable your camera.'], 422);
        }

        // Mandatory live location check
        if ($lat === null || $lat === '' || $lng === null || $lng === '') {
            jsonResponse(['success' => false, 'error' => 'Live GPS location is required to record your punch. Please enable location permissions.'], 422);
        }

        $locName = trim($_POST['location_name'] ?? '');
        if ($locName === '') {
            $locName = 'Coordinates: ' . round((float)$lat, 4) . ', ' . round((float)$lng, 4);
        }

        $metadata = [
            'selfie'        => $selfie,
            'latitude'      => (float)$lat,
            'longitude'     => (float)$lng,
            'location_name' => $locName,
            'device_info'   => trim($_POST['device_info'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Web browser')),
        ];

        $res = Attendance::punch((int)$user['id'], $type, $metadata);
        if (!$res['success']) {
            jsonResponse(['success' => false, 'error' => $res['error'] ?? 'Punch failed.'], 500);
        }

        // Live email notifications for Late Clock-in and Break Started
        try {
            if ($type === 'in' && !empty($res['record']['is_late'])) {
                \App\Services\MailService::notifyLateArrival((int)$user['id'], [
                    'clock_in_time' => $res['record']['first_in'] ?? date('h:i:s A'),
                    'late_minutes'  => $res['record']['late_minutes'] ?? 0,
                ]);
            } elseif ($type === 'break_start') {
                \App\Services\MailService::notifyBreakStarted((int)$user['id'], [
                    'break_time' => date('h:i:s A'),
                ]);
            }
        } catch (\Throwable $e) {
            error_log("Failed to send punch notification email: " . $e->getMessage());
        }

        jsonResponse([
            'success'      => true,
            'record'       => $res['record'],
            'latest_punch' => Attendance::getLatestPunch((int)$user['id']),
            'message'      => 'Punch recorded successfully.'
        ]);
    }

    public static function logs(): void {
        $viewer = AuthMiddleware::requireLogin();
        $scope = self::scopeParam();
        $userIds = AuthMiddleware::resolveScopeUserIds($viewer, $scope, self::userIdParam(), self::teamIdParam());

        $perPage = min(100, max(1, (int)($_GET['limit'] ?? 50)));
        $page = max(1, (int)($_GET['page'] ?? 1));
        $month = trim($_GET['month'] ?? '');
        $monthStr = preg_match('/^\d{4}-\d{2}$/', $month) ? $month : null;

        $result = Attendance::getLogsPage(
            $userIds, $perPage, ($page - 1) * $perPage, $monthStr,
            self::dateParam('from'), self::dateParam('to')
        );

        jsonResponse([
            'success'  => true,
            'scope'    => $scope,
            'logs'     => $result['rows'],
            'total'    => $result['total'],
            'page'     => $page,
            'per_page' => $perPage,
        ]);
    }

    public static function calendar(): void {
        $viewer = AuthMiddleware::requireLogin();
        $userIds = AuthMiddleware::resolveScopeUserIds($viewer, self::scopeParam(), self::userIdParam(), self::teamIdParam());

        $month = trim($_GET['month'] ?? '');
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)) {
            $month = date('Y-m');
        }

        jsonResponse(['success' => true, 'month' => $month] + Attendance::getCalendarMonth($userIds, $month));
    }

    /**
     * People (and, for admins, teams) the viewer can pick in a scope's filters.
     */
    public static function scopePeople(): void {
        $viewer = AuthMiddleware::requireLogin();
        $userIds = AuthMiddleware::resolveScopeUserIds($viewer, self::scopeParam());

        $people = [];
        if (!empty($userIds)) {
            $db = \App\Config\Database::getConnection();
            $in = implode(',', array_fill(0, count($userIds), '?'));
            $stmt = $db->prepare("SELECT id, name, job_title, team_id FROM users WHERE id IN ({$in}) ORDER BY name ASC");
            $stmt->execute($userIds);
            $people = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        }

        jsonResponse([
            'success' => true,
            'people'  => $people,
            'teams'   => $viewer['role'] === 'admin' ? \App\Models\Team::all() : [],
        ]);
    }

    public static function stats(): void {
        $viewer = AuthMiddleware::requireLogin();
        $scope = self::scopeParam();
        $selectedId = self::userIdParam();
        AuthMiddleware::resolveScopeUserIds($viewer, $scope, $selectedId);
        $groupIds = $scope === 'me'
            ? self::peerIds($viewer)
            : AuthMiddleware::resolveScopeUserIds($viewer, $scope, null, self::teamIdParam());

        $period = trim($_GET['period'] ?? 'this_week');

        switch ($period) {
            case 'last_week':
                $start = date('Y-m-d', strtotime('monday last week'));
                $end = date('Y-m-d', strtotime('sunday last week'));
                break;
            case 'this_month':
                $start = date('Y-m-01');
                $end = date('Y-m-t');
                break;
            case 'last_month':
                $start = date('Y-m-01', strtotime('first day of last month'));
                $end = date('Y-m-t', strtotime('last day of last month'));
                break;
            case 'this_week':
            default:
                $start = date('Y-m-d', strtotime('monday this week'));
                $end = date('Y-m-d', strtotime('sunday this week'));
                break;
        }

        jsonResponse([
            'success' => true,
            'period'  => $period,
            'me'      => [
                'name'     => $viewer['name'],
                'initials' => $viewer['avatar_initials'] ?? 'TR',
            ] + self::aggregateStats([$selectedId ?? (int)$viewer['id']], $start, $end),
            'team'    => self::aggregateStats($groupIds, $start, $end),
        ]);
    }

    /**
     * The "My team" comparison group for the viewer's own stats.
     */
    private static function peerIds(array $viewer): array {
        if ($viewer['role'] === 'admin') {
            return AuthMiddleware::resolveScopeUserIds($viewer, 'all');
        }
        if ($viewer['role'] === 'manager') {
            return AuthMiddleware::resolveScopeUserIds($viewer, 'team');
        }

        $db = \App\Config\Database::getConnection();
        $stmt = $db->prepare("
            SELECT p.id
            FROM users me
            JOIN users p ON p.manager_id = me.manager_id AND p.status = 'active'
            WHERE me.id = :uid
        ");
        $stmt->execute([':uid' => $viewer['id']]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN)) ?: [(int)$viewer['id']];
    }

    private static function aggregateStats(array $userIds, string $start, string $end): array {
        $none = ['avg_hours_str' => '0h 00m', 'on_time_pct' => null];
        if (empty($userIds)) {
            return $none;
        }

        $db = \App\Config\Database::getConnection();
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $db->prepare("
            SELECT COUNT(*) as days_worked,
                   COALESCE(SUM(work_seconds), 0) as total_work_seconds,
                   SUM(CASE WHEN is_late = 0 AND work_seconds > 0 THEN 1 ELSE 0 END) as on_time_count
            FROM attendance_records
            WHERE user_id IN ({$in}) AND date BETWEEN ? AND ? AND (status = 'present' OR work_seconds > 0)
        ");
        $stmt->execute([...array_values($userIds), $start, $end]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        $days = (int)($row['days_worked'] ?? 0);
        if ($days === 0) {
            return $none;
        }

        $avgSec = (int)round((int)$row['total_work_seconds'] / $days);
        return [
            'avg_hours_str' => sprintf('%dh %02dm', floor($avgSec / 3600), floor(($avgSec % 3600) / 60)),
            'on_time_pct'   => (int)round(((int)$row['on_time_count'] / $days) * 100),
        ];
    }

    private static function scopeParam(): string {
        return trim($_GET['scope'] ?? 'me');
    }

    private static function userIdParam(): ?int {
        $id = $_GET['user_id'] ?? '';
        return ($id !== '' && $id !== 'all') ? (int)$id : null;
    }

    private static function teamIdParam(): ?int {
        $id = (int)($_GET['team_id'] ?? 0);
        return $id > 0 ? $id : null;
    }

    private static function dateParam(string $key): ?string {
        $date = trim($_GET[$key] ?? '');
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : null;
    }

    /**
     * Correction requests in a scope, newest first.
     */
    public static function listCorrections(): void {
        $viewer = AuthMiddleware::requireLogin();
        $userIds = AuthMiddleware::resolveScopeUserIds($viewer, self::scopeParam(), self::userIdParam(), self::teamIdParam());

        $perPage = min(100, max(1, (int)($_GET['limit'] ?? 25)));
        $page = max(1, (int)($_GET['page'] ?? 1));

        $result = Correction::listForUsers(
            $userIds, self::dateParam('from'), self::dateParam('to'), $perPage, ($page - 1) * $perPage
        );

        jsonResponse([
            'success'     => true,
            'corrections' => $result['rows'],
            'total'       => $result['total'],
            'page'        => $page,
            'per_page'    => $perPage,
        ]);
    }

    public static function requestCorrection(): void {
        AuthMiddleware::requireCsrf();
        $user = AuthMiddleware::requireLogin();

        $date = trim($_POST['date'] ?? '');
        $requestedIn = trim($_POST['requested_in'] ?? '');
        $requestedOut = trim($_POST['requested_out'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if ($date === '' || $reason === '') {
            jsonResponse(['success' => false, 'error' => 'Please specify date and reason.'], 400);
        }

        $id = Correction::submit((int)$user['id'], [
            'date'          => $date,
            'requested_in'  => $requestedIn ?: null,
            'requested_out' => $requestedOut ?: null,
            'reason'        => $reason,
        ]);

        jsonResponse(['success' => true, 'id' => $id, 'message' => 'Correction request submitted for manager review.']);
    }

    public static function pendingCorrections(): void {
        $viewer = AuthMiddleware::requireManagerOrAdmin();
        $corrections = Correction::getPendingForApprover($viewer);
        jsonResponse(['success' => true, 'corrections' => $corrections]);
    }

    public static function actionCorrection(): void {
        AuthMiddleware::requireCsrf();
        $viewer = AuthMiddleware::requireManagerOrAdmin();
        AuthMiddleware::requirePermission('approve_corrections');
        $id = (int)($_POST['id'] ?? 0);
        $status = trim($_POST['status'] ?? '');
        $reason = trim($_POST['reason'] ?? '');

        if (!$id || !in_array($status, ['approved', 'rejected'], true)) {
            jsonResponse(['success' => false, 'error' => 'Invalid request.'], 400);
        }

        $db = \App\Config\Database::getConnection();
        $stmt = $db->prepare("SELECT user_id FROM attendance_corrections WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $targetUserId = (int)$stmt->fetchColumn();

        if (!$targetUserId || !AuthMiddleware::canAccessUser($viewer, $targetUserId)) {
            jsonResponse(['success' => false, 'error' => 'Forbidden: not authorized to action this correction.'], 403);
        }

        $res = Correction::action($id, $status, (int)$viewer['id'], $reason ?: null);
        if (!$res) {
            jsonResponse(['success' => false, 'error' => 'Failed to action correction request.'], 400);
        }

        jsonResponse(['success' => true, 'message' => "Correction request {$status} successfully."]);
    }
}

