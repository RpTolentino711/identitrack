<?php
$content = file_get_contents('c:/xampp/htdocs/identitrack/UPCC/upccdashboard.php');
$lines = explode("\n", $content);

foreach ($lines as $idx => $line) {
    if (stripos($line, 'dismiss') !== false || stripos($line, 'CLOSED') !== false || stripos($line, 'close') !== false || stripos($line, '✕') !== false || stripos($line, '×') !== false) {
        echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
