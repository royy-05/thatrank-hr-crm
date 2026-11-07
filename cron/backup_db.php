<?php
// cron/backup_db.php
// Daily database backup script: dumps MySQL database to storage/backups, gzips, and retains for 14 days.

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Config\Database;

echo "[" . date('Y-m-d H:i:s') . "] Starting database backup cron...\n";

$backupDir = dirname(__DIR__) . '/storage/backups';
if (!is_dir($backupDir)) {
    mkdir($backupDir, 0755, true);
}

$dbHost = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbPort = $_ENV['DB_PORT'] ?? '3306';
$dbName = $_ENV['DB_NAME'] ?? 'thatrank_hr_software';
$dbUser = $_ENV['DB_USER'] ?? 'root';
$dbPass = $_ENV['DB_PASS'] ?? '';

$timestamp = date('Y-m-d_His');
$sqlFilename = "backup_{$dbName}_{$timestamp}.sql";
$sqlPath = $backupDir . '/' . $sqlFilename;
$gzPath = $sqlPath . '.gz';

$dumpSuccess = false;

// Attempt 1: mysqldump via exec/shell if available
$mysqldump = 'mysqldump';
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    // Check common XAMPP path if mysqldump not in global PATH
    if (file_exists('C:\\xampp\\mysql\\bin\\mysqldump.exe')) {
        $mysqldump = 'C:\\xampp\\mysql\\bin\\mysqldump.exe';
    }
}

$passArg = $dbPass !== '' ? ' -p' . escapeshellarg($dbPass) : '';
$cmd = escapeshellcmd($mysqldump) . " -h {$dbHost} -P {$dbPort} -u {$dbUser}{$passArg} {$dbName} > " . escapeshellarg($sqlPath);

if (function_exists('exec')) {
    $output = [];
    $returnVar = 1;
    @exec($cmd . ' 2>&1', $output, $returnVar);
    if ($returnVar === 0 && file_exists($sqlPath) && filesize($sqlPath) > 0) {
        $dumpSuccess = true;
        echo "[" . date('Y-m-d H:i:s') . "] mysqldump completed successfully.\n";
    }
}

// Attempt 2: PHP PDO dump fallback if mysqldump was not executable or failed
if (!$dumpSuccess) {
    echo "[" . date('Y-m-d H:i:s') . "] mysqldump unavailable or failed. Using PDO export fallback...\n";
    try {
        $pdo = Database::getConnection();
        $handle = fopen($sqlPath, 'w');
        if ($handle) {
            fwrite($handle, "-- ThatRank HR Software Database Dump (PDO fallback)\n");
            fwrite($handle, "-- Date: " . date('Y-m-d H:i:s') . "\n\n");
            fwrite($handle, "SET foreign_key_checks = 0;\n\n");

            $tablesStmt = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            $tables = $tablesStmt->fetchAll(\PDO::FETCH_NUM);

            foreach ($tables as $tableRow) {
                $table = $tableRow[0];
                $createStmt = $pdo->query("SHOW CREATE TABLE `{$table}`");
                $createRow = $createStmt->fetch(\PDO::FETCH_NUM);
                $createSql = $createRow[1] ?? '';

                fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;\n");
                fwrite($handle, $createSql . ";\n\n");

                $rowsStmt = $pdo->query("SELECT * FROM `{$table}`");
                $rows = $rowsStmt->fetchAll(\PDO::FETCH_ASSOC);
                if (!empty($rows)) {
                    foreach ($rows as $row) {
                        $cols = array_map(function ($c) {
                            return "`" . str_replace("`", "``", $c) . "`";
                        }, array_keys($row));

                        $vals = array_map(function ($val) use ($pdo) {
                            if ($val === null) {
                                return "NULL";
                            }
                            return $pdo->quote($val);
                        }, array_values($row));

                        fwrite($handle, "INSERT INTO `{$table}` (" . implode(", ", $cols) . ") VALUES (" . implode(", ", $vals) . ");\n");
                    }
                    fwrite($handle, "\n");
                }
            }

            fwrite($handle, "SET foreign_key_checks = 1;\n");
            fclose($handle);
            $dumpSuccess = true;
            echo "[" . date('Y-m-d H:i:s') . "] PDO dump fallback completed successfully.\n";
        }
    } catch (\Throwable $e) {
        echo "[" . date('Y-m-d H:i:s') . "] PDO dump error: " . $e->getMessage() . "\n";
    }
}

if ($dumpSuccess && file_exists($sqlPath)) {
    // Compress with gzip
    $gz = gzopen($gzPath, 'w9');
    $sqlHandle = fopen($sqlPath, 'r');
    if ($gz && $sqlHandle) {
        while (!feof($sqlHandle)) {
            gzwrite($gz, fread($sqlHandle, 65536));
        }
        fclose($sqlHandle);
        gzclose($gz);
        @unlink($sqlPath); // Remove uncompressed SQL file
        echo "[" . date('Y-m-d H:i:s') . "] Compressed backup created: {$gzPath} (" . filesize($gzPath) . " bytes)\n";
    }
} else {
    echo "[" . date('Y-m-d H:i:s') . "] Failed to create database backup.\n";
}

// Retention policy: Delete files older than 14 days
$retentionSeconds = 14 * 86400;
$now = time();
$deletedCount = 0;

$files = glob($backupDir . '/*');
if ($files) {
    foreach ($files as $file) {
        if (is_file($file)) {
            $age = $now - filemtime($file);
            if ($age > $retentionSeconds) {
                @unlink($file);
                $deletedCount++;
            }
        }
    }
}

echo "[" . date('Y-m-d H:i:s') . "] Retention cleanup complete. Deleted {$deletedCount} old backup files.\n";

