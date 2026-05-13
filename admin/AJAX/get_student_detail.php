<?php
// File: admin/AJAX/get_student_detail.php
require_once __DIR__ . '/../../database/database.php';
require_admin();

// Must come before any output
header('Content-Type: application/json');

// Suppress PHP notices/warnings leaking into JSON output
@ini_set('display_errors', 0);
error_reporting(0);

try {
    $studentId = trim((string)($_GET['student_id'] ?? ''));

    if ($studentId === '') {
        echo json_encode(['ok' => false, 'message' => 'Missing student_id']);
        exit;
    }

    // ── Student row ──
    // Exact columns per schema:
    //   student_id, student_fn, student_ln,
    //   year_level (tinyint), section, school, program,
    //   student_email, home_address, phone_number
    $params = [':sid' => $studentId];
    db_add_encryption_key($params);

    $student = db_one(
        "SELECT student_id, " . db_decrypt_cols(['student_fn', 'student_ln', 'student_email', 'home_address', 'phone_number']) . ",
                year_level, section, school, program
         FROM student
         WHERE student_id = :sid
         LIMIT 1",
        $params
    );

    if (!$student) {
        echo json_encode(['ok' => false, 'message' => 'Student not found']);
        exit;
    }

    // ── Offense history ──
    // offense cols used: offense_id, date_committed, description, status, level
    // offense_type cols used: name, code  (NOT type_name)
    $offParams = [':sid' => $studentId];
    db_add_encryption_key($offParams);

    $offenses = db_all(
        "SELECT o.offense_id,
                o.date_committed,
                " . db_decrypt_col('description', 'o') . " AS description,
                o.status,
                o.level,
                ot.name        AS offense_name,
                ot.code        AS offense_code
         FROM   offense o
         JOIN   offense_type ot ON ot.offense_type_id = o.offense_type_id
         WHERE  o.student_id = :sid
         ORDER  BY o.date_committed DESC",
        $offParams
    );

    // Year suffix: 1 → 1st, 2 → 2nd, 3 → 3rd, 4+ → 4th
    $yl       = (int)($student['year_level'] ?? 0);
    $suffixes = ['', 'st', 'nd', 'rd'];
    $suffix   = ($yl >= 1 && $yl <= 3) ? $suffixes[$yl] : 'th';
    $yearLabel = $yl > 0 ? ($yl . $suffix . ' Year') : '';

    // Single subtitle line: "2nd Year · BSIT · Sec. B"
    $subtitle = implode(' · ', array_filter([
        $yearLabel,
        $student['program'] ?? '',
        !empty($student['section']) ? ('Sec. ' . $student['section']) : '',
    ]));

    echo json_encode([
        'ok'   => true,
        'data' => [
            'student_id' => $student['student_id'],
            'year_label' => $subtitle,
            'school'     => $student['school']        ?? '',
            'address'    => $student['home_address']  ?? '',
            'phone'      => $student['phone_number']  ?? '',
            'email'      => $student['student_email'] ?? '',
            'offenses'   => $offenses ?: [],
        ],
    ]);

} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'message' => $e->getMessage()]);
}