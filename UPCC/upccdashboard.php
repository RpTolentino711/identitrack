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
        return ($case['hearing_is_open'] == 1 && ($case['hearing_is_paused'] == 0));
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
    
    $recentCases = db_all("
         SELECT uc.case_id, uc.status, uc.created_at,
           uc.hearing_date, uc.hearing_time, uc.hearing_type, uc.hearing_is_open, uc.hearing_is_paused,
               COALESCE((SELECT p.status FROM upcc_hearing_presence p
                         WHERE p.case_id = uc.case_id AND p.user_type = 'UPCC' AND p.user_id = :presence_uid
                LIMIT 1), 'ADMITTED') AS my_presence_status,
               uc.hearing_vote_consensus_category,
               CONCAT(s.student_fn,' ',s.student_ln) AS student_name,
               s.student_id,
               GROUP_CONCAT(ot.name ORDER BY ot.offense_type_id SEPARATOR ', ') AS offense_names,
               MAX(ot.level) AS offense_level,
               GROUP_CONCAT(DISTINCT CONCAT(ot.level, ':', ot.name) ORDER BY ot.level DESC SEPARATOR '||') AS offense_details,
               (SELECT COUNT(*) FROM upcc_case_vote v WHERE v.case_id = uc.case_id AND v.upcc_id = :join_uid2) AS user_has_voted,
               (SELECT round_no FROM upcc_case_vote_round WHERE case_id = uc.case_id AND is_active = 1 LIMIT 1) AS active_round
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
    ", array_merge([':presence_uid' => $panelId, ':vote_uid' => $panelId, ':join_uid2' => $panelId, ':join_uid' => $panelId, ':legacy_uid' => $panelId]));
    
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
        $accessGranted = can_access_case($c) && $myPresenceStatus === 'ADMITTED';
        $accepted = isset($acceptedCases[(int)$c['case_id']]);
        $isLocked = !$accessGranted;
        $lockedClass = $isLocked ? 'case-locked' : '';
        $isResolved = in_array($c['status'], ['CLOSED', 'RESOLVED']);
        if ($isResolved) {
            $lockedClass .= ' case-resolved-row';
        }
        
        if ($c['hearing_is_open'] == 1) {
            $stClass = 'badge-success';
            $stLabel = 'Hearing Live';
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
    ?>
    <tr class="<?php echo $lockedClass; ?>" onclick="handleRowClick('<?php echo htmlspecialchars($href); ?>', <?php echo $accepted ? 'true' : 'false'; ?>, <?php echo (int)$c['case_id']; ?>, <?php echo (int)($c['hearing_is_open'] ?? 0); ?>, <?php echo (int)($c['hearing_is_paused'] ?? 0); ?>, '<?php echo htmlspecialchars($myPresenceStatus); ?>')">
      <td><span class="t-id"><?php echo htmlspecialchars($cid); ?></span></td>
      <td>
        <?php if ($isResolved): ?>
          <span class="t-name" style="opacity:0.3; font-style:italic;">[ Respondent Hidden ]</span>
        <?php else: ?>
          <span class="t-name"><?php echo htmlspecialchars($c['student_name']); ?></span>
          <span class="t-sub">ID: <?php echo htmlspecialchars($c['student_id']); ?></span>
        <?php endif; ?>
      </td>
      <td>
        <?php if (!$accessGranted): ?>
          <button onclick="event.stopPropagation(); triggerRejoin(<?php echo (int)$c['case_id']; ?>)" class="badge badge-warning action-btn" style="font-size:10px; cursor:pointer; pointer-events:auto; display:inline-flex; align-items:center; gap:4px; padding:4px 10px; background:rgba(245, 158, 11, 0.15); color:#fcd34d; border:1px solid rgba(245, 158, 11, 0.3);">
            🔐 LOCKED · CLICK TO REJOIN
          </button>
        <?php elseif (!$accepted || $isLocked): ?>
          <span style="opacity:0.5; font-style:italic;">📩 [ Confidential Data Hidden ]</span>
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
          $hasMajor = !empty($majorOffenses);
          $badgeClass = $hasMajor ? 'major' : 'minor';
          ?>
          
          <div class="offense-badge <?php echo $badgeClass; ?>">
            <?php if ($hasMajor): ?>
              <span>⚠️ SECTION <?php echo $maxLevel; ?></span>
            <?php else: ?>
              <span>📋 MINOR</span>
            <?php endif; ?>
            
            <div class="offense-tooltip">
              <div class="offense-tooltip-title">
                <?php echo $hasMajor ? '⚠️ SECTION '.($maxLevel).' OFFENSES' : '📋 MINOR OFFENSES'; ?>
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
            <?php if ($c['hearing_is_paused'] == 1): ?>
              <span class="badge badge-warning" style="font-size:11px;">⏸️ Paused</span>
            <?php else: ?>
              <span class="badge badge-success" style="font-size:11px;">📬 Open</span>
            <?php endif; ?>
          <?php else: ?>
            <span class="badge badge-muted" style="font-size:11px;">✉️ Locked until Admin opens</span>
          <?php endif; ?>
          <?php if($c['hearing_type'] === 'ONLINE'): ?>
            <span class="badge-online" style="margin-left:6px;">Online</span>
          <?php endif; ?>
        <?php else: ?>
          <span class="t-sub">—</span>
        <?php endif; ?>
      </td>
      <td style="position: relative;">
        <?php if ($isResolved): ?>
          <button onclick="event.stopPropagation(); dismissResolvedCase(<?php echo (int)$c['case_id']; ?>)" title="Dismiss case" style="position: absolute; right: 16px; top: 50%; transform: translateY(-50%); background: rgba(239, 68, 68, 0.2); border: 1px solid rgba(239, 68, 68, 0.5); color: #fca5a5; width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; font-size: 20px; font-weight: bold; cursor: pointer; z-index: 20; transition: all 0.2s; pointer-events: auto;">&times;</button>
        <?php endif; ?>
        <?php if ($accepted): ?>
          <?php if ($c['hearing_is_open'] == 1 && $accessGranted): ?>
            <button class="action-btn" style="background:#10b981; pointer-events:auto;" onclick="event.stopPropagation(); window.location.href='<?php echo htmlspecialchars($href); ?>'">▶️ JOIN HEARING</button>
          <?php else: ?>
            <span class="badge <?php echo $stClass; ?>"><?php echo htmlspecialchars($stLabel); ?></span>
          <?php endif; ?>
        <?php else: ?>
          <button class="action-btn" onclick="event.stopPropagation(); triggerAcknowledge(<?php echo (int)$c['case_id']; ?>);">Unlock Case Access</button>
        <?php endif; ?>
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
    }
    header('Location: upccdashboard.php');
    exit;
}

