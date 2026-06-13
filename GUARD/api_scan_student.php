<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['guard_logged_in']) || $_SESSION['guard_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$scanInput = trim((string)($_GET['scan'] ?? ''));
if ($scanInput === '') {
  echo json_encode(['ok' => false, 'message' => 'Missing scan value.']);
  exit;
}

require_once __DIR__ . '/../database/database.php';

function scanner_hash_value(string $rawValue): string {
  $pepper = 'IDENTITRACK_SCANNER_PEPPER_V1_CHANGE_ME';
  $normalized = strtoupper(trim($rawValue));
  return hash('sha256', $pepper . ':' . $normalized);
}

function student_has_scanner_hash_column(): bool {
  static $hasColumn = null;
  if ($hasColumn !== null) return $hasColumn;
  $row = db_one(
    "SELECT 1 AS ok FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'student' AND column_name = 'scanner_id_hash' LIMIT 1"
  );
  $hasColumn = (bool)$row;
  return $hasColumn;
}

$params = [':sid' => $scanInput];
db_add_encryption_key($params);

$student = db_one(
  "SELECT student_id, year_level, program, section, " . db_decrypt_cols(['student_fn', 'student_ln']) . "
   FROM student
   WHERE student_id = :sid AND is_active = 1
   LIMIT 1",
  $params
);

if (!$student && student_has_scanner_hash_column()) {
  $hashParams = [':scanner_hash' => scanner_hash_value($scanInput)];
  db_add_encryption_key($hashParams);
  $student = db_one(
    "SELECT student_id, year_level, program, section, " . db_decrypt_cols(['student_fn', 'student_ln']) . "
     FROM student
     WHERE scanner_id_hash = :scanner_hash AND is_active = 1
     LIMIT 1",
    $hashParams
  );
}

if (!$student) {
  echo json_encode(['ok' => false, 'message' => 'No active student record found.']);
  exit;
}

echo json_encode([
  'ok' => true,
  'student_id' => $student['student_id'],
  'student_fn' => $student['student_fn'],
  'student_ln' => $student['student_ln'],
  'year_level' => $student['year_level'],
  'program' => $student['program'],
  'section' => $student['section'],
]);
