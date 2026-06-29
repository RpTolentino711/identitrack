<?php
$f = 'api/upcc_case_live.php';
$c = file_get_contents($f);

$c = str_replace(
    "SELECT user_id, status, last_ping_at",
    "SELECT user_id, status, last_ping",
    $c
);

$c = str_replace(
    "\$row['last_ping_at']",
    "\$row['last_ping']",
    $c
);

file_put_contents($f, $c);
echo "Replaced.";
