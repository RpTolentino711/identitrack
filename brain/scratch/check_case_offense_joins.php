<?php
require_once 'c:/xampp/htdocs/identitrack/database/database.php';
$res = db_all("SELECT uc.case_id, uc.case_kind, uco.offense_id, o.offense_type_id, ot.code, ot.name, ot.level 
              FROM upcc_case uc 
              LEFT JOIN upcc_case_offense uco ON uc.case_id = uco.case_id 
              LEFT JOIN offense o ON uco.offense_id = o.offense_id 
              LEFT JOIN offense_type ot ON o.offense_type_id = ot.offense_type_id 
              ORDER BY uc.case_id DESC LIMIT 10");
print_r($res);
