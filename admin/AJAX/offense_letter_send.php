<?php
require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/../class.phpmailer.php';
require_once __DIR__ . '/../class.smtp.php';

$offenseId = (int)($_POST['offense_id'] ?? 0);
$subject = trim((string)($_POST['subject'] ?? 'Minor Offense Notice'));
$letterBody = trim((string)($_POST['body'] ?? ''));

use Dompdf\Dompdf;
use Dompdf\Options;

try {

if ($offenseId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Invalid offense_id.']);
  exit;
}

// Load offense + guardian email
$params = [':oid' => $offenseId];
db_add_encryption_key($params);
$row = db_one(
  "SELECT
     o.offense_id, o.level, " . db_decrypt_col('description', 'o') . " AS description, o.date_committed,
     s.student_id, " . db_decrypt_cols(['student_fn', 'student_ln', 'student_email'], 's') . ",
     ot.code AS offense_code, ot.name AS offense_name,
     g.guardian_email, g.guardian_fn, g.guardian_ln
   FROM offense o
   JOIN student s ON s.student_id = o.student_id
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   LEFT JOIN guardian g ON g.student_id = s.student_id
   WHERE o.offense_id = :oid
   LIMIT 1",
  $params
);

if (!$row) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'message' => 'Offense not found.']);
  exit;
}

$guardianEmail = trim((string)($_POST['guardian_email'] ?? $row['guardian_email'] ?? ''));

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
$histParams = [':sid' => (string)$row['student_id']];
db_add_encryption_key($histParams);
$history = db_all(
  "SELECT
      o.offense_id,
      o.date_committed,
      " . db_decrypt_col('description', 'o') . " AS description,
      ot.level,
      ot.code,
      ot.name
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   WHERE o.student_id = :sid
   ORDER BY o.date_committed DESC, o.offense_id DESC
   LIMIT 30",
  $histParams
);

// Generate PDF again and attach (so it matches the latest edits + includes offense history)
require_once __DIR__ . '/../../vendor/autoload.php';

$studentName = trim($row['student_fn'] . ' ' . $row['student_ln']);
$guardianName = trim((string)($row['guardian_fn'] ?? '') . ' ' . (string)($row['guardian_ln'] ?? ''));
if ($guardianName === '') $guardianName = 'Parent/Guardian';

$dateGen = date('F j, Y g:i A');

$imagePath = null;
$imgHtml = '';
if (isset($_FILES['letter_image']) && $_FILES['letter_image']['error'] === UPLOAD_ERR_OK) {
    $imagePath = $_FILES['letter_image']['tmp_name'];
    $imgType = pathinfo($_FILES['letter_image']['name'], PATHINFO_EXTENSION);
    $imgData = file_get_contents($imagePath);
    $base64 = 'data:image/' . $imgType . ';base64,' . base64_encode($imgData);
    
    $imgX = isset($_POST['image_x']) ? (int)$_POST['image_x'] : 72;
    $imgYOffset = isset($_POST['image_y_offset']) ? (int)$_POST['image_y_offset'] : 0;
    $imgW = isset($_POST['image_w']) ? (int)$_POST['image_w'] : 150;
    
    // Convert offsets to relative basic CSS or absolute positioning
    $imgHtml = '<div style="margin-top: 40px; margin-left: '.($imgX - 72).'px;"><img src="'.$base64.'" width="'.$imgW.'" style="position: relative; top: '.$imgYOffset.'px;" /></div>';
}

$letterheadPath = realpath(__DIR__ . '/../../assets/guardian_letterhead.png');
$letterheadImg = '';
if ($letterheadPath && file_exists($letterheadPath)) {
    $lhData = file_get_contents($letterheadPath);
    $lhBase64 = 'data:image/png;base64,' . base64_encode($lhData);
    $letterheadImg = '<div style="margin-bottom: 12px;"><img src="' . $lhBase64 . '" style="max-width: 280px; height: auto;" /></div>';
}

