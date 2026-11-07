<?php
// public/add.php - Add Employee / Team Dedicated Page

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Middleware\AuthMiddleware;
use App\Models\User;
use App\Models\Team;
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

if ($currentUser['role'] !== 'admin') {
    http_response_code(403);
    echo '<!doctype html><html lang="en"><head><title>Access Denied</title><link rel="stylesheet" href="' . htmlspecialchars($assetBase) . '/css/style.css"></head><body style="padding:40px;font-family:sans-serif;text-align:center;"><h2>Access Denied</h2><p>Administrator privileges are required to view this page.</p><a href="' . htmlspecialchars($basePath) . '/index.php" class="btn primary">Return to Dashboard</a></body></html>';
    exit;
}

$csrfToken = getCsrfToken();
$type = isset($_GET['type']) && $_GET['type'] === 'team' ? 'team' : 'employee';
$error = '';
$success = '';

// Handle POST Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submittedCsrf = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $submittedCsrf)) {
        $error = 'Invalid security token. Please refresh and try again.';
    } else {
        if ($type === 'team') {
            $teamName = trim($_POST['name'] ?? '');
            $managerId = !empty($_POST['manager_id']) ? (int)$_POST['manager_id'] : null;

            if ($teamName === '') {
                $error = 'Team name is required.';
            } else {
                $newTeamId = Team::create($teamName, $managerId, (int)$currentUser['id']);
                header('Location: ' . $basePath . '/view.php?type=team&id=' . $newTeamId . '&created=1');
                exit;
            }
        } else {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');

            if ($name === '' || $email === '') {
                $error = 'Full name and work email are required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $error = 'Please enter a valid work email address.';
            } elseif (User::findByEmail($email)) {
                $error = 'An employee with this email already exists in the system.';
            } else {
                $customPassword = trim($_POST['password'] ?? '');
                $tempPassword = $customPassword !== '' ? $customPassword : ('tr_' . substr(bin2hex(random_bytes(4)), 0, 8));
                $_POST['password'] = $tempPassword;
                $_POST['must_change_password'] = 0;

                $newEmpId = User::create($_POST, (int)$currentUser['id']);
                header('Location: ' . $basePath . '/view.php?id=' . $newEmpId . '&created=1&temp_password=' . urlencode($tempPassword));
                exit;
            }
        }
    }
}

// Fetch helper data
$teams = Team::all();
$db = Database::getConnection();
$managersStmt = $db->query("SELECT id, name, job_title, role FROM users WHERE role IN ('manager', 'admin') AND status = 'active' ORDER BY name ASC");
$managers = $managersStmt->fetchAll(\PDO::FETCH_ASSOC);

$rolesStmt = $db->query("SELECT slug, name FROM roles ORDER BY is_system DESC, name ASC");
$availableRoles = $rolesStmt ? $rolesStmt->fetchAll(\PDO::FETCH_ASSOC) : [];
if (empty($availableRoles)) {
    $availableRoles = [
        ['slug' => 'employee', 'name' => 'Employee'],
        ['slug' => 'manager', 'name' => 'Manager'],
        ['slug' => 'admin', 'name' => 'Administrator'],
    ];
}

$deptStmt = $db->query("SELECT DISTINCT department FROM users WHERE department IS NOT NULL AND department != '' UNION SELECT name FROM teams ORDER BY department ASC");
$depts = $deptStmt->fetchAll(\PDO::FETCH_COLUMN);
if (empty($depts)) {
    $depts = ['Engineering', 'Design', 'Marketing', 'Product', 'Human Resources', 'Sales'];
}

