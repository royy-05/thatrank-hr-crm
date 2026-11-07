<?php
// public/index.php - ThatRank HR Web Entry Point and Router

require_once dirname(__DIR__) . '/config/bootstrap.php';

use App\Controllers\AuthController;
use App\Controllers\AttendanceController;
use App\Controllers\LeaveController;
use App\Controllers\LiveBoardController;
use App\Controllers\HeartbeatController;
use App\Controllers\CommunityController;
use App\Controllers\EmployeeController;
use App\Controllers\ReportsController;
use App\Controllers\SettingsController;
use App\Controllers\AuditController;
use App\Controllers\HolidayController;
use App\Controllers\EventController;
use App\Controllers\TeamController;
use App\Controllers\DashboardController;
use App\Controllers\PayrollController;
use App\Controllers\FinanceController;
use App\Controllers\RoleController;
use App\Middleware\AuthMiddleware;

$scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);

// Normalize script directory
$scriptDir = rtrim(dirname($scriptName), '/');
if ($scriptDir === '.' || $scriptDir === '/') {
    $scriptDir = '';
}

// Compute base path: handles /thatrank-hr-software/public, /thatrank-hr-software, or root
$basePath = '';
if ($scriptDir !== '' && str_starts_with($requestPath, $scriptDir)) {
    $basePath = $scriptDir;
} else {
    $cleanDir = preg_replace('#/public$#', '', $scriptDir);
    if ($cleanDir !== '' && str_starts_with($requestPath, $cleanDir)) {
        $basePath = $cleanDir;
    } elseif ($scriptDir !== '') {
        $basePath = $scriptDir;
    }
}

// Route relative to basePath
$route = '/' . ltrim(substr($requestPath, strlen($basePath)), '/');

// Determine asset base URL
if ($basePath === '') {
    $assetBase = '/assets';
} elseif (str_ends_with($basePath, '/public')) {
    $assetBase = $basePath . '/assets';
} else {
    $assetBase = $basePath . '/public/assets';
}

$apiBase = rtrim($basePath, '/') . '/api';
$uri = $route;

// ==================== API ROUTING ====================
if (str_starts_with($uri, '/api/')) {
    $endpoint = substr($uri, 5);

    switch ($endpoint) {
        // Auth
        case 'auth/login':
            AuthController::login();
            break;
        case 'auth/logout':
            AuthController::logout();
            break;
        case 'auth/me':
            AuthController::me();
            break;
        case 'auth/consent':
            AuthController::consent();
            break;
        case 'auth/forgot-password':
            AuthController::forgotPassword();
            break;
        case 'auth/devices':
            AuthController::devices();
            break;
        case 'auth/signout-others':
            AuthController::signOutOthers();
            break;
        case 'auth/change-password':
            AuthController::changePassword();
            break;

        // Dashboard (Role-specific)
        case 'dashboard':
            DashboardController::index();
            break;
        case 'dashboard/employee':
            DashboardController::employee();
            break;
        case 'dashboard/manager':
            DashboardController::manager();
            break;
        case 'dashboard/admin':
            DashboardController::admin();
            break;

        // Attendance & Punch
        case 'attendance/today':
            AttendanceController::today();
            break;
        case 'attendance/stats':
            AttendanceController::stats();
            break;
        case 'attendance/punch':
            AttendanceController::punch();
            break;
        case 'attendance/punch-undo':
            AttendanceController::undoPunch();
            break;
        case 'attendance/punch-edit':
            AttendanceController::editPunch();
            break;
        case 'attendance/logs':
            AttendanceController::logs();
            break;
        case 'attendance/calendar':
            AttendanceController::calendar();
            break;
        case 'attendance/people':
            AttendanceController::scopePeople();
            break;
        case 'attendance/correction/list':
            AttendanceController::listCorrections();
            break;
        case 'attendance/correction':
            AttendanceController::requestCorrection();
            break;
        case 'attendance/correction/pending':
            AttendanceController::pendingCorrections();
            break;
        case 'attendance/correction/action':
            AttendanceController::actionCorrection();
            break;

        // Teammates directory (Active employees)
        case 'teammates':
            AuthMiddleware::requireLogin();
            $db = \App\Config\Database::getConnection();
            $stmt = $db->query("SELECT id, name, email, avatar_initials, avatar_color, role, department FROM users WHERE status = 'active' ORDER BY name ASC");
            jsonResponse(['success' => true, 'teammates' => $stmt->fetchAll()]);
            break;

        // Leave
        case 'leave/balances':
            LeaveController::balances();
            break;
        case 'leave/requests':
            LeaveController::requests();
            break;
        case 'leave/stats':
            LeaveController::stats();
            break;
        case 'leave/types':
        case 'leave-types':
            LeaveController::types();
            break;
        case 'leave-types/all':
            LeaveController::allTypes();
            break;
        case 'leave-types/save':
            LeaveController::saveType();
            break;
        case 'leave-types/toggle':
            LeaveController::toggleType();
            break;
        case 'leave-types/delete':
            LeaveController::deleteType();
            break;
        case 'leave/comp-off-request':
            LeaveController::submitCompOff();
            break;
        case 'leave/comp-off-pending':
            LeaveController::pendingCompOff();
            break;
        case 'leave/comp-off-action':
            LeaveController::actionCompOff();
            break;
        case 'leave/submit':
            LeaveController::submit();
            break;
        case 'leave/withdraw':
            LeaveController::withdraw();
            break;
        case 'leave/pending':
            LeaveController::pending();
            break;
        case 'leave/action':
            LeaveController::action();
            break;

        // Holidays
        case 'holidays':
            HolidayController::index();
            break;
        case 'holidays/create':
            HolidayController::create();
            break;
        case 'holidays/update':
            HolidayController::update();
            break;
        case 'holidays/delete':
            HolidayController::delete();
            break;

        // Live Board & Heartbeat
        case 'liveboard':
        case 'live-board':
            LiveBoardController::index();
            break;
        case 'heartbeat':
            HeartbeatController::beat();
            break;

        // Community & Celebrations
        case 'community/feed':
            CommunityController::feed();
            break;
        case 'community/create':
            CommunityController::create();
            break;
        case 'community/like':
            CommunityController::like();
            break;
        case 'community/vote':
            CommunityController::vote();
            break;
        case 'community/active-poll':
            CommunityController::activePoll();
            break;
        case 'celebrations':
            CommunityController::celebrations();
            break;

        // Events
        case 'events':
            EventController::index();
            break;
        case 'events/create':
            EventController::create();
            break;

        // Teams
        case 'teams':
            TeamController::index();
            break;
        case 'teams/get':
            TeamController::get();
            break;
        case 'teams/create':
            TeamController::create();
            break;
        case 'teams/update':
            TeamController::update();
            break;
        case 'teams/delete':
            TeamController::delete();
            break;
        case 'teams/assign':
            TeamController::assign();
            break;

        // Employees
        case 'employees':
            EmployeeController::index();
            break;
        case 'employees/get':
            EmployeeController::get();
            break;
        case 'employees/create':
            EmployeeController::create();
            break;
        case 'employees/update':
            EmployeeController::update();
            break;
        case 'employees/offboard':
            EmployeeController::offboard();
            break;
        case 'employees/import-csv':
            EmployeeController::importCsv();
            break;

        // Reports & Exports
        case 'reports':
            ReportsController::index();
            break;
        case 'reports/csv':
            ReportsController::exportCsv();
            break;
        case 'reports/pdf':
            ReportsController::exportPdf();
            break;

        // Company Settings
        case 'settings/public':
            SettingsController::publicSettings();
            break;
        case 'settings':
            SettingsController::index();
            break;
        case 'settings/update':
            SettingsController::update();
            break;

        // Audit Log
        case 'audit':
            AuditController::index();
            break;

        // Payroll (Admin only)
        case 'payroll':
            PayrollController::index();
            break;
        case 'payroll/generate':
            PayrollController::generate();
            break;
        case 'payroll/finalize':
            PayrollController::finalize();
            break;
        case 'payroll/paid':
            PayrollController::markPaid();
            break;
        case 'payroll/reopen':
            PayrollController::reopen();
            break;
        case 'payroll/update-payslip':
            PayrollController::updatePayslip();
            break;
        case 'payroll/regenerate-employee':
            PayrollController::regenerateEmployee();
            break;
        case 'payroll/export/bank':
            PayrollController::exportBankCsv();
            break;
        case 'payroll/export/summary':
            PayrollController::exportSummaryCsv();
            break;
        case 'payroll/export/zip':
            PayrollController::exportZip();
            break;
        case 'payroll/components':
            PayrollController::getComponents();
            break;
        case 'payroll/components/save':
            PayrollController::saveComponent();
            break;
        case 'payroll/components/toggle':
            PayrollController::toggleComponentActive();
            break;
        case 'payroll/components/reorder':
            PayrollController::reorderComponents();
            break;
        case 'payroll/structures':
            PayrollController::getStructures();
            break;
        case 'payroll/structures/history':
            PayrollController::getStructureHistory();
            break;
        case 'payroll/structures/save':
            PayrollController::saveStructure();
            break;
        case 'payroll/structures/bulk-save':
            PayrollController::bulkSaveStructure();
            break;
        case 'payroll/settings':
            PayrollController::getSettings();
            break;
        case 'payroll/settings/update':
            PayrollController::updateSettings();
            break;

        // Finances (Employee & Manager own finances)
        case 'finances':
            FinanceController::getMyFinances();
            break;
        case 'finances/payslip-pdf':
            FinanceController::downloadPdf();
            break;

        // Roles & Permissions
        case 'roles':
            RoleController::index();
            break;
        case 'roles/get':
            RoleController::get();
            break;
        case 'roles/create':
            RoleController::create();
            break;
        case 'roles/update':
            RoleController::update();
            break;
        case 'roles/delete':
            RoleController::delete();
            break;
        case 'roles/save':
            RoleController::save();
            break;
        case 'roles/reset':
            RoleController::reset();
            break;
        case 'roles/my-permissions':
            RoleController::myPermissions();
            break;

        default:
            jsonResponse(['success' => false, 'error' => 'API endpoint not found.'], 404);
            break;
    }
    exit;
}

// ==================== DEDICATED PAGES ROUTING ====================
if ($uri === '/add' || $uri === '/add.php') {
    require __DIR__ . '/add.php';
    exit;
}
if ($uri === '/view' || $uri === '/view.php') {
    require __DIR__ . '/view.php';
    exit;
}
if ($uri === '/edit' || $uri === '/edit.php') {
    require __DIR__ . '/edit.php';
    exit;
}

// ==================== HTML PAGE RENDERING ====================
$currentUser = AuthMiddleware::user();
$logoBase64 = file_exists(__DIR__ . '/assets/logo_base64.txt') ? trim(file_get_contents(__DIR__ . '/assets/logo_base64.txt')) : '';
$csrfToken = getCsrfToken();
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
  <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
  <title>ThatRank HR</title>
  <meta name="claude:viewport-fit-sides" content="cover">
  <link rel="manifest" href="<?php echo htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8'); ?>/manifest.json">
  <meta name="theme-color" content="#1e3e62">
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Figtree:wght@400;500;600;700;800&display=swap">
  <link rel="stylesheet" href="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>/css/style.css?v=<?php echo filemtime(__DIR__ . '/assets/css/style.css'); ?>">
  <script>
    (function(){
      var r = document.documentElement;
      var saved = localStorage.getItem('thatrank_theme') || 'light';
      r.setAttribute('data-theme', saved);
    })();
    window.APP_CONFIG = {
      basePath: <?php echo json_encode($basePath); ?>,
      apiBase: <?php echo json_encode($apiBase); ?>,
      assetBase: <?php echo json_encode($assetBase); ?>
    };
  </script>
</head>
<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <?php require_once dirname(__DIR__) . '/app/views/svg_icons.php'; ?>
</svg>

<!-- ===================== LOGIN VIEW ===================== -->
<div class="login" id="loginView" <?php echo $currentUser ? 'hidden' : ''; ?>>
  <div class="art">
    <div class="wordmark">
      <img class="logo" src="<?php echo $logoBase64; ?>" alt="ThatRank" style="width:200px">
    </div>
    <div class="clockface"><b class="num" id="loginClock"><?php echo date('g:i'); ?></b><?php echo date('l, j F'); ?></div>
    <div>
      <h1>Your workday, in one place.</h1>
      <p>Clock in, take breaks and log out from wherever you work. Leave, hours and team updates stay in sync for everyone.</p>
    </div>
    <div class="ring"></div><div class="ring b"></div>
  </div>
  <div class="pane">
    <form class="form" id="loginForm">
      <div class="card-wrap">
        <h2>Sign in</h2>
        <p class="lead">Use your work email to continue.</p>
        <div class="field">
          <label for="em">Work email</label>
          <input id="em" type="email" placeholder="you@thatrank.com" autocomplete="username" required>
        </div>
        <div class="field">
          <label for="pw">Password</label>
          <input id="pw" type="password" placeholder="••••••••" autocomplete="current-password" required>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;margin:14px 0 18px">
          <label style="display:flex;gap:8px;align-items:center;font-size:13px;color:var(--fg-2)">
            <input type="checkbox" checked id="rem"> Keep me signed in
          </label>
          <a href="#" id="linkForgotPw" style="font-size:13px;color:var(--accent);text-decoration:none;font-weight:500">Forgot password?</a>
        </div>
        <button class="btn primary block" type="submit">Sign in</button>
        <div class="consent">
          <b>Before your first punch:</b> this app records a selfie, your location and device details each time you clock in, take a break or clock out. Only your manager and HR can see them. Photos are deleted after <span id="loginNoticeDays">90</span> days.
        </div>
      </div>
    </form>
  </div>
</div>

