<?php
session_start();
$_SESSION['admin_user'] = ['admin_id' => 1, 'username' => 'admin'];

require_once 'c:/xampp/htdocs/identitrack/database/database.php';
require_once 'c:/xampp/htdocs/identitrack/admin/offense_new.php';

// Test student 2024-01001 (0 active DB minors in Cycle 2) with MIN-016 (offense_type_id 16) selected
$studentId = '2024-01001';
$selectedTypeId = 16; // MIN-016

$cycleInfo = getStudentActiveMinorCycle($studentId, $selectedTypeId);
echo "=== TESTING getStudentActiveMinorCycle for $studentId with selected MIN-016 ===\n";
echo "Existing Count (DB): {$cycleInfo['existing_count']}\n";
echo "Projected Count: {$cycleInfo['projected_count']}\n";
echo "Active Count: {$cycleInfo['active_count']}\n";
echo "Selected Type Code: {$cycleInfo['selected_type_code']}\n";
echo "Selected Type Name: {$cycleInfo['selected_type_name']}\n";

echo "\n--- RENDER MINOR ALERT HTML OUTPUT ---\n";
echo renderMinorAlert($cycleInfo['projected_count'], 'guardian@example.com', $cycleInfo['existing_count'], false, 0, $studentId, $selectedTypeId);
