<?php
require_once __DIR__ . '/../database/database.php';
require_admin();

$appealId = (int)($_GET['id'] ?? 0);
$appeal = db_one(
    "SELECT
        sar.appeal_id,
        sar.student_id,
        sar.offense_id,
        sar.case_id,
        sar.appeal_kind,
        sar.reason,
        sar.status,
        sar.admin_response,
        sar.attachment_name,
        sar.created_at,
        sar.decided_at,
        CONCAT(s.student_fn, ' ', s.student_ln) AS student_name,
        ot.code AS offense_code,
        ot.name AS offense_name,
        o.description AS original_offense_description,
        o.date_committed,
        uc.decided_category,
        uc.case_summary,
        uc.final_decision,
        uc.punishment_details
     FROM student_appeal_request sar
     JOIN student s ON s.student_id = sar.student_id
     LEFT JOIN offense o ON o.offense_id = sar.offense_id
     LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
     LEFT JOIN upcc_case uc ON uc.case_id = sar.case_id
     WHERE sar.appeal_id = :id
     LIMIT 1",
    [':id' => $appealId]
);

if (!$appeal) {
    die('Appeal not found.');
}

$kind = strtoupper((string)($appeal['appeal_kind'] ?? 'OFFENSE'));
$kindLabel = $kind === 'UPCC_CASE' ? 'UPCC Case' : 'Offense';

$targetLabel = '';
if ($kind === 'UPCC_CASE') {
    $targetLabel = "UPCC Case #" . (int)($appeal['case_id']) . " • Category " . (int)($appeal['decided_category']);
} else {
    $targetLabel = "Offense #" . (int)($appeal['offense_id']) . " " . trim((string)($appeal['offense_code']) . ' ' . (string)($appeal['offense_name']));
}

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <title>Print Appeal #<?php echo $appealId; ?></title>
  <style>
    body { font-family: Arial, sans-serif; margin: 40px; color: #000; line-height: 1.5; }
    .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #000; padding-bottom: 20px; }
    .header h1 { margin: 0 0 10px 0; font-size: 24px; }
    .row { display: flex; margin-bottom: 10px; }
    .label { font-weight: bold; width: 150px; }
    .value { flex: 1; }
    .box { border: 1px solid #000; padding: 15px; margin-top: 20px; }
    .box-title { font-weight: bold; border-bottom: 1px solid #000; padding-bottom: 5px; margin-bottom: 10px; text-transform: uppercase; }
    @media print {
        body { margin: 0; }
        .no-print { display: none; }
    }
  </style>
</head>
<body onload="window.print()">
  <div class="no-print" style="margin-bottom: 20px;">
    <button onclick="window.print()" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">Print</button>
    <button onclick="window.close()" style="padding: 10px 20px; font-size: 16px; cursor: pointer;">Close</button>
  </div>
  
  <div class="header">
    <h1>Appeal Request Report</h1>
    <div>Generated on <?php echo date('M d, Y g:i A'); ?></div>
  </div>

  <div class="row">
    <div class="label">Appeal ID:</div>
    <div class="value">#<?php echo $appeal['appeal_id']; ?></div>
  </div>
  <div class="row">
    <div class="label">Student Name:</div>
    <div class="value"><?php echo htmlspecialchars((string)$appeal['student_name']); ?> (ID: <?php echo htmlspecialchars((string)$appeal['student_id']); ?>)</div>
  </div>
  <div class="row">
    <div class="label">Appeal Type:</div>
    <div class="value"><?php echo $kindLabel; ?></div>
  </div>
  <div class="row">
    <div class="label">Target Record:</div>
    <div class="value"><?php echo htmlspecialchars($targetLabel); ?></div>
  </div>
  <div class="row">
    <div class="label">Submitted On:</div>
    <div class="value"><?php echo date('M d, Y g:i A', strtotime((string)$appeal['created_at'])); ?></div>
  </div>
  <div class="row">
    <div class="label">Current Status:</div>
    <div class="value"><strong><?php echo htmlspecialchars((string)$appeal['status']); ?></strong></div>
  </div>
  <?php if (!empty($appeal['decided_at'])): ?>
  <div class="row">
    <div class="label">Decided On:</div>
    <div class="value"><?php echo date('M d, Y g:i A', strtotime((string)$appeal['decided_at'])); ?></div>
  </div>
  <?php endif; ?>

  <div class="box">
    <div class="box-title">Appeal Reason / Student Statement</div>
    <div><?php echo nl2br(htmlspecialchars((string)$appeal['reason'])); ?></div>
  </div>

  <div class="box">
    <div class="box-title">Original Record Details</div>
    <?php if ($kind === 'UPCC_CASE'): ?>
      <div><strong>Case Summary:</strong><br/><?php echo nl2br(htmlspecialchars((string)$appeal['case_summary'])); ?></div>
      <div style="margin-top: 10px;"><strong>Final Decision:</strong><br/><?php echo nl2br(htmlspecialchars((string)$appeal['final_decision'])); ?></div>
    <?php else: ?>
      <div><strong>Date Committed:</strong> <?php echo date('M d, Y', strtotime((string)$appeal['date_committed'])); ?></div>
      <div style="margin-top: 10px;"><strong>Offense Description:</strong><br/><?php echo nl2br(htmlspecialchars((string)$appeal['original_offense_description'])); ?></div>
    <?php endif; ?>
  </div>

  <?php if (!empty($appeal['attachment_name'])): ?>
  <div class="box">
    <div class="box-title">Attachment</div>
    <div>File Name: <?php echo htmlspecialchars((string)$appeal['attachment_name']); ?></div>
  </div>
  <?php endif; ?>

  <?php if (!empty($appeal['admin_response'])): ?>
  <div class="box">
    <div class="box-title">Admin Response / Decision Notes</div>
    <div><?php echo nl2br(htmlspecialchars((string)$appeal['admin_response'])); ?></div>
  </div>
  <?php endif; ?>

  <div style="margin-top: 50px; display: flex; justify-content: space-between;">
    <div style="width: 40%; text-align: center; border-top: 1px solid #000; padding-top: 10px;">
        Student Signature
    </div>
    <div style="width: 40%; text-align: center; border-top: 1px solid #000; padding-top: 10px;">
        Parent/Guardian Signature
    </div>
  </div>

  <div style="margin-top: 50px; width: 40%; text-align: center; border-top: 1px solid #000; padding-top: 10px;">
      Admin / UPCC Signature
  </div>

</body>
</html>
