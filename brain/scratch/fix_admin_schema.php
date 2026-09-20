<?php
require_once 'c:/xampp/htdocs/identitrack/database/database.php';

try {
    $row = db_one("SELECT active_session_token FROM admin_user LIMIT 1");
    echo "active_session_token column exists!\n";
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    // Add column safely
    try {
        db_exec("ALTER TABLE admin_user ADD COLUMN active_session_token VARCHAR(255) NULL");
        echo "Added active_session_token column!\n";
    } catch (\Throwable $e2) {
        echo "ALTER ERROR: " . $e2->getMessage() . "\n";
    }
}
