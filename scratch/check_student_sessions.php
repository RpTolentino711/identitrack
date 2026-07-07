<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=identitrack;charset=utf8mb4", "root", "", [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "=== REQUIREMENTS ===\n";
$reqs = $pdo->query("SELECT * FROM community_service_requirement WHERE student_id = '2023-183482'")->fetchAll();
print_r($reqs);

echo "\n=== SESSIONS ===\n";
$sessions = $pdo->query("SELECT * FROM community_service_session WHERE requirement_id IN (SELECT requirement_id FROM community_service_requirement WHERE student_id = '2023-183482')")->fetchAll();
print_r($sessions);
