<?php
declare(strict_types=1);
require_once __DIR__ . '/../../database/database.php';
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'message' => 'Method not allowed.']);
  exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true) ?: [];
$studentId = trim((string)($body['student_id'] ?? ''));
$sessionId = (int)($body['session_id'] ?? 0);
$reason = trim((string)($body['reason'] ?? 'Stationary for 5 minutes'));

if ($studentId === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'student_id is required.']);
  exit;
}

require_student_api_auth($studentId);
ensure_community_service_pause_schema();

// Find active session for this student
if ($sessionId > 0) {
  $session = db_one(
    "SELECT css.session_id, css.requirement_id, css.status, css.time_in
     FROM community_service_session css
     JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
     WHERE css.session_id = :sid AND csr.student_id = :st_id AND css.time_out IS NULL",
    [':sid' => $sessionId, ':st_id' => $studentId]
  );
} else {
  $session = db_one(
    "SELECT css.session_id, css.requirement_id, css.status, css.time_in
     FROM community_service_session css
     JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
     WHERE csr.student_id = :st_id AND css.time_out IS NULL
     ORDER BY css.time_in DESC LIMIT 1",
    [':st_id' => $studentId]
  );
}

if (!$session) {
  echo json_encode(['ok' => false, 'message' => 'No active clock-in session found to pause.']);
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
  [':reason' => $reason, ':sid' => $session['session_id']]
);

// Log activity if associated with a case
$csr = db_one("SELECT related_case_id FROM community_service_requirement WHERE requirement_id = :rid", [':rid' => $session['requirement_id']]);
if ($csr && !empty($csr['related_case_id'])) {
  upcc_log_case_activity((int)$csr['related_case_id'], 'SYSTEM', 0, 'COMMUNITY_SERVICE_PAUSED', [
    'session_id' => $session['session_id'],
    'reason' => $reason,
    'paused_at' => date('Y-m-d H:i:s')
  ]);
}

// Fetch student name for notification
$sRow = db_one("SELECT student_fn, student_ln FROM student WHERE student_id = :sid", [':sid' => $studentId]);
$studentName = $sRow ? trim($sRow['student_fn'] . ' ' . $sRow['student_ln']) : $studentId;

// Send notification to all active admins
$adminIds = db_all("SELECT admin_id FROM admin_user WHERE is_active = 1") ?: [];
foreach ($adminIds as $adm) {
    db_exec(
        "INSERT INTO notification (type, title, message, student_id, admin_id, related_table, related_id, is_read, is_deleted, created_at)
         VALUES ('COMMUNITY_SERVICE_INACTIVITY_PAUSED', '⚠️ Inactivity Auto-Paused', :msg, :sid, :aid, 'community_service_session', :session_id, 0, 0, NOW())",
        [
            ':msg' => "App detected {$studentName} ({$studentId}) has not moved for 5 minutes. Their community service time has been automatically paused.",
            ':sid' => $studentId,
            ':aid' => $adm['admin_id'],
            ':session_id' => (string)$session['session_id']
        ]
    );
}

// Send automated email notification to student's email address
$studentParams = [':sid' => $studentId];
db_add_encryption_key($studentParams);
$studentInfo = db_one("SELECT " . db_decrypt_cols(['student_fn', 'student_ln', 'student_email']) . " FROM student WHERE student_id = :sid", $studentParams);

if ($studentInfo && !empty($studentInfo['student_email'])) {
    require_once __DIR__ . '/../../UPCC/class.phpmailer.php';
    require_once __DIR__ . '/../../UPCC/class.smtp.php';

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
        $mail->Subject = "⚠️ IdentiTrack: Community Service Timer Paused";
        $mail->isHTML(true);
        $mail->Body = "
            <div style='font-family:sans-serif; max-width:600px; line-height:1.6; color:#333; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px;'>
                <h2 style='color:#dc2626; margin-top: 0;'>⏸️ Community Service Timer Paused</h2>
                <p>Hello <b>" . htmlspecialchars($studentName) . "</b>,</p>
                <p>Your active community service timer has been <b>PAUSED</b>.</p>
                <div style='background:#fef2f2; padding:16px; border-radius:10px; border: 1px solid #fecaca; margin:20px 0;'>
                    <p style='margin:4px 0;'><b>Student ID:</b> {$studentId}</p>
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
  'message' => 'Your clock-in has been paused. The system sensed you stopped moving for 5 minutes. Please contact the Admin to resume your community service.',
  'data' => [
    'session_id' => $session['session_id'],
    'status' => 'PAUSED',
    'paused_at' => date('Y-m-d H:i:s')
  ]
]);