$panelParams  = [':join_uid' => $panelId, ':legacy_uid' => $panelId];
$totalCases   = (int)(db_one("SELECT COUNT(DISTINCT uc.case_id) AS c FROM upcc_case uc WHERE $panelAssignmentMatch", $panelParams)['c'] ?? 0);
$pendingCases = (int)(db_one("SELECT COUNT(DISTINCT uc.case_id) AS c FROM upcc_case uc WHERE ($panelAssignmentMatch) AND uc.status IN ('PENDING','UNDER_INVESTIGATION')", $panelParams)['c'] ?? 0);
$resolvedCases= (int)(db_one("SELECT COUNT(DISTINCT uc.case_id) AS c FROM upcc_case uc WHERE ($panelAssignmentMatch) AND uc.status IN ('CLOSED','RESOLVED')", $panelParams)['c'] ?? 0);
$appealCases  = (int)(db_one("SELECT COUNT(DISTINCT uc.case_id) AS c FROM upcc_case uc WHERE ($panelAssignmentMatch) AND uc.status = 'UNDER_APPEAL'", $panelParams)['c'] ?? 0);

$recentCases = db_all("SELECT uc.case_id, uc.status, uc.created_at,
       uc.hearing_date, uc.hearing_time, uc.hearing_type, uc.hearing_is_open, uc.hearing_is_paused,
       COALESCE((SELECT p.status FROM upcc_hearing_presence p
           WHERE p.case_id = uc.case_id AND p.user_type = 'UPCC' AND p.user_id = :presence_uid
           LIMIT 1), 'ADMITTED') AS my_presence_status,
       uc.hearing_vote_consensus_category, uc.case_kind, uc.case_summary,
       CONCAT(s.student_fn,' ',s.student_ln) AS student_name,
       s.student_id,
       GROUP_CONCAT(ot.name ORDER BY ot.offense_type_id SEPARATOR ', ') AS offense_names,
       MAX(ot.level) AS offense_level,
       GROUP_CONCAT(CONCAT(ot.level, ':', ot.name) ORDER BY ot.level DESC SEPARATOR '||') AS offense_details,
       (SELECT COUNT(*) FROM upcc_case_vote v WHERE v.case_id = uc.case_id AND v.upcc_id = :vote_uid) AS user_has_voted,
       (SELECT round_no FROM upcc_case_vote_round WHERE case_id = uc.case_id AND is_active = 1 LIMIT 1) AS active_round
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
", array_merge([':presence_uid' => $panelId, ':vote_uid' => $panelId, ':join_uid' => $panelId, ':legacy_uid' => $panelId]));

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
<title>UPCC Panel — Dashboard</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
/* 🔥 PREMIUM GLASSMORPHIC DESIGN TOKENS 🔥 */
:root {
  --font-heading: 'Outfit', sans-serif;
  --font-body: 'Inter', sans-serif;
  
  --bg-dark: #0a0a0f;
  --bg-glass: rgba(18, 18, 25, 0.65);
  --bg-card: rgba(255, 255, 255, 0.03);
  --border-glass: rgba(255, 255, 255, 0.08);
  --border-glass-hover: rgba(255, 255, 255, 0.15);
  
  --accent-primary: #6366f1;
  --accent-glow: rgba(99, 102, 241, 0.4);
  --success: #10b981;
  --warning: #f59e0b;
  --danger: #ef4444;
  --text-main: #f8fafc;
  --text-muted: #94a3b8;
  
  --radius-lg: 24px;
  --radius-md: 16px;
  --radius-sm: 10px;
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
}

/* Dynamic Animated Background */
body::before {
  content: '';
  position: fixed; inset: 0; z-index: -2;
  background: radial-gradient(circle at 15% 50%, rgba(99, 102, 241, 0.15), transparent 40%),
              radial-gradient(circle at 85% 30%, rgba(139, 92, 246, 0.15), transparent 40%),
              radial-gradient(circle at 50% 80%, rgba(16, 185, 129, 0.05), transparent 40%);
  filter: blur(60px);
  animation: bgPulsate 15s ease-in-out infinite alternate;
}
@keyframes bgPulsate { 0% { opacity: 0.7; transform: scale(1); } 100% { opacity: 1; transform: scale(1.05); } }

/* Layout */
.app-container {
  display: grid;
  grid-template-columns: 280px 1fr;
  min-height: 100vh;
}

/* Sidebar Glass */
.sidebar {
  background: var(--bg-glass);
  backdrop-filter: blur(20px);
  -webkit-backdrop-filter: blur(20px);
  border-right: 1px solid var(--border-glass);
  padding: 30px 20px;
  display: flex;
  flex-direction: column;
  box-shadow: 10px 0 30px rgba(0,0,0,0.2);
}

.brand {
  display: flex; align-items: center; gap: 15px;
  margin-bottom: 40px; padding-bottom: 20px;
  border-bottom: 1px solid var(--border-glass);
}
.brand-icon {
  width: 50px; height: 50px;
  background: linear-gradient(135deg, rgba(255,255,255,0.1), rgba(255,255,255,0));
  border: 1px solid var(--border-glass);
  border-radius: 14px;
  display: grid; place-items: center;
  box-shadow: 0 8px 16px rgba(0,0,0,0.2);
  padding: 8px;
}
.brand-icon img { width: 100%; height: auto; display: block; border-radius: 6px; }
.brand-text h1 { font-family: var(--font-heading); font-size: 20px; font-weight: 700; letter-spacing: 0.5px; margin-bottom: 0; line-height:1;}
.brand-text p { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; margin-top:2px;}

.nav-link {
  display: flex; align-items: center; gap: 12px;
  padding: 14px 18px; margin-bottom: 10px;
  border-radius: var(--radius-sm);
  color: var(--text-main); text-decoration: none;
  font-weight: 500; font-size: 14px;
  background: transparent; border: 1px solid transparent;
  transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}
.nav-link:hover {
  background: rgba(255,255,255,0.05);
  border-color: var(--border-glass);
  transform: translateX(4px);
}
.nav-link.active {
  background: linear-gradient(90deg, rgba(99,102,241,0.2), transparent);
  border-left: 3px solid var(--accent-primary);
  color: #fff;
}
.nav-link svg { width: 20px; height: 20px; opacity: 0.8; }

/* Main Content */
.main-content {
  padding: 40px;
  overflow-y: auto;
}
.header {
  display: flex; justify-content: space-between; align-items: center;
  margin-bottom: 30px;
}
.greeting h2 {
  font-family: var(--font-heading); font-size: 32px; font-weight: 800;
  background: linear-gradient(to right, #fff, #94a3b8);
  -webkit-background-clip: text; -webkit-text-fill-color: transparent;
}
.greeting p { color: var(--text-muted); margin-top: 5px; font-size: 15px; }

/* Stats Row */
.stats-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 20px;
  margin-bottom: 35px;
}
.stat-card {
  position: relative; overflow: hidden;
  background: var(--bg-card);
  backdrop-filter: blur(12px);
  border: 1px solid var(--border-glass);
  border-radius: var(--radius-lg);
  padding: 24px;
  transition: transform 0.3s ease, box-shadow 0.3s ease;
}
.stat-card:hover { transform: translateY(-5px); box-shadow: 0 15px 35px rgba(0,0,0,0.25); border-color: var(--border-glass-hover); }
.stat-icon {
  position: absolute; top: -10px; right: -10px;
  font-size: 100px; opacity: 0.05; transition: transform 0.5s ease;
}
.stat-card:hover .stat-icon { transform: scale(1.1) rotate(-5deg); }
.stat-title { font-size: 13px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 1.5px; font-weight: 600; margin-bottom: 10px; }
.stat-value { font-family: var(--font-heading); font-size: 42px; font-weight: 800; line-height: 1; margin-bottom: 8px; }
.stat-desc { font-size: 13px; color: var(--text-muted); }

/* Accent lines for stats */
.st-total::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background: linear-gradient(90deg, #6366f1, #8b5cf6); }
.st-active::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background: linear-gradient(90deg, #f59e0b, #ed8936); }
.st-resolved::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background: linear-gradient(90deg, #10b981, #059669); }
.st-appeal::before { content:''; position:absolute; top:0; left:0; right:0; height:4px; background: linear-gradient(90deg, #ef4444, #dc2626); }

/* Main Panel layout */
.dashboard-layout {
  display: grid; grid-template-columns: 1fr 340px; gap: 25px;
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
  padding: 24px; border-bottom: 1px solid var(--border-glass);
  display: flex; align-items: center; justify-content: space-between;
}
.panel-title {
  display: flex; align-items: center; gap: 12px;
  font-family: var(--font-heading); font-size: 18px; font-weight: 700;
}
.panel-title svg { stroke: var(--accent-primary); width: 22px; height: 22px; }

/* Modern Table */
.table-wrapper { overflow-x: auto; padding: 0 24px 24px; }
.table { width: 100%; border-collapse: separate; border-spacing: 0 8px; }
.table th {
  text-align: left; padding: 12px 16px;
  font-size: 11px; text-transform: uppercase; letter-spacing: 1.5px;
  color: var(--text-muted); font-weight: 600; border-bottom: 1px solid var(--border-glass);
}
.table td {
  padding: 16px; background: rgba(255,255,255,0.02);
  font-size: 13.5px; border-top: 1px solid transparent; border-bottom: 1px solid transparent;
}
.table td:first-child { border-left: 1px solid transparent; border-radius: 12px 0 0 12px; }
.table td:last-child { border-right: 1px solid transparent; border-radius: 0 12px 12px 0; }
.table tr { transition: all 0.2s; }
.table tr:not(.case-locked):hover td {
  background: rgba(255,255,255,0.05);
  border-color: var(--border-glass-hover);
  cursor: pointer;
}
.table tr.section4-row:hover td {
  background: transparent;
  border-color: transparent;
  cursor: default;
}
.table tr.case-locked {
  opacity: 0.6;
  pointer-events: none;
}
.table tr.case-locked:hover td {
  background: rgba(255,255,255,0.02);
  cursor: not-allowed;
}

.table tr.case-resolved-row {
  position: relative;
  pointer-events: none;
}
.table tr.case-resolved-row td {
  opacity: 0.25;
  filter: grayscale(100%);
}
.table tr.case-resolved-row::after {
  content: 'RESOLVED';
  position: absolute;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  font-size: 28px;
  font-weight: 900;
  color: #10b981;
  letter-spacing: 12px;
  z-index: 10;
  text-shadow: 0 0 10px rgba(16,185,129,0.5);
  background: rgba(10,10,15,0.7);
  padding: 10px 30px;
  border-radius: 12px;
  border: 2px solid rgba(16,185,129,0.3);
  backdrop-filter: blur(4px);
  pointer-events: none;
}

.table tr.case-status-updated {
  animation: statusPulse 1.5s ease-out;
}

@keyframes statusPulse {
  0% { background: rgba(99, 102, 241, 0.3); }
  100% { background: transparent; }
}

/* Table Specific formatting */
.t-id { font-family: monospace; font-weight: 600; color: #a5b4fc; }
.t-name { font-weight: 600; color: #fff; display: block; margin-bottom: 2px; }
.t-sub { font-size: 12px; color: var(--text-muted); }

/* Status Badges */
.badge {
  display: inline-flex; align-items: center; padding: 5px 12px;
  border-radius: 99px; font-size: 11px; font-weight: 700;
  text-transform: uppercase; letter-spacing: 1px;
  border: 1px solid transparent;
}
.badge-pending { background: rgba(245, 158, 11, 0.1); color: #fcd34d; border-color: rgba(245, 158, 11, 0.2); }
.badge-resolved { background: rgba(16, 185, 129, 0.1); color: #6ee7b7; border-color: rgba(16, 185, 129, 0.2); }
.badge-appeal { background: rgba(239, 68, 68, 0.1); color: #fca5a5; border-color: rgba(239, 68, 68, 0.2); }
.badge-success { background: rgba(16, 185, 129, 0.1); color: #6ee7b7; border-color: rgba(16, 185, 129, 0.2); }
.badge-muted { background: rgba(148, 163, 184, 0.1); color: #cbd5e1; border-color: rgba(148, 163, 184, 0.2); }
.badge-warning { background: rgba(245, 158, 11, 0.1); color: #fcd34d; border-color: rgba(245, 158, 11, 0.2); }
.badge-online { background: rgba(99, 102, 241, 0.15); border-color: rgba(99, 102, 241, 0.3); color: #c7d2fe; padding: 3px 8px; font-size:10px; border-radius: 6px;}

.action-btn {
  background: linear-gradient(135deg, var(--accent-primary), #8b5cf6);
  color: white; border: none; padding: 8px 16px; border-radius: 8px;
  font-weight: 600; font-size: 12px; cursor: pointer; transition: all 0.2s; text-decoration:none;
  box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3); display: inline-block;
}
.action-btn:hover { transform: translateY(-2px); box-shadow: 0 6px 20px rgba(99, 102, 241, 0.4); }

/* Confid Gate */
.conf-gate { text-align: center; padding: 30px 20px; }
.conf-gate-icon { font-size: 40px; margin-bottom: 15px; opacity: 0.5; }

/* Profile Card */
.profile-wrap { padding: 24px; text-align: center; }
.avatar {
  width: 90px; height: 90px;
  background: linear-gradient(135deg, rgba(255,255,255,0.1), rgba(255,255,255,0.02));
  border: 1px solid var(--border-glass-hover);
  border-radius: 50%; margin: 0 auto 20px;
  display: grid; place-items: center;
  font-size: 32px; font-weight: 800; font-family: var(--font-heading); color: #fff;
  box-shadow: 0 10px 25px rgba(0,0,0,0.3), inset 0 2px 5px rgba(255,255,255,0.1);
}
.profile-name { font-family: var(--font-heading); font-size: 20px; font-weight: 700; margin-bottom: 4px; }
.profile-role { font-size: 13px; color: var(--accent-primary); font-weight: 600; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 25px; }

.profile-meta { text-align: left; border-top: 1px solid var(--border-glass); padding-top: 20px; }
.meta-row { display: flex; justify-content: space-between; margin-bottom: 12px; font-size: 13px; }
.meta-row .label { color: var(--text-muted); }
.meta-row .value { font-weight: 500; }
.meta-row .value.ok { color: var(--success); }

.quick-links { margin-top: 20px; display: grid; gap: 10px; }
.q-link {
  display: flex; align-items: center; justify-content: space-between;
  padding: 14px 18px; border-radius: 12px;
  background: rgba(255,255,255,0.03); border: 1px solid var(--border-glass);
  color: var(--text-main); text-decoration: none; font-size: 13px; font-weight: 600;
  transition: all 0.2s;
}
.q-link:hover { background: rgba(255,255,255,0.08); transform: translateX(4px); border-color: var(--border-glass-hover); }
.q-link svg { width: 18px; height: 18px; opacity: 0.7; }
.q-link.danger:hover { background: rgba(239,68,68,0.1); border-color: rgba(239,68,68,0.3); color: #fca5a5; }
.q-link.danger:hover svg { stroke: #fca5a5; opacity: 1; }

/* Offense Tooltip */
.offense-badge {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 10px 14px; border-radius: 8px;
  color: #e0e7ff; font-weight: 700; font-size: 13px;
  position: relative; cursor: help;
  transition: all 0.2s;
}

.offense-badge.major {
  background: rgba(239, 68, 68, 0.2); 
  border: 2px solid rgba(239, 68, 68, 0.6);
  color: #fca5a5;
}
.offense-badge.major:hover {
  background: rgba(239, 68, 68, 0.3);
  border-color: #ef4444;
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(239, 68, 68, 0.3);
}

.offense-badge.minor {
  background: rgba(99, 102, 241, 0.2); 
  border: 2px solid rgba(99, 102, 241, 0.5);
  color: #e0e7ff;
}
.offense-badge.minor:hover {
  background: rgba(99, 102, 241, 0.3);
  border-color: #6366f1;
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(99, 102, 241, 0.3);
}

.offense-tooltip {
  position: absolute; top: 100%; left: 50%; transform: translateX(-50%) translateY(12px);
  background: rgba(15, 23, 42, 0.95); backdrop-filter: blur(20px);
  border-radius: 12px; padding: 16px; min-width: 320px;
  box-shadow: 0 20px 60px rgba(0,0,0,0.8), inset 0 1px 0 rgba(255,255,255,0.1);
  opacity: 0; visibility: hidden; z-index: 9999;
  transition: opacity 0.3s ease, visibility 0.3s ease, transform 0.3s ease;
  pointer-events: none;
}

.offense-badge.major .offense-tooltip {
  border: 2px solid rgba(239, 68, 68, 0.6);
}

.offense-badge.minor .offense-tooltip {
  border: 2px solid rgba(99, 102, 241, 0.6);
}

.offense-badge:hover .offense-tooltip {
  opacity: 1; visibility: visible; pointer-events: auto;
  transform: translateX(-50%) translateY(8px);
}
}

.offense-tooltip-title {
  font-size: 13px; color: #e0e7ff; text-transform: uppercase; letter-spacing: 2px;
  font-weight: 800; margin-bottom: 14px; padding-bottom: 10px;
  border-bottom: 2px solid rgba(99, 102, 241, 0.4);
  text-align: center;
}

.offense-item {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 0; font-size: 13px; color: #f8fafc;
  border-bottom: 1px solid rgba(99, 102, 241, 0.2);
}
.offense-item:last-child { border-bottom: none; }

.offense-level-badge {
  display: inline-flex; align-items: center; justify-content: center;
  width: 32px; height: 32px; border-radius: 6px;
  font-weight: 800; font-size: 11px;
  min-width: 32px;
  text-transform: uppercase;
  letter-spacing: 1px;
  flex-shrink: 0;
}

.offense-level-4 { background: rgba(239, 68, 68, 0.25); color: #fca5a5; border: 2px solid rgba(239, 68, 68, 0.5); }
.offense-level-3 { background: rgba(245, 158, 11, 0.25); color: #fbbf24; border: 2px solid rgba(245, 158, 11, 0.5); }
.offense-level-2 { background: rgba(59, 130, 246, 0.25); color: #bfdbfe; border: 2px solid rgba(59, 130, 246, 0.5); }
.offense-level-1 { background: rgba(16, 185, 129, 0.25); color: #86efac; border: 2px solid rgba(16, 185, 129, 0.5); }

.offense-name { color: #f1f5f9; font-weight: 600; flex: 1; line-height: 1.4; text-align: center; }

@media (max-width: 1024px) {
  .app-container { grid-template-columns: 1fr; }
  .sidebar { display: none; }
  .dashboard-layout { grid-template-columns: 1fr; }
}

/* Modal for Acknowledgement */
.modal-overlay {
  position: fixed; inset: 0; background: rgba(0,0,0,0.8); backdrop-filter: blur(15px);
  z-index: 1000; display: none; place-items: center; padding: 20px;
}
.modal-overlay.show { display: grid; }
.modal-content {
  background: var(--bg-dark);
  border: 1px solid var(--border-glass-hover);
  border-radius: var(--radius-lg);
  padding: 40px; max-width: 500px; text-align: center;
  box-shadow: 0 25px 60px rgba(0,0,0,0.5), inset 0 1px 0 rgba(255,255,255,0.1);
  position: relative; overflow: hidden;
}
.modal-content::before {
  content: ''; position: absolute; top:0; left:0; right:0; height: 4px;
  background: linear-gradient(90deg, var(--warning), var(--accent-primary));
}
.modal-icon { font-size: 48px; margin-bottom: 20px; }
.modal-title { font-family: var(--font-heading); font-size: 24px; font-weight: 800; margin-bottom: 12px; }
.modal-desc { color: var(--text-muted); font-size: 14px; margin-bottom: 30px; line-height: 1.6; }
.modal-actions { display: flex; gap: 15px; justify-content: center; }

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
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path></svg>
        Dashboard
      </a>
      <!-- Future expansions -->
    </nav>
  </aside>

  <!-- Main View -->
  <main class="main-content">
    <header class="header">
      <div class="greeting">
        <h2><?php echo $greeting; ?>, <?php echo $firstName; ?>!</h2>
        <p>Here's an overview of your assigned UPCC cases.</p>
      </div>
      <div class="watermark" style="font-family:monospace; font-size:11px; color:rgba(255,255,255,0.2); padding: 8px 16px; border:1px solid rgba(255,255,255,0.05); border-radius:8px;">
        <?php echo date('Y-m-d H:i:s'); ?> SECURE
      </div>
    </header>

    <?php if (isset($_GET['hearing_msg'])): ?>
      <div style="background: rgba(16, 185, 129, 0.1); border: 1px solid rgba(16, 185, 129, 0.3); color: #6ee7b7; padding: 16px; border-radius: 12px; margin-bottom: 25px; display:flex; align-items:center; gap:10px; font-weight:500;">
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
        <?php echo htmlspecialchars((string)$_GET['hearing_msg']); ?>
      </div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="stats-grid">
      <div class="stat-card st-total">
        <div class="stat-icon">📁</div>
        <div class="stat-title">Total Assigned</div>
        <div class="stat-value"><?php echo $totalCases; ?></div>
        <div class="stat-desc">Cases on record</div>
      </div>
      <div class="stat-card st-active">
        <div class="stat-icon">⚡</div>
        <div class="stat-title">Active / Pending</div>
        <div class="stat-value"><?php echo $pendingCases; ?></div>
        <div class="stat-desc">Awaiting resolution</div>
      </div>
      <div class="stat-card st-resolved">
        <div class="stat-icon">✓</div>
        <div class="stat-title">Resolved</div>
        <div class="stat-value"><?php echo $resolvedCases; ?></div>
        <div class="stat-desc">Closed &amp; completed</div>
      </div>
    </div>

    <div class="dashboard-layout">
      <!-- Cases List -->
      <div class="glass-panel">
        <div class="panel-header">
          <div class="panel-title">
            <svg viewBox="0 0 24 24" fill="none"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 002-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            Assigned Case Queue
          </div>
        </div>
        
        <?php if (empty($recentCases)): ?>
          <div style="padding: 60px 20px; text-align: center; color: var(--text-muted);">
            <div style="font-size: 48px; opacity: 0.5; margin-bottom: 15px;">✨</div>
            <p>You have no assigned cases at the moment.</p>
          </div>
        <?php else: ?>
          <div class="table-wrapper">
            <table class="table" style="margin-top:20px;">
              <thead>
                <tr>
                  <th>Case ID</th>
                  <th>Respondent</th>
                  <th>Offenses</th>
                  <th>Hearing</th>
                  <th>Status</th>
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
                  $isResolved = in_array($c['status'], ['CLOSED', 'RESOLVED']);
                  if ($isResolved) {
                      $lockedClass .= ' case-resolved-row';
                  }
                  
                  if ($c['hearing_is_open'] == 1) {
                    $stClass = 'badge-success';
                    $stLabel = 'Hearing Live';
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
                ?>
                <tr class="<?php echo $lockedClass . $section4Class; ?>" onclick="handleRowClick('<?php echo htmlspecialchars($href); ?>', <?php echo $accepted ? 'true' : 'false'; ?>, <?php echo (int)$c['case_id']; ?>, <?php echo (int)($c['hearing_is_open'] ?? 0); ?>, <?php echo (int)($c['hearing_is_paused'] ?? 0); ?>, '<?php echo htmlspecialchars($myPresenceStatus); ?>')">
                  <td><span class="t-id"><?php echo htmlspecialchars($cid); ?></span></td>
                  <td>
                    <?php if ($isResolved): ?>
                    <span class="t-name" style="opacity:0.3; font-style:italic;">[ Respondent Hidden ]</span>
                  <?php else: ?>
                    <span class="t-name"><?php echo htmlspecialchars($c['student_name']); ?></span>
                    <span class="t-sub">ID: <?php echo htmlspecialchars($c['student_id']); ?></span>
                  <?php endif; ?>
                  </td>
                  <td>
                    <?php if (!$accessGranted): ?>
                      <button onclick="event.stopPropagation(); triggerRejoin(<?php echo (int)$c['case_id']; ?>)" class="badge badge-warning action-btn" style="font-size:10px; cursor:pointer; pointer-events:auto; display:inline-flex; align-items:center; gap:4px; padding:4px 10px; background:rgba(245, 158, 11, 0.15); color:#fcd34d; border:1px solid rgba(245, 158, 11, 0.3);">
                        🔐 LOCKED · CLICK TO REJOIN
                      </button>
                    <?php elseif (!$accepted || $isLocked): ?>
                      <span style="opacity:0.5; font-style:italic;">📩 [ Confidential Data Hidden ]</span>
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
                          <span>⚠️ SECTION <?php echo $maxLevel; ?></span>
                        <?php else: ?>
                          <span>📋 MINOR</span>
                        <?php endif; ?>
                        
                      </div>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php if ($c['hearing_is_open'] == 1 || !empty($c['hearing_date'])): ?>
                      <span class="t-name" style="font-size:12px; display:block; margin-bottom:4px;"><?php echo $hearingDate; ?></span>
                      <?php if ($c['hearing_is_open'] == 1): ?>
                        <?php if ($c['hearing_is_paused'] == 1): ?>
                          <span class="badge badge-warning" style="font-size:11px;">⏸️ Paused</span>
                        <?php else: ?>
                          <span class="badge badge-success" style="font-size:11px;">📬 Open</span>
                        <?php endif; ?>
                      <?php else: ?>
                        <span class="badge badge-muted" style="font-size:11px;">✉️ Locked until Admin opens</span>
                      <?php endif; ?>
                      <?php if($c['hearing_type'] === 'ONLINE'): ?>
                        <span class="badge-online" style="margin-left:6px;">Online</span>
                      <?php endif; ?>
                    <?php else: ?>
                      <span class="t-sub">—</span>
                    <?php endif; ?>
                  </td>
                  <td style="position: relative;">
                    <?php if ($isResolved): ?>
                      <button onclick="event.stopPropagation(); dismissResolvedCase(<?php echo (int)$c['case_id']; ?>)" title="Dismiss case" style="position: absolute; right: 16px; top: 50%; transform: translateY(-50%); background: rgba(239, 68, 68, 0.2); border: 1px solid rgba(239, 68, 68, 0.5); color: #fca5a5; width: 32px; height: 32px; border-radius: 50%; display: grid; place-items: center; font-size: 20px; font-weight: bold; cursor: pointer; z-index: 20; transition: all 0.2s; pointer-events: auto;">&times;</button>
                    <?php endif; ?>
                    <?php if ($accepted): ?>
                      <?php if ($c['hearing_is_open'] == 1 && $accessGranted): ?>
                        <button class="action-btn" style="background:#10b981; pointer-events:auto;" onclick="event.stopPropagation(); window.location.href='<?php echo htmlspecialchars($href); ?>'">▶️ JOIN HEARING</button>
                      <?php else: ?>
                        <span class="badge <?php echo $stClass; ?>"><?php echo htmlspecialchars($stLabel); ?></span>
                      <?php endif; ?>
                    <?php else: ?>
                      <button class="action-btn" onclick="event.stopPropagation(); triggerAcknowledge(<?php echo (int)$c['case_id']; ?>);">Unlock Case Access</button>
                    <?php endif; ?>
                  </td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>

      <!-- Right Column -->
      <div style="display: flex; flex-direction: column; gap: 25px;">
        <!-- Profile Card -->
        <div class="glass-panel profile-wrap">
          <div class="avatar"><?php echo $initials; ?></div>
          <div class="profile-name"><?php echo htmlspecialchars($user['full_name']); ?></div>
          <div class="profile-role">UPCC Panel Member</div>
          
          <div class="profile-meta">
            <div class="meta-row"><span class="label">Username</span><span class="value"><?php echo htmlspecialchars($user['username']); ?></span></div>
            <div class="meta-row"><span class="label">Status</span><span class="value ok">Online</span></div>
            <div class="meta-row"><span class="label">Access Level</span><span class="value">Secure Workspace</span></div>
          </div>
        </div>

        <!-- Quick Actions -->
        <div class="glass-panel">
          <div class="panel-header" style="padding: 20px;">
            <div class="panel-title" style="font-size:15px;">Quick Actions</div>
          </div>
          <div style="padding: 20px;">
            <div class="quick-links">
              <a href="upcc_change_password.php" class="q-link">
                <div style="display:flex; align-items:center; gap:10px;">
                  <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path></svg>
                  Security Settings
                </div>
                <span>→</span>
              </a>
              <a href="upccpanel.php?action=logout" class="q-link danger">
                <div style="display:flex; align-items:center; gap:10px;">
                  <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>
                  Terminate Session
                </div>
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>
</div>

<!-- Modal Acknowledge -->
<div id="ackModal" class="modal-overlay">
  <div class="modal-content">
    <div class="modal-icon">🔒</div>
    <div class="modal-title">Protected Record Access</div>
    <div class="modal-desc" style="color:var(--text-muted); font-size:14px; line-height:1.6; margin-bottom:30px;">
      You are about to access highly sensitive student disciplinary records. By proceeding, you agree to maintain strict confidentiality and acknowledge that unauthorized screenshotting, sharing, or distribution is strictly prohibited under institutional policy.
    </div>
    <form method="post" action="upccdashboard.php">
      <input type="hidden" name="action" value="accept_confidentiality">
      <input type="hidden" name="case_id" id="ackCaseId" value="">
      <div class="modal-actions" style="display:flex; gap:15px; justify-content:center;">
        <button type="button" class="action-btn" style="background:var(--bg-glass); border:1px solid var(--border-glass);" onclick="closeAckModal()">Cancel</button>
        <button type="submit" class="action-btn" style="background:var(--success);">I Agree &amp; Acknowledge</button>
      </div>
    </form>
  </div>
</div>

<!-- Modal Rejoin Request -->
<div id="rejoinModal" class="modal-overlay">
  <div class="modal-content">
    <div class="modal-icon">🚪</div>
    <div class="modal-title">Request Rejoin</div>
    <div class="modal-desc" style="color:var(--text-muted); font-size:14px; line-height:1.6; margin-bottom:30px;">
      You have exited this hearing or your connection timed out. You need the administrator's permission to rejoin the live hearing. Would you like to send a rejoin request now?
    </div>
    <input type="hidden" id="rejoinCaseId" value="">
    <div class="modal-actions" style="display:flex; gap:15px; justify-content:center;">
      <button type="button" class="action-btn" style="background:var(--bg-glass); border:1px solid var(--border-glass);" onclick="closeRejoinModal()">Cancel</button>
      <button type="button" id="btnSendRejoin" class="action-btn" style="background:var(--accent-primary);" onclick="sendRejoinRequest()">Send Rejoin Request</button>
    </div>
  </div>
</div>

<script>
function handleRowClick(href, accepted, caseId, hearingIsOpen, hearingIsPaused, myPresenceStatus) {
  // Check if case is actually open
  if (hearingIsOpen === 0) {
    alert('⚠️ This case is currently locked by the administrator. You cannot access it until it is opened.');
    return;
  }
  
  if (hearingIsPaused === 1) {
    alert('⚠️ This case hearing has been paused. You cannot access it until the administrator resumes it.');
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
  document.getElementById('ackCaseId').value = caseId;
  document.getElementById('ackModal').classList.add('show');
}

function closeAckModal() {
  document.getElementById('ackModal').classList.remove('show');
}

function triggerRejoin(caseId) {
  document.getElementById('rejoinCaseId').value = caseId;
  document.getElementById('rejoinModal').classList.add('show');
}

function closeRejoinModal() {
  document.getElementById('rejoinModal').classList.remove('show');
}

function sendRejoinRequest() {
  const caseId = document.getElementById('rejoinCaseId').value;
  if (!caseId) return;
  
  const fd = new FormData();
  fd.append('actor', 'upcc');
  fd.append('case_id', caseId);
  
  const btn = document.getElementById('btnSendRejoin');
  btn.textContent = 'Sending...';
  btn.disabled = true;

  fetch('../api/upcc_case_live.php?action=request_rejoin', { method: 'POST', body: fd })
    .then(r => r.json())
    .then(res => {
        if (res.ok) {
            alert('✅ ' + (res.message || 'Request sent! Please wait for the admin to admit you.'));
            closeRejoinModal();
        } else {
            alert('⚠️ ' + (res.message || res.error || 'Failed to send request.'));
        }
    })
    .catch(err => {
        console.error(err);
        alert('⚠️ An error occurred while sending the request.');
    })
    .finally(() => {
        btn.textContent = 'Send Rejoin Request';
        btn.disabled = false;
    });
}

// ─── LIVE POLLING FOR CASE STATUS UPDATES ──────
function refreshCaseStatus() {
  fetch('./upccdashboard.php?action=refresh_cases', { method: 'GET' })
    .then(r => r.text())
    .then(html => {
      // Parse the new case rows from response
      const parser = new DOMParser();
      const newDoc = parser.parseFromString(html, 'text/html');
      const newRows = newDoc.querySelectorAll('tbody tr');
      const currentRows = document.querySelectorAll('tbody tr');
      
      // Update table body
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

// Poll every 3 seconds for live updates
setInterval(refreshCaseStatus, 3000);

// Anti-screenshot basics
document.addEventListener('keyup', (e) => {
    if (e.key === 'PrintScreen') {
        document.body.style.display = 'none';
        alert('Security Alert: Screenshot key press detected. Screen locked.');
        setTimeout(() => document.body.style.display = '', 2000);
    }
});

function dismissResolvedCase(caseId) {
    if (confirm('Dismiss this resolved case from your queue?')) {
        fetch('upccdashboard.php?action=dismiss_case&case_id=' + caseId)
            .then(r => r.json())
            .then(res => {
                if (res.ok) {
                    refreshCaseStatus();
                }
            })
            .catch(err => console.error(err));
    }
}
</script>
</body>
</html>