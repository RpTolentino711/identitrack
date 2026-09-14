<?php
// File: C:\xampp\htdocs\identitrack\admin\login.php
// Admin Login (USERNAME ONLY + Password)
// Uses ONLY functions from database/database.php

require_once __DIR__ . '/../database/database.php';

header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (session_status() === PHP_SESSION_NONE) session_start();

require_once __DIR__ . '/otp_mailer.php';

// AJAX check for registered admin username & status
if (isset($_GET['check_username'])) {
    header('Content-Type: application/json; charset=utf-8');
    $u = trim((string)($_GET['username'] ?? ''));
    if ($u === '') {
        echo json_encode(['ok' => false, 'exists' => false]);
        exit;
    }
    $admin = admin_find_by_username($u);
    if (!$admin) {
        echo json_encode(['ok' => true, 'exists' => false]);
        exit;
    }

    $isPending = (int)($admin['setup_pending'] ?? 0) === 1 || ((int)($admin['is_active'] ?? 0) === 0 && empty($admin['password_hash']));
    if ($isPending) {
        echo json_encode(['ok' => true, 'exists' => true, 'status' => 'PENDING_SETUP']);
        exit;
    }

    $isActive = (int)($admin['is_active'] ?? 0) === 1;
    echo json_encode(['ok' => true, 'exists' => $isActive, 'status' => $isActive ? 'ACTIVE' : 'INACTIVE']);
    exit;
}

if (($_GET['action'] ?? '') === 'request_setup_otp') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    $u = trim((string)($data['username'] ?? ''));
    $email = strtolower(trim((string)($data['email'] ?? '')));

    if ($u === '' || $email === '') {
        echo json_encode(['ok' => false, 'message' => 'Username and Email are required.']);
        exit;
    }

    $admin = admin_find_by_username($u);
    if (!$admin) {
        echo json_encode(['ok' => false, 'message' => 'Admin username not found.']);
        exit;
    }

    $isPending = (int)($admin['setup_pending'] ?? 0) === 1 || ((int)($admin['is_active'] ?? 0) === 0 && empty($admin['password_hash']));
    if (!$isPending) {
        echo json_encode(['ok' => false, 'message' => 'This account has already completed setup. Please log in with your password.']);
        exit;
    }

    $registeredEmail = strtolower(trim((string)($admin['email'] ?? '')));
    if ($email !== $registeredEmail) {
        echo json_encode(['ok' => false, 'message' => 'Email address does not match the registered email for this account.']);
        exit;
    }

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['new_admin_setup'] = [
        'admin_id' => (int)$admin['admin_id'],
        'username' => $admin['username'],
        'full_name' => $admin['full_name'],
        'email' => $registeredEmail,
        'otp' => $otp,
        'expires' => time() + 600,
        'attempts' => 0,
        'otp_verified' => false
    ];

    $mailSent = send_admin_otp_email($registeredEmail, $admin['full_name'] ?: 'Admin', 'Admin Account Setup OTP', $otp);
    if (!$mailSent) {
        echo json_encode(['ok' => false, 'message' => 'Failed to send OTP to ' . $registeredEmail . '. Please check SMTP configuration.']);
        exit;
    }

    echo json_encode(['ok' => true, 'message' => 'Verification code sent to ' . $registeredEmail . '.']);
    exit;
}

if (($_GET['action'] ?? '') === 'verify_setup_otp') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    $otp = trim((string)($data['otp'] ?? ''));

    $setup = $_SESSION['new_admin_setup'] ?? null;
    if (!$setup || !is_array($setup)) {
        echo json_encode(['ok' => false, 'message' => 'No active setup session found. Please start over.']);
        exit;
    }

    if (time() > (int)($setup['expires'] ?? 0)) {
        unset($_SESSION['new_admin_setup']);
        echo json_encode(['ok' => false, 'message' => 'Verification code has expired. Please request a new code.']);
        exit;
    }

    if (($setup['attempts'] ?? 0) >= 4) {
        unset($_SESSION['new_admin_setup']);
        echo json_encode(['ok' => false, 'message' => 'Too many invalid attempts. Verification reset.']);
        exit;
    }

    if ($otp !== (string)($setup['otp'] ?? '')) {
        $_SESSION['new_admin_setup']['attempts'] = ((int)$setup['attempts']) + 1;
        echo json_encode(['ok' => false, 'message' => 'Incorrect verification code.']);
        exit;
    }

    $_SESSION['new_admin_setup']['otp_verified'] = true;
    echo json_encode(['ok' => true, 'message' => 'OTP verified successfully. Please set your new password.']);
    exit;
}

