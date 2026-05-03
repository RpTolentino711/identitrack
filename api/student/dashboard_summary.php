<?php
declare(strict_types=1);

// TEMP DEBUG (remove after working)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../database/database.php';
ensure_hearing_workflow_schema();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function json_out(bool $ok, string $message = '', $data = null, int $status = 200): void {
  http_response_code($status);
  echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data]);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(false, 'Method not allowed.', null, 405);
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

$studentId = trim((string)($body['student_id'] ?? ''));
if ($studentId === '') json_out(false, 'student_id is required.', null, 400);

// Confirm student exists
$student = db_one(
  "SELECT student_id, student_fn, student_ln, is_active
   FROM student
   WHERE student_id = :sid
   LIMIT 1",
  [':sid' => $studentId]
);

if (!$student) json_out(false, 'Student not found.', null, 404);

// Determine account mode and message
$accountPolicy = student_account_mode($studentId);
$accountMode = (string)($accountPolicy['mode'] ?? 'FULL_ACCESS');
$accountMessage = (string)($accountPolicy['message'] ?? 'Account access is normal.');

if ((int)$student['is_active'] !== 1) {
    // If account is frozen, we ONLY allow access if it's a disciplinary freeze (Cat 4/5)
    // to allow the student to see their notice. Otherwise, block with 403.
    if (!in_array($accountMode, ['WARNING_FREEZE_LOGOUT_ONLY', 'AUTO_LOGOUT_FREEZE'], true)) {
        json_out(false, 'Student is not active.', null, 403);
    }
}

$studentName = trim(((string)$student['student_fn'] . ' ' . (string)$student['student_ln']));

// Total Offenses: Count EVERY non-VOID offense recorded for this student
$totalRow = db_one(
  "SELECT COUNT(*) AS cnt FROM offense WHERE student_id = :sid AND status <> 'VOID'",
  [':sid' => $studentId]
);
$total = (int)($totalRow['cnt'] ?? 0);

// Minor/Major Offenses: Show cumulative counts of all recorded violations
$displayRow = db_one(
  "SELECT
      SUM(CASE WHEN level = 'MINOR' THEN 1 ELSE 0 END) AS minor_offense,
      SUM(CASE WHEN level = 'MAJOR' THEN 1 ELSE 0 END) AS major_offense
   FROM offense
   WHERE student_id = :sid
     AND status <> 'VOID'
",
  [':sid' => $studentId]
);

$minor = (int)($displayRow['minor_offense'] ?? 0);
$major = (int)($displayRow['major_offense'] ?? 0);

$unseenRow = db_one(
  "SELECT COUNT(*) AS c FROM offense WHERE student_id = :sid AND status <> 'VOID' AND acknowledged_at IS NULL",
  [':sid' => $studentId]
);
$unseenOffensesCount = (int)($unseenRow['c'] ?? 0);

// 3 Minors = 1 Major (Section 4 conversion)
// BUT: only convert minors that ARE NOT already part of a UPCC case
$unhandledMinorsCount = db_one(
  "SELECT COUNT(*) as c FROM offense 
   WHERE student_id = :sid 
     AND status <> 'VOID' 
     AND level = 'MINOR'
     AND offense_id NOT IN (SELECT offense_id FROM upcc_case_offense)",
  [':sid' => $studentId]
);
$unhandledMinors = (int)($unhandledMinorsCount['c'] ?? 0);
$major += (int)floor($unhandledMinors / 3);

// Include Section 4 escalations as Major Offenses (these don't have a 'MAJOR' record in the offense table)
$section4Count = db_one(
  "SELECT COUNT(*) AS c FROM upcc_case 
   WHERE student_id = :sid 
     AND case_kind = 'SECTION4_MINOR_ESCALATION'
     AND status <> 'VOID'", 
  [':sid' => $studentId]
);
$major += (int)($section4Count['c'] ?? 0);

// Note: total reflects all individual offense records; we don't add the case count here to avoid double-counting.

// Community service hours (ACTIVE requirements minus completed hours)
$csr = db_one(
  "SELECT COALESCE(SUM(hours_required), 0) AS hours_required
   FROM community_service_requirement
   WHERE student_id = :sid
     AND status = 'ACTIVE'",
  [':sid' => $studentId]
);

$done = db_one(
  "SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, time_in, time_out)/3600), 0) AS hours_done
   FROM community_service_session
   WHERE requirement_id IN (
       SELECT requirement_id FROM community_service_requirement 
       WHERE student_id = :sid AND status = 'ACTIVE'
   )
   AND time_out IS NOT NULL",
  [':sid' => $studentId]
);

$communityHours = max(0, (float)($csr['hours_required'] ?? 0) - (float)($done['hours_done'] ?? 0));

// (Account policy already calculated at top)

$activeCaseRow = db_one(
  "SELECT case_id, hearing_date, hearing_time, hearing_type, hearing_is_open, status,
          hearing_vote_consensus_category, hearing_vote_suggested_details,
          student_explanation_at, case_kind
   FROM upcc_case
   WHERE student_id = :sid
     AND status IN ('PENDING','UNDER_INVESTIGATION','AWAITING_ADMIN_FINALIZATION')
   ORDER BY case_id DESC
   LIMIT 1",
  [':sid' => $studentId]
);

