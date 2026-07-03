<?php
$content = file_get_contents('c:/xampp/htdocs/identitrack/admin/upcc_case_view.php');
$lines = explode("\n", $content);

foreach ($lines as $idx => $line) {
    if ((stripos($line, 'remove') !== false || stripos($line, 'remove_panel') !== false) && (strpos($line, 'btn') !== false || strpos($line, 'button') !== false || strpos($line, '×') !== false || strpos($line, 'x') !== false)) {
        echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
