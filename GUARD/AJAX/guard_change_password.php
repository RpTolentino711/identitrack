<?php
// File: Guard Change Password AJAX
// Allow guards to change their password

require_once __DIR__ . '/../../database/database.php';

session_start();
if (!isset($_SESSION['guard_logged_in']) || $_SESSION['guard_logged_in'] !== true) {
  echo json_encode(['ok' => false, 'message' => 'Not authenticated.']);
  exit;
}

header('Content-Type: application/json; charset=utf-8');

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) {
  echo json_encode(['ok' => false, 'message' => 'Invalid request body.']);
  exit;
}

$guardId = (int)($_SESSION['guard_id'] ?? 0);
$current_password = (string)($data['current_password'] ?? '');
$new_password = (string)($data['new_password'] ?? '');
$confirm_password = (string)($data['confirm_password'] ?? '');

if ($guardId <= 0) {
  echo json_encode(['ok' => false, 'message' => 'Invalid session.']);
  exit;
}

// Validation
if ($current_password === '') {
  echo json_encode(['ok' => false, 'message' => 'Current password is required.']);
  exit;
}

if ($new_password === '') {
  echo json_encode(['ok' => false, 'message' => 'New password is required.']);
  exit;
}

if (strlen($new_password) < 8) {
  echo json_encode(['ok' => false, 'message' => 'Password must be at least 8 characters.']);
  exit;
}

if ($new_password !== $confirm_password) {
  echo json_encode(['ok' => false, 'message' => 'Passwords do not match.']);
  exit;
}

// Verify current password
$guard = db_one(
  "SELECT password_hash FROM security_guard WHERE guard_id = ?",
  [$guardId]
);

if (!$guard) {
  echo json_encode(['ok' => false, 'message' => 'Guard not found.']);
  exit;
}

if (!password_verify($current_password, $guard['password_hash'])) {
  echo json_encode(['ok' => false, 'message' => 'Current password is incorrect.']);
  exit;
}

// Hash new password
$new_hash = password_hash($new_password, PASSWORD_BCRYPT);

try {
  // Update password
  $stmt = $GLOBALS['conn']->prepare(
    "UPDATE security_guard SET password_hash = ?, updated_at = NOW() WHERE guard_id = ?"
  );
  
  if (!$stmt) {
    echo json_encode(['ok' => false, 'message' => 'Database error: ' . $GLOBALS['conn']->error]);
    exit;
  }

  $stmt->bind_param('si', $new_hash, $guardId);

  if ($stmt->execute()) {
    echo json_encode([
      'ok' => true,
      'message' => 'Password changed successfully.'
    ]);
  } else {
    echo json_encode(['ok' => false, 'message' => 'Failed to update password: ' . $stmt->error]);
  }
  $stmt->close();
} catch (Exception $e) {
  echo json_encode(['ok' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
exit;
?>
