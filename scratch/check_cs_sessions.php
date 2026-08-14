<?php
require_once __DIR__ . '/../database/database.php';

$student = db_one("SELECT student_id, student_fn, student_ln FROM student WHERE student_ln LIKE '%Tolentino%' LIMIT 1");
if (!$student) {
    echo "Student not found\n";
    exit;
}

echo "Student: " . $student['student_fn'] . " " . $student['student_ln'] . " (" . $student['student_id'] . ")\n\n";

$reqs = db_all("SELECT * FROM community_service_requirement WHERE student_id = :sid", [':sid' => $student['student_id']]);
print_r($reqs);

$sessions = db_all("SELECT session_id, requirement_id, time_in, time_out, status, pause_reason, paused_at, accum_paused_seconds FROM community_service_session ORDER BY session_id ASC");
print_r($sessions);
