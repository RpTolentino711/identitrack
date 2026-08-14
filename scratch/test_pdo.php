<?php
$ports = [3306, 3307, 3308, 8080];
foreach ($ports as $port) {
    try {
        $p = new PDO("mysql:host=127.0.0.1;port=$port", 'root', '');
        echo "CONNECTED ON PORT $port!\n";
        $dbs = $p->query("SHOW DATABASES")->fetchAll(PDO::FETCH_COLUMN);
        echo "Databases: " . implode(', ', $dbs) . "\n";
    } catch (Exception $e) {
        echo "Port $port failed: " . $e->getMessage() . "\n";
    }
}
