<?php
try {
    $pdo = new PDO("mysql:host=localhost", "root", "");
    $stmt = $pdo->query("SHOW DATABASES");
    $dbs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "DATABASES:\n";
    print_r($dbs);
} catch (PDOException $e) {
    echo "FAILED: " . $e->getMessage() . "\n";
}
