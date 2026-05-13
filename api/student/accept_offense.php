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
$offenseId = (int)($body['offense_id'] ?? 0);

if ($studentId === '' || $offenseId <= 0) {
  json_out(false, 'student_id and offense_id are required.', null, 400);
}

require_student_api_auth($studentId);

$student = db_one("SELECT is_active FROM student WHERE student_id = :sid", [':sid' => $studentId]);
if (!$student) json_out(false, 'Student not found.', null, 404);
if ((int)($student['is_active'] ?? 0) !== 1) json_out(false, 'Student is not active.', null, 403);

// Acceptance is allowed even if account has restrictions

$offense = db_one("SELECT offense_id, student_id, level, status, acknowledged_at FROM offense WHERE offense_id = :oid AND student_id = :sid", [
    ':oid' => $offenseId,
    ':sid' => $studentId
]);

if (!$offense) {
    json_out(false, 'Offense not found or belongs to another student.', null, 404);
}

if ($offense['status'] === 'VOID') {
    json_out(false, 'Cannot accept a voided offense.', null, 400);
}

if ($offense['acknowledged_at'] !== null) {
    json_out(false, 'You have already accepted this offense.', null, 400);
}

db_exec("UPDATE offense SET acknowledged_at = NOW() WHERE offense_id = :oid", [':oid' => $offenseId]);

json_out(true, 'Offense accepted successfully.');
