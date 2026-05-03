<?php
declare(strict_types=1);

require_once __DIR__ . '/../../database/database.php';
ensure_hearing_workflow_schema();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    json_out(false, 'Method not allowed.', null, 405);
}

$studentId = trim((string)($_POST['student_id'] ?? ''));
$caseId = (int)($_POST['case_id'] ?? 0);
$explanation = trim((string)($_POST['explanation_text'] ?? ''));

if ($studentId === '' || $caseId <= 0) {
    json_out(false, 'student_id and case_id are required.', null, 400);
}

$student = db_one("SELECT is_active FROM student WHERE student_id = :sid", [':sid' => $studentId]);
if (!$student) json_out(false, 'Student not found.', null, 404);
if ((int)($student['is_active'] ?? 0) !== 1) json_out(false, 'Student is not active.', null, 403);

$case = db_one("SELECT case_id, status FROM upcc_case WHERE case_id = :cid AND student_id = :sid", [
    ':cid' => $caseId,
    ':sid' => $studentId
]);

if (!$case) {
    json_out(false, 'Case not found or belongs to another student.', null, 404);
}

// Check if case is still in a state where explanation can be submitted
if (in_array($case['status'], ['RESOLVED', 'CLOSED', 'CANCELLED'])) {
    json_out(false, 'This case is already closed. Explanations can no longer be submitted.', null, 400);
}

$uploadDir = __DIR__ . '/../../uploads/explanations/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$imagePath = null;
$pdfPath = null;

// Handle Image Upload
if (isset($_FILES['explanation_image']) && $_FILES['explanation_image']['error'] === UPLOAD_ERR_OK) {
    $tmpName = $_FILES['explanation_image']['tmp_name'];
    $name = basename($_FILES['explanation_image']['name']);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        json_out(false, 'Invalid image format. Only JPG, JPEG, and PNG are allowed.', null, 400);
    }
    
    $newName = 'exp_' . $caseId . '_' . time() . '.' . $ext;
    if (move_uploaded_file($tmpName, $uploadDir . $newName)) {
        $imagePath = 'uploads/explanations/' . $newName;
    }
}

// Handle PDF Upload
if (isset($_FILES['explanation_pdf']) && $_FILES['explanation_pdf']['error'] === UPLOAD_ERR_OK) {
    $tmpName = $_FILES['explanation_pdf']['tmp_name'];
    $name = basename($_FILES['explanation_pdf']['name']);
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    
    if ($ext !== 'pdf') {
        json_out(false, 'Invalid file format. Only PDF is allowed for documents.', null, 400);
    }
    
    $newName = 'exp_' . $caseId . '_' . time() . '.pdf';
    if (move_uploaded_file($tmpName, $uploadDir . $newName)) {
        $pdfPath = 'uploads/explanations/' . $newName;
    }
}

if ($explanation === '' && !$imagePath && !$pdfPath) {
    json_out(false, 'Please provide an explanation (text, image, or PDF).', null, 400);
}

// Update the case
db_exec("UPDATE upcc_case SET 
    student_explanation_text = :text,
    student_explanation_image = :img,
    student_explanation_pdf = :pdf,
    student_explanation_at = NOW(),
    updated_at = NOW()
    WHERE case_id = :cid", [
    ':text' => $explanation ?: null,
    ':img' => $imagePath ?: null,
    ':pdf' => $pdfPath ?: null,
    ':cid' => $caseId
]);

upcc_log_case_activity($caseId, 'SYSTEM', 0, 'STUDENT_EXPLANATION_SUBMITTED', [
    'has_text' => !empty($explanation),
    'has_image' => !empty($imagePath),
    'has_pdf' => !empty($pdfPath)
]);

// Notify panel members that evidence is ready
upcc_send_explanation_notification($caseId);

json_out(true, 'Explanation submitted successfully.');
