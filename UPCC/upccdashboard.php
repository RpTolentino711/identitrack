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
                    <span class="badge badge-warning" style="font-size:10px; padding:5px 12px; background:rgba(245, 158, 11, 0.1); color:#fbbf24; border:1px solid rgba(245, 158, 11, 0.25);">Awaiting Admin Admission</span>
                  <?php else: ?>
                    <button onclick="event.stopPropagation(); triggerRejoin(<?php echo (int)$c['case_id']; ?>)" class="badge badge-warning action-btn" style="font-size:10px; cursor:pointer; pointer-events:auto; padding:6px 12px; background:rgba(245, 158, 11, 0.15); color:#fbbf24; border:1px solid rgba(245, 158, 11, 0.3);">Request Rejoin</button>
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
<title>UPCC Panel Workspace — Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root {
  --font-heading: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
  --font-body: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
  
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
  font-family: var(--font-body);
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

/* Sidebar */
.sidebar {
  background: var(--bg-sidebar);
  border-right: 1px solid var(--border-glass);
  padding: 28px 20px;
  display: flex;
  flex-direction: column;
}

.brand {
  display: flex; align-items: center; gap: 12px;
  margin-bottom: 36px; padding-bottom: 20px;
  border-bottom: 1px solid var(--border-glass);
}
.brand-icon {
  width: 44px; height: 44px;
  background: rgba(255,255,255,0.04);
  border: 1px solid var(--border-glass);
  border-radius: 10px;
  display: grid; place-items: center;
  padding: 6px;
}
.brand-icon img { width: 100%; height: auto; display: block; border-radius: 4px; }
.brand-text h1 { font-family: var(--font-heading); font-size: 17px; font-weight: 700; letter-spacing: 0.3px; line-height: 1.2; }
.brand-text p { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1.2px; font-weight: 600; margin-top: 2px; }

.nav-link {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 16px; margin-bottom: 8px;
  border-radius: var(--radius-sm);
  color: var(--text-sub); text-decoration: none;
  font-weight: 500; font-size: 13.5px;
  background: transparent; border: 1px solid transparent;
  transition: all 0.2s ease;
}
.nav-link:hover {
  background: rgba(255,255,255,0.04);
  color: var(--text-main);
  border-color: var(--border-glass);
}
.nav-link.active {
  background: rgba(56, 189, 248, 0.12);
  border: 1px solid rgba(56, 189, 248, 0.25);
  color: #38bdf8;
  font-weight: 600;
}

/* Main Content */
.main-content {
  padding: 36px 40px;
  overflow-y: auto;
}
.header {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 28px;
}
.greeting h2 {
  font-family: var(--font-heading); font-size: 26px; font-weight: 700;
  color: var(--text-main); letter-spacing: -0.3px;
}
.greeting p { color: var(--text-muted); margin-top: 4px; font-size: 14px; }

.institutional-badge {
  font-family: var(--font-body); font-size: 11px; font-weight: 600; letter-spacing: 0.5px;
  color: #94a3b8; background: rgba(255,255,255,0.03);
  padding: 8px 16px; border: 1px solid var(--border-glass); border-radius: 8px;
  display: inline-flex; align-items: center; gap: 8px;
}
.institutional-badge .secure-dot {
  width: 6px; height: 6px; border-radius: 50%; background: #10b981;
  box-shadow: 0 0 8px rgba(16, 185, 129, 0.6);
}

/* Controls */
.toolbar {
  display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 24px;
}
.search-wrapper { position: relative; width: 380px; max-width: 60%; }
.search-wrapper svg { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: var(--text-muted); pointer-events: none; }
.search-input {
  width: 100%; padding: 10px 14px 10px 40px; border-radius: 10px; border: 1px solid var(--border-glass);
  background: rgba(18, 26, 43, 0.5); color: var(--text-main); font-family: var(--font-body); font-size: 13px;
  outline: none; transition: border-color .15s ease, box-shadow .15s ease;
}
.search-input:focus { border-color: rgba(56, 189, 248, 0.5); box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.12); }

