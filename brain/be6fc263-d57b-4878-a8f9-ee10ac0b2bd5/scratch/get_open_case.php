<?php
require_once __DIR__ . '/../../../database/database.php';
$row = db_one("SELECT case_id, student_id, status FROM upcc_case WHERE status NOT IN ('CLOSED', 'RESOLVED', 'FINALIZED') ORDER BY case_id DESC LIMIT 1");
print_r($row);
