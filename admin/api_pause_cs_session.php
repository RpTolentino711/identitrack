<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../database/database.php';

header('Content-Type: application/json; charset=utf-8');

// Check admin authentication
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized admin session.']);
    exit;
}

$adminId = (int)$_SESSION['user_id'];
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true) ?: $_POST;

$sessionId = (int)($body['session_id'] ?? 0);
$reason = trim((string)($body['reason'] ?? 'Manually paused by Admin'));
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

echo json_encode([
    'ok' => true,
    'message' => 'Student community service session has been paused.',
    'data' => [
        'session_id' => $sessionId,
        'status' => 'PAUSED',
        'student_id' => $session['student_id']
    ]
]);
