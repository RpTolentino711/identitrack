<?php
require_once __DIR__ . '/../database/database.php';

function scanner_hash_value(string $rawValue): string
{
  // Replace this pepper in production using a secret from environment/config.
  $pepper = 'IDENTITRACK_SCANNER_PEPPER_V1_CHANGE_ME';
  $normalized = strtoupper(trim($rawValue));
  return hash('sha256', $pepper . ':' . $normalized);
}

function student_has_scanner_hash_column(): bool
{
  static $hasColumn = null;
  if ($hasColumn !== null) {
    return $hasColumn;
  }

  $row = db_one(
    "SELECT 1 AS ok
     FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'student'
       AND column_name = 'scanner_id_hash'
     LIMIT 1"
  );

  $hasColumn = (bool)$row;
  return $hasColumn;
}

function find_student_by_input(string $input): ?array
{
  $input = trim($input);
  if ($input === '') {
    return null;
  }

  // Backward-compatible: allow direct student ID entry.
  $studentParams = [':sid' => $input];
  db_add_encryption_key($studentParams);
  $student = db_one(
    "SELECT student_id, " . db_decrypt_cols(['student_fn', 'student_ln']) . ", is_active
     FROM student
     WHERE student_id = :sid
     LIMIT 1",
    $studentParams
  );
  if ($student) {
    return $student;
  }

  if (!student_has_scanner_hash_column()) {
    return null;
  }

  $hashParams = [':scanner_hash' => $hash];
  db_add_encryption_key($hashParams);
  return db_one(
    "SELECT student_id, " . db_decrypt_cols(['student_fn', 'student_ln']) . ", is_active
     FROM student
     WHERE scanner_id_hash = :scanner_hash
     LIMIT 1",
    $hashParams
  );
}

$studentId = '';
$student = null;
$tasks = [];
$error = '';

