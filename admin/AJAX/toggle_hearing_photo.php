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

  if ($uploadedFilePath && $id > 0) {
    if ($type === 'nte') {
      db_exec("UPDATE notice_to_explain SET show_in_hearing = :show WHERE nte_id = :id", [':show' => $show, ':id' => $id]);
      $nteRow = db_one("SELECT case_id, offense_id, student_id FROM notice_to_explain WHERE nte_id = :id LIMIT 1", [':id' => $id]);
      if (!empty($nteRow['offense_id'])) {
        db_exec("UPDATE offense SET evidence_file = :evfile, show_in_hearing = 1 WHERE offense_id = :oid", [':evfile' => $uploadedFilePath, ':oid' => (int)$nteRow['offense_id']]);
      }
      if (!empty($nteRow['case_id'])) {
        db_exec("UPDATE upcc_case SET evidence_file = :evfile, show_in_hearing = 1 WHERE case_id = :cid", [':evfile' => $uploadedFilePath, ':cid' => (int)$nteRow['case_id']]);
      }
      if (!empty($nteRow['student_id'])) {
        db_exec("UPDATE offense SET evidence_file = :evfile WHERE student_id = :sid AND (evidence_file IS NULL OR evidence_file = '') ORDER BY offense_id DESC LIMIT 1", [':evfile' => $uploadedFilePath, ':sid' => $nteRow['student_id']]);
        db_exec("UPDATE upcc_case SET evidence_file = :evfile WHERE student_id = :sid AND (evidence_file IS NULL OR evidence_file = '') ORDER BY case_id DESC LIMIT 1", [':evfile' => $uploadedFilePath, ':sid' => $nteRow['student_id']]);
      }
    } elseif ($type === 'offense') {
      db_exec("UPDATE offense SET show_in_hearing = :show, evidence_file = :evfile WHERE offense_id = :id", [':show' => $show, ':evfile' => $uploadedFilePath, ':id' => $id]);
      $ucoRow = db_one("SELECT student_id FROM offense WHERE offense_id = :id LIMIT 1", [':id' => $id]);
      $ucoCase = db_one("SELECT case_id FROM upcc_case_offense WHERE offense_id = :id LIMIT 1", [':id' => $id]);
      $cid = !empty($ucoCase['case_id']) ? (int)$ucoCase['case_id'] : 0;
      if ($cid > 0) {
        db_exec("UPDATE upcc_case SET evidence_file = :evfile, show_in_hearing = 1 WHERE case_id = :cid", [':evfile' => $uploadedFilePath, ':cid' => $cid]);
      }
      if (!empty($ucoRow['student_id'])) {
        db_exec("UPDATE upcc_case SET evidence_file = :evfile WHERE student_id = :sid AND (evidence_file IS NULL OR evidence_file = '') ORDER BY case_id DESC LIMIT 1", [':evfile' => $uploadedFilePath, ':sid' => $ucoRow['student_id']]);
      }
    } elseif ($type === 'case') {
      db_exec("UPDATE upcc_case SET show_in_hearing = :show, evidence_file = :evfile WHERE case_id = :id", [':show' => $show, ':evfile' => $uploadedFilePath, ':id' => $id]);
      db_exec("UPDATE offense SET evidence_file = :evfile WHERE offense_id IN (SELECT offense_id FROM upcc_case_offense WHERE case_id = :cid)", [':evfile' => $uploadedFilePath, ':cid' => $id]);
      $cRow = db_one("SELECT student_id FROM upcc_case WHERE case_id = :id LIMIT 1", [':id' => $id]);
      if (!empty($cRow['student_id'])) {
        db_exec("UPDATE offense SET evidence_file = :evfile WHERE student_id = :sid AND (evidence_file IS NULL OR evidence_file = '') ORDER BY offense_id DESC LIMIT 1", [':evfile' => $uploadedFilePath, ':sid' => $cRow['student_id']]);
      }
    }
  }

  if (session_status() === PHP_SESSION_NONE) session_start();
  if ($id > 0) {
    $_SESSION['evidence_done_' . $id] = true;
    unset($_SESSION['pending_evidence_offense_id'], $_SESSION['pending_nte_offense_id'], $_SESSION['pending_letter_offense_id']);
  }

  echo json_encode(['ok' => true, 'show' => $show, 'message' => 'Hearing photo status updated successfully.']);
} catch (\Throwable $e) {
  echo json_encode(['ok' => false, 'message' => 'Failed to update: ' . $e->getMessage()]);
}
