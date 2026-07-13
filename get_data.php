<?php
require_once __DIR__ . '/database/database.php';
$reqs = db_all("SELECT * FROM community_service_requirement WHERE student_id = '2023-183482'");
$sessions = db_all("SELECT * FROM community_service_session WHERE requirement_id = 43");
header('Content-Type: application/json');
echo json_encode(['reqs' => $reqs, 'sessions' => $sessions], JSON_PRETTY_PRINT);
