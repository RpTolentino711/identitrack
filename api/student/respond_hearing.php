<?php
declare(strict_types=1);

require_once __DIR__ . '/../../database/database.php';
ensure_hearing_workflow_schema();

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept, Authorization');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
  http_response_code(204);
  exit;
}

function json_out(bool $ok, string $message = '', $data = null, int $status = 200): void {
  http_response_code($status);
  echo json_encode(['ok' => $ok, 'message' => $message, 'data' => $data]);
  exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  json_out(false, 'Method not allowed.', null, 405);
}

$raw = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true);
if (!is_array($body)) $body = [];

$studentId = trim((string)($body['student_id'] ?? ''));
$caseId = (int)($body['case_id'] ?? 0);
$response = strtoupper(trim((string)($body['response'] ?? '')));

if ($studentId === '' || $caseId <= 0 || !in_array($response, ['ACCEPTED', 'DECLINED'], true)) {
  json_out(false, 'student_id, case_id, and response (ACCEPTED or DECLINED) are required.', null, 400);
}

require_student_api_auth($studentId);

$student = db_one("SELECT is_active FROM student WHERE student_id = :sid", [':sid' => $studentId]);
if (!$student) json_out(false, 'Student not found.', null, 404);
if ((int)($student['is_active'] ?? 0) !== 1) json_out(false, 'Student is not active.', null, 403);

$case = db_one("SELECT case_id, status FROM upcc_case WHERE case_id = :cid AND student_id = :sid", [
    ':cid' => $caseId,
    ':sid' => $studentId
]);

if (!$case) {
    json_out(false, 'Case not found or belongs to another student.', null, 404);
}

if (!in_array($case['status'], ['PENDING', 'UNDER_INVESTIGATION', 'UNDER_APPEAL'], true)) {
    json_out(false, 'Hearing schedule cannot be updated for this case state.', null, 400);
}

db_exec("UPDATE upcc_case SET student_hearing_response = :resp WHERE case_id = :cid", [
    ':resp' => $response,
    ':cid' => $caseId
]);

$activityAction = $response === 'ACCEPTED' ? 'STUDENT_ACCEPTED_HEARING' : 'STUDENT_DECLINED_HEARING';
upcc_log_case_activity($caseId, 'SYSTEM', 0, $activityAction, ['student_id' => $studentId]);

// Fetch student name
$sRow = db_one("SELECT student_fn, student_ln FROM student WHERE student_id = :sid", [':sid' => $studentId]);
$studentName = $sRow ? trim($sRow['student_fn'] . ' ' . $sRow['student_ln']) : $studentId;

// 1. Add notification on the admin side
$notifTitle = $response === 'ACCEPTED' ? 'Hearing Schedule Accepted' : 'Hearing Schedule Declined';
$notifMsg = "Student {$studentName} ({$studentId}) has {$response} the scheduled hearing for Case #{$caseId}.";

db_exec(
    "INSERT INTO notification (type, title, message, student_id, admin_id, related_table, related_id, is_read, is_deleted, created_at)
     VALUES ('HEARING_RSVP', :title, :msg, :sid, NULL, 'upcc_case', :cid, 0, 0, NOW())",
    [
        ':title' => $notifTitle,
        ':msg' => $notifMsg,
        ':sid' => $studentId,
        ':cid' => (string)$caseId
    ]
);

// 2. Email panel members and administrators
$members = db_all("
    SELECT u.full_name, u.email 
    FROM upcc_user u
    JOIN upcc_case_panel_member pm ON pm.upcc_id = u.upcc_id
    WHERE pm.case_id = :cid AND u.is_active = 1
", [':cid' => $caseId]);

$admins = db_all("SELECT full_name, email FROM admin_user WHERE is_active = 1");

$recipients = [];
foreach ($members as $m) {
    $email = trim(strtolower($m['email']));
    if ($email !== '') {
        $recipients[$email] = trim($m['full_name']);
    }
}
foreach ($admins as $a) {
    $email = trim(strtolower($a['email']));
    if ($email !== '') {
        $recipients[$email] = trim($a['full_name']);
    }
}

if (!empty($recipients)) {
    require_once __DIR__ . '/../../UPCC/class.phpmailer.php';
    require_once __DIR__ . '/../../UPCC/class.smtp.php';

    $caseRow = db_one("SELECT created_at, hearing_date, hearing_time, hearing_type, hearing_link_or_location FROM upcc_case WHERE case_id = :cid", [':cid' => $caseId]);
    $caseLabel = 'UPCC-' . date('Y', strtotime($caseRow['created_at'] ?? 'now')) . '-' . str_pad((string)$caseId, 3, '0', STR_PAD_LEFT);
    
    $hearingDate = $caseRow['hearing_date'] ? date('M j, Y', strtotime($caseRow['hearing_date'])) : 'TBD';
    $hearingTime = $caseRow['hearing_time'] ? date('g:i A', strtotime($caseRow['hearing_time'])) : 'TBD';
    $hearingTypeLabel = (($caseRow['hearing_type'] ?? '') === 'ONLINE') ? 'Online / Virtual' : 'Face-to-Face';
    $hearingLoc = htmlspecialchars($caseRow['hearing_link_or_location'] ?? 'Not provided');

    $statusColor = $response === 'ACCEPTED' ? '#16a34a' : '#dc2626';

    foreach ($recipients as $email => $name) {
        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.hostinger.com';
            $mail->Port = 587;
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = 'tls';
            $mail->Username = $_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site';
            $mail->Password = $_ENV['SMTP_PASS'] ?? '';
            $mail->Timeout = 30;

            $mail->setFrom($_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site', 'IdentiTrack UPCC');
            $mail->addAddress($email, $name);
            $mail->Subject = "Student Hearing RSVP: Case $caseLabel ($response)";

            $mail->isHTML(true);
            $mail->Body = "
                <div style='font-family:sans-serif; max-width:600px; line-height:1.6; color:#333; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px;'>
                    <h2 style='color:#1e3a8a; margin-top: 0;'>Student Hearing RSVP</h2>
                    <p>Hello <b>" . htmlspecialchars($name) . "</b>,</p>
                    <p>The student has responded to the scheduled hearing for Case <b style='color:#1e3a8a;'>$caseLabel</b>.</p>
                    <div style='background:#f8fafc; padding:20px; border-radius:10px; border: 1px solid #f1f5f9; margin:20px 0;'>
                        <p style='margin:5px 0;'><b>Student:</b> " . htmlspecialchars($studentName) . " ($studentId)</p>
                        <p style='margin:5px 0;'><b>Response:</b> <span style='color:$statusColor; font-weight: bold;'>$response</span></p>
                        <hr style='border: 0; border-top: 1px solid #e2e8f0; margin: 12px 0;' />
                        <p style='margin:5px 0;'><b>Hearing Schedule:</b> $hearingDate at $hearingTime</p>
                        <p style='margin:5px 0;'><b>Hearing Mode:</b> $hearingTypeLabel</p>
                        <p style='margin:5px 0;'><b>Location / Link:</b> $hearingLoc</p>
                    </div>
                    <p>Please log in to your portal dashboard to review the case updates.</p>
                    <p style='margin-top:30px; font-size:12px; color:#94a3b8; border-top: 1px solid #f1f5f9; padding-top: 15px;'>This is an automated notification from IdentiTrack. Please do not reply.</p>
                </div>
            ";
            $mail->send();
        } catch (Exception $e) {
            error_log("Failed to send hearing RSVP email to {$email}: " . $mail->ErrorInfo);
        }
    }
}

json_out(true, 'Hearing response submitted successfully.');
