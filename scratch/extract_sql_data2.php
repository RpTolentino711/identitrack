<?php
$sqlFile = 'C:/Users/Acer/Downloads/u321173822_track (2).sql';
if (!file_exists($sqlFile)) {
    echo "SQL File not found at $sqlFile\n";
    exit;
}

$handle = fopen($sqlFile, 'r');
if ($handle) {
    while (($line = fgets($handle)) !== false) {
        if (strpos($line, '2026-000001') !== false) {
            echo "MATCH: " . trim($line) . "\n\n";
        }
    }
    fclose($handle);
} else {
    echo "Failed to open file.\n";
}
