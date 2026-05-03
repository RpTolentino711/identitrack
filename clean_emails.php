<?php
require 'database/database.php';
db_exec("UPDATE student SET student_email = REPLACE(REPLACE(student_email, '\r', ''), '\n', '')");
echo "Emails cleaned of newlines.\n";
?>
