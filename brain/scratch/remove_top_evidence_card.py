import re

# 1. UPCC/case_view.php
upcc_path = r'c:\xampp\htdocs\identitrack\UPCC\case_view.php'
with open(upcc_path, 'r', encoding='utf-8') as f:
    upcc = f.read()

upcc_pattern = re.compile(
    r'\s*<!-- Incident Report & Photo Evidence Attachment Card -->.*?<\?php endif; \?>\s*',
    re.DOTALL
)

new_upcc, c1 = upcc_pattern.subn('\n\n', upcc, count=1)
print(f"UPCC/case_view.php top evidence card removed: {c1}")
if c1 > 0:
    with open(upcc_path, 'w', encoding='utf-8') as f:
        f.write(new_upcc)

# 2. admin/upcc_case_view.php
admin_path = r'c:\xampp\htdocs\identitrack\admin\upcc_case_view.php'
with open(admin_path, 'r', encoding='utf-8') as f:
    admin = f.read()

admin_pattern = re.compile(
    r'\s*<!-- Incident Report & Photo Evidence Attachment Card -->.*?<\?php endif; \?>\s*',
    re.DOTALL
)

new_admin, c2 = admin_pattern.subn('\n\n', admin, count=1)
print(f"admin/upcc_case_view.php top evidence card removed: {c2}")
if c2 > 0:
    with open(admin_path, 'w', encoding='utf-8') as f:
        f.write(new_admin)
