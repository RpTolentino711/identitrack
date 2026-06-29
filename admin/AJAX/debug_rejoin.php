<?php
require_once __DIR__ . '/../../database/database.php';
header('Content-Type: application/json');
$caseId = $_GET['case_id'] ?? 13;
$rows = db_all("SELECT * FROM upcc_panel_rejoin_requests WHERE case_id = :c ORDER BY request_id DESC", [':c' => $caseId]);
$presence = db_all("SELECT * FROM upcc_hearing_presence WHERE case_id = :c", [':c' => $caseId]);
echo json_encode([
    'requests' => $rows,
    'presence' => $presence,
    'time' => date('Y-m-d H:i:s')
]);
