<?php
require 'database/database.php';
$students = db_all("SELECT student_email, LENGTH(student_email) as len FROM student WHERE student_email LIKE '%jmesa%'");
print_r($students);
?>
