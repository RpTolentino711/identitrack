import re

# 1. Update admin/api_ai_suggest_sanction.php
api_path = r'c:\xampp\htdocs\identitrack\admin\api_ai_suggest_sanction.php'
with open(api_path, 'r', encoding='utf-8') as f:
    api_content = f.read()

old_case_fetch = """        if (!empty($allCaseOffenses)) {
            $case = $allCaseOffenses[0];
            $case['case_id'] = $rawCaseId;
            $cMetaRow = db_one("SELECT student_id, decided_category, probation_until, punishment_details FROM upcc_case WHERE case_id = :cid OR CAST(case_id AS CHAR) = :cid", [':cid' => $rawCaseId]);
            if ($cMetaRow) {
                $case = array_merge($case, $cMetaRow);
            }
        }
    }

    if (!$case && $studentId !== '') {
        $case = db_one("SELECT s.student_id,
                   o.offense_type_id, o.description as offense_description, ot.code as offense_code, ot.name as offense_name, ot.level as offense_level, ot.major_category
            FROM student s
            LEFT JOIN offense o ON o.student_id = s.student_id
            LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
            WHERE s.student_id = :sid
            ORDER BY o.date_committed DESC LIMIT 1
        ", [':sid' => $studentId]);
    }"""

new_case_fetch = """        if (!empty($allCaseOffenses)) {
            $case = $allCaseOffenses[0];
            $case['case_id'] = $rawCaseId;
            $cMetaRow = db_one("SELECT student_id, decided_category, probation_until, punishment_details FROM upcc_case WHERE case_id = :cid OR CAST(case_id AS CHAR) = :cid", [':cid' => $rawCaseId]);
            if ($cMetaRow) {
                $case = array_merge($case, $cMetaRow);
            }
        } else {
            $cMetaRow = db_one("SELECT student_id, case_summary, case_kind FROM upcc_case WHERE case_id = :cid OR CAST(case_id AS CHAR) = :cid", [':cid' => $rawCaseId]);
            if ($cMetaRow) {
                $case = [
                    'case_id' => $rawCaseId,
                    'student_id' => $cMetaRow['student_id'],
                    'offense_name' => !empty($cMetaRow['case_summary']) ? $cMetaRow['case_summary'] : 'Student Discipline Case',
                    'offense_level' => (stripos($cMetaRow['case_kind'] ?? '', 'MAJOR') !== false) ? 'MAJOR' : 'MINOR',
                    'offense_code' => 'UPCC_CASE',
                    'offense_type_id' => 0
                ];
            }
        }
    }

    if (!$case && $studentId !== '') {
        $case = db_one("SELECT s.student_id,
                   o.offense_type_id, o.description as offense_description, ot.code as offense_code, ot.name as offense_name, ot.level as offense_level, ot.major_category
            FROM student s
            LEFT JOIN offense o ON o.student_id = s.student_id AND o.offense_id NOT IN (
                SELECT uco.offense_id FROM upcc_case_offense uco
                JOIN upcc_case uc ON uc.case_id = uco.case_id
                WHERE uc.status IN ('RESOLVED', 'CLOSED', 'FINALIZED')
            )
            LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
            WHERE s.student_id = :sid
            ORDER BY o.date_committed DESC LIMIT 1
        ", [':sid' => $studentId]);
    }"""

if old_case_fetch in api_content:
    api_content = api_content.replace(old_case_fetch, new_case_fetch, 1)
    print("api_ai_suggest_sanction.php updated")
    with open(api_path, 'w', encoding='utf-8') as f:
        f.write(api_content)
else:
    print("old_case_fetch pattern not found in api_ai_suggest_sanction.php")

# 2. Update UPCC/case_view.php to always run fresh prediction on load
upcc_path = r'c:\xampp\htdocs\identitrack\UPCC\case_view.php'
with open(upcc_path, 'r', encoding='utf-8') as f:
    upcc_content = f.read()

old_upcc_onload = """// Auto-restore cached AI prediction on page load / hard refresh
document.addEventListener('DOMContentLoaded', function() {
    try {
        const caseId = <?= (int)$caseId ?>;
        const currentCycle = document.getElementById('comsiceNumOffense')?.value || '';
        const cached = sessionStorage.getItem('comsice_ai_prediction_case_' + caseId);
        if (cached) {
            const data = JSON.parse(cached);
            if (data && data.number_of_offense && currentCycle.includes(data.number_of_offense)) {
                renderComsiceResult(data);
                return;
            }
        }
        runComsicePrediction();
    } catch(e) {
        runComsicePrediction();
    }
});"""

new_upcc_onload = """// Auto-run fresh AI prediction on page load / hard refresh
document.addEventListener('DOMContentLoaded', function() {
    try {
        const caseId = <?= (int)$caseId ?>;
        sessionStorage.removeItem('comsice_ai_prediction_case_' + caseId);
        runComsicePrediction();
    } catch(e) {
        runComsicePrediction();
    }
});"""

if old_upcc_onload in upcc_content:
    upcc_content = upcc_content.replace(old_upcc_onload, new_upcc_onload, 1)
    print("UPCC/case_view.php updated")
    with open(upcc_path, 'w', encoding='utf-8') as f:
        f.write(upcc_content)
else:
    print("old_upcc_onload pattern not found in UPCC/case_view.php")
