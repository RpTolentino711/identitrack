<?php
include 'database/database.php';
$sessions = db_all("SELECT * FROM community_service_session ORDER BY session_id DESC LIMIT 5");
foreach ($sessions as $s) {
    echo "Session ID: " . $s['session_id'] . "\n";
    echo "Time In: " . $s['time_in'] . "\n";
    echo "Time Out: " . $s['time_out'] . "\n";
    
    if ($s['time_in'] && $s['time_out']) {
        $in = new DateTime($s['time_in']);
        $out = new DateTime($s['time_out']);
        $diff = $in->diff($out);
        $hours = $diff->h + ($diff->i / 60) + ($diff->s / 3600) + ($diff->days * 24);
        echo "Calculated Hours: " . number_format($hours, 4) . "\n";
    }
    echo "-------------------\n";
}
?>
