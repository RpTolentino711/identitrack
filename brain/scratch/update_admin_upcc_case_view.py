import re

file_path = r'c:\xampp\htdocs\identitrack\admin\upcc_case_view.php'

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Update SQL query on line 31
old_query = '''$offenses = db_all("SELECT o.*, ot.code, ot.name AS offense_name, ot.level, ot.major_category,
           ot.intervention_first, ot.intervention_second
    FROM upcc_case_offense uco
    JOIN offense o ON o.offense_id = uco.offense_id
    JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
    WHERE uco.case_id = :id ORDER BY o.date_committed ASC", [':id' => $case_id]);'''

new_query = '''$offenseParams = [':id' => $case_id];
db_add_encryption_key($offenseParams);
$offenses = db_all("SELECT o.*, " . db_decrypt_col('description', 'o') . " AS description, ot.code, ot.name AS offense_name, ot.level, ot.major_category,
           ot.intervention_first, ot.intervention_second
    FROM upcc_case_offense uco
    JOIN offense o ON o.offense_id = uco.offense_id
    JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
    WHERE uco.case_id = :id ORDER BY o.date_committed ASC", $offenseParams);'''

# 2. Update offenses rendering
old_render = '''              <?php if (empty($offenses)): ?>
                <div style="font-size:.78rem;color:var(--ink-400);font-style:italic;">No offenses recorded in this case.</div>
              <?php else: ?>
                <?php foreach ($offenses as $off): ?>
                  <div class="offense-item <?= ($off['level'] ?? '') === 'MAJOR' ? 'major' : '' ?>">
                    <div class="offense-top">
                      <span class="stag <?= ($off['level'] ?? '') === 'MAJOR' ? 'stag-major' : 'stag-minor' ?>"><?= htmlspecialchars($off['level'] ?? 'MINOR') ?></span>
                      <span class="offense-code"><?= htmlspecialchars($off['code'] ?? '') ?></span>
                      <span class="offense-name"><?= htmlspecialchars($off['offense_name'] ?? '') ?></span>
                    </div>
                    <div class="offense-meta">
                      <?php if (!empty($off['date_committed'])): ?><span>📅 <?= fmtd($off['date_committed']) ?></span><?php endif; ?>
                      <?php if (!empty($off['intervention_first'])): ?><span>1st: <?= htmlspecialchars($off['intervention_first']) ?></span><?php endif; ?>
                    </div>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>'''

new_render = '''              <?php if (empty($offenses)): ?>
                <div style="font-size:.78rem;color:var(--ink-400);font-style:italic;">No offenses recorded in this case.</div>
              <?php else: ?>
                <div class="offense-accordion-list" style="display:flex; flex-direction:column; gap:10px;">
                <?php foreach ($offenses as $idx => $off):
                  $lvl = strtoupper((string)($off['level'] ?? 'MINOR'));
                  $isMaj = ($lvl === 'MAJOR');
                  $evFile = $off['evidence_file'] ?? '';
                  $evUrl = !empty($evFile) ? '../' . ltrim($evFile, '/') : '';
                ?>
                  <details class="offense-details-card" <?= $idx === 0 ? 'open' : '' ?> style="border:1px solid <?= $isMaj ? '#fca5a5' : '#cbd5e1' ?>; border-radius:10px; background:<?= $isMaj ? '#fef2f2' : '#ffffff' ?>; overflow:hidden;">
                    <summary style="padding:12px 14px; cursor:pointer; font-weight:700; display:flex; align-items:center; justify-content:space-between; user-select:none; background:<?= $isMaj ? 'rgba(239,68,68,0.06)' : 'rgba(241,245,249,0.5)' ?>;">
                      <div style="display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <span class="stag <?= $isMaj ? 'stag-major' : 'stag-minor' ?>"><?= htmlspecialchars($lvl) ?></span>
                        <span style="font-family:var(--mono); font-size:12px; color:var(--ink-700);"><?= htmlspecialchars($off['code'] ?? '') ?></span>
                        <span style="font-size:13px; color:var(--ink-900);"><?= htmlspecialchars($off['offense_name'] ?? '') ?></span>
                      </div>
                      <div style="font-size:11px; color:var(--ink-500);">📅 <?= fmtd($off['date_committed']) ?></div>
                    </summary>
                    <div style="padding:14px; border-top:1px solid <?= $isMaj ? '#fee2e2' : '#e2e8f0' ?>; background:#ffffff; font-size:12.5px; display:flex; flex-direction:column; gap:8px;">
                      <?php if (!empty(trim((string)($off['description'] ?? '')))): ?>
                        <div>
                          <strong style="color:var(--ink-700); font-size:11px; text-transform:uppercase; letter-spacing:0.4px;">Description:</strong>
                          <div style="color:var(--ink-800); margin-top:2px; font-style:italic;">"<?= htmlspecialchars((string)$off['description']) ?>"</div>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($evUrl)): ?>
                        <div style="margin-top:4px;">
                          <strong style="color:#2563eb; font-size:11px; text-transform:uppercase; letter-spacing:0.4px; display:flex; align-items:center; gap:4px;">
                            <span>📷</span> Incident Photo Evidence:
                          </strong>
                          <a href="<?= htmlspecialchars($evUrl) ?>" target="_blank" title="Click to view full photo evidence" style="display:inline-block; margin-top:6px;">
                            <img src="<?= htmlspecialchars($evUrl) ?>" alt="Photo Evidence" style="max-width:180px; max-height:140px; object-fit:cover; border-radius:8px; border:1px solid #cbd5e1; box-shadow:0 2px 8px rgba(0,0,0,0.08); transition:transform 0.15s ease;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                          </a>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty(trim((string)($off['intervention_first'] ?? '')))): ?>
                        <div style="font-size:11.5px; color:var(--ink-600);">
                          <strong>1st Intervention:</strong> <?= htmlspecialchars((string)$off['intervention_first']) ?>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty(trim((string)($off['intervention_second'] ?? '')))): ?>
                        <div style="font-size:11.5px; color:var(--ink-600);">
                          <strong>2nd Intervention:</strong> <?= htmlspecialchars((string)$off['intervention_second']) ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </details>
                <?php endforeach; ?>
                </div>
              <?php endif; ?>'''

norm_content = content.replace('\r\n', '\n')
norm_old_query = old_query.replace('\r\n', '\n')
norm_new_query = new_query.replace('\r\n', '\n')
norm_old_render = old_render.replace('\r\n', '\n')
norm_new_render = new_render.replace('\r\n', '\n')

updated = False
if norm_old_query in norm_content:
    norm_content = norm_content.replace(norm_old_query, norm_new_query, 1)
    print("SUCCESS: query replaced")
    updated = True
else:
    print("WARNING: query block not matched")

if norm_old_render in norm_content:
    norm_content = norm_content.replace(norm_old_render, norm_new_render, 1)
    print("SUCCESS: render replaced")
    updated = True
else:
    print("WARNING: render block not matched")

if updated:
    with open(file_path, 'w', encoding='utf-8') as f:
        f.write(norm_content)
    print("admin/upcc_case_view.php saved successfully")
