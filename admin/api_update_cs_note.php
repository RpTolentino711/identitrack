<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../database/database.php';

header('Content-Type: application/json; charset=utf-8');

// Require Admin Authentication
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
$sdoNotes = trim((string)($body['sdo_notes'] ?? ''));

if ($sessionId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'session_id is required.']);
    exit;
}

// User Requirement: Cannot save an empty note/location
if ($sdoNotes === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'SDO Notes / Assignment Location cannot be empty. Please enter task details.']);
    exit;
}

ensure_community_service_pause_schema();

// Check if active session exists
$session = db_one(
    "SELECT css.session_id, css.requirement_id, csr.student_id, csr.related_case_id
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

// Update SDO Notes and mark task_is_new = 1 so student gets "NEW TASK" badge notification
db_exec(
    "UPDATE community_service_session 
     SET sdo_notes = :notes, task_is_new = 1, updated_at = NOW() 
     WHERE session_id = :sid",
    [':notes' => $sdoNotes, ':sid' => $sessionId]
);

// Log activity if linked to a UPCC case
if (!empty($session['related_case_id'])) {
    upcc_log_case_activity((int)$session['related_case_id'], 'ADMIN', $adminId, 'COMMUNITY_SERVICE_NOTE_UPDATED', [
        'session_id' => $sessionId,
        'sdo_notes' => $sdoNotes,
        'updated_by' => $adminId
    ]);
}

echo json_encode([
    'ok' => true,
    'message' => 'SDO Notes updated successfully! Student will see the NEW TASK badge in their portal.',
    'data' => [
        'session_id' => $sessionId,
        'sdo_notes' => $sdoNotes,
        'task_is_new' => true
    ]
]);
