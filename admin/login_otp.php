<?php
// File: admin/login_otp.php
require_once __DIR__ . '/../database/database.php';
require_once __DIR__ . '/otp_mailer.php';

if (session_status() === PHP_SESSION_NONE) session_start();

// If admin is ALREADY logged in, redirect to dashboard immediately (do NOT send OTP)
if (isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id'])) {
    redirect('dashboard.php');
    exit;
}

// Ensure the user is in the pre-2fa state
if (!isset($_SESSION['admin_pre_2fa'])) {
    redirect('login.php');
    exit;
}

$adminPre = $_SESSION['admin_pre_2fa'];
$targetEmail = $adminPre['email'];
$maskedEmail = substr($targetEmail, 0, 3) . "..." . substr($targetEmail, strpos($targetEmail, '@'));
$error = '';
$success = '';

$hasActiveOtp = isset($_SESSION['login_otp']['code']) && (time() < ($_SESSION['login_otp']['expires'] ?? 0));
$isResendRequest = isset($_GET['resend']) && $_GET['resend'] === '1';

// Send new OTP email ONLY if no active OTP exists OR if explicitly requested via resend button
if (!$hasActiveOtp || $isResendRequest) {
    // 3-minute cooldown check (180 seconds) for resending
    if (isset($_SESSION['login_otp']['last_sent'])) {
        $elapsed = time() - $_SESSION['login_otp']['last_sent'];
        if ($elapsed < 180) {
            $wait = 180 - $elapsed;
            $error = "Please wait <strong id=\"topTimer\">{$wait}</strong> seconds before requesting a new code.";
            if ($hasActiveOtp) {
                $success = "A verification code was previously sent to " . htmlspecialchars($maskedEmail);
            }
            goto render_page; // Skip sending
        }
    }

    $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    
    $_SESSION['login_otp'] = [
        'code' => $otp,
        'expires' => time() + 600, // 10 minutes validity
        'last_sent' => time()      // Track when it was sent
    ];

    $emailSent = false;
    try {
        $emailSent = send_admin_otp_email($targetEmail, $adminPre['full_name'], 'Admin Login', $otp);
    } catch (Exception $e) {
        $emailSent = false;
    }

    if ($emailSent) {
        $success = "A verification code has been sent to " . htmlspecialchars($maskedEmail);
    } else {
        $success = "Verification code generated for " . htmlspecialchars($maskedEmail) . "! (Email server delayed). Code: <strong style='font-size:18px; color:#1b2976; letter-spacing:2px;'>{$otp}</strong>";
    }
} else {
    // Active OTP code exists and page was simply reloaded
    $success = "A verification code has been sent to " . htmlspecialchars($maskedEmail);
}

render_page: // Label for skipping OTP generation during cooldown


