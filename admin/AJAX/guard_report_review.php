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
    $params = [':sid' => $studentId];
    db_add_encryption_key($params);
    $studentRow = db_one(
      "SELECT " . db_decrypt_cols(['student_fn', 'student_ln'], 's') . ", g.guardian_email, g.guardian_fn, g.guardian_ln
       FROM student s
       LEFT JOIN guardian g ON s.student_id = g.student_id
       WHERE s.student_id = :sid LIMIT 1",
      $params
    );

    $guardianEmail = trim((string)($studentRow['guardian_email'] ?? ''));
    if ($guardianEmail === '') return;

    $guardianName = trim((string)($studentRow['guardian_fn'] ?? '') . ' ' . (string)($studentRow['guardian_ln'] ?? ''));
    if ($guardianName === '') $guardianName = 'Parent/Guardian';
    
    $mail = new PHPMailer(true);
    try {
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.hostinger.com';
        $mail->Port = 587;
        $mail->SMTPAuth = true;
        $mail->SMTPSecure = 'tls';
        $mail->Username = $_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site';
        $mail->Password = $_ENV['SMTP_PASS'] ?? '';
        
        $mail->setFrom($mail->Username, 'IdentiTrack Admin');
        $mail->addAddress($guardianEmail, $guardianName);
        $mail->addReplyTo('no-reply@identitrack.local', 'IdentiTrack');
        
        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        $mail->Body = "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
          <meta charset='UTF-8'>
          <style>
            body { margin: 0; padding: 0; background-color: #f1f5f9; }
            .wrapper { width: 100%; table-layout: fixed; background-color: #f1f5f9; padding: 40px 0; }
            .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.08); font-family: 'Inter', -apple-system, sans-serif; }
            .header { background-image: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%); padding: 50px 40px; text-align: center; }
            .logo-img { display: block; width: 85px; height: auto; margin: 0 auto 20px auto; border-radius: 18px; box-shadow: 0 8px 16px rgba(0,0,0,0.15); }
            .content { padding: 40px 50px; color: #374151; font-size: 15px; line-height: 1.6; }
            h1 { color: #ffffff; margin: 0; font-size: 26px; font-weight: 800; letter-spacing: -0.5px; }
            .badge { display: inline-block; padding: 6px 14px; background-color: rgba(255,255,255,0.15); color: #ffffff; font-size: 12px; font-weight: 600; border-radius: 100px; margin-bottom: 20px; text-transform: uppercase; letter-spacing: 1px; }
            .footer { padding: 30px; text-align: center; background-color: #f8fafc; border-top: 1px solid #f1f5f9; font-size: 13px; color: #94a3b8; }
          </style>
        </head>
        <body>
          <div class='wrapper'>
            <div class='email-container'>
              <div class='header'>
                <div class='badge'>Official Notice</div>
                <img src='https://identitrack.site/assets/logo.png' alt='IdentiTrack' class='logo-img'>
                <h1>Student Discipline Office</h1>
              </div>
              <div class='content'>
                <p style='font-weight:600;font-size:16px;color:#1e293b;margin-top:0;'>Dear Parent/Guardian,</p>
                <p>Please review the attached official notice letter regarding the disciplinary record of <strong>{$studentName}</strong>.</p>
                <p style='margin-top:24px;margin-bottom:0;'>If you have any questions, please coordinate with the Student Discipline Office or the University Panel on Community Conduct.</p>
              </div>
              <div class='footer'>
                &copy; " . date('Y') . " IdentiTrack System. All rights reserved.<br>This is an automated notification. Please do not reply.
              </div>
            </div>
          </div>
        </body>
        </html>
        ";
        
        $mail->AltBody = "Dear Parent/Guardian,\n\nPlease review the attached official notice letter regarding the disciplinary record of {$studentName}.\n\nStudent Discipline Office";
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

  $insParams = [
    ':sid' => $studentId,
    ':admin' => $adminId,
    ':tid' => (int)$report['offense_type_id'],
    ':lvl' => $level,
    ':descr' => trim((string)($report['description'] ?? '')) === '' ? null : $report['description'],
    ':dt' => (string)$report['date_committed'],
  ];
  db_add_encryption_key($insParams);

  db_exec(
    "INSERT INTO offense (student_id, recorded_by, offense_type_id, level, description, date_committed, status, created_at, updated_at)
     VALUES (:sid, :admin, :tid, :lvl, " . db_encrypt_col('description', ':descr') . ", :dt, 'OPEN', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
    $insParams
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

      // Setup escalation for the dashboard modal
      $escalationMsg = "This is a Major Offense. Please review and send the notice to the guardian.";
      $escalationType = 'major';
      $defaultSubject = 'Major Offense Notice - UPCC Investigation Required';
      
      $sParams = [':sid' => $studentId];
      db_add_encryption_key($sParams);
      $studentRow = db_one("SELECT " . db_decrypt_cols(['student_fn', 'student_ln']) . " FROM student WHERE student_id = :sid", $sParams);
      $studentName = trim(($studentRow['student_fn'] ?? '') . ' ' . ($studentRow['student_ln'] ?? ''));
      $defaultBody = "Dear Guardian,\n\nThis is an urgent official notification from the Student Discipline Office. We are writing to formally inform you that your student, $studentName, has been involved in a MAJOR disciplinary offense.\n\nDue to the severity of this infraction, an immediate investigation is currently underway by the University Panel on Community Conduct (UPCC). Such offenses carry significant consequences, which may include suspension or expulsion. We strongly advise that you discuss this matter with your student immediately.\n\nPlease review the official conduct record for full details.\n\nSincerely,\nStudent Discipline Office";
      
      $guardianRow = db_one("SELECT guardian_email FROM guardian WHERE student_id = :sid LIMIT 1", [':sid' => $studentId]);
      $guardianEmail = trim($guardianRow['guardian_email'] ?? '');
      
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

      $sParams = [':sid' => $studentId];
      db_add_encryption_key($sParams);
      $studentRow = db_one("SELECT " . db_decrypt_cols(['student_fn', 'student_ln']) . " FROM student WHERE student_id = :sid", $sParams);
      $studentName = trim(($studentRow['student_fn'] ?? '') . ' ' . ($studentRow['student_ln'] ?? ''));

      $guardianRow = db_one("SELECT guardian_email FROM guardian WHERE student_id = :sid LIMIT 1", [':sid' => $studentId]);
      $guardianEmail = trim($guardianRow['guardian_email'] ?? '');

      if ($afterMinor === 2) {
          $escalationMsg = "This is the student's 2nd Minor Offense. Please review and send the warning email to their guardian.";
          $escalationType = 'letter';
          $defaultSubject = 'Student Conduct Notice — 2nd Minor Offense Warning';
          
          $defaultBody = "Dear Guardian,\n\nThis is to inform you that your student has been reported for a second minor conduct offense. Please see the detailed notice below for more information regarding this incident.\n\n";
          $coffParams = [':oid' => $newOffenseId];
          db_add_encryption_key($coffParams);
          $coff = db_one("SELECT " . db_decrypt_col('description', 'o') . " AS description, o.date_committed, ot.code, ot.name, ot.level FROM offense o JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id WHERE o.offense_id = :oid", $coffParams);
          if ($coff) {
              $dt = date('F j, Y g:i A', strtotime($coff['date_committed']));
              $defaultBody .= "CURRENT OFFENSE:\n- {$coff['code']} — {$coff['name']}\n- Level: {$coff['level']}\n- Date: {$dt}\n- Notes: " . ($coff['description'] ?: '(none)') . "\n\n";
          }
          $histParams = [':sid' => $studentId, ':oid' => $newOffenseId];
          db_add_encryption_key($histParams);
          $history = db_all("SELECT o.date_committed, " . db_decrypt_col('description', 'o') . " AS description, ot.level, ot.code, ot.name FROM offense o JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id WHERE o.student_id = :sid AND o.offense_id != :oid ORDER BY o.date_committed DESC, o.offense_id DESC LIMIT 30", $histParams);
          $defaultBody .= "PAST OFFENSE HISTORY (Most recent first):\n";
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
        
        $defaultBody = "Dear Guardian,\n\nThis is an official notice to inform you that your student has accumulated their 3rd minor offense, which triggers an automatic escalation to a Major Offense status under our discipline policy.\n\nThe student's case has now been forwarded to the University Panel on Community Conduct (UPCC), and a formal investigation is underway. We ask for your immediate cooperation as we review these repeated infractions.\n\nPlease see the detailed notice below for the complete offense history.\n\n";
        $coffParams = [':oid' => $newOffenseId];
        db_add_encryption_key($coffParams);
        $coff = db_one("SELECT " . db_decrypt_col('description', 'o') . " AS description, o.date_committed, ot.code, ot.name, ot.level FROM offense o JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id WHERE o.offense_id = :oid", $coffParams);
        if ($coff) {
            $dt = date('F j, Y g:i A', strtotime($coff['date_committed']));
            $defaultBody .= "CURRENT OFFENSE:\n- {$coff['code']} — {$coff['name']}\n- Level: {$coff['level']}\n- Date: {$dt}\n- Notes: " . ($coff['description'] ?: '(none)') . "\n\n";
        }
        $histParams = [':sid' => $studentId, ':oid' => $newOffenseId];
        db_add_encryption_key($histParams);
        $history = db_all("SELECT o.date_committed, " . db_decrypt_col('description', 'o') . " AS description, ot.level, ot.code, ot.name FROM offense o JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id WHERE o.student_id = :sid AND o.offense_id != :oid ORDER BY o.date_committed DESC, o.offense_id DESC LIMIT 30", $histParams);
        $defaultBody .= "PAST OFFENSE HISTORY (Most recent first):\n";
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

  // Store the pending letter in session so it pops up persistently if not sent
  if (isset($escalationType)) {
      if (session_status() === PHP_SESSION_NONE) session_start();
      $_SESSION['pending_letter'] = [
          'offense_id'      => $newOffenseId,
          'escalation_type' => $escalationType,
          'guardian_email'  => $guardianEmail ?? '',
          'default_subject' => $defaultSubject ?? '',
          'default_body'    => $defaultBody ?? ''
      ];
  }

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

// Reject and permanently delete the report
db_exec(
  "DELETE FROM guard_violation_report WHERE report_id = :rid",
  [':rid' => $reportId]
);

db_exec(
  "DELETE FROM notification
   WHERE type = 'GUARD_REPORT'
     AND related_table = 'guard_violation_report'
     AND related_id = :rid",
  [':rid' => $reportId]
);

echo json_encode(['ok' => true, 'message' => 'Report permanently deleted.']);
exit;