<!-- ===================== MAIN APP SHELL ===================== -->
<div class="shell" id="shell" <?php echo !$currentUser ? 'hidden' : ''; ?>>
  <aside class="rail" id="rail">
    <div class="wordmark">
      <img class="logo" src="<?php echo $logoBase64; ?>" alt="ThatRank">
    </div>
    <nav id="nav">
      <button data-s="dash" class="on"><svg class="i"><use href="#i-home"/></svg>Dashboard</button>
      <button data-s="attn"><svg class="i"><use href="#i-clock"/></svg><span id="navAttnLabel">My attendance</span><svg class="i chev" viewBox="0 0 24 24" hidden><path d="m6 9 6 6 6-6"/></svg></button>
      <div class="subnav" data-for="attn" hidden>
        <button type="button" data-sub="attn" data-view="me">My attendance</button>
        <button type="button" data-sub="attn" data-view="team">Team attendance</button>
      </div>
      <button data-s="leave"><svg class="i"><use href="#i-leave"/></svg><span>Leave</span><svg class="i chev" viewBox="0 0 24 24" hidden><path d="m6 9 6 6 6-6"/></svg></button>
      <div class="subnav" data-for="leave" hidden>
        <button type="button" data-sub="leave" data-view="me">My leave</button>
        <button type="button" data-sub="leave" data-view="team">Team leave</button>
      </div>
      <button data-s="hub"><svg class="i"><use href="#i-hub"/></svg>Community</button>
      <button data-s="fin"><svg class="i"><use href="#i-fin"/></svg>My finances</button>

      <div class="group" data-mgr hidden>Team</div>
      <button data-s="board" data-mgr hidden><svg class="i"><use href="#i-board"/></svg>Live board</button>
      <button data-s="approve" data-mgr hidden>
        <svg class="i"><use href="#i-check"/></svg>Approvals
        <span class="count" id="pendingApprovalCount" style="display:none">0</span>
      </button>
      <button data-s="reports" data-mgr hidden><svg class="i"><use href="#i-report"/></svg>Reports</button>

      <div class="group" data-admin hidden>Company</div>
      <button data-s="payroll" data-admin hidden><svg class="i"><use href="#i-fin"/></svg>Payroll</button>
      <button data-s="people" data-admin hidden><svg class="i"><use href="#i-people"/></svg>Employees</button>
      <button data-s="teams" data-admin hidden><svg class="i"><use href="#i-people"/></svg>Teams</button>
      <button data-s="roles" data-admin hidden><svg class="i"><use href="#i-shield"/></svg>Roles</button>
      <button data-s="audit" data-admin hidden><svg class="i"><use href="#i-report"/></svg>Audit log</button>
      <button data-s="settings"><svg class="i"><use href="#i-settings"/></svg>Settings</button>
    </nav>
    <div class="me">
      <div class="avatar" id="meAv"><?php echo $currentUser['avatar_initials'] ?? 'TR'; ?></div>
      <div style="min-width:0">
        <div class="n" id="meName"><?php echo htmlspecialchars($currentUser['name'] ?? 'User'); ?></div>
        <div class="r" id="meRole"><?php echo htmlspecialchars($currentUser['job_title'] ?? 'Staff'); ?></div>
      </div>
      <button class="btn quiet sm" id="logoutBtn" title="Sign out" style="margin-left:auto;padding:6px">
        <svg class="i"><use href="#i-logout"/></svg>
      </button>
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <button class="iconbtn menubtn" id="menuBtn" aria-label="Menu"><svg class="i"><use href="#i-menu"/></svg></button>
      <div>
        <h1 id="scrTitle">Good morning<?php echo !empty($currentUser['name']) ? ', ' . htmlspecialchars(explode(' ', $currentUser['name'])[0]) : ''; ?></h1>
        <div class="sub" id="scrSub"><?php echo date('l, j F Y'); ?></div>
      </div>
      <div class="grow"></div>
      <button class="iconbtn" aria-label="Notifications"><svg class="i"><use href="#i-bell"/></svg></button>
      <button class="iconbtn" id="themeBtn" aria-label="Toggle theme"><svg class="i"><use href="#i-sun"/></svg></button>
    </div>

    <div class="content">

      <!-- ==================== HOME / DASHBOARD (Role-specific) ==================== -->
      <section class="screen" data-screen="dash">
        <!-- ==================== EMPLOYEE DASHBOARD ==================== -->
        <div id="dashEmployee" style="display:none">
          <div class="cols side-main">
            <div class="qa">
              <!-- Standout Navy Punch Panel -->
              <div class="punch compact">
                <div>
                  <div class="date" id="empBigDate"><?php echo date('l, j F'); ?></div>
                  <div class="time num"><span id="empBigClock"><?php echo date('g:i'); ?></span><small id="empBigAmPm"><?php echo date('A'); ?></small></div>
                </div>
                <div class="status"><i id="empStDot" class="off"></i><span id="empStText">Not clocked in yet</span></div>
                <div class="actions">
                  <button class="pb ghost" id="empBtnBreak" disabled>Start break</button>
                  <button class="pb main" id="empBtnOut">Clock in</button>
                </div>
                <div class="actions" style="margin-top:6px;gap:6px">
                  <button class="btn xs quiet" id="empBtnUndoPunch" style="display:none;font-size:11.5px;padding:4px 8px">Undo punch (15m window)</button>
                  <button class="btn xs quiet" id="empBtnEditPunch" style="display:none;font-size:11.5px;padding:4px 8px">Edit punch</button>
                </div>
                <div class="note">
                  <svg class="i"><use href="#i-camera"/></svg>Selfie, location and device are recorded on each punch.
                </div>
              </div>

              <!-- Next holiday -->
              <div class="holiday">
                <div class="spark"></div>
                <div class="label">Next holiday</div>
                <div class="big" id="empNextHolidayName">--</div>
                <div class="sub" id="empNextHolidayDate">--</div>
                <a class="link" href="#" id="empLinkViewHolidays" style="color:#fff;opacity:.8;display:inline-block;margin-top:8px">View all holidays</a>
              </div>

              <!-- On leave today -->
              <div class="panel">
                <div class="bd">
                  <div class="label">On leave today</div>
                  <div id="empOnLeaveList" style="margin-top:8px">
                    <div style="font-size:13px;color:var(--muted)">No one on leave today</div>
                  </div>
                </div>
              </div>

              <!-- Leave balances -->
              <div class="panel">
                <div class="bd">
                  <div class="row">
                    <div class="label">Leave balances</div>
                    <a class="link" href="#" onclick="document.querySelector('button[data-s=leave]').click(); return false;">Request leave</a>
                  </div>
                  <div class="rings" id="empLeaveRings">
                    <div class="ring">
                      <svg viewBox="0 0 56 56"><circle class="bg" cx="28" cy="28" r="23"/><circle class="fg" id="empRingCasual" cx="28" cy="28" r="23" stroke-dasharray="144.5" stroke-dashoffset="144.5"/></svg>
                      <div><div class="v num" id="empCasualVal">0</div><div class="k">Casual</div></div>
                    </div>
                    <div class="ring">
                      <svg viewBox="0 0 56 56"><circle class="bg" cx="28" cy="28" r="23"/><circle class="fg" id="empRingSick" cx="28" cy="28" r="23" stroke-dasharray="144.5" stroke-dashoffset="144.5" style="stroke:var(--warn)"/></svg>
                      <div><div class="v num" id="empSickVal">0</div><div class="k">Sick</div></div>
                    </div>
                    <div class="ring">
                      <svg viewBox="0 0 56 56"><circle class="bg" cx="28" cy="28" r="23"/><circle class="fg" id="empRingEarned" cx="28" cy="28" r="23" stroke-dasharray="144.5" stroke-dashoffset="144.5" style="stroke:var(--ok)"/></svg>
                      <div><div class="v num" id="empEarnedVal">0</div><div class="k">Earned</div></div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Quick links -->
              <div class="panel">
                <div class="bd">
                  <div class="label">Quick links</div>
                  <div style="display:flex;flex-direction:column;gap:6px;margin-top:8px">
                    <a class="link" href="#" onclick="document.querySelector('button[data-s=attn]').click(); return false;">My attendance log</a>
                    <a class="link" href="#" onclick="document.querySelector('button[data-s=leave]').click(); return false;">Submit leave request</a>
                    <a class="link" href="#" id="empLinkHolidayCal">Holiday calendar <span class="current-year"></span></a>
                  </div>
                </div>
              </div>
            </div>

            <!-- Main column -->
            <div class="stack">
              <!-- Stats Row: Hours, Late marks, Pending requests -->
              <div class="stats">
                <div><div class="k">Today</div><div class="v num" id="empTodayHours">0h 00m</div><div class="d" id="empTargetHoursLabel">Target 9h</div></div>
                <div><div class="k">This week</div><div class="v num" id="empThisWeekHours">0h 00m</div><div class="d" id="empWorkWeekLabel">Mon to Fri</div></div>
                <div><div class="k">Late marks</div><div class="v num" id="empLateMarks">0</div><div class="d">this month</div></div>
                <div><div class="k">Pending requests</div><div class="v num" id="empPendingRequests">0</div><div class="d">leave & corrections</div></div>
              </div>

              <!-- Composer -->
              <div class="composer open" id="empComposer">
                <div class="ctabs">
                  <button class="on" data-tab="post"><svg class="i"><use href="#i-comment"/></svg>Post</button>
                  <button data-tab="poll"><svg class="i"><use href="#i-report"/></svg>Poll</button>
                  <button data-tab="praise"><svg class="i"><use href="#i-heart"/></svg>Praise</button>
                </div>
                <div class="praise-picker-row" id="praisePickerRowEmp" style="display:none;padding:8px 12px;background:var(--accent-soft);border-radius:6px;margin:8px 0;align-items:center;gap:8px">
                  <span style="font-size:13px;font-weight:600;color:var(--accent)">Praise teammate:</span>
                  <select class="praise-select btn xs" id="praiseTeammateEmp" style="flex:1">
                    <option value="">Select teammate to praise...</option>
                  </select>
                </div>
                <textarea id="composerInputEmp" placeholder="Write your post and mention your teammates"></textarea>
                <div class="preview" hidden>
                  <img alt=""><button type="button" class="rm" aria-label="Remove photo">&times;</button>
                </div>
                <div class="foot">
                  <div class="rowbtns">
                    <button type="button" class="btn quiet sm addphoto"><svg class="i" style="width:16px;height:16px"><use href="#i-camera"/></svg>Add photo</button>
                    <input type="file" accept="image/*" hidden>
                  </div>
                  <div class="rowbtns">
                    <span class="hint">Visible to everyone</span>
                    <button type="button" class="btn primary sm dopost">Post</button>
                  </div>
                </div>
              </div>

              <!-- Celebrations (Next 30 days) -->
              <div class="panel">
                <div class="tabs" style="padding:0 8px">
                  <button class="on">Celebrations (Next 30 days)</button>
                </div>
                <div class="bd">
                  <div class="bday" id="empCelebrationsList" style="grid-template-columns:repeat(auto-fill,minmax(90px,1fr))">
                    <div style="font-size:13px;color:var(--muted);padding:8px 0">No upcoming celebrations in the next 30 days.</div>
                  </div>
                </div>
              </div>

              <!-- Feed & Announcements container -->
              <div id="empFeedContainer">
                <!-- Dynamically populated from /api/community/feed -->
              </div>
            </div>
          </div>
        </div>

        <!-- ==================== MANAGER DASHBOARD ==================== -->
        <div id="dashManager" style="display:none">
          <div class="stack" style="gap:16px">
            <!-- My Team Block -->
            <div class="panel" style="border-left:4px solid var(--accent)">
              <div class="hd">
                <h3>My team status</h3>
                <div class="grow"></div>
                <button class="btn xs" onclick="document.querySelector('button[data-s=board]').click();">Open live board</button>
              </div>
              <div class="bd">
                <div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(125px,1fr));margin:0">
                  <div><div class="k"><span class="pill ok plain" style="padding:0;background:none">Working</span></div><div class="v num" id="mgrTeamWorking">0</div><div class="d">active now</div></div>
                  <div><div class="k"><span class="pill neutral plain" style="padding:0;background:none">Idle</span></div><div class="v num" id="mgrTeamIdle">0</div><div class="d">tab open, 5m+ idle</div></div>
                  <div><div class="k"><span class="pill warn plain" style="padding:0;background:none">On break</span></div><div class="v num" id="mgrTeamBreak">0</div><div class="d">away</div></div>
                  <div><div class="k"><span class="pill crit plain" style="padding:0;background:none">Offline</span></div><div class="v num" id="mgrTeamOffline">0</div><div class="d">tab closed</div></div>
                  <div><div class="k"><span class="pill crit plain" style="padding:0;background:none">Not in</span></div><div class="v num" id="mgrTeamNotIn">0</div><div class="d">un-clocked / on leave</div></div>
                  <div><div class="k">Pending approvals</div><div class="v num" id="mgrPendingCount">0</div><div class="d"><a href="#" onclick="document.querySelector('button[data-s=approve]').click(); return false;">Review requests</a></div></div>
                  <div><div class="k">Team late marks</div><div class="v num" id="mgrTeamLateCount">0</div><div class="d">this week</div></div>
                </div>
              </div>
            </div>

            <!-- Two-column manager personal + feed layout -->
            <div class="cols side-main">
              <div class="qa">
                <!-- Manager's own punch card -->
                <div class="punch compact">
                  <div>
                    <div class="date" id="mgrBigDate"><?php echo date('l, j F'); ?></div>
                    <div class="time num"><span id="mgrBigClock"><?php echo date('g:i'); ?></span><small id="mgrBigAmPm"><?php echo date('A'); ?></small></div>
                  </div>
                  <div class="status"><i id="mgrStDot" class="off"></i><span id="mgrStText">Not clocked in yet</span></div>
                  <div class="actions">
                    <button class="pb ghost" id="mgrBtnBreak" disabled>Start break</button>
                    <button class="pb main" id="mgrBtnOut">Clock in</button>
                  </div>
                  <div class="actions" style="margin-top:6px;gap:6px">
                    <button class="btn xs quiet" id="mgrBtnUndoPunch" style="display:none;font-size:11.5px;padding:4px 8px">Undo punch (15m window)</button>
                    <button class="btn xs quiet" id="mgrBtnEditPunch" style="display:none;font-size:11.5px;padding:4px 8px">Edit punch</button>
                  </div>
                  <div class="note">
                    <svg class="i"><use href="#i-camera"/></svg>Selfie, location and device recorded on each punch.
                  </div>
                </div>

                <!-- Next holiday -->
                <div class="holiday">
                  <div class="spark"></div>
                  <div class="label">Next holiday</div>
                  <div class="big" id="mgrNextHolidayName">--</div>
                  <div class="sub" id="mgrNextHolidayDate">--</div>
                  <a class="link" href="#" id="mgrLinkViewHolidays" style="color:#fff;opacity:.8;display:inline-block;margin-top:8px">View all holidays</a>
                </div>

                <!-- On leave today -->
                <div class="panel">
                  <div class="bd">
                    <div class="label">On leave today</div>
                    <div id="mgrOnLeaveList" style="margin-top:8px">
                      <div style="font-size:13px;color:var(--muted)">No team members on leave today</div>
                    </div>
                  </div>
                </div>

                <!-- Leave balances -->
                <div class="panel">
                  <div class="bd">
                    <div class="row">
                      <div class="label">Leave balances</div>
                      <a class="link" href="#" onclick="document.querySelector('button[data-sub=leave][data-view=me]').click(); return false;">Request leave</a>
                    </div>
                    <div class="rings">
                      <div class="ring">
                        <svg viewBox="0 0 56 56"><circle class="bg" cx="28" cy="28" r="23"/><circle class="fg" cx="28" cy="28" r="23" stroke-dasharray="144.5" stroke-dashoffset="144.5"/></svg>
                        <div><div class="v num" id="mgrCasualVal">0</div><div class="k">Casual</div></div>
                      </div>
                      <div class="ring">
                        <svg viewBox="0 0 56 56"><circle class="bg" cx="28" cy="28" r="23"/><circle class="fg" cx="28" cy="28" r="23" stroke-dasharray="144.5" stroke-dashoffset="144.5" style="stroke:var(--warn)"/></svg>
                        <div><div class="v num" id="mgrSickVal">0</div><div class="k">Sick</div></div>
                      </div>
                      <div class="ring">
                        <svg viewBox="0 0 56 56"><circle class="bg" cx="28" cy="28" r="23"/><circle class="fg" cx="28" cy="28" r="23" stroke-dasharray="144.5" stroke-dashoffset="144.5" style="stroke:var(--ok)"/></svg>
                        <div><div class="v num" id="mgrEarnedVal">0</div><div class="k">Earned</div></div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Main column for manager -->
              <div class="stack">
                <!-- Stats Row -->
                <div class="stats">
                  <div><div class="k">My hours today</div><div class="v num" id="mgrTodayHours">0h 00m</div><div class="d" id="mgrTargetHoursLabel">Target 9h</div></div>
                  <div><div class="k">My hours this week</div><div class="v num" id="mgrThisWeekHours">0h 00m</div><div class="d" id="mgrWorkWeekLabel">Mon to Fri</div></div>
                  <div><div class="k">My late marks</div><div class="v num" id="mgrLateMarks">0</div><div class="d">this month</div></div>
                  <div><div class="k">My pending requests</div><div class="v num" id="mgrPendingRequests">0</div><div class="d">personal</div></div>
                </div>

                <!-- Composer -->
                <div class="composer open" id="mgrComposer">
                  <div class="ctabs">
                    <button class="on" data-tab="post"><svg class="i"><use href="#i-comment"/></svg>Post</button>
                    <button data-tab="poll"><svg class="i"><use href="#i-report"/></svg>Poll</button>
                    <button data-tab="praise"><svg class="i"><use href="#i-heart"/></svg>Praise</button>
                  </div>
                  <div class="praise-picker-row" id="praisePickerRowMgr" style="display:none;padding:8px 12px;background:var(--accent-soft);border-radius:6px;margin:8px 0;align-items:center;gap:8px">
                    <span style="font-size:13px;font-weight:600;color:var(--accent)">Praise teammate:</span>
                    <select class="praise-select btn xs" id="praiseTeammateMgr" style="flex:1">
                      <option value="">Select teammate to praise...</option>
                    </select>
                  </div>
                  <textarea id="composerInputMgr" placeholder="Write your post and mention your teammates"></textarea>
                  <div class="preview" hidden>
                    <img alt=""><button type="button" class="rm" aria-label="Remove photo">&times;</button>
                  </div>
                  <div class="foot">
                    <div class="rowbtns">
                      <button type="button" class="btn quiet sm addphoto"><svg class="i" style="width:16px;height:16px"><use href="#i-camera"/></svg>Add photo</button>
                      <input type="file" accept="image/*" hidden>
                    </div>
                    <div class="rowbtns">
                      <span class="hint">Visible to everyone</span>
                      <button type="button" class="btn primary sm dopost">Post</button>
                    </div>
                  </div>
                </div>

                <!-- Celebrations -->
                <div class="panel">
                  <div class="tabs" style="padding:0 8px">
                    <button class="on">Celebrations (Next 30 days)</button>
                  </div>
                  <div class="bd">
                    <div class="bday" id="mgrCelebrationsList" style="grid-template-columns:repeat(auto-fill,minmax(90px,1fr))">
                      <div style="font-size:13px;color:var(--muted);padding:8px 0">No upcoming celebrations in the next 30 days.</div>
                    </div>
                  </div>
                </div>

                <!-- Feed -->
                <div id="mgrFeedContainer">
                  <!-- Dynamically populated -->
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- ==================== ADMIN DASHBOARD ==================== -->
        <div id="dashAdmin" style="display:none">
          <div class="stack" style="gap:20px">
            <!-- Company-wide stats grid -->
            <div>
              <div class="sec"><h2>Company Overview</h2><div class="grow"></div><span class="meta"><?php echo date('l, j F Y'); ?></span></div>
              <div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(160px,1fr))">
                <div><div class="k">Total employees</div><div class="v num" id="admTotalEmployees">0</div><div class="d">active staff</div></div>
                <div><div class="k">Present today</div><div class="v num" id="admPresentToday">0</div><div class="d">clocked in</div></div>
                <div><div class="k">On leave today</div><div class="v num" id="admOnLeaveToday">0</div><div class="d">approved leaves</div></div>
                <div><div class="k">Pending approvals</div><div class="v num" id="admPendingApprovals">0</div><div class="d">across all teams</div></div>
                <div><div class="k">Late marks</div><div class="v num" id="admLateMarksWeek">0</div><div class="d">this week</div></div>
                <div><div class="k">Anomaly flags</div><div class="v num" id="admAnomalyCount">0</div><div class="d">today</div></div>
              </div>
            </div>

            <!-- Quick management actions -->
            <div class="panel">
              <div class="hd"><h3>Quick management links</h3></div>
              <div class="bd" style="display:flex;gap:10px;flex-wrap:wrap">
                <button class="btn sm" onclick="document.querySelector('button[data-s=people]').click();"><svg class="i" style="width:15px;height:15px"><use href="#i-people"/></svg>Manage Employees</button>
                <button class="btn sm" onclick="document.querySelector('button[data-s=teams]').click();"><svg class="i" style="width:15px;height:15px"><use href="#i-people"/></svg>Manage Teams</button>
                <button class="btn sm" onclick="document.querySelector('button[data-s=settings]').click();"><svg class="i" style="width:15px;height:15px"><use href="#i-settings"/></svg>Company Settings</button>
                <button class="btn sm" id="admBtnHolidayCal"><svg class="i" style="width:15px;height:15px"><use href="#i-calendar"/></svg>Holiday Calendar</button>
                <button class="btn sm" id="admBtnCsvImport"><svg class="i" style="width:15px;height:15px"><use href="#i-report"/></svg>Import Employee CSV</button>
                <button class="btn sm" onclick="document.querySelector('button[data-s=audit]').click();"><svg class="i" style="width:15px;height:15px"><use href="#i-report"/></svg>View Audit Log</button>
              </div>
            </div>

            <!-- Two-column admin data -->
            <div class="cols c2" style="gap:16px">
              <!-- Recent Audit Log Entries -->
              <div class="panel">
                <div class="hd">
                  <h3>Recent audit log entries</h3>
                  <div class="grow"></div>
                  <a href="#" onclick="document.querySelector('button[data-s=audit]').click(); return false;" class="link">View full log</a>
                </div>
                <div class="tblwrap">
                  <table class="tbl">
                    <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Target</th></tr></thead>
                    <tbody id="admRecentAuditBody">
                      <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:14px">No audit entries yet</td></tr>
                    </tbody>
                  </table>
                </div>
              </div>

              <!-- Company On Leave Today -->
              <div class="panel">
                <div class="hd">
                  <h3>On leave today</h3>
                  <div class="grow"></div>
                  <span class="meta" id="admLeaveMeta">Company wide</span>
                </div>
                <div class="bd" id="admOnLeaveList" style="padding-block:6px">
                  <div style="font-size:13px;color:var(--muted);padding:8px 0">No employees on leave today.</div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ==================== MY ATTENDANCE ==================== -->
      <section class="screen stack" data-screen="attn" hidden>
        <div class="att-top">
          <div class="panel">
            <div class="hd">
              <h3>Attendance stats</h3>
              <div class="grow"></div>
              <select class="btn xs" id="selectAttnPeriod">
                <option value="this_week">This week</option>
                <option value="last_week">Last week</option>
                <option value="this_month">This month</option>
                <option value="last_month">Last month</option>
              </select>
            </div>
            <div class="bd" style="padding-block:4px">
              <div class="att-stat" id="attnStatRowMe">
                <div class="who"><span class="avatar sm" id="attnStatMeAvatar"><?php echo $currentUser['avatar_initials'] ?? 'TR'; ?></span><span id="attnStatMeName">Me</span></div>
                <div class="m"><div class="k">Avg hrs / day</div><div class="v num" id="attnStatMeHours">--</div></div>
                <div class="m"><div class="k">On-time arrival</div><div class="v num" id="attnStatMeOnTime">--</div></div>
              </div>
              <div class="att-stat">
                <div class="who"><span class="avatar sm" style="background:var(--accent)"><svg class="i" style="width:15px;height:15px"><use href="#i-people"/></svg></span><span id="attnStatTeamName">My team</span></div>
                <div class="m"><div class="k">Avg hrs / day</div><div class="v num" id="attnStatTeamHours">--</div></div>
                <div class="m"><div class="k">On-time arrival</div><div class="v num" id="attnStatTeamOnTime">--</div></div>
              </div>
            </div>
          </div>
          <div class="panel">
            <div class="hd"><h3>Timings</h3></div>
            <div class="bd">
              <div class="days" id="timingDaysRow"><span>M</span><span>T</span><span>W</span><span>T</span><span>F</span><span>S</span><span>S</span></div>
              <div class="shift">
                <div class="t" id="timingShiftText">--</div>
                <div class="bar" id="timingShiftBar"><i class="work" style="width:0%"></i><i class="brk" style="width:0%"></i><i class="rest"></i></div>
                <div class="meta" id="timingShiftMeta"><span>Duration --</span><span>Break --</span></div>
              </div>
            </div>
          </div>
          <div class="panel">
            <div class="hd"><h3>Actions</h3></div>
            <div class="bd">
              <div class="liveclock num"><span id="attClock"><?php echo date('g:i:s'); ?></span><small id="attAmPm"><?php echo date('A'); ?></small></div>
              <div class="hint" style="margin-top:6px"><?php echo date('l, j F Y'); ?></div>
              <div class="actlinks">
                <a href="#" data-attn-me onclick="document.querySelector('button[data-s=dash]').click(); return false;"><svg class="i" style="width:16px;height:16px"><use href="#i-clock"/></svg>Web clock-in</a>
                <a href="#" data-attn-me id="btnOpenCorrection"><svg class="i" style="width:16px;height:16px"><use href="#i-check"/></svg>Request correction</a>
                <a href="#" id="btnOpenAttnPolicy"><svg class="i" style="width:16px;height:16px"><use href="#i-report"/></svg>Attendance policy</a>
              </div>
            </div>
          </div>
        </div>

        <div>
          <div class="sec"><h2>Logs and requests</h2><div class="grow"></div><label style="display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--muted)"><span>24-hour format</span><span class="switch"><input type="checkbox" id="chkAttn24h"><i></i></span></label></div>
          <div class="panel">
            <div class="tabs"><button class="on" data-attn-tab="log">Attendance log</button><button data-attn-tab="calendar">Calendar</button><button data-attn-tab="requests">Requests</button></div>
            <div class="hd filters" id="attnFilters" hidden>
              <select class="btn xs" id="selectAttnTeamFilter" aria-label="Team" hidden></select>
              <select class="btn xs" id="selectAttnEmployeeFilter" aria-label="Employee"></select>
              <label class="datef" data-attn-dates>From <input class="btn xs" type="date" id="attnFrom"></label>
              <label class="datef" data-attn-dates>To <input class="btn xs" type="date" id="attnTo"></label>
              <button class="btn xs quiet" id="btnAttnClearFilters">Clear</button>
            </div>
            <div class="hd" style="border-bottom:1px solid var(--line);flex-wrap:wrap;gap:10px">
              <h3 id="attnRangeTitle">Last 30 days</h3>
              <div class="grow"></div>
              <div class="chips" id="attnMonthChips"></div>
              <div class="rowbtns" id="attnCalNav" hidden>
                <button class="btn xs" id="btnAttnCalPrev">Previous</button>
                <button class="btn xs" id="btnAttnCalNext">Next</button>
              </div>
            </div>

            <div id="attnPaneLog">
              <div class="tblwrap"><table class="tbl">
                <thead><tr><th id="thAttnEmp" hidden>Employee</th><th>Date</th><th>Attendance visual</th><th class="r">Effective hours</th><th class="r">Break</th><th class="r">Gross hours</th><th>Arrival</th><th class="r">Log</th></tr></thead>
                <tbody id="attLogTableBody">
                  <!-- Dynamically populated -->
                </tbody>
              </table></div>
              <div class="hd pager" id="attnLogPager" hidden></div>
            </div>

            <div id="attnPaneCalendar" hidden>
              <div class="calwrap">
                <div class="calhead"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
                <div class="calgrid" id="attnCalGrid"></div>
                <div class="callegend">
                  <span><i style="background:var(--accent)"></i>Present</span>
                  <span><i style="background:var(--warn-soft);border:1px solid var(--warn)"></i>Leave</span>
                  <span><i style="background:var(--ok-soft);border:1px solid var(--ok)"></i>Holiday</span>
                  <span><i style="background:var(--surface-3)"></i>Weekly off</span>
                  <span><i style="background:var(--crit-soft);border:1px solid var(--crit)"></i>Absent</span>
                  <span class="grow"></span>
                  <span id="attnCalSummary"></span>
                </div>
              </div>
            </div>

            <div id="attnPaneRequests" hidden>
              <div class="tblwrap"><table class="tbl">
                <thead><tr><th id="thCorrEmp" hidden>Employee</th><th>Date</th><th>Clock in</th><th>Clock out</th><th>Reason</th><th>Status</th><th>Actioned by</th><th></th></tr></thead>
                <tbody id="attnRequestsBody"></tbody>
              </table></div>
              <div class="hd pager" id="attnReqPager" hidden></div>
            </div>
          </div>
        </div>
      </section>

      <!-- ==================== LEAVE ==================== -->
      <section class="screen stack" data-screen="leave" hidden>
        <div class="panel" id="leaveFilters" hidden>
          <div class="hd filters" style="border-bottom:0">
            <select class="btn xs" id="selectLeaveTeamFilter" aria-label="Team" hidden></select>
            <select class="btn xs" id="selectLeaveEmployeeFilter" aria-label="Employee"></select>
            <label class="datef">From <input class="btn xs" type="date" id="leaveFrom"></label>
            <label class="datef">To <input class="btn xs" type="date" id="leaveTo"></label>
            <button class="btn xs quiet" id="btnLeaveClearFilters">Clear</button>
          </div>
        </div>

        <div class="cols main-side" style="grid-template-columns:minmax(0,1fr) 300px">
          <div class="panel">
            <div class="hd"><h3>Pending requests</h3><div class="grow"></div><select class="btn xs" id="selectLeavePendingYear"></select></div>
            <div class="tblwrap"><table class="tbl">
              <thead><tr><th id="thPendEmp" hidden>Employee</th><th>Dates</th><th>Type</th><th class="r">Days</th><th>Approver</th><th>Status</th><th></th></tr></thead>
              <tbody id="pendingLeaveTableBody"></tbody>
            </table></div>
          </div>
          <div class="panel"><div class="bd" style="display:flex;flex-direction:column;gap:10px">
            <button class="btn primary block" id="openLeave" data-leave-me>Request leave</button>
            <a class="qa link" href="#" id="linkOpenCompOff" data-leave-me style="font-size:13px;color:var(--accent);font-weight:600;text-decoration:none">Request comp-off credit</a>
            <a href="#" id="linkOpenLeavePolicy" style="font-size:13px;color:var(--accent);font-weight:600;text-decoration:none">Leave policy</a>
            <a href="#" id="linkHolidayCalLeave" style="font-size:13px;color:var(--accent);font-weight:600;text-decoration:none">Holiday calendar <span class="current-year"></span></a>
          </div></div>
        </div>

        <!-- Request Leave Form -->
        <div class="panel" id="leaveForm" hidden>
          <div class="hd"><h3>New leave request</h3><div class="grow"></div><button class="btn xs quiet" id="closeLeave">Close</button></div>
          <div class="bd">
            <div class="cols c3" style="gap:14px">
              <div class="field"><label for="lt">Type</label><select id="lt"></select></div>
              <div class="field" style="margin:0"><label for="lf">From</label><input id="lf" type="date" value="<?php echo date('Y-m-d'); ?>"></div>
              <div class="field" style="margin:0"><label for="lto">To</label><input id="lto" type="date" value="<?php echo date('Y-m-d'); ?>"></div>
            </div>
            <div class="cols c3" style="gap:14px;margin-top:14px">
              <div class="field" style="margin:0"><label for="ld">Duration</label><select id="ld"><option value="full_day">Full day</option><option value="half_day">Half day</option><option value="short_leave">Short leave</option></select></div>
              <div class="field" style="margin:0;grid-column:span 2"><label for="lr">Note for your manager</label><input id="lr" placeholder="Short reason"></div>
            </div>
            <div class="hint" id="leaveBalanceHint" style="margin:14px 0 16px">Balance will be verified upon submission. Request routes to your manager.</div>
            <div class="rowbtns">
              <button class="btn primary" id="btnSubmitLeave">Submit request</button>
              <button class="btn" id="btnDraftLeave">Save draft</button>
            </div>
          </div>
        </div>

        <!-- My leave stats -->
        <div id="leaveStatsBlock">
          <div class="sec"><h2 id="leaveStatsTitle">My leave stats</h2></div>
          <div class="cols" style="grid-template-columns:1fr 1fr 1.6fr">
            <div class="panel"><div class="hd"><h3>Weekly pattern</h3></div><div class="bd">
              <svg class="chart" viewBox="0 0 280 110" id="leaveWeeklyChart"><line class="grid" x1="10" x2="270" y1="86" y2="86"/>
                <rect class="bar empty" id="leaveBarMon" x="18" y="86" width="26" height="0" rx="3"/><text x="31" y="102" text-anchor="middle">Mon</text>
                <rect class="bar empty" id="leaveBarTue" x="56" y="86" width="26" height="0" rx="3"/><text x="69" y="102" text-anchor="middle">Tue</text>
                <rect class="bar empty" id="leaveBarWed" x="94" y="86" width="26" height="0" rx="3"/><text x="107" y="102" text-anchor="middle">Wed</text>
                <rect class="bar empty" id="leaveBarThu" x="132" y="86" width="26" height="0" rx="3"/><text x="145" y="102" text-anchor="middle">Thu</text>
                <rect class="bar empty" id="leaveBarFri" x="170" y="86" width="26" height="0" rx="3"/><text x="183" y="102" text-anchor="middle">Fri</text>
                <rect class="bar empty" id="leaveBarSat" x="208" y="86" width="26" height="0" rx="2"/><text x="221" y="102" text-anchor="middle">Sat</text>
                <rect class="bar empty" id="leaveBarSun" x="246" y="86" width="22" height="0" rx="2"/><text x="257" y="102" text-anchor="middle">Sun</text>
              </svg><div class="hint" id="leaveWeeklyHint" style="margin-top:6px"></div></div></div>
            <div class="panel"><div class="hd"><h3>Leave by type</h3></div><div class="bd" style="display:flex;align-items:center;gap:18px">
              <svg viewBox="0 0 100 100" style="width:96px;height:96px;flex:0 0 auto;transform:rotate(-90deg)" id="leaveTypeDonut"><circle cx="50" cy="50" r="38" fill="none" stroke="var(--surface-3)" stroke-width="12"/><circle id="donutCircleCasual" cx="50" cy="50" r="38" fill="none" stroke="var(--accent)" stroke-width="12" stroke-dasharray="0 239"/><circle id="donutCircleEarned" cx="50" cy="50" r="38" fill="none" stroke="var(--ok)" stroke-width="12" stroke-dasharray="0 239"/><circle id="donutCircleSick" cx="50" cy="50" r="38" fill="none" stroke="var(--warn)" stroke-width="12" stroke-dasharray="0 239"/></svg>
              <div style="font-size:13px;display:flex;flex-direction:column;gap:6px"><div><i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:var(--accent);margin-right:8px"></i>Casual <b class="num" id="leaveStatTypeCasual">0</b></div><div><i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:var(--ok);margin-right:8px"></i>Earned <b class="num" id="leaveStatTypeEarned">0</b></div><div><i style="display:inline-block;width:8px;height:8px;border-radius:2px;background:var(--warn);margin-right:8px"></i>Sick <b class="num" id="leaveStatTypeSick">0</b></div></div>
            </div></div>
            <div class="panel"><div class="hd"><h3>Monthly</h3><div class="grow"></div><span class="meta" id="leaveYearlyMeta">0 days</span></div><div class="bd">
              <svg class="chart" viewBox="0 0 480 110" id="leaveMonthlyChart"><line class="grid" x1="10" x2="470" y1="86" y2="86"/>
                <rect class="bar empty" id="mbar-1" x="18" y="86" width="24" height="0" rx="2"/><text x="30" y="102" text-anchor="middle">Jan</text>
                <rect class="bar empty" id="mbar-2" x="56" y="86" width="24" height="0" rx="3"/><text x="68" y="102" text-anchor="middle">Feb</text>
                <rect class="bar empty" id="mbar-3" x="94" y="86" width="24" height="0" rx="2"/><text x="106" y="102" text-anchor="middle">Mar</text>
                <rect class="bar empty" id="mbar-4" x="132" y="86" width="24" height="0" rx="2"/><text x="144" y="102" text-anchor="middle">Apr</text>
                <rect class="bar empty" id="mbar-5" x="170" y="86" width="24" height="0" rx="3"/><text x="182" y="102" text-anchor="middle">May</text>
                <rect class="bar empty" id="mbar-6" x="208" y="86" width="24" height="0" rx="3"/><text x="220" y="102" text-anchor="middle">Jun</text>
                <rect class="bar empty" id="mbar-7" x="246" y="86" width="24" height="0" rx="3"/><text x="258" y="102" text-anchor="middle">Jul</text>
                <rect class="bar empty" id="mbar-8" x="284" y="86" width="24" height="0" rx="3"/><text x="296" y="102" text-anchor="middle">Aug</text>
                <rect class="bar empty" id="mbar-9" x="322" y="86" width="24" height="0" rx="3"/><text x="334" y="102" text-anchor="middle">Sep</text>
                <rect class="bar empty" id="mbar-10" x="360" y="86" width="24" height="0" rx="3"/><text x="372" y="102" text-anchor="middle">Oct</text>
                <rect class="bar empty" id="mbar-11" x="398" y="86" width="24" height="0" rx="3"/><text x="410" y="102" text-anchor="middle">Nov</text>
                <rect class="bar empty" id="mbar-12" x="436" y="86" width="24" height="0" rx="2"/><text x="448" y="102" text-anchor="middle">Dec</text>
              </svg></div></div>
          </div>
        </div>

        <!-- Donut Balance Cards (Generated dynamically from real leave types) -->
        <div id="leaveBalanceBlock">
          <div class="sec"><h2 id="leaveBalanceTitle">Leave balances</h2><span class="meta">Year <span id="leaveBalanceYear" class="current-year"></span></span></div>
          <div class="cols c3" id="leaveBalanceCardsContainer">
            <!-- Dynamically populated from /api/leave/balances -->
          </div>
        </div>

        <!-- Leave History -->
        <div class="panel" id="leaveHistoryPanel">
          <div class="hd" style="flex-wrap:wrap;gap:10px">
            <h3 id="leaveHistoryTitle">Leave history</h3>
            <span class="meta" id="leaveHistoryCount"></span>
            <div class="grow"></div>
            <div class="rowbtns" style="flex-wrap:wrap">
              <select class="btn xs" id="selectLeaveHistoryType" aria-label="Leave type"><option value="all">All types</option></select>
              <select class="btn xs" id="selectLeaveHistoryStatus" aria-label="Status">
                <option value="any">Any status</option>
                <option value="approved">Approved</option>
                <option value="pending">Pending</option>
                <option value="rejected">Rejected</option>
                <option value="withdrawn">Withdrawn</option>
              </select>
              <input class="btn xs" id="leaveHistorySearch" type="search" placeholder="Search" aria-label="Search leave history" style="width:160px;font-weight:500">
            </div>
          </div>
          <div class="tblwrap"><table class="tbl">
            <thead><tr><th id="thHistEmp" hidden>Employee</th><th>Dates</th><th>Type</th><th>Status</th><th>Approved by</th><th>Actioned on</th><th>Note</th><th></th></tr></thead>
            <tbody id="leaveHistoryTableBody">
              <!-- Dynamically populated -->
            </tbody>
          </table></div>
          <div class="hd pager" id="leaveHistoryPager"></div>
        </div>
      </section>

      <!-- ==================== COMMUNITY ==================== -->
      <section class="screen" data-screen="hub" hidden>
        <div class="cols main-side">
          <div class="stack" style="gap:14px">
            <div class="composer open" id="mainComposerHub">
              <div class="ctabs">
                <button class="on" data-tab="post"><svg class="i"><use href="#i-comment"/></svg>Post</button>
                <button data-tab="poll"><svg class="i"><use href="#i-report"/></svg>Poll</button>
                <button data-tab="praise"><svg class="i"><use href="#i-heart"/></svg>Praise</button>
              </div>
              <div class="praise-picker-row" id="praisePickerRowHub" style="display:none;padding:8px 12px;background:var(--accent-soft);border-radius:6px;margin:8px 0;align-items:center;gap:8px">
                <span style="font-size:13px;font-weight:600;color:var(--accent)">Praise teammate:</span>
                <select class="praise-select btn xs" id="praiseTeammateHub" style="flex:1">
                  <option value="">Select teammate to praise...</option>
                </select>
              </div>

              <!-- Dedicated Poll Creation UI -->
              <div id="composerPollFields" style="display:none;padding:8px 0 4px">
                <div style="margin-bottom:10px">
                  <label style="display:block;font-size:12px;font-weight:700;color:var(--ink-secondary);margin-bottom:4px;text-transform:uppercase;letter-spacing:0.04em">Poll Question</label>
                  <input type="text" id="pollQuestionInput" placeholder="Ask a question for your team..." style="width:100%;box-sizing:border-box;padding:9px 12px;font-size:14px;border:1px solid var(--line);border-radius:6px;background:var(--surface)">
                </div>
                <label style="display:block;font-size:12px;font-weight:700;color:var(--ink-secondary);margin-bottom:6px;text-transform:uppercase;letter-spacing:0.04em">Poll Options</label>
                <div id="pollOptionsList" style="display:flex;flex-direction:column;gap:8px">
                  <div class="poll-opt-row" style="display:flex;align-items:center;gap:8px">
                    <input type="text" class="poll-opt-input" placeholder="Option 1" style="flex:1;padding:8px 12px;font-size:13.5px;border:1px solid var(--line);border-radius:6px;background:var(--surface)">
                  </div>
                  <div class="poll-opt-row" style="display:flex;align-items:center;gap:8px">
                    <input type="text" class="poll-opt-input" placeholder="Option 2" style="flex:1;padding:8px 12px;font-size:13.5px;border:1px solid var(--line);border-radius:6px;background:var(--surface)">
                  </div>
                </div>
                <div style="margin-top:10px;display:flex;align-items:center;gap:8px">
                  <button type="button" class="btn xs quiet" id="btnAddPollOption" style="display:inline-flex;align-items:center;gap:4px">
                    <svg class="i" style="width:13px;height:13px"><use href="#i-plus"/></svg>Add option
                  </button>
                  <span style="font-size:12px;color:var(--muted)">(Up to 6 options)</span>
                </div>
              </div>

              <textarea id="composer" placeholder="Write your post and mention your teammates"></textarea>
              <div class="preview" hidden><img alt=""><button type="button" class="rm" aria-label="Remove photo">&times;</button></div>
              <div class="foot">
                <div class="rowbtns"><button type="button" class="btn quiet sm addphoto"><svg class="i" style="width:16px;height:16px"><use href="#i-camera"/></svg>Add photo</button><input type="file" accept="image/*" hidden></div>
                <div class="rowbtns"><span class="hint">Visible to everyone</span><button type="button" class="btn primary sm dopost">Post</button></div>
              </div>
            </div>

            <div id="communityPostsContainer">
              <!-- Dynamically populated -->
            </div>
          </div>

          <div class="stack" style="gap:14px">
            <div class="panel">
              <div class="hd">
                <h3>Upcoming</h3>
                <div class="grow"></div>
                <button class="btn xs" id="btnOpenAddEvent" data-admin hidden style="padding:2px 8px">+ Event</button>
              </div>
              <div class="bd" id="upcomingEventsList" style="padding-block:4px">
                <!-- Dynamically populated from /api/events -->
              </div>
            </div>

            <div class="panel" id="sidebarPollPanel">
              <div class="hd">
                <h3>Poll</h3>
                <div class="grow"></div>
                <span class="meta" id="sidebarPollMeta">0 votes</span>
              </div>
              <div class="bd">
                <div style="font-weight:600;margin-bottom:10px" id="sidebarPollQuestion">Community Poll</div>
                <div class="poll" id="sidebarPollOptions">
                  <!-- Dynamically populated from /api/community/active-poll -->
                </div>
                <div id="sidebarPollEmpty" style="display:none;color:var(--muted);font-size:13px;text-align:center;padding:12px 0">
                  No active poll right now. Create one using the Poll tab!
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ==================== MY FINANCES (All Roles) ==================== -->
      <section class="screen stack" data-screen="fin" hidden>
        <div class="stats">
          <div><div class="k">Net pay, <span id="finNetMonth">latest</span></div><div class="v num" id="finNetPay">₹0</div><div class="d" id="finNetStatus">Published</div></div>
          <div><div class="k">Gross</div><div class="v num" id="finGross">₹0</div><div class="d">Monthly earnings share</div></div>
          <div><div class="k">Deductions</div><div class="v num" id="finDeductions">₹0</div><div class="d">Statutory and deductions</div></div>
          <div><div class="k">Next payday</div><div class="v num" id="finNextPayday">--</div><div class="d" id="finPaydayRemaining">-- days</div></div>
        </div>
        <div class="cols main-side">
          <div class="panel">
            <div class="hd">
              <h3 id="finBreakdownTitle">Month breakdown</h3>
              <div class="grow"></div>
              <div class="rowbtns">
                <select id="finMonthSelect" class="btn xs" style="margin-right:6px">
                  <option value="">Select month...</option>
                </select>
                <button class="btn sm" id="finBtnDownloadPdf" disabled><svg class="i" style="width:15px;height:15px"><use href="#i-download"/></svg>Payslip PDF</button>
              </div>
            </div>
            <div class="tblwrap"><table class="tbl">
              <thead><tr><th>Earnings</th><th class="r">Amount</th></tr></thead>
              <tbody id="finEarningsBody">
                <tr><td colspan="2" class="empty-state">No payslip data available.</td></tr>
              </tbody>
              <thead><tr><th>Deductions</th><th class="r">Amount</th></tr></thead>
              <tbody id="finDeductionsBody">
                <tr><td colspan="2" class="empty-state">No deductions data.</td></tr>
              </tbody>
            </table></div>
          </div>
          <div class="stack" style="gap:14px">
            <div class="panel">
              <div class="hd">
                <h3>Payslips</h3>
                <div class="grow"></div>
                <select class="btn xs" id="finFySelect">
                  <option value="">Select FY</option>
                </select>
              </div>
              <div class="tblwrap"><table class="tbl">
                <tbody id="finPayslipsListBody">
                  <tr><td colspan="3" class="empty-state">No payslips found for this financial year.</td></tr>
                </tbody>
              </table></div>
            </div>
            <div class="panel">
              <div class="hd">
                <h3>Tax</h3>
                <div class="grow"></div>
                <span class="pill accent plain">Estimate</span>
              </div>
              <div class="bd" style="padding-block:4px">
                <div class="setting">
                  <div>
                    <div class="t"><span id="finTaxTdsLabel">TDS</span> deducted so far</div>
                    <div class="m" id="finTaxMonthsLabel">Completed months in FY</div>
                  </div>
                  <b class="num" id="finTaxDeducted">₹0</b>
                </div>
                <div class="setting">
                  <div>
                    <div class="t">Projected tax for FY</div>
                    <div class="m">Based on current monthly salary</div>
                  </div>
                  <b class="num" id="finTaxProjected">₹0</b>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- ==================== LIVE BOARD (Manager/Admin) ==================== -->
      <section class="screen stack" data-screen="board" hidden>
        <div class="stats" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr))">
          <div><div class="k"><span class="pill ok plain" style="padding:0;background:none">Working</span></div><div class="v num" id="boardWorkingCount">0</div><div class="d">active now</div></div>
          <div><div class="k"><span class="pill neutral plain" style="padding:0;background:none">Idle</span></div><div class="v num" id="boardIdleCount">0</div><div class="d">tab open, 5m+ idle</div></div>
          <div><div class="k"><span class="pill warn plain" style="padding:0;background:none">On break</span></div><div class="v num" id="boardBreakCount">0</div><div class="d">away</div></div>
          <div><div class="k"><span class="pill crit plain" style="padding:0;background:none">Offline</span></div><div class="v num" id="boardOfflineCount">0</div><div class="d">tab closed / disconnected</div></div>
          <div><div class="k"><span class="pill crit plain" style="padding:0;background:none">Not in</span></div><div class="v num" id="boardNotInCount">0</div><div class="d" id="boardNotInSub">un-clocked or on leave</div></div>
        </div>
        <div>
          <div class="sec"><h2>Team right now</h2><div class="grow"></div><span class="meta">Updates every 30 seconds</span></div>
          <div class="board" id="liveBoardCards">
            <!-- Dynamically populated -->
          </div>
        </div>
      </section>

      <!-- ==================== APPROVALS (Manager/Admin) ==================== -->
      <section class="screen stack" data-screen="approve" hidden>
        <div class="panel">
          <div class="hd"><h3>Leave requests</h3><div class="grow"></div><span class="pill warn">Action pending</span></div>
          <div class="tblwrap"><table class="tbl">
            <thead><tr><th>Employee</th><th>Type</th><th>Dates</th><th class="r">Days</th><th class="r">Balance after</th><th>Reason</th><th></th></tr></thead>
            <tbody id="leaveApprovalsBody">
              <!-- Dynamically populated -->
            </tbody>
          </table></div>
        </div>

        <div class="panel" style="margin-top:14px">
          <div class="hd"><h3>Comp-off credit requests</h3><div class="grow"></div><span class="pill warn">Action pending</span></div>
          <div class="tblwrap"><table class="tbl">
            <thead><tr><th>Employee</th><th>Worked Date</th><th class="r">Hours</th><th>Reason</th><th></th></tr></thead>
            <tbody id="compOffApprovalsBody">
              <!-- Dynamically populated -->
            </tbody>
          </table></div>
        </div>
      </section>

      <!-- ==================== REPORTS (Manager/Admin) ==================== -->
      <section class="screen stack" data-screen="reports" hidden>
        <div class="stats">
          <div><div class="k">Team hours</div><div class="v num" id="repTeamHours">0h</div><div class="d">this week</div></div>
          <div><div class="k">Average per person</div><div class="v num" id="repAvgPerson">0h 00m</div><div class="d">per day</div></div>
          <div><div class="k">Attendance</div><div class="v num" id="repAttendanceRate">0%</div><div class="d" id="repAttendanceSub">this week</div></div>
          <div><div class="k">Late marks</div><div class="v num" id="repLateMarks">0</div><div class="d">team total</div></div>
        </div>
        <div class="panel">
          <div class="hd">
            <h3>Work hours</h3>
            <div class="grow"></div>
            <div class="rowbtns">
              <select class="btn sm" id="selectReportPeriod" style="padding-right:28px">
                <option value="this_week">This week</option>
                <option value="this_month">This month</option>
                <option value="last_month">Last month</option>
              </select>
              <button class="btn sm" id="btnExportCsv"><svg class="i" style="width:15px;height:15px"><use href="#i-download"/></svg>CSV</button>
              <button class="btn sm primary" id="btnExportPdf"><svg class="i" style="width:15px;height:15px"><use href="#i-download"/></svg>PDF</button>
            </div>
          </div>
          <div class="tblwrap"><table class="tbl">
            <thead><tr><th>Employee</th><th class="r">Days</th><th class="r">Worked</th><th class="r">Avg per day</th><th class="r">Breaks</th><th class="r">Late</th><th class="r">Overtime</th></tr></thead>
            <tbody id="reportsTableBody">
              <!-- Dynamically populated -->
            </tbody>
          </table></div>
        </div>
      </section>

      <!-- ==================== PAYROLL (Admin Only) ==================== -->
      <section class="screen stack" data-screen="payroll" hidden>
        <!-- Top Sub Navigation Tabs -->
        <div class="tabs" style="margin-bottom:12px;display:flex;gap:4px">
          <button class="btn sm on" id="payrollSubTabRun">Run payroll</button>
          <button class="btn sm" id="payrollSubTabComponents">Components</button>
          <button class="btn sm" id="payrollSubTabStructures">Structures</button>
          <button class="btn sm" id="payrollSubTabSettings">Payroll settings</button>
        </div>

        <!-- 1. Run Payroll Tab Pane -->
        <div id="payrollPaneRun" class="stack" style="gap:14px">
          <div class="stats">
            <div><div class="k">Month payroll</div><div class="v num" id="payrollStatNet">₹0</div><div class="d" id="payrollStatEmployees">0 employees</div></div>
            <div><div class="k">Status</div><div class="v"><span class="pill warn" id="payrollStatStatus">Draft</span></div><div class="d" id="payrollStatPayday">Payday: --</div></div>
            <div><div class="k">Deductions</div><div class="v num" id="payrollStatDeductions">₹0</div><div class="d">Statutory and deductions</div></div>
            <div><div class="k">Leave without pay</div><div class="v num" id="payrollStatLop">0</div><div class="d">days across team</div></div>
          </div>

          <div class="panel">
            <div class="hd">
              <h3 id="payrollMonthTitle">Payroll Run</h3>
              <div class="grow"></div>
              <div class="rowbtns" style="gap:6px">
                <select id="payrollMonthPicker" class="btn sm">
                  <!-- Dynamically populated -->
                </select>
                <button class="btn sm" id="payrollBtnGenerate" title="Generate or re-run draft payroll">Run payroll</button>
                <button class="btn sm" id="payrollBtnFinalize" title="Lock run and publish payslips">Finalize</button>
                <button class="btn sm" id="payrollBtnPaid" title="Mark payslips as paid">Mark as paid</button>
                <button class="btn sm" id="payrollBtnReopen" title="Reopen finalized run back to draft" style="display:none">Reopen</button>
                <button class="btn sm" id="payrollBtnBankCsv" title="Export bank transfer CSV"><svg class="i" style="width:15px;height:15px"><use href="#i-download"/></svg>Bank file</button>
                <button class="btn sm" id="payrollBtnSummaryCsv" title="Export payroll summary CSV"><svg class="i" style="width:15px;height:15px"><use href="#i-download"/></svg>Summary</button>
                <button class="btn sm" id="payrollBtnZip" title="Download ZIP of all payslips"><svg class="i" style="width:15px;height:15px"><use href="#i-download"/></svg>ZIP PDFs</button>
              </div>
            </div>
            <div class="tblwrap"><table class="tbl">
              <thead>
                <tr>
                  <th>Employee</th>
                  <th class="r">Paid days</th>
                  <th class="r">LOP</th>
                  <th class="r">Gross</th>
                  <th class="r">Deductions</th>
                  <th class="r">Net pay</th>
                  <th>Status</th>
                  <th class="r">Actions</th>
                </tr>
              </thead>
              <tbody id="payrollTableBody">
                <tr><td colspan="8" class="empty-state">No payroll records for this month. Click Run payroll to generate.</td></tr>
              </tbody>
            </table></div>
          </div>
        </div>

        <!-- 2. Components Tab Pane -->
        <div id="payrollPaneComponents" class="stack" style="gap:14px;display:none">
          <div class="panel">
            <div class="hd">
              <h3>Salary Components</h3>
              <div class="grow"></div>
              <button class="btn sm primary" id="payrollBtnAddComponent"><svg class="i" style="width:15px;height:15px"><use href="#i-plus"/></svg>Add component</button>
            </div>
            <div class="tblwrap"><table class="tbl">
              <thead>
                <tr>
                  <th>Sort</th>
                  <th>Name</th>
                  <th>Type</th>
                  <th>Calculation</th>
                  <th class="r">Default value</th>
                  <th>Statutory</th>
                  <th>On Payslip</th>
                  <th>Status</th>
                  <th class="r">Actions</th>
                </tr>
              </thead>
              <tbody id="payrollComponentsTableBody">
                <!-- Dynamically populated -->
              </tbody>
            </table></div>
          </div>
        </div>

        <!-- 3. Structures Tab Pane -->
        <div id="payrollPaneStructures" class="stack" style="gap:14px;display:none">
          <div class="panel">
            <div class="hd">
              <h3>Employee Salary Structures</h3>
              <div class="grow"></div>
              <div class="rowbtns">
                <button class="btn sm" id="payrollBtnBulkStructure" disabled>Bulk set structure (<span id="bulkSelectedCount">0</span>)</button>
              </div>
            </div>
            <div class="tblwrap"><table class="tbl">
              <thead>
                <tr>
                  <th style="width:36px"><input type="checkbox" id="payrollBulkSelectAll"></th>
                  <th>Employee</th>
                  <th>Department / Team</th>
                  <th class="r">Monthly Gross</th>
                  <th class="r">Annual CTC</th>
                  <th>Effective From</th>
                  <th class="r">Actions</th>
                </tr>
              </thead>
              <tbody id="payrollStructuresTableBody">
                <!-- Dynamically populated -->
              </tbody>
            </table></div>
          </div>
        </div>

        <!-- 4. Payroll Settings Tab Pane -->
        <div id="payrollPaneSettings" class="stack" style="gap:14px;display:none">
          <div class="panel">
            <div class="hd">
              <h3>Payroll Configuration</h3>
              <div class="grow"></div>
              <button class="btn sm primary" id="payrollBtnSaveSettings">Save settings</button>
            </div>
            <div class="bd" style="padding:16px">
              <form id="payrollSettingsForm" class="stack" style="gap:16px">
                <div class="cols c2" style="gap:16px">
                  <div class="field">
                    <label>Company Legal Name</label>
                    <input type="text" id="pset_company_legal_name" class="input" placeholder="Legal registered entity name">
                  </div>
                  <div class="field">
                    <label>Currency Symbol</label>
                    <input type="text" id="pset_currency_symbol" class="input" placeholder="₹ or INR">
                  </div>
                </div>

                <div class="field">
                  <label>Company Registered Address</label>
                  <textarea id="pset_company_address" class="input" rows="2" placeholder="Full address for payslips"></textarea>
                </div>

                <div class="cols c2" style="gap:16px">
                  <div class="field">
                    <label>Custom Payslip Logo (URL or data URI)</label>
                    <input type="text" id="pset_payroll_logo" class="input" placeholder="https://... or data:image/png;base64,...">
                  </div>
                  <div class="field">
                    <label>Payslip Footer Disclaimer</label>
                    <input type="text" id="pset_payslip_footer_text" class="input" placeholder="Disclaimer text printed at bottom">
                  </div>
                </div>

                <div class="cols c3" style="gap:16px">
                  <div class="field">
                    <label>Payday (Day of month 1-31)</label>
                    <input type="number" id="pset_payroll_payday" min="1" max="31" class="input">
                  </div>
                  <div class="field">
                    <label>Financial Year Start Month</label>
                    <select id="pset_financial_year_start_month" class="input">
                      <option value="1">January</option>
                      <option value="4">April (Standard India)</option>
                    </select>
                  </div>
                  <div class="field">
                    <label>Payslip Visibility to Employees</label>
                    <select id="pset_payslip_visibility" class="input">
                      <option value="on_finalize">On Finalize</option>
                      <option value="on_paid">On Paid</option>
                    </select>
                  </div>
                </div>

                <div class="cols c3" style="gap:16px">
                  <div class="field">
                    <label>LOP Daily Divisor</label>
                    <select id="pset_lop_divisor" class="input">
                      <option value="calendar_days">Calendar Days in Month</option>
                      <option value="working_days">Working Days in Month</option>
                    </select>
                  </div>
                  <div class="field">
                    <label>Payroll Rounding</label>
                    <select id="pset_payroll_rounding" class="input">
                      <option value="nearest_rupee">Nearest Rupee</option>
                      <option value="none">None (Exact 2 Decimals)</option>
                    </select>
                  </div>
                  <div class="field">
                    <label>TDS Display Label</label>
                    <input type="text" id="pset_tds_label" class="input" placeholder="TDS">
                  </div>
                </div>

                <div class="sec" style="margin-top:10px"><h2>Statutory Deductions Rules</h2></div>

                <div class="cols c3" style="gap:16px">
                  <div class="field">
                    <label>PF Rate (%)</label>
                    <input type="number" step="0.01" id="pset_pf_rate" class="input" placeholder="12.0">
                  </div>
                  <div class="field">
                    <label>PF Wage Ceiling Enabled</label>
                    <select id="pset_pf_wage_ceiling_enabled" class="input">
                      <option value="1">Enabled (Cap to Ceiling)</option>
                      <option value="0">Disabled (Calculate on full Basic)</option>
                    </select>
                  </div>
                  <div class="field">
                    <label>PF Wage Ceiling (₹)</label>
                    <input type="number" id="pset_pf_wage_ceiling" class="input" placeholder="15000">
                  </div>
                </div>

                <div class="cols c3" style="gap:16px">
                  <div class="field">
                    <label>ESI Rate (%)</label>
                    <input type="number" step="0.01" id="pset_esi_rate" class="input" placeholder="0.75">
                  </div>
                  <div class="field">
                    <label>ESI Gross Threshold (₹)</label>
                    <input type="number" id="pset_esi_gross_threshold" class="input" placeholder="21000">
                  </div>
                  <div class="field">
                    <label>Default Professional Tax (₹)</label>
                    <input type="number" id="pset_default_professional_tax" class="input" placeholder="200">
                  </div>
                </div>
              </form>
            </div>
          </div>
        </div>
      </section>

      <!-- ==================== EMPLOYEES (Admin) ==================== -->
      <section class="screen stack" data-screen="people" hidden>
        <div class="panel">
          <div class="hd">
            <h3>Employees</h3>
            <span class="meta" id="employeeActiveCount">--</span>
            <div class="grow"></div>
            <div class="rowbtns">
              <button class="btn sm" id="btnOpenCsvImport"><svg class="i" style="width:15px;height:15px"><use href="#i-report"/></svg>Import CSV</button>
              <a href="<?php echo htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8'); ?>/add.php" class="btn sm primary" id="btnOpenAddEmp" style="text-decoration:none"><svg class="i" style="width:15px;height:15px"><use href="#i-plus"/></svg>Add employee</a>
            </div>
          </div>
          <div class="tblwrap"><table class="tbl">
            <thead><tr><th>Name</th><th>Role</th><th>Team</th><th>Manager</th><th>Joined</th><th>Status</th><th class="r">Actions</th></tr></thead>
            <tbody id="employeesTableBody">
              <!-- Dynamically populated -->
            </tbody>
          </table></div>
        </div>
      </section>

      <!-- ==================== TEAMS (Admin) ==================== -->
      <section class="screen stack" data-screen="teams" hidden>
        <div class="panel">
          <div class="hd">
            <h3>Teams</h3>
            <span class="meta" id="teamsCountMeta">Active departments & squads</span>
            <div class="grow"></div>
            <a href="<?php echo htmlspecialchars($basePath, ENT_QUOTES, 'UTF-8'); ?>/add.php?type=team" class="btn sm primary" id="btnOpenAddTeam" style="text-decoration:none"><svg class="i" style="width:15px;height:15px"><use href="#i-plus"/></svg>Add team</a>
          </div>
          <div class="tblwrap"><table class="tbl">
            <thead><tr><th>Team</th><th>Manager</th><th class="r">Members</th><th class="r">Actions</th></tr></thead>
            <tbody id="teamsTableBody">
              <!-- Dynamically populated from /api/teams -->
            </tbody>
          </table></div>
        </div>
      </section>

      <!-- ==================== SETTINGS & DEVICES ==================== -->
      <section class="screen stack" data-screen="settings" hidden>
        <div class="panel">
          <div class="hd">
            <h3>Active devices</h3>
            <span class="meta">Manage sessions signed in to your account</span>
            <div class="grow"></div>
            <button class="btn sm danger" id="btnSignOutOthers">Sign out other devices</button>
          </div>
          <div class="bd" style="padding-block:4px">
            <div id="deviceListContainer">
              <!-- Dynamically populated from /api/auth/devices -->
            </div>
          </div>
        </div>

        <div data-admin hidden>
          <div class="cols c2">
            <div class="panel">
              <div class="hd"><h3>Work rules</h3></div>
              <div class="bd" style="padding-block:4px">
                <div class="setting">
                  <div><div class="t">Shift start time</div><div class="m">Standard daily work shift begin time.</div></div>
                  <input type="time" class="btn sm" id="settShiftStart" style="padding:4px 8px">
                </div>
                <div class="setting">
                  <div><div class="t">Shift end time</div><div class="m">Standard daily work shift conclusion time.</div></div>
                  <input type="time" class="btn sm" id="settShiftEnd" style="padding:4px 8px">
                </div>
                <div class="setting">
                  <div><div class="t">Work week</div><div class="m">Days counted as working days.</div></div>
                  <select class="btn sm" id="settWorkWeek"><option value="Monday to Friday">Monday to Friday</option><option value="Monday to Saturday">Monday to Saturday</option></select>
                </div>
                <div class="setting">
                  <div><div class="t">Daily target</div><div class="m">Used for overtime and the hours chart.</div></div>
                  <select class="btn sm" id="settDailyTarget"><option value="9">9 hours</option><option value="8">8 hours</option></select>
                </div>
                <div class="setting">
                  <div><div class="t">Late grace period</div><div class="m">Clocking in within this window is not marked late.</div></div>
                  <select class="btn sm" id="settGraceMin"><option value="15">15 minutes</option><option value="10">10 minutes</option><option value="30">30 minutes</option></select>
                </div>
                <div class="setting">
                  <div><div class="t">Late fine</div><div class="m">Off: late marks are recorded but never deducted from pay.</div></div>
                  <label class="switch"><input type="checkbox" id="settLateFine"><i></i></label>
                </div>
                <div class="setting">
                  <div><div class="t">Auto clock out</div><div class="m">Close forgotten sessions at this time.</div></div>
                  <select class="btn sm" id="settAutoClockOut"><option value="23:00:00">11:00 PM</option><option value="22:00:00">10:00 PM</option><option value="23:59:59">Midnight</option></select>
                </div>
              </div>
            </div>

            <div class="panel">
              <div class="hd">
                <h3>Leave types & quotas</h3>
                <div class="grow"></div>
                <button class="btn xs primary" id="btnOpenAddLeaveType">+ Add type</button>
              </div>
              <div class="bd" style="padding-block:4px">
                <div class="tblwrap" style="margin-bottom:12px">
                  <table class="tbl" style="font-size:12.5px">
                    <thead><tr><th>Name</th><th>Code</th><th class="r">Quota</th><th>Status</th><th class="r">Action</th></tr></thead>
                    <tbody id="settingsLeaveTypesBody">
                      <!-- Dynamically populated from /api/leave-types/all -->
                    </tbody>
                  </table>
                </div>
                <div class="setting">
                  <div><div class="t">Manager approval required</div><div class="m">Every request waits for a manager before it counts.</div></div>
                  <label class="switch"><input type="checkbox" id="settMgrApproval"><i></i></label>
                </div>
                <div class="setting">
                  <div><div class="t">Carry forward unused leave</div><div class="m">Into the next year.</div></div>
                  <label class="switch"><input type="checkbox" id="settCarryForward"><i></i></label>
                </div>
              </div>
            </div>
          </div>

          <div class="panel" style="margin-top:14px">
            <div class="hd"><h3>Privacy and data</h3><div class="grow"></div><span class="meta">India DPDP Act</span></div>
            <div class="bd" style="padding-block:4px">
              <div class="setting">
                <div><div class="t">Selfie on each punch</div><div class="m">Confirms who clocked in. Visible to managers and HR only.</div></div>
                <label class="switch"><input type="checkbox" id="settSelfiePunch" checked><i></i></label>
              </div>
              <div class="setting">
                <div><div class="t">Location on each punch</div><div class="m">Recorded for the log. Never blocks a punch.</div></div>
                <label class="switch"><input type="checkbox" id="settLocationPunch" checked><i></i></label>
              </div>
              <div class="setting">
                <div><div class="t">Delete selfies after</div><div class="m">Photos are removed automatically after this period.</div></div>
                <select class="btn sm" id="settDeleteSelfieDays"><option value="90">90 days</option><option value="60">60 days</option><option value="180">180 days</option></select>
              </div>
              <div class="setting">
                <div><div class="t">Consent screen</div><div class="m">Every employee accepts the tracking policy before their first punch.</div></div>
                <span class="pill ok">Required</span>
              </div>
            </div>
          </div>

          <div style="display:flex;justify-content:flex-end;margin-top:10px">
            <button class="btn primary" id="btnSaveSettings">Save settings</button>
          </div>
        </div>
      </section>

      <!-- ==================== AUDIT LOG (Admin) ==================== -->
      <section class="screen stack" data-screen="audit" hidden>
        <div class="panel">
          <div class="hd">
            <h3>Audit log</h3>
            <span class="meta">Every change recorded with old and new values</span>
          </div>
          <div class="tblwrap"><table class="tbl">
            <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Target</th><th>Values (JSON)</th></tr></thead>
            <tbody id="auditTableBody">
              <!-- Dynamically populated -->
            </tbody>
          </table></div>
        </div>
      </section>

      <!-- ==================== ROLES & PERMISSIONS (Admin) ==================== -->
      <section class="screen stack" data-screen="roles" hidden>
        <!-- 1. ROLES LIST TABLE VIEW -->
        <div id="rolesListView" class="panel">
          <div class="hd" style="flex-wrap:wrap;gap:12px;align-items:center">
            <div>
              <h3>Roles & Permissions</h3>
              <span class="meta">Manage system access levels, create custom roles, and configure module permissions</span>
            </div>
            <div class="grow"></div>
            <button type="button" class="btn sm primary" id="btnOpenCreateRole">
              <svg class="i" style="width:14px;height:14px;margin-right:4px"><use href="#i-plus"/></svg>Create Role
            </button>
          </div>
          <div class="bd" style="padding:0">
            <div class="tblwrap">
              <table class="tbl">
                <thead>
                  <tr>
                    <th>Role Name</th>
                    <th>Description</th>
                    <th>Type</th>
                    <th class="r">Assigned Staff</th>
                    <th class="r">Permissions</th>
                    <th class="r" style="min-width:180px">Actions</th>
                  </tr>
                </thead>
                <tbody id="rolesTableBody">
                  <!-- Dynamically populated from /api/roles -->
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- 2. CREATE ROLE VIEW -->
        <div id="rolesCreateView" class="panel" style="display:none">
          <div class="hd" style="flex-wrap:wrap;gap:12px;align-items:center">
            <div style="display:flex;align-items:center;gap:12px">
              <button type="button" class="btn xs quiet btn-back-to-roles" title="Back to roles table">&larr; Back</button>
              <div>
                <h3>Create New Role</h3>
                <span class="meta">Define a custom role title and grant specific tab permissions</span>
              </div>
            </div>
            <div class="grow"></div>
            <div style="display:flex;gap:8px">
              <button type="button" class="btn sm quiet btn-back-to-roles">Cancel</button>
              <button type="button" class="btn sm primary" id="btnSubmitCreateRole">Create Role</button>
            </div>
          </div>
          <div class="bd" style="padding-top:16px">
            <div class="cols c2" style="margin-bottom:20px;gap:16px">
              <div class="form-group" style="margin-bottom:0">
                <label for="newRoleName" style="font-size:13px;font-weight:700">Role Name *</label>
                <input type="text" id="newRoleName" placeholder="e.g. HR Executive, Team Lead, Accountant" style="width:100%;padding:9px 12px;border:1px solid var(--line-2);border-radius:6px;background:var(--surface);color:var(--fg);font-size:13.5px">
              </div>
              <div class="form-group" style="margin-bottom:0">
                <label for="newRoleSlug" style="font-size:13px;font-weight:700">Role Identifier (Slug)</label>
                <input type="text" id="newRoleSlug" placeholder="Auto-generated from name if left blank" style="width:100%;padding:9px 12px;border:1px solid var(--line-2);border-radius:6px;background:var(--surface);color:var(--fg);font-size:13.5px">
              </div>
            </div>

            <div class="cols c2" style="margin-bottom:20px;gap:16px">
              <div class="form-group" style="margin-bottom:0">
                <label for="newRoleDesc" style="font-size:13px;font-weight:700">Description</label>
                <input type="text" id="newRoleDesc" placeholder="Brief summary of duties and responsibilities" style="width:100%;padding:9px 12px;border:1px solid var(--line-2);border-radius:6px;background:var(--surface);color:var(--fg);font-size:13.5px">
              </div>
              <div class="form-group" style="margin-bottom:0">
                <label for="newRoleTemplate" style="font-size:13px;font-weight:700">Quick Template Preset</label>
                <select id="newRoleTemplate" style="width:100%;padding:9px 12px;border:1px solid var(--line-2);border-radius:6px;background:var(--surface);color:var(--fg);font-size:13.5px">
                  <option value="scratch">Start with clean template (None selected)</option>
                  <option value="employee">Copy from Employee preset</option>
                  <option value="manager" selected>Copy from Manager preset</option>
                </select>
              </div>
            </div>

            <div style="display:flex;justify-content:space-between;align-items:center;margin:24px 0 16px;padding-top:16px;border-top:1px solid var(--line);flex-wrap:wrap;gap:10px">
              <div>
                <b style="font-size:14px">Module Permissions (13 Tabs)</b>
                <div style="font-size:12px;color:var(--muted);margin-top:2px">
                  Select permissions to grant. (<span id="newRoleActivePermsCount">0</span> of <span id="newRoleTotalPermsCount">35</span> enabled)
                </div>
              </div>
              <div style="display:flex;gap:8px">
                <button type="button" class="btn xs quiet" id="btnSelectAllNewPerms">Select All</button>
                <button type="button" class="btn xs quiet" id="btnDeselectAllNewPerms">Deselect All</button>
              </div>
            </div>

            <div id="newRolePermissionsGrid" class="cols c2" style="gap:16px">
              <!-- Dynamically populated per module -->
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:16px;border-top:1px solid var(--line)">
              <button type="button" class="btn sm quiet btn-back-to-roles">Cancel</button>
              <button type="button" class="btn sm primary" id="btnSubmitCreateRoleBottom">Create Role</button>
            </div>
          </div>
        </div>

        <!-- 3. VIEW ROLE DETAIL VIEW -->
        <div id="rolesDetailView" class="panel" style="display:none">
          <div class="hd" style="flex-wrap:wrap;gap:12px;align-items:center">
            <div style="display:flex;align-items:center;gap:12px">
              <button type="button" class="btn xs quiet btn-back-to-roles" title="Back to roles table">&larr; Back</button>
              <div>
                <h3 id="viewRoleTitle">Role Details</h3>
                <span class="meta" id="viewRoleSlugMeta">role_slug</span>
              </div>
            </div>
            <div class="grow"></div>
            <button type="button" class="btn sm primary" id="btnEditFromView">Edit Role</button>
          </div>
          <div class="bd" style="padding-top:16px">
            <div class="panel" style="background:var(--surface-2);border:1px solid var(--line);padding:16px 20px;margin-bottom:20px;border-radius:8px">
              <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px">
                <div>
                  <div style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.04em;font-weight:700">Description</div>
                  <div id="viewRoleDesc" style="font-size:14px;color:var(--fg);margin-top:4px">No description provided.</div>
                </div>
                <div style="display:flex;gap:16px">
                  <div>
                    <div style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.04em;font-weight:700">Type</div>
                    <div id="viewRoleTypeBadge" style="margin-top:4px"><span class="pill sm">System</span></div>
                  </div>
                  <div>
                    <div style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.04em;font-weight:700">Assigned Staff</div>
                    <div id="viewRoleStaffCount" class="num" style="font-size:16px;font-weight:700;margin-top:4px">0</div>
                  </div>
                  <div>
                    <div style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.04em;font-weight:700">Permissions</div>
                    <div id="viewRolePermCount" class="num" style="font-size:16px;font-weight:700;margin-top:4px">0 / 35</div>
                  </div>
                </div>
              </div>

              <!-- Assigned users list -->
              <div style="margin-top:14px;padding-top:12px;border-top:1px solid var(--line-2)">
                <div style="font-size:12px;color:var(--muted);font-weight:700;margin-bottom:6px">Assigned Team Members:</div>
                <div id="viewRoleUsersContainer" style="display:flex;gap:6px;flex-wrap:wrap">
                  <span style="font-size:13px;color:var(--muted)">No staff currently assigned to this role.</span>
                </div>
              </div>
            </div>

            <div style="margin:20px 0 12px">
              <b style="font-size:14px">Configured Permissions by Module</b>
            </div>

            <div id="viewRolePermissionsGrid" class="cols c2" style="gap:16px">
              <!-- Dynamically rendered read-only cards -->
            </div>
          </div>
        </div>

        <!-- 4. EDIT ROLE VIEW -->
        <div id="rolesEditView" class="panel" style="display:none">
          <div class="hd" style="flex-wrap:wrap;gap:12px;align-items:center">
            <div style="display:flex;align-items:center;gap:12px">
              <button type="button" class="btn xs quiet btn-back-to-roles" title="Back to roles table">&larr; Back</button>
              <div>
                <h3>Edit Role: <span id="editRoleTitleName">Role Name</span></h3>
                <span class="meta" id="editRoleSlugMeta">role_slug</span>
              </div>
            </div>
            <div class="grow"></div>
            <div style="display:flex;gap:8px">
              <button type="button" class="btn sm quiet btn-back-to-roles">Cancel</button>
              <button type="button" class="btn sm primary" id="btnSubmitUpdateRole">Save Changes</button>
            </div>
          </div>
          <div class="bd" style="padding-top:16px">
            <input type="hidden" id="editRoleSlug">

            <div id="editRoleAdminNotice" class="panel" style="background:var(--surface-2);border:1px dashed var(--line-2);padding:12px 16px;margin-bottom:16px;display:none">
              <div style="display:flex;align-items:center;gap:10px">
                <svg class="i" style="width:20px;height:20px;color:var(--accent)"><use href="#i-shield"/></svg>
                <div style="font-size:13px;color:var(--fg-2)">
                  <b>System Administrator Role:</b> Administrators have permanent full access to all system tabs and capabilities. Permissions cannot be revoked for this role.
                </div>
              </div>
            </div>

            <div class="cols c2" style="margin-bottom:20px;gap:16px">
              <div class="form-group" style="margin-bottom:0">
                <label for="editRoleName" style="font-size:13px;font-weight:700">Role Name *</label>
                <input type="text" id="editRoleName" style="width:100%;padding:9px 12px;border:1px solid var(--line-2);border-radius:6px;background:var(--surface);color:var(--fg);font-size:13.5px">
              </div>
              <div class="form-group" style="margin-bottom:0">
                <label for="editRoleDesc" style="font-size:13px;font-weight:700">Description</label>
                <input type="text" id="editRoleDesc" style="width:100%;padding:9px 12px;border:1px solid var(--line-2);border-radius:6px;background:var(--surface);color:var(--fg);font-size:13.5px">
              </div>
            </div>

            <div id="editRolePermissionsHeader" style="display:flex;justify-content:space-between;align-items:center;margin:24px 0 16px;padding-top:16px;border-top:1px solid var(--line);flex-wrap:wrap;gap:10px">
              <div>
                <b style="font-size:14px">Module Permissions (13 Tabs)</b>
                <div style="font-size:12px;color:var(--muted);margin-top:2px">
                  Configure access levels and action permissions. (<span id="editRoleActivePermsCount">0</span> of <span id="editRoleTotalPermsCount">35</span> enabled)
                </div>
              </div>
              <div style="display:flex;gap:8px" id="editRoleQuickActions">
                <button type="button" class="btn xs quiet" id="btnSelectAllEditPerms">Select All</button>
                <button type="button" class="btn xs quiet" id="btnDeselectAllEditPerms">Deselect All</button>
              </div>
            </div>

            <div id="editRolePermissionsGrid" class="cols c2" style="gap:16px">
              <!-- Dynamically populated per module -->
            </div>

            <div style="display:flex;justify-content:flex-end;gap:10px;margin-top:24px;padding-top:16px;border-top:1px solid var(--line)">
              <button type="button" class="btn sm quiet btn-back-to-roles">Cancel</button>
              <button type="button" class="btn sm primary" id="btnSubmitUpdateRoleBottom">Save Changes</button>
            </div>
          </div>
        </div>
      </section>

    </div>
  </div>
