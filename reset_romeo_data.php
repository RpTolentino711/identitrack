<?php
// Bypass env for local testing
$_ENV['DB_HOST'] = 'localhost';
$_ENV['DB_NAME'] = 'identitrack';
$_ENV['DB_USER'] = 'root';
$_ENV['DB_PASS'] = '';
$_SERVER['DB_HOST'] = 'localhost';
$_SERVER['DB_NAME'] = 'identitrack';
$_SERVER['DB_USER'] = 'root';
$_SERVER['DB_PASS'] = '';

require_once __DIR__ . '/database/database.php';

$sql = "
    SELECT student_id,
           AES_DECRYPT(student_fn, UNHEX(SHA2(:key, 256))) AS student_fn,
           AES_DECRYPT(student_ln, UNHEX(SHA2(:key, 256))) AS student_ln,
           AES_DECRYPT(student_email, UNHEX(SHA2(:key, 256))) AS student_email
    FROM student
    WHERE CAST(AES_DECRYPT(student_fn, UNHEX(SHA2(:key, 256))) AS CHAR) LIKE '%Romeo%'
       OR CAST(AES_DECRYPT(student_email, UNHEX(SHA2(:key, 256))) AS CHAR) LIKE '%romeo%'
";

$students = db_all($sql, [':key' => db_encryption_key()]);

if (!$students) {
    die("No students found.");
}

foreach ($students as $student) {
    $id = $student['student_id'];
    echo "Resetting data for " . $student['student_fn'] . " " . $student['student_ln'] . " (ID: $id)...\n";

    // Delete any active cases involving this student in UPCC
    try {
        $cases = db_all("SELECT case_id FROM upcc_case WHERE student_id = :id", [':id' => $id]);
        foreach ($cases as $c) {
            $cid = $c['case_id'];
            db_exec("DELETE FROM upcc_case_activity WHERE case_id = :cid", [':cid' => $cid]);
            db_exec("DELETE FROM upcc_case_discussion WHERE case_id = :cid", [':cid' => $cid]);
            db_exec("DELETE FROM upcc_case_document WHERE case_id = :cid", [':cid' => $cid]);
            db_exec("DELETE FROM upcc_case_panel_member WHERE case_id = :cid", [':cid' => $cid]);
            db_exec("DELETE FROM upcc_case_vote WHERE case_id = :cid", [':cid' => $cid]);
            db_exec("DELETE FROM upcc_case_vote_round WHERE case_id = :cid", [':cid' => $cid]);
            db_exec("DELETE FROM upcc_case_panel_acceptance WHERE case_id = :cid", [':cid' => $cid]);
            db_exec("DELETE FROM upcc_hearing_presence WHERE case_id = :cid", [':cid' => $cid]);
            db_exec("DELETE FROM upcc_suggestion_cooldown WHERE case_id = :cid", [':cid' => $cid]);
            db_exec("DELETE FROM upcc_panel_rejoin_requests WHERE case_id = :cid", [':cid' => $cid]);
            db_exec("DELETE FROM upcc_case_offense WHERE case_id = :cid", [':cid' => $cid]);
        }
        db_exec("DELETE FROM upcc_case WHERE student_id = :id", [':id' => $id]);
        echo "- Deleted UPCC cases\n";
    } catch (Exception $e) {
        echo "- Notice: Could not delete UPCC cases: " . $e->getMessage() . "\n";
    }

    // Delete guard reports
    db_exec("DELETE FROM guard_violation_report WHERE student_id = :id", [':id' => $id]);
    echo "- Deleted guard reports\n";

    // Delete community service
    db_exec("DELETE FROM community_service_session WHERE requirement_id IN (SELECT requirement_id FROM community_service_requirement WHERE student_id = :id)", [':id' => $id]);
    db_exec("DELETE FROM community_service_requirement WHERE student_id = :id", [':id' => $id]);
    echo "- Deleted community service\n";

    // Delete all offenses
    db_exec("DELETE FROM student_appeal_request WHERE student_id = :id", [':id' => $id]);
    db_exec("DELETE FROM upcc_case_offense WHERE offense_id IN (SELECT offense_id FROM offense WHERE student_id = :id)", [':id' => $id]);
    db_exec("DELETE FROM offense WHERE student_id = :id", [':id' => $id]);
    echo "- Deleted offenses\n";
}
echo "Reset complete!\n";
