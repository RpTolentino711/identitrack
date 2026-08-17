<?php
// File: admin/api_send_nte_form.php
require_once __DIR__ . '/../database/database.php';
require_admin();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$admin = admin_current();
$adminName = trim((string)($admin['full_name'] ?? $admin['username'] ?? 'Discipline Officer'));

$caseId = (int)($_POST['case_id'] ?? 0);
$offenseId = (int)($_POST['offense_id'] ?? 0);
$studentId = trim((string)($_POST['student_id'] ?? ''));

if ($caseId <= 0 && $offenseId > 0) {
    $offRow = db_one("SELECT case_id FROM upcc_case_offense WHERE offense_id = :oid LIMIT 1", [':oid' => $offenseId]);
    if (!empty($offRow['case_id'])) {
        $caseId = (int)$offRow['case_id'];
    }
}

if ($studentId === '') {
    echo json_encode(['ok' => false, 'error' => 'Student ID is required.']);
    exit;
}

$irNo = trim((string)($_POST['incident_report_no'] ?? ''));
$alleged = trim((string)($_POST['alleged_details'] ?? ''));
$section = trim((string)($_POST['handbook_section'] ?? ''));
$page = trim((string)($_POST['handbook_page'] ?? ''));
$instructions = trim((string)($_POST['custom_instructions'] ?? ''));
$signature = trim((string)($_POST['admin_signature'] ?? $adminName));

$attachmentPath = null;
$uploadedFileName = null;
if (isset($_FILES['nte_file']) && $_FILES['nte_file']['error'] === UPLOAD_ERR_OK) {
    $fileTmp = $_FILES['nte_file']['tmp_name'];
    $uploadedFileName = basename($_FILES['nte_file']['name']);
    $ext = strtolower(pathinfo($uploadedFileName, PATHINFO_EXTENSION));
    
    $uploadDir = __DIR__ . '/../uploads/nte/';
    if (!is_dir($uploadDir)) {
        @mkdir($uploadDir, 0777, true);
    }
    
    $newFileName = 'nte_' . time() . '_' . uniqid() . '.' . $ext;
    $targetPath = $uploadDir . $newFileName;
    
    if (move_uploaded_file($fileTmp, $targetPath)) {
        $attachmentPath = 'uploads/nte/' . $newFileName;
    }
}

ensure_notice_to_explain_table();

// Save or Replace Form F-005 record for specific case or offense
$existing = null;
if ($caseId > 0) {
    $existing = db_one("SELECT nte_id, case_id, offense_id, attachment_path FROM notice_to_explain WHERE case_id = :cid LIMIT 1", [':cid' => $caseId]);
}
if (!$existing && $offenseId > 0) {
    $existing = db_one("SELECT nte_id, case_id, offense_id, attachment_path FROM notice_to_explain WHERE offense_id = :oid LIMIT 1", [':oid' => $offenseId]);
}

$finalAttachment = $attachmentPath ?: ($existing['attachment_path'] ?? null);

if ($existing) {
    db_exec("
        UPDATE notice_to_explain 
        SET case_id = COALESCE(:cid, case_id),
            offense_id = COALESCE(:oid, offense_id),
            incident_report_no = :ir,
            alleged_details = :alleged,
            handbook_section = :sec,
            handbook_page = :page,
            custom_instructions = :inst,
            admin_signature = :sig,
            attachment_path = :att,
            status = 'SENT',
            updated_at = NOW()
        WHERE nte_id = :nid
    ", [
        ':cid' => $caseId > 0 ? $caseId : null,
        ':oid' => $offenseId > 0 ? $offenseId : null,
        ':ir' => $irNo,
        ':alleged' => $alleged,
        ':sec' => $section,
        ':page' => $page,
        ':inst' => $instructions,
        ':sig' => $signature,
        ':att' => $finalAttachment,
        ':nid' => (int)$existing['nte_id']
    ]);
    $nteId = (int)$existing['nte_id'];
} else {
    db_exec("
        INSERT INTO notice_to_explain (case_id, offense_id, student_id, incident_report_no, alleged_details, handbook_section, handbook_page, custom_instructions, admin_signature, attachment_path, status, created_at, updated_at)
        VALUES (:cid, :oid, :sid, :ir, :alleged, :sec, :page, :inst, :sig, :att, 'SENT', NOW(), NOW())
    ", [
        ':cid' => $caseId > 0 ? $caseId : null,
        ':oid' => $offenseId > 0 ? $offenseId : null,
        ':sid' => $studentId,
        ':ir' => $irNo,
        ':alleged' => $alleged,
        ':sec' => $section,
        ':page' => $page,
        ':inst' => $instructions,
        ':sig' => $signature,
        ':att' => $finalAttachment
    ]);
    $nteId = (int)db_last_id();
}

