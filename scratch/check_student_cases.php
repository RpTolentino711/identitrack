<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=identitrack;charset=utf8mb4", "root", "", [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

echo "=== CASES FOR STUDENT ===\n";
$cases = $pdo->query("SELECT * FROM upcc_case WHERE student_id = '2023-183482'")->fetchAll();
print_r($cases);

echo "\n=== ALL CASES IN DB ===\n";
$all_cases = $pdo->query("SELECT case_id, student_id, decided_category, status FROM upcc_case")->fetchAll();
print_r($all_cases);
