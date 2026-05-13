<?php
require 'database/database.php';
$tables = db_all("SHOW TABLES");
foreach ($tables as $row) {
    $table = current($row);
    echo "Table: $table\n";
    $cols = db_all("SHOW COLUMNS FROM $table");
    foreach ($cols as $c) {
        echo "  - " . $c['Field'] . " (" . $c['Type'] . ")\n";
    }
    echo "\n";
}
