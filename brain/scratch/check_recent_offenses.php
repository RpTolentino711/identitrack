<?php
require_once 'c:/xampp/htdocs/identitrack/database/database.php';
$res = db_all("SELECT offense_id, student_id, offense_type_id, date_committed, status, created_at FROM offense ORDER BY offense_id DESC LIMIT 10");
print_r($res);
