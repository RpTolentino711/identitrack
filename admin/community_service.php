<?php
// File: C:\xampp\htdocs\identitrack\admin\community_service.php
// Community Service Management with tabs and modern UI
//
// UPDATE:
// - Supports deep-link from notifications: community_service.php?q=STUDENT_ID
//   - Automatically switches to the correct tab (active/pending/history) where the student appears
//   - Highlights matching cards

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

// Auto-complete any active sessions that have finished their required hours
auto_complete_all_active_sessions();

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
      css.sdo_notes,
      css.task_is_new,
      css.status AS session_status,
      css.pause_reason,
      css.paused_at,
      css.accum_paused_seconds,
      csr.task_name,
      csr.hours_required,
      s.student_id,
      CONCAT(s.student_ln, ', ', s.student_fn) AS student_name,
      CASE 
        WHEN css.status = 'PAUSED' AND css.paused_at IS NOT NULL THEN
          GREATEST(0, TIMESTAMPDIFF(SECOND, css.time_in, css.paused_at) - css.accum_paused_seconds)
        ELSE
          GREATEST(0, TIMESTAMPDIFF(SECOND, css.time_in, NOW()) - css.accum_paused_seconds)
      END AS net_elapsed_seconds,
      (
        SELECT COALESCE(SUM(
          CASE 
            WHEN prev.status = 'PAUSED' AND prev.paused_at IS NOT NULL THEN
              GREATEST(0, TIMESTAMPDIFF(SECOND, prev.time_in, prev.paused_at) - COALESCE(prev.accum_paused_seconds, 0))
            ELSE
              GREATEST(0, TIMESTAMPDIFF(SECOND, prev.time_in, COALESCE(prev.time_out, NOW())) - COALESCE(prev.accum_paused_seconds, 0))
          END
        )/3600.0, 0.0)
        FROM community_service_session prev
        JOIN community_service_requirement prev_csr ON prev_csr.requirement_id = prev.requirement_id
        WHERE prev.requirement_id = css.requirement_id AND prev.time_out IS NOT NULL
      ) AS prev_hours_completed
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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@600;700&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
      background: #f1f5f9;
      color: #0f172a;
      -webkit-font-smoothing: antialiased;
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
      border-bottom: 1px solid #e2e8f0;
      padding: 24px 32px;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .page-header h1 {
      margin: 0;
      color: #0f172a;
      font-size: 24px;
      font-weight: 800;
      letter-spacing: -0.02em;
    }

    .welcome {
      margin-top: 4px;
      color: #64748b;
      font-size: 14px;
      font-weight: 500;
    }

    /* Content Area */
    .content-area {
      padding: 28px 32px;
    }

    /* Highlight banner */
    .focus-banner {
      background: #eef2ff;
      border: 1px solid #c7d2fe;
      border-left: 4px solid #4f46e5;
      border-radius: 12px;
      padding: 14px 18px;
      margin-bottom: 20px;
      color: #1e1b4b;
      font-weight: 700;
      font-size: 14px;
    }
    .focus-banner .muted { color: #64748b; font-weight: 600; }

    .highlight {
      border: 2px solid #4f46e5 !important;
      box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.15) !important;
      background: #ffffff !important;
    }

    /* Alert Banner */
    .alert-banner {
      background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%);
      border: 1px solid #fde68a;
      border-left: 5px solid #d97706;
      border-radius: 14px;
      padding: 16px 22px;
      margin-bottom: 24px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 18px;
      box-shadow: 0 4px 12px -2px rgba(217, 119, 6, 0.08);
    }

    .alert-content {
      display: flex;
      align-items: center;
      gap: 14px;
      flex: 1;
    }

    .alert-icon-wrap {
      width: 42px;
      height: 42px;
      border-radius: 12px;
      background: #fef3c7;
      border: 1px solid #fde68a;
      display: flex;
      align-items: center;
      justify-content: center;
      color: #b45309;
      flex-shrink: 0;
    }

    .alert-title { font-weight: 800; color: #78350f; font-size: 15px; margin-bottom: 2px; }
    .alert-desc { font-size: 13.5px; color: #92400e; font-weight: 500; }

    .btn-alert {
      background: #d97706;
      color: #ffffff;
      padding: 9px 20px;
      border-radius: 10px;
      text-decoration: none;
      font-weight: 700;
      font-size: 13.5px;
      white-space: nowrap;
      transition: all 0.2s ease;
      box-shadow: 0 2px 6px rgba(217, 119, 6, 0.25);
    }
    .btn-alert:hover {
      background: #b45309;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(217, 119, 6, 0.35);
    }

    /* Tabs */
    .tabs {
      display: flex;
      gap: 10px;
      margin-bottom: 24px;
      background: #e2e8f0;
      padding: 5px;
      border-radius: 14px;
      width: fit-content;
    }

    .tab {
      padding: 10px 20px;
      background: transparent;
      border: none;
      border-radius: 10px;
      color: #64748b;
      font-weight: 700;
      font-size: 14px;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.2s ease;
      display: inline-flex;
      align-items: center;
      gap: 8px;
    }

    .tab:hover { color: #1e293b; background: rgba(255,255,255,0.6); }
    .tab.active {
      color: #1e1b4b;
      background: #ffffff;
      box-shadow: 0 2px 8px rgba(15, 23, 42, 0.08);
    }

    .tab-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: #ef4444;
      color: white;
      border-radius: 9999px;
      padding: 2px 8px;
      font-size: 11px;
      font-weight: 800;
      line-height: 1;
    }

    /* Card Panel */
    .panel {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 18px;
      padding: 28px;
      box-shadow: 0 4px 20px -2px rgba(15, 23, 42, 0.04);
    }

    .panel h2 {
      margin: 0 0 24px;
      font-size: 18px;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -0.01em;
    }

    /* Session Card */
    .session-card {
      background: #f8fafc;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 22px;
      margin-bottom: 18px;
      display: flex;
      gap: 20px;
      transition: all 0.2s ease;
    }
    .session-card:hover {
      border-color: #cbd5e1;
      box-shadow: 0 6px 16px -4px rgba(15, 23, 42, 0.06);
    }

    .session-avatar {
      width: 52px;
      height: 52px;
      background: linear-gradient(135deg, #3b42c4 0%, #1e1b4b 100%);
      border-radius: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 20px;
      font-weight: 800;
      flex-shrink: 0;
      box-shadow: 0 4px 10px rgba(59, 66, 196, 0.25);
    }

    .session-info { flex: 1; }
    .session-name { font-size: 17px; font-weight: 800; color: #0f172a; margin-bottom: 2px; }
    .session-id { font-size: 13px; color: #64748b; font-weight: 600; margin-bottom: 10px; }

    .session-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      background: #e0e7ff;
      color: #3730a3;
      padding: 4px 10px;
      border-radius: 8px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.3px;
    }

    .session-details {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
      gap: 16px;
      margin-top: 14px;
      background: #ffffff;
      padding: 14px 18px;
      border-radius: 12px;
      border: 1px solid #edf2f7;
    }

    .detail-item { font-size: 13px; }
    .detail-label { color: #64748b; font-weight: 600; margin-bottom: 3px; font-size: 12px; }
    .detail-value { color: #0f172a; font-weight: 800; }

    /* Pending Card */
    .pending-card {
      background: #fffbeb;
      border: 1px solid #fde68a;
      border-radius: 14px;
      padding: 20px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 20px;
    }

    .pending-name { font-size: 16px; font-weight: 800; color: #78350f; margin-bottom: 4px; }
    .pending-meta { font-size: 13px; color: #92400e; font-weight: 500; }

    .btn-validate {
      background: #3b42c4;
      color: white;
      padding: 10px 22px;
      border-radius: 10px;
      text-decoration: none;
      font-weight: 700;
      font-size: 13.5px;
      white-space: nowrap;
      transition: all 0.2s ease;
      box-shadow: 0 4px 12px rgba(59, 66, 196, 0.2);
    }
    .btn-validate:hover {
      background: #2d32a4;
      transform: translateY(-1px);
    }

    /* Completed Card */
    .completed-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 14px;
      padding: 20px;
      margin-bottom: 16px;
    }

    .completed-header {
      display: flex;
      justify-content: space-between;
      align-items: flex-start;
      margin-bottom: 14px;
    }

    .completed-badge {
      background: #dcfce7;
      color: #15803d;
      padding: 4px 12px;
      border-radius: 20px;
      font-size: 11.5px;
      font-weight: 800;
      text-transform: uppercase;
    }

    .completed-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 16px;
    }

    .empty-state {
      padding: 60px 20px;
      text-align: center;
      color: #94a3b8;
      font-size: 14.5px;
      font-weight: 600;
    }

    /* Modern Glassmorphic Modals */
    .modal-backdrop {
      display: none;
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      background: rgba(15, 23, 42, 0.65);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      z-index: 99999;
      align-items: center;
      justify-content: center;
      padding: 20px;
      animation: fadeIn 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    }

    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    .modal-card {
      background: #ffffff;
      width: 100%;
      max-width: 480px;
      border-radius: 20px;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(255, 255, 255, 0.1);
      overflow: hidden;
      transform: scale(0.98);
      animation: modalPop 0.25s cubic-bezier(0.16, 1, 0.3, 1) forwards;
    }

    @keyframes modalPop {
      to { transform: scale(1); }
    }

    .modal-header {
      padding: 20px 24px;
      border-bottom: 1px solid #f1f5f9;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .modal-header-title {
      display: flex;
      align-items: center;
      gap: 12px;
    }

    .modal-icon-badge {
      width: 40px;
      height: 40px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .modal-icon-badge.emerald { background: #dcfce7; color: #16a34a; }
    .modal-icon-badge.rose { background: #ffe4e6; color: #e11d48; }
    .modal-icon-badge.indigo { background: #e0e7ff; color: #4f46e5; }

    .modal-title {
      margin: 0;
      font-size: 17.5px;
      font-weight: 800;
      color: #0f172a;
      letter-spacing: -0.01em;
    }

    .modal-close-btn {
      background: #f1f5f9;
      border: none;
      width: 32px;
      height: 32px;
      border-radius: 50%;
      color: #64748b;
      font-size: 16px;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: all 0.2s ease;
    }
    .modal-close-btn:hover { background: #e2e8f0; color: #0f172a; }

    .modal-body {
      padding: 24px;
    }

    .modal-notice-box {
      border-radius: 12px;
      padding: 12px 16px;
      font-size: 12.5px;
      line-height: 1.5;
      font-weight: 600;
      margin-top: 12px;
      display: flex;
      gap: 10px;
      align-items: flex-start;
    }

    .modal-notice-box.emerald {
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      color: #15803d;
    }

    .modal-notice-box.rose {
      background: #fff1f2;
      border: 1px solid #fecdd3;
      color: #be123c;
    }

    .modal-notice-box.indigo {
      background: #eef2ff;
      border: 1px solid #c7d2fe;
      color: #3730a3;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-label {
      display: block;
      font-size: 12.5px;
      font-weight: 700;
      color: #334155;
      margin-bottom: 7px;
    }

    .form-control-wrap {
      position: relative;
    }

    .form-control {
      width: 100%;
      padding: 11px 42px 11px 14px;
      border: 1.5px solid #cbd5e1;
      border-radius: 12px;
      font-size: 13.5px;
      font-family: inherit;
      color: #0f172a;
      background: #ffffff;
      transition: all 0.2s ease;
    }
    .form-control:focus {
      outline: none;
      border-color: #4f46e5;
      box-shadow: 0 0 0 4px rgba(79, 70, 229, 0.12);
    }

    .pwd-toggle-btn {
      position: absolute;
      right: 8px;
      top: 50%;
      transform: translateY(-50%);
      background: transparent;
      border: none;
      color: #64748b;
      cursor: pointer;
      padding: 6px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: color 0.2s ease;
    }
    .pwd-toggle-btn:hover { color: #0f172a; }

    .modal-footer {
      padding: 16px 24px;
      background: #f8fafc;
      border-top: 1px solid #f1f5f9;
      display: flex;
      gap: 12px;
      justify-content: flex-end;
    }

    .btn-ghost {
      background: #ffffff;
      border: 1px solid #cbd5e1;
      color: #475569;
      padding: 10px 18px;
      border-radius: 10px;
      font-size: 13.5px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .btn-ghost:hover { background: #f1f5f9; color: #0f172a; }

    .btn-action-emerald {
      background: linear-gradient(135deg, #16a34a 0%, #15803d 100%);
      border: none;
      color: #ffffff;
      padding: 10px 22px;
      border-radius: 10px;
      font-size: 13.5px;
      font-weight: 800;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 4px 12px rgba(22, 163, 74, 0.25);
      transition: all 0.2s ease;
    }
    .btn-action-emerald:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(22, 163, 74, 0.35);
    }

    .btn-action-rose {
      background: linear-gradient(135deg, #e11d48 0%, #be123c 100%);
      border: none;
      color: #ffffff;
      padding: 10px 22px;
      border-radius: 10px;
      font-size: 13.5px;
      font-weight: 800;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 4px 12px rgba(225, 29, 72, 0.25);
      transition: all 0.2s ease;
    }
    .btn-action-rose:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(225, 29, 72, 0.35);
    }

    .btn-action-indigo {
      background: linear-gradient(135deg, #4f46e5 0%, #3730a3 100%);
      border: none;
      color: #ffffff;
      padding: 10px 22px;
      border-radius: 10px;
      font-size: 13.5px;
      font-weight: 800;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      box-shadow: 0 4px 12px rgba(79, 70, 229, 0.25);
      transition: all 0.2s ease;
    }
    .btn-action-indigo:hover {
      transform: translateY(-1px);
      box-shadow: 0 6px 16px rgba(79, 70, 229, 0.35);
    }

    @media (max-width: 900px) {
      .admin-shell { grid-template-columns: 1fr; }
      .content-area { padding: 20px 16px; }
      .page-header { padding: 20px 16px; }
      .session-card { flex-direction: column; }
      .completed-grid { grid-template-columns: 1fr; }
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
        <div>
          <h1>Community Service Management</h1>
          <div class="welcome">Welcome back, <?php echo e($fullName); ?></div>
        </div>
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
              <div class="alert-icon-wrap">
                <svg width="22" height="22" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
              </div>
              <div>
                <div class="alert-title"><?php echo $pendingCount; ?> Manual Service Requests Pending</div>
                <div class="alert-desc">Students are waiting for SDO validation. Click to review requests.</div>
              </div>
            </div>
            <a href="?tab=pending<?php echo $q!=='' ? '&q='.urlencode($q) : ''; ?>" class="btn-alert">View Requests</a>
          </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
          <a href="?tab=active<?php echo $q!=='' ? '&q='.urlencode($q) : ''; ?>" class="tab <?php echo $tab === 'active' ? 'active' : ''; ?>">
            Active Service (<?php echo $activeCount; ?>)
          </a>
          <a href="?tab=pending<?php echo $q!=='' ? '&q='.urlencode($q) : ''; ?>" class="tab <?php echo $tab === 'pending' ? 'active' : ''; ?>">
            Pending Requests
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
              <div class="empty-state">No active community service sessions right now.</div>
            <?php else: ?>
              <?php foreach ($activeSessions as $session): ?>
                <?php $isHi = ($highlightStudentId !== '' && (string)$session['student_id'] === $highlightStudentId); ?>
                <div class="session-card <?php echo $isHi ? 'highlight' : ''; ?>" <?php echo $isHi ? 'id="focusCard"' : ''; ?>>
                  <div class="session-avatar">
                    <?php echo strtoupper(substr($session['student_name'] ?? 'S', 0, 1)); ?>
                  </div>
                  <div class="session-info">
                    <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px;">
                      <div>
                        <div class="session-name"><?php echo e($session['student_name']); ?></div>
                        <div class="session-id"><?php echo e($session['student_id']); ?></div>
                      </div>
                      <?php if (($session['session_status'] ?? '') === 'PAUSED'): ?>
                        <span style="background: #fffbe6; color: #d97706; border: 1px solid #fef3c7; font-weight: 800; padding: 5px 12px; border-radius: 20px; font-size: 12px; display: inline-flex; align-items: center; gap: 6px;">
                          <span style="width:7px; height:7px; border-radius:50%; background:#d97706;"></span> PAUSED
                        </span>
                      <?php else: ?>
                        <span style="background: #f0fdf4; color: #16a34a; border: 1px solid #bbf7d0; font-weight: 800; padding: 5px 12px; border-radius: 20px; font-size: 12px; display: inline-flex; align-items: center; gap: 6px;">
                          <span style="width:7px; height:7px; border-radius:50%; background:#16a34a; box-shadow:0 0 0 3px rgba(22,163,74,0.2);"></span> ACTIVE
                        </span>
                      <?php endif; ?>
                    </div>
                    <span class="session-badge">Login: <?php echo e($session['login_method'] ?: 'NFC'); ?></span>
                    
                    <div class="session-details">
                      <div class="detail-item">
                        <div class="detail-label">Started</div>
                        <div class="detail-value"><?php echo date('g:i A', strtotime($session['time_in'])); ?></div>
                      </div>
                      <div class="detail-item">
                        <div class="detail-label" style="color: #e11d48;">Remaining Countdown</div>
                        <div class="detail-value countdown-timer" 
                             style="color: #e11d48; font-family: 'JetBrains Mono', monospace; font-size: 15px;"
                             data-status="<?php echo e($session['session_status'] ?: 'ACTIVE'); ?>"
                             data-net-elapsed-sec="<?php echo (int)$session['net_elapsed_seconds']; ?>"
                             data-prev-completed="<?php echo (float)$session['prev_hours_completed']; ?>"
                             data-required="<?php echo (float)$session['hours_required']; ?>">
                          Loading...
                        </div>
                      </div>
                    </div>

                    <div style="margin-top: 12px; padding: 12px 16px; background: #ffffff; border-radius: 12px; border: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; gap: 12px;">
                      <div style="flex: 1; min-width: 0;">
                        <div style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.4px;">SDO Notes / Assignment Location</div>
                        <div style="font-size: 13.5px; font-weight: 700; color: #0f172a; margin-top: 3px; word-break: break-word;">
                          <?php echo e($session['sdo_notes'] ?: 'No location/notes provided'); ?>
                        </div>
                      </div>
                      <button type="button" 
                              class="btn-edit-cs-note"
                              data-session-id="<?php echo (int)$session['session_id']; ?>"
                              data-sdo-notes="<?php echo e($session['sdo_notes'] ?? ''); ?>"
                              data-student-name="<?php echo e($session['student_name']); ?>"
                              onclick="openUpdateCSNoteModal(this)" 
                              style="padding: 7px 14px; background: #4f46e5; color: #ffffff; border: none; border-radius: 8px; font-weight: 700; font-size: 12.5px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; flex-shrink: 0; box-shadow: 0 2px 6px rgba(79, 70, 229, 0.25); transition: all 0.2s ease;">
                        <svg width="14" height="14" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg> Edit Note
                      </button>
                    </div>

                    <?php if (($session['session_status'] ?? '') === 'PAUSED'): ?>
                      <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid #edf2f7; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                        <div style="font-size: 12.5px; color: #b45309; font-weight: 600; display: flex; align-items: center; gap: 6px;">
                          <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#d97706;"></span>
                          Reason: <?php echo e($session['pause_reason'] ?: 'Manually paused by Admin'); ?>
                        </div>
                        <button type="button" 
                                onclick="openResumeCSModal(<?php echo (int)$session['session_id']; ?>, '<?php echo e($session['student_id']); ?>', '<?php echo e($session['student_name']); ?>')" 
                                title="Resume Service Timer (Requires Admin Password)"
                                style="padding: 8px 16px; border-radius: 10px; background: linear-gradient(135deg, #16a34a 0%, #15803d 100%); color: #ffffff; border: none; font-weight: 800; font-size: 12.5px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 10px rgba(22,163,74,0.25); transition: all 0.2s ease;">
                          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg> Resume Session
                        </button>
                      </div>
                    <?php else: ?>
                      <div style="margin-top: 14px; padding-top: 12px; border-top: 1px solid #edf2f7; display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px;">
                        <span style="font-size: 12.5px; color: #15803d; font-weight: 700; display: inline-flex; align-items: center; gap: 6px;">
                          <span style="display:inline-block; width:8px; height:8px; border-radius:50%; background:#16a34a;"></span> Session Active (Tracking Motion)
                        </span>
                        <button type="button" 
                                onclick="openPauseCSModal(<?php echo (int)$session['session_id']; ?>, '<?php echo e($session['student_id']); ?>', '<?php echo e($session['student_name']); ?>')" 
                                title="Pause Service Timer (Requires Admin Password)"
                                style="padding: 8px 16px; border-radius: 10px; background: linear-gradient(135deg, #e11d48 0%, #be123c 100%); color: #ffffff; border: none; font-weight: 800; font-size: 12.5px; cursor: pointer; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 4px 10px rgba(225,29,72,0.25); transition: all 0.2s ease;">
                          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg> Pause Session
                        </button>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </section>

        <?php elseif ($tab === 'pending'): ?>
          <section class="panel" id="tabPending">
            <h2>Pending Manual Login Requests</h2>
            <?php if (empty($pendingRequests)): ?>
              <div class="empty-state">No pending manual login requests.</div>
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
              <div class="empty-state">No completed service sessions recorded yet.</div>
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
                      <div class="detail-label">Date Completed</div>
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
                      <div class="detail-label">Login / Logout</div>
                      <div class="detail-value">
                        <?php echo e($completed['login_method'] ?: 'NFC'); ?> / <?php echo e($completed['logout_method'] ?: 'NFC'); ?>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </section>
        <?php endif; ?>
      </div>
    </main>
  </div>

  <!-- MODAL: Confirm Resume Student Service -->
  <div id="resumeCSModal" class="modal-backdrop">
    <div class="modal-card">
      <div class="modal-header">
        <div class="modal-header-title">
          <div class="modal-icon-badge emerald">
            <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
          </div>
          <h3 class="modal-title">Resume Service Session</h3>
        </div>
        <button type="button" class="modal-close-btn" onclick="closeResumeCSModal()">✕</button>
      </div>
      <div class="modal-body">
        <div style="font-size: 14px; color: #334155; line-height: 1.5;">
          Are you sure you want to resume the community service clock-in for <strong id="resumeCSStudentName" style="color:#16a34a;">Student</strong>?
        </div>
        <div class="modal-notice-box emerald">
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink:0; margin-top:2px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <div>Resuming will notify the student on their app and restart active service hours tracking.</div>
        </div>

        <div class="form-group" style="margin-top: 20px;">
          <label class="form-label">SDO Admin Password <span style="color:#e11d48;">*</span></label>
          <div class="form-control-wrap">
            <input type="password" id="resumeCSPasswordInput" class="form-control" placeholder="Enter Admin Password to confirm..." required onkeydown="if(event.key==='Enter') confirmResumeCS();">
            <button type="button" class="pwd-toggle-btn" onclick="togglePasswordVisibility('resumeCSPasswordInput', this)" title="Show Password">
              <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            </button>
          </div>
        </div>
        <div id="resumeCSMsg" style="margin-top:10px; font-size:13px; font-weight:700;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" onclick="closeResumeCSModal()">Cancel</button>
        <button type="button" id="btnConfirmResumeCS" onclick="confirmResumeCS()" class="btn-action-emerald">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg> Yes, Resume Session
        </button>
      </div>
    </div>
  </div>

  <!-- MODAL: Confirm Pause Student Service -->
  <div id="pauseCSModal" class="modal-backdrop">
    <div class="modal-card">
      <div class="modal-header">
        <div class="modal-header-title">
          <div class="modal-icon-badge rose">
            <svg width="20" height="20" fill="currentColor" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
          </div>
          <h3 class="modal-title">Pause Service Session</h3>
        </div>
        <button type="button" class="modal-close-btn" onclick="closePauseCSModal()">✕</button>
      </div>
      <div class="modal-body">
        <div style="font-size: 14px; color: #334155; line-height: 1.5;">
          Are you sure you want to pause the community service timer for <strong id="pauseCSStudentName" style="color:#e11d48;">Student</strong>?
        </div>
        <div class="modal-notice-box rose">
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink:0; margin-top:2px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
          <div>Pausing will freeze the timer countdown on the student's mobile app until resumed by SDO Admin.</div>
        </div>

        <div class="form-group" style="margin-top: 18px;">
          <label class="form-label">Pause Reason (Optional)</label>
          <input type="text" id="pauseCSReasonInput" class="form-control" style="padding-right:14px;" placeholder="e.g. Break time / Stationary / Admin decision">
        </div>

        <div class="form-group">
          <label class="form-label">SDO Admin Password <span style="color:#e11d48;">*</span></label>
          <div class="form-control-wrap">
            <input type="password" id="pauseCSPasswordInput" class="form-control" placeholder="Enter Admin Password to confirm..." required onkeydown="if(event.key==='Enter') confirmPauseCS();">
            <button type="button" class="pwd-toggle-btn" onclick="togglePasswordVisibility('pauseCSPasswordInput', this)" title="Show Password">
              <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
            </button>
          </div>
        </div>
        <div id="pauseCSMsg" style="margin-top:10px; font-size:13px; font-weight:700;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" onclick="closePauseCSModal()">Cancel</button>
        <button type="button" id="btnConfirmPauseCS" onclick="confirmPauseCS()" class="btn-action-rose">
          <svg width="16" height="16" fill="currentColor" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg> Yes, Pause Service Timer
        </button>
      </div>
    </div>
  </div>

  <!-- MODAL: Update SDO Notes / Task Location -->
  <div id="updateCSNoteModal" class="modal-backdrop">
    <div class="modal-card">
      <div class="modal-header">
        <div class="modal-header-title">
          <div class="modal-icon-badge indigo">
            <svg width="20" height="20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
          </div>
          <h3 class="modal-title">Edit Task Location / SDO Note</h3>
        </div>
        <button type="button" class="modal-close-btn" onclick="closeUpdateCSNoteModal()">✕</button>
      </div>
      <div class="modal-body">
        <div style="font-size: 14px; color: #334155; line-height: 1.5;">
          Updating task location / SDO notes for <strong id="updateCSNoteStudentName" style="color:#4f46e5;">Student</strong>:
        </div>
        <div class="form-group" style="margin-top: 16px;">
          <label class="form-label">SDO Notes / Assignment Location <span style="color:#e11d48;">*</span></label>
          <textarea id="updateCSNoteInput" class="form-control" rows="3" style="padding-right:14px; resize:vertical;" placeholder="Enter task location or SDO instructions..." required></textarea>
        </div>
        <div class="modal-notice-box indigo">
          <svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24" style="flex-shrink:0; margin-top:2px;"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
          <div>Updating this note will instantly send a <strong>NEW TASK</strong> notification badge to the student's mobile app.</div>
        </div>
        <div id="updateCSNoteMsg" style="margin-top:10px; font-size:13px; font-weight:700;"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-ghost" onclick="closeUpdateCSNoteModal()">Cancel</button>
        <button type="button" id="btnConfirmUpdateCSNote" onclick="confirmUpdateCSNote()" class="btn-action-indigo">
          <svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7H5a2 2 0 00-2 2v9a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-3m-1 4l-3 3m0 0l-3-3m3 3V4"/></svg> Save & Notify Student
        </button>
      </div>
    </div>
  </div>

  <script>
    <?php if ($highlightStudentId !== ''): ?>
      const el = document.getElementById('focusCard');
      if (el) {
        setTimeout(() => el.scrollIntoView({ behavior: 'smooth', block: 'center' }), 120);
      }
    <?php endif; ?>

    function updateCountdownTimers() {
      const nowTs = Math.floor(Date.now() / 1000);
      document.querySelectorAll('.countdown-timer').forEach(el => {
        const status = el.getAttribute('data-status') || 'ACTIVE';
        const initialNetElapsed = parseInt(el.getAttribute('data-net-elapsed-sec') || '0', 10);
        const prevCompleted = parseFloat(el.getAttribute('data-prev-completed')) || 0;
        const required = parseFloat(el.getAttribute('data-required')) || 0;
        
        let currentNetElapsed = initialNetElapsed;
        
        if (status === 'ACTIVE') {
          if (!el._startNowTs) {
            el._startNowTs = nowTs;
          }
          currentNetElapsed += Math.max(0, nowTs - el._startNowTs);
        } else {
          el._startNowTs = null;
        }

        const requiredSeconds = Math.round(required * 3600);
        const prevSeconds = Math.round(prevCompleted * 3600);
        
        let remainingSeconds = Math.max(0, requiredSeconds - prevSeconds - currentNetElapsed);
        
        const h = Math.floor(remainingSeconds / 3600);
        const m = Math.floor((remainingSeconds % 3600) / 60);
        const s = remainingSeconds % 60;
        
        const pad = n => String(n).padStart(2, '0');
        el.textContent = `${pad(h)}:${pad(m)}:${pad(s)}`;
      });
    }
    
    updateCountdownTimers();
    setInterval(updateCountdownTimers, 1000);

    let currentResumeSessionId = 0;
    let currentResumeStudentId = '';

    function openResumeCSModal(sessionId, studentId, studentName) {
        currentResumeSessionId = sessionId;
        currentResumeStudentId = studentId;
        const nameEl = document.getElementById('resumeCSStudentName');
        if (nameEl) nameEl.textContent = studentName;
        const pwdInput = document.getElementById('resumeCSPasswordInput');
        if (pwdInput) pwdInput.value = '';
        const msg = document.getElementById('resumeCSMsg');
        if (msg) msg.innerHTML = '';
        const modal = document.getElementById('resumeCSModal');
        if (modal) modal.style.display = 'flex';
    }

    function closeResumeCSModal() {
        const modal = document.getElementById('resumeCSModal');
        if (modal) modal.style.display = 'none';
    }

    async function confirmResumeCS() {
        if (currentResumeSessionId <= 0) return;
        const msg = document.getElementById('resumeCSMsg');
        const btn = document.getElementById('btnConfirmResumeCS');
        const pwdInput = document.getElementById('resumeCSPasswordInput');
        const pwd = pwdInput ? pwdInput.value.trim() : '';

        if (!pwd) {
            if (msg) { msg.innerHTML = '❌ Please enter your SDO Admin password.'; msg.style.color = '#e11d48'; }
            if (pwdInput) pwdInput.focus();
            return;
        }

        if (msg) { msg.innerHTML = '⌛ Verifying credentials & resuming session…'; msg.style.color = '#475569'; }
        if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }

        try {
            const res = await fetch('api_resume_cs_session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: currentResumeSessionId, student_id: currentResumeStudentId, password: pwd })
            });
            const data = await res.json();
            if (data.ok) {
                if (msg) { msg.innerHTML = '✅ Student session resumed! Notifying app…'; msg.style.color = '#16a34a'; }
                setTimeout(() => {
                    closeResumeCSModal();
                    window.location.reload();
                }, 1000);
            } else {
                if (msg) { msg.innerHTML = '❌ ' + (data.message || 'Failed to resume session'); msg.style.color = '#e11d48'; }
                if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
            }
        } catch (err) {
            if (msg) { msg.innerHTML = '❌ Error: ' + err.message; msg.style.color = '#e11d48'; }
            if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
        }
    }

    let currentPauseSessionId = 0;
    let currentPauseStudentId = '';

    function openPauseCSModal(sessionId, studentId, studentName) {
        currentPauseSessionId = sessionId;
        currentPauseStudentId = studentId;
        const nameEl = document.getElementById('pauseCSStudentName');
        if (nameEl) nameEl.textContent = studentName;
        const reasonInput = document.getElementById('pauseCSReasonInput');
        if (reasonInput) reasonInput.value = '';
        const pwdInput = document.getElementById('pauseCSPasswordInput');
        if (pwdInput) pwdInput.value = '';
        const msg = document.getElementById('pauseCSMsg');
        if (msg) msg.innerHTML = '';
        const modal = document.getElementById('pauseCSModal');
        if (modal) modal.style.display = 'flex';
    }

    function closePauseCSModal() {
        const modal = document.getElementById('pauseCSModal');
        if (modal) modal.style.display = 'none';
    }

    async function confirmPauseCS() {
        if (currentPauseSessionId <= 0) return;
        const msg = document.getElementById('pauseCSMsg');
        const btn = document.getElementById('btnConfirmPauseCS');
        const reasonInput = document.getElementById('pauseCSReasonInput');
        const reason = reasonInput ? reasonInput.value.trim() : 'Manually paused by Admin';
        const pwdInput = document.getElementById('pauseCSPasswordInput');
        const pwd = pwdInput ? pwdInput.value.trim() : '';

        if (!pwd) {
            if (msg) { msg.innerHTML = '❌ Please enter your SDO Admin password.'; msg.style.color = '#e11d48'; }
            if (pwdInput) pwdInput.focus();
            return;
        }

        if (msg) { msg.innerHTML = '⌛ Verifying credentials & pausing session…'; msg.style.color = '#475569'; }
        if (btn) { btn.disabled = true; btn.style.opacity = '0.6'; }

        try {
            const res = await fetch('api_pause_cs_session.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ session_id: currentPauseSessionId, student_id: currentPauseStudentId, reason: reason, password: pwd })
            });
            const data = await res.json();
            if (data.ok) {
                if (msg) { msg.innerHTML = '✅ Student session paused! Notifying app…'; msg.style.color = '#16a34a'; }
                setTimeout(() => {
                    closePauseCSModal();
                    window.location.reload();
                }, 1000);
            } else {
                if (msg) { msg.innerHTML = '❌ ' + (data.message || 'Failed to pause session'); msg.style.color = '#e11d48'; }
                if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
            }
        } catch (err) {
            if (msg) { msg.innerHTML = '❌ Error: ' + err.message; msg.style.color = '#e11d48'; }
            if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
        }
    }

    let activeCSNoteSessionId = 0;

    function openUpdateCSNoteModal(sessionId, currentNote, studentName) {
        if (typeof sessionId === 'object' && sessionId !== null) {
            const btn = sessionId;
            sessionId = parseInt(btn.getAttribute('data-session-id'), 10);
            currentNote = btn.getAttribute('data-sdo-notes') || '';
            studentName = btn.getAttribute('data-student-name') || 'Student';
        }
        activeCSNoteSessionId = sessionId;
        document.getElementById('updateCSNoteStudentName').textContent = studentName || 'Student';
        document.getElementById('updateCSNoteInput').value = currentNote || '';
        document.getElementById('updateCSNoteMsg').textContent = '';
        const modal = document.getElementById('updateCSNoteModal');
        if (modal) modal.style.display = 'flex';
    }

    function closeUpdateCSNoteModal() {
        const modal = document.getElementById('updateCSNoteModal');
        if (modal) modal.style.display = 'none';
        activeCSNoteSessionId = 0;
    }

    async function confirmUpdateCSNote() {
        const noteInput = document.getElementById('updateCSNoteInput');
        const noteVal = noteInput ? noteInput.value.trim() : '';
        const msgDiv = document.getElementById('updateCSNoteMsg');
        const btn = document.getElementById('btnConfirmUpdateCSNote');

        if (!noteVal) {
            msgDiv.style.color = '#e11d48';
            msgDiv.textContent = '❌ SDO Notes / Assignment Location cannot be empty. Please enter task details.';
            return;
        }

        btn.disabled = true;
        msgDiv.style.color = '#4f46e5';
        msgDiv.textContent = '⏳ Saving note and notifying student...';

        try {
            const res = await fetch('api_update_cs_note.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    session_id: activeCSNoteSessionId,
                    sdo_notes: noteVal
                })
            });
            const data = await res.json();
            if (data && data.ok) {
                msgDiv.style.color = '#16a34a';
                msgDiv.textContent = '✅ Note updated successfully!';
                setTimeout(() => {
                    window.location.reload();
                }, 800);
            } else {
                msgDiv.style.color = '#e11d48';
                msgDiv.textContent = '❌ ' + (data.message || 'Error updating note.');
            }
        } catch (err) {
            msgDiv.style.color = '#e11d48';
            msgDiv.textContent = '❌ Network error updating note.';
        } finally {
            btn.disabled = false;
        }
    }

    let lastCSStateHash = null;

    async function pollCSLiveStatus() {
        try {
            const res = await fetch('api_get_cs_live_status.php');
            if (!res.ok) return;
            const data = await res.json();
            if (!data.ok) return;

            const activeBadge = document.querySelector('a[href*="tab=active"] .tab-badge');
            if (activeBadge) activeBadge.textContent = data.active_count;

            const pendingBadge = document.querySelector('a[href*="tab=pending"] .tab-badge');
            if (pendingBadge) pendingBadge.textContent = data.pending_count;

            if (lastCSStateHash !== null && lastCSStateHash !== data.state_hash) {
                console.log('⚡ RFID scan / session state change detected! Auto-refreshing active service grid...');
                window.location.reload();
            }
            lastCSStateHash = data.state_hash;
        } catch (e) {
            // Ignore temporary network glitch
        }
    }

    function togglePasswordVisibility(inputId, btnEl) {
        const input = document.getElementById(inputId);
        if (!input) return;
        const iconEye = `<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>`;
        const iconEyeOff = `<svg width="18" height="18" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858-5.908a10.03 10.03 0 013.987-.863c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m-3.676-3.676a3 3 0 00-4.243-4.243M3 3l18 18"/></svg>`;
        
        if (input.type === 'password') {
            input.type = 'text';
            btnEl.innerHTML = iconEyeOff;
            btnEl.title = 'Hide Password';
        } else {
            input.type = 'password';
            btnEl.innerHTML = iconEye;
            btnEl.title = 'Show Password';
        }
    }

    pollCSLiveStatus();
    setInterval(pollCSLiveStatus, 1500);
  </script>
</body>
</html>
