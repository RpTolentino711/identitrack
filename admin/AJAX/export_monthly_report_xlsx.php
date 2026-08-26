<?php
// File: C:\xampp\htdocs\identitrack\admin\AJAX\export_monthly_report_xlsx.php
// Exports Monthly Discipline Report with summary stats, pie chart, bar chart, and raw data.

require_once __DIR__ . '/../../database/database.php';
require_admin();

$autoload = __DIR__ . '/../../vendor/autoload.php';
if (!file_exists($autoload)) {
    die("Composer autoload not found. Please run 'composer require phpoffice/phpspreadsheet'");
}
require_once $autoload;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Chart\Chart;
use PhpOffice\PhpSpreadsheet\Chart\DataSeries;
use PhpOffice\PhpSpreadsheet\Chart\DataSeriesValues;
use PhpOffice\PhpSpreadsheet\Chart\Legend;
use PhpOffice\PhpSpreadsheet\Chart\PlotArea;
use PhpOffice\PhpSpreadsheet\Chart\Title;

$month = trim((string)($_GET['month'] ?? ''));
if (strtoupper($month) === 'ALL') {
  $monthStart = '1970-01-01 00:00:00';
  $monthEnd = '2099-12-31 23:59:59';
  $titleMonthStr = 'ALL TIME';
} else {
  if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
    $month = date('Y-m');
  }
  $monthStart = $month . '-01 00:00:00';
  $monthEnd = date('Y-m-t 23:59:59', strtotime($monthStart));
  $titleMonthStr = strtoupper(date('F Y', strtotime($monthStart)));
}

$audience = strtoupper(trim((string)($_GET['audience'] ?? 'ALL')));
if (!in_array($audience, ['ALL', 'COLLEGE', 'SHS'], true)) $audience = 'ALL';

$segmentExpr = "(CASE WHEN (LOWER(COALESCE(s.school,'')) LIKE '%senior high%' OR UPPER(COALESCE(s.school,'')) = 'SHS' OR UPPER(COALESCE(s.program,'')) LIKE '%SHS%' OR UPPER(COALESCE(s.program,'')) LIKE '%STEM%' OR UPPER(COALESCE(s.program,'')) LIKE '%ABM%' OR UPPER(COALESCE(s.program,'')) LIKE '%HUMSS%' OR UPPER(COALESCE(s.program,'')) LIKE '%TVL%' OR UPPER(COALESCE(s.program,'')) LIKE '%GAS%') THEN 'SHS' ELSE 'COLLEGE' END)";
$audienceClause = '';
if ($audience === 'SHS') {
  $audienceClause = " AND $segmentExpr = 'SHS' ";
} elseif ($audience === 'COLLEGE') {
  $audienceClause = " AND $segmentExpr = 'COLLEGE' ";
}

$category = strtoupper(trim((string)($_GET['category'] ?? 'ALL')));

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

// 1. Fetch raw data
$params = [':start' => $monthStart, ':end' => $monthEnd];
db_add_encryption_key($params);

$rows = db_all(
  "SELECT
      o.offense_id,
      o.student_id,
      {$segmentExpr} AS segment,
      CONCAT(" . db_decrypt_col('student_ln', 's') . ", ', ', " . db_decrypt_col('student_fn', 's') . ") AS student_name,
      COALESCE(NULLIF(s.program,''), 'N/A') AS program,
      COALESCE(NULLIF(s.section,''), 'N/A') AS section,
      ot.level AS offense_level,
      ot.code AS offense_code,
      ot.name AS offense_name,
      ot.intervention_first,
      ot.intervention_second,
      o.status,
      o.date_committed,
      " . db_decrypt_col('description', 'o') . " AS description,
      uc.case_id,
      uc.case_kind,
      uc.decided_category,
      uc.final_decision,
      uc.status AS case_status
   FROM offense o
   JOIN student s ON s.student_id = o.student_id
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   LEFT JOIN upcc_case_offense uco ON uco.offense_id = o.offense_id
   LEFT JOIN upcc_case uc ON uc.case_id = uco.case_id
   WHERE o.date_committed BETWEEN :start AND :end
   $activeFilter
   ORDER BY o.date_committed DESC",
  $params
);

