<?php
// File: admin/otp_mailer.php
require_once __DIR__ . '/class.phpmailer.php';
require_once __DIR__ . '/class.smtp.php';
require_once __DIR__ . '/../database/database.php';

function send_admin_otp_email(string $toEmail, string $toName, string $action, string $otp): bool {
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.hostinger.com';
    $mail->Port = (int)($_ENV['SMTP_PORT'] ?? 465);
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = $_ENV['SMTP_SECURE'] ?? 'ssl';
    $mail->SMTPAutoTLS = false;
    $mail->Timeout = 20;

    // ✅ SDO SMTP Credentials
    $mail->Username = get_env_var('SMTP_USER', 'identitrack@identitrack.site');
    $mail->Password = get_env_var('SMTP_PASS', '');

    $mail->setFrom($mail->Username, 'IdentiTrack Admin Verification');
    $mail->addAddress($toEmail, $toName);
    $mail->isHTML(true);
    $mail->Subject = "Verification Code: {$otp} for IdentiTrack Admin";

    // Embed logo
    $logoPath = realpath(__DIR__ . '/../assets/logo.png');
    $cid = 'identitrack_logo';
    $hasLogo = ($logoPath && is_readable($logoPath));
    if ($hasLogo) {
        $mail->addEmbeddedImage($logoPath, $cid, 'logo.png');
    }

    $actionLabel = ucwords(str_replace('_', ' ', $action));
    $logoHtml = $hasLogo 
        ? "<img src='cid:$cid' width='50' height='50' style='display:block;margin-bottom:15px;'>" 
        : "<div style='font-size:24px;font-weight:bold;color:#3b4a9e;margin-bottom:15px;'>IdentiTrack</div>";

    $mail->Body = "
    <div style='font-family: Arial, sans-serif; background-color: #f4f7ff; padding: 30px; color: #333;'>
        <div style='max-width: 500px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.05); border: 1px solid #e2e8f0;'>
            $logoHtml
            <h2 style='color: #1e293b; margin-top: 0; font-size: 20px;'>Verification Code</h2>
            <p style='font-size: 15px; color: #475569; margin-top: 0;'>Hello <strong>$toName</strong>,</p>
            <p style='font-size: 14px; color: #64748b; line-height: 1.5;'>Please use the 6-digit code below to complete your login verification:</p>
            
            <div style='background: #f1f5f9; border-radius: 14px; padding: 22px; text-align: center; margin: 20px 0;'>
                <span style='font-size: 38px; font-weight: 900; letter-spacing: 10px; color: #1e293b;'>$otp</span>
            </div>

            <p style='font-size: 13px; color: #94a3b8; line-height: 1.5;'>This code is valid for 10 minutes. Do not share this code with anyone.</p>
            
            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 25px 0;'>
            <p style='font-size: 12px; color: #94a3b8; text-align: center;'>&copy; " . date('Y') . " IdentiTrack SDO System. All rights reserved.</p>
        </div>
    </div>
    ";

    try {
        return $mail->send();
    } catch (\Exception $e) {
        // Fallback 1: Try Port 587 TLS if Port 465 SSL failed
        try {
            $mail->Port = 587;
            $mail->SMTPSecure = 'tls';
            return $mail->send();
        } catch (\Exception $e2) {
            // Fallback 2: Native PHP server mail() fallback
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
            $headers .= "From: IdentiTrack Admin Security <identitrack@identitrack.site>\r\n";
            return @mail($toEmail, "Security Code: {$otp} for IdentiTrack Admin", $mail->Body, $headers);
        }
    }
}
