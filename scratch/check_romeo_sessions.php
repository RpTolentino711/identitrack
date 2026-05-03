<?php
require 'database/database.php';
$sid = '2023-1280';
$sessions = db_all("
    SELECT css.*, csr.task_name 
    FROM community_service_session css
    JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
    WHERE csr.student_id = :sid
    ORDER BY css.time_in DESC
", [':sid' => $sid]);
print_r($sessions);
