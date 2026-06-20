<?php
require 'database/database.php';

$res = db_all("
    SELECT student_id, level, COUNT(*) as cnt 
    FROM offense 
    GROUP BY student_id, level
");

echo "Student Offense Counts:\n";
foreach ($res as $row) {
    echo "Student: {$row['student_id']}, Level: {$row['level']}, Count: {$row['cnt']}\n";
}
