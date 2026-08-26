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
if (strtoupper($month) === 'ALL') {
  $monthStart = '1970-01-01 00:00:00';
  $monthEnd = '2099-12-31 23:59:59';
} else {
  if (!preg_match('/^\d{4}-\d{2}$/', $month)) $month = date('Y-m');
  $monthStart = $month . '-01 00:00:00';
  $monthEnd = date('Y-m-t 23:59:59', strtotime($monthStart));
}

$audience = strtoupper(trim((string)($_GET['audience'] ?? 'ALL')));
if (!in_array($audience, ['ALL', 'COLLEGE', 'SHS'], true)) $audience = 'ALL';

$category = strtoupper(trim((string)($_GET['category'] ?? 'ALL')));

$segmentExpr = "(CASE WHEN (LOWER(COALESCE(s.school,'')) LIKE '%senior high%' OR UPPER(COALESCE(s.school,'')) = 'SHS' OR UPPER(COALESCE(s.program,'')) LIKE '%SHS%') THEN 'SHS' ELSE 'COLLEGE' END)";
$audienceClause = '';
if ($audience === 'SHS') {
  $audienceClause = " AND $segmentExpr = 'SHS' ";
} elseif ($audience === 'COLLEGE') {
  $audienceClause = " AND $segmentExpr = 'COLLEGE' ";
}

$categoryClause = "";
if ($category === 'MINOR') {
    $categoryClause = " AND ot.level = 'MINOR' ";
} elseif ($category === 'MAJOR_SANCTIONS' || $category === 'SANCTIONS' || $category === 'MAJOR') {
    $categoryClause = " AND (ot.level = 'MAJOR' OR uc.case_id IS NOT NULL OR (uc.final_decision IS NOT NULL AND uc.final_decision != '')) ";
} elseif ($category === 'DISMISSED') {
    $categoryClause = " AND (COALESCE(o.status,'') = 'DISMISSED' OR COALESCE(ot.level,'') = 'DISMISSED' OR COALESCE(o.level,'') = 'DISMISSED' OR uc.status = 'DISMISSED') ";
}

// Clause to exclude dismissed offenses & cases from active report totals
$dismissClause = " AND COALESCE(o.status,'') != 'DISMISSED'
                   AND COALESCE(ot.level,'') != 'DISMISSED'
                   AND COALESCE(o.level,'') != 'DISMISSED'
                   AND o.offense_id NOT IN (
                       SELECT uco.offense_id
                       FROM upcc_case_offense uco
                       JOIN upcc_case uc ON uc.case_id = uco.case_id
                       WHERE uc.status = 'DISMISSED'
                   ) ";

$activeFilter = ($category === 'DISMISSED') ? ($audienceClause . $categoryClause) : ($audienceClause . $dismissClause . $categoryClause);

// -------------------- Stats --------------------
$totalRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM offense o
   JOIN student s ON s.student_id = o.student_id
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   LEFT JOIN upcc_case_offense uco ON uco.offense_id = o.offense_id
   LEFT JOIN upcc_case uc ON uc.case_id = uco.case_id
   WHERE o.date_committed BETWEEN ? AND ? $activeFilter",
  [$monthStart, $monthEnd]
);

$minorRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   JOIN student s ON s.student_id = o.student_id
   WHERE o.date_committed BETWEEN ? AND ?
     AND ot.level = 'MINOR' $audienceClause $dismissClause",
  [$monthStart, $monthEnd]
);

$majorRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   JOIN student s ON s.student_id = o.student_id
   WHERE o.date_committed BETWEEN ? AND ?
     AND ot.level = 'MAJOR' $audienceClause $dismissClause",
  [$monthStart, $monthEnd]
);

$dismissedOffensesRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM offense o
   JOIN student s ON s.student_id = o.student_id
   LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   WHERE o.date_committed BETWEEN ? AND ? $audienceClause
     AND (
       COALESCE(o.status,'') = 'DISMISSED'
       OR COALESCE(ot.level,'') = 'DISMISSED'
       OR COALESCE(o.level,'') = 'DISMISSED'
       OR o.offense_id IN (
           SELECT uco.offense_id
           FROM upcc_case_offense uco
           JOIN upcc_case uc ON uc.case_id = uco.case_id
           WHERE uc.status = 'DISMISSED'
       )
     )",
  [$monthStart, $monthEnd]
);

$dismissedUnlinkedCasesRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM upcc_case uc
   JOIN student s ON s.student_id = uc.student_id
   WHERE uc.status = 'DISMISSED'
     AND uc.created_at BETWEEN ? AND ?
     AND uc.case_id NOT IN (SELECT DISTINCT case_id FROM upcc_case_offense WHERE case_id IS NOT NULL)
     $audienceClause",
  [$monthStart, $monthEnd]
);

require_once __DIR__ . '/../data/historical_dataset_cache.php';

$hRecords = function_exists('get_filtered_historical_records') ? get_filtered_historical_records($monthStart, $monthEnd, $audience, $category) : [];

$hTotal = count($hRecords);
$hMinor = 0;
$hMajor = 0;
$hDismissed = 0;
$hBreakdownMap = [];
$hCoursesMap = [];

function clean_format_offense_name(string $rawName, string $level, bool $isDismissed): string {
    $clean = trim($rawName);
    $clean = preg_replace('/\s*\((Minor|Major|Dismissed|minor|major|dismissed)\)$/i', '', $clean);
    if ($isDismissed) {
        return "$clean (Dismissed)";
    }
    $lvl = (strtoupper(trim($level)) === 'MAJOR') ? 'Major' : 'Minor';
    return "$clean ($lvl)";
}

foreach ($hRecords as $hr) {
    $lvl = strtoupper($hr['level'] ?? 'MINOR');
    $isDismissed = strpos(strtoupper($hr['sanction'] ?? ''), 'DISMISS') !== false;
    if ($lvl === 'MINOR') $hMinor++;
    else $hMajor++;

    if ($isDismissed) {
        $hDismissed++;
    }

    $offName = $hr['offense'] ?? 'Minor Offense';
    $labelName = clean_format_offense_name($offName, $lvl, $isDismissed);
    if (!isset($hBreakdownMap[$labelName])) $hBreakdownMap[$labelName] = 0;
    $hBreakdownMap[$labelName]++;

    $prog = !empty($hr['program']) ? $hr['program'] : 'N/A';
    if (!isset($hCoursesMap[$prog])) $hCoursesMap[$prog] = 0;
    $hCoursesMap[$prog]++;
}

$totalCount = (int)($totalRow['cnt'] ?? 0) + $hTotal;
$minorCount = (int)($minorRow['cnt'] ?? 0) + $hMinor;
$majorCount = (int)($majorRow['cnt'] ?? 0) + $hMajor;
$dismissedCount = (int)($dismissedOffensesRow['cnt'] ?? 0) + (int)($dismissedUnlinkedCasesRow['cnt'] ?? 0) + $hDismissed;

// Active UPCC cases count (filtered by chosen month date range and audience)
if ($monthStart === '1970-01-01 00:00:00') {
    $upccActiveRow = db_one(
        "SELECT COUNT(*) AS cnt
         FROM upcc_case uc
         JOIN student s ON s.student_id = uc.student_id
         WHERE uc.status IN ('PENDING', 'UNDER_INVESTIGATION', 'UNDER_APPEAL')
         $audienceClause"
    );
} else {
    $upccActiveRow = db_one(
        "SELECT COUNT(*) AS cnt
         FROM upcc_case uc
         JOIN student s ON s.student_id = uc.student_id
         WHERE uc.status IN ('PENDING', 'UNDER_INVESTIGATION', 'UNDER_APPEAL')
           AND uc.created_at BETWEEN ? AND ?
         $audienceClause",
        [$monthStart, $monthEnd]
    );
}
$activeCases = (int)($upccActiveRow['cnt'] ?? 0);

