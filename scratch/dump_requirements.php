<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=identitrack;charset=utf8mb4", "root", "", [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

$reqs = $pdo->query("SELECT * FROM community_service_requirement")->fetchAll();
print_r($reqs);
