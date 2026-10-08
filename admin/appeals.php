<?php
require_once __DIR__ . '/../database/database.php';
require_admin();
ensure_hearing_workflow_schema();

$activeSidebar = 'appeals';
$admin = admin_current();
$fullName = trim((string)($admin['full_name'] ?? ''));
if ($fullName === '') {
    $fullName = (string)($admin['username'] ?? 'User');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'review_appeal') {
    $appealId = (int)($_POST['appeal_id'] ?? 0);
    $decision = strtoupper(trim((string)($_POST['decision'] ?? '')));
    $notes = trim((string)($_POST['admin_response'] ?? ''));

    $appeal = db_one(
        "SELECT appeal_id, student_id, offense_id, case_id, appeal_kind, status
         FROM student_appeal_request
         WHERE appeal_id = :id
         LIMIT 1",
        [':id' => $appealId]
    );

    if ($appeal && in_array($decision, ['APPROVED', 'REJECTED'], true)) {
        db_exec(
            "UPDATE student_appeal_request
             SET status = :status,
                 admin_response = :response,
                 decided_by = :admin_id,
                 decided_at = NOW()
             WHERE appeal_id = :id",
            [
                ':status' => $decision,
                ':response' => $notes,
                ':admin_id' => (int)$admin['admin_id'],
                ':id' => $appealId,
            ]
        );

        $studentId = (string)$appeal['student_id'];
        $offenseId = (int)$appeal['offense_id'];
        $caseId = (int)$appeal['case_id'];

        if ($decision === 'APPROVED') {
            if ($appeal['appeal_kind'] === 'UPCC_CASE' && $caseId > 0) {
                db_exec("UPDATE upcc_case SET status = 'CANCELLED' WHERE case_id = :cid AND student_id = :sid", [':cid' => $caseId, ':sid' => $studentId]);
                db_exec("UPDATE community_service_requirement SET status = 'CANCELLED' WHERE related_case_id = :cid AND student_id = :sid", [':cid' => $caseId, ':sid' => $studentId]);
                db_exec("UPDATE offense SET status = 'VOID' WHERE offense_id IN (SELECT offense_id FROM upcc_case_offense WHERE case_id = :cid)", [':cid' => $caseId]);
            } else if ($appeal['appeal_kind'] === 'OFFENSE' && $offenseId > 0) {
                db_exec("UPDATE offense SET status = 'VOID' WHERE offense_id = :oid AND student_id = :sid", [':oid' => $offenseId, ':sid' => $studentId]);
            }

            // Approved -> send admin to sanctions.php so they can adjust the student's category / sanction
            redirect('sanctions.php?student_id=' . urlencode($studentId) . '&appeal_id=' . $appealId . '&msg=approved');
        }

        if ($decision === 'REJECTED') {
            if ($appeal['appeal_kind'] === 'UPCC_CASE' && $caseId > 0) {
                db_exec("UPDATE upcc_case SET status = 'RESOLVED' WHERE case_id = :cid AND student_id = :sid", [':cid' => $caseId, ':sid' => $studentId]);
                $caseRow = db_one("SELECT decided_category FROM upcc_case WHERE case_id = :cid", [':cid' => $caseId]);
                if ($caseRow && in_array((int)$caseRow['decided_category'], [4, 5], true)) {
                    db_exec("UPDATE student SET is_active = 0 WHERE student_id = :sid", [':sid' => $studentId]);
                }
                activate_or_merge_community_service_requirement($studentId, $caseId);
            } else if ($appeal['appeal_kind'] === 'OFFENSE' && $offenseId > 0) {
                db_exec("UPDATE offense SET status = 'RESOLVED' WHERE offense_id = :oid AND student_id = :sid", [':oid' => $offenseId, ':sid' => $studentId]);
            }

            // Rejected -> stay on appeals page, panel decision stands and continues for student
            redirect('appeals.php?msg=rejected');
        }
    }

    redirect('appeals.php?msg=error');
}

// Pending, Approved, and Rejected filters
$filter = strtoupper(trim((string)($_GET['filter'] ?? 'PENDING')));
if (!in_array($filter, ['PENDING', 'APPROVED', 'REJECTED'], true)) {
    $filter = 'PENDING';
}

