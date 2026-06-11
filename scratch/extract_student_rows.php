<?php
$sqlFile = 'C:/Users/Acer/Downloads/u321173822_track (2).sql';
if (!file_exists($sqlFile)) {
    echo "SQL File not found at $sqlFile\n";
    exit;
}

$content = file_get_contents($sqlFile);

// Find INSERT INTO `student`
if (preg_match('/INSERT INTO `student`[^;]*;/is', $content, $matches)) {
    echo "Found student INSERT statement:\n";
    echo $matches[0] . "\n";
} else if (preg_match('/INSERT INTO student[^;]*;/is', $content, $matches)) {
    echo "Found student INSERT statement (no backticks):\n";
    echo $matches[0] . "\n";
} else {
    echo "No INSERT INTO student found. Searching line by line...\n";
    $handle = fopen($sqlFile, 'r');
    while (($line = fgets($handle)) !== false) {
        if (stripos($line, 'INSERT INTO `student`') !== false || stripos($line, 'INSERT INTO student') !== false) {
            echo $line . "\n";
        }
    }
    fclose($handle);
}
