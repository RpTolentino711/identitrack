<?php
// File: admin/otp_mailer.php
require_once __DIR__ . '/class.phpmailer.php';
require_once __DIR__ . '/class.smtp.php';
require_once __DIR__ . '/../database/database.php';

function send_admin_otp_email(string $toEmail, string $toName, string $action, string $otp): bool {
    $mail = new PHPMailer(true);
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host = 'smtp.gmail.com';
    $mail->Port = 587;
    $mail->SMTPAuth = true;
    $mail->SMTPSecure = 'tls';

    // ✅ SDO SMTP Credentials
    $mail->Username = 'romeopaolotolentino@gmail.com';
    $mail->Password = 'xhgg ajje ixak ajoj'; 

    $mail->setFrom($mail->Username, 'IdentiTrack Admin Security');
    $mail->addAddress($toEmail, $toName);
    $mail->isHTML(true);
    $mail->Subject = "Security Code: {$otp} for IdentiTrack Admin";

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
    <div style='font-family: Arial, sans-serif; background-color: #f4f7ff; padding: 40px; color: #333;'>
        <div style='max-width: 500px; margin: 0 auto; background: #fff; padding: 30px; border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.05);'>
            $logoHtml
            <h2 style='color: #1e293b; margin-top: 0;'>Verification Code</h2>
            <p style='font-size: 16px; color: #64748b;'>Hello <strong>$toName</strong>,</p>
            <p style='font-size: 16px; color: #64748b;'>A login attempt was made for your account. Please use the code below to complete your authentication for <strong>$actionLabel</strong>.</p>
            
            <div style='background: #f1f5f9; border-radius: 12px; padding: 25px; text-align: center; margin: 25px 0;'>
                <span style='font-size: 36px; font-weight: 800; letter-spacing: 8px; color: #3b4a9e;'>$otp</span>
            </div>
            
            <p style='font-size: 14px; color: #94a3b8; line-height: 1.5;'>This code is valid for 5 minutes. If you did not request this code, please secure your account immediately.</p>
            
            <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 25px 0;'>
            <p style='font-size: 12px; color: #94a3b8; text-align: center;'>&copy; " . date('Y') . " IdentiTrack SDO System. All rights reserved.</p>
        </div>
    </div>
    ";

    return $mail->send();
}
