<?php
$content = file_get_contents('c:/xampp/htdocs/identitrack/admin/upcc_case_view.php');
$lines = explode("\n", $content);

foreach ($lines as $idx => $line) {
    if (stripos($line, 'manual') !== false || stripos($line, 'consensus') !== false || stripos($line, 'finalize') !== false) {
        echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
