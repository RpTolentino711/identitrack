<?php
require_once __DIR__ . '/../../database/database.php';

$_GET['month'] = 'ALL';
$_GET['audience'] = 'ALL';
$_GET['show_names'] = '1';

// Import masking logic test
require_once __DIR__ . '/../AJAX/export_monthly_report_xlsx.php';