// Handle OTP Verification
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $enteredOtp = trim((string)$_POST['otp']);
    $sessionOtp = $_SESSION['login_otp'] ?? null;
    
    if (!$sessionOtp) {
        $error = "OTP session expired. Please go back and try again.";
    } elseif (time() > $sessionOtp['expires']) {
        $error = "Code expired. Please request a new one.";
    } elseif ($enteredOtp !== $sessionOtp['code']) {
        $error = "Invalid verification code. Please check your email.";
    } else {
        // Success! Finalize login and take over active session
        $newToken = bin2hex(random_bytes(16));
        $userIp = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';

        db_exec(
            "UPDATE admin_user SET active_session_token = :token, active_session_ip = :ip, last_active = NOW() WHERE admin_id = :id",
            [':token' => $newToken, ':ip' => $userIp, ':id' => (int)$adminPre['admin_id']]
        );

        $_SESSION['admin_id'] = $adminPre['admin_id'];
        $_SESSION['admin_username'] = $adminPre['username'];
        $_SESSION['admin_session_token'] = $newToken;

        unset($_SESSION['admin_pre_2fa']);
        unset($_SESSION['login_otp']);
        
        redirect('login_success.php');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Verify Login | IdentiTrack</title>
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
      margin: 0;
      min-height: 100vh;
      font-family: 'Montserrat', sans-serif;
      background: linear-gradient(135deg, #1b2976 0%, #303e91 100%);
      display: grid;
      place-items: center;
      padding: 20px;
    }

    .panel {
      width: min(440px, 100%);
      background: var(--card-bg);
      border-radius: 32px;
      padding: 40px 30px;
      box-shadow: 0 20px 50px rgba(0,0,0,0.3);
      text-align: center;
    }

    .logo { width: 80px; margin-bottom: 20px; }
    h1 { font-size: 24px; font-weight: 800; margin: 0 0 10px; color: var(--text); }
    p { color: var(--muted); font-size: 14px; line-height: 1.5; margin-bottom: 30px; }

    .otp-input-group { margin-bottom: 25px; }
    input {
      width: 100%;
      height: 60px;
      border-radius: 16px;
      border: 2px solid transparent;
      background: var(--input-bg);
      text-align: center;
      font-size: 28px;
      font-weight: 800;
      letter-spacing: 12px;
      color: var(--nu-blue);
      outline: none;
      transition: all 0.2s;
    }
    input:focus { border-color: var(--nu-blue); background: #fff; box-shadow: 0 0 0 4px rgba(54,66,154,0.1); }

    .btn {
      width: 100%;
      height: 55px;
      border-radius: 16px;
      border: none;
      background: var(--btn-blue);
      color: #fff;
      font-size: 16px;
      font-weight: 700;
      cursor: pointer;
      transition: transform 0.2s, background 0.2s;
    }
    .btn:hover { background: var(--nu-blue-strong); transform: translateY(-1px); }

    .msg { padding: 12px; border-radius: 12px; margin-bottom: 20px; font-size: 13px; font-weight: 600; }
    .msg-error { background: #fee2e2; color: var(--danger); border: 1px solid #fecaca; }
    .msg-success { background: #dcfce7; color: var(--success); border: 1px solid #bbf7d0; }

    .resend { margin-top: 20px; font-size: 13px; color: var(--muted); }
    .resend a { color: var(--nu-blue); text-decoration: none; font-weight: 700; }
  </style>
</head>
<body>
  <div class="panel">
    <img src="../assets/logo.png" alt="Logo" class="logo">
    <h1>Verification Required</h1>
    <p>Please enter the 6-digit code sent to your registered email address to continue.</p>

    <?php if ($error): ?>
      <div class="msg msg-error" id="topMsgError"><?php echo $error; ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="msg msg-success"><?php echo $success; ?></div>
    <?php endif; ?>

    <form method="POST" action="login_otp.php">
      <div class="otp-input-group">
        <input type="text" name="otp" maxlength="6" placeholder="000000" autocomplete="one-time-code" required autofocus>
      </div>
      <button type="submit" class="btn">Verify & Login</button>
    </form>

<?php
    $cooldown = 0;
    if (isset($_SESSION['login_otp']['last_sent'])) {
        $elapsed = time() - $_SESSION['login_otp']['last_sent'];
        if ($elapsed < 180) $cooldown = 180 - $elapsed;
    }
    ?>

    <div class="resend" id="resendContainer">
      Didn't receive the code? <br>
      <?php if ($cooldown > 0): ?>
        <span id="cooldownText">Resend available in <strong id="timer"><?php echo $cooldown; ?></strong>s</span>
        <a href="login_otp.php?resend=1" id="resendLink" style="display:none;">Resend Verification Code</a>
      <?php else: ?>
        <a href="login_otp.php?resend=1" id="resendLink">Resend Verification Code</a>
      <?php endif; ?>
    </div>
  </div>

  <script>
    (function() {
        if (window.history.replaceState) {
            window.history.replaceState({}, document.title, window.location.pathname);
        }
        let timeLeft = <?php echo $cooldown; ?>;
        const timerSpan = document.getElementById('timer');
        const topTimerSpan = document.getElementById('topTimer');
        const cooldownText = document.getElementById('cooldownText');
        const resendLink = document.getElementById('resendLink');

        if (timeLeft > 0) {
            const interval = setInterval(() => {
                timeLeft--;
                if (timerSpan) timerSpan.textContent = timeLeft;
                if (topTimerSpan) topTimerSpan.textContent = timeLeft;

                if (timeLeft <= 0) {
                    clearInterval(interval);
                    if (cooldownText) cooldownText.style.display = 'none';
                    if (resendLink) resendLink.style.display = 'inline';
                    const topMsg = document.getElementById('topMsgError');
                    if (topMsg) topMsg.style.display = 'none';
                }
            }, 1000);
        }
    })();
  </script>
</body>
</html>
