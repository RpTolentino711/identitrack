import re

file_path = r'c:\xampp\htdocs\identitrack\admin\pending_request_view.php'
with open(file_path, 'r', encoding='utf-8') as f:
    content = f.read()

# 1. Update Modal Subtitle (line ~644)
old_modal_sub = '<p class="modal-subtitle">Please input your password to approve and start this community service session.</p>'
new_modal_sub = '<p class="modal-subtitle"><?php echo $r[\'request_type\'] === \'LOGIN\' ? \'Please input your password to approve and start this community service session.\' : \'Please input your password to approve and complete this manual logout request.\'; ?></p>'

if old_modal_sub in content:
    content = content.replace(old_modal_sub, new_modal_sub, 1)
    print("Modal subtitle updated")
else:
    print("old_modal_sub not found")

# 2. Update Panel Header Subtitle (line ~776)
old_panel_sub = '<p>Approve to start the student\'s community service session now, or reject the request.</p>'
new_panel_sub = '<p><?php echo $r[\'request_type\'] === \'LOGIN\' ? "Approve to start the student\'s community service session now, or reject the request." : "Approve to validate and complete the student\'s manual logout request, or reject the request."; ?></p>'

if old_panel_sub in content:
    content = content.replace(old_panel_sub, new_panel_sub, 1)
    print("Panel subtitle updated")
else:
    print("old_panel_sub not found")

# 3. Update Field Label (lines ~796-803)
old_label = """                <label class="field-label" for="notes">
                  SDO Notes / Assignment Location 
                  <?php if ($r['request_type'] === 'LOGIN'): ?>
                    <span style="font-weight:800; color:#dc3545;">(Required *)</span>
                  <?php else: ?>
                    <span style="font-weight:600; color:#6c757d;">(Optional)</span>
                  <?php endif; ?>
                </label>"""

new_label = """                <label class="field-label" for="notes">
                  <?php echo $r['request_type'] === 'LOGIN' ? 'SDO Notes / Assignment Location' : 'SDO Notes'; ?> 
                  <?php if ($r['request_type'] === 'LOGIN'): ?>
                    <span style="font-weight:800; color:#dc3545;">(Required *)</span>
                  <?php else: ?>
                    <span style="font-weight:600; color:#6c757d;">(Optional)</span>
                  <?php endif; ?>
                </label>"""

if old_label in content:
    content = content.replace(old_label, new_label, 1)
    print("Field label updated")
else:
    print("old_label not found")

# 4. Update Approve Button Text (line ~823)
old_btn = """                    Approve &amp; Start Session"""
new_btn = """                    <?php echo $r['request_type'] === 'LOGIN' ? 'Approve &amp; Start Session' : 'Approve &amp; Complete Logout'; ?>"""

if old_btn in content:
    content = content.replace(old_btn, new_btn, 1)
    print("Approve button text updated")
else:
    print("old_btn not found")

with open(file_path, 'w', encoding='utf-8') as f:
    f.write(content)
print("admin/pending_request_view.php saved!")
