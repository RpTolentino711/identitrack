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
$case = db_one("SELECT uc.*,
        CONCAT(s.student_fn,' ',s.student_ln) AS student_name,
        s.student_fn, s.student_ln,
        s.year_level, s.section, s.program, s.school,
        s.student_email, s.phone_number, s.home_address,
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
    [':case_id' => $caseId, ':join_uid' => $panelId, ':legacy_uid' => $panelId]
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
                if ($hrs === 'OTHER') $hrs = trim((string)($_POST['suggest_cat2_service_hours_custom'] ?? ''));
                $voteDetails['service_hours'] = is_numeric($hrs) ? (int)$hrs : 0;
            }
            if (!empty($_POST['suggest_cat2_counseling']))  $voteDetails['interventions'][] = 'Referral for Counseling';
            if (!empty($_POST['suggest_cat2_lectures']))    $voteDetails['interventions'][] = 'Attendance to lectures';
            if (!empty($_POST['suggest_cat2_evaluation']))  $voteDetails['interventions'][] = 'Evaluation';
        }
        // Cat 3/4/5 — no extra input, punishment is fixed by policy
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

        // Suggester's own vote (auto-agree)
        db_exec(
            "INSERT INTO upcc_case_vote (case_id, upcc_id, round_no, vote_category, vote_details, created_at, updated_at)
             VALUES (:c, :u, :r, :vc, :vd, NOW(), NOW())
             ON DUPLICATE KEY UPDATE vote_category = VALUES(vote_category), vote_details = VALUES(vote_details), updated_at = VALUES(updated_at)",
            [':c' => $caseId, ':u' => $panelId, ':r' => $roundNo,
             ':vc' => $category, ':vd' => !empty($voteDetails) ? json_encode($voteDetails) : null]
        );

        $fullName = htmlspecialchars($user['full_name'] ?? 'Panel member');
        $catLabel = _catLabel($category, $voteDetails);
        db_exec(
            "INSERT INTO upcc_case_discussion (case_id, message, created_at, updated_at) VALUES (:c, :m, NOW(), NOW())",
            [':c' => $caseId, ':m' => "🗳️ {$fullName} suggested: {$catLabel}. All other panel members, please cast your vote now."]
        );

        $_SESSION['upcc_last_suggester_case_id'] = $caseId;
        $_SESSION['upcc_last_suggester_id']      = $panelId;

        upcc_log_case_activity($caseId, 'UPCC', $panelId, 'PENALTY_SUGGESTED', [
            'round_no' => $roundNo, 'vote_category' => $category,
        ]);

        _checkAndFinalizeConsensus($caseId, $roundNo, $assignedPanelIds);
    }
    header('Location: case_view.php?id=' . $caseId . '#decision-panel');
    exit;
}

// ── CANCEL SUGGESTION ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel_suggestion') {
    $roundNo = (int)($_POST['round_no'] ?? 0);
    if ($roundNo > 0) {
        $roundInfo = _getRoundSuggesterId($caseId, $roundNo);

        if ($roundInfo && (int)$roundInfo['suggested_by'] === $panelId) {
            db_exec("DELETE FROM upcc_suggestion_cooldown WHERE case_id = :c", [':c' => $caseId]);
            db_exec(
                "INSERT INTO upcc_suggestion_cooldown (case_id, round_no, upcc_id, cooldown_until, created_at)
                 VALUES (:c, :r, :u, DATE_ADD(NOW(), INTERVAL 3 MINUTE), NOW())",
                [':c' => $caseId, ':r' => $roundNo, ':u' => $panelId]
            );

            db_exec("DELETE FROM upcc_case_vote WHERE case_id = :c AND round_no = :r",
                [':c' => $caseId, ':r' => $roundNo]);

            _closeRound($caseId, $roundNo);

            db_exec("UPDATE upcc_case SET
                     hearing_vote_consensus_category = NULL, hearing_vote_suggested_details = NULL,
                     hearing_vote_consensus_at = NULL, hearing_vote_suggester_id = NULL,
                     status = CASE WHEN status = 'AWAITING_ADMIN_FINALIZATION' THEN 'UNDER_INVESTIGATION' ELSE status END,
                     updated_at = NOW() WHERE case_id = :c", [':c' => $caseId]);

            $fullName = htmlspecialchars($user['full_name'] ?? 'Panel member');
            db_exec(
                "INSERT INTO upcc_case_discussion (case_id, message, created_at, updated_at) VALUES (:c, :m, NOW(), NOW())",
                [':c' => $caseId, ':m' => "❌ {$fullName} cancelled the proposed penalty. Panel may submit a new suggestion after the 3-minute cooldown."]
            );
            upcc_log_case_activity($caseId, 'UPCC', $panelId, 'SUGGESTION_CANCELLED', ['round_no' => $roundNo]);
        }
    }
    header('Location: case_view.php?id=' . $caseId . '#decision-panel');
    exit;
}

// ── VOTE ON SUGGESTION ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'vote_on_suggestion') {
    file_put_contents(__DIR__ . '/../scratch_debug_post.txt', "POST started.\n", FILE_APPEND);
    $agree       = (int)($_POST['vote_agree']   ?? -1);
    $roundNo     = (int)($_POST['round_no']     ?? 0);
    $suggestedBy = (int)($_POST['suggested_by'] ?? 0);
    file_put_contents(__DIR__ . '/../scratch_debug_post.txt', "Params: agree=$agree, roundNo=$roundNo, suggestedBy=$suggestedBy\n", FILE_APPEND);

    if ($roundNo > 0 && ($agree === 0 || $agree === 1)) {

        // Confirm round active
        $roundActive = db_one(
            "SELECT is_active FROM upcc_case_vote_round WHERE case_id = :c AND round_no = :r",
            [':c' => $caseId, ':r' => $roundNo]
        );
        if (!$roundActive || (int)$roundActive['is_active'] !== 1) {
            file_put_contents(__DIR__ . '/../scratch_debug_post.txt', "Failed: round not active.\n", FILE_APPEND);
            header('Location: case_view.php?id=' . $caseId . '#decision-panel');
            exit;
        }

        // No double voting
        $existingVote = db_one(
            "SELECT vote_category FROM upcc_case_vote WHERE case_id = :c AND upcc_id = :u AND round_no = :r",
            [':c' => $caseId, ':u' => $panelId, ':r' => $roundNo]
        );
        if ($existingVote !== null) {
            file_put_contents(__DIR__ . '/../scratch_debug_post.txt', "Failed: existing vote found.\n", FILE_APPEND);
            header('Location: case_view.php?id=' . $caseId . '#decision-panel');
            exit;
        }

        // Suggester cannot vote
        $roundInfo = _getRoundSuggesterId($caseId, $roundNo);
        if ($roundInfo && (int)$roundInfo['suggested_by'] === $panelId) {
            file_put_contents(__DIR__ . '/../scratch_debug_post.txt', "Failed: suggester cannot vote. panelId=$panelId, suggested_by=" . $roundInfo['suggested_by'] . "\n", FILE_APPEND);
            header('Location: case_view.php?id=' . $caseId . '#decision-panel');
            exit;
        }

        $voteCategory = 0;
        $voteDetails  = null;
        if ($agree === 1 && $suggestedBy > 0) {
            $suggestion = db_one(
                "SELECT vote_category, vote_details FROM upcc_case_vote
                 WHERE case_id = :c AND upcc_id = :u AND round_no = :r",
                [':c' => $caseId, ':u' => $suggestedBy, ':r' => $roundNo]
            );
            if ($suggestion) {
                $voteCategory = (int)$suggestion['vote_category'];
                $voteDetails  = $suggestion['vote_details'];
            }
        }
        // agree === 0 → voteCategory = 0 (disagree marker)

        try {
            db_exec(
                "INSERT INTO upcc_case_vote (case_id, upcc_id, round_no, vote_category, vote_details, created_at, updated_at)
                 VALUES (:c, :u, :r, :vc, :vd, NOW(), NOW())
                 ON DUPLICATE KEY UPDATE vote_category = VALUES(vote_category), vote_details = VALUES(vote_details), updated_at = VALUES(updated_at)",
                [':c' => $caseId, ':u' => $panelId, ':r' => $roundNo, ':vc' => $voteCategory, ':vd' => $voteDetails]
            );
            file_put_contents(__DIR__ . '/../scratch_debug_post.txt', "Insert SUCCEEDED. Cat=$voteCategory, Details=$voteDetails\n", FILE_APPEND);
        } catch (Exception $e) {
            file_put_contents(__DIR__ . '/../scratch_debug_post.txt', "Insert FAILED: " . $e->getMessage() . "\n", FILE_APPEND);
        }

        upcc_log_case_activity($caseId, 'UPCC', $panelId, 'VOTE_ON_SUGGESTION', [
            'round_no' => $roundNo, 'agreed' => $agree, 'suggested_by' => $suggestedBy,
        ]);

        _checkAndFinalizeConsensus($caseId, $roundNo, $assignedPanelIds);
    }
    if ($agree === 1) {
        $_SESSION['upcc_vote_flash'] = [
            'type' => 'success',
            'message' => '✅ Your vote was recorded. Waiting for the other panel member(s)…',
        ];
    } else {
        $_SESSION['upcc_vote_flash'] = [
            'type' => 'disagree',
            'message' => '❌ You voted DISAGREE. The proposal was cancelled. Panel may suggest again after the cooldown.',
        ];
    }
    header('Location: case_view.php?id=' . $caseId . '#decision-panel');
    exit;
}



// ═══════════════════════════════════════════════════════════════════════════
//  HELPER FUNCTIONS
// ═══════════════════════════════════════════════════════════════════════════

function _getRoundSuggesterId(int $caseId, int $roundNo): ?array {
    global $voteRoundHasSuggestedBy;
    if ($voteRoundHasSuggestedBy) {
        return db_one(
            "SELECT suggested_by FROM upcc_case_vote_round WHERE case_id = :c AND round_no = :r AND is_active = 1",
            [':c' => $caseId, ':r' => $roundNo]
        ) ?: null;
    }
    return db_one(
        "SELECT v.upcc_id AS suggested_by
         FROM upcc_case_vote v
         JOIN upcc_case_vote_round r ON r.case_id = v.case_id AND r.round_no = v.round_no
         WHERE v.case_id = :c AND v.round_no = :r AND r.is_active = 1 AND v.vote_category > 0
         ORDER BY v.created_at ASC LIMIT 1",
        [':c' => $caseId, ':r' => $roundNo]
    ) ?: null;
}

function _closeRound(int $caseId, int $roundNo): void {
    global $voteRoundHasEndedAt;
    $sql = $voteRoundHasEndedAt
        ? "UPDATE upcc_case_vote_round SET is_active = 0, ended_at = NOW() WHERE case_id = :c AND round_no = :r"
        : "UPDATE upcc_case_vote_round SET is_active = 0 WHERE case_id = :c AND round_no = :r";
    db_exec($sql, [':c' => $caseId, ':r' => $roundNo]);
}

function _catLabel(int $cat, array $details = []): string {
    $labels = [
        1 => 'Category 1 — Probation (' . ($details['probation_terms'] ?? 3) . ' terms)',
        2 => 'Category 2 — Formative Intervention (' . implode(', ', $details['interventions'] ?? []) . ')',
        3 => 'Category 3 — Non-Readmission',
        4 => 'Category 4 — Exclusion',
        5 => 'Category 5 — Expulsion',
    ];
    return $labels[$cat] ?? "Category {$cat}";
}

/**
 * MAJORITY of panel members (including suggester) must agree.
 * If majority disagree or if it's impossible to reach majority agree, round cancelled.
 */
