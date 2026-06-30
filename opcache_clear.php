<?php
header('Content-Type: text/html; charset=utf-8');
echo "<h2>System Deployment Diagnostic</h2>";

// Check git log
echo "<h3>1. Git Revision on Server</h3>";
if (is_callable('shell_exec') && !in_array('shell_exec', explode(',', ini_get('disable_functions')))) {
    $gitLog = shell_exec('git log -n 1 2>&1');
    if ($gitLog) {
        echo "<pre style='background:#f4f4f4;padding:12px;border:1px solid #ccc;border-radius:4px;'>" . htmlspecialchars($gitLog) . "</pre>";
    } else {
        echo "<p style='color:orange;'>Git log command returned empty. Checking git folder...</p>";
    }
} else {
    echo "<p style='color:red;'>shell_exec is disabled or not available on this server configuration.</p>";
}

// Check last modified dates of files
echo "<h3>2. Code File Modification Dates</h3>";
$filesToCheck = [
    'UPCC/case_view.php',
    'admin/upcc_case_view.php',
    'admin/offenses_student_view.php',
    'api/upcc_case_live.php'
];
echo "<ul>";
foreach ($filesToCheck as $f) {
    $fullPath = __DIR__ . '/' . $f;
    if (file_exists($fullPath)) {
        echo "<li><strong>{$f}</strong>: Last Modified " . date("Y-m-d H:i:s", filemtime($fullPath)) . "</li>";
    } else {
        echo "<li style='color:red;'><strong>{$f}</strong>: File not found!</li>";
    }
}
echo "</ul>";

// Reset OPcache
echo "<h3>3. PHP OPcache Status</h3>";
if (function_exists('opcache_reset')) {
    if (opcache_reset()) {
        echo "<p style='color:green;font-weight:bold;'>✓ OPcache reset command succeeded! Any cached code has been cleared.</p>";
    } else {
        echo "<p style='color:red;'>✗ OPcache reset failed.</p>";
    }
} else {
    echo "<p style='color:gray;'>OPcache is not enabled or not supported on this PHP environment.</p>";
}
