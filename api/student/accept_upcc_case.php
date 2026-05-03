<?php
declare(strict_types=1);

require_once __DIR__ . '/../../database/database.php';
ensure_hearing_workflow_schema();

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
$caseId = (int)($body['case_id'] ?? 0);

if ($studentId === '' || $caseId <= 0) {
  json_out(false, 'student_id and case_id are required.', null, 400);
}

$student = db_one("SELECT is_active FROM student WHERE student_id = :sid", [':sid' => $studentId]);
if (!$student) json_out(false, 'Student not found.', null, 404);
if ((int)($student['is_active'] ?? 0) !== 1) json_out(false, 'Student is not active.', null, 403);

// Acceptance is allowed even if account has restrictions (like probation from the same case)

$case = db_one("SELECT case_id, status, decided_category FROM upcc_case WHERE case_id = :cid AND student_id = :sid", [
    ':cid' => $caseId,
    ':sid' => $studentId
]);

if (!$case) {
    json_out(false, 'Case not found or belongs to another student.', null, 404);
}

if ($case['status'] === 'RESOLVED') {
    json_out(false, 'This case is already resolved and accepted.', null, 400);
}

if ($case['status'] !== 'CLOSED') {
    json_out(false, 'This case is not ready to be accepted.', null, 400);
}

// Accept the decision by marking the case as RESOLVED
db_exec("UPDATE upcc_case SET status = 'RESOLVED' WHERE case_id = :cid", [':cid' => $caseId]);

// Log the activity
upcc_log_case_activity($caseId, 'SYSTEM', 0, 'STUDENT_ACCEPTED_DECISION', ['student_id' => $studentId]);

// If Category 4 (Exclusion) or 5 (Expulsion), freeze account immediately upon acceptance
if (in_array((int)$case['decided_category'], [4, 5], true)) {
    db_exec("UPDATE student SET is_active = 0 WHERE student_id = :sid", [':sid' => $studentId]);
}

// Activate any pending community service requirements tied to this case
db_exec("UPDATE community_service_requirement SET status = 'ACTIVE' WHERE student_id = :sid AND related_case_id = :cid AND status = 'PENDING_ACCEPTANCE'", [':sid' => $studentId, ':cid' => $caseId]);

json_out(true, 'UPCC decision accepted successfully.');
