<?php
require_once __DIR__ . '/../../admin/otp_mailer.php';

$toEmail = 'identitrack@identitrack.site';
$toName = 'Test User';
$action = 'OTP Test';
$otp = '123456';

echo "Testing SMTP connection with new password...\n";
$res = send_admin_otp_email($toEmail, $toName, $action, $otp);
echo "Result: " . ($res ? "SUCCESS" : "FAILED") . "\n";
