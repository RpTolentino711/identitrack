<?php
require_once __DIR__ . '/../database/database.php';
$cols = db_all("DESCRIBE guard_violation_report");
header('Content-Type: application/json');
echo json_encode($cols, JSON_PRETTY_PRINT);
