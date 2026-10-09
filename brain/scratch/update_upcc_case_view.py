import re

file_path = r'c:\xampp\htdocs\identitrack\UPCC\case_view.php'

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

target = '''                                        <div class="offense-body">
                                            <?php if (!empty(trim((string)$offense['description']))): ?>
                                            <div class="offense-row">
                                                <div class="label">Description</div>
                                                <div class="value"><?= htmlspecialchars((string)$offense['description']) ?></div>
                                            </div>
                                            <?php endif; ?>'''

replacement = '''                                        <div class="offense-body">
                                            <?php if (!empty(trim((string)$offense['description']))): ?>
                                            <div class="offense-row">
                                                <div class="label">Description</div>
                                                <div class="value"><?= htmlspecialchars((string)$offense['description']) ?></div>
                                            </div>
                                            <?php endif; ?>
                                            <?php if (!empty(trim((string)($offense['evidence_file'] ?? '')))): 
                                                $evPath = '../' . ltrim($offense['evidence_file'], '/');
                                            ?>
                                            <div class="offense-row">
                                                <div class="label">Photo Evidence</div>
                                                <div class="value">
                                                    <a href="<?= htmlspecialchars($evPath) ?>" target="_blank" title="View full image">
                                                        <img src="<?= htmlspecialchars($evPath) ?>" alt="Incident Photo Evidence" style="max-width: 160px; max-height: 160px; border-radius: 8px; border: 1px solid rgba(255,255,255,0.2); object-fit: cover; margin-top: 4px; transition: transform 0.2s; cursor: pointer;" onmouseover="this.style.transform='scale(1.05)'" onmouseout="this.style.transform='scale(1)'">
                                                    </a>
                                                </div>
                                            </div>
                                            <?php endif; ?>'''

if target in content:
    content = content.replace(target, replacement, 1)
    with open(file_path, 'w', encoding='utf-8') as f:
        f.write(content)
    print("SUCCESS: UPCC/case_view.php updated")
else:
    # Try normalizing line endings for target match
    norm_content = content.replace('\r\n', '\n')
    norm_target = target.replace('\r\n', '\n')
    if norm_target in norm_content:
        norm_content = norm_content.replace(norm_target, replacement.replace('\r\n', '\n'), 1)
        with open(file_path, 'w', encoding='utf-8') as f:
            f.write(norm_content)
        print("SUCCESS (normalized): UPCC/case_view.php updated")
    else:
        print("ERROR: Target block not found in UPCC/case_view.php")
