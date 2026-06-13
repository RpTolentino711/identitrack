<?php
session_start();
require_once __DIR__ . '/../database/database.php';
require_once __DIR__ . '/class.phpmailer.php';
require_once __DIR__ . '/class.smtp.php';

if (!isset($_SESSION['upcc_authenticated']) || !upcc_current()) {
    header('Location: upccpanel.php');
    exit;
}

$user = upcc_current();
$upccId = (int)($user['upcc_id'] ?? 0);
$error = '';
$success = '';

// Reload user from DB to get freshest photo
$dbUser = db_one("SELECT * FROM upcc_user WHERE upcc_id = :id", [':id' => $upccId]);
if (!$dbUser) {
    header('Location: upccpanel.php?action=logout');
    exit;
}
$_SESSION['upcc_user']['photo_path'] = $dbUser['photo_path'] ?? '';
$user = $_SESSION['upcc_user'];

$action = $_POST['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($action === 'upload_photo') {
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $tmpPath = $_FILES['photo']['tmp_name'];
            $name = $_FILES['photo']['name'];
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $uploadDir = __DIR__ . '/../assets/profiles/';
                if (!is_dir($uploadDir)) {
                    @mkdir($uploadDir, 0777, true);
                }
                $newFilename = 'upcc_' . $upccId . '_' . time() . '.' . $ext;
                if (move_uploaded_file($tmpPath, $uploadDir . $newFilename)) {
                    $relPath = '../assets/profiles/' . $newFilename;
                    db_exec("UPDATE upcc_user SET photo_path = :path WHERE upcc_id = :id", [':path' => $relPath, ':id' => $upccId]);
                    $success = 'Profile picture updated.';
                    $user['photo_path'] = $relPath;
                    $_SESSION['upcc_user']['photo_path'] = $relPath;
                } else {
                    $error = 'Failed to save uploaded file.';
                }
            } else {
                $error = 'Invalid file type. Only JPG, PNG, and WebP are allowed.';
            }
        } else {
            $error = 'Please select a valid image file.';
        }
    } elseif ($action === 'remove_photo') {
        db_exec("UPDATE upcc_user SET photo_path = '' WHERE upcc_id = :id", [':id' => $upccId]);
        $success = 'Profile picture removed.';
        $user['photo_path'] = '';
        $_SESSION['upcc_user']['photo_path'] = '';
    } elseif ($action === 'request_otp') {
        // Send OTP
        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['upcc_pwd_otp'] = $otp;
        $_SESSION['upcc_pwd_otp_time'] = time();

        try {
            $mail = new PHPMailer(true);
            $mail->CharSet   = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.hostinger.com';
            $mail->Port      = 587;
            $mail->SMTPAuth  = true;
            $mail->SMTPSecure = 'tls';
            $mail->Username = $_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site';
            $mail->Password = $_ENV['SMTP_PASS'] ?? '';
            $mail->Timeout   = 30;

            $mail->setFrom($_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site', 'UPCC Panel');
            $mail->addAddress($user['email'], $user['full_name']);
            
            $mail->isHTML(true);
            $mail->Subject = 'Your UPCC Password Change OTP';
            
            $safeName = htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8');
            $safeOtp  = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
            
            $mail->Body = "
            <!doctype html>
            <html>
            <body style='font-family:sans-serif; background:#f3f4f6; padding:20px;'>
              <div style='max-width:500px; margin:0 auto; background:#fff; padding:20px; border-radius:10px; box-shadow:0 4px 6px rgba(0,0,0,0.1);'>
                <h2 style='color:#1e3a8a; margin-top:0;'>Password Change Verification</h2>
                <p>Hi <b>{$safeName}</b>,</p>
                <p>You have requested to change your password. Use the following One-Time Password (OTP) to proceed:</p>
                <div style='background:#f8fafc; border:1px dashed #c7d2fe; border-radius:8px; padding:15px; text-align:center; font-size:32px; font-weight:bold; letter-spacing:8px; color:#1e3a8a; margin:20px 0;'>
                  {$safeOtp}
                </div>
                <p style='color:#6b7280; font-size:13px;'>This code expires in 5 minutes.</p>
                <p style='color:#9ca3af; font-size:12px;'>If you did not request this change, you can safely ignore this email.</p>
              </div>
            </body>
            </html>";
            
            $mail->send();
            
            $_SESSION['upcc_pwd_step'] = 'verify_otp';
            $success = 'OTP sent to your email address.';
        } catch (Exception $e) {
            $error = 'Failed to send OTP: ' . $e->getMessage();
        }
    } elseif ($action === 'verify_otp') {
        $inputOtp = trim((string)($_POST['otp'] ?? ''));
        if (!isset($_SESSION['upcc_pwd_otp']) || time() - $_SESSION['upcc_pwd_otp_time'] > 300) {
            $error = 'OTP expired. Please request a new one.';
            $_SESSION['upcc_pwd_step'] = '';
        } elseif ($inputOtp !== $_SESSION['upcc_pwd_otp']) {
            $error = 'Invalid OTP.';
        } else {
            $_SESSION['upcc_pwd_step'] = 'change_password';
            $success = 'OTP verified. You can now change your password.';
        }
    } elseif ($action === 'change_password') {
        if (($_SESSION['upcc_pwd_step'] ?? '') !== 'change_password') {
            $error = 'Unauthorized access.';
        } else {
            $newPw = trim((string)($_POST['new_password'] ?? ''));
            $confirmPw = trim((string)($_POST['confirm_password'] ?? ''));

            if ($newPw === '' || $confirmPw === '') {
                $error = 'All fields are required.';
            } elseif ($newPw !== $confirmPw) {
                $error = 'Passwords do not match.';
            } elseif (strlen($newPw) < 8) {
                $error = 'Password must be at least 8 characters.';
            } else {
                upcc_set_password($upccId, $newPw);
                $success = 'Password changed successfully.';
                unset($_SESSION['upcc_pwd_step']);
                unset($_SESSION['upcc_pwd_otp']);
            }
        }
    }
}

