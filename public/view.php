<?php
// public/view.php - View Employee Profile / Team Details Dedicated Page

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Models\User;
use App\Models\Team;
use App\Models\Leave;
use App\Models\Attendance;
use App\Config\Database;

$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$scriptDir = rtrim(dirname($scriptName), '/');
if ($scriptDir === '.' || $scriptDir === '/') {
    $scriptDir = '';
}
$basePath = '';
if ($scriptDir !== '' && str_starts_with($requestPath, $scriptDir)) {
    $basePath = $scriptDir;
} else {
    $cleanDir = preg_replace('#/public$#', '', $scriptDir);
    if ($cleanDir !== '' && str_starts_with($requestPath, $cleanDir)) {
        $basePath = $cleanDir;
    }
}
$basePath = rtrim($basePath, '/');
if ($basePath === '') {
    $assetBase = '/assets';
} elseif (str_ends_with($basePath, '/public')) {
    $assetBase = $basePath . '/assets';
} else {
    $assetBase = $basePath . '/public/assets';
}

$currentUser = AuthMiddleware::user();
if (!$currentUser) {
    header('Location: ' . $basePath . '/index.php');
    exit;
}

$csrfToken = getCsrfToken();
$type = isset($_GET['type']) && $_GET['type'] === 'team' ? 'team' : 'employee';
$id = (int)($_GET['id'] ?? 0);
$created = !empty($_GET['created']);
$updated = !empty($_GET['updated']);
$tempPassword = $_GET['temp_password'] ?? '';

// Handle Offboard POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'offboard') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf) && $currentUser['role'] === 'admin') {
        if ($id !== (int)$currentUser['id']) {
            User::offboard($id, (int)$currentUser['id']);
            header('Location: ' . $basePath . '/view.php?id=' . $id . '&updated=1');
            exit;
        }
    }
}

// Handle Update Punch Requirements POST request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_punch_requirements') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf) && $currentUser['role'] === 'admin') {
        User::update($id, [
            'require_camera'   => isset($_POST['require_camera']) ? 1 : 0,
            'require_location' => isset($_POST['require_location']) ? 1 : 0,
        ], (int)$currentUser['id']);
        header('Location: ' . $basePath . '/view.php?id=' . $id . '&updated=1');
        exit;
    }
}

// Load data based on type
$entity = null;
$members = [];
$leaveBalances = null;
$recentLogs = [];
$activeSessions = [];

