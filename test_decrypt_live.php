<?php
require_once __DIR__ . '/database/database.php';

$studentId = '2023-184363'; // From the screenshot
$sql = "SELECT " . db_decrypt_cols(['student_fn', 'student_ln'], 's') . " 
        FROM student s 
        WHERE student_id = :sid";
$params = [':sid' => $studentId];
db_add_encryption_key($params);

$res = db_one($sql, $params);
echo "Student ID: $studentId\n";
if ($res) {
    echo "First Name: [" . ($res['student_fn'] ?? 'NULL') . "]\n";
    echo "Last Name: [" . ($res['student_ln'] ?? 'NULL') . "]\n";
} else {
    echo "Student not found.\n";
}

echo "\nEncryption Key Hash: " . hash('sha256', db_encryption_key()) . "\n";
