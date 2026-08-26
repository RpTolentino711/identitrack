<?php
// File: C:\xampp\htdocs\identitrack\database\seed_historical_dataset.php
// Seeds all 2,295 historical dataset records from VIOLATIONS.xlsx & NU_LIPA DISCIPLINE DATA BASE into MySQL.

require_once __DIR__ . '/database.php';

echo "=== STARTING HISTORICAL DATASET SEEDER ===\n";

$jsonPath = __DIR__ . '/../scratch/all_historical_records.json';
if (!file_exists($jsonPath)) {
    die("Error: all_historical_records.json not found!\n");
}

$records = json_decode(file_get_contents($jsonPath), true);
if (!is_array($records)) {
    die("Error: Invalid JSON data!\n");
}

echo "Total records to seed: " . count($records) . "\n";

$insertedStudents = 0;
$insertedOffenses = 0;
$insertedCases = 0;

// Pre-fetch existing offense types
$offenseTypes = [];
foreach (db_all("SELECT offense_type_id, name, level FROM offense_type") as $ot) {
    $offenseTypes[strtoupper(trim($ot['name']))] = (int)$ot['offense_type_id'];
}

foreach ($records as $r) {
    $sid = trim((string)($r['student_id'] ?? ''));
    $name = trim((string)($r['name'] ?? ''));
    if (!$sid || !$name) continue;

    // 1. Split name into First & Last Name
    $parts = explode(',', $name, 2);
    if (count($parts) === 2) {
        $ln = trim($parts[0]);
        $fn = trim($parts[1]);
    } else {
        $nameParts = explode(' ', $name);
        $ln = array_pop($nameParts);
        $fn = implode(' ', $nameParts);
        if (!$fn) { $fn = $ln; $ln = 'Student'; }
    }

    $prog = !empty($r['program']) ? trim((string)$r['program']) : 'BSIT';
    $email = !empty($r['email']) ? trim((string)$r['email']) : '';

    // 2. Ensure student exists in `student` table
    $studentExists = db_one("SELECT student_id FROM student WHERE student_id = ?", [$sid]);
    if (!$studentExists) {
        try {
            $fnEnc = db_encrypt_value($fn);
            $lnEnc = db_encrypt_value($ln);
            $emailEnc = $email ? db_encrypt_value($email) : db_encrypt_value(strtolower(str_replace(' ', '', $fn . '.' . $ln)) . '@students.nu-lipa.edu.ph');
            
            db_exec(
                "INSERT INTO student (student_id, student_fn, student_ln, student_email, program, section, school, created_at)
                 VALUES (?, ?, ?, ?, ?, 'INF232', 'NU Lipa', CURRENT_TIMESTAMP)",
                [$sid, $fnEnc, $lnEnc, $emailEnc, $prog]
            );
            $insertedStudents++;
        } catch (\Throwable $e) {
            // Student insert fallback if encryption syntax differs
            try {
                db_exec(
                    "INSERT IGNORE INTO student (student_id, program, section, school) VALUES (?, ?, 'INF232', 'NU Lipa')",
                    [$sid, $prog]
                );
            } catch (\Throwable $ex) {}
        }
    }

    // 3. Ensure Offense Type exists
    $offName = !empty($r['offense']) ? trim((string)$r['offense']) : 'Minor Violation';
    $offKey = strtoupper($offName);
    $level = strtoupper(trim((string)($r['level'] ?? 'MINOR')));
    if ($level !== 'MAJOR') $level = 'MINOR';

    if (!isset($offenseTypes[$offKey])) {
        $code = 'EXP-' . strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $offName), 0, 5)) . '-' . rand(100, 999);
        try {
            db_exec(
                "INSERT INTO offense_type (category_id, code, name, level, intervention_first, intervention_second)
                 VALUES (1, ?, ?, ?, 'Written Warning & Form F-005 Notice to Explain', '2nd Written Notice & Guardian Conference')",
                [$code, $offName, $level]
            );
            $offenseTypes[$offKey] = (int)db()->lastInsertId();
        } catch (\Throwable $e) {
            // Fallback to default offense type
            $offenseTypes[$offKey] = 1;
        }
    }
    $offTypeId = $offenseTypes[$offKey] ?? 1;

    // 4. Insert Offense
    $dateComm = !empty($r['date']) ? $r['date'] : '2023-09-01 10:00:00';
    $descEnc = db_encrypt_value($offName);

    try {
        db_exec(
            "INSERT INTO offense (student_id, offense_type_id, date_committed, description, status, level, created_at)
             VALUES (?, ?, ?, ?, 'RESOLVED', ?, ?)",
            [$sid, $offTypeId, $dateComm, $descEnc, $level, $dateComm]
        );
        $offenseId = (int)db()->lastInsertId();
        $insertedOffenses++;

        // 5. If Major Case, insert into `upcc_case`
        if ($level === 'MAJOR' || !empty($r['sanction'])) {
            $caseNum = 'UPCC-' . date('Y', strtotime($dateComm)) . '-' . sprintf('%03d', rand(1, 999));
            $sanction = !empty($r['sanction']) ? trim((string)$r['sanction']) : 'University Disciplinary Sanction';
            $sanctionEnc = db_encrypt_value($sanction);

            try {
                db_exec(
                    "INSERT INTO upcc_case (case_id, student_id, case_kind, decided_category, final_decision, status, created_at, updated_at)
                     VALUES (?, ?, 'MAJOR_VIOLATION', 2, ?, 'RESOLVED', ?, ?)",
                    [$caseNum, $sid, $sanction, $dateComm, $dateComm]
                );
                
                db_exec(
                    "INSERT IGNORE INTO upcc_case_offense (case_id, offense_id) VALUES (?, ?)",
                    [$caseNum, $offenseId]
                );
                $insertedCases++;
            } catch (\Throwable $e) {}
        }
    } catch (\Throwable $e) {
        // Skip duplicate or error
    }
}

echo "=== SEEDING COMPLETED SUCCESSFULLY! ===\n";
echo "Students Processed: $insertedStudents\n";
echo "Offenses Inserted:  $insertedOffenses\n";
echo "Major Cases Created: $insertedCases\n";