// -------------------- Breakdown (this month) --------------------
$breakdownRows = db_all(
  "SELECT
      ot.offense_type_id,
      ot.name,
      ot.code,
      ot.level,
      o.status AS offense_status,
      uc.status AS case_status,
      COUNT(*) AS cnt
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   JOIN student s ON s.student_id = o.student_id
   LEFT JOIN upcc_case_offense uco ON uco.offense_id = o.offense_id
   LEFT JOIN upcc_case uc ON uc.case_id = uco.case_id
   WHERE o.date_committed BETWEEN ? AND ?
   $audienceClause
   GROUP BY ot.offense_type_id, ot.name, ot.code, ot.level, o.status, uc.status
   ORDER BY cnt DESC, ot.name ASC",
  [$monthStart, $monthEnd]
);

$combinedBreakdownMap = [];
foreach ($breakdownRows as $r) {
  $name = (string)$r['name'];
  $isDismissed = ($r['offense_status'] === 'DISMISSED' || $r['case_status'] === 'DISMISSED' || strtoupper((string)$r['level']) === 'DISMISSED');
  $labelName = clean_format_offense_name($name, (string)$r['level'], $isDismissed);
  $cnt = (int)$r['cnt'];
  $combinedBreakdownMap[$labelName] = ($combinedBreakdownMap[$labelName] ?? 0) + $cnt;
}
foreach ($hBreakdownMap as $labelName => $cnt) {
  $combinedBreakdownMap[$labelName] = ($combinedBreakdownMap[$labelName] ?? 0) + $cnt;
}
arsort($combinedBreakdownMap);

$topN = 6;
$pieLabels = [];
$pieCounts = [];
$pieColors = ['#0d6efd', '#198754', '#ffc107', '#dc3545', '#6f42c1', '#fd7e14', '#6c757d'];

$detailed = [];
$othersCount = 0;
$idx = 0;

foreach ($combinedBreakdownMap as $labelName => $cnt) {
  $levelStr = (strpos($labelName, 'Major') !== false) ? 'MAJOR' : 'MINOR';
  $detailed[] = ['name' => $labelName, 'code' => 'INF', 'level' => $levelStr, 'cnt' => $cnt];

  if ($idx < $topN) {
    $pieLabels[] = $labelName;
    $pieCounts[] = $cnt;
  } else {
    $othersCount += $cnt;
  }
  $idx++;
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
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   JOIN student s ON s.student_id = o.student_id
   LEFT JOIN upcc_case_offense uco ON uco.offense_id = o.offense_id
   LEFT JOIN upcc_case uc ON uc.case_id = uco.case_id
   WHERE o.date_committed BETWEEN ? AND ?
   $activeFilter
   GROUP BY program
   ORDER BY cnt DESC, program ASC
   LIMIT 8",
  [$monthStart, $monthEnd]
);

$combinedCoursesMap = [];
foreach ($courses as $c) {
  $prog = (string)$c['program'];
  $cnt = (int)$c['cnt'];
  $combinedCoursesMap[$prog] = ($combinedCoursesMap[$prog] ?? 0) + $cnt;
}
foreach ($hCoursesMap as $prog => $cnt) {
  $combinedCoursesMap[$prog] = ($combinedCoursesMap[$prog] ?? 0) + $cnt;
}
arsort($combinedCoursesMap);

