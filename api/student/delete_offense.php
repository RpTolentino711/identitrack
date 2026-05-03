<?php
declare(strict_types=1);

require_once __DIR__ . '/../../database/database.php';

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

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

$studentId = trim((string)($body['student_id'] ?? ''));
$offenseId = (int)($body['offense_id'] ?? 0);

if ($studentId === '' || $offenseId === 0) {
    json_out(false, 'student_id and offense_id are required.', null, 400);
}

try {
    // If it's a virtual bundle (Section 4 Major), find the linked case
    if ($offenseId < 0) {
        // Find the most recent Section 4 case for this student
        $vCase = db_one("SELECT case_id FROM upcc_case WHERE student_id = :sid AND case_kind = 'SECTION4_MINOR_ESCALATION' AND status <> 'VOID' ORDER BY case_id DESC LIMIT 1", [':sid' => $studentId]);
        if (!$vCase) {
            json_out(false, 'Major case bundle not found.');
        }
        $caseId = (int)$vCase['case_id'];
        
        // Find all offenses linked to this case
        $linkedOffenses = db_all("SELECT offense_id FROM upcc_case_offense WHERE case_id = :cid", [':cid' => $caseId]);
        
        // HARD DELETE everything related to this case
        db_exec("DELETE FROM upcc_case_offense WHERE case_id = :cid", [':cid' => $caseId]);
        db_exec("DELETE FROM upcc_case_vote WHERE case_id = :cid", [':cid' => $caseId]);
        db_exec("DELETE FROM upcc_case_discussion WHERE case_id = :cid", [':cid' => $caseId]);
        db_exec("DELETE FROM upcc_case_activity WHERE case_id = :cid", [':cid' => $caseId]);
        db_exec("DELETE FROM upcc_hearing_presence WHERE case_id = :cid", [':cid' => $caseId]);
        db_exec("DELETE FROM student_appeal_request WHERE case_id = :cid", [':cid' => $caseId]);
        db_exec("DELETE FROM community_service_session WHERE requirement_id IN (SELECT requirement_id FROM community_service_requirement WHERE case_id = :cid)", [':cid' => $caseId]);
        db_exec("DELETE FROM community_service_requirement WHERE case_id = :cid", [':cid' => $caseId]);
        db_exec("DELETE FROM upcc_case WHERE case_id = :cid", [':cid' => $caseId]);
        
        // HARD DELETE all offenses that were in this bundle
        foreach ($linkedOffenses as $lo) {
            $oid = (int)$lo['offense_id'];
            db_exec("DELETE FROM student_appeal_request WHERE offense_id = :oid", [':oid' => $oid]);
            db_exec("DELETE FROM guard_violation_report WHERE offense_id = :oid", [':oid' => $oid]);
            db_exec("DELETE FROM offense WHERE offense_id = :oid", [':oid' => $oid]);
        }
        
        json_out(true, 'Major case bundle and all linked offenses permanently deleted.');
    }
    // Check if it's a bundle (Major case with linked offenses)
    // Actually, we'll just look for a case that includes this offense
    $linkedCase = db_one("SELECT case_id FROM upcc_case_offense WHERE offense_id = :oid LIMIT 1", [':oid' => $offenseId]);
    
    if ($linkedCase) {
        $caseId = (int)$linkedCase['case_id'];
        
        // HARD DELETE everything related to this case
        db_exec("DELETE FROM upcc_case_offense WHERE case_id = :cid", [':cid' => $caseId]);
        db_exec("DELETE FROM upcc_case_vote WHERE case_id = :cid", [':cid' => $caseId]);
        db_exec("DELETE FROM upcc_case_discussion WHERE case_id = :cid", [':cid' => $caseId]);
        db_exec("DELETE FROM upcc_case_activity WHERE case_id = :cid", [':cid' => $caseId]);
        db_exec("DELETE FROM upcc_hearing_presence WHERE case_id = :cid", [':cid' => $caseId]);
        db_exec("DELETE FROM student_appeal_request WHERE case_id = :cid", [':cid' => $caseId]);
        db_exec("DELETE FROM community_service_session WHERE requirement_id IN (SELECT requirement_id FROM community_service_requirement WHERE case_id = :cid)", [':cid' => $caseId]);
        db_exec("DELETE FROM community_service_requirement WHERE case_id = :cid", [':cid' => $caseId]);
        db_exec("DELETE FROM upcc_case WHERE case_id = :cid", [':cid' => $caseId]);
    }

    // HARD DELETE the offense itself
    // Also delete any related appeals if not already handled by case delete
    db_exec("DELETE FROM student_appeal_request WHERE offense_id = :oid", [':oid' => $offenseId]);
    db_exec("DELETE FROM guard_violation_report WHERE offense_id = :oid", [':oid' => $offenseId]);
    
    $deleted = db_exec("DELETE FROM offense WHERE offense_id = :oid AND student_id = :sid", [
        ':oid' => $offenseId,
        ':sid' => $studentId
    ]);

    if ($deleted > 0) {
        json_out(true, 'Offense permanently deleted.');
    } else {
        json_out(false, 'Offense not found or already deleted.');
    }

} catch (Exception $e) {
    json_out(false, 'Database error: ' . $e->getMessage());
}
