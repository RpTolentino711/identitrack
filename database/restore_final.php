<?php
// File: C:\xampp\htdocs\identitrack\database\restore_final.php

declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

if (file_exists(__DIR__ . '/database.php')) {
    require_once __DIR__ . '/database.php';
} else {
    require_once __DIR__ . '/database/database.php';
}

header('Content-Type: text/plain');
echo "=== IdentiTrack Student Data Restoration ===\n\n";

// 1. Restore student 2023-183482 (Romeo Paolo Tolentino)
echo "Restoring student 2023-183482...\n";
try {
    $r1 = db_exec(
        "UPDATE student SET 
            student_fn = :fn, 
            student_ln = :ln, 
            student_email = :email, 
            phone_number = NULL, 
            home_address = NULL 
        WHERE student_id = :sid",
        [
            ':sid' => '2023-183482',
            ':fn' => 'Romeo Paolo',
            ':ln' => 'Tolentino',
            ':email' => 'romeo.paolo.tolentino.2023-183482@nulipa.local'
        ]
    );
    echo "  Success: Updated $r1 row(s).\n";
} catch (Exception $e) {
    echo "  Error updating 2023-183482: " . $e->getMessage() . "\n";
}

// 2. Restore student 2023-184363 (Jin Maullon)
echo "Restoring student 2023-184363...\n";
try {
    $r2 = db_exec(
        "UPDATE student SET 
            student_fn = :fn, 
            student_ln = :ln, 
            student_email = :email, 
            phone_number = :phone, 
            home_address = :address 
        WHERE student_id = :sid",
        [
            ':sid' => '2023-184363',
            ':fn' => 'Jin',
            ':ln' => 'Maullon',
            ':email' => 'romeopaolotolentino@gmail.com',
            ':phone' => '000-000-0000',
            ':address' => 'Update Required'
        ]
    );
    echo "  Success: Updated $r2 row(s).\n";
} catch (Exception $e) {
    echo "  Error updating 2023-184363: " . $e->getMessage() . "\n";
}

echo "\nVerification of Student Records:\n";
try {
    $rows = db_all("SELECT student_id, student_fn, student_ln, student_email FROM student WHERE student_id IN ('2023-183482', '2023-184363')");
    foreach ($rows as $row) {
        echo "  ID: {$row['student_id']} | Name: {$row['student_fn']} {$row['student_ln']} | Email: {$row['student_email']}\n";
    }
} catch (Exception $e) {
    echo "  Error verifying: " . $e->getMessage() . "\n";
}

echo "\n=== Restoration Complete ===\n";
