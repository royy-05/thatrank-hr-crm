<?php
$files = glob(__DIR__ . '/../app/**/*.php');
$foundIssues = false;

foreach ($files as $f) {
    $content = file_get_contents($f);
    // Find all prepare(...) calls
    if (preg_match_all('/(?:prepare)\s*\(\s*["\']([^"\']+)["\']/s', $content, $matches)) {
        foreach ($matches[1] as $query) {
            if (preg_match_all('/:([a-zA-Z0-9_]+)/', $query, $pMatches)) {
                $counts = array_count_values($pMatches[1]);
                foreach ($counts as $p => $count) {
                    if ($count > 1) {
                        echo "Found duplicate :$p in " . basename($f) . ":\n  " . trim(preg_replace('/\s+/', ' ', $query)) . "\n\n";
                        $foundIssues = true;
                    }
                }
            }
        }
    }
}

if (!$foundIssues) {
    echo "PASS: No duplicate named parameters found in any SQL query in app/\n";
}

