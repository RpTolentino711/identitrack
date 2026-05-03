<?php
// File: C:\xampp\htdocs\identitrack\admin\notifications.php
// Notifications with smart routing
// - Clickable notifications route to correct page based on type
// - COMMUNITY_LOGIN/LOGOUT -> community_service.php
// - UPCC_FILED -> offenses_student_view.php
// - GUARD_VIOLATION -> offenses_student_view.php
// - Auto-mark as read on page visit
// - Unread count badge

require_once __DIR__ . '/../database/database.php';
require_admin();

$activeSidebar = 'notifications';

$admin = admin_current();
$fullName = trim((string)($admin['full_name'] ?? ''));
if ($fullName === '') $fullName = (string)($admin['username'] ?? 'User');

// Identify current admin id
$adminId = (int)($admin['admin_id'] ?? $admin['id'] ?? 0);

// ✅ AUTO-MARK AS READ ON PAGE VISIT
db_exec(
  "UPDATE notification
   SET is_read = 1
   WHERE is_deleted = 0
     AND is_read = 0
     AND (admin_id IS NULL OR admin_id <> ?)",
  [$adminId]
);

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'approve_guard_report') {
    $reportId = (int)($_POST['report_id'] ?? 0);
    if ($reportId > 0) {
      $report = db_one(
        "SELECT r.report_id, r.student_id, r.offense_type_id, r.date_committed, r.description, r.status
         FROM guard_violation_report r
         WHERE r.report_id = :rid AND r.is_deleted = 0
         LIMIT 1",
        [':rid' => $reportId]
      );

      if ($report && strtoupper((string)$report['status']) === 'PENDING') {
        $offenseType = db_one(
          "SELECT level FROM offense_type WHERE offense_type_id = :oid LIMIT 1",
          [':oid' => (int)$report['offense_type_id']]
        );

        if ($offenseType) {
          db_exec(
            "INSERT INTO offense (student_id, recorded_by, offense_type_id, level, description, date_committed, status, created_at, updated_at)
             VALUES (:sid, :admin, :tid, :lvl, :descr, :dt, 'OPEN', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            [
              ':sid' => (string)$report['student_id'],
              ':admin' => $adminId,
              ':tid' => (int)$report['offense_type_id'],
              ':lvl' => strtoupper((string)$offenseType['level']),
              ':descr' => ($report['description'] === '' ? null : $report['description']),
              ':dt' => (string)$report['date_committed'],
            ]
          );

          db_exec(
            "UPDATE guard_violation_report
             SET status = 'APPROVED', reviewed_by = :admin, reviewed_at = NOW(), review_notes = :note
             WHERE report_id = :rid",
            [':admin' => $adminId, ':note' => 'Approved by admin via notifications.', ':rid' => $reportId]
          );

          // We no longer auto-delete the notification; the user requested it stay in the audit so it can be logged and manually deleted.
          db_exec(
            "UPDATE notification
             SET is_read = 1
             WHERE type = 'GUARD_REPORT'
               AND related_table = 'guard_violation_report'
               AND related_id = :rid",
            [':rid' => $reportId]
          );

          redirect('notifications.php?msg=guard_report_approved');
        }
      }
    }
    redirect('notifications.php?msg=guard_report_approve_failed');
  }

  if ($action === 'reject_guard_report') {
    $reportId = (int)($_POST['report_id'] ?? 0);
    if ($reportId > 0) {
      $report = db_one(
        "SELECT report_id, status FROM guard_violation_report WHERE report_id = :rid LIMIT 1",
        [':rid' => $reportId]
      );

      if ($report && strtoupper((string)$report['status']) === 'PENDING') {
        // Mark as REJECTED instead of deleting it, so the audit log preserves it.
        db_exec(
          "UPDATE guard_violation_report
           SET status = 'REJECTED', reviewed_by = :admin, reviewed_at = NOW(), review_notes = 'Rejected by admin via notifications.'
           WHERE report_id = :rid",
          [':admin' => $adminId, ':rid' => $reportId]
        );
        
        // We no longer auto-delete the notification, keeping it in the audit log until manually deleted.
        db_exec(
          "UPDATE notification
           SET is_read = 1
           WHERE type = 'GUARD_REPORT'
             AND related_table = 'guard_violation_report'
             AND related_id = :rid",
          [':rid' => $reportId]
        );
        redirect('notifications.php?msg=guard_report_rejected');
      }
    }
    redirect('notifications.php?msg=guard_report_reject_failed');
  }

  if ($action === 'delete_single') {
      $nid = (int)($_POST['notif_id'] ?? 0);
      if ($nid > 0) db_exec("UPDATE notification SET is_deleted=1 WHERE notification_id=:nid", [':nid'=>$nid]);
      redirect('notifications.php');
  }

  if ($action === 'delete_resolved') {
      db_exec("UPDATE notification n 
               JOIN guard_violation_report r ON r.report_id = n.related_id
               SET n.is_deleted = 1 
               WHERE n.related_table = 'guard_violation_report' AND r.status IN ('APPROVED', 'REJECTED')");
      redirect('notifications.php');
  }

  if ($action === 'mark_all_read') {
    db_exec("UPDATE notification SET is_read=1 WHERE is_deleted=0");
    redirect('notifications.php');
  }

  if ($action === 'delete_all') {
    db_exec("UPDATE notification SET is_deleted=1 WHERE is_deleted=0");
    redirect('notifications.php');
  }
}

// Fetch notifications (latest first)
$items = db_all(
  "SELECT
      n.notification_id,
      n.type,
      n.title,
      n.message,
      n.student_id,
      n.admin_id,
      n.related_table,
      n.related_id,
      n.is_read,
      n.created_at
   FROM notification n
   WHERE n.is_deleted = 0
   ORDER BY n.created_at DESC
   LIMIT 200"
);

// Unread count
$unreadRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM notification
   WHERE is_deleted=0 AND is_read=0
     AND (admin_id IS NULL OR admin_id <> ?)",
  [$adminId]
);
$unreadCount = (int)($unreadRow['cnt'] ?? 0);

