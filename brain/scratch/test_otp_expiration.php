<?php
session_start();

// Test 1: Explicit cancellation via Back to Login Link
$_SESSION['admin_pre_2fa'] = ['admin_id' => 1, 'email' => 'test@example.com'];
$_SESSION['login_otp'] = ['code' => '654321', 'expires' => time() + 600];

echo "=== INITIAL OTP SESSION ===\n";
echo "admin_pre_2fa: " . (isset($_SESSION['admin_pre_2fa']) ? 'ACTIVE' : 'NONE') . "\n";
echo "login_otp: " . (isset($_SESSION['login_otp']) ? 'ACTIVE' : 'NONE') . "\n\n";

// Simulate clicking Back to Login link (login_otp.php?cancel=1)
unset($_SESSION['admin_pre_2fa']);
unset($_SESSION['login_otp']);
unset($_SESSION['login_otp_attempts']);
$_SESSION['login_otp_cancelled_msg'] = "Notice: Your previous verification code was automatically expired. Please log in with your username and password to request a new code.";

echo "=== AFTER GOING BACK TO LOGIN ===\n";
echo "admin_pre_2fa: " . (isset($_SESSION['admin_pre_2fa']) ? 'ACTIVE' : 'EXPIRED / CLEARED') . "\n";
echo "login_otp: " . (isset($_SESSION['login_otp']) ? 'ACTIVE' : 'EXPIRED / CLEARED') . "\n";
echo "Cancelled Notice Msg: " . ($_SESSION['login_otp_cancelled_msg']) . "\n\n";

// Test 2: Auto-invalidation on landing back on login.php
$_SESSION['admin_pre_2fa'] = ['admin_id' => 1];
$_SESSION['login_otp'] = ['code' => '999999'];

if (isset($_SESSION['admin_pre_2fa']) || isset($_SESSION['login_otp'])) {
    unset($_SESSION['admin_pre_2fa']);
    unset($_SESSION['login_otp']);
    unset($_SESSION['login_otp_attempts']);
    $notice = "Notice: Your previous verification code was automatically expired. Please log in with your username and password to request a new code.";
}

echo "=== AUTO INVALIDATION ON LOGIN.PHP LANDING ===\n";
echo "admin_pre_2fa: " . (isset($_SESSION['admin_pre_2fa']) ? 'ACTIVE' : 'EXPIRED / CLEARED') . "\n";
echo "Auto Notice Msg: " . $notice . "\n";
