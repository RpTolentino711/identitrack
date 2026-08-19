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
  $uploadedFilePath = null;
  if (isset($_FILES['photo_file']) && $_FILES['photo_file']['error'] === UPLOAD_ERR_OK) {
    $uploadDir = __DIR__ . '/../../uploads/incident_reports/';
    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0777, true);
    $ext = strtolower(pathinfo($_FILES['photo_file']['name'], PATHINFO_EXTENSION));
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'pdf'], true)) {
      $filename = 'evidence_' . time() . '_' . uniqid() . '.' . $ext;
      if (move_uploaded_file($_FILES['photo_file']['tmp_name'], $uploadDir . $filename)) {
        $uploadedFilePath = 'uploads/incident_reports/' . $filename;
      }
    }
  }

  if ($type === 'nte') {
    db_exec("UPDATE nte_document SET show_in_hearing = :show WHERE nte_id = :id", [':show' => $show, ':id' => $id]);
  } elseif ($type === 'offense') {
    if ($uploadedFilePath) {
      db_exec("UPDATE offense SET show_in_hearing = :show, evidence_file = :evfile WHERE offense_id = :id", [':show' => $show, ':evfile' => $uploadedFilePath, ':id' => $id]);
    } else {
      db_exec("UPDATE offense SET show_in_hearing = :show WHERE offense_id = :id", [':show' => $show, ':id' => $id]);
    }
  } elseif ($type === 'case') {
    if ($uploadedFilePath) {
      db_exec("UPDATE upcc_case SET show_in_hearing = :show, evidence_file = :evfile WHERE case_id = :id", [':show' => $show, ':evfile' => $uploadedFilePath, ':id' => $id]);
    } else {
      db_exec("UPDATE upcc_case SET show_in_hearing = :show WHERE case_id = :id", [':show' => $show, ':id' => $id]);
    }
  }

  echo json_encode(['ok' => true, 'show' => $show, 'message' => 'Hearing photo status updated successfully.']);
} catch (\Throwable $e) {
  echo json_encode(['ok' => false, 'message' => 'Failed to update: ' . $e->getMessage()]);
}
