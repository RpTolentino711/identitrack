<?php
require_once __DIR__ . '/database/database.php';
$caseId = 13;
$adminPresence = db_one("SELECT last_ping, status, TIMESTAMPDIFF(SECOND, last_ping, NOW()) as seconds_ago 
                         FROM upcc_hearing_presence 
                         WHERE case_id = :c AND user_type = 'ADMIN' 
                         ORDER BY last_ping DESC LIMIT 1", [':c' => $caseId]);
echo json_encode($adminPresence);
