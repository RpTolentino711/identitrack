<?php
require_once __DIR__ . '/../database/database.php';

$target = '2023-184363';
echo "=== Search Results for student ID: $target ===\n\n";

$tables = db_all("SHOW TABLES");
foreach ($tables as $t) {
    $table = array_values($t)[0];
    try {
        $cols = db_all("SHOW COLUMNS FROM `$table`");
        $colNames = array_map(function($c) { return $c['Field'] ?? $c['FIELD'] ?? ''; }, $cols);
        
        $whereClauses = [];
        $params = [];
        foreach ($colNames as $col) {
            $whereClauses[] = "`$col` LIKE :target";
        }
        
        $query = "SELECT * FROM `$table` WHERE " . implode(' OR ', $whereClauses);
        $rows = db_all($query, [':target' => "%$target%"]);
        
        if (!empty($rows)) {
            echo "Table: $table\n";
            foreach ($rows as $r) {
                echo "  Row: " . json_encode($r) . "\n";
            }
            echo "\n";
        }
    } catch (Exception $e) {
        // ignore
    }
}
