<?php
// File: admin/settings.php
require_once __DIR__ . '/../database/database.php';
require_admin();

$activeSidebar = 'settings';

// ─── Helpers ──────────────────────────────────────────────────────────────────

function scanner_hash_value(string $rawValue): string {
    $pepper     = 'IDENTITRACK_SCANNER_PEPPER_V1_CHANGE_ME';
    $normalized = strtoupper(trim($rawValue));
    return hash('sha256', $pepper . ':' . $normalized);
}

function is_valid_email(string $email): bool {
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

function normalize_phone(string $phone): string {
    return (string) preg_replace('/[^0-9+]/', '', $phone);
}

function is_valid_phone(string $phone): bool {
    return (bool) preg_match('/^\+?[0-9]{7,15}$/', $phone);
}

function get_pending_nfc_registration(): ?array {
    if (!isset($_SESSION['nfc_pending_registration']) || !is_array($_SESSION['nfc_pending_registration'])) {
        return null;
    }
    return $_SESSION['nfc_pending_registration'];
}

function set_pending_nfc_registration(array $data): void {
    $_SESSION['nfc_pending_registration'] = $data;
}

function clear_pending_nfc_registration(): void {
    unset($_SESSION['nfc_pending_registration']);
}

// ─── AJAX: Assign NFC scan value ──────────────────────────────────────────────

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' &&
    (string) ($_POST['action'] ?? '') === 'assign_nfc'
) {
    header('Content-Type: application/json; charset=utf-8');

    $studentId = trim((string) ($_POST['student_id'] ?? ''));
    $scanRaw   = trim((string) ($_POST['scan_value'] ?? ''));

    if ($studentId === '' || $scanRaw === '') {
        echo json_encode(['ok' => false, 'message' => 'Missing student ID or scan value.']);
        exit;
    }

    $scanHash = scanner_hash_value($scanRaw);

    // ─── Check if this is a pending registration (student not yet saved to DB)
    $pending = get_pending_nfc_registration();
    $isPending = ($pending !== null && ($pending['student_id'] ?? '') === $studentId);

    // Only require DB existence if NOT pending
    if (!$isPending) {
        $student = db_one(
            "SELECT student_id FROM student WHERE student_id = :sid LIMIT 1",
            [':sid' => $studentId]
        );

        if (!$student) {
            echo json_encode(['ok' => false, 'message' => 'Student not found.']);
            exit;
        }
    }

    // Check if this NFC is already linked to another student (exclude current student)
    $owner = db_one(
        "SELECT student_id FROM student WHERE scanner_id_hash = :hash AND student_id <> :sid LIMIT 1",
        [':hash' => $scanHash, ':sid' => $studentId]
    );

    if ($owner) {
        echo json_encode([
            'ok'      => false,
            'message' => 'This NFC ID is already linked to student ' . (string) $owner['student_id'] . '.',
        ]);
        exit;
    }

    // If a pending registration exists, store the NFC hash in the session and request finalization
    if ($isPending) {
        $pending['scanner_id_hash']   = $scanHash;
        $pending['scanner_raw_value'] = $scanRaw;
        set_pending_nfc_registration($pending);

        echo json_encode([
            'ok'               => true,
            'requires_finalize' => true,
            'message'          => 'NFC ID linked. Close the modal to save the student record.',
        ]);
        exit;
    }

    // Otherwise, update the existing student record directly
    db_exec(
        "UPDATE student SET scanner_id_hash = :hash, updated_at = CURRENT_TIMESTAMP WHERE student_id = :sid",
        [':hash' => $scanHash, ':sid' => $studentId]
    );

    echo json_encode(['ok' => true, 'message' => 'NFC ID linked. Student can now use scanner login.']);
    exit;
}

// ─── AJAX: Finalize pending registration ──────────────────────────────────────

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' &&
    (string) ($_POST['action'] ?? '') === 'finalize_pending_registration'
) {
    header('Content-Type: application/json; charset=utf-8');

    $pending = get_pending_nfc_registration();
    if ($pending === null) {
        echo json_encode(['ok' => false, 'message' => 'No pending student registration found.']);
        exit;
    }

    $scanHash = trim((string) ($pending['scanner_id_hash'] ?? ''));
    if ($scanHash === '') {
        echo json_encode(['ok' => false, 'message' => 'NFC was not linked yet.']);
        exit;
    }

    try {
        db_exec(
            "INSERT INTO student
             (student_id, student_fn, student_ln, year_level, section, school, program,
              student_email, phone_number, scanner_id_hash, is_active, created_at, updated_at)
             VALUES
             (:sid, :fn, :ln, :yr, :section, :school, :program, :email, :phone, :hash,
              1, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            [
                ':sid'     => (string) $pending['student_id'],
                ':fn'      => (string) $pending['student_fn'],
                ':ln'      => (string) $pending['student_ln'],
                ':yr'      => (int)    $pending['year_level'],
                ':section' => (string) $pending['section'],
                ':school'  => (string) $pending['school'],
                ':program' => (string) $pending['program'],
                ':email'   => (string) $pending['student_email'],
                ':phone'   => (string) $pending['phone_number'],
                ':hash'    => $scanHash,
            ]
        );

        db_exec(
            "INSERT INTO guardian
             (student_id, guardian_fn, guardian_ln, guardian_email, guardian_number, created_at, updated_at)
             VALUES
             (:sid, 'Guardian', 'Account', :gemail, :gnum, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
            [
                ':sid'    => (string) $pending['student_id'],
                ':gemail' => (string) $pending['guardian_email'],
                ':gnum'   => (string) $pending['phone_number'],
            ]
        );

        clear_pending_nfc_registration();

        echo json_encode([
            'ok'         => true,
            'message'    => 'Student record saved successfully.',
            'student_id' => (string) $pending['student_id'],
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'message' => 'Unable to save student record.']);
        exit;
    }
}

// ─── AJAX: Cancel pending registration ────────────────────────────────────────

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' &&
    (string) ($_POST['action'] ?? '') === 'cancel_pending_registration'
) {
    header('Content-Type: application/json; charset=utf-8');
    clear_pending_nfc_registration();
    echo json_encode(['ok' => true, 'message' => 'Pending registration cleared.']);
    exit;
}

// ─── AJAX: Live field availability check ──────────────────────────────────────

if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' &&
    (string) ($_POST['action'] ?? '') === 'check_field_availability'
) {
    header('Content-Type: application/json; charset=utf-8');

    $field           = trim((string) ($_POST['field'] ?? ''));
    $value           = trim((string) ($_POST['value'] ?? ''));
    $excludeStudentId = trim((string) ($_POST['exclude_student_id'] ?? ''));

    if ($field === '' || $value === '') {
        echo json_encode(['ok' => false, 'message' => 'Missing field or value.']);
        exit;
    }

    if ($field === 'student_id') {
      if (!preg_match('/^[0-9\-]+$/', $value)) {
        echo json_encode(['ok' => true, 'available' => false, 'message' => 'Invalid format. Use numbers or hyphen only.']);
            exit;
        }

        $row = db_one("SELECT student_id FROM student WHERE student_id = :v LIMIT 1", [':v' => $value]);
        if ($row && ($excludeStudentId === '' || strcasecmp((string) $row['student_id'], $excludeStudentId) !== 0)) {
            echo json_encode(['ok' => true, 'available' => false, 'message' => 'Student ID is already in use.']);
            exit;
        }
        echo json_encode(['ok' => true, 'available' => true, 'message' => 'Student ID is available.']);
        exit;
    }

    if ($field === 'student_email') {
        if (!is_valid_email($value)) {
            echo json_encode(['ok' => true, 'available' => false, 'message' => 'Invalid email format.']);
            exit;
        }

        $row = db_one("SELECT student_id FROM student WHERE student_email = :v LIMIT 1", [':v' => $value]);
        if ($row && ($excludeStudentId === '' || strcasecmp((string) $row['student_id'], $excludeStudentId) !== 0)) {
            echo json_encode(['ok' => true, 'available' => false, 'message' => 'Student email is already in use.']);
            exit;
        }
        echo json_encode(['ok' => true, 'available' => true, 'message' => 'Student email is available.']);
        exit;
    }

    if ($field === 'guardian_email') {
        if (!is_valid_email($value)) {
            echo json_encode(['ok' => true, 'available' => false, 'message' => 'Invalid email format.']);
            exit;
        }

        $row = db_one("SELECT student_id FROM guardian WHERE guardian_email = :v LIMIT 1", [':v' => $value]);
        if ($row && ($excludeStudentId === '' || strcasecmp((string) $row['student_id'], $excludeStudentId) !== 0)) {
            echo json_encode(['ok' => true, 'available' => false, 'message' => 'Guardian email is already in use.']);
            exit;
        }
        echo json_encode(['ok' => true, 'available' => true, 'message' => 'Guardian email is available.']);
        exit;
    }

    if ($field === 'nfc_scan') {
        if ($value === '') {
            echo json_encode(['ok' => true, 'available' => false, 'message' => 'NFC value is required.']);
            exit;
        }

        $scanHash = scanner_hash_value($value);
        $row      = db_one("SELECT student_id FROM student WHERE scanner_id_hash = :hash LIMIT 1", [':hash' => $scanHash]);

        if ($row && ($excludeStudentId === '' || strcasecmp((string) $row['student_id'], $excludeStudentId) !== 0)) {
            echo json_encode(['ok' => true, 'available' => false, 'message' => 'NFC already linked to student ' . (string) $row['student_id'] . '.']);
            exit;
        }

        echo json_encode(['ok' => true, 'available' => true, 'message' => 'NFC is available.']);
        exit;
    }

    echo json_encode(['ok' => false, 'message' => 'Unsupported field.']);
    exit;
}

// ─── AJAX: Get courses and sections ───────────────────────────────────────────

if (
    $_SERVER['REQUEST_METHOD'] === 'GET' &&
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' &&
    (string) ($_GET['action'] ?? '') === 'get_courses_sections'
) {
    header('Content-Type: application/json; charset=utf-8');

    $school         = trim((string) ($_GET['school'] ?? ''));
    $selectedCourse = trim((string) ($_GET['course'] ?? ''));
  $schoolKey      = (strcasecmp($school, 'shs') === 0) ? 'SHS' : 'COLLEGE';

  $pendingCourses  = isset($_SESSION['pending_courses']) && is_array($_SESSION['pending_courses']) ? $_SESSION['pending_courses'] : [];
  $pendingSections = isset($_SESSION['pending_sections']) && is_array($_SESSION['pending_sections']) ? $_SESSION['pending_sections'] : [];
  $deletedSections = isset($_SESSION['deleted_sections']) && is_array($_SESSION['deleted_sections']) ? $_SESSION['deleted_sections'] : [];

    if ($school === 'college' || $school === 'College') {
        $courses = db_all(
            "SELECT DISTINCT program FROM student WHERE school = 'College' AND program IS NOT NULL AND program <> '' ORDER BY program"
        );
    } elseif ($school === 'shs' || $school === 'SHS') {
        $courses = db_all(
            "SELECT DISTINCT program FROM student WHERE school = 'Senior High School' AND program IS NOT NULL AND program <> '' ORDER BY program"
        );
    } else {
        $courses = [];
    }

    $sections = [];
    if ($selectedCourse !== '') {
        if ($schoolKey === 'SHS') {
            $sections = db_all(
                "SELECT DISTINCT section FROM student
                 WHERE program = :course
                   AND (school = 'Senior High School' OR UPPER(COALESCE(school,'')) = 'SHS')
                   AND section IS NOT NULL AND section <> ''
                 ORDER BY section",
                [':course' => $selectedCourse]
            );
        } else {
            $sections = db_all(
                "SELECT DISTINCT section FROM student
                 WHERE program = :course
                   AND school = 'College'
                   AND section IS NOT NULL AND section <> ''
                 ORDER BY section",
                [':course' => $selectedCourse]
            );
        }
    }

    $courseList = array_map(fn($c) => trim((string) ($c['program'] ?? '')), $courses);
    foreach ($pendingCourses as $pc) {
      $pc = trim((string) $pc);
      if ($pc === '') {
        continue;
      }

      $parts = explode('|', $pc, 2);
      if (count($parts) === 2) {
        $entrySchool = strtoupper(trim((string) $parts[0]));
        $entryCourse = trim((string) $parts[1]);
        if ($entrySchool === $schoolKey && $entryCourse !== '') {
          $courseList[] = $entryCourse;
        }
      } else {
        // Backward compatibility for old unsuffixed session entries.
        $courseList[] = $pc;
      }
    }
    $courseList = array_values(array_unique($courseList));
    natcasesort($courseList);
    $courseList = array_values($courseList);

    $sectionList = array_map(fn($s) => trim((string) ($s['section'] ?? '')), $sections);
    foreach ($pendingSections as $pair) {
      $pair = (string) $pair;
      $parts = explode('|', $pair);

      if (count($parts) === 3) {
        $entrySchool = strtoupper(trim((string) $parts[0]));
        $courseFromPair = trim((string) $parts[1]);
        $sectionFromPair = trim((string) $parts[2]);
        if ($entrySchool === $schoolKey && strcasecmp($courseFromPair, $selectedCourse) === 0 && $sectionFromPair !== '') {
          $sectionList[] = $sectionFromPair;
        }
      } elseif (count($parts) === 2) {
        // Backward compatibility for old unsuffixed session entries.
        $courseFromPair = trim((string) $parts[0]);
        $sectionFromPair = trim((string) $parts[1]);
        if (strcasecmp($courseFromPair, $selectedCourse) === 0 && $sectionFromPair !== '') {
          $sectionList[] = $sectionFromPair;
        }
      }
    }

    $sectionList = array_values(array_unique(array_filter($sectionList, fn($s) => $s !== '')));

    if ($selectedCourse !== '' && !empty($deletedSections)) {
      $sectionList = array_values(array_filter($sectionList, function ($sectionName) use ($selectedCourse, $deletedSections, $schoolKey) {
        foreach ($deletedSections as $pair) {
          $parts = explode('|', (string) $pair);
          if (count($parts) === 3) {
            $entrySchool = strtoupper(trim((string) $parts[0]));
            $entryCourse = trim((string) $parts[1]);
            $entrySection = trim((string) $parts[2]);
            if ($entrySchool !== $schoolKey) {
              continue;
            }
            if (strcasecmp($entryCourse, $selectedCourse) === 0 && strcasecmp($entrySection, (string) $sectionName) === 0) {
              return false;
            }
          } elseif (count($parts) === 2) {
            // Backward compatibility for old unsuffixed session entries.
            if (strcasecmp(trim($parts[0]), $selectedCourse) === 0 && strcasecmp(trim($parts[1]), (string) $sectionName) === 0) {
              return false;
            }
          }
        }
        return true;
      }));
    }

    natcasesort($sectionList);
    $sectionList = array_values($sectionList);

    echo json_encode([
        'ok'       => true,
      'courses'  => $courseList,
      'sections' => $sectionList,
    ]);
    exit;
}

