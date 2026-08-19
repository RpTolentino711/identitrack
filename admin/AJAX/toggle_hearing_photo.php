<?php
require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$type = trim((string)($_POST['type'] ?? 'nte')); // 'nte', 'offense', or 'case'
$id = (int)($_POST['id'] ?? 0);
$show = (int)($_POST['show'] ?? 1); // 1 for YES, 0 for NO

if ($id <= 0) {
  echo json_encode(['ok' => false, 'message' => 'Invalid ID']);
  exit;
}

try {
  if ($type === 'nte') {
    db_exec("UPDATE nte_document SET show_in_hearing = :show WHERE nte_id = :id", [':show' => $show, ':id' => $id]);
  } elseif ($type === 'offense') {
    db_exec("UPDATE offense SET show_in_hearing = :show WHERE offense_id = :id", [':show' => $show, ':id' => $id]);
  } elseif ($type === 'case') {
    db_exec("UPDATE upcc_case SET show_in_hearing = :show WHERE case_id = :id", [':show' => $show, ':id' => $id]);
  }

  echo json_encode(['ok' => true, 'show' => $show, 'message' => 'Hearing photo status updated successfully.']);
} catch (\Throwable $e) {
  echo json_encode(['ok' => false, 'message' => 'Failed to update: ' . $e->getMessage()]);
}
