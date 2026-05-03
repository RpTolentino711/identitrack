<?php
// File: C:\xampp\htdocs\identitrack\admin\login.php
// Admin Login (USERNAME ONLY + Password)
// Uses ONLY functions from database/database.php

require_once __DIR__ . '/../database/database.php';

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $username = trim((string)($_POST['username'] ?? ''));
  $password = (string)($_POST['password'] ?? '');

  if (session_status() === PHP_SESSION_NONE) session_start();
  
  $res = admin_login($username, $password);
  if (($res['ok'] ?? false) === true) {
    // Reset failed attempts on success
    unset($_SESSION['admin_login_attempts']);
    
    // 2FA IMPLEMENTATION
    $adminData = admin_find_by_username($username);
    if (!$adminData) {
        $errors[] = "Critical error: Admin data not found after login.";
    } else {
        // Store pre-2fa state
        $_SESSION['admin_pre_2fa'] = [
            'admin_id' => $adminData['admin_id'],
            'username' => $adminData['username'],
            'full_name' => $adminData['full_name']
        ];
        
        // Clear normal admin session if it was set by admin_login (admin_login usually sets it)
        unset($_SESSION['admin_id']);
        unset($_SESSION['admin_username']);

        // Redirect to a helper that sends the OTP and shows the UI
        redirect('login_otp.php?init=1');
    }
  } else {
    // Increment failed attempts
    $_SESSION['admin_login_attempts'] = ($_SESSION['admin_login_attempts'] ?? 0) + 1;
    $errors[] = (string)($res['error'] ?? 'Login failed.');
  }
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Admin Login | SDO Web Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;600;700;800&display=swap" rel="stylesheet">

  <style>
    :root {
      --nu-blue: #36429a;
      --nu-blue-strong: #2d3788;
      --card-bg: #f4f4f5;
      --text: #181818;
      --muted: #8c8c8c;
      --input-bg: #e8e8eb;
      --input-text: #333333;
      --btn-blue: #39439b;
      --btn-blue-hover: #2e3988;
      --danger: #a32b2b;
      --shadow: 0 16px 30px rgba(9, 20, 60, 0.35);
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      min-height: 100vh;
      font-family: 'Montserrat', 'Segoe UI', Tahoma, sans-serif;
      color: var(--text);
      background:
        linear-gradient(180deg, rgba(27, 41, 118, 0.78), rgba(48, 62, 145, 0.78)),
        url('../assets/wallpaper.png');
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
      display: grid;
      place-items: center;
      padding: 18px;
    }

    .panel {
      width: min(460px, 94vw);
      border-radius: 38px;
      background: var(--card-bg);
      border: 1px solid rgba(255, 255, 255, 0.32);
      box-shadow: var(--shadow);
      padding: 28px 30px 24px;
    }

    .brand { text-align: center; margin-bottom: 24px; }

    .brand img {
      width: 78px;
      height: auto;
      display: block;
      margin: 0 auto 12px;
    }

    .brand h1 {
      margin: 0;
      font-size: 2rem;
      font-weight: 800;
      letter-spacing: 0.2px;
    }

    .brand p {
      margin: 6px 0 0;
      font-size: 1rem;
      font-weight: 600;
      color: var(--muted);
    }

    label {
      display: block;
      font-size: 1.24rem;
      font-weight: 700;
      margin: 0 0 8px;
    }

    .field { margin-bottom: 18px; }
    .password-wrap { position: relative; }

    input {
      width: 100%;
      height: 54px;
      border-radius: 14px;
      border: 1px solid transparent;
      background: var(--input-bg);
      color: var(--input-text);
      padding: 0 16px;
      font-size: 1rem;
      outline: none;
      transition: border-color 0.2s ease, box-shadow 0.2s ease;
    }

    .password-wrap input { padding-right: 52px; }

    .toggle-password {
      position: absolute;
      top: 50%;
      right: 12px;
      transform: translateY(-50%);
      width: 34px;
      height: 34px;
      border: 0;
      border-radius: 10px;
      background: transparent;
      color: #5a6290;
      cursor: pointer;
      font-size: 1.1rem;
      line-height: 1;
      display: inline-flex;
      align-items: center;
      justify-content: center;
    }

    .toggle-password .eye-icon { position: relative; display: inline-block; }

    .toggle-password.is-hidden .eye-icon::after {
      content: '';
      position: absolute;
      left: -2px;
      right: -2px;
      top: 50%;
      height: 2px;
      background: currentColor;
      transform: rotate(-35deg);
      transform-origin: center;
      border-radius: 3px;
    }

    .toggle-password:hover { background: rgba(57, 67, 155, 0.1); }

    input::placeholder { color: #b8b8bb; }

    input:focus {
      border-color: rgba(56, 67, 156, 0.65);
      box-shadow: 0 0 0 3px rgba(56, 67, 156, 0.14);
    }

    .btn {
      width: 100%;
      height: 52px;
      border-radius: 13px;
      border: none;
      cursor: pointer;
      background: linear-gradient(180deg, var(--btn-blue), var(--nu-blue-strong));
      color: #f7f8ff;
      font-size: 1.15rem;
      font-weight: 700;
      letter-spacing: 0.2px;
      box-shadow: 0 8px 16px rgba(45, 55, 130, 0.32);
      transition: transform 0.2s ease, background 0.2s ease;
    }

    .btn:hover {
      background: linear-gradient(180deg, #2f3b90, #273279);
      transform: translateY(-1px);
    }

    .forgot {
      display: block;
      text-align: center;
      margin-top: 12px;
      color: #6f7bb7;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.96rem;
    }

    .divider { margin: 22px 0 10px; border-top: 1px solid rgba(24, 24, 24, 0.2); }

    .back-link {
      display: inline-block;
      color: #4f5da5;
      text-decoration: none;
      font-weight: 600;
      font-size: 0.95rem;
    }

    .back-link:hover { text-decoration: underline; }

    .errors {
      margin: 0 0 16px;
      padding: 12px 14px;
      border-radius: 12px;
      border: 1px solid rgba(163, 43, 43, 0.25);
      background: rgba(163, 43, 43, 0.07);
      color: var(--danger);
    }

    @media (max-width: 540px) {
      .panel { border-radius: 30px; padding: 24px 18px 20px; }
      .brand h1 { font-size: 1.74rem; }
      label { font-size: 1.07rem; }
    }
  </style>
</head>

<body>
  <div class="panel" role="main" aria-label="Admin login form">
    <div class="brand">
      <img src="../assets/logo.png" alt="NU Logo">
      <h1>SDO Web Portal</h1>
      <p>Student Discipline Office</p>
    </div>

    <?php if (!empty($errors)): ?>
      <div class="errors" role="alert">
        <strong>Login failed:</strong>
        <ul style="margin:8px 0 0; padding-left: 18px;">
          <?php foreach ($errors as $err): ?>
            <li><?php echo e($err); ?></li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endif; ?>

    <form method="post" action="">
      <div class="field">
        <label for="username">Username</label>
        <input
          id="username"
          name="username"
          type="text"
          placeholder="Enter your username"
          required
          autocomplete="username"
          value="<?php echo e((string)($_POST['username'] ?? '')); ?>"
        />
      </div>

      <div class="field">
        <label for="password">Password</label>
        <div class="password-wrap">
          <input
            id="password"
            name="password"
            type="password"
            placeholder="Enter your password"
            required
            autocomplete="current-password"
          />
          <button
            type="button"
            class="toggle-password is-hidden"
            id="togglePassword"
            aria-label="Show password"
            aria-controls="password"
            aria-pressed="false"
          >
            <span class="eye-icon" aria-hidden="true">&#128065;</span>
          </button>
        </div>
      </div>

      <button class="btn" type="submit">Login to Dashboard</button>
      
      <?php if (($_SESSION['admin_login_attempts'] ?? 0) >= 3): ?>
        <a class="forgot" href="forgot_password.php">Forgot Password?</a>
      <?php endif; ?>

      <div class="divider"></div>
      <a class="back-link" href="index.php">&larr; Back to SDO Landing</a>
    </form>
  </div>

  <script>
    (function () {
      var passwordInput = document.getElementById('password');
      var toggleBtn = document.getElementById('togglePassword');

      if (!passwordInput || !toggleBtn) return;

      toggleBtn.addEventListener('click', function () {
        var isHidden = passwordInput.type === 'password';
        passwordInput.type = isHidden ? 'text' : 'password';
        toggleBtn.classList.toggle('is-hidden', !isHidden);
        toggleBtn.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        toggleBtn.setAttribute('aria-pressed', isHidden ? 'true' : 'false');
      });
    })();
  </script>
</body>
</html>

