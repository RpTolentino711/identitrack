<?php
require_once __DIR__ . '/database/database.php';
header('Content-Type: text/plain');

try {
    $params = [':sid' => '2023-183482'];
    db_add_encryption_key($params);

    $query = "
      SELECT uc.case_id, uc.student_id, uc.decided_category, uc.probation_until, uc.punishment_details, uc.status AS case_status,
             " . db_decrypt_cols(['student_fn', 'student_ln'], 's') . ",
             s.program, s.section, s.year_level, s.is_active AS student_active,
             csr.requirement_id, csr.status AS req_status, csr.hours_required, csr.task_name, csr.completed_at AS req_completed_at
      FROM upcc_case uc
      JOIN student s ON s.student_id = uc.student_id
      LEFT JOIN community_service_requirement csr ON csr.related_case_id = uc.case_id
      WHERE uc.student_id = :sid
    ";
    
    $rows = db_all($query, $params);
    echo "=== QUERY ROWS ===\n";
    foreach ($rows as $r) {
        $p_details = json_decode($r['punishment_details'] ?? '', true) ?: [];
        $is_completed = !empty($p_details['completed']) || ($r['req_status'] === 'COMPLETED');
        echo "Case: {$r['case_id']}, Req: {$r['requirement_id']}, Req Status: {$r['req_status']}, Details Completed: " . ($p_details['completed'] ? 'true' : 'false') . ", Is Completed: " . ($is_completed ? 'true' : 'false') . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
