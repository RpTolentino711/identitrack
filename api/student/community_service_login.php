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

function json_out(bool $ok, string $message = '', $data = null, int $status = 200): void {
  http_response_code($status);
  echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data]);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(false, 'Method not allowed.', null, 405);
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

$studentId = trim((string)($body['student_id'] ?? ''));
$method = strtoupper(trim((string)($body['login_method'] ?? 'MANUAL')));
$requirementId = (int)($body['requirement_id'] ?? 0);
$reason = trim((string)($body['reason'] ?? ''));

if (!in_array($method, ['NFC', 'RFID', 'MANUAL'], true)) {
  $method = 'MANUAL';
}
if ($method === 'RFID') {
  $method = 'NFC';
}

if ($studentId === '' || $requirementId <= 0) {
  json_out(false, 'student_id and requirement_id are required.', null, 400);
}

require_student_api_auth($studentId);

$student = db_one(
  "SELECT student_id, is_active FROM student WHERE student_id = :sid LIMIT 1",
  [':sid' => $studentId]
);

if (!$student) json_out(false, 'Student not found.', null, 404);
if ((int)($student['is_active'] ?? 0) !== 1) json_out(false, 'Student is not active.', null, 403);

$requirement = db_one(
  "SELECT requirement_id, task_name, status
   FROM community_service_requirement
   WHERE requirement_id = :rid
     AND student_id = :sid
     AND status = 'ACTIVE'
   LIMIT 1",
  [':rid' => $requirementId, ':sid' => $studentId]
);

if (!$requirement) {
  json_out(false, 'Invalid or inactive community service assignment.', null, 400);
}

$activeSession = db_one(
  "SELECT session_id, time_in, login_method
   FROM community_service_session
   WHERE requirement_id = :rid
     AND time_out IS NULL
   LIMIT 1",
  [':rid' => $requirementId]
);

if ($activeSession) {
  json_out(true, 'Service timer is already running.', [
    'starts_timer' => true,
    'awaiting_admin' => false,
    'already_active' => true,
    'session_id' => (int)$activeSession['session_id'],
    'time_in' => (string)$activeSession['time_in'],
    'login_method' => (string)$activeSession['login_method'],
  ]);
}

if ($method === 'NFC') {
  $existingRequest = db_one(
    "SELECT request_id
     FROM manual_login_request
     WHERE requirement_id = :rid
       AND student_id = :sid
       AND status = 'PENDING'
     ORDER BY requested_at DESC
     LIMIT 1",
    [':rid' => $requirementId, ':sid' => $studentId]
  );

  if ($existingRequest) {
    db_exec(
      "UPDATE manual_login_request
       SET status = 'APPROVED',
           decided_by = NULL,
           decided_at = NOW(),
           decision_notes = 'Auto-started via NFC scan.'
       WHERE request_id = :id",
      [':id' => (int)$existingRequest['request_id']]
    );
  }

  db_exec(
    "INSERT INTO community_service_session
     (requirement_id, time_in, time_out, login_method, validated_by, sdo_notes, created_at, updated_at)
     VALUES (:rid, NOW(), NULL, 'NFC', NULL, NULL, NOW(), NOW())",
    [':rid' => $requirementId]
  );

  json_out(true, 'Scanner login successful. Your service timer has started.', [
    'starts_timer' => true,
    'awaiting_admin' => false,
    'session_id' => (int)db_last_id(),
  ]);
}

$pendingRequest = db_one(
  "SELECT request_id
   FROM manual_login_request
   WHERE requirement_id = :rid
     AND student_id = :sid
     AND status = 'PENDING'
   LIMIT 1",
  [':rid' => $requirementId, ':sid' => $studentId]
);

if ($pendingRequest) {
  json_out(true, 'A login request is already pending admin validation.', [
    'starts_timer' => false,
    'awaiting_admin' => true,
    'request_id' => (int)$pendingRequest['request_id'],
  ]);
}

  $params = [':rid' => $requirementId, ':sid' => $studentId, ':method' => $method, ':reason' => $reason];
  db_add_encryption_key($params);
  db_exec(
    "INSERT INTO manual_login_request (requirement_id, student_id, request_type, login_method, requested_at, reason, status)
     VALUES (:rid, :sid, 'LOGIN', :method, NOW(), " . db_encrypt_col('reason', ':reason') . ", 'PENDING')",
    $params
  );

$requestId = (int)db_last_id();

db_exec(
  "INSERT INTO notification (type, title, message, student_id, admin_id, related_table, related_id, is_read, is_deleted, created_at)
   VALUES ('MANUAL_LOGIN', 'Pending Manual Login', :msg, :sid, NULL, 'manual_login_request', :rid, 0, 0, NOW())",
  [
    ':msg' => 'Student ' . $studentId . ' has submitted a manual login request. SDO validation is required.',
    ':sid' => $studentId,
    ':rid' => $requestId,
  ]
);

json_out(true, 'Manual login request submitted. Wait for admin approval.', [
  'starts_timer' => false,
  'awaiting_admin' => true,
  'request_id' => $requestId,
]);
