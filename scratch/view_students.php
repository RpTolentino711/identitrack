<?php
require_once __DIR__ . '/../database/database.php';

header('Content-Type: text/plain; charset=utf-8');

$key = db_encryption_key();

$students = db_all(
  "SELECT student_id, 
    " . db_decrypt_cols(['student_fn', 'student_ln', 'student_email']) . ",
    is_active
   FROM student
   ORDER BY student_id
   LIMIT 50",
  [':__enckey' => $key]
);

echo "=== DECRYPTED STUDENT LIST ===\n\n";
echo str_pad("ID", 15) . str_pad("NAME", 35) . str_pad("EMAIL", 40) . "ACTIVE\n";
echo str_repeat("-", 95) . "\n";

foreach ($students as $s) {
    $name = trim(($s['student_fn'] ?? '') . ' ' . ($s['student_ln'] ?? ''));
    echo str_pad($s['student_id'] ?? '', 15)
       . str_pad($name, 35)
       . str_pad($s['student_email'] ?? '(empty)', 40)
       . ($s['is_active'] == 1 ? 'YES' : 'NO')
       . "\n";
}

echo "\nTotal: " . count($students) . " students\n";
