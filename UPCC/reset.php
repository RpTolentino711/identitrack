<?php
require_once __DIR__ . '/../database/database.php';
db_exec("DELETE FROM upcc_case_panel_acceptance WHERE case_id = 10");
echo "Done.";