if (($_GET['action'] ?? '') === 'submit_setup_password') {
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    $newPw = (string)($data['new_password'] ?? '');
    $confirmPw = (string)($data['confirm_password'] ?? '');

    $setup = $_SESSION['new_admin_setup'] ?? null;
    if (!$setup || !is_array($setup) || empty($setup['otp_verified'])) {
        echo json_encode(['ok' => false, 'message' => 'Session expired or OTP not verified. Please start over.']);
        exit;
    }

    if ($newPw !== $confirmPw) {
        echo json_encode(['ok' => false, 'message' => 'Passwords do not match.']);
        exit;
    }
    if (strlen($newPw) < 8) {
        echo json_encode(['ok' => false, 'message' => 'Password must be at least 8 characters long.']);
        exit;
    }
    if (!preg_match('/[A-Z]/', $newPw)) {
        echo json_encode(['ok' => false, 'message' => 'Password must contain at least one uppercase letter (A-Z).']);
        exit;
    }
    if (!preg_match('/[0-9]/', $newPw)) {
        echo json_encode(['ok' => false, 'message' => 'Password must contain at least one number (0-9).']);
        exit;
    }
    if (!preg_match('/[!@#$%^&*(),.?":{}|<>_+-]/', $newPw)) {
        echo json_encode(['ok' => false, 'message' => 'Password must contain at least one special character (!@#$%^&*...).']);
        exit;
    }

    $adminId = (int)$setup['admin_id'];
    $hash = password_hash($newPw, PASSWORD_BCRYPT);

    try {
        db_exec(
            "UPDATE admin_user
             SET password_hash = :hash, is_active = 1, setup_pending = 0, updated_at = NOW()
             WHERE admin_id = :id",
            [':hash' => $hash, ':id' => $adminId]
        );

        $_SESSION['admin'] = [
            'admin_id' => $adminId,
            'full_name' => (string)($setup['full_name'] ?: $setup['username']),
            'username' => (string)$setup['username'],
            'email' => (string)$setup['email'],
            'role' => 'ADMIN',
            'photo_path' => ''
        ];
        $_SESSION['admin_session_token'] = bin2hex(random_bytes(16));
        db_exec("UPDATE admin_user SET active_session_token = :t, active_session_ip = :ip, last_active = NOW() WHERE admin_id = :id", [
            ':t' => $_SESSION['admin_session_token'],
            ':ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            ':id' => $adminId
        ]);

        unset($_SESSION['new_admin_setup']);

        echo json_encode([
            'ok' => true,
            'message' => 'Account setup complete! Logging you into Dashboard...',
            'redirect' => 'dashboard.php'
        ]);
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'message' => 'Failed to save password: ' . $e->getMessage()]);
    }
    exit;
}

// If admin is ALREADY logged in, redirect to dashboard
if (isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id'])) {
    redirect('dashboard.php');
    exit;
}

$errors = [];
$isLocked = false;
$remainingSeconds = 0;
$lockoutAttempts = (int)($_SESSION['admin_login_attempts'] ?? 0);
$lockoutUntil = (int)($_SESSION['admin_lockout_until'] ?? 0);

if ($lockoutUntil > time()) {
  $isLocked = true;
  $remainingSeconds = $lockoutUntil - time();
} else {
  if ($lockoutUntil > 0) {
    unset($_SESSION['admin_lockout_until']);
    $_SESSION['admin_login_attempts'] = 0;
    $lockoutAttempts = 0;
  }
}

$infoMsg = '';

// Automatically invalidate any active pre-2fa OTP session when arriving back on login.php
if (isset($_SESSION['admin_pre_2fa']) || isset($_SESSION['login_otp'])) {
    unset($_SESSION['admin_pre_2fa']);
    unset($_SESSION['login_otp']);
    unset($_SESSION['login_otp_attempts']);
    if (empty($_SESSION['login_otp_cancelled_msg']) && empty($_SESSION['login_otp_locked_error'])) {
        $_SESSION['login_otp_cancelled_msg'] = "Notice: Your previous verification code was automatically expired. Please log in with your username and password to request a new code.";
    }
}

if (!empty($_SESSION['login_otp_cancelled_msg'])) {
    $infoMsg = $_SESSION['login_otp_cancelled_msg'];
    unset($_SESSION['login_otp_cancelled_msg']);
} elseif (isset($_GET['otp_expired']) && $_GET['otp_expired'] === '1') {
    $infoMsg = "Notice: Your previous verification code was automatically expired. Please log in with your username and password to request a new code.";
}

if ($isLocked) {
    if (!empty($_SESSION['login_otp_locked_error'])) {
        $min = floor($remainingSeconds / 60);
        $sec = str_pad((string)($remainingSeconds % 60), 2, '0', STR_PAD_LEFT);
        $errors[] = str_replace('3:00', "{$min}:{$sec}", $_SESSION['login_otp_locked_error']);
        unset($_SESSION['login_otp_locked_error']);
    } elseif (isset($_GET['error']) && $_GET['error'] === 'otp_locked') {
        $min = floor($remainingSeconds / 60);
        $sec = str_pad((string)($remainingSeconds % 60), 2, '0', STR_PAD_LEFT);
        $errors[] = "LOCKOUT_ERR::Security Lockout: Exceeded maximum 4 invalid OTP attempts. (Try again in <span id=\"lockoutTimer\">{$min}:{$sec}</span>)";
    }
} else {
    unset($_SESSION['login_otp_locked_error']);
}

