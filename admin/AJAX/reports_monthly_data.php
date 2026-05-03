<?php
// File: C:\xampp\htdocs\identitrack\admin\AJAX\reports_monthly_data.php
// Returns JSON for reports.php (month-based)
// Supports:
// - stats
// - offense breakdown (topN + others + full detailed list)
// - top courses + top course sections
// - trend (last 6 months)

require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$month = trim((string)($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
$audience = strtoupper(trim((string)($_GET['audience'] ?? 'ALL')));
if (!in_array($audience, ['ALL', 'COLLEGE', 'SHS'], true)) $audience = 'ALL';

$monthStart = $month . '-01 00:00:00';
$monthEnd = date('Y-m-t 23:59:59', strtotime($monthStart));

$segmentExpr = "(CASE WHEN (LOWER(COALESCE(s.school,'')) LIKE '%senior high%' OR UPPER(COALESCE(s.school,'')) = 'SHS' OR UPPER(COALESCE(s.program,'')) LIKE '%SHS%') THEN 'SHS' ELSE 'COLLEGE' END)";
$audienceClause = '';
if ($audience === 'SHS') {
  $audienceClause = " AND $segmentExpr = 'SHS' ";
} elseif ($audience === 'COLLEGE') {
  $audienceClause = " AND $segmentExpr = 'COLLEGE' ";
}

// -------------------- Stats --------------------
$totalRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM offense o
   JOIN student s ON s.student_id = o.student_id
   WHERE o.date_committed BETWEEN ? AND ? $audienceClause",
  [$monthStart, $monthEnd]
);

$minorRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   JOIN student s ON s.student_id = o.student_id
   WHERE o.date_committed BETWEEN ? AND ?
     AND ot.level = 'MINOR' $audienceClause",
  [$monthStart, $monthEnd]
);

$majorRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   JOIN student s ON s.student_id = o.student_id
   WHERE o.date_committed BETWEEN ? AND ?
     AND ot.level = 'MAJOR' $audienceClause",
  [$monthStart, $monthEnd]
);

$totalCount = (int)($totalRow['cnt'] ?? 0);
$minorCount = (int)($minorRow['cnt'] ?? 0);
$majorCount = (int)($majorRow['cnt'] ?? 0);

// Active cases placeholder (no schema yet)
$activeCases = 0;

// -------------------- Breakdown (this month) --------------------
$breakdownRows = db_all(
  "SELECT
      ot.offense_type_id,
      ot.name,
      ot.code,
      ot.level,
      COUNT(*) AS cnt
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
  JOIN student s ON s.student_id = o.student_id
   WHERE o.date_committed BETWEEN ? AND ?
  $audienceClause
   GROUP BY ot.offense_type_id, ot.name, ot.code, ot.level
   ORDER BY cnt DESC, ot.name ASC",
  [$monthStart, $monthEnd]
);

$topN = 6;
$pieLabels = [];
$pieCounts = [];
$pieColors = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6f42c1', '#fd7e14', '#6c757d'];

$detailed = [];
$othersCount = 0;

foreach ($breakdownRows as $idx => $r) {
  $name = (string)$r['name'];
  $level = ucfirst(strtolower((string)$r['level']));
  $labelName = "$name ($level)";
  $cnt = (int)$r['cnt'];
  $detailed[] = ['name' => $labelName, 'code' => (string)$r['code'], 'level' => (string)$r['level'], 'cnt' => $cnt];

  if ($idx < $topN) {
    $pieLabels[] = $labelName;
    $pieCounts[] = $cnt;
  } else {
    $othersCount += $cnt;
  }
}

if ($othersCount > 0) {
  $pieLabels[] = 'Others';
  $pieCounts[] = $othersCount;
}

// -------------------- Top Courses --------------------
$courses = db_all(
  "SELECT
      COALESCE(NULLIF(s.program,''), 'N/A') AS program,
      COUNT(*) AS cnt
   FROM offense o
   JOIN student s ON s.student_id = o.student_id
   WHERE o.date_committed BETWEEN ? AND ?
  $audienceClause
   GROUP BY program
   ORDER BY cnt DESC, program ASC
   LIMIT 8",
  [$monthStart, $monthEnd]
);

$sectionRows = db_all(
  "SELECT
      COALESCE(NULLIF(s.program,''), 'N/A') AS program,
      COALESCE(NULLIF(s.section,''), 'N/A') AS section,
      COUNT(*) AS cnt
   FROM offense o
   JOIN student s ON s.student_id = o.student_id
   WHERE o.date_committed BETWEEN ? AND ?
  $audienceClause
   GROUP BY program, section
   ORDER BY program ASC, cnt DESC, section ASC",
  [$monthStart, $monthEnd]
);

$sectionsByProgram = [];
foreach ($sectionRows as $sr) {
  $program = (string)$sr['program'];
  $section = (string)$sr['section'];

  if (!isset($sectionsByProgram[$program])) {
    $sectionsByProgram[$program] = [];
  }
  $sectionsByProgram[$program][] = $section;
}

$courseLabels = [];
$courseCounts = [];
foreach ($courses as $c) {
  $courseLabels[] = (string)$c['program'];
  $courseCounts[] = (int)$c['cnt'];
}

$topCourse = $courseLabels[0] ?? '';

// -------------------- Trend (last 6 months) --------------------
$trendMonths = [];
$trendMinor = [];
$trendMajor = [];

for ($i = 5; $i >= 0; $i--) {
  $mStart = date('Y-m-01 00:00:00', strtotime($monthStart . " -$i months"));
  $mEnd   = date('Y-m-t 23:59:59', strtotime($mStart));

  $trendMonths[] = date('M', strtotime($mStart));

  $mMinor = db_one(
    "SELECT COUNT(*) AS cnt
     FROM offense o
     JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
     JOIN student s ON s.student_id = o.student_id
     WHERE o.date_committed BETWEEN ? AND ?
       AND ot.level='MINOR' $audienceClause",
    [$mStart, $mEnd]
  );

  $mMajor = db_one(
    "SELECT COUNT(*) AS cnt
     FROM offense o
     JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
     JOIN student s ON s.student_id = o.student_id
     WHERE o.date_committed BETWEEN ? AND ?
       AND ot.level='MAJOR' $audienceClause",
    [$mStart, $mEnd]
  );

  $trendMinor[] = (int)($mMinor['cnt'] ?? 0);
  $trendMajor[] = (int)($mMajor['cnt'] ?? 0);
}

echo json_encode([
  'ok' => true,
  'month' => $month,
  'audience' => $audience,
  'stats' => [
    'total' => $totalCount,
    'minor' => $minorCount,
    'major' => $majorCount,
    'active_cases' => $activeCases,
  ],
  'breakdown' => [
    'pie' => [
      'labels' => $pieLabels,
      'counts' => $pieCounts,
      'colors' => array_slice($pieColors, 0, max(1, count($pieLabels))),
    ],
    'detailed' => $detailed,
  ],
  'courses' => [
    'labels' => $courseLabels,
    'counts' => $courseCounts,
    'top_course' => $topCourse,
    'list' => array_map(function ($c) use ($sectionsByProgram) {
      $program = (string)$c['program'];
      $sections = $sectionsByProgram[$program] ?? [];
      return [
        'program' => $program,
        'cnt' => (int)$c['cnt'],
        'sections' => $sections,
      ];
    }, $courses),
    'sections' => [],
  ],
  'trend' => [
    'labels' => $trendMonths,
    'minor' => $trendMinor,
    'major' => $trendMajor,
  ],
]);