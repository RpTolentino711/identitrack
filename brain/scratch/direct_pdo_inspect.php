<?php
$pdo = new PDO('mysql:host=127.0.0.1;dbname=identitrack;charset=utf8mb4', 'root', '');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== OFFENSE TYPES ===\n";
$stmt = $pdo->query("SELECT offense_type_id, offense_code, offense_name, offense_level FROM offense_type WHERE offense_level = 'MAJOR' LIMIT 20");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== UPCC CASES WITH APPEALS ===\n";
$stmt = $pdo->query("SELECT sar.appeal_id, sar.student_id, sar.offense_id, sar.case_id, sar.status AS appeal_status, uc.case_kind, uc.decided_category, uc.status AS case_status FROM student_appeal_request sar LEFT JOIN upcc_case uc ON uc.case_id = sar.case_id ORDER BY sar.appeal_id DESC LIMIT 5");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

echo "\n=== ALL UPCC CASES ===\n";
$stmt = $pdo->query("SELECT case_id, student_id, case_kind, decided_category, status, created_at FROM upcc_case ORDER BY case_id DESC LIMIT 10");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
