<?php
require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../class.phpmailer.php';
require_once __DIR__ . '/../class.smtp.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['ok' => false, 'message' => 'Invalid request method.']);
  exit;
}

$admin = admin_current();
$adminId = (int)($admin['admin_id'] ?? $admin['id'] ?? 0);
$action = trim((string)($_POST['action'] ?? ''));
$reportId = (int)($_POST['report_id'] ?? 0);

function send_guardian_notice(string $studentId, string $subject, string $letterBody) {
    $studentRow = db_one(
      "SELECT s.student_fn, s.student_ln, g.guardian_email, g.guardian_fn, g.guardian_ln
       FROM student s
       LEFT JOIN guardian g ON s.student_id = g.student_id
       WHERE s.student_id = :sid LIMIT 1",
      [':sid' => $studentId]
    );

    $guardianEmail = trim((string)($studentRow['guardian_email'] ?? ''));
    if ($guardianEmail === '') return;

    $guardianName = trim((string)($studentRow['guardian_fn'] ?? '') . ' ' . (string)($studentRow['guardian_ln'] ?? ''));
    if ($guardianName === '') $guardianName = 'Parent/Guardian';
    
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
        $mail->Subject = $subject;
        
        $safeBody = nl2br(htmlspecialchars($letterBody, ENT_QUOTES, 'UTF-8'));
        
        $mail->Body = "
          <div style='font-family:Segoe UI,Tahoma,Arial,sans-serif;'>
            <p>Good day,</p>
            <hr style='border:none;border-top:1px solid #e5e7eb;margin:14px 0;' />
            <div style='color:#374151;font-size:14px;line-height:1.6;'>{$safeBody}</div>
            <p style='margin-top:18px;color:#6b7280;font-size:12px;'>This is an automated message from IdentiTrack SDO Web Portal.</p>
          </div>
        ";
        
        $mail->AltBody = $subject . "\n\n" . $letterBody;
        $mail->send();
    } catch (Exception $e) {
        error_log('Guard report mail error: ' . $e->getMessage());
    }
}

if ($reportId <= 0 || ($action !== 'approve_guard_report' && $action !== 'reject_guard_report')) {
  echo json_encode(['ok' => false, 'message' => 'Invalid review request.']);
  exit;
}

$report = db_one(
  "SELECT report_id, student_id, offense_type_id, date_committed, description, status
   FROM guard_violation_report
   WHERE report_id = :rid AND is_deleted = 0
   LIMIT 1",
  [':rid' => $reportId]
);

if (!$report || strtoupper((string)$report['status']) !== 'PENDING') {
  echo json_encode(['ok' => false, 'message' => 'Report is no longer pending.']);
  exit;
}

