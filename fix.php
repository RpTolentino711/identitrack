<?php 
$c = file_get_contents("admin/upcc_case_view.php"); 
$c = str_replace(
    'fetch(`../api/upcc_case_live.php?case_id=&actor=admin`, { method: \'POST\', body: fd })', 
    'fetch(`../api/upcc_case_live.php?case_id=${CASE_ID}&actor=admin`, { method: \'POST\', body: fd })', 
    $c
); 
file_put_contents("admin/upcc_case_view.php", $c);
