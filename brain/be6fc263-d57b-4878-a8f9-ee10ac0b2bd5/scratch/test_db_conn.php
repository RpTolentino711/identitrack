<?php
try {
    echo "Connecting to MySQL 127.0.0.1...\n";
    $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=u321173822_track;charset=utf8mb4", "u321173822_titrack", "Pogilameg@10", [
        PDO::ATTR_TIMEOUT => 3,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    echo "SUCCESS: Connected to MySQL database u321173822_track!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
