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
$appealId = (int)($body['appeal_id'] ?? 0);

if ($studentId === '' || $appealId <= 0) {
    json_out(false, 'student_id and appeal_id are required.', null, 400);
}

require_student_api_auth($studentId);

db_exec(
    "UPDATE student_appeal_request SET is_seen_by_student = 1 WHERE appeal_id = :aid AND student_id = :sid",
    [':aid' => $appealId, ':sid' => $studentId]
);

json_out(true, 'Appeal acknowledged.');
