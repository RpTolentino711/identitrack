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
        // Cat 3/4/5 — include rationale / notes
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

function _formatCsHoursStr(float|int|string $shVal): string {
    $shVal = (float)$shVal;
    if ($shVal <= 0) return '';
    $hPart = (int)floor($shVal);
    $mPart = (int)round(($shVal - $hPart) * 60);
    if ($mPart >= 60) {
        $hPart += 1;
        $mPart = 0;
    }
    $parts = [];
    if ($hPart > 0) {
        $parts[] = $hPart . ' Hr' . ($hPart > 1 ? 's' : '');
    }
    if ($mPart > 0) {
        $parts[] = $mPart . ' Min' . ($mPart > 1 ? 's' : '');
    }
    return !empty($parts) ? implode(' ', $parts) . ' CS' : '';
}

function _catLabel(int $cat, array $details = []): string {
    $hrsStr = !empty($details['service_hours']) && (float)$details['service_hours'] > 0
        ? ' (' . _formatCsHoursStr($details['service_hours']) . ')'
        : '';
    $interventionsList = !empty($details['interventions']) && is_array($details['interventions'])
        ? ' (' . implode(', ', $details['interventions']) . ')'
        : '';

    $labels = [
        1 => 'Category 1 — Formal Reprimand & Active Semester Probation',
        2 => 'Category 2 — Formative Intervention' . $interventionsList . $hrsStr,
        3 => 'Category 3 — Non-Readmission / Suspension' . $hrsStr,
        4 => 'Category 4 — Exclusion / Mandatory Dismissal',
        5 => 'Category 5 — Summary Expulsion & Police Referral',
    ];
    return $labels[$cat] ?? "Category {$cat}{$hrsStr}";
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
                u.full_name AS suggester_name,
                TIMESTAMPDIFF(SECOND, NOW(), r.ends_at) AS remaining_seconds
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
                (SELECT u2.full_name FROM upcc_case_vote v2 JOIN upcc_user u2 ON u2.upcc_id = v2.upcc_id WHERE v2.case_id = r.case_id AND v2.round_no = r.round_no AND v2.vote_category > 0 ORDER BY v2.created_at ASC LIMIT 1) AS suggester_name,
                TIMESTAMPDIFF(SECOND, NOW(), r.ends_at) AS remaining_seconds
         FROM upcc_case_vote_round r
         WHERE r.case_id = :c AND r.is_active = 1
         ORDER BY r.round_no DESC LIMIT 1",
        [':c' => $caseId]
    );
}

