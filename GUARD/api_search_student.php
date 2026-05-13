<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['guard_logged_in']) || $_SESSION['guard_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$q = trim($_GET['q'] ?? '');
if ($q === '') {
    echo json_encode([]);
    exit;
}

require_once __DIR__ . '/../database/database.php';

$decFn = db_decrypt_col('student_fn', 's');
$decLn = db_decrypt_col('student_ln', 's');

$params = [
  ':q' => $q . '%',
  ':qLike' => '%' . $q . '%',
];
db_add_encryption_key($params);

$pdo = getConnection();
$stmt = $pdo->prepare("SELECT
      s.student_id,
      $decFn AS student_fn,
      $decLn AS student_ln,
      s.year_level,
      s.section,
      s.program,
      s.school
    FROM student s
    WHERE s.is_active = 1
      AND (
        s.student_id LIKE :q
        OR $decFn LIKE :qLike
        OR $decLn LIKE :qLike
        OR CONCAT($decFn, ' ', $decLn) LIKE :qLike
        OR CONCAT($decLn, ', ', $decFn) LIKE :qLike
      )
    ORDER BY student_ln ASC, student_fn ASC
    LIMIT 12");

$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows);