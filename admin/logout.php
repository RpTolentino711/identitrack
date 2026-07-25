<?php
// File: C:\xampp\htdocs\identitrack\admin\logout.php
// Admin logout

require_once __DIR__ . '/../database/database.php';

admin_logout();

if (isset($_GET['reason']) && $_GET['reason'] === 'inactivity') {
    redirect('login.php?reason=inactivity');
}
redirect('logout_success.php');