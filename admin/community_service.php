<?php
// File: C:\xampp\htdocs\identitrack\admin\community_service.php
// Community Service Management with tabs
//
// UPDATE:
// - Supports deep-link from notifications: community_service.php?q=STUDENT_ID
//   - Automatically switches to the correct tab (active/pending/history) where the student appears
//   - Highlights matching cards
//
// How it works:
// - If ?q= is present, we search within the already-loaded lists (activeSessions, pendingRequests, completedSessions)
// - If a match is found, we switch $tab accordingly and highlight items

require_once __DIR__ . '/../database/database.php';
require_admin();

$activeSidebar = 'community';
$admin = admin_current();
$fullName = trim((string)($admin['full_name'] ?? ''));
if ($fullName === '') $fullName = (string)($admin['username'] ?? 'User');

// NEW: incoming search from notifications
$q = trim((string)($_GET['q'] ?? ''));

// Get current tab (default active)
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'active';

// Count pending requests
$pendingRow = db_one("SELECT COUNT(*) AS cnt FROM manual_login_request WHERE status='PENDING'");
$pendingCount = (int)($pendingRow['cnt'] ?? 0);

// Get active sessions
$activeSessions = db_all(
  "SELECT
      css.session_id,
      css.requirement_id,
      css.time_in,
      css.login_method,
      csr.task_name,
      csr.hours_required,
      s.student_id,
      CONCAT(s.student_ln, ', ', s.student_fn) AS student_name,
      TIMESTAMPDIFF(HOUR, css.time_in, NOW()) AS hours_elapsed
   FROM community_service_session css
   JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
   JOIN student s ON s.student_id = csr.student_id
   WHERE css.time_out IS NULL
   ORDER BY css.time_in DESC"
);

// Get pending requests
$pendingRequests = db_all(
  "SELECT
      mlr.request_id,
      mlr.student_id,
      mlr.request_type,
      mlr.login_method,
      mlr.requested_at,
      CONCAT(s.student_ln, ', ', s.student_fn) AS student_name
   FROM manual_login_request mlr
   JOIN student s ON s.student_id = mlr.student_id
   WHERE mlr.status = 'PENDING'
   ORDER BY mlr.requested_at ASC"
);

// Get completed sessions (history)
$completedSessions = db_all(
  "SELECT
      css.session_id,
      css.time_in,
      css.time_out,
      css.login_method,
      css.logout_method,
      csr.task_name,
      s.student_id,
      CONCAT(s.student_ln, ', ', s.student_fn) AS student_name,
      TIMESTAMPDIFF(MINUTE, css.time_in, css.time_out) AS duration_minutes
   FROM community_service_session css
   JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
   JOIN student s ON s.student_id = csr.student_id
   WHERE css.time_out IS NOT NULL
   ORDER BY css.time_out DESC
   LIMIT 50"
);

$activeCount = count($activeSessions);