$html = '
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 12pt; color: #1e293b; line-height: 1.55; }
        .header { margin-bottom: 22px; border-bottom: 2px solid #e2e8f0; padding-bottom: 14px; }
        .title { font-size: 16pt; font-weight: bold; margin-bottom: 14px; color: #0f172a; border-bottom: 1px solid #cbd5e1; padding-bottom: 8px; }
        .meta { margin-bottom: 18px; font-size: 11.5pt; color: #334155; line-height: 1.6; }
        .content { font-size: 12pt; color: #1e293b; line-height: 1.6; }
        .content p { margin: 0 0 10px 0; padding: 0; }
        .content ul, .content ol { margin: 8px 0; padding-left: 24px; }
        .content li { margin-bottom: 4px; }
        .sdo { font-size: 20pt; font-weight: bold; font-family: "Times New Roman", Times, serif; color: #0f172a; margin-bottom: 4px; }
        .official { font-size: 11pt; color: #64748b; margin-bottom: 16px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="header">
        ' . $letterheadImg . '
        <div class="sdo">Student Discipline Office</div>
        <div class="official">Official Student Conduct Notice · IdentiTrack System</div>
        <div class="title">' . htmlspecialchars($subject) . '</div>
        <div class="meta">
            <strong>To:</strong> ' . htmlspecialchars($guardianName) . '<br>
            <strong>Student:</strong> ' . htmlspecialchars($studentName) . ' (' . htmlspecialchars($row['student_id']) . ')<br>
            <strong>Generated:</strong> ' . $dateGen . '
        </div>
    </div>
    <div class="content">
        ' . $letterBody . '
    </div>
    ' . $imgHtml . '
</body>
</html>';

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$pdfBytes = $dompdf->output();

// Save temp file & permanent copy in uploads/letters/ for student/admin access
$filename = 'minor_offense_' . $offenseId . '_' . date('Ymd_His') . '.pdf';
$lettersDir = __DIR__ . '/../../uploads/letters';
if (!file_exists($lettersDir)) {
    @mkdir($lettersDir, 0777, true);
}
$publicRelPath = 'uploads/letters/' . $filename;
$fileAbs = __DIR__ . '/../../' . $publicRelPath;
file_put_contents($fileAbs, $pdfBytes);

// Send email via PHPMailer
$mail = new PHPMailer(true);
$mail->CharSet = 'UTF-8';
$mail->isSMTP();
    $getEnv = function($key, $default) {
        return (string)($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default);
    };

    $mail->Host = $getEnv('SMTP_HOST', 'smtp.hostinger.com');
    $mail->Port = 587;
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = 'tls';

    // ✅ Set this to the Gmail you are using for SMTP in your project
    $mail->Username = $getEnv('SMTP_USER', 'identitrack@identitrack.site');
    $mail->Password = $getEnv('SMTP_PASS', '');

$mail->setFrom($mail->Username, 'IdentiTrack Admin');
$mail->addAddress($guardianEmail, $guardianName);
$mail->addReplyTo('no-reply@identitrack.site', 'IdentiTrack');

$mail->isHTML(true);
$mail->Subject = $subject;

$logoPath = realpath(__DIR__ . '/../../assets/logo.png');
$hasLogo = ($logoPath && is_readable($logoPath));
$cid = 'identitracklogo';
if ($hasLogo) {
    $mail->addEmbeddedImage($logoPath, $cid, 'logo.png');
}

$logoSrc = $hasLogo ? "cid:$cid" : "https://identitrack.site/assets/logo.png";

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
        <img src='{$logoSrc}' alt='IdentiTrack Logo' class='logo-img'>
        <h1>Student Discipline Office</h1>
      </div>
      <div class='content'>
        <p style='font-weight:600;font-size:16px;color:#1e293b;margin-top:0;'>Dear Parent/Guardian,</p>
        <p>This is an official communication from the Student Discipline Office.</p>
        <p>We are writing to formally inform you that an incident report involving your student, <strong>{$studentName}</strong>, has been filed and processed by our office.</p>
        <p>Because we hold our students to the highest standards of conduct, we believe it is essential to keep parents and guardians closely informed of any disciplinary matters. Attached to this email is the official notice detailing the nature of the offense, the date it occurred, and any associated sanctions or next steps.</p>
        <p>We strongly encourage you to review the attached PDF document carefully and discuss this matter with your student.</p>
        <div style='background-color:#eff6ff; border:1px solid #bfdbfe; border-radius:12px; padding:16px 20px; margin:20px 0; color:#1e3a8a; font-size:13.5px; line-height:1.5;'>
          <p style='margin:0 0 6px 0; font-weight:700; color:#1d4ed8; font-size:14px;'>🤝 Parent & Guardian Formal Cooperation Request</p>
          <p style='margin:0;'>Please formally cooperate with your student regarding this disciplinary notification to support their personal development, conduct compliance, and academic progress.</p>
        </div>
        <p style='margin-top:16px;margin-bottom:0;'>If you have any questions, require further clarification, or wish to schedule a meeting, please coordinate with the Student Discipline Office or the University Panel on Community Conduct.</p>
      </div>
      <div class='footer'>
        <p style='margin:0 0 6px 0; font-weight:700; color:#475569;'>🤖 Automated System Notification — Please Do Not Reply</p>
        <p style='margin:0 0 8px 0; font-size:12px; color:#64748b;'>This email was generated automatically by the IdentiTrack System. Please do not reply directly to this message as this mailbox is unmonitored.</p>
        <div style='font-size:11px; color:#94a3b8;'>&copy; " . date('Y') . " IdentiTrack System. All rights reserved.</div>
      </div>
    </div>
  </div>
</body>
</html>
";

$mail->AltBody = "Dear Parent/Guardian,\n\nThis is an official communication from the Student Discipline Office. We are writing to formally inform you that an incident report involving your student, {$studentName}, has been filed and processed by our office.\n\nPlease review the attached official conduct notice regarding their disciplinary record. We strongly encourage you to review the attached PDF document carefully and discuss this matter with your student.\n\nIf you have any questions, please coordinate with the Student Discipline Office.\n\nSincerely,\nStudent Discipline Office";

$mail->addAttachment($fileAbs, $filename);

if (isset($_FILES['letter_image']) && $_FILES['letter_image']['error'] === UPLOAD_ERR_OK) {
    $mail->addAttachment($_FILES['letter_image']['tmp_name'], $_FILES['letter_image']['name']);
}

try {
  $mail->send();
  
  // Mark the offense as having notified the guardian
  db_exec(
    "UPDATE offense SET guardian_notified_at = CURRENT_TIMESTAMP WHERE offense_id = :oid",
    [':oid' => $offenseId]
  );

  $_SESSION['pending_nte_offense_id'] = $offenseId;
  $_SESSION['active_offense_id'] = $offenseId;

  // Store in violation_letter table for student record & app access
  $admin = admin_current();
  $adminId = (int)($admin['admin_id'] ?? $admin['id'] ?? 1);

  $letterType = 'CUSTOM';
  $is3rdMinor = (stripos($subject, '3rd Minor') !== false || stripos($subject, 'Section 4') !== false || stripos($subject, 'Formative') !== false);
  $isMajor = (stripos($subject, 'Major Offense') !== false || strtoupper((string)($row['level'] ?? '')) === 'MAJOR');

  if ($is3rdMinor) {
      $letterType = 'THIRD_MINOR_NOTICE';
  } elseif ($isMajor) {
      $letterType = 'MAJOR_OFFENSE_NOTICE';
  }

  db_exec(
      "INSERT INTO violation_letter (student_id, generated_by, letter_type, subject, body, file_path, generated_at)
       VALUES (:sid, :admin, :ltype, :subject, :body, :fpath, CURRENT_TIMESTAMP)",
      [
          ':sid'     => (string)$row['student_id'],
          ':admin'   => $adminId,
          ':ltype'   => $letterType,
          ':subject' => $subject,
          ':body'    => $letterBody,
          ':fpath'   => $publicRelPath
      ]
  );
  $letterId = (int)db_last_id();

  // Create student in-app notification
  $notifTitle = $is3rdMinor ? 'Notice of Formative Intervention (F-005) Issued' : ($isMajor ? 'Major Offense Conduct Notice Issued' : 'Student Conduct Notice Sent');
  $notifMsg = 'An official conduct notice (' . $subject . ') was sent to your parent/guardian by the Student Discipline Office.';

  db_exec(
      "INSERT INTO notification (type, title, message, student_id, admin_id, related_table, related_id, is_read, created_at)
       VALUES ('OFFENSE_LETTER', :title, :msg, :sid, :admin, 'violation_letter', :lid, 0, CURRENT_TIMESTAMP)",
      [
          ':title' => $notifTitle,
          ':msg'   => $notifMsg,
          ':sid'   => (string)$row['student_id'],
          ':admin' => $adminId,
          ':lid'   => (string)$letterId
      ]
  );
  
  if (isset($_SESSION['pending_letter']) && $_SESSION['pending_letter']['offense_id'] == $offenseId) {
      unset($_SESSION['pending_letter']);
  }
  
  echo json_encode(['ok' => true, 'message' => 'Email sent and official notice recorded for student.']);
  exit;
} catch (Exception $e) {
  error_log('Offense letter mail error: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Failed to send email: ' . $e->getMessage()]);
  exit;
}

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'System Error: ' . $e->getMessage()]);
    exit;
}