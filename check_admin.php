<?php
require_once __DIR__ . '/database/database.php';
header('Content-Type: text/plain');

try {
    $admins = db_all("SELECT admin_id, username FROM admin_user");
    echo "=== ADMIN USERS ===\n";
    foreach ($admins as $a) {
        echo "ID: {$a['admin_id']}, Username: {$a['username']}\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
