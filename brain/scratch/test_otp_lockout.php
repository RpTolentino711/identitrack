<?php
session_start();

// Mock pre-2fa state
$_SESSION['admin_pre_2fa'] = [
    'admin_id' => 1,
    'username' => 'testadmin',
    'full_name' => 'Test Admin',
    'email' => 'test@example.com'
];
$_SESSION['login_otp'] = [
    'code' => '123456',
    'expires' => time() + 600,
    'last_sent' => time()
];
$_SESSION['login_otp_attempts'] = 0;

echo "=== INITIAL STATE ===\n";
echo "Pre 2FA set: " . (isset($_SESSION['admin_pre_2fa']) ? 'YES' : 'NO') . "\n";
echo "Attempts: " . ($_SESSION['login_otp_attempts']) . "\n\n";

// Simulate 3 wrong attempts
for ($i = 1; $i <= 3; $i++) {
    $enteredOtp = '000000';
    if ($enteredOtp !== $_SESSION['login_otp']['code']) {
        $attempts = (int)($_SESSION['login_otp_attempts'] ?? 0) + 1;
        $_SESSION['login_otp_attempts'] = $attempts;
        echo "Attempt {$i}: Registered wrong code. Current Attempts = {$attempts}/4\n";
    }
}

echo "\nPre 2FA after 3 attempts: " . (isset($_SESSION['admin_pre_2fa']) ? 'STILL ACTIVE' : 'LOCKED') . "\n";

// Simulate 4th wrong attempt
echo "\n=== 4TH WRONG ATTEMPT == philosophy ...\n";
$enteredOtp = '000000';
if ($enteredOtp !== $_SESSION['login_otp']['code']) {
    $attempts = (int)($_SESSION['login_otp_attempts'] ?? 0) + 1;
    $_SESSION['login_otp_attempts'] = $attempts;

    if ($attempts >= 4) {
        unset($_SESSION['admin_pre_2fa']);
        unset($_SESSION['login_otp']);
        unset($_SESSION['login_otp_attempts']);
        $_SESSION['login_otp_locked_error'] = "Security Lockout: Exceeded maximum 4 invalid OTP attempts. Please log in again.";
        echo "4th Attempt reached! Pre-2FA cleared: " . (!isset($_SESSION['admin_pre_2fa']) ? 'YES (LOCKED)' : 'NO') . "\n";
        echo "Session Lockout Error: " . $_SESSION['login_otp_locked_error'] . "\n";
    }
}