$logoBase64 = file_exists(__DIR__ . '/assets/logo_base64.txt') ? trim(file_get_contents(__DIR__ . '/assets/logo_base64.txt')) : '';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <title><?php echo $type === 'team' ? 'Add New Team' : 'Add New Employee'; ?> | ThatRank HR</title>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase); ?>/css/style.css">
  <style>
    .page-wrap { max-width: 960px; margin: 0 auto; padding: 24px 20px 60px; }
    .top-nav { display: flex; align-items: center; justify-content: space-between; padding: 14px 24px; border-bottom: 1px solid var(--line); background: var(--surface); }
    .top-nav .brand { display: flex; align-items: center; gap: 12px; text-decoration: none; color: var(--fg); font-weight: 700; }
    .top-nav .brand img { height: 32px; width: auto; }
    .top-nav .u-pill { display: flex; align-items: center; gap: 10px; font-size: 13px; font-weight: 600; }
    .crumb-bar { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--muted); margin-bottom: 20px; }
    .crumb-bar a { color: var(--accent); text-decoration: none; font-weight: 600; }
    .form-card { background: var(--surface); border: 1px solid var(--line); border-radius: var(--r); padding: 32px; box-shadow: var(--shadow-sm); }
    .form-card .hd { margin-bottom: 24px; padding-bottom: 16px; border-bottom: 1px solid var(--line); display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px; }
    .form-card .hd h1 { font-size: 22px; font-weight: 800; margin: 0; color: var(--fg); }
    .type-tabs { display: flex; gap: 6px; background: var(--surface-2); padding: 4px; border-radius: 8px; }
    .type-tabs a { padding: 6px 14px; font-size: 13px; font-weight: 600; text-decoration: none; border-radius: 6px; color: var(--muted); }
    .type-tabs a.on { background: var(--surface); color: var(--fg); box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
    @media (max-width: 720px) { .grid-2 { grid-template-columns: 1fr; } .form-card { padding: 20px; } }
    .form-group { display: flex; flex-direction: column; gap: 6px; margin-bottom: 18px; }
    .form-group label { font-size: 13px; font-weight: 700; color: var(--fg); }
    .form-group .hint { font-size: 11.5px; color: var(--muted); }
    .form-group input, .form-group select, .form-group textarea {
      padding: 10px 14px; border: 1px solid var(--line); border-radius: 6px; background: var(--surface-2); color: var(--fg); font-size: 14px; font-family: inherit; transition: border-color .15s ease;
    }
    .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
      outline: none; border-color: var(--accent); background: var(--surface);
    }
    .sec-legend { font-size: 14px; font-weight: 700; color: var(--accent); text-transform: uppercase; letter-spacing: 0.05em; margin: 24px 0 14px; padding-bottom: 6px; border-bottom: 1px solid var(--line); }
    .alert-error { background: rgba(220, 38, 38, 0.1); border: 1px solid var(--crit); color: var(--crit); padding: 12px 16px; border-radius: 6px; margin-bottom: 20px; font-size: 13.5px; font-weight: 600; }
    .btn-row { display: flex; justify-content: flex-end; gap: 12px; margin-top: 28px; padding-top: 20px; border-top: 1px solid var(--line); }
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
        <span>Add Team</span>
      <?php else: ?>
        <a href="<?php echo htmlspecialchars($basePath); ?>/index.php#people">Employees</a>
        <span>/</span>
        <span>Add Employee</span>
      <?php endif; ?>
    </div>

    <?php if ($error !== ''): ?>
      <div class="alert-error"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="form-card">
      <div class="hd">
        <div>
          <h1><?php echo $type === 'team' ? 'Create New Team' : 'Add New Employee'; ?></h1>
          <p style="margin:4px 0 0 0;font-size:13px;color:var(--muted)">
            <?php echo $type === 'team' ? 'Organize employees into functional squads and departments.' : 'Set up a new teammate account with assigned role and shift details.'; ?>
          </p>
        </div>
        <div class="type-tabs">
          <a href="<?php echo htmlspecialchars($basePath); ?>/add.php?type=employee" class="<?php echo $type !== 'team' ? 'on' : ''; ?>">Employee</a>
          <a href="<?php echo htmlspecialchars($basePath); ?>/add.php?type=team" class="<?php echo $type === 'team' ? 'on' : ''; ?>">Team</a>
        </div>
      </div>

      <?php if ($type === 'team'): ?>
        <!-- Team Creation Form -->
        <form method="POST" action="<?php echo htmlspecialchars($basePath); ?>/add.php?type=team">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

          <div class="form-group">
            <label for="teamName">Team Name *</label>
            <input type="text" id="teamName" name="name" required placeholder="e.g. Frontend Engineering, SEO Squad" autofocus>
            <span class="hint">The name of the department or project team.</span>
          </div>

          <div class="form-group">
            <label for="teamManager">Team Manager (Optional)</label>
            <select id="teamManager" name="manager_id">
              <option value="">No manager assigned</option>
              <?php foreach ($managers as $m): ?>
                <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['name'] . ' (' . ($m['job_title'] ?: ucfirst($m['role'])) . ')'); ?></option>
              <?php endforeach; ?>
            </select>
            <span class="hint">The lead responsible for team attendance and leave approvals.</span>
          </div>

          <div class="btn-row">
            <a href="<?php echo htmlspecialchars($basePath); ?>/index.php#teams" class="btn quiet">Cancel</a>
            <button type="submit" class="btn primary">Create Team</button>
          </div>
        </form>

      <?php else: ?>
        <!-- Employee Creation Form -->
        <form method="POST" action="<?php echo htmlspecialchars($basePath); ?>/add.php?type=employee">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

          <div class="sec-legend">1. Personal & Contact Details</div>
          <div class="grid-2">
            <div class="form-group">
              <label for="empName">Full Name *</label>
              <input type="text" id="empName" name="name" required placeholder="e.g. Ronit Roy" autofocus>
            </div>
            <div class="form-group">
              <label for="empEmail">Work Email *</label>
              <input type="email" id="empEmail" name="email" required placeholder="name@company.com">
            </div>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label for="empPhone">Phone Number</label>
              <input type="tel" id="empPhone" name="phone" placeholder="+91 98765 43210">
            </div>
            <div class="form-group">
              <label for="empPassword">Initial Password</label>
              <input type="text" id="empPassword" name="password" placeholder="Leave empty to auto-generate secure password">
              <span class="hint">If left empty, a secure temporary password will be generated automatically.</span>
            </div>
          </div>

          <div class="sec-legend">2. Role & Organizational Placement</div>
          <div class="grid-2">
            <div class="form-group">
              <label for="empJobTitle">Job Title</label>
              <input type="text" id="empJobTitle" name="job_title" placeholder="e.g. Backend Developer">
            </div>
            <div class="form-group">
              <label for="empRole">System Role *</label>
              <select id="empRole" name="role" required>
                <?php foreach ($availableRoles as $ar): ?>
                  <option value="<?php echo htmlspecialchars($ar['slug']); ?>" <?php echo $ar['slug'] === 'employee' ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($ar['name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <span class="hint">Role permissions govern module access and authorization.</span>
            </div>
          </div>

          <div class="grid-2">
            <div class="form-group">
              <label for="empTeam">Team</label>
              <select id="empTeam" name="team_id">
                <option value="">No team assigned</option>
                <?php foreach ($teams as $t): ?>
                  <option value="<?php echo (int)$t['id']; ?>"><?php echo htmlspecialchars($t['name']); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="form-group">
              <label for="empManager">Reporting Manager</label>
              <select id="empManager" name="manager_id">
                <option value="">No manager assigned</option>
                <?php foreach ($managers as $m): ?>
                  <option value="<?php echo (int)$m['id']; ?>"><?php echo htmlspecialchars($m['name'] . ' (' . ($m['job_title'] ?: ucfirst($m['role'])) . ')'); ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <div class="sec-legend">3. Important Dates & Onboarding</div>
          <div class="grid-2">
            <div class="form-group">
              <label for="empJoined">Joining Date *</label>
              <input type="date" id="empJoined" name="joined_date" required value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="form-group">
              <label for="empDob">Date of Birth</label>
              <input type="date" id="empDob" name="date_of_birth">
              <span class="hint">Used for birthday announcements and celebration cards.</span>
            </div>
          </div>

          <div class="form-group">
            <label for="empAnniv">Work Anniversary Date</label>
            <input type="date" id="empAnniv" name="work_anniversary" value="<?php echo date('Y-m-d'); ?>">
            <span class="hint">Defaults to joining date. Used for company work milestone greetings.</span>
          </div>

          <div class="btn-row">
            <a href="<?php echo htmlspecialchars($basePath); ?>/index.php#people" class="btn quiet">Cancel</a>
            <button type="submit" class="btn primary">Add Employee</button>
          </div>
        </form>
      <?php endif; ?>
    </div>
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
  </script>
</body>
</html>

