<?php
// File: scratch/check_active_sessions.php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=identitrack;charset=utf8mb4", "root", "", [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$active = $pdo->query("
  SELECT s.*, r.hours_required, r.student_id, r.status as req_status
  FROM community_service_session s
  LEFT JOIN community_service_requirement r ON r.requirement_id = s.requirement_id
  WHERE s.time_out IS NULL
")->fetchAll();

print_r($active);
