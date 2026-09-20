<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once 'c:/xampp/htdocs/identitrack/database/database.php';
require_once 'c:/xampp/htdocs/identitrack/UPCC/class.phpmailer.php';
require_once 'c:/xampp/htdocs/identitrack/UPCC/class.smtp.php';

$mail = new PHPMailer(true);
$mail->CharSet   = 'UTF-8';
$mail->isSMTP();
$mail->Host      = 'smtp.hostinger.com';
$mail->Port      = 465;
$mail->SMTPAuth  = true;
$mail->SMTPSecure = 'ssl';
$mail->Username  = db_smtp_user();
$mail->Password  = 'Bonefacio@10';
$mail->SMTPDebug = 2;

try {
    $mail->setFrom(db_smtp_user(), 'UPCC Panel');
    $mail->addAddress('romeopaolotolentino@gmail.com', 'Test User');
    $mail->Subject = 'Test OTP Email';
    $mail->Body    = 'Your OTP code is 123456';
    $mail->send();
    echo "\nSUCCESS! Email sent successfully.\n";
} catch (Exception $e) {
    echo "\nFAILED: " . $e->getMessage() . "\n";
}
