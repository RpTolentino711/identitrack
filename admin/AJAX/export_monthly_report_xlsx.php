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

$segmentExpr = "(CASE WHEN (LOWER(COALESCE(s.school,'')) LIKE '%senior high%' OR UPPER(COALESCE(s.school,'')) = 'SHS' OR UPPER(COALESCE(s.program,'')) LIKE '%SHS%') THEN 'SHS' ELSE 'COLLEGE' END)";
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

$dismissedCasesRow = db_one(
  "SELECT COUNT(*) AS cnt
   FROM upcc_case
   WHERE status = 'DISMISSED'
     AND created_at BETWEEN :start AND :end",
  [':start' => $monthStart, ':end' => $monthEnd]
);

$dismissedCount = (int)($dismissedRow['cnt'] ?? 0) + (int)($dismissedCasesRow['cnt'] ?? 0);

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
  $sheet->setTitle('Monthly Report');
  
  $sheet->setShowGridlines(false);

  // Styling arrays
  $styleHeader = [
      'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 16],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B2B6B']],
  ];
  
  $styleSubHeader = [
      'font' => ['italic' => true, 'color' => ['argb' => 'FFCCCCCC'], 'size' => 10],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B2B6B']],
  ];

  $styleStatBox = [
      'font' => ['bold' => true, 'size' => 11],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF000000']]],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF2F2F2']],
  ];

  $styleStatVal = [
      'font' => ['bold' => true, 'size' => 20, 'color' => ['argb' => 'FF000000']],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF000000']]],
  ];

  $styleTableHeader = [
      'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF3B4A9E']],
  ];
  
  $styleTableBody = [
      'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFBFBFBF']]],
  ];

  // Header
  $sheet->setCellValue('A1', 'MONTHLY DISCIPLINE REPORT - ' . $titleMonthStr);
  $sheet->mergeCells('A1:L1');
  $sheet->getStyle('A1:L1')->applyFromArray($styleHeader);
  $sheet->getRowDimension(1)->setRowHeight(30);

  $sheet->setCellValue('A2', 'Generated: ' . date('Y-m-d H:i:s') . ' | Audience Filter: ' . $audience);
  $sheet->mergeCells('A2:L2');
  $sheet->getStyle('A2:L2')->applyFromArray($styleSubHeader);

  // Summary Metrics (Dashboard style - 5 Cards)
  $sheet->setCellValue('A4', 'TOTAL OFFENSES');
  $sheet->setCellValue('C4', 'MINOR OFFENSES');
  $sheet->setCellValue('E4', 'MAJOR OFFENSES');
  $sheet->setCellValue('G4', 'ACTIVE CASES');
  $sheet->setCellValue('I4', 'DISMISSED');
  
  $sheet->mergeCells('A4:B4');
  $sheet->mergeCells('C4:D4');
  $sheet->mergeCells('E4:F4');
  $sheet->mergeCells('G4:H4');
  $sheet->mergeCells('I4:J4');
  
  $sheet->getStyle('A4:J4')->applyFromArray($styleStatBox);

  $sheet->setCellValue('A5', $total);
  $sheet->setCellValue('C5', $minor);
  $sheet->setCellValue('E5', $major);
  $sheet->setCellValue('G5', $activeCases);
  $sheet->setCellValue('I5', $dismissedCount);

  $sheet->mergeCells('A5:B5');
  $sheet->mergeCells('C5:D5');
  $sheet->mergeCells('E5:F5');
  $sheet->mergeCells('G5:H5');
  $sheet->mergeCells('I5:J5');

  $sheet->getStyle('A5:J5')->applyFromArray($styleStatVal);
  $sheet->getStyle('E5')->getFont()->getColor()->setARGB('FFC00000'); // Red for major
  $sheet->getStyle('C5')->getFont()->getColor()->setARGB('FFE69300'); // Orange for minor
  $sheet->getStyle('I5')->getFont()->getColor()->setARGB('FF64748B'); // Gray for dismissed
  $sheet->getRowDimension(5)->setRowHeight(36);

  // Hidden Data for Charts
  $bRow = 5;
  foreach ($breakdownMap as $name => $count) {
      $sheet->setCellValue('AA' . $bRow, $name);
      $sheet->setCellValue('AB' . $bRow, $count);
      $bRow++;
  }

  $cRow = 5;
  $topN = 8;
  foreach ($coursesMap as $prog => $count) {
      $sheet->setCellValue('AE' . $cRow, $prog);
      $sheet->setCellValue('AF' . $cRow, $count);
      $cRow++;
      if ($cRow >= 5 + $topN) break;
  }

  // Create Pie Chart
  if (!empty($breakdownMap)) {
      $dataSeriesLabels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Worksheet!$AB$4', null, 1)];
      $xAxisTickValues = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Worksheet!$AA$5:$AA$' . ($bRow - 1), null, count($breakdownMap))];
      $dataSeriesValues = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, 'Worksheet!$AB$5:$AB$' . ($bRow - 1), null, count($breakdownMap))];

      $series = new DataSeries(DataSeries::TYPE_PIECHART, null, range(0, count($dataSeriesValues) - 1), $dataSeriesLabels, $xAxisTickValues, $dataSeriesValues);
      
      $layout = new \PhpOffice\PhpSpreadsheet\Chart\Layout();
      $layout->setShowVal(true);
      $layout->setShowPercent(true);
      
      $plotArea = new PlotArea($layout, [$series]);
      $legend = new Legend(Legend::POSITION_RIGHT, null, false);
      $chartTitle = new Title('Offense Breakdown (Major/Minor)');

      $chart = new Chart('chart1', $chartTitle, $legend, $plotArea, true, 0, null, null);
      $chart->setTopLeftPosition('B7');
      $chart->setBottomRightPosition('E20');
      $sheet->addChart($chart);
  }

  // Create Bar Chart
  if (!empty($coursesMap)) {
      $dataSeriesLabels2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Worksheet!$AF$4', null, 1)];
      $xAxisTickValues2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, 'Worksheet!$AE$5:$AE$' . ($cRow - 1), null, count($coursesMap))];
      $dataSeriesValues2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, 'Worksheet!$AF$5:$AF$' . ($cRow - 1), null, count($coursesMap))];

      $series2 = new DataSeries(DataSeries::TYPE_BARCHART, DataSeries::GROUPING_STANDARD, range(0, count($dataSeriesValues2) - 1), $dataSeriesLabels2, $xAxisTickValues2, $dataSeriesValues2);
      $series2->setPlotDirection(DataSeries::DIRECTION_COL);
      
      $layout2 = new \PhpOffice\PhpSpreadsheet\Chart\Layout();
      $layout2->setShowVal(true);

      $plotArea2 = new PlotArea($layout2, [$series2]);
      $chartTitle2 = new Title('Top Courses by Offenses');

      $chart2 = new Chart('chart2', $chartTitle2, null, $plotArea2, true, 0, null, null);
      $chart2->setTopLeftPosition('F7');
      $chart2->setBottomRightPosition('J20');
      $sheet->addChart($chart2);
  }

  // Raw Data Section
  $headers = [
    'Offense ID', 'Student ID', 'Student Name', 'Program', 'Section',
    'Level', 'Offense Code', 'Offense Name', 'Status', 'Date Committed', 'Description',
    'Sanction / Penalty (NU Lipa Discipline Handbook)'
  ];

  $dataStartRow = 22;
  $sheet->setCellValue('A' . ($dataStartRow - 1), 'RAW DATA EXPORT');
  $sheet->getStyle('A' . ($dataStartRow - 1))->getFont()->setBold(true)->setSize(14);
  
  $sheet->fromArray($headers, null, 'A' . $dataStartRow);
  $sheet->getStyle('A'.$dataStartRow.':L'.$dataStartRow)->applyFromArray($styleTableHeader);

  $rowIndex = $dataStartRow + 1;
  foreach ($rows as $r) {
    $offenseLevel = strtoupper((string)($r['offense_level'] ?? ''));
    $caseStatus = strtoupper((string)($r['case_status'] ?? ''));
    $caseKind = strtoupper((string)($r['case_kind'] ?? ''));

    // Compute Sanction / Penalty string according to NU Lipa Discipline Handbook
    $sanctionStr = '';
    if ($caseStatus === 'DISMISSED' || strtoupper((string)($r['status'] ?? '')) === 'DISMISSED') {
        $sanctionStr = 'Case / Offense Dismissed (No Sanction Imposed)';
    } elseif (!empty($r['final_decision'])) {
        $catStr = !empty($r['decided_category']) ? "Category {$r['decided_category']}" : "Decided";
        $sanctionStr = "{$catStr}: " . (string)$r['final_decision'];
    } elseif ($offenseLevel === 'MINOR') {
        // Track sequence of minor offenses for this student up to this offense date
        $seqCount = (int)(db_one(
            "SELECT COUNT(*) AS cnt FROM offense WHERE student_id = ? AND date_committed <= ?",
            [$r['student_id'], $r['date_committed']]
        )['cnt'] ?? 1);

        if ($seqCount === 1) {
            $interv = !empty($r['intervention_first']) ? " - " . $r['intervention_first'] : "";
            $sanctionStr = "1st Minor Offense (Written Warning & Form F-005 Notice to Explain{$interv})";
        } elseif ($seqCount === 2) {
            $interv = !empty($r['intervention_second']) ? " - " . $r['intervention_second'] : "";
            $sanctionStr = "2nd Minor Offense (2nd Written Notice & Guardian Conference{$interv})";
        } else {
            $sanctionStr = "3rd Minor Offense — Section 4 Escalation (UPCC Major Committee Hearing & Sanction)";
        }
    } elseif ($offenseLevel === 'MAJOR') {
        $sanctionStr = "Major Offense (Pending UPCC Committee Hearing & Sanction)";
    } else {
        $sanctionStr = "Under Review";
    }

    $sheet->setCellValueExplicit('A' . $rowIndex, (string)($r['offense_id'] ?? ''), DataType::TYPE_STRING);
    $sheet->setCellValueExplicit('B' . $rowIndex, (string)($r['student_id'] ?? ''), DataType::TYPE_STRING);
    $sheet->setCellValue('C' . $rowIndex, (string)($r['student_name'] ?? ''));
    $sheet->setCellValue('D' . $rowIndex, (string)($r['program'] ?? ''));
    $sheet->setCellValue('E' . $rowIndex, (string)($r['section'] ?? ''));
    $sheet->setCellValue('F' . $rowIndex, (string)($r['offense_level'] ?? ''));
    $sheet->setCellValue('G' . $rowIndex, (string)($r['offense_code'] ?? ''));
    $sheet->setCellValue('H' . $rowIndex, (string)($r['offense_name'] ?? ''));
    $sheet->setCellValue('I' . $rowIndex, (string)($r['status'] ?? ''));
    $sheet->setCellValue('J' . $rowIndex, (string)($r['date_committed'] ?? ''));
    $sheet->setCellValue('K' . $rowIndex, (string)($r['description'] ?? ''));
    $sheet->setCellValue('L' . $rowIndex, $sanctionStr);
    $rowIndex++;
  }

  if ($rowIndex > $dataStartRow + 1) {
      $sheet->getStyle('A'.($dataStartRow + 1).':L'.($rowIndex - 1))->applyFromArray($styleTableBody);
  }

  foreach (range('A', 'L') as $col) {
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