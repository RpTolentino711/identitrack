<?php
require 'database/database.php';
$studentId = '2023-1280';
$caseId = 19;

// Try to update status to RESOLVED
$count = db_exec("UPDATE upcc_case SET status = 'RESOLVED' WHERE case_id = :cid AND student_id = :sid", [
    ':cid' => $caseId,
    ':sid' => $studentId
]);

echo "Updated $count rows to RESOLVED.\n";
?>
