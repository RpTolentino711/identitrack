<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$studentId = '2023-0001'; // Use a real ID if possible, but any ID should trigger the query
$body = ['student_id' => $studentId];
// Mock file_get_contents('php://input') is hard, so I'll just bypass it by setting $body
require_once __DIR__ . '/../database/database.php';

// I'll manually run the query from offense_list.php to see if it fails
try {
    $rows = db_all(
      "SELECT
          o.offense_id,
          uc.case_id AS upcc_case_id,
          uc.student_explanation_text
       FROM offense o
       LEFT JOIN upcc_case_offense uco ON uco.offense_id = o.offense_id
       LEFT JOIN upcc_case uc ON uc.case_id = uco.case_id
       LIMIT 1"
    );
    echo "Query 1 OK\n";
} catch (Exception $e) {
    echo "Query 1 FAILED: " . $e->getMessage() . "\n";
}
