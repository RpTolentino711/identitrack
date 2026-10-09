<?php
require __DIR__ . '/../../database/database.php';
$s = db_one("SELECT * FROM student WHERE student_id = '2023-183482'");
print_r($s);
