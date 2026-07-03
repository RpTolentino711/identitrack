<?php
$content = file_get_contents('c:/xampp/htdocs/identitrack/UPCC/case_view.php');
$lines = explode("\n", $content);

foreach ($lines as $idx => $line) {
    if (strpos($line, 'roundActive') !== false) {
        echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
