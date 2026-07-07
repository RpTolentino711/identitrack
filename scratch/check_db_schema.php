<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=identitrack;charset=utf8mb4", "root", "", [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "=== TABLE DESCRIPTION community_service_requirement ===\n";
print_r($pdo->query("DESCRIBE community_service_requirement")->fetchAll());

echo "=== QUERY FOR ROMEO TOLENTINO IN CSR ===\n";
$rows = $pdo->query("SELECT * FROM community_service_requirement WHERE student_id LIKE '%2023-183482%'")->fetchAll();
print_r($rows);

echo "=== ALL SESSIONS IN DB ===\n";
$sessions = $pdo->query("SELECT * FROM community_service_session")->fetchAll();
print_r($sessions);
