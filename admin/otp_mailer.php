<?php
// File: admin/otp_mailer.php
require_once __DIR__ . '/class.phpmailer.php';
require_once __DIR__ . '/class.smtp.php';
require_once __DIR__ . '/../database/database.php';

function send_admin_otp_email(string $toEmail, string $toName, string $action, string $otp): bool {
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = get_env_var('SMTP_HOST', 'smtp.hostinger.com');
    $mail->Port = (int)get_env_var('SMTP_PORT', 465);
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = get_env_var('SMTP_SECURE', 'ssl');
    $mail->SMTPAutoTLS = true;
    $mail->Timeout = 15;

    // ✅ SDO SMTP Credentials
    $mail->Username = db_smtp_user();
    $mail->Password = db_smtp_pass();

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
        // Fallback 1: Gmail Backup SMTP
        try {
            $mail->Host = (string)get_env_var('SMTP_BACKUP_HOST', 'smtp.gmail.com');
            $mail->Port = (int)get_env_var('SMTP_BACKUP_PORT', 465);
            $mail->SMTPSecure = (string)get_env_var('SMTP_BACKUP_SECURE', 'ssl');
            $mail->Username = db_smtp_backup_user();
            $mail->Password = db_smtp_backup_pass();
            $mail->setFrom($mail->Username, 'IdentiTrack Admin Security');
            return $mail->send();
        } catch (\Exception $e2) {
            // Fallback 2: Native PHP server mail() fallback
            $headers  = "MIME-Version: 1.0\r\n";
            $headers .= "Content-type: text/html; charset=UTF-8\r\n";
            $headers .= "From: IdentiTrack Admin Security <" . db_smtp_user() . ">\r\n";
            return @mail($toEmail, "Security Code: {$otp} for IdentiTrack Admin", $mail->Body, $headers);
        }
    }
}
