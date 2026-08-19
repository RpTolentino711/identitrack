<?php
// File: admin/offense_new.php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
require_once __DIR__ . '/../database/database.php';
require_admin();

$activeSidebar = 'offenses';

$admin   = admin_current();
$adminId = (int)($admin['admin_id'] ?? 0);

$level = strtoupper(trim((string)($_GET['level'] ?? $_POST['level'] ?? 'MINOR')));
if ($level !== 'MINOR' && $level !== 'MAJOR' && $level !== 'DISMISSED') $level = 'MINOR';

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
     WHERE is_active = 1 AND level = 'MINOR' AND code NOT LIKE '%OTHER%' ORDER BY code ASC",
    []
  ) ?: [];
} else if ($level === 'DISMISSED') {
  $offenseTypes = db_all(
    "SELECT offense_type_id, code, name FROM offense_type
     WHERE is_active = 1 AND level = 'DISMISSED' AND code NOT LIKE '%OTHER%' ORDER BY code ASC",
    []
  ) ?: [];
} else if ($level === 'MAJOR' && $category >= 1 && $category <= 5) {
  $offenseTypes = db_all(
    "SELECT offense_type_id, code, name FROM offense_type
     WHERE is_active = 1 AND level = 'MAJOR' AND major_category = :cat AND code NOT LIKE '%OTHER%' ORDER BY code ASC",
    [':cat' => $category]
  ) ?: [];
} else if ($level === 'MAJOR') {
  $offenseTypes = db_all(
    "SELECT offense_type_id, code, name FROM offense_type
     WHERE is_active = 1 AND level = 'MAJOR' AND code NOT LIKE '%OTHER%' ORDER BY code ASC",
    []
  ) ?: [];
}
// Append the "Other" option to the end of the list
if ($level === 'MINOR') {
    $offenseTypes[] = ['offense_type_id' => 22, 'code' => 'OTHER', 'name' => 'Other / Custom Minor Offense'];
} else if ($level === 'DISMISSED') {
    $offenseTypes[] = ['offense_type_id' => 24, 'code' => 'OTHER', 'name' => 'Other / Custom Dismissed Offense'];
} else if ($level === 'MAJOR') {
    $offenseTypes[] = ['offense_type_id' => 23, 'code' => 'OTHER', 'name' => 'Other / Custom Major Offense'];
}

