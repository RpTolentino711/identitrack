<?php
/**
 * Database Decryption Migration Script
 */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/database.php';

header('Content-Type: text/plain');

$key = db_encryption_key();

echo "=== IdentiTrack Database Decryption Migration ===\n";
echo "Key: " . substr($key, 0, 10) . "****...\n\n";

// Helper function to check if a value is encrypted and decrypt it
function decrypt_value($val, $key) {
    if ($val === null || $val === '') {
        return null;
    }
    
    // Check if the value is hexadecimal or binary representation.
    // MySQL AES_ENCRYPT returns binary, which is often stored as BLOB or hex string.
    try {
        // Try decrypting the value directly using AES_DECRYPT.
        // We CAST to CHAR to retrieve it as a string.
        $row = db_one("SELECT CAST(AES_DECRYPT(:val, UNHEX(SHA2(:key, 256))) AS CHAR) AS decrypted", [
            ':val' => $val,
            ':key' => $key
        ]);
        if ($row && $row['decrypted'] !== null) {
            return $row['decrypted'];
        }
    } catch (Exception $e) {
        // Decryption failed or column is not in a format that AES_DECRYPT accepts
    }
    return null;
}

// Dynamically discover the primary key column of a table
function get_primary_key(string $table): ?string {
    try {
        $keys = db_all("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'");
        if (!empty($keys)) {
            return $keys[0]['Column_name'] ?? $keys[0]['COLUMN_NAME'] ?? null;
        }
    } catch (Exception $e) {
        // Fallback for different database configurations
    }
    
    // Hardcoded common fallbacks
    if ($table === 'student') return 'student_id';
    if ($table === 'offense') return 'offense_id';
    if ($table === 'student_appeal_request') return 'appeal_id';
    if ($table === 'upcc_case') return 'case_id';
    if ($table === 'community_service_requirement') return 'requirement_id';
    if ($table === 'security_guard') return 'guard_id';
    if ($table === 'manual_login_request') return 'request_id';
    
    return null;
}

$tablesToDecrypt = [
    'student' => ['student_fn', 'student_ln', 'student_email', 'phone_number', 'home_address'],
    'offense' => ['description', 'location', 'details', 'reason'],
    'student_appeal_request' => ['reason', 'admin_response'],
    'upcc_case' => ['student_explanation_text', 'case_summary', 'final_decision', 'punishment_details'],
    'community_service_requirement' => ['task_name', 'location', 'notes', 'reason', 'contact_person', 'contact_number'],
    'security_guard' => ['full_name', 'email'],
    'manual_login_request' => ['reason']
];

foreach ($tablesToDecrypt as $table => $cols) {
    echo "Processing table: $table...\n";
    
    // Check if table exists
    try {
        $tableCheck = db_one("SHOW TABLES LIKE :table", [':table' => $table]);
        if (!$tableCheck) {
            echo "  [SKIP] Table '$table' does not exist in the database.\n";
            continue;
        }
    } catch (Exception $e) {
        echo "  [ERROR] Failed to verify existence of table '$table': " . $e->getMessage() . "\n";
        continue;
    }

    $pk = get_primary_key($table);
    if (!$pk) {
        echo "  [ERROR] Could not determine primary key for table '$table'. Skipping.\n";
        continue;
    }

    try {
        $rows = db_all("SELECT * FROM `$table`");
        $decryptedCount = 0;
        
        foreach ($rows as $row) {
            $updateCols = [];
            $updateParams = [':pk_val' => $row[$pk]];
            $needsUpdate = false;
            
            foreach ($cols as $col) {
                if (!array_key_exists($col, $row)) {
                    continue;
                }
                
                $decrypted = decrypt_value($row[$col], $key);
                if ($decrypted !== null) {
                    $updateCols[] = "`$col` = :$col";
                    $updateParams[":$col"] = $decrypted;
                    $needsUpdate = true;
                }
            }
            
            if ($needsUpdate) {
                $sql = "UPDATE `$table` SET " . implode(', ', $updateCols) . " WHERE `$pk` = :pk_val";
                db_exec($sql, $updateParams);
                $decryptedCount++;
            }
        }
        echo "  ✓ Table '$table' processed. Decrypted $decryptedCount rows.\n";
    } catch (Exception $e) {
        echo "  ✗ Error processing table '$table': " . $e->getMessage() . "\n";
    }
}

echo "\n=== Decryption Migration Complete ===\n";
