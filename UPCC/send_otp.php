<?php
session_start();
require_once __DIR__ . '/../database/database.php';

// Must have come from a successful password check
if (!isset($_SESSION['upcc_pending_otp'])) {
    header('Location: upccpanel.php');
    exit;
}

$user = upcc_find_by_username($_SESSION['upcc_pending_otp']);
if (!$user) {
    $_SESSION['login_error'] = 'Session expired. Please log in again.';
    header('Location: upccpanel.php');
    exit;
}

// Generate a fresh OTP
$otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
$_SESSION['upcc_otp_val']  = $otp;
$_SESSION['upcc_otp_user'] = $user['username'];
$_SESSION['upcc_otp_time'] = time();

// --- Send OTP via PHPMailer (v5 style, same as your existing mailer) ---
require_once __DIR__ . '/class.phpmailer.php';
require_once __DIR__ . '/class.smtp.php';

$mailError = '';
try {
    $mail = new PHPMailer(true);
    $mail->CharSet   = 'UTF-8';
    $mail->isSMTP();
    $mail->Host      = 'smtp.gmail.com';
    $mail->Port      = 587;
    $mail->SMTPAuth  = true;
    $mail->SMTPSecure = 'tls';
    $mail->Username  = 'romeopaolotolentino@gmail.com';
    $mail->Password  = 'xhgg ajje ixak ajoj';
    $mail->Timeout   = 30;

    $mail->setFrom('romeopaolotolentino@gmail.com', 'UPCC Panel');
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
    $mail->Subject = 'Your UPCC Login Code';
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
            <div style='font-size:12px;color:#7a8aac;margin-top:3px;'>Login Verification</div>
          </div>
        </div>
      </div>

      <div style='padding:28px 24px;'>
        <p style='margin:0 0 10px;color:#111827;font-size:14px;'>Hi <b>{$safeName}</b>,</p>
        <p style='margin:0 0 20px;color:#374151;font-size:14px;line-height:1.6;'>
          Use the code below to complete your sign-in. Do not share this code with anyone.
        </p>

        <div style='background:#f8fafc;border:1px dashed #c7d2fe;border-radius:16px;padding:20px;text-align:center;margin-bottom:20px;'>
          <div style='font-size:11px;color:#64748b;letter-spacing:.12em;text-transform:uppercase;font-weight:700;'>One-Time Password</div>
          <div style='font-size:38px;letter-spacing:12px;font-weight:900;color:#1e3a8a;margin-top:12px;'>{$safeOtp}</div>
          <div style='font-size:12px;color:#6b7280;margin-top:10px;'>Expires in <b>5 minutes</b></div>
        </div>

        <div style='border-left:4px solid #60a5fa;background:#eff6ff;padding:12px 14px;border-radius:10px;color:#1e3a8a;font-size:13px;line-height:1.6;'>
          <b>Requested at:</b> {$requestedAt}
        </div>

        <p style='margin:18px 0 0;color:#9ca3af;font-size:12px;'>If you did not try to log in, ignore this email. Your account is safe.</p>
      </div>

      <div style='padding:14px 24px;background:#111827;color:#6b7280;font-size:11px;text-align:center;'>
        © " . date('Y') . " IdentiTrack &bull; Automated message, do not reply.
      </div>
    </div>
  </div>
</body>
</html>";

    $mail->AltBody = "Your UPCC login OTP is: {$otp}\n\nExpires in 5 minutes. Do not share it with anyone.\nRequested at: {$requestedAt}";

    $mail->send();
} catch (Exception $e) {
    $mailError = $e->getMessage();
}

