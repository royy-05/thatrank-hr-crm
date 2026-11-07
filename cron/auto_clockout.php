<?php
// cron/auto_clockout.php
// Runs via Hostinger hPanel cron (e.g. at 23:00 daily)

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Config\Database;
use App\Models\Setting;
use App\Services\AuditService;

echo "[" . date('Y-m-d H:i:s') . "] Starting auto clock-out cron...\n";

$db = Database::getConnection();
$settings = Setting::getAllAsMap();
$autoTime = $settings['auto_clock_out_time'] ?? '23:00:00';
$targetHours = (int)($settings['daily_target_hours'] ?? 9);
$targetSeconds = $targetHours * 3600;

$today = date('Y-m-d');

// Find all open sessions today
$stmt = $db->prepare("
    SELECT * FROM attendance_records
    WHERE date = :today AND current_status IN ('clocked_in', 'on_break')
");
$stmt->execute([':today' => $today]);
$openRecords = $stmt->fetchAll();

$count = 0;
foreach ($openRecords as $rec) {
    $lastStatusTime = !empty($rec['last_status_time']) ? strtotime($rec['last_status_time']) : time();
    $cutoffTime = min(time(), strtotime($today . ' ' . $autoTime));
    $elapsed = max(0, $cutoffTime - $lastStatusTime);

    $workSec = (int)$rec['work_seconds'];
    $breakSec = (int)$rec['break_seconds'];

    if ($rec['current_status'] === 'clocked_in') {
        $workSec += $elapsed;
    } else {
        $breakSec += $elapsed;
    }

    $grossSec = $workSec + $breakSec;
    $overtime = max(0, $workSec - $targetSeconds);

    $up = $db->prepare("
        UPDATE attendance_records
        SET current_status = 'clocked_out',
            last_out = :auto_time,
            work_seconds = :work_sec,
            break_seconds = :break_sec,
            gross_seconds = :gross_sec,
            overtime_seconds = :overtime,
            auto_clocked_out = 1,
            notes = CONCAT(COALESCE(notes, ''), ' [Auto clocked out at {$autoTime}]'),
            updated_at = NOW()
        WHERE id = :id
    ");

    $up->execute([
        ':auto_time' => $autoTime,
        ':work_sec'  => $workSec,
        ':break_sec' => $breakSec,
        ':gross_sec' => $grossSec,
        ':overtime'  => $overtime,
        ':id'        => $rec['id'],
    ]);

    // Update heartbeat to off
    $hb = $db->prepare("UPDATE heartbeats SET active_status = 'off', updated_at = NOW() WHERE user_id = :uid");
    $hb->execute([':uid' => $rec['user_id']]);

    // Audit log
    AuditService::log(null, 'auto_clock_out', 'attendance', $rec['id'], $rec, [
        'current_status'   => 'clocked_out',
        'auto_clocked_out' => 1,
        'last_out'         => $autoTime,
    ]);

    $count++;
}

echo "[" . date('Y-m-d H:i:s') . "] Finished auto clock-out. Closed {$count} open sessions.\n";

