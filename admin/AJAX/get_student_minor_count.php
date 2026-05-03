<?php
// File: C:\xampp\htdocs\identitrack\admin\AJAX\get_student_minor_count.php
// Purpose: Live fetch of a student's current MINOR offense count + guardian email.
// GET: student_id=2024-01001
// Returns: { ok: true, data: { student_id, exists, minor_count, guardian_email } }

require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$studentId = trim((string)($_GET['student_id'] ?? ''));

if ($studentId === '') {
  echo json_encode(['ok' => true, 'data' => [
    'student_id' => '',
    'exists' => false,
    'minor_count' => 0,
    'guardian_email' => '',
  ]]);
  exit;
}

$studentRow = db_one(
  "SELECT s.student_id, g.guardian_email
   FROM student s
   LEFT JOIN guardian g ON g.student_id = s.student_id
   WHERE s.student_id = :sid
   LIMIT 1",
  [':sid' => $studentId]
);

if (!$studentRow) {
  echo json_encode(['ok' => true, 'data' => [
    'student_id' => $studentId,
    'exists' => false,
    'minor_count' => 0,
    'guardian_email' => '',
  ]]);
  exit;
}

$cntRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   WHERE o.student_id = :sid AND ot.level='MINOR'",
  [':sid' => $studentId]
);

echo json_encode(['ok' => true, 'data' => [
  'student_id' => (string)$studentRow['student_id'],
  'exists' => true,
  'minor_count' => (int)($cntRow['cnt'] ?? 0),
  'guardian_email' => trim((string)($studentRow['guardian_email'] ?? '')),
]]);
exit;