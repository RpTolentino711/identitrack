<?php
include 'database/database.php';
$email = 'romeotolentino804@gmail.com';

$pdo = db();
$pdo->exec("SET FOREIGN_KEY_CHECKS = 0");

// 1. Find Student ID
$student = db_one("SELECT student_id FROM student WHERE student_email = :email", [':email' => $email]);

if (!$student) {
    echo "Student not found.\n";
    exit;
}

$studentId = $student['student_id'];
echo "Found Student ID: $studentId\n";

// 2. Delete Offenses
db_exec("DELETE FROM offense WHERE student_id = :sid", [':sid' => $studentId]);
echo "Deleted all offenses.\n";

// 3. Delete Community Service Data
$reqs = db_all("SELECT requirement_id FROM community_service_requirement WHERE student_id = :sid", [':sid' => $studentId]);
foreach ($reqs as $r) {
    $rid = $r['requirement_id'];
    db_exec("DELETE FROM community_service_session WHERE requirement_id = :rid", [':rid' => $rid]);
}
db_exec("DELETE FROM community_service_requirement WHERE student_id = :sid", [':sid' => $studentId]);
echo "Deleted community service data.\n";

// 4. Delete Appeals
db_exec("DELETE FROM student_appeal_request WHERE student_id = :sid", [':sid' => $studentId]);
echo "Deleted student appeal requests.\n";

// 5. Delete UPCC Cases
$cases = db_all("SELECT case_id FROM upcc_case WHERE student_id = :sid", [':sid' => $studentId]);
foreach ($cases as $c) {
    $cid = $c['case_id'];
    db_exec("DELETE FROM upcc_case_offense WHERE case_id = :cid", [':cid' => $cid]);
    db_exec("DELETE FROM upcc_case_panel_member WHERE case_id = :cid", [':cid' => $cid]);
    db_exec("DELETE FROM upcc_case_activity WHERE case_id = :cid", [':cid' => $cid]);
    db_exec("DELETE FROM upcc_case_vote WHERE case_id = :cid", [':cid' => $cid]);
    db_exec("DELETE FROM upcc_case_vote_round WHERE case_id = :cid", [':cid' => $cid]);
    db_exec("DELETE FROM upcc_hearing_presence WHERE case_id = :cid", [':cid' => $cid]);
    db_exec("DELETE FROM upcc_case WHERE case_id = :cid", [':cid' => $cid]);
}
echo "Deleted all UPCC cases for this student.\n";

$pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
echo "Cleanup complete for $email.\n";
?>
