<?php
// File: C:\xampp\htdocs\identitrack\admin\dashboard.php
// SDO Dashboard (protected) - title first, then "Welcome <Full Name>"

require_once __DIR__ . '/../database/database.php';
require_admin();

$admin = admin_current();
$activeSidebar = 'dashboard';

$fullName = trim((string)($admin['full_name'] ?? ''));
if ($fullName === '') {
  $fullName = (string)($admin['username'] ?? 'User');
}

// ------------------------------------------------------------
// Community Service: active students (active sessions with no time_out)
// ------------------------------------------------------------
$row = db_one(
  "SELECT COUNT(DISTINCT csr.student_id) AS cnt
   FROM community_service_session css
   JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
   WHERE css.time_out IS NULL"
);
$activeServiceCount = (int)($row['cnt'] ?? 0);

// ------------------------------------------------------------
// Pending Community Service Requests
// ------------------------------------------------------------
$csRow = db_one("SELECT COUNT(*) AS cnt FROM manual_login_request WHERE status='PENDING'");
$pendingCsCount = (int)($csRow['cnt'] ?? 0);

// ------------------------------------------------------------
// Offenses: THIS MONTH breakdown
// ------------------------------------------------------------
$monthStart = date('Y-m-01 00:00:00');
$monthEnd   = date('Y-m-t 23:59:59');

$monthTotalRow = db_one(
  "SELECT COUNT(*) AS cnt FROM offense
   WHERE date_committed BETWEEN :start AND :end",
  [':start' => $monthStart, ':end' => $monthEnd]
);

$monthMinorRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   WHERE o.date_committed BETWEEN :start AND :end AND ot.level = 'MINOR'",
  [':start' => $monthStart, ':end' => $monthEnd]
);

$monthMajorRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   WHERE o.date_committed BETWEEN :start AND :end AND ot.level = 'MAJOR'",
  [':start' => $monthStart, ':end' => $monthEnd]
);

$guardApproveRow = db_one(
  "SELECT COUNT(*) AS cnt FROM guard_violation_report WHERE status = 'APPROVED'"
);
$guardRejectRow = db_one(
  "SELECT COUNT(*) AS cnt FROM guard_violation_report WHERE status = 'REJECTED'"
);

$guardApprovedCount = (int)($guardApproveRow['cnt'] ?? 0);
$guardRejectedCount = (int)($guardRejectRow['cnt'] ?? 0);
$guardTotalCount = $guardApprovedCount + $guardRejectedCount;

$monthTotal = (int)($monthTotalRow['cnt'] ?? 0);
$monthMinor = (int)($monthMinorRow['cnt'] ?? 0);
$monthMajor = (int)($monthMajorRow['cnt'] ?? 0);
$monthLabel = date('F', strtotime($monthStart));

// ------------------------------------------------------------
// UPCC Cases Breakdown
// ------------------------------------------------------------
$upccTotalRow = db_one("SELECT COUNT(*) AS cnt FROM upcc_case");
$upccTotal = (int)($upccTotalRow['cnt'] ?? 0);

$upccUnassignedRow = db_one("SELECT COUNT(*) AS cnt FROM upcc_case WHERE assigned_department_id IS NULL OR assigned_department_id = 0");
$upccUnassigned = (int)($upccUnassignedRow['cnt'] ?? 0);

$upccAssignedNoHearingRow = db_one("SELECT COUNT(*) AS cnt FROM upcc_case WHERE assigned_department_id IS NOT NULL AND assigned_department_id > 0 AND (hearing_date IS NULL OR hearing_date = '')");
$upccAssignedNoHearing = (int)($upccAssignedNoHearingRow['cnt'] ?? 0);

$upccSolvedRow = db_one("SELECT COUNT(*) AS cnt FROM upcc_case WHERE status IN ('CLOSED', 'RESOLVED', 'CANCELLED')");
$upccSolved = (int)($upccSolvedRow['cnt'] ?? 0);

$upccUnsolvedRow = db_one("SELECT COUNT(*) AS cnt FROM upcc_case WHERE status IN ('PENDING', 'UNDER_INVESTIGATION', 'UNDER_APPEAL')");
$upccUnsolved = (int)($upccUnsolvedRow['cnt'] ?? 0);

// ------------------------------------------------------------
// Appeals Breakdown
// ------------------------------------------------------------
$appealPendingRow = db_one("SELECT COUNT(*) AS cnt FROM student_appeal_request WHERE status IN ('PENDING', 'REVIEWING')");
$appealPending = (int)($appealPendingRow['cnt'] ?? 0);

$appealApprovedRow = db_one("SELECT COUNT(*) AS cnt FROM student_appeal_request WHERE status = 'APPROVED'");
$appealApproved = (int)($appealApprovedRow['cnt'] ?? 0);

$appealRejectedRow = db_one("SELECT COUNT(*) AS cnt FROM student_appeal_request WHERE status = 'REJECTED'");
$appealRejected = (int)($appealRejectedRow['cnt'] ?? 0);
$appealTotal = $appealPending + $appealApproved + $appealRejected;

$pendingGuardQueue = db_all(
  "SELECT
     r.report_id,
     r.student_id,
     r.date_committed,
     r.created_at,
     ot.code AS offense_code,
     ot.name AS offense_name,
     ot.level AS offense_level,
     CONCAT(COALESCE(" . db_decrypt_col('student_fn', 's') . ",''), ' ', COALESCE(" . db_decrypt_col('student_ln', 's') . ",'')) AS student_name,
     sg.full_name AS guard_name
   FROM guard_violation_report r
   JOIN offense_type ot ON ot.offense_type_id = r.offense_type_id
   LEFT JOIN student s ON s.student_id = r.student_id
   LEFT JOIN security_guard sg ON sg.guard_id = r.submitted_by
   WHERE r.status = 'PENDING' AND r.is_deleted = 0
   ORDER BY r.created_at DESC
   LIMIT 20",
  [':__enckey' => db_encryption_key()]
);

