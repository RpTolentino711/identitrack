<?php
// File: admin/print_nte.php
// Form F-005: Notice To Explain (Official NU Lipa Disciplinary Form)

require_once __DIR__ . '/../database/database.php';
require_admin();

$admin = admin_current();
$adminName = trim((string)($admin['full_name'] ?? $admin['username'] ?? 'Discipline Officer'));

$caseId = (int)($_GET['case_id'] ?? 0);
$offenseId = (int)($_GET['offense_id'] ?? 0);

$studentName = '____________________________';
$studentId = '';
$studentProgram = '';
$caseNoStr = '2026-XXXX';
$sectionStr = '____';
$pageStr = '____';
$provisionText = '_____________________________________________________________________';
$complainantName = 'Student Discipline Office';
$incidentDate = date('F d, Y');
$allegedDetails = '_________________________________________________';
$incidentReportNo = 'IR-' . date('Y') . '-XXXX';

if ($caseId > 0) {
    $case = db_one("
        SELECT c.case_id, c.student_id, c.created_at,
               o.description AS offense_desc, o.date_committed,
               ot.code AS offense_code, ot.name AS offense_name, ot.section AS handbook_section, ot.page_num AS handbook_page,
               CONCAT(" . db_decrypt_col('student_ln', 's') . ", ', ', " . db_decrypt_col('student_fn', 's') . ") AS student_name,
               s.program, s.year_level, s.section AS student_section,
               sg.full_name AS guard_name
        FROM upcc_case c
        LEFT JOIN upcc_case_offense uco ON uco.case_id = c.case_id
        LEFT JOIN offense o ON o.offense_id = uco.offense_id
        LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
        LEFT JOIN student s ON s.student_id = c.student_id
        LEFT JOIN guard_violation_report gvr ON gvr.student_id = c.student_id AND gvr.offense_type_id = o.offense_type_id
        LEFT JOIN security_guard sg ON sg.guard_id = gvr.submitted_by
        WHERE c.case_id = :cid
        ORDER BY o.date_committed DESC LIMIT 1
    ", [':cid' => $caseId]);

    if ($case) {
        $studentName = !empty($case['student_name']) ? $case['student_name'] : 'Student ' . $case['student_id'];
        $studentId = $case['student_id'];
        $studentProgram = trim(($case['program'] ?? '') . ' ' . ($case['year_level'] ? $case['year_level'] . 'th Year' : ''));
        $caseNoStr = 'UPCC-' . date('Y', strtotime($case['created_at'])) . '-' . str_pad((string)$case['case_id'], 4, '0', STR_PAD_LEFT);
        $sectionStr = !empty($case['handbook_section']) ? $case['handbook_section'] : (!empty($case['offense_code']) ? $case['offense_code'] : '____');
        $pageStr = !empty($case['handbook_page']) ? $case['handbook_page'] : '____';
        $provisionText = !empty($case['offense_name']) ? $case['offense_name'] : 'Student Handbook Regulation';
        if (!empty($case['guard_name'])) $complainantName = $case['guard_name'] . ' (Campus Security)';
        if (!empty($case['date_committed'])) $incidentDate = date('F d, Y \a\t h:i A', strtotime($case['date_committed']));
        if (!empty($case['offense_desc'])) $allegedDetails = $case['offense_desc'];
        $incidentReportNo = 'IR-' . date('Y', strtotime($case['created_at'])) . '-' . str_pad((string)$case['case_id'], 4, '0', STR_PAD_LEFT);
    }
} else if ($offenseId > 0) {
    $off = db_one("
        SELECT o.offense_id, o.student_id, o.description AS offense_desc, o.date_committed, o.created_at,
               ot.code AS offense_code, ot.name AS offense_name, ot.section AS handbook_section, ot.page_num AS handbook_page,
               CONCAT(" . db_decrypt_col('student_ln', 's') . ", ', ', " . db_decrypt_col('student_fn', 's') . ") AS student_name,
               s.program, s.year_level
        FROM offense o
        LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
        LEFT JOIN student s ON s.student_id = o.student_id
        WHERE o.offense_id = :oid
        LIMIT 1
    ", [':oid' => $offenseId]);

    if ($off) {
        $studentName = !empty($off['student_name']) ? $off['student_name'] : 'Student ' . $off['student_id'];
        $studentId = $off['student_id'];
        $studentProgram = trim(($off['program'] ?? '') . ' ' . ($off['year_level'] ? $off['year_level'] . 'th Year' : ''));
        $caseNoStr = 'OFF-' . date('Y', strtotime($off['created_at'])) . '-' . str_pad((string)$off['offense_id'], 4, '0', STR_PAD_LEFT);
        $sectionStr = !empty($off['handbook_section']) ? $off['handbook_section'] : (!empty($off['offense_code']) ? $off['offense_code'] : '____');
        $pageStr = !empty($off['handbook_page']) ? $off['handbook_page'] : '____';
        $provisionText = !empty($off['offense_name']) ? $off['offense_name'] : 'Student Handbook Regulation';
        if (!empty($off['date_committed'])) $incidentDate = date('F d, Y \a\t h:i A', strtotime($off['date_committed']));
        if (!empty($off['offense_desc'])) $allegedDetails = $off['offense_desc'];
        $incidentReportNo = 'IR-' . date('Y', strtotime($off['created_at'])) . '-' . str_pad((string)$off['offense_id'], 4, '0', STR_PAD_LEFT);
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Notice To Explain (Form F-005) - IdentiTrack</title>
<style>
  @page { size: A4; margin: 20mm; }
  body {
    font-family: 'Times New Roman', Times, serif;
    font-size: 12pt;
    line-height: 1.5;
    color: #000;
    background: #fff;
    margin: 0;
    padding: 20px;
  }
  .header-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 25px;
  }
  .header-table td {
    vertical-align: top;
  }
  .nu-title {
    font-size: 14pt;
    font-weight: bold;
    text-transform: uppercase;
    color: #1b2b6b;
  }
  .form-num {
    text-align: right;
    font-size: 10pt;
    font-weight: bold;
    color: #555;
  }
  .meta-block {
    margin-bottom: 20px;
  }
  .meta-row {
    margin-bottom: 6px;
  }
  .meta-label {
    font-weight: bold;
    display: inline-block;
    width: 90px;
  }
  .subject-line {
    font-size: 13pt;
    font-weight: bold;
    text-decoration: underline;
    margin: 15px 0;
  }
  .case-no {
    font-weight: bold;
    margin-bottom: 20px;
  }
  .body-paragraph {
    text-align: justify;
    text-indent: 40px;
    margin-bottom: 16px;
  }
  .fill-underline {
    border-bottom: 1px solid #000;
    padding: 0 4px;
    font-weight: bold;
  }
  .notice-box {
    border: 1px solid #000;
    padding: 12px 16px;
    margin: 20px 0;
    background: #f9f9f9;
    font-weight: bold;
  }
  .signature-table {
    width: 100%;
    margin-top: 50px;
    border-collapse: collapse;
  }
  .signature-table td {
    width: 50%;
    vertical-align: top;
  }
  .sig-line {
    border-top: 1px solid #000;
    width: 80%;
    margin-top: 45px;
    padding-top: 4px;
    font-weight: bold;
  }
  .no-print {
    background: #1b2b6b;
    color: #fff;
    padding: 12px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .no-print button {
    background: #f0a500;
    color: #000;
    border: none;
    padding: 8px 18px;
    border-radius: 6px;
    font-weight: bold;
    cursor: pointer;
  }
  @media print {
    .no-print { display: none !important; }
    body { padding: 0; }
  }
</style>
</head>
<body>

<div class="no-print">
  <div>
    <strong>NATIONAL UNIVERSITY LIPA — STUDENT DISCIPLINE OFFICE</strong><br>
    <small>Form F-005: Official Notice To Explain Generator</small>
  </div>
  <button onclick="window.print()">🖨️ Print Form (F-005)</button>
</div>

<table class="header-table">
  <tr>
    <td>
      <div class="nu-title">NATIONAL UNIVERSITY LIPA</div>
      <div>Student Discipline Office (SDO)</div>
      <div>Kalinga Hills, Brgy. Marawoy, Lipa City, Batangas</div>
    </td>
    <td class="form-num">
      FORM F-005<br>
      Revision 02
    </td>
  </tr>
</table>

<hr style="border: 1px solid #1b2b6b; margin-bottom: 20px;">

<div class="meta-block">
  <div class="meta-row"><span class="meta-label">To:</span> <span class="fill-underline"><?= htmlspecialchars($studentName) ?></span> <?= $studentId ? ' (' . htmlspecialchars($studentId) . ' - ' . htmlspecialchars($studentProgram) . ')' : '' ?></div>
  <div class="meta-row"><span class="meta-label">From:</span> <span class="fill-underline"><?= htmlspecialchars($adminName) ?></span></div>
  <div class="meta-row"><span class="meta-label">Role:</span> Discipline Officer / Student Discipline Office</div>
  <div class="meta-row"><span class="meta-label">Date:</span> <span class="fill-underline"><?= date('F d, Y') ?></span></div>
</div>

<div class="subject-line">SUBJECT: NOTICE TO EXPLAIN</div>
<div class="case-no">Discipline Case No. <span class="fill-underline"><?= htmlspecialchars($caseNoStr) ?></span>: Violation of Section <span class="fill-underline"><?= htmlspecialchars($sectionStr) ?></span>, Page <span class="fill-underline"><?= htmlspecialchars($pageStr) ?></span> of the Student Handbook</div>

<div class="body-paragraph">
  Please be informed that an infraction complaint has been filed against you by 
  <span class="fill-underline"><?= htmlspecialchars($complainantName) ?></span>, 
  thru the Student Discipline Office for alleged violation of Section <span class="fill-underline"><?= htmlspecialchars($sectionStr) ?></span>, 
  Page <span class="fill-underline"><?= htmlspecialchars($pageStr) ?></span> 
  which provides that:
  <blockquote style="margin: 10px 20px; font-style: italic; font-weight: bold; background: #f5f5f5; padding: 10px; border-left: 3px solid #1b2b6b;">
    "<?= htmlspecialchars($provisionText) ?>"
  </blockquote>
</div>

<div class="body-paragraph">
  It is alleged that on <span class="fill-underline"><?= htmlspecialchars($incidentDate) ?></span>, you committed the following act(s):
  <div style="margin: 10px 20px; padding: 10px; border: 1px solid #ccc; font-weight: bold;">
    <?= htmlspecialchars($allegedDetails) ?>
  </div>
</div>

<div class="body-paragraph">
  Attached for your reference is the following official document:
  <br>
  <strong>Incident Report Identified as:</strong> <span class="fill-underline"><?= htmlspecialchars($incidentReportNo) ?></span>
</div>

<div class="notice-box">
  In view thereof, please explain in writing within five (5) days upon receipt of this notice. Failure to respond within the period given will be construed as a waiver of your right to be heard, and investigation into the foregoing matter will proceed.
</div>

<div style="margin-top: 20px;">
  This is for strict compliance.
</div>

<table class="signature-table">
  <tr>
    <td>
      <div>Prepared by:</div>
      <div class="sig-line">
        <?= htmlspecialchars($adminName) ?><br>
        <span style="font-weight: normal; font-size: 10pt;">Discipline Officer</span>
      </div>
    </td>
    <td>
      <div>Approved by:</div>
      <div class="sig-line">
        ACADEMIC DIRECTOR / UPCC BOARD<br>
        <span style="font-weight: normal; font-size: 10pt;">Academic Director</span>
      </div>
    </td>
  </tr>
</table>

</body>
</html>
