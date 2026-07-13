<?php
require_once __DIR__ . '/database/database.php';
$reqs = db_all("SELECT * FROM community_service_requirement WHERE student_id = '2023-183482'");
header('Content-Type: application/json');
echo json_encode($reqs, JSON_PRETTY_PRINT);