$guardReportMap = [];
$guardReportIds = [];
foreach ($items as $it) {
  $isGuardNotif = strtoupper((string)($it['type'] ?? '')) === 'GUARD_REPORT'
    && strtoupper((string)($it['related_table'] ?? '')) === 'GUARD_VIOLATION_REPORT';
  if ($isGuardNotif) {
    $rid = (int)($it['related_id'] ?? 0);
    if ($rid > 0) $guardReportIds[] = $rid;
  }
}

if (!empty($guardReportIds)) {
  $guardReportIds = array_values(array_unique($guardReportIds));
  $placeholders = implode(',', array_fill(0, count($guardReportIds), '?'));

  $reportRows = db_all(
    "SELECT
       r.report_id,
       r.student_id,
       r.offense_type_id,
       r.date_committed,
       r.description,
       r.status,
       r.created_at,
       ot.code AS offense_code,
       ot.name AS offense_name,
       ot.level AS offense_level,
       CONCAT(COALESCE(s.student_fn,''), ' ', COALESCE(s.student_ln,'')) AS student_name,
       sg.full_name AS guard_name
     FROM guard_violation_report r
     JOIN offense_type ot ON ot.offense_type_id = r.offense_type_id
     LEFT JOIN student s ON s.student_id = r.student_id
     LEFT JOIN security_guard sg ON sg.guard_id = r.submitted_by
     WHERE r.report_id IN ($placeholders)
     ORDER BY r.created_at DESC",
    $guardReportIds
  );

  foreach ($reportRows as $rr) {
    $guardReportMap[(int)$rr['report_id']] = $rr;
  }
}

$flashKey = trim((string)($_GET['msg'] ?? ''));
$flashText = '';
if ($flashKey === 'guard_report_approved') $flashText = 'Guard submission approved and added to student offense records.';
if ($flashKey === 'guard_report_rejected') $flashText = 'Guard submission rejected and deleted.';
if ($flashKey === 'guard_report_approve_failed') $flashText = 'Unable to approve guard submission.';
if ($flashKey === 'guard_report_reject_failed') $flashText = 'Unable to reject guard submission.';

function iconSvg(string $type): string {
  $type = strtoupper($type);

  if (str_contains($type, 'LOGIN')) {
    return '<span class="ico blue" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle></svg>
    </span>';
  }

  if (str_contains($type, 'LOGOUT')) {
    return '<span class="ico green" aria-hidden="true">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="9"></circle><path d="M12 7v6l3 3"></path></svg>
    </span>';
  }

  if (str_contains($type, 'UPCC') || str_contains($type, 'CASE')) {
    return '<span class="ico purple" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
    </span>';
  }

  if (str_contains($type, 'GUARD') || str_contains($type, 'VIOLATION') || str_contains($type, 'OFFENSE')) {
    return '<span class="ico red" aria-hidden="true">
      <svg viewBox="0 0 24 24"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path></svg>
    </span>';
  }

  if (str_contains($type, 'DEADLINE') || str_contains($type, 'WARNING') || str_contains($type, 'ALERT')) {
    return '<span class="ico amber" aria-hidden="true">
      <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><path d="M12 7v6"></path><path d="M12 16h.01"></path></svg>
    </span>';
  }

  return '<span class="ico gray" aria-hidden="true">
    <svg viewBox="0 0 24 24"><path d="M21 15a4 4 0 0 1-4 4H7l-4 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4z"></path></svg>
  </span>';
}

