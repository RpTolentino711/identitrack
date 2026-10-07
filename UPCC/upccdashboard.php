<?php
session_start();
require_once __DIR__ . '/../database/database.php';
ensure_hearing_workflow_schema();

if (!isset($_SESSION['upcc_authenticated']) || !upcc_current()) {
    header('Location: upccpanel.php');
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

$user    = upcc_current();
$panelId = (int)($user['upcc_id'] ?? 0);

$mustChange = db_one("SELECT must_change_password FROM upcc_user WHERE upcc_id = :id", [':id' => $panelId]);
if ((int)($mustChange['must_change_password'] ?? 0) === 1) {
    header('Location: upcc_change_password.php');
    exit;
}

$legacyPanelMatch = "FIND_IN_SET(:legacy_uid, REPLACE(REPLACE(REPLACE(COALESCE(uc.assigned_panel_members,''),'[',''),']',''),' ','')) > 0";
$panelAssignmentMatch = "EXISTS (SELECT 1 FROM upcc_case_panel_member ucpm WHERE ucpm.case_id = uc.case_id AND ucpm.upcc_id = :join_uid) OR $legacyPanelMatch";

// Helper function for case access check
function can_access_case($case) {
    if (in_array($case['status'], ['CLOSED', 'RESOLVED'])) return true;
    if (!empty($case['hearing_date']) && !empty($case['hearing_time'])) {
        if (isset($case['hearing_is_paused']) && (int)$case['hearing_is_paused'] === 1) {
            return false;
        }
        return ((int)($case['hearing_is_open'] ?? 0) === 1);
    }
    return true;
}

// Helper function to format case ID
function fmt_case_id(int $id, string $created): string {
    return 'UPCC-' . date('Y', strtotime($created)) . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

// ─── AJAX DISMISS: Manually hide resolved case ───
if (isset($_GET['action']) && $_GET['action'] === 'dismiss_case') {
    $cid = (int)($_GET['case_id'] ?? 0);
    if ($cid > 0) {
        if (!isset($_SESSION['dismissed_cases'])) $_SESSION['dismissed_cases'] = [];
        $_SESSION['dismissed_cases'][] = $cid;
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ─── AJAX REFRESH: Return updated case rows ──────
if (isset($_GET['action']) && $_GET['action'] === 'refresh_cases') {
    header('Content-Type: text/html; charset=utf-8');
    
    $refreshParams = [
        ':presence_uid' => $panelId,
        ':join_uid'     => $panelId,
        ':join_uid2'    => $panelId,
        ':legacy_uid'   => $panelId
    ];
    db_add_encryption_key($refreshParams);

    $recentCases = db_all("
         SELECT uc.case_id, uc.status, uc.created_at,
            uc.hearing_date, uc.hearing_time, uc.hearing_type, uc.hearing_is_open, uc.hearing_is_paused,
            COALESCE((SELECT p.status FROM upcc_hearing_presence p
                        WHERE p.case_id = uc.case_id AND p.user_type = 'UPCC' AND p.user_id = :presence_uid
                LIMIT 1), 'ADMITTED') AS my_presence_status,
            uc.hearing_vote_consensus_category,
            " . db_decrypt_cols(['student_fn', 'student_ln'], 's') . ",
            s.student_id,
            GROUP_CONCAT(ot.name ORDER BY ot.offense_type_id SEPARATOR ', ') AS offense_names,
            MAX(ot.level) AS offense_level,
            GROUP_CONCAT(DISTINCT CONCAT(ot.level, ':', ot.name) ORDER BY ot.level DESC SEPARATOR '||') AS offense_details,
            (SELECT COUNT(*) FROM upcc_case_vote v WHERE v.case_id = uc.case_id AND v.upcc_id = :join_uid2) AS user_has_voted,
            (SELECT round_no FROM upcc_case_vote_round WHERE case_id = uc.case_id AND is_active = 1 LIMIT 1) AS active_round,
            (SELECT TIMESTAMPDIFF(SECOND, last_ping, NOW()) FROM upcc_hearing_presence p WHERE p.case_id = uc.case_id AND p.user_type = 'ADMIN' LIMIT 1) AS admin_ping_diff
        FROM upcc_case uc
        JOIN upcc_case_panel_member ucpm ON ucpm.case_id = uc.case_id
        JOIN student s ON s.student_id = uc.student_id
        LEFT JOIN upcc_case_offense uco ON uco.case_id = uc.case_id
        LEFT JOIN offense o ON o.offense_id = uco.offense_id
        LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
        WHERE (ucpm.upcc_id = :join_uid OR $legacyPanelMatch)
          AND (uc.status NOT IN ('CLOSED', 'RESOLVED') OR uc.resolution_date >= DATE_SUB(NOW(), INTERVAL 10 MINUTE))
        GROUP BY uc.case_id
        ORDER BY uc.created_at DESC
        LIMIT 10
    ", $refreshParams);
    
    $acceptedRows  = db_all("SELECT case_id FROM upcc_case_panel_acceptance WHERE upcc_id = :uid", [':uid' => $panelId]);
    $acceptedCases = [];
    foreach ($acceptedRows as $r) {
        $acceptedCases[(int)$r['case_id']] = true;
    }
    
    // Output only the table rows
    foreach ($recentCases as $c): 
        if (in_array((int)$c['case_id'], $_SESSION['dismissed_cases'] ?? [])) continue;
        $cid = fmt_case_id((int)$c['case_id'], $c['created_at']);
        $href = 'case_view.php?id=' . (int)$c['case_id'];
        $myPresenceStatus = strtoupper((string)($c['my_presence_status'] ?? 'ADMITTED'));
        $accessGranted = can_access_case($c);
        $accepted = isset($acceptedCases[(int)$c['case_id']]);
        $isLocked = !$accessGranted;
        $lockedClass = $isLocked ? 'case-locked' : '';
        if (!$accepted) {
            $lockedClass .= ' case-needs-action';
        }
        $isResolved = in_array($c['status'], ['CLOSED', 'RESOLVED']);
        if ($isResolved) {
            $lockedClass .= ' case-resolved-row';
        }
        
        $adminOffline = false;

        if ($c['hearing_is_open'] == 1) {
            if ((int)($c['hearing_is_paused'] ?? 0) === 1) {
                $stClass = 'badge-muted';
                $stLabel = 'Hearing Paused';
            } else {
                $stClass = 'badge-success';
                $stLabel = 'Hearing Live';
            }
        } else {
            $stClass = match($c['status']) {
                'PENDING', 'UNDER_INVESTIGATION' => 'badge-pending',
                'RESOLVED', 'CLOSED'             => 'badge-resolved',
                'UNDER_APPEAL'                   => 'badge-appeal',
                default                          => 'badge-pending',
            };
            $stLabel = match($c['status']) {
                'UNDER_INVESTIGATION' => 'Investigating',
                'UNDER_APPEAL'        => 'Appeal',
                default               => ucfirst(strtolower($c['status'])),
            };
        }
        
        $hearingDate = !empty($c['hearing_date']) ? date('M j, Y', strtotime($c['hearing_date'])) : 'Not scheduled';
        $isNearHearing = (!empty($c['hearing_date']) && $c['hearing_date'] === date('Y-m-d')) ? 1 : 0;
        $adminOff = 0;
    ?>
    <tr class="<?php echo $lockedClass; ?>" data-near-hearing="<?php echo $isNearHearing; ?>" data-is-open="<?php echo (int)($c['hearing_is_open'] ?? 0); ?>" data-is-paused="<?php echo (int)($c['hearing_is_paused'] ?? 0); ?>" data-admin-offline="0" onclick="handleRowClick('<?php echo htmlspecialchars($href); ?>', <?php echo $accepted ? 'true' : 'false'; ?>, <?php echo (int)$c['case_id']; ?>, <?php echo (int)($c['hearing_is_open'] ?? 0); ?>, <?php echo (int)($c['hearing_is_paused'] ?? 0); ?>, '<?php echo htmlspecialchars($myPresenceStatus); ?>', 0, <?php echo $isResolved ? 'true' : 'false'; ?>)">
      <td><span class="t-id"><?php echo htmlspecialchars($cid); ?></span></td>
      <td>
        <?php if ($isResolved): ?>
          <span class="t-name" style="opacity:0.4; font-style:italic;">[ Respondent Confidential ]</span>
        <?php else: 
          $sName = trim(($c['student_fn'] ?? '') . ' ' . ($c['student_ln'] ?? ''));
          if ($sName === '') $sName = (string)($c['student_id'] ?? 'N/A');
          ?>
          <span class="t-name"><?php echo htmlspecialchars($sName); ?></span>
          <span class="t-sub">ID: <?php echo htmlspecialchars($c['student_id']); ?></span>
        <?php endif; ?>
      </td>
      <td>
        <?php if (!$accepted): ?>
          <span class="confidential-blur-pill"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Confidential Data Locked</span>
        <?php else: ?>
          <?php 
          $offenseDetails = [];
          if (!empty($c['offense_details'])) {
            $detailPairs = explode('||', $c['offense_details']);
            foreach ($detailPairs as $pair) {
              if (strpos($pair, ':') !== false) {
                list($level, $name) = explode(':', $pair, 2);
                $offenseDetails[] = ['level' => (int)$level, 'name' => trim($name)];
              }
            }
          }
          
          $maxLevel = (int)($c['offense_level'] ?? 1);
          $majorOffenses = array_filter($offenseDetails, fn($o) => $o['level'] >= 4);
          $minorOffenses = array_filter($offenseDetails, fn($o) => $o['level'] < 4);
          $isSection4 = (string)($c['case_kind'] ?? '') === 'SECTION4_MINOR_ESCALATION' || stripos((string)($c['case_summary'] ?? ''), 'Section 4') !== false || count($minorOffenses) >= 3;
          $hasMajor = !empty($majorOffenses) || $isSection4;
          if ($isSection4 && $maxLevel < 4) {
              $maxLevel = 4;
          }
          $badgeClass = $hasMajor ? 'major' : 'minor';
          ?>
          
          <div class="offense-badge <?php echo $badgeClass; ?>">
            <?php if ($hasMajor): ?>
              <span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0zM12 9v4M12 17h.01"/></svg> SECTION <?php echo $maxLevel; ?></span>
            <?php else: ?>
              <span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> MINOR</span>
            <?php endif; ?>
            
            <div class="offense-tooltip">
              <div class="offense-tooltip-title">
                <?php echo $hasMajor ? 'SECTION '.($maxLevel).' OFFENSES' : 'MINOR OFFENSES'; ?>
              </div>
              <?php foreach ($offenseDetails as $off): ?>
                <div class="offense-item">
                  <span class="offense-level-badge offense-level-<?php echo $off['level']; ?>">
                    <?php if ($off['level'] >= 4): ?>
                      S<?php echo $off['level']; ?>
                    <?php else: ?>
                      L<?php echo $off['level']; ?>
                    <?php endif; ?>
                  </span>
                  <span class="offense-name"><?php echo htmlspecialchars($off['name']); ?></span>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>
      </td>
      <td>
        <?php if ($c['hearing_is_open'] == 1 || !empty($c['hearing_date'])): ?>
          <span class="t-name" style="font-size:12px; display:block; margin-bottom:4px;"><?php echo $hearingDate; ?></span>
          <?php if ($c['hearing_is_open'] == 1): ?>
            <?php if ((int)($c['hearing_is_paused'] ?? 0) === 1): ?>
              <span class="badge badge-muted" style="font-size:10px;"><span class="dot dot-paused"></span> Paused</span>
            <?php else: ?>
              <span class="badge badge-success" style="font-size:10px;"><span class="dot dot-live"></span> Live Hearing</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="badge badge-muted" style="font-size:10px;">Pending Admin Open</span>
          <?php endif; ?>
          <?php if($c['hearing_type'] === 'ONLINE'): ?>
            <span class="badge-online" style="margin-left:4px;">Online</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="t-sub">—</span>
        <?php endif; ?>
      </td>
      <td style="padding: 12px 16px; min-width: 180px;">
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; width: 100%; min-width: 170px;">
          <div style="display: flex; align-items: center; gap: 8px;">
            <?php if ($accepted): ?>
              <?php if ($c['hearing_is_open'] == 1 && $accessGranted): ?>
                <button class="action-btn btn-join" style="pointer-events:auto;" onclick="event.stopPropagation(); window.location.href='<?php echo htmlspecialchars($href); ?>'">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21 5 3"/></svg> Enter Hearing
                </button>
              <?php elseif ($c['hearing_is_open'] == 1 && !$accessGranted && (int)($c['hearing_is_paused'] ?? 0) !== 1): ?>
                <div style="display:flex; gap:8px; align-items:center;">
                  <?php if ($myPresenceStatus === 'WAITING'): ?>
                    <span class="badge badge-warning" style="font-size:10px; padding:5px 12px;">Awaiting Admin Admission</span>
                  <?php else: ?>
                    <button onclick="event.stopPropagation(); triggerRejoin(<?php echo (int)$c['case_id']; ?>)" class="action-btn btn-rejoin" style="font-size:10px; cursor:pointer; pointer-events:auto; padding:6px 12px;">
                      <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="filter:drop-shadow(0 0 2px rgba(255,255,255,0.4));"><path d="M21.5 2v6h-6"/><path d="M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg> Request Rejoin
                    </button>
                  <?php endif; ?>
                </div>
              <?php else: ?>
                <div style="display:flex; gap:8px; align-items:center;">
                  <span class="badge <?php echo $stClass; ?>"><?php echo htmlspecialchars($stLabel); ?></span>
                </div>
              <?php endif; ?>
            <?php else: ?>
              <div class="need-action-box">
                <span class="need-action-label">Assignment Acknowledgement Required</span>
                <div style="display:flex; gap:8px; margin-top:4px;">
                  <button class="action-btn btn-ack-yes" onclick="event.stopPropagation(); triggerAcknowledge(<?php echo (int)$c['case_id']; ?>);">Accept Role</button>
                  <button class="action-btn btn-ack-no" onclick="event.stopPropagation(); triggerDecline(<?php echo (int)$c['case_id']; ?>);">Decline</button>
                </div>
              </div>
            <?php endif; ?>
          </div>
          <?php if ($isResolved): ?>
            <button onclick="event.stopPropagation(); dismissResolvedCase(<?php echo (int)$c['case_id']; ?>)" title="Dismiss case" class="dismiss-resolved-btn">&times;</button>
          <?php endif; ?>
        </div>
      </td>
    </tr>
    <?php endforeach;
    exit;
}

try {
    $panelMapCount = (int)(db_one("SELECT COUNT(*) AS c FROM upcc_case_panel_member")['c'] ?? 0);
    if ($panelMapCount === 0) {
        $legacyCases = db_all("SELECT case_id, assigned_panel_members FROM upcc_case WHERE COALESCE(assigned_panel_members, '') <> ''");
        foreach ($legacyCases as $legacy) {
            $caseId  = (int)($legacy['case_id'] ?? 0);
            if ($caseId <= 0) continue;
            $decoded = json_decode((string)$legacy['assigned_panel_members'], true);
            if (!is_array($decoded)) continue;
            $seen = [];
            foreach ($decoded as $pid) {
                $pid = (int)$pid;
                if ($pid <= 0 || isset($seen[$pid])) continue;
                $seen[$pid] = true;
                db_exec(
                    "INSERT IGNORE INTO upcc_case_panel_member (case_id, upcc_id, assigned_at) VALUES (:case_id, :upcc_id, NOW())",
                    [':case_id' => $caseId, ':upcc_id' => $pid]
                );
            }
        }
    }

    db_exec("CREATE TABLE IF NOT EXISTS upcc_case_panel_acceptance (
        acceptance_id BIGINT NOT NULL AUTO_INCREMENT,
        case_id BIGINT NOT NULL,
        upcc_id INT NOT NULL,
        accepted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (acceptance_id),
        UNIQUE KEY uq_case_panel (case_id, upcc_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
} catch (Exception $e) {
    error_log('UPCC acceptance migration failed: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'accept_confidentiality') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    if ($caseId > 0 && $panelId > 0) {
        db_exec(
            "INSERT INTO upcc_case_panel_acceptance (case_id, upcc_id, accepted_at)
             VALUES (:case_id, :upcc_id, NOW())
             ON DUPLICATE KEY UPDATE accepted_at = VALUES(accepted_at)",
            [':case_id' => $caseId, ':upcc_id' => $panelId]
        );
        $caseRow = db_one("SELECT created_at FROM upcc_case WHERE case_id = :id", [':id' => $caseId]);
        if ($caseRow) {
            $caseLabel = 'UPCC-' . date('Y', strtotime($caseRow['created_at'])) . '-' . str_pad((string)$caseId, 4, '0', STR_PAD_LEFT);
            $adminIds = db_all("SELECT admin_id FROM admin_user WHERE is_active = 1");
            foreach ($adminIds as $adm) {
                db_exec(
                    "INSERT INTO notification (type, title, message, admin_id, student_id, related_table, related_id, created_at)
                     VALUES ('HEARING_ACCEPTED', 'Panelist Accepted', :msg, :adm, '', 'upcc_case', :cid, NOW())",
                    [
                        ':msg' => "Panelist {$user['full_name']} accepted assignment for {$caseLabel}.",
                        ':adm' => $adm['admin_id'],
                        ':cid' => $caseId
                    ]
                );
            }
            upcc_log_case_activity($caseId, 'UPCC', $panelId, 'ACCEPTED_ASSIGNMENT');
        }
    }
    header('Location: upccdashboard.php');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'decline_assignment') {
    $caseId = (int)($_POST['case_id'] ?? 0);
    $reason = trim($_POST['reason'] ?? 'No reason provided');
    if ($caseId > 0 && $panelId > 0) {
        db_exec("DELETE FROM upcc_case_panel_member WHERE case_id = :case_id AND upcc_id = :upcc_id", [
            ':case_id' => $caseId,
            ':upcc_id' => $panelId
        ]);
        $caseRow = db_one("SELECT assigned_panel_members, created_at FROM upcc_case WHERE case_id = :id", [':id' => $caseId]);
        if ($caseRow) {
            $panelArray = json_decode((string)$caseRow['assigned_panel_members'], true) ?: [];
            $panelArray = array_values(array_filter($panelArray, fn($id) => (int)$id !== $panelId));
            db_exec("UPDATE upcc_case SET assigned_panel_members = :panel WHERE case_id = :id", [
                ':panel' => json_encode($panelArray),
                ':id' => $caseId
            ]);
            $caseLabel = 'UPCC-' . date('Y', strtotime($caseRow['created_at'])) . '-' . str_pad((string)$caseId, 4, '0', STR_PAD_LEFT);
            $adminIds = db_all("SELECT admin_id FROM admin_user WHERE is_active = 1");
            foreach ($adminIds as $adm) {
                db_exec(
                    "INSERT INTO notification (type, title, message, admin_id, student_id, related_table, related_id, created_at)
                     VALUES ('HEARING_DECLINED', 'Panelist Declined', :msg, :adm, '', 'upcc_case', :cid, NOW())",
                    [
                        ':msg' => "Panelist {$user['full_name']} declined assignment for {$caseLabel}. Reason: {$reason}",
                        ':adm' => $adm['admin_id'],
                        ':cid' => $caseId
                    ]
                );
            }
            upcc_log_case_activity($caseId, 'UPCC', $panelId, 'DECLINED_ASSIGNMENT', ['reason' => $reason]);
        }
    }
    header('Location: upccdashboard.php?msg=declined');
    exit;
}

$panelParams  = [':join_uid' => $panelId, ':legacy_uid' => $panelId];
$totalCases   = (int)(db_one("SELECT COUNT(DISTINCT uc.case_id) AS c FROM upcc_case uc WHERE $panelAssignmentMatch", $panelParams)['c'] ?? 0);
$pendingCases = (int)(db_one("SELECT COUNT(DISTINCT uc.case_id) AS c FROM upcc_case uc WHERE ($panelAssignmentMatch) AND uc.status IN ('PENDING','UNDER_INVESTIGATION')", $panelParams)['c'] ?? 0);
$resolvedCases= (int)(db_one("SELECT COUNT(DISTINCT uc.case_id) AS c FROM upcc_case uc WHERE ($panelAssignmentMatch) AND uc.status IN ('CLOSED','RESOLVED')", $panelParams)['c'] ?? 0);
$appealCases  = (int)(db_one("SELECT COUNT(DISTINCT uc.case_id) AS c FROM upcc_case uc WHERE ($panelAssignmentMatch) AND uc.status = 'UNDER_APPEAL'", $panelParams)['c'] ?? 0);

$initialParams = [
    ':presence_uid' => $panelId,
    ':vote_uid'     => $panelId,
    ':join_uid'     => $panelId,
    ':legacy_uid'   => $panelId
];
db_add_encryption_key($initialParams);

$recentCases = db_all("SELECT uc.case_id, uc.status, uc.created_at,
       uc.hearing_date, uc.hearing_time, uc.hearing_type, uc.hearing_is_open, uc.hearing_is_paused,
       COALESCE((SELECT p.status FROM upcc_hearing_presence p
           WHERE p.case_id = uc.case_id AND p.user_type = 'UPCC' AND p.user_id = :presence_uid
           LIMIT 1), 'ADMITTED') AS my_presence_status,
       uc.hearing_vote_consensus_category, uc.case_kind, " . db_decrypt_col('case_summary', 'uc') . " AS case_summary,
       " . db_decrypt_cols(['student_fn', 'student_ln'], 's') . ",
       s.student_id,
       GROUP_CONCAT(ot.name ORDER BY ot.offense_type_id SEPARATOR ', ') AS offense_names,
       MAX(ot.level) AS offense_level,
       GROUP_CONCAT(CONCAT(ot.level, ':', ot.name) ORDER BY ot.level DESC SEPARATOR '||') AS offense_details,
       (SELECT COUNT(*) FROM upcc_case_vote v WHERE v.case_id = uc.case_id AND v.upcc_id = :vote_uid) AS user_has_voted,
       (SELECT round_no FROM upcc_case_vote_round WHERE case_id = uc.case_id AND is_active = 1 LIMIT 1) AS active_round,
       (SELECT TIMESTAMPDIFF(SECOND, last_ping, NOW()) FROM upcc_hearing_presence p WHERE p.case_id = uc.case_id AND p.user_type = 'ADMIN' LIMIT 1) AS admin_ping_diff
  FROM upcc_case uc
  JOIN upcc_case_panel_member ucpm ON ucpm.case_id = uc.case_id
  JOIN student s ON s.student_id = uc.student_id
  LEFT JOIN upcc_case_offense uco ON uco.case_id = uc.case_id
  LEFT JOIN offense o ON o.offense_id = uco.offense_id
  LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
  WHERE (ucpm.upcc_id = :join_uid OR $legacyPanelMatch)
    AND (uc.status NOT IN ('CLOSED', 'RESOLVED') OR uc.resolution_date >= DATE_SUB(NOW(), INTERVAL 10 MINUTE))
  GROUP BY uc.case_id
  ORDER BY uc.created_at DESC
  LIMIT 10
", $initialParams);

$acceptedRows  = db_all("SELECT case_id FROM upcc_case_panel_acceptance WHERE upcc_id = :uid", [':uid' => $panelId]);
$acceptedCases = [];
foreach ($acceptedRows as $r) {
    $acceptedCases[(int)$r['case_id']] = true;
}

$privacyWatermark = sprintf('CONFIDENTIAL  ·  %s  ·  %s', $user['username'] ?? 'upcc', date('Y-m-d H:i:s'));

$nameParts = explode(' ', trim($user['full_name']));
$initials  = strtoupper(substr($nameParts[0], 0, 1));
if (count($nameParts) > 1) $initials .= strtoupper(substr(end($nameParts), 0, 1));

$greeting = date('H') < 12 ? 'Good morning' : (date('H') < 18 ? 'Good afternoon' : 'Good evening');
$firstName = htmlspecialchars($nameParts[0]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>UPCC Panel Workspace — Docket Queue</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Libre+Baskerville:ital,wght@0,400;0,700;1,400&family=Inter:wght@400;500;600;700;800&family=JetBrains+Mono:wght@500;600&display=swap" rel="stylesheet">
<style>
/* ══════════════════════════════════════════════════════════════════════════
   UPCC TRIBUNAL — Docket Queue
   Deep navy + antique gold. Formal institutional document feel.
   ══════════════════════════════════════════════════════════════════════════ */

:root{
    --ink-900:#060a14;
    --ink-800:#0a1220;
    --ink-700:#0e1a2d;
    --ink-600:#132340;

    --panel:#101c31;
    --panel-hi:#152540;

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

*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body{
    font-family:var(--f-sans);
    font-size:14px;
    color:var(--text);
    background:var(--ink-900);
    min-height:100vh;
    line-height:1.55;
    letter-spacing:0.005em;
    -webkit-font-smoothing:antialiased;
    background-image:
        radial-gradient(ellipse 80% 50% at 50% -20%, rgba(201,169,97,.06), transparent),
        radial-gradient(ellipse 60% 40% at 100% 100%, rgba(124,143,201,.04), transparent);
    background-attachment:fixed;
}

.app-container{
    display:grid;
    grid-template-columns:280px 1fr;
    min-height:100vh;
}

/* ── Sidebar ─────────────────────────────────────────────────────────── */
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
.brand{
    display:flex;align-items:center;gap:12px;
    padding-bottom:22px;margin-bottom:26px;
    border-bottom:1px solid var(--line);
}
.brand-icon{
    width:44px;height:44px;border:1px solid var(--line);
    background:rgba(201,169,97,.05);border-radius:4px;
    display:grid;place-items:center;padding:6px;flex-shrink:0;
}
.brand-icon img{width:100%;height:auto;display:block;border-radius:3px}
.brand-text h1{
    font-family:var(--f-serif);font-size:16px;font-weight:700;
    letter-spacing:.5px;color:var(--text-hi);line-height:1.1;
}
.brand-text p{
    font-size:10px;color:var(--gold);text-transform:uppercase;
    letter-spacing:2px;margin-top:4px;font-weight:600;
}

.side-section{margin-bottom:22px}
.side-label{
    font-size:10px;letter-spacing:2px;color:var(--text-mute);
    text-transform:uppercase;margin-bottom:12px;font-weight:700;
    padding-bottom:6px;border-bottom:1px solid var(--line-2);
}

.nav-link{
    display:flex;align-items:center;gap:11px;
    padding:11px 14px;margin-bottom:6px;
    border-radius:4px;color:var(--text-dim);
    text-decoration:none;font-weight:500;font-size:13px;
    background:transparent;border:1px solid transparent;
    transition:all .2s ease;letter-spacing:.2px;
}
.nav-link:hover{
    background:rgba(255,255,255,.03);
    color:var(--text-hi);
    border-color:var(--line-2);
}
.nav-link.active{
    background:var(--gold-faint);
    border:1px solid var(--line-hi);
    color:var(--gold-bright);
    font-weight:700;
}
.nav-link-danger:hover{
    background:var(--rose-soft);
    border-color:rgba(201,107,107,.4);
    color:#e0a0a0;
}
.nav-link svg{flex-shrink:0}

.side-info{
    margin-top:auto;
    border-top:1px solid var(--line);
    padding-top:18px;
    font-size:11.5px;
    color:var(--text-mute);
    line-height:1.6;
}
.side-info .side-info-row{display:flex;justify-content:space-between;margin-bottom:6px}
.side-info .side-info-label{color:var(--text-mute);font-size:10.5px;letter-spacing:.5px}
.side-info .side-info-value{color:var(--text-dim);font-weight:600;font-family:var(--f-mono);font-size:11px}

/* ── Main Content ────────────────────────────────────────────────────── */
.main-content{padding:32px 40px;overflow-y:auto}

/* ── Chamber Banner ──────────────────────────────────────────────────── */
.chamber-banner{
    display:flex;align-items:center;justify-content:space-between;
    background:linear-gradient(180deg,var(--ink-800),var(--ink-700));
    border:1px solid var(--line);
    border-left:3px solid var(--gold);
    padding:14px 22px;margin-bottom:26px;
    box-shadow:var(--shadow-md);
    gap:18px;flex-wrap:wrap;
}
.chamber-seal{display:flex;align-items:center;gap:14px}
.chamber-seal-mark{
    width:48px;height:48px;border:1.5px solid var(--gold);border-radius:50%;
    display:grid;place-items:center;background:rgba(201,169,97,.06);
    font-size:20px;flex-shrink:0;position:relative;
}
.chamber-seal-mark::before{
    content:'';position:absolute;inset:3px;border:1px solid rgba(201,169,97,.3);border-radius:50%;
}
.chamber-title{
    font-family:var(--f-serif);font-size:14px;font-weight:700;color:var(--text-hi);
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
    color:var(--text-dim);
}
.chamber-status .dot{
    width:7px;height:7px;border-radius:50%;display:inline-block;
    background:var(--sage);box-shadow:0 0 0 3px rgba(110,158,126,.2);
}

/* ── Greeting ────────────────────────────────────────────────────────── */
.header{
    display:flex;justify-content:space-between;align-items:flex-end;
    margin-bottom:26px;gap:20px;flex-wrap:wrap;
}
.greeting h2{
    font-family:var(--f-serif);font-size:26px;font-weight:700;
    color:var(--text-hi);letter-spacing:-.2px;line-height:1.15;
}
.greeting p{
    color:var(--text-mute);margin-top:6px;font-size:13px;
    letter-spacing:.3px;
}
.header-meta{
    font-size:11px;color:var(--text-mute);
    font-family:var(--f-mono);letter-spacing:.5px;
    border:1px solid var(--line);border-radius:3px;
    padding:7px 12px;background:rgba(0,0,0,.3);
}

/* ── Toolbar ─────────────────────────────────────────────────────────── */
.toolbar{
    display:flex;align-items:center;justify-content:space-between;
    gap:12px;margin-bottom:22px;flex-wrap:wrap;
}
.search-wrapper{position:relative;width:380px;max-width:100%}
.search-wrapper svg{
    position:absolute;left:13px;top:50%;transform:translateY(-50%);
    color:var(--text-mute);pointer-events:none;
}
.search-input{
    width:100%;padding:10px 14px 10px 40px;
    border-radius:3px;border:1px solid var(--line-2);
    background:rgba(0,0,0,.3);color:var(--text-hi);
    font-family:var(--f-sans);font-size:13px;outline:none;
    transition:border-color .15s ease,background .15s ease;
}
.search-input:focus{
    border-color:var(--gold);
    background:rgba(0,0,0,.5);
}
.search-input::placeholder{color:var(--text-mute)}

.filter-select{
    padding:10px 14px;border-radius:3px;border:1px solid var(--line-2);
    background:rgba(0,0,0,.3);color:var(--text);
    font-family:var(--f-sans);font-size:12.5px;outline:none;cursor:pointer;
    letter-spacing:.3px;
}
.filter-select:focus{border-color:var(--gold)}
.filter-select option{background:#0e1a2d;color:#f1ece0}

/* ── Notification ────────────────────────────────────────────────────── */
.notif-banner{
    background:var(--sage-soft);
    border:1px solid rgba(110,158,126,.35);
    border-left:3px solid var(--sage);
    color:#9dc5a8;padding:13px 18px;border-radius:3px;
    margin-bottom:22px;display:flex;align-items:center;gap:10px;
    font-size:13px;font-weight:500;letter-spacing:.2px;
}

/* ── Stats ───────────────────────────────────────────────────────────── */
.stats-grid{
    display:grid;grid-template-columns:repeat(3,1fr);
    gap:16px;margin-bottom:26px;
}
.stat-card{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:var(--radius-md);
    padding:20px 22px;
    position:relative;overflow:hidden;
    transition:border-color .2s;
}
.stat-card::before{
    content:'';position:absolute;top:0;left:0;right:0;height:2px;
    background:linear-gradient(90deg,var(--gold-faint),transparent);
}
.stat-card:hover{border-color:var(--line-hi)}
.stat-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:14px}
.stat-title{
    font-size:10.5px;color:var(--text-mute);
    text-transform:uppercase;letter-spacing:1.5px;font-weight:700;
}
.stat-icon-wrap{
    width:34px;height:34px;border-radius:3px;display:grid;place-items:center;
    background:rgba(255,255,255,.03);border:1px solid var(--line-2);
}
.stat-value{
    font-family:var(--f-serif);font-size:32px;font-weight:700;
    color:var(--text-hi);line-height:1;margin-bottom:6px;
}
.stat-desc{font-size:12px;color:var(--text-mute);letter-spacing:.2px}

.st-total .stat-icon-wrap{color:var(--gold-bright);background:var(--gold-faint);border-color:var(--line-hi)}
.st-active .stat-icon-wrap{color:#dfb87c;background:rgba(201,152,91,.1);border-color:rgba(201,152,91,.3)}
.st-resolved .stat-icon-wrap{color:#9dc5a8;background:var(--sage-soft);border-color:rgba(110,158,126,.3)}

/* ── Layout ──────────────────────────────────────────────────────────── */
.dashboard-layout{
    display:grid;grid-template-columns:1fr 320px;gap:22px;
}

/* ── Panels ──────────────────────────────────────────────────────────── */
.glass-panel{
    background:var(--panel);
    border:1px solid var(--line);
    border-radius:var(--radius-md);
    overflow:hidden;
    display:flex;flex-direction:column;
    box-shadow:var(--shadow-sm);
}
.panel-header{
    padding:18px 22px;border-bottom:1px solid var(--line);
    display:flex;align-items:center;justify-content:space-between;
    background:rgba(0,0,0,.2);position:relative;
}
.panel-header::before{
    content:'';position:absolute;left:22px;right:22px;bottom:-1px;height:1px;
    background:linear-gradient(90deg,var(--gold-faint),transparent);
}
.panel-title{
    display:flex;align-items:center;gap:10px;
    font-family:var(--f-serif);font-size:15px;font-weight:700;
    color:var(--text-hi);letter-spacing:.3px;
}
.panel-title svg{color:var(--gold)}

/* ── Table ───────────────────────────────────────────────────────────── */
.table-wrapper{overflow-x:auto;padding:0 22px 22px}
.table{width:100%;border-collapse:separate;border-spacing:0 6px}
.table th{
    text-align:left;padding:12px 16px;
    font-size:10.5px;text-transform:uppercase;letter-spacing:1.5px;
    color:var(--text-mute);font-weight:700;
    border-bottom:1px solid var(--line);
}
.table td{
    padding:16px;background:rgba(255,255,255,.015);
    font-size:13.5px;border-top:1px solid var(--line-2);
    border-bottom:1px solid var(--line-2);
    vertical-align:middle;
}
.table td:first-child{
    border-left:1px solid var(--line-2);
    border-radius:4px 0 0 4px;
}
.table td:last-child{
    border-right:1px solid var(--line-2);
    border-radius:0 4px 4px 0;
}
.table tr{transition:all .2s ease}

.table tr:not(.case-locked):not(.case-resolved-row):hover td{
    background:rgba(201,169,97,.04);
    border-color:rgba(201,169,97,.28);
    cursor:pointer;
}
.table tr.case-locked{opacity:.6}
.table tr.case-locked:hover td{
    cursor:not-allowed;
    background:rgba(255,255,255,.015);
}
.table tr.case-needs-action{position:relative}
.table tr.case-needs-action td{
    border-color:rgba(110,158,126,.35);
    background:rgba(110,158,126,.03);
}
.table tr.case-resolved-row td{background:rgba(0,0,0,.2)}

.t-id{
    font-family:var(--f-mono);font-weight:700;
    color:var(--gold-bright);font-size:12.5px;letter-spacing:.3px;
}
.t-name{
    font-family:var(--f-serif);font-weight:700;
    color:var(--text-hi);display:block;margin-bottom:2px;font-size:13.5px;
}
.t-sub{font-size:11.5px;color:var(--text-mute);letter-spacing:.2px}

/* ── Badges ──────────────────────────────────────────────────────────── */
.badge{
    display:inline-flex;align-items:center;gap:6px;padding:4px 10px;
    border-radius:3px;font-size:10px;font-weight:700;letter-spacing:1.2px;
    border:1px solid transparent;text-transform:uppercase;
}
.badge-pending{background:rgba(201,152,91,.12);color:#dfb87c;border-color:rgba(201,152,91,.35)}
.badge-resolved{background:var(--sage-soft);color:#9dc5a8;border-color:rgba(110,158,126,.35)}
.badge-appeal{background:var(--rose-soft);color:#e0a0a0;border-color:rgba(201,107,107,.35)}
.badge-success{background:var(--sage-soft);color:#9dc5a8;border-color:rgba(110,158,126,.4)}
.badge-muted{background:rgba(147,160,181,.1);color:#b0bac9;border-color:rgba(147,160,181,.3)}
.badge-warning{background:rgba(201,152,91,.12);color:#dfb87c;border-color:rgba(201,152,91,.35)}
.badge-online{
    background:rgba(124,143,201,.12);border:1px solid rgba(124,143,201,.3);
    color:#a5b6e0;padding:2px 7px;font-size:9.5px;border-radius:3px;
    letter-spacing:.8px;font-weight:700;text-transform:uppercase;
}

.dot{width:6px;height:6px;border-radius:50%;display:inline-block}
.dot-live{background:#6e9e7e;box-shadow:0 0 6px rgba(110,158,126,.7)}
.dot-paused{background:#93a0b5}

.confidential-blur-pill{
    display:inline-flex;align-items:center;gap:6px;
    font-size:11px;color:var(--text-mute);font-style:italic;
    background:rgba(255,255,255,.03);padding:5px 10px;border-radius:3px;
    border:1px solid var(--line-2);letter-spacing:.2px;
}

/* ── Action buttons ──────────────────────────────────────────────────── */
.action-btn{
    background:linear-gradient(180deg,var(--gold),var(--gold-soft));
    color:#0a1220;border:none;padding:7px 14px;border-radius:3px;
    font-weight:700;font-size:11px;cursor:pointer;
    transition:all .18s ease;text-decoration:none;
    display:inline-flex;align-items:center;gap:6px;
    letter-spacing:.8px;text-transform:uppercase;
    font-family:var(--f-sans);
    border:1px solid var(--gold-soft);
    box-shadow:0 1px 0 rgba(255,255,255,.15) inset;
}
.action-btn:hover{
    background:linear-gradient(180deg,var(--gold-bright),var(--gold));
    box-shadow:0 2px 10px rgba(201,169,97,.4);
}

.btn-join{background:linear-gradient(180deg,#5c8a6c,#4c7760);color:#fff;border-color:#4c7760}
.btn-join:hover{background:linear-gradient(180deg,#6ba075,#5c8a6c)}

.btn-rejoin{
    background:linear-gradient(135deg, #f59e0b 0%, #d97706 100%) !important;
    color:#ffffff !important;
    border:1px solid #fcd34d !important;
    border-radius:4px;
    font-weight:800 !important;
    letter-spacing:0.8px;
    text-transform:uppercase;
    cursor:pointer;
    box-shadow:0 0 14px rgba(245, 158, 11, 0.55), 0 2px 4px rgba(0,0,0,0.3), inset 0 1px 1px rgba(255,255,255,0.4) !important;
    text-shadow:0 1px 2px rgba(0,0,0,0.5);
    animation:rejoin-glow-pulse 2s infinite ease-in-out;
    display:inline-flex;
    align-items:center;
    gap:6px;
    transition:all .2s ease;
}
.btn-rejoin:hover{
    background:linear-gradient(135deg, #fbbf24 0%, #f59e0b 100%) !important;
    border-color:#fef08a !important;
    box-shadow:0 0 22px rgba(251, 191, 36, 0.85), 0 0 8px rgba(245, 158, 11, 0.7), inset 0 1px 2px rgba(255,255,255,0.6) !important;
    transform:translateY(-1px) scale(1.02);
    color:#ffffff !important;
}
.btn-rejoin:active{
    transform:translateY(0) scale(0.99);
}
@keyframes rejoin-glow-pulse{
    0%, 100%{
        box-shadow:0 0 12px rgba(245, 158, 11, 0.45), 0 2px 4px rgba(0,0,0,0.3), inset 0 1px 1px rgba(255,255,255,0.35);
    }
    50%{
        box-shadow:0 0 22px rgba(245, 158, 11, 0.8), 0 0 8px rgba(251, 191, 36, 0.65), 0 2px 4px rgba(0,0,0,0.3), inset 0 1px 1px rgba(255,255,255,0.45);
    }
}

.btn-ack-yes{
    background:var(--sage-soft);color:#9dc5a8;
    border:1px solid rgba(110,158,126,.4);
    font-size:10.5px;padding:5px 10px;letter-spacing:.8px;
}
.btn-ack-yes:hover{background:rgba(110,158,126,.25)}

.btn-ack-no{
    background:var(--rose-soft);color:#e0a0a0;
    border:1px solid rgba(201,107,107,.4);
    font-size:10.5px;padding:5px 10px;letter-spacing:.8px;
}
.btn-ack-no:hover{background:rgba(201,107,107,.25)}

.need-action-box{display:flex;flex-direction:column;gap:5px}
.need-action-label{
    font-size:9.5px;color:#dfb87c;font-weight:700;
    text-transform:uppercase;letter-spacing:1.2px;
}

.dismiss-resolved-btn{
    background:var(--rose-soft);border:1px solid rgba(201,107,107,.4);
    color:#e0a0a0;width:26px;height:26px;border-radius:3px;
    display:inline-flex;align-items:center;justify-content:center;
    font-size:15px;cursor:pointer;transition:all .2s;flex-shrink:0;
    line-height:1;font-family:var(--f-sans);
}
.dismiss-resolved-btn:hover{background:rgba(201,107,107,.3);color:#fff}

/* ── Offense badge ───────────────────────────────────────────────────── */
.offense-badge{
    display:inline-flex;align-items:center;gap:6px;
    padding:5px 10px;border-radius:3px;
    font-weight:700;font-size:10px;letter-spacing:1.2px;
    position:relative;cursor:help;transition:all .2s;white-space:nowrap;
    text-transform:uppercase;
}
.offense-badge.major{
    background:var(--rose-soft);border:1px solid rgba(201,107,107,.4);color:#e0a0a0;
}
.offense-badge.minor{
    background:rgba(124,143,201,.12);border:1px solid rgba(124,143,201,.35);color:#a5b6e0;
}
.offense-badge:hover{transform:translateY(-1px)}

.offense-tooltip{
    position:absolute;top:100%;left:50%;
    transform:translateX(-50%) translateY(8px);
    background:var(--panel-hi);
    border-radius:4px;padding:14px;min-width:280px;
    border:1px solid var(--line-hi);
    box-shadow:var(--shadow-lg);
    opacity:0;visibility:hidden;z-index:99;
    transition:all .2s ease;pointer-events:none;
    text-transform:none;letter-spacing:0;
}
.offense-badge:hover .offense-tooltip{
    opacity:1;visibility:visible;pointer-events:auto;
    transform:translateX(-50%) translateY(4px);
}
.offense-tooltip-title{
    font-size:10px;color:var(--gold);text-transform:uppercase;
    letter-spacing:1.5px;font-weight:700;margin-bottom:10px;
    border-bottom:1px solid var(--line);padding-bottom:8px;
}
.offense-item{
    display:flex;align-items:center;gap:8px;
    padding:6px 0;font-size:12px;
}
.offense-level-badge{
    padding:2px 6px;border-radius:3px;font-weight:700;font-size:9.5px;
    min-width:26px;text-align:center;letter-spacing:.5px;
    font-family:var(--f-mono);
}
.offense-level-4{background:var(--rose-soft);color:#e0a0a0}
.offense-level-3{background:rgba(201,152,91,.2);color:#dfb87c}
.offense-level-2{background:rgba(124,143,201,.2);color:#a5b6e0}
.offense-level-1{background:var(--sage-soft);color:#9dc5a8}
.offense-name{color:var(--text);flex:1}

/* ── Profile ─────────────────────────────────────────────────────────── */
.profile-wrap{padding:24px;text-align:center}
.avatar{
    width:80px;height:80px;
    background:linear-gradient(180deg,var(--ink-700),var(--ink-800));
    border:2px solid var(--line-hi);border-radius:50%;
    margin:0 auto 16px;display:grid;place-items:center;
    font-size:24px;font-weight:700;font-family:var(--f-serif);
    color:var(--gold-bright);background-size:cover;background-position:center;
    letter-spacing:.5px;
}
.profile-name{
    font-family:var(--f-serif);font-size:17px;font-weight:700;
    margin-bottom:4px;color:var(--text-hi);letter-spacing:.2px;
}
.profile-role{
    font-size:10.5px;color:var(--gold);font-weight:700;
    text-transform:uppercase;letter-spacing:1.8px;margin-bottom:22px;
}

.profile-meta{
    text-align:left;border-top:1px solid var(--line);
    padding-top:16px;
}
.meta-row{
    display:flex;justify-content:space-between;margin-bottom:10px;
    font-size:12px;gap:10px;
}
.meta-row .label{
    color:var(--text-mute);letter-spacing:.3px;
    text-transform:uppercase;font-size:10.5px;font-weight:600;
}
.meta-row .value{
    font-weight:600;color:var(--text);font-family:var(--f-mono);
    font-size:11.5px;text-align:right;
}
.meta-row .value.ok{color:#9dc5a8}

.quick-links{margin-top:16px;display:grid;gap:8px}
.q-link{
    display:flex;align-items:center;justify-content:space-between;
    padding:11px 15px;border-radius:3px;
    background:rgba(255,255,255,.02);border:1px solid var(--line-2);
    color:var(--text);text-decoration:none;font-size:12.5px;
    font-weight:500;transition:all .2s;letter-spacing:.2px;
}
.q-link:hover{
    background:rgba(255,255,255,.05);
    border-color:var(--line-hi);color:var(--text-hi);
}
.q-link.danger:hover{
    background:var(--rose-soft);
    border-color:rgba(201,107,107,.4);color:#e0a0a0;
}

/* ── Modals ──────────────────────────────────────────────────────────── */
.modal-overlay{
    position:fixed;inset:0;
    background:rgba(6,10,20,.94);
    backdrop-filter:blur(10px);
    z-index:1000;display:none;place-items:center;padding:20px;
}
.modal-overlay.show{display:grid}
.modal-content{
    background:var(--panel);
    border:1px solid var(--line-hi);
    border-radius:var(--radius-md);
    padding:32px;max-width:480px;text-align:center;
    box-shadow:var(--shadow-lg);position:relative;
    max-height:92vh;overflow-y:auto;
}
.modal-content::before{
    content:'';position:absolute;top:0;left:24px;right:24px;height:3px;
    background:linear-gradient(90deg,transparent,var(--gold),transparent);
}
.modal-title{
    font-family:var(--f-serif);font-size:19px;font-weight:700;
    margin-bottom:12px;color:var(--text-hi);letter-spacing:.3px;
    margin-top:8px;
}
.modal-desc{
    color:var(--text-dim);font-size:13px;margin-bottom:24px;
    line-height:1.6;
}
.modal-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}

/* ── Progress circle (rejoin) ────────────────────────────────────────── */
.rejoin-circle{
    width:70px;height:70px;border-radius:50%;
    background:var(--gold-faint);
    border:3px solid var(--line-hi);
    display:flex;align-items:center;justify-content:center;
    margin:0 auto 20px;position:relative;
}
.rejoin-circle span{
    font-size:1.3rem;font-weight:700;color:var(--gold-bright);
    font-family:var(--f-mono);font-variant-numeric:tabular-nums;
    letter-spacing:.5px;
}

/* ── Responsive ──────────────────────────────────────────────────────── */
@media (max-width: 1100px) {
    .app-container{display:flex;flex-direction:column}
    .sidebar{
        width:100%;padding:16px 22px;
        flex-direction:row;align-items:center;justify-content:space-between;
        border-right:none;border-bottom:1px solid var(--line);
        flex-wrap:wrap;gap:12px;
    }
    .sidebar::after{display:none}
    .brand{margin-bottom:0;padding-bottom:0;border-bottom:none}
    .side-section{margin-bottom:0;display:flex;align-items:center;gap:8px}
    .side-section .side-label{display:none}
    .side-info{display:none}
    .dashboard-layout{grid-template-columns:1fr}
    .stats-grid{grid-template-columns:1fr}
    .search-wrapper{width:100%;max-width:100%}
    .toolbar{flex-direction:column;align-items:stretch}
    .main-content{padding:22px 20px}
}
</style>
</head>
<body>

<div class="app-container">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="brand">
      <div class="brand-icon">
        <img src="../assets/logo.png" alt="IdentiTrack logo">
      </div>
      <div class="brand-text">
        <h1>IdentiTrack</h1>
        <p>UPCC Portal</p>
      </div>
    </div>

    <div class="side-section">
      <div class="side-label">Navigation</div>
      <nav>
        <a href="upccdashboard.php" class="nav-link active">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>
          Docket Queue
        </a>
        <a href="upcc_settings.php" class="nav-link">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
          Security Settings
        </a>
        <a href="javascript:void(0)" onclick="document.getElementById('logoutModal').classList.add('show');" class="nav-link nav-link-danger">
          <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
          Sign Out Session
        </a>
      </nav>
    </div>

    <div class="side-info">
      <div class="side-info-row">
        <span class="side-info-label">USER</span>
        <span class="side-info-value"><?php echo htmlspecialchars($user['username']); ?></span>
      </div>
      <div class="side-info-row">
        <span class="side-info-label">SESSION</span>
        <span class="side-info-value" style="color:#9dc5a8">ACTIVE</span>
      </div>
      <div class="side-info-row">
        <span class="side-info-label">CLEARANCE</span>
        <span class="side-info-value">PANEL</span>
      </div>
      <div class="side-info-row" style="margin-top:12px;padding-top:12px;border-top:1px solid var(--line-2)">
        <span class="side-info-label">TIME</span>
        <span class="side-info-value"><?php echo date('H:i:s'); ?></span>
      </div>
    </div>
  </aside>

  <!-- Main View -->
  <main class="main-content">

    <!-- Chamber banner -->
    <div class="chamber-banner">
      <div class="chamber-seal">
        <div class="chamber-seal-mark">⚖️</div>
        <div>
          <div class="chamber-title">National University · Discipline Board</div>
          <div class="chamber-sub">UPCC Panel Workspace · Confidential Proceedings</div>
        </div>
      </div>
      <div class="chamber-status">
        <span class="dot"></span> Secure Panel System
      </div>
    </div>

    <!-- Greeting -->
    <header class="header">
      <div class="greeting">
        <h2><?php echo $greeting; ?>, <?php echo $firstName; ?>.</h2>
        <p>University Disciplinary Board Case Workspace · Review assigned dockets below.</p>
      </div>
      <div class="header-meta">
        <?php echo date('Y-m-d H:i:s'); ?>
      </div>
    </header>

    <?php if (isset($_GET['hearing_msg'])): ?>
      <div class="notif-banner">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
        <?php echo htmlspecialchars((string)$_GET['hearing_msg']); ?>
      </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card st-total">
        <div class="stat-header">
          <span class="stat-title">Assigned Cases</span>
          <div class="stat-icon-wrap">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
          </div>
        </div>
        <div class="stat-value"><?php echo $totalCases; ?></div>
        <div class="stat-desc">Total panel assignments on record</div>
      </div>

      <div class="stat-card st-active">
        <div class="stat-header">
          <span class="stat-title">Active / Pending</span>
          <div class="stat-icon-wrap">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          </div>
        </div>
        <div class="stat-value"><?php echo $pendingCases; ?></div>
        <div class="stat-desc">Awaiting panel hearing &amp; consensus</div>
      </div>

      <div class="stat-card st-resolved">
        <div class="stat-header">
          <span class="stat-title">Resolved Cases</span>
          <div class="stat-icon-wrap">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
        </div>
        <div class="stat-value"><?php echo $resolvedCases; ?></div>
        <div class="stat-desc">Finalized by panel &amp; administration</div>
      </div>
    </div>

    <!-- Toolbar -->
    <div class="toolbar">
      <div class="search-wrapper">
        <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input id="caseSearch" class="search-input" type="search" placeholder="Search by Case ID or Respondent Name…">
      </div>
      <select id="caseFilter" class="filter-select">
        <option value="">All Hearing Statuses</option>
        <option value="Near Hearing">Scheduled Today</option>
        <option value="Hearing Live">Live Hearings</option>
        <option value="Paused">Paused Hearings</option>
        <option value="Locked">Locked Cases</option>
      </select>
    </div>

    <div class="dashboard-layout">
      <!-- Cases List -->
      <div class="glass-panel">
        <div class="panel-header">
          <div class="panel-title">
            <svg width="17" height="17" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            Assigned Case Queue
          </div>
        </div>

        <?php if (empty($recentCases)): ?>
          <div style="padding:60px 20px;text-align:center;color:var(--text-mute);font-style:italic;">
            <div style="font-family:var(--f-serif);font-size:15px;color:var(--text-dim);margin-bottom:6px">No assigned cases present in queue.</div>
            <div style="font-size:12.5px">New docket assignments will appear here.</div>
          </div>
        <?php else: ?>
          <div class="table-wrapper">
            <table class="table" style="margin-top:12px">
              <thead>
                <tr>
                  <th>Case ID</th>
                  <th>Respondent</th>
                  <th>Offenses</th>
                  <th>Hearing Schedule</th>
                  <th style="min-width:180px">Status / Action</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($recentCases as $c):
                  if (in_array((int)$c['case_id'], $_SESSION['dismissed_cases'] ?? [])) continue;
                  $cid = fmt_case_id((int)$c['case_id'], $c['created_at']);
                  $href = 'case_view.php?id=' . (int)$c['case_id'];
                  $myPresenceStatus = strtoupper((string)($c['my_presence_status'] ?? 'ADMITTED'));
                  $accessGranted = can_access_case($c) && $myPresenceStatus === 'ADMITTED';
                  $accepted = isset($acceptedCases[(int)$c['case_id']]);
                  $isLocked = !$accessGranted;
                  $lockedClass = $isLocked ? 'case-locked' : '';
                  if (!$accepted) {
                      $lockedClass .= ' case-needs-action';
                  }
                  $isResolved = in_array($c['status'], ['CLOSED', 'RESOLVED']);
                  if ($isResolved) {
                      $lockedClass .= ' case-resolved-row';
                  }

                  $adminOffline = false;

                  if ($c['hearing_is_open'] == 1) {
                    if ((int)($c['hearing_is_paused'] ?? 0) === 1) {
                        $stClass = 'badge-muted';
                        $stLabel = 'Hearing Paused';
                    } else {
                        $stClass = 'badge-success';
                        $stLabel = 'Hearing Live';
                    }
                  } else {
                    $stClass = match($c['status']) {
                      'PENDING', 'UNDER_INVESTIGATION' => 'badge-pending',
                      'RESOLVED', 'CLOSED'             => 'badge-resolved',
                      'UNDER_APPEAL'                   => 'badge-appeal',
                      default                          => 'badge-pending',
                    };
                    $stLabel = match($c['status']) {
                      'UNDER_INVESTIGATION' => 'Investigating',
                      'UNDER_APPEAL'        => 'Appeal',
                      default               => ucfirst(strtolower($c['status'])),
                    };
                  }

                  $hearingDate = !empty($c['hearing_date']) ? date('M j, Y', strtotime($c['hearing_date'])) : 'Not scheduled';
                  $offenseDetails = [];
                  if (!empty($c['offense_details'])) {
                    $detailPairs = explode('||', $c['offense_details']);
                    foreach ($detailPairs as $pair) {
                      if (strpos($pair, ':') !== false) {
                        list($level, $name) = explode(':', $pair, 2);
                        $offenseDetails[] = ['level' => (int)$level, 'name' => trim($name)];
                      }
                    }
                  }
                  $minorOffenses = array_filter($offenseDetails, fn($o) => $o['level'] < 4);
                  $isSection4 = (string)($c['case_kind'] ?? '') === 'SECTION4_MINOR_ESCALATION' || stripos((string)($c['case_summary'] ?? ''), 'Section 4') !== false || count($minorOffenses) >= 3;
                  $section4Class = $isSection4 ? ' section4-row' : '';
                  $isNearHearing = (!empty($c['hearing_date']) && $c['hearing_date'] === date('Y-m-d')) ? 1 : 0;
                  $adminOff = 0;
                ?>
                <tr class="<?php echo $lockedClass . $section4Class; ?>" data-near-hearing="<?php echo $isNearHearing; ?>" data-is-open="<?php echo (int)($c['hearing_is_open'] ?? 0); ?>" data-is-paused="<?php echo (int)($c['hearing_is_paused'] ?? 0); ?>" data-admin-offline="0" onclick="handleRowClick('<?php echo htmlspecialchars($href); ?>', <?php echo $accepted ? 'true' : 'false'; ?>, <?php echo (int)$c['case_id']; ?>, <?php echo (int)($c['hearing_is_open'] ?? 0); ?>, <?php echo (int)($c['hearing_is_paused'] ?? 0); ?>, '<?php echo htmlspecialchars($myPresenceStatus); ?>', 0, <?php echo $isResolved ? 'true' : 'false'; ?>)">
                  <td><span class="t-id"><?php echo htmlspecialchars($cid); ?></span></td>
                  <td>
                    <?php if ($isResolved): ?>
                    <span class="t-name" style="opacity:0.4; font-style:italic;">[ Respondent Confidential ]</span>
                  <?php else:
                                  $sName = trim(($c['student_fn'] ?? '') . ' ' . ($c['student_ln'] ?? ''));
                                  if ($sName === '') $sName = (string)($c['student_id'] ?? 'N/A');
                                  ?>
                                  <span class="t-name"><?php echo htmlspecialchars($sName); ?></span>
                    <span class="t-sub">ID: <?php echo htmlspecialchars($c['student_id']); ?></span>
                  <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!$accepted): ?>
                      <span class="confidential-blur-pill"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg> Confidential Data Locked</span>
                    <?php else: ?>
                      <?php
                      $offenseDetails = [];
                      if (!empty($c['offense_details'])) {
                        $detailPairs = explode('||', $c['offense_details']);
                        foreach ($detailPairs as $pair) {
                          if (strpos($pair, ':') !== false) {
                            list($level, $name) = explode(':', $pair, 2);
                            $offenseDetails[] = ['level' => (int)$level, 'name' => trim($name)];
                          }
                        }
                      }

                      $maxLevel = (int)($c['offense_level'] ?? 1);
                      $majorOffenses = array_filter($offenseDetails, fn($o) => $o['level'] >= 4);
                      $minorOffenses = array_filter($offenseDetails, fn($o) => $o['level'] < 4);

                      $isSection4 = (string)($c['case_kind'] ?? '') === 'SECTION4_MINOR_ESCALATION' || stripos((string)($c['case_summary'] ?? ''), 'Section 4') !== false || count($minorOffenses) >= 3;
                      $hasMajor = !empty($majorOffenses) || $isSection4;
                      if ($isSection4 && $maxLevel < 4) {
                          $maxLevel = 4;
                      }
                      $badgeClass = $hasMajor ? 'major' : 'minor';
                      ?>

                      <div class="offense-badge <?php echo $badgeClass; ?>">
                        <?php if ($hasMajor): ?>
                          <span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0zM12 9v4M12 17h.01"/></svg> SECTION <?php echo $maxLevel; ?></span>
                        <?php else: ?>
                          <span><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z"/><polyline points="14 2 14 8 20 8"/></svg> MINOR</span>
                        <?php endif; ?>

                        <div class="offense-tooltip">
                          <div class="offense-tooltip-title">
                            <?php echo $hasMajor ? 'SECTION '.($maxLevel).' OFFENSES' : 'MINOR OFFENSES'; ?>
                          </div>
                          <?php foreach ($offenseDetails as $off): ?>
                            <div class="offense-item">
                              <span class="offense-level-badge offense-level-<?php echo $off['level']; ?>">
                                <?php if ($off['level'] >= 4): ?>
                                  S<?php echo $off['level']; ?>
                                <?php else: ?>
                                  L<?php echo $off['level']; ?>
                                <?php endif; ?>
                              </span>
                              <span class="offense-name"><?php echo htmlspecialchars($off['name']); ?></span>
                            </div>
                          <?php endforeach; ?>
                        </div>
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($c['hearing_is_open'] == 1 || !empty($c['hearing_date'])): ?>
                      <span class="t-name" style="font-size:12px; display:block; margin-bottom:5px;font-family:var(--f-sans);font-weight:600;"><?php echo $hearingDate; ?></span>
                      <?php if ($c['hearing_is_open'] == 1): ?>
                        <?php if ((int)($c['hearing_is_paused'] ?? 0) === 1): ?>
                          <span class="badge badge-muted"><span class="dot dot-paused"></span> Paused</span>
                        <?php else: ?>
                          <span class="badge badge-success"><span class="dot dot-live"></span> Live Hearing</span>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="badge badge-muted">Pending Admin Open</span>
                      <?php endif; ?>
                      <?php if($c['hearing_type'] === 'ONLINE'): ?>
                        <span class="badge-online" style="margin-left:5px;">Online</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="t-sub">—</span>
                    <?php endif; ?>
                  </td>
                  <td style="padding: 14px 16px; min-width: 180px;">
                    <div style="display: flex; align-items: center; justify-content: space-between; gap: 16px; width: 100%; min-width: 170px;">
                      <div style="display: flex; align-items: center; gap: 8px;">
                        <?php if ($accepted): ?>
                          <?php if ($c['hearing_is_open'] == 1 && $accessGranted): ?>
                            <button class="action-btn btn-join" style="pointer-events:auto;" onclick="event.stopPropagation(); window.location.href='<?php echo htmlspecialchars($href); ?>'">
                              <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><polygon points="5 3 19 12 5 21 5 3"/></svg> Enter Hearing
                            </button>
                          <?php elseif ($c['hearing_is_open'] == 1 && !$accessGranted && (int)($c['hearing_is_paused'] ?? 0) !== 1): ?>
                            <div style="display:flex; gap:8px; align-items:center;">
                              <?php if ($myPresenceStatus === 'WAITING'): ?>
                                <span class="badge badge-warning" style="padding:6px 12px;">Awaiting Admission</span>
                              <?php else: ?>
                                <button onclick="event.stopPropagation(); triggerRejoin(<?php echo (int)$c['case_id']; ?>)" class="action-btn btn-rejoin" style="font-size:10px; cursor:pointer; pointer-events:auto; padding:6px 12px;">
                                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" style="filter:drop-shadow(0 0 2px rgba(255,255,255,0.4));"><path d="M21.5 2v6h-6"/><path d="M21.34 15.57a10 10 0 1 1-.57-8.38l5.67-5.67"/></svg> Request Rejoin
                                </button>
                              <?php endif; ?>
                            </div>
                          <?php else: ?>
                            <div style="display:flex; gap:8px; align-items:center;">
                              <span class="badge <?php echo $stClass; ?>"><?php echo htmlspecialchars($stLabel); ?></span>
                            </div>
                          <?php endif; ?>
                        <?php else: ?>
                          <div class="need-action-box">
                            <span class="need-action-label">Assignment Acknowledgement Required</span>
                            <div style="display:flex; gap:6px; margin-top:2px;">
                              <button class="action-btn btn-ack-yes" onclick="event.stopPropagation(); triggerAcknowledge(<?php echo (int)$c['case_id']; ?>);">Accept Role</button>
                              <button class="action-btn btn-ack-no" onclick="event.stopPropagation(); triggerDecline(<?php echo (int)$c['case_id']; ?>);">Decline</button>
                            </div>
                          </div>
                        <?php endif; ?>
                      </div>
                      <?php if ($isResolved): ?>
                        <button onclick="event.stopPropagation(); dismissResolvedCase(<?php echo (int)$c['case_id']; ?>)" title="Dismiss case" class="dismiss-resolved-btn">&times;</button>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- Right Column -->
      <div style="display: flex; flex-direction: column; gap: 20px;">
        <!-- Profile Card -->
        <div class="glass-panel profile-wrap">
          <?php if (!empty($user['photo_path'])): ?>
            <div class="avatar" style="background-image: url('<?php echo htmlspecialchars($user['photo_path']); ?>');"></div>
          <?php else: ?>
            <div class="avatar"><?php echo $initials; ?></div>
          <?php endif; ?>
          <div class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
          <div class="profile-role">UPCC Panel Member</div>

          <div class="profile-meta">
            <div class="meta-row"><span class="label">Username</span><span class="value"><?php echo htmlspecialchars($user['username']); ?></span></div>
            <div class="meta-row"><span class="label">Session</span><span class="value ok">Authenticated</span></div>
            <div class="meta-row"><span class="label">Clearance</span><span class="value">Institutional Panel</span></div>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<!-- Modal Acknowledge Step 1 -->
<div id="ackModal" class="modal-overlay">
  <div class="modal-content">
    <div class="modal-title">Confidentiality Agreement</div>
    <div class="modal-desc">
      You are about to access protected student disciplinary records. Panel members must adhere to strict institutional confidentiality protocols. Unauthorized sharing or exporting of case information is strictly prohibited.
    </div>
    <div class="modal-actions">
      <button type="button" class="action-btn" style="background:rgba(255,255,255,.04);border:1px solid var(--line-2);color:var(--text);" onclick="closeAckModal()">Cancel</button>
      <button type="button" class="action-btn btn-join" onclick="showAckStep2()">I Acknowledge &amp; Proceed</button>
    </div>
  </div>
</div>

<!-- Modal Acknowledge Step 2 -->
<div id="ackModalStep2" class="modal-overlay">
  <div class="modal-content" style="border-color: rgba(201,107,107,.5);">
    <div class="modal-title" style="color:#e0a0a0;">Final Legal Confirmation</div>
    <div class="modal-desc" style="background: var(--rose-soft); border-left: 3px solid var(--rose); padding: 14px; border-radius: 3px; text-align: left; font-size: 12.5px; color: #e0a0a0;">
      <strong style="color:#e0a0a0;font-family:var(--f-serif);">NOTICE:</strong> Accessing these records generates an audit log. Any breach of student confidentiality or institutional policy will result in immediate termination of panel privileges and formal disciplinary proceedings.
    </div>
    <form method="post" action="upccdashboard.php" style="margin-top:22px;">
      <input type="hidden" name="action" value="accept_confidentiality">
      <input type="hidden" name="case_id" id="ackCaseIdStep2" value="">
      <div class="modal-actions">
        <button type="button" class="action-btn" style="background:rgba(255,255,255,.04);border:1px solid var(--line-2);color:var(--text);" onclick="backToAckStep1()">← Back</button>
        <button type="submit" class="action-btn" style="background:linear-gradient(180deg,#a85858,#8d4747);color:#fff;border-color:#8d4747;">Confirm &amp; Accept Role</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Decline Assignment -->
<div id="declineModal" class="modal-overlay">
  <div class="modal-content">
    <div class="modal-title">Decline Case Assignment</div>
    <div class="modal-desc">
      Please provide a reason for declining this panel assignment (e.g., schedule conflict or conflict of interest). The administrator will be notified.
    </div>
    <form method="post" action="upccdashboard.php">
      <input type="hidden" name="action" value="decline_assignment">
      <input type="hidden" name="case_id" id="declineCaseId" value="">
      <div style="margin-bottom: 20px; text-align: left;">
        <label style="display:block; font-size:10.5px; color:var(--text-mute); margin-bottom:8px; font-weight:700; text-transform:uppercase; letter-spacing:1.5px;">Reason for Declining</label>
        <textarea name="reason" rows="3" style="width:100%; padding:11px 14px; border-radius:3px; border:1px solid var(--line-2); background:rgba(0,0,0,.35); color:var(--text-hi); font-family:var(--f-sans); font-size:13px; outline:none; resize:none;" placeholder="Specify reason..."></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="action-btn" style="background:rgba(255,255,255,.04);border:1px solid var(--line-2);color:var(--text);" onclick="closeDeclineModal()">Cancel</button>
        <button type="submit" class="action-btn" style="background:linear-gradient(180deg,#a85858,#8d4747);color:#fff;border-color:#8d4747;">Decline Assignment</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Rejoin Request -->
<div id="rejoinModal" class="modal-overlay">
  <div class="modal-content">
    <!-- Step 1 -->
    <div id="rejoinStep1">
      <div class="modal-title">Request Re-Admission</div>
      <div class="modal-desc">
        Your hearing session was interrupted or connection lost. Request administrator approval to re-enter the live panel hearing.
      </div>
      <input type="hidden" id="rejoinCaseId" value="">
      <div class="modal-actions">
        <button type="button" class="action-btn" style="background:rgba(255,255,255,.04);border:1px solid var(--line-2);color:var(--text);" onclick="closeRejoinModal()">Cancel</button>
        <button type="button" id="btnSendRejoin" class="action-btn btn-join" onclick="sendRejoinRequest()">Submit Rejoin Request</button>
      </div>
    </div>
    <!-- Step 2 -->
    <div id="rejoinStep2" style="display:none; text-align:center;">
      <div class="modal-title">Awaiting Administrator Approval</div>
      <p style="color:var(--text-dim); font-size:13px; margin-bottom:22px; line-height:1.6;">Your request has been submitted. Please wait while the administrator reviews your entry request.</p>
      <div class="rejoin-circle">
        <span id="rejoinCountdown">5:00</span>
        <svg style="position:absolute;top:-3px;left:-3px;width:76px;height:76px;" viewBox="0 0 88 88">
          <circle id="rejoinProgressCircle" cx="44" cy="44" r="40" fill="none" stroke="#c9a961" stroke-width="3" stroke-dasharray="251" stroke-dashoffset="0" stroke-linecap="round" transform="rotate(-90 44 44)" style="transition:stroke-dashoffset 1s linear;"/>
        </svg>
      </div>
      <div id="rejoinRetryArea" style="display:none;">
        <p style="color:#dfb87c; font-size:12.5px; font-weight:600; margin-bottom:12px;">Admin response pending. You may resend the request.</p>
        <button type="button" class="action-btn btn-rejoin" onclick="resendRejoinRequest()">Resend Request</button>
      </div>
      <button type="button" class="action-btn" style="background:rgba(255,255,255,.04);border:1px solid var(--line-2);color:var(--text); margin-top:12px;" onclick="closeRejoinModal()">Cancel</button>
    </div>
    <!-- Step 3 -->
    <div id="rejoinStep3" style="display:none; text-align:center;">
      <div class="modal-title">Admitted to Hearing</div>
      <p style="color:var(--text-dim); font-size:13px; margin-bottom:20px; line-height:1.6;">Redirecting to live hearing workspace…</p>
      <div style="width: 80%; height: 4px; background: rgba(255,255,255,0.05); border-radius: 2px; margin: 0 auto 16px; overflow: hidden;">
        <div id="rejoinStep3Progress" style="width: 0%; height: 100%; background: #6e9e7e; border-radius: 2px; transition: width 2s ease-in-out;"></div>
      </div>
    </div>
  </div>
</div>

<!-- Modal Logout -->
<div id="logoutModal" class="modal-overlay">
  <div class="modal-content">
    <div class="modal-title">Sign Out Session</div>
    <div class="modal-desc">
      Are you sure you want to terminate your current UPCC Panel session?
    </div>
    <div class="modal-actions">
      <button type="button" class="action-btn" style="background:rgba(255,255,255,.04);border:1px solid var(--line-2);color:var(--text);" onclick="document.getElementById('logoutModal').classList.remove('show');">Cancel</button>
      <button type="button" class="action-btn" style="background:linear-gradient(180deg,#a85858,#8d4747);color:#fff;border-color:#8d4747;" onclick="window.location.href='upccpanel.php?action=logout'">Sign Out</button>
    </div>
  </div>
</div>

<script>
function handleRowClick(href, accepted, caseId, hearingIsOpen, hearingIsPaused, myPresenceStatus, adminOffline, isResolved) {
  if (isResolved) {
    return;
  }
  if (hearingIsOpen === 0) {
    alert('This case hearing is currently locked by the administrator.');
    return;
  }
  
  if (hearingIsPaused === 1 || adminOffline === 1) {
    alert('This hearing is currently paused by the administrator.');
    return;
  }

  if (String(myPresenceStatus || 'ADMITTED').toUpperCase() !== 'ADMITTED') {
    triggerRejoin(caseId);
    return;
  }
  
  if (accepted) {
    window.location.href = href;
  } else {
    triggerAcknowledge(caseId);
  }
}

function triggerAcknowledge(caseId) {
  document.getElementById('ackCaseIdStep2').value = caseId;
  document.getElementById('ackModalStep2').classList.remove('show');
  document.getElementById('ackModal').classList.add('show');
}

function closeAckModal() {
  document.getElementById('ackModal').classList.remove('show');
}

function showAckStep2() {
  document.getElementById('ackModal').classList.remove('show');
  document.getElementById('ackModalStep2').classList.add('show');
}

function backToAckStep1() {
  document.getElementById('ackModalStep2').classList.remove('show');
  document.getElementById('ackModal').classList.add('show');
}

function closeSecondAckModal() {
  document.getElementById('ackModalStep2').classList.remove('show');
}

function triggerDecline(caseId) {
  document.getElementById('declineCaseId').value = caseId;
  document.getElementById('declineModal').classList.add('show');
}

function closeDeclineModal() {
  document.getElementById('declineModal').classList.remove('show');
}

function triggerRejoin(caseId) {
  document.getElementById('rejoinCaseId').value = caseId;
  document.getElementById('rejoinModal').classList.add('show');
}

function closeRejoinModal() {
  clearRejoinTimers();
  document.getElementById('rejoinModal').classList.remove('show');
  document.getElementById('rejoinStep1').style.display = 'block';
  document.getElementById('rejoinStep2').style.display = 'none';
  document.getElementById('rejoinStep3').style.display = 'none';
  const progressFill = document.getElementById('rejoinStep3Progress');
  if (progressFill) progressFill.style.width = '0%';
  document.getElementById('rejoinRetryArea').style.display = 'none';
  const btn = document.getElementById('btnSendRejoin');
  if (btn) { btn.textContent = 'Submit Rejoin Request'; btn.disabled = false; }
}

let _rejoinPollInterval = null;
let _rejoinCountdownInterval = null;
let _rejoinHref = '';

function sendRejoinRequest() {
  const caseId = document.getElementById('rejoinCaseId').value;
  if (!caseId) return;

  _rejoinHref = 'case_view.php?id=' + caseId;

  const fd = new FormData();
  fd.append('actor', 'upcc');
  fd.append('case_id', caseId);

  const btn = document.getElementById('btnSendRejoin');
  btn.textContent = 'Submitting...';
  btn.disabled = true;

  fetch('../api/upcc_case_live.php?action=request_rejoin', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
      if (res.ok) {
        document.getElementById('rejoinStep1').style.display = 'none';
        document.getElementById('rejoinStep2').style.display = 'block';
        document.getElementById('rejoinRetryArea').style.display = 'none';
        startRejoinWaiting(caseId);
      } else {
        alert(res.message || res.error || 'Failed to send request.');
        btn.textContent = 'Submit Rejoin Request';
        btn.disabled = false;
      }
    })
    .catch(err => {
      console.error(err);
      alert('An error occurred while submitting the request.');
      btn.textContent = 'Submit Rejoin Request';
      btn.disabled = false;
    });
}

function resendRejoinRequest() {
  document.getElementById('rejoinStep2').style.display = 'none';
  document.getElementById('rejoinStep1').style.display = 'block';
  const btn = document.getElementById('btnSendRejoin');
  btn.textContent = 'Submit Rejoin Request';
  btn.disabled = false;
  clearRejoinTimers();
}

function clearRejoinTimers() {
  if (_rejoinPollInterval) { clearInterval(_rejoinPollInterval); _rejoinPollInterval = null; }
  if (_rejoinCountdownInterval) { clearInterval(_rejoinCountdownInterval); _rejoinCountdownInterval = null; }
}

function startRejoinWaiting(caseId) {
  clearRejoinTimers();
  let seconds = 300;
  const countEl = document.getElementById('rejoinCountdown');
  const circle = document.getElementById('rejoinProgressCircle');
  const circumference = 251;

  function formatTime(sec) {
    let m = Math.floor(sec / 60);
    let s = sec % 60;
    return m + ":" + (s < 10 ? "0" : "") + s;
  }

  if (countEl) countEl.textContent = formatTime(seconds);
  if (circle) circle.style.strokeDashoffset = '0';

  _rejoinCountdownInterval = setInterval(() => {
    seconds--;
    if (countEl) countEl.textContent = formatTime(Math.max(0, seconds));
    if (circle) circle.style.strokeDashoffset = ((300 - seconds) / 300 * circumference);
    if (seconds <= 0) {
      clearInterval(_rejoinCountdownInterval);
      clearInterval(_rejoinPollInterval);
      document.getElementById('rejoinRetryArea').style.display = 'block';
    }
  }, 1000);

  _rejoinPollInterval = setInterval(() => {
    fetch('../api/upcc_case_live.php?case_id=' + caseId + '&actor=upcc&t=' + Date.now(), { cache: 'no-store' })
      .then(r => r.json())
      .then(data => {
        if (data.my_status === 'ADMITTED' && data.is_paused === false) {
          clearRejoinTimers();
          document.getElementById('rejoinStep2').style.display = 'none';
          document.getElementById('rejoinStep3').style.display = 'block';
          
          setTimeout(() => {
            const bar = document.getElementById('rejoinStep3Progress');
            if (bar) bar.style.width = '100%';
          }, 50);

          setTimeout(() => {
            window.location.href = _rejoinHref;
          }, 2000);
        }
      })
      .catch(() => {});
  }, 2000);
}

function refreshCaseStatus() {
  fetch('./upccdashboard.php?action=refresh_cases&t=' + Date.now(), { method: 'GET', cache: 'no-store' })
    .then(r => r.text())
    .then(html => {
      const parser = new DOMParser();
      const newDoc = parser.parseFromString(html, 'text/html');
      const newRows = newDoc.querySelectorAll('tbody tr');
      const currentRows = document.querySelectorAll('tbody tr');
      
      const tbody = document.querySelector('tbody');
      const newTbody = newDoc.querySelector('tbody');
      if (tbody && newTbody) {
          if (newRows.length === currentRows.length) {
              newRows.forEach((newRow, idx) => {
                  const currentRow = currentRows[idx];
                  if (currentRow && newRow && currentRow.outerHTML !== newRow.outerHTML) {
                      currentRow.outerHTML = newRow.outerHTML;
                  }
              });
          } else {
              tbody.innerHTML = newTbody.innerHTML;
          }
      }
    })
    .catch(err => console.log('Refresh failed:', err));
}

setInterval(refreshCaseStatus, 5000);

document.addEventListener('keyup', (e) => {
    if (e.key === 'PrintScreen') {
        document.body.style.display = 'none';
        alert('Security Alert: Screenshot key press detected.');
        setTimeout(() => document.body.style.display = '', 2000);
    }
});

function dismissResolvedCase(caseId) {
    if (confirm('Dismiss this resolved case from your queue?')) {
        fetch('upccdashboard.php?action=dismiss_case&case_id=' + caseId)
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    window.location.reload();
                }
            })
            .catch(err => console.error(err));
    }
}

document.getElementById('caseSearch')?.addEventListener('input', function (e) {
  const q = (e.target.value || '').toLowerCase().trim();
  const rows = document.querySelectorAll('tbody tr');
  rows.forEach(r => {
    const text = (r.textContent || '').toLowerCase();
    r.style.display = q === '' || text.indexOf(q) !== -1 ? '' : 'none';
  });
});

document.getElementById('caseFilter')?.addEventListener('change', function (e) {
  const v = e.target.value;
  const rows = document.querySelectorAll('tbody tr');
  rows.forEach(r => {
    if (!v) { r.style.display = ''; return; }
    if (v === 'Near Hearing') {
      r.style.display = r.getAttribute('data-near-hearing') === '1' ? '' : 'none';
      return;
    }
    
    const isOpen = r.getAttribute('data-is-open') === '1';
    const isPaused = r.getAttribute('data-is-paused') === '1';
    const isAdminOffline = r.getAttribute('data-admin-offline') === '1';
    
    let isMatch = false;
    if (v === 'Hearing Live' && isOpen && !isPaused && !isAdminOffline) {
       isMatch = true;
    } else if (v === 'Paused' && isOpen && (isPaused || isAdminOffline)) {
       isMatch = true;
    } else if (v === 'Locked' && !isOpen) {
       isMatch = true;
    }
    
    r.style.display = isMatch ? '' : 'none';
  });
});
</script>
</body>
</html>