<?php
// admin/AJAX/profile_save.php
// Saves admin_user full_name and email.
// Requires OTP if email is changed.

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

$fullName = trim((string)($body['full_name'] ?? ''));
$newEmail = trim((string)($body['email'] ?? ''));

if ($fullName === '' || $newEmail === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Name and email are required.']);
    exit;
}

if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid email format.']);
    exit;
}

// Check if email changed
$currentAdmin = db_one("SELECT email FROM admin_user WHERE admin_id = ?", [$adminId]);
$oldEmail = (string)($currentAdmin['email'] ?? '');

if (strtolower($newEmail) !== strtolower($oldEmail)) {
    // Email changed -> Check OTP verification
    $otpKey = "adminotp_{$adminId}_change_email";
    $verifiedTime = (int)($_SESSION['otp_verified'][$otpKey] ?? 0);
    
    // OTP must be verified within the last 10 minutes
    if ($verifiedTime === 0 || (time() - $verifiedTime) > 600) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => 'OTP verification required for email change.', 'requires_otp' => true]);
        exit;
    }
    
    // Consume the verification
    unset($_SESSION['otp_verified'][$otpKey]);
}

try {
    db_exec("UPDATE admin_user SET full_name = :fn, email = :e, updated_at = NOW() WHERE admin_id = :id", [
        ':fn' => $fullName,
        ':e'  => $newEmail,
        ':id' => $adminId
    ]);
    
    // Update session
    $_SESSION['admin']['full_name'] = $fullName;
    $_SESSION['admin']['email'] = $newEmail;
    
    echo json_encode(['ok' => true, 'message' => 'Profile updated successfully.']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
exit;
