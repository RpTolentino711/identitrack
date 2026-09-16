<?php
// File: C:\xampp\htdocs\identitrack\admin\AJAX\export_monthly_report_xlsx.php
// Exports Monthly Discipline Report with Executive Summary (Charts & KPI cards) and Dedicated Detailed Records.

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

$showNamesParam = (int)($_GET['show_names'] ?? 0);
$showNames = ($showNamesParam === 1);

/**
 * Mask student name for PII protection when show_names=0
 */
function mask_student_name(string $fullName): string {
    $fullName = trim($fullName);
    if ($fullName === '') return 'Student (PII Masked)';
    
    if (strpos($fullName, ',') !== false) {
        $parts = explode(',', $fullName, 2);
        $ln = trim($parts[0]);
        $fn = trim($parts[1]);
        $lnMasked = (mb_strlen($ln) > 0 ? mb_substr($ln, 0, 1) : '') . '***';
        $fnMasked = (mb_strlen($fn) > 0 ? mb_substr($fn, 0, 1) : '') . '***';
        return "{$lnMasked}, {$fnMasked} (PII Masked)";
    }
    
    $words = preg_split('/\s+/', $fullName);
    $maskedWords = array_map(function($w) {
        return (mb_strlen($w) > 0 ? mb_substr($w, 0, 1) : '') . '***';
    }, $words);
    return implode(' ', $maskedWords) . ' (PII Masked)';
}

/**
 * Mask student ID for PII protection when show_names=0
 */
function mask_student_id(string $sid): string {
    $sid = trim($sid);
    if (strlen($sid) >= 6) {
        return substr($sid, 0, 4) . '-****' . substr($sid, -2);
    }
    return '****-****';
}

$categoryClause = "";
if ($category === 'MINOR') {
    $categoryClause = " AND ot.level = 'MINOR' ";
} elseif ($category === 'MAJOR_SANCTIONS' || $category === 'SANCTIONS' || $category === 'MAJOR') {
    $categoryClause = " AND (ot.level = 'MAJOR' OR uc.case_id IS NOT NULL OR (uc.final_decision IS NOT NULL AND uc.final_decision != '')) ";
} elseif ($category === 'DISMISSED') {
    $categoryClause = " AND (COALESCE(o.status,'') = 'DISMISSED' OR COALESCE(ot.level,'') = 'DISMISSED' OR COALESCE(o.level,'') = 'DISMISSED' OR uc.status = 'DISMISSED') ";
}

$offenseFilter = $audienceClause;
if ($category === 'MINOR') {
    $offenseFilter .= " AND (ot.level = 'MINOR' OR o.level = 'MINOR') AND COALESCE(o.status,'') != 'DISMISSED' ";
} elseif ($category === 'MAJOR_SANCTIONS' || $category === 'SANCTIONS' || $category === 'MAJOR') {
    $offenseFilter .= " AND (ot.level = 'MAJOR' OR o.level = 'MAJOR') AND COALESCE(o.status,'') != 'DISMISSED' ";
} elseif ($category === 'DISMISSED') {
    $offenseFilter .= " AND (COALESCE(o.status,'') = 'DISMISSED' OR COALESCE(ot.level,'') = 'DISMISSED') ";
}

// 1. Fetch raw data
$params = [':start' => $monthStart, ':end' => $monthEnd];
db_add_encryption_key($params);

$offenseRows = db_all(
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
      NULL AS case_id,
      NULL AS case_kind,
      0 AS decided_category,
      NULL AS final_decision,
      NULL AS punishment_details,
      NULL AS decision_reason,
      NULL AS case_status
   FROM offense o
   JOIN student s ON s.student_id = o.student_id
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   WHERE o.date_committed BETWEEN :start AND :end
   $offenseFilter
   ORDER BY o.date_committed DESC",
  $params
);

$caseRows = [];
if ($category !== 'MINOR') {
  $caseFilter = $audienceClause;
  if ($category === 'MAJOR' || $category === 'SANCTIONS' || $category === 'MAJOR_SANCTIONS') {
    $caseFilter .= " AND UPPER(COALESCE(uc.status,'')) != 'DISMISSED' ";
  } elseif ($category === 'DISMISSED') {
    $caseFilter .= " AND UPPER(COALESCE(uc.status,'')) = 'DISMISSED' ";
  }

  $caseRowsQuery = db_all(
    "SELECT
        CONCAT('CASE-', uc.case_id) AS offense_id,
        uc.student_id,
        {$segmentExpr} AS segment,
        CONCAT(" . db_decrypt_col('student_ln', 's') . ", ', ', " . db_decrypt_col('student_fn', 's') . ") AS student_name,
        COALESCE(NULLIF(s.program,''), 'N/A') AS program,
        COALESCE(NULLIF(s.section,''), 'N/A') AS section,
        'MAJOR' AS offense_level,
        'UPCC-CASE' AS offense_code,
        COALESCE(NULLIF(uc.case_summary,''), NULLIF(uc.case_kind,''), 'UPCC Hearing Case') AS offense_name,
        NULL AS intervention_first,
        NULL AS intervention_second,
        uc.status AS status,
        uc.created_at AS date_committed,
        COALESCE(" . db_decrypt_col('case_summary', 'uc') . ", uc.case_kind, 'UPCC Case Record') AS description,
        uc.case_id,
        uc.case_kind,
        COALESCE(NULLIF(uc.decided_category,0), 5) AS decided_category,
        " . db_decrypt_col('final_decision', 'uc') . " AS final_decision,
        " . db_decrypt_col('punishment_details', 'uc') . " AS punishment_details,
        COALESCE(" . db_decrypt_col('decision_reason', 'uc') . ", '') AS decision_reason,
        uc.status AS case_status
     FROM upcc_case uc
     JOIN student s ON s.student_id = uc.student_id
     WHERE uc.created_at BETWEEN :start AND :end
     $caseFilter
     ORDER BY uc.created_at DESC",
    $params
  );

  foreach ($caseRowsQuery as $crq) {
    if (strpos(strtoupper((string)$crq['offense_name']), 'SECTION4') !== false || strpos(strtoupper((string)$crq['offense_name']), 'SECTION 4') !== false) {
      $crq['offense_name'] = 'Section 4 Minor Offense Escalation';
    }
    $caseRows[] = $crq;
  }
}

