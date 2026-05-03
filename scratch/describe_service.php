<?php
include 'database/database.php';
$pdo = db();
$tables = ['community_service_session', 'community_service_requirement'];
foreach ($tables as $t) {
    echo "\nTable: $t\n";
    $res = $pdo->query("DESCRIBE $t")->fetchAll();
    foreach ($res as $row) {
        echo $row['Field'] . " (" . $row['Type'] . ")\n";
    }
}
?>
