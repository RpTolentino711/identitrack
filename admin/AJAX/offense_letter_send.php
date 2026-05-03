<?php
require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../class.phpmailer.php';
require_once __DIR__ . '/../class.smtp.php';

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

$offenseId = (int)($body['offense_id'] ?? 0);
$subject = trim((string)($body['subject'] ?? 'Minor Offense Notice'));
$letterBody = trim((string)($body['body'] ?? ''));

if ($offenseId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Invalid offense_id.']);
  exit;
}

// Load offense + guardian email
$row = db_one(
  "SELECT
     o.offense_id, o.level, o.description, o.date_committed,
     s.student_id, s.student_fn, s.student_ln, s.student_email,
     ot.code AS offense_code, ot.name AS offense_name,
     g.guardian_email, g.guardian_fn, g.guardian_ln
   FROM offense o
   JOIN student s ON s.student_id = o.student_id
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   LEFT JOIN guardian g ON g.student_id = s.student_id
   WHERE o.offense_id = :oid
   LIMIT 1",
  [':oid' => $offenseId]
);

if (!$row) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'message' => 'Offense not found.']);
  exit;
}

$guardianEmail = trim((string)($body['guardian_email'] ?? $row['guardian_email'] ?? ''));

if ($guardianEmail === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Guardian email is empty. Please enter a valid email address.']);
  exit;
}

// If the frontend provided a new email, update or insert into the guardian table
$dbGuardianEmail = trim((string)($row['guardian_email'] ?? ''));
if ($guardianEmail !== $dbGuardianEmail && $guardianEmail !== '') {
  $hasGuardian = db_one("SELECT guardian_id FROM guardian WHERE student_id = :sid LIMIT 1", [':sid' => $row['student_id']]);
  try {
      if ($hasGuardian) {
        db_exec("UPDATE guardian SET guardian_email = :email, updated_at = CURRENT_TIMESTAMP WHERE student_id = :sid", [
          ':email' => $guardianEmail,
          ':sid'   => $row['student_id']
        ]);
      } else {
        db_exec("INSERT INTO guardian (student_id, guardian_email, created_at, updated_at) VALUES (:sid, :email, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)", [
          ':sid'   => $row['student_id'],
          ':email' => $guardianEmail
        ]);
      }
  } catch (PDOException $e) {
      // If it's a duplicate entry error (e.g., siblings sharing the same email), we just proceed
      // to send the email anyway rather than crashing and blocking the notification.
      if ($e->getCode() != 23000) {
          throw $e;
      }
  }
}

// ✅ Fetch offense history so attached PDF includes it too
$history = db_all(
  "SELECT
      o.offense_id,
      o.date_committed,
      o.description,
      ot.level,
      ot.code,
      ot.name
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   WHERE o.student_id = :sid
   ORDER BY o.date_committed DESC, o.offense_id DESC
   LIMIT 30",
  [':sid' => (string)$row['student_id']]
);

// Generate PDF again and attach (so it matches the latest edits + includes offense history)
require_once __DIR__ . '/pdf_text_letter.php';

$studentName = trim($row['student_fn'] . ' ' . $row['student_ln']);
$guardianName = trim((string)($row['guardian_fn'] ?? '') . ' ' . (string)($row['guardian_ln'] ?? ''));
if ($guardianName === '') $guardianName = 'Parent/Guardian';

$lines = [];
$lines[] = "To: {$guardianName}";
$lines[] = "Student: {$studentName} ({$row['student_id']})";
$lines[] = "Generated: " . date('F j, Y g:i A');
$lines[] = "";

$lines[] = $letterBody;

$pdfBytes = pdf_make_simple($subject, $lines);

// Save temp file to attach
$dirAbs = __DIR__ . '/../../uploads/letters';
if (!is_dir($dirAbs)) mkdir($dirAbs, 0775, true);

$filename = 'minor_offense_' . $offenseId . '_' . date('Ymd_His') . '.pdf';
$fileAbs = $dirAbs . '/' . $filename;
file_put_contents($fileAbs, $pdfBytes);

// Send email via PHPMailer
$mail = new PHPMailer(true);
$mail->CharSet = 'UTF-8';
$mail->isSMTP();
$mail->Host = 'smtp.gmail.com';
$mail->Port = 587;
$mail->SMTPAuth = true;
$mail->SMTPSecure = 'tls';

// ✅ Set this to the Gmail you are using for SMTP in your project
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
    <p>Please see the attached notice letter regarding the student’s recorded offenses.</p>
    <hr style='border:none;border-top:1px solid #e5e7eb;margin:14px 0;' />
    <div style='color:#374151;font-size:14px;line-height:1.6;'>{$safeBody}</div>
    <p style='margin-top:18px;color:#6b7280;font-size:12px;'>This is an automated message from IdentiTrack SDO Web Portal.</p>
  </div>
";

$mail->AltBody = "Minor Offense Notice\n\n" . $letterBody;

$mail->addAttachment($fileAbs, $filename);

try {
  $mail->send();
  
  // Mark the offense as having notified the guardian
  db_exec(
    "UPDATE offense SET guardian_notified_at = CURRENT_TIMESTAMP WHERE offense_id = :oid",
    [':oid' => $offenseId]
  );
  
  echo json_encode(['ok' => true, 'message' => 'Email sent.']);
  exit;
} catch (Exception $e) {
  error_log('Offense letter mail error: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Failed to send email. Check error logs.']);
  exit;
}