// ── FETCH STUDENT EMAIL & NAME FOR OUTLOOK EMAIL DIRECT DELIVERY ──────────────
$studentParams = [':sid' => $studentId];
$studentRow = db_one("SELECT student_id, " . db_decrypt_cols(['student_fn', 'student_ln', 'student_email']) . " FROM student WHERE student_id = :sid LIMIT 1", $studentParams);

$studentName  = 'Student';
$studentEmail = '';
if ($studentRow) {
    $studentName  = trim(($studentRow['student_fn'] ?? '') . ' ' . ($studentRow['student_ln'] ?? ''));
    $studentEmail = trim((string)($studentRow['student_email'] ?? ''));
}
if (empty($studentEmail)) {
    $studentEmail = $studentId . '@national-u.edu.ph'; // Default NU Outlook email
}

// ── SEND FORM F-005 FILE ATTACHMENT VIA EMAIL DIRECTLY TO STUDENT'S OUTLOOK ──
$emailSent = false;
$emailError = null;

if (!empty($finalAttachment)) {
    $fullAbsPath = __DIR__ . '/../' . ltrim((string)$finalAttachment, '/');
    if (file_exists($fullAbsPath)) {
        require_once __DIR__ . '/class.phpmailer.php';
        require_once __DIR__ . '/class.smtp.php';

        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();

            $getEnv = function($key, $default) {
                return (string)($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default);
            };

            $mail->Host = $getEnv('SMTP_HOST', 'smtp.hostinger.com');
            $mail->Port = 587;
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = 'tls';
            $mail->Username = $getEnv('SMTP_USER', 'identitrack@identitrack.site');
            $mail->Password = $getEnv('SMTP_PASS', 'Pogilameg@10');

            $mail->setFrom($mail->Username, 'Student Discipline Office - NU Lipa');
            $mail->addAddress($studentEmail, $studentName);
            $mail->addReplyTo('no-reply@identitrack.site', 'IdentiTrack SDO');

            $mail->isHTML(true);
            $mail->Subject = '[NU Lipa SDO] Notice to Explain (Form F-005) - Action Required';

            $logoPath = realpath(__DIR__ . '/../assets/logo.png');
            $hasLogo = ($logoPath && is_readable($logoPath));
            $cid = 'identitracklogo';
            if ($hasLogo) {
                $mail->addEmbeddedImage($logoPath, $cid, 'logo.png');
            }
            $logoSrc = $hasLogo ? "cid:$cid" : "https://identitrack.site/assets/logo.png";

            $mail->Body = "
            <!DOCTYPE html>
            <html lang='en'>
            <head>
              <meta charset='UTF-8'>
              <style>
                body { margin: 0; padding: 0; background-color: #f1f5f9; font-family: 'Inter', -apple-system, sans-serif; }
                .wrapper { width: 100%; table-layout: fixed; background-color: #f1f5f9; padding: 40px 0; }
                .email-container { max-width: 600px; margin: 0 auto; background-color: #ffffff; border-radius: 24px; overflow: hidden; box-shadow: 0 10px 40px rgba(0,0,0,0.08); }
                .header { background-image: linear-gradient(135deg, #1b2b6b 0%, #1e40af 100%); padding: 40px 30px; text-align: center; color: white; }
                .logo-img { display: block; width: 75px; height: auto; margin: 0 auto 15px auto; }
                .content { padding: 36px 40px; color: #334155; font-size: 15px; line-height: 1.6; }
                .notice-box { background-color: #fff8e1; border-left: 4px solid #f59e0b; padding: 16px; border-radius: 8px; margin: 20px 0; font-size: 14px; color: #92400e; }
                .footer { padding: 24px; text-align: center; background-color: #f8fafc; border-top: 1px solid #e2e8f0; font-size: 12px; color: #94a3b8; }
              </style>
            </head>
            <body>
              <div class='wrapper'>
                <div class='email-container'>
                  <div class='header'>
                    <img src='{$logoSrc}' alt='IdentiTrack Logo' class='logo-img'>
                    <h2 style='margin:0;font-size:22px;font-weight:800;'>Student Discipline Office</h2>
                    <p style='margin:4px 0 0 0;font-size:13px;opacity:0.9;'>National University Lipa</p>
                  </div>
                  <div class='content'>
                    <p style='font-size:16px;font-weight:700;color:#0f172a;margin-top:0;'>Dear {$studentName} ({$studentId}),</p>
                    <p>You have been issued an official <strong>Notice to Explain (Form F-005)</strong> by the Student Discipline Office regarding a disciplinary matter.</p>
                    <div class='notice-box'>
                      📄 <strong>Form F-005 Document Attached</strong><br>
                      Please inspect the official Form F-005 file attached to this email for full details of the reported incident and instructions.
                    </div>
                    <p>Per NU Lipa Student Handbook Policy, you are required to submit your written explanation within <strong>five (5) days</strong> upon receipt of this notice.</p>
                    <p>You can submit your written explanation and supporting evidence through the <strong>IdentiTrack Student Mobile App</strong>.</p>
                    <p style='margin-bottom:0;'>Failure to respond within 5 days will be construed as a waiver of your right to be heard.</p>
                  </div>
                  <div class='footer'>
                    &copy; " . date('Y') . " IdentiTrack System - National University Lipa<br>
                    This is an official automated notification sent to your student email.
                  </div>
                </div>
              </div>
            </body>
            </html>
            ";

            $mail->AltBody = "Dear {$studentName} ({$studentId}),\n\nYou have been issued an official Notice to Explain (Form F-005) by the Student Discipline Office (SDO).\n\nPlease inspect the attached official Form F-005 file for details. Per NU Lipa policy, you are required to submit your written explanation within five (5) days upon receipt through the IdentiTrack Student Mobile App.\n\nSincerely,\nStudent Discipline Office";

            $attachName = $uploadedFileName ?: ('Form_F005_Notice_To_Explain_' . $studentId . '.pdf');
            $mail->addAttachment($fullAbsPath, $attachName);

            $mail->send();
            $emailSent = true;
        } catch (\Throwable $e) {
            $emailError = $e->getMessage();
        }
    }
}

// Send Notification to Student in Database
try {
    db_exec("
        INSERT INTO notification (type, title, message, student_id, admin_id, related_table, related_id, is_read, is_deleted, created_at)
        VALUES ('FORM_F005', 'Notice To Explain Issued (Form F-005)', 'Per NU Lipa SDO Policy, an official Form F-005 Notice to Explain has been sent to your student Outlook email. Please check your inbox and submit your written explanation within 5 days.', :sid, :aid, 'notice_to_explain', :nid, 0, 0, NOW())
    ", [
        ':sid' => $studentId,
        ':aid' => (int)($admin['admin_id'] ?? 0),
        ':nid' => (string)$nteId
    ]);
} catch (\Throwable $e) {}

// Log Activity into upcc_case_activity for History Log
if ($caseId > 0) {
    try {
        db_exec("
            INSERT INTO upcc_case_activity (case_id, actor_type, actor_id, action, payload_json, created_at)
            VALUES (:cid, 'ADMIN', :aid, 'FORM_F005_SENT', :json, NOW())
        ", [
            ':cid'  => $caseId,
            ':aid'  => (int)($admin['admin_id'] ?? 0),
            ':json' => json_encode([
                'by' => $adminName,
                'student_email' => $studentEmail,
                'attachment' => $finalAttachment,
                'date_formatted' => date('F j, Y'),
                'time_formatted' => date('h:i:s A')
            ])
        ]);
    } catch (\Throwable $e) {}
}

echo json_encode([
    'ok' => true,
    'nte_id' => $nteId,
    'email_sent' => $emailSent,
    'student_email' => $studentEmail,
    'submitted_at' => date('Y-m-d H:i:s'),
    'formatted_date' => date('F j, Y'),
    'formatted_time' => date('h:i:s A'),
    'attachment_path' => $finalAttachment,
    'message' => 'Notice to Explain (Form F-005) sent to student Outlook email successfully!'
]);
