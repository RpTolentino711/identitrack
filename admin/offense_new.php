<?php
// File: admin/offense_new.php
require_once __DIR__ . '/../database/database.php';
require_admin();

$activeSidebar = 'offenses';

$admin   = admin_current();
$adminId = (int)($admin['admin_id'] ?? 0);

$level = (string)($_GET['level'] ?? $_POST['level'] ?? 'MINOR');
if ($level !== 'MINOR' && $level !== 'MAJOR') $level = 'MINOR';

$category = (int)($_GET['major_category'] ?? $_POST['major_category'] ?? 0);
if ($category < 0 || $category > 5) $category = 0;

$studentIdPrefill = trim((string)($_GET['student_id'] ?? ''));

$categoryDescriptions = [
  1 => 'Probation for three (3) academic terms and referral for counseling.',
  2 => 'Formative Intervention (university service, counseling, education program).',
  3 => 'Non-Readmission for the next term.',
  4 => 'Exclusion (immediate removal from roll).',
  5 => 'Expulsion (disqualified from all HEIs in Philippines).',
];

// ── Load offense types ──────────────────────────────────────────────────────
$offenseTypes       = [];
$postExistingTypeId = (int)($_POST['offense_type_id'] ?? 0);

if ($level === 'MINOR') {
  $offenseTypes = db_all(
    "SELECT offense_type_id, code, name FROM offense_type
     WHERE is_active = 1 AND level = 'MINOR' ORDER BY code ASC",
    []
  ) ?: [];
}
// Append the "Other" option to the end of the list
if ($level === 'MINOR') {
    $offenseTypes[] = ['offense_type_id' => 22, 'code' => 'OTHER', 'name' => 'Other / Custom Minor Offense'];
} else if ($level === 'MAJOR') {
    $offenseTypes[] = ['offense_type_id' => 23, 'code' => 'OTHER', 'name' => 'Other / Custom Major Offense'];
}

