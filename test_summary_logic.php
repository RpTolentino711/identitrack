<?php
require 'database/database.php';
$studentId = '2023-1280';

// Mock the request to dashboard_summary.php logic
$closedCase = db_one(
  "SELECT case_id, status as case_status, decided_category, final_decision, punishment_details, resolution_date
   FROM upcc_case
   WHERE student_id = :sid
     AND status IN ('CLOSED', 'RESOLVED')
     AND decided_category IS NOT NULL
   ORDER BY resolution_date DESC, case_id DESC
   LIMIT 1",
  [':sid' => $studentId]
);

$latestAppeal = null;
if ($closedCase) {
  $latestAppeal = db_one(
    "SELECT appeal_id, status, created_at
     FROM student_appeal_request
     WHERE student_id = :sid
       AND case_id = :cid
       AND appeal_kind = 'UPCC_CASE'
     ORDER BY created_at DESC, appeal_id DESC
     LIMIT 1",
    [':sid' => $studentId, ':cid' => (int)$closedCase['case_id']]
  );
}

if ($closedCase) {
  $resolutionAt = strtotime((string)($closedCase['resolution_date'] ?? ''));
  $appealWindowOpen = $resolutionAt > 0 && (time() - $resolutionAt) <= (5 * 86400);
  $hasActiveAppeal = $latestAppeal && in_array((string)($latestAppeal['status'] ?? ''), ['PENDING', 'REVIEWING'], true);
  $isResolved = $closedCase['case_status'] === 'RESOLVED';

  $can_appeal = $appealWindowOpen && !$hasActiveAppeal && !$isResolved;
  
  echo "case_status: " . $closedCase['case_status'] . "\n";
  echo "isResolved: " . ($isResolved ? 'true' : 'false') . "\n";
  echo "can_appeal: " . ($can_appeal ? 'true' : 'false') . "\n";
} else {
  echo "No closed case found.\n";
}
?>
