<?php
$files = [
    'c:/xampp/htdocs/identitrack/admin/upcc_case_view.php',
    'c:/xampp/htdocs/identitrack/UPCC/case_view.php',
    'c:/xampp/htdocs/identitrack/api/upcc_case_live.php'
];

foreach ($files as $file) {
    if (!file_exists($file)) continue;
    echo "=== $file ===\n";
    $content = file_get_contents($file);
    $lines = explode("\n", $content);
    foreach ($lines as $idx => $line) {
        if (stripos($line, 'pause') !== false || stripos($line, 'resume') !== false || stripos($line, 'admit') !== false || stripos($line, 'presence') !== false) {
            echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
        }
    }
}
