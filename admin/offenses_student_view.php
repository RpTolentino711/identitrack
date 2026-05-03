<?php
require_once __DIR__ . '/../database/database.php';
require_admin();

require_once __DIR__ . '/class.phpmailer.php';
require_once __DIR__ . '/class.smtp.php';

$activeSidebar = 'offenses';

$admin = admin_current();
$fullName = trim((string)($admin['full_name'] ?? ''));
if ($fullName === '') $fullName = (string)($admin['username'] ?? 'User');

$studentId = trim((string)($_GET['student_id'] ?? ''));
if ($studentId === '') redirect('offenses.php');

$student = db_one(
  "SELECT student_id, student_fn, student_ln, year_level, program, school, section, student_email
   FROM student
   WHERE student_id = :sid
   LIMIT 1",
  [':sid' => $studentId]
);
if (!$student) redirect('offenses.php');

$studentName = trim((string)($student['student_fn'] ?? '') . ' ' . (string)($student['student_ln'] ?? ''));
if ($studentName === '') {
  $studentName = (string)$student['student_id'];
}

$adminId = (int)($admin['admin_id'] ?? $admin['id'] ?? 0);
$pendingReportId = (int)($_GET['pending_report_id'] ?? 0);

$guardReviewFlash = '';
$scanFlash = '';
$guardReviewFlashKey = trim((string)($_GET['gr_msg'] ?? ''));
if ($guardReviewFlashKey === 'approved') {
  $guardReviewFlash = 'Guard report approved and offense record created.';
} elseif ($guardReviewFlashKey === 'rejected') {
  $guardReviewFlash = 'Guard report rejected.';
} elseif ($guardReviewFlashKey === 'failed') {
  $guardReviewFlash = 'Failed to process the selected guard report.';
}

