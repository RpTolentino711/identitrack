<?php
/**
 * Sends an OTP to the currently logged-in admin's email (admin_user.email).
 * POST: action=<string>
 * Example: action=change_password
 */

session_start();
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/class.phpmailer.php';
require_once __DIR__ . '/class.smtp.php';
require_once __DIR__ . '/../database/database.php';

require_admin();

date_default_timezone_set('Asia/Manila');

define('OTP_EXPIRY_MINUTES', 5);
define('OTP_COOLDOWN_SECONDS', 60);

function otp_key(int $adminId, string $action): string {
    $action = preg_replace('/[^a-z0-9_]/i', '', $action);
    return "adminotp_{$adminId}_{$action}";
}

function generateOTP(): string {
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function makeMailer(): PHPMailer {
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->Port = 587;
    $mail->SMTPAuth = true;

    // PHPMailer 5.2.28 uses string 'tls' (not PHPMailer::ENCRYPTION_STARTTLS)
    $mail->SMTPSecure = 'tls';

    // ✅ Your Gmail SMTP
    $mail->Username = 'romeopaolotolentino@gmail.com';
    $mail->Password = 'xhgg ajje ixak ajoj';

    $mail->Timeout = 30;
    return $mail;
}

function themeActionLabel(string $action): string {
    $action = trim($action);
    if ($action === '') return 'Verification';

    // Make it readable
    $action = str_replace('_', ' ', strtolower($action));
    $action = ucwords($action);

    // Small mapping for nicer labels (optional)
    $map = [
        'Change Password' => 'Change Password',
        'Change Email' => 'Change Email',
        'Update Profile' => 'Update Profile',
    ];
    return $map[$action] ?? $action;
}

function sendOTPEmail(string $toEmail, string $toName, string $action, string $otp): bool {
    $mail = makeMailer();

    $mail->setFrom($mail->Username, 'IdentiTrack Admin');
    $mail->addAddress($toEmail, $toName ?: 'Admin');
    $mail->addReplyTo('no-reply@identitrack.local', 'IdentiTrack');

    $actionLabel = themeActionLabel($action);
    $mail->isHTML(true);
    $mail->Subject = "Your OTP Code - {$actionLabel}";

    $safeName = htmlspecialchars($toName ?: 'Admin', ENT_QUOTES, 'UTF-8');
    $safeOtp  = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

    // IMPORTANT about logo image in email:
    // Email clients cannot load local files like ../assets/logo.png.
    // You have 2 options:
    // 1) Host it publicly (recommended) and use <img src="https://your-domain/assets/logo.png">
    // 2) Embed it as an inline attachment (CID). We'll do CID below.
    //
    // We'll try to embed: identitrack/assets/logo.png (project root/assets/logo.png)
    $logoPath = realpath(__DIR__ . '/../assets/logo.png');
    $hasLogo = ($logoPath && is_readable($logoPath));

    $cid = 'identitracklogo';
    if ($hasLogo) {
        // embed logo so it works in Gmail/outlook without external loading
        $mail->addEmbeddedImage($logoPath, $cid, 'logo.png');
    }

    $expiresText = OTP_EXPIRY_MINUTES . " minute" . (OTP_EXPIRY_MINUTES == 1 ? "" : "s");
    $requestedAt = date('F j, Y g:i A');

    $logoImgHtml = $hasLogo
        ? "<img src=\"cid:$cid\" width=\"42\" height=\"42\" alt=\"IdentiTrack\" style=\"display:block;border-radius:12px;\" />"
        : "<div style=\"width:42px;height:42px;border-radius:12px;background:#e0e7ff;color:#1e3a8a;display:flex;align-items:center;justify-content:center;font-weight:800;\">IT</div>";

    // Clean theme: blue header like your UI (#3b4a9e)
    $mail->Body = "
<!doctype html>
<html>
<head>
  <meta charset='utf-8' />
  <meta name='viewport' content='width=device-width, initial-scale=1' />
  <title>OTP Verification</title>
</head>
<body style='margin:0;padding:0;background:#f3f4f6;'>
  <div style='padding:24px 12px;'>
    <div style='max-width:620px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 12px 30px rgba(17,24,39,.10);font-family:Segoe UI,Tahoma,Arial,sans-serif;'>
      
      <div style='background:#3b4a9e;color:#ffffff;padding:18px 18px;'>
        <div style='display:flex;align-items:center;gap:12px;'>
          $logoImgHtml
          <div style='min-width:0;'>
            <div style='font-size:18px;font-weight:900;line-height:1.1;'>IdentiTrack</div>
            <div style='opacity:.9;font-size:13px;margin-top:3px;'>OTP Verification • {$actionLabel}</div>
          </div>
        </div>
      </div>

      <div style='padding:18px 18px;'>
        <p style='margin:0 0 10px;color:#111827;font-size:14px;'>
          Hi <b>{$safeName}</b>,
        </p>

        <p style='margin:0 0 14px;color:#374151;font-size:14px;line-height:1.55;'>
          Use the verification code below to continue. For your security, please do not share this code with anyone.
        </p>

        <div style='background:#f8fafc;border:1px dashed #c7d2fe;border-radius:16px;padding:16px;text-align:center;margin:18px 0;'>
          <div style='font-size:12px;color:#64748b;letter-spacing:.10em;text-transform:uppercase;font-weight:700;'>
            Your One-Time Password
          </div>
          <div style='font-size:34px;letter-spacing:10px;font-weight:900;color:#1e3a8a;margin-top:10px;'>
            {$safeOtp}
          </div>
          <div style='font-size:12px;color:#6b7280;margin-top:10px;'>
            Expires in <b>{$expiresText}</b>
          </div>
        </div>

        <div style='border-left:4px solid #60a5fa;background:#eff6ff;padding:12px 12px;border-radius:12px;color:#1e3a8a;font-size:13px;line-height:1.5;'>
          <b>Requested action:</b> {$actionLabel}<br/>
          <b>Requested at:</b> {$requestedAt}
        </div>

        <p style='margin:16px 0 0;color:#6b7280;font-size:12px;line-height:1.5;'>
          If you did not request this, you can ignore this email. Your account is still safe.
        </p>
      </div>

      <div style='padding:14px 18px;background:#111827;color:#9ca3af;font-size:12px;text-align:center;'>
        © " . date('Y') . " IdentiTrack • This is an automated message, please do not reply.
      </div>

    </div>
  </div>
</body>
</html>
";

    $mail->AltBody =
        "IdentiTrack OTP Verification\n"
        . "Action: {$actionLabel}\n"
        . "OTP: {$otp}\n"
        . "Expires in: {$expiresText}\n"
        . "Requested at: {$requestedAt}\n\n"
        . "If you did not request this, ignore this email.\n";

    return $mail->send();
}

// ---- main ----
$action = trim((string)($_POST['action'] ?? ''));

if ($action === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing action.']);
    exit;
}

$admin = admin_current();
$adminId = (int)($admin['admin_id'] ?? 0);
$email = trim((string)($admin['email'] ?? ''));
$name  = trim((string)($admin['full_name'] ?? 'Admin'));

if ($adminId <= 0 || $email === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Admin session/email not found.']);
    exit;
}

$key = otp_key($adminId, $action);

if (!isset($_SESSION['otp'])) $_SESSION['otp'] = [];
if (!isset($_SESSION['otp'][$key])) $_SESSION['otp'][$key] = [];

if (!empty($_SESSION['otp'][$key]['last_sent'])) {
    $diff = time() - (int)$_SESSION['otp'][$key]['last_sent'];
    if ($diff < OTP_COOLDOWN_SECONDS) {
        http_response_code(429);
        echo json_encode([
            'success' => false,
            'message' => 'Please wait ' . (OTP_COOLDOWN_SECONDS - $diff) . ' seconds before requesting a new code.'
        ]);
        exit;
    }
}

$otp = generateOTP();

$_SESSION['otp'][$key] = [
    'code' => $otp,
    'expires' => time() + (OTP_EXPIRY_MINUTES * 60),
    'attempts' => 0,
    'locked_until' => 0,
    'last_sent' => time(),
];

try {
    $sent = sendOTPEmail($email, $name, $action, $otp);
    if (!$sent) {
        echo json_encode(['success' => false, 'message' => 'Failed to send OTP email.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'OTP sent to your email.',
        'expires_at' => $_SESSION['otp'][$key]['expires'],
    ]);
    exit;
} catch (Exception $e) {
    error_log("OTP Send Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Mailer error. Check error logs.']);
    exit;
}

