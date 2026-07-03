<?php
$content = file_get_contents('c:/xampp/htdocs/identitrack/appdentitrack/lib/src/screens/dashboard_screen.dart');
$lines = explode("\n", $content);

foreach ($lines as $idx => $line) {
    if (strpos($line, '_latestPunishmentCard') !== false || strpos($line, '_dismissedPunishmentId') !== false || strpos($line, 'dismissed_punishment') !== false) {
        echo "Line " . ($idx + 1) . ": " . trim($line) . "\n";
    }
}
