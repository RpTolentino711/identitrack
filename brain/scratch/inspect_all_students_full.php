<?php
require_once 'c:/xampp/htdocs/identitrack/database/database.php';

$sParams = [];
db_add_encryption_key($sParams);
$students = db_all("
    SELECT s.student_id, " . db_decrypt_cols(['student_fn', 'student_ln'], 's') . "
    FROM student s
    ORDER BY s.student_id
", $sParams);

foreach ($students as $st) {
    $sid = $st['student_id'];
    $name = trim(($st['student_fn'] ?? '') . ' ' . ($st['student_ln'] ?? ''));
    
    $offenses = db_all("
        SELECT o.offense_id, o.offense_type_id, ot.code, ot.name, o.level, o.status, o.created_at,
               (SELECT COUNT(*) FROM upcc_case_offense uco WHERE uco.offense_id = o.offense_id) AS is_linked_to_case
        FROM offense o
        JOIN offense_type ot ON o.offense_type_id = ot.offense_type_id
        WHERE o.student_id = ?
        ORDER BY o.offense_id ASC
    ", [$sid]);
    
    if (empty($offenses)) continue;
    
    echo "========================================================\n";
    echo "STUDENT: $sid ($name)\n";
    echo "Total Offenses: " . count($offenses) . "\n";
    foreach ($offenses as $off) {
        echo "  [ID: {$off['offense_id']}] Code: {$off['code']} | Name: {$off['name']} | Status: {$off['status']} | LinkedToCase: {$off['is_linked_to_case']} | Date: {$off['created_at']}\n";
    }
}
