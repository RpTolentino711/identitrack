<?php
require 'database/database.php';
$columns = db_all("SHOW COLUMNS FROM student");
print_r($columns);
?>
