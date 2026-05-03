<?php
require 'database/database.php';
$email = 'jmesadrew29@gmail.com';
$student = db_one("SELECT * FROM student WHERE student_email = :em", [':em' => $email]);
if ($student) {
    echo "Student found! ID: " . $student['student_id'] . "\n";
} else {
    echo "Student NOT found.\n";
}
?>
