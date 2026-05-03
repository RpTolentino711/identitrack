<?php
// Reusable admin header branding + notifications/profile actions (AJAX live badge)

$admin = function_exists('admin_current') ? admin_current() : null;
$adminId = (int)($admin['admin_id'] ?? $admin['id'] ?? 0);
$currentPage = strtolower((string)basename((string)($_SERVER['PHP_SELF'] ?? '')));
$isProfilePage = ($currentPage === 'profile.php');

$headerProfilePhoto = '';
if ($adminId > 0 && function_exists('db_one')) {
  $photoRow = db_one("SELECT photo_path FROM admin_user WHERE admin_id = ? LIMIT 1", [$adminId]);
  $headerProfilePhoto = trim((string)($photoRow['photo_path'] ?? ''));
}

if ($headerProfilePhoto === '') {
  $headerProfilePhoto = trim((string)($admin['photo_path'] ?? $admin['photo'] ?? $admin['profile_photo'] ?? ''));
}

// Initial unread count (same logic as poll)
$unreadCount = 0;
if (function_exists('db_one')) {
  $row = db_one(
    "SELECT COUNT(*) AS cnt
     FROM notification
     WHERE is_deleted = 0
       AND is_read = 0
       AND (admin_id IS NULL OR admin_id <> ?)",
    [$adminId]
  );
  $notifCount = (int)($row['cnt'] ?? 0);

  $guardRow = db_one(
    "SELECT COUNT(*) AS cnt
     FROM guard_violation_report
     WHERE status = 'PENDING' AND is_deleted = 0"
  );
  $pendingGuardCount = (int)($guardRow['cnt'] ?? 0);

  // User request: Only include guard reports in badge if NOT on dashboard.php
  if ($currentPage === 'dashboard.php') {
    $unreadCount = $notifCount;
  } else {
    $unreadCount = $notifCount + $pendingGuardCount;
  }
}
?>
<style>
  .admin-header {
    width: 100%;
    background: linear-gradient(180deg, #3b4aa6 0%, #314094 100%);
    border-bottom: 1px solid rgba(255, 255, 255, 0.14);
    box-shadow: 0 8px 20px rgba(8, 16, 48, 0.25);
  }

  .admin-header-inner {
    max-width: none;
    margin: 0;
    padding: 10px 20px 10px 12px;
    display: flex;
    align-items: center;
    gap: 10px;
  }

  .admin-header-left {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 0;
  }

  .admin-header-right {
    margin-left: auto;
    display: flex;
    align-items: center;
    gap: 8px;
    padding-right: 4px;
  }

  .admin-header-logo {
    width: 52px;
    height: 52px;
    object-fit: contain;
    flex-shrink: 0;
  }

  .admin-header-text {
    display: grid;
    gap: 1px;
    line-height: 1;
    font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
    min-width: 0;
  }

  .admin-header-title {
    margin: 0;
    color: #ffffff;
    font-size: 2rem;
    font-weight: 600;
    letter-spacing: 0;
  }

  .admin-header-subtitle {
    margin: 0;
    color: #c7d4ff;
    font-size: 0.95rem;
    font-weight: 400;
  }

  .header-icon-btn {
    position: relative;
    width: 28px;
    height: 28px;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: transparent;
    border: 0;
    text-decoration: none;
    color: #fff;
    transition: .15s ease;
    flex-shrink: 0;
  }

  .header-icon-btn:hover {
    opacity: 0.9;
    transform: translateY(-1px);
  }

  .header-icon-btn:focus {
    outline: 3px solid rgba(255,255,255,0.28);
    outline-offset: 2px;
  }

  .header-icon-btn svg {
    width: 22px;
    height: 22px;
    stroke: currentColor;
    fill: none;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
  }

  .header-icon-btn.has-unread {
    animation: notifBellPulse 1.2s ease-in-out infinite;
  }

  .header-icon-btn.has-unread.red svg {
    stroke: #dc3545;
    filter: drop-shadow(0 0 4px rgba(220, 53, 69, 0.8));
  }

  .notif-badge {
    position: absolute;
    top: -2px;
    right: -2px;
    min-width: 18px;
    height: 18px;
    padding: 0 5px;
    border-radius: 999px;
    background: #dc3545;
    color: #fff;
    font-size: 10px;
    font-weight: 800;
    line-height: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    border: 2px solid #314094;
  }

  .notif-badge.blink {
    animation: notifBadgeBlink 1s ease-in-out infinite;
  }

  @keyframes notifBadgeBlink {
    0%, 100% {
      box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.9), 0 0 10px 2px rgba(220, 53, 69, 0.75);
      filter: brightness(1);
    }
    50% {
      box-shadow: 0 0 0 4px rgba(220, 53, 69, 0.2), 0 0 16px 4px rgba(220, 53, 69, 0.95);
      filter: brightness(1.15);
    }
  }

  @keyframes notifBellPulse {
    0%, 100% {
      transform: scale(1);
      filter: drop-shadow(0 0 0 rgba(255, 77, 96, 0));
    }
    50% {
      transform: scale(1.05);
      filter: drop-shadow(0 0 6px rgba(255, 77, 96, 0.75));
    }
  }

  /* ── DROPDOWN ── */
  .notif-dropdown {
    position: absolute;
    top: calc(100% + 12px);
    right: -10px;
    width: 320px;
    background: #fff;
    border-radius: 16px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
    border: 1px solid #e2e8f0;
    z-index: 1000;
    display: none;
    flex-direction: column;
    overflow: hidden;
    animation: dropdownFadeIn .2s ease-out;
  }
  .notif-dropdown.show { display: flex; }

  @keyframes dropdownFadeIn {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
  }

  .notif-dropdown-header {
    padding: 14px 16px;
    background: #f8fafc;
    border-bottom: 1px solid #e2e8f0;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .notif-dropdown-header h3 {
    margin: 0; font-size: 14px; font-weight: 700; color: #1e293b;
  }
  .notif-dropdown-body {
    max-height: 360px;
    overflow-y: auto;
  }
  .notif-item {
    padding: 12px 16px;
    border-bottom: 1px solid #f1f5f9;
    display: flex;
    gap: 12px;
    transition: background 0.2s;
    cursor: pointer;
    text-decoration: none;
    color: inherit;
  }
  .notif-item:hover { background: #f8fafc; }
  .notif-item-icon {
    width: 36px; height: 36px; border-radius: 10px;
    background: #eff6ff; color: #3b82f6;
    display: grid; place-items: center; flex-shrink: 0;
  }
  .notif-item-content { flex: 1; min-width: 0; }
  .notif-item-title { font-size: 13px; font-weight: 600; color: #1e293b; margin-bottom: 2px; }
  .notif-item-desc { font-size: 12px; color: #64748b; line-height: 1.4; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
  .notif-item-time { font-size: 10px; color: #94a3b8; margin-top: 4px; }

  .notif-empty { padding: 30px 20px; text-align: center; color: #94a3b8; font-size: 13px; }
  .notif-footer { padding: 10px; text-align: center; border-top: 1px solid #f1f5f9; background: #fff; }
  .notif-footer a { font-size: 12px; font-weight: 600; color: #3b4aa6; text-decoration: none; }
  .notif-footer a:hover { text-decoration: underline; }

  .header-right-item { position: relative; }

  .profile-link {
    height: 36px;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    text-decoration: none;
    color: #fff;
    padding: 0 2px;
    border-radius: 999px;
    transition: .15s ease;
  }

  .profile-link:hover {
    background: rgba(255,255,255,0.08);
  }

  .profile-link:focus {
    outline: 3px solid rgba(255,255,255,0.28);
    outline-offset: 2px;
  }

  .profile-avatar {
    width: 32px;
    height: 32px;
    border-radius: 999px;
    background: #ffd23f;
    color: #2f2f2f;
    display: grid;
    place-items: center;
    overflow: hidden;
  }

  .profile-avatar.has-photo {
    background: #ffffff;
  }

  .profile-avatar img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  .profile-avatar svg {
    width: 20px;
    height: 20px;
    stroke: currentColor;
    fill: none;
    stroke-width: 2;
    stroke-linecap: round;
    stroke-linejoin: round;
  }

  .profile-caret {
    width: 17px;
    height: 17px;
    color: #fff;
  }

  .profile-caret svg {
    width: 17px;
    height: 17px;
    stroke: currentColor;
    fill: none;
    stroke-width: 2.25;
    stroke-linecap: round;
    stroke-linejoin: round;
  }

  /* ── NOTIFICATION TOASTS ── */
  .notif-container {
    position: fixed;
    bottom: 24px;
    right: 24px;
    z-index: 9999;
    display: flex;
    flex-direction: column;
    gap: 12px;
    align-items: flex-end;
    pointer-events: none; /* Let clicks pass through container */
  }

  .notif-popup {
    background: #ffffff;
    border: 1px solid #dce4f5;
    border-radius: 14px;
    padding: 18px 20px;
    box-shadow: 0 12px 40px rgba(8, 16, 48, 0.25);
    width: 380px;
    max-width: calc(100vw - 32px);
    animation: popupSlideIn .4s cubic-bezier(.16,1,.3,1) both;
    display: flex;
    flex-direction: column;
    gap: 10px;
    pointer-events: auto; /* Re-enable clicks for the toast itself */
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
  }

  .notif-popup-timer-bar {
    height: 100%;
    background: #dc3545;
    border-radius: 999px;
    animation: timerCountdown 8s linear forwards;
  }

  @keyframes timerCountdown {
    from { width: 100%; }
    to { width: 0%; }
  }

  @media (max-width: 640px) {
    .admin-header-inner { padding: 8px 14px 8px 10px; }
    .admin-header-logo { width: 42px; height: 42px; }
    .admin-header-title { font-size: 1.06rem; }
    .admin-header-subtitle { font-size: 0.76rem; }
    .header-icon-btn,
    .profile-avatar { width: 29px; height: 29px; }
    .notif-container {
      bottom: 16px;
      right: 16px;
    }
    .notif-popup {
      width: calc(100vw - 32px);
    }
  }
</style>

<div style="background:red; color:white; padding:10px; font-size:20px; text-align:center; z-index:99999; font-weight:bold;">
  GLOBAL DEBUG: CODE UPDATED - IF YOU SEE THIS, IT IS LIVE.
</div>
<header class="admin-header">

  <div class="admin-header-inner">
    <div class="admin-header-left">
      <img class="admin-header-logo" src="../assets/logo.png" alt="NU Logo">

      <div class="admin-header-text">
        <p class="admin-header-title">SDO Portal</p>
        <p class="admin-header-subtitle">Discipline Office</p>
      </div>
    </div>

    <div class="admin-header-right">
      <div class="header-right-item">
        <button class="header-icon-btn <?php echo $unreadCount > 0 ? 'has-unread' : ''; ?>" id="notifBell" aria-label="Notifications">
          <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7"></path>
            <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
          </svg>

          <span
            class="notif-badge <?php echo $unreadCount > 0 ? 'blink' : ''; ?>"
            id="notifBadge"
            style="<?php echo $unreadCount > 0 ? '' : 'display:none;'; ?>"
            aria-label="<?php echo (int)$unreadCount; ?> unread notifications"
          ><?php echo (int)$unreadCount; ?></span>
        </button>

        <!-- Dropdown Panel -->
        <div class="notif-dropdown" id="notifDropdown">
          <div class="notif-dropdown-header">
            <h3>Notifications & Reports</h3>
            <span id="notifCountLabel" style="font-size: 11px; color: #64748b;"></span>
          </div>
          <div class="notif-dropdown-body" id="notifList">
            <div class="notif-empty">Loading violation reports...</div>
          </div>
          <div class="notif-footer">
            <a href="notifications.php">View All Notifications</a>
          </div>
        </div>
      </div>

      <?php if (!$isProfilePage): ?>
        <a class="profile-link" href="profile.php" aria-label="Profile">
          <span class="profile-avatar <?php echo $headerProfilePhoto !== '' ? 'has-photo' : ''; ?>" aria-hidden="true">
            <?php if ($headerProfilePhoto !== ''): ?>
              <img src="<?php echo e($headerProfilePhoto); ?>?v=<?php echo urlencode((string)time()); ?>" alt="Admin profile" />
            <?php else: ?>
              <svg viewBox="0 0 24 24">
                <circle cx="12" cy="8" r="4"></circle>
                <path d="M4 21c0-4 3.5-7 8-7s8 3 8 7"></path>
              </svg>
            <?php endif; ?>
          </span>
          <span class="profile-caret" aria-hidden="true">
            <svg viewBox="0 0 24 24">
              <path d="M6 9l6 6 6-6"></path>
            </svg>
          </span>
        </a>
      <?php endif; ?>
    </div>
  </div>
</header>

<!-- NOTIFICATION CONTAINER -->
<div class="notif-container" id="notifContainer"></div>

<script>
  (function() {
    const isDashboard = window.location.pathname.toLowerCase().includes('dashboard.php');
    const badge = document.getElementById('notifBadge');
    const bell = document.getElementById('notifBell');
    const container = document.getElementById('notifContainer');
    if (!badge || !container) return;

    let lastNotifId = 0;

    function createToast(title, message) {
      const popup = document.createElement('div');
      popup.className = 'notif-popup';
      popup.innerHTML = `
        <div class="notif-popup-header">
          <div class="notif-popup-icon">
            <svg viewBox="0 0 24 24" aria-hidden="true">
              <path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
          </div>
          <div>
            <p class="notif-popup-title">${title}</p>
            <p class="notif-popup-message">${message}</p>
          </div>
        </div>
        <div class="notif-popup-timer">
          <div class="notif-popup-timer-bar"></div>
        </div>
      `;
      container.appendChild(popup);

      setTimeout(() => {
        popup.classList.add('hide');
        setTimeout(() => {
          if (popup.parentNode === container) {
            container.removeChild(popup);
          }
        }, 400); // Wait for slide out animation
      }, 8000);
    }

    const dropdown = document.getElementById('notifDropdown');
    const notifList = document.getElementById('notifList');
    const countLabel = document.getElementById('notifCountLabel');

    let dropdownOpen = false;

    bell.addEventListener('click', (e) => {
      e.stopPropagation();
      dropdownOpen = !dropdownOpen;
      dropdown.classList.toggle('show', dropdownOpen);
      if (dropdownOpen) {
        fetchPendingReports();
      }
    });

    document.addEventListener('click', () => {
      if (dropdownOpen) {
        dropdownOpen = false;
        dropdown.classList.remove('show');
      }
    });

    dropdown.addEventListener('click', (e) => e.stopPropagation());

    async function fetchPendingReports() {
      notifList.innerHTML = '<div class="notif-empty">Loading pending reports...</div>';
      try {
        const res = await fetch('AJAX/get_pending_guard_reports.php');
        const json = await res.json();
        if (json.ok && json.reports.length > 0) {
          renderReports(json.reports);
          countLabel.textContent = json.reports.length + ' pending';
        } else {
          notifList.innerHTML = '<div class="notif-empty">No pending violation reports</div>';
          countLabel.textContent = '0 pending';
        }
      } catch (e) {
        notifList.innerHTML = '<div class="notif-empty">Error loading reports</div>';
      }
    }

    function renderReports(reports) {
      notifList.innerHTML = reports.map(r => {
        if (r.item_type === 'VIOLATION') {
          return `
            <a href="dashboard.php?open_report_id=${r.report_id}" class="notif-item">
              <div class="notif-item-icon" style="background:#fff7ed; color:#f97316;">
                <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
              </div>
              <div class="notif-item-content">
                <div class="notif-item-title">Violation Report: ${esc(r.student_name)}</div>
                <div class="notif-item-desc">${esc(r.offense_name || 'Violation report')} - ${esc(r.description || '')}</div>
                <div class="notif-item-time">${formatDate(r.created_at)}</div>
              </div>
            </a>
          `;
        } else {
          return `
            <a href="notifications.php" class="notif-item">
              <div class="notif-item-icon" style="background:#eff6ff; color:#3b82f6;">
                <svg viewBox="0 0 24 24" style="width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:2;"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 7h18s-3 0-3-7"></path><path d="M13.73 21a2 2 0 0 1-3.46 0"></path></svg>
              </div>
              <div class="notif-item-content">
                <div class="notif-item-title">${esc(r.title || 'Notification')}</div>
                <div class="notif-item-desc">${esc(r.message || '')}</div>
                <div class="notif-item-time">${formatDate(r.created_at)}</div>
              </div>
            </a>
          `;
        }
      }).join('');
    }

    function formatDate(ds) {
      const d = new Date(ds);
      return d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) + ' ' + d.toLocaleDateString();
    }

    function esc(s) {
      if (!s) return '';
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    let lastPendingGuardCount = 0;

    async function poll(){
      try{
        const res = await fetch('AJAX/notifications_poll.php?last_id=' + lastNotifId, {
          headers: { 'Accept': 'application/json' },
          cache: 'no-store'
        });
        if(!res.ok) return;

        const json = await res.json();
        if(!json || !json.ok) return;

        let unread = Number(json.unread || 0);
        const newNotifs = json.new_notifications || [];
        const pendingGuards = Number(json.pending_guard_count || 0);

        // Subtract pending guard count if on dashboard
        if (isDashboard) {
            unread = Math.max(0, unread - pendingGuards);
        }

        // Show toast for new notifications
        if (newNotifs.length > 0) {
          let highestId = lastNotifId;
          newNotifs.forEach(n => {
            const id = Number(n.notification_id || 0);
            if (id > highestId) highestId = id;
            if (lastNotifId > 0 && !isDashboard) {
              const title = n.title || 'New Offense Report';
              const message = n.message || 'A new violation report has been filed.';
              createToast(title, message);
            }
          });
          lastNotifId = highestId;
        }

        // Show toast for new pending guard submissions
        if (pendingGuards > lastPendingGuardCount) {
          if ((lastPendingGuardCount > 0 || lastNotifId > 0) && !isDashboard) {
            createToast('Violation Report', `There are ${pendingGuards} pending violation reports awaiting review.`);
          }
          lastPendingGuardCount = pendingGuards;
          if (dropdownOpen) fetchPendingReports();
        } else {
          lastPendingGuardCount = pendingGuards;
        }

        if (unread > 0) {
          badge.style.display = 'flex';
          badge.textContent = unread > 99 ? '99+' : String(unread);
          badge.setAttribute('aria-label', unread + ' unread notifications');
          badge.classList.add('blink');
          if (bell) bell.classList.add('has-unread', 'red');
        } else {
          badge.style.display = 'none';
          badge.textContent = '0';
          badge.setAttribute('aria-label', '0 unread notifications');
          badge.classList.remove('blink');
          if (bell) bell.classList.remove('has-unread', 'red');
        }
      } catch(e){ }
    }

    window.refreshNotifications = poll;
    document.addEventListener('refreshNotifications', poll);

    poll();
    setInterval(poll, 5000);
  })();
</script>

