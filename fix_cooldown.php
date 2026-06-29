<?php 
$c = file_get_contents("UPCC/case_view.php"); 
$c = str_replace(
    'if (diff < 300) { alert(\'Please wait \' + Math.floor((300 - diff) / 60) + \'m before requesting again.\'); return; }', 
    'if (diff < 30) { alert(\'Please wait \' + Math.floor(30 - diff) + \'s before requesting again.\'); return; }', 
    $c
); 
file_put_contents("UPCC/case_view.php", $c);
