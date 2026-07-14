<?php
require_once __DIR__ . '/database/database.php';
header('Content-Type: text/plain');

try {
    $cases = db_all("SELECT case_id, decided_category, punishment_details, status FROM upcc_case WHERE student_id = '2023-183482'");
    echo "=== ALL CASES FOR STUDENT 2023-183482 ===\n";
    foreach ($cases as $c) {
        echo "Case ID: {$c['case_id']}, Cat: {$c['decided_category']}, Details: {$c['punishment_details']}, Status: {$c['status']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
