<?php
require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

$offenseId = (int)($_POST['offense_id'] ?? 0);
$subject = trim((string)($_POST['subject'] ?? 'Minor Offense Notice'));
$letterBody = trim((string)($_POST['body'] ?? ''));

if ($offenseId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Invalid offense_id.']);
  exit;
}

// Load data for PDF (includes this offense + ALL prior offenses list)
$params = [':oid' => $offenseId];
db_add_encryption_key($params);
$row = db_one(
  "SELECT
     o.offense_id, o.level, " . db_decrypt_col('description', 'o') . " AS description, o.date_committed,
     s.student_id, " . db_decrypt_cols(['student_fn', 'student_ln', 'student_email'], 's') . ",
     ot.code AS offense_code, ot.name AS offense_name,
     g.guardian_email, g.guardian_fn, g.guardian_ln
   FROM offense o
   JOIN student s ON s.student_id = o.student_id
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   LEFT JOIN guardian g ON g.student_id = s.student_id
   WHERE o.offense_id = :oid
   LIMIT 1",
  $params
);

if (!$row) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'message' => 'Offense not found.']);
  exit;
}

// ✅ Fetch student's offense history (so PDF shows what student committed)
$histParams = [':sid' => (string)$row['student_id']];
db_add_encryption_key($histParams);
$history = db_all(
  "SELECT
      o.offense_id,
      o.date_committed,
      " . db_decrypt_col('description', 'o') . " AS description,
      ot.level,
      ot.code,
      ot.name
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   WHERE o.student_id = :sid
   ORDER BY o.date_committed DESC, o.offense_id DESC
   LIMIT 30",
  $histParams
);

require_once __DIR__ . '/../../vendor/autoload.php';
use Dompdf\Dompdf;
use Dompdf\Options;

$studentName = trim($row['student_fn'] . ' ' . $row['student_ln']);
$guardianName = trim((string)($row['guardian_fn'] ?? '') . ' ' . (string)($row['guardian_ln'] ?? ''));
if ($guardianName === '') $guardianName = 'Parent/Guardian';

$dateGen = date('F j, Y g:i A');

$imagePath = null;
$imgHtml = '';
if (isset($_FILES['letter_image']) && $_FILES['letter_image']['error'] === UPLOAD_ERR_OK) {
    $imagePath = $_FILES['letter_image']['tmp_name'];
    $imgType = pathinfo($_FILES['letter_image']['name'], PATHINFO_EXTENSION);
    $imgData = file_get_contents($imagePath);
    $base64 = 'data:image/' . $imgType . ';base64,' . base64_encode($imgData);
    
    $imgX = isset($_POST['image_x']) ? (int)$_POST['image_x'] : 72;
    $imgYOffset = isset($_POST['image_y_offset']) ? (int)$_POST['image_y_offset'] : 0;
    $imgW = isset($_POST['image_w']) ? (int)$_POST['image_w'] : 150;
    
    // Convert offsets to relative basic CSS or absolute positioning
    $imgHtml = '<div style="margin-top: 40px; margin-left: '.($imgX - 72).'px;"><img src="'.$base64.'" width="'.$imgW.'" style="position: relative; top: '.$imgYOffset.'px;" /></div>';
}

$html = '
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; font-size: 11pt; color: #333; line-height: 1.5; }
        .header { margin-bottom: 30px; }
        .title { font-size: 16pt; font-weight: bold; margin-bottom: 20px; color: #000; }
        .meta { margin-bottom: 20px; font-size: 11pt; }
        .content { font-size: 11pt; }
        .sdo { font-size: 16pt; font-family: "Times New Roman", Times, serif; margin-bottom: 5px; }
        .official { font-size: 10pt; color: #666; margin-bottom: 30px; }
    </style>
</head>
<body>
    <div class="header">
        <div class="sdo">Student Discipline Office</div>
        <div class="official">Official Student Conduct Notice<br>IdentiTrack System</div>
        <div class="title">' . htmlspecialchars($subject) . '</div>
        <div class="meta">
            <strong>To:</strong> ' . htmlspecialchars($guardianName) . '<br>
            <strong>Student:</strong> ' . htmlspecialchars($studentName) . ' (' . htmlspecialchars($row['student_id']) . ')<br>
            <strong>Generated:</strong> ' . $dateGen . '
        </div>
    </div>
    <div class="content">
        ' . $letterBody . '
    </div>
    ' . $imgHtml . '
</body>
</html>';

$options = new Options();
$options->set('isHtml5ParserEnabled', true);
$options->set('isRemoteEnabled', true);

$dompdf = new Dompdf($options);
$dompdf->loadHtml($html);
$dompdf->setPaper('A4', 'portrait');
$dompdf->render();

$pdfBytes = $dompdf->output();

// Save PDF file under /uploads/letters/
$dirAbs = __DIR__ . '/../../uploads/letters';
if (!is_dir($dirAbs)) mkdir($dirAbs, 0775, true);

$filename = 'minor_offense_' . $offenseId . '_' . date('Ymd_His') . '.pdf';
$fileAbs = $dirAbs . '/' . $filename;

file_put_contents($fileAbs, $pdfBytes);

// Return a URL reachable from /admin/offense_new.php
$pdfUrl = '../uploads/letters/' . $filename;

echo json_encode([
  'ok' => true,
  'pdf_url' => $pdfUrl . '?v=' . time(),
]);
exit;