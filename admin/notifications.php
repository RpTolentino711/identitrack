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

function getNotifVisuals(array $n): array {
  $type = strtoupper(trim((string)($n['type'] ?? '')));
  $title = strtoupper(trim((string)($n['title'] ?? '')));
  $relTable = strtoupper(trim((string)($n['related_table'] ?? '')));
  $text = $type . ' ' . $title . ' ' . $relTable;

  // 1. HEARING / PANELIST ACCEPTED OR DECLINED
  if (str_contains($text, 'HEARING_ACCEPTED') || str_contains($text, 'PANELIST ACCEPTED')) {
    return [
      'ico_class'   => 'purple',
      'ico_svg'     => '<svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><polyline points="16 11 18 13 22 9"></polyline></svg>',
      'badge_class' => 'upcc',
      'badge_label' => 'Panel Accepted',
      'badge_icon'  => '<svg viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"></polyline></svg>'
    ];
  }

  if (str_contains($text, 'HEARING_DECLINED') || str_contains($text, 'PANELIST DECLINED')) {
    return [
      'ico_class'   => 'red',
      'ico_svg'     => '<svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><line x1="17" y1="9" x2="21" y2="13"></line><line x1="21" y1="9" x2="17" y2="13"></line></svg>',
      'badge_class' => 'escalation',
      'badge_label' => 'Panel Declined',
      'badge_icon'  => '<svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>'
    ];
  }

  // 2. ESCALATION / SECTION 4 TRIGGER
  if (str_contains($text, 'ESCALATION') || str_contains($text, 'SECTION 4')) {
    return [
      'ico_class'   => 'red',
      'ico_svg'     => '<svg viewBox="0 0 24 24"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"></polygon><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>',
      'badge_class' => 'escalation',
      'badge_label' => 'Section 4 Escalation',
      'badge_icon'  => '<svg viewBox="0 0 24 24"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>'
    ];
  }

  // 3. NOTICE / CONDUCT LETTER / PARENT NOTICE / NTE
  if (str_contains($text, 'CONDUCT NOTICE') || str_contains($text, 'NOTICE SENT') || str_contains($text, 'LETTER') || str_contains($text, 'NTE') || str_contains($text, 'PARENT')) {
    return [
      'ico_class'   => 'blue',
      'ico_svg'     => '<svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>',
      'badge_class' => 'notice',
      'badge_label' => 'Conduct Notice',
      'badge_icon'  => '<svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"></path><polyline points="22,6 12,13 2,6"></polyline></svg>'
    ];
  }

  // 4. GUARD REPORT SUBMISSION
  if (str_contains($text, 'GUARD')) {
    return [
      'ico_class'   => 'amber',
      'ico_svg'     => '<svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>',
      'badge_class' => 'violation',
      'badge_label' => 'Guard Submission',
      'badge_icon'  => '<svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>'
    ];
  }

  // 5. UPCC CASE / HEARING PROCEEDINGS
  if (str_contains($text, 'UPCC') || str_contains($text, 'CASE') || str_contains($text, 'HEARING')) {
    return [
      'ico_class'   => 'purple',
      'ico_svg'     => '<svg viewBox="0 0 24 24"><path d="m14 13-7.5 7.5c-.8.8-2.2.8-3 0s-.8-2.2 0-3L11 10"></path><path d="m16 16 6-6"></path><path d="m8 8 6-6"></path><path d="m9 7 8 8"></path><path d="m21 11-8-8"></path></svg>',
      'badge_class' => 'upcc',
      'badge_label' => 'UPCC Case',
      'badge_icon'  => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><path d="M12 6v6l4 2"></path></svg>'
    ];
  }

  // 6. COMMUNITY SERVICE / TIME IN / OUT / PAUSE
  if (str_contains($text, 'COMMUNITY') || str_contains($text, 'CS_SESSION') || str_contains($text, 'SERVICE')) {
    return [
      'ico_class'   => 'green',
      'ico_svg'     => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>',
      'badge_class' => 'community',
      'badge_label' => 'Community Service',
      'badge_icon'  => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>'
    ];
  }

  // 7. VIOLATION / OFFENSE
  if (str_contains($text, 'VIOLATION') || str_contains($text, 'OFFENSE')) {
    return [
      'ico_class'   => 'red',
      'ico_svg'     => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>',
      'badge_class' => 'offense',
      'badge_label' => 'Offense Report',
      'badge_icon'  => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line></svg>'
    ];
  }

  // 8. DEADLINE / WARNING / ALERT
  if (str_contains($text, 'DEADLINE') || str_contains($text, 'DUE') || str_contains($text, 'WARNING') || str_contains($text, 'ALERT')) {
    return [
      'ico_class'   => 'amber',
      'ico_svg'     => '<svg viewBox="0 0 24 24"><circle cx="12" cy="13" r="8"></circle><path d="M12 9v4l2 2"></path><path d="M5 3 2 6"></path><path d="m22 6-3-3"></path></svg>',
      'badge_class' => 'deadline',
      'badge_label' => 'Deadline Alert',
      'badge_icon'  => '<svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle></svg>'
    ];
  }

  // 9. ADMIN ACTIVITY / AUTH
  if (str_contains($text, 'ADMIN') || str_contains($text, 'LOGIN') || str_contains($text, 'LOGOUT')) {
    return [
      'ico_class'   => 'indigo',
      'ico_svg'     => '<svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>',
      'badge_class' => 'admin',
      'badge_label' => 'Admin Activity',
      'badge_icon'  => '<svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>'
    ];
  }

  // Default notice
  return [
    'ico_class'   => 'indigo',
    'ico_svg'     => '<svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>',
    'badge_class' => 'general',
    'badge_label' => 'Notice',
    'badge_icon'  => '<svg viewBox="0 0 24 24"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path></svg>'
  ];
}

