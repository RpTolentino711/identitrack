<?php
require_once 'database/database.php';
$res1 = db_exec("DELETE FROM upcc_case_discussion WHERE message LIKE '%Admin left%' OR message LIKE '%Hearing is paused%' OR message LIKE '%Hearing resumed%'");
$res2 = db_exec("UPDATE upcc_case SET hearing_is_paused = 0, hearing_pause_reason = NULL");
echo "Cleaned chat messages and reset all pause flags.";
unlink(__FILE__);
