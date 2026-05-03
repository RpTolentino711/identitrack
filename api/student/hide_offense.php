<?php
declare(strict_types=1);

ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

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
$offenseId = (int)($body['offense_id'] ?? 0);

if ($studentId === '') json_out(false, 'student_id is required.', null, 400);
if ($offenseId === 0) json_out(false, 'offense_id is required.', null, 400);

$student = db_one(
  "SELECT student_id, is_active FROM student WHERE student_id = :sid LIMIT 1",
  [':sid' => $studentId]
);

if (!$student) json_out(false, 'Student not found.', null, 404);
if ((int)$student['is_active'] !== 1) json_out(false, 'Student is not active.', null, 403);

// Perform soft delete
if ($offenseId < -1000) {
  // It's a bundle! Calculate which set of 3 minors it refers to.
  // Bundle -1001 is the first set of 3, -1002 is the second, etc.
  $bundleIndex = abs($offenseId + 1000) - 1; 
  
  // Get all active minors for this student, oldest first (matching offense_list.php logic)
  $minors = db_all(
    "SELECT offense_id FROM offense WHERE student_id = :sid AND level = 'MINOR' AND status <> 'VOID' ORDER BY date_committed ASC, offense_id ASC",
    [':sid' => $studentId]
  );
  
  $toHide = array_slice($minors, $bundleIndex * 3, 3);
  if (count($toHide) > 0) {
    foreach ($toHide as $m) {
      db_exec("UPDATE offense SET is_deleted_by_student = 1 WHERE offense_id = :oid", [':oid' => $m['offense_id']]);
    }
    json_out(true, 'Offense bundle hidden successfully.');
  } else {
    json_out(false, 'Bundle not found or already hidden.', null, 404);
  }
} else {
  db_exec(
    "UPDATE offense SET is_deleted_by_student = 1 WHERE offense_id = :oid",
    [':oid' => $offenseId]
  );
  json_out(true, 'Offense hidden successfully.');
}
