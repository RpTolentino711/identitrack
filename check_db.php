<?php
require 'database/database.php';
$rows = db_all("SELECT * FROM upcc_panel_rejoin_requests ORDER BY request_id DESC LIMIT 5");
print_r($rows);
