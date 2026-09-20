<?php
require_once 'c:/xampp/htdocs/identitrack/database/database.php';
$offenses = db_all("SELECT o.offense_id, o.student_id, o.date_committed, o.created_at, o.status, ot.name AS offense_name, ot.level 
                    FROM offense o 
                    JOIN offense_type ot ON o.offense_type_id = ot.offense_type_id 
                    ORDER BY o.offense_id DESC LIMIT 15");
echo "--- LATEST OFFENSES ---\n";
print_r($offenses);

$cases = db_all("SELECT case_id, student_id, case_kind, status, created_at FROM upcc_case ORDER BY case_id DESC LIMIT 15");
echo "--- LATEST UPCC CASES ---\n";
print_r($cases);
