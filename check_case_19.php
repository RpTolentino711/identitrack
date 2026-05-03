<?php
require 'database/database.php';
$case = db_one("SELECT student_id, status FROM upcc_case WHERE case_id = 19");
print_r($case);
?>
