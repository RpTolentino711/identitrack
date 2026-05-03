<?php
require 'database/database.php';
$caseId = 25;
$c = db_one("SELECT * FROM upcc_case WHERE case_id = :id", [':id' => $caseId]);
echo "Case Details:\n";
print_r($c);

$a = db_all("SELECT * FROM student_appeal_request WHERE case_id = :id", [':id' => $caseId]);
echo "\nAppeals:\n";
print_r($a);
