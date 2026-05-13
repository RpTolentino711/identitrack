<?php
require_once __DIR__ . '/database/database.php';

header('Content-Type: text/plain');
echo "--- IdentiTrack FORCED Encryption Migration ---\n\n";

$key = db_encryption_key();
$params = [':__enckey' => $key];

// 1. Repair and Force Encrypt Students
$students = db_all("SELECT student_id, student_fn, student_ln FROM student");
foreach ($students as $s) {
    echo "Processing Student: " . $s['student_id'] . "... ";
    
    // If it's already encrypted, we skip it (unless it's garbage)
    $check = db_one("SELECT " . db_decrypt_col('student_fn') . " as name FROM student WHERE student_id = :sid", [':sid' => $s['student_id'], ':__enckey' => $key]);
    
    if ($check['name'] === null || strpos($s['student_fn'], 'REPAIR_NEEDED') !== false || strpos($s['student_ln'], 'REPAIR_NEEDED') !== false) {
        // FORCE ENCRYPT
        $fn = str_replace('REPAIR_NEEDED', '', $s['student_fn']);
        $ln = str_replace('REPAIR_NEEDED', '', $s['student_ln']);
        
        db_exec("UPDATE student SET 
            student_fn = " . db_encrypt_col('fn', ':fn') . ",
            student_ln = " . db_encrypt_col('ln', ':ln') . "
            WHERE student_id = :sid", 
            [':sid' => $s['student_id'], ':fn' => $fn, ':ln' => $ln, ':__enckey' => $key]
        );
        echo "[ENCRYPTED]\n";
    } else {
        echo "[ALREADY SECURE]\n";
    }
}

echo "\n--- Migration Complete ---";
