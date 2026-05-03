<?php
declare(strict_types=1);

// TEMP DEBUG (remove after working)
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/../../database/database.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function json_out(bool $ok, string $message = '', $data = null, int $status = 200): void {
  http_response_code($status);
  echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data]);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(false, 'Method not allowed.', null, 405);
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

$studentId = trim((string)($body['student_id'] ?? ''));
if ($studentId === '') json_out(false, 'student_id is required.', null, 400);

// Student info
$student = db_one(
  "SELECT student_id, student_fn, student_ln, program, year_level, is_active
   FROM student
   WHERE student_id = :sid
   LIMIT 1",
  [':sid' => $studentId]
);

if (!$student) json_out(false, 'Student not found.', null, 404);
if ((int)$student['is_active'] !== 1) json_out(false, 'Student is not active.', null, 403);

$studentName = trim(((string)$student['student_fn'] . ' ' . (string)$student['student_ln']));

// Total Offenses
$totalRow = db_one(
  "SELECT COUNT(*) AS cnt FROM offense WHERE student_id = :sid AND status <> 'VOID'",
  [':sid' => $studentId]
);
$total = (int)($totalRow['cnt'] ?? 0);

// Minor/Major Offenses
$displayRow = db_one(
  "SELECT
      SUM(CASE WHEN level = 'MINOR' THEN 1 ELSE 0 END) AS minor_offense,
      SUM(CASE WHEN level = 'MAJOR' THEN 1 ELSE 0 END) AS major_offense
   FROM offense
   WHERE student_id = :sid
     AND status <> 'VOID'
",
  [':sid' => $studentId]
);

$minorCount = (int)($displayRow['minor_offense'] ?? 0);
$majorCount = (int)($displayRow['major_offense'] ?? 0);

// Include Section 4 Cases in Major count
$section4Count = db_one(
  "SELECT COUNT(*) AS c FROM upcc_case WHERE student_id = :sid AND case_kind = 'SECTION4_MINOR_ESCALATION' AND status <> 'VOID'",
  [':sid' => $studentId]
);
$majorCount += (int)($section4Count['c'] ?? 0);

$minor = $minorCount;
$major = $majorCount;

// Mark as acknowledged
db_exec(
  "UPDATE offense SET acknowledged_at = NOW() WHERE student_id = :sid AND acknowledged_at IS NULL",
  [':sid' => $studentId]
);

// List of offenses
$rows = db_all(
  "SELECT
      o.offense_id,
      o.level,
      o.status,
      o.description,
      o.date_committed,
      o.acknowledged_at,
      o.is_deleted_by_student,
      ot.code AS offense_code,
      ot.name AS offense_name,
      (SELECT status FROM student_appeal_request sar WHERE sar.offense_id = o.offense_id AND sar.appeal_kind = 'OFFENSE' ORDER BY appeal_id DESC LIMIT 1) AS appeal_status,
      uc.case_id AS upcc_case_id,
      uc.student_explanation_text,
      uc.student_explanation_image,
      uc.student_explanation_pdf,
      uc.student_explanation_at,
      uc.status AS upcc_case_status
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   LEFT JOIN upcc_case_offense uco ON uco.offense_id = o.offense_id
   LEFT JOIN upcc_case uc ON uc.case_id = uco.case_id AND uc.status <> 'VOID'
   WHERE o.student_id = :sid
     AND (o.status <> 'VOID' OR (SELECT status FROM student_appeal_request sar WHERE sar.offense_id = o.offense_id AND sar.appeal_kind = 'OFFENSE' ORDER BY appeal_id DESC LIMIT 1) = 'APPROVED')
   ORDER BY o.date_committed DESC, o.offense_id DESC",
  [':sid' => $studentId]
);

$minorList = [];
$majorList = [];

foreach ($rows as $r) {
  if ($r['level'] === 'MINOR') {
    $minorList[] = $r;
  } else {
    $majorList[] = $r;
  }
}

$minorList = array_reverse($minorList);

$bundledItems = [];
$bundleCount = 0;
$items = [];

