<?php
require 'database/database.php';
$students = db_all("SELECT * FROM student WHERE student_email LIKE '%jmesa%' OR student_email LIKE '%andrew%'");
print_r($students);
?>