function _checkAndFinalizeConsensus(int $caseId, int $roundNo, array $assignedPanelIds): void {
    global $voteRoundHasSuggestedBy, $voteRoundHasEndedAt;

    $votes = db_all(
        "SELECT upcc_id, vote_category, vote_details FROM upcc_case_vote
         WHERE case_id = :c AND round_no = :r",
        [':c' => $caseId, ':r' => $roundNo]
    );

    $suggesterRow = _getRoundSuggesterId($caseId, $roundNo);
    $suggesterId  = (int)($suggesterRow['suggested_by'] ?? 0);

    $totalPanelMembers = count($assignedPanelIds);
    if ($totalPanelMembers === 0) return;

    $majorityNeeded = (int)floor($totalPanelMembers / 2) + 1;

    $agreeCount = 0;
    $disagreeCount = 0;
    $disagreeName = '';

    foreach ($votes as $v) {
        $uid = (int)$v['upcc_id'];
        $cat = (int)$v['vote_category'];
        if ($cat > 0) {
            $agreeCount++;
        } else {
            $disagreeCount++;
            if (!$disagreeName) {
                $row = db_one("SELECT full_name FROM upcc_user WHERE upcc_id = :u LIMIT 1", [':u' => $uid]);
                $disagreeName = $row['full_name'] ?? 'A panel member';
            }
        }
    }

    $totalVotesCast = count($votes);
    $remainingVoters = $totalPanelMembers - $totalVotesCast;
    $maxPossibleAgrees = $agreeCount + $remainingVoters;

    // ── MAJORITY DISAGREES OR IMPOSSIBLE TO REACH MAJORITY ──
    if ($maxPossibleAgrees < $majorityNeeded || $disagreeCount >= $majorityNeeded) {
        db_exec("DELETE FROM upcc_suggestion_cooldown WHERE case_id = :c", [':c' => $caseId]);
        db_exec(
            "INSERT INTO upcc_suggestion_cooldown (case_id, round_no, upcc_id, cooldown_until, created_at)
             VALUES (:c, :r, :u, DATE_ADD(NOW(), INTERVAL 3 MINUTE), NOW())",
            [':c' => $caseId, ':r' => $roundNo, ':u' => $suggesterId]
        );

        db_exec("DELETE FROM upcc_case_vote WHERE case_id = :c AND round_no = :r",
            [':c' => $caseId, ':r' => $roundNo]);

        _closeRound($caseId, $roundNo);

        db_exec("UPDATE upcc_case SET
                 hearing_vote_consensus_category = NULL, hearing_vote_suggested_details = NULL,
                 hearing_vote_consensus_at = NULL, hearing_vote_suggester_id = NULL,
                 status = CASE WHEN status = 'AWAITING_ADMIN_FINALIZATION' THEN 'UNDER_INVESTIGATION' ELSE status END,
                 updated_at = NOW() WHERE case_id = :c", [':c' => $caseId]);

        $disName = $disagreeName ?: 'Panel members';
        db_exec(
            "INSERT INTO upcc_case_discussion (case_id, message, created_at, updated_at) VALUES (:c, :m, NOW(), NOW())",
            [':c' => $caseId, ':m' => "❌ {$disName} voted DISAGREE — majority consensus failed. Panel may submit a new suggestion after the 3-minute cooldown."]
        );

        upcc_log_case_activity($caseId, 'SYSTEM', 0, 'VOTE_DISAGREED', [
            'round_no' => $roundNo, 'disagreed_by' => $disagreeName,
        ]);
        return;
    }

    // ── MAJORITY AGREED ──
    if ($agreeCount >= $majorityNeeded) {
        $suggestionVote = db_one(
            "SELECT vote_category, vote_details, upcc_id AS suggester_id
             FROM upcc_case_vote WHERE case_id = :c AND upcc_id = :u AND round_no = :r",
            [':c' => $caseId, ':u' => $suggesterId, ':r' => $roundNo]
        );
        if (!$suggestionVote) return;

        $consensusCategory = (int)$suggestionVote['vote_category'];
        $consensusDetails  = $suggestionVote['vote_details'];

        db_exec("UPDATE upcc_case SET
                 hearing_vote_consensus_category = :cat,
                 hearing_vote_suggested_details  = :det,
                 hearing_vote_consensus_at       = NOW(),
                 hearing_vote_suggester_id       = :sid,
                 status = 'AWAITING_ADMIN_FINALIZATION',
                 updated_at = NOW()
                 WHERE case_id = :c",
            [':cat' => $consensusCategory, ':det' => $consensusDetails,
             ':sid' => $suggesterId, ':c'   => $caseId]);

        _closeRound($caseId, $roundNo);

        db_exec(
            "INSERT INTO upcc_case_discussion (case_id, message, created_at, updated_at) VALUES (:c, :m, NOW(), NOW())",
            [':c' => $caseId, ':m' => "✅ CONSENSUS REACHED! All panel members agreed on Category {$consensusCategory}. Awaiting Admin to finalize."]
        );

        upcc_log_case_activity($caseId, 'SYSTEM', 0, 'CONSENSUS_REACHED', [
            'round_no' => $roundNo, 'vote_category' => $consensusCategory,
            'total_voters' => $totalVoters,
        ]);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
//  LOAD LIVE STATE
// ═══════════════════════════════════════════════════════════════════════════

if ($voteRoundHasSuggestedBy) {
    $activeRound = db_one(
        "SELECT r.round_no, r.started_at, r.ends_at, r.is_active,
                COALESCE(r.suggested_by, uc.hearing_vote_suggester_id,
                    (SELECT v.upcc_id FROM upcc_case_vote v
                     WHERE v.case_id = r.case_id AND v.round_no = r.round_no AND v.vote_category > 0
                     ORDER BY v.created_at ASC LIMIT 1)) AS suggested_by,
                u.full_name AS suggester_name
         FROM upcc_case_vote_round r
         LEFT JOIN upcc_case uc ON uc.case_id = r.case_id
         LEFT JOIN upcc_user u ON u.upcc_id = COALESCE(r.suggested_by, uc.hearing_vote_suggester_id,
                    (SELECT v.upcc_id FROM upcc_case_vote v
                     WHERE v.case_id = r.case_id AND v.round_no = r.round_no AND v.vote_category > 0
                     ORDER BY v.created_at ASC LIMIT 1))
         WHERE r.case_id = :c AND r.is_active = 1
         ORDER BY r.round_no DESC LIMIT 1",
        [':c' => $caseId]
    );
} else {
    $activeRound = db_one(
        "SELECT r.round_no, r.started_at, r.ends_at, r.is_active,
                (SELECT v.upcc_id FROM upcc_case_vote v WHERE v.case_id = r.case_id AND v.round_no = r.round_no AND v.vote_category > 0 ORDER BY v.created_at ASC LIMIT 1) AS suggested_by,
                (SELECT u2.full_name FROM upcc_case_vote v2 JOIN upcc_user u2 ON u2.upcc_id = v2.upcc_id WHERE v2.case_id = r.case_id AND v2.round_no = r.round_no AND v2.vote_category > 0 ORDER BY v2.created_at ASC LIMIT 1) AS suggester_name
         FROM upcc_case_vote_round r
         WHERE r.case_id = :c AND r.is_active = 1
         ORDER BY r.round_no DESC LIMIT 1",
        [':c' => $caseId]
    );
}

if ($activeRound && !empty($activeRound['ends_at']) && strtotime((string)$activeRound['ends_at']) <= time()) {
    $expiredRoundNo = (int)($activeRound['round_no'] ?? 0);
    if ($expiredRoundNo > 0) {
        _closeRound($caseId, $expiredRoundNo);
        db_exec("DELETE FROM upcc_case_vote WHERE case_id = :c AND round_no = :r", [':c' => $caseId, ':r' => $expiredRoundNo]);
        db_exec("UPDATE upcc_case SET
                 hearing_vote_consensus_category = NULL, hearing_vote_suggested_details = NULL,
                 hearing_vote_consensus_at = NULL, hearing_vote_suggester_id = NULL,
                 status = CASE WHEN status = 'AWAITING_ADMIN_FINALIZATION' THEN 'UNDER_INVESTIGATION' ELSE status END,
                 updated_at = NOW() WHERE case_id = :c", [':c' => $caseId]);
        db_exec(
            "INSERT INTO upcc_case_discussion (case_id, message, created_at, updated_at)
             VALUES (:c, :m, NOW(), NOW())",
            [':c' => $caseId, ':m' => "⌛ Voting window ended after 10 minutes with no decision. Panel may submit a new suggestion."]
        );
    }
    $activeRound = null;
}

$roundNo                  = (int)($activeRound['round_no']    ?? 0);
$isRoundActive            = $roundNo > 0 && (int)($activeRound['is_active'] ?? 0) === 1;
$suggesterId              = (int)($activeRound['suggested_by'] ?? ($case['hearing_vote_suggester_id'] ?? 0));
if ($suggesterId <= 0 && $sessionSuggesterCaseId === $caseId && $sessionSuggesterId > 0) {
    $suggesterId = $sessionSuggesterId;
}
$suggesterName            = $activeRound['suggester_name'] ?? '';
$isCurrentUserSuggester   = ($suggesterId === $panelId);
$roundEndsAt              = $activeRound['ends_at'] ?? null;
$roundSecondsRemaining    = $roundEndsAt ? max(0, strtotime($roundEndsAt) - time()) : 0;

$votesThisRound  = [];
$votesByMember   = [];
$agreeVotes      = 0;
$disagreeVotes   = 0;

if ($isRoundActive && $roundNo > 0) {
    $votesThisRound = db_all(
        "SELECT v.upcc_id, v.vote_category, v.updated_at, u.full_name
         FROM upcc_case_vote v
         LEFT JOIN upcc_user u ON u.upcc_id = v.upcc_id
         WHERE v.case_id = :c AND v.round_no = :r
         ORDER BY v.created_at ASC",
        [':c' => $caseId, ':r' => $roundNo]
    );
    foreach ($votesThisRound as $v) {
        $uid = (int)$v['upcc_id'];
        $cat = (int)$v['vote_category'];
        $votesByMember[$uid] = $cat;
        if ($uid !== $suggesterId) {
            if ($cat > 0) $agreeVotes++;
            else          $disagreeVotes++;
        }
    }
}

$currentMemberVote = isset($votesByMember[$panelId]) ? (int)$votesByMember[$panelId] : null;
$hasVoted          = $currentMemberVote !== null && !$isCurrentUserSuggester;
$showCancelSuggestion = $isRoundActive && $isCurrentUserSuggester;
$showVoteButtons      = $isRoundActive && !$isCurrentUserSuggester && !$hasVoted;

// Voters = all except suggester
$voterIds     = array_filter($assignedPanelIds, fn($id) => $id !== $suggesterId);
$totalVoters  = count($voterIds);
$allVotersIn  = $totalVoters > 0 && $agreeVotes === $totalVoters;

// Suggested details
$suggestedDetails = null;
if ($isRoundActive && $suggesterId > 0) {
    $sv = db_one(
        "SELECT vote_category, vote_details FROM upcc_case_vote
         WHERE case_id = :c AND upcc_id = :u AND round_no = :r",
        [':c' => $caseId, ':u' => $suggesterId, ':r' => $roundNo]
    );
    if ($sv) {
        $suggestedDetails = [
            'category' => (int)$sv['vote_category'],
            'details'  => $sv['vote_details'] ? json_decode((string)$sv['vote_details'], true) : [],
        ];
    }
}

// Re-fetch case
$case = db_one("SELECT uc.*, CONCAT(s.student_fn,' ',s.student_ln) AS student_name,
        s.student_fn, s.student_ln, s.year_level, s.section, s.program, s.school,
        s.student_email, s.phone_number, s.home_address, d.dept_name AS assigned_dept_name
    FROM upcc_case uc
    JOIN student s ON s.student_id = uc.student_id
    LEFT JOIN departments d ON d.dept_id = uc.assigned_department_id
    WHERE uc.case_id = :c LIMIT 1", [':c' => $caseId]);

$consensusCategory = (int)($case['hearing_vote_consensus_category'] ?? 0);
$isAwaitingAdmin   = $consensusCategory > 0 && (string)($case['status'] ?? '') === 'AWAITING_ADMIN_FINALIZATION';

// Cooldown for current user
$activeCooldown = db_one(
    "SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(cooldown_until)) AS remaining
     FROM upcc_suggestion_cooldown
     WHERE case_id = :c AND cooldown_until > NOW()",
    [':c' => $caseId]
);
$cooldownRemainingSecs = max(0, (int)($activeCooldown['remaining'] ?? 0));
$isInCooldown = $cooldownRemainingSecs > 0;

$showVotingPopup = $isRoundActive && $suggestedDetails !== null;

// ── OTHER QUERIES ─────────────────────────────────────────────────────────
$offenses = db_all(
    "SELECT o.offense_id, o.level, o.description, o.date_committed, o.status,
            ot.code, ot.name AS offense_name, ot.major_category, ot.intervention_first, ot.intervention_second
     FROM upcc_case_offense uco
     JOIN offense o   ON o.offense_id = uco.offense_id
     JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
     WHERE uco.case_id = :c ORDER BY o.date_committed ASC",
    [':c' => $caseId]
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
$isClosed        = in_array($statusRaw, ['CLOSED','RESOLVED'], true);
$isHearingOpen   = (int)($case['hearing_is_open']  ?? 0) === 1;
$isHearingPaused = (int)($case['hearing_is_paused'] ?? 0) === 1;

$myPresenceStatus = 'ADMITTED';
$presRow = db_one(
    "SELECT status FROM upcc_hearing_presence WHERE case_id = :c AND user_type = 'UPCC' AND user_id = :u",
    [':c' => $caseId, ':u' => $panelId]
);
if ($presRow) $myPresenceStatus = $presRow['status'] ?? 'ADMITTED';

$caseLabel = 'UPCC-' . date('Y', strtotime((string)$case['created_at'])) . '-' . str_pad((string)$caseId, 3, '0', STR_PAD_LEFT);
$initials  = strtoupper(substr((string)$user['full_name'], 0, 1));
$parts     = explode(' ', (string)$user['full_name']);
if (count($parts) > 1) $initials .= strtoupper(substr((string)end($parts), 0, 1));

$isSection4 = (string)($case['case_kind'] ?? '') === 'SECTION4_MINOR_ESCALATION'
    || stripos((string)($case['case_summary'] ?? ''), 'Section 4') !== false;
$hasMajorOffense = false;
foreach ($offenses as $off) {
    if (strtoupper((string)($off['level'] ?? '')) === 'MAJOR') { $hasMajorOffense = true; break; }
}
$decisionHint = $isSection4 ? 'Section 4 escalation case'
    : ($hasMajorOffense ? 'Major offense — Category 1–5 review' : 'Minor offense review');

function fmt_dt(?string $v): string {
    return $v ? date('M j, Y g:i A', strtotime($v)) : '—';
}
function decision_badge(string $s): array {
    return match(strtoupper($s)) {
        'CLOSED','RESOLVED'          => ['label' => 'Closed',              'class' => 'badge-green'],
        'AWAITING_ADMIN_FINALIZATION'=> ['label' => 'Awaiting Admin',      'class' => 'badge-purple'],
        'UNDER_INVESTIGATION'        => ['label' => 'Under Investigation', 'class' => 'badge-purple'],
        'UNDER_APPEAL'               => ['label' => 'Under Appeal',        'class' => 'badge-blue'],
        default                      => ['label' => 'Pending',             'class' => 'badge-amber'],
    };
}
$statusBadge = decision_badge($statusRaw);

$categoryDescriptions = [
    1 => 'Probation for the chosen number of academic terms with referral for counseling. Any subsequent major offense during probation triggers Suspension or Non-Readmission.',
    2 => 'Formative Intervention — University Service, Referral for Counseling, Attendance to Discipline Education Program lectures, and/or Evaluation.',
    3 => 'Non-Readmission — the student is not allowed to enroll for the next term but may finish the current one. Student account will be frozen.',
    4 => 'Exclusion — the student is dropped from the roll upon promulgation. Student account will be frozen.',
    5 => 'Expulsion — the student is permanently disqualified from admission to any higher education institution. Student account will be permanently frozen.',
];

$postedDecidedCategory = isset($_POST['decided_category']) ? (int)$_POST['decided_category'] : 0;
$postedFinalDecision   = trim($_POST['final_decision'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($caseLabel) ?> | UPCC Case</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
    --bg-dark:#0f172a;--bg-card:rgba(30,41,59,.7);--bg-glass:rgba(15,23,42,.65);
    --border-glass:rgba(255,255,255,.08);--border-glass-hover:rgba(255,255,255,.15);
    --accent-primary:#6366f1;--success:#10b981;--warning:#f59e0b;--danger:#ef4444;
    --text-main:#f8fafc;--text-muted:#94a3b8;
    --radius-lg:24px;--radius-md:16px;--radius-sm:10px;
    --font-h:'Outfit',sans-serif;--font-b:'Plus Jakarta Sans',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font-b);color:var(--text-main);background:var(--bg-dark);min-height:100vh;overflow-x:hidden;line-height:1.5}
body::before{content:'';position:fixed;inset:0;z-index:-2;
    background:radial-gradient(circle at 15% 50%,rgba(99,102,241,.15),transparent 40%),
               radial-gradient(circle at 85% 30%,rgba(139,92,246,.15),transparent 40%),
               radial-gradient(circle at 50% 80%,rgba(16,185,129,.05),transparent 40%);
    filter:blur(60px)}
.app-container{display:grid;grid-template-columns:280px 1fr;min-height:100vh}
@media(max-width:1100px){.app-container{grid-template-columns:1fr}.sidebar{display:none}}
.sidebar{background:var(--bg-glass);backdrop-filter:blur(20px);border-right:1px solid var(--border-glass);
    padding:30px 20px;display:flex;flex-direction:column}
.brand{display:flex;align-items:center;gap:15px;margin-bottom:40px;padding-bottom:20px;border-bottom:1px solid var(--border-glass)}
.brand-icon{width:50px;height:50px;background:rgba(255,255,255,.05);border:1px solid var(--border-glass);
    border-radius:14px;display:grid;place-items:center;padding:8px}
.brand-icon img{width:100%;height:auto;border-radius:6px}
.brand-text h1{font-family:var(--font-h);font-size:20px;font-weight:700;line-height:1}
.brand-text p{font-size:12px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-top:2px}
.side-group{margin-top:18px}
.side-label{font-size:11px;letter-spacing:1.5px;color:var(--text-muted);text-transform:uppercase;margin-bottom:12px;font-weight:600}
.panel-chip{display:inline-flex;align-items:center;gap:8px;border:1px solid var(--border-glass);
    border-radius:999px;padding:8px 12px;margin:0 8px 8px 0;background:rgba(255,255,255,.02);
    font-size:12px;color:var(--text-main);font-weight:500}
.panel-chip small{color:var(--text-muted);font-weight:400}
.main-content{padding:40px;overflow-y:auto}
.hero{background:var(--bg-card);backdrop-filter:blur(16px);border:1px solid var(--border-glass);
    border-radius:var(--radius-lg);padding:30px;display:flex;justify-content:space-between;
    gap:20px;align-items:start;margin-bottom:24px;box-shadow:0 10px 30px rgba(0,0,0,.2)}
.crumb{color:var(--accent-primary);font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px}
.title{font-family:var(--font-h);font-size:28px;font-weight:800;
    background:linear-gradient(to right,#fff,#94a3b8);-webkit-background-clip:text;-webkit-text-fill-color:transparent;line-height:1.2}
.subtitle{margin-top:10px;color:var(--text-muted);max-width:760px;line-height:1.6;font-size:14px}
.hero-meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:20px}
.pill{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;font-size:12px;font-weight:700;border:1px solid transparent}
.pill.green{color:#6ee7b7;background:rgba(16,185,129,.1);border-color:rgba(16,185,129,.2)}
.pill.amber{color:#fcd34d;background:rgba(245,158,11,.1);border-color:rgba(245,158,11,.2)}
.pill.blue{color:#93c5fd;background:rgba(59,130,246,.1);border-color:rgba(59,130,246,.2)}
.pill.purple{color:#c4b5fd;background:rgba(139,92,246,.1);border-color:rgba(139,92,246,.2)}
.info{border:1px solid var(--border-glass);background:rgba(255,255,255,.02);border-radius:12px;padding:16px;min-width:200px}
.info-label{color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;font-weight:600}
.info-value{font-size:16px;font-weight:700;color:var(--text-main);font-family:var(--font-h)}
.layout{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:24px}
@media(max-width:1100px){.layout{grid-template-columns:1fr}}
.glass-panel{background:var(--bg-card);backdrop-filter:blur(16px);border:1px solid var(--border-glass);
    border-radius:var(--radius-lg);overflow:hidden;display:flex;flex-direction:column;
    box-shadow:0 10px 30px rgba(0,0,0,.15)}
.panel-header{padding:20px 24px;border-bottom:1px solid var(--border-glass);
    display:flex;align-items:center;justify-content:space-between;gap:12px;background:rgba(255,255,255,.01)}
.panel-title{font-family:var(--font-h);font-size:16px;font-weight:700;display:flex;align-items:center;gap:8px}
.panel-body{padding:24px}
.badge{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:999px;
    font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:1px;border:1px solid transparent}
.badge-amber{background:rgba(245,158,11,.1);color:#fcd34d;border-color:rgba(245,158,11,.2)}
.badge-purple{background:rgba(139,92,246,.1);color:#c4b5fd;border-color:rgba(139,92,246,.2)}
.badge-green{background:rgba(16,185,129,.1);color:#6ee7b7;border-color:rgba(16,185,129,.2)}
.badge-blue{background:rgba(59,130,246,.1);color:#93c5fd;border-color:rgba(59,130,246,.2)}
.offense-list{display:grid;gap:12px}
.offense-item{border:1px solid var(--border-glass);background:rgba(255,255,255,.02);
    border-radius:var(--radius-md);overflow:hidden;transition:all .3s}
.offense-item:hover{background:rgba(255,255,255,.04);border-color:var(--border-glass-hover)}
.offense-item summary{list-style:none;cursor:pointer;padding:16px 20px;
    display:flex;justify-content:space-between;gap:12px;align-items:center}
.offense-item summary::-webkit-details-marker{display:none}
.offense-main{display:flex;flex-direction:column;gap:6px;min-width:0}
.offense-code{font-size:12px;color:var(--accent-primary);font-weight:800;letter-spacing:1px}
.offense-name{font-size:15px;font-weight:700;font-family:var(--font-h)}
.offense-meta{color:var(--text-muted);font-size:12px}
.offense-body{padding:0 20px 20px;border-top:1px solid var(--border-glass);padding-top:16px;display:grid;gap:12px}
.offense-row{display:grid;grid-template-columns:140px 1fr;gap:12px;align-items:start}
.offense-row .label{color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:1px;font-weight:600}
.offense-row .value{color:var(--text-main);font-size:13px;line-height:1.6}
.field{display:grid;gap:8px}
.field label{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;font-weight:600}
.fld-input{width:100%;border:1px solid var(--border-glass);background:rgba(0,0,0,.2);color:var(--text-main);
    border-radius:12px;padding:12px 16px;font-family:var(--font-b);font-size:13px;transition:all .2s}
.fld-input:focus{outline:none;border-color:var(--accent-primary);box-shadow:0 0 0 3px rgba(99,102,241,.2)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:none;border-radius:12px;
    cursor:pointer;padding:10px 18px;font-family:var(--font-b);font-size:13px;font-weight:700;text-decoration:none;transition:all .2s}
.btn-primary{background:linear-gradient(135deg,var(--accent-primary),#8b5cf6);color:#fff;box-shadow:0 4px 15px rgba(99,102,241,.3)}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 6px 20px rgba(99,102,241,.4)}
.btn-secondary{background:rgba(255,255,255,.05);color:var(--text-main);border:1px solid var(--border-glass)}
.btn-secondary:hover{background:rgba(255,255,255,.1)}
.btn-danger{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.3)}
.btn-danger:hover{background:rgba(239,68,68,.25)}
.btn-success{background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.3)}
.btn-success:hover{background:rgba(16,185,129,.25)}
.chat-item{border:1px solid var(--border-glass);background:rgba(255,255,255,.02);
    border-radius:var(--radius-md);padding:14px 16px;position:relative;margin-bottom:12px}
.chat-head{display:flex;justify-content:space-between;gap:10px;margin-bottom:8px}
.chat-name{font-weight:700;font-size:13px;font-family:var(--font-h)}
.chat-role{color:var(--text-muted);font-size:11px;text-transform:uppercase;letter-spacing:.5px}
.chat-time{color:var(--text-muted);font-size:11px;white-space:nowrap}
.chat-msg{color:#e2e8f0;line-height:1.6;font-size:13px;white-space:pre-wrap}
.empty{color:var(--text-muted);font-size:13px;padding:12px 0;font-style:italic}
.lock{border:1px dashed rgba(245,158,11,.4);background:rgba(245,158,11,.1);border-radius:var(--radius-md);padding:24px;color:#fcd34d;text-align:center}
.stack{display:grid;gap:16px}
hr{border-color:var(--border-glass);margin:16px 0}

/* ── COOLDOWN ALERT ──────────────────────────────────────────────────── */
.cooldown-alert{background:rgba(239,68,68,.1);border:1px solid rgba(239,68,68,.3);
    border-radius:12px;padding:14px 16px;text-align:center;font-size:13px;color:#f87171}
.cooldown-alert strong{font-size:20px;display:block;margin-top:6px;font-family:var(--font-h);
    font-variant-numeric:tabular-nums}

/* ── CONSENSUS BANNER ────────────────────────────────────────────────── */
.consensus-banner{background:linear-gradient(135deg,rgba(16,185,129,.2),rgba(5,150,105,.1));
    border:2px solid rgba(16,185,129,.4);border-radius:var(--radius-md);padding:20px;text-align:center;margin-bottom:16px}
.consensus-banner-title{font-family:var(--font-h);font-size:18px;font-weight:800;color:#6ee7b7;margin-bottom:6px}

/* ── PRESENCE OVERLAY ────────────────────────────────────────────────── */
.presence-overlay{position:fixed;inset:0;z-index:3000;display:none;align-items:center;
    justify-content:center;background:rgba(15,23,42,.85);backdrop-filter:blur(16px);padding:24px;text-align:center}
.presence-overlay.open{display:flex}
.presence-card{background:var(--bg-card);border:1px solid var(--border-glass);border-radius:var(--radius-lg);
    padding:40px;max-width:400px;width:100%;backdrop-filter:blur(20px)}

/* ══════════════════════════════════════════════════════════════════════
   VOTING MODAL — full-screen live voting popup
══════════════════════════════════════════════════════════════════════ */
.voting-modal{position:fixed;inset:0;z-index:9000;display:none;align-items:center;
    justify-content:center;background:rgba(7,17,31,.97);backdrop-filter:blur(12px);padding:16px}
.voting-modal.open{display:flex}
.vmc{background:linear-gradient(160deg,rgba(30,41,59,.98),rgba(15,23,42,.98));
    border:2px solid var(--accent-primary);border-radius:var(--radius-lg);
    padding:36px 32px;width:100%;max-width:580px;max-height:92vh;overflow-y:auto;
    box-shadow:0 32px 64px rgba(0,0,0,.6),0 0 60px rgba(99,102,241,.25);
    animation:popIn .35s cubic-bezier(.34,1.56,.64,1)}
@keyframes popIn{from{transform:scale(.85) translateY(30px);opacity:0}to{transform:scale(1) translateY(0);opacity:1}}
.vmc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:6px}
.vmc-title{font-family:var(--font-h);font-size:26px;font-weight:800;color:var(--accent-primary)}
.vmc-live-badge{background:rgba(239,68,68,.2);color:#f87171;border:1px solid rgba(239,68,68,.4);
    border-radius:999px;padding:4px 12px;font-size:11px;font-weight:700;text-transform:uppercase;
    letter-spacing:1px;animation:blink 1.4s ease-in-out infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.4}}
.vmc-sub{color:var(--text-muted);font-size:13px;margin-bottom:20px;line-height:1.5}

/* Timer bar */
.timer-wrap{margin:0 0 18px}
.timer-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px}
.timer-label{font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px}
.timer-num{font-family:var(--font-h);font-size:28px;font-weight:800;font-variant-numeric:tabular-nums;color:#fff;
    transition:color .5s}
.timer-num.urgent{color:#f87171;animation:pulseRed 1s ease-in-out infinite}
@keyframes pulseRed{0%,100%{opacity:1}50%{opacity:.6}}
.timer-bar-wrap{height:6px;background:rgba(255,255,255,.08);border-radius:999px;overflow:hidden}
.timer-bar-fill{height:100%;border-radius:999px;transition:width 1s linear,background .5s}

/* Suggestion box */
.sug-box{background:rgba(0,0,0,.35);border:1px solid rgba(99,102,241,.3);
    border-radius:14px;padding:18px;margin:0 0 18px}
.sug-cat{font-family:var(--font-h);font-size:20px;font-weight:800;color:#c4b5fd;margin-bottom:6px}
.sug-desc{font-size:13px;color:#e2e8f0;line-height:1.6;margin-bottom:8px}
.sug-note{background:rgba(255,255,255,.04);border-left:3px solid var(--accent-primary);
    padding:8px 12px;border-radius:0 8px 8px 0;font-size:12px;color:var(--text-muted);font-style:italic;margin-top:8px}
.sug-tag{display:inline-block;background:rgba(99,102,241,.15);color:#c4b5fd;border:1px solid rgba(99,102,241,.3);
    border-radius:6px;padding:2px 8px;font-size:11px;font-weight:700;margin:2px 2px 0 0}

/* Tally */
.tally-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px}
.tally-cell{background:rgba(0,0,0,.2);border-radius:12px;padding:12px;text-align:center}
.tally-cell label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);display:block;margin-bottom:4px}
.tally-cell span{font-family:var(--font-h);font-size:24px;font-weight:800;transition:all .3s}
.tc-agree span{color:#6ee7b7}.tc-disagree span{color:#f87171}.tc-pending span{color:#fcd34d}
.tally-note{text-align:center;font-size:12px;color:var(--text-muted);margin-bottom:14px}

/* Member vote list */
.voter-list{display:grid;gap:8px;margin-bottom:18px}
.voter-item{display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:10px 14px;border:1px solid rgba(255,255,255,.07);border-radius:12px;
    background:rgba(0,0,0,.2);transition:border-color .3s,background .3s}
.voter-item.v-agree{border-color:rgba(16,185,129,.3);background:rgba(16,185,129,.07)}
.voter-item.v-disagree{border-color:rgba(239,68,68,.3);background:rgba(239,68,68,.07)}
.voter-name{font-weight:700;font-size:13px}
.voter-meta{font-size:11px;color:var(--text-muted);margin-top:2px}
.v-pill{padding:3px 10px;border-radius:999px;font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px}
.v-pill.agree{background:rgba(16,185,129,.15);color:#6ee7b7;border:1px solid rgba(16,185,129,.35)}
.v-pill.disagree{background:rgba(239,68,68,.15);color:#f87171;border:1px solid rgba(239,68,68,.35)}
.v-pill.pending{background:rgba(245,158,11,.15);color:#fcd34d;border:1px solid rgba(245,158,11,.35)}
.v-pill.suggester{background:rgba(99,102,241,.15);color:#c4b5fd;border:1px solid rgba(99,102,241,.35)}

/* Vote buttons */
.vote-btn-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:12px}
.btn-agree{background:linear-gradient(135deg,#059669,#10b981);color:#fff;border:none;
    border-radius:14px;padding:16px;font-family:var(--font-b);font-size:16px;font-weight:800;
    cursor:pointer;transition:all .2s;width:100%}
.btn-agree:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(16,185,129,.4)}
.btn-disagree{background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;border:none;
    border-radius:14px;padding:16px;font-family:var(--font-b);font-size:16px;font-weight:800;
    cursor:pointer;transition:all .2s;width:100%}
.btn-disagree:hover{transform:translateY(-3px);box-shadow:0 8px 24px rgba(239,68,68,.4)}
.voted-conf{text-align:center;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);
    border-radius:12px;padding:14px;font-size:14px;font-weight:600;color:#6ee7b7;margin-bottom:12px}
.voted-conf small{display:block;font-size:11px;font-weight:400;color:var(--text-muted);margin-top:4px}

/* Result flash */
.result-flash{border-radius:12px;padding:14px;text-align:center;font-weight:700;font-size:14px;margin-bottom:12px;display:none}
.result-flash.consensus{background:rgba(16,185,129,.15);border:1px solid rgba(16,185,129,.35);color:#6ee7b7}
.result-flash.disagreed{background:rgba(239,68,68,.15);border:1px solid rgba(239,68,68,.35);color:#f87171}

/* Panel-side voting section */
.vote-tally{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;
    background:rgba(0,0,0,.2);border-radius:12px;padding:14px;margin:16px 0;text-align:center}
.tally-item label{font-size:10px;text-transform:uppercase;letter-spacing:1px;color:var(--text-muted);display:block;margin-bottom:4px}
.tally-item span{font-family:var(--font-h);font-size:22px;font-weight:800}
.tally-agree span{color:#6ee7b7}.tally-disagree span{color:#f87171}.tally-pending span{color:#fcd34d}
.suggestion-box{background:rgba(0,0,0,.35);border:1px solid rgba(99,102,241,.3);
    border-radius:14px;padding:18px;margin:12px 0}
.suggestion-category{font-family:var(--font-h);font-size:18px;font-weight:800;color:#c4b5fd;margin-bottom:8px}
.vote-btns{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px}
.vote-btns form{display:contents}
.btn-vote-agree{background:linear-gradient(135deg,#059669,#10b981);color:#fff;border:none;
    border-radius:12px;padding:14px;font-family:var(--font-b);font-size:15px;font-weight:800;cursor:pointer;transition:all .2s}
.btn-vote-agree:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(16,185,129,.4)}
.btn-vote-disagree{background:linear-gradient(135deg,#dc2626,#ef4444);color:#fff;border:none;
    border-radius:12px;padding:14px;font-family:var(--font-b);font-size:15px;font-weight:800;cursor:pointer;transition:all .2s}
.btn-vote-disagree:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(239,68,68,.4)}
.voted-confirmation{text-align:center;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);
    border-radius:12px;padding:14px;font-size:14px;font-weight:600;color:#6ee7b7}
.decision-box{border:1px solid rgba(99,102,241,.3);background:rgba(99,102,241,.1);
    border-radius:var(--radius-md);padding:18px;display:grid;gap:10px}

/* Final decision form inputs */
#finalDecisionForm select,
#finalDecisionForm textarea,
#finalDecisionForm input[type=text],
#finalDecisionForm input[type=number]{width:100%;border:1px solid var(--border-glass);background:rgba(0,0,0,.2);
    color:var(--text-main);border-radius:12px;padding:12px 16px;font-family:var(--font-b);font-size:13px;transition:all .2s}
#finalDecisionForm select:focus,
#finalDecisionForm textarea:focus,
#finalDecisionForm input[type=text]:focus,
#finalDecisionForm input[type=number]:focus{outline:none;border-color:var(--accent-primary);box-shadow:0 0 0 3px rgba(99,102,241,.2)}
#finalDecisionForm textarea{min-height:90px;resize:vertical}
</style>
</head>
<body>
<div class="app-container">

<!-- ── SIDEBAR ──────────────────────────────────────────────────────────── -->
<aside class="sidebar">
    <div class="brand">
        <div class="brand-icon"><img src="../assets/logo.png" alt="IdentiTrack"></div>
        <div class="brand-text"><h1>UPCC Panel</h1><p>Case workspace</p></div>
    </div>
    <div class="side-group">
        <div class="side-label">Current Case</div>
        <div class="panel-chip"><small>ID</small> <?= htmlspecialchars($caseLabel) ?></div>
        <div class="panel-chip"><small>Status</small> <?= htmlspecialchars($statusBadge['label']) ?></div>
        <div class="panel-chip"><small>Mode</small> <?= htmlspecialchars($decisionHint) ?></div>
    </div>
    <div class="side-group">
        <div class="side-label">Assigned Panel</div>
        <?php if (!empty($panelMembers)): foreach ($panelMembers as $m): ?>
            <div class="panel-chip">
                👤 <?= htmlspecialchars($m['full_name']) ?>
                <small>(<?= htmlspecialchars($m['role']) ?>)</small>
                <?php if ((int)$m['upcc_id'] === $suggesterId && $isRoundActive): ?>
                    🗣️
                <?php elseif (isset($votesByMember[(int)$m['upcc_id']])): ?>
                    <?= $votesByMember[(int)$m['upcc_id']] > 0 ? '✅' : '❌' ?>
                <?php else: ?>⏳<?php endif; ?>
            </div>
        <?php endforeach; else: ?>
            <div class="empty">No panel members mapped yet.</div>
        <?php endif; ?>
    </div>
    <div class="side-group" style="margin-top:auto">
        <a id="backToDashboardBtn" class="btn btn-secondary" href="upccdashboard.php" style="width:100%">← Back</a>
    </div>
</aside>

<!-- ── MAIN ──────────────────────────────────────────────────────────────── -->
<main class="main-content">

    <!-- HERO -->
    <section class="hero">
        <div>
            <div class="crumb">UPCC / Case Detail</div>
            <div class="title"><?= htmlspecialchars($caseLabel) ?></div>
            <div class="subtitle"><?= htmlspecialchars($case['student_name']) ?> is under panel review. Inspect offenses, coordinate with panel, record the final decision.</div>
            <div class="hero-meta">
                <span class="pill amber">⚖️ <?= htmlspecialchars($decisionHint) ?></span>
                <span class="pill purple">🏢 <?= htmlspecialchars($case['assigned_dept_name'] ?? 'No dept') ?></span>
                <span class="pill blue">🎓 <?= htmlspecialchars($case['year_level']) ?> Yr • <?= htmlspecialchars($case['section'] ?? 'N/A') ?></span>
                <span class="pill green">📌 <?= htmlspecialchars($statusBadge['label']) ?></span>
                <?php if ($isHearingOpen && $isHearingPaused): ?>
                  <span class="pill" data-pause-pill="1" style="background: #fca5a5; color: #7f1d1d; border-color: #ef4444;">⏸️ HEARING PAUSED</span>
                <?php elseif ($isHearingOpen): ?>
                  <span class="pill" data-pause-pill="1" style="background: #86efac; color: #15803d; border-color: #22c55e;"><span style="display:inline-block;width:6px;height:6px;background:#15803d;border-radius:50%;margin-right:4px"></span> HEARING LIVE</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="stack" style="min-width:260px">
            <div class="info">
                <div class="info-label">Student</div>
                <div class="info-value"><?= htmlspecialchars($case['student_name']) ?></div>
                <div style="margin-top:6px;font-size:13px;color:var(--text-muted)"><?= htmlspecialchars($case['student_id']) ?> • <?= htmlspecialchars($case['program']) ?></div>
            </div>
            <div class="info">
                <div class="info-label">Filed</div>
                <div class="info-value"><?= fmt_dt((string)$case['created_at']) ?></div>
            </div>
        </div>
    </section>

    <div class="layout">
        <!-- ── LEFT ─────────────────────────────────────────────────── -->
        <section class="stack">

            <!-- OFFENSE LIST -->
            <div class="glass-panel">
                <div class="panel-header">
                    <div><div class="panel-title">📋 Offense Breakdown</div>
                    <div style="color:var(--text-muted);font-size:12px;margin-top:4px">Every offense linked to this case</div></div>
                    <span class="badge <?= htmlspecialchars($statusBadge['class']) ?>"><?= htmlspecialchars($statusBadge['label']) ?></span>
                </div>
                <div class="panel-body">
                    <?php if (!$confidentialityAccepted): ?>
                        <div class="lock">
                            <div style="font-weight:800;margin-bottom:6px">Confidential case data is locked</div>
                            <div style="line-height:1.5;margin-bottom:12px;color:#ffe8ad">Accept confidentiality to view offenses and join the discussion.</div>
                            <form method="post">
                                <input type="hidden" name="action" value="accept_confidentiality">
                                <button class="btn btn-primary" type="submit">I Accept Confidentiality</button>
                            </form>
                        </div>
                    <?php else: ?>
                        <!-- Student Explanation -->
                        <div id="studentExplanationBlock" style="<?= !empty($case['student_explanation_at']) ? 'display:block' : 'display:none' ?>; margin-bottom: 24px; background: rgba(79, 123, 255, 0.08); border: 1px solid rgba(79, 123, 255, 0.2); border-radius: 12px; padding: 16px;">
                           <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px;">
                              <span style="font-size: 11px; font-weight: 800; color: #a5b4fc; text-transform: uppercase; letter-spacing: 1px;">Student Explanation</span>
                              <span id="explanationTime" style="font-size: 11px; color: var(--text-muted);"><?= $case['student_explanation_at'] ? 'Submitted ' . date('M j, Y g:i A', strtotime($case['student_explanation_at'])) : '' ?></span>
                           </div>
                           <div id="explanationText" style="font-size: 13px; line-height: 1.6; color: var(--text-main); white-space: pre-wrap; margin-bottom: 12px;"><?= htmlspecialchars($case['student_explanation_text'] ?? '') ?></div>
                           <div id="explanationAttachments" style="display: flex; gap: 10px; flex-wrap: wrap;">
                              <?php if (!empty($case['student_explanation_image'])): ?>
                                <a href="../<?= htmlspecialchars($case['student_explanation_image']) ?>" target="_blank" style="display: block; border-radius: 8px; overflow: hidden; border: 1px solid var(--border-glass);">
                                   <img src="../<?= htmlspecialchars($case['student_explanation_image']) ?>" style="max-width: 80px; max-height: 80px; display: block; object-fit: cover;">
                                </a>
                              <?php endif; ?>
                              <?php if (!empty($case['student_explanation_pdf'])): ?>
                                <a href="../<?= htmlspecialchars($case['student_explanation_pdf']) ?>" target="_blank" style="display: flex; align-items: center; gap: 8px; padding: 6px 12px; background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 8px; text-decoration: none; color: #fca5a5; font-size: 11px; font-weight: 600;">
                                   <span>📄 View PDF Explanation</span>
                                </a>
                              <?php endif; ?>
                           </div>
                        </div>
                        <div class="offense-list">
                            <?php if (empty($offenses)): ?>
                                <div class="empty">No linked offenses found.</div>
                            <?php else: foreach ($offenses as $idx => $offense):
                                $lvl = strtoupper((string)($offense['level'] ?? 'MINOR'));
                                $lvlClass = $lvl === 'MAJOR' ? 'badge-blue' : 'badge-amber';
                            ?>
                                <details class="offense-item" <?= $idx === 0 ? 'open' : '' ?>>
                                    <summary>
                                        <div class="offense-main">
                                            <div class="offense-code"><?= htmlspecialchars($offense['code']) ?></div>
                                            <div class="offense-name"><?= htmlspecialchars($offense['offense_name']) ?></div>
                                            <div class="offense-meta"><?= $lvl ?> • <?= fmt_dt((string)$offense['date_committed']) ?></div>
                                        </div>
                                        <div class="badge <?= $lvlClass ?>"><?= $lvl ?></div>
                                    </summary>
                                    <div class="offense-body">
                                        <?php if (!empty(trim((string)$offense['description']))): ?>
                                        <div class="offense-row">
                                            <div class="label">Description</div>
                                            <div class="value"><?= htmlspecialchars((string)$offense['description']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty(trim((string)($offense['intervention_first'] ?? '')))): ?>
                                        <div class="offense-row">
                                            <div class="label">1st intervention</div>
                                            <div class="value"><?= htmlspecialchars((string)$offense['intervention_first']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!empty(trim((string)($offense['intervention_second'] ?? '')))): ?>
                                        <div class="offense-row">
                                            <div class="label">2nd intervention</div>
                                            <div class="value"><?= htmlspecialchars((string)$offense['intervention_second']) ?></div>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </details>
                            <?php endforeach; endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- LIVE CHAT -->
            <div class="glass-panel" id="chat-room">
                <div class="panel-header"><div class="panel-title">💬 Live Panel Chat</div></div>
                <div class="panel-body" style="display:flex;flex-direction:column;padding:0">
                    <?php if (!$confidentialityAccepted): ?>
                        <div class="lock" style="margin:24px">Accept confidentiality to join the discussion.</div>
                    <?php else: ?>
                        <div id="live-chat-box" style="height:350px;overflow-y:auto;background:rgba(0,0,0,.15);
                            border:1px solid var(--border-glass);border-radius:12px;padding:12px;margin:16px 24px 0">
                            <div style="text-align:center;color:var(--text-muted);font-size:11px">Loading…</div>
                        </div>
                        <div id="replying-to-container" style="display:none;background:rgba(79,123,255,.1);
                            padding:8px 24px;border-top:1px solid rgba(79,123,255,.2);font-size:11px;color:#dbe5ff">
                            <strong>Replying to <span id="reply-to-name"></span>:</strong>
                            <span id="reply-to-text" style="color:var(--text-muted)"></span>
                            <button type="button" class="btn btn-secondary" onclick="cancelReply()"
                                style="float:right;padding:2px 6px;font-size:10px;min-height:0">✕</button>
                        </div>
                        <form id="chat-form" style="padding:16px 24px 24px">
                            <input type="hidden" id="reply_to" name="reply_to" value="">
                            <input type="hidden" name="action" value="post_message">
                            <input type="hidden" name="case_id" value="<?= $caseId ?>">
                            <?php $isHearingOpen = ((int)$case['hearing_is_open'] === 1); ?>
                            <div class="field">
                                <textarea id="chat_message" name="message" class="fld-input" 
                                    placeholder="<?= $isHearingOpen && !$isHearingPaused ? 'Type your message…' : ($isHearingPaused ? 'Chat disabled - hearing is paused' : 'Chat disabled until hearing is open…') ?>" 
                                    required style="min-height:60px" <?= (!$isHearingOpen || $isHearingPaused) ? 'disabled' : '' ?> id="chat_message_input"></textarea>
                            </div>
                            <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:12px">
                                <button class="btn btn-primary" type="submit" <?= (!$isHearingOpen || $isHearingPaused) ? 'disabled' : '' ?> id="chat_submit_btn">Post Message</button>
                                <a class="btn btn-secondary" href="#decision-panel">Jump to Decision</a>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- ── RIGHT COLUMN ──────────────────────────────────────────── -->
        <aside class="stack">

            <!-- CASE INFO BOX -->
            <div class="glass-panel" id="decision-panel">
                <div class="panel-header"><div class="panel-title">🗳️ Decision Panel</div></div>
                <div class="panel-body">
                    <div class="decision-box">
                        <div style="font-family:var(--font-h);font-size:14px;font-weight:700"><?= htmlspecialchars($decisionHint) ?></div>
                        <div style="font-size:13px;color:#c7d2fe;line-height:1.5">
                            <?= $isSection4
                                ? 'Escalation from repeated minor offenses. Confirm whether facts support Section 4 outcome.'
                                : 'Select the category matching the panel decision and write the sanction clearly.' ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- PANEL MEMBERS -->
            <div class="glass-panel">
                <div class="panel-header">
                    <div><div class="panel-title">👥 Panel Members</div>
                    <div style="color:var(--text-muted);font-size:12px;margin-top:4px">Assigned to this case</div></div>
                </div>
                <div class="panel-body" style="display:grid;gap:10px">
                    <?php if (empty($panelMembers)): ?>
                        <div class="empty">No assigned panel members found.</div>
                    <?php else: foreach ($panelMembers as $m):
                        $uid = (int)$m['upcc_id'];
                        $isSug = $uid === $suggesterId && $isRoundActive;
                        $vote  = $votesByMember[$uid] ?? null;
                    ?>
                        <div class="info">
                            <div class="info-value"><?= htmlspecialchars($m['full_name']) ?> <?= $uid === $panelId ? '<small style="color:var(--text-muted)">(you)</small>' : '' ?></div>
                            <div style="color:var(--text-muted);font-size:12px;margin-top:2px"><?= htmlspecialchars(ucfirst($m['role'])) ?></div>
                            <div style="font-size:12px;margin-top:6px">
                                <?php if ($isSug): ?>
                                    <span style="color:#c4b5fd">🗣️ Suggested this round</span>
                                <?php elseif ($vote !== null && $isRoundActive): ?>
                                    <?= $vote > 0 ? '<span style="color:#6ee7b7">✅ Agreed</span>' : '<span style="color:#f87171">❌ Disagreed</span>' ?>
                                <?php elseif ($isRoundActive): ?>
                                    <span style="color:#fcd34d">⏳ Pending vote</span>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; endif; ?>
                </div>
            </div>

            <!-- VOTING / SUGGESTION SECTION -->
            <div class="glass-panel" id="voting-section">
                <div class="panel-header">
                    <div><div class="panel-title">⚡ Penalty Suggestion & Voting</div>
                    <div style="color:var(--text-muted);font-size:12px;margin-top:4px">One member suggests; ALL others must agree</div></div>
                </div>
                <div class="panel-body">

                    <?php if (!$confidentialityAccepted): ?>
                        <div class="lock">Accept confidentiality before suggesting penalties or voting.</div>

                    <?php elseif ($isAwaitingAdmin): ?>
                        <!-- ── CONSENSUS REACHED ── -->
                        <div class="consensus-banner">
                            <div class="consensus-banner-title">✅ CONSENSUS REACHED</div>
                            <div style="font-size:20px;font-weight:800;color:#a7f3d0;margin:6px 0">Category <?= $consensusCategory ?></div>
                            <div style="font-size:12px;color:var(--text-muted)">Awaiting Admin to record the final decision.</div>
                        </div>
                        <div class="info" style="margin-bottom:16px">
                            <div class="info-label">Agreed Penalty</div>
                            <div style="font-size:13px;line-height:1.5;margin-top:4px"><?= htmlspecialchars($categoryDescriptions[$consensusCategory] ?? '') ?></div>
                            <?php
                            $cda = $case['hearing_vote_suggested_details'] ? json_decode($case['hearing_vote_suggested_details'], true) : null;
                            ?>
                            <?php if ($consensusCategory === 1 && !empty($cda['probation_terms'])): ?>
                                <div style="margin-top:8px;font-size:12px;color:var(--text-muted)">Probation: <?= (int)$cda['probation_terms'] ?> term(s)</div>
                            <?php endif; ?>
                            <?php if ($consensusCategory === 2 && !empty($cda['interventions'])): ?>
                                <div style="margin-top:8px;font-size:12px;color:var(--text-muted)">
                                    Interventions: <?= htmlspecialchars(implode(', ', $cda['interventions'])) ?>
                                    <?php if (!empty($cda['service_hours'])): ?>(<?= $cda['service_hours'] ?> hrs)<?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($cda['description'])): ?>
                                <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--border-glass);font-size:13px;line-height:1.5"><?= nl2br(htmlspecialchars($cda['description'])) ?></div>
                            <?php endif; ?>
                        </div>

                        <!-- Admin records final decision, panel waits -->

                    <?php elseif ($isRoundActive && $suggestedDetails): ?>
                        <!-- ── ACTIVE ROUND ── -->
                        <div class="vote-tally">
                            <div class="tally-item tally-agree">
                                <label>✅ Agree</label><span id="panelAgree"><?= $agreeVotes ?></span>
                            </div>
                            <div class="tally-item tally-disagree">
                                <label>❌ Disagree</label><span id="panelDisagree"><?= $disagreeVotes ?></span>
                            </div>
                            <div class="tally-item tally-pending">
                                <label>⏳ Pending</label><span id="panelPending"><?= $totalVoters - $agreeVotes - $disagreeVotes ?></span>
                            </div>
                        </div>
                        <div style="text-align:center;font-size:11px;color:var(--text-muted);margin-bottom:12px">Majority of the panel must agree to finalize</div>

                        <div class="suggestion-box">
                            <div class="suggestion-category">Category <?= $suggestedDetails['category'] ?></div>
                            <div style="font-size:12px;color:var(--text-muted);margin-bottom:6px">Suggested by <strong style="color:var(--text-main)"><?= htmlspecialchars($suggesterName) ?></strong></div>
                            <?php _renderSugDetails($suggestedDetails); ?>
                        </div>

                        <?php if ($showCancelSuggestion): ?>
                            <form method="post" onsubmit="return confirm('Cancel your suggestion? The panel can submit a new suggestion immediately.')">
                                <input type="hidden" name="action" value="cancel_suggestion">
                                <input type="hidden" name="round_no" value="<?= $roundNo ?>">
                                <button type="submit" class="btn btn-danger" style="width:100%">❌ Cancel My Suggestion</button>
                            </form>
                            <div style="text-align:center;font-size:11px;color:var(--text-muted);margin-top:8px">You are the suggester — waiting for others to vote</div>
                        <?php elseif ($showVoteButtons): ?>
                            <div class="vote-btns">
                                <form method="post">
                                    <input type="hidden" name="action" value="vote_on_suggestion">
                                    <input type="hidden" name="round_no" value="<?= $roundNo ?>">
                                    <input type="hidden" name="suggested_by" value="<?= $suggesterId ?>">
                                    <input type="hidden" name="vote_agree" value="1">
                                    <button class="btn-vote-agree" type="submit">✅ AGREE</button>
                                </form>
                                <form method="post">
                                    <input type="hidden" name="action" value="vote_on_suggestion">
                                    <input type="hidden" name="round_no" value="<?= $roundNo ?>">
                                    <input type="hidden" name="suggested_by" value="<?= $suggesterId ?>">
                                    <input type="hidden" name="vote_agree" value="0">
                                    <button class="btn-vote-disagree" type="submit">❌ DISAGREE</button>
                                </form>
                            </div>
                        <?php elseif ($isRoundActive): ?>
                            <div class="voted-confirmation">
                                <?= $currentMemberVote > 0 ? '✅ You voted <strong>AGREE</strong>' : '❌ You voted <strong>DISAGREE</strong>' ?>
                                <div style="font-size:11px;margin-top:4px;color:var(--text-muted)">Waiting for other panel members…</div>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- ── NO ACTIVE ROUND: SUGGEST FORM ── -->
                        <?php if ($isInCooldown): ?>
                            <div class="cooldown-alert">
                                ⏳ Cooldown active — any panel member can suggest after:
                                <strong id="cooldownDisplay"><?= sprintf('%02d:%02d', floor($cooldownRemainingSecs / 60), $cooldownRemainingSecs % 60) ?></strong>
                            </div>
                        <?php endif; ?>

                        <?php if (!$isInCooldown && !$isClosed): ?>
                            <details id="suggestDetails" style="margin-top:8px">
                                <summary id="suggestDetailsSummary" style="cursor:pointer;color:var(--accent-primary);font-weight:600;padding:8px 0;font-size:14px">
                                    ➕ Suggest a Penalty Category
                                </summary>
                                <form method="post" style="margin-top:16px" id="suggestForm">
                                    <input type="hidden" name="action" value="suggest_penalty">
                                    <div class="field" style="margin-bottom:12px">
                                        <label>Select Penalty Category</label>
                                        <select id="suggest_category" name="suggest_category" class="fld-input" required onchange="toggleSugFields()">
                                            <option value="">Choose category…</option>
                                            <?php for ($i = 1; $i <= 5; $i++): ?>
                                                <option value="<?= $i ?>">Category <?= $i ?> — <?= htmlspecialchars(mb_substr($categoryDescriptions[$i], 0, 55)) ?>…</option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>

                                    <!-- CAT 1 -->
                                    <div id="sugCat1" style="display:none;margin-bottom:12px;padding:14px;background:rgba(0,0,0,.2);border-radius:10px;border:1px solid var(--border-glass)">
                                        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;font-weight:600">Probation Details</div>
                                        <div class="field" style="margin-bottom:0">
                                            <label>Number of probation terms</label>
                                            <select name="suggest_cat1_terms" class="fld-input">
                                                <option value="1">1 term</option>
                                                <option value="2">2 terms</option>
                                                <option value="3" selected>3 terms (maximum)</option>
                                            </select>
                                        </div>
                                        <div style="margin-top:8px;font-size:12px;color:var(--text-muted);line-height:1.5">
                                            ℹ️ Any subsequent major offense during probation triggers Suspension or Non-Readmission.
                                        </div>
                                    </div>

                                    <!-- CAT 2 -->
                                    <div id="sugCat2" style="display:none;margin-bottom:12px;padding:14px;background:rgba(0,0,0,.2);border-radius:10px;border:1px solid var(--border-glass)">
                                        <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;font-weight:600">Formative Interventions</div>
                                        <label style="font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:8px;cursor:pointer">
                                            <input type="checkbox" id="sug_us" name="suggest_cat2_university_service" value="1" onchange="toggleSugHours()">
                                            University Service (Community Service)
                                        </label>
                                        <div id="sugHoursBox" style="display:none;margin-left:22px;margin-bottom:8px">
                                            <label style="font-size:11px;color:var(--text-muted)">Required Hours</label>
                                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px" id="sugHoursBtns">
                                                <?php foreach ([100,150,200,250,300,350,400,450,500] as $h): ?>
                                                    <button type="button" class="sug-hrs-btn" data-h="<?= $h ?>"
                                                        onclick="selectSugHours('<?= $h ?>', this)"
                                                        style="padding:6px 16px;font-size:12px;border-radius:8px;border:1px solid var(--border-glass);
                                                               background:rgba(0,0,0,.2);color:var(--text-muted);cursor:pointer;font-family:var(--font-b)">
                                                        <?= $h ?> hrs
                                                    </button>
                                                <?php endforeach; ?>
                                                    <button type="button" class="sug-hrs-btn" data-h="OTHER"
                                                        onclick="selectSugHours('OTHER', this)"
                                                        style="padding:6px 16px;font-size:12px;border-radius:8px;border:1px solid var(--border-glass);
                                                               background:rgba(0,0,0,.2);color:var(--text-muted);cursor:pointer;font-family:var(--font-b)">
                                                        Other
                                                    </button>
                                            </div>
                                            <input type="number" id="sug_cat2_service_hours_custom" name="suggest_cat2_service_hours_custom" min="1" placeholder="Custom hours" style="display:none;width:100%;margin-top:8px;padding:8px 12px;border-radius:8px;background:rgba(0,0,0,.25);color:var(--text-main);border:1px solid var(--border-glass);font-family:var(--font-b);font-size:13px">
                                            <input type="hidden" id="sug_cat2_service_hours" name="suggest_cat2_service_hours" value="">
                                        </div>
                                        <label style="font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:8px;cursor:pointer">
                                            <input type="checkbox" name="suggest_cat2_counseling" value="1"> Referral for Counseling
                                        </label>
                                        <label style="font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:8px;cursor:pointer">
                                            <input type="checkbox" name="suggest_cat2_lectures" value="1"> Attendance to Discipline Education Program
                                        </label>
                                        <label style="font-size:13px;display:flex;align-items:center;gap:8px;cursor:pointer">
                                            <input type="checkbox" name="suggest_cat2_evaluation" value="1"> Evaluation
                                        </label>
                                    </div>

                                    <!-- CAT 3/4/5 -->
                                    <div id="sugCat345" style="display:none;margin-bottom:12px;padding:14px;background:rgba(239,68,68,.08);border-radius:10px;border:1px solid rgba(239,68,68,.2)">
                                        <div style="font-size:13px;color:#fca5a5;line-height:1.5" id="sugCat345Text"></div>
                                        <div style="margin-top:8px;font-size:12px;color:#f87171;font-weight:700">⚠️ Student account will be frozen upon finalization.</div>
                                    </div>

                                    <div class="field" style="margin-bottom:14px">
                                        <label>Penalty Rationale / Notes <span style="color:var(--text-muted);font-weight:400">(optional)</span></label>
                                        <textarea name="suggest_description" class="fld-input" rows="3" placeholder="Describe the reasoning…"></textarea>
                                    </div>

                                    <button id="suggestSubmitBtn" type="submit" class="btn btn-primary" style="width:100%">📝 Suggest This Penalty</button>
                                    <div id="suggestLockNote" style="display:none;margin-top:8px;font-size:12px;color:#fcd34d;text-align:center"></div>
                                </form>
                            </details>
                        <?php elseif ($isClosed): ?>
                            <div class="empty">This case is closed. No further voting is allowed.</div>
                        <?php endif; ?>
                    <?php endif; ?>

                </div>
            </div><!-- end voting-section -->

        </aside>
    </div><!-- end .layout -->
</main>
</div><!-- end .app-container -->

<?php
// Helper to render suggestion details inline
function _renderSugDetails(array $sd): void {
    $cat     = $sd['category'];
    $details = $sd['details'] ?? [];
    if ($cat === 1 && !empty($details['probation_terms'])):
        echo '<div style="font-size:13px;color:#e2e8f0;margin-bottom:6px">🗓️ Probation: <strong>' . (int)$details['probation_terms'] . ' term(s)</strong></div>';
    endif;
    if ($cat === 2 && !empty($details['interventions'])):
        echo '<div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:6px">';
        foreach ($details['interventions'] as $iv) {
            echo '<span class="sug-tag">' . htmlspecialchars($iv) . '</span>';
        }
        if (!empty($details['service_hours'])) {
            echo '<span class="sug-tag">' . (int)$details['service_hours'] . ' hrs</span>';
        }
        echo '</div>';
    endif;
    if (!empty($details['description'])):
        echo '<div class="sug-note">' . nl2br(htmlspecialchars($details['description'])) . '</div>';
    endif;
}
?>

<!-- ── PRESENCE OVERLAY ───────────────────────────────────────────────── -->
<div id="presenceOverlay" class="presence-overlay" style="display:none">
    <div class="presence-card">
        <div id="presenceIcon" style="font-size:48px;margin-bottom:20px">🚪</div>
        <div id="presenceTitle" style="font-family:var(--font-h);font-size:28px;font-weight:800;margin-bottom:12px">Waiting Room</div>
        <div id="presenceText" style="font-size:15px;line-height:1.6;color:var(--text-muted)">Please wait for the Admin to let you in.</div>
        <div style="margin-top:24px;display:flex;gap:10px;flex-direction:column">
            <button id="requestJoinBtn" class="btn btn-primary" style="width:100%;justify-content:center;display:none" onclick="requestJoinHearing()">🔔 Request to Join Hearing</button>
            <button id="exitHearingBtn" class="btn btn-secondary" style="width:100%;justify-content:center;display:none" onclick="exitHearing()">🚪 Exit Hearing</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     VOTING MODAL — appears on ALL panel members' screens when vote active
══════════════════════════════════════════════════════════════════════ -->
<div id="votingModal" class="voting-modal <?= $showVotingPopup ? 'open' : '' ?>">
    <div class="vmc" id="vmcInner">

        <div class="vmc-header">
            <div class="vmc-title">🗳️ Live Voting Session</div>
            <span class="vmc-live-badge" id="vmcLiveBadge">● Live</span>
        </div>
        <div class="vmc-sub" id="vmcSub">
            <?php if ($isCurrentUserSuggester): ?>
                You submitted this penalty proposal. Your screen will stay in waiting mode until the other panel members vote.
            <?php else: ?>
                <strong><?= htmlspecialchars($suggesterName ?? '') ?></strong> submitted a penalty proposal. Choose <strong>Agree</strong> or <strong>Disagree</strong> before the 10-minute voting window ends.
            <?php endif; ?>
        </div>

        <!-- 30-min countdown -->
        <div class="timer-wrap">
            <div class="timer-top">
                <span class="timer-label">Time to vote</span>
                <span class="timer-num" id="vmcTimer"><?= sprintf('%02d:%02d', floor($roundSecondsRemaining / 60), $roundSecondsRemaining % 60) ?></span>
            </div>
            <div class="timer-bar-wrap">
                <div class="timer-bar-fill" id="vmcTimerBar"
                     style="width:<?= $roundSecondsRemaining > 0 ? round(($roundSecondsRemaining / 600) * 100) : 0 ?>%;
                            background:<?= $roundSecondsRemaining > 600 ? '#10b981' : ($roundSecondsRemaining > 180 ? '#f59e0b' : '#ef4444') ?>"></div>
            </div>
        </div>

        <!-- Suggestion box -->
        <div class="sug-box" id="vmcSugBox">
            <?php if ($suggestedDetails): ?>
                <div class="sug-cat">Category <?= $suggestedDetails['category'] ?></div>
                <div class="sug-desc"><?= htmlspecialchars($categoryDescriptions[$suggestedDetails['category']] ?? '') ?></div>
                <?php _renderSugDetails($suggestedDetails); ?>
            <?php endif; ?>
        </div>

        <!-- Tally -->
        <div class="tally-row">
            <div class="tally-cell tc-agree"><label>✅ Agree</label><span id="vmcAgree"><?= $agreeVotes ?></span></div>
            <div class="tally-cell tc-disagree"><label>❌ Disagree</label><span id="vmcDisagree"><?= $disagreeVotes ?></span></div>
            <div class="tally-cell tc-pending"><label>⏳ Pending</label><span id="vmcPending"><?= $totalVoters - $agreeVotes - $disagreeVotes ?></span></div>
        </div>
        <div class="tally-note" id="vmcNote">All <?= $totalVoters ?> voter(s) must agree to pass</div>

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
                        <div class="voter-name"><?= htmlspecialchars($m['full_name']) ?><?= $uid === $panelId ? ' <small style="color:var(--text-muted)">(you)</small>' : '' ?></div>
                        <div class="voter-meta"><?= htmlspecialchars(ucfirst($m['role'])) ?></div>
                    </div>
                    <div id="vpill-<?= $uid ?>">
                        <?php if ($isSug): ?>
                            <span class="v-pill suggester">🗣️ Suggester</span>
                        <?php elseif ($vote === null): ?>
                            <span class="v-pill pending">⏳ Pending</span>
                        <?php elseif ($vote > 0): ?>
                            <span class="v-pill agree">✅ Agree</span>
                        <?php else: ?>
                            <span class="v-pill disagree">❌ Disagree</span>
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
                    <form method="post" onsubmit="return confirm('Cancel your suggestion? The panel can submit a new suggestion immediately.')">
                        <input type="hidden" name="action" value="cancel_suggestion">
                        <input type="hidden" name="round_no" value="<?= $roundNo ?>">
                        <button type="submit" class="btn btn-danger" style="width:100%;padding:14px;font-size:15px">❌ Cancel My Suggestion</button>
                    </form>
                    <div style="text-align:center;font-size:11px;color:var(--text-muted);margin-top:8px">You are the suggester. Only the other panel members can vote.</div>
                    <div style="text-align:center;margin-top:12px;">
                        <button type="button" class="btn btn-secondary" onclick="closeVotingModal()" style="padding:6px 12px;font-size:12px;">Hide Window</button>
                    </div>
                <?php elseif (!$hasVoted): ?>
                    <div class="vote-btn-row">
                        <form method="post">
                            <input type="hidden" name="action" value="vote_on_suggestion">
                            <input type="hidden" name="round_no" value="<?= $roundNo ?>">
                            <input type="hidden" name="suggested_by" value="<?= $suggesterId ?>">
                            <input type="hidden" name="vote_agree" value="1">
                            <button type="submit" class="btn-agree">✅ AGREE</button>
                        </form>
                        <form method="post">
                            <input type="hidden" name="action" value="vote_on_suggestion">
                            <input type="hidden" name="round_no" value="<?= $roundNo ?>">
                            <input type="hidden" name="suggested_by" value="<?= $suggesterId ?>">
                            <input type="hidden" name="vote_agree" value="0">
                            <button type="submit" class="btn-disagree">❌ DISAGREE</button>
                        </form>
                    </div>
                <?php else: ?>
                    <div class="voted-conf">
                        <?= $currentMemberVote > 0 ? '✅ You voted <strong>AGREE</strong>' : '❌ You voted <strong>DISAGREE</strong>' ?>
                        <small>Waiting for other panel members…</small>
                        <div style="margin-top:12px;">
                            <button type="button" class="btn btn-secondary" onclick="closeVotingModal()" style="padding:6px 12px;font-size:12px;">Hide Window</button>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div style="text-align:center;margin-top:16px;font-size:12px;color:var(--text-muted)">
            This live voting window remains open for up to 10 minutes, or until consensus/cancellation.
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     COOLDOWN OVERLAY — shown globally after cancel/disagree/expire
══════════════════════════════════════════════════════════════════════ -->
<div id="cooldownModal" style="position:fixed;inset:0;z-index:9100;display:none;align-items:center;
    justify-content:center;background:rgba(7,17,31,.96);backdrop-filter:blur(12px);padding:16px">
    <div style="background:var(--bg-card);border:2px solid rgba(239,68,68,.4);border-radius:var(--radius-lg);
        padding:40px 36px;max-width:420px;width:100%;text-align:center;
        box-shadow:0 24px 48px rgba(0,0,0,.5),0 0 40px rgba(239,68,68,.2)">
        <div style="font-size:40px;margin-bottom:16px">⏳</div>
        <div style="font-family:var(--font-h);font-size:22px;font-weight:800;margin-bottom:8px">Voting Cooldown Active</div>
        <div style="font-size:13px;color:var(--text-muted);margin-bottom:20px;line-height:1.5" id="cooldownModalReason">
            Voting ended. Any panel member may suggest again after:
        </div>
        <div style="font-family:var(--font-h);font-size:48px;font-weight:800;color:#fcd34d;
            font-variant-numeric:tabular-nums;margin-bottom:8px" id="cooldownModalTimer">3:00</div>
        <div style="font-size:12px;color:var(--text-muted)">Panel will be unlocked automatically</div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     AWAITING ADMIN MODAL
══════════════════════════════════════════════════════════════════════ -->
<div id="awaitingAdminModal" style="position:fixed;inset:0;z-index:9200;display:<?= $isAwaitingAdmin ? 'flex' : 'none' ?>;align-items:center;justify-content:center;background:rgba(15,23,42,.9);backdrop-filter:blur(8px);padding:24px">
    <div style="background:var(--bg-card);border:2px solid #10b981;border-radius:var(--radius-lg);padding:40px;text-align:center;max-width:420px;width:100%;box-shadow:0 24px 48px rgba(0,0,0,.5)">
        <div style="font-size:48px;margin-bottom:16px">⏳</div>
        <div style="font-family:var(--font-h);font-size:24px;font-weight:800;color:#6ee7b7;margin-bottom:12px">Waiting for Admin</div>
        <div style="font-size:14px;color:var(--text-muted);line-height:1.6">
            The panel has reached a consensus.<br><br>
            Please wait while the Admin reviews and records the final decision. You will be redirected automatically once the case is closed.
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     CASE RESOLVED MODAL
══════════════════════════════════════════════════════════════════════ -->
<div id="caseResolvedModal" style="position:fixed;inset:0;z-index:9300;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.95);backdrop-filter:blur(12px);padding:24px">
    <div style="background:var(--bg-card);border:2px solid #3b82f6;border-radius:var(--radius-lg);padding:40px;text-align:center;max-width:420px;width:100%;box-shadow:0 24px 48px rgba(0,0,0,.5),0 0 40px rgba(59,130,246,.2)">
        <div style="font-size:48px;margin-bottom:16px">🎓</div>
        <div style="font-family:var(--font-h);font-size:24px;font-weight:800;color:#93c5fd;margin-bottom:12px">Case Resolved</div>
        <div style="font-size:14px;color:var(--text-muted);line-height:1.6;margin-bottom:24px">
            The Admin has finalized and recorded the decision. This case is now permanently closed.
        </div>
        <a href="upccdashboard.php" class="btn btn-primary" style="width:100%;justify-content:center;padding:14px;font-size:15px">Return to Dashboard</a>
        <div style="font-size:11px;color:var(--text-muted);margin-top:12px">Auto-redirecting in <span id="resolvedCountdown">5</span>s...</div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     HEARING PAUSED MODAL — shown when hearing is paused while panel is in
══════════════════════════════════════════════════════════════════════ -->
<div id="hearingPausedModal" style="position:fixed;inset:0;z-index:9200;display:none;align-items:center;justify-content:center;background:rgba(15,23,42,.96);backdrop-filter:blur(12px);padding:24px">
    <div style="background:var(--bg-card);border:2px solid rgba(239,68,68,.4);border-radius:var(--radius-lg);padding:40px;text-align:center;max-width:480px;width:100%;box-shadow:0 24px 48px rgba(0,0,0,.5),0 0 40px rgba(239,68,68,.2)">
        <div style="font-size:48px;margin-bottom:16px">⏸️</div>
        <div style="font-family:var(--font-h);font-size:24px;font-weight:800;color:#fca5a5;margin-bottom:8px">Hearing Has Been Paused</div>
        <div style="font-size:13px;color:var(--text-muted);line-height:1.6;margin-bottom:24px">
            <p id="pauseReasonText" style="margin:0 0 12px">The admin has paused the hearing.</p>
            <p style="margin:0;font-size:12px;font-style:italic">You can stay in the hearing and wait for it to resume, or return to your dashboard.</p>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <button type="button" class="btn btn-primary" onclick="closePauseModal(); document.getElementById('waitInHearingBtn').focus();" style="padding:14px;font-size:14px;">⏳ Wait & Stay</button>
            <button type="button" class="btn btn-secondary" id="exitPauseBtn" onclick="exitHearingDueToPause()" style="padding:14px;font-size:14px;">← Go to Dashboard</button>
        </div>
        <div id="waitInHearingBtn" style="margin-top:16px;padding:12px;background:rgba(16,185,129,.1);border:1px solid rgba(16,185,129,.3);border-radius:10px;font-size:12px;color:#6ee7b7">
            The hearing will automatically resume. Stay and keep your place in the panel.
        </div>
    </div>
</div>

<script>
// ─────────────────────────────────────────────────────────────────────────
//  CONSTANTS
// ─────────────────────────────────────────────────────────────────────────
const CASE_ID          = <?= $caseId ?>;
const PANEL_ID         = <?= $panelId ?>;
const SUGGESTER_ID     = <?= $suggesterId ?>;
const IS_SUGGESTER     = <?= $isCurrentUserSuggester ? 'true' : 'false' ?>;
const TOTAL_VOTERS     = <?= $totalVoters ?>;
const IS_ROUND_ACTIVE  = <?= $isRoundActive ? 'true' : 'false' ?>;
const ROUND_NO         = <?= $roundNo ?>;
const ROUND_ENDS_EPOCH = <?= $roundEndsAt ? strtotime($roundEndsAt) : 0 ?>;
const COOLDOWN_SECS    = <?= $cooldownRemainingSecs ?>;

// ─────────────────────────────────────────────────────────────────────────
//  STATE
// ─────────────────────────────────────────────────────────────────────────
let lastChatCount     = 0;
let lastVoteSig       = '';
let timerInterval     = null;
let cooldownInterval  = null;
let cooldownModalOpen = false;
let votingModalRound  = 0;
let isPartialReloading= false;
let lastRejoinTs      = parseInt(localStorage.getItem('lastRejoin_' + CASE_ID) || '0', 10);
let currentPauseState = <?= $isHearingPaused ? 'true' : 'false' ?>;
let pauseReason       = <?= $pauseReason ? json_encode($pauseReason) : 'null' ?>;
let pauseModalOpen    = false;
let resumeReloadQueued = false;

// ─────────────────────────────────────────────────────────────────────────
//  LIVE VOTING TIMER
// ─────────────────────────────────────────────────────────────────────────
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
            // Server will expire the round; just trigger a sync
            syncLive();
        }
    }
    tick();
    timerInterval = setInterval(tick, 1000);
}

// ─────────────────────────────────────────────────────────────────────────
//  COOLDOWN DISPLAY
// ─────────────────────────────────────────────────────────────────────────
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
    // Close voting modal
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

    // Keep hidden fields enabled so normal form semantics are preserved once unlocked.
    form.querySelectorAll('input[type="hidden"]').forEach(el => el.removeAttribute('disabled'));

    if (locked) {
        const m = Math.floor(Math.max(0, remainingSeconds) / 60);
        const s = Math.max(0, remainingSeconds) % 60;
        submitBtn.textContent = '⏳ Suggestion Locked';
        if (note) {
            note.style.display = 'block';
            note.textContent = `New suggestions are disabled for all panel members for ${m}:${String(s).padStart(2, '0')}.`;
        }
    } else {
        submitBtn.textContent = '📝 Suggest This Penalty';
        if (note) note.style.display = 'none';
    }

    if (category && locked) {
        category.value = '';
        toggleSugFields();
    }
}

// ─────────────────────────────────────────────────────────────────────────
//  SUGGEST FORM FIELD TOGGLING
// ─────────────────────────────────────────────────────────────────────────
function toggleSugFields() {
    const v = parseInt(document.getElementById('suggest_category')?.value || '0', 10);
    const show = id => { const el = document.getElementById(id); if (el) el.style.display = 'block'; };
    const hide = id => { const el = document.getElementById(id); if (el) el.style.display = 'none'; };
    hide('sugCat1'); hide('sugCat2'); hide('sugCat345');
    if (v === 1) show('sugCat1');
    else if (v === 2) show('sugCat2');
    else if (v >= 3 && v <= 5) {
        show('sugCat345');
        const texts = {
            3: 'Non-Readmission: The student cannot enroll next term but may finish the current one.',
            4: 'Exclusion: The student will be dropped from the roll immediately upon promulgation.',
            5: 'Expulsion: The student will be permanently disqualified from all higher education.',
        };
        const t = document.getElementById('sugCat345Text');
        if (t) t.textContent = texts[v] || '';
    }
}

function toggleSugHours() {
    const cb  = document.getElementById('sug_us');
    const box = document.getElementById('sugHoursBox');
    if (box) box.style.display = cb?.checked ? 'block' : 'none';
    if (!cb?.checked) {
        document.getElementById('sug_cat2_service_hours').value = '';
        document.querySelectorAll('.sug-hrs-btn').forEach(b => {
            b.style.background = 'rgba(0,0,0,.2)'; b.style.color = 'var(--text-muted)';
            b.style.border = '1px solid var(--border-glass)';
        });
    }
}

let selectedSugHours = '';
function selectSugHours(h, btn) {
    selectedSugHours = h;
    document.getElementById('sug_cat2_service_hours').value = h;
    const cus = document.getElementById('sug_cat2_service_hours_custom');
    if (cus) cus.style.display = h === 'OTHER' ? 'block' : 'none';
    if (h !== 'OTHER' && cus) cus.value = '';

    document.querySelectorAll('.sug-hrs-btn').forEach(b => {
        const active = b.dataset.h == h;
        b.style.background = active ? 'rgba(99,102,241,.2)' : 'rgba(0,0,0,.2)';
        b.style.color      = active ? '#c4b5fd' : 'var(--text-muted)';
        b.style.border     = active ? '1px solid rgba(99,102,241,.4)' : '1px solid var(--border-glass)';
        b.style.fontWeight = active ? '700' : '400';
    });
}

function bindSuggestFormValidation() {
    const form = document.getElementById('suggestForm');
    if (!form || form.dataset.bound === '1') return;
    form.dataset.bound = '1';
    form.addEventListener('submit', function(e) {
        if (cooldownModalOpen) {
            e.preventDefault();
            alert('Suggestion is temporarily disabled while cooldown is active.');
            return;
        }
        const v = parseInt(document.getElementById('suggest_category')?.value || '0', 10);
        if (v === 2) {
            const usChecked = document.getElementById('sug_us')?.checked;
            if (usChecked && !selectedSugHours) {
                e.preventDefault();
                alert('Please select the number of community service hours.');
                return;
            }
            const anyChecked = document.querySelectorAll('#sugCat2 input[type=checkbox]:checked').length > 0;
            if (!anyChecked) {
                e.preventDefault();
                alert('Please select at least one formative intervention.');
                return;
            }
        }
    });
}

// ─────────────────────────────────────────────────────────────────────────
//  FINAL DECISION FORM
// ─────────────────────────────────────────────────────────────────────────
function toggleFinalCatFields() {
    const cat = parseInt(document.getElementById('decided_category')?.value || '0', 10);
    const container = document.getElementById('finalDynamicFields');
    if (!container) return;

    if (cat === 1) {
        container.innerHTML = `
        <div style="margin-bottom:12px;padding:14px;background:rgba(0,0,0,.2);border-radius:10px;border:1px solid var(--border-glass)">
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;font-weight:600">Probation Details</div>
            <label style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px">Number of terms</label>
            <select name="cat1_terms" style="width:100%;margin-top:6px;padding:10px 14px;border-radius:10px;background:rgba(0,0,0,.25);color:var(--text-main);border:1px solid var(--border-glass);font-family:var(--font-b);font-size:13px">
                <option value="1">1 term</option>
                <option value="2">2 terms</option>
                <option value="3" selected>3 terms (maximum)</option>
            </select>
            <div style="margin-top:8px;font-size:12px;color:var(--text-muted);line-height:1.5">Any subsequent major offense triggers Suspension or Non-Readmission.</div>
        </div>`;
    } else if (cat === 2) {
        container.innerHTML = `
        <div style="margin-bottom:12px;padding:14px;background:rgba(0,0,0,.2);border-radius:10px;border:1px solid var(--border-glass)">
            <div style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:10px;font-weight:600">Formative Interventions</div>
            <label style="font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:8px;cursor:pointer">
                <input type="checkbox" name="cat2_university_service" value="1" onchange="toggleFinalHours(this)"> University Service
            </label>
            <div id="finalHoursBox" style="display:none;margin-left:22px;margin-bottom:10px">
                <label style="font-size:11px;color:var(--text-muted)">Required Hours</label>
                <select name="cat2_service_hours" style="width:100%;margin-top:4px;padding:8px 12px;border-radius:8px;background:rgba(0,0,0,.25);color:var(--text-main);border:1px solid var(--border-glass);font-family:var(--font-b);font-size:13px" onchange="this.nextElementSibling.style.display = this.value === 'OTHER' ? 'block' : 'none'">
                    <?php foreach ([100,150,200,250,300,350,400,450,500] as $h): ?>
                        <option value="<?= $h ?>"><?= $h ?> hours</option>
                    <?php endforeach; ?>
                    <option value="OTHER">Other</option>
                </select>
                <input type="number" name="cat2_service_hours_custom" min="1" placeholder="Custom hours" style="display:none;width:100%;margin-top:6px;padding:8px 12px;border-radius:8px;background:rgba(0,0,0,.25);color:var(--text-main);border:1px solid var(--border-glass);font-family:var(--font-b);font-size:13px">
            </div>
            <label style="font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:8px;cursor:pointer">
                <input type="checkbox" name="cat2_counseling" value="1"> Referral for Counseling
            </label>
            <label style="font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:8px;cursor:pointer">
                <input type="checkbox" name="cat2_lectures" value="1"> Attendance to Discipline Education Program
            </label>
            <label style="font-size:13px;display:flex;align-items:center;gap:8px;cursor:pointer">
                <input type="checkbox" name="cat2_evaluation" value="1"> Evaluation
            </label>
        </div>`;
    } else if (cat >= 3 && cat <= 5) {
        const msgs = {
            3: '⚠️ Non-Readmission: The student will not be readmitted next term. Account will be frozen.',
            4: '⚠️ Exclusion: The student will be dropped from the roll. Account will be frozen.',
            5: '⚠️ Expulsion: The student will be permanently disqualified. Account will be permanently frozen.',
        };
        container.innerHTML = `
        <div style="margin-bottom:12px;padding:14px;background:rgba(239,68,68,.08);border-radius:10px;border:1px solid rgba(239,68,68,.2)">
            <div style="font-size:13px;color:#fca5a5;font-weight:600;line-height:1.5">${msgs[cat] || ''}</div>
        </div>`;
    } else {
        container.innerHTML = '';
    }
}

function toggleFinalHours(cb) {
    const box = document.getElementById('finalHoursBox');
    if (box) box.style.display = cb.checked ? 'block' : 'none';
}

// ─────────────────────────────────────────────────────────────────────────
//  UTILS
// ─────────────────────────────────────────────────────────────────────────
function esc(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function voteSig(votes) {
    return (votes || []).map(v => v.upcc_id + ':' + v.vote_category + ':' + v.updated_at).join('|');
}

// ─────────────────────────────────────────────────────────────────────────
//  PARTIAL RELOAD — refreshes voting section + modal without full page load
// ─────────────────────────────────────────────────────────────────────────
function partialReload() {
    if (isPartialReloading) return;
    isPartialReloading = true;
    fetch(location.href + (location.href.includes('?') ? '&' : '?') + '_t=' + Date.now())
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            ['voting-section', 'votingModal'].forEach(id => {
                const n = doc.getElementById(id);
                const o = document.getElementById(id);
                if (n && o) o.innerHTML = n.innerHTML;
            });
            // Re-init form toggles
            initFormToggles();
            isPartialReloading = false;
        })
        .catch(() => { isPartialReloading = false; });
}

// ─────────────────────────────────────────────────────────────────────────
//  CHAT RENDERING
// ─────────────────────────────────────────────────────────────────────────
function renderChat(msgs) {
    const box = document.getElementById('live-chat-box');
    if (!box) return;
    if (!msgs || !msgs.length) {
        box.innerHTML = '<div style="text-align:center;color:var(--text-muted);font-size:11px">No messages yet.</div>';
        return;
    }
    const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 60;
    box.innerHTML = msgs.map(m => {
        if (m.is_system) {
            return `<div style="text-align:center;margin:14px 0">
                <span style="background:rgba(240,192,64,.12);color:#f8d77c;border:1px solid rgba(240,192,64,.25);
                    padding:6px 14px;border-radius:20px;font-size:11px;font-weight:700">${esc(m.message)}</span>
            </div>`;
        }
        const isMe   = !!m.is_me;
        const bg     = isMe ? 'rgba(79,123,255,.12)' : 'rgba(255,255,255,.03)';
        const border = isMe ? '1px solid rgba(79,123,255,.25)' : '1px solid var(--border-glass)';
        return `<div class="chat-item" style="background:${bg};border:${border}">
            <div class="chat-head">
                <div><div class="chat-name">${esc(m.sender_name)}</div><div class="chat-role">${esc(m.sender_role)}</div></div>
                <div class="chat-time">${esc(m.created_at)}</div>
            </div>
            <div class="chat-msg">${esc(m.message)}</div>
        </div>`;
    }).join('');
    if (atBottom) box.scrollTop = box.scrollHeight;
}

// ─────────────────────────────────────────────────────────────────────────
//  UPDATE VOTER LIST IN MODAL (live, no reload)
// ─────────────────────────────────────────────────────────────────────────
function updateVoterPill(uid, cat, suggesterId) {
    const item = document.getElementById('voter-' + uid);
    const pill = document.getElementById('vpill-' + uid);
    if (!item || !pill) return;
    if (uid === suggesterId) {
        item.classList.remove('v-agree', 'v-disagree');
        item.classList.add('v-suggester');
        pill.innerHTML = '<span class="v-pill suggester">🗣️ Suggester</span>';
        return;
    }
    item.classList.remove('v-agree', 'v-disagree', 'v-suggester');
    if (cat === null) {
        pill.innerHTML = '<span class="v-pill pending">⏳ Pending</span>';
    } else if (cat > 0) {
        item.classList.add('v-agree');
        pill.innerHTML = '<span class="v-pill agree">✅ Agree</span>';
    } else {
        item.classList.add('v-disagree');
        pill.innerHTML = '<span class="v-pill disagree">❌ Disagree</span>';
    }
}

function openVotingModalForRound(roundNo) {
    const modal = document.getElementById('votingModal');
    if (!modal || roundNo <= 0) return;
    votingModalRound = roundNo;
    modal.classList.add('open');
}

function closeVotingModal() {
    const modal = document.getElementById('votingModal');
    if (modal) modal.classList.remove('open');
}

function normalizePauseState(value) {
    return value === true || value === 1 || value === '1' || value === 'true';
}

// ─────────────────────────────────────────────────────────────────────────
// ─────────────────────────────────────────────────────────────────────────
//  PAUSE STATE HANDLERS
// ─────────────────────────────────────────────────────────────────────────
function updatePauseUI(isPaused, pauseReason = null) {
    const heroMeta = document.querySelector('.hero-meta');
    if (!heroMeta) return;
    
    // Remove old pause pill if exists
    const oldPausePill = heroMeta.querySelector('[data-pause-pill]');
    if (oldPausePill) oldPausePill.remove();
    
    if (isPaused) {
        const pill = document.createElement('span');
        pill.className = 'pill';
        pill.setAttribute('data-pause-pill', '1');
        pill.style.background = '#fca5a5';
        pill.style.color = '#7f1d1d';
        pill.style.borderColor = '#ef4444';
        pill.innerHTML = '⏸️ HEARING PAUSED';
        heroMeta.appendChild(pill);
    } else {
        const pill = document.createElement('span');
        pill.className = 'pill';
        pill.setAttribute('data-pause-pill', '1');
        pill.style.background = '#86efac';
        pill.style.color = '#15803d';
        pill.style.borderColor = '#22c55e';
        pill.innerHTML = '<span style="display:inline-block;width:6px;height:6px;background:#15803d;border-radius:50%;margin-right:4px"></span> HEARING LIVE';
        heroMeta.appendChild(pill);
    }
}

function disablePauseableControls() {
    // Disable chat
    const chatInput = document.getElementById('chat_message_input');
    const chatSubmit = document.getElementById('chat_submit_btn');
    if (chatInput) {
        chatInput.disabled = true;
        chatInput.placeholder = 'Chat disabled - hearing is paused';
    }
    if (chatSubmit) chatSubmit.disabled = true;
    
    // Disable voting buttons
    document.querySelectorAll('.btn-vote-agree, .btn-vote-disagree').forEach(btn => btn.disabled = true);
    
    // Disable suggestion form
    const suggestForm = document.getElementById('suggestForm');
    if (suggestForm) {
        suggestForm.querySelectorAll('input:not([type=\"hidden\"]), select, textarea, button').forEach(el => el.disabled = true);
    }
}

function enablePauseableControls() {
    // Only re-enable if hearing is still open (not just unpaused, but actually open)
    const hearingOpen = <?= $isHearingOpen ? 'true' : 'false' ?>;
    if (!hearingOpen) return;
    
    // Enable chat
    const chatInput = document.getElementById('chat_message_input');
    const chatSubmit = document.getElementById('chat_submit_btn');
    if (chatInput) {
        chatInput.disabled = false;
        chatInput.placeholder = 'Type your message…';
    }
    if (chatSubmit) chatSubmit.disabled = false;
    
    // Enable voting buttons
    document.querySelectorAll('.btn-vote-agree, .btn-vote-disagree').forEach(btn => btn.disabled = false);
    
    // Enable suggestion form
    const suggestForm = document.getElementById('suggestForm');
    if (suggestForm) {
        suggestForm.querySelectorAll('input:not([type=\"hidden\"]), select, textarea, button').forEach(el => el.disabled = false);
    }
}

function disablePauseableControls() {
    // Disable chat
    const chatInput = document.getElementById('chat_message_input');
    const chatSubmit = document.getElementById('chat_submit_btn');
    if (chatInput) {
        chatInput.disabled = true;
        chatInput.placeholder = 'Chat disabled - hearing is paused';
    }
    if (chatSubmit) chatSubmit.disabled = true;
    
    // Disable voting buttons
    document.querySelectorAll('.btn-vote-agree, .btn-vote-disagree').forEach(btn => btn.disabled = true);
    
    // Disable suggestion form
    const suggestForm = document.getElementById('suggestForm');
    if (suggestForm) {
        suggestForm.querySelectorAll('input:not([type="hidden"]), select, textarea, button').forEach(el => el.disabled = true);
    }
}

function enablePauseableControls() {
    // Only re-enable if hearing is still open (not just unpaused, but actually open)
    const hearingOpen = <?= $isHearingOpen ? 'true' : 'false' ?>;
    if (!hearingOpen) return;
    
    // Enable chat
    const chatInput = document.getElementById('chat_message_input');
    const chatSubmit = document.getElementById('chat_submit_btn');
    if (chatInput) {
        chatInput.disabled = false;
        chatInput.placeholder = 'Type your message…';
    }
    if (chatSubmit) chatSubmit.disabled = false;
    
    // Enable voting buttons
    document.querySelectorAll('.btn-vote-agree, .btn-vote-disagree').forEach(btn => btn.disabled = false);
    
    // Enable suggestion form
    const suggestForm = document.getElementById('suggestForm');
    if (suggestForm) {
        suggestForm.querySelectorAll('input:not([type="hidden"]), select, textarea, button').forEach(el => el.disabled = false);
    }
}

function showPauseModal(pauseReasonStr = null) {
    const modal = document.getElementById('hearingPausedModal');
    const reasonEl = document.getElementById('pauseReasonText');
    if (!modal) return;
    
    if (pauseReasonStr === 'AUTO_PAUSE_ADMIN_LEFT') {
        reasonEl.textContent = 'The admin has disconnected. The hearing is paused while they reconnect.';
    } else {
        reasonEl.textContent = 'The admin has paused the hearing.';
    }
    
    modal.style.display = 'flex';
    pauseModalOpen = true;
    disablePauseableControls();
}

function closePauseModal() {
    const modal = document.getElementById('hearingPausedModal');
    if (!modal) return;
    modal.style.display = 'none';
    pauseModalOpen = false;
    enablePauseableControls();
}

function exitHearingDueToPause() {
    const fd = new FormData();
    fd.append('action', 'exit_hearing');
    fd.append('case_id', CASE_ID);
    
    fetch('../api/upcc_case_live.php', { method: 'POST', body: fd })
        .then(() => {
            window.location.href = 'upccdashboard.php?msg=exited_due_to_pause';
        })
        .catch(() => {
            window.location.href = 'upccdashboard.php';
        });
}

// ─────────────────────────────────────────────────────────────────────────
//  MAIN LIVE SYNC LOOP
// ─────────────────────────────────────────────────────────────────────────
let prevRoundActive = IS_ROUND_ACTIVE;
let prevConsensus   = <?= json_encode($consensusCategory > 0) ?>;
let prevCooldown    = <?= json_encode($isInCooldown) ?>;
let lastRoundClosureNoticeKey = '';

function showToast(title, message, type = 'info') {
    const wrap = document.createElement('div');
    wrap.style.position = 'fixed';
    wrap.style.right = '16px';
    wrap.style.bottom = '16px';
    wrap.style.zIndex = '9999';
    wrap.style.maxWidth = '340px';
    wrap.style.padding = '12px 14px';
    wrap.style.borderRadius = '10px';
    wrap.style.border = '1px solid rgba(255,255,255,.15)';
    wrap.style.background = type === 'warning' ? 'rgba(245, 158, 11, .18)' : 'rgba(59, 130, 246, .18)';
    wrap.style.backdropFilter = 'blur(8px)';
    wrap.style.color = '#e2e8f0';
    wrap.style.boxShadow = '0 8px 20px rgba(0,0,0,.35)';
    wrap.innerHTML = `<div style="font-weight:700;font-size:12px;margin-bottom:2px">${esc(title)}</div>
                      <div style="font-size:12px;line-height:1.35">${esc(message)}</div>`;
    document.body.appendChild(wrap);
    setTimeout(() => wrap.remove(), 4200);
}

let redirectTimer = null;
function showCaseResolvedModal() {
    if (document.getElementById('caseResolvedModal').style.display === 'flex') return;
    
    // Hide other modals to ensure clean UI
    document.getElementById('votingModal')?.classList.remove('open');
    if (document.getElementById('cooldownModal')) document.getElementById('cooldownModal').style.display = 'none';
    if (document.getElementById('awaitingAdminModal')) document.getElementById('awaitingAdminModal').style.display = 'none';
    
    document.getElementById('caseResolvedModal').style.display = 'flex';
    let secs = 5;
    const el = document.getElementById('resolvedCountdown');
    redirectTimer = setInterval(() => {
        secs--;
        if (el) el.textContent = secs;
        if (secs <= 0) {
            clearInterval(redirectTimer);
            window.location.href = 'upccdashboard.php?hearing_msg=' + encodeURIComponent('The case was resolved and closed.');
        }
    }, 1000);
}

function syncLive() {
    fetch('../api/upcc_case_live.php?case_id=' + CASE_ID + '&actor=upcc&t=' + Date.now(), {
        cache: 'no-store'
    })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;

            // ── CHECK IF CASE IS RESOLVED ─────────────────────────────
            if (data.is_closed || (data.case_status && (data.case_status === 'CLOSED' || data.case_status === 'RESOLVED'))) {
                showCaseResolvedModal();
                return; // Stop processing further state changes
            }

            // ── CHAT ──────────────────────────────────────────────────
            if (Array.isArray(data.chat) && data.chat.length !== lastChatCount) {
                renderChat(data.chat);
                lastChatCount = data.chat.length;
            }

            // ── STUDENT EXPLANATION LIVE UPDATE ───────────────────────
            if (data.student_explanation && data.student_explanation.submitted_at) {
                const block = document.getElementById('studentExplanationBlock');
                const text = document.getElementById('explanationText');
                const time = document.getElementById('explanationTime');
                
                if (block && block.style.display === 'none') {
                    block.style.display = 'block';
                    if (text) text.textContent = data.student_explanation.text || '';
                    if (time) time.textContent = 'Submitted ' + data.student_explanation.submitted_at;
                    
                    const attachments = document.getElementById('explanationAttachments');
                    if (attachments) {
                        attachments.innerHTML = '';
                        if (data.student_explanation.image) {
                            attachments.innerHTML += `<a href="../${data.student_explanation.image}" target="_blank" style="display: block; border-radius: 8px; overflow: hidden; border: 1px solid var(--border-glass);">
                                <img src="../${data.student_explanation.image}" style="max-width: 80px; max-height: 80px; display: block; object-fit: cover;">
                            </a>`;
                        }
                        if (data.student_explanation.pdf) {
                            attachments.innerHTML += `<a href="../${data.student_explanation.pdf}" target="_blank" style="display: flex; align-items: center; gap: 8px; padding: 6px 12px; background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); border-radius: 8px; text-decoration: none; color: #fca5a5; font-size: 11px; font-weight: 600;">
                                <span>📄 View PDF Explanation</span>
                            </a>`;
                        }
                    }
                }
            }

            // ── VOTE STATE ────────────────────────────────────────────
            const roundActive  = data.round && parseInt(data.round.is_active, 10) === 1;
            const hasConsensus = parseInt(data.consensus || 0, 10) > 0;
            const hasCooldown  = !!data.cooldown;
            const votes        = Array.isArray(data.votes) ? data.votes : [];
            const sig          = voteSig(votes);
            const roundNoNow   = data.round ? parseInt(data.round.round_no || 0, 10) : 0;

            if (sig !== lastVoteSig) {
                lastVoteSig = sig;

                // Update tally counts
                let agree = 0, disagree = 0;
                const suggId = data.round ? parseInt(data.round.suggested_by || 0, 10) : 0;
                votes.forEach(v => {
                    if (parseInt(v.upcc_id, 10) !== suggId) {
                        if (parseInt(v.vote_category, 10) > 0) agree++;
                        else disagree++;
                    }
                    updateVoterPill(parseInt(v.upcc_id, 10), parseInt(v.vote_category, 10), suggId);
                });
                const pending = Math.max(0, TOTAL_VOTERS - agree - disagree);
                ['vmcAgree','panelAgree'].forEach(id => { const e = document.getElementById(id); if (e) e.textContent = agree; });
                ['vmcDisagree','panelDisagree'].forEach(id => { const e = document.getElementById(id); if (e) e.textContent = disagree; });
                ['vmcPending','panelPending'].forEach(id => { const e = document.getElementById(id); if (e) e.textContent = pending; });
            }

            // ── TRANSITIONS ───────────────────────────────────────────
            const needsReload =
                (roundActive !== prevRoundActive) ||
                (hasConsensus !== prevConsensus)  ||
                (hasCooldown  !== prevCooldown);

            if (prevRoundActive && !roundActive && !hasConsensus) {
                const closeKey = String(roundNoNow || '0') + '|' + String(data.case_status || '');
                if (closeKey !== lastRoundClosureNoticeKey) {
                    lastRoundClosureNoticeKey = closeKey;
                    showToast(
                        'Voting Round Closed',
                        'The 10-minute voting window ended or the proposal was closed. Panel may submit a new suggestion.',
                        'warning'
                    );
                }
            }

            if (needsReload) {
                prevRoundActive = roundActive;
                prevConsensus   = hasConsensus;
                prevCooldown    = hasCooldown;
                partialReload();
            }

            // ── UI UPDATES FOR ACTIVE VOTE ─────────────────────────────
            const btnBack = document.getElementById('backToDashboardBtn');
            if (btnBack) {
                btnBack.style.pointerEvents = roundActive ? 'none' : 'auto';
                btnBack.style.opacity = roundActive ? '0.5' : '1';
                btnBack.title = roundActive ? 'Cannot exit while voting is active' : '';
            }

            // ── MODAL VISIBILITY ──────────────────────────────────────
            const modal = document.getElementById('votingModal');
            if (modal) {
                if (roundActive && !cooldownModalOpen) {
                    const roundNo = data.round ? parseInt(data.round.round_no || 0, 10) : 0;
                    const suggId = data.round ? parseInt(data.round.suggested_by || 0, 10) : 0;
                    const isCurrentUserSuggesterNow = PANEL_ID === suggId;
                    const myVote = votes.find(v => parseInt(v.upcc_id, 10) === PANEL_ID) || null;
                    // Only forcefully open the modal if the user HAS NOT VOTED and is NOT the suggester.
                    // If they are the suggester, or if they have already voted, we do not force it open 
                    // (so if they hide it, it stays hidden).
                    const shouldOpenForAction = !isCurrentUserSuggesterNow && !myVote;
                    
                    if (shouldOpenForAction) {
                        openVotingModalForRound(roundNo);
                    }
                } else if (!roundActive && !hasConsensus) {
                    modal.classList.remove('open');
                    votingModalRound = 0;
                }
            }

            // ── COOLDOWN MODAL ────────────────────────────────────────
            if (hasCooldown && !cooldownModalOpen) {
                const rem = parseInt(data.cooldown_seconds || 180, 10);
                startCooldownDisplay(rem, null);
            } else if (!hasCooldown && !roundActive) {
                setSuggestionLocked(false, 0);
            }

            // ── PAUSE STATE HANDLING ──────────────────────────────────
            const nextPauseState = normalizePauseState(data.is_paused);
            if (data.is_paused !== undefined && nextPauseState !== currentPauseState) {
                currentPauseState = nextPauseState;
                pauseReason = data.pause_reason || null;
                
                // Update UI to reflect pause state
                updatePauseUI(currentPauseState, pauseReason);
                
                if (currentPauseState) {
                    // Hearing is now paused
                    const reason = pauseReason === 'AUTO_PAUSE_ADMIN_LEFT' 
                        ? 'Admin disconnected' 
                        : 'Admin paused the hearing';
                    showToast('⏸️ Hearing Paused', reason, 'warning');
                    
                    // Disable voting/chat when paused
                    disablePauseableControls();
                    
                    // Show pause modal so panel members have options
                    showPauseModal(pauseReason);
                } else {
                    // Hearing is now resumed
                    showToast('▶️ Hearing Resumed', 'You may continue voting and messaging.', 'success');
                    
                    // Close modal if open
                    if (pauseModalOpen) {
                        closePauseModal();
                    }
                    
                    // Re-enable controls
                    enablePauseableControls();

                    // Reload once so every server-rendered badge/placeholder syncs to the live state
                    if (!resumeReloadQueued) {
                        resumeReloadQueued = true;
                        setTimeout(() => window.location.reload(), 500);
                    }
                }
            }

            // ── CONSENSUS FLASH & MODAL ───────────────────────────────
            const awaitingModal = document.getElementById('awaitingAdminModal');
            if (hasConsensus) {
                const flash = document.getElementById('vmcResult');
                if (flash) {
                    flash.className = 'result-flash consensus';
                    flash.style.display = 'block';
                    flash.textContent   = '✅ Panel consensus reached on Category ' + data.consensus + '. Awaiting admin finalization.';
                }
                if (awaitingModal) awaitingModal.style.display = 'flex';
                modal?.classList.remove('open');
                votingModalRound = 0;
            } else {
                if (awaitingModal) awaitingModal.style.display = 'none';
                const flash = document.getElementById('vmcResult');
                if (flash) flash.style.display = 'none';
            }

            // ── PRESENCE ──────────────────────────────────────────────
            const overlay = document.getElementById('presenceOverlay');
            if (overlay) {
                const stat = String(data.my_status || 'ADMITTED').toUpperCase();
                if (stat === 'ADMITTED') {
                    overlay.classList.remove('open');
                    overlay.style.display = 'none';
                } else {
                    overlay.classList.add('open');
                    overlay.style.display = 'flex';
                    
                    const pTitle = document.getElementById('presenceTitle');
                    const pText  = document.getElementById('presenceText');
                    const rBtn   = document.getElementById('requestJoinBtn');
                    const eBtn   = document.getElementById('exitHearingBtn');

                    if (stat === 'WAITING') {
                        if (pTitle) pTitle.textContent = 'Awaiting Admission';
                        if (pText)  pText.textContent  = 'Your rejoin request has been sent. Please wait for the Admin to let you in.';
                        if (rBtn)   rBtn.style.display = 'none';
                        if (eBtn)   eBtn.style.display = 'flex';
                    } else if (stat === 'EXITED') {
                        if (pTitle) pTitle.textContent = 'Hearing Exited';
                        if (pText)  pText.textContent  = 'You are currently outside the hearing session. Request to rejoin if needed.';
                        if (rBtn)   rBtn.style.display = 'flex';
                        if (eBtn)   eBtn.style.display = 'flex';
                    } else {
                        // generic fallback
                        if (pTitle) pTitle.textContent = 'Waiting Room';
                        if (pText)  pText.textContent  = 'Please wait for the Admin to let you in.';
                        if (rBtn)   rBtn.style.display = 'flex';
                        if (eBtn)   eBtn.style.display = 'flex';
                    }
                }
            }
        })
        .catch(err => console.warn('[sync]', err));
}

// ─────────────────────────────────────────────────────────────────────────
//  CHAT FORM — AJAX
// ─────────────────────────────────────────────────────────────────────────
document.getElementById('chat-form')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('actor', 'upcc');
    fetch('../api/upcc_case_live.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                document.getElementById('chat_message').value = '';
                cancelReply();
                syncLive();
            }
        })
        .catch(err => console.error(err));
});

function setReply(id, name, text) {
    document.getElementById('reply_to').value = id;
    document.getElementById('reply-to-name').textContent = name;
    document.getElementById('reply-to-text').textContent = text;
    document.getElementById('replying-to-container').style.display = 'block';
    document.getElementById('chat_message')?.focus();
}
function cancelReply() {
    document.getElementById('reply_to').value = '';
    document.getElementById('replying-to-container').style.display = 'none';
}

// ─────────────────────────────────────────────────────────────────────────
//  PRESENCE
// ─────────────────────────────────────────────────────────────────────────
function pingPresence() {
    const fd = new FormData();
    fd.append('action', 'ping_presence');
    fd.append('case_id', CASE_ID);
    fd.append('actor', 'upcc');
    fetch('../api/upcc_case_live.php', { method: 'POST', body: fd }).catch(() => {});
}

function requestJoinHearing() {
    const now  = Math.floor(Date.now() / 1000);
    const diff = now - lastRejoinTs;
    if (diff < 300) { alert('Please wait ' + Math.floor((300 - diff) / 60) + 'm before requesting again.'); return; }
    const fd = new FormData();
    fd.append('action',  'request_rejoin');
    fd.append('case_id', CASE_ID);
    fd.append('actor', 'upcc');
    fetch('../api/upcc_case_live.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.ok) { lastRejoinTs = now; localStorage.setItem('lastRejoin_' + CASE_ID, now); alert('✓ Rejoin request sent.'); }
            else alert('Error: ' + (res.message || 'Could not send request'));
        })
        .catch(() => alert('Network error'));
}

function exitHearing() {
    if (typeof roundActiveNow !== 'undefined' && roundActiveNow) {
        alert('Cannot exit the hearing while a voting round is active.');
        return;
    }
    if (!confirm('Exit this hearing? You will need admin approval to rejoin.')) return;
    const fd = new FormData();
    fd.append('action', 'exit_hearing'); fd.append('case_id', CASE_ID);
    fd.append('actor', 'upcc');
    fetch('../api/upcc_case_live.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { if (res.ok) location.href = 'upccdashboard.php'; })
        .catch(() => alert('Network error'));
}

// ─────────────────────────────────────────────────────────────────────────
//  BACK BUTTON / UNLOAD GUARD
// ─────────────────────────────────────────────────────────────────────────
let isSubmitting = false;
document.addEventListener('submit', e => { if (e.target.id !== 'chat-form') isSubmitting = true; });
document.getElementById('backToDashboardBtn')?.addEventListener('click', function(e) {
    if (typeof roundActiveNow !== 'undefined' && roundActiveNow) {
        e.preventDefault();
        alert('Cannot exit the hearing while a voting round is active.');
        return;
    }
    const open = <?= $isHearingOpen ? 'true' : 'false' ?>;
    const stat = <?= json_encode($myPresenceStatus) ?>;
    if (open && stat === 'ADMITTED') {
        e.preventDefault();
        if (confirm('Leave this hearing? You will need to request to rejoin.')) {
            exitHearing();
        }
    }
});
window.addEventListener('beforeunload', e => {
    if (isSubmitting) return;
    if (typeof roundActiveNow !== 'undefined' && roundActiveNow) {
        e.preventDefault(); e.returnValue = 'Cannot leave while voting is active.';
    } else if (<?= $isHearingOpen ? 'true' : 'false' ?> && <?= json_encode($myPresenceStatus) ?> === 'ADMITTED') {
        e.preventDefault(); e.returnValue = 'Leave hearing?';
    }
});

// ─────────────────────────────────────────────────────────────────────────
//  SECURITY
// ─────────────────────────────────────────────────────────────────────────
document.addEventListener('contextmenu', e => e.preventDefault());
document.body.style.userSelect = 'none';
document.addEventListener('keydown', e => { if (e.ctrlKey && ['p','s'].includes(e.key)) e.preventDefault(); });

// ─────────────────────────────────────────────────────────────────────────
//  INIT
// ─────────────────────────────────────────────────────────────────────────
function initFormToggles() {
    // suggestion form
    const sugCat = document.getElementById('suggest_category');
    if (sugCat?.value) toggleSugFields();
    bindSuggestFormValidation();
    // final decision form
    const finalCat = document.getElementById('decided_category');
    if (finalCat?.value) toggleFinalCatFields();

    const suggestSummary = document.getElementById('suggestDetailsSummary');
    const suggestDetails = document.getElementById('suggestDetails');
    if (suggestSummary && suggestDetails && !suggestSummary.dataset.bound) {
        suggestSummary.dataset.bound = '1';
        suggestSummary.addEventListener('click', function(e) {
            e.preventDefault();
            suggestDetails.open = !suggestDetails.open;
        });
    }
}

initFormToggles();

// Start cooldown display if active on page load
<?php if ($isInCooldown && $cooldownRemainingSecs > 0): ?>
startCooldownDisplay(<?= $cooldownRemainingSecs ?>, null);
<?php endif; ?>

startVotingTimer();
setInterval(syncLive,    3000);   // poll every 3 seconds
setInterval(pingPresence, 5000);  // ping presence every 5 seconds
syncLive(); // immediate first call
</script>
</body>
</html>