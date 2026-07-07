<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=identitrack;charset=utf8mb4", "root", "", [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "=== CSR COUNT ===\n";
print_r($pdo->query("SELECT COUNT(*) as cnt FROM community_service_requirement")->fetchAll());

echo "=== CSR ALL ROWS ===\n";
print_r($pdo->query("SELECT requirement_id, student_id, task_name, hours_required, status FROM community_service_requirement")->fetchAll());

echo "=== CSS COUNT ===\n";
print_r($pdo->query("SELECT COUNT(*) as cnt FROM community_service_session")->fetchAll());

echo "=== CSS ALL ROWS ===\n";
print_r($pdo->query("SELECT session_id, requirement_id, time_in, time_out FROM community_service_session")->fetchAll());