$error = $_SESSION['otp_error'] ?? '';
unset($_SESSION['otp_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>UPCC — OTP Verification</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --navy:#0b1630; --panel:#111d3a; --card:#16244a;
            --border:rgba(255,255,255,0.07); --accent:#4f7bff; --accent2:#7c9fff;
            --gold:#f0c040; --text:#e8ecf7; --muted:#7a8aac; --danger:#ff5b5b;
        }
        body { background:var(--navy);color:var(--text);font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;align-items:center;justify-content:center; }
        body::before { content:'';position:fixed;inset:0;background:radial-gradient(ellipse 60% 50% at 20% 20%,rgba(79,123,255,0.12) 0%,transparent 70%),radial-gradient(ellipse 50% 40% at 80% 80%,rgba(124,159,255,0.08) 0%,transparent 70%);pointer-events:none; }
        .wrapper { width:100%;max-width:420px;padding:20px;animation:fadeUp 0.55s ease both; }
        .logo-row { display:flex;align-items:center;gap:10px;margin-bottom:28px; }
        .logo-mark { width:34px;height:34px;border-radius:9px;object-fit:contain; }
        .logo-text { font-family:'Syne',sans-serif;font-weight:700;font-size:14px;color:var(--muted);letter-spacing:2px;text-transform:uppercase; }
        .card { background:var(--card);border:1px solid var(--border);border-radius:20px;padding:32px 28px 28px;position:relative;overflow:hidden; }
        .card::before { content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--accent),var(--accent2),transparent); }
        .otp-icon { width:48px;height:48px;background:rgba(79,123,255,0.15);border:1px solid rgba(79,123,255,0.25);border-radius:14px;display:flex;align-items:center;justify-content:center;margin-bottom:20px; }
        .otp-icon svg { width:22px;height:22px;color:var(--accent2); }
        .card-title { font-family:'Syne',sans-serif;font-size:22px;font-weight:800;margin-bottom:6px; }
        .card-sub { font-size:13px;color:var(--muted);margin-bottom:24px;line-height:1.6; }
        .card-sub strong { color:var(--text); }
        .alert-err { background:rgba(255,91,91,0.12);border:1px solid rgba(255,91,91,0.3);color:#ff8a8a;border-radius:10px;padding:10px 14px;font-size:13px;margin-bottom:18px; }
        .alert-warn { background:rgba(240,192,64,0.1);border:1px solid rgba(240,192,64,0.25);color:var(--gold);border-radius:10px;padding:10px 14px;font-size:12px;margin-bottom:18px; }
        .otp-fields { display:flex;gap:8px;margin-bottom:10px; }
        .otp-digit { flex:1;height:54px;text-align:center;font-family:'Syne',sans-serif;font-size:22px;font-weight:700;background:var(--panel);border:1px solid var(--border);border-radius:10px;color:var(--text);outline:none;transition:border-color 0.2s,box-shadow 0.2s;-moz-appearance:textfield; }
        .otp-digit::-webkit-outer-spin-button,.otp-digit::-webkit-inner-spin-button { -webkit-appearance:none; }
        .otp-digit:focus { border-color:var(--accent);box-shadow:0 0 0 3px rgba(79,123,255,0.18); }
        #otp-hidden { display:none; }
        .timer-row { display:flex;justify-content:space-between;align-items:center;margin-bottom:22px;font-size:12px;color:var(--muted); }
        #countdown { font-family:'Syne',sans-serif;font-size:12px;font-weight:600;color:var(--gold); }
        #countdown.expiring { color:var(--danger); }
        .btn-verify { width:100%;padding:13px;border-radius:11px;border:none;background:linear-gradient(135deg,var(--accent) 0%,#3a5fcc 100%);color:#fff;font-family:'Syne',sans-serif;font-size:15px;font-weight:700;cursor:pointer;letter-spacing:0.5px;transition:opacity 0.2s,transform 0.15s; }
        .btn-verify:hover { opacity:0.88;transform:translateY(-1px); }
        .back-link { display:block;text-align:center;margin-top:18px;font-size:13px;color:var(--muted);text-decoration:none;transition:color 0.2s; }
        .back-link:hover { color:var(--text); }
        @keyframes fadeUp { from{opacity:0;transform:translateY(16px)}to{opacity:1;transform:translateY(0)} }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="logo-row">
        <img src="../assets/logo.png" alt="UPCC Logo" class="logo-mark">
        <div class="logo-text">UPCC Panel</div>
    </div>
    <div class="card">
        <div class="otp-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <rect x="2" y="7" width="20" height="14" rx="3"/>
                <path d="M16 7V5a4 4 0 0 0-8 0v2"/>
                <circle cx="12" cy="14" r="1.5" fill="currentColor" stroke="none"/>
            </svg>
        </div>
        <div class="card-title">Verify your identity</div>
        <div class="card-sub">
            A 6-digit code was sent to<br>
            <strong><?= htmlspecialchars(preg_replace('/(?<=.{2}).(?=[^@]*@)/u', '*', $user['email'])) ?></strong>
        </div>

        <?php if (!empty($mailError)): ?>
            <div class="alert-warn">⚠️ Failed to send email: <?= htmlspecialchars($mailError) ?></div>
        <?php endif; ?>
        <?php if (!empty($error)): ?>
            <div class="alert-err"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>

        <form method="post" action="verify_otp.php" id="otp-form" autocomplete="off">
            <div class="otp-fields">
                <?php for ($i = 0; $i < 6; $i++): ?>
                    <input type="number" class="otp-digit" maxlength="1" min="0" max="9" inputmode="numeric" tabindex="<?= $i + 1 ?>">
                <?php endfor; ?>
            </div>
            <input type="hidden" name="otp" id="otp-hidden">
            <div class="timer-row">
                <span>Code expires in</span>
                <span id="countdown">05:00</span>
            </div>
            <button type="submit" class="btn-verify">Verify Code</button>
        </form>
        <a href="upccpanel.php" class="back-link">← Back to login</a>
    </div>
</div>
<script>
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
function assemble() { hidden.value = [...digits].map(d => d.value).join(''); }
form.addEventListener('submit', e => { assemble(); if (hidden.value.length < 6) { e.preventDefault(); digits[0].focus(); } });
digits[0].focus();
let remaining = 300;
const countdown = document.getElementById('countdown');
const pad = n => String(n).padStart(2, '0');
const timer = setInterval(() => {
    remaining--;
    if (remaining <= 0) { clearInterval(timer); countdown.textContent = '00:00'; countdown.classList.add('expiring'); window.location.href = 'upccpanel.php'; return; }
    countdown.textContent = pad(Math.floor(remaining / 60)) + ':' + pad(remaining % 60);
    if (remaining <= 30) countdown.classList.add('expiring');
}, 1000);
</script>
</body>
</html>

