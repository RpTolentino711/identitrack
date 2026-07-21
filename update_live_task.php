<?php
require_once __DIR__ . '/database/database.php';

try {
    // 1. Update any remaining 'UPCC Decision — Case #%' to 'University Service'
    $count1 = db_exec("
        UPDATE community_service_requirement 
        SET task_name = 'University Service', updated_at = NOW()
        WHERE task_name LIKE 'UPCC Decision — Case #%'
    ");

    // 2. Also rename any recently updated 'Community Service' to 'University Service'
    $count2 = db_exec("
        UPDATE community_service_requirement 
        SET task_name = 'University Service', updated_at = NOW()
        WHERE task_name = 'Community Service'
    ");

    $total = $count1 + $count2;
    echo "<h1>Database Update Success</h1>";
    echo "<p>Successfully updated $total requirement(s) task names to 'University Service' on the live server.</p>";
} catch (Exception $e) {
    echo "<h1>Error</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
