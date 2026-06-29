<?php
$_ENV['DB_HOST'] = 'localhost';
$_ENV['DB_NAME'] = 'identitrack';
$_ENV['DB_USER'] = 'root';
$_ENV['DB_PASS'] = '';
$_SERVER['DB_HOST'] = 'localhost';
$_SERVER['DB_NAME'] = 'identitrack';
$_SERVER['DB_USER'] = 'root';
$_SERVER['DB_PASS'] = '';
require_once __DIR__ . '/database/database.php';
$cnt = db_one("SELECT COUNT(*) as c FROM offense WHERE student_id='2023-1280'");
echo "Total offenses for 2023-1280: " . $cnt['c'] . "\n";
