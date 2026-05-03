<?php
require 'database/database.php';

// 1. Create a session for student 2023-1280
$req = db_one("SELECT requirement_id FROM community_service_requirement WHERE student_id = '2023-1280' AND status = 'ACTIVE' LIMIT 1");
$reqId = $req['requirement_id'];

db_exec("INSERT INTO community_service_session (requirement_id, time_in, time_out, login_method, logout_method, validated_by, created_at, updated_at) 
        VALUES (:rid, NOW(), NULL, 'NFC', NULL, 1, NOW(), NOW())", [':rid' => $reqId]);
$sessionId = db_last_id();
echo "Created session $sessionId\n";

// 2. Simulate Manual Logout Request
// This is what comstudent/community_service.php does:
db_exec("UPDATE community_service_session SET time_out = NOW(), logout_method = 'MANUAL' WHERE session_id = :sid", [':sid' => $sessionId]);
db_exec("INSERT INTO manual_login_request (requirement_id, student_id, request_type, login_method, requested_at, reason, status)
         VALUES (:rid, '2023-1280', 'LOGOUT', 'MANUAL', NOW(), 'Test manual logout', 'PENDING')", [':rid' => $reqId]);
$requestId = db_last_id();
echo "Created logout request $requestId\n";

// 3. Check history view data
$completed = db_one("SELECT login_method, logout_method FROM community_service_session WHERE session_id = :sid", [':sid' => $sessionId]);
print_r($completed);