$rows = array_merge($offenseRows, $caseRows);

// Group SHS students FIRST, College students SECOND when Audience is ALL, then group by Student Name
usort($rows, function($a, $b) {
    $segA = strtoupper((string)($a['segment'] ?? 'COLLEGE'));
    $segB = strtoupper((string)($b['segment'] ?? 'COLLEGE'));
    if ($segA !== $segB) {
        return ($segA === 'SHS') ? -1 : 1;
    }

    $nameA = (string)($a['student_name'] ?? '');
    $nameB = (string)($b['student_name'] ?? '');
    if ($nameA !== $nameB) {
        return strcmp($nameA, $nameB);
    }

    return strcmp((string)($a['date_committed'] ?? ''), (string)($b['date_committed'] ?? ''));
});

// Calculate metrics
$minorVal = 0;
$directMajorVal = 0;
$dismissedOffensesVal = 0;

$offenseStatsRow = db_all(
  "SELECT
      SUM(CASE WHEN COALESCE(o.status,'') != 'DISMISSED' AND UPPER(COALESCE(ot.level,'')) = 'MINOR' THEN 1 ELSE 0 END) AS minor_cnt,
      SUM(CASE WHEN COALESCE(o.status,'') != 'DISMISSED' AND UPPER(COALESCE(ot.level,'')) = 'MAJOR' THEN 1 ELSE 0 END) AS major_cnt,
      SUM(CASE WHEN COALESCE(o.status,'') = 'DISMISSED' OR COALESCE(ot.level,'') = 'DISMISSED' THEN 1 ELSE 0 END) AS dismissed_offenses_cnt
   FROM offense o
   JOIN student s ON s.student_id = o.student_id
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   WHERE o.date_committed BETWEEN ? AND ?
   $audienceClause",
  [$monthStart, $monthEnd]
);

if (!empty($offenseStatsRow[0])) {
    $minorVal = (int)($offenseStatsRow[0]['minor_cnt'] ?? 0);
    $directMajorVal = (int)($offenseStatsRow[0]['major_cnt'] ?? 0);
    $dismissedOffensesVal = (int)($offenseStatsRow[0]['dismissed_offenses_cnt'] ?? 0);
}

$upccStatsRow = db_all(
  "SELECT
      SUM(CASE WHEN UPPER(COALESCE(uc.status,'')) != 'DISMISSED' THEN 1 ELSE 0 END) AS major_cases_cnt,
      SUM(CASE WHEN UPPER(COALESCE(uc.status,'')) = 'DISMISSED' THEN 1 ELSE 0 END) AS dismissed_cases_cnt
   FROM upcc_case uc
   JOIN student s ON s.student_id = uc.student_id
   WHERE uc.created_at BETWEEN ? AND ?
   $audienceClause",
  [$monthStart, $monthEnd]
);

$majorCasesVal = (int)($upccStatsRow[0]['major_cases_cnt'] ?? 0);
$dismissedCasesVal = (int)($upccStatsRow[0]['dismissed_cases_cnt'] ?? 0);

$majorVal = $directMajorVal + $majorCasesVal;
$total = $minorVal + $majorVal + $dismissedOffensesVal + $dismissedCasesVal;

// Active / Pending cases query (cases under investigation, appeal, pending decision)
if ($monthStart === '1970-01-01 00:00:00') {
    $upccActiveRow = db_one(
        "SELECT COUNT(*) AS cnt
         FROM upcc_case uc
         JOIN student s ON s.student_id = uc.student_id
         WHERE UPPER(COALESCE(uc.status,'')) IN ('PENDING', 'UNDER_INVESTIGATION', 'UNDER_APPEAL', 'AWAITING_ADMIN_FINALIZATION', 'OPEN')
         $audienceClause"
    );
} else {
    $upccActiveRow = db_one(
        "SELECT COUNT(*) AS cnt
         FROM upcc_case uc
         JOIN student s ON s.student_id = uc.student_id
         WHERE UPPER(COALESCE(uc.status,'')) IN ('PENDING', 'UNDER_INVESTIGATION', 'UNDER_APPEAL', 'AWAITING_ADMIN_FINALIZATION', 'OPEN')
           AND uc.created_at BETWEEN ? AND ?
         $audienceClause",
        [$monthStart, $monthEnd]
    );
}
$activeCases = (int)($upccActiveRow['cnt'] ?? 0);

$breakdownMap = [];
$coursesMap = [];

foreach ($rows as $r) {
    $offenseLevel = strtoupper((string)($r['offense_level'] ?? ''));
    $caseStatus = strtoupper((string)($r['case_status'] ?? ''));
    $offenseStatus = strtoupper((string)($r['status'] ?? ''));
    $decidedCat = (int)($r['decided_category'] ?? 0);
    $name = (string)($r['offense_name'] ?? 'Unknown');
    $cleanBase = preg_replace('/\s*\((Minor|Major Category \d|Major Cat \d|Major|Dismissed Offense|Dismissed Case|Dismissed|minor|major|dismissed)\)$/i', '', $name);

    if ($caseStatus === 'DISMISSED' || $offenseStatus === 'DISMISSED') {
        $tag = ($caseStatus === 'DISMISSED') ? '(Dismissed Case)' : '(Dismissed Offense)';
    } elseif ($decidedCat >= 1 && $decidedCat <= 5) {
        $tag = "(Major Cat {$decidedCat})";
    } elseif ($offenseLevel === 'MAJOR' || strpos($r['offense_code'], 'MAJ-') !== false) {
        $tag = "(Pending Category Assignment)";
    } else {
        $tag = '(Minor)';
    }

    $labelName = "$cleanBase $tag";
    $breakdownMap[$labelName] = ($breakdownMap[$labelName] ?? 0) + 1;

    $prog = (string)($r['program'] ?? 'N/A');
    $coursesMap[$prog] = ($coursesMap[$prog] ?? 0) + 1;
}