</div>

<!-- ===================== MODALS ===================== -->
<!-- Punch Modal with Camera -->
<div class="modal-overlay" id="punchModal" hidden>
  <div class="modal-card">
    <div class="m-hd">
      <h3 id="punchModalTitle">Record Punch</h3>
      <button class="btn xs quiet" id="btnClosePunchModal">&times;</button>
    </div>
    <div class="m-bd">
      <div class="cam-wrap">
        <video id="punchVideo" autoplay playsinline muted></video>
        <div class="cam-badge" id="camNotice">Starting camera...</div>
      </div>
      <div id="punchLocNotice" style="margin:10px 0 6px;font-size:12.5px;color:var(--muted);display:flex;align-items:center;gap:6px">
        <span id="punchLocDot" style="width:8px;height:8px;border-radius:50%;background:var(--warn);display:inline-block"></span>
        <span id="punchLocStatus">Acquiring GPS location...</span>
      </div>
      <div id="punchModalError" style="display:none;color:var(--crit);font-size:12.5px;margin:8px 0;line-height:1.4"></div>
      <p class="hint" style="margin-bottom:16px">Live camera selfie and location are required to record your punch.</p>
      <div class="rowbtns" style="justify-content:flex-end">
        <button class="btn quiet" id="btnCancelPunch" onclick="document.getElementById('btnClosePunchModal').click()">Cancel</button>
        <button class="btn primary" id="btnConfirmPunch" disabled>Confirm Punch</button>
      </div>
    </div>
  </div>
