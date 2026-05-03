<?php
declare(strict_types=1);

require_once __DIR__ . '/../../database/database.php';
ensure_hearing_workflow_schema();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function json_out(bool $ok, string $message = '', $data = null, int $status = 200): void {
  http_response_code($status);
  echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data]);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(false, 'Method not allowed.', null, 405);
}

$isMultipart = str_starts_with($_SERVER['CONTENT_TYPE'] ?? '', 'multipart/form-data');

if ($isMultipart) {
    $studentId = trim((string)($_POST['student_id'] ?? ''));
    $offenseId = (int)($_POST['offense_id'] ?? 0);
    $caseId = (int)($_POST['case_id'] ?? 0);
    $reason = trim((string)($_POST['reason'] ?? ''));
} else {
    $raw = file_get_contents('php://input') ?: '';
    $body = json_decode($raw, true);
    if (!is_array($body)) $body = [];

    $studentId = trim((string)($body['student_id'] ?? ''));
    $offenseId = (int)($body['offense_id'] ?? 0);
    $caseId = (int)($body['case_id'] ?? 0);
    $reason = trim((string)($body['reason'] ?? ''));
}

$attachmentPath = null;
$attachmentName = null;

if ($isMultipart && isset($_FILES['attachment']) && $_FILES['attachment']['error'] === UPLOAD_ERR_OK) {
    $fileTmpPath = $_FILES['attachment']['tmp_name'];
    $fileName = $_FILES['attachment']['name'];
    $fileSize = $_FILES['attachment']['size'];
    $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $allowedExts = ['pdf', 'jpg', 'jpeg', 'png'];
    
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $actualMime = finfo_file($finfo, $fileTmpPath);
    finfo_close($finfo);
    
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'application/pdf'];
    
    if (!in_array($ext, $allowedExts) || !in_array($actualMime, $allowedMimeTypes)) {
        json_out(false, 'Invalid file type. Only JPEG, PNG, and PDF are allowed.', null, 400);
    }
    
    if ($fileSize > 5 * 1024 * 1024) {
        json_out(false, 'File is too large. Maximum size is 5MB.', null, 400);
    }
    
    $uploadDir = __DIR__ . '/../../uploads/appeals/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    $newFileName = uniqid('appeal_') . '_' . preg_replace('/[^a-zA-Z0-9.\-_]/', '', basename($fileName));
    $destPath = $uploadDir . $newFileName;
    
    if (move_uploaded_file($fileTmpPath, $destPath)) {
        $attachmentPath = 'uploads/appeals/' . $newFileName;
        $attachmentName = basename($fileName);
    } else {
        json_out(false, 'Failed to save the uploaded file.', null, 500);
    }
}

if ($studentId === '' || ($offenseId <= 0 && $caseId <= 0)) {
  json_out(false, 'student_id and either offense_id or case_id are required.', null, 400);
}

if (mb_strlen($reason) < 10) {
  json_out(false, 'Appeal reason must be at least 10 characters.', null, 400);
}

$student = db_one(
  "SELECT student_id, is_active
   FROM student
   WHERE student_id = :sid
   LIMIT 1",
  [':sid' => $studentId]
);

if (!$student) json_out(false, 'Student not found.', null, 404);
if ((int)($student['is_active'] ?? 0) !== 1) json_out(false, 'Student is not active.', null, 403);

// Appeals are allowed even if account has restrictions

if (!db_one("SHOW TABLES LIKE 'student_appeal_request'")) {
  json_out(false, 'Appeal table is missing. Ask admin to create student_appeal_request first.', null, 500);
}

$appealKind = 'OFFENSE';
$targetLabel = 'offense';
$targetId = 0;