if ($_POST) {
  $studentId = trim($_POST['student_id'] ?? '');
  if ($studentId === '') {
    $error = "Please enter your student ID.";
  } else {
    $student = find_student_by_input($studentId);
    if (!$student || (int)$student['is_active'] !== 1) {
      $error = "Student not found or inactive.";
    } else {
      $studentId = (string)$student['student_id'];
      
      // Check for active session
      $activeSession = db_one(
        "SELECT css.session_id, csr.task_name 
         FROM community_service_session css
         JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
         WHERE csr.student_id = :sid AND css.time_out IS NULL
         LIMIT 1",
        [':sid' => $studentId]
      );

      if (!$activeSession) {
        // Must have at least one active requirement to time in
        $hasReq = db_one("SELECT 1 AS ok FROM community_service_requirement WHERE student_id = :sid AND status='ACTIVE' LIMIT 1", [':sid' => $studentId]);
        if (!$hasReq) {
          $error = "No active community service tasks assigned for this student.";
        }
      }
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Community Service Login | IDENTITRACK</title>
  <link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
  <style>
    :root {
      --navy:   #0d1f5c;
      --blue:   #1a3fa0;
      --accent: #2563eb;
      --sky:    #e8eeff;
      --white:  #ffffff;
      --muted:  #6b7a9e;
      --error:  #dc2626;
      --success:#16a34a;
      --radius: 16px;
      --shadow: 0 24px 70px rgba(13,31,92,.28);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
      font-family: 'DM Sans', sans-serif;
      min-height: 100vh;
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 24px;
      background:
        linear-gradient(180deg, rgba(8,10,28,.65) 0%, rgba(8,10,28,.78) 100%),
        url("../assets/wallpaper.png") center / cover no-repeat fixed;
    }

    /* ─── Card ─── */
    .card {
      width: min(440px, 100%);
      background: var(--white);
      border-radius: 24px;
      box-shadow: var(--shadow);
      overflow: hidden;
      animation: rise .45s cubic-bezier(.22,.68,0,1.2) both;
    }

    @keyframes rise {
      from { opacity: 0; transform: translateY(28px) scale(.97); }
      to   { opacity: 1; transform: none; }
    }

    /* ─── Header band ─── */
    .card-header {
      background:
        linear-gradient(180deg, rgba(8,12,40,.52) 0%, rgba(8,12,40,.70) 100%),
        url("../assets/guard.jpg") center / cover no-repeat;
      padding: 32px 32px 0;
      text-align: center;
      position: relative;
    }

    /* all header content above the scallop */
    .header-inner {
      position: relative;
      z-index: 1;
      padding-bottom: 40px;         /* space before scallop cut */
    }

    /* white scallop at the bottom of the header */
    .header-scallop {
      display: block;
      width: 100%;
      height: 48px;
      position: relative;
      z-index: 1;
    }

    .logo-wrap {
      width: 76px; height: 76px;
      margin: 0 auto 14px;
      border-radius: 50%;
      background: rgba(255,255,255,.15);
      border: 2px solid rgba(255,255,255,.32);
      display: flex; align-items: center; justify-content: center;
      overflow: hidden;
    }

    .logo-wrap img {
      width: 60px; height: 60px;
      object-fit: contain;
    }

    .card-header h1 {
      font-family: 'Syne', sans-serif;
      font-size: 24px;
      font-weight: 800;
      color: #fff;
      letter-spacing: .5px;
      margin-bottom: 6px;
    }

    .card-header p {
      font-size: 13px;
      color: rgba(255,255,255,.82);
      font-weight: 500;
      letter-spacing: .3px;
    }

    /* ─── Body ─── */
    .card-body {
      padding: 8px 32px 28px;
    }

    /* ─── Step label ─── */
    .step-label {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: 1.2px;
      text-transform: uppercase;
      color: var(--accent);
      margin-bottom: 20px;
    }

    .step-dot {
      width: 8px; height: 8px;
      border-radius: 50%;
      background: var(--accent);
      flex-shrink: 0;
    }

    /* ─── Welcome banner ─── */
    .welcome-banner {
      display: flex;
      align-items: center;
      gap: 12px;
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      border-radius: 12px;
      padding: 12px 16px;
      margin-bottom: 22px;
    }

    .welcome-banner .avatar {
      width: 40px; height: 40px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--navy), var(--accent));
      display: flex; align-items: center; justify-content: center;
      font-family: 'Syne', sans-serif;
      font-weight: 800;
      font-size: 16px;
      color: #fff;
      flex-shrink: 0;
    }

    .welcome-banner .wtext strong {
      display: block;
      font-weight: 700;
      color: var(--navy);
      font-size: 15px;
    }

    .welcome-banner .wtext span {
      font-size: 12px;
      color: var(--success);
      font-weight: 600;
    }

    /* ─── Error box ─── */
    .error-box {
      display: flex;
      align-items: flex-start;
      gap: 10px;
      background: #fef2f2;
      border: 1px solid #fecaca;
      border-radius: 12px;
      padding: 12px 16px;
      margin-bottom: 20px;
      color: var(--error);
      font-size: 14px;
      font-weight: 600;
      animation: shake .35s ease;
    }

    @keyframes shake {
      0%,100%{ transform: translateX(0); }
      25%    { transform: translateX(-6px); }
      75%    { transform: translateX(6px); }
    }

    .error-icon { font-size: 16px; flex-shrink: 0; margin-top: 1px; }

    /* ─── Form fields ─── */
    .field { margin-bottom: 18px; }

    label {
      display: block;
      font-size: 12px;
      font-weight: 700;
      letter-spacing: .6px;
      text-transform: uppercase;
      color: var(--muted);
      margin-bottom: 7px;
    }

    input, select, textarea {
      width: 100%;
      padding: 13px 16px;
      border-radius: var(--radius);
      border: 1.5px solid #dde2ef;
      font-family: 'DM Sans', sans-serif;
      font-size: 15px;
      color: var(--navy);
      background: var(--sky);
      transition: border-color .18s, box-shadow .18s, background .18s;
      outline: none;
      -webkit-appearance: none;
      appearance: none;
    }

    select {
      background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8' viewBox='0 0 12 8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236b7a9e' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
      background-repeat: no-repeat;
      background-position: right 14px center;
      background-color: var(--sky);
      padding-right: 40px;
      cursor: pointer;
    }

    input:focus, select:focus, textarea:focus {
      border-color: var(--accent);
      background: #fff;
      box-shadow: 0 0 0 3.5px rgba(37,99,235,.12);
    }

    textarea {
      resize: vertical;
      min-height: 88px;
      line-height: 1.5;
    }

    /* ─── Manual reason collapsible ─── */
    .manual-box { display: none; }
    .manual-box.open {
      display: block;
      animation: slideDown .22s ease;
    }

    @keyframes slideDown {
      from { opacity: 0; transform: translateY(-6px); }
      to   { opacity: 1; transform: none; }
    }

    /* ─── Button ─── */
    .btn {
      width: 100%;
      padding: 15px;
      border-radius: var(--radius);
      border: none;
      background: linear-gradient(135deg, var(--navy) 0%, var(--accent) 100%);
      color: #fff;
      font-family: 'Syne', sans-serif;
      font-weight: 800;
      font-size: 16px;
      letter-spacing: .4px;
      cursor: pointer;
      transition: transform .14s, box-shadow .14s;
      margin-top: 4px;
    }

    .btn:hover {
      transform: translateY(-2px);
      box-shadow: 0 8px 24px rgba(37,99,235,.38);
    }

    .btn:active { transform: translateY(0); }

    /* ─── Hint ─── */
    .hint {
      text-align: center;
      font-size: 12.5px;
      color: var(--muted);
      margin-top: 14px;
      line-height: 1.55;
    }

    /* ─── Footer ─── */
    .card-footer {
      text-align: center;
      padding: 14px 32px 20px;
      border-top: 1px solid #edf0f7;
      font-size: 12px;
      color: var(--muted);
    }

    .card-footer a {
      color: var(--accent);
      text-decoration: none;
      font-weight: 600;
    }
  </style>
</head>
<body>

  <div class="card">

    <!-- ── Header ── -->
    <div class="card-header">
      <div class="header-inner">
        <div class="logo-wrap">
          <img src="../assets/logo.png" alt="IDENTITRACK Logo" />
        </div>
        <h1>Community Service</h1>
        <p>IDENTITRACK — Student Service Portal</p>
      </div>
      <!-- SVG scallop so text is never clipped -->
      <svg class="header-scallop" viewBox="0 0 440 48" preserveAspectRatio="none" xmlns="http://www.w3.org/2000/svg">
        <path d="M0,48 Q110,0 220,24 Q330,48 440,0 L440,48 Z" fill="#ffffff"/>
      </svg>
    </div>

    <!-- ── Body ── -->
    <div class="card-body">

      <?php if ($error !== ''): ?>
        <div class="error-box">
          <span class="error-icon">⚠</span>
          <?= htmlspecialchars($error) ?>
        </div>
      <?php endif; ?>

      <?php if (!$student): ?>
        <div class="step-label"><span class="step-dot"></span>Step 1 of 2 — Identify</div>
        <form method="post">
          <div class="field">
            <label for="student_id">Student ID</label>
            <input type="text" id="student_id" name="student_id"
                   placeholder="e.g. 2024-00001"
                   required maxlength="50" autofocus
                   value="<?= htmlspecialchars($studentId) ?>">
          </div>
          <button class="btn" type="submit">Continue →</button>
          <p class="hint">Enter your institutional student ID to look up your assigned service tasks.</p>
        </form>

      <?php elseif ($student && $error === ''): ?>
        <?php $isTimingOut = (bool)$activeSession; ?>
        <div class="step-label"><span class="step-dot"></span>Step 2 of 2 — <?= $isTimingOut ? 'Time Out' : 'Time In' ?></div>

        <div class="welcome-banner">
          <div class="avatar"><?= strtoupper(substr($student['student_fn'], 0, 1)) ?></div>
          <div class="wtext">
            <strong><?= htmlspecialchars($student['student_fn'].' '.$student['student_ln']) ?></strong>
            <span>✔ Identity verified</span>
          </div>
        </div>

        <?php if ($isTimingOut): ?>
          <div class="welcome-banner" style="background:#e8eeff; border-color:#b6c6f2;">
            <div class="wtext" style="width:100%;">
              <strong>Active Session: <?= htmlspecialchars($activeSession['task_name']) ?></strong>
              <span style="color:#1a3fa0;">You are currently timed in. Proceed to time out.</span>
            </div>
          </div>
        <?php endif; ?>

        <form method="post" action="community_service.php">
          <input type="hidden" name="student_id" value="<?= htmlspecialchars($studentId) ?>">
          <input type="hidden" name="action_type" value="<?= $isTimingOut ? 'LOGOUT' : 'LOGIN' ?>">

          <div class="field">
            <label for="login_method"><?= $isTimingOut ? 'Logout Method' : 'Login Method' ?></label>
            <select id="login_method" name="login_method" required
              onchange="document.getElementById('manual_box').classList.toggle('open', this.value==='MANUAL')">
              <option value="NFC">Scan ID (Instant <?= $isTimingOut ? 'Logout' : 'Verification' ?>)</option>
              <option value="MANUAL">Manual <?= $isTimingOut ? 'Logout' : 'Login' ?> (Admin Approval Required)</option>
            </select>
          </div>

          <div id="manual_box" class="manual-box field">
            <label for="reason">Manual <?= $isTimingOut ? 'Logout' : 'Login' ?> Reason</label>
            <textarea id="reason" name="reason" placeholder="Explain why NFC could not be used…"></textarea>
          </div>

          <button class="btn" type="submit"><?= $isTimingOut ? 'Time Out →' : 'Time In →' ?></button>
          <p class="hint">
            <?php if ($isTimingOut): ?>
              Scan ID to instantly time out. Manual logout requires admin approval to stop the timer.
            <?php else: ?>
              Scan ID or use Manual Login to send a request. Your timer starts once the admin assigns your task and approves.
            <?php endif; ?>
          </p>
        </form>
      <?php endif; ?>

    </div>

    <div class="card-footer">
      National University Lipa &nbsp;•&nbsp; Student Discipline Office &nbsp;|&nbsp;
      <a href="../index.php">← Back to Home</a>
    </div>

  </div>

</body>
</html>