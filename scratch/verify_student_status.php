<?php
require_once 'database/database.php';
$studentId = '2023-1280';
$policy = student_account_mode($studentId);
echo "Mode: " . $policy['mode'] . "\n";
echo "Message: " . $policy['message'] . "\n";

$activeCase = db_one("SELECT * FROM upcc_case WHERE student_id = :sid AND status IN ('PENDING','UNDER_INVESTIGATION','AWAITING_ADMIN_FINALIZATION')", [':sid' => $studentId]);
echo "Active Case Found: " . ($activeCase ? 'YES (' . $activeCase['case_id'] . ')' : 'NO') . "\n";

$closedCase = db_one("SELECT * FROM upcc_case WHERE student_id = :sid AND status IN ('CLOSED','RESOLVED') ORDER BY resolution_date DESC LIMIT 1", [':sid' => $studentId]);
echo "Closed Case Found: " . ($closedCase ? 'YES (' . $closedCase['case_id'] . ') Cat: ' . $closedCase['decided_category'] : 'NO') . "\n";
