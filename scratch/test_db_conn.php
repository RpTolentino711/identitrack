<?php
$configs = [
    ['user' => 'root', 'pass' => ''],
    ['user' => 'u321173822_titrack', 'pass' => 'Pogilameg@10'],
];

foreach ($configs as $cfg) {
    try {
        $dsn = "mysql:host=localhost;dbname=u321173822_track;charset=utf8mb4";
        $pdo = new PDO($dsn, $cfg['user'], $cfg['pass']);
        echo "SUCCESS with user: {$cfg['user']}\n";
        exit(0);
    } catch (PDOException $e) {
        echo "FAILED with user: {$cfg['user']} - " . $e->getMessage() . "\n";
    }
}
echo "ALL ATTEMPTS FAILED\n";
exit(1);