if ($type === 'team') {
    $entity = Team::findById($id);
    if ($entity) {
        $db = Database::getConnection();
        $stmt = $db->prepare("
            SELECT u.id, u.name, u.email, u.job_title, u.role, u.avatar_initials, u.avatar_color, u.status, m.name as manager_name
            FROM users u
            LEFT JOIN users m ON u.manager_id = m.id
            WHERE u.team_id = :id
            ORDER BY u.name ASC
        ");
        $stmt->execute([':id' => $id]);
        $members = $stmt->fetchAll(\PDO::FETCH_ASSOC);

        // Fetch team manager name
        if (!empty($entity['manager_id'])) {
            $mRow = User::findById((int)$entity['manager_id']);
            $entity['manager_name'] = $mRow ? $mRow['name'] : 'Unassigned';
        } else {
            $entity['manager_name'] = 'Unassigned';
        }
    }
} else {
    $entity = User::findById($id);
    if ($entity) {
        // Permission check
        if ($currentUser['role'] !== 'admin' && (int)$currentUser['id'] !== $id && (int)$entity['manager_id'] !== (int)$currentUser['id']) {
            http_response_code(403);
            echo '<!doctype html><html lang="en"><head><title>Access Denied</title><link rel="stylesheet" href="' . htmlspecialchars($assetBase) . '/css/style.css"></head><body style="padding:40px;text-align:center;"><h2>Access Denied</h2><p>You do not have permission to view this employee profile.</p><a href="' . htmlspecialchars($basePath) . '/index.php" class="btn primary">Return to Dashboard</a></body></html>';
            exit;
        }

        $leaveBalances = Leave::getBalances($id);
        $recentLogs = Attendance::getRecentLogs($id, 5);
        $activeSessions = User::getActiveSessions($id);
    }
}

$logoBase64 = file_exists(__DIR__ . '/assets/logo_base64.txt') ? trim(file_get_contents(__DIR__ . '/assets/logo_base64.txt')) : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title><?php echo $entity ? htmlspecialchars($entity['name']) : 'Details'; ?> | ThatRank HR</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase); ?>/css/style.css">
  <style>
    .page-wrap { max-width: 1040px; margin: 0 auto; padding: 24px 20px 60px; }
    .top-nav { display: flex; align-items: center; justify-content: space-between; padding: 14px 24px; border-bottom: 1px solid var(--line); background: var(--surface); }
    .top-nav .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: var(--fg); font-weight: 700; }
    .top-nav .brand img { height: 32px; width: auto; }
    .top-nav .u-pill { display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 600; }
    .crumb-bar { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--muted); margin-bottom: 20px; }
    .crumb-bar a { color: var(--accent); text-decoration: none; font-weight: 600; }

    .hero-card {
      background: var(--surface); border: 1px solid var(--line); border-radius: var(--r); padding: 28px;
      display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 20px;
      box-shadow: var(--shadow-sm); margin-bottom: 24px;
    }
    .hero-profile { display: flex; align-items: center; gap: 20px; }
    .hero-avatar { width: 72px; height: 72px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 26px; font-weight: 800; color: #fff; flex-shrink: 0; }
    .hero-name { font-size: 24px; font-weight: 800; margin: 0 0 4px; color: var(--fg); }
    .hero-meta { display: flex; align-items: center; gap: 10px; font-size: 13.5px; color: var(--muted); flex-wrap: wrap; }
    .hero-actions { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; }

    .alert-banner-ok {
      background: rgba(16, 185, 129, 0.12); border: 1px solid var(--ok); color: var(--ok);
      padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 14px;
      display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 10px;
    }
    .alert-banner-pw {
      background: var(--surface-2); border: 1px dashed var(--ok); padding: 6px 12px; border-radius: 6px; font-family: monospace; font-size: 15px; font-weight: 700; color: var(--fg); letter-spacing: 0.05em;
    }

    .main-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    @media (max-width: 840px) { .main-grid { grid-template-columns: 1fr; } }

    .detail-card {
      background: var(--surface); border: 1px solid var(--line); border-radius: var(--r); padding: 24px; box-shadow: var(--shadow-sm);
    }
    .detail-card .card-hd {
      font-size: 15px; font-weight: 800; color: var(--fg); margin: 0 0 16px 0; padding-bottom: 12px; border-bottom: 1px solid var(--line);
      display: flex; align-items: center; justify-content: space-between;
    }
    .info-list { display: flex; flex-direction: column; gap: 12px; font-size: 13.5px; }
    .info-row { display: flex; justify-content: space-between; align-items: baseline; gap: 16px; padding-bottom: 8px; border-bottom: 1px dashed var(--line); }
    .info-row:last-child { border-bottom: none; padding-bottom: 0; }
    .info-label { color: var(--muted); font-weight: 600; flex-shrink: 0; }
    .info-val { font-weight: 700; color: var(--fg); text-align: right; word-break: break-all; }
    .info-val a { color: var(--accent); text-decoration: none; }

    .bal-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(130px, 1fr)); gap: 12px; margin-top: 8px; }
    .bal-item { background: var(--surface-2); border: 1px solid var(--line); border-radius: 8px; padding: 14px; text-align: center; }
    .bal-item .num { font-size: 22px; font-weight: 800; color: var(--fg); margin-top: 2px; }
    .bal-item .lbl { font-size: 12px; font-weight: 700; color: var(--muted); text-transform: uppercase; letter-spacing: 0.04em; }
    .bal-item .sub { font-size: 11px; color: var(--muted); margin-top: 4px; }
  </style>
