<?php
$ch = curl_init('http://localhost/identitrack/api/student/request_otp.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'OPTIONS');
$response = curl_exec($ch);
if(curl_errno($ch)){
    echo 'Curl error: ' . curl_error($ch);
} else {
    echo $response;
}
curl_close($ch);
