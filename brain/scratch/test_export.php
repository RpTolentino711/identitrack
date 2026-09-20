<?php
require_once __DIR__ . '/../../database/database.php';
session_start();

$adminRow = db_one("SELECT * FROM admin_user LIMIT 1");
if ($adminRow) {
    $_SESSION['admin_id'] = (int)$adminRow['admin_id'];
    $_SESSION['admin'] = [
        'admin_id' => (int)$adminRow['admin_id'],
        'full_name' => (string)($adminRow['full_name'] ?? 'Admin'),
        'username' => (string)($adminRow['username'] ?? 'admin'),
        'role' => 'ADMIN'
    ];
    if (!empty($adminRow['active_session_token'])) {
        $_SESSION['admin_session_token'] = $adminRow['active_session_token'];
    }
}

$_GET['month'] = 'ALL';
$_GET['audience'] = 'ALL';
$_GET['category'] = 'ALL';

ob_start();
include __DIR__ . '/../../admin/AJAX/export_monthly_report_xlsx.php';
$output = ob_get_clean();

echo "Generated Excel byte length: " . strlen($output) . "\n";
if (strlen($output) > 1000) {
    echo "SUCCESS: Excel file generated cleanly!\n";
} else {
    echo "OUTPUT SAMPLE:\n" . substr($output, 0, 500) . "\n";
}
