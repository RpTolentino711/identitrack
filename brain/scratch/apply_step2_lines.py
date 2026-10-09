path = r'c:\xampp\htdocs\identitrack\admin\AJAX\export_monthly_report_xlsx.php'
with open(path, 'r', encoding='utf-8') as f:
    lines = f.readlines()

new_lines = []
i = 0
while i < len(lines):
    if "$displayRegCat = ($regCat > 0) ? $regCat : (($decidedCat > 0) ? $decidedCat : 0);" in lines[i]:
        new_lines.append("              $displayRegCat = detect_registered_major_category($r);\n")
        i += 1
    elif "$pendingCol = '';\n" == lines[i] and i > 750:
        # Check context
        if "if ($isDismissed)" in lines[i-1] or "if ($isDismissed)" in lines[i-2]:
            new_lines.append("                      $pendingCol = 'N/A (Dismissed)';\n")
        elif "$rowCategory = 'OTHER';" in lines[i-1]:
            new_lines.append("                      $pendingCol = 'N/A';\n")
        else:
            new_lines.append(lines[i])
        i += 1
    elif "$pendingCol = $isPending ? 'PENDING UPCC HEARING' : '';\n" == lines[i]:
        new_lines.append("                      $pendingCol = $isPending ? 'PENDING UPCC HEARING' : 'N/A (Resolved / Closed)';\n")
        i += 1
    elif "$pendingCol = $isPending ? \"ACTIVE MINOR (CYCLE {$cycleNum})\" : '';\n" == lines[i]:
        new_lines.append("                          $pendingCol = $isPending ? \"ACTIVE MINOR (CYCLE {$cycleNum})\" : 'N/A (Resolved)';\n")
        i += 1
    elif "$pendingCol = $isPending ? 'ACTIVE MINOR OFFENSE' : '';\n" == lines[i]:
        new_lines.append("                          $pendingCol = $isPending ? 'ACTIVE MINOR OFFENSE' : 'N/A (Resolved)';\n")
        i += 1
    elif "$resolvedCol = '';\n" == lines[i] and i > 750:
        if "$rowCategory = 'OTHER';" in lines[i-2]:
            new_lines.append("                      $resolvedCol = 'N/A';\n")
        else:
            new_lines.append(lines[i])
        i += 1
    elif "$resolvedCol = $isResolved ? (($decidedCat > 0) ? \"SECTION 4 MAJOR (CATEGORY {$decidedCat})\" : \"SECTION 4 MAJOR (RESOLVED)\") : '';\n" == lines[i]:
        new_lines.append("                      $resolvedCol = $isResolved ? (($decidedCat > 0) ? \"SECTION 4 MAJOR (CATEGORY {$decidedCat})\" : \"SECTION 4 MAJOR (RESOLVED)\") : 'N/A (Pending Case)';\n")
        i += 1
    elif "$resolvedCol = $isResolved ? (($displayRegCat > 0) ? \"AUTOMATIC MAJOR (CATEGORY {$displayRegCat})\" : \"AUTOMATIC MAJOR (RESOLVED)\") : '';\n" == lines[i]:
        new_lines.append("                      $resolvedCol = $isResolved ? (($displayRegCat > 0) ? \"AUTOMATIC MAJOR (CATEGORY {$displayRegCat})\" : \"AUTOMATIC MAJOR (RESOLVED)\") : 'N/A (Pending Case)';\n")
        i += 1
    elif "$resolvedCol = $isResolved ? \"RESOLVED MINOR (CYCLE {$cycleNum})\" : '';\n" == lines[i]:
        new_lines.append("                          $resolvedCol = $isResolved ? \"RESOLVED MINOR (CYCLE {$cycleNum})\" : 'N/A (Pending)';\n")
        i += 1
    elif "$resolvedCol = $isResolved ? 'RESOLVED MINOR OFFENSE' : '';\n" == lines[i]:
        new_lines.append("                          $resolvedCol = $isResolved ? 'RESOLVED MINOR OFFENSE' : 'N/A (Pending)';\n")
        i += 1
    else:
        new_lines.append(lines[i])
        i += 1

with open(path, 'w', encoding='utf-8', newline='') as f:
    f.writelines(new_lines)

print("STEP 2 LINES UPDATED")
