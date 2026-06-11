<?php
require_once __DIR__ . '/../database/database.php';

$hexValues = [
    '2023-183482 student_fn' => 'f2a69b2249f6d109a5df59c35a0c6855',
    '2023-183482 student_ln' => '97759d4573b5d06e0faa84a4293414a5',
    '2023-183482 student_email' => '97759d4573b5d06e0faa84a4293414a5',
    '2023-183482 home_address' => '97759d4573b5d06e0faa84a4293414a5',
    '2023-183482 phone_number' => '97759d4573b5d06e0faa84a4293414a5',
    '2023-184363 student_fn' => '699067089d5f55019b40b210ec057c98',
    '2023-184363 student_ln' => '97759d4573b5d06e0faa84a4293414a5',
    '2023-184363 home_address' => '557064617465205265717569726564',
    '2023-184363 phone_number' => '3030302d3030302d30303030',
];

$keys = [
    db_encryption_key(),
    'IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!',
    'IdentiTrack_Secure_Key_2024_@SDO'
];

foreach ($hexValues as $label => $hex) {
    echo "$label ($hex):\n";
    $bin = hex2bin($hex);
    
    // Check if it's already plain text (like 'Update Required' or '000-000-0000')
    $isPrintable = preg_match('/^[[:print:]]{1,100}$/', $bin);
    if ($isPrintable) {
        echo "  As plaintext: '$bin'\n";
    }
    
    foreach ($keys as $i => $k) {
        try {
            $row = db_one("SELECT CAST(AES_DECRYPT(:val, UNHEX(SHA2(:key, 256))) AS CHAR) AS decrypted", [
                ':val' => $bin,
                ':key' => $k
            ]);
            $dec = $row['decrypted'] ?? null;
            echo "  Key $i: " . ($dec !== null ? "'$dec'" : "NULL") . "\n";
        } catch (Exception $e) {
            echo "  Key $i: Error: " . $e->getMessage() . "\n";
        }
    }
    echo "\n";
}
