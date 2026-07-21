<?php
require_once __DIR__ . '/database/database.php';

try {
    $count = db_exec("
        UPDATE community_service_requirement 
        SET task_name = 'Community Service', updated_at = NOW()
        WHERE task_name LIKE 'UPCC Decision — Case #%'
    ");
    echo "<h1>Database Update Success</h1>";
    echo "<p>Successfully updated $count requirement(s) task names to 'Community Service' on the live server.</p>";
} catch (Exception $e) {
    echo "<h1>Error</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
