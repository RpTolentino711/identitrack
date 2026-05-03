<?php
require_once __DIR__ . '/../database/database.php';
require_admin();

$admin = admin_current();
$fullName = trim((string)($admin['full_name'] ?? ''));
if ($fullName === '') {
  $fullName = (string)($admin['username'] ?? 'Admin');
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Welcome | SDO Web Portal</title>
  <style>
    :root {
      --blue: #36429a;
      --blue-dark: #2d3788;
      --card: rgba(255, 255, 255, 0.95);
      --text: #1d2345;
      --muted: #6670a3;
    }

    * { box-sizing: border-box; }

    body {
      margin: 0;
      min-height: 100vh;
      font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
      background:
        linear-gradient(180deg, rgba(27, 41, 118, 0.78), rgba(48, 62, 145, 0.78)),
        url('../assets/wallpaper.png');
      background-size: cover;
      background-position: center;
      display: grid;
      place-items: center;
      padding: 18px;
      color: var(--text);
    }

    .panel {
      width: min(500px, 95vw);
      border-radius: 24px;
      background: var(--card);
      border: 1px solid rgba(255, 255, 255, 0.6);
      box-shadow: 0 20px 40px rgba(10, 20, 60, 0.28);
      padding: 26px 24px;
      text-align: center;
      overflow: hidden;
    }

    .state { display: none; }
    .state.show { display: block; }

    .spinner {
      width: 58px;
      height: 58px;
      border-radius: 50%;
      border: 6px solid rgba(54, 66, 154, 0.18);
      border-top-color: var(--blue);
      margin: 0 auto;
      animation: spin 1s linear infinite;
    }

    .loading-text {
      margin-top: 14px;
      font-size: 1.05rem;
      font-weight: 600;
      color: var(--muted);
    }

    .logo {
      width: 92px;
      height: auto;
      display: block;
      margin: 0 auto 12px;
      filter: drop-shadow(0 8px 12px rgba(25, 42, 120, 0.2));
    }

    .welcome-title {
      margin: 0;
      font-size: 1.7rem;
      font-weight: 800;
      color: var(--blue-dark);
      line-height: 1.2;
    }

    .welcome-name {
      margin-top: 8px;
      font-size: 1.1rem;
      font-weight: 600;
      color: #2c356e;
    }

    .small-note {
      margin-top: 10px;
      font-size: 0.92rem;
      color: var(--muted);
    }

    @keyframes spin {
      to { transform: rotate(360deg); }
    }
  </style>
</head>
<body>
  <main class="panel" role="main" aria-label="Login success transition">
    <section id="loadingState" class="state show" aria-live="polite">
      <div class="spinner" aria-hidden="true"></div>
      <div class="loading-text">Signing in...</div>
    </section>

    <section id="welcomeState" class="state" aria-live="polite">
      <img class="logo" src="../assets/logo.png" alt="NU Logo">
      <h1 class="welcome-title">Welcome</h1>
      <div class="welcome-name"><?php echo e($fullName); ?></div>
      <div class="small-note">Login successful. Redirecting to dashboard...</div>
    </section>
  </main>

  <script>
    (function () {
      var loading = document.getElementById('loadingState');
      var welcome = document.getElementById('welcomeState');

      setTimeout(function () {
        loading.classList.remove('show');
        welcome.classList.add('show');

        setTimeout(function () {
          window.location.href = 'dashboard.php';
        }, 1600);
      }, 1200);
    })();
  </script>
</body>
</html>


