<?php
require_once __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->safeLoad();

require_once __DIR__ . '/../database/database.php';

try {
    $pdo = db();
    echo "Connected successfully to DB!\n";

    // 1. Get student
    $stmt = $pdo->query("SELECT student_id, student_fn, student_ln FROM student WHERE student_ln LIKE '%Tolentino%' LIMIT 1");
    $student = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "Student: " . print_r($student, true) . "\n";

    if ($student) {
        $sid = $student['student_id'];
        $reqs = $pdo->query("SELECT requirement_id, case_id, hours_required, status, assigned_at FROM community_service_requirement WHERE student_id = '$sid'")->fetchAll(PDO::FETCH_ASSOC);
        echo "Requirements:\n";
        print_r($reqs);

        $sessions = $pdo->query("SELECT session_id, requirement_id, time_in, time_out, status, pause_reason, paused_at, accum_paused_seconds FROM community_service_session WHERE requirement_id IN (SELECT requirement_id FROM community_service_requirement WHERE student_id = '$sid') ORDER BY session_id ASC")->fetchAll(PDO::FETCH_ASSOC);
        echo "Sessions:\n";
        print_r($sessions);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
