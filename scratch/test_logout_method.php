<?php
require 'database/database.php';

// 1. Create a dummy session
db_exec("INSERT INTO community_service_session (requirement_id, time_in, login_method) VALUES (1, NOW(), 'NFC')");
$sessionId = db_last_id();
echo "Created session $sessionId\n";

// 2. Simulate manual logout (like in comstudent/community_service.php)
db_exec(
    "UPDATE community_service_session SET time_out = NOW(), logout_method = 'MANUAL' WHERE session_id = :sid",
    [':sid' => $sessionId]
);
echo "Logged out session $sessionId manually.\n";

// 3. Verify
$session = db_one("SELECT logout_method FROM community_service_session WHERE session_id = :sid", [':sid' => $sessionId]);
echo "Stored logout_method: " . ($session['logout_method'] ?? 'NULL') . "\n";

if ($session['logout_method'] === 'MANUAL') {
    echo "SUCCESS: logout_method saved correctly.\n";
} else {
    echo "FAILURE: logout_method NOT saved correctly.\n";
}
