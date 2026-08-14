<?php
require_once __DIR__ . '/../database/database.php';

try {
    $student = db_one("SELECT student_id, student_fn, student_ln FROM student WHERE student_ln LIKE '%Tolentino%' LIMIT 1");
    if (!$student) {
        echo "Student not found\n";
        exit;
    }
    $sid = $student['student_id'];
    echo "Student: " . $student['student_fn'] . " " . $student['student_ln'] . " (ID: $sid)\n\n";

    $reqs = db_all("SELECT requirement_id, case_id, hours_required, status, assigned_at FROM community_service_requirement WHERE student_id = :sid ORDER BY requirement_id ASC", [':sid' => $sid]);
    echo "--- Requirements ---\n";
    foreach ($reqs as $r) {
        echo "Req ID: {$r['requirement_id']} | Case ID: {$r['case_id']} | Hours Req: {$r['hours_required']} | Status: {$r['status']} | Assigned: {$r['assigned_at']}\n";
    }

    echo "\n--- All Sessions ---\n";
    $sessions = db_all("
        SELECT 
            css.session_id,
            css.requirement_id,
            css.time_in,
            css.time_out,
            css.status,
            css.paused_at,
            css.accum_paused_seconds,
            TIMESTAMPDIFF(SECOND, css.time_in, COALESCE(css.time_out, NOW())) AS raw_duration_sec,
            CASE 
              WHEN css.status = 'PAUSED' AND css.paused_at IS NOT NULL THEN
                GREATEST(0, TIMESTAMPDIFF(SECOND, css.time_in, css.paused_at) - COALESCE(css.accum_paused_seconds, 0))
              ELSE
                GREATEST(0, TIMESTAMPDIFF(SECOND, css.time_in, COALESCE(css.time_out, NOW())) - COALESCE(css.accum_paused_seconds, 0))
            END AS net_duration_sec
        FROM community_service_session css
        JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
        WHERE csr.student_id = :sid
        ORDER BY css.session_id ASC
    ", [':sid' => $sid]);

    $totalNetSec = 0;
    foreach ($sessions as $s) {
        $netSec = (int)$s['net_duration_sec'];
        if (!empty($s['time_out'])) {
            $totalNetSec += $netSec;
        }
        $m = floor($netSec / 60);
        $sec = $netSec % 60;
        $statusStr = $s['time_out'] ? "COMPLETED" : ("ACTIVE (" . $s['status'] . ")");
        echo "Session #{$s['session_id']} (Req #{$s['requirement_id']}): TimeIn: {$s['time_in']} | TimeOut: {$s['time_out']} | Status: {$statusStr} | Net Duration: {$m}m {$sec}s ({$netSec}s)\n";
    }

    $totalM = floor($totalNetSec / 60);
    $totalSec = $totalNetSec % 60;
    echo "\nTotal Completed Past Served Time across all sessions: {$totalM} minutes and {$totalSec} seconds ({$totalNetSec} seconds)\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
