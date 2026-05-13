<?php
require_once __DIR__ . '/database/database.php';

echo "--- IdentiTrack Encryption Verification ---\n";

$tables = [
    'student' => ['student_fn', 'student_ln', 'student_email', 'phone_number', 'home_address'],
    'offense' => ['description'],
    'upcc_case' => ['case_summary', 'final_decision', 'punishment_details'],
    'security_guard' => ['full_name', 'email'],
    'manual_login_request' => ['reason'],
    'student_appeal_request' => ['reason', 'admin_response']
];

foreach ($tables as $table => $cols) {
    echo "\nTable: $table\n";
    try {
        $row = db_one("SELECT * FROM $table LIMIT 1");
    } catch (Exception $e) {
        echo "  [ERROR] Table access failed: " . $e->getMessage() . "\n";
        continue;
    }
    
    if (!$row) {
        echo "  [SKIP] No records found.\n";
        continue;
    }

    foreach ($cols as $col) {
        if (!array_key_exists($col, $row)) {
            echo "  [ERROR] Column '$col' does not exist.\n";
            continue;
        }

        $val = (string)$row[$col];
        if ($val === '') {
            echo "  [INFO] Column '$col' is empty.\n";
            continue;
        }
        
        $params = [':val' => $val, ':__enckey' => db_encryption_key()];
        $decrypted = db_one("SELECT AES_DECRYPT(:val, UNHEX(SHA2(:__enckey, 256))) AS result", $params);
        
        if ($decrypted && $decrypted['result'] !== null) {
            echo "  [OK] Column '$col' is encrypted and decryptable.\n";
        } else {
            // Check if it's plaintext
            if (preg_match('/[a-zA-Z0-9]/', $val)) {
                echo "  [WARNING] Column '$col' appears to be PLAINTEXT: " . substr($val, 0, 20) . "...\n";
            } else {
                echo "  [ERROR] Column '$col' is binary but decryption FAILED.\n";
            }
        }
    }
}

echo "\n--- Verification Complete ---\n";
