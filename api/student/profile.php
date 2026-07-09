<?php
declare(strict_types=1);

require_once __DIR__ . '/../../database/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
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

// In PHP, GET requests don't typically have raw input body, but POST does.
// Let's read both body and query parameters.
$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

$studentId = trim((string)($body['student_id'] ?? $_GET['student_id'] ?? $_POST['student_id'] ?? ''));
if ($studentId === '') json_out(false, 'student_id is required.', null, 400);

require_student_api_auth($studentId);

// Handle GET request to fetch profile
if ($_SERVER['REQUEST_METHOD'] === 'GET' || (isset($body['action']) && $body['action'] === 'get') || (isset($_GET['action']) && $_GET['action'] === 'get')) {
  $params = [':sid' => $studentId];
  db_add_encryption_key($params);

  $student = db_one(
    "SELECT student_id, student_fn, student_ln, student_email, phone_number
     FROM student
     WHERE student_id = :sid
     LIMIT 1",
    $params
  );

  if (!$student) {
    json_out(false, 'Student not found.', null, 404);
  }

  $guardian = db_one(
    "SELECT guardian_fn, guardian_ln, guardian_email, guardian_number
     FROM guardian
     WHERE student_id = :sid
     LIMIT 1",
    [':sid' => $studentId]
  );

  $profileData = [
    'student_id' => $student['student_id'],
    'student_fn' => $student['student_fn'],
    'student_ln' => $student['student_ln'],
    'student_email' => $student['student_email'],
    'phone_number' => $student['phone_number'] ?? '',
    'guardian_fn' => $guardian ? ($guardian['guardian_fn'] ?? '') : '',
    'guardian_ln' => $guardian ? ($guardian['guardian_ln'] ?? '') : '',
    'guardian_email' => $guardian ? ($guardian['guardian_email'] ?? '') : '',
    'guardian_number' => $guardian ? ($guardian['guardian_number'] ?? '') : '',
  ];

  json_out(true, 'Profile details loaded.', $profileData);
}

// Handle POST request to update profile
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $studentPhone = trim((string)($body['phone_number'] ?? ''));
  $guardianFn = trim((string)($body['guardian_fn'] ?? ''));
  $guardianLn = trim((string)($body['guardian_ln'] ?? ''));
  $guardianEmail = trim((string)($body['guardian_email'] ?? ''));
  $guardianPhone = trim((string)($body['guardian_number'] ?? $body['guardian_phone'] ?? ''));

  // Validation
  if ($studentPhone === '') {
    json_out(false, 'Student phone number is required.', null, 400);
  }
  if ($guardianFn === '') {
    json_out(false, 'Guardian first name is required.', null, 400);
  }
  if ($guardianLn === '') {
    json_out(false, 'Guardian last name is required.', null, 400);
  }
  if ($guardianEmail === '') {
    json_out(false, 'Guardian email is required.', null, 400);
  }
  if (!filter_var($guardianEmail, FILTER_VALIDATE_EMAIL)) {
    json_out(false, 'Guardian email is invalid.', null, 400);
  }
  if ($guardianPhone === '') {
    json_out(false, 'Guardian phone number is required.', null, 400);
  }

  // Update student phone number
  $params = [
    ':phone' => $studentPhone,
    ':sid' => $studentId,
  ];
  db_add_encryption_key($params);
  
  db_exec(
    "UPDATE student
     SET phone_number = :phone, updated_at = CURRENT_TIMESTAMP
     WHERE student_id = :sid",
    $params
  );

  // Check if guardian already exists
  $existingGuardian = db_one(
    "SELECT guardian_id FROM guardian WHERE student_id = :sid LIMIT 1",
    [':sid' => $studentId]
  );

  if ($existingGuardian) {
    db_exec(
      "UPDATE guardian
       SET guardian_fn = :gfn, guardian_ln = :gln, guardian_email = :gemail, guardian_number = :gnum, updated_at = CURRENT_TIMESTAMP
       WHERE student_id = :sid",
      [
        ':gfn' => $guardianFn,
        ':gln' => $guardianLn,
        ':gemail' => $guardianEmail,
        ':gnum' => $guardianPhone,
        ':sid' => $studentId
      ]
    );
  } else {
    db_exec(
      "INSERT INTO guardian (student_id, guardian_fn, guardian_ln, guardian_email, guardian_number, created_at, updated_at)
       VALUES (:sid, :gfn, :gln, :gemail, :gnum, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)",
      [
        ':sid' => $studentId,
        ':gfn' => $guardianFn,
        ':gln' => $guardianLn,
        ':gemail' => $guardianEmail,
        ':gnum' => $guardianPhone
      ]
    );
  }

  json_out(true, 'Profile updated successfully.');
}
