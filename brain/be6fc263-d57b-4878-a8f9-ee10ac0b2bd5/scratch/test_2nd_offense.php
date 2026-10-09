<?php
$_POST['case_id'] = '999';
$_POST['student_id'] = '2023-10001';
$_POST['action'] = 'suggest';
$_POST['category'] = 'Automatic Major Offenses';
$_POST['violation'] = 'Cheating or academic dishonesty, in online or face-to-face settings, before or during an examination.';
$_POST['number_of_offense'] = 'Automatic Major - 2nd Offense';
$_POST['description'] = 'Caught cheating by the prof';

require_once 'c:/xampp/htdocs/identitrack/admin/api_ai_suggest_sanction.php';