require_once __DIR__ . '/../data/historical_dataset_cache.php';

$hRecords = function_exists('get_filtered_historical_records') ? get_filtered_historical_records($monthStart, $monthEnd, $audience, $category) : [];

foreach ($hRecords as $hr) {
    $prog = strtoupper((string)($hr['program'] ?? ''));
    $isShs = (strpos($prog, 'SHS') !== false || strpos($prog, 'ABM') !== false || strpos($prog, 'STEM') !== false || strpos($prog, 'HUMSS') !== false || strpos($prog, 'TVL') !== false || strpos($prog, 'GAS') !== false || strpos($prog, 'HIGH') !== false);
    $rows[] = [
        'offense_id' => 'HIST-' . substr(md5(($hr['student_id'] ?? '') . ($hr['date'] ?? '') . ($hr['offense'] ?? '')), 0, 8),
        'student_id' => $hr['student_id'] ?? 'N/A',
        'segment' => $isShs ? 'SHS' : 'COLLEGE',
        'student_name' => $hr['name'] ?? 'N/A',
        'program' => $hr['program'] ?? 'N/A',
        'section' => 'INF232',
        'offense_level' => strtoupper($hr['level'] ?? 'MINOR'),
        'offense_code' => 'HIST-EXCEL',
        'offense_name' => $hr['offense'] ?? 'Minor Violation',
        'intervention_first' => 'Form F-005 Written Notice to Explain',
        'intervention_second' => '2nd Notice & Guardian Conference',
        'status' => strpos(strtoupper($hr['sanction'] ?? ''), 'DISMISS') !== false ? 'DISMISSED' : 'RESOLVED',
        'date_committed' => $hr['date'] ?? '2023-09-01 10:00:00',
        'description' => $hr['offense'] ?? 'Historical Dataset Record',
        'case_id' => '',
        'case_kind' => '',
        'decided_category' => 2,
        'final_decision' => $hr['sanction'] ?? '',
        'case_status' => strpos(strtoupper($hr['sanction'] ?? ''), 'DISMISS') !== false ? 'DISMISSED' : 'CLOSED'
    ];
}

// Group SHS students FIRST, College students SECOND when Audience is ALL
usort($rows, function($a, $b) {
    $segA = strtoupper((string)($a['segment'] ?? 'COLLEGE'));
    $segB = strtoupper((string)($b['segment'] ?? 'COLLEGE'));
    if ($segA !== $segB) {
        return ($segA === 'SHS') ? -1 : 1;
    }
    return strcmp((string)($b['date_committed'] ?? ''), (string)($a['date_committed'] ?? ''));
});

$dismissedRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM offense o
   JOIN student s ON s.student_id = o.student_id
   LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   WHERE o.date_committed BETWEEN :start AND :end $audienceClause
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
  [':start' => $monthStart, ':end' => $monthEnd]
);

$dismissedUnlinkedCasesRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM upcc_case uc
   JOIN student s ON s.student_id = uc.student_id
   WHERE uc.status = 'DISMISSED'
     AND uc.created_at BETWEEN :start AND :end
     AND uc.case_id NOT IN (SELECT DISTINCT case_id FROM upcc_case_offense WHERE case_id IS NOT NULL)
     $audienceClause",
  [':start' => $monthStart, ':end' => $monthEnd]
);

$dismissedCount = (int)($dismissedRow['cnt'] ?? 0) + (int)($dismissedUnlinkedCasesRow['cnt'] ?? 0);

// 2. Fetch stats
$total = count($rows);
$minor = 0;
$major = 0;
$activeCases = 0;
$breakdownMap = [];
$coursesMap = [];

foreach ($rows as $r) {
    if (strtoupper((string)($r['offense_level'] ?? '')) === 'MINOR') {
        $minor++;
    } else {
        $major++;
    }

    $status = strtoupper((string)($r['status'] ?? ''));
    if ($status === 'PENDING' || $status === 'UNDER_INVESTIGATION' || $status === 'UNDER_APPEAL') {
        $activeCases++;
    }

    // Pie chart map with Major/Minor label!
    $levelStr = ucfirst(strtolower((string)($r['offense_level'] ?? '')));
    $name = (string)($r['offense_name'] ?? 'Unknown');
    $labelName = "$name ($levelStr)";
    
    if (!isset($breakdownMap[$labelName])) {
        $breakdownMap[$labelName] = 0;
    }
    $breakdownMap[$labelName]++;

    // Bar chart map
    $prog = (string)($r['program'] ?? 'N/A');
    if (!isset($coursesMap[$prog])) {
        $coursesMap[$prog] = 0;
    }
    $coursesMap[$prog]++;
}