$step = $_SESSION['upcc_pwd_step'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>UPCC Settings & Profile</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
/* Modern Premium Glassmorphic Design */
:root {
  --font-heading: 'Outfit', sans-serif;
  --font-body: 'Inter', sans-serif;
  --bg-dark: #0a0a0f;
  --bg-glass: rgba(18, 18, 25, 0.65);
  --bg-card: rgba(255, 255, 255, 0.03);
  --border-glass: rgba(255, 255, 255, 0.08);
  --border-glass-hover: rgba(255, 255, 255, 0.15);
  --accent-primary: #6366f1;
  --success: #10b981;
  --danger: #ef4444;
  --text-main: #f8fafc;
  --text-muted: #94a3b8;
  --radius-lg: 24px;
  --radius-md: 16px;
  --radius-sm: 10px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: var(--font-body);
  background: var(--bg-dark);
  color: var(--text-main);
  min-height: 100vh;
  padding: 40px 20px;
  display: flex; flex-direction: column; align-items: center;
  position: relative; overflow-x: hidden;
}
body::before {
  content: ''; position: fixed; inset: 0; z-index: -2;
  background: radial-gradient(circle at 15% 50%, rgba(99, 102, 241, 0.15), transparent 40%),
              radial-gradient(circle at 85% 30%, rgba(139, 92, 246, 0.15), transparent 40%);
  filter: blur(60px);
  animation: bgPulsate 15s ease-in-out infinite alternate;
}
@keyframes bgPulsate { 0% { opacity: 0.7; transform: scale(1); } 100% { opacity: 1; transform: scale(1.05); } }

.header-row {
  width: 100%; max-width: 1000px;
  margin-bottom: 30px;
  display: flex; align-items: center; justify-content: space-between;
}
.page-title { font-family: var(--font-heading); font-size: 32px; font-weight: 800; background: linear-gradient(to right, #fff, #94a3b8); -webkit-background-clip: text; -webkit-text-fill-color: transparent; }
.back-link {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 18px; border-radius: 12px;
  background: rgba(255,255,255,0.03); border: 1px solid var(--border-glass);
  color: var(--text-main); text-decoration: none; font-size: 14px; font-weight: 600;
  transition: all 0.2s;
}
.back-link:hover { background: rgba(255,255,255,0.08); border-color: var(--border-glass-hover); transform: translateX(-4px); }

.grid-container {
  width: 100%; max-width: 1000px;
  display: grid; grid-template-columns: 340px 1fr; gap: 30px;
}
@media (max-width: 800px) { .grid-container { grid-template-columns: 1fr; } }

/* Glass Panels */
.glass-panel {
  background: var(--bg-card); backdrop-filter: blur(20px); -webkit-backdrop-filter: blur(20px);
  border: 1px solid var(--border-glass); border-radius: var(--radius-lg);
  overflow: hidden; padding: 30px;
  box-shadow: 0 25px 50px rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.05);
}

/* Profile Card specific */
.profile-wrap { text-align: center; }
.avatar-container {
  position: relative; width: 120px; height: 120px; margin: 0 auto 20px;
}
.avatar {
  width: 100%; height: 100%; border-radius: 50%;
  background: linear-gradient(135deg, rgba(255,255,255,0.1), rgba(255,255,255,0.02));
  border: 2px solid var(--border-glass-hover);
  display: grid; place-items: center; font-size: 40px; font-weight: 800; font-family: var(--font-heading); color: #fff;
  box-shadow: 0 15px 35px rgba(0,0,0,0.4), inset 0 2px 10px rgba(255,255,255,0.1);
  background-size: cover; background-position: center;
}
.profile-name { font-family: var(--font-heading); font-size: 24px; font-weight: 700; margin-bottom: 4px; }
.profile-role { font-size: 13px; color: var(--accent-primary); font-weight: 600; text-transform: uppercase; letter-spacing: 1.5px; margin-bottom: 30px; }

.credentials-list { text-align: left; border-top: 1px solid var(--border-glass); padding-top: 25px; }
.cred-item { margin-bottom: 16px; }
.cred-label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; font-weight: 600; margin-bottom: 4px; }
.cred-val { font-size: 15px; font-weight: 500; color: #fff; background: rgba(255,255,255,0.02); padding: 10px 14px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.04); display: flex; align-items: center; gap: 10px; }
.cred-val svg { width: 16px; height: 16px; color: var(--text-muted); }

/* Forms */
.section-title { font-family: var(--font-heading); font-size: 20px; font-weight: 700; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border-bottom: 1px solid var(--border-glass); padding-bottom: 15px; }
.section-title svg { color: var(--accent-primary); width: 22px; height: 22px; }

.form-group { margin-bottom: 20px; }
label { display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 8px; font-weight: 500; }
input[type="password"], input[type="text"], input[type="file"] {
  width: 100%; padding: 14px 16px; border-radius: 12px;
  background: rgba(0,0,0,0.2); border: 1px solid var(--border-glass);
  color: var(--text-main); font-size: 14px; transition: all 0.2s;
}
input:focus { outline: none; border-color: var(--accent-primary); background: rgba(0,0,0,0.4); box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1); }
input[type="file"] { padding: 10px; background: rgba(255,255,255,0.02); cursor: pointer; }
input[type="file"]::file-selector-button {
  background: rgba(255,255,255,0.1); color: #fff; border: none; padding: 8px 16px; border-radius: 8px; font-weight: 600; margin-right: 15px; cursor: pointer; transition: 0.2s;
}
input[type="file"]::file-selector-button:hover { background: rgba(255,255,255,0.2); }

.btn {
  background: linear-gradient(135deg, var(--accent-primary), #8b5cf6);
  color: white; border: none; padding: 12px 24px; border-radius: 12px;
  font-weight: 600; font-size: 14px; cursor: pointer; transition: all 0.2s;
  display: inline-flex; align-items: center; gap: 8px; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3);
}
.btn:hover { transform: translateY(-2px); box-shadow: 0 8px 25px rgba(99, 102, 241, 0.4); }
.btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626); box-shadow: 0 4px 15px rgba(239, 68, 68, 0.2); }
.btn-danger:hover { box-shadow: 0 8px 25px rgba(239, 68, 68, 0.3); }
.btn-outline { background: transparent; border: 1px solid var(--border-glass); box-shadow: none; }
.btn-outline:hover { background: rgba(255,255,255,0.05); border-color: var(--border-glass-hover); box-shadow: none; }

