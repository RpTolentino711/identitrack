<?php
require 'database/database.php';
$r = db_one("SHOW CREATE TABLE student");
echo $r['Create Table'];
echo "\n\n";
$r = db_one("SHOW CREATE TABLE offense");
echo $r['Create Table'];
echo "\n\n";
$r = db_one("SHOW CREATE TABLE upcc_case");
echo $r['Create Table'];
