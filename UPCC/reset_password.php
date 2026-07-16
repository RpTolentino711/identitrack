<?php
// File: UPCC/reset_password.php
session_start();
require_once __DIR__ . '/../database/database.php';

// Initialize step if not set or request to restart/cancel
if (!isset($_SESSION['reset_step']) || isset($_GET['restart'])) {
    unset(
        $_SESSION['reset_step'],
        $_SESSION['reset_username'],
        $_SESSION['reset_email'],
        $_SESSION['reset_otp_val'],
        $_SESSION['reset_otp_time'],
        $_SESSION['reset_otp_failures']
    );
    $_SESSION['reset_step'] = 'username';
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Step 1: Submit Username
    if ($action === 'submit_username') {
        $username = trim($_POST['username'] ?? '');
        if ($username === '') {
            $error = 'Please enter your username.';
        } else {
            $user = upcc_find_by_username($username);
            if (!$user) {
                $error = 'Username not found.';
            } elseif ((int)($user['is_active'] ?? 0) !== 1) {
                $error = 'Your account is inactive. Please contact the administrator.';
            } else {
                $_SESSION['reset_username'] = $user['username'];
                $_SESSION['reset_email'] = $user['email'];
                $_SESSION['reset_full_name'] = $user['full_name'];
                
                // Generate 6-digit OTP
                $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $_SESSION['reset_otp_val'] = $otp;
                $_SESSION['reset_otp_time'] = time();
                $_SESSION['reset_otp_failures'] = 0;
                
                // Send email
                require_once __DIR__ . '/class.phpmailer.php';
                require_once __DIR__ . '/class.smtp.php';
                
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
                    $mail->addReplyTo('no-reply@identitrack.local', 'UPCC Panel');
                    
                    $logoPath = realpath(__DIR__ . '/../assets/logo.png');
                    $cid = 'upcclogo';
                    if ($logoPath && is_readable($logoPath)) {
                        $mail->addEmbeddedImage($logoPath, $cid, 'logo.png');
                        $logoHtml = "<img src=\"cid:$cid\" width=\"42\" height=\"42\" alt=\"UPCC\" style=\"display:block;border-radius:12px;\" />";
                    } else {
                        $logoHtml = "<div style=\"width:42px;height:42px;border-radius:12px;background:#1e3a8a;color:#fff;font-weight:800;font-size:14px;text-align:center;line-height:42px;\">IT</div>";
                    }

                    $safeName = htmlspecialchars($user['full_name'], ENT_QUOTES, 'UTF-8');
                    $safeOtp  = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
                    $requestedAt = date('F j, Y g:i A');

                    $mail->isHTML(true);
                    $mail->Subject = 'Reset Your UPCC Password';
                    $mail->Body = "
                <!doctype html>
                <html>
                <head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
                <body style='margin:0;padding:0;background:#f3f4f6;'>
                  <div style='padding:24px 12px;'>
                    <div style='max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 12px 30px rgba(17,24,39,.10);font-family:Segoe UI,Tahoma,Arial,sans-serif;'>

                      <div style='background:#0b1630;padding:20px 24px;'>
                        <div style='display:flex;align-items:center;gap:12px;'>
                          {$logoHtml}
                          <div>
                            <div style='font-size:17px;font-weight:900;color:#e8ecf7;line-height:1.1;'>UPCC Panel</div>
                            <div style='font-size:12px;color:#7a8aac;margin-top:3px;'>Password Reset</div>
                          </div>
                        </div>
                      </div>

                      <div style='padding:28px 24px;'>
                        <p style='margin:0 0 10px;color:#111827;font-size:14px;'>Hi <b>{$safeName}</b>,</p>
                        <p style='margin:0 0 20px;color:#374151;font-size:14px;line-height:1.6;'>
                          We received a request to reset your password. Use the verification code below to verify your identity.
                        </p>

                        <div style='background:#f8fafc;border:1px dashed #c7d2fe;border-radius:16px;padding:20px;text-align:center;margin-bottom:20px;'>
                          <div style='font-size:11px;color:#64748b;letter-spacing:.12em;text-transform:uppercase;font-weight:700;'>One-Time Password</div>
                          <div style='font-size:38px;letter-spacing:12px;font-weight:900;color:#1e3a8a;margin-top:12px;'>{$safeOtp}</div>
                          <div style='font-size:12px;color:#6b7280;margin-top:10px;'>Expires in <b>5 minutes</b></div>
                        </div>

                        <div style='border-left:4px solid #60a5fa;background:#eff6ff;padding:12px 14px;border-radius:10px;color:#1e3a8a;font-size:13px;line-height:1.6;'>
                          <b>Requested at:</b> {$requestedAt}
                        </div>

                        <p style='margin:18px 0 0;color:#9ca3af;font-size:12px;'>If you did not request this password reset, please ignore this email.</p>
                      </div>

                      <div style='padding:14px 24px;background:#111827;color:#6b7280;font-size:11px;text-align:center;'>
                        © " . date('Y') . " IdentiTrack &bull; Automated message, do not reply.
                      </div>
                    </div>
                  </div>
                </body>
                </html>";

                    $mail->AltBody = "Your UPCC password reset code is: {$otp}\n\nExpires in 5 minutes.\nRequested at: {$requestedAt}";

                    if ($mail->send()) {
                        $_SESSION['reset_step'] = 'otp';
                    } else {
                        $error = 'Failed to send verification email. Please try again.';
                    }
                } catch (Exception $e) {
                    $error = 'Mailer error: ' . $e->getMessage();
                }
            }
        }
    }
    
    // Step 2: Submit OTP Code
    elseif ($action === 'submit_otp') {
        $otp = trim($_POST['otp'] ?? '');
        $sessionOtp = $_SESSION['reset_otp_val'] ?? null;
        $sessionTime = $_SESSION['reset_otp_time'] ?? 0;
        
        if (!$sessionOtp) {
            $error = 'OTP session expired. Please restart the request.';
            $_SESSION['reset_step'] = 'username';
        } elseif (time() - $sessionTime > 300) {
            $error = 'Verification code has expired. Please request a new one.';
            $_SESSION['reset_step'] = 'username';
        } elseif ($otp !== $sessionOtp) {
            $_SESSION['reset_otp_failures'] = ($_SESSION['reset_otp_failures'] ?? 0) + 1;
            if ($_SESSION['reset_otp_failures'] >= 5) {
                $error = 'Too many incorrect OTP attempts. Please start over.';
                $_SESSION['reset_step'] = 'username';
            } else {
                $rem = 5 - $_SESSION['reset_otp_failures'];
                $error = "Incorrect verification code. {$rem} attempts remaining.";
            }
        } else {
            $_SESSION['reset_step'] = 'password';
        }
    }
    
    // Step 3: Submit New Password
    elseif ($action === 'submit_password') {
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';
        
        if (strlen($newPass) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif (!preg_match('/[A-Z]/', $newPass)) {
            $error = 'Password must contain at least one uppercase letter.';
        } elseif (!preg_match('/[^A-Za-z0-9]/', $newPass)) {
            $error = 'Password must contain at least one special character/symbol.';
        } elseif ($newPass !== $confirmPass) {
            $error = 'Passwords do not match.';
        } else {
            $username = $_SESSION['reset_username'] ?? '';
            $hashed = password_hash($newPass, PASSWORD_DEFAULT);
            
            try {
                db_exec("UPDATE upcc_user SET password_hash = :hash, must_change_password = 0 WHERE username = :username", [
                    ':hash' => $hashed,
                    ':username' => $username
                ]);
                
                // Clear sessions
                unset(
                    $_SESSION['reset_username'],
                    $_SESSION['reset_email'],
                    $_SESSION['reset_otp_val'],
                    $_SESSION['reset_otp_time'],
                    $_SESSION['reset_otp_failures']
                );
                
                $_SESSION['reset_step'] = 'success';
            } catch (Exception $e) {
                $error = 'Database error: ' . $e->getMessage();
            }
        }
    }
}

