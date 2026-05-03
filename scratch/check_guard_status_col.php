<?php
require_once __DIR__ . '/../database/database.php';
$col = db_one("SHOW COLUMNS FROM guard_violation_report LIKE 'status'");
header('Content-Type: application/json');
echo json_encode($col, JSON_PRETTY_PRINT);
