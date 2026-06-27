<?php
session_start();
require_once __DIR__ . '/../database/database.php';

// Must have an active OTP session
if (!isset($_SESSION['upcc_otp_val'], $_SESSION['upcc_otp_user'], $_SESSION['upcc_otp_time'])) {
    header('Location: upccpanel.php');
    exit;
}

// Check lock state
if (isset($_SESSION['upcc_otp_locked_until'])) {
    $diff = $_SESSION['upcc_otp_locked_until'] - time();
    if ($diff > 0) {
        $_SESSION['otp_error'] = 'Too many failed attempts. Locked for ' . ceil($diff / 60) . ' minutes.';
        header('Location: send_otp.php');
        exit;
    } else {
        unset($_SESSION['upcc_otp_locked_until'], $_SESSION['upcc_otp_failures']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted = trim($_POST['otp'] ?? '');
    $stored    = (string) $_SESSION['upcc_otp_val'];
    $elapsed   = time() - (int) $_SESSION['upcc_otp_time'];

    // OTP expired (5 minutes)
    if ($elapsed > 300) {
        unset(
            $_SESSION['upcc_otp_val'],
            $_SESSION['upcc_otp_user'],
            $_SESSION['upcc_otp_time'],
            $_SESSION['upcc_pending_otp'],
            $_SESSION['upcc_otp_failures'],
            $_SESSION['upcc_otp_locked_until']
        );
        $_SESSION['login_error'] = 'OTP expired. Please log in again.';
        header('Location: upccpanel.php');
        exit;
    }

    if ($submitted === $stored) {
        // ✅ Correct OTP — fetch user and set authenticated session
        $username = $_SESSION['upcc_otp_user'];
        $user = upcc_find_by_username($username);

        // Clear all OTP / pending session keys
        unset(
            $_SESSION['upcc_otp_val'],
            $_SESSION['upcc_otp_user'],
            $_SESSION['upcc_otp_time'],
            $_SESSION['upcc_pending_otp'],
            $_SESSION['upcc_otp_failures'],
            $_SESSION['upcc_otp_locked_until']
        );

        // Set the session keys that upcc_current() and upccdashboard.php check
        $_SESSION['upcc_authenticated'] = true;
        $_SESSION['upcc_user'] = [
            'upcc_id'    => (int) $user['upcc_id'],
            'full_name'  => (string) $user['full_name'],
            'username'   => (string) $user['username'],
            'email'      => (string) $user['email'],
            'role'       => (string) $user['role'],
            'photo_path' => (string) ($user['photo_path'] ?? ''),
        ];

        $need = db_one("SELECT must_change_password FROM upcc_user WHERE upcc_id = :id", [':id' => (int)$user['upcc_id']]);
        if ((int)($need['must_change_password'] ?? 0) === 1) {
            header('Location: upcc_change_password.php');
        } else {
            header('Location: upccdashboard.php');
        }
        exit;
    }

    // ❌ Wrong OTP — increment failure count
    $_SESSION['upcc_otp_failures'] = ($_SESSION['upcc_otp_failures'] ?? 0) + 1;
    if ($_SESSION['upcc_otp_failures'] >= 4) {
        $_SESSION['upcc_otp_locked_until'] = time() + 300; // Lock for 5 minutes
        $_SESSION['otp_error'] = 'Too many failed attempts. Locked for 5 minutes.';
    } else {
        $left = 4 - $_SESSION['upcc_otp_failures'];
        $_SESSION['otp_error'] = "Incorrect OTP. Please try again. ({$left} attempts remaining)";
    }
    header('Location: send_otp.php');
    exit;
}

// GET request with no POST — just redirect back to OTP page
header('Location: send_otp.php');
exit;