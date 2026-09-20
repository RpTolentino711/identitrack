<?php
require_once __DIR__ . '/../../database/database.php';
$admins = db_all("SELECT admin_id, username, email, is_active, COALESCE(setup_pending,0) AS setup_pending FROM admin_user");
echo "Found " . count($admins) . " admins:\n";
print_r($admins);
