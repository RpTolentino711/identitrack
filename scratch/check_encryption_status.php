<?php
require_once __DIR__ . '/../database/database.php';

$student = db_one("SELECT student_fn, student_ln, student_email FROM student LIMIT 1");
echo "Raw Data:\n";
print_r($student);

$decrypted_cols = db_decrypt_cols(['student_fn', 'student_ln', 'student_email']);
$params = [];
db_add_encryption_key($params);
$decrypted_student = db_one("SELECT $decrypted_cols FROM student LIMIT 1", $params);
echo "\nDecrypted Data:\n";
print_r($decrypted_student);
