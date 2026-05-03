<?php
// File: C:\xampp\htdocs\identitrack\admin\AJAX\export_monthly_report_xlsx.php
// Exports Monthly Discipline Report with summary stats, pie chart, bar chart, and raw data.

require_once __DIR__ . '/../../database/database.php';
require_admin();

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

// 1. Fetch raw data
$rows = db_all(
  "SELECT
      o.offense_id,
      o.student_id,
      CONCAT(s.student_ln, ', ', s.student_fn) AS student_name,
      COALESCE(NULLIF(s.program,''), 'N/A') AS program,
      COALESCE(NULLIF(s.section,''), 'N/A') AS section,
      ot.level AS offense_level,
      ot.code AS offense_code,
      ot.name AS offense_name,
      o.status,
      o.date_committed,
      o.description
   FROM offense o
   JOIN student s ON s.student_id = o.student_id
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   WHERE o.date_committed BETWEEN ? AND ?
   $audienceClause
   ORDER BY o.date_committed DESC",
  [$monthStart, $monthEnd]
);

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
      'font' => ['bold' => true, 'size' => 14],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF000000']]],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF2F2F2']],
  ];

  $styleStatVal = [
      'font' => ['bold' => true, 'size' => 24, 'color' => ['argb' => 'FF000000']],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'borders' => ['outline' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF000000']]],
  ];

  $styleTableHeader = [
      'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF']],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF44546A']],
      'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
  ];
  
  $styleTableBody = [
      'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFBFBFBF']]],
  ];

  // Header
  $sheet->setCellValue('A1', 'MONTHLY DISCIPLINE REPORT - ' . strtoupper(date('F Y', strtotime($month . '-01'))));
  $sheet->mergeCells('A1:K1');
  $sheet->getStyle('A1:K1')->applyFromArray($styleHeader);
  $sheet->getRowDimension(1)->setRowHeight(30);

  $sheet->setCellValue('A2', 'Generated: ' . date('Y-m-d H:i:s') . ' | Audience Filter: ' . $audience);
  $sheet->mergeCells('A2:K2');
  $sheet->getStyle('A2:K2')->applyFromArray($styleSubHeader);

  // Summary Metrics (Dashboard style)
  $sheet->setCellValue('B4', 'TOTAL OFFENSES');
  $sheet->setCellValue('D4', 'MINOR OFFENSES');
  $sheet->setCellValue('F4', 'MAJOR OFFENSES');
  $sheet->setCellValue('H4', 'ACTIVE CASES');
  
  $sheet->mergeCells('B4:C4');
  $sheet->mergeCells('D4:E4');
  $sheet->mergeCells('F4:G4');
  $sheet->mergeCells('H4:I4');
  
  $sheet->getStyle('B4:I4')->applyFromArray($styleStatBox);

  $sheet->setCellValue('B5', $total);
  $sheet->setCellValue('D5', $minor);
  $sheet->setCellValue('F5', $major);
  $sheet->setCellValue('H5', $activeCases);

  $sheet->mergeCells('B5:C5');
  $sheet->mergeCells('D5:E5');
  $sheet->mergeCells('F5:G5');
  $sheet->mergeCells('H5:I5');

  $sheet->getStyle('B5:I5')->applyFromArray($styleStatVal);
  $sheet->getStyle('F5')->getFont()->getColor()->setARGB('FFC00000'); // Red for major
  $sheet->getStyle('D5')->getFont()->getColor()->setARGB('FFE69300'); // Orange for minor
  $sheet->getRowDimension(5)->setRowHeight(40);

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
    'Level', 'Offense Code', 'Offense Name', 'Status', 'Date Committed', 'Description'
  ];

  $dataStartRow = 22;
  $sheet->setCellValue('A' . ($dataStartRow - 1), 'RAW DATA EXPORT');
  $sheet->getStyle('A' . ($dataStartRow - 1))->getFont()->setBold(true)->setSize(14);
  
  $sheet->fromArray($headers, null, 'A' . $dataStartRow);
  $sheet->getStyle('A'.$dataStartRow.':K'.$dataStartRow)->applyFromArray($styleTableHeader);

  $rowIndex = $dataStartRow + 1;
  foreach ($rows as $r) {
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
    $rowIndex++;
  }

  if ($rowIndex > $dataStartRow + 1) {
      $sheet->getStyle('A'.($dataStartRow + 1).':K'.($rowIndex - 1))->applyFromArray($styleTableBody);
  }

  foreach (range('A', 'K') as $col) {
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