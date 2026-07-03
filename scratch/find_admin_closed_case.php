<?php
$content = file_get_contents('c:/xampp/htdocs/identitrack/admin/upcc_case_view.php');
$lines = explode("\n", $content);

foreach ($lines as $idx => $line) {
    if (stripos($line, 'CLOSED') !== false || stripos($line, 'decided_category') !== false || stripos($line, 'resolved_on') !== false || stripos($line, 'resolution_date') !== false || stripos($line, 'punishment_details') !== false) {
        if (strpos($line, 'php') !== false || strpos($line, '$case') !== false || strpos($line, '<div') !== false || strpos($line, 'echo') !== false || strpos($line, '?=') !== false) {
            echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
        }
    }
}
