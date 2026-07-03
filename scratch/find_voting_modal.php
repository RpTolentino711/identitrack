<?php
$content = file_get_contents('c:/xampp/htdocs/identitrack/UPCC/case_view.php');
$lines = explode("\n", $content);
$start = 0;
$end = 0;

foreach ($lines as $idx => $line) {
    if (strpos($line, 'id="votingModal"') !== false) {
        $start = $idx + 1;
        break;
    }
}

if ($start > 0) {
    echo "Found votingModal at line $start\n";
}
