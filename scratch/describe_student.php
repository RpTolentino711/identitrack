<?php
include 'database/database.php';
$pdo = db();
$res = $pdo->query('DESCRIBE student')->fetchAll();
foreach ($res as $row) {
    echo $row['Field'] . " (" . $row['Type'] . ")\n";
}
?>
