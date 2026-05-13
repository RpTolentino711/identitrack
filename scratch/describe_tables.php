<?php
require_once __DIR__ . '/../database/database.php';
$tables = ['student', 'offense', 'student_appeal_request', 'upcc_case', 'community_service_requirement'];
foreach ($tables as $table) {
    try {
        $cols = db_all("DESCRIBE $table");
        echo "Table: $table\n";
        print_r($cols);
    } catch (Exception $e) {
        echo "Table $table not found or error: " . $e->getMessage() . "\n";
    }
}
