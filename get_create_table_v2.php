<?php
require 'database/database.php';
$r = db_one("SHOW CREATE TABLE student");
echo "STUDENT:\n" . $r['Create Table'] . "\n\n";
$r = db_one("SHOW CREATE TABLE security_guard");
echo "GUARD:\n" . $r['Create Table'] . "\n\n";
