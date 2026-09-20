<?php
session_start();

require_once 'c:/xampp/htdocs/identitrack/database/database.php';

echo "=== TEST 1: USERNAME CHECK AJAX ENDPOINT ===\n";
$_GET['check_username'] = '1';
$_GET['username'] = 'admin';

$u = trim((string)($_GET['username'] ?? ''));
$admin = admin_find_by_username($u);
$exists = ($admin && (int)($admin['is_active'] ?? 1) === 1);
echo "Check 'admin': exists = " . ($exists ? 'YES (Registered)' : 'NO') . "\n";

$_GET['username'] = 'nonexistent_user_123';
$u2 = trim((string)($_GET['username'] ?? ''));
$admin2 = admin_find_by_username($u2);
$exists2 = ($admin2 && (int)($admin2['is_active'] ?? 1) === 1);
echo "Check 'nonexistent_user_123': exists = " . ($exists2 ? 'YES' : 'NO (Unregistered)') . "\n\n";

echo "=== TEST 2: 2-MINUTE LOCKOUT COOLDOWN ===\n";
// Simulate 4th OTP failed attempt setting 2-min lockout
$_SESSION['admin_lockout_until'] = time() + 120;
$_SESSION['login_otp_locked_error'] = "LOCKOUT_ERR::Security Lockout: Exceeded maximum 4 invalid OTP attempts. (Try again in <span id=\"lockoutTimer\">2:00</span>)";

$lockoutUntil = (int)($_SESSION['admin_lockout_until'] ?? 0);
$isLocked = false;
$remainingSeconds = 0;
if ($lockoutUntil > time()) {
    $isLocked = true;
    $remainingSeconds = $lockoutUntil - time();
}

echo "isLocked: " . ($isLocked ? 'TRUE (Form Locked)' : 'FALSE') . "\n";
echo "remainingSeconds: " . $remainingSeconds . "s\n";
echo "Timer string: " . floor($remainingSeconds / 60) . ":" . str_pad((string)($remainingSeconds % 60), 2, '0', STR_PAD_LEFT) . "\n";
