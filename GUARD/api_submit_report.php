<?php
session_start();
header('Content-Type: application/json');

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
$ins = $pdo->prepare("INSERT INTO guard_violation_report
        (student_id, submitted_by, offense_type_id, date_committed, description, status)
    VALUES
        (:sid, :gid, :oid, :dt, UNHEX(AES_ENCRYPT(:desc, UNHEX(SHA2(:key, 256)))), 'PENDING')
");
$key = db_encryption_key();
$ins->execute([
    ':sid'  => $studentId,
    ':gid'  => $guardId,
    ':oid'  => $offenseTypeId,
    ':dt'   => $dateFormatted,
    ':desc' => $description !== '' ? $description : '',
    ':key'  => $key,
]);

$reportId = $pdo->lastInsertId();

// Insert notification for admin
$notifStmt = $pdo->prepare("INSERT INTO notification
        (type, title, message, student_id, related_table, related_id, is_read)
    VALUES
        ('GUARD_REPORT',
         :title,
         :msg,
         :sid,
         'guard_violation_report',
         :rid,
         0)
");

// Get offense name and student name (decrypted) for notification
$infoStmt = $pdo->prepare("SELECT 
        AES_DECRYPT(UNHEX(s.student_fn), UNHEX(SHA2(:key, 256))) as student_fn, 
        AES_DECRYPT(UNHEX(s.student_ln), UNHEX(SHA2(:key, 256))) as student_ln, 
        ot.name AS offense_name, ot.level
    FROM student s
    JOIN offense_type ot ON ot.offense_type_id = :oid
    WHERE s.student_id = :sid
");
$infoStmt->execute([':oid' => $offenseTypeId, ':sid' => $studentId, ':key' => $key]);
$info = $infoStmt->fetch(PDO::FETCH_ASSOC);

if ($info) {
    $fullName = $info['student_fn'] . ' ' . $info['student_ln'];
    $notifStmt->execute([
        ':title' => 'Guard Report: ' . $info['level'] . ' offense filed for ' . $fullName,
        ':msg'   => 'Guard submitted a violation report for ' . $fullName . '. Offense: ' . $info['offense_name'] . '. Pending admin review.',
        ':sid'   => $studentId,
        ':rid'   => $reportId,
    ]);
}

echo json_encode(['success' => true, 'report_id' => $reportId]);