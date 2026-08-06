<?php
// File: admin/api_send_nte_form.php
require_once __DIR__ . '/../database/database.php';
require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$admin = admin_current();
$adminName = trim((string)($admin['full_name'] ?? $admin['username'] ?? 'Discipline Officer'));

$caseId = (int)($_POST['case_id'] ?? 0);
$offenseId = (int)($_POST['offense_id'] ?? 0);
$studentId = trim((string)($_POST['student_id'] ?? ''));

if ($studentId === '') {
    echo json_encode(['ok' => false, 'error' => 'Student ID is required.']);
    exit;
}

$irNo = trim((string)($_POST['incident_report_no'] ?? ''));
$alleged = trim((string)($_POST['alleged_details'] ?? ''));
$section = trim((string)($_POST['handbook_section'] ?? ''));
$page = trim((string)($_POST['handbook_page'] ?? ''));
$instructions = trim((string)($_POST['custom_instructions'] ?? ''));
$signature = trim((string)($_POST['admin_signature'] ?? $adminName));

ensure_notice_to_explain_table();

// Save or Replace Form F-005 record
$existing = null;
if ($caseId > 0) {
    $existing = db_one("SELECT nte_id FROM notice_to_explain WHERE case_id = :cid LIMIT 1", [':cid' => $caseId]);
} else if ($offenseId > 0) {
    $existing = db_one("SELECT nte_id FROM notice_to_explain WHERE offense_id = :oid LIMIT 1", [':oid' => $offenseId]);
}

if ($existing) {
    db_exec("
        UPDATE notice_to_explain 
        SET incident_report_no = :ir,
            alleged_details = :alleged,
            handbook_section = :sec,
            handbook_page = :page,
            custom_instructions = :inst,
            admin_signature = :sig,
            status = 'SENT',
            updated_at = NOW()
        WHERE nte_id = :nid
    ", [
        ':ir' => $irNo,
        ':alleged' => $alleged,
        ':sec' => $section,
        ':page' => $page,
        ':inst' => $instructions,
        ':sig' => $signature,
        ':nid' => (int)$existing['nte_id']
    ]);
    $nteId = (int)$existing['nte_id'];
} else {
    db_exec("
        INSERT INTO notice_to_explain (case_id, offense_id, student_id, incident_report_no, alleged_details, handbook_section, handbook_page, custom_instructions, admin_signature, status, created_at, updated_at)
        VALUES (:cid, :oid, :sid, :ir, :alleged, :sec, :page, :inst, :sig, 'SENT', NOW(), NOW())
    ", [
        ':cid' => $caseId > 0 ? $caseId : null,
        ':oid' => $offenseId > 0 ? $offenseId : null,
        ':sid' => $studentId,
        ':ir' => $irNo,
        ':alleged' => $alleged,
        ':sec' => $section,
        ':page' => $page,
        ':inst' => $instructions,
        ':sig' => $signature
    ]);
    $nteId = (int)db_last_id();
}

// Send Notification to Student
try {
    db_exec("
        INSERT INTO notification (type, title, message, student_id, admin_id, related_table, related_id, is_read, is_deleted, created_at)
        VALUES ('FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, you have been issued a Notice to Explain (Form F-005). Please submit your written explanation within 5 days.', :sid, :aid, 'notice_to_explain', :nid, 0, 0, NOW())
    ", [
        ':sid' => $studentId,
        ':aid' => (int)($admin['admin_id'] ?? 0),
        ':nid' => (string)$nteId
    ]);
} catch (\Throwable $e) {}

echo json_encode([
    'ok' => true,
    'nte_id' => $nteId,
    'message' => 'Notice to Explain (Form F-005) sent to student successfully!'
]);
