<?php
date_default_timezone_set('Asia/Manila');

$pdo = new PDO("mysql:host=127.0.0.1;dbname=identitrack;charset=utf8mb4", "root", "", [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Set timezone on connection
$pdo->exec("SET time_zone = '+08:00'");

$studentId = '2023-183482';

// 1. Delete existing CSR/CSS for this student for a clean test
$pdo->exec("DELETE FROM community_service_session WHERE requirement_id IN (SELECT requirement_id FROM community_service_requirement WHERE student_id = '$studentId')");
$pdo->exec("DELETE FROM community_service_requirement WHERE student_id = '$studentId'");

// 2. Insert a requirement of 5 hours (assigned_at = now)
$pdo->exec("INSERT INTO community_service_requirement (student_id, assigned_by, task_name, location, hours_required, status, assigned_at) 
            VALUES ('$studentId', 1, 'Library Assistance', 'Main Library', 5.00, 'ACTIVE', NOW())");
$reqId = $pdo->lastInsertId();

// 3. Insert a completed session of 1.5 hours (elapsed: 1 hour 30 mins)
$timeInCompleted = date('Y-m-d H:i:s', strtotime('-2 hours'));
$timeOutCompleted = date('Y-m-d H:i:s', strtotime('-30 minutes'));
$pdo->exec("INSERT INTO community_service_session (requirement_id, time_in, time_out, login_method, logout_method, validated_by) 
            VALUES ($reqId, '$timeInCompleted', '$timeOutCompleted', 'NFC', 'NFC', 1)");

// 4. Start an active session (clocked in 10 minutes ago)
$timeInActive = date('Y-m-d H:i:s', strtotime('-10 minutes'));
$pdo->exec("INSERT INTO community_service_session (requirement_id, time_in, time_out, login_method, logout_method, validated_by) 
            VALUES ($reqId, '$timeInActive', NULL, 'NFC', NULL, NULL)");

file_put_contents('scratch/request.json', json_encode(['student_id' => $studentId]));
echo "Simulated successfully.\n";
