<?php
$_SERVER['REQUEST_METHOD'] = 'POST';
$student_id = '2023-183482'; // Romeo Paolo Tolentino

// Let's read the API file but mock the auth header check or use a token.
// Let's see if we can just define a function that does the logic without require_once/include.
// Or we can just generate a token in auth_session table for this student, and pass it in the Authorization header.
$pdo = new PDO("mysql:host=127.0.0.1;dbname=identitrack;charset=utf8mb4", "root", "", [
  PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
  PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Let's create an active token for this student
$token = "test_token_123";
$hash = password_hash($token, PASSWORD_DEFAULT);
$pdo->exec("DELETE FROM auth_session WHERE student_id = '2023-183482'");
$pdo->exec("INSERT INTO auth_session (student_id, session_token_hash, actor_type, expires_at) VALUES ('2023-183482', '$hash', 'STUDENT', DATE_ADD(NOW(), INTERVAL 1 DAY))");

// Now we can call the API using a real curl request or by setting headers.
$_SERVER['HTTP_AUTHORIZATION'] = "Bearer $token";

// Let's write the JSON input
$input = json_encode(['student_id' => '2023-183482']);
// In PHP, we can't easily override php://input, but we can call a function or run a curl.
// Let's run a curl to the local server!
// Wait, is XAMPP running? Let's check.
