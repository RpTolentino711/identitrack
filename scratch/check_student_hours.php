<?php
// File: scratch/check_student_hours.php

$studentId = '2023-183482';

function getDb() {
  // Try fallback 1: root / no password / identitrack
  try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=identitrack;charset=utf8mb4", "root", "", [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "Connected via fallback 1 (identitrack)!\n";
    return $pdo;
  } catch (Exception $e) {
    echo "Fallback 1 failed: " . $e->getMessage() . "\n";
  }

  // Try fallback 2: root / no password / u321173822_track
  try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=u321173822_track;charset=utf8mb4", "root", "", [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "Connected via fallback 2 (u321173822_track)!\n";
    return $pdo;
  } catch (Exception $e) {
    echo "Fallback 2 failed: " . $e->getMessage() . "\n";
  }

  // Try fallback 3: use credentials from .env but force 127.0.0.1
  try {
    $pdo = new PDO("mysql:host=127.0.0.1;dbname=u321173822_track;charset=utf8mb4", "u321173822_titrack", "Pogilameg@10", [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    echo "Connected via fallback 3 (u321173822_track with .env user)!\n";
    return $pdo;
  } catch (Exception $e) {
    echo "Fallback 3 failed: " . $e->getMessage() . "\n";
  }

  die("All connection attempts failed.\n");
}

$pdo = getDb();

function query_one($pdo, $sql, $params = []) {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetch();
}

function query_all($pdo, $sql, $params = []) {
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll();
}

echo "\n=== STUDENT INFO ===\n";
$student = query_one($pdo, "SELECT * FROM student WHERE student_id = :sid", [':sid' => $studentId]);
print_r($student);

echo "\n=== REQUIREMENTS ===\n";
$reqs = query_all($pdo, "SELECT * FROM community_service_requirement WHERE student_id = :sid", [':sid' => $studentId]);
print_r($reqs);

echo "\n=== SESSIONS ===\n";
$sessions = query_all($pdo, "
  SELECT s.* FROM community_service_session s
  JOIN community_service_requirement r ON r.requirement_id = s.requirement_id
  WHERE r.student_id = :sid
", [':sid' => $studentId]);
print_r($sessions);
