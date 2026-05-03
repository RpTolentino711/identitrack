<?php
// File: C:\xampp\htdocs\identitrack\admin\logout_success.php
// Loading screen -> NU logo -> "Successful log out" -> auto redirect to login

require_once __DIR__ . '/../database/database.php';

// If you want to prevent back button from showing dashboard cache (basic attempt)
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Logged Out | SDO Web Portal</title>

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&display=swap" rel="stylesheet">

  <style>
    :root{
      --nu-blue:#36429a;
      --nu-blue-strong:#2d3788;
      --card-bg:#f4f4f5;
      --text:#181818;
      --muted:#6b6b6b;
      --shadow: 0 16px 30px rgba(9, 20, 60, 0.35);
    }
    *{ box-sizing:border-box; }
    body{
      margin:0;
      min-height:100vh;
      font-family: 'Montserrat', 'Segoe UI', Tahoma, sans-serif;
      background:
        linear-gradient(180deg, rgba(27, 41, 118, 0.78), rgba(48, 62, 145, 0.78)),
        url('../assets/wallpaper.png');
      background-size: cover;
      background-position: center;
      display:grid;
      place-items:center;
      padding: 18px;
      color: var(--text);
    }
    .panel{
      width: min(460px, 94vw);
      border-radius: 38px;
      background: var(--card-bg);
      border: 1px solid rgba(255,255,255,.35);
      box-shadow: var(--shadow);
      padding: 28px 30px 26px;
      text-align:center;
    }
    .logo{
      width: 90px;
      height: 90px;
      object-fit: contain;
      margin: 0 auto 12px;
      display:block;
    }
    h1{
      margin: 10px 0 0;
      font-size: 1.7rem;
      font-weight: 800;
      letter-spacing:.2px;
      display:none;
    }
    p{
      margin: 10px 0 0;
      color: var(--muted);
      font-weight: 600;
      display:none;
    }

    /* Loading spinner */
    .spinner{
      width: 58px;
      height: 58px;
      border-radius: 999px;
      border: 7px solid rgba(54,66,154,.18);
      border-top-color: rgba(54,66,154,.95);
      margin: 6px auto 10px;
      animation: spin .9s linear infinite;
    }
    @keyframes spin { to { transform: rotate(360deg); } }

    .loading-text{
      margin-top: 12px;
      font-weight: 700;
      color: rgba(24,24,24,.75);
    }

    .small-link{
      margin-top: 16px;
      display:none;
      font-size: .95rem;
      font-weight: 700;
      color: #4f5da5;
      text-decoration:none;
    }
    .small-link:hover{ text-decoration: underline; }
  </style>
</head>

<body>
  <div class="panel" role="main" aria-label="Logout status">
    <img id="logo" class="logo" src="../assets/logo.png" alt="NU Logo">

    <div id="loading">
      <div class="spinner" aria-label="Loading"></div>
      <div class="loading-text">Logging out...</div>
    </div>

    <h1 id="title">Successful log out</h1>
    <p id="msg">You will be redirected to the login page.</p>

    <a id="link" class="small-link" href="login.php">Go to Login</a>
  </div>

  <script>
    (function(){
      var loading = document.getElementById('loading');
      var title = document.getElementById('title');
      var msg = document.getElementById('msg');
      var link = document.getElementById('link');

      // 1) Show loading for a moment
      setTimeout(function(){
        loading.style.display = 'none';

        // 2) Show success text (logo stays visible)
        title.style.display = 'block';
        msg.style.display = 'block';
        link.style.display = 'inline-block';

        // 3) Redirect after user can read the message
        setTimeout(function(){
          window.location.href = 'login.php';
        }, 2500);
      }, 1400);
    })();
  </script>
</body>
</html>

