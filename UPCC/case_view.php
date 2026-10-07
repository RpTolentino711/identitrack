<?php
session_start();
require_once __DIR__ . '/../database/database.php';
ensure_hearing_workflow_schema();

if (!isset($_SESSION['upcc_authenticated']) || !upcc_current()) {
    header('Location: upccpanel.php');
    exit;
}

$voteFlash = $_SESSION['upcc_vote_flash'] ?? null;
unset($_SESSION['upcc_vote_flash']);

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$user    = upcc_current();
$panelId = (int)($user['upcc_id'] ?? 0);
$caseId  = (int)($_GET['id'] ?? 0);
if ($caseId <= 0 || $panelId <= 0) {
    header('Location: upccdashboard.php');
    exit;
}

$sessionSuggesterCaseId = (int)($_SESSION['upcc_last_suggester_case_id'] ?? 0);
$sessionSuggesterId     = (int)($_SESSION['upcc_last_suggester_id'] ?? 0);

// ── SCHEMA CHECKS ─────────────────────────────────────────────────────────
$voteRoundHasSuggestedBy = db_one(
    "SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'upcc_case_vote_round'
       AND COLUMN_NAME  = 'suggested_by' LIMIT 1"
) !== null;

$voteRoundHasEndedAt = db_one(
    "SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME   = 'upcc_case_vote_round'
       AND COLUMN_NAME  = 'ended_at' LIMIT 1"
) !== null;

