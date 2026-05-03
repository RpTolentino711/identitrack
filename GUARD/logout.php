<?php
ob_start();
session_start();

// Clear all session variables
$_SESSION = [];

// Force expire the session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Destroy session
session_destroy();
session_write_close();

// Redirect to login with success flag
header('Location: index.php?logout=1');
exit;