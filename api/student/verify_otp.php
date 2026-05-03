<?php
declare(strict_types=1);

// TEMP DEBUG (remove after working)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../database/database.php';

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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(false, 'Method not allowed.', null, 405);
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

$email = trim((string)($body['email'] ?? ''));
$otp   = trim((string)($body['otp'] ?? ''));

if ($email === '') json_out(false, 'Email is required.', null, 400);
if ($otp === '') json_out(false, 'OTP is required.', null, 400);

// Find student
$student = db_one(
  "SELECT student_id, student_email, student_fn, student_ln, is_active
   FROM student
   WHERE student_email = :em
   LIMIT 1",
  [':em' => $email]
);

// Confirm student exists
if (!$student) json_out(false, 'Email not found.', null, 404);

// Determine account mode and message
$accountPolicy = student_account_mode((string)$student['student_id']);
$accountMode = (string)($accountPolicy['mode'] ?? 'FULL_ACCESS');

if ((int)$student['is_active'] !== 1) {
    // If account is frozen, we ONLY allow login if it's a disciplinary freeze (Cat 4/5)
    // to allow the student to see their notice. Otherwise, block with 403.
    if (!in_array($accountMode, ['WARNING_FREEZE_LOGOUT_ONLY', 'AUTO_LOGOUT_FREEZE'], true)) {
        json_out(false, 'Student is not active.', null, 403);
    }
}

// Latest unused OTP — scoped to both student_id and email
$otpRow = db_one(
  "SELECT otp_id, otp_hash, expires_at
   FROM student_email_otp
   WHERE student_id = :sid
     AND email = :em
     AND used_at IS NULL
   ORDER BY created_at DESC
   LIMIT 1",
  [
    ':sid' => (string)$student['student_id'],
    ':em'  => $email,
  ]
);

if (!$otpRow) json_out(false, 'No OTP request found. Please request a new OTP.', null, 400);

// Expiration check
$exp = strtotime((string)$otpRow['expires_at']);
if ($exp !== false && $exp < time()) {
  json_out(false, 'OTP expired. Please request a new OTP.', null, 400);
}

// Verify hash
if (!password_verify($otp, (string)$otpRow['otp_hash'])) {
  json_out(false, 'Invalid OTP.', null, 401);
}

// Mark OTP as used
db_exec(
  "UPDATE student_email_otp SET used_at = NOW() WHERE otp_id = :id LIMIT 1",
  [':id' => (int)$otpRow['otp_id']]
);

// Create auth session token
$token     = bin2hex(random_bytes(32));
$tokenHash = password_hash($token, PASSWORD_DEFAULT);

$issuedAt  = (new DateTime('now'))->format('Y-m-d H:i:s');
$expiresAt = (new DateTime('now'))->add(new DateInterval('P30D'))->format('Y-m-d H:i:s');

db_exec(
  "INSERT INTO auth_session (actor_type, student_id, session_token_hash, issued_at, expires_at)
   VALUES ('STUDENT', :sid, :hash, :issued, :exp)",
  [
    ':sid'    => (string)$student['student_id'],
    ':hash'   => $tokenHash,
    ':issued' => $issuedAt,
    ':exp'    => $expiresAt,
  ]
);

// Build full name
$studentName = trim((string)$student['student_fn'] . ' ' . (string)$student['student_ln']);

json_out(true, 'Login successful.', [
  'student_id'   => (string)$student['student_id'],
  'student_name' => $studentName,
  'token'        => $token,
  'expires_at'   => $expiresAt,
]);