<?php
require_once __DIR__ . '/../database/database.php';
$pdo = db();
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$studentTables = [];
foreach ($tables as $t) {
    $cols = $pdo->query("SHOW COLUMNS FROM `$t` LIKE 'student_id'")->fetchAll();
    if (!empty($cols)) {
        $studentTables[] = $t;
    }
}
echo implode("\n", $studentTables);
