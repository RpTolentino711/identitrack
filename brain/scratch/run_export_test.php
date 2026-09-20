<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

$_GET['month'] = '2026-05';
$_GET['audience'] = 'ALL';
$_GET['category'] = 'ALL';
$_GET['show_names'] = '0';

session_start();
$_SESSION['role'] = 'admin';
$_SESSION['user_id'] = 1;
$_SESSION['admin_session_token'] = 'test';

require_once 'c:/xampp/htdocs/identitrack/database/database.php';

// Set active session token in DB so require_admin() passes
db_exec("UPDATE admin_user SET active_session_token = 'test' WHERE admin_id = 1");

ob_start();
include 'c:/xampp/htdocs/identitrack/admin/AJAX/export_monthly_report_xlsx.php';
$content = ob_get_clean();

file_put_contents('c:/xampp/htdocs/identitrack/brain/scratch/test_export_result.xlsx', $content);
echo "Saved test_export_result.xlsx, size = " . strlen($content) . " bytes\n";
