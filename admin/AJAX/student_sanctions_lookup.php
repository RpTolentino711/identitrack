<?php
declare(strict_types=1);

require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$scanInput = trim((string)($_GET['scan'] ?? ''));
if ($scanInput === '') {
  echo json_encode([
    'ok' => false,
    'message' => 'Missing student ID or scan value.'
  ]);
  exit;
}

if (!function_exists('scanner_hash_value')) {
  function scanner_hash_value(string $rawValue): string {
    $pepper = 'IDENTITRACK_SCANNER_PEPPER_V1_CHANGE_ME';
    $normalized = strtoupper(trim($rawValue));
    return hash('sha256', $pepper . ':' . $normalized);
  }
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

$params = [':sid' => $scanInput];
db_add_encryption_key($params);

$student = db_one(
  "SELECT student_id, program, section, year_level, is_active, " . db_decrypt_cols(['student_fn', 'student_ln']) . "
   FROM student
   WHERE student_id = :sid
   LIMIT 1",
  $params
);

if (!$student && student_has_scanner_hash_column()) {
  $hashParams = [':scanner_hash' => scanner_hash_value($scanInput)];
  db_add_encryption_key($hashParams);
  $student = db_one(
    "SELECT student_id, program, section, year_level, is_active, " . db_decrypt_cols(['student_fn', 'student_ln']) . "
     FROM student
     WHERE scanner_id_hash = :scanner_hash
     LIMIT 1",
    $hashParams
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

// Fetch all sanctions for this student
$sanctParams = [':sid' => $studentId];
db_add_encryption_key($sanctParams);

$sanctionsQuery = "
  SELECT uc.case_id, uc.student_id, uc.decided_category, uc.probation_until, uc.punishment_details, uc.status AS case_status,
         csr.requirement_id, csr.status AS req_status, csr.hours_required, csr.task_name, csr.completed_at AS req_completed_at,
         (
           SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, sess.time_in, sess.time_out)/60.0), 0.0)
           FROM community_service_session sess
           WHERE sess.requirement_id = csr.requirement_id AND sess.time_out IS NOT NULL
         ) AS hours_completed
  FROM upcc_case uc
  LEFT JOIN community_service_requirement csr ON csr.related_case_id = uc.case_id
  WHERE uc.student_id = :sid 
    AND uc.decided_category IS NOT NULL 
    AND uc.decided_category BETWEEN 1 AND 5
  ORDER BY uc.created_at DESC
";

$rawSanctions = db_all($sanctionsQuery, $sanctParams);

$sanctionsList = [];
foreach ($rawSanctions as $s) {
  $p_details = json_decode($s['punishment_details'] ?? '', true) ?: [];
  $completed = !empty($p_details['completed']);
  
  $is_ongoing = false;
  $cat = (int)$s['decided_category'];
  if ($cat === 1) {
    $is_ongoing = !$completed && (empty($s['probation_until']) || (strtotime($s['probation_until']) > time()));
  } elseif ($cat === 2) {
    $completed = $completed || ($s['req_status'] === 'COMPLETED');
    $is_ongoing = !$completed;
  } else {
    $is_ongoing = !$completed;
  }

  $sanctionsList[] = [
    'case_id' => $s['case_id'],
    'category' => $cat,
    'probation_until' => !empty($s['probation_until']) ? date('Y-m-d', strtotime($s['probation_until'])) : '',
    'hours_required' => (float)($s['hours_required'] ?? 0.0),
    'hours_completed' => (float)($s['hours_completed'] ?? 0.0),
    'completed' => $completed,
    'is_ongoing' => $is_ongoing
  ];
}

echo json_encode([
  'ok' => true,
  'student' => [
    'student_id' => $studentId,
    'student_name' => $studentName,
    'program' => $student['program'],
    'section' => $student['section'],
    'year_level' => $student['year_level'],
    'is_active' => (bool)$student['is_active']
  ],
  'sanctions' => $sanctionsList
]);
