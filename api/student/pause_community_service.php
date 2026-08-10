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

echo json_encode([
  'ok' => true,
  'message' => 'Your clock-in has been paused. The system sensed you stopped moving for 5 minutes. Please contact the Admin to resume your community service.',
  'data' => [
    'session_id' => $session['session_id'],
    'status' => 'PAUSED',
    'paused_at' => date('Y-m-d H:i:s')
  ]
]);
