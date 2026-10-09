path = r'c:\xampp\htdocs\identitrack\admin\AJAX\export_monthly_report_xlsx.php'
with open(path, 'r', encoding='utf-8') as f:
    lines = f.readlines()

for i, l in enumerate(lines):
    if 'COALESCE(NULLIF(uc.decided_category,0), 0) AS decided_category,' in l:
        if i < 150: # offense query
            lines[i] = "      COALESCE(NULLIF(ot.major_category, 0), 0) AS registered_category,\n" + l
        else: # case query
            lines[i] = "        COALESCE((SELECT COALESCE(NULLIF(ot2.major_category, 0), 0) FROM upcc_case_offense uco2 JOIN offense o2 ON o2.offense_id = uco2.offense_id JOIN offense_type ot2 ON ot2.offense_type_id = o2.offense_type_id WHERE uco2.case_id = uc.case_id ORDER BY ot2.major_category DESC LIMIT 1), 0) AS registered_category,\n" + l

# Now update populate_sheet_data_rows lines
new_lines = []
i = 0
while i < len(lines):
    if "$decidedCat = (int)($r['decided_category'] ?? 0);" in lines[i]:
        new_lines.append(lines[i])
        new_lines.append("              $regCat = (int)($r['registered_category'] ?? 0);\n")
        new_lines.append("              $displayRegCat = ($regCat > 0) ? $regCat : (($decidedCat > 0) ? $decidedCat : 0);\n")
        i += 1
    elif "$pendingCol = 'N/A (Dismissed)';" in lines[i]:
        new_lines.append("                      $pendingCol = '';\n")
        i += 1
    elif "$pendingCol = $isPending ? 'PENDING UPCC HEARING' : 'N/A (Resolved / Closed)';" in lines[i]:
        new_lines.append("                      $pendingCol = $isPending ? 'PENDING UPCC HEARING' : '';\n")
        i += 1
    elif "$pendingCol = $isPending ? \"ACTIVE MINOR (CYCLE {$cycleNum})\" : 'N/A (Resolved)';" in lines[i]:
        new_lines.append("                          $pendingCol = $isPending ? \"ACTIVE MINOR (CYCLE {$cycleNum})\" : '';\n")
        i += 1
    elif "$pendingCol = $isPending ? 'ACTIVE MINOR OFFENSE' : 'N/A (Resolved)';" in lines[i]:
        new_lines.append("                          $pendingCol = $isPending ? 'ACTIVE MINOR OFFENSE' : '';\n")
        i += 1
    elif "$pendingCol = 'N/A';" in lines[i]:
        new_lines.append("                      $pendingCol = '';\n")
        i += 1
    elif "$resolvedCol = 'N/A (Pending Case)';" in lines[i]:
        new_lines.append("                      $resolvedCol = '';\n")
        i += 1
    elif "$resolvedCol = 'N/A (Pending)';" in lines[i]:
        new_lines.append("                          $resolvedCol = '';\n")
        i += 1
    elif "$resolvedCol = 'N/A';" in lines[i]:
        new_lines.append("                      $resolvedCol = '';\n")
        i += 1
    elif "$resolvedCol = $isResolved ? (($decidedCat > 0) ? \"SECTION 4 MAJOR (CATEGORY {$decidedCat})\" : \"SECTION 4 MAJOR (RESOLVED)\") : 'N/A (Pending Case)';" in lines[i]:
        new_lines.append("                      $resolvedCol = $isResolved ? (($decidedCat > 0) ? \"SECTION 4 MAJOR (CATEGORY {$decidedCat})\" : \"SECTION 4 MAJOR (RESOLVED)\") : '';\n")
        i += 1
    elif "$resolvedCol = $isResolved ? (($decidedCat > 0) ? \"AUTOMATIC MAJOR (CATEGORY {$decidedCat})\" : \"AUTOMATIC MAJOR (RESOLVED)\") : 'N/A (Pending Case)';" in lines[i]:
        new_lines.append("                      $resolvedCol = $isResolved ? (($displayRegCat > 0) ? \"AUTOMATIC MAJOR (CATEGORY {$displayRegCat})\" : \"AUTOMATIC MAJOR (RESOLVED)\") : '';\n")
        i += 1
    elif "$resolvedCol = $isResolved ? \"RESOLVED MINOR (CYCLE {$cycleNum})\" : 'N/A (Pending)';" in lines[i]:
        new_lines.append("                          $resolvedCol = $isResolved ? \"RESOLVED MINOR (CYCLE {$cycleNum})\" : '';\n")
        i += 1
    elif "$resolvedCol = $isResolved ? 'RESOLVED MINOR OFFENSE' : 'N/A (Pending)';" in lines[i]:
        new_lines.append("                          $resolvedCol = $isResolved ? 'RESOLVED MINOR OFFENSE' : '';\n")
        i += 1
    else:
        new_lines.append(lines[i])
        i += 1

with open(path, 'w', encoding='utf-8', newline='') as f:
    f.writelines(new_lines)

print("SUCCESS")
