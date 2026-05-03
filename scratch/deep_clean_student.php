<?php
require_once __DIR__ . '/../database/database.php';

$email = 'romeotolentino804@gmail.com';
$student = db_one("SELECT student_id FROM student WHERE student_email = ?", [$email]);

if (!$student) {
    die("Student not found with email: $email");
}

$sid = $student['student_id'];
echo "CLEANING EVERYTHING for student ID: $sid\n";

// Disable foreign key checks temporarily for a deep clean
db_exec("SET FOREIGN_KEY_CHECKS = 0");

try {
    // 1. UPCC Case Data (Discussions, Votes, Rounds, Activity, etc.)
    echo "Deleting detailed UPCC records...\n";
    $caseIds = db_all("SELECT case_id FROM upcc_case WHERE student_id = ?", [$sid]);
    foreach ($caseIds as $c) {
        $cid = $c['case_id'];
        db_exec("DELETE FROM upcc_case_discussion WHERE case_id = ?", [$cid]);
        db_exec("DELETE FROM upcc_case_vote WHERE case_id = ?", [$cid]);
        db_exec("DELETE FROM upcc_case_vote_round WHERE case_id = ?", [$cid]);
        db_exec("DELETE FROM upcc_case_activity WHERE case_id = ?", [$cid]);
        db_exec("DELETE FROM upcc_case_panel_member WHERE case_id = ?", [$cid]);
        db_exec("DELETE FROM upcc_suggestion_cooldown WHERE case_id = ?", [$cid]);
        db_exec("DELETE FROM upcc_hearing_presence WHERE case_id = ?", [$cid]);
    }

    echo "Deleting upcc_case_offense...\n";
    db_exec("DELETE FROM upcc_case_offense WHERE offense_id IN (SELECT offense_id FROM offense WHERE student_id = ?)", [$sid]);
    db_exec("DELETE FROM upcc_case_offense WHERE case_id IN (SELECT case_id FROM upcc_case WHERE student_id = ?)", [$sid]);

    // 2. Community Service Sessions (linked via requirement)
    echo "Deleting community_service_session...\n";
    db_exec("DELETE FROM community_service_session WHERE requirement_id IN (SELECT requirement_id FROM community_service_requirement WHERE student_id = ?)", [$sid]);

    // 3. Simple tables with student_id
    $tables = [
        'auth_session',
        'community_service_requirement',
        'guard_violation_report',
        'guardian',
        'manual_login_request',
        'notification',
        'notification_log',
        'offense',
        'student_appeal_request',
        'student_email_otp',
        'upcc_case',
        'violation_letter'
    ];

    foreach ($tables as $table) {
        echo "Deleting from $table...\n";
        db_exec("DELETE FROM `$table` WHERE student_id = ?", [$sid]);
    }

    // Reset student status to active
    db_exec("UPDATE student SET is_active = 1 WHERE student_id = ?", [$sid]);

    echo "COMPLETELY CLEANED everything for $sid and reset status to ACTIVE.\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
} finally {
    db_exec("SET FOREIGN_KEY_CHECKS = 1");
}
