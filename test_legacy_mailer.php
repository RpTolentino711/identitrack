<?php
require_once 'database/database.php';
require_once 'admin/class.phpmailer.php';
require_once 'admin/class.smtp.php';

$getEnv = function($key, $default) {
    return (string)($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default);
};

$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = $getEnv('SMTP_HOST', 'smtp.hostinger.com');
$mail->Port = 587;
$mail->SMTPAuth = true;
$mail->SMTPSecure = 'tls';
$mail->Username = $getEnv('SMTP_USER', 'identitrack@identitrack.site');
$mail->Password = $getEnv('SMTP_PASS', '');

$mail->SMTPDebug = 2; // ENable debug output!

try {
    $mail->setFrom($mail->Username, 'Test');
    $mail->addAddress($mail->Username);
    $mail->Subject = 'Test';
    $mail->Body = 'Test';
    $mail->send();
    echo "OK!";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage();
}