// AJAX: Add a new course
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' &&
    (string) ($_POST['action'] ?? '') === 'add_course'
) {
    header('Content-Type: application/json; charset=utf-8');

    $courseName = trim((string) ($_POST['course_name'] ?? ''));
    $school     = trim((string) ($_POST['school'] ?? 'college'));
  $schoolKey  = (strcasecmp($school, 'shs') === 0) ? 'SHS' : 'COLLEGE';

    if ($courseName === '') {
        echo json_encode(['ok' => false, 'message' => 'Course name is required.']);
        exit;
    }

    if (strlen($courseName) > 100) {
        echo json_encode(['ok' => false, 'message' => 'Course name is too long.']);
        exit;
    }

    if ($schoolKey === 'SHS') {
      $existingCourse = db_one(
        "SELECT program FROM student
         WHERE program = :program
           AND (school = 'Senior High School' OR UPPER(COALESCE(school,'')) = 'SHS')
         LIMIT 1",
        [':program' => $courseName]
      );
    } else {
      $existingCourse = db_one(
        "SELECT program FROM student
         WHERE program = :program
           AND school = 'College'
         LIMIT 1",
        [':program' => $courseName]
      );
    }

    if ($existingCourse) {
      echo json_encode(['ok' => true, 'message' => 'Course already exists.', 'course' => $courseName]);
      exit;
    }

    $_SESSION['pending_courses'] = $_SESSION['pending_courses'] ?? [];
    $alreadyPending = false;
    $courseKey = $schoolKey . '|' . $courseName;
    foreach ($_SESSION['pending_courses'] as $existingPendingCourse) {
      if (strcasecmp((string) $existingPendingCourse, $courseKey) === 0) {
        $alreadyPending = true;
        break;
      }
    }
    if (!$alreadyPending) {
        $_SESSION['pending_courses'][] = $courseKey;
    }

    echo json_encode(['ok' => true, 'message' => 'Course created successfully.', 'course' => $courseName]);
    exit;
}

// AJAX: Add a new section to a course
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' &&
    (string) ($_POST['action'] ?? '') === 'add_section'
) {
    header('Content-Type: application/json; charset=utf-8');

    $sectionName = trim((string) ($_POST['section_name'] ?? ''));
    $courseName  = trim((string) ($_POST['course_name'] ?? ''));
  $school      = trim((string) ($_POST['school'] ?? 'college'));
  $schoolKey   = (strcasecmp($school, 'shs') === 0) ? 'SHS' : 'COLLEGE';

    if ($sectionName === '' || $courseName === '') {
        echo json_encode(['ok' => false, 'message' => 'Section name and course are required.']);
        exit;
    }

    if (strlen($sectionName) > 50) {
        echo json_encode(['ok' => false, 'message' => 'Section name is too long.']);
        exit;
    }

    if ($schoolKey === 'SHS') {
      $existingSection = db_one(
        "SELECT section FROM student
         WHERE program = :course
           AND section = :section
           AND (school = 'Senior High School' OR UPPER(COALESCE(school,'')) = 'SHS')
         LIMIT 1",
        [':course' => $courseName, ':section' => $sectionName]
      );
    } else {
      $existingSection = db_one(
        "SELECT section FROM student
         WHERE program = :course
           AND section = :section
           AND school = 'College'
         LIMIT 1",
        [':course' => $courseName, ':section' => $sectionName]
      );
    }

    if ($existingSection) {
      $existingKey = $schoolKey . '|' . $courseName . '|' . $sectionName;
      if (isset($_SESSION['deleted_sections']) && is_array($_SESSION['deleted_sections'])) {
        $_SESSION['deleted_sections'] = array_values(array_filter(
          $_SESSION['deleted_sections'],
          fn($deletedPair) => strcasecmp((string) $deletedPair, $existingKey) !== 0
        ));
      }
      echo json_encode(['ok' => true, 'message' => 'Section already exists.', 'section' => $sectionName]);
      exit;
    }

    $_SESSION['pending_sections'] = $_SESSION['pending_sections'] ?? [];
    $key                          = $schoolKey . '|' . $courseName . '|' . $sectionName;
    $alreadyPending = false;
    foreach ($_SESSION['pending_sections'] as $existingPair) {
      if (strcasecmp((string) $existingPair, $key) === 0) {
        $alreadyPending = true;
        break;
      }
    }

    if (!$alreadyPending) {
        $_SESSION['pending_sections'][] = $key;
    }

    if (isset($_SESSION['deleted_sections']) && is_array($_SESSION['deleted_sections'])) {
      $_SESSION['deleted_sections'] = array_values(array_filter(
        $_SESSION['deleted_sections'],
        fn($deletedPair) => strcasecmp((string) $deletedPair, $key) !== 0
      ));
    }

    echo json_encode(['ok' => true, 'message' => 'Section created successfully.', 'section' => $sectionName]);
    exit;
}

  // AJAX: Delete a section from a course (for dropdown management)
  if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_SERVER['HTTP_X_REQUESTED_WITH']) &&
    strtolower((string) $_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest' &&
    (string) ($_POST['action'] ?? '') === 'delete_section'
  ) {
    header('Content-Type: application/json; charset=utf-8');

    $sectionName = trim((string) ($_POST['section_name'] ?? ''));
    $courseName  = trim((string) ($_POST['course_name'] ?? ''));
    $school      = trim((string) ($_POST['school'] ?? 'college'));
    $schoolKey   = (strcasecmp($school, 'shs') === 0) ? 'SHS' : 'COLLEGE';

    if ($sectionName === '' || $courseName === '') {
      echo json_encode(['ok' => false, 'message' => 'Section and course are required.']);
      exit;
    }

    $pairKey = $schoolKey . '|' . $courseName . '|' . $sectionName;

    $_SESSION['pending_sections'] = isset($_SESSION['pending_sections']) && is_array($_SESSION['pending_sections'])
      ? array_values(array_filter(
        $_SESSION['pending_sections'],
        fn($existingPair) => strcasecmp((string) $existingPair, $pairKey) !== 0
      ))
      : [];

    $_SESSION['deleted_sections'] = $_SESSION['deleted_sections'] ?? [];
    $alreadyDeleted = false;
    foreach ($_SESSION['deleted_sections'] as $existingDeletedPair) {
      if (strcasecmp((string) $existingDeletedPair, $pairKey) === 0) {
        $alreadyDeleted = true;
        break;
      }
    }
    if (!$alreadyDeleted) {
      $_SESSION['deleted_sections'][] = $pairKey;
    }

    echo json_encode(['ok' => true, 'message' => 'Section removed from course list.']);
    exit;
  }

// ─── Form defaults ────────────────────────────────────────────────────────────

$form = [
    'student_id'          => trim((string) ($_POST['student_id'] ?? '')),
    'student_fn'          => trim((string) ($_POST['student_fn'] ?? '')),
    'student_ln'          => trim((string) ($_POST['student_ln'] ?? '')),
    'academic_group'      => trim((string) ($_POST['academic_group'] ?? 'COLLEGE')),
    'year_level'          => trim((string) ($_POST['year_level'] ?? '')),
    'section'             => trim((string) ($_POST['section'] ?? '')),
    'college_department'  => trim((string) ($_POST['college_department'] ?? '')),
    'shs_track'           => trim((string) ($_POST['shs_track'] ?? '')),
    'student_email'       => trim((string) ($_POST['student_email'] ?? '')),
    'guardian_email'      => trim((string) ($_POST['guardian_email'] ?? '')),
    'phone_number'        => trim((string) ($_POST['phone_number'] ?? '')),
];

if (!in_array($form['academic_group'], ['SHS', 'COLLEGE'], true)) {
    $form['academic_group'] = 'COLLEGE';
}

$errors = [];

// ─── Edit mode: pre-fill form ─────────────────────────────────────────────────

