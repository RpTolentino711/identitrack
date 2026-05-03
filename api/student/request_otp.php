<?php
declare(strict_types=1);

// TEMP DEBUG (remove after working)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../database/database.php';
require_once __DIR__ . '/class.smtp.php';
require_once __DIR__ . '/class.phpmailer.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function json_out(bool $ok, string $message = '', $data = null, int $status = 200): void {
  http_response_code($status);
  echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data]);
  exit;
}

function send_otp_email(string $toEmail, string $otp): array {
  $mail = new PHPMailer(true);

  try {
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->SMTPAuth = true;
    $mail->Username = 'romeopaolotolentino@gmail.com';
    $mail->Password = str_replace(' ', '', 'xhgg ajje ixak ajoj');
    $mail->SMTPSecure = 'tls';
    $mail->Port = 587;
    $mail->SMTPAutoTLS = true;

    $mail->CharSet = 'UTF-8';
    $mail->setFrom('romeopaolotolentino@gmail.com', 'IdentiTrack SDO');
    $mail->addAddress($toEmail);
    $mail->isHTML(true);
    $mail->Subject = $otp . ' is your IdentiTrack Verification Code';

    // Embed Logo
    $logoPath = __DIR__ . '/../../assets/logo.png';
    if (file_exists($logoPath)) {
        $mail->addEmbeddedImage($logoPath, 'identitrack_logo', 'logo.png');
    }

    $mail->Body = "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
      <meta charset='UTF-8'>
      <meta name='viewport' content='width=device-width, initial-scale=1.0'>
      <style>
        body { margin: 0; padding: 0; background-color: #f1f5f9; }
        .wrapper { width: 100%; table-layout: fixed; background-color: #f1f5f9; padding: 40px 0; }
        .email-container {
          max-width: 600px;
          margin: 0 auto;
          background-color: #ffffff;
          border-radius: 24px;
          overflow: hidden;
          box-shadow: 0 10px 40px rgba(0,0,0,0.08);
          font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
        }
        .header {
          background-color: #1e3a8a;
          background-image: linear-gradient(135deg, #1e3a8a 0%, #1e40af 100%);
          padding: 60px 40px;
          text-align: center;
        }
        .logo-img {
          width: 90px;
          height: auto;
          margin-bottom: 24px;
          border-radius: 20px;
          box-shadow: 0 8px 16px rgba(0,0,0,0.15);
        }
        .content {
          padding: 60px 50px;
          text-align: center;
        }
        h1 {
          color: #ffffff;
          margin: 0;
          font-size: 32px;
          font-weight: 800;
          letter-spacing: -0.5px;
        }
        p {
          color: #475569;
          font-size: 16px;
          line-height: 1.6;
          margin: 0 0 24px;
        }
        .otp-wrapper {
          margin: 40px 0;
        }
        .otp-display {
          display: inline-block;
          background-color: #f8fafc;
          border: 2px solid #e2e8f0;
          border-radius: 20px;
          padding: 30px 40px;
        }
        .otp-value {
          font-size: 48px;
          font-weight: 900;
          letter-spacing: 12px;
          color: #1e3a8a;
          margin: 0;
          padding-left: 12px; /* balance the letter spacing */
        }
        .footer {
          padding: 40px;
          text-align: center;
          background-color: #f8fafc;
          border-top: 1px solid #f1f5f9;
        }
        .footer-text {
          font-size: 13px;
          color: #94a3b8;
          margin: 0;
          line-height: 1.5;
        }
        .badge {
          display: inline-block;
          padding: 6px 12px;
          background-color: rgba(255,255,255,0.1);
          color: #ffffff;
          font-size: 12px;
          font-weight: 600;
          border-radius: 100px;
          margin-bottom: 16px;
          text-transform: uppercase;
          letter-spacing: 1px;
        }
      </style>
    </head>
    <body>
      <div class='wrapper'>
        <div class='email-container'>
          <div class='header'>
            <div class='badge'>Security Alert</div>
            <img src='cid:identitrack_logo' alt='IdentiTrack' class='logo-img'>
            <h1>Verify your account</h1>
          </div>
          <div class='content'>
            <p>To access your IdentiTrack account, please enter the following verification code on the student portal or mobile app.</p>
            <div class='otp-wrapper'>
              <div class='otp-display'>
                <div class='otp-value'>{$otp}</div>
              </div>
            </div>
            <p style='font-size: 14px; margin-bottom: 0;'>This code expires in <strong>5 minutes</strong>.</p>
          </div>
          <div class='footer'>
            <p class='footer-text'>&copy; " . date('Y') . " IdentiTrack System. All rights reserved.<br>This is an automated notification. Please do not reply.</p>
          </div>
        </div>
      </div>
    </body>
    </html>
    ";

    $mail->AltBody = "Your IdentiTrack OTP code is: {$otp}\n\nThis code will expire in 5 minutes.";

    return [$mail->send(), null];
  } catch (Exception $e) {
    return [false, $e->getMessage()];
  }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(false, 'Method not allowed.', null, 405);
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

$email = trim((string)($body['email'] ?? ''));

if ($email === '') json_out(false, 'Email is required.', null, 400);

// Find student
$student = db_one(
  "SELECT student_id, student_email, student_fn, student_ln, is_active
   FROM student
   WHERE student_email = :em
   LIMIT 1",
  [':em' => $email]
);

if (!$student) json_out(false, 'Email not found.', null, 404);
if ((int)$student['is_active'] !== 1) json_out(false, 'Student is not active.', null, 403);

// Automatically clean up any expired or used OTPs to keep the database light.
db_exec("DELETE FROM student_email_otp WHERE expires_at < NOW() OR used_at IS NOT NULL");

// The user requested to keep up to 3 recent OTPs so that if emails arrive late, the student can still log in.
// We get the current active OTPs for this student, ordered by newest first.
$activeOtps = db_all(
  "SELECT otp_id FROM student_email_otp WHERE student_id = :sid ORDER BY created_at DESC", 
  [':sid' => (string)$student['student_id']]
);

// If they already have 3 or more active OTPs, we delete the oldest ones so the new one makes it exactly 3.
if (count($activeOtps) >= 3) {
    // We keep the 2 most recent, and the new one we insert below will be the 3rd.
    $toDelete = array_slice($activeOtps, 2);
    foreach ($toDelete as $oldOtp) {
        db_exec("DELETE FROM student_email_otp WHERE otp_id = :id", [':id' => $oldOtp['otp_id']]);
    }
}

// Generate new OTP valid for 5 minutes.
$otp = (string)random_int(100000, 999999);
$otpHash = password_hash($otp, PASSWORD_DEFAULT);

$expiresAt = (new DateTime('now'))->add(new DateInterval('PT5M'))->format('Y-m-d H:i:s');

db_exec(
  "INSERT INTO student_email_otp (student_id, email, otp_hash, expires_at, created_at)
   VALUES (:sid, :em, :otp_hash, :exp, NOW())",
  [
    ':sid'    => (string)$student['student_id'],
    ':em'     => $email,
    ':otp_hash' => $otpHash,
    ':exp'    => $expiresAt,
  ]
);

[$sent, $mailErr] = send_otp_email($email, $otp);

json_out(true, $sent ? 'OTP sent successfully.' : 'OTP generated. SMTP failed; use debug OTP.', [
  'expires_at' => $expiresAt,
  'debug_otp' => $sent ? null : $otp,
  'mail_error' => $sent ? null : $mailErr,
]);