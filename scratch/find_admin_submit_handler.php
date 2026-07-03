<?php
$content = file_get_contents('c:/xampp/htdocs/identitrack/admin/upcc_case_view.php');
$lines = explode("\n", $content);

foreach ($lines as $idx => $line) {
    if (strpos($line, 'Record Final Decision') !== false || strpos($line, 'decided_category') !== false || strpos($line, 'final_decision') !== false) {
        if (strpos($line, '$_POST') !== false || strpos($line, 'if (') !== false || strpos($line, 'UPDATE upcc_case') !== false) {
            echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
        }
    }
}
