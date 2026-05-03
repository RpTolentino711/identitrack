<?php
require 'database/database.php';
try {
    // 1. Update login_method enum
    db_exec("ALTER TABLE community_service_session MODIFY COLUMN login_method ENUM('MANUAL','NFC') NOT NULL DEFAULT 'MANUAL'");
    echo "Updated login_method enum.\n";

    // 2. Add logout_method column if it doesn't exist
    $cols = db_all("SHOW COLUMNS FROM community_service_session LIKE 'logout_method'");
    if (empty($cols)) {
        db_exec("ALTER TABLE community_service_session ADD COLUMN logout_method ENUM('MANUAL','NFC') NULL AFTER login_method");
        echo "Added logout_method column.\n";
    } else {
        echo "logout_method column already exists.\n";
    }

    // 3. Update manual_login_request enum too just in case
    db_exec("ALTER TABLE manual_login_request MODIFY COLUMN login_method ENUM('MANUAL','NFC') NOT NULL DEFAULT 'MANUAL'");
    echo "Updated manual_login_request login_method enum.\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
