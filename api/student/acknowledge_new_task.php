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

if ($studentId === '') {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'student_id required']);
  exit;
}

require_student_api_auth($studentId);

db_exec(
  "UPDATE community_service_session css
   JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
   SET css.task_is_new = 0
   WHERE csr.student_id = :sid AND css.time_out IS NULL",
  [':sid' => $studentId]
);

echo json_encode(['ok' => true, 'message' => 'New task badge acknowledged']);
