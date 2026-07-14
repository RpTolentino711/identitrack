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
             csr.requirement_id, csr.status AS req_status, csr.hours_required, csr.task_name, csr.completed_at AS req_completed_at,
             (
               SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, sess.time_in, sess.time_out)/3600.0), 0.0)
               FROM community_service_session sess
               WHERE sess.requirement_id = csr.requirement_id AND sess.time_out IS NOT NULL
             ) AS hours_completed
      FROM upcc_case uc
      JOIN student s ON s.student_id = uc.student_id
      LEFT JOIN community_service_requirement csr ON csr.related_case_id = uc.case_id
      WHERE uc.student_id = :sid
    ";
    
    $rows = db_all($query, $params);
    foreach ($rows as $c) {
        $p_details = json_decode($c['punishment_details'] ?? '', true) ?: [];
        $is_completed = !empty($p_details['completed']) || ($c['req_status'] === 'COMPLETED');
        $hours_comp = (float)$c['hours_completed'];
        $hours_req = (float)$c['hours_required'];
        $hours_rem = max(0.0, $hours_req - $hours_comp);
        $has_finished_hours = ($hours_comp >= ($hours_req - 0.0001));
        
        echo "Case: {$c['case_id']}\n";
        echo "Hours Completed: {$hours_comp}\n";
        echo "Hours Required: {$hours_req}\n";
        echo "Hours Remaining: {$hours_rem}\n";
        echo "Has Finished Hours: " . ($has_finished_hours ? 'true' : 'false') . "\n";
        echo "Is Completed: " . ($is_completed ? 'true' : 'false') . "\n";
        echo "Auto Check Completed: " . (($is_completed || $has_finished_hours) ? 'true' : 'false') . "\n";
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
