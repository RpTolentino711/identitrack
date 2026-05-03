<?php
require 'database/database.php';
$email = 'jmesadrew29@gmail.com';
$student = db_one("SELECT * FROM student WHERE student_email = :email", [':email' => $email]);
print_r($student);

$upccStatus = db_one("SELECT * FROM upcc_case WHERE student_id = :sid ORDER BY case_id DESC LIMIT 1", [':sid' => $student['student_id'] ?? '']);
echo "UPCC Status:\n";
print_r($upccStatus);
?>