// ── SCHEMA MIGRATIONS ─────────────────────────────────────────────────────
try {
    db_exec("CREATE TABLE IF NOT EXISTS upcc_case_panel_acceptance (
        acceptance_id BIGINT NOT NULL AUTO_INCREMENT,
        case_id       BIGINT NOT NULL,
        upcc_id       INT    NOT NULL,
        accepted_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (acceptance_id),
        UNIQUE KEY uq_case_panel (case_id, upcc_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_exec("CREATE TABLE IF NOT EXISTS upcc_panel_rejoin_requests (
        request_id   BIGINT NOT NULL AUTO_INCREMENT,
        case_id      BIGINT NOT NULL,
        upcc_id      INT    NOT NULL,
        requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (request_id),
        KEY idx_case_upcc   (case_id, upcc_id),
        KEY idx_requested_at (requested_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_exec("CREATE TABLE IF NOT EXISTS upcc_suggestion_cooldown (
        cooldown_id     BIGINT NOT NULL AUTO_INCREMENT,
        case_id         BIGINT NOT NULL,
        round_no        INT    NOT NULL,
        upcc_id         INT    NOT NULL,
        cooldown_until  DATETIME NOT NULL,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (cooldown_id),
        KEY idx_case_round_upcc (case_id, round_no, upcc_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Exception $e) {
    error_log('UPCC case page migration failed: ' . $e->getMessage());
}

// ── LOAD CASE ─────────────────────────────────────────────────────────────
$legacyPanelMatch = "FIND_IN_SET(:legacy_uid, REPLACE(REPLACE(REPLACE(COALESCE(uc.assigned_panel_members,''),'[',''),']',''),' ','')) > 0";
$case = db_one("SELECT uc.*, " . db_decrypt_cols(['case_summary', 'student_explanation_text'], 'uc') . ",
        CONCAT(" . db_decrypt_col('student_fn', 's') . ",' '," . db_decrypt_col('student_ln', 's') . ") AS student_name,
        " . db_decrypt_cols(['student_fn', 'student_ln', 'student_email', 'phone_number', 'home_address'], 's') . ",
        s.year_level, s.section, s.program, s.school,
        d.dept_name AS assigned_dept_name
    FROM upcc_case uc
    JOIN student s ON s.student_id = uc.student_id
    LEFT JOIN departments d ON d.dept_id = uc.assigned_department_id
    WHERE uc.case_id = :case_id
      AND (
        EXISTS (SELECT 1 FROM upcc_case_panel_member ucpm WHERE ucpm.case_id = uc.case_id AND ucpm.upcc_id = :join_uid)
        OR $legacyPanelMatch
      )
    LIMIT 1",
    [':case_id' => $caseId, ':join_uid' => $panelId, ':legacy_uid' => $panelId, ':__enckey' => db_encryption_key()]
);

if (!$case) {
    header('Location: upccdashboard.php');
    exit;
}

$accessBlockReason = upcc_staff_case_access_block_reason($case);
if ($accessBlockReason !== null) {
    header('Location: upccdashboard.php?hearing_msg=' . urlencode($accessBlockReason));
    exit;
}

// ── ASSIGNED PANEL IDS ────────────────────────────────────────────────────
$assignedPanelIds = array_map(
    static fn($r) => (int)$r['upcc_id'],
    db_all("SELECT upcc_id FROM upcc_case_panel_member WHERE case_id = :id", [':id' => $caseId])
);
if (empty($assignedPanelIds) && !empty($case['assigned_panel_members'])) {
    $assignedPanelIds = json_decode((string)$case['assigned_panel_members'], true) ?? [];
}
$assignedPanelIds  = array_values(array_unique(array_map('intval', $assignedPanelIds)));
$totalPanelMembers = count($assignedPanelIds);

// ── CONFIDENTIALITY ───────────────────────────────────────────────────────
$acceptedRow = db_one(
    "SELECT 1 AS ok FROM upcc_case_panel_acceptance WHERE case_id = :c AND upcc_id = :u LIMIT 1",
    [':c' => $caseId, ':u' => $panelId]
);
$confidentialityAccepted = (bool)$acceptedRow;

// ═══════════════════════════════════════════════════════════════════════════
//  POST HANDLERS
// ═══════════════════════════════════════════════════════════════════════════

// ── ACCEPT CONFIDENTIALITY ────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'accept_confidentiality') {
    db_exec(
        "INSERT INTO upcc_case_panel_acceptance (case_id, upcc_id, accepted_at)
         VALUES (:c, :u, NOW()) ON DUPLICATE KEY UPDATE accepted_at = VALUES(accepted_at)",
        [':c' => $caseId, ':u' => $panelId]
    );
    header('Location: case_view.php?id=' . $caseId . '&accepted=1');
    exit;
}

// ── POST CHAT MESSAGE ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'post_message') {
    $message = trim((string)($_POST['message'] ?? ''));
    if ($message !== '') {
        db_exec(
            "INSERT INTO upcc_case_discussion (case_id, upcc_id, message, created_at, updated_at)
             VALUES (:c, :u, :m, NOW(), NOW())",
            [':c' => $caseId, ':u' => $panelId, ':m' => $message]
        );
        upcc_log_case_activity($caseId, 'UPCC', $panelId, 'CHAT_MESSAGE_POSTED', ['length' => mb_strlen($message)]);
    }
    header('Location: case_view.php?id=' . $caseId . '#chat-room');
    exit;
}

// ── SUGGEST PENALTY ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'suggest_penalty') {
    $category = (int)($_POST['suggest_category'] ?? 0);

    $activeCooldown = db_one(
        "SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(cooldown_until)) AS remaining
         FROM upcc_suggestion_cooldown
         WHERE case_id = :c AND cooldown_until > NOW()",
        [':c' => $caseId]
    );
    $cooldownRemainingSecs = max(0, (int)($activeCooldown['remaining'] ?? 0));
    if ($cooldownRemainingSecs > 0) {
        header('Location: case_view.php?id=' . $caseId . '#decision-panel&cooldown=1');
        exit;
    }

    if ($category >= 1 && $category <= 5) {

        // Block if active round exists
        $existingActive = db_one(
            "SELECT round_no FROM upcc_case_vote_round WHERE case_id = :c AND is_active = 1 LIMIT 1",
            [':c' => $caseId]
        );
        if ($existingActive) {
            header('Location: case_view.php?id=' . $caseId . '#decision-panel&already_active=1');
            exit;
        }

        // Build details payload
        $voteDetails = [];
        if ($category === 1) {
            $terms = (int)($_POST['suggest_cat1_terms'] ?? 3);
            $terms = max(1, min(3, $terms));
            $voteDetails['probation_terms'] = $terms;
        } elseif ($category === 2) {
            $voteDetails['interventions'] = [];
            if (!empty($_POST['suggest_cat2_university_service'])) {
                $voteDetails['interventions'][] = 'University Service';
                $hrs = trim((string)($_POST['suggest_cat2_service_hours'] ?? ''));
                if ($hrs === 'OTHER') {
                    $hVal = trim((string)($_POST['suggest_cat2_service_hours_custom_h'] ?? ''));
                    $mVal = trim((string)($_POST['suggest_cat2_service_hours_custom_m'] ?? ''));
                    $h = is_numeric($hVal) ? (float)$hVal : 0.0;
                    $m = is_numeric($mVal) ? (float)$mVal : 0.0;
                    $hrs = (string)($h + ($m / 60.0));
                }
                $voteDetails['service_hours'] = is_numeric($hrs) ? (float)$hrs : 0.0;
            }
            if (!empty($_POST['suggest_cat2_counseling']))  $voteDetails['interventions'][] = 'Referral for Counseling';
            if (!empty($_POST['suggest_cat2_lectures']))    $voteDetails['interventions'][] = 'Attendance to Discipline Education Program';
            if (!empty($_POST['suggest_cat2_evaluation']))  $voteDetails['interventions'][] = 'Evaluation';
        }
        $voteDetails['description'] = trim((string)($_POST['suggest_description'] ?? ''));

        // Create round
        $roundRow = db_one(
            "SELECT COALESCE(MAX(round_no), 0) + 1 AS new_round FROM upcc_case_vote_round WHERE case_id = :c",
            [':c' => $caseId]
        );
        $roundNo = (int)($roundRow['new_round'] ?? 1);

        if ($voteRoundHasSuggestedBy) {
            db_exec(
                "INSERT INTO upcc_case_vote_round (case_id, round_no, started_at, ends_at, is_active, suggested_by)
                 VALUES (:c, :r, NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE), 1, :sb)",
                [':c' => $caseId, ':r' => $roundNo, ':sb' => $panelId]
            );
        } else {
            db_exec(
                "INSERT INTO upcc_case_vote_round (case_id, round_no, started_at, ends_at, is_active)
                 VALUES (:c, :r, NOW(), DATE_ADD(NOW(), INTERVAL 10 MINUTE), 1)",
                [':c' => $caseId, ':r' => $roundNo]
            );
        }

        // Clear stale consensus
        db_exec("UPDATE upcc_case SET
                 hearing_vote_consensus_category = NULL,
                 hearing_vote_suggested_details  = NULL,
                 hearing_vote_consensus_at       = NULL,
                 hearing_vote_suggester_id       = NULL,
                 status = CASE WHEN status = 'AWAITING_ADMIN_FINALIZATION' THEN 'UNDER_INVESTIGATION' ELSE status END,
                 updated_at = NOW()
                 WHERE case_id = :c", [':c' => $caseId]);

        db_exec(
            "UPDATE upcc_case
             SET hearing_vote_suggester_id = :sid,
                 updated_at = NOW()
             WHERE case_id = :c",
            [':sid' => $panelId, ':c' => $caseId]
        );

        // Auto-vote AGREE for the suggester
        db_exec(
            "INSERT INTO upcc_case_vote (case_id, round_no, upcc_id, vote_category, vote_details, vote_agree, created_at)
             VALUES (:c, :r, :u, :cat, :det, 1, NOW())
             ON DUPLICATE KEY UPDATE vote_category = VALUES(vote_category), vote_details = VALUES(vote_details), vote_agree = 1",
            [
                ':c'   => $caseId,
                ':r'   => $roundNo,
                ':u'   => $panelId,
                ':cat' => $category,
                ':det' => json_encode($voteDetails),
            ]
        );

        $_SESSION['upcc_last_suggester_case_id'] = $caseId;
        $_SESSION['upcc_last_suggester_id']      = $panelId;

        upcc_log_case_activity($caseId, 'UPCC', $panelId, 'SUGGESTED_PENALTY_CATEGORY', [
            'category' => $category,
            'round_no' => $roundNo,
        ]);
    }

    header('Location: case_view.php?id=' . $caseId . '#voting-section');
    exit;
}

// ── VOTE ON SUGGESTION ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vote_on_suggestion') {
    $roundNo     = (int)($_POST['round_no'] ?? 0);
    $voteAgree   = (int)($_POST['vote_agree'] ?? 0);
    $suggesterId = (int)($_POST['suggested_by'] ?? 0);

    // Verify active round
    $round = db_one(
        "SELECT * FROM upcc_case_vote_round WHERE case_id = :c AND round_no = :r AND is_active = 1 LIMIT 1",
        [':c' => $caseId, ':r' => $roundNo]
    );

    if ($round) {
        // Find suggester's vote to copy payload
        $sugVote = db_one(
            "SELECT vote_category, vote_details FROM upcc_case_vote
             WHERE case_id = :c AND round_no = :r AND upcc_id = :s LIMIT 1",
            [':c' => $caseId, ':r' => $roundNo, ':s' => $suggesterId]
        );

        if (!$sugVote && $roundHasSugByColumn = $voteRoundHasSuggestedBy) {
            $sugIdFromRound = (int)($round['suggested_by'] ?? 0);
            if ($sugIdFromRound > 0) {
                $sugVote = db_one(
                    "SELECT vote_category, vote_details FROM upcc_case_vote
                     WHERE case_id = :c AND round_no = :r AND upcc_id = :s LIMIT 1",
                    [':c' => $caseId, ':r' => $roundNo, ':s' => $sugIdFromRound]
                );
            }
        }

        $vCat = (int)($sugVote['vote_category'] ?? 1);
        $vDet = $sugVote['vote_details'] ?? '{}';

        db_exec(
            "INSERT INTO upcc_case_vote (case_id, round_no, upcc_id, vote_category, vote_details, vote_agree, created_at)
             VALUES (:c, :r, :u, :cat, :det, :ag, NOW())
             ON DUPLICATE KEY UPDATE vote_agree = VALUES(vote_agree)",
            [
                ':c'   => $caseId,
                ':r'   => $roundNo,
                ':u'   => $panelId,
                ':cat' => $vCat,
                ':det' => $vDet,
                ':ag'  => $voteAgree,
            ]
        );

        upcc_log_case_activity($caseId, 'UPCC', $panelId, 'VOTED_ON_SUGGESTION', [
            'round_no'   => $roundNo,
            'vote_agree' => $voteAgree,
        ]);

        // Check if all voters submitted
        $totalAssigned = count($assignedPanelIds);
        $allVotes = db_all(
            "SELECT vote_agree FROM upcc_case_vote WHERE case_id = :c AND round_no = :r",
            [':c' => $caseId, ':r' => $roundNo]
        );

        $agrees    = 0;
        $disagrees = 0;
        foreach ($allVotes as $v) {
            if ((int)$v['vote_agree'] === 1) $agrees++;
            else $disagrees++;
        }

        // DISAGREE -> cancel round immediately
        if ($disagrees > 0) {
            db_exec(
                "UPDATE upcc_case_vote_round SET is_active = 0" . ($voteRoundHasEndedAt ? ", ended_at = NOW()" : "") . " WHERE case_id = :c AND round_no = :r",
                [':c' => $caseId, ':r' => $roundNo]
            );
            db_exec(
                "INSERT INTO upcc_suggestion_cooldown (case_id, round_no, upcc_id, cooldown_until, created_at)
                 VALUES (:c, :r, :u, DATE_ADD(NOW(), INTERVAL 3 MINUTE), NOW())",
                [':c' => $caseId, ':r' => $roundNo, ':u' => $panelId]
            );
            upcc_log_case_activity($caseId, 'SYSTEM', 0, 'VOTE_ROUND_FAILED_DISAGREE', ['round_no' => $roundNo]);
            $_SESSION['upcc_vote_flash'] = ['type' => 'disagree', 'message' => 'Proposal rejected by panel member. 3-minute cooldown initiated.'];
        }
        // CONSENSUS REACHED -> all assigned members agreed
        elseif ($agrees >= $totalAssigned && $totalAssigned > 0) {
            db_exec(
                "UPDATE upcc_case_vote_round SET is_active = 0" . ($voteRoundHasEndedAt ? ", ended_at = NOW()" : "") . " WHERE case_id = :c AND round_no = :r",
                [':c' => $caseId, ':r' => $roundNo]
            );
            db_exec(
                "UPDATE upcc_case SET
                 hearing_vote_consensus_category = :cat,
                 hearing_vote_suggested_details  = :det,
                 hearing_vote_consensus_at       = NOW(),
                 status                          = 'AWAITING_ADMIN_FINALIZATION',
                 updated_at                      = NOW()
                 WHERE case_id = :c",
                [':cat' => $vCat, ':det' => $vDet, ':c' => $caseId]
            );
            upcc_log_case_activity($caseId, 'SYSTEM', 0, 'VOTE_CONSENSUS_REACHED', [
                'round_no' => $roundNo,
                'category' => $vCat,
            ]);
            $_SESSION['upcc_vote_flash'] = ['type' => 'consensus', 'message' => 'Panel consensus reached! Awaiting Admin finalization.'];
        }
    }

    header('Location: case_view.php?id=' . $caseId . '#voting-section');
    exit;
}

// ── CANCEL SUGGESTION ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_suggestion') {
    $roundNo = (int)($_POST['round_no'] ?? 0);
    $round   = db_one(
        "SELECT * FROM upcc_case_vote_round WHERE case_id = :c AND round_no = :r AND is_active = 1 LIMIT 1",
        [':c' => $caseId, ':r' => $roundNo]
    );

    if ($round) {
        $sugIdFromRound = $voteRoundHasSuggestedBy ? (int)($round['suggested_by'] ?? 0) : 0;
        if ($sugIdFromRound === $panelId || $sessionSuggesterId === $panelId) {
            db_exec(
                "UPDATE upcc_case_vote_round SET is_active = 0" . ($voteRoundHasEndedAt ? ", ended_at = NOW()" : "") . " WHERE case_id = :c AND round_no = :r",
                [':c' => $caseId, ':r' => $roundNo]
            );
            db_exec(
                "INSERT INTO upcc_suggestion_cooldown (case_id, round_no, upcc_id, cooldown_until, created_at)
                 VALUES (:c, :r, :u, DATE_ADD(NOW(), INTERVAL 3 MINUTE), NOW())",
                [':c' => $caseId, ':r' => $roundNo, ':u' => $panelId]
            );
            upcc_log_case_activity($caseId, 'UPCC', $panelId, 'CANCELLED_SUGGESTION', ['round_no' => $roundNo]);
            $_SESSION['upcc_vote_flash'] = ['type' => 'cancelled', 'message' => 'Suggestion cancelled. 3-minute cooldown initiated.'];
        }
    }

    header('Location: case_view.php?id=' . $caseId . '#voting-section');
    exit;
}

// ═══════════════════════════════════════════════════════════════════════════
//  DATA FETCHING FOR VIEW
// ═══════════════════════════════════════════════════════════════════════════

// Active vote round
$activeRound = db_one(
    "SELECT * FROM upcc_case_vote_round WHERE case_id = :c AND is_active = 1 ORDER BY round_no DESC LIMIT 1",
    [':c' => $caseId]
);

$isRoundActive          = (bool)$activeRound;
$roundNo                = (int)($activeRound['round_no'] ?? 0);
$roundSecondsRemaining = 0;
if ($isRoundActive && !empty($activeRound['ends_at'])) {
    $roundSecondsRemaining = max(0, strtotime($activeRound['ends_at']) - time());
}

// Suggester identification
$suggesterId = 0;
if ($isRoundActive) {
    if ($voteRoundHasSuggestedBy && !empty($activeRound['suggested_by'])) {
        $suggesterId = (int)$activeRound['suggested_by'];
    } elseif ($sessionSuggesterCaseId === $caseId && $sessionSuggesterId > 0) {
        $suggesterId = $sessionSuggesterId;
    } else {
        $sugRow = db_one(
            "SELECT upcc_id FROM upcc_case_vote WHERE case_id = :c AND round_no = :r ORDER BY vote_id ASC LIMIT 1",
            [':c' => $caseId, ':r' => $roundNo]
        );
        if ($sugRow) $suggesterId = (int)$sugRow['upcc_id'];
    }
}

$isCurrentUserSuggester = ($suggesterId === $panelId);

// Votes in active round
$votesInRound = [];
if ($isRoundActive) {
    $votesInRound = db_all(
        "SELECT * FROM upcc_case_vote WHERE case_id = :c AND round_no = :r",
        [':c' => $caseId, ':r' => $roundNo]
    );
}

$votesByMember = [];
$agreeVotes    = 0;
$disagreeVotes = 0;
$suggestedDetails = null;

foreach ($votesInRound as $v) {
    $uid = (int)$v['upcc_id'];
    $ag  = (int)$v['vote_agree'];
    $votesByMember[$uid] = $ag;
    if ($ag === 1) $agreeVotes++;
    else $disagreeVotes++;

    if ($uid === $suggesterId && $suggestedDetails === null) {
        $suggestedDetails = [
            'category' => (int)$v['vote_category'],
            'details'  => json_decode((string)$v['vote_details'], true) ?? [],
        ];
    }
}

$totalVoters        = count($assignedPanelIds);
$hasVoted           = isset($votesByMember[$panelId]);
$currentMemberVote  = $votesByMember[$panelId] ?? null;
$showVoteButtons    = $isRoundActive && !$isCurrentUserSuggester && !$hasVoted;
$showCancelSuggestion = $isRoundActive && $isCurrentUserSuggester;

// Suggester name
$suggesterName = 'Panel Member';
if ($suggesterId > 0) {
    $sugUser = db_one("SELECT full_name FROM upcc_user WHERE upcc_id = :u", [':u' => $suggesterId]);
    if ($sugUser) $suggesterName = $sugUser['full_name'];
}

// Cooldown state
$cooldownRow = db_one(
    "SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(cooldown_until)) AS remaining
     FROM upcc_suggestion_cooldown
     WHERE case_id = :c AND cooldown_until > NOW()",
    [':c' => $caseId]
);
$cooldownRemainingSecs = max(0, (int)($cooldownRow['remaining'] ?? 0));
$isInCooldown          = $cooldownRemainingSecs > 0;

// Consensus state
$consensusCategory = (int)($case['hearing_vote_consensus_category'] ?? 0);
$isAwaitingAdmin   = ($consensusCategory >= 1 && $consensusCategory <= 5);

// Popup flag: show modal if round is active AND user hasn't voted OR is suggester
$showVotingPopup = $isRoundActive && ($isCurrentUserSuggester || !$hasVoted);

// ── OFFENSES ──────────────────────────────────────────────────────────────
$offenses = db_all(
    "SELECT o.offense_id, o.date_committed, o.description, o.incident_photo,
            ot.code, ot.name AS offense_name, ot.level, ot.intervention_first, ot.intervention_second
     FROM upcc_case_offense uco
     JOIN offense o ON o.offense_id = uco.offense_id
     JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
     WHERE uco.case_id = :c ORDER BY ot.level DESC, ot.code ASC",
    [':c' => $caseId]
);

$priorResolvedCases = db_all(
    "SELECT uc.case_id, uc.status, uc.created_at, uc.updated_at, uc.decided_category,
            GROUP_CONCAT(DISTINCT ot.code ORDER BY ot.code SEPARATOR ', ') AS offense_codes,
            GROUP_CONCAT(DISTINCT ot.name ORDER BY ot.code SEPARATOR ' | ') AS offense_names,
            SUM(CASE WHEN ot.level >= 4 THEN 1 ELSE 0 END) AS major_count,
            SUM(CASE WHEN ot.level < 4 THEN 1 ELSE 0 END) AS minor_count
     FROM upcc_case uc
     LEFT JOIN upcc_case_offense uco ON uco.case_id = uc.case_id
     LEFT JOIN offense o ON o.offense_id = uco.offense_id
     LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
     WHERE uc.student_id = :sid AND uc.case_id != :cid
       AND uc.status IN ('RESOLVED', 'CLOSED', 'FINALIZED')
     GROUP BY uc.case_id
     ORDER BY uc.created_at DESC",
    [':sid' => $case['student_id'], ':cid' => $caseId]
);

$otherPendingCases = db_all(
    "SELECT uc.case_id, uc.status, uc.created_at, uc.updated_at,
            GROUP_CONCAT(DISTINCT ot.code ORDER BY ot.code SEPARATOR ', ') AS offense_codes,
            GROUP_CONCAT(DISTINCT ot.name ORDER BY ot.code SEPARATOR ' | ') AS offense_names,
            SUM(CASE WHEN ot.level >= 4 THEN 1 ELSE 0 END) AS major_count,
            SUM(CASE WHEN ot.level < 4 THEN 1 ELSE 0 END) AS minor_count
     FROM upcc_case uc
     LEFT JOIN upcc_case_offense uco ON uco.case_id = uc.case_id
     LEFT JOIN offense o ON o.offense_id = uco.offense_id
     LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
     WHERE uc.student_id = :sid AND uc.case_id != :cid
       AND uc.status NOT IN ('RESOLVED', 'CLOSED', 'FINALIZED', 'DISMISSED', 'CANCELLED')
     GROUP BY uc.case_id
     ORDER BY uc.created_at DESC",
    [':sid' => $case['student_id'], ':cid' => $caseId]
);

$discussion = db_all(
    "SELECT d.message, d.created_at, d.reply_to_message_id, d.upcc_id, d.admin_id,
            COALESCE(u.full_name, a.full_name) AS full_name,
            COALESCE(u.role, a.role) AS role, d.message_id
     FROM upcc_case_discussion d
     LEFT JOIN upcc_user u   ON u.upcc_id   = d.upcc_id
     LEFT JOIN admin_user a  ON a.admin_id  = d.admin_id
     WHERE d.case_id = :c ORDER BY d.created_at ASC",
    [':c' => $caseId]
);

$panelMembers = db_all(
    "SELECT u.full_name, u.role, u.upcc_id
     FROM upcc_user u
     JOIN upcc_case_panel_member ucpm ON ucpm.upcc_id = u.upcc_id
     WHERE ucpm.case_id = :c ORDER BY u.full_name ASC",
    [':c' => $caseId]
);

$statusRaw       = (string)($case['status'] ?? 'PENDING');
$isClosed        = in_array($statusRaw, ['CLOSED','RESOLVED','DISMISSED'], true);
$isHearingOpen   = (int)($case['hearing_is_open']  ?? 0) === 1;
$isHearingPaused = (int)($case['hearing_is_paused'] ?? 0) === 1;

$myPresenceStatus = 'ADMITTED';
$presRow = db_one(
    "SELECT status FROM upcc_hearing_presence WHERE case_id = :c AND user_type = 'UPCC' AND user_id = :u",
    [':c' => $caseId, ':u' => $panelId]
);
if ($presRow) $myPresenceStatus = $presRow['status'] ?? 'ADMITTED';

$caseLabel = 'UPCC-' . date('Y', strtotime((string)$case['created_at'])) . '-' . str_pad((string)$caseId, 4, '0', STR_PAD_LEFT);
$initials  = strtoupper(substr((string)$user['full_name'], 0, 1));
$parts     = explode(' ', (string)$user['full_name']);
if (count($parts) > 1) $initials .= strtoupper(substr((string)end($parts), 0, 1));

$hasMajorOffense = false;
foreach ($offenses as $off) {
    if ((int)($off['level'] ?? 1) >= 4 || strtoupper((string)($off['level'] ?? '')) === 'MAJOR') { $hasMajorOffense = true; break; }
}
$cKindUpper = strtoupper((string)($case['case_kind'] ?? ''));
$isSection4 = !$hasMajorOffense && ($cKindUpper === 'SECTION4_MINOR_ESCALATION'
    || ($cKindUpper !== 'MAJOR_OFFENSE' && stripos((string)($case['case_summary'] ?? ''), 'Section 4') !== false));
$decisionHint = $isSection4 ? 'Section 4 escalation case'
    : ($hasMajorOffense ? 'Major offense — Category 1–5 review' : 'Minor offense review');

function fmt_dt(?string $v): string {
    return $v ? date('M j, Y g:i A', strtotime($v)) : '—';
}
function decision_badge(string $s): array {
    return match(strtoupper($s)) {
        'DISMISSED'                  => ['label' => 'Dismissed',          'class' => 'badge-slate'],
        'CLOSED','RESOLVED'          => ['label' => 'Closed / Finalized', 'class' => 'badge-green'],
        'AWAITING_ADMIN_FINALIZATION'=> ['label' => 'Awaiting Admin',      'class' => 'badge-purple'],
        'UNDER_INVESTIGATION'        => ['label' => 'Under Review',       'class' => 'badge-purple'],
        'UNDER_APPEAL'               => ['label' => 'Under Appeal',        'class' => 'badge-blue'],
        default                      => ['label' => 'Pending',             'class' => 'badge-amber'],
    };
}
$statusBadge = decision_badge($statusRaw);

$categoryDescriptions = [
    1 => 'Formal Reprimand & Active Semester Probation (0 Hours CS).',
    2 => 'Formative Community Service with Counseling / Education / Evaluation.',
    3 => 'Non-Readmission / Suspension.',
    4 => 'Exclusion / Mandatory Dismissal (Dropped from University Rolls).',
    5 => 'Summary Expulsion & Police Referral (Permanent Disqualification).',
];

$postedDecidedCategory = isset($_POST['decided_category']) ? (int)$_POST['decided_category'] : 0;
$postedFinalDecision   = trim($_POST['final_decision'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($caseLabel) ?> — UPCC Case Workspace</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --font-h: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
  --font-b: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
  
  --bg-dark: #090d16;
  --bg-sidebar: #0e1526;
  --bg-card: rgba(18, 26, 43, 0.75);
  --bg-glass: rgba(15, 22, 38, 0.85);
  
  --border-glass: rgba(255, 255, 255, 0.08);
  --border-glass-hover: rgba(255, 255, 255, 0.16);
  
  --accent-primary: #38bdf8;
  --accent-secondary: #6366f1;
  --success: #10b981;
  --warning: #f59e0b;
  --danger: #ef4444;
  
  --text-main: #f8fafc;
  --text-sub: #cbd5e1;
  --text-muted: #64748b;
  
  --radius-lg: 16px;
  --radius-md: 12px;
  --radius-sm: 8px;
}

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: var(--font-b);
  color: var(--text-main);
  background: var(--bg-dark);
  min-height: 100vh;
  position: relative;
  overflow-x: hidden;
  line-height: 1.5;
  -webkit-font-smoothing: antialiased;
}

body::before {
  content: '';
  position: fixed; inset: 0; z-index: -2;
  background: radial-gradient(circle at 10% 20%, rgba(56, 189, 248, 0.08), transparent 45%),
              radial-gradient(circle at 90% 80%, rgba(99, 102, 241, 0.08), transparent 45%),
              radial-gradient(circle at 50% 50%, rgba(16, 185, 129, 0.03), transparent 50%);
  filter: blur(80px);
}

.app-container {
  display: grid;
  grid-template-columns: 260px 1fr;
  min-height: 100vh;
}

.sidebar {
  background: var(--bg-sidebar);
  border-right: 1px solid var(--border-glass);
  padding: 28px 20px;
  display: flex;
  flex-direction: column;
}

.brand {
  display: flex; align-items: center; gap: 12px;
  margin-bottom: 32px; padding-bottom: 20px;
  border-bottom: 1px solid var(--border-glass);
}
.brand-icon {
  width: 42px; height: 42px;
  background: rgba(255,255,255,0.04);
  border: 1px solid var(--border-glass);
  border-radius: 10px;
  display: grid; place-items: center;
  padding: 6px;
}
.brand-icon img { width: 100%; height: auto; border-radius: 4px; }
.brand-text h1 { font-family: var(--font-h); font-size: 17px; font-weight: 700; line-height: 1.2; }
.brand-text p { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; font-weight: 600; margin-top: 2px; }

.side-group { margin-top: 20px; }
.side-label { font-size: 11px; letter-spacing: 1px; color: var(--text-muted); text-transform: uppercase; margin-bottom: 10px; font-weight: 600; }
.panel-chip {
  display: inline-flex; align-items: center; gap: 8px; border: 1px solid var(--border-glass);
  border-radius: 8px; padding: 6px 12px; margin: 0 6px 6px 0; background: rgba(255,255,255,0.02);
  font-size: 12.5px; color: var(--text-sub); font-weight: 500;
}
.panel-chip small { color: var(--text-muted); font-weight: 400; }

.main-content { padding: 36px 40px; overflow-y: auto; }

.hero {
  background: var(--bg-card); backdrop-filter: blur(16px); border: 1px solid var(--border-glass);
  border-radius: var(--radius-lg); padding: 28px 32px; display: flex; justify-content: space-between;
  gap: 24px; align-items: flex-start; margin-bottom: 24px; box-shadow: 0 12px 32px rgba(0,0,0,0.2);
}
.crumb { color: var(--accent-primary); font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; }
.title { font-family: var(--font-h); font-size: 26px; font-weight: 800; color: var(--text-main); line-height: 1.2; letter-spacing: -0.3px; }
.subtitle { margin-top: 8px; color: var(--text-muted); max-width: 740px; line-height: 1.5; font-size: 13.5px; }

.hero-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-top: 18px; }
.pill { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 6px; font-size: 11.5px; font-weight: 600; border: 1px solid transparent; }
.pill.green { color: #34d399; background: rgba(16,185,129,0.1); border-color: rgba(16,185,129,0.25); }
.pill.amber { color: #fbbf24; background: rgba(245,158,11,0.1); border-color: rgba(245,158,11,0.25); }
.pill.blue { color: #38bdf8; background: rgba(56,189,248,0.1); border-color: rgba(56,189,248,0.25); }
.pill.purple { color: #c4b5fd; background: rgba(139,92,246,0.1); border-color: rgba(139,92,246,0.25); }

.info { border: 1px solid var(--border-glass); background: rgba(255,255,255,0.02); border-radius: 10px; padding: 14px 18px; min-width: 200px; }
.info-label { color: var(--text-muted); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 6px; font-weight: 600; }
.info-value { font-size: 15px; font-weight: 700; color: var(--text-main); font-family: var(--font-h); }

.layout { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 24px; }
@media(max-width: 1100px) { .layout { grid-template-columns: 1fr; } }

.glass-panel {
  background: var(--bg-card); backdrop-filter: blur(16px); border: 1px solid var(--border-glass);
  border-radius: var(--radius-lg); overflow: hidden; display: flex; flex-direction: column;
}
.panel-header {
  padding: 18px 24px; border-bottom: 1px solid var(--border-glass);
  display: flex; align-items: center; justify-content: space-between; gap: 12px; background: rgba(255,255,255,0.01);
}
.panel-title { font-family: var(--font-h); font-size: 15.5px; font-weight: 700; display: flex; align-items: center; gap: 8px; color: var(--text-main); }
.panel-body { padding: 24px; }

.badge { display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; border: 1px solid transparent; }
.badge-amber { background: rgba(245,158,11,0.1); color: #fbbf24; border-color: rgba(245,158,11,0.25); }
.badge-purple { background: rgba(139,92,246,0.1); color: #c4b5fd; border-color: rgba(139,92,246,0.25); }
.badge-green { background: rgba(16,185,129,0.1); color: #34d399; border-color: rgba(16,185,129,0.25); }
.badge-blue { background: rgba(56,189,248,0.1); color: #38bdf8; border-color: rgba(56,189,248,0.25); }
.badge-slate { background: rgba(100,116,139,0.1); color: #94a3b8; border-color: rgba(100,116,139,0.25); }

.offense-list { display: grid; gap: 10px; }
.offense-item { border: 1px solid var(--border-glass); background: rgba(255,255,255,0.015); border-radius: 10px; overflow: hidden; transition: all .2s ease; }
.offense-item:hover { background: rgba(255,255,255,0.035); border-color: var(--border-glass-hover); }
.offense-item summary { list-style: none; cursor: pointer; padding: 14px 18px; display: flex; justify-content: space-between; gap: 12px; align-items: center; }
.offense-item summary::-webkit-details-marker { display: none; }
.offense-main { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.offense-code { font-size: 12px; color: var(--accent-primary); font-weight: 700; letter-spacing: 0.5px; }
.offense-name { font-size: 14.5px; font-weight: 600; font-family: var(--font-h); color: var(--text-main); }
.offense-meta { color: var(--text-muted); font-size: 12px; }
.offense-body { padding: 0 18px 18px; border-top: 1px solid var(--border-glass); padding-top: 14px; display: grid; gap: 10px; }
.offense-row { display: grid; grid-template-columns: 130px 1fr; gap: 12px; align-items: start; }
.offense-row .label { color: var(--text-muted); font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
.offense-row .value { color: var(--text-sub); font-size: 13px; line-height: 1.5; }

.field { display: grid; gap: 6px; }
.field label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
.fld-input { width: 100%; border: 1px solid var(--border-glass); background: rgba(18, 26, 43, 0.5); color: var(--text-main); border-radius: 8px; padding: 10px 14px; font-family: var(--font-b); font-size: 13px; transition: all .15s ease; outline: none; }
.fld-input option, select option { background-color: #0f172a !important; color: #f8fafc !important; }
.fld-input:focus { border-color: rgba(56, 189, 248, 0.5); box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.12); }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: 8px; border: none; border-radius: 8px; cursor: pointer; padding: 9px 16px; font-family: var(--font-b); font-size: 13px; font-weight: 600; text-decoration: none; transition: all .15s ease; }
.btn-primary { background: #2563eb; color: #fff; }
.btn-primary:hover { background: #1d4ed8; transform: translateY(-1px); }
.btn-secondary { background: rgba(255,255,255,0.04); color: var(--text-sub); border: 1px solid var(--border-glass); }
.btn-secondary:hover { background: rgba(255,255,255,0.08); color: var(--text-main); }
.btn-danger { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
.btn-danger:hover { background: rgba(239,68,68,0.25); }
.btn-success { background: rgba(16,185,129,0.15); color: #34d399; border: 1px solid rgba(16,185,129,0.3); }
.btn-success:hover { background: rgba(16,185,129,0.25); }

.chat-item { border: 1px solid var(--border-glass); background: rgba(255,255,255,0.015); border-radius: 10px; padding: 12px 14px; position: relative; margin-bottom: 10px; }
.chat-head { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 6px; }
.chat-name { font-weight: 600; font-size: 13px; font-family: var(--font-h); color: var(--text-main); }
.chat-role { color: var(--text-muted); font-size: 11px; text-transform: uppercase; letter-spacing: .5px; }
.chat-time { color: var(--text-muted); font-size: 11px; white-space: nowrap; }
.chat-msg { color: var(--text-sub); line-height: 1.5; font-size: 13px; white-space: pre-wrap; }
.empty { color: var(--text-muted); font-size: 13px; padding: 12px 0; font-style: italic; }
.lock { border: 1px dashed rgba(245,158,11,0.3); background: rgba(245,158,11,0.05); border-radius: 10px; padding: 20px; color: #fbbf24; text-align: center; font-size: 13px; }
.stack { display: grid; gap: 16px; }

/* Cooldown Alert */
.cooldown-alert { background: rgba(239,68,68,0.1); border: 1px solid rgba(239,68,68,0.25); border-radius: 10px; padding: 12px 16px; text-align: center; font-size: 13px; color: #f87171; }
.cooldown-alert strong { font-size: 18px; display: block; margin-top: 4px; font-family: var(--font-h); font-variant-numeric: tabular-nums; }

/* Consensus Banner */
.consensus-banner { background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.3); border-radius: 10px; padding: 18px; text-align: center; margin-bottom: 16px; }
.consensus-banner-title { font-family: var(--font-h); font-size: 16px; font-weight: 700; color: #34d399; margin-bottom: 4px; }

/* Voting Modal */
.voting-modal { position: fixed; inset: 0; z-index: 9000; display: none; align-items: center; justify-content: center; background: rgba(0,0,0,0.8); backdrop-filter: blur(10px); padding: 16px; }
.voting-modal.open { display: flex; }
.vmc { background: #0f172a; border: 1px solid var(--border-glass-hover); border-radius: var(--radius-lg); padding: 28px; width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto; box-shadow: 0 24px 48px rgba(0,0,0,0.6); }

.vmc-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.vmc-title { font-family: var(--font-h); font-size: 18px; font-weight: 700; color: var(--text-main); }
.vmc-live-badge { font-size: 10px; font-weight: 700; color: #34d399; text-transform: uppercase; background: rgba(16, 185, 129, 0.15); border: 1px solid rgba(16, 185, 129, 0.3); padding: 2px 8px; border-radius: 4px; }
.vmc-sub { font-size: 13px; color: var(--text-muted); margin-bottom: 16px; line-height: 1.5; }

.timer-wrap { margin-bottom: 16px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 8px; padding: 12px; }
.timer-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
.timer-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 600; }
.timer-num { font-family: var(--font-h); font-size: 18px; font-weight: 700; color: var(--text-main); font-variant-numeric: tabular-nums; }
.timer-bar-wrap { height: 4px; background: rgba(255,255,255,0.05); border-radius: 2px; overflow: hidden; }
.timer-bar-fill { height: 100%; transition: width 1s linear; }

.vote-tally { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; background: rgba(0,0,0,0.2); border-radius: 10px; padding: 12px; margin: 14px 0; text-align: center; }
.tally-item label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); display: block; margin-bottom: 2px; }
.tally-item span { font-family: var(--font-h); font-size: 20px; font-weight: 700; }
.tally-agree span { color: #34d399; } .tally-disagree span { color: #f87171; } .tally-pending span { color: #fbbf24; }

.suggestion-box { background: rgba(0,0,0,0.25); border: 1px solid var(--border-glass); border-radius: 10px; padding: 16px; margin: 12px 0; }
.suggestion-category { font-family: var(--font-h); font-size: 16px; font-weight: 700; color: #38bdf8; margin-bottom: 6px; }

.vote-btns { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 14px; }
.vote-btns form { display: contents; }
.btn-vote-agree { background: #059669; color: #fff; border: none; border-radius: 8px; padding: 12px; font-family: var(--font-b); font-size: 13.5px; font-weight: 700; cursor: pointer; transition: all .15s ease; }
.btn-vote-agree:hover { background: #047857; }
.btn-vote-disagree { background: #dc2626; color: #fff; border: none; border-radius: 8px; padding: 12px; font-family: var(--font-b); font-size: 13.5px; font-weight: 700; cursor: pointer; transition: all .15s ease; }
.btn-vote-disagree:hover { background: #b91c1c; }

.voted-confirmation { text-align: center; background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.25); border-radius: 8px; padding: 12px; font-size: 13px; font-weight: 600; color: #34d399; }
.decision-box { border: 1px solid rgba(56,189,248,0.25); background: rgba(56,189,248,0.05); border-radius: var(--radius-md); padding: 16px; display: grid; gap: 8px; }

.voter-list { display: grid; gap: 8px; margin: 14px 0; max-height: 200px; overflow-y: auto; }
.voter-item { display: flex; align-items: center; justify-content: space-between; padding: 8px 12px; background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass); border-radius: 8px; font-size: 12.5px; }
.voter-name { font-weight: 600; color: var(--text-main); }
.voter-meta { font-size: 11px; color: var(--text-muted); }

.v-pill { padding: 2px 8px; border-radius: 4px; font-size: 10.5px; font-weight: 600; text-transform: uppercase; }
.v-pill.agree { background: rgba(16,185,129,0.15); color: #34d399; }
.v-pill.disagree { background: rgba(239,68,68,0.15); color: #f87171; }
.v-pill.pending { background: rgba(245,158,11,0.15); color: #fbbf24; }
.v-pill.suggester { background: rgba(56,189,248,0.15); color: #38bdf8; }

.tally-row { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; margin: 12px 0; text-align: center; }
.tally-cell { background: rgba(0,0,0,0.2); padding: 10px; border-radius: 8px; border: 1px solid var(--border-glass); }
.tally-cell label { font-size: 10px; text-transform: uppercase; color: var(--text-muted); display: block; margin-bottom: 2px; }
.tally-cell span { font-family: var(--font-h); font-size: 18px; font-weight: 700; }
.tc-agree span { color: #34d399; } .tc-disagree span { color: #f87171; } .tc-pending span { color: #fbbf24; }
.tally-note { font-size: 11px; color: var(--text-muted); text-align: center; margin-top: 4px; }

.result-flash { border-radius: 8px; padding: 12px; text-align: center; font-weight: 600; font-size: 13px; margin-bottom: 12px; }
.result-flash.consensus { background: rgba(16,185,129,0.15); border: 1px solid rgba(16,185,129,0.3); color: #34d399; }
.result-flash.disagreed { background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3); color: #f87171; }

.sug-tag { display: inline-block; background: rgba(56,189,248,0.12); border: 1px solid rgba(56,189,248,0.25); color: #7dd3fc; font-size: 11px; padding: 2px 8px; border-radius: 4px; margin-right: 4px; margin-bottom: 4px; }
.sug-note { margin-top: 8px; padding-top: 8px; border-top: 1px solid var(--border-glass); font-size: 12.5px; color: var(--text-sub); line-height: 1.4; }

.spinner-loader {
  width: 40px; height: 40px; border: 3px solid rgba(255,255,255,.1); border-top: 3px solid #38bdf8;
  border-radius: 50%; margin: 0 auto 16px; animation: spin-loader 0.8s linear infinite;
}
@keyframes spin-loader { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

.case-details-blur { filter: blur(5px); user-select: none; pointer-events: none; }
</style>
</head>
<body>

<div id="globalLoadingOverlay" style="position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(9,13,22,.92);backdrop-filter:blur(8px)">
    <div style="text-align:center;color:#fff;padding:24px">
        <div class="spinner-loader"></div>
        <div id="loadingOverlayText" style="font-family:var(--font-h);font-size:18px;font-weight:700;color:#f8fafc;margin-bottom:4px">Submitting suggestion...</div>
        <div style="font-size:12px;color:var(--text-muted)">Please wait, processing request.</div>
    </div>
</div>

<div class="app-container">

<!-- SIDEBAR -->
<aside class="sidebar">
    <div class="brand">
        <div class="brand-icon"><img src="../assets/logo.png" alt="IdentiTrack"></div>
        <div class="brand-text"><h1>UPCC Panel</h1><p>Case Workspace</p></div>
    </div>
    <div class="side-group">
        <div class="side-label">Active Case Record</div>
        <div class="panel-chip"><small>ID</small> <?= htmlspecialchars($caseLabel) ?></div>
        <div class="panel-chip"><small>Status</small> <?= htmlspecialchars($statusBadge['label']) ?></div>
        <div class="panel-chip"><small>Review Mode</small> <?= htmlspecialchars($decisionHint) ?></div>
    </div>
    <div class="side-group">
        <div class="side-label">Assigned Panel</div>
        <?php if (!empty($panelMembers)): foreach ($panelMembers as $m): ?>
            <div class="panel-chip">
                <span><?= htmlspecialchars($m['full_name']) ?></span>
                <small>(<?= htmlspecialchars($m['role']) ?>)</small>
            </div>
        <?php endforeach; else: ?>
            <div class="empty">No panel members assigned.</div>
        <?php endif; ?>
    </div>
    <div class="side-group" style="margin-top:auto; display:flex; flex-direction:column; gap:8px;">
        <a id="backToDashboardBtn" class="btn btn-secondary" href="upccdashboard.php" style="width:100%; text-align:center;">&larr; Return to Dashboard</a>
    </div>
</aside>

<!-- MAIN -->
<main class="main-content">

    <!-- HERO HEADER -->
    <section class="hero">
        <div>
            <div class="crumb">Panel Workspace / Case Details</div>
            <div class="title"><?= htmlspecialchars($caseLabel) ?></div>
            <div class="subtitle">Respondent <?= htmlspecialchars($case['student_name']) ?> is under active panel review. Evaluate offenses, review student explanation, and establish penalty consensus.</div>
            <div class="hero-meta">
                <span class="pill amber"><?= htmlspecialchars($decisionHint) ?></span>
                <span class="pill purple"><?= htmlspecialchars($case['assigned_dept_name'] ?? 'General Department') ?></span>
                <span class="pill blue"><?= htmlspecialchars($case['year_level']) ?> Year • <?= htmlspecialchars($case['section'] ?? 'N/A') ?></span>
                <span class="pill green"><?= htmlspecialchars($statusBadge['label']) ?></span>
                <?php if ($isHearingOpen && $isHearingPaused): ?>
                  <span class="pill" data-pause-pill="1" style="background: rgba(239, 68, 68, 0.12); color: #f87171; border-color: rgba(239, 68, 68, 0.3);">HEARING PAUSED</span>
                <?php elseif ($isHearingOpen): ?>
                  <span class="pill" data-pause-pill="1" style="background: rgba(16, 185, 129, 0.12); color: #34d399; border-color: rgba(16, 185, 129, 0.3);"><span class="dot dot-live"></span> LIVE HEARING</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="stack" style="min-width:240px">
            <div class="info">
                <div class="info-label">Respondent Student</div>
                <div class="info-value"><?= htmlspecialchars($case['student_name']) ?></div>
                <div style="margin-top:4px;font-size:12px;color:var(--text-muted)"><?= htmlspecialchars($case['student_id']) ?> &bull; <?= htmlspecialchars($case['program']) ?></div>
            </div>
            <div class="info">
                <div class="info-label">Case Creation Date</div>
                <div class="info-value"><?= fmt_dt((string)$case['created_at']) ?></div>
            </div>
        </div>
    </section>

    <div class="layout">
        <!-- LEFT COLUMN -->
        <section class="stack">

            <!-- OFFENSE LIST -->
            <div class="glass-panel">
                <div class="panel-header">
                    <div>
                        <div class="panel-title">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                            Offense Breakdown
                        </div>
                        <div style="color:var(--text-muted);font-size:12px;margin-top:2px">Linked offenses and student history</div>
                    </div>
                    <span class="badge <?= htmlspecialchars($statusBadge['class']) ?>"><?= htmlspecialchars($statusBadge['label']) ?></span>
                </div>
                <div class="panel-body">
                    <?php if (!$confidentialityAccepted): ?>
                        <div class="lock">
                            <div style="font-weight:700;margin-bottom:4px">Confidential Record Access Locked</div>
                            <div style="line-height:1.5;margin-bottom:14px;color:var(--text-sub)">You must accept the confidentiality agreement before viewing case offenses and participating in discussion.</div>
                            <form method="post">
                                <input type="hidden" name="action" value="accept_confidentiality">
                                <button class="btn btn-primary" type="submit">Accept Confidentiality Agreement</button>
                            </form>
                        </div>
                    <?php else: ?>

                        <!-- Student Explanation -->
                        <div id="studentExplanationBlock" style="<?= (!empty($case['student_explanation_text']) || !empty($case['student_explanation_at'])) ? 'display:block' : 'display:none' ?>; margin-bottom: 20px; background: rgba(56, 189, 248, 0.05); border: 1px solid rgba(56, 189, 248, 0.2); border-radius: 10px; padding: 16px;">
                           <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; flex-wrap: wrap; gap: 8px;">
                              <span style="font-size: 11px; font-weight: 700; color: #38bdf8; text-transform: uppercase; letter-spacing: 0.5px;">Student Explanation Statement</span>
                              <span id="explanationTime" style="font-size: 11px; color: var(--text-muted);"><?= $case['student_explanation_at'] ? 'Submitted ' . date('M j, Y g:i A', strtotime($case['student_explanation_at'])) : '' ?></span>
                           </div>
                           <div id="explanationText" style="font-size: 13px; line-height: 1.5; color: var(--text-sub); white-space: pre-wrap; margin-bottom: 10px;"><?= htmlspecialchars($case['student_explanation_text'] ?? '') ?></div>
                           <div id="explanationAttachments" style="display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin-top: 8px;">
                              <?php if (!empty($case['student_explanation_image'])): ?>
                                <a href="../<?= htmlspecialchars($case['student_explanation_image']) ?>" target="_blank" style="display: block; border-radius: 6px; overflow: hidden; border: 1px solid var(--border-glass);">
                                   <img src="../<?= htmlspecialchars($case['student_explanation_image']) ?>" style="max-width: 80px; max-height: 80px; display: block; object-fit: cover;">
                                </a>
                              <?php endif; ?>
                              <?php if (!empty($case['student_explanation_pdf'])): ?>
                                <a href="../<?= htmlspecialchars($case['student_explanation_pdf']) ?>" target="_blank" style="display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.25); border-radius: 6px; text-decoration: none; color: #f87171; font-size: 12px; font-weight: 600;">
                                   <span>View Submitted PDF Document</span>
                                </a>
                              <?php endif; ?>
                           </div>
                        </div>

                        <!-- SUB-TABS NAVIGATION -->
                        <div class="case-breakdown-tabs" style="display: flex; gap: 8px; border-bottom: 1px solid var(--border-glass); padding-bottom: 12px; margin-bottom: 16px; flex-wrap: wrap;">
                            <button type="button" class="btn btn-tab active-tab-btn" onclick="switchBreakdownTab('current-offenses', this)" style="border-radius: 6px; font-weight: 600; font-size: 12px; padding: 6px 12px; background: rgba(56, 189, 248, 0.15); color: #38bdf8; border: 1px solid rgba(56, 189, 248, 0.3); cursor: pointer;">
                                Current Case Offenses (<?= count($offenses) ?>)
                            </button>
                            <button type="button" class="btn btn-tab" onclick="switchBreakdownTab('prior-resolved', this)" style="border-radius: 6px; font-weight: 600; font-size: 12px; padding: 6px 12px; background: rgba(255, 255, 255, 0.03); color: var(--text-muted); border: 1px solid var(--border-glass); cursor: pointer;">
                                Prior Resolved Cases (<?= count($priorResolvedCases) ?>)
                            </button>
                            <?php if (!empty($otherPendingCases)): ?>
                            <button type="button" class="btn btn-tab" onclick="switchBreakdownTab('other-pending', this)" style="border-radius: 6px; font-weight: 600; font-size: 12px; padding: 6px 12px; background: rgba(245, 158, 11, 0.12); color: #fbbf24; border: 1px solid rgba(245, 158, 11, 0.25); cursor: pointer;">
                                Other Active Cases (<?= count($otherPendingCases) ?>)
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- TAB 1: CURRENT OFFENSES -->
                        <div id="tab-current-offenses" class="breakdown-tab-content">
                            <div class="offense-list">
                                <?php if (empty($offenses)): ?>
                                    <div class="empty">No linked offenses found.</div>
                                <?php else: foreach ($offenses as $idx => $offense):
                                    $lvlVal = (int)($offense['level'] ?? 1);
                                    $lvlClass = $lvlVal >= 4 ? 'badge-blue' : 'badge-amber';
                                    $lvlLabel = $lvlVal >= 4 ? 'SECTION ' . $lvlVal : 'MINOR (L' . $lvlVal . ')';
                                ?>
                                    <details class="offense-item" <?= $idx === 0 ? 'open' : '' ?>>
                                        <summary>
                                            <div class="offense-main">
                                                <div class="offense-code"><?= htmlspecialchars($offense['code']) ?></div>
                                                <div class="offense-name"><?= htmlspecialchars($offense['offense_name']) ?></div>
                                                <div class="offense-meta">Committed: <?= fmt_dt((string)$offense['date_committed']) ?></div>
                                            </div>
                                            <div class="badge <?= $lvlClass ?>"><?= $lvlLabel ?></div>
                                        </summary>
                                        <div class="offense-body">
                                            <?php if (!empty(trim((string)$offense['description']))): ?>
                                            <div class="offense-row">
                                                <div class="label">Description</div>
                                                <div class="value"><?= htmlspecialchars((string)$offense['description']) ?></div>
                                            </div>
                                            <?php endif; ?>
                                             
                                             <?php 
                                               $offEv = $offense['evidence_file'] ?? ($offense['incident_photo'] ?? null);
                                               if (!empty($offEv)): 
                                                 $ext = strtolower(pathinfo($offEv, PATHINFO_EXTENSION));
                                                 $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
                                             ?>
                                             <div class="offense-row">
                                                 <div class="label">Evidence File</div>
                                                 <div class="value">
                                                     <?php if ($isImg): ?>
                                                         <a href="../<?= htmlspecialchars($offEv) ?>" target="_blank" style="display: inline-block; margin-top: 4px;">
                                                             <img src="../<?= htmlspecialchars($offEv) ?>" style="max-width: 180px; max-height: 120px; border-radius: 6px; border: 1px solid var(--border-glass); object-fit: cover; display: block;">
                                                         </a>
                                                     <?php else: ?>
                                                         <a href="../<?= htmlspecialchars($offEv) ?>" target="_blank" style="color: #38bdf8; font-weight: 600; font-size: 12px; text-decoration: underline;">
                                                             View Attached Evidence Document
                                                         </a>
                                                     <?php endif; ?>
                                                 </div>
                                             </div>
                                             <?php endif; ?>
                                            
                                            <?php if (!empty(trim((string)($offense['intervention_first'] ?? '')))): ?>
                                            <div class="offense-row">
                                                <div class="label">1st Intervention</div>
                                                <div class="value">
                                                    <?= htmlspecialchars(trim(rtrim(preg_replace('/\s*&?\s*0\.0\s+in\s+the\s+course/i', '', preg_replace('/^Category\s*\d+\s*[\(\:\-—]?\s*/i', '', trim((string)$offense['intervention_first']))), ')-—')) ?: 'Formative Intervention: University Service & Evaluation') ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                    </details>
                                <?php endforeach; endif; ?>
                            </div>
                        </div>

                        <!-- TAB 2: PRIOR RESOLVED -->
                        <div id="tab-prior-resolved" class="breakdown-tab-content" style="display: none;">
                            <?php if (empty($priorResolvedCases)): ?>
                                <div class="empty" style="text-align: center; padding: 20px; color: var(--text-muted); font-size: 13px;">
                                    No prior resolved cases on record for this student.
                                </div>
                            <?php else: foreach ($priorResolvedCases as $rc):
                                $isMajorResolved = ((int)($rc['major_count'] ?? 0)) > 0;
                                $resolvedLvlBadge = $isMajorResolved 
                                    ? '<span class="badge badge-blue" style="font-size: 10px;">MAJOR OFFENSE</span>'
                                    : '<span class="badge badge-amber" style="font-size: 10px;">MINOR / SECTION 4</span>';
                            ?>
                                <div style="background: rgba(16, 185, 129, 0.04); border: 1px solid rgba(16, 185, 129, 0.2); border-radius: 8px; padding: 12px; margin-bottom: 10px;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 4px;">
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                            <span style="font-weight: 700; color: #34d399; font-size: 13px;">Case #<?= htmlspecialchars((string)$rc['case_id']) ?></span>
                                            <?= $resolvedLvlBadge ?>
                                        </div>
                                        <span class="badge badge-green" style="font-size: 10px;">RESOLVED</span>
                                    </div>
                                    <div style="font-weight: 600; color: var(--text-main); font-size: 13px; margin-bottom: 4px;">
                                        <?= htmlspecialchars((string)($rc['offense_names'] ?: 'General Violation')) ?>
                                    </div>
                                    <div style="font-size: 11px; color: var(--text-muted);">
                                        Code: <?= htmlspecialchars((string)($rc['offense_codes'] ?: 'N/A')) ?> &bull; Resolved <?= fmt_dt((string)$rc['updated_at']) ?>
                                    </div>
                                    <?php if (!empty($rc['decided_category'])): ?>
                                        <div style="font-size: 11px; color: #34d399; background: rgba(16,185,129,0.1); padding: 2px 6px; border-radius: 4px; display: inline-block; font-weight: 600; margin-top: 6px;">
                                            Decided Penalty: Category <?= (int)$rc['decided_category'] ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>

                        <?php if (!empty($otherPendingCases)): ?>
                        <!-- TAB 3: OTHER PENDING -->
                        <div id="tab-other-pending" class="breakdown-tab-content" style="display: none;">
                            <?php foreach ($otherPendingCases as $pc):
                                $isMajorPending = ((int)($pc['major_count'] ?? 0)) > 0;
                                $pendingLvlBadge = $isMajorPending 
                                    ? '<span class="badge badge-blue" style="font-size: 10px;">MAJOR OFFENSE</span>'
                                    : '<span class="badge badge-amber" style="font-size: 10px;">MINOR / SECTION 4</span>';
                            ?>
                                <div style="background: rgba(245, 158, 11, 0.04); border: 1px solid rgba(245, 158, 11, 0.2); border-radius: 8px; padding: 12px; margin-bottom: 10px;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 4px;">
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                            <a href="case_view.php?id=<?= (int)$pc['case_id'] ?>" style="font-weight: 700; color: #fbbf24; font-size: 13px; text-decoration: underline;">
                                                Case #<?= htmlspecialchars((string)$pc['case_id']) ?> &rarr;
                                            </a>
                                            <?= $pendingLvlBadge ?>
                                        </div>
                                        <span class="badge badge-amber" style="font-size: 10px;">
                                            <?= htmlspecialchars(str_replace('_', ' ', (string)$pc['status'])) ?>
                                        </span>
                                    </div>
                                    <div class="case-details-blur">
                                        <div style="font-weight: 600; color: var(--text-main); font-size: 13px; margin-bottom: 2px;">
                                            <?= htmlspecialchars((string)($pc['offense_names'] ?: 'General Violation')) ?>
                                        </div>
                                        <div style="font-size: 11px; color: var(--text-muted);">
                                            Code: <?= htmlspecialchars((string)($pc['offense_codes'] ?: 'N/A')) ?> &bull; Created <?= fmt_dt((string)$pc['created_at']) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- LIVE CHAT -->
            <div class="glass-panel" id="chat-room">
                <div class="panel-header">
                    <div class="panel-title">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                        Panel Deliberation Discussion
                    </div>
                </div>
                <div class="panel-body" style="display:flex;flex-direction:column;padding:0">
                    <?php if (!$confidentialityAccepted): ?>
                        <div class="lock" style="margin:20px">Accept confidentiality to participate in panel discussion.</div>
                    <?php else: ?>
                        <div id="live-chat-box" style="height:320px;overflow-y:auto;background:rgba(0,0,0,.15);border:1px solid var(--border-glass);border-radius:8px;padding:12px;margin:16px 24px 0">
                            <div style="text-align:center;color:var(--text-muted);font-size:11px">Loading discussion history...</div>
                        </div>
                        <div id="replying-to-container" style="display:none;background:rgba(56,189,248,.1);padding:6px 24px;border-top:1px solid rgba(56,189,248,.2);font-size:11px;color:#7dd3fc">
                            <strong>Replying to <span id="reply-to-name"></span>:</strong>
                            <span id="reply-to-text" style="color:var(--text-muted)"></span>
                            <button type="button" class="btn btn-secondary" onclick="cancelReply()" style="float:right;padding:2px 6px;font-size:10px;">✕</button>
                        </div>
                        <form id="chat-form" style="padding:16px 24px 20px">
                            <input type="hidden" id="reply_to" name="reply_to" value="">
                            <input type="hidden" name="action" value="post_message">
                            <input type="hidden" name="case_id" value="<?= $caseId ?>">
                            <?php $isHearingOpen = ((int)$case['hearing_is_open'] === 1); ?>
                            <div class="field">
                                <textarea id="chat_message" name="message" class="fld-input" 
                                    placeholder="<?= $isHearingOpen && !$isHearingPaused ? 'Enter comment or note for panel...' : ($isHearingPaused ? 'Discussion locked — hearing paused' : 'Discussion locked until hearing opens...') ?>" 
                                    required style="min-height:54px" <?= (!$isHearingOpen || $isHearingPaused) ? 'disabled' : '' ?> id="chat_message_input"></textarea>
                            </div>
                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px">
                                <button class="btn btn-primary" type="submit" <?= (!$isHearingOpen || $isHearingPaused) ? 'disabled' : '' ?> id="chat_submit_btn">Post Discussion Message</button>
                                <a class="btn btn-secondary" href="#decision-panel">Jump to Penalty Voting</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- RIGHT COLUMN -->
        <aside class="stack">

            <!-- DECISION PANEL INFO -->
            <div class="glass-panel" id="decision-panel">
                <div class="panel-header">
                    <div class="panel-title">
                        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                        Decision Framework
                    </div>
                </div>
                <div class="panel-body">
                    <div class="decision-box">
                        <div style="font-family:var(--font-h);font-size:13.5px;font-weight:700;color:var(--text-main)"><?= htmlspecialchars($decisionHint) ?></div>
                        <div style="font-size:12.5px;color:var(--text-muted);line-height:1.4">
                            <?= $isSection4
                                ? 'Escalation from accumulated minor offenses under Student Code Section 4.'
                                : 'Select penalty category matching the offense severity and established precedents.' ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PANEL MEMBERS LIST -->
            <div class="glass-panel">
                <div class="panel-header">
                    <div>
                        <div class="panel-title">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                            Panel Composition
                        </div>
                    </div>
                </div>
                <div class="panel-body" style="display:grid;gap:8px">
                    <?php if (empty($panelMembers)): ?>
                        <div class="empty">No assigned panel members found.</div>
                    <?php else: foreach ($panelMembers as $m):
                        $uid = (int)$m['upcc_id'];
                        $isSug = $uid === $suggesterId && $isRoundActive;
                        $vote  = $votesByMember[$uid] ?? null;
                    ?>
                        <div class="info" style="padding:10px 14px">
                            <div class="info-value" style="font-size:13.5px"><?= htmlspecialchars($m['full_name']) ?> <?= $uid === $panelId ? '<small style="color:var(--text-muted)">(You)</small>' : '' ?></div>
                            <div style="color:var(--text-muted);font-size:11px;margin-top:2px"><?= htmlspecialchars(ucfirst($m['role'])) ?></div>
                            <div style="font-size:11.5px;margin-top:4px">
                                <?php if ($isSug): ?>
                                    <span style="color:#38bdf8;font-weight:600;">Proposed Penalty</span>
                                <?php elseif ($vote !== null && $isRoundActive): ?>
                                    <?= $vote > 0 ? '<span style="color:#34d399;font-weight:600;">Agreed</span>' : '<span style="color:#f87171;font-weight:600;">Disagreed</span>' ?>
                                <?php elseif ($isRoundActive): ?>
                                    <span style="color:#fbbf24">Pending Vote</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- VOTING / SUGGESTION SECTION -->
            <div class="glass-panel" id="voting-section">
                <div class="panel-header">
                    <div>
                        <div class="panel-title">
                            <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
                            Penalty Consensus Voting
                        </div>
                        <div style="color:var(--text-muted);font-size:11.5px;margin-top:2px">Unanimous panel agreement required</div>
                    </div>
                </div>
                <div class="panel-body">

                    <?php if (!$confidentialityAccepted): ?>
                        <div class="lock">Accept confidentiality agreement to access penalty voting.</div>

                    <?php elseif ($isAwaitingAdmin): ?>
                        <!-- CONSENSUS REACHED -->
                        <div class="consensus-banner">
                            <div class="consensus-banner-title">PANEL CONSENSUS ESTABLISHED</div>
                            <div style="font-size:18px;font-weight:700;color:#34d399;margin:4px 0">Category <?= $consensusCategory ?></div>
                            <div style="font-size:12px;color:var(--text-muted)">Awaiting Administrator to record final sanction.</div>
                        </div>
                        <div class="info" style="margin-bottom:14px">
                            <div class="info-label">Agreed Sanction Specification</div>
                            <div style="font-size:12.5px;line-height:1.4;margin-top:4px"><?= htmlspecialchars($categoryDescriptions[$consensusCategory] ?? '') ?></div>
                            <?php
                            $cda = $case['hearing_vote_suggested_details'] ? json_decode($case['hearing_vote_suggested_details'], true) : null;
                            ?>
                            <?php if ($consensusCategory === 1 && !empty($cda['probation_terms'])): ?>
                                <div style="margin-top:6px;font-size:12px;color:var(--text-muted)">Probation Period: <?= (int)$cda['probation_terms'] ?> term(s)</div>
                            <?php endif; ?>
                            <?php if ($consensusCategory === 2 && !empty($cda['interventions'])): ?>
                                <div style="margin-top:6px;font-size:12px;color:var(--text-muted)">
                                    Interventions: <?= htmlspecialchars(implode(', ', $cda['interventions'])) ?>
                                    <?php if (!empty($cda['service_hours'])): ?>(<?= _formatCsHoursStr($cda['service_hours']) ?>)<?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($cda['description'])): ?>
                                <div style="margin-top:8px;padding-top:8px;border-top:1px solid var(--border-glass);font-size:12.5px;line-height:1.4"><?= nl2br(htmlspecialchars($cda['description'])) ?></div>
                            <?php endif; ?>
                        </div>

                    <?php elseif ($isRoundActive && $suggestedDetails): ?>
                        <!-- ACTIVE ROUND -->
                        <div class="vote-tally">
                            <div class="tally-item tally-agree">
                                <label>Agree</label><span id="panelAgree"><?= $agreeVotes ?></span>
                            </div>
                            <div class="tally-item tally-disagree">
                                <label>Disagree</label><span id="panelDisagree"><?= $disagreeVotes ?></span>
                            </div>
                            <div class="tally-item tally-pending">
                                <label>Pending</label><span id="panelPending"><?= $totalVoters - $agreeVotes - $disagreeVotes ?></span>
                            </div>
                        </div>
                        <div style="text-align:center;font-size:11px;color:var(--text-muted);margin-bottom:10px">
                            Full panel consensus required<br>
                            <button type="button" class="btn btn-secondary" onclick="openVotingModalForRound(<?= $roundNo ?>)" style="margin-top:4px;padding:4px 8px;font-size:11px;">Open Voting Window</button>
                        </div>

                        <div class="suggestion-box">
                            <div class="suggestion-category">Category <?= $suggestedDetails['category'] ?></div>
                            <div style="font-size:12px;color:var(--text-muted);margin-bottom:4px">Proposed by <strong style="color:var(--text-main)"><?= htmlspecialchars($suggesterName) ?></strong></div>
                            <?php _renderSugDetails($suggestedDetails); ?>
                        </div>

                        <?php if ($showCancelSuggestion): ?>
                            <form method="post" onsubmit="return confirm('Cancel your proposed penalty?')">
                                <input type="hidden" name="action" value="cancel_suggestion">
                                <input type="hidden" name="round_no" value="<?= $roundNo ?>">
                                <button type="submit" class="btn btn-danger" style="width:100%">Cancel Proposal</button>
                            </form>
                            <div style="text-align:center;font-size:11px;color:var(--text-muted);margin-top:6px">Awaiting votes from other panel members</div>
                        <?php elseif ($showVoteButtons): ?>
                            <div class="vote-btns">
                                <form method="post">
                                    <input type="hidden" name="action" value="vote_on_suggestion">
                                    <input type="hidden" name="round_no" value="<?= $roundNo ?>">
                                    <input type="hidden" name="suggested_by" value="<?= $suggesterId ?>">
                                    <input type="hidden" name="vote_agree" value="1">
                                    <button class="btn-vote-agree" type="submit">AGREE</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="vote_on_suggestion">
                                    <input type="hidden" name="round_no" value="<?= $roundNo ?>">
                                    <input type="hidden" name="suggested_by" value="<?= $suggesterId ?>">
                                    <input type="hidden" name="vote_agree" value="0">
                                    <button class="btn-vote-disagree" type="submit">DISAGREE</button>
                                </form>
                            </div>
                        <?php elseif ($isRoundActive): ?>
                            <div class="voted-confirmation">
                                <?= $currentMemberVote > 0 ? 'You voted <strong>AGREE</strong>' : 'You voted <strong>DISAGREE</strong>' ?>
                                <div style="font-size:11px;margin-top:2px;color:var(--text-muted)">Waiting for remaining panel votes...</div>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- FORM TO SUGGEST PENALTY -->
                        <?php if ($isInCooldown): ?>
                            <div class="cooldown-alert">
                                Cooldown active &bull; New proposal available in:
                                <strong id="cooldownDisplay"><?= sprintf('%02d:%02d', floor($cooldownRemainingSecs / 60), $cooldownRemainingSecs % 60) ?></strong>
                            </div>
                        <?php endif; ?>

                        <?php if (!$isInCooldown && !$isClosed): ?>
                            <details id="suggestDetails" style="margin-top:4px">
                                <summary id="suggestDetailsSummary" style="cursor:pointer;color:var(--accent-primary);font-weight:600;padding:6px 0;font-size:13.5px">
                                    + Propose Penalty Category
                                </summary>
                                <form method="post" style="margin-top:14px" id="suggestForm">
                                    <input type="hidden" name="action" value="suggest_penalty">
                                    <div class="field" style="margin-bottom:10px">
                                        <label>Select Penalty Category</label>
                                        <select id="suggest_category" name="suggest_category" class="fld-input" required onchange="toggleSugFields()">
                                            <option value="">Choose category...</option>
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <option value="<?= $i ?>">Category <?= $i ?> — <?= htmlspecialchars(mb_substr($categoryDescriptions[$i], 0, 50)) ?>...</option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>

                                    <!-- CAT 1 -->
                                    <div id="sugCat1" style="display:none;margin-bottom:10px;padding:12px;background:rgba(0,0,0,.2);border-radius:8px;border:1px solid var(--border-glass)">
                                        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px;font-weight:600">Probation Terms</div>
                                        <div class="field">
                                            <select name="suggest_cat1_terms" class="fld-input">
                                                <option value="1">1 term</option>
                                                <option value="2">2 terms</option>
                                                <option value="3" selected>3 terms (maximum)</option>
                                            </select>
                                        </div>
                                    </div>

                                    <!-- CAT 2 -->
                                    <div id="sugCat2" style="display:none;margin-bottom:10px;padding:12px;background:rgba(0,0,0,.2);border-radius:8px;border:1px solid var(--border-glass)">
                                        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px;font-weight:600">Formative Interventions</div>
                                        <label style="font-size:12.5px;display:flex;align-items:center;gap:6px;margin-bottom:6px;cursor:pointer">
                                            <input type="checkbox" id="sug_us" name="suggest_cat2_university_service" value="1" onchange="toggleSugHours()">
                                            University Service (Community Service)
                                        </label>
                                        <div id="sugHoursBox" style="display:none;margin-left:18px;margin-bottom:6px">
                                            <label style="font-size:11px;color:var(--text-muted)">Required Hours</label>
                                            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:4px" id="sugHoursBtns">
                                                <?php foreach ([100,150,200,250,300,350,400,450,500] as $h): ?>
                                                    <button type="button" class="sug-hrs-btn" data-h="<?= $h ?>"
                                                        onclick="selectSugHours('<?= $h ?>', this)"
                                                        style="padding:4px 10px;font-size:11.5px;border-radius:6px;border:1px solid var(--border-glass);background:rgba(0,0,0,.2);color:var(--text-muted);cursor:pointer;">
                                                        <?= $h ?> hrs
                                                    </button>
                                                <?php endforeach; ?>
                                                    <button type="button" class="sug-hrs-btn" data-h="OTHER"
                                                        onclick="selectSugHours('OTHER', this)"
                                                        style="padding:4px 10px;font-size:11.5px;border-radius:6px;border:1px solid var(--border-glass);background:rgba(0,0,0,.2);color:var(--text-muted);cursor:pointer;">
                                                        Other
                                                    </button>
                                            </div>
                                            <div id="sug_cat2_custom_wrap" style="display:none;align-items:center;gap:6px;margin-top:6px">
                                                <input type="number" id="sug_cat2_service_hours_custom_h" name="suggest_cat2_service_hours_custom_h" min="0" step="1" placeholder="Hours" style="width:70px;padding:6px;border-radius:6px;background:rgba(0,0,0,.25);color:var(--text-main);border:1px solid var(--border-glass);font-size:12px">
                                                <span style="color:var(--text-muted);font-size:11px">hrs</span>
                                                <input type="number" id="sug_cat2_service_hours_custom_m" name="suggest_cat2_service_hours_custom_m" min="0" max="59" step="1" placeholder="Mins" style="width:70px;padding:6px;border-radius:6px;background:rgba(0,0,0,.25);color:var(--text-main);border:1px solid var(--border-glass);font-size:12px">
                                                <span style="color:var(--text-muted);font-size:11px">mins</span>
                                            </div>
                                            <input type="hidden" id="sug_cat2_service_hours" name="suggest_cat2_service_hours" value="">
                                        </div>
                                        <label style="font-size:12.5px;display:flex;align-items:center;gap:6px;margin-bottom:6px;cursor:pointer">
                                            <input type="checkbox" name="suggest_cat2_counseling" value="1"> Referral for Counseling
                                        </label>
                                        <label style="font-size:12.5px;display:flex;align-items:center;gap:6px;margin-bottom:6px;cursor:pointer">
                                            <input type="checkbox" name="suggest_cat2_lectures" value="1"> Discipline Education Program
                                        </label>
                                        <label style="font-size:12.5px;display:flex;align-items:center;gap:6px;cursor:pointer">
                                            <input type="checkbox" name="suggest_cat2_evaluation" value="1"> Psychological Evaluation
                                        </label>
                                    </div>

                                    <!-- CAT 3/4/5 -->
                                    <div id="sugCat345" style="display:none;margin-bottom:10px;padding:12px;background:rgba(239,68,68,0.08);border-radius:8px;border:1px solid rgba(239,68,68,0.2)">
                                        <div style="font-size:12px;color:#f87171;line-height:1.4" id="sugCat345Text"></div>
                                        <div style="margin-top:6px;font-size:11px;color:#f87171;font-weight:600">Student account will be locked upon decision finalization.</div>
                                    </div>

                                    <div class="field" style="margin-bottom:12px">
                                        <label>Sanction Rationale / Rationale Statement</label>
                                        <textarea name="suggest_description" class="fld-input" rows="3" placeholder="Provide justification based on case facts..."></textarea>
                                    </div>

                                    <button id="suggestSubmitBtn" type="submit" class="btn btn-primary" style="width:100%">Submit Penalty Proposal</button>
                                    <div id="suggestLockNote" style="display:none;margin-top:6px;font-size:11px;color:#fbbf24;text-align:center"></div>
                                </form>
                            </details>
                        <?php elseif ($isClosed): ?>
                            <div class="empty">This case is resolved and closed.</div>
                        <?php endif; ?>
                    <?php endif; ?>

                </div>
            </div>

        </aside>
    </div>
</main>
</div>

<?php
function _renderSugDetails(array $sd): void {
    $cat     = $sd['category'];
    $details = $sd['details'] ?? [];
    if ($cat === 1 && !empty($details['probation_terms'])):
        echo '<div style="font-size:12.5px;color:var(--text-sub);margin-bottom:4px">Probation: <strong>' . (int)$details['probation_terms'] . ' term(s)</strong></div>';
    endif;
    if ($cat === 2 && !empty($details['interventions'])):
        echo '<div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:4px">';
        foreach ($details['interventions'] as $iv) {
            echo '<span class="sug-tag">' . htmlspecialchars($iv) . '</span>';
        }
        if (!empty($details['service_hours'])) {
            $shVal = (float)$details['service_hours'];
            $hPart = floor($shVal);
            $mPart = round(($shVal - $hPart) * 60);
            if ($mPart >= 60) {
                $hPart += 1;
                $mPart = 0;
            }
            $parts = [];
            if ($hPart > 0) {
                $parts[] = $hPart . ' hr' . ($hPart > 1 ? 's' : '');
            }
            if ($mPart > 0) {
                $parts[] = $mPart . ' min' . ($mPart > 1 ? 's' : '');
            }
            $shText = !empty($parts) ? implode(' ', $parts) : '0 hrs';
            echo '<span class="sug-tag">' . htmlspecialchars($shText) . '</span>';
        }
        echo '</div>';
    endif;
    if (!empty($details['description'])):
        echo '<div class="sug-note">' . nl2br(htmlspecialchars($details['description'])) . '</div>';
    endif;
}
?>

<!-- PRESENCE OVERLAY -->
<div id="presenceOverlay" class="presence-overlay" style="display:none">
    <div class="presence-card" style="background:#0f172a; border:1px solid var(--border-glass-hover); border-radius:16px; padding:32px; text-align:center; max-width:400px; width:100%;">
        <div id="presenceTitle" style="font-family:var(--font-h);font-size:22px;font-weight:700;margin-bottom:8px">Hearing Waiting Room</div>
        <div id="presenceText" style="font-size:13.5px;line-height:1.5;color:var(--text-muted)">Please wait for the administrator to admit you to the live session.</div>
        <div style="margin-top:20px;display:flex;gap:10px;flex-direction:column">
            <button id="requestJoinBtn" class="btn btn-primary" style="width:100%;justify-content:center;display:none" onclick="requestJoinHearing()">Request Admission</button>
            <button id="exitHearingBtn" class="btn btn-secondary" style="width:100%;justify-content:center;display:none" onclick="exitHearing()">Return to Dashboard</button>
        </div>
    </div>
</div>

<!-- VOTING MODAL -->
<div id="votingModal" class="voting-modal <?= $showVotingPopup ? 'open' : '' ?>">
    <div class="vmc" id="vmcInner">

        <div class="vmc-header">
            <div class="vmc-title">Live Penalty Consensus Voting</div>
            <span class="vmc-live-badge" id="vmcLiveBadge">Live Session</span>
        </div>
        <div class="vmc-sub" id="vmcSub">
            <?php if ($isCurrentUserSuggester): ?>
                Your penalty proposal is active. Waiting for remaining panel votes.
            <?php else: ?>
                <strong><?= htmlspecialchars($suggesterName ?? '') ?></strong> submitted a penalty proposal. Please review and cast your vote before the timer expires.
            <?php endif; ?>
        </div>

        <!-- Timer -->
        <div class="timer-wrap">
            <div class="timer-top">
                <span class="timer-label">Time Remaining</span>
                <span class="timer-num" id="vmcTimer"><?= sprintf('%02d:%02d', floor($roundSecondsRemaining / 60), $roundSecondsRemaining % 60) ?></span>
            </div>
            <div class="timer-bar-wrap">
                <div class="timer-bar-fill" id="vmcTimerBar"
                     style="width:<?= $roundSecondsRemaining > 0 ? round(($roundSecondsRemaining / 600) * 100) : 0 ?>%;
                            background:<?= $roundSecondsRemaining > 600 ? '#10b981' : ($roundSecondsRemaining > 180 ? '#f59e0b' : '#ef4444') ?>"></div>
            </div>
        </div>

        <!-- Proposal box -->
        <div class="sug-box" id="vmcSugBox">
            <?php if ($suggestedDetails): ?>
                <div class="sug-cat">Category <?= $suggestedDetails['category'] ?></div>
                <div style="font-size:12.5px;color:var(--text-sub);margin-bottom:6px"><?= htmlspecialchars($categoryDescriptions[$suggestedDetails['category']] ?? '') ?></div>
                <?php _renderSugDetails($suggestedDetails); ?>
            <?php endif; ?>
        </div>

        <!-- Tally -->
        <div class="tally-row">
            <div class="tally-cell tc-agree"><label>Agree</label><span id="vmcAgree"><?= $agreeVotes ?></span></div>
            <div class="tally-cell tc-disagree"><label>Disagree</label><span id="vmcDisagree"><?= $disagreeVotes ?></span></div>
            <div class="tally-cell tc-pending"><label>Pending</label><span id="vmcPending"><?= $totalVoters - $agreeVotes - $disagreeVotes ?></span></div>
        </div>
        <div class="tally-note" id="vmcNote">All <?= $totalVoters ?> panel voter(s) must agree to pass</div>

        <!-- Per-member vote list -->
        <div class="voter-list" id="vmcVoterList">
            <?php foreach ($panelMembers as $m):
                $uid    = (int)$m['upcc_id'];
                $isSug  = $uid === $suggesterId;
                $vote   = $votesByMember[$uid] ?? null;
                $cls    = '';
                if (!$isSug && $vote !== null) $cls = $vote > 0 ? 'v-agree' : 'v-disagree';
            ?>
                <div class="voter-item <?= $cls ?><?= $isSug ? ' v-suggester' : '' ?>" id="voter-<?= $uid ?>">
                    <div>
                        <div class="voter-name"><?= htmlspecialchars($m['full_name']) ?><?= $uid === $panelId ? ' <small style="color:var(--text-muted)">(You)</small>' : '' ?></div>
                        <div class="voter-meta"><?= htmlspecialchars(ucfirst($m['role'])) ?></div>
                    </div>
                    <div id="vpill-<?= $uid ?>">
                        <?php if ($isSug): ?>
                            <span class="v-pill suggester">Proposer</span>
                        <?php elseif ($vote === null): ?>
                            <span class="v-pill pending">Pending</span>
                        <?php elseif ($vote > 0): ?>
                            <span class="v-pill agree">Agreed</span>
                        <?php else: ?>
                            <span class="v-pill disagree">Disagreed</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Result flash -->
        <div class="result-flash<?= (!empty($voteFlash) && ($voteFlash['type'] ?? '') === 'disagree') ? ' disagreed' : (!empty($voteFlash) ? ' consensus' : '') ?>" id="vmcResult" style="<?= !empty($voteFlash) ? 'display:block;' : '' ?>">
            <?= htmlspecialchars((string)($voteFlash['message'] ?? '')) ?>
        </div>

        <!-- Action buttons -->
        <div id="vmcActions">
            <?php if ($isRoundActive): ?>
                <?php if ($isCurrentUserSuggester): ?>
                    <form method="post" onsubmit="return confirm('Cancel your proposed penalty?')">
                        <input type="hidden" name="action" value="cancel_suggestion">
                        <input type="hidden" name="round_no" value="<?= $roundNo ?>">
                        <button type="submit" class="btn btn-danger" style="width:100%;padding:12px;font-size:14px">Cancel Proposal</button>
                    </form>
                    <div style="text-align:center;font-size:11px;color:var(--text-muted);margin-top:6px">You proposed this penalty. Awaiting votes from remaining panel.</div>
                    <div style="text-align:center;margin-top:10px;">
                        <button type="button" class="btn btn-secondary" onclick="closeVotingModal()" style="padding:6px 12px;font-size:12px;">Hide Window</button>
                    </div>
                <?php elseif (!$hasVoted): ?>
                    <div class="vote-btn-row">
                        <form method="post">
                            <input type="hidden" name="action" value="vote_on_suggestion">
                            <input type="hidden" name="round_no" value="<?= $roundNo ?>">
                            <input type="hidden" name="suggested_by" value="<?= $suggesterId ?>">
                            <input type="hidden" name="vote_agree" value="1">
                            <button type="submit" class="btn-agree">AGREE</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="action" value="vote_on_suggestion">
                            <input type="hidden" name="round_no" value="<?= $roundNo ?>">
                            <input type="hidden" name="suggested_by" value="<?= $suggesterId ?>">
                            <input type="hidden" name="vote_agree" value="0">
                            <button type="submit" class="btn-disagree">DISAGREE</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="voted-conf">
                        <?= $currentMemberVote > 0 ? 'You voted <strong>AGREE</strong>' : 'You voted <strong>DISAGREE</strong>' ?>
                        <small style="display:block;margin-top:2px;">Waiting for remaining panel votes...</small>
                        <div style="margin-top:10px;">
                            <button type="button" class="btn btn-secondary" onclick="closeVotingModal()" style="padding:6px 12px;font-size:12px;">Hide Window</button>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

    </div>
</div>

<script>
let pauseModalOpen = false;
let isWaitingForAdminState = false;

function showPauseModal(reason) {
    pauseModalOpen = true;
    const m = document.getElementById('hearingPausedModal');
    const r = document.getElementById('pauseReasonText');
    if (r) r.textContent = reason === 'AUTO_PAUSE_ADMIN_LEFT' ? 'The administrator disconnected from the hearing.' : 'The administrator has paused the hearing.';
    if (m) {
        m.style.display = 'flex';
        if (isWaitingForAdminState) {
            setPauseWaitingState();
        } else {
            setPauseOptionsState();
        }
    }
}

function setPauseWaitingState() {
    isWaitingForAdminState = true;
    const optionsState = document.getElementById('pauseModalStateOptions');
    const waitingState = document.getElementById('pauseModalStateWaiting');
    if (optionsState) optionsState.style.display = 'none';
    if (waitingState) waitingState.style.display = 'block';
}

function setPauseOptionsState() {
    isWaitingForAdminState = false;
    const optionsState = document.getElementById('pauseModalStateOptions');
    const waitingState = document.getElementById('pauseModalStateWaiting');
    if (optionsState) optionsState.style.display = 'block';
    if (waitingState) waitingState.style.display = 'none';
}

function closePauseModal() {
    pauseModalOpen = false;
    isWaitingForAdminState = false;
    const m = document.getElementById('hearingPausedModal');
    if (m) {
        m.style.display = 'none';
    }
    setPauseOptionsState();
}
</script>

<!-- COOLDOWN MODAL -->
<div id="cooldownModal" style="position:fixed;inset:0;z-index:9100;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.8);backdrop-filter:blur(10px);padding:16px">
    <div style="background:#0f172a;border:1px solid var(--border-glass-hover);border-radius:var(--radius-lg);padding:32px;max-width:400px;width:100%;text-align:center;">
        <div style="font-family:var(--font-h);font-size:20px;font-weight:700;margin-bottom:6px;color:var(--text-main)">Proposal Cooldown Active</div>
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:16px;line-height:1.5" id="cooldownModalReason">
            Voting ended. A new proposal can be submitted after:
        </div>
        <div style="font-family:var(--font-h);font-size:40px;font-weight:700;color:#fbbf24;font-variant-numeric:tabular-nums;margin-bottom:6px" id="cooldownModalTimer">3:00</div>
        <div style="font-size:12px;color:var(--text-muted)">Panel will be unlocked automatically</div>
    </div>
</div>

<!-- AWAITING ADMIN MODAL -->
<div id="awaitingAdminModal" style="position:fixed;inset:0;z-index:9200;display:<?= $isAwaitingAdmin ? 'flex' : 'none' ?>;align-items:center;justify-content:center;background:rgba(0,0,0,.8);backdrop-filter:blur(8px);padding:24px">
    <div style="background:#0f172a;border:1px solid rgba(16,185,129,.3);border-radius:var(--radius-lg);padding:36px;text-align:center;max-width:420px;width:100%;">
        <div style="font-family:var(--font-h);font-size:22px;font-weight:700;color:#34d399;margin-bottom:10px">Awaiting Administrator Action</div>
        <div style="font-size:13.5px;color:var(--text-muted);line-height:1.5">
            The panel has reached a final penalty consensus.<br><br>
            The administrator is reviewing and recording the decision. You will be notified when finalized.
        </div>
    </div>
</div>

<!-- CASE RESOLVED MODAL -->
<div id="caseResolvedModal" style="position:fixed;inset:0;z-index:9300;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.8);backdrop-filter:blur(10px);padding:24px">
    <div style="background:#0f172a;border:1px solid rgba(56,189,248,.3);border-radius:var(--radius-lg);padding:36px;text-align:center;max-width:420px;width:100%;position:relative">
        <button onclick="dismissResolvedModal()" style="position:absolute;top:12px;right:12px;background:none;border:none;color:var(--text-muted);font-size:20px;cursor:pointer;padding:4px 8px;line-height:1" title="Dismiss">✕</button>
        <div style="font-family:var(--font-h);font-size:22px;font-weight:700;color:#38bdf8;margin-bottom:10px">Case Decision Finalized</div>
        <div style="font-size:13.5px;color:var(--text-muted);line-height:1.5;margin-bottom:20px">
            The administrator has recorded the final decision. This case is now resolved and closed.
        </div>
        <a href="upccdashboard.php" class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;font-size:14px">Return to Dashboard</a>
        <div style="font-size:11px;color:var(--text-muted);margin-top:10px">Auto-redirecting in <span id="resolvedCountdown">5</span>s...</div>
    </div>
</div>

<!-- HEARING PAUSED MODAL -->
<div id="hearingPausedModal" style="position:fixed;inset:0;z-index:9200;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.8);backdrop-filter:blur(10px);padding:24px">
    <!-- State 1 -->
    <div id="pauseModalStateOptions" style="background:#0f172a;border:1px solid rgba(239,68,68,.3);border-radius:var(--radius-lg);padding:36px;text-align:center;max-width:460px;width:100%;">
        <div style="font-family:var(--font-h);font-size:22px;font-weight:700;color:#f87171;margin-bottom:8px">Hearing Paused</div>
        <div style="font-size:13px;color:var(--text-muted);line-height:1.5;margin-bottom:20px">
            <p id="pauseReasonText" style="margin:0 0 10px">The administrator has paused the hearing.</p>
            <p style="margin:0;font-size:12px;font-style:italic">You may remain in waiting mode until the hearing resumes, or return to your dashboard.</p>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px">
            <button type="button" class="btn btn-primary" onclick="setPauseWaitingState();" style="padding:12px;font-size:13.5px;">Remain in Waiting Room</button>
            <button type="button" class="btn btn-secondary" onclick="exitHearing()" style="padding:12px;font-size:13.5px;">Return to Dashboard</button>
        </div>
        <div style="padding:10px;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.25);border-radius:8px;font-size:12px;color:#34d399">
            Session connection is preserved while waiting.
        </div>
    </div>

    <!-- State 2 -->
    <div id="pauseModalStateWaiting" style="display:none;background:#0f172a;border:1px solid rgba(56,189,248,.3);border-radius:var(--radius-lg);padding:36px;text-align:center;max-width:460px;width:100%;">
        <div style="font-family:var(--font-h);font-size:22px;font-weight:700;color:#38bdf8;margin-bottom:8px">Waiting for Administrator...</div>
        <div style="font-size:13px;color:var(--text-muted);line-height:1.5;margin-bottom:18px">
            <p style="margin:0 0 8px">You are in the hearing waiting room.</p>
            <p style="margin:0;font-size:12px;color:var(--text-sub)">The hearing will automatically resume once unlocked by the administrator.</p>
        </div>
        <button type="button" class="btn btn-secondary" onclick="exitHearing()" style="width:100%;padding:12px;font-size:13.5px;">Return to Dashboard</button>
    </div>
</div>

<!-- Confirm Exit Hearing Modal -->
<div id="confirmExitHearingModal" style="position:fixed;inset:0;z-index:9300;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.8);backdrop-filter:blur(10px);padding:24px">
    <div style="background:#0f172a;border:1px solid rgba(239,68,68,.3);border-radius:var(--radius-lg);padding:32px;text-align:center;max-width:380px;width:100%;">
        <div style="font-family:var(--font-h);font-size:20px;font-weight:700;color:#f87171;margin-bottom:8px">Exit Hearing Session?</div>
        <div style="font-size:13px;color:var(--text-muted);line-height:1.5;margin-bottom:20px">
            If you leave the hearing, administrator approval will be required to re-enter.
        </div>
        <div style="display:flex;gap:10px;justify-content:center">
            <button type="button" class="btn btn-secondary" onclick="cancelExitHearing()">Cancel</button>
            <button type="button" class="btn btn-danger" onclick="proceedExitHearing()" id="confirmExitHearingBtn">Exit Session</button>
        </div>
    </div>
</div>

<!-- Rejoin Sent Modal -->
<div id="rejoinSentModal" style="position:fixed;inset:0;z-index:9400;display:none;align-items:center;justify-content:center;background:rgba(0,0,0,.7);backdrop-filter:blur(6px);padding:16px">
    <div style="background:#0f172a;border-radius:12px;padding:24px;max-width:360px;width:100%;text-align:center;border:1px solid var(--border-glass);">
        <div style="font-family:var(--font-h);font-size:18px;font-weight:700;margin-bottom:6px">Rejoin Request Submitted</div>
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:16px">Your request to re-enter has been transmitted. Please wait for administrator admission.</div>
        <div style="display:flex;gap:8px;justify-content:center">
            <button class="btn btn-secondary" onclick="document.getElementById('rejoinSentModal').style.display='none'">Close</button>
        </div>
    </div>
</div>

<script>
const CASE_ID          = <?= $caseId ?>;
const PANEL_ID         = <?= $panelId ?>;
const SUGGESTER_ID     = <?= $suggesterId ?>;
const IS_SUGGESTER     = <?= $isCurrentUserSuggester ? 'true' : 'false' ?>;
const TOTAL_VOTERS     = <?= $totalVoters ?>;
const IS_ROUND_ACTIVE  = <?= $isRoundActive ? 'true' : 'false' ?>;
const ROUND_NO         = <?= $roundNo ?>;
const ROUND_ENDS_EPOCH = Math.floor(Date.now() / 1000) + <?= $roundSecondsRemaining ?>;
const COOLDOWN_SECS    = <?= $cooldownRemainingSecs ?>;

let lastChatCount     = 0;
let lastVoteSig       = '';
let timerInterval     = null;
let cooldownInterval  = null;
let cooldownModalOpen = false;
let votingModalRound  = 0;
let isPartialReloading= false;
let lastRejoinTs      = parseInt(localStorage.getItem('lastRejoin_' + CASE_ID) || '0', 10);
let currentPauseState = <?= $isHearingPaused ? 'true' : 'false' ?>;
let pauseReason       = <?= !empty($case['hearing_pause_reason']) ? json_encode($case['hearing_pause_reason']) : 'null' ?>;
let resumeReloadQueued = false;

if (currentPauseState) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => showPauseModal(pauseReason));
    } else {
        showPauseModal(pauseReason);
    }
}

function startVotingTimer() {
    if (!IS_ROUND_ACTIVE || ROUND_ENDS_EPOCH <= 0) return;
    clearInterval(timerInterval);

    function tick() {
        const now     = Math.floor(Date.now() / 1000);
        const rem     = Math.max(0, ROUND_ENDS_EPOCH - now);
        const m       = Math.floor(rem / 60);
        const s       = rem % 60;
        const display = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        const pct     = Math.min(100, Math.round((rem / 600) * 100));

        const timerEl = document.getElementById('vmcTimer');
        const barEl   = document.getElementById('vmcTimerBar');
        if (timerEl) {
            timerEl.textContent = display;
            timerEl.classList.toggle('urgent', rem <= 180);
        }
        if (barEl) {
            barEl.style.width = pct + '%';
            barEl.style.background = rem > 600 ? '#10b981' : rem > 180 ? '#f59e0b' : '#ef4444';
        }

        if (rem <= 0) {
            clearInterval(timerInterval);
            syncLive();
        }
    }
    tick();
    timerInterval = setInterval(tick, 1000);
}

function startCooldownDisplay(seconds, reason) {
    let rem = seconds;
    const modal  = document.getElementById('cooldownModal');
    const timer  = document.getElementById('cooldownModalTimer');
    const reason2= document.getElementById('cooldownModalReason');
    const inline = document.getElementById('cooldownDisplay');
    if (!modal) return;
    setSuggestionLocked(true, rem);
    if (reason) reason2.textContent = reason;
    modal.style.display = 'flex';
    cooldownModalOpen = true;
    document.getElementById('votingModal')?.classList.remove('open');

    clearInterval(cooldownInterval);
    function tick() {
        const m = Math.floor(rem / 60);
        const s = rem % 60;
        const disp = m + ':' + String(s).padStart(2,'0');
        if (timer) timer.textContent = disp;
        if (inline) inline.textContent = disp;
        if (rem <= 0) {
            clearInterval(cooldownInterval);
            modal.style.display = 'none';
            cooldownModalOpen = false;
            setSuggestionLocked(false, 0);
            partialReload();
            return;
        }
        setSuggestionLocked(true, rem);
        rem--;
    }
    tick();
    cooldownInterval = setInterval(tick, 1000);
}

function setSuggestionLocked(locked, remainingSeconds) {
    const form = document.getElementById('suggestForm');
    const submitBtn = document.getElementById('suggestSubmitBtn');
    const category = document.getElementById('suggest_category');
    const note = document.getElementById('suggestLockNote');
    if (!form || !submitBtn) return;

    const controls = form.querySelectorAll('input, select, textarea, button');
    controls.forEach(el => {
        if (locked) {
            if (el.type !== 'hidden') el.setAttribute('disabled', 'disabled');
        } else {
            el.removeAttribute('disabled');
        }
    });

    form.querySelectorAll('input[type="hidden"]').forEach(el => el.removeAttribute('disabled'));

    if (locked) {
        if (category) category.value = '';
        toggleSugFields();
        submitBtn.textContent = 'Suggestion Cooldown Active';
        if (note) {
            const m = Math.floor(remainingSeconds / 60);
            const s = remainingSeconds % 60;
            const timeStr = m + ':' + String(s).padStart(2, '0');
            note.textContent = `⏳ Proposal cooldown active (${timeStr}). Please wait...`;
            note.style.display = 'block';
        }
    } else {
        submitBtn.textContent = 'Submit Penalty Proposal';
        if (note) {
            note.textContent = '';
            note.style.display = 'none';
        }
    }
}

function toggleSugFields() {
    const cat = parseInt(document.getElementById('suggest_category')?.value || '0', 10);
    const c1  = document.getElementById('sugCat1');
    const c2  = document.getElementById('sugCat2');
    const c345= document.getElementById('sugCat345');
    const txt = document.getElementById('sugCat345Text');

    if (c1)   c1.style.display   = cat === 1 ? 'block' : 'none';
    if (c2)   c2.style.display   = cat === 2 ? 'block' : 'none';
    if (c345) c345.style.display = cat >= 3 ? 'block' : 'none';

    if (cat >= 3 && txt) {
        const descs = {
            3: 'Non-Readmission / Suspension — Disciplinary exclusion for specified terms.',
            4: 'Exclusion / Mandatory Dismissal — Permanent dropping from university rolls.',
            5: 'Summary Expulsion — Permanent disqualification and legal referral.',
        };
        txt.textContent = descs[cat] || '';
    }
}

function toggleSugHours() {
    const us  = document.getElementById('sug_us');
    const box = document.getElementById('sugHoursBox');
    if (box) box.style.display = us && us.checked ? 'block' : 'none';
}

function selectSugHours(val, btn) {
    const hidden = document.getElementById('sug_cat2_service_hours');
    const customWrap = document.getElementById('sug_cat2_custom_wrap');
    document.querySelectorAll('.sug-hrs-btn').forEach(b => {
        b.style.background = 'rgba(0,0,0,.2)';
        b.style.color      = 'var(--text-muted)';
        b.style.borderColor= 'var(--border-glass)';
    });
    if (btn) {
        btn.style.background = 'rgba(56,189,248,0.15)';
        btn.style.color      = '#38bdf8';
        btn.style.borderColor= 'rgba(56,189,248,0.3)';
    }

    if (val === 'OTHER') {
        if (customWrap) customWrap.style.display = 'flex';
        if (hidden) hidden.value = 'OTHER';
    } else {
        if (customWrap) customWrap.style.display = 'none';
        if (hidden) hidden.value = val;
    }
}

function switchBreakdownTab(tabKey, clickedBtn) {
    document.querySelectorAll('.breakdown-tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.btn-tab').forEach(b => {
        b.classList.remove('active-tab-btn');
        b.style.background = 'rgba(255, 255, 255, 0.03)';
        b.style.color = 'var(--text-muted)';
        b.style.border = '1px solid var(--border-glass)';
    });

    const targetTab = document.getElementById('tab-' + tabKey);
    if (targetTab) targetTab.style.display = 'block';

    if (clickedBtn) {
        clickedBtn.classList.add('active-tab-btn');
        if (tabKey === 'current-offenses') {
            clickedBtn.style.background = 'rgba(56, 189, 248, 0.15)';
            clickedBtn.style.color = '#38bdf8';
            clickedBtn.style.border = '1px solid rgba(56, 189, 248, 0.3)';
        } else if (tabKey === 'prior-resolved') {
            clickedBtn.style.background = 'rgba(16, 185, 129, 0.15)';
            clickedBtn.style.color = '#34d399';
            clickedBtn.style.border = '1px solid rgba(16, 185, 129, 0.3)';
        } else if (tabKey === 'other-pending') {
            clickedBtn.style.background = 'rgba(245, 158, 11, 0.15)';
            clickedBtn.style.color = '#fbbf24';
            clickedBtn.style.border = '1px solid rgba(245, 158, 11, 0.3)';
        }
    }
}

function openVotingModalForRound(rNo) {
    document.getElementById('votingModal')?.classList.add('open');
}

function closeVotingModal() {
    document.getElementById('votingModal')?.classList.remove('open');
}

function replyToMessage(msgId, senderName, msgText) {
    document.getElementById('reply_to').value = msgId;
    document.getElementById('reply-to-name').textContent = senderName;
    document.getElementById('reply-to-text').textContent = msgText.substring(0, 60) + (msgText.length > 60 ? '...' : '');
    document.getElementById('replying-to-container').style.display = 'block';
    document.getElementById('chat_message').focus();
}

function cancelReply() {
    document.getElementById('reply_to').value = '';
    document.getElementById('replying-to-container').style.display = 'none';
}

function exitHearing() {
    const confirmModal = document.getElementById('confirmExitHearingModal');
    if (confirmModal) confirmModal.style.display = 'flex';
}

function cancelExitHearing() {
    const confirmModal = document.getElementById('confirmExitHearingModal');
    if (confirmModal) confirmModal.style.display = 'none';
}

function proceedExitHearing() {
    const btn = document.getElementById('confirmExitHearingBtn');
    if (btn) { btn.disabled = true; btn.textContent = 'Exiting...'; }
    window.location.href = 'upccdashboard.php';
}

function dismissResolvedModal() {
    const m = document.getElementById('caseResolvedModal');
    if (m) m.style.display = 'none';
}

// LIVE SYNC & CHAT POLLING
function syncLive() {
    if (isPartialReloading) return;
    const url = `../api/upcc_case_live.php?case_id=${CASE_ID}&actor=upcc&t=${Date.now()}`;
    fetch(url, { cache: 'no-store' })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;

            // Handle pause state
            if (data.is_paused) {
                currentPauseState = true;
                showPauseModal(data.pause_reason);
            } else if (currentPauseState && !data.is_paused) {
                currentPauseState = false;
                closePauseModal();
                window.location.reload();
            }

            // Handle chat
            if (data.chat && Array.isArray(data.chat)) {
                renderChat(data.chat);
            }

            // Handle resolved case
            if (data.status === 'CLOSED' || data.status === 'RESOLVED') {
                const resModal = document.getElementById('caseResolvedModal');
                if (resModal && resModal.style.display !== 'flex') {
                    resModal.style.display = 'flex';
                    let cnt = 5;
                    const cdEl = document.getElementById('resolvedCountdown');
                    const itv = setInterval(() => {
                        cnt--;
                        if (cdEl) cdEl.textContent = cnt;
                        if (cnt <= 0) {
                            clearInterval(itv);
                            window.location.href = 'upccdashboard.php';
                        }
                    }, 1000);
                }
            }

            // Voting signature sync
            const sig = `${data.round_no}_${data.is_round_active}_${data.agree_votes}_${data.disagree_votes}_${data.consensus_category}`;
            if (lastVoteSig !== '' && lastVoteSig !== sig) {
                partialReload();
            }
            lastVoteSig = sig;
        })
        .catch(err => console.error('Sync failed:', err));
}

function renderChat(chatList) {
    const box = document.getElementById('live-chat-box');
    if (!box) return;

    if (chatList.length === 0) {
        box.innerHTML = '<div class="empty">No discussion messages posted yet.</div>';
        return;
    }

    if (chatList.length === lastChatCount && box.children.length > 1) return;
    lastChatCount = chatList.length;

    let html = '';
    chatList.forEach(m => {
        const isSelf = parseInt(m.upcc_id, 10) === PANEL_ID;
        const name = escapeHtml(m.full_name || 'Panel Member');
        const role = escapeHtml(m.role || 'UPCC');
        const text = escapeHtml(m.message || '');
        const time = escapeHtml(m.created_at || '');

        html += `
            <div class="chat-item" style="${isSelf ? 'border-color:rgba(56,189,248,0.25);background:rgba(56,189,248,0.03)' : ''}">
                <div class="chat-head">
                    <div>
                        <span class="chat-name">${name}</span>
                        <span class="chat-role">&bull; ${role}</span>
                    </div>
                    <span class="chat-time">${time}</span>
                </div>
                <div class="chat-msg">${text}</div>
                <button type="button" class="btn btn-secondary" style="padding:2px 8px;font-size:10px;margin-top:6px;" onclick="replyToMessage(${m.message_id}, '${name}', '${text.replace(/'/g, "\\'")}')">Reply</button>
            </div>
        `;
    });

    box.innerHTML = html;
    box.scrollTop = box.scrollHeight;
}

function escapeHtml(str) {
    return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function partialReload() {
    isPartialReloading = true;
    window.location.reload();
}

// Form submit handlers & initial setup
document.addEventListener('DOMContentLoaded', () => {
    startVotingTimer();

    if (COOLDOWN_SECS > 0) {
        startCooldownDisplay(COOLDOWN_SECS, 'Proposal cooldown active.');
    }

    // Chat form submit listener
    const chatForm = document.getElementById('chat-form');
    if (chatForm) {
        chatForm.addEventListener('submit', function(e) {
            e.preventDefault();
            const msgInput = document.getElementById('chat_message');
            const msg = (msgInput?.value || '').trim();
            if (!msg) return;

            const submitBtn = document.getElementById('chat_submit_btn');
            if (submitBtn) submitBtn.disabled = true;

            const fd = new FormData(chatForm);
            fetch('case_view.php?id=' + CASE_ID, { method: 'POST', body: fd })
                .then(() => {
                    msgInput.value = '';
                    cancelReply();
                    syncLive();
                })
                .catch(err => console.error('Chat post failed:', err))
                .finally(() => {
                    if (submitBtn) submitBtn.disabled = false;
                });
        });
    }

    // Penalty suggest form loading handler
    const sugForm = document.getElementById('suggestForm');
    if (sugForm) {
        sugForm.addEventListener('submit', function() {
            const overlay = document.getElementById('globalLoadingOverlay');
            if (overlay) overlay.style.display = 'flex';
        });
    }

    // Start sync polling loop (every 3s)
    syncLive();
    setInterval(syncLive, 3000);
});
</script>
</body>
</html>