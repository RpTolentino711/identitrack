<?php
require_once __DIR__ . '/database/database.php';
$scan = '3667085316';
$pepper = 'IDENTITRACK_SCANNER_PEPPER_V1_CHANGE_ME';
$hash = hash('sha256', $pepper . ':' . strtoupper(trim($scan)));
// Clear it from whoever has it currently
db_exec("UPDATE student SET scanner_id_hash = NULL WHERE scanner_id_hash = :hash", [':hash' => $hash]);
// Map it to Romeo
db_exec("UPDATE student SET scanner_id_hash = :hash WHERE student_email = 'romeotolentino804@gmail.com'", [':hash' => $hash]);
echo "Scanner ID cleared from old record and mapped successfully to Romeo!\n";
