<?php
require_once __DIR__ . '/database/database.php';

header('Content-Type: text/plain');
echo "--- IdentiTrack MULTI-TEST Generator ---\n\n";

$key = db_encryption_key();

$test_students = [
    [
        'id' => 'T-001',
        'fn' => 'Juan',
        'ln' => 'Dela Cruz',
        'em' => 'juan@example.com',
        'ph' => '0912-111-1111'
    ],
    [
        'id' => 'T-002',
        'fn' => 'Maria',
        'ln' => 'Santos',
        'em' => 'maria@example.com',
        'ph' => '0912-222-2222'
    ],
    [
        'id' => 'T-003',
        'fn' => 'Pedro',
        'ln' => 'Penduko',
        'em' => 'pedro@example.com',
        'ph' => '0912-333-3333'
    ]
];

try {
    foreach ($test_students as $s) {
        echo "Adding " . $s['fn'] . " (" . $s['id'] . ")... ";
        
        // Delete if exists
        db_exec("DELETE FROM student WHERE student_id = :sid", [':sid' => $s['id']]);

        // Insert
        db_exec("INSERT INTO student (student_id, student_fn, student_ln, student_email, phone_number, home_address, year_level, program, school, section) 
                 VALUES (:sid, 
                 " . db_encrypt_col('fn', ':fn') . ", 
                 " . db_encrypt_col('ln', ':ln') . ", 
                 " . db_encrypt_col('em', ':em') . ", 
                 " . db_encrypt_col('ph', ':ph') . ", 
                 " . db_encrypt_col('ad', ':ad') . ", 
                 2, 'BSIT', 'College', 'INF232')", 
                 [
                    ':sid' => $s['id'],
                    ':fn'  => $s['fn'],
                    ':ln'  => $s['ln'],
                    ':em'  => $s['em'],
                    ':ph'  => $s['ph'],
                    ':ad'  => 'Encryption Test City',
                    ':__enckey' => $key
                 ]
        );
        echo "[DONE]\n";
    }

    echo "\nSUCCESS! 3 Test students added.\n";
    echo "Check your dashboard now!\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
