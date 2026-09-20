<?php
require_once 'c:/xampp/htdocs/identitrack/database/database.php';

$types = db_all("SELECT offense_type_id, code, name, level FROM offense_type ORDER BY offense_type_id ASC");
print_r($types);
