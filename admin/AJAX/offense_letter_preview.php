<?php
require_once __DIR__ . '/../../database/database.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_NONE) session_start();

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);

$offenseId = (int)($body['offense_id'] ?? 0);
$subject = trim((string)($body['subject'] ?? 'Minor Offense Notice'));
$letterBody = trim((string)($body['body'] ?? ''));

if ($offenseId <= 0) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'message' => 'Invalid offense_id.']);
  exit;
}

// Load data for PDF (includes this offense + ALL prior offenses list)
$row = db_one(
  "SELECT
     o.offense_id, o.level, o.description, o.date_committed,
     s.student_id, s.student_fn, s.student_ln, s.student_email,
     ot.code AS offense_code, ot.name AS offense_name,
     g.guardian_email, g.guardian_fn, g.guardian_ln
   FROM offense o
   JOIN student s ON s.student_id = o.student_id
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   LEFT JOIN guardian g ON g.student_id = s.student_id
   WHERE o.offense_id = :oid
   LIMIT 1",
  [':oid' => $offenseId]
);

if (!$row) {
  http_response_code(404);
  echo json_encode(['ok' => false, 'message' => 'Offense not found.']);
  exit;
}

// ✅ Fetch student's offense history (so PDF shows what student committed)
$history = db_all(
  "SELECT
      o.offense_id,
      o.date_committed,
      o.description,
      ot.level,
      ot.code,
      ot.name
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   WHERE o.student_id = :sid
   ORDER BY o.date_committed DESC, o.offense_id DESC
   LIMIT 30",
  [':sid' => (string)$row['student_id']]
);

require_once __DIR__ . '/pdf_text_letter.php';

$studentName = trim($row['student_fn'] . ' ' . $row['student_ln']);
$guardianName = trim((string)($row['guardian_fn'] ?? '') . ' ' . (string)($row['guardian_ln'] ?? ''));
if ($guardianName === '') $guardianName = 'Parent/Guardian';

$lines = [];
$lines[] = "To: {$guardianName}";
$lines[] = "Student: {$studentName} ({$row['student_id']})";
$lines[] = "Generated: " . date('F j, Y g:i A');
$lines[] = "";

$lines[] = $letterBody;

$pdfBytes = pdf_make_simple($subject, $lines);

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