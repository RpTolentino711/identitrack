<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

session_start();
$_SESSION['role'] = 'admin';

$_GET['month'] = 'ALL';
$_GET['audience'] = 'ALL';
$_GET['category'] = 'ALL';
$_GET['show_names'] = '0';

// Intercept output buffer
ob_start();

try {
    include 'c:/xampp/htdocs/identitrack/admin/AJAX/export_monthly_report_xlsx.php';
} catch (\Throwable $e) {
    echo "EXCEPT: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
$out = ob_get_clean();
echo "Script finished. Output length: " . strlen($out) . " bytes.\n";
if (strlen($out) > 0) {
    echo "First 100 bytes (hex): " . bin2hex(substr($out, 0, 50)) . "\n";
}