</div>

<!-- Consent Modal -->
<div class="modal-overlay" id="consentModal" hidden>
  <div class="modal-card" style="max-width:520px">
    <div class="m-hd">
      <h3>Data Privacy & Tracking Policy</h3>
    </div>
    <div class="m-bd">
      <p style="margin-bottom:12px;font-size:13.5px;color:var(--fg-2);line-height:1.6">
        To maintain accountability in our remote work environment while strictly complying with the <b>Digital Personal Data Protection (DPDP) Act</b>, ThatRank HR records:
      </p>
      <ul style="margin:0 0 16px 20px;font-size:13px;color:var(--muted);line-height:1.6">
        <li>A verification selfie captured during each punch action</li>
        <li>Browser geolocation coordinates during punch events</li>
        <li>Device and browser information for audit verification</li>
      </ul>
      <p style="font-size:12.5px;color:var(--muted);line-height:1.5;margin-bottom:20px">
        All verification selfies are automatically purged after <span id="consentNoticeDays">90</span> days. This information is accessible only to authorized managers and HR administrators.
      </p>
      <button class="btn primary block" id="btnAcceptConsent">I Understand and Accept</button>
    </div>
  </div>
</div>

<!-- Add Employee Modal -->
<div class="modal-overlay" id="addEmpModal" hidden>
  <div class="modal-card" style="max-width:500px">
    <div class="m-hd">
      <h3>Add New Employee</h3>
      <button class="btn xs quiet" id="btnCloseAddEmp">&times;</button>
    </div>
    <div class="m-bd">
      <form id="formAddEmp">
        <div class="field">
          <label>Full name</label>
          <input type="text" id="newEmpName" required placeholder="Full name">
        </div>
        <div class="field">
          <label>Work email</label>
          <input type="email" id="newEmpEmail" required placeholder="name@company.com">
        </div>
        <div class="field">
          <label>Phone number</label>
          <input type="tel" id="newEmpPhone" placeholder="Phone number">
        </div>
        <div class="field">
          <label>Job title</label>
          <input type="text" id="newEmpTitle" required placeholder="Job title">
        </div>
        <div class="cols c2" style="gap:12px">
          <div class="field">
            <label>Team</label>
            <select id="newEmpTeam">
              <option value="">No team assigned</option>
            </select>
          </div>
          <div class="field">
            <label>Role</label>
            <select id="newEmpRole">
              <option value="employee">Employee</option>
              <option value="manager">Manager</option>
              <option value="admin">Admin</option>
            </select>
          </div>
        </div>
        <div class="cols c2" style="gap:12px">
          <div class="field">
            <label>Date of birth</label>
            <input type="date" id="newEmpDob">
          </div>
          <div class="field">
            <label>Work anniversary</label>
            <input type="date" id="newEmpAnniv">
          </div>
        </div>
        <div class="field">
          <label>Joined date</label>
          <input type="date" id="newEmpJoined" value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="field">
          <label>Temporary password</label>
          <input type="password" id="newEmpPassword" placeholder="Optional custom password (or leave blank to generate)" autocomplete="new-password">
          <span class="hint" style="display:block;margin-top:4px">Leave empty to automatically generate a secure temporary password. The temporary password will be displayed upon creation.</span>
        </div>
        <div class="rowbtns" style="justify-content:flex-end;margin-top:16px">
          <button type="button" class="btn quiet" onclick="document.getElementById('btnCloseAddEmp').click()">Cancel</button>
          <button type="submit" class="btn primary">Add Employee</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Holiday Calendar Modal -->
