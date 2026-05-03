<?php
// File: api/upcc_case_live.php
require_once __DIR__ . '/../database/database.php';
ensure_hearing_workflow_schema();
db_exec("CREATE TABLE IF NOT EXISTS upcc_suggestion_cooldown (
    cooldown_id BIGINT NOT NULL AUTO_INCREMENT,
    case_id BIGINT NOT NULL,
    round_no INT NOT NULL,
    upcc_id INT NOT NULL,
    cooldown_until DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (cooldown_id),
    KEY idx_case_round_upcc (case_id, round_no, upcc_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$hasPauseReason = db_one("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'upcc_case' AND COLUMN_NAME = 'hearing_pause_reason' LIMIT 1") !== null;
if (!$hasPauseReason) {
    db_exec("ALTER TABLE upcc_case ADD COLUMN hearing_pause_reason VARCHAR(50) DEFAULT NULL");
}

header('Content-Type: application/json; charset=utf-8');

$isAdmin = admin_current() !== null;
$isUpcc = upcc_current() !== null;

if (!$isAdmin && !$isUpcc) {
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'sync';
$requestedActor = strtolower(trim((string)($_POST['actor'] ?? $_GET['actor'] ?? '')));
$isRequestedAdmin = $requestedActor === 'admin';
$isRequestedUpcc = $requestedActor === 'upcc';
$hasExplicitActor = $isRequestedAdmin || $isRequestedUpcc;
$sessionPreferredActor = strtolower(trim((string)($_SESSION['upcc_live_actor'] ?? '')));
$hasSessionPreferredActor = in_array($sessionPreferredActor, ['admin', 'upcc'], true);

if ($hasExplicitActor) {
    $_SESSION['upcc_live_actor'] = $requestedActor;
}
$voteRoundHasSuggestedBy = db_one(
    "SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'upcc_case_vote_round'
       AND COLUMN_NAME = 'suggested_by'
     LIMIT 1"
) !== null;

// If both admin and UPCC sessions exist, prefer explicit actor, else fallback heuristic.
if ($isAdmin && $isUpcc) {
    if ($isRequestedAdmin) {
        $isUpcc = false;
    } elseif ($isRequestedUpcc) {
        $isAdmin = false;
    } elseif ($hasSessionPreferredActor) {
        if ($sessionPreferredActor === 'admin') {
            $isUpcc = false;
        } else {
            $isAdmin = false;
        }
    } else {
        $isFromAdminPage = strpos($_SERVER['HTTP_REFERER'] ?? '', 'admin/') !== false;
        $upccActions = ['request_rejoin', 'exit_hearing', 'vote', 'suggest_category', 'cast_vote'];
        if (in_array($action, $upccActions, true) || !$isFromAdminPage) {
            $isAdmin = false;
        } else {
            $isUpcc = false;
        }
    }
}

if ($isAdmin && !$isUpcc) {
    $_SESSION['upcc_live_actor'] = 'admin';
} elseif ($isUpcc && !$isAdmin) {
    $_SESSION['upcc_live_actor'] = 'upcc';
}

$user = $isAdmin ? admin_current() : upcc_current();
$actorId = (int)($isAdmin ? $user['admin_id'] : $user['upcc_id']);
$caseId = (int)($_GET['case_id'] ?? $_POST['case_id'] ?? 0);
if ($caseId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Invalid case']);
    exit;
}

// Check case access for UPCC users
if ($isUpcc && !$isAdmin) {
    $case = db_one("SELECT * FROM upcc_case WHERE case_id = :id", [':id' => $caseId]);
    if (!$case) {
        echo json_encode(['ok' => false, 'error' => 'Case not found']);
        exit;
    }
    $allowedWhenBlocked = ['request_rejoin', 'exit_hearing', 'ping_presence', 'sync'];
    if (!in_array($action, $allowedWhenBlocked, true)) {
        $block = upcc_staff_case_access_block_reason($case);
        if ($block) {
            echo json_encode(['ok' => false, 'error' => $block]);
            exit;
        }
    }
}

// ─────────────────────────────────────────────────────────────────────────
// POST MESSAGE ACTION
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'post_message' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $msg = trim((string)($_POST['message'] ?? ''));
    $replyTo = (int)($_POST['reply_to'] ?? 0);
    $replyTo = $replyTo > 0 ? $replyTo : null;

    if ($msg !== '') {
        $upcc_id = $isUpcc ? $actorId : null;
        $admin_id = $isAdmin ? $actorId : null;
        db_exec("INSERT INTO upcc_case_discussion (case_id, upcc_id, admin_id, reply_to_message_id, message, created_at, updated_at) 
                 VALUES (:c, :u, :a, :r, :m, NOW(), NOW())", [
            ':c' => $caseId, ':u' => $upcc_id, ':a' => $admin_id, ':r' => $replyTo, ':m' => $msg
        ]);
        upcc_log_case_activity($caseId, $isAdmin ? 'ADMIN' : 'UPCC', $actorId, 'CHAT_MESSAGE_POSTED', ['length' => mb_strlen($msg)]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// CANCEL SUGGESTION ACTION
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'cancel_suggestion' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $roundNo = (int)($_POST['round_no'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? 'canceled their suggestion'));

    if ($roundNo <= 0) {
        echo json_encode(['ok' => false, 'error' => 'Invalid round']); exit;
    }

    // Verify suggester when called by UPCC user
    $roundRow = db_one("SELECT suggested_by FROM upcc_case_vote_round WHERE case_id = :c AND round_no = :r LIMIT 1", [':c' => $caseId, ':r' => $roundNo]);
    $suggestedBy = (int)($roundRow['suggested_by'] ?? 0);
    if ($isUpcc && !$isAdmin) {
        if ($suggestedBy <= 0) {
            echo json_encode(['ok' => false, 'error' => 'No suggester for this round']); exit;
        }
        if ($suggestedBy !== $actorId) {
            echo json_encode(['ok' => false, 'error' => 'Only the suggester may cancel their suggestion']); exit;
        }
    }

    // Delete all votes for this round to reset the suggestion state
    db_exec("DELETE FROM upcc_case_vote WHERE case_id = :c AND round_no = :r", [':c' => $caseId, ':r' => $roundNo]);
    // Close the round if present
    db_exec("UPDATE upcc_case_vote_round SET is_active = 0 WHERE case_id = :c AND round_no = :r", [':c' => $caseId, ':r' => $roundNo]);

    db_exec("UPDATE upcc_case SET hearing_vote_consensus_category = NULL, hearing_vote_suggested_details = NULL, hearing_vote_consensus_at = NULL, hearing_vote_suggester_id = NULL WHERE case_id = :c", [':c' => $caseId]);
    upcc_log_case_activity($caseId, $isAdmin ? 'ADMIN' : 'UPCC', $actorId, 'VOTE_ROUND_RESET', ['round_no' => $roundNo]);

    // Announce the cancellation in chat
    $actorName = $user['full_name'] ?? ($isAdmin ? 'Admin' : 'Panel member');
    $sysMsg = "🔔 " . $actorName . " has " . $reason . " for this round. The panel may submit a new suggestion now.";
    db_exec("INSERT INTO upcc_case_discussion (case_id, message, created_at, updated_at) VALUES (:c, :m, NOW(), NOW())", [':c' => $caseId, ':m' => $sysMsg]);

    echo json_encode(['ok' => true]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// CAST VOTE ACTION (UPCC Panel Members)
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'cast_vote' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isUpcc) {
    $category = (int)($_POST['vote_category'] ?? 0);
    $voteDetails = [];
    
    if ($category === 1) {
        $cat1Semester = trim((string)($_POST['vote_cat1_semester'] ?? ''));
        if ($cat1Semester === 'OTHER') {
            $cat1Semester = trim((string)($_POST['vote_cat1_semester_custom'] ?? ''));
        }
        $voteDetails['semester'] = $cat1Semester;
        $voteDetails['probation_terms'] = 3;
        $voteDetails['suspend_if_violated'] = isset($_POST['vote_cat1_suspend_if_violated']);
    } elseif ($category === 2) {
        $interventions = [];
        if (isset($_POST['vote_cat2_university_service'])) {
            $interventions[] = 'University Service';
            $cat2Hours = trim((string)($_POST['vote_cat2_service_hours'] ?? ''));
            if ($cat2Hours === 'OTHER') {
                $cat2Hours = trim((string)($_POST['vote_cat2_service_hours_custom'] ?? ''));
            }
            $voteDetails['service_hours'] = $cat2Hours !== '' ? (int)$cat2Hours : 0;
        }
        if (isset($_POST['vote_cat2_counseling'])) $interventions[] = 'Referral for Counseling';
        if (isset($_POST['vote_cat2_lectures'])) $interventions[] = 'Attendance to lectures';
        $voteDetails['interventions'] = $interventions;
    }
    
    // Check for existing consensus
    $currentConsensus = db_one("SELECT hearing_vote_consensus_category FROM upcc_case WHERE case_id = :c", [':c' => $caseId]);
    if ((int)($currentConsensus['hearing_vote_consensus_category'] ?? 0) > 0) {
        echo json_encode(['ok' => false, 'error' => 'Consensus already reached']);
        exit;
    }
    
    // Get current active round
    $activeRound = db_one("SELECT round_no, is_active FROM upcc_case_vote_round WHERE case_id = :c AND is_active = 1 ORDER BY round_no DESC LIMIT 1", [':c' => $caseId]);
    if (!$activeRound || (int)$activeRound['is_active'] !== 1) {
        echo json_encode(['ok' => false, 'error' => 'No active voting round']);
        exit;
    }
    
    $roundNo = (int)$activeRound['round_no'];
    
    // Insert or update vote
    db_exec("INSERT INTO upcc_case_vote (case_id, upcc_id, round_no, vote_category, vote_details, created_at, updated_at)
             VALUES (:c, :u, :r, :cat, :details, NOW(), NOW())
             ON DUPLICATE KEY UPDATE vote_category = VALUES(vote_category), vote_details = VALUES(vote_details), updated_at = VALUES(updated_at)", [
        ':c' => $caseId,
        ':u' => $actorId,
        ':r' => $roundNo,
        ':cat' => $category,
        ':details' => !empty($voteDetails) ? json_encode($voteDetails) : null
    ]);
    
    upcc_log_case_activity($caseId, 'UPCC', $actorId, 'VOTE_SUBMITTED', ['round_no' => $roundNo, 'vote_category' => $category]);
    
    // Check for consensus
    checkAndUpdateConsensus($caseId, $roundNo);
    
    echo json_encode(['ok' => true]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// HELPERS: Consensus Detection
// ─────────────────────────────────────────────────────────────────────────
function checkAndUpdateConsensus($caseId, $roundNo) {
    $panelMembers = db_all("SELECT upcc_id FROM upcc_case_panel_member WHERE case_id = :c", [':c' => $caseId]);
    $totalMembers = count($panelMembers);
    if ($totalMembers === 0) return;

    $suggestedByRow = db_one(
        "SELECT suggested_by FROM upcc_case_vote_round WHERE case_id = :c AND round_no = :r AND is_active = 1 LIMIT 1",
        [':c' => $caseId, ':r' => $roundNo]
    );
    $suggesterId = (int)($suggestedByRow['suggested_by'] ?? 0);
    if ($suggesterId <= 0) {
        $fallbackSuggester = db_one(
            "SELECT upcc_id FROM upcc_case_vote
             WHERE case_id = :c AND round_no = :r AND vote_category > 0
             ORDER BY created_at ASC
             LIMIT 1",
            [':c' => $caseId, ':r' => $roundNo]
        );
        $suggesterId = (int)($fallbackSuggester['upcc_id'] ?? 0);
    }

    $voterIds = [];
    foreach ($panelMembers as $member) {
        $uid = (int)($member['upcc_id'] ?? 0);
        if ($uid > 0 && $uid !== $suggesterId) {
            $voterIds[] = $uid;
        }
    }

    $votes = db_all(
        "SELECT upcc_id, vote_category, vote_details FROM upcc_case_vote WHERE case_id = :c AND round_no = :r",
        [':c' => $caseId, ':r' => $roundNo]
    );

    $voteMap = [];
    $voteDetailsByCategory = [];
    $disagreeCount = 0;
    $disagreeName = '';

    foreach ($votes as $vote) {
        $uid = (int)($vote['upcc_id'] ?? 0);
        $cat = (int)($vote['vote_category'] ?? 0);
        $voteMap[$uid] = $cat;

        if ($uid !== $suggesterId && $cat <= 0) {
            $disagreeCount++;
            $row = db_one("SELECT full_name FROM upcc_user WHERE upcc_id = :u LIMIT 1", [':u' => $uid]);
            $disagreeName = $row['full_name'] ?? 'A panel member';
        }

        if ($cat > 0 && !isset($voteDetailsByCategory[$cat])) {
            $voteDetailsByCategory[$cat] = $vote['vote_details'];
        }
    }

    if ($disagreeCount > 0) {
        db_exec("DELETE FROM upcc_case_vote WHERE case_id = :c AND round_no = :r", [':c' => $caseId, ':r' => $roundNo]);
        db_exec("UPDATE upcc_case_vote_round SET is_active = 0 WHERE case_id = :c AND round_no = :r", [':c' => $caseId, ':r' => $roundNo]);
        db_exec("UPDATE upcc_case SET
                 hearing_vote_consensus_category = NULL,
                 hearing_vote_suggested_details = NULL,
                 hearing_vote_consensus_at = NULL,
                 hearing_vote_suggester_id = NULL,
                 status = CASE WHEN status = 'AWAITING_ADMIN_FINALIZATION' THEN 'UNDER_INVESTIGATION' ELSE status END,
                 updated_at = NOW()
                 WHERE case_id = :c", [':c' => $caseId]);

        db_exec(
            "INSERT INTO upcc_case_discussion (case_id, message, created_at, updated_at) VALUES (:c, :m, NOW(), NOW())",
            [':c' => $caseId, ':m' => "❌ {$disagreeName} voted DISAGREE — voting cancelled. Panel may submit a new suggestion now."]
        );

        upcc_log_case_activity($caseId, 'SYSTEM', 0, 'VOTE_DISAGREED', [
            'round_no' => $roundNo,
            'disagreed_by' => $disagreeName,
        ]);
        return;
    }

    $allVotersAgreed = true;
    foreach ($voterIds as $uid) {
        if (!isset($voteMap[$uid]) || (int)$voteMap[$uid] <= 0) {
            $allVotersAgreed = false;
            break;
        }
    }

    if ($allVotersAgreed && $suggesterId > 0) {
        $suggestionVote = db_one(
            "SELECT vote_category, vote_details FROM upcc_case_vote WHERE case_id = :c AND upcc_id = :u AND round_no = :r LIMIT 1",
            [':c' => $caseId, ':u' => $suggesterId, ':r' => $roundNo]
        );
        if (!$suggestionVote) return;

        $consensusCategory = (int)$suggestionVote['vote_category'];
        $detailsJson = $suggestionVote['vote_details'] ?? null;

        db_exec("UPDATE upcc_case SET
                 hearing_vote_consensus_category = :cat,
                 hearing_vote_suggested_details = :details,
                 hearing_vote_consensus_at = NOW(),
                 hearing_vote_suggester_id = :sid,
                 status = 'AWAITING_ADMIN_FINALIZATION',
                 updated_at = NOW()
                 WHERE case_id = :c", [
            ':cat' => $consensusCategory,
            ':details' => $detailsJson,
            ':sid' => $suggesterId,
            ':c' => $caseId,
        ]);

        db_exec("UPDATE upcc_case_vote_round SET is_active = 0 WHERE case_id = :c AND round_no = :r", [':c' => $caseId, ':r' => $roundNo]);

        db_exec(
            "INSERT INTO upcc_case_discussion (case_id, message, created_at, updated_at) VALUES (:c, :m, NOW(), NOW())",
            [':c' => $caseId, ':m' => "✅ CONSENSUS REACHED! All panel members agreed on Category {$consensusCategory}. Awaiting Admin to finalize."]
        );

        upcc_log_case_activity($caseId, 'SYSTEM', 0, 'CONSENSUS_REACHED', [
            'round_no' => $roundNo,
            'vote_category' => $consensusCategory,
            'total_voters' => count($voterIds),
        ]);
    }
}

// ─────────────────────────────────────────────────────────────────────────
// PING PRESENCE ACTION
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'ping_presence' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $userType = $isAdmin ? 'ADMIN' : 'UPCC';
    $status = $_POST['status'] ?? 'ADMITTED';
    
    if ($isUpcc) {
        $existing = db_one("SELECT status, last_ping FROM upcc_hearing_presence WHERE case_id=:c AND user_type='UPCC' AND user_id=:u", [':c' => $caseId, ':u' => $actorId]);
        if ($existing) {
            db_exec("UPDATE upcc_hearing_presence SET last_ping = NOW() WHERE case_id=:c AND user_type='UPCC' AND user_id=:u", [':c' => $caseId, ':u' => $actorId]);
        } else {
            // Changed from 'WAITING' to 'ADMITTED' to allow panel members to join without manual approval by default
            db_exec("INSERT INTO upcc_hearing_presence (case_id, user_type, user_id, status, last_ping) VALUES (:c, 'UPCC', :u, 'ADMITTED', NOW())", [':c' => $caseId, ':u' => $actorId]);
        }
    } else {
        db_exec("INSERT INTO upcc_hearing_presence (case_id, user_type, user_id, status, last_ping) 
                 VALUES (:c, :t, :u, :s1, NOW())
                 ON DUPLICATE KEY UPDATE status = :s2, last_ping = NOW()", [
            ':c' => $caseId, ':t' => $userType, ':u' => $actorId, ':s1' => $status, ':s2' => $status
        ]);
    }
    
    echo json_encode(['ok' => true]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// TOGGLE PAUSE ACTION (Admin only)
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'toggle_pause' && $isAdmin) {
    // Action disabled: Hearing pause/resume functionality has been removed.
    echo json_encode(['ok' => true, 'is_paused' => 0]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// ADMIT USER ACTION (Admin only)
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'admit_user' && $isAdmin) {
    $targetId = (int)($_POST['upcc_id'] ?? $_GET['upcc_id'] ?? 0);
    if ($targetId > 0) {
        db_exec("UPDATE upcc_hearing_presence SET status = 'ADMITTED' WHERE case_id = :c AND user_type = 'UPCC' AND user_id = :u", [':c' => $caseId, ':u' => $targetId]);
        upcc_log_case_activity($caseId, 'ADMIN', $actorId, 'ADMITTED_PANEL_MEMBER', ['admitted_upcc_id' => $targetId]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// REQUEST REJOIN ACTION (UPCC only)
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'request_rejoin' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isUpcc) {
    // Ensure table exists
    db_exec("CREATE TABLE IF NOT EXISTS upcc_panel_rejoin_requests (
        request_id BIGINT NOT NULL AUTO_INCREMENT,
        case_id BIGINT NOT NULL,
        upcc_id INT NOT NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (request_id),
        KEY idx_case_upcc (case_id, upcc_id),
        KEY idx_requested_at (requested_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Check cooldown (30 seconds)
    $latestRequest = db_one(
        "SELECT requested_at, TIMESTAMPDIFF(SECOND, requested_at, NOW()) AS elapsed_seconds
         FROM upcc_panel_rejoin_requests
         WHERE case_id = :c AND upcc_id = :u
         ORDER BY requested_at DESC LIMIT 1",
        [':c' => $caseId, ':u' => $actorId]
    );

    $canNotify = true;
    if ($latestRequest) {
        $elapsed = max(0, (int)($latestRequest['elapsed_seconds'] ?? 0));
        if ($elapsed < 30) {
            $canNotify = false;
        }
    }

    if ($canNotify) {
        db_exec("INSERT INTO upcc_panel_rejoin_requests (case_id, upcc_id, requested_at) VALUES (:c, :u, NOW())", [':c' => $caseId, ':u' => $actorId]);
        upcc_log_case_activity($caseId, 'UPCC', $actorId, 'REJOIN_REQUESTED');
    }

    // Set to WAITING so the Admin sees the request in the waiting room list
    db_exec("UPDATE upcc_hearing_presence SET status = 'WAITING', last_ping = NOW() WHERE case_id = :c AND user_type = 'UPCC' AND user_id = :u", [':c' => $caseId, ':u' => $actorId]);
    
    $presenceExists = db_one("SELECT 1 FROM upcc_hearing_presence WHERE case_id = :c AND user_type = 'UPCC' AND user_id = :u", [':c' => $caseId, ':u' => $actorId]);
    if (!$presenceExists) {
        db_exec("INSERT INTO upcc_hearing_presence (case_id, user_type, user_id, status, last_ping) VALUES (:c, 'UPCC', :u, 'WAITING', NOW())", [':c' => $caseId, ':u' => $actorId]);
    }

    echo json_encode(['ok' => true, 'message' => 'Rejoin request sent to admin']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// EXIT HEARING ACTION (UPCC only)
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'exit_hearing' && $_SERVER['REQUEST_METHOD'] === 'POST' && $isUpcc) {
    db_exec(
        "INSERT INTO upcc_hearing_presence (case_id, user_type, user_id, status, last_ping)
         VALUES (:c, 'UPCC', :u, 'EXITED', NOW())
         ON DUPLICATE KEY UPDATE status = 'EXITED', last_ping = NOW()",
        [':c' => $caseId, ':u' => $actorId]
    );
    upcc_log_case_activity($caseId, 'UPCC', $actorId, 'EXITED_HEARING');
    echo json_encode(['ok' => true, 'message' => 'You have exited the hearing and are now waiting for admin approval to rejoin']);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────
// SYNC ACTION - Main data fetch for both admin and panel
// ─────────────────────────────────────────────────────────────────────────
if ($action === 'sync') {
    $case = db_one("SELECT hearing_vote_consensus_category, hearing_vote_suggested_details, hearing_vote_consensus_at, 
                           hearing_is_paused, hearing_pause_reason, hearing_opened_by_admin, status,
                           student_explanation_text, student_explanation_image, student_explanation_pdf, student_explanation_at,
                           case_kind
                    FROM upcc_case WHERE case_id = :id", [':id' => $caseId]);
    
    $isHearingPaused = (int)($case['hearing_is_paused'] ?? 0) === 1;
    $pauseReason = $case['hearing_pause_reason'] ?? null;
    $isClosed = in_array($case['status'] ?? '', ['CLOSED', 'RESOLVED']);
    
    // Check admin presence for auto-pause/resume
    $adminPresence = db_one("SELECT last_ping FROM upcc_hearing_presence WHERE case_id = :c AND user_type = 'ADMIN'", [':c' => $caseId]);
    $isAdminOnline = false;
    if ($adminPresence) {
        $secondsAgo = time() - strtotime($adminPresence['last_ping']);
        if ($secondsAgo <= 15) {
            $isAdminOnline = true;
        }
    }

    // If the Admin is the one making the sync request, they are obviously online
    if ($isAdmin) {
        $isAdminOnline = true;
        // Implicitly update their presence ping so panel members know they are here
        db_exec("INSERT INTO upcc_hearing_presence (case_id, user_type, user_id, status, last_ping) 
                 VALUES (:c, 'ADMIN', :u, 'ADMITTED', NOW())
                 ON DUPLICATE KEY UPDATE last_ping = NOW()", [':c' => $caseId, ':u' => $actorId]);
    }
    
    $activeVoteRound = db_one("SELECT 1 FROM upcc_case_vote_round WHERE case_id = :c AND is_active = 1 LIMIT 1", [':c' => $caseId]);
    $isVotingOngoing = $activeVoteRound !== null;

    // Auto-pause logic removed to allow full manual control by Admin.

    
    // Get user presence status
    $myPresenceStatus = 'ADMITTED';
    if ($isUpcc) {
        $row = db_one("SELECT status FROM upcc_hearing_presence WHERE case_id = :c AND user_type = 'UPCC' AND user_id = :u", [':c' => $caseId, ':u' => $actorId]);
        $myPresenceStatus = $row ? $row['status'] : 'ADMITTED';
    }
    
    // Get waiting users (for admin)
    $waitingUsers = [];
    if ($isAdmin) {
        $waitingUsers = db_all("SELECT p.user_id as upcc_id, u.full_name as name
                                FROM upcc_hearing_presence p
                                JOIN upcc_user u ON u.upcc_id = p.user_id
                                WHERE p.case_id = :c AND p.user_type = 'UPCC' AND p.status = 'WAITING'", [':c' => $caseId]);
    }
    
    $latestRejoinRequestAt = null;
    if ($isAdmin) {
        $latestReqRow = db_one("SELECT MAX(requested_at) AS latest_requested_at FROM upcc_panel_rejoin_requests WHERE case_id = :c", [':c' => $caseId]);
        $latestRejoinRequestAt = $latestReqRow['latest_requested_at'] ?? null;
    }
    
    // Get current voting round
    if ($voteRoundHasSuggestedBy) {
        $voteRound = db_one("SELECT round_no, started_at, ends_at, is_active, suggested_by,
                                    TIMESTAMPDIFF(SECOND, NOW(), ends_at) as remaining_seconds
                             FROM upcc_case_vote_round WHERE case_id = :case_id
                             ORDER BY round_no DESC LIMIT 1", [':case_id' => $caseId]);
    } else {
        $voteRound = db_one("SELECT round_no, started_at, ends_at, is_active,
                                    (SELECT v.upcc_id FROM upcc_case_vote v WHERE v.case_id = upcc_case_vote_round.case_id AND v.round_no = upcc_case_vote_round.round_no AND v.vote_category > 0 ORDER BY v.created_at ASC LIMIT 1) AS suggested_by,
                                    TIMESTAMPDIFF(SECOND, NOW(), ends_at) as remaining_seconds
                             FROM upcc_case_vote_round WHERE case_id = :case_id
                             ORDER BY round_no DESC LIMIT 1", [':case_id' => $caseId]);
    }
    
    // Auto-close expired round (10-minute window)
    if ($voteRound && !$isClosed) {
        $isRoundExpired = (int)($voteRound['remaining_seconds'] ?? 0) <= 0;
        if ($isRoundExpired && (int)($voteRound['is_active'] ?? 0) === 1 && (int)($case['hearing_vote_consensus_category'] ?? 0) === 0) {
            $oldRound = (int)$voteRound['round_no'];
            db_exec("UPDATE upcc_case_vote_round SET is_active = 0 WHERE case_id = :c AND round_no = :old_r", [':c' => $caseId, ':old_r' => $oldRound]);
            db_exec("DELETE FROM upcc_case_vote WHERE case_id = :c AND round_no = :r", [':c' => $caseId, ':r' => $oldRound]);
            db_exec("UPDATE upcc_case 
                     SET hearing_vote_consensus_category = NULL, 
                         hearing_vote_suggested_details = NULL, 
                         hearing_vote_consensus_at = NULL, 
                         hearing_vote_suggester_id = NULL,
                         updated_at = NOW()
                     WHERE case_id = :c", [':c' => $caseId]);
            db_exec("INSERT INTO upcc_case_discussion (case_id, message, created_at, updated_at)
                     VALUES (:c, :m, NOW(), NOW())", [
                ':c' => $caseId,
                ':m' => "⌛ Voting window ended after 10 minutes with no decision. Panel may submit a new suggestion."
            ]);
            if ($voteRoundHasSuggestedBy) {
                $voteRound = db_one("SELECT round_no, started_at, ends_at, is_active, suggested_by,
                                            TIMESTAMPDIFF(SECOND, NOW(), ends_at) as remaining_seconds
                                     FROM upcc_case_vote_round WHERE case_id = :case_id
                                     ORDER BY round_no DESC LIMIT 1", [':case_id' => $caseId]);
            } else {
                $voteRound = db_one("SELECT round_no, started_at, ends_at, is_active,
                                            (SELECT v.upcc_id FROM upcc_case_vote v WHERE v.case_id = upcc_case_vote_round.case_id AND v.round_no = upcc_case_vote_round.round_no AND v.vote_category > 0 ORDER BY v.created_at ASC LIMIT 1) AS suggested_by,
                                            TIMESTAMPDIFF(SECOND, NOW(), ends_at) as remaining_seconds
                                     FROM upcc_case_vote_round WHERE case_id = :case_id
                                     ORDER BY round_no DESC LIMIT 1", [':case_id' => $caseId]);
            }
        }
    }
    
    $roundNo = (int)($voteRound['round_no'] ?? 1);
    if ($voteRound && !$voteRoundHasSuggestedBy) {
        $fallbackSuggester = db_one(
            "SELECT upcc_id FROM upcc_case_vote
             WHERE case_id = :c AND round_no = :r AND vote_category > 0
             ORDER BY created_at ASC
             LIMIT 1",
            [':c' => $caseId, ':r' => $roundNo]
        );
        $voteRound['suggested_by'] = (int)($fallbackSuggester['upcc_id'] ?? 0);
    }
    
    // Get votes for current round
    $votes = db_all("SELECT v.upcc_id, v.vote_category, v.vote_details, v.updated_at, u.full_name 
                     FROM upcc_case_vote v
                     LEFT JOIN upcc_user u ON u.upcc_id = v.upcc_id
                     WHERE v.case_id = :c AND v.round_no = :r
                     ORDER BY v.updated_at DESC", [':c' => $caseId, ':r' => $roundNo]);
    
    foreach ($votes as &$voteRow) {
        try {
            $voteRow['vote_details'] = json_decode((string)($voteRow['vote_details'] ?? ''), true) ?: new stdClass();
        } catch (Throwable $e) {
            $voteRow['vote_details'] = new stdClass();
        }
    }
    unset($voteRow);
    
    // Get chat messages
    $discussions = db_all("SELECT d.message_id, d.message, d.created_at, d.upcc_id, d.admin_id, d.reply_to_message_id,
                   IF(d.upcc_id IS NULL AND d.admin_id IS NULL, 'System Notification', COALESCE(u.full_name, a.full_name)) AS sender_name,
                   IF(d.upcc_id IS NULL AND d.admin_id IS NULL, 'System', COALESCE(u.role, 'Admin')) AS sender_role,
                   IF(d.upcc_id IS NULL AND d.admin_id IS NULL, 1, 0) AS is_system,
                   IF(d.admin_id IS NOT NULL, 1, 0) AS is_admin,
                   (SELECT d2.message FROM upcc_case_discussion d2 WHERE d2.message_id = d.reply_to_message_id LIMIT 1) as reply_message,
                   (SELECT COALESCE(u2.full_name, a2.full_name) FROM upcc_case_discussion d2 LEFT JOIN upcc_user u2 ON u2.upcc_id = d2.upcc_id LEFT JOIN admin_user a2 ON a2.admin_id = d2.admin_id WHERE d2.message_id = d.reply_to_message_id LIMIT 1) as reply_sender
            FROM upcc_case_discussion d
            LEFT JOIN upcc_user u ON u.upcc_id = d.upcc_id
            LEFT JOIN admin_user a ON a.admin_id = d.admin_id
            WHERE d.case_id = :c
            ORDER BY d.created_at ASC", [':c' => $caseId]);
    
    $chat_messages = [];
    foreach ($discussions as $d) {
        $isMe = false;
        if ($isAdmin && (int)$d['admin_id'] === $actorId) $isMe = true;
        if ($isUpcc && (int)$d['upcc_id'] === $actorId) $isMe = true;
        
        $chat_messages[] = [
            'id' => $d['message_id'],
            'sender_name' => $d['sender_name'],
            'sender_role' => $d['sender_role'],
            'is_admin' => (int)$d['is_admin'],
            'is_system' => (int)$d['is_system'],
            'message' => $d['message'],
            'created_at' => date('M j, Y g:i A', strtotime($d['created_at'])),
            'reply_to_id' => $d['reply_to_message_id'],
            'reply_message' => mb_strimwidth((string)$d['reply_message'], 0, 50, "..."),
            'reply_sender' => $d['reply_sender'],
            'is_me' => $isMe
        ];
    }
    
    // Get total panel members
    $assignedPanelIds = array_map(
        static fn($row) => (int)$row['upcc_id'],
        db_all("SELECT upcc_id FROM upcc_case_panel_member WHERE case_id = :id", [':id' => $caseId])
    );
    if (empty($assignedPanelIds)) {
        $c = db_one("SELECT assigned_panel_members FROM upcc_case WHERE case_id = :id", [':id' => $caseId]);
        try {
            $assignedPanelIds = json_decode((string)$c['assigned_panel_members'], true) ?? [];
        } catch (Exception $e) {
        }
    }
    $totalMembers = count(array_unique(array_filter(array_map('intval', $assignedPanelIds))));

    // Cooldown status used by panel/admin frontends
    $cooldownRow = db_one(
        "SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(cooldown_until)) AS remaining
         FROM upcc_suggestion_cooldown
         WHERE case_id = :c AND cooldown_until > NOW()",
        [':c' => $caseId]
    );
    $cooldownSeconds = max(0, (int)($cooldownRow['remaining'] ?? 0));
    
    // Parse suggested details
    $suggestionDetails = null;
    $consensusCategory = (int)($case['hearing_vote_consensus_category'] ?? 0);
    if ($consensusCategory > 0 && !empty($case['hearing_vote_suggested_details'])) {
        try {
            $suggestionDetails = json_decode($case['hearing_vote_suggested_details'], true);
        } catch (Throwable $e) {
            $suggestionDetails = null;
        }
    }
    
    echo json_encode([
        'ok' => true,
        'round' => $voteRound,
        'consensus' => $consensusCategory,
        'suggestion_details' => $suggestionDetails,
        'total_members' => $totalMembers,
        'votes' => $votes,
        'chat' => $chat_messages,
        'is_paused' => false,
        'is_closed' => $isClosed,
        'case_status' => (string)($case['status'] ?? ''),
        'my_status' => $myPresenceStatus,
        'waiting_users' => $waitingUsers,
        'latest_rejoin_request_at' => $latestRejoinRequestAt,
        'cooldown' => $cooldownSeconds > 0,
        'cooldown_seconds' => $cooldownSeconds,
        'student_explanation' => [
            'text' => $case['student_explanation_text'] ?? null,
            'image' => $case['student_explanation_image'] ?? null,
            'pdf' => $case['student_explanation_pdf'] ?? null,
            'submitted_at' => $case['student_explanation_at'] ?? null,
        ],
        'case_kind' => $case['case_kind'] ?? '',
        'current_time' => time()
    ]);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Invalid action']);
exit;