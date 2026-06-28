<?php
require_once __DIR__ . '/database/database.php';

try {
    $email = 'romeotolentino804@gmail.com';

    $student = db_one("SELECT student_id FROM student WHERE student_email = :email", [':email' => $email]);

    if (!$student) {
        echo "Student not found!\n";
        exit;
    }

    $sid = $student['student_id'];

    $cases = db_all("SELECT case_id FROM upcc_case WHERE student_id = :sid", [':sid' => $sid]);
    $case_ids = array_column($cases, 'case_id');

    if (!empty($case_ids)) {
        $inClause = implode(',', $case_ids);
        db_exec("DELETE FROM upcc_case_discussion WHERE case_id IN ($inClause)");
        db_exec("DELETE FROM upcc_case_activity WHERE case_id IN ($inClause)");
    }

    echo "Cleared chats for Romeo's cases.";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine();
}
