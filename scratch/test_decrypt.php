<?php
$key = 'IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!';
$hashed_key = hash('sha256', $key, true);

$modes = ['aes-128-ecb', 'aes-192-ecb', 'aes-256-ecb'];

foreach ($modes as $mode) {
    $len = ($mode === 'aes-128-ecb') ? 16 : (($mode === 'aes-192-ecb') ? 24 : 32);
    $k = substr($hashed_key, 0, $len);
    $enc = openssl_encrypt('', $mode, $k, OPENSSL_RAW_DATA);
    echo "$mode with hashed key: " . bin2hex($enc) . "\n";
    
    $k_raw = substr($key, 0, $len);
    $enc_raw = openssl_encrypt('', $mode, $k_raw, OPENSSL_RAW_DATA);
    echo "$mode with raw key: " . bin2hex($enc_raw) . "\n";
}
