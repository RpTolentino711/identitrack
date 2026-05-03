<?php
// File: C:\xampp\htdocs\identitrack\admin\AJAX\notifications_poll.php
// Poll endpoint: returns unread notification count for the current admin (for bell badge)
// - Excludes notifications created by the same admin (admin_id = current admin id)
// - Excludes deleted and read notifications

require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$admin = admin_current();
$adminId = (int)($admin['admin_id'] ?? $admin['id'] ?? 0);

$row = db_one(
  "SELECT COUNT(*) AS cnt
   FROM notification
   WHERE is_deleted = 0
     AND is_read = 0
     AND type <> 'GUARD_REPORT'
     AND (admin_id IS NULL OR admin_id <> ?)",
  [$adminId]
);

$guardRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM guard_violation_report
   WHERE status = 'PENDING' AND is_deleted = 0"
);
$pendingGuardCount = (int)($guardRow['cnt'] ?? 0);

$totalUnread = (int)($row['cnt'] ?? 0) + $pendingGuardCount;

$lastId = (int)($_GET['last_id'] ?? 0);

if ($lastId > 0) {
  $newNotifications = db_all(
    "SELECT notification_id, type, title, message, student_id, related_table, related_id, created_at
     FROM notification
     WHERE is_deleted = 0
       AND is_read = 0
       AND type <> 'GUARD_REPORT'
       AND notification_id > ?
       AND (admin_id IS NULL OR admin_id <> ?)
     ORDER BY notification_id ASC",
    [$lastId, $adminId]
  );
} else {
  // If no last_id provided, just get the very latest one
  $latest = db_one(
    "SELECT notification_id, type, title, message, student_id, related_table, related_id, created_at
     FROM notification
     WHERE is_deleted = 0
       AND is_read = 0
       AND type <> 'GUARD_REPORT'
       AND (admin_id IS NULL OR admin_id <> ?)
     ORDER BY notification_id DESC
     LIMIT 1",
    [$adminId]
  );
  $newNotifications = $latest ? [$latest] : [];
}

echo json_encode([
  'ok' => true,
  'unread' => $totalUnread,
  'pending_guard_count' => $pendingGuardCount,
  'new_notifications' => $newNotifications,
]);
exit;