<?php
$content = file_get_contents('c:/xampp/htdocs/identitrack/admin/upcc_case_view.php');
$lines = explode("\n", $content);

foreach ($lines as $idx => $line) {
    if (strpos($line, 'loading') !== false || strpos($line, 'spinner') !== false || strpos($line, 'Overlay') !== false) {
        echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
