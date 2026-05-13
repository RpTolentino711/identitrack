<?php
declare(strict_types=1);

require_once __DIR__ . '/../../database/database.php';
ensure_hearing_workflow_schema();

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');
header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method not allowed']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($data)) {
        $data = [];
    }
    $studentId = trim((string)($data['student_id'] ?? ''));
    if ($studentId === '') {
        throw new Exception('Missing student_id');
    }

    require_student_api_auth($studentId);

    // Alerts are allowed even if account has restrictions

    $alerts = [];

    $decrypted_offense = db_decrypt_cols(['description', 'location', 'reason']);
    $params = [':sid' => $studentId];
    db_add_encryption_key($params);
    
    $offenses = db_all(
        "SELECT level, status, date_committed AS recorded_at, guardian_notified_at, $decrypted_offense
         FROM offense
         WHERE student_id = :sid
         ORDER BY date_committed DESC",
        $params
    );

    foreach ($offenses as $offense) {
        if (!empty($offense['guardian_notified_at'])) {
            $alerts[] = [
                'alert_type' => 'GUARDIAN_ALERT',
                'title' => 'Guardian Alert Sent',
                'message' => 'Your guardian has been officially notified about your recent offense record.',
                'created_at' => (string)$offense['guardian_notified_at'],
                'metadata' => ['level' => (string)($offense['level'] ?? '')],
            ];
        }
    }

    $recentOffenses = array_slice($offenses, 0, 3);
    foreach ($recentOffenses as $offense) {
        $levelLabel = ucfirst(strtolower((string)($offense['level'] ?? 'OFFENSE')));
        $alerts[] = [
            'alert_type' => 'OFFENSE_RECORDED',
            'title' => $levelLabel . ' Offense Recorded',
            'message' => 'An offense has been recorded on your account.',
            'created_at' => (string)($offense['recorded_at'] ?? date('Y-m-d H:i:s')),
            'metadata' => ['level' => (string)($offense['level'] ?? '')],
        ];
    }

    $csRecord = db_one(
        "SELECT assigned_at
         FROM community_service_requirement
         WHERE student_id = :sid
         ORDER BY assigned_at DESC
         LIMIT 1",
        [':sid' => $studentId]
    );

    if ($csRecord) {
        $alerts[] = [
            'alert_type' => 'UPCC_DECISION',
            'title' => 'UPCC Decision Made',
            'message' => 'Community service requirement has been assigned to your account.',
            'created_at' => (string)$csRecord['assigned_at'],
            'metadata' => [],
        ];
    }

    $latestCase = db_one(
        "SELECT case_id, decided_category, " . db_decrypt_cols(['final_decision', 'punishment_details']) . ", resolution_date
         FROM upcc_case
         WHERE student_id = :sid
           AND status IN ('CLOSED','RESOLVED')
           AND decided_category IS NOT NULL
         ORDER BY resolution_date DESC, case_id DESC
         LIMIT 1",
        [':sid' => $studentId, ':__enckey' => db_encryption_key()]
    );

    if ($latestCase) {
        $details = json_decode((string)($latestCase['punishment_details'] ?? ''), true);
        if (!is_array($details)) {
            $details = [];
        }

        $category = (int)($latestCase['decided_category'] ?? 0);
        $title = 'UPCC Case Decision: Category ' . $category;
        $summary = trim((string)($latestCase['final_decision'] ?? ''));
        if ($summary === '') {
            $summary = 'Your UPCC case has been finalized.';
        }

        if ($category === 1 && !empty($details['semester'])) {
            $summary .= ' Probation semester: ' . (string)$details['semester'] . '.';
        } elseif ($category === 2 && !empty($details['interventions'])) {
            $summary .= ' Interventions: ' . implode(', ', array_map('strval', (array)$details['interventions'])) . '.';
        }

        $alerts[] = [
            'alert_type' => 'UPCC_CASE_DECISION',
            'title' => $title,
            'message' => $summary,
            'created_at' => (string)($latestCase['resolution_date'] ?? date('Y-m-d H:i:s')),
            'metadata' => [
                'case_id' => (int)$latestCase['case_id'],
                'category' => $category,
                'details' => $details,
            ],
        ];
    }

    $latestAppeal = db_one(
        "SELECT appeal_id, appeal_kind, case_id, offense_id, status, created_at, decided_at, " . db_decrypt_col('admin_response') . " AS admin_response
         FROM student_appeal_request
         WHERE student_id = :sid
         ORDER BY created_at DESC, appeal_id DESC
         LIMIT 1",
        [':sid' => $studentId, ':__enckey' => db_encryption_key()]
    );

    if ($latestAppeal) {
        $kind = strtoupper((string)($latestAppeal['appeal_kind'] ?? 'OFFENSE'));
        $caseId = (int)($latestAppeal['case_id'] ?? 0);
        $offenseId = (int)($latestAppeal['offense_id'] ?? 0);
        $status = strtoupper((string)($latestAppeal['status'] ?? 'PENDING'));
        $baseCreatedAt = (string)($latestAppeal['created_at'] ?? date('Y-m-d H:i:s'));
        $titlePrefix = $kind === 'UPCC_CASE' ? 'UPCC Appeal' : 'Offense Appeal';

        if (in_array($status, ['PENDING', 'REVIEWING'], true)) {
            $alerts[] = [
                'alert_type' => 'APPEAL_SUBMITTED',
                'title' => $titlePrefix . ' Submitted',
                'message' => 'Your appeal is waiting for admin review.',
                'created_at' => $baseCreatedAt,
                'metadata' => [
                    'appeal_id' => (int)$latestAppeal['appeal_id'],
                    'case_id' => $caseId,
                    'offense_id' => $offenseId,
                    'status' => $status,
                    'appeal_kind' => $kind,
                ],
            ];
        } elseif (in_array($status, ['APPROVED', 'REJECTED'], true)) {
            $alerts[] = [
                'alert_type' => 'APPEAL_RESPONSE',
                'title' => $titlePrefix . ': ' . ucfirst(strtolower($status)),
                'message' => 'Your appeal has been ' . strtolower($status) . ' by UPCC/Admin.',
                'created_at' => (string)($latestAppeal['decided_at'] ?? $baseCreatedAt),
                'metadata' => [
                    'appeal_id' => (int)$latestAppeal['appeal_id'],
                    'case_id' => $caseId,
                    'offense_id' => $offenseId,
                    'status' => $status,
                    'appeal_kind' => $kind,
                    'admin_response' => (string)($latestAppeal['admin_response'] ?? ''),
                ],
            ];
        }
    }

    $hearings = db_all(
        "SELECT case_id, hearing_date, hearing_time, hearing_type, hearing_is_open, status
         FROM upcc_case
         WHERE student_id = :sid
           AND hearing_date IS NOT NULL
           AND status IN ('PENDING','UNDER_INVESTIGATION','UNDER_APPEAL')
         ORDER BY hearing_date ASC, hearing_time ASC",
        [':sid' => $studentId]
    );

    $today = date('Y-m-d');
    foreach ($hearings as $hearing) {
        $hearingDate = (string)($hearing['hearing_date'] ?? '');
        if ($hearingDate === '') {
            continue;
        }
        $hearingTime = (string)($hearing['hearing_time'] ?? '00:00:00');
        $hearingAt = $hearingDate . ' ' . $hearingTime;
        $hearingType = (string)($hearing['hearing_type'] ?? 'FACE_TO_FACE');
        $typeLabel = $hearingType === 'ONLINE' ? 'online' : 'face-to-face';

        $alerts[] = [
            'alert_type' => 'HEARING_SCHEDULE',
            'title' => 'Hearing Scheduled',
            'message' => 'Your hearing is scheduled on ' . date('M d, Y', strtotime($hearingDate)) . ' at ' . date('g:i A', strtotime($hearingTime)) . ' (' . $typeLabel . ').',
            'created_at' => $hearingAt,
            'metadata' => [
                'case_id' => (int)$hearing['case_id'],
                'hearing_type' => $hearingType,
                'hearing_date' => $hearingDate,
                'hearing_time' => $hearingTime,
                'popup' => true,
            ],
        ];

        if ($hearingDate === $today) {
            $alerts[] = [
                'alert_type' => 'HEARING_REMINDER',
                'title' => 'Hearing Reminder',
                'message' => 'Be ready for your ' . $typeLabel . ' hearing today. Wait for panel instructions.',
                'created_at' => date('Y-m-d H:i:s'),
                'metadata' => [
                    'case_id' => (int)$hearing['case_id'],
                    'hearing_type' => $hearingType,
                    'hearing_date' => $hearingDate,
                    'hearing_time' => $hearingTime,
                    'admin_opened' => (int)($hearing['hearing_is_open'] ?? 0) === 1,
                    'popup' => true,
                ],
            ];
        }
    }

    $activeSession = db_one(
        "SELECT session_id, time_in FROM community_service_session
         WHERE requirement_id IN (SELECT requirement_id FROM community_service_requirement WHERE student_id = :sid)
           AND time_out IS NULL",
        [':sid' => $studentId]
    );

    if ($activeSession) {
        $alerts[] = [
            'alert_type' => 'SERVICE_ACTIVE',
            'title' => 'Service Timer is Running',
            'message' => 'Your community service timer is currently active. Do not forget to log out when finished.',
            'created_at' => date('Y-m-d H:i:s'),
            'metadata' => ['session_id' => (int)$activeSession['session_id']],
        ];
    }

    $recentLogout = db_one(
        "SELECT session_id, time_out FROM community_service_session
         WHERE requirement_id IN (SELECT requirement_id FROM community_service_requirement WHERE student_id = :sid)
           AND time_out IS NOT NULL
           AND DATE(time_out) = CURDATE()
         ORDER BY time_out DESC LIMIT 1",
        [':sid' => $studentId]
    );

    if ($recentLogout) {
        $alerts[] = [
            'alert_type' => 'SERVICE_LOGGED_OUT',
            'title' => 'Service Session Logged Out',
            'message' => 'You have successfully logged out of your community service session today.',
            'created_at' => (string)$recentLogout['time_out'],
            'metadata' => ['session_id' => (int)$recentLogout['session_id']],
        ];
    }

    usort($alerts, static function (array $a, array $b): int {
        return strtotime((string)$b['created_at']) <=> strtotime((string)$a['created_at']);
    });

    echo json_encode([
        'ok' => true,
        'message' => 'Alerts retrieved successfully',
        'data' => $alerts,
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'ok' => false,
        'message' => $e->getMessage(),
    ]);
}
