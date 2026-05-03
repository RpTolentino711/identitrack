<?php
require_once __DIR__ . '/database/database.php';
echo "Attempting to unpause all hearings...\n";
try {
    $count = db_exec("UPDATE upcc_case SET hearing_is_paused = 0, hearing_pause_reason = NULL WHERE hearing_is_paused = 1");
    echo "Done. Rows affected: " . ($count ?? 'unknown') . "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
?>
