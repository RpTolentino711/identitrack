<?php
require_once __DIR__ . '/database/database.php';
header('Content-Type: text/plain');

try {
    $studentId = '2023-183482';
    $sanctParams = [':sid' => $studentId];
    db_add_encryption_key($sanctParams);

    $sanctionsQuery = "
      SELECT uc.case_id, uc.student_id, uc.decided_category, uc.probation_until, uc.punishment_details, uc.status AS case_status,
             csr.requirement_id, csr.status AS req_status, csr.hours_required, csr.task_name, csr.completed_at AS req_completed_at,
             (
               SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, sess.time_in, sess.time_out)/3600.0), 0.0)
               FROM community_service_session sess
               WHERE sess.requirement_id = csr.requirement_id AND sess.time_out IS NOT NULL
             ) AS hours_completed
      FROM upcc_case uc
      LEFT JOIN community_service_requirement csr ON csr.related_case_id = uc.case_id
      WHERE uc.student_id = :sid 
        AND uc.decided_category IS NOT NULL 
        AND uc.decided_category BETWEEN 1 AND 5
      ORDER BY uc.created_at DESC
    ";

    $rawSanctions = db_all($sanctionsQuery, $sanctParams);

    echo "=== LOOKUP DETAILS ===\n";
    foreach ($rawSanctions as $s) {
        $p_details = json_decode($s['punishment_details'] ?? '', true) ?: [];
        $completed = !empty($p_details['completed']);
        $cat = (int)$s['decided_category'];
        if ($cat === 2) {
            $completed_combined = $completed || ($s['req_status'] === 'COMPLETED');
            echo "Case ID: {$s['case_id']}\n";
            echo "Req Status: {$s['req_status']}\n";
            echo "Punishment Details Completed: " . ($completed ? 'true' : 'false') . "\n";
            echo "Combined Completed: " . ($completed_combined ? 'true' : 'false') . "\n";
        }
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
