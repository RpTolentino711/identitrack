<?php
require_once __DIR__ . '/../../database/database.php';

$historicalBase = 3441;

try {
    $dbCases = db_one("SELECT COUNT(*) as cnt FROM upcc_case");
    $dbOffenses = db_one("SELECT COUNT(*) as cnt FROM student_offense");
    $aiAuditLogs = db_one("SELECT COUNT(*) as cnt FROM ai_analysis_log");
    echo "upcc_case count: " . ($dbCases['cnt'] ?? 0) . "\n";
    echo "student_offense count: " . ($dbOffenses['cnt'] ?? 0) . "\n";
    echo "ai_analysis_log count: " . ($aiAuditLogs['cnt'] ?? 0) . "\n";
} catch (\Throwable $e) {
    echo "Error querying DB: " . $e->getMessage() . "\n";
}
