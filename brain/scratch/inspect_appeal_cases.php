<?php
require_once __DIR__ . '/../../database/database.php';

echo "=== OFFENSE TYPES ===\n";
$types = db_all("SELECT offense_type_id, offense_code, offense_name, offense_level, description FROM offense_type WHERE offense_level = 'MAJOR' LIMIT 10");
print_r($types);

echo "\n=== UPCC CASES WITH APPEALS ===\n";
$appeals = db_all("
  SELECT sar.appeal_id, sar.student_id, sar.offense_id, sar.case_id, sar.status AS appeal_status,
         uc.case_id, uc.case_kind, uc.decided_category, uc.status AS case_status,
         " . db_decrypt_col('final_decision', 'uc') . " AS final_decision,
         " . db_decrypt_col('punishment_details', 'uc') . " AS punishment_details
  FROM student_appeal_request sar
  LEFT JOIN upcc_case uc ON uc.case_id = sar.case_id
  ORDER BY sar.appeal_id DESC LIMIT 10
");
print_r($appeals);

echo "\n=== ALL RECENT CASES ===\n";
$cases = db_all("
  SELECT uc.case_id, uc.student_id, uc.case_kind, uc.decided_category, uc.status,
         " . db_decrypt_col('punishment_details', 'uc') . " AS punishment_details
  FROM upcc_case uc
  ORDER BY uc.case_id DESC LIMIT 5
");
print_r($cases);
