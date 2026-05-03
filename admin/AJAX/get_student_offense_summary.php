<?php
// File: admin/AJAX/get_student_offense_summary.php
// Returns minor count, major count, guardian email, and active UPCC cases for a student.

require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json');

$studentId = trim((string)($_GET['student_id'] ?? ''));

if ($studentId === '') {
  echo json_encode(['ok' => false, 'message' => 'No student_id provided.']);
  exit;
}

// Check student exists
$student = db_one(
  "SELECT student_id FROM student WHERE student_id = :sid LIMIT 1",
  [':sid' => $studentId]
);

if (!$student) {
  echo json_encode(['ok' => false, 'message' => 'Student not found.']);
  exit;
}

// Minor count
$minorRow = db_one(
  "SELECT COUNT(*) AS cnt FROM offense WHERE student_id = :sid AND level = 'MINOR'",
  [':sid' => $studentId]
);
$minorCount = (int)($minorRow['cnt'] ?? 0);

// Major count
$majorRow = db_one(
  "SELECT COUNT(*) AS cnt FROM offense WHERE student_id = :sid AND level = 'MAJOR'",
  [':sid' => $studentId]
);
$majorCount = (int)($majorRow['cnt'] ?? 0);

// Guardian email
$guardianRow = db_one(
  "SELECT guardian_email FROM guardian WHERE student_id = :sid LIMIT 1",
  [':sid' => $studentId]
);
$guardianEmail = (string)($guardianRow['guardian_email'] ?? '');

// Active UPCC cases
$upccCases = db_all(
  "SELECT case_id, status, case_summary, created_at
   FROM upcc_case
   WHERE student_id = :sid AND status IN ('PENDING', 'UNDER_APPEAL')
   ORDER BY created_at DESC",
  [':sid' => $studentId]
) ?: [];

echo json_encode([
  'ok'   => true,
  'data' => [
    'minor_count'    => $minorCount,
    'major_count'    => $majorCount,
    'guardian_email' => $guardianEmail,
    'upcc_cases'     => $upccCases,
  ],
]);