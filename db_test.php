<?php
require 'database/database.php';
$rows = db_all("SELECT case_id, user_type, user_id, status, last_ping, TIMESTAMPDIFF(SECOND, last_ping, NOW()) as diff FROM upcc_hearing_presence");
print_r($rows);
