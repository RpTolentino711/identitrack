<?php
// File: admin/sanctions.php
require_once __DIR__ . '/../database/database.php';
require_admin();

$activeSidebar = 'sanctions';
$admin = admin_current();
$fullName = trim((string)($admin['full_name'] ?? ''));
if ($fullName === '') $fullName = (string)($admin['username'] ?? 'User');

// 1. Password Verification gate for entering the page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'verify_page_password') {
    header('Content-Type: application/json');
    $pwd = (string)($_POST['password'] ?? '');
    if ($pwd === '') {
        echo json_encode(['success' => false, 'message' => 'Password is required.']);
        exit;
    }
    if (admin_verify_password($admin['admin_id'], $pwd)) {
        $_SESSION['sanctions_page_verified'] = time();
        echo json_encode(['success' => true]);
        exit;
    } else {
        echo json_encode(['success' => false, 'message' => 'Incorrect password.']);
        exit;
    }
}

$is_verified = isset($_SESSION['sanctions_page_verified']) && (time() - $_SESSION['sanctions_page_verified'] < 900);

// Get current tab (default category 1)
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'cat1';

// Fetch cases only if page is verified
$cases = [];
if ($is_verified) {
    $params = [];
    db_add_encryption_key($params);

    $query = "
      SELECT uc.case_id, uc.student_id, uc.decided_category, uc.probation_until, uc.punishment_details, uc.status AS case_status,
             " . db_decrypt_cols(['student_fn', 'student_ln'], 's') . ",
             s.program, s.section, s.year_level, s.is_active AS student_active,
             csr.requirement_id, csr.status AS req_status, csr.hours_required, csr.task_name, csr.completed_at AS req_completed_at,
             (
               SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, sess.time_in, sess.time_out)/60.0), 0.0)
               FROM community_service_session sess
               WHERE sess.requirement_id = csr.requirement_id AND sess.time_out IS NOT NULL
             ) AS hours_completed
      FROM upcc_case uc
      JOIN student s ON s.student_id = uc.student_id
      LEFT JOIN community_service_requirement csr ON csr.related_case_id = uc.case_id
      WHERE uc.decided_category IS NOT NULL AND uc.decided_category BETWEEN 1 AND 5
      ORDER BY uc.created_at DESC
    ";
    $cases = db_all($query, $params);
}

// Group cases by category tab for display
$cat1_cases = [];
$cat2_cases = [];
$cat3_cases = [];
$cat4_5_cases = [];

