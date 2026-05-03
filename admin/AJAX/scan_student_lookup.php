<?php
declare(strict_types=1);

require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$scanInput = trim((string)($_GET['scan'] ?? ''));
if ($scanInput === '') {
  echo json_encode([
    'ok' => false,
    'message' => 'Missing scan value.'
  ]);
  exit;
}

function scanner_hash_value(string $rawValue): string {
  $pepper = 'IDENTITRACK_SCANNER_PEPPER_V1_CHANGE_ME';
  $normalized = strtoupper(trim($rawValue));
  return hash('sha256', $pepper . ':' . $normalized);
}

function student_has_scanner_hash_column(): bool {
  static $hasColumn = null;
  if ($hasColumn !== null) return $hasColumn;

  $row = db_one(
    "SELECT 1 AS ok
     FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'student'
       AND column_name = 'scanner_id_hash'
     LIMIT 1"
  );

  $hasColumn = (bool)$row;
  return $hasColumn;
}

$student = db_one(
  "SELECT student_id, student_fn, student_ln
   FROM student
   WHERE student_id = :sid
   LIMIT 1",
  [':sid' => $scanInput]
);

if (!$student && student_has_scanner_hash_column()) {
  $student = db_one(
    "SELECT student_id, student_fn, student_ln
     FROM student
     WHERE scanner_id_hash = :scanner_hash
     LIMIT 1",
    [':scanner_hash' => scanner_hash_value($scanInput)]
  );
}

if (!$student) {
  echo json_encode([
    'ok' => false,
    'message' => 'No student record found.'
  ]);
  exit;
}

$studentId = (string)$student['student_id'];
$studentName = trim((string)$student['student_fn'] . ' ' . (string)$student['student_ln']);
if ($studentName === '') {
  $studentName = $studentId;
}

$pendingRow = db_one(
  "SELECT report_id
   FROM guard_violation_report
   WHERE student_id = :sid
     AND status = 'PENDING'
     AND is_deleted = 0
   ORDER BY created_at DESC, report_id DESC
   LIMIT 1",
  [':sid' => $studentId]
) ?: [];

$pendingReportId = (int)($pendingRow['report_id'] ?? 0);
$pendingCount = $pendingReportId > 0 ? 1 : 0;
$scanMsg = $pendingCount > 0 ? 'pending_guard_found' : 'no_offense_record';

$redirectUrl = 'offenses_student_view.php?student_id=' . urlencode($studentId) . '&scan_msg=' . urlencode($scanMsg);
if ($pendingReportId > 0) {
  $redirectUrl .= '&pending_report_id=' . urlencode((string)$pendingReportId);
}

echo json_encode([
  'ok' => true,
  'student_id' => $studentId,
  'student_name' => $studentName,
  'pending_guard_count' => $pendingCount,
  'pending_report_id' => $pendingReportId,
  'scan_msg' => $scanMsg,
  'redirect_url' => $redirectUrl,
]);
