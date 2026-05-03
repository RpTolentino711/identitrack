<?php
require 'database/database.php';
db_exec("UPDATE community_service_requirement SET status = 'PENDING_ACCEPTANCE', related_case_id = 13 WHERE requirement_id = 34");
db_exec("UPDATE community_service_requirement SET status = 'PENDING_ACCEPTANCE', related_case_id = 14 WHERE requirement_id = 35");
echo "DB Fixed for Jin.\n";
?>
