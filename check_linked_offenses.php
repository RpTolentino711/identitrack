<?php
require 'database/database.php';
$caseId = 19;
$offenses = db_all("SELECT offense_id FROM upcc_case_offense WHERE case_id = ?", [$caseId]);
print_r($offenses);
?>
