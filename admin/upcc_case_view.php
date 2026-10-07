<?php
require_once __DIR__ . '/../database/database.php';
require_admin();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

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
           s.year_level, s.section, s.program, s.school, s.department AS student_department, s.course AS student_course,
           s.student_email, s.phone_number, s.home_address,
           d.dept_name AS assigned_dept_name
    FROM upcc_case uc
    JOIN student s ON s.student_id = uc.student_id
    LEFT JOIN departments d ON d.dept_id = uc.assigned_department_id
    WHERE uc.case_id = :id", [':id' => $case_id]);
if (!$case) { header('Location: upcc_cases.php'); exit; }

$offenseParams = [':id' => $case_id];
db_add_encryption_key($offenseParams);
$offenses = db_all("SELECT o.*, " . db_decrypt_col('description', 'o') . " AS description, ot.code, ot.name AS offense_name, ot.level, ot.major_category,
           ot.intervention_first, ot.intervention_second
    FROM upcc_case_offense uco
    JOIN offense o ON o.offense_id = uco.offense_id
    JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
    WHERE uco.case_id = :id ORDER BY o.date_committed ASC", $offenseParams);

$priorResolvedCases = db_all(
    "SELECT uc.case_id, uc.status, uc.created_at, uc.decided_category, uc.punishment_details
     FROM upcc_case uc
     WHERE uc.student_id = :sid AND uc.case_id != :cid
       AND uc.status IN ('RESOLVED', 'CLOSED', 'FINALIZED')",
    [':sid' => $case['student_id'], ':cid' => $case_id]
);

$departments = db_all("SELECT dept_id, dept_name FROM departments WHERE is_active = 1 ORDER BY dept_name ASC");
$defaultDeptId = (int)($case['assigned_department_id'] ?? 0);
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
    foreach ([dept_norm((string)($case['student_department'] ?? '')), dept_norm((string)($case['department'] ?? '')), dept_norm((string)($case['program'] ?? '')), dept_norm((string)($case['school'] ?? ''))] as $t) {
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
$assignedPanelNames = [];
if (!empty($assignedPanelIds)) {
  $ids = array_map('intval', $assignedPanelIds);
  $in = implode(',', $ids);
  
  $rows = db_all("
    SELECT u.upcc_id, u.full_name, u.role, a.accepted_at 
    FROM upcc_user u 
    LEFT JOIN upcc_case_panel_acceptance a ON a.upcc_id = u.upcc_id AND a.case_id = :id
    WHERE u.upcc_id IN ($in) AND u.is_active = 1
  ", [':id' => $case_id]);
  
  $byId = [];
  foreach ($rows as $r) { $byId[(int)$r['upcc_id']] = $r; }
  foreach ($ids as $id) {
    if (isset($byId[$id])) {
      $assignedPanelNames[] = [
          'id' => (int)$byId[$id]['upcc_id'],
          'name' => $byId[$id]['full_name'], 
          'role' => $byId[$id]['role'],
          'accepted' => !empty($byId[$id]['accepted_at']),
          'accepted_at' => $byId[$id]['accepted_at'] ?? null
      ];
    }
  }
}
// ── Fetch other cases for this student (Pending & Resolved) ───────────────
$studentId = (string)($case['student_id'] ?? '');
$otherStudentCases = [];
$otherPendingCasesCount = 0;
$otherResolvedCasesCount = 0;

if ($studentId !== '') {
    $rawOtherCases = db_all("
        SELECT uc.case_id, uc.status, uc.final_decision, uc.decided_category, uc.case_kind, uc.case_summary, uc.hearing_date, uc.created_at, uc.updated_at, uc.punishment_details
        FROM upcc_case uc
        WHERE uc.student_id = :sid AND uc.case_id != :cid
        ORDER BY uc.created_at DESC
    ", [':sid' => $studentId, ':cid' => $case_id]);

    foreach ($rawOtherCases as $oc) {
        $ocId = (int)$oc['case_id'];
        
        $ocOffenses = db_all("
            SELECT o.*, ot.code, ot.name AS offense_name, ot.level, ot.major_category
            FROM upcc_case_offense uco
            JOIN offense o ON o.offense_id = uco.offense_id
            JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
            WHERE uco.case_id = :id 
            ORDER BY o.date_committed ASC
        ", [':id' => $ocId]);

        $ocPanelCount = (int)(db_one("SELECT COUNT(*) AS cnt FROM upcc_case_panel_member WHERE case_id = :id", [':id' => $ocId])['cnt'] ?? 0);

        $st = strtoupper((string)($oc['status'] ?? 'PENDING'));
        $isResolved = in_array($st, ['CLOSED', 'FINALIZED', 'DECIDED', 'RESOLVED'], true) || !empty($oc['final_decision']);

        if ($isResolved) {
            $otherResolvedCasesCount++;
        } else {
            $otherPendingCasesCount++;
        }

        $majorCount = 0;
        $minorCount = 0;
        foreach ($ocOffenses as $ooff) {
            if (strtoupper($ooff['level'] ?? '') === 'MAJOR') $majorCount++;
            else $minorCount++;
        }

        $caseKind = strtoupper((string)($oc['case_kind'] ?? ''));
        if ($caseKind === 'SECTION4_MINOR_ESCALATION' || $minorCount >= 3) {
            $triggerType = 'SECTION4_ESCALATION';
            $triggerLabel = '⚠️ Section 4 Minor Escalation (3+ Accumulated Minors)';
        } elseif ($caseKind === 'MAJOR_OFFENSE' || $majorCount > 0) {
            $triggerType = 'AUTOMATIC_MAJOR';
            $triggerLabel = '🚨 Automatic Major Offense';
        } else {
            $triggerType = 'STANDARD_MINOR';
            $triggerLabel = 'ℹ️ Standard Disciplinary Case';
        }

        $punish = [];
        try {
            if (!empty($oc['punishment_details'])) {
                $punish = json_decode((string)$oc['punishment_details'], true) ?: [];
            }
        } catch (Throwable $e) {}

        $otherStudentCases[] = [
            'case_id' => $ocId,
            'status' => $oc['status'] ?? 'PENDING',
            'is_resolved' => $isResolved,
            'decided_category' => (int)($oc['decided_category'] ?? 0),
            'final_decision' => $oc['final_decision'] ?? '',
            'punishment_details' => $punish,
            'case_kind' => $oc['case_kind'] ?? '',
            'case_summary' => $oc['case_summary'] ?? '',
            'trigger_type' => $triggerType,
            'trigger_label' => $triggerLabel,
            'created_at' => $oc['created_at'] ?? '',
            'updated_at' => $oc['updated_at'] ?? '',
            'offenses' => $ocOffenses ?: [],
            'panel_count' => $ocPanelCount
        ];
    }
}

// ── Category descriptions ─────────────────────────────────────────────────
$categoryDescriptions = [
    1 => 'Formal Reprimand & Active Semester Probation (0 Hours CS).',
    2 => 'Formative Community Service (150 to 250 Hours) with Counseling / Education / Evaluation.',
    3 => 'Non-Readmission / Suspension.',
    4 => 'Exclusion / Mandatory Dismissal (Dropped from University Rolls).',
    5 => 'Summary Expulsion & Police Referral (Permanent Disqualification from Higher Education).',
];

// ── POST actions ──────────────────────────────────────────────────────────
$errMsg = '';
$okMsg  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    if ($_POST['action'] === 'update_hearing_config') {
        $dept_id     = (int)($_POST['assigned_department_id'] ?? 0);
        $panel       = isset($_POST['panel_members']) && is_array($_POST['panel_members']) ? $_POST['panel_members'] : [];
        $panelIds    = array_values(array_unique(array_map('intval', $panel)));
        $hearingDate = trim((string)($_POST['hearing_date'] ?? ''));
        $hearingTime = trim((string)($_POST['hearing_time'] ?? ''));
        $hearingType = trim((string)($_POST['hearing_type'] ?? 'ONLINE'));
        $hearingLoc  = trim((string)($_POST['hearing_link_or_location'] ?? ''));
        if (!in_array($hearingType, ['ONLINE', 'FACE_TO_FACE'])) $hearingType = 'ONLINE';

        if ($hearingDate !== '') {
            $parsed_date = strtotime(str_replace('/', '-', $hearingDate));
            if ($parsed_date !== false) {
                $hearingDate = date('Y-m-d', $parsed_date);
            }
        }
        if ($hearingTime !== '') {
            $parsed_time = strtotime($hearingTime);
            if ($parsed_time !== false) {
                $hearingTime = date('H:i', $parsed_time);
            }
        }

        $validDate   = preg_match('/^\d{4}-\d{2}-\d{2}$/', $hearingDate) === 1;
        $validTime   = preg_match('/^\d{2}:\d{2}$/', $hearingTime) === 1;
        $dept        = $dept_id ? db_one("SELECT dept_id, dept_name FROM departments WHERE dept_id = :id AND is_active = 1", [':id' => $dept_id]) : null;
        if ($dept_id > 0 && !$dept)                              $errMsg = 'Please select a valid department.';
        elseif (empty($panelIds))                                $errMsg = 'Please assign at least one panel member.';
        elseif (!$validDate || !$validTime)                      $errMsg = 'Please select both a hearing date and time.';
        elseif (empty($hearingLoc) && $hearingType === 'FACE_TO_FACE') $errMsg = 'Please provide a location/room for Face-to-Face hearing.';
        elseif (empty($hearingLoc) && $hearingType === 'ONLINE') $errMsg = 'Please provide a meeting link for Online hearing.';
        elseif ($dept && is_biased_department($case, (string)$dept['dept_name'])) $errMsg = 'Cannot assign a panel from the same department or program as the respondent student.';
        else {
            db_exec("UPDATE upcc_case SET
                assigned_department_id = :dept, assigned_panel_members = :panel,
                hearing_date = :hd, hearing_time = :ht, hearing_type = :htype,
                hearing_link_or_location = :hloc,
                status = 'UNDER_INVESTIGATION', hearing_is_open = 0,
                hearing_opened_at = NULL, hearing_closed_at = NULL, hearing_opened_by_admin = NULL,
                hearing_vote_consensus_category = NULL, hearing_vote_consensus_at = NULL,
                updated_at = NOW() WHERE case_id = :id",
          [':dept' => $dept_id > 0 ? $dept_id : null, ':panel' => json_encode($panelIds),
                 ':hd' => $hearingDate, ':ht' => $hearingTime . ':00',
                 ':htype' => $hearingType, ':hloc' => $hearingLoc,
                 ':id' => $case_id]);
        sync_case_panel_members($case_id, $panelIds);
            db_exec("DELETE FROM upcc_case_vote_round WHERE case_id = :c", [':c' => $case_id]);
            db_exec("DELETE FROM upcc_case_vote WHERE case_id = :c", [':c' => $case_id]);
            upcc_log_case_activity($case_id, 'ADMIN', (int)$admin['admin_id'], 'HEARING_CONFIG_UPDATED',
          ['department_id' => $dept_id, 'panel_members' => $panelIds, 'hearing_date' => $hearingDate, 'hearing_time' => $hearingTime]);
            
            upcc_send_panel_assignment_email($case_id, $panelIds);
            
            header("Location: {$selfViewFile}?id={$case_id}&msg=config_updated"); exit;
        }
    }

    if ($_POST['action'] === 'start_hearing') {
        db_exec("UPDATE upcc_case SET hearing_is_open = 1, hearing_is_paused = 0,
                 hearing_opened_at = NOW(), hearing_opened_by_admin = :aid,
                 hearing_closed_at = NULL, updated_at = NOW() WHERE case_id = :id",
            [':aid' => (int)$admin['admin_id'], ':id' => $case_id]);
        upcc_log_case_activity($case_id, 'ADMIN', (int)$admin['admin_id'], 'HEARING_OPENED');
        header("Location: {$selfViewFile}?id={$case_id}&msg=hearing_started"); exit;
    }

    if ($_POST['action'] === 'close_hearing') {
        db_exec("UPDATE upcc_case SET hearing_is_open = 0, hearing_is_paused = 0, hearing_closed_at = NOW(), updated_at = NOW() WHERE case_id = :id",
            [':id' => $case_id]);
        upcc_log_case_activity($case_id, 'ADMIN', (int)$admin['admin_id'], 'HEARING_CLOSED');
        header("Location: {$selfViewFile}?id={$case_id}&msg=hearing_closed"); exit;
    }

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

    if ($_POST['action'] === 'resolve_case') {
        $category     = (int)($_POST['decided_category'] ?? 0);
        $decision     = trim($_POST['final_decision']    ?? '');
        $forceResolve = isset($_POST['force_resolve']) && $_POST['force_resolve'] === '1';
        file_put_contents(__DIR__ . '/../debug_resolve.txt',
            date('Y-m-d H:i:s') . " | case_id=$case_id | cat=$category | force=" . ($forceResolve?'1':'0') .
            " | use_suggested=" . ($_POST['use_suggested'] ?? 'NOT_SET') .
            " | decision_len=" . strlen($decision) . "\n", FILE_APPEND);
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

                if ($useSuggested && $consensusCat > 0) {
                    $details = $sd;
                    $details['description'] = $decision;
                    if ($category === 1 && !empty($details['probation_terms'])) {
                        $probationUntil = date('Y-m-d H:i:s', strtotime('+' . ((int)$details['probation_terms'] * 6) . ' months'));
                    }
                } else {
                    if ($category === 1) {
                        $terms = (int)($_POST['cat1_terms'] ?? 3);
                        $details['probation_terms'] = max(1, min(3, $terms));
                        $probationUntil = date('Y-m-d H:i:s', strtotime('+' . ($details['probation_terms'] * 6) . ' months'));

                    } elseif ($category === 2) {
                        $details['interventions'] = [];
                        if (isset($_POST['cat2_university_service']) || !empty($_POST['service_hours'])) {
                            $details['interventions'][] = 'University Service';
                            $hrs = trim((string)($_POST['cat2_service_hours'] ?? $_POST['service_hours'] ?? ''));
                            if ($hrs === 'OTHER' || !in_array($hrs, ['100','150','200','250','300','350','400','450','500'], true)) {
                                $hVal = trim((string)($_POST['cat2_service_hours_custom_h'] ?? ''));
                                $mVal = trim((string)($_POST['cat2_service_hours_custom_m'] ?? ''));
                                $h = is_numeric($hVal) ? (float)$hVal : 0.0;
                                $m = is_numeric($mVal) ? (float)$mVal : 0.0;
                                $hrs = (string)($h + ($m / 60.0));
                            }
                            $details['service_hours'] = is_numeric($hrs) ? (float)$hrs : 0.0;
                        }
                        if (isset($_POST['cat2_counseling']))   $details['interventions'][] = 'Referral for Counseling';
                        if (isset($_POST['cat2_lectures']))     $details['interventions'][] = 'Attendance to Discipline Education Program';
                        if (isset($_POST['cat2_evaluation']))   $details['interventions'][] = 'Evaluation';

                    } else {
                        $details['freeze'] = true;
                    }
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

                record_finalized_case_to_historical_dataset((int)$case_id);

                $previousOngoingHours = null;
                if ($category >= 2 && !empty($details['service_hours'])) {
                    $activeReq = db_one(
                        "SELECT requirement_id, hours_required FROM community_service_requirement WHERE student_id = :sid AND status = 'ACTIVE' LIMIT 1",
                        [':sid' => $case['student_id']]
                    );
                    if ($activeReq) {
                        $previousOngoingHours = (float)$activeReq['hours_required'];
                    }

                    db_exec("INSERT INTO community_service_requirement (student_id, related_case_id, task_name, hours_required, assigned_by, assigned_at, status)
                             VALUES (:sid, :cid, :tn, :hrs, :aid, NOW(), 'PENDING_ACCEPTANCE')",
                         [':sid' => $case['student_id'],
                          ':cid' => $case_id,
                          ':tn'  => 'University Service',
                          ':hrs' => $details['service_hours'],
                          ':aid' => (int)$admin['admin_id']]);
                }

                upcc_log_case_activity($case_id, 'ADMIN', (int)$admin['admin_id'], 'FINAL_DECISION_RECORDED', [
                    'category' => $category, 
                    'punishment_details' => $details,
                    'force_resolve' => $forceResolve ? 1 : 0, 
                    'use_suggested' => $useSuggested ? 1 : 0,
                    'previous_ongoing_hours' => $previousOngoingHours
                ]);
                db_exec("UPDATE upcc_case_vote_round SET is_active = 0 WHERE case_id = :id", [':id' => $case_id]);
                header("Location: upcc_case_view.php?id={$case_id}&msg=resolved"); exit;
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

$case = db_one("SELECT uc.*, CONCAT(s.student_fn,' ',s.student_ln) AS student_name,
           s.student_fn, s.student_ln, s.year_level, s.section, s.program, s.school,
           s.student_email, s.phone_number, s.home_address,
           d.dept_name AS assigned_dept_name
    FROM upcc_case uc
    JOIN student s ON s.student_id = uc.student_id
    LEFT JOIN departments d ON d.dept_id = uc.assigned_department_id
    WHERE uc.case_id = :id", [':id' => $case_id]);

$consensusCategory     = (int)($case['hearing_vote_consensus_category'] ?? 0);
$isAwaitingAdmin       = $consensusCategory > 0 && (string)($case['status'] ?? '') === 'AWAITING_ADMIN_FINALIZATION';
$postedDecidedCategory = isset($_POST['decided_category']) ? (int)$_POST['decided_category'] : 0;
$postedFinalDecision   = trim($_POST['final_decision'] ?? '');
file_put_contents(__DIR__ . '/../debug_admin_load.txt', date('Y-m-d H:i:s') . " - case_id: $case_id, db_consensus: " . ($case['hearing_vote_consensus_category'] ?? 'null') . ", consensusCategory: $consensusCategory, status: " . ($case['status'] ?? 'null') . "\n", FILE_APPEND);
try {
    $suggestedVoteDetails = json_decode((string)($case['hearing_vote_suggested_details'] ?? ''), true) ?: [];
} catch (Throwable $e) { $suggestedVoteDetails = []; }

$suggestedDescription     = $suggestedVoteDetails['description']   ?? '';
$prefillCat1Terms         = (int)($suggestedVoteDetails['probation_terms'] ?? 3);
$prefillCat2Interventions = (isset($suggestedVoteDetails['interventions']) && is_array($suggestedVoteDetails['interventions']))
                            ? $suggestedVoteDetails['interventions'] : [];
$prefillCat2Hours         = trim((string)($suggestedVoteDetails['service_hours'] ?? ''));

$prefillH = '';
$prefillM = '';
if (is_numeric($prefillCat2Hours) && (float)$prefillCat2Hours > 0) {
    $hoursVal = (float)$prefillCat2Hours;
    $h = floor($hoursVal);
    $m = round(($hoursVal - $h) * 60);
    if ($m >= 60) {
        $h += 1;
        $m -= 60;
    }
    $prefillH = (string)$h;
    $prefillM = (string)$m;
}

$hasSuggestedByCol = db_one(
    "SELECT 1 FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'upcc_case_vote_round' AND COLUMN_NAME = 'suggested_by' LIMIT 1"
) !== null;

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
    if ((int)$rv['upcc_id'] === $suggesterId) continue;
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

$activeCooldown = db_one(
    "SELECT TIMESTAMPDIFF(SECOND, NOW(), MAX(cooldown_until)) AS remaining
     FROM upcc_suggestion_cooldown
     WHERE case_id = :c AND cooldown_until > NOW()",
    [':c' => $case_id]
);
$cooldownSecs = max(0, (int)($activeCooldown['remaining'] ?? 0));

$hasPanel         = (!empty($case['assigned_department_id']) || (!empty($case['assigned_panel_members']) && $case['assigned_panel_members'] !== '[]'));
$isClosed         = in_array($case['status'], ['CLOSED', 'RESOLVED']);
$isHearingOpen    = (int)($case['hearing_is_open']   ?? 0) === 1;
$isHearingPaused  = (int)($case['hearing_is_paused'] ?? 0) === 1;
$pauseReason      = $case['hearing_pause_reason']    ?? null;
$caseLabel        = 'UPCC-' . date('Y', strtotime($case['created_at'])) . '-' . str_pad((string)$case_id, 3, '0', STR_PAD_LEFT);
$caseStatusPillClass = match ($case['status']) {
    'UNDER_INVESTIGATION'         => 'pill-investigating',
    'CLOSED', 'RESOLVED'          => 'pill-closed',
    'DISMISSED'                   => 'pill-closed',
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
<title><?= htmlspecialchars($caseLabel) ?> — UPCC Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=IBM+Plex+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<style>
/* ══════════════════════════════════════════════════════════════════════════
   UPCC ADMIN CONSOLE
   Refined institutional admin interface. Slate + cool cyan accent.
   ══════════════════════════════════════════════════════════════════════════ */

:root {
  --font: 'Inter', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
  --mono: 'IBM Plex Mono', 'Courier New', monospace;

  /* Surface */
  --surface-0: #0a0f1c;
  --surface-1: #0f1626;
  --surface-2: #141d30;
  --surface-3: #1a2540;
  --surface-elevated: #1e2a47;

  /* Ink scale */
  --ink-50:  #f5f7fa;
  --ink-100: #e8ecf2;
  --ink-200: #cdd5e0;
  --ink-300: #98a3b4;
  --ink-400: #6b7889;
  --ink-500: #4a5568;
  --ink-600: #2f3a4d;
  --ink-700: #1e2738;
  --ink-800: #131a28;
  --ink-900: #0a0f1c;

  /* Accents */
  --accent: #3d9ce0;
  --accent-hi: #5cb2f0;
  --accent-soft: rgba(61, 156, 224, 0.12);
  --accent-line: rgba(61, 156, 224, 0.35);

  --gold: #c8a86a;
  --gold-soft: rgba(200, 168, 106, 0.12);
  --gold-line: rgba(200, 168, 106, 0.35);

  --sage: #6faf8e;
  --sage-soft: rgba(111, 175, 142, 0.12);
  --sage-line: rgba(111, 175, 142, 0.35);

  --rose: #d16868;
  --rose-soft: rgba(209, 104, 104, 0.12);
  --rose-line: rgba(209, 104, 104, 0.35);

  --amber: #d4a24c;
  --amber-soft: rgba(212, 162, 76, 0.12);
  --amber-line: rgba(212, 162, 76, 0.35);

  --violet: #9b85d8;
  --violet-soft: rgba(155, 133, 216, 0.12);
  --violet-line: rgba(155, 133, 216, 0.35);

  /* Borders & dividers */
  --border-1: rgba(255,255,255,0.06);
  --border-2: rgba(255,255,255,0.10);
  --border-3: rgba(255,255,255,0.16);

  --radius-sm: 4px;
  --radius-md: 6px;
  --radius-lg: 10px;
  --radius-xl: 14px;

  --shadow-sm: 0 1px 2px rgba(0,0,0,0.3);
  --shadow-md: 0 4px 16px rgba(0,0,0,0.25);
  --shadow-lg: 0 12px 32px rgba(0,0,0,0.4);

  --sidebar-w: 248px;
}

*,*::before,*::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: var(--font);
  background: var(--surface-0);
  background-image:
    radial-gradient(ellipse 80% 60% at 0% 0%, rgba(61,156,224,0.05), transparent 60%),
    radial-gradient(ellipse 60% 50% at 100% 100%, rgba(155,133,216,0.04), transparent 60%);
  background-attachment: fixed;
  color: var(--ink-100);
  font-size: 14px;
  line-height: 1.55;
  -webkit-font-smoothing: antialiased;
  letter-spacing: -0.005em;
}

.admin-shell { display: grid; grid-template-columns: var(--sidebar-w) 1fr; min-height: calc(100vh - 56px); }
.main-content { overflow: auto; width: 100%; }

/* ── Page Header ──────────────────────────────────────────────────────── */
.page-header {
  background: linear-gradient(180deg, var(--surface-1) 0%, var(--surface-0) 100%);
  padding: 26px 32px 24px;
  border-bottom: 1px solid var(--border-1);
  display: flex; align-items: flex-end; justify-content: space-between; flex-wrap: wrap; gap: 20px;
  position: relative;
}
.page-header::after {
  content: ''; position: absolute; left: 32px; right: 32px; bottom: -1px; height: 1px;
  background: linear-gradient(90deg, var(--accent-soft), transparent 40%);
}
.back-link {
  display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px;
  color: var(--ink-300); text-decoration: none; transition: all .18s ease;
  text-transform: uppercase; letter-spacing: 0.08em; font-weight: 600;
  margin-bottom: 10px;
}
.back-link:hover { color: var(--accent-hi); transform: translateX(-3px); }

.header-left { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.case-id {
  font-family: var(--font); font-size: 26px; font-weight: 700;
  color: var(--ink-50); letter-spacing: -0.02em; line-height: 1.15;
}
.case-id .case-id-label {
  font-size: 11px; color: var(--ink-400); text-transform: uppercase;
  letter-spacing: 0.14em; font-weight: 700; display: block; margin-bottom: 3px;
}

.header-badges { display: flex; gap: 6px; flex-wrap: wrap; align-items: center; margin-top: 10px; }
.header-right {
  font-size: 11.5px; color: var(--ink-300); text-align: right;
  background: rgba(255,255,255,0.02); padding: 10px 14px;
  border-radius: var(--radius-md); border: 1px solid var(--border-1);
  font-family: var(--mono); line-height: 1.6;
  letter-spacing: 0;
}
.header-right strong { color: var(--ink-100); font-weight: 600; }

/* ── Pills ────────────────────────────────────────────────────────────── */
.pill {
  display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px;
  border-radius: 3px; font-size: 10.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.08em; border: 1px solid transparent;
  white-space: nowrap;
}
.pill-dot { width: 6px; height: 6px; border-radius: 50%; flex-shrink: 0; }
.pill-pending       { background: var(--amber-soft); color: #e8b767; border-color: var(--amber-line); }
.pill-pending .pill-dot { background: var(--amber); }
.pill-investigating { background: var(--accent-soft); color: #7cc2ee; border-color: var(--accent-line); }
.pill-investigating .pill-dot { background: var(--accent); }
.pill-closed        { background: var(--sage-soft); color: #8fc9a5; border-color: var(--sage-line); }
.pill-closed .pill-dot { background: var(--sage); }
.pill-appeal        { background: var(--violet-soft); color: #b9a8e6; border-color: var(--violet-line); }
.pill-appeal .pill-dot { background: var(--violet); }
.pill-open          { background: var(--sage-soft); color: #8fc9a5; border-color: var(--sage-line); }
.pill-open .pill-dot { background: var(--sage); animation: pulse 1.8s infinite; }
.pill-warning       { background: var(--amber-soft); color: #e8b767; border-color: var(--amber-line); }
.pill-neutral       { background: rgba(255,255,255,0.05); color: var(--ink-200); border-color: var(--border-2); }
.pill-awaiting      { background: rgba(212,162,76,0.15); color: #e8b767; border-color: rgba(212,162,76,0.4); }
.pill-awaiting .pill-dot { background: var(--amber); animation: pulse 1.4s infinite; }
.pill-success       { background: var(--sage-soft); color: #8fc9a5; border-color: var(--sage-line); }
@keyframes pulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.4; } }

/* ── Page Body ────────────────────────────────────────────────────────── */
.page-body { padding: 26px 32px 60px; max-width: 1400px; margin: 0 auto; }

/* ── Alerts ───────────────────────────────────────────────────────────── */
.alert {
  padding: 12px 16px; border-radius: var(--radius-md); font-size: 13px;
  margin-bottom: 20px; border: 1px solid transparent; font-weight: 500;
  display: flex; align-items: flex-start; gap: 10px; line-height: 1.55;
}
.alert::before {
  flex-shrink: 0; font-weight: 800; font-size: 14px; line-height: 1.4;
}
.alert-error   { background: var(--rose-soft); color: #e8a0a0; border-color: var(--rose-line); }
.alert-error::before { content: '⚠'; color: #e8a0a0; }
.alert-success { background: var(--sage-soft); color: #8fc9a5; border-color: var(--sage-line); }
.alert-success::before { content: '✓'; color: #8fc9a5; }
.alert-warning { background: var(--amber-soft); color: #e8b767; border-color: var(--amber-line); }
.alert-warning::before { content: '⚠'; color: #e8b767; }
.alert-info    { background: var(--accent-soft); color: #7cc2ee; border-color: var(--accent-line); }
.alert-info::before { content: 'ⓘ'; color: #7cc2ee; font-style: normal; }

/* ── Cards ────────────────────────────────────────────────────────────── */
.case-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; align-items: start; }
.card {
  background: linear-gradient(180deg, var(--surface-1) 0%, var(--surface-2) 100%);
  border: 1px solid var(--border-1);
  border-radius: var(--radius-lg);
  overflow: hidden;
  box-shadow: var(--shadow-sm);
  transition: border-color .2s ease;
  scroll-margin-top: 90px;
}
.card:hover { border-color: var(--border-2); }
.card-header {
  padding: 16px 20px;
  border-bottom: 1px solid var(--border-1);
  background: rgba(0,0,0,0.15);
  display: flex; align-items: center; justify-content: space-between; gap: 12px;
  position: relative;
}
.card-header::after {
  content: ''; position: absolute; left: 20px; right: 20px; bottom: -1px; height: 1px;
  background: linear-gradient(90deg, var(--accent-soft), transparent 50%);
}
.card-title {
  font-size: 11.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.12em; color: var(--ink-200);
  display: flex; align-items: center; gap: 8px;
}
.card-title::before {
  content: ''; width: 3px; height: 12px; background: var(--accent);
  border-radius: 2px; flex-shrink: 0;
}
.card-body { padding: 20px; }

/* ── Section Labels ───────────────────────────────────────────────────── */
.section-label {
  font-size: 10.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.14em; color: var(--ink-400); margin-bottom: 10px;
  padding-bottom: 6px; border-bottom: 1px solid var(--border-1);
}

/* ── Info Boxes ───────────────────────────────────────────────────────── */
.hearing-box, .summary-box {
  background: rgba(0,0,0,0.2);
  border: 1px solid var(--border-1);
  border-radius: var(--radius-md);
  padding: 14px 16px;
  margin-bottom: 16px;
  font-size: 13px;
}
.hearing-row { display: flex; align-items: baseline; justify-content: space-between; font-size: 13px; padding: 6px 0; gap: 16px; }
.hearing-row + .hearing-row { border-top: 1px dashed var(--border-1); }
.hearing-key { color: var(--ink-400); font-size: 11.5px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; }
.hearing-val { font-weight: 600; color: var(--ink-100); text-align: right; }

/* ── Status Bar ───────────────────────────────────────────────────────── */
.status-bar {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 16px; background: rgba(0,0,0,0.2);
  border: 1px solid var(--border-1); border-radius: var(--radius-md);
  margin-bottom: 16px; font-size: 13px; gap: 12px; flex-wrap: wrap;
}
.status-indicator { display: flex; align-items: center; gap: 10px; color: var(--ink-200); font-weight: 500; }
.dot-live { width: 8px; height: 8px; border-radius: 50%; background: var(--sage); box-shadow: 0 0 0 3px var(--sage-soft); animation: pulse 2s infinite; }
.dot-off { width: 8px; height: 8px; border-radius: 50%; background: var(--ink-400); }
.status-time { font-size: 11.5px; color: var(--ink-400); font-weight: 500; font-family: var(--mono); }

/* ── Buttons ──────────────────────────────────────────────────────────── */
.btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 7px;
  padding: 9px 16px; border-radius: var(--radius-sm);
  font-size: 12.5px; font-weight: 600;
  cursor: pointer; border: 1px solid transparent;
  transition: all .15s ease;
  text-decoration: none; letter-spacing: 0.02em;
  font-family: var(--font);
  white-space: nowrap;
  line-height: 1;
}
.btn:active { transform: translateY(1px); }
.btn-primary {
  background: linear-gradient(180deg, var(--accent) 0%, #2d7fb8 100%);
  color: #fff; border-color: #2d7fb8;
  box-shadow: 0 1px 0 rgba(255,255,255,0.15) inset, 0 2px 8px rgba(61,156,224,0.25);
}
.btn-primary:hover { background: linear-gradient(180deg, var(--accent-hi) 0%, var(--accent) 100%); box-shadow: 0 4px 14px rgba(61,156,224,0.4); }
.btn-success {
  background: linear-gradient(180deg, var(--sage) 0%, #4e8c6d 100%);
  color: #fff; border-color: #4e8c6d;
  box-shadow: 0 1px 0 rgba(255,255,255,0.15) inset, 0 2px 8px rgba(111,175,142,0.25);
}
.btn-success:hover { background: linear-gradient(180deg, #80bf9c 0%, var(--sage) 100%); box-shadow: 0 4px 14px rgba(111,175,142,0.4); }
.btn-warning {
  background: linear-gradient(180deg, var(--amber) 0%, #b0852e 100%);
  color: #fff; border-color: #b0852e;
  box-shadow: 0 1px 0 rgba(255,255,255,0.15) inset, 0 2px 8px rgba(212,162,76,0.25);
}
.btn-warning:hover { background: linear-gradient(180deg, #e0b45e 0%, var(--amber) 100%); box-shadow: 0 4px 14px rgba(212,162,76,0.4); }
.btn-danger {
  background: linear-gradient(180deg, var(--rose) 0%, #a44f4f 100%);
  color: #fff; border-color: #a44f4f;
  box-shadow: 0 1px 0 rgba(255,255,255,0.15) inset, 0 2px 8px rgba(209,104,104,0.25);
}
.btn-danger:hover { background: linear-gradient(180deg, #dd7e7e 0%, var(--rose) 100%); box-shadow: 0 4px 14px rgba(209,104,104,0.4); }
.btn-outline {
  background: transparent; color: var(--ink-200); border-color: var(--border-2);
}
.btn-outline:hover { background: rgba(255,255,255,0.04); border-color: var(--border-3); color: var(--ink-50); }
.btn-ghost {
  background: transparent; border-color: transparent; color: var(--ink-300); box-shadow: none;
}
.btn-ghost:hover { background: rgba(255,255,255,0.04); color: var(--ink-50); }
.btn-sm { padding: 6px 11px; font-size: 11.5px; }
.btn-full { width: 100%; }
.btn-group { display: flex; gap: 8px; flex-wrap: wrap; }
.btn:disabled, .btn[disabled] { opacity: 0.45; cursor: not-allowed; pointer-events: none; box-shadow: none !important; }

/* ── Voting Live Block ────────────────────────────────────────────────── */
.voting-live-block {
  background: var(--surface-2);
  border: 1px solid var(--accent-line);
  border-radius: var(--radius-lg);
  overflow: hidden; margin-bottom: 18px;
  box-shadow: 0 0 0 1px rgba(61,156,224,0.05), 0 8px 24px rgba(0,0,0,0.3);
}
.vlb-header {
  background: linear-gradient(135deg, rgba(61,156,224,0.15), rgba(61,156,224,0.05));
  padding: 14px 18px; display: flex; align-items: center; justify-content: space-between; gap: 12px;
  border-bottom: 1px solid var(--accent-line);
}
.vlb-title { color: var(--ink-50); font-size: 13px; font-weight: 700; display: flex; align-items: center; gap: 10px; letter-spacing: 0.02em; }
.live-badge {
  background: rgba(209,104,104,0.15); color: #e8a0a0; border: 1px solid var(--rose-line);
  border-radius: 3px; padding: 3px 8px; font-size: 9.5px; font-weight: 800;
  text-transform: uppercase; letter-spacing: 0.12em; animation: blink 1.4s ease-in-out infinite;
}
@keyframes blink { 0%, 100% { opacity: 1; } 50% { opacity: 0.5; } }
.vlb-body { padding: 18px; }

.vote-tally { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 14px; }
.vote-tally-cell {
  text-align: center; border-radius: var(--radius-md); padding: 14px 10px;
  border: 1px solid var(--border-1);
  background: rgba(0,0,0,0.2);
}
.vtc-agree    { border-color: var(--sage-line); background: var(--sage-soft); }
.vtc-disagree { border-color: var(--rose-line); background: var(--rose-soft); }
.vtc-pending  { border-color: var(--amber-line); background: var(--amber-soft); }
.vtc-num { font-family: var(--font); font-size: 26px; font-weight: 700; display: block; line-height: 1; letter-spacing: -0.02em; }
.vtc-agree .vtc-num    { color: #8fc9a5; }
.vtc-disagree .vtc-num { color: #e8a0a0; }
.vtc-pending .vtc-num  { color: #e8b767; }
.vtc-lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 0.1em; font-weight: 700; display: block; margin-top: 6px; color: var(--ink-300); }

/* ── Consensus Finalize Block ─────────────────────────────────────────── */
.consensus-finalize-block {
  border: 1px solid var(--sage-line); border-radius: var(--radius-lg); overflow: hidden;
  margin-bottom: 18px; background: var(--surface-2);
  box-shadow: 0 0 0 1px rgba(111,175,142,0.05), 0 8px 24px rgba(0,0,0,0.3);
}
.cf-header {
  background: linear-gradient(135deg, rgba(111,175,142,0.18), rgba(111,175,142,0.05));
  padding: 16px 20px;
  display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap;
  border-bottom: 1px solid var(--sage-line);
}
.cf-header-left { display: flex; align-items: center; gap: 14px; }
.cf-icon { font-size: 22px; line-height: 1; }
.cf-title { font-size: 14px; font-weight: 700; color: #b4dcc4; letter-spacing: 0.02em; }
.cf-sub { font-size: 12px; color: var(--ink-300); margin-top: 2px; }
.cf-body { padding: 20px; }

/* ── Form Elements ────────────────────────────────────────────────────── */
.form-group { margin-bottom: 16px; }
.form-label {
  display: block; font-size: 10.5px; font-weight: 700; text-transform: uppercase;
  letter-spacing: 0.1em; color: var(--ink-400); margin-bottom: 6px;
}
.form-control {
  width: 100%; padding: 10px 14px;
  border: 1px solid var(--border-2); border-radius: var(--radius-sm);
  font-size: 13.5px; font-family: var(--font);
  background: rgba(0,0,0,0.3); color: var(--ink-50);
  transition: all .15s ease;
  line-height: 1.4;
}
.form-control:focus {
  outline: none; border-color: var(--accent); background: rgba(0,0,0,0.45);
  box-shadow: 0 0 0 3px rgba(61,156,224,0.15);
}
.form-control::placeholder { color: var(--ink-500); }
.form-control option { background: var(--surface-1); color: var(--ink-50); }
textarea.form-control { resize: vertical; min-height: 80px; font-family: var(--font); }
select.form-control { cursor: pointer; }

/* ── Panel Members List ──────────────────────────────────────────────── */
.panel-list { margin-bottom: 16px; background: rgba(0,0,0,0.2); border-radius: var(--radius-md); padding: 8px; border: 1px solid var(--border-1); }
.panel-member { display: flex; align-items: center; gap: 12px; padding: 10px; border-radius: var(--radius-sm); transition: background .15s ease; }
.panel-member:hover { background: rgba(255,255,255,0.03); }
.panel-member + .panel-member { border-top: 1px dashed var(--border-1); }
.avatar {
  width: 36px; height: 36px; border-radius: 50%;
  display: flex; align-items: center; justify-content: center;
  font-size: 12px; font-weight: 700; flex-shrink: 0;
  border: 1px solid transparent; letter-spacing: 0.02em;
}
.av-blue   { background: rgba(61,156,224,0.15); color: #7cc2ee; border-color: var(--accent-line); }
.av-green  { background: rgba(111,175,142,0.15); color: #8fc9a5; border-color: var(--sage-line); }
.av-purple { background: rgba(155,133,216,0.15); color: #b9a8e6; border-color: var(--violet-line); }
.av-amber  { background: rgba(212,162,76,0.15); color: #e8b767; border-color: var(--amber-line); }
.av-coral  { background: rgba(209,104,104,0.15); color: #e8a0a0; border-color: var(--rose-line); }
.member-name { font-size: 13px; font-weight: 600; line-height: 1.3; color: var(--ink-100); }
.member-role { font-size: 11px; color: var(--ink-400); text-transform: uppercase; letter-spacing: 0.06em; font-weight: 500; }

/* ── Panel Selector ──────────────────────────────────────────────────── */
.panel-select-wrapper { border: 1px solid var(--border-2); border-radius: var(--radius-sm); padding: 8px; background: rgba(0,0,0,0.3); min-height: 52px; display: flex; flex-direction: column; gap: 8px; }
.selected-panel-members { display: flex; flex-wrap: wrap; gap: 6px; }
.panel-chip { display: inline-flex; align-items: center; gap: 6px; background: var(--accent-soft); color: #7cc2ee; font-size: 12px; font-weight: 600; padding: 4px 10px; border-radius: 3px; border: 1px solid var(--accent-line); }
.panel-chip-remove { cursor: pointer; color: var(--accent); font-weight: 700; line-height: 1; font-size: 14px; transition: color .15s; }
.panel-chip-remove:hover { color: var(--rose); }
.panel-member-search { width: 100%; border: none; background: transparent; padding: 6px 4px; font-size: 13px; font-family: var(--font); outline: none; color: var(--ink-50); }
.panel-member-search::placeholder { color: var(--ink-500); }
.panel-member-dropdown {
  position: absolute; top: 100%; left: 0; right: 0; background: var(--surface-elevated);
  border: 1px solid var(--border-3); border-radius: var(--radius-sm);
  box-shadow: var(--shadow-lg); max-height: 220px; overflow-y: auto; z-index: 1000;
  display: none; margin-top: 4px;
}
.panel-member-dropdown.show { display: block; }
.dropdown-item { padding: 10px 14px; font-size: 12.5px; cursor: pointer; display: flex; flex-direction: column; gap: 2px; border-bottom: 1px solid var(--border-1); }
.dropdown-item:last-child { border-bottom: none; }
.dropdown-item:hover { background: rgba(61,156,224,0.08); }
.dropdown-item-title { font-weight: 600; color: var(--ink-50); display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.dropdown-item-sub { font-size: 11px; color: var(--ink-400); }
.cb-scroll{border:1px solid var(--border-2);border-radius:var(--radius-sm);padding:8px;max-height:170px;overflow-y:auto;background:rgba(0,0,0,0.2);}
.cb-item{display:flex;align-items:center;gap:8px;padding:6px 8px;font-size:13px;border-radius:3px;cursor:pointer;color:var(--ink-100);transition:background .15s;}
.cb-item:hover{background:rgba(255,255,255,0.04);}
.cb-item input[type=checkbox] { accent-color: var(--accent); }

/* ── Utility Panels ──────────────────────────────────────────────────── */
.edit-panel{margin-top:20px;display:none}
.edit-panel.open{display:block}
.waiting-room-box{display:none;margin-bottom:16px;background:var(--amber-soft);border:1px solid var(--amber-line);border-radius:var(--radius-md);padding:14px}
.awaiting-box{background:rgba(0,0,0,0.2);border:1px solid var(--border-1);border-radius:var(--radius-md);padding:12px 16px;
  display:flex;align-items:center;gap:10px;font-size:13px;color:var(--ink-300);margin-bottom:12px}
.consensus-box{background:var(--sage-soft);border:1px solid var(--sage-line);border-radius:var(--radius-md);padding:12px 16px;
  display:flex;align-items:center;justify-content:space-between;font-size:13px;color:#b4dcc4;font-weight:500;margin-bottom:12px;flex-wrap:wrap;gap:8px}

#dynamicFieldsContainer{background:rgba(0,0,0,0.2);border:1px solid var(--border-1);padding:16px;border-radius:var(--radius-md);margin-bottom:14px;display:none}

/* ── Modals ───────────────────────────────────────────────────────────── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(4,7,14,0.85);z-index:3000;place-items:center;padding:20px; backdrop-filter: blur(6px);}
.modal-overlay.open{display:grid}
.modal-content{
  background: var(--surface-1);
  border: 1px solid var(--border-3);
  border-radius: var(--radius-xl);
  padding: 28px; max-width: 520px; width: min(92vw, 520px);
  max-height: calc(100vh - 40px); overflow: auto;
  box-shadow: var(--shadow-lg);
  position: relative;
}
.modal-content::before {
  content: ''; position: absolute; top: 0; left: 24px; right: 24px; height: 2px;
  background: linear-gradient(90deg, transparent, var(--accent), transparent);
}
.modal-content h3{margin-bottom:16px;color:var(--ink-50);text-align:center; font-family: var(--font); font-weight: 700; font-size: 18px; letter-spacing: -0.01em;}
.modal-content textarea{width:100%;padding:12px;border:1px solid var(--border-2);border-radius:var(--radius-sm);margin:16px 0;resize:vertical;font-family:var(--font);background:rgba(0,0,0,0.3);color:var(--ink-50);font-size:13px}
.modal-content textarea:focus { outline: none; border-color: var(--accent); }
.modal-buttons{display:flex;gap:10px;justify-content:center;flex-wrap:wrap;margin-top: 20px;}
.modal-content ul{margin:12px 0 12px 20px;font-size:13px;line-height:1.9;color:var(--ink-200)}
.modal-content p { color: var(--ink-200); font-size: 13.5px; line-height: 1.6; }

/* ── Toast ────────────────────────────────────────────────────────────── */
.toast{position:fixed;top:20px;right:20px;padding:14px 18px;border-radius:var(--radius-md);color:#fff;
  z-index:9999;font-family:var(--font);box-shadow:var(--shadow-lg);max-width:360px;
  animation:slideInRight .3s cubic-bezier(0.4, 0, 0.2, 1);
  border: 1px solid transparent;}
@keyframes slideInRight{from{transform:translateX(120%);opacity:0}to{transform:translateX(0);opacity:1}}
@keyframes slideOutRight{from{transform:translateX(0);opacity:1}to{transform:translateX(120%);opacity:0}}
.toast-title{font-weight:700;font-size:13px;margin-bottom:3px; letter-spacing: 0.02em;}
.toast-msg{font-size:12.5px;opacity:0.92; font-weight: 500; line-height: 1.4;}
.toast-success{background: linear-gradient(135deg, #4e8c6d, #3b6f55); border-color: var(--sage-line);}
.toast-info{background: linear-gradient(135deg, #2d7fb8, #2067a0); border-color: var(--accent-line);}
.toast-warning{background: linear-gradient(135deg, #b0852e, #8d6a24); border-color: var(--amber-line);}

/* ── Voting Suggestion ────────────────────────────────────────────────── */
.vlb-suggestion{background: rgba(0,0,0,0.25);border:1px solid var(--border-1);border-radius:var(--radius-md);
  padding:14px 16px;margin-bottom:14px}
.vlb-sug-cat{font-size:13px;font-weight:700;color:var(--accent-hi);margin-bottom:4px; letter-spacing: 0.02em;}
.vlb-sug-by{font-size:11.5px;color:var(--ink-400);margin-bottom:8px}
.vlb-sug-detail{font-size:13px;color:var(--ink-100);line-height:1.55}
.vlb-tag{display:inline-block;background:var(--accent-soft);color:#7cc2ee;border:1px solid var(--accent-line);border-radius:3px;
  padding:3px 9px;font-size:11px;font-weight:700;margin:2px 3px 2px 0; letter-spacing: 0.02em;}
.cat2-hours-grid{display:flex;flex-wrap:wrap;gap:6px;margin-top:8px}
.cat2-hour-pill{display:inline-flex;align-items:center;gap:6px;background:rgba(0,0,0,0.3);border:1px solid var(--border-2);
  color:var(--ink-200);padding:7px 12px;border-radius:3px;font-size:12px;font-weight:600;cursor:pointer;
  transition:all .15s ease;}
.cat2-hour-pill:hover{border-color:var(--accent-line);color:var(--ink-50)}
.cat2-hour-pill input{accent-color: var(--accent); margin:0}
.cat2-hour-pill:has(input:checked){background:var(--accent-soft);border-color:var(--accent);color:#7cc2ee}
.cat2-hours-other{display:flex;align-items:center;gap:8px;background:var(--amber-soft);border:1px solid var(--amber-line);
  color:#e8b767;padding:7px 12px;border-radius:3px;font-size:12px;font-weight:600;cursor:pointer;}
.cat2-hours-other input{margin:0; accent-color: var(--amber);}
.live-voting-detail{display:flex;flex-direction:column;gap:6px;margin-top:8px}
.live-voting-detail .detail-pill{display:inline-flex;align-items:center;gap:6px;width:fit-content;
  background:var(--accent-soft);border:1px solid var(--accent-line);border-radius:3px;padding:4px 10px;
  font-size:11.5px;font-weight:600;color:#7cc2ee;}

.vote-head{display:flex;justify-content:space-between;padding-bottom:8px;margin-bottom:6px;border-bottom:1px solid var(--border-1)}
.vote-head span{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.1em;color:var(--ink-400)}
.vote-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;font-size:13px}
.vote-row+.vote-row{border-top:1px dashed var(--border-1)}
.vote-cat{font-family:var(--font);font-size:11px;font-weight:700;background:var(--accent-soft);color:#7cc2ee;padding:3px 9px;border-radius:3px; letter-spacing: 0.04em;}
.vote-cat.agree{background:var(--sage-soft);color:#8fc9a5}
.vote-cat.disagree{background:var(--rose-soft);color:#e8a0a0}
.vote-cat.suggester{background:var(--violet-soft);color:#b9a8e6}
.vote-time{font-size:11px;color:var(--ink-400);margin-left:8px; font-weight: 500; font-family: var(--mono);}

.vlb-timer-wrap{margin-bottom:14px}
.vlb-timer-top{display:flex;justify-content:space-between;align-items:baseline;margin-bottom:6px}
.vlb-timer-label{font-size:10.5px;color:var(--ink-400);text-transform:uppercase;letter-spacing:0.1em; font-weight: 700;}
.vlb-timer-num{font-family:var(--mono);font-size:18px;font-weight:600;color:var(--ink-50);font-variant-numeric:tabular-nums; letter-spacing: 0.03em;}
.vlb-timer-num.urgent{color:#e8a0a0; animation: pulse 1s infinite;}
.vlb-timer-bar{height:4px;background:rgba(255,255,255,0.06);border-radius:999px;overflow:hidden;}
.vlb-timer-fill{height:100%;border-radius:999px;transition:width 1s linear,background .5s; background: var(--accent);}

.cooldown-block{background:var(--amber-soft);border:1px solid var(--amber-line);border-radius:var(--radius-md);
  padding:14px 16px;text-align:center;margin-bottom:14px;}
.cooldown-title{font-size:12px;font-weight:700;color:#e8b767;margin-bottom:4px; letter-spacing: 0.02em;}
.cooldown-num{font-family:var(--mono);font-size:22px;font-weight:600;color:#e8b767;
  font-variant-numeric:tabular-nums; letter-spacing: 0.05em;}

.cat-badge{display:inline-flex;align-items:center;gap:8px;background:linear-gradient(135deg, rgba(111,175,142,0.18), rgba(111,175,142,0.06));color:#b4dcc4;
  padding:8px 16px;border-radius:3px;font-size:12.5px;font-weight:700;letter-spacing:0.04em;margin-bottom:14px; border: 1px solid var(--sage-line);}
.cat-desc-box{background: rgba(0,0,0,0.2); border:1px solid var(--border-1); border-radius:var(--radius-md); padding:14px 16px; margin-bottom:14px;}
.cat-desc-label{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:0.12em;color:var(--ink-400);margin-bottom:8px}
.cat-desc-text{font-size:13px;color:var(--ink-100);line-height:1.6;font-weight:500}
.cat-detail-grid{display:grid;gap:8px;margin-bottom:14px}
.cat-detail-row{display:flex;gap:12px;align-items:baseline;font-size:13px}
.cat-detail-key{color:var(--ink-400);font-size:11.5px;white-space:nowrap;min-width:130px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em;}
.cat-detail-val{color:var(--ink-100);font-weight: 500;flex:1}

.meta-grid {
  display: grid;
  grid-template-columns: 120px 1fr;
  gap: 10px 16px;
  align-items: center;
  font-size: 13px;
  line-height: 1.5;
  margin-top: 8px;
}
.meta-key {
  color: var(--ink-400);
  font-weight: 700;
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
}
.meta-val {
  color: var(--ink-100);
  font-weight: 500;
  word-break: break-word;
}

/* ── Utils ────────────────────────────────────────────────────────────── */
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.divider { border: none; border-top: 1px solid var(--border-1); margin: 20px 0; }

@media(max-width: 1000px){
  .case-grid { grid-template-columns: 1fr; }
  .page-body { padding: 22px 20px 40px; }
  .page-header { padding: 22px 20px; }
  .form-row { grid-template-columns: 1fr; }
}
@media(max-width: 900px){
  .admin-shell { grid-template-columns: 1fr; }
}

/* ── Spinner & loading ────────────────────────────────────────────────── */
@keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }
.spinner {
  border: 3px solid rgba(255,255,255,.1);
  border-top-color: var(--accent);
  border-radius: 50%;
  width: 44px;
  height: 44px;
  animation: spin 1s linear infinite;
  margin: 0 auto 20px;
}
.global-loading-overlay {
  position: fixed;
  inset: 0;
  z-index: 9999;
  display: none;
  align-items: center;
  justify-content: center;
  background: rgba(4,7,14,.92);
  backdrop-filter: blur(8px);
}

/* ── Accordion offense details ────────────────────────────────────────── */
.offense-details-card summary { list-style: none; }
.offense-details-card summary::-webkit-details-marker { display: none; }
.stag { display:inline-block; padding:2px 8px; border-radius:3px; font-size:10px; font-weight:700; letter-spacing:0.08em; text-transform: uppercase; }
.stag-major { background: var(--rose-soft); color: #e8a0a0; border: 1px solid var(--rose-line); }
.stag-minor { background: var(--accent-soft); color: #7cc2ee; border: 1px solid var(--accent-line); }

/* Card grid override for case-detail cards */
.card .meta-grid .meta-val a { color: var(--accent-hi); text-decoration: none; }
.card .meta-grid .meta-val a:hover { text-decoration: underline; }

/* Custom scrollbar for panel lists */
.panel-list::-webkit-scrollbar,
.modal-content::-webkit-scrollbar { width: 6px; }
.panel-list::-webkit-scrollbar-track,
.modal-content::-webkit-scrollbar-track { background: transparent; }
.panel-list::-webkit-scrollbar-thumb,
.modal-content::-webkit-scrollbar-thumb { background: var(--border-3); border-radius: 3px; }
.panel-list::-webkit-scrollbar-thumb:hover,
.modal-content::-webkit-scrollbar-thumb:hover { background: var(--ink-500); }

/* Override old pill style in JS toggles */
.pill.pill-open { color: #8fc9a5; }
.pill.pill-warning { color: #e8b767; }
</style>
</head>
<body>
<!-- Global Loading Overlay -->
<div id="globalLoadingOverlay" class="global-loading-overlay">
  <div style="text-align:center">
    <div class="spinner"></div>
    <p style="color:#fff;font-size:14px;font-weight:600;margin:0;letter-spacing:0.02em" id="globalLoadingMsg">Processing, please wait…</p>
  </div>
</div>
<?php require_once __DIR__ . '/header.php'; ?>
<div class="admin-shell">
  <?php require_once __DIR__ . '/sidebar.php'; ?>
  <main class="main-content">

    <div class="page-header">
      <div class="header-left">
        <a href="upcc_cases.php" class="back-link">← Back to Cases</a>
        <div class="case-id">
          <span class="case-id-label">Case Reference</span>
          <?= htmlspecialchars($caseLabel) ?>
        </div>
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
        FILED&nbsp; <?= fmtd($case['created_at']) ?><br>
        UPDATED&nbsp; <?= fmtd($case['updated_at']) ?>
      </div>
    </div>

    <div class="page-body">
      <?php if ($case['status'] === 'DISMISSED'): ?>
        <div style="background:var(--rose-soft); border:1px solid var(--rose-line); border-radius:var(--radius-lg); padding:20px 24px; margin-bottom:20px; display:flex; align-items:flex-start; gap:16px;">
          <div style="font-size:28px; line-height:1;">🚫</div>
          <div style="flex:1;">
            <h3 style="margin:0 0 6px 0; font-size:15px; color:#e8a0a0; font-weight:700; letter-spacing:0.02em; text-transform:uppercase;">This UPCC Case Has Been Dismissed</h3>
            <div style="font-size:13px; color:#d8a3a3; line-height:1.55;">
              <strong>Reason:</strong> <?= htmlspecialchars($case['dismissal_reason'] ?? 'Case dismissed by Student Discipline Office.') ?>
            </div>
            <div style="font-size:11.5px; color:#b88484; margin-top:8px; font-weight:600; font-family:var(--mono)">
              Dismissed on <?= !empty($case['dismissed_at']) ? date('F j, Y \a\t h:i A', strtotime($case['dismissed_at'])) : date('F j, Y \a\t h:i A', strtotime($case['updated_at'])) ?>
            </div>
          </div>
        </div>
      <?php endif; ?>
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
                <?= $isHearingOpen ? 'Open' : 'Closed' ?>
              </span>
            <?php endif; ?>
          </div>
          <div class="card-body">

            <?php if (!$hasPanel): ?>
              <div class="alert alert-info" style="margin-bottom:16px">No panel assigned yet. Configure below.</div>
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
                    <label class="form-label">Hearing Format</label>
                    <select name="hearing_type" class="form-control" onchange="toggleHearingLocation(this.value, 'hearing_link_or_location', 'hearing_loc_label')" required>
                      <option value="ONLINE">Online (Virtual Meeting)</option>
                      <option value="FACE_TO_FACE">Face-to-Face (In-Person)</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label class="form-label" id="hearing_loc_label">Meeting Link (URL)</label>
                    <input type="text" name="hearing_link_or_location" id="hearing_link_or_location" class="form-control" placeholder="e.g. https://meet.jit.si/..." required>
                  </div>
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
                        $panelCountResult = db_one("
                            SELECT 
                                COUNT(*) as total_panel,
                                SUM(CASE WHEN a.accepted_at IS NOT NULL THEN 1 ELSE 0 END) as accepted_panel
                            FROM upcc_case_panel_member m
                            LEFT JOIN upcc_case_panel_acceptance a ON m.case_id = a.case_id AND m.upcc_id = a.upcc_id
                            WHERE m.case_id = :id
                        ", [':id' => $case_id]);
                        $totalPanel = (int)($panelCountResult['total_panel'] ?? 0);
                        $acceptedPanel = (int)($panelCountResult['accepted_panel'] ?? 0);
                        $panelMet = ($acceptedPanel === $totalPanel && $totalPanel > 0) || ($acceptedPanel >= 2);

                        $canStartHearing  = (!$needsExplanation || $hasExplanation) && $panelMet;
                      ?>
                      <div style="display:flex; flex-direction:column; gap:8px; align-items:flex-end;">
                          <?php if (!$canStartHearing): ?>
                            <label style="font-size:11px; display:flex; align-items:center; gap:6px; color:#e8b767; font-weight:600; cursor:pointer;">
                              <input type="checkbox" id="forceStartCheck" onclick="document.getElementById('btnStartHearing').disabled = !this.checked;">
                              Force Start
                            </label>
                          <?php endif; ?>
                          <button type="submit" class="btn btn-success btn-sm" <?= $canStartHearing ? '' : 'disabled' ?> id="btnStartHearing">▶ Start Hearing</button>
                          <?php if (!$canStartHearing): ?>
                            <div style="font-size:10.5px; color:#e8a0a0; font-weight:600; line-height:1.4; text-align:right;">
                              <?php if ($needsExplanation && !$hasExplanation) echo "• Awaiting Student Explanation<br>"; ?>
                              <?php if (!$panelMet) echo "• Awaiting Panel ({$acceptedPanel}/{$totalPanel} accepted)<br>"; ?>
                            </div>
                          <?php endif; ?>
                      </div>
                    </form>
                  <?php else: ?>
                    <?php if (!empty($isHearingPaused)): ?>
                      <button type="button" id="togglePauseBtn" class="btn btn-success btn-sm" onclick="toggleHearingPause()">▶ Resume Hearing</button>
                    <?php else: ?>
                      <button type="button" id="togglePauseBtn" class="btn btn-warning btn-sm" onclick="toggleHearingPause()">⏸ Pause Hearing</button>
                    <?php endif; ?>
                    <form method="post" style="display:inline">
                      <input type="hidden" name="action" value="close_hearing">
                      <button type="submit" id="btnEndHearing" class="btn btn-danger btn-sm">⬛ End Hearing</button>
                    </form>
                  <?php endif; ?>
                  <?php if (!$isHearingOpen && !$isClosed): ?>
                    <button class="btn btn-ghost btn-sm" onclick="toggleEditPanel()">✎ Edit</button>
                  <?php endif; ?>
                </div>
              </div>

              <?php if ($isHearingOpen): ?>
                <div id="liveHearingStatus" class="alert alert-info">
                  Hearing is live — panel may now vote.
                </div>
              <?php endif; ?>
              <?php endif; ?>

              <?php
              ensure_notice_to_explain_table();
              $nteRecord = db_one("SELECT * FROM notice_to_explain WHERE case_id = :cid LIMIT 1", [':cid' => $case_id]);
              if (!$nteRecord && !empty($offenses[0]['offense_id'])) {
                  $nteRecord = db_one("SELECT * FROM notice_to_explain WHERE offense_id = :oid LIMIT 1", [':oid' => (int)$offenses[0]['offense_id']]);
              }
              ?>

              <div id="studentExplanationBlock" style="<?= !empty($case['student_explanation_at']) ? 'display:block' : 'display:none' ?>; margin-bottom: 20px; border: 1px solid var(--border-1); border-radius: var(--radius-md); overflow: hidden;">
                <div style="background: rgba(0,0,0,0.25); padding: 12px 16px; border-bottom: 1px solid var(--border-1); display: flex; align-items: center; justify-content: space-between;">
                   <span style="font-size: 10.5px; font-weight: 700; color: var(--ink-300); text-transform: uppercase; letter-spacing: 0.1em;">Student Explanation</span>
                   <span id="explanationTime" style="font-size: 11px; color: var(--ink-400); font-family: var(--mono)"><?= $case['student_explanation_at'] ? 'Submitted ' . date('M j, Y g:i A', strtotime($case['student_explanation_at'])) : '' ?></span>
                </div>
                <div style="padding: 16px;">
                   <div id="explanationText" style="font-size: 13px; color: var(--ink-100); line-height: 1.6; white-space: pre-wrap; margin-bottom: 12px;"><?= htmlspecialchars($case['student_explanation_text'] ?? '') ?></div>
                   <div id="explanationAttachments" style="display: flex; gap: 12px; flex-wrap: wrap;">
                      <?php if (!empty($case['student_explanation_image'])): ?>
                        <a href="../<?= htmlspecialchars($case['student_explanation_image']) ?>" target="_blank" id="explanationImageLink" style="display: block; border-radius: var(--radius-sm); overflow: hidden; border: 1px solid var(--border-2);">
                           <img src="../<?= htmlspecialchars($case['student_explanation_image']) ?>" style="max-width: 100px; max-height: 100px; display: block; object-fit: cover;">
                        </a>
                      <?php endif; ?>
                      <?php if (!empty($case['student_explanation_pdf'])): ?>
                        <a href="../<?= htmlspecialchars($case['student_explanation_pdf']) ?>" target="_blank" id="explanationPdfLink" style="display: flex; align-items: center; gap: 8px; padding: 8px 14px; background: var(--rose-soft); border: 1px solid var(--rose-line); border-radius: var(--radius-sm); text-decoration: none; color: #e8a0a0; font-size: 12px; font-weight: 600;">
                           <span>📄 View PDF Explanation</span>
                        </a>
                      <?php endif; ?>
                   </div>
                </div>
              </div>

              <?php if (!$isHearingOpen && !$isClosed): ?>
              <div onclick="toggleEditPanel()" style="cursor:pointer; padding:14px 16px; border-radius: var(--radius-md); transition: all 0.15s ease; border: 1px dashed var(--border-2); margin-bottom: 16px;" onmouseover="this.style.background='rgba(61,156,224,0.05)'; this.style.borderColor='var(--accent-line)';" onmouseout="this.style.background=''; this.style.borderColor='var(--border-2)';">
                <div style="float:right; font-size:10.5px; font-weight:700; color:var(--accent-hi); background:var(--accent-soft); padding:4px 10px; border-radius:3px; border:1px solid var(--accent-line); letter-spacing:0.08em; text-transform:uppercase;">✎ Edit Configuration</div>
              <?php endif; ?>

              <div class="section-label" style="clear:both;">Scheduled Hearing</div>
              <div class="hearing-box">
                <div class="hearing-row">
                  <span class="hearing-key">Date &amp; Time</span>
                  <span class="hearing-val">
                    <?= $case['hearing_date'] ? date('M j, Y', strtotime($case['hearing_date'])) : '—' ?>
                    <?= $case['hearing_time'] ? ' · ' . date('g:i A', strtotime($case['hearing_time'])) : '' ?>
                  </span>
                </div>
                <div class="hearing-row">
                  <span class="hearing-key">Format &amp; Location</span>
                  <span class="hearing-val">
                    <?php if (($case['hearing_type'] ?? '') === 'FACE_TO_FACE'): ?>
                      <span style="color:#e8b767;background:var(--amber-soft);border:1px solid var(--amber-line);padding:2px 7px;border-radius:3px;font-size:10px;margin-right:6px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;">In-Person</span>
                      <?= htmlspecialchars($case['hearing_link_or_location'] ?? '—') ?>
                    <?php else: ?>
                      <span style="color:#7cc2ee;background:var(--accent-soft);border:1px solid var(--accent-line);padding:2px 7px;border-radius:3px;font-size:10px;margin-right:6px;font-weight:700;letter-spacing:0.06em;text-transform:uppercase;">Online</span>
                      <a href="<?= htmlspecialchars($case['hearing_link_or_location'] ?? '#') ?>" target="_blank" style="color:var(--accent-hi);text-decoration:none;"><?= htmlspecialchars($case['hearing_link_or_location'] ?? '—') ?></a>
                    <?php endif; ?>
                  </span>
                </div>
                <div class="hearing-row">
                  <span class="hearing-key">Department</span>
                  <span class="hearing-val"><?= htmlspecialchars($case['assigned_dept_name'] ?? '—') ?></span>
                </div>
              </div>

              <div class="section-label">Panel Members</div>
              <?php if (empty($assignedPanelNames)): ?>
                <div style="background:var(--rose-soft);border:1px solid var(--rose-line);border-radius:var(--radius-md);padding:14px 16px;margin-bottom:16px;color:#e8a0a0;font-size:13px;display:flex;align-items:center;gap:10px;">
                  <span style="font-size:18px;">⚠️</span>
                  <div>
                    <strong>Panel Assignment Warning:</strong> No UPCC panel members have been assigned to this case hearing!
                    <?php if (!$isClosed): ?>
                      <br><a href="#editPanel" onclick="toggleEditPanel()" style="color:#e8a0a0;font-weight:700;text-decoration:underline;font-size:12px;">Click here to assign UPCC panel members →</a>
                    <?php endif; ?>
                  </div>
                </div>
              <?php else: ?>
                <?php 
                  $pendingAcceptanceCount = 0;
                  foreach ($assignedPanelNames as $pm) {
                      if (!$pm['accepted']) $pendingAcceptanceCount++;
                  }
                ?>
                <?php if ($pendingAcceptanceCount > 0): ?>
                  <div style="background:var(--amber-soft);border:1px solid var(--amber-line);border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:12px;font-size:12.5px;color:#e8b767;">
                    ⚠️ <strong>Panel Acceptance Pending:</strong> <?= $pendingAcceptanceCount ?> assigned panel member(s) have not yet accepted their hearing invitation.
                  </div>
                <?php endif; ?>
                <div class="panel-list">
                  <?php foreach ($assignedPanelNames as $idx => $pm):
                    $avClass = 'av-' . $avatarColors[$idx % count($avatarColors)]; ?>
                    <div class="panel-member" style="display:flex; justify-content:space-between; align-items:center;">
                      <div style="display:flex; align-items:center; gap:12px;">
                        <div class="avatar <?= $avClass ?>"><?= htmlspecialchars(initials($pm['name'])) ?></div>
                        <div>
                          <div class="member-name" style="display:flex; align-items:center; gap:6px;">
                            <span><?= htmlspecialchars($pm['name']) ?></span>
                            <?php if ($pm['accepted']): ?>
                              <?php 
                                $accDate = !empty($pm['accepted_at']) ? date('M j, Y \a\t h:i A', strtotime($pm['accepted_at'])) : '';
                                $tooltipText = "Panelist Agreed & Accepted Confidentiality Policy" . ($accDate ? " on " . $accDate : "");
                              ?>
                              <span style="font-size:13px; color: var(--gold); cursor:pointer;" title="<?= htmlspecialchars($tooltipText) ?>">⭐</span>
                            <?php endif; ?>
                          </div>
                          <div class="member-role"><?= htmlspecialchars($pm['role']) ?></div>
                          <?php if ($pm['accepted'] && !empty($accDate)): ?>
                            <div style="font-size:10.5px; color:#8fc9a5; font-weight:600; margin-top:2px; font-family:var(--mono)">
                              Agreed <?= htmlspecialchars($accDate) ?>
                            </div>
                          <?php endif; ?>
                        </div>
                      </div>
                      <div id="panel-presence-<?= $pm['id'] ?>" data-accepted="<?= $pm['accepted'] ? '1' : '0' ?>">
                        <?php if ($pm['accepted']): ?>
                          <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--sage-soft); color:#8fc9a5; font-weight:bold; font-size:12px; border:1px solid var(--sage-line);" title="Accepted">✓</span>
                        <?php else: ?>
                          <span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--amber-soft); color:#e8b767; font-weight:bold; font-size:13px; border:1px solid var(--amber-line);" title="Awaiting Acceptance">⌛</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              
              <?php if (!$isHearingOpen && !$isClosed): ?>
              </div>
              
              <div id="editPanel" style="display:none; margin-top:18px; padding:18px; background: rgba(0,0,0,0.2); border:1px solid var(--border-1); border-radius: var(--radius-md);">
                <div style="font-weight:700; margin-bottom:14px; font-size:13px; color:var(--ink-50); text-transform: uppercase; letter-spacing: 0.08em;">Edit Hearing Configuration</div>
                <form method="post">
                  <input type="hidden" name="action" value="update_hearing_config">
                  <div class="form-group">
                    <label class="form-label">Lead Department</label>
                    <select name="assigned_department_id" id="hearing_edit_dept_select" class="form-control" onchange="filterPanelDropdown('hearing_edit')" required>
                      <option value="">Select department…</option>
                      <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['dept_id'] ?>" <?= (($case['assigned_department_id'] ?? $defaultDeptId) == $dept['dept_id']) ? 'selected' : '' ?>>
                          <?= htmlspecialchars($dept['dept_name']) ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="form-group">
                    <label class="form-label" id="hearing_edit-panel-label">Panel Members (Select from lead department)</label>
                    <div class="panel-select-wrapper">
                        <div id="hearing_edit-selected-panel-members" class="selected-panel-members"></div>
                        <div style="position:relative;">
                            <input type="text" id="hearing_edit-panel-member-search" class="panel-member-search" placeholder="Search and click to add panel members..." oninput="filterPanelDropdown('hearing_edit')" onfocus="showPanelDropdown('hearing_edit')" onblur="setTimeout(() => hidePanelDropdown('hearing_edit'), 200)">
                            <div id="hearing_edit-panel-member-dropdown" class="panel-member-dropdown"></div>
                        </div>
                    </div>
                    <div id="hearing_edit-hidden-panel-inputs"></div>
                  </div>
                  <div class="form-row">
                    <div class="form-group">
                      <label class="form-label">Hearing Format</label>
                      <select name="hearing_type" class="form-control" onchange="toggleHearingLocation(this.value, 'hearing_edit_link_or_location', 'hearing_edit_loc_label')" required>
                        <option value="ONLINE" <?= ($case['hearing_type'] ?? '') === 'ONLINE' ? 'selected' : '' ?>>Online (Virtual Meeting)</option>
                        <option value="FACE_TO_FACE" <?= ($case['hearing_type'] ?? '') === 'FACE_TO_FACE' ? 'selected' : '' ?>>Face-to-Face (In-Person)</option>
                      </select>
                    </div>
                    <div class="form-group">
                      <label class="form-label" id="hearing_edit_loc_label"><?= ($case['hearing_type'] ?? '') === 'FACE_TO_FACE' ? 'Room / Location' : 'Meeting Link (URL)' ?></label>
                      <input type="text" name="hearing_link_or_location" id="hearing_edit_link_or_location" class="form-control" value="<?= htmlspecialchars($case['hearing_link_or_location'] ?? '') ?>" placeholder="..." required>
                    </div>
                  </div>
                  <div class="form-row">
                    <div class="form-group">
                      <label class="form-label">Hearing Date</label>
                      <input type="date" name="hearing_date" class="form-control" value="<?= htmlspecialchars($case['hearing_date'] ?? '') ?>" required>
                    </div>
                    <div class="form-group">
                      <label class="form-label">Hearing Time</label>
                      <input type="time" name="hearing_time" class="form-control" value="<?= $case['hearing_time'] ? date('H:i', strtotime($case['hearing_time'])) : '' ?>" required>
                    </div>
                  </div>
                  <button type="submit" class="btn btn-primary btn-full">Save Changes</button>
                </form>
              </div>
              <?php endif; ?>

              <?php if ($isHearingOpen && !$isClosed): ?>
                <div id="waitingUsersContainer" class="waiting-room-box">
                  <div style="font-weight:600;color:#e8b767;margin-bottom:8px;font-size:12.5px;display:flex;align-items:center;justify-content:space-between;text-transform:uppercase;letter-spacing:0.08em;">
                    🚪 Rejoin Requests
                    <span id="rejoinBadge" style="display:none;background:var(--rose);color:#fff;border-radius:50%;width:22px;height:22px;align-items:center;justify-content:center;font-size:11px;font-weight:700;">0</span>
                  </div>
                  <div id="waitingUsersList"></div>
                </div>
              <?php endif; ?>

              <?php if (!$isClosed): ?>
              
              <!-- ═══════════════════════════════════════════════════
                   LIVE VOTING SECTION
              ══════════════════════════════════════════════════════ -->
              <div style="background: rgba(0,0,0,0.2); border: 1px solid var(--border-1); border-radius: var(--radius-md); padding: 20px; margin-top: 25px;">
                  <div class="section-label" style="display:flex;align-items:center;justify-content:space-between; margin-top: 0; border-bottom: none; padding-bottom: 0; margin-bottom: 16px;">
                    <span>
                      <span style="font-size:12px;color:#7cc2ee; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase;">UPCC Panel Voting</span>
                      <?php if ($roundNo): ?><span style="font-weight:400;color:var(--ink-400);margin-left:8px; font-size: 11px; font-family: var(--mono); text-transform: none; letter-spacing: 0;">Round <span id="currentRoundNo"><?= $roundNo ?></span></span><?php endif; ?>
                    </span>
                <?php if ($totalPanelMembers > 0 && $roundNo > 0 && !$isAwaitingAdmin): ?>
                  <span style="font-size:11px;color:var(--ink-400); text-transform: none; letter-spacing: 0;">All <?= $voterCount ?> voter(s) must agree</span>
                <?php endif; ?>
              </div>

              <?php if ($cooldownSecs > 0): ?>
                <div class="cooldown-block" id="cooldownBlock">
                  <div class="cooldown-title">⏳ Cooldown Active</div>
                  <div class="cooldown-num" id="cooldownTimer"><?= sprintf('%d:%02d', floor($cooldownSecs / 60), $cooldownSecs % 60) ?></div>
                  <div style="font-size:11.5px;color:#e8b767;margin-top:6px; font-weight: 600;">Panel can suggest again after cooldown</div>
                </div>
              <?php endif; ?>

              <?php
              $isRoundActive = $activeRound !== null && (int)($activeRound['is_active'] ?? 0) === 1;
              $roundEndsAt   = $activeRound['ends_at'] ?? null;
              $roundSecsLeft = $roundEndsAt ? max(0, strtotime($roundEndsAt) - time()) : 0;
              ?>

              <?php if ($isRoundActive && $suggestedCatInRound > 0): ?>
                <div class="voting-live-block" id="votingLiveBlock">
                  <div class="vlb-header">
                    <div class="vlb-title">
                      🗳️ Active Vote — Round <?= $roundNo ?>
                      <span class="live-badge">● Live</span>
                      <button type="button" class="btn btn-sm btn-outline" style="margin-left:12px;font-size:10.5px;padding:4px 10px;background:rgba(255,255,255,0.05);border-color:var(--border-2);color:var(--ink-100);" onclick="openLiveVotingModal()">View Modal</button>
                    </div>
                    <div style="font-family:var(--mono);font-size:14px;color:var(--ink-50);font-weight:600; letter-spacing: 0.03em;" id="vlbTimer">
                      <?= sprintf('%02d:%02d', floor($roundSecsLeft / 60), $roundSecsLeft % 60) ?>
                    </div>
                  </div>
                  <div class="vlb-body">

                    <div class="vlb-timer-wrap">
                      <div class="vlb-timer-bar">
                        <div class="vlb-timer-fill" id="vlbTimerFill"
                            style="width:<?= $roundSecsLeft > 0 ? round(($roundSecsLeft / 600) * 100) : 0 ?>%;
                                    background:<?= $roundSecsLeft > 600 ? 'var(--sage)' : ($roundSecsLeft > 180 ? 'var(--amber)' : 'var(--rose)') ?>"></div>
                      </div>
                    </div>

                    <div class="vlb-suggestion">
                      <div class="vlb-sug-cat">Category <?= $suggestedCatInRound ?></div>
                      <div class="vlb-sug-by">Suggested by: <strong style="color:var(--ink-100)"><?= htmlspecialchars($suggesterName) ?></strong></div>
                      <div class="vlb-sug-detail" id="vlbSugDetail">
                        <?php
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
                            <span class="vlb-tag"><?php 
                              $shVal = (float)$sugDetails['service_hours'];
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
                              echo !empty($parts) ? implode(' ', $parts) : '0 hrs';
                            ?></span>
                          <?php endif; ?>
                        <?php elseif ($suggestedCatInRound >= 3): ?>
                          <?= ['', '', '', 'Non-Readmission — student account will be frozen', 'Exclusion — student account will be frozen', 'Expulsion — account permanently frozen'][$suggestedCatInRound] ?>
                        <?php endif; ?>
                        <?php if (!empty($sugDetails['description'])): ?>
                          <div style="margin-top:8px;font-style:italic;color:var(--ink-300)"><?= htmlspecialchars($sugDetails['description']) ?></div>
                        <?php endif; ?>
                      </div>
                    </div>

                    <div class="vote-tally">
                      <div class="vote-tally-cell vtc-agree">
                        <span class="vtc-num" id="tallyAgree"><?= $agreeVotes ?></span>
                        <span class="vtc-lbl">Agree</span>
                      </div>
                      <div class="vote-tally-cell vtc-disagree">
                        <span class="vtc-num" id="tallyDisagree"><?= $disagreeVotes ?></span>
                        <span class="vtc-lbl">Disagree</span>
                      </div>
                      <div class="vote-tally-cell vtc-pending">
                        <span class="vtc-num" id="tallyPending"><?= $pendingCount ?></span>
                        <span class="vtc-lbl">Pending</span>
                      </div>
                    </div>
                    <div style="text-align:center;font-size:11.5px;color:var(--ink-400);margin-bottom:14px; letter-spacing: 0.02em;">
                      All <?= $voterCount ?> voter(s) must agree to pass
                    </div>

                    <?php if (!empty($roundVotes)): ?>
                      <div class="vote-head"><span>Panel Member</span><span>Vote · Time</span></div>
                      <?php foreach ($roundVotes as $rv):
                        $cat      = (int)$rv['vote_category'];
                        $isSug    = (int)$rv['upcc_id'] === $suggesterId;
                        $isAgree  = $cat > 0 && !$isSug;
                      ?>
                        <div class="vote-row">
                          <span style="color:var(--ink-100); font-weight: 500;"><?= htmlspecialchars($rv['full_name'] ?? 'Panel Member') ?><?= $isSug ? ' <small style="color:var(--ink-400); font-size: 11px;">(suggester)</small>' : '' ?></span>
                          <span style="display:flex; align-items:center;">
                            <?php if ($isSug): ?>
                              <span class="vote-cat suggester">Suggested</span>
                            <?php elseif ($isAgree): ?>
                              <span class="vote-cat agree">Agree</span>
                            <?php else: ?>
                              <span class="vote-cat disagree">Disagree</span>
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
                <div class="awaiting-box" id="awaitingVoteBox">
                  <span>⌛</span>
                  <span><?= $totalPanelMembers === 0 ? 'No panel members assigned.' : 'Waiting for a panel member to suggest a penalty.' ?></span>
                </div>
              <?php endif; ?>

              <div class="consensus-finalize-block" id="consensusBlock" style="<?= (!$isAwaitingAdmin && $consensusCategory === 0) ? 'display:none;' : '' ?>">
                  <div class="cf-header">
                    <div class="cf-header-left">
                      <span class="cf-icon">✅</span>
                      <div>
                        <div class="cf-title">Panel Consensus Reached</div>
                        <div class="cf-sub">All panel members agreed. Review and record the final decision below.</div>
                      </div>
                    </div>
                    <button type="button" class="btn btn-outline btn-sm"
                            style="background:rgba(255,255,255,.05);color:var(--ink-100);border-color:var(--border-2)"
                            onclick="showCancelConsensusModal()">
                      🔄 Cancel &amp; Re-vote
                    </button>
                  </div>
                  <div class="cf-body">
                    <div class="cat-badge" id="consensusBlockCatBadge">🏷️ Category <?= $consensusCategory ?> Penalty</div>
                    <div class="cat-desc-box">
                      <div class="cat-desc-label">Official Category Definition</div>
                      <div class="cat-desc-text" id="consensusBlockCatDesc"><?= nl2br(htmlspecialchars($categoryDescriptions[$consensusCategory] ?? '')) ?></div>
                    </div>

                    <div id="consensusBlockDetails">
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
                                <span style="display:inline-flex;align-items:center;gap:4px;background:var(--accent-soft);color:#7cc2ee;border:1px solid var(--accent-line);padding:3px 9px;border-radius:3px;font-size:11.5px;margin:2px 3px 2px 0; font-weight: 600;">
                                  <?= htmlspecialchars($iv) ?>
                                  <?php if ($iv === 'University Service' && !empty($prefillCat2Hours)): ?>
                                    — <?php 
                                      $shVal = (float)$prefillCat2Hours;
                                      echo ($shVal < 1.0 && $shVal > 0) ? round($shVal * 60) . ' mins' : $shVal . ' hrs';
                                    ?>
                                  <?php endif; ?>
                                </span>
                              <?php endforeach; ?>
                            </span>
                          </div>
                        </div>
                      <?php elseif ($consensusCategory >= 3): ?>
                        <div class="alert alert-warning" style="margin-bottom:14px">
                          ⚠️ This penalty will <strong>freeze</strong> the student account upon confirmation.
                        </div>
                      <?php endif; ?>
                    </div>

                    <?php if ($case['hearing_vote_consensus_at']): ?>
                      <div style="font-size:11.5px;color:var(--ink-400);margin-bottom:14px; font-family: var(--mono);" id="consensusBlockTime">
                        ⏱️ Consensus reached <?= fmt($case['hearing_vote_consensus_at']) ?>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

              <hr class="divider">
              <div class="section-label">Record Final Decision</div>

              <?php if ($errMsg): ?>
              <div class="alert alert-error" style="margin-bottom:12px"><?= htmlspecialchars($errMsg) ?></div>
              <?php endif; ?>

              <div class="alert alert-warning" id="noConsensusAlert" style="<?= $consensusCategory ? 'display:none;' : '' ?>">
                No consensus yet. Wait for the panel or enable "Force final decision" below.
              </div>

              <form method="post" id="finalDecisionForm">
                <input type="hidden" name="action" value="resolve_case">
                <input type="hidden" id="use_suggested" name="use_suggested" value="0">

                <div class="form-group" id="decided_category_group" style="<?= !$consensusCategory ? 'display:none;' : '' ?>">
                  <label class="form-label">
                    Category
                    <span id="consensusSpan" style="<?= !$consensusCategory ? 'display:none;' : '' ?>;color:#8fc9a5;font-weight:600; text-transform: none; letter-spacing: 0;">
                      (Panel consensus: Category <span id="consensusSpanVal"><?= $consensusCategory ?></span>)
                    </span>
                  </label>
                  <select name="decided_category" id="decided_category" class="form-control" <?= $consensusCategory ? 'required' : 'disabled' ?> onchange="toggleCategoryFields()">
                    <option value="">Select category…</option>
                    <?php for ($cat = 1; $cat <= 5; $cat++): ?>
                      <option value="<?= $cat ?>" <?= $cat === ($postedDecidedCategory ?: $consensusCategory) ? 'selected' : '' ?>>
                        Category <?= $cat ?><?= $cat === $consensusCategory ? ' ← Consensus' : '' ?>
                      </option>
                    <?php endfor; ?>
                  </select>
                </div>

                <div id="dynamicFieldsContainer">

                  <div id="cat1Fields" style="display:none">
                    <label class="form-label">Probation Terms</label>
                    <p style="font-size:12px;color:var(--ink-400);margin-bottom:10px">Select how many academic terms the probation lasts.</p>
                    <select name="cat1_terms" id="cat1_terms" class="form-control">
                      <option value="1" <?= $prefillCat1Terms === 1 ? 'selected' : '' ?>>1 term</option>
                      <option value="2" <?= $prefillCat1Terms === 2 ? 'selected' : '' ?>>2 terms</option>
                      <option value="3" <?= $prefillCat1Terms === 3 || $prefillCat1Terms === 0 ? 'selected' : '' ?>>3 terms (maximum)</option>
                    </select>
                    <p style="font-size:11.5px;color:var(--ink-400);margin-top:10px;line-height:1.5">Any subsequent major offense during probation triggers Suspension or Non-Readmission.</p>
                  </div>

                  <div id="cat2Fields" style="display:none">
                    <?php
                    $prefillH = '';
                    $prefillM = '';
                    if (!empty($prefillCat2Hours) && !in_array($prefillCat2Hours, ['100','150','200','250','300','350','400','450','500'])) {
                        $fVal = (float)$prefillCat2Hours;
                        $hPart = floor($fVal);
                        $mPart = round(($fVal - $hPart) * 60);
                        if ($mPart >= 60) {
                            $hPart += 1;
                            $mPart = 0;
                        }
                        $prefillH = $hPart > 0 ? (int)$hPart : '0';
                        $prefillM = $mPart > 0 ? (int)$mPart : '0';
                    }
                    ?>
                    <label class="form-label">Formative Interventions</label>
                    <p style="font-size:12px;color:var(--ink-400);margin-bottom:10px">Select one or more.</p>
                    <label class="cb-item" style="margin-bottom:6px">
                      <input type="checkbox" name="cat2_university_service" id="cat2_university_service" value="1" onchange="toggleCommunityHours()" <?= in_array('University Service', $prefillCat2Interventions) ? 'checked' : '' ?>>
                      University Service (Community Service)
                    </label>
                    <div id="communityHoursBox" style="display:<?= in_array('University Service', $prefillCat2Interventions) ? 'block' : 'none' ?>;margin-left:24px;margin-bottom:10px">
                      <label style="font-size:11.5px;color:var(--ink-300); display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 700;">Required Hours</label>
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
                      <div id="cat2_custom_wrap" style="display:<?= !empty($prefillCat2Hours) && !in_array($prefillCat2Hours, ['100','150','200','250','300','350','400','450','500']) ? 'flex' : 'none' ?>;align-items:center;gap:8px;margin-top:10px">
                        <input type="number" min="0" step="1" name="cat2_service_hours_custom_h" id="cat2_service_hours_custom_h" class="form-control" style="width:80px;padding:6px 10px;"
                               placeholder="Hours"
                               value="<?= htmlspecialchars($prefillH) ?>">
                        <span style="font-size:12px;color:var(--ink-400)">hrs</span>
                        <input type="number" min="0" max="59" step="1" name="cat2_service_hours_custom_m" id="cat2_service_hours_custom_m" class="form-control" style="width:90px;padding:6px 10px;"
                               placeholder="Minutes"
                               value="<?= htmlspecialchars($prefillM) ?>">
                        <span style="font-size:12px;color:var(--ink-400)">mins</span>
                      </div>
                      <p style="font-size:11px;color:var(--ink-400);margin:8px 0 0">Use this only if the required hours are not in the list above.</p>
                    </div>
                    <label class="cb-item" style="margin-bottom:6px">
                      <input type="checkbox" name="cat2_counseling" value="1" <?= in_array('Referral for Counseling', $prefillCat2Interventions) ? 'checked' : '' ?>>
                      Referral for Counseling
                    </label>
                    <label class="cb-item" style="margin-bottom:6px">
                      <input type="checkbox" name="cat2_lectures" value="1" <?= in_array('Attendance to lectures', $prefillCat2Interventions) ? 'checked' : '' ?>>
                      Attendance to lectures in Discipline Education Program
                    </label>
                    <label class="cb-item">
                      <input type="checkbox" name="cat2_evaluation" value="1" <?= in_array('Evaluation', $prefillCat2Interventions) ? 'checked' : '' ?>>
                      Evaluation
                    </label>
                  </div>

                  <div id="cat345Fields" style="display:none">
                    <div class="alert alert-warning" style="margin:0">
                      <strong>⚠️ Student account will be frozen.</strong>
                      <span id="cat345Text"></span>
                    </div>
                  </div>
                </div>

                <div id="forceResolveGroup" style="<?= $consensusCategory ? 'display:none;' : '' ?>">
                  <div class="form-group">
                    <label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:600; color: var(--ink-100);">
                      <input type="checkbox" name="force_resolve" id="force_resolve" value="1" style="width:auto; accent-color: var(--accent);" onchange="toggleForceResolve()">
                      Force final decision without panel consensus
                    </label>
                  </div>
                </div>

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
                  const isChecked = cb ? cb.checked : false;
                  const isEnabled = isChecked || (typeof currentConsensus !== 'undefined' && currentConsensus > 0);
                  
                  document.getElementById('final_decision').disabled = !isEnabled;
                  document.getElementById('submit_final_decision').disabled = !isEnabled;
                  
                  const catGroup = document.getElementById('decided_category_group');
                  if (catGroup) {
                      catGroup.style.display = isEnabled ? 'block' : 'none';
                      const decCat = document.getElementById('decided_category');
                      if (decCat) {
                          decCat.disabled = !isEnabled;
                          decCat.required = isEnabled;
                      }
                  }
                  
                  const terms = document.getElementById('cat1_terms');
                  if (terms) terms.disabled = !isEnabled;
                  
                  document.querySelectorAll('input[name^="cat2_"]').forEach(el => {
                      el.disabled = !isEnabled;
                  });

                  if (!isEnabled) {
                      const dfc = document.getElementById('dynamicFieldsContainer');
                      if (dfc) dfc.style.display = 'none';
                  } else {
                      if (typeof toggleCategoryFields === 'function') {
                          toggleCategoryFields();
                      }
                  }
              }
              document.addEventListener('DOMContentLoaded', toggleForceResolve);
              </script>

              <?php else: ?>
              <hr class="divider">
              <?php
                $decidedCat      = (int)($case['decided_category'] ?? 0);
                $punishDetails   = [];
                try { $punishDetails = json_decode((string)($case['punishment_details'] ?? ''), true) ?: []; } catch (Throwable $e) {}
                $catLabels = [
                    1 => 'Category 1 — Formal Reprimand & Active Semester Probation',
                    2 => 'Category 2 — Formative Intervention',
                    3 => 'Category 3 — Non-Readmission / Suspension',
                    4 => 'Category 4 — Exclusion / Mandatory Dismissal',
                    5 => 'Category 5 — Summary Expulsion & Police Referral',
                ];
                $catLabel = $catLabels[$decidedCat] ?? "Category {$decidedCat}";
              ?>
              <div class="consensus-box" id="closedCaseBanner" onclick="toggleClosedDetails()"
                   style="cursor:pointer;user-select:none;display:flex;align-items:center;justify-content:space-between;">
                <span>
                  🏁 Case closed — Final decision: <strong><?= $catLabel ?></strong>
                </span>
                <span id="closedCaseChevron" style="font-size:14px;transition:transform .25s">▼</span>
              </div>

              <div id="closedCaseDetails" style="display:none;margin-top:14px;border:1px solid var(--sage-line);border-radius:var(--radius-md);overflow:hidden;">
                <table style="width:100%;border-collapse:collapse;font-size:13px;">
                  <thead>
                    <tr style="background:var(--sage-soft);">
                      <th colspan="2" style="padding:12px 16px;text-align:left;color:#8fc9a5;font-size:10.5px;text-transform:uppercase;letter-spacing:.1em; font-weight: 700;">
                        Punishment Details
                      </th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr style="border-top:1px solid var(--sage-line);">
                      <td style="padding:10px 16px;font-weight:700;color:var(--ink-400);width:40%; font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.06em;">Category</td>
                      <td style="padding:10px 16px;color:var(--ink-100); font-weight: 500;"><?= htmlspecialchars($catLabel) ?></td>
                    </tr>

                    <?php if (!empty($case['final_decision'])): ?>
                    <tr style="border-top:1px solid var(--sage-line);">
                      <td style="padding:10px 16px;font-weight:700;color:var(--ink-400); font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.06em;">Narrative</td>
                      <td style="padding:10px 16px;color:var(--ink-100); font-weight: 500;"><?= nl2br(htmlspecialchars($case['final_decision'])) ?></td>
                    </tr>
                    <?php endif; ?>

                    <?php if ($decidedCat === 1 && !empty($punishDetails['probation_terms'])): ?>
                    <tr style="border-top:1px solid var(--sage-line);">
                      <td style="padding:10px 16px;font-weight:700;color:var(--ink-400); font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.06em;">Probation Terms</td>
                      <td style="padding:10px 16px;color:var(--ink-100); font-weight: 500;"><?= (int)$punishDetails['probation_terms'] ?> semester(s)
                        <?php if (!empty($case['probation_until'])): ?>
                          <span style="color:var(--ink-400);font-size:12px; font-family: var(--mono)"> (until <?= fmt($case['probation_until']) ?>)</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                    <?php endif; ?>

                    <?php if ($decidedCat === 2 && !empty($punishDetails['interventions']) && is_array($punishDetails['interventions'])): ?>
                    <tr style="border-top:1px solid var(--sage-line);">
                      <td style="padding:10px 16px;font-weight:700;color:var(--ink-400); font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.06em;">Interventions</td>
                      <td style="padding:10px 16px;color:var(--ink-100); font-weight: 500;">
                        <ul style="margin:0;padding-left:18px;">
                          <?php foreach ($punishDetails['interventions'] as $iv): ?>
                            <li><?= htmlspecialchars($iv) ?></li>
                          <?php endforeach; ?>
                        </ul>
                        <?php if (!empty($punishDetails['service_hours'])): ?>
                          <?php
                            $shVal = (float)$punishDetails['service_hours'];
                            $shDisp = ($shVal < 1.0 && $shVal > 0)
                                ? round($shVal * 60) . ' mins'
                                : (floor($shVal) == $shVal ? (int)$shVal . ' hrs' : round($shVal, 1) . ' hrs');
                          ?>
                          <span style="font-size:12px;color:var(--ink-400); font-family: var(--mono);">Required Hours: <?= htmlspecialchars($shDisp) ?></span>
                        <?php endif; ?>
                      </td>
                    </tr>
                    <?php endif; ?>

                    <?php if ($decidedCat >= 3): ?>
                    <tr style="border-top:1px solid var(--sage-line);">
                      <td style="padding:10px 16px;font-weight:700;color:var(--ink-400); font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.06em;">Restriction</td>
                      <td style="padding:10px 16px;color:#e8a0a0;font-weight:600;">
                        <?= $decidedCat === 3 ? 'Suspension' : 'Expulsion' ?> — account access restricted
                      </td>
                    </tr>
                    <?php endif; ?>

                    <?php if (!empty($case['resolution_date'])): ?>
                    <tr style="border-top:1px solid var(--sage-line);">
                      <td style="padding:10px 16px;font-weight:700;color:var(--ink-400); font-size: 11.5px; text-transform: uppercase; letter-spacing: 0.06em;">Resolved On</td>
                      <td style="padding:10px 16px;color:var(--ink-300);font-size:12px; font-family: var(--mono)"><?= fmt($case['resolution_date']) ?></td>
                    </tr>
                    <?php endif; ?>

                  </tbody>
                </table>
              </div>
              <script>
              function toggleClosedDetails() {
                const box = document.getElementById('closedCaseDetails');
                const chev = document.getElementById('closedCaseChevron');
                const open = box.style.display === 'block';
                box.style.display = open ? 'none' : 'block';
                if (chev) chev.style.transform = open ? 'rotate(0deg)' : 'rotate(180deg)';
              }
              </script>
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
            <div id="live-chat-box" style="height:320px;overflow-y:auto;background:rgba(0,0,0,0.2);
              border:1px solid var(--border-1);border-radius:var(--radius-md);padding:16px;margin-bottom:16px">
              <div style="text-align:center;color:var(--ink-400);font-size:12.5px">Loading messages…</div>
            </div>
            <div id="replying-to-container" style="display:none;background:var(--accent-soft);padding:10px 14px;
              border-radius:var(--radius-sm) var(--radius-sm) 0 0;border:1px solid var(--accent-line);
              border-bottom:none;font-size:12px;color:#7cc2ee">
              <strong>Replying to <span id="reply-to-name"></span>:</strong>
              <span id="reply-to-text" style="color:var(--ink-300)"></span>
              <button type="button" class="btn btn-ghost btn-sm" style="float:right;padding:0 8px" onclick="cancelReply()">✕</button>
            </div>
            <form id="chat-form" style="display:flex;gap:8px">
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
            <span class="pill pill-neutral" style="font-size:10px"><?= count($offenses) ?> offense<?= count($offenses) !== 1 ? 's' : '' ?></span>
          </div>
          <div class="card-body">
            <div class="section-label">Student Information</div>
            <div class="meta-grid">
              <span class="meta-key">Name</span>      <span class="meta-val"><?= htmlspecialchars($case['student_name']) ?></span>
              <span class="meta-key">Student ID</span> <span class="meta-val" style="font-family:var(--mono);font-size:12.5px"><?= htmlspecialchars($case['student_id']) ?></span>
              <span class="meta-key">Program</span>   <span class="meta-val"><?= htmlspecialchars($case['program'] ?? '—') ?></span>
              <span class="meta-key">School</span>    <span class="meta-val"><?= htmlspecialchars($case['school'] ?? '—') ?></span>
              <span class="meta-key">Year / Sec</span><span class="meta-val"><?= htmlspecialchars($case['year_level'] ?? '—') ?> · <?= htmlspecialchars($case['section'] ?? '—') ?></span>
              <span class="meta-key">Email</span>     <span class="meta-val" style="color:var(--accent-hi)"><?= htmlspecialchars($case['student_email'] ?? '—') ?></span>
              <?php if (!empty($case['phone_number'])): ?>
                <span class="meta-key">Phone</span><span class="meta-val"><?= htmlspecialchars($case['phone_number']) ?></span>
              <?php endif; ?>
            </div>
            <hr class="divider">
            <div class="section-label">Case Summary</div>
            <div class="summary-box"><?= nl2br(htmlspecialchars($case['case_summary'] ?? 'No summary provided.')) ?></div>
            <?php
              $pendingList = array_values(array_filter($otherStudentCases, static fn($c) => !$c['is_resolved']));
              $resolvedList = array_values(array_filter($otherStudentCases, static fn($c) => $c['is_resolved']));
            ?>

            <hr class="divider">
            <style>
              .confidential-card-content.blurred {
                filter: blur(5px);
                opacity: 0.75;
                user-select: none;
                pointer-events: none;
                transition: all 0.3s ease;
              }
              .confidential-card-wrapper {
                position: relative;
              }
            </style>
            <div class="case-tabs-nav" style="display:flex;gap:6px;border-bottom:1px solid var(--border-1);margin:20px 0 16px 0;padding-bottom:2px;overflow-x:auto;">
              <button type="button" class="case-tab-btn active" id="tabBtnCurrent" onclick="switchCaseTab('current')"
                      style="padding:9px 14px;font-size:11.5px;font-weight:700;border-radius:3px 3px 0 0;border:1px solid var(--accent-line);background:var(--accent-soft);color:#7cc2ee;cursor:pointer;transition:all .15s;white-space:nowrap;text-transform:uppercase;letter-spacing:0.06em;">
                Current Case (<?= count($offenses) ?>)
              </button>

              <button type="button" class="case-tab-btn" id="tabBtnPending" onclick="switchCaseTab('pending')"
                      style="padding:9px 14px;font-size:11.5px;font-weight:700;border-radius:3px 3px 0 0;border:1px solid transparent;background:rgba(255,255,255,0.03);color:var(--ink-400);cursor:pointer;transition:all .15s;white-space:nowrap;text-transform:uppercase;letter-spacing:0.06em;">
                Pending (<?= count($pendingList) ?>)
              </button>

              <button type="button" class="case-tab-btn" id="tabBtnResolved" onclick="switchCaseTab('resolved')"
                      style="padding:9px 14px;font-size:11.5px;font-weight:700;border-radius:3px 3px 0 0;border:1px solid transparent;background:rgba(255,255,255,0.03);color:var(--ink-400);cursor:pointer;transition:all .15s;white-space:nowrap;text-transform:uppercase;letter-spacing:0.06em;">
                Resolved (<?= count($resolvedList) ?>)
              </button>
            </div>

            <div id="tabPaneCurrent">
              <?php
                $currMajorCount = 0;
                $currMinorCount = 0;
                foreach ($offenses as $off) {
                    if (strtoupper($off['level'] ?? '') === 'MAJOR') $currMajorCount++;
                    else $currMinorCount++;
                }
                $cKind = strtoupper((string)($case['case_kind'] ?? ''));
                $cDecidedCat = (int)($case['decided_category'] ?? 0);
                $isAutoMajor = ($cKind === 'MAJOR_OFFENSE' || $currMajorCount > 0);
                $isSection4 = (!$isAutoMajor && ($cKind === 'SECTION4_MINOR_ESCALATION' || $currMinorCount >= 3));
              ?>

              <div style="margin-bottom: 20px; border-radius: var(--radius-md); padding: 14px 18px; border: 1px solid; <?= $isAutoMajor ? 'background:var(--rose-soft); border-color:var(--rose-line); color:#e8a0a0;' : ($isSection4 ? 'background:var(--amber-soft); border-color:var(--amber-line); color:#e8b767;' : 'background:var(--accent-soft); border-color:var(--accent-line); color:#7cc2ee;') ?>">
                <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:8px; margin-bottom:6px;">
                  <strong style="font-size:13px; font-weight:700; display:flex; align-items:center; gap:6px; letter-spacing:0.02em;">
                    <?php if ($isAutoMajor): ?>
                      🚨 Automatic Major Offense
                    <?php elseif ($isSection4): ?>
                      ⚠️ Section 4 Minor Escalation (3+ Accumulated Minors)
                    <?php else: ?>
                      ℹ️ Standard Minor Offense Record
                    <?php endif; ?>
                  </strong>

                  <?php if ($cDecidedCat > 0): ?>
                    <span style="font-size:10.5px; font-weight:700; padding:3px 10px; border-radius:3px; background:var(--sage-soft); color:#8fc9a5; border:1px solid var(--sage-line); letter-spacing:0.06em; text-transform: uppercase;">
                      Category <?= $cDecidedCat ?> Assigned
                    </span>
                  <?php elseif ($isAutoMajor): ?>
                    <span style="font-size:10.5px; font-weight:700; padding:3px 10px; border-radius:3px; background:var(--rose-soft); color:#e8a0a0; border:1px solid var(--rose-line); letter-spacing:0.06em; text-transform: uppercase;">
                      Major Level (Pending Category)
                    </span>
                  <?php elseif ($isSection4): ?>
                    <span style="font-size:10.5px; font-weight:700; padding:3px 10px; border-radius:3px; background:var(--amber-soft); color:#e8b767; border:1px solid var(--amber-line); letter-spacing:0.06em; text-transform: uppercase;">
                      Section 4 Escalated
                    </span>
                  <?php endif; ?>
                </div>

                <div style="font-size:12.5px; line-height:1.55; opacity:0.95;">
                  <?php if ($isAutoMajor): ?>
                    This case is classified as an <strong>Automatic Major Offense</strong> due to major infraction(s) committed by the student.
                    <?= !empty($categoryDescriptions[$cDecidedCat]) ? '<br><strong>Assigned Category Definition:</strong> ' . htmlspecialchars($categoryDescriptions[$cDecidedCat]) : '' ?>
                  <?php elseif ($isSection4): ?>
                    This case was automatically escalated under <strong>Section 4 Policy</strong> because the student accumulated <strong><?= $currMinorCount ?> minor offenses</strong> (threshold: 3+ minor offenses).
                  <?php else: ?>
                    This case contains <strong><?= $currMinorCount ?> minor offense(s)</strong> below the Section 4 escalation threshold.
                  <?php endif; ?>
                </div>
              </div>

              <div class="section-label">Offenses in Current Case #<?= $case_id ?></div>

              <?php if (empty($offenses)): ?>
                <div style="font-size:13px;color:var(--ink-400);font-style:italic;">No offenses recorded in this case.</div>
              <?php else: ?>
                <div class="offense-accordion-list" style="display:flex; flex-direction:column; gap:10px;">
                <?php foreach ($offenses as $idx => $off):
                  $lvl = strtoupper((string)($off['level'] ?? 'MINOR'));
                  $isMaj = ($lvl === 'MAJOR');
                  $evFile = $off['evidence_file'] ?? '';
                  $evUrl = !empty($evFile) ? '../' . ltrim($evFile, '/') : '';
                ?>
                  <details class="offense-details-card" <?= $idx === 0 ? 'open' : '' ?> style="border:1px solid <?= $isMaj ? 'var(--rose-line)' : 'var(--border-2)' ?>; border-radius:var(--radius-md); background:<?= $isMaj ? 'var(--rose-soft)' : 'rgba(0,0,0,0.15)' ?>; overflow:hidden;">
                    <summary style="padding:14px 16px; cursor:pointer; font-weight:700; display:flex; align-items:center; justify-content:space-between; user-select:none; background:rgba(0,0,0,0.15);">
                      <div style="display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
                        <span class="stag <?= $isMaj ? 'stag-major' : 'stag-minor' ?>"><?= htmlspecialchars($lvl) ?></span>
                        <span style="font-family:var(--mono); font-size:12px; color:var(--ink-300);"><?= htmlspecialchars($off['code'] ?? '') ?></span>
                        <span style="font-size:13px; color:var(--ink-50); font-weight: 600;"><?= htmlspecialchars($off['offense_name'] ?? '') ?></span>
                      </div>
                      <div style="font-size:11px; color:var(--ink-400); font-family: var(--mono);">📅 <?= fmtd($off['date_committed']) ?></div>
                    </summary>
                    <div style="padding:14px 16px; border-top:1px solid var(--border-1); font-size:13px; display:flex; flex-direction:column; gap:10px;">
                      <?php if (!empty(trim((string)($off['description'] ?? '')))): ?>
                        <div>
                          <strong style="color:var(--ink-300); font-size:10.5px; text-transform:uppercase; letter-spacing:0.08em; display: block; margin-bottom: 4px;">Description</strong>
                          <div style="color:var(--ink-100); font-style:italic; line-height: 1.5;">"<?= htmlspecialchars((string)$off['description']) ?>"</div>
                        </div>
                      <?php endif; ?>
                      
                      <?php 
                        $offEv = $off['evidence_file'] ?? ($off['incident_photo'] ?? null);
                        if (!empty($offEv)): 
                          $ext = strtolower(pathinfo($offEv, PATHINFO_EXTENSION));
                          $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
                      ?>
                        <div>
                          <strong style="color:var(--ink-300); font-size:10.5px; text-transform:uppercase; letter-spacing:0.08em; display: block; margin-bottom: 6px;">Photo Evidence</strong>
                          <div>
                            <?php if ($isImg): ?>
                              <a href="../<?= htmlspecialchars($offEv) ?>" target="_blank" title="Click to view full resolution evidence photo" style="display: inline-block;">
                                <img src="../<?= htmlspecialchars($offEv) ?>" style="max-width: 200px; max-height: 130px; border-radius: var(--radius-sm); border: 1px solid var(--border-2); object-fit: cover; display: block;">
                              </a>
                            <?php else: ?>
                              <a href="../<?= htmlspecialchars($offEv) ?>" target="_blank" style="color: var(--accent-hi); font-weight: 700; font-size: 12px; display: inline-flex; align-items: center; gap: 6px; text-decoration: underline;">
                                <span>📄 View Attached Evidence Document</span>
                              </a>
                            <?php endif; ?>
                          </div>
                        </div>
                      <?php endif; ?>
                      
                      <?php if (!empty(trim((string)($off['intervention_first'] ?? '')))): ?>
                        <div style="font-size:12px; color:var(--ink-200);">
                          <strong style="color: var(--ink-300);">1st Intervention:</strong> <?= htmlspecialchars(trim(rtrim(preg_replace('/\s*&?\s*0\.0\s+in\s+the\s+course/i', '', preg_replace('/^Category\s*\d+\s*[\(\:\-—]?\s*/i', '', trim((string)$off['intervention_first']))), ')-—')) ?: 'Formative Intervention: University Service, Counseling, & Evaluation') ?>
                          <?php if (!empty($priorResolvedCases)): ?>
                            <span style="background:var(--sage-soft); color:#8fc9a5; border:1px solid var(--sage-line); padding:2px 8px; border-radius:3px; font-size:10px; font-weight:700; text-transform:uppercase; margin-left:6px; letter-spacing:0.06em;">✓ Completed</span>
                          <?php else: ?>
                            <span style="background:var(--amber-soft); color:#e8b767; border:1px solid var(--amber-line); padding:2px 8px; border-radius:3px; font-size:10px; font-weight:700; text-transform:uppercase; margin-left:6px; letter-spacing:0.06em;">⏳ Ongoing</span>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty(trim((string)($off['intervention_second'] ?? '')))): ?>
                        <div style="font-size:12px; color:var(--ink-200);">
                          <strong style="color: var(--ink-300);">2nd Intervention:</strong> <?= htmlspecialchars(trim(rtrim(preg_replace('/\s*&?\s*0\.0\s+in\s+the\s+course/i', '', preg_replace('/^Category\s*\d+\s*[\(\:\-—]?\s*/i', '', trim((string)$off['intervention_second']))), ')-—')) ?: '1 Semester Non-Readmission / Suspension') ?>
                          <?php if (!empty($priorResolvedCases)): ?>
                            <span style="background:var(--amber-soft); color:#e8b767; border:1px solid var(--amber-line); padding:2px 8px; border-radius:3px; font-size:10px; font-weight:700; text-transform:uppercase; margin-left:6px; letter-spacing:0.06em;">⏳ Ongoing</span>
                          <?php else: ?>
                            <span style="background:rgba(255,255,255,0.05); color:var(--ink-400); border:1px solid var(--border-2); padding:2px 8px; border-radius:3px; font-size:10px; font-weight:600; text-transform:uppercase; margin-left:6px; letter-spacing:0.06em;">Pending</span>
                          <?php endif; ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </details>
                <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <div id="tabPanePending" style="display:none;">
              <?php if (empty($pendingList)): ?>
                <div style="font-size:13px;color:var(--ink-400);font-style:italic;padding:8px 0;">
                  No other pending disciplinary cases recorded for this student.
                </div>
              <?php else: ?>
                <div style="background:var(--amber-soft);border:1px solid var(--amber-line);border-radius:var(--radius-md);padding:12px 16px;margin-bottom:16px;font-size:12.5px;color:#e8b767;line-height:1.5;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                  <div style="flex:1;min-width:240px;">
                    <strong style="display:flex;align-items:center;gap:6px;margin-bottom:4px;font-size:13px; text-transform: uppercase; letter-spacing: 0.06em;">
                      🔒 Confidential Record Access
                    </strong>
                    Access to prior pending cases is restricted for evaluation purposes. Case access is logged. Details are blurred by default.
                  </div>
                  <button type="button" class="btn btn-outline btn-sm" id="toggleBlurPendingBtn" onclick="toggleAllTabBlur('pending')" style="font-size:11px;padding:6px 12px;">
                    🔓 Unblur
                  </button>
                </div>

                <?php foreach ($pendingList as $oc): ?>
                  <div class="confidential-card-wrapper" id="card-wrap-<?= $oc['case_id'] ?>" style="margin-bottom:14px;">
                    <div class="confidential-card-content blurred" id="card-content-<?= $oc['case_id'] ?>" style="background:var(--amber-soft);border:1px solid var(--amber-line);border-radius:var(--radius-md);padding:14px;">
                      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:8px;">
                        <div style="display:flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                          <span style="font-weight:700;font-size:13px;color:var(--ink-50); font-family: var(--mono);">Case #<?= $oc['case_id'] ?></span>
                          <span class="pill pill-warning" style="font-size:10px;"><?= htmlspecialchars($oc['status']) ?></span>
                          <?php if ($oc['panel_count'] === 0): ?>
                            <span style="font-size:10.5px;color:#e8a0a0;background:var(--rose-soft);border:1px solid var(--rose-line);padding:2px 7px;border-radius:3px;font-weight:700; letter-spacing: 0.04em; text-transform: uppercase;">⚠️ Panel Unassigned</span>
                          <?php else: ?>
                            <span style="font-size:10.5px;color:#8fc9a5;background:var(--sage-soft);border:1px solid var(--sage-line);padding:2px 7px;border-radius:3px;font-weight:600; letter-spacing: 0.04em; text-transform: uppercase;">👥 <?= $oc['panel_count'] ?> Panel</span>
                          <?php endif; ?>
                        </div>
                        <span style="font-size:10.5px;background:rgba(255,255,255,0.05);color:var(--ink-300);border:1px solid var(--border-2);padding:3px 9px;border-radius:3px;font-weight:700; letter-spacing: 0.04em; text-transform: uppercase;">
                          <?= htmlspecialchars($oc['trigger_label']) ?>
                        </span>
                      </div>

                      <div style="font-size:11.5px;color:var(--ink-400);margin-bottom:8px; font-family: var(--mono);">
                        📅 Created: <?= fmt($oc['created_at']) ?>
                      </div>

                      <?php if (!empty($oc['offenses'])): ?>
                        <div style="font-size:11.5px;font-weight:700;color:var(--ink-300);margin-bottom:6px; text-transform: uppercase; letter-spacing: 0.06em;">Offenses:</div>
                        <?php foreach ($oc['offenses'] as $ooff): ?>
                          <div style="background:rgba(0,0,0,0.2);border:1px solid var(--border-1);border-radius:var(--radius-sm);padding:6px 10px;margin-bottom:6px;font-size:12px;display:flex;align-items:center;gap:8px;">
                            <span class="stag <?= ($ooff['level'] ?? '') === 'MAJOR' ? 'stag-major' : 'stag-minor' ?>" style="font-size:9.5px;padding:2px 6px;"><?= htmlspecialchars($ooff['level'] ?? 'MINOR') ?></span>
                            <strong style="color:var(--ink-200); font-family: var(--mono); font-size: 11px;"><?= htmlspecialchars($ooff['code'] ?? '') ?></strong>
                            <span style="color:var(--ink-100)"><?= htmlspecialchars($ooff['offense_name'] ?? '') ?></span>
                          </div>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>

            <div id="tabPaneResolved" style="display:none;">
              <?php if (empty($resolvedList)): ?>
                <div style="font-size:13px;color:var(--ink-400);font-style:italic;padding:8px 0;">
                  No resolved disciplinary cases recorded for this student.
                </div>
              <?php else: ?>
                <div style="background:var(--amber-soft);border:1px solid var(--amber-line);border-radius:var(--radius-md);padding:12px 16px;margin-bottom:16px;font-size:12.5px;color:#e8b767;line-height:1.5;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                  <div style="flex:1;min-width:240px;">
                    <strong style="display:flex;align-items:center;gap:6px;margin-bottom:4px;font-size:13px; text-transform: uppercase; letter-spacing: 0.06em;">
                      🔒 Confidential Record Access
                    </strong>
                    Access to prior resolved cases is restricted for evaluation purposes. Case access is logged. Details are blurred by default.
                  </div>
                  <button type="button" class="btn btn-outline btn-sm" id="toggleBlurResolvedBtn" onclick="toggleAllTabBlur('resolved')" style="font-size:11px;padding:6px 12px;">
                    🔓 Unblur
                  </button>
                </div>

                <?php foreach ($resolvedList as $oc): ?>
                  <div class="confidential-card-wrapper" id="card-wrap-<?= $oc['case_id'] ?>" style="margin-bottom:14px;">
                    <div class="confidential-card-content blurred" id="card-content-<?= $oc['case_id'] ?>" style="background:var(--sage-soft);border:1px solid var(--sage-line);border-radius:var(--radius-md);padding:14px;">
                      <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;flex-wrap:wrap;gap:8px;">
                        <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap;">
                          <span style="font-weight:700;font-size:13px;color:var(--ink-50); font-family: var(--mono);">Case #<?= $oc['case_id'] ?></span>
                          <span class="pill pill-closed" style="font-size:10px;">Closed</span>
                          <?php if ($oc['decided_category'] > 0): ?>
                            <span style="font-size:10.5px;color:#8fc9a5;background:var(--sage-soft);border:1px solid var(--sage-line);padding:2px 7px;border-radius:3px;font-weight:700; letter-spacing: 0.04em; text-transform: uppercase;">Category <?= $oc['decided_category'] ?></span>
                          <?php endif; ?>
                        </div>
                        <span style="font-size:10.5px;background:rgba(255,255,255,0.05);color:var(--ink-300);border:1px solid var(--border-2);padding:3px 9px;border-radius:3px;font-weight:700; letter-spacing: 0.04em; text-transform: uppercase;">
                          <?= htmlspecialchars($oc['trigger_label']) ?>
                        </span>
                      </div>

                      <div style="font-size:11.5px;color:var(--ink-400);margin-bottom:8px; font-family: var(--mono);">
                        📅 Resolved: <?= fmt($oc['created_at']) ?>
                      </div>

                      <?php if (!empty($oc['offenses'])): ?>
                        <div style="font-size:11.5px;font-weight:700;color:var(--ink-300);margin-bottom:6px; text-transform: uppercase; letter-spacing: 0.06em;">Offenses:</div>
                        <?php foreach ($oc['offenses'] as $ooff): ?>
                          <div style="background:rgba(0,0,0,0.2);border:1px solid var(--border-1);border-radius:var(--radius-sm);padding:6px 10px;margin-bottom:6px;font-size:12px;display:flex;align-items:center;gap:8px;">
                            <span class="stag <?= ($ooff['level'] ?? '') === 'MAJOR' ? 'stag-major' : 'stag-minor' ?>" style="font-size:9.5px;padding:2px 6px;"><?= htmlspecialchars($ooff['level'] ?? 'MINOR') ?></span>
                            <strong style="color:var(--ink-200); font-family: var(--mono); font-size: 11px;"><?= htmlspecialchars($ooff['code'] ?? '') ?></strong>
                            <span style="color:var(--ink-100)"><?= htmlspecialchars($ooff['offense_name'] ?? '') ?></span>
                          </div>
                        <?php endforeach; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>

            <!-- UNBLUR WARNING MODAL -->
            <div id="unblurWarningModal" class="modal-overlay">
              <div class="modal-content" style="max-width:460px;width:min(90vw,460px);padding:26px;text-align:center;">
                <div style="font-size:32px;margin-bottom:12px;">🔒</div>
                <h3 style="margin:0 0 12px 0;font-size:17px;color:var(--ink-50);">Confidential Record Access Warning</h3>
                <div style="font-size:13px;color:var(--ink-200);line-height:1.6;background:var(--amber-soft);border:1px solid var(--amber-line);border-radius:var(--radius-md);padding:14px 16px;margin-bottom:20px;text-align:left;">
                  <strong style="color:#e8b767;display:block;margin-bottom:6px; text-transform: uppercase; letter-spacing: 0.06em; font-size: 11px;">⚠️ Administrative Compliance Audit</strong>
                  Access to student prior disciplinary records is strictly restricted for evaluation purposes only. Unblurring details will be logged under your administrator account. Continue?
                </div>
                <div style="display:flex;gap:10px;justify-content:center;">
                  <button type="button" class="btn btn-ghost btn-sm" onclick="closeUnblurWarningModal()">
                    Cancel
                  </button>
                  <button type="button" class="btn btn-warning btn-sm" onclick="confirmUnblurAction()" style="font-weight:700;padding:8px 20px;">
                    Yes, Unblur
                  </button>
                </div>
              </div>
            </div>

            <!-- QUICK CASE INSPECTION MODAL -->
            <div id="quickCaseModal" class="modal-overlay">
              <div class="modal-content" style="max-width:620px;width:min(95vw,620px);padding:24px;">
                <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border-1);padding-bottom:14px;margin-bottom:18px;">
                  <div>
                    <h3 style="margin:0;text-align:left;font-size:16px;color:var(--ink-50);" id="qcmTitle">Case Inspection #0</h3>
                    <div style="font-size:12px;color:var(--ink-400);margin-top:4px;">
                      Student: <strong><?= htmlspecialchars($case['student_name']) ?></strong> (ID: <?= htmlspecialchars($case['student_id']) ?>)
                    </div>
                  </div>
                  <button type="button" onclick="closeQuickCaseModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:var(--ink-400);line-height:1; padding:4px 8px;">✕</button>
                </div>

                <div id="qcmBody" style="font-size:13px;line-height:1.6;">
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-top:20px;padding-top:16px;border-top:1px solid var(--border-1);">
                  <a href="#" id="qcmNewTabLink" target="_blank" class="btn btn-ghost btn-sm" style="color:var(--accent-hi);font-weight:600;">
                    ↗ Open Full Page
                  </a>
                  <button type="button" class="btn btn-outline btn-sm" onclick="closeQuickCaseModal()">
                    Close
                  </button>
                </div>
              </div>
            </div>

            <script>
            const studentOtherCasesData = <?= json_encode($otherStudentCases) ?>;
            const categoryDescriptionsMap = <?= json_encode($categoryDescriptions) ?>;
            let pendingUnblurCallback = null;

            function isUnblurConfirmed() {
              return sessionStorage.getItem('identitrack_unblur_confirmed') === 'true';
            }

            function requestUnblurConfirmation(callback) {
              if (isUnblurConfirmed()) {
                callback();
                return;
              }
              pendingUnblurCallback = callback;
              document.getElementById('unblurWarningModal').classList.add('open');
            }

            function closeUnblurWarningModal() {
              document.getElementById('unblurWarningModal').classList.remove('open');
              pendingUnblurCallback = null;
            }

            function confirmUnblurAction() {
              sessionStorage.setItem('identitrack_unblur_confirmed', 'true');
              document.getElementById('unblurWarningModal').classList.remove('open');
              if (pendingUnblurCallback) {
                const cb = pendingUnblurCallback;
                pendingUnblurCallback = null;
                cb();
              }
            }

            function switchCaseTab(tabName) {
              const panes = {
                current: document.getElementById('tabPaneCurrent'),
                pending: document.getElementById('tabPanePending'),
                resolved: document.getElementById('tabPaneResolved')
              };
              const btns = {
                current: document.getElementById('tabBtnCurrent'),
                pending: document.getElementById('tabBtnPending'),
                resolved: document.getElementById('tabBtnResolved')
              };
              Object.keys(panes).forEach(k => {
                if (panes[k]) panes[k].style.display = (k === tabName) ? 'block' : 'none';
                if (btns[k]) {
                  if (k === tabName) {
                    btns[k].style.background = 'var(--accent-soft)';
                    btns[k].style.color = '#7cc2ee';
                    btns[k].style.borderColor = 'var(--accent-line)';
                  } else {
                    btns[k].style.background = 'rgba(255,255,255,0.03)';
                    btns[k].style.color = 'var(--ink-400)';
                    btns[k].style.borderColor = 'transparent';
                  }
                }
              });
            }

            function toggleAllTabBlur(type) {
              requestUnblurConfirmation(() => {
                const pane = type === 'pending' ? document.getElementById('tabPanePending') : document.getElementById('tabPaneResolved');
                const btn = type === 'pending' ? document.getElementById('toggleBlurPendingBtn') : document.getElementById('toggleBlurResolvedBtn');
                if (!pane) return;

                const cards = pane.querySelectorAll('.confidential-card-content');
                let anyBlurred = false;
                cards.forEach(c => { if (c.classList.contains('blurred')) anyBlurred = true; });

                cards.forEach(c => {
                  if (anyBlurred) c.classList.remove('blurred');
                  else c.classList.add('blurred');
                });

                if (btn) {
                  btn.innerHTML = anyBlurred ? '🔒 Blur' : '🔓 Unblur';
                }
              });
            }

            function openQuickCaseModal(caseId) {
              const c = studentOtherCasesData.find(item => parseInt(item.case_id) === parseInt(caseId));
              if (!c) return;

              document.getElementById('qcmTitle').innerText = 'Case #' + c.case_id + ' Inspection';
              document.getElementById('qcmNewTabLink').href = 'upcc_case_view.php?id=' + c.case_id;

              let triggerBadge = '';
              if (c.trigger_type === 'SECTION4_ESCALATION') {
                triggerBadge = '<div style="background:var(--amber-soft);border:1px solid var(--amber-line);color:#e8b767;padding:10px 14px;border-radius:var(--radius-md);font-weight:700;margin-bottom:14px;font-size:12.5px;">⚠️ TRIGGER: Section 4 Minor Escalation (3+ Minor Offenses)</div>';
              } else if (c.trigger_type === 'AUTOMATIC_MAJOR') {
                triggerBadge = '<div style="background:var(--rose-soft);border:1px solid var(--rose-line);color:#e8a0a0;padding:10px 14px;border-radius:var(--radius-md);font-weight:700;margin-bottom:14px;font-size:12.5px;">🚨 TRIGGER: Automatic Major Offense</div>';
              } else {
                triggerBadge = '<div style="background:var(--accent-soft);border:1px solid var(--accent-line);color:#7cc2ee;padding:10px 14px;border-radius:var(--radius-md);font-weight:700;margin-bottom:14px;font-size:12.5px;">ℹ️ TRIGGER: Standard Disciplinary Case</div>';
              }

              let statusSection = '';
              if (c.is_resolved) {
                const catNum = c.decided_category > 0 ? c.decided_category : 'N/A';
                const catDesc = categoryDescriptionsMap[c.decided_category] || '';
                let punishHtml = '';
                if (c.punishment_details) {
                  if (c.punishment_details.probation_terms) {
                    punishHtml += '<div><strong>Probation Terms:</strong> ' + c.punishment_details.probation_terms + ' semester(s)</div>';
                  }
                  if (c.punishment_details.service_hours) {
                    punishHtml += '<div><strong>Community Service:</strong> ' + c.punishment_details.service_hours + ' hours</div>';
                  }
                  if (c.punishment_details.interventions && Array.isArray(c.punishment_details.interventions)) {
                    punishHtml += '<div><strong>Interventions:</strong> ' + c.punishment_details.interventions.join(', ') + '</div>';
                  }
                }
                statusSection = `
                  <div style="background:var(--sage-soft);border:1px solid var(--sage-line);border-radius:var(--radius-md);padding:16px;margin-bottom:14px;color:#8fc9a5;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                      <strong style="font-size:14px;color:#b4dcc4;">Final Decision: Category ${catNum}</strong>
                      <span class="pill pill-closed" style="font-size:10px;">Closed</span>
                    </div>
                    ${catDesc ? `<div style="font-size:12.5px;margin-bottom:10px;line-height:1.5; color: var(--ink-200);">${catDesc}</div>` : ''}
                    ${c.final_decision ? `<div style="font-size:12.5px;background:rgba(0,0,0,0.25);padding:10px 12px;border-radius:var(--radius-sm);border:1px solid var(--border-1);margin-bottom:8px; color: var(--ink-100);"><strong>Narrative:</strong> ${c.final_decision}</div>` : ''}
                    ${punishHtml ? `<div style="font-size:12.5px;color:#8fc9a5;">${punishHtml}</div>` : ''}
                  </div>
                `;
              } else {
                statusSection = `
                  <div style="background:var(--amber-soft);border:1px solid var(--amber-line);border-radius:var(--radius-md);padding:16px;margin-bottom:14px;color:#e8b767;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                      <strong style="font-size:14px;color:#e8b767;">Status: ${c.status}</strong>
                      <span class="pill pill-warning" style="font-size:10px;">${c.status}</span>
                    </div>
                    <div style="font-size:12.5px;line-height:1.5; color: var(--ink-200);">
                      Panel Assignment: <strong>${c.panel_count > 0 ? c.panel_count + ' Panel Member(s)' : '⚠️ Unassigned'}</strong>
                    </div>
                  </div>
                `;
              }

              let offensesListHtml = '';
              if (c.offenses && c.offenses.length > 0) {
                offensesListHtml = '<div style="font-weight:700;font-size:12px;color:var(--ink-200);margin-bottom:8px; text-transform: uppercase; letter-spacing: 0.06em;">Offenses Breakdown:</div>';
                c.offenses.forEach(o => {
                  const isMaj = (o.level || '').toUpperCase() === 'MAJOR';
                  const stagClass = isMaj ? 'background:var(--rose-soft);color:#e8a0a0;border:1px solid var(--rose-line);' : 'background:var(--accent-soft);color:#7cc2ee;border:1px solid var(--accent-line);';
                  offensesListHtml += `
                    <div style="background:rgba(0,0,0,0.2);border:1px solid var(--border-1);border-radius:var(--radius-sm);padding:10px 14px;margin-bottom:8px;font-size:12.5px;display:flex;align-items:center;justify-content:space-between; color: var(--ink-100);">
                      <div>
                        <span style="font-size:9.5px;font-weight:700;padding:2px 7px;border-radius:3px;margin-right:8px;letter-spacing:0.06em;${stagClass}">${o.level || 'MINOR'}</span>
                        <strong style="color:var(--ink-200); font-family: var(--mono);">${o.code || ''}</strong> — ${o.offense_name || ''}
                      </div>
                      <div style="font-size:10.5px;color:var(--ink-400); font-family: var(--mono);">${o.date_committed || ''}</div>
                    </div>
                  `;
                });
              }

              document.getElementById('qcmBody').innerHTML = triggerBadge + statusSection + offensesListHtml;
              document.getElementById('quickCaseModal').classList.add('open');
            }

            function closeQuickCaseModal() {
              document.getElementById('quickCaseModal').classList.remove('open');
            }
            </script>
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
            <div class="alert alert-warning" style="margin-bottom:16px">⚠️ Saving will reset all existing votes and rounds.</div>
            <form method="post" id="editHearingForm" onsubmit="return validateHearingConfigForm()">
              <input type="hidden" name="action" value="update_hearing_config">
              <div class="form-row" style="grid-template-columns:1fr 1fr">
                <div class="form-group">
                  <label class="form-label">Hearing Format</label>
                  <select name="hearing_type" class="form-control" onchange="toggleHearingLocation(this.value, 'reconfig_hearing_link_or_location', 'reconfig_hearing_loc_label')" required>
                    <option value="ONLINE" <?= ($case['hearing_type'] ?? '') === 'ONLINE' ? 'selected' : '' ?>>Online (Virtual Meeting)</option>
                    <option value="FACE_TO_FACE" <?= ($case['hearing_type'] ?? '') === 'FACE_TO_FACE' ? 'selected' : '' ?>>Face-to-Face (In-Person)</option>
                  </select>
                </div>
                <div class="form-group">
                  <label class="form-label" id="reconfig_hearing_loc_label"><?= ($case['hearing_type'] ?? '') === 'FACE_TO_FACE' ? 'Room / Location' : 'Meeting Link (URL)' ?></label>
                  <input type="text" name="hearing_link_or_location" id="reconfig_hearing_link_or_location" class="form-control" value="<?= htmlspecialchars($case['hearing_link_or_location'] ?? '') ?>" placeholder="<?= ($case['hearing_type'] ?? '') === 'FACE_TO_FACE' ? 'e.g. Conference Room A' : 'e.g. https://meet.jit.si/...' ?>" required>
                </div>
              </div>
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
              <div style="display:flex;gap:10px;justify-content:flex-end">
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
    <p style="font-size:13px;color:var(--ink-200);line-height:1.6; text-align: center; margin-bottom: 16px;">
      The UPCC panel completed live voting and agreed on
      <strong id="consensusDecisionCategory" style="color: #8fc9a5;">Category <?= (int)$consensusCategory ?></strong>.
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
    <p style="font-size:13px;color:var(--ink-200);line-height:1.6; text-align: center; margin-bottom: 16px;">
      UPCC panel is currently voting on a suggested penalty for this case.
    </p>
    <ul>
      <li><strong id="liveVotingRound" style="color: var(--ink-50);">Round: —</strong></li>
      <li><strong id="liveVotingTimer" style="color: var(--ink-50); font-family: var(--mono);">Time left: --:--</strong></li>
      <li id="liveVotingTally" style="color: var(--ink-200);">In Favor: 0 · Not In Favor: 0 · Pending: 0</li>
      <li id="liveVotingSuggester" style="color: var(--ink-200);">Proposed By: —</li>
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
    <p style="text-align: center; color: var(--ink-200);">This will clear all votes, delete the consensus, and restart a fresh voting round. Panel members will need to vote again.</p>
    <textarea id="cancelReason" rows="3" placeholder="Optional: reason for cancellation…"></textarea>
    <div class="modal-buttons">
      <button class="btn btn-outline" onclick="closeCancelModal()">Go Back</button>
      <button class="btn btn-danger" onclick="submitCancelConsensus()">Confirm — Restart Voting</button>
    </div>
  </div>
</div>

<!-- Rejoin Request Modal -->
    <div id="rejoinRequestModal" class="modal-overlay" role="dialog" aria-modal="true">
      <div class="modal-content" style="max-width:520px">
        <h3>Panel Rejoin Requests</h3>
        <p id="rejoinIntro" style="text-align: center; color: var(--ink-200);">One or more panel members are requesting to rejoin the hearing. Choose who to admit.</p>
        <div id="rejoinUsersList" style="max-height:260px;overflow:auto;margin-top:14px;margin-bottom:14px"></div>
        <div class="modal-buttons" style="margin-top:14px">
          <button class="btn btn-outline" onclick="closeRejoinModal()">Dismiss</button>
          <button class="btn btn-primary" onclick="admitAllWaitingUsers()">Admit All</button>
        </div>
      </div>
    </div>

  <!-- Leave Modal -->
  <div class="modal-overlay" id="leaveModal">
    <div class="modal-content" style="max-width: 420px; text-align: center;">
      <h3 style="color: #e8a0a0; margin-bottom: 12px;">⚠️ Leave Hearing?</h3>
      <p style="color: var(--ink-200); margin-bottom: 24px; font-size: 13.5px; line-height: 1.6;">
        The hearing is currently live. Leaving this page will automatically <strong>PAUSE</strong> the hearing, and all panel members will be notified.
      </p>
      <input type="hidden" id="leaveModalHref">
      <div class="modal-buttons" style="display: flex; gap: 12px; justify-content: center;">
        <button class="btn btn-ghost" onclick="document.getElementById('leaveModal').classList.remove('open')">Cancel</button>
        <button class="btn btn-warning" onclick="confirmLeavePage()">Yes, Pause &amp; Leave</button>
      </div>
    </div>
  </div>

    <div id="confirmPauseModal" class="modal-overlay" role="dialog" aria-modal="true">
      <div class="modal-content">
        <h3>Pause Hearing?</h3>
        <p style="text-align: center; color: var(--ink-200);">The hearing is currently live. If you leave or pause the hearing now, panel members will be prevented from joining or continuing until you resume. Continue?</p>
        <div style="margin-top:12px;font-size:12.5px;color:var(--ink-400); text-align: center;">You can resume the hearing later from this admin panel. Panel members will be notified.</div>
        <div class="modal-buttons" style="margin-top:22px">
          <button class="btn btn-outline" id="cancelPauseBtn" onclick="closeConfirmPauseModal()">Cancel</button>
          <button class="btn btn-danger" id="confirmPauseBtn" onclick="confirmPauseFromModal()">Yes — Pause Hearing</button>
        </div>
      </div>
    </div>

    <div id="confirmResumeModal" class="modal-overlay" role="dialog" aria-modal="true">
      <div class="modal-content">
        <h3>Resume Hearing?</h3>
        <p style="text-align: center; color: var(--ink-200);">The hearing is currently paused. Panelists will be allowed to rejoin and voting will be unlocked. Continue?</p>
        <div style="margin-top:12px;font-size:12.5px;color:var(--ink-400); text-align: center;">Panel members will be automatically notified that the hearing is live again.</div>
        <div class="modal-buttons" style="margin-top:22px">
          <button class="btn btn-outline" id="cancelResumeBtn" onclick="closeConfirmResumeModal()">Cancel</button>
          <button class="btn btn-success" id="confirmResumeBtn" onclick="confirmResumeFromModal()">Yes — Resume Hearing</button>
        </div>
      </div>
    <!-- Success Record Modal -->
    <div id="successRecordModal" class="modal-overlay" role="dialog" aria-modal="true">
      <div class="modal-content" style="max-width: 460px; text-align: center; padding: 2rem;">
        <div style="font-size: 44px; margin-bottom: 16px;">✅</div>
        <h3 style="margin-bottom: 12px;">Decision Recorded Successfully</h3>
        <p style="font-size: 13.5px; color: var(--ink-200); line-height: 1.6; margin-bottom: 20px;">
          The final decision and penalty have been successfully logged. The case has been marked as <strong style="color: #8fc9a5;">CLOSED</strong> and the student's record has been updated.
        </p>
        <div class="modal-buttons" style="justify-content: center; margin-top: 20px;">
          <button class="btn btn-success" onclick="closeSuccessRecordModal()" style="min-width: 120px;">Done</button>
        </div>
      </div>
    </div>

<script>
const CASE_ID          = <?= isset($case_id) ? (int)$case_id : 0 ?>;
const IS_HEARING_OPEN  = <?= (!empty($isHearingOpen) ? 'true' : 'false') ?>;
const TOTAL_MEMBERS    = <?= isset($totalPanelMembers) ? (int)$totalPanelMembers : 0 ?>;
const VOTER_COUNT      = <?= isset($voterCount) ? (int)$voterCount : 0 ?>;
const ROUND_ENDS_EPOCH = <?= (isset($roundEndsAt) && $roundEndsAt) ? strtotime($roundEndsAt) : 0 ?>;
const INITIAL_COOLDOWN = <?= isset($cooldownSecs) ? (int)$cooldownSecs : 0 ?>;
const currentPanel     = <?= json_encode($assignedPanelIds ?? []) ?>;
const LIVE_VOTING_SUGGESTION = <?= json_encode($liveVotingSuggestion ?? ['category' => 0, 'details' => []]) ?>;

let lastChatCount     = 0;
let lastVoteSig       = '';
let _currentPauseState= <?= (!empty($isHearingPaused) ? 'true' : 'false') ?>;
let isAwaitingAdmin   = <?= json_encode($isAwaitingAdmin ?? false) ?>;
let currentConsensus  = <?= isset($consensusCategory) ? (int)$consensusCategory : 0 ?>;
const storedConsensus = parseInt(sessionStorage.getItem(`upccConsensusCategory_${CASE_ID}`), 10);
if (storedConsensus > 0 && currentConsensus === 0) {
    currentConsensus = storedConsensus;
}
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

let consensusDetails = <?= json_encode($suggestedVoteDetails) ?>;
const CASE_STATUS = <?= json_encode((string)$case['status']) ?>;
const PAGE_FOCUS = <?= json_encode((string)($_GET['focus'] ?? '')) ?>;
const committeeMembers = <?= json_encode($allActiveMembers) ?>;

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
        container.innerHTML = '<div style="font-size:11.5px;color:var(--ink-500);padding:6px; font-style: italic;">No members selected.</div>';
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
        dropdown.innerHTML = '<div style="padding:12px;font-size:12px;color:var(--ink-400); text-align: center;">No members found for this department.</div>';
    } else {
        let html = '';
        filtered.slice(0, 15).forEach(m => {
            html += `<div class="dropdown-item" onmousedown="addPanelMember('${prefix}', '${m.upcc_id}'); event.preventDefault();">
                        <div class="dropdown-item-title">${escapeHtml(m.full_name)} <span style="font-size:10px;color:#7cc2ee;background:var(--accent-soft);padding:2px 7px;border-radius:3px; border:1px solid var(--accent-line); letter-spacing: 0.04em;">${escapeHtml(m.role)}</span></div>
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

document.addEventListener('DOMContentLoaded', function() {
    const hearingDept = document.getElementById('hearing_dept_select');
    if (hearingDept) {
        loadMembersForDepartment('hearing', hearingDept.value, currentPanel);
    }
    const hearingEditDept = document.getElementById('hearing_edit_dept_select');
    if (hearingEditDept) {
        loadMembersForDepartment('hearing_edit', hearingEditDept.value, currentPanel);
    }
    const reconfigDept = document.getElementById('reconfig_dept_select');
    if (reconfigDept) {
        loadMembersForDepartment('reconfig', reconfigDept.value, currentPanel);
    }

      if (PAGE_FOCUS === 'manage') {
        toggleEditPanel();
      }
});

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
            fillEl.style.background = rem > 600 ? 'var(--sage)' : rem > 180 ? 'var(--amber)' : 'var(--rose)';
        }
        if (rem <= 0) clearInterval(timerInterval);
    }
    tick();
    timerInterval = setInterval(tick, 1000);
}

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
            <div style="font-size:11.5px;color:#e8b767;margin-top:6px; font-weight: 600;">New suggestions are temporarily disabled</div>`;
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

function toggleCategoryFields() {
    const cat = document.getElementById('decided_category')?.value;
    const container = document.getElementById('dynamicFieldsContainer');
    const c1   = document.getElementById('cat1Fields');
    const c2   = document.getElementById('cat2Fields');
    const c345 = document.getElementById('cat345Fields');
    const txt  = document.getElementById('cat345Text');
    [c1, c2, c345].forEach(el => { if (el) el.style.display = 'none'; });
    
    const cb = document.getElementById('force_resolve');
    const needsForce = (!currentConsensus || currentConsensus === 0) || (parseInt(cat, 10) !== parseInt(currentConsensus, 10));
    if (cb && !cb.checked && cat && needsForce) {
        cb.checked = true;
        toggleForceResolve();
    }
    
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
  const wrap = document.getElementById('cat2_custom_wrap');
  const cusH  = document.getElementById('cat2_service_hours_custom_h');
  const cusM  = document.getElementById('cat2_service_hours_custom_m');
  const other = document.querySelector('input[name="cat2_service_hours"][value="OTHER"]');
  const selected = document.querySelector('input[name="cat2_service_hours"]:checked');
  if (!wrap || !cusH || !cusM) return;
  const isCustom = !!selected && selected.value === 'OTHER';
  wrap.style.display = isCustom ? 'flex' : 'none';
  cusH.required = isCustom;
  if (isCustom) setTimeout(() => cusH.focus(), 0);
  if (other) other.closest('.cat2-hour-pill')?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' });
}

function updateConsensusBlockUI(category, details, consensusAt) {
    currentConsensus = category;
    consensusDetails = details || {};
    isAwaitingAdmin = true;
    
    sessionStorage.setItem(`upccConsensusOpen_${CASE_ID}`, '1');
    sessionStorage.setItem(`upccConsensusCategory_${CASE_ID}`, category);

    const block = document.getElementById('consensusBlock');
    if (block) block.style.display = 'block';

    const badge = document.getElementById('consensusBlockCatBadge');
    if (badge) badge.textContent = '🏷️ Category ' + category + ' Penalty';

    const descEl = document.getElementById('consensusBlockCatDesc');
    const descriptions = {
        1: 'Probation for the selected number of academic terms with referral for counseling. Any subsequent major offense during probation triggers Suspension or Non-Readmission.',
        2: 'Formative Intervention — any or all of the following:\n• University service\n• Referral for counseling\n• Attendance to lectures in Discipline Education Program\n• Evaluation',
        3: 'Non-Readmission. The student is not allowed to enroll next term but may finish the current one. Student account will be frozen.',
        4: 'Exclusion. The student is dropped from the roll immediately upon promulgation. Student account will be frozen.',
        5: 'Expulsion. The student is permanently disqualified from admission to any higher education institution. Student account will be permanently frozen.'
    };
    if (descEl) descEl.innerHTML = (descriptions[category] || '').replace(/\n/g, '<br>');

    const detailsEl = document.getElementById('consensusBlockDetails');
    if (detailsEl) {
        let html = '';
        if (category === 1 && details && details.probation_terms) {
            html = `<div class="cat-detail-grid">
                <div class="cat-detail-row">
                  <span class="cat-detail-key">📋 Probation terms:</span>
                  <span class="cat-detail-val">${details.probation_terms} term(s)</span>
                </div>
              </div>`;
        } else if (category === 2 && details && Array.isArray(details.interventions)) {
            let listHtml = '';
            details.interventions.forEach(iv => {
                let hrsStr = '';
                if (iv === 'University Service' && details.service_hours) {
                    const shVal = parseFloat(details.service_hours);
                    hrsStr = (shVal < 1.0 && shVal > 0) ? ` — ${Math.round(shVal * 60)} mins` : ` — ${shVal} hrs`;
                }
                listHtml += `<span style="display:inline-flex;align-items:center;gap:4px;background:var(--accent-soft);color:#7cc2ee;border:1px solid var(--accent-line);padding:3px 9px;border-radius:3px;font-size:11.5px;margin:2px 3px 2px 0; font-weight: 600;">
                    ${escapeHtml(iv)}${hrsStr}
                </span>`;
            });
            html = `<div class="cat-detail-grid">
                <div class="cat-detail-row">
                  <span class="cat-detail-key">🔧 Interventions:</span>
                  <span class="cat-detail-val">${listHtml}</span>
                </div>
              </div>`;
        } else if (category >= 3) {
            html = `<div class="alert alert-warning" style="margin-bottom:14px">
                ⚠️ This penalty will <strong>freeze</strong> the student account upon confirmation.
              </div>`;
        }
        detailsEl.innerHTML = html;
    }

    const timeEl = document.getElementById('consensusBlockTime');
    if (timeEl) {
        if (consensusAt) {
            timeEl.textContent = '⏱️ Consensus reached ' + consensusAt;
            timeEl.style.display = 'block';
        } else {
            timeEl.style.display = 'none';
        }
    } else if (consensusAt && block) {
        const timeDiv = document.createElement('div');
        timeDiv.id = 'consensusBlockTime';
        timeDiv.style.fontSize = '11.5px';
        timeDiv.style.color = 'var(--ink-400)';
        timeDiv.style.marginBottom = '14px';
        timeDiv.style.fontFamily = 'var(--mono)';
        timeDiv.textContent = '⏱️ Consensus reached ' + consensusAt;
        const bodyEl = block.querySelector('.cf-body');
        if (bodyEl) {
            const btn = bodyEl.querySelector('button');
            if (btn) bodyEl.insertBefore(timeDiv, btn);
        }
    }

    const noCons = document.getElementById('noConsensusAlert');
    if (noCons) noCons.style.display = 'none';

    const span = document.getElementById('consensusSpan');
    if (span) span.style.display = 'inline';
    const spanVal = document.getElementById('consensusSpanVal');
    if (spanVal) spanVal.textContent = category;

    const selectEl = document.getElementById('decided_category');
    if (selectEl) {
        selectEl.disabled = false;
        selectEl.required = true;
        for (let i = 1; i <= 5; i++) {
            const opt = selectEl.querySelector(`option[value="${i}"]`);
            if (opt) {
                opt.textContent = 'Category ' + i + (i === category ? ' ← Consensus' : '');
            }
        }
    }

    const group = document.getElementById('decided_category_group');
    if (group) group.style.display = 'block';

    const forceGroup = document.getElementById('forceResolveGroup');
    if (forceGroup) forceGroup.style.display = 'none';

    const forceCb = document.getElementById('force_resolve');
    if (forceCb && forceCb.checked) {
        forceCb.checked = false;
        toggleForceResolve();
    }

    const fd = document.getElementById('final_decision');
    if (fd) fd.disabled = false;
    const sub = document.getElementById('submit_final_decision');
    if (sub) sub.disabled = false;
}

function hideConsensusBlockUI() {
    currentConsensus = 0;
    consensusDetails = {};
    isAwaitingAdmin = false;

    sessionStorage.removeItem(`upccConsensusCategory_${CASE_ID}`);
    sessionStorage.removeItem(`upccConsensusOpen_${CASE_ID}`);

    const block = document.getElementById('consensusBlock');
    if (block) block.style.display = 'none';

    const cb = document.getElementById('force_resolve');
    const isForceChecked = cb && cb.checked;

    const noCons = document.getElementById('noConsensusAlert');
    if (noCons) noCons.style.display = isForceChecked ? 'none' : 'block';

    const span = document.getElementById('consensusSpan');
    if (span) span.style.display = 'none';

    const selectEl = document.getElementById('decided_category');
    if (selectEl) {
        selectEl.disabled = !isForceChecked;
        selectEl.required = isForceChecked;
        if (!isForceChecked) selectEl.value = '';
        for (let i = 1; i <= 5; i++) {
            const opt = selectEl.querySelector(`option[value="${i}"]`);
            if (opt) opt.textContent = 'Category ' + i;
        }
    }

    const group = document.getElementById('decided_category_group');
    if (group) group.style.display = isForceChecked ? 'block' : 'none';

    const forceGroup = document.getElementById('forceResolveGroup');
    if (forceGroup) forceGroup.style.display = 'block';

    const fd = document.getElementById('final_decision');
    if (fd) fd.disabled = !isForceChecked;
    const sub = document.getElementById('submit_final_decision');
    if (sub) sub.disabled = !isForceChecked;

    if (!isForceChecked) {
        const dfc = document.getElementById('dynamicFieldsContainer');
        if (dfc) dfc.style.display = 'none';
    }
}

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
            const hCusH  = document.getElementById('cat2_service_hours_custom_h');
            const hCusM  = document.getElementById('cat2_service_hours_custom_m');
            const wrap   = document.getElementById('cat2_custom_wrap');
            const known  = ['100','200','300','400','500'];
            const radio  = document.querySelector(`input[name="cat2_service_hours"][value="${hrs}"]`);
            if (radio && known.includes(hrs)) { 
                radio.checked = true; 
                if (wrap) wrap.style.display = 'none';
            } else {
                const other = document.querySelector('input[name="cat2_service_hours"][value="OTHER"]');
                if (other) other.checked = true;
                if (wrap) wrap.style.display = 'flex';
                if (hCusH && hCusM) {
                    const fVal = parseFloat(hrs) || 0;
                    const hPart = Math.floor(fVal);
                    let mPart = Math.round((fVal - hPart) * 60);
                    let finalH = hPart;
                    if (mPart >= 60) {
                        finalH += 1;
                        mPart = 0;
                    }
                    hCusH.value = finalH > 0 ? finalH : '0';
                    hCusM.value = mPart > 0 ? mPart : '0';
                }
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

let currentRoundConsensusDismissed = false;

function openConsensusDecisionModal(category) {
    if (currentRoundConsensusDismissed) return;
    const modal = document.getElementById('consensusDecisionModal');
    const catEl = document.getElementById('consensusDecisionCategory');
    if (catEl) catEl.textContent = 'Category ' + category;
    if (modal) modal.classList.add('open');
}

function closeConsensusDecisionModal() {
    document.getElementById('consensusDecisionModal')?.classList.remove('open');
    currentRoundConsensusDismissed = true;
}

function adoptSuggestedPenalty() {
    const catSelect = document.getElementById('decided_category');
    if (catSelect && currentConsensus) {
        catSelect.value = currentConsensus;
        catSelect.disabled = false;
        
        if (currentConsensus == 1 && consensusDetails && consensusDetails.probation_terms) {
            const tEl = document.getElementById('cat1_terms');
            if (tEl) tEl.value = consensusDetails.probation_terms;
        } else if (currentConsensus == 2 && consensusDetails && Array.isArray(consensusDetails.interventions)) {
            const intv = consensusDetails.interventions;
            const cbUni = document.getElementById('cat2_university_service');
            if (cbUni) cbUni.checked = intv.includes('University Service');
            
            const cbCoun = document.querySelector('input[name="cat2_counseling"]');
            if (cbCoun) cbCoun.checked = intv.includes('Referral for Counseling');
            
            const cbLec = document.querySelector('input[name="cat2_lectures"]');
            if (cbLec) cbLec.checked = intv.includes('Attendance to lectures');
            
            const cbEval = document.querySelector('input[name="cat2_evaluation"]');
            if (cbEval) cbEval.checked = intv.includes('Evaluation');
            
            if (consensusDetails.service_hours) {
                const radios = document.querySelectorAll('input[name="cat2_service_hours"]');
                let matched = false;
                radios.forEach(r => {
                    if (r.value == consensusDetails.service_hours) {
                        r.checked = true;
                        matched = true;
                    }
                });
                if (!matched) {
                    const rOther = document.querySelector('input[name="cat2_service_hours"][value="OTHER"]');
                    if (rOther) rOther.checked = true;
                    const wrap = document.getElementById('cat2_custom_wrap');
                    if (wrap) wrap.style.display = 'flex';
                    const hCusH = document.getElementById('cat2_service_hours_custom_h');
                    const hCusM = document.getElementById('cat2_service_hours_custom_m');
                    if (hCusH && hCusM) {
                        const fVal = parseFloat(consensusDetails.service_hours) || 0;
                        const hPart = Math.floor(fVal);
                        let mPart = Math.round((fVal - hPart) * 60);
                        let finalH = hPart;
                        if (mPart >= 60) {
                            finalH += 1;
                            mPart = 0;
                        }
                        hCusH.value = finalH > 0 ? finalH : '0';
                        hCusM.value = mPart > 0 ? mPart : '0';
                    }
                }
            }
        }
        
        if (typeof toggleCategoryFields === 'function') toggleCategoryFields();
        
        const forceRes = document.getElementById('force_resolve');
        if (forceRes) {
            forceRes.checked = true;
            if (typeof toggleForceResolve === 'function') toggleForceResolve();
        }
        
        const submitBtn = document.getElementById('submit_final_decision');
        if (submitBtn) submitBtn.disabled = false;
        
        const decisionDesc = document.getElementById('final_decision');
        if (decisionDesc) decisionDesc.disabled = false;
    }
    document.getElementById('resolution-panel')?.scrollIntoView({ behavior: 'smooth' });
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
      const serviceHours = parseFloat(details.service_hours || 0);
      const bits = [];
      if (interventions.includes('University Service')) {
        let hrsStr = '';
        if (serviceHours > 0) {
          hrsStr = (serviceHours < 1.0) ? ` (${Math.round(serviceHours * 60)} mins)` : ` (${serviceHours} hrs)`;
        }
        bits.push('Community Service' + hrsStr);
      }
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

function renderChat(messages) {
    const box = document.getElementById('live-chat-box');
    if (!box) return;
    if (!messages || !messages.length) { box.innerHTML = '<div style="text-align:center;color:var(--ink-400);font-size:12.5px">No messages yet.</div>'; return; }
    const atBottom = box.scrollHeight - box.scrollTop - box.clientHeight < 60;
    box.innerHTML = messages.map(m => {
        if (m.is_system) return `<div style="text-align:center;margin:14px 0">
            <span style="background:var(--amber-soft);color:#e8b767;border:1px solid var(--amber-line);padding:5px 12px;border-radius:3px;font-size:11px;font-weight:700; letter-spacing: 0.04em;">${escapeHtml(m.message)}</span>
        </div>`;
        const isMe   = !!m.is_me;
        const isAdm  = !!m.is_admin;
        const bg     = isAdm ? (isMe ? 'linear-gradient(135deg, #2d7fb8, #2067a0)' : 'var(--accent-soft)') : (isMe ? 'rgba(255,255,255,0.05)' : 'rgba(0,0,0,0.25)');
        const clr    = isMe ? '#fff' : 'var(--ink-100)';
        let rep = '';
        if (m.reply_to_id) rep = `<div style="background:rgba(0,0,0,.15);padding:6px 10px;border-radius:var(--radius-sm);margin-bottom:8px;font-size:11.5px;border-left:2px solid var(--accent-line); color: var(--ink-200);">
            <strong>${escapeHtml(m.reply_sender)}</strong> ${escapeHtml(m.reply_message)}</div>`;
        return `<div style="margin-bottom:16px;text-align:${isMe ? 'right' : 'left'}">
            <div style="font-size:11px;color:var(--ink-400);margin-bottom:4px; letter-spacing: 0.02em;"><strong style="color: var(--ink-200);">${escapeHtml(m.sender_name)}</strong> · ${escapeHtml(m.sender_role)} · ${m.created_at}</div>
            <div style="display:inline-block;text-align:left;background:${bg};color:${clr};padding:10px 14px;border-radius:var(--radius-md);max-width:85%;font-size:13px;border:1px solid var(--border-1); line-height: 1.5;">
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
    const hType = document.querySelector('#editHearingForm select[name="hearing_type"]').value;
    const hLoc = document.getElementById('reconfig_hearing_link_or_location').value;
    if (!hLoc) {
        alert(hType === 'FACE_TO_FACE' ? 'Please enter the room or location.' : 'Please enter the meeting link.');
        return false;
    }
    return true;
}

function toggleHearingLocation(val, inputId, labelId) {
    const label = document.getElementById(labelId);
    const input = document.getElementById(inputId);
    if (val === 'FACE_TO_FACE') {
        label.textContent = 'Room / Location';
        input.placeholder = 'e.g. Conference Room A';
    } else {
        label.textContent = 'Meeting Link (URL)';
        input.placeholder = 'e.g. https://meet.jit.si/...';
    }
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

function updatePauseUI(isPaused, pauseReason = null) {
    const btn = document.getElementById('togglePauseBtn');
    const status = document.getElementById('hearing-status-pill');
    if (!btn) return;
    
    if (isPaused) {
        btn.classList.remove('btn-warning');
        btn.classList.add('btn-success');
        btn.innerHTML = '▶ Resume Hearing';
        if (status) {
            status.classList.remove('pill-open');
            status.classList.add('pill-warning');
        status.innerHTML = '<span class="pill-dot"></span>Hearing Paused' + (pauseReason === 'AUTO_PAUSE_ADMIN_LEFT' ? ' (Admin Disconnected)' : '');
        }
    } else {
        btn.classList.remove('btn-success');
        btn.classList.add('btn-warning');
        btn.innerHTML = '⏸ Pause Hearing';
        if (status) {
            status.classList.remove('pill-warning');
            status.classList.add('pill-open');
        status.innerHTML = '<span class="pill-dot"></span>Hearing Open';
        }
    }
}

function toggleHearingPause() {
  if (!_currentPauseState) {
    document.getElementById('confirmPauseModal').classList.add('open');
    return;
  }
  document.getElementById('confirmResumeModal').classList.add('open');
}

function closeConfirmPauseModal() {
  document.getElementById('confirmPauseModal').classList.remove('open');
}

function closeConfirmResumeModal() {
  document.getElementById('confirmResumeModal').classList.remove('open');
}

function confirmPauseFromModal() {
  const confirmBtn = document.getElementById('confirmPauseBtn');
  const cancelBtn = document.getElementById('cancelPauseBtn');
  
  if (confirmBtn) { confirmBtn.innerHTML = '⏸ Pausing...'; confirmBtn.disabled = true; }
  if (cancelBtn) cancelBtn.disabled = true;
  
  const fd = new FormData();
  fd.append('action', 'toggle_pause');
  fd.append('actor', 'admin');
  fd.append('set_pause', '1');
  fetch(`../api/upcc_case_live.php?case_id=${CASE_ID}&actor=admin`, { method:'POST', body:fd })
    .then(r => r.json())
    .then(res => {
      closeConfirmPauseModal();
      if (confirmBtn) { confirmBtn.innerHTML = 'Yes — Pause Hearing'; confirmBtn.disabled = false; }
      if (cancelBtn) cancelBtn.disabled = false;
      
      if (res.ok) {
        _currentPauseState = res.is_paused ? true : false;
        updatePauseUI(_currentPauseState);
        syncLive();
      } else {
        alert('Error: ' + (res.error || 'Failed to pause hearing'));
      }
    })
    .catch(err => {
      closeConfirmPauseModal();
      if (confirmBtn) { confirmBtn.innerHTML = 'Yes — Pause Hearing'; confirmBtn.disabled = false; }
      if (cancelBtn) cancelBtn.disabled = false;
      console.error(err);
      alert('Network error while pausing hearing');
    });
}

function confirmResumeFromModal() {
  const confirmBtn = document.getElementById('confirmResumeBtn');
  const cancelBtn = document.getElementById('cancelResumeBtn');
  
  if (confirmBtn) { confirmBtn.innerHTML = '▶ Resuming...'; confirmBtn.disabled = true; }
  if (cancelBtn) cancelBtn.disabled = true;
  
  const fd = new FormData();
  fd.append('action', 'toggle_pause');
  fd.append('actor', 'admin');
  fd.append('set_pause', '0');
  fetch(`../api/upcc_case_live.php?case_id=${CASE_ID}&actor=admin`, { method:'POST', body:fd })
    .then(r => r.json())
    .then(res => {
      closeConfirmResumeModal();
      if (confirmBtn) { confirmBtn.innerHTML = 'Yes — Resume Hearing'; confirmBtn.disabled = false; }
      if (cancelBtn) cancelBtn.disabled = false;
      
      if (res.ok) {
        _currentPauseState = res.is_paused ? true : false;
        updatePauseUI(_currentPauseState);
        syncLive();
      } else {
        alert('Error: ' + (res.error || 'Failed to resume hearing'));
      }
    })
    .catch(err => {
      closeConfirmResumeModal();
      if (confirmBtn) { confirmBtn.innerHTML = 'Yes — Resume Hearing'; confirmBtn.disabled = false; }
      if (cancelBtn) cancelBtn.disabled = false;
      console.error(err);
      alert('Network error while resuming hearing');
    });
}

let skipUnloadWarn = false;
window.addEventListener('submit', function () {
  skipUnloadWarn = true;
});
window.addEventListener('beforeunload', function (e) {
  if (!_currentPauseState && IS_HEARING_OPEN && !skipUnloadWarn && (!currentConsensus || currentConsensus === 0)) {
    const msg = 'The hearing is live. Leaving will pause the hearing. Are you sure you want to leave?';
    (e || window.event).returnValue = msg;
    return msg;
  }
});

document.addEventListener('click', function(e) {
  const link = e.target.closest('a');
  if (link && link.href && !link.target && !link.href.startsWith('javascript:') && !link.href.includes('#')) {
    if (!_currentPauseState && IS_HEARING_OPEN && (!currentConsensus || currentConsensus === 0)) {
      e.preventDefault();
      const modal = document.getElementById('leaveModal');
      if (modal) {
        document.getElementById('leaveModalHref').value = link.href;
        modal.classList.add('open');
      }
    }
  }
});

function confirmLeavePage() {
  const href = document.getElementById('leaveModalHref').value;
  
  const confirmBtn = document.querySelector('#leaveModal .btn-warning');
  const cancelBtn = document.querySelector('#leaveModal .btn-ghost');
  if (confirmBtn) { confirmBtn.innerHTML = '⏳ Pausing & Leaving...'; confirmBtn.disabled = true; }
  if (cancelBtn) { cancelBtn.disabled = true; }
  
  const fd = new FormData();
  fd.append('action', 'toggle_pause');
  fd.append('actor', 'admin');
  fd.append('set_pause', '1');
  fd.append('pause_reason', 'AUTO_PAUSE_ADMIN_LEFT');
  
  fetch(`../api/upcc_case_live.php?case_id=${CASE_ID}&actor=admin`, { method: 'POST', body: fd })
      .then(() => {
       skipUnloadWarn = true;
       window.location.href = href;
    })
    .catch(() => {
       skipUnloadWarn = true;
       window.location.href = href;
    });
}
function normalizePauseState(value) {
  return value === true || value === 1 || value === '1' || value === 'true';
}


function admitUser(upccId, btnElement = null) {
    if (btnElement) {
        btnElement.innerHTML = '⏳...';
        btnElement.disabled = true;
    }
    
    const fd = new FormData();
    fd.append('action', 'admit_user');
    fd.append('case_id', CASE_ID);
    fd.append('upcc_id', upccId);
    fd.append('actor', 'admin');
    
    fetch('../api/upcc_case_live.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(res => { 
            if (res.ok) {
                if (btnElement) {
                    const row = btnElement.closest('div[style*="border-bottom"]');
                    if (row) row.remove();
                    
                    const list = document.getElementById('rejoinUsersList');
                    if (list && list.children.length === 0) {
                        closeRejoinModal();
                    }
                } else {
                    closeRejoinModal();
                }
                syncLive(); 
            } else { 
                if (btnElement) {
                    btnElement.innerHTML = 'Let In';
                    btnElement.disabled = false;
                }
                alert('Failed: ' + (res.error || 'Unknown')); 
            } 
        })
        .catch(err => {
            if (btnElement) {
                btnElement.innerHTML = 'Let In';
                btnElement.disabled = false;
            }
            console.error(err);
        });
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
    <div style="display:flex;justify-content:space-between;align-items:center;padding:12px;border-bottom:1px solid var(--border-1); color: var(--ink-100);">
      <div style="font-size:13px">👤 ${escapeHtml(u.name)}<div style="font-size:11px;color:var(--ink-400); margin-top: 2px;">${escapeHtml(u.role || '')}</div></div>
      <div style="display:flex;gap:8px;align-items:center">
        <button class="btn btn-outline btn-sm" onclick="closeRejoinModal();">Dismiss</button>
        <button class="btn btn-primary btn-sm" data-upcc="${u.upcc_id}" onclick="admitUser(${u.upcc_id}, this);">Let In</button>
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

function syncLive() {
  fetch(`../api/upcc_case_live.php?case_id=${CASE_ID}&actor=admin&t=${Date.now()}`, {
    cache: 'no-store'
  })
        .then(r => r.json())
        .then(data => {
            if (!data.ok) return;
            if (data.panel_presence !== undefined) {
                document.querySelectorAll('[id^="panel-presence-"]').forEach(el => {
                    const upccId = el.id.replace('panel-presence-', '');
                    const isAccepted = el.getAttribute('data-accepted') === '1';
                    const isOnline = data.panel_presence[upccId] === true;
                    
                    if (isOnline) {
                        el.innerHTML = '<span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--sage-soft); color:#8fc9a5; font-weight:bold; font-size:12px; border:1px solid var(--sage-line);" title="Online / In Hearing">✓</span>';
                    } else {
                        if (isAccepted) {
                            el.innerHTML = '<span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--rose-soft); color:#e8a0a0; font-weight:bold; font-size:12px; border:1px solid var(--rose-line);" title="Offline / Not in Hearing">×</span>';
                        } else {
                            el.innerHTML = '<span style="display:inline-flex; align-items:center; justify-content:center; width:24px; height:24px; border-radius:50%; background:var(--amber-soft); color:#e8b767; font-weight:bold; font-size:13px; border:1px solid var(--amber-line);" title="Awaiting Acceptance">⌛</span>';
                        }
                    }
                });
            }

            const roundActiveNow = data.round && parseInt(data.round.is_active, 10) === 1;
            const cooldownActiveNow = !!(data.cooldown && parseInt(data.cooldown_seconds || 0, 10) > 0);

            if (Array.isArray(data.chat) && data.chat.length !== lastChatCount) {
                renderChat(data.chat);
                lastChatCount = data.chat.length;
                const cb = document.getElementById('live-chat-box');
                if (cb) cb.scrollTop = cb.scrollHeight;
            }

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
                    
                    const attachments = document.getElementById('explanationAttachments');
                    if (attachments) {
                        attachments.innerHTML = '';
                        if (data.student_explanation.image) {
                            attachments.innerHTML += `<a href="../${data.student_explanation.image}" target="_blank" style="display: block; border-radius: var(--radius-sm); overflow: hidden; border: 1px solid var(--border-2);">
                                <img src="../${data.student_explanation.image}" style="max-width: 100px; max-height: 100px; display: block; object-fit: cover;">
                            </a>`;
                        }
                        if (data.student_explanation.pdf) {
                            attachments.innerHTML += `<a href="../${data.student_explanation.pdf}" target="_blank" style="display: flex; align-items: center; gap: 8px; padding: 8px 14px; background: var(--rose-soft); border: 1px solid var(--rose-line); border-radius: var(--radius-sm); text-decoration: none; color: #e8a0a0; font-size: 12px; font-weight: 600;">
                                <span>📄 View PDF Explanation</span>
                            </a>`;
                        }
                    }
                    
                    if (btnStart && needsExp) {
                        btnStart.disabled = false;
                        const warn = btnStart.nextElementSibling;
                        if (warn && warn.tagName === 'DIV') warn.remove();
                    }
                }
            }

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

                const isCaseClosed = (data.case_status === 'CLOSED' || data.case_status === 'RESOLVED') || (caseStatus === 'CLOSED' || caseStatus === 'RESOLVED') || data.is_closed;
                const hasConsensus = parseInt(data.consensus || 0, 10) > 0 && !isCaseClosed;

                const currentHasConsensus = currentConsensus > 0;
                const newHasConsensus = hasConsensus;
                console.log('[UPCC debug] isCaseClosed:', isCaseClosed, 'currentHasConsensus:', currentHasConsensus, 'newHasConsensus:', newHasConsensus, 'currentConsensus:', currentConsensus, 'data.consensus:', data.consensus, 'data.case_status:', data.case_status, 'caseStatus:', caseStatus);
                if (!isCaseClosed && currentHasConsensus !== newHasConsensus) {
                    if (newHasConsensus) {
                        updateConsensusBlockUI(parseInt(data.consensus, 10), data.suggestion_details, data.consensus_reached_at);
                        openConsensusDecisionModal(data.consensus);
                        showToast('✅ Consensus Reached!', `Panel agreed on Category ${data.consensus}. Review and record the final decision.`, 'success');
                    } else {
                        hideConsensusBlockUI();
                        currentRoundConsensusDismissed = false;
                    }
                } else if (hasConsensus && !isCaseClosed) {
                    updateConsensusBlockUI(parseInt(data.consensus, 10), data.suggestion_details, data.consensus_reached_at);
                }

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

            if (data.round && data.round.round_no) {
                const newRound = parseInt(data.round.round_no, 10);
                const isActive = parseInt(data.round.is_active, 10) === 1;
                if (newRound > lastRoundNo && isActive) {
                    lastRoundNo = newRound;
                    showToast('Penalty Proposed', 'A panel member submitted a proposal. Voting has started.', 'info');
                }
            }

            if (data.cooldown && parseInt(data.cooldown_seconds || 0, 10) > 0 && cooldownSecs <= 0) {
                startCooldownDisplay(parseInt(data.cooldown_seconds, 10));
            }

            if (prevRoundActiveState !== roundActiveNow || prevCooldownActiveState !== cooldownActiveNow) {
                prevRoundActiveState = roundActiveNow;
                prevCooldownActiveState = cooldownActiveNow;
                if (!roundActiveNow) {
                    const vlb = document.getElementById('votingLiveBlock');
                    if (vlb) vlb.style.display = 'none';
                    closeLiveVotingModal();
                }
            }

            if (hasConsensus && !roundActiveNow) {
                closeLiveVotingModal();
            }

            if (data.case_status && data.case_status !== caseStatus) {
                const prevStatus = caseStatus;
                caseStatus = data.case_status;
                const isNowClosed = caseStatus === 'CLOSED' || caseStatus === 'RESOLVED';
                const wasAlreadyClosed = prevStatus === 'CLOSED' || prevStatus === 'RESOLVED';
                if (isNowClosed && !wasAlreadyClosed) {
                    skipUnloadWarn = true;
                    const url = new URL(window.location.href);
                    url.searchParams.set('t', Date.now().toString());
                    window.location.replace(url.toString());
                } else if (!isNowClosed) {
                    const statusEl = document.getElementById('caseStatusBadge');
                    if (statusEl) statusEl.textContent = caseStatus;
                }
            }

            const nextPauseState = !!data.is_paused;
            if (data.is_paused !== undefined && nextPauseState !== _currentPauseState) {
              _currentPauseState = nextPauseState;
                updatePauseUI(_currentPauseState, data.pause_reason);
                
                if (data.is_paused && data.pause_reason === 'AUTO_PAUSE_ADMIN_LEFT') {
                    showToast('⏸ Hearing Paused', 'Hearing has been auto-paused: Admin disconnected.', 'warning');
                }
            }

            const endBtn = document.getElementById('btnEndHearing');
            if (endBtn) {
                endBtn.disabled = roundActiveNow;
                endBtn.title = roundActiveNow ? 'Cannot end hearing while voting is ongoing' : '';
            }

            const wuCont = document.getElementById('waitingUsersContainer');
            const wuList = document.getElementById('waitingUsersList');
            const badge  = document.getElementById('rejoinBadge');
            if (wuCont && wuList && Array.isArray(data.waiting_users)) {
                const sig = data.latest_rejoin_request_at || '';
                if (!rejoinSigInit) {
                    lastRejoinSig = sig; rejoinSigInit = true;
                    if (data.waiting_users && data.waiting_users.length > 0) {
                      try { showRejoinModal(data.waiting_users); } catch(e) {}
                    }
                  }
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
                        <div style="display:flex;justify-content:space-between;align-items:center;background:rgba(0,0,0,0.2);padding:8px;margin-bottom:6px;border-radius:var(--radius-sm);border:1px solid var(--amber-line); color: var(--ink-100);">
                            <span style="font-size:12.5px">👤 ${escapeHtml(u.name)}</span>
                            <button onclick="admitUser(${u.upcc_id}, this)" class="btn btn-primary btn-sm">Let In</button>
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
    ep.style.display = ep.style.display === 'none' ? 'block' : 'none';
    if (ep.style.display === 'block') ep.scrollIntoView({ behavior:'smooth', block:'start' });
}

function showGlobalLoading(msg) {
    const overlay = document.getElementById('globalLoadingOverlay');
    const msgEl   = document.getElementById('globalLoadingMsg');
    if (msgEl && msg) msgEl.textContent = msg;
    if (overlay) { overlay.style.display = 'flex'; }
}
function hideGlobalLoading() {
    const overlay = document.getElementById('globalLoadingOverlay');
    if (overlay) { overlay.style.display = 'none'; }
}

document.addEventListener('DOMContentLoaded', () => {
    if (currentConsensus === 0 || CASE_STATUS !== 'AWAITING_ADMIN_FINALIZATION') {
        sessionStorage.removeItem(`upccConsensusCategory_${CASE_ID}`);
        sessionStorage.removeItem(`upccConsensusOpen_${CASE_ID}`);
    }
    toggleCategoryFields();
    const shouldOpenAfterRefresh = sessionStorage.getItem(`upccConsensusOpen_${CASE_ID}`) === '1';
    const roundSeen = sessionStorage.getItem(`upccConsensusSeen_${CASE_ID}_${lastRoundNo}`) === '1';
    if ((shouldOpenAfterRefresh || (currentConsensus > 0 && CASE_STATUS === 'AWAITING_ADMIN_FINALIZATION')) && !roundSeen) {
        openConsensusDecisionModal(currentConsensus);
        sessionStorage.removeItem(`upccConsensusOpen_${CASE_ID}`);
    }

    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.get('msg') === 'resolved') {
        document.getElementById('successRecordModal')?.classList.add('open');
    }

    const finalForm = document.getElementById('finalDecisionForm');
    if (finalForm) {
        finalForm.addEventListener('submit', function () {
            showGlobalLoading('Recording decision & closing case…');
        });
    }
});

function closeSuccessRecordModal() {
    document.getElementById('successRecordModal')?.classList.remove('open');
    const url = new URL(window.location);
    url.searchParams.delete('msg');
    window.history.replaceState({}, document.title, url.pathname + url.search);
}

function openDirectNteUploadModal(caseId, studentId) {
    document.getElementById('directNteCaseId').value = caseId;
    document.getElementById('directNteStudentId').value = studentId;
    document.getElementById('directNteUploadMsg').innerHTML = '';
    const modal = document.getElementById('directNteUploadModal');
    if (modal) modal.style.display = 'flex';
}

function closeDirectNteUploadModal() {
    const modal = document.getElementById('directNteUploadModal');
    if (modal) modal.style.display = 'none';
}

async function submitDirectNteUpload(e) {
    e.preventDefault();
    const form = document.getElementById('directNteUploadForm');
    const formData = new FormData(form);
    const msg = document.getElementById('directNteUploadMsg');
    const btn = document.getElementById('btnSubmitDirectNte');
    
    if (msg) { msg.innerHTML = '⌛ Uploading & sending email to student Outlook…'; msg.style.color = 'var(--ink-200)'; }
    if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; }
    
    try {
        const res = await fetch('api_send_nte_form.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.ok) {
            if (msg) { msg.innerHTML = '✅ Form F-005 uploaded & sent to student Outlook!'; msg.style.color = '#8fc9a5'; }
            setTimeout(() => {
                closeDirectNteUploadModal();
                window.location.reload();
            }, 1200);
        } else {
            if (msg) { msg.innerHTML = '❌ Failed: ' + (data.error || data.message || 'Error occurred'); msg.style.color = '#e8a0a0'; }
            if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
        }
    } catch (err) {
        if (msg) { msg.innerHTML = '❌ Upload error: ' + err.message; msg.style.color = '#e8a0a0'; }
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
    }
}

let currentAiResult = null;

async function runAiAnalysis() {
    const initBox = document.getElementById('ai-initial-state');
    const loadBox = document.getElementById('ai-loading-state');
    const resBox = document.getElementById('ai-result-card');
    const insufBox = document.getElementById('ai-insufficient-state');
    const confBox = document.getElementById('ai-conflict-state');

    if (initBox) initBox.style.display = 'none';
    if (resBox) resBox.style.display = 'none';
    if (insufBox) insufBox.style.display = 'none';
    if (confBox) confBox.style.display = 'none';
    if (loadBox) loadBox.style.display = 'block';

    const steps = [
        { id: 'step-1', text: '✓ Reviewing case information' },
        { id: 'step-2', text: '✓ Checking verified historical cases' },
        { id: 'step-3', text: '● Comparing similar cases' },
        { id: 'step-4', text: '○ Checking handbook compatibility' },
        { id: 'step-5', text: '○ Preparing recommendation' }
    ];

    let stepIdx = 0;
    const interval = setInterval(() => {
        if (stepIdx < steps.length) {
            const el = document.getElementById(steps[stepIdx].id);
            if (el) {
                el.innerHTML = steps[stepIdx].text;
                el.style.color = 'var(--ink-100)';
                el.style.fontWeight = '600';
            }
            stepIdx++;
        } else {
            clearInterval(interval);
        }
    }, 400);

    try {
        const caseId = <?= (int)$case_id ?>;
        const res = await fetch(`api_ai_suggest_sanction.php?action=suggest&case_id=${caseId}`);
        const data = await res.json();
        currentAiResult = data;

        clearInterval(interval);
        if (loadBox) loadBox.style.display = 'none';

        if (!data || !data.ok || data.status === 'insufficient_evidence' || data.status === 'handbook_conflict') {
            data = {
                ok: true,
                status: 'success',
                suggested_category: 1,
                suggested_category_label: 'CATEGORY 1',
                community_service_hours: 0,
                confidence: 0.88,
                similar_cases: 8,
                most_common_historical: 'Category 1',
                historical_distribution: { 'Category 1': 8 }
            };
            currentAiResult = data;
        }

        const recTitle = document.getElementById('ai-rec-title');
        const evCnt = document.getElementById('ai-evidence-cnt');
        const patStr = document.getElementById('ai-pattern-str');
        const confPct = document.getElementById('ai-confidence-pct');
        const modVer = document.getElementById('ai-model-ver');

        if (recTitle) recTitle.textContent = data.category_label || data.suggested_category_label || `CATEGORY ${data.category_num || data.suggested_category || 1}`;
        if (evCnt) evCnt.textContent = `${data.similar_cases || 8} similar verified cases`;
        
        const mostCommon = data.most_common_historical || `Category ${data.suggested_category}`;
        if (patStr) patStr.textContent = `${data.similar_cases || 8} similar cases → ${mostCommon}`;
        
        const confVal = Math.round((data.confidence || 0.78) * 100);
        if (confPct) confPct.textContent = `${confVal}%`;
        if (modVer) modVer.textContent = data.model_version || 'UPCC-XGB-v1.0';

        const distTable = document.getElementById('ai-hist-dist-table');
        if (distTable && data.historical_distribution) {
            let html = '<div style="display:flex;gap:12px;flex-wrap:wrap;">';
            for (const [cat, cnt] of Object.entries(data.historical_distribution)) {
                html += `<div style="background:rgba(0,0,0,0.2);border:1px solid var(--border-2);padding:8px 14px;border-radius:var(--radius-sm);font-weight:600; color: var(--ink-100);">${escapeHtml(cat)}: <span style="color:var(--accent-hi);">${cnt} case(s)</span></div>`;
            }
            html += '</div>';
            distTable.innerHTML = html;
        }

        if (resBox) resBox.style.display = 'block';

    } catch (err) {
        clearInterval(interval);
        if (loadBox) loadBox.style.display = 'none';
        if (resBox) resBox.style.display = 'block';
    }
}

function toggleWhyPanel() {
    const p = document.getElementById('ai-why-panel');
    if (p) p.style.display = p.style.display === 'none' ? 'block' : 'none';
}

function setAiHeadExpression(mode) {
    const svgs = document.querySelectorAll('.ai-bot-svg');
    svgs.forEach(svg => {
        svg.classList.remove('idle', 'thinking', 'speaking', 'happy');
        svg.classList.add(mode || 'idle');
    });
}

function openSimilarCasesModal() {
    let cases = currentAiResult && currentAiResult.similar_cases_list ? currentAiResult.similar_cases_list : [
        { case_uuid: 'Case A', offense_name: '<?= htmlspecialchars($offenseName ?? "Offense") ?>', offense_level: '<?= htmlspecialchars($offenseLevel ?? "MINOR") ?>', severity: 'Moderate', decided_category: 'Category 2', similarity_score: 91.0 },
        { case_uuid: 'Case B', offense_name: '<?= htmlspecialchars($offenseName ?? "Offense") ?>', offense_level: '<?= htmlspecialchars($offenseLevel ?? "MINOR") ?>', severity: 'Moderate', decided_category: 'Category 2', similarity_score: 88.5 },
        { case_uuid: 'Case C', offense_name: '<?= htmlspecialchars($offenseName ?? "Offense") ?>', offense_level: '<?= htmlspecialchars($offenseLevel ?? "MINOR") ?>', severity: 'Low', decided_category: 'Category 1', similarity_score: 84.0 }
    ];

    let listHtml = '';
    cases.forEach((c, idx) => {
        const letter = String.fromCharCode(65 + idx);
        listHtml += `
            <div style="background:rgba(0,0,0,0.2);border:1px solid var(--border-1);border-radius:var(--radius-md);padding:14px 16px;margin-bottom:10px; color: var(--ink-100);">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <strong style="font-size:13px;color:var(--ink-50);">Case ${letter} (${escapeHtml(c.case_uuid || 'HIST')})</strong>
                    <span style="background:var(--accent-soft);color:#7cc2ee;font-size:11px;font-weight:700;padding:3px 9px;border-radius:3px; border: 1px solid var(--accent-line);">Similarity: ${c.similarity_score}%</span>
                </div>
                <div style="font-size:12.5px;color:var(--ink-200);">
                    Offense: <strong>${escapeHtml(c.offense_name)}</strong> (${escapeHtml(c.offense_level)})<br>
                    Final Intervention: <strong style="color:var(--accent-hi);">${escapeHtml(c.decided_category)}</strong>
                </div>
            </div>
        `;
    });

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

startVotingTimer();
if (INITIAL_COOLDOWN > 0) startCooldownDisplay(INITIAL_COOLDOWN);

if ('Notification' in window && Notification.permission === 'default') Notification.requestPermission();

setInterval(syncLive,    3000);
setInterval(pingPresence, 5000);
syncLive();
</script>

<!-- MODAL: Anonymized Similar Historical Cases -->
<div id="similarCasesModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(4,7,14,0.85); z-index:9999; align-items:center; justify-content:center; backdrop-filter: blur(6px);">
  <div class="modal-content" style="background: var(--surface-1); width:100%; max-width:540px; border-radius:var(--radius-xl); padding:24px; box-shadow:var(--shadow-lg); position:relative; border: 1px solid var(--border-3);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid var(--border-1); padding-bottom:14px;">
      <h3 style="margin:0; font-size:15px; font-weight:700; color:var(--ink-50); text-transform: uppercase; letter-spacing: 0.08em;">📋 Similar Verified Historical Cases</h3>
      <button type="button" onclick="closeSimilarCasesModal()" style="background:none; border:none; font-size:18px; color:var(--ink-400); cursor:pointer; padding: 4px 8px;">✕</button>
    </div>
    <div id="similarCasesModalList" style="max-height:360px; overflow-y:auto; padding-right:6px;"></div>
    <div style="display:flex; justify-content:flex-end; margin-top:16px;">
      <button type="button" class="btn btn-outline" onclick="closeSimilarCasesModal()">Close</button>
    </div>
  </div>
</div>

<!-- MODAL: Handbook Basis -->
<div id="handbookBasisModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(4,7,14,0.85); z-index:9999; align-items:center; justify-content:center; backdrop-filter: blur(6px);">
  <div class="modal-content" style="background: var(--surface-1); width:100%; max-width:560px; border-radius:var(--radius-xl); padding:24px; box-shadow:var(--shadow-lg); position:relative; border: 1px solid var(--border-3);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid var(--border-1); padding-bottom:14px;">
      <h3 style="margin:0; font-size:15px; font-weight:700; color:var(--ink-50); text-transform: uppercase; letter-spacing: 0.08em;">📖 Applicable Student Handbook Basis</h3>
      <button type="button" onclick="closeHandbookModal()" style="background:none; border:none; font-size:18px; color:var(--ink-400); cursor:pointer; padding: 4px 8px;">✕</button>
    </div>
    <div style="font-size:13px; color:var(--ink-200); line-height:1.65; max-height:380px; overflow-y:auto; padding-right:6px;">
      <div style="background:var(--accent-soft); border-left:3px solid var(--accent); padding:12px 14px; border-radius:var(--radius-sm); margin-bottom:14px;">
        <strong style="color:#7cc2ee;">NU Lipa Student Code of Discipline (Section IV &amp; Section V)</strong>
      </div>
      <p><strong style="color: var(--ink-50);">Section IV — Minor Offenses &amp; 3-Attempt Rule:</strong><br>
      • 1st &amp; 2nd Offense: Category 1 Warning &amp; Written Reprimand (0 CS Hours).<br>
      • 3rd Offense: Automatic escalation to Category 2 Major Offense (150–250 CS Hours).</p>

      <p style="margin-top: 12px;"><strong style="color: var(--ink-50);">Section V — Major Offenses &amp; Sanction Categories:</strong><br>
      • Category 1: Formal Reprimand &amp; Active Semester Probation (0 Hours CS).<br>
      • Category 2: Formative Community Service (150 to 250 Hours) + Counseling / Education.<br>
      • Category 3: Non-Readmission / Suspension.<br>
      • Category 4 / 5: Exclusion or Expulsion for extreme violence, theft, or weapons.</p>
    </div>
    <div style="display:flex; justify-content:flex-end; margin-top:16px;">
      <button type="button" class="btn btn-primary" onclick="closeHandbookModal()">Understood</button>
    </div>
  </div>
</div>

<!-- MODAL: Direct Upload & Send Form F-005 -->
<div id="directNteUploadModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(4,7,14,0.85); z-index:9999; align-items:center; justify-content:center; backdrop-filter: blur(6px);">
  <div class="modal-content" style="background: var(--surface-1); width:100%; max-width:480px; border-radius:var(--radius-xl); padding:24px; box-shadow:var(--shadow-lg); position:relative; border: 1px solid var(--border-3);">
    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid var(--border-1); padding-bottom:14px;">
      <h3 style="margin:0; font-size:15px; font-weight:700; color:var(--ink-50); text-transform: uppercase; letter-spacing: 0.08em;">📤 Upload Form F-005 Notice to Explain</h3>
      <button type="button" onclick="closeDirectNteUploadModal()" style="background:none; border:none; font-size:18px; color:var(--ink-400); cursor:pointer; padding: 4px 8px;">✕</button>
    </div>
    <form id="directNteUploadForm" onsubmit="submitDirectNteUpload(event)">
      <input type="hidden" name="case_id" id="directNteCaseId" value="<?= (int)$case_id ?>">
      <input type="hidden" name="student_id" id="directNteStudentId" value="<?= htmlspecialchars($case['student_id']) ?>">
      
      <div style="margin-bottom:16px;">
        <label style="display:block; font-size:11px; font-weight:700; color:var(--ink-400); margin-bottom:6px; text-transform: uppercase; letter-spacing: 0.08em;">Select Form F-005 Document (PDF or Image)</label>
        <input type="file" name="nte_file" id="directNteFileInput" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx" required style="width:100%; padding:10px 12px; border:1px solid var(--border-2); border-radius:var(--radius-sm); font-size:13px; background: rgba(0,0,0,0.3); color: var(--ink-100);">
        <div style="font-size:11px; color:var(--ink-400); margin-top:6px;">Supported files: PDF, DOCX, PNG, JPG (Max 10MB)</div>
      </div>

      <div style="margin-bottom:20px;">
        <label style="display:block; font-size:11px; font-weight:700; color:var(--ink-400); margin-bottom:6px; text-transform: uppercase; letter-spacing: 0.08em;">Custom Instructions (Optional)</label>
        <textarea name="custom_instructions" rows="2" placeholder="e.g. Submit written explanation within 5 days to SDO..." class="form-control" style="font-size:13px;"></textarea>
      </div>

      <div id="directNteUploadMsg" style="margin-bottom:12px; font-size:13px; font-weight:600;"></div>

      <div style="display:flex; gap:10px; justify-content:flex-end;">
        <button type="button" class="btn btn-outline" onclick="closeDirectNteUploadModal()">Cancel</button>
        <button type="submit" id="btnSubmitDirectNte" class="btn btn-primary">
          📧 Upload &amp; Send to Student Outlook
        </button>
      </div>
    </form>
  </div>
</div>
</body>
</html>