<?php
// Automatic Webhook Deployment Verification File
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>IdentiTrack Webhook Sync Test</title>
    <style>
        body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #0f172a; color: #f8fafc; display: flex; align-items: center; justify-content: center; height: 100vh; margin: 0; }
        .card { background: #1e293b; padding: 40px; border-radius: 16px; border: 1px solid #334155; text-align: center; max-width: 500px; box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5); }
        h1 { color: #38bdf8; margin-top: 0; font-size: 24px; }
        .status { font-size: 18px; color: #4ade80; font-weight: bold; margin: 20px 0; }
        .timestamp { color: #94a3b8; font-size: 13px; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🚀 IdentiTrack Live Webhook Sync Successful!</h1>
        <div class="status">✅ Automatic Deployment is 100% Active!</div>
        <p>This file was created on the local developer machine and automatically deployed to Hostinger via GitHub Webhook.</p>
        <p class="timestamp">Deployed at: <?php echo date('Y-m-d H:i:s T'); ?></p>
    </div>
</body>
</html>
