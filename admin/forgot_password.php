<?php
// File: admin/forgot_password.php
require_once __DIR__ . '/../database/database.php';
require_once __DIR__ . '/otp_mailer.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$step = $_SESSION['forgot_pw']['step'] ?? 1;
$error = '';
$success = '';
$username = $_SESSION['forgot_pw']['username'] ?? '';

// --- Reset / Clear flow ---
if (isset($_GET['reset'])) {
    unset($_SESSION['forgot_pw']);
    header('Location: forgot_password.php');
    exit;
}

// --- Step 1: Identify ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_identify'])) {
    $inputUser = trim((string)$_POST['username']);
    $admin = admin_find_by_username($inputUser);
    
    if (!$admin) {
        $error = "Account not found. Please verify your username.";
    } else {
        $_SESSION['forgot_pw'] = [
            'step' => 2,
            'admin_id' => $admin['admin_id'],
            'username' => $admin['username'],
            'full_name' => $admin['full_name'],
            'otp_verified' => false
        ];
        header('Location: forgot_password.php?init=1');
        exit;
    }
}

// --- Step 2: Send/Resend OTP (GET) ---
if ($step == 2 && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['init'])) {
    // 3-minute cooldown check
    if (isset($_SESSION['forgot_pw']['last_sent'])) {
        $elapsed = time() - $_SESSION['forgot_pw']['last_sent'];
        if ($elapsed < 180) {
            $wait = 180 - $elapsed;
            $error = "Please wait {$wait} seconds before requesting a new code.";
            goto render;
        }
    }

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['forgot_pw']['code'] = $otp;
    $_SESSION['forgot_pw']['expires'] = time() + 300;
    $_SESSION['forgot_pw']['last_sent'] = time();

    $targetEmail = 'romeopaolotolentino@gmail.com';
    try {
        if (send_admin_otp_email($targetEmail, $_SESSION['forgot_pw']['full_name'], 'Password Reset', $otp)) {
            $success = "A new verification code has been sent to your email.";
        } else {
            $error = "Failed to send email. Please try again.";
        }
    } catch (Exception $e) {
        $error = "Mailer error: " . $e->getMessage();
    }
}

// --- Step 2: Verify OTP (POST) ---
if ($step == 2 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_verify'])) {
    $entered = trim((string)$_POST['otp']);
    $stored  = $_SESSION['forgot_pw']['code'] ?? '';
    $expiry  = $_SESSION['forgot_pw']['expires'] ?? 0;

    if (!$stored || time() > $expiry) {
        $error = "Code expired. Please request a new one.";
    } elseif ($entered !== $stored) {
        $error = "Invalid verification code.";
    } else {
        $_SESSION['forgot_pw']['step'] = 3;
        $_SESSION['forgot_pw']['otp_verified'] = true;
        unset($_SESSION['forgot_pw']['code']); // Clear code after use
        header('Location: forgot_password.php');
        exit;
    }
}

// --- Step 3: Reset Password (POST) ---
if ($step == 3 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_reset'])) {
    if (!($_SESSION['forgot_pw']['otp_verified'] ?? false)) {
        header('Location: forgot_password.php?reset=1');
        exit;
    }

    $pw1 = (string)$_POST['new_password'];
    $pw2 = (string)$_POST['confirm_password'];

    if (strlen($pw1) < 8) {
        $error = "Password must be at least 8 characters long.";
    } elseif ($pw1 !== $pw2) {
        $error = "Passwords do not match.";
    } else {
        if (admin_set_password_by_username($_SESSION['forgot_pw']['username'], $pw1)) {
            unset($_SESSION['forgot_pw']);
            unset($_SESSION['admin_login_attempts']); // Clear failed attempts too
            $success_final = true;
        } else {
            $error = "Failed to update password. Please try again.";
        }
    }
}