// Masking Helper: romeopaolotolentino@gmail.com -> romeo*****@gmail.com
function maskEmail(string $email): string {
    $parts = explode('@', $email);
    if (count($parts) !== 2) return $email;
    $name = $parts[0];
    $domain = $parts[1];
    $len = strlen($name);
    if ($len <= 5) {
        return substr($name, 0, 1) . str_repeat('*', 5) . '@' . $domain;
    }
    return substr($name, 0, 5) . str_repeat('*', 5) . '@' . $domain;
}

$currentStep = $_SESSION['reset_step'] ?? 'username';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>UPCC Panel &mdash; Reset Password</title>
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

        .card::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 3px;
            background: linear-gradient(90deg, var(--accent), #a855f7, transparent);
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
            line-height: 1.5;
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
        }
        .alert-err svg { width: 18px; height: 18px; flex-shrink: 0; }

        .alert-success {
            background: rgba(16, 185, 129, 0.1);
            border: 1px solid rgba(16, 185, 129, 0.2);
            color: #a7f3d0;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 14px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-success svg { width: 18px; height: 18px; flex-shrink: 0; }

        .field { margin-bottom: 20px; }
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
        .field input::placeholder { color: rgba(148, 163, 184, 0.5); }

        .otp-fields { display: flex; gap: 8px; margin-bottom: 20px; }
        .otp-digit {
            flex: 1;
            height: 52px;
            text-align: center;
            font-family: 'Syne', sans-serif;
            font-size: 22px;
            font-weight: 700;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border);
            border-radius: 10px;
            color: var(--text-main);
            outline: none;
            transition: all 0.2s;
            -moz-appearance: textfield;
        }
        .otp-digit::-webkit-outer-spin-button, .otp-digit::-webkit-inner-spin-button { -webkit-appearance: none; }
        .otp-digit:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-glow); }

        .btn-submit {
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
        }
        .btn-submit:hover { transform: translateY(-2px); box-shadow: 0 8px 16px var(--accent-glow); filter: brightness(1.1); }
        .btn-submit:active { transform: translateY(0); box-shadow: 0 2px 8px var(--accent-glow); }

        .back-link { text-align: center; margin-top: 24px; }
        .back-link a {
            color: var(--text-muted); text-decoration: none;
            font-size: 14px; transition: color 0.2s;
        }
        .back-link a:hover { color: var(--text-main); }

        .timer-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 22px; font-size: 12px; color: var(--text-muted); }
        #countdown { font-family: 'Syne', sans-serif; font-size: 12px; font-weight: 600; color: #fbbf24; }

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
        <?php if ($currentStep === 'username'): ?>
            <div class="card-title">Reset Password</div>
            <div class="card-sub">Enter your username to search your profile and verify your account.</div>

            <?php if (!empty($error)): ?>
                <div class="alert-err">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="reset_password.php" autocomplete="off">
                <input type="hidden" name="action" value="submit_username">
                <div class="field">
                    <label for="username">Username</label>
                    <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
                </div>
                <button type="submit" class="btn-submit">Search Username &rarr;</button>
            </form>

        <?php elseif ($currentStep === 'otp'): ?>
            <div class="card-title">Verify Identity</div>
            <div class="card-sub">
                A verification OTP code was sent to <br>
                <strong><?= htmlspecialchars(maskEmail($_SESSION['reset_email'])) ?></strong>
            </div>

            <?php if (!empty($error)): ?>
                <div class="alert-err">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="reset_password.php" id="otp-form" autocomplete="off">
                <input type="hidden" name="action" value="submit_otp">
                <div class="otp-fields">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <input type="number" class="otp-digit" maxlength="1" min="0" max="9" inputmode="numeric" tabindex="<?= $i + 1 ?>" required>
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="otp" id="otp-hidden">

                <div class="timer-row">
                    <span>Code expires in</span>
                    <span id="countdown">05:00</span>
                </div>

                <button type="submit" class="btn-submit">Verify OTP &rarr;</button>
            </form>

        <?php elseif ($currentStep === 'password'): ?>
            <div class="card-title">New Password</div>
            <div class="card-sub">Set a strong, new password for account recovery.</div>

            <?php if (!empty($error)): ?>
                <div class="alert-err">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <form method="post" action="reset_password.php" autocomplete="off">
                <input type="hidden" name="action" value="submit_password">
                <div class="field">
                    <label for="new_password">New Password</label>
                    <input type="password" id="new_password" name="new_password" placeholder="Min. 8 chars (1 uppercase, 1 symbol)" required autofocus>
                </div>
                <div class="field">
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" placeholder="Confirm your password" required>
                </div>
                <button type="submit" class="btn-submit">Reset Password &rarr;</button>
            </form>

        <?php elseif ($currentStep === 'success'): ?>
            <div class="card-title">Password Reset!</div>
            <div class="card-sub">Your password has been changed successfully. You can now log in to the UPCC Panel.</div>

            <div class="alert-success">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                Success! Go to login.
            </div>

            <a href="upccpanel.php" class="btn-submit" style="display: block; text-align: center; text-decoration: none; line-height: 1.5;">Back to Login</a>
        <?php endif; ?>

        <div class="back-link">
            <?php if ($currentStep === 'username' || $currentStep === 'success'): ?>
                <a href="upccpanel.php">&larr; Back to Login</a>
            <?php else: ?>
                <a href="reset_password.php?restart=1">&larr; Cancel and Start Over</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
