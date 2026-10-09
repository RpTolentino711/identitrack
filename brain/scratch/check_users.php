<?php
require_once __DIR__ . '/../../database/database.php';

$upccUsers = db_all("SELECT upcc_id, username, email, full_name FROM upcc_user");
$adminUsers = db_all("SELECT admin_id, username, email, name FROM admin");

echo "=== UPCC USERS ===\n";
print_r($upccUsers);

echo "=== ADMIN USERS ===\n";
print_r($adminUsers);
