<?php
require_once __DIR__ . '/../../database/database.php';

try {
    db_exec("ALTER TABLE guard_violation_report ADD COLUMN evidence_file VARCHAR(255) DEFAULT NULL;");
    echo "Successfully added evidence_file column to guard_violation_report!\n";
} catch (\Throwable $e) {
    echo "Note: " . $e->getMessage() . "\n";
}
