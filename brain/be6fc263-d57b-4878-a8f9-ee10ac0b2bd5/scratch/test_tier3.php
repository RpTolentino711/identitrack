<?php
define('IS_TESTING_CLI', true);
require_once __DIR__ . '/../../../admin/api_ai_suggest_sanction.php';

$res = queryAiEngine("system prompt", "cheating exam major offenses", "John Doe", "123", [
    'offense_name' => 'Cheating during major exam',
    'category' => 'Major Offenses',
    'number_of_offense' => '1st Offense'
]);

echo json_encode($res, JSON_PRETTY_PRINT);
