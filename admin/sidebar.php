<?php
// Reusable admin sidebar.
$activeSidebar = isset($activeSidebar) ? (string)$activeSidebar : 'dashboard';
$pendingGuardReports = 0;
$pendingAppeals = 0;
if (function_exists('db_one')) {
  $row = db_one("SELECT COUNT(*) AS cnt FROM guard_violation_report WHERE status = 'PENDING' AND is_deleted = 0");
  $pendingGuardReports = (int)($row['cnt'] ?? 0);

  if (db_one("SHOW TABLES LIKE 'student_appeal_request'")) {
    $appealRow = db_one("SELECT COUNT(*) AS cnt FROM student_appeal_request WHERE status IN ('PENDING','REVIEWING')");
    $pendingAppeals = (int)($appealRow['cnt'] ?? 0);
  }

  $pendingCommunityService = 0;
  if (db_one("SHOW TABLES LIKE 'manual_login_request'")) {
    $csRow = db_one("SELECT COUNT(*) AS cnt FROM manual_login_request WHERE status = 'PENDING'");
    $pendingCommunityService = (int)($csRow['cnt'] ?? 0);
  }
}
?>
<style>
  .admin-sidebar {
    width: 240px;
    min-height: calc(100vh - 72px);
    background: #f8f9fa;
    border-right: 1px solid #dee2e6;
    display: flex;
    flex-direction: column;
    padding: 16px 0;
  }

  .admin-sidebar-menu {
    list-style: none;
    margin: 0;
    padding: 0 12px;
  }

  .admin-sidebar-link {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 16px;
    color: #495057;
    text-decoration: none;
    font-size: 14px;
    font-weight: 500;
    border-radius: 6px;
    transition: all 0.15s ease;
    margin-bottom: 4px;
  }

  .admin-sidebar-link:hover {
    background: #e9ecef;
    color: #212529;
  }

  .admin-sidebar-link.active {
    background: #5862ad;
    color: #FFB81C;
  }

  .admin-sidebar-icon {
    width: 20px;
    height: 20px;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  .admin-sidebar-icon svg {
    width: 20px;
    height: 20px;
    stroke: currentColor;
    fill: none;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
  }

  .admin-sidebar-count {
    margin-left: auto;
    min-width: 20px;
    height: 20px;
    padding: 0 6px;
    border-radius: 999px;
    background: #dc3545;
    color: #fff;
    font-size: 11px;
    font-weight: 700;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
  }

  .admin-sidebar-disabled {
    opacity: 0.4;
    cursor: not-allowed;
    pointer-events: none;
  }

  .admin-sidebar-disabled:hover {
    background: transparent;
  }

  .admin-sidebar-bottom {
    margin-top: auto;
    padding-top: 16px;
    border-top: 1px solid #dee2e6;
  }

  .sidebar-divider {
    height: 1px;
    background: #dee2e6;
    margin: 12px 12px;
  }

  .logout-modal-overlay {
    position: fixed;
    inset: 0;
    background: rgba(9, 16, 48, 0.5);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 999;
    padding: 16px;
  }

  .logout-modal-overlay.show {
    display: flex;
  }

  .global-scan-overlay {
    position: fixed;
    inset: 0;
    background: rgba(15, 23, 42, .55);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 1600;
    padding: 16px;
  }

  .global-scan-overlay.show {
    display: flex;
  }

  .global-scan-card {
    width: min(420px, 96vw);
    background: #fff;
    border: 1px solid #e5e7eb;
    border-radius: 14px;
    box-shadow: 0 20px 50px rgba(0, 0, 0, .22);
    padding: 22px;
    text-align: center;
  }

  .global-scan-logo-wrap {
    width: 72px;
    height: 72px;
    border-radius: 50%;
    margin: 0 auto 10px;
    background: #eff6ff;
    border: 1px solid #bfdbfe;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  .global-scan-logo {
    width: 48px;
    height: 48px;
    object-fit: contain;
    display: block;
  }

  .global-scan-card.loading .global-scan-logo-wrap {
    animation: globalScanLogoPulse 1.1s ease-in-out infinite;
  }

  @keyframes globalScanLogoPulse {
    0%, 100% {
      transform: scale(1);
      box-shadow: 0 0 0 0 rgba(37, 99, 235, .25);
    }
    50% {
      transform: scale(1.04);
      box-shadow: 0 0 0 8px rgba(37, 99, 235, 0);
    }
  }

  .global-scan-spinner {
    width: 44px;
    height: 44px;
    border: 3px solid #dbeafe;
    border-top-color: #2563eb;
    border-radius: 50%;
    margin: 0 auto 14px;
    animation: globalScanSpin .8s linear infinite;
    display: none;
  }

  @keyframes globalScanSpin {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
  }

  .global-scan-card.loading .global-scan-spinner {
    display: block;
  }

  .global-scan-title {
    margin: 0;
    font-size: 18px;
    font-weight: 700;
    color: #111827;
  }

  .global-scan-subtitle {
    margin: 8px 0 0;
    font-size: 13px;
    color: #64748b;
    line-height: 1.45;
  }

  .global-scan-student {
    margin-top: 12px;
    border: 1px solid #dbeafe;
    background: #eff6ff;
    border-radius: 10px;
    padding: 10px 12px;
    display: none;
    text-align: left;
  }

  .global-scan-student.show {
    display: block;
  }

  .global-scan-student .nm {
    font-size: 14px;
    font-weight: 700;
    color: #1e3a8a;
  }

  .global-scan-student .sid {
    font-size: 12px;
    color: #334155;
    margin-top: 2px;
    font-family: 'Consolas', 'Courier New', monospace;
  }

  .global-scan-card.error .global-scan-title {
    color: #b91c1c;
  }

  .global-scan-card.error .global-scan-subtitle {
    color: #991b1b;
  }

  .logout-modal {
    width: min(400px, 95vw);
    background: #ffffff;
    border-radius: 14px;
    border: 1px solid #dfe4f2;
    box-shadow: 0 18px 38px rgba(9, 16, 48, 0.26);
    padding: 18px;
  }

  .logout-modal h3 {
    margin: 0;
    font-size: 1.1rem;
    color: #2b377f;
  }

  .logout-modal p {
    margin: 10px 0 0;
    color: #5e678f;
    font-size: 0.95rem;
    line-height: 1.45;
  }

  .logout-modal-actions {
    margin-top: 18px;
    display: flex;
    justify-content: flex-end;
    gap: 10px;
  }

  .logout-btn {
    border: 0;
    border-radius: 8px;
    height: 38px;
    padding: 0 14px;
    font-size: 0.9rem;
    font-weight: 600;
    cursor: pointer;
  }

  .logout-btn-cancel {
    background: #eceff6;
    color: #2f3a83;
  }

  .logout-btn-confirm {
    background: #d83838;
    color: #ffffff;
  }

  @media (max-width: 900px) {
    .admin-sidebar {
      width: 100%;
      min-height: auto;
      border-right: 0;
      border-bottom: 1px solid #dee2e6;
    }

    .admin-sidebar-menu {
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
    }

    .admin-sidebar-link {
      margin-bottom: 0;
    }

    .admin-sidebar-bottom {
      margin-top: 0;
      border-top: 1px solid #dee2e6;
      padding-top: 12px;
    }

    .sidebar-divider {
      display: none;
    }
  }
</style>

<aside class="admin-sidebar" aria-label="Admin sidebar navigation">
  <ul class="admin-sidebar-menu">
    <li>
      <a class="admin-sidebar-link <?php echo $activeSidebar === 'dashboard' ? 'active' : ''; ?>" href="dashboard.php">
        <span class="admin-sidebar-icon">
          <svg viewBox="0 0 24 24">
            <rect x="3" y="3" width="7" height="7" rx="1"></rect>
            <rect x="14" y="3" width="7" height="7" rx="1"></rect>
            <rect x="14" y="14" width="7" height="7" rx="1"></rect>
            <rect x="3" y="14" width="7" height="7" rx="1"></rect>
          </svg>
        </span>
        <span>Dashboard</span>
        <?php if ($pendingGuardReports > 0): ?>
          <span class="admin-sidebar-count"><?php echo (int)$pendingGuardReports; ?></span>
        <?php endif; ?>
      </a>
    </li>
    <li>
      <a class="admin-sidebar-link <?php echo $activeSidebar === 'offenses' ? 'active' : ''; ?>" href="offenses.php">
        <span class="admin-sidebar-icon">
          <svg viewBox="0 0 24 24">
            <path d="M3 8a2 2 0 0 1 2-2h5l2 2h7a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"></path>
          </svg>
        </span>
        <span>Offenses</span>
      </a>
    </li>

    <li>
      <a class="admin-sidebar-link <?php echo $activeSidebar === 'upcc' ? 'active' : ''; ?>" href="upcc_cases.php">
        <span class="admin-sidebar-icon">
          <svg viewBox="0 0 24 24">
            <rect x="4" y="3" width="16" height="18" rx="2"></rect>
            <path d="M4 14h16"></path>
          </svg>
        </span>
        <span>UPCC Cases</span>
      </a>
    </li>

    <li>
      <a class="admin-sidebar-link <?php echo $activeSidebar === 'appeals' ? 'active' : ''; ?>" href="appeals.php">
        <span class="admin-sidebar-icon">
          <svg viewBox="0 0 24 24">
            <path d="M12 3v18"></path>
            <path d="M5 8h14"></path>
            <path d="M5 16h14"></path>
          </svg>
        </span>
        <span>Appeals</span>
        <?php if ($pendingAppeals > 0): ?>
          <span class="admin-sidebar-count"><?php echo (int)$pendingAppeals; ?></span>
        <?php endif; ?>
      </a>
    </li>

    <li>
      <a class="admin-sidebar-link <?php echo $activeSidebar === 'community' ? 'active' : ''; ?>" href="community_service.php">
        <span class="admin-sidebar-icon">
          <svg viewBox="0 0 24 24">
            <circle cx="9" cy="8" r="3"></circle>
            <circle cx="17" cy="8" r="3"></circle>
            <path d="M2 20c0-3 3-5 7-5"></path>
            <path d="M12 20c0-3 3-5 7-5"></path>
          </svg>
        </span>
        <span>Community Service</span>
        <span class="admin-sidebar-count" style="background:#dc3545; animation: pulse 2s infinite; display: <?php echo $pendingCommunityService > 0 ? 'inline-flex' : 'none'; ?>;">
            <?php echo (int)$pendingCommunityService; ?>
        </span>
      </a>
    </li>

    <div class="sidebar-divider"></div>

    <li>
      <a class="admin-sidebar-link <?php echo $activeSidebar === 'reports' ? 'active' : ''; ?>" href="reports.php">
        <span class="admin-sidebar-icon">
          <svg viewBox="0 0 24 24">
            <rect x="5" y="5" width="14" height="16" rx="2"></rect>
            <path d="M9 5v-2h6v2"></path>
          </svg>
        </span>
        <span>Reports</span>
      </a>
    </li>

    <li>
      <a class="admin-sidebar-link <?php echo $activeSidebar === 'notifications' ? 'active' : ''; ?>" href="notifications.php">
        <span class="admin-sidebar-icon">
          <svg viewBox="0 0 24 24">
            <path d="M15 17H9"></path>
            <path d="M18 10a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7"></path>
            <path d="M10 21a2 2 0 0 0 4 0"></path>
          </svg>
        </span>
        <span>Audit</span>
      </a>
    </li>

    <li>
      <a class="admin-sidebar-link <?php echo $activeSidebar === 'profile' ? 'active' : ''; ?>" href="profile.php">
        <span class="admin-sidebar-icon">
          <svg viewBox="0 0 24 24">
            <circle cx="12" cy="8" r="4"></circle>
            <path d="M4 21c0-4 3.5-7 8-7s8 3 8 7"></path>
          </svg>
        </span>
        <span>Profile</span>
      </a>
    </li>

    <li>
      <a class="admin-sidebar-link <?php echo $activeSidebar === 'settings' ? 'active' : ''; ?>" href="settings.php">
        <span class="admin-sidebar-icon">
          <svg viewBox="0 0 24 24">
            <circle cx="12" cy="12" r="1.5"></circle>
            <path d="M7 12a5 5 0 0 1 5-5"></path>
            <path d="M17 12a5 5 0 0 0-5-5"></path>
            <path d="M4 12a8 8 0 0 1 8-8"></path>
            <path d="M20 12a8 8 0 0 0-8-8"></path>
          </svg>
        </span>
        <span>NFC</span>
      </a>
    </li>
  </ul>

  <div class="admin-sidebar-bottom">
    <ul class="admin-sidebar-menu">
      <li>
        <a
          class="admin-sidebar-link <?php echo $activeSidebar === 'logout' ? 'active' : ''; ?>"
          href="logout.php"
          id="sidebarLogoutLink"
        >
          <span class="admin-sidebar-icon">
            <svg viewBox="0 0 24 24">
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
              <polyline points="16 17 21 12 16 7"></polyline>
              <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
          </span>
          <span>Log Out</span>
        </a>
      </li>
    </ul>
  </div>
</aside>

<div class="logout-modal-overlay" id="logoutModalOverlay" aria-hidden="true">
  <div class="logout-modal" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle" aria-describedby="logoutModalText">
    <h3 id="logoutModalTitle">Confirm Log Out</h3>
    <p id="logoutModalText">Are you sure you want to log out?</p>

    <div class="logout-modal-actions">
      <button type="button" class="logout-btn logout-btn-cancel" id="logoutCancelBtn">Cancel</button>
      <button type="button" class="logout-btn logout-btn-confirm" id="logoutConfirmBtn">Yes, Log Out</button>
    </div>
  </div>
</div>

<script>
  (function () {
    var logoutLink = document.getElementById('sidebarLogoutLink');
    var modal = document.getElementById('logoutModalOverlay');
    var cancelBtn = document.getElementById('logoutCancelBtn');
    var confirmBtn = document.getElementById('logoutConfirmBtn');
    var previousActiveLink = null;

    if (!logoutLink || !modal || !cancelBtn || !confirmBtn) return;

    function setActiveLink(linkEl) {
      var activeLinks = document.querySelectorAll('.admin-sidebar-link.active');
      activeLinks.forEach(function (el) {
        el.classList.remove('active');
      });

      if (linkEl) {
        linkEl.classList.add('active');
      }
    }

    function openModal() {
      previousActiveLink = document.querySelector('.admin-sidebar-link.active');
      setActiveLink(logoutLink);
      modal.classList.add('show');
      modal.setAttribute('aria-hidden', 'false');
      cancelBtn.focus();
    }

    function closeModal() {
      modal.classList.remove('show');
      modal.setAttribute('aria-hidden', 'true');
      setActiveLink(previousActiveLink);
      logoutLink.focus();
    }

    logoutLink.addEventListener('click', function (event) {
      event.preventDefault();
      openModal();
    });

    cancelBtn.addEventListener('click', function () {
      closeModal();
    });

    confirmBtn.addEventListener('click', function () {
      window.location.href = logoutLink.getAttribute('href') || 'logout.php';
    });

    modal.addEventListener('click', function (event) {
      if (event.target === modal) {
        closeModal();
      }
    });

    document.addEventListener('keydown', function (event) {
      if (event.key === 'Escape' && modal.classList.contains('show')) {
        closeModal();
      }
    });
  })();
</script>

<script>
  (function () {
    if (window.__identitrackGlobalScannerInit) return;
    window.__identitrackGlobalScannerInit = true;

    if (window.__identitrackDisableGlobalScanner) return;

    // Dashboard currently has a dedicated scanner UI/script. Skip duplicate init there.
    if (document.getElementById('scanOverlay')) return;

    function buildOverlay() {
      var existing = document.getElementById('globalScanOverlay');
      if (existing) return existing;

      var wrap = document.createElement('div');
      wrap.id = 'globalScanOverlay';
      wrap.className = 'global-scan-overlay';
      wrap.setAttribute('aria-hidden', 'true');
      wrap.innerHTML = ''
        + '<div id="globalScanCard" class="global-scan-card loading" role="status" aria-live="polite">'
        + '  <div class="global-scan-logo-wrap">'
        + '    <img class="global-scan-logo" src="../assets/logo.png" alt="IDENTITRACK logo" />'
        + '  </div>'
        + '  <div class="global-scan-spinner" aria-hidden="true"></div>'
        + '  <h3 id="globalScanTitle" class="global-scan-title">Scanning ID...</h3>'
        + '  <p id="globalScanSubtitle" class="global-scan-subtitle">Please wait while student record is being verified.</p>'
        + '  <div id="globalScanStudent" class="global-scan-student">'
        + '    <div id="globalScanStudentName" class="nm"></div>'
        + '    <div id="globalScanStudentId" class="sid"></div>'
        + '  </div>'
        + '</div>';

      document.body.appendChild(wrap);
      return wrap;
    }

    var scanOverlay = buildOverlay();
    var scanCard = document.getElementById('globalScanCard');
    var scanTitle = document.getElementById('globalScanTitle');
    var scanSubtitle = document.getElementById('globalScanSubtitle');
    var scanStudent = document.getElementById('globalScanStudent');
    var scanStudentName = document.getElementById('globalScanStudentName');
    var scanStudentId = document.getElementById('globalScanStudentId');

    var scanBuffer = '';
    var scanTimer = null;
    var scanBusy = false;

    function setOverlay(mode, title, subtitle, studentName, studentId) {
      if (!scanOverlay || !scanCard || !scanTitle || !scanSubtitle || !scanStudent) return;
      scanOverlay.classList.add('show');
      scanOverlay.setAttribute('aria-hidden', 'false');

      scanCard.classList.remove('loading', 'success', 'error');
      scanCard.classList.add(mode);
      scanTitle.textContent = title || '';
      scanSubtitle.textContent = subtitle || '';

      if (studentName || studentId) {
        scanStudent.classList.add('show');
        scanStudentName.textContent = String(studentName || '');
        scanStudentId.textContent = String(studentId || '');
      } else {
        scanStudent.classList.remove('show');
        scanStudentName.textContent = '';
        scanStudentId.textContent = '';
      }
    }

    function hideOverlay() {
      if (!scanOverlay) return;
      scanOverlay.classList.remove('show');
      scanOverlay.setAttribute('aria-hidden', 'true');
    }

    function forceNavigate(targetUrl) {
      var fallback = 'offenses.php';
      var rawTarget = String(targetUrl || fallback);

      try {
        var target = new URL(rawTarget, window.location.href);
        var current = new URL(window.location.href);

        // If target is effectively the current page, append a nonce so browser reloads it.
        if (target.pathname === current.pathname && target.search === current.search) {
          target.searchParams.set('scan_nonce', String(Date.now()));
        }

        window.location.assign(target.toString());
      } catch (_) {
        window.location.assign(rawTarget);
      }
    }

    function handleScannerValue(value) {
      var scanned = String(value || '').trim();
      if (!scanned || scanBusy) return;

      scanBusy = true;
      setOverlay('loading', 'Scanning ID...', 'Please wait while student record is being verified.');

      fetch('AJAX/scan_student_lookup.php?scan=' + encodeURIComponent(scanned), {
        method: 'GET',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (data && data.ok) {
          setOverlay(
            'success',
            'Student Found',
            'Redirecting to offense records...',
            data.student_name || '',
            data.student_id || ''
          );
          setTimeout(function () {
            forceNavigate(data.redirect_url || 'offenses.php');
            // Fallback: if browser does not navigate (same-page edge case), re-arm scanner.
            setTimeout(function () {
              hideOverlay();
              scanBusy = false;
            }, 1200);
          }, 1800);
          return;
        }

        setOverlay('error', 'No Match Found', 'No student record found for scanned ID.');
        setTimeout(function () {
          hideOverlay();
          scanBusy = false;
        }, 1400);
      })
      .catch(function () {
        setOverlay('error', 'Scan Error', 'Unable to process scan right now. Try again.');
        setTimeout(function () {
          hideOverlay();
          scanBusy = false;
        }, 1600);
      });
    }

    function flushScanBuffer() {
      if (scanBusy) return;
      var finalScan = String(scanBuffer || '').trim();
      scanBuffer = '';
      if (scanTimer) {
        clearTimeout(scanTimer);
        scanTimer = null;
      }
      if (finalScan.length >= 6) {
        handleScannerValue(finalScan);
      }
    }

    document.addEventListener('keydown', function (ev) {
      if (scanBusy) return;

      var tgt = ev.target;
      var isTypingTarget = tgt && (
        tgt.tagName === 'INPUT' ||
        tgt.tagName === 'TEXTAREA' ||
        tgt.tagName === 'SELECT' ||
        tgt.isContentEditable
      );
      if (isTypingTarget) return;

      if (ev.key === 'Enter') {
        flushScanBuffer();
        return;
      }

      if (ev.key.length === 1 && !ev.ctrlKey && !ev.altKey && !ev.metaKey) {
        scanBuffer += ev.key;
        if (scanTimer) clearTimeout(scanTimer);
        scanTimer = setTimeout(function () {
          flushScanBuffer();
        }, 180);
      }
    });
  })();
</script>