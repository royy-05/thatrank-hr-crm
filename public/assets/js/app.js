// public/assets/js/app.js - ThatRank HR Client Application
(function () {
  'use strict';

  var $ = function (s) { return document.querySelector(s); };
  var $$ = function (s) { return document.querySelectorAll(s); };

  var state = {
    user: null,
    currentScreen: 'dash',
    csrfToken: '',
    punchStatus: 'clocked_out', // 'clocked_in', 'on_break', 'clocked_out'
    workSeconds: 0,
    timerInterval: null,
    idleSeconds: 0,
    isIdle: false,
    cameraStream: null,
    pendingPunchType: null,
    latestPunch: null,
    publicSettings: null,
    idleThreshold: 300,
    heartbeatInterval: 120000,
    heartbeatTimer: null,
    selectedAttendanceMonth: '',
    navOpen: {},
  };

  var HOLIDAY_TYPES = {
    gazetted: 'Gazetted',
    restricted: 'Restricted'
  };

  var titles = {
    dash: ['Good morning', new Date().toLocaleDateString('en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })],
    attn: ['My attendance', 'Punches and hours'],
    leave: ['Leave', 'Balance and requests'],
    hub: ['Community', 'Updates from the team'],
    fin: ['My finances', 'Payslips and tax projections'],
    board: ['Live board', 'Who is working right now'],
    approve: ['Approvals', 'Leave and correction requests'],
    reports: ['Reports', 'Weekly and monthly hours'],
    payroll: ['Payroll', 'Runs, salary structures and settings'],
    people: ['Employees', 'Your team'],
    teams: ['Teams', 'Departments and squads'],
    roles: ['Roles & Permissions', 'Configure module access and action permissions per role'],
    settings: ['Settings', 'Rules, devices and leave'],
    audit: ['Audit Log', 'System changes and access log']
  };

  // Helper for API fetch requests
  async function api(endpoint, method = 'GET', data = null) {
    var apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) ? window.APP_CONFIG.apiBase : '/api';
    var url = endpoint;
    if (endpoint.startsWith('/api')) {
      url = endpoint.replace(/^\/api/, apiBase);
    } else if (endpoint.startsWith('/')) {
      url = (window.APP_CONFIG && window.APP_CONFIG.basePath ? window.APP_CONFIG.basePath : '') + endpoint;
    }

    var options = {
      method: method,
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      }
    };

    if (state.csrfToken) {
      options.headers['X-CSRF-Token'] = state.csrfToken;
    }

    if (data) {
      if (data instanceof FormData) {
        options.body = data;
        if (state.csrfToken && !data.has('csrf_token')) {
          data.append('csrf_token', state.csrfToken);
        }
      } else {
        options.headers['Content-Type'] = 'application/x-www-form-urlencoded';
        var params = new URLSearchParams();
        if (data && typeof data === 'object') {
          for (var k in data) {
            if (Object.prototype.hasOwnProperty.call(data, k)) {
              var v = data[k];
              if (v !== null && v !== undefined && v !== 'null') {
                params.append(k, v);
              }
            }
          }
        }
        if (state.csrfToken && !params.has('csrf_token')) {
          params.append('csrf_token', state.csrfToken);
        }
        options.body = params.toString();
      }
    }

    try {
      var res = await fetch(url, options);
      var text = await res.text();
      try {
        var json = JSON.parse(text);
        return json;
      } catch (jsonErr) {
        console.error('Non-JSON response from ' + url + ':', text);
        return { success: false, error: 'Server error (' + res.status + ')' };
      }
    } catch (err) {
      console.error('API Error:', err);
      return { success: false, error: 'Network error or server unavailable.' };
    }
  }

  function showToast(msg) {
    var existing = $('.toast-msg');
    if (existing) existing.remove();
    var toast = document.createElement('div');
    toast.className = 'toast-msg';
    toast.textContent = msg;
    document.body.appendChild(toast);
    setTimeout(function () { toast.remove(); }, 3500);
  }

  // Clock ticking (Asia/Kolkata display)
  function tick() {
    var d = new Date();
    var h = d.getHours();
    var m = d.getMinutes();
    var s = d.getSeconds();
    var t = (h % 12 || 12) + ':' + (m < 10 ? '0' : '') + m;
    var ap = h >= 12 ? 'PM' : 'AM';
    var fullTime = t + ':' + (s < 10 ? '0' : '') + s;

    if ($('#bigClock')) $('#bigClock').textContent = t;
    if ($('#bigAmPm')) $('#bigAmPm').textContent = ap;
    if ($('#empBigClock')) $('#empBigClock').textContent = t;
    if ($('#empBigAmPm')) $('#empBigAmPm').textContent = ap;
    if ($('#mgrBigClock')) $('#mgrBigClock').textContent = t;
    if ($('#mgrBigAmPm')) $('#mgrBigAmPm').textContent = ap;
    if ($('#loginClock')) $('#loginClock').textContent = t;
    if ($('#attClock')) $('#attClock').textContent = fullTime;
    if ($('#attAmPm')) $('#attAmPm').textContent = ap;

    // Increment work timer if clocked in, or break timer if on break
    if (state.punchStatus === 'clocked_in') {
      state.workSeconds++;
      updateTimerDisplay();
    } else if (state.punchStatus === 'on_break') {
      state.breakSeconds = (state.breakSeconds || 0) + 1;
      updateBreakTimerDisplay();
    }
  }

  function updateTimerDisplay() {
    var hrs = Math.floor(state.workSeconds / 3600);
    var mins = Math.floor((state.workSeconds % 3600) / 60);
    var timeStr = hrs + 'h ' + (mins < 10 ? '0' : '') + mins + 'm';
    if ($('#todayStatHours')) $('#todayStatHours').textContent = timeStr;
    if ($('#empTodayHours')) $('#empTodayHours').textContent = timeStr;
    if ($('#mgrTodayHours')) $('#mgrTodayHours').textContent = timeStr;
  }

  function updateBreakTimerDisplay() {
    var bNum = (state.breakCount && state.breakCount > 0) ? ' #' + state.breakCount : '';
    var timeStr = 'On break' + bNum + ' (' + formatSecondsToHms(state.breakSeconds) + ')';
    if ($('#empStText')) $('#empStText').textContent = timeStr;
    if ($('#mgrStText')) $('#mgrStText').textContent = timeStr;
    if ($('#stText')) $('#stText').textContent = timeStr;
  }

  // Idle tracking using configured threshold
  function resetIdle() {
    state.idleSeconds = 0;
    state.isIdle = false;
  }
  window.addEventListener('mousemove', resetIdle);
  window.addEventListener('keydown', resetIdle);
  window.addEventListener('scroll', resetIdle);
  setInterval(function () {
    state.idleSeconds += 5;
    if (state.idleSeconds >= (state.idleThreshold || 300)) {
      state.isIdle = true;
    }
  }, 5000);

  // Heartbeat loop
  async function sendHeartbeat() {
    if (!state.user) return;
    var res = await api('/api/heartbeat', 'POST', {
      is_idle: state.isIdle ? 1 : 0,
      idle_minutes: Math.floor(state.idleSeconds / 60),
    });
    if (res && res.ok) {
      if (res.online && $('#remoteCount')) {
        $('#remoteCount').innerHTML = res.online + ' <span style="font-size:13px;color:var(--muted);font-weight:500">online</span>';
      }
      if (res.pending !== undefined && $('#pendingApprovalCount')) {
        $('#pendingApprovalCount').textContent = res.pending;
        $('#pendingApprovalCount').style.display = res.pending > 0 ? 'inline-block' : 'none';
      }
    }
  }

  // Screen Navigation
  function showScreen(s) {
    if (!state.user) return;
    var role = state.user.role || 'employee';
    var isMgr = role === 'manager' || role === 'admin';
    var isAdmin = role === 'admin';

    // "attn:team" opens a screen in a given view (managers only)
    var target = String(s).split(':');
    s = target[0];
    if (role === 'manager' && (target[1] === 'me' || target[1] === 'team')) {
      if (s === 'attn') state.attendanceView = target[1];
      if (s === 'leave') state.leaveView = target[1];
    }

    // Access control based on database role
    if ((s === 'board' || s === 'approve' || s === 'reports') && !isMgr) {
      s = 'dash';
      window.location.hash = 'dash';
    } else if ((s === 'people' || s === 'teams' || s === 'audit' || s === 'payroll' || s === 'roles') && !isAdmin) {
      s = 'dash';
      window.location.hash = 'dash';
    } else {
      var view = s === 'attn' ? state.attendanceView : (s === 'leave' ? state.leaveView : '');
      window.location.hash = view === 'team' ? s + ':team' : s;
    }

    state.currentScreen = s;
    $$('.screen').forEach(function (el) {
      el.hidden = el.dataset.screen !== s;
    });
    $$('#nav button').forEach(function (b) {
      b.classList.toggle('on', b.dataset.s === s);
    });

    if (titles[s]) {
      var name = state.user ? state.user.name.split(' ')[0] : 'there';
      var title = scopedTitle(s) || titles[s];
      var titleText = s === 'dash' ? 'Good morning, ' + name : title[0];
      if ($('#scrTitle')) $('#scrTitle').textContent = titleText;
      if ($('#scrSub')) $('#scrSub').textContent = title[1];
    }
    if (s === 'attn' || s === 'leave') state.navOpen[s] = role === 'manager';
    syncSubnav();

    if ($('#rail')) $('#rail').classList.remove('open');
    if ($('#shell')) $('#shell').classList.remove('navopen');
    window.scrollTo(0, 0);

    // Refresh active screen data
    if (s === 'dash') loadDashboard();
    if (s === 'attn') loadAttendanceData();
    if (s === 'leave') { loadLeaveData(); loadLeaveStats(); }
    if (s === 'hub') { loadCommunityFeed(); loadUpcomingEvents(); loadSidebarPoll(); loadTeammatesForPraise(); }
    if (s === 'fin') loadFinances();
    if (s === 'board') loadLiveBoard();
    if (s === 'approve') loadApprovals();
    if (s === 'reports') loadReports();
    if (s === 'payroll') loadPayroll();
    if (s === 'people') loadEmployees();
    if (s === 'teams') loadTeams();
    if (s === 'roles') loadRoles();
    if (s === 'settings') { loadSettings(); loadActiveDevices(); }
    if (s === 'audit') loadAuditLogs();
  }

  function scopedTitle(s) {
    var manager = state.user && state.user.role === 'manager';
    if (s === 'attn') {
      if (state.attendanceView === 'all') return ['Attendance', 'Everyone in the company'];
      if (state.attendanceView === 'team') return ['Team attendance', 'Punches and hours for your team'];
    }
    if (s === 'leave') {
      if (state.leaveView === 'all') return ['Leave', 'Requests from employees and managers'];
      if (state.leaveView === 'team') return ['Team leave', 'Requests from your team'];
      if (manager) return ['My leave', 'Balance and requests'];
    }
    return null;
  }

  // Managers get My / Team dropdowns under Attendance and Leave
  function syncSubnav() {
    var isManager = !!state.user && state.user.role === 'manager';
    $$('.subnav').forEach(function (sub) {
      var s = sub.dataset.for;
      var parent = $('button[data-s="' + s + '"]');
      var open = isManager && !parent.hidden && !!state.navOpen[s];
      var activeView = s === 'attn' ? state.attendanceView : state.leaveView;

      sub.hidden = !open;
      parent.classList.toggle('has-sub', isManager);
      parent.classList.toggle('open', open);
      parent.querySelector('.chev').hidden = !isManager;
      if (isManager) parent.setAttribute('aria-expanded', open ? 'true' : 'false');
      sub.querySelectorAll('button').forEach(function (b) {
        b.classList.toggle('on', state.currentScreen === s && b.dataset.view === activeView);
      });
    });
  }

  // Sidebar role visibility
  function applyRoleNavigation() {
    if (!state.user) return;
    var role = state.user.role || 'employee';
    var isMgr = role === 'manager' || role === 'admin';
    var isAdmin = role === 'admin';
    var perms = state.permissions || null;

    $$('[data-mgr]').forEach(function (el) { el.hidden = !isMgr; });
    $$('[data-admin]').forEach(function (el) { el.hidden = !isAdmin; });

    // Granular navigation filtering based on role permissions
    if (perms && !isAdmin) {
      if (perms.view_live_board !== undefined) {
        var btnBoard = $('button[data-s="board"]');
        if (btnBoard) btnBoard.hidden = !perms.view_live_board;
      }
      if (perms.view_approvals !== undefined) {
        var btnApprove = $('button[data-s="approve"]');
        if (btnApprove) btnApprove.hidden = !perms.view_approvals;
      }
      if (perms.view_reports !== undefined) {
        var btnReports = $('button[data-s="reports"]');
        if (btnReports) btnReports.hidden = !perms.view_reports;
      }
      if (perms.view_finances !== undefined) {
        var btnFin = $('button[data-s="fin"]');
        if (btnFin) btnFin.hidden = !perms.view_finances;
      }
      if (perms.view_community !== undefined) {
        var btnHub = $('button[data-s="hub"]');
        if (btnHub) btnHub.hidden = !perms.view_community;
      }
      if (perms.view_attendance !== undefined) {
        var btnAttn = $('button[data-s="attn"]');
        if (btnAttn) btnAttn.hidden = !perms.view_attendance;
      }
      if (perms.view_leave !== undefined) {
        var btnLeave = $('button[data-s="leave"]');
        if (btnLeave) btnLeave.hidden = !perms.view_leave;
      }
    }

    if ($('#navAttnLabel')) $('#navAttnLabel').textContent = role === 'employee' ? 'My attendance' : 'Attendance';
    syncSubnav();
  }

  // Auth Initialization
  async function initAuth() {
    var meta = $('meta[name="csrf-token"]');
    if (meta && meta.content) {
      state.csrfToken = meta.content;
    }

    // Set dynamic current year on all year placeholders
    var currentYear = new Date().getFullYear();
    $$('.current-year').forEach(function (el) { el.textContent = currentYear; });
    if ($('#holidayModalYear')) $('#holidayModalYear').textContent = currentYear;

    // Load public settings for retention notices, shift defaults, and timers
    var pub = await api('/api/settings/public');
    if (pub && pub.success && pub.settings) {
      state.publicSettings = pub.settings;
      var retDays = pub.settings.delete_selfies_after_days || '90';
      if ($('#loginNoticeDays')) $('#loginNoticeDays').textContent = retDays;
      if ($('#consentNoticeDays')) $('#consentNoticeDays').textContent = retDays;
      if (pub.settings.idle_threshold_seconds) {
        state.idleThreshold = parseInt(pub.settings.idle_threshold_seconds, 10);
      }
      if (pub.settings.heartbeat_interval_seconds) {
        state.heartbeatInterval = parseInt(pub.settings.heartbeat_interval_seconds, 10) * 1000;
      }
    }

    var res = await api('/api/auth/me');
    if (res && res.csrf_token) {
      state.csrfToken = res.csrf_token;
    }
    if (res && res.authenticated) {
      state.user = res.user;
      onSignedIn();
    } else {
      onSignedOut();
    }
  }

  function onSignedIn() {
    if ($('#loginView')) $('#loginView').hidden = true;
    if ($('#shell')) $('#shell').hidden = false;

    // Update user profile display in rail
    var u = state.user;
    if ($('#meAv')) {
      $('#meAv').textContent = u.avatar_initials || 'TR';
      if (u.avatar_color) $('#meAv').style.background = u.avatar_color;
    }
    if ($('#meName')) $('#meName').textContent = u.name;
    if ($('#meRole')) $('#meRole').textContent = u.job_title || ucfirst(u.role);
    if ($('#scrTitle')) $('#scrTitle').textContent = 'Good morning' + (u.name ? ', ' + u.name.split(' ')[0] : '');
    if ($('#scrSub')) $('#scrSub').textContent = new Date().toLocaleDateString('en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

    // Build sidebar strictly from user role
    state.attendanceView = defaultScopeView();
    state.leaveView = defaultScopeView();
    applyRoleNavigation();
    loadUserPermissions();

    // Check if consent accepted
    if (!u.consent_accepted_at) {
      $('#consentModal').hidden = false;
    }

    var initialHash = window.location.hash.replace('#', '');
    if (initialHash && initialHash !== 'dash') {
      showScreen(initialHash);
    } else {
      loadDashboard();
    }

    sendHeartbeat();
    if (state.heartbeatTimer) clearInterval(state.heartbeatTimer);
    state.heartbeatTimer = setInterval(sendHeartbeat, state.heartbeatInterval || 120000);
  }

  function onSignedOut() {
    state.user = null;
    if (state.heartbeatTimer) {
      clearInterval(state.heartbeatTimer);
      state.heartbeatTimer = null;
    }
    if ($('#shell')) $('#shell').hidden = true;
    if ($('#loginView')) $('#loginView').hidden = false;
  }

  function ucfirst(str) {
    if (!str) return '';
    return str.charAt(0).toUpperCase() + str.slice(1);
  }

  function escapeHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function formatPaydayDate(dateStr) {
    if (!dateStr) return '';
    var parts = String(dateStr).split('-');
    if (parts.length === 3) {
      var d = new Date(parseInt(parts[0], 10), parseInt(parts[1], 10) - 1, parseInt(parts[2], 10));
      return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' });
    }
    return dateStr;
  }

  function updateLeaveFormHint() {
    var hint = $('#leaveBalanceHint');
    var sel = $('#lt');
    if (!hint || !sel) return;
    var opt = sel.selectedOptions ? sel.selectedOptions[0] : null;
    if (!opt) return;
    var avail = opt.dataset.available !== undefined ? parseFloat(opt.dataset.available) : null;
    if (avail !== null && avail <= 0) {
      hint.innerHTML = '<span style="color:var(--crit);font-weight:600">Balance notice: You have 0 available days for this leave type. Request cannot be submitted.</span>';
    } else if (avail !== null && avail < 999) {
      hint.innerHTML = 'Available balance: <strong>' + avail + ' day(s)</strong>. Request routes to your manager upon submission.';
    } else {
      hint.innerHTML = 'Balance will be verified upon submission. Request routes to your manager.';
    }
  }

  // Dashboard Loader Router
  async function loadDashboard() {
    if (!state.user) return;
    var role = state.user.role || 'employee';

    if (role === 'admin') {
      if ($('#dashAdmin')) $('#dashAdmin').style.display = 'block';
      if ($('#dashManager')) $('#dashManager').style.display = 'none';
      if ($('#dashEmployee')) $('#dashEmployee').style.display = 'none';
      await loadAdminDashboard();
    } else if (role === 'manager') {
      if ($('#dashAdmin')) $('#dashAdmin').style.display = 'none';
      if ($('#dashManager')) $('#dashManager').style.display = 'block';
      if ($('#dashEmployee')) $('#dashEmployee').style.display = 'none';
      await loadManagerDashboard();
    } else {
      if ($('#dashAdmin')) $('#dashAdmin').style.display = 'none';
      if ($('#dashManager')) $('#dashManager').style.display = 'none';
      if ($('#dashEmployee')) $('#dashEmployee').style.display = 'block';
      await loadEmployeeDashboard();
    }
  }

  async function loadEmployeeDashboard() {
    var res = await api('/api/dashboard/employee');
    if (res && res.success) {
      var d = res.dashboard;
      state.latestPunch = d.latest_punch || null;
      var todayRec = d.today || d.record || null;
      renderPunchState(todayRec, d.latest_punch, 'emp');

      // Shift labels from settings
      var dailyTarget = (state.publicSettings && state.publicSettings.daily_target_hours) ? state.publicSettings.daily_target_hours : '9';
      var workWeekStr = (state.publicSettings && state.publicSettings.work_week) ? state.publicSettings.work_week : 'Mon to Fri';
      if ($('#empTargetHoursLabel')) $('#empTargetHoursLabel').textContent = 'Target ' + dailyTarget + 'h';
      if ($('#empWorkWeekLabel')) $('#empWorkWeekLabel').textContent = workWeekStr;

      if (d.next_holiday && $('#empNextHolidayName')) {
        $('#empNextHolidayName').textContent = d.next_holiday.name.toUpperCase();
        var hd = new Date(d.next_holiday.date + 'T00:00:00');
        $('#empNextHolidayDate').textContent = hd.toLocaleDateString('en-US', { weekday: 'long', day: 'numeric', month: 'long' });
      } else if ($('#empNextHolidayName')) {
        $('#empNextHolidayName').textContent = 'None';
        $('#empNextHolidayDate').textContent = 'No upcoming holidays';
      }

      // Dynamic Leave Balance Rings
      if (d.balances) {
        var ringsContainer = $('#empLeaveRings');
        if (ringsContainer && d.balances.cards && d.balances.cards.length > 0) {
          ringsContainer.innerHTML = '';
          var ringCirc = 144.5;
          d.balances.cards.forEach(function (c) {
            var tot = parseFloat(c.total !== undefined ? c.total : (c.quota || 0));
            var av = parseFloat(c.available !== undefined ? c.available : 0);
            var off = tot > 0 ? ringCirc * (1 - Math.min(1, Math.max(0, av / tot))) : ringCirc;
            var col = c.color || 'var(--accent)';
            var shortName = (c.name || '').replace(/ Leave$/i, '');

            var ringDiv = document.createElement('div');
            ringDiv.className = 'ring';
            ringDiv.innerHTML = '<svg viewBox="0 0 56 56">' +
              '<circle class="bg" cx="28" cy="28" r="23"/>' +
              '<circle class="fg" cx="28" cy="28" r="23" stroke-dasharray="144.5" style="stroke:' + col + ';stroke-dashoffset:' + off + '"/></svg>' +
              '<div><div class="v num">' + av + '</div><div class="k">' + shortName + '</div></div>';
            ringsContainer.appendChild(ringDiv);
          });
        } else {
          if ($('#empCasualVal')) $('#empCasualVal').textContent = d.balances.casual_available !== undefined ? d.balances.casual_available : 0;
          if ($('#empSickVal')) $('#empSickVal').textContent = d.balances.sick_available !== undefined ? d.balances.sick_available : 0;
          if ($('#empEarnedVal')) $('#empEarnedVal').textContent = d.balances.earned_available !== undefined ? d.balances.earned_available : 0;
        }
      }

      // Working hours
      var todayH = (d.hours && d.hours.today_str) ? d.hours.today_str : (d.today_str || '0h 00m');
      var weekH = (d.hours && d.hours.week_str) ? d.hours.week_str : (d.week_str || '0h 00m');
      if ($('#empTodayHours')) $('#empTodayHours').textContent = todayH;
      if ($('#empThisWeekHours')) $('#empThisWeekHours').textContent = weekH;

      if ($('#empLateMarks')) $('#empLateMarks').textContent = d.late_marks !== undefined ? d.late_marks : 0;
      if ($('#empPendingRequests')) $('#empPendingRequests').textContent = d.pending_requests !== undefined ? d.pending_requests : 0;

      // On leave today list
      if ($('#empOnLeaveList')) {
        var box = $('#empOnLeaveList');
        box.innerHTML = '';
        if (!d.on_leave_today || d.on_leave_today.length === 0) {
          box.innerHTML = '<div style="font-size:13px;color:var(--muted)">No one on leave today</div>';
        } else {
          d.on_leave_today.forEach(function (l) {
            var row = document.createElement('div');
            row.className = 'row';
            row.style.marginTop = '6px';
            row.innerHTML = '<div class="person"><span class="avatar sm" style="background:' + (l.avatar_color || 'var(--crit)') + '">' + (l.avatar_initials || 'TR') + '</span><div><div class="n">' + l.name + '</div><div class="e">' + ucfirst(l.leave_type) + ' leave</div></div></div>';
            box.appendChild(row);
          });
        }
      }

      loadCelebrations('#empCelebrationsList');
      loadCommunityFeed('#empFeedContainer');
      loadTeammatesForPraise();
    }
  }

  async function loadManagerDashboard() {
    var res = await api('/api/dashboard/manager');
    if (res && res.success) {
      var d = res.dashboard;
      state.latestPunch = d.latest_punch || null;
      var todayRec = d.today || d.record || null;
      renderPunchState(todayRec, d.latest_punch, 'mgr');

      // Shift labels from settings
      var dailyTarget = (state.publicSettings && state.publicSettings.daily_target_hours) ? state.publicSettings.daily_target_hours : '9';
      var workWeekStr = (state.publicSettings && state.publicSettings.work_week) ? state.publicSettings.work_week : 'Mon to Fri';
      if ($('#mgrTargetHoursLabel')) $('#mgrTargetHoursLabel').textContent = 'Target ' + dailyTarget + 'h';
      if ($('#mgrWorkWeekLabel')) $('#mgrWorkWeekLabel').textContent = workWeekStr;

      // Team status counts
      if (d.team && d.team.counts) {
        if ($('#mgrTeamWorking')) $('#mgrTeamWorking').textContent = d.team.counts.working || 0;
        if ($('#mgrTeamIdle')) $('#mgrTeamIdle').textContent = d.team.counts.idle || 0;
        if ($('#mgrTeamBreak')) $('#mgrTeamBreak').textContent = d.team.counts.break || 0;
        if ($('#mgrTeamOffline')) $('#mgrTeamOffline').textContent = d.team.counts.offline || 0;
        if ($('#mgrTeamNotIn')) $('#mgrTeamNotIn').textContent = d.team.counts.not_in || 0;
      }
      if (d.team) {
        if ($('#mgrPendingCount')) $('#mgrPendingCount').textContent = d.team.pending_count || 0;
        if ($('#mgrTeamLateCount')) $('#mgrTeamLateCount').textContent = d.team.team_late_this_week || 0;
      }

      var mgrTodayH = (d.hours && d.hours.today_str) ? d.hours.today_str : (d.today_str || '0h 00m');
      var mgrWeekH = (d.hours && d.hours.week_str) ? d.hours.week_str : (d.week_str || '0h 00m');
      if ($('#mgrTodayHours')) $('#mgrTodayHours').textContent = mgrTodayH;
      if ($('#mgrThisWeekHours')) $('#mgrThisWeekHours').textContent = mgrWeekH;

      loadTeammatesForPraise();

      if (d.next_holiday && $('#mgrNextHolidayName')) {
        $('#mgrNextHolidayName').textContent = d.next_holiday.name.toUpperCase();
        var hd = new Date(d.next_holiday.date + 'T00:00:00');
        $('#mgrNextHolidayDate').textContent = hd.toLocaleDateString('en-US', { weekday: 'long', day: 'numeric', month: 'long' });
      } else if ($('#mgrNextHolidayName')) {
        $('#mgrNextHolidayName').textContent = 'None';
        $('#mgrNextHolidayDate').textContent = 'No upcoming holidays';
      }

      if (d.balances) {
        if ($('#mgrCasualVal')) $('#mgrCasualVal').textContent = d.balances.casual_available;
        if ($('#mgrSickVal')) $('#mgrSickVal').textContent = d.balances.sick_available;
        if ($('#mgrEarnedVal')) $('#mgrEarnedVal').textContent = d.balances.earned_available;

        var ringCirc = 144.5;
        var cTot = parseFloat(d.balances.casual_total || 12);
        var cAv = parseFloat(d.balances.casual_available || 0);
        var cOff = cTot > 0 ? ringCirc * (1 - Math.min(1, Math.max(0, cAv / cTot))) : ringCirc;
        if ($('#mgrRingCasual')) $('#mgrRingCasual').style.strokeDashoffset = cOff;

        var sTot = parseFloat(d.balances.sick_total || 6);
        var sAv = parseFloat(d.balances.sick_available || 0);
        var sOff = sTot > 0 ? ringCirc * (1 - Math.min(1, Math.max(0, sAv / sTot))) : ringCirc;
        if ($('#mgrRingSick')) $('#mgrRingSick').style.strokeDashoffset = sOff;

        var eTot = parseFloat(d.balances.earned_total || 15);
        var eAv = parseFloat(d.balances.earned_available || 0);
        var eOff = eTot > 0 ? ringCirc * (1 - Math.min(1, Math.max(0, eAv / eTot))) : ringCirc;
        if ($('#mgrRingEarned')) $('#mgrRingEarned').style.strokeDashoffset = eOff;
      }

      if (d.hours) {
        if ($('#mgrTodayHours')) $('#mgrTodayHours').textContent = d.hours.today_str;
        if ($('#mgrThisWeekHours')) $('#mgrThisWeekHours').textContent = d.hours.week_str;
      }
      if ($('#mgrLateMarks')) $('#mgrLateMarks').textContent = d.late_marks !== undefined ? d.late_marks : 0;
      if ($('#mgrPendingRequests')) $('#mgrPendingRequests').textContent = d.pending_requests !== undefined ? d.pending_requests : 0;

      // On leave today list
      if ($('#mgrOnLeaveList')) {
        var box = $('#mgrOnLeaveList');
        box.innerHTML = '';
        if (!d.on_leave_today || d.on_leave_today.length === 0) {
          box.innerHTML = '<div style="font-size:13px;color:var(--muted)">No team members on leave today</div>';
        } else {
          d.on_leave_today.forEach(function (l) {
            var row = document.createElement('div');
            row.className = 'row';
            row.style.marginTop = '6px';
            row.innerHTML = '<div class="person"><span class="avatar sm" style="background:var(--crit)">' + (l.avatar_initials || 'TR') + '</span><div><div class="n">' + l.name + '</div><div class="e">' + ucfirst(l.leave_type) + ' leave</div></div></div>';
            box.appendChild(row);
          });
        }
      }

      loadCelebrations('#mgrCelebrationsList');
      loadCommunityFeed('#mgrFeedContainer');
    }
  }

  async function loadAdminDashboard() {
    var res = await api('/api/dashboard/admin');
    if (res && res.success) {
      var d = res.dashboard;
      var stats = d.stats || d;
      if (stats) {
        if ($('#admTotalEmployees')) $('#admTotalEmployees').textContent = stats.total_employees !== undefined ? stats.total_employees : 0;
        if ($('#admPresentToday')) $('#admPresentToday').textContent = stats.present_today !== undefined ? stats.present_today : 0;
        if ($('#admOnLeaveToday')) $('#admOnLeaveToday').textContent = stats.on_leave_today !== undefined ? stats.on_leave_today : 0;
        if ($('#admPendingApprovals')) $('#admPendingApprovals').textContent = stats.pending_approvals !== undefined ? stats.pending_approvals : 0;
        if ($('#admLateMarksWeek')) $('#admLateMarksWeek').textContent = stats.late_marks_week !== undefined ? stats.late_marks_week : 0;
        if ($('#admAnomalyCount')) $('#admAnomalyCount').textContent = stats.anomaly_count !== undefined ? stats.anomaly_count : 0;
      }

      // Recent audit log entries
      if ($('#admRecentAuditBody')) {
        var tbody = $('#admRecentAuditBody');
        tbody.innerHTML = '';
        if (!d.recent_audit || d.recent_audit.length === 0) {
          tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--muted);padding:14px">No audit entries yet</td></tr>';
        } else {
          d.recent_audit.forEach(function (a) {
            var tr = document.createElement('tr');
            var timeFormatted = a.created_at ? a.created_at.slice(11, 16) : '';
            tr.innerHTML = '<td>' + timeFormatted + '</td>' +
              '<td>' + (a.user_name || 'System') + '</td>' +
              '<td><span class="tag">' + a.action + '</span></td>' +
              '<td>' + a.target_type + '</td>';
            tbody.appendChild(tr);
          });
        }
      }

      // Company on leave today
      if ($('#admOnLeaveList')) {
        var container = $('#admOnLeaveList');
        container.innerHTML = '';
        if (!d.on_leave_today_list || d.on_leave_today_list.length === 0) {
          container.innerHTML = '<div style="font-size:13px;color:var(--muted);padding:8px 0">No employees on leave today.</div>';
        } else {
          d.on_leave_today_list.forEach(function (l) {
            var item = document.createElement('div');
            item.className = 'row';
            item.style.marginTop = '6px';
            item.innerHTML = '<div class="person"><span class="avatar sm" style="background:var(--crit)">' + (l.avatar_initials || 'TR') + '</span><div><div class="n">' + l.name + '</div><div class="e">' + (l.department || '') + ' : ' + ucfirst(l.leave_type) + ' leave</div></div></div>';
            container.appendChild(item);
          });
        }
      }
    }
  }

  function renderPunchState(r, latestPunch, prefix) {
    var prefixes = prefix ? [prefix] : ['emp', 'mgr'];
    prefixes.forEach(function (p) {
      var dot = $('#' + p + 'StDot') || $('#stDot');
      var txt = $('#' + p + 'StText') || $('#stText');
      var bB = $('#' + p + 'BtnBreak') || $('#btnBreak');
      var bO = $('#' + p + 'BtnOut') || $('#btnOut');
      var btnUndo = $('#' + p + 'BtnUndoPunch') || $('#btnUndoPunch');
      var btnEdit = $('#' + p + 'BtnEditPunch') || $('#btnEditPunch');

      if (latestPunch && latestPunch.can_edit) {
        if (btnUndo) btnUndo.style.display = 'inline-block';
        if (btnEdit) btnEdit.style.display = 'inline-block';
      } else {
        if (btnUndo) btnUndo.style.display = 'none';
        if (btnEdit) btnEdit.style.display = 'none';
      }

      if ((r && r.status === 'on_leave') || (r && r.is_on_leave && r.current_status === 'clocked_out')) {
        state.punchStatus = 'on_leave';
        if (dot) dot.className = 'off';
        if (txt) {
          txt.textContent = 'On approved leave today';
        }
        if (bB) { bB.textContent = 'On leave'; bB.disabled = true; }
        if (bO) { bO.textContent = 'On leave'; bO.disabled = true; }
        state.workSeconds = r ? parseInt(r.work_seconds || 0, 10) : 0;
      } else if (!r || r.current_status === 'clocked_out') {
        state.punchStatus = 'clocked_out';
        if (dot) dot.className = 'off';
        if (txt) {
          var outTime = r && r.last_out ? formatTimeStr(r.last_out) : '';
          txt.textContent = outTime ? 'Clocked out at ' + outTime : 'Not clocked in yet';
        }
        if (bB) { bB.textContent = 'Start break'; bB.disabled = true; }
        if (bO) { bO.textContent = 'Clock in'; bO.disabled = false; }
        state.workSeconds = r ? parseInt(r.work_seconds || 0, 10) : 0;
      } else if (r.current_status === 'on_break') {
        state.punchStatus = 'on_break';
        state.breakCount = r ? (r.break_count || 1) : 1;
        state.breakSeconds = parseInt(r.effective_break_seconds || r.break_seconds || 0, 10);
        if (dot) dot.className = 'break';
        if (txt) {
          var bTimeStr = state.breakSeconds > 0 ? ' (' + formatSecondsToHms(state.breakSeconds) + ')' : '';
          var bLabel = state.breakCount > 0 ? 'On break #' + state.breakCount : 'On break';
          txt.textContent = bLabel + bTimeStr;
        }
        if (bB) { bB.textContent = 'End break'; bB.disabled = false; }
        if (bO) { bO.textContent = 'Clock out'; bO.disabled = true; }
        state.workSeconds = parseInt(r.work_seconds || 0, 10);
      } else {
        state.punchStatus = 'clocked_in';
        state.breakCount = r ? (r.break_count || 0) : 0;
        state.breakSeconds = r ? parseInt(r.break_seconds || 0, 10) : 0;
        if (dot) dot.className = '';
        if (txt) {
          var inTime = r.first_in ? formatTimeStr(r.first_in) : '';
          var bInfo = state.breakCount > 0 ? ' (' + state.breakCount + (state.breakCount === 1 ? ' break' : ' breaks') + ')' : '';
          txt.textContent = inTime ? 'Clocked in at ' + inTime + bInfo : 'Clocked in' + bInfo;
        }
        if (bB) { bB.textContent = 'Start break'; bB.disabled = false; }
        if (bO) { bO.textContent = 'Clock out'; bO.disabled = false; }
        state.workSeconds = parseInt(r.effective_work_seconds || r.work_seconds || 0, 10);
      }
    });
    updateTimerDisplay();
  }

  function formatTimeStr(timeVal) {
    if (!timeVal) return '';
    var parts = timeVal.split(':');
    var h = parseInt(parts[0], 10);
    var m = parts[1] || '00';
    if (state.attn24h) {
      return (h < 10 ? '0' : '') + h + ':' + m;
    }
    var ap = h >= 12 ? 'PM' : 'AM';
    return (h % 12 || 12) + ':' + m + ' ' + ap;
  }

  // Camera and Location requirements helper
  function isCameraRequired() {
    var userCam = (state.user && state.user.require_camera !== undefined) ? parseInt(state.user.require_camera, 10) : 1;
    var coCam = (state.publicSettings && state.publicSettings.selfie_on_punch !== undefined) ? parseInt(state.publicSettings.selfie_on_punch, 10) : 1;
    return userCam === 1 && coCam === 1;
  }

  function isLocationRequired() {
    var userLoc = (state.user && state.user.require_location !== undefined) ? parseInt(state.user.require_location, 10) : 1;
    var coLoc = (state.publicSettings && state.publicSettings.location_on_punch !== undefined) ? parseInt(state.publicSettings.location_on_punch, 10) : 1;
    return userLoc === 1 && coLoc === 1;
  }

  // Camera and Punch flow
  function openPunchModal(type) {
    state.pendingPunchType = type;
    state.cameraReady = false;
    state.locationReady = false;
    state.punchCoords = null;

    var modal = $('#punchModal');
    var title = $('#punchModalTitle');
    var btn = $('#btnConfirmPunch');
    var errBox = $('#punchModalError');
    var camWrap = $('#punchCamWrap');
    var locNotice = $('#punchLocNotice');
    var hint = $('#punchNoticeHint');

    if (errBox) {
      errBox.style.display = 'none';
      errBox.textContent = '';
    }

    var camReq = isCameraRequired();
    var locReq = isLocationRequired();

    if (camWrap) camWrap.style.display = camReq ? 'block' : 'none';
    if (locNotice) locNotice.style.display = locReq ? 'flex' : 'none';
    if (hint) {
      if (camReq && locReq) hint.textContent = 'Live camera selfie and location are required to record your punch.';
      else if (camReq) hint.textContent = 'Live camera selfie is required to record your punch.';
      else if (locReq) hint.textContent = 'Live location is required to record your punch.';
      else hint.textContent = 'Click confirm to record your punch.';
    }

    var typeNames = {
      'in': 'Clock In',
      'break_start': 'Start Break',
      'break_end': 'End Break',
      'out': 'Clock Out'
    };
    var label = typeNames[type] || 'Punch';
    if (title) title.textContent = 'Record ' + label;
    if (btn) {
      btn.textContent = 'Confirm ' + label;
      btn.disabled = true;
    }

    modal.hidden = false;

    if (camReq) {
      startCamera();
    } else {
      state.cameraReady = true;
    }

    if (locReq) {
      requestPunchLocation();
    } else {
      state.locationReady = true;
    }

    checkPunchReady();
  }

  function closePunchModal() {
    stopCamera();
    var modal = $('#punchModal');
    if (modal) modal.hidden = true;
    state.pendingPunchType = null;
    state.punchCoords = null;
    state.cameraReady = false;
    state.locationReady = false;
    var btn = $('#btnConfirmPunch');
    if (btn) {
      btn.disabled = false;
      btn.textContent = 'Confirm Punch';
    }
  }

  function checkPunchReady() {
    var btn = $('#btnConfirmPunch');
    if (!btn) return;
    var camReq = isCameraRequired();
    var locReq = isLocationRequired();

    var camOk = !camReq || (state.cameraReady && state.cameraStream);
    var locOk = !locReq || (state.locationReady && state.punchCoords);

    btn.disabled = !(camOk && locOk);
  }

  function startCamera() {
    var video = $('#punchVideo');
    var notice = $('#camNotice');
    var errBox = $('#punchModalError');
    state.cameraReady = false;
    checkPunchReady();

    if (!video || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      if (notice) notice.textContent = 'Camera not supported on this device.';
      if (errBox) {
        errBox.textContent = 'Live camera is required to punch. Please access from a browser with camera support.';
        errBox.style.display = 'block';
      }
      return;
    }

    if (notice) notice.textContent = 'Accessing camera...';

    navigator.mediaDevices.getUserMedia({ video: { width: { ideal: 640 }, height: { ideal: 480 }, facingMode: 'user' }, audio: false })
      .then(function (stream) {
        state.cameraStream = stream;
        video.srcObject = stream;
        video.play().catch(function () {});
        state.cameraReady = true;
        if (notice) notice.textContent = 'Position your face in the camera frame.';
        checkPunchReady();
      })
      .catch(function (err) {
        console.warn('Camera access denied:', err);
        state.cameraReady = false;
        if (notice) notice.textContent = 'Camera access denied.';
        if (errBox) {
          errBox.textContent = 'Camera access is required. Please grant camera permission in your browser.';
          errBox.style.display = 'block';
        }
        checkPunchReady();
      });
  }

  function stopCamera() {
    if (state.cameraStream) {
      state.cameraStream.getTracks().forEach(function (track) { track.stop(); });
      state.cameraStream = null;
    }
    state.cameraReady = false;
  }

  function captureSelfie() {
    var video = $('#punchVideo');
    if (!video || !state.cameraStream) return null;
    try {
      var canvas = document.createElement('canvas');
      canvas.width = video.videoWidth || 480;
      canvas.height = video.videoHeight || 360;
      var ctx = canvas.getContext('2d');
      ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
      return canvas.toDataURL('image/jpeg', 0.85);
    } catch (e) {
      return null;
    }
  }

  function requestPunchLocation() {
    var dot = $('#punchLocDot');
    var txt = $('#punchLocStatus');
    var errBox = $('#punchModalError');
    state.locationReady = false;
    state.punchCoords = null;
    checkPunchReady();

    if (!navigator.geolocation) {
      if (dot) dot.style.background = 'var(--crit)';
      if (txt) txt.textContent = 'Geolocation not supported by this browser.';
      if (errBox) {
        errBox.textContent = 'GPS location is mandatory. Please access from a browser supporting geolocation.';
        errBox.style.display = 'block';
      }
      return;
    }

    if (dot) dot.style.background = 'var(--warn)';
    if (txt) txt.textContent = 'Acquiring GPS location...';

    navigator.geolocation.getCurrentPosition(
      function (pos) {
        state.punchCoords = {
          latitude: pos.coords.latitude,
          longitude: pos.coords.longitude,
          accuracy: pos.coords.accuracy
        };
        state.locationReady = true;
        if (dot) dot.style.background = 'var(--ok)';
        if (txt) txt.textContent = 'Location acquired (~' + Math.round(pos.coords.accuracy) + 'm accuracy)';
        checkPunchReady();
      },
      function (err) {
        state.locationReady = false;
        state.punchCoords = null;
        if (dot) dot.style.background = 'var(--crit)';
        var msg = 'Location access denied or unavailable.';
        if (err.code === 1) msg = 'Location permission denied. Please allow location access in your browser.';
        else if (err.code === 2) msg = 'Location unavailable. Please check your GPS/network.';
        else if (err.code === 3) msg = 'Location request timed out. Please retry.';
        if (txt) txt.textContent = msg;
        if (errBox) {
          errBox.textContent = msg + ' GPS location is mandatory to punch.';
          errBox.style.display = 'block';
        }
        checkPunchReady();
      },
      { enableHighAccuracy: true, timeout: 10000, maximumAge: 0 }
    );
  }

  async function executePunch() {
    var type = state.pendingPunchType;
    if (!type) return;

    var btn = $('#btnConfirmPunch');
    var errBox = $('#punchModalError');

    var camReq = isCameraRequired();
    var locReq = isLocationRequired();

    // Strict validation if required
    if (camReq && (!state.cameraReady || !state.cameraStream)) {
      var camErr = 'Live camera selfie is required to record your punch. Please grant camera permission.';
      if (errBox) { errBox.textContent = camErr; errBox.style.display = 'block'; }
      alert(camErr);
      return;
    }

    if (locReq && (!state.locationReady || !state.punchCoords)) {
      var locErr = 'Live GPS location is required to record your punch. Please grant location permissions.';
      if (errBox) { errBox.textContent = locErr; errBox.style.display = 'block'; }
      alert(locErr);
      return;
    }

    var selfieData = null;
    if (camReq) {
      selfieData = captureSelfie();
      if (!selfieData) {
        var snapErr = 'Failed to capture live camera snapshot. Please check your camera.';
        if (errBox) { errBox.textContent = snapErr; errBox.style.display = 'block'; }
        alert(snapErr);
        return;
      }
    }

    if (btn) {
      btn.disabled = true;
      btn.textContent = 'Verifying and recording...';
    }

    var payload = {
      type: type,
      selfie: selfieData || '',
      latitude: (locReq && state.punchCoords) ? state.punchCoords.latitude : '',
      longitude: (locReq && state.punchCoords) ? state.punchCoords.longitude : '',
      location_name: (locReq && state.punchCoords) ? ('Coordinates: ' + state.punchCoords.latitude.toFixed(4) + ', ' + state.punchCoords.longitude.toFixed(4)) : '',
      device_info: navigator.userAgent.slice(0, 100)
    };

    var res = await api('/api/attendance/punch', 'POST', payload);
    if (res && res.success) {
      closePunchModal();
      showToast('Punch recorded successfully.');
      state.latestPunch = res.latest_punch || null;
      renderPunchState(res.record, res.latest_punch, 'emp');
      renderPunchState(res.record, res.latest_punch, 'mgr');
      await loadDashboard();
      if (state.currentScreen === 'attn') await loadAttendanceData();
    } else {
      var errMsg = res && res.error ? res.error : 'Punch failed. Please try again.';
      if (errBox) {
        errBox.textContent = errMsg;
        errBox.style.display = 'block';
      }
      if (btn) {
        btn.disabled = false;
        btn.textContent = 'Confirm Punch';
      }
      alert(errMsg);
    }
  }

  // Shared helpers for the attendance and leave screens
  var ATTN_PAGE_SIZE = 30;
  var LEAVE_HISTORY_PAGE_SIZE = 5;

  function defaultScopeView() {
    return state.user && state.user.role === 'admin' ? 'all' : 'me';
  }

  function parseLocalDate(dateStr) {
    var p = String(dateStr).slice(0, 10).split('-');
    return new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1, parseInt(p[2], 10));
  }

  function formatDate(dateStr, opts) {
    return parseLocalDate(dateStr).toLocaleDateString('en-GB', opts);
  }

  function monthValue(d) {
    var m = d.getMonth() + 1;
    return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m;
  }

  function monthLabel(value) {
    return formatDate(value + '-01', { month: 'long', year: 'numeric' });
  }

  function durationStr(sec) {
    var s = parseInt(sec || 0, 10);
    var h = Math.floor(s / 3600);
    var m = Math.floor((s % 3600) / 60);
    return h > 0 ? h + 'h ' + (m < 10 ? '0' : '') + m + 'm' : m + 'm';
  }

  function personCell(name, subtitle, initials, color) {
    return '<td><div class="person"><span class="avatar sm" style="background:' + escapeHtml(color || 'var(--ink)') + '">' + escapeHtml(initials || 'TR') + '</span>' +
      '<div><div class="n">' + escapeHtml(name || 'Employee') + '</div><div class="e">' + escapeHtml(subtitle || '') + '</div></div></div></td>';
  }

  function emptyRow(cols, text) {
    return '<tr><td colspan="' + cols + '" style="text-align:center;color:var(--muted);padding:18px">' + escapeHtml(text) + '</td></tr>';
  }

  function renderPager(el, total, page, perPage, onPage) {
    var pages = Math.max(1, Math.ceil(total / perPage));
    var first = total === 0 ? 0 : (page - 1) * perPage + 1;
    var last = Math.min(total, page * perPage);
    el.innerHTML = '<span class="meta">' + first + ' to ' + last + ' of ' + total + '</span><div class="grow"></div>' +
      '<div class="rowbtns"><button class="btn xs" data-page="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>Previous</button>' +
      '<span class="meta">Page ' + page + ' of ' + pages + '</span>' +
      '<button class="btn xs" data-page="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>Next</button></div>';
    el.querySelectorAll('button[data-page]').forEach(function (b) {
      b.addEventListener('click', function () { onPage(parseInt(b.dataset.page, 10)); });
    });
  }

  async function getScopeOptions(view) {
    if (!state.scopeOptions[view]) {
      var res = await api('/api/attendance/people?scope=' + encodeURIComponent(view));
      state.scopeOptions[view] = (res && res.success) ? res : { people: [], teams: [] };
    }
    return state.scopeOptions[view];
  }

  function fillOptions(select, allLabel, items, selected, labelOf) {
    select.innerHTML = '<option value="all">' + allLabel + '</option>' + items.map(function (it) {
      return '<option value="' + it.id + '">' + escapeHtml(labelOf(it)) + '</option>';
    }).join('');
    select.value = selected;
    if (select.selectedIndex < 0) select.value = 'all';
    return select.value;
  }

  // Fills a team + employee filter pair. Returns the values that remain selected.
  function fillScopeFilters(teamSel, empSel, data, teamId, empId) {
    teamSel.hidden = data.teams.length === 0;
    teamId = fillOptions(teamSel, 'All teams', data.teams, teamId, function (t) { return t.name; });
    var people = data.people.filter(function (p) { return teamId === 'all' || String(p.team_id) === teamId; });
    empId = fillOptions(empSel, 'All employees', people, empId, function (p) {
      return p.name + (p.job_title ? ' (' + p.job_title + ')' : '');
    });
    return { teamId: teamId, empId: empId };
  }

  function selectedOptionName(select) {
    return select.selectedOptions[0] ? select.selectedOptions[0].textContent.replace(/ \(.*\)$/, '') : '';
  }

  // Attendance Screen
  state.attendanceView = 'me'; // 'me', 'team', 'all'
  state.attendanceTab = 'log'; // 'log', 'calendar', 'requests'
  state.attendanceTeamId = 'all';
  state.attendanceEmployeeId = 'all';
  state.attendanceFrom = '';
  state.attendanceTo = '';
  state.attendanceLogPage = 1;
  state.attendanceRequestsPage = 1;
  state.attendanceCalMonth = '';
  state.scopeOptions = {};
  state.attn24h = false;

  function attnScopeQuery() {
    var view = state.attendanceView;
    var q = ['scope=' + encodeURIComponent(view)];
    if (view !== 'me') {
      if (state.attendanceEmployeeId !== 'all') q.push('user_id=' + encodeURIComponent(state.attendanceEmployeeId));
      if (state.attendanceTeamId !== 'all') q.push('team_id=' + encodeURIComponent(state.attendanceTeamId));
    }
    return q;
  }

  async function loadAttendanceStats(period) {
    var p = period || ($('#selectAttnPeriod') ? $('#selectAttnPeriod').value : 'this_week');
    var view = state.attendanceView;
    var res = await api('/api/attendance/stats?' + attnScopeQuery().concat('period=' + encodeURIComponent(p)).join('&'));
    if (!res || !res.success) return;

    var hasPerson = view === 'me' || state.attendanceEmployeeId !== 'all';
    $('#attnStatRowMe').hidden = !hasPerson;
    if (hasPerson) {
      var empSel = $('#selectAttnEmployeeFilter');
      var name = view === 'me' ? 'Me' : selectedOptionName(empSel);
      $('#attnStatMeName').textContent = name;
      $('#attnStatMeAvatar').textContent = view === 'me'
        ? (res.me.initials || 'ME')
        : name.split(' ').map(function (w) { return w.charAt(0); }).join('').slice(0, 2).toUpperCase();
      $('#attnStatMeHours').textContent = res.me.avg_hours_str;
      $('#attnStatMeOnTime').textContent = res.me.on_time_pct !== null ? res.me.on_time_pct + '%' : '--';
    }

    $('#attnStatTeamName').textContent = view === 'all' ? 'All employees' : 'My team';
    $('#attnStatTeamHours').textContent = res.team.avg_hours_str;
    $('#attnStatTeamOnTime').textContent = res.team.on_time_pct !== null ? res.team.on_time_pct + '%' : '--';
  }

  function renderAttendanceMonthChips() {
    var container = $('#attnMonthChips');
    container.innerHTML = '';

    var chips = [{ label: '30 days', value: '' }];
    var now = new Date();
    for (var i = 1; i <= 4; i++) {
      var d = new Date(now.getFullYear(), now.getMonth() - i, 1);
      chips.push({ label: d.toLocaleDateString('en-GB', { month: 'short' }), value: monthValue(d) });
    }

    var hasRange = state.attendanceFrom || state.attendanceTo;
    chips.forEach(function (c) {
      var btn = document.createElement('button');
      btn.type = 'button';
      btn.className = !hasRange && state.selectedAttendanceMonth === c.value ? 'on' : '';
      btn.textContent = c.label;
      btn.addEventListener('click', function () {
        state.selectedAttendanceMonth = c.value;
        setAttendanceRange('', '');
      });
      container.appendChild(btn);
    });
  }

  function setAttendanceRange(from, to) {
    applyAttendanceRange(from, to);
    loadAttendanceTab();
  }

  // Stores a from/to range (blank clears it) without reloading.
  function applyAttendanceRange(from, to) {
    state.attendanceFrom = from;
    state.attendanceTo = to;
    if (from || to) state.selectedAttendanceMonth = '';
    state.attendanceLogPage = 1;
    state.attendanceRequestsPage = 1;
    $('#attnFrom').value = from;
    $('#attnTo').value = to;
    $('#attnTo').min = from;
  }

  function attendanceRangeTitle() {
    var from = state.attendanceFrom;
    var to = state.attendanceTo;
    var full = { day: 'numeric', month: 'short', year: 'numeric' };
    if (from && to) {
      return from === to ? formatDate(from, full) : formatDate(from, full) + ' to ' + formatDate(to, full);
    }
    if (from) return 'From ' + formatDate(from, full);
    if (to) return 'Until ' + formatDate(to, full);
    return state.selectedAttendanceMonth ? monthLabel(state.selectedAttendanceMonth) : 'Last 30 days';
  }

  // Sets up the screen for the current view: filters, personal actions, table columns.
  async function initAttendanceView() {
    var view = state.attendanceView;
    var isMe = view === 'me';

    $('#attnFilters').hidden = isMe;
    $$('[data-attn-me]').forEach(function (el) { el.hidden = !isMe; });
    $('#thAttnEmp').hidden = isMe;
    $('#thCorrEmp').hidden = isMe;

    if (!isMe) {
      var data = await getScopeOptions(view);
      var kept = fillScopeFilters($('#selectAttnTeamFilter'), $('#selectAttnEmployeeFilter'), data, state.attendanceTeamId, state.attendanceEmployeeId);
      state.attendanceTeamId = kept.teamId;
      state.attendanceEmployeeId = kept.empId;
    }
  }

  function setAttendanceTab(tab) {
    state.attendanceTab = tab;
    $$('[data-attn-tab]').forEach(function (b) { b.classList.toggle('on', b.dataset.attnTab === tab); });
    $('#attnPaneLog').hidden = tab !== 'log';
    $('#attnPaneCalendar').hidden = tab !== 'calendar';
    $('#attnPaneRequests').hidden = tab !== 'requests';
    $('#attnMonthChips').hidden = tab === 'calendar';
    $('#attnCalNav').hidden = tab !== 'calendar';
    $$('[data-attn-dates]').forEach(function (el) { el.hidden = tab === 'calendar'; });
    loadAttendanceTab();
  }

  async function loadAttendanceData() {
    await initAttendanceView();
    loadAttendanceStats();
    loadAttendanceTimings();
    loadAttendanceTab();
  }

  async function loadAttendanceTimings() {
    var isMe = state.attendanceView === 'me';
    var todayRes = await api('/api/attendance/today');
    if (!todayRes || !todayRes.success) return;

    var targetHours = parseFloat(todayRes.daily_target_hours || 9);
    var workWeek = (todayRes.work_week || 'Monday to Friday').toLowerCase();
    var todayRec = isMe ? todayRes.record : null;

    $('#timingShiftText').textContent = 'Today, ' + formatTimeStr(todayRes.shift_start_time || '10:00:00') + ' to ' + formatTimeStr(todayRes.shift_end_time || '19:00:00');

    var dayRow = $('#timingDaysRow');
    dayRow.innerHTML = '';
    var currentDayIndex = (new Date().getDay() + 6) % 7;
    ['M', 'T', 'W', 'T', 'F', 'S', 'S'].forEach(function (name, idx) {
      var span = document.createElement('span');
      span.textContent = name;
      var isWeeklyOff = workWeek.includes('friday') ? idx >= 5 : idx === 6;
      if (idx === currentDayIndex) span.className = 'today';
      else if (isWeeklyOff) span.className = 'off';
      span.title = isWeeklyOff ? 'Weekly off' : 'Work day';
      dayRow.appendChild(span);
    });

    var wSec = todayRec ? parseInt(todayRec.effective_work_seconds || todayRec.work_seconds || 0, 10) : 0;
    var bSec = todayRec ? parseInt(todayRec.break_seconds || 0, 10) : 0;
    var targetSec = targetHours * 3600;
    var wPct = Math.min(100, Math.round((wSec / targetSec) * 100));
    var bPct = Math.min(100 - wPct, Math.round((bSec / targetSec) * 100));

    $('#timingShiftBar').hidden = !isMe;
    $('#timingShiftBar').innerHTML = '<i class="work" style="width:' + wPct + '%"></i><i class="brk" style="width:' + bPct + '%"></i><i class="rest"></i>';
    $('#timingShiftMeta').innerHTML = isMe
      ? '<span>Duration ' + durationStr(wSec) + '</span><span>Break ' + durationStr(bSec) + '</span><span>Target ' + targetHours + 'h</span>'
      : '<span>Target ' + targetHours + 'h a day</span>';
  }

  function loadAttendanceTab() {
    if (state.attendanceTab === 'calendar') return loadAttendanceCalendar();
    renderAttendanceMonthChips();
    if (state.attendanceTab === 'requests') return loadAttendanceRequests();
    return loadAttendanceLog();
  }

  async function loadAttendanceLog() {
    var isMe = state.attendanceView === 'me';
    var cols = isMe ? 7 : 8;
    $('#attnRangeTitle').textContent = attendanceRangeTitle();

    var q = attnScopeQuery().concat('limit=' + ATTN_PAGE_SIZE, 'page=' + state.attendanceLogPage);
    if (state.selectedAttendanceMonth) q.push('month=' + encodeURIComponent(state.selectedAttendanceMonth));
    if (state.attendanceFrom) q.push('from=' + state.attendanceFrom);
    if (state.attendanceTo) q.push('to=' + state.attendanceTo);

    var res = await api('/api/attendance/logs?' + q.join('&'));
    var tbody = $('#attLogTableBody');
    var pager = $('#attnLogPager');
    if (!res || !res.success) {
      tbody.innerHTML = emptyRow(cols, (res && res.error) || 'Could not load attendance.');
      pager.hidden = true;
      return;
    }

    pager.hidden = res.total <= res.per_page;
    renderPager(pager, res.total, res.page, res.per_page, function (p) {
      state.attendanceLogPage = p;
      loadAttendanceLog();
    });

    if (!res.logs.length) {
      var noTeam = state.attendanceView === 'team' && !$('#selectAttnEmployeeFilter').options[1];
      tbody.innerHTML = emptyRow(cols, noTeam ? 'No one reports to you yet.' : 'No attendance logs found for this period.');
      return;
    }

    tbody.innerHTML = '';
    res.logs.forEach(function (l) {
      var tr = document.createElement('tr');
      var dateText = formatDate(l.date, { weekday: 'short', day: 'numeric', month: 'short' });
      var empCell = '';
      if (!isMe) {
        empCell = l.user_id == 0
          ? '<td style="color:var(--muted)">' + escapeHtml(l.user_name) + '</td>'
          : personCell(l.user_name, l.job_title, l.avatar_initials, l.avatar_color);
      }

      var offKind = !l.has_punches && { weekly_off: ['offrow', 'Weekly off'], on_leave: ['leaverow', 'Leave'], holiday: ['holrow', 'Holiday'] }[l.status];
      if (offKind) {
        tr.className = offKind[0];
        tr.innerHTML = empCell + '<td>' + dateText + ' <span class="tag">' + offKind[1] + '</span></td><td colspan="6">' + escapeHtml(l.notes || '') + '</td>';
        tbody.appendChild(tr);
        return;
      }

      var arrivalHtml = '<span class="ontime">' + escapeHtml(l.arrival_text || 'On time') + '</span>';
      if (l.arrival_status === 'late' || (l.is_late == 1 && !l.arrival_status)) {
        arrivalHtml = '<span class="late">' + escapeHtml(l.arrival_text || (l.late_minutes + ' min late')) + '</span>';
      } else if (l.arrival_status === 'outside_hours' || l.arrival_status === 'weekly_off' || l.arrival_status === 'holiday') {
        arrivalHtml = '<span class="neutral">' + escapeHtml(l.arrival_text) + '</span>';
      }

      var anomalyHtml = '';
      if (l.anomalies && l.anomalies.length > 0) {
        anomalyHtml = '<div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:4px">' + l.anomalies.map(function (a) {
          return '<span class="pill crit xs" title="' + escapeHtml(a.description || a.label) + '">' + escapeHtml(a.label) + '</span>';
        }).join('') + '</div>';
      }

      var leavePillHtml = l.show_leave_pill ? ' <span class="tag">' + escapeHtml(l.leave_label || 'Leave') + '</span>' : '';

      var inPct = l.visual_in_pct || 0;
      var outPct = l.visual_out_pct || 0;
      var segHtml = (l.visual_segments || []).map(function (s) {
        return '<i' + (s.type === 'break' ? ' class="b"' : '') + ' style="left:' + s.start_pct + '%;width:' + s.width_pct + '%"></i>';
      }).join('');
      var avTitle = l.first_in ? 'In ' + formatTimeStr(l.first_in) + (l.last_out ? ', out ' + formatTimeStr(l.last_out) : '') : '';

      var isToday = (l.date === new Date().toISOString().slice(0, 10));
      var statusPill = '<span class="ok-ic"><svg class="i"><use href="#i-check"/></svg></span>';
      if (l.current_status === 'clocked_in' || l.current_status === 'on_break') {
        if (isToday) {
          statusPill = '<span class="pill ok plain">Active</span>';
        } else {
          statusPill = '<span class="pill crit plain">Missing clock-out</span>';
        }
      }

      tr.innerHTML = empCell + '<td>' + dateText + leavePillHtml + anomalyHtml + '</td>' +
        '<td><div class="av" title="' + avTitle + '" style="--in:' + inPct + '%;--out:' + outPct + '%">' + segHtml + '</div></td>' +
        '<td class="num r">' + durationStr(l.work_seconds) + '</td>' +
        '<td class="num r">' + durationStr(l.break_seconds) + '</td>' +
        '<td class="num r">' + durationStr(l.gross_seconds) + '</td>' +
        '<td>' + arrivalHtml + '</td>' +
        '<td class="r">' + statusPill + '</td>';
      tbody.appendChild(tr);
    });
  }

  function attendanceCalMonth() {
    if (state.attendanceCalMonth) return state.attendanceCalMonth;
    if (state.selectedAttendanceMonth) return state.selectedAttendanceMonth;
    if (state.attendanceFrom) return state.attendanceFrom.slice(0, 7);
    return monthValue(new Date());
  }

  function stepAttendanceCalMonth(delta) {
    var d = parseLocalDate(attendanceCalMonth() + '-01');
    state.attendanceCalMonth = monthValue(new Date(d.getFullYear(), d.getMonth() + delta, 1));
    loadAttendanceCalendar();
  }

  function calendarDayText(day, single, people) {
    if (day.status === 'present') {
      if (!single) return day.present + ' of ' + people + ' present';
      return (day.work_str || 'Present') + (day.late ? ' · Late' : '');
    }
    if (day.status === 'leave') return single ? (day.label || 'Leave') : day.leave + ' on leave';
    if (day.status === 'holiday') return day.label || 'Holiday';
    if (day.status === 'weekly_off') return 'Weekly off';
    if (day.status === 'absent') return single ? 'Absent' : 'No one in';
    return '';
  }

  function calendarDayTitle(day, single) {
    if (day.status !== 'present') return '';
    if (!single) {
      return [day.late ? day.late + ' late' : '', day.leave ? day.leave + ' on leave' : ''].filter(Boolean).join(', ');
    }
    var title = 'In ' + formatTimeStr(day.first_in);
    if (day.last_out) title += ', out ' + formatTimeStr(day.last_out);
    if (day.late_minutes) title += ', ' + day.late_minutes + ' min late';
    return title;
  }

  async function loadAttendanceCalendar() {
    var month = attendanceCalMonth();
    $('#attnRangeTitle').textContent = monthLabel(month);

    var res = await api('/api/attendance/calendar?' + attnScopeQuery().concat('month=' + month).join('&'));
    if (month !== attendanceCalMonth()) return;
    var grid = $('#attnCalGrid');
    grid.innerHTML = '';
    if (!res || !res.success) {
      $('#attnCalSummary').textContent = (res && res.error) || 'Could not load the calendar.';
      return;
    }

    var single = res.people === 1;
    var today = new Date();
    var todayStr = monthValue(today) + '-' + (today.getDate() < 10 ? '0' : '') + today.getDate();
    var leadingBlanks = (parseLocalDate(month + '-01').getDay() + 6) % 7;
    for (var i = 0; i < leadingBlanks; i++) {
      var pad = document.createElement('div');
      pad.className = 'calday pad';
      grid.appendChild(pad);
    }

    var counts = { present: 0, leave: 0, absent: 0 };
    res.days.forEach(function (day) {
      var openable = day.status === 'present' || day.status === 'leave';
      var cell = document.createElement(openable ? 'button' : 'div');
      if (openable) cell.type = 'button';
      cell.className = 'calday ' + day.status + (day.date === todayStr ? ' today' : '');
      cell.title = calendarDayTitle(day, single);
      cell.innerHTML = '<span class="d num">' + parseInt(day.date.slice(8), 10) + '</span><span class="s">' + escapeHtml(calendarDayText(day, single, res.people)) + '</span>';
      if (openable) {
        cell.addEventListener('click', function () {
          state.attendanceCalMonth = '';
          applyAttendanceRange(day.date, day.date);
          setAttendanceTab('log');
        });
      }
      grid.appendChild(cell);
      if (counts[day.status] !== undefined) counts[day.status]++;
    });

    var dayWord = function (n) { return n + (n === 1 ? ' day' : ' days'); };
    $('#attnCalSummary').textContent = res.people === 0
      ? 'No employees to show'
      : dayWord(counts.present) + ' present, ' + dayWord(counts.leave) + ' on leave, ' + dayWord(counts.absent) + ' absent';
  }

  async function loadAttendanceRequests() {
    var isMe = state.attendanceView === 'me';
    var cols = isMe ? 7 : 8;
    $('#attnRangeTitle').textContent = 'Correction requests';

    var q = attnScopeQuery().concat('limit=25', 'page=' + state.attendanceRequestsPage);
    var from = state.attendanceFrom;
    var to = state.attendanceTo;
    if (state.selectedAttendanceMonth) {
      var d = parseLocalDate(state.selectedAttendanceMonth + '-01');
      from = state.selectedAttendanceMonth + '-01';
      to = state.selectedAttendanceMonth + '-' + new Date(d.getFullYear(), d.getMonth() + 1, 0).getDate();
    }
    if (from) q.push('from=' + from);
    if (to) q.push('to=' + to);

    var res = await api('/api/attendance/correction/list?' + q.join('&'));
    var tbody = $('#attnRequestsBody');
    var pager = $('#attnReqPager');
    if (!res || !res.success) {
      tbody.innerHTML = emptyRow(cols, (res && res.error) || 'Could not load requests.');
      pager.hidden = true;
      return;
    }

    pager.hidden = res.total <= res.per_page;
    renderPager(pager, res.total, res.page, res.per_page, function (p) {
      state.attendanceRequestsPage = p;
      loadAttendanceRequests();
    });

    if (!res.corrections.length) {
      tbody.innerHTML = emptyRow(cols, 'No correction requests found.');
      return;
    }

    var canApprove = !isMe && (!state.permissions || state.permissions.approve_corrections !== false || state.user.role === 'admin');
    tbody.innerHTML = res.corrections.map(function (c) {
      var pill = { approved: 'ok', rejected: 'crit', pending: 'warn' }[c.status] || 'neutral';
      var actions = c.status === 'pending' && canApprove
        ? '<div class="rowbtns" style="justify-content:flex-end"><button class="btn xs btn-action-correction" data-id="' + c.id + '" data-act="rejected">Reject</button>' +
          '<button class="btn xs ok btn-action-correction" data-id="' + c.id + '" data-act="approved">Approve</button></div>'
        : '';
      return '<tr>' + (isMe ? '' : personCell(c.employee_name, c.job_title, c.avatar_initials, c.avatar_color)) +
        '<td>' + formatDate(c.date, { weekday: 'short', day: 'numeric', month: 'short' }) + '</td>' +
        '<td class="num">' + (c.requested_in ? formatTimeStr(c.requested_in) : '-') + '</td>' +
        '<td class="num">' + (c.requested_out ? formatTimeStr(c.requested_out) : '-') + '</td>' +
        '<td style="color:var(--muted);max-width:28ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + escapeHtml(c.reason) + '">' + escapeHtml(c.reason) + '</td>' +
        '<td><span class="pill ' + pill + '">' + ucfirst(c.status) + '</span></td>' +
        '<td>' + escapeHtml(c.approver_name || '-') + '</td>' +
        '<td class="r">' + actions + '</td></tr>';
    }).join('');
  }

  function formatSecondsToHms(sec) {
    var s = parseInt(sec || 0, 10);
    if (s <= 0) return '0h 00m';
    var h = Math.floor(s / 3600);
    var m = Math.floor((s % 3600) / 60);
    var remS = s % 60;
    if (h > 0) {
      return h + 'h ' + (m < 10 ? '0' : '') + m + 'm';
    }
    if (m > 0) {
      return remS > 0 ? m + 'm ' + remS + 's' : '0h ' + (m < 10 ? '0' : '') + m + 'm';
    }
    return remS + 's';
  }

  // Leave Screen
  state.leaveView = 'me'; // 'me', 'team', 'all'
  state.leaveTeamId = 'all';
  state.leaveEmployeeId = 'all';
  state.leaveFrom = '';
  state.leaveTo = '';
  state.leaveHistoryPage = 1;

  function leaveTypeLabel(code) {
    return ucfirst(String(code || '').replace(/_/g, ' '));
  }

  function formatLeaveDates(start, end) {
    var full = { day: 'numeric', month: 'short', year: 'numeric' };
    if (start === end) return formatDate(start, full);
    if (start.slice(0, 7) === end.slice(0, 7)) {
      return parseLocalDate(start).getDate() + ' to ' + formatDate(end, full);
    }
    if (start.slice(0, 4) === end.slice(0, 4)) {
      return formatDate(start, { day: 'numeric', month: 'short' }) + ' to ' + formatDate(end, full);
    }
    return formatDate(start, full) + ' to ' + formatDate(end, full);
  }

  function leaveScopeQuery() {
    var view = state.leaveView;
    var q = ['scope=' + encodeURIComponent(view)];
    if (view === 'me') {
      var yr = $('#selectLeavePendingYear').value;
      if (yr) q.push('year=' + encodeURIComponent(yr));
    } else {
      if (state.leaveEmployeeId !== 'all') q.push('user_id=' + encodeURIComponent(state.leaveEmployeeId));
      if (state.leaveTeamId !== 'all') q.push('team_id=' + encodeURIComponent(state.leaveTeamId));
      if (state.leaveFrom) q.push('from=' + state.leaveFrom);
      if (state.leaveTo) q.push('to=' + state.leaveTo);
    }
    return q;
  }

  // The person whose balances and stats are shown: 'me', an employee id, or null for none.
  function leaveSubject() {
    if (state.leaveView === 'me') return 'me';
    return state.leaveEmployeeId !== 'all' ? state.leaveEmployeeId : null;
  }

  async function initLeaveView() {
    var view = state.leaveView;
    var isMe = view === 'me';

    $('#leaveFilters').hidden = isMe;
    $$('[data-leave-me]').forEach(function (el) { el.hidden = !isMe; });
    if (!isMe) $('#leaveForm').hidden = true;
    $('#selectLeavePendingYear').hidden = !isMe;
    $('#thPendEmp').hidden = isMe;
    $('#thHistEmp').hidden = isMe;

    if (!isMe) {
      var data = await getScopeOptions(view);
      var kept = fillScopeFilters($('#selectLeaveTeamFilter'), $('#selectLeaveEmployeeFilter'), data, state.leaveTeamId, state.leaveEmployeeId);
      state.leaveTeamId = kept.teamId;
      state.leaveEmployeeId = kept.empId;
    }

    var subject = leaveSubject();
    var subjectName = subject && subject !== 'me' ? selectedOptionName($('#selectLeaveEmployeeFilter')) : '';
    $('#leaveStatsBlock').hidden = !subject;
    $('#leaveBalanceBlock').hidden = !subject;
    $('#leaveStatsTitle').textContent = subjectName ? 'Leave stats for ' + subjectName : 'My leave stats';
    $('#leaveBalanceTitle').textContent = subjectName ? 'Leave balances for ' + subjectName : 'Leave balances';
    $('#leaveHistoryTitle').textContent = isMe ? 'Leave history' : 'All leave requests';
  }

  async function loadLeaveData() {
    await initLeaveView();
    await loadLeaveBalances();
    loadPendingLeave();
    loadLeaveHistory();
  }

  function renderLeaveBalanceCards(cards) {
    var container = $('#leaveBalanceCardsContainer');
    if (!cards || !cards.length) {
      container.innerHTML = '<div style="color:var(--muted);padding:14px;grid-column:1/-1">No leave balance data available.</div>';
      return;
    }

    var circumference = 314;
    container.innerHTML = cards.map(function (c) {
      var avail = parseFloat(c.available || 0);
      var used = parseFloat(c.used || 0);
      var quota = parseFloat(c.quota || 0);
      var carried = parseFloat(c.carried || 0);
      var entitled = quota + carried;
      var offset = Math.round(circumference * (1 - (entitled > 0 ? Math.min(1, avail / entitled) : 0)));
      var middleLabel = c.code === 'earned' ? 'Carried over' : 'Accrued';
      var middleValue = c.code === 'earned' ? carried : parseFloat(c.accrued || 0);

      return '<div class="panel lb"><div class="hd"><h3>' + escapeHtml(c.name) + '</h3><div class="grow"></div>' +
        '<a href="#" class="link-leave-details" data-code="' + escapeHtml(c.code) + '" style="font-size:12.5px;color:var(--accent);font-weight:600;text-decoration:none">Details</a></div>' +
        '<div class="bd" style="display:grid;place-items:center;padding-block:22px"><div class="donut"><svg viewBox="0 0 120 120"><circle class="bg" cx="60" cy="60" r="50"/>' +
        '<circle class="fg" cx="60" cy="60" r="50" stroke-dasharray="' + circumference + '" stroke-dashoffset="' + offset + '" style="stroke:' + c.color + '"/></svg>' +
        '<div class="c"><b class="num">' + avail + '</b><span>days left</span></div></div></div>' +
        '<div class="lbfoot"><div><span>Available</span><b class="num">' + avail + ' days</b></div><div><span>Used</span><b class="num">' + used + ' days</b></div>' +
        '<div><span>' + middleLabel + '</span><b class="num">' + middleValue + ' days</b></div><div><span>Annual quota</span><b class="num">' + quota + ' days</b></div></div></div>';
    }).join('');
  }

  async function loadLeaveBalances() {
    var subject = leaveSubject();
    var q = [];
    var yr = $('#selectLeavePendingYear').value;
    if (subject === 'me' && yr) q.push('year=' + encodeURIComponent(yr));
    if (subject && subject !== 'me') q.push('user_id=' + encodeURIComponent(subject));

    var res = await api(subject ? '/api/leave/balances?' + q.join('&') : '/api/leave/types');
    if (!res || !res.success) return;

    if (res.years && $('#selectLeavePendingYear').options.length === 0) {
      var currentY = new Date().getFullYear();
      $('#selectLeavePendingYear').innerHTML = '<option value="">All years</option>' + res.years.map(function (y) {
        return '<option value="' + y + '">Jan ' + y + ' to Dec ' + y + '</option>';
      }).join('');
      $('#selectLeavePendingYear').value = currentY;
    }

    if (res.types && res.types.length) {
      var typeSel = $('#selectLeaveHistoryType');
      if (typeSel.options.length <= 1) {
        typeSel.innerHTML = '<option value="all">All types</option>' + res.types.map(function (t) {
          return '<option value="' + escapeHtml(t.code) + '">' + escapeHtml(t.name) + '</option>';
        }).join('');
      }
      if (subject === 'me') fillLeaveFormTypes(res);
    }

    if (subject) {
      $('#leaveBalanceYear').textContent = res.balances.year;
      renderLeaveBalanceCards(res.balances.cards);
    }
  }

  function fillLeaveFormTypes(res) {
    var balMap = {};
    if (res.balances && res.balances.cards) {
      res.balances.cards.forEach(function (c) { balMap[c.code] = parseFloat(c.available) || 0; });
    }
    var prevLt = $('#lt').value;
    $('#lt').innerHTML = res.types.filter(function (t) { return t.is_active == 1; }).map(function (t) {
      var quotaVal = (t.days_per_year !== undefined && t.days_per_year !== null) ? t.days_per_year : t.yearly_quota;
      var qText = (quotaVal !== undefined && quotaVal !== null) ? (parseFloat(quotaVal) + '/yr') : 'Credit';
      var avail = balMap[t.code] !== undefined ? balMap[t.code] : null;
      var availText = avail !== null ? (' - ' + avail + ' available') : '';
      return '<option value="' + escapeHtml(t.code) + '" data-available="' + (avail !== null ? avail : 999) + '">' +
        escapeHtml(t.name) + ' (' + qText + availText + ')</option>';
    }).join('');
    if (prevLt && $('#lt').querySelector('option[value="' + prevLt + '"]')) {
      $('#lt').value = prevLt;
    }
    updateLeaveFormHint();
  }

  async function loadPendingLeave() {
    var isMe = state.leaveView === 'me';
    var cols = isMe ? 6 : 7;
    var res = await api('/api/leave/requests?' + leaveScopeQuery().concat('status=pending', 'limit=100').join('&'));
    var tbody = $('#pendingLeaveTableBody');
    if (!res || !res.success) {
      tbody.innerHTML = emptyRow(cols, (res && res.error) || 'Could not load pending requests.');
      return;
    }
    if (!res.requests.length) {
      tbody.innerHTML = emptyRow(cols, 'No pending leave requests');
      return;
    }

    var canApprove = !isMe && (!state.permissions || state.permissions.approve_leaves !== false || state.user.role === 'admin');
    tbody.innerHTML = res.requests.map(function (h) {
      var actions = '';
      if (isMe) {
        actions = '<button class="btn xs quiet btn-withdraw-leave" data-id="' + h.id + '">Withdraw</button>';
      } else if (canApprove) {
        actions = '<div class="rowbtns" style="justify-content:flex-end"><button class="btn xs btn-action-leave" data-id="' + h.id + '" data-act="rejected">Reject</button>' +
          '<button class="btn xs ok btn-action-leave" data-id="' + h.id + '" data-act="approved">Approve</button></div>';
      }
      var sameDay = h.start_date === h.end_date;
      var dates = sameDay ? formatDate(h.start_date, { weekday: 'short', day: 'numeric', month: 'short' }) : formatLeaveDates(h.start_date, h.end_date);
      return '<tr>' + (isMe ? '' : personCell(h.employee_name, h.job_title, h.avatar_initials, h.avatar_color)) +
        '<td>' + dates + '<div class="hint" style="max-width:28ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">' + escapeHtml(h.reason || '') + '</div></td>' +
        '<td>' + escapeHtml(leaveTypeLabel(h.leave_type)) + '</td>' +
        '<td class="num r">' + parseFloat(h.days_count) + '</td>' +
        '<td>' + escapeHtml(h.manager_name || 'Admin') + '</td>' +
        '<td><span class="pill warn">Awaiting approval</span></td>' +
        '<td class="r">' + actions + '</td></tr>';
    }).join('');
  }

  async function loadLeaveHistory() {
    var isMe = state.leaveView === 'me';
    var cols = isMe ? 7 : 8;
    var type = $('#selectLeaveHistoryType').value;
    var status = $('#selectLeaveHistoryStatus').value;
    var search = $('#leaveHistorySearch').value.trim();

    var q = leaveScopeQuery().concat('limit=' + LEAVE_HISTORY_PAGE_SIZE, 'page=' + state.leaveHistoryPage);
    if (type !== 'all') q.push('type=' + encodeURIComponent(type));
    if (status !== 'any') q.push('status=' + encodeURIComponent(status));
    if (search) q.push('q=' + encodeURIComponent(search));

    var res = await api('/api/leave/requests?' + q.join('&'));
    var tbody = $('#leaveHistoryTableBody');
    if (!res || !res.success) {
      tbody.innerHTML = emptyRow(cols, (res && res.error) || 'Could not load leave history.');
      return;
    }

    $('#leaveHistoryCount').textContent = res.total + ' total';
    renderPager($('#leaveHistoryPager'), res.total, res.page, res.per_page, function (p) {
      state.leaveHistoryPage = p;
      loadLeaveHistory();
    });

    if (!res.requests.length) {
      tbody.innerHTML = emptyRow(cols, 'No leave history records found.');
      return;
    }

    tbody.innerHTML = res.requests.map(function (h) {
      var pill = { approved: 'ok', rejected: 'crit', pending: 'warn' }[h.status] || 'neutral';
      var days = parseFloat(h.days_count);
      var note = (h.status === 'rejected' && h.rejection_reason) ? h.rejection_reason : (h.reason || '');
      var withdraw = isMe && h.status === 'pending' ? '<button class="btn xs quiet btn-withdraw-leave" data-id="' + h.id + '">Withdraw</button>' : '';
      return '<tr>' + (isMe ? '' : personCell(h.employee_name, h.job_title, h.avatar_initials, h.avatar_color)) +
        '<td><div class="n" style="font-weight:600">' + formatLeaveDates(h.start_date, h.end_date) + '</div>' +
        '<div class="hint">' + days + (days === 1 ? ' day' : ' days') + ' · requested ' + formatDate(h.created_at, { day: 'numeric', month: 'short' }) + '</div></td>' +
        '<td>' + escapeHtml(leaveTypeLabel(h.leave_type)) + '</td>' +
        '<td><span class="pill ' + pill + '">' + ucfirst(h.status) + '</span></td>' +
        '<td>' + escapeHtml(h.approver_name || '-') + '</td>' +
        '<td>' + (h.actioned_at ? formatDate(h.actioned_at, { day: 'numeric', month: 'short' }) : '-') + '</td>' +
        '<td style="color:var(--muted);max-width:28ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="' + escapeHtml(note) + '">' + escapeHtml(note) + '</td>' +
        '<td class="r">' + withdraw + '</td></tr>';
    }).join('');
  }

  // Live Board Loader
  async function loadLiveBoard() {
    var res = await api('/api/liveboard');
    if (res && res.success && $('#liveBoardCards')) {
      var container = $('#liveBoardCards');
      container.innerHTML = '';
      var b = res.board;

      if ($('#boardWorkingCount')) $('#boardWorkingCount').textContent = b.counts.working;
      if ($('#boardIdleCount')) $('#boardIdleCount').textContent = b.counts.idle;
      if ($('#boardBreakCount')) $('#boardBreakCount').textContent = b.counts.break;
      if ($('#boardOfflineCount')) $('#boardOfflineCount').textContent = b.counts.offline || 0;
      if ($('#boardNotInCount')) $('#boardNotInCount').textContent = b.counts.not_in;

      if (!b.cards || b.cards.length === 0) {
        container.innerHTML = '<div style="color:var(--muted);padding:18px;grid-column:1/-1">No live board records available.</div>';
        return;
      }

      b.cards.forEach(function (c) {
        var card = document.createElement('div');
        card.className = 'card-person';
        var inText = c.first_in ? 'In at <b class="num">' + c.first_in + '</b>' : 'Not in';
        var workText = c.on_leave ? 'On leave' : 'Today <b class="num">' + c.work_time_str + '</b>';

        var anomalyHtml = '';
        if (c.anomalies && c.anomalies.length > 0) {
          anomalyHtml = '<div style="display:flex;gap:4px;flex-wrap:wrap;margin:4px 0">' +
            c.anomalies.map(function (a) {
              return '<span class="pill crit xs" title="' + (a.description || a.label) + '">' + a.label + '</span>';
            }).join('') +
            '</div>';
        }

        card.innerHTML = '<div class="hd">' +
          '<span class="avatar" style="background:' + (c.avatar_color || 'var(--ink)') + '">' + (c.avatar_initials || 'TR') + '</span>' +
          '<div><div class="nm">' + c.name + '</div><div class="rl">' + (c.department || '') + '</div></div>' +
          '<span class="pill ' + c.pill_class + '" style="margin-left:auto">' + c.status + '</span>' +
          '</div>' +
          anomalyHtml +
          '<div class="ft"><span>' + inText + '</span><span>' + workText + '</span></div>';
        container.appendChild(card);
      });
    }
  }

  // Approvals Loader
  async function loadApprovals() {
    var canApproveLeaves = !state.permissions || state.permissions.approve_leaves !== false || (state.user && state.user.role === 'admin');
    var canApproveCompOff = !state.permissions || state.permissions.approve_compoff !== false || (state.user && state.user.role === 'admin');

    var res = await api('/api/leave/pending');
    if (res && res.success && $('#leaveApprovalsBody')) {
      var tbody = $('#leaveApprovalsBody');
      tbody.innerHTML = '';
      if (!res.requests || res.requests.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--muted);padding:24px;">No pending leave requests.</td></tr>';
      } else {
        res.requests.forEach(function (r) {
          var tr = document.createElement('tr');
          var actionHtml = canApproveLeaves
            ? '<button class="btn xs btn-action-leave" data-id="' + r.id + '" data-act="rejected">Reject</button>' +
              '<button class="btn xs ok btn-action-leave" data-id="' + r.id + '" data-act="approved">Approve</button>'
            : '<span class="pill xs" style="color:var(--muted)">View only</span>';

          tr.innerHTML = '<td><div class="person"><span class="avatar sm" style="background:' + (r.avatar_color || 'var(--ink)') + '">' + r.avatar_initials + '</span><div><div class="n">' + r.employee_name + '</div><div class="e">' + r.department + '</div></div></div></td>' +
            '<td>' + ucfirst(r.leave_type) + '</td>' +
            '<td>' + r.start_date + ' to ' + r.end_date + '</td>' +
            '<td class="num r">' + r.days_count + '</td>' +
            '<td class="num r">' + r.days_count + '</td>' +
            '<td style="color:var(--muted)">' + (r.reason || '') + '</td>' +
            '<td><div class="rowbtns" style="justify-content:flex-end">' + actionHtml + '</div></td>';
          tbody.appendChild(tr);
        });
      }
    }

    // Load Comp-off pending requests
    var cRes = await api('/api/leave/comp-off-pending');
    if (cRes && cRes.success && $('#compOffApprovalsBody')) {
      var ctbody = $('#compOffApprovalsBody');
      ctbody.innerHTML = '';
      if (!cRes.requests || cRes.requests.length === 0) {
        ctbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px;">No pending comp-off credit requests.</td></tr>';
      } else {
        cRes.requests.forEach(function (r) {
          var tr = document.createElement('tr');
          var compActionHtml = canApproveCompOff
            ? '<button class="btn xs btn-action-compoff" data-id="' + r.id + '" data-act="rejected">Reject</button>' +
              '<button class="btn xs ok btn-action-compoff" data-id="' + r.id + '" data-act="approved">Approve</button>'
            : '<span class="pill xs" style="color:var(--muted)">View only</span>';

          tr.innerHTML = '<td><div class="person"><span class="avatar sm" style="background:' + (r.avatar_color || 'var(--ink)') + '">' + (r.avatar_initials || 'TR') + '</span><div><div class="n">' + r.employee_name + '</div><div class="e">' + (r.department || '') + '</div></div></div></td>' +
            '<td>' + r.worked_date + '</td>' +
            '<td class="num r">' + r.hours_worked + '</td>' +
            '<td style="color:var(--muted)">' + (r.reason || '') + '</td>' +
            '<td><div class="rowbtns" style="justify-content:flex-end">' + compActionHtml + '</div></td>';
          ctbody.appendChild(tr);
        });
      }
    }
  }

  // Reports Loader
  async function loadReports(period = 'this_week') {
    var res = await api('/api/reports?period=' + period);
    if (res && res.success && $('#reportsTableBody')) {
      var tbody = $('#reportsTableBody');
      tbody.innerHTML = '';
      var rep = res.report;

      if ($('#repTeamHours')) $('#repTeamHours').textContent = rep.summary.total_hours_str;
      if ($('#repAvgPerson')) $('#repAvgPerson').textContent = rep.summary.avg_per_person_str;
      if ($('#repAttendanceRate')) $('#repAttendanceRate').textContent = rep.summary.attendance_pct + '%';
      if ($('#repLateMarks')) $('#repLateMarks').textContent = rep.summary.total_late;

      if (!rep.employees || rep.employees.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--muted);padding:16px">No report records found for this period.</td></tr>';
        return;
      }

      rep.employees.forEach(function (e) {
        var tr = document.createElement('tr');
        var anomalyHtml = '';
        if (e.anomalies && e.anomalies.length > 0) {
          anomalyHtml = '<div style="display:flex;gap:4px;flex-wrap:wrap;margin-top:4px">' +
            e.anomalies.map(function (a) {
              return '<span class="pill crit xs" title="' + (a.description || a.label) + '">' + a.label + '</span>';
            }).join('') +
            '</div>';
        }

        tr.innerHTML = '<td><div class="person"><span class="avatar sm" style="background:' + (e.avatar_color || 'var(--ink)') + '">' + e.avatar_initials + '</span><div><div class="n">' + e.name + '</div>' + anomalyHtml + '</div></div></td>' +
          '<td class="num r">' + e.days + '</td>' +
          '<td class="num r">' + e.worked_str + '</td>' +
          '<td class="num r">' + e.avg_per_day_str + '</td>' +
          '<td class="num r">' + e.break_str + '</td>' +
          '<td class="num r">' + e.late_count + '</td>' +
          '<td class="num r">' + e.overtime_str + '</td>';
        tbody.appendChild(tr);
      });
    }
  }

  // Community Feed Loader
  async function loadCommunityFeed(specificContainer) {
    var res = await api('/api/community/feed');
    var targets = specificContainer ? [specificContainer] : ['#communityPostsContainer', '#empFeedContainer', '#mgrFeedContainer'];
    var basePath = (window.APP_CONFIG && window.APP_CONFIG.basePath) ? window.APP_CONFIG.basePath : '';

    targets.forEach(function (tId) {
      var container = $(tId);
      if (!container) return;
      container.innerHTML = '';

      if (!res || !res.success || !res.posts || res.posts.length === 0) {
        container.innerHTML = '<div style="text-align:center;color:var(--muted);padding:24px">No posts yet. Start the conversation!</div>';
        return;
      }

      res.posts.forEach(function (p) {
        var postDiv = document.createElement('div');
        var isPraise = p.type === 'praise';
        postDiv.className = 'post' + (p.is_pinned ? ' pinned' : '') + (isPraise ? ' praise' : '');

        var imgHtml = p.image_path ? '<img class="pimg" src="' + basePath + '/' + p.image_path.replace(/^\//, '') + '" alt="">' : '';
        var pinnedBadge = p.is_pinned ? '<span class="pill accent plain" style="margin-left:auto">Pinned</span>' : '';

        var praiseHeader = '';
        if (isPraise && p.recipient_name) {
          praiseHeader = '<div style="display:inline-flex;align-items:center;gap:6px;background:var(--accent-soft);color:var(--accent);padding:3px 8px;border-radius:4px;font-size:12px;font-weight:700;margin-bottom:8px">' +
            '<svg class="i" style="width:13px;height:13px"><use href="#i-heart"/></svg>Praise for ' + p.recipient_name + '</div>';
        }

        // If poll, render poll options
        var pollHtml = '';
        if (p.poll && p.poll.options) {
          pollHtml = '<div class="poll" style="margin-top:12px">';
          p.poll.options.forEach(function (opt) {
            var isUserVote = opt.user_voted > 0;
            var votedTag = isUserVote ? '<span class="pill accent xs" style="padding:1px 6px;font-size:10px;margin-left:6px;font-weight:700">Your vote</span>' : '';
            pollHtml += '<div class="opt btn-vote-poll' + (isUserVote ? ' on' : '') + '" data-poll-id="' + p.poll.id + '" data-option-id="' + opt.id + '" style="cursor:pointer;position:relative">' +
              '<i style="width:' + opt.percentage + '%"></i>' +
              '<span style="display:inline-flex;align-items:center"><b>' + opt.option_text + '</b>' + votedTag + '</span>' +
              '<span class="num" style="color:var(--muted);font-weight:600">' + opt.percentage + '% (' + (opt.votes_count || 0) + ')</span>' +
              '</div>';
          });
          pollHtml += '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:6px;font-size:12px;color:var(--muted)">' +
            '<span>' + (p.poll.total_votes || 0) + ' total ' + ((p.poll.total_votes === 1) ? 'vote' : 'votes') + '</span>' +
            '<span style="font-size:11.5px">' + (p.poll.options.some(function(o){ return o.user_voted > 0; }) ? 'You have voted' : 'Click any option to vote') + '</span>' +
            '</div>';
          pollHtml += '</div>';
        }

        postDiv.innerHTML = '<div class="ph">' +
          '<span class="avatar sm" style="background:' + (p.avatar_color || 'var(--ink)') + '">' + (p.avatar_initials || 'TR') + '</span>' +
          '<div><div style="font-weight:600;font-size:13.5px">' + p.author_name + '</div>' +
          '<div style="font-size:12px;color:var(--muted)">' + (p.title || p.department || 'Update') + '</div></div>' +
          pinnedBadge +
          '</div>' +
          '<div class="pb">' + praiseHeader + '<div>' + p.content + '</div>' + pollHtml + '</div>' +
          imgHtml +
          '<div class="pf">' +
          '<button class="btn-like-post" data-id="' + p.id + '">' +
          '<svg class="i" style="width:15px;height:15px"><use href="#i-heart"/></svg><span class="like-cnt">' + p.likes_count + '</span>' +
          '</button>' +
          '<button><svg class="i" style="width:15px;height:15px"><use href="#i-comment"/></svg>' + p.comments_count + '</button>' +
          '</div>';

        container.appendChild(postDiv);
      });
    });
  }

  // Employees Loader
  async function loadEmployees() {
    var res = await api('/api/employees');
    if (res && res.success && $('#employeesTableBody')) {
      var tbody = $('#employeesTableBody');
      tbody.innerHTML = '';
      if ($('#employeeActiveCount')) {
        var cnt = res.count !== undefined ? res.count : (res.employees ? res.employees.length : 0);
        $('#employeeActiveCount').textContent = cnt + ' active';
      }

      if (!res.employees || res.employees.length === 0) {
        tbody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--muted);padding:16px">No employees found.</td></tr>';
        return;
      }

      var basePath = (window.APP_CONFIG && window.APP_CONFIG.basePath) ? window.APP_CONFIG.basePath : '';
      res.employees.forEach(function (e) {
        var tr = document.createElement('tr');
        var pill = e.status === 'active' ? '<span class="pill ok">Active</span>' : '<span class="pill crit">' + ucfirst(e.status) + '</span>';

        tr.innerHTML = '<td><div class="person"><span class="avatar sm" style="background:' + (e.avatar_color || 'var(--ink)') + '">' + e.avatar_initials + '</span><div><div class="n">' + e.name + '</div><div class="e">' + e.email + '</div></div></div></td>' +
          '<td>' + (e.job_title || '') + '</td>' +
          '<td>' + (e.team_name || e.department || '') + '</td>' +
          '<td>' + (e.manager_name || '-') + '</td>' +
          '<td>' + (e.joined_date || '') + '</td>' +
          '<td>' + pill + '</td>' +
          '<td class="r">' +
          '<a href="' + basePath + '/view.php?id=' + e.id + '" class="btn xs quiet" style="margin-right:4px;text-decoration:none">View</a>' +
          '<a href="' + basePath + '/edit.php?id=' + e.id + '" class="btn xs quiet" style="text-decoration:none">Edit</a>' +
          '</td>';
        tbody.appendChild(tr);
      });
      loadTeammatesForPraise();
    }
  }

  // Active Devices Loader
  async function loadActiveDevices() {
    var res = await api('/api/auth/devices');
    if (res && res.success && $('#deviceListContainer')) {
      var container = $('#deviceListContainer');
      container.innerHTML = '';
      if (!res.devices || res.devices.length === 0) {
        container.innerHTML = '<div style="padding:12px;color:var(--muted)">No active devices found.</div>';
        return;
      }
      res.devices.forEach(function (d) {
        var row = document.createElement('div');
        row.className = 'setting';
        var currentBadge = d.is_current ? '<span class="pill ok sm">Current device</span>' : '';
        var newBadge = d.is_new ? '<span class="pill warn sm">New device</span>' : '';
        var screenInfo = d.screen ? ' : ' + d.screen : '';
        row.innerHTML = '<div>' +
          '<div class="t">' + (d.browser || 'Browser') + ' on ' + (d.os || 'Device') + ' ' + currentBadge + ' ' + newBadge + '</div>' +
          '<div class="m">IP: ' + (d.ip || 'Unknown') + screenInfo + ' : Last active: ' + (d.last_seen_at || d.created_at) + '</div>' +
          '</div>';
        container.appendChild(row);
      });
    }
  }

  // Leave Stats Loader
  async function loadLeaveStats() {
    var subject = leaveSubject();
    if (!subject) return;
    var res = await api('/api/leave/stats' + (subject === 'me' ? '' : '?user_id=' + encodeURIComponent(subject)));
    if (!res || !res.success) return;
    var st = res.stats;

    // 1. Weekly pattern bars
    var dayKeys = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    var maxVal = 1;
    dayKeys.forEach(function (k) {
      if ((st.weekly_pattern && st.weekly_pattern[k] || 0) > maxVal) maxVal = st.weekly_pattern[k];
    });

    dayKeys.forEach(function (k) {
      var bar = $('#leaveBar' + k);
      if (bar) {
        var val = st.weekly_pattern ? (st.weekly_pattern[k] || 0) : 0;
        if (val === 0) {
          bar.setAttribute('height', '4');
          bar.setAttribute('y', '82');
          bar.className.baseVal = 'bar empty';
        } else {
          var h = Math.max(8, Math.min(60, Math.round((val / maxVal) * 55)));
          bar.setAttribute('height', h);
          bar.setAttribute('y', 86 - h);
          bar.className.baseVal = 'bar';
        }
      }
    });

    if ($('#leaveWeeklyHint')) {
      if (st.most_taken_days) {
        $('#leaveWeeklyHint').textContent = 'Most leave is taken on ' + st.most_taken_days + '.';
      } else {
        $('#leaveWeeklyHint').textContent = 'No leave records for this period.';
      }
    }

    // 2. Leave by type donut
    if (st.by_type) {
      var cCount = parseFloat(st.by_type.casual || 0);
      var eCount = parseFloat(st.by_type.earned || 0);
      var sCount = parseFloat(st.by_type.sick || 0);
      var totalType = cCount + eCount + sCount;

      if ($('#leaveStatTypeCasual')) $('#leaveStatTypeCasual').textContent = cCount;
      if ($('#leaveStatTypeEarned')) $('#leaveStatTypeEarned').textContent = eCount;
      if ($('#leaveStatTypeSick')) $('#leaveStatTypeSick').textContent = sCount;

      var circ = 239; // 2 * PI * 38
      if (totalType > 0) {
        var cDash = Math.round((cCount / totalType) * circ);
        var eDash = Math.round((eCount / totalType) * circ);
        var sDash = Math.max(0, circ - cDash - eDash);

        if ($('#donutCircleCasual')) $('#donutCircleCasual').setAttribute('stroke-dasharray', cDash + ' ' + (circ - cDash));
        if ($('#donutCircleEarned')) {
          $('#donutCircleEarned').setAttribute('stroke-dasharray', eDash + ' ' + (circ - eDash));
          $('#donutCircleEarned').setAttribute('stroke-dashoffset', -cDash);
        }
        if ($('#donutCircleSick')) {
          $('#donutCircleSick').setAttribute('stroke-dasharray', sDash + ' ' + (circ - sDash));
          $('#donutCircleSick').setAttribute('stroke-dashoffset', -(cDash + eDash));
        }
      } else {
        if ($('#donutCircleCasual')) $('#donutCircleCasual').setAttribute('stroke-dasharray', '0 ' + circ);
        if ($('#donutCircleEarned')) {
          $('#donutCircleEarned').setAttribute('stroke-dasharray', '0 ' + circ);
          $('#donutCircleEarned').setAttribute('stroke-dashoffset', '0');
        }
        if ($('#donutCircleSick')) {
          $('#donutCircleSick').setAttribute('stroke-dasharray', '0 ' + circ);
          $('#donutCircleSick').setAttribute('stroke-dashoffset', '0');
        }
      }
    }

    // 3. Monthly bar chart
    if (st.monthly) {
      var maxM = 1;
      for (var m = 1; m <= 12; m++) {
        if ((st.monthly[m] || 0) > maxM) maxM = st.monthly[m];
      }
      for (var mi = 1; mi <= 12; mi++) {
        var mbar = $('#mbar-' + mi);
        if (mbar) {
          var mv = st.monthly[mi] || 0;
          if (mv === 0) {
            mbar.setAttribute('height', '4');
            mbar.setAttribute('y', '82');
            mbar.className.baseVal = 'bar empty';
          } else {
            var mh = Math.max(8, Math.min(55, Math.round((mv / maxM) * 50)));
            mbar.setAttribute('height', mh);
            mbar.setAttribute('y', 86 - mh);
            mbar.className.baseVal = 'bar';
          }
        }
      }
    }
    if ($('#leaveYearlyMeta')) {
      $('#leaveYearlyMeta').textContent = (st.total_days_year || 0) + ' days in ' + new Date().getFullYear();
    }
  }

  // Holiday Calendar Loader
  async function loadHolidays() {
    var res = await api('/api/holidays');
    if (res && res.success && $('#holidayTableBody')) {
      var tbody = $('#holidayTableBody');
      tbody.innerHTML = '';
      var isAdmin = state.user && state.user.role === 'admin';
      var adminTh = $$('th[data-admin]');
      adminTh.forEach(function (th) { th.hidden = !isAdmin; });

      var adminControls = $('#holidayAdminControls');
      if (adminControls) adminControls.hidden = !isAdmin;

      // Populate holiday type select options if empty
      var selType = $('#newHolidayType');
      if (selType && selType.options.length === 0) {
        for (var k in HOLIDAY_TYPES) {
          var opt = document.createElement('option');
          opt.value = k;
          opt.textContent = HOLIDAY_TYPES[k];
          selType.appendChild(opt);
        }
      }

      if (!res.holidays || res.holidays.length === 0) {
        tbody.innerHTML = '<tr><td colspan="' + (isAdmin ? 5 : 4) + '" style="text-align:center;color:var(--muted);padding:16px">No holidays configured for ' + new Date().getFullYear() + '.</td></tr>';
        return;
      }

      res.holidays.forEach(function (h) {
        var tr = document.createElement('tr');
        var d = new Date(h.date + 'T00:00:00');
        var dayName = d.toLocaleDateString('en-US', { weekday: 'long' });
        var dateFormatted = d.toLocaleDateString('en-US', { day: 'numeric', month: 'short', year: 'numeric' });
        var typePill = h.type === 'gazetted' ? '<span class="pill ok sm">Gazetted</span>' : '<span class="pill sm">Restricted</span>';
        var actionTd = isAdmin
          ? '<td class="r"><button class="btn xs danger btn-delete-holiday" data-id="' + h.id + '">Delete</button></td>'
          : '<td class="r" hidden></td>';

        tr.innerHTML = '<td><b>' + h.name + '</b></td>' +
          '<td>' + dateFormatted + '</td>' +
          '<td>' + dayName + '</td>' +
          '<td>' + typePill + '</td>' +
          actionTd;
        tbody.appendChild(tr);
      });
    }
  }

  // Celebrations Loader
  async function loadCelebrations(specificTarget) {
    var res = await api('/api/celebrations');
    var targets = specificTarget ? [specificTarget] : ['#celebrationsList', '#empCelebrationsList', '#mgrCelebrationsList'];
    targets.forEach(function (tId) {
      var list = $(tId);
      if (!list) return;
      list.innerHTML = '';
      if (!res || !res.success || !res.celebrations || res.celebrations.length === 0) {
        list.innerHTML = '<div style="color:var(--muted);padding:8px">No upcoming celebrations in the next 30 days.</div>';
        return;
      }
      res.celebrations.forEach(function (c) {
        var item = document.createElement('div');
        var wishBtn = (c.days_away === 0 || c.days_away === 1)
          ? '<button class="btn xs btn-wish" data-name="' + c.name + '" data-type="' + c.type + '" style="margin-top:6px">Wish</button>'
          : '';
        var color = c.type === 'birthday' ? 'var(--warn)' : 'var(--ok)';

        item.innerHTML = '<span class="avatar lg" style="background:' + color + '">' + (c.avatar_initials || 'TR') + '</span>' +
          '<div class="n">' + c.name + '</div>' +
          '<div class="d">' + c.date_text + '</div>' +
          wishBtn;
        list.appendChild(item);
      });
    });
  }

  // Upcoming Events Loader
  async function loadUpcomingEvents() {
    var res = await api('/api/events');
    if (res && res.success && $('#upcomingEventsList')) {
      var container = $('#upcomingEventsList');
      container.innerHTML = '';
      if (!res.events || res.events.length === 0) {
        container.innerHTML = '<div style="color:var(--muted);padding:8px">No upcoming events scheduled.</div>';
        return;
      }
      res.events.forEach(function (e) {
        var d = new Date(e.event_date + 'T00:00:00');
        var day = d.getDate();
        var month = d.toLocaleDateString('en-US', { month: 'short' });
        var linkHtml = e.link ? ' : <a href="' + e.link + '" target="_blank" style="color:var(--accent);text-decoration:none">' + e.link + '</a>' : '';

        var item = document.createElement('div');
        item.className = 'ev';
        item.innerHTML = '<div class="date"><b class="num">' + day + '</b><span>' + month + '</span></div>' +
          '<div><div class="t">' + e.title + '</div><div class="m">' + (e.event_time || '') + linkHtml + '</div></div>';
        container.appendChild(item);
      });
    }
  }

  // Sidebar Poll Loader
  async function loadSidebarPoll() {
    var res = await api('/api/community/active-poll');
    var optContainer = $('#sidebarPollOptions');
    var emptyEl = $('#sidebarPollEmpty');

    if (res && res.success && res.poll) {
      var p = res.poll;
      if ($('#sidebarPollPanel')) $('#sidebarPollPanel').style.display = 'block';
      if ($('#sidebarPollMeta')) $('#sidebarPollMeta').textContent = (p.total_votes || 0) + ' ' + ((p.total_votes === 1) ? 'vote' : 'votes');
      if ($('#sidebarPollQuestion')) $('#sidebarPollQuestion').textContent = p.title || p.question;
      if (emptyEl) emptyEl.style.display = 'none';

      if (optContainer && p.options) {
        optContainer.style.display = 'flex';
        optContainer.innerHTML = '';
        p.options.forEach(function (opt) {
          var isUserVote = opt.user_voted > 0;
          var votedTag = isUserVote ? '<span class="pill accent xs" style="padding:1px 5px;font-size:10px;margin-left:6px;font-weight:700">Voted</span>' : '';
          var div = document.createElement('div');
          div.className = 'opt btn-vote-poll' + (isUserVote ? ' on' : '');
          div.dataset.pollId = p.id;
          div.dataset.optionId = opt.id;
          div.style.cursor = 'pointer';
          div.style.position = 'relative';
          div.innerHTML = '<i style="width:' + opt.percentage + '%"></i>' +
            '<span style="display:inline-flex;align-items:center"><b>' + opt.option_text + '</b>' + votedTag + '</span>' +
            '<span class="num" style="color:var(--muted);font-weight:600">' + opt.percentage + '%</span>';
          optContainer.appendChild(div);
        });
      }
    } else {
      if ($('#sidebarPollMeta')) $('#sidebarPollMeta').textContent = '0 votes';
      if ($('#sidebarPollQuestion')) $('#sidebarPollQuestion').textContent = 'Community Poll';
      if (optContainer) optContainer.innerHTML = '';
      if (emptyEl) emptyEl.style.display = 'block';
    }
  }

  // Teams Loader
  async function loadTeams() {
    var res = await api('/api/teams');
    if (res && res.success && $('#teamsTableBody')) {
      var tbody = $('#teamsTableBody');
      tbody.innerHTML = '';
      if ($('#teamsCountMeta')) $('#teamsCountMeta').textContent = res.teams.length + ' teams';

      // Update team select in add employee form
      var teamSelect = $('#newEmpTeam');
      if (teamSelect) {
        teamSelect.innerHTML = '<option value="">No team assigned</option>';
        res.teams.forEach(function (t) {
          var opt = document.createElement('option');
          opt.value = t.id;
          opt.textContent = t.name;
          teamSelect.appendChild(opt);
        });
      }

      // Update department select in add employee form from real teams
      var deptSelect = $('#newEmpDept');
      if (deptSelect) {
        deptSelect.innerHTML = '<option value="">Select department...</option>';
        var depts = [];
        res.teams.forEach(function (t) {
          if (t.name && !depts.includes(t.name)) depts.push(t.name);
        });
        if (depts.length === 0) {
          depts = ['Engineering', 'Design', 'Marketing', 'Product', 'Human Resources', 'Sales'];
        }
        depts.forEach(function (d) {
          var opt = document.createElement('option');
          opt.value = d;
          opt.textContent = d;
          deptSelect.appendChild(opt);
        });
      }

      var basePath = (window.APP_CONFIG && window.APP_CONFIG.basePath) ? window.APP_CONFIG.basePath : '';
      res.teams.forEach(function (t) {
        var tr = document.createElement('tr');
        tr.innerHTML = '<td><b>' + t.name + '</b></td>' +
          '<td>' + (t.manager_name || '<span style="color:var(--muted)">Unassigned</span>') + '</td>' +
          '<td class="num r">' + t.members_count + ' members</td>' +
          '<td class="r">' +
          '<a href="' + basePath + '/view.php?type=team&id=' + t.id + '" class="btn xs quiet" style="margin-right:4px;text-decoration:none">View</a>' +
          '<a href="' + basePath + '/edit.php?type=team&id=' + t.id + '" class="btn xs quiet" style="margin-right:4px;text-decoration:none">Edit</a>' +
          '<button class="btn xs danger btn-delete-team" data-id="' + t.id + '">Delete</button>' +
          '</td>';
        tbody.appendChild(tr);
      });
    }
  }

  // Teammates for Praise Picker
  async function loadTeammatesForPraise() {
    var res = await api('/api/teammates');
    if (res && res.success && res.teammates) {
      ['#praiseTeammateEmp', '#praiseTeammateMgr', '#praiseTeammateHome', '#praiseTeammateHub', '#newTeamManager'].forEach(function (selId) {
        var sel = $(selId);
        if (sel) {
          var defaultText = selId === '#newTeamManager' ? 'Select team manager (optional)...' : 'Select teammate to praise...';
          sel.innerHTML = '<option value="">' + defaultText + '</option>';
          res.teammates.forEach(function (e) {
            var opt = document.createElement('option');
            opt.value = e.id;
            opt.textContent = e.name + ' (' + (e.role || e.department || 'Staff') + ')';
            sel.appendChild(opt);
          });
        }
      });
    }
  }

  // Settings Loader
  async function loadSettings() {
    var res = await api('/api/settings');
    if (res && res.success) {
      var s = res.settings || {};
      if ($('#settShiftStart')) $('#settShiftStart').value = s.shift_start_time ? s.shift_start_time.slice(0, 5) : '10:00';
      if ($('#settShiftEnd')) $('#settShiftEnd').value = s.shift_end_time ? s.shift_end_time.slice(0, 5) : '19:00';
      if ($('#settWorkWeek')) $('#settWorkWeek').value = s.work_week !== undefined && s.work_week !== null ? s.work_week : '';
      if ($('#settDailyTarget')) $('#settDailyTarget').value = s.daily_target_hours !== undefined && s.daily_target_hours !== null ? s.daily_target_hours : '';
      if ($('#settGraceMin')) $('#settGraceMin').value = s.late_grace_minutes !== undefined && s.late_grace_minutes !== null ? s.late_grace_minutes : '';
      if ($('#settLateFine')) $('#settLateFine').checked = s.late_fine_enabled === '1';
      if ($('#settAutoClockOut')) $('#settAutoClockOut').value = s.auto_clock_out_time !== undefined && s.auto_clock_out_time !== null ? s.auto_clock_out_time : '';

      if ($('#settMgrApproval')) $('#settMgrApproval').checked = s.manager_approval_required === '1';
      if ($('#settCarryForward')) $('#settCarryForward').checked = s.carry_forward_leave === '1';

      if ($('#settSelfiePunch')) $('#settSelfiePunch').checked = s.selfie_on_punch === '1';
      if ($('#settLocationPunch')) $('#settLocationPunch').checked = s.location_on_punch === '1';
      if ($('#settAnomalyThresholdKm')) $('#settAnomalyThresholdKm').value = s.anomaly_distance_threshold_km !== undefined && s.anomaly_distance_threshold_km !== null ? s.anomaly_distance_threshold_km : '100';
      if ($('#settDeleteSelfieDays')) $('#settDeleteSelfieDays').value = s.delete_selfies_after_days !== undefined && s.delete_selfies_after_days !== null ? s.delete_selfies_after_days : '';
    }
    loadSettingsLeaveTypes();
  }

  // Leave Policy Settings Table Loader
  async function loadSettingsLeaveTypes() {
    var res = await api('/api/leave-types/all');
    if (res && res.success && $('#settingsLeaveTypesBody')) {
      var tbody = $('#settingsLeaveTypesBody');
      tbody.innerHTML = '';
      if (!res.types || res.types.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:var(--muted);padding:14px">No leave types configured.</td></tr>';
        return;
      }
      res.types.forEach(function (t) {
        var tr = document.createElement('tr');
        var statusPill = t.is_active == 1 ? '<span class="pill ok sm">Active</span>' : '<span class="pill crit sm">Disabled</span>';
        var daysNum = (t.days_per_year !== undefined && t.days_per_year !== null) ? t.days_per_year : (t.yearly_quota || 0);
        var quotaText = parseFloat(daysNum) > 0 ? parseFloat(daysNum) + ' d' : (t.code === 'comp_off' ? 'Credit' : '0 d');
        var jsonStr = JSON.stringify(t).replace(/'/g, '&apos;');
        tr.innerHTML = '<td><b>' + t.name + '</b></td>' +
          '<td><code>' + t.code + '</code></td>' +
          '<td class="num r">' + quotaText + '</td>' +
          '<td>' + statusPill + '</td>' +
          '<td class="r">' +
          '<button class="btn xs quiet btn-edit-leavetype" data-json=\'' + jsonStr + '\' style="margin-right:6px">Edit</button>' +
          '<button class="btn xs danger btn-delete-leavetype" data-id="' + t.id + '" data-name="' + t.name + '">Delete</button>' +
          '</td>';
        tbody.appendChild(tr);
      });
    }
  }

  // Attendance Policy Modal Populator
  function openAttnPolicyModal() {
    var s = state.publicSettings || {};
    if ($('#attnPolicyShiftText')) {
      $('#attnPolicyShiftText').textContent = 'Work hours: ' + (s.shift_start_time ? formatTimeStr(s.shift_start_time) : '10:00 AM') + ' to ' + (s.shift_end_time ? formatTimeStr(s.shift_end_time) : '7:00 PM') + ' (Target ' + (s.daily_target_hours || '9') + ' hours).';
    }
    if ($('#attnPolicyWorkWeek')) {
      $('#attnPolicyWorkWeek').textContent = 'Working days: ' + (s.work_week || 'Monday to Friday') + '.';
    }
    if ($('#attnPolicyGraceText')) {
      $('#attnPolicyGraceText').textContent = 'A grace period of ' + (s.late_grace_minutes || '15') + ' minutes is allowed after shift start. Clocks after the grace period are marked late.';
    }
    if ($('#attnPolicyAutoText')) {
      $('#attnPolicyAutoText').textContent = 'Forgotten open sessions are closed automatically at ' + (s.auto_clock_out_time || '23:59:00') + '.';
    }
    $('#attendancePolicyModal').hidden = false;
  }

  // Leave Policy Modal Populator
  async function openLeavePolicyModal() {
    var res = await api('/api/leave/types');
    if (res && res.success && $('#leavePolicyList')) {
      var list = $('#leavePolicyList');
      list.innerHTML = '';
      if (res.types && res.types.length) {
        res.types.forEach(function (t) {
          var item = document.createElement('div');
          var qText = t.yearly_quota !== null ? t.yearly_quota + ' days per year' : 'Credit-based';
          var desc = t.description ? ' (' + t.description + ')' : '';
          item.innerHTML = '• <b>' + t.name + '</b>: ' + qText + desc;
          list.appendChild(item);
        });
      } else {
        list.textContent = 'Standard leave policy applies.';
      }
    }
    $('#leavePolicyModal').hidden = false;
  }

  // Audit Log Loader
  async function loadAuditLogs() {
    var res = await api('/api/audit');
    if (res && res.success && $('#auditTableBody')) {
      var tbody = $('#auditTableBody');
      tbody.innerHTML = '';
      res.logs.forEach(function (l) {
        var tr = document.createElement('tr');
        var newStr = l.new_values ? JSON.stringify(JSON.parse(l.new_values)) : '-';
        tr.innerHTML = '<td><span class="num">' + l.created_at + '</span></td>' +
          '<td><b>' + (l.user_name || 'System') + '</b></td>' +
          '<td><span class="pill accent plain">' + l.action + '</span></td>' +
          '<td>' + l.target_type + ' #' + (l.target_id || '') + '</td>' +
          '<td style="font-family:monospace;font-size:11px;max-width:35ch;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">' + newStr + '</td>';
        tbody.appendChild(tr);
      });
    }
  }

  // ==================== MY FINANCES MODULE ====================
  var financeState = {
    selectedFy: null,
    selectedSlipId: null,
    data: null
  };

  async function loadFinances(fy, payslipId) {
    var params = [];
    if (fy) params.push('fy=' + encodeURIComponent(fy));
    if (payslipId) params.push('payslip_id=' + encodeURIComponent(payslipId));
    var qs = params.length ? '?' + params.join('&') : '';

    var res = await api('/api/finances' + qs);
    if (!res || !res.success) {
      return;
    }
    financeState.data = res;
    financeState.selectedFy = res.selected_fy;

    var cur = res.currency || '₹';

    // Top cards
    var tc = res.top_cards || {};
    if ($('#finNetMonth')) $('#finNetMonth').textContent = tc.latest_month || 'latest';
    if ($('#finNetPay')) $('#finNetPay').textContent = cur + (tc.latest_net || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
    if ($('#finGross')) $('#finGross').textContent = cur + (tc.latest_gross || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
    if ($('#finDeductions')) $('#finDeductions').textContent = cur + (tc.latest_deductions || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
    if ($('#finNextPayday')) $('#finNextPayday').textContent = tc.next_payday || '--';
    if ($('#finPaydayRemaining')) $('#finPaydayRemaining').textContent = tc.days_remaining !== undefined ? ('in ' + tc.days_remaining + ' days') : '--';

    // Month selector in breakdown
    var mSel = $('#finMonthSelect');
    if (mSel) {
      mSel.innerHTML = '';
      if (res.fy_payslips && res.fy_payslips.length > 0) {
        res.fy_payslips.forEach(function (s) {
          var opt = document.createElement('option');
          opt.value = s.id;
          var mName = new Date(s.year, s.month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
          opt.textContent = mName;
          if (res.selected_payslip && s.id === res.selected_payslip.id) {
            opt.selected = true;
          }
          mSel.appendChild(opt);
        });
      } else {
        mSel.innerHTML = '<option value="">No published payslips</option>';
      }
    }

    // Breakdown tables
    var slip = res.selected_payslip;
    var earnBody = $('#finEarningsBody');
    var dedBody = $('#finDeductionsBody');
    var pdfBtn = $('#finBtnDownloadPdf');

    if (slip) {
      var mName = new Date(slip.year, slip.month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
      if ($('#finBreakdownTitle')) $('#finBreakdownTitle').textContent = mName + ' breakdown';
      if (pdfBtn) {
        pdfBtn.disabled = false;
        pdfBtn.onclick = function () {
          var apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) ? window.APP_CONFIG.apiBase : '/api';
          window.open(apiBase + '/finances/payslip-pdf?id=' + slip.id);
        };
      }

      if (earnBody) {
        earnBody.innerHTML = '';
        var earnings = slip.earnings || [];
        earnings.forEach(function (e) {
          var tr = document.createElement('tr');
          tr.innerHTML = '<td>' + escapeHtml(e.name) + '</td><td class="num r">' + cur + (parseFloat(e.amount) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</td>';
          earnBody.appendChild(tr);
        });

        // Earning adjustments if any
        var adjs = slip.adjustments || [];
        adjs.forEach(function (adj) {
          if ((adj.type || 'earning') === 'earning') {
            var tr = document.createElement('tr');
            tr.style.color = 'var(--ok)';
            tr.innerHTML = '<td>' + escapeHtml(adj.name || 'Adjustment') + ' (Adj)</td><td class="num r">+' + cur + (parseFloat(adj.amount) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</td>';
            earnBody.appendChild(tr);
          }
        });

        var trGross = document.createElement('tr');
        trGross.style.fontWeight = '600';
        trGross.innerHTML = '<td>Gross earnings</td><td class="num r">' + cur + (parseFloat(slip.gross) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</td>';
        earnBody.appendChild(trGross);
      }

      if (dedBody) {
        dedBody.innerHTML = '';
        var deductions = slip.deductions || [];
        deductions.forEach(function (d) {
          var tr = document.createElement('tr');
          tr.innerHTML = '<td>' + escapeHtml(d.name) + '</td><td class="num r">' + cur + (parseFloat(d.amount) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</td>';
          dedBody.appendChild(tr);
        });

        if (parseFloat(slip.lop_amount) > 0) {
          var trLop = document.createElement('tr');
          trLop.style.color = 'var(--crit)';
          trLop.innerHTML = '<td>Loss of Pay (LOP ' + slip.lop_days + 'd)</td><td class="num r">' + cur + (parseFloat(slip.lop_amount) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</td>';
          dedBody.appendChild(trLop);
        }

        // Deduction adjustments
        adjs.forEach(function (adj) {
          if (adj.type === 'deduction') {
            var tr = document.createElement('tr');
            tr.style.color = 'var(--crit)';
            tr.innerHTML = '<td>' + escapeHtml(adj.name || 'Adjustment') + ' (Adj)</td><td class="num r">+' + cur + (parseFloat(adj.amount) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</td>';
            dedBody.appendChild(tr);
          }
        });

        var trTotDed = document.createElement('tr');
        trTotDed.style.fontWeight = '600';
        var totalDeductionsWithLop = (parseFloat(slip.total_deductions) || 0) + (parseFloat(slip.lop_amount) || 0);
        trTotDed.innerHTML = '<td>Total deductions</td><td class="num r">' + cur + totalDeductionsWithLop.toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</td>';
        dedBody.appendChild(trTotDed);

        var trNet = document.createElement('tr');
        trNet.style.fontWeight = '700';
        trNet.innerHTML = '<td>Net pay</td><td class="num r" style="color:var(--ok)">' + cur + (parseFloat(slip.net_pay) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</td>';
        dedBody.appendChild(trNet);
      }
    } else {
      if ($('#finBreakdownTitle')) $('#finBreakdownTitle').textContent = 'Month breakdown';
      if (pdfBtn) pdfBtn.disabled = true;
      if (earnBody) earnBody.innerHTML = '<tr><td colspan="2" class="empty-state" style="text-align:center;padding:24px;color:var(--muted)">No payslip records published yet.</td></tr>';
      if (dedBody) dedBody.innerHTML = '<tr><td colspan="2" class="empty-state" style="text-align:center;padding:24px;color:var(--muted)">No deductions records.</td></tr>';
    }

    // FY Dropdown
    var fySel = $('#finFySelect');
    if (fySel && res.available_fys) {
      fySel.innerHTML = '';
      Object.keys(res.available_fys).forEach(function (k) {
        var opt = document.createElement('option');
        opt.value = k;
        opt.textContent = res.available_fys[k];
        if (parseInt(k, 10) === parseInt(res.selected_fy, 10)) {
          opt.selected = true;
        }
        fySel.appendChild(opt);
      });
    }

    // Payslips list
    var listBody = $('#finPayslipsListBody');
    if (listBody) {
      listBody.innerHTML = '';
      if (res.fy_payslips && res.fy_payslips.length > 0) {
        res.fy_payslips.forEach(function (s) {
          var tr = document.createElement('tr');
          var mName = new Date(s.year, s.month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
          tr.innerHTML = '<td>' + mName + '</td>' +
            '<td class="num r">' + cur + (parseFloat(s.net_pay) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</td>' +
            '<td class="r"><button class="btn xs download-slip-btn" data-id="' + s.id + '" title="Download PDF"><svg class="i" style="width:14px;height:14px"><use href="#i-download"/></svg></button></td>';
          listBody.appendChild(tr);
        });
      } else {
        listBody.innerHTML = '<tr><td colspan="3" class="empty-state" style="text-align:center;padding:18px;color:var(--muted)">No payslips published for this financial year.</td></tr>';
      }
    }

    // Tax Card
    var ts = res.tax_summary || {};
    var tdsLabel = ts.tds_label || 'TDS';
    if ($('#finTaxTdsLabel')) $('#finTaxTdsLabel').textContent = tdsLabel;
    if ($('#finTaxMonthsLabel')) $('#finTaxMonthsLabel').textContent = (ts.months_completed || 0) + ' months deducted so far in FY';
    if ($('#finTaxDeducted')) $('#finTaxDeducted').textContent = cur + (ts.deducted_so_far || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
    if ($('#finTaxProjected')) $('#finTaxProjected').textContent = cur + (ts.projected_total || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 });
  }

  // ==================== ADMIN PAYROLL MODULE ====================
  var payrollState = {
    selectedRunId: null,
    data: null,
    activeTab: 'run',
    components: [],
    structures: [],
    settings: {},
    selectedEmployeeIds: new Set()
  };

  window.switchPayrollTab = function (tab) {
    payrollState.activeTab = tab;
    var tabs = ['run', 'components', 'structures', 'settings'];
    tabs.forEach(function (t) {
      var btn = $('#payrollSubTab' + t.charAt(0).toUpperCase() + t.slice(1));
      var pane = $('#payrollPane' + t.charAt(0).toUpperCase() + t.slice(1));
      if (btn) btn.classList.toggle('on', t === tab);
      if (pane) pane.style.display = (t === tab) ? 'flex' : 'none';
    });

    if (tab === 'run') loadPayroll(payrollState.selectedRunId);
    if (tab === 'components') loadSalaryComponents();
    if (tab === 'structures') loadSalaryStructures();
    if (tab === 'settings') loadPayrollSettings();
  };

  async function loadPayroll(runId) {
    var qs = runId ? ('?run_id=' + encodeURIComponent(runId)) : '';
    var res = await api('/api/payroll' + qs);
    if (!res || !res.success) {
      return;
    }
    payrollState.data = res;
    payrollState.settings = res.settings || {};
    var cur = res.settings && res.settings.currency_symbol ? res.settings.currency_symbol : '₹';

    var runs = res.runs || [];
    var selRun = res.selected_run;
    var payslips = res.payslips || [];

    // Populate month picker
    var mPicker = $('#payrollMonthPicker');
    if (mPicker) {
      mPicker.innerHTML = '';
      if (runs.length > 0) {
        runs.forEach(function (r) {
          var opt = document.createElement('option');
          opt.value = r.id;
          var mName = new Date(r.year, r.month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
          opt.textContent = mName + ' (' + r.status.toUpperCase() + ')';
          if (selRun && r.id === selRun.id) {
            opt.selected = true;
          }
          mPicker.appendChild(opt);
        });
      } else {
        var opt = document.createElement('option');
        opt.value = '';
        opt.textContent = 'No payroll runs yet';
        mPicker.appendChild(opt);
      }
    }

    if (selRun) {
      payrollState.selectedRunId = selRun.id;
      var runTitle = new Date(selRun.year, selRun.month - 1, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
      if ($('#payrollMonthTitle')) $('#payrollMonthTitle').textContent = runTitle + ' Payroll';

      // Stats
      var totalNet = payslips.reduce(function (sum, p) { return sum + (parseFloat(p.net_pay) || 0); }, 0);
      var totalDed = payslips.reduce(function (sum, p) { return sum + (parseFloat(p.total_deductions) || 0) + (parseFloat(p.lop_amount) || 0); }, 0);
      var totalLop = payslips.reduce(function (sum, p) { return sum + (parseFloat(p.lop_days) || 0); }, 0);

      if ($('#payrollStatNet')) $('#payrollStatNet').textContent = cur + totalNet.toLocaleString('en-IN', { minimumFractionDigits: 2 });
      if ($('#payrollStatEmployees')) $('#payrollStatEmployees').textContent = payslips.length + ' employees';
      if ($('#payrollStatDeductions')) $('#payrollStatDeductions').textContent = cur + totalDed.toLocaleString('en-IN', { minimumFractionDigits: 2 });
      if ($('#payrollStatLop')) $('#payrollStatLop').textContent = totalLop;

      var stPill = $('#payrollStatStatus');
      if (stPill) {
        stPill.textContent = ucfirst(selRun.status);
        stPill.className = 'pill ' + (selRun.status === 'paid' ? 'ok' : (selRun.status === 'finalized' ? 'accent' : 'warn'));
      }
      var paydayText = (selRun && selRun.formatted_payday)
        ? selRun.formatted_payday
        : (selRun && selRun.payday_date ? formatPaydayDate(selRun.payday_date) : (res.next_payday || ('Day ' + (res.payday_day || (payrollState.settings && payrollState.settings.payroll_payday) || 30))));
      if ($('#payrollStatPayday')) $('#payrollStatPayday').textContent = 'Payday: ' + paydayText;

      // Buttons visibility
      var isDraft = (selRun.status === 'draft');
      var isFinalized = (selRun.status === 'finalized');
      var isPaid = (selRun.status === 'paid');

      if ($('#payrollBtnFinalize')) $('#payrollBtnFinalize').style.display = isDraft ? 'inline-flex' : 'none';
      if ($('#payrollBtnPaid')) {
        $('#payrollBtnPaid').style.display = isFinalized ? 'inline-flex' : 'none';
        $('#payrollBtnPaid').disabled = false;
      }
      if ($('#payrollBtnReopen')) $('#payrollBtnReopen').style.display = isFinalized ? 'inline-flex' : 'none';

      // Table body
      var tbody = $('#payrollTableBody');
      if (tbody) {
        tbody.innerHTML = '';
        if (payslips.length > 0) {
          payslips.forEach(function (p) {
            var tr = document.createElement('tr');
            var avatarColor = p.avatar_color || 'var(--ink)';
            var initials = p.avatar_initials || 'EM';

            var personHtml = '<div class="person"><span class="avatar sm" style="background:' + avatarColor + '">' + initials + '</span>' +
              '<div><div class="n">' + escapeHtml(p.employee_name || '') + '</div><div style="font-size:11px;color:var(--muted)">' + escapeHtml(p.team_name || p.job_title || '') + '</div></div></div>';

            var stBadge = '<span class="pill ' + (p.status === 'published' ? 'ok' : 'neutral') + '">' + ucfirst(p.status) + '</span>';

            var actionsHtml = '<div class="rowbtns" style="justify-content:flex-end;gap:4px">';
            if (isDraft) {
              actionsHtml += '<button class="btn xs adjust-slip-btn" data-id="' + p.id + '">Adjust</button>' +
                '<button class="btn xs regen-slip-btn" data-uid="' + p.user_id + '" data-rid="' + p.run_id + '" title="Recalculate attendance and structure">Regen</button>';
            }
            actionsHtml += '<button class="btn xs view-slip-pdf-btn" data-id="' + p.id + '" title="Download PDF"><svg class="i" style="width:13px;height:13px"><use href="#i-download"/></svg></button></div>';

            var totalEmpDed = (parseFloat(p.total_deductions) || 0) + (parseFloat(p.lop_amount) || 0);

            tr.innerHTML = '<td>' + personHtml + '</td>' +
              '<td class="num r">' + p.paid_days + '</td>' +
              '<td class="num r" style="' + (parseFloat(p.lop_days) > 0 ? 'color:var(--crit);font-weight:600' : '') + '">' + p.lop_days + '</td>' +
              '<td class="num r">' + cur + (parseFloat(p.gross) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</td>' +
              '<td class="num r">' + cur + totalEmpDed.toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</td>' +
              '<td class="num r" style="font-weight:700;color:var(--ok)">' + cur + (parseFloat(p.net_pay) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</td>' +
              '<td>' + stBadge + '</td>' +
              '<td class="r">' + actionsHtml + '</td>';
            tbody.appendChild(tr);
          });
        } else {
          tbody.innerHTML = '<tr><td colspan="8" class="empty-state" style="text-align:center;padding:24px;color:var(--muted)">No payslips generated for this run yet. Click Run payroll to generate.</td></tr>';
        }
      }
    } else {
      if ($('#payrollMonthTitle')) $('#payrollMonthTitle').textContent = 'Payroll Run';
      if ($('#payrollStatNet')) $('#payrollStatNet').textContent = cur + '0.00';
      if ($('#payrollStatEmployees')) $('#payrollStatEmployees').textContent = '0 employees';
      if ($('#payrollStatDeductions')) $('#payrollStatDeductions').textContent = cur + '0.00';
      if ($('#payrollStatLop')) $('#payrollStatLop').textContent = '0';
      var defaultPayday = res.next_payday || ('Day ' + (res.payday_day || (payrollState.settings && payrollState.settings.payroll_payday) || 30));
      if ($('#payrollStatPayday')) $('#payrollStatPayday').textContent = 'Payday: ' + defaultPayday;
      var stPill = $('#payrollStatStatus');
      if (stPill) {
        stPill.textContent = 'None';
        stPill.className = 'pill';
      }
      if ($('#payrollTableBody')) $('#payrollTableBody').innerHTML = '<tr><td colspan="8" class="empty-state" style="text-align:center;padding:24px;color:var(--muted)">No payroll runs exist yet. Click Run payroll to create one.</td></tr>';
    }
  }

  // ==================== SALARY COMPONENTS ====================
  async function loadSalaryComponents() {
    var res = await api('/api/payroll/components');
    if (!res || !res.success) return;
    payrollState.components = res.components || [];

    var tbody = $('#payrollComponentsTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (payrollState.components.length === 0) {
      tbody.innerHTML = '<tr><td colspan="9" class="empty-state" style="text-align:center;padding:24px;color:var(--muted)">No salary components defined yet.</td></tr>';
      return;
    }

    payrollState.components.forEach(function (c, idx) {
      var tr = document.createElement('tr');
      var typeBadge = c.type === 'earning' ? '<span class="pill ok plain">Earning</span>' : '<span class="pill crit plain">Deduction</span>';
      var calcStr = c.calc_type === 'percent_of_basic' ? '% of Basic' : (c.calc_type === 'percent_of_gross' ? '% of Gross' : 'Fixed');
      var isActive = parseInt(c.active, 10) === 1;
      var activeBadge = isActive ? '<span class="pill ok">Active</span>' : '<span class="pill neutral">Inactive</span>';

      var moveBtns = '<button class="btn xs quiet comp-order-up" data-idx="' + idx + '" ' + (idx === 0 ? 'disabled' : '') + '>↑</button>' +
        '<button class="btn xs quiet comp-order-down" data-idx="' + idx + '" ' + (idx === payrollState.components.length - 1 ? 'disabled' : '') + '>↓</button>';

      var actionsHtml = '<div class="rowbtns" style="justify-content:flex-end;gap:4px">' +
        '<button class="btn xs edit-comp-btn" data-id="' + c.id + '">Edit</button>' +
        '<button class="btn xs toggle-comp-btn" data-id="' + c.id + '">' + (isActive ? 'Deactivate' : 'Activate') + '</button>' +
        '</div>';

      var valStr = (parseFloat(c.default_value) || 0) + (c.calc_type && c.calc_type.indexOf('percent') !== -1 ? '%' : '');

      tr.innerHTML = '<td>' + moveBtns + '</td>' +
        '<td><b>' + escapeHtml(c.name) + '</b></td>' +
        '<td>' + typeBadge + '</td>' +
        '<td>' + calcStr + '</td>' +
        '<td class="num r">' + valStr + '</td>' +
        '<td><span class="pill neutral plain">' + escapeHtml((c.is_statutory || 'none').toUpperCase()) + '</span></td>' +
        '<td>' + (parseInt(c.show_on_payslip, 10) === 1 ? 'Yes' : 'No') + '</td>' +
        '<td>' + activeBadge + '</td>' +
        '<td class="r">' + actionsHtml + '</td>';
      tbody.appendChild(tr);
    });
  }

  function openSalaryComponentModal(comp) {
    var modal = $('#modalSalaryComponent');
    if (!modal) return;
    modal.hidden = false;

    if ($('#modalComponentTitle')) {
      $('#modalComponentTitle').textContent = comp ? 'Edit Salary Component' : 'Add Salary Component';
    }

    $('#comp_id').value = comp ? comp.id : '';
    $('#comp_name').value = comp ? comp.name : '';
    $('#comp_type').value = comp ? comp.type : 'earning';
    $('#comp_calc_type').value = comp ? comp.calc_type : 'fixed';
    $('#comp_default_value').value = comp ? comp.default_value : 0;
    $('#comp_statutory').value = comp ? comp.is_statutory : 'none';
    $('#comp_sort_order').value = comp ? comp.sort_order : 10;
    $('#comp_active').value = comp ? (comp.active ? '1' : '0') : '1';
    $('#comp_applies_all').checked = comp ? (parseInt(comp.applies_to_all, 10) === 1) : true;
    $('#comp_show_payslip').checked = comp ? (parseInt(comp.show_on_payslip, 10) === 1) : true;
  }

  // ==================== SALARY STRUCTURES ====================
  async function loadSalaryStructures() {
    var res = await api('/api/payroll/structures');
    if (!res || !res.success) return;
    payrollState.structures = res.employees || [];
    payrollState.components = res.components || [];
    payrollState.selectedEmployeeIds.clear();
    updateBulkButtonState();

    var tbody = $('#payrollStructuresTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (payrollState.structures.length === 0) {
      tbody.innerHTML = '<tr><td colspan="7" class="empty-state" style="text-align:center;padding:24px;color:var(--muted)">No active employees found.</td></tr>';
      return;
    }

    var cur = (payrollState.settings && payrollState.settings.currency_symbol) ? payrollState.settings.currency_symbol : '₹';

    payrollState.structures.forEach(function (emp) {
      var tr = document.createElement('tr');
      var avatarColor = emp.avatar_color || 'var(--ink)';
      var initials = emp.avatar_initials || 'EM';

      var personHtml = '<div class="person"><span class="avatar sm" style="background:' + avatarColor + '">' + initials + '</span>' +
        '<div><div class="n">' + escapeHtml(emp.name || '') + '</div><div style="font-size:11px;color:var(--muted)">' + escapeHtml(emp.job_title || '') + '</div></div></div>';

      var effFrom = (emp.structure && emp.structure.effective_from) ? emp.structure.effective_from : 'Default / Not set';

      var actionsHtml = '<div class="rowbtns" style="justify-content:flex-end;gap:4px">' +
        '<button class="btn xs edit-struct-btn" data-id="' + emp.id + '">Edit structure</button>' +
        '<button class="btn xs hist-struct-btn" data-id="' + emp.id + '" data-name="' + escapeHtml(emp.name) + '">History</button>' +
        '</div>';

      tr.innerHTML = '<td><input type="checkbox" class="emp-struct-chk" data-id="' + emp.id + '"></td>' +
        '<td>' + personHtml + '</td>' +
        '<td>' + escapeHtml(emp.team_name || emp.department || 'General') + '</td>' +
        '<td class="num r">' + cur + (parseFloat(emp.monthly_gross) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</td>' +
        '<td class="num r" style="font-weight:700">' + cur + (parseFloat(emp.current_ctc) || 0).toLocaleString('en-IN', { minimumFractionDigits: 2 }) + '</td>' +
        '<td>' + effFrom + '</td>' +
        '<td class="r">' + actionsHtml + '</td>';
      tbody.appendChild(tr);
    });
  }

  function updateBulkButtonState() {
    var count = payrollState.selectedEmployeeIds.size;
    var btn = $('#payrollBtnBulkStructure');
    var span = $('#bulkSelectedCount');
    if (span) span.textContent = count;
    if (btn) btn.disabled = (count === 0);
  }

  function openSalaryStructureModal(emp) {
    var modal = $('#modalSalaryStructure');
    if (!modal) return;
    modal.hidden = false;

    if ($('#structEmpName')) $('#structEmpName').textContent = emp.name;
    $('#struct_user_id').value = emp.id;
    $('#struct_effective_from').value = new Date().toISOString().split('T')[0];

    var container = $('#structComponentFieldsContainer');
    if (!container) return;
    container.innerHTML = '';

    var overrides = (emp.structure && emp.structure.component_values) ? emp.structure.component_values : {};

    payrollState.components.forEach(function (c) {
      var cid = String(c.id);
      var currentVal = overrides[cid] !== undefined ? overrides[cid] : c.default_value;

      var div = document.createElement('div');
      div.className = 'field';
      div.style.marginBottom = '6px';

      var labelStr = escapeHtml(c.name) + ' (' + ucfirst(c.type) + ' - ' + (c.calc_type === 'percent_of_basic' ? '% of Basic' : (c.calc_type === 'percent_of_gross' ? '% of Gross' : 'Fixed')) + ')';
      div.innerHTML = '<label style="font-size:12px;display:flex;justify-content:space-between">' +
        '<span>' + labelStr + '</span>' +
        '<span style="color:var(--muted)">Default: ' + c.default_value + '</span>' +
        '</label>' +
        '<input type="number" step="0.01" class="input struct-comp-input" data-cid="' + c.id + '" data-type="' + c.type + '" data-calctype="' + c.calc_type + '" value="' + currentVal + '">';
      container.appendChild(div);
    });

    // Wire live calculation inside modal
    function recalcPreview() {
      var basicVal = 0.0;
      var inputs = $$('.struct-comp-input', container);
      inputs.forEach(function (inp) {
        var comp = payrollState.components.find(function (x) { return String(x.id) === inp.dataset.cid; });
        if (comp && comp.type === 'earning' && comp.name.toLowerCase().includes('basic')) {
          basicVal = parseFloat(inp.value) || 0.0;
        }
      });

      var gross = 0.0;
      inputs.forEach(function (inp) {
        if (inp.dataset.type === 'earning') {
          var val = parseFloat(inp.value) || 0.0;
          if (inp.dataset.calctype === 'percent_of_basic') {
            gross += (val / 100.0) * basicVal;
          } else {
            gross += val;
          }
        }
      });

      var totalDed = 0.0;
      inputs.forEach(function (inp) {
        if (inp.dataset.type === 'deduction') {
          var val = parseFloat(inp.value) || 0.0;
          if (inp.dataset.calctype === 'percent_of_basic') {
            totalDed += (val / 100.0) * basicVal;
          } else if (inp.dataset.calctype === 'percent_of_gross') {
            totalDed += (val / 100.0) * gross;
          } else {
            totalDed += val;
          }
        }
      });

      var net = gross - totalDed;
      var cur = (payrollState.settings && payrollState.settings.currency_symbol) ? payrollState.settings.currency_symbol : '₹';
      if ($('#structPreviewGross')) $('#structPreviewGross').textContent = cur + gross.toLocaleString('en-IN', { minimumFractionDigits: 2 });
      if ($('#structPreviewDeductions')) $('#structPreviewDeductions').textContent = cur + totalDed.toLocaleString('en-IN', { minimumFractionDigits: 2 });
      if ($('#structPreviewNet')) $('#structPreviewNet').textContent = cur + net.toLocaleString('en-IN', { minimumFractionDigits: 2 });
    }

    container.oninput = recalcPreview;
    recalcPreview();
  }

  function openBulkSalaryStructureModal() {
    var modal = $('#modalBulkSalaryStructure');
    if (!modal) return;
    modal.hidden = false;

    var count = payrollState.selectedEmployeeIds.size;
    if ($('#bulkModalCount')) $('#bulkModalCount').textContent = count;
    $('#bulk_struct_effective_from').value = new Date().toISOString().split('T')[0];

    var container = $('#bulkStructComponentFieldsContainer');
    if (!container) return;
    container.innerHTML = '';

    payrollState.components.forEach(function (c) {
      var div = document.createElement('div');
      div.className = 'field';
      div.style.marginBottom = '6px';
      var labelStr = escapeHtml(c.name) + ' (' + ucfirst(c.type) + ' - ' + (c.calc_type.startsWith('percent') ? '%' : 'Fixed') + ')';
      div.innerHTML = '<label style="font-size:12px">' + labelStr + '</label>' +
        '<input type="number" step="0.01" class="input bulk-comp-input" data-cid="' + c.id + '" value="' + c.default_value + '">';
      container.appendChild(div);
    });
  }

  async function openStructureHistoryModal(userId, empName) {
    var modal = $('#modalStructureHistory');
    if (!modal) return;
    modal.hidden = false;
    if ($('#histEmpName')) $('#histEmpName').textContent = empName;

    var tbody = $('#structureHistoryTableBody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:18px">Loading history...</td></tr>';

    var res = await api('/api/payroll/structures/history?user_id=' + encodeURIComponent(userId));
    if (!res || !res.success || !tbody) return;

    tbody.innerHTML = '';
    var history = res.history || [];
    if (history.length === 0) {
      tbody.innerHTML = '<tr><td colspan="4" class="empty-state" style="text-align:center;padding:18px;color:var(--muted)">No historical structures found.</td></tr>';
      return;
    }

    history.forEach(function (h) {
      var tr = document.createElement('tr');
      var vals = h.component_values || {};
      var valParts = [];
      Object.keys(vals).forEach(function (cid) {
        var comp = payrollState.components.find(function (c) { return String(c.id) === String(cid); });
        var compName = comp ? comp.name : ('Component #' + cid);
        valParts.push(compName + ': ' + vals[cid]);
      });

      tr.innerHTML = '<td><b>' + h.effective_from + '</b></td>' +
        '<td>' + (h.creator_name || 'Admin') + '</td>' +
        '<td>' + (h.created_at || '--') + '</td>' +
        '<td style="font-size:11px;color:var(--muted)">' + (valParts.length ? valParts.join(', ') : 'Standard defaults') + '</td>';
      tbody.appendChild(tr);
    });
  }

  // ==================== PAYROLL SETTINGS ====================
  async function loadPayrollSettings() {
    var res = await api('/api/payroll/settings');
    if (!res || !res.success) return;
    var s = res.settings || {};
    payrollState.settings = s;

    $('#pset_company_legal_name').value = s.company_legal_name || '';
    $('#pset_currency_symbol').value = s.currency_symbol || '₹';
    $('#pset_company_address').value = s.company_address || '';
    $('#pset_payroll_logo').value = s.payroll_logo || '';
    $('#pset_payslip_footer_text').value = s.payslip_footer_text || '';
    $('#pset_payroll_payday').value = s.payroll_payday || '30';
    $('#pset_financial_year_start_month').value = s.financial_year_start_month || '4';
    $('#pset_payslip_visibility').value = s.payslip_visibility || 'on_finalize';
    $('#pset_lop_divisor').value = s.lop_divisor || 'calendar_days';
    $('#pset_payroll_rounding').value = s.payroll_rounding || 'nearest_rupee';
    $('#pset_tds_label').value = s.tds_label || 'TDS';

    $('#pset_pf_rate').value = s.pf_rate || '12.0';
    $('#pset_pf_wage_ceiling_enabled').value = s.pf_wage_ceiling_enabled || '1';
    $('#pset_pf_wage_ceiling').value = s.pf_wage_ceiling || '15000';
    $('#pset_esi_rate').value = s.esi_rate || '0.75';
    $('#pset_esi_gross_threshold').value = s.esi_gross_threshold || '21000';
    $('#pset_default_professional_tax').value = s.default_professional_tax || '200';
  }

  // ==================== PAYSLIP ADJUSTMENTS ====================
  function openPayslipAdjustModal(slip) {
    var modal = $('#modalPayslipAdjust');
    if (!modal) return;
    modal.hidden = false;

    $('#adjustPayslipId').value = slip.id;
    if ($('#adjustEmpName')) $('#adjustEmpName').textContent = slip.employee_name || 'Employee';
    $('#adjustLopDays').value = slip.lop_days !== undefined ? slip.lop_days : 0;

    var container = $('#adjustmentsRowsContainer');
    if (!container) return;
    container.innerHTML = '';

    var adjs = slip.adjustments || [];
    adjs.forEach(function (adj) {
      appendAdjustmentRow(adj.type, adj.name, adj.amount);
    });
  }

  function appendAdjustmentRow(type, name, amount) {
    var container = $('#adjustmentsRowsContainer');
    if (!container) return;
    var row = document.createElement('div');
    row.className = 'adj-row';
    row.style.display = 'flex';
    row.style.gap = '8px';
    row.style.alignItems = 'center';

    row.innerHTML = '<select class="input adj-type" style="width:110px">' +
      '<option value="earning" ' + (type === 'earning' ? 'selected' : '') + '>Earning (+)</option>' +
      '<option value="deduction" ' + (type === 'deduction' ? 'selected' : '') + '>Deduction (-)</option>' +
      '</select>' +
      '<input type="text" class="input adj-name" placeholder="Reason (e.g. Incentive)" value="' + escapeHtml(name || '') + '" style="flex:1" required>' +
      '<input type="number" step="0.01" class="input adj-amt" placeholder="Amount" value="' + (parseFloat(amount) || 0) + '" style="width:100px" required>' +
      '<button type="button" class="btn xs quiet rm-adj-btn" style="color:var(--crit)">&times;</button>';

    row.querySelector('.rm-adj-btn').onclick = function () { row.remove(); };
    container.appendChild(row);
  }

  // EVENT LISTENERS INITIALIZATION
  function bindEvents() {
    // Nav Click
    if ($('#nav')) {
      $('#nav').addEventListener('click', function (e) {
        var sub = e.target.closest('button[data-sub]');
        if (sub) {
          showScreen(sub.dataset.sub + ':' + sub.dataset.view);
          return;
        }
        var b = e.target.closest('button[data-s]');
        if (!b) return;
        if (b.classList.contains('has-sub') && state.currentScreen === b.dataset.s) {
          state.navOpen[b.dataset.s] = !state.navOpen[b.dataset.s];
          syncSubnav();
          return;
        }
        showScreen(b.dataset.s);
      });
    }

    // Login Form Submit
    if ($('#loginForm')) {
      $('#loginForm').addEventListener('submit', async function (e) {
        e.preventDefault();
        var email = $('#em').value.trim();
        var password = $('#pw').value;
        var res = await api('/api/auth/login', 'POST', { email: email, password: password });
        if (res && res.success) {
          state.user = res.user;
          state.csrfToken = res.csrf_token;
          showToast('Signed in successfully.');
          onSignedIn();
        } else {
          alert(res && res.error ? res.error : 'Login failed.');
        }
      });
    }

    // Forgot Password Notice
    if ($('#linkForgotPw')) {
      $('#linkForgotPw').addEventListener('click', function (e) {
        e.preventDefault();
        alert('Passwords can only be set and reset by a system administrator. Please contact your HR administrator.');
      });
    }



    // Logout
    if ($('#logoutBtn')) {
      $('#logoutBtn').addEventListener('click', async function () {
        await api('/api/auth/logout', 'POST');
        onSignedOut();
      });
    }

    // Consent accept
    if ($('#btnAcceptConsent')) {
      $('#btnAcceptConsent').addEventListener('click', async function () {
        var res = await api('/api/auth/consent', 'POST');
        if (res && res.success) {
          $('#consentModal').hidden = true;
          showToast('Consent accepted.');
        }
      });
    }

    // Punch Buttons on all roles (emp, mgr, and general)
    ['', 'emp', 'mgr'].forEach(function (prefix) {
      var p = prefix ? '#' + prefix : '#';
      var btnBreak = $(p + (prefix ? 'BtnBreak' : 'btnBreak'));
      if (btnBreak) {
        btnBreak.addEventListener('click', function () {
          if (state.punchStatus === 'on_break') openPunchModal('break_end');
          else if (state.punchStatus === 'clocked_in') openPunchModal('break_start');
        });
      }

      var btnOut = $(p + (prefix ? 'BtnOut' : 'btnOut'));
      if (btnOut) {
        btnOut.addEventListener('click', function () {
          if (state.punchStatus === 'clocked_out') openPunchModal('in');
          else openPunchModal('out');
        });
      }

      var btnUndo = $(p + (prefix ? 'BtnUndoPunch' : 'btnUndoPunch'));
      if (btnUndo) {
        btnUndo.addEventListener('click', async function () {
          if (confirm('Undo your most recent punch? This will remove the punch from your shift.')) {
            var res = await api('/api/attendance/punch-undo', 'POST');
            if (res && res.success) {
              showToast(res.message);
              loadDashboard();
              loadAttendanceData();
            } else {
              alert(res && res.error ? res.error : 'Failed to undo punch.');
            }
          }
        });
      }

      var btnEdit = $(p + (prefix ? 'BtnEditPunch' : 'btnEditPunch'));
      if (btnEdit) {
        btnEdit.addEventListener('click', function () {
          if (state.latestPunch && $('#editPunchTime')) {
            $('#editPunchTime').value = state.latestPunch.punch_time || '';
          }
          $('#editPunchModal').hidden = false;
        });
      }
    });

    // Admin Quick Links in Dashboard
    if ($('#admBtnHolidayCal')) {
      $('#admBtnHolidayCal').addEventListener('click', function () {
        $('#holidayModal').hidden = false;
        loadHolidays();
      });
    }
    if ($('#admBtnCsvImport')) {
      $('#admBtnCsvImport').addEventListener('click', function () {
        $('#importCsvModal').hidden = false;
      });
    }
    if ($('#empLinkHolidayCal')) {
      $('#empLinkHolidayCal').addEventListener('click', function (e) {
        e.preventDefault();
        $('#holidayModal').hidden = false;
        loadHolidays();
      });
    }
    if ($('#empLinkViewHolidays')) {
      $('#empLinkViewHolidays').addEventListener('click', function (e) {
        e.preventDefault();
        $('#holidayModal').hidden = false;
        loadHolidays();
      });
    }
    if ($('#mgrLinkViewHolidays')) {
      $('#mgrLinkViewHolidays').addEventListener('click', function (e) {
        e.preventDefault();
        $('#holidayModal').hidden = false;
        loadHolidays();
      });
    }

    if ($('#btnConfirmPunch')) {
      $('#btnConfirmPunch').addEventListener('click', executePunch);
    }

    if ($('#btnClosePunchModal')) {
      $('#btnClosePunchModal').addEventListener('click', closePunchModal);
    }
    if ($('#btnCloseEditPunchModal')) {
      $('#btnCloseEditPunchModal').addEventListener('click', function () {
        $('#editPunchModal').hidden = true;
      });
    }
    if ($('#formEditPunch')) {
      $('#formEditPunch').addEventListener('submit', async function (e) {
        e.preventDefault();
        var newTime = $('#editPunchTime').value;
        var res = await api('/api/attendance/punch-edit', 'POST', { punch_time: newTime });
        if (res && res.success) {
          showToast(res.message);
          $('#editPunchModal').hidden = true;
          loadDashboard();
          loadAttendanceData();
        } else {
          alert(res && res.error ? res.error : 'Failed to edit punch.');
        }
      });
    }

    // Attendance stats card period filter
    if ($('#selectAttnPeriod')) {
      $('#selectAttnPeriod').addEventListener('change', function () {
        loadAttendanceStats(this.value);
      });
    }

    // Attendance 24-hour format toggle
    if ($('#chkAttn24h')) {
      $('#chkAttn24h').addEventListener('change', function () {
        state.attn24h = this.checked;
        loadAttendanceData();
      });
    }

    // Attendance tabs
    $$('[data-attn-tab]').forEach(function (b) {
      b.addEventListener('click', function () {
        state.attendanceCalMonth = '';
        setAttendanceTab(b.dataset.attnTab);
      });
    });

    // Attendance filters (team and all views)
    function onAttendanceFilterChange() {
      state.attendanceLogPage = 1;
      state.attendanceRequestsPage = 1;
      loadAttendanceData();
    }
    $('#selectAttnTeamFilter').addEventListener('change', function () {
      state.attendanceTeamId = this.value;
      state.attendanceEmployeeId = 'all';
      onAttendanceFilterChange();
    });
    $('#selectAttnEmployeeFilter').addEventListener('change', function () {
      state.attendanceEmployeeId = this.value;
      onAttendanceFilterChange();
    });
    $('#attnFrom').addEventListener('change', function () { setAttendanceRange(this.value, $('#attnTo').value); });
    $('#attnTo').addEventListener('change', function () { setAttendanceRange($('#attnFrom').value, this.value); });
    $('#btnAttnClearFilters').addEventListener('click', function () {
      state.attendanceTeamId = 'all';
      state.attendanceEmployeeId = 'all';
      state.selectedAttendanceMonth = '';
      state.attendanceFrom = '';
      state.attendanceTo = '';
      $('#attnFrom').value = '';
      $('#attnTo').value = '';
      $('#attnTo').min = '';
      onAttendanceFilterChange();
    });
    $('#btnAttnCalPrev').addEventListener('click', function () { stepAttendanceCalMonth(-1); });
    $('#btnAttnCalNext').addEventListener('click', function () { stepAttendanceCalMonth(1); });

    // Attendance correction requests
    $('#btnOpenCorrection').addEventListener('click', function (e) {
      e.preventDefault();
      $('#correctionModal').hidden = false;
    });
    $('#btnCloseCorrection').addEventListener('click', function () {
      $('#correctionModal').hidden = true;
    });
    $('#formCorrection').addEventListener('submit', async function (e) {
      e.preventDefault();
      var res = await api('/api/attendance/correction', 'POST', {
        date: $('#corrDate').value,
        requested_in: $('#corrIn').value,
        requested_out: $('#corrOut').value,
        reason: $('#corrReason').value,
      });
      if (res && res.success) {
        showToast(res.message);
        $('#correctionModal').hidden = true;
        this.reset();
        setAttendanceTab('requests');
      } else {
        alert(res && res.error ? res.error : 'Could not submit the request.');
      }
    });
    document.addEventListener('click', async function (e) {
      var btn = e.target.closest('.btn-action-correction');
      if (!btn) return;
      var res = await api('/api/attendance/correction/action', 'POST', { id: btn.dataset.id, status: btn.dataset.act });
      if (res && res.success) {
        showToast(res.message);
        loadAttendanceRequests();
      } else {
        alert(res && res.error ? res.error : 'Action failed.');
      }
    });

    // Attendance & Leave Policy Modals
    if ($('#btnOpenAttnPolicy')) {
      $('#btnOpenAttnPolicy').addEventListener('click', function (e) {
        e.preventDefault();
        openAttnPolicyModal();
      });
    }
    if ($('#btnCloseAttnPolicy')) {
      $('#btnCloseAttnPolicy').addEventListener('click', function () {
        $('#attendancePolicyModal').hidden = true;
      });
    }

    if ($('#linkOpenLeavePolicy')) {
      $('#linkOpenLeavePolicy').addEventListener('click', function (e) {
        e.preventDefault();
        openLeavePolicyModal();
      });
    }
    if ($('#btnCloseLeavePolicy')) {
      $('#btnCloseLeavePolicy').addEventListener('click', function () {
        $('#leavePolicyModal').hidden = true;
      });
    }

    // Comp-off credit request modal
    if ($('#linkOpenCompOff')) {
      $('#linkOpenCompOff').addEventListener('click', function (e) {
        e.preventDefault();
        $('#compOffModal').hidden = false;
      });
    }
    if ($('#btnCloseCompOff')) {
      $('#btnCloseCompOff').addEventListener('click', function () {
        $('#compOffModal').hidden = true;
      });
    }
    if ($('#formCompOff')) {
      $('#formCompOff').addEventListener('submit', async function (e) {
        e.preventDefault();
        var payload = {
          worked_date: $('#compOffDate').value,
          hours_worked: $('#compOffHours').value,
          reason: $('#compOffReason').value.trim()
        };
        var res = await api('/api/leave/comp-off-request', 'POST', payload);
        if (res && res.success) {
          showToast(res.message);
          $('#compOffModal').hidden = true;
          $('#formCompOff').reset();
          loadLeaveData();
        } else {
          alert(res && res.error ? res.error : 'Failed to submit comp-off request.');
        }
      });
    }

    // Comp-off action delegation
    document.addEventListener('click', async function (e) {
      var btn = e.target.closest('.btn-action-compoff');
      if (btn) {
        var id = btn.dataset.id;
        var act = btn.dataset.act;
        var res = await api('/api/leave/comp-off-action', 'POST', { id: id, status: act });
        if (res && res.success) {
          showToast(res.message);
          loadApprovals();
        } else {
          alert(res && res.error ? res.error : 'Action failed.');
        }
      }
    });

    // Leave Types management modal (Admin)
    if ($('#btnOpenAddLeaveType')) {
      $('#btnOpenAddLeaveType').addEventListener('click', function () {
        $('#leaveTypeId').value = '';
        $('#leaveTypeName').value = '';
        $('#leaveTypeCode').value = '';
        $('#leaveTypeDays').value = 10;
        if ($('#leaveTypeIsPaid')) $('#leaveTypeIsPaid').checked = true;
        if ($('#leaveTypeModalTitle')) $('#leaveTypeModalTitle').textContent = 'Add Leave Type';
        $('#addLeaveTypeModal').hidden = false;
      });
    }
    if ($('#btnCloseLeaveTypeModal')) {
      $('#btnCloseLeaveTypeModal').addEventListener('click', function () {
        $('#addLeaveTypeModal').hidden = true;
      });
    }
    if ($('#formAddLeaveType')) {
      $('#formAddLeaveType').addEventListener('submit', async function (e) {
        e.preventDefault();
        var payload = {
          id: $('#leaveTypeId').value || '',
          name: $('#leaveTypeName').value.trim(),
          code: $('#leaveTypeCode').value.trim(),
          days_per_year: $('#leaveTypeDays').value,
          yearly_quota: $('#leaveTypeDays').value,
          is_paid: ($('#leaveTypeIsPaid') && $('#leaveTypeIsPaid').checked) ? 1 : 0,
          is_active: 1
        };
        var res = await api('/api/leave-types/save', 'POST', payload);
        if (res && res.success) {
          showToast(res.message || 'Leave type saved.');
          $('#addLeaveTypeModal').hidden = true;
          $('#formAddLeaveType').reset();
          $('#leaveTypeId').value = '';
          loadSettingsLeaveTypes();
          loadLeaveData();
        } else {
          alert(res && res.error ? res.error : 'Failed to save leave type.');
        }
      });
    }
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.btn-edit-leavetype');
      if (btn) {
        var data = JSON.parse(btn.dataset.json);
        $('#leaveTypeId').value = data.id || '';
        $('#leaveTypeName').value = data.name || '';
        $('#leaveTypeCode').value = data.code || '';
        var d = (data.days_per_year !== undefined && data.days_per_year !== null) ? data.days_per_year : (data.yearly_quota || 0);
        $('#leaveTypeDays').value = parseFloat(d);
        if ($('#leaveTypeIsPaid')) $('#leaveTypeIsPaid').checked = data.is_paid == 1;
        if ($('#leaveTypeModalTitle')) $('#leaveTypeModalTitle').textContent = 'Edit Leave Type';
        $('#addLeaveTypeModal').hidden = false;
      }
    });
    document.addEventListener('click', async function (e) {
      var btn = e.target.closest('.btn-delete-leavetype');
      if (btn) {
        var id = btn.dataset.id;
        var name = btn.dataset.name || 'this leave type';
        if (!confirm('Are you sure you want to delete ' + name + '?')) {
          return;
        }
        var res = await api('/api/leave-types/delete', 'POST', { id: id });
        if (res && res.success) {
          showToast(res.message || 'Leave type deleted.');
          loadSettingsLeaveTypes();
          loadLeaveData();
        } else {
          alert(res && res.error ? res.error : 'Failed to delete leave type.');
        }
      }
    });

    // Sign out other devices
    if ($('#btnSignOutOthers')) {
      $('#btnSignOutOthers').addEventListener('click', async function () {
        if (confirm('Sign out all other devices? All other active sessions will be terminated immediately.')) {
          var res = await api('/api/auth/signout-others', 'POST');
          if (res && res.success) {
            showToast(res.message);
            loadActiveDevices();
          } else {
            alert(res && res.error ? res.error : 'Failed to sign out other devices.');
          }
        }
      });
    }

    // Holiday Calendar Modal Triggers
    ['#linkViewAllHolidays', '#linkHolidayCalDash', '#linkHolidayCalLeave'].forEach(function (sel) {
      if ($(sel)) {
        $(sel).addEventListener('click', function (e) {
          e.preventDefault();
          $('#holidayModal').hidden = false;
          loadHolidays();
        });
      }
    });
    if ($('#btnCloseHolidayModal')) {
      $('#btnCloseHolidayModal').addEventListener('click', function () {
        $('#holidayModal').hidden = true;
      });
    }
    if ($('#formAddHoliday')) {
      $('#formAddHoliday').addEventListener('submit', async function (e) {
        e.preventDefault();
        var payload = {
          name: $('#newHolidayName').value.trim(),
          date: $('#newHolidayDate').value,
          type: $('#newHolidayType').value
        };
        var res = await api('/api/holidays/create', 'POST', payload);
        if (res && res.success) {
          showToast(res.message);
          $('#formAddHoliday').reset();
          loadHolidays();
        } else {
          alert(res && res.error ? res.error : 'Failed to create holiday.');
        }
      });
    }
    document.addEventListener('click', async function (e) {
      var btn = e.target.closest('.btn-delete-holiday');
      if (btn) {
        var id = btn.dataset.id;
        if (confirm('Delete this holiday?')) {
          var res = await api('/api/holidays/delete', 'POST', { id: id });
          if (res && res.success) {
            showToast(res.message);
            loadHolidays();
          } else {
            alert(res && res.error ? res.error : 'Failed to delete holiday.');
          }
        }
      }
    });

    // Wish Button Delegation (Prefills Composer)
    document.addEventListener('click', function (e) {
      var btn = e.target.closest('.btn-wish');
      if (btn) {
        var name = btn.dataset.name;
        var type = btn.dataset.type;
        var greeting = type === 'work_anniversary'
          ? 'Happy Work Anniversary, ' + name + '! Thank you for all your wonderful dedication and hard work!'
          : 'Happy Birthday, ' + name + '! Wishing you a fantastic day and a wonderful year ahead!';

        var ta = $('#composerHome') || $('#composer');
        if (ta) {
          ta.value = greeting;
          ta.focus();
          ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        showToast('Composer prefilled with greeting for ' + name + '.');
      }
    });

    // Leave Form Toggle
    if ($('#openLeave')) {
      $('#openLeave').addEventListener('click', function () {
        $('#leaveForm').hidden = false;
        $('#leaveForm').scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
    }
    if ($('#closeLeave')) {
      $('#closeLeave').addEventListener('click', function () {
        $('#leaveForm').hidden = true;
      });
    }

    // Leave filters & year selection
    function reloadLeaveHistory() {
      state.leaveHistoryPage = 1;
      loadLeaveHistory();
    }
    function onLeaveFilterChange() {
      state.leaveHistoryPage = 1;
      loadLeaveData();
      loadLeaveStats();
    }
    $('#selectLeavePendingYear').addEventListener('change', onLeaveFilterChange);
    $('#selectLeaveHistoryType').addEventListener('change', reloadLeaveHistory);
    $('#selectLeaveHistoryStatus').addEventListener('change', reloadLeaveHistory);
    var leaveSearchTimer = null;
    $('#leaveHistorySearch').addEventListener('input', function () {
      clearTimeout(leaveSearchTimer);
      leaveSearchTimer = setTimeout(reloadLeaveHistory, 300);
    });
    $('#selectLeaveTeamFilter').addEventListener('change', function () {
      state.leaveTeamId = this.value;
      state.leaveEmployeeId = 'all';
      onLeaveFilterChange();
    });
    $('#selectLeaveEmployeeFilter').addEventListener('change', function () {
      state.leaveEmployeeId = this.value;
      onLeaveFilterChange();
    });
    $('#leaveFrom').addEventListener('change', function () {
      state.leaveFrom = this.value;
      $('#leaveTo').min = this.value;
      onLeaveFilterChange();
    });
    $('#leaveTo').addEventListener('change', function () {
      state.leaveTo = this.value;
      onLeaveFilterChange();
    });
    $('#btnLeaveClearFilters').addEventListener('click', function () {
      state.leaveTeamId = 'all';
      state.leaveEmployeeId = 'all';
      state.leaveFrom = '';
      state.leaveTo = '';
      $('#leaveFrom').value = '';
      $('#leaveTo').value = '';
      $('#leaveTo').min = '';
      onLeaveFilterChange();
    });
    document.addEventListener('click', function (e) {
      var link = e.target.closest('.link-leave-details');
      if (!link) return;
      e.preventDefault();
      $('#selectLeaveHistoryType').value = link.dataset.code;
      reloadLeaveHistory();
      $('#leaveHistoryPanel').scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    // Leave Type selection hint
    if ($('#lt')) {
      $('#lt').addEventListener('change', updateLeaveFormHint);
    }

    // Submit Leave Request
    if ($('#btnSubmitLeave')) {
      $('#btnSubmitLeave').addEventListener('click', async function () {
        var opt = $('#lt').selectedOptions ? $('#lt').selectedOptions[0] : null;
        var avail = (opt && opt.dataset.available !== undefined) ? parseFloat(opt.dataset.available) : null;

        var fromVal = $('#lf').value;
        var toVal = $('#lto').value;
        var durVal = $('#ld').value;
        var requestedDays = 1.0;
        if (fromVal && toVal) {
          var startMs = new Date(fromVal).getTime();
          var endMs = new Date(toVal).getTime();
          if (endMs >= startMs) {
            requestedDays = 1 + Math.round((endMs - startMs) / 86400000);
            if (durVal === 'half_day') requestedDays = 0.5;
            else if (durVal === 'short_leave') requestedDays = 0.25;
          }
        }

        if (avail !== null && (avail <= 0 || requestedDays > avail)) {
          if (avail <= 0) {
            alert('You have 0 available days for this leave type. Cannot submit leave request.');
          } else {
            alert('Insufficient leave balance. You requested ' + requestedDays + ' day(s), but only have ' + avail + ' day(s) available.');
          }
          return;
        }

        var payload = {
          leave_type: $('#lt').value,
          start_date: fromVal,
          end_date: toVal,
          duration_type: durVal,
          reason: $('#lr').value,
        };
        var res = await api('/api/leave/submit', 'POST', payload);
        if (res && res.success) {
          showToast(res.message);
          $('#leaveForm').hidden = true;
          $('#lr').value = '';
          loadLeaveData();
          loadLeaveStats();
        } else {
          alert(res && res.error ? res.error : 'Failed to submit leave.');
        }
      });
    }

    // Save Draft Leave Request
    if ($('#btnDraftLeave')) {
      $('#btnDraftLeave').addEventListener('click', async function () {
        var payload = {
          leave_type: $('#lt').value,
          start_date: $('#lf').value,
          end_date: $('#lto').value,
          duration_type: $('#ld').value,
          reason: $('#lr').value,
          is_draft: 1,
        };
        var res = await api('/api/leave/submit', 'POST', payload);
        if (res && res.success) {
          showToast(res.message);
          $('#leaveForm').hidden = true;
          $('#lr').value = '';
          loadLeaveData();
          loadLeaveStats();
        } else {
          alert(res && res.error ? res.error : 'Failed to save draft.');
        }
      });
    }

    // Withdraw leave delegation
    document.addEventListener('click', async function (e) {
      var btn = e.target.closest('.btn-withdraw-leave');
      if (btn) {
        var id = btn.dataset.id;
        if (confirm('Are you sure you want to withdraw this leave request?')) {
          var res = await api('/api/leave/withdraw', 'POST', { id: id });
          if (res && res.success) {
            showToast('Leave request withdrawn.');
            loadLeaveData();
            loadLeaveStats();
          } else {
            alert(res && res.error ? res.error : 'Could not withdraw leave.');
          }
        }
      }
    });

    // Action Leave (Approve / Reject)
    document.addEventListener('click', async function (e) {
      var btn = e.target.closest('.btn-action-leave');
      if (btn) {
        var id = btn.dataset.id;
        var act = btn.dataset.act;
        var res = await api('/api/leave/action', 'POST', { id: id, status: act });
        if (res && res.success) {
          showToast(res.message);
          loadApprovals();
          if (state.currentScreen === 'leave') loadLeaveData();
        } else {
          alert(res && res.error ? res.error : 'Action failed.');
        }
      }
    });

    // Post Composer Upload & Post
    document.querySelectorAll('.composer').forEach(function (c) {
      var file = c.querySelector('input[type=file]');
      var pv = c.querySelector('.preview');
      var img = pv ? pv.querySelector('img') : null;
      var ta = c.querySelector('textarea');
      var addPhotoBtn = c.querySelector('.addphoto');
      var doBtn = c.querySelector('.dopost');
      var praiseRow = c.querySelector('.praise-picker-row');
      var praiseSelect = c.querySelector('.praise-select');

      // Dedicated Poll Fields within Composer
      var pollFields = c.querySelector('#composerPollFields');
      var pollQuestion = c.querySelector('#pollQuestionInput');
      var pollOptsList = c.querySelector('#pollOptionsList');
      var btnAddPollOpt = c.querySelector('#btnAddPollOption');

      if (btnAddPollOpt && pollOptsList) {
        btnAddPollOpt.addEventListener('click', function () {
          var rows = pollOptsList.querySelectorAll('.poll-opt-row');
          if (rows.length >= 6) {
            showToast('A poll can have at most 6 options.');
            return;
          }
          var nextNum = rows.length + 1;
          var div = document.createElement('div');
          div.className = 'poll-opt-row';
          div.style.cssText = 'display:flex;align-items:center;gap:8px';
          div.innerHTML = '<input type="text" class="poll-opt-input" placeholder="Option ' + nextNum + '" style="flex:1;padding:8px 12px;font-size:13.5px;border:1px solid var(--line);border-radius:6px;background:var(--surface)">' +
            '<button type="button" class="btn xs quiet btn-del-opt" style="padding:4px 8px;font-size:16px;line-height:1;color:var(--muted)" title="Remove option">&times;</button>';
          pollOptsList.appendChild(div);
        });

        pollOptsList.addEventListener('click', function (e) {
          var delBtn = e.target.closest('.btn-del-opt');
          if (delBtn) {
            var row = delBtn.closest('.poll-opt-row');
            if (row && pollOptsList.querySelectorAll('.poll-opt-row').length > 2) {
              row.remove();
            } else {
              showToast('A poll must have at least 2 options.');
            }
          }
        });
      }

      // Switch composer modes on tab click (Post, Poll, Praise)
      var ctabs = c.querySelector('.ctabs');
      if (ctabs) {
        ctabs.addEventListener('click', function (e) {
          var btn = e.target.closest('button');
          if (!btn) return;
          var tabType = btn.dataset.tab || (btn.textContent.toLowerCase().includes('poll') ? 'poll' : (btn.textContent.toLowerCase().includes('praise') ? 'praise' : 'post'));

          var isPoll = tabType === 'poll';
          var isPraise = tabType === 'praise';

          if (pollFields) pollFields.style.display = isPoll ? 'block' : 'none';
          if (ta) ta.style.display = isPoll ? 'none' : 'block';
          if (praiseRow) praiseRow.style.display = isPraise ? 'flex' : 'none';
          if (addPhotoBtn) addPhotoBtn.style.display = isPoll ? 'none' : 'inline-flex';
          if (doBtn) doBtn.textContent = isPoll ? 'Post Poll' : 'Post';
        });
      }

      if (addPhotoBtn && file) {
        addPhotoBtn.addEventListener('click', function () { file.click(); });
      }

      if (file && img && pv) {
        file.addEventListener('change', function () {
          var f = file.files[0];
          if (!f) return;
          var rd = new FileReader();
          rd.onload = function () {
            img.src = rd.result;
            pv.hidden = false;
          };
          rd.readAsDataURL(f);
        });

        var rmBtn = pv.querySelector('.rm');
        if (rmBtn) {
          rmBtn.addEventListener('click', function () {
            img.src = '';
            pv.hidden = true;
            file.value = '';
          });
        }
      }

      if (doBtn) {
        doBtn.addEventListener('click', async function () {
          var activeTab = ctabs ? ctabs.querySelector('button.on') : null;
          var tabType = activeTab ? (activeTab.dataset.tab || (activeTab.textContent.toLowerCase().includes('poll') ? 'poll' : (activeTab.textContent.toLowerCase().includes('praise') ? 'praise' : 'post'))) : 'post';
          var isPoll = tabType === 'poll';
          var isPraise = tabType === 'praise';

          // Handle Poll Submission
          if (isPoll) {
            var q = pollQuestion ? pollQuestion.value.trim() : '';
            if (!q) {
              showToast('Please enter a poll question.');
              if (pollQuestion) pollQuestion.focus();
              return;
            }

            var pollOpts = [];
            if (pollOptsList) {
              pollOptsList.querySelectorAll('.poll-opt-input').forEach(function (inp) {
                var v = inp.value.trim();
                if (v) pollOpts.push(v);
              });
            }

            if (pollOpts.length < 2) {
              showToast('Please provide at least 2 poll options.');
              return;
            }

            var pollPayload = {
              type: 'poll',
              content: q,
              poll_question: q,
              poll_options: pollOpts
            };

            var res = await api('/api/community/create', 'POST', pollPayload);
            if (res && res.success) {
              showToast('Poll published successfully.');
              if (pollQuestion) pollQuestion.value = '';
              if (pollOptsList) {
                pollOptsList.innerHTML =
                  '<div class="poll-opt-row" style="display:flex;align-items:center;gap:8px"><input type="text" class="poll-opt-input" placeholder="Option 1" style="flex:1;padding:8px 12px;font-size:13.5px;border:1px solid var(--line);border-radius:6px;background:var(--surface)"></div>' +
                  '<div class="poll-opt-row" style="display:flex;align-items:center;gap:8px"><input type="text" class="poll-opt-input" placeholder="Option 2" style="flex:1;padding:8px 12px;font-size:13.5px;border:1px solid var(--line);border-radius:6px;background:var(--surface)"></div>';
              }
              loadCommunityFeed();
              loadSidebarPoll();
            } else {
              showToast(res && res.error ? res.error : 'Failed to publish poll.');
            }
            return;
          }

          // Handle Post / Praise Submission
          var txt = ta ? ta.value.trim() : '';
          var imgSrc = img && img.src ? img.src : null;
          if (!txt && !imgSrc) return;

          var recipientId = null;
          if (isPraise) {
            if (!praiseSelect || !praiseSelect.value) {
              alert('Please select a teammate to praise.');
              return;
            }
            recipientId = praiseSelect.value;
          }

          var payload = {
            content: txt,
            image_base64: imgSrc,
            type: isPraise ? 'praise' : 'update'
          };
          if (recipientId) {
            payload.recipient_id = recipientId;
          }

          var res = await api('/api/community/create', 'POST', payload);

          if (res && res.success) {
            showToast('Post created.');
            if (ta) ta.value = '';
            if (img) img.src = '';
            if (pv) pv.hidden = true;
            if (file) file.value = '';
            if (praiseSelect) praiseSelect.value = '';
            loadCommunityFeed();
          } else {
            alert(res && res.error ? res.error : 'Failed to publish post.');
          }
        });
      }
    });

    // Like Post delegation
    document.addEventListener('click', async function (e) {
      var btn = e.target.closest('.btn-like-post');
      if (btn) {
        var id = btn.dataset.id;
        var res = await api('/api/community/like', 'POST', { post_id: id });
        if (res && res.success) {
          var cntEl = btn.querySelector('.like-cnt');
          if (cntEl) cntEl.textContent = res.likes_count;
        }
      }
    });

    // Vote Poll delegation
    document.addEventListener('click', async function (e) {
      var opt = e.target.closest('.btn-vote-poll');
      if (opt) {
        var pollId = opt.dataset.pollId;
        var optionId = opt.dataset.optionId;
        var res = await api('/api/community/vote', 'POST', { poll_id: pollId, option_id: optionId });
        if (res && res.success) {
          showToast('Vote recorded.');
          loadCommunityFeed();
          loadSidebarPoll();
        } else {
          showToast(res && res.error ? res.error : 'Already voted.');
        }
      }
    });

    // Reports export CSV & PDF
    if ($('#btnExportCsv')) {
      $('#btnExportCsv').addEventListener('click', function () {
        var period = $('#selectReportPeriod') ? $('#selectReportPeriod').value : 'this_week';
        var apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) ? window.APP_CONFIG.apiBase : '/api';
        window.location.href = apiBase + '/reports/csv?period=' + encodeURIComponent(period);
      });
    }

    if ($('#btnExportPdf')) {
      $('#btnExportPdf').addEventListener('click', function () {
        var period = $('#selectReportPeriod') ? $('#selectReportPeriod').value : 'this_week';
        var apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) ? window.APP_CONFIG.apiBase : '/api';
        window.location.href = apiBase + '/reports/pdf?period=' + encodeURIComponent(period);
      });
    }

    if ($('#selectReportPeriod')) {
      $('#selectReportPeriod').addEventListener('change', function () {
        loadReports(this.value);
      });
    }

    // Save Settings
    if ($('#btnSaveSettings')) {
      $('#btnSaveSettings').addEventListener('click', async function () {
        var payload = {
          shift_start_time: ($('#settShiftStart') && $('#settShiftStart').value) ? $('#settShiftStart').value + ':00' : '10:00:00',
          shift_end_time: ($('#settShiftEnd') && $('#settShiftEnd').value) ? $('#settShiftEnd').value + ':00' : '19:00:00',
          work_week: $('#settWorkWeek').value,
          daily_target_hours: $('#settDailyTarget').value,
          late_grace_minutes: $('#settGraceMin').value,
          late_fine_enabled: $('#settLateFine').checked ? '1' : '0',
          auto_clock_out_time: $('#settAutoClockOut').value,
          manager_approval_required: $('#settMgrApproval').checked ? '1' : '0',
          carry_forward_leave: $('#settCarryForward').checked ? '1' : '0',
          selfie_on_punch: $('#settSelfiePunch').checked ? '1' : '0',
          location_on_punch: $('#settLocationPunch').checked ? '1' : '0',
          anomaly_distance_threshold_km: $('#settAnomalyThresholdKm') ? $('#settAnomalyThresholdKm').value : '100',
          delete_selfies_after_days: $('#settDeleteSelfieDays').value,
        };
        var res = await api('/api/settings/update', 'POST', payload);
        if (res && res.success) {
          showToast('Settings saved successfully.');
          var pub = await api('/api/settings/public');
          if (pub && pub.success && pub.settings) {
            state.publicSettings = pub.settings;
          }
        } else {
          alert(res && res.error ? res.error : 'Could not save settings.');
        }
      });
    }

    // Add Employee (uses dedicated add.php page)
    if ($('#btnCloseAddEmp')) {
      $('#btnCloseAddEmp').addEventListener('click', function () {
        $('#addEmpModal').hidden = true;
      });
    }
    if ($('#formAddEmp')) {
      $('#formAddEmp').addEventListener('submit', async function (e) {
        e.preventDefault();
        var payload = {
          name: $('#newEmpName').value.trim(),
          email: $('#newEmpEmail').value.trim(),
          phone: $('#newEmpPhone') ? $('#newEmpPhone').value.trim() : '',
          password: $('#newEmpPassword') ? $('#newEmpPassword').value.trim() : '',
          job_title: $('#newEmpTitle').value.trim(),
          team_id: $('#newEmpTeam') ? $('#newEmpTeam').value : '',
          role: $('#newEmpRole').value,
          date_of_birth: $('#newEmpDob') ? $('#newEmpDob').value : '',
          work_anniversary: $('#newEmpAnniv') ? $('#newEmpAnniv').value : '',
          joined_date: $('#newEmpJoined').value,
          require_camera: $('#newEmpRequireCamera') ? ($('#newEmpRequireCamera').checked ? 1 : 0) : 1,
          require_location: $('#newEmpRequireLocation') ? ($('#newEmpRequireLocation').checked ? 1 : 0) : 1,
        };
        var res = await api('/api/employees/create', 'POST', payload);
        if (res && res.success) {
          showToast('Employee added.');
          $('#addEmpModal').hidden = true;
          $('#formAddEmp').reset();
          if ($('#newEmpRequireCamera')) $('#newEmpRequireCamera').checked = true;
          if ($('#newEmpRequireLocation')) $('#newEmpRequireLocation').checked = true;
          loadEmployees();

          // Show temporary password modal if generated
          if (res.temp_password) {
            if ($('#displayTempPassword')) $('#displayTempPassword').textContent = res.temp_password;
            if ($('#tempPasswordModal')) $('#tempPasswordModal').hidden = false;
          }
        } else {
          alert(res && res.error ? res.error : 'Failed to create employee.');
        }
      });
    }

    // Temporary Password Modal Copy & Close
    if ($('#btnCopyTempPassword')) {
      $('#btnCopyTempPassword').addEventListener('click', function () {
        var txt = $('#displayTempPassword') ? $('#displayTempPassword').textContent : '';
        if (txt) {
          navigator.clipboard.writeText(txt).then(function () {
            showToast('Temporary password copied to clipboard.');
          }).catch(function () {
            showToast('Password: ' + txt);
          });
        }
      });
    }
    if ($('#btnCloseTempPwModal')) {
      $('#btnCloseTempPwModal').addEventListener('click', function () {
        if ($('#tempPasswordModal')) $('#tempPasswordModal').hidden = true;
      });
    }

    // Add Event Modal
    if ($('#btnOpenAddEvent')) {
      $('#btnOpenAddEvent').addEventListener('click', function () {
        $('#addEventModal').hidden = false;
      });
    }
    if ($('#btnCloseAddEvent')) {
      $('#btnCloseAddEvent').addEventListener('click', function () {
        $('#addEventModal').hidden = true;
      });
    }
    if ($('#formAddEvent')) {
      $('#formAddEvent').addEventListener('submit', async function (e) {
        e.preventDefault();
        var payload = {
          title: $('#newEventTitle').value.trim(),
          event_date: $('#newEventDate').value,
          event_time: $('#newEventTime').value.trim(),
          link: $('#newEventLink').value.trim()
        };
        var res = await api('/api/events/create', 'POST', payload);
        if (res && res.success) {
          showToast(res.message);
          $('#addEventModal').hidden = true;
          $('#formAddEvent').reset();
          loadUpcomingEvents();
        } else {
          alert(res && res.error ? res.error : 'Failed to create event.');
        }
      });
    }

    // Add Team (uses dedicated add.php?type=team page)
    if ($('#btnCloseAddTeam')) {
      $('#btnCloseAddTeam').addEventListener('click', function () {
        $('#addTeamModal').hidden = true;
      });
    }
    if ($('#formAddTeam')) {
      $('#formAddTeam').addEventListener('submit', async function (e) {
        e.preventDefault();
        var payload = {
          name: $('#newTeamName').value.trim(),
          manager_id: $('#newTeamManager').value || null
        };
        var res = await api('/api/teams/create', 'POST', payload);
        if (res && res.success) {
          showToast(res.message);
          $('#addTeamModal').hidden = true;
          $('#formAddTeam').reset();
          loadTeams();
        } else {
          alert(res && res.error ? res.error : 'Failed to create team.');
        }
      });
    }
    document.addEventListener('click', async function (e) {
      var btn = e.target.closest('.btn-delete-team');
      if (btn) {
        var id = btn.dataset.id;
        if (confirm('Delete this team? Assigned members will be unassigned.')) {
          var res = await api('/api/teams/delete', 'POST', { id: id });
          if (res && res.success) {
            showToast(res.message);
            loadTeams();
          } else {
            alert(res && res.error ? res.error : 'Failed to delete team.');
          }
        }
      }
    });

    // CSV Import Modal
    if ($('#btnOpenCsvImport')) {
      $('#btnOpenCsvImport').addEventListener('click', function () {
        $('#importCsvModal').hidden = false;
        if ($('#csvImportResults')) $('#csvImportResults').style.display = 'none';
      });
    }
    if ($('#btnCloseCsvModal')) {
      $('#btnCloseCsvModal').addEventListener('click', function () {
        $('#importCsvModal').hidden = true;
      });
    }
    if ($('#formImportCsv')) {
      $('#formImportCsv').addEventListener('submit', async function (e) {
        e.preventDefault();
        var fileInput = $('#csvFileInput');
        if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
          alert('Please select a CSV file.');
          return;
        }
        var formData = new FormData();
        formData.append('csv_file', fileInput.files[0]);
        var res = await api('/api/employees/import-csv', 'POST', formData);
        var resBox = $('#csvImportResults');
        if (res && res.success) {
          var rep = res.report;
          var errHtml = '';
          if (rep.rejected > 0 && rep.errors.length > 0) {
            errHtml = '<div style="margin-top:8px;max-height:120px;overflow-y:auto;color:var(--crit)">' +
              rep.errors.map(function (er) { return '<div>Row ' + er.row + ': ' + er.reason + '</div>'; }).join('') +
              '</div>';
          }
          if (resBox) {
            resBox.style.display = 'block';
            resBox.innerHTML = '<div style="font-weight:600;color:var(--ok)">Import complete: ' + rep.imported + ' imported, ' + rep.rejected + ' rejected.</div>' + errHtml;
          }
          showToast(res.message);
          loadEmployees();
        } else {
          alert(res && res.error ? res.error : 'Failed to import CSV.');
        }
      });
    }

    // Tab / Chip visual toggles
    document.querySelectorAll('.tabs, .chips, .ctabs').forEach(function (g) {
      g.addEventListener('click', function (e) {
        var b = e.target.closest('button');
        if (!b) return;
        g.querySelectorAll('button').forEach(function (x) {
          x.classList.toggle('on', x === b);
        });
      });
    });

    // Theme toggle
    if ($('#themeBtn')) {
      $('#themeBtn').addEventListener('click', function () {
        var r = document.documentElement;
        var cur = r.getAttribute('data-theme');
        var isDark = cur === 'dark';
        var nextTheme = isDark ? 'light' : 'dark';
        r.setAttribute('data-theme', nextTheme);
        localStorage.setItem('thatrank_theme', nextTheme);
      });
    }

    // Mobile menu toggle
    if ($('#menuBtn')) {
      $('#menuBtn').addEventListener('click', function () {
        $('#rail').classList.toggle('open');
        $('#shell').classList.toggle('navopen');
      });
    }

    // ===== Finances Events =====
    if ($('#finMonthSelect')) {
      $('#finMonthSelect').addEventListener('change', function () {
        if (this.value) loadFinances(financeState.selectedFy, this.value);
      });
    }

    if ($('#finFySelect')) {
      $('#finFySelect').addEventListener('change', function () {
        if (this.value) loadFinances(this.value);
      });
    }

    if ($('#finPayslipsListBody')) {
      $('#finPayslipsListBody').addEventListener('click', function (e) {
        var btn = e.target.closest('.download-slip-btn');
        if (btn && btn.dataset.id) {
          var apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) ? window.APP_CONFIG.apiBase : '/api';
          window.open(apiBase + '/finances/payslip-pdf?id=' + btn.dataset.id);
        }
      });
    }

    // ===== Payroll Run Events =====
    if ($('#payrollSubTabRun')) {
      $('#payrollSubTabRun').addEventListener('click', function () { switchPayrollTab('run'); });
    }
    if ($('#payrollSubTabComponents')) {
      $('#payrollSubTabComponents').addEventListener('click', function () { switchPayrollTab('components'); });
    }
    if ($('#payrollSubTabStructures')) {
      $('#payrollSubTabStructures').addEventListener('click', function () { switchPayrollTab('structures'); });
    }
    if ($('#payrollSubTabSettings')) {
      $('#payrollSubTabSettings').addEventListener('click', function () { switchPayrollTab('settings'); });
    }

    if ($('#payrollMonthPicker')) {
      $('#payrollMonthPicker').addEventListener('change', function () {
        if (this.value) loadPayroll(this.value);
      });
    }

    if ($('#payrollBtnGenerate')) {
      $('#payrollBtnGenerate').addEventListener('click', async function () {
        var curDate = new Date();
        var m = prompt('Enter month (1-12) to run payroll:', String(curDate.getMonth() + 1));
        if (!m) return;
        var y = prompt('Enter 4-digit year:', String(curDate.getFullYear()));
        if (!y) return;

        var res = await api('/api/payroll/generate', 'POST', {
          month: parseInt(m, 10),
          year: parseInt(y, 10)
        });

        if (res && res.success) {
          showToast(res.message || 'Payroll generated successfully.');
          loadPayroll(res.run ? res.run.id : null);
        } else {
          alert(res && res.error ? res.error : 'Failed to generate payroll.');
        }
      });
    }

    if ($('#payrollBtnFinalize')) {
      $('#payrollBtnFinalize').addEventListener('click', async function () {
        if (!payrollState.selectedRunId) return;
        if (!confirm('Finalizing this payroll will lock changes and publish payslips to employees according to company settings. Continue?')) return;

        var res = await api('/api/payroll/finalize', 'POST', {
          run_id: payrollState.selectedRunId
        });

        if (res && res.success) {
          showToast('Payroll run finalized.');
          loadPayroll(payrollState.selectedRunId);
        } else {
          alert(res && res.error ? res.error : 'Failed to finalize run.');
        }
      });
    }

    if ($('#payrollBtnPaid')) {
      $('#payrollBtnPaid').addEventListener('click', async function () {
        if (!payrollState.selectedRunId) return;
        if (!confirm('Mark all payslips in this run as Paid?')) return;

        var res = await api('/api/payroll/paid', 'POST', {
          run_id: payrollState.selectedRunId
        });

        if (res && res.success) {
          showToast('Payroll marked as paid.');
          loadPayroll(payrollState.selectedRunId);
        } else {
          alert(res && res.error ? res.error : 'Failed to mark as paid.');
        }
      });
    }

    if ($('#payrollBtnReopen')) {
      $('#payrollBtnReopen').addEventListener('click', async function () {
        if (!payrollState.selectedRunId) return;
        if (!confirm('Reopen this finalized payroll run back to draft? (Only permitted before any payslip is paid)')) return;

        var res = await api('/api/payroll/reopen', 'POST', {
          run_id: payrollState.selectedRunId
        });

        if (res && res.success) {
          showToast('Payroll run reopened to draft.');
          loadPayroll(payrollState.selectedRunId);
        } else {
          alert(res && res.error ? res.error : 'Failed to reopen run.');
        }
      });
    }

    if ($('#payrollBtnBankCsv')) {
      $('#payrollBtnBankCsv').addEventListener('click', function () {
        if (!payrollState.selectedRunId) {
          alert('Select or generate a payroll run first.');
          return;
        }
        var apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) ? window.APP_CONFIG.apiBase : '/api';
        window.open(apiBase + '/payroll/export/bank?run_id=' + payrollState.selectedRunId);
      });
    }

    if ($('#payrollBtnSummaryCsv')) {
      $('#payrollBtnSummaryCsv').addEventListener('click', function () {
        if (!payrollState.selectedRunId) {
          alert('Select or generate a payroll run first.');
          return;
        }
        var apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) ? window.APP_CONFIG.apiBase : '/api';
        window.open(apiBase + '/payroll/export/summary?run_id=' + payrollState.selectedRunId);
      });
    }

    if ($('#payrollBtnZip')) {
      $('#payrollBtnZip').addEventListener('click', function () {
        if (!payrollState.selectedRunId) {
          alert('Select or generate a payroll run first.');
          return;
        }
        var apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) ? window.APP_CONFIG.apiBase : '/api';
        window.open(apiBase + '/payroll/export/zip?run_id=' + payrollState.selectedRunId);
      });
    }

    if ($('#payrollTableBody')) {
      $('#payrollTableBody').addEventListener('click', async function (e) {
        var adjBtn = e.target.closest('.adjust-slip-btn');
        if (adjBtn && adjBtn.dataset.id) {
          var slipId = parseInt(adjBtn.dataset.id, 10);
          var slip = (payrollState.data && payrollState.data.payslips) ? payrollState.data.payslips.find(function (x) { return x.id === slipId; }) : null;
          if (slip) openPayslipAdjustModal(slip);
          return;
        }

        var regenBtn = e.target.closest('.regen-slip-btn');
        if (regenBtn && regenBtn.dataset.uid && regenBtn.dataset.rid) {
          regenBtn.disabled = true;
          regenBtn.textContent = '...';
          var res = await api('/api/payroll/regenerate-employee', 'POST', {
            run_id: parseInt(regenBtn.dataset.rid, 10),
            user_id: parseInt(regenBtn.dataset.uid, 10)
          });
          if (res && res.success) {
            showToast('Employee recalculation completed.');
            loadPayroll(payrollState.selectedRunId);
          } else {
            alert(res && res.error ? res.error : 'Regenerate failed.');
            regenBtn.disabled = false;
            regenBtn.textContent = 'Regen';
          }
          return;
        }

        var pdfBtn = e.target.closest('.view-slip-pdf-btn');
        if (pdfBtn && pdfBtn.dataset.id) {
          var apiBase = (window.APP_CONFIG && window.APP_CONFIG.apiBase) ? window.APP_CONFIG.apiBase : '/api';
          window.open(apiBase + '/finances/payslip-pdf?id=' + pdfBtn.dataset.id);
        }
      });
    }

    // Modal: Payslip Adjust
    if ($('#btnClosePayslipAdjustModal')) {
      $('#btnClosePayslipAdjustModal').addEventListener('click', function () {
        $('#modalPayslipAdjust').hidden = true;
      });
    }
    if ($('#btnAddAdjustmentRow')) {
      $('#btnAddAdjustmentRow').addEventListener('click', function () {
        appendAdjustmentRow('earning', '', 0);
      });
    }
    if ($('#formPayslipAdjust')) {
      $('#formPayslipAdjust').addEventListener('submit', async function (e) {
        e.preventDefault();
        var payslipId = parseInt($('#adjustPayslipId').value, 10);
        var lopDays = parseFloat($('#adjustLopDays').value) || 0.0;

        var adjustments = [];
        $$('#adjustmentsRowsContainer .adj-row').forEach(function (row) {
          var type = row.querySelector('.adj-type').value;
          var name = row.querySelector('.adj-name').value.trim();
          var amt = parseFloat(row.querySelector('.adj-amt').value) || 0.0;
          if (name && amt > 0) {
            adjustments.push({ type: type, name: name, amount: amt });
          }
        });

        var res = await api('/api/payroll/update-payslip', 'POST', {
          payslip_id: payslipId,
          lop_days: lopDays,
          adjustments: adjustments
        });

        if (res && res.success) {
          showToast('Adjustments updated.');
          $('#modalPayslipAdjust').hidden = true;
          loadPayroll(payrollState.selectedRunId);
        } else {
          alert(res && res.error ? res.error : 'Failed to update adjustments.');
        }
      });
    }

    // Modal: Salary Component
    if ($('#payrollBtnAddComponent')) {
      $('#payrollBtnAddComponent').addEventListener('click', function () {
        openSalaryComponentModal(null);
      });
    }
    if ($('#btnCloseSalaryComponentModal')) {
      $('#btnCloseSalaryComponentModal').addEventListener('click', function () {
        $('#modalSalaryComponent').hidden = true;
      });
    }
    if ($('#formSalaryComponent')) {
      $('#formSalaryComponent').addEventListener('submit', async function (e) {
        e.preventDefault();
        var payload = {
          id: $('#comp_id').value ? parseInt($('#comp_id').value, 10) : 0,
          name: $('#comp_name').value.trim(),
          type: $('#comp_type').value,
          calc_type: $('#comp_calc_type').value,
          default_value: parseFloat($('#comp_default_value').value) || 0,
          is_statutory: $('#comp_statutory').value,
          sort_order: parseInt($('#comp_sort_order').value, 10) || 10,
          active: $('#comp_active').value === '1' ? 1 : 0,
          applies_to_all: $('#comp_applies_all').checked ? 1 : 0,
          show_on_payslip: $('#comp_show_payslip').checked ? 1 : 0
        };

        var res = await api('/api/payroll/components/save', 'POST', payload);
        if (res && res.success) {
          showToast(res.message || 'Component saved.');
          $('#modalSalaryComponent').hidden = true;
          loadSalaryComponents();
        } else {
          alert(res && res.error ? res.error : 'Failed to save component.');
        }
      });
    }

    if ($('#payrollComponentsTableBody')) {
      $('#payrollComponentsTableBody').addEventListener('click', async function (e) {
        var editBtn = e.target.closest('.edit-comp-btn');
        if (editBtn && editBtn.dataset.id) {
          var cid = parseInt(editBtn.dataset.id, 10);
          var comp = payrollState.components.find(function (c) { return c.id === cid; });
          if (comp) openSalaryComponentModal(comp);
          return;
        }

        var toggleBtn = e.target.closest('.toggle-comp-btn');
        if (toggleBtn && toggleBtn.dataset.id) {
          var cid = parseInt(toggleBtn.dataset.id, 10);
          var res = await api('/api/payroll/components/toggle', 'POST', { id: cid });
          if (res && res.success) {
            showToast('Component status updated.');
            loadSalaryComponents();
          } else {
            alert(res && res.error ? res.error : 'Failed to update component status.');
          }
          return;
        }

        var upBtn = e.target.closest('.comp-order-up');
        var downBtn = e.target.closest('.comp-order-down');
        if (upBtn || downBtn) {
          var idx = parseInt((upBtn || downBtn).dataset.idx, 10);
          var targetIdx = upBtn ? (idx - 1) : (idx + 1);
          if (targetIdx >= 0 && targetIdx < payrollState.components.length) {
            var temp = payrollState.components[idx];
            payrollState.components[idx] = payrollState.components[targetIdx];
            payrollState.components[targetIdx] = temp;

            var orders = payrollState.components.map(function (c, i) {
              return { id: c.id, sort_order: (i + 1) * 10 };
            });

            await api('/api/payroll/components/reorder', 'POST', { orders: orders });
            loadSalaryComponents();
          }
        }
      });
    }

    // Modal: Salary Structures
    if ($('#payrollBulkSelectAll')) {
      $('#payrollBulkSelectAll').addEventListener('change', function () {
        var checked = this.checked;
        $$('.emp-struct-chk').forEach(function (chk) {
          chk.checked = checked;
          var uid = parseInt(chk.dataset.id, 10);
          if (checked) {
            payrollState.selectedEmployeeIds.add(uid);
          } else {
            payrollState.selectedEmployeeIds.delete(uid);
          }
        });
        updateBulkButtonState();
      });
    }

    if ($('#payrollStructuresTableBody')) {
      $('#payrollStructuresTableBody').addEventListener('change', function (e) {
        var chk = e.target.closest('.emp-struct-chk');
        if (chk && chk.dataset.id) {
          var uid = parseInt(chk.dataset.id, 10);
          if (chk.checked) {
            payrollState.selectedEmployeeIds.add(uid);
          } else {
            payrollState.selectedEmployeeIds.delete(uid);
          }
          updateBulkButtonState();
        }
      });

      $('#payrollStructuresTableBody').addEventListener('click', function (e) {
        var editBtn = e.target.closest('.edit-struct-btn');
        if (editBtn && editBtn.dataset.id) {
          var uid = parseInt(editBtn.dataset.id, 10);
          var emp = payrollState.structures.find(function (x) { return x.id === uid; });
          if (emp) openSalaryStructureModal(emp);
          return;
        }

        var histBtn = e.target.closest('.hist-struct-btn');
        if (histBtn && histBtn.dataset.id) {
          openStructureHistoryModal(parseInt(histBtn.dataset.id, 10), histBtn.dataset.name || 'Employee');
        }
      });
    }

    if ($('#btnCloseSalaryStructureModal')) {
      $('#btnCloseSalaryStructureModal').addEventListener('click', function () {
        $('#modalSalaryStructure').hidden = true;
      });
    }
    if ($('#formSalaryStructure')) {
      $('#formSalaryStructure').addEventListener('submit', async function (e) {
        e.preventDefault();
        var uid = parseInt($('#struct_user_id').value, 10);
        var eff = $('#struct_effective_from').value;

        var vals = {};
        $$('.struct-comp-input', $('#structComponentFieldsContainer')).forEach(function (inp) {
          vals[inp.dataset.cid] = parseFloat(inp.value) || 0.0;
        });

        var res = await api('/api/payroll/structures/save', 'POST', {
          user_id: uid,
          effective_from: eff,
          component_values: vals
        });

        if (res && res.success) {
          showToast('Salary structure saved.');
          $('#modalSalaryStructure').hidden = true;
          loadSalaryStructures();
        } else {
          alert(res && res.error ? res.error : 'Failed to save salary structure.');
        }
      });
    }

    if ($('#payrollBtnBulkStructure')) {
      $('#payrollBtnBulkStructure').addEventListener('click', function () {
        if (payrollState.selectedEmployeeIds.size === 0) {
          alert('Select at least one employee using the checkboxes.');
          return;
        }
        openBulkSalaryStructureModal();
      });
    }
    if ($('#btnCloseBulkSalaryStructureModal')) {
      $('#btnCloseBulkSalaryStructureModal').addEventListener('click', function () {
        $('#modalBulkSalaryStructure').hidden = true;
      });
    }
    if ($('#formBulkSalaryStructure')) {
      $('#formBulkSalaryStructure').addEventListener('submit', async function (e) {
        e.preventDefault();
        var eff = $('#bulk_struct_effective_from').value;
        var uids = Array.from(payrollState.selectedEmployeeIds);

        var vals = {};
        $$('.bulk-comp-input', $('#bulkStructComponentFieldsContainer')).forEach(function (inp) {
          vals[inp.dataset.cid] = parseFloat(inp.value) || 0.0;
        });

        var res = await api('/api/payroll/structures/bulk-save', 'POST', {
          user_ids: uids,
          effective_from: eff,
          component_values: vals
        });

        if (res && res.success) {
          showToast(res.message || 'Bulk structures applied.');
          $('#modalBulkSalaryStructure').hidden = true;
          loadSalaryStructures();
        } else {
          alert(res && res.error ? res.error : 'Failed to apply bulk structure.');
        }
      });
    }

    if ($('#btnCloseStructureHistoryModal')) {
      $('#btnCloseStructureHistoryModal').addEventListener('click', function () {
        $('#modalStructureHistory').hidden = true;
      });
    }

    // Modal: Payroll Settings Save
    if ($('#payrollBtnSaveSettings')) {
      $('#payrollBtnSaveSettings').addEventListener('click', async function (e) {
        e.preventDefault();
        var payload = {
          company_legal_name: $('#pset_company_legal_name').value.trim(),
          currency_symbol: $('#pset_currency_symbol').value.trim(),
          company_address: $('#pset_company_address').value.trim(),
          payroll_logo: $('#pset_payroll_logo').value.trim(),
          payslip_footer_text: $('#pset_payslip_footer_text').value.trim(),
          payroll_payday: $('#pset_payroll_payday').value,
          financial_year_start_month: $('#pset_financial_year_start_month').value,
          payslip_visibility: $('#pset_payslip_visibility').value,
          lop_divisor: $('#pset_lop_divisor').value,
          payroll_rounding: $('#pset_payroll_rounding').value,
          tds_label: $('#pset_tds_label').value.trim(),
          pf_rate: $('#pset_pf_rate').value,
          pf_wage_ceiling_enabled: $('#pset_pf_wage_ceiling_enabled').value,
          pf_wage_ceiling: $('#pset_pf_wage_ceiling').value,
          esi_rate: $('#pset_esi_rate').value,
          esi_gross_threshold: $('#pset_esi_gross_threshold').value,
          default_professional_tax: $('#pset_default_professional_tax').value
        };

        var res = await api('/api/payroll/settings/update', 'POST', { settings: payload });
        if (res && res.success) {
          showToast('Payroll settings saved successfully.');
          payrollState.settings = res.settings;
        } else {
          alert(res && res.error ? res.error : 'Failed to save settings.');
        }
      });
    }
  }

  // ==========================================
  // ROLES & PERMISSIONS MODULE
  // ==========================================
  var rolesState = {
    roles: [],
    catalog: null,
    currentRoleData: null,
    createPerms: {},
    editPerms: {}
  };

  async function loadUserPermissions() {
    try {
      var res = await api('/api/roles/my-permissions');
      if (res && res.success && res.permissions) {
        state.permissions = res.permissions;
        applyRoleNavigation();
      }
    } catch (e) {
      console.error('Failed to load user permissions:', e);
    }
  }

  function showRolesSubView(viewName) {
    var vList = $('#rolesListView');
    var vCreate = $('#rolesCreateView');
    var vDetail = $('#rolesDetailView');
    var vEdit = $('#rolesEditView');

    if (vList) vList.style.display = viewName === 'list' ? 'block' : 'none';
    if (vCreate) vCreate.style.display = viewName === 'create' ? 'block' : 'none';
    if (vDetail) vDetail.style.display = viewName === 'detail' ? 'block' : 'none';
    if (vEdit) vEdit.style.display = viewName === 'edit' ? 'block' : 'none';

    window.scrollTo(0, 0);
  }

  async function loadRoles() {
    if (!state.user || state.user.role !== 'admin') return;

    try {
      var res = await api('/api/roles');
      if (res && res.success) {
        rolesState.roles = res.roles || [];
        rolesState.catalog = res.catalog || {};
        renderRolesTable();
        showRolesSubView('list');
      } else {
        alert(res && res.error ? res.error : 'Failed to load roles.');
      }
    } catch (e) {
      console.error('Error loading roles:', e);
    }
  }

  function renderRolesTable() {
    var tbody = $('#rolesTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';

    if (!rolesState.roles || rolesState.roles.length === 0) {
      tbody.innerHTML = '<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted)">No roles found.</td></tr>';
      return;
    }

    rolesState.roles.forEach(function (r) {
      var tr = document.createElement('tr');

      var typeBadge = r.is_system
        ? '<span class="pill sm">System</span>'
        : '<span class="pill sm plain">Custom</span>';

      var actionButtons = '<button type="button" class="btn xs quiet btn-view-role" data-slug="' + r.slug + '">View</button> ' +
        '<button type="button" class="btn xs quiet btn-edit-role" data-slug="' + r.slug + '">Edit</button>';

      if (!r.is_system) {
        actionButtons += ' <button type="button" class="btn xs danger btn-delete-role" data-slug="' + r.slug + '" data-name="' + escapeHtml(r.name) + '">Delete</button>';
      } else {
        actionButtons += ' <span style="font-size:11px;color:var(--muted);padding-left:4px" title="System roles cannot be deleted">Protected</span>';
      }

      tr.innerHTML = '<td>' +
        '<div style="font-weight:600;font-size:13.5px;color:var(--fg)">' + escapeHtml(r.name) + '</div>' +
        '<div class="meta" style="font-size:11.5px">' + escapeHtml(r.slug) + '</div>' +
        '</td>' +
        '<td style="font-size:13px;color:var(--fg-2);max-width:320px">' + escapeHtml(r.description || 'No description provided.') + '</td>' +
        '<td>' + typeBadge + '</td>' +
        '<td class="num r">' + r.user_count + '</td>' +
        '<td class="num r"><b style="color:var(--fg)">' + r.granted_permissions + '</b> <span style="color:var(--muted)">/ ' + r.total_permissions + '</span></td>' +
        '<td class="r"><div class="rowbtns" style="justify-content:flex-end">' + actionButtons + '</div></td>';

      tbody.appendChild(tr);
    });

    // Wire action buttons
    tbody.querySelectorAll('.btn-view-role').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openViewRole(this.dataset.slug);
      });
    });

    tbody.querySelectorAll('.btn-edit-role').forEach(function (btn) {
      btn.addEventListener('click', function () {
        openEditRole(this.dataset.slug);
      });
    });

    tbody.querySelectorAll('.btn-delete-role').forEach(function (btn) {
      btn.addEventListener('click', function () {
        deleteRole(this.dataset.slug, this.dataset.name);
      });
    });
  }

  function renderModuleCheckboxes(containerId, permsMap, isReadOnly, onChangeCallback) {
    var container = $('#' + containerId);
    if (!container || !rolesState.catalog) return;
    container.innerHTML = '';

    Object.keys(rolesState.catalog).forEach(function (modKey) {
      var mod = rolesState.catalog[modKey];
      var card = document.createElement('div');
      card.className = 'panel';
      card.style.marginBottom = '0';

      var toggleBtnHtml = !isReadOnly
        ? '<button type="button" class="btn xs quiet btn-toggle-mod-card" data-mod="' + modKey + '">Toggle All</button>'
        : '';

      var headerHtml = '<div class="hd" style="display:flex;justify-content:space-between;align-items:center;padding:12px 16px">' +
        '<div>' +
        '<h3 style="font-size:14px;font-weight:600;margin:0;color:var(--fg)">' + mod.title + '</h3>' +
        '<span class="meta" style="font-size:12px;color:var(--muted)">' + mod.description + '</span>' +
        '</div>' +
        toggleBtnHtml +
        '</div>';

      var listHtml = '<div class="bd" style="padding:14px 16px;display:flex;flex-direction:column;gap:12px">';
      Object.keys(mod.permissions).forEach(function (permKey) {
        var pInfo = mod.permissions[permKey];
        var isChecked = !(!permsMap[permKey]);

        if (isReadOnly) {
          var statusPill = isChecked
            ? '<span class="pill ok xs" style="margin-left:auto;flex-shrink:0">Enabled</span>'
            : '<span class="pill plain xs" style="margin-left:auto;flex-shrink:0;color:var(--muted)">Disabled</span>';

          listHtml += '<div style="display:flex;align-items:center;justify-content:space-between;gap:10px">' +
            '<div style="min-width:0">' +
            '<div style="font-size:13px;font-weight:600;color:var(--fg)">' + pInfo.name + '</div>' +
            '<div style="font-size:12px;color:var(--muted);margin-top:1px">' + pInfo.desc + '</div>' +
            '</div>' +
            statusPill +
            '</div>';
        } else {
          listHtml += '<label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;user-select:none">' +
            '<input type="checkbox" class="role-grid-cb" data-mod="' + modKey + '" data-key="' + permKey + '" ' + (isChecked ? 'checked' : '') + ' style="width:17px;height:17px;margin-top:2px;accent-color:var(--accent);cursor:pointer;flex-shrink:0">' +
            '<div style="min-width:0">' +
            '<div style="font-size:13px;font-weight:600;color:var(--fg)">' + pInfo.name + '</div>' +
            '<div style="font-size:12px;color:var(--muted);margin-top:1px">' + pInfo.desc + '</div>' +
            '</div>' +
            '</label>';
        }
      });
      listHtml += '</div>';

      card.innerHTML = headerHtml + listHtml;
      container.appendChild(card);
    });

    if (!isReadOnly && onChangeCallback) {
      container.querySelectorAll('.role-grid-cb').forEach(function (cb) {
        cb.addEventListener('change', function () {
          permsMap[this.dataset.key] = this.checked;
          onChangeCallback();
        });
      });

      container.querySelectorAll('.btn-toggle-mod-card').forEach(function (btn) {
        btn.addEventListener('click', function () {
          var mKey = this.dataset.mod;
          var mod = rolesState.catalog[mKey];
          if (!mod) return;

          var allChecked = true;
          Object.keys(mod.permissions).forEach(function (pKey) {
            if (!permsMap[pKey]) allChecked = false;
          });

          var targetVal = !allChecked;
          Object.keys(mod.permissions).forEach(function (pKey) {
            permsMap[pKey] = targetVal;
            var el = container.querySelector('.role-grid-cb[data-key="' + pKey + '"]');
            if (el) el.checked = targetVal;
          });
          onChangeCallback();
        });
      });
    }
  }

  function countActivePerms(permsMap) {
    if (!rolesState.catalog) return 0;
    var count = 0;
    Object.keys(rolesState.catalog).forEach(function (mKey) {
      Object.keys(rolesState.catalog[mKey].permissions).forEach(function (pKey) {
        if (permsMap && permsMap[pKey]) count++;
      });
    });
    return count;
  }

  function openCreateRole() {
    if ($('#newRoleName')) $('#newRoleName').value = '';
    if ($('#newRoleSlug')) $('#newRoleSlug').value = '';
    if ($('#newRoleDesc')) $('#newRoleDesc').value = '';
    if ($('#newRoleTemplate')) $('#newRoleTemplate').value = 'manager';

    applyCreateTemplate('manager');
    showRolesSubView('create');
  }

  function applyCreateTemplate(preset) {
    rolesState.createPerms = {};
    if (!rolesState.catalog) return;

    Object.keys(rolesState.catalog).forEach(function (mKey) {
      var mod = rolesState.catalog[mKey];
      Object.keys(mod.permissions).forEach(function (pKey) {
        var pInfo = mod.permissions[pKey];
        if (preset === 'scratch') {
          rolesState.createPerms[pKey] = false;
        } else if (preset === 'employee') {
          rolesState.createPerms[pKey] = !emptyVal(pInfo.default_emp);
        } else {
          rolesState.createPerms[pKey] = !emptyVal(pInfo.default_mgr);
        }
      });
    });

    renderCreatePermsGrid();
  }

  function emptyVal(v) {
    return !v || v === 0 || v === '0';
  }

  function renderCreatePermsGrid() {
    renderModuleCheckboxes('newRolePermissionsGrid', rolesState.createPerms, false, function () {
      updateCreatePermsCount();
    });
    updateCreatePermsCount();
  }

  function updateCreatePermsCount() {
    var c = countActivePerms(rolesState.createPerms);
    if ($('#newRoleActivePermsCount')) $('#newRoleActivePermsCount').textContent = c;
  }

  async function submitCreateRole() {
    var name = $('#newRoleName') ? $('#newRoleName').value.trim() : '';
    var slug = $('#newRoleSlug') ? $('#newRoleSlug').value.trim() : '';
    var desc = $('#newRoleDesc') ? $('#newRoleDesc').value.trim() : '';

    if (!name) {
      alert('Please enter a role name.');
      if ($('#newRoleName')) $('#newRoleName').focus();
      return;
    }

    var payload = {
      name: name,
      slug: slug,
      description: desc,
      permissions: rolesState.createPerms
    };

    try {
      var res = await api('/api/roles/create', 'POST', payload);
      if (res && res.success) {
        showToast(res.message || 'Role created successfully.');
        loadRoles();
      } else {
        alert(res && res.error ? res.error : 'Failed to create role.');
      }
    } catch (e) {
      console.error('Error creating role:', e);
      alert('An error occurred while creating the role.');
    }
  }

  async function openViewRole(slug) {
    try {
      var res = await api('/api/roles/get?slug=' + encodeURIComponent(slug));
      if (!res || !res.success) {
        alert(res && res.error ? res.error : 'Failed to load role details.');
        return;
      }

      rolesState.currentRoleData = res;
      var role = res.role;

      if ($('#viewRoleTitle')) $('#viewRoleTitle').textContent = role.name;
      if ($('#viewRoleSlugMeta')) $('#viewRoleSlugMeta').textContent = role.slug;
      if ($('#viewRoleDesc')) $('#viewRoleDesc').textContent = role.description || 'No description provided.';
      if ($('#viewRoleTypeBadge')) {
        $('#viewRoleTypeBadge').innerHTML = role.is_system
          ? '<span class="pill sm">System</span>'
          : '<span class="pill sm plain">Custom</span>';
      }

      var userCount = res.assigned_users ? res.assigned_users.length : 0;
      if ($('#viewRoleStaffCount')) $('#viewRoleStaffCount').textContent = userCount;

      var totalPerms = 0;
      var activePerms = 0;
      Object.keys(res.catalog).forEach(function (mKey) {
        Object.keys(res.catalog[mKey].permissions).forEach(function (pKey) {
          totalPerms++;
          if (role.slug === 'admin' || (res.permissions && res.permissions[pKey])) {
            activePerms++;
          }
        });
      });
      if ($('#viewRolePermCount')) $('#viewRolePermCount').textContent = activePerms + ' / ' + totalPerms;

      // Populate assigned users chips
      var userContainer = $('#viewRoleUsersContainer');
      if (userContainer) {
        userContainer.innerHTML = '';
        if (!res.assigned_users || res.assigned_users.length === 0) {
          userContainer.innerHTML = '<span style="font-size:13px;color:var(--muted)">No staff currently assigned to this role.</span>';
        } else {
          res.assigned_users.forEach(function (u) {
            var chip = document.createElement('div');
            chip.className = 'pill sm plain';
            chip.style.display = 'inline-flex';
            chip.style.alignItems = 'center';
            chip.style.gap = '6px';
            chip.innerHTML = '<span class="avatar xs" style="background:' + (u.avatar_color || 'var(--ink)') + '">' + (u.avatar_initials || 'TR') + '</span> ' +
              '<span>' + escapeHtml(u.name) + '</span>' +
              (u.job_title ? '<span style="color:var(--muted);font-weight:400">(' + escapeHtml(u.job_title) + ')</span>' : '');
            userContainer.appendChild(chip);
          });
        }
      }

      // Render read-only permission matrix
      renderModuleCheckboxes('viewRolePermissionsGrid', res.permissions || {}, true);

      showRolesSubView('detail');
    } catch (e) {
      console.error('Error opening view role:', e);
      alert('An error occurred while loading role details.');
    }
  }

  async function openEditRole(slug) {
    try {
      var res = await api('/api/roles/get?slug=' + encodeURIComponent(slug));
      if (!res || !res.success) {
        alert(res && res.error ? res.error : 'Failed to load role details.');
        return;
      }

      rolesState.currentRoleData = res;
      var role = res.role;
      var isAdmin = role.slug === 'admin';

      if ($('#editRoleSlug')) $('#editRoleSlug').value = role.slug;
      if ($('#editRoleName')) $('#editRoleName').value = role.name;
      if ($('#editRoleDesc')) $('#editRoleDesc').value = role.description || '';
      if ($('#editRoleTitleName')) $('#editRoleTitleName').textContent = role.name;
      if ($('#editRoleSlugMeta')) $('#editRoleSlugMeta').textContent = role.slug;

      rolesState.editPerms = Object.assign({}, res.permissions || {});

      // Admin role notice
      if ($('#editRoleAdminNotice')) $('#editRoleAdminNotice').style.display = isAdmin ? 'block' : 'none';
      if ($('#editRolePermissionsHeader')) $('#editRolePermissionsHeader').style.display = isAdmin ? 'none' : 'flex';
      if ($('#editRolePermissionsGrid')) $('#editRolePermissionsGrid').style.display = isAdmin ? 'none' : 'grid';

      if (!isAdmin) {
        renderEditPermsGrid();
      }

      showRolesSubView('edit');
    } catch (e) {
      console.error('Error opening edit role:', e);
      alert('An error occurred while loading role for editing.');
    }
  }

  function renderEditPermsGrid() {
    renderModuleCheckboxes('editRolePermissionsGrid', rolesState.editPerms, false, function () {
      updateEditPermsCount();
    });
    updateEditPermsCount();
  }

  function updateEditPermsCount() {
    var c = countActivePerms(rolesState.editPerms);
    if ($('#editRoleActivePermsCount')) $('#editRoleActivePermsCount').textContent = c;
  }

  async function submitUpdateRole() {
    var slug = $('#editRoleSlug') ? $('#editRoleSlug').value.trim() : '';
    var name = $('#editRoleName') ? $('#editRoleName').value.trim() : '';
    var desc = $('#editRoleDesc') ? $('#editRoleDesc').value.trim() : '';

    if (!slug) {
      alert('Invalid role slug.');
      return;
    }
    if (!name) {
      alert('Please enter a role name.');
      if ($('#editRoleName')) $('#editRoleName').focus();
      return;
    }

    var payload = {
      slug: slug,
      name: name,
      description: desc,
      permissions: rolesState.editPerms
    };

    try {
      var res = await api('/api/roles/update', 'POST', payload);
      if (res && res.success) {
        showToast(res.message || 'Role updated successfully.');

        // If current user has this role, refresh live permissions
        if (state.user && state.user.role === slug) {
          state.permissions = rolesState.editPerms;
          applyRoleNavigation();
        }

        loadRoles();
      } else {
        alert(res && res.error ? res.error : 'Failed to update role.');
      }
    } catch (e) {
      console.error('Error updating role:', e);
      alert('An error occurred while updating the role.');
    }
  }

  async function deleteRole(slug, name) {
    if (!confirm('Are you sure you want to delete role "' + name + '"?\n\nThis action cannot be undone.')) {
      return;
    }

    try {
      var res = await api('/api/roles/delete', 'POST', { slug: slug });
      if (res && res.success) {
        showToast(res.message || 'Role deleted successfully.');
        loadRoles();
      } else {
        alert(res && res.error ? res.error : 'Failed to delete role.');
      }
    } catch (e) {
      console.error('Error deleting role:', e);
      alert('An error occurred while deleting the role.');
    }
  }

  function bindRoleEvents() {
    // Open Create Role view
    var btnOpenCreate = $('#btnOpenCreateRole');
    if (btnOpenCreate) btnOpenCreate.addEventListener('click', openCreateRole);

    // Back to roles table buttons
    $$('.btn-back-to-roles').forEach(function (btn) {
      btn.addEventListener('click', function () {
        showRolesSubView('list');
      });
    });

    // Create role submission
    var btnSubmitCreate = $('#btnSubmitCreateRole');
    var btnSubmitCreateBottom = $('#btnSubmitCreateRoleBottom');
    if (btnSubmitCreate) btnSubmitCreate.addEventListener('click', submitCreateRole);
    if (btnSubmitCreateBottom) btnSubmitCreateBottom.addEventListener('click', submitCreateRole);

    // Create role template change
    var selTemplate = $('#newRoleTemplate');
    if (selTemplate) {
      selTemplate.addEventListener('change', function () {
        applyCreateTemplate(this.value);
      });
    }

    // Create role Select All / Deselect All
    var btnSelectAllNew = $('#btnSelectAllNewPerms');
    if (btnSelectAllNew) {
      btnSelectAllNew.addEventListener('click', function () {
        if (!rolesState.catalog) return;
        Object.keys(rolesState.catalog).forEach(function (mKey) {
          Object.keys(rolesState.catalog[mKey].permissions).forEach(function (pKey) {
            rolesState.createPerms[pKey] = true;
          });
        });
        renderCreatePermsGrid();
      });
    }

    var btnDeselectAllNew = $('#btnDeselectAllNewPerms');
    if (btnDeselectAllNew) {
      btnDeselectAllNew.addEventListener('click', function () {
        if (!rolesState.catalog) return;
        Object.keys(rolesState.catalog).forEach(function (mKey) {
          Object.keys(rolesState.catalog[mKey].permissions).forEach(function (pKey) {
            rolesState.createPerms[pKey] = false;
          });
        });
        renderCreatePermsGrid();
      });
    }

    // Edit from view
    var btnEditFromView = $('#btnEditFromView');
    if (btnEditFromView) {
      btnEditFromView.addEventListener('click', function () {
        if (rolesState.currentRoleData && rolesState.currentRoleData.role) {
          openEditRole(rolesState.currentRoleData.role.slug);
        }
      });
    }

    // Update role submission
    var btnSubmitUpdate = $('#btnSubmitUpdateRole');
    var btnSubmitUpdateBottom = $('#btnSubmitUpdateRoleBottom');
    if (btnSubmitUpdate) btnSubmitUpdate.addEventListener('click', submitUpdateRole);
    if (btnSubmitUpdateBottom) btnSubmitUpdateBottom.addEventListener('click', submitUpdateRole);

    // Edit role Select All / Deselect All
    var btnSelectAllEdit = $('#btnSelectAllEditPerms');
    if (btnSelectAllEdit) {
      btnSelectAllEdit.addEventListener('click', function () {
        if (!rolesState.catalog) return;
        Object.keys(rolesState.catalog).forEach(function (mKey) {
          Object.keys(rolesState.catalog[mKey].permissions).forEach(function (pKey) {
            rolesState.editPerms[pKey] = true;
          });
        });
        renderEditPermsGrid();
      });
    }

    var btnDeselectAllEdit = $('#btnDeselectAllEditPerms');
    if (btnDeselectAllEdit) {
      btnDeselectAllEdit.addEventListener('click', function () {
        if (!rolesState.catalog) return;
        Object.keys(rolesState.catalog).forEach(function (mKey) {
          Object.keys(rolesState.catalog[mKey].permissions).forEach(function (pKey) {
            rolesState.editPerms[pKey] = false;
          });
        });
        renderEditPermsGrid();
      });
    }
  }

  // Restore saved theme
  var savedTheme = localStorage.getItem('thatrank_theme');
  if (savedTheme) {
    document.documentElement.setAttribute('data-theme', savedTheme);
  }

  // Run on page load
  document.addEventListener('DOMContentLoaded', function () {
    bindEvents();
    bindRoleEvents();
    initAuth();
    setInterval(tick, 1000);
    tick();

    // Register Service Worker for PWA
    if ('serviceWorker' in navigator) {
      var swPath = (window.APP_CONFIG && window.APP_CONFIG.basePath ? window.APP_CONFIG.basePath : '') + '/sw.js';
      navigator.serviceWorker.register(swPath).catch(function () {});
    }
  });
})();
