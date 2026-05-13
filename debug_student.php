<?php
require 'database/database.php';
$rows = db_all('SELECT student_id, student_email, HEX(student_email) as hex_email FROM student');
foreach($rows as $r) {
    echo "ID: {$r['student_id']} | Email: " . (preg_match('/[a-zA-Z0-9]/', $r['student_email']) ? $r['student_email'] : '[BINARY]') . " | HEX: {$r['hex_email']}\n";
}
