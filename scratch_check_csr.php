<?php
require 'database/database.php';
$columns = db_all("SHOW COLUMNS FROM community_service_requirement");
print_r($columns);
?>
