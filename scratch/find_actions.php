<?php
$content = file_get_contents('c:/xampp/htdocs/identitrack/api/upcc_case_live.php');
$lines = explode("\n", $content);

foreach ($lines as $idx => $line) {
    if (strpos($line, 'action ===') !== false || strpos($line, 'action ==') !== false) {
        echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