// Determine initial visibility of password field on page load
$showPasswordField = false;
$initialUsername = (string)($_POST['username'] ?? '');
if (!empty($initialUsername)) {
    $checkAdmin = admin_find_by_username($initialUsername);
    if ($checkAdmin && (int)($checkAdmin['is_active'] ?? 1) === 1) {
        $showPasswordField = true;
    }
}
if (!empty($errors) && !empty($initialUsername)) {
    $showPasswordField = true;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($isLocked) {
    $min = floor($remainingSeconds / 60);
    $sec = str_pad((string)($remainingSeconds % 60), 2, '0', STR_PAD_LEFT);
    $errors[] = "LOCKOUT_ERR::Too many invalid attempts (5/5). (Try again in <span id=\"lockoutTimer\">{$min}:{$sec}</span>)";
  } else {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $res = admin_login($username, $password);
    if (($res['ok'] ?? false) === true) {
      unset($_SESSION['admin_login_attempts']);
      unset($_SESSION['admin_lockout_until']);
      
      // 2FA IMPLEMENTATION
      $adminData = admin_find_by_username($username);
      if (!$adminData) {
          $errors[] = "Critical error: Admin data not found after login.";
      } else {
          $adminRow = db_one("SELECT active_session_token, active_session_ip, last_active FROM admin_user WHERE admin_id = :id", [':id' => (int)$adminData['admin_id']]);
          $hasActiveSession = false;
          $activeIp = (string)($adminRow['active_session_ip'] ?? '');

          if (!empty($adminRow['active_session_token']) && !empty($adminRow['last_active'])) {
              $lastActiveSecs = time() - strtotime((string)$adminRow['last_active']);
              if ($lastActiveSecs < 900) {
                  $hasActiveSession = true;
              }
          }

          $_SESSION['admin_pre_2fa'] = [
              'admin_id' => $adminData['admin_id'],
              'username' => $adminData['username'],
              'full_name' => $adminData['full_name'],
              'email' => $adminData['email'],
              'has_active_session' => $hasActiveSession,
              'active_ip' => $activeIp,
          ];
          unset($_SESSION['admin_id']);
          unset($_SESSION['admin_username']);

          redirect('login_otp.php?init=1');
      }
    } else {
      $lockoutAttempts++;
      $_SESSION['admin_login_attempts'] = $lockoutAttempts;

      if ($lockoutAttempts >= 5) {
        $lockoutUntil = time() + 300; // 5 minutes lockout
        $_SESSION['admin_lockout_until'] = $lockoutUntil;
        $isLocked = true;
        $remainingSeconds = 300;
        $errors[] = "LOCKOUT_ERR::Too many invalid attempts (5/5). (Try again in <span id=\"lockoutTimer\">5:00</span>)";
      } else {
        $remainingAttempts = 5 - $lockoutAttempts;
        $errors[] = (string)($res['error'] ?? 'Login failed.') . " ({$lockoutAttempts}/5 invalid attempts)";
      }
    }
  }
} elseif ($isLocked && empty($errors)) {
  $min = floor($remainingSeconds / 60);
  $sec = str_pad((string)($remainingSeconds % 60), 2, '0', STR_PAD_LEFT);
  $errors[] = "LOCKOUT_ERR::Too many invalid attempts. (Try again in <span id=\"lockoutTimer\">{$min}:{$sec}</span>)";
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Login | SDO Web Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --nu-blue: #36429a;
      --nu-blue-strong: #2d3788;
      --card-bg: #f4f4f5;
      --text: #181818;
      --muted: #8c8c8c;
      --input-bg: #e8e8eb;
      --input-text: #333333;
      --btn-blue: #39439b;
      --btn-blue-hover: #2e3988;
      --danger: #a32b2b;
      --shadow: 0 16px 30px rgba(9, 20, 60, 0.35);
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      min-height: 100vh;
      font-family: 'Montserrat', 'Segoe UI', Tahoma, sans-serif;
      color: var(--text);
      background:
        linear-gradient(180deg, rgba(27, 41, 118, 0.78), rgba(48, 62, 145, 0.78)),
        url('../assets/wallpaper.png');
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
      display: grid;
      place-items: center;
      padding: 18px;
    }

    .panel {
      width: min(460px, 94vw);
      border-radius: 38px;
      background: var(--card-bg);
      border: 1px solid rgba(255, 255, 255, 0.32);
      box-shadow: var(--shadow);
      padding: 28px 30px 24px;
    }

    .brand { text-align: center; margin-bottom: 24px; }

    .brand img {
      width: 78px;
      height: auto;
      display: block;
      margin: 0 auto 12px;
    }

    .brand h1 {
      margin: 0;
      font-size: 2rem;
      font-weight: 800;
      letter-spacing: 0.2px;
    }

    .brand p {
      margin: 6px 0 0;
      font-size: 1rem;
      font-weight: 600;
      color: var(--muted);
    }

    label {
      display: block;
      font-size: 1.24rem;
      font-weight: 700;
      margin: 0 0 8px;
    }

    .field { margin-bottom: 18px; }
    .password-wrap { position: relative; }

    input {
      width: 100%;
      height: 54px;
      border-radius: 14px;
      border: 1px solid transparent;
      background: var(--input-bg);
      color: var(--input-text);
      padding: 0 16px;
      font-size: 1rem;
      outline: none;
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .password-wrap input { padding-right: 52px; }

    .toggle-password {
      position: absolute;
      top: 50%;
      right: 12px;
      transform: translateY(-50%);
      width: 34px;
      height: 34px;
      border: 0;
      border-radius: 10px;
      background: transparent;
      color: #5a6290;
      cursor: pointer;
      font-size: 1.1rem;
      line-height: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .toggle-password .eye-icon { position: relative; display: inline-block; }

    .toggle-password.is-hidden .eye-icon::after {
      content: '';
      position: absolute;
      left: -2px;
      right: -2px;
      top: 50%;
      height: 2px;
      background: currentColor;
      transform: rotate(-35deg);
      transform-origin: center;
      border-radius: 3px;
    }

    .toggle-password:hover { background: rgba(57, 67, 155, 0.1); }

    input::placeholder { color: #b8b8bb; }

    input:focus {
      border-color: rgba(56, 67, 156, 0.65);
      box-shadow: 0 0 0 3px rgba(56, 67, 156, 0.14);
    }

    .btn {
      width: 100%;
      height: 52px;
      border-radius: 13px;
      border: none;
      cursor: pointer;
      background: linear-gradient(180deg, var(--btn-blue), var(--nu-blue-strong));
      color: #f7f8ff;
      font-size: 1.15rem;
      font-weight: 700;
      letter-spacing: 0.2px;
      box-shadow: 0 8px 16px rgba(45, 55, 130, 0.32);
      transition: transform 0.2s ease, background 0.2s ease;
    }

    .btn:hover {
      background: linear-gradient(180deg, #2f3b90, #273279);
      transform: translateY(-1px);
    }

    .forgot {
      display: block;
      text-align: center;
      margin-top: 12px;
      color: #6f7bb7;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.96rem;
    }

    .divider { margin: 22px 0 10px; border-top: 1px solid rgba(24, 24, 24, 0.2); }

    .back-link {
      display: inline-block;
      color: #4f5da5;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.95rem;
    }

    .back-link:hover { text-decoration: underline; }

    .errors {
      margin: 0 0 16px;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid rgba(163, 43, 43, 0.25);
      background: rgba(163, 43, 43, 0.07);
      color: var(--danger);
    }

    @media (max-width: 540px) {
      .panel { border-radius: 30px; padding: 24px 18px 20px; }
      .brand h1 { font-size: 1.74rem; }
      label { font-size: 1.07rem; }
    }
  </style>
</head>

<body>
  <div class="panel" role="main" aria-label="Admin login form">
    <div class="brand">
      <img src="../assets/logo.png" alt="NU Logo">
      <h1>SDO Web Portal</h1>
      <p>Student Discipline Office</p>
    </div>

    <?php if (!empty($infoMsg)): ?>
      <div style="margin: 0 0 16px; padding: 12px 14px; border-radius: 12px; border: 1px solid rgba(54, 66, 154, 0.25); background: rgba(54, 66, 154, 0.08); color: #2d3788; font-size: 13px; font-weight: 600; line-height: 1.4; text-align: left;">
        ⚠️ <?php echo htmlspecialchars($infoMsg); ?>
      </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
      <div class="errors" role="alert">
        <ul style="margin:0; list-style: none; padding-left: 0;">
          <?php foreach ($errors as $err): ?>
            <?php if (strpos($err, 'LOCKOUT_ERR::') === 0): ?>
              <li style="font-weight: 700; color: var(--danger); font-size: 13.5px; line-height: 1.4;"><?php echo str_replace('LOCKOUT_ERR::', '', $err); ?></li>
            <?php else: ?>
              <li style="font-weight: 600; margin-bottom: 3px;">• <?php echo e($err); ?></li>
            <?php endif; ?>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" action="" id="loginForm">
      <div class="field">
        <label for="username">
          Username
          <span id="userStatusBadge" style="<?php echo $showPasswordField ? 'display:inline-block;' : 'display:none;'; ?> font-size:12px; color:#15803d; font-weight:700; margin-left:8px;">✓ Verified</span>
        </label>
        <input
          id="username"
          name="username"
          type="text"
          placeholder="Enter your username"
          required
          autocomplete="username"
          value="<?php echo e((string)($_POST['username'] ?? '')); ?>"
          <?php echo $isLocked ? 'disabled="disabled" style="opacity:0.5; cursor:not-allowed;"' : ''; ?>
        />
      </div>

      <div class="field" id="passwordGroup" style="<?php echo $showPasswordField ? '' : 'display: none; opacity: 0; transform: translateY(-8px);'; ?> transition: opacity 0.3s ease, transform 0.3s ease;">
        <label for="password">Password</label>
        <div class="password-wrap">
          <input
            id="password"
            name="password"
            type="password"
            placeholder="Enter your password"
            autocomplete="current-password"
            <?php echo $showPasswordField ? 'required' : ''; ?>
            <?php echo $isLocked ? 'disabled="disabled" style="opacity:0.5; cursor:not-allowed;"' : ''; ?>
          />
          <button
            type="button"
            class="toggle-password is-hidden"
            id="togglePassword"
            aria-label="Show password"
            aria-controls="password"
            aria-pressed="false"
            <?php echo $isLocked ? 'disabled="disabled" style="opacity:0.5; cursor:not-allowed;"' : ''; ?>
          >
            <span class="eye-icon" aria-hidden="true">&#128065;</span>
          </button>
        </div>
      </div>

      <div id="usernameInlineError" style="display:none; color: #dc2626; background: #fef2f2; border: 1px solid #fecaca; padding: 10px 14px; border-radius: 10px; font-size: 13px; font-weight: 600; margin-bottom: 14px;"></div>

      <button class="btn" id="submitBtn" type="submit" <?php echo $isLocked ? 'disabled="disabled" style="opacity:0.5; cursor:not-allowed;"' : ''; ?>>
        <?php echo $showPasswordField ? 'Login to Dashboard' : 'Next'; ?>
      </button>
      
      <?php if (($_SESSION['admin_login_attempts'] ?? 0) >= 3): ?>
        <a class="forgot" id="forgotPasswordLink" href="<?php echo $isLocked ? 'javascript:void(0);' : 'forgot_password.php'; ?>" <?php echo $isLocked ? 'style="opacity:0.4; cursor:not-allowed; pointer-events:none;" onclick="return false;"' : ''; ?>>Forgot Password?</a>
      <?php endif; ?>

      <div class="divider"></div>
      <a class="back-link" href="index.php">&larr; Back to SDO Landing</a>
    </form>

    <!-- ── First-Time Admin Setup Container ── -->
    <div id="setupContainer" style="display:none; margin-top: 15px;">
      
      <!-- Step 1: Email Input Step -->
      <div id="setupStepEmail">
        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:14px; padding:14px; margin-bottom:16px;">
          <div style="font-size:12px; font-weight:800; color:#1d4ed8; text-transform:uppercase; letter-spacing:0.5px;">⚡ First-Time Account Setup</div>
          <p style="margin:4px 0 0; font-size:13px; color:#1e3a8a; line-height:1.4;">
            Please enter the email address provided to the administrator who created your account.
          </p>
        </div>

        <div class="field">
          <label for="setupEmailInput">Registered Email Address</label>
          <input type="email" id="setupEmailInput" placeholder="e.g. yourname@example.com" />
        </div>

        <div id="setupEmailError" style="display:none; color: var(--danger); font-size: 13px; font-weight: 600; margin-bottom: 14px;"></div>

        <button type="button" class="btn" id="btnRequestSetupOtp">
          Send Setup Verification Code
        </button>
      </div>

      <!-- Step 2: OTP Input Step -->
      <div id="setupStepOtp" style="display:none;">
        <div style="background:#ecfdf5; border:1px solid #a7f3d0; border-radius:14px; padding:14px; margin-bottom:16px;">
          <div style="font-size:12px; font-weight:800; color:#047857; text-transform:uppercase; letter-spacing:0.5px;">📧 Verification Code Sent</div>
          <p id="setupOtpSubtext" style="margin:4px 0 0; font-size:13px; color:#064e3b; line-height:1.4;">
            Check your email inbox for the 6-digit setup code.
          </p>
        </div>

        <div class="field">
          <label for="setupOtpInput">6-Digit Verification Code</label>
          <input type="text" id="setupOtpInput" placeholder="• • • • • •" style="font-size:22px; letter-spacing:8px; text-align:center;" maxlength="6" inputmode="numeric" />
        </div>

        <div id="setupOtpError" style="display:none; color: var(--danger); font-size: 13px; font-weight: 600; margin-bottom: 14px;"></div>

        <button type="button" class="btn" id="btnVerifySetupOtp">
          Verify & Continue Setup
        </button>
      </div>

      <!-- Step 3: Set Password Step -->
      <div id="setupStepPassword" style="display:none;">
        <div style="background:#eff6ff; border:1px solid #bfdbfe; border-radius:14px; padding:14px; margin-bottom:16px;">
          <div style="font-size:12px; font-weight:800; color:#1d4ed8; text-transform:uppercase; letter-spacing:0.5px;">🔒 Set Your New Password</div>
          <p style="margin:4px 0 0; font-size:13px; color:#1e3a8a; line-height:1.4;">
            Create a secure password to complete your account activation.
          </p>
        </div>

        <div class="field">
          <label for="setupNewPassword">New Password</label>
          <div class="password-wrap">
            <input type="password" id="setupNewPassword" placeholder="Enter new password" />
          </div>
        </div>

        <div class="field">
          <label for="setupConfirmPassword">Confirm Password</label>
          <div class="password-wrap">
            <input type="password" id="setupConfirmPassword" placeholder="Re-enter new password" />
          </div>
        </div>

        <!-- Live Rules Checker Card -->
        <div style="background:#fff; border:1.5px solid #e2e8f0; border-radius:14px; padding:14px; margin-bottom:16px;">
          <div style="font-size:11px; font-weight:800; color:#64748b; text-transform:uppercase; letter-spacing:0.5px; margin-bottom:10px;">Password Requirements</div>
          <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; font-size:12px;">
            <div id="supLen" style="color:#64748b; display:flex; align-items:center; gap:6px;"><span>🔴</span> 8+ characters</div>
            <div id="supUp" style="color:#64748b; display:flex; align-items:center; gap:6px;"><span>🔴</span> Uppercase letter</div>
            <div id="supNum" style="color:#64748b; display:flex; align-items:center; gap:6px;"><span>🔴</span> Number</div>
            <div id="supSpc" style="color:#64748b; display:flex; align-items:center; gap:6px;"><span>🔴</span> Special character</div>
            <div id="supMatch" style="grid-column:1/-1; color:#64748b; display:flex; align-items:center; gap:6px;"><span>🔴</span> Passwords match</div>
          </div>
        </div>

        <div id="setupPasswordError" style="display:none; color: var(--danger); font-size: 13px; font-weight: 600; margin-bottom: 14px;"></div>

        <button type="button" class="btn" id="btnSubmitSetupPassword" disabled style="opacity:0.5; cursor:not-allowed;">
          Complete Setup & Log In
        </button>
      </div>

    </div>
  </div>

  <script>
    (function () {
      window.addEventListener('pageshow', function (e) {
        if (e.persisted || (window.performance && window.performance.navigation && window.performance.navigation.type === 2)) {
          window.location.reload();
        }
      });

      var passwordInput = document.getElementById('password');
      var toggleBtn = document.getElementById('togglePassword');
      var usernameInput = document.getElementById('username');
      var passwordGroup = document.getElementById('passwordGroup');
      var submitBtn = document.getElementById('submitBtn');
      var userBadge = document.getElementById('userStatusBadge');
      var inlineErr = document.getElementById('usernameInlineError');
      var loginForm = document.getElementById('loginForm');

      var isPasswordVisible = <?php echo $showPasswordField ? 'true' : 'false'; ?>;
      var isLocked = <?php echo $isLocked ? 'true' : 'false'; ?>;

      if (passwordInput && toggleBtn) {
        toggleBtn.addEventListener('click', function () {
          var isHidden = passwordInput.type === 'password';
          passwordInput.type = isHidden ? 'text' : 'password';
          toggleBtn.classList.toggle('is-hidden', !isHidden);
          toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
          toggleBtn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
        });
      }

      <?php if ($isLocked && $remainingSeconds > 0): ?>
      var remaining = <?php echo (int)$remainingSeconds; ?>;
      var timerEl = document.getElementById('lockoutTimer');
      var forgotLink = document.getElementById('forgotPasswordLink');

      if (usernameInput) { usernameInput.disabled = true; usernameInput.style.opacity = '0.5'; usernameInput.style.cursor = 'not-allowed'; }
      if (passwordInput) { passwordInput.disabled = true; passwordInput.style.opacity = '0.5'; passwordInput.style.cursor = 'not-allowed'; }
      if (toggleBtn) { toggleBtn.disabled = true; toggleBtn.style.opacity = '0.5'; toggleBtn.style.cursor = 'not-allowed'; }
      if (submitBtn) { submitBtn.disabled = true; submitBtn.style.opacity = '0.5'; submitBtn.style.cursor = 'not-allowed'; }
      if (forgotLink) { forgotLink.style.opacity = '0.4'; forgotLink.style.cursor = 'not-allowed'; forgotLink.style.pointerEvents = 'none'; forgotLink.removeAttribute('href'); }

      var interval = setInterval(function() {
        remaining--;
        if (remaining <= 0) {
          clearInterval(interval);
          if (window.history && window.history.replaceState) {
            var url = new URL(window.location.href);
            url.searchParams.delete('error');
            window.history.replaceState(null, '', url.pathname + url.search);
          }
          window.location.href = 'login.php';
        } else {
          var m = Math.floor(remaining / 60);
          var s = remaining % 60;
          var sStr = s < 10 ? '0' + s : s;
          if (timerEl) timerEl.textContent = m + ':' + sStr;
        }
      }, 1000);
      <?php else: ?>

      var setupContainer = document.getElementById('setupContainer');
      var setupStepEmail = document.getElementById('setupStepEmail');
      var setupStepOtp = document.getElementById('setupStepOtp');
      var setupStepPassword = document.getElementById('setupStepPassword');

      var setupEmailInput = document.getElementById('setupEmailInput');
      var btnRequestSetupOtp = document.getElementById('btnRequestSetupOtp');
      var setupEmailError = document.getElementById('setupEmailError');

      var setupOtpInput = document.getElementById('setupOtpInput');
      var btnVerifySetupOtp = document.getElementById('btnVerifySetupOtp');
      var setupOtpError = document.getElementById('setupOtpError');
      var setupOtpSubtext = document.getElementById('setupOtpSubtext');

      var setupNewPassword = document.getElementById('setupNewPassword');
      var setupConfirmPassword = document.getElementById('setupConfirmPassword');
      var btnSubmitSetupPassword = document.getElementById('btnSubmitSetupPassword');
      var setupPasswordError = document.getElementById('setupPasswordError');

      var isPendingSetupState = false;

      function hidePendingSetupState() {
        if (!isPendingSetupState) return;
        isPendingSetupState = false;
        if (setupContainer) setupContainer.style.display = 'none';
        if (submitBtn) submitBtn.style.display = 'block';
      }

      function showPendingSetupState() {
        hidePassword();
        isPendingSetupState = true;
        if (userBadge) {
          userBadge.style.display = 'inline-block';
          userBadge.style.background = '#fef3c7';
          userBadge.style.color = '#d97706';
          userBadge.style.border = '1px solid #fde68a';
          userBadge.style.padding = '2px 8px';
          userBadge.style.borderRadius = '6px';
          userBadge.textContent = '⚡ First-Time Setup Required';
        }
        if (passwordGroup) passwordGroup.style.display = 'none';
        if (submitBtn) submitBtn.style.display = 'none';
        if (inlineErr) inlineErr.style.display = 'none';

        if (setupContainer) setupContainer.style.display = 'block';
        if (setupStepEmail) setupStepEmail.style.display = 'block';
        if (setupStepOtp) setupStepOtp.style.display = 'none';
        if (setupStepPassword) setupStepPassword.style.display = 'none';
      }

      function revealPassword() {
        if (isPasswordVisible) return;
        isPasswordVisible = true;
        if (inlineErr) inlineErr.style.display = 'none';
        if (userBadge) {
          userBadge.style.display = 'inline-block';
          userBadge.style.background = 'transparent';
          userBadge.style.color = '#15803d';
          userBadge.style.border = 'none';
          userBadge.style.padding = '0';
          userBadge.textContent = '✓ Verified';
        }
        
        passwordGroup.style.display = 'block';
        setTimeout(function() {
          passwordGroup.style.opacity = '1';
          passwordGroup.style.transform = 'translateY(0)';
        }, 20);
        
        if (passwordInput) passwordInput.setAttribute('required', 'required');
        if (submitBtn) submitBtn.textContent = 'Login to Dashboard';
        setTimeout(function() { if (passwordInput) passwordInput.focus(); }, 150);
      }

      function hidePassword() {
        if (!isPasswordVisible) return;
        isPasswordVisible = false;
        if (userBadge) userBadge.style.display = 'none';
        passwordGroup.style.opacity = '0';
        passwordGroup.style.transform = 'translateY(-8px)';
        setTimeout(function() {
          if (!isPasswordVisible) passwordGroup.style.display = 'none';
        }, 300);
        if (passwordInput) {
          passwordInput.removeAttribute('required');
          passwordInput.value = '';
        }
        if (submitBtn) submitBtn.textContent = 'Next';
      }

      var checkTimer = null;
      var lastCheckedUser = (usernameInput ? usernameInput.value : '').trim();
      var activeFetchController = null;

      function verifyUsername(onComplete) {
        var val = (usernameInput ? usernameInput.value : '').trim();
        if (!val) {
          hidePassword();
          hidePendingSetupState();
          if (inlineErr) {
            inlineErr.textContent = '❌ Please enter a username.';
            inlineErr.style.display = 'block';
          }
          if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = 'Next';
          }
          if (onComplete) onComplete(false);
          return;
        }

        if (val === lastCheckedUser && isPasswordVisible) {
          if (onComplete) onComplete(true);
          return;
        }

        if (activeFetchController) {
          try { activeFetchController.abort(); } catch (err) {}
          activeFetchController = null;
        }

        if (submitBtn && !isPasswordVisible && !isPendingSetupState) {
          submitBtn.textContent = 'Checking...';
          submitBtn.disabled = true;
        }

        var fetchSignal = null;
        if (window.AbortController) {
          activeFetchController = new AbortController();
          fetchSignal = activeFetchController.signal;
        }

        fetch('login.php?check_username=1&username=' + encodeURIComponent(val), { signal: fetchSignal })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            activeFetchController = null;
            if (submitBtn && !isPasswordVisible && !isPendingSetupState) {
              submitBtn.disabled = false;
              submitBtn.textContent = 'Next';
            }
            lastCheckedUser = val;
            if (res && res.exists) {
              if (inlineErr) inlineErr.style.display = 'none';
              if (res.status === 'PENDING_SETUP') {
                showPendingSetupState();
              } else {
                hidePendingSetupState();
                revealPassword();
              }
              if (onComplete) onComplete(true);
            } else {
              hidePendingSetupState();
              hidePassword();
              if (inlineErr) {
                inlineErr.textContent = '❌ Admin username "' + val + '" not found. Please check your username.';
                inlineErr.style.display = 'block';
              }
              if (onComplete) onComplete(false);
            }
          })
          .catch(function(err) {
            if (err && err.name === 'AbortError') return;
            activeFetchController = null;
            if (submitBtn && !isPasswordVisible && !isPendingSetupState) {
              submitBtn.disabled = false;
              submitBtn.textContent = 'Next';
            }
            hidePendingSetupState();
            hidePassword();
            if (inlineErr) {
              inlineErr.textContent = '❌ Unable to check username. Please try again.';
              inlineErr.style.display = 'block';
            }
            if (onComplete) onComplete(false);
          });
      }

      if (usernameInput) {
        usernameInput.addEventListener('input', function() {
          clearTimeout(checkTimer);
          if (inlineErr) inlineErr.style.display = 'none';
          var val = (usernameInput.value || '').trim();
          if (val !== lastCheckedUser) {
            if (isPasswordVisible) hidePassword();
            if (isPendingSetupState) hidePendingSetupState();
          }
          if (val.length >= 2) {
            checkTimer = setTimeout(function() {
              verifyUsername();
            }, 350);
          } else {
            hidePendingSetupState();
            hidePassword();
          }
        });

        usernameInput.addEventListener('blur', function() {
          if (usernameInput.value.trim().length >= 2 && !isPasswordVisible && !isPendingSetupState) {
            verifyUsername();
          }
        });
      }

      if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
          if (!isPasswordVisible && !isPendingSetupState) {
            e.preventDefault();
            clearTimeout(checkTimer);
            verifyUsername(function(isValid) {
              if (!isValid && usernameInput) {
                usernameInput.focus();
              }
            });
          }
        });
      }

      // Step 1: Request Setup OTP
      if (btnRequestSetupOtp) {
        btnRequestSetupOtp.addEventListener('click', function() {
          var u = (usernameInput ? usernameInput.value : '').trim();
          var email = (setupEmailInput ? setupEmailInput.value : '').trim();

          if (setupEmailError) setupEmailError.style.display = 'none';

          if (!email || !email.includes('@')) {
            if (setupEmailError) {
              setupEmailError.textContent = 'Please enter a valid email address.';
              setupEmailError.style.display = 'block';
            }
            return;
          }

          btnRequestSetupOtp.disabled = true;
          btnRequestSetupOtp.textContent = 'Sending Verification Code...';

          fetch('login.php?action=request_setup_otp', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username: u, email: email })
          })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            btnRequestSetupOtp.disabled = false;
            btnRequestSetupOtp.textContent = 'Send Setup Verification Code';

            if (res && res.ok) {
              if (setupStepEmail) setupStepEmail.style.display = 'none';
              if (setupStepOtp) setupStepOtp.style.display = 'block';
              if (setupOtpSubtext) setupOtpSubtext.textContent = 'Check ' + email + ' for the 6-digit verification code.';
            } else {
              if (setupEmailError) {
                setupEmailError.textContent = (res && res.message) ? res.message : 'Verification failed.';
                setupEmailError.style.display = 'block';
              }
            }
          })
          .catch(function() {
            btnRequestSetupOtp.disabled = false;
            btnRequestSetupOtp.textContent = 'Send Setup Verification Code';
            if (setupEmailError) {
              setupEmailError.textContent = 'Network or server error occurred.';
              setupEmailError.style.display = 'block';
            }
          });
        });
      }

      // Step 2: Verify Setup OTP
      if (btnVerifySetupOtp) {
        btnVerifySetupOtp.addEventListener('click', function() {
          var otp = (setupOtpInput ? setupOtpInput.value : '').trim();
          if (setupOtpError) setupOtpError.style.display = 'none';

          if (!otp || otp.length < 6) {
            if (setupOtpError) {
              setupOtpError.textContent = 'Please enter the 6-digit verification code.';
              setupOtpError.style.display = 'block';
            }
            return;
          }

          btnVerifySetupOtp.disabled = true;
          btnVerifySetupOtp.textContent = 'Verifying Code...';

          fetch('login.php?action=verify_setup_otp', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ otp: otp })
          })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            btnVerifySetupOtp.disabled = false;
            btnVerifySetupOtp.textContent = 'Verify & Continue Setup';

            if (res && res.ok) {
              if (setupStepOtp) setupStepOtp.style.display = 'none';
              if (setupStepPassword) setupStepPassword.style.display = 'block';
            } else {
              if (setupOtpError) {
                setupOtpError.textContent = (res && res.message) ? res.message : 'Invalid code.';
                setupOtpError.style.display = 'block';
              }
            }
          })
          .catch(function() {
            btnVerifySetupOtp.disabled = false;
            btnVerifySetupOtp.textContent = 'Verify & Continue Setup';
            if (setupOtpError) {
              setupOtpError.textContent = 'Network error occurred.';
              setupOtpError.style.display = 'block';
            }
          });
        });
      }

      // Live Password Checker
      function updateRuleUI(el, ok) {
        if (!el) return;
        var icon = el.querySelector('span');
        if (ok) {
          el.style.color = '#15803d';
          el.style.fontWeight = '600';
          if (icon) icon.textContent = '🟢';
        } else {
          el.style.color = '#64748b';
          el.style.fontWeight = '400';
          if (icon) icon.textContent = '🔴';
        }
      }

      function checkSetupPwRules() {
        var pw = (setupNewPassword ? setupNewPassword.value : '');
        var confirmPw = (setupConfirmPassword ? setupConfirmPassword.value : '');

        var isLen = pw.length >= 8;
        var isUp = /[A-Z]/.test(pw);
        var isNum = /[0-9]/.test(pw);
        var isSpc = /[!@#$%^&*(),.?":{}|<>_+-]/.test(pw);
        var isMatch = pw.length > 0 && pw === confirmPw;

        updateRuleUI(document.getElementById('supLen'), isLen);
        updateRuleUI(document.getElementById('supUp'), isUp);
        updateRuleUI(document.getElementById('supNum'), isNum);
        updateRuleUI(document.getElementById('supSpc'), isSpc);
        updateRuleUI(document.getElementById('supMatch'), isMatch);

        var allValid = isLen && isUp && isNum && isSpc && isMatch;
        if (btnSubmitSetupPassword) {
          btnSubmitSetupPassword.disabled = !allValid;
          btnSubmitSetupPassword.style.opacity = allValid ? '1' : '0.5';
          btnSubmitSetupPassword.style.cursor = allValid ? 'pointer' : 'not-allowed';
        }
      }

      if (setupNewPassword) setupNewPassword.addEventListener('input', checkSetupPwRules);
      if (setupConfirmPassword) setupConfirmPassword.addEventListener('input', checkSetupPwRules);

      // Step 3: Submit Setup Password
      if (btnSubmitSetupPassword) {
        btnSubmitSetupPassword.addEventListener('click', function() {
          var pw = (setupNewPassword ? setupNewPassword.value : '');
          var confirmPw = (setupConfirmPassword ? setupConfirmPassword.value : '');

          if (setupPasswordError) setupPasswordError.style.display = 'none';

          btnSubmitSetupPassword.disabled = true;
          btnSubmitSetupPassword.textContent = 'Finalizing Setup...';

          fetch('login.php?action=submit_setup_password', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ new_password: pw, confirm_password: confirmPw })
          })
          .then(function(r) { return r.json(); })
          .then(function(res) {
            btnSubmitSetupPassword.disabled = false;
            btnSubmitSetupPassword.textContent = 'Complete Setup & Log In';

            if (res && res.ok) {
              window.location.href = res.redirect || 'dashboard.php';
            } else {
              if (setupPasswordError) {
                setupPasswordError.textContent = (res && res.message) ? res.message : 'Setup failed.';
                setupPasswordError.style.display = 'block';
              }
            }
          })
          .catch(function() {
            btnSubmitSetupPassword.disabled = false;
            btnSubmitSetupPassword.textContent = 'Complete Setup & Log In';
            if (setupPasswordError) {
              setupPasswordError.textContent = 'Network error occurred.';
              setupPasswordError.style.display = 'block';
            }
          });
        });
      }
      <?php endif; ?>
    })();
  </script>
</body>
</html>
