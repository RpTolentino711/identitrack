<?php
$env = parse_ini_file(__DIR__ . '/../../.env');
$host = $env['DB_HOST'] ?? '127.0.0.1';
$db   = $env['DB_NAME'] ?? 'identitrack';
$user = $env['DB_USER'] ?? 'root';
$pass = $env['DB_PASS'] ?? '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass);
    
    $stmt = $pdo->prepare("SELECT upcc_id, username, full_name, email FROM upcc_user WHERE LOWER(username) = 'upccpanel3'");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "UPCC_USER:\n";
    print_r($rows);

    $stmt2 = $pdo->prepare("SELECT admin_id, username, name, email FROM admin WHERE LOWER(username) = 'upccpanel3'");
    $stmt2->execute();
    $rows2 = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "ADMIN:\n";
    print_r($rows2);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
