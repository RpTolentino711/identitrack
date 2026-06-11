<?php
require_once __DIR__ . '/../database/database.php';
echo "DB_HOST: " . (string)($_ENV['DB_HOST'] ?? $_SERVER['DB_HOST'] ?? getenv('DB_HOST') ?: 'default:localhost') . "\n";
echo "DB_NAME: " . (string)($_ENV['DB_NAME'] ?? $_SERVER['DB_NAME'] ?? getenv('DB_NAME') ?: 'default:identitrack') . "\n";
echo "DB_USER: " . (string)($_ENV['DB_USER'] ?? $_SERVER['DB_USER'] ?? getenv('DB_USER') ?: 'default:root') . "\n";
try {
    $pdo = db();
    echo "SUCCESSfully connected to DB!\n";
} catch (Throwable $e) {
    echo "Connection failed: " . $e->getMessage() . "\n";
}
