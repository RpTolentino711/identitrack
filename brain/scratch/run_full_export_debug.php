<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

$_GET['month'] = '2026-09';
$_GET['audience'] = 'ALL';
$_GET['category'] = 'ALL';
$_GET['show_names'] = '0';

session_start();
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;
$_SESSION['admin_session_token'] = 'test';

require_once 'c:/xampp/htdocs/identitrack/database/database.php';
db_exec("UPDATE admin_user SET active_session_token = 'test' WHERE admin_id = 1");

echo "Starting export_monthly_report_xlsx.php...\n";

try {
    include 'c:/xampp/htdocs/identitrack/admin/AJAX/export_monthly_report_xlsx.php';
    echo "\nSUCCESSFULLY FINISHED!\n";
} catch (\Throwable $e) {
    echo "\nFATAL EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
