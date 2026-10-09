import re

file_path = r'c:\xampp\htdocs\identitrack\admin\offense_new.php'

with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Update HTML container for photo input to add id="formPhotoGroup" and conditionally hide on MAJOR
old_photo_row = '''                <div class="form-row full" style="margin-top: 6px;">
                  <div class="form-group">
                    <label for="visible_evidence_input" style="font-weight:700; color:var(--text-3); display:flex; align-items:center; justify-content:space-between;">'''

new_photo_row = '''                <div class="form-row full" id="formPhotoGroup" style="margin-top: 6px; <?php echo ($level === 'MAJOR') ? 'display:none;' : ''; ?>">
                  <div class="form-group">
                    <label for="visible_evidence_input" style="font-weight:700; color:var(--text-3); display:flex; align-items:center; justify-content:space-between;">'''

# 2. Update onLevelChange(newLevel) in JS to toggle formPhotoGroup visibility
old_on_level_change = '''  function onLevelChange(newLevel) {
    const lvl = (newLevel || 'MINOR').toUpperCase();
    currentLevel = lvl;

    const catGroup = document.getElementById('categoryGroup');
    if (catGroup) {
      catGroup.style.display = (lvl === 'MAJOR') ? 'block' : 'none';
    }

    const disBanner = document.getElementById('dismissedAlertBanner');'''

new_on_level_change = '''  function onLevelChange(newLevel) {
    const lvl = (newLevel || 'MINOR').toUpperCase();
    currentLevel = lvl;

    const catGroup = document.getElementById('categoryGroup');
    if (catGroup) {
      catGroup.style.display = (lvl === 'MAJOR') ? 'block' : 'none';
    }

    const photoGroup = document.getElementById('formPhotoGroup');
    if (photoGroup) {
      photoGroup.style.display = (lvl === 'MAJOR') ? 'none' : 'block';
    }

    const disBanner = document.getElementById('dismissedAlertBanner');'''

norm_content = content.replace('\r\n', '\n')

updated = False
if old_photo_row.replace('\r\n', '\n') in norm_content:
    norm_content = norm_content.replace(old_photo_row.replace('\r\n', '\n'), new_photo_row.replace('\r\n', '\n'), 1)
    print("SUCCESS: Photo row updated with id and conditional hide")
    updated = True
else:
    print("WARNING: Photo row not matched")

if old_on_level_change.replace('\r\n', '\n') in norm_content:
    norm_content = norm_content.replace(old_on_level_change.replace('\r\n', '\n'), new_on_level_change.replace('\r\n', '\n'), 1)
    print("SUCCESS: onLevelChange updated to toggle photo group")
    updated = True
else:
    print("WARNING: onLevelChange not matched")

if updated:
    with open(file_path, 'w', encoding='utf-8') as f:
        f.write(norm_content)
    print("admin/offense_new.php updated successfully")
