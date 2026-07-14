<?php
require_once __DIR__ . '/database/database.php';
header('Content-Type: text/plain');

try {
    echo "=== UPDATING REQ ID 44 ===\n";
    db_exec("UPDATE community_service_requirement SET hours_required = 0.3333 WHERE requirement_id = 44");
    echo "Update completed.\n";

    echo "\n=== STUDENT REQUIREMENTS ===\n";
    $reqs = db_all("SELECT requirement_id, related_case_id, hours_required, status, assigned_at, completed_at FROM community_service_requirement WHERE student_id = '2023-183482'");
    foreach ($reqs as $r) {
        echo "Req ID: {$r['requirement_id']}, Case ID: {$r['related_case_id']}, Hours: {$r['hours_required']}, Status: {$r['status']}, Assigned: {$r['assigned_at']}, Completed: {$r['completed_at']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
