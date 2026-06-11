<?php
if (file_exists(__DIR__ . '/database.php')) {
    require_once __DIR__ . '/database.php';
} else {
    require_once __DIR__ . '/database/database.php';
}

header('Content-Type: text/plain');
echo "=== IdentiTrack Database Diagnostic ===\n\n";

$key = db_encryption_key();
echo "Loaded DB_ENCRYPTION_KEY: " . var_export($key, true) . "\n";
echo "Key Length: " . strlen($key) . "\n\n";

try {
    $tables = db_all("SHOW TABLES");
    echo "=== Database Tables ===\n";
    $backupTables = [];
    foreach ($tables as $t) {
        $name = array_values($t)[0];
        echo "  - $name\n";
        if (strpos(strtolower($name), 'backup') !== false || strpos(strtolower($name), 'student') !== false) {
            $backupTables[] = $name;
        }
    }
    echo "\n";
    
    foreach ($backupTables as $table) {
        if ($table === 'student') continue;
        echo "=== Content of Backup Table: $table ===\n";
        try {
            $cols = db_all("SHOW COLUMNS FROM `$table`");
            $colNames = array_map(function($c) { return $c['Field'] ?? $c['FIELD'] ?? ''; }, $cols);
            echo "Columns: " . implode(', ', $colNames) . "\n";
            
            $rows = db_all("SELECT * FROM `$table` LIMIT 10");
            foreach ($rows as $r) {
                echo "  Row: " . json_encode($r) . "\n";
            }
        } catch (Exception $ex) {
            echo "  Error querying table $table: " . $ex->getMessage() . "\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "ERROR Listing Tables: " . $e->getMessage() . "\n\n";
}

try {
    $rows = db_all("SELECT student_id, student_fn, student_ln, student_email, phone_number, home_address FROM student LIMIT 10");
    echo "Total students found: " . count($rows) . "\n\n";
    
    foreach ($rows as $index => $row) {
        echo "Row #$index (ID: {$row['student_id']}):\n";
        foreach (['student_fn', 'student_ln', 'student_email', 'phone_number', 'home_address'] as $col) {
            $val = $row[$col];
            if ($val === null) {
                echo "  $col: NULL\n";
            } else {
                $hex = bin2hex($val);
                $isPrintable = preg_match('/^[[:print:]]{1,100}$/', $val);
                echo "  $col:\n";
                echo "    Raw length: " . strlen($val) . " bytes\n";
                echo "    Hex: $hex\n";
                echo "    Is printable ASCII: " . ($isPrintable ? 'YES' : 'NO') . "\n";
                if ($isPrintable) {
                    echo "    Value: " . var_export($val, true) . "\n";
                }
                
                // Test decryption with loaded key
                $dec = db_one("SELECT CAST(AES_DECRYPT(:val, UNHEX(SHA2(:key, 256))) AS CHAR) AS decrypted", [
                    ':val' => $val,
                    ':key' => $key
                ]);
                echo "    Decrypted with env key: " . var_export($dec['decrypted'] ?? null, true) . "\n";
                
                // Test decryption with setup key
                $setupKey = 'IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!';
                $decSetup = db_one("SELECT CAST(AES_DECRYPT(:val, UNHEX(SHA2(:key, 256))) AS CHAR) AS decrypted", [
                    ':val' => $val,
                    ':key' => $setupKey
                ]);
                echo "    Decrypted with setup key: " . var_export($decSetup['decrypted'] ?? null, true) . "\n";
            }
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