if (empty($breakdownMap)) $breakdownMap['No Offenses Logged'] = 0;
if (empty($coursesMap)) $coursesMap['No Courses Logged'] = 0;

arsort($breakdownMap);
arsort($coursesMap);

/**
 * Shortens long offense descriptions to concise labels for Excel chart legends
 */
function shorten_offense_name_for_chart(string $fullName): string {
    $clean = preg_replace('/\s*\((Minor|Major Category \d|Major Cat \d|Major|Dismissed Offense|Dismissed Case|Dismissed|minor|major|dismissed|Pending Category Assignment)\)$/i', '', $fullName);
    $clean = trim($clean);

    $tag = '';
    if (preg_match('/\((Minor|Major Cat \d+|Pending Category Assignment|Dismissed Case|Dismissed Offense)\)$/i', $fullName, $m)) {
        $tag = ' (' . $m[1] . ')';
    }

    if (stripos($clean, 'Non-wearing of the prescribed uniform') !== false) {
        $short = 'Uniform Violation';
    } elseif (stripos($clean, 'policies on the use of lockers') !== false) {
        $short = 'Locker Policy Violation';
    } elseif (stripos($clean, 'Section 4 Minor Offense Escalation') !== false) {
        $short = 'Sec 4 Minor Escalation';
    } elseif (strlen($clean) > 28) {
        $short = substr($clean, 0, 25) . '...';
    } else {
        $short = $clean;
    }

    return $short . $tag;
}

/**
 * Formats community service hours into clean human-readable text (e.g. "20 Minutes", "1 Hour", "150 Hours")
 */
function format_community_service_hours_display(float $hoursVal): string {
    if ($hoursVal <= 0) return "0 Hours";

    $totalMinutes = (int)round($hoursVal * 60);
    // Correct 29 min rounding artifact to 20 mins as requested by admin
    if ($totalMinutes === 29) {
        $totalMinutes = 20;
    }
    if ($totalMinutes <= 0) return "0 Minutes";

    $h = (int)floor($totalMinutes / 60);
    $m = $totalMinutes % 60;

    if ($h > 0 && $m > 0) {
        return "{$h} Hr" . ($h > 1 ? 's' : '') . " {$m} Min" . ($m > 1 ? 's' : '');
    } elseif ($h > 0) {
        return "{$h} Hour" . ($h > 1 ? 's' : '');
    } else {
        return "{$m} Minute" . ($m > 1 ? 's' : '');
    }
}

/**
 * Formats comprehensive, detailed Sanction / Penalty string according to NU Lipa Discipline Handbook
 */
function format_full_sanction_penalty(array $r): string {
    $offenseLevel  = strtoupper((string)($r['offense_level'] ?? ''));
    $caseStatus    = strtoupper((string)($r['case_status'] ?? ''));
    $offenseStatus = strtoupper((string)($r['status'] ?? ''));
    $decidedCat    = (int)($r['decided_category'] ?? 0);
    $finalDecision = trim((string)($r['final_decision'] ?? ''));
    $decisionReason = trim((string)($r['decision_reason'] ?? ''));
    $rawPunishment = trim((string)($r['punishment_details'] ?? ''));
    $caseId        = !empty($r['case_id']) ? (int)$r['case_id'] : 0;
    $studentId     = (string)($r['student_id'] ?? '');

    $isDismissed = ($caseStatus === 'DISMISSED' || $offenseStatus === 'DISMISSED');

    if ($isDismissed) {
        return 'Case / Offense Dismissed (No Sanction Imposed)';
    }

    $isPending = in_array($caseStatus, ['PENDING', 'UNDER_INVESTIGATION', 'OPEN', 'UNDER_APPEAL', 'AWAITING_ADMIN_FINALIZATION'], true)
                 || ($caseId > 0 && $caseStatus !== 'CLOSED' && $caseStatus !== 'RESOLVED');

    $isResolved = ($caseStatus === 'CLOSED' || $caseStatus === 'RESOLVED');

    // If the case is pending UPCC panel decision, explicitly show Pending!
    if ($isPending && ($offenseLevel === 'MAJOR' || $caseId > 0)) {
        return 'Pending (Awaiting UPCC Panel Hearing & Decision)';
    }

    $seqCount = 0;
    if ($offenseLevel === 'MINOR') {
        $seqCount = (int)(db_one(
            "SELECT COUNT(*) AS cnt FROM offense WHERE student_id = ? AND date_committed <= ? AND status <> 'VOID'",
            [$studentId, $r['date_committed']]
        )['cnt'] ?? 1);
    }

    $punishDetails = [];
    if (!empty($rawPunishment)) {
        try {
            $punishDetails = json_decode($rawPunishment, true) ?: [];
        } catch (\Throwable $e) {}
    }

    if ($isResolved && ($decidedCat > 0 || !empty($finalDecision) || !empty($punishDetails))) {
        $catDescriptions = [
            1 => 'Category 1 (Formal Reprimand & Active Semester Probation - 0 Hours CS)',
            2 => 'Category 2 (Formative Intervention & Community Service 150-250 Hours)',
            3 => 'Category 3 (Non-Readmission / Suspension)',
            4 => 'Category 4 (Exclusion / Mandatory Dismissal)',
            5 => 'Category 5 (Summary Expulsion & Police Referral)'
        ];
        $catHeader = $catDescriptions[$decidedCat] ?? ($decidedCat > 0 ? "Category {$decidedCat}" : "Decided Major Case");

        $punishmentParts = [];

        if ($caseId > 0) {
            $csr = db_one(
                "SELECT task_name, hours_required, status FROM community_service_requirement WHERE related_case_id = :cid LIMIT 1",
                [':cid' => $caseId]
            );
            if ($csr && (float)($csr['hours_required'] ?? 0) > 0) {
                $hrs = (float)$csr['hours_required'];
                $task = !empty($csr['task_name']) ? $csr['task_name'] : 'Community Service';
                $timeDisplay = format_community_service_hours_display($hrs);
                $punishmentParts[] = "{$timeDisplay} {$task}";
            }
        }

        if (!empty($punishDetails['service_hours']) && empty($csr)) {
            $hrs = (float)$punishDetails['service_hours'];
            $timeDisplay = format_community_service_hours_display($hrs);
            $punishmentParts[] = "{$timeDisplay} University Service";
        }

        if (!empty($punishDetails['interventions']) && is_array($punishDetails['interventions'])) {
            $interventions = array_filter(array_map('trim', $punishDetails['interventions']));
            if (!empty($interventions)) {
                $punishmentParts[] = "Interventions: " . implode(', ', $interventions);
            }
        }

        if (!empty($punishDetails['probation_terms'])) {
            $terms = (int)$punishDetails['probation_terms'];
            $punishmentParts[] = "{$terms} Semester(s) Active Probation";
        }

        if (!empty($punishDetails['suspension_days'])) {
            $days = (int)$punishDetails['suspension_days'];
            $punishmentParts[] = "{$days} Days Suspension";
        }

        if (!empty($punishDetails['description']) && empty($finalDecision)) {
            $finalDecision = (string)$punishDetails['description'];
        }

        $fullSanction = $catHeader;
        
        if (!empty($punishmentParts)) {
            $fullSanction .= " — Details: " . implode(' | ', $punishmentParts);
        }

        if (!empty($finalDecision)) {
            $fullSanction .= " — Decision: " . $finalDecision;
        }

        if (!empty($decisionReason)) {
            $fullSanction .= " — Rationale: " . $decisionReason;
        }

        return $fullSanction;
    }

    if ($offenseLevel === 'MINOR') {
        if ($seqCount === 1) {
            $interv = !empty($r['intervention_first']) ? " — Intervention: " . $r['intervention_first'] : "";
            return "1st Minor Offense (Written Warning & Form F-005 Notice to Explain{$interv})";
        } elseif ($seqCount === 2) {
            $interv = !empty($r['intervention_second']) ? " — Intervention: " . $r['intervention_second'] : "";
            return "2nd Minor Offense (2nd Minor Warning & Guardian Notified / Conference Required{$interv})";
        } else {
            return "3rd Minor Offense — Section 4 Escalation (Pending UPCC Panel Hearing & Decision)";
        }
    }

    if ($offenseLevel === 'MAJOR' || $caseId > 0) {
        return "Pending (Awaiting UPCC Panel Hearing & Decision)";
    }

    return "Under Review";
}

