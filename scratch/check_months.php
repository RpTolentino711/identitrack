<?php
require 'database/database.php';
$months = db_all("SELECT DISTINCT DATE_FORMAT(date_committed, '%Y-%m') as m FROM offense ORDER BY m DESC");
foreach($months as $m) echo $m['m'] . "\n";