$guardMsgKey = trim((string)($_GET['guard_msg'] ?? ''));
$guardFlash  = '';
if ($guardMsgKey === 'approved')       $guardFlash = 'Guard submission approved and added to offense records.';
if ($guardMsgKey === 'rejected')       $guardFlash = 'Guard submission rejected and kept in guard-report history.';
if ($guardMsgKey === 'approve_failed') $guardFlash = 'Unable to approve guard submission.';
if ($guardMsgKey === 'reject_failed')  $guardFlash = 'Unable to reject guard submission.';
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>SDO Dashboard | SDO Web Portal</title>
  <style>
    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
      background: #f4f6f9;
      color: #1b2244;
    }

    .admin-shell {
      min-height: calc(100vh - 72px);
      display: grid;
      grid-template-columns: 240px 1fr;
    }

    .wrap { min-height: 100%; padding: 0; }

    /* ── Hero ── */
    .dashboard-hero {
      width: 100%;
      background: #ffffff;
      border-bottom: 1px solid #e5e7eb;
      padding: 28px 32px;
    }
    .dashboard-title {
      margin: 0;
      color: #111827;
      font-size: 26px;
      font-weight: 700;
    }
    .welcome {
      margin-top: 4px;
      color: #6b7280;
      font-size: 14px;
    }

    .scan-overlay {
      position: fixed;
      inset: 0;
      background: rgba(15, 23, 42, .55);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 1500;
      padding: 16px;
    }
    .scan-overlay.show { display: flex; }
    .scan-card {
      width: min(420px, 96vw);
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      box-shadow: 0 20px 50px rgba(0, 0, 0, .22);
      padding: 22px;
      text-align: center;
    }
    .scan-logo-wrap {
      width: 72px;
      height: 72px;
      border-radius: 50%;
      margin: 0 auto 10px;
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .scan-logo {
      width: 48px;
      height: 48px;
      object-fit: contain;
      display: block;
    }
    .scan-card.loading .scan-logo-wrap {
      animation: scanLogoPulse 1.1s ease-in-out infinite;
    }
    @keyframes scanLogoPulse {
      0%, 100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(37, 99, 235, .25); }
      50% { transform: scale(1.04); box-shadow: 0 0 0 8px rgba(37, 99, 235, 0); }
    }
    .scan-spinner {
      width: 44px;
      height: 44px;
      border: 3px solid #dbeafe;
      border-top-color: #2563eb;
      border-radius: 50%;
      margin: 0 auto 14px;
      animation: scanSpin .8s linear infinite;
      display: none;
    }
    @keyframes scanSpin {
      from { transform: rotate(0deg); }
      to { transform: rotate(360deg); }
    }
    .scan-card.loading .scan-spinner { display: block; }
    .scan-title {
      margin: 0;
      font-size: 18px;
      font-weight: 700;
      color: #111827;
    }
    .scan-subtitle {
      margin: 8px 0 0;
      font-size: 13px;
      color: #64748b;
      line-height: 1.45;
    }
    .scan-student {
      margin-top: 12px;
      border: 1px solid #dbeafe;
      background: #eff6ff;
      border-radius: 10px;
      padding: 10px 12px;
      display: none;
      text-align: left;
    }
    .scan-student.show { display: block; }
    .scan-student .nm {
      font-size: 14px;
      font-weight: 700;
      color: #1e3a8a;
    }
    .scan-student .sid {
      font-size: 12px;
      color: #334155;
      margin-top: 2px;
      font-family: 'Consolas', 'Courier New', monospace;
    }
    .scan-card.error .scan-title { color: #b91c1c; }
    .scan-card.error .scan-subtitle { color: #991b1b; }

    @keyframes badgePulse {
      0%, 100% { transform: scale(1); box-shadow: 0 2px 4px rgba(0,0,0,0.2); }
      50% { transform: scale(1.05); box-shadow: 0 4px 8px rgba(239, 68, 68, 0.4); }
    }

    /* ── Stats ── */
    .stats-wrap { padding: 20px 24px 28px; }

    .stats {
      display: grid;
      grid-template-columns: repeat(5, minmax(190px, 1fr));
      gap: 14px;
      overflow-x: auto;
      padding-bottom: 2px;
    }

    .stat-card {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      box-shadow: 0 1px 4px rgba(0,0,0,.05);
      padding: 18px 18px 16px;
      min-height: 200px;
      text-decoration: none;
      color: #111827;
      display: block;
      position: relative;
      overflow: hidden;
      transition: transform .25s ease, box-shadow .25s ease, border-color .25s ease;
    }
    .stat-card::before {
      content: '';
      position: absolute;
      left: 0; top: 0; bottom: 0;
      width: 4px;
      border-radius: 4px 0 0 4px;
    }
    .stat-card.blue::before   { background: #3b82f6; }
    .stat-card.yellow::before { background: #f59e0b; }
    .stat-card.red::before    { background: #ef4444; }
    .stat-card.pink::before   { background: #ec4899; }
    .stat-card.green::before  { background: #10b981; }

    .stat-card:not(.disabled):hover {
      transform: translateY(-4px);
      box-shadow: 0 8px 20px rgba(0,0,0,.1);
    }
    .stat-card.blue:not(.disabled):hover   { border-color: #3b82f6; }
    .stat-card.yellow:not(.disabled):hover { border-color: #f59e0b; }
    .stat-card.red:not(.disabled):hover    { border-color: #ef4444; }
    .stat-card.pink:not(.disabled):hover   { border-color: #ec4899; }
    .stat-card.green:not(.disabled):hover  { border-color: #10b981; }

    .stat-card.disabled { opacity: .5; cursor: not-allowed; pointer-events: none; }

    .stat-icon {
      width: 40px; height: 40px;
      display: grid; place-items: center;
      border-radius: 10px;
    }
    .stat-card.blue   .stat-icon { background: #eff6ff; color: #3b82f6; }
    .stat-card.yellow .stat-icon { background: #fffbeb; color: #f59e0b; }
    .stat-card.red    .stat-icon { background: #fef2f2; color: #ef4444; }
    .stat-card.pink   .stat-icon { background: #fdf2f8; color: #ec4899; }
    .stat-card.green  .stat-icon { background: #ecfdf5; color: #10b981; }

    .stat-icon svg {
      width: 20px; height: 20px;
      stroke: currentColor; fill: none;
      stroke-width: 2; stroke-linecap: round; stroke-linejoin: round;
    }
    .stat-title {
      margin-top: 14px;
      font-size: .85rem;
      color: #6b7280;
      font-weight: 500;
      text-transform: uppercase;
      letter-spacing: .4px;
    }
    .stat-value {
      margin-top: 6px;
      font-size: 2rem;
      font-weight: 700;
      color: #111827;
      line-height: 1;
    }
    .stat-sub {
      margin-top: 8px;
      font-size: .8rem;
      color: #9ca3af;
    }
    .stat-sub.danger { color: #ef4444; }
    .stat-breakdown {
      margin-top: 10px;
      display: flex;
      gap: 12px;
      font-size: .8rem;
      color: #9ca3af;
    }
    .stat-breakdown span strong { color: #374151; }

    /* ── Guard Feed ── */
    .guard-section {
      margin-top: 4px;
    }

    .guard-section-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 14px;
      flex-wrap: wrap;
      gap: 8px;
    }
    .guard-section-left h2 {
      margin: 0;
      font-size: 15px;
      font-weight: 600;
      color: #111827;
    }
    .guard-section-left p {
      margin: 3px 0 0;
      font-size: 12px;
      color: #9ca3af;
      display: flex;
      align-items: center;
      gap: 5px;
    }
    .guard-count-badge {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      height: 20px;
      min-width: 20px;
      padding: 0 6px;
      border-radius: 10px;
      font-size: 11px;
      font-weight: 600;
      background: #fef3c7;
      color: #92400e;
      margin-left: 6px;
    }
    .live-dot {
      width: 7px; height: 7px;
      border-radius: 50%;
      background: #22c55e;
      display: inline-block;
      flex-shrink: 0;
      animation: livePulse 2s infinite;
    }
    @keyframes livePulse {
      0%,100% { box-shadow: 0 0 0 0 rgba(34,197,94,.35); }
      50%      { box-shadow: 0 0 0 5px rgba(34,197,94,0); }
    }

    /* Flash messages */
    .guard-flash {
      margin-bottom: 12px;
      padding: 10px 14px;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 500;
      background: #eff6ff;
      border: 1px solid #bfdbfe;
      color: #1e40af;
    }
    .guard-live-flash {
      margin-bottom: 12px;
      padding: 10px 14px;
      border-radius: 8px;
      font-size: 12px;
      font-weight: 600;
    }
    .guard-live-flash.ok  { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
    .guard-live-flash.err { background: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }

    /* Cards */
    .guard-card {
      background: #ffffff;
      border: 1px solid #e5e7eb;
      border-radius: 12px;
      padding: 14px 16px;
      margin-bottom: 10px;
      transition: border-color .15s, box-shadow .15s;
    }
    .guard-card:hover { border-color: #d1d5db; box-shadow: 0 2px 8px rgba(0,0,0,.06); }
    .guard-card.is-new {
      border-color: #93c5fd;
      background: linear-gradient(180deg, #f0f7ff 0%, #fff 60%);
      animation: newCardPulse 2.5s ease-out;
    }
    @keyframes newCardPulse {
      0%   { box-shadow: 0 0 0 0 rgba(59,130,246,.25); }
      50%  { box-shadow: 0 0 0 6px rgba(59,130,246,0); }
      100% { box-shadow: none; }
    }

    .guard-card-top {
      display: flex;
      align-items: flex-start;
      justify-content: space-between;
      gap: 10px;
      margin-bottom: 10px;
    }
    .guard-offense-code {
      font-size: 11px;
      font-weight: 600;
      color: #9ca3af;
      font-family: 'Consolas', 'Courier New', monospace;
      margin-bottom: 3px;
      letter-spacing: .3px;
    }
    .guard-offense-name {
      font-size: 13px;
      font-weight: 600;
      color: #111827;
      line-height: 1.35;
    }
    .level-badge {
      display: inline-flex;
      align-items: center;
      height: 22px;
      padding: 0 9px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 600;
      white-space: nowrap;
      flex-shrink: 0;
    }
    .level-minor { background: #eff6ff; color: #1d4ed8; }
    .level-major { background: #fef2f2; color: #b91c1c; }

    .guard-card-meta {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 6px 16px;
      margin-bottom: 12px;
    }
    .guard-meta-item { font-size: 12px; color: #374151; }
    .guard-meta-label {
      display: block;
      font-size: 11px;
      color: #9ca3af;
      margin-bottom: 1px;
    }

    .guard-card-footer {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding-top: 10px;
      border-top: 1px solid #f3f4f6;
      gap: 10px;
    }
    .student-row { display: flex; align-items: center; gap: 9px; }
    .student-avatar {
      width: 28px; height: 28px;
      border-radius: 50%;
      background: #eff6ff;
      color: #1d4ed8;
      display: flex; align-items: center; justify-content: center;
      font-size: 10px; font-weight: 700;
      flex-shrink: 0;
    }
    .student-name { font-size: 12px; font-weight: 600; color: #111827; }
    .student-id   { font-size: 11px; color: #9ca3af; margin-top: 1px; }

    .guard-review-btn {
      height: 30px;
      padding: 0 14px;
      border-radius: 7px;
      border: 1px solid #e5e7eb;
      background: #f9fafb;
      color: #374151;
      font-size: 12px;
      font-weight: 600;
      cursor: pointer;
      font-family: inherit;
      white-space: nowrap;
      transition: background .12s, border-color .12s;
    }
    .guard-review-btn:hover { background: #f3f4f6; border-color: #d1d5db; }

    .guard-empty {
      text-align: center;
      padding: 32px 16px;
      color: #9ca3af;
      font-size: 13px;
      background: #f9fafb;
      border-radius: 10px;
      border: 1px dashed #e5e7eb;
    }

    /* ── Modal ── */
    .guard-modal-overlay {
      position: fixed; inset: 0;
      background: rgba(10,20,40,.5);
      display: flex; align-items: center; justify-content: center;
      z-index: 1200;
      padding: 20px;
      opacity: 0; visibility: hidden; pointer-events: none;
      transition: opacity .2s;
    }
    .guard-modal-overlay.show {
      opacity: 1; visibility: visible; pointer-events: auto;
    }
    .guard-modal {
      width: min(500px, 96vw);
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 16px;
      box-shadow: 0 20px 60px rgba(0,0,0,.18);
      overflow: hidden;
      transform: translateY(10px) scale(.98);
      opacity: 0;
      transition: transform .2s ease, opacity .2s ease;
    }
    .guard-modal-overlay.show .guard-modal {
      transform: translateY(0) scale(1);
      opacity: 1;
    }
    .guard-modal-head {
      padding: 16px 20px;
      border-bottom: 1px solid #f3f4f6;
      display: flex; align-items: center; justify-content: space-between; gap: 10px;
    }
    .guard-modal-title {
      margin: 0;
      font-size: 15px;
      font-weight: 700;
      color: #111827;
    }
    .guard-modal-close {
      width: 30px; height: 30px;
      border-radius: 8px;
      border: 1px solid #e5e7eb;
      background: #f9fafb;
      color: #6b7280;
      cursor: pointer;
      font-size: 14px;
      display: flex; align-items: center; justify-content: center;
    }
    .guard-modal-close:hover { background: #f3f4f6; }

    .guard-modal-body { padding: 16px 20px; }

    .guard-modal-offense-block {
      background: #f9fafb;
      border: 1px solid #f3f4f6;
      border-radius: 10px;
      padding: 12px 14px;
      margin-bottom: 14px;
    }
    .guard-modal-offense-code {
      font-size: 11px; font-weight: 600;
      color: #9ca3af;
      font-family: 'Consolas', monospace;
      margin-bottom: 3px;
    }
    .guard-modal-offense-name {
      font-size: 14px; font-weight: 700;
      color: #111827;
    }

    .guard-modal-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px 16px;
      font-size: 12px;
    }
    .guard-modal-field-label {
      font-size: 11px;
      color: #9ca3af;
      margin-bottom: 2px;
    }
    .guard-modal-field-value {
      font-size: 13px;
      font-weight: 500;
      color: #111827;
    }

    .guard-modal-actions {
      padding: 14px 20px 16px;
      border-top: 1px solid #f3f4f6;
      display: flex; gap: 8px; justify-content: flex-end; flex-wrap: wrap;
    }
    .gm-btn {
      height: 34px; padding: 0 16px;
      border-radius: 8px;
      font-size: 12px; font-weight: 700;
      cursor: pointer;
      font-family: inherit;
      display: inline-flex; align-items: center; justify-content: center;
      text-decoration: none;
      border: 1px solid transparent;
      transition: opacity .12s;
    }
    .gm-btn:disabled { opacity: .55; cursor: wait; }
    .gm-btn.neutral { background: #f3f4f6; border-color: #e5e7eb; color: #374151; }
    .gm-btn.neutral:hover { background: #e5e7eb; }
    .gm-btn.view    { background: #eff6ff; border-color: #bfdbfe; color: #1d4ed8; }
    .gm-btn.view:hover { background: #dbeafe; }
    .gm-btn.reject  { background: #fef2f2; border-color: #fecaca; color: #b91c1c; }
    .gm-btn.reject:hover { background: #fee2e2; }
    .gm-btn.approve { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
    .gm-btn.approve:hover { background: #dcfce7; }

    /* ── Confirm dialogs ── */
    .guard-confirm-overlay {
      position: fixed; inset: 0;
      background: rgba(10,20,40,.45);
      display: flex; align-items: center; justify-content: center;
      z-index: 1300;
      padding: 16px;
      opacity: 0; visibility: hidden; pointer-events: none;
      transition: opacity .18s;
    }
    .guard-confirm-overlay.show {
      opacity: 1; visibility: visible; pointer-events: auto;
    }
    .guard-confirm-box {
      width: min(420px, 96vw);
      background: #fff;
      border: 1px solid #e5e7eb;
      border-radius: 14px;
      box-shadow: 0 16px 40px rgba(0,0,0,.2);
      padding: 20px;
      transform: translateY(8px);
      opacity: 0;
      transition: transform .18s, opacity .18s;
    }
    .guard-confirm-overlay.show .guard-confirm-box { transform: translateY(0); opacity: 1; }
    .guard-confirm-icon {
      width: 40px; height: 40px;
      border-radius: 10px;
      display: flex; align-items: center; justify-content: center;
      margin-bottom: 12px;
    }
    .guard-confirm-icon.danger { background: #fef2f2; }
    .guard-confirm-icon.success { background: #f0fdf4; }
    .guard-confirm-icon svg { width: 20px; height: 20px; fill: none; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .guard-confirm-icon.danger svg { stroke: #ef4444; }
    .guard-confirm-icon.success svg { stroke: #22c55e; }
    .guard-confirm-title {
      margin: 0 0 6px;
      font-size: 15px; font-weight: 700;
      color: #111827;
    }
    .guard-confirm-text {
      margin: 0; color: #6b7280; font-size: 13px; line-height: 1.55;
    }
    .guard-confirm-actions {
      margin-top: 18px;
      display: flex; justify-content: flex-end; gap: 8px;
    }

    /* ── Responsive ── */
    @media (max-width: 1400px) { .stats { grid-template-columns: repeat(3, minmax(190px, 1fr)); } }
    @media (max-width: 900px)  { .admin-shell { grid-template-columns: 1fr; } .stats { grid-template-columns: repeat(2, minmax(190px, 1fr)); } }
    @media (max-width: 640px)  {
      .dashboard-hero { padding: 18px 16px; }
      .stats-wrap { padding: 14px 14px 20px; }
      .stats { grid-template-columns: 1fr; }
      .guard-card-meta { grid-template-columns: 1fr; }
      .guard-modal-grid { grid-template-columns: 1fr; }
    }

    /* ── Letter Modal ── */
    .letter-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; padding: 22px; }
    .letter-col h3 { font-size: 13px; font-weight: 700; color: #111827; margin-bottom: 12px; }
    .letter-preview {
      background: #f9fafb;
      border: 1.5px solid #e5e7eb;
      border-radius: 8px;
      height: 420px;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .letter-preview iframe { width: 100%; height: 100%; border: none; }
    .loading {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      color: #9ca3af;
      font-size: 13px;
      font-weight: 600;
    }
    .loading svg { animation: spin 1s linear infinite; width: 18px; height: 18px; }
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .letter-msg { font-size: 12.5px; font-weight: 600; margin-top: 12px; }
    @media (max-width: 640px) { .letter-grid { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>
  <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="wrap">

      <!-- Hero -->
      <section class="dashboard-hero">
        <h1 class="dashboard-title">SDO Dashboard</h1>
        <div class="welcome">Welcome back, <?php echo e($fullName); ?></div>
      </section>

      <section class="stats-wrap" aria-label="Dashboard stats">

        <!-- Stat Cards -->
        <div class="stats">

          <!-- Active Community Service -->
          <a class="stat-card blue" href="community_service.php?tab=pending" aria-label="View active community service" style="position:relative;">
              <div id="csPendingBadge" style="position:absolute; top:12px; right:12px; background:#ef4444; color:#fff; border-radius:12px; padding:3px 8px; font-size:11px; font-weight:700; z-index:10; animation: badgePulse 2s infinite; display:<?php echo $pendingCsCount > 0 ? 'block' : 'none'; ?>;">
                <span><?php echo $pendingCsCount; ?></span> PENDING
              </div>
            <div class="stat-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><circle cx="8" cy="8" r="3"/><circle cx="16" cy="8" r="3"/><path d="M2.5 19c0-3 2.5-5 5.5-5"/><path d="M10.5 19c0-3 2.5-5 5.5-5"/></svg>
            </div>
            <div class="stat-title">Community Service</div>
            <div class="stat-value" id="csActiveCount"><?php echo (int)$activeServiceCount; ?></div>
            <div class="stat-sub">Active students</div>
          </a>

          <!-- Guard Reports History -->
          <div class="stat-card yellow">
            <div class="stat-icon" aria-hidden="true">
               <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
            </div>
            <div class="stat-title">Violation Reports</div>
            <div class="stat-value" id="guardCardTotal"><?php echo $guardTotalCount; ?></div>
            <div class="stat-breakdown">
              <span><strong style="color: #10b981;" id="guardCardApproved">Approved: <?php echo $guardApprovedCount; ?></strong></span>
              <span><strong style="color: #ef4444;" id="guardCardRejected">Rejected: <?php echo $guardRejectedCount; ?></strong></span>
            </div>
          </div>

          <!-- Appeals -->
          <div class="stat-card red">
            <a href="appeals.php" style="text-decoration:none; color:inherit; display:block;" aria-label="View Appeals">
              <div class="stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M12 3v18"></path><path d="M5 8h14"></path><path d="M5 16h14"></path></svg>
              </div>
              <div class="stat-title">Appeals</div>
              <div class="stat-value"><?php echo $appealTotal; ?></div>
            </a>
            <div style="margin-top: 10px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; font-size: 0.75rem; color: #6b7280; line-height: 1.2;">
              <a href="appeals.php?filter=pending" style="text-decoration:none; color:inherit; display:block;">
                Pending:<br><strong style="color:#f59e0b; transition: opacity 0.15s;" onmouseover="this.style.opacity=0.7;" onmouseout="this.style.opacity=1;"><?php echo $appealPending; ?></strong>
              </a>
              <a href="appeals.php?filter=rejected" style="text-decoration:none; color:inherit; display:block;">
                Rejected:<br><strong style="color:#ef4444; transition: opacity 0.15s;" onmouseover="this.style.opacity=0.7;" onmouseout="this.style.opacity=1;"><?php echo $appealRejected; ?></strong>
              </a>
              <a href="appeals.php?filter=approved" style="text-decoration:none; color:inherit; display:block;">
                Approved:<br><strong style="color:#10b981; transition: opacity 0.15s;" onmouseover="this.style.opacity=0.7;" onmouseout="this.style.opacity=1;"><?php echo $appealApproved; ?></strong>
              </a>
            </div>
          </div>

          <!-- UPCC Cases -->
          <div class="stat-card pink">
            <a href="upcc_cases.php" style="text-decoration:none; color:inherit; display:block;" aria-label="View UPCC Cases">
              <div class="stat-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24"><path d="M2 3h6a4 4 0 0 1 4 4v14a3 3 0 0 0-3-3H2z"/><path d="M22 3h-6a4 4 0 0 0-4 4v14a3 3 0 0 1 3-3h7z"/></svg>
              </div>
              <div class="stat-title">UPCC Cases</div>
              <div class="stat-value"><?php echo $upccTotal; ?></div>
            </a>
            <div style="margin-top: 10px; display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 6px; font-size: 0.75rem; color: #6b7280; line-height: 1.2;">
              <a href="upcc_cases.php?filter=unassigned" style="text-decoration:none; color:inherit; display:block;">
                Unassigned:<br><strong style="color:#ef4444; transition: opacity 0.15s;" onmouseover="this.style.opacity=0.7;" onmouseout="this.style.opacity=1;"><?php echo $upccUnassigned; ?></strong>
              </a>
              <a href="upcc_cases.php?filter=unsolved" style="text-decoration:none; color:inherit; display:block;">
                Unsolved:<br><strong style="color:#374151; transition: opacity 0.15s;" onmouseover="this.style.opacity=0.7;" onmouseout="this.style.opacity=1;"><?php echo $upccUnsolved; ?></strong>
              </a>
              <a href="upcc_cases.php?filter=solved" style="text-decoration:none; color:inherit; display:block;">
                Solved:<br><strong style="color:#10b981; transition: opacity 0.15s;" onmouseover="this.style.opacity=0.7;" onmouseout="this.style.opacity=1;"><?php echo $upccSolved; ?></strong>
              </a>
            </div>
          </div>

          <!-- This Month's Offenses -->
          <a class="stat-card green" href="offenses.php" aria-label="View offenses">
            <div class="stat-icon" aria-hidden="true">
              <svg viewBox="0 0 24 24"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
            </div>
            <div class="stat-title"><?php echo e($monthLabel); ?>'s Offenses</div>
            <div class="stat-value"><?php echo (int)$monthTotal; ?></div>
            <div class="stat-breakdown">
              <span>Minor: <strong><?php echo (int)$monthMinor; ?></strong></span>
              <span>Major: <strong><?php echo (int)$monthMajor; ?></strong></span>
            </div>
          </a>

        </div><!-- /.stats -->

        <!-- Violation Submissions Feed -->
        <div class="guard-section" aria-label="Pending violation submissions">

          <div class="guard-section-header">
            <div class="guard-section-left">
              <h2>
                Pending Violation Submissions
                <span class="guard-count-badge" id="guardPendingCount"><?php echo (int)count($pendingGuardQueue); ?></span>
              </h2>
              <p><span class="live-dot" aria-hidden="true"></span>Live queue </p>
            </div>
          </div>

          <?php if ($guardFlash !== ''): ?>
            <div class="guard-flash"><?php echo e($guardFlash); ?></div>
          <?php endif; ?>

          <div id="guardLiveFlash" style="display:none;"></div>

          <div id="guardFeedList">
            <?php if (empty($pendingGuardQueue)): ?>
              <div class="guard-empty">No pending violation reports &mdash; all clear.</div>
            <?php else: ?>
              <?php foreach ($pendingGuardQueue as $g): ?>
                <?php
                  $sName     = trim((string)$g['student_name']);
                  $sDisplay  = $sName !== '' ? $sName : (string)$g['student_id'];
                  $initials  = '';
                  $parts     = preg_split('/\s+/', $sDisplay);
                  foreach (array_slice($parts, 0, 2) as $p) {
                    $initials .= mb_strtoupper(mb_substr($p, 0, 1));
                  }
                  $levelClass = strtolower((string)$g['offense_level']) === 'major' ? 'level-major' : 'level-minor';
                ?>
                <div class="guard-card">
                  <div class="guard-card-top">
                    <div>
                      <div class="guard-offense-code"><?php echo e((string)$g['offense_code']); ?></div>
                      <div class="guard-offense-name"><?php echo e((string)$g['offense_name']); ?></div>
                    </div>
                    <span class="level-badge <?php echo $levelClass; ?>"><?php echo e((string)$g['offense_level']); ?></span>
                  </div>

                  <div class="guard-card-meta">
                    <div class="guard-meta-item">
                      <span class="guard-meta-label">Guard</span>
                      <?php echo e((string)($g['guard_name'] ?? 'Unknown')); ?>
                    </div>
                    <div class="guard-meta-item">
                      <span class="guard-meta-label">Date committed</span>
                      <?php echo e(date('M d, Y g:i A', strtotime((string)$g['date_committed']))); ?>
                    </div>
                    <div class="guard-meta-item">
                      <span class="guard-meta-label">Submitted</span>
                      <?php echo e(date('M d, Y g:i A', strtotime((string)$g['created_at']))); ?>
                    </div>
                  </div>

                  <div class="guard-card-footer">
                    <div class="student-row">
                      <div class="student-avatar"><?php echo e($initials); ?></div>
                      <div>
                        <div class="student-name"><?php echo e($sDisplay); ?></div>
                        <div class="student-id"><?php echo e((string)$g['student_id']); ?></div>
                      </div>
                    </div>
                    <button
                      type="button"
                      class="guard-review-btn open-guard-modal"
                      data-report-id="<?php echo (int)$g['report_id']; ?>"
                      data-student-id="<?php echo htmlspecialchars((string)$g['student_id'], ENT_QUOTES, 'UTF-8'); ?>"
                      data-student-name="<?php echo htmlspecialchars($sDisplay, ENT_QUOTES, 'UTF-8'); ?>"
                      data-student-initials="<?php echo htmlspecialchars($initials, ENT_QUOTES, 'UTF-8'); ?>"
                      data-guard-name="<?php echo htmlspecialchars((string)($g['guard_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?>"
                      data-offense-code="<?php echo htmlspecialchars((string)$g['offense_code'], ENT_QUOTES, 'UTF-8'); ?>"
                      data-offense-name="<?php echo htmlspecialchars((string)$g['offense_name'], ENT_QUOTES, 'UTF-8'); ?>"
                      data-offense-level="<?php echo htmlspecialchars((string)$g['offense_level'], ENT_QUOTES, 'UTF-8'); ?>"
                      data-date-committed="<?php echo htmlspecialchars(date('M d, Y g:i A', strtotime((string)$g['date_committed'])), ENT_QUOTES, 'UTF-8'); ?>"
                      data-submitted-at="<?php echo htmlspecialchars(date('M d, Y g:i A', strtotime((string)$g['created_at'])), ENT_QUOTES, 'UTF-8'); ?>"
                    >Review</button>
                  </div>
                </div>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>

        </div><!-- /.guard-section -->

      </section>
    </main>
  </div>

  <!-- ── Review Modal ── -->
  <div id="guardReviewModal" class="guard-modal-overlay" aria-hidden="true">
    <div class="guard-modal" role="dialog" aria-modal="true" aria-labelledby="guardModalTitle">
      <div class="guard-modal-head">
        <h3 id="guardModalTitle" class="guard-modal-title">Review Guard Report</h3>
        <button id="guardModalClose" type="button" class="guard-modal-close" aria-label="Close">&#x2715;</button>
      </div>
      <div class="guard-modal-body">
        <div class="guard-modal-offense-block">
          <div class="guard-modal-offense-code" id="gmCode">—</div>
          <div class="guard-modal-offense-name" id="gmOffense">—</div>
        </div>
        <div class="guard-modal-grid">
          <div>
            <div class="guard-modal-field-label">Student</div>
            <div class="guard-modal-field-value" id="gmStudent">—</div>
          </div>
          <div>
            <div class="guard-modal-field-label">Student ID</div>
            <div class="guard-modal-field-value" id="gmStudentId">—</div>
          </div>
          <div>
            <div class="guard-modal-field-label">Guard</div>
            <div class="guard-modal-field-value" id="gmGuard">—</div>
          </div>
          <div>
            <div class="guard-modal-field-label">Level</div>
            <div class="guard-modal-field-value" id="gmLevel">—</div>
          </div>
          <div>
            <div class="guard-modal-field-label">Date committed</div>
            <div class="guard-modal-field-value" id="gmDate">—</div>
          </div>
          <div>
            <div class="guard-modal-field-label">Submitted at</div>
            <div class="guard-modal-field-value" id="gmSubmitted">—</div>
          </div>
        </div>
      </div>
      <div class="guard-modal-actions">
        <a id="gmViewStudent" class="gm-btn view" href="#">View Student Record</a>
        <button id="gmRejectBtn"  type="button" class="gm-btn reject">Reject</button>
        <button id="gmApproveBtn" type="button" class="gm-btn approve">Approve &amp; Record</button>
      </div>
    </div>
  </div>

  <!-- ── Reject Confirm ── -->
  <div id="guardRejectConfirm" class="guard-confirm-overlay" aria-hidden="true">
    <div class="guard-confirm-box" role="dialog" aria-modal="true">
      <div class="guard-confirm-icon danger">
        <svg viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>
      </div>
      <h4 class="guard-confirm-title">Reject this report?</h4>
      <p class="guard-confirm-text">This report will be marked as rejected and kept in guard-report history, but removed from the pending review queue.</p>
      <div class="guard-confirm-actions">
        <button id="guardRejectCancel" type="button" class="gm-btn neutral">Cancel</button>
        <button id="guardRejectConfirmBtn" type="button" class="gm-btn reject">Yes, Reject (Keep History)</button>
      </div>
    </div>
  </div>

  <!-- ── Approve Confirm ── -->
  <div id="guardApproveConfirm" class="guard-confirm-overlay" aria-hidden="true">
    <div class="guard-confirm-box" role="dialog" aria-modal="true">
      <div class="guard-confirm-icon success">
        <svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
      </div>
      <h4 class="guard-confirm-title">Approve and record?</h4>
      <p class="guard-confirm-text">This report will be approved and permanently recorded in the student's offense history.</p>
      <div class="guard-confirm-actions">
        <button id="guardApproveCancel" type="button" class="gm-btn neutral">Cancel</button>
        <button id="guardApproveConfirmBtn" type="button" class="gm-btn approve">Yes, Approve</button>
      </div>
    </div>
  </div>

  <!-- ── Scanner Overlay ── -->
  <div id="scanOverlay" class="scan-overlay" aria-hidden="true">
    <div id="scanCard" class="scan-card loading" role="status" aria-live="polite">
      <div class="scan-logo-wrap">
        <img class="scan-logo" src="../assets/logo.png" alt="IDENTITRACK logo" />
      </div>
      <div class="scan-spinner" aria-hidden="true"></div>
      <h3 id="scanTitle" class="scan-title">Scanning ID...</h3>
      <p id="scanSubtitle" class="scan-subtitle">Please wait while student record is being verified.</p>
      <div id="scanStudent" class="scan-student">
        <div id="scanStudentName" class="nm"></div>
        <div id="scanStudentId" class="sid"></div>
      </div>
    </div>
  </div>

  <script>
  (function () {
    var feedList      = document.getElementById('guardFeedList');
    var countBadge    = document.getElementById('guardPendingCount');
    var liveFlash     = document.getElementById('guardLiveFlash');
    var modalEl       = document.getElementById('guardReviewModal');
    var modalClose    = document.getElementById('guardModalClose');
    var gmCode        = document.getElementById('gmCode');
    var gmOffense     = document.getElementById('gmOffense');
    var gmStudent     = document.getElementById('gmStudent');
    var gmStudentId   = document.getElementById('gmStudentId');
    var gmGuard       = document.getElementById('gmGuard');
    var gmLevel       = document.getElementById('gmLevel');
    var gmDate        = document.getElementById('gmDate');
    var gmSubmitted   = document.getElementById('gmSubmitted');
    var gmViewStudent = document.getElementById('gmViewStudent');
    var gmRejectBtn   = document.getElementById('gmRejectBtn');
    var gmApproveBtn  = document.getElementById('gmApproveBtn');
    var rejectConfirm         = document.getElementById('guardRejectConfirm');
    var rejectCancel          = document.getElementById('guardRejectCancel');
    var rejectConfirmBtn      = document.getElementById('guardRejectConfirmBtn');
    var approveConfirm        = document.getElementById('guardApproveConfirm');
    var approveCancel         = document.getElementById('guardApproveCancel');
    var approveConfirmBtn     = document.getElementById('guardApproveConfirmBtn');
    var scanOverlay           = document.getElementById('scanOverlay');
    var scanCard              = document.getElementById('scanCard');
    var scanTitle             = document.getElementById('scanTitle');
    var scanSubtitle          = document.getElementById('scanSubtitle');
    var scanStudent           = document.getElementById('scanStudent');
    var scanStudentName       = document.getElementById('scanStudentName');
    var scanStudentId         = document.getElementById('scanStudentId');

    if (!feedList) return;

    var seenIds      = new Set();
    var initialLoad  = true;
    var selectedId   = '';
    var lastFocus    = null;
    var lastConfFocus = null;
    var scanBuffer   = '';
    var scanTimer    = null;
    var scanBusy     = false;

    function flushScanBuffer() {
      if (scanBusy) return;
      var finalScan = String(scanBuffer || '').trim();
      scanBuffer = '';
      if (scanTimer) {
        clearTimeout(scanTimer);
        scanTimer = null;
      }
      if (finalScan.length >= 6) {
        handleScannerValue(finalScan);
      }
    }

    function setScanOverlay(mode, title, subtitle, studentName, studentId) {
      if (!scanOverlay || !scanCard || !scanTitle || !scanSubtitle || !scanStudent) return;
      scanOverlay.classList.add('show');
      scanOverlay.setAttribute('aria-hidden', 'false');

      scanCard.classList.remove('loading', 'success', 'error');
      scanCard.classList.add(mode);
      scanTitle.textContent = title || '';
      scanSubtitle.textContent = subtitle || '';

      if (studentName || studentId) {
        scanStudent.classList.add('show');
        scanStudentName.textContent = String(studentName || '');
        scanStudentId.textContent = String(studentId || '');
      } else {
        scanStudent.classList.remove('show');
        scanStudentName.textContent = '';
        scanStudentId.textContent = '';
      }
    }

    function hideScanOverlay() {
      if (!scanOverlay) return;
      scanOverlay.classList.remove('show');
      scanOverlay.setAttribute('aria-hidden', 'true');
    }

    function handleScannerValue(value) {
      var scanned = String(value || '').trim();
      if (!scanned || scanBusy) return;

      scanBusy = true;
      setScanOverlay('loading', 'Scanning ID...', 'Please wait while student record is being verified.');

      fetch('AJAX/scan_student_lookup.php?scan=' + encodeURIComponent(scanned), {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) {
          setScanOverlay(
            'success',
            'Student Found',
            'Redirecting to offense records...',
            data.student_name || '',
            data.student_id || ''
          );
          setTimeout(function () {
            window.location.href = String(data.redirect_url || 'offenses.php');
          }, 1800);
          return;
        }

        setScanOverlay('error', 'No Match Found', 'No student record found for scanned ID.');
        setTimeout(function () {
          hideScanOverlay();
          scanBusy = false;
        }, 1400);
      })
      .catch(function () {
        setScanOverlay('error', 'Scan Error', 'Unable to process scan right now. Try again.');
        setTimeout(function () {
          hideScanOverlay();
          scanBusy = false;
        }, 1600);
      });
    }

    document.addEventListener('keydown', function (ev) {
      if (scanBusy) return;

      var tgt = ev.target;
      var isTypingTarget = tgt && (
        tgt.tagName === 'INPUT' ||
        tgt.tagName === 'TEXTAREA' ||
        tgt.tagName === 'SELECT' ||
        tgt.isContentEditable
      );
      if (isTypingTarget) return;

      if (ev.key === 'Enter') {
        flushScanBuffer();
        return;
      }

      if (ev.key.length === 1 && !ev.ctrlKey && !ev.altKey && !ev.metaKey) {
        scanBuffer += ev.key;
        if (scanTimer) clearTimeout(scanTimer);
        scanTimer = setTimeout(function () {
          flushScanBuffer();
        }, 180);
      }
    });

    /* ── Flash ── */
    var flashTimer = null;
    function showFlash(kind, msg, timeout) {
      if (!liveFlash) return;
      if (flashTimer) clearTimeout(flashTimer);

      liveFlash.className = 'guard-live-flash ' + kind;
      liveFlash.textContent = msg;
      liveFlash.style.display = 'block';
      
      if (timeout) {
        flashTimer = setTimeout(function() {
          liveFlash.style.display = 'none';
        }, timeout);
      }
    }

    /* ── Modal ── */
    function openModal(d) {
      lastFocus = document.activeElement;
      selectedId = String(d.reportId || '');
      gmCode.textContent       = d.offenseCode || '—';
      gmOffense.textContent    = d.offenseName || '—';
      gmStudent.textContent    = d.studentName || d.studentId || '—';
      gmStudentId.textContent  = d.studentId   || '—';
      gmGuard.textContent      = d.guardName   || '—';
      gmLevel.textContent      = d.offenseLevel || '—';
      gmDate.textContent       = d.dateCommitted || '—';
      gmSubmitted.textContent  = d.submittedAt  || '—';
      gmViewStudent.href       = 'offenses_student_view.php?student_id='
        + encodeURIComponent(String(d.studentId || ''))
        + '&pending_report_id=' + encodeURIComponent(String(d.reportId || ''));
      modalEl.classList.add('show');
      modalEl.setAttribute('aria-hidden', 'false');
      if (gmApproveBtn) gmApproveBtn.focus();
    }

    function closeModal() {
      modalEl.classList.remove('show');
      modalEl.setAttribute('aria-hidden', 'true');
      selectedId = '';
      closeRejectConfirm();
      closeApproveConfirm();
      if (lastFocus && lastFocus.focus) lastFocus.focus();
    }

    function openRejectConfirm() {
      lastConfFocus = document.activeElement;
      rejectConfirm.classList.add('show');
      rejectConfirm.setAttribute('aria-hidden', 'false');
      if (rejectConfirmBtn) rejectConfirmBtn.focus();
    }
    function closeRejectConfirm() {
      rejectConfirm.classList.remove('show');
      rejectConfirm.setAttribute('aria-hidden', 'true');
      if (lastConfFocus && lastConfFocus.focus) lastConfFocus.focus();
    }
    function openApproveConfirm() {
      lastConfFocus = document.activeElement;
      approveConfirm.classList.add('show');
      approveConfirm.setAttribute('aria-hidden', 'false');
      if (approveConfirmBtn) approveConfirmBtn.focus();
    }
    function closeApproveConfirm() {
      approveConfirm.classList.remove('show');
      approveConfirm.setAttribute('aria-hidden', 'true');
      if (lastConfFocus && lastConfFocus.focus) lastConfFocus.focus();
    }

    /* ── AJAX action ── */
    function runAction(action) {
      if (!selectedId) return;
      gmApproveBtn.disabled = true;
      gmRejectBtn.disabled  = true;
      var fd = new FormData();
      fd.append('action', action);
      fd.append('report_id', selectedId);
      fetch('AJAX/guard_report_review.php', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) {
          if (!data || data.ok !== true) {
            showFlash('err', (data && data.message) ? data.message : 'Unable to process the report.', 5000);
            return;
          }
          
          var timeout = action === 'reject_guard_report' ? 4000 : 5000;
          closeModal();
          poll();
          if (typeof window.refreshNotifications === 'function') {
            window.refreshNotifications();
          }
          
          if (data.escalation_type) {
              openLetterModal(data);
          } else {
              showFlash('ok', data.message || 'Guard report updated.', timeout);
          }
        })
        .catch(function () { showFlash('err', 'Unable to process the report.'); })
        .finally(function () {
          gmApproveBtn.disabled = false;
          gmRejectBtn.disabled  = false;
        });
    }

    /* ── Helpers ── */
    function esc(v) {
      return String(v == null ? '' : v)
        .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }
    function initials(name) {
      var parts = String(name || '').trim().split(/\s+/);
      return parts.slice(0,2).map(function(p){ return p.charAt(0).toUpperCase(); }).join('');
    }

    /* ── Render ── */
    function render(items) {
      var rows = Array.isArray(items) ? items : [];
      if (countBadge) countBadge.textContent = rows.length;

      // Sidebar count badge
      var sidebarCount = document.querySelector('.admin-sidebar-link[href="dashboard.php"] .admin-sidebar-count');
      if (sidebarCount) {
        sidebarCount.textContent = rows.length > 0 ? String(rows.length) : '';
        sidebarCount.style.display = rows.length > 0 ? 'inline-flex' : 'none';
      }

      if (!rows.length) {
        feedList.innerHTML = '<div class="guard-empty">No pending violation reports &mdash; all clear.</div>';
        seenIds = new Set();
        initialLoad = false;
        return;
      }

      var nextSeen = new Set();
      var html = rows.map(function (g) {
        var id      = String(g.report_id || '');
        var isNew   = !initialLoad && id !== '' && !seenIds.has(id);
        var sName   = String(g.student_name || g.student_id || '');
        var inits   = initials(sName) || String(g.student_id || '').substring(0,2).toUpperCase();
        var lvlClass= String(g.offense_level||'').toLowerCase() === 'major' ? 'level-major' : 'level-minor';
        if (id) nextSeen.add(id);
        return '<div class="guard-card' + (isNew ? ' is-new' : '') + '">'
          + '<div class="guard-card-top">'
            + '<div>'
              + '<div class="guard-offense-code">' + esc(g.offense_code) + '</div>'
              + '<div class="guard-offense-name">' + esc(g.offense_name) + '</div>'
            + '</div>'
            + '<span class="level-badge ' + lvlClass + '">' + esc(g.offense_level) + '</span>'
          + '</div>'
          + '<div class="guard-card-meta">'
            + '<div class="guard-meta-item"><span class="guard-meta-label">Guard</span>' + esc(g.guard_name || 'Unknown') + '</div>'
            + '<div class="guard-meta-item"><span class="guard-meta-label">Date committed</span>' + esc(g.date_committed_label) + '</div>'
            + '<div class="guard-meta-item"><span class="guard-meta-label">Submitted</span>' + esc(g.created_at_label) + '</div>'
          + '</div>'
          + '<div class="guard-card-footer">'
            + '<div class="student-row">'
              + '<div class="student-avatar">' + esc(inits) + '</div>'
              + '<div>'
                + '<div class="student-name">' + esc(sName) + '</div>'
                + '<div class="student-id">' + esc(g.student_id) + '</div>'
              + '</div>'
            + '</div>'
            + '<button type="button" class="guard-review-btn open-guard-modal"'
              + ' data-report-id="' + esc(g.report_id) + '"'
              + ' data-student-id="' + esc(g.student_id) + '"'
              + ' data-student-name="' + esc(sName) + '"'
              + ' data-guard-name="' + esc(g.guard_name || 'Unknown') + '"'
              + ' data-offense-code="' + esc(g.offense_code) + '"'
              + ' data-offense-name="' + esc(g.offense_name) + '"'
              + ' data-offense-level="' + esc(g.offense_level) + '"'
              + ' data-date-committed="' + esc(g.date_committed_label) + '"'
              + ' data-submitted-at="' + esc(g.created_at_label) + '">Review</button>'
          + '</div>'
        + '</div>';
      }).join('');

      feedList.innerHTML = html;
      seenIds = nextSeen;
      initialLoad = false;
    }

    /* ── Poll ── */
    function poll() {
      fetch('AJAX/guard_reports_live.php', { cache: 'no-store', credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (data) { 
          if (data && data.ok === true) {
            render(data.pending_reports || []); 
            
            // Update Community Service Live Stats
            if (data.community_service) {
                var pendingCount = parseInt(data.community_service.pending) || 0;
                var activeCount = parseInt(data.community_service.active) || 0;
                
                // Update Dashboard Card
                var csPendingBadge = document.getElementById('csPendingBadge');
                if (csPendingBadge) {
                    if (pendingCount > 0) {
                        csPendingBadge.querySelector('span').textContent = pendingCount;
                        csPendingBadge.style.display = 'block';
                    } else {
                        csPendingBadge.style.display = 'none';
                    }
                }
                
                var csActiveCount = document.getElementById('csActiveCount');
                if (csActiveCount) {
                    csActiveCount.textContent = activeCount;
                }
                
                // Update Sidebar Link Badge
                var csSidebarLink = document.querySelector('.admin-sidebar-link[href="community_service.php"]');
                if (csSidebarLink) {
                    var badge = csSidebarLink.querySelector('.admin-sidebar-count');
                    if (badge) {
                        badge.textContent = pendingCount > 0 ? pendingCount : '';
                        badge.style.display = pendingCount > 0 ? 'inline-flex' : 'none';
                    }
                }
            }
            
            // Update Guard Reports Stats
            if (data.guard_stats) {
                var gTotal = document.getElementById('guardCardTotal');
                var gApproved = document.getElementById('guardCardApproved');
                var gRejected = document.getElementById('guardCardRejected');
                
                if (gTotal) gTotal.textContent = data.guard_stats.total;
                if (gApproved) gApproved.textContent = 'Approved: ' + data.guard_stats.approved;
                if (gRejected) gRejected.textContent = 'Rejected: ' + data.guard_stats.rejected;
            }
          }
        })
        .catch(function () {});
    }
    poll();
    setInterval(poll, 8000);

    /* ── Event listeners ── */
    feedList.addEventListener('click', function (ev) {
      var btn = ev.target.closest('.open-guard-modal');
      if (!btn) return;
      openModal({
        reportId:      btn.getAttribute('data-report-id')      || '',
        studentId:     btn.getAttribute('data-student-id')     || '',
        studentName:   btn.getAttribute('data-student-name')   || '',
        guardName:     btn.getAttribute('data-guard-name')     || '',
        offenseCode:   btn.getAttribute('data-offense-code')   || '',
        offenseName:   btn.getAttribute('data-offense-name')   || '',
        offenseLevel:  btn.getAttribute('data-offense-level')  || '',
        dateCommitted: btn.getAttribute('data-date-committed') || '',
        submittedAt:   btn.getAttribute('data-submitted-at')   || ''
      });
    });

    if (modalClose) modalClose.addEventListener('click', closeModal);
    modalEl.addEventListener('click', function (e) { if (e.target === modalEl) closeModal(); });

    if (gmApproveBtn) gmApproveBtn.addEventListener('click', openApproveConfirm);
    if (gmRejectBtn)  gmRejectBtn.addEventListener('click', openRejectConfirm);

    if (rejectCancel)     rejectCancel.addEventListener('click', closeRejectConfirm);
    if (rejectConfirmBtn) rejectConfirmBtn.addEventListener('click', function () { closeRejectConfirm(); runAction('reject_guard_report'); });
    rejectConfirm.addEventListener('click', function (e) { if (e.target === rejectConfirm) closeRejectConfirm(); });

    if (approveCancel)     approveCancel.addEventListener('click', closeApproveConfirm);
    if (approveConfirmBtn) approveConfirmBtn.addEventListener('click', function () { closeApproveConfirm(); runAction('approve_guard_report'); });
    approveConfirm.addEventListener('click', function (e) { if (e.target === approveConfirm) closeApproveConfirm(); });

    document.addEventListener('keydown', function (ev) {
      var approveOpen = approveConfirm.classList.contains('show');
      var rejectOpen  = rejectConfirm.classList.contains('show');
      var modalOpen   = modalEl.classList.contains('show');
      var letterOpen  = document.getElementById('modal-guardian-letter').classList.contains('show');

      if (approveOpen) {
        if (ev.key === 'Escape') { ev.preventDefault(); closeApproveConfirm(); return; }
        if (ev.key === 'Enter' && !ev.ctrlKey && !ev.altKey && !ev.metaKey && !ev.shiftKey) {
          ev.preventDefault();
          if (approveConfirmBtn && !approveConfirmBtn.disabled) approveConfirmBtn.click();
          return;
        }
      }
      if (rejectOpen) {
        if (ev.key === 'Escape') { ev.preventDefault(); closeRejectConfirm(); return; }
        if (ev.key === 'Enter' && !ev.ctrlKey && !ev.altKey && !ev.metaKey && !ev.shiftKey) {
          ev.preventDefault();
          if (rejectConfirmBtn && !rejectConfirmBtn.disabled) rejectConfirmBtn.click();
          return;
        }
      }
      if (letterOpen) {
        if (ev.key === 'Escape') { ev.preventDefault(); closeLetterModal(); return; }
      }
      if (modalOpen && !approveOpen && !rejectOpen && !letterOpen) {
        if (ev.key === 'Escape') { ev.preventDefault(); closeModal(); }
      }
    });
    
    /* ── Letter Modal Logic ── */
    window.currentLetterOffenseId = null;
    
    window.openLetterModal = function(data) {
        var modal = document.getElementById('modal-guardian-letter');
        var title = document.getElementById('letter_modal_title');
        
        currentLetterOffenseId = data.offense_id;
        
        if (data.escalation_type === 'escalation') {
            title.textContent = '📧 Guardian Notification — Section 4 Panel Referral';
        } else {
            title.textContent = '📧 Guardian Notification — 2nd Minor Offense';
        }
        
        document.getElementById('letter_guardian_email').value = data.guardian_email || '';
        document.getElementById('letter_subject').value = data.default_subject || '';
        document.getElementById('letter_body').value = data.default_body || '';
        document.getElementById('previewContent').innerHTML = '<div class="loading"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Generating preview…</div>';
        document.getElementById('letterMsg').textContent = '';
        
        modal.classList.add('show');
        checkEmailRequired();
        previewLetter();
    };
    
    window.checkEmailRequired = function() {
        const input = document.getElementById('letter_guardian_email');
        const msg = document.getElementById('email_validation_msg');
        const btn = document.getElementById('btn_send_letter');
        if(!input || !btn) return;
        
        const val = input.value.trim();
        const isValid = val.length > 0 && val.includes('@') && val.includes('.');
        
        if (val.length === 0) {
          if (msg) { msg.style.display = 'block'; msg.textContent = 'A valid guardian email is required to send this notice.'; }
          btn.disabled = true;
          btn.style.opacity = '0.5';
          btn.style.cursor = 'not-allowed';
        } else if (!isValid) {
          if (msg) { msg.style.display = 'block'; msg.textContent = 'Please enter a valid email address.'; }
          btn.disabled = true;
          btn.style.opacity = '0.5';
          btn.style.cursor = 'not-allowed';
        } else {
          if (msg) msg.style.display = 'none';
          btn.disabled = false;
          btn.style.opacity = '1';
          btn.style.cursor = 'pointer';
        }
    };
    
    window.closeLetterModal = function() {
        document.getElementById('modal-guardian-letter').classList.remove('show');
        showFlash('ok', 'Guard report approved. Letter composition was closed.', 5000);
    };

    async function postJSON(url, body) {
      const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        body: JSON.stringify(body),
        cache: 'no-store'
      });
      return { ok: res.ok, json: await res.json().catch(() => null) };
    }
    
    window.previewLetter = async function() {
      const guardianEmail = document.getElementById('letter_guardian_email')?.value.trim() || '';
      const subject = document.getElementById('letter_subject')?.value || '';
      const body    = document.getElementById('letter_body')?.value    || '';
      const preview = document.getElementById('previewContent');
      if (!preview) return;
      if (!guardianEmail) {
          preview.innerHTML = '<div style="padding:16px;color:#ef4444;font-weight:600;">⚠️ Cannot generate preview: Guardian email is required.</div>';
          return;
      }
      preview.innerHTML = '<div class="loading"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Generating…</div>';
      const r = await postJSON('AJAX/offense_letter_preview.php', { offense_id: currentLetterOffenseId, subject, body, guardian_email: guardianEmail });
      if (r.ok && r.json?.ok && r.json?.pdf_url) preview.innerHTML = '<iframe src="' + r.json.pdf_url + '"></iframe>';
      else preview.innerHTML = '<div style="padding:16px;color:#ef4444;font-weight:600;">Failed to generate preview.</div>';
    };
    
    window.sendLetter = async function() {
      const guardianEmail = document.getElementById('letter_guardian_email')?.value.trim() || '';
      const subject = document.getElementById('letter_subject')?.value || '';
      const body    = document.getElementById('letter_body')?.value    || '';
      const msg     = document.getElementById('letterMsg');
      
      if (!guardianEmail) {
          if (msg) { msg.textContent = '❌ Cannot send email: Guardian email is required.'; msg.style.color = '#ef4444'; }
          alert('Please enter a guardian email address before sending.');
          document.getElementById('letter_guardian_email').focus();
          return;
      }
      
      if (msg) { msg.textContent = 'Sending…'; msg.style.color = '#6b7280'; }
      const r = await postJSON('AJAX/offense_letter_send.php', { offense_id: currentLetterOffenseId, subject, body, guardian_email: guardianEmail });
      if (msg) {
          if (r.ok && r.json?.ok) {
              msg.textContent = '✅ Email sent successfully!';
              msg.style.color = '#10b981';
              setTimeout(() => {
                  document.getElementById('modal-guardian-letter').classList.remove('show');
                  showFlash('ok', 'Guard report approved and guardian notified.', 5000);
              }, 1500);
          } else {
              msg.textContent = '❌ Failed to send email: ' + (r.json?.message || 'Unknown error');
              msg.style.color = '#ef4444';
          }
      }
    };

    /* ── Auto-open report from URL ── */
    const urlParams = new URLSearchParams(window.location.search);
    const openId = urlParams.get('open_report_id');
    if (openId) {
      // Find the report in the list or fetch it
      // For now, we'll wait for the first poll to complete or use the initial PHP data if available
      const checkAndOpen = () => {
        const btn = document.querySelector(`.open-guard-modal[data-report-id="${openId}"]`);
        if (btn) {
          btn.click();
          // Remove param from URL without refresh
          const newUrl = window.location.pathname;
          window.history.replaceState({}, '', newUrl);
        } else {
          // If not found in first 20, maybe it's approved already?
        }
      };
      
      // Wait a bit for initial render
      setTimeout(checkAndOpen, 500);
    }

  })();
  </script>
  
  <div class="guard-modal-overlay" id="modal-guardian-letter" style="z-index: 2500;">
    <div class="guard-modal" style="max-width: 1100px; width: 95%; max-height: 95vh; overflow-y: auto;">
      <div class="guard-modal-head">
        <h3 class="guard-modal-title" id="letter_modal_title">📧 Guardian Notification</h3>
        <button class="guard-modal-close" onclick="closeLetterModal()">&times;</button>
      </div>
      <div class="guard-modal-body" style="padding: 24px;">
        <p style="color: #6b7280; margin-top: 0; margin-bottom: 20px; font-size: 13px;">Review and send the notification letter to the guardian. You can update the email address if needed before sending.</p>
        
        <div class="letter-grid">
          <div class="letter-col">
            <h3>Compose Letter</h3>
            <div style="margin-bottom:14px;">
              <label for="letter_guardian_email" style="font-size:11px; color:#9ca3af; display:block; margin-bottom:4px;">Guardian Email Address <span style="color:#ef4444;">(Required)</span></label>
              <input id="letter_guardian_email" type="email" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px;" placeholder="Enter guardian email..." oninput="checkEmailRequired()" />
              <div id="email_validation_msg" style="font-size:11px; margin-top:6px; color:#ef4444; font-weight:600; display:none;"></div>
            </div>
            <div style="margin-bottom:14px;">
              <label for="letter_subject" style="font-size:11px; color:#9ca3af; display:block; margin-bottom:4px;">Subject</label>
              <input id="letter_subject" type="text" style="width:100%; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px;"/>
            </div>
            <div style="margin-bottom:14px;">
              <label for="letter_body" style="font-size:11px; color:#9ca3af; display:block; margin-bottom:4px;">Message</label>
              <textarea id="letter_body" style="width:100%; min-height:350px; font-family: monospace; font-size: 13px; padding:8px 12px; border:1px solid #d1d5db; border-radius:6px;"></textarea>
            </div>
            <div style="display:flex; gap:10px;">
              <button type="button" class="gm-btn neutral" onclick="previewLetter()">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px; height:14px; margin-right:6px;"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                Preview
              </button>
              <button type="button" class="gm-btn approve" id="btn_send_letter" onclick="sendLetter()">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px; height:14px; margin-right:6px;"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
                Send Email
              </button>
            </div>
            <div id="letterMsg" class="letter-msg"></div>
          </div>
          <div class="letter-col">
            <h3>PDF Preview</h3>
            <div class="letter-preview" style="height: 600px;">
              <div id="previewContent" style="width: 100%; height: 100%;">
                <div class="loading" style="margin: auto;"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Generating preview…</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

</body>
</html>