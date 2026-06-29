<?php
$f = 'admin/upcc_case_view.php';
$c = file_get_contents($f);

$pattern = '/let idleTimerInterval = null;\s*\nfunction showIdleWarningModal\(\) \{.*?function hideIdleWarningModal\(\) \{.*?if \(m\) m\.classList\.remove\(\'open\'\);\s*\n\s*\}/s';

$replace = 'let idleTimerInterval = null;
function showIdleWarningModal() {
    let m = document.getElementById(\'idleWarningModal\');
    if (!m) {
        m = document.createElement(\'div\');
        m.id = \'idleWarningModal\';
        // Full screen forced overlay - pure inline style, no CSS dependency
        m.style.cssText = \'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.7);z-index:99999;display:flex;align-items:center;justify-content:center;\';
        m.innerHTML = `
          <div style="background:#fff;border-radius:16px;padding:40px 36px;max-width:420px;width:90%;text-align:center;box-shadow:0 25px 50px rgba(0,0,0,0.4);">
            <div style="font-size:3rem;margin-bottom:1rem;">⚠️</div>
            <h3 style="font-size:1.4rem;font-weight:800;color:#1e293b;margin:0 0 0.75rem;">Are you still there?</h3>
            <p style="color:#64748b;margin:0 0 0.5rem;font-size:0.95rem;">No activity detected for 4 minutes.</p>
            <p style="font-size:1rem;font-weight:700;color:#ef4444;margin:0 0 2rem;">Hearing will auto-pause in <span id="idleTimerCount" style="font-size:1.3rem;background:#fef2f2;padding:2px 10px;border-radius:8px;">60</span> seconds</p>
            <button onclick="imHereClick()" style="background:#2563eb;color:#fff;border:none;border-radius:10px;padding:14px 32px;font-size:1rem;font-weight:700;cursor:pointer;width:100%;transition:background 0.2s;" onmouseover="this.style.background=\'#1d4ed8\'" onmouseout="this.style.background=\'#2563eb\'">✅ Yes, I\'m still here!</button>
          </div>
        `;
        document.body.appendChild(m);
    }
    m.style.display = \'flex\';

    // Live countdown
    const countSpan = document.getElementById(\'idleTimerCount\');
    if (idleTimerInterval) clearInterval(idleTimerInterval);
    idleTimerInterval = setInterval(() => {
        const elapsed = Date.now() - lastActivityTime;
        const remaining = Math.max(0, Math.ceil((300000 - elapsed) / 1000));
        if (countSpan) countSpan.textContent = remaining;
        if (remaining <= 0) clearInterval(idleTimerInterval);
    }, 500);
}

function hideIdleWarningModal() {
    if (idleTimerInterval) clearInterval(idleTimerInterval);
    const m = document.getElementById(\'idleWarningModal\');
    if (m) m.style.display = \'none\';
}';

$c = preg_replace($pattern, $replace, $c, 1, $count);
echo "Replacements: $count\n";
file_put_contents($f, $c);
echo "Done.";