<div class="modal-overlay" id="holidayModal" hidden>
  <div class="modal-card" style="max-width:640px">
    <div class="m-hd">
      <h3>Holiday Calendar <span id="holidayModalYear"><?php echo date('Y'); ?></span></h3>
      <button class="btn xs quiet" id="btnCloseHolidayModal">&times;</button>
    </div>
    <div class="m-bd">
      <div id="holidayAdminControls" data-admin hidden style="margin-bottom:14px;padding:12px;background:var(--surface-2);border-radius:var(--r-sm)">
        <h4 style="margin:0 0 8px 0;font-size:13px">Add Holiday</h4>
        <form id="formAddHoliday" style="display:flex;gap:8px;flex-wrap:wrap">
          <input type="text" id="newHolidayName" placeholder="Holiday name" required class="btn sm" style="flex:2;background:var(--surface)">
          <input type="date" id="newHolidayDate" required class="btn sm" style="flex:1;background:var(--surface)">
          <select id="newHolidayType" class="btn sm" style="flex:1;background:var(--surface)"></select>
          <button type="submit" class="btn primary sm">Add</button>
        </form>
      </div>
      <div class="tblwrap">
        <table class="tbl">
          <thead>
            <tr>
              <th>Holiday</th>
              <th>Date</th>
              <th>Day</th>
              <th>Type</th>
              <th data-admin hidden class="r">Actions</th>
            </tr>
          </thead>
          <tbody id="holidayTableBody">
            <!-- Dynamically populated from /api/holidays -->
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<!-- Add Event Modal -->
<div class="modal-overlay" id="addEventModal" hidden>
  <div class="modal-card" style="max-width:480px">
    <div class="m-hd">
      <h3>Add Upcoming Event</h3>
      <button class="btn xs quiet" id="btnCloseAddEvent">&times;</button>
    </div>
    <div class="m-bd">
      <form id="formAddEvent">
        <div class="field">
          <label>Event title</label>
          <input type="text" id="newEventTitle" required placeholder="Event title">
        </div>
        <div class="cols c2" style="gap:12px">
          <div class="field">
            <label>Date</label>
            <input type="date" id="newEventDate" required value="<?php echo date('Y-m-d'); ?>">
          </div>
          <div class="field">
            <label>Time</label>
            <input type="text" id="newEventTime" placeholder="Event time (e.g. 4:00 PM)">
          </div>
        </div>
        <div class="field">
          <label>Meeting link or location</label>
          <input type="text" id="newEventLink" placeholder="Meeting link or place">
        </div>
        <div class="rowbtns" style="justify-content:flex-end;margin-top:16px">
          <button type="button" class="btn quiet" onclick="document.getElementById('btnCloseAddEvent').click()">Cancel</button>
          <button type="submit" class="btn primary">Create Event</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add Team Modal -->
