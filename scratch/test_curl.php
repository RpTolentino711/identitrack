<?php
$data = ['student_id' => '2023-183482'];
$options = [
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n" .
                     "X-Student-Token: any_token_here_or_auth\r\n",
        'content' => json_encode($data),
        'ignore_errors' => true
    ]
];
$context  = stream_context_create($options);
$response = file_get_contents('https://identitrack.site/api/student/alerts.php', false, $context);
echo "RESPONSE FROM alerts.php:\n";
var_dump($response);

$response2 = file_get_contents('https://identitrack.site/api/student/community_service_overview.php', false, $context);
echo "\nRESPONSE FROM community_service_overview.php:\n";
var_dump($response2);
