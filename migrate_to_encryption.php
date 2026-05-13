<?php
require_once __DIR__ . '/database/database.php';

header('Content-Type: text/plain');
echo "--- IdentiTrack COMPLETE PII CLEANUP --- \n\n";

$key = db_encryption_key();

// 1. Fetch all student records
$students = db_all("SELECT student_id, 
    " . db_decrypt_cols(['student_fn', 'student_ln', 'student_email', 'phone_number', 'home_address']) . " 
    FROM student", [':__enckey' => $key]);

foreach ($students as $s) {
    echo "Processing Student: " . $s['student_id'] . "... ";
    
    // We check if ANY field is still garbage or has the repair markers
    $fn = (string)($s['student_fn'] ?? '');
    $ln = (string)($s['student_ln'] ?? '');
    $em = (string)($s['student_email'] ?? '');
    $ph = (string)($s['phone_number'] ?? '');
    $ad = (string)($s['home_address'] ?? '');

    // Cleanup logic: Remove placeholder text if present
    $clean_fn = trim(str_replace('REPAIR_NEEDED', '', $fn));
    $clean_ln = trim(str_replace('REPAIR_NEEDED', '', $ln));
    $clean_em = trim(str_replace('REPAIR_NEEDED', '', $em));
    $clean_ph = trim(str_replace('REPAIR_NEEDED', '', $ph));
    $clean_ad = trim(str_replace('REPAIR_NEEDED', '', $ad));

    // Update with encryption
    db_exec("UPDATE student SET 
        student_fn    = " . db_encrypt_col('fn', ':fn') . ",
        student_ln    = " . db_encrypt_col('ln', ':ln') . ",
        student_email = " . db_encrypt_col('em', ':em') . ",
        phone_number  = " . db_encrypt_col('ph', ':ph') . ",
        home_address  = " . db_encrypt_col('ad', ':ad') . "
        WHERE student_id = :sid", 
        [
            ':sid' => $s['student_id'], 
            ':fn' => $clean_fn, 
            ':ln' => $clean_ln, 
            ':em' => $clean_em, 
            ':ph' => $clean_ph, 
            ':ad' => $clean_ad,
            ':__enckey' => $key
        ]
    );
    echo "[DONE]\n";
}

echo "\n--- All PII Fields are now Encrypted & Clean ---";
