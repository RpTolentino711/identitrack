<?php
// File: C:\xampp\htdocs\identitrack\admin\offense_view.php
// View Details: Student profile + selected offense + offense history
// "Add New Offense" button opens offense_new.php with student_id prefilled

require_once __DIR__ . '/../database/database.php';
require_admin();

$activeSidebar = 'offenses';

$admin = admin_current();
$fullName = trim((string)($admin['full_name'] ?? ''));
if ($fullName === '') $fullName = (string)($admin['username'] ?? 'User');

$offenseId = (int)($_GET['offense_id'] ?? 0);
if ($offenseId <= 0) {
  redirect('offenses.php');
}

// Get selected offense + student + offense_type details
$row = db_one(
  "SELECT
      o.offense_id,
      o.student_id,
      o.status,
      o.description,
      o.date_committed,
      ot.level AS level,
      ot.code AS offense_code,
      ot.name AS offense_name,
      ot.major_category,
      ot.intervention_first,
      ot.intervention_second,
      s.student_fn,
      s.student_ln,
      s.year_level,
      s.section,
      s.school,
      s.program,
      s.student_email
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   JOIN student s ON s.student_id = o.student_id
   WHERE o.offense_id = :id
   LIMIT 1",
  [':id' => $offenseId]
);

if (!$row) {
  redirect('offenses.php');
}

$studentId = (string)$row['student_id'];
$studentName = trim((string)$row['student_fn'] . ' ' . (string)$row['student_ln']);

// offense history for this student
$history = db_all(
  "SELECT
      o.offense_id,
      o.status,
      o.date_committed,
      ot.level AS level,
      ot.code AS offense_code,
      ot.name AS offense_name,
      ot.major_category
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   WHERE o.student_id = :sid
   ORDER BY o.date_committed DESC",
  [':sid' => $studentId]
);

// Get NEW/PENDING offenses for this student (that need review)
$newOffenses = db_all(
  "SELECT
      o.offense_id,
      o.date_committed,
      ot.level AS level,
      ot.code AS offense_code,
      ot.name AS offense_name
   FROM offense o
   JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
   WHERE o.student_id = :sid AND o.status = 'OPEN'
   ORDER BY o.date_committed DESC
   LIMIT 1",
  [':sid' => $studentId]
);

