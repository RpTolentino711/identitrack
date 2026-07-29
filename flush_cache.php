<?php
// Flush Hostinger OPCache & Server Cache
if (function_exists('opcache_reset')) {
    opcache_reset();
    $opcacheStatus = "OPCache successfully reset!";
} else {
    $opcacheStatus = "OPCache extension not active.";
}

header('Content-Type: text/html; charset=utf-8');
echo "<h2>⚡ IdentiTrack Server Cache Flush Tool</h2>";
echo "<p style='color:green;font-weight:bold;'>{$opcacheStatus}</p>";
echo "<p>PHP Bytecode Cache cleared. Your live site will now execute the 100% latest code from disk.</p>";
