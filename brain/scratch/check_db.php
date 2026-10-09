<?php
require_once __DIR__ . '/../../database/database.php';

try {
    $rows = db_all("SELECT upcc_id, username, email, is_active, password_hash, must_change_password FROM upcc_user");
    print_r($rows);
} catch (Exception $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}
