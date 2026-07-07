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
$response = strtoupper(trim((string)($body['response'] ?? '')));

if ($studentId === '' || $caseId <= 0 || !in_array($response, ['ACCEPTED', 'DECLINED'], true)) {
  json_out(false, 'student_id, case_id, and response (ACCEPTED or DECLINED) are required.', null, 400);
}

require_student_api_auth($studentId);

$student = db_one("SELECT is_active FROM student WHERE student_id = :sid", [':sid' => $studentId]);
if (!$student) json_out(false, 'Student not found.', null, 404);
if ((int)($student['is_active'] ?? 0) !== 1) json_out(false, 'Student is not active.', null, 403);

$case = db_one("SELECT case_id, status FROM upcc_case WHERE case_id = :cid AND student_id = :sid", [
    ':cid' => $caseId,
    ':sid' => $studentId
]);

if (!$case) {
    json_out(false, 'Case not found or belongs to another student.', null, 404);
}

if (!in_array($case['status'], ['PENDING', 'UNDER_INVESTIGATION', 'UNDER_APPEAL'], true)) {
    json_out(false, 'Hearing schedule cannot be updated for this case state.', null, 400);
}

db_exec("UPDATE upcc_case SET student_hearing_response = :resp WHERE case_id = :cid", [
    ':resp' => $response,
    ':cid' => $caseId
]);

$activityAction = $response === 'ACCEPTED' ? 'STUDENT_ACCEPTED_HEARING' : 'STUDENT_DECLINED_HEARING';
upcc_log_case_activity($caseId, 'SYSTEM', 0, $activityAction, ['student_id' => $studentId]);

json_out(true, 'Hearing response submitted successfully.');
