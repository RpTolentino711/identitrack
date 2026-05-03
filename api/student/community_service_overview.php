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
  echo json_encode(['ok' => false, 'message' => 'Method not allowed.', 'data' => null]);
  exit;
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true) ?: [];
$studentId = trim((string)($body['student_id'] ?? ''));
if ($studentId === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'student_id required']);
  exit;
}

$student = db_one(
  "SELECT student_id, is_active
   FROM student
   WHERE student_id = :sid
   LIMIT 1",
  [':sid' => $studentId]
);

if (!$student) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'message' => 'Student not found.', 'data' => null]);
  exit;
}

if ((int)($student['is_active'] ?? 0) !== 1) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'message' => 'Student is not active.', 'data' => null]);
  exit;
}

$policy = student_account_mode($studentId);
// Informational overview is allowed even if account has restrictions

// Get assigned requirements & hours
$reqs = db_all("
  SELECT 
    requirement_id, task_name, location, hours_required, status, assigned_at, completed_at
  FROM community_service_requirement
  WHERE student_id = :sid AND status = 'ACTIVE'
  ORDER BY assigned_at DESC
", [':sid' => $studentId]);

// Sessions (clock-ins with hours)
$sessions = db_all("
  SELECT 
    session_id, requirement_id, time_in, time_out, login_method, logout_method, validated_by, sdo_notes,
    TIMESTAMPDIFF(SECOND, time_in, time_out)/3600 AS hours_done
  FROM community_service_session
  WHERE requirement_id IN (SELECT requirement_id FROM community_service_requirement WHERE student_id = :sid)
  AND time_out IS NOT NULL
  ORDER BY time_in DESC
", [':sid' => $studentId]);

$activeSession = db_one(
  "SELECT
      css.session_id,
      css.requirement_id,
      css.time_in,
      css.login_method,
      css.sdo_notes,
      csr.task_name,
      csr.location,
      csr.hours_required,
      csr.status
   FROM community_service_session css
   JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
   WHERE csr.student_id = :sid
     AND css.time_out IS NULL
   ORDER BY css.time_in DESC
   LIMIT 1",
  [':sid' => $studentId]
);

$pendingManual = db_one(
  "SELECT
      request_id,
      requirement_id,
      requested_at,
      reason,
      status
   FROM manual_login_request
   WHERE student_id = :sid
     AND status = 'PENDING'
   ORDER BY requested_at DESC
   LIMIT 1",
  [':sid' => $studentId]
);

$activeAdmin = db_one("
  SELECT admin_id
  FROM admin_user
  WHERE is_active = 1
  LIMIT 1
");

// Compute totals
$assigned = 0.0;
foreach ($reqs as $r) $assigned += (float)($r['hours_required']);
$completed = 0.0;
foreach ($sessions as $s) $completed += (float)($s['hours_done']);

// If the student already has ACTIVE community service, they've accepted — not under investigation
$isUnderInvestigation = ((string)$policy['mode'] === 'APPEAL_GRACE_PERIOD') && count($reqs) === 0;
$hasActiveAdmin = ($activeAdmin !== null && $activeAdmin !== false);

echo json_encode([
  'ok' => true,
  'message' => 'Loaded',
  'data' => [
    'requirements' => $reqs,
    'sessions' => $sessions,
    'hours_assigned' => $assigned,
    'hours_completed' => $completed,
    'has_assignment' => count($reqs) > 0,
    'active_session' => $activeSession ?: null,
    'pending_manual_request' => $pendingManual ?: null,
    'is_under_investigation' => $isUnderInvestigation,
    'investigation_message' => (string)$policy['message'],
    'has_active_admin' => $hasActiveAdmin,
  ]
]);