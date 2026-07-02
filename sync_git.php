<?php
header('Content-Type: text/plain; charset=utf-8');

echo "=== GIT SYNC START ===\n";

// Execute git commands
function run_cmd($cmd) {
    echo "Running: $cmd\n";
    $output = [];
    $retval = 0;
    exec($cmd . ' 2>&1', $output, $retval);
    echo implode("\n", $output) . "\n";
    echo "Exit code: $retval\n\n";
}

run_cmd('git fetch --all');
run_cmd('git reset --hard origin/main');
run_cmd('git log -1 --oneline');

if (file_exists('IdentiTrack.apk')) {
    echo "IdentiTrack.apk size: " . filesize('IdentiTrack.apk') . " bytes\n";
    echo "IdentiTrack.apk modified: " . date("F d Y H:i:s.", filemtime('IdentiTrack.apk')) . "\n";
} else {
    echo "IdentiTrack.apk does not exist!\n";
}

echo "=== GIT SYNC END ===\n";