/**
 * Smart routing based on notification type and related data
 */
function notifHref(array $n): string {
  $type = strtoupper((string)($n['type'] ?? ''));
  $studentId = trim((string)($n['student_id'] ?? ''));
  $relatedTable = strtoupper(trim((string)($n['related_table'] ?? '')));
  $relatedId = trim((string)($n['related_id'] ?? ''));

  // Guard submissions are reviewed/accepted/rejected directly in notifications page.
  if ($type === 'GUARD_REPORT' || $relatedTable === 'GUARD_VIOLATION_REPORT') {
    return '';
  }

  // COMMUNITY SERVICE ROUTES
  if ((str_contains($type, 'COMMUNITY') || str_contains($type, 'LOGIN') || str_contains($type, 'LOGOUT')) && $studentId !== '') {
    return 'community_service.php?q=' . urlencode($studentId);
  }

  // UPCC CASE ROUTES
  if ((str_contains($type, 'UPCC') || str_contains($type, 'CASE')) && $relatedId !== '') {
    return 'upcc_case_view.php?id=' . (int)$relatedId;
  }

  // GUARD VIOLATION / OFFENSE ROUTES
  if ((str_contains($type, 'GUARD') || str_contains($type, 'VIOLATION') || str_contains($type, 'OFFENSE')) && $studentId !== '') {
    return 'offenses_student_view.php?student_id=' . urlencode($studentId);
  }

  // ADMIN LOGIN - no routing
  if (str_contains($type, 'ADMIN')) {
    return '';
  }

  // DEADLINE - stays on notifications
  if (str_contains($type, 'DEADLINE')) {
    return '';
  }

  return '';
}

/**
 * Get badge label for notification type
 */
