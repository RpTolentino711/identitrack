<?php
// File: C:\xampp\htdocs\identitrack\admin\AJAX\export_student_profile_pdf.php
// Official PDF Export of Comprehensive Student Conduct & Disciplinary Record

declare(strict_types=1);

@ini_set('memory_limit', '512M');
@set_time_limit(120);

// Enable error reporting for debug if needed
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
ini_set('display_errors', '0');

require_once __DIR__ . '/../../database/database.php';

// Support both admin session formats (admin array vs admin_id scalar)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin']) && !empty($_SESSION['admin_id'])) {
    $_SESSION['admin'] = [
        'admin_id' => $_SESSION['admin_id'],
        'username' => $_SESSION['admin_username'] ?? 'admin',
        'role' => $_SESSION['role'] ?? 'ADMIN'
    ];
}

if (!admin_current()) {
    redirect('../login.php');
}

require_admin();
session_write_close();

try {
    $studentId = trim((string)($_GET['student_id'] ?? $_POST['student_id'] ?? ''));
    if ($studentId === '') {
        http_response_code(400);
        die('Error: Missing or invalid student ID.');
    }

    // 1. Fetch Student Information
    $sParams = [':sid' => $studentId];
    db_add_encryption_key($sParams);
    $student = db_one(
        "SELECT student_id, program, section, year_level, school, is_active,
                " . db_decrypt_cols(['student_fn', 'student_ln', 'student_email', 'phone_number']) . "
         FROM student
         WHERE student_id = :sid
         LIMIT 1",
        $sParams
    );

    if (!$student) {
        http_response_code(404);
        die('Error: Student record not found.');
    }

    $fullName = trim(($student['student_fn'] ?? '') . ' ' . ($student['student_ln'] ?? ''));
    if ($fullName === '') $fullName = $studentId;

    // 2. Fetch Guardian Information (with schema fallback)
    $guardian = null;
    try {
        $guardian = db_one(
            "SELECT guardian_id, guardian_fn, guardian_ln, guardian_email, 
                    COALESCE(guardian_number, '') AS guardian_phone
             FROM guardian
             WHERE student_id = :sid
             LIMIT 1",
            [':sid' => $studentId]
        );
    } catch (\Throwable $e) {
        try {
            $guardian = db_one(
                "SELECT guardian_id, guardian_fn, guardian_ln, guardian_email
                 FROM guardian
                 WHERE student_id = :sid
                 LIMIT 1",
                [':sid' => $studentId]
            );
        } catch (\Throwable $e2) {
            $guardian = null;
        }
    }

    $guardianName = $guardian ? trim(($guardian['guardian_fn'] ?? '') . ' ' . ($guardian['guardian_ln'] ?? '')) : 'Not provided';
    $guardianEmail = !empty($guardian['guardian_email']) ? $guardian['guardian_email'] : 'Not provided';
    $guardianPhone = !empty($guardian['guardian_phone']) ? $guardian['guardian_phone'] : 'Not provided';
    $guardianRel   = 'Parent / Guardian';

    // 3. Fetch Offenses History
    $offenses = [];
    try {
        $oParams = [':sid' => $studentId];
        db_add_encryption_key($oParams);
        $offenses = db_all(
            "SELECT o.offense_id, o.date_committed, o.level, o.status,
                    " . db_decrypt_col('description', 'o') . " AS description,
                    ot.code, ot.name, ot.major_category,
                    uco.case_id
             FROM offense o
             JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
             LEFT JOIN upcc_case_offense uco ON uco.offense_id = o.offense_id
             WHERE o.student_id = :sid
             ORDER BY o.date_committed ASC, o.offense_id ASC",
            $oParams
        ) ?: [];
    } catch (\Throwable $e) {
        $offenses = [];
    }

    $minorCount = 0;
    $majorCount = 0;
    $dismissedCount = 0;
    foreach ($offenses as $o) {
        $lvl = strtoupper((string)($o['level'] ?? ''));
        if ($lvl === 'MINOR') $minorCount++;
        elseif ($lvl === 'MAJOR') $majorCount++;
        elseif ($lvl === 'DISMISSED') $dismissedCount++;
    }
    $section4Escalations = (int)floor($minorCount / 3);

    // 4. Fetch UPCC Disciplinary Cases
    $cases = [];
    try {
        $cParams = [':sid' => $studentId];
        db_add_encryption_key($cParams);
        $cases = db_all(
            "SELECT c.case_id, c.case_kind, c.status, c.decided_category,
                    " . db_decrypt_col('case_summary', 'c') . " AS case_summary,
                    " . db_decrypt_col('punishment_details', 'c') . " AS punishment_details,
                    c.probation_until, c.hearing_date, c.hearing_time, c.hearing_type,
                    c.final_decision, c.created_at, c.updated_at
             FROM upcc_case c
             WHERE c.student_id = :sid AND c.status <> 'VOID'
             ORDER BY c.created_at DESC",
            $cParams
        ) ?: [];
    } catch (\Throwable $e) {
        $cases = [];
    }

    $pendingCases = [];
    $resolvedCases = [];
    $pendingStatuses = ['PENDING', 'UNDER_INVESTIGATION', 'AWAITING_PANEL_DECISION', 'AWAITING_ADMIN_FINALIZATION', 'ACTIVE', 'UNDER_APPEAL'];

    foreach ($cases as $c) {
        $st = strtoupper((string)($c['status'] ?? ''));
        if (in_array($st, $pendingStatuses, true)) {
            $pendingCases[] = $c;
        } else {
            $resolvedCases[] = $c;
        }
    }

    // 5. Fetch Community Service Requirements & Sessions
    $requirements = [];
    try {
        $rParams = [':sid' => $studentId];
        db_add_encryption_key($rParams);
        $requirements = db_all(
            "SELECT r.requirement_id, r.related_case_id,
                    r.task_name, r.hours_required, r.status, r.assigned_at, r.completed_at
             FROM community_service_requirement r
             WHERE r.student_id = :sid
             ORDER BY r.requirement_id DESC",
            $rParams
        ) ?: [];
    } catch (\Throwable $e) {
        $requirements = [];
    }

    $activeReq = null;
    $completedReqs = [];
    $allReqIds = [];

    foreach ($requirements as $r) {
        $allReqIds[] = (int)$r['requirement_id'];
        $rSt = strtoupper((string)$r['status']);
        if ($rSt === 'ACTIVE' && $activeReq === null) {
            $activeReq = $r;
        } elseif ($rSt === 'COMPLETED') {
            $completedReqs[] = $r;
        }
    }

    $sessions = [];
    if (!empty($allReqIds)) {
        $inClause = implode(',', $allReqIds);
        try {
            $sessions = db_all(
                "SELECT s.session_id, s.requirement_id, s.time_in, s.time_out, s.login_method,
                        s.status, s.validated_by, s.sdo_notes,
                        (
                          TIMESTAMPDIFF(SECOND, s.time_in, COALESCE(s.time_out, NOW()))
                        ) / 3600.0 AS hours_done
                 FROM community_service_session s
                 WHERE s.requirement_id IN ($inClause)
                 ORDER BY s.time_in DESC"
            ) ?: [];
        } catch (\Throwable $e) {
            $sessions = [];
        }
    }

    // Group sessions by requirement_id
    $sessionsByReq = [];
    foreach ($sessions as $sess) {
        $sessionsByReq[(int)$sess['requirement_id']][] = $sess;
    }

    // Compute active community service metrics
    $activeCsRequired = 0.0;
    $activeCsDone = 0.0;
    $activeCsRemaining = 0.0;
    $hasOngoingCs = ($activeReq !== null);
    $hasCompletedCs = (!empty($completedReqs));

    if ($activeReq) {
        $activeCsRequired = (float)$activeReq['hours_required'];
        $activeSessions = $sessionsByReq[(int)$activeReq['requirement_id']] ?? [];
        foreach ($activeSessions as $as) {
            $activeCsDone += (float)($as['hours_done'] ?? 0);
        }
        $activeCsRemaining = max(0.0, $activeCsRequired - $activeCsDone);
    }

    // 6. Overall Profile Status Determination
    $isCleanProfile = (empty($offenses) && empty($cases) && empty($requirements));
    $isUnderSanction = ($hasOngoingCs || !empty($pendingCases) || $majorCount > 0);

    if ($isCleanProfile) {
        $standingTitle = 'CLEAN PROFILE — IN GOOD STANDING';
        $standingClass = 'standing-clean';
        $standingDesc  = 'This student has no disciplinary offenses, active sanctions, or committee cases recorded on file.';
    } elseif ($hasOngoingCs) {
        $standingTitle = 'ACTIVE DISCIPLINARY SANCTION (ONGOING COMMUNITY SERVICE)';
        $standingClass = 'standing-warning';
        $standingDesc  = 'The student currently has an ongoing community service requirement to fulfill (' . number_format($activeCsRemaining, 2) . ' hours remaining).';
    } elseif (!empty($pendingCases)) {
        $standingTitle = 'CASE UNDER INVESTIGATION / PENDING PROCEEDINGS';
        $standingClass = 'standing-danger';
        $standingDesc  = 'The student currently has active UPCC committee case proceedings pending administrative finalization.';
    } elseif ($majorCount > 0) {
        $standingTitle = 'MAJOR DISCIPLINARY RECORD ON FILE';
        $standingClass = 'standing-danger';
        $standingDesc  = 'The student has past major offense records. Review historical case determinations below.';
    } else {
        $standingTitle = 'RESOLVED RECORD / CONDUCT WARNING HISTORY';
        $standingClass = 'standing-info';
        $standingDesc  = 'The student has minor conduct warning records on file, with all previous requirements resolved.';
    }

    // Format Helper
    function formatHrsMins(float $hours): string {
        $h = floor($hours);
        $m = round(($hours - $h) * 60);
        if ($h > 0 && $m > 0) return "{$h} hr " . ($m < 10 ? '0' : '') . "{$m} mins";
        if ($h > 0) return "{$h} " . ($h == 1 ? 'hour' : 'hours');
        return "{$m} " . ($m == 1 ? 'minute' : 'minutes');
    }

    $categoryNames = [
        1 => 'Category 1 — Probation & Counseling',
        2 => 'Category 2 — Formative Community Service',
        3 => 'Category 3 — Non-Readmission Warning / Suspension',
        4 => 'Category 4 — Exclusion / Dropping from Roll',
        5 => 'Category 5 — Expulsion & Police Referral',
    ];

    // University Logo
    $logoBase64 = '';
    $logoPath = __DIR__ . '/../../assets/logo.png';
    if (file_exists($logoPath)) {
        $logoData = @file_get_contents($logoPath);
        if ($logoData !== false && strlen($logoData) > 0) {
            $logoBase64 = 'data:image/png;base64,' . base64_encode($logoData);
        }
    }

    $generatedAt = ph_date('F j, Y \a\t g:i A');
    $adminUsername = $_SESSION['admin_username'] ?? 'SDO Administrator';

    // Build HTML
    ob_start();
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Student Conduct Profile - <?= htmlspecialchars($studentId) ?></title>
<style>
  @page {
    margin: 28px 32px 35px 32px;
  }
  body {
    font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
    color: #1e293b;
    font-size: 11px;
    line-height: 1.45;
  }
  .header-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 14px;
    border-bottom: 2px solid #1b2b6b;
    padding-bottom: 8px;
  }
  .header-logo {
    width: 65px;
    vertical-align: middle;
  }
  .header-text {
    vertical-align: middle;
    padding-left: 10px;
  }
  .header-title {
    font-size: 16px;
    font-weight: bold;
    color: #1b2b6b;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
  }
  .header-sub {
    font-size: 10px;
    font-weight: 600;
    color: #475569;
    margin: 2px 0 0 0;
  }
  .header-meta {
    text-align: right;
    vertical-align: middle;
    font-size: 9.5px;
    color: #64748b;
  }
  .doc-title-bar {
    background: #f1f5f9;
    border-left: 4px solid #1b2b6b;
    padding: 7px 12px;
    margin-bottom: 12px;
  }
  .doc-title {
    font-size: 13px;
    font-weight: bold;
    color: #0f172a;
    text-transform: uppercase;
    margin: 0;
  }
  .standing-box {
    padding: 9px 12px;
    border-radius: 6px;
    margin-bottom: 14px;
    border-width: 1px;
    border-style: solid;
  }
  .standing-clean {
    background: #f0fdf4;
    border-color: #86efac;
    color: #166534;
  }
  .standing-warning {
    background: #fffbeb;
    border-color: #fcd34d;
    color: #92400e;
  }
  .standing-danger {
    background: #fef2f2;
    border-color: #fca5a5;
    color: #991b1b;
  }
  .standing-info {
    background: #eff6ff;
    border-color: #93c5fd;
    color: #1e40af;
  }
  .standing-header {
    font-size: 11.5px;
    font-weight: bold;
    margin-bottom: 2px;
  }
  .standing-desc {
    font-size: 10px;
    line-height: 1.35;
  }

  /* Metric KPI Cards */
  .kpi-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 14px;
  }
  .kpi-card {
    border: 1px solid #cbd5e1;
    border-radius: 6px;
    padding: 8px 6px;
    text-align: center;
    background: #ffffff;
  }
  .kpi-num {
    font-size: 18px;
    font-weight: bold;
    line-height: 1.1;
  }
  .kpi-label {
    font-size: 9px;
    font-weight: 600;
    color: #64748b;
    text-transform: uppercase;
    margin-top: 3px;
  }

  /* Info Tables */
  .section-title {
    font-size: 11.5px;
    font-weight: bold;
    color: #1b2b6b;
    border-bottom: 1.5px solid #e2e8f0;
    padding-bottom: 4px;
    margin-top: 14px;
    margin-bottom: 8px;
    text-transform: uppercase;
    letter-spacing: 0.3px;
  }
  .info-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 12px;
  }
  .info-table td {
    padding: 4px 6px;
    vertical-align: top;
  }
  .info-label {
    width: 20%;
    font-weight: bold;
    color: #475569;
    font-size: 10px;
  }
  .info-val {
    width: 30%;
    color: #0f172a;
    font-size: 10.5px;
  }

  /* Data Tables */
  .data-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 12px;
    font-size: 9.5px;
  }
  .data-table th {
    background: #1e293b;
    color: #ffffff;
    font-weight: bold;
    text-align: left;
    padding: 5px 6px;
    border: 1px solid #1e293b;
    text-transform: uppercase;
    font-size: 8.5px;
    letter-spacing: 0.3px;
  }
  .data-table td {
    padding: 5px 6px;
    border: 1px solid #e2e8f0;
    vertical-align: top;
  }
  .data-table tr:nth-child(even) td {
    background: #f8fafc;
  }

  /* Badges */
  .badge {
    display: inline-block;
    padding: 2px 5px;
    border-radius: 4px;
    font-size: 8.5px;
    font-weight: bold;
    text-transform: uppercase;
  }
  .badge-active { background: #eff6ff; color: #1d4ed8; border: 1px solid #93c5fd; }
  .badge-completed { background: #f0fdf4; color: #15803d; border: 1px solid #86efac; }
  .badge-minor { background: #fef3c7; color: #b45309; border: 1px solid #fcd34d; }
  .badge-major { background: #fee2e2; color: #b91c1c; border: 1px solid #fca5a5; }
  .badge-dismissed { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }

  /* Sign-off footer */
  .footer-sign {
    margin-top: 25px;
    page-break-inside: avoid;
    width: 100%;
    border-collapse: collapse;
  }
  .footer-sign td {
    padding: 0 10px;
    vertical-align: top;
  }
  .sign-line {
    border-top: 1px solid #334155;
    margin-top: 35px;
    padding-top: 4px;
    text-align: center;
    font-size: 9.5px;
    font-weight: bold;
    color: #0f172a;
  }
  .confidential-note {
    font-size: 8px;
    color: #94a3b8;
    text-align: center;
    margin-top: 20px;
    border-top: 1px dashed #cbd5e1;
    padding-top: 6px;
  }
</style>
</head>
<body>

  <!-- Official Letterhead Header -->
  <table class="header-table">
    <tr>
      <?php if (!empty($logoBase64)): ?>
        <td class="header-logo"><img src="<?= $logoBase64 ?>" style="height: 52px;" alt="Logo"></td>
      <?php endif; ?>
      <td class="header-text">
        <div class="header-title">Student Discipline Office (SDO)</div>
        <div class="header-sub">IdentiTrack Disciplinary Records &amp; Conduct Management System</div>
        <div style="font-size: 8.5px; color: #64748b; margin-top: 1px;">National University Lipa • Official Conduct Assessment</div>
      </td>
      <td class="header-meta">
        <strong>CONFIDENTIAL REPORT</strong><br>
        Date: <?= $generatedAt ?><br>
        Ref ID: SDO-REC-<?= htmlspecialchars($studentId) ?>
      </td>
    </tr>
  </table>

  <!-- Document Title Bar -->
  <div class="doc-title-bar">
    <div class="doc-title">Student Disciplinary Record &amp; Conduct Summary</div>
  </div>

  <!-- Standing / Status Assessment Callout -->
  <div class="standing-box <?= $standingClass ?>">
    <div class="standing-header"><?= $standingTitle ?></div>
    <div class="standing-desc"><?= htmlspecialchars($standingDesc) ?></div>
  </div>

  <!-- Metric KPI Cards -->
  <table class="kpi-table">
    <tr>
      <td style="width: 20%; padding: 0 4px 0 0;">
        <div class="kpi-card">
          <div class="kpi-num" style="color: #b45309;"><?= $minorCount ?></div>
          <div class="kpi-label">Minor Offenses</div>
        </div>
      </td>
      <td style="width: 20%; padding: 0 4px;">
        <div class="kpi-card">
          <div class="kpi-num" style="color: #b91c1c;"><?= $majorCount ?></div>
          <div class="kpi-label">Major Offenses</div>
        </div>
      </td>
      <td style="width: 20%; padding: 0 4px;">
        <div class="kpi-card">
          <div class="kpi-num" style="color: #4338ca;"><?= $section4Escalations ?></div>
          <div class="kpi-label">Section 4 Escalations</div>
        </div>
      </td>
      <td style="width: 20%; padding: 0 4px;">
        <div class="kpi-card">
          <div class="kpi-num" style="color: #0369a1;"><?= count($pendingCases) ?></div>
          <div class="kpi-label">Pending Cases</div>
        </div>
      </td>
      <td style="width: 20%; padding: 0 0 0 4px;">
        <div class="kpi-card">
          <div class="kpi-num" style="color: <?= $hasOngoingCs ? '#d97706' : ($hasCompletedCs ? '#15803d' : '#64748b'); ?>;">
            <?= $hasOngoingCs ? number_format($activeCsRemaining, 1) . 'h' : ($hasCompletedCs ? 'Cleared' : 'None'); ?>
          </div>
          <div class="kpi-label">Community Service</div>
        </div>
      </td>
    </tr>
  </table>

  <!-- 1. Student & Guardian Information -->
  <div class="section-title">1. Student &amp; Guardian Profile</div>
  <table class="info-table">
    <tr>
      <td class="info-label">Full Name:</td>
      <td class="info-val"><strong><?= htmlspecialchars($fullName) ?></strong></td>
      <td class="info-label">Student ID:</td>
      <td class="info-val"><strong><?= htmlspecialchars($student['student_id']) ?></strong></td>
    </tr>
    <tr>
      <td class="info-label">Program &amp; Section:</td>
      <td class="info-val"><?= htmlspecialchars(($student['program'] ?? 'N/A') . ' • Sec. ' . ($student['section'] ?? 'N/A')) ?></td>
      <td class="info-label">Year Level:</td>
      <td class="info-val"><?= htmlspecialchars(($student['year_level'] ?? 'N/A') . ' Year') ?></td>
    </tr>
    <tr>
      <td class="info-label">Institutional School:</td>
      <td class="info-val"><?= htmlspecialchars($student['school'] ?? 'College') ?></td>
      <td class="info-label">Account Status:</td>
      <td class="info-val">
        <span class="badge <?= !empty($student['is_active']) ? 'badge-completed' : 'badge-major' ?>">
          <?= !empty($student['is_active']) ? 'Active Student' : 'Account Frozen / Suspended' ?>
        </span>
      </td>
    </tr>
    <tr>
      <td class="info-label">Student Email:</td>
      <td class="info-val"><?= htmlspecialchars($student['student_email'] ?? 'Not provided') ?></td>
      <td class="info-label">Student Phone:</td>
      <td class="info-val"><?= htmlspecialchars($student['phone_number'] ?? 'Not provided') ?></td>
    </tr>
    <tr>
      <td class="info-label">Guardian Name:</td>
      <td class="info-val"><?= htmlspecialchars($guardianName) ?> (<?= htmlspecialchars($guardianRel) ?>)</td>
      <td class="info-label">Guardian Contact:</td>
      <td class="info-val"><?= htmlspecialchars($guardianEmail) ?> • <?= htmlspecialchars($guardianPhone) ?></td>
    </tr>
  </table>

  <!-- 2. Community Service Record -->
  <div class="section-title">2. Formative Community Service Status &amp; Session Logs</div>
  <?php if (empty($requirements)): ?>
    <div style="padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; color: #64748b; font-style: italic; margin-bottom: 12px;">
      ✓ No community service sanctions have ever been assigned to this student. Record is clean.
    </div>
  <?php else: ?>
    <?php foreach ($requirements as $req): ?>
      <?php
        $rid = (int)$req['requirement_id'];
        $rStatus = strtoupper((string)$req['status']);
        $reqSessions = $sessionsByReq[$rid] ?? [];
        $totServed = 0.0;
        foreach ($reqSessions as $rs) {
            $totServed += (float)($rs['hours_done'] ?? 0);
        }
        $hrsReq = (float)$req['hours_required'];
        $hrsLeft = max(0.0, $hrsReq - $totServed);
      ?>
      <div style="background: #f8fafc; border: 1px solid #cbd5e1; border-radius: 6px; padding: 8px 10px; margin-bottom: 10px;">
        <table style="width: 100%; border-collapse: collapse; margin-bottom: 6px;">
          <tr>
            <td style="font-size: 10.5px; font-weight: bold; color: #0f172a;">
              Assignment #<?= $rid ?>: <?= htmlspecialchars($req['task_name'] ?: 'University Service') ?>
            </td>
            <td style="text-align: right;">
              <span class="badge <?= $rStatus === 'ACTIVE' ? 'badge-active' : 'badge-completed' ?>">
                <?= $rStatus === 'ACTIVE' ? 'ONGOING SERVICE' : 'COMPLETED &amp; CLEARED' ?>
              </span>
            </td>
          </tr>
          <tr>
            <td colspan="2" style="font-size: 9.5px; color: #475569; padding-top: 3px;">
              Required Time: <strong><?= formatHrsMins($hrsReq) ?></strong> (<?= number_format($hrsReq, 2) ?> hrs) •
              Completed: <strong style="color: #15803d;"><?= formatHrsMins($totServed) ?></strong> (<?= number_format($totServed, 2) ?> hrs) •
              Remaining: <strong style="color: <?= $hrsLeft > 0 ? '#b91c1c' : '#15803d'; ?>"><?= formatHrsMins($hrsLeft) ?></strong>
              <?php if (!empty($req['completed_at'])): ?>
                • Completed On: <strong><?= ph_date('M j, Y g:i A', $req['completed_at']) ?></strong>
              <?php endif; ?>
            </td>
          </tr>
        </table>

        <!-- Sessions Log for this Requirement -->
        <?php if (empty($reqSessions)): ?>
          <div style="font-size: 9px; color: #94a3b8; font-style: italic; padding: 4px 0;">No clock-in attendance sessions recorded yet for this assignment.</div>
        <?php else: ?>
          <table class="data-table" style="margin-bottom: 0;">
            <thead>
              <tr>
                <th style="width: 18%;">Time In</th>
                <th style="width: 18%;">Time Out</th>
                <th style="width: 12%;">Duration</th>
                <th style="width: 12%;">Method</th>
                <th style="width: 15%;">Status</th>
                <th style="width: 25%;">Validator Notes</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($reqSessions as $s): ?>
                <tr>
                  <td><?= !empty($s['time_in']) ? ph_date('M j, Y g:i A', $s['time_in']) : '—' ?></td>
                  <td><?= !empty($s['time_out']) ? ph_date('M j, Y g:i A', $s['time_out']) : '<em style="color:#2563eb;">Ongoing Session</em>' ?></td>
                  <td style="font-weight: bold;"><?= formatHrsMins((float)($s['hours_done'] ?? 0)) ?></td>
                  <td><?= htmlspecialchars($s['login_method'] ?: 'RFID') ?></td>
                  <td>
                    <span class="badge <?= strtoupper((string)$s['status']) === 'COMPLETED' ? 'badge-completed' : 'badge-active' ?>">
                      <?= htmlspecialchars(strtoupper((string)($s['status'] ?? 'COMPLETED'))) ?>
                    </span>
                  </td>
                  <td><?= htmlspecialchars($s['sdo_notes'] ?: 'Validated by SDO Officer #' . ($s['validated_by'] ?? '1')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <!-- 3. UPCC Disciplinary Committee Cases -->
  <div class="section-title">3. Disciplinary Committee Cases (UPCC Proceedings)</div>
  <?php if (empty($cases)): ?>
    <div style="padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; color: #64748b; font-style: italic; margin-bottom: 12px;">
      ✓ No formal UPCC disciplinary cases have been filed against this student.
    </div>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th style="width: 10%;">Case #</th>
          <th style="width: 14%;">Filing Date</th>
          <th style="width: 20%;">Proceedings Status</th>
          <th style="width: 26%;">Violation Summary</th>
          <th style="width: 30%;">Decided Sanction / Category</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($cases as $c): ?>
          <?php
            $catNum = (int)($c['decided_category'] ?? 0);
            $catText = $categoryNames[$catNum] ?? 'Under Panel Evaluation';
            $cStatus = strtoupper((string)($c['status'] ?? ''));
            $isCasePending = in_array($cStatus, $pendingStatuses, true);
          ?>
          <tr>
            <td><strong>#<?= (int)$c['case_id'] ?></strong></td>
            <td><?= ph_date('M j, Y', $c['created_at']) ?></td>
            <td>
              <span class="badge <?= $isCasePending ? 'badge-major' : 'badge-completed' ?>">
                <?= htmlspecialchars($cStatus) ?>
              </span>
              <?php if (!empty($c['hearing_date'])): ?>
                <div style="font-size: 8.5px; color: #475569; margin-top: 2px;">Hearing: <?= ph_date('M j, Y', $c['hearing_date']) ?></div>
              <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($c['case_summary'] ?: 'University Disciplinary Violation') ?></td>
            <td>
              <strong><?= htmlspecialchars($catText) ?></strong>
              <?php if (!empty($c['final_decision'])): ?>
                <div style="font-size: 8.5px; color: #475569; margin-top: 2px;">Decision: <?= htmlspecialchars($c['final_decision']) ?></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- 4. Chronological Offense History Log -->
  <div class="section-title">4. Complete Recorded Offenses Log</div>
  <?php if (empty($offenses)): ?>
    <div style="padding: 8px 12px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 4px; color: #64748b; font-style: italic; margin-bottom: 12px;">
      ✓ Clean Record. Zero violations or infraction slips recorded in database.
    </div>
  <?php else: ?>
    <table class="data-table">
      <thead>
        <tr>
          <th style="width: 6%;">#</th>
          <th style="width: 15%;">Date &amp; Time</th>
          <th style="width: 12%;">Severity</th>
          <th style="width: 14%;">Offense Code</th>
          <th style="width: 25%;">Offense Description</th>
          <th style="width: 28%;">Incident Details / Notes</th>
        </tr>
      </thead>
      <tbody>
        <?php $idx = 1; foreach ($offenses as $o): ?>
          <?php
            $lvl = strtoupper((string)($o['level'] ?? 'MINOR'));
            $badgeClass = ($lvl === 'MAJOR') ? 'badge-major' : (($lvl === 'MINOR') ? 'badge-minor' : 'badge-dismissed');
          ?>
          <tr>
            <td><?= $idx++ ?></td>
            <td><?= ph_date('M j, Y g:i A', $o['date_committed']) ?></td>
            <td><span class="badge <?= $badgeClass ?>"><?= htmlspecialchars($lvl) ?></span></td>
            <td><strong><?= htmlspecialchars($o['code'] ?? 'GEN') ?></strong></td>
            <td><?= htmlspecialchars($o['name'] ?? 'General Handbook Violation') ?></td>
            <td><?= htmlspecialchars($o['description'] ?: 'Official infraction slip logged in SDO system.') ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <!-- Official Certification Block -->
  <table class="footer-sign">
    <tr>
      <td style="width: 50%;">
        <div style="font-size: 9px; color: #64748b; line-height: 1.4;">
          <strong>Official System Verification:</strong><br>
          This document is generated directly from the IdentiTrack Student Conduct database.<br>
          Generated by: <strong><?= htmlspecialchars($adminUsername) ?></strong><br>
          Timestamp: <?= $generatedAt ?>
        </div>
      </td>
      <td style="width: 50%;">
        <div class="sign-line">
          STUDENT DISCIPLINE OFFICE (SDO)<br>
          <span style="font-size: 8.5px; font-weight: normal; color: #64748b;">Authorized Signatory &amp; University Discipline Committee</span>
        </div>
      </td>
    </tr>
  </table>

  <div class="confidential-note">
    NOTICE: This document contains confidential student conduct information protected by university privacy policies and national education privacy laws.<br>
    Unauthorized distribution, copying, or disclosure is strictly prohibited. IdentiTrack System • National University Lipa.
  </div>

</body>
</html>
<?php
    $html = ob_get_clean();

    // 7. Render PDF via Dompdf
    require_once __DIR__ . '/../../vendor/autoload.php';

    $options = new \Dompdf\Options();
    $options->set('isHtml5ParserEnabled', true);
    $options->set('isRemoteEnabled', false);
    $options->set('defaultFont', 'Helvetica');

    // Make sure font cache directories are writable in production
    $tmpDir = sys_get_temp_dir();
    if (is_writable($tmpDir)) {
        $options->set('tempDir', $tmpDir);
        $options->set('fontDir', $tmpDir);
        $options->set('fontCache', $tmpDir);
    }
    $options->set('chroot', realpath(__DIR__ . '/../../'));

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    $cleanSid = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $studentId);
    $filename = "SDO_Student_Record_{$cleanSid}_" . date('Ymd_His') . ".pdf";

    $isInline = (isset($_GET['action']) && $_GET['action'] === 'view') || !empty($_GET['inline']);

    $dompdf->stream($filename, [
        'Attachment' => $isInline ? 0 : 1
    ]);
    exit;

} catch (\Throwable $e) {
    if (ob_get_level() > 0) {
        ob_end_clean();
    }
    error_log("PDF Export Error: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<div style="font-family: Arial, sans-serif; padding: 24px; max-width: 680px; margin: 40px auto; border: 1px solid #fca5a5; background: #fef2f2; border-radius: 8px;">';
    echo '<h3 style="color: #991b1b; margin-top: 0;">Unable to Generate PDF Document</h3>';
    echo '<p style="color: #7f1d1d; font-size: 13px;">An error occurred while compiling the student record: <strong>' . htmlspecialchars($e->getMessage()) . '</strong></p>';
    echo '<p style="font-size: 11px; color: #64748b;">Please verify that the student ID exists and that you are logged into the SDO Administrator Portal.</p>';
    echo '</div>';
    exit;
}
