<?php
// File: C:\xampp\htdocs\identitrack\admin\AJAX\profile_upload_photo.php
// FIXED: Actually upload + save admin profile photo
// - accepts multipart/form-data "photo"
// - validates type + size
// - stores file in: /uploads/admin/
// - updates DB column (change column name if needed)
// - returns JSON with new photo URL

require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

// If your database.php already session_start() you can remove this,
// but calling it twice is safe in PHP (it will just ignore).
if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

$admin = admin_current();
$adminId = (int)($admin['admin_id'] ?? $admin['id'] ?? 0);

// Require recent re-auth bound to this admin session.
$reauthOk = !empty($_SESSION['profile_reauth_ok'])
  && (int)($_SESSION['profile_reauth_admin_id'] ?? 0) === $adminId
  && (time() - (int)($_SESSION['profile_reauth_at'] ?? 0) <= 1800);

if (!$reauthOk) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'message' => 'Re-auth required.']); exit;
}

if (!isset($_FILES['photo'])) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'No file found (photo).']); exit;
}

$f = $_FILES['photo'];

if (!empty($f['error'])) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Upload error code: ' . (int)$f['error']]); exit;
}

$maxBytes = 3 * 1024 * 1024; // 3MB
if ((int)$f['size'] > $maxBytes) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'File too large. Max 3MB.']); exit;
}

// Detect MIME safely
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $f['tmp_name']) : '';
if ($finfo) finfo_close($finfo);

$allowed = [
  'image/jpeg' => 'jpg',
  'image/png'  => 'png',
  'image/webp' => 'webp',
];

if (!isset($allowed[$mime])) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Invalid file type. Please upload JPG/PNG/WEBP.']); exit;
}

$ext = $allowed[$mime];

// Ensure upload folder exists
$uploadDirAbs = realpath(__DIR__ . '/../../') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'admin';
if ($uploadDirAbs === false) {
  // If realpath fails (folder doesn't exist yet), build path manually
  $uploadDirAbs = __DIR__ . '/../../uploads/admin';
}

if (!is_dir($uploadDirAbs)) {
  if (!mkdir($uploadDirAbs, 0775, true)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Failed to create uploads folder.']); exit;
  }
}

// Build file name
$filename = 'admin_' . $adminId . '_' . date('Ymd_His') . '.' . $ext;
$destAbs = rtrim($uploadDirAbs, '/\\') . DIRECTORY_SEPARATOR . $filename;

// Move file
if (!move_uploaded_file($f['tmp_name'], $destAbs)) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'message' => 'Failed to save uploaded file.']); exit;
}

// Public path used in HTML <img src="...">
// Adjust if your project uses a different base path
$destRel = '../uploads/admin/' . $filename;

// Persist file path to admin_user photo column if available.
// This project authenticates against admin_user, not admin.
$photoCol = null;
$candidateCols = ['photo_path', 'photo', 'profile_photo', 'avatar', 'image_path'];

foreach ($candidateCols as $col) {
  $hasCol = db_one(
    "SELECT 1 AS ok
     FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'admin_user'
       AND COLUMN_NAME = ?
     LIMIT 1",
    [$col]
  );
  if ($hasCol) {
    $photoCol = $col;
    break;
  }
}

if ($photoCol !== null) {
  try {
    db_exec("UPDATE admin_user SET {$photoCol} = ? WHERE admin_id = ?", [$destRel, $adminId]);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'message' => 'Upload saved but failed to update profile photo in database.']);
    exit;
  }
}

// Keep current session avatar in sync even if DB has no photo column.
if (!isset($_SESSION['admin']) || !is_array($_SESSION['admin'])) {
  $_SESSION['admin'] = [];
}
$_SESSION['admin']['photo'] = $destRel;
$_SESSION['admin']['photo_path'] = $destRel;
$_SESSION['admin']['profile_photo'] = $destRel;

echo json_encode([
  'ok' => true,
  'message' => ($photoCol !== null)
    ? 'Photo updated.'
    : 'Photo uploaded. Database photo column not found; using session preview.',
  'photo_url' => $destRel . '?v=' . urlencode((string)time()), // cache-buster
]);
exit;