$hearingNotice = null;
if ($activeCaseRow) {
  $hDate = (string)($activeCaseRow['hearing_date'] ?? '');
  $hTime = (string)($activeCaseRow['hearing_time'] ?? '00:00:00');
  $hType = (string)($activeCaseRow['hearing_type'] ?? 'FACE_TO_FACE');
  $today = date('Y-m-d');
  $typeLabel = $hType === 'ONLINE' ? 'online' : 'face-to-face';
  
  $status = (string)$activeCaseRow['status'];
  $consensusCat = (int)($activeCaseRow['hearing_vote_consensus_category'] ?? 0);
  
  if ($consensusCat > 0 && $status === 'AWAITING_ADMIN_FINALIZATION') {
    $hearingNotice = [
      'case_id' => (int)$activeCaseRow['case_id'],
      'hearing_date' => $hDate,
      'hearing_time' => $hTime,
      'hearing_type' => $hType,
      'title' => 'UPCC Panel Consensus Reached',
      'message' => 'The panel has reached a consensus and is suggesting a Category ' . $consensusCat . ' punishment. Please wait for the Administration to finalize the decision. Once finalized, you will be able to accept or appeal the punishment here.',
      'popup' => false,
      'admin_opened' => true,
    ];
  } else if ($status === 'UNDER_INVESTIGATION' && (int)($activeCaseRow['hearing_is_open'] ?? 0) === 1) {
    $hearingNotice = [
      'case_id' => (int)$activeCaseRow['case_id'],
      'hearing_date' => $hDate,
      'hearing_time' => $hTime,
      'hearing_type' => $hType,
      'title' => 'Live UPCC Hearing',
      'message' => 'Your case is currently live. The UPCC panel is actively discussing and voting on a suggested punishment. Please stand by.',
      'popup' => false,
      'admin_opened' => true,
    ];
  } else if ($hDate !== '') {
    $hearingNotice = [
      'case_id' => (int)$activeCaseRow['case_id'],
      'hearing_date' => $hDate,
      'hearing_time' => $hTime,
      'hearing_type' => $hType,
      'title' => $hDate === $today ? 'Hearing Reminder' : 'Upcoming Hearing',
      'message' => $hDate === $today
        ? 'Be ready for your ' . $typeLabel . ' hearing today at ' . date('g:i A', strtotime($hTime)) . '.'
        : 'Your hearing is scheduled on ' . date('M d, Y', strtotime($hDate)) . ' at ' . date('g:i A', strtotime($hTime)) . ' (' . $typeLabel . ').',
      'popup' => false,
      'admin_opened' => (int)($activeCaseRow['hearing_is_open'] ?? 0) === 1,
    ];
  } else {
    // Active case but no hearing date yet
    $needsExplanation = in_array($activeCaseRow['case_kind'], ['MAJOR_OFFENSE', 'SECTION4_MINOR_ESCALATION']);
    $hasExplanation = !empty($activeCaseRow['student_explanation_at']);
    
    // IF it needs explanation and they already submitted, HIDE the "Active UPCC Case" warning
    if ($needsExplanation && $hasExplanation) {
      $hearingNotice = null;
    } else {
      $hearingNotice = [
        'case_id' => (int)$activeCaseRow['case_id'],
        'hearing_date' => '',
        'hearing_time' => '',
        'hearing_type' => '',
        'title' => 'Active UPCC Case',
        'message' => 'You currently have an active UPCC investigation. A hearing schedule will be finalized soon.',
        'popup' => false,
        'admin_opened' => false,
      ];
    }
  }
  
  // Attach has_explanation flag to the notice if it exists
  if ($hearingNotice) {
      $hearingNotice['has_explanation'] = !empty($activeCaseRow['student_explanation_at']);
  }
}

$closedCase = db_one(
  "SELECT case_id, status as case_status, decided_category, final_decision, punishment_details, resolution_date
   FROM upcc_case
   WHERE student_id = :sid
     AND status IN ('CLOSED', 'RESOLVED')
     AND decided_category IS NOT NULL
   ORDER BY resolution_date DESC, case_id DESC
   LIMIT 1",
  [':sid' => $studentId]
);

$latestAppeal = null;
if ($closedCase) {
  $latestAppeal = db_one(
    "SELECT appeal_id, status, created_at
     FROM student_appeal_request
     WHERE student_id = :sid
       AND case_id = :cid
       AND appeal_kind = 'UPCC_CASE'
     ORDER BY created_at DESC, appeal_id DESC
     LIMIT 1",
    [':sid' => $studentId, ':cid' => (int)$closedCase['case_id']]
  );
}

