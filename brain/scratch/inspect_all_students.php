<?php
require_once 'c:/xampp/htdocs/identitrack/database/database.php';

$students = db_all("
    SELECT DISTINCT o.student_id 
    FROM offense o 
    ORDER BY o.student_id
");

foreach ($students as $st) {
    $sid = $st['student_id'];
    echo "========================================================\n";
    echo "STUDENT: $sid\n";
    
    $offenses = db_all("
        SELECT o.offense_id, o.offense_type_id, ot.code, ot.name, o.level, o.status, o.created_at,
               (SELECT COUNT(*) FROM upcc_case_offense uco WHERE uco.offense_id = o.offense_id) AS is_linked_to_case
        FROM offense o
        JOIN offense_type ot ON o.offense_type_id = ot.offense_type_id
        WHERE o.student_id = ?
        ORDER BY o.offense_id ASC
    ", [$sid]);
    
    echo "ALL OFFENSES IN DB (" . count($offenses) . "):\n";
    foreach ($offenses as $off) {
        echo "  [ID: {$off['offense_id']}] Code: {$off['code']} | Name: {$off['name']} | Status: {$off['status']} | LinkedToCase: {$off['is_linked_to_case']} | Date: {$off['created_at']}\n";
    }
    
    $cases = db_all("
        SELECT case_id, case_kind, case_summary, status, created_at
        FROM upcc_case
        WHERE student_id = ?
        ORDER BY case_id ASC
    ", [$sid]);
    
    echo "UPCC CASES (" . count($cases) . "):\n";
    foreach ($cases as $c) {
        echo "  [Case ID: {$c['case_id']}] Kind: {$c['case_kind']} | Status: {$c['status']} | Summary: {$c['case_summary']}\n";
    }
}