// ── Handle POST (save offense) ─────────────────────────────────────────────
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['_action_hint'] ?? '') === 'save') {

  $student_id       = trim((string)($_POST['student_id']     ?? ''));
  $date_committed   = trim((string)($_POST['date_committed'] ?? ''));
  $description      = trim((string)($_POST['description']    ?? ''));
  $existing_type_id = (int)($_POST['offense_type_id'] ?? 0);

  if ($student_id     === '') $errors[] = 'Student ID is required.';
  if ($date_committed === '') $errors[] = 'Date & time of incident is required.';

  if ($student_id !== '') {
    $s = db_one("SELECT student_id FROM student WHERE student_id = :sid LIMIT 1", [':sid' => $student_id]);
    if (!$s) $errors[] = 'Student not found in the system.';
  }

  if ($existing_type_id <= 0) {
    $errors[] = 'Please select an offense type.';
  } else if (in_array($existing_type_id, [22, 23], true) && $description === '') {
    $errors[] = 'Please provide a detailed description for this custom offense.';
  }

  if (empty($errors)) {

    db_exec(
      "INSERT INTO offense (student_id, recorded_by, offense_type_id, level, description, date_committed, status, created_at, updated_at)
       VALUES (:sid, :admin, :tid, :lvl, :desc, :dt, 'OPEN', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
      [
        ':sid'   => $student_id,
        ':admin' => $adminId,
        ':tid'   => $existing_type_id,
        ':lvl'   => $level,
        ':desc'  => ($description === '' ? null : $description),
        ':dt'    => $date_committed,
      ]
    );

    $newRow       = db_one(
      "SELECT offense_id FROM offense WHERE student_id = :sid AND recorded_by = :aid ORDER BY offense_id DESC LIMIT 1",
      [':sid' => $student_id, ':aid' => $adminId]
    );
    $newOffenseId = (int)($newRow['offense_id'] ?? 0);

    // ── AFTER INSERT LOGIC ────────────────────────────────────────────────
    if ($level === 'MINOR') {
      $afterRow = db_one(
        "SELECT COUNT(*) AS cnt FROM offense WHERE student_id = :sid AND level = 'MINOR'",
        [':sid' => $student_id]
      );
      $afterMinor = (int)($afterRow['cnt'] ?? 0);

      $existingSection4Case = db_one(
        "SELECT case_id, created_at FROM upcc_case
         WHERE student_id = :sid
           AND status IN ('PENDING','UNDER_APPEAL')
           AND case_kind = 'SECTION4_MINOR_ESCALATION'
         ORDER BY created_at ASC
         LIMIT 1",
        [':sid' => $student_id]
      );

      if ($existingSection4Case) {
        redirect('offenses.php?msg=Minor+offense+recorded.+Student+already+under+Section+4+investigation.');
      }

      if ($afterMinor >= 3) {
        db_exec(
          "INSERT INTO upcc_case (student_id, created_by, status, case_kind, case_summary, created_at, updated_at)
           VALUES (:sid, :aid, 'PENDING', 'SECTION4_MINOR_ESCALATION', :summary, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
          [
            ':sid'     => $student_id,
            ':aid'     => $adminId,
            ':summary' => 'Section 4 Major — 3rd Minor attempt → Referred to UPCC panel for investigation and category assignment (1‑5).',
          ]
        );
        $caseId = db_last_id();

        // Fetch the 3 oldest minor offenses (the ones that triggered Section 4)
        $triggerMinors = db_all(
          "SELECT offense_id FROM offense
           WHERE student_id = :sid AND level = 'MINOR'
           ORDER BY date_committed ASC
           LIMIT 3",
          [':sid' => $student_id]
        );
        foreach ($triggerMinors as $minor) {
          db_exec(
            "INSERT INTO upcc_case_offense (case_id, offense_id) VALUES (:case_id, :offense_id)",
            [':case_id' => $caseId, ':offense_id' => $minor['offense_id']]
          );
        }

        redirect('offense_new.php?level=MINOR&student_id=' . urlencode($student_id) . '&letter=1&offense_id=' . $newOffenseId . '&type=escalation&success=1');
      } elseif ($afterMinor >= 2) {
        redirect('offense_new.php?level=MINOR&student_id=' . urlencode($student_id) . '&letter=1&offense_id=' . $newOffenseId . '&type=letter&minor_no=' . $afterMinor . '&success=1');
      }

      redirect('offense_new.php?level=MINOR&student_id=' . urlencode($student_id) . '&success=1');

    } elseif ($level === 'MAJOR') {
      db_exec(
        "INSERT INTO upcc_case (student_id, created_by, status, case_kind, case_summary, created_at, updated_at)
         VALUES (:sid, :aid, 'PENDING', 'MAJOR_OFFENSE', :summary, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
        [
          ':sid'     => $student_id,
          ':aid'     => $adminId,
          ':summary' => 'Major Offense - Category ' . $category . ' - UPCC investigation required',
        ]
      );
      $caseId = db_last_id();

      // Link the newly created offense to the case
      db_exec(
        "INSERT INTO upcc_case_offense (case_id, offense_id) VALUES (:case_id, :offense_id)",
        [':case_id' => $caseId, ':offense_id' => $newOffenseId]
      );

      redirect('offense_new.php?level=MAJOR&student_id=' . urlencode($student_id) . '&letter=1&offense_id=' . $newOffenseId . '&type=major&success=1');
    }

    redirect('offense_new.php?level=' . urlencode($level) . '&student_id=' . urlencode($student_id) . '&success=1');
  }
}

// ── Handle AJAX ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
  header('Content-Type: application/json');
  $action = $_POST['action'] ?? '';

  if ($action === 'add_offense_type') {
    $code      = trim($_POST['code'] ?? '');
    $name      = trim($_POST['name'] ?? '');
    $lvl       = $_POST['level'] ?? 'MINOR';
    $major_cat = isset($_POST['major_category']) ? (int)$_POST['major_category'] : null;

    if (empty($code) || empty($name)) {
      echo json_encode(['ok' => false, 'error' => 'Code and Name are required.']);
      exit;
    }
    if ($lvl === 'MAJOR' && ($major_cat < 1 || $major_cat > 5)) {
      echo json_encode(['ok' => false, 'error' => 'Major offense requires a valid category (1-5).']);
      exit;
    }
    try {
      db_exec(
        "INSERT INTO offense_type (code, name, level, major_category, is_active, created_at, updated_at)
         VALUES (:code, :name, :lvl, :cat, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
        [':code' => $code, ':name' => $name, ':lvl' => $lvl, ':cat' => $lvl === 'MAJOR' ? $major_cat : null]
      );
      echo json_encode(['ok' => true, 'message' => 'Offense type added.']);
    } catch (Exception $e) {
      echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
  }

  if ($action === 'list_offense_types') {
    $lvl = $_POST['level'] ?? 'MINOR';
    $cat = isset($_POST['major_category']) ? (int)$_POST['major_category'] : 0;
    if ($lvl === 'MAJOR' && $cat >= 1 && $cat <= 5) {
      $rows = db_all("SELECT offense_type_id, code, name FROM offense_type WHERE is_active = 1 AND level = 'MAJOR' AND major_category = :cat ORDER BY code ASC", [':cat' => $cat]) ?: [];
      $rows[] = ['offense_type_id' => 23, 'code' => 'OTHER', 'name' => 'Other / Custom Major Offense'];
    } else {
      $rows = db_all("SELECT offense_type_id, code, name FROM offense_type WHERE is_active = 1 AND level = 'MINOR' ORDER BY code ASC") ?: [];
      $rows[] = ['offense_type_id' => 22, 'code' => 'OTHER', 'name' => 'Other / Custom Minor Offense'];
    }
    echo json_encode(['ok' => true, 'types' => $rows]);
    exit;
  }

  echo json_encode(['ok' => false, 'error' => 'Invalid action']);
  exit;
}

// ── Sticky / prefill values ──────────────────────────────────────────────────
$postStudentId = (string)($_POST['student_id'] ?? $studentIdPrefill);
$defaultDate   = date('Y-m-d\TH:i');
$postDate      = (string)($_POST['date_committed'] ?? $defaultDate);
$postDesc      = (string)($_POST['description']    ?? '');

// ── Letter mode ──────────────────────────────────────────────────────────────
$letterMode      = ((int)($_GET['letter'] ?? 0) === 1);
$letterOffenseId = (int)($_GET['offense_id'] ?? 0);
$letterType      = (string)($_GET['type'] ?? '');
$successMode     = ((int)($_GET['success'] ?? 0) === 1);

// ── Live student data ─────────────────────────────────────────────────────────
$liveMinorCount      = 0;
$liveMajorCount      = 0;
$liveGuardianEmail   = '';
$liveActiveUpccCases = [];
$hasActiveSection4   = false;
$section4StartDate   = null;
$postSection4Minors  = 0;
$studentInfo         = null;

if ($postStudentId !== '') {
  $studentInfo = db_one(
    "SELECT student_id, student_fn, student_ln, year_level, section, school, program, student_email, phone_number
     FROM student WHERE student_id = :sid LIMIT 1",
    [':sid' => $postStudentId]
  );

  $mRow = db_one(
    "SELECT COUNT(*) AS cnt FROM offense WHERE student_id = :sid AND level = 'MINOR'",
    [':sid' => $postStudentId]
  );
  $liveMinorCount = (int)($mRow['cnt'] ?? 0);

  $mjRow = db_one(
    "SELECT COUNT(*) AS cnt FROM offense WHERE student_id = :sid AND level = 'MAJOR'",
    [':sid' => $postStudentId]
  );
  $liveMajorCount = (int)($mjRow['cnt'] ?? 0);

  $gRow = db_one(
    "SELECT guardian_email FROM guardian WHERE student_id = :sid LIMIT 1",
    [':sid' => $postStudentId]
  );
  $liveGuardianEmail = (string)($gRow['guardian_email'] ?? '');

  $liveActiveUpccCases = db_all(
    "SELECT case_id, status, case_kind, case_summary, created_at FROM upcc_case
     WHERE student_id = :sid AND status IN ('PENDING','UNDER_APPEAL')
     ORDER BY created_at DESC",
    [':sid' => $postStudentId]
  ) ?: [];

  foreach ($liveActiveUpccCases as $case) {
    if (($case['case_kind'] ?? '') === 'SECTION4_MINOR_ESCALATION') {
      $hasActiveSection4 = true;
      $section4StartDate = $case['created_at'];
      break;
    }
  }

  if ($hasActiveSection4 && $section4StartDate) {
    $countRow = db_one(
      "SELECT COUNT(*) AS cnt FROM offense
       WHERE student_id = :sid
         AND level = 'MINOR'
         AND date_committed > :start_date",
      [':sid' => $postStudentId, ':start_date' => $section4StartDate]
    );
    $postSection4Minors = (int)($countRow['cnt'] ?? 0);
  }
}

// ── Helper: render alert panel HTML ──────────────────────────────────────────
function renderMinorAlert(int $projectedCount, string $guardianEmail, int $currentCount = -1, bool $hasActiveSection4 = false, int $postSection4Minors = 0): string {

  if ($hasActiveSection4) {
    // New minor recorded after Section 4 is open:
    // Top bar = 3/3 locked (original trigger)
    // Bottom bar = fresh counter starting from 1/3
    $postProjected = $postSection4Minors + 1;
    $postPct       = min($postProjected, 3) / 3 * 100;
    $warningNote   = ($postProjected >= 3)
      ? '<div class="ap-warning">⚠️ This will be the 3rd offense since Section 4 — consider escalating to the panel.</div>'
      : '<div class="ap-subdesc">' . (3 - $postProjected) . ' more offense(s) recorded here will prompt another panel review.</div>';

    return '
    <div class="alert-panel alert-panel--critical">
      <div class="ap-icon">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
        </svg>
      </div>
      <div class="ap-body">
        <div class="ap-title">Active Section 4 Investigation</div>
        <div class="ap-desc">
          This student already has an open Section 4 case. This offense will <strong>not</strong> open a new case — it is tracked separately below.
        </div>

        <div class="ap-track-label">Original trigger</div>
        <div class="ap-progress" style="margin-bottom:14px;padding:10px 12px;background:rgba(0,0,0,.04);border-radius:8px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <span style="font-size:11px;font-weight:600;">Section 4 triggered</span>
            <span style="font-size:11px;font-weight:800;color:var(--pink);">3 / 3 — Section 4 Major Investigation</span>
          </div>
          <div class="ap-progress-track">
            <div class="ap-progress-fill ap-progress--critical" style="width:100%"></div>
          </div>
        </div>

        <div class="ap-track-label">Additional Violations</div>
        <div class="ap-progress" style="padding:10px 12px;background:rgba(0,0,0,.04);border-radius:8px;">
          <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
            <span style="font-size:11px;font-weight:600;">Post-Escalation Minors</span>
            <span style="font-size:11px;font-weight:800;color:var(--red);">' . $postProjected . ' Recorded</span>
          </div>
          <div class="ap-progress-track">
            <div class="ap-progress-fill ap-progress--critical" style="width:100%"></div>
          </div>
        </div>
        <div class="ap-warning" style="margin-top:8px;">⚠️ The student is already under a <strong>Section 4 Major</strong> investigation. Any further minor offenses are considered a critical breach of conduct and will be added to the current UPCC case for immediate escalation.</div>
      </div>
    </div>';
  }

  if ($currentCount < 0) $currentCount = $projectedCount - 1;
  $pctMap = [1 => 33, 2 => 66, 3 => 100];
  $pct    = $pctMap[min($projectedCount, 3)] ?? 100;

  if ($projectedCount === 1) {
    return '
    <div class="alert-panel alert-panel--info">
      <div class="ap-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
      <div class="ap-body">
        <div class="ap-title">1st Minor – Warning</div>
        <div class="ap-projected-badge ap-projected--info">📋 Currently ' . $currentCount . ' → becomes <strong>1/3</strong></div>
        <div class="ap-progress"><div class="ap-progress-track"><div class="ap-progress-fill ap-progress--info" style="width:' . $pct . '%"></div></div><span class="ap-progress-label">1/3 – 2 more to Section 4</span></div>
        <div class="ap-desc">Warning only. No letter required.</div>
        <div class="ap-steps">
          <div class="ap-step ap-step--next">1st Minor ⬅ Warning</div>
          <div class="ap-step">2nd Minor → Letter</div>
          <div class="ap-step">3rd Minor → Section 4 Panel</div>
        </div>
      </div>
    </div>';
  }

  if ($projectedCount === 2) {
    $emailHtml = $guardianEmail
      ? '<div class="ap-email"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>' . htmlspecialchars($guardianEmail) . '</div>'
      : '<div class="ap-email ap-email--warn"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>No guardian email on file</div>';
    return '
    <div class="alert-panel alert-panel--warning">
      <div class="ap-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
      <div class="ap-body">
        <div class="ap-title">2nd Minor – Letter to Guardian</div>
        <div class="ap-projected-badge ap-projected--warning">📋 Currently ' . $currentCount . ' → becomes <strong>2/3</strong></div>
        <div class="ap-progress"><div class="ap-progress-track"><div class="ap-progress-fill ap-progress--warning" style="width:' . $pct . '%"></div></div><span class="ap-progress-label">2/3 – 1 more to Section 4</span></div>
        <div class="ap-desc">A formal notice will be sent to the guardian after saving.</div>
        ' . $emailHtml . '
        <div class="ap-steps">
          <div class="ap-step ap-step--done">1st Minor ✓</div>
          <div class="ap-step ap-step--next">2nd Minor ⬅ Letter</div>
          <div class="ap-step">3rd Minor → Section 4 Panel</div>
        </div>
      </div>
    </div>';
  }

  // projectedCount >= 3
  $emailHtml = $guardianEmail
    ? '<div class="ap-email"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>' . htmlspecialchars($guardianEmail) . '</div>'
    : '<div class="ap-email ap-email--warn"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>No guardian email on file</div>';

  return '
  <div class="alert-panel alert-panel--critical">
    <div class="ap-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div>
    <div class="ap-body">
      <div class="ap-title">⚖️ 3rd Minor – Becomes Section 4 Major</div>
      <div class="ap-projected-badge ap-projected--critical">🚨 Currently ' . $currentCount . ' → becomes <strong>' . $projectedCount . '/3 – SECTION 4 MAJOR</strong></div>
      <div class="ap-progress"><div class="ap-progress-track"><div class="ap-progress-fill ap-progress--critical" style="width:100%"></div></div><span class="ap-progress-label">3/3 – Panel investigation triggered</span></div>
      <div class="ap-desc">Student referred to UPCC panel. The panel will assign a Category 1–5 sanction.</div>
      ' . $emailHtml . '
      <div class="ap-checklist">
        <div class="ap-check">✓ UPCC case will be created</div>
        <div class="ap-check">✓ Panel assigns category (1–5)</div>
        <div class="ap-check">✓ Guardian letter generated</div>
      </div>
      <div class="ap-steps">
        <div class="ap-step ap-step--done">1st Minor ✓</div>
        <div class="ap-step ap-step--done">2nd Minor ✓</div>
        <div class="ap-step ap-step--critical">3rd Minor ⬅ Section 4 Panel</div>
      </div>
    </div>
  </div>';
}

function renderMajorAlert(int $majorCount, array $upccCases): string {
  $caseCount     = count($upccCases);
  $categoryNames = [1 => 'Probation', 2 => 'Formative Intervention', 3 => 'Non-Readmission', 4 => 'Exclusion', 5 => 'Expulsion'];

  $casesHtml = '';
  if ($caseCount > 0) {
    foreach ($upccCases as $c) {
      $summary      = (string)($c['case_summary'] ?? '');
      $offenseType  = 'Under Investigation';
      $offenseStatus = '';

      if (strpos($summary, 'Section 4') !== false || ($c['case_kind'] ?? '') === 'SECTION4_MINOR_ESCALATION') {
        $offenseType   = 'Section 4 Panel Case';
        $offenseStatus = 'Awaiting category';
      } elseif (preg_match('/Major Offense - Category (\d)/', $summary, $m)) {
        $cat           = (int)$m[1];
        $offenseType   = 'Major Offense';
        $offenseStatus = 'Category ' . $cat . ' (' . ($categoryNames[$cat] ?? '') . ')';
      }

      $casesHtml .= '
      <div class="ap-case">
        <div class="ap-case-header">
          <span class="ap-case-id">Case #' . (int)$c['case_id'] . '</span>
          <span class="ap-case-badge">' . htmlspecialchars(strtoupper((string)($c['status'] ?? ''))) . '</span>
        </div>
        <div class="ap-case-type">' . htmlspecialchars($offenseType) . '</div>
        ' . ($offenseStatus ? '<div class="ap-case-status">' . htmlspecialchars($offenseStatus) . '</div>' : '') . '
      </div>';
    }
  } else {
    $casesHtml = '<div class="ap-empty"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg><p>No active UPCC cases</p></div>';
  }

  return '
  <div class="alert-panel alert-panel--major">
    <div class="ap-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
    <div class="ap-body">
      <div class="ap-title">⚠️ Major Offense – UPCC Case Required</div>
      <div class="ap-stat-row">
        <div class="ap-stat"><div class="ap-stat-val" style="color:var(--red)">' . $majorCount . '</div><div class="ap-stat-lbl">Major Offenses</div></div>
        <div class="ap-stat"><div class="ap-stat-val" style="color:var(--amber)">' . $caseCount . '</div><div class="ap-stat-lbl">Active Cases</div></div>
      </div>
      <div class="ap-desc">Saving will auto-create a UPCC case and generate a guardian letter.</div>
      <div style="font-size:11px;font-weight:800;color:var(--red);text-transform:uppercase;margin-bottom:6px;">Active Cases</div>
      <div class="ap-cases">' . $casesHtml . '</div>
    </div>
  </div>';
}

function renderStudentInfoCard($student, $guardianEmail, $minorCount = 0, $majorCount = 0, $activeCases = []) {
  if (!$student) return '';
  $fullName    = htmlspecialchars($student['student_fn'] . ' ' . $student['student_ln']);
  $studentId   = htmlspecialchars($student['student_id']);
  $yearSection = htmlspecialchars($student['year_level'] . ' - ' . ($student['section'] ?? 'N/A'));
  $program     = htmlspecialchars($student['program'] ?? 'N/A');
  $school      = htmlspecialchars($student['school'] ?? 'NU Lipa');
  $email       = htmlspecialchars($student['student_email'] ?? '');
  $guardian    = $guardianEmail ? htmlspecialchars($guardianEmail) : '<span class="text-muted">Not provided</span>';

  $minorText = $minorCount === 1 ? '1 Minor Offense' : $minorCount . ' Minor Offenses';
  $majorText = $majorCount === 1 ? '1 Major Offense' : $majorCount . ' Major Offenses';

  $caseRows = '';
  if (!empty($activeCases)) {
    $firstCaseId = (int)$activeCases[0]['case_id'];
    $studentIdEnc = urlencode($student['student_id']);
    
    if ($majorCount > 0) {
      $majorText = '<a href="upcc_cases.php?student_id=' . $studentIdEnc . '" style="color: var(--blue); text-decoration: underline; cursor: pointer;" title="View Student Active Cases">' . $majorText . '</a>';
    } elseif ($minorCount >= 3) {
      $minorText = '<a href="upcc_cases.php?student_id=' . $studentIdEnc . '" style="color: var(--blue); text-decoration: underline; cursor: pointer;" title="View Student Active Cases">' . $minorText . '</a>';
    }

    $caseHtml = '';
    foreach ($activeCases as $case) {
      $caseId = (int)$case['case_id'];
      $caseHtml .= '<a href="upcc_case_view.php?id=' . $caseId . '" class="btn" style="margin-top: 6px; width: 100%; display: flex; justify-content: center; padding: 6px 12px; font-size: 12px; border-color: var(--blue); color: var(--blue);"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;margin-right:4px;"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg> View UPCC Case #' . $caseId . '</a>';
    }
    $caseRows = '
      <div class="sic-row" style="align-items: center;">
        <span class="sic-label">Active Case:</span>
        <div style="flex: 1;">' . $caseHtml . '</div>
      </div>';
  }

  $historyUrl = 'offenses_student_view.php?student_id=' . urlencode($student['student_id']);

  return '
  <div class="student-info-card">
    <div class="sic-header">
      <div class="sic-avatar">
        <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M15 9h6m-6 3h6m-6 3h6M3 9h6m-6 3h6m-6 3h6M9 3v18M3 3h18a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/></svg>
      </div>
      <div class="sic-title">Student Information</div>
    </div>
    <div class="sic-body">
      <div class="sic-row"><span class="sic-label">Full Name:</span><span class="sic-value">' . $fullName . '</span></div>
      <div class="sic-row"><span class="sic-label">Student ID:</span><span class="sic-value">' . $studentId . '</span></div>
      <div class="sic-row"><span class="sic-label">Year &amp; Section:</span><span class="sic-value">' . $yearSection . '</span></div>
      <div class="sic-row"><span class="sic-label">Program:</span><span class="sic-value">' . $program . '</span></div>
      <div class="sic-row"><span class="sic-label">School:</span><span class="sic-value">' . $school . '</span></div>
      <div class="sic-row"><span class="sic-label">Student Email:</span><span class="sic-value">' . $email . '</span></div>
      <div class="sic-row"><span class="sic-label">Guardian Email:</span><span class="sic-value">' . $guardian . '</span></div>
      
      <div style="margin: 14px -22px; border-top: 1px solid var(--border);"></div>
      
      <div class="sic-row" style="align-items: center;">
        <span class="sic-label">Records:</span>
        <span class="sic-value" style="font-weight: 700; color: var(--blue);">' . $minorText . ', ' . $majorText . '</span>
      </div>
      ' . $caseRows . '
      <div style="margin-top: 12px;">
        <a href="' . $historyUrl . '" class="btn" style="width: 100%; display: flex; justify-content: center; padding: 7px 12px; font-size: 12px; background: var(--surface-2);">
          View Full History
        </a>
      </div>
    </div>
  </div>';
}

function renderStudentRecordModal($student, $guardianEmail, int $minorCount, int $majorCount, array $activeCases, bool $hasActiveSection4, int $section4Minors) {
  if (!$student || ($minorCount + $majorCount === 0 && empty($activeCases))) {
    return '';
  }

  $fullName   = htmlspecialchars($student['student_fn'] . ' ' . $student['student_ln']);
  $studentId  = htmlspecialchars($student['student_id']);
  $yearSection = htmlspecialchars($student['year_level'] . ' - ' . ($student['section'] ?? 'N/A'));
  $program    = htmlspecialchars($student['program'] ?? 'N/A');
  $school     = htmlspecialchars($student['school'] ?? 'NU Lipa');
  $email      = htmlspecialchars($student['student_email'] ?? 'Not provided');
  $guardian   = $guardianEmail ? htmlspecialchars($guardianEmail) : 'Not provided';
  $statusNote = $hasActiveSection4 ? '<div class="ap-warning" style="margin-top:12px;">⚠️ This student has an active Section 4 investigation.</div>' : '';

  $caseItems = '';
  if (!empty($activeCases)) {
    foreach ($activeCases as $case) {
      $caseId = (int)$case['case_id'];
      $caseTitle = htmlspecialchars('Case #' . $caseId . ' · ' . strtoupper((string)($case['status'] ?? '')));
      $caseSummary = htmlspecialchars((string)($case['case_summary'] ?? 'No summary'));
      $caseItems .= '<a href="upcc_case_view.php?id=' . $caseId . '" class="ap-case" target="_blank" rel="noreferrer noopener">'
        . '<div class="ap-case-header"><span class="ap-case-id">' . $caseTitle . '</span></div>'
        . '<div class="ap-case-type">' . $caseSummary . '</div>'
        . '</a>';
    }
  } else {
    $caseItems = '<div class="ap-empty"><p>No active UPCC cases.</p></div>';
  }

  $minorText = $minorCount === 1 ? '1 Minor Offense' : $minorCount . ' Minor Offenses';
  $majorText = $majorCount === 1 ? '1 Major Offense' : $majorCount . ' Major Offenses';

  return '
  <div id="studentRecordModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Student Record Found</h3>
        <button class="modal-close" onclick="closeStudentRecordModal()">&times;</button>
      </div>
      <div class="modal-body">
        <div class="student-info-card" style="margin-bottom:16px;">
          <div class="sic-header">
            <div class="sic-avatar"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M15 9h6m-6 3h6m-6 3h6M3 9h6m-6 3h6m-6 3h6M9 3v18M3 3h18a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/></svg></div>
            <div class="sic-title">Student Information</div>
          </div>
          <div class="sic-body">
            <div class="sic-row"><span class="sic-label">Full Name:</span><span class="sic-value">' . $fullName . '</span></div>
            <div class="sic-row"><span class="sic-label">Student ID:</span><span class="sic-value">' . $studentId . '</span></div>
            <div class="sic-row"><span class="sic-label">Year & Section:</span><span class="sic-value">' . $yearSection . '</span></div>
            <div class="sic-row"><span class="sic-label">Program:</span><span class="sic-value">' . $program . '</span></div>
            <div class="sic-row"><span class="sic-label">School:</span><span class="sic-value">' . $school . '</span></div>
            <div class="sic-row"><span class="sic-label">Student Email:</span><span class="sic-value">' . $email . '</span></div>
            <div class="sic-row"><span class="sic-label">Guardian Email:</span><span class="sic-value">' . $guardian . '</span></div>
          </div>
        </div>
        <div class="alert-panel alert-panel--info" style="padding:16px; margin-bottom:16px;">
          <div class="ap-body">
            <div class="ap-title">Existing records</div>
            <div class="ap-desc">' . $minorText . ', ' . $majorText . '.</div>
            ' . $statusNote . '
          </div>
        </div>
        <div class="ap-cases">' . $caseItems . '</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary" onclick="closeStudentRecordModal()">Continue</button>
      </div>
    </div>
  </div>';
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Register Offense | SDO Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
      font-family: 'Sora', sans-serif;
      background: #f1f5f9;
      color: #0f172a;
      font-size: 14px;
    }

    :root {
      --navy:       #0a1628;
      --blue:       #1d4ed8;
      --blue-h:     #1e40af;
      --blue-soft:  #eff6ff;
      --blue-mid:   #dbeafe;
      --red:        #dc2626;
      --red-soft:   #fef2f2;
      --red-mid:    #fee2e2;
      --amber:      #b45309;
      --amber-soft: #fffbeb;
      --amber-mid:  #fde68a;
      --green:      #15803d;
      --green-soft: #f0fdf4;
      --green-mid:  #bbf7d0;
      --pink:       #be185d;
      --pink-soft:  #fdf2f8;
      --pink-mid:   #fbcfe8;
      --border:     #e2e8f0;
      --bg:         #f1f5f9;
      --surface:    #ffffff;
      --surface-2:  #f8fafc;
      --text-1:     #0f172a;
      --text-2:     #334155;
      --text-3:     #64748b;
      --text-4:     #94a3b8;
      --radius:     14px;
      --radius-sm:  8px;
      --shadow:     0 4px 16px rgba(15,27,61,.08), 0 2px 6px rgba(15,27,61,.05);
      --shadow-sm:  0 1px 3px rgba(15,27,61,.06);
    }

    .admin-shell {
      min-height: calc(100vh - 72px);
      display: grid;
      grid-template-columns: 240px 1fr;
    }
    .main-wrap { display: flex; flex-direction: column; min-height: 100%; }

    .page-header {
      background: var(--surface);
      border-bottom: 1px solid var(--border);
      padding: 16px 32px;
      display: flex;
      align-items: center;
      gap: 14px;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .back-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: var(--text-3);
      text-decoration: none;
      font-size: 13px;
      font-weight: 600;
      padding: 7px 14px;
      border-radius: var(--radius-sm);
      border: 1.5px solid var(--border);
      background: var(--surface);
      transition: all .18s;
    }
    .back-btn svg { width: 14px; height: 14px; }
    .back-btn:hover { border-color: var(--blue); color: var(--blue); background: var(--blue-soft); }
    .page-title { font-size: 17px; font-weight: 800; letter-spacing: -.3px; }
    .page-sub   { font-size: 12px; color: var(--text-4); margin-left: auto; font-weight: 500; }

    .content-grid {
      display: grid;
      grid-template-columns: 1fr 340px;
      gap: 20px;
      padding: 24px 32px;
      align-items: start;
    }

    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
    }
    .card-header {
      padding: 18px 22px 16px;
      border-bottom: 1px solid var(--border);
      background: linear-gradient(180deg,#fafcff 0%,var(--surface) 100%);
    }
    .card-header__title { font-size: 15px; font-weight: 700; letter-spacing: -.2px; }
    .card-header__sub   { font-size: 12px; color: var(--text-4); margin-top: 2px; }
    .card-body { padding: 22px; }

    .form-row {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 18px;
      margin-bottom: 18px;
    }
    .form-row.full { grid-template-columns: 1fr; }
    .form-group { display: flex; flex-direction: column; gap: 6px; }

    label {
      font-size: 11px;
      font-weight: 700;
      color: var(--text-3);
      text-transform: uppercase;
      letter-spacing: .6px;
    }

    input, select, textarea {
      width: 100%;
      border: 1.5px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 10px 13px;
      font-size: 13.5px;
      font-family: 'Sora', sans-serif;
      color: var(--text-1);
      background: var(--surface);
      outline: none;
      transition: border-color .18s, box-shadow .18s;
    }
    input:focus, select:focus, textarea:focus {
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(29,78,216,.1);
    }
    textarea { min-height: 100px; resize: vertical; }

    .field-hint {
      font-size: 11.5px;
      color: var(--text-4);
      line-height: 1.4;
    }

    .category-desc {
      margin-top: 8px;
      padding: 10px 12px;
      border-radius: var(--radius-sm);
      background: var(--blue-soft);
      border: 1px solid var(--blue-mid);
      font-size: 12px;
      color: var(--blue);
      font-weight: 500;
      line-height: 1.4;
    }

    .form-actions {
      display: flex;
      gap: 10px;
      margin-top: 22px;
      padding-top: 18px;
      border-top: 1px solid var(--border);
    }
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      padding: 11px 20px;
      border-radius: var(--radius-sm);
      font-size: 13.5px;
      font-weight: 700;
      font-family: 'Sora', sans-serif;
      cursor: pointer;
      border: 1.5px solid var(--border);
      background: var(--surface);
      color: var(--text-2);
      text-decoration: none;
      transition: all .18s;
    }
    .btn svg { width: 15px; height: 15px; }
    .btn:hover { background: var(--surface-2); border-color: var(--border); }
    .btn-primary {
      background: linear-gradient(135deg, var(--blue) 0%, #2563eb 100%);
      color: #fff;
      border-color: var(--blue);
      box-shadow: 0 2px 8px rgba(29,78,216,.3);
    }
    .btn-primary:hover {
      background: linear-gradient(135deg, var(--blue-h) 0%, var(--blue) 100%);
      box-shadow: 0 4px 16px rgba(29,78,216,.4);
      transform: translateY(-1px);
    }
    .btn-primary:active { transform: translateY(0); }
    .btn-circle {
      width: 36px;
      height: 36px;
      padding: 0;
      border-radius: 50%;
      justify-content: center;
      background: var(--surface-2);
      border: 1.5px solid var(--border);
    }
    .btn-circle svg { width: 18px; height: 18px; margin: 0; }
    .btn-circle:hover { background: var(--surface); border-color: var(--blue); color: var(--blue); }

    .errors {
      background: var(--red-soft);
      border: 1.5px solid #fca5a5;
      border-radius: var(--radius-sm);
      padding: 14px 16px;
      color: var(--red);
      margin-bottom: 20px;
      font-size: 13px;
    }
    .errors ul { margin: 0; padding-left: 18px; }
    .errors li { margin: 3px 0; font-weight: 600; }

    /* Student Info Card */
    .student-info-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      margin-bottom: 16px;
      overflow: hidden;
      box-shadow: var(--shadow-sm);
    }
    .sic-header {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 12px 16px;
      background: linear-gradient(135deg, var(--blue-soft) 0%, var(--surface) 100%);
      border-bottom: 1px solid var(--blue-mid);
    }
    .sic-avatar {
      width: 32px;
      height: 32px;
      background: var(--blue-mid);
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--blue);
    }
    .sic-avatar svg { width: 18px; height: 18px; }
    .sic-title { font-weight: 800; font-size: 13px; color: var(--blue); letter-spacing: -.2px; }
    .sic-body { padding: 12px 16px; display: flex; flex-direction: column; gap: 8px; }
    .sic-row { display: flex; font-size: 12px; line-height: 1.4; }
    .sic-label { width: 110px; font-weight: 700; color: var(--text-3); flex-shrink: 0; }
    .sic-value { color: var(--text-1); font-weight: 500; word-break: break-word; }
    .text-muted { color: var(--text-4); font-style: italic; }

    /* Alert panel */
    .alert-panel {
      border-radius: var(--radius);
      padding: 16px;
      display: flex;
      gap: 12px;
      margin-bottom: 12px;
      box-shadow: var(--shadow-sm);
      border: 1.5px solid transparent;
      animation: apIn .25s ease both;
    }
    @keyframes apIn {
      from { opacity: 0; transform: translateY(-6px); }
      to   { opacity: 1; transform: translateY(0); }
    }
    .alert-panel--info     { background: var(--blue-soft);  border-color: var(--blue-mid); color: #1e3a6e; }
    .alert-panel--warning  { background: var(--amber-soft); border-color: var(--amber-mid); color: #78350f; }
    .alert-panel--critical { background: var(--pink-soft);  border-color: var(--pink-mid); color: #831843; }
    .alert-panel--major    { background: var(--red-soft);   border-color: var(--red-mid); color: #7f1d1d; }
    .ap-icon {
      width: 34px; height: 34px;
      border-radius: 10px;
      display: grid;
      place-items: center;
      flex-shrink: 0;
    }
    .ap-icon svg { width: 18px; height: 18px; }
    .alert-panel--info     .ap-icon { background: var(--blue-mid);  color: var(--blue); }
    .alert-panel--warning  .ap-icon { background: var(--amber-mid); color: var(--amber); }
    .alert-panel--critical .ap-icon { background: var(--pink-mid);  color: var(--pink); }
    .alert-panel--major    .ap-icon { background: var(--red-mid);   color: var(--red); }
    .ap-body { flex: 1; min-width: 0; }
    .ap-title { font-size: 12.5px; font-weight: 800; letter-spacing: -.1px; margin-bottom: 8px; }
    .ap-track-label {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
      opacity: .6;
      margin-bottom: 5px;
    }
    .ap-progress { margin-bottom: 8px; }
    .ap-progress-track {
      height: 5px;
      border-radius: 999px;
      background: rgba(0,0,0,.08);
      overflow: hidden;
    }
    .ap-progress-fill {
      height: 100%;
      border-radius: 999px;
      transition: width .4s ease;
    }
    .ap-progress--info     { background: var(--blue); }
    .ap-progress--warning  { background: var(--amber); }
    .ap-progress--critical { background: var(--pink); }
    .ap-progress-label { font-size: 11px; font-weight: 600; opacity: .85; margin-top: 5px; display: block; }
    .ap-desc { font-size: 11.5px; line-height: 1.55; margin-bottom: 10px; opacity: .9; }
    .ap-subdesc {
      font-size: 11px;
      color: inherit;
      opacity: 0.75;
      margin-top: 8px;
      font-weight: 500;
      line-height: 1.5;
    }
    .ap-warning { font-size: 11px; font-weight: 700; color: var(--red); margin-top: 8px; }
    .ap-steps {
      display: flex;
      flex-direction: column;
      gap: 4px;
      margin-top: 10px;
      padding-top: 10px;
      border-top: 1px solid rgba(0,0,0,.07);
    }
    .ap-step {
      display: flex;
      align-items: center;
      font-size: 10.5px;
      font-weight: 600;
      padding: 4px 8px;
      border-radius: 6px;
      background: rgba(255,255,255,.5);
      color: inherit;
      opacity: .5;
    }
    .ap-step--done     { opacity: 1; background: rgba(255,255,255,.7); }
    .ap-step--done::before { content: '✓ '; color: var(--green); }
    .ap-step--critical { opacity: 1; background: rgba(255,255,255,.8); color: var(--pink); font-weight: 800; }
    .ap-step--critical::before { content: '⚖️ '; }
    .ap-step--next     { opacity: 1; background: rgba(255,255,255,.9); font-weight: 800; border: 1.5px solid rgba(0,0,0,.1); }
    .ap-step--next::before { content: '→ '; }
    .ap-projected-badge {
      font-size: 11px;
      font-weight: 700;
      padding: 6px 10px;
      border-radius: 7px;
      margin-bottom: 10px;
      line-height: 1.4;
      border: 1px solid rgba(0,0,0,.07);
    }
    .ap-projected--info     { background: rgba(255,255,255,.7); color: #1e3a6e; }
    .ap-projected--warning  { background: rgba(255,255,255,.7); color: #78350f; }
    .ap-projected--critical {
      background: rgba(255,255,255,.85);
      color: #831843;
      border-color: rgba(190,24,93,.2);
      animation: criticalPulse 2s ease infinite;
    }
    @keyframes criticalPulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(190,24,93,.0); }
      50%       { box-shadow: 0 0 0 4px rgba(190,24,93,.12); }
    }
    .ap-email {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 10.5px;
      font-weight: 600;
      padding: 5px 8px;
      border-radius: 6px;
      background: rgba(255,255,255,.6);
      border: 1px solid rgba(0,0,0,.06);
      margin-bottom: 8px;
      word-break: break-all;
    }
    .ap-email svg { width: 12px; height: 12px; flex-shrink: 0; }
    .ap-email--warn { color: var(--red); background: var(--red-soft); border-color: #fca5a5; }
    .ap-checklist { display: flex; flex-direction: column; gap: 3px; margin-bottom: 10px; }
    .ap-check { font-size: 11px; font-weight: 600; padding: 3px 0; color: var(--pink); opacity: .9; }
    .ap-cases { display: flex; flex-direction: column; gap: 7px; margin-top: 8px; }
    .ap-case {
      background: rgba(255,255,255,.7);
      border: 1px solid rgba(0,0,0,.07);
      border-radius: 8px;
      padding: 9px 11px;
    }
    .ap-case-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px; }
    .ap-case-id { font-size: 11px; font-weight: 800; font-family: 'JetBrains Mono', monospace; }
    .ap-case-badge { font-size: 9px; font-weight: 800; padding: 2px 6px; border-radius: 4px; background: rgba(0,0,0,.08); letter-spacing: .3px; }
    .ap-case-type  { font-size: 11px; font-weight: 700; margin-bottom: 2px; }
    .ap-case-status { font-size: 10.5px; font-weight: 500; opacity: .8; }
    .ap-stat-row { display: flex; gap: 8px; margin-bottom: 10px; }
    .ap-stat {
      flex: 1;
      background: rgba(255,255,255,.7);
      border: 1px solid rgba(0,0,0,.07);
      border-radius: 8px;
      padding: 8px 10px;
      text-align: center;
    }
    .ap-stat-val { font-size: 22px; font-weight: 800; letter-spacing: -1px; line-height: 1; }
    .ap-stat-lbl { font-size: 9.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; margin-top: 3px; opacity: .7; }
    .ap-empty { text-align: center; padding: 24px 12px; color: var(--text-4); }
    .ap-empty svg { width: 32px; height: 32px; margin-bottom: 8px; opacity: .3; }
    .ap-empty p { font-size: 12px; font-weight: 600; }
    .panel-placeholder {
      border: 1.5px dashed var(--border);
      border-radius: var(--radius);
      padding: 32px 16px;
      text-align: center;
      color: var(--text-4);
    }
    .panel-placeholder svg { width: 32px; height: 32px; margin-bottom: 10px; opacity: .3; }
    .panel-placeholder p { font-size: 12px; font-weight: 600; line-height: 1.5; }

    /* Letter */
    .letter-wrap { grid-column: 1 / -1; }
    .letter-card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
      margin-top: 4px;
    }
    .letter-card .card-header { border-left: 4px solid var(--blue); }
    .letter-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 22px; padding: 22px; }
    .letter-col h3 { font-size: 13px; font-weight: 700; color: var(--text-2); margin-bottom: 12px; letter-spacing: -.1px; }
    .letter-preview {
      background: var(--surface-2);
      border: 1.5px solid var(--border);
      border-radius: var(--radius-sm);
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
      color: var(--text-4);
      font-size: 13px;
      font-weight: 600;
    }
    .loading svg { animation: spin 1s linear infinite; width: 18px; height: 18px; }
    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .letter-msg { font-size: 12.5px; font-weight: 600; margin-top: 12px; }

    /* Modal */
    .modal {
      display: none;
      position: fixed;
      top: 0; left: 0;
      width: 100%; height: 100%;
      background: rgba(0,0,0,0.5);
      align-items: center;
      justify-content: center;
      z-index: 1000;
    }
    .modal.active { display: flex; }
    .modal-content {
      background: var(--surface);
      border-radius: var(--radius);
      width: 500px;
      max-width: 90%;
      box-shadow: var(--shadow);
    }
    .modal-header {
      padding: 16px 20px;
      border-bottom: 1px solid var(--border);
      display: flex;
      justify-content: space-between;
      align-items: center;
    }
    .modal-header h3 { font-size: 16px; font-weight: 700; }
    .modal-close { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-3); }
    .modal-body { padding: 20px; }
    .modal-footer {
      padding: 12px 20px;
      border-top: 1px solid var(--border);
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }

    @media (max-width: 1100px) {
      .content-grid { grid-template-columns: 1fr; }
      .letter-wrap  { grid-column: 1; }
    }
    @media (max-width: 1024px) {
      .admin-shell { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
      .content-grid { padding: 16px; }
      .form-row     { grid-template-columns: 1fr; }
      .letter-grid  { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>

  <script>
    // This page uses its own scanner behavior (auto-fill student field),
    // so skip sidebar's global redirect scanner listener here.
    window.__identitrackDisableGlobalScan = true;
  </script>

  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="main-wrap">

      <section class="page-header">
        <a class="back-btn" href="offenses.php">
          <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="15 18 9 12 15 6"/></svg>
          Back
        </a>
        <div>
          <div class="page-title">Register New Offense</div>
        </div>
        <div class="page-sub">Student Discipline Office</div>
      </section>

      <div class="content-grid">

        <!-- LEFT: FORM -->
        <div>
          <div class="card">
            <div class="card-header">
              <div class="card-header__title">Offense Details</div>
              <div class="card-header__sub">Fill in all required fields marked with *</div>
            </div>
            <div class="card-body">

              <?php if (!empty($errors)): ?>
                <div class="errors"><ul><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
              <?php endif; ?>

              <form method="post" id="offenseForm">

                <div class="form-row">
                  <div class="form-group">
                    <label for="level">Offense Level *</label>
                    <select id="levelSelect" name="level" onchange="onLevelChange(this.value)">
                      <option value="MINOR" <?php echo $level === 'MINOR' ? 'selected' : ''; ?>>Minor</option>
                      <option value="MAJOR" <?php echo $level === 'MAJOR' ? 'selected' : ''; ?>>Major</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label for="student_id">Student ID *</label>
                    <input id="studentIdInput" name="student_id"
                           value="<?php echo htmlspecialchars($postStudentId); ?>"
                           placeholder="e.g., 2024-01001"
                           autocomplete="off"/>
                  </div>
                </div>

                <div class="form-row" id="row2">
                  <div class="form-group">
                    <label for="date_committed">Date &amp; Time of Incident *</label>
                    <input id="date_committed" name="date_committed" type="datetime-local"
                           value="<?php echo htmlspecialchars($postDate); ?>"/>
                  </div>

                  <?php if ($level === 'MAJOR'): ?>
                  <div class="form-group" id="categoryGroup">
                    <label for="major_category">Major Category *</label>
                    <select id="major_category" name="major_category" onchange="onCategoryChange(this.value)">
                      <option value="">— Select Category —</option>
                      <?php for ($i = 1; $i <= 5; $i++): ?>
                        <option value="<?php echo $i; ?>" <?php echo $category === $i ? 'selected' : ''; ?>>
                          Category <?php echo $i; ?>
                        </option>
                      <?php endfor; ?>
                    </select>
                    <?php if ($category >= 1 && $category <= 5): ?>
                      <div class="category-desc">
                        <strong>Category <?php echo $category; ?>:</strong> <?php echo htmlspecialchars($categoryDescriptions[$category]); ?>
                      </div>
                    <?php endif; ?>
                  </div>
                  <?php else: ?>
                  <div></div>
                  <?php endif; ?>
                </div>

                <div class="form-row full">
                  <div class="form-group">
                    <label for="offense_type_id">Offense Type *</label>
                    <select id="offense_type_id" name="offense_type_id">
                      <option value="">— Select Offense Type —</option>
                      <?php foreach ($offenseTypes as $t): ?>
                        <option value="<?php echo (int)$t['offense_type_id']; ?>"
                          <?php echo $postExistingTypeId === (int)$t['offense_type_id'] ? 'selected' : ''; ?>>
                          <?php echo htmlspecialchars((string)$t['code'] . ' — ' . (string)$t['name']); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                    <?php if ($level === 'MAJOR' && $category < 1): ?>
                      <div class="field-hint">Select a category first to load offense types.</div>
                    <?php elseif (empty($offenseTypes)): ?>
                      <div class="field-hint">No offense types found. Click the + button below to add one.</div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="form-row full" style="margin-top: -8px; margin-bottom: 16px;">
                  <div class="form-group">
                    <div style="display: flex; gap: 10px; align-items: center;">
                      <button type="button" class="btn btn-circle" onclick="openAddModal()" title="Add new offense type">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>
                      </button>
                      <span style="font-size: 12px; color: var(--text-3);">Add new offense type</span>
                    </div>
                  </div>
                </div>

                <div class="form-row full">
                  <div class="form-group">
                    <label for="description" id="descLabel">Description / Notes <span id="descOptional">(optional)</span></label>
                    <textarea id="description" name="description"
                              placeholder="Describe the incident in detail..."><?php echo htmlspecialchars($postDesc); ?></textarea>
                  </div>
                </div>

                <div class="form-actions">
                  <button type="submit" name="_action_hint" value="save" class="btn btn-primary">
                    <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    Register Offense
                  </button>
                  <a href="offenses.php" class="btn">Cancel</a>
                </div>

              </form>
            </div>
          </div>
        </div>

        <!-- RIGHT: STUDENT INFO + ALERT PANEL -->
        <aside>
          <?php if ($postStudentId !== '' && $studentInfo): ?>
            <?php echo renderStudentInfoCard($studentInfo, $liveGuardianEmail, $liveMinorCount, $liveMajorCount, $liveActiveUpccCases); ?>
          <?php elseif ($postStudentId !== '' && !$studentInfo): ?>
            <div class="panel-placeholder" style="margin-bottom:16px;">
              <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              <p>Student not found.</p>
            </div>
          <?php endif; ?>

          <div id="alertPanel">
            <?php
              if ($postStudentId === '') {
                echo '<div class="panel-placeholder"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><p>Enter a Student ID to see the offense status and history for this student.</p></div>';
              } elseif ($level === 'MINOR') {
                echo renderMinorAlert($liveMinorCount + 1, $liveGuardianEmail, $liveMinorCount, $hasActiveSection4, $postSection4Minors);
              } else {
                echo renderMajorAlert($liveMajorCount, $liveActiveUpccCases);
              }
            ?>
          </div>
        </aside>

        <!-- LETTER SECTION -->
        <?php if ($letterMode && $letterOffenseId > 0): ?>
        <div class="modal" id="modal-guardian-letter" style="z-index: 2500;">
          <div class="modal-content" style="max-width: 1100px; width: 95%; max-height: 95vh; overflow-y: auto;">
            <div class="modal-header">
              <h3>
                <?php
                  if ($letterType === 'escalation') echo '📧 Guardian Notification — Section 4 Panel Referral';
                  elseif ($letterType === 'letter')  echo '📧 Guardian Notification — 2nd Minor Offense';
                  elseif ($letterType === 'major')   echo '📧 Guardian Notification — Major Offense';
                  else echo '📧 Guardian Notification';
                ?>
              </h3>
            </div>
            <div class="modal-body" style="padding: 24px;">
              <p style="color: var(--text-2); margin-bottom: 20px; font-size: 13px;">Review and send the notification letter to the guardian. You can update the email address if needed before sending.</p>
              
              <div class="letter-grid">
                <div class="letter-col">
                  <h3>Compose Letter</h3>
                  <div class="form-group" style="margin-bottom:14px;">
                    <label for="letter_guardian_email">Guardian Email Address <span style="color:var(--red); font-weight:normal;">(Required)</span></label>
                    <input id="letter_guardian_email" type="email" value="<?php echo htmlspecialchars($liveGuardianEmail); ?>" placeholder="Enter guardian email..." oninput="checkEmailRequired()" />
                    <div id="email_validation_msg" style="font-size:11px; margin-top:6px; color:var(--red); font-weight:600; display:none;"></div>
                  </div>
                  <div class="form-group" style="margin-bottom:14px;">
                    <label for="letter_subject">Subject</label>
                    <input id="letter_subject" type="text" value="Student Conduct Notice — Offense Report"/>
                  </div>
                  <div class="form-group" style="margin-bottom:14px;">
                    <label for="letter_body">Message</label>
                    <?php
                      $defaultBody = "Dear Guardian,\n\nThis is to inform you that your student has been reported for a conduct offense and an investigation is underway. Please see the detailed notice below for more information.\n\n";
                      if ($studentInfo && $letterOffenseId > 0) {
                          $coff = db_one("SELECT o.description, o.date_committed, ot.code, ot.name, ot.level FROM offense o JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id WHERE o.offense_id = :oid", [':oid' => $letterOffenseId]);
                          if ($coff) {
                              $dt = date('F j, Y g:i A', strtotime($coff['date_committed']));
                              $defaultBody .= "CURRENT OFFENSE:\n- {$coff['code']} — {$coff['name']}\n- Level: {$coff['level']}\n- Date: {$dt}\n- Notes: " . ($coff['description'] ?: '(none)') . "\n\n";
                          }
                          $history = db_all("SELECT o.date_committed, o.description, ot.level, ot.code, ot.name FROM offense o JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id WHERE o.student_id = :sid ORDER BY o.date_committed DESC, o.offense_id DESC LIMIT 30", [':sid' => $studentInfo['student_id']]);
                          $defaultBody .= "OFFENSE HISTORY (Most recent first):\n";
                          if (empty($history)) {
                              $defaultBody .= "(No offenses found.)\n";
                          } else {
                              foreach ($history as $i => $h) {
                                  $dt = date('M j, Y g:i A', strtotime($h['date_committed']));
                                  $defaultBody .= ($i + 1) . ". [{$h['level']}] {$h['code']} — {$h['name']} ({$dt})\n";
                                  if (trim($h['description']) !== '') $defaultBody .= "   Notes: " . trim($h['description']) . "\n";
                              }
                          }
                      }
                      $defaultBody .= "\n\nWe encourage you to support your student in maintaining proper conduct within our institution.\n\nSincerely,\nStudent Discipline Office";
                    ?>
                    <textarea id="letter_body" style="min-height:350px; font-family: monospace; font-size: 13px;"><?php echo htmlspecialchars($defaultBody); ?></textarea>
                  </div>
                  <div class="form-actions" style="border:none;padding:0;margin:0;">
                    <button type="button" class="btn btn-primary" onclick="previewLetter()">
                      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                      Preview
                    </button>
                    <button type="button" class="btn" id="btn_send_letter" style="background:#15803d;color:#fff;border-color:#15803d;" onclick="sendLetter()">
                      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>
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
        <?php endif; ?>

      </div>
    </main>
  </div>

  <?php echo renderStudentRecordModal($studentInfo, $liveGuardianEmail, $liveMinorCount, $liveMajorCount, $liveActiveUpccCases, $hasActiveSection4, $postSection4Minors); ?>

  <!-- MODAL: Add Offense Type -->
  <div id="offenseTypeModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Add Offense Type</h3>
        <button class="modal-close" onclick="closeModal()">&times;</button>
      </div>
      <div class="modal-body">
        <div class="form-group" style="margin-bottom:12px;">
          <label>Code *</label>
          <input type="text" id="type_code" placeholder="e.g., MIN-099 or MAJ-021">
        </div>
        <div class="form-group" style="margin-bottom:12px;">
          <label>Name *</label>
          <input type="text" id="type_name" placeholder="Offense description">
        </div>
        <div class="form-group" style="margin-bottom:12px;">
          <label>Level</label>
          <select id="type_level">
            <option value="MINOR">Minor</option>
            <option value="MAJOR">Major</option>
          </select>
        </div>
        <div class="form-group" id="modalCategoryGroup" style="display:none; margin-bottom:12px;">
          <label>Major Category (1-5)</label>
          <select id="type_major_category">
            <option value="">— Select —</option>
            <?php for ($i=1;$i<=5;$i++): ?><option value="<?php echo $i; ?>">Category <?php echo $i; ?></option><?php endfor; ?>
          </select>
        </div>
        <div id="modalError" style="color:var(--red); font-size:12px; margin-top:8px;"></div>
      </div>
      <div class="modal-footer">
        <button class="btn" onclick="closeModal()">Cancel</button>
        <button class="btn btn-primary" onclick="saveOffenseType()">Save</button>
      </div>
    </div>
  </div>

  <!-- MODAL: Success after register -->
  <div id="successModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Offense Registered Successfully</h3>
        <button class="modal-close" onclick="closeSuccessModal()">&times;</button>
      </div>
      <div class="modal-body">
        <?php if ($letterMode): ?>
          <p style="font-size:13px;color:var(--text-2);line-height:1.6;">
            The offense record has been saved. You may now review and send the guardian notification letter below.
          </p>
        <?php else: ?>
          <p style="font-size:13px;color:var(--text-2);line-height:1.6;">
            The offense record has been saved successfully.
          </p>
        <?php endif; ?>
      </div>
      <div class="modal-footer">
        <?php if ($letterMode): ?>
          <button class="btn btn-primary" type="button" onclick="closeSuccessModal()">Compose Guardian Email</button>
        <?php else: ?>
          <a href="offenses.php" class="btn btn-primary" style="width: 100%; justify-content: center;">Go to Offenses</a>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- MODAL: Success after sending email -->
  <div id="emailSuccessModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3>Email Sent Successfully</h3>
        <button class="modal-close" onclick="closeEmailSuccessModal()">&times;</button>
      </div>
      <div class="modal-body">
        <p style="font-size:13px;color:var(--text-2);line-height:1.6;">
          The guardian notification letter has been sent successfully!
        </p>
      </div>
      <div class="modal-footer">
        <button class="btn" type="button" onclick="closeEmailSuccessModal()">Stay on page</button>
        <a href="offenses.php" class="btn btn-primary">Go to Offenses</a>
      </div>
    </div>
  </div>

  <script>
  const OFFENSE_ID  = <?php echo (int)$letterOffenseId; ?>;
  const LETTER_MODE = <?php echo json_encode($letterMode && $letterOffenseId > 0); ?>;
  const SUCCESS_MODE = <?php echo json_encode($successMode); ?>;
  const INIT_LEVEL  = <?php echo json_encode($level); ?>;
  const SHOW_STUDENT_RECORD_MODAL = <?php echo json_encode($studentInfo && ($liveMinorCount + $liveMajorCount > 0 || count($liveActiveUpccCases) > 0)); ?>;
  let currentLevel    = INIT_LEVEL;
  let currentCategory = <?php echo $category; ?>;

  function escHtml(str) {
    return String(str)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function renderMinorAlert(projectedCount, guardianEmail, currentCount, hasActiveSection4, postSection4Minors) {

    if (hasActiveSection4) {
      const postProjected = postSection4Minors + 1;
      const postPct       = (Math.min(postProjected, 3) / 3) * 100;
      const remaining     = 3 - postProjected;
      const warningNote   = (postProjected >= 3)
        ? `<div class="ap-warning">⚠️ This will be the 3rd offense since Section 4 — consider escalating to the panel.</div>`
        : `<div class="ap-subdesc">${remaining} more offense(s) recorded here will prompt another panel review.</div>`;

      return `
      <div class="alert-panel alert-panel--critical">
        <div class="ap-icon">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
          </svg>
        </div>
        <div class="ap-body">
          <div class="ap-title">Active Section 4 Investigation</div>
          <div class="ap-desc">
            This student already has an open Section 4 case. This offense will <strong>not</strong> open a new case — it is tracked separately below.
          </div>

          <div class="ap-track-label">Original trigger</div>
          <div class="ap-progress" style="margin-bottom:14px;padding:10px 12px;background:rgba(0,0,0,.04);border-radius:8px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
              <span style="font-size:11px;font-weight:600;">Section 4 triggered</span>
              <span style="font-size:11px;font-weight:800;color:var(--pink);">3 / 3 — Under Investigation</span>
            </div>
            <div class="ap-progress-track">
              <div class="ap-progress-fill ap-progress--critical" style="width:100%"></div>
            </div>
          </div>

          <div class="ap-track-label">Since Section 4 opened</div>
          <div class="ap-progress" style="padding:10px 12px;background:rgba(0,0,0,.04);border-radius:8px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
              <span style="font-size:11px;font-weight:600;">New minor offenses</span>
              <span style="font-size:11px;font-weight:800;">${postProjected} / 3</span>
            </div>
            <div class="ap-progress-track">
              <div class="ap-progress-fill ap-progress--critical" style="width:${postPct}%"></div>
            </div>
          </div>
          ${warningNote}
        </div>
      </div>`;
    }

    if (currentCount === undefined) currentCount = projectedCount - 1;
    const pctMap = {1:33, 2:66, 3:100};
    const pct    = pctMap[Math.min(projectedCount, 3)] || 100;

    if (projectedCount === 1) {
      return `
      <div class="alert-panel alert-panel--info">
        <div class="ap-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
        <div class="ap-body">
          <div class="ap-title">1st Minor – Warning</div>
          <div class="ap-projected-badge ap-projected--info">📋 Currently ${currentCount} → becomes <strong>1/3</strong></div>
          <div class="ap-progress"><div class="ap-progress-track"><div class="ap-progress-fill ap-progress--info" style="width:${pct}%"></div></div><span class="ap-progress-label">1/3 – 2 more to Section 4</span></div>
          <div class="ap-desc">Warning only. No letter required.</div>
          <div class="ap-steps">
            <div class="ap-step ap-step--next">1st Minor ⬅ Warning</div>
            <div class="ap-step">2nd Minor → Letter</div>
            <div class="ap-step">3rd Minor → Section 4 Panel</div>
          </div>
        </div>
      </div>`;
    }

    if (projectedCount === 2) {
      const emailHtml = guardianEmail
        ? `<div class="ap-email"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>${escHtml(guardianEmail)}</div>`
        : `<div class="ap-email ap-email--warn"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>No guardian email on file</div>`;
      return `
      <div class="alert-panel alert-panel--warning">
        <div class="ap-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
        <div class="ap-body">
          <div class="ap-title">2nd Minor – Letter to Guardian</div>
          <div class="ap-projected-badge ap-projected--warning">📋 Currently ${currentCount} → becomes <strong>2/3</strong></div>
          <div class="ap-progress"><div class="ap-progress-track"><div class="ap-progress-fill ap-progress--warning" style="width:${pct}%"></div></div><span class="ap-progress-label">2/3 – 1 more to Section 4</span></div>
          <div class="ap-desc">A formal notice will be sent to the guardian after saving.</div>
          ${emailHtml}
          <div class="ap-steps">
            <div class="ap-step ap-step--done">1st Minor ✓</div>
            <div class="ap-step ap-step--next">2nd Minor ⬅ Letter</div>
            <div class="ap-step">3rd Minor → Section 4 Panel</div>
          </div>
        </div>
      </div>`;
    }

    // projectedCount >= 3
    const emailHtml2 = guardianEmail
      ? `<div class="ap-email"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>${escHtml(guardianEmail)}</div>`
      : `<div class="ap-email ap-email--warn"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>No guardian email on file</div>`;
    return `
    <div class="alert-panel alert-panel--critical">
      <div class="ap-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div>
      <div class="ap-body">
        <div class="ap-title">⚖️ 3rd Minor – Triggers Section 4 Panel</div>
        <div class="ap-projected-badge ap-projected--critical">🚨 Currently ${currentCount} → becomes <strong>${projectedCount}/3 – SECTION 4</strong></div>
        <div class="ap-progress"><div class="ap-progress-track"><div class="ap-progress-fill ap-progress--critical" style="width:100%"></div></div><span class="ap-progress-label">3/3 – Panel investigation triggered</span></div>
        <div class="ap-desc">Student referred to UPCC panel. The panel will assign a Category 1–5 sanction.</div>
        ${emailHtml2}
        <div class="ap-checklist">
          <div class="ap-check">✓ UPCC case will be created</div>
          <div class="ap-check">✓ Panel assigns category (1–5)</div>
          <div class="ap-check">✓ Guardian letter generated</div>
        </div>
        <div class="ap-steps">
          <div class="ap-step ap-step--done">1st Minor ✓</div>
          <div class="ap-step ap-step--done">2nd Minor ✓</div>
          <div class="ap-step ap-step--critical">3rd Minor ⬅ Section 4 Panel</div>
        </div>
      </div>
    </div>`;
  }

  function renderMajorAlert(majorCount, upccCases) {
    const caseCount    = upccCases.length;
    const categoryNames = {1:'Probation',2:'Formative Intervention',3:'Non-Readmission',4:'Exclusion',5:'Expulsion'};
    let casesHtml = '';
    if (caseCount > 0) {
      casesHtml = upccCases.map(c => {
        const summary = String(c.case_summary || '');
        let offenseType = 'Under Investigation', offenseStatus = '';
        if (summary.includes('Section 4') || c.case_kind === 'SECTION4_MINOR_ESCALATION') {
          offenseType = 'Section 4 Panel Case'; offenseStatus = 'Awaiting category';
        } else {
          const m = summary.match(/Major Offense - Category (\d)/);
          if (m) {
            offenseType   = 'Major Offense';
            offenseStatus = 'Category ' + m[1] + ' (' + (categoryNames[parseInt(m[1])] || '') + ')';
          }
        }
        return `<div class="ap-case"><div class="ap-case-header"><span class="ap-case-id">Case #${escHtml(String(c.case_id))}</span><span class="ap-case-badge">${escHtml(String(c.status||'').toUpperCase())}</span></div><div class="ap-case-type">${escHtml(offenseType)}</div>${offenseStatus ? `<div class="ap-case-status">${escHtml(offenseStatus)}</div>` : ''}</div>`;
      }).join('');
    } else {
      casesHtml = `<div class="ap-empty"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/><rect x="9" y="3" width="6" height="4" rx="1"/></svg><p>No active UPCC cases</p></div>`;
    }
    return `
    <div class="alert-panel alert-panel--major">
      <div class="ap-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg></div>
      <div class="ap-body">
        <div class="ap-title">⚠️ Major Offense – UPCC Case Required</div>
        <div class="ap-stat-row">
          <div class="ap-stat"><div class="ap-stat-val" style="color:var(--red)">${majorCount}</div><div class="ap-stat-lbl">Major Offenses</div></div>
          <div class="ap-stat"><div class="ap-stat-val" style="color:var(--amber)">${caseCount}</div><div class="ap-stat-lbl">Active Cases</div></div>
        </div>
        <div class="ap-desc">Saving will auto-create a UPCC case and generate a guardian letter.</div>
        <div style="font-size:11px;font-weight:800;color:var(--red);text-transform:uppercase;margin-bottom:6px;">Active Cases</div>
        <div class="ap-cases">${casesHtml}</div>
      </div>
    </div>`;
  }

  const studentIdInput    = document.getElementById('studentIdInput');
  const levelSelect       = document.getElementById('levelSelect');
  const alertPanel        = document.getElementById('alertPanel');
  const offenseTypeSelect = document.getElementById('offense_type_id');

  function showPlaceholder() {
    alertPanel.innerHTML = `<div class="panel-placeholder"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><p>Enter a Student ID to see the offense status and history for this student.</p></div>`;
  }

  async function refreshOffenseTypes() {
    const level = levelSelect?.value || 'MINOR';
    let cat = 0;
    if (level === 'MAJOR') {
      const catSelect = document.getElementById('major_category');
      if (catSelect) cat = parseInt(catSelect.value) || 0;
    }
    const formData = new FormData();
    formData.append('action', 'list_offense_types');
    formData.append('level', level);
    if (level === 'MAJOR' && cat >= 1 && cat <= 5) formData.append('major_category', cat);
    const res  = await fetch(window.location.href, { method:'POST', body:formData, headers:{'X-Requested-With':'XMLHttpRequest'} });
    const data = await res.json();
    if (data.ok && data.types) {
      const select     = offenseTypeSelect;
      const currentVal = select.value;
      select.innerHTML = '<option value="">— Select Offense Type —</option>';
      data.types.forEach(t => {
        const opt       = document.createElement('option');
        opt.value       = t.offense_type_id;
        opt.textContent = t.code + ' — ' + t.name;
        if (currentVal == t.offense_type_id) opt.selected = true;
        select.appendChild(opt);
      });
      updateDescriptionRequirement();
    }
  }

  function updateDescriptionRequirement() {
    const typeId = parseInt(offenseTypeSelect.value) || 0;
    const optionalLabel = document.getElementById('descOptional');
    const descTextarea = document.getElementById('description');
    
    if (typeId === 22 || typeId === 23) {
      // It's "Other", make it required
      if (optionalLabel) optionalLabel.style.display = 'none';
      descTextarea.required = true;
      descTextarea.placeholder = "Please provide a detailed description of this custom offense (REQUIRED)...";
      // Add visual asterisk if not already there
      if (!document.getElementById('descReqStar')) {
        const star = document.createElement('span');
        star.id = 'descReqStar';
        star.style.color = 'var(--red)';
        star.style.marginLeft = '4px';
        star.textContent = '*';
        document.getElementById('descLabel').appendChild(star);
      }
    } else {
      if (optionalLabel) optionalLabel.style.display = 'inline';
      descTextarea.required = false;
      descTextarea.placeholder = "Describe the incident in detail...";
      const star = document.getElementById('descReqStar');
      if (star) star.remove();
    }
  }

  offenseTypeSelect.addEventListener('change', updateDescriptionRequirement);
  document.addEventListener('DOMContentLoaded', updateDescriptionRequirement);

  // Modal
  const modal = document.getElementById('offenseTypeModal');
  const successModal = document.getElementById('successModal');
  function closeModal() { modal.classList.remove('active'); document.getElementById('modalError').innerText = ''; }
  function closeEmailSuccessModal() {
    const m = document.getElementById('emailSuccessModal');
    if (m) m.classList.remove('active');
  }
  function closeSuccessModal() { 
    if (successModal) successModal.classList.remove('active'); 
    if (typeof LETTER_MODE !== 'undefined' && LETTER_MODE) {
      const letterModal = document.getElementById('modal-guardian-letter');
      if (letterModal) letterModal.classList.add('active');
      if (typeof previewLetter === 'function') previewLetter();
    }
  }
  function openStudentRecordModal() {
    const studentModal = document.getElementById('studentRecordModal');
    if (studentModal) studentModal.classList.add('active');
  }
  function closeStudentRecordModal() {
    const studentModal = document.getElementById('studentRecordModal');
    if (studentModal) studentModal.classList.remove('active');
  }
  document.addEventListener('DOMContentLoaded', () => {
    if (SHOW_STUDENT_RECORD_MODAL && !SUCCESS_MODE) {
      openStudentRecordModal();
    }
  });
  function openAddModal() {
    document.getElementById('type_code').value  = '';
    document.getElementById('type_name').value  = '';
    document.getElementById('type_level').value = currentLevel;
    document.getElementById('type_major_category').value = '';
    toggleModalCategory();
    modal.classList.add('active');
  }
  function toggleModalCategory() {
    const lvl = document.getElementById('type_level').value;
    document.getElementById('modalCategoryGroup').style.display = (lvl === 'MAJOR') ? 'block' : 'none';
  }
  document.getElementById('type_level').addEventListener('change', toggleModalCategory);

  async function saveOffenseType() {
    const code  = document.getElementById('type_code').value.trim();
    const name  = document.getElementById('type_name').value.trim();
    const level = document.getElementById('type_level').value;
    let majorCategory = null;
    if (level === 'MAJOR') {
      const cat = document.getElementById('type_major_category').value;
      if (!cat) { document.getElementById('modalError').innerText = 'Major offense requires a category (1-5).'; return; }
      majorCategory = parseInt(cat);
    }
    if (!code || !name) { document.getElementById('modalError').innerText = 'Code and Name are required.'; return; }
    const formData = new FormData();
    formData.append('action', 'add_offense_type');
    formData.append('code', code);
    formData.append('name', name);
    formData.append('level', level);
    if (majorCategory) formData.append('major_category', majorCategory);
    const res  = await fetch(window.location.href, { method:'POST', body:formData, headers:{'X-Requested-With':'XMLHttpRequest'} });
    const data = await res.json();
    if (data.ok) { closeModal(); await refreshOffenseTypes(); alert(data.message); }
    else { document.getElementById('modalError').innerText = data.error || 'Error saving offense type.'; }
  }

  function onLevelChange(newLevel) {
    const studentId = (studentIdInput?.value || '').trim();
    const params    = new URLSearchParams({ level: newLevel });
    if (studentId) params.set('student_id', studentId);
    window.location.href = 'offense_new.php?' + params.toString();
  }
  function onCategoryChange(cat) {
    const studentId = (studentIdInput?.value || '').trim();
    const params    = new URLSearchParams({ level: 'MAJOR', major_category: cat });
    if (studentId) params.set('student_id', studentId);
    window.location.href = 'offense_new.php?' + params.toString();
  }

  let debounceTimer;
  if (studentIdInput) {
    studentIdInput.addEventListener('input', () => {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(() => {
        const studentId = studentIdInput.value.trim();
        const level     = levelSelect.value;
        const params    = new URLSearchParams({ level });
        if (studentId) params.set('student_id', studentId);
        window.location.href = 'offense_new.php?' + params.toString();
      }, 800);
    });
  }

  // Letter
  async function postJSON(url, body) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
      body: JSON.stringify(body),
      cache: 'no-store'
    });
    return { ok: res.ok, json: await res.json().catch(() => null) };
  }
  async function previewLetter() {
    const guardianEmail = document.getElementById('letter_guardian_email')?.value.trim() || '';
    const subject = document.getElementById('letter_subject')?.value || '';
    const body    = document.getElementById('letter_body')?.value    || '';
    const preview = document.getElementById('previewContent');
    if (!preview) return;
    if (!guardianEmail) {
        preview.innerHTML = '<div style="padding:16px;color:var(--red);font-weight:600;">⚠️ Cannot generate preview: Guardian email is required. Please enter a valid email address.</div>';
        return;
    }
    preview.innerHTML = '<div class="loading"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Generating…</div>';
    const r = await postJSON('AJAX/offense_letter_preview.php', { offense_id: OFFENSE_ID, subject, body, guardian_email: guardianEmail });
    if (r.ok && r.json?.ok && r.json?.pdf_url) preview.innerHTML = '<iframe src="' + r.json.pdf_url + '"></iframe>';
    else preview.innerHTML = '<div style="padding:16px;color:var(--red);font-weight:600;">Failed to generate preview.</div>';
  }
  async function sendLetter() {
    const guardianEmail = document.getElementById('letter_guardian_email')?.value.trim() || '';
    const subject = document.getElementById('letter_subject')?.value || '';
    const body    = document.getElementById('letter_body')?.value    || '';
    const msg     = document.getElementById('letterMsg');
    
    if (!guardianEmail) {
        if (msg) {
            msg.textContent = '❌ Cannot send email: Guardian email is required.';
            msg.style.color = 'var(--red)';
        }
        alert('Please enter a guardian email address before sending.');
        document.getElementById('letter_guardian_email').focus();
        return;
    }
    
    const btn = document.getElementById('btn_send_letter');
    if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; btn.style.cursor = 'not-allowed'; }

    if (msg) { msg.textContent = 'Sending…'; msg.style.color = 'var(--text-3)'; }
    const r = await postJSON('AJAX/offense_letter_send.php', { offense_id: OFFENSE_ID, subject, body, guardian_email: guardianEmail });
    
    if (msg) {
      if (r.ok && r.json?.ok) { 
        msg.textContent = '✅ Email sent successfully.'; 
        msg.style.color = 'var(--green)'; 
        
        // Hide the guardian letter modal
        const letterModal = document.getElementById('modal-guardian-letter');
        if (letterModal) letterModal.classList.remove('active');
        
        // Update guardian email in Student Information UI dynamically
        const sicRows = document.querySelectorAll('.sic-row');
        sicRows.forEach(row => {
          if (row.innerHTML.includes('Guardian Email:')) {
            const valSpan = row.querySelector('.sic-value');
            if (valSpan) valSpan.innerHTML = `<span style="color:var(--green);font-weight:600;">${escHtml(guardianEmail)}</span>`;
          }
        });

        // Show the email success modal
        const emailSuccessModal = document.getElementById('emailSuccessModal');
        if (emailSuccessModal) emailSuccessModal.classList.add('active');
      }
      else { 
        msg.textContent = '❌ Failed: ' + (r.json?.message || 'Unknown error'); 
        msg.style.color = 'var(--red)'; 
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer'; }
      }
    }
  }
  let previewDebounce = null;
  function checkEmailRequired() {
    const btn = document.getElementById('btn_send_letter');
    const emailInput = document.getElementById('letter_guardian_email');
    const msgDiv = document.getElementById('email_validation_msg');
    const email = emailInput?.value.trim() || '';
    const isValid = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
    
    if (msgDiv) {
        if (!email) {
            msgDiv.textContent = 'Email address is required.';
            msgDiv.style.color = 'var(--red)';
            msgDiv.style.display = 'block';
        } else if (!isValid) {
            msgDiv.textContent = 'Not valid. Please enter a valid email address.';
            msgDiv.style.color = 'var(--red)';
            msgDiv.style.display = 'block';
        } else {
            msgDiv.style.display = 'none';
        }
    }
    
    if (btn) {
      if (!isValid) {
        btn.disabled = true;
        btn.style.opacity = '0.5';
        btn.style.cursor = 'not-allowed';
      } else {
        btn.disabled = false;
        btn.style.opacity = '1';
        btn.style.cursor = 'pointer';
      }
    }

    clearTimeout(previewDebounce);
    previewDebounce = setTimeout(() => {
        if (isValid && typeof previewLetter === 'function') {
            previewLetter();
        } else {
            const preview = document.getElementById('previewContent');
            if (preview && (!email || !isValid)) {
                preview.innerHTML = '<div style="padding:16px;color:var(--red);font-weight:600;">⚠️ Cannot generate preview: Guardian email is required. Please enter a valid email address.</div>';
            }
        }
    }, 600);
  }

  if (SUCCESS_MODE && successModal) successModal.classList.add('active');
  if (LETTER_MODE) {
      setTimeout(() => {
          checkEmailRequired();
          previewLetter();
      }, 500);
  }

  // Local scanner capture: resolve scan and auto-fill student ID on this form.
  (function () {
    const studentInput = document.getElementById('studentIdInput');
    if (!studentInput) return;

    let scanBuffer = '';
    let scanTimer = null;
    let scanBusy = false;

    function clearScanTimer() {
      if (scanTimer) {
        clearTimeout(scanTimer);
        scanTimer = null;
      }
    }

    function resolveAndApplyScan(rawValue) {
      const scanned = String(rawValue || '').trim();
      if (!scanned || scanBusy) return;

      scanBusy = true;

      fetch('AJAX/scan_student_lookup.php?scan=' + encodeURIComponent(scanned), {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
      .then(r => r.json())
      .then(data => {
        if (!data || !data.ok || !data.student_id) return;

        const foundId = String(data.student_id);
        studentInput.value = foundId;
        studentInput.dispatchEvent(new Event('input', { bubbles: true }));

        // Navigate immediately so student profile/alerts refresh for the scanned ID.
        const lvl = (levelSelect && levelSelect.value) ? levelSelect.value : 'MINOR';
        const params = new URLSearchParams({ level: lvl, student_id: foundId });
        if (lvl === 'MAJOR') {
          const catEl = document.getElementById('major_category');
          const cat = catEl ? String(catEl.value || '').trim() : '';
          if (cat) params.set('major_category', cat);
        }
        window.location.href = 'offense_new.php?' + params.toString();
      })
      .catch(() => {})
      .finally(() => {
        scanBusy = false;
      });
    }

    function flushScanBuffer() {
      const value = String(scanBuffer || '').trim();
      scanBuffer = '';
      clearScanTimer();
      if (value.length >= 6) resolveAndApplyScan(value);
    }

    document.addEventListener('keydown', function (ev) {
      // If typing in another text field, skip scanner capture.
      const tgt = ev.target;
      const isTypingTarget = tgt && (
        tgt.tagName === 'TEXTAREA' ||
        tgt.tagName === 'SELECT' ||
        (tgt.tagName === 'INPUT' && tgt !== studentInput) ||
        tgt.isContentEditable
      );
      if (isTypingTarget) return;

      if (ev.key === 'Enter') {
        flushScanBuffer();
        return;
      }

      if (ev.key.length === 1 && !ev.ctrlKey && !ev.altKey && !ev.metaKey) {
        scanBuffer += ev.key;
        clearScanTimer();
        scanTimer = setTimeout(flushScanBuffer, 180);
      }
    });
  })();
  </script>
</body>
</html>

