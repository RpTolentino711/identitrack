<?php
require 'database/database.php';
$studentId = '2023-1280';
$raw = file_get_contents('http://localhost/identitrack/api/student/dashboard_summary.php', false, stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => 'Content-Type: application/json',
        'content' => json_encode(['student_id' => $studentId])
    ]
]));
print_r(json_decode($raw, true));
?>
