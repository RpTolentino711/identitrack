<?php
$f = 'admin/upcc_case_view.php';
$c = file_get_contents($f);

$floatingCode = "
// Floating Idle Timer
let idleDisplayInterval = null;
function initFloatingIdleTimer() {
    let t = document.getElementById('floatingIdleTimer');
    if (!t) {
        t = document.createElement('div');
        t.id = 'floatingIdleTimer';
        t.style.position = 'fixed';
        t.style.bottom = '20px';
        t.style.right = '20px';
        t.style.background = '#1e293b';
        t.style.color = '#f8fafc';
        t.style.padding = '8px 16px';
        t.style.borderRadius = '9999px';
        t.style.zIndex = '9999';
        t.style.fontFamily = 'var(--font), sans-serif';
        t.style.fontSize = '13px';
        t.style.fontWeight = '600';
        t.style.pointerEvents = 'none';
        t.style.boxShadow = '0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06)';
        t.style.border = '1px solid #334155';
        t.style.display = 'flex';
        t.style.alignItems = 'center';
        t.style.gap = '8px';
        document.body.appendChild(t);
    }
    
    if (idleDisplayInterval) clearInterval(idleDisplayInterval);
    idleDisplayInterval = setInterval(() => {
        const elapsed = Date.now() - lastActivityTime;
        const totalIdleAllowed = 300;
        let remaining = Math.max(0, totalIdleAllowed - Math.floor(elapsed / 1000));
        
        let min = Math.floor(remaining / 60);
        let sec = remaining % 60;
        
        let color = '#10b981'; // Green
        if (remaining <= 120) color = '#f59e0b'; // Yellow
        if (remaining <= 60) color = '#ef4444'; // Red
        
        t.innerHTML = `
            <div style=\"width:8px;height:8px;border-radius:50%;background:\${color};box-shadow: 0 0 8px \${color}\"></div>
            Auto-Pause In: \${min}:\${sec.toString().padStart(2, '0')}
        `;
    }, 1000);
}
initFloatingIdleTimer();
</script>
";

$c = str_replace('</script>', $floatingCode, $c);
file_put_contents($f, $c);
echo "Added floating timer.";
