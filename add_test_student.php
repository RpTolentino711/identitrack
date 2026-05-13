<?php
require_once __DIR__ . '/database/database.php';

header('Content-Type: text/plain');
echo "--- IdentiTrack Encryption Test Generator ---\n\n";

$key = db_encryption_key();

$sid = 'TEST-999';
$fn  = 'Test';
$ln  = 'Student';
$em  = 'test@identitrack.site';
$ph  = '0912-345-6789';
$ad  = '123 Security Lane, Encryption City';

try {
    // Delete if exists first
    db_exec("DELETE FROM student WHERE student_id = :sid", [':sid' => $sid]);

    // Insert with encryption
    db_exec("INSERT INTO student (student_id, student_fn, student_ln, student_email, phone_number, home_address, year_level, program, school, section) 
             VALUES (:sid, 
             " . db_encrypt_col('fn', ':fn') . ", 
             " . db_encrypt_col('ln', ':ln') . ", 
             " . db_encrypt_col('em', ':em') . ", 
             " . db_encrypt_col('ph', ':ph') . ", 
             " . db_encrypt_col('ad', ':ad') . ", 
             1, 'BSIT', 'College', 'INF-101')", 
             [
                ':sid' => $sid,
                ':fn'  => $fn,
                ':ln'  => $ln,
                ':em'  => $em,
                ':ph'  => $ph,
                ':ad'  => $ad,
                ':__enckey' => $key
             ]
    );

    echo "SUCCESS! Test student 'TEST-999' has been added.\n";
    echo "Go to your dashboard and search for 'TEST-999' to verify.\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
