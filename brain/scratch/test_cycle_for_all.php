<?php
session_start();
$_SESSION['admin_user'] = ['admin_id' => 1, 'username' => 'admin'];

require_once 'c:/xampp/htdocs/identitrack/database/database.php';

// Define getStudentActiveMinorCycle directly or include
function getStudentActiveMinorCycleTest(string $studentId, ?int $includeNewTypeId = null): array {
    if (empty($studentId)) {
        return [];
    }

    $completedRow = db_one(
        "SELECT COUNT(*) AS cnt FROM upcc_case 
         WHERE student_id = :sid 
           AND case_kind = 'SECTION4_MINOR_ESCALATION' 
           AND status NOT IN ('CANCELLED','VOID')",
        [':sid' => $studentId]
    );
    $completedCyclesCount = (int)($completedRow['cnt'] ?? 0);

    $lastSection4 = db_one(
        "SELECT MAX(created_at) AS max_date FROM upcc_case 
         WHERE student_id = :sid 
           AND case_kind = 'SECTION4_MINOR_ESCALATION' 
           AND status NOT IN ('CANCELLED','VOID')",
        [':sid' => $studentId]
    );
    $startDate = $lastSection4['max_date'] ?? null;

    if (!empty($startDate)) {
        $minors = db_all(
            "SELECT o.offense_id, o.offense_type_id, o.date_committed, o.created_at, ot.code, ot.name
             FROM offense o
             JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
             WHERE o.student_id = :sid 
               AND o.level = 'MINOR'
               AND o.status <> 'VOID'
               AND o.created_at > :start_date
               AND o.offense_id NOT IN (SELECT offense_id FROM upcc_case_offense WHERE offense_id IS NOT NULL)
             ORDER BY o.date_committed ASC, o.offense_id ASC",
            [':sid' => $studentId, ':start_date' => $startDate]
        ) ?: [];
    } else {
        $minors = db_all(
            "SELECT o.offense_id, o.offense_type_id, o.date_committed, o.created_at, ot.code, ot.name
             FROM offense o
             JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
             WHERE o.student_id = :sid 
               AND o.level = 'MINOR'
               AND o.status <> 'VOID'
               AND o.offense_id NOT IN (SELECT offense_id FROM upcc_case_offense WHERE offense_id IS NOT NULL)
             ORDER BY o.date_committed ASC, o.offense_id ASC",
            [':sid' => $studentId]
        ) ?: [];
    }

    if ($includeNewTypeId !== null && $includeNewTypeId > 0) {
        $otName = db_one("SELECT code, name FROM offense_type WHERE offense_type_id = ?", [$includeNewTypeId]);
        $minors[] = [
            'offense_id' => 0,
            'offense_type_id' => $includeNewTypeId,
            'date_committed' => date('Y-m-d H:i:s'),
            'code' => $otName['code'] ?? 'CUSTOM',
            'name' => $otName['name'] ?? 'Minor Offense',
        ];
    }

    $typeCounts = [];
    foreach ($minors as $m) {
        $tid = (int)$m['offense_type_id'];
        $typeCounts[$tid] = ($typeCounts[$tid] ?? 0) + 1;
    }

    return [
        'active_count' => count($minors),
        'completed_cycles' => $completedCyclesCount,
        'current_cycle_num' => $completedCyclesCount + 1,
        'minors' => $minors,
        'type_counts' => $typeCounts,
    ];
}

$students = db_all("SELECT DISTINCT student_id FROM student");

foreach ($students as $st) {
    $sid = $st['student_id'];
    $cycle = getStudentActiveMinorCycleTest($sid);
    echo "========================================================\n";
    echo "STUDENT: $sid\n";
    echo "Active Count: {$cycle['active_count']}\n";
    echo "Completed Cycles: {$cycle['completed_cycles']}\n";
    echo "Current Cycle Num: {$cycle['current_cycle_num']}\n";
    echo "Minors in Active Cycle:\n";
    foreach ($cycle['minors'] as $m) {
        echo "  - [ID: {$m['offense_id']}] Code: {$m['code']} | Name: {$m['name']}\n";
    }
}
