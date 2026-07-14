<?php
require_once __DIR__ . '/database/database.php';
header('Content-Type: text/plain');

try {
    $case = db_one("SELECT case_id, decided_category, punishment_details FROM upcc_case WHERE case_id = 18");
    if ($case) {
        echo "Case ID: {$case['case_id']}\n";
        echo "Decided Category: {$case['decided_category']}\n";
        echo "Punishment Details: {$case['punishment_details']}\n";
    } else {
        echo "Case 18 not found.\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
