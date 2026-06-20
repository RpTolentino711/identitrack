<?php
$file = __DIR__ . '/admin/AJAX/offense_letter_send.php';
if (file_exists($file)) {
    $content = file_get_contents($file);
    if (strpos($content, 'letter-box') !== false) {
        echo "<h1 style='color: red;'>❌ OLD VERSION IS STILL ON THE LIVE SERVER</h1>";
        echo "<p>The old code is still inside <b>admin/AJAX/offense_letter_send.php</b> on your live server.</p>";
        echo "<p>This means your FTP upload failed, or Hostinger is caching the old file.</p>";
        echo "<p><b>How to fix:</b> Go to Hostinger File Manager, edit the file manually, and paste the code from your local computer!</p>";
    } else {
        echo "<h1 style='color: green;'>✅ NEW VERSION IS LIVE!</h1>";
        echo "<p>The file is successfully updated! You can now generate an email and it will look perfect.</p>";
    }
} else {
    echo "<h1>Error: File not found at $file</h1>";
}
?>
