<?php
require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$rows = db_all(
  "SELECT
     r.report_id,
     r.student_id,
     r.date_committed,
     r.created_at,
     ot.code AS offense_code,
     ot.name AS offense_name,
     ot.level AS offense_level,
     CONCAT(COALESCE(s.student_fn,''), ' ', COALESCE(s.student_ln,'')) AS student_name,
     sg.full_name AS guard_name
   FROM guard_violation_report r
   JOIN offense_type ot ON ot.offense_type_id = r.offense_type_id
   LEFT JOIN student s ON s.student_id = r.student_id
   LEFT JOIN security_guard sg ON sg.guard_id = r.submitted_by
   WHERE r.status = 'PENDING' AND r.is_deleted = 0
   ORDER BY r.created_at DESC
   LIMIT 20"
);

$payload = [];
foreach ((array)$rows as $r) {
  $payload[] = [
    'report_id' => (int)($r['report_id'] ?? 0),
    'student_id' => (string)($r['student_id'] ?? ''),
    'student_name' => trim((string)($r['student_name'] ?? '')),
    'guard_name' => (string)($r['guard_name'] ?? ''),
    'offense_code' => (string)($r['offense_code'] ?? ''),
    'offense_name' => (string)($r['offense_name'] ?? ''),
    'offense_level' => (string)($r['offense_level'] ?? ''),
    'date_committed_label' => !empty($r['date_committed']) ? date('M d, Y h:i A', strtotime((string)$r['date_committed'])) : '',
    'created_at_label' => !empty($r['created_at']) ? date('M d, Y h:i A', strtotime((string)$r['created_at'])) : '',
  ];
}

// Community Service Stats
$csRow = db_one("SELECT COUNT(*) AS cnt FROM manual_login_request WHERE status='PENDING'");
$pendingCsCount = (int)($csRow['cnt'] ?? 0);

$row = db_one(
  "SELECT COUNT(DISTINCT csr.student_id) AS cnt
   FROM community_service_session css
   JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
   WHERE css.time_out IS NULL"
);
$activeServiceCount = (int)($row['cnt'] ?? 0);

// Guard Reports Stats
$guardTotalRow = db_one("SELECT COUNT(*) AS cnt FROM guard_violation_report WHERE is_deleted=0");
$guardTotal = (int)($guardTotalRow['cnt'] ?? 0);

$guardApprovedRow = db_one("SELECT COUNT(*) AS cnt FROM guard_violation_report WHERE status='APPROVED' AND is_deleted=0");
$guardApproved = (int)($guardApprovedRow['cnt'] ?? 0);

$guardRejectedRow = db_one("SELECT COUNT(*) AS cnt FROM guard_violation_report WHERE status='REJECTED' AND is_deleted=0");
$guardRejected = (int)($guardRejectedRow['cnt'] ?? 0);

echo json_encode([
  'ok' => true,
  'pending_reports' => $payload,
  'guard_stats' => [
      'total' => $guardTotal,
      'approved' => $guardApproved,
      'rejected' => $guardRejected
  ],
  'community_service' => [
      'pending' => $pendingCsCount,
      'active' => $activeServiceCount
  ]
]);
exit;
