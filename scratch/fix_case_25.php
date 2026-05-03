<?php
require 'database/database.php';
$r = db_exec("UPDATE upcc_case SET status = 'RESOLVED' WHERE case_id = 25 AND student_id = '2023-1280'");
echo "Result: $r\n";
$c = db_one("SELECT status FROM upcc_case WHERE case_id = 25");
print_r($c);
