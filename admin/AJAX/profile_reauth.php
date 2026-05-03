<?php
// File: C:\xampp\htdocs\identitrack\admin\AJAX\profile_reauth.php
// Verify current admin password for profile gate.

require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '{}', true);
if (!is_array($data)) {
  echo json_encode(['ok' => false, 'message' => 'Invalid request body.']);
  exit;
}

$password = trim((string)($data['password'] ?? ''));
if ($password === '') {
  echo json_encode(['ok' => false, 'message' => 'Password is required.']);
  exit;
}

$admin = admin_current();
$adminId = (int)($admin['admin_id'] ?? $admin['id'] ?? 0);
if ($adminId <= 0) {
  echo json_encode(['ok' => false, 'message' => 'Session expired. Please log in again.']);
  exit;
}

$isValid = admin_verify_password($adminId, $password);
if (!$isValid) {
  echo json_encode(['ok' => false, 'message' => 'Invalid password.']);
  exit;
}

// Mark profile re-auth as successful for subsequent protected AJAX actions.
$_SESSION['profile_reauth_ok'] = true;
$_SESSION['profile_reauth_admin_id'] = $adminId;
$_SESSION['profile_reauth_at'] = time();

echo json_encode(['ok' => true]);