.alert { padding: 14px 18px; border-radius: 12px; margin-bottom: 25px; font-size: 14px; display: flex; align-items: center; gap: 12px; font-weight: 500; }
.alert.error { background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; }
.alert.success { background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: #6ee7b7; }

.step-info { font-size: 14px; color: var(--text-muted); margin-bottom: 20px; line-height: 1.6; padding: 15px; background: rgba(255,255,255,0.02); border-radius: 12px; border: 1px solid var(--border-glass); }
.otp-box { font-family: monospace; font-size: 24px; letter-spacing: 4px; text-align: center; }

</style>
</head>
<body>

<div class="header-row">
  <div class="page-title">Profile Settings</div>
  <a href="upccdashboard.php" class="back-link">
    <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path></svg>
    Dashboard
  </a>
</div>

<div class="grid-container">

  <!-- Left: Credentials Profile -->
  <div class="glass-panel profile-wrap">
    <div class="avatar-container">
      <?php if (!empty($user['photo_path'])): ?>
        <div class="avatar" style="background-image: url('<?php echo htmlspecialchars($user['photo_path']); ?>'); border-color: var(--accent-primary);"></div>
      <?php else: ?>
        <div class="avatar"><?php echo strtoupper(substr($user['full_name'], 0, 1)); ?></div>
      <?php endif; ?>
    </div>
    
    <div class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
    <div class="profile-role">UPCC Panel Member</div>

    <div class="credentials-list">
      <div class="cred-item">
        <div class="cred-label">UPCC ID Number</div>
        <div class="cred-val">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
          #<?php echo str_pad((string)$upccId, 4, '0', STR_PAD_LEFT); ?>
        </div>
      </div>
      <div class="cred-item">
        <div class="cred-label">Username</div>
        <div class="cred-val">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>
          <?php echo htmlspecialchars($user['username']); ?>
        </div>
      </div>
      <div class="cred-item">
        <div class="cred-label">Email Address</div>
        <div class="cred-val">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
          <?php echo htmlspecialchars($user['email']); ?>
        </div>
      </div>
    </div>
  </div>

  <!-- Right: Settings Form -->
  <div style="display: flex; flex-direction: column; gap: 30px;">
    
    <?php if ($error): ?>
      <div class="alert error">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><path d="M15 9l-6 6m0-6l6 6"></path></svg>
        <?php echo htmlspecialchars($error); ?>
      </div>
    <?php endif; ?>
    <?php if ($success): ?>
      <div class="alert success">
        <svg width="20" height="20" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><path d="M22 4L12 14.01l-3-3"></path></svg>
        <?php echo htmlspecialchars($success); ?>
      </div>
    <?php endif; ?>

    <!-- Photo Upload Panel -->
    <div class="glass-panel">
      <div class="section-title">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect><circle cx="8.5" cy="8.5" r="1.5"></circle><polyline points="21 15 16 10 5 21"></polyline></svg>
        Profile Picture
      </div>
      <form method="post" enctype="multipart/form-data" style="margin-bottom: 20px;">
        <input type="hidden" name="action" value="upload_photo">
        <div class="form-group">
          <label>Upload New Picture (JPG, PNG, WebP)</label>
          <input type="file" name="photo" accept="image/jpeg, image/png, image/webp" required>
        </div>
        <button type="submit" class="btn">
          <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path><polyline points="17 8 12 3 7 8"></polyline><line x1="12" y1="3" x2="12" y2="15"></line></svg>
          Upload Photo
        </button>
      </form>

      <?php if (!empty($user['photo_path'])): ?>
        <form method="post" style="border-top: 1px solid var(--border-glass); padding-top: 20px;">
          <input type="hidden" name="action" value="remove_photo">
          <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to remove your profile picture?');">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 6h18M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"></path></svg>
            Remove Current Photo
          </button>
        </form>
      <?php endif; ?>
    </div>

    <!-- Password Change Panel -->
    <div class="glass-panel">
      <div class="section-title">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0110 0v4"></path></svg>
        Security & Password
      </div>

      <?php if ($step === ''): ?>
        <div class="step-info">
          To protect your UPCC account, changing your password requires an email verification step. A One-Time Password (OTP) will be sent to your registered email address <b><?php echo htmlspecialchars($user['email']); ?></b>.
        </div>
        <form method="post">
          <input type="hidden" name="action" value="request_otp">
          <button type="submit" class="btn">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"></path></svg>
            Send OTP to Email
          </button>
        </form>

      <?php elseif ($step === 'verify_otp'): ?>
        <div class="step-info">
          An OTP has been sent to <b><?php echo htmlspecialchars($user['email']); ?></b>. Please check your inbox and enter the 6-digit code below to proceed. The code expires in 5 minutes.
        </div>
        <form method="post">
          <input type="hidden" name="action" value="verify_otp">
          <div class="form-group">
            <label>6-Digit OTP Code</label>
            <input type="text" name="otp" class="otp-box" required maxlength="6" pattern="\d{6}" placeholder="------" autocomplete="off" autofocus>
          </div>
          <div style="display:flex; gap:15px;">
            <button type="submit" class="btn">
              <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 11l3 3L22 4"></path><path d="M21 12v7a2 2 0 01-2 2H5a2 2 0 01-2-2V5a2 2 0 012-2h11"></path></svg>
              Verify OTP
            </button>
          </div>
        </form>
        <form method="post" style="margin-top: 15px;">
          <input type="hidden" name="action" value="request_otp">
          <button type="submit" class="btn btn-outline" style="padding: 8px 16px; font-size: 13px;">Resend OTP</button>
        </form>

      <?php elseif ($step === 'change_password'): ?>
        <div class="step-info" style="border-color: rgba(16, 185, 129, 0.3); background: rgba(16, 185, 129, 0.05);">
          <span style="color:#10b981; font-weight:600;">✓ OTP Verified.</span> You may now set a new secure password for your account.
        </div>
        <form method="post">
          <input type="hidden" name="action" value="change_password">
          <div class="form-group">
            <label>New Password</label>
            <input type="password" name="new_password" required minlength="8" placeholder="At least 8 characters">
          </div>
          <div class="form-group">
            <label>Confirm New Password</label>
            <input type="password" name="confirm_password" required minlength="8" placeholder="Re-enter your new password">
          </div>
          <button type="submit" class="btn">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"></path><polyline points="17 21 17 13 7 13 7 21"></polyline><polyline points="7 3 7 8 15 8"></polyline></svg>
            Save New Password
          </button>
        </form>
      <?php endif; ?>

    </div>
  </div>
</div>

</body>
</html>
