<?php
$_GET['action'] = 'global_chat';
$_GET['user_query'] = 'test';
require __DIR__ . '/../../../admin/api_ai_suggest_sanction.php';
$payload = [
    'description' => 'Caught cheating by the prof',
    'category' => 'Automatic Major Offenses',
    'violation' => 'Cheating or academic dishonesty',
    'number_of_offense' => '2nd Offense'
];
$out = runCliPythonPrediction($payload);
echo "\nCLI FALLBACK OUTPUT:\n" . $out . "\n";
