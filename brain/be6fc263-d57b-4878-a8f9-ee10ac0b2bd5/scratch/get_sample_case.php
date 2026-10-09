<?php
require_once __DIR__ . '/../../../database/database.php';
$row = db_one("SELECT case_id, student_id FROM upcc_case ORDER BY case_id DESC LIMIT 1");
print_r($row);
