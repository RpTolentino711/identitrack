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
     AND (admin_id IS NULL OR admin_id = ?)",
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
       AND (admin_id IS NULL OR admin_id = ?)
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
       AND (admin_id IS NULL OR admin_id = ?)
     ORDER BY notification_id DESC
     LIMIT 1",
    [$adminId]
  );
  $newNotifications = $latest ? [$latest] : [];
}

$decrypted_student = db_decrypt_cols(['student_fn', 'student_ln']);
$pollParams = [];
db_add_encryption_key($pollParams);

$completedServices = db_all(
  "SELECT 
      csr.requirement_id,
      csr.student_id,
      csr.task_name,
      csr.hours_required,
      csr.completed_at,
      $decrypted_student
   FROM community_service_requirement csr
   JOIN student s ON s.student_id = csr.student_id
   WHERE csr.status = 'COMPLETED'
     AND csr.completed_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
     AND NOT EXISTS (
       SELECT 1 FROM community_service_requirement csr_act
       WHERE csr_act.student_id = csr.student_id AND csr_act.status = 'ACTIVE'
     )
   ORDER BY csr.completed_at DESC",
  $pollParams
);

$pendingSanctionsCount = 0;
try {
  $sanctRow = db_one("
    SELECT COUNT(DISTINCT csr.student_id) AS cnt
    FROM community_service_requirement csr
    LEFT JOIN upcc_case uc ON (uc.case_id = csr.related_case_id OR uc.student_id = csr.student_id)
    WHERE csr.status NOT IN ('COMPLETED', 'CANCELLED')
      AND csr.hours_required > 0
      AND (
        SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, sess.time_in, sess.time_out)/3600.0), 0.0)
        FROM community_service_session sess
        WHERE sess.requirement_id = csr.requirement_id AND sess.time_out IS NOT NULL
      ) >= (csr.hours_required - 0.0001)
      AND (
        uc.punishment_details IS NULL
        OR (
          uc.punishment_details NOT LIKE '%\"completed\":true%'
          AND uc.punishment_details NOT LIKE '%\"completed\":1%'
        )
      )
  ");
  $pendingSanctionsCount = (int)($sanctRow['cnt'] ?? 0);
} catch (\Throwable $e) {}

echo json_encode([
  'ok' => true,
  'unread' => $totalUnread,
  'pending_guard_count' => $pendingGuardCount,
  'pending_sanctions_count' => $pendingSanctionsCount,
  'new_notifications' => $newNotifications,
  'completed_services' => $completedServices,
]);
exit;