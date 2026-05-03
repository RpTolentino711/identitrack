<?php
require_once __DIR__ . '/../database/database.php';
require_admin();

$admin = admin_current();
$adminId = (int)($admin['admin_id'] ?? 0);

$requestId = (int)($_GET['request_id'] ?? 0);
if ($requestId <= 0) {
  redirect('pending_requests.php');
}

$r = db_one(
  "SELECT
      r.request_id,
      r.requirement_id,
      r.student_id,
      r.request_type,
      r.login_method,
      r.requested_at,
      r.reason,
      r.status,
      CONCAT(s.student_ln, ', ', s.student_fn) AS student_name,
      csr.task_name
   FROM manual_login_request r
   JOIN student s ON s.student_id = r.student_id
   LEFT JOIN community_service_requirement csr ON csr.requirement_id = r.requirement_id
   WHERE r.request_id = :id
   LIMIT 1",
  [':id' => $requestId]
);

if (!$r) {
  redirect('pending_requests.php');
}

$activeReqs = [];
if ($r['status'] === 'PENDING' && $r['request_type'] === 'LOGIN') {
  $activeReqs = db_all(
    "SELECT requirement_id, task_name FROM community_service_requirement 
     WHERE student_id = :sid AND status = 'ACTIVE'",
    [':sid' => $r['student_id']]
  );
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $notes = trim((string)($_POST['notes'] ?? ''));
  $doPassword = (string)($_POST['do_password'] ?? '');
  $selectedReqId = (int)($_POST['selected_req_id'] ?? 0);
  
  $loginMethod = $r['login_method'];
  $requestType = $r['request_type'];

  if ($r['status'] !== 'PENDING') {
    $errors[] = 'This request is already processed.';
  } else if ($action === 'approve') {
    if (!admin_verify_password($adminId, $doPassword)) {
      $errors[] = 'Invalid SDO password. Approval was not completed.';
    }

    if (empty($errors)) {
      if ($requestType === 'LOGIN') {
        $selectedReqId = (int)$r['requirement_id'];
        if ($selectedReqId <= 0) {
            $firstActive = db_one("SELECT requirement_id FROM community_service_requirement WHERE student_id = :sid AND status = 'ACTIVE' LIMIT 1", [':sid' => $r['student_id']]);
            $selectedReqId = (int)($firstActive['requirement_id'] ?? 0);
        }
        
        if ($selectedReqId <= 0) {
           $errors[] = 'This student has no active tasks to assign.';
        } else {
           $exists = db_one("SELECT session_id FROM community_service_session WHERE requirement_id = :req AND time_out IS NULL LIMIT 1", [':req' => $selectedReqId]);
           if ($exists) {
             $errors[] = 'This student already has an active session.';
           } else {
             db_exec(
               "INSERT INTO community_service_session (requirement_id, time_in, time_out, login_method, validated_by, sdo_notes, created_at, updated_at)
                VALUES (:req, NOW(), NULL, :method, :admin, NULL, NOW(), NOW())",
               [':req' => $selectedReqId, ':admin' => $adminId, ':method' => $loginMethod]
             );
             db_exec(
               "UPDATE manual_login_request SET status='APPROVED', requirement_id=:req, decided_by=:admin, decided_at=NOW(), decision_notes=:notes WHERE request_id=:id",
               [':req' => $selectedReqId, ':admin' => $adminId, ':notes' => $notes, ':id' => $requestId]
             );
             db_exec(
               "INSERT INTO notification (type, title, message, student_id, admin_id, related_table, related_id, is_read, is_deleted, created_at)
                VALUES ('COMMUNITY_LOGIN', 'Service Session Started', :msg, :sid, :aid, 'community_service_session', :rid, 0, 0, NOW())",
               [
                 ':msg' => 'A community service session has been approved and started for ' . (string)$r['student_name'] . '. The timer is now running.',
                 ':sid' => $r['student_id'], ':aid' => $adminId, ':rid' => (string)$requestId,
               ]
             );
             redirect('community_service.php?tab=active&success=login_started&student_name=' . urlencode($r['student_name']));
           }
        }
      } else {
        // LOGOUT: The timer was strictly stopped when the student made the request.
        // We simply acknowledge/approve this request here.
        db_exec(
          "UPDATE manual_login_request SET status='APPROVED', decided_by=:admin, decided_at=NOW(), decision_notes=:notes WHERE request_id=:id",
          [':admin' => $adminId, ':notes' => $notes, ':id' => $requestId]
        );
        check_requirement_completion((int)$r['requirement_id']);
        redirect('community_service.php?tab=history');
      }
    }
  } else if ($action === 'reject') {
    db_exec(
      "UPDATE manual_login_request SET status='REJECTED', decided_by=:admin, decided_at=NOW(), decision_notes=:notes WHERE request_id=:id",
      [':admin' => $adminId, ':notes' => $notes, ':id' => $requestId]
    );
    redirect('community_service.php?tab=pending');
  } else {
    $errors[] = 'Invalid action.';
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Validate Request | SDO Web Portal</title>
  <style>
    * { box-sizing: border-box; }

    body {
      margin: 0;
      font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
      background: #f8f9fa;
      color: #1b2244;
    }

    .admin-shell {
      min-height: calc(100vh - 72px);
      display: grid;
      grid-template-columns: 240px 1fr;
    }

    .wrap { min-height: 100%; padding: 0; }

    /* Page Header */
    .page-header {
      background: #ffffff;
      border-bottom: 1px solid #e0e0e0;
      padding: 28px 32px;
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .back-btn {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      color: #6c757d;
      text-decoration: none;
      font-size: 14px;
      font-weight: 600;
      padding: 6px 12px;
      border-radius: 6px;
      border: 1px solid #dee2e6;
      background: white;
      transition: all 0.2s ease;
    }

    .back-btn:hover {
      color: #3b4a9e;
      border-color: #3b4a9e;
      background: #f0f2ff;
    }

    .page-header-text h1 {
      margin: 0;
      color: #1a1a1a;
      font-size: 28px;
      font-weight: 700;
      letter-spacing: 0.3px;
    }

    .page-header-text .subtitle {
      margin-top: 4px;
      color: #6c757d;
      font-size: 15px;
    }

    /* Content Area */
    .content-area {
      padding: 24px 32px;
      max-width: 860px;
    }

    /* Error Banner */
    .error-banner {
      background: #f8d7da;
      border: 1px solid #f5c2c7;
      border-left: 4px solid #dc3545;
      border-radius: 8px;
      padding: 14px 18px;
      margin-bottom: 24px;
      color: #842029;
      font-size: 14px;
    }

    .error-banner ul { margin: 0; padding-left: 18px; }

    /* Panel */
    .panel {
      background: #fff;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.04);
      overflow: hidden;
      margin-bottom: 20px;
    }

    .panel-header {
      padding: 20px 24px;
      border-bottom: 1px solid #dee2e6;
      background: #fafafa;
      display: flex;
      align-items: center;
      justify-content: space-between;
    }

    .panel-header h2 {
      margin: 0;
      font-size: 16px;
      font-weight: 700;
      color: #1a1a1a;
    }

    .status-badge {
      display: inline-block;
      padding: 4px 12px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .status-pending  { background: #fff3cd; color: #856404; border: 1px solid #ffc107; }
    .status-approved { background: #d1e7dd; color: #0a3622; }
    .status-rejected { background: #f8d7da; color: #842029; }

    .panel-body { padding: 24px; }

    /* Student Block */
    .student-block {
      display: flex;
      align-items: center;
      gap: 16px;
      padding: 20px;
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      margin-bottom: 24px;
    }

    .student-avatar {
      width: 56px;
      height: 56px;
      background: #3b4a9e;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      flex-shrink: 0;
    }

    .student-name { font-size: 18px; font-weight: 700; color: #1a1a1a; margin-bottom: 2px; }
    .student-id   { font-size: 14px; color: #6c757d; }

    /* Info Grid */
    .info-grid {
      display: grid;
      grid-template-columns: repeat(2, 1fr);
      gap: 20px;
      margin-bottom: 24px;
    }

    .info-item { display: flex; flex-direction: column; gap: 4px; }

    .info-label {
      font-size: 12px;
      font-weight: 600;
      color: #6c757d;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .info-value { font-size: 15px; font-weight: 600; color: #1a1a1a; }

    /* Reason Block */
    .reason-block {
      background: #fff3cd;
      border: 1px solid #ffc107;
      border-left: 4px solid #ffc107;
      border-radius: 8px;
      padding: 16px 20px;
    }

    .reason-label {
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      color: #856404;
      margin-bottom: 6px;
    }

    .reason-text { font-size: 14px; color: #856404; line-height: 1.6; }

    /* Processed Banner */
    .processed-banner {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 16px 20px;
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      color: #495057;
      font-size: 15px;
    }

    /* Action Panel */
    .action-panel {
      background: #fff;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.04);
    }

    .action-panel-header {
      padding: 20px 24px;
      border-bottom: 1px solid #dee2e6;
      background: #fafafa;
    }

    .action-panel-header h2 { margin: 0; font-size: 16px; font-weight: 700; color: #1a1a1a; }
    .action-panel-header p  { margin: 4px 0 0; font-size: 13px; color: #6c757d; }

    .action-panel-body { padding: 24px; }

    .field-label {
      display: block;
      font-size: 13px;
      font-weight: 600;
      color: #495057;
      margin-bottom: 8px;
    }

    textarea {
      width: 100%;
      min-height: 100px;
      border: 1px solid #dee2e6;
      border-radius: 6px;
      padding: 12px;
      font-size: 14px;
      font-family: inherit;
      color: #1a1a1a;
      resize: vertical;
      transition: border-color 0.2s;
      outline: none;
    }

    textarea:focus {
      border-color: #3b4a9e;
      box-shadow: 0 0 0 3px rgba(59, 74, 158, 0.1);
    }

    .approve-note {
      font-size: 12px;
      color: #6c757d;
      margin-top: 12px;
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .action-buttons {
      display: flex;
      gap: 12px;
      margin-top: 20px;
      padding-top: 20px;
      border-top: 1px solid #dee2e6;
    }

    /* Shared Button */
    .btn {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      padding: 11px 24px;
      border-radius: 6px;
      border: none;
      cursor: pointer;
      font-weight: 700;
      font-size: 14px;
      transition: all 0.3s ease;
      position: relative;
      overflow: hidden;
    }

    .btn::before {
      content: '';
      position: absolute;
      top: 50%;
      left: 50%;
      width: 0;
      height: 0;
      border-radius: 50%;
      background: rgba(255,255,255,0.2);
      transform: translate(-50%, -50%);
      transition: width 0.5s ease, height 0.5s ease;
    }

    .btn:hover::before { width: 300px; height: 300px; }

    .btn-approve {
      background: #3b4a9e;
      color: white;
    }

    .btn-approve:hover {
      background: #2d3a7e;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(59, 74, 158, 0.4);
    }

    .btn-reject {
      background: white;
      color: #dc3545;
      border: 1px solid #dc3545;
    }

    .btn-reject:hover {
      background: #dc3545;
      color: white;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(220, 53, 69, 0.3);
    }

    /* ─────────────────────────────
       Password Confirmation Modal
    ───────────────────────────── */
    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      background: rgba(0, 0, 0, 0.5);
      backdrop-filter: blur(4px);
      -webkit-backdrop-filter: blur(4px);
      z-index: 9999;
      align-items: center;
      justify-content: center;
    }

    .modal-overlay.open { display: flex; }

    .modal {
      background: #fff;
      border-radius: 14px;
      box-shadow: 0 24px 64px rgba(0, 0, 0, 0.18);
      width: 100%;
      max-width: 400px;
      padding: 36px 32px 28px;
      text-align: center;
      position: relative;
      animation: modal-pop 0.22s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    @keyframes modal-pop {
      from { transform: scale(0.88); opacity: 0; }
      to   { transform: scale(1);    opacity: 1; }
    }

    .modal-close {
      position: absolute;
      top: 14px;
      right: 16px;
      background: none;
      border: none;
      cursor: pointer;
      color: #adb5bd;
      padding: 4px;
      border-radius: 4px;
      display: flex;
      align-items: center;
      transition: color 0.2s;
    }

    .modal-close:hover { color: #495057; }

    .modal-logo {
      width: 80px;
      height: 80px;
      object-fit: contain;
      margin: 0 auto 14px;
      display: block;
    }

    .modal-divider {
      width: 48px;
      height: 3px;
      background: #3b4a9e;
      border-radius: 2px;
      margin: 0 auto 20px;
    }

    .modal h3 {
      margin: 0 0 6px;
      font-size: 18px;
      font-weight: 700;
      color: #1a1a1a;
    }

    .modal-subtitle {
      margin: 0 0 22px;
      font-size: 13px;
      color: #6c757d;
      line-height: 1.6;
    }

    .modal-input-wrap {
      position: relative;
      margin-bottom: 6px;
      text-align: left;
    }

    .modal-input-wrap input {
      width: 100%;
      height: 44px;
      border: 1px solid #dee2e6;
      border-radius: 6px;
      padding: 10px 44px 10px 12px;
      font-size: 14px;
      font-family: inherit;
      color: #1a1a1a;
      outline: none;
      transition: border-color 0.2s, box-shadow 0.2s;
    }

    .modal-input-wrap input:focus {
      border-color: #3b4a9e;
      box-shadow: 0 0 0 3px rgba(59, 74, 158, 0.1);
    }

    .toggle-pw {
      position: absolute;
      right: 12px;
      top: 50%;
      transform: translateY(-50%);
      background: none;
      border: none;
      cursor: pointer;
      color: #adb5bd;
      padding: 0;
      display: flex;
      align-items: center;
      transition: color 0.2s;
    }

    .toggle-pw:hover { color: #495057; }

    .modal-error {
      font-size: 12px;
      color: #dc3545;
      text-align: left;
      min-height: 18px;
      margin-bottom: 16px;
    }

    .modal-actions {
      display: flex;
      gap: 10px;
    }

    .modal-actions .btn {
      flex: 1;
      justify-content: center;
      padding: 11px 16px;
    }

    .btn-cancel {
      background: white;
      color: #6c757d;
      border: 1px solid #dee2e6;
    }

    .btn-cancel:hover {
      background: #f8f9fa;
      border-color: #adb5bd;
      transform: none;
      box-shadow: none;
    }

    /* Responsive */
    @media (max-width: 900px) {
      .admin-shell { grid-template-columns: 1fr; }
      .content-area { padding: 20px 16px; }
      .page-header { padding: 20px 16px; }
      .info-grid { grid-template-columns: 1fr; }
    }

    @media (max-width: 480px) {
      .modal { margin: 16px; padding: 28px 20px 22px; }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>

  <!-- ─── Password Confirmation Modal ─── -->
  <div class="modal-overlay" id="approveModal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle">

      <button class="modal-close" id="modalClose" type="button" aria-label="Close">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="18" y1="6" x2="6" y2="18"></line>
          <line x1="6" y1="6" x2="18" y2="18"></line>
        </svg>
      </button>

      <img src="../assets/logo.png" alt="SDO Logo" class="modal-logo" />
      <div class="modal-divider"></div>

      <h3 id="modalTitle">Confirm Approval</h3>
      <p class="modal-subtitle">Please input your password to approve and start this community service session.</p>

      <div class="modal-input-wrap">
        <input type="password" id="modalPassword" placeholder="Enter your password" autocomplete="current-password" />
        <button type="button" class="toggle-pw" id="togglePw" aria-label="Toggle visibility">
          <svg id="eyeIcon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path>
            <circle cx="12" cy="12" r="3"></circle>
          </svg>
        </button>
      </div>

      <div class="modal-error" id="modalError"></div>

      <div class="modal-actions">
        <button type="button" class="btn btn-cancel" id="modalCancel">Cancel</button>
        <button type="button" class="btn btn-approve" id="modalConfirm">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
            <polyline points="20 6 9 17 4 12"></polyline>
          </svg>
          Confirm
        </button>
      </div>

    </div>
  </div>
  <!-- ─────────────────────────────────── -->

  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="wrap">
      <section class="page-header">
        <a href="community_service.php?tab=pending" class="back-btn">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="15 18 9 12 15 6"></polyline>
          </svg>
          Back
        </a>
        <div class="page-header-text">
          <h1>Review Request: <?php echo e($r['request_type']); ?> (<?php echo e($r['login_method']); ?>)</h1>
          <div class="subtitle">Review and process community service request</div>
        </div>
      </section>

      <div class="content-area">

        <?php if (!empty($errors)): ?>
          <div class="error-banner">
            <ul>
              <?php foreach ($errors as $e): ?><li><?php echo e($e); ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <div class="panel">
          <div class="panel-header">
            <h2>Request Details</h2>
            <?php
              $statusClass = 'status-pending';
              if ($r['status'] === 'APPROVED') $statusClass = 'status-approved';
              elseif ($r['status'] === 'REJECTED') $statusClass = 'status-rejected';
            ?>
            <span class="status-badge <?php echo $statusClass; ?>"><?php echo e((string)$r['status']); ?></span>
          </div>
          <div class="panel-body">

            <div class="student-block">
              <div class="student-avatar">
                <svg width="26" height="26" viewBox="0 0 24 24" fill="currentColor">
                  <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                  <circle cx="12" cy="7" r="4"></circle>
                </svg>
              </div>
              <div>
                <div class="student-name"><?php echo e((string)$r['student_name']); ?></div>
                <div class="student-id"><?php echo e((string)$r['student_id']); ?></div>
              </div>
            </div>

            <div class="info-grid">
              <div class="info-item">
                <span class="info-label">Request Type</span>
                <span class="info-value"><?php echo e($r['request_type'] . ' (' . $r['login_method'] . ')'); ?></span>
              </div>
              <div class="info-item">
                <span class="info-label">Requested At</span>
                <span class="info-value"><?php echo date('F j, Y — g:i A', strtotime((string)$r['requested_at'])); ?></span>
              </div>
            </div>

            <?php if (!empty($r['reason'])): ?>
              <div class="reason-block">
                <div class="reason-label">Student's Reason</div>
                <div class="reason-text"><?php echo e((string)$r['reason']); ?></div>
              </div>
            <?php endif; ?>

          </div>
        </div>

        <?php if ((string)$r['status'] !== 'PENDING'): ?>
          <div class="processed-banner">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"></circle>
              <line x1="12" y1="8" x2="12" y2="12"></line>
              <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
            This request has already been <strong>&nbsp;<?php echo e((string)$r['status']); ?></strong>.
          </div>

        <?php else: ?>
          <div class="action-panel">
            <div class="action-panel-header">
              <h2>SDO Decision</h2>
              <p>Approve to start the student's community service session now, or reject the request.</p>
            </div>
            <div class="action-panel-body">

              <!-- Hidden approve form — submitted by modal confirm -->
              <form method="post" id="approveForm">
                <input type="hidden" name="action" value="approve" />
                <input type="hidden" name="notes" id="approveNotesHidden" />
                <input type="hidden" name="do_password" id="approvePasswordHidden" />
                <input type="hidden" name="selected_req_id" id="approveReqIdHidden" />
              </form>

              <!-- Main form (notes + reject button) -->
              <form method="post" id="mainForm">
                
                <?php if ($r['status'] === 'PENDING' && $r['request_type'] === 'LOGIN'): ?>
                  <?php if (empty($activeReqs)): ?>
                    <p style="color:#dc3545; font-size:13px; margin-bottom:16px; font-weight: 600;">This student has no active requirements. You cannot approve this login.</p>
                  <?php endif; ?>
                <?php endif; ?>
                <label class="field-label" for="notes">
                  SDO Notes <span style="font-weight:400; color:#adb5bd;">(optional)</span>
                </label>
                <textarea id="notes" name="notes" placeholder="Add any notes for this decision..."></textarea>

                <p class="approve-note">
                  <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                    <polyline points="12 6 12 12 16 14"></polyline>
                  </svg>
                  <?php if ($r['request_type'] === 'LOGIN'): ?>
                    Approving will immediately start a session with the current time as Time In. Your SDO password is required to confirm.
                  <?php else: ?>
                    Approving will officially validate the manual logout request. The timer was already stopped at the exact time the request was made. Your SDO password is required to confirm.
                  <?php endif; ?>
                </p>

                <div class="action-buttons">
                  <button type="button" class="btn btn-approve" id="openModalBtn">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                      <polyline points="20 6 9 17 4 12"></polyline>
                    </svg>
                    Approve &amp; Start Session
                  </button>

                  <button class="btn btn-reject" type="submit" name="action" value="reject">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                      <line x1="18" y1="6" x2="6" y2="18"></line>
                      <line x1="6" y1="6" x2="18" y2="18"></line>
                    </svg>
                    Reject Request
                  </button>
                </div>

                <!-- carry notes for reject -->
                <input type="hidden" name="notes" />
              </form>

            </div>
          </div>
        <?php endif; ?>

      </div>
    </main>
  </div>

  <script>
    const overlay    = document.getElementById('approveModal');
    const openBtn    = document.getElementById('openModalBtn');
    const closeBtn   = document.getElementById('modalClose');
    const cancelBtn  = document.getElementById('modalCancel');
    const confirmBtn = document.getElementById('modalConfirm');
    const pwInput    = document.getElementById('modalPassword');
    const modalError = document.getElementById('modalError');
    const togglePw   = document.getElementById('togglePw');
    const eyeIcon    = document.getElementById('eyeIcon');
    const notesField = document.getElementById('notes');

    function openModal() {
      pwInput.value = '';
      modalError.textContent = '';
      overlay.classList.add('open');
      setTimeout(() => pwInput.focus(), 80);
    }

    function closeModal() {
      overlay.classList.remove('open');
      pwInput.value = '';
      modalError.textContent = '';
    }

    if (openBtn)   openBtn.addEventListener('click', openModal);
    if (closeBtn)  closeBtn.addEventListener('click', closeModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeModal);

    overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });
    document.addEventListener('keydown', (e) => {
      if (e.key === 'Escape' && overlay.classList.contains('open')) closeModal();
    });

    // Toggle show/hide password
    if (togglePw) {
      togglePw.addEventListener('click', () => {
        const isText = pwInput.type === 'text';
        pwInput.type = isText ? 'password' : 'text';
        eyeIcon.innerHTML = isText
          ? '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>'
          : '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"></path><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
      });
    }

    // Confirm — inject data into hidden approve form and submit
    if (confirmBtn) {
      confirmBtn.addEventListener('click', () => {
        const reqSelect = document.getElementById('selected_req_id');
        if (reqSelect && reqSelect.value === "") {
          alert('You must select a task to assign.');
          return;
        }

        const pw = pwInput.value.trim();
        if (!pw) {
          modalError.textContent = 'Password is required.';
          pwInput.focus();
          return;
        }
        document.getElementById('approveNotesHidden').value   = notesField ? notesField.value : '';
        document.getElementById('approvePasswordHidden').value = pw;
        const reqHidden = document.getElementById('approveReqIdHidden');
        if (reqHidden && reqSelect) reqHidden.value = reqSelect.value;
        document.getElementById('approveForm').submit();
      });
    }

    // Enter key inside modal password field
    if (pwInput) {
      pwInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') confirmBtn.click();
      });
    }
  </script>
</body>
</html>

