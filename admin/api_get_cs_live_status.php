<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../database/database.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized admin session.']);
    exit;
}

// 1. Get active sessions count and state hash
$activeSessions = db_all(
    "SELECT css.session_id, css.status AS session_status, csr.student_id, css.time_in
     FROM community_service_session css
     JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
     WHERE css.time_out IS NULL
     ORDER BY css.session_id DESC"
);

// 2. Get pending requests count
$pendingRow = db_one("SELECT COUNT(*) AS cnt FROM manual_login_request WHERE status='PENDING'");
$pendingCount = (int)($pendingRow['cnt'] ?? 0);

// 3. Get completed count today
$completedRow = db_one("SELECT COUNT(*) AS cnt FROM community_service_session WHERE time_out IS NOT NULL AND DATE(time_out) = CURDATE()");
$completedTodayCount = (int)($completedRow['cnt'] ?? 0);

// Build active session hash signature
$sessionSignatures = [];
foreach ($activeSessions as $s) {
    $sessionSignatures[] = $s['session_id'] . ':' . ($s['session_status'] ?? 'ACTIVE') . ':' . $s['student_id'];
}
$stateHash = md5(implode('|', $sessionSignatures) . '|p:' . $pendingCount . '|c:' . $completedTodayCount);

echo json_encode([
    'ok' => true,
    'active_count' => count($activeSessions),
    'pending_count' => $pendingCount,
    'completed_today_count' => $completedTodayCount,
    'state_hash' => $stateHash,
    'active_sessions' => array_map(function($s) {
        return [
            'session_id' => (int)$s['session_id'],
            'student_id' => (string)$s['student_id'],
            'status' => (string)($s['session_status'] ?? 'ACTIVE'),
        ];
    }, $activeSessions)
]);