</head>
<body>

  <!-- Top Navigation Bar -->
  <nav class="top-nav">
    <a href="<?php echo htmlspecialchars($basePath); ?>/index.php" class="brand">
      <?php if (!empty($logoBase64)): ?>
        <img src="<?php echo $logoBase64; ?>" alt="ThatRank Logo">
      <?php else: ?>
        <span style="font-weight:800;font-size:18px;color:var(--accent)">ThatRank HR</span>
      <?php endif; ?>
    </a>
    <div style="display:flex;align-items:center;gap:16px">
      <button class="btn xs quiet" id="themeBtn" title="Toggle Theme">Toggle Theme</button>
      <div class="u-pill">
        <span class="avatar sm" style="background:<?php echo htmlspecialchars($currentUser['avatar_color'] ?? 'var(--ink)'); ?>"><?php echo htmlspecialchars($currentUser['avatar_initials'] ?? 'TR'); ?></span>
        <span><?php echo htmlspecialchars($currentUser['name']); ?></span>
      </div>
    </div>
  </nav>

  <div class="page-wrap">
    <!-- Breadcrumb -->
    <div class="crumb-bar">
      <a href="<?php echo htmlspecialchars($basePath); ?>/index.php">Dashboard</a>
      <span>/</span>
      <?php if ($type === 'team'): ?>
        <a href="<?php echo htmlspecialchars($basePath); ?>/index.php#teams">Teams</a>
        <span>/</span>
        <span><?php echo $entity ? htmlspecialchars($entity['name']) : 'Team Details'; ?></span>
      <?php else: ?>
        <a href="<?php echo htmlspecialchars($basePath); ?>/index.php#people">Employees</a>
        <span>/</span>
        <span><?php echo $entity ? htmlspecialchars($entity['name']) : 'Employee Profile'; ?></span>
      <?php endif; ?>
    </div>

    <?php if (!$entity): ?>
      <div class="detail-card" style="text-align:center;padding:48px 24px;">
        <h2 style="font-size:20px;font-weight:800;margin-bottom:8px">Record Not Found</h2>
        <p style="color:var(--muted);margin-bottom:20px">The requested <?php echo $type === 'team' ? 'team' : 'employee'; ?> could not be found or has been deleted.</p>
        <a href="<?php echo htmlspecialchars($basePath); ?>/index.php<?php echo $type === 'team' ? '#teams' : '#people'; ?>" class="btn primary">Return to <?php echo $type === 'team' ? 'Teams' : 'Employees'; ?></a>
      </div>
    <?php else: ?>

      <?php if ($created && !empty($tempPassword)): ?>
        <div class="alert-banner-ok">
          <div>
            <b>Employee added successfully!</b>
            <div style="font-size:12.5px;color:var(--fg);margin-top:2px">Please share this temporary password with the employee:</div>
          </div>
          <div style="display:flex;align-items:center;gap:8px">
            <span class="alert-banner-pw" id="copyPwTarget"><?php echo htmlspecialchars($tempPassword); ?></span>
            <button class="btn sm" id="btnCopyPw">Copy Password</button>
          </div>
        </div>
      <?php elseif ($created): ?>
        <div class="alert-banner-ok">
          <div><b><?php echo $type === 'team' ? 'Team created successfully.' : 'Employee added successfully.'; ?></b></div>
        </div>
      <?php elseif ($updated): ?>
        <div class="alert-banner-ok">
          <div><b><?php echo $type === 'team' ? 'Team details updated successfully.' : 'Employee profile updated successfully.'; ?></b></div>
        </div>
      <?php endif; ?>

      <!-- Hero Header Card -->
      <div class="hero-card">
        <div class="hero-profile">
          <?php if ($type === 'team'): ?>
            <div class="hero-avatar" style="background:var(--accent)">
              <svg class="i" style="width:36px;height:36px;stroke:#fff"><use href="#i-people"/></svg>
            </div>
            <div>
              <h1 class="hero-name"><?php echo htmlspecialchars($entity['name']); ?></h1>
              <div class="hero-meta">
                <span>Manager: <b><?php echo htmlspecialchars($entity['manager_name']); ?></b></span>
                <span>•</span>
                <span><b><?php echo count($members); ?></b> assigned members</span>
              </div>
            </div>
          <?php else: ?>
            <div class="hero-avatar" style="background:<?php echo htmlspecialchars($entity['avatar_color'] ?? 'var(--ink)'); ?>">
              <?php echo htmlspecialchars($entity['avatar_initials'] ?? 'TR'); ?>
            </div>
            <div>
              <h1 class="hero-name"><?php echo htmlspecialchars($entity['name']); ?></h1>
              <div class="hero-meta">
                <span><?php echo htmlspecialchars($entity['job_title'] ?: ucfirst($entity['role'])); ?></span>
                <span>•</span>
                <span><?php echo htmlspecialchars($entity['team_name'] ?: ($entity['department'] ?: 'No Team')); ?></span>
                <span>•</span>
                <span class="pill <?php echo $entity['status'] === 'active' ? 'ok' : 'crit'; ?>" style="font-size:11px">
                  <?php echo ucfirst($entity['status']); ?>
                </span>
                <span class="pill plain" style="font-size:11px">
                  <?php echo ucfirst($entity['role']); ?>
                </span>
              </div>
            </div>
          <?php endif; ?>
        </div>

        <div class="hero-actions">
          <?php if ($type === 'team'): ?>
            <a href="<?php echo htmlspecialchars($basePath); ?>/edit.php?type=team&id=<?php echo (int)$entity['id']; ?>" class="btn primary sm">Edit Team</a>
            <a href="<?php echo htmlspecialchars($basePath); ?>/index.php#teams" class="btn quiet sm">Back to Teams</a>
          <?php else: ?>
            <?php if ($currentUser['role'] === 'admin'): ?>
              <a href="<?php echo htmlspecialchars($basePath); ?>/edit.php?id=<?php echo (int)$entity['id']; ?>" class="btn primary sm">Edit Employee</a>
              <?php if ((int)$entity['id'] !== (int)$currentUser['id'] && $entity['status'] === 'active'): ?>
                <form method="POST" action="<?php echo htmlspecialchars($basePath); ?>/view.php?id=<?php echo (int)$entity['id']; ?>" style="display:inline" onsubmit="return confirm('Offboard this employee? They will no longer be able to sign in.')">
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                  <input type="hidden" name="action" value="offboard">
                  <button type="submit" class="btn danger sm">Offboard</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
            <a href="<?php echo htmlspecialchars($basePath); ?>/index.php#people" class="btn quiet sm">Back to Employees</a>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($type === 'team'): ?>
        <!-- Team Members Table -->
        <div class="detail-card">
          <div class="card-hd">
            <span>Team Members (<?php echo count($members); ?>)</span>
            <a href="<?php echo htmlspecialchars($basePath); ?>/add.php?type=employee" class="btn xs primary">+ Add Teammate</a>
          </div>
          <div class="tblwrap">
            <table class="tbl">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th>Job Title</th>
                  <th>Role</th>
                  <th>Manager</th>
                  <th>Status</th>
                  <th class="r">Action</th>
                </tr>
              </thead>
              <tbody>
                <?php if (empty($members)): ?>
                  <tr><td colspan="6" style="text-align:center;color:var(--muted);padding:24px;">No employees currently assigned to this team.</td></tr>
                <?php else: ?>
                  <?php foreach ($members as $m): ?>
                    <tr>
                      <td>
                        <div class="person">
                          <span class="avatar sm" style="background:<?php echo htmlspecialchars($m['avatar_color'] ?? 'var(--ink)'); ?>"><?php echo htmlspecialchars($m['avatar_initials'] ?? 'TR'); ?></span>
                          <div>
                            <div class="n"><?php echo htmlspecialchars($m['name']); ?></div>
                            <div class="e"><?php echo htmlspecialchars($m['email']); ?></div>
                          </div>
                        </div>
                      </td>
                      <td><?php echo htmlspecialchars($m['job_title'] ?: '-'); ?></td>
                      <td><span class="pill plain sm"><?php echo ucfirst($m['role']); ?></span></td>
                      <td><?php echo htmlspecialchars($m['manager_name'] ?: '-'); ?></td>
                      <td>
                        <span class="pill <?php echo $m['status'] === 'active' ? 'ok' : 'crit'; ?> sm"><?php echo ucfirst($m['status']); ?></span>
                      </td>
                      <td class="r">
                        <a href="<?php echo htmlspecialchars($basePath); ?>/view.php?id=<?php echo (int)$m['id']; ?>" class="btn xs quiet">View Profile</a>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      <?php else: ?>
        <!-- Employee Details Grid -->
        <div class="main-grid">
          <!-- Left Column -->
          <div style="display:flex;flex-direction:column;gap:20px">
            <!-- Contact & Work Information -->
            <div class="detail-card">
              <div class="card-hd">Contact & Work Information</div>
              <div class="info-list">
                <div class="info-row">
                  <span class="info-label">Work Email</span>
                  <span class="info-val"><a href="mailto:<?php echo htmlspecialchars($entity['email']); ?>"><?php echo htmlspecialchars($entity['email']); ?></a></span>
                </div>
                <div class="info-row">
                  <span class="info-label">Phone Number</span>
                  <span class="info-val"><?php echo htmlspecialchars($entity['phone'] ?: 'Not provided'); ?></span>
                </div>
                <div class="info-row">
                  <span class="info-label">Job Title</span>
                  <span class="info-val"><?php echo htmlspecialchars($entity['job_title'] ?: '-'); ?></span>
                </div>
                <div class="info-row">
                  <span class="info-label">Assigned Team</span>
                  <span class="info-val"><?php echo htmlspecialchars($entity['team_name'] ?: 'None'); ?></span>
                </div>
                <div class="info-row">
                  <span class="info-label">Reporting Manager</span>
                  <span class="info-val">
                    <?php if (!empty($entity['manager_name'])): ?>
                      <a href="<?php echo htmlspecialchars($basePath); ?>/view.php?id=<?php echo (int)$entity['manager_id']; ?>"><?php echo htmlspecialchars($entity['manager_name']); ?></a>
                    <?php else: ?>
                      No manager assigned
                    <?php endif; ?>
                  </span>
                </div>
              </div>
            </div>

            <!-- Dates & Onboarding -->
            <div class="detail-card">
              <div class="card-hd">Schedule & Milestones</div>
              <div class="info-list">
                <div class="info-row">
                  <span class="info-label">Joining Date</span>
                  <span class="info-val"><?php echo htmlspecialchars($entity['joined_date'] ? date('j M Y', strtotime($entity['joined_date'])) : '-'); ?></span>
                </div>
                <div class="info-row">
                  <span class="info-label">Date of Birth</span>
                  <span class="info-val"><?php echo htmlspecialchars($entity['date_of_birth'] ? date('j M Y', strtotime($entity['date_of_birth'])) : 'Not set'); ?></span>
                </div>
                <div class="info-row">
                  <span class="info-label">Work Anniversary</span>
                  <span class="info-val"><?php echo htmlspecialchars($entity['work_anniversary'] ? date('j M Y', strtotime($entity['work_anniversary'])) : '-'); ?></span>
                </div>
                <div class="info-row">
                  <span class="info-label">Consent Accepted</span>
                  <span class="info-val"><?php echo htmlspecialchars($entity['consent_accepted_at'] ? date('j M Y, g:i A', strtotime($entity['consent_accepted_at'])) : 'Pending verification'); ?></span>
                </div>
              </div>
            </div>

            <!-- Punch Requirements -->
            <div class="detail-card">
              <div class="card-hd">Punch Requirements</div>
              <form method="POST" action="<?php echo htmlspecialchars($basePath); ?>/view.php?id=<?php echo (int)$entity['id']; ?>">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                <input type="hidden" name="action" value="update_punch_requirements">
                <div class="info-list" style="margin-bottom:12px">
                  <div class="info-row" style="align-items:center">
                    <span class="info-label">Camera Verification</span>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin:0">
                      <input type="checkbox" name="require_camera" value="1" <?php echo !isset($entity['require_camera']) || (int)$entity['require_camera'] === 1 ? 'checked' : ''; ?> <?php echo $currentUser['role'] !== 'admin' ? 'disabled' : ''; ?> style="width:auto">
                      <span style="font-size:13px">Camera required for clock in</span>
                    </label>
                  </div>
                  <div class="info-row" style="align-items:center">
                    <span class="info-label">Location Verification</span>
                    <label style="display:flex;align-items:center;gap:6px;cursor:pointer;margin:0">
                      <input type="checkbox" name="require_location" value="1" <?php echo !isset($entity['require_location']) || (int)$entity['require_location'] === 1 ? 'checked' : ''; ?> <?php echo $currentUser['role'] !== 'admin' ? 'disabled' : ''; ?> style="width:auto">
                      <span style="font-size:13px">Location required for clock in</span>
                    </label>
                  </div>
                </div>
                <?php if ($currentUser['role'] === 'admin'): ?>
                  <button type="submit" class="btn sm primary">Save Requirements</button>
                <?php endif; ?>
              </form>
            </div>
          </div>

          <!-- Right Column -->
          <div style="display:flex;flex-direction:column;gap:20px">
            <!-- Leave Balances -->
            <div class="detail-card">
              <div class="card-hd">Leave Balances (<?php echo date('Y'); ?>)</div>
              <?php if ($leaveBalances): ?>
                <div class="bal-grid">
                  <div class="bal-item">
                    <div class="lbl">Casual Leave</div>
                    <div class="num"><?php echo htmlspecialchars($leaveBalances['casual_available'] ?? '0'); ?></div>
                    <div class="sub">Used: <?php echo htmlspecialchars($leaveBalances['casual_used'] ?? '0'); ?> d</div>
                  </div>
                  <div class="bal-item">
                    <div class="lbl">Sick Leave</div>
                    <div class="num"><?php echo htmlspecialchars($leaveBalances['sick_available'] ?? '0'); ?></div>
                    <div class="sub">Used: <?php echo htmlspecialchars($leaveBalances['sick_used'] ?? '0'); ?> d</div>
                  </div>
                  <div class="bal-item">
                    <div class="lbl">Earned Leave</div>
                    <div class="num"><?php echo htmlspecialchars($leaveBalances['earned_available'] ?? '0'); ?></div>
                    <div class="sub">Used: <?php echo htmlspecialchars($leaveBalances['earned_used'] ?? '0'); ?> d</div>
                  </div>
                </div>
              <?php else: ?>
                <div style="color:var(--muted);font-size:13px;padding:8px 0">No leave balance data initialized.</div>
              <?php endif; ?>
            </div>

            <!-- Recent Attendance Shifts -->
            <div class="detail-card">
              <div class="card-hd">Recent Attendance Shifts</div>
              <?php if (empty($recentLogs)): ?>
                <div style="color:var(--muted);font-size:13px;padding:8px 0">No recent shifts recorded.</div>
              <?php else: ?>
                <div class="info-list">
                  <?php foreach ($recentLogs as $log): ?>
                    <div class="info-row">
                      <div>
                        <b><?php echo date('D, j M', strtotime($log['date'])); ?></b>
                        <div style="font-size:11.5px;color:var(--muted)">
                          <?php echo !empty($log['first_in']) ? date('g:i A', strtotime($log['first_in'])) : 'No in-punch'; ?> to <?php echo !empty($log['last_out']) ? date('g:i A', strtotime($log['last_out'])) : ($log['date'] === date('Y-m-d') ? 'Active' : 'Missing clock-out'); ?>
                        </div>
                      </div>
                      <div style="text-align:right">
                        <span class="pill ok plain sm"><?php echo htmlspecialchars(floor(($log['effective_work_seconds'] ?? 0) / 3600) . 'h ' . floor((($log['effective_work_seconds'] ?? 0) % 3600) / 60) . 'm'); ?></span>
                        <div style="font-size:11.5px;color:var(--muted);margin-top:2px"><?php echo !empty($log['is_late']) ? 'Late mark' : (isset($log['arrival_text']) ? htmlspecialchars($log['arrival_text']) : 'On time'); ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <!-- Active Sessions / Devices -->
            <div class="detail-card">
              <div class="card-hd">Active Sessions & Devices</div>
              <?php if (empty($activeSessions)): ?>
                <div style="color:var(--muted);font-size:13px;padding:8px 0">No active login sessions recorded.</div>
              <?php else: ?>
                <div class="info-list">
                  <?php foreach ($activeSessions as $sess): ?>
                    <div class="info-row">
                      <div>
                        <b><?php echo htmlspecialchars(($sess['browser'] ?: 'Browser') . ' on ' . ($sess['os'] ?: 'Device')); ?></b>
                        <div style="font-size:11.5px;color:var(--muted)">IP: <?php echo htmlspecialchars($sess['ip'] ?: 'Unknown'); ?></div>
                      </div>
                      <div style="font-size:11.5px;color:var(--muted);text-align:right">
                        <?php echo htmlspecialchars(date('j M, g:i A', strtotime($sess['last_seen_at'] ?? $sess['created_at']))); ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <script>
    // Theme toggle
    var savedTheme = localStorage.getItem('thatrank_theme') || 'light';
    document.documentElement.setAttribute('data-theme', savedTheme);
    var tBtn = document.getElementById('themeBtn');
    if (tBtn) {
      tBtn.addEventListener('click', function() {
        var cur = document.documentElement.getAttribute('data-theme');
        var next = cur === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('thatrank_theme', next);
      });
    }

    // Copy temporary password button
    var copyBtn = document.getElementById('btnCopyPw');
    if (copyBtn) {
      copyBtn.addEventListener('click', function() {
        var pwEl = document.getElementById('copyPwTarget');
        if (pwEl) {
          navigator.clipboard.writeText(pwEl.textContent).then(function() {
            copyBtn.textContent = 'Copied!';
            setTimeout(function() { copyBtn.textContent = 'Copy Password'; }, 2500);
          });
        }
      });
    }
  </script>
</body>
</html>

