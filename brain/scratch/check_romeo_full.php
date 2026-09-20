<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$_SESSION['admin_id'] = 1;
$_SESSION['admin_username'] = 'admin';
$_SESSION['role'] = 'ADMIN';

require_once __DIR__ . '/../../database/database.php';

$params = [];
db_add_encryption_key($params);

$offenses = db_all("
    SELECT o.offense_id, o.student_id,
           CONCAT(" . db_decrypt_col('student_ln', 's') . ", ', ', " . db_decrypt_col('student_fn', 's') . ") AS student_name,
           ot.level, ot.code, ot.name AS offense_name, o.date_committed, o.status
    FROM offense o
    JOIN student s ON s.student_id = o.student_id
    JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
    ORDER BY s.student_id, o.date_committed ASC
", $params);

$cases = db_all("
    SELECT uc.case_id, uc.student_id,
           CONCAT(" . db_decrypt_col('student_ln', 's') . ", ', ', " . db_decrypt_col('student_fn', 's') . ") AS student_name,
           uc.case_summary, uc.case_kind, uc.status, uc.decided_category, uc.created_at
    FROM upcc_case uc
    JOIN student s ON s.student_id = uc.student_id
    ORDER BY uc.student_id, uc.created_at ASC
", $params);

echo "--- OFFENSES (" . count($offenses) . ") ---\n";
foreach ($offenses as $o) {
    echo "ID: {$o['offense_id']} | Student: {$o['student_id']} - {$o['student_name']} | Level: {$o['level']} | Code: {$o['code']} | Name: {$o['offense_name']} | Date: {$o['date_committed']} | Status: {$o['status']}\n";
}

echo "\n--- CASES (" . count($cases) . ") ---\n";
foreach ($cases as $c) {
    echo "Case ID: {$c['case_id']} | Student: {$c['student_id']} - {$c['student_name']} | Summary: {$c['case_summary']} | Kind: {$c['case_kind']} | Status: {$c['status']} | Cat: {$c['decided_category']} | Created: {$c['created_at']}\n";
}
