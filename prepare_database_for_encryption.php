<?php
require_once __DIR__ . '/database/database.php';

echo "--- IdentiTrack Database Schema Preparation ---\n";

$migrations = [
    // Table: student
    "ALTER TABLE student MODIFY student_fn VARBINARY(255) NOT NULL",
    "ALTER TABLE student MODIFY student_ln VARBINARY(255) NOT NULL",
    "ALTER TABLE student MODIFY student_email VARBINARY(255) NOT NULL",
    "ALTER TABLE student MODIFY home_address VARBINARY(512) DEFAULT NULL",
    "ALTER TABLE student MODIFY phone_number VARBINARY(128) DEFAULT NULL",
    
    // Table: offense
    "ALTER TABLE offense MODIFY description BLOB DEFAULT NULL",
    
    // Table: upcc_case
    "ALTER TABLE upcc_case MODIFY case_summary BLOB DEFAULT NULL",
    "ALTER TABLE upcc_case MODIFY final_decision BLOB DEFAULT NULL",
    "ALTER TABLE upcc_case MODIFY punishment_details BLOB DEFAULT NULL",
    "ALTER TABLE upcc_case MODIFY student_explanation_text BLOB DEFAULT NULL",
    
    // Table: security_guard
    "ALTER TABLE security_guard MODIFY full_name VARBINARY(255) NOT NULL",
    "ALTER TABLE security_guard MODIFY email VARBINARY(255) NOT NULL",
    
    // Table: manual_login_request
    "ALTER TABLE manual_login_request MODIFY reason VARBINARY(512) DEFAULT NULL",
    
    // Table: student_appeal_request
    "ALTER TABLE student_appeal_request MODIFY reason BLOB NOT NULL",
    "ALTER TABLE student_appeal_request MODIFY admin_response BLOB DEFAULT NULL"
];

foreach ($migrations as $sql) {
    try {
        echo "Running: $sql\n";
        db_exec($sql);
        echo "✓ OK\n";
    } catch (Exception $e) {
        echo "✗ FAILED: " . $e->getMessage() . "\n";
    }
}

echo "\n--- Schema Preparation Complete ---\n";
