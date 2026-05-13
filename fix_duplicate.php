<?php
require 'database/database.php';
db_exec("UPDATE student SET student_email = 'temp_20040330@nulipa.local' WHERE student_id = '2004-03-30'");
echo "Fixed 2004-03-30 email.\n";
