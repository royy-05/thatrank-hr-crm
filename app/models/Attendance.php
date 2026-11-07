<?php
// app/models/Attendance.php

namespace App\Models;

use App\Config\Database;
use App\Services\AuditService;
use PDO;

class Attendance {
    public static function getTodayRecord(int $userId): ?array {
        $db = Database::getConnection();
        $today = date('Y-m-d');

        // Check if user has an approved leave today
        $leaveStmt = $db->prepare("
            SELECT lr.*, u.name as approver_name
            FROM leave_requests lr
            LEFT JOIN users u ON lr.actioned_by = u.id
            WHERE lr.user_id = :uid AND :today BETWEEN lr.start_date AND lr.end_date AND lr.status = 'approved'
            LIMIT 1
        ");
        $leaveStmt->execute([':uid' => $userId, ':today' => $today]);
        $leaveToday = $leaveStmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $db->prepare("SELECT * FROM attendance_records WHERE user_id = :uid AND date = :date LIMIT 1");
        $stmt->execute([':uid' => $userId, ':date' => $today]);
        $rec = $stmt->fetch();
        if ($rec) {
            // Compute real-time elapsed seconds for active sessions
            if ($rec['current_status'] === 'clocked_in' && !empty($rec['last_status_time'])) {
                $elapsed = time() - strtotime($rec['last_status_time']);
                if ($elapsed > 0) {
                    $rec['effective_work_seconds'] = (int)$rec['work_seconds'] + $elapsed;
                } else {
                    $rec['effective_work_seconds'] = (int)$rec['work_seconds'];
                }
                $rec['effective_break_seconds'] = (int)$rec['break_seconds'];
            } elseif ($rec['current_status'] === 'on_break' && !empty($rec['last_status_time'])) {
                $elapsed = time() - strtotime($rec['last_status_time']);
                if ($elapsed > 0) {
                    $rec['effective_break_seconds'] = (int)$rec['break_seconds'] + $elapsed;
                } else {
                    $rec['effective_break_seconds'] = (int)$rec['break_seconds'];
                }
                $rec['effective_work_seconds'] = (int)$rec['work_seconds'];
            } else {
                $rec['effective_work_seconds'] = (int)$rec['work_seconds'];
                $rec['effective_break_seconds'] = (int)$rec['break_seconds'];
            }

            $bCountStmt = $db->prepare("SELECT COUNT(*) FROM punch_logs WHERE attendance_record_id = :id AND type = 'break_start'");
            $bCountStmt->execute([':id' => $rec['id']]);
            $rec['break_count'] = (int)$bCountStmt->fetchColumn();
            $bSec = (int)($rec['effective_break_seconds'] ?? $rec['break_seconds']);
            $bFmt = self::formatDuration($bSec);
            $rec['break_str'] = $rec['break_count'] > 0 ? sprintf('%d %s (%s)', $rec['break_count'], $rec['break_count'] === 1 ? 'break' : 'breaks', $bFmt) : $bFmt;

            if ($leaveToday) {
                $rec['is_on_leave'] = true;
                $rec['leave_type'] = $leaveToday['leave_type'];
                $rec['leave_label'] = ucfirst($leaveToday['leave_type']);
                $rec['leave_approver'] = $leaveToday['approver_name'] ?? 'Manager';
                if ($rec['current_status'] === 'clocked_out') {
                    $rec['status'] = 'on_leave';
                }
            }
        } elseif ($leaveToday) {
            $rec = [
                'id'                     => 0,
                'user_id'                => $userId,
                'date'                   => $today,
                'status'                 => 'on_leave',
                'current_status'         => 'clocked_out',
                'first_in'               => null,
                'last_out'               => null,
                'work_seconds'           => 0,
                'break_seconds'          => 0,
                'gross_seconds'          => 0,
                'effective_work_seconds' => 0,
                'effective_break_seconds'=> 0,
                'break_count'            => 0,
                'break_str'              => '0h 00m',
                'is_on_leave'            => true,
                'leave_type'             => $leaveToday['leave_type'],
                'leave_label'            => ucfirst($leaveToday['leave_type']),
                'leave_approver'         => $leaveToday['approver_name'] ?? 'Manager',
                'notes'                  => ucfirst($leaveToday['leave_type']) . ' leave approved',
            ];
        }
        return $rec ?: null;
    }

    public static function punch(int $userId, string $type, array $metadata = []): array {
        $db = Database::getConnection();
        $today = date('Y-m-d');
        $now = date('Y-m-d H:i:s');
        $timeOnly = date('H:i:s');

        // Fetch settings
        $settings = Setting::getAllAsMap();
        $shiftStart = $settings['shift_start_time'] ?? '10:00:00';
        $graceMin = (int)($settings['late_grace_minutes'] ?? 15);
        $targetHours = (int)($settings['daily_target_hours'] ?? 9);
        $targetSeconds = $targetHours * 3600;

        $record = self::getTodayRecord($userId);
        $oldRecord = $record;

        $db->beginTransaction();

        try {
            if (!$record) {
                // First punch of the day: can only be 'in'
                if ($type !== 'in') {
                    $type = 'in';
                }

                // Calculate late
                $shiftStartWithGrace = date('H:i:s', strtotime($shiftStart) + ($graceMin * 60));
                $sStartSec = strtotime($shiftStart);
                $punchSec = strtotime($timeOnly);
                $diffFromStartSec = $punchSec - $sStartSec;

                $isLate = 0;
                $lateMinutes = 0;
                $notes = null;

                if ($diffFromStartSec > ($graceMin * 60)) {
                    $isLate = 1;
                    $lateMinutes = max(1, (int)round($diffFromStartSec / 60));
                } elseif ($diffFromStartSec < -7200) {
                    // Punched more than 2 hours before shift start
                    $notes = 'Punch recorded outside standard shift hours';
                }

                $stmt = $db->prepare("
                    INSERT INTO attendance_records
                    (user_id, date, status, first_in, current_status, last_status_time, is_late, late_minutes, work_seconds, break_seconds, gross_seconds, notes, created_at)
                    VALUES (:uid, :date, 'present', :first_in, 'clocked_in', :last_status, :is_late, :late_min, 0, 0, 0, :notes, NOW())
                ");
                $stmt->execute([
                    ':uid'         => $userId,
                    ':date'        => $today,
                    ':first_in'    => $timeOnly,
                    ':last_status' => $now,
                    ':is_late'     => $isLate,
                    ':late_min'    => $lateMinutes,
                    ':notes'       => $notes,
                ]);
                $recordId = (int)$db->lastInsertId();
            } else {
                $recordId = (int)$record['id'];
                $currentStatus = $record['current_status'];
                $lastStatusTime = $record['last_status_time'] ? strtotime($record['last_status_time']) : time();
                $elapsed = max(0, time() - $lastStatusTime);

                $workSec = (int)$record['work_seconds'];
                $breakSec = (int)$record['break_seconds'];

                if ($type === 'break_start') {
                    if ($currentStatus === 'clocked_in') {
                        $workSec += $elapsed;
                    }
                    $newStatus = 'on_break';
                } elseif ($type === 'break_end') {
                    if ($currentStatus === 'on_break') {
                        $breakSec += $elapsed;
                    }
                    $newStatus = 'clocked_in';
                } elseif ($type === 'out') {
                    if ($currentStatus === 'clocked_in') {
                        $workSec += $elapsed;
                    } elseif ($currentStatus === 'on_break') {
                        $breakSec += $elapsed;
                    }
                    $newStatus = 'clocked_out';
                } else { // 'in' re-entry
                    $newStatus = 'clocked_in';
                }

                $grossSec = $workSec + $breakSec;
                $overtime = max(0, $workSec - $targetSeconds);

                $updateSql = "
                    UPDATE attendance_records
                    SET current_status = :new_status,
                        last_status_time = :last_status,
                        work_seconds = :work_sec,
                        break_seconds = :break_sec,
                        gross_seconds = :gross_sec,
                        overtime_seconds = :overtime,
                        " . ($type === 'out' ? "last_out = :last_out," : "") . "
                        updated_at = NOW()
                    WHERE id = :id
                ";
                $updateParams = [
                    ':new_status'   => $newStatus,
                    ':last_status'  => $now,
                    ':work_sec'     => $workSec,
                    ':break_sec'    => $breakSec,
                    ':gross_sec'    => $grossSec,
                    ':overtime'     => $overtime,
                    ':id'           => $recordId,
                ];
                if ($type === 'out') {
                    $updateParams[':last_out'] = $timeOnly;
                }

                $stmt = $db->prepare($updateSql);
                $stmt->execute($updateParams);
            }

            // Save selfie if provided
            $selfiePath = null;
            if (!empty($metadata['selfie'])) {
                $selfiePath = self::saveSelfie($userId, $metadata['selfie']);
            }

            // Insert punch log
            $logStmt = $db->prepare("
                INSERT INTO punch_logs
                (attendance_record_id, user_id, type, punched_at, selfie_path, latitude, longitude, location_name, device_info, ip_address, created_at)
                VALUES (:rec_id, :uid, :type, :punched_at, :selfie, :lat, :lon, :loc_name, :dev_info, :ip, NOW())
            ");
            $logStmt->execute([
                ':rec_id'     => $recordId,
                ':uid'        => $userId,
                ':type'       => $type,
                ':punched_at' => $now,
                ':selfie'     => $selfiePath,
                ':lat'        => !empty($metadata['latitude']) ? (float)$metadata['latitude'] : null,
                ':lon'        => !empty($metadata['longitude']) ? (float)$metadata['longitude'] : null,
                ':loc_name'   => $metadata['location_name'] ?? 'Home, Chandigarh',
                ':dev_info'   => $metadata['device_info'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? 'Web browser'),
                ':ip'         => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ]);

            // Update heartbeat
            Heartbeat::recordBeat($userId, $type === 'out' ? 'off' : ($type === 'break_start' ? 'break' : 'working'));

            $newRecord = self::getTodayRecord($userId);

            // Audit log
            AuditService::log($userId, 'punch_' . $type, 'punch', $recordId, $oldRecord, $newRecord);

            $db->commit();

            return ['success' => true, 'record' => $newRecord];
        } catch (\Throwable $e) {
            $db->rollBack();
            error_log("Punch failed: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private static function saveSelfie(int $userId, string $base64Data): ?string {
        if (!preg_match('/^data:image\/(\w+);base64,/', $base64Data, $type)) {
            return null;
        }
        $data = substr($base64Data, strpos($base64Data, ',') + 1);
        $decoded = base64_decode($data);
        if (!$decoded) {
            return null;
        }

        $ext = strtolower($type[1]);
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $ext = 'jpg';
        }

        $fileName = 'selfie_' . $userId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dir = dirname(__DIR__, 2) . '/storage/selfies';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $fullPath = $dir . '/' . $fileName;
        file_put_contents($fullPath, $decoded);
        return 'storage/selfies/' . $fileName;
    }

    public static function getRecentLogs(int $userId, ?int $limit = 30, ?string $month = null): array {
        return self::getLogsForUsers([$userId], $limit ?? 30, $month);
    }

    public static function getAllRecentLogs(int $limit = 60, ?string $month = null, ?int $managerId = null): array {
        $db = Database::getConnection();
        if ($managerId !== null) {
            $stmt = $db->prepare("SELECT id FROM users WHERE manager_id = :mid AND status = 'active'");
            $stmt->execute([':mid' => $managerId]);
            $uids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
            return self::getLogsForUsers($uids, $limit ?? 60, $month);
        }

        $stmt = $db->query("SELECT id FROM users WHERE status = 'active'");
        $uids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        return self::getLogsForUsers($uids, $limit ?? 60, $month);
    }

    public static function getLogsForUsers(array $userIds, int $limit = 60, ?string $month = null): array {
        return self::getLogsPage($userIds, $limit, 0, $month)['rows'];
    }

    /**
     * One page of day rows for the given users. A date range wins over a month;
     * with neither, returns the last 30 days plus any upcoming approved leave.
     *
     * With several users, weekly offs and holidays collapse into a single row per
     * date (user_id 0) instead of repeating once per person.
     *
     * @return array{rows: array, total: int}
     */
    public static function getLogsPage(array $userIds, int $limit = 60, int $offset = 0, ?string $month = null, ?string $from = null, ?string $to = null): array {
        $userIds = array_values(array_map('intval', $userIds));
        if (empty($userIds)) {
            return ['rows' => [], 'total' => 0];
        }

        $today = date('Y-m-d');
        if ($from !== null || $to !== null) {
            $rangeEnd = $to ?? $today;
            $rangeStart = $from ?? date('Y-m-d', strtotime($rangeEnd . ' -30 days'));
            $fetchEnd = $rangeEnd;
            $rangeEnd = min($rangeEnd, $today);
        } elseif ($month && preg_match('/^\d{4}-\d{2}$/', $month)) {
            $rangeStart = $month . '-01';
            $fetchEnd = date('Y-m-t', strtotime($rangeStart));
            $rangeEnd = min($fetchEnd, $today);
        } else {
            $rangeStart = date('Y-m-d', strtotime('-30 days'));
            $rangeEnd = $today;
            $fetchEnd = '9999-12-31';
        }

        $rows = self::collectDayRows($userIds, $rangeStart, $rangeEnd, $fetchEnd);

        usort($rows, function ($a, $b) {
            return strcmp($b['date'], $a['date'])
                ?: ((int)($a['user_id'] !== 0) <=> (int)($b['user_id'] !== 0))
                ?: strcmp($a['user_name'] ?? '', $b['user_name'] ?? '');
        });

        return [
            'rows'  => self::hydrateLogsWithVisuals(array_slice($rows, $offset, $limit)),
            'total' => count($rows),
        ];
    }

    /**
     * Build unhydrated day rows. Off days are generated up to $rangeEnd; attendance
     * records and approved leave are included up to $fetchEnd.
     */
    private static function collectDayRows(array $userIds, string $rangeStart, string $rangeEnd, string $fetchEnd): array {
        $db = Database::getConnection();
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $isMulti = count($userIds) > 1;

        $stmt = $db->prepare("
            SELECT ar.*, u.name as user_name, u.avatar_initials, u.avatar_color, u.job_title
            FROM attendance_records ar
            JOIN users u ON ar.user_id = u.id
            WHERE ar.user_id IN ({$in}) AND ar.date BETWEEN ? AND ?
        ");
        $stmt->execute([...$userIds, $rangeStart, $fetchEnd]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $leaves = self::approvedLeavesFor($userIds, $rangeStart, $fetchEnd);
        $holidays = self::holidayMap();
        $mondayToFriday = self::isMondayToFriday();

        $uStmt = $db->prepare("SELECT id, name as user_name, avatar_initials, avatar_color, job_title FROM users WHERE id IN ({$in})");
        $uStmt->execute($userIds);
        $userMap = [];
        foreach ($uStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $userMap[(int)$u['id']] = $u;
        }

        $rows = [];
        $seen = [];

        foreach ($records as $r) {
            $seen[$r['user_id'] . '_' . $r['date']] = true;
            $leave = self::leaveOnDate($leaves, (int)$r['user_id'], $r['date']);

            $hasPunches = !empty($r['first_in']) || (int)$r['work_seconds'] > 0 || $r['current_status'] === 'clocked_in';
            $r['has_punches'] = $hasPunches;

            if ($hasPunches) {
                $r['status'] = 'present';
                if ($leave) {
                    $r['show_leave_pill'] = true;
                    $r['leave_label'] = ucfirst($leave['leave_type']);
                }
            } elseif ($leave) {
                $r['status'] = 'on_leave';
                $r['notes'] = self::leaveNote($leave);
            } elseif (isset($holidays[$r['date']])) {
                if ($isMulti) continue;
                $r['status'] = 'holiday';
                $r['notes'] = $holidays[$r['date']];
            } elseif (self::isWeeklyOff($r['date'], $mondayToFriday)) {
                if ($isMulti) continue;
                $r['status'] = 'weekly_off';
                $r['notes'] = 'Full day weekly off';
            } else {
                $r['status'] = 'present';
            }
            $rows[] = $r;
        }

        // Approved leave days with no attendance record
        foreach ($leaves as $l) {
            $uid = (int)$l['user_id'];
            if (!isset($userMap[$uid])) continue;
            $first = strtotime(max($l['start_date'], $rangeStart));
            $last = strtotime(min($l['end_date'], $fetchEnd));
            for ($ts = $first; $ts <= $last; $ts = strtotime('+1 day', $ts)) {
                $d = date('Y-m-d', $ts);
                if (isset($seen[$uid . '_' . $d])) continue;
                $seen[$uid . '_' . $d] = true;
                $rows[] = self::blankDayRow($uid, $d, 'on_leave', self::leaveNote($l), $userMap[$uid]);
            }
        }

        // Holidays and weekly offs. A single user keeps any row they already have for that date.
        $everyone = ['user_name' => 'Everyone', 'avatar_initials' => '', 'avatar_color' => '', 'job_title' => ''];
        $rowUid = $isMulti ? 0 : $userIds[0];
        $rowUser = $isMulti ? $everyone : ($userMap[$rowUid] ?? $everyone);
        $startTs = strtotime($rangeStart);
        for ($ts = strtotime($rangeEnd); $ts >= $startTs; $ts = strtotime('-1 day', $ts)) {
            $d = date('Y-m-d', $ts);
            if (!$isMulti && isset($seen[$rowUid . '_' . $d])) continue;
            if (isset($holidays[$d])) {
                $rows[] = self::blankDayRow($rowUid, $d, 'holiday', $holidays[$d], $rowUser);
            } elseif (self::isWeeklyOff($d, $mondayToFriday)) {
                $rows[] = self::blankDayRow($rowUid, $d, 'weekly_off', 'Full day weekly off', $rowUser);
            }
        }

        return $rows;
    }

    /**
     * Per-day attendance for a month, for the calendar view. With one user each day
     * carries that person's status; with several, `present` counts how many came in.
     *
     * @return array{days: array, people: int}
     */
    public static function getCalendarMonth(array $userIds, string $month): array {
        $userIds = array_values(array_map('intval', $userIds));
        if (empty($userIds)) {
            return ['days' => [], 'people' => 0];
        }

        $db = Database::getConnection();
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $monthStart = $month . '-01';
        $monthEnd = date('Y-m-t', strtotime($monthStart));
        $today = date('Y-m-d');

        $stmt = $db->prepare("
            SELECT user_id, date, first_in, last_out, work_seconds, is_late, late_minutes, current_status
            FROM attendance_records
            WHERE user_id IN ({$in}) AND date BETWEEN ? AND ?
              AND (first_in IS NOT NULL OR work_seconds > 0 OR current_status = 'clocked_in')
        ");
        $stmt->execute([...$userIds, $monthStart, $monthEnd]);
        $byDate = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $byDate[$r['date']][(int)$r['user_id']] = $r;
        }

        $jStmt = $db->prepare("SELECT id, joined_date FROM users WHERE id IN ({$in})");
        $jStmt->execute($userIds);
        $joined = [];
        foreach ($jStmt->fetchAll(PDO::FETCH_ASSOC) as $u) {
            $joined[(int)$u['id']] = $u['joined_date'];
        }

        $leaves = self::approvedLeavesFor($userIds, $monthStart, $monthEnd);
        $holidays = self::holidayMap();
        $mondayToFriday = self::isMondayToFriday();

        $days = [];
        $endTs = strtotime($monthEnd);
        for ($ts = strtotime($monthStart); $ts <= $endTs; $ts = strtotime('+1 day', $ts)) {
            $d = date('Y-m-d', $ts);
            $punched = $byDate[$d] ?? [];

            $onLeave = 0;
            $leaveType = null;
            $employed = 0;
            foreach ($userIds as $uid) {
                if (($joined[$uid] ?? '9999-12-31') <= $d) $employed++;
                if (isset($punched[$uid])) continue;
                $leave = self::leaveOnDate($leaves, $uid, $d);
                if ($leave) {
                    $onLeave++;
                    $leaveType = $leave['leave_type'];
                }
            }

            $present = count($punched);
            if ($present > 0) {
                $status = 'present';
            } elseif ($onLeave > 0) {
                $status = 'leave';
            } elseif (isset($holidays[$d])) {
                $status = 'holiday';
            } elseif (self::isWeeklyOff($d, $mondayToFriday)) {
                $status = 'weekly_off';
            } elseif ($d >= $today || $employed === 0) {
                $status = 'none';
            } else {
                $status = 'absent';
            }

            $day = [
                'date'    => $d,
                'status'  => $status,
                'present' => $present,
                'leave'   => $onLeave,
                'late'    => count(array_filter($punched, fn($r) => (int)$r['is_late'] === 1)),
                'label'   => $holidays[$d] ?? ($leaveType ? ucfirst($leaveType) . ' leave' : null),
            ];

            if (count($userIds) === 1 && $present === 1) {
                $r = reset($punched);
                $day['first_in'] = $r['first_in'];
                $day['last_out'] = $r['last_out'];
                $day['work_str'] = self::formatDuration((int)$r['work_seconds']);
                $day['late_minutes'] = (int)$r['late_minutes'];
            }
            $days[] = $day;
        }

        return ['days' => $days, 'people' => count($userIds)];
    }

    private static function approvedLeavesFor(array $userIds, string $from, string $to): array {
        $db = Database::getConnection();
        $in = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $db->prepare("
            SELECT lr.user_id, lr.start_date, lr.end_date, lr.leave_type, u.name as approver_name
            FROM leave_requests lr
            LEFT JOIN users u ON lr.actioned_by = u.id
            WHERE lr.user_id IN ({$in}) AND lr.status = 'approved' AND lr.end_date >= ? AND lr.start_date <= ?
        ");
        $stmt->execute([...$userIds, $from, $to]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function leaveOnDate(array $leaves, int $userId, string $date): ?array {
        foreach ($leaves as $l) {
            if ((int)$l['user_id'] === $userId && $date >= $l['start_date'] && $date <= $l['end_date']) {
                return $l;
            }
        }
        return null;
    }

    private static function leaveNote(array $leave): string {
        return ucfirst($leave['leave_type']) . ' leave, approved by ' . ($leave['approver_name'] ?? 'Manager');
    }

    private static function holidayMap(): array {
        $db = Database::getConnection();
        return $db->query("SELECT date, name FROM holidays")->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    private static function isMondayToFriday(): bool {
        return str_contains(strtolower(Setting::getAllAsMap()['work_week'] ?? 'monday to friday'), 'friday');
    }

    private static function isWeeklyOff(string $date, bool $mondayToFriday): bool {
        $dow = (int)date('w', strtotime($date));
        return $mondayToFriday ? ($dow === 0 || $dow === 6) : ($dow === 0);
    }

    private static function blankDayRow(int $userId, string $date, string $status, string $notes, array $user): array {
        return [
            'id'              => 0,
            'user_id'         => $userId,
            'date'            => $date,
            'status'          => $status,
            'current_status'  => 'clocked_out',
            'first_in'        => null,
            'last_out'        => null,
            'work_seconds'    => 0,
            'break_seconds'   => 0,
            'gross_seconds'   => 0,
            'is_late'         => 0,
            'late_minutes'    => 0,
            'notes'           => $notes,
            'user_name'       => $user['user_name'],
            'avatar_initials' => $user['avatar_initials'],
            'avatar_color'    => $user['avatar_color'],
            'job_title'       => $user['job_title'],
            'has_punches'     => false,
        ];
    }

    private static function hydrateLogsWithVisuals(array $rows): array {
        if (empty($rows)) {
            return [];
        }

        $db = Database::getConnection();
        $settings = Setting::getAllAsMap();
        $shiftStartStr = $settings['shift_start_time'] ?? '10:00:00';
        $shiftEndStr = $settings['shift_end_time'] ?? '19:00:00';
        $graceMin = (int)($settings['late_grace_minutes'] ?? 15);

        $sStartParts = explode(':', $shiftStartStr);
        $shiftStartSec = (int)$sStartParts[0] * 3600 + (int)($sStartParts[1] ?? 0) * 60;
        $sEndParts = explode(':', $shiftEndStr);
        $shiftEndSec = (int)$sEndParts[0] * 3600 + (int)($sEndParts[1] ?? 0) * 60;
        if ($shiftEndSec <= $shiftStartSec) {
            $shiftEndSec = $shiftStartSec + (9 * 3600);
        }

        // Visual window: from 1 hour before shift start to 1 hour after shift end (min 10 hours)
        $windowStart = max(0, $shiftStartSec - 3600);
        $windowTotal = max(36000, ($shiftEndSec + 3600) - $windowStart);

        $punchStmt = $db->prepare("
            SELECT type as punch_type, type, DATE_FORMAT(punched_at, '%H:%i:%s') as punch_time, DATE_FORMAT(punched_at, '%H:%i') as time_str
            FROM punch_logs
            WHERE attendance_record_id = :rec_id
            ORDER BY punched_at ASC
        ");

        foreach ($rows as &$r) {
            // Real-time calculation for active records today
            if ($r['current_status'] === 'clocked_in' && !empty($r['last_status_time']) && $r['date'] === date('Y-m-d')) {
                $elapsed = max(0, time() - strtotime($r['last_status_time']));
                $r['effective_work_seconds'] = (int)$r['work_seconds'] + $elapsed;
                $r['work_seconds'] = $r['effective_work_seconds'];
                $r['gross_seconds'] = (int)$r['work_seconds'] + (int)$r['break_seconds'];
            } elseif ($r['current_status'] === 'on_break' && !empty($r['last_status_time']) && $r['date'] === date('Y-m-d')) {
                $elapsed = max(0, time() - strtotime($r['last_status_time']));
                $r['effective_break_seconds'] = (int)$r['break_seconds'] + $elapsed;
                $r['break_seconds'] = $r['effective_break_seconds'];
                $r['gross_seconds'] = (int)$r['work_seconds'] + (int)$r['break_seconds'];
            }

            $r['anomalies'] = \App\Services\AnomalyService::detectRecordAnomalies($r);

            // Fetch punch timeline segments
            $punchStmt->execute([':rec_id' => $r['id']]);
            $punches = $punchStmt->fetchAll(PDO::FETCH_ASSOC);
            $r['punches'] = $punches;

            $bCount = 0;
            foreach ($punches as $p) {
                if ($p['punch_type'] === 'break_start') {
                    $bCount++;
                }
            }
            $r['break_count'] = $bCount;
            $bSec = (int)($r['break_seconds'] ?? 0);
            $bFmt = self::formatDuration($bSec);
            $r['break_str'] = $bCount > 0 ? sprintf('%d %s (%s)', $bCount, $bCount === 1 ? 'break' : 'breaks', $bFmt) : $bFmt;

            $segments = [];
            $inPct = 0.0;
            $outPct = 0.0;
            $arrivalStatus = 'on_time';
            $arrivalText = 'On time';

            if (!empty($r['first_in'])) {
                $tParts = explode(':', $r['first_in']);
                $firstInSec = (int)$tParts[0] * 3600 + (int)($tParts[1] ?? 0) * 60 + (int)($tParts[2] ?? 0);
                $diffSec = $firstInSec - $shiftStartSec;

                if ($r['is_late'] == 1 || $diffSec > ($graceMin * 60)) {
                    $lateM = max(1, (int)round($diffSec / 60));
                    $arrivalStatus = 'late';
                    $arrivalText = $lateM . ' min late';
                } elseif ($diffSec < -7200) {
                    $arrivalStatus = 'outside_hours';
                    $arrivalText = 'Outside shift hours';
                } elseif ($diffSec < -1800) {
                    $earlyM = (int)round(abs($diffSec) / 60);
                    $arrivalStatus = 'early';
                    $arrivalText = 'Early (' . $earlyM . 'm)';
                } else {
                    $arrivalStatus = 'on_time';
                    $arrivalText = 'On time';
                }

                $inPct = max(0.0, min(100.0, round(($firstInSec - $windowStart) / $windowTotal * 100, 1)));

                $lastOutSec = $firstInSec;
                if (!empty($r['last_out'])) {
                    $oParts = explode(':', $r['last_out']);
                    $lastOutSec = (int)$oParts[0] * 3600 + (int)($oParts[1] ?? 0) * 60 + (int)($oParts[2] ?? 0);
                } elseif ($r['current_status'] === 'clocked_in' && $r['date'] === date('Y-m-d')) {
                    $nowSec = (int)date('H') * 3600 + (int)date('i') * 60 + (int)date('s');
                    $lastOutSec = max($firstInSec, $nowSec);
                }
                $outPct = max(0.0, min(100.0, round(($lastOutSec - $windowStart) / $windowTotal * 100, 1)));

                // Calculate punch segments
                $currentInSec = null;
                $currentBreakSec = null;
                foreach ($punches as $p) {
                    $pParts = explode(':', $p['punch_time']);
                    $pSec = (int)$pParts[0] * 3600 + (int)($pParts[1] ?? 0) * 60 + (int)($pParts[2] ?? 0);

                    if ($p['punch_type'] === 'in' || $p['punch_type'] === 'break_end') {
                        if ($currentBreakSec !== null) {
                            $brkStartPct = max(0.0, min(100.0, round(($currentBreakSec - $windowStart) / $windowTotal * 100, 1)));
                            $brkEndPct = max(0.0, min(100.0, round(($pSec - $windowStart) / $windowTotal * 100, 1)));
                            $w = max(1.0, $brkEndPct - $brkStartPct);
                            $segments[] = ['type' => 'break', 'left' => $brkStartPct, 'width' => $w, 'start_pct' => $brkStartPct, 'width_pct' => $w];
                            $currentBreakSec = null;
                        }
                        $currentInSec = $pSec;
                    } elseif ($p['punch_type'] === 'break_start') {
                        if ($currentInSec !== null) {
                            $wStartPct = max(0.0, min(100.0, round(($currentInSec - $windowStart) / $windowTotal * 100, 1)));
                            $wEndPct = max(0.0, min(100.0, round(($pSec - $windowStart) / $windowTotal * 100, 1)));
                            $w = max(1.0, $wEndPct - $wStartPct);
                            $segments[] = ['type' => 'work', 'left' => $wStartPct, 'width' => $w, 'start_pct' => $wStartPct, 'width_pct' => $w];
                            $currentInSec = null;
                        }
                        $currentBreakSec = $pSec;
                    } elseif ($p['punch_type'] === 'out') {
                        if ($currentInSec !== null) {
                            $wStartPct = max(0.0, min(100.0, round(($currentInSec - $windowStart) / $windowTotal * 100, 1)));
                            $wEndPct = max(0.0, min(100.0, round(($pSec - $windowStart) / $windowTotal * 100, 1)));
                            $w = max(1.0, $wEndPct - $wStartPct);
                            $segments[] = ['type' => 'work', 'left' => $wStartPct, 'width' => $w, 'start_pct' => $wStartPct, 'width_pct' => $w];
                            $currentInSec = null;
                        }
                    }
                }

                if ($currentInSec !== null) {
                    $wStartPct = max(0.0, min(100.0, round(($currentInSec - $windowStart) / $windowTotal * 100, 1)));
                    $wEndPct = max(0.0, min(100.0, round(($lastOutSec - $windowStart) / $windowTotal * 100, 1)));
                    $w = max(1.0, $wEndPct - $wStartPct);
                    $segments[] = ['type' => 'work', 'left' => $wStartPct, 'width' => $w, 'start_pct' => $wStartPct, 'width_pct' => $w];
                }
                if ($currentBreakSec !== null) {
                    $brkStartPct = max(0.0, min(100.0, round(($currentBreakSec - $windowStart) / $windowTotal * 100, 1)));
                    $brkEndPct = max(0.0, min(100.0, round(($lastOutSec - $windowStart) / $windowTotal * 100, 1)));
                    $w = max(1.0, $brkEndPct - $brkStartPct);
                    $segments[] = ['type' => 'break', 'left' => $brkStartPct, 'width' => $w, 'start_pct' => $brkStartPct, 'width_pct' => $w];
                }
            }

            if (empty($segments) && $inPct < $outPct) {
                $w = max(1.0, $outPct - $inPct);
                $segments[] = ['type' => 'work', 'left' => $inPct, 'width' => $w, 'start_pct' => $inPct, 'width_pct' => $w];
            }

            $r['arrival_status'] = $arrivalStatus;
            $r['arrival_text'] = $arrivalText;
            $r['visual_in_pct'] = $inPct;
            $r['visual_out_pct'] = $outPct;
            $r['visual_segments'] = $segments;
        }
        return $rows;
    }

    public static function getPunchTimelineToday(int $userId): array {
        $db = Database::getConnection();
        $today = date('Y-m-d');
        $stmt = $db->prepare("
            SELECT p.*, DATE_FORMAT(p.punched_at, '%H:%i') as time_str
            FROM punch_logs p
            JOIN attendance_records a ON p.attendance_record_id = a.id
            WHERE p.user_id = :uid AND a.date = :today
            ORDER BY p.punched_at ASC
        ");
        $stmt->execute([':uid' => $userId, ':today' => $today]);
        return $stmt->fetchAll();
    }

    public static function getWeeklyHours(int $userId): array {
        $db = Database::getConnection();
        // Get Monday to Friday of current week
        $startOfWeek = date('Y-m-d', strtotime('monday this week'));
        $endOfWeek = date('Y-m-d', strtotime('sunday this week'));

        $stmt = $db->prepare("
            SELECT date, work_seconds, is_late, status
            FROM attendance_records
            WHERE user_id = :uid AND date BETWEEN :start AND :end
            ORDER BY date ASC
        ");
        $stmt->execute([':uid' => $userId, ':start' => $startOfWeek, ':end' => $endOfWeek]);
        $rows = $stmt->fetchAll();

        $weekMap = [];
        $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];
        $curr = strtotime($startOfWeek);
        for ($i = 0; $i < 5; $i++) {
            $d = date('Y-m-d', $curr);
            $weekMap[$d] = [
                'day'     => $days[$i],
                'date'    => $d,
                'hours'   => 0.0,
                'is_today'=> ($d === date('Y-m-d')),
                'is_empty'=> true,
            ];
            $curr = strtotime('+1 day', $curr);
        }

        foreach ($rows as $r) {
            if (isset($weekMap[$r['date']])) {
                $hrs = round((int)$r['work_seconds'] / 3600, 1);
                $weekMap[$r['date']]['hours'] = $hrs;
                $weekMap[$r['date']]['is_empty'] = ($hrs <= 0);
            }
        }

        return array_values($weekMap);
    }

    public static function getLatestPunch(int $userId): ?array {
        $db = Database::getConnection();
        $today = date('Y-m-d');
        $stmt = $db->prepare("
            SELECT p.*, a.date
            FROM punch_logs p
            JOIN attendance_records a ON p.attendance_record_id = a.id
            WHERE p.user_id = :uid AND a.date = :today
            ORDER BY p.punched_at DESC
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId, ':today' => $today]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;

        $secondsAgo = time() - strtotime($row['punched_at']);
        $row['seconds_ago'] = max(0, $secondsAgo);
        $row['can_edit'] = ($secondsAgo <= 900); // 15 minutes = 900s
        $row['minutes_left'] = max(0, ceil((900 - $secondsAgo) / 60));
        return $row;
    }

    public static function undoLatestPunch(int $userId): array {
        $db = Database::getConnection();
        $latest = self::getLatestPunch($userId);
        if (!$latest) {
            return ['success' => false, 'error' => 'No punches found today to undo.'];
        }
        if (!$latest['can_edit']) {
            return ['success' => false, 'error' => 'The 15-minute edit window has expired. Please submit a manager correction request.'];
        }

        $recordId = (int)$latest['attendance_record_id'];
        $oldRecord = self::getTodayRecord($userId);

        $db->beginTransaction();
        try {
            $del = $db->prepare("DELETE FROM punch_logs WHERE id = :id");
            $del->execute([':id' => $latest['id']]);

            $remStmt = $db->prepare("
                SELECT * FROM punch_logs
                WHERE attendance_record_id = :rec_id
                ORDER BY punched_at ASC
            ");
            $remStmt->execute([':rec_id' => $recordId]);
            $remaining = $remStmt->fetchAll(PDO::FETCH_ASSOC);

            if (empty($remaining)) {
                $delRec = $db->prepare("DELETE FROM attendance_records WHERE id = :id");
                $delRec->execute([':id' => $recordId]);
                Heartbeat::recordBeat($userId, 'off');
                $newRecord = null;
            } else {
                $workSec = 0;
                $breakSec = 0;
                $firstIn = date('H:i:s', strtotime($remaining[0]['punched_at']));
                $lastOut = null;
                $prevTime = strtotime($remaining[0]['punched_at']);
                $prevState = 'clocked_in';

                for ($i = 1; $i < count($remaining); $i++) {
                    $currTime = strtotime($remaining[$i]['punched_at']);
                    $elapsed = max(0, $currTime - $prevTime);
                    if ($prevState === 'clocked_in') {
                        $workSec += $elapsed;
                    } elseif ($prevState === 'on_break') {
                        $breakSec += $elapsed;
                    }

                    $pType = $remaining[$i]['type'];
                    if ($pType === 'break_start') {
                        $prevState = 'on_break';
                    } elseif ($pType === 'break_end') {
                        $prevState = 'clocked_in';
                    } elseif ($pType === 'out') {
                        $prevState = 'clocked_out';
                        $lastOut = date('H:i:s', $currTime);
                    }
                    $prevTime = $currTime;
                }

                $lastPunch = end($remaining);
                $currentStatus = ($lastPunch['type'] === 'out') ? 'clocked_out' :
                                 (($lastPunch['type'] === 'break_start') ? 'on_break' : 'clocked_in');
                $lastStatusTime = $lastPunch['punched_at'];

                $up = $db->prepare("
                    UPDATE attendance_records
                    SET current_status = :st,
                        last_status_time = :lst,
                        first_in = :fi,
                        last_out = :lo,
                        work_seconds = :ws,
                        break_seconds = :bs,
                        gross_seconds = :gs,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $up->execute([
                    ':st'  => $currentStatus,
                    ':lst' => $lastStatusTime,
                    ':fi'  => $firstIn,
                    ':lo'  => $lastOut,
                    ':ws'  => $workSec,
                    ':bs'  => $breakSec,
                    ':gs'  => ($workSec + $breakSec),
                    ':id'  => $recordId,
                ]);

                Heartbeat::recordBeat($userId, $currentStatus === 'clocked_out' ? 'off' : ($currentStatus === 'on_break' ? 'break' : 'working'));
                $newRecord = self::getTodayRecord($userId);
            }

            AuditService::log($userId, 'punch_undo', 'attendance', $recordId, [
                'record' => $oldRecord,
                'undone_punch' => $latest
            ], [
                'record' => $newRecord
            ]);

            $db->commit();
            return ['success' => true, 'message' => 'Punch undone successfully.', 'record' => $newRecord];
        } catch (\Throwable $e) {
            $db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    public static function editLatestPunch(int $userId, string $newTime, ?string $note = null): array {
        $db = Database::getConnection();
        $latest = self::getLatestPunch($userId);
        if (!$latest) {
            return ['success' => false, 'error' => 'No punch found to edit.'];
        }
        if (!$latest['can_edit']) {
            return ['success' => false, 'error' => 'The 15-minute edit window has expired. Please submit a manager correction request.'];
        }

        $newTimeClean = trim($newTime);
        if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $newTimeClean)) {
            return ['success' => false, 'error' => 'Invalid time format. Please use HH:MM.'];
        }
        if (strlen($newTimeClean) === 5) {
            $newTimeClean .= ':00';
        }

        $today = date('Y-m-d');
        $newTimestamp = $today . ' ' . $newTimeClean;

        $db->beginTransaction();
        try {
            $oldPunch = $latest;
            $up = $db->prepare("UPDATE punch_logs SET punched_at = :pt WHERE id = :id");
            $up->execute([':pt' => $newTimestamp, ':id' => $latest['id']]);

            $recordId = (int)$latest['attendance_record_id'];
            $oldRecord = self::getTodayRecord($userId);

            $upRec = $db->prepare("UPDATE attendance_records SET last_status_time = :pt WHERE id = :id");
            $upRec->execute([':pt' => $newTimestamp, ':id' => $recordId]);

            $newRecord = self::getTodayRecord($userId);

            AuditService::log($userId, 'punch_edit', 'punch_log', (int)$latest['id'], $oldPunch, [
                'new_punched_at' => $newTimestamp,
                'note'           => $note
            ]);

            $db->commit();
            return ['success' => true, 'message' => 'Punch updated successfully.', 'record' => $newRecord];
        } catch (\Throwable $e) {
            $db->rollBack();
            return ['success' => false, 'error' => $e->getMessage()];
        }
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

