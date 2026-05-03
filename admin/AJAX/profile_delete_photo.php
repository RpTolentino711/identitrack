<?php
// File: C:\xampp\htdocs\identitrack\admin\AJAX\profile_delete_photo.php
// Deletes current admin profile photo and clears stored DB path.

require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$admin = admin_current();
$adminId = (int)($admin['admin_id'] ?? $admin['id'] ?? 0);
if ($adminId <= 0) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'message' => 'Session expired.']);
  exit;
}

$reauthOk = !empty($_SESSION['profile_reauth_ok'])
  && (int)($_SESSION['profile_reauth_admin_id'] ?? 0) === $adminId
  && (time() - (int)($_SESSION['profile_reauth_at'] ?? 0) <= 1800);

if (!$reauthOk) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'message' => 'Re-auth required.']);
  exit;
}

$row = db_one("SELECT photo_path FROM admin_user WHERE admin_id = ? LIMIT 1", [$adminId]);
$currentPath = trim((string)($row['photo_path'] ?? ''));

if ($currentPath !== '') {
  $rootAbs = realpath(__DIR__ . '/../../');
  if ($rootAbs !== false) {
    $candidate = str_replace(['../', '..\\'], '', $currentPath);
    $candidate = ltrim($candidate, '/\\');
    $fileAbs = $rootAbs . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);

    // Delete only files under uploads/admin for safety.
    $uploadsAdminAbs = $rootAbs . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'admin' . DIRECTORY_SEPARATOR;
    if (strpos($fileAbs, $uploadsAdminAbs) === 0 && is_file($fileAbs)) {
      @unlink($fileAbs);
    }
  }
}

try {
  db_exec("UPDATE admin_user SET photo_path = NULL WHERE admin_id = ?", [$adminId]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Failed to clear profile photo.']);
  exit;
}

if (!isset($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
  $_SESSION['admin'] = [];
}
$_SESSION['admin']['photo_path'] = '';
$_SESSION['admin']['photo'] = '';
$_SESSION['admin']['profile_photo'] = '';

echo json_encode([
  'ok' => true,
  'message' => 'Profile photo deleted.',
  'photo_url' => '../assets/logo.png'
]);
exit;