$escalationMsg = null;
if ($action === 'approve_guard_report') {
  $offenseType = db_one(
    "SELECT level, major_category FROM offense_type WHERE offense_type_id = :oid LIMIT 1",
    [':oid' => (int)$report['offense_type_id']]
  );

  if (!$offenseType) {
    echo json_encode(['ok' => false, 'message' => 'Offense type is missing.']);
    exit;
  }

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

      // Send email to guardian
      $studentRow = db_one("SELECT student_fn, student_ln FROM student WHERE student_id = :sid", [':sid' => $studentId]);
      $studentName = trim(($studentRow['student_fn'] ?? '') . ' ' . ($studentRow['student_ln'] ?? ''));
      $letterBody = "Please be advised that $studentName has been reported for a Major Offense. This case is now an active case under UPCC investigation and a hearing will be required.";
      send_guardian_notice($studentId, 'Major Offense Notice - UPCC Investigation Required', $letterBody);
  } elseif ($level === 'MINOR') {
      $afterRow = db_one(
        "SELECT COUNT(*) AS cnt FROM offense WHERE student_id = :sid AND level = 'MINOR'",
        [':sid' => $studentId]
      );
      $afterMinor = (int)($afterRow['cnt'] ?? 0);

      $escalationType = null;
      $defaultSubject = '';
      $defaultBody = '';
      $guardianEmail = '';

      $studentRow = db_one("SELECT student_fn, student_ln FROM student WHERE student_id = :sid", [':sid' => $studentId]);
      $studentName = trim(($studentRow['student_fn'] ?? '') . ' ' . ($studentRow['student_ln'] ?? ''));

      $guardianRow = db_one("SELECT guardian_email FROM guardian WHERE student_id = :sid LIMIT 1", [':sid' => $studentId]);
      $guardianEmail = trim($guardianRow['guardian_email'] ?? '');

      if ($afterMinor === 2) {
          $escalationMsg = "This is the student's 2nd Minor Offense. Please review and send the warning email to their guardian.";
          $escalationType = 'letter';
          $defaultSubject = 'Student Conduct Notice — 2nd Minor Offense Warning';
          
          $defaultBody = "Dear Guardian,\n\nThis is to inform you that your student has been reported for a conduct offense and an investigation is underway. Please see the detailed notice below for more information.\n\n";
          $coff = db_one("SELECT o.description, o.date_committed, ot.code, ot.name, ot.level FROM offense o JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id WHERE o.offense_id = :oid", [':oid' => $newOffenseId]);
          if ($coff) {
              $dt = date('F j, Y g:i A', strtotime($coff['date_committed']));
              $defaultBody .= "CURRENT OFFENSE:\n- {$coff['code']} — {$coff['name']}\n- Level: {$coff['level']}\n- Date: {$dt}\n- Notes: " . ($coff['description'] ?: '(none)') . "\n\n";
          }
          $history = db_all("SELECT o.date_committed, o.description, ot.level, ot.code, ot.name FROM offense o JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id WHERE o.student_id = :sid ORDER BY o.date_committed DESC, o.offense_id DESC LIMIT 30", [':sid' => $studentId]);
          $defaultBody .= "OFFENSE HISTORY (Most recent first):\n";
          if (empty($history)) {
              $defaultBody .= "(No offenses found.)\n";
          } else {
              foreach ($history as $i => $h) {
                  $dt = date('M j, Y g:i A', strtotime($h['date_committed']));
                  $defaultBody .= ($i + 1) . ". [{$h['level']}] {$h['code']} — {$h['name']} ({$dt})\n";
                  if (trim($h['description']) !== '') $defaultBody .= "   Notes: " . trim($h['description']) . "\n";
              }
          }
          $defaultBody .= "\n\nPlease be reminded that a 3rd minor offense will automatically escalate to a Major Offense and trigger a UPCC Investigation.\n\nWe encourage you to support your student in maintaining proper conduct within our institution.\n\nSincerely,\nStudent Discipline Office";
      }

      $existingSection4Case = db_one(
        "SELECT case_id FROM upcc_case
         WHERE student_id = :sid
           AND status IN ('PENDING','UNDER_APPEAL')
           AND case_kind = 'SECTION4_MINOR_ESCALATION'
         LIMIT 1",
        [':sid' => $studentId]
      );

      if (!$existingSection4Case && $afterMinor >= 3) {
        db_exec(
          "INSERT INTO upcc_case (student_id, created_by, status, case_kind, case_summary, created_at, updated_at)
           VALUES (:sid, :aid, 'PENDING', 'SECTION4_MINOR_ESCALATION', :summary, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
          [
            ':sid'     => $studentId,
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
          [':sid' => $studentId]
        );
        foreach ($triggerMinors as $minor) {
          db_exec(
            "INSERT INTO upcc_case_offense (case_id, offense_id) VALUES (:case_id, :offense_id)",
            [':case_id' => $caseId, ':offense_id' => (int)$minor['offense_id']]
          );
        }

        $escalationMsg = "This is the student's 3rd Minor Offense. It has been escalated to a Major Offense, a UPCC case has been generated. Please review and send the notification to the guardian.";
        $escalationType = 'escalation';
        $defaultSubject = 'Student Conduct Notice — 3rd Minor Offense Escalation';
        
        $defaultBody = "Dear Guardian,\n\nThis is to inform you that your student has been reported for a conduct offense and an investigation is underway. Please see the detailed notice below for more information.\n\n";
        $coff = db_one("SELECT o.description, o.date_committed, ot.code, ot.name, ot.level FROM offense o JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id WHERE o.offense_id = :oid", [':oid' => $newOffenseId]);
        if ($coff) {
            $dt = date('F j, Y g:i A', strtotime($coff['date_committed']));
            $defaultBody .= "CURRENT OFFENSE:\n- {$coff['code']} — {$coff['name']}\n- Level: {$coff['level']}\n- Date: {$dt}\n- Notes: " . ($coff['description'] ?: '(none)') . "\n\n";
        }
        $history = db_all("SELECT o.date_committed, o.description, ot.level, ot.code, ot.name FROM offense o JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id WHERE o.student_id = :sid ORDER BY o.date_committed DESC, o.offense_id DESC LIMIT 30", [':sid' => $studentId]);
        $defaultBody .= "OFFENSE HISTORY (Most recent first):\n";
        if (empty($history)) {
            $defaultBody .= "(No offenses found.)\n";
        } else {
            foreach ($history as $i => $h) {
                $dt = date('M j, Y g:i A', strtotime($h['date_committed']));
                $defaultBody .= ($i + 1) . ". [{$h['level']}] {$h['code']} — {$h['name']} ({$dt})\n";
                if (trim($h['description']) !== '') $defaultBody .= "   Notes: " . trim($h['description']) . "\n";
            }
        }
        $defaultBody .= "\n\nPer university policy, this has escalated to a Major Offense (Section 4).\n\nThis case is now an active case under UPCC investigation and a hearing will be required.\n\nWe encourage you to support your student in maintaining proper conduct within our institution.\n\nSincerely,\nStudent Discipline Office";
      }
  }

  db_exec(
    "UPDATE guard_violation_report
     SET status = 'APPROVED', reviewed_by = :admin, reviewed_at = NOW(), review_notes = :note
     WHERE report_id = :rid",
    [':admin' => $adminId, ':note' => 'Approved by admin via dashboard modal.', ':rid' => $reportId]
  );

  db_exec(
    "UPDATE notification
     SET is_read = 1
     WHERE type = 'GUARD_REPORT'
       AND related_table = 'guard_violation_report'
       AND related_id = :rid",
    [':rid' => $reportId]
  );

  echo json_encode([
      'ok' => true, 
      'message' => 'Report approved and recorded in offenses.',
      'escalation_msg' => $escalationMsg,
      'escalation_type' => $escalationType ?? null,
      'offense_id' => $newOffenseId,
      'guardian_email' => $guardianEmail ?? '',
      'default_subject' => $defaultSubject ?? '',
      'default_body' => $defaultBody ?? ''
  ]);
  exit;
}

// Reject only marks as rejected; it does not create offense and does not soft-delete report.
db_exec(
  "UPDATE guard_violation_report
   SET status = 'REJECTED', reviewed_by = :admin, reviewed_at = NOW(), review_notes = :note
   WHERE report_id = :rid",
  [':admin' => $adminId, ':note' => 'Rejected by admin via dashboard modal.', ':rid' => $reportId]
);

db_exec(
  "UPDATE notification
   SET is_read = 1
   WHERE type = 'GUARD_REPORT'
     AND related_table = 'guard_violation_report'
     AND related_id = :rid",
  [':rid' => $reportId]
);

echo json_encode(['ok' => true, 'message' => 'Report rejected. No offense record created.']);
exit;
