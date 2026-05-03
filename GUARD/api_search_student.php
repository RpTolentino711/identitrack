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
$pdo = getConnection();

$like = '%' . $q . '%';
$stmt = $pdo->prepare("SELECT
      s.student_id,
      s.student_fn,
      s.student_ln,
      s.year_level,
      s.section,
      s.program,
      s.school
    FROM student s
    WHERE s.is_active = 1
      AND (
        s.student_id LIKE :q1
        OR s.student_fn LIKE :q2
        OR s.student_ln LIKE :q3
        OR CONCAT(s.student_fn,' ',s.student_ln) LIKE :q4
        OR CONCAT(s.student_ln,', ',s.student_fn) LIKE :q5
      )
    ORDER BY s.student_ln ASC, s.student_fn ASC
    LIMIT 12");
$stmt->execute([':q1' => $like, ':q2' => $like, ':q3' => $like, ':q4' => $like, ':q5' => $like]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($rows);