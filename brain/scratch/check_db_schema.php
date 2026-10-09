<?php
require_once __DIR__ . '/../../database/database.php';

$pdo = getConnection();

echo "--- GUARD VIOLATION REPORT COLUMNS ---\n";
$cols = db_all("DESCRIBE guard_violation_report");
foreach ($cols as $c) {
    echo $c['Field'] . " (" . $c['Type'] . ")\n";
}

echo "\n--- LATEST GUARD VIOLATION REPORTS WITH EVIDENCE ---\n";
$reports = db_all("SELECT report_id, student_id, offense_type_id, status, evidence_file, created_at FROM guard_violation_report ORDER BY report_id DESC LIMIT 10");
print_r($reports);

echo "\n--- LATEST OFFENSES WITH EVIDENCE ---\n";
$offenses = db_all("SELECT offense_id, student_id, offense_type_id, level, status, evidence_file, created_at FROM offense ORDER BY offense_id DESC LIMIT 10");
print_r($offenses);
