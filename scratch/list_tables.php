<?php
require_once __DIR__ . '/../database/database.php';
$tabs = db_all("SHOW TABLES");
header('Content-Type: application/json');
echo json_encode($tabs, JSON_PRETTY_PRINT);
