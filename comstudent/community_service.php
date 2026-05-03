<?php
require_once __DIR__ . '/../database/database.php';

$msg = '';
$msgType = 'info';

function response_wants_json(): bool
{
  if (isset($_GET['format']) && strtolower((string)$_GET['format']) === 'json') {
    return true;
  }

  $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
  return strpos($accept, 'application/json') !== false;
}

function send_json_response(array $payload): void
{
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload);
  exit;
}

if ($_POST) {
  $studentId = trim($_POST['student_id'] ?? '');
  $method = strtoupper(trim((string)($_POST['login_method'] ?? 'NFC')));
  $actionType = strtoupper(trim((string)($_POST['action_type'] ?? 'LOGIN')));
  $reason = trim($_POST['reason'] ?? '');
  $jsonMode = response_wants_json();

  if (!in_array($method, ['NFC', 'RFID', 'MANUAL'], true)) {
    $method = 'NFC';
  }
  if ($method === 'RFID') {
    $method = 'NFC';
  }
  if (!in_array($actionType, ['LOGIN', 'LOGOUT'], true)) {
    $actionType = 'LOGIN';
  }

  if ($studentId === '') {
    $msg = "Missing student ID!";
    $msgType = 'error';
    if ($jsonMode) {
      send_json_response(['ok' => false, 'message' => $msg]);
    }
  } else {
    // Check for any pending request (Login or Logout)
    $anyPending = db_one(
      "SELECT request_type FROM manual_login_request 
       WHERE student_id = :sid AND status = 'PENDING' LIMIT 1",
      [':sid' => $studentId]
    );

    if ($anyPending) {
      $typeLabel = strtoupper($anyPending['request_type']) === 'LOGIN' ? 'Login' : 'Logout';
      $msg = "You already have a $typeLabel request pending. Please wait for the SDO to process it.";
      $msgType = 'info';
    } else {
      // Check active session
      $activeSession = db_one(
        "SELECT css.session_id, css.requirement_id
         FROM community_service_session css
         JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
         WHERE csr.student_id = :sid AND css.time_out IS NULL
         LIMIT 1",
        [':sid' => $studentId]
      );

      if ($actionType === 'LOGOUT') {
        if (!$activeSession) {
          $msg = "You are not currently timed in.";
          $msgType = 'error';
        } else {
          if ($method === 'NFC') {
            // Instant logout for NFC
            db_exec(
              "UPDATE community_service_session SET time_out = NOW(), logout_method = 'NFC' WHERE session_id = :sid",
              [':sid' => (int)$activeSession['session_id']]
            );
            check_requirement_completion((int)$activeSession['requirement_id']);
            $msg = "Scanner logout successful. Your service timer has stopped.";
            $msgType = 'success';
          } else {
            // Strict manual logout: stop timer immediately upon request
            db_exec(
              "UPDATE community_service_session SET time_out = NOW(), logout_method = 'MANUAL' WHERE session_id = :sid",
              [':sid' => (int)$activeSession['session_id']]
            );

            db_exec(
              "INSERT INTO manual_login_request (requirement_id, student_id, request_type, login_method, requested_at, reason, status)
               VALUES (:rid, :sid, 'LOGOUT', 'MANUAL', NOW(), :reason, 'PENDING')",
              [':rid' => (int)$activeSession['requirement_id'], ':sid' => $studentId, ':reason' => $reason]
            );
            $msg = "Manual logout request sent. Your timer has stopped exactly at this time, pending admin validation.";
            $msgType = 'success';
          }
        }
      } else {
        // LOGIN
        if ($activeSession) {
          $msg = "You are already timed in.";
          $msgType = 'info';
        } else {
          db_exec(
            "INSERT INTO manual_login_request (requirement_id, student_id, request_type, login_method, requested_at, reason, status)
             VALUES (NULL, :sid, 'LOGIN', :method, NOW(), :reason, 'PENDING')",
            [':sid' => $studentId, ':method' => $method, ':reason' => $reason]
          );
          $msg = "Login request sent. The admin will assign your task and start your timer.";
          $msgType = 'success';
        }
      }
    }

    if ($jsonMode) {
      send_json_response(['ok' => ($msgType === 'success'), 'message' => $msg]);
    }
  }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" /><title>Community Service | IDENTITRACK</title>
  <style> body{font-family:system-ui,sans-serif;background:#f1f1f9;}
    .main{max-width:400px;margin:80px auto;background:#fff;border-radius:14px;box-shadow:0 6px 22px rgba(0,0,0,.10);padding:32px 22px;}
    h2{font-weight:800;color:#193B8C;}
    .msg{margin-bottom:14px;font-size:19px;}
    .msg.success{color:#0f6d2f;}
    .msg.error{color:#b42318;}
    .msg.info{color:#193B8C;}
  </style>
</head>
<body>
  <section class="main">
    <h2>Login Result</h2>
    <?php if ($msg): ?>
      <div class="msg <?= htmlspecialchars($msgType) ?>"><?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>
    <a href="land.php">Back to Start</a>
  </section>
</body>
</html>