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

$stmt = $pdo->prepare("SELECT
        SUM(status = 'PENDING')  AS pending,
        SUM(status = 'APPROVED') AS approved,
        SUM(status = 'REJECTED') AS rejected
    FROM guard_violation_report
    WHERE submitted_by = :gid
");
$stmt->execute([':gid' => $gid]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'pending'  => (int)($row['pending']  ?? 0),
    'approved' => (int)($row['approved'] ?? 0),
    'rejected' => (int)($row['rejected'] ?? 0),
]);