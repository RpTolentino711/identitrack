<?php
$_GET['check_username'] = 1;
$_GET['username'] = 'Admin';
echo "--- TESTING ADMIN ---\n";
ob_start();
include 'c:/xampp/htdocs/identitrack/admin/login.php';
$out1 = ob_get_clean();
echo $out1 . "\n";

$_GET['check_username'] = 1;
$_GET['username'] = 'asdasdas';
echo "--- TESTING INVALID ---\n";
ob_start();
include 'c:/xampp/htdocs/identitrack/admin/login.php';
$out2 = ob_get_clean();
echo $out2 . "\n";