$sectionRows = db_all(
  "SELECT
      COALESCE(NULLIF(s.program,''), 'N/A') AS program,
      COALESCE(NULLIF(s.section,''), 'N/A') AS section,
      COUNT(*) AS cnt
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   JOIN student s ON s.student_id = o.student_id
   LEFT JOIN upcc_case_offense uco ON uco.offense_id = o.offense_id
   LEFT JOIN upcc_case uc ON uc.case_id = uco.case_id
   WHERE o.date_committed BETWEEN ? AND ?
   $activeFilter
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

$academicCoursesMap = [];
$unspecifiedCount = 0;

foreach ($combinedCoursesMap as $prog => $cnt) {
  if (strpos($prog, 'General Student Body') !== false || $prog === 'N/A' || $prog === 'UNSPECIFIED') {
    $unspecifiedCount += $cnt;
  } else {
    $academicCoursesMap[$prog] = ($academicCoursesMap[$prog] ?? 0) + $cnt;
  }
}
arsort($academicCoursesMap);

$courseLabels = [];
$courseCounts = [];
foreach (array_slice($academicCoursesMap, 0, 8, true) as $prog => $cnt) {
  $courseLabels[] = (string)$prog;
  $courseCounts[] = (int)$cnt;
}

if (empty($courseLabels) && $unspecifiedCount > 0) {
  $courseLabels[] = 'General Student Body';
  $courseCounts[] = $unspecifiedCount;
}

$topCourse = $courseLabels[0] ?? '';

// -------------------- Trend (last 6 months) --------------------
$trendMonths = [];
$trendMinor = [];
$trendMajor = [];

for ($i = 5; $i >= 0; $i--) {
  $mStart = date('Y-m-01 00:00:00', strtotime($monthStart . " -$i months"));
  $mEnd   = date('Y-m-t 23:59:59', strtotime($mStart));

  $trendMonths[] = date('M Y', strtotime($mStart));

  $mMinor = db_one(
    "SELECT COUNT(*) AS cnt
     FROM offense o
     JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
     JOIN student s ON s.student_id = o.student_id
     WHERE o.date_committed BETWEEN ? AND ?
       AND ot.level='MINOR' $audienceClause $dismissClause",
    [$mStart, $mEnd]
  );

  $mMajor = db_one(
    "SELECT COUNT(*) AS cnt
     FROM offense o
     JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
     JOIN student s ON s.student_id = o.student_id
     WHERE o.date_committed BETWEEN ? AND ?
       AND ot.level='MAJOR' $audienceClause $dismissClause",
    [$mStart, $mEnd]
  );

  $hTrendRecords = function_exists('get_filtered_historical_records') ? get_filtered_historical_records($mStart, $mEnd, $audience, $category) : [];
  $hTrendMinor = 0;
  $hTrendMajor = 0;
  foreach ($hTrendRecords as $htr) {
    if (strtoupper($htr['level'] ?? 'MINOR') === 'MINOR') $hTrendMinor++;
    else $hTrendMajor++;
  }

  $trendMinor[] = (int)($mMinor['cnt'] ?? 0) + $hTrendMinor;
  $trendMajor[] = (int)($mMajor['cnt'] ?? 0) + $hTrendMajor;
}

// Compute available months for this specific audience and category
$availableMonthsMap = [];

$mysqlMonths = db_all(
    "SELECT DISTINCT DATE_FORMAT(o.date_committed, '%Y-%m') AS ym
     FROM offense o
     JOIN student s ON s.student_id = o.student_id
     JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
     LEFT JOIN upcc_case_offense uco ON uco.offense_id = o.offense_id
     LEFT JOIN upcc_case uc ON uc.case_id = uco.case_id
     WHERE o.date_committed IS NOT NULL $activeFilter
     ORDER BY ym DESC"
);
foreach ($mysqlMonths as $mm) {
    if (!empty($mm['ym'])) $availableMonthsMap[$mm['ym']] = true;
}

$maxYM = date('Y-m');
if (function_exists('get_filtered_historical_records')) {
    $allCatRecords = get_filtered_historical_records('1970-01-01 00:00:00', '2099-12-31 23:59:59', $audience, $category);
    foreach ($allCatRecords as $hr) {
        if (!empty($hr['date'])) {
            $ym = date('Y-m', strtotime($hr['date']));
            if ($ym <= $maxYM) {
                $availableMonthsMap[$ym] = true;
            }
        }
    }
}

$availableMonths = array_keys($availableMonthsMap);
usort($availableMonths, function($a, $b) { return strcmp($b, $a); });

echo json_encode([
  'ok' => true,
  'month' => $month,
  'audience' => $audience,
  'category' => $category,
  'availableMonths' => $availableMonths,
  'stats' => [
    'total' => $totalCount,
    'minor' => $minorCount,
    'major' => $majorCount,
    'active_cases' => $activeCases,
    'dismissed' => $dismissedCount,
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