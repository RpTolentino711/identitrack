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
     AND (admin_id IS NULL OR admin_id = ?)",
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
          "SELECT level, major_category FROM offense_type WHERE offense_type_id = :oid LIMIT 1",
          [':oid' => (int)$report['offense_type_id']]
        );

        if ($offenseType) {
          $level = strtoupper((string)$offenseType['level']);
          $majorCategory = (int)($offenseType['major_category'] ?? 0);
          $studentId = (string)$report['student_id'];

          db_exec(
            "INSERT INTO offense (student_id, recorded_by, offense_type_id, level, description, date_committed, status, created_at, updated_at)
             VALUES (:sid, :admin, :tid, :lvl, :descr, :dt, 'OPEN', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            [
              ':sid' => $studentId,
              ':admin' => $adminId,
              ':tid' => (int)$report['offense_type_id'],
              ':lvl' => $level,
              ':descr' => ($report['description'] === '' ? null : $report['description']),
              ':dt' => (string)$report['date_committed'],
            ]
          );
          $newOffenseId = (int)db_last_id();

          $isEsc = false;
          if ($level === 'MAJOR') {
              db_exec(
                "INSERT INTO upcc_case (student_id, created_by, status, case_kind, case_summary, created_at, updated_at)
                 VALUES (:sid, :aid, 'UNDER_INVESTIGATION', 'MAJOR_OFFENSE', :summary, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [
                  ':sid'     => $studentId,
                  ':aid'     => $adminId,
                  ':summary' => 'Major Offense - Category ' . $majorCategory . ' - UPCC investigation required',
                ]
              );
              $caseId = (int)db_last_id();
              db_exec(
                "INSERT INTO upcc_case_offense (case_id, offense_id) VALUES (:case_id, :offense_id)",
                [':case_id' => $caseId, ':offense_id' => $newOffenseId]
              );
          } elseif ($level === 'MINOR') {
              $cycleInfo = getStudentActiveMinorCycle($studentId);
              $afterMinor = (int)($cycleInfo['existing_count'] ?? $cycleInfo['active_count'] ?? 0);
              $maxSameCount = (int)($cycleInfo['max_same_type_count'] ?? 0);
              $isEsc = ($maxSameCount >= 3 || $afterMinor >= 4) && (bool)($cycleInfo['is_escalation_triggered'] ?? false);

              $existingSection4Case = db_one(
                "SELECT case_id FROM upcc_case
                 WHERE student_id = :sid
                   AND status IN ('PENDING','UNDER_INVESTIGATION','UNDER_APPEAL')
                   AND case_kind = 'SECTION4_MINOR_ESCALATION'
                 LIMIT 1",
                [':sid' => $studentId]
              );

              if (!$existingSection4Case && $isEsc) {
                $reason = $cycleInfo['trigger_reason'] ?? 'NONE';
                $cycleNum = $cycleInfo['current_cycle_num'] ?? 1;
                $ordStr = getOrdinal($cycleNum);

                if ($reason === 'SAME_TYPE_3') {
                    $typeName = $cycleInfo['max_same_type_name'];
                    $summaryStr = 'Section 4 Major (' . $ordStr . ' Escalation - 3 Same Minor Offenses: ' . $typeName . ') → Referred to UPCC panel for investigation and category assignment (1‑5).';
                } else {
                    $summaryStr = 'Section 4 Major (' . $ordStr . ' Escalation - 4 Different Minor Offenses) → Referred to UPCC panel for investigation and category assignment (1‑5).';
                }

                db_exec(
                  "INSERT INTO upcc_case (student_id, created_by, status, case_kind, case_summary, created_at, updated_at)
                   VALUES (:sid, :aid, 'PENDING', 'SECTION4_MINOR_ESCALATION', :summary, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                  [
                    ':sid'     => $studentId,
                    ':aid'     => $adminId,
                    ':summary' => $summaryStr,
                  ]
                );
                $caseId = (int)db_last_id();

                $triggerMinors = $cycleInfo['minors'] ?? [];
                if ($reason === 'SAME_TYPE_3') {
                    $targetTypeId = $cycleInfo['max_same_type_id'];
                    $triggerMinors = array_values(array_filter($triggerMinors, function($m) use ($targetTypeId) {
                        return (int)$m['offense_type_id'] === $targetTypeId;
                    }));
                }
                foreach ($triggerMinors as $minor) {
                  if (!empty($minor['offense_id'])) {
                    db_exec(
                      "INSERT INTO upcc_case_offense (case_id, offense_id) VALUES (:case_id, :offense_id)",
                      [':case_id' => $caseId, ':offense_id' => (int)$minor['offense_id']]
                    );
                  }
                }
              }
          }

          db_exec(
            "UPDATE guard_violation_report
             SET status = 'APPROVED', reviewed_by = :admin, reviewed_at = NOW(), review_notes = :note
             WHERE report_id = :rid",
            [':admin' => $adminId, ':note' => 'Approved by admin via notifications.', ':rid' => $reportId]
          );

          db_exec(
            "UPDATE notification
             SET is_read = 1
             WHERE type = 'GUARD_REPORT'
               AND related_table = 'guard_violation_report'
               AND related_id = :rid",
            [':rid' => $reportId]
          );

          if (!empty($level) && $level === 'MAJOR') {
              redirect('offense_new.php?level=MAJOR&student_id=' . urlencode($studentId) . '&letter=1&offense_id=' . $newOffenseId . '&type=major&success=1');
          } elseif (!empty($isEsc) && $isEsc) {
              redirect('offense_new.php?level=MINOR&student_id=' . urlencode($studentId) . '&letter=1&offense_id=' . $newOffenseId . '&type=escalation&success=1');
          } elseif (isset($afterMinor) && $afterMinor === 2) {
              redirect('offense_new.php?level=MINOR&student_id=' . urlencode($studentId) . '&letter=1&offense_id=' . $newOffenseId . '&type=letter&minor_no=2&success=1');
          }

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
     AND (n.admin_id IS NULL OR n.admin_id = :admin_id)
   ORDER BY n.created_at DESC
   LIMIT 200",
  [':admin_id' => $adminId]
);

// Unread count
$unreadRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM notification
   WHERE is_deleted=0 AND is_read=0
     AND (admin_id IS NULL OR admin_id = ?)",
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
  if ((str_contains($type, 'UPCC') || str_contains($type, 'CASE') || str_contains($type, 'HEARING') || str_contains($type, 'DECLINED')) && $relatedId !== '') {
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
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    /* ══════════════════════════════════════════════════════════════════════
       SDO ADMIN — NOTIFICATION CENTER
       Deep slate + cool cyan institutional interface.
       ══════════════════════════════════════════════════════════════════════ */

    :root{
      --font: 'Inter', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
      --mono: 'IBM Plex Mono', 'Courier New', monospace;

      --surface-0: #0a0f1c;
      --surface-1: #0f1626;
      --surface-2: #141d30;
      --surface-3: #1a2540;
      --surface-hi: #1e2a47;

      --ink-50:  #f5f7fa;
      --ink-100: #e8ecf2;
      --ink-200: #cdd5e0;
      --ink-300: #98a3b4;
      --ink-400: #6b7889;
      --ink-500: #4a5568;
      --ink-600: #2f3a4d;

      --accent:      #3d9ce0;
      --accent-hi:   #5cb2f0;
      --accent-soft: rgba(61, 156, 224, 0.12);
      --accent-line: rgba(61, 156, 224, 0.35);

      --sage:      #6faf8e;
      --sage-soft: rgba(111, 175, 142, 0.12);
      --sage-line: rgba(111, 175, 142, 0.35);

      --rose:      #d16868;
      --rose-soft: rgba(209, 104, 104, 0.12);
      --rose-line: rgba(209, 104, 104, 0.35);

      --amber:      #d4a24c;
      --amber-soft: rgba(212, 162, 76, 0.12);
      --amber-line: rgba(212, 162, 76, 0.35);

      --violet:      #9b85d8;
      --violet-soft: rgba(155, 133, 216, 0.12);
      --violet-line: rgba(155, 133, 216, 0.35);

      --border-1: rgba(255,255,255,0.06);
      --border-2: rgba(255,255,255,0.10);
      --border-3: rgba(255,255,255,0.16);

      --radius-sm: 4px;
      --radius-md: 6px;
      --radius-lg: 10px;
      --radius-xl: 14px;

      --shadow-sm: 0 1px 2px rgba(0,0,0,0.3);
      --shadow-md: 0 4px 16px rgba(0,0,0,0.25);
      --shadow-lg: 0 12px 32px rgba(0,0,0,0.4);
    }

    *{ box-sizing:border-box; }

    body{
      margin:0;
      font-family: var(--font);
      background: var(--surface-0);
      background-image:
        radial-gradient(ellipse 80% 60% at 0% 0%, rgba(61,156,224,0.05), transparent 60%),
        radial-gradient(ellipse 60% 50% at 100% 100%, rgba(155,133,216,0.04), transparent 60%);
      background-attachment: fixed;
      color: var(--ink-100);
      font-size: 14px;
      line-height: 1.55;
      -webkit-font-smoothing: antialiased;
      letter-spacing: -0.005em;
    }

    .admin-shell{ min-height: calc(100vh - 72px); display:grid; grid-template-columns: 248px 1fr; }
    .wrap{ min-height:100%; padding:0; }

    /* ── Page Header ────────────────────────────────────────────────────── */
    .page-header{
      background: linear-gradient(180deg, var(--surface-1) 0%, var(--surface-0) 100%);
      border-bottom: 1px solid var(--border-1);
      padding: 26px 32px 24px;
      position: relative;
    }
    .page-header::after{
      content:''; position:absolute; left:32px; right:32px; bottom:-1px; height:1px;
      background: linear-gradient(90deg, var(--accent-soft), transparent 40%);
    }
    .page-header h1{
      margin: 0;
      color: var(--ink-50);
      font-size: 26px;
      font-weight: 700;
      letter-spacing: -0.02em;
      line-height: 1.15;
    }
    .welcome{
      margin-top: 6px;
      color: var(--ink-400);
      font-size: 12.5px;
      font-weight: 500;
      letter-spacing: 0.02em;
    }
    .welcome strong{ color: var(--ink-200); font-weight: 600; }

    .content-area{ padding: 24px 32px 60px; max-width: 1180px; }

    /* ── Panel ──────────────────────────────────────────────────────────── */
    .panel{
      background: linear-gradient(180deg, var(--surface-1) 0%, var(--surface-2) 100%);
      border: 1px solid var(--border-1);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow-sm);
      padding: 0;
      overflow: hidden;
    }

    .panel-top{
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      padding: 18px 22px;
      border-bottom: 1px solid var(--border-1);
      background: rgba(0,0,0,0.15);
      flex-wrap: wrap;
      position: relative;
    }
    .panel-top::after{
      content:''; position:absolute; left:22px; right:22px; bottom:-1px; height:1px;
      background: linear-gradient(90deg, var(--accent-soft), transparent 50%);
    }
    .panel-top h2{
      margin: 0;
      font-size: 13px;
      font-weight: 700;
      color: var(--ink-100);
      display: flex;
      align-items: center;
      gap: 10px;
      text-transform: uppercase;
      letter-spacing: 0.12em;
      padding-left: 12px;
      position: relative;
    }
    .panel-top h2::before{
      content:''; position:absolute; left:0; top:50%; transform: translateY(-50%);
      width: 3px; height: 14px; background: var(--accent); border-radius: 2px;
    }

    /* ── Action Buttons ─────────────────────────────────────────────────── */
    .actions{ display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .btn{
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      height: 34px;
      padding: 0 14px;
      border-radius: var(--radius-sm);
      border: 1px solid var(--border-2);
      background: rgba(255,255,255,0.02);
      cursor: pointer;
      font-weight: 600;
      font-family: var(--font);
      color: var(--ink-100);
      font-size: 12px;
      letter-spacing: 0.02em;
      transition: all .15s ease;
      white-space: nowrap;
      line-height: 1;
    }
    .btn:hover{
      border-color: var(--accent-line);
      color: var(--accent-hi);
      background: var(--accent-soft);
    }
    .btn:active{ transform: translateY(1px); }

    .btn-danger{
      border-color: var(--rose-line);
      color: #e8a0a0;
      background: transparent;
    }
    .btn-danger:hover{
      border-color: var(--rose);
      background: var(--rose-soft);
      color: #e8a0a0;
    }

    /* ── Badge ──────────────────────────────────────────────────────────── */
    .badge{
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 22px;
      height: 20px;
      padding: 0 7px;
      border-radius: 3px;
      background: var(--accent-soft);
      color: var(--accent-hi);
      border: 1px solid var(--accent-line);
      font-weight: 700;
      font-size: 10.5px;
      font-family: var(--mono);
      letter-spacing: 0.04em;
    }

    /* ── Type Badges ────────────────────────────────────────────────────── */
    .type-badge {
      display: inline-block;
      padding: 3px 8px;
      border-radius: 3px;
      font-size: 9.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.1em;
      margin-top: 6px;
      border: 1px solid transparent;
      font-family: var(--font);
    }

    .type-badge.community  { background: var(--accent-soft); color: #7cc2ee; border-color: var(--accent-line); }
    .type-badge.upcc       { background: var(--violet-soft); color: #b9a8e6; border-color: var(--violet-line); }
    .type-badge.violation  { background: var(--rose-soft);   color: #e8a0a0; border-color: var(--rose-line); }
    .type-badge.deadline   { background: var(--amber-soft);  color: #e8b767; border-color: var(--amber-line); }
    .type-badge.admin      { background: var(--sage-soft);   color: #8fc9a5; border-color: var(--sage-line); }
    .type-badge.offense    { background: var(--rose-soft);   color: #e8a0a0; border-color: var(--rose-line); }

    /* ── Notification item ──────────────────────────────────────────────── */
    .notif-list{ padding: 16px 22px 22px; }

    .notif{
      border: 1px solid var(--border-1);
      border-radius: var(--radius-md);
      padding: 16px 18px;
      display: flex;
      gap: 16px;
      align-items: flex-start;
      margin-bottom: 10px;
      background: rgba(0,0,0,0.2);
      text-decoration: none;
      color: inherit;
      transition: all .18s ease;
      pointer-events: auto;
      position: relative;
    }

    .notif.unread{
      background: rgba(61,156,224,0.04);
      border-color: var(--accent-line);
    }
    .notif.unread::before{
      content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
      background: var(--accent); border-radius: var(--radius-md) 0 0 var(--radius-md);
    }

    .notif.clickable{ cursor: pointer; }

    .notif.clickable:hover{
      background: rgba(61,156,224,0.06);
      border-color: var(--accent-line);
      transform: translateX(2px);
    }

    .notif:focus{
      outline: 2px solid var(--accent-line);
      outline-offset: 2px;
    }

    /* ── Icon ───────────────────────────────────────────────────────────── */
    .ico{
      width: 42px;
      height: 42px;
      border-radius: var(--radius-md);
      display: grid;
      place-items: center;
      flex-shrink: 0;
      border: 1px solid transparent;
    }
    .ico svg{
      width: 20px;
      height: 20px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .ico.blue   { color: #7cc2ee; background: var(--accent-soft);  border-color: var(--accent-line); }
    .ico.green  { color: #8fc9a5; background: var(--sage-soft);    border-color: var(--sage-line); }
    .ico.purple { color: #b9a8e6; background: var(--violet-soft);  border-color: var(--violet-line); }
    .ico.red    { color: #e8a0a0; background: var(--rose-soft);    border-color: var(--rose-line); }
    .ico.amber  { color: #e8b767; background: var(--amber-soft);   border-color: var(--amber-line); }
    .ico.gray   { color: var(--ink-300); background: rgba(255,255,255,0.04); border-color: var(--border-2); }

    /* ── Text Block ─────────────────────────────────────────────────────── */
    .text{ flex: 1; min-width: 0; }
    .title{
      font-weight: 600;
      color: var(--ink-50);
      margin-top: 2px;
      line-height: 1.45;
      font-size: 13.5px;
      word-break: break-word;
      letter-spacing: 0.005em;
    }
    .msg{
      margin-top: 8px;
      color: var(--ink-200);
      font-weight: 400;
      line-height: 1.55;
      font-size: 12.5px;
      word-break: break-word;
    }
    .time{
      margin-top: 10px;
      color: var(--ink-400);
      font-weight: 500;
      font-size: 11px;
      display: flex;
      gap: 12px;
      align-items: center;
      flex-wrap: wrap;
      font-family: var(--mono);
      letter-spacing: 0.02em;
    }
    .openhint{
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: var(--accent-hi);
      font-weight: 700;
      font-size: 11px;
      letter-spacing: 0.06em;
      text-transform: uppercase;
      font-family: var(--font);
    }

    /* ── Empty state ────────────────────────────────────────────────────── */
    .empty{
      padding: 60px 20px;
      text-align: center;
      color: var(--ink-400);
      font-weight: 500;
      font-size: 13px;
      line-height: 1.6;
    }
    .empty::before{
      content: '◯';
      display: block;
      font-size: 32px;
      margin-bottom: 12px;
      color: var(--ink-500);
      font-weight: 300;
    }

    /* ── Flash Note ─────────────────────────────────────────────────────── */
    .flash-note {
      margin: 16px 22px 0;
      background: var(--sage-soft);
      border: 1px solid var(--sage-line);
      color: #8fc9a5;
      border-radius: var(--radius-md);
      padding: 12px 16px;
      font-size: 12.5px;
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 10px;
      letter-spacing: 0.02em;
    }
    .flash-note::before{
      content: '✓';
      font-weight: 800;
      font-size: 14px;
      color: #8fc9a5;
      flex-shrink: 0;
    }

    /* ── Guard Review Block ────────────────────────────────────────────── */
    .guard-review {
      margin-top: 12px;
      padding: 14px 16px;
      border-radius: var(--radius-md);
      border: 1px solid var(--amber-line);
      background: var(--amber-soft);
    }

    .guard-review-grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px 18px;
      margin-bottom: 12px;
      font-size: 12px;
      color: var(--ink-200);
    }
    .guard-review-grid strong{
      color: var(--ink-400);
      font-size: 10.5px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-weight: 700;
      display: block;
      margin-bottom: 2px;
    }

    .guard-review-desc {
      font-size: 12px;
      color: var(--ink-200);
      margin-bottom: 12px;
      line-height: 1.55;
    }
    .guard-review-desc strong{
      color: var(--ink-400);
      font-size: 10.5px;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-weight: 700;
      display: block;
      margin-bottom: 4px;
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
      border-radius: var(--radius-sm);
      border: 1px solid var(--border-2);
      background: rgba(255,255,255,0.03);
      color: var(--ink-100);
      font-size: 11.5px;
      font-weight: 600;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      font-family: var(--font);
      transition: all .15s ease;
      letter-spacing: 0.02em;
      line-height: 1;
    }

    .btn-mini:hover{ border-color: var(--accent-line); color: var(--accent-hi); background: var(--accent-soft); }

    .btn-mini-approve {
      border-color: var(--sage-line);
      color: #8fc9a5;
      background: var(--sage-soft);
    }
    .btn-mini-approve:hover {
      background: rgba(111,175,142,0.22);
      border-color: var(--sage);
      color: #8fc9a5;
    }

    .btn-mini-reject {
      border-color: var(--rose-line);
      color: #e8a0a0;
      background: var(--rose-soft);
    }
    .btn-mini-reject:hover {
      background: rgba(209,104,104,0.22);
      border-color: var(--rose);
      color: #e8a0a0;
    }

    /* ── Status Chips (approved/rejected tags in guard reviews) ─────────── */
    .status-chip{
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 3px;
      font-size: 10.5px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
      font-family: var(--font);
      border: 1px solid transparent;
    }
    .status-chip.approved{
      background: var(--sage-soft); color: #8fc9a5; border-color: var(--sage-line);
    }
    .status-chip.rejected{
      background: var(--rose-soft); color: #e8a0a0; border-color: var(--rose-line);
    }
    .status-chip.pending{
      background: var(--amber-soft); color: #e8b767; border-color: var(--amber-line);
    }

    /* ── Responsive ─────────────────────────────────────────────────────── */
    @media (max-width: 900px){
      .admin-shell{ grid-template-columns: 1fr; }
      .content-area{ padding: 18px 16px 40px; }
      .page-header{ padding: 20px 18px; }
      .page-header h1{ font-size: 22px; }

      .panel-top{ padding: 16px 18px; gap: 12px; }
      .notif-list{ padding: 14px 16px 18px; }

      .actions{ width: 100%; }
      .btn{
        width: 100%;
        height: 40px;
        border-radius: var(--radius-md);
      }

      .notif{
        padding: 14px;
        gap: 12px;
      }
      .guard-review-grid { grid-template-columns: 1fr; }
      .ico{
        width: 38px;
        height: 38px;
      }
      .ico svg{
        width: 18px;
        height: 18px;
      }
    }

    /* ── Scrollbar polish ───────────────────────────────────────────────── */
    ::-webkit-scrollbar{ width: 8px; height: 8px; }
    ::-webkit-scrollbar-track{ background: transparent; }
    ::-webkit-scrollbar-thumb{ background: var(--border-3); border-radius: 4px; }
    ::-webkit-scrollbar-thumb:hover{ background: var(--ink-500); }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>

  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="wrap">
      <section class="page-header">
        <h1>Notification Center</h1>
        <div class="welcome">Signed in as <strong><?php echo e($fullName); ?></strong> · System-wide activity feed &amp; audit log</div>
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
              <form method="post" style="margin:0;">
                <input type="hidden" name="action" value="mark_all_read" />
                <button class="btn" type="submit">Mark All Read</button>
              </form>

              <form method="post" style="margin:0;" onsubmit="return confirm('Soft-delete ALL notifications?');">
                <input type="hidden" name="action" value="delete_all" />
                <button class="btn btn-danger" type="submit">Delete All</button>
              </form>
              
              <form method="post" style="margin:0;" onsubmit="return confirm('Delete all resolved (Approved/Rejected) reports from the audit log?');">
                <input type="hidden" name="action" value="delete_resolved" />
                <button class="btn btn-danger" type="submit">Delete Resolved</button>
              </form>
            </div>
          </div>

          <?php if ($flashText !== ''): ?>
            <div class="flash-note"><?php echo e($flashText); ?></div>
          <?php endif; ?>

          <?php if (empty($items)): ?>
            <div class="empty">No notifications yet.</div>
          <?php else: ?>
            <div class="notif-list">
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
                              <div>
                                <strong>Student</strong>
                                <?php echo e(trim((string)$guardDetails['student_name']) !== '' ? (string)$guardDetails['student_name'] : (string)$guardDetails['student_id']); ?>
                              </div>
                              <div>
                                <strong>Submitted By</strong>
                                <?php echo e((string)($guardDetails['guard_name'] ?? 'Guard')); ?>
                              </div>
                              <div>
                                <strong>Offense</strong>
                                <?php echo e((string)($guardDetails['offense_code'] ?? '')); ?> — <?php echo e((string)($guardDetails['offense_name'] ?? '')); ?>
                              </div>
                              <div>
                                <strong>Level</strong>
                                <?php echo e((string)($guardDetails['offense_level'] ?? '')); ?>
                              </div>
                              <div>
                                <strong>Date Committed</strong>
                                <?php echo e(date('M j, Y h:i A', strtotime((string)$guardDetails['date_committed']))); ?>
                              </div>
                              <div>
                                <strong>Status</strong>
                                <?php
                                  $gStatus = strtoupper((string)$guardDetails['status']);
                                  $statusClass = 'status-chip ';
                                  if ($gStatus === 'APPROVED') $statusClass .= 'approved';
                                  elseif ($gStatus === 'REJECTED') $statusClass .= 'rejected';
                                  else $statusClass .= 'pending';
                                ?>
                                <span class="<?php echo $statusClass; ?>" style="margin-top: 4px;"><?php echo e($gStatus); ?></span>
                              </div>
                            </div>
                            <?php if (trim((string)($guardDetails['description'] ?? '')) !== ''): ?>
                              <div class="guard-review-desc">
                                <strong>Description</strong>
                                <?php echo e((string)$guardDetails['description']); ?>
                              </div>
                            <?php endif; ?>

                            <div class="guard-actions">
                              <?php if (strtoupper((string)$guardDetails['status']) === 'PENDING'): ?>
                                <a class="btn-mini" href="offenses_student_view.php?student_id=<?php echo urlencode((string)$guardDetails['student_id']); ?>">View Student</a>

                                <form method="post" style="margin:0;">
                                  <input type="hidden" name="action" value="approve_guard_report" />
                                  <input type="hidden" name="report_id" value="<?php echo (int)$guardDetails['report_id']; ?>" />
                                  <button type="submit" class="btn-mini btn-mini-approve">✓ Accept</button>
                                </form>

                                <form method="post" style="margin:0;" onsubmit="return confirm('Reject this submission? This will mark it rejected and it will NOT be saved to student offenses.');">
                                  <input type="hidden" name="action" value="reject_guard_report" />
                                  <input type="hidden" name="report_id" value="<?php echo (int)$guardDetails['report_id']; ?>" />
                                  <button type="submit" class="btn-mini btn-mini-reject">✕ Reject</button>
                                </form>
                              <?php else: ?>
                                <!-- Resolved items (APPROVED/REJECTED) can no longer be "viewed" as actionable -->
                                <span class="status-chip <?php echo (strtoupper((string)$guardDetails['status']) === 'APPROVED') ? 'approved' : 'rejected'; ?>">
                                  <?php echo strtoupper((string)$guardDetails['status']); ?>
                                </span>
                                
                                <form method="post" style="margin:0;">
                                  <input type="hidden" name="action" value="delete_single" />
                                  <input type="hidden" name="notif_id" value="<?php echo (int)$n['notification_id']; ?>" />
                                  <button type="submit" class="btn-mini btn-mini-reject">Delete from Audit</button>
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
            </div>
          <?php endif; ?>
        </section>
      </div>
    </main>
  </div>
</body>
</html>