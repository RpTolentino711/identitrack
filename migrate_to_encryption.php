<?php
require_once __DIR__ . '/database/database.php';

echo "--- IdentiTrack Encryption Migration ---\n";

$tables = [
    'student' => ['student_fn', 'student_ln', 'student_email', 'phone_number', 'home_address'],
    'offense' => ['description'],
    'upcc_case' => ['case_summary', 'final_decision', 'punishment_details'],
    'security_guard' => ['full_name', 'email'],
    'manual_login_request' => ['reason'],
    'student_appeal_request' => ['reason', 'admin_response']
];

$primaryKeys = [
    'student' => 'student_id',
    'offense' => 'offense_id',
    'upcc_case' => 'case_id',
    'security_guard' => 'guard_id',
    'manual_login_request' => 'request_id',
    'student_appeal_request' => 'appeal_id'
];

foreach ($tables as $table => $cols) {
    echo "\nProcessing Table: $table\n";
    $pk = $primaryKeys[$table];
    $rows = db_all("SELECT * FROM $table");
    
    foreach ($rows as $row) {
        $id = $row[$pk];
        $updates = [];
        $params = [':id' => $id];
        db_add_encryption_key($params);
        
        $needsUpdate = false;
        foreach ($cols as $col) {
            $val = (string)($row[$col] ?? '');
            if ($val === '') continue;
            
            // Check if already encrypted
            $checkParams = [':val' => $val, ':__enckey' => db_encryption_key()];
            $dec = db_one("SELECT AES_DECRYPT(:val, UNHEX(SHA2(:__enckey, 256))) AS result", $checkParams);
            
            if ($dec && $dec['result'] !== null) {
                // Already encrypted
                continue;
            }
            
            // Encrypt it
            $needsUpdate = true;
            $pName = ':val_' . $col;
            $updates[] = "$col = " . db_encrypt_col($col, $pName);
            $params[$pName] = $val;
        }
        
        if ($needsUpdate) {
            $sql = "UPDATE $table SET " . implode(', ', $updates) . " WHERE $pk = :id";
            db_exec($sql, $params);
            echo "  [OK] Updated $table ID: $id\n";
        }
    }
}

echo "\n--- Migration Complete ---\n";
