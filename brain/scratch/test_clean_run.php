<?php
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

ob_start();
try {
    include 'c:/xampp/htdocs/identitrack/admin/AJAX/export_monthly_report_xlsx.php';
} catch (\Throwable $e) {
    echo "ERROR: " . $e->getMessage();
}
$content = ob_get_clean();

file_put_contents('c:/xampp/htdocs/identitrack/brain/scratch/clean_out.xlsx', $content);
echo "Result length: " . strlen($content) . " bytes\n";