$editMode      = false;
$editStudentId = trim((string) ($_GET['edit_student_id'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $editStudentId !== '') {
    $row = db_one(
        "SELECT s.student_id, s.student_fn, s.student_ln, s.year_level, s.section,
                s.school, s.program, s.student_email, s.phone_number, g.guardian_email
         FROM student s
         LEFT JOIN guardian g ON g.student_id = s.student_id
         WHERE s.student_id = :sid
         LIMIT 1",
        [':sid' => $editStudentId]
    );

    if ($row) {
        $isShs              = stripos((string) ($row['school'] ?? ''), 'senior high') !== false;
        $form['student_id']         = (string) ($row['student_id'] ?? '');
        $form['student_fn']         = (string) ($row['student_fn'] ?? '');
        $form['student_ln']         = (string) ($row['student_ln'] ?? '');
        $form['academic_group']     = $isShs ? 'SHS' : 'COLLEGE';
        $form['year_level']         = (string) ($row['year_level'] ?? '');
        $form['section']            = (string) ($row['section'] ?? '');
        $form['college_department'] = $isShs ? '' : (string) ($row['program'] ?? '');
        $form['shs_track']          = $isShs ? (string) ($row['program'] ?? '') : '';
        $form['student_email']      = (string) ($row['student_email'] ?? '');
        $form['guardian_email']     = (string) ($row['guardian_email'] ?? '');
        $form['phone_number']       = (string) ($row['phone_number'] ?? '');
        $editMode = true;
    }
}

// ─── POST: Register new student ───────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'register_student') {

    $form['student_id']    = preg_replace('/\s+/', '', $form['student_id']);
    $form['phone_number']  = normalize_phone($form['phone_number']);

    // Validation
    if ($form['student_id'] === '')                                           $errors[] = 'Student ID is required.';
    if ($form['student_id'] !== '' && !preg_match('/^[0-9\-]+$/', $form['student_id']))
                                          $errors[] = 'Student ID must contain only numbers or hyphens.';
    if ($form['student_fn'] === '')                                           $errors[] = 'First name is required.';
    if ($form['student_ln'] === '')                                           $errors[] = 'Last name is required.';
    if ($form['year_level'] === '' || !ctype_digit($form['year_level']))      $errors[] = 'Year level is required.';
    if ($form['section'] === '')                                              $errors[] = 'Section is required.';
    if ($form['student_email'] === '')                                        $errors[] = 'Student email is required.';
    if ($form['guardian_email'] === '')                                       $errors[] = 'Guardian email is required.';
    if ($form['phone_number'] === '')                                         $errors[] = 'Phone number is required.';

    if ($form['student_email'] !== '' && !is_valid_email($form['student_email']))   $errors[] = 'Student email format is invalid.';
    if ($form['guardian_email'] !== '' && !is_valid_email($form['guardian_email'])) $errors[] = 'Guardian email format is invalid.';
    if ($form['phone_number'] !== '' && !is_valid_phone($form['phone_number']))     $errors[] = 'Phone number must be 7–15 digits (optional + prefix).';

    if ($form['year_level'] !== '' && ctype_digit($form['year_level'])) {
        $yr = (int) $form['year_level'];
        if ($yr < 1 || $yr > 11) $errors[] = 'Year level must be between 1 and 11.';
    }

    if ($form['academic_group'] === 'SHS') {
        if ($form['shs_track'] === '') $errors[] = 'SHS track is required.';
    } else {
        if ($form['college_department'] === '') $errors[] = 'College course/department is required.';
    }

    // Uniqueness checks
    if ($form['student_id'] !== '') {
        if (db_one("SELECT student_id FROM student WHERE student_id = :sid LIMIT 1", [':sid' => $form['student_id']])) {
            $errors[] = 'Student ID is already registered.';
        }
    }

    if ($form['student_email'] !== '') {
        if (db_one("SELECT student_id FROM student WHERE student_email = :email LIMIT 1", [':email' => $form['student_email']])) {
            $errors[] = 'Student email is already registered.';
        }
    }

    if ($form['guardian_email'] !== '') {
        if (db_one("SELECT guardian_id FROM guardian WHERE guardian_email = :email LIMIT 1", [':email' => $form['guardian_email']])) {
            $errors[] = 'Guardian email is already registered.';
        }
    }

    if (empty($errors)) {
        $school  = ($form['academic_group'] === 'SHS') ? 'Senior High School' : 'College';
        $program = ($form['academic_group'] === 'SHS') ? $form['shs_track'] : $form['college_department'];

        set_pending_nfc_registration([
            'student_id'      => $form['student_id'],
            'student_fn'      => $form['student_fn'],
            'student_ln'      => $form['student_ln'],
            'year_level'      => (int) $form['year_level'],
            'section'         => $form['section'],
            'school'          => $school,
            'program'         => $program,
            'student_email'   => $form['student_email'],
            'guardian_email'  => $form['guardian_email'],
            'phone_number'    => $form['phone_number'],
            'scanner_id_hash' => '',
        ]);

        redirect('settings.php?pending_nfc=1&student_id=' . urlencode($form['student_id']));
    }
}

// ─── POST: Update existing student ────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'update_student') {

    $targetStudentId = trim((string) ($_POST['original_student_id'] ?? $_POST['student_id'] ?? ''));
    $form['student_id']   = $targetStudentId;
    $form['phone_number'] = normalize_phone($form['phone_number']);

    // Validation
    if ($targetStudentId === '')                                              $errors[] = 'Student ID is required.';
    if ($form['student_fn'] === '')                                           $errors[] = 'First name is required.';
    if ($form['student_ln'] === '')                                           $errors[] = 'Last name is required.';
    if ($form['year_level'] === '' || !ctype_digit($form['year_level']))      $errors[] = 'Year level is required.';
    if ($form['section'] === '')                                              $errors[] = 'Section is required.';
    if ($form['student_email'] === '')                                        $errors[] = 'Student email is required.';
    if ($form['guardian_email'] === '')                                       $errors[] = 'Guardian email is required.';
    if ($form['phone_number'] === '')                                         $errors[] = 'Phone number is required.';

    if ($form['student_email'] !== '' && !is_valid_email($form['student_email']))   $errors[] = 'Student email format is invalid.';
    if ($form['guardian_email'] !== '' && !is_valid_email($form['guardian_email'])) $errors[] = 'Guardian email format is invalid.';
    if ($form['phone_number'] !== '' && !is_valid_phone($form['phone_number']))     $errors[] = 'Phone number must be 7–15 digits (optional + prefix).';

    if ($form['year_level'] !== '' && ctype_digit($form['year_level'])) {
        $yr = (int) $form['year_level'];
        if ($yr < 1 || $yr > 11) $errors[] = 'Year level must be between 1 and 11.';
    }

    if ($form['academic_group'] === 'SHS') {
        if ($form['shs_track'] === '') $errors[] = 'SHS track is required.';
    } else {
        if ($form['college_department'] === '') $errors[] = 'College course/department is required.';
    }

    if (!db_one("SELECT student_id FROM student WHERE student_id = :sid LIMIT 1", [':sid' => $targetStudentId])) {
        $errors[] = 'Student not found.';
    }

    if ($form['student_email'] !== '') {
        if (db_one("SELECT student_id FROM student WHERE student_email = :email AND student_id <> :sid LIMIT 1",
                   [':email' => $form['student_email'], ':sid' => $targetStudentId])) {
            $errors[] = 'Student email is already registered to another student.';
        }
    }

    if ($form['guardian_email'] !== '') {
        if (db_one("SELECT guardian_id FROM guardian WHERE guardian_email = :email AND student_id <> :sid LIMIT 1",
                   [':email' => $form['guardian_email'], ':sid' => $targetStudentId])) {
            $errors[] = 'Guardian email is already registered to another student.';
        }
    }

    if (empty($errors)) {
        $school  = ($form['academic_group'] === 'SHS') ? 'Senior High School' : 'College';
        $program = ($form['academic_group'] === 'SHS') ? $form['shs_track'] : $form['college_department'];

        db_exec(
            "UPDATE student
             SET student_fn = :fn, student_ln = :ln, year_level = :yr, section = :section,
                 school = :school, program = :program, student_email = :email,
                 phone_number = :phone, updated_at = CURRENT_TIMESTAMP
             WHERE student_id = :sid",
            [
                ':fn'      => $form['student_fn'],
                ':ln'      => $form['student_ln'],
                ':yr'      => (int) $form['year_level'],
                ':section' => $form['section'],
                ':school'  => $school,
                ':program' => $program,
                ':email'   => $form['student_email'],
                ':phone'   => $form['phone_number'],
                ':sid'     => $targetStudentId,
            ]
        );

        $existingGuardian = db_one("SELECT guardian_id FROM guardian WHERE student_id = :sid LIMIT 1", [':sid' => $targetStudentId]);

        if ($existingGuardian) {
            db_exec(
                "UPDATE guardian
                 SET guardian_email = :gemail, guardian_number = :gnum, updated_at = CURRENT_TIMESTAMP
                 WHERE student_id = :sid",
                [':gemail' => $form['guardian_email'], ':gnum' => $form['phone_number'], ':sid' => $targetStudentId]
            );
        } else {
            db_exec(
                "INSERT INTO guardian
                 (student_id, guardian_fn, guardian_ln, guardian_email, guardian_number, created_at, updated_at)
                 VALUES (:sid, 'Guardian', 'Account', :gemail, :gnum, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
                [':sid' => $targetStudentId, ':gemail' => $form['guardian_email'], ':gnum' => $form['phone_number']]
            );
        }

        redirect('settings.php?updated=1&student_id=' . urlencode($targetStudentId) . '&edit_student_id=' . urlencode($targetStudentId));
    }
}

// ─── Page state ───────────────────────────────────────────────────────────────

$pendingNfcRegistration = get_pending_nfc_registration();

$registeredMode    = ((int) ($_GET['registered'] ?? 0) === 1);
$registeredStudentId = trim((string) ($_GET['student_id'] ?? ''));
$pendingNfcMode    = ((int) ($_GET['pending_nfc'] ?? 0) === 1) || $pendingNfcRegistration !== null;
$pendingNfcStudentId = trim((string) ($pendingNfcRegistration['student_id'] ?? ($_GET['student_id'] ?? '')));
$updatedMode       = ((int) ($_GET['updated'] ?? 0) === 1);
$updatedStudentId  = trim((string) ($_GET['student_id'] ?? ''));

// ─── NFC mapping table ────────────────────────────────────────────────────────

$nfcQuery    = trim((string) ($_GET['nfc_q'] ?? ''));
$queryParams = $nfcQuery !== '' ? [':q' => '%' . $nfcQuery . '%'] : [];
$whereClause = $nfcQuery !== ''
    ? "AND (s.student_id LIKE :q OR s.student_fn LIKE :q OR s.student_ln LIKE :q OR s.program LIKE :q OR s.section LIKE :q)"
    : '';

$nfcMappings = db_all(
    "SELECT s.student_id, s.student_fn, s.student_ln, s.school, s.program, s.year_level,
            s.section, s.student_email, g.guardian_email, s.updated_at
     FROM student s
     LEFT JOIN guardian g ON g.student_id = s.student_id
     WHERE s.scanner_id_hash IS NOT NULL
     $whereClause
     ORDER BY s.updated_at DESC
     LIMIT 200",
    $queryParams
) ?: [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>NFC Registration | SDO Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <style>
    /* ── Reset & Base ─────────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    :root {
      --font:        'DM Sans', system-ui, sans-serif;
      --font-mono:   'DM Mono', monospace;
      --radius-sm:   6px;
      --radius-md:   10px;
      --radius-lg:   14px;
      --radius-xl:   20px;
      /* Palette */
      --blue-50:     #EFF6FF;
      --blue-100:    #DBEAFE;
      --blue-200:    #BFDBFE;
      --blue-500:    #3B82F6;
      --blue-600:    #2563EB;
      --blue-700:    #1D4ED8;
      --blue-800:    #1E40AF;
      --blue-900:    #1E3A8A;
      --slate-50:    #F8FAFC;
      --slate-100:   #F1F5F9;
      --slate-200:   #E2E8F0;
      --slate-300:   #CBD5E1;
      --slate-400:   #94A3B8;
      --slate-500:   #64748B;
      --slate-600:   #475569;
      --slate-700:   #334155;
      --slate-800:   #1E293B;
      --slate-900:   #0F172A;
      --green-50:    #F0FDF4;
      --green-100:   #DCFCE7;
      --green-600:   #16A34A;
      --green-700:   #15803D;
      --red-50:      #FFF1F2;
      --red-100:     #FFE4E6;
      --red-600:     #DC2626;
      --red-700:     #B91C1C;
      --amber-50:    #FFFBEB;
      --amber-200:   #FDE68A;
      --amber-700:   #B45309;
    }

    body {
      font-family: var(--font);
      background: var(--slate-100);
      color: var(--slate-900);
      font-size: 14px;
      line-height: 1.5;
      -webkit-font-smoothing: antialiased;
    }

    /* ── Layout ───────────────────────────────────────────────── */
    .admin-shell {
      min-height: calc(100vh - 64px);
      display: grid;
      grid-template-columns: 240px 1fr;
    }

    .main-wrap { min-height: 100%; }

    .page {
      max-width: 1020px;
      margin: 28px auto;
      padding: 0 20px;
      display: flex;
      flex-direction: column;
      gap: 20px;
    }

    /* ── Cards ────────────────────────────────────────────────── */
    .card {
      background: #fff;
      border: 1px solid var(--slate-200);
      border-radius: var(--radius-xl);
      box-shadow: 0 1px 3px rgba(15,23,42,.06), 0 4px 16px rgba(15,23,42,.04);
      overflow: hidden;
    }

    .card-header {
      padding: 20px 24px;
      border-bottom: 1px solid var(--slate-100);
      display: flex;
      align-items: flex-start;
      gap: 14px;
    }

    .card-icon {
      width: 40px;
      height: 40px;
      border-radius: var(--radius-md);
      background: var(--blue-700);
      color: #fff;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 18px;
      flex-shrink: 0;
    }

    .card-icon svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

    .card-title { font-size: 16px; font-weight: 700; color: var(--slate-900); letter-spacing: -.2px; }
    .card-sub   { font-size: 12px; color: var(--slate-500); margin-top: 3px; line-height: 1.4; }

    .card-body  { padding: 24px; }

    /* ── Form Grid ─────────────────────────────────────────────── */
    .form-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 18px;
    }

    .form-grid .full { grid-column: 1 / -1; }

    .field-group { display: flex; flex-direction: column; gap: 6px; }

    label {
      font-size: 11.5px;
      font-weight: 600;
      text-transform: uppercase;
      letter-spacing: .6px;
      color: var(--slate-600);
    }

    input, select {
      width: 100%;
      height: 40px;
      padding: 0 12px;
      border: 1.5px solid var(--slate-200);
      border-radius: var(--radius-md);
      font-family: var(--font);
      font-size: 14px;
      color: var(--slate-900);
      background: #fff;
      transition: border-color .15s, box-shadow .15s;
      outline: none;
      appearance: none;
      -webkit-appearance: none;
    }

    select {
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%2364748b' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 12px center;
      padding-right: 32px;
    }

    input:focus, select:focus {
      border-color: var(--blue-500);
      box-shadow: 0 0 0 3px rgba(59,130,246,.12);
    }

    input[readonly], input.field-locked, input:disabled {
      background: var(--slate-100);
      border-color: var(--slate-200);
      color: var(--slate-500);
      cursor: not-allowed;
    }

    input.field-error { border-color: var(--red-600); box-shadow: 0 0 0 3px rgba(220,38,38,.1); }

    .field-hint   { font-size: 11.5px; color: var(--slate-400); }
    .field-status { font-size: 11.5px; font-weight: 600; display: none; align-items: center; gap: 4px; }
    .field-status.visible  { display: flex; }
    .field-status.loading  { color: var(--slate-500); }
    .field-status.ok       { color: var(--green-600); }
    .field-status.used     { color: var(--red-600); }

    /* ── Alerts ────────────────────────────────────────────────── */
    .alert {
      display: flex;
      gap: 12px;
      padding: 14px 16px;
      border-radius: var(--radius-md);
      font-size: 13px;
      margin-bottom: 20px;
    }

    .alert-error {
      background: var(--red-50);
      border: 1px solid #FECACA;
      color: var(--red-700);
    }

    .alert-success {
      background: var(--green-50);
      border: 1px solid #BBF7D0;
      color: var(--green-700);
    }

    .alert-icon { flex-shrink: 0; width: 18px; height: 18px; margin-top: 1px; }
    .alert ul { padding-left: 16px; margin: 0; }
    .alert ul li + li { margin-top: 3px; }

    /* ── Buttons ───────────────────────────────────────────────── */
    .btn-row { display: flex; gap: 10px; align-items: center; margin-top: 22px; flex-wrap: wrap; }

    .btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      height: 40px;
      padding: 0 18px;
      border-radius: var(--radius-md);
      font-family: var(--font);
      font-size: 13.5px;
      font-weight: 600;
      cursor: pointer;
      transition: background .15s, border-color .15s, transform .1s, box-shadow .15s;
      text-decoration: none;
      border: 1.5px solid transparent;
      white-space: nowrap;
    }

    .btn:active { transform: scale(.98); }

    .btn-primary {
      background: var(--blue-700);
      border-color: var(--blue-700);
      color: #fff;
      box-shadow: 0 1px 4px rgba(29,78,216,.25);
    }

    .btn-primary:hover { background: var(--blue-800); border-color: var(--blue-800); }

    .btn-secondary {
      background: #fff;
      border-color: var(--slate-200);
      color: var(--slate-700);
    }

    .btn-secondary:hover { background: var(--slate-50); border-color: var(--slate-300); }

    .btn:disabled, .btn[disabled] {
      background: var(--slate-200) !important;
      border-color: var(--slate-200) !important;
      color: var(--slate-400) !important;
      cursor: not-allowed;
      box-shadow: none;
      transform: none;
    }

    .btn svg { width: 15px; height: 15px; flex-shrink: 0; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

    /* ── Scanner box ───────────────────────────────────────────── */
    .scanner-box {
      margin-top: 24px;
      padding: 20px;
      background: var(--blue-50);
      border: 1.5px solid var(--blue-200);
      border-radius: var(--radius-lg);
    }

    .scanner-box-title {
      font-size: 13.5px;
      font-weight: 700;
      color: var(--blue-800);
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .scanner-box-title svg { width: 16px; height: 16px; fill: none; stroke: var(--blue-700); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }

    /* ── Inline status ─────────────────────────────────────────── */
    .status-block {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 12px 14px;
      border-radius: var(--radius-md);
      font-size: 13px;
      font-weight: 500;
    }

    .status-block.ok  { background: var(--green-50); border: 1px solid #BBF7D0; color: var(--green-700); }
    .status-block.err { background: var(--red-50); border: 1px solid #FECACA; color: var(--red-700); }

    /* ── NFC Pending Overlay ───────────────────────────────────── */
    .overlay-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(15,23,42,.5);
      backdrop-filter: blur(4px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1800;
      padding: 20px;
      animation: fadeIn .2s ease;
    }

    .overlay-card {
      width: min(680px, 100%);
      background: #fff;
      border-radius: var(--radius-xl);
      box-shadow: 0 20px 60px rgba(15,23,42,.2);
      overflow: hidden;
    }

    .overlay-header {
      padding: 20px 24px;
      background: linear-gradient(135deg, var(--blue-700) 0%, var(--blue-800) 100%);
      color: #fff;
      display: flex;
      align-items: center;
      gap: 14px;
    }

    .overlay-badge {
      width: 48px;
      height: 48px;
      border-radius: var(--radius-md);
      background: rgba(255,255,255,.15);
      border: 1.5px solid rgba(255,255,255,.25);
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 11px;
      font-weight: 800;
      letter-spacing: .5px;
      color: #fff;
      flex-shrink: 0;
    }

    .overlay-title { font-size: 17px; font-weight: 700; }
    .overlay-sub   { font-size: 12px; opacity: .8; margin-top: 3px; line-height: 1.4; }

    .overlay-body  { padding: 24px; }

    .overlay-note {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      padding: 12px 14px;
      background: var(--amber-50);
      border: 1px solid var(--amber-200);
      border-radius: var(--radius-md);
      color: var(--amber-700);
      font-size: 12.5px;
      line-height: 1.5;
      margin-bottom: 20px;
    }

    .overlay-note strong { color: var(--slate-900); }

    .overlay-actions { display: flex; gap: 10px; margin-top: 20px; flex-wrap: wrap; }

    /* ── Modal ─────────────────────────────────────────────────── */
    .modal {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(15,23,42,.5);
      backdrop-filter: blur(3px);
      align-items: center;
      justify-content: center;
      z-index: 2000;
      padding: 20px;
    }

    .modal.active { display: flex; animation: fadeIn .18s ease; }

    .modal-card {
      width: min(420px, 100%);
      background: #fff;
      border-radius: var(--radius-xl);
      box-shadow: 0 20px 60px rgba(15,23,42,.22);
      overflow: hidden;
    }

    .modal-card.error { animation: shake .35s ease; }

    .modal-header {
      padding: 20px;
      display: flex;
      align-items: center;
      gap: 12px;
      border-bottom: 1px solid var(--slate-100);
    }

    .modal-indicator {
      width: 32px;
      height: 32px;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 15px;
      font-weight: 700;
      flex-shrink: 0;
      color: #fff;
    }

    .modal-indicator.success {
      background: var(--green-600);
      box-shadow: 0 0 0 0 rgba(22,163,74,.35);
      animation: pulse 1.4s infinite;
    }

    .modal-indicator.error-ind { background: var(--red-600); }

    .modal-logo { width: 36px; height: 36px; border-radius: var(--radius-sm); border: 1px solid var(--slate-200); object-fit: cover; }

    .modal-title  { font-size: 15px; font-weight: 700; color: var(--slate-900); }
    .modal-sub    { font-size: 12px; color: var(--slate-500); margin-top: 2px; }

    .modal-card.error .modal-header { background: var(--red-50); }
    .modal-card.error .modal-title  { color: var(--red-700); }

    .modal-body { padding: 20px; }

    .modal-pill {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 10px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .4px;
      text-transform: uppercase;
      margin-bottom: 10px;
    }

    .modal-pill.ok    { background: var(--green-100); color: var(--green-700); }
    .modal-pill.error { background: var(--red-100); color: var(--red-700); }

    .modal-body p { font-size: 13.5px; color: var(--slate-600); line-height: 1.6; }

    .modal-id {
      margin-top: 12px;
      padding: 10px 14px;
      background: var(--slate-50);
      border: 1px solid var(--slate-200);
      border-radius: var(--radius-md);
      font-family: var(--font-mono);
      font-size: 13px;
      font-weight: 500;
      color: var(--slate-700);
    }

    .lookup-details {
      display: none;
      margin-top: 12px;
      padding: 14px;
      border-radius: var(--radius-lg);
      border: 1px solid var(--slate-200);
      background: var(--slate-50);
      color: var(--slate-700);
      font-size: 12.5px;
      line-height: 1.6;
    }

    .lookup-details.registered {
      background: var(--green-50);
      border-color: #bbf7d0;
      color: var(--green-800);
    }

    .lookup-details.missing {
      background: var(--amber-50);
      border-color: #fde68a;
      color: #92400e;
    }

    .lookup-details .lookup-title {
      display: flex;
      align-items: center;
      gap: 8px;
      font-size: 12px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .5px;
      margin-bottom: 8px;
    }

    .lookup-details .lookup-title .dot {
      width: 10px;
      height: 10px;
      border-radius: 50%;
      flex: 0 0 auto;
    }

    .lookup-details.registered .dot { background: var(--green-600); }
    .lookup-details.missing .dot { background: var(--amber-500); }

    .lookup-details .lookup-grid {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px 14px;
    }

    .lookup-details .lookup-item {
      display: flex;
      flex-direction: column;
      gap: 2px;
    }

    .lookup-details .lookup-label {
      font-size: 10px;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: .5px;
      opacity: .8;
    }

    .lookup-details .lookup-value {
      font-size: 12.5px;
      font-weight: 600;
      word-break: break-word;
    }

    .lookup-details .lookup-empty {
      font-size: 12.5px;
      font-weight: 600;
    }

    .modal-card.error .modal-id { background: var(--red-50); border-color: #FECACA; color: var(--red-700); }

    .modal-actions { padding: 0 20px 20px; display: flex; justify-content: flex-end; gap: 10px; }

    .section-manage-list {
      display: flex;
      flex-direction: column;
      gap: 8px;
      max-height: 180px;
      overflow: auto;
      padding: 2px;
    }

    .section-manage-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
      border: 1px solid var(--slate-200);
      border-radius: var(--radius-md);
      padding: 8px 10px;
      font-size: 13px;
      background: #fff;
    }

    .section-manage-empty {
      font-size: 12px;
      color: var(--slate-500);
      border: 1px dashed var(--slate-300);
      border-radius: var(--radius-md);
      padding: 10px;
      background: var(--slate-50);
    }

    .section-delete-btn {
      border: 1px solid #fecaca;
      background: #fef2f2;
      color: #b91c1c;
      border-radius: 8px;
      font-size: 11px;
      font-weight: 700;
      padding: 4px 8px;
      cursor: pointer;
    }

    .section-delete-btn:hover {
      background: #fee2e2;
    }

    /* ── Table ─────────────────────────────────────────────────── */
    .search-row { display: flex; gap: 10px; align-items: center; margin-bottom: 16px; }
    .search-row input { max-width: 360px; }

    .table-wrap {
      border: 1px solid var(--slate-200);
      border-radius: var(--radius-lg);
      overflow: auto;
    }

    table { width: 100%; border-collapse: collapse; min-width: 980px; }

    th, td {
      padding: 11px 14px;
      text-align: left;
      font-size: 13px;
      border-bottom: 1px solid var(--slate-100);
    }

    th {
      background: var(--slate-50);
      color: var(--slate-600);
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .5px;
    }

    tr:last-child td { border-bottom: none; }
    tr:hover td      { background: var(--slate-50); }

    .badge {
      display: inline-flex;
      align-items: center;
      padding: 2px 8px;
      border-radius: 999px;
      font-size: 11px;
      font-weight: 600;
    }

    .badge-blue  { background: var(--blue-50); color: var(--blue-700); }
    .badge-slate { background: var(--slate-100); color: var(--slate-600); }

    .empty-state {
      padding: 40px;
      text-align: center;
      color: var(--slate-400);
      font-size: 13px;
    }

    /* ── Utilities ─────────────────────────────────────────────── */
    .hidden { display: none !important; }

    body.modal-open {
      overflow: hidden;
      overscroll-behavior: contain;
    }

    /* ── Animations ────────────────────────────────────────────── */
    @keyframes fadeIn  { from { opacity: 0; } to { opacity: 1; } }
    @keyframes pulse {
      0%   { box-shadow: 0 0 0 0 rgba(22,163,74,.35); }
      70%  { box-shadow: 0 0 0 10px rgba(22,163,74,0); }
      100% { box-shadow: 0 0 0 0 rgba(22,163,74,0); }
    }
    @keyframes shake {
      0%,100% { transform: translateX(0); }
      20%     { transform: translateX(-8px); }
      40%     { transform: translateX(8px); }
      60%     { transform: translateX(-6px); }
      80%     { transform: translateX(6px); }
    }

    /* ── Responsive ────────────────────────────────────────────── */
    @media (max-width: 1024px) { .admin-shell { grid-template-columns: 1fr; } }
    @media (max-width: 720px)  { .form-grid { grid-template-columns: 1fr; } .form-grid .full { grid-column: 1; } }
    @media (max-width: 540px)  { .overlay-actions { flex-direction: column; } .overlay-actions .btn { width: 100%; justify-content: center; } }
  </style>
</head>
<body class="<?php echo $pendingNfcMode ? 'modal-open' : ''; ?>">
  <?php require_once __DIR__ . '/header.php'; ?>
  <script>window.__identitrackDisableGlobalScanner = true;</script>

  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="main-wrap">
      <div class="page">

        <!-- ── Registration / Edit Card ─────────────────────────────────── -->
        <div class="card" id="editForm">
          <div class="card-header">
            <div class="card-icon">
              <?php if ($editMode): ?>
                <svg viewBox="0 0 24 24"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
              <?php else: ?>
                <svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/><line x1="20" y1="8" x2="20" y2="14"/><line x1="23" y1="11" x2="17" y2="11"/></svg>
              <?php endif; ?>
            </div>
            <div>
              <div class="card-title">
                <?php echo $editMode ? 'Update Student + NFC' : 'NFC Student Registration'; ?>
              </div>
              <div class="card-sub">
                <?php echo $editMode
                  ? 'Update student details below. Use the NFC section to re-link their card.'
                  : 'Fill in the student details first. You will be prompted to scan their NFC card/tag after saving.'; ?>
              </div>
            </div>
          </div>

          <div class="card-body">

            <!-- NFC Pending Overlay ──────────────────────────────────────── -->
            <?php if ($pendingNfcMode): ?>

              <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                  <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                  </svg>
                  <ul><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
                </div>
              <?php endif; ?>

              <div class="overlay-backdrop">
                <div class="overlay-card">
                  <div class="overlay-header">
                    <img src="../assets/logo.png" alt="IdentiTrack logo" style="width:54px;height:54px;border-radius:16px;object-fit:cover;border:1px solid rgba(255,255,255,.24);box-shadow:0 10px 24px rgba(15,23,42,.18);background:#fff;flex:0 0 auto;">
                    <div>
                      <div class="overlay-title">Finish NFC Registration</div>
                      <div class="overlay-sub">Tap an NFC card to check if it is registered, or link it to the student you are creating.</div>
                    </div>
                  </div>
                  <div class="overlay-body">
                    <div class="overlay-note">
                      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="flex-shrink:0;margin-top:1px;">
                        <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/>
                      </svg>
                      <div><strong>Nothing is saved yet.</strong> The student record is staged until the NFC tap succeeds and you confirm the modal.</div>
                    </div>

                    <input type="hidden" id="nfc_student_id" data-pending-mode="1" value="<?php echo htmlspecialchars($pendingNfcStudentId); ?>"/>

                    <div class="field-group">
                      <label for="nfc_scan_value">Scanner Input</label>
                      <input id="nfc_scan_value" placeholder="Tap NFC card/tag — value auto-fills" autocomplete="off"/>
                      <div id="nfc_scan_status" class="field-status"></div>
                      <div class="field-hint">The scanner will tell you whether the NFC is registered or not registered.</div>
                    </div>

                    <div class="overlay-actions">
                      <button id="linkBtn" class="btn btn-primary" type="button">
                        <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
                        Register NFC
                      </button>
                      <button id="cancelPendingBtn" class="btn btn-secondary" type="button">Cancel</button>
                    </div>

                    <div id="nfcStatus" class="status-block hidden" style="margin-top:14px;"></div>
                  </div>
                </div>
              </div>

            <?php elseif (!$registeredMode): ?>

              <!-- Success / Error Alerts ───────────────────────────────── -->
              <?php if ($updatedMode && $updatedStudentId !== ''): ?>
                <div class="alert alert-success">
                  <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                  </svg>
                  <div>Student info updated for <strong><?php echo htmlspecialchars($updatedStudentId); ?></strong>.</div>
                </div>
              <?php endif; ?>

              <?php if (!empty($errors)): ?>
                <div class="alert alert-error">
                  <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                  </svg>
                  <ul><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
                </div>
              <?php endif; ?>

              <!-- Student Form ─────────────────────────────────────────── -->
              <form method="post">
                <input type="hidden" name="action" value="<?php echo $editMode ? 'update_student' : 'register_student'; ?>"/>
                <?php if ($editMode): ?>
                  <input type="hidden" name="original_student_id" value="<?php echo htmlspecialchars($form['student_id']); ?>"/>
                <?php endif; ?>

                <div class="form-grid">

                  <div class="field-group">
                    <label for="student_id">Student ID</label>
                    <input id="student_id" name="student_id" value="<?php echo htmlspecialchars($form['student_id']); ?>" required inputmode="numeric" pattern="[0-9\-]+" <?php echo $editMode ? 'readonly' : ''; ?>/>
                    <div class="field-hint">Numbers and hyphen only.</div>
                    <div id="student_id_status" class="field-status"></div>
                  </div>

                  <div class="field-group">
                    <label for="academic_group">School Group</label>
                    <select id="academic_group" name="academic_group" required>
                      <option value="COLLEGE" <?php echo $form['academic_group'] === 'COLLEGE' ? 'selected' : ''; ?>>College</option>
                      <option value="SHS"     <?php echo $form['academic_group'] === 'SHS'     ? 'selected' : ''; ?>>Senior High School (SHS)</option>
                    </select>
                  </div>

                  <div class="field-group">
                    <label for="student_fn">First Name</label>
                    <input id="student_fn" name="student_fn" value="<?php echo htmlspecialchars($form['student_fn']); ?>" required/>
                  </div>

                  <div class="field-group">
                    <label for="student_ln">Last Name</label>
                    <input id="student_ln" name="student_ln" value="<?php echo htmlspecialchars($form['student_ln']); ?>" required/>
                  </div>

                  <div class="field-group">
                    <label for="year_level">Year Level</label>
                    <select id="year_level" name="year_level" required>
                      <option value="">Select year level</option>
                      <?php $yearLimit = $form['academic_group'] === 'SHS' ? 2 : 11; ?>
                      <?php for ($yr = 1; $yr <= $yearLimit; $yr++): ?>
                        <option value="<?php echo $yr; ?>" <?php echo (string) $form['year_level'] === (string) $yr ? 'selected' : ''; ?>>
                          <?php echo $yr; ?>
                        </option>
                      <?php endfor; ?>
                    </select>
                  </div>

                  <div id="collegeWrap" class="field-group">
                    <div style="display: flex; gap: 8px; align-items: flex-end;">
                      <div style="flex: 1;">
                        <label for="college_department">Course / Department</label>
                        <select id="college_department" name="college_department">
                          <option value="">Select course</option>
                        </select>
                      </div>
                      <button type="button" id="addCourseBtn" class="btn btn-sm" style="padding: 8px 12px; white-space: nowrap;">+ Add Course</button>
                    </div>
                  </div>

                  <div class="field-group">
                    <div style="display: flex; gap: 8px; align-items: flex-end;">
                      <div style="flex: 1;">
                        <label for="section">Section</label>
                        <select id="section" name="section" required>
                          <option value="">Select section</option>
                        </select>
                      </div>
                      <button type="button" id="addSectionBtn" class="btn btn-sm" style="padding: 8px 12px; white-space: nowrap;">+ Add Section</button>
                    </div>
                  </div>

                  <div id="shsWrap" class="field-group hidden">
                    <label for="shs_track">Track / Strand (SHS)</label>
                    <select id="shs_track" name="shs_track">
                      <option value="">Select SHS track</option>
                      <?php foreach (['STEM', 'ABM', 'HUMSS', 'GAS', 'TVL'] as $track): ?>
                        <option value="<?php echo $track; ?>" <?php echo $form['shs_track'] === $track ? 'selected' : ''; ?>><?php echo $track; ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>

                  <div class="field-group">
                    <label for="student_email">Student Email</label>
                    <input id="student_email" name="student_email" type="email" value="<?php echo htmlspecialchars($form['student_email']); ?>" required/>
                    <div id="student_email_status" class="field-status"></div>
                  </div>

                  <div class="field-group">
                    <label for="guardian_email">Guardian Email</label>
                    <input id="guardian_email" name="guardian_email" type="email" value="<?php echo htmlspecialchars($form['guardian_email']); ?>" required/>
                    <div id="guardian_email_status" class="field-status"></div>
                  </div>

                  <div class="field-group full">
                    <label for="phone_number">Phone Number</label>
                    <input id="phone_number" name="phone_number" value="<?php echo htmlspecialchars($form['phone_number']); ?>" required inputmode="numeric" autocomplete="off" maxlength="16" placeholder="09XXXXXXXXX"/>
                    <div id="phone_number_status" class="field-status"></div>
                  </div>

                </div><!-- .form-grid -->

                <div class="btn-row">
                  <button id="studentSaveBtn" class="btn btn-primary" type="submit">
                    <svg viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 01-2-2V5a2 2 0 012-2h11l5 5v11a2 2 0 01-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                    <?php echo $editMode ? 'Update Student Info' : 'Save & Continue to NFC'; ?>
                  </button>
                  <?php if ($editMode): ?>
                    <a class="btn btn-secondary" href="settings.php">
                      <svg viewBox="0 0 24 24"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                      Cancel
                    </a>
                  <?php endif; ?>
                </div>
              </form>

              <!-- Edit mode NFC update ─────────────────────────────────── -->
              <?php if ($editMode): ?>
                <div class="scanner-box" style="margin-top:28px;">
                  <div class="scanner-box-title">
                    <svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="2" ry="2"/><line x1="7" y1="2" x2="7" y2="22"/><line x1="11" y1="2" x2="11" y2="22"/><line x1="15" y1="2" x2="15" y2="22"/><line x1="19" y1="2" x2="19" y2="22"/></svg>
                    Update NFC Mapping
                  </div>
                  <input type="hidden" id="nfc_student_id" value="<?php echo htmlspecialchars($form['student_id']); ?>"/>

                  <div class="field-group">
                    <label for="nfc_scan_value">Scanner Input</label>
                    <input id="nfc_scan_value" placeholder="Tap NFC card/tag — value auto-fills" autocomplete="off"/>
                    <div id="nfc_scan_status" class="field-status"></div>
                    <div class="field-hint">This will overwrite the current NFC mapping for this student.</div>
                  </div>

                  <div class="btn-row">
                    <button id="linkBtn" class="btn btn-primary" type="button">
                      <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
                      Update NFC
                    </button>
                  </div>

                  <div id="nfcStatus" class="status-block hidden" style="margin-top:14px;"></div>
                </div>
              <?php endif; ?>

            <?php else: ?>

              <!-- Post-registration NFC link ───────────────────────────── -->
              <div class="alert alert-success" style="margin-bottom:20px;">
                <svg class="alert-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/>
                </svg>
                <div>Student record created for <strong><?php echo htmlspecialchars($registeredStudentId); ?></strong>. Now link their NFC card.</div>
              </div>

              <div class="scanner-box">
                <div class="scanner-box-title">
                  <svg viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="2" ry="2"/><line x1="7" y1="2" x2="7" y2="22"/><line x1="11" y1="2" x2="11" y2="22"/><line x1="15" y1="2" x2="15" y2="22"/><line x1="19" y1="2" x2="19" y2="22"/></svg>
                  Link NFC Card
                </div>
                <input type="hidden" id="nfc_student_id" value="<?php echo htmlspecialchars($registeredStudentId); ?>"/>

                <div class="field-group">
                  <label for="nfc_scan_value">Scanner Input</label>
                  <input id="nfc_scan_value" placeholder="Tap NFC card/tag — value auto-fills" autocomplete="off"/>
                  <div id="nfc_scan_status" class="field-status"></div>
                  <div class="field-hint">The scanner types the value then sends Enter. You can also paste and click Link NFC.</div>
                </div>

                <div class="btn-row">
                  <button id="linkBtn" class="btn btn-primary" type="button">
                    <svg viewBox="0 0 24 24"><path d="M10 13a5 5 0 007.54.54l3-3a5 5 0 00-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 00-7.54-.54l-3 3a5 5 0 007.07 7.07l1.71-1.71"/></svg>
                    Link NFC
                  </button>
                  <a href="settings.php" class="btn btn-secondary">Register Another</a>
                </div>

                <div id="nfcStatus" class="status-block hidden" style="margin-top:14px;"></div>
              </div>

            <?php endif; ?>

          </div><!-- .card-body -->
        </div><!-- .card -->

        <!-- ── NFC Mapping Management Table ─────────────────────────────── -->
        <?php if (false): ?>
        <div class="card">
          <div class="card-header">
            <div class="card-icon" style="background:var(--slate-700);">
              <svg viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87"/><path d="M16 3.13a4 4 0 010 7.75"/></svg>
            </div>
            <div>
              <div class="card-title">NFC Mapping Management</div>
              <div class="card-sub">View and manage NFC-linked students. Showing up to 200 most recently linked.</div>
            </div>
          </div>

          <div class="card-body">
            <form method="get" class="search-row">
              <input name="nfc_q" value="<?php echo htmlspecialchars($nfcQuery); ?>" placeholder="Search by ID, name, section, or course…" style="flex:1;"/>
              <button class="btn btn-secondary" type="submit">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                </svg>
                Search
              </button>
              <?php if ($nfcQuery !== ''): ?>
                <a class="btn btn-secondary" href="settings.php">Clear</a>
              <?php endif; ?>
            </form>

            <div class="table-wrap">
              <table>
                <thead>
                  <tr>
                    <th>Student ID</th>
                    <th>Full Name</th>
                    <th>School</th>
                    <th>Program</th>
                    <th>Year / Section</th>
                    <th>Student Email</th>
                    <th>Guardian Email</th>
                    <th>Last Linked</th>
                    <th>Action</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($nfcMappings)): ?>
                    <tr>
                      <td colspan="9">
                        <div class="empty-state">No NFC-linked students found<?php echo $nfcQuery !== '' ? ' for your search' : ''; ?>.</div>
                      </td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($nfcMappings as $row): ?>
                      <tr id="map-row-<?php echo htmlspecialchars((string) $row['student_id']); ?>">
                        <td><code style="font-family:var(--font-mono);font-size:12px;"><?php echo htmlspecialchars((string) $row['student_id']); ?></code></td>
                        <td style="font-weight:500;"><?php echo htmlspecialchars(trim((string) $row['student_fn'] . ' ' . (string) $row['student_ln'])); ?></td>
                        <td>
                          <span class="badge <?php echo stripos((string) ($row['school'] ?? ''), 'senior') !== false ? 'badge-slate' : 'badge-blue'; ?>">
                            <?php echo htmlspecialchars((string) ($row['school'] ?? '')); ?>
                          </span>
                        </td>
                        <td><?php echo htmlspecialchars((string) ($row['program'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars((string) ($row['year_level'] ?? '') . ' / ' . (string) ($row['section'] ?? '')); ?></td>
                        <td style="color:var(--slate-600);"><?php echo htmlspecialchars((string) ($row['student_email'] ?? '')); ?></td>
                        <td style="color:var(--slate-600);"><?php echo htmlspecialchars((string) ($row['guardian_email'] ?? '')); ?></td>
                        <td style="color:var(--slate-500);font-size:12px;"><?php echo htmlspecialchars((string) ($row['updated_at'] ?? '')); ?></td>
                        <td>
                          <a class="btn btn-secondary" style="height:32px;padding:0 12px;font-size:12px;" href="settings.php?edit_student_id=<?php echo urlencode((string) $row['student_id']); ?>#editForm">
                            Edit
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
        <?php endif; ?>

      </div><!-- .page -->
    </main>
  </div>

  <!-- ── Add Course Modal ──────────────────────────────────────────────── -->
  <div id="addCourseModal" class="modal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="addCourseTitle">
      <div class="modal-header">
        <div class="modal-title" id="addCourseTitle">Add New Course</div>
      </div>
      <div class="modal-body">
        <div class="field-group">
          <label for="newCourseName">Course Name</label>
          <input type="text" id="newCourseName" placeholder="e.g., BSIT, BSCS, BSN" maxlength="100"/>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-primary" id="addCourseConfirmBtn">Create Course</button>
        <button type="button" class="btn" id="addCourseCloseBtn">Cancel</button>
      </div>
    </div>
  </div>

  <!-- ── Add Section Modal ──────────────────────────────────────────────── -->
  <div id="addSectionModal" class="modal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="addSectionTitle">
      <div class="modal-header">
        <div class="modal-title" id="addSectionTitle">Add New Section</div>
      </div>
      <div class="modal-body">
        <div class="field-group" style="margin-bottom: 12px;">
          <label style="font-weight: 600; font-size: 13px;">Course:</label>
          <div id="addSectionCourseDisplay" style="padding: 8px; background: #f5f5f5; border-radius: 4px; font-weight: 500;"></div>
        </div>
        <div class="field-group">
          <label for="newSectionName">Section Name</label>
          <input type="text" id="newSectionName" placeholder="e.g., A, B, C, 1, 2" maxlength="50"/>
        </div>
        <div class="field-group" style="margin-top: 14px;">
          <label style="font-weight: 600; font-size: 13px;">Existing Sections</label>
          <div id="sectionManageList" class="section-manage-list"></div>
          <div class="field-hint">Tap Delete to remove a section from this course dropdown list.</div>
        </div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-primary" id="addSectionConfirmBtn">Create Section</button>
        <button type="button" class="btn" id="addSectionCloseBtn">Cancel</button>
      </div>
    </div>
  </div>

  <!-- ── NFC Result Modal ──────────────────────────────────────────────── -->
  <div id="nfcSuccessModal" class="modal" aria-hidden="true">
    <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="nfcSuccessTitle">
      <div class="modal-header">
        <div id="modalIndicator" class="modal-indicator success">✓</div>
        <img class="modal-logo" src="../assets/logo.png" alt="Logo"/>
        <div>
          <div id="nfcSuccessTitle" class="modal-title">NFC Registered</div>
          <div class="modal-sub">The NFC ID is now linked to this student.</div>
        </div>
      </div>
      <div class="modal-body">
        <div id="nfcModalPill" class="modal-pill ok">Ready</div>
        <p>The scanner value has been saved. The NFC field is now locked — update it from the management table if needed.</p>
        <div id="nfcSuccessStudentId" class="modal-id"></div>
        <div id="nfcLookupDetails" class="modal-id" style="margin-top:10px; display:none;"></div>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-primary" id="nfcSuccessCloseBtn">Done</button>
      </div>
    </div>
  </div>

  <!-- ── Scripts ──────────────────────────────────────────────────────── -->
  <script>
  (function () {
    'use strict';

    // ── School group toggle ──────────────────────────────────────────────
    var groupSel    = document.getElementById('academic_group');
    var shsWrap     = document.getElementById('shsWrap');
    var collegeWrap = document.getElementById('collegeWrap');
    var shsTrack    = document.getElementById('shs_track');
    var collegeDep  = document.getElementById('college_department');
    var sectionSel  = document.getElementById('section');
    var yearSelect  = document.getElementById('year_level');

    function getActiveProgram() {
      var isShs = groupSel && groupSel.value === 'SHS';
      return String(isShs ? (shsTrack ? shsTrack.value : '') : (collegeDep ? collegeDep.value : '')).trim();
    }

    function syncYearOptions() {
      if (!yearSelect || !groupSel) return;
      var isShs = groupSel.value === 'SHS';
      var current = String(yearSelect.value || '');
      var maxYear = isShs ? 2 : 11;
      var html = ['<option value="">Select year level</option>'];
      for (var yr = 1; yr <= maxYear; yr++) {
        html.push('<option value="' + yr + '"' + (current === String(yr) ? ' selected' : '') + '>' + yr + '</option>');
      }
      yearSelect.innerHTML = html.join('');
      if (current && parseInt(current, 10) > maxYear) {
        yearSelect.value = '';
      }
    }

    function syncSchoolGroup() {
      if (!groupSel || !shsWrap || !collegeWrap) return;
      var isShs = groupSel.value === 'SHS';
      shsWrap.classList.toggle('hidden', !isShs);
      collegeWrap.classList.toggle('hidden', isShs);
      if (shsTrack)   shsTrack.required   = isShs;
      if (collegeDep) collegeDep.required  = !isShs;
      syncYearOptions();
      if (isShs) {
        updateSections(getActiveProgram());
      } else {
        loadCoursesAndSections(collegeDep ? collegeDep.value : '');
      }
    }

    function loadCoursesAndSections(selectedCourse) {
      if (!groupSel || !collegeDep || !sectionSel) return;
      var school = groupSel.value === 'SHS' ? 'shs' : 'college';
      fetch('settings.php?action=get_courses_sections&school=' + encodeURIComponent(school) + '&course=' + encodeURIComponent(selectedCourse), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.ok) return;
        var cur = collegeDep.value;
        collegeDep.innerHTML = '<option value="">Select course</option>';
        data.courses.forEach(function(c) {
          var opt = document.createElement('option');
          opt.value = c; opt.textContent = c;
          if (c === cur) opt.selected = true;
          collegeDep.appendChild(opt);
        });
        updateSections(collegeDep.value);
      });
    }

    function updateSections(selectedCourse) {
      if (!sectionSel || !groupSel) return;
      var school = groupSel.value === 'SHS' ? 'shs' : 'college';
      fetch('settings.php?action=get_courses_sections&school=' + encodeURIComponent(school) + '&course=' + encodeURIComponent(selectedCourse), {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data.ok) return;
        var cur = sectionSel.value;
        sectionSel.innerHTML = '<option value="">Select section</option>';
        data.sections.forEach(function(s) {
          var opt = document.createElement('option');
          opt.value = s; opt.textContent = s;
          if (s === cur) opt.selected = true;
          sectionSel.appendChild(opt);
        });
      });
    }

    if (groupSel) {
      syncSchoolGroup();
      groupSel.addEventListener('change', syncSchoolGroup);
    }
    if (collegeDep) {
      collegeDep.addEventListener('change', function() { updateSections(this.value); });
      if (groupSel && groupSel.value !== 'SHS') loadCoursesAndSections(collegeDep.value || '');
    }
    if (shsTrack) {
      shsTrack.addEventListener('change', function() { updateSections(this.value); });
      if (groupSel && groupSel.value === 'SHS') updateSections(shsTrack.value || '');
    }

    // ── NFC scanner handler ──────────────────────────────────────────────
    var studentIdEl      = document.getElementById('nfc_student_id');
    var nfcInput         = document.getElementById('nfc_scan_value');
    var nfcScanStatus    = document.getElementById('nfc_scan_status');
    var nfcStatusBlock   = document.getElementById('nfcStatus');
    var linkBtn          = document.getElementById('linkBtn');
    var cancelPendingBtn = document.getElementById('cancelPendingBtn');
    var successModal     = document.getElementById('nfcSuccessModal');
    var successCloseBtn  = document.getElementById('nfcSuccessCloseBtn');
    var successStudentId = document.getElementById('nfcSuccessStudentId');
    var lookupDetails    = document.getElementById('nfcLookupDetails');
    var modalPill        = document.getElementById('nfcModalPill');
    var modalIndicator   = document.getElementById('modalIndicator');

    if (!studentIdEl || !nfcInput || !linkBtn) return;

    var pendingMode      = studentIdEl.getAttribute('data-pending-mode') === '1';
    var readyToFinalize  = false;
    var busy             = false;
    var scanBuffer       = '';
    var scanTimer        = null;
    var successTimer     = null;
    var overlayCard      = document.querySelector('.overlay-card');

    function setNfcStatus(ok, message) {
      if (!nfcStatusBlock) return;
      nfcStatusBlock.classList.remove('hidden', 'ok', 'err');
      nfcStatusBlock.classList.add(ok ? 'ok' : 'err');
      nfcStatusBlock.textContent = message;
    }

    function setScanFieldStatus(kind, message) {
      if (!nfcScanStatus) return;
      nfcScanStatus.className = 'field-status visible ' + kind;
      nfcScanStatus.textContent = message;
    }

    function clearScanFieldStatus() {
      if (!nfcScanStatus) return;
      nfcScanStatus.className = 'field-status';
      nfcScanStatus.textContent = '';
    }

    function lockNfcField() {
      nfcInput.classList.add('field-locked');
      nfcInput.disabled = true;
      linkBtn.disabled  = true;
    }

    function setModalMode(isError) {
      if (!successModal) return;
      var card = successModal.querySelector('.modal-card');
      if (card) card.classList.toggle('error', isError);
      if (modalIndicator) {
        modalIndicator.className = 'modal-indicator ' + (isError ? 'error-ind' : 'success');
        modalIndicator.textContent = isError ? '!' : '✓';
      }
      if (modalPill) {
        modalPill.className = 'modal-pill ' + (isError ? 'error' : 'ok');
        modalPill.textContent = isError ? 'Already Registered' : 'Ready';
      }
      var titleEl = document.getElementById('nfcSuccessTitle');
      var subEl   = successModal.querySelector('.modal-sub');
      var bodyEl  = successModal.querySelector('.modal-body p');
      if (lookupDetails) {
        lookupDetails.style.display = 'none';
        lookupDetails.textContent = '';
      }
      if (isError) {
        if (titleEl) titleEl.textContent = 'NFC ID Already Used';
        if (subEl)   subEl.textContent   = 'This NFC ID is already registered.';
        if (bodyEl)  bodyEl.textContent  = 'This NFC ID is linked to another student. Please scan a different card.';
        if (successStudentId) successStudentId.textContent = '';
      } else {
        if (titleEl) titleEl.textContent = 'NFC Registered';
        if (subEl)   subEl.textContent   = 'The NFC ID is now linked to this student.';
        if (bodyEl)  bodyEl.textContent  = 'The scanner value has been saved. The NFC field is locked — update from the management table if needed.';
      }
    }

    function renderLookupDetails(student, registered) {
      if (!lookupDetails) return;

      if (registered) {
        var studentData = student || {};
        var detailRows = [];
        detailRows.push('<div class="lookup-title"><span class="dot"></span><span>Registered NFC Found</span></div>');
        detailRows.push('<div class="lookup-grid">');
        if (studentData.student_id) detailRows.push('<div class="lookup-item"><span class="lookup-label">Student ID</span><span class="lookup-value">' + studentData.student_id + '</span></div>');
        if (studentData.student_name) detailRows.push('<div class="lookup-item"><span class="lookup-label">Name</span><span class="lookup-value">' + studentData.student_name + '</span></div>');
        if (studentData.school) detailRows.push('<div class="lookup-item"><span class="lookup-label">School</span><span class="lookup-value">' + studentData.school + '</span></div>');
        if (studentData.program) detailRows.push('<div class="lookup-item"><span class="lookup-label">Program</span><span class="lookup-value">' + studentData.program + '</span></div>');
        if (studentData.year_level) detailRows.push('<div class="lookup-item"><span class="lookup-label">Year</span><span class="lookup-value">' + studentData.year_level + '</span></div>');
        if (studentData.section) detailRows.push('<div class="lookup-item"><span class="lookup-label">Section</span><span class="lookup-value">' + studentData.section + '</span></div>');
        detailRows.push('</div>');
        lookupDetails.className = 'lookup-details registered';
        lookupDetails.innerHTML = detailRows.join('');
        lookupDetails.style.display = 'block';
        return;
      }

      lookupDetails.className = 'lookup-details missing';
      lookupDetails.innerHTML = '<div class="lookup-title"><span class="dot"></span><span>NFC Not Registered</span></div><div class="lookup-empty">This NFC card/tag is not linked to any student yet.</div>';
      lookupDetails.style.display = 'block';
    }

    function openModal(isError, message) {
      clearSuccessTimer();
      setModalMode(isError);
      document.body.classList.add('modal-open');
      if (!isError && successStudentId) {
        successStudentId.textContent = 'Linked Student ID: ' + studentIdEl.value;
      }
      if (!isError && !pendingMode) lockNfcField();
      if (successModal) {
        successModal.classList.add('active');
        successModal.setAttribute('aria-hidden', 'false');
      }
      if (!isError && !pendingMode) {
        successTimer = setTimeout(closeModal, 2600);
      }
    }

    function closeModal() {
      clearSuccessTimer();
      if (successModal) {
        successModal.classList.remove('active');
        successModal.setAttribute('aria-hidden', 'true');
      }
      if (!pendingMode) {
        document.body.classList.remove('modal-open');
      }
    }

    function clearSuccessTimer() {
      if (successTimer) { clearTimeout(successTimer); successTimer = null; }
    }

    function pulseOverlayError() {
      if (!overlayCard) return;
      overlayCard.style.boxShadow = '0 0 0 3px rgba(239,68,68,.25), 0 20px 50px rgba(15,23,42,.2)';
      setTimeout(function() {
        overlayCard.style.boxShadow = '';
      }, 700);
    }

    function postAjax(action, extra) {
      var fd = new FormData();
      fd.append('action', action);
      if (extra) Object.keys(extra).forEach(function(k) { fd.append(k, extra[k]); });
      return fetch(window.location.href, {
        method: 'POST',
        body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function(r) { return r.json(); });
    }

    function finalizePending() {
      postAjax('finalize_pending_registration')
      .then(function(data) {
        if (data && data.ok) {
          window.location.href = 'settings.php?registered=1&student_id=' + encodeURIComponent(data.student_id || studentIdEl.value);
          return;
        }
        setNfcStatus(false, (data && data.message) || 'Unable to save student record.');
      })
      .catch(function() { setNfcStatus(false, 'Unable to save student record.'); });
    }

    function cancelPending() {
      postAjax('cancel_pending_registration')
      .finally(function() { window.location.href = 'settings.php'; });
    }

    function handleModalClose() {
      if (pendingMode) {
        if (readyToFinalize) finalizePending();
        else cancelPending();
        return;
      }
      closeModal();
    }

    function sendAssign(scanValue) {
      var cleaned = String(scanValue || '').trim();
      if (!cleaned || busy) return;
      busy = true;

      postAjax('assign_nfc', { student_id: studentIdEl.value, scan_value: cleaned })
      .then(function(data) {
        if (data && data.ok) {
          if (data.registered === true && !pendingMode) {
            setNfcStatus(true, (data.message || 'NFC is registered.') + (data.student && data.student.student_id ? ' Student ID: ' + data.student.student_id : ''));
            setScanFieldStatus('ok', '✓ NFC is registered.');
            renderLookupDetails(data.student || null, true);
            linkBtn.disabled = true;
            openModal(false);
            return;
          }

          if (data.registered === false && !pendingMode) {
            setNfcStatus(false, data.message || 'NFC is not registered yet.');
            setScanFieldStatus('used', '✕ ' + (data.message || 'NFC is not registered yet.'));
            renderLookupDetails(null, false);
            linkBtn.disabled = true;
            openModal(true, data.message || '');
            return;
          }

          if (data.requires_finalize) readyToFinalize = true;
          setNfcStatus(true, data.message || 'NFC linked.');
          setScanFieldStatus('ok', '✓ NFC is available.');
          openModal(false);
          scanBuffer = '';
          nfcInput.value = '';
        } else {
          setNfcStatus(false, (data && data.message) || 'Failed to link NFC.');
          setScanFieldStatus('used', '✕ ' + ((data && data.message) || 'This NFC ID is already registered.'));
          if (pendingMode) pulseOverlayError();
          linkBtn.disabled = true;
        }
      })
      .catch(function() {
        setNfcStatus(false, 'Network error while linking NFC.');
        linkBtn.disabled = true;
      })
      .finally(function() { busy = false; });
    }

    function checkNfcAvailability(rawValue) {
      if (nfcInput.disabled) return;
      var cleaned = String(rawValue || '').trim();
      if (!cleaned) {
        clearScanFieldStatus();
        linkBtn.disabled = false;
        return;
      }

      setScanFieldStatus('loading', 'Checking…');
      linkBtn.disabled = true;

      postAjax('check_field_availability', {
        field: 'nfc_scan',
        value: cleaned,
        exclude_student_id: studentIdEl.value
      })
      .then(function(data) {
        if (data && data.ok && data.available) {
          setScanFieldStatus('ok', '✓ ' + (data.message || 'NFC is available.'));
          linkBtn.disabled = false;
        } else {
          var msg = (data && data.message) || 'This NFC ID is already registered.';
          setScanFieldStatus('used', '✕ ' + msg);
          linkBtn.disabled = true;
          if (String(nfcInput.value || '').trim() !== '') {
            setNfcStatus(false, msg);
            pulseOverlayError();
          }
        }
      })
      .catch(function() {
        setScanFieldStatus('used', '✕ Unable to check NFC availability.');
        linkBtn.disabled = true;
      });
    }

    // Availability debounce
    var nfcCheckTimer = null;
    nfcInput.addEventListener('input', function() {
      if (nfcInput.disabled) return;
      if (nfcCheckTimer) clearTimeout(nfcCheckTimer);
      nfcCheckTimer = setTimeout(function() { checkNfcAvailability(nfcInput.value); }, 450);
    });

    linkBtn.addEventListener('click', function() {
      if (!nfcInput.disabled) sendAssign(nfcInput.value);
    });

    if (successCloseBtn) successCloseBtn.addEventListener('click', handleModalClose);
    if (successModal)    successModal.addEventListener('click', function(e) { if (e.target === successModal) handleModalClose(); });
    if (cancelPendingBtn) cancelPendingBtn.addEventListener('click', cancelPending);

    // Hardware scanner (barcode/NFC sends chars fast then Enter)
    document.addEventListener('keydown', function(ev) {
      var t = ev.target;
      var isField = t && (t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' ||
        (t.tagName === 'INPUT' && t !== nfcInput) || t.isContentEditable);
      if (isField) return;

      if (ev.key === 'Enter') {
        var v = String(scanBuffer || '').trim();
        scanBuffer = '';
        if (scanTimer) { clearTimeout(scanTimer); scanTimer = null; }
        if (v.length >= 4) { nfcInput.value = v; sendAssign(v); }
        return;
      }

      if (ev.key.length === 1 && !ev.ctrlKey && !ev.altKey && !ev.metaKey) {
        scanBuffer += ev.key;
        if (scanTimer) clearTimeout(scanTimer);
        scanTimer = setTimeout(function() {
          var v = String(scanBuffer || '').trim();
          scanBuffer = '';
          if (v.length >= 4) { nfcInput.value = v; sendAssign(v); }
        }, 180);
      }
    });
  })();

  // ── Live field availability checks ────────────────────────────────────
  (function () {
    'use strict';

    var studentIdInput   = document.getElementById('student_id');
    var studentEmailInput = document.getElementById('student_email');
    var guardianEmailInput = document.getElementById('guardian_email');
    var phoneInput       = document.getElementById('phone_number');
    var phoneStatus      = document.getElementById('phone_number_status');
    var saveBtn          = document.getElementById('studentSaveBtn');
    var studentIdStatus  = document.getElementById('student_id_status');
    var studentEmailStatus = document.getElementById('student_email_status');
    var guardianEmailStatus = document.getElementById('guardian_email_status');

    var excludeStudentId = <?php echo json_encode($editMode ? (string) $form['student_id'] : ''); ?>;

    function setFieldStatus(el, kind, text) {
      if (!el) return;
      el.className = 'field-status visible ' + kind;
      el.textContent = text;
      el.dataset.state = kind;
    }

    function clearFieldStatus(el) {
      if (!el) return;
      el.className = 'field-status';
      el.textContent = '';
      el.dataset.state = '';
    }

    function updateSaveAvailability() {
      if (!saveBtn) return;
      var blocked = (studentIdStatus   && studentIdStatus.dataset.state   === 'used') ||
                    (studentEmailStatus && studentEmailStatus.dataset.state === 'used') ||
                    (phoneStatus       && phoneStatus.dataset.state        === 'used');
      saveBtn.disabled = !!blocked;
    }

    function sanitizePhone(v) {
      var c = String(v || '').replace(/[^0-9+]/g, '');
      if ((c.match(/\+/g) || []).length > 1) c = c.replace(/\+/g, '');
      if (c.indexOf('+') > 0)                c = c.replace(/\+/g, '');
      return c.slice(0, 16);
    }

    function validatePhone() {
      if (!phoneInput || !phoneStatus) return;
      var cleaned = sanitizePhone(phoneInput.value);
      if (cleaned !== phoneInput.value) phoneInput.value = cleaned;

      if (!cleaned) { clearFieldStatus(phoneStatus); updateSaveAvailability(); return; }

      if (!/^\+?[0-9]{7,15}$/.test(cleaned)) {
        setFieldStatus(phoneStatus, 'used', '✕ Must be 7–15 digits (optional + prefix).');
      } else {
        setFieldStatus(phoneStatus, 'ok', '✓ Phone number looks valid.');
      }
      updateSaveAvailability();
    }

    function checkAvailability(field, value, statusEl) {
      var cleaned = String(value || '').trim();
      if (!cleaned) { clearFieldStatus(statusEl); updateSaveAvailability(); return; }

      setFieldStatus(statusEl, 'loading', 'Checking…');

      var fd = new FormData();
      fd.append('action', 'check_field_availability');
      fd.append('field', field);
      fd.append('value', cleaned);
      if (excludeStudentId) fd.append('exclude_student_id', excludeStudentId);

      fetch(window.location.href, {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        if (!data || !data.ok) {
          setFieldStatus(statusEl, 'used', 'Unable to check right now.');
        } else if (data.available) {
          setFieldStatus(statusEl, 'ok', '✓ ' + (data.message || 'Available'));
        } else {
          setFieldStatus(statusEl, 'used', '✕ ' + (data.message || 'Already in use'));
        }
        updateSaveAvailability();
      })
      .catch(function() {
        setFieldStatus(statusEl, 'used', 'Unable to check right now.');
        updateSaveAvailability();
      });
    }

    function bindLiveCheck(inputEl, statusEl, fieldName) {
      if (!inputEl || !statusEl) return;
      var timer = null;
      inputEl.addEventListener('input', function() {
        if (fieldName === 'student_id') {
          var cleanedId = String(inputEl.value || '').replace(/[^0-9-]/g, '');
          if (cleanedId !== inputEl.value) inputEl.value = cleanedId;
        }
        clearTimeout(timer);
        timer = setTimeout(function() { checkAvailability(fieldName, inputEl.value, statusEl); }, 450);
      });
      if (String(inputEl.value || '').trim() !== '') {
        checkAvailability(fieldName, inputEl.value, statusEl);
      }
    }

    bindLiveCheck(studentIdInput, studentIdStatus, 'student_id');
    bindLiveCheck(studentEmailInput, studentEmailStatus, 'student_email');
    bindLiveCheck(guardianEmailInput, guardianEmailStatus, 'guardian_email');

    if (phoneInput && phoneStatus) {
      phoneInput.addEventListener('input', validatePhone);
      phoneInput.addEventListener('blur',  validatePhone);
      validatePhone();
    }

    updateSaveAvailability();
  })();

  // ── Add Course and Section Modal Handlers ─────────────────────────────
  (function () {
    'use strict';

    var addCourseBtn            = document.getElementById('addCourseBtn');
    var addSectionBtn           = document.getElementById('addSectionBtn');
    var addCourseModal          = document.getElementById('addCourseModal');
    var addSectionModal         = document.getElementById('addSectionModal');
    var newCourseNameInput      = document.getElementById('newCourseName');
    var newSectionNameInput     = document.getElementById('newSectionName');
    var addCourseConfirmBtn     = document.getElementById('addCourseConfirmBtn');
    var addSectionConfirmBtn    = document.getElementById('addSectionConfirmBtn');
    var addCourseCloseBtn       = document.getElementById('addCourseCloseBtn');
    var addSectionCloseBtn      = document.getElementById('addSectionCloseBtn');
    var collegeDep              = document.getElementById('college_department');
    var shsTrack                = document.getElementById('shs_track');
    var sectionSel              = document.getElementById('section');
    var groupSel                = document.getElementById('academic_group');
    var addSectionCourseDisplay = document.getElementById('addSectionCourseDisplay');
    var sectionManageList       = document.getElementById('sectionManageList');

    function showAlert(msg) {
      alert(msg);
    }

    function syncBodyModalLock() {
      var hasActiveModal = !!document.querySelector('.modal.active');
      var hasPendingOverlay = !!document.querySelector('.overlay-backdrop');
      if (hasActiveModal || hasPendingOverlay) {
        document.body.classList.add('modal-open');
      } else {
        document.body.classList.remove('modal-open');
      }
    }

    function openModal(modal) {
      if (!modal) return;
      modal.classList.add('active');
      modal.setAttribute('aria-hidden', 'false');
      syncBodyModalLock();
    }

    function closeModal(modal) {
      if (!modal) return;
      modal.classList.remove('active');
      modal.setAttribute('aria-hidden', 'true');
      syncBodyModalLock();
    }

    function getSchoolParam() {
      return groupSel && groupSel.value === 'SHS' ? 'shs' : 'college';
    }

    function getProgramTypeLabel() {
      return getSchoolParam() === 'shs' ? 'strand' : 'course';
    }

    function getSelectedProgram() {
      var isShs = getSchoolParam() === 'shs';
      return String(isShs ? (shsTrack ? shsTrack.value : '') : (collegeDep ? collegeDep.value : '')).trim();
    }

    function fetchCoursesAndSections(courseName) {
      var course = String(courseName || '').trim();
      var url = 'settings.php?action=get_courses_sections&school=' + encodeURIComponent(getSchoolParam()) + '&course=' + encodeURIComponent(course);
      return fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function(r) { return r.json(); });
    }

    function postAction(action, payload) {
      var body = 'action=' + encodeURIComponent(action);
      Object.keys(payload || {}).forEach(function(key) {
        body += '&' + encodeURIComponent(key) + '=' + encodeURIComponent(payload[key]);
      });
      return fetch('settings.php', {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/x-www-form-urlencoded'
        },
        body: body
      }).then(function(r) { return r.json(); });
    }

    function refreshCourses(selectedCourse) {
      if (!collegeDep) return Promise.resolve();
      return fetchCoursesAndSections(selectedCourse || collegeDep.value || '')
        .then(function(data) {
          if (!data || !data.ok) return;
          var wanted = String(selectedCourse || collegeDep.value || '').trim();
          collegeDep.innerHTML = '<option value="">Select course</option>';
          (data.courses || []).forEach(function(course) {
            var opt = document.createElement('option');
            opt.value = course;
            opt.textContent = course;
            if (course === wanted) opt.selected = true;
            collegeDep.appendChild(opt);
          });
          if (wanted) collegeDep.value = wanted;
        });
    }

    function refreshSections(courseName, selectedSection) {
      if (!sectionSel) return Promise.resolve();
      var course = String(courseName || '').trim();
      return fetchCoursesAndSections(course)
        .then(function(data) {
          if (!data || !data.ok) return;
          sectionSel.innerHTML = '<option value="">Select section</option>';
          var wanted = String(selectedSection || '').trim();
          (data.sections || []).forEach(function(section) {
            var opt = document.createElement('option');
            opt.value = section;
            opt.textContent = section;
            if (section === wanted) opt.selected = true;
            sectionSel.appendChild(opt);
          });
          if (wanted) sectionSel.value = wanted;
        });
    }

    function renderSectionManageList(courseName) {
      if (!sectionManageList) return;
      var course = String(courseName || '').trim();
      sectionManageList.innerHTML = '<div class="section-manage-empty">Loading sections…</div>';

      fetchCoursesAndSections(course)
        .then(function(data) {
          if (!data || !data.ok) {
            sectionManageList.innerHTML = '<div class="section-manage-empty">Unable to load sections right now.</div>';
            return;
          }

          var sections = data.sections || [];
          if (!sections.length) {
            sectionManageList.innerHTML = '<div class="section-manage-empty">No sections yet for this course.</div>';
            return;
          }

          sectionManageList.innerHTML = '';
          sections.forEach(function(sectionName) {
            var row = document.createElement('div');
            row.className = 'section-manage-item';

            var name = document.createElement('span');
            name.textContent = sectionName;

            var delBtn = document.createElement('button');
            delBtn.type = 'button';
            delBtn.className = 'section-delete-btn';
            delBtn.textContent = 'Delete';
            delBtn.setAttribute('data-course', course);
            delBtn.setAttribute('data-section', sectionName);

            row.appendChild(name);
            row.appendChild(delBtn);
            sectionManageList.appendChild(row);
          });
        })
        .catch(function() {
          sectionManageList.innerHTML = '<div class="section-manage-empty">Unable to load sections right now.</div>';
        });
    }

    function openSectionModalForCourse(courseName) {
      var course = String(courseName || '').trim();
      if (!course) {
        showAlert('Please select a ' + getProgramTypeLabel() + ' first.');
        return;
      }

      if (getSchoolParam() !== 'shs' && collegeDep) collegeDep.value = course;
      if (addSectionCourseDisplay) {
        var label = getSchoolParam() === 'shs' ? 'Strand' : 'Course';
        addSectionCourseDisplay.textContent = label + ': ' + course;
      }
      if (newSectionNameInput) newSectionNameInput.value = '';

      openModal(addSectionModal);
      renderSectionManageList(course);

      if (newSectionNameInput) {
        setTimeout(function() { newSectionNameInput.focus(); }, 80);
      }
    }

    if (addCourseBtn) {
      addCourseBtn.addEventListener('click', function() {
        if (newCourseNameInput) newCourseNameInput.value = '';
        openModal(addCourseModal);
        if (newCourseNameInput) {
          setTimeout(function() { newCourseNameInput.focus(); }, 80);
        }
      });
    }

    if (addCourseCloseBtn) {
      addCourseCloseBtn.addEventListener('click', function() {
        closeModal(addCourseModal);
      });
    }

    if (addCourseConfirmBtn) {
      addCourseConfirmBtn.addEventListener('click', function() {
        var courseName = String(newCourseNameInput ? newCourseNameInput.value : '').trim();
        if (!courseName) {
          showAlert('Please enter a course name.');
          return;
        }

        postAction('add_course', { course_name: courseName, school: getSchoolParam() })
          .then(function(data) {
            if (!data || !data.ok) {
              showAlert('Error: ' + ((data && data.message) || 'Could not create course.'));
              return;
            }

            return refreshCourses(courseName)
              .then(function() { return refreshSections(courseName, ''); })
              .then(function() {
                closeModal(addCourseModal);
                openSectionModalForCourse(courseName);
              });
          })
          .catch(function(e) {
            showAlert('Network error: ' + e.message);
          });
      });
    }

    if (addSectionBtn) {
      addSectionBtn.addEventListener('click', function() {
        openSectionModalForCourse(getSelectedProgram());
      });
    }

    if (addSectionCloseBtn) {
      addSectionCloseBtn.addEventListener('click', function() {
        closeModal(addSectionModal);
      });
    }

    if (addSectionConfirmBtn) {
      addSectionConfirmBtn.addEventListener('click', function() {
        var courseName = getSelectedProgram();
        var sectionName = String(newSectionNameInput ? newSectionNameInput.value : '').trim();

        if (!courseName) {
          showAlert('Please select a ' + getProgramTypeLabel() + ' first.');
          return;
        }
        if (!sectionName) {
          showAlert('Please enter a section name.');
          return;
        }

        postAction('add_section', { course_name: courseName, section_name: sectionName, school: getSchoolParam() })
          .then(function(data) {
            if (!data || !data.ok) {
              showAlert('Error: ' + ((data && data.message) || 'Could not create section.'));
              return;
            }

            if (newSectionNameInput) newSectionNameInput.value = '';
            return refreshSections(courseName, sectionName)
              .then(function() { renderSectionManageList(courseName); });
          })
          .catch(function(e) {
            showAlert('Network error: ' + e.message);
          });
      });
    }

    if (sectionManageList) {
      sectionManageList.addEventListener('click', function(e) {
        var btn = e.target;
        if (!btn || !btn.classList || !btn.classList.contains('section-delete-btn')) return;

        var courseName = String(btn.getAttribute('data-course') || '').trim();
        var sectionName = String(btn.getAttribute('data-section') || '').trim();

        if (!courseName || !sectionName) return;
        if (!confirm('Delete section "' + sectionName + '" from "' + courseName + '"?')) return;

        postAction('delete_section', { course_name: courseName, section_name: sectionName, school: getSchoolParam() })
          .then(function(data) {
            if (!data || !data.ok) {
              showAlert('Error: ' + ((data && data.message) || 'Could not delete section.'));
              return;
            }

            if (sectionSel && sectionSel.value === sectionName) {
              sectionSel.value = '';
            }
            return refreshSections(courseName, sectionSel ? sectionSel.value : '')
              .then(function() { renderSectionManageList(courseName); });
          })
          .catch(function(e) {
            showAlert('Network error: ' + e.message);
          });
      });
    }

    if (addCourseModal) {
      addCourseModal.addEventListener('click', function(e) {
        if (e.target === addCourseModal) closeModal(addCourseModal);
      });
    }

    if (addSectionModal) {
      addSectionModal.addEventListener('click', function(e) {
        if (e.target === addSectionModal) closeModal(addSectionModal);
      });
    }

    if (newCourseNameInput) {
      newCourseNameInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && addCourseConfirmBtn) addCourseConfirmBtn.click();
      });
    }

    if (newSectionNameInput) {
      newSectionNameInput.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && addSectionConfirmBtn) addSectionConfirmBtn.click();
      });
    }
  })();
  </script>
</body>
</html>