$latestPunishment = null;
if ($closedCase) {
  $resolutionAt = strtotime((string)($closedCase['resolution_date'] ?? ''));
  $appealWindowOpen = $resolutionAt > 0 && (time() - $resolutionAt) <= (5 * 86400);
  $hasActiveAppeal = $latestAppeal && in_array((string)($latestAppeal['status'] ?? ''), ['PENDING', 'REVIEWING'], true);
  
  // If the case is already RESOLVED, student has accepted — no appeal possible.
  $isResolved = $closedCase['case_status'] === 'RESOLVED';

  // If the student has an ACTIVE or COMPLETED community service requirement
  // linked to this case, they have already accepted the Category 2 decision.
  $hasAcceptedViaService = false;
  if ((int)$closedCase['decided_category'] === 2) {
    $csrRow = db_row(
      "SELECT requirement_id FROM community_service_requirement
       WHERE student_id = :sid AND case_id = :cid
         AND status IN ('ACTIVE', 'COMPLETED')
       LIMIT 1",
      [':sid' => $studentId, ':cid' => (int)$closedCase['case_id']]
    );
    $hasAcceptedViaService = !empty($csrRow);
  }

  $latestPunishment = [
    'case_id' => (int)$closedCase['case_id'],
    'category' => (int)$closedCase['decided_category'],
    'decision_text' => (string)$closedCase['final_decision'],
    'details' => json_decode((string)$closedCase['punishment_details'], true) ?: [],
    'resolved_at' => (string)$closedCase['resolution_date'],
    'can_appeal' => !$isResolved && !$hasAcceptedViaService && $appealWindowOpen && !$hasActiveAppeal,
    'appeal_status' => $latestAppeal ? (string)$latestAppeal['status'] : '',
  ];
}

$unseenAppeals = db_all(
  "SELECT 
     sar.appeal_id,
     sar.appeal_kind,
     sar.status,
     sar.admin_response,
     sar.case_id,
     sar.offense_id,
     c.decided_category
   FROM student_appeal_request sar
   LEFT JOIN upcc_case c ON c.case_id = sar.case_id
   WHERE sar.student_id = :sid 
     AND sar.status IN ('APPROVED', 'REJECTED') 
     AND sar.is_seen_by_student = 0
   ORDER BY sar.decided_at DESC",
  [':sid' => $studentId]
);

$activeSession = db_one(
  "SELECT session_id FROM community_service_session
   WHERE requirement_id IN (SELECT requirement_id FROM community_service_requirement WHERE student_id = :sid)
     AND time_out IS NULL",
  [':sid' => $studentId]
);

$recentLogout = db_one(
  "SELECT session_id FROM community_service_session
   WHERE requirement_id IN (SELECT requirement_id FROM community_service_requirement WHERE student_id = :sid)
     AND time_out IS NOT NULL
     AND DATE(time_out) = CURDATE()
   ORDER BY time_out DESC LIMIT 1",
  [':sid' => $studentId]
);

// Calculate total_alerts_count
$totalAlertsCount = 0;
$guardianNotifiedRow = db_one("SELECT COUNT(*) as c FROM offense WHERE student_id = :sid AND guardian_notified_at IS NOT NULL", [':sid' => $studentId]);
$totalAlertsCount += (int)($guardianNotifiedRow['c'] ?? 0); // GUARDIAN_ALERT
$totalAlertsCount += min(3, $total); // OFFENSE_RECORDED
if ($csr && ((float)($csr['hours_required'] ?? 0) > 0)) $totalAlertsCount++; // UPCC_DECISION
if ($latestPunishment) $totalAlertsCount++; // UPCC_CASE_DECISION
if ($latestAppeal) $totalAlertsCount++; // APPEAL_SUBMITTED/RESPONSE

$hearings = db_all(
    "SELECT hearing_date FROM upcc_case
     WHERE student_id = :sid
       AND hearing_date IS NOT NULL
       AND status IN ('PENDING','UNDER_INVESTIGATION','UNDER_APPEAL')",
    [':sid' => $studentId]
);
foreach ($hearings as $h) {
    if (!empty($h['hearing_date'])) {
        $totalAlertsCount++; // HEARING_SCHEDULE
        if ($h['hearing_date'] === date('Y-m-d')) {
            $totalAlertsCount++; // HEARING_REMINDER
        }
    }
}
if ($activeSession) $totalAlertsCount++; // SERVICE_ACTIVE
if ($recentLogout) $totalAlertsCount++; // SERVICE_LOGGED_OUT

json_out(true, 'Dashboard summary loaded.', [
  'student_id' => $studentId,
  'student_name' => $studentName,
  'total_offense' => $total,
  'minor_offense' => $minor,
  'major_offense' => $major,
  'unseen_offenses_count' => $unseenOffensesCount,
  'total_alerts_count' => $totalAlertsCount,
  'community_service_hours' => $communityHours,
  'account_mode' => $accountMode,
  'account_message' => $accountMessage,
  'hearing_notice' => $hearingNotice,
  'latest_punishment' => $latestPunishment,
  'unseen_appeals' => $unseenAppeals,
  'active_service_session' => $activeSession ? true : false,
  'recent_service_logout' => $recentLogout ? true : false,
  'active_service_session_id' => $activeSession,
  'recent_service_logout_id' => $recentLogout,
]);