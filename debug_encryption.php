<?php
require_once __DIR__ . '/database/database.php';

header('Content-Type: text/plain');
echo "--- IDENTITRACK ENCRYPTION DEBUG ---\n\n";

echo "DEBUG INFO:\n";
echo "Current Dir: " . __DIR__ . "\n";
echo "Looking for .env in: " . realpath(__DIR__ . '/.env') . "\n";
echo "Looking for .env in: " . realpath(__DIR__ . '/../.env') . "\n";
echo "File exists (./.env): " . (file_exists(__DIR__ . '/.env') ? "YES" : "NO") . "\n";
echo "File exists (../.env): " . (file_exists(__DIR__ . '/../.env') ? "YES" : "NO") . "\n";

if (file_exists(__DIR__ . '/.env')) {
    echo "\nKeys found in .env:\n";
    $lines = file(__DIR__ . '/.env');
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0) continue;
        if (strpos($line, '=') !== false) {
            list($name, $value) = explode('=', $line, 2);
            echo "- " . trim($name) . " (Value length: " . strlen(trim($value)) . ")\n";
        }
    }
}
echo "\n";

$key = db_encryption_key();
echo "1. Encryption Key Found: " . ($key !== '' ? "YES (Length: " . strlen($key) . ")" : "NO") . "\n";
if ($key === 'IdentiTrack_Secure_Key_2024_@SDO') {
    echo "   (Note: Using default fallback key)\n";
}

try {
    $params = [':__enckey' => $key];
    
    // Test 1: Can we encrypt and decrypt a test string?
    $test = db_one("SELECT CAST(AES_DECRYPT(AES_ENCRYPT('Hello World', UNHEX(SHA2(:__enckey, 256))), UNHEX(SHA2(:__enckey, 256))) AS CHAR) as result", $params);
    echo "2. Self-Test (Encrypt -> Decrypt): " . ($test['result'] === 'Hello World' ? "SUCCESS" : "FAILED") . "\n";

    // Test 2: Look at raw data in DB
    $raw = db_one("SELECT student_id, student_fn, HEX(student_fn) as hex_fn FROM student WHERE student_fn IS NOT NULL LIMIT 1");
    if ($raw) {
        echo "3. Sample Student ID: " . $raw['student_id'] . "\n";
        echo "4. Raw Data Length: " . strlen($raw['student_fn'] ?? '') . " bytes\n";
        echo "5. Data starts with HEX: " . substr($raw['hex_fn'] ?? '', 0, 16) . "...\n";
        
        // Attempt decryption with multiple methods
        $methods = [
            'Standard (RAW + SHA2)' => "CAST(AES_DECRYPT(student_fn, UNHEX(SHA2(:__enckey, 256))) AS CHAR)",
            'Legacy (RAW + Plain Key)' => "CAST(AES_DECRYPT(student_fn, :__enckey) AS CHAR)",
            'Hex-Wrapped (UNHEX + SHA2)' => "CAST(AES_DECRYPT(UNHEX(student_fn), UNHEX(SHA2(:__enckey, 256))) AS CHAR)",
            'Hex-Wrapped (UNHEX + Plain Key)' => "CAST(AES_DECRYPT(UNHEX(student_fn), :__enckey) AS CHAR)"
        ];

        foreach ($methods as $name => $sql) {
            try {
                $dec = db_one("SELECT $sql as name FROM student WHERE student_id = :sid", [
                    ':sid' => $raw['student_id'],
                    ':__enckey' => $key
                ]);
                echo "6. Method [$name]: " . ($dec['name'] ?? "NULL") . "\n";
            } catch (Exception $e) {
                echo "6. Method [$name]: ERROR (" . $e->getMessage() . ")\n";
            }
        }
    } else {
        echo "3. No students found with data.\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
