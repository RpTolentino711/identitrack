<?php
$content = file_get_contents('c:/xampp/htdocs/identitrack/UPCC/case_view.php');
$lines = explode("\n", $content);

foreach ($lines as $idx => $line) {
    if (stripos($line, 'dismiss') !== false || stripos($line, 'close') !== false || stripos($line, 'CLOSED') !== false || stripos($line, 'Case closed') !== false || stripos($line, 'final_decision') !== false) {
        echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
