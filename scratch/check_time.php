<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=identitrack;charset=utf8mb4", "root", "", [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

date_default_timezone_set('Asia/Manila');

$phpTime = date('Y-m-d H:i:s');
$mysqlTime = $pdo->query("SELECT NOW() as now")->fetch()['now'];
$mysqlUtc = $pdo->query("SELECT UTC_TIMESTAMP() as utc")->fetch()['utc'];

echo "PHP (Asia/Manila): $phpTime\n";
echo "MySQL NOW():       $mysqlTime\n";
echo "MySQL UTC():       $mysqlUtc\n";
