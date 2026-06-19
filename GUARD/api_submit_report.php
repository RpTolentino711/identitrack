<?php
session_start();
header('Content-Type: application/json');

require_once __DIR__ . '/../admin/class.phpmailer.php';
require_once __DIR__ . '/../admin/class.smtp.php';

if (!isset($_SESSION['guard_logged_in']) || $_SESSION['guard_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

$studentId     = trim($_POST['student_id']      ?? '');
$offenseTypeId = intval($_POST['offense_type_id'] ?? 0);
$dateCommitted = trim($_POST['date_committed']   ?? '');
$description   = trim($_POST['description']      ?? '');
$guardId       = $_SESSION['guard_id'];

// Validate
if ($studentId === '') {
    echo json_encode(['success' => false, 'message' => 'Student ID is required.']);
    exit;
}
if ($offenseTypeId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Please select a valid offense type.']);
    exit;
}
if ($dateCommitted === '') {
    echo json_encode(['success' => false, 'message' => 'Date & time of incident is required.']);
    exit;
}

// Parse datetime
$dt = DateTime::createFromFormat('Y-m-d\TH:i', $dateCommitted);
if (!$dt) {
    $dt = DateTime::createFromFormat('Y-m-d H:i:s', $dateCommitted);
}
if (!$dt) {
    echo json_encode(['success' => false, 'message' => 'Invalid date/time format.']);
    exit;
}
$dateFormatted = $dt->format('Y-m-d H:i:s');

require_once __DIR__ . '/../database/database.php';
$pdo = getConnection();

// Verify student exists
$stCheck = $pdo->prepare("SELECT student_id FROM student WHERE student_id = :sid AND is_active = 1 LIMIT 1");
$stCheck->execute([':sid' => $studentId]);
if (!$stCheck->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Student not found or inactive.']);
    exit;
}

// Verify offense type exists
$otCheck = $pdo->prepare("SELECT offense_type_id FROM offense_type WHERE offense_type_id = :oid AND is_active = 1 LIMIT 1");
$otCheck->execute([':oid' => $offenseTypeId]);
if (!$otCheck->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Invalid offense type.']);
    exit;
}

// Insert report with encrypted description
$sqlIns = "INSERT INTO guard_violation_report
        (student_id, submitted_by, offense_type_id, date_committed, description, status)
    VALUES
        (:sid, :gid, :oid, :dt, " . db_encrypt_col('description', ':desc') . ", 'PENDING')";

$paramsIns = [
    ':sid'  => $studentId,
    ':gid'  => $guardId,
    ':oid'  => $offenseTypeId,
    ':dt'   => $dateFormatted,
    ':desc' => $description !== '' ? $description : '',
];
db_add_encryption_key($paramsIns);
db_exec($sqlIns, $paramsIns);

$reportId = db_last_id();

// Insert notification for admin
$sqlNotif = "INSERT INTO notification
        (type, title, message, student_id, related_table, related_id, is_read)
    VALUES
        ('GUARD_REPORT', :title, :msg, :sid, 'guard_violation_report', :rid, 0)";

// Get offense name and student name (decrypted) for notification
$sqlInfo = "SELECT 
        " . db_decrypt_cols(['student_fn', 'student_ln'], 's') . ", 
        ot.name AS offense_name, ot.level
    FROM student s
    JOIN offense_type ot ON ot.offense_type_id = :oid
    WHERE s.student_id = :sid";

$paramsInfo = [':oid' => $offenseTypeId, ':sid' => $studentId];
db_add_encryption_key($paramsInfo);
$info = db_one($sqlInfo, $paramsInfo);

    if ($info) {
        $fullName = $info['student_fn'] . ' ' . $info['student_ln'];
        db_exec($sqlNotif, [
            ':title' => 'Guard Report: ' . $info['level'] . ' offense filed for ' . $fullName,
            ':msg'   => 'Guard submitted a violation report for ' . $fullName . '. Offense: ' . $info['offense_name'] . '. Pending admin review.',
            ':sid'   => $studentId,
            ':rid'   => $reportId,
        ]);
        
        // Send email to Admin
        $mail = new PHPMailer(true);
        try {
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.hostinger.com';
            $mail->Port = 587;
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = 'tls';
            $mail->Username = $_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site';
            $mail->Password = $_ENV['SMTP_PASS'] ?? '';
            
            $mail->setFrom($mail->Username, 'IdentiTrack System');
            $mail->addAddress($mail->Username, 'IdentiTrack Admin');
            
            $mail->isHTML(true);
            $mail->Subject = 'New Guard Report: ' . $fullName;
            
            $logoPath = __DIR__ . '/../assets/logo.png';
            if (file_exists($logoPath)) {
                $mail->addEmbeddedImage($logoPath, 'identitrack_logo', 'logo.png');
            }
            
            $dateDisp = date('F j, Y g:i A', strtotime($dateFormatted));
            
            $mail->Body = "
            <!DOCTYPE html>
            <html>
            <body style='background-color:#f1f5f9; padding:40px 0; font-family:sans-serif;'>
              <div style='max-width:600px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 10px 20px rgba(0,0,0,0.1);'>
                <div style='background:#1e3a8a; padding:30px; text-align:center;'>
                    <img src='cid:identitrack_logo' style='width:60px; border-radius:12px; margin-bottom:15px;'>
                    <h2 style='color:#fff; margin:0;'>New Guard Report</h2>
                </div>
                <div style='padding:30px; color:#333; line-height:1.6;'>
                    <p>Hello Admin,</p>
                    <p>A new violation report has been submitted by a Guard and is pending your review.</p>
                    <div style='background:#f8fafc; padding:20px; border-radius:8px; border:1px solid #e2e8f0; margin-bottom:20px;'>
                        <p style='margin:0 0 10px 0;'><strong>Student:</strong> {$fullName} ({$studentId})</p>
                        <p style='margin:0 0 10px 0;'><strong>Offense:</strong> {$info['offense_name']} ({$info['level']})</p>
                        <p style='margin:0 0 10px 0;'><strong>Date & Time:</strong> {$dateDisp}</p>
                        <p style='margin:0;'><strong>Guard ID:</strong> {$guardId}</p>
                    </div>
                    <p>Please log in to the Admin Dashboard to approve or reject this report.</p>
                </div>
              </div>
            </body>
            </html>";
            
            $mail->AltBody = "New Guard Report for {$fullName}.\nOffense: {$info['offense_name']}\nDate: {$dateDisp}\nPlease log in to review.";
            $mail->send();
        } catch (Exception $e) {
            error_log('Guard report admin email error: ' . $e->getMessage());
        }
    }

echo json_encode(['success' => true, 'report_id' => $reportId]);