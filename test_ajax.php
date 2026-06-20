<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();
$_SESSION['admin_id'] = 1;
$_SESSION['admin_username'] = 'identitrack';

$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['offense_id'] = 5; // Use an ID that actually exists (find one)
$_POST['subject'] = 'Test';
$_POST['body'] = 'Test body';
$_POST['guardian_email'] = 'identitrack@identitrack.site';

// Mock the DB to find a valid offense ID
require_once 'database/database.php';
$offense = db_one("SELECT offense_id FROM offense ORDER BY offense_id DESC LIMIT 1");
if ($offense) {
    $_POST['offense_id'] = $offense['offense_id'];
}

echo "Testing offense ID: " . $_POST['offense_id'] . "\n";

ob_start();
try {
    require 'admin/AJAX/offense_letter_send.php';
} catch (Throwable $e) {
    echo "\nFATAL: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine();
}
$out = ob_get_clean();
echo "\nOUTPUT:\n" . $out;
