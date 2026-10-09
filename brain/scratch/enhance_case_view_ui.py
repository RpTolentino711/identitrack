import re

file_path = r"c:\xampp\htdocs\identitrack\UPCC\case_view.php"

with open(file_path, 'r', encoding='utf-8') as f:
    code = f.read()

# 1. Update CSS root and body background for ultra-executive glassmorphism
old_root = re.search(r':root\s*\{[^}]+\}', code)
if old_root:
    new_root = ''':root{
    --bg-dark:#060a12;--bg-card:rgba(15,23,42,.85);--bg-glass:rgba(11,17,31,.9);
    --border-glass:rgba(56,189,248,.18);--border-glass-hover:rgba(56,189,248,.4);
    --accent-primary:#38bdf8;--accent-secondary:#818cf8;--success:#10b981;--warning:#fbbf24;--danger:#f87171;
    --text-main:#f8fafc;--text-sub:#cbd5e1;--text-muted:#64748b;
    --radius-lg:20px;--radius-md:14px;--radius-sm:8px;
    --font-h:'Outfit',-apple-system,BlinkMacSystemFont,sans-serif;--font-b:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,sans-serif;
}'''
    code = code.replace(old_root.group(0), new_root)

# 2. Update body glowing background
old_bg = re.search(r'body::before\{content:\'\';position:fixed;inset:0;z-index:-2;[^}]+\}', code)
if old_bg:
    new_bg = '''body::before{content:'';position:fixed;inset:0;z-index:-2;
    background:radial-gradient(circle at 12% 18%,rgba(56,189,248,.14),transparent 45%),
               radial-gradient(circle at 88% 82%,rgba(129,140,248,.14),transparent 45%),
               radial-gradient(circle at 50% 50%,rgba(16,185,129,.06),transparent 50%);
    filter:blur(80px)}'''
    code = code.replace(old_bg.group(0), new_bg)

# 3. Enhance Offense Card & Photo Evidence rendering
evidence_replacement = '''<?php 
  $offEv = $offense['evidence_file'] ?? ($offense['incident_photo'] ?? null);
  if (!empty($offEv)): 
    $ext = strtolower(pathinfo($offEv, PATHINFO_EXTENSION));
    $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
?>
<div class="offense-row" style="margin-top:10px;">
    <div class="label">Photo / Document Evidence</div>
    <div class="value">
        <?php if ($isImg): ?>
            <div style="display:inline-block;">
                <a href="../<?= htmlspecialchars($offEv) ?>" target="_blank" title="Click to view full resolution photo evidence" style="display: block; border-radius: 12px; overflow: hidden; border: 1.5px solid rgba(56, 189, 248, 0.4); box-shadow: 0 8px 24px rgba(0,0,0,0.5); transition: transform 0.2s ease;">
                    <img src="../<?= htmlspecialchars($offEv) ?>" style="max-width: 280px; max-height: 180px; object-fit: cover; display: block; border-radius: 10px;">
                </a>
                <div style="font-size:11px; font-weight:700; color:#38bdf8; margin-top:6px; display:flex; align-items:center; gap:6px;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/><line x1="11" y1="8" x2="11" y2="14"/><line x1="8" y1="11" x2="14" y2="11"/></svg>
                    Click image to expand full resolution evidence
                </div>
            </div>
        <?php else: ?>
            <a href="../<?= htmlspecialchars($offEv) ?>" target="_blank" style="color: #38bdf8; font-weight: 700; font-size: 12.5px; display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; background: rgba(56,189,248,0.1); border: 1px solid rgba(56,189,248,0.3); border-radius: 8px; text-decoration: none;">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>
                <span>View Attached Evidence File</span>
            </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>'''

code = re.sub(r'<\?php\s*\$offEv = \$offense\[\'evidence_file\'\].*?<\?php endif; \?>', evidence_replacement, code, flags=re.DOTALL)

with open(file_path, 'w', encoding='utf-8') as f:
    f.write(code)

print("Successfully enhanced UPCC/case_view.php design & photo evidence presentation!")