// NEW: figure out which tab contains the student when q is provided
$highlightStudentId = '';
if ($q !== '') {
  $qLower = mb_strtolower($q);

  $foundIn = '';

  foreach ($activeSessions as $s) {
    $sid = (string)$s['student_id'];
    $name = (string)$s['student_name'];
    if (mb_strtolower($sid) === $qLower || str_contains(mb_strtolower($name), $qLower)) {
      $foundIn = 'active';
      $highlightStudentId = $sid;
      break;
    }
  }

  if ($foundIn === '') {
    foreach ($pendingRequests as $r) {
      $sid = (string)$r['student_id'];
      $name = (string)$r['student_name'];
      if (mb_strtolower($sid) === $qLower || str_contains(mb_strtolower($name), $qLower)) {
        $foundIn = 'pending';
        $highlightStudentId = $sid;
        break;
      }
    }
  }

  if ($foundIn === '') {
    foreach ($completedSessions as $c) {
      $sid = (string)$c['student_id'];
      $name = (string)$c['student_name'];
      if (mb_strtolower($sid) === $qLower || str_contains(mb_strtolower($name), $qLower)) {
        $foundIn = 'history';
        $highlightStudentId = $sid;
        break;
      }
    }
  }

  // If found, force tab to where the student is
  if ($foundIn !== '') {
    $tab = $foundIn;
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Community Service Management | SDO Web Portal</title>
  <style>
    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
      background: #f8f9fa;
      color: #1b2244;
    }

    .admin-shell {
      min-height: calc(100vh - 72px);
      display: grid;
      grid-template-columns: 240px 1fr;
    }

    .wrap {
      min-height: 100%;
      padding: 0;
    }

    /* Page Header */
    .page-header {
      background: #ffffff;
      border-bottom: 1px solid #e0e0e0;
      padding: 28px 32px;
    }

    .page-header h1 {
      margin: 0;
      color: #1a1a1a;
      font-size: 28px;
      font-weight: 700;
      letter-spacing: 0.3px;
    }

    .welcome {
      margin-top: 4px;
      color: #6c757d;
      font-size: 15px;
      font-weight: 400;
    }

    /* Content Area */
    .content-area {
      padding: 24px 32px;
    }

    /* NEW: highlight banner */
    .focus-banner{
      background:#eef2ff;
      border:1px solid #c7d2fe;
      border-left: 4px solid #3b4a9e;
      border-radius: 10px;
      padding: 12px 14px;
      margin-bottom: 18px;
      color:#1a1a1a;
      font-weight: 800;
    }
    .focus-banner .muted{ color:#6c757d; font-weight:700; }

    /* NEW: highlighted card effect */
    .highlight {
      border: 2px solid #3b4a9e !important;
      box-shadow: 0 0 0 4px rgba(59,74,158,.12);
      background: #ffffff !important;
    }

    /* Alert Banner */
    .alert-banner {
      background: #fff3cd;
      border: 2px solid #ffc107;
      border-left: 4px solid #ffc107;
      border-radius: 8px;
      padding: 16px 20px;
      margin-bottom: 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }

    .alert-content {
      display: flex;
      align-items: center;
      gap: 12px;
      flex: 1;
    }

    .alert-icon {
      width: 24px;
      height: 24px;
      color: #856404;
      flex-shrink: 0;
    }

    .alert-icon .clock-hand {
      transform-origin: 12px 12px;
      animation: tick-spin 8s linear infinite;
    }

    .alert-icon .clock-hand-fast {
      transform-origin: 12px 12px;
      animation: tick-spin 2.5s linear infinite;
      opacity: 0.9;
    }

    @keyframes tick-spin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }

    .alert-text { color: #856404; }
    .alert-title { font-weight: 700; margin-bottom: 2px; }
    .alert-desc { font-size: 14px; }

    .btn-alert {
      background: #ffc107;
      color: #000;
      padding: 8px 20px;
      border-radius: 6px;
      text-decoration: none;
      font-weight: 600;
      font-size: 14px;
      white-space: nowrap;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .btn-alert::before {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      width: 0;
      height: 0;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.3);
      transform: translate(-50%, -50%);
      transition: width 0.5s ease, height 0.5s ease;
    }

    .btn-alert:hover::before { width: 300px; height: 300px; }
    .btn-alert:hover {
      background: #e0a800;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(255, 193, 7, 0.4);
    }

    /* Tabs */
    .tabs {
      display: flex;
      gap: 8px;
      margin-bottom: 24px;
      border-bottom: 2px solid #e0e0e0;
    }

    .tab {
      padding: 12px 24px;
      background: transparent;
      border: none;
      border-bottom: 3px solid transparent;
      color: #6c757d;
      font-weight: 600;
      font-size: 14px;
      cursor: pointer;
      text-decoration: none;
      position: relative;
      margin-bottom: -2px;
    }

    .tab:hover { color: #495057; background: #f8f9fa; }
    .tab.active { color: #3b4a9e; border-bottom-color: #3b4a9e; background: transparent; }

    .tab-badge {
      display: inline-block;
      background: #dc3545;
      color: white;
      border-radius: 10px;
      padding: 2px 8px;
      font-size: 11px;
      font-weight: 700;
      margin-left: 6px;
      min-width: 20px;
      text-align: center;
    }

    /* Card Panel */
    .panel {
      background: #fff;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      padding: 24px;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
    }

    .panel h2 {
      margin: 0 0 20px;
      font-size: 20px;
      font-weight: 700;
      color: #1a1a1a;
    }

    /* Session Card */
    .session-card {
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      padding: 20px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 20px;
    }

    .session-avatar {
      width: 56px;
      height: 56px;
      background: #3b4a9e;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 24px;
      flex-shrink: 0;
    }

    .session-info { flex: 1; }
    .session-name { font-size: 18px; font-weight: 700; color: #1a1a1a; margin-bottom: 4px; }
    .session-id { font-size: 14px; color: #6c757d; margin-bottom: 12px; }

    .session-badge {
      display: inline-block;
      background: #d1e7dd;
      color: #0a3622;
      padding: 4px 10px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
    }

    .session-details {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
      gap: 16px;
      margin-top: 12px;
    }

    .detail-item { font-size: 13px; }
    .detail-label { color: #6c757d; margin-bottom: 2px; }
    .detail-value { color: #1a1a1a; font-weight: 600; }

    .session-action { flex-shrink: 0; }

    .btn-view {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      background: white;
      border: 1px solid #dee2e6;
      border-radius: 6px;
      color: #3b4a9e;
      text-decoration: none;
      font-weight: 600;
      font-size: 14px;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .btn-view::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(59, 74, 158, 0.1), transparent);
      transition: left 0.5s ease;
    }

    .btn-view:hover::before { left: 100%; }
    .btn-view:hover {
      background: #f8f9fa;
      border-color: #3b4a9e;
      transform: translateX(4px);
      box-shadow: 0 2px 8px rgba(59, 74, 158, 0.15);
    }

    .btn-view svg { transition: transform 0.3s ease; }
    .btn-view:hover svg { transform: scale(1.1); }

    /* Pending Request Card */
    .pending-card {
      background: #fff3cd;
      border: 1px solid #ffc107;
      border-radius: 8px;
      padding: 20px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
    }

    .pending-info { flex: 1; }
    .pending-name { font-size: 16px; font-weight: 700; color: #856404; margin-bottom: 4px; }
    .pending-meta { font-size: 13px; color: #856404; }

    .btn-validate {
      background: #3b4a9e;
      color: white;
      padding: 10px 24px;
      border-radius: 6px;
      text-decoration: none;
      font-weight: 600;
      font-size: 14px;
      white-space: nowrap;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .btn-validate::before {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      width: 0;
      height: 0;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.2);
      transform: translate(-50%, -50%);
      transition: width 0.5s ease, height 0.5s ease;
    }

    .btn-validate:hover::before { width: 300px; height: 300px; }
    .btn-validate:hover {
      background: #2d3a7e;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(59, 74, 158, 0.4);
    }

    /* Completed Card */
    .completed-card {
      background: white;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      padding: 20px;
      margin-bottom: 16px;
    }

    .completed-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 16px;
    }

    .completed-badge {
      background: #d1e7dd;
      color: #0a3622;
      padding: 4px 12px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
    }

    .completed-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 16px;
      margin-bottom: 16px;
    }

    .empty-state {
      padding: 60px 20px;
      text-align: center;
      color: #6c757d;
      font-size: 15px;
    }

    @media (max-width: 900px) {
      .admin-shell { grid-template-columns: 1fr; }
      .content-area { padding: 20px 16px; }
      .page-header { padding: 20px 16px; }
      .session-card { flex-direction: column; align-items: flex-start; }
      .completed-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 640px) {
      .page-header h1 { font-size: 22px; }
      .alert-banner { flex-direction: column; align-items: flex-start; }
      .tabs { overflow-x: auto; }
      .tab { white-space: nowrap; }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>

  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="wrap">
      <!-- Page Header -->
      <section class="page-header">
        <h1>Community Service Management</h1>
        <div class="welcome">Welcome, <?php echo e($fullName); ?></div>
      </section>

      <!-- Content Area -->
      <div class="content-area">

        <?php if ($q !== '' && $highlightStudentId !== ''): ?>
          <div class="focus-banner">
            Showing result for <b><?php echo e($highlightStudentId); ?></b>
            <span class="muted">(from notifications)</span>
          </div>
        <?php elseif ($q !== ''): ?>
          <div class="focus-banner">
            No match found for <b><?php echo e($q); ?></b>
            <span class="muted">(from notifications)</span>
          </div>
        <?php endif; ?>

        <!-- Alert Banner -->
        <?php if ($pendingCount > 0 && $tab !== 'pending'): ?>
          <div class="alert-banner">
            <div class="alert-content">
              <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line class="clock-hand" x1="12" y1="12" x2="12" y2="7"></line>
                <line class="clock-hand-fast" x1="12" y1="12" x2="16" y2="12"></line>
                <circle cx="12" cy="12" r="1" fill="currentColor" stroke="none"></circle>
              </svg>
              <div class="alert-text">
                <div class="alert-title"><?php echo $pendingCount; ?> Manual Service Requests Pending</div>
                <div class="alert-desc">Students are waiting for SDO validation. Click "View Request" to process.</div>
              </div>
            </div>
            <a href="?tab=pending<?php echo $q!=='' ? '&q='.urlencode($q) : ''; ?>" class="btn-alert">View Request</a>
          </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
          <a href="?tab=active<?php echo $q!=='' ? '&q='.urlencode($q) : ''; ?>" class="tab <?php echo $tab === 'active' ? 'active' : ''; ?>">
            Active Service (<?php echo $activeCount; ?>)
          </a>
          <a href="?tab=pending<?php echo $q!=='' ? '&q='.urlencode($q) : ''; ?>" class="tab <?php echo $tab === 'pending' ? 'active' : ''; ?>">
            Pending Request
            <?php if ($pendingCount > 0): ?>
              <span class="tab-badge"><?php echo $pendingCount; ?></span>
            <?php endif; ?>
          </a>
          <a href="?tab=history<?php echo $q!=='' ? '&q='.urlencode($q) : ''; ?>" class="tab <?php echo $tab === 'history' ? 'active' : ''; ?>">
            History
          </a>
        </div>

        <!-- Tab Content -->
        <?php if ($tab === 'active'): ?>
          <section class="panel" id="tabActive">
            <h2>Active Service Sessions</h2>
            <?php if (empty($activeSessions)): ?>
              <div class="empty-state">No active sessions right now.</div>
            <?php else: ?>
              <?php foreach ($activeSessions as $session): ?>
                <?php $isHi = ($highlightStudentId !== '' && (string)$session['student_id'] === $highlightStudentId); ?>
                <div class="session-card <?php echo $isHi ? 'highlight' : ''; ?>" <?php echo $isHi ? 'id="focusCard"' : ''; ?>>
                  <div class="session-avatar">
                    <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
                      <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                      <circle cx="12" cy="7" r="4"></circle>
                    </svg>
                  </div>
                  <div class="session-info">
                    <div class="session-name"><?php echo e($session['student_name']); ?></div>
                    <div class="session-id"><?php echo e($session['student_id']); ?></div>
                    <span class="session-badge"><?php echo e($session['login_method'] ?: 'NFC'); ?></span>
                    <div class="session-details">

                      <div class="detail-item">
                        <div class="detail-label">Duration</div>
                        <div class="detail-value"><?php echo $session['hours_elapsed']; ?>h <?php echo ($session['hours_elapsed'] * 60) % 60; ?>m</div>
                      </div>
                      <div class="detail-item">
                        <div class="detail-label">Started</div>
                        <div class="detail-value"><?php echo date('g:i A', strtotime($session['time_in'])); ?></div>
                      </div>
                    </div>
                  </div>
                  <!-- Action button removed for testing -->
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </section>

        <?php elseif ($tab === 'pending'): ?>
          <section class="panel" id="tabPending">
            <h2>Pending Manual Login Requests</h2>
            <?php if (empty($pendingRequests)): ?>
              <div class="empty-state">No pending requests.</div>
            <?php else: ?>
              <?php foreach ($pendingRequests as $request): ?>
                <?php $isHi = ($highlightStudentId !== '' && (string)$request['student_id'] === $highlightStudentId); ?>
                <div class="pending-card <?php echo $isHi ? 'highlight' : ''; ?>" <?php echo $isHi ? 'id="focusCard"' : ''; ?>>
                  <div class="pending-info">
                    <div class="pending-name"><?php echo e($request['student_name']); ?></div>
                    <div class="pending-meta">
                      Student ID: <?php echo e($request['student_id']); ?> •
                      Type: <strong><?php echo e($request['request_type'] ?: 'LOGIN'); ?> (<?php echo e($request['login_method'] ?: 'MANUAL'); ?>)</strong> •
                      Requested: <?php echo date('g:i:s A', strtotime($request['requested_at'])); ?>
                    </div>
                  </div>
                  <a href="pending_request_view.php?request_id=<?php echo $request['request_id']; ?>" class="btn-validate">
                    Validate with SDO Credentials
                  </a>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </section>

        <?php else: ?>
          <section class="panel" id="tabHistory">
            <h2>Completed Service Sessions</h2>
            <?php if (empty($completedSessions)): ?>
              <div class="empty-state">No completed sessions yet.</div>
            <?php else: ?>
              <?php foreach ($completedSessions as $completed): ?>
                <?php $isHi = ($highlightStudentId !== '' && (string)$completed['student_id'] === $highlightStudentId); ?>
                <div class="completed-card <?php echo $isHi ? 'highlight' : ''; ?>" <?php echo $isHi ? 'id="focusCard"' : ''; ?>>
                  <div class="completed-header">
                    <div>
                      <div class="session-name"><?php echo e($completed['student_name']); ?></div>
                      <div class="session-id"><?php echo e($completed['student_id']); ?></div>
                    </div>
                    <span class="completed-badge">Completed</span>
                  </div>
                  <div class="completed-grid">
                    <div class="detail-item">
                      <div class="detail-label">Date</div>
                      <div class="detail-value"><?php echo date('n/j/Y', strtotime($completed['time_out'])); ?></div>
                    </div>
                    <div class="detail-item">
                      <div class="detail-label">Duration</div>
                      <div class="detail-value">
                        <?php
                          $hours = floor($completed['duration_minutes'] / 60);
                          $mins = $completed['duration_minutes'] % 60;
                          echo "{$hours}h {$mins}m";
                        ?>
                      </div>
                    </div>
                    <div class="detail-item">
                      <div class="detail-label">Login Method</div>
                      <div class="detail-value"><?php echo e($completed['login_method'] ?: 'NFC'); ?></div>
                    </div>
                    <div class="detail-item">
                      <div class="detail-label">Logout Method</div>
                      <div class="detail-value" style="color: #dc3545;">
                        <?php echo e($completed['logout_method'] ?: 'NFC'); ?>
                      </div>
                    </div>
                  </div>
                  <div class="detail-item">
                    <div class="detail-label">Task</div>
                    <div class="detail-value"><?php echo e($completed['task_name']); ?></div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </section>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <?php if ($highlightStudentId !== ''): ?>
    <script>
      // Auto-scroll to highlighted card
      const el = document.getElementById('focusCard');
      if (el) {
        setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'center' }), 120);
      }
    </script>
  <?php endif; ?>
</body>
</html>

