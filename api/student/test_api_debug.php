<?php
declare(strict_types=1);
require_once __DIR__ . '/../../database/database.php';
header('Content-Type: application/json; charset=utf-8');

$studentId = '2023-183482'; // Known student

echo "--- RUNNING CS OVERVIEW TEST ---\n";
try {
    $decrypted_cols = db_decrypt_cols(['task_name', 'location']) . ", " . db_decrypt_col('notes') . " AS reason";
    $params = [':sid' => $studentId];
    $reqs = db_all("
      SELECT 
        requirement_id, $decrypted_cols, hours_required, status, assigned_at, completed_at
      FROM community_service_requirement
      WHERE student_id = :sid AND status = 'ACTIVE'
      ORDER BY assigned_at DESC
    ", $params);
    echo "Requirements count: " . count($reqs) . "\n";
    echo "SUCCESS\n";
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
