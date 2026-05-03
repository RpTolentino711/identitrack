<?php
require_once __DIR__ . '/../database/database.php';
$admin = admin_current();
$adminId = (int)($admin['admin_id'] ?? $admin['id'] ?? 0);

$notifs = db_all(
    "SELECT * FROM notification 
     WHERE is_deleted = 0 AND is_read = 0 
     AND (admin_id IS NULL OR admin_id <> ?)
     ORDER BY created_at DESC",
    [$adminId]
);
header('Content-Type: application/json');
echo json_encode($notifs, JSON_PRETTY_PRINT);
