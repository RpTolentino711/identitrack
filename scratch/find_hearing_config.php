<?php
$content = file_get_contents('c:/xampp/htdocs/identitrack/admin/upcc_case_view.php');
$lines = explode("\n", $content);

foreach ($lines as $idx => $line) {
    if (stripos($line, 'Edit Hearing Configuration') !== false || stripos($line, 'editPanel') !== false || stripos($line, 'config_updated') !== false || stripos($line, 'editHearingConfig') !== false || stripos($line, 'Click to Edit') !== false) {
        echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