<div class="modal-overlay" id="addTeamModal" hidden>
  <div class="modal-card" style="max-width:480px">
    <div class="m-hd">
      <h3>Create New Team</h3>
      <button class="btn xs quiet" id="btnCloseAddTeam">&times;</button>
    </div>
    <div class="m-bd">
      <form id="formAddTeam">
        <div class="field">
          <label>Team name</label>
          <input type="text" id="newTeamName" required placeholder="Team name">
        </div>
        <div class="field">
          <label>Team manager</label>
          <select id="newTeamManager">
            <option value="">Select team manager (optional)...</option>
          </select>
        </div>
        <div class="rowbtns" style="justify-content:flex-end;margin-top:16px">
          <button type="button" class="btn quiet" onclick="document.getElementById('btnCloseAddTeam').click()">Cancel</button>
          <button type="submit" class="btn primary">Create Team</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Import Employees CSV Modal -->
<div class="modal-overlay" id="importCsvModal" hidden>
  <div class="modal-card" style="max-width:540px">
    <div class="m-hd">
      <h3>Import Employees via CSV</h3>
      <button class="btn xs quiet" id="btnCloseCsvModal">&times;</button>
    </div>
    <div class="m-bd">
      <p style="font-size:13px;color:var(--fg-2);margin-bottom:12px;line-height:1.5">
        Upload a CSV file containing employee records. Required columns: <code>name, email, phone, role, team, manager email, joining date, birthday</code>.
      </p>
      <form id="formImportCsv">
        <div class="field">
          <label>Select CSV file</label>
          <input type="file" id="csvFileInput" accept=".csv,text/csv" required>
        </div>
        <div class="rowbtns" style="justify-content:flex-end;margin-top:16px">
          <button type="button" class="btn quiet" onclick="document.getElementById('btnCloseCsvModal').click()">Cancel</button>
          <button type="submit" class="btn primary">Upload and Import</button>
        </div>
      </form>
      <div id="csvImportResults" style="margin-top:16px;display:none;font-size:13px"></div>
    </div>
  </div>