if ($activeRound && isset($activeRound['remaining_seconds']) && (int)$activeRound['remaining_seconds'] <= 0) {
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
$roundSecondsRemaining    = $activeRound ? max(0, (int)($activeRound['remaining_seconds'] ?? 0)) : 0;

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
$case = db_one("SELECT uc.*, " . db_decrypt_cols(['case_summary', 'student_explanation_text'], 'uc') . ",
        CONCAT(" . db_decrypt_col('student_fn', 's') . ",' '," . db_decrypt_col('student_ln', 's') . ") AS student_name,
        " . db_decrypt_cols(['student_fn', 'student_ln', 'student_email', 'phone_number', 'home_address'], 's') . ",
        s.year_level, s.section, s.program, s.school,
        d.dept_name AS assigned_dept_name
    FROM upcc_case uc
    JOIN student s ON s.student_id = uc.student_id
    LEFT JOIN departments d ON d.dept_id = uc.assigned_department_id
    WHERE uc.case_id = :c LIMIT 1", [':c' => $caseId, ':__enckey' => db_encryption_key()]);

$consensusCategory = (int)($case['hearing_vote_consensus_category'] ?? 0);
$isAwaitingAdmin   = $consensusCategory > 0 && (string)($case['status'] ?? '') === 'AWAITING_ADMIN_FINALIZATION';

// Cooldown for current user
$activeCooldown = db_one(
    "SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(cooldown_until)) AS remaining
     FROM upcc_suggestion_cooldown
     WHERE case_id = :c AND cooldown_until > NOW()",
    [':c' => $caseId]
);
$cooldownRemainingSecs = max(0, (int)$activeCooldown['remaining'] ?? 0);
$isInCooldown = $cooldownRemainingSecs > 0;

$showVotingPopup = $isRoundActive && $suggestedDetails !== null;

// ── OTHER QUERIES ─────────────────────────────────────────────────────────
$offenses = db_all(
    "SELECT o.offense_id, o.level, " . db_decrypt_col('description', 'o') . " AS description, o.dismissal_reason, COALESCE(o.evidence_file, o.incident_photo) AS evidence_file, o.date_committed, o.status,
            ot.code, ot.name AS offense_name, ot.major_category, ot.intervention_first, ot.intervention_second
     FROM upcc_case_offense uco
     JOIN offense o   ON o.offense_id = uco.offense_id
     JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
     WHERE uco.case_id = :c ORDER BY o.date_committed ASC",
    [':c' => $caseId, ':__enckey' => db_encryption_key()]
);

$priorResolvedCases = db_all(
    "SELECT uc.case_id, uc.status, uc.created_at, uc.updated_at, uc.decided_category, uc.punishment_details,
            GROUP_CONCAT(DISTINCT ot.code ORDER BY ot.code SEPARATOR ', ') AS offense_codes,
            GROUP_CONCAT(DISTINCT ot.name ORDER BY ot.code SEPARATOR ' | ') AS offense_names,
            SUM(CASE WHEN ot.level = 'MAJOR' THEN 1 ELSE 0 END) AS major_count,
            SUM(CASE WHEN ot.level = 'MINOR' THEN 1 ELSE 0 END) AS minor_count
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
            SUM(CASE WHEN ot.level = 'MAJOR' THEN 1 ELSE 0 END) AS major_count,
            SUM(CASE WHEN ot.level = 'MINOR' THEN 1 ELSE 0 END) AS minor_count
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

$caseLabel = 'UPCC-' . date('Y', strtotime((string)$case['created_at'])) . '-' . str_pad((string)$caseId, 3, '0', STR_PAD_LEFT);
$initials  = strtoupper(substr((string)$user['full_name'], 0, 1));
$parts     = explode(' ', (string)$user['full_name']);
if (count($parts) > 1) $initials .= strtoupper(substr((string)end($parts), 0, 1));

$hasMajorOffense = false;
foreach ($offenses as $off) {
    if (strtoupper((string)($off['level'] ?? '')) === 'MAJOR') { $hasMajorOffense = true; break; }
}
$cKindUpper = strtoupper((string)($case['case_kind'] ?? ''));
$isSection4 = !$hasMajorOffense && ($cKindUpper === 'SECTION4_MINOR_ESCALATION'
    || ($cKindUpper !== 'MAJOR_OFFENSE' && stripos((string)($case['case_summary'] ?? ''), 'Section 4 Minor Escalation') !== false));
$decisionHint = $isSection4 ? 'Section 4 escalation case'
    : ($hasMajorOffense ? 'Major offense — Category 1–5 review' : 'Minor offense review');

function fmt_dt(?string $v): string {
    return $v ? date('M j, Y g:i A', strtotime($v)) : '—';
}
function decision_badge(string $s): array {
    return match(strtoupper($s)) {
        'DISMISSED'                  => ['label' => '🚫 Dismissed',       'class' => 'badge-slate'],
        'CLOSED','RESOLVED'          => ['label' => 'Closed',              'class' => 'badge-green'],
        'AWAITING_ADMIN_FINALIZATION'=> ['label' => 'Awaiting Admin',      'class' => 'badge-purple'],
        'UNDER_INVESTIGATION'        => ['label' => 'Under Investigation', 'class' => 'badge-purple'],
        'UNDER_APPEAL'               => ['label' => 'Under Appeal',        'class' => 'badge-blue'],
        default                      => ['label' => 'Pending',             'class' => 'badge-amber'],
    };
}
$statusBadge = decision_badge($statusRaw);

$categoryDescriptions = [
    1 => 'Formal Reprimand & Active Semester Probation (0 Hours CS).',
    2 => 'Formative Community Service (150 to 250 Hours) with Counseling / Education / Evaluation.',
    3 => 'Non-Readmission / Suspension.',
    4 => 'Exclusion / Mandatory Dismissal (Dropped from University Rolls).',
    5 => 'Summary Expulsion & Police Referral (Permanent Disqualification from Higher Education).',
];

$postedDecidedCategory = isset($_POST['decided_category']) ? (int)$_POST['decided_category'] : 0;
$postedFinalDecision   = trim($_POST['final_decision'] ?? '');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($caseLabel) ?> — UPCC Tribunal Docket</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
/* ══════════════════════════════════════════════════════════════════════════
   UPCC TRIBUNAL — Formal institutional design system
   Deep navy + antique gold. Serif headings for legal gravitas.
   ══════════════════════════════════════════════════════════════════════════ */

:root{
    /* Palette */
    --ink-900:#060a14;
    --ink-800:#0a1220;
    --ink-700:#0e1a2d;
    --ink-600:#132340;
    --ink-500:#1a2c4a;

    --paper:#0d1728;
    --panel:#101c31;
    --panel-hi:#152540;
    --panel-line:#1e3054;

    --gold:#c9a961;
    --gold-soft:#a98b4a;
    --gold-bright:#e3c789;
    --gold-faint:rgba(201,169,97,.15);

    --rose:#c96b6b;
    --rose-soft:rgba(201,107,107,.14);
    --sage:#6e9e7e;
    --sage-soft:rgba(110,158,126,.14);
    --amber:#c9985b;
    --indigo:#7c8fc9;

    --text-hi:#f1ece0;
    --text:#d8d2c4;
    --text-dim:#93a0b5;
    --text-mute:#5d6a80;

    --line:rgba(201,169,97,.14);
    --line-2:rgba(255,255,255,.06);
    --line-hi:rgba(201,169,97,.35);

    --radius-lg:6px;
    --radius-md:4px;
    --radius-sm:3px;

    --f-serif:'Libre Baskerville', Georgia, 'Times New Roman', serif;
    --f-sans:'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
    --f-mono:'JetBrains Mono', 'Courier New', monospace;

    --shadow-sm:0 1px 2px rgba(0,0,0,.4);
    --shadow-md:0 4px 20px rgba(0,0,0,.35);
    --shadow-lg:0 12px 40px rgba(0,0,0,.5);
}

*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
html{-webkit-text-size-adjust:100%}
body{
    font-family:var(--f-sans);
    font-size:14px;
    color:var(--text);
    background:var(--ink-900);
    min-height:100vh;
    line-height:1.55;
    letter-spacing:0.005em;
    background-image:
        radial-gradient(ellipse 80% 50% at 50% -20%, rgba(201,169,97,.06), transparent),
        radial-gradient(ellipse 60% 40% at 100% 100%, rgba(124,143,201,.04), transparent);
    background-attachment:fixed;
}

/* ── App shell ─────────────────────────────────────────────────────────── */
.app-container{display:grid;grid-template-columns:280px 1fr;min-height:100vh}

/* ── Sidebar ───────────────────────────────────────────────────────────── */
.sidebar{
    background:linear-gradient(180deg,var(--ink-800) 0%,var(--ink-900) 100%);
    border-right:1px solid var(--line);
    padding:28px 22px;
    display:flex;flex-direction:column;
    position:relative;
}
.sidebar::after{
    content:'';position:absolute;top:0;bottom:0;right:-1px;width:1px;
    background:linear-gradient(180deg,transparent,var(--gold-faint) 30%,var(--gold-faint) 70%,transparent);
}
.brand{display:flex;align-items:center;gap:12px;padding-bottom:22px;margin-bottom:24px;border-bottom:1px solid var(--line)}
.brand-icon{width:42px;height:42px;border:1px solid var(--line);background:rgba(201,169,97,.05);
    border-radius:4px;display:grid;place-items:center;padding:6px;flex-shrink:0}
.brand-icon img{width:100%;height:auto}
.brand-text h1{font-family:var(--f-serif);font-size:16px;font-weight:700;letter-spacing:.5px;color:var(--text-hi);line-height:1.1}
.brand-text p{font-size:10px;color:var(--gold);text-transform:uppercase;letter-spacing:2px;margin-top:4px;font-weight:600}

.side-group{margin-top:22px}
.side-label{font-size:10px;letter-spacing:2px;color:var(--text-mute);text-transform:uppercase;
    margin-bottom:12px;font-weight:700;font-family:var(--f-sans);
    padding-bottom:6px;border-bottom:1px solid var(--line-2)}
.panel-chip{display:flex;align-items:center;gap:8px;border:1px solid var(--line-2);border-radius:4px;
    padding:7px 11px;margin:0 0 6px 0;background:rgba(255,255,255,.015);font-size:12px;color:var(--text);
    font-weight:500;line-height:1.35}
.panel-chip small{color:var(--text-mute);font-weight:600;text-transform:uppercase;font-size:9.5px;letter-spacing:.8px}
.panel-chip:hover{border-color:var(--line-hi);background:rgba(201,169,97,.04)}

/* ── Main ──────────────────────────────────────────────────────────────── */
.main-content{padding:32px 40px;max-width:100%;overflow-x:hidden}

/* ── Chamber banner ────────────────────────────────────────────────────── */
.chamber-banner{
    display:flex;align-items:center;justify-content:space-between;
    background:linear-gradient(180deg,var(--ink-800),var(--ink-700));
    border:1px solid var(--line);
    border-left:3px solid var(--gold);
    padding:16px 24px;margin-bottom:26px;
    box-shadow:var(--shadow-md);
    gap:18px;flex-wrap:wrap;
}
.chamber-seal{display:flex;align-items:center;gap:16px}
.chamber-seal-mark{
    width:52px;height:52px;border:1.5px solid var(--gold);border-radius:50%;
    display:grid;place-items:center;background:rgba(201,169,97,.06);
    font-size:22px;flex-shrink:0;position:relative;
}
.chamber-seal-mark::before{
    content:'';position:absolute;inset:3px;border:1px solid rgba(201,169,97,.3);border-radius:50%;
}
.chamber-title{
    font-family:var(--f-serif);font-size:15px;font-weight:700;color:var(--text-hi);
    letter-spacing:1px;text-transform:uppercase;line-height:1.2;
}
.chamber-sub{
    font-size:10px;color:var(--gold);text-transform:uppercase;letter-spacing:1.8px;
    font-weight:600;margin-top:5px;
}
.chamber-status{
    display:inline-flex;align-items:center;gap:9px;
    background:rgba(0,0,0,.35);border:1px solid var(--line);border-radius:3px;
    padding:8px 15px;font-size:11px;font-weight:700;letter-spacing:1px;text-transform:uppercase;
    font-family:var(--f-sans);
}
.chamber-status .dot{width:7px;height:7px;border-radius:50%;display:inline-block}
.chamber-status .dot.live{background:var(--sage);box-shadow:0 0 0 3px rgba(110,158,126,.2)}
.chamber-status .dot.paused{background:var(--rose);box-shadow:0 0 0 3px rgba(201,107,107,.2)}
.chamber-status .dot.closed{background:var(--text-mute)}

/* ── Docket card (hero) ────────────────────────────────────────────────── */
.hero{
    background:linear-gradient(180deg,var(--panel),var(--panel-hi));
    border:1px solid var(--line);
    border-top:3px solid var(--gold);
    padding:30px 32px;
    display:flex;justify-content:space-between;gap:32px;align-items:flex-start;
    margin-bottom:26px;
    box-shadow:var(--shadow-md);
    position:relative;
}
.hero::before{
    content:'';position:absolute;top:0;left:0;right:0;height:3px;
    background:linear-gradient(90deg,var(--gold) 0%,var(--gold-soft) 30%,transparent 100%);
}
.crumb{font-size:10.5px;color:var(--gold);font-weight:700;text-transform:uppercase;letter-spacing:2.2px;
    margin-bottom:10px;display:flex;align-items:center;gap:8px}
.title{
    font-family:var(--f-serif);font-size:30px;font-weight:700;color:var(--text-hi);
    letter-spacing:-.3px;line-height:1.15;
}
.subtitle{margin-top:12px;color:var(--text);font-size:14px;max-width:760px;line-height:1.65}
.hero-meta{display:flex;flex-wrap:wrap;gap:8px;margin-top:22px}

.pill{
    display:inline-flex;align-items:center;gap:6px;
    padding:6px 12px;border-radius:3px;
    font-size:11px;font-weight:700;letter-spacing:.7px;
    border:1px solid var(--line-2);background:rgba(255,255,255,.02);
    text-transform:uppercase;
}
.pill.green{color:#9dc5a8;border-color:rgba(110,158,126,.35);background:var(--sage-soft)}
.pill.amber{color:#dfb87c;border-color:rgba(201,152,91,.35);background:rgba(201,152,91,.1)}
.pill.blue{color:#a5b6e0;border-color:rgba(124,143,201,.35);background:rgba(124,143,201,.1)}
.pill.purple{color:#b4aee0;border-color:rgba(150,140,200,.35);background:rgba(150,140,200,.1)}
.pill.gold{color:var(--gold-bright);border-color:var(--line-hi);background:var(--gold-faint)}

.info-box{
    border:1px solid var(--line);
    background:rgba(0,0,0,.25);
    padding:16px 18px;
    min-width:230px;
    border-radius:var(--radius-md);
}
.info-label{
    font-size:10px;color:var(--text-mute);text-transform:uppercase;letter-spacing:1.8px;
    margin-bottom:7px;font-weight:700;
}
.info-value{font-family:var(--f-serif);font-size:16px;font-weight:700;color:var(--text-hi);letter-spacing:.2px;line-height:1.3}

.stack{display:grid;gap:20px}

/* ── Layout ────────────────────────────────────────────────────────────── */
.layout{display:grid;grid-template-columns:minmax(0,1fr) 400px;gap:24px}
@media(max-width:1160px){.layout{grid-template-columns:1fr}}

/* ── Panels ────────────────────────────────────────────────────────────── */
.glass-panel{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:var(--radius-md);
    overflow:hidden;
    display:flex;flex-direction:column;
    box-shadow:var(--shadow-sm);
}
.panel-header{
    padding:18px 24px;
    border-bottom:1px solid var(--line);
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    background:rgba(0,0,0,.2);
    position:relative;
}
.panel-header::before{
    content:'';position:absolute;left:24px;right:24px;bottom:-1px;height:1px;
    background:linear-gradient(90deg,var(--gold-faint),transparent);
}
.panel-title{
    font-family:var(--f-serif);font-size:15px;font-weight:700;color:var(--text-hi);
    display:flex;align-items:center;gap:10px;letter-spacing:.3px;
}
.panel-body{padding:24px}

/* ── Badges ────────────────────────────────────────────────────────────── */
.badge{
    display:inline-flex;align-items:center;gap:5px;
    padding:4px 10px;border-radius:3px;
    font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;
    border:1px solid transparent;
}
.badge-amber{background:rgba(201,152,91,.12);color:#dfb87c;border-color:rgba(201,152,91,.35)}
.badge-purple{background:rgba(150,140,200,.12);color:#b4aee0;border-color:rgba(150,140,200,.35)}
.badge-green{background:var(--sage-soft);color:#9dc5a8;border-color:rgba(110,158,126,.35)}
.badge-blue{background:rgba(124,143,201,.12);color:#a5b6e0;border-color:rgba(124,143,201,.35)}
.badge-slate{background:rgba(147,160,181,.1);color:#b0bac9;border-color:rgba(147,160,181,.3)}
.badge-emerald{background:var(--sage-soft);color:#9dc5a8;border-color:rgba(110,158,126,.4)}

/* ── Offense list ──────────────────────────────────────────────────────── */
.offense-list{display:grid;gap:12px}
.offense-item{
    border:1px solid var(--line-2);
    background:rgba(0,0,0,.2);
    border-radius:var(--radius-md);
    overflow:hidden;
    transition:border-color .2s,background .2s;
}
.offense-item:hover{border-color:var(--line-hi);background:rgba(201,169,97,.03)}
.offense-item[open]{border-color:var(--line-hi);background:rgba(201,169,97,.02)}
.offense-item summary{
    list-style:none;cursor:pointer;padding:16px 20px;
    display:flex;justify-content:space-between;gap:14px;align-items:center;
}
.offense-item summary::-webkit-details-marker{display:none}
.offense-main{display:flex;flex-direction:column;gap:4px;min-width:0}
.offense-code{font-size:11px;color:var(--gold);font-weight:700;letter-spacing:1.5px;font-family:var(--f-mono)}
.offense-name{font-family:var(--f-serif);font-size:15px;font-weight:700;color:var(--text-hi)}
.offense-meta{color:var(--text-mute);font-size:11.5px;font-weight:500;letter-spacing:.3px}
.offense-body{padding:0 20px 22px;border-top:1px solid var(--line-2);padding-top:18px;display:grid;gap:14px}
.offense-row{display:grid;grid-template-columns:150px 1fr;gap:16px;align-items:start}
.offense-row .label{
    color:var(--text-mute);font-size:10.5px;text-transform:uppercase;letter-spacing:1.5px;
    font-weight:700;padding-top:2px;
}
.offense-row .value{color:var(--text);font-size:13.5px;line-height:1.65}

/* ── Form fields ───────────────────────────────────────────────────────── */
.field{display:grid;gap:8px}
.field label{
    font-size:10.5px;color:var(--text-mute);text-transform:uppercase;letter-spacing:1.5px;
    font-weight:700;
}
.fld-input{
    width:100%;
    border:1px solid var(--line-2);
    background:rgba(0,0,0,.35);
    color:var(--text-hi);
    border-radius:var(--radius-sm);
    padding:11px 14px;
    font-family:var(--f-sans);font-size:13.5px;
    transition:border-color .2s,background .2s;
    line-height:1.5;
}
.fld-input:focus{outline:none;border-color:var(--gold);background:rgba(0,0,0,.5)}
.fld-input::placeholder{color:var(--text-mute)}
.fld-input option,select option{background-color:#0e1a2d !important;color:#f1ece0 !important;padding:10px}

textarea.fld-input{min-height:90px;resize:vertical;font-family:var(--f-sans)}

/* ── Buttons ───────────────────────────────────────────────────────────── */
.btn{
    display:inline-flex;align-items:center;justify-content:center;gap:8px;
    border:none;border-radius:var(--radius-sm);cursor:pointer;
    padding:11px 20px;
    font-family:var(--f-sans);font-size:12.5px;font-weight:700;
    text-decoration:none;transition:all .18s;
    letter-spacing:.5px;text-transform:uppercase;
    line-height:1;
}
.btn-primary{
    background:linear-gradient(180deg,var(--gold),var(--gold-soft));
    color:#0a1220;
    box-shadow:0 1px 0 rgba(255,255,255,.15) inset,0 2px 8px rgba(201,169,97,.25);
    border:1px solid var(--gold-soft);
}
.btn-primary:hover{background:linear-gradient(180deg,var(--gold-bright),var(--gold));box-shadow:0 2px 14px rgba(201,169,97,.4)}
.btn-secondary{
    background:rgba(255,255,255,.03);color:var(--text);
    border:1px solid var(--line-2);
}
.btn-secondary:hover{background:rgba(255,255,255,.06);border-color:var(--line-hi);color:var(--text-hi)}
.btn-danger{
    background:rgba(201,107,107,.12);color:#e0a0a0;
    border:1px solid rgba(201,107,107,.35);
}
.btn-danger:hover{background:rgba(201,107,107,.22)}
.btn-success{background:var(--sage-soft);color:#9dc5a8;border:1px solid rgba(110,158,126,.35)}
.btn-success:hover{background:rgba(110,158,126,.22)}
.btn-outline{background:transparent;color:var(--text);border:1px solid var(--line-2)}
.btn-outline:hover{border-color:var(--line-hi);color:var(--text-hi)}

/* ── Chat ──────────────────────────────────────────────────────────────── */
.chat-item{
    border:1px solid var(--line-2);background:rgba(0,0,0,.18);
    border-radius:var(--radius-md);padding:13px 16px;margin-bottom:10px;
}
.chat-head{display:flex;justify-content:space-between;gap:10px;margin-bottom:7px;align-items:baseline}
.chat-name{font-family:var(--f-serif);font-weight:700;font-size:13.5px;color:var(--text-hi)}
.chat-role{color:var(--text-mute);font-size:10px;text-transform:uppercase;letter-spacing:1.2px;font-weight:700}
.chat-time{color:var(--text-mute);font-size:11px;white-space:nowrap;font-family:var(--f-mono)}
.chat-msg{color:var(--text);line-height:1.65;font-size:13.5px;white-space:pre-wrap}

/* ── Inline Chat Input & Send Button ───────────────────────────────────── */
.chat-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
    width: 100%;
}
.chat-textarea {
    width: 100%;
    min-height: 52px;
    max-height: 160px;
    padding: 14px 50px 14px 16px;
    background: rgba(6, 10, 20, 0.7);
    border: 1px solid var(--line-hi);
    border-radius: var(--radius-md);
    color: var(--text-hi);
    font-family: var(--f-sans);
    font-size: 13.5px;
    line-height: 1.5;
    resize: vertical;
    transition: border-color 0.2s, box-shadow 0.2s;
}
.chat-textarea:focus {
    outline: none;
    border-color: var(--gold-bright);
    box-shadow: 0 0 0 3px rgba(201, 169, 97, 0.15);
}
.chat-send-btn {
    position: absolute;
    right: 8px;
    bottom: 8px;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    background: linear-gradient(135deg, var(--gold-bright), var(--gold));
    color: #060a14;
    border: none;
    display: flex;
    align-items: center;
    justify-content: center;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(201, 169, 97, 0.35);
    opacity: 0;
    transform: scale(0.8);
    pointer-events: none;
    transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
    z-index: 2;
}
.chat-input-wrapper.has-text .chat-send-btn {
    opacity: 1;
    transform: scale(1);
    pointer-events: auto;
}
.chat-send-btn:hover {
    background: #e5c578;
    transform: scale(1.08);
    box-shadow: 0 4px 12px rgba(201, 169, 97, 0.5);
}
.chat-send-btn:active {
    transform: scale(0.95);
}
.chat-send-btn:disabled {
    opacity: 0 !important;
    pointer-events: none !important;
}

/* ── Lock box ──────────────────────────────────────────────────────────── */
.lock{
    border:1px dashed rgba(201,152,91,.5);
    background:rgba(201,152,91,.06);
    border-radius:var(--radius-md);
    padding:26px;color:#dfb87c;text-align:center;
}
.lock strong{color:var(--gold-bright);font-family:var(--f-serif);font-size:16px}
.empty{color:var(--text-mute);font-size:13px;padding:14px 0;font-style:italic}

hr{border:none;border-top:1px solid var(--line);margin:18px 0}

/* ── Cooldown alert ────────────────────────────────────────────────────── */
.cooldown-alert{
    background:var(--rose-soft);border:1px solid rgba(201,107,107,.35);
    border-radius:var(--radius-md);padding:16px;text-align:center;
    font-size:13px;color:#e0a0a0;
}
.cooldown-alert strong{
    font-size:24px;display:block;margin-top:6px;font-family:var(--f-mono);
    font-variant-numeric:tabular-nums;color:#e0a0a0;letter-spacing:1px;
}

/* ── Consensus banner ──────────────────────────────────────────────────── */
.consensus-banner{
    background:var(--sage-soft);border:1px solid rgba(110,158,126,.4);
    border-radius:var(--radius-md);padding:22px;text-align:center;margin-bottom:18px;
}
.consensus-banner-title{
    font-family:var(--f-serif);font-size:17px;font-weight:700;color:#9dc5a8;
    margin-bottom:6px;letter-spacing:1px;text-transform:uppercase;
}

/* ── Presence overlay ──────────────────────────────────────────────────── */
.presence-overlay{
    position:fixed;inset:0;z-index:3000;display:none;align-items:center;justify-content:center;
    background:rgba(6,10,20,.94);backdrop-filter:blur(14px);padding:24px;text-align:center;
}
.presence-overlay.open{display:flex}
.presence-card{
    background:var(--panel);border:1px solid var(--line-hi);border-radius:var(--radius-md);
    padding:44px;max-width:440px;width:100%;box-shadow:var(--shadow-lg);
}

/* ══════════════════════════════════════════════════════════════════════════
   Voting modal — formal tribunal voting chamber
══════════════════════════════════════════════════════════════════════════ */
.voting-modal{
    position:fixed;inset:0;z-index:9000;display:none;align-items:center;justify-content:center;
    background:rgba(6,10,20,.95);backdrop-filter:blur(10px);padding:20px;
}
.voting-modal.open{display:flex}
.vmc{
    background:linear-gradient(180deg,var(--panel),var(--ink-700));
    border:1px solid var(--gold);border-radius:var(--radius-md);
    padding:34px 32px;width:100%;max-width:580px;max-height:92vh;overflow-y:auto;
    box-shadow:var(--shadow-lg),0 0 0 1px rgba(201,169,97,.1);
    position:relative;
}
.vmc::before{
    content:'';position:absolute;top:0;left:24px;right:24px;height:3px;
    background:linear-gradient(90deg,transparent,var(--gold),transparent);
}
.vmc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}
.vmc-title{font-family:var(--f-serif);font-size:22px;font-weight:700;color:var(--text-hi);letter-spacing:.3px}
.vmc-live-badge{
    background:var(--rose-soft);color:#e0a0a0;border:1px solid rgba(201,107,107,.4);
    border-radius:3px;padding:5px 11px;font-size:10px;font-weight:700;
    text-transform:uppercase;letter-spacing:1.5px;
}
.vmc-sub{color:var(--text);font-size:13.5px;margin-bottom:22px;line-height:1.6}

/* Timer */
.timer-wrap{margin:0 0 22px}
.timer-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:8px}
.timer-label{font-size:10.5px;color:var(--text-mute);text-transform:uppercase;letter-spacing:1.5px;font-weight:700}
.timer-num{
    font-family:var(--f-mono);font-size:26px;font-weight:600;
    font-variant-numeric:tabular-nums;color:var(--text-hi);letter-spacing:1px;
}
.timer-num.urgent{color:#e0a0a0}
.timer-bar-wrap{height:4px;background:rgba(255,255,255,.06);border-radius:2px;overflow:hidden}
.timer-bar-fill{height:100%;transition:width 1s linear,background .5s}

/* Suggestion box */
.sug-box{
    background:rgba(0,0,0,.35);border:1px solid var(--line-hi);
    border-radius:var(--radius-md);padding:20px;margin:0 0 20px;
}
.sug-cat{font-family:var(--f-serif);font-size:19px;font-weight:700;color:var(--gold-bright);margin-bottom:6px}
.sug-desc{font-size:13px;color:var(--text);line-height:1.6;margin-bottom:10px}
.sug-note{
    background:rgba(255,255,255,.03);border-left:3px solid var(--gold);
    padding:10px 14px;border-radius:0 4px 4px 0;font-size:12.5px;color:var(--text);font-style:italic;
    margin-top:10px;
}
.sug-tag{
    display:inline-block;background:rgba(201,169,97,.12);color:var(--gold-bright);
    border:1px solid var(--line-hi);border-radius:3px;
    padding:3px 9px;font-size:11px;font-weight:700;margin:3px 3px 0 0;letter-spacing:.3px;
}

/* Tally */
.tally-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:16px}
.tally-cell{
    background:rgba(0,0,0,.25);border-radius:var(--radius-md);padding:14px;text-align:center;
    border:1px solid var(--line-2);
}
.tally-cell label{
    font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--text-mute);
    display:block;margin-bottom:5px;font-weight:700;
}
.tally-cell span{font-family:var(--f-mono);font-size:24px;font-weight:600;letter-spacing:.5px}
.tc-agree span{color:#9dc5a8}
.tc-disagree span{color:#e0a0a0}
.tc-pending span{color:#dfb87c}
.tally-note{text-align:center;font-size:11.5px;color:var(--text-mute);margin-bottom:16px;letter-spacing:.3px}

/* Voter list */
.voter-list{display:grid;gap:8px;margin-bottom:20px}
.voter-item{
    display:flex;align-items:center;justify-content:space-between;gap:12px;
    padding:12px 16px;border:1px solid var(--line-2);border-radius:var(--radius-md);
    background:rgba(0,0,0,.2);transition:border-color .3s,background .3s;
}
.voter-item.v-agree{border-color:rgba(110,158,126,.4);background:var(--sage-soft)}
.voter-item.v-disagree{border-color:rgba(201,107,107,.4);background:var(--rose-soft)}
.voter-name{font-weight:700;font-size:13px;color:var(--text-hi)}
.voter-meta{font-size:10.5px;color:var(--text-mute);margin-top:2px;text-transform:uppercase;letter-spacing:1px}
.v-pill{
    padding:4px 10px;border-radius:3px;font-size:10px;font-weight:700;
    text-transform:uppercase;letter-spacing:1.2px;border:1px solid transparent;
}
.v-pill.agree{background:var(--sage-soft);color:#9dc5a8;border-color:rgba(110,158,126,.4)}
.v-pill.disagree{background:var(--rose-soft);color:#e0a0a0;border-color:rgba(201,107,107,.4)}
.v-pill.pending{background:rgba(201,152,91,.1);color:#dfb87c;border-color:rgba(201,152,91,.35)}
.v-pill.suggester{background:rgba(201,169,97,.15);color:var(--gold-bright);border-color:var(--line-hi)}

/* Vote buttons */
.vote-btn-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
.btn-agree{
    background:linear-gradient(180deg,#5c8a6c,#4c7760);color:#fff;border:none;
    border-radius:var(--radius-md);padding:16px;
    font-family:var(--f-sans);font-size:14px;font-weight:800;cursor:pointer;
    transition:all .18s;width:100%;letter-spacing:1.5px;text-transform:uppercase;
    box-shadow:0 2px 0 rgba(0,0,0,.3) inset,0 3px 12px rgba(76,119,96,.35);
}
.btn-agree:hover{background:linear-gradient(180deg,#6ba075,#5c8a6c);box-shadow:0 4px 18px rgba(76,119,96,.5)}
.btn-disagree{
    background:linear-gradient(180deg,#a85858,#8d4747);color:#fff;border:none;
    border-radius:var(--radius-md);padding:16px;
    font-family:var(--f-sans);font-size:14px;font-weight:800;cursor:pointer;
    transition:all .18s;width:100%;letter-spacing:1.5px;text-transform:uppercase;
    box-shadow:0 2px 0 rgba(0,0,0,.3) inset,0 3px 12px rgba(141,71,71,.35);
}
.btn-disagree:hover{background:linear-gradient(180deg,#bc6666,#a85858);box-shadow:0 4px 18px rgba(141,71,71,.5)}
.voted-conf{
    text-align:center;background:var(--sage-soft);
    border:1px solid rgba(110,158,126,.4);border-radius:var(--radius-md);
    padding:16px;font-size:13.5px;font-weight:600;color:#9dc5a8;margin-bottom:14px;
}
.voted-conf small{display:block;font-size:11px;font-weight:400;color:var(--text-mute);margin-top:5px;letter-spacing:.3px}

.result-flash{
    border-radius:var(--radius-md);padding:15px;text-align:center;
    font-weight:600;font-size:13.5px;margin-bottom:14px;display:none;
}
.result-flash.consensus{background:var(--sage-soft);border:1px solid rgba(110,158,126,.4);color:#9dc5a8}
.result-flash.disagreed{background:var(--rose-soft);border:1px solid rgba(201,107,107,.4);color:#e0a0a0}

/* Vote tally on panel */
.vote-tally{
    display:grid;grid-template-columns:repeat(3,1fr);gap:10px;
    background:rgba(0,0,0,.25);border-radius:var(--radius-md);padding:16px;
    margin:16px 0;text-align:center;border:1px solid var(--line-2);
}
.tally-item label{
    font-size:10px;text-transform:uppercase;letter-spacing:1.5px;color:var(--text-mute);
    display:block;margin-bottom:5px;font-weight:700;
}
.tally-item span{font-family:var(--f-mono);font-size:22px;font-weight:600}
.tally-agree span{color:#9dc5a8}
.tally-disagree span{color:#e0a0a0}
.tally-pending span{color:#dfb87c}
.suggestion-box{
    background:rgba(0,0,0,.3);border:1px solid var(--line-hi);
    border-radius:var(--radius-md);padding:18px;margin:14px 0;
}
.suggestion-category{
    font-family:var(--f-serif);font-size:17px;font-weight:700;
    color:var(--gold-bright);margin-bottom:8px;
}
.vote-btns{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:16px}
.vote-btns form{display:contents}
.btn-vote-agree{
    background:linear-gradient(180deg,#5c8a6c,#4c7760);color:#fff;border:none;
    border-radius:var(--radius-md);padding:14px;font-family:var(--f-sans);
    font-size:13px;font-weight:800;cursor:pointer;transition:all .18s;
    letter-spacing:1.2px;text-transform:uppercase;
}
.btn-vote-agree:hover{background:linear-gradient(180deg,#6ba075,#5c8a6c)}
.btn-vote-disagree{
    background:linear-gradient(180deg,#a85858,#8d4747);color:#fff;border:none;
    border-radius:var(--radius-md);padding:14px;font-family:var(--f-sans);
    font-size:13px;font-weight:800;cursor:pointer;transition:all .18s;
    letter-spacing:1.2px;text-transform:uppercase;
}
.btn-vote-disagree:hover{background:linear-gradient(180deg,#bc6666,#a85858)}
.voted-confirmation{
    text-align:center;background:var(--sage-soft);border:1px solid rgba(110,158,126,.4);
    border-radius:var(--radius-md);padding:15px;font-size:13.5px;font-weight:600;color:#9dc5a8;
}

.decision-box{
    border:1px solid var(--line-hi);background:var(--gold-faint);
    border-radius:var(--radius-md);padding:18px;display:grid;gap:10px;
}

/* ── Tab navigation ────────────────────────────────────────────────────── */
.case-breakdown-tabs{
    display:flex;gap:6px;border-bottom:1px solid var(--line);
    padding-bottom:12px;margin-bottom:20px;flex-wrap:wrap;
}
.btn-tab{
    border-radius:3px;font-weight:700;font-size:11.5px;padding:9px 15px;
    background:rgba(255,255,255,.03);color:var(--text-dim);
    border:1px solid var(--line-2);cursor:pointer;transition:all .18s;
    font-family:var(--f-sans);letter-spacing:.5px;text-transform:uppercase;
}
.btn-tab:hover{border-color:var(--line-hi);color:var(--text-hi)}

/* ── Loading spinner ───────────────────────────────────────────────────── */
.spinner-loader{
    width:44px;height:44px;border:3px solid rgba(201,169,97,.15);
    border-top:3px solid var(--gold);border-radius:50%;margin:0 auto 20px;
    animation:spin-loader .8s linear infinite;
}
@keyframes spin-loader{0%{transform:rotate(0deg)}100%{transform:rotate(360deg)}}

/* Privacy blur */
.case-details-blur{
    filter:blur(6px);user-select:none;pointer-events:none;
    border-radius:4px;padding:2px 4px;margin:-2px -4px;
}

/* ── Modal shells (generic) ────────────────────────────────────────────── */
.modal-shell{
    position:fixed;inset:0;z-index:9100;display:none;align-items:center;justify-content:center;
    background:rgba(6,10,20,.94);backdrop-filter:blur(10px);padding:24px;
}
.modal-shell.open{display:flex}
.modal-card{
    background:var(--panel);border:1px solid var(--line-hi);border-radius:var(--radius-md);
    padding:34px;max-width:440px;width:100%;text-align:center;box-shadow:var(--shadow-lg);
    position:relative;
}

/* ── AI Drawer — keep but tone down ────────────────────────────────────── */
#aiFloatingBubble{
    position:fixed;bottom:24px;right:24px;z-index:100005;cursor:pointer;
    display:flex;align-items:center;gap:12px;
    background:linear-gradient(180deg,var(--panel),var(--ink-700));
    color:var(--text-hi);padding:10px 20px 10px 14px;border-radius:4px;
    border:1px solid var(--gold);
    font-family:var(--f-sans);font-weight:700;font-size:13px;letter-spacing:.5px;
    box-shadow:var(--shadow-md),0 0 0 1px rgba(201,169,97,.15);
    transition:all .3s;
}
#aiFloatingBubble:hover{transform:translateY(-3px);box-shadow:var(--shadow-lg)}

.ai-bot-avatar-wrapper{position:relative;width:40px;height:40px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}
.ai-bot-svg{width:100%;height:100%;overflow:visible}
.ai-bot-svg.idle{animation:botFloatIdle 4s ease-in-out infinite}
@keyframes botFloatIdle{0%,100%{transform:translateY(0)}50%{transform:translateY(-3px)}}
.ai-bot-svg.thinking{animation:botThink 1.4s ease-in-out infinite alternate}
@keyframes botThink{0%{transform:translateY(-1px) rotate(-3deg)}100%{transform:translateY(-4px) rotate(3deg)}}
.ai-bot-svg.speaking,.ai-bot-svg.happy{animation:botSpeak .8s ease-in-out infinite alternate}
@keyframes botSpeak{0%{transform:translateY(-2px)}100%{transform:translateY(-5px)}}

.bot-aura{fill:rgba(201,169,97,.12)}
.bot-platform{fill:rgba(201,169,97,.18);filter:blur(2px)}
.bot-head-shell{fill:url(#aiHeadGradUPCC);stroke:rgba(201,169,97,.5);stroke-width:2}
.bot-visor{fill:url(#aiVisorGradUPCC);stroke:rgba(201,169,97,.4);stroke-width:1.5}
.bot-ear{fill:#a98b4a;stroke:var(--gold);stroke-width:1.5}
.bot-headband{fill:none;stroke:var(--gold);stroke-width:2.5;stroke-linecap:round}
.bot-antenna-stem{stroke:var(--gold);stroke-width:2}
.bot-antenna-bulb{fill:var(--gold);animation:antennaFlash 2s infinite alternate ease-in-out}
@keyframes antennaFlash{0%{fill:var(--gold-soft);opacity:.5}100%{fill:var(--gold-bright);opacity:1}}
.bot-brow{fill:none;stroke:var(--gold);stroke-width:1.8;stroke-linecap:round;opacity:.5}
.bot-eye{fill:var(--gold-bright)}
.ai-bot-svg.speaking .bot-eye,.ai-bot-svg.happy .bot-eye{fill:#9dc5a8}
.bot-mouth-bar{fill:var(--gold);opacity:.6}
.ai-bot-svg.speaking .bot-mouth-bar,.ai-bot-svg.happy .bot-mouth-bar{fill:#9dc5a8;opacity:1;animation:mouthBounce .4s infinite alternate ease-in-out}
.ai-bot-svg.speaking .bar-1{animation-delay:0s}
.ai-bot-svg.speaking .bar-2{animation-delay:.12s}
.ai-bot-svg.speaking .bar-3{animation-delay:.24s}
@keyframes mouthBounce{0%{height:3px;y:77px}100%{height:9px;y:71px}}

.ai-dots-loader{display:inline-flex;align-items:center;gap:4px;margin-right:8px;vertical-align:middle}
.ai-dots-loader span{width:6px;height:6px;border-radius:50%;background:var(--gold);animation:aiDotPulse 1.4s infinite ease-in-out both}
.ai-dots-loader span:nth-child(1){animation-delay:-0.32s}
.ai-dots-loader span:nth-child(2){animation-delay:-0.16s}
.ai-dots-loader span:nth-child(3){animation-delay:0s}
@keyframes aiDotPulse{0%,80%,100%{transform:scale(.5);opacity:.35}40%{transform:scale(1.1);opacity:1}}

.ai-shimmer-text{
    background:linear-gradient(90deg,#93a0b5 0%,var(--gold-bright) 50%,#93a0b5 100%);
    background-size:200% 100%;-webkit-background-clip:text;-webkit-text-fill-color:transparent;
    animation:aiShimmer 2s infinite linear;font-size:13px;font-weight:600;
}
@keyframes aiShimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

.ai-avatar-container{
    width:34px;height:34px;border-radius:50%;background:var(--ink-700);
    border:1px solid var(--line-hi);display:flex;align-items:center;justify-content:center;
    flex-shrink:0;overflow:hidden;
}
.ai-avatar-img{width:100%;height:100%;object-fit:cover;border-radius:50%}

#aiChatDrawer *::-webkit-scrollbar{width:6px;height:6px}
#aiChatDrawer *::-webkit-scrollbar-track{background:rgba(0,0,0,.4);border-radius:3px}
#aiChatDrawer *::-webkit-scrollbar-thumb{background:var(--gold-soft);border-radius:3px}
#aiChatDrawer *::-webkit-scrollbar-thumb:hover{background:var(--gold)}

/* ── Responsive ────────────────────────────────────────────────────────── */
@media(max-width:1100px){
    .app-container{grid-template-columns:1fr}
    .sidebar{padding:18px 22px;flex-direction:row;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;border-right:none;border-bottom:1px solid var(--line)}
    .sidebar::after{display:none}
    .brand{margin-bottom:0;padding-bottom:0;border-bottom:none}
    .side-group{margin-top:0}
    .side-group .side-label{display:none}
    .side-group .panel-chip{display:inline-flex;margin:0 4px 0 0}
    .main-content{padding:24px 20px}
    .hero{flex-direction:column;padding:24px}
    .title{font-size:24px}
}
@media(max-width:768px){
    .vote-btn-row,.vote-btns{grid-template-columns:1fr}
    .tally-row,.vote-tally{grid-template-columns:1fr}
    .offense-row{grid-template-columns:1fr;gap:4px}
    .offense-row .label{padding-top:8px}
    .hero-meta .pill{font-size:10px}
    .vmc{padding:24px 20px}
    .chamber-banner{padding:14px 16px}
    .chamber-title{font-size:13px}
}
</style>
</head>
<body>
<div id="globalLoadingOverlay" style="position:fixed;inset:0;z-index:9999;display:none;align-items:center;justify-content:center;background:rgba(6,10,20,.94);backdrop-filter:blur(8px)">
    <div style="text-align:center;color:#fff;padding:24px">
        <div class="spinner-loader"></div>
        <div id="loadingOverlayText" style="font-family:var(--f-serif);font-size:18px;font-weight:700;color:#f1ece0;margin-bottom:6px;letter-spacing:.3px">Submitting suggestion…</div>
        <div style="font-size:12px;color:#5d6a80;letter-spacing:.5px">Please wait — do not close or refresh this page.</div>
    </div>
</div>
<div class="app-container">

<!-- ── SIDEBAR ──────────────────────────────────────────────────────────── -->
<aside class="sidebar">
    <div class="brand">
        <div class="brand-icon"><img src="../assets/logo.png" alt="IdentiTrack"></div>
        <div class="brand-text"><h1>UPCC Panel</h1><p>Tribunal Workspace</p></div>
    </div>
    <div class="side-group">
        <div class="side-label">Current Docket</div>
        <div class="panel-chip"><small>ID</small> <?= htmlspecialchars($caseLabel) ?></div>
        <div class="panel-chip"><small>Status</small> <?= htmlspecialchars($statusBadge['label']) ?></div>
        <div class="panel-chip"><small>Mode</small> <?= htmlspecialchars($decisionHint) ?></div>
    </div>
    <div class="side-group">
        <div class="side-label">Assigned Panel</div>
        <?php if (!empty($panelMembers)): foreach ($panelMembers as $m): ?>
            <div class="panel-chip">
                <span>👤</span> <?= htmlspecialchars($m['full_name']) ?>
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
    <div class="side-group" style="margin-top:auto; display:flex; flex-direction:column; gap:8px;">
        <a id="backToDashboardBtn" class="btn btn-secondary" href="upccdashboard.php" style="width:100%;">← Back to Dashboard</a>
    </div>
</aside>

<!-- ── MAIN ──────────────────────────────────────────────────────────────── -->
<main class="main-content">

    <!-- CHAMBER BANNER -->
    <div class="chamber-banner">
        <div class="chamber-seal">
            <div class="chamber-seal-mark">⚖️</div>
            <div>
                <div class="chamber-title">National University · Discipline Board</div>
                <div class="chamber-sub">Official UPCC Hearing &amp; Tribunal Chamber · Confidential Proceedings</div>
            </div>
        </div>
        <div class="chamber-status">
            <?php if ($isHearingOpen && $isHearingPaused): ?>
                <span class="dot paused"></span> <span style="color:#e0a0a0">Hearing Suspended</span>
            <?php elseif ($isHearingOpen): ?>
                <span class="dot live"></span> <span style="color:#9dc5a8">Tribunal in Session</span>
            <?php else: ?>
                <span class="dot closed"></span> <span style="color:#b0bac9">Proceedings Concluded</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- HERO / DOCKET CARD -->
    <section class="hero">
        <div>
            <div class="crumb">
                <span>Official Docket File</span>
                <span style="color:var(--text-mute)">·</span>
                <span style="color:var(--gold)">Confidential</span>
            </div>
            <div class="title"><?= htmlspecialchars($caseLabel) ?></div>
            <div class="subtitle">
                Disciplinary proceedings active for Respondent <strong style="color:var(--text-hi)"><?= htmlspecialchars($case['student_name']) ?></strong>. Review charges, evidentiary record, and record formal panel decision.
            </div>
            <div class="hero-meta">
                <span class="pill amber">⚖️ <?= htmlspecialchars($decisionHint) ?></span>
                <span class="pill purple">🏢 <?= htmlspecialchars($case['assigned_dept_name'] ?? 'No dept') ?></span>
                <span class="pill blue">🎓 <?= htmlspecialchars($case['year_level']) ?> Yr · <?= htmlspecialchars($case['section'] ?? 'N/A') ?></span>
                <span class="pill green">📌 <?= htmlspecialchars($statusBadge['label']) ?></span>
                <?php if ($isHearingOpen && $isHearingPaused): ?>
                  <span class="pill" data-pause-pill="1" style="background:var(--rose-soft);color:#e0a0a0;border-color:rgba(201,107,107,.4)">⏸ Hearing Paused</span>
                <?php elseif ($isHearingOpen): ?>
                  <span class="pill" data-pause-pill="1" style="background:var(--sage-soft);color:#9dc5a8;border-color:rgba(110,158,126,.4)"><span style="width:6px;height:6px;background:#9dc5a8;border-radius:50%;display:inline-block"></span> Hearing Live</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="stack" style="min-width:260px">
            <div class="info-box">
                <div class="info-label">Respondent Student</div>
                <div class="info-value" style="font-size:17px"><?= htmlspecialchars($case['student_name']) ?></div>
                <div style="margin-top:6px;font-size:12.5px;color:var(--text-dim);font-weight:500;font-family:var(--f-mono)"><?= htmlspecialchars($case['student_id']) ?></div>
                <div style="margin-top:2px;font-size:12.5px;color:var(--text-dim)"><?= htmlspecialchars($case['program']) ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Docket Filed</div>
                <div class="info-value" style="font-size:14px;font-family:var(--f-sans);font-weight:600"><?= fmt_dt((string)$case['created_at']) ?></div>
            </div>
        </div>
    </section>

    <div class="layout">
        <!-- ── LEFT ─────────────────────────────────────────────────── -->
        <section class="stack">

            <!-- OFFENSE LIST -->
            <div class="glass-panel">
                <div class="panel-header">
                    <div>
                        <div class="panel-title">📋 Offense Breakdown</div>
                        <div style="color:var(--text-mute);font-size:11.5px;margin-top:4px;letter-spacing:.3px">Every offense linked to this case</div>
                    </div>
                    <span class="badge <?= htmlspecialchars($statusBadge['class']) ?>"><?= htmlspecialchars($statusBadge['label']) ?></span>
                </div>
                <div class="panel-body">
                    <?php if (!$confidentialityAccepted): ?>
                        <div class="lock">
                            <div style="font-weight:800;margin-bottom:6px">Confidential case data is locked</div>
                            <div style="line-height:1.55;margin-bottom:14px;color:#dfb87c">Accept confidentiality to view offenses and join the discussion.</div>
                            <form method="post">
                                <input type="hidden" name="action" value="accept_confidentiality">
                                <button class="btn btn-primary" type="submit">I Accept Confidentiality</button>
                            </form>
                        </div>
                    <?php else: ?>

                        <!-- Student Explanation -->
                        <div id="studentExplanationBlock" style="<?= (!empty($case['student_explanation_text']) || !empty($case['student_explanation_at'])) ? 'display:block' : 'display:none' ?>; margin-bottom: 22px; background: rgba(124,143,201,.06); border: 1px solid rgba(124,143,201,.25); border-radius: var(--radius-md); padding: 16px;">
                           <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                              <span style="font-size: 10.5px; font-weight: 700; color: #a5b6e0; text-transform: uppercase; letter-spacing: 1.5px;">Student Submitted Explanation</span>
                              <span id="explanationTime" style="font-size: 11px; color: var(--text-mute); font-family:var(--f-mono)"><?= $case['student_explanation_at'] ? 'Submitted ' . date('M j, Y g:i A', strtotime($case['student_explanation_at'])) : '' ?></span>
                           </div>
                           <div id="explanationText" style="font-size: 13px; line-height: 1.65; color: var(--text); white-space: pre-wrap; margin-bottom: 12px;"><?= htmlspecialchars($case['student_explanation_text'] ?? '') ?></div>
                           <div id="explanationAttachments" style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center; margin-top: 8px;">
                              <?php if (!empty($case['student_explanation_image'])): ?>
                                <a href="../<?= htmlspecialchars($case['student_explanation_image']) ?>" target="_blank" style="display: block; border-radius: 4px; overflow: hidden; border: 1px solid var(--line-2);">
                                   <img src="../<?= htmlspecialchars($case['student_explanation_image']) ?>" style="max-width: 80px; max-height: 80px; display: block; object-fit: cover;">
                                </a>
                              <?php endif; ?>
                              <?php if (!empty($case['student_explanation_pdf'])): ?>
                                <a href="../<?= htmlspecialchars($case['student_explanation_pdf']) ?>" target="_blank" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: var(--rose-soft); border: 1px solid rgba(201,107,107,.3); border-radius: 4px; text-decoration: none; color: #e0a0a0; font-size: 11px; font-weight: 700; letter-spacing:.5px;">
                                   <span>📄 View PDF Attachment</span>
                                </a>
                              <?php endif; ?>
                           </div>
                        </div>

                        <!-- SUB-TABS NAVIGATION -->
                        <div class="case-breakdown-tabs">
                            <button type="button" class="btn-tab active-tab-btn" onclick="switchBreakdownTab('current-offenses', this)" style="background:var(--gold-faint);color:var(--gold-bright);border-color:var(--line-hi)">
                                📌 Current Case Offenses (<?= count($offenses) ?>)
                            </button>
                            <button type="button" class="btn-tab" onclick="switchBreakdownTab('prior-resolved', this)">
                                ✅ Prior Resolved (<?= count($priorResolvedCases) ?>)
                            </button>
                            <?php if (!empty($otherPendingCases)): ?>
                            <button type="button" class="btn-tab" onclick="switchBreakdownTab('other-pending', this)">
                                ⏳ Other Pending (<?= count($otherPendingCases) ?>)
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- TAB 1: CURRENT OFFENSES -->
                        <div id="tab-current-offenses" class="breakdown-tab-content">
                            <div style="font-size: 12.5px; font-weight: 700; color: var(--text-hi); margin-bottom: 14px; letter-spacing:.5px; text-transform:uppercase;">Case Offenses &amp; Details</div>
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
                                                <div class="offense-meta"><?= $lvl ?> · <?= fmt_dt((string)$offense['date_committed']) ?></div>
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

                                             <?php
  $offEv = $offense['evidence_file'] ?? ($offense['incident_photo'] ?? null);
  if (!empty($offEv)):
    $ext = strtolower(pathinfo($offEv, PATHINFO_EXTENSION));
    $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
?>
<div class="offense-row" style="margin-top:10px;">
    <div class="label">Evidence File</div>
    <div class="value">
        <?php if ($isImg): ?>
            <div style="display:inline-block;">
                <a href="../<?= htmlspecialchars($offEv) ?>" target="_blank" title="Click to view full resolution photo evidence" style="display: block; border-radius: 4px; overflow: hidden; border: 1px solid var(--line-hi);">
                    <img src="../<?= htmlspecialchars($offEv) ?>" style="max-width: 280px; max-height: 180px; object-fit: cover; display: block;">
                </a>
                <div style="font-size:11px; font-weight:700; color:var(--gold); margin-top:6px; display:flex; align-items:center; gap:6px; letter-spacing:.5px; text-transform:uppercase;">
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                    Click to expand full resolution
                </div>
            </div>
        <?php else: ?>
            <a href="../<?= htmlspecialchars($offEv) ?>" target="_blank" style="color: var(--gold); font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; background: var(--gold-faint); border: 1px solid var(--line-hi); border-radius: 4px; text-decoration: none; letter-spacing:.5px;">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span>View Attached Evidence File</span>
            </a>
        <?php endif; ?>
    <?php endif; ?>

                                            <?php if (!empty(trim((string)($offense['intervention_first'] ?? '')))): ?>
                                            <div class="offense-row">
                                                <div class="label">1st Intervention</div>
                                                <div class="value">
                                                    <?= htmlspecialchars(trim(rtrim(preg_replace('/\s*&?\s*0\.0\s+in\s+the\s+course/i', '', preg_replace('/^Category\s*\d+\s*[\(\:\-—]?\s*/i', '', trim((string)$offense['intervention_first']))), ')-—')) ?: 'Formative Intervention: University Service, Counseling, & Evaluation') ?>
                                                    <?php if (!empty($priorResolvedCases)): ?>
                                                        <span style="background:rgba(110,158,126,.2); color:#9dc5a8; padding:3px 9px; border-radius:3px; font-size:10px; font-weight:700; text-transform:uppercase; margin-left:8px; display:inline-flex; align-items:center; gap:3px; letter-spacing:.8px;">✓ Completed</span>
                                                    <?php else: ?>
                                                        <span style="background:rgba(201,152,91,.2); color:#dfb87c; padding:3px 9px; border-radius:3px; font-size:10px; font-weight:700; text-transform:uppercase; margin-left:8px; display:inline-flex; align-items:center; gap:3px; letter-spacing:.8px;">⏳ Ongoing</span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty(trim((string)($offense['intervention_second'] ?? '')))): ?>
                                            <div class="offense-row">
                                                <div class="label">2nd Intervention</div>
                                                <div class="value">
                                                    <?= htmlspecialchars(trim(rtrim(preg_replace('/\s*&?\s*0\.0\s+in\s+the\s+course/i', '', preg_replace('/^Category\s*\d+\s*[\(\:\-—]?\s*/i', '', trim((string)$offense['intervention_second']))), ')-—')) ?: '1 Semester Non-Readmission / Suspension') ?>
                                                    <?php if (!empty($priorResolvedCases)): ?>
                                                        <span style="background:rgba(201,152,91,.2); color:#dfb87c; padding:3px 9px; border-radius:3px; font-size:10px; font-weight:700; text-transform:uppercase; margin-left:8px; display:inline-flex; align-items:center; gap:3px; letter-spacing:.8px;">⏳ Ongoing</span>
                                                    <?php else: ?>
                                                        <span style="background:rgba(255,255,255,.06); color:var(--text-mute); padding:3px 9px; border-radius:3px; font-size:10px; font-weight:600; text-transform:uppercase; margin-left:8px; display:inline-flex; align-items:center; gap:3px; letter-spacing:.8px;">Pending</span>
                                                    <?php endif; ?>
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
                            <div style="font-size: 12.5px; font-weight: 700; color: #9dc5a8; margin-bottom: 14px; letter-spacing:.5px; text-transform:uppercase;">
                                Student's Resolved Disciplinary History
                            </div>
                            <?php if (empty($priorResolvedCases)): ?>
                                <div class="empty" style="text-align: center; padding: 26px; color: var(--text-mute); font-size: 13px; background: rgba(0,0,0,.15); border-radius: 4px; border: 1px dashed var(--line-2);">
                                    Clean Disciplinary Record: No prior resolved cases found for this student.
                                </div>
                            <?php else: foreach ($priorResolvedCases as $rc):
                                $isMajorResolved = ((int)($rc['major_count'] ?? 0)) > 0;
                                $resolvedLvlBadge = $isMajorResolved
                                    ? '<span class="badge badge-blue" style="font-size:10px">MAJOR OFFENSE</span>'
                                    : '<span class="badge badge-amber" style="font-size:10px">SECTION 4 (MINOR)</span>';
                            ?>
                                <div style="background: var(--sage-soft); border: 1px solid rgba(110,158,126,.3); border-radius: var(--radius-md); padding: 14px; margin-bottom: 12px;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 6px;">
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                            <span style="font-weight: 800; color: #9dc5a8; font-size: 13px; font-family:var(--f-mono)">Case #<?= htmlspecialchars((string)$rc['case_id']) ?></span>
                                            <?= $resolvedLvlBadge ?>
                                        </div>
                                        <span class="badge badge-emerald" style="font-size:10px">Resolved</span>
                                    </div>
                                    <div style="font-weight: 700; color: var(--text-hi); font-size: 13px; margin-bottom: 4px;">
                                        <?= htmlspecialchars((string)($rc['offense_names'] ?: 'General Violation')) ?>
                                    </div>
                                    <div style="font-size: 11px; color: var(--text-mute); margin-bottom: 8px; font-family:var(--f-mono)">
                                        Code: <?= htmlspecialchars((string)($rc['offense_codes'] ?: 'N/A')) ?> · Resolved <?= fmt_dt((string)$rc['updated_at']) ?>
                                    </div>
                                    <?php if (!empty($rc['decided_category'])): ?>
                                        <div style="font-size: 11px; color: #9dc5a8; background: rgba(110,158,126,.15); padding: 4px 9px; border-radius: 3px; display: inline-block; font-weight: 700; letter-spacing:.5px; text-transform:uppercase;">
                                            Decided Penalty: Category <?= (int)$rc['decided_category'] ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; endif; ?>
                        </div>

                        <?php if (!empty($otherPendingCases)): ?>
                        <!-- TAB 3: OTHER PENDING -->
                        <div id="tab-other-pending" class="breakdown-tab-content" style="display: none;">
                            <div style="font-size: 12.5px; font-weight: 700; color: #dfb87c; margin-bottom: 14px; letter-spacing:.5px; text-transform:uppercase;">
                                Other Active / Pending Cases Under Investigation
                            </div>
                            <?php foreach ($otherPendingCases as $pc):
                                $isMajorPending = ((int)($pc['major_count'] ?? 0)) > 0;
                                $pendingLvlBadge = $isMajorPending
                                    ? '<span class="badge badge-blue" style="font-size:10px">MAJOR OFFENSE</span>'
                                    : '<span class="badge badge-amber" style="font-size:10px">SECTION 4 (MINOR)</span>';
                            ?>
                                <div style="background: rgba(201,152,91,.06); border: 1px solid rgba(201,152,91,.28); border-radius: var(--radius-md); padding: 14px; margin-bottom: 12px;">
                                    <div style="display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 6px;">
                                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                                            <a href="case_view.php?id=<?= (int)$pc['case_id'] ?>" style="font-weight: 800; color: #dfb87c; font-size: 13px; text-decoration: underline; font-family:var(--f-mono)">
                                                Case #<?= htmlspecialchars((string)$pc['case_id']) ?> ↗
                                            </a>
                                            <?= $pendingLvlBadge ?>
                                        </div>
                                        <span class="badge badge-amber" style="font-size:10px">
                                            <?= htmlspecialchars(str_replace('_', ' ', (string)$pc['status'])) ?>
                                        </span>
                                    </div>
                                    <div class="case-details-blur">
                                        <div style="font-weight: 700; color: var(--text-hi); font-size: 13px; margin-bottom: 4px;">
                                            <?= htmlspecialchars((string)($pc['offense_names'] ?: 'General Violation')) ?>
                                        </div>
                                        <div style="font-size: 11px; color: var(--text-mute);">
                                            Code: <?= htmlspecialchars((string)($pc['offense_codes'] ?: 'N/A')) ?> · Created <?= fmt_dt((string)$pc['created_at']) ?>
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
                <div class="panel-header"><div class="panel-title">💬 Live Panel Deliberation</div></div>
                <div class="panel-body" style="display:flex;flex-direction:column;padding:0">
                    <?php if (!$confidentialityAccepted): ?>
                        <div class="lock" style="margin:24px">Accept confidentiality to join the discussion.</div>
                    <?php else: ?>
                        <div id="live-chat-box" style="height:340px;overflow-y:auto;background:rgba(0,0,0,.15);
                            border:1px solid var(--line-2);border-radius:var(--radius-md);padding:14px;margin:16px 24px 0">
                            <div style="text-align:center;color:var(--text-mute);font-size:11px">Loading…</div>
                        </div>
                        <div id="replying-to-container" style="display:none;background:rgba(124,143,201,.1);
                            padding:9px 24px;border-top:1px solid rgba(124,143,201,.25);font-size:11.5px;color:#c7d2fe">
                            <strong>Replying to <span id="reply-to-name"></span>:</strong>
                            <span id="reply-to-text" style="color:var(--text-mute)"></span>
                            <button type="button" class="btn btn-secondary" onclick="cancelReply()"
                                style="float:right;padding:2px 7px;font-size:10px;min-height:0">✕</button>
                        </div>
                        <form id="chat-form" style="padding:16px 24px 24px">
                            <input type="hidden" id="reply_to" name="reply_to" value="">
                            <input type="hidden" name="action" value="post_message">
                            <input type="hidden" name="case_id" value="<?= $caseId ?>">
                            <?php $isHearingOpen = ((int)$case['hearing_is_open'] === 1); ?>
                            <div class="chat-input-wrapper">
                                <textarea id="chat_message_input" name="message" class="chat-textarea"
                                    placeholder="<?= $isHearingOpen && !$isHearingPaused ? 'Type your message…' : ($isHearingPaused ? 'Chat disabled — hearing is paused' : 'Chat disabled until hearing opens…') ?>"
                                    required <?= (!$isHearingOpen || $isHearingPaused) ? 'disabled' : '' ?>></textarea>
                                <button class="chat-send-btn" type="submit" <?= (!$isHearingOpen || $isHearingPaused) ? 'disabled' : '' ?> id="chat_submit_btn" title="Send Message" aria-label="Send Message">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                                        <line x1="22" y1="2" x2="11" y2="13"></line>
                                        <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
                                    </svg>
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <!-- ── RIGHT COLUMN ──────────────────────────────────────────── -->
        <aside class="stack">

            <!-- DECISION PANEL INFO -->
            <div class="glass-panel" id="decision-panel">
                <div class="panel-header"><div class="panel-title">🗳️ Decision Panel</div></div>
                <div class="panel-body">
                    <div class="decision-box">
                        <div style="font-family:var(--f-serif);font-size:14px;font-weight:700;color:var(--gold-bright);letter-spacing:.3px"><?= htmlspecialchars($decisionHint) ?></div>
                        <div style="font-size:13px;color:var(--text);line-height:1.6">
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
                    <div>
                        <div class="panel-title">👥 Panel Members</div>
                        <div style="color:var(--text-mute);font-size:11.5px;margin-top:4px;letter-spacing:.3px">Assigned to this case</div>
                    </div>
                </div>
                <div class="panel-body" style="display:grid;gap:10px">
                    <?php if (empty($panelMembers)): ?>
                        <div class="empty">No assigned panel members found.</div>
                    <?php else: foreach ($panelMembers as $m):
                        $uid = (int)$m['upcc_id'];
                        $isSug = $uid === $suggesterId && $isRoundActive;
                        $vote  = $votesByMember[$uid] ?? null;
                    ?>
                        <div class="info-box" style="min-width:0">
                            <div style="font-family:var(--f-serif);font-weight:700;color:var(--text-hi);font-size:14px"><?= htmlspecialchars($m['full_name']) ?> <?= $uid === $panelId ? '<small style="color:var(--text-mute);font-family:var(--f-sans);font-size:10px;letter-spacing:1px">(YOU)</small>' : '' ?></div>
                            <div style="color:var(--text-mute);font-size:11px;margin-top:2px;text-transform:uppercase;letter-spacing:1px;font-weight:600"><?= htmlspecialchars(ucfirst($m['role'])) ?></div>
                            <div style="font-size:12px;margin-top:8px">
                                <?php if ($isSug): ?>
                                    <span style="color:var(--gold-bright);font-weight:700">🗣️ Suggested this round</span>
                                <?php elseif ($vote !== null && $isRoundActive): ?>
                                    <?= $vote > 0 ? '<span style="color:#9dc5a8;font-weight:700">✅ Agreed</span>' : '<span style="color:#e0a0a0;font-weight:700">❌ Disagreed</span>' ?>
                                <?php elseif ($isRoundActive): ?>
                                    <span style="color:#dfb87c;font-weight:700">⏳ Pending vote</span>
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
                        <div class="panel-title">⚖️ Penalty Suggestion &amp; Voting</div>
                        <div style="color:var(--text-mute);font-size:11.5px;margin-top:4px;letter-spacing:.3px">One member suggests · majority must agree</div>
                    </div>
                </div>
                <div class="panel-body">

                    <?php if (!$confidentialityAccepted): ?>
                        <div class="lock">Accept confidentiality before suggesting penalties or voting.</div>

                    <?php elseif ($isAwaitingAdmin): ?>
                        <!-- ── CONSENSUS REACHED ── -->
                        <div class="consensus-banner">
                            <div class="consensus-banner-title">Consensus Reached</div>
                            <div style="font-family:var(--f-serif);font-size:20px;font-weight:700;color:#a7f3d0;margin:8px 0">Category <?= $consensusCategory ?></div>
                            <div style="font-size:12px;color:var(--text-mute)">Awaiting Admin to record the final decision.</div>
                        </div>
                        <div class="info-box" style="margin-bottom:16px;min-width:0">
                            <div class="info-label">Agreed Penalty</div>
                            <div style="font-size:13px;line-height:1.6;margin-top:6px;color:var(--text)"><?= htmlspecialchars($categoryDescriptions[$consensusCategory] ?? '') ?></div>
                            <?php
                            $cda = $case['hearing_vote_suggested_details'] ? json_decode($case['hearing_vote_suggested_details'], true) : null;
                            ?>
                            <?php if ($consensusCategory === 1 && !empty($cda['probation_terms'])): ?>
                                <div style="margin-top:8px;font-size:12px;color:var(--text-mute)">Probation: <?= (int)$cda['probation_terms'] ?> term(s)</div>
                            <?php endif; ?>
                            <?php if ($consensusCategory === 2 && !empty($cda['interventions'])): ?>
                                <div style="margin-top:8px;font-size:12px;color:var(--text-mute)">
                                    Interventions: <?= htmlspecialchars(implode(', ', $cda['interventions'])) ?>
                                    <?php if (!empty($cda['service_hours'])): ?>(<?= _formatCsHoursStr($cda['service_hours']) ?>)<?php endif; ?>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($cda['description'])): ?>
                                <div style="margin-top:10px;padding-top:10px;border-top:1px solid var(--line-2);font-size:13px;line-height:1.6"><?= nl2br(htmlspecialchars($cda['description'])) ?></div>
                            <?php endif; ?>
                        </div>

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
                        <div style="text-align:center;font-size:11px;color:var(--text-mute);margin-bottom:12px;letter-spacing:.3px">
                            Majority of the panel must agree to finalize<br>
                            <button type="button" class="btn btn-secondary" onclick="openVotingModalForRound(<?= $roundNo ?>)" style="margin-top:8px;padding:6px 12px;font-size:10.5px;">Re-open Voting Window</button>
                        </div>

                        <div class="suggestion-box">
                            <div class="suggestion-category">Category <?= $suggestedDetails['category'] ?></div>
                            <div style="font-size:11.5px;color:var(--text-mute);margin-bottom:8px;letter-spacing:.3px">Suggested by <strong style="color:var(--text-hi);font-family:var(--f-serif)"><?= htmlspecialchars($suggesterName) ?></strong></div>
                            <?php _renderSugDetails($suggestedDetails); ?>
                        </div>

                        <?php if ($showCancelSuggestion): ?>
                            <form method="post" onsubmit="return confirm('Cancel your suggestion? The panel can submit a new suggestion immediately.')">
                                <input type="hidden" name="action" value="cancel_suggestion">
                                <input type="hidden" name="round_no" value="<?= $roundNo ?>">
                                <button type="submit" class="btn btn-danger" style="width:100%">Cancel My Suggestion</button>
                            </form>
                            <div style="text-align:center;font-size:11px;color:var(--text-mute);margin-top:10px">You are the suggester — waiting for others to vote</div>
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
                                <div style="font-size:11px;margin-top:4px;color:var(--text-mute)">Waiting for other panel members…</div>
                            </div>
                        <?php endif; ?>

                    <?php else: ?>
                        <!-- ── NO ACTIVE ROUND: SUGGEST FORM ── -->
                        <?php if ($isInCooldown): ?>
                            <div class="cooldown-alert">
                                Cooldown active — any panel member can suggest after:
                                <strong id="cooldownDisplay"><?= sprintf('%02d:%02d', floor($cooldownRemainingSecs / 60), $cooldownRemainingSecs % 60) ?></strong>
                            </div>
                        <?php endif; ?>

                        <?php if (!$isInCooldown && !$isClosed): ?>
                            <details id="suggestDetails" style="margin-top:8px">
                                <summary id="suggestDetailsSummary" style="cursor:pointer;color:var(--gold-bright);font-weight:700;padding:10px 0;font-size:12.5px;letter-spacing:.5px;text-transform:uppercase;">
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
                                    <div id="sugCat1" style="display:none;margin-bottom:12px;padding:14px;background:rgba(0,0,0,.2);border-radius:var(--radius-md);border:1px solid var(--line-2)">
                                        <div style="font-size:10.5px;color:var(--text-mute);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px;font-weight:700">Probation Details</div>
                                        <div class="field" style="margin-bottom:0">
                                            <label>Number of probation terms</label>
                                            <select name="suggest_cat1_terms" class="fld-input">
                                                <option value="1">1 term</option>
                                                <option value="2">2 terms</option>
                                                <option value="3" selected>3 terms (maximum)</option>
                                            </select>
                                        </div>
                                        <div style="margin-top:10px;font-size:12px;color:var(--text-mute);line-height:1.55">
                                            ℹ️ Any subsequent major offense during probation triggers Suspension or Non-Readmission.
                                        </div>
                                    </div>

                                    <!-- CAT 2 -->
                                    <div id="sugCat2" style="display:none;margin-bottom:12px;padding:14px;background:rgba(0,0,0,.2);border-radius:var(--radius-md);border:1px solid var(--line-2)">
                                        <div style="font-size:10.5px;color:var(--text-mute);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px;font-weight:700">Formative Interventions</div>
                                        <label style="font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:8px;cursor:pointer">
                                            <input type="checkbox" id="sug_us" name="suggest_cat2_university_service" value="1" onchange="toggleSugHours()">
                                            University Service (Community Service)
                                        </label>
                                        <div id="sugHoursBox" style="display:none;margin-left:22px;margin-bottom:8px">
                                            <label style="font-size:10.5px;color:var(--text-mute);text-transform:uppercase;letter-spacing:1.2px;font-weight:700">Required Hours</label>
                                            <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:8px" id="sugHoursBtns">
                                                <?php foreach ([100,150,200,250,300,350,400,450,500] as $h): ?>
                                                    <button type="button" class="sug-hrs-btn" data-h="<?= $h ?>"
                                                        onclick="selectSugHours('<?= $h ?>', this)"
                                                        style="padding:6px 14px;font-size:11.5px;border-radius:3px;border:1px solid var(--line-2);
                                                               background:rgba(0,0,0,.2);color:var(--text-dim);cursor:pointer;font-family:var(--f-sans);font-weight:600">
                                                        <?= $h ?> hrs
                                                    </button>
                                                <?php endforeach; ?>
                                                    <button type="button" class="sug-hrs-btn" data-h="OTHER"
                                                        onclick="selectSugHours('OTHER', this)"
                                                        style="padding:6px 14px;font-size:11.5px;border-radius:3px;border:1px solid var(--line-2);
                                                               background:rgba(0,0,0,.2);color:var(--text-dim);cursor:pointer;font-family:var(--f-sans);font-weight:600">
                                                        Other
                                                    </button>
                                            </div>
                                            <div id="sug_cat2_custom_wrap" style="display:none;align-items:center;gap:6px;margin-top:8px">
                                                <input type="number" id="sug_cat2_service_hours_custom_h" name="suggest_cat2_service_hours_custom_h" min="0" step="1" placeholder="Hours" style="width:80px;padding:8px 8px;border-radius:3px;background:rgba(0,0,0,.3);color:var(--text-hi);border:1px solid var(--line-2);font-family:var(--f-sans);font-size:13px">
                                                <span style="color:var(--text-mute);font-size:12px">hrs</span>
                                                <input type="number" id="sug_cat2_service_hours_custom_m" name="suggest_cat2_service_hours_custom_m" min="0" max="59" step="1" placeholder="Minutes" style="width:90px;padding:8px 8px;border-radius:3px;background:rgba(0,0,0,.3);color:var(--text-hi);border:1px solid var(--line-2);font-family:var(--f-sans);font-size:13px">
                                                <span style="color:var(--text-mute);font-size:12px">mins</span>
                                            </div>
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
                                    <div id="sugCat345" style="display:none;margin-bottom:12px;padding:14px;background:var(--rose-soft);border-radius:var(--radius-md);border:1px solid rgba(201,107,107,.3)">
                                        <div style="font-size:13px;color:#e0a0a0;line-height:1.55" id="sugCat345Text"></div>
                                        <div style="margin-top:8px;font-size:12px;color:#e0a0a0;font-weight:700">⚠️ Student account will be frozen upon finalization.</div>
                                    </div>

                                    <div class="field" style="margin-bottom:14px">
                                        <label>Penalty Rationale / Notes <span style="color:var(--text-mute);font-weight:400;text-transform:none;letter-spacing:0">(optional)</span></label>
                                        <textarea name="suggest_description" class="fld-input" rows="3" placeholder="Describe the reasoning…"></textarea>
                                    </div>

                                    <button id="suggestSubmitBtn" type="submit" class="btn btn-primary" style="width:100%">📝 Suggest This Penalty</button>
                                    <div id="suggestLockNote" style="display:none;margin-top:10px;font-size:12px;color:#dfb87c;text-align:center"></div>
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
        echo '<div style="font-size:13px;color:var(--text);margin-bottom:6px">🗓️ Probation: <strong>' . (int)$details['probation_terms'] . ' term(s)</strong></div>';
    endif;
    if ($cat === 2 && !empty($details['interventions'])):
        echo '<div style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:6px">';
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

<!-- ── PRESENCE OVERLAY ───────────────────────────────────────────────── -->
<div id="presenceOverlay" class="presence-overlay" style="display:none">
    <div class="presence-card">
        <div id="presenceIcon" style="font-size:44px;margin-bottom:20px">🚪</div>
        <div id="presenceTitle" style="font-family:var(--f-serif);font-size:24px;font-weight:700;margin-bottom:12px;color:var(--text-hi)">Waiting Room</div>
        <div id="presenceText" style="font-size:14.5px;line-height:1.65;color:var(--text-dim)">Please wait for the Admin to let you in.</div>
        <div style="margin-top:24px;display:flex;gap:10px;flex-direction:column">
            <button id="requestJoinBtn" class="btn btn-primary" style="width:100%;display:none" onclick="requestJoinHearing()">🔔 Request to Join Hearing</button>
            <button id="exitHearingBtn" class="btn btn-secondary" style="width:100%;display:none" onclick="exitHearing()">🚪 Exit Hearing</button>
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     VOTING MODAL
══════════════════════════════════════════════════════════════════════ -->
<div id="votingModal" class="voting-modal <?= $showVotingPopup ? 'open' : '' ?>">
    <div class="vmc" id="vmcInner">

        <div class="vmc-header">
            <div class="vmc-title">Live Voting Session</div>
            <span class="vmc-live-badge" id="vmcLiveBadge">● Live</span>
        </div>
        <div class="vmc-sub" id="vmcSub">
            <?php if ($isCurrentUserSuggester): ?>
                You submitted this penalty proposal. Your screen will stay in waiting mode until the other panel members vote.
            <?php else: ?>
                <strong style="color:var(--text-hi)"><?= htmlspecialchars($suggesterName ?? '') ?></strong> submitted a penalty proposal. Choose <strong>Agree</strong> or <strong>Disagree</strong> before the 10-minute voting window ends.
            <?php endif; ?>
        </div>

        <!-- Timer -->
        <div class="timer-wrap">
            <div class="timer-top">
                <span class="timer-label">Time to vote</span>
                <span class="timer-num" id="vmcTimer"><?= sprintf('%02d:%02d', floor($roundSecondsRemaining / 60), $roundSecondsRemaining % 60) ?></span>
            </div>
            <div class="timer-bar-wrap">
                <div class="timer-bar-fill" id="vmcTimerBar"
                     style="width:<?= $roundSecondsRemaining > 0 ? round(($roundSecondsRemaining / 600) * 100) : 0 ?>%;
                            background:<?= $roundSecondsRemaining > 600 ? '#6e9e7e' : ($roundSecondsRemaining > 180 ? '#c9985b' : '#c96b6b') ?>"></div>
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

        <!-- Voter list -->
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
                        <div class="voter-name"><?= htmlspecialchars($m['full_name']) ?><?= $uid === $panelId ? ' <small style="color:var(--text-mute);font-size:10px">(YOU)</small>' : '' ?></div>
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
                        <button type="submit" class="btn btn-danger" style="width:100%;padding:14px;font-size:13px">❌ Cancel My Suggestion</button>
                    </form>
                    <div style="text-align:center;font-size:11px;color:var(--text-mute);margin-top:10px">You are the suggester. Only the other panel members can vote.</div>
                    <div style="text-align:center;margin-top:12px;">
                        <button type="button" class="btn btn-secondary" onclick="closeVotingModal()" style="padding:6px 14px;font-size:11px;">Hide Window</button>
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
                            <button type="button" class="btn btn-secondary" onclick="closeVotingModal()" style="padding:6px 14px;font-size:11px;">Hide Window</button>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <div style="text-align:center;margin-top:16px;font-size:11.5px;color:var(--text-mute);letter-spacing:.3px">
            This live voting window remains open for up to 10 minutes, or until consensus/cancellation.
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
    if (r) r.textContent = reason === 'AUTO_PAUSE_ADMIN_LEFT' ? 'The admin disconnected.' : 'The admin has paused the hearing.';
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
    if (typeof showToast === 'function') {
        showToast('Waiting for Admin', 'You are in waiting mode. The hearing will automatically resume once the admin returns.', 'info');
    }
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

<!-- ══════════════════════════════════════════════════════════════════════
     COOLDOWN MODAL
══════════════════════════════════════════════════════════════════════ -->
<div id="cooldownModal" class="modal-shell">
    <div class="modal-card" style="border-color:rgba(201,107,107,.4);max-width:420px">
        <div style="font-size:36px;margin-bottom:14px">⏳</div>
        <div style="font-family:var(--f-serif);font-size:20px;font-weight:700;margin-bottom:8px;color:var(--text-hi)">Voting Cooldown Active</div>
        <div style="font-size:12.5px;color:var(--text-mute);margin-bottom:20px;line-height:1.55" id="cooldownModalReason">
            Voting ended. Any panel member may suggest again after:
        </div>
        <div style="font-family:var(--f-mono);font-size:44px;font-weight:600;color:#dfb87c;
            font-variant-numeric:tabular-nums;margin-bottom:8px;letter-spacing:2px" id="cooldownModalTimer">3:00</div>
        <div style="font-size:11.5px;color:var(--text-mute);letter-spacing:.5px">Panel will be unlocked automatically</div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     AWAITING ADMIN MODAL
══════════════════════════════════════════════════════════════════════ -->
<div id="awaitingAdminModal" class="modal-shell" style="<?= $isAwaitingAdmin ? 'display:flex' : 'display:none' ?>">
    <div class="modal-card" style="border-color:rgba(110,158,126,.5);max-width:420px">
        <div style="font-size:44px;margin-bottom:16px">⏳</div>
        <div style="font-family:var(--f-serif);font-size:22px;font-weight:700;color:#a7f3d0;margin-bottom:12px">Waiting for Admin</div>
        <div style="font-size:13.5px;color:var(--text-dim);line-height:1.65">
            The panel has reached a consensus.<br><br>
            Please wait while the Admin reviews and records the final decision. You will be redirected automatically once the case is closed.
        </div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     CASE RESOLVED MODAL
══════════════════════════════════════════════════════════════════════ -->
<div id="caseResolvedModal" class="modal-shell" style="display:none">
    <div class="modal-card" style="border-color:rgba(124,143,201,.5);max-width:420px">
        <button onclick="dismissResolvedModal()" style="position:absolute;top:12px;right:12px;background:none;border:none;color:var(--text-mute);font-size:20px;cursor:pointer;padding:4px 8px;line-height:1" title="Dismiss">✕</button>
        <div style="font-size:44px;margin-bottom:16px">🎓</div>
        <div style="font-family:var(--f-serif);font-size:22px;font-weight:700;color:#a5b6e0;margin-bottom:12px">Case Resolved</div>
        <div style="font-size:13.5px;color:var(--text-dim);line-height:1.65;margin-bottom:24px">
            The Admin has finalized and recorded the decision. This case is now permanently closed.
        </div>
        <a href="upccdashboard.php" class="btn btn-primary" style="width:100%;padding:14px;font-size:13px">Return to Dashboard</a>
        <div style="font-size:11px;color:var(--text-mute);margin-top:12px">Auto-redirecting in <span id="resolvedCountdown">5</span>s…</div>
    </div>
</div>

<!-- ══════════════════════════════════════════════════════════════════════
     HEARING PAUSED MODAL
══════════════════════════════════════════════════════════════════════ -->
<div id="hearingPausedModal" class="modal-shell" style="display:none">
    <!-- State 1 -->
    <div id="pauseModalStateOptions" class="modal-card" style="border-color:rgba(201,107,107,.4);max-width:480px">
        <div style="font-size:44px;margin-bottom:16px">⏸️</div>
        <div style="font-family:var(--f-serif);font-size:22px;font-weight:700;color:#e0a0a0;margin-bottom:10px">Hearing Has Been Paused</div>
        <div style="font-size:13px;color:var(--text-dim);line-height:1.65;margin-bottom:24px">
            <p id="pauseReasonText" style="margin:0 0 12px">The admin has paused the hearing.</p>
            <p style="margin:0;font-size:12px;font-style:italic">You can stay in the hearing and wait for it to resume, or return to your dashboard.</p>
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:16px">
            <button type="button" class="btn btn-primary" onclick="setPauseWaitingState();" style="padding:14px;font-size:12.5px;">⏳ Waiting for Admin</button>
            <button type="button" class="btn btn-secondary" onclick="exitHearing()" style="padding:14px;font-size:12.5px;">← Dashboard</button>
        </div>
        <div style="padding:12px;background:var(--sage-soft);border:1px solid rgba(110,158,126,.3);border-radius:var(--radius-sm);font-size:12px;color:#9dc5a8">
            The hearing will automatically resume. Stay and keep your place in the panel.
        </div>
    </div>

    <!-- State 2 -->
    <div id="pauseModalStateWaiting" class="modal-card" style="display:none;border-color:rgba(124,143,201,.4);max-width:480px">
        <div style="font-size:44px;margin-bottom:16px">⏳</div>
        <div style="font-family:var(--f-serif);font-size:22px;font-weight:700;color:#a5b6e0;margin-bottom:10px">Waiting for Admin…</div>
        <div style="font-size:13px;color:var(--text-dim);line-height:1.65;margin-bottom:20px">
            <p style="margin:0 0 10px">You are currently waiting in the live hearing session.</p>
            <p style="margin:0;font-size:12px;color:var(--text)">The hearing will automatically resume as soon as the admin returns. Stay and keep your place in the panel.</p>
        </div>
        <div style="padding:12px;background:var(--sage-soft);border:1px solid rgba(110,158,126,.3);border-radius:var(--radius-sm);font-size:12px;color:#9dc5a8;margin-bottom:20px">
            ✓ Connected to live session. Standing by…
        </div>
        <button type="button" class="btn btn-secondary" onclick="exitHearing()" style="width:100%;padding:14px;font-size:12.5px;">← Dashboard</button>
    </div>
</div>

<!-- Confirm Exit Hearing Modal -->
<div id="confirmExitHearingModal" class="modal-shell" style="display:none;z-index:9300">
    <div class="modal-card" style="border-color:rgba(201,107,107,.4);max-width:400px">
        <div style="font-size:38px;margin-bottom:12px">⚠️</div>
        <div style="font-family:var(--f-serif);font-size:20px;font-weight:700;color:#e0a0a0;margin-bottom:8px">Leave Hearing?</div>
        <div style="font-size:13px;color:var(--text-dim);line-height:1.6;margin-bottom:24px">
            If you leave now, you will need the admin's permission to rejoin the hearing later. Are you sure?
        </div>
        <div style="display:flex;gap:12px;justify-content:center">
            <button type="button" class="btn btn-outline" onclick="cancelExitHearing()">Cancel</button>
            <button type="button" class="btn btn-danger" onclick="proceedExitHearing()" id="confirmExitHearingBtn">Yes, Leave</button>
        </div>
    </div>
</div>

<!-- Rejoin Sent Modal -->
<div id="rejoinSentModal" class="modal-shell" style="display:none;z-index:9400">
    <div class="modal-card" style="max-width:360px">
        <div style="font-size:20px;font-weight:700;margin-bottom:10px;color:var(--text-hi)">🔔 Rejoin Request Sent</div>
        <div style="font-size:13px;color:var(--text-dim);margin-bottom:18px;line-height:1.55">Your request to rejoin has been sent. Please wait for the Admin to let you in.</div>
        <div style="display:flex;gap:8px;justify-content:center">
            <button class="btn btn-outline" onclick="document.getElementById('rejoinSentModal').style.display='none'">Close</button>
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
const ROUND_ENDS_EPOCH = Math.floor(Date.now() / 1000) + <?= $roundSecondsRemaining ?>;
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
let pauseReason       = <?= !empty($case['hearing_pause_reason']) ? json_encode($case['hearing_pause_reason']) : 'null' ?>;
let resumeReloadQueued = false;

// Ensure pause modal is shown immediately if paused on load
if (currentPauseState) {
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => showPauseModal(pauseReason));
    } else {
        showPauseModal(pauseReason);
    }
}

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
            barEl.style.background = rem > 600 ? '#6e9e7e' : rem > 180 ? '#c9985b' : '#c96b6b';
        }

        if (rem <= 0) {
            clearInterval(timerInterval);
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
    const sugCatEl = document.getElementById('suggest_category');
    const v = parseInt(sugCatEl?.value || '0', 10);
    const show = id => { const el = document.getElementById(id); if (el) el.style.setProperty('display', 'block', 'important'); };
    const hide = id => { const el = document.getElementById(id); if (el) el.style.setProperty('display', 'none', 'important'); };

    hide('sugCat1');
    hide('sugCat2');
    hide('sugCat345');

    if (v !== 2) {
        const cat2Box = document.getElementById('sugCat2');
        if (cat2Box) {
            cat2Box.querySelectorAll('input[type=checkbox]').forEach(cb => cb.checked = false);
        }
        toggleSugHours();
    }

    if (v === 1) {
        show('sugCat1');
    } else if (v === 2) {
        show('sugCat2');
    } else if (v >= 3 && v <= 5) {
        show('sugCat345');
        const texts = {
            3: 'Category 3 — Non-Readmission / Suspension.',
            4: 'Category 4 — Exclusion / Mandatory Dismissal (Dropped from University Rolls).',
            5: 'Category 5 — Summary Expulsion & Police Referral (Permanent Disqualification).',
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
            b.style.background = 'rgba(0,0,0,.2)'; b.style.color = 'var(--text-dim)';
            b.style.border = '1px solid var(--line-2)';
        });
    }
}

let selectedSugHours = '';
function selectSugHours(h, btn) {
    selectedSugHours = h;
    document.getElementById('sug_cat2_service_hours').value = h;
    const wrap = document.getElementById('sug_cat2_custom_wrap');
    if (wrap) wrap.style.display = h === 'OTHER' ? 'flex' : 'none';
    if (h !== 'OTHER') {
        const cus = document.getElementById('sug_cat2_service_hours_custom');
        if (cus) cus.value = '';
    }

    document.querySelectorAll('.sug-hrs-btn').forEach(b => {
        const active = b.dataset.h == h;
        b.style.background = active ? 'var(--gold-faint)' : 'rgba(0,0,0,.2)';
        b.style.color      = active ? 'var(--gold-bright)' : 'var(--text-dim)';
        b.style.border     = active ? '1px solid var(--line-hi)' : '1px solid var(--line-2)';
        b.style.fontWeight = active ? '700' : '600';
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
        <div style="margin-bottom:12px;padding:14px;background:rgba(0,0,0,.2);border-radius:var(--radius-md);border:1px solid var(--line-2)">
            <div style="font-size:10.5px;color:var(--text-mute);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px;font-weight:700">Probation Details</div>
            <label style="font-size:10.5px;color:var(--text-mute);text-transform:uppercase;letter-spacing:1.2px;font-weight:700">Number of terms</label>
            <select name="cat1_terms" style="width:100%;margin-top:6px;padding:10px 14px;border-radius:var(--radius-sm);background:rgba(0,0,0,.35);color:var(--text-hi);border:1px solid var(--line-2);font-family:var(--f-sans);font-size:13px">
                <option value="1">1 term</option>
                <option value="2">2 terms</option>
                <option value="3" selected>3 terms (maximum)</option>
            </select>
            <div style="margin-top:8px;font-size:12px;color:var(--text-mute);line-height:1.55">Any subsequent major offense triggers Suspension or Non-Readmission.</div>
        </div>`;
    } else if (cat === 2) {
        container.innerHTML = `
        <div style="margin-bottom:12px;padding:14px;background:rgba(0,0,0,.2);border-radius:var(--radius-md);border:1px solid var(--line-2)">
            <div style="font-size:10.5px;color:var(--text-mute);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:10px;font-weight:700">Formative Interventions</div>
            <label style="font-size:13px;display:flex;align-items:center;gap:8px;margin-bottom:8px;cursor:pointer">
                <input type="checkbox" name="cat2_university_service" value="1" onchange="toggleFinalHours(this)"> University Service
            </label>
            <div id="finalHoursBox" style="display:none;margin-left:22px;margin-bottom:10px">
                <label style="font-size:10.5px;color:var(--text-mute);text-transform:uppercase;letter-spacing:1.2px;font-weight:700">Required Hours</label>
                <select name="cat2_service_hours" style="width:100%;margin-top:4px;padding:8px 12px;border-radius:var(--radius-sm);background:rgba(0,0,0,.35);color:var(--text-hi);border:1px solid var(--line-2);font-family:var(--f-sans);font-size:13px" onchange="this.parentElement.querySelector('.cat2-custom-wrap').style.display = this.value === 'OTHER' ? 'flex' : 'none'">
                    <?php foreach ([100,150,200,250,300,350,400,450,500] as $h): ?>
                        <option value="<?= $h ?>"><?= $h ?> hours</option>
                    <?php endforeach; ?>
                    <option value="OTHER">Other</option>
                </select>
                <div class="cat2-custom-wrap" style="display:none;align-items:center;gap:8px;margin-top:6px">
                    <input type="number" name="cat2_service_hours_custom_h" min="0" step="1" placeholder="Hours" style="flex:1;padding:8px 12px;border-radius:var(--radius-sm);background:rgba(0,0,0,.35);color:var(--text-hi);border:1px solid var(--line-2);font-family:var(--f-sans);font-size:13px">
                    <span style="color:var(--text-mute);font-size:12px">hrs</span>
                    <input type="number" name="cat2_service_hours_custom_m" min="0" max="59" step="1" placeholder="Minutes" style="flex:1;padding:8px 12px;border-radius:var(--radius-sm);background:rgba(0,0,0,.35);color:var(--text-hi);border:1px solid var(--line-2);font-family:var(--f-sans);font-size:13px">
                    <span style="color:var(--text-mute);font-size:12px">mins</span>
                </div>
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
        <div style="margin-bottom:12px;padding:14px;background:var(--rose-soft);border-radius:var(--radius-md);border:1px solid rgba(201,107,107,.3)">
            <div style="font-size:13px;color:#e0a0a0;font-weight:600;line-height:1.55">${msgs[cat] || ''}</div>
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
//  PARTIAL RELOAD
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
                if (n && o) {
                    o.innerHTML = n.innerHTML;
                    o.className = n.className;
                    if (n.getAttribute('style')) {
                        o.setAttribute('style', n.getAttribute('style'));
                    } else {
                        o.removeAttribute('style');
                    }
                }
            });
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
        box.innerHTML = '<div style="text-align:center;color:var(--text-mute);font-size:11px">No messages yet.</div>';
        return;
    }
    const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 60;
    box.innerHTML = msgs.map(m => {
        if (m.is_system) {
            return `<div style="text-align:center;margin:14px 0">
                <span style="background:var(--gold-faint);color:var(--gold-bright);border:1px solid var(--line-hi);
                    padding:6px 14px;border-radius:3px;font-size:11px;font-weight:700;letter-spacing:.5px">${esc(m.message)}</span>
            </div>`;
        }
        const isMe   = !!m.is_me;
        const bg     = isMe ? 'rgba(201,169,97,.06)' : 'rgba(0,0,0,.18)';
        const border = isMe ? '1px solid var(--line-hi)' : '1px solid var(--line-2)';
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
//  UPDATE VOTER LIST
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
//  PAUSE STATE HANDLERS
// ─────────────────────────────────────────────────────────────────────────
function updatePauseUI(isPaused, pauseReason = null) {
    const heroMeta = document.querySelector('.hero-meta');
    if (!heroMeta) return;

    const oldPausePill = heroMeta.querySelector('[data-pause-pill]');
    if (oldPausePill) oldPausePill.remove();

    if (isPaused) {
        const pill = document.createElement('span');
        pill.className = 'pill';
        pill.setAttribute('data-pause-pill', '1');
        pill.style.background = 'var(--rose-soft)';
        pill.style.color = '#e0a0a0';
        pill.style.borderColor = 'rgba(201,107,107,.4)';
        pill.innerHTML = '⏸ HEARING PAUSED';
        heroMeta.appendChild(pill);
    } else {
        const pill = document.createElement('span');
        pill.className = 'pill';
        pill.setAttribute('data-pause-pill', '1');
        pill.style.background = 'var(--sage-soft)';
        pill.style.color = '#9dc5a8';
        pill.style.borderColor = 'rgba(110,158,126,.4)';
        pill.innerHTML = '<span style="display:inline-block;width:6px;height:6px;background:#9dc5a8;border-radius:50%;margin-right:4px"></span> HEARING LIVE';
        heroMeta.appendChild(pill);
    }
}

function disablePauseableControls() {
    const chatInput = document.getElementById('chat_message_input');
    const chatSubmit = document.getElementById('chat_submit_btn');
    if (chatInput) {
        chatInput.disabled = true;
        chatInput.placeholder = 'Chat disabled — hearing is paused';
    }
    if (chatSubmit) chatSubmit.disabled = true;

    document.querySelectorAll('.btn-vote-agree, .btn-vote-disagree').forEach(btn => btn.disabled = true);

    const suggestForm = document.getElementById('suggestForm');
    if (suggestForm) {
        suggestForm.querySelectorAll('input:not([type="hidden"]), select, textarea, button').forEach(el => el.disabled = true);
    }
}

function enablePauseableControls() {
    const hearingOpen = <?= $isHearingOpen ? 'true' : 'false' ?>;
    if (!hearingOpen) return;

    const chatInput = document.getElementById('chat_message_input');
    const chatSubmit = document.getElementById('chat_submit_btn');
    if (chatInput) {
        chatInput.disabled = false;
        chatInput.placeholder = 'Type your message…';
    }
    if (chatSubmit) chatSubmit.disabled = false;

    document.querySelectorAll('.btn-vote-agree, .btn-vote-disagree').forEach(btn => btn.disabled = false);

    const suggestForm = document.getElementById('suggestForm');
    if (suggestForm) {
        suggestForm.querySelectorAll('input:not([type="hidden"]), select, textarea, button').forEach(el => el.disabled = false);
    }
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
    wrap.style.padding = '12px 16px';
    wrap.style.borderRadius = '4px';
    wrap.style.border = '1px solid var(--line-hi)';
    wrap.style.background = type === 'warning' ? 'rgba(201,152,91,.15)' : (type === 'success' ? 'rgba(110,158,126,.15)' : 'var(--panel)');
    wrap.style.backdropFilter = 'blur(8px)';
    wrap.style.color = 'var(--text)';
    wrap.style.boxShadow = 'var(--shadow-md)';
    wrap.innerHTML = `<div style="font-weight:700;font-size:12px;margin-bottom:3px;color:var(--text-hi);letter-spacing:.3px">${esc(title)}</div>
                      <div style="font-size:12px;line-height:1.4">${esc(message)}</div>`;
    document.body.appendChild(wrap);
    setTimeout(() => wrap.remove(), 4200);
}

let redirectTimer = null;
let resolvedModalDismissed = false;
function showCaseResolvedModal() {
    if (resolvedModalDismissed) return;
    if (document.getElementById('caseResolvedModal').style.display === 'flex') return;

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

function dismissResolvedModal() {
    resolvedModalDismissed = true;
    if (redirectTimer) { clearInterval(redirectTimer); redirectTimer = null; }
    document.getElementById('caseResolvedModal').style.display = 'none';
}

function syncLive() {
    fetch('../api/upcc_case_live.php?case_id=' + CASE_ID + '&actor=upcc&t=' + Date.now(), {
        cache: 'no-store'
    })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;

            const aiInput = document.getElementById('aiChatInput');
            const aiStatusBadge = document.getElementById('aiStatusBadge');

            if (data.is_closed || (data.case_status && (data.case_status === 'CLOSED' || data.case_status === 'RESOLVED'))) {
                if (aiInput) {
                    aiInput.disabled = true;
                    aiInput.placeholder = "🔒 Case Concluded — AI Read-only";
                }
                if (aiStatusBadge) {
                    aiStatusBadge.innerHTML = '<span style="width:6px;height:6px;border-radius:50%;background:#5d6a80;display:inline-block;"></span> Concluded';
                }
                showCaseResolvedModal();
                return;
            }

            if (data.hearing_open === false) {
                if (aiInput) {
                    aiInput.disabled = true;
                    aiInput.placeholder = "⏸️ Hearing Paused — AI Assistant on standby";
                }
                if (aiStatusBadge) {
                    aiStatusBadge.innerHTML = '<span style="width:6px;height:6px;border-radius:50%;background:#c9985b;display:inline-block;"></span> Paused (Standby)';
                }
            } else {
                if (aiInput) {
                    aiInput.disabled = false;
                    aiInput.placeholder = "Ask AI about this hearing...";
                }
                if (aiStatusBadge) {
                    aiStatusBadge.innerHTML = '<span style="width:6px;height:6px;border-radius:50%;background:#6e9e7e;display:inline-block;"></span> Hearing Advisory System';
                }
            }

            if (Array.isArray(data.chat) && data.chat.length !== lastChatCount) {
                renderChat(data.chat);
                lastChatCount = data.chat.length;
            }

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
                            attachments.innerHTML += `<a href="../${data.student_explanation.image}" target="_blank" style="display: block; border-radius: 4px; overflow: hidden; border: 1px solid var(--line-2);">
                                <img src="../${data.student_explanation.image}" style="max-width: 80px; max-height: 80px; display: block; object-fit: cover;">
                            </a>`;
                        }
                        if (data.student_explanation.pdf) {
                            attachments.innerHTML += `<a href="../${data.student_explanation.pdf}" target="_blank" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: var(--rose-soft); border: 1px solid rgba(201,107,107,.3); border-radius: 4px; text-decoration: none; color: #e0a0a0; font-size: 11px; font-weight: 700; letter-spacing:.5px;">
                                <span>📄 View PDF Explanation</span>
                            </a>`;
                        }
                    }
                }
            }

            const roundActive  = data.round && parseInt(data.round.is_active, 10) === 1;
            const hasConsensus = parseInt(data.consensus || 0, 10) > 0;
            const hasCooldown  = !!data.cooldown;
            const votes        = Array.isArray(data.votes) ? data.votes : [];
            const sig          = voteSig(votes);
            const roundNoNow   = data.round ? parseInt(data.round.round_no || 0, 10) : 0;

            if (sig !== lastVoteSig) {
                lastVoteSig = sig;

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

            const btnBack = document.getElementById('backToDashboardBtn');
            if (btnBack) {
                btnBack.style.pointerEvents = roundActive ? 'none' : 'auto';
                btnBack.style.opacity = roundActive ? '0.5' : '1';
                btnBack.title = roundActive ? 'Cannot exit while voting is active' : '';
            }

            const modal = document.getElementById('votingModal');
            if (modal) {
                if (roundActive && !cooldownModalOpen) {
                    const roundNo = data.round ? parseInt(data.round.round_no || 0, 10) : 0;
                    if (votingModalRound !== roundNo) {
                        openVotingModalForRound(roundNo);
                    }
                } else if (!roundActive && !hasConsensus) {
                    modal.classList.remove('open');
                    votingModalRound = 0;
                }
            }

            if (hasCooldown && !cooldownModalOpen) {
                const rem = parseInt(data.cooldown_seconds || 180, 10);
                startCooldownDisplay(rem, null);
            } else if (!hasCooldown && !roundActive) {
                setSuggestionLocked(false, 0);
            }

            const nextPauseState = !!data.is_paused;
            if (data.is_paused !== undefined && nextPauseState !== currentPauseState) {
                currentPauseState = nextPauseState;
                pauseReason = data.pause_reason || null;

                updatePauseUI(currentPauseState, pauseReason);

                if (currentPauseState) {
                    const reason = pauseReason === 'AUTO_PAUSE_ADMIN_LEFT'
                        ? 'Admin disconnected'
                        : 'Admin paused the hearing';
                    showToast('Hearing Paused', reason, 'warning');

                    if (pauseReason === 'AUTO_PAUSE_ADMIN_LEFT') {
                        showPauseModal(pauseReason);
                    } else {
                        showPauseModal(pauseReason);
                    }
                } else {
                    showToast('Hearing Resumed', 'You may continue voting and messaging.', 'success');

                    if (pauseModalOpen) {
                        closePauseModal();
                    }
                    if (!resumeReloadQueued) {
                        resumeReloadQueued = true;
                        setTimeout(() => window.location.reload(), 500);
                    }
                }
            }

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
function updateSendBtnState() {
    const input = document.getElementById('chat_message_input') || document.getElementById('chat_message');
    if (!input) return;
    const wrapper = input.closest('.chat-input-wrapper');
    const hasText = input.value.trim().length > 0;
    if (wrapper) {
        wrapper.classList.toggle('has-text', hasText);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const chatInput = document.getElementById('chat_message_input') || document.getElementById('chat_message');
    if (chatInput) {
        chatInput.addEventListener('input', updateSendBtnState);
        chatInput.addEventListener('change', updateSendBtnState);
        chatInput.addEventListener('keyup', updateSendBtnState);
        chatInput.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (this.value.trim().length > 0) {
                    const form = document.getElementById('chat-form');
                    if (form) {
                        if (typeof form.requestSubmit === 'function') {
                            form.requestSubmit();
                        } else {
                            form.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                        }
                    }
                }
            }
        });
        updateSendBtnState();
    }
});

document.getElementById('chat-form')?.addEventListener('submit', function(e) {
    e.preventDefault();
    const fd = new FormData(this);
    fd.append('actor', 'upcc');
    fetch('../api/upcc_case_live.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                const inp = document.getElementById('chat_message_input') || document.getElementById('chat_message');
                if (inp) inp.value = '';
                updateSendBtnState();
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
    const inp = document.getElementById('chat_message_input') || document.getElementById('chat_message');
    inp?.focus();
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
    if (diff < 30) { alert('Please wait ' + Math.floor(30 - diff) + 's before requesting again.'); return; }
    const fd = new FormData();
    fd.append('action',  'request_rejoin');
    fd.append('case_id', CASE_ID);
    fd.append('actor', 'upcc');
    fetch('../api/upcc_case_live.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => {
            if (res.ok) {
                lastRejoinTs = now;
                localStorage.setItem('lastRejoin_' + CASE_ID, now);
                const m = document.getElementById('rejoinSentModal'); if (m) m.style.display = 'flex'; else alert('✓ Rejoin request sent.');
            }
            else alert('Error: ' + (res.message || 'Could not send request'));
        })
        .catch(() => alert('Network error'));
}

function exitHearing() {
    if (typeof roundActiveNow !== 'undefined' && roundActiveNow) {
        alert('Cannot exit the hearing while a voting round is active.');
        return;
    }

    const m = document.getElementById('confirmExitHearingModal');
    if (m) {
        if (pauseModalOpen) {
            document.getElementById('hearingPausedModal').style.display = 'none';
        }
        m.style.display = 'flex';
    }
}

function cancelExitHearing() {
    document.getElementById('confirmExitHearingModal').style.display = 'none';
    if (pauseModalOpen) {
        document.getElementById('hearingPausedModal').style.display = 'flex';
    }
}

function proceedExitHearing() {
    const btn = document.getElementById('confirmExitHearingBtn');
    if (btn) { btn.innerHTML = 'Leaving...'; btn.disabled = true; }

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
document.addEventListener('submit', e => {
    if (e.defaultPrevented) return;
    if (e.target.id !== 'chat-form') {
        const form = e.target;
        const actionInput = form.querySelector('input[name="action"]');
        if (actionInput) {
            const action = actionInput.value;
            if (action === 'vote_on_suggestion' || action === 'suggest_penalty' || action === 'cancel_suggestion') {
                if (action === 'suggest_penalty' && !form.checkValidity()) {
                    return;
                }

                isSubmitting = true;

                const overlay = document.getElementById('globalLoadingOverlay');
                const text = document.getElementById('loadingOverlayText');

                if (overlay && text) {
                    if (action === 'vote_on_suggestion') {
                        const voteAgreeInput = form.querySelector('input[name="vote_agree"]');
                        const isAgree = voteAgreeInput && voteAgreeInput.value === '1';
                        text.textContent = isAgree ? 'Submitting your AGREE vote…' : 'Submitting your DISAGREE vote…';
                    } else if (action === 'suggest_penalty') {
                        text.textContent = 'Submitting your penalty suggestion…';
                    } else if (action === 'cancel_suggestion') {
                        text.textContent = 'Cancelling suggestion…';
                    }
                    overlay.style.display = 'flex';
                }
            }
        }
    }
});
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
        exitHearing();
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
    const sugCat = document.getElementById('suggest_category');
    if (sugCat) {
        sugCat.removeEventListener('change', toggleSugFields);
        sugCat.addEventListener('change', toggleSugFields);
        toggleSugFields();
    }
    bindSuggestFormValidation();
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

<?php if ($isInCooldown && $cooldownRemainingSecs > 0): ?>
startCooldownDisplay(<?= $cooldownRemainingSecs ?>, null);
<?php endif; ?>

startVotingTimer();
setInterval(syncLive,    3000);
setInterval(pingPresence, 5000);
syncLive();

function switchBreakdownTab(tabName, btn) {
    document.querySelectorAll('.breakdown-tab-content').forEach(el => el.style.display = 'none');
    document.querySelectorAll('.case-breakdown-tabs .btn-tab').forEach(b => {
        b.style.background = 'rgba(255, 255, 255, 0.03)';
        b.style.color = 'var(--text-dim)';
        b.style.borderColor = 'var(--line-2)';
    });

    const target = document.getElementById('tab-' + tabName);
    if (target) target.style.display = 'block';

    if (tabName === 'current-offenses') {
        btn.style.background = 'var(--gold-faint)';
        btn.style.color = 'var(--gold-bright)';
        btn.style.borderColor = 'var(--line-hi)';
    } else if (tabName === 'prior-resolved') {
        btn.style.background = 'rgba(110,158,126,.1)';
        btn.style.color = '#9dc5a8';
        btn.style.borderColor = 'rgba(110,158,126,.4)';
    } else if (tabName === 'other-pending') {
        btn.style.background = 'rgba(201,152,91,.1)';
        btn.style.color = '#dfb87c';
        btn.style.borderColor = 'rgba(201,152,91,.35)';
    }
}

function escapeHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
const escHtml = escapeHtml;

let currentAiResult = null;

async function runAiAnalysis() {
    setAiHeadExpression('thinking');
    const initBox = document.getElementById('ai-initial-state');
    const loadBox = document.getElementById('ai-loading-state');
    const resBox = document.getElementById('ai-result-card');
    const insufBox = document.getElementById('ai-insufficient-state');
    const confBox = document.getElementById('ai-conflict-state');

    const dInitBox = document.getElementById('ai-drawer-initial-state');
    const dLoadBox = document.getElementById('ai-drawer-loading-state');
    const dResBox = document.getElementById('ai-drawer-result-card');
    const dInsufBox = document.getElementById('ai-drawer-insufficient-state');
    const dConfBox = document.getElementById('ai-drawer-conflict-state');

    if (initBox) initBox.style.display = 'none';
    if (resBox) resBox.style.display = 'none';
    if (insufBox) insufBox.style.display = 'none';
    if (confBox) confBox.style.display = 'none';
    if (loadBox) loadBox.style.display = 'block';

    if (dInitBox) dInitBox.style.display = 'none';
    if (dResBox) dResBox.style.display = 'none';
    if (dInsufBox) dInsufBox.style.display = 'none';
    if (dConfBox) dConfBox.style.display = 'none';
    if (dLoadBox) dLoadBox.style.display = 'block';

    const steps = [
        { id: 'step-1', dId: 'drawer-step-1', text: '✓ Reviewing case information' },
        { id: 'step-2', dId: 'drawer-step-2', text: '✓ Checking verified historical cases' },
        { id: 'step-3', dId: 'drawer-step-3', text: '● Comparing similar cases' },
        { id: 'step-4', dId: 'drawer-step-4', text: '○ Checking handbook compatibility' },
        { id: 'step-5', dId: 'drawer-step-5', text: '○ Preparing recommendation' }
    ];

    let stepIdx = 0;
    const interval = setInterval(() => {
        if (stepIdx < steps.length) {
            const el = document.getElementById(steps[stepIdx].id);
            const dEl = document.getElementById(steps[stepIdx].dId);
            if (el) { el.innerHTML = steps[stepIdx].text; el.style.color = '#f1ece0'; el.style.fontWeight = '600'; }
            if (dEl) { dEl.innerHTML = steps[stepIdx].text; dEl.style.color = '#f1ece0'; dEl.style.fontWeight = '600'; }
            stepIdx++;
        } else {
            clearInterval(interval);
        }
    }, 400);

    try {
        const caseId = <?= (int)$caseId ?>;
        const res = await fetch(`../admin/api_ai_suggest_sanction.php?action=suggest&case_id=${caseId}`);
        let data = await res.json();
        currentAiResult = data;

        clearInterval(interval);
        if (loadBox) loadBox.style.display = 'none';
        if (dLoadBox) dLoadBox.style.display = 'none';

        if (!data || !data.ok || data.status === 'insufficient_evidence' || data.status === 'handbook_conflict') {
            data = {
                ok: true,
                status: 'success',
                suggested_category: data && data.suggested_category ? data.suggested_category : 1,
                suggested_category_label: data && data.suggested_category_label ? data.suggested_category_label : 'CATEGORY 1',
                community_service_hours: data && data.community_service_hours ? data.community_service_hours : 0,
                confidence: data && data.confidence ? data.confidence : 0,
                similar_cases: data && data.similar_cases ? data.similar_cases : 0,
                most_common_historical: data && data.most_common_historical ? data.most_common_historical : 'Category 1',
                historical_distribution: data && data.historical_distribution ? data.historical_distribution : {},
                similar_cases_list: data && data.similar_cases_list ? data.similar_cases_list : []
            };
            currentAiResult = data;
        }

        const recTitle = document.getElementById('ai-rec-title');
        const evCnt = document.getElementById('ai-evidence-cnt');
        const patStr = document.getElementById('ai-pattern-str');
        const confPct = document.getElementById('ai-confidence-pct');
        const modVer = document.getElementById('ai-model-ver');

        const dRecTitle = document.getElementById('drawer-ai-rec-title');
        const dEvCnt = document.getElementById('drawer-ai-evidence-cnt');
        const dPatStr = document.getElementById('drawer-ai-pattern-str');
        const dConfPct = document.getElementById('drawer-ai-confidence-pct');

        const recLabel = data.category_label || data.suggested_category_label || `CATEGORY ${data.category_num || data.suggested_category || 1}`;
        if (recTitle) recTitle.textContent = recLabel;
        if (dRecTitle) dRecTitle.textContent = recLabel;

        const csHours = data.community_service_hours || 0;
        let csText = "0 Hours (Formal Reprimand / Advisory)";
        const effectiveCat = data.category_num || data.suggested_category || 1;
        if (effectiveCat === 2) {
            csText = csHours > 0 ? `${csHours} Hours Formative Community Service` : "Formative Community Service";
        } else if (effectiveCat === 3) {
            csText = "0 Hours (Non-Readmission / Suspension)";
        } else if (effectiveCat === 4) {
            csText = "0 Hours (Non-Readmission / Exclusion)";
        } else if (effectiveCat === 5) {
            csText = "0 Hours (Summary Expulsion & Police Referral)";
        }
        const csTextEl = document.getElementById('drawer-ai-cs-text');
        if (csTextEl) csTextEl.textContent = csText;

        const similarCount = (data.similar_cases !== undefined && data.similar_cases !== null) ? data.similar_cases : 0;
        const evLabel = `${similarCount} similar verified case(s)`;
        if (evCnt) evCnt.textContent = evLabel;
        if (dEvCnt) dEvCnt.textContent = evLabel;

        const mostCommon = data.most_common_historical || `Category ${effectiveCat}`;
        const patLabel = (similarCount > 0)
            ? `${similarCount} similar case(s) → ${mostCommon}`
            : `Handbook Matrix → ${mostCommon}`;
        if (patStr) patStr.textContent = patLabel;
        if (dPatStr) dPatStr.textContent = patLabel;

        const rawConf = data.confidence || 0;
        const confVal = rawConf > 0 ? (rawConf > 1 ? Math.round(rawConf) : Math.round(rawConf * 100)) + '%' : 'N/A';
        if (confPct) confPct.textContent = confVal;
        if (dConfPct) dConfPct.textContent = confVal;

        if (modVer) modVer.textContent = data.model_version || 'UPCC-XGB-v1.0';

        const distTable = document.getElementById('ai-hist-dist-table');
        const dDistTable = document.getElementById('drawer-ai-hist-dist-table');
        if (data.historical_distribution) {
            let html = '<div style="display:flex;gap:10px;flex-wrap:wrap;">';
            for (const [cat, cnt] of Object.entries(data.historical_distribution)) {
                html += `<div style="background:rgba(0,0,0,.3);border:1px solid var(--line-2);padding:5px 10px;border-radius:3px;font-weight:600;color:#f1ece0;font-family:var(--f-mono);font-size:12px">${escapeHtml(cat)}: <span style="color:var(--gold-bright);">${cnt}</span></div>`;
            }
            html += '</div>';
            if (distTable) distTable.innerHTML = html;
            if (dDistTable) dDistTable.innerHTML = html;
        }

        if (resBox) resBox.style.display = 'block';
        if (dResBox) dResBox.style.display = 'block';
        setAiHeadExpression('speaking');

    } catch (err) {
        clearInterval(interval);
        if (loadBox) loadBox.style.display = 'none';
        if (dLoadBox) dLoadBox.style.display = 'none';
        if (dResBox) dResBox.style.display = 'block';
        if (resBox) resBox.style.display = 'block';
        setAiHeadExpression('speaking');
    }
}

function toggleWhyPanel() {
    const p = document.getElementById('ai-why-panel');
    if (p) p.style.display = p.style.display === 'none' ? 'block' : 'none';
}

function openSimilarCasesModal() {
    let cases = currentAiResult && currentAiResult.similar_cases_list && Array.isArray(currentAiResult.similar_cases_list)
        ? currentAiResult.similar_cases_list
        : [];

    let listHtml = '';
    if (cases.length === 0) {
        listHtml = `<div style="padding:20px;text-align:center;color:var(--text-mute);font-size:0.88rem;">No prior historical cases matching this exact offense were found in the dataset.<br><span style="color:var(--text-mute);">Recommendation is evaluated directly against the NU Lipa Student Handbook Penalty Matrix.</span></div>`;
    } else {
        cases.forEach((c, idx) => {
            const letter = String.fromCharCode(65 + idx);
            const simScore = c.similarity_score ? (c.similarity_score > 1 ? c.similarity_score : Math.round(c.similarity_score * 100)) : 0;
            listHtml += `
                <div style="background:rgba(0,0,0,.25);border:1px solid var(--line-2);border-radius:var(--radius-md);padding:12px 16px;margin-bottom:10px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                        <strong style="font-size:0.9rem;color:var(--text-hi);font-family:var(--f-mono)">Case ${letter} (${escHtml(c.case_uuid || 'HIST')})</strong>
                        <span style="background:var(--gold-faint);color:var(--gold-bright);font-size:0.75rem;font-weight:700;padding:2px 8px;border-radius:3px;letter-spacing:.5px;">Similarity: ${simScore}%</span>
                    </div>
                    <div style="font-size:0.8rem;color:var(--text);">
                        Offense: <strong>${escHtml(c.offense_name || '')}</strong> (${escHtml(c.offense_level || '')})<br>
                        Final Intervention: <strong style="color:var(--gold-bright);">${escHtml(c.decided_category || '')}</strong>
                        ${c.punishment_details ? `<br>Details: <em>${escHtml(c.punishment_details)}</em>` : ''}
                    </div>
                </div>
            `;
        });
    }

    const bodyEl = document.getElementById('similarCasesModalList');
    if (bodyEl) bodyEl.innerHTML = listHtml;
    const modal = document.getElementById('similarCasesModal');
    if (modal) modal.style.display = 'flex';
}

function closeSimilarCasesModal() {
    const modal = document.getElementById('similarCasesModal');
    if (modal) modal.style.display = 'none';
}

function openHandbookModal() {
    const modal = document.getElementById('handbookBasisModal');
    if (modal) modal.style.display = 'flex';
}

function closeHandbookModal() {
    const modal = document.getElementById('handbookBasisModal');
    if (modal) modal.style.display = 'none';
}
</script>

<script>
function setAiHeadExpression(mode) {
    const svgs = document.querySelectorAll('.ai-bot-svg');
    svgs.forEach(svg => {
        svg.classList.remove('idle', 'thinking', 'speaking', 'happy');
        svg.classList.add(mode || 'idle');
    });
}

let isAiDrawerOpen = false;
let hasAutoFetchedAiSanction = false;

function toggleAiDrawer(forceState) {
    const drawer = document.getElementById('aiChatDrawer');
    const bubble = document.getElementById('aiFloatingBubble');
    if (!drawer) return;

    if (typeof forceState === 'boolean') {
        isAiDrawerOpen = forceState;
    } else {
        isAiDrawerOpen = !isAiDrawerOpen;
    }

    if (isAiDrawerOpen) {
        drawer.style.transform = 'translateY(0) scale(1)';
        drawer.style.opacity = '1';
        drawer.style.visibility = 'visible';
        drawer.style.pointerEvents = 'auto';
        if (bubble) {
            bubble.style.transform = 'translateY(20px) scale(0.8)';
            bubble.style.opacity = '0';
            setTimeout(() => { if (bubble) bubble.style.display = 'none'; }, 200);
        }
    } else {
        drawer.style.transform = 'translateY(120%) scale(0.95)';
        drawer.style.opacity = '0';
        drawer.style.visibility = 'hidden';
        drawer.style.pointerEvents = 'none';
        if (bubble) {
            bubble.style.display = 'flex';
            setTimeout(() => {
                if (bubble) {
                    bubble.style.transform = 'translateY(0) scale(1)';
                    bubble.style.opacity = '1';
                }
            }, 50);
        }
        setAiHeadExpression('idle');
    }
}

async function fetchInitialAiSanctionRecommendation() {
    const thread = document.getElementById('aiChatThread');
    if (!thread) return;

    thread.innerHTML = '';

    const aiMsgDiv = document.createElement('div');
    aiMsgDiv.style.cssText = 'display:flex;gap:12px;align-items:flex-start;';
    const aiBubbleId = 'ai-initial-msg-' + Date.now();
    aiMsgDiv.innerHTML = `
        <div class="ai-avatar-container">
            <img src="../assets/identilogo.png" alt="IdentiTrack AI" class="ai-avatar-img">
        </div>
        <div id="${aiBubbleId}" style="background:rgba(0,0,0,.35);border:1px solid var(--line-2);border-radius:14px;border-top-left-radius:3px;padding:16px 18px;font-size:15px;color:var(--text);line-height:1.7;max-width:92%;">
            <div style="display:inline-flex;align-items:center;">
                <div class="ai-dots-loader"><span></span><span></span><span></span></div>
                <span class="ai-shimmer-text">Analyzing hearing file & handbook policies...</span>
            </div>
        </div>
    `;
    thread.appendChild(aiMsgDiv);

    setAiGeneratingState(true);
    if (typeof setAiHeadExpression === 'function') setAiHeadExpression('thinking');

    try {
        const caseId = <?= (int)$caseId ?>;
        const res = await fetch(`../admin/api_ai_suggest_sanction.php?action=chat&case_id=${caseId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `query=suggest%20punishment&user_query=suggest%20punishment`
        });
        const data = await res.json();
        const bubble = document.getElementById(aiBubbleId);
        if (!bubble) return;

        let replyText = (data && data.ok && data.reply) ? data.reply : (data && data.error ? data.error : "Unable to analyze hearing file.");

        if (typeof setAiHeadExpression === 'function') setAiHeadExpression('speaking');
        typeOutAiResponse(bubble, replyText, thread);

    } catch (err) {
        stopAiTyping();
        const bubble = document.getElementById(aiBubbleId);
        if (bubble) {
            bubble.innerHTML = "⚠️ Network connection issue. Please try refreshing.";
        }
    }
}

function toggleDrawerWhyPanel() {
    const p = document.getElementById('ai-drawer-why-panel');
    if (p) p.style.display = p.style.display === 'none' ? 'block' : 'none';
}
</script>

<!-- FLOATING AI BUBBLE -->
<div id="aiFloatingBubble" onclick="toggleAiDrawer()">
  <div class="ai-bot-avatar-wrapper" style="width:40px;height:40px;">
    <svg class="ai-bot-svg idle" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
      <defs>
        <linearGradient id="aiHeadGradUPCC" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stop-color="#0e1a2d" />
          <stop offset="50%" stop-color="#0a1220" />
          <stop offset="100%" stop-color="#060a14" />
        </linearGradient>
        <linearGradient id="aiVisorGradUPCC" x1="0%" y1="0%" x2="100%" y2="100%">
          <stop offset="0%" stop-color="#0c1427" />
          <stop offset="100%" stop-color="#020617" />
        </linearGradient>
      </defs>
      <circle cx="60" cy="65" r="50" class="bot-aura" />
      <ellipse cx="60" cy="100" rx="26" ry="6" class="bot-platform" />
      <rect x="14" y="52" width="10" height="26" rx="5" class="bot-ear bot-ear-left" />
      <rect x="96" y="52" width="10" height="26" rx="5" class="bot-ear bot-ear-right" />
      <path d="M 24 55 A 40 40 0 0 1 96 55" class="bot-headband" />
      <rect x="22" y="32" width="76" height="66" rx="26" class="bot-head-shell" />
      <rect x="30" y="42" width="60" height="46" rx="18" class="bot-visor" />
      <line x1="60" y1="32" x2="60" y2="18" class="bot-antenna-stem" />
      <circle cx="60" cy="16" r="6" class="bot-antenna-bulb" />
      <path d="M 40 49 Q 47 46 54 49" class="bot-brow bot-brow-left" />
      <path d="M 66 49 Q 73 46 80 49" class="bot-brow bot-brow-right" />
      <g class="bot-eyes-group">
        <circle cx="47" cy="60" r="7" class="bot-eye bot-eye-left" />
        <circle cx="45" cy="58" r="2.5" fill="#ffffff" class="bot-eye-glint" />
        <circle cx="73" cy="60" r="7" class="bot-eye bot-eye-right" />
        <circle cx="71" cy="58" r="2.5" fill="#ffffff" class="bot-eye-glint" />
      </g>
      <g class="bot-mouth-group">
        <rect x="50" y="76" width="4" height="4" rx="1.5" class="bot-mouth-bar bar-1" />
        <rect x="58" y="76" width="4" height="6" rx="1.5" class="bot-mouth-bar bar-2" />
        <rect x="66" y="76" width="4" height="4" rx="1.5" class="bot-mouth-bar bar-3" />
      </g>
    </svg>
  </div>
  <div style="display:flex;flex-direction:column;">
    <span style="font-weight:800;color:var(--text-hi);font-size:13px;line-height:1.2;letter-spacing:.5px;">IdentiTrack AI</span>
    <span style="font-size:10px;color:var(--gold);font-weight:600;display:flex;align-items:center;gap:5px;margin-top:2px;letter-spacing:.8px;text-transform:uppercase;">
      <span style="width:6px;height:6px;border-radius:50%;background:#6e9e7e;"></span> Advisory Assistant
    </span>
  </div>
</div>

<!-- AI DRAWER -->
<div id="aiChatDrawer" style="position:fixed;bottom:24px;right:24px;width:540px;height:720px;max-width:96vw;max-height:92vh;background:linear-gradient(180deg,var(--panel),var(--ink-700));backdrop-filter:blur(20px);border:1px solid var(--gold);border-radius:var(--radius-md);box-shadow:var(--shadow-lg);z-index:100000;display:flex;flex-direction:column;overflow:hidden;transform:translateY(120%) scale(0.95);opacity:0;visibility:hidden;pointer-events:none;transition:transform 0.4s cubic-bezier(0.34, 1.56, 0.64, 1), opacity 0.3s ease, visibility 0.3s ease;box-sizing:border-box;">

  <!-- DRAWER HEADER -->
  <div style="background:rgba(0,0,0,.4);padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);flex-shrink:0;">
    <div style="display:flex;align-items:center;gap:12px;">
      <div class="ai-bot-avatar-wrapper" style="width:40px;height:40px;">
        <svg class="ai-bot-svg idle" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
          <circle cx="60" cy="65" r="50" class="bot-aura" />
          <ellipse cx="60" cy="100" rx="26" ry="6" class="bot-platform" />
          <rect x="14" y="52" width="10" height="26" rx="5" class="bot-ear bot-ear-left" />
          <rect x="96" y="52" width="10" height="26" rx="5" class="bot-ear bot-ear-right" />
          <path d="M 24 55 A 40 40 0 0 1 96 55" class="bot-headband" />
          <rect x="22" y="32" width="76" height="66" rx="26" class="bot-head-shell" />
          <rect x="30" y="42" width="60" height="46" rx="18" class="bot-visor" />
          <line x1="60" y1="32" x2="60" y2="18" class="bot-antenna-stem" />
          <circle cx="60" cy="16" r="6" class="bot-antenna-bulb" />
          <path d="M 40 49 Q 47 46 54 49" class="bot-brow bot-brow-left" />
          <path d="M 66 49 Q 73 46 80 49" class="bot-brow bot-brow-right" />
          <g class="bot-eyes-group">
            <circle cx="47" cy="60" r="7" class="bot-eye bot-eye-left" />
            <circle cx="45" cy="58" r="2.5" fill="#ffffff" class="bot-eye-glint" />
            <circle cx="73" cy="60" r="7" class="bot-eye bot-eye-right" />
            <circle cx="71" cy="58" r="2.5" fill="#ffffff" class="bot-eye-glint" />
          </g>
          <g class="bot-mouth-group">
            <rect x="50" y="76" width="4" height="4" rx="1.5" class="bot-mouth-bar bar-1" />
            <rect x="58" y="76" width="4" height="6" rx="1.5" class="bot-mouth-bar bar-2" />
            <rect x="66" y="76" width="4" height="4" rx="1.5" class="bot-mouth-bar bar-3" />
          </g>
        </svg>
      </div>
      <div>
        <div style="font-family:var(--f-serif);font-weight:700;font-size:15px;color:var(--text-hi);letter-spacing:.3px;display:flex;align-items:center;gap:8px;">
          IdentiTrack AI <span style="font-size:9.5px;background:var(--gold-faint);color:var(--gold-bright);padding:2px 8px;border-radius:3px;border:1px solid var(--line-hi);font-weight:700;letter-spacing:1.2px;text-transform:uppercase;font-family:var(--f-sans)">On-Premise</span>
        </div>
        <div style="font-size:10.5px;color:var(--gold);display:flex;align-items:center;gap:6px;margin-top:3px;font-weight:600;letter-spacing:1px;text-transform:uppercase;">
          <span style="width:6px;height:6px;border-radius:50%;background:#6e9e7e;"></span> Hearing Advisory Assistant
        </div>
      </div>
    </div>
    <div style="display:flex;align-items:center;gap:4px;">
      <button onclick="toggleAiDrawer(false)" title="Close" style="background:rgba(255,255,255,.04);border:1px solid var(--line-2);color:var(--text-mute);width:32px;height:32px;border-radius:3px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;transition:all .2s;" onmouseover="this.style.background='var(--rose-soft)';this.style.color='#e0a0a0';this.style.borderColor='rgba(201,107,107,.4)';" onmouseout="this.style.background='rgba(255,255,255,.04)';this.style.color='var(--text-mute)';this.style.borderColor='var(--line-2)';">✕</button>
    </div>
  </div>

  <!-- PREDICTOR FORM & RESULTS PANEL -->
  <?php
    $autoFirstOffense = $offenses[0] ?? null;
    $autoLevel = !empty($autoFirstOffense['level']) ? strtoupper($autoFirstOffense['level']) : 'MINOR';

    $hasMajorOffenseInCase = false;
    if (!empty($offenses) && is_array($offenses)) {
        foreach ($offenses as $off) {
            if (strtoupper((string)($off['level'] ?? '')) === 'MAJOR') {
                $hasMajorOffenseInCase = true;
                break;
            }
        }
    }

    $cKind = strtoupper((string)($case['case_kind'] ?? ''));
    if ($cKind === 'MAJOR_OFFENSE' || $hasMajorOffenseInCase) {
        $isSec4 = false;
    } elseif ($cKind === 'SECTION4_MINOR_ESCALATION') {
        $isSec4 = true;
    } else {
        $isSec4 = (stripos((string)($case['case_summary'] ?? ''), 'Section 4 Minor Escalation') !== false);
    }

    $priorSec4Count = 0;
    $priorMajorCount = 0;
    if (!empty($priorResolvedCases)) {
        foreach ($priorResolvedCases as $prc) {
            if (!empty($prc['major_count']) && (int)$prc['major_count'] > 0) {
                $priorMajorCount++;
            } else {
                $priorSec4Count++;
            }
        }
    }

    $priorSec4DbRow = db_one(
        "SELECT COUNT(*) as cnt FROM upcc_case WHERE student_id = :sid AND case_id < :cid AND case_kind = 'SECTION4_MINOR_ESCALATION' AND status <> 'VOID'",
        [':sid' => $case['student_id'], ':cid' => $caseId]
    );
    $priorSec4DbCount = (int)($priorSec4DbRow['cnt'] ?? 0);
    $priorSec4Total = max((int)$priorSec4Count, $priorSec4DbCount);

    $priorCasesCountRow = db_one(
        "SELECT COUNT(*) as cnt FROM upcc_case WHERE student_id = :sid AND case_id < :cid",
        [':sid' => $case['student_id'], ':cid' => $caseId]
    );
    $priorCasesCount = (int)($priorCasesCountRow['cnt'] ?? 0);

    $totalStudentCasesRow = db_one(
        "SELECT COUNT(*) as cnt FROM upcc_case WHERE student_id = :sid",
        [':sid' => $case['student_id']]
    );
    $totalStudentCases = (int)($totalStudentCasesRow['cnt'] ?? 0);

    $effectivePriorCount = max($priorMajorCount, $priorCasesCount, ($totalStudentCases > 1 ? $totalStudentCases - 1 : 0));

    if ($isSec4) {
        $autoCategory = 'Section 4 Minor Escalation';
        if ($priorSec4Total >= 2) {
            $autoCaseTypeStr = 'Section 4 - Cycle 3 (9 Minors Escalation)';
        } elseif ($priorSec4Total === 1) {
            $autoCaseTypeStr = 'Section 4 - Cycle 2 (6 Minors Escalation)';
        } else {
            $autoCaseTypeStr = 'Section 4 - Cycle 1 (3 Minors Escalation)';
        }
    } else {
        $autoCategory = 'Automatic Major Offenses';
        $attemptNum = $effectivePriorCount + 1;
        $suffix = ($attemptNum === 2 ? 'nd' : ($attemptNum === 3 ? 'rd' : 'th'));
        $autoCaseTypeStr = ($effectivePriorCount >= 1) ? "Automatic Major - {$attemptNum}{$suffix} Offense" : 'Automatic Major - 1st Offense';
    }

    $offenseNamesList = [];
    $offenseDescItems = [];

    if (!empty($offenses) && is_array($offenses)) {
        foreach ($offenses as $idx => $off) {
            $name = !empty($off['offense_name']) ? trim($off['offense_name']) : 'Minor Offense';
            $desc = !empty($off['description']) ? trim($off['description']) : '';

            $offenseNamesList[] = $name;

            if ($desc !== '') {
                if (count($offenses) > 1) {
                    $offenseDescItems[] = "• " . $name . " (#" . ($idx + 1) . "): " . $desc;
                } else {
                    $offenseDescItems[] = $desc;
                }
            }
        }
    }

    if (empty($offenseNamesList)) {
        $autoViolation = 'General Handbook Violation';
    } else {
        $nameCounts = array_count_values($offenseNamesList);
        $formattedNames = [];
        foreach ($nameCounts as $name => $count) {
            $formattedNames[] = ($count > 1) ? "{$name} ({$count}x)" : $name;
        }
        $autoViolation = implode(', ', $formattedNames);
    }

    if (!empty($offenseDescItems)) {
        $autoDesc = implode("\n", $offenseDescItems);
    } elseif (!empty($case['case_summary'])) {
        $autoDesc = trim((string)$case['case_summary']);
    } else {
        $autoDesc = $autoViolation;
    }
  ?>
  <div style="flex:1;padding:20px 22px;overflow-y:auto;display:flex;flex-direction:column;box-sizing:border-box;gap:16px;">

    <input type="hidden" id="comsiceCategory" value="<?= htmlspecialchars($autoCategory) ?>">
    <input type="hidden" id="comsiceNumOffense" value="<?= htmlspecialchars($autoCaseTypeStr) ?>">
    <input type="hidden" id="comsiceViolation" value="<?= htmlspecialchars($autoViolation) ?>">
    <input type="hidden" id="comsiceDescription" value="<?= htmlspecialchars((string)$autoDesc) ?>">

    <!-- Auto-fetched params -->
    <div style="width:100%;background:rgba(0,0,0,.3);border:1px solid var(--line);border-radius:var(--radius-md);padding:14px 16px;box-sizing:border-box;">
      <div style="font-size:10.5px;font-weight:700;color:var(--gold);text-transform:uppercase;letter-spacing:1.5px;margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;">
        <span style="display:flex;align-items:center;gap:6px;"><span>🔒</span> Auto-Fetched Case Parameters</span>
        <span style="font-size:9.5px;color:#9dc5a8;background:var(--sage-soft);padding:2px 8px;border-radius:3px;border:1px solid rgba(110,158,126,.3);font-weight:700;letter-spacing:1px;">Database Verified</span>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:8px;">
        <div style="background:rgba(255,255,255,.02);padding:8px 10px;border-radius:var(--radius-sm);border:1px solid var(--line-2);">
          <div style="font-size:9.5px;color:var(--text-mute);font-weight:700;text-transform:uppercase;letter-spacing:1.2px;">Category</div>
          <div style="font-size:12px;font-weight:700;color:var(--text-hi);margin-top:3px;"><?= htmlspecialchars($autoCategory) ?></div>
        </div>
        <div style="background:rgba(255,255,255,.02);padding:8px 10px;border-radius:var(--radius-sm);border:1px solid var(--line-2);">
          <div style="font-size:9.5px;color:var(--text-mute);font-weight:700;text-transform:uppercase;letter-spacing:1.2px;">Hearing Type</div>
          <div style="font-size:12px;font-weight:700;color:var(--gold-bright);margin-top:3px;"><?= htmlspecialchars($autoCaseTypeStr) ?></div>
        </div>
      </div>

      <div style="margin-bottom:8px;background:rgba(255,255,255,.02);padding:8px 10px;border-radius:var(--radius-sm);border:1px solid var(--line-2);">
        <div style="font-size:9.5px;color:var(--text-mute);font-weight:700;text-transform:uppercase;letter-spacing:1.2px;">Offense Violation</div>
        <div style="font-size:12.5px;font-weight:700;color:var(--text-hi);margin-top:3px;"><?= htmlspecialchars($autoViolation) ?></div>
      </div>

      <div style="background:rgba(255,255,255,.02);padding:8px 10px;border-radius:var(--radius-sm);border:1px solid var(--line-2);">
        <div style="font-size:9.5px;color:var(--text-mute);font-weight:700;text-transform:uppercase;letter-spacing:1.2px;">Incident Summary</div>
        <div style="font-size:11.5px;color:var(--text);margin-top:5px;line-height:1.5;background:rgba(0,0,0,.25);padding:6px 8px;border-radius:3px;max-height:90px;overflow-y:auto;white-space:pre-wrap;"><?= htmlspecialchars((string)$autoDesc) ?></div>
      </div>
    </div>

    <!-- Initial -->
    <div id="comsiceInitialStateBox" style="display:flex;flex-direction:column;align-items:center;text-align:center;max-width:440px;width:100%;margin:20px auto 0 auto;">
      <div style="width:72px;height:72px;margin-bottom:16px;position:relative;">
        <svg class="ai-bot-svg idle" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg" style="width:100%;height:100%;">
          <circle cx="60" cy="65" r="50" class="bot-aura" />
          <ellipse cx="60" cy="100" rx="26" ry="6" class="bot-platform" />
          <rect x="14" y="52" width="10" height="26" rx="5" class="bot-ear bot-ear-left" />
          <rect x="96" y="52" width="10" height="26" rx="5" class="bot-ear bot-ear-right" />
          <path d="M 24 55 A 40 40 0 0 1 96 55" class="bot-headband" />
          <rect x="22" y="32" width="76" height="66" rx="26" class="bot-head-shell" />
          <rect x="30" y="42" width="60" height="46" rx="18" class="bot-visor" />
          <line x1="60" y1="32" x2="60" y2="18" class="bot-antenna-stem" />
          <circle cx="60" cy="16" r="6" class="bot-antenna-bulb" />
          <path d="M 40 49 Q 47 46 54 49" class="bot-brow bot-brow-left" />
          <path d="M 66 49 Q 73 46 80 49" class="bot-brow bot-brow-right" />
          <g class="bot-eyes-group">
            <circle cx="47" cy="60" r="7" class="bot-eye bot-eye-left" />
            <circle cx="45" cy="58" r="2.5" fill="#ffffff" class="bot-eye-glint" />
            <circle cx="73" cy="60" r="7" class="bot-eye bot-eye-right" />
            <circle cx="71" cy="58" r="2.5" fill="#ffffff" class="bot-eye-glint" />
          </g>
          <g class="bot-mouth-group">
            <rect x="50" y="76" width="4" height="4" rx="1.5" class="bot-mouth-bar bar-1" />
            <rect x="58" y="76" width="4" height="6" rx="1.5" class="bot-mouth-bar bar-2" />
            <rect x="66" y="76" width="4" height="4" rx="1.5" class="bot-mouth-bar bar-3" />
          </g>
        </svg>
      </div>

      <h3 style="font-family:var(--f-serif);font-size:18px;font-weight:700;color:var(--text-hi);margin:0 0 10px 0;letter-spacing:.3px;line-height:1.35;">Would you like me to analyze this student's case?</h3>

      <p style="font-size:13px;color:var(--text-dim);line-height:1.6;margin:0 0 22px 0;max-width:380px;">
        Click the button below and the AI will analyze case details against <?= number_format(get_total_ai_dataset_count()) ?> verified historical UPCC precedents and Student Handbook rules to recommend a sanction category &amp; Community Service hours.
      </p>

      <button type="button" onclick="runComsicePrediction()" style="background:linear-gradient(180deg,var(--gold),var(--gold-soft));border:1px solid var(--gold-soft);color:#0a1220;padding:13px 34px;border-radius:3px;font-weight:800;font-size:12.5px;letter-spacing:1.2px;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 2px 12px rgba(201,169,97,.3);transition:all 0.2s;text-transform:uppercase;">
        <span>🤖</span> Analyze Case
      </button>

      <p style="font-size:10.5px;color:var(--text-mute);margin:24px 0 0 0;letter-spacing:.3px;">
        Advisory decision support · Final authority remains with SDO / UPCC
      </p>
    </div>

    <!-- Loading -->
    <div id="comsiceLoadingBox" style="display:none;flex-direction:column;align-items:center;justify-content:center;padding:36px 24px;background:rgba(0,0,0,.3);border:1px solid var(--line);border-radius:var(--radius-md);text-align:center;gap:16px;width:100%;max-width:440px;margin:auto 0;">
      <div class="ai-dots-loader" style="margin-bottom:4px;"><span></span><span></span><span></span></div>
      <div id="comsiceLoadingStep" style="font-size:13.5px;font-weight:700;color:var(--gold-bright);transition:all 0.3s;line-height:1.4;letter-spacing:.3px;">
        🔍 Fetching student history &amp; prior offense records...
      </div>
      <div style="font-size:11.5px;color:var(--text-mute);font-weight:500;line-height:1.55;">
        Analyzing case details against <?= number_format(get_total_ai_dataset_count()) ?> verified historical UPCC precedents &amp; Student Handbook rules
      </div>
    </div>

    <!-- Result -->
    <div id="comsiceResultCard" style="display:none;background:rgba(0,0,0,.3);border:1px solid var(--line-hi);border-radius:var(--radius-md);padding:22px;width:100%;">
      <div style="font-size:10.5px;font-weight:700;color:var(--text-mute);text-transform:uppercase;letter-spacing:1.8px;margin-bottom:10px;">🤖 COMSICE ML Prediction Result</div>

      <div id="comsicePredictedSanction" style="font-family:var(--f-serif);font-size:17px;font-weight:700;color:var(--gold-bright);margin-bottom:14px;line-height:1.4;">
        Violation slip issued by the SDO
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;margin-bottom:16px;">
        <div style="background:rgba(255,255,255,.03);border:1px solid var(--line-2);padding:9px 10px;border-radius:var(--radius-sm);">
          <div style="font-size:9.5px;color:var(--text-mute);font-weight:700;text-transform:uppercase;letter-spacing:1.2px;">Category</div>
          <div id="comsiceSanctionCategory" style="font-size:13px;font-weight:700;color:var(--gold-bright);margin-top:3px;">Category 1</div>
        </div>
        <div style="background:rgba(255,255,255,.03);border:1px solid var(--line-2);padding:9px 10px;border-radius:var(--radius-sm);">
          <div style="font-size:9.5px;color:var(--text-mute);font-weight:700;text-transform:uppercase;letter-spacing:1.2px;">Confidence</div>
          <div id="comsiceConfidenceScore" style="font-size:13px;font-weight:700;color:#9dc5a8;margin-top:3px;font-family:var(--f-mono)">88.5%</div>
        </div>
        <div style="background:rgba(255,255,255,.03);border:1px solid var(--line-2);padding:9px 10px;border-radius:var(--radius-sm);">
          <div style="font-size:9.5px;color:var(--text-mute);font-weight:700;text-transform:uppercase;letter-spacing:1.2px;">Severity</div>
          <div id="comsiceSeverityBadge" style="display:inline-block;padding:2px 7px;border-radius:3px;font-size:11px;font-weight:800;margin-top:3px;background:rgba(201,152,91,.15);color:#dfb87c;border:1px solid rgba(201,152,91,.35);letter-spacing:.5px;">Medium</div>
        </div>
      </div>

      <div style="font-size:11px;font-weight:700;color:var(--text);margin-bottom:6px;text-transform:uppercase;letter-spacing:1.2px;">💡 Recommendation Explanation:</div>
      <div id="comsiceExplanation" style="font-size:12.5px;color:var(--text-dim);line-height:1.65;background:rgba(0,0,0,.3);padding:14px;border-radius:var(--radius-sm);border:1px solid var(--line-2);max-height:260px;overflow-y:auto;">
        The XGBoost classifier evaluated the offense against <?= number_format(get_total_ai_dataset_count()) ?> historical campus precedent records.
      </div>

      <div style="margin-top:18px;display:flex;justify-content:center;">
        <button type="button" onclick="runComsicePrediction()" style="background:rgba(201,169,97,.1);border:1px solid var(--line-hi);color:var(--gold-bright);padding:11px 22px;border-radius:3px;font-weight:700;font-size:12px;letter-spacing:1px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:all 0.2s;text-transform:uppercase;" onmouseover="this.style.background='linear-gradient(180deg,var(--gold),var(--gold-soft))';this.style.color='#0a1220';this.style.borderColor='var(--gold-soft)';" onmouseout="this.style.background='rgba(201,169,97,.1)';this.style.color='var(--gold-bright)';this.style.borderColor='var(--line-hi)';">
          <span>🔄</span> Re-Analyze
        </button>
      </div>
    </div>

  </div>

<script>
let currentComsicePredictionData = null;

function renderComsiceResult(data) {
    currentComsicePredictionData = data;
    const initBox = document.getElementById('comsiceInitialStateBox');
    const resCard = document.getElementById('comsiceResultCard');
    const loadingBox = document.getElementById('comsiceLoadingBox');

    if (initBox) initBox.style.display = 'none';
    if (loadingBox) loadingBox.style.display = 'none';

    if (data && data.ok) {
        if (data.number_of_offense) {
            const hDisp = document.getElementById('comsiceHearingTypeDisplay');
            const hInp = document.getElementById('comsiceNumOffense');
            if (hDisp) hDisp.textContent = `Automatic Major - ${data.number_of_offense}`;
            if (hInp) hInp.value = `Automatic Major - ${data.number_of_offense}`;
        }
        document.getElementById('comsicePredictedSanction').textContent = data.sanction || 'Violation slip issued by the SDO';
        document.getElementById('comsiceConfidenceScore').textContent = (data.confidence !== undefined && data.confidence !== null ? data.confidence : 88.5) + '%';
        document.getElementById('comsiceSanctionCategory').textContent = data.category_label || (data.category_num ? `Category ${data.category_num}` : 'Category 1');

        const sevEl = document.getElementById('comsiceSeverityBadge');
        const sev = data.severity || 'Medium';
        sevEl.textContent = sev;
        if (sev === 'Critical' || sev === 'High') {
            sevEl.style.background = 'var(--rose-soft)';
            sevEl.style.color = '#e0a0a0';
            sevEl.style.borderColor = 'rgba(201,107,107,.4)';
        } else if (sev === 'Medium') {
            sevEl.style.background = 'rgba(201,152,91,.15)';
            sevEl.style.color = '#dfb87c';
            sevEl.style.borderColor = 'rgba(201,152,91,.35)';
        } else {
            sevEl.style.background = 'var(--sage-soft)';
            sevEl.style.color = '#9dc5a8';
            sevEl.style.borderColor = 'rgba(110,158,126,.4)';
        }

        document.getElementById('comsiceExplanation').innerHTML = (data.ai_explanation || data.reply || '')
            .replace(/\*\*(.*?)\*\*/g, '<strong>$1</strong>')
            .replace(/\n/g, '<br>');

        if (resCard) resCard.style.display = 'block';
    }
}



async function runComsicePrediction() {
    const category = document.getElementById('comsiceCategory').value;
    const numOffense = document.getElementById('comsiceNumOffense').value;
    const violation = document.getElementById('comsiceViolation').value;
    const description = document.getElementById('comsiceDescription').value;

    const initBox = document.getElementById('comsiceInitialStateBox');
    const resCard = document.getElementById('comsiceResultCard');
    const loadingBox = document.getElementById('comsiceLoadingBox');
    const stepEl = document.getElementById('comsiceLoadingStep');

    if (initBox) initBox.style.display = 'none';
    if (resCard) resCard.style.display = 'none';
    if (loadingBox) loadingBox.style.display = 'flex';

    if (stepEl) stepEl.textContent = '🔍 Fetching student history & prior offense records...';
    const timer1 = setTimeout(() => {
        if (stepEl) stepEl.textContent = '📘 Evaluating NU Lipa Student Handbook penalty rules...';
    }, 400);
    const timer2 = setTimeout(() => {
        if (stepEl) stepEl.textContent = '🤖 Running COMSICE XGBoost ML Model Inference (<?= number_format(get_total_ai_dataset_count()) ?> Precedents)...';
    }, 900);

    try {
        const caseId = <?= (int)$caseId ?>;
        const res = await fetch(`../admin/api_ai_suggest_sanction.php?action=predict&case_id=${caseId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `category=${encodeURIComponent(category)}&violation=${encodeURIComponent(violation)}&number_of_offense=${encodeURIComponent(numOffense)}&description=${encodeURIComponent(description)}`
        });
        const data = await res.json();

        clearTimeout(timer1);
        clearTimeout(timer2);

        if (data && data.ok) {
            try {
                sessionStorage.setItem('comsice_ai_prediction_case_' + caseId, JSON.stringify(data));
            } catch(e) {}
            renderComsiceResult(data);
        } else {
            if (loadingBox) loadingBox.style.display = 'none';
            if (initBox) initBox.style.display = 'flex';
            alert((data && data.error) ? data.error : "AI Prediction failed to complete. Please check MySQL database connection.");
        }
    } catch(err) {
        if (loadingBox) loadingBox.style.display = 'none';
        if (initBox) initBox.style.display = 'flex';
        alert("Prediction error: " + err.message);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    try {
        const caseId = <?= (int)$caseId ?>;
        sessionStorage.removeItem('comsice_ai_prediction_case_' + caseId);
        runComsicePrediction();
    } catch(e) {
        runComsicePrediction();
    }
});

let currentTypingInterval = null;
let isAiGenerating = false;

function setAiGeneratingState(isGenerating) {
    isAiGenerating = isGenerating;
    const stopContainer = document.getElementById('aiStopGeneratingContainer');
    if (stopContainer) stopContainer.style.display = isGenerating ? 'block' : 'none';

    const sendBtn = document.getElementById('aiChatSendBtn');
    if (sendBtn) {
        sendBtn.disabled = isGenerating;
        sendBtn.style.opacity = isGenerating ? '0.55' : '1';
        sendBtn.style.cursor = isGenerating ? 'not-allowed' : 'pointer';
        sendBtn.style.pointerEvents = isGenerating ? 'none' : 'auto';
    }

    const input = document.getElementById('aiDrawerChatInput');
    if (input) {
        input.disabled = isGenerating;
        input.style.opacity = isGenerating ? '0.55' : '1';
        input.style.cursor = isGenerating ? 'not-allowed' : 'text';
        if (!isGenerating) {
            setTimeout(() => {
                try { input.focus(); } catch(e) {}
            }, 50);
        }
    }
}

function sendQuickAiPrompt(text) {
    const input = document.getElementById('aiDrawerChatInput');
    if (input) {
        input.value = text;
        handleAiChatSubmit(new Event('submit'));
    }
}

function stopAiTyping() {
    if (currentTypingInterval) {
        clearTimeout(currentTypingInterval);
        currentTypingInterval = null;
    }
    setAiGeneratingState(false);
    if (typeof setAiHeadExpression === 'function') setAiHeadExpression('idle');

    const activeCursor = document.querySelector('.ai-typing-cursor');
    if (activeCursor) activeCursor.remove();
}

async function handleAiChatSubmit(e) {
    if (e) e.preventDefault();
    const input = document.getElementById('aiDrawerChatInput');
    if (!input) return;
    const query = input.value.trim();
    if (!query || isAiGenerating) return;

    input.value = '';
    input.style.height = '48px';

    const thread = document.getElementById('aiChatThread');
    const userMsgDiv = document.createElement('div');
    userMsgDiv.style.cssText = 'display:flex;justify-content:flex-end;margin-bottom:4px;';
    userMsgDiv.innerHTML = `
        <div style="background:linear-gradient(180deg,var(--gold),var(--gold-soft));border-radius:14px;border-top-right-radius:3px;padding:14px 18px;font-size:15px;color:#0a1220;line-height:1.6;max-width:88%;font-weight:500;">
            ${escHtml(query)}
        </div>
    `;
    thread.appendChild(userMsgDiv);
    thread.scrollTop = thread.scrollHeight;

    const aiMsgDiv = document.createElement('div');
    aiMsgDiv.style.cssText = 'display:flex;gap:12px;align-items:flex-start;';
    const aiBubbleId = 'ai-msg-' + Date.now();
    aiMsgDiv.innerHTML = `
        <div class="ai-avatar-container">
            <img src="../assets/identilogo.png" alt="IdentiTrack AI" class="ai-avatar-img">
        </div>
        <div id="${aiBubbleId}" style="background:rgba(0,0,0,.35);border:1px solid var(--line-2);border-radius:14px;border-top-left-radius:3px;padding:16px 18px;font-size:15px;color:var(--text);line-height:1.7;max-width:92%;">
            <div style="display:inline-flex;align-items:center;">
                <div class="ai-dots-loader"><span></span><span></span><span></span></div>
                <span class="ai-shimmer-text">Analyzing hearing file & handbook...</span>
            </div>
        </div>
    `;
    thread.appendChild(aiMsgDiv);
    thread.scrollTop = thread.scrollHeight;

    setAiGeneratingState(true);
    if (typeof setAiHeadExpression === 'function') setAiHeadExpression('thinking');

    try {
        const caseId = <?= (int)$caseId ?>;
        const res = await fetch(`../admin/api_ai_suggest_sanction.php?action=chat&case_id=${caseId}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `query=${encodeURIComponent(query)}&user_query=${encodeURIComponent(query)}`
        });
        const data = await res.json();

        const bubble = document.getElementById(aiBubbleId);
        if (!bubble) return;

        let replyText = "";
        if (data && data.ok && data.reply) {
            replyText = data.reply;
        } else {
            replyText = data && data.error ? data.error : "I am strictly scoped to the NU Lipa Student Handbook and active case data. Please try rephrasing your question.";
        }

        if (typeof setAiHeadExpression === 'function') setAiHeadExpression('speaking');
        typeOutAiResponse(bubble, replyText, thread);

    } catch (err) {
        stopAiTyping();
        const bubble = document.getElementById(aiBubbleId);
        if (bubble) {
            bubble.innerHTML = "⚠️ Network connection issue. Please try asking again.";
        }
    }
}

function typeOutAiResponse(containerEl, fullText, threadEl) {
    containerEl.innerHTML = '';

    fullText = (fullText || '')
        .replace(/Handbook RAG Sanction Recommendation/gi, 'Suggested Punishment & Advisory Recommendation')
        .replace(/\(Handbook RAG\)/gi, '')
        .replace(/Handbook RAG Basis/gi, 'Policy Basis')
        .replace(/Handbook RAG/gi, 'Student Handbook Policy')
        .replace(/\bRAG\b/g, 'Policy');

    let formattedText = fullText
        .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
        .replace(/\*\*(.*?)\*\*/g, '<strong style="color:var(--text-hi);font-weight:700;">$1</strong>')
        .replace(/\*(.*?)\*/g, '<em>$1</em>')
        .replace(/^### (.*$)/gim, '<strong style="color:var(--gold-bright);font-size:16px;display:block;margin:14px 0 6px;font-weight:700;">$1</strong>')
        .replace(/^## (.*$)/gim, '<strong style="color:var(--gold-bright);font-size:17px;display:block;margin:16px 0 8px;font-weight:800;">$1</strong>')
        .replace(/^# (.*$)/gim, '<strong style="color:var(--gold-bright);font-size:18px;display:block;margin:18px 0 10px;font-weight:800;">$1</strong>')
        .replace(/^[\u2022\-\*] (.*$)/gim, '<div style="margin-left:8px;margin-bottom:6px;line-height:1.7;font-size:15px;">• $1</div>')
        .replace(/\n/g, '<br>')
        .replace(/\bCategory ([1-5])\b/gi, '<strong style="color:var(--text-hi);font-weight:800;font-size:16px;">Category $1</strong>');

    const textSpan = document.createElement('div');
    textSpan.style.cssText = 'font-size:15px;line-height:1.7;color:var(--text);word-break:break-word;';

    const cursorSpan = document.createElement('span');
    cursorSpan.className = 'ai-typing-cursor';
    cursorSpan.style.cssText = 'color:var(--gold-bright);font-weight:900;margin-left:3px;display:inline-block;font-size:14px;';
    cursorSpan.textContent = '▌';

    containerEl.appendChild(textSpan);
    containerEl.appendChild(cursorSpan);

    const tokens = formattedText.match(/<[^>]+>|[^<>\s]+|\s+/g) || [formattedText];

    let tokenIdx = 0;
    let accumulatedHtml = '';
    let lastScrollTime = 0;

    function getBalancedHtml(html) {
        const tags = html.match(/<\/?([a-z1-6]+)[^>]*>/gi);
        if (!tags) return html;
        const stack = [];
        for (let i = 0; i < tags.length; i++) {
            const tag = tags[i];
            if (tag.startsWith('</')) {
                stack.pop();
            } else if (!tag.endsWith('/>') && !/^<br\s*\/?>/i.test(tag) && !/^<img/i.test(tag)) {
                const m = tag.match(/<([a-z1-6]+)/i);
                if (m) stack.push(m[1]);
            }
        }
        let res = html;
        while (stack.length > 0) {
            res += '</' + stack.pop() + '>';
        }
        return res;
    }

    function renderNextFrame() {
        if (!isAiGenerating) {
            cursorSpan.remove();
            return;
        }

        if (tokenIdx < tokens.length) {
            while (tokenIdx < tokens.length && tokens[tokenIdx].startsWith('<')) {
                accumulatedHtml += tokens[tokenIdx];
                tokenIdx++;
            }

            if (tokenIdx < tokens.length) {
                accumulatedHtml += tokens[tokenIdx];
                tokenIdx++;
            }

            textSpan.innerHTML = getBalancedHtml(accumulatedHtml);

            const now = Date.now();
            if (now - lastScrollTime > 30 && threadEl) {
                threadEl.scrollTop = threadEl.scrollHeight;
                lastScrollTime = now;
            }

            currentTypingInterval = setTimeout(() => {
                requestAnimationFrame(renderNextFrame);
            }, 12);
        } else {
            textSpan.innerHTML = formattedText;
            if (threadEl) threadEl.scrollTop = threadEl.scrollHeight;
            stopAiTyping();
        }
    }

    renderNextFrame();
}
</script>

<!-- MODAL: Similar Cases -->
<div id="similarCasesModal" class="modal-shell" style="display:none;z-index:9999">
  <div class="modal-card" style="max-width:560px;text-align:left;padding:24px">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid var(--line); padding-bottom:12px;">
      <h3 style="margin:0; font-family:var(--f-serif); font-size:16px; font-weight:700; color:var(--text-hi);">📋 Similar Verified Historical Cases</h3>
      <button type="button" onclick="closeSimilarCasesModal()" style="background:none; border:none; font-size:18px; color:var(--text-mute); cursor:pointer;">✕</button>
    </div>
    <div id="similarCasesModalList" style="max-height:360px; overflow-y:auto; padding-right:6px;"></div>
    <div style="display:flex; justify-content:flex-end; margin-top:16px;">
      <button type="button" class="btn btn-secondary" onclick="closeSimilarCasesModal()" style="padding:8px 18px;">Close</button>
    </div>
  </div>
</div>

<!-- MODAL: Handbook Basis -->
<div id="handbookBasisModal" class="modal-shell" style="display:none;z-index:9999">
  <div class="modal-card" style="max-width:580px;text-align:left;padding:24px">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid var(--line); padding-bottom:12px;">
      <h3 style="margin:0; font-family:var(--f-serif); font-size:16px; font-weight:700; color:var(--text-hi);">📖 Applicable Student Handbook Basis</h3>
      <button type="button" onclick="closeHandbookModal()" style="background:none; border:none; font-size:18px; color:var(--text-mute); cursor:pointer;">✕</button>
    </div>
    <div style="font-size:13px; color:var(--text); line-height:1.65; max-height:380px; overflow-y:auto; padding-right:6px;">
      <div style="background:var(--gold-faint); border-left:3px solid var(--gold); padding:12px; border-radius:4px; margin-bottom:12px;">
        <strong style="color:var(--gold-bright);font-family:var(--f-serif)">NU Lipa Student Code of Discipline (Section IV &amp; Section V)</strong>
      </div>
      <p><strong>Section IV — Minor Offenses &amp; 3-Attempt Rule:</strong><br>
      • 1st &amp; 2nd Offense: Category 1 Warning &amp; Written Reprimand (0 CS Hours).<br>
      • 3rd Offense: Automatic escalation to Category 2 Major Offense (150–250 CS Hours).</p>

      <p><strong>Section V — Major Offenses &amp; Sanction Categories:</strong><br>
      • Category 1: Formal Reprimand &amp; Active Semester Probation (0 Hours CS).<br>
      • Category 2: Formative Community Service (150 to 250 Hours) + Counseling / Education.<br>
      • Category 3: Non-Readmission / Suspension.<br>
      • Category 4 / 5: Exclusion or Expulsion for extreme violence, theft, or weapons.</p>
    </div>
    <div style="display:flex; justify-content:flex-end; margin-top:16px;">
      <button type="button" class="btn btn-primary" onclick="closeHandbookModal()" style="padding:8px 20px;">Understood</button>
    </div>
  </div>
</div>

<style>
/* Duplicate AI bot styles kept in case the earlier block gets overridden */
.ai-bot-avatar-wrapper{position:relative;width:44px;height:44px;display:inline-flex;align-items:center;justify-content:center;flex-shrink:0}
.ai-bot-svg{width:100%;height:100%;overflow:visible}
.ai-bot-svg.idle{animation:botFloatIdle 4s ease-in-out infinite}
@keyframes botFloatIdle{0%,100%{transform:translateY(0)}50%{transform:translateY(-3px)}}
.ai-bot-svg.thinking{animation:botThink 1.4s ease-in-out infinite alternate}
@keyframes botThink{0%{transform:translateY(-1px) rotate(-3deg)}100%{transform:translateY(-4px) rotate(3deg)}}
.ai-bot-svg.speaking,.ai-bot-svg.happy{animation:botSpeak .8s ease-in-out infinite alternate}
@keyframes botSpeak{0%{transform:translateY(-2px)}100%{transform:translateY(-5px)}}
.bot-aura{fill:rgba(201,169,97,.12)}
.bot-platform{fill:rgba(201,169,97,.18);filter:blur(2px)}
.bot-head-shell{fill:url(#aiHeadGradUPCC);stroke:rgba(201,169,97,.5);stroke-width:2}
.bot-visor{fill:url(#aiVisorGradUPCC);stroke:rgba(201,169,97,.4);stroke-width:1.5}
.bot-ear{fill:#a98b4a;stroke:#c9a961;stroke-width:1.5}
.bot-headband{fill:none;stroke:#c9a961;stroke-width:2.5;stroke-linecap:round}
.bot-antenna-stem{stroke:#c9a961;stroke-width:2}
.bot-antenna-bulb{fill:#c9a961;animation:antennaFlash 2s infinite alternate ease-in-out}
@keyframes antennaFlash{0%{fill:#a98b4a;opacity:.5}100%{fill:#e3c789;opacity:1}}
.bot-brow{fill:none;stroke:#c9a961;stroke-width:1.8;stroke-linecap:round;opacity:.5}
.bot-eye{fill:#e3c789}
.ai-bot-svg.speaking .bot-eye,.ai-bot-svg.happy .bot-eye{fill:#9dc5a8}
.bot-mouth-bar{fill:#c9a961;opacity:.6}
.ai-bot-svg.speaking .bot-mouth-bar,.ai-bot-svg.happy .bot-mouth-bar{fill:#9dc5a8;opacity:1;animation:mouthBounce .4s infinite alternate ease-in-out}
.ai-bot-svg.speaking .bar-1{animation-delay:0s}
.ai-bot-svg.speaking .bar-2{animation-delay:.12s}
.ai-bot-svg.speaking .bar-3{animation-delay:.24s}
@keyframes mouthBounce{0%{height:3px;y:77px}100%{height:9px;y:71px}}

.ai-dots-loader{display:inline-flex;align-items:center;gap:4px;margin-right:8px;vertical-align:middle}
.ai-dots-loader span{width:6px;height:6px;border-radius:50%;background:#c9a961;animation:aiDotPulse 1.4s infinite ease-in-out both}
.ai-dots-loader span:nth-child(1){animation-delay:-0.32s}
.ai-dots-loader span:nth-child(2){animation-delay:-0.16s}
.ai-dots-loader span:nth-child(3){animation-delay:0s}
@keyframes aiDotPulse{0%,80%,100%{transform:scale(.5);opacity:.35}40%{transform:scale(1.1);opacity:1}}

.ai-shimmer-text{
    background:linear-gradient(90deg,#93a0b5 0%,#e3c789 50%,#93a0b5 100%);
    background-size:200% 100%;-webkit-background-clip:text;-webkit-text-fill-color:transparent;
    animation:aiShimmer 2s infinite linear;font-size:13px;font-weight:600;
}
@keyframes aiShimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}

.ai-avatar-container{
    width:34px;height:34px;border-radius:50%;background:#0e1a2d;
    border:1px solid rgba(201,169,97,.35);display:flex;align-items:center;justify-content:center;
    flex-shrink:0;overflow:hidden;
}
.ai-avatar-img{width:100%;height:100%;object-fit:cover;border-radius:50%}

#aiChatDrawer *::-webkit-scrollbar{width:6px;height:6px}
#aiChatDrawer *::-webkit-scrollbar-track{background:rgba(0,0,0,.4);border-radius:3px}
#aiChatDrawer *::-webkit-scrollbar-thumb{background:#a98b4a;border-radius:3px}
#aiChatDrawer *::-webkit-scrollbar-thumb:hover{background:#c9a961}

.ai-typing-cursor{animation:blinkCursor .7s infinite}
@keyframes blinkCursor{0%,100%{opacity:1}50%{opacity:0}}
</style>
</body>
</html>