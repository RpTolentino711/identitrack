<?php
require_once __DIR__ . '/database/database.php';
header('Content-Type: text/plain');

try {
    $columns = db_all("SHOW COLUMNS FROM community_service_requirement");
    echo "=== SCHEMA ===\n";
    foreach ($columns as $col) {
        echo "{$col['Field']} - {$col['Type']}\n";
    }

    $rows = db_all("SELECT * FROM community_service_requirement WHERE student_id = '2023-183482'");
    echo "\n=== ROWS ===\n";
    foreach ($rows as $r) {
        echo "Req ID: {$r['requirement_id']}, Case ID: " . var_export($r['related_case_id'], true) . ", Status: {$r['status']}, Hours Req: {$r['hours_required']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