</div>

<!-- Edit Punch Modal (15-min window) -->
<div class="modal-overlay" id="editPunchModal" hidden>
  <div class="modal-card" style="max-width:440px">
    <div class="m-hd">
      <h3>Edit Recent Punch</h3>
      <button class="btn xs quiet" id="btnCloseEditPunchModal">&times;</button>
    </div>
    <div class="m-bd">
      <p style="font-size:13px;color:var(--fg-2);margin-bottom:12px">
        You can adjust the time of your most recent punch within 15 minutes of recording it.
      </p>
      <form id="formEditPunch">
        <div class="field">
          <label>Adjusted punch time</label>
          <input type="time" id="editPunchTime" required step="1">
        </div>
        <div class="rowbtns" style="justify-content:flex-end;margin-top:16px">
          <button type="button" class="btn quiet" onclick="document.getElementById('btnCloseEditPunchModal').click()">Cancel</button>
          <button type="submit" class="btn primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>



<!-- Attendance Policy Modal -->
<div class="modal-overlay" id="attendancePolicyModal" hidden>
  <div class="modal-card" style="max-width:540px">
    <div class="m-hd">
      <h3>Attendance Policy</h3>
      <button class="btn xs quiet" id="btnCloseAttnPolicy">&times;</button>
    </div>
    <div class="m-bd" style="font-size:13px;line-height:1.6">
      <div id="attnPolicyContent" style="display:flex;flex-direction:column;gap:12px">
        <div style="background:var(--surface-2);padding:10px 14px;border-radius:6px">
          <b>Standard Shift & Work Week:</b>
          <div id="attnPolicyShiftText">Work hours: 10:00 AM to 7:00 PM (Target 9 hours).</div>
          <div id="attnPolicyWorkWeek">Working days: Monday to Friday.</div>
        </div>
        <div>
          <b>Grace Period & Punctuality:</b>
          <div id="attnPolicyGraceText">A grace period of 15 minutes is allowed after shift start. Clocks after the grace period are marked late.</div>
        </div>
        <div>
          <b>Auto Clock-out:</b>
          <div id="attnPolicyAutoText">Forgotten open sessions are closed automatically at shift cutoff time.</div>
        </div>
        <div>
          <b>DPDP Verification:</b>
          <div>To maintain remote accountability, a verification selfie, location and device information are logged with each punch. Verification selfies are purged after 90 days.</div>
        </div>
      </div>
      <div class="rowbtns" style="justify-content:flex-end;margin-top:18px">
        <button class="btn primary sm" onclick="document.getElementById('btnCloseAttnPolicy').click()">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Leave Policy Modal -->
