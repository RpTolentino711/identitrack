<?php
require 'database/database.php';
foreach(['student','offense','upcc_case','guard_user', 'admin_user', 'manual_login_request', 'student_appeal_request'] as $t) {
    echo "Table: $t\n";
    try {
        $cols = db_all("SHOW COLUMNS FROM $t");
        foreach($cols as $c) {
            echo " - " . $c['Field'] . " (" . $c['Type'] . ")\n";
        }
    } catch(Exception $e) {
        echo " ERROR: " . $e->getMessage() . "\n";
    }
    echo "\n";
}
