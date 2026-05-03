<?php
require_once __DIR__ . '/../../database/database.php';
// For AJAX endpoints return JSON on auth failure instead of redirecting
// Do not call require_admin() (which redirects). Check session directly.
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: 0');
if (!admin_current()) {
    echo json_encode(['ok' => false, 'error' => 'auth', 'message' => 'Not authenticated']);
    exit;
}

$dept_id = (int)($_GET['dept_id'] ?? 0);
if (!$dept_id) {
    echo json_encode(['ok' => false, 'error' => 'No department']);
    exit;
}

// Fetch active members for the requested department
$members = db_all(
    "SELECT upcc_id, full_name, role, is_active, department_id
     FROM upcc_user
     WHERE department_id = :dept AND is_active = 1
     ORDER BY full_name",
    [':dept' => $dept_id]
);

// Return helpful debug fields so the client can confirm which dept the server saw
echo json_encode([
    'ok' => true,
    'dept_requested' => $dept_id,
    'members_count' => is_array($members) ? count($members) : 0,
    'members' => $members ?: [],
]);