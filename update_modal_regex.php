<?php
$f = 'admin/upcc_case_view.php';
$c = file_get_contents($f);

$pattern = '/function showIdleWarningModal\(\) \{.*?function hideIdleWarningModal\(\) \{.*?\}/s';

$replace = "let idleTimerInterval = null;\nfunction showIdleWarningModal() {\n    let m = document.getElementById('idleWarningModal');\n    if (!m) {\n        m = document.createElement('div');\n        m.id = 'idleWarningModal';\n        m.className = 'modal-overlay';\n        m.innerHTML = `\n          <div class=\"modal-content\" style=\"text-align:center; padding: 40px 30px\">\n            <div style=\"font-size: 3rem; margin-bottom: 1rem;\">⚠️</div>\n            <h3 style=\"font-family: var(--font); font-size: 1.5rem; font-weight: 800; color: #333; margin-bottom: 1rem;\">Are you still there?</h3>\n            <p style=\"color: #666; margin-bottom: 0.5rem;\">The system has detected no activity for 4 minutes.</p>\n            <p style=\"color: #eab308; font-weight: 600; margin-bottom: 2rem;\">If you do not respond, the hearing will be auto-paused in <span id=\"idleTimerCount\">60</span> seconds.</p>\n            <button type=\"button\" class=\"btn btn-primary\" onclick=\"imHereClick()\" style=\"width: 100%; justify-content: center; padding: 12px\">Yes, I'm here</button>\n          </div>\n        `;\n        document.body.appendChild(m);\n    }\n    \n    // Reset and start countdown\n    const countSpan = document.getElementById('idleTimerCount');\n    if (countSpan) countSpan.textContent = '60';\n    if (idleTimerInterval) clearInterval(idleTimerInterval);\n    idleTimerInterval = setInterval(() => {\n        const elapsed = Date.now() - lastActivityTime;\n        const remaining = Math.max(0, 300 - Math.floor(elapsed / 1000));\n        if (countSpan) countSpan.textContent = remaining;\n    }, 1000);\n    \n    m.classList.add('open');\n}\n\nfunction hideIdleWarningModal() {\n    if (idleTimerInterval) clearInterval(idleTimerInterval);\n    const m = document.getElementById('idleWarningModal');\n    if (m) m.classList.remove('open');\n}";

$c = preg_replace($pattern, $replace, $c);
file_put_contents($f, $c);
echo "Replaced.";
