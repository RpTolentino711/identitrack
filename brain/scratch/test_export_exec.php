<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

// Catch any output or error
ob_start();

$_GET['month'] = 'ALL';
$_GET['audience'] = 'ALL';
$_GET['category'] = 'ALL';
$_GET['show_names'] = '0';

session_start();
$_SESSION['role'] = 'admin';

try {
    require_once 'c:/xampp/htdocs/identitrack/database/database.php';
    
    // We mock ob_end_clean by overriding or inspecting before exit
    include 'c:/xampp/htdocs/identitrack/admin/AJAX/export_monthly_report_xlsx.php';
} catch (\Throwable $e) {
    echo "EXCEPT: " . $e->getMessage() . "\n" . $e->getTraceAsString();
}