if ($caseId > 0) {
  $appealKind = 'UPCC_CASE';
  $targetLabel = 'UPCC case';
  $targetId = $caseId;

  $case = db_one(
    "SELECT case_id, status, decided_category, final_decision, resolution_date
     FROM upcc_case
     WHERE case_id = :cid
       AND student_id = :sid
     LIMIT 1",
    [':cid' => $caseId, ':sid' => $studentId]
  );

  if (!$case) {
    json_out(false, 'UPCC case record not found for this student.', null, 404);
  }

  $resolvedAt = strtotime((string)($case['resolution_date'] ?? ''));
  if ($resolvedAt > 0 && (time() - $resolvedAt) > (5 * 86400)) {
    json_out(false, 'The appeal window for this UPCC decision has expired.', null, 403);
  }

  if ((int)($case['decided_category'] ?? 0) < 1 || (int)($case['decided_category'] ?? 0) > 5) {
    json_out(false, 'This UPCC case does not have an appealable category.', null, 400);
  }

  $existingAppeal = db_one(
    "SELECT appeal_id
     FROM student_appeal_request
     WHERE student_id = :sid
       AND case_id = :cid
       AND appeal_kind = 'UPCC_CASE'
       AND status IN ('PENDING','REVIEWING')
     LIMIT 1",
    [':sid' => $studentId, ':cid' => $caseId]
  );

  if ($existingAppeal) {
    json_out(false, 'You already have an active appeal for this UPCC case.', [
      'appeal_id' => (int)$existingAppeal['appeal_id'],
    ], 409);
  }
} else {
  $appealKind = 'OFFENSE';
  $targetLabel = 'offense';
  $targetId = $offenseId;

  $offense = db_one(
    "SELECT offense_id, student_id, status, level
     FROM offense
     WHERE offense_id = :oid
       AND student_id = :sid
     LIMIT 1",
    [':oid' => $offenseId, ':sid' => $studentId]
  );

  if (!$offense) {
    json_out(false, 'Offense record not found for this student.', null, 404);
  }

  $status = strtoupper((string)($offense['status'] ?? ''));
  if ($status === 'VOID') {
    json_out(false, 'You cannot appeal a voided offense record.', null, 400);
  }

  if ($status === 'UNDER_APPEAL') {
    json_out(false, 'This offense is already under appeal.', null, 409);
  }

  $level = strtoupper((string)($offense['level'] ?? ''));
  if ($level !== 'MAJOR') {
    json_out(false, 'Appeals are only allowed for major offenses.', null, 403);
  }

  $existingAppeal = db_one(
    "SELECT appeal_id
     FROM student_appeal_request
     WHERE student_id = :sid
       AND offense_id = :oid
       AND appeal_kind = 'OFFENSE'
       AND status IN ('PENDING','REVIEWING')
     LIMIT 1",
    [':sid' => $studentId, ':oid' => $offenseId]
  );

  if ($existingAppeal) {
    json_out(false, 'You already have an active appeal for this offense.', [
      'appeal_id' => (int)$existingAppeal['appeal_id'],
    ], 409);
  }
}

try {
  db_exec(
    "INSERT INTO student_appeal_request (student_id, offense_id, case_id, appeal_kind, reason, status, created_at, attachment_path, attachment_name)
     VALUES (:sid, :oid, :cid, :kind, :reason, 'PENDING', NOW(), :apath, :aname)",
    [
      ':sid' => $studentId,
      ':oid' => $appealKind === 'OFFENSE' ? $targetId : null,
      ':cid' => $appealKind === 'UPCC_CASE' ? $targetId : null,
      ':kind' => $appealKind,
      ':reason' => $reason,
      ':apath' => $attachmentPath,
      ':aname' => $attachmentName,
    ]
  );
  $appealId = (int)db_last_id();

  if ($appealKind === 'OFFENSE') {
    db_exec(
      "UPDATE offense
       SET status = 'UNDER_APPEAL'
       WHERE offense_id = :oid
         AND student_id = :sid",
      [':oid' => $offenseId, ':sid' => $studentId]
    );

    $case = db_one(
      "SELECT uc.case_id
       FROM upcc_case uc
       JOIN upcc_case_offense uco ON uco.case_id = uc.case_id
       WHERE uc.student_id = :sid
         AND uco.offense_id = :oid
       ORDER BY uc.case_id DESC
       LIMIT 1",
      [':sid' => $studentId, ':oid' => $offenseId]
    );

    if (!$case) {
      db_exec(
        "INSERT INTO upcc_case (student_id, created_by, status, case_kind, case_summary, created_at, updated_at)
         VALUES (:sid, 0, 'UNDER_APPEAL', 'DIRECT_APPEAL', :summary, NOW(), NOW())",
        [
          ':sid' => $studentId,
          ':summary' => 'Student appeal submitted for offense #' . $offenseId,
        ]
      );

      $newCaseId = (int)db_last_id();
      db_exec(
        "INSERT INTO upcc_case_offense (case_id, offense_id) VALUES (:cid, :oid)",
        [':cid' => $newCaseId, ':oid' => $offenseId]
      );
    } else {
      db_exec(
        "UPDATE upcc_case
         SET status = 'UNDER_APPEAL', updated_at = NOW()
         WHERE case_id = :cid",
        [':cid' => (int)$case['case_id']]
      );
    }
  } else {
    // appealKind === 'UPCC_CASE'
    db_exec(
      "UPDATE upcc_case
       SET status = 'UNDER_APPEAL', updated_at = NOW()
       WHERE case_id = :cid
         AND student_id = :sid",
      [':cid' => $caseId, ':sid' => $studentId]
    );
  }

  json_out(true, 'Appeal submitted successfully. UPCC/Admin will review your request.', [
    'appeal_id' => $appealId,
    'offense_id' => $appealKind === 'OFFENSE' ? $targetId : null,
    'case_id' => $appealKind === 'UPCC_CASE' ? $targetId : null,
    'appeal_kind' => $appealKind,
    'status' => 'PENDING',
  ]);
} catch (Throwable $e) {
  json_out(false, 'Failed to submit appeal: ' . $e->getMessage(), null, 500);
}
