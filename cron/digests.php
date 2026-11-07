<?php
// cron/digests.php
// Runs daily evening via Hostinger hPanel cron to send attendance digest to n8n / managers

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Config\Database;
use App\Services\AlertService;

echo "[" . date('Y-m-d H:i:s') . "] Starting daily digest cron...\n";

$db = Database::getConnection();
$today = date('Y-m-d');

$stmt = $db->prepare("
    SELECT u.name, u.email, u.department, ar.status, ar.first_in, ar.last_out, ar.work_seconds, ar.is_late
    FROM users u
    LEFT JOIN attendance_records ar ON u.id = ar.user_id AND ar.date = :today
    WHERE u.status = 'active'
    ORDER BY u.name ASC
");
$stmt->execute([':today' => $today]);
$team = $stmt->fetchAll();

$present = 0;
$leave = 0;
$late = 0;
$absent = 0;

foreach ($team as $member) {
    if ($member['status'] === 'present') {
        $present++;
        if (!empty($member['is_late'])) $late++;
    } elseif ($member['status'] === 'on_leave') {
        $leave++;
    } else {
        $absent++;
    }
}

$payload = [
    'date'      => $today,
    'total'     => count($team),
    'present'   => $present,
    'on_leave'  => $leave,
    'late'      => $late,
    'absent'    => $absent,
    'team_data' => $team,
];

AlertService::triggerWebhook('daily_attendance_digest', $payload);

echo "[" . date('Y-m-d H:i:s') . "] Daily digest generated: {$present} present, {$leave} on leave, {$late} late.\n";

