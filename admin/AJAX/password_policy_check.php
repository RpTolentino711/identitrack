<?php
// Checks password rules via AJAX (JSON)
// Rules: min 8, uppercase, lowercase, number, special, and must match confirm

require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');
if (session_status() === PHP_SESSION_NONE) session_start();

// Optional: require profile reauth
if (empty($_SESSION['profile_reauth_ok'])) {
  http_response_code(401);
  echo json_encode(['ok' => false, 'message' => 'Re-auth required.']);
  exit;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

$pw = (string)($body['password'] ?? '');
$confirm = (string)($body['confirm'] ?? '');

$errors = [];

if (strlen($pw) < 8) $errors[] = 'At least 8 characters';
if (!preg_match('/[A-Z]/', $pw)) $errors[] = 'At least 1 uppercase letter';
if (!preg_match('/[a-z]/', $pw)) $errors[] = 'At least 1 lowercase letter';
if (!preg_match('/[0-9]/', $pw)) $errors[] = 'At least 1 number';
if (!preg_match('/[^A-Za-z0-9]/', $pw)) $errors[] = 'At least 1 special character';
if ($pw === '' || $pw !== $confirm) $errors[] = 'New and confirm must match';

if ($errors) {
  echo json_encode(['ok' => false, 'message' => 'Missing: ' . implode(', ', $errors)]);
  exit;
}

echo json_encode(['ok' => true]);
exit;