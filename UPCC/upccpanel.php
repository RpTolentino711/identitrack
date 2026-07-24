<?php
session_start();
require_once __DIR__ . '/../database/database.php';

try {
    $col = db_one("SHOW COLUMNS FROM upcc_user LIKE 'must_change_password'");
    if (!$col) {
        db_exec("ALTER TABLE upcc_user ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
    } else {
        db_exec("ALTER TABLE upcc_user MODIFY COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
    }
    db_exec("UPDATE upcc_user SET must_change_password = 0 WHERE must_change_password IS NULL");
} catch (Exception $e) {
    error_log('UPCC must_change_password migration failed: ' . $e->getMessage());
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    upcc_logout();
    unset(
        $_SESSION['upcc_authenticated'],
        $_SESSION['upcc_pending_otp'],
        $_SESSION['upcc_otp_val'],
        $_SESSION['upcc_otp_user'],
        $_SESSION['upcc_otp_time'],
        $_SESSION['upcc_recovery']
    );
    header('Location: upccpanel.php');
    exit;
}

// Already fully authenticated -> go straight to dashboard
if (isset($_SESSION['upcc_authenticated']) && upcc_current()) {
    $u = upcc_current();
    $need = db_one("SELECT must_change_password FROM upcc_user WHERE upcc_id = :id", [':id' => (int)($u['upcc_id'] ?? 0)]);
    if ((int)($need['must_change_password'] ?? 0) === 1) {
        header('Location: upcc_change_password.php');
    } else {
        header('Location: upccdashboard.php');
    }
    exit;
}

// Check active lockout status
$now = time();
$isLocked = false;
$secondsLeft = 0;

if (isset($_SESSION['upcc_username_locked_until'])) {
    if ($now < $_SESSION['upcc_username_locked_until']) {
        $isLocked = true;
        $secondsLeft = $_SESSION['upcc_username_locked_until'] - $now;
    } else {
        unset($_SESSION['upcc_username_locked_until'], $_SESSION['upcc_username_failures']);
    }
}

// Email OTP Helper Function
function send_upcc_recovery_email(string $toEmail, string $toName, string $otp): bool {
    require_once __DIR__ . '/class.phpmailer.php';
    require_once __DIR__ . '/class.smtp.php';

    try {
        $mail = new PHPMailer(true);
        $mail->CharSet   = 'UTF-8';
        $mail->isSMTP();
        $mail->Host      = $_ENV['SMTP_HOST'] ?? 'smtp.hostinger.com';
        $mail->Port      = 587;
        $mail->SMTPAuth  = true;
        $mail->SMTPSecure = 'tls';
        $mail->Username  = $_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site';
        $mail->Password  = $_ENV['SMTP_PASS'] ?? '';
        $mail->Timeout   = 30;

        $mail->setFrom($_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site', 'UPCC Panel');
        $mail->addAddress($toEmail, $toName);
        $mail->addReplyTo('no-reply@identitrack.local', 'UPCC Panel');

        $logoPath = realpath(__DIR__ . '/../assets/logo.png');
        $cid = 'upcclogo';
        if ($logoPath && is_readable($logoPath)) {
            $mail->addEmbeddedImage($logoPath, $cid, 'logo.png');
            $logoHtml = "<img src=\"cid:$cid\" width=\"42\" height=\"42\" alt=\"UPCC\" style=\"display:block;border-radius:12px;\" />";
        } else {
            $logoHtml = "<div style=\"width:42px;height:42px;border-radius:12px;background:#1e3a8a;color:#fff;font-weight:800;font-size:14px;text-align:center;line-height:42px;\">IT</div>";
        }

        $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
        $safeOtp  = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

        $mail->isHTML(true);
        $mail->Subject = 'UPCC Account Recovery OTP';
        $mail->Body = "
    <!doctype html>
    <html>
    <head><meta charset='utf-8'></head>
    <body style='margin:0;padding:0;background:#f3f4f6;'>
      <div style='padding:24px 12px;'>
        <div style='max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 12px 30px rgba(17,24,39,.10);font-family:Segoe UI,Tahoma,Arial,sans-serif;'>
          <div style='background:#0b1630;padding:20px 24px;'>
            <div style='display:flex;align-items:center;gap:12px;'>
              {$logoHtml}
              <div>
                <div style='font-size:17px;font-weight:900;color:#e8ecf7;'>UPCC Panel</div>
                <div style='font-size:12px;color:#7a8aac;margin-top:3px;'>Account Recovery Verification</div>
              </div>
            </div>
          </div>
          <div style='padding:28px 24px;color:#1f2937;'>
            <h2 style='margin:0 0 12px;font-size:20px;color:#111827;'>Hello, {$safeName}</h2>
            <p style='margin:0 0 20px;font-size:14px;color:#4b5563;'>You requested to recover your UPCC panel account username/password. Use the 6-digit verification code below to proceed:</p>
            <div style='text-align:center;background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:20px;margin-bottom:24px;'>
              <span style='font-size:36px;font-weight:800;letter-spacing:8px;color:#2563eb;'>{$safeOtp}</span>
            </div>
            <p style='font-size:13px;color:#6b7280;margin:0;'>This code will expire in 10 minutes. If you did not request account recovery, please ignore this email.</p>
          </div>
        </div>
      </div>
    </body>
    </html>";

        return $mail->send();
    } catch (Exception $e) {
        error_log('Recovery mail failed: ' . $e->getMessage());
        return false;
    }
}

// AJAX API Handlers
if (isset($_POST['action'])) {
    header('Content-Type: application/json');
    $action = $_POST['action'];

    if ($action === 'check_username') {
        if ($isLocked) {
            echo json_encode([
                'ok' => false,
                'locked' => true,
                'seconds_left' => $secondsLeft,
                'error' => 'Too many invalid attempts (8/8). Account search is locked for 5 minutes.'
            ]);
            exit;
        }

        $username = trim($_POST['username'] ?? '');
        if ($username === '') {
            echo json_encode(['ok' => false, 'error' => 'Please enter your username.']);
            exit;
        }

        $upcc = upcc_find_by_username($username);

        if (!$upcc || (int)($upcc['is_active'] ?? 0) !== 1) {
            $failures = ($_SESSION['upcc_username_failures'] ?? 0) + 1;
            $_SESSION['upcc_username_failures'] = $failures;

            if ($failures >= 8) {
                $_SESSION['upcc_username_locked_until'] = time() + 300; // 5 minutes lockout
                echo json_encode([
                    'ok' => false,
                    'locked' => true,
                    'seconds_left' => 300,
                    'show_recovery' => true,
                    'error' => 'Too many invalid attempts (8/8). Account search is locked for 5 minutes.'
                ]);
            } else {
                $rem = 8 - $failures;
                $msg = !$upcc ? 'Account not found.' : 'Account is inactive.';
                echo json_encode([
                    'ok' => false,
                    'failures' => $failures,
                    'show_recovery' => ($failures >= 4),
                    'error' => $msg . " ($failures/8 failed attempts. Lockout after $rem more attempt" . ($rem > 1 ? 's' : '') . ".)"
                ]);
            }
            exit;
        }

        // Success -> reset failure counter
        unset($_SESSION['upcc_username_failures'], $_SESSION['upcc_username_locked_until']);
        echo json_encode(['ok' => true, 'username' => $upcc['username'], 'full_name' => $upcc['full_name']]);
        exit;
    }

    if ($action === 'send_recovery_otp') {
        $email = trim(strtolower($_POST['email'] ?? ''));
        if ($email === '') {
            echo json_encode(['ok' => false, 'error' => 'Please enter your registered email address.']);
            exit;
        }

        $user = db_one(
            "SELECT upcc_id, full_name, username, email, is_active FROM upcc_user WHERE LOWER(email) = :email LIMIT 1",
            [':email' => $email]
        );

        if (!$user || (int)($user['is_active'] ?? 0) !== 1) {
            echo json_encode(['ok' => false, 'error' => 'No active UPCC panel account found with this email address.']);
            exit;
        }

        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['upcc_recovery'] = [
            'upcc_id' => (int)$user['upcc_id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'full_name' => $user['full_name'],
            'otp' => $otp,
            'expires' => time() + 600,
            'verified' => false
        ];

        $sent = send_upcc_recovery_email($user['email'], $user['full_name'], $otp);
        if ($sent) {
            $parts = explode('@', $user['email']);
            $masked = substr($parts[0], 0, 2) . '***@' . $parts[1];
            echo json_encode(['ok' => true, 'email_masked' => $masked, 'message' => "Verification code sent to $masked"]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Failed to send recovery OTP email. Please verify SMTP configuration.']);
        }
        exit;
    }

    if ($action === 'verify_recovery_otp') {
        $otp = trim($_POST['otp'] ?? '');
        $rec = $_SESSION['upcc_recovery'] ?? null;

        if (!$rec || time() > ($rec['expires'] ?? 0)) {
            echo json_encode(['ok' => false, 'error' => 'Recovery session expired. Please request a new OTP code.']);
            exit;
        }

        if ($otp === $rec['otp']) {
            $_SESSION['upcc_recovery']['verified'] = true;
            echo json_encode(['ok' => true, 'username' => $rec['username']]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Invalid OTP code. Please check your email and try again.']);
        }
        exit;
    }

    if ($action === 'reset_account_credentials') {
        $rec = $_SESSION['upcc_recovery'] ?? null;
        if (!$rec || empty($rec['verified'])) {
            echo json_encode(['ok' => false, 'error' => 'Unauthorized recovery attempt. Please verify OTP first.']);
            exit;
        }

        $newUsername = trim(strtolower($_POST['new_username'] ?? ''));
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if ($newUsername === '') {
            echo json_encode(['ok' => false, 'error' => 'Please enter a username.']);
            exit;
        }

        // Check if username taken by someone else
        $taken = db_one(
            "SELECT upcc_id FROM upcc_user WHERE LOWER(username) = :u AND upcc_id != :id LIMIT 1",
            [':u' => $newUsername, ':id' => $rec['upcc_id']]
        );
        if ($taken) {
            echo json_encode(['ok' => false, 'error' => "Username '$newUsername' is already taken by another account."]);
            exit;
        }

        if (strlen($newPassword) < 8) {
            echo json_encode(['ok' => false, 'error' => 'Password must be at least 8 characters long.']);
            exit;
        }
        if (!preg_match('/[A-Z]/', $newPassword)) {
            echo json_encode(['ok' => false, 'error' => 'Password must contain at least one uppercase letter (A-Z).']);
            exit;
        }
        if (!preg_match('/[a-z]/', $newPassword)) {
            echo json_encode(['ok' => false, 'error' => 'Password must contain at least one lowercase letter (a-z).']);
            exit;
        }
        if (!preg_match('/[^a-zA-Z0-9]/', $newPassword)) {
            echo json_encode(['ok' => false, 'error' => 'Password must contain at least one special character (e.g. !@#$%^&*).']);
            exit;
        }

        if ($newPassword !== $confirmPassword) {
            echo json_encode(['ok' => false, 'error' => 'Passwords do not match.']);
            exit;
        }

        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        db_exec(
            "UPDATE upcc_user SET username = :u, password_hash = :p WHERE upcc_id = :id",
            [':u' => $newUsername, ':p' => $hash, ':id' => $rec['upcc_id']]
        );

        unset($_SESSION['upcc_username_failures'], $_SESSION['upcc_username_locked_until'], $_SESSION['upcc_recovery']);
        echo json_encode([
            'ok' => true,
            'username' => $newUsername,
            'message' => 'Credentials updated successfully! You can now log in.'
        ]);
        exit;
    }
}

$error = '';
$username_checked = false;

if ($isLocked) {
    $error = "Too many invalid attempts (8/8). Account search is locked for 5 minutes.";
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if ($username === '') {
        $error = 'Please enter your username.';
    } else {
        $upcc = upcc_find_by_username($username);
        if (!$upcc) {
            $error = 'Account not found.';
        } elseif ((int)($upcc['is_active'] ?? 0) !== 1) {
            $error = 'Account is inactive.';
        } elseif ($password === '') {
            $username_checked = true;
        } else {
            $result = upcc_login($username, $password);
            if ($result['ok']) {
                unset($_SESSION['upcc_username_failures'], $_SESSION['upcc_username_locked_until']);
                $_SESSION['upcc_pending_otp'] = $result['user']['username'];
                header('Location: send_otp.php');
                exit;
            } else {
                $username_checked = true;
                $error = $result['error'];
            }
        }
    }
}

$error = $error ?: ($_SESSION['login_error'] ?? '');
unset($_SESSION['login_error']);
$currentFailures = $_SESSION['upcc_username_failures'] ?? 0;
$showRecoveryLink = ($currentFailures >= 4);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>UPCC Panel &mdash; Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg-base: #0f172a;
            --card-bg: rgba(15, 23, 42, 0.6);
            --border: rgba(255, 255, 255, 0.08);
            --accent: #3b82f6;
            --accent-glow: rgba(59, 130, 246, 0.4);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --danger: #ef4444;
            --success: #10b981;
        }

        body {
            background-color: var(--bg-base);
            background-image: 
                radial-gradient(ellipse at top right, rgba(59, 130, 246, 0.15), transparent 50%),
                radial-gradient(ellipse at bottom left, rgba(147, 51, 234, 0.1), transparent 50%);
            color: var(--text-main);
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .wrapper {
            width: 100%;
            max-width: 440px;
            padding: 24px;
            animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        .logo-row {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 32px;
        }
        .logo-mark {
            width: 56px; height: 56px;
            border-radius: 14px;
            object-fit: cover;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .logo-text {
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: 16px;
            color: var(--text-main);
            letter-spacing: 2.5px;
            text-transform: uppercase;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px 32px;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            box-shadow: 0 24px 48px rgba(0,0,0,0.4), inset 0 1px 1px rgba(255,255,255,0.05);
        }

        .card-title {
            font-family: 'Syne', sans-serif;
            font-size: 28px;
            font-weight: 700;
            line-height: 1.2;
            text-align: center;
            margin-bottom: 8px;
        }
        .card-sub {
            font-size: 14px;
            text-align: center;
            color: var(--text-muted);
            margin-bottom: 32px;
        }

        .alert-err {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 14px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
            line-height: 1.4;
        }
        .alert-err svg { width: 18px; height: 18px; flex-shrink: 0; }

        .field { 
            margin-bottom: 20px;
            transition: opacity 0.3s ease, transform 0.3s ease;
        }
        .field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .field input {
            width: 100%;
            padding: 14px 16px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text-main);
            font-family: 'DM Sans', sans-serif;
            font-size: 15px;
            outline: none;
            transition: all 0.3s ease;
        }
        .field input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-glow);
            background: rgba(0, 0, 0, 0.4);
        }
        .field input:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .field input::placeholder { color: rgba(148, 163, 184, 0.5); }

        .input-wrapper { position: relative; }
        .input-wrapper input { padding-right: 50px; }

        .eye-toggle {
            position: absolute;
            right: 14px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: var(--text-muted); cursor: pointer;
            padding: 4px; border-radius: 4px;
            transition: color 0.2s;
        }
        .eye-toggle:hover { color: var(--text-main); }
        .eye-icon { width: 20px; height: 20px; outline: none; }

        .field-footer {
            display: flex;
            justify-content: flex-end;
            margin-top: -8px;
            margin-bottom: 24px;
        }
        .forgot-link {
            font-size: 13px;
            color: var(--text-muted);
            text-decoration: none;
            transition: color 0.2s;
        }
        .forgot-link:hover { color: var(--text-main); text-decoration: underline; }

        .btn-recovery-link {
            background: none;
            border: none;
            color: #60a5fa;
            font-size: 13px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: underline;
            transition: color 0.2s;
            display: inline-block;
            margin-top: 8px;
        }
        .btn-recovery-link:hover { color: #93c5fd; }

        .btn-login {
            width: 100%;
            padding: 16px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.1);
            background: var(--accent);
            color: #fff;
            font-family: 'DM Sans', sans-serif;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px var(--accent-glow);
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .btn-login:hover:not(:disabled) { transform: translateY(-2px); box-shadow: 0 8px 16px var(--accent-glow); filter: brightness(1.1); }
        .btn-login:active:not(:disabled) { transform: translateY(0); box-shadow: 0 2px 8px var(--accent-glow); }
        .btn-login:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            box-shadow: none;
        }

        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 0.8s linear infinite;
            margin-right: 8px;
        }
        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Modal Styles for Recovery */
        .modal-overlay {
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background: rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1000;
            padding: 20px;
        }
        .modal-overlay.active { display: flex; }
        .modal-card {
            background: #1e293b;
            border: 1px solid rgba(255, 255, 255, 0.12);
            border-radius: 24px;
            padding: 32px;
            width: 100%;
            max-width: 440px;
            box-shadow: 0 24px 48px rgba(0, 0, 0, 0.5);
            position: relative;
        }
        .modal-close {
            position: absolute;
            top: 20px; right: 20px;
            background: none; border: none;
            color: var(--text-muted);
            font-size: 22px; cursor: pointer;
            line-height: 1;
        }
        .modal-close:hover { color: var(--text-main); }
        .pw-hints {
            font-size: 12px;
            margin-top: 10px;
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 6px;
            background: rgba(0,0,0,0.2);
            padding: 12px;
            border-radius: 10px;
            border: 1px solid rgba(255,255,255,0.05);
        }
        .pw-hint-item { color: #94a3b8; transition: color 0.2s; }
        .pw-hint-item.valid { color: #4ade80; font-weight: 600; }

        .secure-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
            font-size: 13px;
            color: var(--text-muted);
        }
        .secure-note svg { width: 14px; height: 14px; }

        .back-link { text-align: center; margin-top: 24px; }
        .back-link a {
            color: var(--text-muted); text-decoration: none;
            font-size: 14px; transition: color 0.2s;
        }
        .back-link a:hover { color: var(--text-main); }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); filter: blur(4px); }
            to   { opacity: 1; transform: translateY(0); filter: blur(0); }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="logo-row">
        <img src="../assets/logo.png" alt="UPCC Logo" class="logo-mark">
        <div class="logo-text">UPCC Panel</div>
    </div>

    <div class="card">
        <div class="card-title">Sign in</div>
        <div class="card-sub">University Promotion &amp; Conduct Committee</div>

        <div id="alertBox" class="alert-err" style="<?= empty($error) ? 'display: none;' : '' ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <div style="flex:1;">
                <span id="alertText"><?= htmlspecialchars($error) ?></span>
                <div id="recoveryLinkBox" style="<?= $showRecoveryLink ? 'display:block;' : 'display:none;' ?>">
                    <button type="button" class="btn-recovery-link" onclick="openRecoveryModal()">
                        🔍 Forgot Username / Account Recovery?
                    </button>
                </div>
            </div>
        </div>
        <form id="loginForm" method="post" action="upccpanel.php" autocomplete="off">
            <!-- Step 1: Username Field -->
            <div class="field" id="field-username" style="<?= $isLocked ? 'display: none;' : '' ?>">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       placeholder="Enter username" autofocus required <?= $isLocked ? 'disabled' : '' ?>>
            </div>

            <!-- Step 2: Password Field (hidden until username is verified) -->
            <div class="field" id="field-password" style="<?= ($username_checked && !$isLocked) ? 'display: block; opacity: 1;' : 'display: none; opacity: 0;' ?>">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password"
                           placeholder="Enter password" <?= $username_checked ? 'required' : '' ?> <?= $isLocked ? 'disabled' : '' ?>>
                    <button type="button" class="eye-toggle" id="eye-toggle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="eye-icon">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Forgot password link -->
            <div class="field-footer" id="forgot-footer" style="<?= ($username_checked && !$isLocked) ? 'display: flex;' : 'display: none;' ?>">
                <a href="reset_password.php" class="forgot-link">Forgot / Reset password?</a>
            </div>

            <button type="submit" id="btnSubmit" class="btn-login" style="<?= $isLocked ? 'display: none;' : '' ?>" <?= $isLocked ? 'disabled' : '' ?>><?= $username_checked ? 'Sign in &rarr;' : 'Continue &rarr;' ?></button>
        </form>

        <div class="secure-note">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            Secured with 2-factor authentication
        </div>

        <div class="back-link">
            <a href="../index.php">&larr; Back to Home</a>
        </div>
    </div>
</div>

<!-- Account Recovery Modal -->
<div id="recoveryModal" class="modal-overlay">
    <div class="modal-card">
        <button type="button" class="modal-close" onclick="closeRecoveryModal()">&times;</button>
        <div style="font-family:'Syne',sans-serif;font-size:22px;font-weight:700;margin-bottom:6px;text-align:center;">Account Recovery</div>
        <div style="font-size:13px;color:var(--text-muted);text-align:center;margin-bottom:24px;" id="recModalSub">Retrieve your username and set a new password via email OTP.</div>

        <div id="recAlert" class="alert-err" style="display:none;margin-bottom:20px;">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            <span id="recAlertText"></span>
        </div>

        <!-- Recovery Step 1: Send OTP -->
        <div id="recStep1">
            <div class="field">
                <label for="recEmail">Registered Email Address</label>
                <input type="email" id="recEmail" placeholder="e.g. name@domain.com" required>
            </div>
            <button type="button" id="btnSendRecOtp" class="btn-login" onclick="sendRecoveryOtp()">Send Verification OTP &rarr;</button>
        </div>

        <!-- Recovery Step 2: Verify OTP -->
        <div id="recStep2" style="display:none;">
            <div style="font-size:13px;color:#38bdf8;background:rgba(56,189,248,0.1);padding:10px 14px;border-radius:10px;margin-bottom:18px;" id="recEmailNotice"></div>
            <div class="field">
                <label for="recOtp">Enter 6-Digit OTP Code</label>
                <input type="text" id="recOtp" maxlength="6" placeholder="000000" style="text-align:center;letter-spacing:6px;font-size:20px;font-weight:700;" required>
            </div>
            <button type="button" id="btnVerifyRecOtp" class="btn-login" onclick="verifyRecoveryOtp()">Verify OTP Code &rarr;</button>
        </div>

        <!-- Recovery Step 3: Reset Credentials -->
        <div id="recStep3" style="display:none;">
            <div class="field">
                <label for="recNewUsername">Username</label>
                <input type="text" id="recNewUsername" placeholder="Enter username" required>
            </div>
            <div class="field">
                <label for="recNewPassword">New Password</label>
                <div class="input-wrapper">
                    <input type="password" id="recNewPassword" placeholder="Enter new password" oninput="checkPwStrength()" required>
                    <button type="button" class="eye-toggle" onclick="togglePasswordVisibility('recNewPassword', this)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="eye-icon">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
                <div class="pw-hints">
                    <div class="pw-hint-item" id="hintLen">&bull; 8+ Characters</div>
                    <div class="pw-hint-item" id="hintUpper">&bull; Uppercase (A-Z)</div>
                    <div class="pw-hint-item" id="hintLower">&bull; Lowercase (a-z)</div>
                    <div class="pw-hint-item" id="hintSpec">&bull; Special (!@#$)</div>
                </div>
            </div>
            <div class="field">
                <label for="recConfirmPassword">Confirm New Password</label>
                <div class="input-wrapper">
                    <input type="password" id="recConfirmPassword" placeholder="Re-enter new password" oninput="checkPwStrength()" required>
                    <button type="button" class="eye-toggle" onclick="togglePasswordVisibility('recConfirmPassword', this)">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="eye-icon">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
                <div id="matchNotice" style="font-size:12px;margin-top:6px;display:none;"></div>
            </div>
            <button type="button" id="btnSaveCredentials" class="btn-login" onclick="saveNewCredentials()">Save &amp; Sign In &rarr;</button>
        </div>
    </div>
</div>

<script>
let step = <?= $username_checked ? 2 : 1 ?>;
let lockoutTimer = null;

function togglePasswordVisibility(inputId, btn) {
    const input = document.getElementById(inputId);
    const icon = btn.querySelector('.eye-icon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    } else {
        input.type = 'password';
        icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
}

function checkPwStrength() {
    const pw = document.getElementById('recNewPassword').value;
    const confirmPw = document.getElementById('recConfirmPassword').value;
    
    const hLen = document.getElementById('hintLen');
    const hUpper = document.getElementById('hintUpper');
    const hLower = document.getElementById('hintLower');
    const hSpec = document.getElementById('hintSpec');
    const matchNotice = document.getElementById('matchNotice');

    if (pw.length >= 8) hLen.classList.add('valid'); else hLen.classList.remove('valid');
    if (/[A-Z]/.test(pw)) hUpper.classList.add('valid'); else hUpper.classList.remove('valid');
    if (/[a-z]/.test(pw)) hLower.classList.add('valid'); else hLower.classList.remove('valid');
    if (/[^a-zA-Z0-9]/.test(pw)) hSpec.classList.add('valid'); else hSpec.classList.remove('valid');

    if (confirmPw.length > 0) {
        matchNotice.style.display = 'block';
        if (pw === confirmPw) {
            matchNotice.style.color = '#4ade80';
            matchNotice.style.fontWeight = '600';
            matchNotice.innerHTML = '&#10004; Passwords match';
        } else {
            matchNotice.style.color = '#fca5a5';
            matchNotice.style.fontWeight = '500';
            matchNotice.innerHTML = '&#10008; Passwords do not match';
        }
    } else {
        matchNotice.style.display = 'none';
    }
}

function openRecoveryModal() {
    document.getElementById('recoveryModal').classList.add('active');
    document.getElementById('recStep1').style.display = 'block';
    document.getElementById('recStep2').style.display = 'none';
    document.getElementById('recStep3').style.display = 'none';
    document.getElementById('recAlert').style.display = 'none';
}

function closeRecoveryModal() {
    document.getElementById('recoveryModal').classList.remove('active');
}

function sendRecoveryOtp() {
    const email = document.getElementById('recEmail').value.trim();
    const alertBox = document.getElementById('recAlert');
    const alertText = document.getElementById('recAlertText');
    const btn = document.getElementById('btnSendRecOtp');

    if (!email) {
        alertText.textContent = 'Please enter your registered email address.';
        alertBox.style.display = 'flex';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>Sending OTP...';
    alertBox.style.display = 'none';

    const params = new URLSearchParams();
    params.append('action', 'send_recovery_otp');
    params.append('email', email);

    fetch('upccpanel.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = 'Send Verification OTP &rarr;';
        if (data.ok) {
            document.getElementById('recStep1').style.display = 'none';
            document.getElementById('recStep2').style.display = 'block';
            document.getElementById('recEmailNotice').textContent = data.message;
        } else {
            alertText.textContent = data.error || 'Failed to send recovery OTP.';
            alertBox.style.display = 'flex';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = 'Send Verification OTP &rarr;';
        alertText.textContent = 'Connection error. Please try again.';
        alertBox.style.display = 'flex';
    });
}

function verifyRecoveryOtp() {
    const otp = document.getElementById('recOtp').value.trim();
    const alertBox = document.getElementById('recAlert');
    const alertText = document.getElementById('recAlertText');
    const btn = document.getElementById('btnVerifyRecOtp');

    if (!otp || otp.length !== 6) {
        alertText.textContent = 'Please enter the 6-digit OTP code sent to your email.';
        alertBox.style.display = 'flex';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>Verifying...';
    alertBox.style.display = 'none';

    const params = new URLSearchParams();
    params.append('action', 'verify_recovery_otp');
    params.append('otp', otp);

    fetch('upccpanel.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = 'Verify OTP Code &rarr;';
        if (data.ok) {
            document.getElementById('recStep2').style.display = 'none';
            document.getElementById('recStep3').style.display = 'block';
            document.getElementById('recNewUsername').value = data.username;
        } else {
            alertText.textContent = data.error || 'Invalid OTP code.';
            alertBox.style.display = 'flex';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = 'Verify OTP Code &rarr;';
        alertText.textContent = 'Connection error. Please try again.';
        alertBox.style.display = 'flex';
    });
}

function saveNewCredentials() {
    const newUsername = document.getElementById('recNewUsername').value.trim();
    const newPassword = document.getElementById('recNewPassword').value;
    const confirmPassword = document.getElementById('recConfirmPassword').value;
    const alertBox = document.getElementById('recAlert');
    const alertText = document.getElementById('recAlertText');
    const btn = document.getElementById('btnSaveCredentials');

    if (!newUsername) {
        alertText.textContent = 'Please enter a username.';
        alertBox.style.display = 'flex';
        return;
    }

    if (newPassword.length < 8 || !/[A-Z]/.test(newPassword) || !/[a-z]/.test(newPassword) || !/[^a-zA-Z0-9]/.test(newPassword)) {
        alertText.textContent = 'Password must be 8+ characters and contain an uppercase letter, a lowercase letter, and a special character.';
        alertBox.style.display = 'flex';
        return;
    }

    if (newPassword !== confirmPassword) {
        alertText.textContent = 'Passwords do not match.';
        alertBox.style.display = 'flex';
        return;
    }

    btn.disabled = true;
    btn.innerHTML = '<span class="spinner"></span>Saving...';
    alertBox.style.display = 'none';

    const params = new URLSearchParams();
    params.append('action', 'reset_account_credentials');
    params.append('new_username', newUsername);
    params.append('new_password', newPassword);
    params.append('confirm_password', confirmPassword);

    fetch('upccpanel.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = 'Save &amp; Sign In &rarr;';
        if (data.ok) {
            closeRecoveryModal();
            step = 2;
            const usernameInput = document.getElementById('username');
            const fieldUsername = document.getElementById('field-username');
            const fieldPassword = document.getElementById('field-password');
            const btnSubmit = document.getElementById('btnSubmit');

            fieldUsername.style.display = 'block';
            usernameInput.value = data.username;
            usernameInput.disabled = false;

            fieldPassword.style.display = 'block';
            fieldPassword.style.opacity = '1';
            document.getElementById('forgot-footer').style.display = 'flex';
            document.getElementById('password').value = newPassword;
            
            btnSubmit.style.display = 'flex';
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = 'Sign in &rarr;';
            
            const mainAlert = document.getElementById('alertBox');
            const mainAlertText = document.getElementById('alertText');
            mainAlertText.style.color = '#4ade80';
            mainAlertText.textContent = 'Account updated successfully! Click Sign in to proceed.';
            mainAlert.style.display = 'flex';
            document.getElementById('recoveryLinkBox').style.display = 'none';
        } else {
            alertText.textContent = data.error || 'Failed to update credentials.';
            alertBox.style.display = 'flex';
        }
    })
    .catch(err => {
        btn.disabled = false;
        btn.innerHTML = 'Save &amp; Sign In &rarr;';
        alertText.textContent = 'Connection error. Please try again.';
        alertBox.style.display = 'flex';
    });
}

function startLockoutCountdown(seconds) {
    const alertBox = document.getElementById('alertBox');
    const alertText = document.getElementById('alertText');
    const btnSubmit = document.getElementById('btnSubmit');
    const usernameField = document.getElementById('field-username');
    const passwordField = document.getElementById('field-password');
    const forgotFooter = document.getElementById('forgot-footer');
    const recoveryBox = document.getElementById('recoveryLinkBox');

    // HIDE username field and button during countdown!
    usernameField.style.display = 'none';
    passwordField.style.display = 'none';
    forgotFooter.style.display = 'none';
    btnSubmit.style.display = 'none';

    // SHOW recovery link during lockout
    recoveryBox.style.display = 'block';
    alertBox.style.display = 'flex';

    if (lockoutTimer) clearInterval(lockoutTimer);

    let remaining = seconds;
    const updateMsg = () => {
        if (remaining <= 0) {
            clearInterval(lockoutTimer);
            
            // Re-appear username field & button after countdown
            usernameField.style.display = 'block';
            const usernameInput = usernameField.querySelector('input');
            usernameInput.value = '';
            usernameInput.disabled = false;

            btnSubmit.style.display = 'flex';
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = 'Continue &rarr;';
            step = 1;

            alertBox.style.display = 'none';
            recoveryBox.style.display = 'none';
            return;
        }
        const m = Math.floor(remaining / 60);
        const s = remaining % 60;
        const timeStr = `${m}:${s < 10 ? '0' : ''}${s}`;
        alertText.innerHTML = `Too many invalid attempts (8/8). Account search is locked. <strong>(Try again in ${timeStr})</strong>`;
        remaining--;
    };

    updateMsg();
    lockoutTimer = setInterval(updateMsg, 1000);
}

<?php if ($isLocked && $secondsLeft > 0): ?>
startLockoutCountdown(<?= $secondsLeft ?>);
<?php endif; ?>

document.getElementById('loginForm').addEventListener('submit', function(e) {
    if (step === 1) {
        e.preventDefault();
        const usernameInput = document.getElementById('username');
        const username = usernameInput.value.trim();
        const alertBox = document.getElementById('alertBox');
        const alertText = document.getElementById('alertText');
        const btnSubmit = document.getElementById('btnSubmit');
        const recoveryBox = document.getElementById('recoveryLinkBox');

        if (!username) {
            alertText.textContent = 'Please enter your username.';
            alertBox.style.display = 'flex';
            return;
        }

        btnSubmit.disabled = true;
        btnSubmit.innerHTML = '<span class="spinner"></span>Checking...';
        alertBox.style.display = 'none';

        const params = new URLSearchParams();
        params.append('action', 'check_username');
        params.append('username', username);

        fetch('upccpanel.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        })
        .then(res => res.json())
        .then(data => {
            if (data.locked) {
                startLockoutCountdown(data.seconds_left);
                return;
            }

            btnSubmit.disabled = false;
            if (data.ok) {
                step = 2;
                const passField = document.getElementById('field-password');
                const forgotFooter = document.getElementById('forgot-footer');
                const passInput = document.getElementById('password');

                passField.style.display = 'block';
                setTimeout(() => { passField.style.opacity = '1'; }, 10);
                forgotFooter.style.display = 'flex';

                passInput.required = true;
                passInput.focus();

                btnSubmit.innerHTML = 'Sign in &rarr;';
            } else {
                alertText.innerHTML = data.error || 'Account not found.';
                alertBox.style.display = 'flex';
                btnSubmit.innerHTML = 'Continue &rarr;';

                if (data.show_recovery) {
                    recoveryBox.style.display = 'block';
                }
            }
        })
        .catch(err => {
            btnSubmit.disabled = false;
            btnSubmit.innerHTML = 'Continue &rarr;';
            alertText.textContent = 'Connection error. Please try again.';
            alertBox.style.display = 'flex';
        });
    }
});

document.getElementById('eye-toggle').addEventListener('click', function() {
    const input = document.getElementById('password');
    const icon  = this.querySelector('.eye-icon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    } else {
        input.type = 'password';
        icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
});
</script>
</body>
</html>
