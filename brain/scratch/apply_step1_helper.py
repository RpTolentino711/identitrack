path = r'c:\xampp\htdocs\identitrack\admin\AJAX\export_monthly_report_xlsx.php'
with open(path, 'r', encoding='utf-8') as f:
    code = f.read()

# 1. Add detect_registered_major_category helper function before populate_sheet_data_rows
helper_func = """function detect_registered_major_category(array $r): int {
    $allText = strtoupper(
        (string)($r['offense_name'] ?? '') . ' ' .
        (string)($r['description'] ?? '') . ' ' .
        (string)($r['case_kind'] ?? '') . ' ' .
        (string)($r['punishment_details'] ?? '') . ' ' .
        (string)($r['final_decision'] ?? '') . ' ' .
        (string)($r['decision_reason'] ?? '')
    );

    if (strpos($allText, 'CATEGORY 5') !== false || strpos($allText, 'EXPULSION') !== false || strpos($allText, 'POLICE REFERRAL') !== false) {
        return 5;
    }
    if (strpos($allText, 'CATEGORY 4') !== false || strpos($allText, 'EXCLUSION') !== false) {
        return 4;
    }
    if (strpos($allText, 'CATEGORY 3') !== false || strpos($allText, 'SUSPENSION') !== false || strpos($allText, 'NON-READMISSION') !== false) {
        return 3;
    }
    if (strpos($allText, 'CATEGORY 2') !== false || strpos($allText, 'COMMUNITY SERVICE') !== false || strpos($allText, 'FORMATIVE') !== false) {
        return 2;
    }
    if (strpos($allText, 'CATEGORY 1') !== false || strpos($allText, 'FORMAL REPRIMAND') !== false) {
        return 1;
    }

    $reg = (int)($r['registered_category'] ?? 0);
    if ($reg > 0) return $reg;

    $dec = (int)($r['decided_category'] ?? 0);
    if ($dec > 0) return $dec;

    return 0;
}

"""

if 'function detect_registered_major_category' not in code:
    target_str = 'function populate_sheet_data_rows('
    code = code.replace(target_str, helper_func + target_str)

with open(path, 'w', encoding='utf-8', newline='') as f:
    f.write(code)

print("STEP 1 HELPER ADDED")