function getTypeBadge(string $type): string {
  $type = strtoupper($type);
  
  if (str_contains($type, 'COMMUNITY')) return 'Community Service';
  if (str_contains($type, 'UPCC') || str_contains($type, 'CASE')) return 'UPCC Case';
  if (str_contains($type, 'GUARD') || str_contains($type, 'VIOLATION')) return 'Violation Report';
  if (str_contains($type, 'DEADLINE')) return 'Deadline Alert';
  if (str_contains($type, 'ADMIN')) return 'Admin Activity';
  if (str_contains($type, 'OFFENSE')) return 'Offense Report';
  
  return 'Notification';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Notifications | SDO Web Portal</title>
  <style>
    *{ box-sizing:border-box; }
    body{ margin:0; font-family:'Segoe UI',Tahoma,Arial,sans-serif; background:#f8f9fa; color:#1b2244; }
    .admin-shell{ min-height: calc(100vh - 72px); display:grid; grid-template-columns: 240px 1fr; }
    .wrap{ min-height:100%; padding:0; }

    .page-header{
      background:#fff;
      border-bottom:1px solid #e0e0e0;
      padding: 28px 32px;
    }
    .page-header h1{
      margin:0;
      color:#1a1a1a;
      font-size:28px;
      font-weight:600;
    }
    .welcome{ margin-top:4px; color:#6c757d; font-size:14px; font-weight:400; }

    .content-area{ padding: 22px 32px; }

    .panel{
      background:#fff;
      border:1px solid #dee2e6;
      border-radius: 16px;
      box-shadow: 0 4px 12px rgba(20,36,74,0.08);
      padding: 18px;
    }

    .panel-top{
      display:flex;
      align-items:center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 14px;
      flex-wrap: wrap;
    }
    .panel-top h2{
      margin:0;
      font-size: 20px;
      font-weight:600;
      color:#1a1a1a;
      display:flex;
      align-items:center;
      gap: 10px;
    }

    .actions{ display:flex; gap:10px; align-items:center; flex-wrap: wrap; }
    .btn{
      height: 36px;
      padding: 0 16px;
      border-radius: 8px;
      border:1px solid #cfd4da;
      background:#fff;
      cursor:pointer;
      font-weight:500;
      color:#1a1a1a;
      font-size: 14px;
      transition: all .15s;
    }
    .btn:hover{ border-color:#3b4a9e; color:#3b4a9e; background:#f0f2ff; }

    .btn-danger{
      border-color: rgba(220,53,69,.45);
      color:#dc3545;
      background:#fff;
    }
    .btn-danger:hover{
      border-color:#dc3545;
      background: rgba(220,53,69,.08);
      color:#dc3545;
    }

    .badge{
      display:inline-flex;
      align-items:center;
      justify-content:center;
      min-width: 22px;
      height: 22px;
      padding: 0 8px;
      border-radius: 999px;
      background:#2d3a7e;
      color:#fff;
      font-weight:600;
      font-size: 12px;
    }

    .type-badge {
      display: inline-block;
      padding: 3px 9px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .3px;
      margin-top: 6px;
    }

    .type-badge.community { background: #e0f2fe; color: #0369a1; }
    .type-badge.upcc { background: #fce7f3; color: #be185d; }
    .type-badge.violation { background: #fee2e2; color: #991b1b; }
    .type-badge.deadline { background: #fef3c7; color: #92400e; }
    .type-badge.admin { background: #f0fdf4; color: #166534; }
    .type-badge.offense { background: #fee2e2; color: #991b1b; }

    .notif{
      border: 1px solid #dee2e6;
      border-radius: 14px;
      padding: 14px 16px;
      display:flex;
      gap: 14px;
      align-items:flex-start;
      margin-bottom: 12px;
      background:#fff;
      text-decoration:none;
      color: inherit;
      transition: background .15s ease, border-color .15s ease, transform .15s ease;
      pointer-events: auto;
    }

    .notif.unread{
      background:#f7fbff;
      border-color:#b9ddff;
      border-left: 4px solid #2d3a7e;
    }

    .notif.clickable{
      cursor:pointer;
    }

    .notif.clickable:hover{
      background:#f8f9fa;
      border-color:#3b4a9e;
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(45,58,126,0.12);
    }

    .notif:focus{
      outline: 3px solid rgba(59,74,158,.18);
      outline-offset: 2px;
    }

    .ico{
      width: 44px;
      height: 44px;
      border-radius: 12px;
      display:grid;
      place-items:center;
      flex-shrink:0;
    }
    .ico svg{
      width: 26px;
      height: 26px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .ico.blue{ color:#0d6efd; background: rgba(13,110,253,.12); }
    .ico.green{ color:#22c55e; background: rgba(34,197,94,.12); }
    .ico.purple{ color:#7c3aed; background: rgba(124,58,237,.12); }
    .ico.red{ color:#ef4444; background: rgba(239,68,68,.12); }
    .ico.amber{ color:#f59e0b; background: rgba(245,158,11,.12); }
    .ico.gray{ color:#6c757d; background: rgba(108,117,125,.10); }

    .text{ flex:1; min-width: 0; }
    .title{
      font-weight:600;
      color:#1a1a1a;
      margin-top: 2px;
      line-height: 1.4;
      font-size: 15px;
      word-break: break-word;
    }
    .msg{
      margin-top: 6px;
      color:#6c757d;
      font-weight: 400;
      line-height: 1.35;
      font-size: 13px;
      word-break: break-word;
    }
    .time{
      margin-top: 8px;
      color:#9aa0a6;
      font-weight: 400;
      font-size: 12px;
      display:flex;
      gap: 12px;
      align-items:center;
      flex-wrap: wrap;
    }
    .openhint{
      display:inline-flex;
      align-items:center;
      gap:6px;
      color:#3b4a9e;
      font-weight:600;
      font-size: 12px;
    }

    .empty{
      padding: 40px 10px;
      text-align:center;
      color:#6c757d;
      font-weight:400;
    }

    .flash-note {
      margin: 0 0 12px;
      background: #eef6ff;
      border: 1px solid #cfe2ff;
      color: #1f3f7a;
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 13px;
      font-weight: 600;
    }

    .guard-review {
      margin-top: 8px;
      padding: 10px;
      border-radius: 10px;
      border: 1px solid #e9ecef;
      background: #f8f9fb;
    }

    .guard-review-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px 10px;
      margin-bottom: 8px;
      font-size: 12px;
      color: #4d5871;
    }

    .guard-review-desc {
      font-size: 12px;
      color: #4d5871;
      margin-bottom: 8px;
    }

    .guard-actions {
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
    }

    .btn-mini {
      height: 30px;
      padding: 0 12px;
      border-radius: 8px;
      border: 1px solid #cfd4da;
      background: #fff;
      color: #1a1a1a;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .btn-mini:hover { border-color: #3b4a9e; color: #3b4a9e; background: #f0f2ff; }
    .btn-mini-approve { border-color: #28a745; color: #1e7e34; background: #f1fbf4; }
    .btn-mini-approve:hover { background: #e8f8ed; }
    .btn-mini-reject { border-color: #dc3545; color: #b02a37; background: #fff5f5; }
    .btn-mini-reject:hover { background: #ffecec; }

    @media (max-width: 900px){
      .admin-shell{ grid-template-columns: 1fr; }
      .content-area{ padding: 18px 16px; }
      .page-header{ padding: 20px 16px; }

      .panel{ padding: 14px; }
      .panel-top{ gap: 10px; }

      .actions{ width:100%; }
      .btn{
        width:100%;
        height: 42px;
        border-radius: 12px;
      }

      .notif{
        padding: 12px;
        gap: 12px;
      }
      .guard-review-grid { grid-template-columns: 1fr; }
      .ico{
        width: 40px;
        height: 40px;
        border-radius: 12px;
      }
      .ico svg{
        width: 24px;
        height: 24px;
      }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>

  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="wrap">
      <section class="page-header">
        <h1>Notifications</h1>
        <div class="welcome">Welcome, <?php echo e($fullName); ?></div>
      </section>

      <div class="content-area">
        <section class="panel">
          <div class="panel-top">
            <h2>
              All Notifications
              <?php if ($unreadCount > 0): ?>
                <span class="badge"><?php echo (int)$unreadCount; ?></span>
              <?php endif; ?>
            </h2>

            <div class="actions">
              <form method="post" style="margin:0; flex: 1 1 auto;">
                <input type="hidden" name="action" value="mark_all_read" />
                <button class="btn" type="submit">Mark All as Read</button>
              </form>

              <form method="post" style="margin:0; flex: 1 1 auto;" onsubmit="return confirm('Soft-delete ALL notifications?');">
                <input type="hidden" name="action" value="delete_all" />
                <button class="btn btn-danger" type="submit">Delete All</button>
              </form>
              
              <form method="post" style="margin:0; flex: 1 1 auto;" onsubmit="return confirm('Delete all resolved (Approved/Rejected) reports from the audit log?');">
                <input type="hidden" name="action" value="delete_resolved" />
                <button class="btn btn-danger" type="submit">Delete All Resolved</button>
              </form>
            </div>
          </div>

          <?php if ($flashText !== ''): ?>
            <div class="flash-note"><?php echo e($flashText); ?></div>
          <?php endif; ?>

          <?php if (empty($items)): ?>
            <div class="empty">No notifications yet.</div>
          <?php else: ?>
            <?php foreach ($items as $n): ?>
              <?php
                $href = notifHref($n);
                $isUnread = ((int)($n['is_read'] ?? 0) === 0);
                $isClickable = ($href !== '');
                $classes = 'notif ' . ($isUnread ? 'unread ' : '') . ($isClickable ? 'clickable' : '');
                $isGuardReport = strtoupper((string)($n['type'] ?? '')) === 'GUARD_REPORT'
                  && strtoupper((string)($n['related_table'] ?? '')) === 'GUARD_VIOLATION_REPORT';
                $guardDetails = null;
                if ($isGuardReport) {
                  $guardDetails = $guardReportMap[(int)($n['related_id'] ?? 0)] ?? null;
                }
                
                // Get type badge class
                $type = strtoupper((string)$n['type']);
                $typeBadgeClass = 'type-badge ';
                if (str_contains($type, 'COMMUNITY')) $typeBadgeClass .= 'community';
                elseif (str_contains($type, 'UPCC') || str_contains($type, 'CASE')) $typeBadgeClass .= 'upcc';
                elseif (str_contains($type, 'GUARD') || str_contains($type, 'VIOLATION') || str_contains($type, 'OFFENSE')) $typeBadgeClass .= 'violation';
                elseif (str_contains($type, 'DEADLINE')) $typeBadgeClass .= 'deadline';
                elseif (str_contains($type, 'ADMIN')) $typeBadgeClass .= 'admin';
                else $typeBadgeClass .= 'offense';
              ?>

              <?php if ($isClickable): ?>
                <a class="<?php echo e(trim($classes)); ?>" href="<?php echo e($href); ?>">
                  <?php echo iconSvg((string)$n['type']); ?>
                  <div class="text">
                    <div class="title"><?php echo e((string)$n['title']); ?></div>
                    <span class="<?php echo $typeBadgeClass; ?>"><?php echo getTypeBadge($type); ?></span>
                    <div class="msg"><?php echo e((string)$n['message']); ?></div>
                    <div class="time">
                      <?php echo date('M j, Y \a\t h:i A', strtotime((string)$n['created_at'])); ?>
                      <span class="openhint">→ View</span>
                    </div>
                  </div>
                </a>
              <?php else: ?>
                <div class="<?php echo e(trim($classes)); ?>">
                  <?php echo iconSvg((string)$n['type']); ?>
                  <div class="text">
                    <div class="title"><?php echo e((string)$n['title']); ?></div>
                    <span class="<?php echo $typeBadgeClass; ?>"><?php echo getTypeBadge($type); ?></span>
                    <div class="msg"><?php echo e((string)$n['message']); ?></div>
                    <div class="time"><?php echo date('M j, Y \a\t h:i A', strtotime((string)$n['created_at'])); ?></div>

                    <?php if ($isGuardReport): ?>
                      <div class="guard-review">
                        <?php if ($guardDetails): ?>
                          <div class="guard-review-grid">
                            <div><strong>Student:</strong> <?php echo e(trim((string)$guardDetails['student_name']) !== '' ? (string)$guardDetails['student_name'] : (string)$guardDetails['student_id']); ?></div>
                            <div><strong>Submitted By:</strong> <?php echo e((string)($guardDetails['guard_name'] ?? 'Guard')); ?></div>
                            <div><strong>Offense:</strong> <?php echo e((string)($guardDetails['offense_code'] ?? '')); ?> - <?php echo e((string)($guardDetails['offense_name'] ?? '')); ?></div>
                            <div><strong>Level:</strong> <?php echo e((string)($guardDetails['offense_level'] ?? '')); ?></div>
                            <div><strong>Date Committed:</strong> <?php echo e(date('M j, Y h:i A', strtotime((string)$guardDetails['date_committed']))); ?></div>
                            <div><strong>Status:</strong> <?php echo e((string)$guardDetails['status']); ?></div>
                          </div>
                          <?php if (trim((string)($guardDetails['description'] ?? '')) !== ''): ?>
                            <div class="guard-review-desc"><strong>Description:</strong> <?php echo e((string)$guardDetails['description']); ?></div>
                          <?php endif; ?>

                          <div class="guard-actions">
                            <?php if (strtoupper((string)$guardDetails['status']) === 'PENDING'): ?>
                              <a class="btn-mini" href="offenses_student_view.php?student_id=<?php echo urlencode((string)$guardDetails['student_id']); ?>">View Student</a>

                              <form method="post" style="margin:0;">
                                <input type="hidden" name="action" value="approve_guard_report" />
                                <input type="hidden" name="report_id" value="<?php echo (int)$guardDetails['report_id']; ?>" />
                                <button type="submit" class="btn-mini btn-mini-approve">Accept</button>
                              </form>

                              <form method="post" style="margin:0;" onsubmit="return confirm('Reject this submission? This will mark it rejected and it will NOT be saved to student offenses.');">
                                <input type="hidden" name="action" value="reject_guard_report" />
                                <input type="hidden" name="report_id" value="<?php echo (int)$guardDetails['report_id']; ?>" />
                                <button type="submit" class="btn-mini btn-mini-reject">Reject</button>
                              </form>
                            <?php else: ?>
                              <!-- Resolved items (APPROVED/REJECTED) can no longer be "viewed" as actionable -->
                              <span style="font-size: 13px; font-weight: bold; color: <?php echo (strtoupper((string)$guardDetails['status']) === 'APPROVED') ? '#1e7e34' : '#b02a37'; ?>;">
                                  [<?php echo htmlspecialchars(strtoupper((string)$guardDetails['status'])); ?>]
                              </span>
                              
                              <form method="post" style="margin:0;">
                                <input type="hidden" name="action" value="delete_single" />
                                <input type="hidden" name="notif_id" value="<?php echo (int)$n['notification_id']; ?>" />
                                <button type="submit" class="btn-mini btn-mini-reject" style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b;">Delete from Audit</button>
                              </form>
                            <?php endif; ?>
                          </div>
                        <?php else: ?>
                          <div class="guard-review-desc">Report details are no longer available.</div>
                        <?php endif; ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
            <?php endforeach; ?>
          <?php endif; ?>
        </section>
      </div>
    </main>
  </div>
</body>
</html>

