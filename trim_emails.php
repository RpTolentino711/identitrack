<?php
require 'database/database.php';
db_exec("UPDATE student SET student_email = TRIM(student_email)");
echo "Emails trimmed.\n";
?>
