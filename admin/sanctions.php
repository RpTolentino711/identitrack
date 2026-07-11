<?php
// File: admin/sanctions.php
require_once __DIR__ . '/../database/database.php';
require_admin();

function formatHoursMinutes(float $decimalHours): string {
    $hours = (int)floor($decimalHours);
    $minutes = (int)round(($decimalHours - $hours) * 60);
    if ($minutes >= 60) {
        $hours += 1;
        $minutes -= 60;
    }
    $parts = [];
    if ($hours > 0) {
        $parts[] = "{$hours} hr" . ($hours > 1 ? 's' : '');
    }
    if ($minutes > 0 || empty($parts)) {
        $parts[] = "{$minutes} min" . ($minutes > 1 ? 's' : '');
    }
    return implode(' ', $parts);
}

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
  
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

  <style>
    * {
      box-sizing: border-box;
    }

    body {
      margin: 0;
      font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      background: #f4f6fa;
      color: #1e293b;
    }

    /* Shell & Main wrap */
    .admin-shell {
      min-height: calc(100vh - 72px);
      display: grid;
      grid-template-columns: 240px 1fr;
      background: #f4f6fa;
    }

    .wrap {
      min-height: 100%;
      padding: 0;
    }

    /* Page Header Card */
    .page-header {
      background: linear-gradient(135deg, #1a2244 0%, #2b377f 100%);
      padding: 40px;
      color: #ffffff;
      border-bottom: 1px solid rgba(255, 255, 255, 0.1);
      box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
      position: relative;
      overflow: hidden;
    }

    .page-header::before {
      content: '';
      position: absolute;
      top: -50%;
      right: -20%;
      width: 400px;
      height: 400px;
      background: radial-gradient(circle, rgba(255, 184, 28, 0.1) 0%, rgba(255, 184, 28, 0) 70%);
      border-radius: 50%;
      pointer-events: none;
    }

    .page-header h1 {
      margin: 0;
      font-family: 'Montserrat', sans-serif;
      font-size: 30px;
      font-weight: 800;
      letter-spacing: -0.5px;
    }

    .welcome {
      margin-top: 8px;
      color: #c7d2fe;
      font-size: 15px;
      font-weight: 500;
      opacity: 0.9;
    }

    /* Content Area spacing */
    .content-area {
      padding: 32px 40px;
      max-width: 1400px;
      margin: 0 auto;
    }

    /* Tabs: Pill container */
    .tabs-container {
      background: #ffffff;
      padding: 6px;
      border-radius: 16px;
      display: inline-flex;
      gap: 4px;
      margin-bottom: 32px;
      box-shadow: 0 4px 12px rgba(15, 23, 42, 0.03);
      border: 1px solid #e2e8f0;
      width: 100%;
      max-width: fit-content;
    }

    .tab-pill {
      padding: 12px 24px;
      border-radius: 12px;
      color: #64748b;
      font-weight: 600;
      font-size: 14px;
      text-decoration: none;
      transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .tab-pill svg {
      width: 18px;
      height: 18px;
      stroke: currentColor;
      fill: none;
      stroke-width: 2;
      transition: transform 0.2s ease;
    }

    .tab-pill:hover {
      color: #3b4aa6;
      background: #f8fafc;
    }

    .tab-pill:hover svg {
      transform: translateY(-1px);
    }

    .tab-pill.active {
      background: #3b4aa6;
      color: #ffffff;
      box-shadow: 0 4px 14px rgba(59, 74, 166, 0.25);
    }

    /* Panels */
    .panel-card {
      background: #ffffff;
      border-radius: 20px;
      padding: 32px;
      box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04);
      border: 1px solid #e2e8f0;
    }

    .panel-card h2 {
      margin: 0 0 28px;
      font-family: 'Montserrat', sans-serif;
      font-size: 20px;
      font-weight: 700;
      color: #0f172a;
      display: flex;
      align-items: center;
      gap: 10px;
    }

    /* Sanction Cards list */
    .sanctions-list {
      display: grid;
      grid-template-columns: 1fr;
      gap: 20px;
    }

    .sanction-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 16px;
      padding: 24px;
      display: grid;
      grid-template-columns: 1fr 1.2fr auto;
      align-items: center;
      gap: 24px;
      transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      position: relative;
      overflow: hidden;
    }

    .sanction-card::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      width: 5px;
      height: 100%;
    }

    /* Colors by Tab/Category */
    .sanction-card.cat-1::before { background: #f59e0b; }
    .sanction-card.cat-2::before { background: #10b981; }
    .sanction-card.cat-3::before { background: #ef4444; }
    .sanction-card.cat-4::before, .sanction-card.cat-5::before { background: #7f1d1d; }

    .sanction-card:hover {
      transform: translateY(-2px);
      box-shadow: 0 12px 24px rgba(15, 23, 42, 0.06);
      border-color: #cbd5e1;
    }

    /* Left Section: Student Profile */
    .sanction-card-left {
      display: flex;
      align-items: center;
      gap: 16px;
    }

    .sanction-avatar-wrap {
      width: 48px;
      height: 48px;
      border-radius: 12px;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .cat-1 .sanction-avatar-wrap { background: #fffbeb; color: #d97706; }
    .cat-2 .sanction-avatar-wrap { background: #ecfdf5; color: #059669; }
    .cat-3 .sanction-avatar-wrap { background: #fef2f2; color: #dc2626; }
    .cat-4 .sanction-avatar-wrap, .cat-5 .sanction-avatar-wrap { background: #fef2f2; color: #b91c1c; }

    .sanction-avatar-wrap svg {
      width: 24px;
      height: 24px;
    }

    .student-info-section {
      display: flex;
      flex-direction: column;
      gap: 6px;
    }

    .student-name {
      margin: 0;
      font-size: 16px;
      font-weight: 700;
      color: #0f172a;
    }

    .student-badges {
      display: flex;
      flex-wrap: wrap;
      gap: 6px;
    }

    .meta-pill {
      font-size: 11px;
      font-weight: 600;
      padding: 3px 8px;
      border-radius: 6px;
      background: #f1f5f9;
      color: #475569;
    }

    .id-pill {
      background: #e2e8f0;
      color: #334155;
      font-family: monospace;
    }

    /* Middle Section: Details */
    .sanction-card-middle {
      display: flex;
      flex-direction: column;
      gap: 12px;
    }

    .status-badge-container {
      display: flex;
      align-items: center;
    }

    .status-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 4px 10px;
      border-radius: 20px;
      font-size: 11px;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }

    .status-badge.ongoing {
      background: #fffbeb;
      color: #b45309;
    }

    .status-badge.completed {
      background: #ecfdf5;
      color: #047857;
    }

    .status-badge.frozen {
      background: #fef2f2;
      color: #b91c1c;
    }

    .status-badge::before {
      content: '';
      width: 6px;
      height: 6px;
      border-radius: 50%;
      background: currentColor;
    }

    .details-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(130px, 1fr));
      gap: 16px;
    }

    .detail-item {
      display: flex;
      flex-direction: column;
      gap: 4px;
    }

    .detail-label {
      font-size: 10px;
      font-weight: 700;
      text-transform: uppercase;
      color: #94a3b8;
      letter-spacing: 0.5px;
    }

    .detail-value {
      font-size: 13px;
      font-weight: 600;
      color: #334155;
    }

    /* Right Section: Actions */
    .btn-edit {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      padding: 10px 18px;
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 10px;
      color: #3b4aa6;
      font-weight: 600;
      font-size: 13px;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .btn-edit:hover {
      background: #f8fafc;
      border-color: #3b4aa6;
      color: #2b377f;
      box-shadow: 0 4px 12px rgba(59, 74, 166, 0.08);
    }

    .btn-edit svg {
      width: 14px;
      height: 14px;
    }

    .empty-state {
      padding: 64px 24px;
      text-align: center;
      color: #94a3b8;
      font-size: 15px;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 12px;
    }

    .empty-state svg {
      width: 48px;
      height: 48px;
      color: #cbd5e1;
    }

    /* Security verification check points (Password gate) */
    .lock-screen-wrapper {
      max-width: 460px;
      margin: 60px auto;
      background: #ffffff;
      border-radius: 24px;
      box-shadow: 0 20px 40px rgba(15, 23, 42, 0.08);
      overflow: hidden;
      border: 1px solid #e2e8f0;
      animation: fadeIn 0.4s ease-out;
    }

    .lock-screen-header {
      background: linear-gradient(135deg, #1a2244 0%, #2b377f 100%);
      color: #ffffff;
      padding: 40px 32px 32px;
      text-align: center;
      position: relative;
    }

    .lock-screen-header-icon {
      width: 64px;
      height: 64px;
      background: rgba(255, 255, 255, 0.1);
      border-radius: 20px;
      display: flex;
      align-items: center;
      justify-content: center;
      margin: 0 auto 16px;
      border: 1px solid rgba(255, 255, 255, 0.2);
      color: #ffffff;
    }

    .lock-screen-header-icon svg {
      width: 32px;
      height: 32px;
    }

    .lock-screen-header h2 {
      margin: 0;
      font-family: 'Montserrat', sans-serif;
      font-size: 20px;
      font-weight: 700;
    }

    .lock-screen-body {
      padding: 32px;
    }

    .form-group {
      margin-bottom: 20px;
    }

    .form-group label {
      display: block;
      font-weight: 600;
      font-size: 13px;
      color: #475569;
      margin-bottom: 8px;
    }

    .form-group input, .form-group select {
      width: 100%;
      padding: 12px 16px;
      border: 1px solid #cbd5e1;
      border-radius: 10px;
      font-size: 14px;
      font-family: inherit;
      color: #0f172a;
      background: #ffffff;
      transition: all 0.2s ease;
    }

    .form-group input:focus, .form-group select:focus {
      outline: none;
      border-color: #3b4aa6;
      box-shadow: 0 0 0 3px rgba(59, 74, 166, 0.15);
    }

    .btn-submit {
      width: 100%;
      padding: 14px;
      background: #3b4aa6;
      color: white;
      border: none;
      border-radius: 10px;
      font-weight: 700;
      font-size: 14px;
      cursor: pointer;
      transition: all 0.2s ease;
      box-shadow: 0 4px 12px rgba(59, 74, 166, 0.2);
    }

    .btn-submit:hover {
      background: #2b377f;
      box-shadow: 0 4px 16px rgba(59, 74, 166, 0.3);
    }

    .error-banner {
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #b91c1c;
      padding: 12px 16px;
      border-radius: 10px;
      font-size: 13px;
      font-weight: 600;
      margin-bottom: 20px;
      display: none;
      animation: shake 0.3s ease-in-out;
    }

    /* Modals */
    .modal-overlay {
      position: fixed;
      top: 0; left: 0; right: 0; bottom: 0;
      background: rgba(15, 23, 42, 0.4);
      backdrop-filter: blur(8px);
      display: flex;
      align-items: center;
      justify-content: center;
      z-index: 1000;
      opacity: 0;
      pointer-events: none;
      transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    }

    .modal-overlay.active {
      opacity: 1;
      pointer-events: auto;
    }

    .modal-container {
      background: #ffffff;
      width: 100%;
      max-width: 520px;
      border-radius: 24px;
      box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.15);
      overflow: hidden;
      border: 1px solid #e2e8f0;
      transform: translateY(20px) scale(0.98);
      transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .modal-overlay.active .modal-container {
      transform: translateY(0) scale(1);
    }

    .modal-header {
      background: #f8fafc;
      padding: 20px 28px;
      border-bottom: 1px solid #e2e8f0;
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .modal-header h3 {
      margin: 0;
      font-family: 'Montserrat', sans-serif;
      font-size: 18px;
      color: #0f172a;
      font-weight: 700;
    }

    .modal-close {
      background: none;
      border: none;
      font-size: 24px;
      color: #94a3b8;
      cursor: pointer;
      transition: color 0.15s ease;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .modal-close:hover {
      color: #475569;
    }

    .modal-body {
      padding: 28px;
    }

    .btn-secondary {
      padding: 10px 18px;
      background: #f1f5f9;
      border: 1px solid #cbd5e1;
      color: #475569;
      border-radius: 10px;
      font-weight: 600;
      font-size: 13px;
      cursor: pointer;
      transition: all 0.2s ease;
    }

    .btn-secondary:hover {
      background: #e2e8f0;
      color: #1e293b;
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
      font-weight: 500;
    }

    /* Animations */
    @keyframes fadeIn {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    @keyframes shake {
      0%, 100% { transform: translateX(0); }
      25% { transform: translateX(-4px); }
      75% { transform: translateX(4px); }
    }

    @media (max-width: 900px) {
      .admin-shell {
        grid-template-columns: 1fr;
      }
      .content-area {
        padding: 20px 16px;
      }
      .page-header {
        padding: 28px 20px;
      }
      .sanction-card {
        grid-template-columns: 1fr;
        gap: 20px;
      }
      .tabs-container {
        width: 100%;
        overflow-x: auto;
        white-space: nowrap;
        display: flex;
      }
      .tab-pill {
        padding: 10px 16px;
      }
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
          <div class="welcome">Welcome back, <?php echo e($fullName); ?></div>
        </div>
      </section>

      <!-- Content Area -->
      <div class="content-area">

        <?php if (!$is_verified): ?>
          <!-- Password Verification gate -->
          <div class="lock-screen-wrapper">
            <div class="lock-screen-header">
              <div class="lock-screen-header-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                  <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                  <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                </svg>
              </div>
              <h2>Security Verification Required</h2>
              <div style="font-size: 13px; opacity: 0.8; margin-top: 4px;">Please enter your password to access the Sanctions panel.</div>
            </div>
            <div class="lock-screen-body">
              <div class="error-banner" id="pagePasswordError"></div>
              <form id="pagePasswordForm">
                <div class="form-group">
                  <label for="pagePassword">Admin Password</label>
                  <input type="password" id="pagePassword" required placeholder="Enter password to verify identity" />
                </div>
                <button type="submit" class="btn-submit">Verify Identity</button>
              </form>
            </div>
          </div>

        <?php else: ?>
          <!-- Verified Panel Content -->
          <!-- Tabs -->
          <div class="tabs-container">
            <a href="?tab=cat1" class="tab-pill <?php echo $tab === 'cat1' ? 'active' : ''; ?>">
              <svg viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
              Category 1 (Probation)
            </a>
            <a href="?tab=cat2" class="tab-pill <?php echo $tab === 'cat2' ? 'active' : ''; ?>">
              <svg viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>
              Category 2 (Service)
            </a>
            <a href="?tab=cat3" class="tab-pill <?php echo $tab === 'cat3' ? 'active' : ''; ?>">
              <svg viewBox="0 0 24 24"><path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path><line x1="12" y1="9" x2="12" y2="13"></line><line x1="12" y1="17" x2="12.01" y2="17"></line></svg>
              Category 3 (Warning)
            </a>
            <a href="?tab=cat4_5" class="tab-pill <?php echo $tab === 'cat4_5' ? 'active' : ''; ?>">
              <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect><path d="M7 11V7a5 5 0 0 1 10 0v4"></path></svg>
              Category 4 & 5 (Expelled)
            </a>
          </div>

          <!-- Tab Content Panels -->
          <?php if ($tab === 'cat1'): ?>
            <section class="panel-card">
              <h2>Students Under Category 1 (Suspension / Probation)</h2>
              <?php if (empty($cat1_cases)): ?>
                <div class="empty-state">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="8" y1="12" x2="16" y2="12"></line>
                  </svg>
                  <p>No students currently under Category 1 sanction.</p>
                </div>
              <?php else: ?>
                <div class="sanctions-list">
                  <?php foreach ($cat1_cases as $c): ?>
                    <?php 
                      $student_name = trim(($c['student_fn'] ?? '') . ' ' . ($c['student_ln'] ?? ''));
                      if ($student_name === '') $student_name = $c['student_id'];
                      
                      $is_ongoing = empty($c['probation_until']) || (strtotime($c['probation_until']) > time());
                    ?>
                    <div class="sanction-card cat-1">
                      <div class="sanction-card-left">
                        <div class="sanction-avatar-wrap">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                          </svg>
                        </div>
                        <div class="student-info-section">
                          <h3 class="student-name"><?php echo e($student_name); ?></h3>
                          <div class="student-badges">
                            <span class="meta-pill id-pill">ID: <?php echo e($c['student_id']); ?></span>
                            <span class="meta-pill program-pill"><?php echo e($c['program']); ?> • Sec. <?php echo e($c['section']); ?></span>
                            <span class="meta-pill year-pill"><?php echo e($c['year_level']); ?> Year</span>
                          </div>
                        </div>
                      </div>
                      
                      <div class="sanction-card-middle">
                        <div class="status-badge-container">
                          <?php if ($is_ongoing): ?>
                            <span class="status-badge ongoing">Ongoing Probation</span>
                          <?php else: ?>
                            <span class="status-badge completed">Completed</span>
                          <?php endif; ?>
                        </div>
                        <div class="details-grid">
                          <div class="detail-item">
                            <div class="detail-label">Probation End Date</div>
                            <div class="detail-value">
                              <?php echo !empty($c['probation_until']) ? date('M j, Y g:i A', strtotime($c['probation_until'])) : 'No date set'; ?>
                            </div>
                          </div>
                          <div class="detail-item">
                            <div class="detail-label">Case Status</div>
                            <div class="detail-value"><?php echo e(str_replace('_', ' ', $c['case_status'])); ?></div>
                          </div>
                        </div>
                      </div>

                      <div class="sanction-card-right">
                        <button class="btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                          'case_id' => $c['case_id'],
                          'student_id' => $c['student_id'],
                          'student_name' => $student_name,
                          'category' => 1,
                          'probation_until' => !empty($c['probation_until']) ? date('Y-m-d', strtotime($c['probation_until'])) : '',
                          'hours' => 0
                        ])); ?>)">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                          </svg>
                          Edit Sanction
                        </button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </section>

          <?php elseif ($tab === 'cat2'): ?>
            <section class="panel-card">
              <h2>Students Under Category 2 (Formative Intervention / Community Service)</h2>
              <?php if (empty($cat2_cases)): ?>
                <div class="empty-state">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="8" y1="12" x2="16" y2="12"></line>
                  </svg>
                  <p>No students currently under Category 2 sanction.</p>
                </div>
              <?php else: ?>
                <div class="sanctions-list">
                  <?php foreach ($cat2_cases as $c): ?>
                    <?php 
                      $student_name = trim(($c['student_fn'] ?? '') . ' ' . ($c['student_ln'] ?? ''));
                      if ($student_name === '') $student_name = $c['student_id'];
                      
                      $is_ongoing = $c['req_status'] !== 'COMPLETED';
                      $hours_comp = (float)$c['hours_completed'];
                      $hours_req = (float)$c['hours_required'];
                      $hours_rem = max(0.0, $hours_req - $hours_comp);
                    ?>
                    <div class="sanction-card cat-2">
                      <div class="sanction-card-left">
                        <div class="sanction-avatar-wrap">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                          </svg>
                        </div>
                        <div class="student-info-section">
                          <h3 class="student-name"><?php echo e($student_name); ?></h3>
                          <div class="student-badges">
                            <span class="meta-pill id-pill">ID: <?php echo e($c['student_id']); ?></span>
                            <span class="meta-pill program-pill"><?php echo e($c['program']); ?> • Sec. <?php echo e($c['section']); ?></span>
                            <span class="meta-pill year-pill"><?php echo e($c['year_level']); ?> Year</span>
                          </div>
                        </div>
                      </div>

                      <div class="sanction-card-middle">
                        <div class="status-badge-container">
                          <?php if (!$is_ongoing): ?>
                            <span class="status-badge completed">Completed</span>
                          <?php else: ?>
                            <span class="status-badge ongoing">Ongoing (<?php echo formatHoursMinutes($hours_rem); ?> remaining)</span>
                          <?php endif; ?>
                        </div>
                        <div class="details-grid">
                          <div class="detail-item">
                            <div class="detail-label">Service Task</div>
                            <div class="detail-value"><?php echo e($c['task_name'] ?: 'University Service'); ?></div>
                          </div>
                          <div class="detail-item">
                            <div class="detail-label">Completed Hours</div>
                            <div class="detail-value"><?php echo formatHoursMinutes($hours_comp); ?> / <?php echo formatHoursMinutes($hours_req); ?></div>
                          </div>
                          <div class="detail-item">
                            <div class="detail-label">Completed Date</div>
                            <div class="detail-value">
                              <?php echo !empty($c['req_completed_at']) ? date('M j, Y g:i A', strtotime($c['req_completed_at'])) : ($is_ongoing ? 'In Progress' : 'Completed'); ?>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div class="sanction-card-right">
                        <button class="btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                          'case_id' => $c['case_id'],
                          'student_id' => $c['student_id'],
                          'student_name' => $student_name,
                          'category' => 2,
                          'probation_until' => '',
                          'hours' => $hours_req
                        ])); ?>)">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                          </svg>
                          Edit Sanction
                        </button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </section>

          <?php elseif ($tab === 'cat3'): ?>
            <section class="panel-card">
              <h2>Students Under Category 3 (Non-Readmission Next Semester)</h2>
              <?php if (empty($cat3_cases)): ?>
                <div class="empty-state">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="8" y1="12" x2="16" y2="12"></line>
                  </svg>
                  <p>No students currently under Category 3 sanction.</p>
                </div>
              <?php else: ?>
                <div class="sanctions-list">
                  <?php foreach ($cat3_cases as $c): ?>
                    <?php 
                      $student_name = trim(($c['student_fn'] ?? '') . ' ' . ($c['student_ln'] ?? ''));
                      if ($student_name === '') $student_name = $c['student_id'];
                    ?>
                    <div class="sanction-card cat-3">
                      <div class="sanction-card-left">
                        <div class="sanction-avatar-wrap">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                          </svg>
                        </div>
                        <div class="student-info-section">
                          <h3 class="student-name"><?php echo e($student_name); ?></h3>
                          <div class="student-badges">
                            <span class="meta-pill id-pill">ID: <?php echo e($c['student_id']); ?></span>
                            <span class="meta-pill program-pill"><?php echo e($c['program']); ?> • Sec. <?php echo e($c['section']); ?></span>
                            <span class="meta-pill year-pill"><?php echo e($c['year_level']); ?> Year</span>
                          </div>
                        </div>
                      </div>

                      <div class="sanction-card-middle">
                        <div class="status-badge-container">
                          <span class="status-badge frozen">Active Warning</span>
                        </div>
                        <div class="details-grid">
                          <div class="detail-item" style="grid-column: span 2;">
                            <div class="detail-label">Punishment Status Note</div>
                            <div class="detail-value" style="color: #ef4444; font-weight: 700;">Account will be blocked from readmission next semester.</div>
                          </div>
                        </div>
                      </div>

                      <div class="sanction-card-right">
                        <button class="btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                          'case_id' => $c['case_id'],
                          'student_id' => $c['student_id'],
                          'student_name' => $student_name,
                          'category' => 3,
                          'probation_until' => '',
                          'hours' => 0
                        ])); ?>)">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                          </svg>
                          Edit Sanction
                        </button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </section>

          <?php else: ?>
            <section class="panel-card">
              <h2>Students Under Category 4 (Exclusion) & Category 5 (Expulsion)</h2>
              <?php if (empty($cat4_5_cases)): ?>
                <div class="empty-state">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="8" y1="12" x2="16" y2="12"></line>
                  </svg>
                  <p>No students currently under Category 4 or 5 sanction.</p>
                </div>
              <?php else: ?>
                <div class="sanctions-list">
                  <?php foreach ($cat4_5_cases as $c): ?>
                    <?php 
                      $student_name = trim(($c['student_fn'] ?? '') . ' ' . ($c['student_ln'] ?? ''));
                      if ($student_name === '') $student_name = $c['student_id'];
                      $is_expulsion = ((int)$c['decided_category'] === 5);
                      $is_active = (bool)$c['student_active'];
                    ?>
                    <div class="sanction-card cat-4">
                      <div class="sanction-card-left">
                        <div class="sanction-avatar-wrap">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                            <circle cx="12" cy="7" r="4"></circle>
                          </svg>
                        </div>
                        <div class="student-info-section">
                          <h3 class="student-name"><?php echo e($student_name); ?></h3>
                          <div class="student-badges">
                            <span class="meta-pill id-pill">ID: <?php echo e($c['student_id']); ?></span>
                            <span class="meta-pill program-pill"><?php echo e($c['program']); ?> • Sec. <?php echo e($c['section']); ?></span>
                            <span class="meta-pill year-pill"><?php echo e($c['year_level']); ?> Year</span>
                          </div>
                        </div>
                      </div>

                      <div class="sanction-card-middle">
                        <div class="status-badge-container">
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
                        <div class="details-grid">
                          <div class="detail-item" style="grid-column: span 2;">
                            <div class="detail-label">Punishment Status Note</div>
                            <div class="detail-value" style="color: #b91c1c; font-weight: 700;">
                              <?php echo $is_expulsion ? 'Expelled from the University. Registration and login disabled.' : 'Excluded from the University. Registration and login disabled.'; ?>
                            </div>
                          </div>
                        </div>
                      </div>

                      <div class="sanction-card-right">
                        <button class="btn-edit" onclick="openEditModal(<?php echo htmlspecialchars(json_encode([
                          'case_id' => $c['case_id'],
                          'student_id' => $c['student_id'],
                          'student_name' => $student_name,
                          'category' => (int)$c['decided_category'],
                          'probation_until' => '',
                          'hours' => 0
                        ])); ?>)">
                          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"></path>
                            <path d="M18.5 2.5a2.121 2.121 0 1 1 3 3L12 15l-4 1 1-4 9.5-9.5z"></path>
                          </svg>
                          Edit Sanction
                        </button>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
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
              <input type="text" id="editStudentName" readonly style="background:#f1f5f9; border-color:#e2e8f0; color:#475569;" />
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
              <label style="margin-bottom: 12px; display: block;">Required Service Time</label>
              <div style="display: flex; gap: 12px;">
                <div style="flex: 1;">
                  <label for="editHours" style="font-size: 11px; color: #64748b; margin-bottom: 4px; display: block;">Hours</label>
                  <input type="number" min="0" id="editHours" placeholder="e.g. 40" style="width: 100%;" />
                </div>
                <div style="flex: 1;">
                  <label for="editMinutes" style="font-size: 11px; color: #64748b; margin-bottom: 4px; display: block;">Minutes</label>
                  <input type="number" min="0" max="59" id="editMinutes" placeholder="e.g. 0" style="width: 100%;" />
                </div>
              </div>
            </div>

            <button type="button" class="btn-submit" onclick="goToVerification()">Continue to Verification</button>
          </form>
        </div>

        <!-- Step 2: Password and OTP Verification -->
        <div class="step-content" id="stepVerify">
          <div class="error-banner" id="verifyError"></div>
          <div style="font-size: 13.5px; margin-bottom: 20px; color: #475569; line-height: 1.5;">
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
      
      const totalHours = parseFloat(data.hours) || 0;
      const hrs = Math.floor(totalHours);
      const mins = Math.round((totalHours - hrs) * 60);
      document.getElementById('editHours').value = hrs || '';
      document.getElementById('editMinutes').value = mins || '';

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
        const hours = parseFloat(document.getElementById('editHours').value) || 0;
        const minutes = parseFloat(document.getElementById('editMinutes').value) || 0;
        if (hours <= 0 && minutes <= 0) {
          alert('Please enter a valid number of service hours and/or minutes.');
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
      
      const hoursVal = parseFloat(document.getElementById('editHours').value) || 0;
      const minutesVal = parseFloat(document.getElementById('editMinutes').value) || 0;
      const combinedHours = hoursVal + (minutesVal / 60.0);
      
      const password = document.getElementById('verifyPassword').value;
      const otp = document.getElementById('verifyOTP').value;

      const params = new URLSearchParams();
      params.append('case_id', caseId);
      params.append('student_id', studentId);
      params.append('category', category);
      params.append('probation_until', probation_until);
      params.append('hours', combinedHours);
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
