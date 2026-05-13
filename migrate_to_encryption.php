<?php
require_once __DIR__ . '/database/database.php';

header('Content-Type: text/plain');
echo "--- IdentiTrack FINAL CLEANUP Migration ---\n\n";

$key = db_encryption_key();

// 1. Get all students and decrypt their names to check for the repair tag
$students = db_all("SELECT student_id, " . db_decrypt_cols(['student_fn', 'student_ln']) . " FROM student", [':__enckey' => $key]);

foreach ($students as $s) {
    echo "Checking Student: " . $s['student_id'] . "... ";
    
    $fn = (string)($s['student_fn'] ?? '');
    $ln = (string)($s['student_ln'] ?? '');
    
    if (strpos($fn, 'REPAIR_NEEDED') !== false || strpos($ln, 'REPAIR_NEEDED') !== false) {
        // Clean the text
        $clean_fn = trim(str_replace('REPAIR_NEEDED', '', $fn));
        $clean_ln = trim(str_replace('REPAIR_NEEDED', '', $ln));
        
        // If last name became empty, set it to a dot or leave empty
        if ($clean_ln === '') $clean_ln = ''; 

        db_exec("UPDATE student SET 
            student_fn = " . db_encrypt_col('fn', ':fn') . ",
            student_ln = " . db_encrypt_col('ln', ':ln') . "
            WHERE student_id = :sid", 
            [':sid' => $s['student_id'], ':fn' => $clean_fn, ':ln' => $clean_ln, ':__enckey' => $key]
        );
        echo "[CLEANED & ENCRYPTED]\n";
    } else {
        echo "[ALREADY CLEAN]\n";
    }
}

echo "\n--- Cleanup Complete ---";
