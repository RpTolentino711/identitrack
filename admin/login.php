<?php
// File: C:\xampp\htdocs\identitrack\admin\login.php
// Admin Login (USERNAME ONLY + Password)
// Uses ONLY functions from database/database.php

require_once __DIR__ . '/../database/database.php';

header("Cache-Control: no-cache, no-store, must-revalidate, max-age=0");
header("Pragma: no-cache");
header("Expires: 0");

if (session_status() === PHP_SESSION_NONE) session_start();

// AJAX check for registered admin username
if (isset($_GET['check_username'])) {
    header('Content-Type: application/json; charset=utf-8');
    $u = trim((string)($_GET['username'] ?? ''));
    if ($u === '') {
        echo json_encode(['ok' => false, 'exists' => false]);
        exit;
    }
    $admin = admin_find_by_username($u);
    $exists = ($admin && (int)($admin['is_active'] ?? 1) === 1);
    echo json_encode(['ok' => true, 'exists' => $exists]);
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

      <div id="usernameInlineError" style="display:none; color: var(--danger); font-size: 13px; font-weight: 600; margin-bottom: 14px;"></div>

      <button class="btn" id="submitBtn" type="submit" <?php echo $isLocked ? 'disabled="disabled" style="opacity:0.5; cursor:not-allowed;"' : ''; ?>>
        <?php echo $showPasswordField ? 'Login to Dashboard' : 'Next'; ?>
      </button>
      
      <?php if (($_SESSION['admin_login_attempts'] ?? 0) >= 3): ?>
        <a class="forgot" id="forgotPasswordLink" href="<?php echo $isLocked ? 'javascript:void(0);' : 'forgot_password.php'; ?>" <?php echo $isLocked ? 'style="opacity:0.4; cursor:not-allowed; pointer-events:none;" onclick="return false;"' : ''; ?>>Forgot Password?</a>
      <?php endif; ?>

      <div class="divider"></div>
      <a class="back-link" href="index.php">&larr; Back to SDO Landing</a>
    </form>
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

      var checkTimer = null;
      var lastCheckedUser = '';

      function revealPassword() {
        if (isPasswordVisible) return;
        isPasswordVisible = true;
        if (inlineErr) inlineErr.style.display = 'none';
        if (userBadge) userBadge.style.display = 'inline-block';
        
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

      function verifyUsername(onComplete) {
        var val = (usernameInput ? usernameInput.value : '').trim();
        if (!val) {
          hidePassword();
          if (inlineErr) inlineErr.style.display = 'none';
          if (onComplete) onComplete(false);
          return;
        }

        if (val === lastCheckedUser && isPasswordVisible) {
          if (onComplete) onComplete(true);
          return;
        }

        fetch('login.php?check_username=1&username=' + encodeURIComponent(val))
          .then(function(r) { return r.json(); })
          .then(function(res) {
            lastCheckedUser = val;
            if (res && res.exists) {
              revealPassword();
              if (onComplete) onComplete(true);
            } else {
              hidePassword();
              if (inlineErr) inlineErr.style.display = 'none';
              if (onComplete) onComplete(false);
            }
          })
          .catch(function() {
            if (onComplete) onComplete(false);
          });
      }

      if (usernameInput) {
        usernameInput.addEventListener('input', function() {
          clearTimeout(checkTimer);
          if (inlineErr) inlineErr.style.display = 'none';
          var val = (usernameInput.value || '').trim();
          if (val.length >= 2) {
            checkTimer = setTimeout(function() {
              verifyUsername();
            }, 350);
          } else {
            hidePassword();
          }
        });

        usernameInput.addEventListener('blur', function() {
          if (usernameInput.value.trim().length >= 2) {
            verifyUsername();
          }
        });
      }

      if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
          if (!isPasswordVisible) {
            e.preventDefault();
            verifyUsername(function(isValid) {
              if (!isValid && usernameInput) {
                usernameInput.focus();
              }
            });
          }
        });
      }
      <?php endif; ?>
    })();
  </script>
</body>
</html>
