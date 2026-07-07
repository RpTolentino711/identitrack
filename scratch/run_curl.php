<?php
$token = "test_token_123";
$studentId = '2023-183482';

$ch = curl_init("http://localhost/identitrack/api/student/community_service_overview.php");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['student_id' => $studentId]));
curl_setopt($ch, CURLOPT_HTTPHEADER, [
  "Authorization: Bearer $token",
  "Content-Type: application/json"
]);

$response = curl_exec($ch);
if (curl_errno($ch)) {
  echo 'Curl error: ' . curl_error($ch) . "\n";
} else {
  echo $response . "\n";
}
curl_close($ch);
