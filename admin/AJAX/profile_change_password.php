<?php
// Updates admin_user.password_hash AFTER OTP has already been verified in profile.php
// Requires: current_password, new_password, confirm_password (JSON)
//
// IMPORTANT: OTP verification is handled by profile.php calling admin/verify_otp.php.
// This endpoint only changes password in DB.

require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['profile_reauth_ok'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'message' => 'Re-auth required.']);
  exit;
}

$admin = admin_current();
$adminId = (int)($admin['admin_id'] ?? 0);

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

// Verify OTP was actually completed
$otpKey = "adminotp_{$adminId}_change_password";
$verifiedTime = (int)($_SESSION['otp_verified'][$otpKey] ?? 0);
if ($verifiedTime === 0 || (time() - $verifiedTime) > 600) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'OTP verification expired or not found. Please try again.']);
    exit;
}
// Consume verification
unset($_SESSION['otp_verified'][$otpKey]);

$new = (string)($body['new_password'] ?? '');
$confirm = (string)($body['confirm_password'] ?? '');

if (trim($new) === '' || trim($confirm) === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'New password and confirm password are required.']);
  exit;
}
if ($new !== $confirm) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'New password and confirm password do not match.']);
  exit;
}

// Policy check (server-side enforcement)
if (strlen($new) < 8
  || !preg_match('/[A-Z]/', $new)
  || !preg_match('/[a-z]/', $new)
  || !preg_match('/[0-9]/', $new)
  || !preg_match('/[^A-Za-z0-9]/', $new)
) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Password must be 8+ chars with uppercase, lowercase, number, and special character.']);
  exit;
}

// Update password using helper for consistency
admin_set_password($adminId, $new);

echo json_encode(['ok' => true]);
exit;