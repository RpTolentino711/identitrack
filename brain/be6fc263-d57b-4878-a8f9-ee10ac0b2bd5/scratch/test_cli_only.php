<?php
define('IS_TESTING_CLI', true);
require_once __DIR__ . '/../../../admin/api_ai_suggest_sanction.php';

$payload = [
    'description' => 'Caught cheating by the prof',
    'category' => 'Automatic Major Offenses',
    'violation' => 'Cheating or academic dishonesty',
    'number_of_offense' => '2nd Offense'
];
$out = runCliPythonPrediction($payload);
echo "DIRECT CLI OUTPUT:\n" . $out . "\n";
