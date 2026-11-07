<?php
// cron/celebrations.php
// Daily celebrations cron: automatically generates birthday and work anniversary community posts for today.

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Config\Database;

echo "[" . date('Y-m-d H:i:s') . "] Starting celebrations cron...\n";

$db = Database::getConnection();
$today = date('Y-m-d');
$todayMd = date('m-d');
$currentYear = (int)date('Y');

// Find a system author (admin user)
$adminStmt = $db->query("SELECT id FROM users WHERE role = 'admin' AND status = 'active' ORDER BY id ASC LIMIT 1");
$adminId = $adminStmt->fetchColumn();
if (!$adminId) {
    $firstUserStmt = $db->query("SELECT id FROM users WHERE status = 'active' ORDER BY id ASC LIMIT 1");
    $adminId = $firstUserStmt->fetchColumn() ?: 1;
}

// 1. Fetch active users with birthday today
$bdayStmt = $db->prepare("
    SELECT id, name, email, date_of_birth
    FROM users
    WHERE status = 'active'
      AND date_of_birth IS NOT NULL
      AND DATE_FORMAT(date_of_birth, '%m-%d') = :today_md
");
$bdayStmt->execute([':today_md' => $todayMd]);
$birthdayUsers = $bdayStmt->fetchAll();

$bdayCount = 0;
foreach ($birthdayUsers as $u) {
    // Avoid duplicate post today
    $checkStmt = $db->prepare("
        SELECT id FROM community_posts
        WHERE type = 'celebration'
          AND recipient_id = :recipient_id
          AND title LIKE 'Happy Birthday%'
          AND DATE(created_at) = :today
        LIMIT 1
    ");
    $checkStmt->execute([
        ':recipient_id' => $u['id'],
        ':today' => $today,
    ]);

    if ($checkStmt->fetch()) {
        continue;
    }

    $title = "Happy Birthday, {$u['name']}!";
    $content = "Wishing {$u['name']} a wonderful birthday filled with joy and success! Please join us in celebrating today.";

    $insertStmt = $db->prepare("
        INSERT INTO community_posts (user_id, recipient_id, type, title, content, is_pinned, created_at)
        VALUES (:user_id, :recipient_id, 'celebration', :title, :content, 0, NOW())
    ");
    $insertStmt->execute([
        ':user_id' => $adminId,
        ':recipient_id' => $u['id'],
        ':title' => $title,
        ':content' => $content,
    ]);
    $bdayCount++;
}

// 2. Fetch active users with work anniversary today
$annivStmt = $db->prepare("
    SELECT id, name, email,
           COALESCE(work_anniversary, joined_date) AS anniv_date
    FROM users
    WHERE status = 'active'
      AND (
        (work_anniversary IS NOT NULL AND DATE_FORMAT(work_anniversary, '%m-%d') = :today_md1)
        OR (work_anniversary IS NULL AND joined_date IS NOT NULL AND DATE_FORMAT(joined_date, '%m-%d') = :today_md2)
      )
");
$annivStmt->execute([
    ':today_md1' => $todayMd,
    ':today_md2' => $todayMd,
]);
$annivUsers = $annivStmt->fetchAll();

$annivCount = 0;
foreach ($annivUsers as $u) {
    $annivYear = (int)date('Y', strtotime($u['anniv_date']));
    $years = $currentYear - $annivYear;

    // Only celebrate milestones >= 1 year
    if ($years < 1) {
        continue;
    }

    // Avoid duplicate post today
    $checkStmt = $db->prepare("
        SELECT id FROM community_posts
        WHERE type = 'celebration'
          AND recipient_id = :recipient_id
          AND title LIKE '%Work Anniversary%'
          AND DATE(created_at) = :today
        LIMIT 1
    ");
    $checkStmt->execute([
        ':recipient_id' => $u['id'],
        ':today' => $today,
    ]);

    if ($checkStmt->fetch()) {
        continue;
    }

    $yearSuffix = ($years === 1) ? '1 year' : "{$years} years";
    $title = "Happy Work Anniversary, {$u['name']}! ({$yearSuffix})";
    $content = "Congratulations to {$u['name']} on reaching {$yearSuffix} with ThatRank! Thank you for all your hard work, dedication, and positive energy.";

    $insertStmt = $db->prepare("
        INSERT INTO community_posts (user_id, recipient_id, type, title, content, is_pinned, created_at)
        VALUES (:user_id, :recipient_id, 'celebration', :title, :content, 0, NOW())
    ");
    $insertStmt->execute([
        ':user_id' => $adminId,
        ':recipient_id' => $u['id'],
        ':title' => $title,
        ':content' => $content,
    ]);
    $annivCount++;
}

echo "[" . date('Y-m-d H:i:s') . "] Celebrations cron complete. Created {$bdayCount} birthday posts and {$annivCount} work anniversary posts.\n";
