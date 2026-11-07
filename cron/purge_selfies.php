<?php
// cron/purge_selfies.php
// Runs daily via Hostinger hPanel cron to enforce India DPDP Act compliance

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Config\Database;
use App\Models\Setting;
use App\Services\AuditService;

echo "[" . date('Y-m-d H:i:s') . "] Starting selfie purge cron...\n";

$db = Database::getConnection();
$days = (int)Setting::get('delete_selfies_after_days', 90);
$cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));

$stmt = $db->prepare("
    SELECT id, user_id, selfie_path
    FROM punch_logs
    WHERE punched_at <= :cutoff AND selfie_path IS NOT NULL
");
$stmt->execute([':cutoff' => $cutoff]);
$logs = $stmt->fetchAll();

$deletedCount = 0;
$baseDir = dirname(__DIR__);

foreach ($logs as $log) {
    $filePath = $baseDir . '/' . ltrim($log['selfie_path'], '/\\');
    if (file_exists($filePath)) {
        @unlink($filePath);
    }

    $up = $db->prepare("UPDATE punch_logs SET selfie_path = NULL WHERE id = :id");
    $up->execute([':id' => $log['id']]);
    $deletedCount++;
}

if ($deletedCount > 0) {
    AuditService::log(null, 'selfie_purge', 'privacy', null, null, [
        'retention_days' => $days,
        'purged_count'   => $deletedCount,
        'cutoff_date'    => $cutoff,
    ]);
}

echo "[" . date('Y-m-d H:i:s') . "] Finished selfie purge. Deleted {$deletedCount} selfies older than {$days} days.\n";

