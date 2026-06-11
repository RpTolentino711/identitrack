<?php
$hexValues = [
    '2023-183482 student_fn' => 'f2a69b2249f6d109a5df59c35a0c6855',
    '2023-183482 student_ln' => '97759d4573b5d06e0faa84a4293414a5',
    '2023-184363 student_fn' => '699067089d5f55019b40b210ec057c98',
];

$keys = [
    'IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!',
    'IdentiTrack_Secure_Key_2024_@SDO'
];

$methods = ['aes-128-ecb', 'aes-256-ecb', 'aes-128-cbc', 'aes-256-cbc'];

foreach ($hexValues as $label => $hex) {
    echo "=== $label ($hex) ===\n";
    $bin = hex2bin($hex);
    
    foreach ($keys as $i => $k) {
        $sha256 = hash('sha256', $k, true);
        foreach ($methods as $method) {
            $keySize = strpos($method, '128') !== false ? 16 : 32;
            $aesKey = substr($sha256, 0, $keySize);
            
            $ivSize = openssl_cipher_iv_length($method);
            $iv = $ivSize > 0 ? str_repeat("\0", $ivSize) : '';
            
            // Try with PKCS#7 padding (default)
            $dec = openssl_decrypt($bin, $method, $aesKey, OPENSSL_RAW_DATA, $iv);
            if ($dec !== false) {
                echo "  Key $i ($method): '" . $dec . "' (hex: " . bin2hex($dec) . ")\n";
            }
            
            // Try with ZERO_PADDING
            $dec2 = openssl_decrypt($bin, $method, $aesKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
            if ($dec2 !== false) {
                echo "  Key $i ($method) [ZeroPad]: (hex: " . bin2hex($dec2) . ")\n";
            }
        }
    }
    echo "\n";
}
