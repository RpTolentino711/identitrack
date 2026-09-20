<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/../database/database.php';

header('Content-Type: application/json; charset=utf-8');

$admin = admin_current();
if (!$admin) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => 'Unauthorized admin session. Please log in.']);
    exit;
}

$adminId = (int)$admin['admin_id'];
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true) ?: $_POST;

$action = trim((string)($body['action'] ?? ''));

if (!in_array($action, ['pause_all', 'clockout_all'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => 'Invalid action. Expected pause_all or clockout_all.']);
    exit;
}

ensure_community_service_pause_schema();

// Fetch all currently active sessions (where time_out is null)
$activeSessions = db_all(
    "SELECT css.session_id, css.requirement_id, css.time_in, css.status, csr.student_id, csr.related_case_id
     FROM community_service_session css
     JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
     WHERE css.time_out IS NULL
     ORDER BY css.session_id DESC"
);

if (empty($activeSessions)) {
    echo json_encode(['ok' => true, 'message' => 'No active sessions found.', 'processed_count' => 0]);
    exit;
}

$processedCount = 0;

if ($action === 'pause_all') {
    foreach ($activeSessions as $session) {
        $sid = (int)$session['session_id'];
        if ($session['status'] === 'PAUSED') {
            continue; // Already paused
        }

        db_exec(
            "UPDATE community_service_session 
             SET status = 'PAUSED', pause_reason = 'Paused automatically on Admin logout', paused_at = NOW() 
             WHERE session_id = :sid",
            [':sid' => $sid]
        );

        if (!empty($session['related_case_id'])) {
            upcc_log_case_activity((int)$session['related_case_id'], 'ADMIN', $adminId, 'COMMUNITY_SERVICE_PAUSED', [
                'session_id' => $sid,
                'paused_by_admin' => $adminId,
                'reason' => 'Paused automatically on Admin logout',
                'paused_at' => date('Y-m-d H:i:s')
            ]);
        }

        $processedCount++;
    }

    echo json_encode([
        'ok' => true,
        'message' => "Successfully paused {$processedCount} active community service session(s).",
        'processed_count' => $processedCount
    ]);
    exit;
}

if ($action === 'clockout_all') {
    foreach ($activeSessions as $session) {
        $sid = (int)$session['session_id'];
        $timeIn = !empty($session['time_in']) ? strtotime($session['time_in']) : time();
        $timeOut = time();
        $durationHours = max(0.01, round(($timeOut - $timeIn) / 3600.0, 2));

        db_exec(
            "UPDATE community_service_session 
             SET time_out = NOW(), duration_hours = :dur, status = 'COMPLETED', updated_at = NOW() 
             WHERE session_id = :sid",
            [':dur' => $durationHours, ':sid' => $sid]
        );

        if (!empty($session['related_case_id'])) {
            upcc_log_case_activity((int)$session['related_case_id'], 'ADMIN', $adminId, 'COMMUNITY_SERVICE_CLOCKED_OUT', [
                'session_id' => $sid,
                'duration_hours' => $durationHours,
                'clocked_out_by' => 'ADMIN_LOGOUT'
            ]);
        }

        $processedCount++;
    }

    echo json_encode([
        'ok' => true,
        'message' => "Successfully clocked out {$processedCount} active community service session(s).",
        'processed_count' => $processedCount
    ]);
    exit;
}
