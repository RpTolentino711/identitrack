<?php
require_once __DIR__ . '/test_native_php_xgb.php';

$cases = [
    ["description" => "cheating during major exam", "category" => "Major Offenses", "violation" => "Cheating", "number_of_offense" => "1st offense"],
    ["description" => "brawl physical fight on campus", "category" => "Major Offenses", "violation" => "Brawl / Physical Assault", "number_of_offense" => "2nd offense"],
    ["description" => "littering trash in hallway", "category" => "Minor Offenses", "violation" => "Littering", "number_of_offense" => "1st offense"],
    ["description" => "possession of deadly weapon firearm knife", "category" => "Major Offenses", "violation" => "Possession of Deadly Weapon", "number_of_offense" => "1st offense"],
    ["description" => "unauthorized smoking inside premises", "category" => "Minor Offenses", "violation" => "Smoking / Vaping", "number_of_offense" => "3rd offense"]
];

foreach ($cases as $i => $c) {
    $res = runNativePhpXgbPrediction($c);
    echo "PHP Case " . ($i + 1) . ": " . $c['violation'] . " (" . $c['number_of_offense'] . ")\n";
    echo "  Sanction: " . $res['sanction'] . " (" . $res['sanction_confidence'] . "%)\n\n";
}
