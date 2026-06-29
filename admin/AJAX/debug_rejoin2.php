<?php
require_once __DIR__ . '/../../database/database.php';
header('Content-Type: application/json');
$cases = db_all("SELECT case_id FROM upcc_case ORDER BY case_id DESC LIMIT 5");
$case_ids = array_column($cases, 'case_id');

$results = [];
foreach ($case_ids as $cid) {
    $rows = db_all("SELECT * FROM upcc_panel_rejoin_requests WHERE case_id = :c ORDER BY request_id DESC LIMIT 1", [':c' => $cid]);
    $presence = db_all("SELECT * FROM upcc_hearing_presence WHERE case_id = :c", [':c' => $cid]);
    $results[$cid] = [
        'latest_request' => $rows,
        'presence' => $presence
    ];
}
echo json_encode($results);