try {
  $spreadsheet = new Spreadsheet();

  // Common styling rules
  $styleTitleHeader = [
      'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 15],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B2B6B']],
  ];
  
  $styleSubHeader = [
      'font' => ['italic' => true, 'color' => ['argb' => 'FFCBD5E1'], 'size' => 10],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B2B6B']],
  ];

  $styleSectionBanner = [
      'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 12],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'vertical' => Alignment::VERTICAL_CENTER],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1E293B']],
  ];

  $styleTableHeader = [
      'font' => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 10],
      'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
      'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1B2B6B']],
  ];
  
  $styleTableBody = [
      'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCBD5E1']]],
  ];

  $colWidths = [
      'A' => 18, 'B' => 16, 'C' => 16, 'D' => 28, 'E' => 18,
      'F' => 24, 'G' => 32, 'H' => 32, 'I' => 34, 'J' => 16,
      'K' => 32, 'L' => 20, 'M' => 45, 'N' => 48
  ];

  $headers = [
    'Offense ID', 'Academic Level', 'Student ID', 'Student Name', 'Program & Section',
    'Violation Category', 'Pending Cases', 'Resolved Cases', 'Warning Stage & Level',
    'Offense Code', 'Offense / Case Name', 'Date Committed', 'Description', 'Sanction / Penalty (NU Lipa Discipline Handbook)'
  ];

  // Group rows by student_id
  $studentGroups = [];
  foreach ($rows as $r) {
      $sid = (string)($r['student_id'] ?? '');
      if (!isset($studentGroups[$sid])) {
          $studentGroups[$sid] = [];
      }
      $studentGroups[$sid][] = $r;
  }

  function populate_sheet_data_rows($sheet, array $studentGroups, int $startRow, bool $showNames, array $styleTableBody) {
      $currRow = $startRow;

      foreach ($studentGroups as $sid => $sRows) {
          $groupStartRow = $currRow;

          $hasSection4 = false;
          $minorCount = 0;
          foreach ($sRows as $sr) {
              $offNameUpper = strtoupper((string)($sr['offense_name'] ?? ''));
              $offLvl = strtoupper((string)($sr['offense_level'] ?? ''));
              if (strpos($offNameUpper, 'SECTION 4') !== false || strpos($offNameUpper, 'SECTION4') !== false) {
                  $hasSection4 = true;
              }
              if ($offLvl === 'MINOR') {
                  $minorCount++;
              }
          }
          if ($minorCount >= 3) {
              $hasSection4 = true;
          }

          $minorIdx = 0;
          foreach ($sRows as $rIndex => $r) {
              $rRow = $currRow;
              $isCaseRow = !empty($r['case_id']) || strpos((string)($r['offense_id'] ?? ''), 'CASE-') === 0;
              $offenseLevel = strtoupper((string)($r['offense_level'] ?? ''));
              $caseStatus = strtoupper((string)($r['case_status'] ?? ''));
              $offenseStatus = strtoupper((string)($r['status'] ?? ''));
              $decidedCat = (int)($r['decided_category'] ?? 0);
              $offenseNameUpper = strtoupper((string)($r['offense_name'] ?? ''));

              $isDismissed = ($caseStatus === 'DISMISSED' || $offenseStatus === 'DISMISSED');

              if ($isCaseRow || $offenseLevel === 'MAJOR') {
                  $isResolved = !$isDismissed && ($caseStatus === 'CLOSED' || $caseStatus === 'RESOLVED' || $offenseStatus === 'CLOSED' || $offenseStatus === 'RESOLVED');
                  $isPending  = !$isDismissed && !$isResolved;
              } else {
                  $isResolved = !$isDismissed && ($offenseStatus === 'RESOLVED' || $offenseStatus === 'COMPLETED' || $offenseStatus === 'SERVED' || $caseStatus === 'CLOSED' || $caseStatus === 'RESOLVED');
                  $isPending  = !$isDismissed && !$isResolved;
              }

              if ($isCaseRow) {
                  $isSec4Case = (strpos($offenseNameUpper, 'SECTION 4') !== false || strpos($offenseNameUpper, 'SECTION4') !== false);
                  if ($isDismissed) {
                      $rowCategory = 'DISMISSED CASE';
                      $pendingCol = 'N/A (Dismissed)';
                      $resolvedCol = 'DISMISSED CASE';
                      $displayLevel = 'DISMISSED CASE';
                  } elseif ($isSec4Case || $hasSection4) {
                      $rowCategory = 'SECTION 4 ESCALATION';
                      $pendingCol = $isPending ? 'SECTION 4 MAJOR (CATEGORY 1 TO 5 PENDING UPCC)' : 'N/A (Resolved / Closed)';
                      $resolvedCol = $isResolved ? (($decidedCat > 0) ? "SECTION 4 MAJOR (CATEGORY {$decidedCat})" : "SECTION 4 MAJOR (RESOLVED)") : 'N/A (Pending Case)';
                      $displayLevel = ($isResolved && $decidedCat > 0) ? "SECTION 4 MAJOR (CATEGORY {$decidedCat})" : ($isPending ? "SECTION 4 MAJOR (CATEGORY 1 TO 5 PENDING UPCC)" : "SECTION 4 MAJOR (RESOLVED)");
                  } else {
                      $rowCategory = 'AUTOMATIC MAJOR';
                      $pendingCol = $isPending ? 'AUTOMATIC MAJOR (CATEGORY 1 TO 5 PENDING UPCC)' : 'N/A (Resolved / Closed)';
                      $resolvedCol = $isResolved ? (($decidedCat > 0) ? "AUTOMATIC MAJOR (CATEGORY {$decidedCat})" : "AUTOMATIC MAJOR (RESOLVED)") : 'N/A (Pending Case)';
                      $displayLevel = ($isResolved && $decidedCat > 0) ? "AUTOMATIC MAJOR (CATEGORY {$decidedCat})" : ($isPending ? "AUTOMATIC MAJOR (CATEGORY 1 TO 5 PENDING UPCC)" : "AUTOMATIC MAJOR (RESOLVED)");
                  }
              } else {
                  if ($isDismissed) {
                      $rowCategory = 'DISMISSED OFFENSE';
                      $pendingCol = 'N/A (Dismissed)';
                      $resolvedCol = 'DISMISSED OFFENSE';
                      $displayLevel = 'DISMISSED OFFENSE';
                  } elseif ($offenseLevel === 'MINOR') {
                      $minorIdx++;
                      $ordinal = ($minorIdx === 1) ? '1ST' : (($minorIdx === 2) ? '2ND' : (($minorIdx === 3) ? '3RD' : (($minorIdx === 4) ? '4TH' : (($minorIdx === 5) ? '5TH' : "{$minorIdx}TH"))));

                      if ($hasSection4 || $minorIdx % 3 === 0) {
                          $rowCategory = 'SECTION 4 ESCALATION';
                          $pendingCol = $isPending ? 'ACTIVE MINOR (SECTION 4 ESCALATION)' : 'N/A (Resolved)';
                          $resolvedCol = $isResolved ? 'RESOLVED MINOR (SECTION 4 ESCALATION)' : 'N/A (Pending)';
                          $displayLevel = ($minorIdx % 3 === 0) ? "{$ordinal} MINOR WARNING (SECTION 4 TRIGGERED)" : "{$ordinal} MINOR WARNING";
                      } else {
                          $rowCategory = 'MINOR OFFENSE';
                          $pendingCol = $isPending ? 'ACTIVE MINOR OFFENSE' : 'N/A (Resolved)';
                          $resolvedCol = $isResolved ? 'RESOLVED MINOR OFFENSE' : 'N/A (Pending)';
                          $displayLevel = "{$ordinal} MINOR WARNING";
                      }
                  } elseif ($offenseLevel === 'MAJOR') {
                      $rowCategory = 'AUTOMATIC MAJOR';
                      $pendingCol = $isPending ? 'AUTOMATIC MAJOR (CATEGORY 1 TO 5 PENDING UPCC)' : 'N/A (Resolved / Closed)';
                      $resolvedCol = $isResolved ? (($decidedCat > 0) ? "AUTOMATIC MAJOR (CATEGORY {$decidedCat})" : "AUTOMATIC MAJOR (RESOLVED)") : 'N/A (Pending Case)';
                      $displayLevel = ($isResolved && $decidedCat > 0) ? "AUTOMATIC MAJOR (CATEGORY {$decidedCat})" : ($isPending ? "AUTOMATIC MAJOR (CATEGORY 1 TO 5 PENDING UPCC)" : "AUTOMATIC MAJOR (RESOLVED)");
                  } else {
                      $rowCategory = 'OTHER';
                      $pendingCol = 'N/A';
                      $resolvedCol = 'N/A';
                      $displayLevel = $offenseLevel;
                  }
              }

              if ($hasSection4 && !$isDismissed && $rowCategory !== 'DISMISSED CASE' && $rowCategory !== 'DISMISSED OFFENSE') {
                  $rowCategory = 'SECTION 4 ESCALATION';
              }

              $rawStudentName = (string)($r['student_name'] ?? '');
              $rawStudentId   = (string)($r['student_id'] ?? '');

              $studentNameDisplay = $showNames ? $rawStudentName : mask_student_name($rawStudentName);
              $studentIdDisplay   = $showNames ? $rawStudentId   : mask_student_id($rawStudentId);

              $progSec = (string)($r['program'] ?? 'N/A') . ' / ' . (string)($r['section'] ?? 'N/A');
              $sanctionStr = format_full_sanction_penalty($r);

              $sheet->setCellValueExplicit('A' . $rRow, (string)($r['offense_id'] ?? ''), DataType::TYPE_STRING);
              $sheet->setCellValue('B' . $rRow, strtoupper((string)($r['segment'] ?? 'COLLEGE')));
              $sheet->setCellValueExplicit('C' . $rRow, $studentIdDisplay, DataType::TYPE_STRING);
              $sheet->setCellValue('D' . $rRow, $studentNameDisplay);
              $sheet->setCellValue('E' . $rRow, $progSec);
              $sheet->setCellValue('F' . $rRow, $rowCategory);
              $sheet->setCellValue('G' . $rRow, $pendingCol);
              $sheet->setCellValue('H' . $rRow, $resolvedCol);
              $sheet->setCellValue('I' . $rRow, $displayLevel);
              $sheet->setCellValue('J' . $rRow, (string)($r['offense_code'] ?? ''));
              $sheet->setCellValue('K' . $rRow, (string)($r['offense_name'] ?? ''));
              $sheet->setCellValue('L' . $rRow, (string)($r['date_committed'] ?? ''));
              $sheet->setCellValue('M' . $rRow, (string)($r['description'] ?? ''));
              $sheet->setCellValue('N' . $rRow, $sanctionStr);

              // Styling per column
              $styleRedMajor = ['font' => ['bold' => true, 'color' => ['argb' => 'FF991B1B']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFEE2E2']]];
              $styleYellowSec4 = ['font' => ['bold' => true, 'color' => ['argb' => 'FF854D0E']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFEF08A']]];
              $styleGreenResolved = ['font' => ['bold' => true, 'color' => ['argb' => 'FF15803D']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFDCFCE7']]];
              $styleGrayDismissed = ['font' => ['bold' => true, 'color' => ['argb' => 'FF475569']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF1F5F9']]];
              $styleGrayInactive = ['font' => ['color' => ['argb' => 'FF94A3B8']], 'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF8FAFC']]];

              $isMajorType = (strpos($rowCategory, 'AUTOMATIC MAJOR') !== false || strpos($displayLevel, 'AUTOMATIC MAJOR') !== false || strpos($pendingCol, 'AUTOMATIC MAJOR') !== false || $offenseLevel === 'MAJOR');
              $isSec4Type  = (strpos($rowCategory, 'SECTION 4') !== false || strpos($displayLevel, 'SECTION 4') !== false || strpos($pendingCol, 'SECTION 4') !== false || $hasSection4);

              if ($isDismissed) {
                  $colorStyle = $styleGrayDismissed;
              } elseif ($isMajorType) {
                  $colorStyle = $styleRedMajor;
              } elseif ($isSec4Type) {
                  $colorStyle = $styleYellowSec4;
              } elseif ($decidedCat > 0 || !empty($r['final_decision'])) {
                  $colorStyle = $styleGreenResolved;
              } else {
                  $colorStyle = $styleYellowSec4;
              }

              if ($isPending) {
                  $pendingStyle = $isMajorType ? $styleRedMajor : $styleYellowSec4;
              } else {
                  $pendingStyle = $styleGrayInactive;
              }

              $resolvedStyle = $isResolved ? $styleGreenResolved : $styleGrayInactive;

              $sheet->getStyle('F' . $rRow)->applyFromArray($colorStyle);
              $sheet->getStyle('G' . $rRow)->applyFromArray($pendingStyle);
              $sheet->getStyle('H' . $rRow)->applyFromArray($resolvedStyle);
              $sheet->getStyle('I' . $rRow)->applyFromArray($colorStyle);
              $sheet->getStyle('N' . $rRow)->applyFromArray($colorStyle);

              $currRow++;
          }

          $groupEndRow = $currRow - 1;

          if ($hasSection4 && $groupEndRow > $groupStartRow) {
              $sheet->mergeCells("C{$groupStartRow}:C{$groupEndRow}");
              $sheet->mergeCells("D{$groupStartRow}:D{$groupEndRow}");
              $sheet->mergeCells("E{$groupStartRow}:E{$groupEndRow}");
              $sheet->mergeCells("F{$groupStartRow}:F{$groupEndRow}");
          }
      }

      return $currRow;
  }

  // =========================================================================
  // SHEET 1: EXECUTIVE SUMMARY
  // =========================================================================
  $sheet1 = $spreadsheet->getActiveSheet();
  $sheet1Title = 'Executive Summary';
  $sheet1->setTitle($sheet1Title);
  $sheet1->setShowGridlines(true);

  // Header Banner
  $sheet1->setCellValue('A1', 'NATIONAL UNIVERSITY LIPA — MONTHLY DISCIPLINE REPORT (' . $titleMonthStr . ')');
  $sheet1->mergeCells('A1:N1');
  $sheet1->getStyle('A1:N1')->applyFromArray($styleTitleHeader);
  $sheet1->getRowDimension(1)->setRowHeight(32);

  $sheet1->setCellValue('A2', 'Student Discipline Office • Generated: ' . date('F j, Y g:i A') . ' • Target Audience: ' . $audience);
  $sheet1->mergeCells('A2:N2');
  $sheet1->getStyle('A2:N2')->applyFromArray($styleSubHeader);
  $sheet1->getRowDimension(2)->setRowHeight(20);

  // Summary Metrics (Dashboard Cards A4:N5)
  $cards = [
      'A' => ['label' => 'TOTAL OFFENSES', 'val' => $total, 'hdrColor' => 'FF1B2B6B', 'valColor' => 'FF1B2B6B', 'bgColor' => 'FFF8FAFC', 'span' => 'A4:B4', 'vSpan' => 'A5:B5'],
      'C' => ['label' => 'MINOR OFFENSES', 'val' => $minorVal, 'hdrColor' => 'FFB45309', 'valColor' => 'FFB45309', 'bgColor' => 'FFFEF3C7', 'span' => 'C4:D4', 'vSpan' => 'C5:D5'],
      'E' => ['label' => 'MAJOR OFFENSES', 'val' => $majorVal, 'hdrColor' => 'FF991B1B', 'valColor' => 'FF991B1B', 'bgColor' => 'FFFEE2E2', 'span' => 'E4:F4', 'vSpan' => 'E5:F5'],
      'G' => ['label' => 'ACTIVE CASES', 'val' => $activeCases, 'hdrColor' => 'FF6B21A8', 'valColor' => 'FF6B21A8', 'bgColor' => 'FFF3E8FF', 'span' => 'G4:H4', 'vSpan' => 'G5:H5'],
      'I' => ['label' => 'DISMISSED OFFENSES', 'val' => $dismissedOffensesVal, 'hdrColor' => 'FF475569', 'valColor' => 'FF475569', 'bgColor' => 'FFF1F5F9', 'span' => 'I4:J4', 'vSpan' => 'I5:J5'],
      'K' => ['label' => 'DISMISSED CASES', 'val' => $dismissedCasesVal, 'hdrColor' => 'FF334155', 'valColor' => 'FF334155', 'bgColor' => 'FFE2E8F0', 'span' => 'K4:N4', 'vSpan' => 'K5:N5'],
  ];

  foreach ($cards as $colKey => $c) {
      $sheet1->setCellValue($colKey . '4', $c['label']);
      $sheet1->mergeCells($c['span']);
      $sheet1->getStyle($c['span'])->applyFromArray([
          'font' => ['bold' => true, 'size' => 9, 'color' => ['argb' => 'FFFFFFFF']],
          'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
          'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $c['hdrColor']]],
      ]);

      $sheet1->setCellValue($colKey . '5', $c['val']);
      $sheet1->mergeCells($c['vSpan']);
      $sheet1->getStyle($c['vSpan'])->applyFromArray([
          'font' => ['bold' => true, 'size' => 20, 'color' => ['argb' => $c['valColor']]],
          'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
          'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $c['bgColor']]],
          'borders' => ['outline' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => $c['hdrColor']]]],
      ]);
  }
  $sheet1->getRowDimension(4)->setRowHeight(18);
  $sheet1->getRowDimension(5)->setRowHeight(32);

  // Hidden Data for Charts in Columns AA to AF
  $sheet1->setCellValue('AA4', 'Offense Category');
  $sheet1->setCellValue('AB4', 'Cases Count');
  $sheet1->setCellValue('AE4', 'Degree Program');
  $sheet1->setCellValue('AF4', 'Cases Count');

  // Build top 5 + Other for Chart Legend
  $chartBreakdownMap = [];
  $topCount = 0;
  $otherSum = 0;
  foreach ($breakdownMap as $name => $count) {
      $shortName = shorten_offense_name_for_chart($name);
      if ($topCount < 5) {
          $chartBreakdownMap[$shortName] = ($chartBreakdownMap[$shortName] ?? 0) + $count;
          $topCount++;
      } else {
          $otherSum += $count;
      }
  }
  if ($otherSum > 0) {
      $chartBreakdownMap['Other Offenses'] = ($chartBreakdownMap['Other Offenses'] ?? 0) + $otherSum;
  }

  $bRow = 5;
  foreach ($chartBreakdownMap as $name => $count) {
      $sheet1->setCellValue('AA' . $bRow, $name);
      $sheet1->setCellValue('AB' . $bRow, $count);
      $bRow++;
  }
  $bEndRow = max(5, $bRow - 1);

  $cRow = 5;
  $topN = 8;
  foreach ($coursesMap as $prog => $count) {
      $sheet1->setCellValue('AE' . $cRow, $prog);
      $sheet1->setCellValue('AF' . $cRow, $count);
      $cRow++;
      if ($cRow >= 5 + $topN) break;
  }
  $cEndRow = max(5, $cRow - 1);

  // Create Doughnut / Pie Chart (A7:F24)
  if (!empty($chartBreakdownMap)) {
      $dataSeriesLabels = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheet1Title}'!\$AB\$4", null, 1)];
      $xAxisTickValues = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheet1Title}'!\$AA\$5:\$AA\${$bEndRow}", null, count($chartBreakdownMap))];
      $dataSeriesValues = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'{$sheet1Title}'!\$AB\$5:\$AB\${$bEndRow}", null, count($chartBreakdownMap))];

      $series = new DataSeries(
          DataSeries::TYPE_DOUGHNUTCHART,
          null,
          range(0, count($dataSeriesValues) - 1),
          $dataSeriesLabels,
          $xAxisTickValues,
          $dataSeriesValues
      );
      
      $layout = new \PhpOffice\PhpSpreadsheet\Chart\Layout();
      $layout->setShowVal(false);
      $layout->setShowPercent(true);
      
      $plotArea = new PlotArea($layout, [$series]);
      $legend = new Legend(Legend::POSITION_RIGHT, null, false);
      $chartTitle = new Title('Offense Breakdown Distribution');

      $chart = new Chart('chart1', $chartTitle, $legend, $plotArea, true, 0, null, null);
      $chart->setTopLeftPosition('A7');
      $chart->setBottomRightPosition('F24');
      $sheet1->addChart($chart);
  }

  // Create Column Bar Chart (G7:N24)
  if (!empty($coursesMap)) {
      $dataSeriesLabels2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheet1Title}'!\$AF\$4", null, 1)];
      $xAxisTickValues2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_STRING, "'{$sheet1Title}'!\$AE\$5:\$AE\${$cEndRow}", null, count($coursesMap))];
      $dataSeriesValues2 = [new DataSeriesValues(DataSeriesValues::DATASERIES_TYPE_NUMBER, "'{$sheet1Title}'!\$AF\$5:\$AF\${$cEndRow}", null, count($coursesMap))];

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
      $chart2->setBottomRightPosition('N24');
      $sheet1->addChart($chart2);
  }

  // Summary Tables Section (Row 26)
  $sheet1->setCellValue('A26', 'OFFENSE BREAKDOWN SUMMARY');
  $sheet1->mergeCells('A26:F26');
  $sheet1->getStyle('A26:F26')->applyFromArray($styleSectionBanner);

  $sheet1->setCellValue('G26', 'ACADEMIC PROGRAM / COURSE SUMMARY');
  $sheet1->mergeCells('G26:N26');
  $sheet1->getStyle('G26:N26')->applyFromArray($styleSectionBanner);
  $sheet1->getRowDimension(26)->setRowHeight(24);

  // Sub-headers Row 27
  $sheet1->setCellValue('A27', 'Offense Category / Violation');
  $sheet1->mergeCells('A27:D27');
  $sheet1->setCellValue('E27', 'Count');
  $sheet1->setCellValue('F27', '% Share');
  $sheet1->getStyle('A27:F27')->applyFromArray($styleTableHeader);

  $sheet1->setCellValue('G27', 'Degree Program / Strand');
  $sheet1->mergeCells('G27:L27');
  $sheet1->setCellValue('M27', 'Count');
  $sheet1->setCellValue('N27', '% Share');
  $sheet1->getStyle('G27:N27')->applyFromArray($styleTableHeader);
  $sheet1->getRowDimension(27)->setRowHeight(22);

  // Fill Summary Tables
  $sumRow1 = 28;
  $bTotal = array_sum($breakdownMap);
  foreach ($breakdownMap as $name => $count) {
      $pct = $bTotal > 0 ? sprintf('%.1f%%', ($count / $bTotal) * 100) : '0.0%';
      $sheet1->setCellValue('A' . $sumRow1, $name);
      $sheet1->mergeCells("A{$sumRow1}:D{$sumRow1}");
      $sheet1->setCellValue('E' . $sumRow1, $count);
      $sheet1->setCellValue('F' . $sumRow1, $pct);
      $sheet1->getStyle("E{$sumRow1}:F{$sumRow1}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
      $sumRow1++;
  }

  $sumRow2 = 28;
  $cTotal = array_sum($coursesMap);
  foreach ($coursesMap as $prog => $count) {
      $pct = $cTotal > 0 ? sprintf('%.1f%%', ($count / $cTotal) * 100) : '0.0%';
      $sheet1->setCellValue('G' . $sumRow2, $prog);
      $sheet1->mergeCells("G{$sumRow2}:L{$sumRow2}");
      $sheet1->setCellValue('M' . $sumRow2, $count);
      $sheet1->setCellValue('N' . $sumRow2, $pct);
      $sheet1->getStyle("M{$sumRow2}:N{$sumRow2}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
      $sumRow2++;
  }

  $maxSumRow = max($sumRow1, $sumRow2);
  if ($maxSumRow > 28) {
      $sheet1->getStyle('A28:N' . ($maxSumRow - 1))->applyFromArray($styleTableBody);
  }

  // Raw Data Section Header in Sheet 1
  $logsHeaderRow = $maxSumRow + 2;
  $sheet1->setCellValue('A' . $logsHeaderRow, 'DETAILED DISCIPLINARY LOGS & CASE RECORDS');
  $sheet1->mergeCells("A{$logsHeaderRow}:N{$logsHeaderRow}");
  $sheet1->getStyle("A{$logsHeaderRow}:N{$logsHeaderRow}")->applyFromArray($styleSectionBanner);
  $sheet1->getRowDimension($logsHeaderRow)->setRowHeight(26);

  $dataStartRow1 = $logsHeaderRow + 1;
  $sheet1->fromArray($headers, null, 'A' . $dataStartRow1);
  $sheet1->getStyle('A'.$dataStartRow1.':N'.$dataStartRow1)->applyFromArray($styleTableHeader);
  $sheet1->getRowDimension($dataStartRow1)->setRowHeight(24);

  // Populate Sheet 1 Data Rows
  $s1EndRow = populate_sheet_data_rows($sheet1, $studentGroups, $dataStartRow1 + 1, $showNames, $styleTableBody);

  if ($s1EndRow > $dataStartRow1 + 1) {
      $sheet1->getStyle('A'.($dataStartRow1 + 1).':N'.($s1EndRow - 1))->applyFromArray($styleTableBody);
  }

  foreach ($colWidths as $col => $w) {
      $sheet1->getColumnDimension($col)->setWidth($w);
  }
  $sheet1->getStyle("J{$dataStartRow1}:N{$s1EndRow}")->getAlignment()->setWrapText(true);
  $sheet1->getStyle("A{$dataStartRow1}:N{$s1EndRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

  // =========================================================================
  // SHEET 2: DETAILED DISCIPLINARY LOGS (DEDICATED TABLE WITH FROZEN HEADERS)
  // =========================================================================
  $sheet2 = $spreadsheet->createSheet();
  $sheet2->setTitle('Detailed Records');
  $sheet2->setShowGridlines(true);

  // Banner
  $sheet2->setCellValue('A1', 'NATIONAL UNIVERSITY LIPA — DETAILED DISCIPLINARY & CASE LOGS');
  $sheet2->mergeCells('A1:N1');
  $sheet2->getStyle('A1:N1')->applyFromArray($styleTitleHeader);
  $sheet2->getRowDimension(1)->setRowHeight(30);

  $sheet2->setCellValue('A2', 'Generated: ' . date('F j, Y g:i A') . ' • Target Audience: ' . $audience . ' • Month: ' . $titleMonthStr);
  $sheet2->mergeCells('A2:N2');
  $sheet2->getStyle('A2:N2')->applyFromArray($styleSubHeader);
  $sheet2->getRowDimension(2)->setRowHeight(18);

  // Table Headers at Row 3
  $sheet2->fromArray($headers, null, 'A3');
  $sheet2->getStyle('A3:N3')->applyFromArray($styleTableHeader);
  $sheet2->getRowDimension(3)->setRowHeight(24);

  // Populate Sheet 2 Data Rows
  $s2EndRow = populate_sheet_data_rows($sheet2, $studentGroups, 4, $showNames, $styleTableBody);

  if ($s2EndRow > 4) {
      $sheet2->getStyle('A4:N' . ($s2EndRow - 1))->applyFromArray($styleTableBody);
      $sheet2->setAutoFilter('A3:N' . ($s2EndRow - 1));
  }

  foreach ($colWidths as $col => $w) {
      $sheet2->getColumnDimension($col)->setWidth($w);
  }
  $sheet2->getStyle("J3:N{$s2EndRow}")->getAlignment()->setWrapText(true);
  $sheet2->getStyle("A3:N{$s2EndRow}")->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

  // Freeze top 3 rows on Sheet 2 so header row stays visible when scrolling down!
  $sheet2->freezePane('A4');

  // Make Sheet 1 active default
  $spreadsheet->setActiveSheetIndex(0);

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