$resolvedCasesMap = []; 
$resCases = db_all("
  SELECT c.case_id, c.decided_category, c.final_decision, c.resolution_date, co.offense_id, c.status,
         (SELECT status FROM student_appeal_request sar WHERE sar.case_id = c.case_id AND sar.appeal_kind = 'UPCC_CASE' ORDER BY appeal_id DESC LIMIT 1) AS appeal_status
  FROM upcc_case c
  JOIN upcc_case_offense co ON co.case_id = c.case_id
  WHERE c.student_id = :sid 
    AND (c.status = 'RESOLVED' OR (c.status = 'CANCELLED' AND (SELECT status FROM student_appeal_request sar WHERE sar.case_id = c.case_id AND sar.appeal_kind = 'UPCC_CASE' ORDER BY appeal_id DESC LIMIT 1) = 'APPROVED'))
", [':sid' => $studentId]);
foreach ($resCases as $rc) {
  $resolvedCasesMap[(int)$rc['offense_id']] = $rc;
}

// Find Section 4 cases for bundled items
$section4Cases = db_all("
  SELECT case_id, status, student_explanation_text, student_explanation_image, student_explanation_pdf, student_explanation_at
  FROM upcc_case
  WHERE student_id = :sid AND case_kind = 'SECTION4_MINOR_ESCALATION' AND status <> 'VOID'
", [':sid' => $studentId]);

for ($i = 0; $i < count($minorList); $i++) {
  $bundledItems[] = $minorList[$i];
  if (count($bundledItems) === 3) {
    $bundleCount++;
    $latestDate = $bundledItems[2]['date_committed'];
    
    $status = 'ACTIVE';
    $descAddition = "";
    $appealStatus = "";
    $caseId = null;
    $explanation = null;
    $expImage = null;
    $expPdf = null;
    $expAt = null;
    
    foreach ($bundledItems as $bi) {
      if (isset($bi['upcc_case_id']) && $bi['upcc_case_id'] !== null) {
          $caseId = (int)$bi['upcc_case_id'];
          $status = (string)$bi['upcc_case_status'];
          $explanation = $bi['student_explanation_text'];
          $expImage = $bi['student_explanation_image'];
          $expPdf = $bi['student_explanation_pdf'];
          $expAt = $bi['student_explanation_at'];
          break;
      }
    }

    foreach ($bundledItems as $bi) {
      $oid = (int)$bi['offense_id'];
      if (isset($resolvedCasesMap[$oid])) {
        $rc = $resolvedCasesMap[$oid];
        $status = (string)$rc['status'];
        $appealStatus = (string)($rc['appeal_status'] ?? '');
        $descAddition = "\n\n--- UPCC FINAL DECISION ---\nCategory " . $rc['decided_category'] . "\n" . $rc['final_decision'] . "\nResolved on: " . date('M d, Y', strtotime($rc['resolution_date']));
        break;
      }
    }
    
    $desc = "Triggered by the accumulation of 3 Minor Offenses:\n\n" . 
            "• " . trim(((string)$bundledItems[0]['offense_code']) . ' ' . ((string)$bundledItems[0]['offense_name'])) . " (" . date('M d, Y', strtotime($bundledItems[0]['date_committed'])) . ")\n" .
            "• " . trim(((string)$bundledItems[1]['offense_code']) . ' ' . ((string)$bundledItems[1]['offense_name'])) . " (" . date('M d, Y', strtotime($bundledItems[1]['date_committed'])) . ")\n" .
            "• " . trim(((string)$bundledItems[2]['offense_code']) . ' ' . ((string)$bundledItems[2]['offense_name'])) . " (" . date('M d, Y', strtotime($bundledItems[2]['date_committed'])) . ")";
    
    if ($descAddition !== '') {
      $desc .= $descAddition;
    }

    $isAllHidden = true;
    foreach ($bundledItems as $bi) {
      if (((int)($bi['is_deleted_by_student'] ?? 0)) === 0) {
        $isAllHidden = false;
        break;
      }
    }

    $items[] = [
      'offense_id' => -1000 - $bundleCount,
      'level' => 'MAJOR',
      'status' => $status,
      'date_committed' => $latestDate,
      'acknowledged_at' => $bundledItems[2]['acknowledged_at'] ?? null,
      'is_deleted_by_student' => $isAllHidden,
      'title' => 'Section 4 Major Offense (Derived)',
      'description' => $desc,
      'is_bundle' => true,
      'appeal_status' => $appealStatus,
      'upcc_case_id' => $caseId,
      'explanation_text' => $explanation,
      'explanation_image' => $expImage,
      'explanation_pdf' => $expPdf,
      'explanation_at' => $expAt,
    ];

    foreach ($bundledItems as $bi) {
      $items[] = [
        'offense_id' => (int)$bi['offense_id'],
        'level' => 'MINOR',
        'status' => (string)$bi['status'],
        'date_committed' => (string)$bi['date_committed'],
        'acknowledged_at' => $bi['acknowledged_at'] ? (string)$bi['acknowledged_at'] : null,
        'is_deleted_by_student' => ((int)($bi['is_deleted_by_student'] ?? 0)) === 1,
        'title' => trim(((string)$bi['offense_code']) . ' ' . ((string)$bi['offense_name'])),
        'description' => (string)($bi['description'] ?? ''),
        'is_bundle' => false,
        'appeal_status' => (string)($bi['appeal_status'] ?? ''),
      ];
    }

    $bundledItems = [];
  }
}

foreach ($bundledItems as $r) {
  $items[] = [
    'offense_id' => (int)$r['offense_id'],
    'level' => 'MINOR',
    'status' => (string)$r['status'],
    'date_committed' => (string)$r['date_committed'],
    'acknowledged_at' => $r['acknowledged_at'] ? (string)$r['acknowledged_at'] : null,
    'is_deleted_by_student' => ((int)($r['is_deleted_by_student'] ?? 0)) === 1,
    'title' => trim(((string)$r['offense_code']) . ' ' . ((string)$r['offense_name'])),
    'description' => (string)($r['description'] ?? ''),
    'is_bundle' => false,
    'appeal_status' => (string)($r['appeal_status'] ?? ''),
  ];
}

foreach ($majorList as $r) {
  $oid = (int)$r['offense_id'];
  $status = (string)$r['status'];
  $desc = (string)($r['description'] ?? '');
  $appealStatus = (string)($r['appeal_status'] ?? '');
  $caseId = $r['upcc_case_id'] ? (int)$r['upcc_case_id'] : null;
  $explanation = $r['student_explanation_text'];
  $expImage = $r['student_explanation_image'];
  $expPdf = $r['student_explanation_pdf'];
  $expAt = $r['student_explanation_at'];

  if (isset($resolvedCasesMap[$oid])) {
    $rc = $resolvedCasesMap[$oid];
    $status = (string)$rc['status'];
    $appealStatus = (string)($rc['appeal_status'] ?? '');
    $desc .= "\n\n--- UPCC FINAL DECISION ---\nCategory " . $rc['decided_category'] . "\n" . $rc['final_decision'] . "\nResolved on: " . date('M d, Y', strtotime($rc['resolution_date']));
  }

  $items[] = [
    'offense_id' => $oid,
    'level' => 'MAJOR',
    'status' => $status,
    'date_committed' => (string)$r['date_committed'],
    'acknowledged_at' => $r['acknowledged_at'] ? (string)$r['acknowledged_at'] : null,
    'is_deleted_by_student' => ((int)($r['is_deleted_by_student'] ?? 0)) === 1,
    'title' => trim(((string)$r['offense_code']) . ' ' . ((string)$r['offense_name'])),
    'description' => trim($desc),
    'is_bundle' => false,
    'appeal_status' => $appealStatus,
    'upcc_case_id' => $caseId,
    'explanation_text' => $explanation,
    'explanation_image' => $expImage,
    'explanation_pdf' => $expPdf,
    'explanation_at' => $expAt,
  ];
}

usort($items, function($a, $b) {
  return strtotime($b['date_committed']) <=> strtotime($a['date_committed']);
});

json_out(true, 'Offense list loaded.', [
  'student' => [
    'student_id' => (string)$student['student_id'],
    'student_name' => $studentName,
    'program' => (string)($student['program'] ?? ''),
    'year_level' => (int)($student['year_level'] ?? 0),
  ],
  'counts' => [
    'total_offense' => $total,
    'minor_offense' => $minor,
    'major_offense' => $major,
  ],
  'items' => $items,
]);