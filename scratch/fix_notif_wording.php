<?php
require_once __DIR__ . '/../database/database.php';
echo "Updating existing community service notifications...\n";
$sql = "UPDATE notification SET message = REPLACE(message, 'Your community service session has been approved by the SDO. Your timer is now running.', 'The community service session has been approved and started. The timer is now running.') WHERE type = 'COMMUNITY_LOGIN'";
$affected = db_exec($sql);
echo "Done. Affected rows: " . ($affected ?: 0) . "\n";
?>
