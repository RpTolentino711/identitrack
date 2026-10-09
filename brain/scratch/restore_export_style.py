path = r'c:\xampp\htdocs\identitrack\admin\AJAX\export_monthly_report_xlsx.php'
with open(path, 'r', encoding='utf-8') as f:
    lines = f.readlines()

# Replace lines 767 to 772 (0-indexed: 767:773)
# and lines 791 to 796 (0-indexed: 791:797)

# Let's verify line contents
print("Line 767:", repr(lines[767])) # if ($isApprovedAppeal) {
print("Line 791:", repr(lines[791])) # if ($isApprovedAppeal) {

# Remove the 5 lines of isApprovedAppeal from lines 767..772:
# 767: if ($isApprovedAppeal) {
# 768:     $rowCategory = ...
# 769:     $pendingCol = ...
# 770:     $resolvedCol = ...
# 771:     $displayLevel = ...
# 772: } elseif ($isDismissed) { -> replace with if ($isDismissed) {

new_lines = []
i = 0
while i < len(lines):
    if i == 767 and 'if ($isApprovedAppeal) {' in lines[i]:
        # skip 767..771
        i += 5
        # replace '} elseif ($isDismissed) {' with '                  if ($isDismissed) {\n'
        new_lines.append('                  if ($isDismissed) {\n')
        i += 1
    elif i > 770 and 'if ($isApprovedAppeal) {' in lines[i]:
        i += 5
        new_lines.append('                  if ($isDismissed) {\n')
        i += 1
    else:
        new_lines.append(lines[i])
        i += 1

with open(path, 'w', encoding='utf-8', newline='') as f:
    f.writelines(new_lines)

print("SUCCESSFULLY APPLIED")
