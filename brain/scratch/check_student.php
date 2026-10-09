<?php
$pdo = new PDO("mysql:host=127.0.0.1;dbname=identitrack", "root", "");
$r = $pdo->query("SELECT * FROM upcc_case WHERE case_id = 119 OR case_summary LIKE '%119%'")->fetchAll(PDO::FETCH_ASSOC);
echo "identitrack upcc_case 119:\n";
print_r($r);

$pdo2 = new PDO("mysql:host=127.0.0.1;dbname=u321173822_track", "root", "");
$r2 = $pdo2->query("SELECT * FROM upcc_case WHERE case_id = 119 OR case_summary LIKE '%119%'")->fetchAll(PDO::FETCH_ASSOC);
echo "u321173822_track upcc_case 119:\n";
print_r($r2);
