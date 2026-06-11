<?php
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

// Read keys from local .env or fallback
$envKey = 'IdentiTrack_Secure_Key_2024_@SDO'; // Default/setup key we saw in diagnostics
if (file_exists(__DIR__ . '/../.env')) {
    $envLines = file(__DIR__ . '/../.env');
    foreach ($envLines as $line) {
        if (strpos($line, 'DB_ENCRYPTION_KEY') !== false) {
            $parts = explode('=', $line, 2);
            if (isset($parts[1])) {
                $envKey = trim(str_replace(['"', "'"], '', $parts[1]));
            }
        }
    }
}

$keys = [
    $envKey,
    'IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!',
    'IdentiTrack_Secure_Key_2024_@SDO'
];

foreach ($hexValues as $label => $hex) {
    echo "$label ($hex):\n";
    $bin = hex2bin($hex);
    
    // Check if it's already plain text
    $isPrintable = preg_match('/^[[:print:]]{1,100}$/', $bin);
    if ($isPrintable) {
        echo "  As plaintext: '$bin'\n";
    }
    
    $methods = ['aes-128-ecb', 'aes-256-ecb', 'aes-128-cbc', 'aes-256-cbc'];
    
    foreach ($keys as $i => $k) {
        $sha256 = hash('sha256', $k, true);
        
        foreach ($methods as $method) {
            // Determine key size for method
            $keySize = strpos($method, '128') !== false ? 16 : 32;
            $aesKey = substr($sha256, 0, $keySize);
            
            $ivSize = openssl_cipher_iv_length($method);
            $iv = $ivSize > 0 ? str_repeat("\0", $ivSize) : '';
            
            $dec = openssl_decrypt($bin, $method, $aesKey, OPENSSL_RAW_DATA, $iv);
            if ($dec !== false && preg_match('/^[[:print:]\s]{1,150}$/', $dec)) {
                echo "  Key $i ($method): '" . trim($dec) . "'\n";
            }
        }
    }
    echo "\n";
}
