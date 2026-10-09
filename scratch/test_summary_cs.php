<?php
require_once __DIR__ . '/../database/database.php';

$studentId = '2023-183482'; // Romeo Paolo Tolentino

// Test dashboard_summary endpoint logic directly
$_SERVER['REQUEST_METHOD'] = 'POST';
$raw = json_encode(['student_id' => $studentId]);

// Check active requirements
$activeReqs = db_all("
  SELECT requirement_id, hours_required, status
  FROM community_service_requirement
  WHERE student_id = :sid
", [':sid' => $studentId]);

echo "Requirements in DB for $studentId:\n";
print_r($activeReqs);

// Check completed sessions
$sessions = db_all("
  SELECT session_id, requirement_id, time_in, time_out, status
  FROM community_service_session
  WHERE requirement_id IN (SELECT requirement_id FROM community_service_requirement WHERE student_id = :sid)
", [':sid' => $studentId]);

echo "Sessions in DB:\n";
print_r($sessions);
