import re

api_path = r'c:\xampp\htdocs\identitrack\admin\api_ai_suggest_sanction.php'
with open(api_path, 'r', encoding='utf-8') as f:
    content = f.read()

# Replace queryAiEngine category evaluation
old_query_cat = """    $catNum = 1;
    $upperSanct = strtoupper($sanction);
    if (strpos($upperSanct, 'CATEGORY 5') !== false || strpos($upperSanct, 'SUMMARY EXPULSION') !== false || strpos($upperSanct, 'POLICE') !== false) {
        $catNum = 5;
    } elseif (strpos($upperSanct, 'CATEGORY 4') !== false || strpos($upperSanct, 'EXCLUSION') !== false || strpos($upperSanct, 'MANDATORY DISMISSAL') !== false || strpos($upperSanct, 'PERMANENT') !== false) {
        $catNum = 4;
    } elseif (strpos($upperSanct, 'CATEGORY 3') !== false || strpos($upperSanct, 'SUSPENSION') !== false || strpos($upperSanct, 'NON-READMISSION') !== false || strpos($upperSanct, 'CYCLE 2') !== false) {
        $catNum = 3;
    } elseif (strpos($upperSanct, 'CATEGORY 2') !== false || strpos($upperSanct, 'COMMUNITY SERVICE') !== false || strpos($upperSanct, 'FORMATIVE') !== false || strpos($upperSanct, 'HOURS') !== false || strpos($upperSanct, 'CYCLE 1') !== false) {
        $catNum = 2;
    } else {
        $catNum = 1;
    }
    $catLabel = "Category {$catNum}";"""

new_query_cat = """    $catNum = 1;
    $upperSanct = strtoupper($sanction);
    $numOffStr  = strtoupper((string)($caseMeta['number_of_offense'] ?? ''));
    $catStr     = strtoupper((string)($caseMeta['category'] ?? ''));

    // 1. Direct Handbook Rule Check for Section 4 Minor Escalation Cycles
    if (strpos($numOffStr, 'CYCLE 3') !== false || strpos($catStr, 'CYCLE 3') !== false || strpos($numOffStr, '9 MINOR') !== false) {
        $catNum = 3;
    } elseif (strpos($numOffStr, 'CYCLE 2') !== false || strpos($catStr, 'CYCLE 2') !== false || strpos($numOffStr, '6 MINOR') !== false) {
        $catNum = 2;
    } elseif (strpos($numOffStr, 'CYCLE 1') !== false || strpos($catStr, 'CYCLE 1') !== false || strpos($numOffStr, '3 MINOR') !== false) {
        $catNum = 1;
    }
    // 2. ML Prediction Text Match
    elseif (strpos($upperSanct, 'CATEGORY 5') !== false || strpos($upperSanct, 'SUMMARY EXPULSION') !== false || strpos($upperSanct, 'POLICE') !== false) {
        $catNum = 5;
    } elseif (strpos($upperSanct, 'CATEGORY 4') !== false || strpos($upperSanct, 'EXCLUSION') !== false || strpos($upperSanct, 'MANDATORY DISMISSAL') !== false || strpos($upperSanct, 'PERMANENT') !== false) {
        $catNum = 4;
    } elseif (strpos($upperSanct, 'CATEGORY 3') !== false || strpos($upperSanct, 'SUSPENSION') !== false || strpos($upperSanct, 'NON-READMISSION') !== false) {
        $catNum = 3;
    } elseif (strpos($upperSanct, 'CATEGORY 2') !== false || strpos($upperSanct, 'COMMUNITY SERVICE') !== false || strpos($upperSanct, 'FORMATIVE') !== false || strpos($upperSanct, 'HOURS') !== false) {
        $catNum = 2;
    } else {
        $catNum = 1;
    }
    $catLabel = "Category {$catNum}";"""

if old_query_cat in content:
    content = content.replace(old_query_cat, new_query_cat, 1)
    print("queryAiEngine category evaluation updated!")
else:
    print("old_query_cat not matched exactly, checking regex")

# Replace finalNumOffenseStr
old_num_off = "$finalNumOffenseStr = ($effectiveAttempt >= 2) ? ($effectiveAttempt . ($effectiveAttempt === 2 ? 'nd Offense' : ($effectiveAttempt === 3 ? 'rd Offense' : 'th Offense'))) : \"1st Offense\";"
new_num_off = "$finalNumOffenseStr = !empty($pNumOffense) ? $pNumOffense : (($effectiveAttempt >= 2) ? ($effectiveAttempt . ($effectiveAttempt === 2 ? 'nd Offense' : ($effectiveAttempt === 3 ? 'rd Offense' : 'th Offense'))) : \"1st Offense\");"

if old_num_off in content:
    content = content.replace(old_num_off, new_num_off, 1)
    print("finalNumOffenseStr updated!")
else:
    print("old_num_off not matched")

with open(api_path, 'w', encoding='utf-8') as f:
    f.write(content)
print("api_ai_suggest_sanction.php updated")
