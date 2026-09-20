<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();
$_SESSION['role'] = 'admin';

$_GET['month'] = 'ALL';
$_GET['audience'] = 'ALL';
$_GET['category'] = 'ALL';
$_GET['show_names'] = '0';

$t0 = microtime(true);

require_once 'c:/xampp/htdocs/identitrack/database/database.php';
db_exec("UPDATE admin_user SET active_session_token = 'test' WHERE admin_id = 1");
$_SESSION['admin_session_token'] = 'test';

ob_start();
include 'c:/xampp/htdocs/identitrack/admin/AJAX/export_monthly_report_xlsx.php';
$content = ob_get_clean();

$t1 = microtime(true);
$duration = round($t1 - $t0, 3);

echo "Execution time: {$duration} seconds. Output length: " . strlen($content) . " bytes.\n";
