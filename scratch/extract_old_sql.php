<?php
$files = [
    'C:/Users/Acer/Downloads/u321173822_track.sql',
    'C:/Users/Acer/Downloads/identitrack_host.sql'
];

foreach ($files as $file) {
    if (!file_exists($file)) {
        echo "File not found: $file\n";
        continue;
    }
    echo "=== Searching in $file ===\n";
    $handle = fopen($file, 'r');
    if ($handle) {
        while (($line = fgets($handle)) !== false) {
            if (strpos($line, '2023-183482') !== false || strpos($line, '2023-184363') !== false) {
                echo "MATCH: " . trim($line) . "\n\n";
            }
        }
        fclose($handle);
    } else {
        echo "Failed to open $file\n";
    }
}