arsort($breakdownMap);
arsort($coursesMap);

try {
  $spreadsheet = new Spreadsheet();
  $sheet = $spreadsheet->getActiveSheet();
  $sheetTitle = 'Monthly Report';
  $sheet->setTitle($sheetTitle);
  
  $sheet->setShowGridlines(true);

  // Styling arrays
  $styleHeader = [
      'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 16],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B2B6B']],
  ];
  
  $styleSubHeader = [
      'font' => ['italic' => true, 'color' => ['argb' => 'FFCBD5E1'], 'size' => 10],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B2B6B']],
  ];

  $styleStatCardHeader = [
      'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FFCBD5E1']]],
  ];

  $styleTableHeader = [
      'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B2B6B']],
  ];
  
  $styleTableBody = [
      'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCBD5E1']]],
  ];

  // Header Banner
  $sheet->setCellValue('A1', 'NATIONAL UNIVERSITY LIPA — MONTHLY DISCIPLINE REPORT (' . $titleMonthStr . ')');
  $sheet->mergeCells('A1:M1');
  $sheet->getStyle('A1:M1')->applyFromArray($styleHeader);
  $sheet->getRowDimension(1)->setRowHeight(34);

  $sheet->setCellValue('A2', 'Student Discipline Office • Generated: ' . date('F j, Y g:i A') . ' • Target Audience: ' . $audience);
  $sheet->mergeCells('A2:M2');
  $sheet->getStyle('A2:M2')->applyFromArray($styleSubHeader);
  $sheet->getRowDimension(2)->setRowHeight(20);

  // Summary Metrics (Dashboard style - 6 Cards covering A4:M5)
  $cards = [
      'A' => ['label' => 'TOTAL OFFENSES', 'val' => $total, 'hdrColor' => 'FF1B2B6B', 'valColor' => 'FF1B2B6B', 'bgColor' => 'FFF8FAFC', 'span' => 'A4:B4', 'vSpan' => 'A5:B5'],
      'C' => ['label' => 'MINOR OFFENSES', 'val' => $minor, 'hdrColor' => 'FFB45309', 'valColor' => 'FFB45309', 'bgColor' => 'FFFEF3C7', 'span' => 'C4:D4', 'vSpan' => 'C5:D5'],
      'E' => ['label' => 'MAJOR OFFENSES', 'val' => $major, 'hdrColor' => 'FF991B1B', 'valColor' => 'FF991B1B', 'bgColor' => 'FFFEE2E2', 'span' => 'E4:F4', 'vSpan' => 'E5:F5'],
      'G' => ['label' => 'ACTIVE CASES', 'val' => $activeCases, 'hdrColor' => 'FF6B21A8', 'valColor' => 'FF6B21A8', 'bgColor' => 'FFF3E8FF', 'span' => 'G4:H4', 'vSpan' => 'G5:H5'],
      'I' => ['label' => 'DISMISSED OFFENSES', 'val' => (int)($dismissedRow['cnt'] ?? 0), 'hdrColor' => 'FF475569', 'valColor' => 'FF475569', 'bgColor' => 'FFF1F5F9', 'span' => 'I4:J4', 'vSpan' => 'I5:J5'],
      'K' => ['label' => 'DISMISSED CASES', 'val' => (int)($dismissedUnlinkedCasesRow['cnt'] ?? 0), 'hdrColor' => 'FF334155', 'valColor' => 'FF334155', 'bgColor' => 'FFE2E8F0', 'span' => 'K4:M4', 'vSpan' => 'K5:M5'],
  ];

  foreach ($cards as $colKey => $c) {
      $sheet->setCellValue($colKey . '4', $c['label']);
      $sheet->mergeCells($c['span']);
      $sheet->getStyle($c['span'])->applyFromArray([
          'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFFFFFFF']],
          'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
          'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $c['hdrColor']]],
      ]);

      $sheet->setCellValue($colKey . '5', $c['val']);
      $sheet->mergeCells($c['vSpan']);
      $sheet->getStyle($c['vSpan'])->applyFromArray([
          'font' => ['bold' => true, 'size' => 20, 'color' => ['argb' => $c['valColor']]],
          'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
          'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $c['bgColor']]],
          'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => $c['hdrColor']]]],
      ]);
  }
  $sheet->getRowDimension(4)->setRowHeight(18);
  $sheet->getRowDimension(5)->setRowHeight(34);

  // Hidden Data for Charts in Columns AA to AF
  $bRow = 5;
  foreach ($breakdownMap as $name => $count) {
      $sheet->setCellValue('AA' . $bRow, $name);
      $sheet->setCellValue('AB' . $bRow, $count);
      $bRow++;
  }
  $bEndRow = max(5, $bRow - 1);

  $cRow = 5;
  $topN = 8;
  foreach ($coursesMap as $prog => $count) {
      $sheet->setCellValue('AE' . $cRow, $prog);
      $sheet->setCellValue('AF' . $cRow, $count);
      $cRow++;
      if ($cRow >= 5 + $topN) break;
  }
  $cEndRow = max(5, $cRow - 1);

  // Create Doughnut / Pie Chart (Positions A7:F20)
  if (!empty($breakdownMap)) {
      $dataSeriesLabels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheetTitle}'!\$AB\$4", null, 1)];
      $xAxisTickValues = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheetTitle}'!\$AA\$5:\$AA\${$bEndRow}", null, count($breakdownMap))];
      $dataSeriesValues = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'{$sheetTitle}'!\$AB\$5:\$AB\${$bEndRow}", null, count($breakdownMap))];

      $series = new DataSeries(
          DataSeries::TYPE_DOUGHNUTCHART,
          null,
          range(0, count($dataSeriesValues) - 1),
          $dataSeriesLabels,
          $xAxisTickValues,
          $dataSeriesValues
      );
      
      $layout = new \PhpOffice\PhpSpreadsheet\Chart\Layout();
      $layout->setShowVal(true);
      $layout->setShowPercent(true);
      
      $plotArea = new PlotArea($layout, [$series]);
      $legend = new Legend(Legend::POSITION_BOTTOM, null, false);
      $chartTitle = new Title('Offense Breakdown Distribution');

      $chart = new Chart('chart1', $chartTitle, $legend, $plotArea, true, 0, null, null);
      $chart->setTopLeftPosition('A7');
      $chart->setBottomRightPosition('F20');
      $sheet->addChart($chart);
  }

  // Create Column Bar Chart (Positions G7:M20)
  if (!empty($coursesMap)) {
      $dataSeriesLabels2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheetTitle}'!\$AF\$4", null, 1)];
      $xAxisTickValues2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheetTitle}'!\$AE\$5:\$AE\${$cEndRow}", null, count($coursesMap))];
      $dataSeriesValues2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'{$sheetTitle}'!\$AF\$5:\$AF\${$cEndRow}", null, count($coursesMap))];

      $series2 = new DataSeries(
          DataSeries::TYPE_BARCHART,
          DataSeries::GROUPING_STANDARD,
          range(0, count($dataSeriesValues2) - 1),
          $dataSeriesLabels2,
          $xAxisTickValues2,
          $dataSeriesValues2
      );
      $series2->setPlotDirection(DataSeries::DIRECTION_COL);
      
      $layout2 = new \PhpOffice\PhpSpreadsheet\Chart\Layout();
      $layout2->setShowVal(true);

      $plotArea2 = new PlotArea($layout2, [$series2]);
      $chartTitle2 = new Title('Top Courses by Offenses');

      $chart2 = new Chart('chart2', $chartTitle2, null, $plotArea2, true, 0, null, null);
      $chart2->setTopLeftPosition('G7');
      $chart2->setBottomRightPosition('M20');
      $sheet->addChart($chart2);
  }

  // Raw Data Title Header
  $sheet->setCellValue('A21', 'DETAILED DISCIPLINARY LOGS & CASE RECORDS');
  $sheet->mergeCells('A21:M21');
  $sheet->getStyle('A21:M21')->applyFromArray([
      'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 13],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E293B']],
  ]);
  $sheet->getRowDimension(21)->setRowHeight(28);

  // Raw Data Section
  $headers = [
    'Offense ID', 'Academic Level', 'Student ID', 'Student Name', 'Program', 'Section',
    'Level', 'Offense Code', 'Offense Name', 'Status', 'Date Committed', 'Description',
    'Sanction / Penalty (NU Lipa Discipline Handbook)'
  ];

  $dataStartRow = 22;
  $sheet->fromArray($headers, null, 'A' . $dataStartRow);
  $sheet->getStyle('A'.$dataStartRow.':M'.$dataStartRow)->applyFromArray($styleTableHeader);
  $sheet->getRowDimension($dataStartRow)->setRowHeight(26);

  $rowIndex = $dataStartRow + 1;
  foreach ($rows as $r) {
    $offenseLevel = strtoupper((string)($r['offense_level'] ?? ''));
    $caseStatus = strtoupper((string)($r['case_status'] ?? ''));
    $caseKind = strtoupper((string)($r['case_kind'] ?? ''));
    $offenseStatus = strtoupper((string)($r['status'] ?? ''));
    $decidedCat = (int)($r['decided_category'] ?? 0);

    // Track sequence of minor offenses for this student up to this offense date
    $seqCount = 0;
    if ($offenseLevel === 'MINOR') {
        $seqCount = (int)(db_one(
            "SELECT COUNT(*) AS cnt FROM offense WHERE student_id = ? AND date_committed <= ? AND status <> 'VOID'",
            [$r['student_id'], $r['date_committed']]
        )['cnt'] ?? 1);
    }

    $isDismissed = ($caseStatus === 'DISMISSED' || $offenseStatus === 'DISMISSED');

    $displayLevel = $offenseLevel;
    if ($isDismissed) {
        $displayLevel = 'DISMISSED';
    } elseif ($decidedCat > 0) {
        $displayLevel = "MAJOR (CATEGORY {$decidedCat})";
    } elseif ($offenseLevel === 'MAJOR' || $seqCount >= 3) {
        $displayLevel = ($seqCount >= 3) ? 'SECTION 4 MAJOR' : 'MAJOR';
    } elseif ($offenseLevel === 'MINOR') {
        if ($seqCount === 2) {
            $displayLevel = '2ND MINOR WARNING';
        } elseif ($seqCount === 1) {
            $displayLevel = '1ST MINOR WARNING';
        } else {
            $displayLevel = 'MINOR WARNING';
        }
    }

    // Compute Sanction / Penalty string according to NU Lipa Discipline Handbook
    $sanctionStr = '';
    if ($isDismissed) {
        $sanctionStr = 'Case / Offense Dismissed (No Sanction Imposed)';
    } elseif (!empty($r['final_decision']) || $decidedCat > 0) {
        $catDescriptions = [
            1 => 'Category 1 (Formative Intervention & Written Warning)',
            2 => 'Category 2 (Formative Intervention & Community Service 5-10 Hours)',
            3 => 'Category 3 (Community Service 15-30 Hours / Short Suspension 1-3 Days)',
            4 => 'Category 4 (Extended Suspension 5-15 Days / Semester)',
            5 => 'Category 5 (Non-Readmission / Exclusion from NU Lipa)'
        ];
        $catLabel = $catDescriptions[$decidedCat] ?? ($decidedCat > 0 ? "Category {$decidedCat}" : "Decided Major Case");
        $decisionText = !empty($r['final_decision']) ? " - " . (string)$r['final_decision'] : "";
        $sanctionStr = "{$catLabel}{$decisionText}";
    } elseif ($offenseLevel === 'MINOR') {
        if ($seqCount === 1) {
            $interv = !empty($r['intervention_first']) ? " - " . $r['intervention_first'] : "";
            $sanctionStr = "1st Minor Offense (Written Warning & Form F-005 Notice to Explain{$interv})";
        } elseif ($seqCount === 2) {
            $interv = !empty($r['intervention_second']) ? " - " . $r['intervention_second'] : "";
            $sanctionStr = "2nd Minor Offense (2nd Minor Warning & Guardian Notified / Conference Required{$interv})";
        } else {
            $sanctionStr = "3rd Minor Offense — Section 4 Escalation (UPCC Hearing & Committee Required)";
        }
    } elseif ($offenseLevel === 'MAJOR') {
        $sanctionStr = "Major Offense (Pending UPCC Committee Hearing & Sanction)";
    } else {
        $sanctionStr = "Under Review";
    }

    $sheet->setCellValueExplicit('A' . $rowIndex, (string)($r['offense_id'] ?? ''), DataType::TYPE_STRING);
    $sheet->setCellValue('B' . $rowIndex, strtoupper((string)($r['segment'] ?? 'COLLEGE')));
    $sheet->setCellValueExplicit('C' . $rowIndex, (string)($r['student_id'] ?? ''), DataType::TYPE_STRING);
    $sheet->setCellValue('D' . $rowIndex, (string)($r['student_name'] ?? ''));
    $sheet->setCellValue('E' . $rowIndex, (string)($r['program'] ?? ''));
    $sheet->setCellValue('F' . $rowIndex, (string)($r['section'] ?? ''));
    $sheet->setCellValue('G' . $rowIndex, $displayLevel);
    $sheet->setCellValue('H' . $rowIndex, (string)($r['offense_code'] ?? ''));
    $sheet->setCellValue('I' . $rowIndex, (string)($r['offense_name'] ?? ''));
    $sheet->setCellValue('J' . $rowIndex, (string)($r['status'] ?? ''));
    $sheet->setCellValue('K' . $rowIndex, (string)($r['date_committed'] ?? ''));
    $sheet->setCellValue('L' . $rowIndex, (string)($r['description'] ?? ''));
    $sheet->setCellValue('M' . $rowIndex, $sanctionStr);

    // Apply color styling to Level (G) & Sanction (M) cells based on offense & sanction level
    if ($isDismissed) { // GRAY
        $sheet->getStyle('G' . $rowIndex)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF475569']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF1F5F9']]
        ]);
        $sheet->getStyle('M' . $rowIndex)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF475569']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF1F5F9']]
        ]);
    } elseif ($decidedCat > 0 || !empty($r['final_decision'])) { // GREEN (Resolved Category 1-5)
        $sheet->getStyle('G' . $rowIndex)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF15803D']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDCFCE7']]
        ]);
        $sheet->getStyle('M' . $rowIndex)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF15803D']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDCFCE7']]
        ]);
    } elseif ($offenseLevel === 'MAJOR' || strpos($displayLevel, 'SECTION 4') !== false || $seqCount >= 3) { // RED (Major / Section 4)
        $sheet->getStyle('G' . $rowIndex)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF991B1B']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFEE2E2']]
        ]);
        $sheet->getStyle('M' . $rowIndex)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF991B1B']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFEE2E2']]
        ]);
    } else { // YELLOW (1st Minor Warning / 2nd Minor Warning)
        $sheet->getStyle('G' . $rowIndex)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF854D0E']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFEF08A']]
        ]);
        $sheet->getStyle('M' . $rowIndex)->applyFromArray([
            'font' => ['bold' => true, 'color' => ['argb' => 'FF854D0E']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFEF08A']]
        ]);
    }

    $rowIndex++;
  }

  if ($rowIndex > $dataStartRow + 1) {
      $sheet->getStyle('A'.($dataStartRow + 1).':M'.($rowIndex - 1))->applyFromArray($styleTableBody);
  }

  foreach (range('A', 'M') as $col) {
    $sheet->getColumnDimension($col)->setAutoSize(true);
  }

  $sheet->freezePane('A' . ($dataStartRow + 1));

  while (ob_get_level() > 0) {
    ob_end_clean();
  }

  $filename = 'monthly_discipline_report_' . strtolower($audience) . '_' . $month . '.xlsx';
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: max-age=0');
  
  $writer = new Xlsx($spreadsheet);
  $writer->setIncludeCharts(true);
  $writer->save('php://output');
  exit;
} catch (\Throwable $e) {
  die("Error generating Excel with charts: " . $e->getMessage());
}