// Category descriptions for display
$categoryDescriptions = [
  1 => "Category 1 — Probation for three (3) academic terms and referral for counseling.",
  2 => "Category 2 — Formative intervention (university service, counseling, lectures, evaluation).",
  3 => "Category 3 — Non-Readmission.",
  4 => "Category 4 — Exclusion.",
  5 => "Category 5 — Expulsion.",
];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Offense Details | SDO Web Portal</title>
  <style>
    *{ box-sizing:border-box; }
    body{ margin:0; font-family:'Segoe UI',Tahoma,Arial,sans-serif; background:#f8f9fa; color:#1b2244; }
    .admin-shell{ min-height: calc(100vh - 72px); display:grid; grid-template-columns: 240px 1fr; }
    .wrap{ min-height:100%; padding:0; }
    .page-header{
      background:#fff;
      border-bottom:1px solid #e0e0e0;
      padding: 28px 32px;
      display:flex;
      align-items:center;
      justify-content: space-between;
      gap: 16px;
    }
    .back-btn{
      display:inline-flex; align-items:center; gap:6px;
      color:#6c757d; text-decoration:none;
      font-size:14px; font-weight:700;
      padding: 8px 12px; border-radius: 8px;
      border:1px solid #dee2e6; background:#fff;
    }
    .back-btn:hover{ border-color:#3b4a9e; color:#3b4a9e; background:#f0f2ff; }
    .content-area{ padding: 24px 32px; max-width: 980px; }

    .panel{
      background:#fff;
      border:1px solid #dee2e6;
      border-radius: 12px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.04);
      padding: 18px;
      margin-bottom: 16px;
    }

    .profile-head{
      display:flex;
      align-items:flex-start;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 12px;
    }

    .name{
      margin:0;
      color:#1a1a1a;
      font-size: 20px;
      font-weight: 900;
    }

    .sub{
      margin-top: 6px;
      color:#6c757d;
      font-weight:700;
      font-size: 13px;
    }

    .btn-primary{
      background:#3b4a9e;
      color:#fff;
      padding: 10px 14px;
      border-radius: 10px;
      text-decoration:none;
      font-weight:900;
      font-size: 13px;
      white-space: nowrap;
    }
    .btn-primary:hover{ background:#2d3a7e; }

    .grid{
      display:grid;
      grid-template-columns: repeat(2, minmax(0, 1fr));
      gap: 10px 14px;
      margin-top: 12px;
    }

    .item .label{
      color:#6c757d;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: .4px;
      font-weight: 800;
      margin-bottom: 3px;
    }
    .item .value{
      font-weight: 700;
      color:#1a1a1a;
      font-size: 14px;
      line-height: 1.35;
    }

    table{ width:100%; border-collapse: collapse; margin-top: 8px; }
    th,td{ padding: 10px 8px; border-bottom:1px solid #eef1fb; text-align:left; }
    th{ color:#6c757d; font-size: 12px; text-transform: uppercase; letter-spacing:.5px; }
    tr:last-child td{ border-bottom:none; }

    .pill{
      display:inline-block;
      padding: 4px 10px;
      border-radius:999px;
      font-size: 11px;
      font-weight: 900;
      border: 1px solid transparent;
    }
    .pill.minor{ background: rgba(255,193,7,.18); color:#856404; border-color: rgba(255,193,7,.45); }
    .pill.major{ background: rgba(220,53,69,.12); color:#842029; border-color: rgba(220,53,69,.25); }

    .category-panel{
      border-radius: 12px;
      border: 1px solid rgba(220,53,69,.25);
      background: rgba(220,53,69,.08);
      padding: 14px;
      margin-top: 10px;
      color: #842029;
    }
    .category-panel .title{ font-weight: 900; margin-bottom: 6px; }
    .category-panel .text{ font-weight: 700; font-size: 13px; line-height: 1.45; }

    a.link{ color:#3b4a9e; font-weight: 900; text-decoration:none; }
    a.link:hover{ text-decoration: underline; }

    /* ── NOTIFICATION POPUP ── */
    .notif-popup {
      position: fixed;
      bottom: 24px;
      right: 24px;
      z-index: 9999;
      background: #ffffff;
      border: 2px solid #dc3545;
      border-radius: 14px;
      padding: 18px 20px;
      box-shadow: 0 12px 40px rgba(8, 16, 48, 0.25);
      max-width: 400px;
      animation: popupSlideIn .4s cubic-bezier(.16,1,.3,1) both;
      display: none;
      cursor: pointer;
      transition: all .2s ease;
    }

    .notif-popup:hover {
      transform: translateY(-4px);
      box-shadow: 0 16px 48px rgba(220, 53, 69, 0.35);
    }

    .notif-popup.show {
      display: flex;
      flex-direction: column;
      gap: 10px;
    }

    @keyframes popupSlideIn {
      from {
        opacity: 0;
        transform: translateY(20px) translateX(10px);
      }
      to {
        opacity: 1;
        transform: translateY(0) translateX(0);
      }
    }

    @keyframes popupSlideOut {
      from {
        opacity: 1;
        transform: translateY(0) translateX(0);
      }
      to {
        opacity: 0;
        transform: translateY(20px) translateX(10px);
      }
    }

    .notif-popup.hide {
      animation: popupSlideOut .3s cubic-bezier(.16,1,.3,1) forwards;
    }

    .notif-popup-header {
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .notif-popup-icon {
      width: 40px;
      height: 40px;
      border-radius: 10px;
      background: #fff5f5;
      display: flex;
      align-items: center;
      justify-content: center;
      flex-shrink: 0;
    }

    .notif-popup-icon svg {
      width: 22px;
      height: 22px;
      stroke: #dc3545;
      fill: none;
      stroke-width: 2;
      stroke-linecap: round;
      stroke-linejoin: round;
    }

    .notif-popup-title {
      font-size: 15px;
      font-weight: 700;
      color: #1a1f36;
      margin: 0;
      line-height: 1.3;
    }

    .notif-popup-message {
      font-size: 13px;
      color: #4a5578;
      margin: 0;
      line-height: 1.5;
    }

    .notif-popup-timer {
      height: 2px;
      background: #e0e6f5;
      border-radius: 999px;
      overflow: hidden;
      margin-top: 6px;
    }

    .notif-popup-timer-bar {
      height: 100%;
      background: #dc3545;
      border-radius: 999px;
      animation: timerCountdown 8s linear forwards;
    }

    .notif-popup-hint {
      font-size: 11px;
      color: #6c757d;
      font-weight: 600;
      text-align: center;
      margin-top: 4px;
    }

    @keyframes timerCountdown {
      from { width: 100%; }
      to { width: 0%; }
    }

    @media (max-width: 900px){
      .admin-shell{ grid-template-columns: 1fr; }
      .content-area{ padding: 20px 16px; }
      .page-header{ padding: 20px 16px; flex-direction: column; align-items:flex-start; }
      .grid{ grid-template-columns: 1fr; }
      .notif-popup {
        bottom: 16px;
        right: 16px;
        max-width: calc(100vw - 32px);
      }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>

  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="wrap">
      <section class="page-header">
        <a class="back-btn" href="offenses.php">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="15 18 9 12 15 6"></polyline>
          </svg>
          Back to Offenses
        </a>
        <div style="color:#6c757d;font-weight:700;font-size:13px;">Welcome, <?php echo e($fullName); ?></div>
      </section>

      <div class="content-area">

        <!-- Student Profile -->
        <section class="panel">
          <div class="profile-head">
            <div>
              <h2 class="name"><?php echo e($studentName); ?></h2>
              <div class="sub">
                Student ID: <?php echo e($studentId); ?> •
                Year: <?php echo e((string)$row['year_level']); ?> •
                Course/Program: <?php echo e((string)($row['program'] ?? '')); ?>
              </div>
            </div>

            <!-- Prefill student_id -->
            <a class="btn-primary" href="offense_new.php?student_id=<?php echo urlencode($studentId); ?>">Add New Offense</a>
          </div>

          <div class="grid">
            <div class="item">
              <div class="label">School</div>
              <div class="value"><?php echo e((string)($row['school'] ?? '')); ?></div>
            </div>
            <div class="item">
              <div class="label">Section</div>
              <div class="value"><?php echo e((string)($row['section'] ?? '')); ?></div>
            </div>
            <div class="item">
              <div class="label">Email</div>
              <div class="value"><?php echo e((string)($row['student_email'] ?? '')); ?></div>
            </div>
            <div class="item">
              <div class="label">Status (Offense)</div>
              <div class="value"><?php echo e((string)$row['status']); ?></div>
            </div>
          </div>
        </section>

        <!-- Selected Offense Details -->
        <section class="panel">
          <h2 style="margin:0 0 10px; font-size:18px; font-weight:900; color:#1a1a1a;">Selected Offense</h2>

          <div class="grid">
            <div class="item">
              <div class="label">Offense</div>
              <div class="value"><?php echo e((string)$row['offense_name']); ?> (<?php echo e((string)$row['offense_code']); ?>)</div>
            </div>

            <div class="item">
              <div class="label">Level</div>
              <div class="value">
                <?php if ($row['level'] === 'MAJOR'): ?>
                  <span class="pill major">MAJOR</span>
                <?php else: ?>
                  <span class="pill minor">MINOR</span>
                <?php endif; ?>
              </div>
            </div>

            <div class="item">
              <div class="label">Date Committed</div>
              <div class="value"><?php echo e((string)$row['date_committed']); ?></div>
            </div>

            <div class="item">
              <div class="label">Recorded Status</div>
              <div class="value"><?php echo e((string)$row['status']); ?></div>
            </div>

            <div class="item" style="grid-column: 1 / -1;">
              <div class="label">Description</div>
              <div class="value"><?php echo e((string)($row['description'] ?? '')); ?></div>
            </div>
          </div>

          <?php if ($row['level'] === 'MAJOR' && !empty($row['major_category'])): ?>
            <div class="category-panel">
              <div class="title">Major Category <?php echo (int)$row['major_category']; ?></div>
              <div class="text"><?php echo e($categoryDescriptions[(int)$row['major_category']] ?? ''); ?></div>
              <?php if (!empty($row['intervention_first'])): ?>
                <div class="text" style="margin-top:8px;">1st: <?php echo e((string)$row['intervention_first']); ?></div>
              <?php endif; ?>
              <?php if (!empty($row['intervention_second'])): ?>
                <div class="text" style="margin-top:6px;">2nd: <?php echo e((string)$row['intervention_second']); ?></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </section>

        <!-- Offense History -->
        <section class="panel">
          <h2 style="margin:0 0 10px; font-size:18px; font-weight:900; color:#1a1a1a;">Offense History</h2>

          <?php if (empty($history)): ?>
            <div style="color:#6c757d;font-weight:700;">No offense history found.</div>
          <?php else: ?>
            <table>
              <thead>
                <tr>
                  <th>Offense</th>
                  <th>Level</th>
                  <th>Date</th>
                  <th>Status</th>
                  <th></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($history as $h): ?>
                  <tr>
                    <td>
                      <div style="font-weight:900;color:#1a1a1a;"><?php echo e((string)$h['offense_name']); ?></div>
                      <div style="color:#6c757d;font-size:13px;"><?php echo e((string)$h['offense_code']); ?></div>
                    </td>
                    <td>
                      <?php if ($h['level'] === 'MAJOR'): ?>
                        <span class="pill major">MAJOR</span>
                      <?php else: ?>
                        <span class="pill minor">MINOR</span>
                      <?php endif; ?>
                    </td>
                    <td><?php echo e((string)$h['date_committed']); ?></td>
                    <td><?php echo e((string)$h['status']); ?></td>
                    <td>
                      <?php if ((int)$h['offense_id'] !== (int)$offenseId): ?>
                        <a class="link" href="offense_view.php?offense_id=<?php echo (int)$h['offense_id']; ?>">View</a>
                      <?php else: ?>
                        <span style="color:#6c757d;font-weight:800;">Current</span>
                      <?php endif; ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          <?php endif; ?>
        </section>

      </div>
    </main>
  </div>

  <!-- NOTIFICATION POPUP - CLICKABLE -->
  <?php if (!empty($newOffenses)): $newOffense = $newOffenses[0]; ?>
  <div class="notif-popup" id="notifPopup" onclick="handlePopupClick()">
    <div class="notif-popup-header">
      <div class="notif-popup-icon">
        <svg viewBox="0 0 24 24" aria-hidden="true">
          <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
        </svg>
      </div>
      <div>
        <p class="notif-popup-title" id="notifPopupTitle">New Offense Reported</p>
        <p class="notif-popup-message" id="notifPopupMessage">
          <?php echo e((string)$newOffense['level']); ?> - <?php echo e((string)$newOffense['offense_name']); ?>
        </p>
      </div>
    </div>
    <div class="notif-popup-timer">
      <div class="notif-popup-timer-bar"></div>
    </div>
    <div class="notif-popup-hint">👆 Click to review • Approve or Reject</div>
  </div>
  <?php endif; ?>

  <script>
    (function(){
      const popup = document.getElementById('notifPopup');
      if (!popup) return;

      let popupTimeout;

      function handlePopupClick() {
        const studentId = '<?php echo urlencode($studentId); ?>';
        // Go to offense listing for this student to approve/reject
        window.location.href = `offenses_student_view.php?student_id=${studentId}`;
      }

      function hidePopup() {
        popup.classList.add('hide');
        popup.classList.remove('show');
      }

      // Show popup on page load
      window.addEventListener('load', () => {
        if (popup) {
          popup.classList.add('show');
          
          // Auto-hide after 8 seconds
          popupTimeout = setTimeout(() => {
            hidePopup();
          }, 8000);
          
          // Add click handler
          popup.addEventListener('click', handlePopupClick);
        }
      });
    })();

    function handlePopupClick() {
      const studentId = '<?php echo urlencode($studentId); ?>';
      window.location.href = `offenses_student_view.php?student_id=${studentId}`;
    }
  </script>
</body>
</html>

