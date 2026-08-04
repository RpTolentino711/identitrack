<?php
// File: admin/forgot_password.php
require_once __DIR__ . '/../database/database.php';
require_once __DIR__ . '/otp_mailer.php';

if (session_status() === PHP_SESSION_NONE) session_start();

$step = $_SESSION['forgot_pw']['step'] ?? 1;
$error = '';
$success = '';
$identityMode = $_GET['mode'] ?? $_POST['identity_mode'] ?? 'username';
if ($identityMode !== 'email') $identityMode = 'username';

// --- Reset / Clear flow ---
if (isset($_GET['reset'])) {
    unset($_SESSION['forgot_pw']);
    header('Location: forgot_password.php');
    exit;
}

// --- Step 1: Identify Account ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_identify'])) {
    $identifier = trim((string)($_POST['identifier'] ?? ''));
    $identityMode = $_POST['identity_mode'] ?? 'username';

    if ($identifier === '') {
        $error = ($identityMode === 'email') ? "Please enter your registered email." : "Please enter your admin username.";
    } else {
        if ($identityMode === 'email') {
            $admin = admin_find_by_email($identifier);
        } else {
            $admin = admin_find_by_username($identifier);
        }

        if (!$admin || (int)($admin['is_active'] ?? 0) !== 1) {
            $error = ($identityMode === 'email') 
                ? "No active admin account found for email '$identifier'."
                : "No active admin account found for username '$identifier'.";
        } else {
            $_SESSION['forgot_pw'] = [
                'step' => 2,
                'admin_id' => (int)$admin['admin_id'],
                'username' => (string)$admin['username'],
                'email' => (string)$admin['email'],
                'full_name' => (string)$admin['full_name'],
                'otp_verified' => false
            ];
            header('Location: forgot_password.php?init=1');
            exit;
        }
    }
}

