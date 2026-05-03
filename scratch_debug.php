<?php
require 'database/database.php';
$rows = db_all("SELECT * FROM offense WHERE student_id='2004-03-30'");
print_r($rows);
