<?php
require_once __DIR__ . '/database/database.php';
header('Content-Type: text/plain');

try {
    $params = [];
    db_add_encryption_key($params);
    $students = db_all("SELECT student_id, " . db_decrypt_cols(['student_fn', 'student_ln']) . " FROM student", $params);
    
    echo "=== STUDENTS FOUND ===\n";
    foreach ($students as $s) {
        $fullName = trim(($s['student_fn'] ?? '') . ' ' . ($s['student_ln'] ?? ''));
        if (stripos($fullName, 'Romeo') !== false || stripos($fullName, 'Tolentino') !== false) {
            echo "ID: {$s['student_id']}, Name: {$fullName}\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
