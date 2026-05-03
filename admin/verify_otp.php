<?php
/**
 * Verifies OTP for the logged-in admin.
 * POST: action=<string>, otp=123456
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../database/database.php';
require_admin();

date_default_timezone_set('Asia/Manila');

define('MAX_OTP_ATTEMPTS', 5);
define('LOCKOUT_DURATION', 300); // 5 minutes

function otp_key(int $adminId, string $action): string {
    $action = preg_replace('/[^a-z0-9_]/i', '', $action);
    return "adminotp_{$adminId}_{$action}";
}

$action = trim((string)($_POST['action'] ?? ''));
$otp = trim((string)($_POST['otp'] ?? ''));

if ($action === '' || $otp === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'action and otp are required.']);
    exit;
}

if (!preg_match('/^\d{6}$/', $otp)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'OTP must be 6 digits.']);
    exit;
}

$admin = admin_current();
$adminId = (int)($admin['admin_id'] ?? 0);
$key = otp_key($adminId, $action);

if (empty($_SESSION['otp'][$key])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No OTP request found. Please request a new one.']);
    exit;
}

$rec = $_SESSION['otp'][$key];

if (!empty($rec['locked_until']) && time() < (int)$rec['locked_until']) {
    $wait = (int)$rec['locked_until'] - time();
    http_response_code(429);
    echo json_encode(['success' => false, 'message' => "Too many attempts. Try again in {$wait} seconds."]);
    exit;
}

if (time() > (int)$rec['expires']) {
    unset($_SESSION['otp'][$key]);
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'OTP expired. Please request a new one.']);
    exit;
}

$rec['attempts'] = (int)($rec['attempts'] ?? 0) + 1;

if (!hash_equals((string)$rec['code'], $otp)) {
    if ($rec['attempts'] >= MAX_OTP_ATTEMPTS) {
        $rec['locked_until'] = time() + LOCKOUT_DURATION;
        $_SESSION['otp'][$key] = $rec;
        http_response_code(429);
        echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please wait 5 minutes.']);
        exit;
    }

    $_SESSION['otp'][$key] = $rec;
    $left = MAX_OTP_ATTEMPTS - $rec['attempts'];
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => "Incorrect OTP. {$left} attempts remaining."]);
    exit;
}

// ✅ success
unset($_SESSION['otp'][$key]);

// Optional: set a verified timestamp you can check later
if (!isset($_SESSION['otp_verified'])) $_SESSION['otp_verified'] = [];
$_SESSION['otp_verified'][$key] = time();

echo json_encode(['success' => true, 'message' => 'OTP verified.']);
exit;