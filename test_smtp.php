<?php
require_once __DIR__ . '/database/database.php';
require_once __DIR__ . '/vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
$mail = new PHPMailer(true);
$mail->isSMTP();
$mail->Host = 'smtp.hostinger.com';
$mail->Port = 587;
$mail->SMTPAuth = true;
$mail->SMTPSecure = 'tls';
$mail->Username = 'identitrack@identitrack.site';
$mail->Password = 'Pogilameg@10';
$mail->SMTPDebug = 2; // Enable verbose debug output

try {
    $mail->setFrom('identitrack@identitrack.site');
    $mail->addAddress('identitrack@identitrack.site');
    $mail->Subject = 'Test';
    $mail->Body = 'Test';
    $mail->send();
    echo 'OK';
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage();
}