$pendingCount  = (int)(db_one("SELECT COUNT(*) AS c FROM student_appeal_request WHERE status IN ('PENDING','REVIEWING')")['c'] ?? 0);
$approvedCount = (int)(db_one("SELECT COUNT(*) AS c FROM student_appeal_request WHERE status = 'APPROVED'")['c'] ?? 0);
$rejectedCount = (int)(db_one("SELECT COUNT(*) AS c FROM student_appeal_request WHERE status = 'REJECTED'")['c'] ?? 0);

$where = '';
$params = [];
if ($filter === 'PENDING') {
    $where = "WHERE sar.status IN ('PENDING','REVIEWING')";
} else {
    $where = 'WHERE sar.status = :status';
    $params[':status'] = $filter;
}

$appeals = db_all(
    "SELECT
        sar.appeal_id,
        sar.student_id,
        sar.offense_id,
        sar.case_id,
        sar.appeal_kind,
        sar.reason,
        sar.status,
        sar.admin_response,
        sar.attachment_path,
        sar.attachment_name,
        sar.created_at,
        sar.decided_at,
        CONCAT(s.student_fn, ' ', s.student_ln) AS student_name,
        ot.code AS offense_code,
        ot.name AS offense_name,
        uc.decided_category,
        uc.final_decision
     FROM student_appeal_request sar
     JOIN student s ON s.student_id = sar.student_id
     LEFT JOIN offense o ON o.offense_id = sar.offense_id
     LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
     LEFT JOIN upcc_case uc ON uc.case_id = sar.case_id
     $where
     ORDER BY sar.created_at DESC, sar.appeal_id DESC
     LIMIT 100",
    $params
);

function appeal_kind_label(string $kind): string
{
    return $kind === 'UPCC_CASE' ? 'UPCC Case' : 'Offense';
}

function appeal_status_class(string $status): string
{
    return match ($status) {
        'APPROVED' => 'status-approved',
        'REJECTED' => 'status-rejected',
        default    => 'status-pending',
    };
}

