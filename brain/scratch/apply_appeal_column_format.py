path = r'c:\xampp\htdocs\identitrack\admin\AJAX\export_monthly_report_xlsx.php'
with open(path, 'r', encoding='utf-8') as f:
    code = f.read()

# For Automatic Major (in case branch and offense branch):
# displayLevel for automatic major and section 4:
# When $isApprovedAppeal is true, show APPROVED APPEAL (CATEGORY X) in displayLevel (second column), while resolvedCol (first column) has AUTOMATIC MAJOR (CATEGORY X).

old_sec4_display = '$displayLevel = ($isResolved && $decidedCat > 0) ? "{$cOrd} CYCLE SECTION 4 MAJOR (CATEGORY {$decidedCat})" : ($isPending ? "{$cOrd} CYCLE SECTION 4 MAJOR (CATEGORY 1 TO 5 PENDING UPCC)" : "{$cOrd} CYCLE SECTION 4 MAJOR (RESOLVED)");'
new_sec4_display = '$displayLevel = $isApprovedAppeal ? (($decidedCat > 0) ? "APPROVED APPEAL (CATEGORY {$decidedCat})" : "APPROVED APPEAL (SANCTION VOIDED)") : (($isResolved && $decidedCat > 0) ? "{$cOrd} CYCLE SECTION 4 MAJOR (CATEGORY {$decidedCat})" : ($isPending ? "{$cOrd} CYCLE SECTION 4 MAJOR (CATEGORY 1 TO 5 PENDING UPCC)" : "{$cOrd} CYCLE SECTION 4 MAJOR (RESOLVED)"));'

old_auto_display = '$displayLevel = ($isResolved && $decidedCat > 0) ? "AUTOMATIC MAJOR (CATEGORY {$decidedCat})" : ($isPending ? "AUTOMATIC MAJOR (CATEGORY 1 TO 5 PENDING UPCC)" : "AUTOMATIC MAJOR (RESOLVED)");'
new_auto_display = '$displayLevel = $isApprovedAppeal ? (($decidedCat > 0) ? "APPROVED APPEAL (CATEGORY {$decidedCat})" : "APPROVED APPEAL (SANCTION VOIDED)") : (($isResolved && $decidedCat > 0) ? "AUTOMATIC MAJOR (CATEGORY {$decidedCat})" : ($isPending ? "AUTOMATIC MAJOR (CATEGORY 1 TO 5 PENDING UPCC)" : "AUTOMATIC MAJOR (RESOLVED)"));'

if old_sec4_display in code and old_auto_display in code:
    code = code.replace(old_sec4_display, new_sec4_display)
    code = code.replace(old_auto_display, new_auto_display)
    with open(path, 'w', encoding='utf-8', newline='') as f:
        f.write(code)
    print("SUCCESS")
else:
    print("MATCH FAILED")