foreach ($cases as $c) {
    $cat = (int)$c['decided_category'];
    if ($cat === 1) {
        $cat1_cases[] = $c;
    } elseif ($cat === 2) {
        $cat2_cases[] = $c;
    } elseif ($cat === 3) {
        $cat3_cases[] = $c;
    } elseif ($cat === 4 || $cat === 5) {
        $cat4_5_cases[] = $c;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Sanction Tracker & Management | SDO Web Portal</title>
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

    .wrap {
      min-height: 100%;
      padding: 0;
    }

    /* Page Header */
    .page-header {
      background: #ffffff;
      border-bottom: 1px solid #e0e0e0;
      padding: 28px 32px;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .page-header h1 {
      margin: 0;
      color: #1a1a1a;
      font-size: 28px;
      font-weight: 700;
      letter-spacing: 0.3px;
    }

    .welcome {
      margin-top: 4px;
      color: #6c757d;
      font-size: 15px;
      font-weight: 400;
    }

    /* Content Area */
    .content-area {
      padding: 24px 32px;
      position: relative;
    }

    /* Tabs */
    .tabs {
      display: flex;
      gap: 8px;
      margin-bottom: 24px;
      border-bottom: 2px solid #e0e0e0;
    }

    .tab {
      padding: 12px 24px;
      background: transparent;
      border: none;
      border-bottom: 3px solid transparent;
      color: #6c757d;
      font-weight: 600;
      font-size: 14px;
      cursor: pointer;
      text-decoration: none;
      position: relative;
      margin-bottom: -2px;
    }

    .tab:hover { color: #495057; background: #f8f9fa; }
    .tab.active { color: #3b4a9e; border-bottom-color: #3b4a9e; background: transparent; }

    /* Cards */
    .panel {
      background: #fff;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      padding: 24px;
      box-shadow: 0 2px 4px rgba(0, 0, 0, 0.04);
    }

    .panel h2 {
      margin: 0 0 20px;
      font-size: 20px;
      font-weight: 700;
      color: #1a1a1a;
    }

    .sanction-card {
      background: #f8f9fa;
      border: 1px solid #dee2e6;
      border-radius: 8px;
      padding: 20px;
      margin-bottom: 16px;
      display: flex;
      align-items: center;
      gap: 20px;
      transition: box-shadow 0.2s ease;
    }

    .sanction-card:hover {
      box-shadow: 0 4px 12px rgba(0,0,0,0.05);
    }

    .sanction-avatar {
      width: 56px;
      height: 56px;
      background: #3b4a9e;
      border-radius: 50%;
      display: flex;
      align-items: center;
      justify-content: center;
      color: white;
      font-size: 24px;
      flex-shrink: 0;
    }

    .sanction-avatar.cat-expelled {
      background: #dc3545;
    }

    .sanction-info { flex: 1; }
    .student-name { font-size: 18px; font-weight: 700; color: #1a1a1a; margin-bottom: 4px; }
    .student-meta { font-size: 14px; color: #6c757d; margin-bottom: 12px; }

    .status-badge {
      display: inline-block;
      padding: 4px 10px;
      border-radius: 12px;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
    }

    .status-badge.ongoing {
      background: #fff3cd;
      color: #856404;
    }

    .status-badge.completed {
      background: #d1e7dd;
      color: #0a3622;
    }

    .status-badge.frozen {
      background: #f8d7da;
      color: #842029;
    }

    .sanction-details {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 16px;
      margin-top: 12px;
    }

    .detail-item { font-size: 13px; }
    .detail-label { color: #6c757d; margin-bottom: 2px; }
    .detail-value { color: #1a1a1a; font-weight: 600; }

    .btn-edit {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 8px 16px;
      background: white;
      border: 1px solid #dee2e6;
      border-radius: 6px;
      color: #3b4a9e;
      text-decoration: none;
      font-weight: 600;
      font-size: 14px;
      cursor: pointer;
      transition: all 0.3s ease;
    }

    .btn-edit:hover {
      background: #f8f9fa;
      border-color: #3b4a9e;
      transform: translateY(-1px);
      box-shadow: 0 2px 6px rgba(59, 74, 158, 0.15);
    }

    .empty-state {
      padding: 60px 20px;
      text-align: center;
      color: #6c757d;
      font-size: 15px;
    }

    /* Password Screen Style */
    .lock-screen-wrapper {
      max-width: 420px;
      margin: 80px auto;
      background: #ffffff;
      border: 1px solid #dee2e6;
      border-radius: 12px;
      box-shadow: 0 10px 25px rgba(0,0,0,0.05);
      overflow: hidden;
    }

    .lock-screen-header {
      background: #3b4a9e;
      color: white;
      padding: 24px;
      text-align: center;
    }

    .lock-screen-header svg {
      width: 48px;
      height: 48px;
      margin-bottom: 12px;
    }

    .lock-screen-header h2 {
      margin: 0;
      font-size: 20px;
      font-weight: 700;
    }

    .lock-screen-body {
      padding: 24px;
    }

    .form-group {
      margin-bottom: 18px;
    }

    .form-group label {
      display: block;
      font-weight: 600;
      font-size: 13px;
      color: #495057;
      margin-bottom: 6px;
    }

    .form-group input {
      width: 100%;
      padding: 10px 14px;
      border: 1px solid #ced4da;
      border-radius: 6px;
      font-size: 14px;
      transition: border-color 0.15s ease;
    }

    .form-group input:focus {
      outline: none;
      border-color: #3b4a9e;
    }

    .btn-submit {
      width: 100%;
      padding: 12px;
      background: #3b4a9e;
      color: white;
      border: none;
      border-radius: 6px;
      font-weight: 600;
      font-size: 14px;
      cursor: pointer;
      transition: background 0.2s ease;
    }

    .btn-submit:hover {
      background: #2d3a7e;
    }

    .error-banner {
      background: #f8d7da;
      border: 1px solid #f5c2c7;
      color: #842029;
      padding: 10px 14px;
      border-radius: 6px;
      font-size: 13px;
      margin-bottom: 16px;
      display: none;
    }

    /* Modal Style */
    .modal-overlay {
      position: fixed;
      top: 0;
      left: 0;
      right: 0;
      bottom: 0;
      background: rgba(27, 34, 68, 0.4);
      backdrop-filter: blur(4px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.3s ease;
    }

    .modal-overlay.active {
      opacity: 1;
      pointer-events: auto;
    }

    .modal-container {
      background: #ffffff;
      width: 100%;
      max-width: 500px;
      border-radius: 12px;
      box-shadow: 0 15px 35px rgba(0,0,0,0.15);
      overflow: hidden;
      transform: translateY(-20px);
      transition: transform 0.3s ease;
    }

    .modal-overlay.active .modal-container {
      transform: translateY(0);
    }

    .modal-header {
      background: #f8f9fa;
      padding: 16px 24px;
      border-bottom: 1px solid #dee2e6;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .modal-header h3 {
      margin: 0;
      font-size: 18px;
      color: #1a1a1a;
    }

    .modal-close {
      background: none;
      border: none;
      font-size: 24px;
      color: #6c757d;
      cursor: pointer;
    }

    .modal-body {
      padding: 24px;
    }

    .modal-footer {
      background: #f8f9fa;
      padding: 16px 24px;
      border-top: 1px solid #dee2e6;
      display: flex;
      justify-content: flex-end;
      gap: 12px;
    }

    .btn-secondary {
      padding: 8px 16px;
      background: #e2e8f0;
      border: none;
      color: #475569;
      border-radius: 6px;
      font-weight: 600;
      font-size: 14px;
      cursor: pointer;
    }

    .btn-secondary:hover {
      background: #cbd5e1;
    }

    .step-content {
      display: none;
    }

    .step-content.active {
      display: block;
    }

    .otp-cooldown-text {
      font-size: 12px;
      color: #64748b;
      margin-top: 6px;
    }

    .resend-link {
      color: #3b4a9e;
      text-decoration: underline;
      cursor: pointer;
    }

    @media (max-width: 900px) {
      .admin-shell { grid-template-columns: 1fr; }
      .content-area { padding: 20px 16px; }
      .page-header { padding: 20px 16px; }
      .sanction-card { flex-direction: column; align-items: flex-start; }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>

  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="wrap">
      <!-- Page Header -->
      <section class="page-header">
        <div>
          <h1>Sanction Tracker & Management</h1>
          <div class="welcome">Welcome, <?php echo e($fullName); ?></div>
        </div>
      </section>

      <!-- Content Area -->
      <div class="content-area">

        <?php if (!$is_verified): ?>
          <!-- Password Verification gate -->
          <div class="lock-screen-wrapper">
            <div class="lock-screen-header">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
              </svg>
              <h2>Security Verification Required</h2>
              <div style="font-size: 13px; opacity: 0.8; margin-top: 4px;">Please enter your password to access the Sanction panel.</div>
            </div>
            <div class="lock-screen-body">
              <div class="error-banner" id="pagePasswordError"></div>
              <form id="pagePasswordForm">
                <div class="form-group">
                  <label for="pagePassword">Admin Password</label>
                  <input type="password" id="pagePassword" required placeholder="••••••••" />
                </div>
                <button type="submit" class="btn-submit">Verify Identity</button>
              </form>
            </div>
          </div>

        <?php else: ?>
          <!-- Verified Panel Content -->
          <!-- Tabs -->
          <div class="tabs">
            <a href="?tab=cat1" class="tab <?php echo $tab === 'cat1' ? 'active' : ''; ?>">
              Category 1 (Probation)
            </a>
            <a href="?tab=cat2" class="tab <?php echo $tab === 'cat2' ? 'active' : ''; ?>">
              Category 2 (Community Service)
            </a>
            <a href="?tab=cat3" class="tab <?php echo $tab === 'cat3' ? 'active' : ''; ?>">
              Category 3 (Non-Readmission)
            </a>
            <a href="?tab=cat4_5" class="tab <?php echo $tab === 'cat4_5' ? 'active' : ''; ?>">
              Category 4 & 5 (Exclusion/Expulsion)
            </a>
          </div>

          <!-- Tab Content Panels -->
          <?php if ($tab === 'cat1'): ?>
            <section class="panel">
              <h2>Students Under Category 1 (Suspension / Probation)</h2>
              <?php if (empty($cat1_cases)): ?>
                <div class="empty-state">No students currently in this category.</div>
              <?php else: ?>
                <?php foreach ($cat1_cases as $c): ?>
                  <?php 
                    $student_name = trim(($c['student_fn'] ?? '') . ' ' . ($c['student_ln'] ?? ''));
                    if ($student_name === '') $student_name = $c['student_id'];
                    
                    $is_ongoing = empty($c['probation_until']) || (strtotime($c['probation_until']) > time());
                  ?>
                  <div class="sanction-card">
                    <div class="sanction-avatar">
                      <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                      </svg>
                    </div>
                    <div class="sanction-info">
                      <div class="student-name"><?php echo e($student_name); ?></div>
                      <div class="student-meta">
                        ID: <?php echo e($c['student_id']); ?> • <?php echo e($c['program']); ?> Sec. <?php echo e($c['section']); ?> (<?php echo e($c['year_level']); ?> Year)
                      </div>
                      <div>
                        <?php if ($is_ongoing): ?>
                          <span class="status-badge ongoing">Ongoing Probation</span>
                        <?php else: ?>
                          <span class="status-badge completed">Completed</span>
                        <?php endif; ?>
                      </div>
                      <div class="sanction-details">
                        <div class="detail-item">
                          <div class="detail-label">Probation End Date</div>
                          <div class="detail-value">
                            <?php echo !empty($c['probation_until']) ? date('F j, Y g:i A', strtotime($c['probation_until'])) : 'No date set'; ?>
                          </div>
                        </div>
                        <div class="detail-item">
                          <div class="detail-label">Case Status</div>
                          <div class="detail-value"><?php echo e(str_replace('_', ' ', $c['case_status'])); ?></div>
                        </div>
                      </div>
                    </div>
                    <div>
                      <button class="btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                        'case_id' => $c['case_id'],
                        'student_id' => $c['student_id'],
                        'student_name' => $student_name,
                        'category' => 1,
                        'probation_until' => !empty($c['probation_until']) ? date('Y-m-d', strtotime($c['probation_until'])) : '',
                        'hours' => 0
                      ])); ?>)">
                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" style="vertical-align:middle;margin-right:4px;">
                          <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                          <path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                        Edit Sanction
                      </button>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </section>

          <?php elseif ($tab === 'cat2'): ?>
            <section class="panel">
              <h2>Students Under Category 2 (Formative Intervention / Community Service)</h2>
              <?php if (empty($cat2_cases)): ?>
                <div class="empty-state">No students currently in this category.</div>
              <?php else: ?>
                <?php foreach ($cat2_cases as $c): ?>
                  <?php 
                    $student_name = trim(($c['student_fn'] ?? '') . ' ' . ($c['student_ln'] ?? ''));
                    if ($student_name === '') $student_name = $c['student_id'];
                    
                    // Determine ongoing/completed based on requirement status
                    $is_ongoing = $c['req_status'] !== 'COMPLETED';
                    $hours_comp = (float)$c['hours_completed'];
                    $hours_req = (float)$c['hours_required'];
                    $hours_rem = max(0.0, $hours_req - $hours_comp);
                  ?>
                  <div class="sanction-card">
                    <div class="sanction-avatar">
                      <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                      </svg>
                    </div>
                    <div class="sanction-info">
                      <div class="student-name"><?php echo e($student_name); ?></div>
                      <div class="student-meta">
                        ID: <?php echo e($c['student_id']); ?> • <?php echo e($c['program']); ?> Sec. <?php echo e($c['section']); ?> (<?php echo e($c['year_level']); ?> Year)
                      </div>
                      <div>
                        <?php if (!$is_ongoing): ?>
                          <span class="status-badge completed">Completed</span>
                        <?php else: ?>
                          <span class="status-badge ongoing">Ongoing (<?php echo number_format($hours_rem, 1); ?> hrs remaining)</span>
                        <?php endif; ?>
                      </div>
                      <div class="sanction-details">
                        <div class="detail-item">
                          <div class="detail-label">Service Task</div>
                          <div class="detail-value"><?php echo e($c['task_name'] ?: 'University Service'); ?></div>
                        </div>
                        <div class="detail-item">
                          <div class="detail-label">Completed Hours</div>
                          <div class="detail-value"><?php echo number_format($hours_comp, 1); ?> / <?php echo number_format($hours_req, 1); ?> hrs</div>
                        </div>
                        <div class="detail-item">
                          <div class="detail-label">Completed Date</div>
                          <div class="detail-value">
                            <?php echo !empty($c['req_completed_at']) ? date('F j, Y g:i A', strtotime($c['req_completed_at'])) : ($is_ongoing ? 'N/A' : 'Completed'); ?>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div>
                      <button class="btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                        'case_id' => $c['case_id'],
                        'student_id' => $c['student_id'],
                        'student_name' => $student_name,
                        'category' => 2,
                        'probation_until' => '',
                        'hours' => $hours_req
                      ])); ?>)">
                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" style="vertical-align:middle;margin-right:4px;">
                          <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                          <path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                        Edit Sanction
                      </button>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </section>

          <?php elseif ($tab === 'cat3'): ?>
            <section class="panel">
              <h2>Students Under Category 3 (Non-Readmission Next Semester)</h2>
              <?php if (empty($cat3_cases)): ?>
                <div class="empty-state">No students currently in this category.</div>
              <?php else: ?>
                <?php foreach ($cat3_cases as $c): ?>
                  <?php 
                    $student_name = trim(($c['student_fn'] ?? '') . ' ' . ($c['student_ln'] ?? ''));
                    if ($student_name === '') $student_name = $c['student_id'];
                  ?>
                  <div class="sanction-card">
                    <div class="sanction-avatar">
                      <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                      </svg>
                    </div>
                    <div class="sanction-info">
                      <div class="student-name"><?php echo e($student_name); ?></div>
                      <div class="student-meta">
                        ID: <?php echo e($c['student_id']); ?> • <?php echo e($c['program']); ?> Sec. <?php echo e($c['section']); ?> (<?php echo e($c['year_level']); ?> Year)
                      </div>
                      <div>
                        <span class="status-badge ongoing">Active Warning (Non-Readmission)</span>
                      </div>
                      <div class="sanction-details">
                        <div class="detail-item" style="grid-column: span 2;">
                          <div class="detail-label">Punishment Status Note</div>
                          <div class="detail-value" style="color: #dc3545;">Account will be blocked from readmission next semester.</div>
                        </div>
                      </div>
                    </div>
                    <div>
                      <button class="btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                        'case_id' => $c['case_id'],
                        'student_id' => $c['student_id'],
                        'student_name' => $student_name,
                        'category' => 3,
                        'probation_until' => '',
                        'hours' => 0
                      ])); ?>)">
                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" style="vertical-align:middle;margin-right:4px;">
                          <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                          <path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                        Edit Sanction
                      </button>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </section>

          <?php else: ?>
            <section class="panel">
              <h2>Students Under Category 4 (Exclusion) & Category 5 (Expulsion)</h2>
              <?php if (empty($cat4_5_cases)): ?>
                <div class="empty-state">No students currently in these categories.</div>
              <?php else: ?>
                <?php foreach ($cat4_5_cases as $c): ?>
                  <?php 
                    $student_name = trim(($c['student_fn'] ?? '') . ' ' . ($c['student_ln'] ?? ''));
                    if ($student_name === '') $student_name = $c['student_id'];
                    $is_expulsion = ((int)$c['decided_category'] === 5);
                    $is_active = (bool)$c['student_active'];
                  ?>
                  <div class="sanction-card">
                    <div class="sanction-avatar cat-expelled">
                      <svg viewBox="0 0 24 24" width="28" height="28" fill="currentColor">
                        <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                        <circle cx="12" cy="7" r="4"></circle>
                      </svg>
                    </div>
                    <div class="sanction-info">
                      <div class="student-name"><?php echo e($student_name); ?></div>
                      <div class="student-meta">
                        ID: <?php echo e($c['student_id']); ?> • <?php echo e($c['program']); ?> Sec. <?php echo e($c['section']); ?> (<?php echo e($c['year_level']); ?> Year)
                      </div>
                      <div>
                        <?php if ($is_expulsion): ?>
                          <span class="status-badge frozen">Expulsion (Category 5)</span>
                        <?php else: ?>
                          <span class="status-badge frozen">Exclusion (Category 4)</span>
                        <?php endif; ?>
                        
                        <?php if (!$is_active): ?>
                          <span class="status-badge frozen" style="margin-left: 8px;">Account Frozen</span>
                        <?php else: ?>
                          <span class="status-badge completed" style="margin-left: 8px;">Account Active</span>
                        <?php endif; ?>
                      </div>
                      <div class="sanction-details">
                        <div class="detail-item" style="grid-column: span 2;">
                          <div class="detail-label">Punishment Status Note</div>
                          <div class="detail-value" style="color: #dc3545;">
                            <?php echo $is_expulsion ? 'Expelled from the University. Registration and login disabled.' : 'Excluded from the University. Registration and login disabled.'; ?>
                          </div>
                        </div>
                      </div>
                    </div>
                    <div>
                      <button class="btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                        'case_id' => $c['case_id'],
                        'student_id' => $c['student_id'],
                        'student_name' => $student_name,
                        'category' => (int)$c['decided_category'],
                        'probation_until' => '',
                        'hours' => 0
                      ])); ?>)">
                        <svg viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none" style="vertical-align:middle;margin-right:4px;">
                          <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                          <path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                        </svg>
                        Edit Sanction
                      </button>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </section>
          <?php endif; ?>
        <?php endif; ?>

      </div>
    </main>
  </div>

  <!-- Edit Sanction Modal -->
  <div class="modal-overlay" id="editModalOverlay">
    <div class="modal-container">
      <div class="modal-header">
        <h3 id="modalTitle">Edit Sanction</h3>
        <button class="modal-close" onclick="closeEditModal()">&times;</button>
      </div>
      <div class="modal-body">
        <!-- Step 1: Edit Form -->
        <div class="step-content active" id="stepForm">
          <form id="sanctionEditForm">
            <input type="hidden" id="editCaseId" />
            <input type="hidden" id="editStudentId" />

            <div class="form-group">
              <label>Student Name</label>
              <input type="text" id="editStudentName" readonly style="background:#e9ecef; border-color:#dee2e6;" />
            </div>

            <div class="form-group">
              <label for="editCategory">Sanction Category</label>
              <select id="editCategory" class="form-group input" style="width:100%;" onchange="toggleCategoryFields()">
                <option value="1">Category 1 - Probation / Suspension</option>
                <option value="2">Category 2 - Community Service / Formative Intervention</option>
                <option value="3">Category 3 - Non-Readmission Warning</option>
                <option value="4">Category 4 - Exclusion</option>
                <option value="5">Category 5 - Expulsion</option>
              </select>
            </div>

            <!-- Category 1 Field -->
            <div class="form-group" id="groupProbation" style="display:none;">
              <label for="editProbationUntil">Probation Until Date</label>
              <input type="date" id="editProbationUntil" />
            </div>

            <!-- Category 2 Field -->
            <div class="form-group" id="groupHours" style="display:none;">
              <label for="editHours">Required Service Hours</label>
              <input type="number" step="0.5" min="0.5" id="editHours" placeholder="e.g. 40" />
            </div>

            <button type="button" class="btn-submit" onclick="goToVerification()">Continue to Verification</button>
          </form>
        </div>

        <!-- Step 2: Password and OTP Verification -->
        <div class="step-content" id="stepVerify">
          <div class="error-banner" id="verifyError"></div>
          <div style="font-size: 14px; margin-bottom: 18px; color: #475569;">
            Updating a student's sanction is a restricted administrative action. Please verify your password and the OTP sent to your admin email.
          </div>

          <form id="sanctionVerifyForm">
            <div class="form-group">
              <label for="verifyPassword">Admin Password</label>
              <input type="password" id="verifyPassword" required placeholder="Confirm admin password" />
            </div>

            <div class="form-group">
              <label for="verifyOTP">One-Time Password (OTP)</label>
              <div style="display:flex; gap:8px;">
                <input type="text" id="verifyOTP" required maxlength="6" pattern="\d{6}" placeholder="6-digit code" style="flex:1;" />
                <button type="button" class="btn-secondary" id="btnResendOTP" onclick="requestOTP()">Send OTP</button>
              </div>
              <div id="otpCooldownText" class="otp-cooldown-text"></div>
            </div>

            <button type="submit" class="btn-submit">Confirm and Apply Changes</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <script>
    // 1. Password Verification gate handling
    const pagePasswordForm = document.getElementById('pagePasswordForm');
    if (pagePasswordForm) {
      pagePasswordForm.addEventListener('submit', function(e) {
        e.preventDefault();
        const pwd = document.getElementById('pagePassword').value;
        const errBanner = document.getElementById('pagePasswordError');
        errBanner.style.display = 'none';

        fetch('sanctions.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
          body: 'action=verify_page_password&password=' + encodeURIComponent(pwd)
        })
        .then(res => res.json())
        .then(data => {
          if (data.success) {
            location.reload();
          } else {
            errBanner.textContent = data.message;
            errBanner.style.display = 'block';
          }
        })
        .catch(err => {
          errBanner.textContent = 'A connection error occurred. Please try again.';
          errBanner.style.display = 'block';
        });
      });
    }

    // Modal Control functions
    const modalOverlay = document.getElementById('editModalOverlay');
    const stepForm = document.getElementById('stepForm');
    const stepVerify = document.getElementById('stepVerify');

    let otpCooldownInterval = null;
    let otpCooldownSeconds = 0;

    function openEditModal(data) {
      document.getElementById('editCaseId').value = data.case_id;
      document.getElementById('editStudentId').value = data.student_id;
      document.getElementById('editStudentName').value = data.student_name;
      document.getElementById('editCategory').value = data.category;
      document.getElementById('editProbationUntil').value = data.probation_until;
      document.getElementById('editHours').value = data.hours || '';

      toggleCategoryFields();

      // Reset steps
      stepForm.classList.add('active');
      stepVerify.classList.remove('active');
      document.getElementById('verifyError').style.display = 'none';
      document.getElementById('verifyPassword').value = '';
      document.getElementById('verifyOTP').value = '';

      modalOverlay.classList.add('active');
    }

    function closeEditModal() {
      modalOverlay.classList.remove('active');
      if (otpCooldownInterval) {
        clearInterval(otpCooldownInterval);
      }
    }

    function toggleCategoryFields() {
      const cat = parseInt(document.getElementById('editCategory').value);
      const groupProbation = document.getElementById('groupProbation');
      const groupHours = document.getElementById('groupHours');

      groupProbation.style.display = (cat === 1) ? 'block' : 'none';
      groupHours.style.display = (cat === 2) ? 'block' : 'none';
    }

    function goToVerification() {
      const cat = parseInt(document.getElementById('editCategory').value);
      if (cat === 1) {
        const prob = document.getElementById('editProbationUntil').value;
        if (!prob) {
          alert('Please select a probation end date.');
          return;
        }
      } else if (cat === 2) {
        const hours = parseFloat(document.getElementById('editHours').value);
        if (isNaN(hours) || hours <= 0) {
          alert('Please enter a valid number of service hours.');
          return;
        }
      }

      // Switch views
      stepForm.classList.remove('active');
      stepVerify.classList.add('active');

      // Trigger automatic OTP request
      requestOTP();
    }

    function requestOTP() {
      const btn = document.getElementById('btnResendOTP');
      if (otpCooldownSeconds > 0) return;

      btn.disabled = true;
      btn.textContent = 'Sending...';

      fetch('send_otp_mail.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=edit_sanction'
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          startOTPCooldown(60);
          alert('A 6-digit OTP code has been sent to your registered admin email.');
        } else {
          btn.disabled = false;
          btn.textContent = 'Send OTP';
          alert('Failed to send OTP: ' + data.message);
        }
      })
      .catch(err => {
        btn.disabled = false;
        btn.textContent = 'Send OTP';
        alert('A connection error occurred while sending the OTP.');
      });
    }

    function startOTPCooldown(seconds) {
      otpCooldownSeconds = seconds;
      const btn = document.getElementById('btnResendOTP');
      const textEl = document.getElementById('otpCooldownText');

      if (otpCooldownInterval) clearInterval(otpCooldownInterval);

      btn.disabled = true;
      btn.textContent = `Resend OTP (${otpCooldownSeconds}s)`;

      otpCooldownInterval = setInterval(() => {
        otpCooldownSeconds--;
        if (otpCooldownSeconds <= 0) {
          clearInterval(otpCooldownInterval);
          btn.disabled = false;
          btn.textContent = 'Send OTP';
          textEl.textContent = '';
        } else {
          btn.textContent = `Resend OTP (${otpCooldownSeconds}s)`;
        }
      }, 1000);
    }

    // Submit verify form
    document.getElementById('sanctionVerifyForm').addEventListener('submit', function(e) {
      e.preventDefault();
      const errBanner = document.getElementById('verifyError');
      errBanner.style.display = 'none';

      const caseId = document.getElementById('editCaseId').value;
      const studentId = document.getElementById('editStudentId').value;
      const category = document.getElementById('editCategory').value;
      const probation_until = document.getElementById('editProbationUntil').value;
      const hours = document.getElementById('editHours').value;
      const password = document.getElementById('verifyPassword').value;
      const otp = document.getElementById('verifyOTP').value;

      const params = new URLSearchParams();
      params.append('case_id', caseId);
      params.append('student_id', studentId);
      params.append('category', category);
      params.append('probation_until', probation_until);
      params.append('hours', hours);
      params.append('password', password);
      params.append('otp', otp);

      fetch('AJAX/update_student_sanction.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
      })
      .then(res => res.json())
      .then(data => {
        if (data.success) {
          alert('Sanction updated successfully.');
          closeEditModal();
          location.reload();
        } else {
          errBanner.textContent = data.message;
          errBanner.style.display = 'block';
        }
      })
      .catch(err => {
        errBanner.textContent = 'A connection error occurred. Please try again.';
        errBanner.style.display = 'block';
      });
    });
  </script>
</body>
</html>
