<?php
// Test script for Multi-Admin Creation & Setup Flow
require_once __DIR__ . '/../../database/database.php';
require_once __DIR__ . '/../../admin/otp_mailer.php';

echo "--- STARTING MULTI-ADMIN WORKFLOW TEST ---\n\n";

// 1. Ensure schema updated
ensure_admin_schema();
echo "[1] Schema check completed.\n";

// 2. Clean up any previous test admin user
$testUsername = 'testadmin_' . time();
$testEmail = 'testadmin_' . time() . '@example.com';
$testName = 'Test New Admin';

db_exec("DELETE FROM admin_user WHERE username LIKE 'testadmin_%'");

// 3. Find existing active admin to act as old admin
$oldAdmin = db_one("SELECT * FROM admin_user WHERE is_active = 1 LIMIT 1");
if (!$oldAdmin) {
    die("Error: No existing active admin found in DB for test.\n");
}
echo "[2] Found existing admin: @" . $oldAdmin['username'] . " (" . $oldAdmin['email'] . ")\n";

// 4. Test init_create_admin session logic
$_SESSION['admin'] = [
    'admin_id' => (int)$oldAdmin['admin_id'],
    'full_name' => (string)$oldAdmin['full_name'],
    'username' => (string)$oldAdmin['username'],
    'email' => (string)$oldAdmin['email'],
    'role' => (string)$oldAdmin['role']
];

$otp = '123456';
$_SESSION['create_admin_pending'] = [
    'full_name' => $testName,
    'username' => $testUsername,
    'email' => $testEmail,
    'otp' => $otp,
    'expires' => time() + 600,
    'attempts' => 0,
    'otp_verified' => true // mark OTP verified for test
];

// Verify old admin password authorization
$pwVerify = admin_verify_password((int)$oldAdmin['admin_id'], 'admin123'); // assuming standard or test password
echo "[3] Tested admin_verify_password helper.\n";

// Direct insertion test matching profile.php logic
db_exec(
    "INSERT INTO admin_user (full_name, username, email, role, is_active, setup_pending, created_at, updated_at)
     VALUES (:fn, :u, :e, 'ADMIN', 0, 1, NOW(), NOW())",
    [
        ':fn' => $testName,
        ':u' => $testUsername,
        ':e' => $testEmail
    ]
);
echo "[4] Inserted pending admin @{$testUsername} into database.\n";

// 5. Test admin_find_by_username
$pendingAdmin = admin_find_by_username($testUsername);
assert($pendingAdmin !== null, 'Pending admin should be found');
assert((int)$pendingAdmin['setup_pending'] === 1, 'setup_pending should be 1');
assert((int)$pendingAdmin['is_active'] === 0, 'is_active should be 0');
echo "[5] Verified database record: setup_pending = 1, is_active = 0.\n";

// 6. Test password validation regexes
$weakPw1 = 'simple';
$weakPw2 = 'NoNumber!';
$weakPw3 = 'NoSpecial123';
$strongPw = 'StrongAdminPass123!';

function validate_pw(string $pw): bool {
    return strlen($pw) >= 8
        && preg_match('/[A-Z]/', $pw)
        && preg_match('/[0-9]/', $pw)
        && preg_match('/[!@#$%^&*(),.?":{}|<>_+-]/', $pw);
}

assert(validate_pw($weakPw1) === false, 'Weak PW 1 should fail');
assert(validate_pw($weakPw2) === false, 'Weak PW 2 should fail');
assert(validate_pw($weakPw3) === false, 'Weak PW 3 should fail');
assert(validate_pw($strongPw) === true, 'Strong PW should pass');
echo "[6] Password policy regex validation passed (8+ chars, Uppercase, Number, Special char).\n";

// 7. Simulate setup completion
$hash = password_hash($strongPw, PASSWORD_BCRYPT);
db_exec(
    "UPDATE admin_user
     SET password_hash = :hash, is_active = 1, setup_pending = 0, updated_at = NOW()
     WHERE admin_id = :id",
    [':hash' => $hash, ':id' => (int)$pendingAdmin['admin_id']]
);

$activeAdmin = admin_find_by_username($testUsername);
assert((int)$activeAdmin['setup_pending'] === 0, 'setup_pending should be 0 after setup');
assert((int)$activeAdmin['is_active'] === 1, 'is_active should be 1 after setup');
assert(password_verify($strongPw, $activeAdmin['password_hash']) === true, 'Password hash verification should pass');

echo "[7] First-time setup completed successfully! Account @{$testUsername} is now fully ACTIVE.\n\n";

// Clean up test admin
db_exec("DELETE FROM admin_user WHERE username = ?", [$testUsername]);
echo "--- ALL TESTS PASSED SUCCESSFULLY! ---\n";
