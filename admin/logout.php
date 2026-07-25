<?php
// File: C:\xampp\htdocs\identitrack\admin\logout.php
// Admin logout

require_once __DIR__ . '/../database/database.php';

admin_logout();
redirect('logout_success.php');