<?php
$content = file_get_contents('c:/xampp/htdocs/identitrack/admin/upcc_case_view.php');
$lines = explode("\n", $content);

foreach ($lines as $idx => $line) {
    if (strpos($line, 'openConsensusDecisionModal') !== false) {
        echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
