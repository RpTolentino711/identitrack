<?php
require_once __DIR__ . '/../database/database.php';
db_exec("DELETE FROM upcc_case_panel_acceptance WHERE case_id = 10");
db_exec("INSERT IGNORE INTO upcc_case_panel_member (case_id, upcc_id, assigned_at) VALUES (10, 1, NOW())");
db_exec("UPDATE upcc_case SET assigned_panel_members = '[1]' WHERE case_id = 10");
echo "Done.";