function fmt_dt(?string $value): string
{
    if (!$value) return '—';
    return date('M d, Y g:i A', strtotime($value));
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name));
    $out = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        if ($p !== '') $out .= strtoupper($p[0]);
    }
    return $out !== '' ? $out : '?';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Appeals Review | SDO Web Portal</title>
  <style>
    :root {
      --bg: #f4f6fb;
      --card: #ffffff;
      --ink: #101a3a;
      --muted: #6b7280;
      --line: #e6e9f2;
      --brand: #3b4a9e;
      --brand-2: #5b6fd1;
      --ok: #059669;
      --ok-bg: #ecfdf5;
      --warn: #b45309;
      --warn-bg: #fffbeb;
      --danger: #dc2626;
      --danger-bg: #fef2f2;
      --shadow: 0 1px 2px rgba(16, 26, 58, .04), 0 8px 24px -12px rgba(16, 26, 58, .12);
      --shadow-lg: 0 4px 8px rgba(16, 26, 58, .05), 0 24px 48px -20px rgba(16, 26, 58, .18);
      --radius: 16px;
    }

    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
      margin: 0;
      font-family: 'Segoe UI', -apple-system, BlinkMacSystemFont, 'Inter', Tahoma, Arial, sans-serif;
      background: var(--bg);
      color: var(--ink);
      -webkit-font-smoothing: antialiased;
    }

    .admin-shell {
      min-height: calc(100vh - 72px);
      display: grid;
      grid-template-columns: 240px 1fr;
    }
    .wrap { min-height: 100%; min-width: 0; }

    /* ---------- Header ---------- */
    .page-header {
      position: relative;
      overflow: hidden;
      background:
        radial-gradient(900px 320px at 100% -40%, rgba(91, 111, 209, .28), transparent 60%),
        radial-gradient(700px 300px at -10% 120%, rgba(59, 74, 158, .22), transparent 60%),
        linear-gradient(135deg, #101a3a 0%, #1e2a63 55%, #3b4a9e 100%);
      color: #fff;
      padding: 32px 36px;
      border-bottom: 1px solid rgba(255,255,255,.06);
    }
    .page-header::after {
      content: "";
      position: absolute; inset: 0;
      background-image:
        linear-gradient(rgba(255,255,255,.04) 1px, transparent 1px),
        linear-gradient(90deg, rgba(255,255,255,.04) 1px, transparent 1px);
      background-size: 34px 34px;
      mask-image: radial-gradient(700px 260px at 15% 30%, #000 30%, transparent 75%);
      pointer-events: none;
    }
    .page-header h1 {
      margin: 0;
      font-size: 30px;
      font-weight: 800;
      letter-spacing: -.015em;
      display: flex; align-items: center; gap: 12px;
    }
    .page-header h1 .h1-icon {
      width: 42px; height: 42px;
      display: inline-flex; align-items: center; justify-content: center;
      border-radius: 12px;
      background: rgba(255,255,255,.12);
      border: 1px solid rgba(255,255,255,.18);
      backdrop-filter: blur(6px);
      font-size: 20px;
    }
    .welcome {
      margin-top: 8px;
      color: rgba(255,255,255,.72);
      font-size: 14.5px;
      display: flex; align-items: center; gap: 8px;
    }
    .welcome .dot {
      width: 8px; height: 8px; border-radius: 50%;
      background: #34d399;
      box-shadow: 0 0 0 4px rgba(52, 211, 153, .18);
    }

    /* ---------- Content ---------- */
    .content-area { padding: 26px 32px 40px; max-width: 1200px; }

    /* ---------- Stats ---------- */
    .stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
      gap: 16px;
      margin-bottom: 22px;
    }
    .stat {
      position: relative;
      overflow: hidden;
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 18px 20px;
      box-shadow: var(--shadow);
      transition: transform .18s ease, box-shadow .18s ease;
    }
    .stat:hover { transform: translateY(-2px); box-shadow: var(--shadow-lg); }
    .stat .stat-top { display: flex; align-items: center; justify-content: space-between; }
    .stat .label {
      font-size: 11.5px;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: .09em;
      font-weight: 700;
    }
    .stat .value {
      font-size: 32px;
      font-weight: 800;
      margin-top: 10px;
      color: var(--ink);
      line-height: 1;
      letter-spacing: -.02em;
    }
    .stat .sub { font-size: 12.5px; color: var(--muted); margin-top: 8px; }
    .stat .icon {
      width: 40px; height: 40px; border-radius: 12px;
      display: inline-flex; align-items: center; justify-content: center;
      font-size: 18px;
    }
    .stat.pending .icon { background: var(--warn-bg); color: var(--warn); }
    .stat.approved .icon { background: var(--ok-bg); color: var(--ok); }
    .stat.rejected .icon { background: var(--danger-bg); color: var(--danger); }
    .stat::before {
      content: ""; position: absolute; left: 0; top: 0; bottom: 0; width: 4px;
    }
    .stat.pending::before  { background: linear-gradient(180deg, #f59e0b, #d97706); }
    .stat.approved::before { background: linear-gradient(180deg, #10b981, #059669); }
    .stat.rejected::before { background: linear-gradient(180deg, #ef4444, #dc2626); }

    /* ---------- Tabs ---------- */
    .tabs {
      display: inline-flex;
      gap: 6px;
      margin-bottom: 20px;
      padding: 6px;
      background: #fff;
      border: 1px solid var(--line);
      border-radius: 999px;
      box-shadow: var(--shadow);
    }
    .tab {
      display: inline-flex; align-items: center; gap: 8px;
      padding: 10px 18px;
      border-radius: 999px;
      text-decoration: none;
      color: #4b5563;
      font-weight: 700;
      font-size: 14px;
      transition: background .15s ease, color .15s ease;
    }
    .tab:hover { background: #f3f4f6; color: var(--ink); }
    .tab.active {
      background: linear-gradient(135deg, var(--brand) 0%, var(--brand-2) 100%);
      color: #fff;
      box-shadow: 0 6px 16px -6px rgba(59, 74, 158, .55);
    }
    .badge {
      display: inline-flex; align-items: center; justify-content: center;
      min-width: 22px; padding: 0 7px; height: 20px;
      border-radius: 999px;
      background: #ef4444; color: #fff;
      font-size: 11px; font-weight: 800;
    }
    .tab.active .badge { background: rgba(255,255,255,.24); }

    /* ---------- Cards ---------- */
    .card {
      background: var(--card);
      border: 1px solid var(--line);
      border-radius: var(--radius);
      padding: 20px 22px;
      margin-bottom: 16px;
      box-shadow: var(--shadow);
      transition: transform .18s ease, box-shadow .18s ease, border-color .18s ease;
    }
    .card:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-lg);
      border-color: #d5dbea;
    }
    .card-head {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 16px;
      margin-bottom: 14px;
    }
    .student-wrap { display: flex; gap: 14px; align-items: flex-start; min-width: 0; }
    .avatar {
      flex: 0 0 auto;
      width: 46px; height: 46px; border-radius: 14px;
      display: inline-flex; align-items: center; justify-content: center;
      font-weight: 800; font-size: 15px; letter-spacing: .02em;
      color: #fff;
      background: linear-gradient(135deg, #3b4a9e, #5b6fd1);
      box-shadow: 0 6px 14px -6px rgba(59, 74, 158, .55);
    }
    .student { font-size: 17.5px; font-weight: 800; color: var(--ink); line-height: 1.25; }
    .meta {
      color: var(--muted);
      font-size: 13px;
      margin-top: 4px;
      line-height: 1.5;
      display: flex; flex-wrap: wrap; gap: 6px 10px; align-items: center;
    }
    .meta .sep { color: #cbd5e1; }
    .chip {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 3px 10px;
      border-radius: 999px;
      background: #eef1fb;
      color: #3b4a9e;
      font-size: 11.5px;
      font-weight: 700;
      border: 1px solid #dfe4f7;
    }
    .chip.gray { background: #f3f4f6; color: #4b5563; border-color: #e5e7eb; }

    /* ---------- Status pill ---------- */
    .status {
      display: inline-flex; align-items: center; gap: 6px;
      padding: 6px 12px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .06em;
      white-space: nowrap;
    }
    .status::before {
      content: ""; width: 7px; height: 7px; border-radius: 50%;
      background: currentColor;
    }
    .status-pending  { background: var(--warn-bg); color: var(--warn); }
    .status-approved { background: var(--ok-bg);  color: var(--ok); }
    .status-rejected { background: var(--danger-bg); color: var(--danger); }

    /* ---------- Reason / Response blocks ---------- */
    .reason, .response {
      margin-top: 6px;
      padding: 14px 16px;
      border-radius: 12px;
      line-height: 1.6;
      font-size: 14px;
      color: #374151;
    }
    .reason {
      background: linear-gradient(180deg, #fbfcfe, #f6f8fd);
      border: 1px solid #eaeef8;
      border-left: 3px solid #c7d2fe;
    }
    .reason .block-label,
    .response .block-label {
      display: block;
      font-size: 11px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .09em;
      color: var(--muted);
      margin-bottom: 6px;
    }
    .response {
      background: linear-gradient(180deg, #eff6ff, #eef2ff);
      border: 1px solid #dbeafe;
      border-left: 3px solid #60a5fa;
      color: #1e3a8a;
    }
    .response .block-label { color: #3b6fd1; }

    /* ---------- Attachments ---------- */
    .attach-row { margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap; }

    /* ---------- Buttons ---------- */
    .btn {
      border: 0;
      border-radius: 10px;
      padding: 10px 16px;
      font-weight: 700;
      font-size: 13.5px;
      cursor: pointer;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
      transition: transform .12s ease, box-shadow .18s ease, background .18s ease, color .18s ease, border-color .18s ease;
      white-space: nowrap;
    }
    .btn:active { transform: translateY(1px); }
    .btn-approve {
      background: linear-gradient(135deg, #10b981, #059669);
      color: #fff;
      box-shadow: 0 8px 18px -8px rgba(5, 150, 105, .7);
    }
    .btn-approve:hover { box-shadow: 0 12px 22px -10px rgba(5, 150, 105, .85); }
    .btn-reject {
      background: #fff;
      color: var(--danger);
      border: 1px solid #fecaca;
      box-shadow: 0 1px 2px rgba(220, 38, 38, .08);
    }
    .btn-reject:hover { background: #fff5f5; border-color: #fca5a5; }
    .btn-neutral {
      background: #fff;
      color: #374151;
      border: 1px solid var(--line);
      box-shadow: 0 1px 2px rgba(16, 26, 58, .04);
    }
    .btn-neutral:hover { background: #f8fafc; border-color: #d5dbea; color: var(--ink); }

    /* ---------- Actions / form ---------- */
    .actions { margin-top: 14px; }
    .review-form {
      margin-top: 16px;
      padding-top: 16px;
      border-top: 1px dashed #e5e7eb;
    }
    .review-form textarea {
      width: 100%;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid #d8dced;
      font: inherit;
      font-size: 14px;
      resize: vertical;
      min-height: 74px;
      background: #fbfcfe;
      color: var(--ink);
      transition: border-color .15s ease, box-shadow .15s ease, background .15s ease;
    }
    .review-form textarea::placeholder { color: #9aa3b2; }
    .review-form textarea:focus {
      outline: none;
      border-color: var(--brand-2);
      background: #fff;
      box-shadow: 0 0 0 4px rgba(91, 111, 209, .15);
    }
    .review-form .btn-row {
      display: flex;
      gap: 10px;
      flex-wrap: wrap;
      margin-top: 12px;
    }

    /* ---------- Empty ---------- */
    .empty {
      background: #fff;
      border: 1px dashed #cfd6e6;
      border-radius: var(--radius);
      padding: 48px 24px;
      text-align: center;
      color: var(--muted);
      box-shadow: var(--shadow);
    }
    .empty .empty-icon {
      width: 60px; height: 60px; margin: 0 auto 14px;
      border-radius: 18px;
      display: flex; align-items: center; justify-content: center;
      font-size: 26px;
      background: linear-gradient(135deg, #eef1fb, #e4e9fb);
      color: var(--brand);
    }
    .empty .empty-title { font-weight: 800; color: var(--ink); font-size: 16px; margin-bottom: 4px; }
    .empty .empty-sub { font-size: 13.5px; }

    /* ---------- Responsive ---------- */
    @media (max-width: 900px) {
      .admin-shell { grid-template-columns: 1fr; }
      .page-header { padding: 24px 18px; }
      .page-header h1 { font-size: 24px; }
      .content-area { padding: 18px; }
      .card { padding: 16px; }
      .card-head { flex-direction: column; }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>
  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>
    <main class="wrap">
      <section class="page-header">
        <h1><span class="h1-icon">⚖️</span> Appeals Review</h1>
        <div class="welcome"><span class="dot"></span> Welcome, <?php echo e($fullName); ?></div>
      </section>

      <div class="content-area">
        <div class="stats">
          <div class="stat pending">
            <div class="stat-top">
              <div class="label">Pending</div>
              <div class="icon">⏳</div>
            </div>
            <div class="value"><?php echo $pendingCount; ?></div>
            <div class="sub">Awaiting your review</div>
          </div>
          <div class="stat approved">
            <div class="stat-top">
              <div class="label">Approved</div>
              <div class="icon">✓</div>
            </div>
            <div class="value"><?php echo $approvedCount; ?></div>
            <div class="sub">Appeals upheld</div>
          </div>
          <div class="stat rejected">
            <div class="stat-top">
              <div class="label">Rejected</div>
              <div class="icon">✕</div>
            </div>
            <div class="value"><?php echo $rejectedCount; ?></div>
            <div class="sub">Appeals denied</div>
          </div>
        </div>

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'rejected'): ?>
          <div style="background:#fef2f2; border:1px solid #fee2e2; border-left:4px solid #dc2626; color:#991b1b; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:600; display:flex; align-items:center; gap:10px;">
            <span>❌</span> Appeal has been rejected. The panel's original decision stands and continues on the student's app.
          </div>
        <?php elseif (isset($_GET['msg']) && $_GET['msg'] === 'error'): ?>
          <div style="background:#fffbeb; border:1px solid #fef3c7; border-left:4px solid #f59e0b; color:#92400e; padding:14px 18px; border-radius:12px; margin-bottom:20px; font-weight:600; display:flex; align-items:center; gap:10px;">
            <span>⚠️</span> An error occurred while processing the appeal.
          </div>
        <?php endif; ?>

        <div class="tabs">
          <a class="tab <?php echo $filter === 'PENDING' ? 'active' : ''; ?>" href="?filter=PENDING">
            Pending <?php if ($pendingCount > 0): ?><span class="badge"><?php echo $pendingCount; ?></span><?php endif; ?>
          </a>
          <a class="tab <?php echo $filter === 'APPROVED' ? 'active' : ''; ?>" href="?filter=APPROVED">
            Approved <?php if ($approvedCount > 0): ?><span class="badge" style="background:#059669;"><?php echo $approvedCount; ?></span><?php endif; ?>
          </a>
          <a class="tab <?php echo $filter === 'REJECTED' ? 'active' : ''; ?>" href="?filter=REJECTED">
            Rejected <?php if ($rejectedCount > 0): ?><span class="badge" style="background:#dc2626;"><?php echo $rejectedCount; ?></span><?php endif; ?>
          </a>
        </div>

        <?php if (empty($appeals)): ?>
          <div class="empty">
            <div class="empty-icon">📭</div>
            <div class="empty-title">No appeals found</div>
            <div class="empty-sub">Nothing to show for this filter right now.</div>
          </div>
        <?php else: ?>
          <?php foreach ($appeals as $appeal): ?>
            <?php
              $status = strtoupper((string)($appeal['status'] ?? 'PENDING'));
              $kind = strtoupper((string)($appeal['appeal_kind'] ?? 'OFFENSE'));
              $statusClass = appeal_status_class($status);
              $studentName = (string)($appeal['student_name'] ?? 'Student');
            ?>
            <div class="card">
              <div class="card-head">
                <div class="student-wrap">
                  <div class="avatar"><?php echo e(initials($studentName)); ?></div>
                  <div style="min-width:0;">
                    <div class="student"><?php echo e($studentName); ?></div>
                    <div class="meta">
                      <span class="chip"><?php echo e(appeal_kind_label($kind)); ?> Appeal</span>
                      <span>Student ID: <?php echo e((string)$appeal['student_id']); ?></span>
                      <span class="sep">•</span>
                      <span>Submitted <?php echo e(fmt_dt((string)($appeal['created_at'] ?? ''))); ?></span>
                    </div>
                    <div class="meta" style="margin-top:6px;">
                      <?php if ($kind === 'UPCC_CASE'): ?>
                        <span>UPCC Case <strong>#<?php echo (int)($appeal['case_id'] ?? 0); ?></strong></span>
                        <span class="sep">•</span>
                        <span class="chip gray">Category <?php echo (int)($appeal['decided_category'] ?? 0); ?></span>
                      <?php else: ?>
                        <span>Offense <strong>#<?php echo (int)($appeal['offense_id'] ?? 0); ?></strong></span>
                        <?php $offLabel = trim((string)($appeal['offense_code'] ?? '') . ' ' . (string)($appeal['offense_name'] ?? '')); ?>
                        <?php if ($offLabel !== ''): ?>
                          <span class="sep">•</span>
                          <span class="chip gray"><?php echo e($offLabel); ?></span>
                        <?php endif; ?>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <span class="status <?php echo $statusClass; ?>"><?php echo e($status); ?></span>
              </div>

              <div class="reason">
                <span class="block-label">Student's Reason</span>
                <?php echo nl2br(e((string)($appeal['reason'] ?? ''))); ?>
              </div>

              <?php if (!empty($appeal['attachment_path'])): ?>
                <div class="attach-row">
                  <a href="../<?php echo e((string)$appeal['attachment_path']); ?>" target="_blank" class="btn btn-neutral">
                    📄 <?php echo e((string)$appeal['attachment_name']); ?>
                  </a>
                </div>
              <?php endif; ?>

              <?php if (!empty($appeal['admin_response'])): ?>
                <div class="response">
                  <span class="block-label">Admin Response</span>
                  <?php echo nl2br(e((string)$appeal['admin_response'])); ?>
                </div>
              <?php endif; ?>

              <?php if (in_array($status, ['PENDING', 'REVIEWING'], true)): ?>
                <form method="post" class="review-form">
                  <input type="hidden" name="action" value="review_appeal" />
                  <input type="hidden" name="appeal_id" value="<?php echo (int)$appeal['appeal_id']; ?>" />
                  <textarea name="admin_response" rows="3" placeholder="Write your admin response / appeal notes here…"></textarea>
                  <div class="btn-row">
                    <button class="btn btn-approve" name="decision" value="APPROVED" type="submit">✓ Approve Appeal</button>
                    <button class="btn btn-reject" name="decision" value="REJECTED" type="submit">✕ Reject Appeal</button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </main>
  </div>
</body>
</html>