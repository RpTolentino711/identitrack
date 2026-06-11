<?php
$names = ['Romeo Paolo', 'Tolentino', 'Jin', 'Maullon', ''];
$keys = [
    'IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!',
    'IdentiTrack_Secure_Key_2024_@SDO'
];

$methods = ['aes-128-ecb', 'aes-256-ecb'];

foreach ($names as $name) {
    echo "Plaintext: '$name'\n";
    foreach ($keys as $i => $k) {
        $sha256 = hash('sha256', $k, true);
        foreach ($methods as $method) {
            $keySize = strpos($method, '128') !== false ? 16 : 32;
            $aesKey = substr($sha256, 0, $keySize);
            
            $enc = openssl_encrypt($name, $method, $aesKey, OPENSSL_RAW_DATA);
            if ($enc !== false) {
                echo "  Key $i ($method): " . bin2hex($enc) . "\n";
            }
        }
    }
    echo "\n";
}
