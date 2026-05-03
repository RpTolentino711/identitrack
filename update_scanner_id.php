<?php
require 'database/database.php';
$student_id = '2004-03-30';
$scanner_id = '3667085316';
$scanHash = hash('sha256', trim($scanner_id));
db_exec("UPDATE student SET scanner_id_hash = :hash WHERE student_id = :sid", [
    ':hash' => $scanHash,
    ':sid' => $student_id
]);
echo "Updated scanner_id_hash for $student_id using scanner_id $scanner_id (Hash: $scanHash)\n";
?>
