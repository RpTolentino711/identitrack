<?php
require_once __DIR__ . '/../database/database.php';
$cols = db_all("SHOW COLUMNS FROM upcc_case");
echo json_encode($cols, JSON_PRETTY_PRINT);