<div class="modal-overlay" id="leavePolicyModal" hidden>
  <div class="modal-card" style="max-width:540px">
    <div class="m-hd">
      <h3>Leave Policy & Guidelines</h3>
      <button class="btn xs quiet" id="btnCloseLeavePolicy">&times;</button>
    </div>
    <div class="m-bd" style="font-size:13px;line-height:1.6">
      <div id="leavePolicyContent" style="display:flex;flex-direction:column;gap:12px">
        <div>
          <b>Annual Leave Entitlements:</b>
          <div id="leavePolicyList" style="margin-top:6px">
            <!-- Dynamically populated -->
          </div>
        </div>
        <div style="background:var(--surface-2);padding:10px 14px;border-radius:6px">
          <b>Approval Workflow:</b>
          <div>Leave requests route directly to your designated manager for review and approval. Balance will be verified upon submission.</div>
        </div>
        <div>
          <b>Duration Options:</b>
          <div>You may submit requests for Full Day, Half Day (0.5), or Short Leave (0.25).</div>
        </div>
      </div>
      <div class="rowbtns" style="justify-content:flex-end;margin-top:18px">
        <button class="btn primary sm" onclick="document.getElementById('btnCloseLeavePolicy').click()">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- Request Attendance Correction Modal -->
<div class="modal-overlay" id="correctionModal" hidden>
  <div class="modal-card" style="max-width:460px">
    <div class="m-hd">
      <h3>Request correction</h3>
      <button class="btn xs quiet" id="btnCloseCorrection">&times;</button>
    </div>
    <div class="m-bd">
      <form id="formCorrection">
        <div class="field">
          <label for="corrDate">Date</label>
          <input type="date" id="corrDate" required max="<?php echo date('Y-m-d'); ?>" value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="cols c2" style="gap:14px">
          <div class="field" style="margin:0"><label for="corrIn">Clock in</label><input type="time" id="corrIn"></div>
          <div class="field" style="margin:0"><label for="corrOut">Clock out</label><input type="time" id="corrOut"></div>
        </div>
        <div class="field" style="margin-top:14px">
          <label for="corrReason">Reason</label>
          <textarea id="corrReason" required placeholder="What needs correcting?" style="min-height:70px"></textarea>
        </div>
        <button class="btn primary block" type="submit">Submit request</button>
      </form>
    </div>
  </div>
</div>

<!-- Request Comp-off Credit Modal -->
<div class="modal-overlay" id="compOffModal" hidden>
  <div class="modal-card" style="max-width:460px">
    <div class="m-hd">
      <h3>Request Comp-off Credit</h3>
      <button class="btn xs quiet" id="btnCloseCompOff">&times;</button>
    </div>
    <div class="m-bd">
      <p style="font-size:13px;color:var(--fg-2);margin-bottom:12px">
        Claim compensatory off credit for working on a weekend, public holiday or extra project hours.
      </p>
      <form id="formCompOff">
        <div class="field">
          <label>Date worked</label>
          <input type="date" id="compOffDate" required value="<?php echo date('Y-m-d'); ?>">
        </div>
        <div class="field">
          <label>Hours worked</label>
          <input type="number" id="compOffHours" required min="1" max="24" step="0.5" value="8">
        </div>
        <div class="field">
          <label>Reason / Project worked on</label>
          <textarea id="compOffReason" required placeholder="Describe the extra work completed..." style="min-height:70px"></textarea>
        </div>
        <div class="rowbtns" style="justify-content:flex-end;margin-top:16px">
          <button type="button" class="btn quiet" onclick="document.getElementById('btnCloseCompOff').click()">Cancel</button>
          <button type="submit" class="btn primary">Submit Comp-off Request</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Add / Edit Leave Type Modal (Admin) -->
<div class="modal-overlay" id="addLeaveTypeModal" hidden>
  <div class="modal-card" style="max-width:440px">
    <div class="m-hd">
      <h3 id="leaveTypeModalTitle">Add Leave Type</h3>
      <button class="btn xs quiet" id="btnCloseLeaveTypeModal">&times;</button>
    </div>
    <div class="m-bd">
      <form id="formAddLeaveType">
        <input type="hidden" id="leaveTypeId" value="">
        <div class="field">
          <label>Leave name</label>
          <input type="text" id="leaveTypeName" required placeholder="e.g. Paternity Leave">
        </div>
        <div class="field">
          <label>Code (identifier)</label>
          <input type="text" id="leaveTypeCode" required placeholder="e.g. paternity">
        </div>
        <div class="field">
          <label>Days per year</label>
          <input type="number" id="leaveTypeDays" required min="0" max="365" step="0.5" value="10">
        </div>
        <div class="field">
          <label style="display:flex;align-items:center;gap:8px">
            <input type="checkbox" id="leaveTypeIsPaid" checked> Paid leave
          </label>
        </div>
        <div class="rowbtns" style="justify-content:flex-end;margin-top:16px">
          <button type="button" class="btn quiet" onclick="document.getElementById('btnCloseLeaveTypeModal').click()">Cancel</button>
          <button type="submit" class="btn primary">Save Leave Type</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Temporary Password Display Modal -->
<div class="modal-overlay" id="tempPasswordModal" hidden style="z-index:9999">
  <div class="modal-card" style="max-width:440px">
    <div class="m-hd">
      <h3>Employee Created</h3>
      <button class="btn xs quiet" id="btnCloseTempPwModal">&times;</button>
    </div>
    <div class="m-bd">
      <p style="font-size:13.5px;color:var(--fg);margin-bottom:12px;line-height:1.5">
        The employee account has been created. A temporary password was generated:
      </p>
      <div style="background:var(--surface-2);border:1px solid var(--line);border-radius:6px;padding:12px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between">
        <code style="font-size:15px;font-weight:700;color:var(--accent);letter-spacing:0.04em" id="displayTempPassword"></code>
        <button class="btn xs" id="btnCopyTempPassword">Copy</button>
      </div>
      <p style="font-size:12.5px;color:var(--muted);margin-bottom:16px;line-height:1.4">
        Please share this temporary password with the employee. They will be prompted to choose a permanent password upon their first login.
      </p>
      <div class="rowbtns" style="justify-content:flex-end">
        <button class="btn primary sm" onclick="document.getElementById('btnCloseTempPwModal').click()">Done</button>
      </div>
    </div>
  </div>
</div>

<!-- Payroll: Edit Payslip Adjustments Modal -->
<div class="modal-overlay" id="modalPayslipAdjust" hidden style="z-index:9990">
  <div class="modal-card" style="max-width:520px">
    <div class="m-hd">
      <h3>Edit Payslip & Adjustments</h3>
      <button class="btn xs quiet" id="btnClosePayslipAdjustModal">&times;</button>
    </div>
    <div class="m-bd">
      <form id="formPayslipAdjust" class="stack" style="gap:14px">
        <input type="hidden" id="adjustPayslipId">
        <div style="font-size:13.5px;font-weight:600;color:var(--fg)" id="adjustEmpName">Employee</div>
        
        <div class="field">
          <label>LOP Days (Loss of Pay)</label>
          <input type="number" id="adjustLopDays" step="0.5" min="0" max="31" class="input" required>
          <span style="font-size:12px;color:var(--muted)">Adjusting LOP days automatically recalculates LOP deduction and paid days.</span>
        </div>

        <div class="sec" style="margin-top:6px;display:flex;justify-content:space-between;align-items:center">
          <h2 style="font-size:14px">One-off Adjustments</h2>
          <button type="button" class="btn xs" id="btnAddAdjustmentRow">+ Add row</button>
        </div>

        <div id="adjustmentsRowsContainer" class="stack" style="gap:8px">
          <!-- Dynamically populated rows -->
        </div>

        <div class="rowbtns" style="justify-content:flex-end;margin-top:10px">
          <button type="button" class="btn quiet" onclick="document.getElementById('btnClosePayslipAdjustModal').click()">Cancel</button>
          <button type="submit" class="btn primary">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Payroll: Salary Component Modal -->
<div class="modal-overlay" id="modalSalaryComponent" hidden style="z-index:9990">
  <div class="modal-card" style="max-width:500px">
    <div class="m-hd">
      <h3 id="modalComponentTitle">Add Salary Component</h3>
      <button class="btn xs quiet" id="btnCloseSalaryComponentModal">&times;</button>
    </div>
    <div class="m-bd">
      <form id="formSalaryComponent" class="stack" style="gap:12px">
        <input type="hidden" id="comp_id">
        <div class="field">
          <label>Component Name</label>
          <input type="text" id="comp_name" required class="input" placeholder="e.g. Performance Bonus">
        </div>
        <div class="cols c2" style="gap:12px">
          <div class="field">
            <label>Component Type</label>
            <select id="comp_type" class="input">
              <option value="earning">Earning</option>
              <option value="deduction">Deduction</option>
            </select>
          </div>
          <div class="field">
            <label>Calculation Type</label>
            <select id="comp_calc_type" class="input">
              <option value="fixed">Fixed Amount</option>
              <option value="percent_of_basic">% of Basic</option>
              <option value="percent_of_gross">% of Gross</option>
            </select>
          </div>
        </div>
        <div class="cols c2" style="gap:12px">
          <div class="field">
            <label>Default Value (₹ or %)</label>
            <input type="number" step="0.01" id="comp_default_value" class="input" required value="0">
          </div>
          <div class="field">
            <label>Statutory Rule</label>
            <select id="comp_statutory" class="input">
              <option value="none">None (Standard)</option>
              <option value="pf">PF (Provident Fund)</option>
              <option value="esi">ESI (State Insurance)</option>
              <option value="pt">PT (Professional Tax)</option>
              <option value="tds">TDS (Tax Deduction)</option>
            </select>
          </div>
        </div>
        <div class="cols c2" style="gap:12px">
          <div class="field">
            <label>Sort Order</label>
            <input type="number" id="comp_sort_order" class="input" value="10">
          </div>
          <div class="field">
            <label>Status</label>
            <select id="comp_active" class="input">
              <option value="1">Active</option>
              <option value="0">Inactive</option>
            </select>
          </div>
        </div>
        <div class="field" style="display:flex;gap:16px;margin-top:4px">
          <label style="display:flex;align-items:center;gap:6px;font-size:13px">
            <input type="checkbox" id="comp_applies_all" checked> Applies to all employees
          </label>
          <label style="display:flex;align-items:center;gap:6px;font-size:13px">
            <input type="checkbox" id="comp_show_payslip" checked> Show on payslip
          </label>
        </div>
        <div class="rowbtns" style="justify-content:flex-end;margin-top:10px">
          <button type="button" class="btn quiet" onclick="document.getElementById('btnCloseSalaryComponentModal').click()">Cancel</button>
          <button type="submit" class="btn primary">Save Component</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Payroll: Salary Structure Modal -->
<div class="modal-overlay" id="modalSalaryStructure" hidden style="z-index:9990">
  <div class="modal-card" style="max-width:560px">
    <div class="m-hd">
      <h3>Edit Salary Structure: <span id="structEmpName"></span></h3>
      <button class="btn xs quiet" id="btnCloseSalaryStructureModal">&times;</button>
    </div>
    <div class="m-bd">
      <form id="formSalaryStructure" class="stack" style="gap:14px">
        <input type="hidden" id="struct_user_id">
        <div class="field">
          <label>Effective From</label>
          <input type="date" id="struct_effective_from" class="input" required>
          <span style="font-size:12px;color:var(--muted)">The latest effective structure on or before the payroll month is applied.</span>
        </div>

        <div class="sec"><h2>Active Components</h2></div>
        <div id="structComponentFieldsContainer" class="stack" style="gap:10px;max-height:300px;overflow-y:auto;padding-right:6px">
          <!-- Dynamically populated component input fields -->
        </div>

        <!-- Calculated Live Preview -->
        <div style="background:var(--surface-2);border:1px solid var(--line);border-radius:6px;padding:12px;margin-top:6px">
          <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
            <span>Estimated Monthly Gross:</span>
            <b id="structPreviewGross">₹0</b>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:4px">
            <span>Estimated Total Deductions:</span>
            <b id="structPreviewDeductions">₹0</b>
          </div>
          <div style="display:flex;justify-content:space-between;font-size:14px;border-top:1px dashed var(--line);padding-top:6px;color:var(--ok)">
            <span>Estimated Net Take-Home:</span>
            <b id="structPreviewNet">₹0</b>
          </div>
        </div>

        <div class="rowbtns" style="justify-content:flex-end;margin-top:10px">
          <button type="button" class="btn quiet" onclick="document.getElementById('btnCloseSalaryStructureModal').click()">Cancel</button>
          <button type="submit" class="btn primary">Save Structure</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Payroll: Bulk Salary Structure Modal -->
<div class="modal-overlay" id="modalBulkSalaryStructure" hidden style="z-index:9990">
  <div class="modal-card" style="max-width:560px">
    <div class="m-hd">
      <h3>Bulk Set Salary Structure (<span id="bulkModalCount">0</span> selected)</h3>
      <button class="btn xs quiet" id="btnCloseBulkSalaryStructureModal">&times;</button>
    </div>
    <div class="m-bd">
      <form id="formBulkSalaryStructure" class="stack" style="gap:14px">
        <div class="field">
          <label>Effective From Date</label>
          <input type="date" id="bulk_struct_effective_from" class="input" required>
        </div>

        <div class="sec"><h2>Component Values / Overrides</h2></div>
        <div id="bulkStructComponentFieldsContainer" class="stack" style="gap:10px;max-height:300px;overflow-y:auto;padding-right:6px">
          <!-- Dynamically populated component fields -->
        </div>

        <div class="rowbtns" style="justify-content:flex-end;margin-top:10px">
          <button type="button" class="btn quiet" onclick="document.getElementById('btnCloseBulkSalaryStructureModal').click()">Cancel</button>
          <button type="submit" class="btn primary">Apply to All Selected</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Payroll: Structure History Modal -->
<div class="modal-overlay" id="modalStructureHistory" hidden style="z-index:9990">
  <div class="modal-card" style="max-width:620px">
    <div class="m-hd">
      <h3>Structure History: <span id="histEmpName"></span></h3>
      <button class="btn xs quiet" id="btnCloseStructureHistoryModal">&times;</button>
    </div>
    <div class="m-bd">
      <div class="tblwrap"><table class="tbl">
        <thead>
          <tr>
            <th>Effective From</th>
            <th>Created By</th>
            <th>Date Created</th>
            <th>Component Overrides</th>
          </tr>
        </thead>
        <tbody id="structureHistoryTableBody">
          <!-- Dynamically populated -->
        </tbody>
      </table></div>
    </div>
  </div>
</div>

<script src="<?php echo htmlspecialchars($assetBase, ENT_QUOTES, 'UTF-8'); ?>/js/app.js?v=<?php echo filemtime(__DIR__ . '/assets/js/app.js'); ?>"></script>
</body>
</html>
