<?php
// File: admin/AJAX/update_student_sanction.php
require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
  exit;
}

$admin = admin_current();
$adminId = (int)($admin['admin_id'] ?? 0);

$caseId = (int)($_POST['case_id'] ?? 0);
$studentId = trim((string)($_POST['student_id'] ?? ''));
$category = (int)($_POST['category'] ?? 0);
$password = (string)($_POST['password'] ?? '');
$otp = trim((string)($_POST['otp'] ?? ''));
$hours = (float)($_POST['hours'] ?? 0);
$probation_until = trim((string)($_POST['probation_until'] ?? ''));
$completed = (int)($_POST['completed'] ?? 0);

if ($caseId <= 0 || $studentId === '' || $category < 1 || $category > 5) {
  echo json_encode(['success' => false, 'message' => 'Invalid parameters.']);
  exit;
}

$lockKey = "edit_sanction_lock_{$adminId}";
if (!empty($_SESSION[$lockKey]) && time() < (int)$_SESSION[$lockKey]) {
  $secondsLeft = (int)$_SESSION[$lockKey] - time();
  echo json_encode([
    'success' => false,
    'lockout' => true,
    'seconds_left' => $secondsLeft,
    'message' => 'Too many failed attempts. Please wait 5 minutes.'
  ]);
  exit;
}

if ($password === '') {
  echo json_encode(['success' => false, 'message' => 'Password is required.']);
  exit;
}

if ($otp === '') {
  echo json_encode(['success' => false, 'message' => 'OTP is required.']);
  exit;
}

// 1. Verify Password
if (!admin_verify_password($adminId, $password)) {
  $attemptsKey = "edit_sanction_attempts_{$adminId}";
  $attempts = (int)($_SESSION[$attemptsKey] ?? 0) + 1;
  $_SESSION[$attemptsKey] = $attempts;

  if ($attempts >= 5) {
    $_SESSION[$lockKey] = time() + 300;
    $_SESSION[$attemptsKey] = 0; // reset
    $secondsLeft = 300;
    echo json_encode([
      'success' => false,
      'lockout' => true,
      'seconds_left' => $secondsLeft,
      'message' => 'Too many failed attempts. Please wait 5 minutes.'
    ]);
    exit;
  }

  $left = 5 - $attempts;
  echo json_encode(['success' => false, 'message' => "Incorrect password. {$left} attempts remaining."]);
  exit;
}

// 2. Verify OTP
$key = "adminotp_{$adminId}_edit_sanction";
if (empty($_SESSION['otp'][$key])) {
  echo json_encode(['success' => false, 'message' => 'No OTP request found. Please request a new OTP.']);
  exit;
}

$rec = $_SESSION['otp'][$key];
if (time() > (int)$rec['expires']) {
  unset($_SESSION['otp'][$key]);
  echo json_encode(['success' => false, 'message' => 'OTP has expired. Please request a new one.']);
  exit;
}

if (!hash_equals((string)$rec['code'], $otp)) {
  $attemptsKey = "edit_sanction_attempts_{$adminId}";
  $attempts = (int)($_SESSION[$attemptsKey] ?? 0) + 1;
  $_SESSION[$attemptsKey] = $attempts;

  if ($attempts >= 5) {
    $_SESSION[$lockKey] = time() + 300;
    $_SESSION[$attemptsKey] = 0; // reset
    unset($_SESSION['otp'][$key]); // clear OTP
    $secondsLeft = 300;
    echo json_encode([
      'success' => false,
      'lockout' => true,
      'seconds_left' => $secondsLeft,
      'message' => 'Too many failed attempts. Please wait 5 minutes.'
    ]);
    exit;
  }

  $left = 5 - $attempts;
  echo json_encode(['success' => false, 'message' => "Incorrect OTP code. {$left} attempts remaining."]);
  exit;
}

// OTP verified! Clear it and clear failed attempts count.
unset($_SESSION['otp'][$key]);
unset($_SESSION[$attemptsKey]);

