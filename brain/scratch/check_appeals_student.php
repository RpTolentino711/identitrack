<?php
require_once __DIR__ . '/../../database/database.php';
$sid = '2023-183482';
echo "=== APPEALS ===\n";
print_r(db_all('SELECT appeal_id, student_id, offense_id, case_id, appeal_kind, status, created_at, decided_at FROM student_appeal_request WHERE student_id = :sid ORDER BY appeal_id DESC', [':sid'=>$sid]));

echo "=== CASES ===\n";
print_r(db_all('SELECT case_id, case_kind, decided_category, status FROM upcc_case WHERE student_id = :sid', [':sid'=>$sid]));

echo "=== UPCC CASE OFFENSES ===\n";
print_r(db_all('SELECT co.case_id, co.offense_id, o.level, o.status FROM upcc_case_offense co JOIN offense o ON o.offense_id = co.offense_id WHERE o.student_id = :sid', [':sid'=>$sid]));

echo "=== OFFENSES ===\n";
print_r(db_all('SELECT offense_id, level, status, offense_type_id FROM offense WHERE student_id = :sid', [':sid'=>$sid]));
