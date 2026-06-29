<?php
$f = 'admin/upcc_case_view.php';
$c = file_get_contents($f);

$target = "function showIdleWarningModal() {
    let m = document.getElementById('idleWarningModal');
    if (!m) {
        m = document.createElement('div');
        m.id = 'idleWarningModal';
        m.className = 'modal-overlay';
        m.innerHTML = `
          <div class=\"modal-box\">
            <div class=\"modal-header\">
              <h3 class=\"modal-title\">⚠️ Are you still there?</h3>
            </div>
            <div class=\"modal-body\" style=\"text-align:center\">
              <p>The system has detected no activity for 4 minutes.</p>
              <p>If you do not respond, the hearing will be <strong>auto-paused</strong> in 1 minute to secure the session.</p>
            </div>
            <div class=\"modal-footer\" style=\"justify-content:center\">
              <button type=\"button\" class=\"btn btn-primary\" onclick=\"imHereClick()\">Yes, I'm here</button>
            </div>
          </div>
        `;
        document.body.appendChild(m);
    }
    m.classList.add('open');
}

function hideIdleWarningModal() {
    const m = document.getElementById('idleWarningModal');
    if (m) m.classList.remove('open');
}";

$replace = "let idleTimerInterval = null;
function showIdleWarningModal() {
    let m = document.getElementById('idleWarningModal');
    if (!m) {
        m = document.createElement('div');
        m.id = 'idleWarningModal';
        m.className = 'modal-overlay';
        m.innerHTML = `
          <div class=\"modal-content\" style=\"text-align:center; padding: 40px 30px\">
            <div style=\"font-size: 3rem; margin-bottom: 1rem;\">⚠️</div>
            <h3 style=\"font-family: var(--font); font-size: 1.5rem; font-weight: 800; color: #333; margin-bottom: 1rem;\">Are you still there?</h3>
            <p style=\"color: #666; margin-bottom: 0.5rem;\">The system has detected no activity for 4 minutes.</p>
            <p style=\"color: #eab308; font-weight: 600; margin-bottom: 2rem;\">If you do not respond, the hearing will be auto-paused in <span id=\"idleTimerCount\">60</span> seconds.</p>
            <button type=\"button\" class=\"btn btn-primary\" onclick=\"imHereClick()\" style=\"width: 100%; justify-content: center; padding: 12px\">Yes, I'm here</button>
          </div>
        `;
        document.body.appendChild(m);
    }
    
    // Reset and start countdown
    const countSpan = document.getElementById('idleTimerCount');
    if (countSpan) countSpan.textContent = '60';
    if (idleTimerInterval) clearInterval(idleTimerInterval);
    idleTimerInterval = setInterval(() => {
        const elapsed = Date.now() - lastActivityTime;
        const remaining = Math.max(0, 300 - Math.floor(elapsed / 1000));
        if (countSpan) countSpan.textContent = remaining;
    }, 1000);
    
    m.classList.add('open');
}

function hideIdleWarningModal() {
    if (idleTimerInterval) clearInterval(idleTimerInterval);
    const m = document.getElementById('idleWarningModal');
    if (m) m.classList.remove('open');
}";

$c = str_replace($target, $replace, $c);
file_put_contents($f, $c);
echo "Replaced.";