$scanFlashKey = trim((string)($_GET['scan_msg'] ?? ''));
if ($scanFlashKey === 'pending_guard_found') {
  $scanFlash = 'A pending guard report was found for this student.';
} elseif ($scanFlashKey === 'no_offense_record') {
  $scanFlash = 'No existing offense record found for this student.';
} elseif ($scanFlashKey === 'student_not_found') {
  $scanFlash = 'No student match found for the scanned ID.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $reportId = (int)($_POST['report_id'] ?? 0);

  if ($reportId > 0 && ($action === 'approve_guard_report' || $action === 'reject_guard_report')) {
    $report = db_one(
      "SELECT report_id, student_id, offense_type_id, date_committed, description, status
       FROM guard_violation_report
       WHERE report_id = :rid AND student_id = :sid AND is_deleted = 0
       LIMIT 1",
      [':rid' => $reportId, ':sid' => $studentId]
    );

    if ($report && strtoupper((string)$report['status']) === 'PENDING') {
      if ($action === 'approve_guard_report') {
        $offenseType = db_one(
          "SELECT level, major_category FROM offense_type WHERE offense_type_id = :oid LIMIT 1",
          [':oid' => (int)$report['offense_type_id']]
        );

        if ($offenseType) {
          $level = strtoupper((string)$offenseType['level']);
          $majorCategory = (int)($offenseType['major_category'] ?? 0);

          db_exec(
            "INSERT INTO offense (student_id, recorded_by, offense_type_id, level, description, date_committed, status, created_at, updated_at)
             VALUES (:sid, :admin, :tid, :lvl, :descr, :dt, 'OPEN', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            [
              ':sid' => (string)$report['student_id'],
              ':admin' => $adminId,
              ':tid' => (int)$report['offense_type_id'],
              ':lvl' => $level,
              ':descr' => trim((string)($report['description'] ?? '')) === '' ? null : $report['description'],
              ':dt' => (string)$report['date_committed'],
            ]
          );
          $newOffenseId = (int)db_last_id();

          // Trigger UPCC Case logic
          if ($level === 'MAJOR') {
              db_exec(
                "INSERT INTO upcc_case (student_id, created_by, status, case_kind, case_summary, created_at, updated_at)
                 VALUES (:sid, :aid, 'UNDER_INVESTIGATION', 'MAJOR_OFFENSE', :summary, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [
                  ':sid'     => (string)$report['student_id'],
                  ':aid'     => $adminId,
                  ':summary' => 'Major Offense - Category ' . $majorCategory . ' - UPCC investigation required',
                ]
              );
              $caseId = (int)db_last_id();

              db_exec(
                "INSERT INTO upcc_case_offense (case_id, offense_id) VALUES (:case_id, :offense_id)",
                [':case_id' => $caseId, ':offense_id' => $newOffenseId]
              );

              // Send email to guardian
              $studentRow = db_one(
                "SELECT s.student_fn, s.student_ln, g.guardian_email, g.guardian_fn, g.guardian_ln
                 FROM student s
                 LEFT JOIN guardian g ON s.student_id = g.student_id
                 WHERE s.student_id = :sid LIMIT 1",
                [':sid' => (string)$report['student_id']]
              );

              $guardianEmail = trim((string)($studentRow['guardian_email'] ?? ''));
              if ($guardianEmail !== '') {
                  $guardianName = trim((string)($studentRow['guardian_fn'] ?? '') . ' ' . (string)($studentRow['guardian_ln'] ?? ''));
                  if ($guardianName === '') $guardianName = 'Parent/Guardian';
                  $studentName = trim($studentRow['student_fn'] . ' ' . $studentRow['student_ln']);
                  
                  $mail = new PHPMailer(true);
                  try {
                      $mail->CharSet = 'UTF-8';
                      $mail->isSMTP();
                      $mail->Host = 'smtp.gmail.com';
                      $mail->Port = 587;
                      $mail->SMTPAuth = true;
                      $mail->SMTPSecure = 'tls';
                      $mail->Username = 'romeopaolotolentino@gmail.com';
                      $mail->Password = 'xhggajjeixakajoj';
                      
                      $mail->setFrom($mail->Username, 'IdentiTrack SDO');
                      $mail->addAddress($guardianEmail, $guardianName);
                      $mail->addReplyTo('no-reply@identitrack.local', 'IdentiTrack');
                      
                      $mail->isHTML(true);
                      $mail->Subject = 'Major Offense Notice - UPCC Investigation Required';
                      
                      $letterBody = "Please be advised that $studentName has been reported for a Major Offense. This case is now an active case under UPCC investigation and a hearing will be required.";
                      
                      $safeBody = nl2br(htmlspecialchars($letterBody, ENT_QUOTES, 'UTF-8'));
                      
                      $mail->Body = "
                        <div style='font-family:Segoe UI,Tahoma,Arial,sans-serif;'>
                          <p>Good day,</p>
                          <hr style='border:none;border-top:1px solid #e5e7eb;margin:14px 0;' />
                          <div style='color:#374151;font-size:14px;line-height:1.6;'>{$safeBody}</div>
                          <p style='margin-top:18px;color:#6b7280;font-size:12px;'>This is an automated message from IdentiTrack SDO Web Portal.</p>
                        </div>
                      ";
                      
                      $mail->AltBody = "Major Offense Notice\n\n" . $letterBody;
                      $mail->send();
                  } catch (Exception $e) {
                      error_log('Guard report mail error: ' . $e->getMessage());
                  }
              }
          } elseif ($level === 'MINOR') {
              $afterRow = db_one(
                "SELECT COUNT(*) AS cnt FROM offense WHERE student_id = :sid AND level = 'MINOR'",
                [':sid' => (string)$report['student_id']]
              );
              $afterMinor = (int)($afterRow['cnt'] ?? 0);

              $existingSection4Case = db_one(
                "SELECT case_id FROM upcc_case
                 WHERE student_id = :sid
                   AND status IN ('PENDING','UNDER_APPEAL')
                   AND case_kind = 'SECTION4_MINOR_ESCALATION'
                 LIMIT 1",
                [':sid' => (string)$report['student_id']]
              );

              if (!$existingSection4Case && $afterMinor >= 3) {
                db_exec(
                  "INSERT INTO upcc_case (student_id, created_by, status, case_kind, case_summary, created_at, updated_at)
                   VALUES (:sid, :aid, 'PENDING', 'SECTION4_MINOR_ESCALATION', :summary, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                  [
                    ':sid'     => (string)$report['student_id'],
                    ':aid'     => $adminId,
                    ':summary' => 'Section 4 — 3 minor offenses → Referred to UPCC panel for investigation and category assignment (1‑5).',
                  ]
                );
                $caseId = (int)db_last_id();

                $triggerMinors = db_all(
                  "SELECT offense_id FROM offense
                   WHERE student_id = :sid AND level = 'MINOR'
                   ORDER BY date_committed ASC
                   LIMIT 3",
                  [':sid' => (string)$report['student_id']]
                );
                foreach ($triggerMinors as $minor) {
                  db_exec(
                    "INSERT INTO upcc_case_offense (case_id, offense_id) VALUES (:case_id, :offense_id)",
                    [':case_id' => $caseId, ':offense_id' => (int)$minor['offense_id']]
                  );
                }
              }
          }

          db_exec(
            "UPDATE guard_violation_report
             SET status = 'APPROVED', reviewed_by = :admin, reviewed_at = NOW(), review_notes = :note
             WHERE report_id = :rid",
            [':admin' => $adminId, ':note' => 'Approved by admin.', ':rid' => $reportId]
          );

          db_exec(
            "UPDATE notification
             SET is_read = 1, is_deleted = 1
             WHERE type = 'GUARD_REPORT'
               AND related_table = 'guard_violation_report'
               AND related_id = :rid",
            [':rid' => $reportId]
          );

          $nextPending = db_one(
            "SELECT report_id FROM guard_violation_report 
             WHERE student_id = :sid AND status = 'PENDING' AND is_deleted = 0 
             ORDER BY created_at ASC LIMIT 1",
            [':sid' => $studentId]
          );
          $nextIdParam = $nextPending ? '&pending_report_id=' . (int)$nextPending['report_id'] : '';
          
          redirect('offenses_student_view.php?student_id=' . urlencode($studentId) . '&gr_msg=approved' . $nextIdParam);
        }
      } else {
        db_exec(
          "UPDATE guard_violation_report
           SET status = 'REJECTED', reviewed_by = :admin, reviewed_at = NOW(), review_notes = :note
           WHERE report_id = :rid",
          [':admin' => $adminId, ':note' => 'Rejected by admin.', ':rid' => $reportId]
        );

        db_exec(
          "UPDATE notification
           SET is_read = 1, is_deleted = 1
           WHERE type = 'GUARD_REPORT'
             AND related_table = 'guard_violation_report'
             AND related_id = :rid",
          [':rid' => $reportId]
        );

        $nextPending = db_one(
          "SELECT report_id FROM guard_violation_report 
           WHERE student_id = :sid AND status = 'PENDING' AND is_deleted = 0 
           ORDER BY created_at ASC LIMIT 1",
          [':sid' => $studentId]
        );
        $nextIdParam = $nextPending ? '&pending_report_id=' . (int)$nextPending['report_id'] : '';
        
        redirect('offenses_student_view.php?student_id=' . urlencode($studentId) . '&gr_msg=rejected' . $nextIdParam);
      }
    }

    redirect('offenses_student_view.php?student_id=' . urlencode($studentId) . '&gr_msg=failed');
  }
}

$pendingGuardReport = null;
if ($pendingReportId > 0) {
  $pendingGuardReport = db_one(
    "SELECT
       r.report_id,
       r.student_id,
       r.date_committed,
       r.created_at,
       ot.code AS offense_code,
       ot.name AS offense_name,
       ot.level AS offense_level,
       sg.full_name AS guard_name
     FROM guard_violation_report r
     JOIN offense_type ot ON ot.offense_type_id = r.offense_type_id
     LEFT JOIN security_guard sg ON sg.guard_id = r.submitted_by
     WHERE r.report_id = :rid
       AND r.student_id = :sid
       AND r.status = 'PENDING'
       AND r.is_deleted = 0
     LIMIT 1",
    [':rid' => $pendingReportId, ':sid' => $studentId]
  );
}

$allGuardReports = db_all(
  "SELECT
     r.report_id,
     r.student_id,
     r.date_committed,
     r.description,
     r.status,
     r.created_at,
     r.reviewed_at,
     ot.code AS offense_code,
     ot.name AS offense_name,
     ot.level AS offense_level,
     sg.full_name AS guard_name,
     au.full_name AS reviewer_name
   FROM guard_violation_report r
   JOIN offense_type ot ON ot.offense_type_id = r.offense_type_id
   LEFT JOIN security_guard sg ON sg.guard_id = r.submitted_by
   LEFT JOIN admin_user au ON au.admin_id = r.reviewed_by
   WHERE r.student_id = :sid
     AND r.is_deleted = 0
     AND r.status = 'PENDING'
   ORDER BY r.created_at DESC, r.report_id DESC",
  [':sid' => $studentId]
);

function initials2(string $fn, string $ln): string {
  $a = strtoupper(substr(trim($fn), 0, 1));
  $b = strtoupper(substr(trim($ln), 0, 1));
  return ($a ?: '?') . ($b ?: '?');
}
$avatar = initials2((string)$student['student_fn'], (string)$student['student_ln']);

$suffix = match((int)$student['year_level']) { 1=>'st', 2=>'nd', 3=>'rd', default=>'th' };

// Fetch all offenses, oldest first for correct grouping
$history = db_all(
  "SELECT o.offense_id, o.status, o.description, o.date_committed,
          o.level,
          ot.code, ot.name, ot.major_category,
          uc.status AS uc_status, uc.decided_category AS uc_category,
          uc.final_decision AS uc_decision, uc.punishment_details AS uc_punishment, uc.resolution_date AS uc_resolution_date
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   LEFT JOIN upcc_case_offense uco ON uco.offense_id = o.offense_id
   LEFT JOIN upcc_case uc ON uc.case_id = uco.case_id
   WHERE o.student_id = :sid
   ORDER BY o.date_committed ASC",
  [':sid' => $studentId]
);

$totalOffenses   = count($history);
$rawMajorCount   = count(array_filter($history, fn($h) => $h['level'] === 'MAJOR'));
$minorCount      = $totalOffenses - $rawMajorCount;

// Separate minors and majors
$allMinors = array_values(array_filter($history, fn($h) => $h['level'] === 'MINOR'));
$allMajors = array_filter($history, fn($h) => $h['level'] === 'MAJOR');

// Create escalation groups: every 3 minors (in order) becomes one major escalation card
$escalationGroups = [];
for ($i = 0; $i < count($allMinors); $i += 3) {
    $group = array_slice($allMinors, $i, 3);
    if (count($group) === 3) {
        $lastMinorId = $group[2]['offense_id'];
        $caseRow = db_one(
            "SELECT uc.status, uc.decided_category, uc.final_decision, uc.punishment_details, uc.resolution_date
             FROM upcc_case uc
             JOIN upcc_case_offense uco ON uc.case_id = uco.case_id
             WHERE uco.offense_id = :oid
             LIMIT 1",
            [':oid' => $lastMinorId]
        );
        $escalationGroups[] = [
            'minors' => $group,
            'case_status' => $caseRow ? strtoupper((string)$caseRow['status']) : 'PENDING',
            'case_category' => $caseRow ? $caseRow['decided_category'] : null,
            'case_decision' => $caseRow ? $caseRow['final_decision'] : null,
            'case_punishment' => $caseRow ? $caseRow['punishment_details'] : null,
            'case_resolution_date' => $caseRow ? $caseRow['resolution_date'] : null
        ];
    }
}

// Major count = explicit majors + number of escalation groups
$majorCount = $rawMajorCount + count($escalationGroups);
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Student Offense Details | SDO Web Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet" />
  <style>
    /* (Keep all existing styles – unchanged from previous version) */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --navy:        #0a1628;
      --navy-mid:    #0f2040;
      --blue:        #1d4ed8;
      --blue-h:      #1e40af;
      --blue-soft:   #eff6ff;
      --blue-mid:    #dbeafe;
      --red:         #dc2626;
      --red-h:       #b91c1c;
      --red-soft:    #fef2f2;
      --red-mid:     #fee2e2;
      --amber:       #b45309;
      --amber-h:     #92400e;
      --amber-soft:  #fffbeb;
      --amber-mid:   #fde68a;
      --green:       #15803d;
      --green-soft:  #f0fdf4;
      --green-mid:   #bbf7d0;
      --border:      #e2e8f0;
      --border-mid:  #cbd5e1;
      --bg:          #f1f5f9;
      --bg-mid:      #e8edf5;
      --surface:     #ffffff;
      --surface-2:   #f8fafc;
      --text-1:      #0f172a;
      --text-2:      #334155;
      --text-3:      #64748b;
      --text-4:      #94a3b8;
      --radius-sm:   8px;
      --radius:      14px;
      --radius-lg:   20px;
      --shadow-sm:   0 1px 3px rgba(15,27,61,.06), 0 1px 2px rgba(15,27,61,.04);
      --shadow:      0 4px 16px rgba(15,27,61,.08), 0 2px 6px rgba(15,27,61,.05);
      --shadow-lg:   0 12px 40px rgba(15,27,61,.13), 0 4px 12px rgba(15,27,61,.08);
      --shadow-blue: 0 4px 20px rgba(29,78,216,.25);
    }

    html, body { height: 100%; }
    body {
      font-family: 'Sora', sans-serif;
      background: var(--bg);
      color: var(--text-1);
      font-size: 14px;
      line-height: 1.6;
    }

    /* ─── SHELL ─── */
    .admin-shell {
      min-height: calc(100vh - 72px);
      display: grid;
      grid-template-columns: 240px 1fr;
    }
    .main-wrap { min-height: 100%; display: flex; flex-direction: column; }

    /* ─── PAGE HEADER ─── */
    .page-header {
      background: var(--surface);
      border-bottom: 1px solid var(--border);
      padding: 16px 32px;
      display: flex;
      align-items: center;
      gap: 14px;
      position: sticky;
      top: 0;
      z-index: 10;
      backdrop-filter: blur(8px);
    }

    .back-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: var(--text-3);
      text-decoration: none;
      font-size: 13px;
      font-weight: 600;
      padding: 7px 14px;
      border-radius: var(--radius-sm);
      border: 1.5px solid var(--border);
      background: var(--surface);
      transition: all .18s cubic-bezier(.4,0,.2,1);
      flex-shrink: 0;
      letter-spacing: -.1px;
    }
    .back-btn svg { width: 14px; height: 14px; transition: transform .18s; }
    .back-btn:hover {
      border-color: var(--blue);
      color: var(--blue);
      background: var(--blue-soft);
      box-shadow: var(--shadow-blue);
      transform: translateX(-1px);
    }
    .back-btn:hover svg { transform: translateX(-2px); }

    .header-sep {
      width: 1px;
      height: 20px;
      background: var(--border);
      flex-shrink: 0;
    }

    .header-breadcrumb {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 13px;
      color: var(--text-4);
      font-weight: 500;
    }
    .header-breadcrumb a {
      color: var(--text-3);
      text-decoration: none;
      font-weight: 600;
      transition: color .15s;
    }
    .header-breadcrumb a:hover { color: var(--blue); }
    .header-breadcrumb span { color: var(--text-1); font-weight: 700; }
    .header-breadcrumb svg { width: 13px; height: 13px; }

    .header-admin {
      margin-left: auto;
      font-size: 12.5px;
      color: var(--text-4);
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 8px;
    }
    .header-admin-dot {
      width: 7px; height: 7px;
      border-radius: 50%;
      background: #22c55e;
      box-shadow: 0 0 0 2px #dcfce7;
    }
    .header-admin strong { color: var(--text-2); font-weight: 700; }

    /* ─── CONTENT ─── */
    .content-area {
      padding: 28px 32px;
      flex: 1;
      display: grid;
      grid-template-columns: 1fr 308px;
      gap: 22px;
      align-items: start;
    }

    @keyframes panelPopIn {
      0% { opacity: 0; transform: translateY(12px) scale(0.98); }
      50% { opacity: 1; transform: translateY(-4px) scale(1.01); }
      100% { opacity: 1; transform: translateY(0) scale(1); }
    }

    .guard-review-panel {
      background: linear-gradient(170deg, #ffffff 0%, #f8fbff 100%);
      border: 1px solid #d7e3f5;
      border-left: 5px solid #3b82f6;
      border-radius: 16px;
      padding: 16px 18px;
      margin-bottom: 14px;
      box-shadow: 0 8px 20px rgba(27, 53, 99, 0.08);
    }
    .guard-review-panel.major {
      background: linear-gradient(170deg, #ffffff 0%, #fffafa 100%);
      border: 1px solid #fecaca;
      border-left: 5px solid #ef4444;
      box-shadow: 0 8px 20px rgba(220, 38, 38, 0.08);
    }
    .guard-review-head {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      flex-wrap: wrap;
    }
    .guard-review-title {
      margin: 0;
      font-size: 16px;
      font-weight: 800;
      color: #1c355f;
      letter-spacing: -.2px;
    }
    .guard-review-panel.major .guard-review-title {
      color: #991b1b;
    }
    .guard-review-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      font-size: 11px;
      font-weight: 800;
      color: #1e4e95;
      background: #e8f1ff;
      border: 1px solid #c8dbfb;
      border-radius: 999px;
      padding: 4px 10px;
      text-transform: uppercase;
      letter-spacing: .35px;
    }
    .guard-review-panel.major .guard-review-badge {
      color: #b91c1c;
      background: #fef2f2;
      border-color: #fca5a5;
    }
    .guard-review-sub {
      margin-top: 3px;
      font-size: 12px;
      color: #4f6d99;
      font-weight: 700;
    }
    .guard-review-panel.major .guard-review-sub {
      color: #991b1b;
    }
    .guard-review-grid {
      margin-top: 11px;
      display: grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 8px 12px;
      font-size: 12px;
      color: #36527e;
    }
    .guard-review-grid strong { color: #1f3f6a; }
    .guard-review-actions {
      margin-top: 12px;
      display: flex;
      gap: 8px;
      flex-wrap: wrap;
      align-items: center;
    }
    .guard-pill {
      height: 34px;
      border-radius: 10px;
      padding: 0 16px;
      border: 1px solid transparent;
      font-family: 'Sora', sans-serif;
      font-size: 12.5px;
      font-weight: 700;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      transition: transform .16s ease, box-shadow .16s ease, opacity .16s ease;
    }
    .guard-pill-view {
      border-color: #c0d5f8;
      color: #2a4f8a;
      background: #edf4ff;
    }
    .guard-pill-approve {
      border-color: #81d4a6;
      color: #145f38;
      background: #eafbf1;
    }
    .guard-pill-reject {
      border-color: #ef9dad;
      color: #8f2330;
      background: #fff0f3;
    }
    .guard-pill:hover { opacity: .94; transform: translateY(-1px); box-shadow: 0 6px 14px rgba(27, 53, 99, 0.14); }

    .guard-flash {
      margin-bottom: 14px;
      border: 1px solid #c6dbff;
      background: #edf4ff;
      color: #1d3f79;
      border-radius: 10px;
      padding: 10px 12px;
      font-size: 12px;
      font-weight: 700;
    }

    .guard-report-list {
      display: grid;
      gap: 10px;
      margin-bottom: 14px;
    }
    .guard-report-item {
      border: 1px solid #d7e3f5;
      border-radius: 12px;
      background: #fff;
      padding: 10px 12px;
    }
    .guard-report-item.major {
      border-color: #fca5a5;
      border-left: 4px solid #ef4444;
    }
    .guard-report-item.current {
      border-color: #7aa7eb;
      box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.12);
    }
    .guard-report-item.major.current {
      border-color: #f87171;
      box-shadow: 0 0 0 2px rgba(239, 68, 68, 0.12);
    }
    .guard-report-top {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 10px;
      margin-bottom: 6px;
      flex-wrap: wrap;
    }
    .guard-report-title {
      font-size: 12px;
      font-weight: 800;
      color: #1c355f;
    }
    .guard-report-meta {
      font-size: 11px;
      color: #4b648b;
      display: flex;
      flex-wrap: wrap;
      gap: 10px;
    }
    .guard-status {
      display: inline-flex;
      align-items: center;
      height: 22px;
      border-radius: 999px;
      padding: 0 8px;
      font-size: 10.5px;
      font-weight: 800;
      letter-spacing: .25px;
      text-transform: uppercase;
    }
    .guard-status.pending {
      background: #fff7ed;
      border: 1px solid #fdba74;
      color: #9a3412;
    }
    .guard-status.approved {
      background: #ecfdf3;
      border: 1px solid #86efac;
      color: #166534;
    }
    .guard-status.rejected {
      background: #fff1f2;
      border: 1px solid #fda4af;
      color: #9f1239;
    }

    .guard-confirm-overlay {
      position: fixed;
      inset: 0;
      background: rgba(10, 22, 40, 0.48);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1300;
      opacity: 0;
      visibility: hidden;
      pointer-events: none;
      transition: opacity .18s ease;
      padding: 16px;
    }
    .guard-confirm-overlay.show {
      opacity: 1;
      visibility: visible;
      pointer-events: auto;
    }
    .guard-confirm-box {
      width: min(430px, 96vw);
      background: #fff;
      border: 1px solid #ecd3d8;
      border-radius: 12px;
      box-shadow: 0 14px 36px rgba(8, 22, 45, 0.3);
      padding: 14px;
      transform: translateY(10px);
      opacity: 0;
      transition: transform .2s ease, opacity .2s ease;
    }
    .guard-confirm-overlay.show .guard-confirm-box {
      transform: translateY(0);
      opacity: 1;
    }
    .guard-confirm-title {
      margin: 0;
      font-size: 15px;
      font-weight: 800;
      color: #7d1d2a;
    }
    .guard-confirm-text {
      margin: 7px 0 0;
      color: #475875;
      font-size: 12px;
      line-height: 1.5;
    }
    .guard-confirm-actions {
      margin-top: 12px;
      display: flex;
      justify-content: flex-end;
      gap: 8px;
      flex-wrap: wrap;
    }

    /* ─── CARD ─── */
    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius-lg);
      box-shadow: var(--shadow);
      overflow: hidden;
    }
    .card-header {
      padding: 18px 22px 16px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 14px;
      background: linear-gradient(180deg, #fafcff 0%, var(--surface) 100%);
    }
    .card-header__left { display: flex; flex-direction: column; gap: 2px; }
    .card-header__title {
      font-size: 15px;
      font-weight: 700;
      letter-spacing: -.3px;
      color: var(--text-1);
    }
    .card-header__sub {
      font-size: 12px;
      color: var(--text-4);
      font-weight: 500;
    }
    .card-body { padding: 20px 22px; }

    /* ─── PROFILE HERO ─── */
    .profile-hero {
      padding: 32px 22px 22px;
      background: linear-gradient(160deg, #eef4ff 0%, #f5f8ff 50%, #fafcff 100%);
      border-bottom: 1px solid var(--border);
      text-align: center;
      position: relative;
      overflow: hidden;
    }
    .profile-hero::before {
      content: '';
      position: absolute;
      top: -40px; right: -40px;
      width: 160px; height: 160px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(29,78,216,.07) 0%, transparent 70%);
      pointer-events: none;
    }
    .profile-hero::after {
      content: '';
      position: absolute;
      bottom: -30px; left: -30px;
      width: 120px; height: 120px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(29,78,216,.05) 0%, transparent 70%);
      pointer-events: none;
    }

    .avatar-wrap {
      position: relative;
      display: inline-block;
      margin-bottom: 16px;
    }
    .avatar-ring {
      width: 82px; height: 82px;
      border-radius: 50%;
      padding: 2.5px;
      background: conic-gradient(from 0deg, var(--blue), #60a5fa, var(--blue));
      box-shadow: 0 0 0 3px #fff, var(--shadow-lg);
    }
    .avatar-inner {
      width: 100%; height: 100%;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--navy) 0%, #1e3a6e 100%);
      color: #fff;
      font-size: 22px;
      font-weight: 800;
      display: grid;
      place-items: center;
      letter-spacing: -1px;
    }

    .profile-name {
      font-size: 17px;
      font-weight: 800;
      letter-spacing: -.4px;
      color: var(--text-1);
      line-height: 1.2;
    }
    .profile-id {
      font-family: 'JetBrains Mono', monospace;
      font-size: 11.5px;
      color: var(--text-4);
      margin-top: 5px;
      letter-spacing: .3px;
    }
    .profile-year {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      margin-top: 10px;
      font-size: 11.5px;
      font-weight: 700;
      color: var(--blue);
      background: var(--blue-soft);
      border: 1px solid var(--blue-mid);
      padding: 4px 12px;
      border-radius: 999px;
      letter-spacing: .2px;
    }

    /* ─── STATS ROW ─── */
    .stats-row {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      border-bottom: 1px solid var(--border);
    }
    .stat-cell {
      padding: 16px 10px;
      text-align: center;
      border-right: 1px solid var(--border);
      position: relative;
      transition: background .15s;
    }
    .stat-cell:last-child { border-right: none; }
    .stat-cell:hover { background: var(--surface-2); }
    .stat-val {
      font-size: 26px;
      font-weight: 800;
      letter-spacing: -1px;
      line-height: 1;
    }
    .stat-lbl {
      font-size: 10px;
      font-weight: 700;
      color: var(--text-4);
      text-transform: uppercase;
      letter-spacing: .8px;
      margin-top: 5px;
    }
    .stat-val.total  { color: var(--text-1); }
    .stat-val.major  { color: var(--red); }
    .stat-val.minor  { color: var(--amber); }

    /* ─── INFO LIST ─── */
    .info-list {
      padding: 18px 22px;
      display: flex;
      flex-direction: column;
      gap: 13px;
    }
    .info-row {
      display: flex;
      align-items: flex-start;
      gap: 11px;
      font-size: 13px;
    }
    .info-icon {
      width: 32px; height: 32px;
      border-radius: 9px;
      background: var(--surface-2);
      border: 1px solid var(--border);
      display: grid;
      place-items: center;
      flex-shrink: 0;
      color: var(--text-4);
      transition: all .15s;
    }
    .info-row:hover .info-icon {
      background: var(--blue-soft);
      border-color: var(--blue-mid);
      color: var(--blue);
    }
    .info-icon svg { width: 14px; height: 14px; }
    .info-lbl {
      font-size: 10px;
      color: var(--text-4);
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .6px;
      line-height: 1;
    }
    .info-val {
      font-size: 13px;
      font-weight: 600;
      color: var(--text-1);
      margin-top: 3px;
      line-height: 1.3;
    }

    /* ─── ADD BUTTON ─── */
    .add-btn-wrap {
      padding: 16px 22px;
      border-top: 1px solid var(--border);
      background: var(--surface-2);
    }
    .add-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      width: 100%;
      padding: 12px 16px;
      border-radius: var(--radius-sm);
      background: linear-gradient(135deg, var(--blue) 0%, #2563eb 100%);
      color: #fff;
      text-decoration: none;
      font-size: 13.5px;
      font-weight: 700;
      transition: all .2s cubic-bezier(.4,0,.2,1);
      border: none;
      letter-spacing: -.1px;
      box-shadow: 0 2px 8px rgba(29,78,216,.3);
    }
    .add-btn svg { width: 15px; height: 15px; }
    .add-btn:hover {
      background: linear-gradient(135deg, var(--blue-h) 0%, var(--blue) 100%);
      transform: translateY(-1px);
      box-shadow: 0 6px 20px rgba(29,78,216,.4);
    }
    .add-btn:active { transform: translateY(0); box-shadow: 0 2px 8px rgba(29,78,216,.3); }

    /* ─── FILTER BAR ─── */
    .filter-bar { display: flex; gap: 6px; flex-wrap: wrap; }
    .filter-chip {
      padding: 6px 14px;
      border-radius: var(--radius-sm);
      border: 1.5px solid var(--border);
      background: var(--surface);
      font-size: 12px;
      font-weight: 700;
      color: var(--text-3);
      cursor: pointer;
      transition: all .18s cubic-bezier(.4,0,.2,1);
      letter-spacing: -.1px;
      font-family: 'Sora', sans-serif;
    }
    .filter-chip:hover {
      border-color: var(--blue);
      background: var(--blue-soft);
      color: var(--blue);
    }
    .filter-chip.active {
      border-color: var(--blue);
      background: var(--blue);
      color: #fff;
      box-shadow: 0 2px 8px rgba(29,78,216,.3);
    }
    .filter-chip.active-major {
      border-color: var(--red);
      background: var(--red);
      color: #fff;
      box-shadow: 0 2px 8px rgba(220,38,38,.3);
    }
    .filter-chip.active-minor {
      border-color: var(--amber);
      background: var(--amber);
      color: #fff;
      box-shadow: 0 2px 8px rgba(180,83,9,.3);
    }
    .filter-chip.active-major:hover { background: var(--red-h); border-color: var(--red-h); }
    .filter-chip.active-minor:hover { background: var(--amber-h); border-color: var(--amber-h); }

    /* ─── ESCALATION CARD (Major) ─── */
    .escalation-card {
      border: 1.5px solid #fca5a5;
      background: linear-gradient(135deg,#fef2f2 0%,#fff8f8 100%);
      margin-bottom: 16px;
    }
    .escalation-card .badge-major {
      background: var(--red-soft);
      color: var(--red);
      border-color: #fca5a5;
    }
    .escalation-minors-list {
      margin-top: 12px;
      display: flex;
      flex-direction: column;
      gap: 7px;
    }
    .escalation-minor-item {
      background: rgba(255,255,255,.75);
      border: 1px solid #fca5a5;
      border-radius: 9px;
      padding: 9px 12px;
      display: flex;
      align-items: flex-start;
      gap: 10px;
    }
    .escalation-minor-num {
      width: 22px; height: 22px;
      border-radius: 50%;
      background: var(--red-soft);
      border: 1px solid #fca5a5;
      color: var(--red);
      font-size: 10px;
      font-weight: 800;
      display: grid;
      place-items: center;
      flex-shrink: 0;
    }
    .escalation-minor-name {
      font-size: 12.5px;
      font-weight: 700;
      color: #7f1d1d;
      line-height: 1.3;
    }
    .escalation-minor-meta {
      display: flex;
      align-items: center;
      gap: 8px;
      margin-top: 4px;
      flex-wrap: wrap;
    }
    .escalation-minor-code {
      font-family: 'JetBrains Mono', monospace;
      font-size: 10px;
      color: var(--red);
      background: var(--red-soft);
      padding: 1px 7px;
      border-radius: 4px;
      border: 1px solid #fca5a5;
      font-weight: 600;
    }

    /* ─── OFFENSE LIST ─── */
    .offense-list { display: flex; flex-direction: column; gap: 10px; }

    .off-card {
      border: 1.5px solid var(--border);
      border-radius: var(--radius);
      padding: 16px 18px;
      background: var(--surface);
      transition: all .2s cubic-bezier(.4,0,.2,1);
      position: relative;
      overflow: hidden;
      animation: cardIn .3s cubic-bezier(.4,0,.2,1) both;
    }
    @keyframes cardIn {
      from { opacity: 0; transform: translateY(6px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .off-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0;
      width: 3px; height: 100%;
      border-radius: 3px 0 0 3px;
    }
    .off-card.major::before { background: linear-gradient(180deg, var(--red) 0%, #ef4444 100%); }
    .off-card.minor::before { background: linear-gradient(180deg, var(--amber) 0%, #f59e0b 100%); }
    .off-card:hover {
      border-color: var(--border-mid);
      box-shadow: var(--shadow);
      transform: translateY(-1px);
    }
    .off-card.major:hover { border-color: #fca5a5; }
    .off-card.minor:hover { border-color: var(--amber-mid); }

    .off-top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 10px;
    }
    .off-badges { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; }

    .badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 3px 9px;
      border-radius: 6px;
      font-size: 10.5px;
      font-weight: 700;
      letter-spacing: .2px;
    }
    .badge-dot {
      width: 5px; height: 5px;
      border-radius: 50%;
    }
    .badge-major  {
      background: var(--red-soft);
      color: var(--red);
      border: 1px solid #fca5a5;
    }
    .badge-major .badge-dot { background: var(--red); }
    .badge-minor  {
      background: var(--amber-soft);
      color: var(--amber);
      border: 1px solid var(--amber-mid);
    }
    .badge-minor .badge-dot { background: var(--amber); }
    .badge-category {
      background: var(--surface-2);
      color: var(--text-3);
      border: 1px solid var(--border);
    }
    .badge-open {
      background: var(--blue-soft);
      color: var(--blue);
      border: 1px solid var(--blue-mid);
    }
    .badge-resolved {
      background: var(--green-soft);
      color: var(--green);
      border: 1px solid var(--green-mid);
    }
    .badge-resolved .badge-dot { background: var(--green); }
    .badge-void {
      background: #f8fafc;
      color: var(--text-3);
      border: 1px solid var(--border);
      text-decoration: line-through;
    }

    .off-name {
      font-size: 14.5px;
      font-weight: 700;
      color: var(--text-1);
      letter-spacing: -.2px;
      line-height: 1.3;
    }
    .off-desc {
      font-size: 13px;
      color: var(--text-3);
      line-height: 1.55;
      margin-top: 6px;
    }

    .off-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-top: 12px;
      padding-top: 12px;
      border-top: 1px solid #f1f5f9;
      gap: 10px;
    }
    .off-code {
      font-family: 'JetBrains Mono', monospace;
      font-size: 11px;
      font-weight: 500;
      color: var(--text-4);
      background: var(--surface-2);
      padding: 3px 9px;
      border-radius: 5px;
      border: 1px solid var(--border);
      letter-spacing: .3px;
    }
    .off-date {
      font-size: 12px;
      color: var(--text-4);
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .off-date svg { width: 12px; height: 12px; }

    /* ─── EMPTY STATE ─── */
    .empty-state {
      text-align: center;
      padding: 56px 20px;
      color: var(--text-4);
    }
    .empty-state-icon {
      width: 56px; height: 56px;
      border-radius: 16px;
      background: var(--surface-2);
      border: 1px solid var(--border);
      display: grid;
      place-items: center;
      margin: 0 auto 16px;
      color: var(--text-4);
    }
    .empty-state-icon svg { width: 26px; height: 26px; }
    .empty-state h3 { font-size: 14px; font-weight: 700; color: var(--text-2); margin-bottom: 5px; }
    .empty-state p { font-size: 13px; font-weight: 500; color: var(--text-4); }

    /* ─── COUNT PILL ─── */
    .count-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      min-width: 22px;
      height: 22px;
      padding: 0 7px;
      border-radius: 6px;
      background: rgba(255,255,255,.22);
      border: 1px solid rgba(255,255,255,.35);
      font-size: 11px;
      font-weight: 700;
      color: inherit;
    }
    .filter-chip:not(.active) .count-pill {
      background: var(--surface-2);
      border-color: var(--border);
      color: var(--text-3);
    }
    button#filterMajor .count-pill {
      color: var(--red) !important;
      border-color: #fca5a5 !important;
      background: var(--red-soft) !important;
    }
    button#filterMinor:not(.active) .count-pill {
      color: var(--amber) !important;
      border-color: var(--amber-mid) !important;
      background: var(--amber-soft) !important;
    }

    /* ─── RESPONSIVE ─── */
    @media (max-width: 1100px) { .content-area { grid-template-columns: 1fr; } }
    @media (max-width: 1024px) { .admin-shell { grid-template-columns: 1fr; } }
    @media (max-width: 640px) {
      .content-area { padding: 16px; }
      .page-header { padding: 12px 16px; }
      .card-body { padding: 16px; }
    }
    @media (max-width: 760px) { .guard-review-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>

  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="main-wrap">

      <!-- PAGE HEADER -->
      <section class="page-header">
        <a class="back-btn" href="offenses.php">
          <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <polyline points="15 18 9 12 15 6"/>
          </svg>
          Back
        </a>
        <div class="header-sep"></div>
        <div class="header-breadcrumb">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
            <polyline points="9 22 9 12 15 12 15 22"/>
          </svg>
          <a href="offenses.php">Offenses</a>
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <polyline points="9 18 15 12 9 6"/>
          </svg>
          <span><?php echo e($studentName); ?></span>
        </div>
        <div class="header-admin">
          <div class="header-admin-dot"></div>
          <span>Welcome, <strong><?php echo e($fullName); ?></strong></span>
        </div>
      </section>

      <!-- CONTENT -->
      <div class="content-area">

        <!-- LEFT: OFFENSE HISTORY -->
        <div>
          <?php if ($guardReviewFlash !== ''): ?>
            <div class="guard-flash"><?php echo e($guardReviewFlash); ?></div>
          <?php endif; ?>

          <?php if ($scanFlash !== ''): ?>
            <div class="guard-flash"><?php echo e($scanFlash); ?></div>
          <?php endif; ?>

          <?php if (!empty($allGuardReports) && !$pendingGuardReport): ?>
            <section class="guard-review-panel" aria-label="All guard reports">
              <div class="guard-review-head">
                <h2 class="guard-review-title">All Guard Reports</h2>
                <span class="guard-review-badge"><?php echo count($allGuardReports); ?> total</span>
              </div>
              <div class="guard-review-sub">Guard submissions linked to student record (pending review only).</div>

              <div class="guard-report-list">
                <?php foreach ($allGuardReports as $gr): ?>
                  <?php
                    $statusKey = strtoupper((string)($gr['status'] ?? ''));
                    $statusClass = $statusKey === 'APPROVED' ? 'approved' : ($statusKey === 'REJECTED' ? 'rejected' : 'pending');
                    $isCurrent = $pendingReportId > 0 && (int)$gr['report_id'] === $pendingReportId;
                    $isMajor = strtoupper((string)$gr['offense_level']) === 'MAJOR';
                    $isPending = $statusKey === 'PENDING';
                  ?>
                  <<?php echo $isPending ? 'a href="offenses_student_view.php?student_id=' . urlencode($studentId) . '&pending_report_id=' . (int)$gr['report_id'] . '"' : 'div'; ?> 
                     class="guard-report-item <?php echo $isCurrent ? 'current' : ''; ?> <?php echo $isMajor ? 'major' : ''; ?> <?php echo $isPending ? 'clickable' : ''; ?>"
                     <?php echo $isPending ? 'style="text-decoration:none; color:inherit; display:block;"' : ''; ?>>
                    <div class="guard-report-top">
                      <div class="guard-report-title">#<?php echo (int)$gr['report_id']; ?> • <?php echo e((string)$gr['offense_code']); ?> - <?php echo e((string)$gr['offense_name']); ?></div>
                      <span class="guard-status <?php echo e($statusClass); ?>"><?php echo e($statusKey !== '' ? $statusKey : 'PENDING'); ?></span>
                    </div>
                    <div class="guard-report-meta">
                      <span><strong>Guard:</strong> <?php echo e((string)($gr['guard_name'] ?? 'Guard')); ?></span>
                      <span><strong>Committed:</strong> <?php echo e(date('M d, Y h:i A', strtotime((string)$gr['date_committed']))); ?></span>
                      <span><strong>Submitted:</strong> <?php echo e(date('M d, Y h:i A', strtotime((string)$gr['created_at']))); ?></span>
                      <?php if (!empty($gr['reviewed_at'])): ?>
                        <span><strong>Reviewed:</strong> <?php echo e(date('M d, Y h:i A', strtotime((string)$gr['reviewed_at']))); ?><?php if (!empty($gr['reviewer_name'])): ?> by <?php echo e((string)$gr['reviewer_name']); ?><?php endif; ?></span>
                      <?php endif; ?>
                    </div>
                    <?php if (!empty($gr['description'])): ?>
                      <div class="guard-review-sub" style="margin-top:6px;">"<?php echo e((string)$gr['description']); ?>"</div>
                    <?php endif; ?>
                  </<?php echo $isPending ? 'a' : 'div'; ?>>
                <?php endforeach; ?>
              </div>
            </section>
          <?php endif; ?>

          <?php if ($pendingGuardReport): ?>
            <?php $isPendingMajor = strtoupper((string)$pendingGuardReport['offense_level']) === 'MAJOR'; ?>
            <section class="guard-review-panel anim-pop <?php echo $isPendingMajor ? 'major' : ''; ?>" aria-label="Guard report review" style="animation: panelPopIn 0.5s cubic-bezier(0.34, 1.56, 0.64, 1) forwards;">
              <div class="guard-review-head">
                <h2 class="guard-review-title">Pending Guard Report Review</h2>
                <span class="guard-review-badge">Needs Decision</span>
              </div>
              <div class="guard-review-sub">Review this submission and decide whether to record it as an offense.</div>

              <div class="guard-review-grid">
                <div><strong>Offense:</strong> <?php echo e((string)$pendingGuardReport['offense_code']); ?> - <?php echo e((string)$pendingGuardReport['offense_name']); ?> (<?php echo e((string)$pendingGuardReport['offense_level']); ?>)</div>
                <div><strong>Guard:</strong> <?php echo e((string)($pendingGuardReport['guard_name'] ?? 'Guard')); ?></div>
                <div><strong>Date Committed:</strong> <?php echo e(date('M d, Y h:i A', strtotime((string)$pendingGuardReport['date_committed']))); ?></div>
                <div><strong>Submitted:</strong> <?php echo e(date('M d, Y h:i A', strtotime((string)$pendingGuardReport['created_at']))); ?></div>
              </div>

              <div class="guard-review-actions">

                <form method="post" style="margin:0;" id="guardApproveForm">
                  <input type="hidden" name="action" value="approve_guard_report" />
                  <input type="hidden" name="report_id" value="<?php echo (int)$pendingGuardReport['report_id']; ?>" />
                  <button type="button" class="guard-pill guard-pill-approve" id="guardApproveBtn">Approve and Record</button>
                </form>

                <form method="post" style="margin:0;" id="guardRejectForm">
                  <input type="hidden" name="action" value="reject_guard_report" />
                  <input type="hidden" name="report_id" value="<?php echo (int)$pendingGuardReport['report_id']; ?>" />
                  <button type="button" class="guard-pill guard-pill-reject" id="guardRejectBtn">Reject (Keep in History)</button>
                </form>
              </div>
            </section>
          <?php endif; ?>

          <div class="card">
            <div class="card-header">
              <div class="card-header__left">
                <div class="card-header__title">Offense History</div>
                <div class="card-header__sub">
                  <?php echo $totalOffenses; ?> record<?php echo $totalOffenses !== 1 ? 's' : ''; ?> found
                </div>
              </div>
              <div class="filter-bar">
                <button class="filter-chip active" id="filterAll" onclick="filterOffenses('all')">
                  All <span class="count-pill" style="margin-left:4px;"><?php echo $totalOffenses; ?></span>
                </button>
                <button class="filter-chip" id="filterMajor" onclick="filterOffenses('major')">
                  Major <span class="count-pill" style="margin-left:4px;"><?php echo $majorCount; ?></span>
                </button>
                <button class="filter-chip" id="filterMinor" onclick="filterOffenses('minor')">
                  Minor <span class="count-pill" style="margin-left:4px;"><?php echo $minorCount; ?></span>
                </button>
              </div>
            </div>

            <div class="card-body">
              <?php if (empty($history)): ?>
                <div class="empty-state">
                  <div class="empty-state-icon">
                    <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                      <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2M9 5a2 2 0 0 0 2 2h2a2 2 0 0 0 2-2M9 5a2 2 0 0 1 2-2h2a2 2 0 0 1 2 2"/>
                    </svg>
                  </div>
                  <h3>No Records Found</h3>
                  <p>This student has no offense records.</p>
                </div>
              <?php else: ?>
                <div class="offense-list" id="offenseList">
                  <!-- Display escalation cards (Major) for each group of 3 minors -->
                  <?php foreach ($escalationGroups as $groupIndex => $groupData): ?>
                    <?php
                      $group = $groupData['minors'];
                      $caseStatus = $groupData['case_status'];
                      $caseCategory = $groupData['case_category'];
                      
                      $isFirst = ($groupIndex === 0);
                      $title = $isFirst ? 'Section 4 Escalation' : 'Batch ' . ($groupIndex + 1) . ' Escalation';
                      $desc = $isFirst
                        ? 'This student has reached <strong>3 minor offenses</strong>, triggering a Section 4 escalation. The panel must investigate and assign a Category <strong>1-5</strong> before a final sanction can be issued.'
                        : 'This student has accumulated another batch of <strong>3 minor offenses</strong>. This also constitutes a major offense and should be reviewed by the panel.';
                        
                      if ($caseStatus === 'RESOLVED' || $caseStatus === 'CLOSED') {
                          $badgeLabel = 'Panel Decision Finalized';
                          $badgeBg = '#f0fdf4';
                          $badgeColor = '#15803d';
                          $badgeBorder = '#bbf7d0';
                          $footerText = 'Panel Decision Finalized';
                      } elseif ($caseStatus === 'UNDER_APPEAL') {
                          $badgeLabel = 'Appeal Window Active';
                          $badgeBg = '#eff6ff';
                          $badgeColor = '#1d4ed8';
                          $badgeBorder = '#bfdbfe';
                          $footerText = 'Awaiting Student Action / Appeal';
                      } elseif ($caseStatus === 'VOID' || $caseStatus === 'CANCELLED') {
                          $badgeLabel = 'Appeal Approved / Voided';
                          $badgeBg = '#f1f5f9';
                          $badgeColor = '#64748b';
                          $badgeBorder = '#cbd5e1';
                          $footerText = 'Penalty Voided';
                      } else {
                          $badgeLabel = 'Pending Panel Review';
                          $badgeBg = '#fef9f0';
                          $badgeColor = '#92400e';
                          $badgeBorder = '#fde68a';
                          $footerText = 'Awaiting Panel Decision';
                      }
                    ?>
                    <div class="off-card major escalation-card" data-level="major">
                      <div class="off-top">
                        <div class="off-badges">
                          <span class="badge badge-major"><span class="badge-dot"></span>Major</span>
                          <span class="badge" style="background:#fef2f2;color:var(--red);border-color:#fca5a5;font-weight:800;"><?php echo e($title); ?></span>
                          <?php if ($caseCategory): ?>
                            <span class="badge badge-category">Category <?php echo (int)$caseCategory; ?></span>
                          <?php endif; ?>
                        </div>
                        <span class="badge" style="background:<?php echo $badgeBg; ?>;color:<?php echo $badgeColor; ?>;border:1px solid <?php echo $badgeBorder; ?>;font-size:11px;font-weight:700;">
                          <?php echo $badgeLabel; ?>
                        </span>
                      </div>
                      <div class="off-name" style="color:var(--red);">Accumulated Minor Offenses - Escalated to Major</div>
                      <div class="off-desc" style="margin-bottom:14px;"><?php echo $desc; ?></div>

                      <div class="escalation-minors-list">
                        <div style="font-size:11px;font-weight:800;color:#b91c1c;text-transform:uppercase;letter-spacing:.6px;margin-bottom:2px;">
                          Contributing Minor Offenses
                        </div>
                        <?php foreach ($group as $subIdx => $minor): ?>
                        <div class="escalation-minor-item">
                          <div class="escalation-minor-num"><?php echo ($subIdx + 1); ?></div>
                          <div>
                            <div class="escalation-minor-name"><?php echo e((string)$minor['name']); ?></div>
                            <div class="escalation-minor-meta">
                              <?php if (!empty($minor['code'])): ?>
                                <span class="escalation-minor-code"><?php echo e((string)$minor['code']); ?></span>
                              <?php endif; ?>
                              <?php if (!empty($minor['description'])): ?>
                                <span style="font-size:11px;color:#991b1b;font-weight:500;font-style:italic;">
                                  "<?php echo e((string)$minor['description']); ?>"
                                </span>
                              <?php endif; ?>
                              <span style="font-size:11px;color:#b91c1c;font-weight:500;display:flex;align-items:center;gap:4px;">
                                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:11px;height:11px;">
                                  <rect x="3" y="4" width="18" height="18" rx="2"/>
                                  <line x1="16" y1="2" x2="16" y2="6"/>
                                  <line x1="8" y1="2" x2="8" y2="6"/>
                                  <line x1="3" y1="10" x2="21" y2="10"/>
                                </svg>
                                <?php echo date('M j, Y', strtotime((string)$minor['date_committed'])); ?>
                              </span>
                            </div>
                          </div>
                        </div>
                        <?php endforeach; ?>
                      </div>
                      
                      <?php if (in_array($caseStatus, ['RESOLVED', 'CLOSED', 'UNDER_APPEAL', 'CANCELLED', 'VOID']) && !empty($groupData['case_punishment'])): ?>
                        <?php 
                          $punish = json_decode($groupData['case_punishment'], true) ?? []; 
                          $decisionText = $groupData['case_decision'] ?: 'Panel consensus adopted.';
                        ?>
                        <div style="margin-top:16px; margin-bottom:16px; padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                          <div style="font-size:11px; font-weight:800; color:#334155; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;vertical-align:text-bottom;margin-right:4px;"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            UPCC Final Decision
                          </div>
                          <div style="font-size:13px; color:#0f172a; margin-bottom:8px;"><strong>Decision:</strong> <?php echo e($decisionText); ?></div>
                          
                          <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                            <?php if (!empty($punish['suspension_days'])): ?>
                              <div style="background:#fff; padding:8px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px;">
                                <strong style="color:#b91c1c;">Suspension:</strong> <?php echo (int)$punish['suspension_days']; ?> days
                              </div>
                            <?php endif; ?>
                            <?php if (!empty($punish['community_service_hours'])): ?>
                              <div style="background:#fff; padding:8px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px;">
                                <strong style="color:#0369a1;">Community Service:</strong> <?php echo (int)$punish['community_service_hours']; ?> hrs
                              </div>
                            <?php endif; ?>
                            <?php if (!empty($punish['counseling_sessions'])): ?>
                              <div style="background:#fff; padding:8px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px;">
                                <strong style="color:#047857;">Counseling:</strong> <?php echo (int)$punish['counseling_sessions']; ?> sessions
                              </div>
                            <?php endif; ?>
                          </div>
                          <?php if (!empty($punish['notes'])): ?>
                            <div style="margin-top:8px; font-size:12px; color:#475569; font-style:italic;">"<?php echo e((string)$punish['notes']); ?>"</div>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>

                      <div class="off-footer" style="border-top-color:#fca5a5;">
                        <span class="off-code" style="color:var(--red);border-color:#fca5a5;background:var(--red-soft);">
                          <?php echo $isFirst ? 'SEC-4' : 'BATCH-' . ($groupIndex + 1); ?> / 3 MINORS
                        </span>
                        <div class="off-date" style="color:<?php echo $caseStatus === 'RESOLVED' ? '#15803d' : 'var(--red)'; ?>;">
                          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                            <line x1="12" y1="9" x2="12" y2="13"/>
                            <line x1="12" y1="17" x2="12.01" y2="17"/>
                          </svg>
                          <?php echo $footerText; ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>

                  <!-- Display ALL minor offenses as individual minor cards (even those in groups) -->
                  <?php foreach ($allMinors as $minor): ?>
                    <div class="off-card minor" data-level="minor">
                      <div class="off-top">
                        <div class="off-badges">
                          <span class="badge badge-minor"><span class="badge-dot"></span>Minor</span>
                        </div>
                        <span class="badge badge-open">Open</span>
                      </div>
                      <div class="off-name"><?php echo e((string)$minor['name']); ?></div>
                      <?php if (!empty($minor['description'])): ?>
                        <div class="off-desc"><?php echo e((string)$minor['description']); ?></div>
                      <?php endif; ?>
                      <div class="off-footer">
                        <?php if (!empty($minor['code'])): ?>
                          <span class="off-code"><?php echo e((string)$minor['code']); ?></span>
                        <?php else: ?>
                          <span></span>
                        <?php endif; ?>
                        <div class="off-date">
                          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                          </svg>
                          <?php echo date('M j, Y', strtotime((string)$minor['date_committed'])); ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>

                  <!-- Display explicit major offenses (not from escalation) -->
                  <?php foreach ($allMajors as $h): ?>
                      <?php 
                        $ucStatus = strtoupper((string)($h['uc_status'] ?? ''));
                        $statusBadge = match($ucStatus) { 'RESOLVED' => 'badge-resolved', 'CLOSED' => 'badge-resolved', 'VOID' => 'badge-void', 'CANCELLED' => 'badge-void', 'UNDER_APPEAL' => 'badge-resolved', default => 'badge-open' };
                        $statusLabel = match($ucStatus) { 'RESOLVED' => 'Finalized', 'CLOSED' => 'Finalized', 'VOID' => 'Voided', 'CANCELLED' => 'Voided', 'UNDER_APPEAL' => 'Under Appeal', default => 'Open' };
                        $catLabel = !empty($h['uc_category']) ? 'Category ' . (int)$h['uc_category'] : '';
                      ?>
                    <div class="off-card major" data-level="major">
                      <div class="off-top">
                        <div class="off-badges">
                          <span class="badge badge-major"><span class="badge-dot"></span>Major</span>
                          <?php if ($catLabel !== ''): ?>
                            <span class="badge badge-category"><?php echo e($catLabel); ?></span>
                          <?php endif; ?>
                        </div>
                        <span class="badge <?php echo $statusBadge; ?>"><?php echo e($statusLabel); ?></span>
                      </div>
                      <div class="off-name"><?php echo e((string)$h['name']); ?></div>
                      <?php if (!empty($h['description'])): ?>
                        <div class="off-desc"><?php echo e((string)$h['description']); ?></div>
                      <?php endif; ?>
                      
                      <?php 
                        if (in_array($ucStatus, ['RESOLVED', 'CLOSED', 'UNDER_APPEAL', 'CANCELLED', 'VOID']) && !empty($h['uc_punishment'])): 
                          $punish = json_decode($h['uc_punishment'], true) ?? []; 
                          $decisionText = $h['uc_decision'] ?: 'Panel consensus adopted.';
                      ?>
                        <div style="margin-top:16px; margin-bottom:16px; padding:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px;">
                          <div style="font-size:11px; font-weight:800; color:#334155; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:8px;">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;vertical-align:text-bottom;margin-right:4px;"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                            UPCC Final Decision
                          </div>
                          <div style="font-size:13px; color:#0f172a; margin-bottom:8px;"><strong>Decision:</strong> <?php echo e($decisionText); ?></div>
                          
                          <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                            <?php if (!empty($punish['suspension_days'])): ?>
                              <div style="background:#fff; padding:8px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px;">
                                <strong style="color:#b91c1c;">Suspension:</strong> <?php echo (int)$punish['suspension_days']; ?> days
                              </div>
                            <?php endif; ?>
                            <?php if (!empty($punish['community_service_hours'])): ?>
                              <div style="background:#fff; padding:8px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px;">
                                <strong style="color:#0369a1;">Community Service:</strong> <?php echo (int)$punish['community_service_hours']; ?> hrs
                              </div>
                            <?php endif; ?>
                            <?php if (!empty($punish['counseling_sessions'])): ?>
                              <div style="background:#fff; padding:8px; border-radius:6px; border:1px solid #cbd5e1; font-size:12px;">
                                <strong style="color:#047857;">Counseling:</strong> <?php echo (int)$punish['counseling_sessions']; ?> sessions
                              </div>
                            <?php endif; ?>
                          </div>
                          <?php if (!empty($punish['notes'])): ?>
                            <div style="margin-top:8px; font-size:12px; color:#475569; font-style:italic;">"<?php echo e((string)$punish['notes']); ?>"</div>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>

                      <div class="off-footer">
                        <?php if (!empty($h['code'])): ?>
                          <span class="off-code"><?php echo e((string)$h['code']); ?></span>
                        <?php else: ?>
                          <span></span>
                        <?php endif; ?>
                        <div class="off-date">
                          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                            <line x1="16" y1="2" x2="16" y2="6"/>
                            <line x1="8" y1="2" x2="8" y2="6"/>
                            <line x1="3" y1="10" x2="21" y2="10"/>
                          </svg>
                          <?php echo date('M j, Y', strtotime((string)$h['date_committed'])); ?>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>

                <!-- Filter empty state -->
                <div class="empty-state" id="filterEmpty" style="display:none;">
                  <div class="empty-state-icon">
                    <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                      <path d="M12 9v4"/><path d="M12 17h.01"/>
                      <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0Z"/>
                    </svg>
                  </div>
                  <h3 id="filterEmptyTitle">No Offenses Found</h3>
                  <p id="filterEmptyText">No offenses match this filter.</p>
                </div>
              <?php endif; ?>
            </div><!-- /.card-body -->
          </div><!-- /.card -->
        </div>

        <!-- RIGHT: STUDENT PROFILE -->
        <aside>
          <div class="card">
            <div class="profile-hero">
              <div class="avatar-wrap">
                <div class="avatar-ring">
                  <div class="avatar-inner"><?php echo e($avatar); ?></div>
                </div>
              </div>
              <div class="profile-name"><?php echo e($studentName); ?></div>
              <div class="profile-id"><?php echo e((string)$student['student_id']); ?></div>
              <div class="profile-year">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:11px;height:11px;">
                  <path d="M22 10v6M2 10l10-5 10 5-10 5z"/>
                </svg>
                <?php echo (int)$student['year_level'] . $suffix; ?> Year
              </div>
            </div>

            <div class="stats-row">
              <div class="stat-cell">
                <div class="stat-val total"><?php echo $totalOffenses; ?></div>
                <div class="stat-lbl">Total</div>
              </div>
              <div class="stat-cell">
                <div class="stat-val major"><?php echo $majorCount; ?></div>
                <div class="stat-lbl">Major</div>
              </div>
              <div class="stat-cell">
                <div class="stat-val minor"><?php echo $minorCount; ?></div>
                <div class="stat-lbl">Minor</div>
              </div>
            </div>

            <div class="info-list">
              <?php if (!empty($student['school'])): ?>
              <div class="info-row">
                <div class="info-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg></div>
                <div><div class="info-lbl">School</div><div class="info-val"><?php echo e((string)$student['school']); ?></div></div>
              </div>
              <?php endif; ?>
              <?php if (!empty($student['program'])): ?>
              <div class="info-row">
                <div class="info-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 10v6M2 10l10-5 10 5-10 5z"/><path d="M6 12v5c3 3 9 3 12 0v-5"/></svg></div>
                <div><div class="info-lbl">Program</div><div class="info-val"><?php echo e((string)$student['program']); ?></div></div>
              </div>
              <?php endif; ?>
              <?php if (!empty($student['section'])): ?>
              <div class="info-row">
                <div class="info-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2"/><path d="M3 9h18M9 21V9"/></svg></div>
                <div><div class="info-lbl">Section</div><div class="info-val"><?php echo e((string)$student['section']); ?></div></div>
              </div>
              <?php endif; ?>
              <?php if (!empty($student['student_email'])): ?>
              <div class="info-row">
                <div class="info-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg></div>
                <div><div class="info-lbl">Email</div><div class="info-val" style="font-family:'JetBrains Mono',monospace;font-size:11.5px;"><?php echo e((string)$student['student_email']); ?></div></div>
              </div>
              <?php endif; ?>
            </div>

            <div class="add-btn-wrap">
              <a class="add-btn" href="offense_new.php?student_id=<?php echo urlencode((string)$student['student_id']); ?>">
                <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add New Offense
              </a>
            </div>
          </div>
        </aside>
      </div><!-- /.content-area -->
    </main>
  </div><!-- /.admin-shell -->

  <div id="guardRejectConfirm" class="guard-confirm-overlay" aria-hidden="true">
    <div class="guard-confirm-box" role="dialog" aria-modal="true" aria-labelledby="guardRejectTitle">
      <h4 id="guardRejectTitle" class="guard-confirm-title">Confirm Reject</h4>
      <p class="guard-confirm-text">Reject this report? It will be marked as rejected and kept in guard-report history, but removed from pending review queues.</p>
      <div class="guard-confirm-actions">
        <button id="guardRejectCancel" type="button" class="guard-pill guard-pill-view">Cancel</button>
        <button id="guardRejectConfirmBtn" type="button" class="guard-pill guard-pill-reject">Yes, Reject</button>
      </div>
    </div>
  </div>

  <div id="guardApproveConfirm" class="guard-confirm-overlay" aria-hidden="true">
    <div class="guard-confirm-box" role="dialog" aria-modal="true" aria-labelledby="guardApproveTitle">
      <h4 id="guardApproveTitle" class="guard-confirm-title" style="color:#145d35;">Confirm Approve</h4>
      <p class="guard-confirm-text">Approve this report and record it in student offenses?</p>
      <div class="guard-confirm-actions">
        <button id="guardApproveCancel" type="button" class="guard-pill guard-pill-view">Cancel</button>
        <button id="guardApproveConfirmBtn" type="button" class="guard-pill guard-pill-approve">Yes, Approve</button>
      </div>
    </div>
  </div>

  <script>
  (function () {
    const filterMap = { all: 'filterAll', major: 'filterMajor', minor: 'filterMinor' };

    function filterOffenses(type) {
      const list        = document.getElementById('offenseList');
      const emptyWrap   = document.getElementById('filterEmpty');
      const emptyTitle  = document.getElementById('filterEmptyTitle');
      const emptyText   = document.getElementById('filterEmptyText');

      let shown = 0;
      if (list) {
        list.querySelectorAll('.off-card').forEach(card => {
          const visible = type === 'all' || card.dataset.level === type;
          card.style.display = visible ? '' : 'none';
          if (visible) shown++;
        });
      }

      // Update chip styles
      document.querySelectorAll('.filter-chip').forEach(b => {
        b.classList.remove('active', 'active-major', 'active-minor');
      });
      const activeBtn = document.getElementById(filterMap[type]);
      if (activeBtn) {
        activeBtn.classList.add('active');
        if (type === 'major') activeBtn.classList.add('active-major');
        if (type === 'minor') activeBtn.classList.add('active-minor');
      }

      // Empty state
      if (emptyWrap) {
        if (shown === 0) {
          emptyWrap.style.display = '';
          if (type === 'minor') {
            emptyTitle.textContent = 'No Minor Offenses';
            emptyText.textContent = 'This student has no minor offense records.';
          } else if (type === 'major') {
            emptyTitle.textContent = 'No Major Offenses';
            emptyText.textContent = 'This student has no major offense records.';
          } else {
            emptyTitle.textContent = 'No Records Found';
            emptyText.textContent = 'No offense records available.';
          }
        } else {
          emptyWrap.style.display = 'none';
        }
      }
    }

    window.filterOffenses = filterOffenses;

    const rejectForm = document.getElementById('guardRejectForm');
    const rejectBtn = document.getElementById('guardRejectBtn');
    const rejectConfirm = document.getElementById('guardRejectConfirm');
    const rejectCancel = document.getElementById('guardRejectCancel');
    const rejectConfirmBtn = document.getElementById('guardRejectConfirmBtn');
    const approveForm = document.getElementById('guardApproveForm');
    const approveBtn = document.getElementById('guardApproveBtn');
    const approveConfirm = document.getElementById('guardApproveConfirm');
    const approveCancel = document.getElementById('guardApproveCancel');
    const approveConfirmBtn = document.getElementById('guardApproveConfirmBtn');

    function openRejectConfirm() {
      if (!rejectConfirm) return;
      rejectConfirm.classList.add('show');
      rejectConfirm.setAttribute('aria-hidden', 'false');
      if (rejectConfirmBtn) rejectConfirmBtn.focus();
    }

    function closeRejectConfirm() {
      if (!rejectConfirm) return;
      rejectConfirm.classList.remove('show');
      rejectConfirm.setAttribute('aria-hidden', 'true');
      if (rejectBtn) rejectBtn.focus();
    }

    function openApproveConfirm() {
      if (!approveConfirm) return;
      approveConfirm.classList.add('show');
      approveConfirm.setAttribute('aria-hidden', 'false');
      if (approveConfirmBtn) approveConfirmBtn.focus();
    }

    function closeApproveConfirm() {
      if (!approveConfirm) return;
      approveConfirm.classList.remove('show');
      approveConfirm.setAttribute('aria-hidden', 'true');
      if (approveBtn) approveBtn.focus();
    }

    if (approveBtn && approveForm) {
      approveBtn.addEventListener('click', openApproveConfirm);
    }
    if (approveCancel) {
      approveCancel.addEventListener('click', closeApproveConfirm);
    }
    if (approveConfirmBtn && approveForm) {
      approveConfirmBtn.addEventListener('click', function () {
        approveForm.submit();
      });
    }
    if (approveConfirm) {
      approveConfirm.addEventListener('click', function (ev) {
        if (ev.target === approveConfirm) closeApproveConfirm();
      });
    }

    if (rejectBtn && rejectForm) {
      rejectBtn.addEventListener('click', openRejectConfirm);
    }
    if (rejectCancel) {
      rejectCancel.addEventListener('click', closeRejectConfirm);
    }
    if (rejectConfirmBtn && rejectForm) {
      rejectConfirmBtn.addEventListener('click', function () {
        rejectForm.submit();
      });
    }
    if (rejectConfirm) {
      rejectConfirm.addEventListener('click', function (ev) {
        if (ev.target === rejectConfirm) closeRejectConfirm();
      });
    }

    document.addEventListener('keydown', function (ev) {
      if (approveConfirm && approveConfirm.classList.contains('show')) {
        if (ev.key === 'Escape') {
          ev.preventDefault();
          closeApproveConfirm();
          return;
        }
        if (ev.key === 'Enter' && !ev.ctrlKey && !ev.altKey && !ev.metaKey && !ev.shiftKey) {
          ev.preventDefault();
          if (approveConfirmBtn) approveConfirmBtn.click();
          return;
        }
      }

      if (!rejectConfirm || !rejectConfirm.classList.contains('show')) return;
      if (ev.key === 'Escape') {
        ev.preventDefault();
        closeRejectConfirm();
        return;
      }
      if (ev.key === 'Enter' && !ev.ctrlKey && !ev.altKey && !ev.metaKey && !ev.shiftKey) {
        ev.preventDefault();
        if (rejectConfirmBtn) rejectConfirmBtn.click();
      }
    });
  })();
  </script>
</body>
</html>