render:
$step = $_SESSION['forgot_pw']['step'] ?? 1; // Refresh step after logic
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Reset Password | IdentiTrack</title>
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
      --btn-blue: #39439b;
      --danger: #dc2626;
      --success: #15803d;
    }

    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh;
      font-family: 'Montserrat', sans-serif;
      background: linear-gradient(135deg, #1b2976 0%, #303e91 100%);
      display: grid; place-items: center; padding: 20px;
    }

    .panel {
      width: min(460px, 100%);
      background: var(--card-bg);
      border-radius: 38px;
      padding: 40px 36px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.4);
      position: relative;
    }

    .logo { width: 70px; display: block; margin: 0 auto 20px; }
    h1 { font-size: 24px; font-weight: 800; text-align: center; margin: 0 0 10px; color: var(--text); }
    .sub { text-align: center; color: var(--muted); font-size: 14px; margin-bottom: 30px; }

    .field { margin-bottom: 20px; }
    label { display: block; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: var(--text); margin-bottom: 8px; }
    
    .password-wrap { position: relative; }

    input {
      width: 100%; height: 54px; border-radius: 14px; border: 2px solid transparent;
      background: var(--input-bg); padding: 0 18px; font-size: 15px; font-weight: 600;
      color: var(--text); outline: none; transition: all 0.2s;
    }
    input:focus { border-color: var(--nu-blue); background: #fff; box-shadow: 0 0 0 4px rgba(54,66,154,0.1); }
    
    .password-wrap input { padding-right: 50px; }

    .toggle-password {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; color: var(--muted);
      padding: 8px; display: flex; align-items: center; justify-content: center;
      transition: color 0.2s;
    }
    .toggle-password:hover { color: var(--nu-blue); }
    .toggle-password svg { width: 20px; height: 20px; }
    
    .otp-input { text-align: center; font-size: 28px; letter-spacing: 10px; height: 64px; }

    .btn {
      width: 100%; height: 54px; border-radius: 14px; border: none;
      background: var(--btn-blue); color: #fff; font-size: 16px; font-weight: 700;
      cursor: pointer; transition: all 0.2s;
    }
    .btn:hover { background: var(--nu-blue-strong); transform: translateY(-1px); }

    .msg { padding: 14px; border-radius: 14px; margin-bottom: 25px; font-size: 13px; font-weight: 600; line-height: 1.5; }
    .msg-error { background: #fee2e2; color: var(--danger); border: 1px solid #fecaca; }
    .msg-success { background: #dcfce7; color: var(--success); border: 1px solid #bbf7d0; }

    .footer-links { margin-top: 24px; text-align: center; font-size: 13px; }
    .footer-links a { color: var(--nu-blue); text-decoration: none; font-weight: 700; }
    
    .timer { font-weight: 800; color: var(--nu-blue); }

    .success-final { text-align: center; }
    .success-final svg { width: 64px; height: 64px; color: var(--success); margin-bottom: 20px; }
  </style>
</head>
<body>
  <div class="panel">
    <img src="../assets/logo.png" alt="Logo" class="logo">

    <?php if (isset($success_final)): ?>
      <div class="success-final">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
        <h1>Password Reset!</h1>
        <p class="sub">Your password has been successfully updated. You can now log in with your new credentials.</p>
        <button class="btn" onclick="window.location='login.php'">Back to Login</button>
      </div>
    <?php else: ?>

      <h1>Reset Password</h1>
      <p class="sub">
        <?php 
          if ($step == 1) echo "Enter your admin username to identify your account.";
          elseif ($step == 2) echo "We've sent a 6-digit code to your registered email.";
          elseif ($step == 3) echo "Identity verified! Please set a strong new password.";
        ?>
      </p>

      <?php if ($error): ?>
        <div class="msg msg-error"><?php echo $error; ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="msg msg-success"><?php echo $success; ?></div>
      <?php endif; ?>

      <?php if ($step == 1): ?>
        <form method="POST">
          <div class="field">
            <label>Username</label>
            <input type="text" name="username" placeholder="Enter admin username" required autofocus>
          </div>
          <button type="submit" name="action_identify" class="btn">Find Account &rarr;</button>
        </form>
      <?php elseif ($step == 2): ?>
        <form method="POST" action="forgot_password.php">
          <div class="field">
            <label>Verification Code</label>
            <input type="text" name="otp" class="otp-input" maxlength="6" placeholder="000000" required autofocus>
          </div>
          <button type="submit" name="action_verify" class="btn">Verify Code</button>
        </form>
        
        <?php
          $cooldown = 0;
          if (isset($_SESSION['forgot_pw']['last_sent'])) {
              $elapsed = time() - $_SESSION['forgot_pw']['last_sent'];
              if ($elapsed < 180) $cooldown = 180 - $elapsed;
          }
        ?>
        <div class="footer-links">
          Didn't get the code? <br>
          <span id="cooldownWrap" style="<?php echo $cooldown <= 0 ? 'display:none' : ''; ?>">
            Resend in <span class="timer" id="timer"><?php echo $cooldown; ?></span>s
          </span>
          <a href="forgot_password.php?init=1" id="resendLink" style="<?php echo $cooldown > 0 ? 'display:none' : ''; ?>">Request New Code</a>
        </div>
        <script>
          (function() {
              let t = <?php echo $cooldown; ?>;
              if (t > 0) {
                  const timer = document.getElementById('timer');
                  const wrap = document.getElementById('cooldownWrap');
                  const link = document.getElementById('resendLink');
                  const itv = setInterval(() => {
                      t--;
                      timer.textContent = t;
                      if (t <= 0) {
                          clearInterval(itv);
                          wrap.style.display = 'none';
                          link.style.display = 'inline';
                      }
                  }, 1000);
              }
          })();
        </script>

      <?php elseif ($step == 3): ?>
        <form method="POST">
          <div class="field">
            <label>New Password</label>
            <div class="password-wrap">
              <input type="password" name="new_password" id="new_password" placeholder="Min 8 characters" required autofocus>
              <button type="button" class="toggle-password" onclick="togglePw('new_password', this)">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              </button>
            </div>
          </div>
          <div class="field">
            <label>Confirm Password</label>
            <div class="password-wrap">
              <input type="password" name="confirm_password" id="confirm_password" placeholder="Repeat password" required>
              <button type="button" class="toggle-password" onclick="togglePw('confirm_password', this)">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              </button>
            </div>
          </div>
          <button type="submit" name="action_reset" class="btn">Update Password</button>
        </form>
      <?php endif; ?>

      <div class="footer-links">
        <a href="login.php" onclick="return confirm('Cancel password reset?')">&larr; Back to Login</a>
      </div>
    <?php endif; ?>
  </div>

  <script>
    function togglePw(id, btn) {
        const input = document.getElementById(id);
        const isPw = input.type === 'password';
        input.type = isPw ? 'text' : 'password';
        btn.style.color = isPw ? 'var(--nu-blue)' : 'var(--muted)';
        
        // Update icon with slash if hidden
        if (!isPw) {
            btn.innerHTML = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
        } else {
            btn.innerHTML = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>';
        }
    }
  </script>
</body>
</html>
