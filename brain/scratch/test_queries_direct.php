<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once 'c:/xampp/htdocs/identitrack/database/database.php';

$monthStart = '1970-01-01 00:00:00';
$monthEnd = '2099-12-31 23:59:59';
$segmentExpr = "(CASE WHEN (LOWER(COALESCE(s.school,'')) LIKE '%senior high%' OR UPPER(COALESCE(s.school,'')) = 'SHS' OR UPPER(COALESCE(s.program,'')) LIKE '%SHS%') THEN 'SHS' ELSE 'COLLEGE' END)";
$audienceClause = '';
$offenseFilter = $audienceClause;

$params = [':start' => $monthStart, ':end' => $monthEnd];
db_add_encryption_key($params);

echo "Executing Offense Query...\n";
try {
    $offenseRows = db_all(
      "SELECT
          o.offense_id,
          o.student_id,
          {$segmentExpr} AS segment,
          CONCAT(" . db_decrypt_col('student_ln', 's') . ", ', ', " . db_decrypt_col('student_fn', 's') . ") AS student_name,
          COALESCE(NULLIF(s.program,''), 'N/A') AS program,
          COALESCE(NULLIF(s.section,''), 'N/A') AS section,
          ot.level AS offense_level,
          ot.code AS offense_code,
          ot.name AS offense_name,
          ot.intervention_first,
          ot.intervention_second,
          o.status,
          o.date_committed,
          " . db_decrypt_col('description', 'o') . " AS description,
          uc.case_id,
          uc.case_kind,
          COALESCE(NULLIF(uc.decided_category,0), 0) AS decided_category,
          " . db_decrypt_col('final_decision', 'uc') . " AS final_decision,
          " . db_decrypt_col('punishment_details', 'uc') . " AS punishment_details,
          COALESCE(" . db_decrypt_col('decision_reason', 'uc') . ", '') AS decision_reason,
          uc.status AS case_status
       FROM offense o
       JOIN student s ON s.student_id = o.student_id
       JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
       LEFT JOIN upcc_case_offense uco ON uco.offense_id = o.offense_id
       LEFT JOIN upcc_case uc ON uc.case_id = uco.case_id
       WHERE o.date_committed BETWEEN :start AND :end
       $offenseFilter
       ORDER BY o.date_committed DESC",
      $params
    );
    echo "Offense rows count: " . count($offenseRows) . "\n";
} catch (\Throwable $e) {
    echo "OFFENSE QUERY ERROR: " . $e->getMessage() . "\n";
}

echo "Executing Case Query...\n";
try {
    $caseRowsQuery = db_all(
        "SELECT
            CONCAT('CASE-', uc.case_id) AS offense_id,
            uc.student_id,
            {$segmentExpr} AS segment,
            CONCAT(" . db_decrypt_col('student_ln', 's') . ", ', ', " . db_decrypt_col('student_fn', 's') . ") AS student_name,
            COALESCE(NULLIF(s.program,''), 'N/A') AS program,
            COALESCE(NULLIF(s.section,''), 'N/A') AS section,
            'MAJOR' AS offense_level,
            'UPCC-CASE' AS offense_code,
            COALESCE(NULLIF(uc.case_summary,''), NULLIF(uc.case_kind,''), 'UPCC Hearing Case') AS offense_name,
            NULL AS intervention_first,
            NULL AS intervention_second,
            uc.status AS status,
            uc.created_at AS date_committed,
            COALESCE(" . db_decrypt_col('case_summary', 'uc') . ", uc.case_kind, 'UPCC Case Record') AS description,
            uc.case_id,
            uc.case_kind,
            COALESCE(NULLIF(uc.decided_category,0), 0) AS decided_category,
            " . db_decrypt_col('final_decision', 'uc') . " AS final_decision,
            " . db_decrypt_col('punishment_details', 'uc') . " AS punishment_details,
            COALESCE(" . db_decrypt_col('decision_reason', 'uc') . ", '') AS decision_reason,
            uc.status AS case_status
         FROM upcc_case uc
         JOIN student s ON s.student_id = uc.student_id
         WHERE uc.created_at BETWEEN :start AND :end
           AND UPPER(COALESCE(uc.case_kind,'')) = 'SECTION4_MINOR_ESCALATION'
         ORDER BY uc.created_at DESC",
        $params
    );
    echo "Case rows count: " . count($caseRowsQuery) . "\n";
} catch (\Throwable $e) {
    echo "CASE QUERY ERROR: " . $e->getMessage() . "\n";
}
