<?php
require 'database/database.php';
$cols = db_all("SHOW COLUMNS FROM community_service_requirement");
print_r($cols);
