<?php
// Test CLI Fallback when HTTP microservice is unreachable
$_POST['category'] = 'Automatic Major Offenses';
$_POST['violation'] = 'Cheating or academic dishonesty, in online or face-to-face settings, before or during an examination.';
$_POST['number_of_offense'] = 'Automatic Major - 2nd Offense';
$_POST['description'] = 'Caught cheating by the prof';
$_GET['action'] = 'predict';
$_GET['case_id'] = '29';
$_GET['student_id'] = '2024-02000';

require __DIR__ . '/../../../admin/api_ai_suggest_sanction.php';
