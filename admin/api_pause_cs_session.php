<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../database/database.php';

header('Content-Type: application/json; charset=utf-8');

// Check admin authentication
$admin = admin_current();
if (!$admin) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized admin session. Please log in.']);
    exit;
}

$adminId = (int)$admin['admin_id'];
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true) ?: $_POST;

$sessionId = (int)($body['session_id'] ?? 0);
$reason = trim((string)($body['reason'] ?? 'Manually paused by Admin'));
$password = (string)($body['password'] ?? '');

if ($password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Admin password is required to pause session.']);
    exit;
}

// Verify Admin Password
$adminRow = db_one("SELECT password_hash FROM admin_user WHERE admin_id = :aid AND is_active = 1", [':aid' => $adminId]);
if (!$adminRow || !password_verify($password, (string)$adminRow['password_hash'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Incorrect Admin Password. Unauthorized.']);
    exit;
}

if ($reason === '') {
    $reason = 'Manually paused by Admin';
}

if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'session_id is required.']);
    exit;
}

ensure_community_service_pause_schema();

$session = db_one(
    "SELECT css.session_id, css.requirement_id, css.status, csr.student_id, csr.related_case_id
     FROM community_service_session css
     JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
     WHERE css.session_id = :sid AND css.time_out IS NULL",
    [':sid' => $sessionId]
);

if (!$session) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'message' => 'Active session not found or already completed.']);
    exit;
}

if ($session['status'] === 'PAUSED') {
    echo json_encode(['ok' => true, 'message' => 'Session is already paused.']);
    exit;
}

// Update session status to PAUSED
db_exec(
    "UPDATE community_service_session 
     SET status = 'PAUSED', pause_reason = :reason, paused_at = NOW() 
     WHERE session_id = :sid",
    [':reason' => $reason, ':sid' => $sessionId]
);

// Log activity
if (!empty($session['related_case_id'])) {
    upcc_log_case_activity((int)$session['related_case_id'], 'ADMIN', $adminId, 'COMMUNITY_SERVICE_PAUSED', [
        'session_id' => $sessionId,
        'paused_by_admin' => $adminId,
        'reason' => $reason,
        'paused_at' => date('Y-m-d H:i:s')
    ]);
}

// Fetch student name
$sRow = db_one("SELECT student_fn, student_ln FROM student WHERE student_id = :sid", [':sid' => $session['student_id']]);
$studentName = $sRow ? trim($sRow['student_fn'] . ' ' . $sRow['student_ln']) : $session['student_id'];

// Create notification for admin
db_exec(
    "INSERT INTO notification (type, title, message, student_id, admin_id, related_table, related_id, is_read, is_deleted, created_at)
     VALUES ('COMMUNITY_SERVICE_PAUSED', 'Service Timer Paused', :msg, :sid, :aid, 'community_service_session', :session_id, 0, 0, NOW())",
    [
        ':msg' => "Community service timer for {$studentName} ({$session['student_id']}) was manually paused by Admin. Reason: {$reason}.",
        ':sid' => $session['student_id'],
        ':aid' => $adminId,
        ':session_id' => (string)$sessionId
    ]
);

// Send automated email notification to student
$studentParams = [':sid' => $session['student_id']];
db_add_encryption_key($studentParams);
$studentInfo = db_one("SELECT " . db_decrypt_cols(['student_fn', 'student_ln', 'student_email']) . " FROM student WHERE student_id = :sid", $studentParams);

if ($studentInfo && !empty($studentInfo['student_email'])) {
    require_once __DIR__ . '/../UPCC/class.phpmailer.php';
    require_once __DIR__ . '/../UPCC/class.smtp.php';

    try {
        $mail = new PHPMailer(true);
        $mail->CharSet = 'UTF-8';
        $mail->isSMTP();
        $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.hostinger.com';
        $mail->Port = 587;
        $mail->SMTPAuth = true;
        $mail->SMTPSecure = 'tls';
        $mail->Username = $_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site';
        $mail->Password = $_ENV['SMTP_PASS'] ?? '';
        $mail->Timeout = 20;

        $mail->setFrom($_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site', 'IdentiTrack SDO');
        $mail->addAddress($studentInfo['student_email'], $studentName);
        $mail->Subject = "⚠️ IdentiTrack: Community Service Timer Paused by Admin";
        $mail->isHTML(true);
        $mail->Body = "
            <div style='font-family:sans-serif; max-width:600px; line-height:1.6; color:#333; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px;'>
                <h2 style='color:#dc2626; margin-top: 0;'>⏸️ Community Service Timer Paused</h2>
                <p>Hello <b>" . htmlspecialchars($studentName) . "</b>,</p>
                <p>Your active community service timer has been <b>PAUSED</b> by the SDO Administration.</p>
                <div style='background:#fef2f2; padding:16px; border-radius:10px; border: 1px solid #fecaca; margin:20px 0;'>
                    <p style='margin:4px 0;'><b>Student ID:</b> {$session['student_id']}</p>
                    <p style='margin:4px 0;'><b>Reason:</b> " . htmlspecialchars($reason) . "</p>
                    <p style='margin:4px 0;'><b>Time Paused:</b> " . date('M j, Y g:i A') . "</p>
                </div>
                <p><b>Required Action:</b> Please approach the SDO Admin to resume your community service timer.</p>
                <p style='margin-top:30px; font-size:12px; color:#94a3b8; border-top: 1px solid #f1f5f9; padding-top: 15px;'>This is an automated notification from IdentiTrack. Please do not reply.</p>
            </div>
        ";
        $mail->send();
    } catch (Exception $e) {
        error_log("Failed to send CS pause notification email to {$studentInfo['student_email']}: " . $e->getMessage());
    }
}

echo json_encode([
    'ok' => true,
    'message' => 'Student community service session has been paused.',
    'data' => [
        'session_id' => $sessionId,
        'status' => 'PAUSED',
        'student_id' => $session['student_id']
    ]
]);
