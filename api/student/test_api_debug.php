<?php
declare(strict_types=1);
require_once __DIR__ . '/../../database/database.php';
header('Content-Type: application/json; charset=utf-8');

try {
    echo "--- DESCRIBE community_service_requirement ---\n";
    $cs_cols = db_all("DESCRIBE community_service_requirement");
    foreach ($cs_cols as $c) {
        echo $c['Field'] . " (" . $c['Type'] . ")\n";
    }
} catch (Throwable $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
