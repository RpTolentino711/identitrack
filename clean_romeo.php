<?php
require_once __DIR__ . '/database/database.php';

// Find the student by email
$student = db_one("SELECT student_id, student_fn, student_ln FROM student WHERE student_email = 'romeotolentino804@gmail.com'");
if (!$student) {
    echo "Student with email romeotolentino804@gmail.com not found.<br>";
    exit;
}

$sid = $student['student_id'];
echo "Found student: " . htmlspecialchars($student['student_fn'] . ' ' . $student['student_ln']) . " (ID: " . htmlspecialchars($sid) . ")<br><br>";

// List all tables referencing student_id or case_id
$tables = db_all("SHOW TABLES");
$allTables = [];
foreach ($tables as $t) {
    $allTables[] = array_values($t)[0];
}

$studentTables = [];
$caseTables = [];
foreach ($allTables as $table) {
    try {
        $cols = db_all("SHOW COLUMNS FROM `$table`");
        foreach ($cols as $c) {
            if ($c['Field'] === 'student_id') {
                $studentTables[] = $table;
            }
            if ($c['Field'] === 'case_id') {
                $caseTables[] = $table;
            }
        }
    } catch (Exception $e) {
        // Skip
    }
}

// Find cases
$cases = db_all("SELECT case_id FROM upcc_case WHERE student_id = :sid", [':sid' => $sid]);
$caseIds = array_map(fn($c) => (int)$c['case_id'], $cases);

db_exec("SET FOREIGN_KEY_CHECKS = 0;");

// Delete case related rows
if (!empty($caseIds)) {
    $caseIdsCsv = implode(',', $caseIds);
    foreach ($caseTables as $table) {
        if ($table === 'upcc_case') continue;
        db_exec("DELETE FROM `$table` WHERE case_id IN ($caseIdsCsv)");
        echo "Deleted from $table for cases ($caseIdsCsv)<br>";
    }
    db_exec("DELETE FROM upcc_case WHERE case_id IN ($caseIdsCsv)");
    echo "Deleted cases from upcc_case<br>";
}

// Delete student related rows
foreach ($studentTables as $table) {
    if ($table === 'student') continue;
    db_exec("DELETE FROM `$table` WHERE student_id = :sid", [':sid' => $sid]);
    echo "Deleted from $table for student $sid<br>";
}

// Reset student state fields
db_exec("UPDATE student SET scanner_id_hash = NULL, verification_status = 0 WHERE student_id = :sid", [':sid' => $sid]);
echo "Reset student table state for $sid<br>";

db_exec("SET FOREIGN_KEY_CHECKS = 1;");
echo "<br><b>Done cleaning! Please delete this file or remove it from git.</b><br>";