<?php if ($currentStep === 'otp'): ?>
// OTP Digit Focus Traversal & Auto-Assemble
const digits = document.querySelectorAll('.otp-digit');
const hidden = document.getElementById('otp-hidden');
const form   = document.getElementById('otp-form');

digits.forEach((input, i) => {
    input.addEventListener('input', () => {
        if (input.value.length > 1) input.value = input.value.slice(-1);
        if (input.value && i < digits.length - 1) digits[i + 1].focus();
        assemble();
    });
    input.addEventListener('keydown', e => {
        if (e.key === 'Backspace' && !input.value && i > 0) digits[i - 1].focus();
    });
    input.addEventListener('paste', e => {
        e.preventDefault();
        const pasted = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
        [...pasted].slice(0, 6).forEach((ch, j) => { if (digits[i + j]) digits[i + j].value = ch; });
        digits[Math.min(i + pasted.length, digits.length - 1)].focus();
        assemble();
    });
});

function assemble() {
    hidden.value = [...digits].map(d => d.value).join('');
}

form.addEventListener('submit', e => {
    assemble();
    if (hidden.value.length < 6) {
        e.preventDefault();
        digits[0].focus();
    }
});

if (digits[0]) digits[0].focus();

// Countdown Timer
let remaining = 300;
const countdown = document.getElementById('countdown');
const pad = n => String(n).padStart(2, '0');
const timer = setInterval(() => {
    remaining--;
    if (remaining <= 0) {
        clearInterval(timer);
        countdown.textContent = '00:00';
        window.location.href = 'reset_password.php?restart=1';
        return;
    }
    countdown.textContent = pad(Math.floor(remaining / 60)) + ':' + pad(remaining % 60);
}, 1000);
<?php endif; ?>
</script>
</body>
</html>
