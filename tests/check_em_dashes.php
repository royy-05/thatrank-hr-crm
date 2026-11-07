<?php
// Scan source files for em-dashes and en-dashes

$dirs = [
    __DIR__ . '/../public',
    __DIR__ . '/../app',
    __DIR__ . '/../config',
    __DIR__ . '/../cron'
];

$violations = [];

function scanDirRecursive($dir, &$violations) {
    $items = scandir($dir);
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . '/' . $item;
        if (is_dir($path)) {
            scanDirRecursive($path, $violations);
        } elseif (preg_match('/\.(php|js|html)$/i', $item)) {
            $content = file_get_contents($path);
            if (preg_match('/[\x{2013}\x{2014}]/u', $content, $matches)) {
                $violations[] = $path;
            }
        }
    }
}

foreach ($dirs as $dir) {
    if (is_dir($dir)) {
        scanDirRecursive($dir, $violations);
    }
}

if (!empty($violations)) {
    echo "FAIL: Em-dashes/en-dashes found in:\n";
    foreach ($violations as $v) {
        echo " - " . realpath($v) . "\n";
    }
    exit(1);
} else {
    echo "PASS: Zero em-dashes/en-dashes found across all UI and backend code.\n";
    exit(0);
}

