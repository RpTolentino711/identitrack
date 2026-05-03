<?php
// admin/AJAX/get_pending_guard_reports.php
require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$reports = db_all(
    "SELECT r.report_id, r.student_id, r.description, r.created_at,
            CONCAT(COALESCE(s.student_fn,''), ' ', COALESCE(s.student_ln,'')) AS student_name,
            ot.name AS offense_name,
            g.full_name as guard_name,
            'VIOLATION' as item_type
     FROM guard_violation_report r
     LEFT JOIN student s ON r.student_id = s.student_id
     LEFT JOIN offense_type ot ON ot.offense_type_id = r.offense_type_id
     LEFT JOIN security_guard g ON r.submitted_by = g.guard_id
     WHERE r.status = 'PENDING' AND r.is_deleted = 0
     ORDER BY r.created_at DESC"
);

$admin = admin_current();
$adminId = (int)($admin['admin_id'] ?? $admin['id'] ?? 0);
$notifs = db_all(
    "SELECT notification_id, type, title, message, created_at, 'SYSTEM' as item_type
     FROM notification
     WHERE is_deleted = 0 AND is_read = 0
     AND type <> 'GUARD_REPORT'
     AND (admin_id IS NULL OR admin_id <> ?)
     ORDER BY created_at DESC",
    [$adminId]
);

$combined = array_merge($reports, $notifs);

echo json_encode([
    'ok' => true,
    'reports' => $combined
]);
exit;