// ── Handle POST (save offense) ─────────────────────────────────────────────
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['_action_hint'] ?? '') === 'save') {

  $student_id       = trim((string)($_POST['student_id']     ?? ''));
  $date_committed   = trim((string)($_POST['date_committed'] ?? ''));
  $description      = trim((string)($_POST['description']    ?? ''));
  $dismissalReason  = trim((string)($_POST['dismissal_reason'] ?? ''));
  $existing_type_id = (int)($_POST['offense_type_id'] ?? 0);

  if ($level === 'DISMISSED') {
    if ($dismissalReason === '' && $description !== '') {
      $dismissalReason = $description;
    }
    if ($description === '' && $dismissalReason !== '') {
      $description = $dismissalReason;
    }
  }

  if ($student_id     === '') $errors[] = 'Student ID is required.';
  if ($date_committed === '') $errors[] = 'Date & time of incident is required.';

  if ($student_id !== '') {
    $s = db_one("SELECT student_id FROM student WHERE student_id = :sid LIMIT 1", [':sid' => $student_id]);
    if (!$s) $errors[] = 'Student not found in the system.';
  }

  if ($existing_type_id <= 0) {
    $errors[] = 'Please select an offense type.';
  } else if ((in_array($existing_type_id, [22, 23, 24], true) || $level === 'DISMISSED') && $description === '' && $dismissalReason === '') {
    $errors[] = 'Please provide a detailed description/notes for this offense.';
  }

  // Check for duplicate offense entry
  if (empty($errors) && $student_id !== '' && $existing_type_id > 0 && $date_committed !== '') {
    $duplicateCheck = db_one(
      "SELECT offense_id FROM offense 
       WHERE student_id = :sid 
         AND offense_type_id = :tid 
         AND DATE_FORMAT(date_committed, '%Y-%m-%d %H:%i') = DATE_FORMAT(:dt, '%Y-%m-%d %H:%i')",
      [':sid' => $student_id, ':tid' => $existing_type_id, ':dt' => $date_committed]
    );
    if ($duplicateCheck) {
      $errors[] = 'A duplicate offense for this student with the same type and incident date/time already exists.';
    }
  }

  if (empty($errors)) {

    // Process evidence file upload if provided
    $evidenceFilePath = null;
    if (isset($_FILES['evidence_file']) && $_FILES['evidence_file']['error'] === UPLOAD_ERR_OK) {
      $uploadDir = __DIR__ . '/../uploads/incident_reports/';
      if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
      }
      $ext = strtolower(pathinfo($_FILES['evidence_file']['name'], PATHINFO_EXTENSION));
      $allowedExts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
      if (in_array($ext, $allowedExts, true)) {
        $filename = 'incident_' . time() . '_' . uniqid() . '.' . $ext;
        $targetPath = $uploadDir . $filename;
        if (move_uploaded_file($_FILES['evidence_file']['tmp_name'], $targetPath)) {
          $evidenceFilePath = 'uploads/incident_reports/' . $filename;
        }
      }
    }

    $offenseStatus = ($level === 'DISMISSED') ? 'DISMISSED' : 'OPEN';

    $params = [
      ':sid'   => $student_id,
      ':admin' => $adminId,
      ':tid'   => $existing_type_id,
      ':lvl'   => $level,
      ':desc'  => ($description === '' ? null : $description),
      ':dreason' => ($dismissalReason === '' ? null : $dismissalReason),
      ':evfile'  => $evidenceFilePath,
      ':dt'    => $date_committed,
      ':status' => $offenseStatus,
    ];

    db_exec(
      "INSERT INTO offense (student_id, recorded_by, offense_type_id, level, description, dismissal_reason, evidence_file, date_committed, status, created_at, updated_at)
       VALUES (:sid, :admin, :tid, :lvl, " . db_encrypt_col('description', ':desc') . ", :dreason, :evfile, :dt, :status, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
      $params
    );

    $newOffenseId = db_last_id();

    // Mark associated pending guard report as APPROVED / REVIEWED
    $pendingReportIdSubmit = (int)($_POST['pending_report_id'] ?? 0);
    if ($pendingReportIdSubmit > 0) {
      db_exec(
        "UPDATE guard_violation_report SET status = 'APPROVED' WHERE report_id = :rid",
        [':rid' => $pendingReportIdSubmit]
      );
      db_exec(
        "UPDATE notification SET is_read = 1 WHERE item_type = 'VIOLATION' AND report_id = :rid",
        [':rid' => $pendingReportIdSubmit]
      );
    }

    // ── AFTER INSERT LOGIC ────────────────────────────────────────────────
    if ($level === 'DISMISSED') {
      redirect('offense_new.php?level=DISMISSED&student_id=' . urlencode($student_id) . '&dismissed_success=1');
    } elseif ($level === 'MINOR') {
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
        redirect('offense_new.php?level=MINOR&student_id=' . urlencode($student_id) . '&success=1&msg=Minor+offense+recorded.+Student+already+under+Section+4+investigation.');
      }

      if ($afterMinor >= 3) {
        db_exec(
          "INSERT INTO upcc_case (student_id, created_by, status, case_kind, case_summary, evidence_file, created_at, updated_at)
           VALUES (:sid, :aid, 'PENDING', 'SECTION4_MINOR_ESCALATION', :summary, :evfile, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
          [
            ':sid'     => $student_id,
            ':aid'     => $adminId,
            ':summary' => 'Section 4 Major — 3rd Minor attempt → Referred to UPCC panel for investigation and category assignment (1‑5).',
            ':evfile'  => $evidenceFilePath,
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
        "INSERT INTO upcc_case (student_id, created_by, status, case_kind, case_summary, evidence_file, created_at, updated_at)
         VALUES (:sid, :aid, 'PENDING', 'MAJOR_OFFENSE', :summary, :evfile, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
        [
          ':sid'     => $student_id,
          ':aid'     => $adminId,
          ':summary' => 'Major Offense - Category ' . $category . ' - UPCC investigation required',
          ':evfile'  => $evidenceFilePath,
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

  if ($action === 'add_offense_type' || $action === 'edit_offense_type') {
    $edit_id   = (int)($_POST['edit_id'] ?? 0);
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
      if ($action === 'edit_offense_type' && $edit_id > 0) {
        db_exec(
          "UPDATE offense_type SET code = :code, name = :name, level = :lvl, major_category = :cat, updated_at = CURRENT_TIMESTAMP WHERE offense_type_id = :id",
          [':code' => $code, ':name' => $name, ':lvl' => $lvl, ':cat' => $lvl === 'MAJOR' ? $major_cat : null, ':id' => $edit_id]
        );
        echo json_encode(['ok' => true, 'message' => 'Offense type updated.', 'new_id' => $edit_id]);
      } else {
        db_exec(
          "INSERT INTO offense_type (code, name, level, major_category, is_active, created_at, updated_at)
           VALUES (:code, :name, :lvl, :cat, 1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
          [':code' => $code, ':name' => $name, ':lvl' => $lvl, ':cat' => $lvl === 'MAJOR' ? $major_cat : null]
        );
        $newId = db_last_id();
        echo json_encode(['ok' => true, 'message' => 'Offense type added.', 'new_id' => $newId]);
      }
    } catch (Exception $e) {
      echo json_encode(['ok' => false, 'error' => 'Database error: ' . $e->getMessage()]);
    }
    exit;
  }

  if ($action === 'delete_offense_type') {
    $tid = (int)($_POST['offense_type_id'] ?? 0);
    if ($tid > 0 && !in_array($tid, [22, 23, 24], true)) {
        // Soft delete
        db_exec("UPDATE offense_type SET is_active = 0, updated_at = CURRENT_TIMESTAMP WHERE offense_type_id = :id", [':id' => $tid]);
        echo json_encode(['ok' => true]);
    } else {
        echo json_encode(['ok' => false, 'error' => 'Invalid offense type.']);
    }
    exit;
  }

  if ($action === 'list_offense_types') {
    $lvl = $_POST['level'] ?? 'MINOR';
    $cat = isset($_POST['major_category']) ? (int)$_POST['major_category'] : 0;
    if ($lvl === 'MAJOR') {
      if ($cat >= 1 && $cat <= 5) {
        $rows = db_all("SELECT offense_type_id, code, name FROM offense_type WHERE is_active = 1 AND level = 'MAJOR' AND major_category = :cat AND code NOT LIKE '%OTHER%' ORDER BY code ASC", [':cat' => $cat]) ?: [];
      } else {
        $rows = db_all("SELECT offense_type_id, code, name FROM offense_type WHERE is_active = 1 AND level = 'MAJOR' AND code NOT LIKE '%OTHER%' ORDER BY code ASC") ?: [];
      }
      $rows[] = ['offense_type_id' => 23, 'code' => 'OTHER', 'name' => 'Other / Custom Major Offense'];
    } else if ($lvl === 'DISMISSED') {
      $rows = db_all("SELECT offense_type_id, code, name FROM offense_type WHERE is_active = 1 AND level = 'DISMISSED' AND code NOT LIKE '%OTHER%' ORDER BY code ASC") ?: [];
      $rows[] = ['offense_type_id' => 24, 'code' => 'OTHER', 'name' => 'Other / Custom Dismissed Offense'];
    } else {
      $rows = db_all("SELECT offense_type_id, code, name FROM offense_type WHERE is_active = 1 AND level = 'MINOR' AND code NOT LIKE '%OTHER%' ORDER BY code ASC") ?: [];
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
$defaultDate   = ph_date('Y-m-d\TH:i');
$postDate      = (string)($_POST['date_committed'] ?? $defaultDate);
$postDesc      = (string)($_POST['description']    ?? '');

// ── Pending Guard Violation Report Lookup ─────────────────────────────────────────
$pendingReportId = (int)($_GET['pending_report_id'] ?? $_POST['pending_report_id'] ?? 0);
$pendingGuardReports = [];

if ($pendingReportId > 0) {
  $single = db_one("
    SELECT pgr.*, ot.code as offense_code, ot.name as offense_name, ot.level as offense_level, ot.major_category,
           g.full_name as guard_name
    FROM guard_violation_report pgr
    LEFT JOIN offense_type ot ON ot.offense_type_id = pgr.offense_type_id
    LEFT JOIN security_guard g ON g.guard_id = pgr.submitted_by
    WHERE pgr.report_id = :rid AND pgr.status = 'PENDING' AND pgr.is_deleted = 0
    LIMIT 1
  ", [':rid' => $pendingReportId]);
  if ($single) {
    $pendingGuardReports[] = $single;
    if ($postStudentId === '') {
      $postStudentId = (string)($single['student_id'] ?? '');
    }
  }
}

if ($postStudentId !== '') {
  $others = db_all("
    SELECT pgr.*, ot.code as offense_code, ot.name as offense_name, ot.level as offense_level, ot.major_category,
           g.full_name as guard_name
    FROM guard_violation_report pgr
    LEFT JOIN offense_type ot ON ot.offense_type_id = pgr.offense_type_id
    LEFT JOIN security_guard g ON g.guard_id = pgr.submitted_by
    WHERE pgr.student_id = :sid AND pgr.status = 'PENDING' AND pgr.is_deleted = 0
    ORDER BY pgr.created_at ASC
  ", [':sid' => $postStudentId]) ?: [];

  foreach ($others as $o) {
    $alreadyIn = false;
    foreach ($pendingGuardReports as $p) {
      if ((int)$p['report_id'] === (int)$o['report_id']) {
        $alreadyIn = true;
        break;
      }
    }
    if (!$alreadyIn) {
      $pendingGuardReports[] = $o;
    }
  }
}

$pendingGuardReport = !empty($pendingGuardReports) ? $pendingGuardReports[0] : null;

if ($pendingGuardReport) {
  $pendingReportId = (int)$pendingGuardReport['report_id'];
  if (empty($_POST)) {
    if ($postDesc === '' && !empty($pendingGuardReport['description'])) {
      $postDesc = (string)$pendingGuardReport['description'];
    }
    if (!empty($pendingGuardReport['created_at'])) {
      $postDate = ph_date('Y-m-d\TH:i', $pendingGuardReport['created_at'] ?? null);
    }
    if (!isset($_GET['level']) && !isset($_POST['level']) && !empty($pendingGuardReport['offense_level'])) {
      $level = strtoupper((string)$pendingGuardReport['offense_level']);
      if ($level !== 'MINOR' && $level !== 'MAJOR' && $level !== 'DISMISSED') $level = 'MINOR';
    }
    if (isset($pendingGuardReport['major_category'])) {
      $category = (int)$pendingGuardReport['major_category'];
    }
    if ($postExistingTypeId <= 0 && !empty($pendingGuardReport['offense_type_id'])) {
      $postExistingTypeId = (int)$pendingGuardReport['offense_type_id'];
    }
  }

  // Reload offenseTypes to match the level and category
  if ($level === 'MINOR') {
    $offenseTypes = db_all(
      "SELECT offense_type_id, code, name FROM offense_type
       WHERE is_active = 1 AND level = 'MINOR' AND code NOT LIKE '%OTHER%' ORDER BY code ASC",
      []
    ) ?: [];
    $offenseTypes[] = ['offense_type_id' => 22, 'code' => 'OTHER', 'name' => 'Other / Custom Minor Offense'];
  } else if ($level === 'DISMISSED') {
    $offenseTypes = db_all(
      "SELECT offense_type_id, code, name FROM offense_type
       WHERE is_active = 1 AND level = 'DISMISSED' AND code NOT LIKE '%OTHER%' ORDER BY code ASC",
      []
    ) ?: [];
    $offenseTypes[] = ['offense_type_id' => 24, 'code' => 'OTHER', 'name' => 'Other / Custom Dismissed Offense'];
  } else if ($level === 'MAJOR') {
    $sql = "SELECT offense_type_id, code, name FROM offense_type WHERE is_active = 1 AND level = 'MAJOR'";
    $params = [];
    if ($category >= 1 && $category <= 5) {
      $sql .= " AND major_category = :cat";
      $params[':cat'] = $category;
    }
    $sql .= " AND code NOT LIKE '%OTHER%' ORDER BY code ASC";
    $offenseTypes = db_all($sql, $params) ?: [];
    $offenseTypes[] = ['offense_type_id' => 23, 'code' => 'OTHER', 'name' => 'Other / Custom Major Offense'];
  }
}

// ── Letter mode ──────────────────────────────────────────────────────────────
$letterOffenseId = (int)($_GET['offense_id'] ?? 0);
$letterType      = (string)($_GET['type'] ?? '');
$successMode     = ((int)($_GET['success'] ?? 0) === 1);

$letterMode = false;
if (((int)($_GET['letter'] ?? 0) === 1) && $letterOffenseId > 0) {
    $offExists = db_one("SELECT 1 FROM offense WHERE offense_id = :oid", [':oid' => $letterOffenseId]);
    if ($offExists) {
        $letterMode = true;
    }
}

// ── Live student data ─────────────────────────────────────────────────────────
$liveMinorCount      = 0;
$liveMajorCount      = 0;
$liveGuardianEmail   = '';
$liveActiveUpccCases = [];
$hasActiveSection4   = false;
$section4StartDate   = null;
$postSection4Minors  = 0;
$studentInfo         = null;
$liveOffenses        = [];

if ($postStudentId !== '') {
  ensure_student_privacy_columns();
  $studentInfo = db_one(
    "SELECT student_id, " . db_decrypt_cols(['student_fn', 'student_ln', 'student_email', 'phone_number']) . ", year_level, section, school, program, privacy_accepted, privacy_accepted_at, app_registered_at
     FROM student WHERE student_id = :sid LIMIT 1",
    [':sid' => $postStudentId]
  );

  // If studentInfo is still null, we cannot show info
  if ($studentInfo) {
    // fetch minor count, major count, guardian email, etc.
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
    
    $liveOffenses = db_all(
     "SELECT o.offense_id, o.date_committed, o.level, ot.code, ot.name, 
            uc.decided_category, uc.status AS case_status,
            uc.probation_until,
            " . db_decrypt_col('punishment_details', 'uc') . " AS punishment_details,
            (SELECT csr.status FROM community_service_requirement csr WHERE csr.related_case_id = uc.case_id LIMIT 1) AS csr_status
     FROM offense o
     JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
     LEFT JOIN upcc_case_offense uco ON uco.offense_id = o.offense_id
     LEFT JOIN upcc_case uc ON uc.case_id = uco.case_id AND uc.status <> 'VOID'
     WHERE o.student_id = :sid
     ORDER BY o.date_committed DESC",
      [':sid' => $postStudentId]
    ) ?: [];
  }
}

// ── Success message from query params ──────────────────────────────────────
$successMsg = '';
if (isset($_GET['msg'])) {
    $successMsg = htmlspecialchars($_GET['msg']);
} elseif (isset($_GET['success']) && $_GET['success'] == '1') {
    $successMsg = 'Offense registered successfully.';
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
        <div class="ap-stat"><div class="ap-stat-val" style="color:var(--red);">' . $majorCount . '</div><div class="ap-stat-lbl">Major Offenses</div></div>
        <div class="ap-stat"><div class="ap-stat-val" style="color:var(--amber);">' . $caseCount . '</div><div class="ap-stat-lbl">Active Cases</div></div>
      </div>
      <div class="ap-desc">Saving will auto-create a UPCC case and generate a guardian letter.</div>
      <div style="font-size:11px;font-weight:800;color:var(--red);text-transform:uppercase;margin-bottom:6px;">Active Cases</div>
      <div class="ap-cases">' . $casesHtml . '</div>
    </div>
  </div>';
}
function renderStudentInfoCard($student, $guardianEmail, $minorCount = 0, $majorCount = 0, $activeCases = [], $offenses = []) {
  if (!$student) return '';
  $fullName    = htmlspecialchars($student['student_fn'] . ' ' . $student['student_ln']);
  $studentId   = htmlspecialchars($student['student_id']);
  $yearSection = htmlspecialchars($student['year_level'] . ' - ' . ($student['section'] ?? 'N/A'));
  $program     = htmlspecialchars($student['program'] ?? 'N/A');
  $school      = htmlspecialchars($student['school'] ?? 'NU Lipa');
  $email       = htmlspecialchars($student['student_email'] ?? '');
  $guardian    = $guardianEmail ? htmlspecialchars($guardianEmail) : '<span class="text-muted">Not provided</span>';

  $isAppRegistered = (!empty($student['privacy_accepted']) || !empty($student['app_registered_at']) || !empty($student['privacy_accepted_at']));
  $privacyDateStr = '';
  if (!empty($student['privacy_accepted_at'])) {
      $privacyDateStr = ph_date('M d, Y g:i A', $student['privacy_accepted_at']);
  } elseif (!empty($student['app_registered_at'])) {
      $privacyDateStr = ph_date('M d, Y g:i A', $student['app_registered_at']);
  }

  $starBadge = '';
  if ($isAppRegistered) {
      $tooltipText = "This student downloaded the app and agreed to the Privacy Policy" . ($privacyDateStr ? " on " . $privacyDateStr : "");
      $starBadge = '
        <span class="privacy-star-tooltip-wrap" style="position: relative; display: inline-flex; align-items: center; margin-left: 6px; cursor: pointer;" title="' . htmlspecialchars($tooltipText) . '">
          <span style="font-size: 16px; color: #f59e0b; filter: drop-shadow(0 1px 2px rgba(245,158,11,0.4));">⭐</span>
          <style>
            .privacy-star-tooltip-wrap:hover .privacy-tooltip-box { display: block !important; }
          </style>
          <span class="privacy-tooltip-box" style="display: none; position: absolute; right: 0; top: 24px; background: #1e293b; color: #ffffff; padding: 8px 12px; border-radius: 8px; font-size: 11px; font-weight: 600; width: 250px; z-index: 9999; box-shadow: 0 10px 20px rgba(0,0,0,0.3); line-height: 1.45; text-align: left; word-break: break-word;">
            🔒 ' . htmlspecialchars($tooltipText) . '
          </span>
        </span>';
  }

  $minorText = $minorCount === 1 ? '1 Minor Offense' : $minorCount . ' Minor Offenses';
  $section4Count = floor($minorCount / 3);
  if ($section4Count > 0) {
      $s4Text = $section4Count === 1 ? '1 Section 4 Escalation' : $section4Count . ' Section 4 Escalations';
      $minorText .= ' <span style="font-weight:800;color:var(--red);font-size:12px;">(' . $s4Text . ')</span>';
  }
  
  $totalMajorCount = $majorCount + $section4Count;
  $majorText = $totalMajorCount === 1 ? '1 Major Offense' : $totalMajorCount . ' Major Offenses';

  $caseRows = '';
  if (!empty($activeCases)) {
    $firstCaseId = (int)$activeCases[0]['case_id'];
    $studentIdEnc = urlencode($student['student_id']);
    
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

  $historyHtml = '';
  if (empty($offenses)) {
    $historyHtml .= '<div style="font-size: 11.5px; color: var(--text-4); text-align: center; padding: 10px;">No offense history found.</div>';
  } else {
    $totalMinors = 0;
    foreach ($offenses as $o) {
        if ($o['level'] === 'MINOR') $totalMinors++;
    }
    
    $completedSection4Minors = floor($totalMinors / 3) * 3;
    
    $chronological = array_reverse($offenses);
    $minorCounter = 0;
    $processed = [];
    
    foreach ($chronological as $o) {
      $isMajor = ($o['level'] === 'MAJOR');
      $isSection4 = false;
      if (!$isMajor) {
          $minorCounter++;
          if ($minorCounter <= $completedSection4Minors) {
              $isSection4 = true;
          }
      }
      
      $o['isRed'] = $isMajor || $isSection4;
      $o['isSection4Label'] = $isSection4;
      $processed[] = $o;
    }
    
    $displayOffenses = array_reverse($processed);

    foreach ($displayOffenses as $o) {
      $isRed = $o['isRed'];
      $isSection4Label = $o['isSection4Label'] ?? false;
      $bgColor = $isRed ? 'var(--red-soft)' : 'var(--amber-soft)';
      $textColor = $isRed ? 'var(--red)' : 'var(--amber)';
      $borderColor = $isRed ? 'var(--red-mid)' : 'var(--amber-mid)';
      $dateStr = ph_date('M j, Y', $o['date_committed']);
      $timeStr = ph_date('g:i A', $o['date_committed']);
      $dateDisplay = $dateStr . '<br><span style="font-size:9px; opacity:0.9;">' . $timeStr . '</span>';
      
      $labelHtml = '';
      if ($isSection4Label) {
          $labelHtml = '<div style="display:inline-block; font-size: 9px; font-weight: 800; background: var(--red); color: white; padding: 2px 5px; border-radius: 4px; margin-bottom: 4px; letter-spacing: 0.5px;">SECTION 4</div><br>';
      } else if ($o['level'] === 'MAJOR') {
          $cat = isset($o['decided_category']) ? (int)$o['decided_category'] : 0;
          $caseStatus = isset($o['case_status']) ? (string)$o['case_status'] : '';
          $csrStatus = isset($o['csr_status']) ? (string)$o['csr_status'] : '';
          
          $p_details = json_decode($o['punishment_details'] ?? '{}', true);
          $is_manually_completed = !empty($p_details['completed']);
          
          $punishmentStatus = 'ONGOING';
          if ($is_manually_completed) {
              $punishmentStatus = 'COMPLETED';
          } else if ($cat === 0) {
              $punishmentStatus = 'ONGOING';
          } else if ($cat === 1) {
              $is_probation_active = false;
              if (!empty($o['probation_until'])) {
                  $is_probation_active = (strtotime($o['probation_until']) > time());
              }
              if ($is_probation_active) {
                  $punishmentStatus = 'ONGOING';
              } else if (in_array($caseStatus, ['CLOSED', 'RESOLVED'], true)) {
                  $punishmentStatus = 'COMPLETED';
              } else {
                  $punishmentStatus = 'ONGOING';
              }
          } else if ($cat === 2) {
              if (strtoupper($csrStatus) === 'COMPLETED') {
                  $punishmentStatus = 'COMPLETED';
              } else {
                  $punishmentStatus = 'ONGOING';
              }
          } else {
              if (in_array($caseStatus, ['CLOSED', 'RESOLVED'], true)) {
                  $punishmentStatus = 'COMPLETED';
              } else {
                  $punishmentStatus = 'ONGOING';
              }
          }
          
          $statusBadge = '';
          if ($punishmentStatus === 'COMPLETED') {
              $statusBadge = '<span style="display:inline-block; font-size: 9px; font-weight: 800; background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; padding: 2px 5px; border-radius: 4px; margin-bottom: 4px; margin-left: 4px; letter-spacing: 0.5px;">COMPLETED</span>';
          } else {
              $statusBadge = '<span style="display:inline-block; font-size: 9px; font-weight: 800; background: #fff3cd; color: #664d03; border: 1px solid #ffecb5; padding: 2px 5px; border-radius: 4px; margin-bottom: 4px; margin-left: 4px; letter-spacing: 0.5px;">ONGOING</span>';
          }
          
          $catLabel = '';
          if ($cat >= 1 && $cat <= 5) {
              $categoryNames = [1 => 'Probation', 2 => 'Formative Intervention', 3 => 'Non-Readmission', 4 => 'Exclusion', 5 => 'Expulsion'];
              $catLabel = ' - CATEGORY ' . $cat . ' (' . strtoupper($categoryNames[$cat]) . ')';
          }
          $labelHtml = '<div style="display:inline-block; font-size: 9px; font-weight: 800; background: var(--red); color: white; padding: 2px 5px; border-radius: 4px; margin-bottom: 4px; letter-spacing: 0.5px;">MAJOR' . $catLabel . '</div>' . $statusBadge . '<br>';
      }

      $historyHtml .= '
      <div style="background: '.$bgColor.'; border: 1px solid '.$borderColor.'; border-radius: 6px; padding: 8px 10px; margin-bottom: 6px; display: flex; justify-content: space-between; align-items: flex-start; gap: 8px;">
        <div style="flex: 1;">
          '.$labelHtml.'
          <div style="font-size: 11.5px; font-weight: 700; color: '.$textColor.'; line-height: 1.3;">'.htmlspecialchars($o['code']).' — '.htmlspecialchars($o['name']).'</div>
        </div>
        <div style="font-size: 10px; font-weight: 600; color: '.$textColor.'; opacity: 0.8; white-space: nowrap; text-align: right; margin-top: 1px;">'.$dateDisplay.'</div>
      </div>';
    }
  }

  $nteRows = [];
  if (!empty($student['student_id'])) {
      try {
          if (function_exists('ensure_notice_to_explain_table')) {
              ensure_notice_to_explain_table();
          }
          $nteRows = db_all("SELECT nte.*, 
                     COALESCE(nte.case_id, uco.case_id) AS resolved_case_id
              FROM notice_to_explain nte
              LEFT JOIN upcc_case_offense uco ON uco.offense_id = nte.offense_id
              WHERE nte.student_id = :sid 
              ORDER BY nte.created_at DESC
          ", [':sid' => $student['student_id']]) ?: [];
      } catch (Throwable $ex) {
          $nteRows = [];
      }
  }

  $nteCaseMap = [];
  foreach ($nteRows as $nte) {
      $cid = !empty($nte['resolved_case_id']) ? (int)$nte['resolved_case_id'] : (!empty($nte['case_id']) ? (int)$nte['case_id'] : 0);
      if ($cid > 0) {
          $nteCaseMap[$cid] = $nte;
      }
  }

  $nteHistoryHtml = '';
  $totalNteDisplay = count($nteRows);

  // Render SENT Form F-005 records
  foreach ($nteRows as $nte) {
      $nteId = (int)$nte['nte_id'];
      $caseIdForNte = !empty($nte['resolved_case_id']) ? (int)$nte['resolved_case_id'] : (!empty($nte['case_id']) ? (int)$nte['case_id'] : 0);
      $nteDate = ph_date('M j, Y', $nte['created_at']);
      $nteTime = ph_date('h:i:s A', $nte['created_at']);
      $irNo = htmlspecialchars($nte['incident_report_no'] ?: ('IR-' . ph_date('Y', $nte['created_at']) . '-' . $nteId));
      
      $fileLink = '';
      if (!empty($nte['attachment_path'])) {
          $fileUrl = '../' . htmlspecialchars($nte['attachment_path']);
          $fileLink = '
          <a href="' . $fileUrl . '" target="_blank" download style="color:var(--blue); font-weight:700; font-size:11px; text-decoration:underline; display:inline-flex; align-items:center; gap:4px;">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            Download Form F-005
          </a>';
      }

      $reuploadBtn = '
      <button type="button" class="btn-trigger-nte-upload" data-case-id="' . $caseIdForNte . '" data-student-id="' . htmlspecialchars($student['student_id']) . '" onclick="window.openDirectNteUploadModal(this, event, ' . $caseIdForNte . ', \'' . htmlspecialchars($student['student_id']) . '\'); return false;" style="background:#fee2e2; border:1px solid #fca5a5; color:#dc2626; font-size:13px; font-weight:800; width:26px; height:26px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; cursor:pointer; transition:all 0.15s; line-height:1;" title="Re-upload or replace Form F-005" onmouseover="this.style.background=\'#dc2626\'; this.style.color=\'#fff\';" onmouseout="this.style.background=\'#fee2e2\'; this.style.color=\'#dc2626\';">✕</button>';

      $showInHearing = (int)($nte['show_in_hearing'] ?? 1);
      $hearingPillBg = $showInHearing ? '#dcfce7' : '#f1f5f9';
      $hearingPillColor = $showInHearing ? '#15803d' : '#64748b';
      $hearingLabel = $showInHearing ? 'YES (Shown in Hearing)' : 'NO (Private)';

      $hearingToggleBtn = '
      <div style="margin-top:6px; padding-top:6px; border-top:1px dashed #bbf7d0; display:flex; align-items:center; justify-content:space-between; font-size:11px;">
        <span style="font-weight:700; color:#166534;">📷 Photo in Student Hearing:</span>
        <button type="button" onclick="toggleHearingPhoto(\'nte\', ' . $nteId . ', ' . ($showInHearing ? 0 : 1) . ', this)" style="background:' . $hearingPillBg . '; color:' . $hearingPillColor . '; font-weight:800; font-size:10.5px; border:1px solid ' . ($showInHearing ? '#86efac' : '#cbd5e1') . '; padding:3px 8px; border-radius:12px; cursor:pointer;">
          ' . $hearingLabel . '
        </button>
      </div>';

      $nteHistoryHtml .= '
      <div style="background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 6px; padding: 10px; margin-bottom: 6px;">
        <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
          <span style="font-size:11px; font-weight:800; color:#166534;">✅ Form F-005 Sent to Outlook</span>
          <span style="font-size:10px; color:#15803d; font-weight:600; white-space:nowrap;">' . $irNo . '</span>
        </div>
        <div style="font-size:11px; color:#334155; margin-bottom:6px;">
          Submitted: <strong>' . $nteDate . ' at ' . $nteTime . '</strong>
        </div>
        <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
          ' . ($fileLink ?: '<div></div>') . '
          ' . $reuploadBtn . '
        </div>
        ' . $hearingToggleBtn . '
      </div>';
  }

  // Render SKIPPED / NOT SENT cards for active cases
  if (!empty($activeCases)) {
      foreach ($activeCases as $acase) {
          $acid = (int)$acase['case_id'];
          if (empty($nteCaseMap[$acid])) {
              $totalNteDisplay++;
              $caseDateStr = !empty($acase['created_at']) ? ph_date('M j, Y \a\t h:i:s A', $acase['created_at']) : 'During Offense Registration';
              $nteHistoryHtml .= '
              <div style="background: #fffbeb; border: 1px solid #fef3c7; border-radius: 6px; padding: 10px; margin-bottom: 6px;">
                <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:4px;">
                  <span style="font-size:11px; font-weight:800; color:#92400e;">⚠️ Form F-005 Skipped / Not Sent</span>
                  <span style="font-size:10px; color:#b45309; font-weight:600;">Case #' . $acid . '</span>
                </div>
                <div style="font-size:11px; color:#334155; margin-bottom:4px;">
                  Skipped On: <strong>' . $caseDateStr . '</strong>
                </div>
                <div style="font-size:11px; color:#475569; margin-bottom:6px;">
                  Form F-005 was not sent during offense registration.
                </div>
                <button type="button" class="btn-trigger-nte-upload" data-case-id="' . $acid . '" data-student-id="' . htmlspecialchars($student['student_id']) . '" style="background:#1b2b6b; color:#fff; font-size:11px; font-weight:700; border:none; padding:4px 10px; border-radius:4px; cursor:pointer;">
                  📤 Upload & Send Form F-005
                </button>
              </div>';
          }
      }
  }

  if (empty($nteHistoryHtml)) {
      $nteHistoryHtml = '
      <div style="background: #fffbeb; border: 1px solid #fde68a; border-radius: 6px; padding: 10px; text-align: center;">
        <div style="font-size:11px; font-weight:800; color:#92400e; margin-bottom:2px;">⚠️ Form F-005 Skipped / Not Sent</div>
        <div style="font-size:11px; color:#78350f;">No Form F-005 document has been sent for this student yet.</div>
      </div>';
  }

  return '
  <div class="student-info-card">
    <div class="sic-header">
      <div class="sic-avatar">
        <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M15 9h6m-6 3h6m-6 3h6M3 9h6m-6 3h6m-6 3h6M9 3v18M3 3h18a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/></svg>
      </div>
      <div class="sic-title">Student Information</div>
    </div>
    <div class="sic-body">
      <div class="sic-row"><span class="sic-label">Full Name:</span><span class="sic-value">' . $fullName . $starBadge . '</span></div>
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
        <details style="background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; overflow: hidden;">
          <summary style="padding: 10px 14px; font-size: 12px; font-weight: 700; cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; color: var(--text-2); user-select: none;">
            View Full History
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width: 14px; height: 14px; transition: transform 0.2s;"><path d="M6 9l6 6 6-6"/></svg>
          </summary>
          <div style="padding: 12px; border-top: 1px solid var(--border); background: var(--surface); max-height: 250px; overflow-y: auto;">
            ' . $historyHtml . '
          </div>
        </details>
      </div>

      <div style="margin-top: 8px;">
        <details style="background: var(--surface-2); border: 1px solid var(--border); border-radius: 8px; overflow: hidden;">
          <summary style="padding: 10px 14px; font-size: 12px; font-weight: 700; cursor: pointer; list-style: none; display: flex; justify-content: space-between; align-items: center; color: var(--text-2); user-select: none;">
            <span style="display:flex; align-items:center; gap:6px;">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
              View Form F-005 History (' . $totalNteDisplay . ')
            </span>
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width: 14px; height: 14px; transition: transform 0.2s;"><path d="M6 9l6 6 6-6"/></svg>
          </summary>
          <div style="padding: 12px; border-top: 1px solid var(--border); background: var(--surface); max-height: 220px; overflow-y: auto;">
            ' . $nteHistoryHtml . '
          </div>
        </details>
        <style>details > summary::-webkit-details-marker { display: none; } details[open] summary svg { transform: rotate(180deg); }</style>
      </div>
    </div>
  </div>';
}

function renderStudentRecordModal($student, $guardianEmail, int $minorCount, int $majorCount, array $activeCases, bool $hasActiveSection4, int $section4Minors, array $offenses = []) {
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
  $section4CountModal = floor($minorCount / 3);
  $totalMajorCountModal = $majorCount + $section4CountModal;
  
  if ($section4CountModal > 0) {
      $s4Text = $section4CountModal === 1 ? '1 Section 4 Escalation' : $section4CountModal . ' Section 4 Escalations';
      $minorText .= ' <span style="font-weight:800;color:var(--red);font-size:12px;">(' . $s4Text . ')</span>';
  }
  
  $majorText = $totalMajorCountModal === 1 ? '1 Major Offense' : $totalMajorCountModal . ' Major Offenses';

  $offensesHtml = '';
  if (!empty($offenses)) {
      $offensesHtml .= '<details style="margin-top:12px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px;">';
      $offensesHtml .= '<summary style="cursor:pointer; font-weight:600; font-size:13px; color:#475569; outline:none; user-select:none;">View all past offenses (' . count($offenses) . ')</summary>';
      $offensesHtml .= '<div style="margin-top:10px; max-height:200px; overflow-y:auto; border-top:1px solid #e2e8f0; padding-top:10px; text-align:left;">';
      foreach ($offenses as $off) {
          $dt = ph_date('M j, Y g:i A', $off['date_committed']);
          $lvlColor = $off['level'] === 'MAJOR' ? 'color:var(--red);' : 'color:var(--amber);';
          $offensesHtml .= '<div style="font-size:12px; margin-bottom:8px; padding-bottom:8px; border-bottom:1px dashed #e2e8f0;">';
          $offensesHtml .= '<div style="display:flex; justify-content:space-between; margin-bottom:4px;">';
          $catLabel = '';
          $statusBadge = '';
          if ($off['level'] === 'MAJOR') {
              $cat = isset($off['decided_category']) ? (int)$off['decided_category'] : 0;
              $caseStatus = isset($off['case_status']) ? (string)$off['case_status'] : '';
              $csrStatus = isset($off['csr_status']) ? (string)$off['csr_status'] : '';
              
              $p_details = json_decode($off['punishment_details'] ?? '{}', true);
              $is_manually_completed = !empty($p_details['completed']);

              $punishmentStatus = 'ONGOING';
              if ($is_manually_completed) {
                  $punishmentStatus = 'COMPLETED';
              } else if ($cat === 0) {
                  $punishmentStatus = 'ONGOING';
              } else if ($cat === 1) {
                  $is_probation_active = false;
                  if (!empty($off['probation_until'])) {
                      $is_probation_active = (strtotime($off['probation_until']) > time());
                  }
                  if ($is_probation_active) {
                      $punishmentStatus = 'ONGOING';
                  } else if (in_array($caseStatus, ['CLOSED', 'RESOLVED'], true)) {
                      $punishmentStatus = 'COMPLETED';
                  } else {
                      $punishmentStatus = 'ONGOING';
                  }
              } else if ($cat === 2) {
                  if (strtoupper($csrStatus) === 'COMPLETED') {
                      $punishmentStatus = 'COMPLETED';
                  } else {
                      $punishmentStatus = 'ONGOING';
                  }
              } else {
                  if (in_array($caseStatus, ['CLOSED', 'RESOLVED'], true)) {
                      $punishmentStatus = 'COMPLETED';
                  } else {
                      $punishmentStatus = 'ONGOING';
                  }
              }
              
              if ($punishmentStatus === 'COMPLETED') {
                  $statusBadge = ' <span style="display:inline-block; font-size: 9px; font-weight: 800; background: #d1e7dd; color: #0f5132; border: 1px solid #badbcc; padding: 2px 5px; border-radius: 4px; letter-spacing: 0.5px;">COMPLETED</span>';
              } else {
                  $statusBadge = ' <span style="display:inline-block; font-size: 9px; font-weight: 800; background: #fff3cd; color: #664d03; border: 1px solid #ffecb5; padding: 2px 5px; border-radius: 4px; letter-spacing: 0.5px;">ONGOING</span>';
              }
              
              if ($cat >= 1 && $cat <= 5) {
                  $categoryNames = [1 => 'Probation', 2 => 'Formative Intervention', 3 => 'Non-Readmission', 4 => 'Exclusion', 5 => 'Expulsion'];
                  $catLabel = ' (Category ' . $cat . ' - ' . $categoryNames[$cat] . ')';
              }
          }
          $offensesHtml .= '<strong style="' . $lvlColor . '">' . htmlspecialchars($off['level']) . $catLabel . $statusBadge . ' - ' . htmlspecialchars($off['code']) . '</strong>';
          $offensesHtml .= '<span style="color:#94a3b8; font-size:11px;">' . $dt . '</span>';
          $offensesHtml .= '</div>';
          $offensesHtml .= '<div style="color:#64748b; line-height:1.4;">' . htmlspecialchars($off['name']) . '</div>';
          $offensesHtml .= '</div>';
      }
      $offensesHtml .= '</div></details>';
  }
            
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
            ' . $offensesHtml . '
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
    .wrap { display: flex; flex-direction: column; min-height: 100%; }

    .page-hero {
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
    .hero-left { display: flex; align-items: center; gap: 14px; }
    .btn-back {
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
    .btn-back svg { width: 14px; height: 14px; }
    .btn-back:hover { border-color: var(--blue); color: var(--blue); background: var(--blue-soft); }
    .page-title { font-size: 17px; font-weight: 800; letter-spacing: -.3px; }
    .page-sub   { font-size: 12px; color: var(--text-4); margin-left: auto; font-weight: 500; }

    .content-area { padding: 24px 32px; }
    .content-grid {
      display: grid;
      grid-template-columns: 1fr 340px;
      gap: 20px;
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
    .card-title { font-size: 15px; font-weight: 700; letter-spacing: -.2px; }
    .card-sub   { font-size: 12px; color: var(--text-4); margin-top: 2px; }
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

    .alert-ok {
      background: var(--green-soft);
      border: 1.5px solid var(--green-mid);
      border-radius: var(--radius-sm);
      padding: 14px 16px;
      color: var(--green);
      margin-bottom: 20px;
      font-size: 13px;
      font-weight: 600;
    }
    .alert-err {
      background: var(--red-soft);
      border: 1.5px solid #fca5a5;
      border-radius: var(--radius-sm);
      padding: 14px 16px;
      color: var(--red);
      margin-bottom: 20px;
      font-size: 13px;
      font-weight: 600;
    }
    .alert-err div { margin: 3px 0; }

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
      border-radius: 12px;
      padding: 20px;
      display: flex;
      gap: 16px;
      margin-bottom: 16px;
      box-shadow: 0 4px 12px rgba(0,0,0,0.05), inset 0 1px 0 rgba(255,255,255,0.5);
      border: 1px solid rgba(0,0,0,0.06);
      animation: apIn .3s cubic-bezier(0.16, 1, 0.3, 1) both;
      backdrop-filter: blur(8px);
      transition: transform 0.2s, box-shadow 0.2s;
    }
    .alert-panel:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(0,0,0,0.08), inset 0 1px 0 rgba(255,255,255,0.6);
    }
    @keyframes apIn {
      from { opacity: 0; transform: translateY(10px) scale(0.98); }
      to   { opacity: 1; transform: translateY(0) scale(1); }
    }
    .alert-panel--info     { background: linear-gradient(135deg, #eff6ff 0%, #dbeafe 100%);  border-color: #bfdbfe; color: #1e3a8a; }
    .alert-panel--warning  { background: linear-gradient(135deg, #fffbeb 0%, #fef3c7 100%); border-color: #fde68a; color: #92400e; }
    .alert-panel--critical { background: linear-gradient(135deg, #fdf2f8 0%, #fce7f3 100%);  border-color: #fbcfe8; color: #9d174d; }
    .alert-panel--major    { background: linear-gradient(135deg, #fef2f2 0%, #fee2e2 100%);   border-color: #fecaca; color: #991b1b; }
    
    /* Red Glowing Pulse for DISMISSED card banner */
    .alert-panel--dismissed-glow {
      background: linear-gradient(135deg, #fff1f2 0%, #ffe4e6 100%) !important;
      border: 1.5px solid #f43f5e !important;
      color: #881337 !important;
      margin-bottom: 20px !important;
      animation: alertRedGlowPulse 1.8s infinite ease-in-out !important;
    }
    @keyframes alertRedGlowPulse {
      0% {
        box-shadow: 0 0 10px rgba(244, 63, 94, 0.45), 0 4px 16px rgba(225, 29, 72, 0.25);
        border-color: #f43f5e;
      }
      50% {
        box-shadow: 0 0 26px rgba(244, 63, 94, 0.85), 0 6px 22px rgba(225, 29, 72, 0.5);
        border-color: #e11d48;
      }
      100% {
        box-shadow: 0 0 10px rgba(244, 63, 94, 0.45), 0 4px 16px rgba(225, 29, 72, 0.25);
        border-color: #f43f5e;
      }
    }
    .alert-panel--dismissed-glow .ap-icon {
      background: linear-gradient(135deg, #e11d48 0%, #be123c 100%) !important;
      color: #ffffff !important;
      box-shadow: 0 2px 10px rgba(225, 29, 72, 0.45) !important;
    }
    
    .ap-icon {
      width: 40px; height: 40px;
      border-radius: 12px;
      display: grid;
      place-items: center;
      flex-shrink: 0;
      box-shadow: 0 2px 6px rgba(0,0,0,0.08);
    }
    .ap-icon svg { width: 22px; height: 22px; }
    .alert-panel--info     .ap-icon { background: linear-gradient(135deg, #60a5fa, #3b82f6); color: #fff; }
    .alert-panel--warning  .ap-icon { background: linear-gradient(135deg, #fbbf24, #f59e0b); color: #fff; }
    .alert-panel--critical .ap-icon { background: linear-gradient(135deg, #f472b6, #ec4899); color: #fff; }
    .alert-panel--major    .ap-icon { background: linear-gradient(135deg, #f87171, #ef4444); color: #fff; }
    
    .ap-body { flex: 1; min-width: 0; }
    .ap-title { font-size: 15px; font-weight: 800; letter-spacing: -0.3px; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }
    
    .ap-projected-badge {
      font-size: 12px;
      font-weight: 700;
      padding: 8px 12px;
      border-radius: 8px;
      margin-bottom: 14px;
      line-height: 1.4;
      border: 1px solid rgba(0,0,0,.08);
      display: inline-flex;
      align-items: center;
      gap: 6px;
      box-shadow: 0 1px 2px rgba(0,0,0,0.03);
    }
    .ap-projected--info     { background: #ffffff; color: #1e3a8a; }
    .ap-projected--warning  { background: #ffffff; color: #92400e; }
    .ap-projected--critical {
      background: #ffffff;
      color: #9d174d;
      border-color: rgba(190,24,93,.2);
      animation: criticalPulse 2s ease infinite;
    }
    
    .ap-progress { margin-bottom: 12px; }
    .ap-progress-track {
      height: 6px;
      border-radius: 999px;
      background: rgba(0,0,0,.06);
      overflow: hidden;
      box-shadow: inset 0 1px 2px rgba(0,0,0,0.04);
    }
    .ap-progress-fill {
      height: 100%;
      border-radius: 999px;
      transition: width .6s cubic-bezier(0.22, 1, 0.36, 1);
      position: relative;
    }
    .ap-progress-fill::after {
      content: '';
      position: absolute;
      top: 0; left: 0; right: 0; bottom: 0;
      background: linear-gradient(90deg, transparent, rgba(255,255,255,0.7), transparent);
      animation: shimmer 1.5s infinite;
    }
    @keyframes shimmer { 0% { transform: translateX(-100%); } 100% { transform: translateX(100%); } }
    .ap-progress--info     { background: linear-gradient(90deg, #60a5fa, #2563eb); }
    .ap-progress--warning  { background: linear-gradient(90deg, #fbbf24, #d97706); }
    .ap-progress--critical { background: linear-gradient(90deg, #f472b6, #db2777); }
    
    .ap-progress-label { font-size: 11.5px; font-weight: 700; opacity: .85; margin-top: 6px; display: block; }
    .ap-desc { font-size: 13px; line-height: 1.6; margin-bottom: 14px; opacity: .9; font-weight: 500; }
    
    .ap-email {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      font-weight: 600;
      padding: 10px 12px;
      border-radius: 8px;
      background: #ffffff;
      border: 1px solid rgba(0,0,0,.08);
      margin-bottom: 12px;
      word-break: break-all;
      box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }
    .ap-email svg { width: 15px; height: 15px; flex-shrink: 0; opacity: 0.8; }
    .ap-email--warn { color: #dc2626; background: #fef2f2; border-color: #fecaca; }
    
    .ap-steps {
      display: flex;
      flex-direction: column;
      gap: 6px;
      margin-top: 14px;
      padding-top: 14px;
      border-top: 1px dashed rgba(0,0,0,.15);
    }
    .ap-step {
      display: flex;
      align-items: center;
      font-size: 12px;
      font-weight: 600;
      padding: 8px 12px;
      border-radius: 8px;
      background: rgba(255,255,255,.4);
      color: inherit;
      opacity: .6;
      transition: all 0.2s;
    }
    .ap-step:hover { background: rgba(255,255,255,.6); }
    .ap-step--done     { opacity: 1; background: #ffffff; box-shadow: 0 1px 2px rgba(0,0,0,0.02); }
    .ap-step--done::before { content: '✓ '; color: #10b981; margin-right: 6px; font-weight: 800; }
    .ap-step--next     { opacity: 1; background: #ffffff; font-weight: 800; border: 1.5px solid rgba(0,0,0,.08); box-shadow: 0 2px 4px rgba(0,0,0,0.04); transform: scale(1.02); margin: 2px 0; }
    .ap-step--next::before { content: '→ '; margin-right: 6px; color: var(--blue); }

    @keyframes criticalPulse {
      0%, 100% { box-shadow: 0 0 0 0 rgba(190,24,93,.0); }
      50%       { box-shadow: 0 0 0 6px rgba(190,24,93,.4); }
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
      height: 520px;
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

    #letter_body_editor .ql-container {
      flex: 1;
      height: auto !important;
      overflow-y: auto !important;
      font-family: inherit;
    }
    #letter_body_editor .ql-editor {
      min-height: 100%;
      padding: 14px 16px;
    }

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
      max-width: 95%;
      max-height: 95vh;
      overflow-y: auto;
    }
    .modal-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      padding: 18px 22px 12px;
      border-bottom: 1px solid var(--border);
    }
    .modal-header h3 { font-size: 16px; font-weight: 700; margin: 0; }
    .modal-close {
      background: none;
      border: none;
      font-size: 24px;
      line-height: 1;
      color: var(--text-4);
      cursor: pointer;
      padding: 0 6px;
    }
    .modal-close:hover { color: var(--text-1); }
    .modal-body { padding: 20px 22px; }
    .modal-footer {
      padding: 12px 22px 18px;
      border-top: 1px solid var(--border);
      display: flex;
      justify-content: flex-end;
      gap: 10px;
    }

    .btn-check-student { display: none !important; }
    .student-input-container {
      position: relative;
      width: 100%;
    }
    .student-suggestions-dropdown {
      position: absolute;
      top: calc(100% + 4px);
      left: 0; right: 0;
      background: #ffffff;
      border: 1.5px solid #c7d2fe;
      border-radius: 12px;
      box-shadow: 0 12px 28px rgba(27, 41, 118, 0.2);
      z-index: 99999;
      display: none;
      max-height: 240px;
      overflow-y: auto;
      -webkit-overflow-scrolling: touch;
    }
    .student-suggestions-dropdown.show { display: block; }
    .suggestion-item {
      padding: 10px 14px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      cursor: pointer;
      border-bottom: 1px solid #f1f5f9;
      transition: background 0.15s ease;
    }
    .suggestion-item:last-child { border-bottom: none; }
    .suggestion-item:hover, .suggestion-item.active { background: #eef2ff; }
    .suggestion-info { display: flex; flex-direction: column; }
    .suggestion-id { font-weight: 700; color: #1b2b6b; font-size: 13px; }
    .suggestion-name { font-size: 12px; color: #475569; }
    .suggestion-program { font-size: 11px; color: #64748b; font-weight: 600; }

    @media (max-width: 768px) {
      .content-grid { padding: 16px; grid-template-columns: 1fr; }
      .content-area { padding: 16px; }
      .form-row     { grid-template-columns: 1fr; }
      .letter-grid  { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>

  <!-- REJECT GUARD REPORT CONFIRMATION MODAL -->
  <div id="rejectGuardReportModal" class="modal" style="display: none;">
    <div class="modal-content" style="max-width: 440px; width: 92%; border-radius: 16px; overflow: hidden; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.35); background: #ffffff; margin: auto;">
      <div style="padding: 26px 24px 22px; text-align: center;">
        <div style="width: 56px; height: 56px; background: #fef2f2; border: 1px solid #fecaca; color: #dc2626; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 26px; margin: 0 auto 16px;">
          ⚠️
        </div>
        <h3 style="font-size: 18px; font-weight: 800; color: #0f172a; margin-bottom: 6px;">Reject Guard Violation Report?</h3>
        <p style="font-size: 13.5px; color: #64748b; line-height: 1.5; margin-bottom: 16px;">
          Are you sure you want to reject and dismiss <strong>Report #<span id="rejectModalReportId"><?php echo (int)($pendingGuardReport['report_id'] ?? 0); ?></span></strong>?
        </p>

        <?php if (!empty($pendingGuardReport)): ?>
        <div style="background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px 14px; text-align: left; font-size: 12.5px; margin-bottom: 18px; line-height: 1.45;">
          <div style="color: #334155; margin-bottom: 4px;"><strong>Student:</strong> <?php echo htmlspecialchars(($studentInfo['student_fn'] ?? '') . ' ' . ($studentInfo['student_ln'] ?? '')); ?> (<?php echo htmlspecialchars($pendingGuardReport['student_id'] ?? ''); ?>)</div>
          <div style="color: #334155; margin-bottom: 4px;"><strong>Offense:</strong> <?php echo htmlspecialchars($pendingGuardReport['offense_name'] ?? 'Violation'); ?></div>
          <div style="color: #64748b; font-style: italic;">"<?php echo htmlspecialchars($pendingGuardReport['description'] ?? ''); ?>"</div>
        </div>
        <?php endif; ?>

        <p style="font-size: 12px; color: #94a3b8; margin-bottom: 20px;">
          This action will dismiss the pending report and mark the notification as read.
        </p>

        <div style="display: flex; gap: 10px; justify-content: center;">
          <button type="button" onclick="closeRejectGuardModal()" style="flex: 1; padding: 11px 16px; background: #f1f5f9; color: #475569; font-weight: 700; font-size: 13px; border-radius: 8px; border: 1px solid #cbd5e1; cursor: pointer;">
            Cancel
          </button>
          <button type="button" id="confirmRejectBtn" onclick="confirmRejectGuardReport()" style="flex: 1; padding: 11px 16px; background: #dc2626; color: #ffffff; font-weight: 700; font-size: 13px; border-radius: 8px; border: none; cursor: pointer; box-shadow: 0 4px 12px rgba(220,38,38,0.25);">
            Reject Report
          </button>
        </div>
      </div>
    </div>
  </div>

  <script>
    window.__identitrackDisableGlobalScan = true;

    window.openDirectNteUploadModal = function(btn, evt, caseId, studentId) {
        if (evt) {
            if (evt.preventDefault) evt.preventDefault();
            if (evt.stopPropagation) evt.stopPropagation();
        }
        let cid = caseId || 0;
        let sid = studentId || '';
        if (btn && btn.getAttribute) {
            cid = btn.getAttribute('data-case-id') || cid;
            sid = btn.getAttribute('data-student-id') || sid;
        }
        const cidEl = document.getElementById('directNteCaseId');
        const sidEl = document.getElementById('directNteStudentId');
        const msgEl = document.getElementById('directNteUploadMsg');
        if (cidEl) cidEl.value = cid;
        if (sidEl) sidEl.value = sid;
        if (msgEl) msgEl.innerHTML = '';
        const modal = document.getElementById('directNteUploadModal');
        if (modal) {
            modal.classList.add('active');
            modal.style.cssText = 'display:flex !important; position:fixed !important; top:0 !important; left:0 !important; width:100vw !important; height:100vh !important; background:rgba(15,23,42,0.75) !important; z-index:999999 !important; align-items:center !important; justify-content:center !important;';
        }
        return false;
    };

    window.closeDirectNteUploadModal = function() {
        const modal = document.getElementById('directNteUploadModal');
        if (modal) {
            modal.classList.remove('active');
            modal.style.cssText = 'display:none !important;';
        }
    };

    <?php
      $cleanReportsJs = [];
      if (!empty($pendingGuardReports)) {
          foreach ($pendingGuardReports as $pr) {
              $cleanReportsJs[] = [
                  'report_id' => (int)($pr['report_id'] ?? 0),
                  'student_id' => (string)($pr['student_id'] ?? ''),
                  'offense_type_id' => (int)($pr['offense_type_id'] ?? 0),
                  'offense_code' => (string)($pr['offense_code'] ?? ''),
                  'offense_name' => (string)($pr['offense_name'] ?? ''),
                  'offense_level' => (string)($pr['offense_level'] ?? ''),
                  'guard_name' => (string)($pr['guard_name'] ?? ''),
                  'description' => (string)($pr['description'] ?? ''),
                  'created_at' => (string)($pr['created_at'] ?? ''),
                  'date_committed' => (string)($pr['date_committed'] ?? '')
              ];
          }
      }
    ?>
    window.guardReportsList = <?php echo json_encode($cleanReportsJs); ?> || [];
    window.currentReportIndex = 0;

    window.selectGuardReportIndex = function(idx) {
        if (!window.guardReportsList || window.guardReportsList.length === 0) return;
        if (idx < 0) idx = 0;
        if (idx >= window.guardReportsList.length) idx = window.guardReportsList.length - 1;
        
        const isDifferent = (window.currentReportIndex !== idx);
        window.currentReportIndex = idx;

        const r = window.guardReportsList[idx];
        if (!r) return;

        const banner = document.getElementById('pendingGuardReportBanner');
        const r1 = document.getElementById('cardStackRight1');
        const r2 = document.getElementById('cardStackRight2');

        // Helper to escape HTML characters
        function escapeHtmlStr(str) {
            if (!str) return '';
            return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#039;');
        }

        function getOrdinal(n) {
            const s = ["th", "st", "nd", "rd"];
            const v = n % 100;
            return n + (s[(v - 20) % 10] || s[v] || s[0]);
        }

        const applyDataUpdates = () => {
            const ordStr = getOrdinal(idx + 1);

            // Update banner UI elements
            const badge = document.getElementById('bannerReportBadge');
            if (badge) badge.textContent = ordStr + ' Report (# ' + r.report_id + ')';

            // Render interactive click-to-switch report pill tabs in carousel header
            const pillsContainer = document.getElementById('carouselReportPills');
            if (pillsContainer && window.guardReportsList && window.guardReportsList.length > 1) {
                pillsContainer.innerHTML = window.guardReportsList.map((item, i) => {
                    const isActive = (i === idx);
                    const label = getOrdinal(i + 1) + ' Report';
                    const activeStyle = 'background:#16a34a; color:#ffffff; font-weight:800; font-size:11.5px; padding:5px 14px; border-radius:12px; border:none; cursor:pointer; box-shadow:0 2px 6px rgba(22,163,74,0.35); transition:all 0.18s;';
                    const inactiveStyle = 'background:#ffffff; color:#15803d; font-weight:700; font-size:11.5px; padding:5px 14px; border-radius:12px; border:1px solid #86efac; cursor:pointer; transition:all 0.18s;';
                    return `<button type="button" onclick="selectGuardReportIndex(${i})" style="${isActive ? activeStyle : inactiveStyle}" title="Click to switch to ${label} (Report #${item.report_id})">${isActive ? '🛡️ ' : ''}${label}${isActive ? ' (Active)' : ''}</button>`;
                }).join('');
            }

            const counter = document.getElementById('carouselReportCounter');
            if (counter) counter.textContent = 'Showing ' + ordStr + ' Report of ' + window.guardReportsList.length;

            const prevBtn = document.getElementById('btnPrevReport');
            const nextBtn = document.getElementById('btnNextReport');
            if (prevBtn) prevBtn.disabled = (idx === 0);
            if (nextBtn) nextBtn.disabled = (idx === window.guardReportsList.length - 1);

            const meta = document.getElementById('bannerGuardMeta');
            if (meta) {
                let dt = '';
                if (r.created_at) {
                    const d = new Date(r.created_at.replace(' ', 'T'));
                    if (!isNaN(d.getTime())) {
                        dt = d.toLocaleDateString('en-US', { month:'short', day:'numeric', year:'numeric' }) + ' ' + d.toLocaleTimeString('en-US', { hour:'numeric', minute:'2-digit', hour12:true });
                    } else {
                        dt = r.created_at;
                    }
                }
                meta.innerHTML = 'Filed by <strong>' + (r.guard_name || 'Campus Security Guard') + '</strong> on ' + dt;
            }

            const offTitle = document.getElementById('bannerOffenseTitle');
            if (offTitle) offTitle.textContent = (r.offense_name || 'Violation Report') + ' (' + (r.offense_code || 'MIN-01') + ')';

            const notes = document.getElementById('bannerGuardNotes');
            if (notes) notes.textContent = '"' + (r.description || 'No additional notes provided.') + '"';

            // Update hidden input & auto-fill form inputs
            const hiddenInput = document.getElementById('pending_report_id');
            if (hiddenInput) hiddenInput.value = r.report_id;

            const typeSelect = document.getElementById('offense_type_id');
            if (typeSelect && r.offense_type_id > 0) {
                typeSelect.value = r.offense_type_id;
            }
            const descInput = document.getElementById('description');
            if (descInput) {
                descInput.value = r.description || '';
            }
            const dateInput = document.getElementById('date_committed');
            if (dateInput && r.created_at) {
                dateInput.value = r.created_at.replace(' ', 'T').substring(0, 16);
            }

            // Update peeking right cards
            if (r1 && window.guardReportsList.length > 1) {
                const nextIdx = (idx + 1) % window.guardReportsList.length;
                const rNext = window.guardReportsList[nextIdx];
                r1.setAttribute('data-report-index', nextIdx);
                r1.onclick = function(e) {
                    if (e) { e.preventDefault(); e.stopPropagation(); }
                    window.selectGuardReportIndex(nextIdx);
                };
                r1.style.cursor = 'pointer';
                r1.title = 'Click to switch to Report #' + rNext.report_id;
                
                const offCode = rNext.offense_code || 'MIN-01';

                r1.innerHTML = '<div style="position:absolute; right:14px; top:50%; transform:translateY(-50%); text-align:right; font-weight:800; font-size:11.5px; color:#0369a1; pointer-events:none; white-space:nowrap;">' +
                  '<div style="background:#0284c7; color:#ffffff; font-size:11.5px; font-weight:800; padding:8px 16px; border-radius:16px; display:inline-flex; align-items:center; gap:6px; box-shadow:0 4px 14px rgba(2,132,199,0.45); border:1.5px solid #38bdf8; letter-spacing:0.2px;">' +
                    '<span>🛡️ Report #' + rNext.report_id + '</span>' +
                    '<span style="opacity:0.9; font-size:10.5px; font-weight:700;">(' + escapeHtmlStr(offCode) + ')</span>' +
                    '<span style="margin-left:4px; font-size:13px;">›</span>' +
                  '</div>' +
                  '</div>';
            }

            if (r2 && window.guardReportsList.length > 2) {
                const nextIdx2 = (idx + 2) % window.guardReportsList.length;
                const rNext2 = window.guardReportsList[nextIdx2];
                r2.setAttribute('data-report-index', nextIdx2);
                r2.onclick = function(e) {
                    if (e) { e.preventDefault(); e.stopPropagation(); }
                    window.selectGuardReportIndex(nextIdx2);
                };
                r2.style.cursor = 'pointer';
                r2.title = 'Click to switch to Report #' + rNext2.report_id;
                
                const offCode2 = rNext2.offense_code || 'MIN-01';

                r2.innerHTML = '<div style="position:absolute; right:8px; top:50%; transform:translateY(-50%); text-align:right; font-weight:800; font-size:11px; color:#92400e; pointer-events:none; white-space:nowrap;">' +
                  '<div style="background:#d97706; color:#ffffff; font-size:10.5px; font-weight:800; padding:5px 12px; border-radius:14px; display:inline-flex; align-items:center; gap:4px; box-shadow:0 3px 10px rgba(217,119,6,0.4); border:1px solid #fbbf24;">' +
                    '<span>🛡️ Report #' + rNext2.report_id + ' ›</span>' +
                  '</div>' +
                  '</div>';
            }
        };

        if (isDifferent && banner) {
            // Phase 1: 3D Deck Card Shuffle Out
            banner.style.transition = 'transform 0.16s cubic-bezier(0.4, 0, 1, 1), opacity 0.16s ease, filter 0.16s ease';
            banner.style.transform = 'translateX(-35px) scale(0.96) rotateY(4deg)';
            banner.style.opacity = '0.4';
            banner.style.filter = 'blur(1px)';

            const l1 = document.getElementById('cardStackLayer1');
            if (l1) {
                l1.style.transition = 'transform 0.16s ease';
                l1.style.transform = 'scale(1.02) translateY(-2px)';
            }

            setTimeout(() => {
                // Phase 2: Update content midway while card is shifted
                applyDataUpdates();

                // Phase 3: 3D Deck Card Snap In
                banner.style.transition = 'transform 0.28s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.28s ease, filter 0.28s ease';
                banner.style.transform = 'translateX(0) scale(1) rotateY(0deg)';
                banner.style.opacity = '1';
                banner.style.filter = 'blur(0px)';

                if (l1) {
                    l1.style.transition = 'transform 0.28s cubic-bezier(0.16, 1, 0.3, 1)';
                    l1.style.transform = 'scale(0.98)';
                }
            }, 160);
        } else {
            applyDataUpdates();
        }
    };

    window.prevGuardReportCarousel = function() {
        if (!window.guardReportsList || window.guardReportsList.length === 0) return;
        const total = window.guardReportsList.length;
        const newIdx = (window.currentReportIndex - 1 + total) % total;
        window.selectGuardReportIndex(newIdx);
    };

    window.nextGuardReportCarousel = function() {
        if (!window.guardReportsList || window.guardReportsList.length === 0) return;
        const total = window.guardReportsList.length;
        const newIdx = (window.currentReportIndex + 1) % total;
        window.selectGuardReportIndex(newIdx);
    };

    // Attach horizontal swipe & wheel gestures for Coverflow deck scrolling
    (function() {
        let isThrottled = false;
        let startX = 0;
        let isDragging = false;

        document.addEventListener('DOMContentLoaded', function() {
            const stack = document.getElementById('pendingGuardReportStackContainer');
            if (!stack) return;

            // Mouse Wheel Horizontal/Vertical Carousel Scroll
            stack.addEventListener('wheel', function(e) {
                if (window.guardReportsList && window.guardReportsList.length > 1) {
                    const delta = Math.abs(e.deltaX) > Math.abs(e.deltaY) ? e.deltaX : e.deltaY;
                    if (Math.abs(delta) < 5) return;
                    e.preventDefault();
                    if (isThrottled) return;
                    isThrottled = true;

                    if (delta > 0) {
                        window.nextGuardReportCarousel();
                    } else {
                        window.prevGuardReportCarousel();
                    }

                    setTimeout(() => { isThrottled = false; }, 350);
                }
            }, { passive: false });

            // Touch Swipe Horizontal Gestures (Left/Right)
            stack.addEventListener('touchstart', function(e) {
                if (e.touches && e.touches.length === 1) {
                    startX = e.touches[0].clientX;
                    isDragging = true;
                }
            }, { passive: true });

            stack.addEventListener('touchmove', function(e) {
                if (!isDragging || !e.touches || e.touches.length !== 1) return;
                const currentX = e.touches[0].clientX;
                const diffX = startX - currentX;

                if (Math.abs(diffX) > 25) {
                    if (window.guardReportsList && window.guardReportsList.length > 1) {
                        isDragging = false;
                        if (diffX > 0) {
                            window.nextGuardReportCarousel();
                        } else {
                            window.prevGuardReportCarousel();
                        }
                    }
                }
            }, { passive: true });

            stack.addEventListener('touchend', function() {
                isDragging = false;
            });

            // Mouse Drag Horizontal Gestures (Desktop Dragging)
            let isMouseDown = false;
            let mouseStartX = 0;
            stack.addEventListener('mousedown', function(e) {
                if (e.target.closest('button, input, select, textarea, a')) return;
                isMouseDown = true;
                mouseStartX = e.clientX;
            });
            stack.addEventListener('mousemove', function(e) {
                if (!isMouseDown) return;
                const diffX = mouseStartX - e.clientX;
                if (Math.abs(diffX) > 30) {
                    if (window.guardReportsList && window.guardReportsList.length > 1) {
                        isMouseDown = false;
                        if (diffX > 0) {
                            window.nextGuardReportCarousel();
                        } else {
                            window.prevGuardReportCarousel();
                        }
                    }
                }
            });
            document.addEventListener('mouseup', function() {
                isMouseDown = false;
            });

            // Populate peeking card layer data on initial load
            if (window.guardReportsList && window.guardReportsList.length > 0) {
                window.selectGuardReportIndex(0);
            }
        });
    })();

    window.autoFillCurrentGuardReport = function() {
        window.selectGuardReportIndex(window.currentReportIndex);
    };

    window.triggerCurrentGuardRejection = function() {
        const r = window.guardReportsList[window.currentReportIndex];
        if (r && r.report_id) {
            window.rejectGuardReport(r.report_id);
        }
    };

    window.rejectGuardReport = function(reportId) {
        if (typeof event !== 'undefined' && event) {
            if (event.preventDefault) event.preventDefault();
            if (event.stopPropagation) event.stopPropagation();
        }
        window.activeRejectReportId = reportId;
        const modal = document.getElementById('rejectGuardReportModal');
        const spanId = document.getElementById('rejectModalReportId');
        if (spanId) spanId.textContent = reportId;
        if (modal) {
            modal.classList.add('active');
            modal.style.cssText = 'display:flex !important; position:fixed !important; top:0 !important; left:0 !important; width:100vw !important; height:100vh !important; background:rgba(15,23,42,0.75) !important; z-index:999999 !important; align-items:center !important; justify-content:center !important;';
        }
        return false;
    };

    window.closeRejectGuardModal = function() {
        const modal = document.getElementById('rejectGuardReportModal');
        if (modal) {
            modal.classList.remove('active');
            modal.style.cssText = 'display:none !important;';
        }
    };

    window.clearGuardReportAutoFill = function() {
        const hiddenInput = document.getElementById('pending_report_id');
        if (hiddenInput) hiddenInput.value = '0';

        const descInput = document.getElementById('description');
        if (descInput) descInput.value = '';

        const typeSelect = document.getElementById('offense_type_id');
        if (typeSelect) typeSelect.selectedIndex = 0;

        const dateInput = document.getElementById('date_committed');
        if (dateInput) {
            const now = new Date();
            const year = now.getFullYear();
            const month = String(now.getMonth() + 1).padStart(2, '0');
            const day = String(now.getDate()).padStart(2, '0');
            const hours = String(now.getHours()).padStart(2, '0');
            const mins = String(now.getMinutes()).padStart(2, '0');
            dateInput.value = `${year}-${month}-${day}T${hours}:${mins}`;
        }

        if (window.history && window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.delete('pending_report_id');
            window.history.replaceState(null, '', url.pathname + url.search);
        }
    };

    window.confirmRejectGuardReport = async function() {
        const reportId = window.activeRejectReportId || 0;
        if (!reportId) return;

        const btn = document.getElementById('confirmRejectBtn');
        if (btn) { btn.disabled = true; btn.textContent = 'Rejecting...'; }

        try {
            const formData = new FormData();
            formData.append('action', 'reject_guard_report');
            formData.append('report_id', reportId);

            const res = await fetch('AJAX/guard_report_review.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();

            closeRejectGuardModal();

            if (data && data.ok) {
                const rejectedId = window.activeRejectReportId;
                window.guardReportsList = (window.guardReportsList || []).filter(r => Number(r.report_id) !== Number(rejectedId));

                // Always clear auto-filled form fields upon rejection
                window.clearGuardReportAutoFill();

                if (window.guardReportsList.length > 0) {
                    if (window.currentReportIndex >= window.guardReportsList.length) {
                        window.currentReportIndex = window.guardReportsList.length - 1;
                    }
                    window.selectGuardReportIndex(window.currentReportIndex);

                    // Update stacked layer cards
                    const layer1 = document.getElementById('cardStackLayer1');
                    const layer2 = document.getElementById('cardStackLayer2');
                    if (layer1) layer1.style.display = window.guardReportsList.length > 1 ? 'block' : 'none';
                    if (layer2) layer2.style.display = window.guardReportsList.length > 2 ? 'block' : 'none';

                    const header = document.getElementById('guardCarouselHeader');
                    if (header) header.style.display = window.guardReportsList.length > 1 ? 'flex' : 'none';
                } else {
                    const stack = document.getElementById('pendingGuardReportStackContainer');
                    if (stack) {
                        stack.style.transition = 'all 0.4s ease';
                        stack.style.opacity = '0';
                        stack.style.transform = 'translateY(-10px)';
                        setTimeout(() => stack.remove(), 400);
                    }
                }

            } else {
                alert('❌ Failed to reject report: ' + (data?.message || 'Error occurred'));
            }
        } catch (e) {
            closeRejectGuardModal();
            alert('❌ Connection error while rejecting report.');
        } finally {
            if (btn) { btn.disabled = false; btn.textContent = 'Reject Report'; }
        }
    };

    document.addEventListener('click', function(e) {
        const btn = e.target.closest('.btn-trigger-nte-upload');
        if (btn) {
            e.preventDefault();
            e.stopPropagation();
            const cid = btn.getAttribute('data-case-id') || 0;
            const sid = btn.getAttribute('data-student-id') || '';
            window.openDirectNteUploadModal(btn, e, cid, sid);
        }
    }, true);
  </script>

  <!-- MODAL: Direct Upload & Send Form F-005 -->
  <div id="directNteUploadModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(15,23,42,0.75); z-index:999999; align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#fff; width:100%; max-width:480px; border-radius:16px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1); position:relative;">
      <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:16px; border-bottom:1px solid #e2e8f0; padding-bottom:12px;">
        <h3 style="margin:0; font-size:18px; font-weight:800; color:#1e293b;">📤 Upload Form F-005 Notice to Explain</h3>
        <button type="button" onclick="closeDirectNteUploadModal()" style="background:none; border:none; font-size:20px; color:#64748b; cursor:pointer;">✕</button>
      </div>
      <form id="directNteUploadForm" onsubmit="submitDirectNteUpload(event)">
        <input type="hidden" name="case_id" id="directNteCaseId" value="0">
        <input type="hidden" name="student_id" id="directNteStudentId" value="">
        
        <div style="margin-bottom:16px;">
          <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">Select Form F-005 Document (PDF or Image)</label>
          <input type="file" name="nte_file" id="directNteFileInput" accept=".pdf,.png,.jpg,.jpeg,.doc,.docx" required style="width:100%; padding:10px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;">
          <div style="font-size:11px; color:#64748b; margin-top:4px;">Supported files: PDF, DOCX, PNG, JPG (Max 10MB)</div>
        </div>

        <div style="margin-bottom:20px;">
          <label style="display:block; font-size:12px; font-weight:700; color:#334155; margin-bottom:6px;">Custom Instructions (Optional)</label>
          <textarea name="custom_instructions" rows="2" placeholder="e.g. Submit written explanation within 5 days to SDO..." style="width:100%; padding:8px 12px; border:1px solid #cbd5e1; border-radius:8px; font-size:13px;"></textarea>
        </div>

        <div id="directNteUploadMsg" style="margin-bottom:12px; font-size:13px; font-weight:600;"></div>

        <div style="display:flex; gap:10px; justify-content:flex-end;">
          <button type="button" class="btn" onclick="closeDirectNteUploadModal()" style="padding:8px 16px; border-radius:8px; font-weight:700;">Cancel</button>
          <button type="submit" id="btnSubmitDirectNte" class="btn btn-primary" style="background:#1b2b6b; border-color:#1b2b6b; padding:8px 20px; border-radius:8px; font-weight:700;">
            📧 Upload & Send to Student Outlook
          </button>
        </div>
      </form>
    </div>
  </div>

  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="wrap">
      <section class="page-hero">
        <div class="hero-left">
          <a class="btn-back" href="offenses.php">
            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path d="M15 18l-6-6 6-6"/>
            </svg>
            Back
          </a>
          <h1 class="page-title">Register New Offense</h1>
        </div>
        <div class="page-sub">Student Discipline Office</div>
      </section>

      <div class="content-area">

        <?php if (isset($_GET['dismissed_success']) && $_GET['dismissed_success'] == '1'): ?>
          <div class="alert-ok" style="background:#f0fdf4; border:1.5px solid #86efac; color:#15803d; padding:14px 18px; border-radius:12px; font-weight:700; margin-bottom:20px; display:flex; align-items:center; gap:10px; box-shadow:0 4px 12px rgba(22,163,74,0.15);">
            <span style="background:#16a34a; color:#ffffff; width:26px; height:26px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:14px; font-weight:800;">✓</span>
            <span>Dismissed offense record has been successfully registered and logged in the database!</span>
          </div>
        <?php endif; ?>

        <?php if ($successMsg): ?>
          <div class="alert-ok">
            ✓ <?php echo htmlspecialchars($successMsg); ?>
          </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
          <div class="alert-err">
            <?php foreach ($errors as $e): ?>
              <div>• <?php echo htmlspecialchars($e); ?></div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <!-- Pending Security Guard Violation Report Tabbed 3D Stacked Deck Banner -->
        <?php 
          $pReportsCount = is_array($pendingGuardReports) ? count($pendingGuardReports) : 0;
          if ($pReportsCount > 0): 
        ?>
          <div id="pendingGuardReportStackWrapper" style="margin-bottom: 28px;">
            <div id="pendingGuardReportStackContainer" style="position: relative; perspective: 1000px;">

              <!-- 3D Card Deck Stack Layers Behind Main Card -->
              <div id="cardStackLayer2" style="position:absolute; top:12px; bottom:-10px; left:16px; right:16px; z-index:1; background:#dcfce7; border:1.5px solid #86efac; border-radius:18px; transform:scale(0.96); opacity:0.75; box-shadow:0 4px 12px rgba(0,0,0,0.05); display:<?php echo $pReportsCount > 2 ? 'block' : 'none'; ?>; transition:all 0.3s ease;"></div>
              <div id="cardStackLayer1" style="position:absolute; top:6px; bottom:-5px; left:8px; right:8px; z-index:2; background:#f0fdf4; border:1.5px solid #a7f3d0; border-radius:18px; transform:scale(0.98); opacity:0.92; box-shadow:0 6px 16px rgba(0,0,0,0.08); display:<?php echo $pReportsCount > 1 ? 'block' : 'none'; ?>; transition:all 0.3s ease;"></div>

              <!-- Active 3D Banner Card -->
              <div id="pendingGuardReportBanner" style="position:relative; z-index:3; background:#f0fdf4; border:1.5px solid #86efac; border-radius:16px; padding:18px 22px; box-shadow: 0 10px 25px rgba(22,163,74,0.15); transition:transform 0.3s cubic-bezier(0.16, 1, 0.3, 1), opacity 0.25s ease, box-shadow 0.3s ease;">
                
                <!-- Tabbed Report Carousel Header -->
                <div id="guardCarouselHeader" style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; padding-bottom:12px; border-bottom:1px dashed #86efac; flex-wrap:wrap; gap:10px;">
                  <div style="font-size:13px; font-weight:800; color:#15803d; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <span style="background:#dcfce7; color:#16a34a; padding:4px 10px; border-radius:10px; font-size:12px;">🛡️ Pending Security Guard Reports (<?php echo $pReportsCount; ?> Total)</span>
                    <span id="carouselReportCounter" style="font-size:11.5px; color:#16a34a; font-weight:700;">Showing 1st Report of <?php echo $pReportsCount; ?></span>
                  </div>

                  <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <?php if ($pReportsCount > 1): ?>
                      <div id="carouselReportPills" style="display:inline-flex; gap:6px; align-items:center; flex-wrap:wrap;">
                        <!-- Clickable report tabs generated by JS -->
                      </div>
                      <div style="display:inline-flex; gap:4px; margin-left:4px;">
                        <button type="button" id="btnPrevReport" onclick="prevGuardReportCarousel()" style="padding:4px 10px; background:#ffffff; border:1px solid #86efac; color:#15803d; font-weight:800; border-radius:8px; cursor:pointer; font-size:12px; transition:all 0.15s;" title="Previous Report">‹</button>
                        <button type="button" id="btnNextReport" onclick="nextGuardReportCarousel()" style="padding:4px 10px; background:#ffffff; border:1px solid #86efac; color:#15803d; font-weight:800; border-radius:8px; cursor:pointer; font-size:12px; transition:all 0.15s;" title="Next Report">›</button>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <div style="display:flex; align-items:flex-start; justify-content:space-between; gap:16px; flex-wrap:wrap;">
                  <div style="display:flex; align-items:flex-start; gap:14px; flex:1; min-width:260px;">
                    <div style="width:44px; height:44px; border-radius:12px; background:#dcfce7; color:#16a34a; display:flex; align-items:center; justify-content:center; font-size:22px; flex-shrink:0; box-shadow:0 2px 6px rgba(22,163,74,0.2);">
                      🛡️
                    </div>
                    <div>
                      <div style="font-size:14px; font-weight:800; color:#15803d; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <span>Pending Security Guard Violation Report</span>
                        <span id="bannerReportBadge" style="background:#16a34a; color:#ffffff; font-size:11px; font-weight:800; padding:2px 10px; border-radius:12px;">1st Report (#<?php echo (int)($pendingGuardReport['report_id'] ?? 0); ?>)</span>
                        <span style="background:#ffffff; color:#15803d; border:1px solid #86efac; font-size:11px; font-weight:700; padding:2px 10px; border-radius:12px; display:inline-flex; align-items:center; gap:4px;">✓ Details Auto-Filled</span>
                      </div>
                      <div id="bannerGuardMeta" style="font-size:12.5px; color:#16a34a; margin-top:3px; font-weight:600;">
                        Filed by <strong><?php echo htmlspecialchars((string)($pendingGuardReport['guard_name'] ?? 'Campus Security Guard')); ?></strong> on <?php echo htmlspecialchars(!empty($pendingGuardReport['created_at']) ? ph_date('M j, Y h:i A', $pendingGuardReport['created_at']) : 'Recently'); ?>
                      </div>
                      <div style="margin-top:8px; font-size:12.5px; color:#1e293b; background:#ffffff; padding:12px 16px; border-radius:10px; border:1px solid #bbf7d0; line-height:1.4; box-shadow:0 2px 4px rgba(0,0,0,0.02);">
                        <div style="font-weight:700; color:#15803d; margin-bottom:2px;">
                          Reported Offense: <span id="bannerOffenseTitle" style="color:#0f172a; font-weight:800;"><?php echo htmlspecialchars((string)($pendingGuardReport['offense_name'] ?? 'Violation Report')); ?> (<?php echo htmlspecialchars((string)($pendingGuardReport['offense_code'] ?? 'MIN-01')); ?>)</span>
                        </div>
                        <div>
                          <span style="font-weight:700; color:#15803d;">Guard Notes:</span>
                          <em id="bannerGuardNotes" style="color:#334155;">"<?php echo htmlspecialchars((string)($pendingGuardReport['description'] ?? 'No additional notes provided.')); ?>"</em>
                        </div>
                      </div>
                    </div>
                  </div>
                  <div style="align-self:center; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                    <button type="button" id="btnAutoFillGuard" onclick="autoFillCurrentGuardReport()" style="padding:9px 16px; background:#16a34a; color:#ffffff; font-weight:700; font-size:12.5px; border-radius:8px; border:none; cursor:pointer; display:flex; align-items:center; gap:6px; white-space:nowrap; box-shadow:0 2px 8px rgba(22,163,74,0.3);">
                      ✓ Details Auto-Filled
                    </button>
                    <button type="button" id="btnRejectGuard" onclick="triggerCurrentGuardRejection()" style="padding:9px 14px; background:#fef2f2; color:#dc2626; border:1px solid #fca5a5; font-weight:700; font-size:12.5px; border-radius:8px; cursor:pointer; display:flex; align-items:center; gap:6px; white-space:nowrap; transition:all 0.2s;" onmouseover="this.style.background='#fee2e2'" onmouseout="this.style.background='#fef2f2'">
                      ✕ Reject Report
                    </button>
                  </div>
                </div>
              </div>

            </div>
          </div>
        <?php endif; ?>

        <div class="content-grid">

          <!-- LEFT: Form -->
          <div>
            <div class="card">
              <div class="card-header">
                <div>
                  <div class="card-title">Offense Details</div>
                  <div class="card-sub">Fill in all required fields marked with *</div>
                </div>
              </div>
              <div class="card-body">

                <!-- Student info summary banner -->
                <?php if ($postStudentId !== '' && $studentInfo): ?>
                  <div style="background: #eff6ff; border: 1px solid #bfdbfe; padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px;">
                    <div>
                      <strong style="font-size: 14px; color: #1e3a8a;"><?php echo htmlspecialchars($studentInfo['student_fn'] . ' ' . $studentInfo['student_ln']); ?></strong>
                      <span style="margin-left: 10px; font-size: 13px; color: #2563eb; font-weight: 700;"><?php echo htmlspecialchars($studentInfo['student_id']); ?></span>
                      <span style="margin-left: 10px; font-size: 12px; color: #475569;"><?php echo htmlspecialchars(($studentInfo['year_level'] ?? '') . ' ' . ($studentInfo['section'] ?? '') . ' (' . ($studentInfo['program'] ?? '') . ')'); ?></span>
                    </div>
                    <div style="font-size: 12px; font-weight: 700; color: #1d4ed8; background: #dbeafe; padding: 4px 10px; border-radius: 20px;">
                      Selected Student
                    </div>
                  </div>
                <?php else: ?>
                  <div style="background: var(--surface-2); border: 1px dashed var(--border); padding: 16px; border-radius: var(--radius-sm); margin-bottom: 18px; text-align: center; color: var(--text-4);">
                    Enter a Student ID above to load student information.
                  </div>
                <?php endif; ?>

                  <div class="alert-panel alert-panel--dismissed-glow" id="dismissedAlertBanner" style="<?php echo ($level === 'DISMISSED') ? '' : 'display:none;'; ?>">
                    <div class="ap-icon">
                      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <div class="ap-body">
                      <div class="ap-title" style="color: #9f1239; font-weight: 800; font-size: 15px;">📋 DISMISSED OFFENSE RECORD (RECORD-KEEPING ONLY)</div>
                      <div class="ap-desc" style="color: #881337; margin-bottom: 0; font-weight: 500;">
                        This record is for administrative tracking purposes only. It will <strong>not</strong> count towards Minor/Major sanction escalations or trigger Section 4 UPCC cases.
                      </div>
                    </div>
                  </div>

                <form method="post" action="offense_new.php" id="offenseForm" enctype="multipart/form-data">
                  <input type="hidden" name="pending_report_id" id="pending_report_id" value="<?php echo (int)($pendingReportId ?? 0); ?>"/>
                  <input type="hidden" name="dismissal_reason" id="dismissal_reason_hidden" value=""/>
                  <input type="hidden" name="dismissal_approval_confirmed" id="dismissal_approval_confirmed" value="0"/>
                  <input type="hidden" name="evidence_file_confirmed" id="evidence_file_confirmed" value="0"/>
                  <input type="file" name="evidence_file" id="evidence_file_input" style="display:none;" accept="image/*,.pdf"/>

                  <div class="form-row">
                    <div class="form-group">
                      <label for="level">Offense Level *</label>
                      <select id="levelSelect" name="level" onchange="onLevelChange(this.value)">
                        <option value="MINOR" <?php echo $level === 'MINOR' ? 'selected' : ''; ?>>Minor</option>
                        <option value="MAJOR" <?php echo $level === 'MAJOR' ? 'selected' : ''; ?>>Major</option>
                        <option value="DISMISSED" <?php echo $level === 'DISMISSED' ? 'selected' : ''; ?>>Dismissed (Record Only)</option>
                      </select>
                    </div>
                    <div class="form-group">
                      <label for="student_id">Student ID *</label>
                      <div style="position: relative; width: 100%;">
                        <input id="studentIdInput" name="student_id"
                               value="<?php echo htmlspecialchars($postStudentId); ?>"
                               placeholder="e.g., 2024-01001"
                               autocomplete="off" style="width: 100%;"/>
                        <div id="studentSuggestions" class="student-suggestions-dropdown"></div>
                      </div>
                    </div>
                  </div>

                  <div class="form-row full" id="categoryGroup" style="<?php echo ($level === 'MAJOR') ? '' : 'display:none;'; ?> margin-top: 14px;">
                    <div class="form-group">
                      <label for="major_category" style="font-weight: 800; color: #dc2626;">Major Category (1–5) *</label>
                      <select id="major_category" name="major_category" onchange="onCategoryChange(this.value)" style="border: 2px solid #dc2626; background: #fff5f5; font-weight: 700; font-size: 14px; padding: 10px; border-radius: 8px; width: 100%;">
                        <option value="">— Select Category (1–5) —</option>
                        <option value="1" <?php echo $category === 1 ? 'selected' : ''; ?>>Category 1 — Probation</option>
                        <option value="2" <?php echo $category === 2 ? 'selected' : ''; ?>>Category 2 — Formative Intervention</option>
                        <option value="3" <?php echo $category === 3 ? 'selected' : ''; ?>>Category 3 — Non-Readmission</option>
                        <option value="4" <?php echo $category === 4 ? 'selected' : ''; ?>>Category 4 — Exclusion</option>
                        <option value="5" <?php echo $category === 5 ? 'selected' : ''; ?>>Category 5 — Expulsion</option>
                      </select>
                      <?php if ($category >= 1 && $category <= 5): ?>
                        <div class="category-desc" style="margin-top: 8px; padding: 10px 14px; background: #fef2f2; border: 1px solid #fca5a5; border-radius: 8px; color: #991b1b; font-size: 12.5px; line-height: 1.5;">
                          <strong>Category <?php echo $category; ?> Description:</strong> <?php echo htmlspecialchars($categoryDescriptions[$category]); ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>

                <div class="form-row" id="row2">
                  <div class="form-group">
                    <label for="date_committed">Date &amp; Time of Incident *</label>
                    <input id="date_committed" name="date_committed" type="datetime-local"
                           value="<?php echo htmlspecialchars($postDate); ?>"/>
                  </div>
                </div>

                <div class="form-row full">
                  <div class="form-group">
                    <label for="offense_type_id">Offense Type *</label>
                    <select id="offense_type_id" name="offense_type_id" onchange="
                      if(this.value == '22' || this.value == '23' || this.value == '24') { openAddModal(); this.value=''; }
                      const btnE = document.getElementById('btnEditType');
                      const btnD = document.getElementById('btnDeleteType');
                      const lbl = document.getElementById('typeActionLabel');
                      if(this.value && this.value != '22' && this.value != '23' && this.value != '24') {
                          btnE.style.display = 'inline-flex';
                          btnD.style.display = 'inline-flex';
                          lbl.innerText = 'Manage selected offense type';
                      } else {
                          btnE.style.display = 'none';
                          btnD.style.display = 'none';
                          updateDescRequirement();
                    ">
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
                      <button type="button" class="btn btn-circle" id="btnEditType" onclick="editSelectedType()" title="Edit selected offense type" style="display:none; color: var(--primary);">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                      </button>
                      <button type="button" class="btn btn-circle" id="btnDeleteType" onclick="deleteSelectedType()" title="Delete selected offense type" style="display:none; color: var(--red);">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
                      </button>
                      <span style="font-size: 12px; color: var(--text-3);" id="typeActionLabel">Add new offense type</span>
                    </div>
                  </div>
                </div>

                <?php
                  $isDescRequired = ($level === 'DISMISSED' || in_array($postExistingTypeId, [22, 23, 24], true));
                ?>
                <div class="form-row full">
                  <div class="form-group">
                    <label for="description" id="descLabel">Description / Notes <span id="descOptional" style="<?php echo $isDescRequired ? 'color:var(--red); font-weight:800;' : ''; ?>"><?php echo $isDescRequired ? '*' : '(optional)'; ?></span></label>
                    <textarea id="description" name="description" <?php echo $isDescRequired ? 'required' : ''; ?>
                              placeholder="Describe the incident in detail..."><?php echo htmlspecialchars($postDesc); ?></textarea>
                  </div>
                </div>

                <style>@keyframes spin { 100% { transform: rotate(360deg); } }</style>
                <div class="form-actions">
                  <input type="hidden" name="_action_hint" value="save">
                  <button type="submit" class="btn btn-primary" onclick="if(!this.form.checkValidity())return true; const btn=this; setTimeout(() => { btn.disabled=true; btn.innerHTML='<svg viewBox=\'0 0 24 24\' fill=\'none\' stroke=\'currentColor\' stroke-width=\'2\' style=\'animation: spin 1s linear infinite; width:18px;height:18px;margin-right:6px;\'><path d=\'M21 12a9 9 0 1 1-6.219-8.56\'/></svg> Registering...'; }, 10); return true;">
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
            <?php echo renderStudentInfoCard($studentInfo, $liveGuardianEmail, $liveMinorCount, $liveMajorCount, $liveActiveUpccCases, $liveOffenses); ?>
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
              <button class="modal-close" onclick="document.getElementById('modal-guardian-letter').classList.remove('active')">&times;</button>
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
                    <input id="letter_subject" type="text" value="Student Conduct Notice — Offense Report" oninput="debouncePreview()"/>
                  </div>
                  <div class="form-group" style="margin-bottom:14px;">
                    <label for="letter_body">Message</label>
                    <?php
                      if ($letterType === 'major') {
                          $defaultBody = "Dear Guardian,\n\nThis is an urgent official notification from the Student Discipline Office. We are writing to formally inform you that your student has been involved in a MAJOR disciplinary offense.\n\nDue to the severity of this infraction, an immediate investigation is currently underway by the University Panel on Community Conduct (UPCC). Such offenses carry significant consequences, which may include suspension or expulsion. We strongly advise that you discuss this matter with your student immediately.\n\n";
                      } elseif ($letterType === 'escalation') {
                          $defaultBody = "Dear Guardian,\n\nThis is an official notice to inform you that your student has accumulated their 3rd minor offense, which triggers an automatic escalation to a Major Offense status under our discipline policy.\n\nThe student's case has now been forwarded to the University Panel on Community Conduct (UPCC), and a formal investigation is underway. We ask for your immediate cooperation as we review these repeated infractions.\n\nPlease see the detailed notice below for the complete offense history.\n\n";
                      } elseif ($letterType === 'letter') {
                          $defaultBody = "Dear Guardian,\n\nThis is to inform you that your student has been reported for a second minor conduct offense. Please see the detailed notice below for more information regarding this incident.\n\n";
                      } else {
                          $defaultBody = "Dear Guardian,\n\nThis is to inform you that your student has been reported for a conduct offense. Please see the detailed notice below for more information.\n\n";
                      }
                      if ($studentInfo && $letterOffenseId > 0) {
                          $coff = db_one("SELECT o.description, o.date_committed, ot.code, ot.name, ot.level FROM offense o JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id WHERE o.offense_id = :oid", [':oid' => $letterOffenseId]);
                          if ($coff) {
                              $dt = ph_date('F j, Y g:i A', $coff['date_committed']);
                              $defaultBody .= "CURRENT OFFENSE:\n- {$coff['code']} — {$coff['name']}\n- Level: {$coff['level']}\n- Date: {$dt}\n- Notes: " . ($coff['description'] ?: '(none)') . "\n\n";
                          }
                          $history = db_all(
                              "SELECT o.date_committed, " . db_decrypt_col('description', 'o') . " AS description, o.level AS o_level, o.status AS o_status, ot.level AS ot_level, ot.code, ot.name
                               FROM offense o
                               JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
                               WHERE o.student_id = :sid AND o.offense_id <> :current_oid AND o.level <> 'DISMISSED' AND o.status <> 'DISMISSED' AND ot.level <> 'DISMISSED' AND ot.code NOT LIKE 'DISM%'
                               ORDER BY o.date_committed DESC, o.offense_id DESC LIMIT 30",
                              [':sid' => $studentInfo['student_id'], ':current_oid' => $letterOffenseId]
                          );
                          $defaultBody .= "PRIOR OFFENSE HISTORY (Most recent first):\n";
                          if (empty($history)) {
                              $defaultBody .= "(No prior sanction offenses found.)\n";
                          } else {
                              foreach ($history as $i => $h) {
                                  $dt = ph_date('M j, Y g:i A', $h['date_committed']);
                                  $lvl = (strtoupper((string)($h['o_level'] ?? '')) === 'MAJOR' || strtoupper((string)($h['ot_level'] ?? '')) === 'MAJOR') ? 'MAJOR' : 'MINOR';
                                  $defaultBody .= ($i + 1) . ". [{$lvl}] {$h['code']} — {$h['name']} ({$dt})\n";
                                  $hDesc = trim((string)($h['description'] ?? ''));
                                  if ($hDesc !== '') $defaultBody .= "   Notes: " . $hDesc . "\n";
                              }
                          }
                      }
                      $defaultBody .= "\n\nWe encourage you to support your student in maintaining proper conduct within our institution.\n\nSincerely,\nStudent Discipline Office";
                    ?>
                    <?php
                      // Convert the \n newlines to <br> for the Quill editor initial content
                      $defaultBodyHtml = nl2br(htmlspecialchars($defaultBody));
                    ?>
                    <div id="letter_body_editor" style="height: 320px; background: #fff; border-radius: 0 0 6px 6px; overflow: hidden; display: flex; flex-direction: column; border: 1px solid var(--border);"><?php echo $defaultBodyHtml; ?></div>
                    <textarea id="letter_body" style="display:none;"></textarea>
                  </div>
                  <div class="form-actions" style="border:none; padding:0; margin:16px 0 0 0; display:flex; gap:10px; position:relative; z-index:10;">
                    <button type="button" class="btn" id="btn_send_letter" style="background:#15803d; color:#fff; border-color:#15803d; padding: 10px 22px; font-weight: 700; border-radius: 8px; font-size: 13.5px;" onclick="sendLetter()">
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
        </div>
        <?php endif; ?>

      </div>
    </main>
  </div>

  <?php echo renderStudentRecordModal($studentInfo, $liveGuardianEmail, $liveMinorCount, $liveMajorCount, $liveActiveUpccCases, $hasActiveSection4, $postSection4Minors, $liveOffenses); ?>

  <!-- MODAL: Add Offense Type -->
  <div id="offenseTypeModal" class="modal">
    <div class="modal-content">
      <div class="modal-header">
        <h3 id="typeModalTitle">Add Offense Type</h3>
        <button class="modal-close" onclick="closeModal()">&times;</button>
      </div>
      <div class="modal-body">
        <input type="hidden" id="edit_offense_type_id" value="">
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
            <option value="DISMISSED">Dismissed</option>
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

  <!-- MODAL: Confirm Delete Type -->
  <div id="confirmDeleteTypeModal" class="modal">
    <div class="modal-content" style="max-width: 400px; text-align: center; border-radius: 12px; padding: 20px;">
      <div class="modal-body" style="padding: 20px 10px;">
        <div style="color: var(--red); margin-bottom: 16px;">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width: 48px; height: 48px;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
        </div>
        <h3 style="margin-top: 0; margin-bottom: 12px; color: #1e293b; font-size: 20px;">Delete Offense Type?</h3>
        <p style="color: var(--text-2); font-size: 14px; line-height: 1.5; margin-bottom: 24px;">
          Are you sure you want to delete this offense type?<br><br>
          <span style="font-size: 12px; opacity: 0.8;">Note: If this offense type is already in use by students, it will be safely kept for historical records but hidden from future selections.</span>
        </p>
        <div style="display: flex; gap: 10px; justify-content: center;">
          <button class="btn" onclick="document.getElementById('confirmDeleteTypeModal').classList.remove('active')" style="flex: 1;">Cancel</button>
          <button class="btn btn-primary" style="flex: 1; background-color: var(--red); border-color: var(--red);" onclick="executeDeleteType()">Yes, Delete</button>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL: Action Success -->
  <div id="typeActionSuccessModal" class="modal">
    <div class="modal-content" style="max-width: 400px; text-align: center; border-radius: 12px; padding: 20px;">
      <div class="modal-body" style="padding: 20px 10px;">
        <div style="color: #10b981; margin-bottom: 16px;">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width: 48px; height: 48px;"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
        </div>
        <h3 style="margin-top: 0; margin-bottom: 12px; color: #1e293b; font-size: 20px;" id="typeActionSuccessTitle">Success</h3>
        <p style="color: var(--text-2); font-size: 14px; line-height: 1.5; margin-bottom: 24px;" id="typeActionSuccessMsg"></p>
        <button class="btn btn-primary" onclick="document.getElementById('typeActionSuccessModal').classList.remove('active')" style="width: 100%;">Continue</button>
      </div>
    </div>
  </div>

  <!-- MODAL: Success after register -->
  <div id="successModal" class="modal">
    <div class="modal-content" style="text-align: center; max-width: 400px; border-radius: 12px; overflow: hidden; position: relative; <?php echo ($letterType === 'escalation' || $letterType === 'major') ? 'border: 2px solid var(--red); box-shadow: 0 10px 25px rgba(220, 38, 38, 0.2);' : ''; ?>">
      <div class="modal-body" style="padding: 40px 30px;">
        <button class="modal-close" onclick="closeSuccessModal()" style="position: absolute; top: 15px; right: 15px;">&times;</button>
        <img src="../assets/logo.png" alt="IdentiTrack Logo" style="height: 64px; margin-bottom: 24px;">
        
        <?php if ($letterType === 'escalation'): ?>
          <h3 style="margin: 0 0 12px 0; font-size: 20px; color: var(--red);">🚨 Section 4 Triggered</h3>
          <p style="font-size:14px;color:var(--text-2);line-height:1.6; margin: 0 0 24px 0;">
            <strong style="color:var(--red);">3rd Minor Offense!</strong> The student has reached Section 4. A UPCC case has been automatically created. Please send the guardian notification below.
          </p>
        <?php elseif ($letterType === 'major'): ?>
          <h3 style="margin: 0 0 12px 0; font-size: 20px; color: var(--red);">🚨 Major Offense</h3>
          <p style="font-size:14px;color:var(--text-2);line-height:1.6; margin: 0 0 24px 0;">
            This major offense requires immediate UPCC panel review. A UPCC case has been automatically created. Please send the guardian notification below.
          </p>
        <?php else: ?>
          <h3 style="margin: 0 0 12px 0; font-size: 20px; color: #1e293b;">Offense Registered</h3>
          <?php if ($letterMode): ?>
            <p style="font-size:14px;color:var(--text-2);line-height:1.6; margin: 0 0 24px 0;">
              The offense record has been saved. You may now review and send the guardian notification letter below.
            </p>
          <?php else: ?>
            <p style="font-size:14px;color:var(--text-2);line-height:1.6; margin: 0 0 24px 0;">
              The offense record has been saved successfully.
            </p>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($letterMode): ?>
          <button class="btn btn-primary" id="successCloseBtn" type="button" onclick="closeSuccessModal()" style="width: 100%; justify-content: center; padding: 12px; <?php echo ($letterType === 'escalation' || $letterType === 'major') ? 'background: var(--red); border-color: var(--red); box-shadow: 0 4px 10px rgba(220, 38, 38, 0.3);' : ''; ?>">Compose Guardian Email</button>
        <?php else: ?>
          <div style="display:flex; gap: 10px;">
            <button class="btn" type="button" onclick="dismissSuccessModal()" style="flex: 1; justify-content: center; padding: 12px;">Stay on page</button>
            <a href="offenses.php" class="btn btn-primary" id="successCloseBtn" style="flex: 1; justify-content: center; padding: 12px;">Go to Offenses</a>
          </div>
        <?php endif; ?>
        <div id="successModalProgress" style="position: absolute; bottom: 0; left: 0; height: 4px; background-color: <?php echo ($letterType === 'escalation' || $letterType === 'major') ? 'var(--red)' : 'var(--blue, #2563eb)'; ?>; width: 100%;"></div>
      </div>
    </div>
  </div>

  <!-- MODAL: Final Process Success Modal (after Guardian Email AND Form F-005 sent) -->
  <div id="finalProcessSuccessModal" class="modal">
    <div class="modal-content" style="text-align: center; max-width: 420px; border-radius: 16px; overflow: hidden; position: relative;">
      <div class="modal-body" style="padding: 40px 30px;">
        <button class="modal-close" onclick="dismissFinalSuccessModal()" style="position: absolute; top: 15px; right: 15px;">&times;</button>
        <img src="../assets/logo.png" alt="IdentiTrack Logo" style="height: 64px; margin-bottom: 24px;">
        <h3 style="margin: 0 0 12px 0; font-size: 20px; color: #10b981;">Process Completed</h3>
        <p id="finalSuccessMsgText" style="font-size:14px;color:var(--text-2);line-height:1.6; margin: 0 0 24px 0;">
          ✅ Guardian notification email sent.<br>
          <span id="finalNteStatusText">✅ Form F-005 Notice to Explain issued to student app.</span>
        </p>
        <div style="display:flex; justify-content:center;">
            <button class="btn btn-primary" type="button" onclick="closeFinalSuccessModal()" style="width:100%; padding:12px; font-weight:800; background:#10b981; border:none; justify-content:center;">✓ Done (Stay on Page)</button>
        </div>
        <div id="finalSuccessProgress" style="position: absolute; bottom: 0; left: 0; height: 4px; background-color: #10b981; width: 100%;"></div>
      </div>
    </div>
  </div>

  <!-- MODAL 2: Form F-005 Notice To Explain File Attachment Modal for Student -->
  <div id="modal-nte-editor" class="modal" data-static="true">
    <div class="modal-content" style="max-width: 520px; border-radius: 16px; overflow: hidden; position: relative; border: 2px solid var(--navy, #1b2b6b);">
      <div class="modal-header" style="background: var(--navy, #1b2b6b); color: white; padding: 16px 20px; display: flex; align-items: center; justify-content: space-between;">
        <div style="display: flex; align-items: center; gap: 10px;">
          <h3 style="margin:0; font-size: 18px; font-weight: 800; color: white;">📄 Form F-005: Notice To Explain</h3>
        </div>
        <button class="modal-close" onclick="promptSkipNteFile()" style="color: white; font-size: 22px; background: none; border: none; cursor: pointer; line-height: 1;" title="Close">&times;</button>
      </div>
      <div class="modal-body" style="padding: 24px;">
        <div style="background: #eff6ff; border: 1px solid #bfdbfe; padding: 12px 16px; border-radius: 10px; margin-bottom: 20px; font-size: 13px; color: #1e40af; font-weight: 600;">
          ℹ️ Attach the official Form F-005 document file below if you wish to send it to the student.
        </div>

        <div style="margin-bottom: 24px; background: #f8fafc; padding: 16px; border-radius: 12px; border: 1px dashed var(--navy, #1b2b6b);">
          <label style="font-size: 12px; font-weight: 800; text-transform: uppercase; color: var(--navy, #1b2b6b); display: block; margin-bottom: 8px;">
            Attach Official Form F-005 Notice File / PDF
          </label>
          <input type="file" id="nte_file_attachment" accept=".pdf,.doc,.docx,.png,.jpg,.jpeg" class="form-control" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #ccc; background: white; font-size: 13px;">
          <small style="color:var(--text-3); font-size:11px; margin-top: 6px; display: block;">Select the official signed Form F-005 file (PDF, Doc, or Image) for the student.</small>
        </div>

        <div style="display: flex; gap: 10px; align-items: center;">
          <button class="btn" type="button" onclick="promptSkipNteFile()" style="flex: 1; justify-content: center; font-weight: 700; color: var(--text-2);">Do Not Send File</button>
          <button class="btn btn-primary" id="btn_send_nte" onclick="sendNteFormToStudent()" style="flex: 2; background: var(--navy, #1b2b6b); border-color: var(--navy, #1b2b6b); padding: 12px; font-weight: 800; font-size: 14px;">📄 Upload &amp; Send File</button>
        </div>
      </div>
    </div>
  </div>

  <!-- MODAL 3: Dedicated Evidence Photo Choice (YES / NO) -->
  <div class="modal" id="modal-evidence-photo-choice" style="z-index: 2600;">
    <div class="modal-content" style="max-width: 520px; width: 92%; padding: 24px; border-radius: 16px; border: 1.5px solid #2563eb;">
      <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:12px; margin-bottom:16px;">
        <h3 style="font-size:18px; font-weight:800; color:#1e293b; display:flex; align-items:center; gap:8px; margin:0;">
          <span>📷</span> Step 3: Photo Evidence in Student Hearing
        </h3>
        <button class="modal-close" onclick="closeEvidencePhotoChoiceModal()">&times;</button>
      </div>
      <div class="modal-body" style="display:flex; flex-direction:column; gap:14px; padding:0;">
        <div style="font-size:13.5px; color:#334155; line-height:1.5;">
          Would you like to include the incident photo evidence in the student's UPCC Hearing case file?
        </div>
        
        <!-- Option YES -->
        <label id="boxHearingYes" onclick="selectModalHearingChoice(1)" style="display:flex; align-items:flex-start; gap:12px; background:#eff6ff; border:2px solid #2563eb; padding:14px; border-radius:12px; cursor:pointer; transition:all 0.2s;">
          <input type="radio" name="modal_hearing_choice" value="1" checked style="margin-top:3px; width:18px; height:18px; accent-color:#2563eb;" />
          <div>
            <div style="font-weight:800; font-size:14px; color:#1e40af;">📷 YES — Include Photo Evidence in Student Hearing</div>
            <div style="font-size:12px; color:#3b82f6; margin-top:2px;">Panel board members will be able to inspect this evidence photo during the UPCC hearing.</div>
          </div>
        </label>

        <!-- Option NO -->
        <label id="boxHearingNo" onclick="selectModalHearingChoice(0)" style="display:flex; align-items:flex-start; gap:12px; background:#f8fafc; border:2px solid #cbd5e1; padding:14px; border-radius:12px; cursor:pointer; transition:all 0.2s;">
          <input type="radio" name="modal_hearing_choice" value="0" style="margin-top:3px; width:18px; height:18px; accent-color:#dc2626;" />
          <div>
            <div style="font-weight:800; font-size:14px; color:#475569;">🔒 NO — Keep Photo Evidence Private</div>
            <div style="font-size:12px; color:#64748b; margin-top:2px;">Photo will remain private for SDO administrative record-keeping only.</div>
          </div>
        </label>
      </div>
      <div class="modal-footer" style="margin-top:20px; border-top:1px solid #e2e8f0; padding-top:14px; display:flex; justify-content:flex-end; gap:10px;">
        <button type="button" class="btn" id="btnSaveHearingChoice" onclick="saveEvidencePhotoChoiceAndFinish()" style="background:#2563eb; color:#ffffff; font-weight:800; padding:11px 22px; border-radius:10px; border:none; font-size:13.5px; cursor:pointer; box-shadow:0 4px 12px rgba(37,99,235,0.3);">
          ✓ Save Choice & Finish Registration
        </button>
      </div>
    </div>
  </div>

  <!-- MODAL: Confirm Skip Form F-005 File -->
  <div id="confirmSkipNteModal" class="modal">
    <div class="modal-content" style="max-width: 420px; text-align: center; border-radius: 16px; overflow: hidden; padding: 24px;">
      <div style="color: #f59e0b; margin-bottom: 16px;">
        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width: 48px; height: 48px; margin: 0 auto;"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      </div>
      <h3 style="margin: 0 0 12px 0; font-size: 20px; color: #1e293b;">Skip Form F-005 File?</h3>
      <p style="color: var(--text-2); font-size: 14px; line-height: 1.5; margin-bottom: 24px;">
        Are you sure you don't want to send the official Form F-005 document file to the student?
      </p>
      <div style="display: flex; gap: 10px; justify-content: center;">
        <button class="btn" onclick="closeConfirmSkipNteModal()" style="flex: 1; justify-content: center; font-weight: 700;">No, Back to Upload</button>
        <button class="btn btn-primary" onclick="confirmSkipNteFile()" style="flex: 1; justify-content: center; font-weight: 700; background: var(--navy, #1b2b6b); border-color: var(--navy, #1b2b6b);">Yes, Skip File</button>
      </div>
    </div>
  </div>
  <!-- END confirmSkipNteModal --> <!-- added missing closing div -->

  <!-- MODAL: Email Sending Loading Modal -->
  <div id="emailSendingModal" class="modal" data-static="true">
    <div class="modal-content" style="max-width: 360px; text-align: center; border-radius: 16px; padding: 32px 24px;">
      <div style="margin-bottom: 16px; color: var(--navy, #1b2b6b);">
        <svg class="spin-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" style="width: 48px; height: 48px; margin: 0 auto; animation: spin 1s linear infinite;"><path d="M12 2v4m0 12v4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83M2 12h4m12 0h4M4.93 19.07l2.83-2.83m8.48-8.48l2.83-2.83"/></svg>
      </div>
      <h4 style="margin: 0 0 8px 0; font-size: 18px; color: #1e293b; font-weight: 800;">Sending Guardian Email...</h4>
      <p style="margin: 0; color: var(--text-2); font-size: 13px;">Please wait while the official warning notice is dispatched.</p>
    </div>
  </div>

  <script>
  const OFFENSE_ID  = <?php echo (int)$letterOffenseId; ?>;
  const LETTER_MODE = <?php echo json_encode($letterMode && $letterOffenseId > 0); ?>;
  const LETTER_TYPE = <?php echo json_encode($letterType); ?>;
  const SUCCESS_MODE = <?php echo json_encode($successMode); ?>;
  const INIT_LEVEL  = <?php echo json_encode($level); ?>;
  const SHOW_STUDENT_RECORD_MODAL = <?php echo json_encode($studentInfo && ($liveMinorCount + $liveMajorCount > 0 || count($liveActiveUpccCases) > 0)); ?>;
  let currentLevel    = INIT_LEVEL;
  let currentCategory = <?php echo $category; ?>;
  window.__projectedMinorCount = <?php echo (int)($liveMinorCount + 1); ?>;

  function escHtml(str) {
    return String(str)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;')
      .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  function renderMinorAlert(projectedCount, guardianEmail, currentCount, hasActiveSection4, postSection4Minors) {

    if (hasActiveSection4) {
      const postProjected = postSection4Minors + 1;
      const postPct       = (Math.min(postProjected, 3) / 3) * 100;
      return `
      <div class="alert-panel alert-panel--critical">
        <div class="ap-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/></svg></div>
        <div class="ap-body">
          <div class="ap-title">⚖️ Section 4 Active – Additional Minor</div>
          <div class="ap-projected-badge ap-projected--critical">📋 Currently ${currentCount} Minors → <strong>Section 4 Active</strong></div>
          <div class="ap-progress"><div class="ap-progress-track"><div class="ap-progress-fill ap-progress--critical" style="width:${postPct}%"></div></div><span class="ap-progress-label">Section 4 Investigation Ongoing (${postProjected}/3 post-trigger)</span></div>
          <div class="ap-desc">Student is under Section 4 UPCC investigation. This additional minor will be appended to their case file.</div>
        </div>
      </div>`;
    }

    if (projectedCount === 1) {
      return `
      <div class="alert-panel alert-panel--info">
        <div class="ap-icon"><svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg></div>
        <div class="ap-body">
          <div class="ap-title">1st Minor – Warning</div>
          <div class="ap-projected-badge ap-projected--info">📋 Currently ${currentCount} → becomes <strong>1/3</strong></div>
          <div class="ap-progress"><div class="ap-progress-track"><div class="ap-progress-fill ap-progress--info" style="width:${(1/3)*100}%"></div></div><span class="ap-progress-label">1/3 – 2 more to Section 4</span></div>
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
          <div class="ap-progress"><div class="ap-progress-track"><div class="ap-progress-fill ap-progress--warning" style="width:${(2/3)*100}%"></div></div><span class="ap-progress-label">2/3 – 1 more to Section 4</span></div>
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

  function promptSkipNteFile() {
    const confirmModal = document.getElementById('confirmSkipNteModal');
    if (confirmModal) confirmModal.classList.add('active');
  }

  function closeConfirmSkipNteModal() {
    const confirmModal = document.getElementById('confirmSkipNteModal');
    if (confirmModal) confirmModal.classList.remove('active');
  }

  window.__pendingNteSentStatus = false;

  function confirmSkipNteFile() {
    closeConfirmSkipNteModal();
    const nteModal = document.getElementById('modal-nte-editor');
    if (nteModal) nteModal.classList.remove('active');
    window.__pendingNteSentStatus = false;
    openEvidencePhotoChoiceModal(OFFENSE_ID);
  }

  function openNteEditorModal() {
    const letterModal = document.getElementById('modal-guardian-letter');
    if (letterModal) letterModal.classList.remove('active');
    
    const nteModal = document.getElementById('modal-nte-editor');
    if (nteModal) nteModal.classList.add('active');
  }

  async function sendNteFormToStudent() {
    const studentId = '<?= htmlspecialchars($postStudentId) ?>';
    const offenseId = OFFENSE_ID;

    const fileInput = document.getElementById('nte_file_attachment');
    const file = fileInput?.files ? fileInput.files[0] : null;

    // STRICT FILE ATTACHMENT VALIDATION: CANNOT SUBMIT IF FILE IS EMPTY!
    if (!file) {
        alert('⚠️ CANNOT SUBMIT: Form F-005 file attachment is required! Please select/attach the Form F-005 document file before sending to student.');
        fileInput?.focus();
        return; // PREVENTS SUBMISSION & KEEPS MODAL OPEN!
    }

    if (!confirm('Are you sure you want to send this Form F-005 file to the student?')) {
        return;
    }

    const btn = document.getElementById('btn_send_nte');
    if (btn) { btn.disabled = true; btn.textContent = 'Uploading & Sending Form F-005...'; }

    const formData = new FormData();
    formData.append('student_id', studentId);
    formData.append('offense_id', offenseId);
    formData.append('nte_file', file);

    const res = await postForm('api_send_nte_form.php', formData);
    if (res.ok && res.json?.ok) {
        const nteModal = document.getElementById('modal-nte-editor');
        if (nteModal) nteModal.classList.remove('active');
        window.__pendingNteSentStatus = true;
        openEvidencePhotoChoiceModal(OFFENSE_ID);
    } else {
        alert('❌ Error: ' + (res.json?.error || 'Failed to send Form F-005.'));
        if (btn) { btn.disabled = false; btn.textContent = '📄 Upload & Send Form F-005 to Student'; }
    }
  }

  window.openEvidencePhotoChoiceModal = function(offenseId) {
    const nteModal = document.getElementById('modal-nte-editor');
    if (nteModal) nteModal.classList.remove('active');
    
    const choiceModal = document.getElementById('modal-evidence-photo-choice');
    if (choiceModal) choiceModal.classList.add('active');
  };

  window.closeEvidencePhotoChoiceModal = function() {
    const choiceModal = document.getElementById('modal-evidence-photo-choice');
    if (choiceModal) choiceModal.classList.remove('active');
    showFinalSuccessModal(window.__pendingNteSentStatus);
  };

  window.selectModalHearingChoice = function(val) {
    const radios = document.getElementsByName('modal_hearing_choice');
    radios.forEach(r => r.checked = (parseInt(r.value) === val));
    
    const boxYes = document.getElementById('boxHearingYes');
    const boxNo  = document.getElementById('boxHearingNo');
    if (val === 1) {
      if (boxYes) { boxYes.style.background = '#eff6ff'; boxYes.style.borderColor = '#2563eb'; }
      if (boxNo)  { boxNo.style.background = '#f8fafc'; boxNo.style.borderColor = '#cbd5e1'; }
    } else {
      if (boxYes) { boxYes.style.background = '#f8fafc'; boxYes.style.borderColor = '#cbd5e1'; }
      if (boxNo)  { boxNo.style.background = '#fef2f2'; boxNo.style.borderColor = '#dc2626'; }
    }
  };

  window.saveEvidencePhotoChoiceAndFinish = async function() {
    const selectedRadio = document.querySelector('input[name="modal_hearing_choice"]:checked');
    const showVal = selectedRadio ? parseInt(selectedRadio.value) : 1;
    const btn = document.getElementById('btnSaveHearingChoice');
    if (btn) { btn.disabled = true; btn.textContent = 'Saving...'; }

    const fd = new FormData();
    fd.append('type', 'offense');
    fd.append('id', OFFENSE_ID);
    fd.append('show', showVal);

    await fetch('AJAX/toggle_hearing_photo.php', { method: 'POST', body: fd }).catch(() => null);

    const choiceModal = document.getElementById('modal-evidence-photo-choice');
    if (choiceModal) choiceModal.classList.remove('active');
    if (btn) { btn.disabled = false; btn.textContent = '✓ Save Choice & Finish Registration'; }

    showFinalSuccessModal(window.__pendingNteSentStatus);
  };

  const studentIdInput    = document.getElementById('studentIdInput');
  const levelSelect       = document.getElementById('levelSelect');
  const alertPanel        = document.getElementById('alertPanel');
  const offenseTypeSelect = document.getElementById('offense_type_id');

  function showPlaceholder() {
    alertPanel.innerHTML = `<div class="panel-placeholder"><svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg><p>Enter a Student ID to see the offense status and history for this student.</p></div>`;
  }

  async function refreshOffenseTypes(selectedId = null) {
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
      const currentVal = selectedId || select.value;
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
  function dismissSuccessModal() {
    if (window.successModalTimer) clearTimeout(window.successModalTimer);
    const bar = document.getElementById('successModalProgress');
    if (bar) bar.style.transition = 'none';
    if (successModal) successModal.classList.remove('active');
  }
  function closeSuccessModal() {
    if (window.successModalTimer) clearTimeout(window.successModalTimer);
    if (successModal) successModal.classList.remove('active'); 
    if (typeof LETTER_MODE !== 'undefined' && LETTER_MODE) {
      const letterModal = document.getElementById('modal-guardian-letter');
      if (letterModal) letterModal.classList.add('active');
      if (typeof previewLetter === 'function') previewLetter();
    }
  }
  function closeEmailSuccessModal() {
    if (window.emailSuccessModalTimer) clearInterval(window.emailSuccessModalTimer);
    const m = document.getElementById('emailSuccessModal');
    if (m) m.classList.remove('active');
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
    const hasPhpErrors = <?php echo empty($errors) ? 'false' : 'true'; ?>;
    if (SHOW_STUDENT_RECORD_MODAL && !SUCCESS_MODE && !hasPhpErrors) {
      openStudentRecordModal();
    }
  });
  function openAddModal() {
    document.getElementById('edit_offense_type_id').value = '';
    document.getElementById('typeModalTitle').innerText = 'Add New Offense Type';
    document.getElementById('type_code').value  = '';
    document.getElementById('type_name').value  = '';
    document.getElementById('type_level').value = currentLevel;
    document.getElementById('type_major_category').value = document.getElementById('major_category')?.value || '';
    toggleModalCategory();
    modal.classList.add('active');
  }
  function toggleModalCategory() {
    const lvl = document.getElementById('type_level').value;
    document.getElementById('modalCategoryGroup').style.display = (lvl === 'MAJOR') ? 'block' : 'none';
  }
  document.getElementById('type_level').addEventListener('change', toggleModalCategory);

  function editSelectedType() {
      const sel = document.getElementById('offense_type_id');
      if (!sel.value || sel.value == '22' || sel.value == '23' || sel.value == '24') return;
      const opt = sel.options[sel.selectedIndex];
      const parts = opt.textContent.trim().split(' — ');
      if (parts.length >= 2) {
          document.getElementById('type_code').value = parts[0].trim();
          document.getElementById('type_name').value = parts.slice(1).join(' — ').trim();
      }
      document.getElementById('type_level').value = currentLevel;
      if (currentLevel === 'MAJOR') {
          document.getElementById('type_major_category').value = document.getElementById('major_category')?.value || '';
      }
      toggleModalCategory();
      
      document.getElementById('edit_offense_type_id').value = sel.value;
      document.getElementById('typeModalTitle').innerText = 'Edit Offense Type';
      modal.classList.add('active');
  }

  let pendingDeleteTypeId = null;

  function deleteSelectedType() {
      const sel = document.getElementById('offense_type_id');
      if (!sel.value || sel.value == '22' || sel.value == '23') return;
      pendingDeleteTypeId = sel.value;
      document.getElementById('confirmDeleteTypeModal').classList.add('active');
  }

  async function executeDeleteType() {
      if (!pendingDeleteTypeId) return;
      document.getElementById('confirmDeleteTypeModal').classList.remove('active');
      
      const formData = new FormData();
      formData.append('action', 'delete_offense_type');
      formData.append('offense_type_id', pendingDeleteTypeId);
      
      const res = await fetch(window.location.href, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
      const data = await res.json();
      if (data.ok) {
          showTypeSuccessModal('Deleted Successfully', 'The offense type has been securely removed from the selection list.');
          await refreshOffenseTypes();
          const sel = document.getElementById('offense_type_id');
          if (sel) sel.dispatchEvent(new Event('change'));
      } else {
          alert(data.error || 'Failed to delete.');
      }
      pendingDeleteTypeId = null;
  }

  function showTypeSuccessModal(title, msg) {
      document.getElementById('typeActionSuccessTitle').innerText = title;
      document.getElementById('typeActionSuccessMsg').innerText = msg;
      document.getElementById('typeActionSuccessModal').classList.add('active');
  }


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
    
    const editId = document.getElementById('edit_offense_type_id').value;
    
    const formData = new FormData();
    formData.append('action', editId ? 'edit_offense_type' : 'add_offense_type');
    if (editId) formData.append('edit_id', editId);
    
    formData.append('code', code);
    formData.append('name', name);
    formData.append('level', level);
    if (majorCategory) formData.append('major_category', majorCategory);
    const res  = await fetch(window.location.href, { method:'POST', body:formData, headers:{'X-Requested-With':'XMLHttpRequest'} });
    const data = await res.json();
    if (data.ok) { 
        closeModal(); 
        await refreshOffenseTypes(data.new_id); 
        showTypeSuccessModal(editId ? 'Offense Type Updated' : 'Offense Type Created', 'The custom offense type has been successfully saved to the database.');
    }
    else { document.getElementById('modalError').innerText = data.error || 'Error saving offense type.'; }
  }

  function onLevelChange(newLevel) {
    const lvl = (newLevel || 'MINOR').toUpperCase();
    currentLevel = lvl;

    const catGroup = document.getElementById('categoryGroup');
    if (catGroup) {
      catGroup.style.display = (lvl === 'MAJOR') ? 'block' : 'none';
    }

    const disBanner = document.getElementById('dismissedAlertBanner');
    if (disBanner) {
      disBanner.style.display = (lvl === 'DISMISSED') ? 'flex' : 'none';
    }

    if (window.history && window.history.replaceState) {
      const url = new URL(window.location.href);
      url.searchParams.set('level', lvl);
      if (lvl !== 'MAJOR') url.searchParams.delete('major_category');
      window.history.replaceState(null, '', url.pathname + url.search);
    }

    refreshOffenseTypes();
  }

  function onCategoryChange(cat) {
    if (window.history && window.history.replaceState) {
      const url = new URL(window.location.href);
      url.searchParams.set('level', 'MAJOR');
      if (cat) url.searchParams.set('major_category', cat);
      else url.searchParams.delete('major_category');
      window.history.replaceState(null, '', url.pathname + url.search);
    }
    refreshOffenseTypes();
  }

  function lookupStudentId(overrideId) {
    if (!studentIdInput) return;
    const studentId = overrideId || studentIdInput.value.trim();
    const level     = levelSelect ? levelSelect.value : 'MINOR';
    const params    = new URLSearchParams({ level });
    if (studentId) params.set('student_id', studentId);
    window.location.href = 'offense_new.php?' + params.toString();
  }

  window.autoFillGuardReportData = function() {
    <?php if ($pendingGuardReport): ?>
      const reportTypeId = <?php echo (int)($pendingGuardReport['offense_type_id'] ?? 0); ?>;
      const reportDesc   = <?php echo json_encode((string)($pendingGuardReport['description'] ?? '')); ?>;
      const reportDate   = <?php echo json_encode(ph_date('Y-m-d\TH:i', $pendingGuardReport['created_at'] ?? null)); ?>;

      const typeSelect = document.getElementById('offense_type_id');
      const descInput  = document.getElementById('description');
      const dateInput  = document.getElementById('date_committed');

      if (typeSelect && reportTypeId > 0) {
        typeSelect.value = reportTypeId;
      }
      if (descInput && reportDesc) {
        descInput.value = reportDesc;
      }
      if (dateInput && reportDate) {
        dateInput.value = reportDate;
      }

      const banner = document.getElementById('pendingGuardReportBanner');
      if (banner) {
        banner.style.transition = 'all 0.3s ease';
        banner.style.background = '#dcfce7';
        banner.style.borderColor = '#86efac';
      }
    <?php endif; ?>
  };

  const suggestionsBox = document.getElementById('studentSuggestions');
  let searchTimer = null;

  function escapeHtml(str) {
    return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }

  if (studentIdInput && suggestionsBox) {
    studentIdInput.addEventListener('input', function() {
      const q = this.value.trim();
      if (searchTimer) clearTimeout(searchTimer);

      if (q.length < 2) {
        suggestionsBox.classList.remove('show');
        suggestionsBox.innerHTML = '';
        return;
      }

      searchTimer = setTimeout(async function() {
        try {
          const res = await fetch('AJAX/search_students_offenses.php?q=' + encodeURIComponent(q) + '&limit=8', {
            headers: { 'Accept': 'application/json' }
          });
          const json = await res.json();
          if (json.ok && Array.isArray(json.data) && json.data.length > 0) {
            suggestionsBox.innerHTML = json.data.map(s => `
              <div class="suggestion-item" data-id="${escapeHtml(s.student_id)}">
                <div class="suggestion-info">
                  <span class="suggestion-id">${escapeHtml(s.student_id)}</span>
                  <span class="suggestion-name">${escapeHtml(s.student_name)}</span>
                </div>
                <span class="suggestion-program">${escapeHtml(s.program || '')}</span>
              </div>
            `).join('');
            suggestionsBox.classList.add('show');

            suggestionsBox.querySelectorAll('.suggestion-item').forEach(item => {
              item.addEventListener('click', function() {
                const sid = this.getAttribute('data-id');
                studentIdInput.value = sid;
                suggestionsBox.classList.remove('show');
                lookupStudentId(sid);
              });
            });
          } else {
            suggestionsBox.classList.remove('show');
          }
        } catch(e) {
          suggestionsBox.classList.remove('show');
        }
      }, 200);
    });

    document.addEventListener('click', function(e) {
      if (!studentIdInput.contains(e.target) && !suggestionsBox.contains(e.target)) {
        suggestionsBox.classList.remove('show');
      }
    });

    studentIdInput.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') {
        e.preventDefault();
        suggestionsBox.classList.remove('show');
        lookupStudentId();
      }
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
  
  async function postForm(url, formData) {
    const res = await fetch(url, {
      method: 'POST',
      headers: { 'Accept': 'application/json' },
      body: formData,
      cache: 'no-store'
    });
    return { ok: res.ok, json: await res.json().catch(() => null) };
  }

  let previewTimeout = null;
  window.debouncePreview = function() {
      if (previewTimeout) clearTimeout(previewTimeout);
      previewTimeout = setTimeout(function() {
          previewLetter();
      }, 500);
  };

  function getLetterBody() {
    if (window.quillLetterEditor && window.quillLetterEditor.root) {
      return window.quillLetterEditor.root.innerHTML;
    }
    const qlEditor = document.querySelector('#letter_body_editor .ql-editor');
    if (qlEditor) return qlEditor.innerHTML;
    const rawDiv = document.getElementById('letter_body_editor');
    if (rawDiv) return rawDiv.innerHTML;
    const txt = document.getElementById('letter_body');
    if (txt && txt.value) return txt.value;
    return '';
  }

  async function previewLetter() {
    const guardianEmail = document.getElementById('letter_guardian_email')?.value.trim() || '';
    const subject = document.getElementById('letter_subject')?.value || '';
    const body    = getLetterBody();
    const preview = document.getElementById('previewContent');
    if (!preview) return;
    if (!guardianEmail) {
        preview.innerHTML = '<div style="padding:16px;color:var(--red);font-weight:600;">⚠️ Cannot generate preview: Guardian email is required. Please enter a valid email address.</div>';
        return;
    }
    preview.innerHTML = '<div class="loading"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg> Generating…</div>';
    
    const formData = new FormData();
    formData.append('offense_id', OFFENSE_ID);
    formData.append('subject', subject);
    formData.append('body', body);
    formData.append('guardian_email', guardianEmail);
    
    const r = await postForm('AJAX/offense_letter_preview.php', formData);
    if (r.ok && r.json?.ok && r.json?.pdf_url) {
        const pdfUrl = r.json.pdf_url;
        const studentName = r.json.student_name || '';
        const studentId = r.json.student_id || '';
        const dateGen = r.json.date_gen || '';
        
        preview.innerHTML = `
          <div style="display:flex; flex-direction:column; height:100%; background:#fff; border-radius:8px; border:1px solid var(--border); overflow:hidden;">
            <div style="padding:10px 14px; background:#f8fafc; border-bottom:1px solid #e2e8f0; display:flex; align-items:center; justify-content:space-between; flex-shrink:0;">
              <span style="font-size:12px; font-weight:700; color:#475569;">📄 Official Document Preview</span>
              <a href="${pdfUrl}" target="_blank" style="font-size:12px; font-weight:700; color:#2563eb; text-decoration:none; display:inline-flex; align-items:center; gap:5px; background:#eff6ff; padding:4px 10px; border-radius:6px; border:1px solid #bfdbfe;">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><path d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg> Open Official PDF
              </a>
            </div>
            <div style="flex:1; padding:20px; overflow-y:auto; font-family:'Segoe UI', system-ui, sans-serif; color:#1e293b; line-height:1.6; font-size:13px;">
              <div style="margin-bottom:10px;"><img src="../assets/guardian_letterhead.png" style="max-width:240px; height:auto; display:block;" alt="NU Lipa Student Discipline Office" /></div>
              <div style="font-size:18px; font-weight:800; color:#0f172a; font-family:'Times New Roman', serif;">Student Discipline Office</div>
              <div style="font-size:11px; font-weight:700; color:#64748b; margin-bottom:14px;">Official Student Conduct Notice · IdentiTrack System</div>
              <div style="font-size:14px; font-weight:800; color:#0f172a; margin-bottom:12px; border-bottom:1px solid #e2e8f0; padding-bottom:6px;">${escHtml(subject)}</div>
              <div style="font-size:11px; color:#475569; margin-bottom:14px; line-height:1.5;">
                <strong>To:</strong> ${escHtml(guardianEmail)}<br>
                <strong>Student:</strong> ${escHtml(studentName)} (${escHtml(studentId)})<br>
                <strong>Generated:</strong> ${escHtml(dateGen)}
              </div>
              <div style="border-top:1px solid #e2e8f0; padding-top:12px; font-size:13px; color:#1e293b;">
                ${body}
              </div>
            </div>
          </div>
        `;
    }
    else preview.innerHTML = '<div style="padding:16px;color:var(--red);font-weight:600;">Failed to generate preview.</div>';
  }
  async function sendLetter() {
    const guardianEmail = document.getElementById('letter_guardian_email')?.value.trim() || '';
    const subject = document.getElementById('letter_subject')?.value || '';
    const body    = getLetterBody();
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

    const sendingModal = document.getElementById('emailSendingModal');
    if (sendingModal) sendingModal.classList.add('active');

    const formData = new FormData();
    formData.append('offense_id', OFFENSE_ID);
    formData.append('subject', subject);
    formData.append('body', body);
    formData.append('guardian_email', guardianEmail);
    
    if (msg) { msg.textContent = 'Sending email with attachments…'; msg.style.color = 'var(--text-3)'; }
    const r = await postForm('AJAX/offense_letter_send.php', formData);
    
    if (sendingModal) sendingModal.classList.remove('active');

    if (msg) {
      if (r.ok && r.json?.ok) { 
        msg.textContent = '✅ Email sent successfully.'; 
        msg.style.color = 'var(--green)'; 
        
        // Hide the guardian letter modal and transition DIRECTLY to Form F-005 Editor
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

        // Strip letter parameters from the URL
        if (window.history && window.history.replaceState) {
            const url = new URL(window.location.href);
            url.searchParams.delete('letter');
            url.searchParams.delete('offense_id');
            url.searchParams.delete('type');
            url.searchParams.delete('minor_no');
            window.history.replaceState(null, '', url.pathname + url.search);
        }

        // Open Modal #2 (Form F-005 Notice to Explain Editor) ONLY for Major or Section 4 Escalation!
        if (typeof LETTER_TYPE !== 'undefined' && (LETTER_TYPE === 'major' || LETTER_TYPE === 'escalation')) {
            openNteEditorModal();
        } else {
            showFinalSuccessModal();
        }
      }
      else { 
        msg.textContent = '❌ Failed: ' + (r.json?.message || 'Unknown error'); 
        msg.style.color = 'var(--red)'; 
        if (btn) { btn.disabled = false; btn.style.opacity = '1'; btn.style.cursor = 'pointer'; }
      }
    }
  }



  function showFinalSuccessModal(nteSent = false) {
      const modal = document.getElementById('finalProcessSuccessModal');
      const nteText = document.getElementById('finalNteStatusText');
      if (nteText) {
          if (nteSent) {
              nteText.innerHTML = '✅ Form F-005 Notice to Explain sent to student\'s Outlook email.';
              nteText.style.color = '#10b981';
              nteText.style.display = 'block';
          } else if (typeof LETTER_TYPE !== 'undefined' && (LETTER_TYPE === 'major' || LETTER_TYPE === 'escalation')) {
              nteText.innerHTML = 'ℹ️ Form F-005 file skipped (not sent to student).';
              nteText.style.color = 'var(--text-3)';
              nteText.style.display = 'block';
          } else {
              nteText.style.display = 'none';
          }
      }
      if (modal) {
          modal.classList.add('active');
          var bar = document.getElementById('finalSuccessProgress');
          if (bar) {
              bar.style.transition = 'none';
              bar.style.width = '100%';
              setTimeout(() => {
                  bar.style.transition = 'width 5s linear';
                  bar.style.width = '0%';
              }, 50);
          }
          window.finalSuccessModalTimer = setTimeout(() => {
              closeFinalSuccessModal();
          }, 5000);
      }
  }

  function dismissFinalSuccessModal() {
      if (window.finalSuccessModalTimer) clearTimeout(window.finalSuccessModalTimer);
      const bar = document.getElementById('finalSuccessProgress');
      if (bar) bar.style.transition = 'none';
      const m = document.getElementById('finalProcessSuccessModal');
      if (m) m.classList.remove('active');
  }

  function closeFinalSuccessModal() {
      dismissFinalSuccessModal();
      if (window.history && window.history.replaceState) {
          const url = new URL(window.location.href);
          url.searchParams.delete('success');
          url.searchParams.delete('letter');
          url.searchParams.delete('offense_id');
          url.searchParams.delete('type');
          url.searchParams.delete('minor_no');
          window.history.replaceState(null, '', url.pathname + url.search);
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

  const HAS_ERRORS = <?php echo empty($errors) ? 'false' : 'true'; ?>;
  if (SUCCESS_MODE && successModal && !HAS_ERRORS && !LETTER_MODE) {
      // Strip ONLY success parameter from the URL so refreshing doesn't trigger success again.
      // (Letter parameters are kept so the email modal persists on refresh if not sent).
      if (window.history && window.history.replaceState) {
          const url = new URL(window.location.href);
          url.searchParams.delete('success');
          window.history.replaceState(null, '', url.pathname + url.search);
      }
      
      successModal.classList.add('active');
      
      var bar = document.getElementById('successModalProgress');
      if (bar) {
          bar.style.transition = 'none';
          bar.style.width = '100%';
          setTimeout(() => {
              bar.style.transition = 'width 5s linear';
              bar.style.width = '0%';
          }, 50);
      }
      
      window.successModalTimer = setTimeout(() => {
          closeSuccessModal();
      }, 5000);
  }
  
  if (LETTER_MODE) {
      setTimeout(() => {
          checkEmailRequired();
          if (typeof previewLetter === 'function') previewLetter();
          const letterModal = document.getElementById('modal-guardian-letter');
          if (letterModal) letterModal.classList.add('active');
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

  window.toggleHearingPhoto = async function(type, id, nextShow, btnEl) {
    const fd = new FormData();
    fd.append('type', type);
    fd.append('id', id);
    fd.append('show', nextShow);
    
    btnEl.disabled = true;
    const oldText = btnEl.textContent;
    btnEl.textContent = 'Updating...';
    
    try {
      const res = await fetch('AJAX/toggle_hearing_photo.php', { method: 'POST', body: fd });
      const json = await res.json().catch(() => null);
      if (json && json.ok) {
        const isYes = json.show === 1;
        btnEl.style.background = isYes ? '#dcfce7' : '#f1f5f9';
        btnEl.style.color = isYes ? '#15803d' : '#64748b';
        btnEl.style.borderColor = isYes ? '#86efac' : '#cbd5e1';
        btnEl.textContent = isYes ? 'YES (Shown in Hearing)' : 'NO (Private)';
        btnEl.setAttribute('onclick', `toggleHearingPhoto('${type}', ${id}, ${isYes ? 0 : 1}, this)`);
      } else {
        alert('Failed to update status: ' + (json?.message || 'Unknown error'));
        btnEl.textContent = oldText;
      }
    } catch(err) {
      alert('Error updating status.');
      btnEl.textContent = oldText;
    }
    btnEl.disabled = false;
  };
  </script>
  <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>
  <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
  <script>
    document.addEventListener('DOMContentLoaded', function() {
        if (document.getElementById('letter_body_editor')) {
            window.quillLetterEditor = new Quill('#letter_body_editor', {
                theme: 'snow',
                modules: {
                    toolbar: [
                        [{ 'size': ['small', false, 'large', 'huge'] }],
                        ['bold', 'italic', 'underline', 'strike'],
                        [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                        ['link', 'image'],
                        ['clean']
                    ]
                }
            });
            window.quillLetterEditor.on('text-change', function() {
                if (window.debouncePreview) {
                    window.debouncePreview();
                }
            });
            
            // Kick off an initial preview render
            if (window.debouncePreview) {
                window.debouncePreview();
            }
        }
    });

  // We keep only the first definition of these functions, so the second block is removed.
  // The event listener for .btn-trigger-nte-upload is already defined above; no need to redefine.
  // Additional duplicate definitions removed.

  async function submitDirectNteUpload(e) {
      e.preventDefault();
      const form = document.getElementById('directNteUploadForm');
      const formData = new FormData(form);
      const msg = document.getElementById('directNteUploadMsg');
      const btn = document.getElementById('btnSubmitDirectNte');
      
      if (msg) { msg.innerHTML = '⌛ Uploading & sending email to student Outlook…'; msg.style.color = '#334155'; }
      if (btn) { btn.disabled = true; btn.style.opacity = '0.5'; }
      
      try {
          const res = await fetch('api_send_nte_form.php', { method: 'POST', body: formData });
          const data = await res.json();
          
          if (data.ok) {
              if (msg) { msg.innerHTML = '✅ Form F-005 uploaded & sent to student Outlook!'; msg.style.color = '#166534'; }
              setTimeout(() => {
                  closeDirectNteUploadModal();
                  window.location.reload();
              }, 1200);
          } else {
              if (msg) { msg.innerHTML = '❌ Failed: ' + (data.error || data.message || 'Error occurred'); msg.style.color = '#b91c1c'; }
              if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
          }
      } catch (err) {
          if (msg) { msg.innerHTML = '❌ Upload error: ' + err.message; msg.style.color = '#b91c1c'; }
          if (btn) { btn.disabled = false; btn.style.opacity = '1'; }
      }
  }
  <!-- MODAL 1: DISMISSAL REASON -->
  <div id="dismissalReasonModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(15,23,42,0.75); z-index:999999; align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#ffffff; border-radius:16px; max-width:540px; width:92%; padding:24px; box-shadow:0 20px 40px rgba(0,0,0,0.3); border:1px solid #cbd5e1; animation: apIn .25s ease;">
      <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #e2e8f0; padding-bottom:14px; margin-bottom:16px;">
        <h3 style="font-size:17px; font-weight:800; color:#0f172a; display:flex; align-items:center; gap:8px;">
          <span style="background:#e2e8f0; padding:6px; border-radius:8px; display:inline-flex;">📋</span> Reason for Dismissal
        </h3>
        <button type="button" class="modal-close" onclick="closeDismissalReasonModal()" style="background:none; border:none; font-size:22px; cursor:pointer; color:#64748b;">&times;</button>
      </div>
      <div class="modal-body" style="display:flex; flex-direction:column; gap:14px;">
        <div style="font-size:13px; color:#475569; line-height:1.5; background:#f8fafc; padding:12px 14px; border-radius:10px; border:1px solid #e2e8f0;">
          Specify why this offense is being recorded as <strong>DISMISSED</strong> (e.g., confiscated vape lacked battery or e-liquid, incident did not meet handbook criteria, recorded for administrative reference only).
        </div>
        <div>
          <label style="font-weight:700; font-size:12px; color:#334155; display:block; margin-bottom:6px;">Dismissal Explanation / Admin Notes *</label>
          <textarea id="modalDismissalReasonInput" rows="4" style="width:100%; border:1.5px solid #cbd5e1; border-radius:10px; padding:12px; font-family:inherit; font-size:13px; outline:none;" placeholder="Enter detailed reason why this offense is recorded as dismissed..."></textarea>
          <div id="modalDismissalError" style="color:#dc2626; font-size:12px; font-weight:700; margin-top:4px; display:none;">Please enter a reason for dismissal before proceeding.</div>
        </div>
      </div>
      <div class="modal-footer" style="display:flex; justify-content:flex-end; gap:10px; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:14px;">
        <button type="button" class="btn" onclick="closeDismissalReasonModal()" style="padding:10px 18px; border-radius:10px; font-weight:700;">Cancel</button>
        <button type="button" class="btn btn-primary" onclick="proceedToDismissalApproval()" style="padding:10px 20px; border-radius:10px; font-weight:700; background:#2563eb; color:#fff; border:none; cursor:pointer;">Next: Second Approval &rarr;</button>
      </div>
    </div>
  </div>

  <!-- MODAL 2: DISMISSAL SECOND APPROVAL -->
  <div id="dismissalApprovalModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(15,23,42,0.8); z-index:999999; align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#ffffff; border-radius:16px; max-width:560px; width:92%; padding:24px; box-shadow:0 25px 50px rgba(0,0,0,0.35); border:1.5px solid #f59e0b; animation: apIn .25s ease;">
      <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #fef3c7; padding-bottom:14px; margin-bottom:16px; background:#fffbeb; margin:-24px -24px 16px -24px; padding:16px 24px; border-radius:16px 16px 0 0;">
        <h3 style="font-size:17px; font-weight:800; color:#b45309; display:flex; align-items:center; gap:8px;">
          <span>🛡️</span> Second Approval — Confirm Record
        </h3>
        <button type="button" class="modal-close" onclick="closeDismissalApprovalModal()" style="background:none; border:none; font-size:22px; cursor:pointer; color:#b45309;">&times;</button>
      </div>
      <div class="modal-body" style="display:flex; flex-direction:column; gap:14px;">
        <div style="font-size:15px; color:#0f172a; line-height:1.5; font-weight:800; background:#fffbeb; padding:14px 16px; border-radius:10px; border:1px solid #fde68a;">
          Are you sure you just want to record this offense as <span style="background:#fef3c7; color:#92400e; padding:2px 8px; border-radius:6px; font-weight:800;">DISMISSED</span>?
        </div>
        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:12px; padding:14px; display:flex; flex-direction:column; gap:8px; font-size:12.5px;">
          <div><strong>Student:</strong> <span id="approvalStudentText">-</span></div>
          <div><strong>Incident Date:</strong> <span id="approvalDateText">-</span></div>
          <div><strong>Offense Type:</strong> <span id="approvalOffenseTypeText">-</span></div>
          <div style="border-top:1px dashed #cbd5e1; padding-top:8px; margin-top:4px;">
            <strong style="color:#b45309; display:block; margin-bottom:4px;">Reason for Dismissal:</strong>
            <div id="approvalReasonText" style="color:#334155; font-style:italic; line-height:1.5; background:#ffffff; padding:10px; border-radius:8px; border:1px solid #e2e8f0;">-</div>
          </div>
        </div>
        <div style="font-size:12px; color:#64748b; background:#f1f5f9; padding:10px 12px; border-radius:8px;">
          ℹ️ Recording as <strong>DISMISSED</strong> stores this in database logs for administrative tracking only. It will not increase minor/major counts or trigger Section 4 escalation.
        </div>
      </div>
      <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:14px;">
        <button type="button" class="btn" onclick="closeDismissalApprovalModal()" style="padding:10px 18px; border-radius:10px; font-weight:700; color:#64748b; background:#f1f5f9; border:1px solid #cbd5e1; cursor:pointer;">No, Cancel</button>
        <button type="button" class="btn" id="btnConfirmDismissedSave" onclick="submitDismissedFormFinal(this)" style="padding:11px 22px; border-radius:10px; font-weight:800; background:#16a34a; color:#ffffff; border:none; box-shadow:0 4px 12px rgba(22,163,74,0.3); cursor:pointer; display:inline-flex; align-items:center; gap:6px;">
          Yes, Record as Dismissed
        </button>
      </div>
    </div>
  </div>

  <!-- MODAL 3: INCIDENT EVIDENCE / PHOTO UPLOAD FOR MAJOR & SECTION 4 -->
  <div id="evidenceUploadModal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(15,23,42,0.8); z-index:999999; align-items:center; justify-content:center;">
    <div class="modal-content" style="background:#ffffff; border-radius:16px; max-width:580px; width:92%; padding:24px; box-shadow:0 25px 50px rgba(0,0,0,0.35); border:1.5px solid #3b82f6; animation: apIn .25s ease;">
      <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; border-bottom:1px solid #dbeafe; padding-bottom:14px; margin-bottom:16px; background:#eff6ff; margin:-24px -24px 16px -24px; padding:16px 24px; border-radius:16px 16px 0 0;">
        <h3 style="font-size:17px; font-weight:800; color:#1e40af; display:flex; align-items:center; gap:8px;">
          <span>📷</span> Incident Report & Evidence Attachment
        </h3>
        <button type="button" class="modal-close" onclick="closeEvidenceUploadModal()" style="background:none; border:none; font-size:22px; cursor:pointer; color:#1e40af;">&times;</button>
      </div>
      <div class="modal-body" style="display:flex; flex-direction:column; gap:14px;">
        <div style="font-size:13px; color:#1e3a8a; line-height:1.5; background:#eff6ff; padding:12px 14px; border-radius:10px; border:1px solid #bfdbfe;">
          <strong>Major / Section 4 Offense Triggered!</strong> You can attach a photo or file of the Incident Report before registering. The UPCC Panel will be able to inspect this evidence during the hearing.
        </div>
        
        <div id="modalDropZone" style="border:2px dashed #93c5fd; background:#f8fafc; border-radius:12px; padding:24px; text-align:center; cursor:pointer; transition:all 0.2s;" onclick="document.getElementById('modalFileSelectInput').click()">
          <svg fill="none" stroke="#3b82f6" stroke-width="1.5" viewBox="0 0 24 24" style="width:42px; height:42px; margin-bottom:8px;"><path d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
          <div style="font-weight:700; font-size:14px; color:#1e293b;" id="modalDropZoneTitle">Click to select Incident Report Photo or PDF</div>
          <div style="font-size:12px; color:#64748b; margin-top:4px;">Supports JPG, PNG, WEBP images or PDF documents</div>
          <input type="file" id="modalFileSelectInput" accept="image/*,.pdf" style="display:none;" onchange="handleModalFileSelected(this.files)">
        </div>

        <div id="modalFilePreviewBox" style="display:none; background:#f1f5f9; padding:12px; border-radius:10px; align-items:center; gap:12px; border:1px solid #cbd5e1;">
          <div id="modalFileThumbnail" style="width:48px; height:48px; border-radius:8px; overflow:hidden; background:#e2e8f0; display:flex; align-items:center; justify-content:center; flex-shrink:0;"></div>
          <div style="flex:1; min-width:0;">
            <div id="modalFileName" style="font-weight:700; font-size:13px; color:#0f172a; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">filename.jpg</div>
            <div id="modalFileSize" style="font-size:11px; color:#64748b;">0 KB</div>
          </div>
          <button type="button" onclick="clearModalFileSelection()" style="background:#fee2e2; color:#dc2626; border:none; padding:6px 10px; border-radius:6px; font-weight:700; font-size:12px; cursor:pointer;">Remove</button>
        </div>

        <div style="background:#f8fafc; border:1px solid #e2e8f0; border-radius:10px; padding:12px; display:flex; align-items:center; justify-content:space-between;">
          <div>
            <div style="font-size:13px; font-weight:700; color:#1e293b;">📷 Show Photo Evidence in Student Hearing?</div>
            <div style="font-size:11px; color:#64748b;">If YES, UPCC panel members can inspect this photo during the student hearing.</div>
          </div>
          <div style="display:flex; gap:6px;">
            <button type="button" id="btnShowInHearingYes" onclick="setHearingChoice(1)" style="padding:6px 14px; border-radius:8px; font-weight:800; font-size:12px; border:1px solid #2563eb; background:#2563eb; color:#fff; cursor:pointer;">YES</button>
            <button type="button" id="btnShowInHearingNo" onclick="setHearingChoice(0)" style="padding:6px 14px; border-radius:8px; font-weight:800; font-size:12px; border:1px solid #cbd5e1; background:#f1f5f9; color:#64748b; cursor:pointer;">NO</button>
            <input type="hidden" id="show_in_hearing_input" name="show_in_hearing" value="1" />
          </div>
        </div>
      </div>
      <div class="modal-footer" style="display:flex; justify-content:space-between; align-items:center; margin-top:20px; border-top:1px solid #e2e8f0; padding-top:14px;">
        <button type="button" class="btn" onclick="skipEvidenceUploadAndSubmit()" style="padding:10px 16px; border-radius:10px; font-weight:700; color:#64748b; cursor:pointer;">Skip & Register Case</button>
        <button type="button" class="btn btn-primary" onclick="submitFormWithEvidence()" style="padding:11px 22px; border-radius:10px; font-weight:800; background:#2563eb; color:#ffffff; border:none; box-shadow:0 4px 12px rgba(37,99,235,0.3); cursor:pointer;">
          Upload & Register Case
        </button>
      </div>
    </div>
  </div>

  <script>
    window.setHearingChoice = function(val) {
      const input = document.getElementById('show_in_hearing_input');
      const btnYes = document.getElementById('btnShowInHearingYes');
      const btnNo = document.getElementById('btnShowInHearingNo');
      if (input) input.value = val;
      if (val === 1) {
        if (btnYes) { btnYes.style.background = '#2563eb'; btnYes.style.borderColor = '#2563eb'; btnYes.style.color = '#fff'; }
        if (btnNo) { btnNo.style.background = '#f1f5f9'; btnNo.style.borderColor = '#cbd5e1'; btnNo.style.color = '#64748b'; }
      } else {
        if (btnYes) { btnYes.style.background = '#f1f5f9'; btnYes.style.borderColor = '#cbd5e1'; btnYes.style.color = '#64748b'; }
        if (btnNo) { btnNo.style.background = '#dc2626'; btnNo.style.borderColor = '#dc2626'; btnNo.style.color = '#fff'; }
      }
    };

    window.__projectedMinorCount = <?php echo (int)($afterMinor ?? ($liveMinorCount + 1)); ?>;

    function openDismissalReasonModal() {
      const m = document.getElementById('dismissalReasonModal');
      const input = document.getElementById('modalDismissalReasonInput');
      const err = document.getElementById('modalDismissalError');
      if (err) err.style.display = 'none';
      if (input) input.value = document.getElementById('dismissal_reason_hidden')?.value || '';
      if (m) m.style.display = 'flex';
    }

    function closeDismissalReasonModal() {
      const m = document.getElementById('dismissalReasonModal');
      if (m) m.style.display = 'none';
    }

    function proceedToDismissalApproval() {
      const input = document.getElementById('modalDismissalReasonInput');
      const err = document.getElementById('modalDismissalError');
      const val = (input ? input.value : '').trim();
      if (!val) {
        if (err) err.style.display = 'block';
        return;
      }
      if (err) err.style.display = 'none';
      
      document.getElementById('dismissal_reason_hidden').value = val;
      closeDismissalReasonModal();

      // Populate Modal 2 summary
      const studentInput = document.getElementById('studentIdInput')?.value || '';
      const dateInput = document.getElementById('date_committed')?.value || '';
      const typeSelect = document.getElementById('offense_type_id');
      const selectedTypeOption = typeSelect ? typeSelect.options[typeSelect.selectedIndex] : null;
      const typeText = selectedTypeOption ? selectedTypeOption.text : 'Selected Offense Type';

      document.getElementById('approvalStudentText').textContent = studentInput || 'Student';
      document.getElementById('approvalDateText').textContent = dateInput ? dateInput.replace('T', ' ') : 'Now';
      document.getElementById('approvalOffenseTypeText').textContent = typeText;
      document.getElementById('approvalReasonText').textContent = val;

      const appModal = document.getElementById('dismissalApprovalModal');
      if (appModal) appModal.style.display = 'flex';
    }

    function closeDismissalApprovalModal() {
      const appModal = document.getElementById('dismissalApprovalModal');
      if (appModal) appModal.style.display = 'none';
    }

    function backToDismissalReasonModal() {
      closeDismissalApprovalModal();
      openDismissalReasonModal();
    }

    function submitDismissedFormFinal() {
      const confirmedInput = document.getElementById('dismissal_approval_confirmed');
      if (confirmedInput) confirmedInput.value = '1';
      closeDismissalApprovalModal();
      const form = document.getElementById('offenseForm');
      if (form) {
        form.submit();
      }
    }

    // Modal 3 Evidence Upload JS
    function openEvidenceUploadModal() {
      const m = document.getElementById('evidenceUploadModal');
      if (m) m.style.display = 'flex';
    }

    function closeEvidenceUploadModal() {
      const m = document.getElementById('evidenceUploadModal');
      if (m) m.style.display = 'none';
    }

    function handleModalFileSelected(files) {
      if (!files || files.length === 0) return;
      const file = files[0];
      const previewBox = document.getElementById('modalFilePreviewBox');
      const fileNameEl = document.getElementById('modalFileName');
      const fileSizeEl = document.getElementById('modalFileSize');
      const thumbEl = document.getElementById('modalFileThumbnail');

      if (fileNameEl) fileNameEl.textContent = file.name;
      if (fileSizeEl) fileSizeEl.textContent = (file.size / 1024).toFixed(1) + ' KB';

      if (file.type.startsWith('image/')) {
        const reader = new FileReader();
        reader.onload = function(e) {
          if (thumbEl) thumbEl.innerHTML = `<img src="${e.target.result}" style="width:100%; height:100%; object-fit:cover;" />`;
        };
        reader.readAsDataURL(file);
      } else {
        if (thumbEl) thumbEl.innerHTML = `<span style="font-size:20px; color:#dc2626;">📄</span>`;
      }

      if (previewBox) previewBox.style.display = 'flex';

      // Sync file to the hidden file input in #offenseForm
      const mainInput = document.getElementById('evidence_file_input');
      if (mainInput && window.DataTransfer) {
        const dt = new DataTransfer();
        dt.items.add(file);
        mainInput.files = dt.files;
      }
    }

    function clearModalFileSelection() {
      const previewBox = document.getElementById('modalFilePreviewBox');
      const fileSelect = document.getElementById('modalFileSelectInput');
      const mainInput = document.getElementById('evidence_file_input');
      if (previewBox) previewBox.style.display = 'none';
      if (fileSelect) fileSelect.value = '';
      if (mainInput) mainInput.value = '';
    }

    function skipEvidenceUploadAndSubmit() {
      document.getElementById('evidence_file_confirmed').value = '1';
      closeEvidenceUploadModal();
      const form = document.getElementById('offenseForm');
      if (form) form.submit();
    }

    function submitFormWithEvidence() {
      document.getElementById('evidence_file_confirmed').value = '1';
      closeEvidenceUploadModal();
      const form = document.getElementById('offenseForm');
      if (form) form.submit();
    }

    function updateDescRequirement() {
      const levelSelect = document.getElementById('levelSelect');
      const lvl = (levelSelect ? levelSelect.value : '').toUpperCase();
      const typeSelect = document.getElementById('offense_type_id');
      const typeId = typeSelect ? typeSelect.value : '';
      const descOpt = document.getElementById('descOptional');
      const descInput = document.getElementById('description');
      const isRequired = (lvl === 'DISMISSED' || ['22', '23', '24'].includes(typeId));

      if (descOpt) {
        if (isRequired) {
          descOpt.innerHTML = ' <span style="color:#dc2626; font-weight:800; font-size:15px;">*</span>';
        } else {
          descOpt.innerHTML = ' <span style="color:#64748b; font-weight:normal;">(optional)</span>';
        }
      }
      if (descInput) {
        if (isRequired) {
          descInput.setAttribute('required', 'required');
        } else {
          descInput.removeAttribute('required');
        }
      }
    }

    // Intercept form submission
    document.addEventListener('DOMContentLoaded', function() {
      updateDescRequirement();
      const form = document.getElementById('offenseForm');
      if (!form) return;

      form.addEventListener('submit', function(e) {
        const lvl = document.getElementById('levelSelect')?.value || 'MINOR';
        const isConfirmedEvidence = document.getElementById('evidence_file_confirmed')?.value === '1';

        // Check if DISMISSED
        if (lvl === 'DISMISSED') {
          let reasonInput = document.getElementById('dismissal_reason_hidden');
          let reason = reasonInput ? reasonInput.value : '';
          const descVal = (document.getElementById('description')?.value || '').trim();
          
          if (!reason && descVal) {
            reason = descVal;
            if (reasonInput) reasonInput.value = descVal;
          }
          
          const isConfirmedApproval = document.getElementById('dismissal_approval_confirmed')?.value === '1';

          if (!isConfirmedApproval) {
            e.preventDefault();
            e.stopPropagation();
            if (!reason) {
              openDismissalReasonModal();
            } else {
              document.getElementById('approvalStudentText').textContent = document.getElementById('studentIdInput')?.value || 'Student';
              document.getElementById('approvalDateText').textContent = (document.getElementById('date_committed')?.value || 'Now').replace('T', ' ');
              const typeSelect = document.getElementById('offense_type_id');
              const selectedTypeOption = typeSelect && typeSelect.selectedIndex >= 0 ? typeSelect.options[typeSelect.selectedIndex] : null;
              document.getElementById('approvalOffenseTypeText').textContent = selectedTypeOption ? selectedTypeOption.text : 'Selected Offense Type';
              document.getElementById('approvalReasonText').textContent = reason;

              const appModal = document.getElementById('dismissalApprovalModal');
              if (appModal) appModal.style.display = 'flex';
            }
            return false;
          }
        }

        // Check if Major or Section 4 escalation (3rd minor attempt)
        const isMajorOrEscalation = (lvl === 'MAJOR') || (lvl === 'MINOR' && window.__projectedMinorCount >= 3);
        if (isMajorOrEscalation && !isConfirmedEvidence) {
          e.preventDefault();
          e.stopPropagation();
          openEvidenceUploadModal();
          return false;
        }

        return true;
      });
    });
  </script>

</body>
</html>