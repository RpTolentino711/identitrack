<?php
$names = ['Romeo Paolo', 'Tolentino', 'Jin', 'Maullon', '2023-183482', '2023-184363', ''];
$keys = [
    'IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!',
    'IdentiTrack_Secure_Key_2024_@SDO'
];

$methods = ['aes-128-ecb', 'aes-256-ecb', 'aes-128-cbc', 'aes-256-cbc'];

foreach ($names as $name) {
    echo "Plaintext: '$name'\n";
    foreach ($keys as $i => $k) {
        foreach ($methods as $method) {
            // In MySQL, direct string key is padded/truncated to fit key size
            $keySize = strpos($method, '128') !== false ? 16 : 32;
            $aesKey = str_pad($k, $keySize, "\0");
            $aesKey = substr($aesKey, 0, $keySize);
            
            $ivSize = openssl_cipher_iv_length($method);
            $iv = $ivSize > 0 ? str_repeat("\0", $ivSize) : '';
            
            $enc = openssl_encrypt($name, $method, $aesKey, OPENSSL_RAW_DATA, $iv);
            if ($enc !== false) {
                echo "  Key $i ($method): " . bin2hex($enc) . "\n";
            }
        }
    }
    echo "\n";
}
