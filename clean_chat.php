<?php
require_once __DIR__ . '/database/database.php';

try {
    // Delete orphaned rows from discussion and activity that don't have a matching case
    db_exec("DELETE FROM upcc_case_discussion WHERE case_id NOT IN (SELECT case_id FROM upcc_case)");
    db_exec("DELETE FROM upcc_case_activity WHERE case_id NOT IN (SELECT case_id FROM upcc_case)");

    echo "Orphaned chat and activity logs cleaned up successfully.";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage();
}
