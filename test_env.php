<?php
require 'database/database.php';
$getEnv = function($key, $default) {
    return (string)($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default);
};
var_dump($getEnv('SMTP_PASS', 'fallback'));
var_dump($_ENV['SMTP_PASS'] ?? 'env null');
var_dump($_SERVER['SMTP_PASS'] ?? 'server null');
