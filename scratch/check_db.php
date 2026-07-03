<?php
require_once __DIR__ . '/../database/database.php';

try {
    $rounds = db_all("SELECT * FROM upcc_case_vote_round ORDER BY round_no DESC LIMIT 5");
    echo "--- LATEST ROUNDS ---\n";
    print_r($rounds);

    $votes = db_all("SELECT * FROM upcc_case_vote ORDER BY created_at DESC LIMIT 5");
    echo "\n--- LATEST VOTES ---\n";
    print_r($votes);
} catch (Exception $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}
