<?php
require_once __DIR__ . '/../database/database.php';
require_admin();
ensure_hearing_workflow_schema();

$admin        = admin_current();
$activeSidebar = 'upcc';
$case_id      = (int)($_GET['id'] ?? 0);
if (!$case_id) { header('Location: upcc_cases.php'); exit; }

$JITSI_DOMAIN = 'meet.jit.si';

// ── Fetch core data ───────────────────────────────────────────────────────
$case = db_one("SELECT uc.*,
           CONCAT(s.student_fn,' ',s.student_ln) AS student_name,
           s.student_fn, s.student_ln,
           s.year_level, s.section, s.program, s.school,
           s.student_email, s.phone_number, s.home_address,
           d.dept_name AS assigned_dept_name
    FROM upcc_case uc
    JOIN student s ON s.student_id = uc.student_id
    LEFT JOIN departments d ON d.dept_id = uc.assigned_department_id
    WHERE uc.case_id = :id", [':id' => $case_id]);
if (!$case) { header('Location: upcc_cases.php'); exit; }

$offenses = db_all("SELECT o.*, ot.code, ot.name AS offense_name, ot.level, ot.major_category,
           ot.intervention_first, ot.intervention_second
    FROM upcc_case_offense uco
    JOIN offense o ON o.offense_id = uco.offense_id
    JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
    WHERE uco.case_id = :id ORDER BY o.date_committed ASC", [':id' => $case_id]);

$departments = db_all("SELECT dept_id, dept_name FROM departments WHERE is_active = 1 ORDER BY dept_name ASC");
$defaultDeptId = (int)($case['assigned_department_id'] ?? 0);
// If the case already has an assigned department, keep it even when that department
// currently has no active staff. Only pick a default department when none is assigned.
 $deptWithStaff = db_one(
    "SELECT d.dept_id
     FROM departments d
     JOIN upcc_user u ON u.department_id = d.dept_id AND u.is_active = 1
     WHERE d.is_active = 1
     GROUP BY d.dept_id
     ORDER BY d.dept_name ASC
     LIMIT 1"
  );
if ($defaultDeptId === 0) {
  $defaultDeptId = (int)($deptWithStaff['dept_id'] ?? ($departments[0]['dept_id'] ?? 0));
}
$initialDeptId = $defaultDeptId;
$initialDeptMembers = $initialDeptId > 0
  ? db_all(
    "SELECT upcc_id, full_name, role
     FROM upcc_user
     WHERE department_id = :dept AND is_active = 1
     ORDER BY full_name",
    [':dept' => $initialDeptId]
  )
  : [];

$allActiveMembers = db_all("
    SELECT u.upcc_id, u.full_name, u.role, u.department_id, d.dept_name, u.is_active
    FROM upcc_user u
    LEFT JOIN departments d ON d.dept_id = u.department_id
    WHERE u.is_active = 1
    ORDER BY u.full_name
");

// ── Helpers ───────────────────────────────────────────────────────────────
function dept_norm(string $v): string { return preg_replace('/[^a-z0-9]+/i', '', strtolower(trim($v))); }
function is_biased_department(array $case, string $deptName): bool {
    $d = dept_norm($deptName);
    if ($d === '') return false;
    foreach ([dept_norm((string)($case['program'] ?? '')), dept_norm((string)($case['school'] ?? ''))] as $t) {
        if ($t !== '' && ($d === $t || str_contains($t, $d) || str_contains($d, $t))) return true;
    }
    return false;
}
function sync_case_panel_members(int $caseId, array $panelIds): void {
    db_exec("DELETE FROM upcc_case_panel_member WHERE case_id = :id", [':id' => $caseId]);
    $seen = [];
    foreach ($panelIds as $pid) {
        $pid = (int)$pid;
        if ($pid <= 0 || isset($seen[$pid])) continue;
        $seen[$pid] = true;
        db_exec("INSERT INTO upcc_case_panel_member (case_id, upcc_id, assigned_at) VALUES (:c, :u, NOW())",
            [':c' => $caseId, ':u' => $pid]);
    }
}
    function panel_members_match_department(int $deptId, array $panelIds): bool {
      $panelIds = array_values(array_unique(array_filter(array_map('intval', $panelIds), static fn($id) => $id > 0)));
      if (empty($panelIds)) {
        return true;
      }

      $allowedRows = db_all(
        "SELECT upcc_id FROM upcc_user WHERE department_id = :dept AND is_active = 1",
        [':dept' => $deptId]
      );
      $allowedIds = array_fill_keys(array_map(static fn($row) => (int)$row['upcc_id'], $allowedRows), true);

      foreach ($panelIds as $panelId) {
        if (!isset($allowedIds[$panelId])) {
          return false;
        }
      }

      return true;
    }
function fmt($dt)  { return $dt ? date('M j, Y  g:i A', strtotime($dt)) : '—'; }
function fmtd($dt) { return $dt ? date('M j, Y', strtotime($dt)) : '—'; }

$selfViewFile = basename(__FILE__);

// ── Panel members ─────────────────────────────────────────────────────────
$assignedPanelIds = array_map(
    static fn($r) => (int)$r['upcc_id'],
    db_all("SELECT upcc_id FROM upcc_case_panel_member WHERE case_id = :id", [':id' => $case_id])
);
if (empty($assignedPanelIds) && !empty($case['assigned_panel_members'])) {
    try { $assignedPanelIds = json_decode($case['assigned_panel_members'], true) ?? []; } catch (Exception $e) {}
}
// Build display data for assigned panel members (preserve ordering)
$assignedPanelNames = [];
if (!empty($assignedPanelIds)) {
  $ids = array_map('intval', $assignedPanelIds);
  $in = implode(',', $ids);
  $rows = db_all("SELECT upcc_id, full_name, role FROM upcc_user WHERE upcc_id IN ($in) AND is_active = 1");
  $byId = [];
  foreach ($rows as $r) { $byId[(int)$r['upcc_id']] = $r; }
  foreach ($ids as $id) {
    if (isset($byId[$id])) {
      $assignedPanelNames[] = ['name' => $byId[$id]['full_name'], 'role' => $byId[$id]['role']];
    }
  }
}
// We'll fetch all active UPCC users for the edit panel later, but for JS we don't need full list anymore
// because we AJAX load members per department.

// ── Category descriptions ─────────────────────────────────────────────────
$categoryDescriptions = [
    1 => 'Probation for the selected number of academic terms with referral for counseling. Any subsequent major offense during probation triggers Suspension or Non-Readmission.',
    2 => "Formative Intervention — any or all of the following:\n• University service\n• Referral for counseling\n• Attendance to lectures in Discipline Education Program\n• Evaluation",
    3 => 'Non-Readmission. The student is not allowed to enroll next term but may finish the current one. Student account will be frozen.',
    4 => 'Exclusion. The student is dropped from the roll immediately upon promulgation. Student account will be frozen.',
    5 => 'Expulsion. The student is permanently disqualified from admission to any higher education institution. Student account will be permanently frozen.',
];

// ── POST actions ──────────────────────────────────────────────────────────
$errMsg = '';
$okMsg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    // ── Update hearing config ──────────────────────────────────────────────
    if ($_POST['action'] === 'update_hearing_config') {
        $dept_id     = (int)($_POST['assigned_department_id'] ?? 0);
        $panel       = isset($_POST['panel_members']) && is_array($_POST['panel_members']) ? $_POST['panel_members'] : [];
      $panelIds    = array_values(array_unique(array_map('intval', $panel)));
        $hearingDate = trim((string)($_POST['hearing_date'] ?? ''));
        $hearingTime = trim((string)($_POST['hearing_time'] ?? ''));
        $validDate   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $hearingDate) === 1;
        $validTime   = preg_match('/^\d{2}:\d{2}$/', $hearingTime) === 1;
        $dept        = $dept_id ? db_one("SELECT dept_id, dept_name FROM departments WHERE dept_id = :id AND is_active = 1", [':id' => $dept_id]) : null;
        if (!$dept)                                              $errMsg = 'Please select a valid department.';
        elseif (empty($panelIds))                                $errMsg = 'Please assign at least one panel member.';
        elseif (!$validDate || !$validTime)                      $errMsg = 'Please select both a hearing date and time.';
        elseif (is_biased_department($case, (string)$dept['dept_name'])) $errMsg = 'Cannot assign a panel from the same department or program as the respondent student.';
        elseif (!$validDate || !$validTime)                     $errMsg = 'Please provide a valid hearing date and time.';
        else {
            db_exec("UPDATE upcc_case SET
                assigned_department_id = :dept, assigned_panel_members = :panel,
                hearing_date = :hd, hearing_time = :ht, hearing_type = 'VOTING',
                status = 'UNDER_INVESTIGATION', hearing_is_open = 0,
                hearing_opened_at = NULL, hearing_closed_at = NULL, hearing_opened_by_admin = NULL,
                hearing_vote_consensus_category = NULL, hearing_vote_consensus_at = NULL,
                updated_at = NOW() WHERE case_id = :id",
          [':dept' => $dept_id, ':panel' => json_encode($panelIds),
                 ':hd' => $hearingDate, ':ht' => $hearingTime . ':00', ':id' => $case_id]);
        sync_case_panel_members($case_id, $panelIds);
            db_exec("DELETE FROM upcc_case_vote_round WHERE case_id = :c", [':c' => $case_id]);
            db_exec("DELETE FROM upcc_case_vote WHERE case_id = :c", [':c' => $case_id]);
            upcc_log_case_activity($case_id, 'ADMIN', (int)$admin['admin_id'], 'HEARING_CONFIG_UPDATED',
          ['department_id' => $dept_id, 'panel_members' => $panelIds, 'hearing_date' => $hearingDate, 'hearing_time' => $hearingTime]);
            
            // Notify panel members
            upcc_send_panel_assignment_email($case_id, $panelIds);
            
            header("Location: {$selfViewFile}?id={$case_id}&msg=config_updated"); exit;
        }
    }

    // ── Start hearing ─────────────────────────────────────────────────────
    if ($_POST['action'] === 'start_hearing') {
        db_exec("UPDATE upcc_case SET hearing_is_open = 1, hearing_is_paused = 0,
                 hearing_opened_at = NOW(), hearing_opened_by_admin = :aid,
                 hearing_closed_at = NULL, updated_at = NOW() WHERE case_id = :id",
            [':aid' => (int)$admin['admin_id'], ':id' => $case_id]);
        upcc_log_case_activity($case_id, 'ADMIN', (int)$admin['admin_id'], 'HEARING_OPENED');
        header("Location: {$selfViewFile}?id={$case_id}&msg=hearing_started"); exit;
    }

    // ── Close hearing ─────────────────────────────────────────────────────
    if ($_POST['action'] === 'close_hearing') {
        db_exec("UPDATE upcc_case SET hearing_is_open = 0, hearing_is_paused = 0, hearing_closed_at = NOW(), updated_at = NOW() WHERE case_id = :id",
            [':id' => $case_id]);
        upcc_log_case_activity($case_id, 'ADMIN', (int)$admin['admin_id'], 'HEARING_CLOSED');
        header("Location: {$selfViewFile}?id={$case_id}&msg=hearing_closed"); exit;
    }

    // ── Cancel consensus & restart voting ─────────────────────────────────
    if ($_POST['action'] === 'cancel_consensus') {
        db_exec("DELETE FROM upcc_case_vote_round WHERE case_id = :c", [':c' => $case_id]);
        db_exec("DELETE FROM upcc_case_vote WHERE case_id = :c", [':c' => $case_id]);
        db_exec("DELETE FROM upcc_suggestion_cooldown WHERE case_id = :c", [':c' => $case_id]);
        db_exec("UPDATE upcc_case SET
                 hearing_vote_consensus_category = NULL, hearing_vote_suggested_details = NULL,
                 hearing_vote_consensus_at = NULL, hearing_vote_suggester_id = NULL,
                 status = 'UNDER_INVESTIGATION', updated_at = NOW() WHERE case_id = :c", [':c' => $case_id]);
        $adminName = htmlspecialchars($admin['full_name'] ?? 'Admin');
        db_exec("INSERT INTO upcc_case_discussion (case_id, message, created_at, updated_at) VALUES (:c, :m, NOW(), NOW())", [
            ':c' => $case_id,
            ':m' => "🔁 Admin {$adminName} cancelled the panel consensus and restarted voting. Panel members, please vote again.",
        ]);
        upcc_log_case_activity($case_id, 'ADMIN', (int)$admin['admin_id'], 'CONSENSUS_CANCELLED',
            ['reason' => $_POST['cancel_reason'] ?? 'Admin requested re-vote']);
        header("Location: {$selfViewFile}?id={$case_id}&msg=consensus_cancelled"); exit;
    }

    // ── Record final decision ─────────────────────────────────────────────
    if ($_POST['action'] === 'resolve_case') {
        $category     = (int)($_POST['decided_category'] ?? 0);
        $decision     = trim($_POST['final_decision']    ?? '');
        $forceResolve = isset($_POST['force_resolve']) && $_POST['force_resolve'] === '1';
        $useSuggested = isset($_POST['use_suggested'])  && $_POST['use_suggested'] === '1';

        $freshRow     = db_one("SELECT hearing_vote_consensus_category, hearing_vote_suggested_details FROM upcc_case WHERE case_id = :id LIMIT 1", [':id' => $case_id]);
        $consensusCat = (int)($freshRow['hearing_vote_consensus_category'] ?? 0);

        if ($useSuggested && $consensusCat > 0) {
            $category = $consensusCat;
            $sd = json_decode((string)($freshRow['hearing_vote_suggested_details'] ?? ''), true) ?: [];
            if (empty($decision)) $decision = $sd['description'] ?? '';
        }

        if (empty($decision)) $decision = "Panel consensus adopted.";

        if ($category >= 1 && $category <= 5) {
            if ($consensusCat <= 0 && !$forceResolve) {
                $errMsg = 'The UPCC panel has not reached a consensus yet. Check "Force final decision" to close the case anyway.';
            } elseif ($consensusCat > 0 && $consensusCat !== $category && !$forceResolve && !$useSuggested) {
                $errMsg = 'Selected category does not match UPCC consensus (' . $consensusCat . '). Use "Use Suggested Penalty" or enable "Force final decision".';
            } else {
                $probationUntil = null;
                $details        = ['description' => $decision];

                if ($category === 1) {
                    $terms = (int)($_POST['cat1_terms'] ?? 3);
                    $details['probation_terms'] = max(1, min(3, $terms));
                    $probationUntil = date('Y-m-d H:i:s', strtotime('+' . ($details['probation_terms'] * 6) . ' months'));

                } elseif ($category === 2) {
                    $details['interventions'] = [];
                    if (isset($_POST['cat2_university_service'])) {
                        $details['interventions'][] = 'University Service';
                        $hrs = trim((string)($_POST['cat2_service_hours'] ?? ''));
                        if ($hrs === 'OTHER' || !in_array($hrs, ['100','200','300','400','500'], true)) {
                          $hrs = trim((string)($_POST['cat2_service_hours_custom'] ?? $hrs));
                        }
                        $details['service_hours'] = is_numeric($hrs) ? (int)$hrs : 0;
                    }
                    if (isset($_POST['cat2_counseling']))   $details['interventions'][] = 'Referral for Counseling';
                    if (isset($_POST['cat2_lectures']))     $details['interventions'][] = 'Attendance to lectures';
                    if (isset($_POST['cat2_evaluation']))   $details['interventions'][] = 'Evaluation';

                } else {
                    // Cat 3/4/5 — punishment details will trigger restrictions in student_account_mode
                    $details['freeze'] = true;
                    // We no longer freeze immediately here so student can login to Accept or Appeal
                }

                $jsonDetails = json_encode($details);
                db_exec("UPDATE upcc_case SET
                         status = 'CLOSED', hearing_is_open = 0, hearing_closed_at = NOW(),
                         decided_category = :cat, final_decision = :dec,
                         resolution_date = NOW(), probation_until = :pu,
                         punishment_details = :pd, updated_at = NOW()
                         WHERE case_id = :id",
                    [':cat' => $category, ':dec' => $decision,
                     ':pu'  => $probationUntil, ':pd' => $jsonDetails, ':id' => $case_id]);

                if ($category === 2 && !empty($details['service_hours'])) {
                    db_exec("INSERT INTO community_service_requirement (student_id, related_case_id, task_name, hours_required, assigned_by, assigned_at, status)
                             VALUES (:sid, :cid, :tn, :hrs, :aid, NOW(), 'PENDING_ACCEPTANCE')",
                        [':sid' => $case['student_id'],
                         ':cid' => $case_id,
                         ':tn'  => 'UPCC Decision — Case #' . $case_id,
                         ':hrs' => $details['service_hours'],
                         ':aid' => (int)$admin['admin_id']]);
                }

                upcc_log_case_activity($case_id, 'ADMIN', (int)$admin['admin_id'], 'FINAL_DECISION_RECORDED', [
                    'category' => $category, 'punishment_details' => $details,
                    'force_resolve' => $forceResolve ? 1 : 0, 'use_suggested' => $useSuggested ? 1 : 0,
                ]);
                header("Location: {$selfViewFile}?id={$case_id}&msg=resolved"); exit;
            }
        } else {
            $errMsg = 'Please select a category (1–5) and enter the final decision narrative.';
        }
    }
}

if (isset($_GET['msg'])) {
    $msgs = [
        'config_updated'      => 'Hearing configuration updated successfully.',
        'resolved'            => 'Case closed and final decision recorded.',
        'hearing_started'     => 'Hearing opened — voting has started.',
        'hearing_closed'      => 'Hearing access closed.',
        'consensus_cancelled' => 'Consensus cancelled. Voting restarted.',
    ];
    $okMsg = $msgs[$_GET['msg']] ?? '';
}

// Refresh case after POST
$case = db_one("SELECT uc.*, CONCAT(s.student_fn,' ',s.student_ln) AS student_name,
           s.student_fn, s.student_ln, s.year_level, s.section, s.program, s.school,
           s.student_email, s.phone_number, s.home_address,
           d.dept_name AS assigned_dept_name
    FROM upcc_case uc
    JOIN student s ON s.student_id = uc.student_id
    LEFT JOIN departments d ON d.dept_id = uc.assigned_department_id
    WHERE uc.case_id = :id", [':id' => $case_id]);

// ── Consensus / voting state ──────────────────────────────────────────────
$consensusCategory     = (int)($case['hearing_vote_consensus_category'] ?? 0);
$isAwaitingAdmin       = $consensusCategory > 0 && (string)($case['status'] ?? '') === 'AWAITING_ADMIN_FINALIZATION';
$postedDecidedCategory = isset($_POST['decided_category']) ? (int)$_POST['decided_category'] : 0;
$postedFinalDecision   = trim($_POST['final_decision'] ?? '');

try {
    $suggestedVoteDetails = json_decode((string)($case['hearing_vote_suggested_details'] ?? ''), true) ?: [];
} catch (Throwable $e) { $suggestedVoteDetails = []; }

$suggestedDescription     = $suggestedVoteDetails['description']   ?? '';
$prefillCat1Terms         = (int)($suggestedVoteDetails['probation_terms'] ?? 3);
$prefillCat2Interventions = (isset($suggestedVoteDetails['interventions']) && is_array($suggestedVoteDetails['interventions']))
                            ? $suggestedVoteDetails['interventions'] : [];
$prefillCat2Hours         = trim((string)($suggestedVoteDetails['service_hours'] ?? ''));

// ── Schema check ──────────────────────────────────────────────────────────
$hasSuggestedByCol = db_one(
    "SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'upcc_case_vote_round' AND COLUMN_NAME = 'suggested_by' LIMIT 1"
) !== null;

// ── Voting rounds ─────────────────────────────────────────────────────────
if ($hasSuggestedByCol) {
    $activeRound = db_one("SELECT r.*, COALESCE(r.suggested_by, uc.hearing_vote_suggester_id,
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
      WHERE r.case_id = :c AND r.is_active = 1 ORDER BY r.round_no DESC LIMIT 1", [':c' => $case_id]);
    $lastRound = db_one("SELECT r.*, COALESCE(r.suggested_by, uc.hearing_vote_suggester_id,
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
      WHERE r.case_id = :c ORDER BY r.round_no DESC LIMIT 1", [':c' => $case_id]);
} else {
    $activeRound = db_one("SELECT r.*, NULL AS suggester_name FROM upcc_case_vote_round r
         WHERE r.case_id = :c AND r.is_active = 1 ORDER BY r.round_no DESC LIMIT 1", [':c' => $case_id]);
    $lastRound = db_one("SELECT r.*, NULL AS suggester_name FROM upcc_case_vote_round r
         WHERE r.case_id = :c ORDER BY r.round_no DESC LIMIT 1", [':c' => $case_id]);
}

$roundNo    = (int)(($activeRound ?? $lastRound)['round_no'] ?? 0);
$roundVotes = $roundNo > 0
    ? db_all($hasSuggestedByCol
        ? "SELECT v.upcc_id, v.vote_category, v.vote_details, v.updated_at, u.full_name, r.suggested_by
           FROM upcc_case_vote v
           LEFT JOIN upcc_user u ON u.upcc_id = v.upcc_id
           LEFT JOIN upcc_case_vote_round r ON r.case_id = v.case_id AND r.round_no = v.round_no
           WHERE v.case_id = :c AND v.round_no = :r ORDER BY v.updated_at ASC"
        : "SELECT v.upcc_id, v.vote_category, v.vote_details, v.updated_at, u.full_name, NULL AS suggested_by
           FROM upcc_case_vote v LEFT JOIN upcc_user u ON u.upcc_id = v.upcc_id
           WHERE v.case_id = :c AND v.round_no = :r ORDER BY v.updated_at ASC",
        [':c' => $case_id, ':r' => $roundNo])
    : [];

$totalPanelMembers = count($assignedPanelIds);
// Voters = all assigned EXCEPT suggester
$suggesterRow = $activeRound ?? $lastRound;
$suggesterId  = (int)($suggesterRow['suggested_by'] ?? ($case['hearing_vote_suggester_id'] ?? 0));
if ($suggesterId <= 0) {
  foreach ($roundVotes as $rv) {
    if ((int)$rv['vote_category'] > 0) {
      $suggesterId = (int)$rv['upcc_id'];
      break;
    }
  }
}
$voterCount   = $suggesterId > 0 ? max(0, $totalPanelMembers - 1) : $totalPanelMembers;

$agreeVotes    = 0;
$disagreeVotes = 0;
foreach ($roundVotes as $rv) {
    if ((int)$rv['upcc_id'] === $suggesterId) continue; // skip suggester's own auto-vote
    if ((int)$rv['vote_category'] > 0) $agreeVotes++;
    else $disagreeVotes++;
}
$votedCount   = $agreeVotes + $disagreeVotes;
$pendingCount = max(0, $voterCount - $votedCount);

$suggesterId   = (int)($suggesterRow['suggested_by'] ?? ($case['hearing_vote_suggester_id'] ?? 0));
$suggesterName = $suggesterRow['suggester_name'] ?? '';
$suggestedCatInRound = 0;
$suggestedDetailsInRound = [];
foreach ($roundVotes as $rv) {
    if ((int)$rv['upcc_id'] === $suggesterId) {
        $suggestedCatInRound = (int)$rv['vote_category']; break;
    }
}
foreach ($roundVotes as $rv) {
  if ((int)$rv['upcc_id'] === $suggesterId) {
    $suggestedDetailsInRound = !empty($rv['vote_details']) ? json_decode($rv['vote_details'], true) : [];
    if (!is_array($suggestedDetailsInRound)) $suggestedDetailsInRound = [];
    break;
  }
}

$liveVotingSuggestion = ['category' => $suggestedCatInRound, 'details' => $suggestedDetailsInRound];

// Cooldown state (any active cooldown for this case)
$activeCooldown = db_one(
    "SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(cooldown_until)) AS remaining
     FROM upcc_suggestion_cooldown
     WHERE case_id = :c AND cooldown_until > NOW()",
    [':c' => $case_id]
);
$cooldownSecs = max(0, (int)($activeCooldown['remaining'] ?? 0));

// ── Other state ───────────────────────────────────────────────────────────
$hasPanel         = !empty($case['assigned_department_id']);
$isClosed         = in_array($case['status'], ['CLOSED', 'RESOLVED']);
$isHearingOpen    = (int)($case['hearing_is_open']   ?? 0) === 1;
$isHearingPaused  = (int)($case['hearing_is_paused'] ?? 0) === 1;
$pauseReason      = $case['hearing_pause_reason']    ?? null;
$caseLabel        = 'UPCC-' . date('Y', strtotime($case['created_at'])) . '-' . str_pad((string)$case_id, 3, '0', STR_PAD_LEFT);
$caseStatusPillClass = match ($case['status']) {
    'UNDER_INVESTIGATION'         => 'pill-investigating',
    'CLOSED', 'RESOLVED'          => 'pill-closed',
    'UNDER_APPEAL'                => 'pill-appeal',
    'AWAITING_ADMIN_FINALIZATION' => 'pill-awaiting',
    default                       => 'pill-pending',
};

function initials(string $name): string {
    $parts = explode(' ', trim($name));
    $i = strtoupper(substr($parts[0], 0, 1));
    if (count($parts) > 1) $i .= strtoupper(substr(end($parts), 0, 1));
    return $i;
}
$avatarColors = ['blue','green','purple','amber','coral'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($caseLabel) ?> — SDO Admin Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:ital,wght@0,400;0,500;0,600&family=IBM+Plex+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --font:'IBM Plex Sans',system-ui,sans-serif; --mono:'IBM Plex Mono',monospace;
  --ink-900:#0d1117; --ink-800:#1a2232; --ink-700:#263347; --ink-600:#3d4f6a;
  --ink-500:#5a6e8a; --ink-400:#8394a8; --ink-300:#b0bccb; --ink-200:#d5dce6;
  --ink-100:#edf0f5; --ink-50:#f6f8fb; --white:#fff;
  --blue-700:#1e40af; --blue-600:#1d4ed8; --blue-100:#dbeafe; --blue-50:#eff6ff;
  --amber-700:#92400e; --amber-500:#f59e0b; --amber-100:#fef3c7;
  --green-900:#014737; --green-800:#065f46; --green-700:#047857; --green-600:#059669;
  --green-100:#d1fae5; --green-50:#ecfdf5;
  --red-800:#991b1b; --red-600:#dc2626; --red-100:#fee2e2; --red-50:#fef2f2;
  --violet-700:#5b21b6; --violet-600:#7c3aed; --violet-100:#ede9fe;
  --orange-700:#c2410c; --orange-100:#ffedd5;
  --surface:#f0f3f8; --card-bg:#fff; --card-border:#e4e9f0; --border-light:#edf0f5;
  --radius-sm:6px; --radius-md:10px; --radius-lg:14px; --sidebar-w:240px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--surface);color:var(--ink-800);font-size:14px;line-height:1.55}
.admin-shell{display:grid;grid-template-columns:var(--sidebar-w) 1fr;min-height:calc(100vh - 56px)}
.main-content{overflow:auto;width:100%}
.page-header{background:var(--ink-900);padding:1.25rem 2rem;border-bottom:1px solid rgba(255,255,255,.06);
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:1rem}
.back-link{display:inline-flex;align-items:center;gap:.35rem;font-size:.75rem;color:var(--ink-400);text-decoration:none;transition:.12s}
.back-link:hover{color:var(--ink-200)}
.header-left{display:flex;flex-direction:column;gap:.5rem}
.case-id{font-family:var(--mono);font-size:1.4rem;font-weight:500;color:#fff;letter-spacing:-.01em}
.header-badges{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center}
.header-right{font-size:.72rem;color:var(--ink-500)}
.header-right strong{color:var(--ink-300)}
.pill{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:100px;font-size:.67rem;font-weight:600;text-transform:uppercase;letter-spacing:.04em}
.pill-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0}
.pill-pending{background:var(--amber-100);color:var(--amber-700)}
.pill-investigating{background:var(--blue-100);color:var(--blue-700)}
.pill-investigating .pill-dot{background:var(--blue-600)}
.pill-closed{background:var(--green-100);color:var(--green-800)}
.pill-closed .pill-dot{background:var(--green-600)}
.pill-appeal{background:var(--violet-100);color:var(--violet-700)}
.pill-open{background:var(--violet-100);color:var(--violet-700)}
.pill-open .pill-dot{background:var(--violet-600)}
.pill-warning{background:var(--amber-100);color:var(--amber-700)}
.pill-neutral{background:var(--ink-100);color:var(--ink-600)}
.pill-awaiting{background:var(--orange-100);color:var(--orange-700)}
.pill-awaiting .pill-dot{background:var(--orange-700);animation:pulse 1.4s infinite}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.page-body{padding:1.5rem 2rem}
.section-nav{display:flex;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem;padding:.5rem 0;
  border-bottom:1px solid rgba(148,163,184,.25);position:sticky;top:0;background:var(--surface);z-index:1}
.section-nav a{display:inline-flex;align-items:center;justify-content:center;
  padding:.7rem 1rem;border-radius:999px;color:var(--ink-700);background:var(--ink-50);
  text-decoration:none;border:1px solid transparent;transition:.15s}
.section-nav a:hover{background:var(--blue-50);color:var(--blue-700);border-color:var(--blue-100)}
.section-nav a.active{background:var(--blue-700);color:#fff;border-color:var(--blue-700)}
.alert{padding:.65rem 1rem;border-radius:var(--radius-md);font-size:.8rem;margin-bottom:1.25rem;border:1px solid transparent}
.alert-error{background:var(--red-100);color:var(--red-800);border-color:#fca5a5}
.alert-success{background:var(--green-100);color:var(--green-800);border-color:#6ee7b7}
.alert-warning{background:var(--amber-100);color:var(--amber-700);border-color:#fcd34d}
.alert-info{background:var(--blue-50);color:var(--blue-700);border-color:#93c5fd}
.case-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;align-items:start}
.card{background:var(--card-bg);border:1px solid var(--card-border);border-radius:var(--radius-lg);overflow:hidden;scroll-margin-top:90px}
.card-header{padding:.85rem 1.25rem;border-bottom:1px solid var(--border-light);background:var(--ink-50);
  display:flex;align-items:center;justify-content:space-between;gap:.75rem}
.card-title{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-500)}
.card-body{padding:1.25rem}
.section-label{font-size:.68rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--ink-400);margin-bottom:.6rem}
.meta-grid{display:grid;grid-template-columns:auto 1fr;gap:.3rem .9rem;font-size:.82rem}
.meta-key{color:var(--ink-500);font-size:.75rem;white-space:nowrap;padding-top:.05rem}
.meta-val{color:var(--ink-800)}
.divider{border:none;border-top:1px solid var(--border-light);margin:.9rem 0}
.summary-box{background:var(--ink-50);border:1px solid var(--border-light);border-radius:var(--radius-md);
  padding:.75rem 1rem;font-size:.82rem;line-height:1.65;color:var(--ink-700);margin-bottom:.9rem}
.offense-item{border-left:3px solid var(--amber-500);background:var(--ink-50);padding:.65rem .9rem;
  margin-bottom:.6rem;border-radius:0 var(--radius-md) var(--radius-md) 0}
.offense-item.major{border-left-color:var(--red-600)}
.offense-item:last-child{margin-bottom:0}
.offense-top{display:flex;align-items:center;gap:.45rem;margin-bottom:.25rem}
.offense-code{font-family:var(--mono);font-size:.72rem;font-weight:500;color:var(--ink-500)}
.offense-name{font-size:.83rem;font-weight:500;color:var(--ink-800)}
.offense-meta{font-size:.72rem;color:var(--ink-500);margin-top:.35rem;display:flex;gap:.75rem;flex-wrap:wrap}
.stag{display:inline-flex;align-items:center;padding:.1rem .45rem;border-radius:4px;font-size:.63rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em}
.stag-major{background:var(--red-100);color:var(--red-800)}
.stag-minor{background:var(--amber-100);color:var(--amber-700)}
.status-bar{display:flex;align-items:center;justify-content:space-between;padding:.55rem .85rem;
  background:var(--ink-50);border:1px solid var(--border-light);border-radius:var(--radius-md);
  margin-bottom:.9rem;font-size:.8rem;gap:.75rem;flex-wrap:wrap}
.status-indicator{display:flex;align-items:center;gap:.45rem}
.dot-live{width:8px;height:8px;border-radius:50%;background:var(--green-600);animation:pulse 1.8s infinite}
.dot-off{width:8px;height:8px;border-radius:50%;background:var(--ink-300)}
.status-time{font-size:.72rem;color:var(--ink-500)}
.hearing-box{background:var(--ink-50);border:1px solid var(--border-light);border-radius:var(--radius-md);padding:.75rem 1rem;margin-bottom:.9rem}
.hearing-row{display:flex;align-items:baseline;justify-content:space-between;font-size:.82rem;padding:.22rem 0}
.hearing-row+.hearing-row{border-top:1px solid var(--border-light);margin-top:.22rem;padding-top:.22rem}
.hearing-key{color:var(--ink-500);font-size:.74rem}
.hearing-val{font-weight:500}
.panel-list{margin-bottom:.9rem}
.panel-member{display:flex;align-items:center;gap:.65rem;padding:.45rem 0}
.panel-member+.panel-member{border-top:1px solid var(--border-light)}
.avatar{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.65rem;font-weight:600;flex-shrink:0}
.av-blue{background:#dbeafe;color:#1d4ed8}.av-green{background:#d1fae5;color:#065f46}
.av-purple{background:#ede9fe;color:#5b21b6}.av-amber{background:#fef3c7;color:#92400e}
.av-coral{background:#fee2e2;color:#991b1b}
.member-name{font-size:.82rem;font-weight:500;line-height:1.2}
.member-role{font-size:.7rem;color:var(--ink-500)}

/* ── LIVE VOTING SIDEBAR BLOCK ──────────────────────────────────────────── */
.voting-live-block{border:2px solid var(--blue-600);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:1rem}
.vlb-header{background:var(--blue-700);padding:.75rem 1rem;display:flex;align-items:center;justify-content:space-between;gap:.5rem}
.vlb-title{color:#fff;font-size:.82rem;font-weight:600;display:flex;align-items:center;gap:.5rem}
.live-badge{background:rgba(255,255,255,.2);color:#fff;border:1px solid rgba(255,255,255,.3);
  border-radius:999px;padding:2px 8px;font-size:.62rem;font-weight:700;text-transform:uppercase;
  letter-spacing:.05em;animation:blink 1.4s ease-in-out infinite}
@keyframes blink{0%,100%{opacity:1}50%{opacity:.4}}
.vlb-body{background:#fff;padding:1rem}
.vlb-suggestion{background:var(--blue-50);border:1px solid var(--blue-100);border-radius:var(--radius-md);
  padding:.75rem 1rem;margin-bottom:.85rem}
.vlb-sug-cat{font-size:.9rem;font-weight:700;color:var(--blue-700);margin-bottom:.3rem}
.vlb-sug-by{font-size:.72rem;color:var(--ink-500);margin-bottom:.5rem}
.vlb-sug-detail{font-size:.75rem;color:var(--ink-700);line-height:1.5}
.vlb-tag{display:inline-block;background:var(--blue-100);color:var(--blue-700);border-radius:4px;
  padding:1px 6px;font-size:.68rem;font-weight:700;margin:1px}
.cat2-hours-grid{display:flex;flex-wrap:wrap;gap:.45rem;margin-top:.45rem}
.cat2-hour-pill{display:inline-flex;align-items:center;gap:.4rem;background:#fff;border:1px solid rgba(99,102,241,.25);
  color:var(--ink-700);padding:.48rem .75rem;border-radius:999px;font-size:.78rem;font-weight:700;cursor:pointer;
  transition:all .15s ease;box-shadow:0 1px 0 rgba(0,0,0,.03)}
.cat2-hour-pill:hover{border-color:var(--blue-400);color:var(--blue-700);transform:translateY(-1px)}
.cat2-hour-pill input{margin:0}
.cat2-hour-pill:has(input:checked){background:var(--blue-600);border-color:var(--blue-600);color:#fff}
.cat2-hours-other{display:flex;align-items:center;gap:.5rem;background:var(--amber-50);border:1px solid var(--amber-200);
  color:var(--amber-800);padding:.5rem .7rem;border-radius:999px;font-size:.78rem;font-weight:700;cursor:pointer;
  width:max-content;box-shadow:0 1px 0 rgba(0,0,0,.03)}
.cat2-hours-other input{margin:0}
.cat2-hours-other span{line-height:1}
.live-voting-detail{display:flex;flex-direction:column;gap:.4rem;margin-top:.6rem}
.live-voting-detail .detail-pill{display:inline-flex;align-items:center;gap:.35rem;width:fit-content;
  background:rgba(255,255,255,.78);border:1px solid rgba(59,130,246,.2);border-radius:999px;padding:.28rem .65rem;
  font-size:.72rem;font-weight:700;color:var(--blue-800)}

/* Vote tally */
.vote-tally{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:.75rem}
.vote-tally-cell{text-align:center;border-radius:var(--radius-md);padding:.6rem;border:1px solid}
.vtc-agree{background:var(--green-50);border-color:#a7f3d0}
.vtc-disagree{background:var(--red-50);border-color:#fca5a5}
.vtc-pending{background:var(--ink-50);border-color:var(--border-light)}
.vtc-num{font-family:var(--mono);font-size:1.4rem;font-weight:700;display:block}
.vtc-lbl{font-size:.65rem;text-transform:uppercase;letter-spacing:.06em;font-weight:600;display:block;margin-top:2px}
.vtc-agree .vtc-num,.vtc-agree .vtc-lbl{color:var(--green-700)}
.vtc-disagree .vtc-num,.vtc-disagree .vtc-lbl{color:var(--red-600)}
.vtc-pending .vtc-num,.vtc-pending .vtc-lbl{color:var(--ink-500)}

/* Vote rows */
.vote-head{display:flex;justify-content:space-between;padding-bottom:.4rem;margin-bottom:.2rem;border-bottom:1px solid var(--border-light)}
.vote-head span{font-size:.67rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--ink-400)}
.vote-row{display:flex;justify-content:space-between;align-items:center;padding:.42rem 0;font-size:.8rem}
.vote-row+.vote-row{border-top:1px solid var(--border-light)}
.vote-cat{font-family:var(--mono);font-size:.7rem;font-weight:500;background:var(--blue-100);color:var(--blue-700);padding:.1rem .45rem;border-radius:4px}
.vote-cat.agree{background:var(--green-100);color:var(--green-800)}
.vote-cat.disagree{background:var(--red-100);color:var(--red-800)}
.vote-cat.suggester{background:var(--violet-100);color:var(--violet-700)}
.vote-time{font-size:.7rem;color:var(--ink-400);margin-left:.4rem}

/* Timer */
.vlb-timer-wrap{margin-bottom:.75rem}
.vlb-timer-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px}
.vlb-timer-label{font-size:.68rem;color:var(--ink-500);text-transform:uppercase;letter-spacing:.06em}
.vlb-timer-num{font-family:var(--mono);font-size:1.2rem;font-weight:700;color:var(--ink-800);font-variant-numeric:tabular-nums}
.vlb-timer-num.urgent{color:var(--red-600)}
.vlb-timer-bar{height:5px;background:var(--ink-100);border-radius:999px;overflow:hidden}
.vlb-timer-fill{height:100%;border-radius:999px;transition:width 1s linear,background .5s}

/* Cooldown block */
.cooldown-block{background:var(--amber-100);border:1px solid #fcd34d;border-radius:var(--radius-md);
  padding:.75rem 1rem;text-align:center;margin-bottom:.75rem}
.cooldown-title{font-size:.75rem;font-weight:600;color:var(--amber-700);margin-bottom:.25rem}
.cooldown-num{font-family:var(--mono);font-size:1.5rem;font-weight:700;color:var(--amber-700);
  font-variant-numeric:tabular-nums}

/* Consensus block */
.consensus-finalize-block{border:2px solid #059669;border-radius:var(--radius-lg);overflow:hidden;margin-bottom:1rem;
  box-shadow:0 4px 16px rgba(5,150,105,.12)}
.cf-header{background:linear-gradient(135deg,#059669,#047857);padding:1rem 1.25rem;
  display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap}
.cf-header-left{display:flex;align-items:center;gap:.75rem}
.cf-icon{font-size:1.5rem}
.cf-title{font-size:.95rem;font-weight:600;color:#fff}
.cf-sub{font-size:.75rem;color:rgba(255,255,255,.8);margin-top:2px}
.cf-body{padding:1.25rem;background:#fff}
.cat-badge{display:inline-flex;align-items:center;gap:.5rem;background:var(--green-900);color:#fff;
  padding:.45rem 1rem;border-radius:999px;font-size:.78rem;font-weight:700;letter-spacing:.02em;margin-bottom:1rem}
.cat-desc-box{background:var(--green-50);border:1px solid #a7f3d0;border-radius:var(--radius-md);padding:.85rem 1rem;margin-bottom:.85rem}
.cat-desc-label{font-size:.65rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--green-800);margin-bottom:.35rem}
.cat-desc-text{font-size:.88rem;color:var(--green-900);line-height:1.55;font-weight:500}
.cat-detail-grid{display:grid;gap:.5rem;margin-bottom:.85rem}
.cat-detail-row{display:flex;gap:.5rem;align-items:baseline;font-size:.8rem}
.cat-detail-key{color:var(--ink-500);font-size:.72rem;white-space:nowrap;min-width:130px}
.cat-detail-val{color:var(--ink-800);font-weight:500;flex:1}

/* Final decision form */
.form-group{margin-bottom:.85rem}
.form-label{display:block;font-size:.69rem;font-weight:600;text-transform:uppercase;letter-spacing:.05em;color:var(--ink-500);margin-bottom:.35rem}
.form-control{width:100%;padding:.48rem .75rem;border:1px solid var(--ink-200);border-radius:var(--radius-sm);
  font-size:.82rem;font-family:var(--font);background:#fff;color:var(--ink-800);transition:.12s}
.form-control:focus{outline:none;border-color:var(--blue-600);box-shadow:0 0 0 3px rgba(59,130,246,.14)}
textarea.form-control{resize:vertical}
.panel-select-wrapper { border: 1px solid var(--ink-200); border-radius: var(--radius-sm); padding: 6px; background: #fff; min-height: 48px; display: flex; flex-direction: column; gap: 6px; }
.selected-panel-members { display: flex; flex-wrap: wrap; gap: 6px; }
.panel-chip { display: inline-flex; align-items: center; gap: 6px; background: var(--blue-100); color: var(--blue-800); font-size: .75rem; font-weight: 600; padding: 4px 10px; border-radius: 14px; }
.panel-chip-remove { cursor: pointer; color: var(--blue-600); font-weight: bold; }
.panel-chip-remove:hover { color: var(--red-600); }
.panel-member-search { width: 100%; border: none; background: transparent; padding: 6px; font-size: .82rem; font-family: var(--font); outline: none; }
.panel-member-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid var(--ink-200); border-radius: var(--radius-sm); box-shadow: 0 4px 12px rgba(0,0,0,0.1); max-height: 200px; overflow-y: auto; z-index: 1000; display: none; }
.panel-member-dropdown.show { display: block; }
.dropdown-item { padding: 8px 12px; font-size: .75rem; cursor: pointer; display: flex; flex-direction: column; border-bottom: 1px solid var(--ink-100); }
.dropdown-item:last-child { border-bottom: none; }
.dropdown-item:hover { background: var(--ink-50); }
.dropdown-item-title { font-weight: 600; color: var(--ink-800); display: flex; justify-content: space-between; }
.dropdown-item-sub { font-size: .68rem; color: var(--ink-500); }
.cb-scroll{border:1px solid var(--ink-200);border-radius:var(--radius-sm);padding:.45rem;max-height:155px;overflow-y:auto}
.cb-item{display:flex;align-items:center;gap:.5rem;padding:.28rem .4rem;font-size:.79rem;border-radius:4px;cursor:pointer}
.cb-item:hover{background:var(--ink-50)}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:.75rem}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:.35rem;padding:.5rem 1.1rem;border-radius:var(--radius-sm);
  font-size:.8rem;font-weight:500;font-family:var(--font);cursor:pointer;border:1px solid transparent;transition:.12s;text-decoration:none}
.btn-primary{background:var(--ink-900);color:#fff;border-color:var(--ink-900)}
.btn-primary:hover{background:var(--ink-800)}
.btn-success{background:var(--green-600);color:#fff;border-color:var(--green-600)}
.btn-success:hover{background:var(--green-800)}
.btn-danger{background:var(--red-600);color:#fff;border-color:var(--red-600)}
.btn-danger:hover{background:var(--red-800)}
.btn-warning{background:var(--amber-500);color:#fff;border-color:var(--amber-500)}
.btn-secondary{background:var(--ink-600);color:#fff;border-color:var(--ink-600)}
.btn-outline{background:#fff;border-color:var(--ink-200);color:var(--ink-700)}
.btn-outline:hover{background:var(--ink-50)}
.btn-ghost{background:transparent;border-color:transparent;color:var(--ink-500)}
.btn-ghost:hover{background:var(--ink-100);color:var(--ink-700)}
.btn-sm{padding:.3rem .7rem;font-size:.73rem}
.btn-full{width:100%}
.btn-group{display:flex;gap:.5rem;flex-wrap:wrap}
.edit-panel{margin-top:1.25rem;display:none}
.edit-panel.open{display:block}
.waiting-room-box{display:none;margin-bottom:1rem;background:var(--amber-100);border:2px solid #ff6b6b;border-radius:var(--radius-md);padding:1rem}
.awaiting-box{background:var(--ink-50);border:1px solid var(--border-light);border-radius:var(--radius-md);padding:.6rem .9rem;
  display:flex;align-items:center;gap:.5rem;font-size:.8rem;color:var(--ink-500);margin-bottom:.75rem}
.consensus-box{background:var(--green-100);border:1px solid #6ee7b7;border-radius:var(--radius-md);padding:.6rem .9rem;
  display:flex;align-items:center;justify-content:space-between;font-size:.82rem;color:var(--green-800);font-weight:500;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem}

/* Dynamic category fields inside final form */
#dynamicFieldsContainer{background:var(--ink-50);border:1px solid var(--border-light);padding:1rem;border-radius:var(--radius-md);margin-bottom:.85rem;display:none}

.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:3000;place-items:center;padding:1rem}
.modal-overlay.open{display:grid}
.modal-content{background:#fff;border-radius:12px;padding:24px;max-width:480px;width:min(92vw,480px);
  max-height:calc(100vh - 2rem);overflow:auto;box-shadow:0 10px 40px rgba(0,0,0,.3)}
.modal-content h3{margin-bottom:16px;color:#333;text-align:center}
.modal-content textarea{width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;margin:16px 0;resize:vertical;font-family:inherit}
.modal-buttons{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}
.modal-content ul{margin:12px 0 12px 20px;font-size:.85rem;line-height:1.8}

/* Toast */
.toast{position:fixed;top:20px;right:20px;padding:14px 20px;border-radius:10px;color:#fff;
  z-index:9999;font-family:var(--font);box-shadow:0 8px 24px rgba(0,0,0,.2);max-width:340px;
  animation:slideInRight .3s ease-out}
@keyframes slideInRight{from{transform:translateX(120%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes slideOutRight{from{transform:translateX(0);opacity:1}to{transform:translateX(120%);opacity:0}}
.toast-title{font-weight:700;font-size:.85rem;margin-bottom:3px}
.toast-msg{font-size:.75rem;opacity:.9}
.toast-success{background:var(--green-700)}
.toast-info{background:var(--blue-700)}
.toast-warning{background:var(--amber-700)}

@media(max-width:900px){.case-grid{grid-template-columns:1fr}}
</style>
</head>
<body style="padding-top: 0px;">
<?php require_once __DIR__ . '/header.php'; ?>
<div class="admin-shell">
  <?php require_once __DIR__ . '/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="header-left">
        <a href="upcc_cases.php" class="back-link">← Back to Cases</a>
        <div class="case-id"><?= htmlspecialchars($caseLabel) ?></div>
        <div class="header-badges">
          <span class="pill <?= htmlspecialchars($caseStatusPillClass) ?>">
            <span class="pill-dot"></span>
            <?= ucfirst(strtolower(str_replace('_', ' ', $case['status']))) ?>
          </span>
          <?php if ($hasPanel && !$isClosed): ?>
            <span class="pill <?= $isHearingOpen ? ($isHearingPaused ? 'pill-warning' : 'pill-open') : 'pill-neutral' ?>" id="hearing-status-pill">
              <?php if ($isHearingOpen && !$isHearingPaused): ?><span class="pill-dot"></span><?php endif; ?>
              Hearing <?= $isHearingOpen ? ($isHearingPaused ? 'Paused' : 'Open') : 'Closed' ?>
            </span>
          <?php endif; ?>
          <?php if ($isAwaitingAdmin): ?>
            <span class="pill pill-awaiting"><span class="pill-dot"></span>Awaiting Your Decision</span>
          <?php endif; ?>
        </div>
      </div>
      <div class="header-right">
        Filed <strong><?= fmtd($case['created_at']) ?></strong><br>
        Updated <strong><?= fmtd($case['updated_at']) ?></strong>
      </div>
    </div>

    <div class="page-body">
      <?php if ($errMsg): ?><div class="alert alert-error"><?= htmlspecialchars($errMsg) ?></div><?php endif; ?>
      <?php if ($okMsg):  ?><div class="alert alert-success"><?= htmlspecialchars($okMsg) ?></div><?php endif; ?>


      <div class="case-grid">

        <!-- ════════════════════════════════════════════════════════════
             CARD 1 — Hearing & Panel Management
        ════════════════════════════════════════════════════════════ -->
        <div class="card" id="hearing-card">
          <div class="card-header">
            <span class="card-title">Hearing &amp; Panel Management</span>
            <?php if (!$isClosed && $hasPanel): ?>
              <span class="pill <?= $isHearingOpen ? 'pill-open' : 'pill-neutral' ?>">
                <?php if ($isHearingOpen): ?><span class="pill-dot"></span><?php endif; ?>
                <?= $isHearingOpen ? 'Hearing Open' : 'Hearing Closed' ?>
              </span>
            <?php endif; ?>
          </div>
          <div class="card-body">

            <?php if (!$hasPanel): ?>
              <!-- No hearing configured yet -->
              <div class="alert alert-info" style="margin-bottom:1rem">No panel assigned yet. Configure below.</div>
              <form method="post" id="hearingConfigForm">
                <input type="hidden" name="action" value="update_hearing_config">
                <div class="form-group">
                  <label class="form-label">Lead Department</label>
                  <select name="assigned_department_id" id="hearing_dept_select" class="form-control" onchange="filterPanelDropdown('hearing')" required>
                    <option value="">Select department…</option>
                    <?php foreach ($departments as $dept): ?>
                      <option value="<?= $dept['dept_id'] ?>" <?= ($defaultDeptId === (int)$dept['dept_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dept['dept_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label" id="hearing-panel-label">Panel Members (Select from lead department)</label>
                  <div class="panel-select-wrapper">
                      <div id="hearing-selected-panel-members" class="selected-panel-members"></div>
                      <div style="position:relative;">
                          <input type="text" id="hearing-panel-member-search" class="panel-member-search" placeholder="Search and click to add panel members..." oninput="filterPanelDropdown('hearing')" onfocus="showPanelDropdown('hearing')" onblur="setTimeout(() => hidePanelDropdown('hearing'), 200)">
                          <div id="hearing-panel-member-dropdown" class="panel-member-dropdown"></div>
                      </div>
                  </div>
                  <div id="hearing-hidden-panel-inputs"></div>
                </div>
                <div class="form-row">
                  <div class="form-group">
                    <label class="form-label">Hearing Date</label>
                    <input type="date" name="hearing_date" class="form-control" required>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Hearing Time</label>
                    <input type="time" name="hearing_time" class="form-control" required>
                  </div>
                </div>
                <button type="submit" class="btn btn-primary btn-full">Save Hearing Configuration</button>
              </form>

            <?php else: ?>

              <?php if (!$isClosed): ?>
              <!-- Status bar -->
              <div class="status-bar">
                <div class="status-indicator">
                  <span class="<?= $isHearingOpen ? 'dot-live' : 'dot-off' ?>"></span>
                  <?php if ($isHearingOpen): ?>
                    <strong>Live</strong><span class="status-time">· Opened <?= fmt($case['hearing_opened_at']) ?></span>
                  <?php else: ?>
                    <span>Hearing not started</span>
                  <?php endif; ?>
                </div>
                <div class="btn-group">
                  <?php if (!$isHearingOpen): ?>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="action" value="start_hearing">
                      <?php
                        $needsExplanation = in_array($case['case_kind'], ['MAJOR_OFFENSE', 'SECTION4_MINOR_ESCALATION']);
                        $hasExplanation   = !empty($case['student_explanation_at']);
                        $canStartHearing  = !$needsExplanation || $hasExplanation;
                      ?>
                      <button type="submit" class="btn btn-success btn-sm" <?= $canStartHearing ? '' : 'disabled' ?> id="btnStartHearing">▶ Start Hearing</button>
                      <?php if (!$canStartHearing): ?>
                        <div style="font-size:10px; color:var(--red-600); margin-top:4px; font-weight:600;">Awaiting Student Explanation</div>
                      <?php endif; ?>
                    </form>
                  <?php else: ?>
                    <button type="button" id="togglePauseBtn" class="btn btn-warning btn-sm" onclick="toggleHearingPause()">⏸️ Pause Hearing</button>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="action" value="close_hearing">
                      <button type="submit" id="btnEndHearing" class="btn btn-danger btn-sm">⬛ End Hearing</button>
                    </form>
                  <?php endif; ?>
                  <?php if (!$isHearingOpen): ?>
                    <button class="btn btn-ghost btn-sm" onclick="toggleEditPanel()">✎ Edit Config</button>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($isHearingOpen): ?>
                <div id="liveHearingStatus" class="alert alert-info">
                  🗳️ Hearing is live — panel may now vote.
                </div>
              <?php endif; ?>
              <?php endif; ?>

              <!-- Student Explanation Section -->
              <div id="studentExplanationBlock" style="<?= !empty($case['student_explanation_at']) ? 'display:block' : 'display:none' ?>; margin-bottom: 20px; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden;">
                <div style="background: #f8fafc; padding: 10px 16px; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between;">
                   <span style="font-size: 11px; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Student Explanation</span>
                   <span id="explanationTime" style="font-size: 11px; color: #94a3b8;"><?= $case['student_explanation_at'] ? 'Submitted ' . date('M j, Y g:i A', strtotime($case['student_explanation_at'])) : '' ?></span>
                </div>
                <div style="padding: 16px; background: #fff;">
                   <div id="explanationText" style="font-size: 14px; color: #1e293b; line-height: 1.6; white-space: pre-wrap; margin-bottom: 12px;"><?= htmlspecialchars($case['student_explanation_text'] ?? '') ?></div>
                   <div id="explanationAttachments" style="display: flex; gap: 12px; flex-wrap: wrap;">
                      <?php if (!empty($case['student_explanation_image'])): ?>
                        <a href="../<?= htmlspecialchars($case['student_explanation_image']) ?>" target="_blank" id="explanationImageLink" style="display: block; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0;">
                           <img src="../<?= htmlspecialchars($case['student_explanation_image']) ?>" style="max-width: 100px; max-height: 100px; display: block; object-fit: cover;">
                        </a>
                      <?php endif; ?>
                      <?php if (!empty($case['student_explanation_pdf'])): ?>
                        <a href="../<?= htmlspecialchars($case['student_explanation_pdf']) ?>" target="_blank" id="explanationPdfLink" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #fff1f2; border: 1px solid #fecaca; border-radius: 8px; text-decoration: none; color: #be123c; font-size: 12px; font-weight: 600;">
                           <span>📄 View PDF Explanation</span>
                        </a>
                      <?php endif; ?>
                   </div>
                </div>
              </div>

              <!-- Hearing info -->
              <div class="section-label">Scheduled Hearing</div>
              <div class="hearing-box">
                <div class="hearing-row">
                  <span class="hearing-key">Date &amp; Time</span>
                  <span class="hearing-val">
                    <?= $case['hearing_date'] ? date('M j, Y', strtotime($case['hearing_date'])) : '—' ?>
                    <?= $case['hearing_time'] ? ' · ' . date('g:i A', strtotime($case['hearing_time'])) : '' ?>
                  </span>
                </div>
                <div class="hearing-row">
                  <span class="hearing-key">Department</span>
                  <span class="hearing-val"><?= htmlspecialchars($case['assigned_dept_name'] ?? '—') ?></span>
                </div>
              </div>

              <!-- Panel members -->
              <div class="section-label">Panel Members</div>
              <?php if (empty($assignedPanelNames)): ?>
                <p style="font-size:.8rem;color:var(--ink-400);margin-bottom:.9rem">No panel members assigned.</p>
              <?php else: ?>
                <div class="panel-list">
                  <?php foreach ($assignedPanelNames as $idx => $pm):
                    $avClass = 'av-' . $avatarColors[$idx % count($avatarColors)]; ?>
                    <div class="panel-member">
                      <div class="avatar <?= $avClass ?>"><?= htmlspecialchars(initials($pm['name'])) ?></div>
                      <div>
                        <div class="member-name"><?= htmlspecialchars($pm['name']) ?></div>
                        <div class="member-role"><?= htmlspecialchars($pm['role']) ?></div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

              <!-- Rejoin requests -->
              <?php if ($isHearingOpen && !$isClosed): ?>
                <div id="waitingUsersContainer" class="waiting-room-box">
                  <div style="font-weight:600;color:var(--amber-700);margin-bottom:.5rem;font-size:.8rem;display:flex;align-items:center;justify-content:space-between">
                    🚪 Rejoin Requests
                    <span id="rejoinBadge" style="display:none;background:#ff6b6b;color:#fff;border-radius:50%;width:22px;height:22px;align-items:center;justify-content:center;font-size:11px;font-weight:700;">0</span>
                  </div>
                  <div id="waitingUsersList"></div>
                </div>
              <?php endif; ?>

              <?php if (!$isClosed): ?>
              
              <!-- ═══════════════════════════════════════════════════
                   LIVE VOTING SECTION (distinct container)
              ══════════════════════════════════════════════════════ -->
              <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 8px; padding: 20px; margin-top: 25px;">
                  <div class="section-label" style="display:flex;align-items:center;justify-content:space-between; margin-top: 0;">
                    <span>
                      <span style="font-size:1.1rem;color:var(--blue-700)">UPCC Panel Voting</span>
                      <?php if ($roundNo): ?><span style="font-weight:400;color:var(--ink-500);margin-left:8px;">— Round <span id="currentRoundNo"><?= $roundNo ?></span></span><?php endif; ?>
                    </span>
                <?php if ($totalPanelMembers > 0 && $roundNo > 0 && !$isAwaitingAdmin): ?>
                  <span style="font-size:.68rem;color:var(--ink-500)">All <?= $voterCount ?> voter(s) must agree</span>
                <?php endif; ?>
              </div>

              <!-- COOLDOWN STATE -->
              <?php if ($cooldownSecs > 0): ?>
                <div class="cooldown-block" id="cooldownBlock">
                  <div class="cooldown-title">⏳ Cooldown Active</div>
                  <div class="cooldown-num" id="cooldownTimer"><?= sprintf('%d:%02d', floor($cooldownSecs / 60), $cooldownSecs % 60) ?></div>
                  <div style="font-size:.7rem;color:var(--amber-700);margin-top:.25rem">Panel can suggest again after cooldown</div>
                </div>
              <?php endif; ?>

              <!-- ACTIVE VOTING ROUND -->
              <?php
              $isRoundActive = $activeRound !== null && (int)($activeRound['is_active'] ?? 0) === 1;
              $roundEndsAt   = $activeRound['ends_at'] ?? null;
              $roundSecsLeft = $roundEndsAt ? max(0, strtotime($roundEndsAt) - time()) : 0;
              ?>

              <?php if ($isRoundActive && $suggestedCatInRound > 0): ?>
                <!-- Active voting round block -->
                <div class="voting-live-block" id="votingLiveBlock">
                  <div class="vlb-header">
                    <div class="vlb-title">
                      🗳️ Active Vote — Round <?= $roundNo ?>
                      <span class="live-badge">● Live</span>
                    </div>
                    <div style="font-family:var(--mono);font-size:.82rem;color:#fff" id="vlbTimer">
                      <?= sprintf('%02d:%02d', floor($roundSecsLeft / 60), $roundSecsLeft % 60) ?>
                    </div>
                  </div>
                  <div class="vlb-body">

                    <!-- Timer bar -->
                    <div class="vlb-timer-wrap">
                      <div class="vlb-timer-bar">
                        <div class="vlb-timer-fill" id="vlbTimerFill"
                            style="width:<?= $roundSecsLeft > 0 ? round(($roundSecsLeft / 600) * 100) : 0 ?>%;
                                    background:<?= $roundSecsLeft > 600 ? 'var(--green-600)' : ($roundSecsLeft > 180 ? 'var(--amber-500)' : 'var(--red-600)') ?>"></div>
                      </div>
                    </div>

                    <!-- Suggested penalty -->
                    <div class="vlb-suggestion">
                      <div class="vlb-sug-cat">Category <?= $suggestedCatInRound ?></div>
                      <div class="vlb-sug-by">Suggested by: <strong><?= htmlspecialchars($suggesterName) ?></strong></div>
                      <div class="vlb-sug-detail" id="vlbSugDetail">
                        <?php
                        // find suggestion vote details
                        $sugVoteRow = null;
                        foreach ($roundVotes as $rv) {
                            if ((int)$rv['upcc_id'] === $suggesterId) { $sugVoteRow = $rv; break; }
                        }
                        $sugDetails = $sugVoteRow && !empty($sugVoteRow['vote_details'])
                            ? json_decode($sugVoteRow['vote_details'], true) : [];
                        if ($suggestedCatInRound === 1 && !empty($sugDetails['probation_terms'])):
                        ?>
                          Probation: <strong><?= (int)$sugDetails['probation_terms'] ?> term(s)</strong>
                        <?php elseif ($suggestedCatInRound === 2 && !empty($sugDetails['interventions'])): ?>
                          <?php foreach ($sugDetails['interventions'] as $iv): ?>
                            <span class="vlb-tag"><?= htmlspecialchars($iv) ?></span>
                          <?php endforeach; ?>
                          <?php if (!empty($sugDetails['service_hours'])): ?>
                            <span class="vlb-tag"><?= (int)$sugDetails['service_hours'] ?> hrs</span>
                          <?php endif; ?>
                        <?php elseif ($suggestedCatInRound >= 3): ?>
                          <?= ['', '', '', 'Non-Readmission — student account will be frozen', 'Exclusion — student account will be frozen', 'Expulsion — account permanently frozen'][$suggestedCatInRound] ?>
                        <?php endif; ?>
                        <?php if (!empty($sugDetails['description'])): ?>
                          <div style="margin-top:.4rem;font-style:italic;color:var(--ink-500)"><?= htmlspecialchars($sugDetails['description']) ?></div>
                        <?php endif; ?>
                      </div>
                    </div>

                    <!-- Tally -->
                    <div class="vote-tally">
                      <div class="vote-tally-cell vtc-agree">
                        <span class="vtc-num" id="tallyAgree"><?= $agreeVotes ?></span>
                        <span class="vtc-lbl">✅ Agree</span>
                      </div>
                      <div class="vote-tally-cell vtc-disagree">
                        <span class="vtc-num" id="tallyDisagree"><?= $disagreeVotes ?></span>
                        <span class="vtc-lbl">❌ Disagree</span>
                      </div>
                      <div class="vote-tally-cell vtc-pending">
                        <span class="vtc-num" id="tallyPending"><?= $pendingCount ?></span>
                        <span class="vtc-lbl">⏳ Pending</span>
                      </div>
                    </div>
                    <div style="text-align:center;font-size:.72rem;color:var(--ink-500);margin-bottom:.75rem">
                      All <?= $voterCount ?> voter(s) must agree to pass
                    </div>

                    <!-- Per-member vote breakdown -->
                    <?php if (!empty($roundVotes)): ?>
                      <div class="vote-head"><span>Panel Member</span><span>Vote · Time</span></div>
                      <?php foreach ($roundVotes as $rv):
                        $cat      = (int)$rv['vote_category'];
                        $isSug    = (int)$rv['upcc_id'] === $suggesterId;
                        $isAgree  = $cat > 0 && !$isSug;
                      ?>
                        <div class="vote-row">
                          <span><?= htmlspecialchars($rv['full_name'] ?? 'Panel Member') ?><?= $isSug ? ' <small style="color:var(--ink-400)">(suggester)</small>' : '' ?></span>
                          <span>
                            <?php if ($isSug): ?>
                              <span class="vote-cat suggester">🗣️ Suggested</span>
                            <?php elseif ($isAgree): ?>
                              <span class="vote-cat agree">✅ Agree</span>
                            <?php else: ?>
                              <span class="vote-cat disagree">❌ Disagree</span>
                            <?php endif; ?>
                            <span class="vote-time"><?= fmt($rv['updated_at']) ?></span>
                          </span>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="awaiting-box">⌛ No votes yet for this round.</div>
                    <?php endif; ?>

                  </div>
                </div>

              <?php elseif (!$isAwaitingAdmin): ?>
                <!-- No active round -->
                <div class="awaiting-box" id="awaitingVoteBox">
                  <span>⌛</span>
                  <span><?= $totalPanelMembers === 0 ? 'No panel members assigned.' : 'Waiting for a panel member to suggest a penalty.' ?></span>
                </div>
              <?php endif; ?>

              <!-- ═══════════════════════════════════════════════════
                   CONSENSUS REACHED — Finalization block
              ══════════════════════════════════════════════════════ -->
              <?php if ($isAwaitingAdmin || $consensusCategory > 0): ?>
                <div class="consensus-finalize-block" id="consensusBlock">
                  <div class="cf-header">
                    <div class="cf-header-left">
                      <span class="cf-icon">✅</span>
                      <div>
                        <div class="cf-title">Panel Consensus Reached</div>
                        <div class="cf-sub">All panel members agreed. Review and record the final decision below.</div>
                      </div>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm"
                            style="background:rgba(255,255,255,.15);color:#fff;border-color:rgba(255,255,255,.3)"
                            onclick="showCancelConsensusModal()">
                      🔄 Cancel &amp; Re-vote
                    </button>
                  </div>
                  <div class="cf-body">
                    <div class="cat-badge">🏷️ Category <?= $consensusCategory ?> Penalty</div>
                    <div class="cat-desc-box">
                      <div class="cat-desc-label">Official Category Definition</div>
                      <div class="cat-desc-text"><?= nl2br(htmlspecialchars($categoryDescriptions[$consensusCategory] ?? '')) ?></div>
                    </div>

                    <?php if ($consensusCategory === 1 && !empty($suggestedVoteDetails['probation_terms'])): ?>
                      <div class="cat-detail-grid">
                        <div class="cat-detail-row">
                          <span class="cat-detail-key">📋 Probation terms:</span>
                          <span class="cat-detail-val"><?= (int)$suggestedVoteDetails['probation_terms'] ?> term(s)</span>
                        </div>
                      </div>
                    <?php elseif ($consensusCategory === 2 && !empty($prefillCat2Interventions)): ?>
                      <div class="cat-detail-grid">
                        <div class="cat-detail-row">
                          <span class="cat-detail-key">🔧 Interventions:</span>
                          <span class="cat-detail-val">
                            <?php foreach ($prefillCat2Interventions as $iv): ?>
                              <span style="display:inline-flex;align-items:center;gap:4px;background:var(--blue-100);color:var(--blue-700);padding:2px 8px;border-radius:4px;font-size:.75rem;margin:2px 2px 2px 0">
                                <?= htmlspecialchars($iv) ?>
                                <?php if ($iv === 'University Service' && !empty($prefillCat2Hours)): ?>
                                  — <?= htmlspecialchars($prefillCat2Hours) ?> hrs
                                <?php endif; ?>
                              </span>
                            <?php endforeach; ?>
                          </span>
                        </div>
                      </div>
                    <?php elseif ($consensusCategory >= 3): ?>
                      <div class="alert alert-warning" style="margin-bottom:.85rem">
                        ⚠️ This penalty will <strong>freeze</strong> the student account upon confirmation.
                      </div>
                    <?php endif; ?>

                    <?php if ($case['hearing_vote_consensus_at']): ?>
                      <div style="font-size:.72rem;color:var(--ink-500);margin-bottom:.85rem">
                        ⏱️ Consensus reached <?= fmt($case['hearing_vote_consensus_at']) ?>
                      </div>
                    <?php endif; ?>

                    <button type="button" class="btn btn-success" style="margin-bottom:.5rem" onclick="adoptSuggestedPenalty()">
                      📋 Auto-fill Form with Suggested Penalty
                    </button>
                  </div>
                </div>
              <?php endif; ?>

              <!-- ═══════════════════════════════════════════════════
                   FINAL DECISION FORM
              ══════════════════════════════════════════════════════ -->
              <hr class="divider">
              <div class="section-label">Record Final Decision</div>

              <?php if (!$consensusCategory): ?>
                <div class="alert alert-warning">
                  No consensus yet. Wait for the panel or enable "Force final decision" below.
                </div>
              <?php endif; ?>

              <form method="post" id="finalDecisionForm">
                <input type="hidden" name="action" value="resolve_case">
                <input type="hidden" id="use_suggested" name="use_suggested" value="0">

                <div class="form-group">
                  <label class="form-label">
                    Category
                    <?php if ($consensusCategory): ?>
                      <span style="color:var(--green-700);font-weight:600">(Panel consensus: Category <?= $consensusCategory ?>)</span>
                    <?php endif; ?>
                  </label>
                  <select name="decided_category" id="decided_category" class="form-control" required onchange="toggleCategoryFields()" <?= !$consensusCategory ? 'disabled' : '' ?>>
                    <option value="">Select category…</option>
                    <?php for ($cat = 1; $cat <= 5; $cat++): ?>
                      <option value="<?= $cat ?>" <?= $cat === ($postedDecidedCategory ?: $consensusCategory) ? 'selected' : '' ?>>
                        Category <?= $cat ?><?= $cat === $consensusCategory ? ' ← Consensus' : '' ?>
                      </option>
                    <?php endfor; ?>
                  </select>
                </div>

                <!-- Dynamic category-specific fields -->
                <div id="dynamicFieldsContainer">

                  <!-- Cat 1: Probation -->
                  <div id="cat1Fields" style="display:none">
                    <label class="form-label">Probation Terms</label>
                    <p style="font-size:.75rem;color:var(--ink-500);margin-bottom:.5rem">Select how many academic terms the probation lasts.</p>
                    <select name="cat1_terms" id="cat1_terms" class="form-control">
                      <option value="1" <?= $prefillCat1Terms === 1 ? 'selected' : '' ?>>1 term</option>
                      <option value="2" <?= $prefillCat1Terms === 2 ? 'selected' : '' ?>>2 terms</option>
                      <option value="3" <?= $prefillCat1Terms === 3 || $prefillCat1Terms === 0 ? 'selected' : '' ?>>3 terms (maximum)</option>
                    </select>
                    <p style="font-size:.73rem;color:var(--ink-500);margin-top:.4rem;line-height:1.4">Any subsequent major offense during probation triggers Suspension or Non-Readmission.</p>
                  </div>

                  <!-- Cat 2: Formative Intervention -->
                  <div id="cat2Fields" style="display:none">
                    <label class="form-label">Formative Interventions</label>
                    <p style="font-size:.75rem;color:var(--ink-500);margin-bottom:.5rem">Select one or more.</p>
                    <label class="cb-item" style="margin-bottom:.35rem">
                      <input type="checkbox" name="cat2_university_service" id="cat2_university_service" value="1" onchange="toggleCommunityHours()" <?= in_array('University Service', $prefillCat2Interventions) ? 'checked' : '' ?>>
                      University Service (Community Service)
                    </label>
                    <div id="communityHoursBox" style="display:<?= in_array('University Service', $prefillCat2Interventions) ? 'block' : 'none' ?>;margin-left:1.5rem;margin-bottom:.5rem">
                      <label style="font-size:.75rem;color:var(--ink-600)">Required Hours</label>
                      <div class="cat2-hours-grid" role="radiogroup" aria-label="Required hours">
                        <?php foreach (['100','150','200','250','300','350','400','450','500'] as $hrs): ?>
                          <label class="cat2-hour-pill">
                            <input type="radio" name="cat2_service_hours" value="<?= $hrs ?>" <?= $prefillCat2Hours === $hrs ? 'checked' : '' ?> onchange="toggleCommunityHoursCustom()">
                            <span><?= $hrs ?> hrs</span>
                          </label>
                        <?php endforeach; ?>
                        <label class="cat2-hour-pill cat2-hours-other">
                          <input type="radio" name="cat2_service_hours" value="OTHER" <?= !empty($prefillCat2Hours) && !in_array($prefillCat2Hours, ['100','150','200','250','300','350','400','450','500']) ? 'checked' : '' ?> onchange="toggleCommunityHoursCustom()">
                          <span>Other</span>
                        </label>
                      </div>
                      <input type="number" min="1" name="cat2_service_hours_custom" id="cat2_service_hours_custom" class="form-control"
                             placeholder="Custom hours" style="display:<?= !empty($prefillCat2Hours) && !in_array($prefillCat2Hours, ['100','150','200','250','300','350','400','450','500']) ? 'block' : 'none' ?>;margin-top:.4rem"
                             value="<?= htmlspecialchars(!empty($prefillCat2Hours) && !in_array($prefillCat2Hours, ['100','150','200','250','300','350','400','450','500']) ? $prefillCat2Hours : '') ?>">
                      <p style="font-size:.7rem;color:var(--ink-500);margin:.35rem 0 0">Use this only if the required hours are not in the list above.</p>
                    </div>
                    <label class="cb-item" style="margin-bottom:.35rem">
                      <input type="checkbox" name="cat2_counseling" value="1" <?= in_array('Referral for Counseling', $prefillCat2Interventions) ? 'checked' : '' ?>>
                      Referral for Counseling
                    </label>
                    <label class="cb-item" style="margin-bottom:.35rem">
                      <input type="checkbox" name="cat2_lectures" value="1" <?= in_array('Attendance to lectures', $prefillCat2Interventions) ? 'checked' : '' ?>>
                      Attendance to lectures in Discipline Education Program
                    </label>
                    <label class="cb-item">
                      <input type="checkbox" name="cat2_evaluation" value="1" <?= in_array('Evaluation', $prefillCat2Interventions) ? 'checked' : '' ?>>
                      Evaluation
                    </label>
                  </div>

                  <!-- Cat 3/4/5 -->
                  <div id="cat345Fields" style="display:none">
                    <div class="alert alert-warning" style="margin:0">
                      <strong>⚠️ Student account will be frozen.</strong>
                      <span id="cat345Text"></span>
                    </div>
                  </div>
                </div>
              </div>

                <?php if (!$consensusCategory): ?>
                  <div class="form-group">
                    <label style="display:flex;align-items:center;gap:.75rem;cursor:pointer;font-size:.82rem;font-weight:600">
                      <input type="checkbox" name="force_resolve" id="force_resolve" value="1" style="width:auto" onchange="toggleForceResolve()">
                      Force final decision without panel consensus
                    </label>
                  </div>
                <?php endif; ?>

                <div class="form-group">
                  <label class="form-label">Final Decision Narrative (Optional if panel provided no details)</label>
                  <textarea name="final_decision" id="final_decision" rows="4" class="form-control" <?= !$consensusCategory ? 'disabled' : '' ?>
                    placeholder="Enter the formal decision narrative…"><?= htmlspecialchars($postedFinalDecision ?: $suggestedDescription) ?></textarea>
                </div>

                <button type="submit" id="submit_final_decision" class="btn btn-success btn-full" <?= !$consensusCategory ? 'disabled' : '' ?>>📝 Record Final Decision &amp; Close Case</button>
              </form>
              
              <script>
              function toggleForceResolve() {
                  const cb = document.getElementById('force_resolve');
                  if (!cb) return; // If there is a consensus, the checkbox doesn't exist, so don't run this logic!
                  
                  const isChecked = cb.checked;
                  document.getElementById('decided_category').disabled = !isChecked;
                  document.getElementById('final_decision').disabled = !isChecked;
                  document.getElementById('submit_final_decision').disabled = !isChecked;
                  
                  // Disable or enable category dynamic fields based on selection
                  const terms = document.getElementById('cat1_terms');
                  if (terms) terms.disabled = !isChecked;
                  
                  document.querySelectorAll('input[name^="cat2_"]').forEach(el => {
                      el.disabled = !isChecked;
                  });
              }
              document.addEventListener('DOMContentLoaded', toggleForceResolve);
              </script>

              <?php else: ?>
              <!-- Case is CLOSED -->
              <hr class="divider">
              <div class="consensus-box">
                <span>🏁</span>
                Case closed — Final decision: <strong>Category <?= (int)$case['decided_category'] ?></strong>
              </div>
              <?php if (!empty($case['final_decision'])): ?>
                <div class="summary-box"><?= nl2br(htmlspecialchars($case['final_decision'])) ?></div>
              <?php endif; ?>
              <?php if (!empty($case['resolution_date'])): ?>
                <p style="font-size:.75rem;color:var(--ink-400)">Resolved on <?= fmt($case['resolution_date']) ?></p>
              <?php endif; ?>
              <?php endif; ?>

            <?php endif; /* end $hasPanel */ ?>
          </div>
        </div>

        <!-- ════════════════════════════════════════════════════════════
             CARD 2 — Live Case Chat
        ════════════════════════════════════════════════════════════ -->
        <?php if ($hasPanel): ?>
        <div class="card" id="chat-card">
          <div class="card-header"><span class="card-title">Live Case Chat</span></div>
          <div class="card-body">
            <div id="live-chat-box" style="height:300px;overflow-y:auto;background:var(--ink-50);
              border:1px solid var(--border-light);border-radius:var(--radius-md);padding:1rem;margin-bottom:1rem">
              <div style="text-align:center;color:var(--ink-400);font-size:.8rem">Loading messages…</div>
            </div>
            <div id="replying-to-container" style="display:none;background:var(--blue-50);padding:.5rem;
              border-radius:var(--radius-md) var(--radius-md) 0 0;border:1px solid var(--blue-100);
              border-bottom:none;font-size:.75rem;color:var(--blue-700)">
              <strong>Replying to <span id="reply-to-name"></span>:</strong>
              <span id="reply-to-text" style="color:var(--ink-500)"></span>
              <button type="button" class="btn btn-ghost btn-sm" style="float:right;padding:0 .4rem" onclick="cancelReply()">✕</button>
            </div>
            <form id="chat-form" style="display:flex;gap:.5rem">
              <input type="hidden" id="reply_to" name="reply_to" value="">
              <input type="hidden" name="action" value="post_message">
              <input type="hidden" name="case_id" value="<?= $case_id ?>">
              <?php $isHearingOpen = ((int)$case['hearing_is_open'] === 1); ?>
              <input type="text" id="chat_message" name="message" class="form-control" autocomplete="off" 
                     placeholder="<?= $isHearingOpen ? 'Type a message…' : 'Chat disabled until hearing is open…' ?>" 
                     required style="flex:1" <?= !$isHearingOpen ? 'disabled' : '' ?>>
              <button type="submit" class="btn btn-primary" <?= !$isHearingOpen ? 'disabled' : '' ?>>Send</button>
            </form>
          </div>
        </div>
        <?php endif; ?>

        <!-- ════════════════════════════════════════════════════════════
             CARD 3 — Student & Offense Details
        ════════════════════════════════════════════════════════════ -->
        <div class="card" id="student-card">
          <div class="card-header">
            <span class="card-title">Student &amp; Offense Details</span>
            <span class="pill pill-neutral" style="font-size:.63rem"><?= count($offenses) ?> offense<?= count($offenses) !== 1 ? 's' : '' ?></span>
          </div>
          <div class="card-body">
            <div class="section-label">Student Information</div>
            <div class="meta-grid">
              <span class="meta-key">Name</span>      <span class="meta-val" style="font-weight:500"><?= htmlspecialchars($case['student_name']) ?></span>
              <span class="meta-key">Student ID</span> <span class="meta-val" style="font-family:var(--mono);font-size:.78rem"><?= htmlspecialchars($case['student_id']) ?></span>
              <span class="meta-key">Program</span>   <span class="meta-val"><?= htmlspecialchars($case['program'] ?? '—') ?></span>
              <span class="meta-key">School</span>    <span class="meta-val"><?= htmlspecialchars($case['school'] ?? '—') ?></span>
              <span class="meta-key">Year / Sec</span><span class="meta-val"><?= htmlspecialchars($case['year_level'] ?? '—') ?> · <?= htmlspecialchars($case['section'] ?? '—') ?></span>
              <span class="meta-key">Email</span>     <span class="meta-val" style="color:var(--blue-600)"><?= htmlspecialchars($case['student_email'] ?? '—') ?></span>
              <?php if (!empty($case['phone_number'])): ?>
                <span class="meta-key">Phone</span><span class="meta-val"><?= htmlspecialchars($case['phone_number']) ?></span>
              <?php endif; ?>
            </div>
            <hr class="divider">
            <div class="section-label">Case Summary</div>
            <div class="summary-box"><?= nl2br(htmlspecialchars($case['case_summary'] ?? 'No summary provided.')) ?></div>
            <?php if (!empty($offenses)): ?>
              <hr class="divider">
              <div class="section-label">Offenses in this Case</div>
              <?php foreach ($offenses as $off): ?>
                <div class="offense-item <?= ($off['level'] ?? '') === 'MAJOR' ? 'major' : '' ?>">
                  <div class="offense-top">
                    <span class="stag <?= ($off['level'] ?? '') === 'MAJOR' ? 'stag-major' : 'stag-minor' ?>"><?= htmlspecialchars($off['level'] ?? 'MINOR') ?></span>
                    <span class="offense-code"><?= htmlspecialchars($off['code'] ?? '') ?></span>
                    <span class="offense-name"><?= htmlspecialchars($off['offense_name'] ?? '') ?></span>
                  </div>
                  <div class="offense-meta">
                    <?php if (!empty($off['date_committed'])): ?><span>📅 <?= fmtd($off['date_committed']) ?></span><?php endif; ?>
                    <?php if (!empty($off['intervention_first'])): ?><span>1st: <?= htmlspecialchars($off['intervention_first']) ?></span><?php endif; ?>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

      </div><!-- /.case-grid -->

      <!-- Edit config panel -->
      <?php if ($hasPanel && !$isClosed): ?>
      <div class="edit-panel" id="editPanel">
        <div class="card">
          <div class="card-header">
            <span class="card-title">Edit Hearing Configuration</span>
            <button class="btn btn-ghost btn-sm" onclick="toggleEditPanel()">✕ Cancel</button>
          </div>
          <div class="card-body">
            <div class="alert alert-warning" style="margin-bottom:1rem">⚠️ Saving will reset all existing votes and rounds.</div>
            <form method="post" id="editHearingForm" onsubmit="return validateHearingConfigForm()">
              <input type="hidden" name="action" value="update_hearing_config">
              <div class="form-row" style="grid-template-columns:1fr 1fr 1fr">
                <div class="form-group">
                  <label class="form-label">Lead Department</label>
                  <select name="assigned_department_id" id="reconfig_dept_select" class="form-control" onchange="filterPanelDropdown('reconfig')" required>
                    <?php foreach ($departments as $dept): ?>
                      <option value="<?= $dept['dept_id'] ?>" <?= ($defaultDeptId === (int)$dept['dept_id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($dept['dept_name']) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label">Hearing Date</label>
                  <input type="date" name="hearing_date" class="form-control" value="<?= htmlspecialchars($case['hearing_date'] ?? '') ?>" required>
                </div>
                <div class="form-group">
                  <label class="form-label">Hearing Time</label>
                  <input type="time" name="hearing_time" class="form-control" value="<?= htmlspecialchars(substr($case['hearing_time'] ?? '', 0, 5)) ?>" required>
                </div>
              </div>
              <div class="form-group">
                <label class="form-label" id="reconfig-panel-label">Panel Members (Select from lead department)</label>
                  <div class="panel-select-wrapper">
                      <div id="reconfig-selected-panel-members" class="selected-panel-members"></div>
                      <div style="position:relative;">
                          <input type="text" id="reconfig-panel-member-search" class="panel-member-search" placeholder="Search and click to add panel members..." oninput="filterPanelDropdown('reconfig')" onfocus="showPanelDropdown('reconfig')" onblur="setTimeout(() => hidePanelDropdown('reconfig'), 200)">
                          <div id="reconfig-panel-member-dropdown" class="panel-member-dropdown"></div>
                      </div>
                  </div>
                  <div id="reconfig-hidden-panel-inputs"></div>
              </div>
              <div style="display:flex;gap:.5rem;justify-content:flex-end">
                <button type="button" class="btn btn-outline" onclick="toggleEditPanel()">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Configuration</button>
              </div>
            </form>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div><!-- /.page-body -->
  </main>
</div>

<!-- Consensus Decision Modal -->
<div id="consensusDecisionModal" class="modal-overlay" role="dialog" aria-modal="true">
  <div class="modal-content">
    <h3>Panel Decision Ready</h3>
    <p style="font-size:.85rem;color:var(--ink-600);line-height:1.6">
      The UPCC panel completed live voting and agreed on
      <strong id="consensusDecisionCategory">Category <?= (int)$consensusCategory ?></strong>.
      You can apply their suggested punishment, then edit before final submission.
    </p>
    <ul>
      <li>Live voting has already ended for this round.</li>
      <li>You can still edit category, details, and narrative before saving.</li>
      <li>Final submission remains under Admin control.</li>
    </ul>
    <div class="modal-buttons">
      <button class="btn btn-success" onclick="applyConsensusFromModal()">Apply Suggested Punishment</button>
      <button class="btn btn-outline" onclick="closeConsensusDecisionModal()">Review Manually</button>
    </div>
  </div>
</div>

<!-- Live Voting Modal -->
<div id="liveVotingModal" class="modal-overlay" role="dialog" aria-modal="true">
  <div class="modal-content">
    <h3>Live Panel Voting</h3>
    <p style="font-size:.85rem;color:var(--ink-600);line-height:1.6">
      UPCC panel is currently voting on a suggested penalty for this case.
    </p>
    <ul>
      <li><strong id="liveVotingRound">Round: —</strong></li>
      <li><strong id="liveVotingTimer">Time left: --:--</strong></li>
      <li id="liveVotingTally">In Favor: 0 · Not In Favor: 0 · Pending: 0</li>
      <li id="liveVotingSuggester">Proposed By: —</li>
      <li class="live-voting-detail">
        <span class="detail-pill" id="liveVotingCategory">Category: —</span>
        <span class="detail-pill" id="liveVotingPunishment">Recommended Penalty: —</span>
      </li>
    </ul>
    <div class="modal-buttons">
      <button class="btn btn-outline" onclick="closeLiveVotingModal()">Close</button>
    </div>
  </div>
</div>

<!-- Cancel Consensus Modal -->
<div id="cancelConsensusModal" class="modal-overlay" role="dialog" aria-modal="true">
  <div class="modal-content">
    <h3>Cancel Consensus &amp; Restart Voting</h3>
    <p>This will clear all votes, delete the consensus, and restart a fresh voting round. Panel members will need to vote again.</p>
    <textarea id="cancelReason" rows="3" placeholder="Optional: reason for cancellation…"></textarea>
    <div class="modal-buttons">
      <button class="btn btn-outline" onclick="closeCancelModal()">Go Back</button>
      <button class="btn btn-danger" onclick="submitCancelConsensus()">Confirm — Restart Voting</button>
    </div>
  </div>

    <!-- Confirm Pause Modal -->
    <!-- Rejoin Request Modal -->
    <div id="rejoinRequestModal" class="modal-overlay" role="dialog" aria-modal="true">
      <div class="modal-content" style="max-width:520px">
        <h3>Panel Rejoin Requests</h3>
        <p id="rejoinIntro">One or more panel members are requesting to rejoin the hearing. Choose who to admit.</p>
        <div id="rejoinUsersList" style="max-height:260px;overflow:auto;margin-top:8px;margin-bottom:8px"></div>
        <div class="modal-buttons" style="margin-top:12px">
          <button class="btn btn-outline" onclick="closeRejoinModal()">Dismiss</button>
          <button class="btn btn-primary" onclick="admitAllWaitingUsers()">Admit All</button>
        </div>
      </div>
    </div>

    <div id="confirmPauseModal" class="modal-overlay" role="dialog" aria-modal="true">
      <div class="modal-content">
        <h3>Pause Hearing?</h3>
        <p>The hearing is currently live. If you leave or pause the hearing now, panel members will be prevented from joining or continuing until you resume. Do you want to pause the hearing?</p>
        <div style="margin-top:8px;font-size:13px;color:#666">You can resume the hearing later from this admin panel. Panel members will be notified.</div>
        <div class="modal-buttons" style="margin-top:18px">
          <button class="btn btn-outline" onclick="closeConfirmPauseModal()">Cancel</button>
          <button class="btn btn-danger" onclick="confirmPauseFromModal()">Yes — Pause Hearing</button>
        </div>
      </div>
    </div>
</div>

<script>
// ── CONSTANTS ─────────────────────────────────────────────────────────────
const CASE_ID          = <?= isset($case_id) ? (int)$case_id : 0 ?>;
const IS_HEARING_OPEN  = <?= (!empty($isHearingOpen) ? 'true' : 'false') ?>;
const TOTAL_MEMBERS    = <?= isset($totalPanelMembers) ? (int)$totalPanelMembers : 0 ?>;
const VOTER_COUNT      = <?= isset($voterCount) ? (int)$voterCount : 0 ?>;
const ROUND_ENDS_EPOCH = <?= (isset($roundEndsAt) && $roundEndsAt) ? strtotime($roundEndsAt) : 0 ?>;
const INITIAL_COOLDOWN = <?= isset($cooldownSecs) ? (int)$cooldownSecs : 0 ?>;
const currentPanel     = <?= json_encode($assignedPanelIds ?? []) ?>;
const LIVE_VOTING_SUGGESTION = <?= json_encode($liveVotingSuggestion ?? ['category' => 0, 'details' => []]) ?>;

// ── STATE ─────────────────────────────────────────────────────────────────
let lastChatCount     = 0;
let lastVoteSig       = '';
let _currentPauseState= <?= (!empty($isHearingPaused) ? 'true' : 'false') ?>;
let isAwaitingAdmin   = <?= json_encode($isAwaitingAdmin ?? false) ?>;
let currentConsensus  = <?= isset($consensusCategory) ? (int)$consensusCategory : 0 ?>;
let lastRoundNo       = <?= isset($roundNo) ? (int)$roundNo : 0 ?>;
let caseStatus        = <?= json_encode($case['status'] ?? '') ?>;
let timerInterval     = null;
let cooldownInterval  = null;
let cooldownSecs      = INITIAL_COOLDOWN;
let rejoinSigInit     = false;
let lastRejoinSig     = '';
let shouldShowConsensusModal = false;
let liveVotingModalRound = 0;
let prevRoundActiveState = <?= !empty($isRoundActive) ? 'true' : 'false' ?>;
let prevCooldownActiveState = <?= (isset($cooldownSecs) && (int)$cooldownSecs > 0) ? 'true' : 'false' ?>;

const consensusDetails = <?= json_encode($suggestedVoteDetails) ?>;
const CASE_STATUS = <?= json_encode((string)$case['status']) ?>;
const PAGE_FOCUS = <?= json_encode((string)($_GET['focus'] ?? '')) ?>;
const committeeMembers = <?= json_encode($allActiveMembers) ?>;

// Helper function to escape HTML
function escapeHtml(str) {
  if (str === null || typeof str === 'undefined') return '';
  str = String(str);
  if (str === '') return '';
  return str.replace(/[&<>]/g, function(m) {
    if (m === '&') return '&amp;';
    if (m === '<') return '&lt;';
    if (m === '>') return '&gt;';
    return m;
  }).replace(/[\uD800-\uDBFF][\uDC00-\uDFFF]/g, function(c) {
    return c;
  });
}

// ── DEPARTMENT → MEMBER LOADER (SEARCHABLE MULTISELECT) ─────────────────────────
let selectedPanelMembersHearing = [];
let selectedPanelMembersReconfig = [];

function loadMembersForDepartment(prefix, deptId, initialSelected = []) {
    if (prefix === 'hearing') {
        selectedPanelMembersHearing = Array.isArray(initialSelected) ? initialSelected.map(String) : [];
    } else {
        selectedPanelMembersReconfig = Array.isArray(initialSelected) ? initialSelected.map(String) : [];
    }
    renderSelectedPanelMembers(prefix);
    filterPanelDropdown(prefix);
}

function renderSelectedPanelMembers(prefix) {
    const activeStaff = committeeMembers.filter(m => String(m.is_active) === '1');
    const container = document.getElementById(`${prefix}-selected-panel-members`);
    const hiddenContainer = document.getElementById(`${prefix}-hidden-panel-inputs`);
    if (!container || !hiddenContainer) return;
    
    let html = '';
    let hiddenHtml = '';
    
    const selectedList = prefix === 'hearing' ? selectedPanelMembersHearing : selectedPanelMembersReconfig;
    
    selectedList.forEach(id => {
        const staff = activeStaff.find(m => String(m.upcc_id) === id);
        if (staff) {
            html += `<div class="panel-chip">${escapeHtml(staff.full_name)} <span class="panel-chip-remove" onclick="removePanelMember('${prefix}', '${id}')">×</span></div>`;
            hiddenHtml += `<input type="hidden" name="panel_members[]" value="${id}">`;
        }
    });
    
    container.innerHTML = html;
    hiddenContainer.innerHTML = hiddenHtml;
    
    if (selectedList.length === 0) {
        container.innerHTML = '<div style="font-size:11px;color:var(--ink-400);padding:4px;">No members selected.</div>';
    }
}

function filterPanelDropdown(prefix) {
    const input = document.getElementById(`${prefix}-panel-member-search`);
    const dropdown = document.getElementById(`${prefix}-panel-member-dropdown`);
    let deptSelect = document.getElementById(`${prefix}_dept_select`);
    if (!input || !dropdown || !deptSelect) return;
    
    const query = input.value.toLowerCase().trim();
    const selectedDeptId = deptSelect.value;
    const selectedList = prefix === 'hearing' ? selectedPanelMembersHearing : selectedPanelMembersReconfig;
    
    let availableStaff = committeeMembers.filter(m => String(m.is_active) === '1');
    
    if (selectedDeptId) {
        availableStaff = availableStaff.filter(m => String(m.department_id) === String(selectedDeptId));
    }
    
    availableStaff = availableStaff.filter(m => !selectedList.includes(String(m.upcc_id)));
    
    const filtered = availableStaff.filter(m => 
        (m.full_name && m.full_name.toLowerCase().includes(query)) ||
        (m.role && m.role.toLowerCase().includes(query)) ||
        (m.dept_name && m.dept_name.toLowerCase().includes(query))
    );
    
    if (filtered.length === 0) {
        dropdown.innerHTML = '<div style="padding:10px;font-size:12px;color:var(--ink-400);">No members found for this department.</div>';
    } else {
        let html = '';
        filtered.slice(0, 15).forEach(m => {
            html += `<div class="dropdown-item" onmousedown="addPanelMember('${prefix}', '${m.upcc_id}'); event.preventDefault();">
                        <div class="dropdown-item-title">${escapeHtml(m.full_name)} <span style="font-size:10px;color:var(--blue-800);background:var(--blue-100);padding:2px 6px;border-radius:10px;">${escapeHtml(m.role)}</span></div>
                        <div class="dropdown-item-sub">${escapeHtml(m.dept_name || 'No Department')}</div>
                     </div>`;
        });
        dropdown.innerHTML = html;
    }
}

function addPanelMember(prefix, id) {
    id = String(id);
    const selectedList = prefix === 'hearing' ? selectedPanelMembersHearing : selectedPanelMembersReconfig;
    if (!selectedList.includes(id)) {
        selectedList.push(id);
        renderSelectedPanelMembers(prefix);
        
        const input = document.getElementById(`${prefix}-panel-member-search`);
        if (input) {
            input.value = '';
            input.focus();
        }
        filterPanelDropdown(prefix);
    }
}

function removePanelMember(prefix, id) {
    if (prefix === 'hearing') {
        selectedPanelMembersHearing = selectedPanelMembersHearing.filter(m => m !== String(id));
    } else {
        selectedPanelMembersReconfig = selectedPanelMembersReconfig.filter(m => m !== String(id));
    }
    renderSelectedPanelMembers(prefix);
    filterPanelDropdown(prefix);
}

function showPanelDropdown(prefix) {
    const dropdown = document.getElementById(`${prefix}-panel-member-dropdown`);
    if (dropdown) {
        dropdown.classList.add('show');
        filterPanelDropdown(prefix);
    }
}

function hidePanelDropdown(prefix) {
    const dropdown = document.getElementById(`${prefix}-panel-member-dropdown`);
    if (dropdown) dropdown.classList.remove('show');
}

// Initialise the department selects with change handlers
document.addEventListener('DOMContentLoaded', function() {
    const hearingDept = document.getElementById('hearing_dept_select');
    if (hearingDept) {
        loadMembersForDepartment('hearing', hearingDept.value, currentPanel);
    }
    const reconfigDept = document.getElementById('reconfig_dept_select');
    if (reconfigDept) {
        loadMembersForDepartment('reconfig', reconfigDept.value, currentPanel);
    }

      if (PAGE_FOCUS === 'manage') {
        toggleEditPanel();
      }
});

// ── VOTING TIMER ──────────────────────────────────────────────────────────
function startVotingTimer() {
    if (ROUND_ENDS_EPOCH <= 0) return;
    clearInterval(timerInterval);
    function tick() {
        const rem = Math.max(0, ROUND_ENDS_EPOCH - Math.floor(Date.now() / 1000));
        const m   = Math.floor(rem / 60);
        const s   = rem % 60;
        const disp = String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        const timerEl = document.getElementById('vlbTimer');
        const fillEl  = document.getElementById('vlbTimerFill');
        if (timerEl) {
            timerEl.textContent = disp;
            timerEl.classList.toggle('urgent', rem <= 180);
        }
        if (fillEl) {
            fillEl.style.width = Math.round((rem / 600) * 100) + '%';
            fillEl.style.background = rem > 600 ? 'var(--green-600)' : rem > 180 ? 'var(--amber-500)' : 'var(--red-600)';
        }
        if (rem <= 0) clearInterval(timerInterval);
    }
    tick();
    timerInterval = setInterval(tick, 1000);
}

// ── COOLDOWN TIMER ────────────────────────────────────────────────────────
function startCooldownDisplay(secs) {
    if (secs <= 0) return;
    cooldownSecs = secs;
    let block = document.getElementById('cooldownBlock');
    if (!block) {
        block = document.createElement('div');
        block.id = 'cooldownBlock';
        block.className = 'cooldown-block';
        block.innerHTML = `<div class="cooldown-title">⏳ Voting Cooldown Active</div>
            <div class="cooldown-num" id="cooldownTimer"></div>
            <div style="font-size:.7rem;color:var(--amber-700);margin-top:.25rem">New suggestions are temporarily disabled</div>`;
        const hr = document.querySelector('#hearing-card .divider');
        if (hr) hr.parentNode.insertBefore(block, hr.nextSibling);
    }
    block.style.display = 'block';

    clearInterval(cooldownInterval);
    function tick() {
        const m = Math.floor(cooldownSecs / 60);
        const s = cooldownSecs % 60;
        const el = document.getElementById('cooldownTimer');
        if (el) el.textContent = m + ':' + String(s).padStart(2,'0');
        if (cooldownSecs <= 0) {
            clearInterval(cooldownInterval);
            if (block) block.style.display = 'none';
            const awBox = document.getElementById('awaitingVoteBox');
            if (awBox) awBox.style.display = 'flex';
        }
        cooldownSecs--;
    }
    tick();
    cooldownInterval = setInterval(tick, 1000);
}

// ── CATEGORY FIELDS ───────────────────────────────────────────────────────
function toggleCategoryFields() {
    const cat = document.getElementById('decided_category')?.value;
    const container = document.getElementById('dynamicFieldsContainer');
    const c1   = document.getElementById('cat1Fields');
    const c2   = document.getElementById('cat2Fields');
    const c345 = document.getElementById('cat345Fields');
    const txt  = document.getElementById('cat345Text');
    [c1, c2, c345].forEach(el => { if (el) el.style.display = 'none'; });
    if (!cat || !container) { if (container) container.style.display = 'none'; return; }
    container.style.display = 'block';
    if (cat === '1')      { if (c1) c1.style.display = 'block'; }
    else if (cat === '2') { if (c2) c2.style.display = 'block'; toggleCommunityHours(); }
    else if (['3','4','5'].includes(cat)) {
        if (c345) c345.style.display = 'block';
        const msgs = { '3': ' The student will be denied re-enrollment next term.', '4': ' The student will be dropped from the roll.', '5': ' The student will be permanently expelled.' };
        if (txt) txt.textContent = msgs[cat] || '';
    }
}
function toggleCommunityHours() {
    const cb  = document.getElementById('cat2_university_service');
    const box = document.getElementById('communityHoursBox');
    if (cb && box) box.style.display = cb.checked ? 'block' : 'none';
    toggleCommunityHoursCustom();
}
function toggleCommunityHoursCustom() {
  const cus = document.getElementById('cat2_service_hours_custom');
  const other = document.querySelector('input[name="cat2_service_hours"][value="OTHER"]');
  const selected = document.querySelector('input[name="cat2_service_hours"]:checked');
  if (!cus) return;
  const isCustom = !!selected && selected.value === 'OTHER';
  cus.style.display = isCustom ? 'block' : 'none';
  cus.required = isCustom;
  if (isCustom) setTimeout(() => cus.focus(), 0);
  if (other) other.closest('.cat2-hour-pill')?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
}

// ── AUTO-FILL FORM FROM CONSENSUS ─────────────────────────────────────────
function adoptSuggestedPenalty() {
    if (!currentConsensus) return;

    const catSel = document.getElementById('decided_category');
    if (catSel) { catSel.value = currentConsensus; toggleCategoryFields(); }

    const narrative = document.getElementById('final_decision');
    if (narrative && consensusDetails.description) narrative.value = consensusDetails.description;

    const useSug = document.getElementById('use_suggested');
    if (useSug) useSug.value = '1';

    if (currentConsensus == 1) {
        const termsSel = document.getElementById('cat1_terms');
        if (termsSel && consensusDetails.probation_terms) termsSel.value = consensusDetails.probation_terms;

    } else if (currentConsensus == 2) {
        const intervs = consensusDetails.interventions || [];
        const svc = document.getElementById('cat2_university_service');
        if (svc) { svc.checked = intervs.includes('University Service'); toggleCommunityHours(); }
        if (svc?.checked && consensusDetails.service_hours) {
            const hrs    = String(consensusDetails.service_hours);
            const hCus   = document.getElementById('cat2_service_hours_custom');
            const known  = ['100','200','300','400','500'];
        const radio  = document.querySelector(`input[name="cat2_service_hours"][value="${hrs}"]`);
        if (radio && known.includes(hrs)) { radio.checked = true; }
        else {
          const other = document.querySelector('input[name="cat2_service_hours"][value="OTHER"]');
          if (other) other.checked = true;
          if (hCus) { hCus.style.display = 'block'; hCus.value = hrs; }
            }
        toggleCommunityHoursCustom();
        }
        const coun = document.getElementById('cat2_counseling');       if (coun) coun.checked = intervs.includes('Referral for Counseling');
        const lec  = document.getElementById('cat2_lectures');          if (lec)  lec.checked  = intervs.includes('Attendance to lectures');
        const ev   = document.getElementById('cat2_evaluation');        if (ev)   ev.checked   = intervs.includes('Evaluation');
    }

    document.getElementById('finalDecisionForm')?.scrollIntoView({ behavior:'smooth', block:'start' });
    showToast('Form Auto-filled', 'Suggested penalty has been loaded. Review and submit.', 'success');
}

// ── CANCEL CONSENSUS MODAL ────────────────────────────────────────────────
function showCancelConsensusModal() { document.getElementById('cancelConsensusModal').classList.add('open'); }
function closeCancelModal()         { document.getElementById('cancelConsensusModal').classList.remove('open'); document.getElementById('cancelReason').value = ''; }
function submitCancelConsensus() {
    const reason = document.getElementById('cancelReason').value;
    const form = document.createElement('form');
    form.method = 'POST';
    Object.entries({ action: 'cancel_consensus', cancel_reason: reason }).forEach(([k, v]) => {
        const inp = document.createElement('input'); inp.type = 'hidden'; inp.name = k; inp.value = v;
        form.appendChild(inp);
    });
    document.body.appendChild(form);
    form.submit();
}

function openConsensusDecisionModal(category) {
    if (sessionStorage.getItem(`upccConsensusSeen_${CASE_ID}_${lastRoundNo}`) === '1') return;
    const modal = document.getElementById('consensusDecisionModal');
    const catEl = document.getElementById('consensusDecisionCategory');
    if (catEl) catEl.textContent = 'Category ' + category;
    if (modal) modal.classList.add('open');
}
function closeConsensusDecisionModal() {
    document.getElementById('consensusDecisionModal')?.classList.remove('open');
    sessionStorage.setItem(`upccConsensusSeen_${CASE_ID}_${lastRoundNo}`, '1');
}
function applyConsensusFromModal() {
    adoptSuggestedPenalty();
    closeConsensusDecisionModal();
}
function openLiveVotingModal() {
    document.getElementById('liveVotingModal')?.classList.add('open');
}
function closeLiveVotingModal() {
    document.getElementById('liveVotingModal')?.classList.remove('open');
}
function updateLiveVotingModal(data) {
    const roundNo = parseInt(data?.round?.round_no || 0, 10);
    const remaining = Math.max(0, parseInt(data?.round?.remaining_seconds || 0, 10));
    const m = Math.floor(remaining / 60);
    const s = remaining % 60;
    const disp = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');

    const votes = Array.isArray(data?.votes) ? data.votes : [];
    const suggId = parseInt(data?.round?.suggested_by || 0, 10);
    let agree = 0, disagree = 0;
    votes.forEach(v => {
        const uid = parseInt(v.upcc_id, 10);
        if (uid === suggId) return;
        if (parseInt(v.vote_category, 10) > 0) agree++;
        else disagree++;
    });
    const pending = Math.max(0, VOTER_COUNT - agree - disagree);
    const sugg = votes.find(v => parseInt(v.upcc_id, 10) === suggId);
    const suggName = sugg?.full_name || 'Panel member';

    const roundEl = document.getElementById('liveVotingRound');
    const timerEl = document.getElementById('liveVotingTimer');
    const tallyEl = document.getElementById('liveVotingTally');
    const suggEl = document.getElementById('liveVotingSuggester');
    const catEl = document.getElementById('liveVotingCategory');
    const punEl = document.getElementById('liveVotingPunishment');
    if (roundEl) roundEl.textContent = `Round: ${roundNo || '-'}`;
    if (timerEl) timerEl.textContent = `Time left: ${disp}`;
    if (tallyEl) tallyEl.textContent = `In Favor: ${agree} · Not In Favor: ${disagree} · Pending: ${pending}`;
    if (suggEl) suggEl.textContent = `Proposed By: ${suggName}`;
    if (catEl || punEl) renderLiveVotingSuggestion(data, catEl, punEl);
}

  function renderLiveVotingSuggestion(data, catEl, punEl) {
    const rounds = Array.isArray(data?.votes) ? data.votes : [];
    const suggId = parseInt(data?.round?.suggested_by || 0, 10);
    const suggVote = rounds.find(v => parseInt(v.upcc_id, 10) === suggId) || null;
    const roundIsActive = parseInt(data?.round?.is_active || 0, 10) === 1;
    const cat = parseInt(suggVote?.vote_category || 0, 10);
    let details = {};
    if (suggVote?.vote_details) {
      try { details = typeof suggVote.vote_details === 'string' ? JSON.parse(suggVote.vote_details) : suggVote.vote_details; }
      catch (e) {}
    }
    if (!roundIsActive || cat <= 0) {
    if (catEl) catEl.textContent = 'Category: —';
    if (punEl) punEl.textContent = 'Recommended Penalty: Waiting for proposal';
    return;
  }
  const categoryLabel = cat > 0 ? `Category ${cat}` : 'Category: —';
  let punishmentLabel = 'No suggestion details available';

    if (cat === 1) {
      const terms = parseInt(details.probation_terms || 0, 10);
      punishmentLabel = terms > 0 ? `Recommended Penalty: Probation for ${terms} term${terms > 1 ? 's' : ''}` : 'Recommended Penalty: Probation';
    } else if (cat === 2) {
      const interventions = Array.isArray(details.interventions) ? details.interventions : [];
      const serviceHours = parseInt(details.service_hours || 0, 10);
      const bits = [];
      if (interventions.includes('University Service')) bits.push(serviceHours > 0 ? `Community Service (${serviceHours} hrs)` : 'Community Service');
      if (interventions.includes('Referral for Counseling')) bits.push('Counseling');
      if (interventions.includes('Attendance to lectures')) bits.push('Lectures');
      if (interventions.includes('Evaluation')) bits.push('Evaluation');
      punishmentLabel = bits.length ? `Recommended Penalty: ${bits.join(' · ')}` : 'Recommended Penalty: Formative Intervention';
    } else if (cat === 3) {
      punishmentLabel = 'Recommended Penalty: Non-Readmission — account will be restricted';
    } else if (cat === 4) {
      punishmentLabel = 'Recommended Penalty: Exclusion — account will be restricted';
    } else if (cat === 5) {
      punishmentLabel = 'Recommended Penalty: Expulsion — account permanently restricted';
    }

    if (catEl) catEl.textContent = categoryLabel;
    if (punEl) punEl.textContent = punishmentLabel;
}

// ── CHAT RENDERING ────────────────────────────────────────────────────────
function renderChat(messages) {
    const box = document.getElementById('live-chat-box');
    if (!box) return;
    if (!messages || !messages.length) { box.innerHTML = '<div style="text-align:center;color:var(--ink-400);font-size:.8rem">No messages yet.</div>'; return; }
    const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 60;
    box.innerHTML = messages.map(m => {
        if (m.is_system) return `<div style="text-align:center;margin:10px 0">
            <span style="background:rgba(240,192,64,.15);color:#db9f00;border:1px solid rgba(240,192,64,.4);padding:4px 10px;border-radius:12px;font-size:11px;font-weight:700">${escapeHtml(m.message)}</span>
        </div>`;
        const isMe   = !!m.is_me;
        const isAdm  = !!m.is_admin;
        const bg     = isAdm ? (isMe ? 'var(--blue-600)' : 'var(--blue-100)') : (isMe ? 'var(--ink-800)' : '#fff');
        const clr    = isMe ? '#fff' : 'var(--ink-800)';
        let rep = '';
        if (m.reply_to_id) rep = `<div style="background:rgba(0,0,0,.08);padding:.3rem .5rem;border-radius:4px;margin-bottom:.4rem;font-size:.7rem;border-left:2px solid rgba(0,0,0,.2)">
            <strong>${escapeHtml(m.reply_sender)}</strong> ${escapeHtml(m.reply_message)}</div>`;
        return `<div style="margin-bottom:1rem;text-align:${isMe ? 'right' : 'left'}">
            <div style="font-size:.7rem;color:var(--ink-500);margin-bottom:.2rem"><strong>${escapeHtml(m.sender_name)}</strong> · ${escapeHtml(m.sender_role)} · ${m.created_at}</div>
            <div style="display:inline-block;text-align:left;background:${bg};color:${clr};padding:.6rem .8rem;border-radius:12px;max-width:85%;font-size:.82rem;border:1px solid rgba(0,0,0,.05)">
                ${rep}${escapeHtml(m.message)}
            </div>
        </div>`;
    }).join('');
    if (atBottom) box.scrollTop = box.scrollHeight;
}

function setReply(id, name, text) {
    document.getElementById('reply_to').value = id;
    document.getElementById('reply-to-name').textContent = name;
    document.getElementById('reply-to-text').textContent = text.length > 60 ? text.substring(0,60)+'…' : text;
    document.getElementById('replying-to-container').style.display = 'block';
    document.getElementById('chat_message')?.focus();
}
function cancelReply() {
    document.getElementById('reply_to').value = '';
    document.getElementById('replying-to-container').style.display = 'none';
}

function validateHearingConfigForm() {
    const dept = document.getElementById('reconfig_dept_select').value;
    if (!dept) {
        alert('Please select a lead department.');
        return false;
    }
    if (selectedPanelMembers.length === 0) {
        alert('Please assign at least one panel member from the dropdown.');
        return false;
    }
    const hDate = document.querySelector('input[name="hearing_date"]').value;
    if (!hDate) {
        alert('Please select a hearing date.');
        return false;
    }
    const hTime = document.querySelector('input[name="hearing_time"]').value;
    if (!hTime) {
        alert('Please select a hearing time.');
        return false;
    }
    return true;
}

const chatForm = document.getElementById('chat-form');
if (chatForm) {
    chatForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const fd = new FormData(this);
        fd.append('actor', 'admin');
        fetch('../api/upcc_case_live.php', { method:'POST', body:fd })
            .then(r => r.json())
            .then(res => { if (res.ok) { document.getElementById('chat_message').value = ''; cancelReply(); syncLive(); } })
            .catch(err => console.error(err));
    });
}

// ── HEARING PAUSE TOGGLE ──────────────────────────────────────────────────
function updatePauseUI(isPaused, pauseReason = null) {
    const btn = document.getElementById('togglePauseBtn');
    const status = document.getElementById('hearing-status-pill');
    if (!btn) return;
    
    if (isPaused) {
        btn.classList.remove('btn-warning');
        btn.classList.add('btn-success');
        btn.innerHTML = '▶️ Resume Hearing';
        if (status) {
            status.classList.remove('pill-open');
            status.classList.add('pill-warning');
        status.innerHTML = '<span class="pill-dot"></span>Hearing Paused' + (pauseReason === 'AUTO_PAUSE_ADMIN_LEFT' ? ' (Admin Disconnected)' : '');
        }
    } else {
        btn.classList.remove('btn-success');
        btn.classList.add('btn-warning');
        btn.innerHTML = '⏸️ Pause Hearing';
        if (status) {
            status.classList.remove('pill-warning');
            status.classList.add('pill-open');
        status.innerHTML = '<span class="pill-dot"></span>Hearing Open';
        }
    }
}

// ── HEARING PAUSE TOGGLE ──────────────────────────────────────────────────
function toggleHearingPause() {
  // If hearing is currently open (not paused), show confirmation modal before pausing
  if (!_currentPauseState) {
    document.getElementById('confirmPauseModal').classList.add('open');
    return;
  }

  // Otherwise (currently paused) resume immediately
  const fd = new FormData();
  fd.append('action', 'toggle_pause');
  fd.append('actor', 'admin');
    
  fetch(`../api/upcc_case_live.php?case_id=${CASE_ID}&actor=admin`, { method:'POST', body:fd })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        _currentPauseState = res.is_paused ? true : false;
        updatePauseUI(_currentPauseState);
        syncLive();
      } else {
        alert('Error: ' + (res.error || 'Failed to toggle pause'));
      }
    })
    .catch(err => {
      console.error(err);
      alert('Network error while toggling pause');
    });
}

function closeConfirmPauseModal() {
  document.getElementById('confirmPauseModal').classList.remove('open');
}

function confirmPauseFromModal() {
  // perform the pause action
  closeConfirmPauseModal();
  const fd = new FormData();
  fd.append('action', 'toggle_pause');
  fd.append('actor', 'admin');
  fetch(`../api/upcc_case_live.php?case_id=${CASE_ID}&actor=admin`, { method:'POST', body:fd })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        _currentPauseState = res.is_paused ? true : false;
        updatePauseUI(_currentPauseState);
        syncLive();
      } else {
        alert('Error: ' + (res.error || 'Failed to pause hearing'));
      }
    })
    .catch(err => {
      console.error(err);
      alert('Network error while pausing hearing');
    });
}

// If the admin attempts to close or navigate away while hearing is live, warn and try to pause via sendBeacon
window.addEventListener('beforeunload', function (e) {
  if (!_currentPauseState && IS_HEARING_OPEN) {
    const msg = 'The hearing is live. Leaving will pause the hearing. Are you sure you want to leave?';
    (e || window.event).returnValue = msg; // Gecko + IE
    return msg; // Webkit, Safari, Chrome
  }
});

window.addEventListener('unload', function () {
  try {
    if (!_currentPauseState && IS_HEARING_OPEN && navigator.sendBeacon) {
      const url = `../api/upcc_case_live.php?case_id=${CASE_ID}&actor=admin`;
      const params = new URLSearchParams();
      params.append('action', 'toggle_pause');
      params.append('actor', 'admin');
      navigator.sendBeacon(url, params.toString());
    }
  } catch (e) { /* ignore */ }
});

function normalizePauseState(value) {
  return value === true || value === 1 || value === '1' || value === 'true';
}


function admitUser(upccId) {
    fetch(`../api/upcc_case_live.php?action=admit_user&case_id=${CASE_ID}&upcc_id=${upccId}&actor=admin`)
        .then(r => r.json())
        .then(res => { if (res.ok) syncLive(); else alert('Failed: ' + (res.error || 'Unknown')); })
        .catch(err => console.error(err));
}

function admitAllWaitingUsers() {
  const buttons = Array.from(document.querySelectorAll('#rejoinUsersList button[data-upcc]'));
  if (!buttons.length) return closeRejoinModal();
  const ids = buttons.map(b => b.getAttribute('data-upcc'));
  (function admitNext(i) {
    if (i >= ids.length) { syncLive(); closeRejoinModal(); return; }
    admitUser(ids[i]);
    setTimeout(() => admitNext(i+1), 250);
  })(0);
}

function showRejoinModal(users) {
  const modal = document.getElementById('rejoinRequestModal');
  const list = document.getElementById('rejoinUsersList');
  if (!modal || !list) return;
  if (!Array.isArray(users) || users.length === 0) { return closeRejoinModal(); }
  list.innerHTML = users.map(u => `
    <div style="display:flex;justify-content:space-between;align-items:center;padding:8px;border-bottom:1px solid #eee">
      <div style="font-size:13px">👤 ${escapeHtml(u.name)}<div style="font-size:11px;color:#666">${escapeHtml(u.role || '')}</div></div>
      <div style="display:flex;gap:8px;align-items:center">
        <button class="btn btn-outline" style="padding:6px 10px;font-size:12px" onclick="closeRejoinModal();">Dismiss</button>
        <button class="btn btn-primary" data-upcc="${u.upcc_id}" style="padding:6px 10px;font-size:12px" onclick="admitUser(${u.upcc_id});">Let In</button>
      </div>
    </div>
  `).join('');
  modal.classList.add('open');
}

function closeRejoinModal() {
  const modal = document.getElementById('rejoinRequestModal');
  if (!modal) return;
  modal.classList.remove('open');
}

// ── PRESENCE PING ─────────────────────────────────────────────────────────
function pingPresence() {
    const fd = new FormData();
    fd.append('action', 'ping_presence');
    fd.append('status', 'ADMITTED');
    fd.append('actor', 'admin');
    fetch(`../api/upcc_case_live.php?case_id=${CASE_ID}&actor=admin`, { method:'POST', body:fd }).catch(() => {});
}

function voteSig(votes) {
    return (votes || []).map(v => v.upcc_id + ':' + v.vote_category + ':' + v.updated_at).join('|');
}

// ── MAIN LIVE SYNC ────────────────────────────────────────────────────────
function syncLive() {
  fetch(`../api/upcc_case_live.php?case_id=${CASE_ID}&actor=admin&t=${Date.now()}`, {
    cache: 'no-store'
  })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;
            const roundActiveNow = !!(data.round && parseInt(data.round.is_active, 10) === 1);
            const cooldownActiveNow = !!(data.cooldown && parseInt(data.cooldown_seconds || 0, 10) > 0);

            // Chat
            if (Array.isArray(data.chat) && data.chat.length !== lastChatCount) {
                renderChat(data.chat);
                lastChatCount = data.chat.length;
                const cb = document.getElementById('live-chat-box');
                if (cb) cb.scrollTop = cb.scrollHeight;
            }

            // Student Explanation Update
            if (data.student_explanation && data.student_explanation.submitted_at) {
                const block = document.getElementById('studentExplanationBlock');
                const text = document.getElementById('explanationText');
                const time = document.getElementById('explanationTime');
                const btnStart = document.getElementById('btnStartHearing');
                const needsExp = data.case_kind === 'MAJOR_OFFENSE' || data.case_kind === 'SECTION4_MINOR_ESCALATION';
                
                if (block && block.style.display === 'none') {
                    block.style.display = 'block';
                    if (text) text.textContent = data.student_explanation.text || '';
                    if (time) time.textContent = 'Submitted ' + data.student_explanation.submitted_at;
                    
                    // Attachments handling
                    const attachments = document.getElementById('explanationAttachments');
                    if (attachments) {
                        attachments.innerHTML = '';
                        if (data.student_explanation.image) {
                            attachments.innerHTML += `<a href="../${data.student_explanation.image}" target="_blank" style="display: block; border-radius: 8px; overflow: hidden; border: 1px solid #e2e8f0;">
                                <img src="../${data.student_explanation.image}" style="max-width: 100px; max-height: 100px; display: block; object-fit: cover;">
                            </a>`;
                        }
                        if (data.student_explanation.pdf) {
                            attachments.innerHTML += `<a href="../${data.student_explanation.pdf}" target="_blank" style="display: flex; align-items: center; gap: 8px; padding: 8px 12px; background: #fff1f2; border: 1px solid #fecaca; border-radius: 8px; text-decoration: none; color: #be123c; font-size: 12px; font-weight: 600;">
                                <span>📄 View PDF Explanation</span>
                            </a>`;
                        }
                    }
                    
                    // Enable start button if it was blocked
                    if (btnStart && needsExp) {
                        btnStart.disabled = false;
                        const warn = btnStart.nextElementSibling;
                        if (warn && warn.tagName === 'DIV') warn.remove();
                    }
                }
            }

            // Votes
            if (Array.isArray(data.votes)) {
                const sig = voteSig(data.votes);
                if (sig !== lastVoteSig) {
                    lastVoteSig = sig;

                    let ag = 0, di = 0;
                    const sugId = data.round ? parseInt(data.round.suggested_by || 0, 10) : 0;
                    data.votes.forEach(v => {
                        if (parseInt(v.upcc_id, 10) === sugId) return;
                        if (parseInt(v.vote_category, 10) > 0) ag++;
                        else di++;
                    });
                    const pen = Math.max(0, VOTER_COUNT - ag - di);
                    const ta = document.getElementById('tallyAgree');    if (ta) ta.textContent = ag;
                    const td = document.getElementById('tallyDisagree'); if (td) td.textContent = di;
                    const tp = document.getElementById('tallyPending');  if (tp) tp.textContent = pen;
                }
            }

                // Consensus should surface immediately, even if the round is already closing.
                const isCaseClosed = (data.case_status === 'CLOSED' || data.case_status === 'RESOLVED') || (caseStatus === 'CLOSED' || caseStatus === 'RESOLVED') || data.is_closed;
                const hasConsensus = parseInt(data.consensus || 0, 10) > 0 && !isCaseClosed;
                if (hasConsensus) {
                  if (!isAwaitingAdmin) {
                    isAwaitingAdmin  = true;
                    currentConsensus = data.consensus;
                    sessionStorage.setItem(`upccConsensusOpen_${CASE_ID}`, '1');
                    showToast('✅ Consensus Reached!', `Panel agreed on Category ${data.consensus}. Review and record the final decision.`, 'success');
                    setTimeout(() => location.reload(), 1200);
                  }
                  openConsensusDecisionModal(data.consensus);
                }

            // Live voting modal for active rounds
            if (roundActiveNow) {
                updateLiveVotingModal(data);
                const rno = parseInt(data.round.round_no || 0, 10);
                if (rno > 0 && rno !== liveVotingModalRound) {
                    liveVotingModalRound = rno;
                    openLiveVotingModal();
                    showToast('Voting Round Started', `Panel voting is now active for round ${rno}.`, 'info');
                }
            } else {
                closeLiveVotingModal();
            }

            // New round started
            if (data.round && data.round.round_no) {
                const newRound = parseInt(data.round.round_no, 10);
                const isActive = parseInt(data.round.is_active, 10) === 1;
                if (newRound > lastRoundNo && isActive) {
                    lastRoundNo = newRound;
                    showToast('Penalty Proposed', 'A panel member submitted a proposal. Voting has started.', 'info');
                    setTimeout(() => location.reload(), 1500);
                }
            }

            // Cooldown
            if (data.cooldown && parseInt(data.cooldown_seconds || 0, 10) > 0 && cooldownSecs <= 0) {
                startCooldownDisplay(parseInt(data.cooldown_seconds, 10));
            }

            // Transition handling: keep admin panel in sync on cancel/end/cooldown changes
            if (prevRoundActiveState !== roundActiveNow || prevCooldownActiveState !== cooldownActiveNow) {
                prevRoundActiveState = roundActiveNow;
                prevCooldownActiveState = cooldownActiveNow;
                if (!roundActiveNow || cooldownActiveNow) {
                    setTimeout(() => location.reload(), 600);
                    return;
                }
            }

              if (hasConsensus && !roundActiveNow) {
                closeLiveVotingModal();
              }

            // Status change
            if (data.case_status && data.case_status !== caseStatus) {
                caseStatus = data.case_status;
                location.reload();
            }

            // Pause state handling
            const nextPauseState = normalizePauseState(data.is_paused);
            if (data.is_paused !== undefined && nextPauseState !== _currentPauseState) {
              _currentPauseState = nextPauseState;
                updatePauseUI(_currentPauseState, data.pause_reason);
                
                // Show toast notification for auto-pause
                if (data.is_paused && data.pause_reason === 'AUTO_PAUSE_ADMIN_LEFT') {
                    showToast('⏸️ Hearing Paused', 'Hearing has been auto-paused: Admin disconnected.', 'warning');
                }
            }

            // Disable End Hearing if voting is ongoing
            const endBtn = document.getElementById('btnEndHearing');
            if (endBtn) {
                endBtn.disabled = roundActiveNow;
                endBtn.title = roundActiveNow ? 'Cannot end hearing while voting is ongoing' : '';
            }

            // Rejoin requests
            const wuCont = document.getElementById('waitingUsersContainer');
            const wuList = document.getElementById('waitingUsersList');
            const badge  = document.getElementById('rejoinBadge');
            if (wuCont && wuList && Array.isArray(data.waiting_users)) {
                const sig = data.latest_rejoin_request_at || '';
                if (!rejoinSigInit) { lastRejoinSig = sig; rejoinSigInit = true; }
                else if (sig && sig !== lastRejoinSig) {
                  lastRejoinSig = sig;
                  if (data.waiting_users.length > 0) {
                    showToast('Rejoin Request', data.waiting_users.map(u => u.name).join(', ') + ' requesting to rejoin.', 'warning');
                    try { showRejoinModal(data.waiting_users); } catch(e) { /* ignore */ }
                  }
                }
                if (data.waiting_users.length > 0) {
                    wuCont.style.display = 'block';
                    if (badge) { badge.textContent = data.waiting_users.length; badge.style.display = 'inline-flex'; }
                    wuList.innerHTML = data.waiting_users.map(u => `
                        <div style="display:flex;justify-content:space-between;align-items:center;background:#fff;padding:6px;margin-bottom:4px;border-radius:4px;border:1px solid #fcd34d">
                            <span style="font-size:12px">👤 ${escapeHtml(u.name)}</span>
                            <button onclick="admitUser(${u.upcc_id})" class="btn btn-primary btn-sm" style="padding:2px 8px;font-size:11px">Let In</button>
                        </div>`).join('');
                } else {
                    wuCont.style.display = 'none';
                    if (badge) badge.style.display = 'none';
                    wuList.innerHTML = '';
                }
            }
        })
        .catch(err => console.warn('[sync]', err));
}

function showToast(title, msg, type = 'info') {
    const t = document.createElement('div');
    t.className = 'toast toast-' + type;
    t.innerHTML = `<div class="toast-title">${escapeHtml(title)}</div><div class="toast-msg">${escapeHtml(msg)}</div>`;
    document.body.appendChild(t);
    setTimeout(() => { t.style.animation = 'slideOutRight .3s ease-in forwards'; setTimeout(() => t.remove(), 300); }, 5000);
}

function toggleEditPanel() {
    const ep = document.getElementById('editPanel');
    if (!ep) return;
    ep.classList.toggle('open');
    if (ep.classList.contains('open')) ep.scrollIntoView({ behavior:'smooth', block:'start' });
}



// ── INIT ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    toggleCategoryFields();
    const shouldOpenAfterRefresh = sessionStorage.getItem(`upccConsensusOpen_${CASE_ID}`) === '1';
    const roundSeen = sessionStorage.getItem(`upccConsensusSeen_${CASE_ID}_${lastRoundNo}`) === '1';
    if ((shouldOpenAfterRefresh || (currentConsensus > 0 && CASE_STATUS === 'AWAITING_ADMIN_FINALIZATION')) && !roundSeen) {
        openConsensusDecisionModal(currentConsensus);
        sessionStorage.removeItem(`upccConsensusOpen_${CASE_ID}`);
    }
});

startVotingTimer();
if (INITIAL_COOLDOWN > 0) startCooldownDisplay(INITIAL_COOLDOWN);

if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();

setInterval(syncLive,    3000);
setInterval(pingPresence, 5000);
syncLive();
</script>
</body>
</html>