<?php
require_once 'c:/xampp/htdocs/identitrack/database/database.php';

// Temporarily include function from offense_new.php
$src = file_get_contents('c:/xampp/htdocs/identitrack/admin/offense_new.php');
preg_match('/function getStudentActiveMinorCycle.*?\n\}/s', $src, $m1);
preg_match('/function renderMinorAlert.*?\n\}/s', $src, $m2);
eval($m1[0]);
eval($m2[0]);
function getOrdinal($n) { return $n . 'th'; }

$sid = '2024-01001';
$tid = 16;
$cycle = getStudentActiveMinorCycle($sid, $tid);

echo "=== CYCLE TEST FOR $sid WITH MIN-016 SELECTED ===\n";
echo "existing_count: " . $cycle['existing_count'] . "\n";
echo "projected_count: " . $cycle['projected_count'] . "\n";
echo "active_count: " . $cycle['active_count'] . "\n";
echo "selected_type_code: " . $cycle['selected_type_code'] . "\n";
echo "selected_type_name: " . $cycle['selected_type_name'] . "\n\n";

echo "--- ALERT HTML ---\n";
echo renderMinorAlert($cycle['projected_count'], 'guardian@test.com', $cycle['existing_count'], false, 0, $sid, $tid);
