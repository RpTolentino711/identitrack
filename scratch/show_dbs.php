<?php
$pdo = new PDO("mysql:host=127.0.0.1;charset=utf8mb4", "root", "");
print_r($pdo->query("SHOW DATABASES")->fetchAll());
