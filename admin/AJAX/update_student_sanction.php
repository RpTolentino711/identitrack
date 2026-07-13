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
  echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
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
  $rec['attempts'] = (int)($rec['attempts'] ?? 0) + 1;
  if ($rec['attempts'] >= 5) {
    $rec['locked_until'] = time() + 300;
    $_SESSION['otp'][$key] = $rec;
    echo json_encode(['success' => false, 'message' => 'Too many failed attempts. Please wait 5 minutes.']);
    exit;
  }
  $_SESSION['otp'][$key] = $rec;
  $left = 5 - $rec['attempts'];
  echo json_encode(['success' => false, 'message' => "Incorrect OTP code. {$left} attempts remaining."]);
  exit;
}

// OTP verified! Clear it.
unset($_SESSION['otp'][$key]);

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
    $csr = db_one("SELECT requirement_id, hours_required, status FROM community_service_requirement WHERE related_case_id = :cid", [':cid' => $caseId]);
    if (!$csr) {
      // Look for an existing unlinked requirement for this student
      $unlinkedCsr = db_one(
        "SELECT requirement_id, hours_required, status FROM community_service_requirement 
         WHERE student_id = :sid AND (related_case_id IS NULL OR related_case_id = '') 
         LIMIT 1",
        [':sid' => $studentId]
      );
      if ($unlinkedCsr) {
        db_exec(
          "UPDATE community_service_requirement SET related_case_id = :cid WHERE requirement_id = :rid",
          [':cid' => $caseId, ':rid' => $unlinkedCsr['requirement_id']]
        );
        $csr = $unlinkedCsr;
      }
    }

    // Determine target hours (additional hours logic when reactivating completed/fully-served requirement)
    $completedHours = 0.0;
    $oldHoursRequired = 0.0;
    $oldStatus = 'ACTIVE';
    if ($csr) {
      $oldHoursRequired = (float)$csr['hours_required'];
      $oldStatus = $csr['status'];
      $completedHours = (float)db_one(
        "SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, time_in, time_out)/3600.0), 0.0) AS completed
         FROM community_service_session 
         WHERE requirement_id = :rid AND time_out IS NOT NULL",
        [':rid' => $csr['requirement_id']]
      )['completed'];
    }

    $newStatus = ($completed === 1) ? 'COMPLETED' : 'ACTIVE';
    $isPreviouslyServed = ($oldStatus === 'COMPLETED' || $completedHours >= ($oldHoursRequired - 0.0001));

    if ($newStatus === 'ACTIVE' && $isPreviouslyServed && $csr) {
      // If reactivating a completed or fully-served requirement, we start fresh with a NEW requirement record.
      // 1. Mark the old requirement as COMPLETED and unlink it from the case to preserve history.
      db_exec(
        "UPDATE community_service_requirement 
         SET status = 'COMPLETED', 
             related_case_id = NULL,
             completed_at = COALESCE(completed_at, NOW()),
             updated_at = NOW() 
         WHERE requirement_id = :rid",
        [':rid' => $csr['requirement_id']]
      );
      // 2. Set $csr to null so the code below inserts a brand new requirement record starting from 0 hours.
      $csr = null;
    }

    if ($csr) {
      db_exec(
        "UPDATE community_service_requirement 
         SET hours_required = :hours, 
             status = :status, 
             completed_at = :completed_at,
             updated_at = NOW() 
         WHERE related_case_id = :cid",
        [
          ':hours' => $hours,
          ':status' => $newStatus,
          ':completed_at' => ($completed === 1) ? date('Y-m-d H:i:s') : null,
          ':cid' => $caseId
        ]
      );
    } else {
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
        'by' => $admin['username']
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

  $pdo->commit();
  echo json_encode(['success' => true, 'message' => 'Student sanction updated successfully.']);
} catch (Exception $e) {
  if (isset($pdo)) {
    $pdo->rollBack();
  }
  echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
