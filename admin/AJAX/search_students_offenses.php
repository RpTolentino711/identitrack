<?php
// File: C:\xampp\htdocs\identitrack\admin\AJAX\search_students_offenses.php
// FINAL UPDATE: Search ANY student by ID/name (even if they have ZERO offenses).
// Returns JSON array with offense summary fields (total/minor/major/last_offense_date).

require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 8);
if ($limit <= 0) $limit = 8;
if ($limit > 15) $limit = 15;

if ($q === '' || strlen($q) < 1) {
  echo json_encode(['ok' => true, 'data' => []]);
  exit;
}

$params = [
  ':q' => $q . '%',
  ':qLike' => '%' . $q . '%',
];

try {
  // NOTE: This query does NOT require offenses to exist.
  // It uses LEFT JOIN to compute counts, so students with no offenses still show up.
  $rows = db_all(
    "SELECT
        s.student_id,
        s.student_fn,
        s.student_ln,
        s.year_level,
        s.program,
        s.student_email,
        s.home_address,
        s.phone_number,

        COALESCE(COUNT(o.offense_id), 0) AS total_offenses,
        COALESCE(SUM(CASE WHEN ot.level = 'MINOR' THEN 1 ELSE 0 END), 0) AS minor_offenses,
        COALESCE(SUM(CASE WHEN ot.level = 'MAJOR' THEN 1 ELSE 0 END), 0) AS major_offenses,
        MAX(o.date_committed) AS last_offense_date

     FROM student s
     LEFT JOIN offense o
       ON o.student_id = s.student_id
     LEFT JOIN offense_type ot
       ON ot.offense_type_id = o.offense_type_id

     WHERE
       s.is_active = 1
       AND (
         s.student_id LIKE :q
         OR s.student_fn LIKE :qLike
         OR s.student_ln LIKE :qLike
         OR CONCAT(s.student_fn, ' ', s.student_ln) LIKE :qLike
         OR CONCAT(s.student_ln, ', ', s.student_fn) LIKE :qLike
       )

     GROUP BY
       s.student_id, s.student_fn, s.student_ln, s.year_level, s.program,
       s.student_email, s.home_address, s.phone_number

     ORDER BY
       (MAX(o.date_committed) IS NULL) ASC,  -- students WITH offenses first
       MAX(o.date_committed) DESC,
       s.student_ln ASC,
       s.student_fn ASC

     LIMIT $limit",
    $params
  );

  $data = [];
  foreach ($rows as $r) {
    $studentId = (string)$r['student_id'];
    $total = (int)($r['total_offenses'] ?? 0);

    $data[] = [
      'student_id' => $studentId,
      'student_name' => trim((string)$r['student_fn'] . ' ' . (string)$r['student_ln']),
      'year_level' => (int)($r['year_level'] ?? 0),
      'program' => (string)($r['program'] ?? ''),
      'student_email' => (string)($r['student_email'] ?? ''),
      'home_address' => (string)($r['home_address'] ?? ''),
      'phone_number' => (string)($r['phone_number'] ?? ''),
      'total' => $total,
      'minor' => (int)($r['minor_offenses'] ?? 0),
      'major' => (int)($r['major_offenses'] ?? 0),
      'last_offense_date' => (string)($r['last_offense_date'] ?? ''),
      'has_offense' => ($total > 0),
      'url' => 'offenses_student_view.php?student_id=' . rawurlencode($studentId),
    ];
  }

  echo json_encode(['ok' => true, 'data' => $data]);
  exit;

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'message' => 'Search query failed. Check schema/columns and server logs.',
  ]);
  exit;
}