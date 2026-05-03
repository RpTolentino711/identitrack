<?php
require_once __DIR__ . '/../database/database.php';
$sid = '2023-1280';
$activities = db_all("SELECT a.*, c.status as case_status FROM upcc_case_activity a JOIN upcc_case c ON c.case_id = a.case_id WHERE c.student_id = :sid ORDER BY a.created_at DESC", [':sid' => $sid]);
print_r(['activities' => $activities]);
