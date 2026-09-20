<?php
require_once 'c:/xampp/htdocs/identitrack/database/database.php';

$gvr = db_all("
    SELECT pgr.*, ot.code, ot.name 
    FROM guard_violation_report pgr
    LEFT JOIN offense_type ot ON ot.offense_type_id = pgr.offense_type_id
    ORDER BY pgr.report_id DESC
    LIMIT 20
");
print_r($gvr);
