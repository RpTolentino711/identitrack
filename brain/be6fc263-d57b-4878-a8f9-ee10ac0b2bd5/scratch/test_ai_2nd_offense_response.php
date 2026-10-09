<?php
$_POST['category'] = 'Automatic Major Offenses';
$_POST['violation'] = 'Cheating or academic dishonesty, in online or face-to-face settings, before or during an examination.';
$_POST['number_of_offense'] = 'Automatic Major - 2nd Offense';
$_POST['description'] = 'Caught cheating by the prof';
$_GET['action'] = 'predict';
$_GET['case_id'] = '1';

ob_start();
require __DIR__ . '/../../../admin/api_ai_suggest_sanction.php';
$output = ob_get_clean();

echo "OUTPUT:\n" . $output . "\n";