// 3. Database Transaction
try {
  $pdo = db();
  $pdo->beginTransaction();

  $case = db_one("SELECT case_id, decided_category, student_id FROM upcc_case WHERE case_id = :cid", [':cid' => $caseId]);
  if (!$case) {
    $pdo->rollBack();
    echo json_encode(['success' => false, 'message' => 'Case not found.']);
    exit;
  }

  $oldCategory = (int)$case['decided_category'];
  $oldHoursCompleted = 0.0;
  $oldHoursRequired = 0.0;

  if ($oldCategory === 2) {
    $req = db_one(
      "SELECT r.requirement_id, r.hours_required, (
         SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, sess.time_in, sess.time_out)/3600.0), 0.0)
         FROM community_service_session sess
         WHERE sess.requirement_id = r.requirement_id AND sess.time_out IS NOT NULL
       ) AS hours_completed 
       FROM community_service_requirement r 
       WHERE r.related_case_id = :cid 
       LIMIT 1",
      [':cid' => $caseId]
    );
    if ($req) {
      $oldHoursCompleted = (float)$req['hours_completed'];
      $oldHoursRequired = (float)$req['hours_required'];
    }
  }

  // If the category is changed, reset the completed status to 0 (false)
  if ($category !== $oldCategory) {
    $completed = 0;
  }

  // Set values based on category
  $probationUntil = null;
  $punishDetails = null;

  if ($category === 1) {
    $probationUntil = !empty($probation_until) ? date('Y-m-d 23:59:59', strtotime($probation_until)) : date('Y-m-d 23:59:59', strtotime('+1 semester'));
    if ($completed === 1) {
      $probationUntil = date('Y-m-d H:i:s', time() - 10);
    }
    $punishDetails = json_encode([
      'semester' => 'Active Probation',
      'completed' => ($completed === 1)
    ]);
  } elseif ($category === 2) {
    $punishDetails = json_encode([
      'service_hours' => $hours,
      'interventions' => ['University Service'],
      'completed' => ($completed === 1)
    ]);
  } elseif ($category === 3) {
    $punishDetails = json_encode([
      'notes' => 'Non-Readmission next semester',
      'completed' => ($completed === 1)
    ]);
  } elseif ($category === 4 || $category === 5) {
    $punishDetails = json_encode([
      'notes' => 'Frozen/Expelled',
      'completed' => ($completed === 1)
    ]);
  }

  // Update upcc_case
  db_exec(
    "UPDATE upcc_case 
     SET decided_category = :cat, 
         probation_until = :prob_until, 
         punishment_details = :punish_details, 
         updated_at = NOW() 
     WHERE case_id = :cid",
    [
      ':cat' => $category,
      ':prob_until' => $probationUntil,
      ':punish_details' => $punishDetails,
      ':cid' => $caseId
    ]
  );

  // Manage Community Service Requirement
  if ($category === 2) {
    // 1. Locate the requirement for this case or student
    $csr = db_one(
      "SELECT requirement_id, hours_required, status 
       FROM community_service_requirement 
       WHERE related_case_id = :cid 
       ORDER BY requirement_id DESC 
       LIMIT 1",
      [':cid' => $caseId]
    );

    if (!$csr) {
      // Check for an active requirement for this student
      $csr = db_one(
        "SELECT requirement_id, hours_required, status 
         FROM community_service_requirement 
         WHERE student_id = :sid AND status = 'ACTIVE' 
         ORDER BY requirement_id DESC 
         LIMIT 1",
        [':sid' => $studentId]
      );
    }

    if (!$csr) {
      // Fallback: Check for any existing requirement for this student
      $csr = db_one(
        "SELECT requirement_id, hours_required, status 
         FROM community_service_requirement 
         WHERE student_id = :sid 
         ORDER BY requirement_id DESC 
         LIMIT 1",
        [':sid' => $studentId]
      );
    }

    $newStatus = ($completed === 1) ? 'COMPLETED' : 'ACTIVE';

    if ($csr) {
      $rid = (int)$csr['requirement_id'];
      // Update the requirement with exact adjusted hours and link to the case
      db_exec(
        "UPDATE community_service_requirement 
         SET hours_required = :hours, 
             related_case_id = :cid,
             status = :status, 
             completed_at = :completed_at,
             updated_at = NOW() 
         WHERE requirement_id = :rid",
        [
          ':hours' => $hours,
          ':cid' => $caseId,
          ':status' => $newStatus,
          ':completed_at' => ($completed === 1) ? date('Y-m-d H:i:s') : null,
          ':rid' => $rid
        ]
      );

      // Clean up any stray/duplicate active records for this student so they don't double-count in mobile dashboard/overview
      if ($newStatus === 'ACTIVE') {
        db_exec(
          "UPDATE community_service_requirement 
           SET status = 'COMPLETED', 
               completed_at = COALESCE(completed_at, NOW()), 
               updated_at = NOW() 
           WHERE student_id = :sid AND status = 'ACTIVE' AND requirement_id != :rid",
          [':sid' => $studentId, ':rid' => $rid]
        );
      } else {
        db_exec(
          "UPDATE community_service_requirement 
           SET status = 'COMPLETED', 
               completed_at = COALESCE(completed_at, NOW()), 
               updated_at = NOW() 
           WHERE student_id = :sid AND status = 'ACTIVE'",
          [':sid' => $studentId]
        );

        db_exec(
          "UPDATE upcc_case 
           SET punishment_details = JSON_SET(COALESCE(NULLIF(punishment_details, ''), '{}'), '$.completed', true),
               updated_at = NOW() 
           WHERE student_id = :sid AND decided_category = 2",
          [':sid' => $studentId]
        );
      }

      upcc_log_case_activity($caseId, 'ADMIN', $adminId, 'SANCTION_HOURS_UPDATED', [
          'hours_required' => $hours,
          'status' => $newStatus,
          'source' => 'Admin Direct Sanction Adjustment',
      ]);
    } else {
      // No requirement existed at all; create a brand new one
      db_exec(
        "INSERT INTO community_service_requirement (student_id, assigned_by, related_case_id, task_name, location, hours_required, status, assigned_at, completed_at, created_at)
         VALUES (:sid, :admin_id, :cid, 'University Service', 'SDO Office', :hours, :status, NOW(), :completed_at, NOW())",
        [
          ':sid' => $studentId,
          ':admin_id' => $adminId,
          ':cid' => $caseId,
          ':hours' => $hours,
          ':status' => $newStatus,
          ':completed_at' => ($completed === 1) ? date('Y-m-d H:i:s') : null
        ]
      );

      upcc_log_case_activity($caseId, 'ADMIN', $adminId, 'SANCTION_HOURS_ASSIGNED', [
          'hours_required' => $hours,
          'status' => $newStatus,
          'source' => 'Admin Direct Sanction Creation',
      ]);
    }
  } else {
    // If no longer Category 2, cancel requirement
    db_exec(
      "UPDATE community_service_requirement 
       SET status = 'CANCELLED', updated_at = NOW() 
       WHERE related_case_id = :cid AND status != 'COMPLETED'",
      [':cid' => $caseId]
    );
  }

  // Manage Student Account Status
  if (($category === 4 || $category === 5) && $completed !== 1) {
    db_exec("UPDATE student SET is_active = 0, updated_at = NOW() WHERE student_id = :sid", [':sid' => $studentId]);
  } else {
    db_exec("UPDATE student SET is_active = 1, updated_at = NOW() WHERE student_id = :sid", [':sid' => $studentId]);
  }

  // Log activity
  db_exec(
    "INSERT INTO upcc_case_activity (case_id, actor_type, actor_id, action, payload_json, created_at)
     VALUES (:cid, 'ADMIN', :admin_id, 'SANCTION_CATEGORY_UPDATED', :payload, NOW())",
    [
      ':cid' => $caseId,
      ':admin_id' => $adminId,
      ':payload' => json_encode([
        'category' => $category,
        'hours' => $hours,
        'probation_until' => $probationUntil,
        'by' => $admin['username'],
        'old_category' => $oldCategory,
        'old_hours_required' => $oldHoursRequired,
        'old_hours_completed' => $oldHoursCompleted
      ])
    ]
  );

  // Audit log
  db_exec(
    "INSERT INTO audit_log (actor_admin_id, action, entity_type, entity_id, details, created_at)
     VALUES (:admin_id, 'UPDATE_SANCTION', 'upcc_case', :cid, :details, NOW())",
    [
      ':admin_id' => $adminId,
      ':cid' => $caseId,
      ':details' => json_encode([
        'message' => "Updated sanction category to Category {$category} (Hours: {$hours}) for student {$studentId} for case {$caseId}",
        'category' => $category,
        'hours' => $hours,
        'student_id' => $studentId
      ])
    ]
  );

  // Auto-record finalized case decision into AI Historical Dataset
  record_finalized_case_to_historical_dataset($caseId);

  $pdo->commit();
  echo json_encode(['success' => true, 'message' => 'Student sanction updated successfully.']);
} catch (Exception $e) {
  if (isset($pdo)) {
    $pdo->rollBack();
  }
  echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