function iconSvg($typeOrItem): string {
  $n = is_array($typeOrItem) ? $typeOrItem : ['type' => $typeOrItem];
  $vis = getNotifVisuals($n);
  return '<span class="ico ' . $vis['ico_class'] . '" aria-hidden="true">' . $vis['ico_svg'] . '</span>';
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

  // UPCC CASE / ESCALATION ROUTES
  if ((str_contains($type, 'UPCC') || str_contains($type, 'CASE') || str_contains($type, 'HEARING') || str_contains($type, 'DECLINED') || str_contains($type, 'ESCALATION') || $relatedTable === 'UPCC_CASE') && $relatedId !== '') {
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
  $vis = getNotifVisuals(['type' => $type]);
  return $vis['badge_label'];
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
      font-size: 13.5px;
      transition: all .15s;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
    }
    .btn svg { flex-shrink: 0; }
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
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 9px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .3px;
      margin-top: 6px;
    }
    .type-badge svg {
      width: 12px;
      height: 12px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2.2;
      stroke-linecap: round;
      stroke-linejoin: round;
      flex-shrink: 0;
    }

    .type-badge.community  { background: #e0f2fe; color: #0369a1; }
    .type-badge.upcc       { background: #ede9fe; color: #6d28d9; }
    .type-badge.escalation { background: #fee2e2; color: #b91c1c; }
    .type-badge.violation  { background: #fee2e2; color: #991b1b; }
    .type-badge.notice     { background: #e0f2fe; color: #0284c7; }
    .type-badge.deadline   { background: #fef3c7; color: #92400e; }
    .type-badge.admin      { background: #f0fdf4; color: #166534; }
    .type-badge.offense    { background: #ffedd5; color: #c2410c; }
    .type-badge.general    { background: #f1f5f9; color: #475569; }

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
      width: 22px;
      height: 22px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }
    .ico.blue{ color:#0284c7; background: #e0f2fe; }
    .ico.green{ color:#16a34a; background: #dcfce7; }
    .ico.purple{ color:#7c3aed; background: #f3e8ff; }
    .ico.red{ color:#dc2626; background: #fee2e2; }
    .ico.amber{ color:#d97706; background: #fef3c7; }
    .ico.slate{ color:#475569; background: #f1f5f9; }
    .ico.indigo{ color:#4f46e5; background: #e0e7ff; }

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
      gap:5px;
      color:#3b4a9e;
      font-weight:600;
      font-size: 12px;
    }

    .empty{
      padding: 48px 16px;
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
      padding: 10px 14px;
      font-size: 13px;
      font-weight: 600;
      display: flex;
      align-items: center;
      gap: 8px;
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
        <h1 style="display:flex; align-items:center; gap:10px;">
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#2d3a7e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
          Notifications
        </h1>
        <div class="welcome">Welcome, <?php echo e($fullName); ?></div>
      </section>

      <div class="content-area">
        <section class="panel">
          <div class="panel-top">
            <h2>
              <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#2d3a7e" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
              All Notifications
              <?php if ($unreadCount > 0): ?>
                <span class="badge"><?php echo (int)$unreadCount; ?></span>
              <?php endif; ?>
            </h2>

            <div class="actions">
              <form method="post" style="margin:0; flex: 1 1 auto;">
                <input type="hidden" name="action" value="mark_all_read" />
                <button class="btn" type="submit">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"></polyline><polyline points="20 12 12 20 9 17"></polyline></svg>
                  Mark All as Read
                </button>
              </form>

              <form method="post" style="margin:0; flex: 1 1 auto;" onsubmit="return confirm('Soft-delete ALL notifications?');">
                <input type="hidden" name="action" value="delete_all" />
                <button class="btn btn-danger" type="submit">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                  Delete All
                </button>
              </form>
              
              <form method="post" style="margin:0; flex: 1 1 auto;" onsubmit="return confirm('Delete all resolved (Approved/Rejected) reports from the audit log?');">
                <input type="hidden" name="action" value="delete_resolved" />
                <button class="btn btn-danger" type="submit">
                  <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="21 8 21 21 3 21 3 8"></polyline><rect x="1" y="3" width="22" height="5"></rect><line x1="10" y1="12" x2="14" y2="12"></line></svg>
                  Delete All Resolved
                </button>
              </form>
            </div>
          </div>

          <?php if ($flashText !== ''): ?>
            <div class="flash-note">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;"><polyline points="20 6 9 17 4 12"></polyline></svg>
              <span><?php echo e($flashText); ?></span>
            </div>
          <?php endif; ?>

          <?php if (empty($items)): ?>
            <div class="empty">
              <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#9ca3af" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" style="display:block; margin: 0 auto 10px;"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
              No notifications yet.
            </div>
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
                
                $vis = getNotifVisuals($n);
              ?>

              <?php if ($isClickable): ?>
                <a class="<?php echo e(trim($classes)); ?>" href="<?php echo e($href); ?>">
                  <?php echo iconSvg($n); ?>
                  <div class="text">
                    <div class="title"><?php echo e((string)$n['title']); ?></div>
                    <span class="type-badge <?php echo $vis['badge_class']; ?>">
                      <?php echo $vis['badge_icon']; ?>
                      <?php echo e($vis['badge_label']); ?>
                    </span>
                    <div class="msg"><?php echo e((string)$n['message']); ?></div>
                    <div class="time">
                      <span style="display:inline-flex; align-items:center; gap:4px;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <?php echo date('M j, Y \a\t h:i A', strtotime((string)$n['created_at'])); ?>
                      </span>
                      <span class="openhint">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"></path><polyline points="15 3 21 3 21 9"></polyline><line x1="10" y1="14" x2="21" y2="3"></line></svg>
                        View Details
                      </span>
                    </div>
                  </div>
                </a>
              <?php else: ?>
                <div class="<?php echo e(trim($classes)); ?>">
                  <?php echo iconSvg($n); ?>
                  <div class="text">
                    <div class="title"><?php echo e((string)$n['title']); ?></div>
                    <span class="type-badge <?php echo $vis['badge_class']; ?>">
                      <?php echo $vis['badge_icon']; ?>
                      <?php echo e($vis['badge_label']); ?>
                    </span>
                    <div class="msg"><?php echo e((string)$n['message']); ?></div>
                    <div class="time">
                      <span style="display:inline-flex; align-items:center; gap:4px;">
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                        <?php echo date('M j, Y \a\t h:i A', strtotime((string)$n['created_at'])); ?>
                      </span>
                    </div>

                    <?php if ($isGuardReport): ?>
                      <div class="guard-review">
                        <?php if ($guardDetails): ?>
                          <div class="guard-review-grid">
                            <div><strong><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px; margin-right:3px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>Student:</strong> <?php echo e(trim((string)$guardDetails['student_name']) !== '' ? (string)$guardDetails['student_name'] : (string)$guardDetails['student_id']); ?></div>
                            <div><strong><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px; margin-right:3px;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>Submitted By:</strong> <?php echo e((string)($guardDetails['guard_name'] ?? 'Guard')); ?></div>
                            <div><strong><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px; margin-right:3px;"><polygon points="7.86 2 16.14 2 22 7.86 22 16.14 16.14 22 7.86 22 2 16.14 2 7.86 7.86 2"></polygon><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>Offense:</strong> <?php echo e((string)($guardDetails['offense_code'] ?? '')); ?> - <?php echo e((string)($guardDetails['offense_name'] ?? '')); ?></div>
                            <div><strong><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px; margin-right:3px;"><path d="M20.59 13.41l-7.17 7.17a2 2 0 0 1-2.83 0L2 12V2h10l8.59 8.59a2 2 0 0 1 0 2.82z"></path><line x1="7" y1="7" x2="7.01" y2="7"></line></svg>Level:</strong> <?php echo e((string)($guardDetails['offense_level'] ?? '')); ?></div>
                            <div><strong><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px; margin-right:3px;"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>Date Committed:</strong> <?php echo e(date('M j, Y h:i A', strtotime((string)$guardDetails['date_committed']))); ?></div>
                            <div><strong><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="vertical-align:-1px; margin-right:3px;"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="16" x2="12" y2="12"></line><line x1="12" y1="8" x2="12.01" y2="8"></line></svg>Status:</strong> <?php echo e((string)$guardDetails['status']); ?></div>
                          </div>
                          <?php if (trim((string)($guardDetails['description'] ?? '')) !== ''): ?>
                            <div class="guard-review-desc"><strong>Description:</strong> <?php echo e((string)$guardDetails['description']); ?></div>
                          <?php endif; ?>

                          <div class="guard-actions">
                            <?php if (strtoupper((string)$guardDetails['status']) === 'PENDING'): ?>
                              <a class="btn-mini" href="offenses_student_view.php?student_id=<?php echo urlencode((string)$guardDetails['student_id']); ?>">
                                <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                                View Student
                              </a>

                              <form method="post" style="margin:0;">
                                <input type="hidden" name="action" value="approve_guard_report" />
                                <input type="hidden" name="report_id" value="<?php echo (int)$guardDetails['report_id']; ?>" />
                                <button type="submit" class="btn-mini btn-mini-approve">
                                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:4px;"><polyline points="20 6 9 17 4 12"></polyline></svg>
                                  Accept
                                </button>
                              </form>

                              <form method="post" style="margin:0;" onsubmit="return confirm('Reject this submission? This will mark it rejected and it will NOT be saved to student offenses.');">
                                <input type="hidden" name="action" value="reject_guard_report" />
                                <input type="hidden" name="report_id" value="<?php echo (int)$guardDetails['report_id']; ?>" />
                                <button type="submit" class="btn-mini btn-mini-reject">
                                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="margin-right:4px;"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>
                                  Reject
                                </button>
                              </form>
                            <?php else: ?>
                              <!-- Resolved items (APPROVED/REJECTED) can no longer be "viewed" as actionable -->
                              <span style="font-size: 13px; font-weight: bold; color: <?php echo (strtoupper((string)$guardDetails['status']) === 'APPROVED') ? '#1e7e34' : '#b02a37'; ?>;">
                                  [<?php echo htmlspecialchars(strtoupper((string)$guardDetails['status'])); ?>]
                              </span>
                              
                              <form method="post" style="margin:0;">
                                <input type="hidden" name="action" value="delete_single" />
                                <input type="hidden" name="notif_id" value="<?php echo (int)$n['notification_id']; ?>" />
                                <button type="submit" class="btn-mini btn-mini-reject" style="background:#fef2f2; border:1px solid #fca5a5; color:#991b1b;">
                                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-right:4px;"><polyline points="3 6 5 6 21 6"></polyline><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path></svg>
                                  Delete from Audit
                                </button>
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

