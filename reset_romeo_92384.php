<?php
ini_set('display_errors', '1');
error_reporting(E_ALL);

try {
    require_once __DIR__ . '/database/database.php';

    $email = 'romeotolentino804@gmail.com';

    $student = db_one("SELECT student_id FROM student WHERE email = :email", [':email' => $email]);

    if (!$student) {
        echo "Student not found!\n";
        exit;
    }
    $sid = $student['student_id'];

    // Get requirement IDs
    $reqs = db_all("SELECT requirement_id FROM community_service_requirement WHERE student_id = :sid", [':sid' => $sid]);
    $req_ids = array_column($reqs, 'requirement_id');

    if (!empty($req_ids)) {
        $inClause = implode(',', $req_ids);
        db_exec("DELETE FROM community_service_session WHERE requirement_id IN ($inClause)");
    }

    // Get case IDs
    $cases = db_all("SELECT case_id FROM upcc_case WHERE student_id = :sid", [':sid' => $sid]);
    $case_ids = array_column($cases, 'case_id');

    if (!empty($case_ids)) {
        $inClause = implode(',', $case_ids);
        db_exec("DELETE FROM upcc_case_vote WHERE case_id IN ($inClause)");
        db_exec("DELETE FROM upcc_case_vote_round WHERE case_id IN ($inClause)");
        db_exec("DELETE FROM upcc_case_panel_member WHERE case_id IN ($inClause)");
        db_exec("DELETE FROM upcc_case_offense WHERE case_id IN ($inClause)");
        db_exec("DELETE FROM upcc_case_note WHERE case_id IN ($inClause)");
        db_exec("DELETE FROM upcc_case_attachment WHERE case_id IN ($inClause)");
        db_exec("DELETE FROM upcc_hearing_presence WHERE case_id IN ($inClause)");
        try {
            db_exec("DELETE FROM upcc_case_panel_acceptance WHERE case_id IN ($inClause)");
        } catch(Exception $e) {}
    }

    db_exec("DELETE FROM notification WHERE student_id = :sid", [':sid' => $sid]);
    db_exec("DELETE FROM student_appeal_request WHERE student_id = :sid", [':sid' => $sid]);
    db_exec("DELETE FROM community_service_requirement WHERE student_id = :sid", [':sid' => $sid]);
    db_exec("DELETE FROM upcc_case WHERE student_id = :sid", [':sid' => $sid]);
    db_exec("DELETE FROM offense WHERE student_id = :sid", [':sid' => $sid]);

    db_exec("UPDATE student SET current_status = 'CLEAN', total_offense_count = 0 WHERE student_id = :sid", [':sid' => $sid]);

    echo "Student Romeo Paolo Tolentino ($sid) has been fully reset to CLEAN.";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
}
