<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;
$_SESSION['admin_session_token'] = 'test';

$_GET['month'] = 'ALL';
$_GET['audience'] = 'ALL';
$_GET['category'] = 'ALL';

$t0 = microtime(true);

require_once 'c:/xampp/htdocs/identitrack/database/database.php';
db_exec("UPDATE admin_user SET active_session_token = 'test' WHERE admin_id = 1");

ob_start();
include 'c:/xampp/htdocs/identitrack/admin/AJAX/reports_monthly_data.php';
$content = ob_get_clean();

$t1 = microtime(true);
$duration = round(($t1 - $t0) * 1000, 2);

echo "AJAX reports_monthly_data.php execution time: {$duration} ms. JSON bytes: " . strlen($content) . "\n";
