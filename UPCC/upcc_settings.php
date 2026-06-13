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
            $mail->Host      = 'smtp.gmail.com';
            $mail->Port      = 587;
            $mail->SMTPAuth  = true;
            $mail->SMTPSecure = 'tls';
            $mail->Username  = 'romeopaolotolentino@gmail.com';
            $mail->Password  = 'bzup emxa ewfw uwll';
            $mail->Timeout   = 30;

            $mail->setFrom('romeopaolotolentino@gmail.com', 'UPCC Panel');
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
<title>UPCC Settings</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
/* Base styles matching the dashboard */
:root {
  --bg-dark: #0a0a0f;
  --bg-glass: rgba(18, 18, 25, 0.65);
  --bg-card: rgba(255, 255, 255, 0.03);
  --border-glass: rgba(255, 255, 255, 0.08);
  --accent-primary: #6366f1;
  --text-main: #f8fafc;
  --text-muted: #94a3b8;
  --success: #10b981;
  --danger: #ef4444;
}
body {
  font-family: 'Inter', sans-serif;
  background: var(--bg-dark);
  color: var(--text-main);
  min-height: 100vh; margin: 0; padding: 40px;
  display: flex; flex-direction: column; align-items: center;
}
body::before {
  content: ''; position: fixed; inset: 0; z-index: -2;
  background: radial-gradient(circle at 15% 50%, rgba(99, 102, 241, 0.15), transparent 40%),
              radial-gradient(circle at 85% 30%, rgba(139, 92, 246, 0.15), transparent 40%);
  filter: blur(60px);
}
.container {
  width: 100%; max-width: 600px;
  background: var(--bg-card);
  backdrop-filter: blur(20px);
  border: 1px solid var(--border-glass);
  border-radius: 20px;
  padding: 30px;
}
h2 { font-family: 'Outfit', sans-serif; margin-top: 0; font-size: 28px; }
.alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 14px; }
.alert.error { background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5; }
.alert.success { background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); color: #6ee7b7; }
.section { border-top: 1px solid var(--border-glass); padding-top: 25px; margin-top: 25px; }
.section:first-of-type { border-top: none; padding-top: 0; margin-top: 0; }
.form-group { margin-bottom: 15px; }
label { display: block; font-size: 13px; color: var(--text-muted); margin-bottom: 6px; }
input[type="password"], input[type="text"], input[type="file"] {
  width: 100%; padding: 10px 14px; border-radius: 8px;
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid var(--border-glass); color: var(--text-main); font-size: 14px;
}
input:focus { outline: none; border-color: var(--accent-primary); }
.btn {
  background: linear-gradient(135deg, var(--accent-primary), #8b5cf6);
  color: white; border: none; padding: 10px 20px; border-radius: 8px;
  font-weight: 600; cursor: pointer; transition: 0.2s;
  display: inline-block; text-align: center;
}
.btn:hover { transform: translateY(-2px); box-shadow: 0 4px 15px rgba(99, 102, 241, 0.4); }
.btn-danger { background: linear-gradient(135deg, #ef4444, #dc2626); }
.btn-danger:hover { box-shadow: 0 4px 15px rgba(239, 68, 68, 0.4); }
.profile-preview {
  width: 100px; height: 100px; border-radius: 50%; object-fit: cover;
  border: 2px solid var(--border-glass); margin-bottom: 15px;
}
.back-link { display: inline-block; margin-bottom: 20px; color: var(--text-muted); text-decoration: none; font-size: 14px; }
.back-link:hover { color: var(--text-main); }
</style>
</head>
<body>
<div class="container">
  <a href="upccdashboard.php" class="back-link">← Back to Dashboard</a>
  <h2>Settings</h2>

  <?php if ($error): ?><div class="alert error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
  <?php if ($success): ?><div class="alert success"><?php echo htmlspecialchars($success); ?></div><?php endif; ?>

  <div class="section">
    <h3 style="margin-top:0; font-family:'Outfit',sans-serif;">Profile Picture</h3>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="action" value="upload_photo">
      <?php if (!empty($user['photo_path'])): ?>
        <img src="<?php echo htmlspecialchars($user['photo_path']); ?>" alt="Profile" class="profile-preview"><br>
      <?php else: ?>
        <div style="width:100px; height:100px; border-radius:50%; background:rgba(255,255,255,0.1); display:flex; align-items:center; justify-content:center; margin-bottom:15px; font-size:32px; font-weight:bold; color:#fff;">
          <?php echo strtoupper(substr($user['full_name'], 0, 1)); ?>
        </div>
      <?php endif; ?>
      <div class="form-group">
        <input type="file" name="photo" accept="image/jpeg, image/png, image/webp" required>
      </div>
      <button type="submit" class="btn">Upload Photo</button>
    </form>
    <?php if (!empty($user['photo_path'])): ?>
      <form method="post" style="margin-top: 10px;">
        <input type="hidden" name="action" value="remove_photo">
        <button type="submit" class="btn btn-danger" onclick="return confirm('Are you sure you want to remove your profile picture?');">Remove Photo</button>
      </form>
    <?php endif; ?>
  </div>

  <div class="section">
    <h3 style="margin-top:0; font-family:'Outfit',sans-serif;">Change Password</h3>
    
    <?php if ($step === ''): ?>
      <p style="font-size:14px; color:var(--text-muted); margin-bottom:15px;">To change your password, an OTP will be sent to <b><?php echo htmlspecialchars($user['email']); ?></b>.</p>
      <form method="post">
        <input type="hidden" name="action" value="request_otp">
        <button type="submit" class="btn">Request OTP via Email</button>
      </form>
    <?php elseif ($step === 'verify_otp'): ?>
      <p style="font-size:14px; color:var(--text-muted); margin-bottom:15px;">An OTP was sent to <b><?php echo htmlspecialchars($user['email']); ?></b>.</p>
      <form method="post">
        <input type="hidden" name="action" value="verify_otp">
        <div class="form-group">
          <label>Enter 6-digit OTP</label>
          <input type="text" name="otp" required maxlength="6" pattern="\d{6}" placeholder="000000">
        </div>
        <button type="submit" class="btn">Verify OTP</button>
      </form>
      <form method="post" style="margin-top:10px;">
        <input type="hidden" name="action" value="request_otp">
        <button type="submit" class="btn" style="background:transparent; border:1px solid var(--border-glass); color:var(--text-main);">Resend OTP</button>
      </form>
    <?php elseif ($step === 'change_password'): ?>
      <form method="post">
        <input type="hidden" name="action" value="change_password">
        <div class="form-group">
          <label>New Password</label>
          <input type="password" name="new_password" required minlength="8">
        </div>
        <div class="form-group">
          <label>Confirm New Password</label>
          <input type="password" name="confirm_password" required minlength="8">
        </div>
        <button type="submit" class="btn">Update Password</button>
      </form>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