.filter-select {
  padding: 10px 14px; border-radius: 10px; border: 1px solid var(--border-glass);
  background: rgba(18, 26, 43, 0.5); color: var(--text-sub); font-family: var(--font-body); font-size: 13px; outline: none; cursor: pointer;
}
.filter-select option { background: #0f172a; color: #fff; }

/* Stats Grid */
.stats-grid {
  display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px;
  margin-bottom: 28px;
}
.stat-card {
  background: var(--bg-card);
  backdrop-filter: blur(12px);
  border: 1px solid var(--border-glass);
  border-radius: var(--radius-lg);
  padding: 20px 24px;
  position: relative;
}
.stat-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.stat-title { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; font-weight: 600; }
.stat-icon-wrap {
  width: 34px; height: 34px; border-radius: 8px; display: grid; place-items: center;
  background: rgba(255,255,255,0.04); border: 1px solid var(--border-glass); color: var(--text-sub);
}
.stat-value { font-family: var(--font-heading); font-size: 34px; font-weight: 700; color: var(--text-main); line-height: 1; margin-bottom: 4px; }
.stat-desc { font-size: 12.5px; color: var(--text-muted); }

.st-total .stat-icon-wrap { color: #38bdf8; background: rgba(56, 189, 248, 0.1); border-color: rgba(56, 189, 248, 0.2); }
.st-active .stat-icon-wrap { color: #fbbf24; background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2); }
.st-resolved .stat-icon-wrap { color: #34d399; background: rgba(16, 185, 129, 0.1); border-color: rgba(16, 185, 129, 0.2); }

/* Layout Grid */
.dashboard-layout {
  display: grid; grid-template-columns: 1fr 320px; gap: 24px;
}

/* Glass Panels */
.glass-panel {
  background: var(--bg-card);
  backdrop-filter: blur(16px);
  border: 1px solid var(--border-glass);
  border-radius: var(--radius-lg);
  overflow: hidden;
  display: flex; flex-direction: column;
}
.panel-header {
  padding: 20px 24px; border-bottom: 1px solid var(--border-glass);
  display: flex; align-items: center; justify-content: space-between;
  background: rgba(255, 255, 255, 0.01);
}
.panel-title {
  display: flex; align-items: center; gap: 10px;
  font-family: var(--font-heading); font-size: 16px; font-weight: 700; color: var(--text-main);
}
.panel-title svg { color: var(--accent-primary); }

/* Executive Table */
.table-wrapper { overflow-x: auto; padding: 0 24px 20px; }
.table { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
.table th {
  text-align: left; padding: 12px 16px;
  font-size: 11px; text-transform: uppercase; letter-spacing: 1px;
  color: var(--text-muted); font-weight: 600; border-bottom: 1px solid var(--border-glass);
}
.table td {
  padding: 16px; background: rgba(255,255,255,0.015);
  font-size: 13.5px; border-top: 1px solid var(--border-glass); border-bottom: 1px solid var(--border-glass);
  vertical-align: middle;
}
.table td:first-child { border-left: 1px solid var(--border-glass); border-radius: 10px 0 0 10px; }
.table td:last-child { border-right: 1px solid var(--border-glass); border-radius: 0 10px 10px 0; }
.table tr { transition: all 0.2s ease; }

.table tr:not(.case-locked):not(.case-resolved-row):hover td {
  background: rgba(255,255,255,0.04);
  border-color: rgba(255,255,255,0.15);
  cursor: pointer;
}
.table tr.case-locked { opacity: 0.6; }
.table tr.case-locked:hover td { cursor: not-allowed; background: rgba(255,255,255,0.015); }

.table tr.case-needs-action {
  position: relative;
  background: rgba(16, 185, 129, 0.03);
}
.table tr.case-needs-action td {
  border-color: rgba(16, 185, 129, 0.25);
}

.t-id { font-family: monospace; font-weight: 700; color: #38bdf8; font-size: 13px; }
.t-name { font-weight: 600; color: var(--text-main); display: block; margin-bottom: 2px; }
.t-sub { font-size: 12px; color: var(--text-muted); }

/* Badges */
.badge {
  display: inline-flex; align-items: center; gap: 6px; padding: 4px 10px;
  border-radius: 6px; font-size: 11px; font-weight: 600; letter-spacing: 0.3px;
  border: 1px solid transparent; text-transform: uppercase;
}
.badge-pending { background: rgba(245, 158, 11, 0.1); color: #fbbf24; border-color: rgba(245, 158, 11, 0.25); }
.badge-resolved { background: rgba(16, 185, 129, 0.1); color: #34d399; border-color: rgba(16, 185, 129, 0.25); }
.badge-appeal { background: rgba(239, 68, 68, 0.1); color: #f87171; border-color: rgba(239, 68, 68, 0.25); }
.badge-success { background: rgba(16, 185, 129, 0.12); color: #34d399; border-color: rgba(16, 185, 129, 0.3); }
.badge-muted { background: rgba(100, 116, 139, 0.12); color: #94a3b8; border-color: rgba(100, 116, 139, 0.25); }
.badge-warning { background: rgba(245, 158, 11, 0.12); color: #fbbf24; border-color: rgba(245, 158, 11, 0.3); }
.badge-online { background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.25); color: #7dd3fc; padding: 2px 6px; font-size: 10px; border-radius: 4px; }

.dot { width: 6px; height: 6px; border-radius: 50%; display: inline-block; }
.dot-live { background: #10b981; box-shadow: 0 0 6px rgba(16, 185, 129, 0.8); }
.dot-paused { background: #94a3b8; }

.confidential-blur-pill {
  display: inline-flex; align-items: center; gap: 6px;
  font-size: 11.5px; color: var(--text-muted); font-style: italic;
  background: rgba(255,255,255,0.03); padding: 4px 10px; border-radius: 6px; border: 1px solid var(--border-glass);
}

.action-btn {
  background: #2563eb; color: #fff; border: none; padding: 7px 14px; border-radius: 8px;
  font-weight: 600; font-size: 12px; cursor: pointer; transition: all 0.2s ease; text-decoration: none;
  display: inline-flex; align-items: center; gap: 6px; border: 1px solid transparent;
}
.action-btn:hover { background: #1d4ed8; transform: translateY(-1px); }
.btn-join { background: #059669; border-color: #10b981; }
.btn-join:hover { background: #047857; }
.btn-ack-yes { background: rgba(16, 185, 129, 0.15); color: #34d399; border: 1px solid rgba(16, 185, 129, 0.3); font-size: 11px; padding: 5px 10px; }
.btn-ack-yes:hover { background: rgba(16, 185, 129, 0.25); }
.btn-ack-no { background: rgba(239, 68, 68, 0.15); color: #f87171; border: 1px solid rgba(239, 68, 68, 0.3); font-size: 11px; padding: 5px 10px; }
.btn-ack-no:hover { background: rgba(239, 68, 68, 0.25); }

.need-action-box {
  display: flex; flex-direction: column; gap: 4px;
}
.need-action-label {
  font-size: 10px; color: #fbbf24; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px;
}

.dismiss-resolved-btn {
  background: rgba(239, 68, 68, 0.15); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171;
  width: 26px; height: 26px; border-radius: 50%; display: inline-flex; align-items: center; justify-content: center;
  font-size: 16px; cursor: pointer; transition: all 0.2s; flex-shrink: 0;
}
.dismiss-resolved-btn:hover { background: rgba(239, 68, 68, 0.35); color: #fff; }

/* Offense Badge & Tooltip */
.offense-badge {
  display: inline-flex; align-items: center; gap: 6px;
  padding: 6px 10px; border-radius: 6px;
  font-weight: 700; font-size: 11px; letter-spacing: 0.5px;
  position: relative; cursor: help; transition: all 0.2s; white-space: nowrap;
}
.offense-badge.major { background: rgba(239, 68, 68, 0.12); border: 1px solid rgba(239, 68, 68, 0.3); color: #f87171; }
.offense-badge.minor { background: rgba(56, 189, 248, 0.12); border: 1px solid rgba(56, 189, 248, 0.3); color: #38bdf8; }
.offense-badge:hover { transform: translateY(-1px); }

.offense-tooltip {
  position: absolute; top: 100%; left: 50%; transform: translateX(-50%) translateY(8px);
  background: #0f172a; border-radius: 10px; padding: 14px; min-width: 280px;
  border: 1px solid var(--border-glass-hover); box-shadow: 0 16px 36px rgba(0,0,0,0.6);
  opacity: 0; visibility: hidden; z-index: 99; transition: all 0.2s ease; pointer-events: none;
}
.offense-badge:hover .offense-tooltip { opacity: 1; visibility: visible; pointer-events: auto; transform: translateX(-50%) translateY(4px); }
.offense-tooltip-title { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; font-weight: 700; margin-bottom: 10px; border-bottom: 1px solid var(--border-glass); padding-bottom: 6px; }
.offense-item { display: flex; align-items: center; gap: 8px; padding: 6px 0; font-size: 12px; }
.offense-level-badge { padding: 2px 6px; border-radius: 4px; font-weight: 700; font-size: 10px; min-width: 26px; text-align: center; }
.offense-level-4 { background: rgba(239, 68, 68, 0.2); color: #f87171; }
.offense-level-3 { background: rgba(245, 158, 11, 0.2); color: #fbbf24; }
.offense-level-2 { background: rgba(56, 189, 248, 0.2); color: #38bdf8; }
.offense-level-1 { background: rgba(16, 185, 129, 0.2); color: #34d399; }
.offense-name { color: var(--text-sub); flex: 1; }

/* Profile Card */
.profile-wrap { padding: 24px; text-align: center; }
.avatar {
  width: 80px; height: 80px; background: rgba(255,255,255,0.05); border: 1px solid var(--border-glass);
  border-radius: 50%; margin: 0 auto 16px; display: grid; place-items: center;
  font-size: 26px; font-weight: 700; font-family: var(--font-heading); color: var(--text-main);
  background-size: cover; background-position: center;
}
.profile-name { font-family: var(--font-heading); font-size: 18px; font-weight: 700; margin-bottom: 2px; }
.profile-role { font-size: 12px; color: var(--accent-primary); font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 20px; }

.profile-meta { text-align: left; border-top: 1px solid var(--border-glass); padding-top: 16px; }
.meta-row { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 12.5px; }
.meta-row .label { color: var(--text-muted); }
.meta-row .value { font-weight: 500; color: var(--text-sub); }
.meta-row .value.ok { color: var(--success); }

.quick-links { margin-top: 16px; display: grid; gap: 8px; }
.q-link {
  display: flex; align-items: center; justify-content: space-between;
  padding: 12px 16px; border-radius: 10px;
  background: rgba(255,255,255,0.02); border: 1px solid var(--border-glass);
  color: var(--text-sub); text-decoration: none; font-size: 13px; font-weight: 500; transition: all 0.2s;
}
.q-link:hover { background: rgba(255,255,255,0.05); border-color: var(--border-glass-hover); color: var(--text-main); }
.q-link.danger:hover { background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.3); color: #f87171; }

/* Modals */
.modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.75); backdrop-filter: blur(10px);
  z-index: 1000; display: none; place-items: center; padding: 20px;
}
.modal-overlay.show { display: grid; }
.modal-content {
  background: #0f172a; border: 1px solid var(--border-glass-hover); border-radius: var(--radius-lg);
  padding: 32px; max-width: 480px; text-align: center; box-shadow: 0 24px 48px rgba(0,0,0,0.6); position: relative;
}
.modal-title { font-family: var(--font-heading); font-size: 20px; font-weight: 700; margin-bottom: 10px; color: var(--text-main); }
.modal-desc { color: var(--text-muted); font-size: 13.5px; margin-bottom: 24px; line-height: 1.5; }
.modal-actions { display: flex; gap: 12px; justify-content: center; }

@media (max-width: 1024px) {
  .app-container { display: flex; flex-direction: column; }
  .sidebar { width: 100%; padding: 16px 20px; flex-direction: row; align-items: center; justify-content: space-between; border-right: none; border-bottom: 1px solid var(--border-glass); }
  .brand { margin-bottom: 0; padding-bottom: 0; border-bottom: none; }
  .dashboard-layout { grid-template-columns: 1fr; }
  .stats-grid { grid-template-columns: 1fr; }
  .search-wrapper { width: 100%; max-width: 100%; }
  .toolbar { flex-direction: column; align-items: stretch; }
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
        <h1>Identitrack</h1>
        <p>UPCC Portal</p>
      </div>
    </div>
    <nav>
      <a href="upccdashboard.php" class="nav-link active">
        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
        Dashboard Overview
      </a>
    </nav>
  </aside>

  <!-- Main View -->
  <main class="main-content">
    <header class="header">
      <div class="greeting">
        <h2><?php echo $greeting; ?>, <?php echo $firstName; ?></h2>
        <p>University Disciplinary Board Case Workspace</p>
      </div>
      <div class="institutional-badge">
        <span class="secure-dot"></span>
        <span>SECURE PANEL SYSTEM</span>
        <span style="opacity:0.4;">|</span>
        <span><?php echo date('Y-m-d H:i:s'); ?></span>
      </div>
    </header>
    
    <!-- Toolbar: Search + Filters -->
    <div class="toolbar">
      <div class="search-wrapper">
        <svg width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
        <input id="caseSearch" class="search-input" type="search" placeholder="Search by Case ID or Respondent Name...">
      </div>
      <select id="caseFilter" class="filter-select">
        <option value="">All Hearing Statuses</option>
        <option value="Near Hearing">Scheduled Today</option>
        <option value="Hearing Live">Live Hearings</option>
        <option value="Paused">Paused Hearings</option>
        <option value="Locked">Locked Cases</option>
      </select>
    </div>

    <?php if (isset($_GET['hearing_msg'])): ?>
      <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.25); color: #34d399; padding: 14px 18px; border-radius: 10px; margin-bottom: 24px; display:flex; align-items:center; gap:10px; font-size:13.5px; font-weight:500;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
        <?php echo htmlspecialchars((string)$_GET['hearing_msg']); ?>
      </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card st-total">
        <div class="stat-header">
          <span class="stat-title">Assigned Cases</span>
          <div class="stat-icon-wrap">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
          </div>
        </div>
        <div class="stat-value"><?php echo $totalCases; ?></div>
        <div class="stat-desc">Total panel assignments on record</div>
      </div>

      <div class="stat-card st-active">
        <div class="stat-header">
          <span class="stat-title">Active / Pending</span>
          <div class="stat-icon-wrap">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
          </div>
        </div>
        <div class="stat-value"><?php echo $pendingCases; ?></div>
        <div class="stat-desc">Awaiting panel hearing &amp; consensus</div>
      </div>

      <div class="stat-card st-resolved">
        <div class="stat-header">
          <span class="stat-title">Resolved Cases</span>
          <div class="stat-icon-wrap">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg>
          </div>
        </div>
        <div class="stat-value"><?php echo $resolvedCases; ?></div>
        <div class="stat-desc">Finalized by panel &amp; administration</div>
      </div>
    </div>

    <div class="dashboard-layout">
      <!-- Cases List -->
      <div class="glass-panel">
        <div class="panel-header">
          <div class="panel-title">
            <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>
            Assigned Case Queue
          </div>
        </div>
        
        <?php if (empty($recentCases)): ?>
          <div style="padding: 60px 20px; text-align: center; color: var(--text-muted);">
            <p>No assigned cases present in queue.</p>
          </div>
        <?php else: ?>
          <div class="table-wrapper">
            <table class="table" style="margin-top: 12px;">
              <thead>
                <tr>
                  <th>Case ID</th>
                  <th>Respondent</th>
                  <th>Offenses</th>
                  <th>Hearing Schedule</th>
                  <th style="min-width: 180px;">Status / Action</th>
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
                                <span class="badge badge-warning" style="font-size:10px; padding:5px 12px; background:rgba(245, 158, 11, 0.1); color:#fbbf24; border:1px solid rgba(245, 158, 11, 0.25);">Awaiting Admin Admission</span>
                              <?php else: ?>
                                <button onclick="event.stopPropagation(); triggerRejoin(<?php echo (int)$c['case_id']; ?>)" class="badge badge-warning action-btn" style="font-size:10px; cursor:pointer; pointer-events:auto; padding:6px 12px; background:rgba(245, 158, 11, 0.15); color:#fbbf24; border:1px solid rgba(245, 158, 11, 0.3);">Request Rejoin</button>
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
            <div class="avatar" style="background-image: url('<?php echo htmlspecialchars($user['photo_path']); ?>'); border-color: var(--accent-primary);"></div>
          <?php else: ?>
            <div class="avatar"><?php echo $initials; ?></div>
          <?php endif; ?>
          <div class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
          <div class="profile-role">UPCC Panel Member</div>
          
          <div class="profile-meta">
            <div class="meta-row"><span class="label">Username</span><span class="value"><?php echo htmlspecialchars($user['username']); ?></span></div>
            <div class="meta-row"><span class="label">Session Status</span><span class="value ok">Authenticated</span></div>
            <div class="meta-row"><span class="label">Security Level</span><span class="value">Institutional Panel</span></div>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="glass-panel">
          <div class="panel-header" style="padding: 16px 20px;">
            <div class="panel-title" style="font-size:14px;">Panel Actions</div>
          </div>
          <div style="padding: 16px 20px;">
            <div class="quick-links">
              <a href="upcc_settings.php" class="q-link">
                <div style="display:flex; align-items:center; gap:10px;">
                  <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                  Security Settings
                </div>
                <span>&rarr;</span>
              </a>
              <a href="javascript:void(0)" onclick="document.getElementById('logoutModal').classList.add('show');" class="q-link danger">
                <div style="display:flex; align-items:center; gap:10px;">
                  <svg width="15" height="15" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
                  Sign Out Session
                </div>
              </a>
            </div>
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
      <button type="button" class="action-btn" style="background:rgba(255,255,255,0.05); border:1px solid var(--border-glass);" onclick="closeAckModal()">Cancel</button>
      <button type="button" class="action-btn btn-join" onclick="showAckStep2()">I Acknowledge &amp; Proceed</button>
    </div>
  </div>
</div>

<!-- Modal Acknowledge Step 2: Confirmation -->
<div id="ackModalStep2" class="modal-overlay">
  <div class="modal-content" style="border-color: rgba(239, 68, 68, 0.4);">
    <div class="modal-title" style="color:#f87171;">Final Legal Confirmation</div>
    <div class="modal-desc" style="background: rgba(239, 68, 68, 0.08); border-left: 3px solid #ef4444; padding: 12px; border-radius: 6px; text-align: left; font-size: 12.5px; color: #fca5a5;">
      <strong>NOTICE:</strong> Accessing these records generates an audit log. Any breach of student confidentiality or institutional policy will result in immediate termination of panel privileges and formal disciplinary proceedings.
    </div>
    <form method="post" action="upccdashboard.php" style="margin-top:20px;">
      <input type="hidden" name="action" value="accept_confidentiality">
      <input type="hidden" name="case_id" id="ackCaseIdStep2" value="">
      <div class="modal-actions">
        <button type="button" class="action-btn" style="background:rgba(255,255,255,0.05); border:1px solid var(--border-glass);" onclick="backToAckStep1()">&larr; Back</button>
        <button type="submit" class="action-btn" style="background:#dc2626; border-color:#dc2626; color:#fff;">Confirm &amp; Accept Role</button>
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
        <label style="display:block; font-size:12px; color:var(--text-muted); margin-bottom:6px; font-weight:600; text-transform:uppercase;">Reason for Declining</label>
        <textarea name="reason" rows="3" style="width:100%; padding:10px; border-radius:8px; border:1px solid var(--border-glass); background:rgba(0,0,0,0.3); color:#fff; font-family:var(--font-body); font-size:13px; outline:none; resize:none;" placeholder="Specify reason..."></textarea>
      </div>
      <div class="modal-actions">
        <button type="button" class="action-btn" style="background:rgba(255,255,255,0.05); border:1px solid var(--border-glass);" onclick="closeDeclineModal()">Cancel</button>
        <button type="submit" class="action-btn" style="background:var(--danger);">Decline Assignment</button>
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
        <button type="button" class="action-btn" style="background:rgba(255,255,255,0.05); border:1px solid var(--border-glass);" onclick="closeRejoinModal()">Cancel</button>
        <button type="button" id="btnSendRejoin" class="action-btn btn-join" onclick="sendRejoinRequest()">Submit Rejoin Request</button>
      </div>
    </div>
    <!-- Step 2 -->
    <div id="rejoinStep2" style="display:none; text-align:center;">
      <div class="modal-title">Awaiting Administrator Approval</div>
      <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px;">Your request has been submitted. Please wait while the administrator reviews your entry request.</p>
      <div style="width:70px; height:70px; border-radius:50%; background:rgba(56,189,248,0.1); border:3px solid rgba(56,189,248,0.3); display:flex; align-items:center; justify-content:center; margin:0 auto 20px; position:relative;">
        <span id="rejoinCountdown" style="font-size:1.4rem; font-weight:700; color:#38bdf8;">5:00</span>
        <svg style="position:absolute;top:-3px;left:-3px;width:76px;height:76px;" viewBox="0 0 88 88">
          <circle id="rejoinProgressCircle" cx="44" cy="44" r="40" fill="none" stroke="#38bdf8" stroke-width="3" stroke-dasharray="251" stroke-dashoffset="0" stroke-linecap="round" transform="rotate(-90 44 44)" style="transition:stroke-dashoffset 1s linear;"/>
        </svg>
      </div>
      <div id="rejoinRetryArea" style="display:none;">
        <p style="color:#fbbf24; font-size:12.5px; font-weight:600; margin-bottom:12px;">Admin response pending. You may resend the request.</p>
        <button type="button" class="action-btn" style="background:var(--accent-secondary);" onclick="resendRejoinRequest()">Resend Request</button>
      </div>
      <button type="button" class="action-btn" style="background:rgba(255,255,255,0.05); border:1px solid var(--border-glass); margin-top:12px;" onclick="closeRejoinModal()">Cancel</button>
    </div>
    <!-- Step 3 -->
    <div id="rejoinStep3" style="display:none; text-align:center;">
      <div class="modal-title">Admitted to Hearing</div>
      <p style="color:var(--text-muted); font-size:13px; margin-bottom:20px;">Redirecting to live hearing workspace...</p>
      <div style="width: 80%; height: 4px; background: rgba(255,255,255,0.05); border-radius: 2px; margin: 0 auto 16px; overflow: hidden;">
        <div id="rejoinStep3Progress" style="width: 0%; height: 100%; background: #10b981; border-radius: 2px; transition: width 2s ease-in-out;"></div>
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
      <button type="button" class="action-btn" style="background:rgba(255,255,255,0.05); border:1px solid var(--border-glass);" onclick="document.getElementById('logoutModal').classList.remove('show');">Cancel</button>
      <button type="button" class="action-btn" style="background:var(--danger);" onclick="window.location.href='upccpanel.php?action=logout'">Sign Out</button>
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