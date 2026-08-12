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
$studentId = trim((string)($body['student_id'] ?? ''));
$password = (string)($body['password'] ?? '');

if ($password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Admin password is required to resume session.']);
    exit;
}

// Verify Admin Password
$adminRow = db_one("SELECT password_hash FROM admin_user WHERE admin_id = :aid AND is_active = 1", [':aid' => $adminId]);
if (!$adminRow || !password_verify($password, (string)$adminRow['password_hash'])) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => 'Incorrect Admin Password. Unauthorized.']);
    exit;
}

if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'session_id is required.']);
    exit;
}

ensure_community_service_pause_schema();

$session = db_one(
    "SELECT css.session_id, css.requirement_id, css.status, css.paused_at, css.accum_paused_seconds, csr.student_id, csr.related_case_id
     FROM community_service_session css
     JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
     WHERE css.session_id = :sid AND css.time_out IS NULL",
    [':sid' => $sessionId]
);

if (!$session) {
    http_response_code(444);
    echo json_encode(['ok' => false, 'message' => 'Active session not found or already completed.']);
    exit;
}

if ($session['status'] !== 'PAUSED') {
    echo json_encode(['ok' => true, 'message' => 'Session is already active.']);
    exit;
}

$pausedAt = $session['paused_at'];
$addedPausedSecs = 0;
if (!empty($pausedAt)) {
    $addedPausedSecs = max(0, time() - strtotime($pausedAt));
}
$newAccumSecs = (int)($session['accum_paused_seconds'] ?? 0) + $addedPausedSecs;

// Resume session
db_exec(
    "UPDATE community_service_session 
     SET status = 'ACTIVE', pause_reason = NULL, paused_at = NULL, accum_paused_seconds = :accum 
     WHERE session_id = :sid",
    [':accum' => $newAccumSecs, ':sid' => $sessionId]
);

// Log activity for student & admin history
if (!empty($session['related_case_id'])) {
    upcc_log_case_activity((int)$session['related_case_id'], 'ADMIN', $adminId, 'COMMUNITY_SERVICE_RESUMED', [
        'session_id' => $sessionId,
        'resumed_by_admin' => $adminId,
        'resumed_at' => date('Y-m-d H:i:s')
    ]);
}

echo json_encode([
    'ok' => true,
    'message' => 'Student community service session has been resumed.',
    'data' => [
        'session_id' => $sessionId,
        'status' => 'ACTIVE',
        'student_id' => $session['student_id']
    ]
]);
