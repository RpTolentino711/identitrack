<?php
require_once __DIR__ . '/database/database.php';

header('Content-Type: text/plain');
echo "--- IdentiTrack MULTI-TEST Generator (With Passwords) ---\n\n";

$key = db_encryption_key();
$hashedPassword = password_hash('student123', PASSWORD_DEFAULT);

$test_students = [
    ['id' => 'T-001', 'fn' => 'Juan', 'ln' => 'Dela Cruz', 'em' => 'juan@example.com'],
    ['id' => 'T-002', 'fn' => 'Maria', 'ln' => 'Santos', 'em' => 'maria@example.com'],
    ['id' => 'T-003', 'fn' => 'Pedro', 'ln' => 'Penduko', 'em' => 'pedro@example.com']
];

try {
    foreach ($test_students as $s) {
        echo "Updating " . $s['fn'] . " (" . $s['id'] . ") with password 'student123'... ";
        
        // Delete if exists
        db_exec("DELETE FROM student WHERE student_id = :sid", [':sid' => $s['id']]);

        // Insert with hashed password
        db_exec("INSERT INTO student (student_id, password, student_fn, student_ln, student_email, phone_number, home_address, year_level, program, school, section) 
                 VALUES (:sid, :pw, 
                 " . db_encrypt_col('fn', ':fn') . ", 
                 " . db_encrypt_col('ln', ':ln') . ", 
                 " . db_encrypt_col('em', ':em') . ", 
                 " . db_encrypt_col('ph', ':ph') . ", 
                 " . db_encrypt_col('ad', ':ad') . ", 
                 2, 'BSIT', 'College', 'INF232')", 
                 [
                    ':sid' => $s['id'],
                    ':pw'  => $hashedPassword,
                    ':fn'  => $s['fn'],
                    ':ln'  => $s['ln'],
                    ':em'  => $s['em'],
                    ':ph'  => '0912-000-0000',
                    ':ad'  => 'Encryption Test City',
                    ':__enckey' => $key
                 ]
        );
        echo "[DONE]\n";
    }

    echo "\nSUCCESS! You can now log in as T-001 with password 'student123'.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
