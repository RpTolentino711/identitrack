<?php
require 'database/database.php';
$students = db_all("SELECT student_id, student_email, HEX(student_email) as hex_val FROM student WHERE student_email LIKE '%jmesa%'");
print_r($students);
?>
