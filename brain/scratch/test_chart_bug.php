<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();
$_SESSION['role'] = 'admin';

$_GET['month'] = '2026-09';
$_GET['audience'] = 'ALL';
$_GET['category'] = 'ALL';
$_GET['show_names'] = '0';

require_once 'c:/xampp/htdocs/identitrack/database/database.php';
db_exec("UPDATE admin_user SET active_session_token = 'test' WHERE admin_id = 1");
$_SESSION['admin_session_token'] = 'test';

// Temporarily test generating spreadsheet object
$month = '2026-09';
$monthStart = '2026-09-01 00:00:00';
$monthEnd = '2026-09-30 23:59:59';

echo "Testing 2026-09 month chart generation...\n";
