<?php
require 'C:/xampp/htdocs/identitrack/database/database.php';
$rows = db_all('SELECT student_id, student_fn, student_ln FROM student ORDER BY created_at DESC LIMIT 5');
print_r($rows);
