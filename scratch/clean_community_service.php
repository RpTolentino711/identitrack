<?php
require 'database/database.php';

echo "Cleaning community service tables...\n";

// Clear active and completed sessions
$res1 = db_exec("DELETE FROM community_service_session");
echo "Sessions cleared: " . $res1 . "\n";

// Clear pending requests
$res2 = db_exec("DELETE FROM manual_login_request");
echo "Requests cleared: " . $res2 . "\n";

// Optionally reset requirement progress if needed (not requested but good for testing)
// db_query("UPDATE community_service_requirement SET status = 'ACTIVE' WHERE status = 'COMPLETED'");

echo "Cleanup complete. You can now test fresh.\n";