// --- Step 2: Send/Resend OTP (GET) ---
if ($step == 2 && $_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['init'])) {
    if (isset($_SESSION['forgot_pw']['last_sent'])) {
        $elapsed = time() - $_SESSION['forgot_pw']['last_sent'];
        if ($elapsed < 180) {
            $wait = 180 - $elapsed;
            $error = "Please wait <strong id=\"topTimer\">{$wait}</strong> seconds before requesting a new code.";
            goto render;
        }
    }

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['forgot_pw']['code'] = $otp;
    $_SESSION['forgot_pw']['expires'] = time() + 300;
    $_SESSION['forgot_pw']['last_sent'] = time();

    $targetEmail = $_SESSION['forgot_pw']['email'] ?? 'identitrack@identitrack.site';
    if (empty($targetEmail)) $targetEmail = 'identitrack@identitrack.site';

    try {
        if (send_admin_otp_email($targetEmail, $_SESSION['forgot_pw']['full_name'], 'Password Reset', $otp)) {
            $parts = explode('@', $targetEmail);
            $maskedEmail = (strlen($parts[0]) > 2) ? substr($parts[0], 0, 2) . '***@' . ($parts[1] ?? '') : $targetEmail;
            $success = "A 6-digit verification code has been sent to your email ($maskedEmail).";
        } else {
            $error = "Failed to send verification code email. Please try again.";
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
        $error = "Verification code has expired. Please request a new code.";
    } elseif ($entered !== $stored) {
        $error = "Invalid verification code. Please check your email and try again.";
    } else {
        $_SESSION['forgot_pw']['step'] = 3;
        $_SESSION['forgot_pw']['otp_verified'] = true;
        unset($_SESSION['forgot_pw']['code']);
        header('Location: forgot_password.php');
        exit;
    }
}

// --- Step 3: Update Username & Reset Password (POST) ---
if ($step == 3 && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_reset'])) {
    if (!($_SESSION['forgot_pw']['otp_verified'] ?? false)) {
        header('Location: forgot_password.php?reset=1');
        exit;
    }

    $adminId = (int)($_SESSION['forgot_pw']['admin_id'] ?? 0);
    $currentUsername = $_SESSION['forgot_pw']['username'] ?? '';
    $newUsername = trim((string)($_POST['username'] ?? ''));
    $pw1 = (string)($_POST['new_password'] ?? '');
    $pw2 = (string)($_POST['confirm_password'] ?? '');

    // Validate Username
    if ($newUsername === '') {
        $error = "Username cannot be empty.";
    } elseif (strtolower($newUsername) !== strtolower($currentUsername)) {
        $taken = db_one(
            "SELECT admin_id FROM admin_user WHERE LOWER(username) = :u AND admin_id != :id LIMIT 1",
            [':u' => strtolower($newUsername), ':id' => $adminId]
        );
        if ($taken) {
            $error = "Username '$newUsername' is already taken by another account.";
        }
    }

    if (!$error) {
        // Validate Password Rules
        if (strlen($pw1) < 8) {
            $error = "Password must be at least 8 characters long.";
        } elseif (!preg_match('/[A-Z]/', $pw1)) {
            $error = "Password must contain at least one uppercase letter (A-Z).";
        } elseif (!preg_match('/[a-z]/', $pw1)) {
            $error = "Password must contain at least one lowercase letter (a-z).";
        } elseif (!preg_match('/[0-9]/', $pw1)) {
            $error = "Password must contain at least one number (0-9).";
        } elseif (!preg_match('/[\W_]/', $pw1)) {
            $error = "Password must contain at least one special character (!@#$%^&*).";
        } elseif ($pw1 !== $pw2) {
            $error = "Passwords do not match.";
        } else {
            $hash = password_hash($pw1, PASSWORD_DEFAULT);
            db_exec(
                "UPDATE admin_user SET username = :u, password_hash = :p, updated_at = NOW() WHERE admin_id = :id",
                [':u' => $newUsername, ':p' => $hash, ':id' => $adminId]
            );

            unset($_SESSION['forgot_pw']);
            unset($_SESSION['admin_login_attempts']);
            unset($_SESSION['admin_lockout_until']);

            $updatedUsername = $newUsername;
            $success_final = true;
        }
    }
}

render:
$step = $_SESSION['forgot_pw']['step'] ?? 1;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Reset Password | IdentiTrack Admin</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --nu-blue: #36429a;
      --nu-blue-strong: #2d3788;
      --card-bg: #f4f4f5;
      --text: #181818;
      --muted: #64748b;
      --input-bg: #e8e8eb;
      --btn-blue: #39439b;
      --danger: #dc2626;
      --success: #16a34a;
    }

    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh;
      font-family: 'Montserrat', sans-serif;
      background: linear-gradient(135deg, #1b2976 0%, #303e91 100%);
      display: grid; place-items: center; padding: 20px;
    }

    .panel {
      width: min(480px, 100%);
      background: var(--card-bg);
      border-radius: 38px;
      padding: 38px 34px;
      box-shadow: 0 20px 60px rgba(0,0,0,0.4);
      position: relative;
    }

    .logo { width: 70px; display: block; margin: 0 auto 20px; }
    h1 { font-size: 24px; font-weight: 800; text-align: center; margin: 0 0 10px; color: var(--text); }
    .sub { text-align: center; color: var(--muted); font-size: 13.5px; margin-bottom: 24px; line-height: 1.5; }

    .field { margin-bottom: 18px; }
    label { display: block; font-size: 11px; font-weight: 800; text-transform: uppercase; letter-spacing: 1px; color: var(--text); margin-bottom: 8px; }
    
    .password-wrap { position: relative; }

    input {
      width: 100%; height: 52px; border-radius: 14px; border: 2px solid transparent;
      background: var(--input-bg); padding: 0 18px; font-size: 15px; font-weight: 600;
      color: var(--text); outline: none; transition: all 0.2s;
    }
    input:focus { border-color: var(--nu-blue); background: #fff; box-shadow: 0 0 0 4px rgba(54,66,154,0.12); }
    
    .password-wrap input { padding-right: 50px; }

    .toggle-password {
      position: absolute; right: 12px; top: 50%; transform: translateY(-50%);
      background: none; border: none; cursor: pointer; color: var(--muted);
      padding: 8px; display: flex; align-items: center; justify-content: center;
      transition: color 0.2s;
    }
    .toggle-password:hover { color: var(--nu-blue); }
    .toggle-password svg { width: 20px; height: 20px; }
    
    .otp-input { text-align: center; font-size: 28px; letter-spacing: 10px; height: 62px; font-weight: 800; }

    .btn {
      width: 100%; height: 52px; border-radius: 14px; border: none;
      background: var(--btn-blue); color: #fff; font-size: 15px; font-weight: 700;
      cursor: pointer; transition: all 0.2s; margin-top: 6px;
    }
    .btn:hover { background: var(--nu-blue-strong); transform: translateY(-1px); }

    .msg { padding: 14px; border-radius: 14px; margin-bottom: 22px; font-size: 13px; font-weight: 600; line-height: 1.5; }
    .msg-error { background: #fee2e2; color: var(--danger); border: 1px solid #fecaca; }
    .msg-success { background: #dcfce7; color: var(--success); border: 1px solid #bbf7d0; }

    .mode-switcher {
      display: flex; gap: 8px; margin-bottom: 20px; background: #e2e8f0; padding: 4px; border-radius: 14px;
    }
    .mode-btn {
      flex: 1; height: 40px; border-radius: 11px; border: none; font-size: 12.5px; font-weight: 700;
      cursor: pointer; background: transparent; color: #64748b; transition: all 0.2s;
    }
    .mode-btn.active { background: #ffffff; color: var(--nu-blue); box-shadow: 0 2px 6px rgba(0,0,0,0.08); }

    .req-list {
      background: #ffffff; border-radius: 14px; padding: 12px 14px; margin-top: 8px; border: 1px solid #e2e8f0; font-size: 12px; font-weight: 600; color: #64748b;
    }
    .req-item { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
    .req-item:last-child { margin-bottom: 0; }
    .req-item.valid { color: var(--success); font-weight: 700; }
    .req-item.invalid { color: #94a3b8; }

    .match-badge {
      font-size: 12.5px; font-weight: 700; margin-top: 6px; display: flex; align-items: center; gap: 4px;
    }
    .match-badge.valid { color: var(--success); }
    .match-badge.invalid { color: var(--danger); }

    .footer-links { margin-top: 22px; text-align: center; font-size: 13px; }
    .footer-links a { color: var(--nu-blue); text-decoration: none; font-weight: 700; }
    
    .timer { font-weight: 800; color: var(--nu-blue); }

    .success-final { text-align: center; }
    .success-final svg { width: 64px; height: 64px; color: var(--success); margin-bottom: 16px; }
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
        <h1>Credentials Updated!</h1>
        <p class="sub">Your admin username and password have been updated successfully.<br><strong>Username:</strong> <?php echo htmlspecialchars($updatedUsername ?? ''); ?></p>
        <button class="btn" onclick="window.location='login.php'">Back to Login</button>
      </div>
    <?php else: ?>

      <h1>Reset Password</h1>
      <p class="sub">
        <?php 
          if ($step == 1) echo "Search for your admin account using your username or registered email.";
          elseif ($step == 2) echo "We've sent a 6-digit verification code to your registered email.";
          elseif ($step == 3) echo "Identity verified! You can update your username and set a new password below.";
        ?>
      </p>

      <?php if ($error): ?>
        <div class="msg msg-error" id="topMsgError"><?php echo $error; ?></div>
      <?php endif; ?>
      <?php if ($success): ?>
        <div class="msg msg-success"><?php echo htmlspecialchars($success); ?></div>
      <?php endif; ?>

      <?php if ($step == 1): ?>
        <div class="mode-switcher">
          <button type="button" class="mode-btn <?php echo $identityMode === 'username' ? 'active' : ''; ?>" onclick="setMode('username')">👤 Username</button>
          <button type="button" class="mode-btn <?php echo $identityMode === 'email' ? 'active' : ''; ?>" onclick="setMode('email')">✉️ Registered Email</button>
        </div>

        <form method="POST">
          <input type="hidden" name="identity_mode" id="identity_mode" value="<?php echo htmlspecialchars($identityMode); ?>">
          <div class="field">
            <label id="inputLabel"><?php echo $identityMode === 'email' ? 'Registered Email' : 'Username'; ?></label>
            <input 
              type="<?php echo $identityMode === 'email' ? 'email' : 'text'; ?>" 
              name="identifier" 
              id="identifierInput"
              placeholder="<?php echo $identityMode === 'email' ? 'Enter registered email address' : 'Enter admin username'; ?>" 
              required 
              autofocus
            >
          </div>
          <button type="submit" name="action_identify" class="btn">Find Account &rarr;</button>
        </form>
        
        <div class="footer-links" style="margin-top: 16px;">
          <a href="javascript:void(0);" id="toggleHelpBtn" onclick="toggleModeFromLink()">
            <?php echo $identityMode === 'email' ? 'Remember your username? Search by Username' : "Can't remember your username? Search by Registered Email"; ?>
          </a>
        </div>

        <script>
          function setMode(mode) {
            document.getElementById('identity_mode').value = mode;
            const label = document.getElementById('inputLabel');
            const input = document.getElementById('identifierInput');
            const toggleLink = document.getElementById('toggleHelpBtn');
            const btns = document.querySelectorAll('.mode-btn');

            btns[0].classList.toggle('active', mode === 'username');
            btns[1].classList.toggle('active', mode === 'email');

            if (mode === 'email') {
              label.textContent = 'Registered Email';
              input.type = 'email';
              input.placeholder = 'Enter registered email address';
              toggleLink.textContent = 'Remember your username? Search by Username';
            } else {
              label.textContent = 'Username';
              input.type = 'text';
              input.placeholder = 'Enter admin username';
              toggleLink.textContent = "Can't remember your username? Search by Registered Email";
            }
            input.focus();
          }

          function toggleModeFromLink() {
            const current = document.getElementById('identity_mode').value;
            setMode(current === 'email' ? 'username' : 'email');
          }
        </script>

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
                  const topTimer = document.getElementById('topTimer');
                  const wrap = document.getElementById('cooldownWrap');
                  const link = document.getElementById('resendLink');
                  const itv = setInterval(() => {
                      t--;
                      if (timer) timer.textContent = t;
                      if (topTimer) topTimer.textContent = t;
                      if (t <= 0) {
                          clearInterval(itv);
                          if (wrap) wrap.style.display = 'none';
                          if (link) link.style.display = 'inline';
                          const topMsg = document.getElementById('topMsgError');
                          if (topMsg) topMsg.style.display = 'none';
                      }
                  }, 1000);
              }
          })();
        </script>

      <?php elseif ($step == 3): ?>
        <form method="POST" id="resetPwForm">
          <div class="field">
            <label>Username</label>
            <input type="text" name="username" id="username_field" value="<?php echo htmlspecialchars($_SESSION['forgot_pw']['username'] ?? ''); ?>" required placeholder="Enter username">
            <div style="font-size:11.5px; color:var(--muted); margin-top:4px; font-weight:600;">You can edit your username above if you wish to change it.</div>
          </div>

          <div class="field">
            <label>New Password</label>
            <div class="password-wrap">
              <input type="password" name="new_password" id="new_password" placeholder="Enter new password" required autofocus autocomplete="new-password">
              <button type="button" class="toggle-password" onclick="togglePw('new_password', this)" aria-label="Toggle password">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              </button>
            </div>
            <!-- Live Password Requirements Checklist -->
            <div class="req-list">
              <div id="req-len" class="req-item invalid"><span>•</span> 8+ characters long</div>
              <div id="req-upper" class="req-item invalid"><span>•</span> At least 1 uppercase letter (A-Z)</div>
              <div id="req-lower" class="req-item invalid"><span>•</span> At least 1 lowercase letter (a-z)</div>
              <div id="req-num" class="req-item invalid"><span>•</span> At least 1 number (0-9)</div>
              <div id="req-spec" class="req-item invalid"><span>•</span> At least 1 special character (!@#$%^&*)</div>
            </div>
          </div>

          <div class="field">
            <label>Confirm New Password</label>
            <div class="password-wrap">
              <input type="password" name="confirm_password" id="confirm_password" placeholder="Repeat new password" required autocomplete="new-password">
              <button type="button" class="toggle-password" onclick="togglePw('confirm_password', this)" aria-label="Toggle password">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              </button>
            </div>
            <!-- Live Password Match Feedback -->
            <div id="match-status" class="match-badge"></div>
          </div>

          <button type="submit" name="action_reset" class="btn">Update Credentials</button>
        </form>

        <script>
          (function() {
            const pwInput = document.getElementById('new_password');
            const confirmInput = document.getElementById('confirm_password');
            const matchStatus = document.getElementById('match-status');

            const reqLen = document.getElementById('req-len');
            const reqUpper = document.getElementById('req-upper');
            const reqLower = document.getElementById('req-lower');
            const reqNum = document.getElementById('req-num');
            const reqSpec = document.getElementById('req-spec');

            function updateReq(el, isValid) {
              if (isValid) {
                el.className = 'req-item valid';
                el.children[0].textContent = '✓';
              } else {
                el.className = 'req-item invalid';
                el.children[0].textContent = '•';
              }
            }

            function validatePassword() {
              const val = pwInput.value || '';
              updateReq(reqLen, val.length >= 8);
              updateReq(reqUpper, /[A-Z]/.test(val));
              updateReq(reqLower, /[a-z]/.test(val));
              updateReq(reqNum, /[0-9]/.test(val));
              updateReq(reqSpec, /[\W_]/.test(val));

              validateMatch();
            }

            function validateMatch() {
              const pw = pwInput.value || '';
              const confirm = confirmInput.value || '';

              if (!confirm) {
                matchStatus.style.display = 'none';
                return;
              }

              matchStatus.style.display = 'flex';
              if (pw === confirm) {
                matchStatus.className = 'match-badge valid';
                matchStatus.innerHTML = '✓ Passwords match';
              } else {
                matchStatus.className = 'match-badge invalid';
                matchStatus.innerHTML = '✕ Passwords do not match';
              }
            }

            if (pwInput) pwInput.addEventListener('input', validatePassword);
            if (confirmInput) confirmInput.addEventListener('input', validateMatch);
          })();
        </script>
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
        
        if (!isPw) {
            btn.innerHTML = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>';
        } else {
            btn.innerHTML = '<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>';
        }
    }
  </script>
</body>
</html>
