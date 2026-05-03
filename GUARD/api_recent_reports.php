<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['guard_logged_in']) || $_SESSION['guard_logged_in'] !== true) {
    http_response_code(401);
    echo json_encode([]);
    exit;
}

require_once __DIR__ . '/../database/database.php';
$pdo = getConnection();
$gid = $_SESSION['guard_id'];

$stmt = $pdo->prepare("SELECT r.report_id, r.date_committed, r.status, r.created_at,
           ot.name AS offense_name, ot.level AS offense_level
    FROM guard_violation_report r
    JOIN offense_type ot ON ot.offense_type_id = r.offense_type_id
    WHERE r.submitted_by = :gid
    ORDER BY r.created_at DESC
    LIMIT 5
");
$stmt->execute([':gid' => $gid]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));