<?php
$loginUrl = 'login.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>SDO Web Portal | IDENTITRACK</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@500;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --nu-blue: #2f3a8f;
      --nu-blue-dark: #29337d;
      --nu-gold: #ffd324;
      --ink: #f4f7ff;
    }

    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: 'Montserrat', 'Segoe UI', Tahoma, sans-serif;
      height: 100vh;
      background: #e8ebf5;
      color: var(--ink);
    }

    .portal {
      min-height: 100vh;
      display: grid;
      grid-template-rows: minmax(420px, 74vh) minmax(170px, 26vh);
      overflow: hidden;
    }

    .back-arrow {
      position: fixed;
      top: 18px;
      left: 18px;
      width: 52px;
      height: 52px;
      border-radius: 999px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      text-decoration: none;
      font-size: 2rem;
      font-weight: 700;
      line-height: 1;
      color: #ffffff;
      background: rgba(9, 20, 64, 0.72);
      border: 2px solid rgba(255, 255, 255, 0.75);
      backdrop-filter: blur(3px);
      box-shadow: 0 8px 18px rgba(0, 0, 0, 0.35);
      z-index: 20;
      transition: transform 0.2s ease, background 0.2s ease;
    }

    .back-arrow:hover {
      background: rgba(9, 20, 64, 0.9);
      transform: translateX(-2px);
    }

    .hero {
      background-image: url('../assets/wallpaper.png');
      background-size: 100% auto;
      background-position: center top;
      background-repeat: no-repeat;
      width: 100%;
      position: relative;
    }

    .hero::after {
      content: '';
      position: absolute;
      inset: 0;
      background: linear-gradient(to bottom, rgba(9, 16, 44, 0) 70%, rgba(12, 22, 60, 0.25) 100%);
    }

    .login-strip {
      background: radial-gradient(circle at left top, #414ea7 0%, var(--nu-blue) 42%, var(--nu-blue-dark) 100%);
      box-shadow: inset 0 16px 28px rgba(0, 0, 0, 0.18);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 32px 22px;
      animation: panelReveal 650ms ease-out both;
    }

    .strip-content {
      width: min(1120px, 100%);
      display: flex;
      align-items: center;
      gap: 20px;
      flex-wrap: wrap;
    }

    .mini-logo {
      width: 86px;
      min-width: 86px;
      height: auto;
      display: block;
      object-fit: contain;
      filter: drop-shadow(0 8px 10px rgba(0, 0, 0, 0.25));
    }

    .portal-text {
      display: grid;
      gap: 14px;
    }

    .portal-title {
      font-size: clamp(2.1rem, 4.4vw, 3.45rem);
      font-weight: 800;
      letter-spacing: 0.3px;
      color: #f8faff;
      text-shadow: 0 4px 10px rgba(0, 0, 0, 0.22);
    }

    .btn-login {
      display: inline-flex;
      justify-content: center;
      align-items: center;
      text-decoration: none;
      background: var(--nu-gold);
      color: #60552a;
      font-weight: 700;
      font-size: 1.16rem;
      width: 208px;
      height: 52px;
      border-radius: 999px;
      box-shadow: 0 8px 18px rgba(0, 0, 0, 0.25);
      transition: transform 0.2s ease, box-shadow 0.2s ease, background 0.2s ease;
    }

    .btn-login:hover {
      background: #ffe46c;
      transform: translateY(-3px);
      box-shadow: 0 12px 20px rgba(0, 0, 0, 0.3);
    }

    @keyframes panelReveal {
      from {
        opacity: 0;
        transform: translateY(10px);
      }
      to {
        opacity: 1;
        transform: translateY(0);
      }
    }

    @media (max-width: 720px) {
      .portal {
        grid-template-rows: minmax(290px, 62vh) minmax(220px, 38vh);
      }

      .back-arrow {
        top: 12px;
        left: 12px;
        width: 46px;
        height: 46px;
        font-size: 1.7rem;
      }

      .strip-content {
        justify-content: center;
        text-align: center;
      }

      .portal-text {
        place-items: center;
      }

      .portal-title {
        font-size: clamp(1.75rem, 8vw, 2.5rem);
      }

      .btn-login {
        width: 180px;
        height: 48px;
        font-size: 1rem;
      }
    }
  </style>
</head>
<body>
  <a class="back-arrow" href="../index.php" aria-label="Back to main page">&larr;</a>

  <main class="portal" aria-label="SDO Web Portal">
    <section class="hero" aria-hidden="true"></section>

    <section class="login-strip">
      <div class="strip-content">
        <img class="mini-logo" src="../assets/logo.png" alt="NU Logo">

        <div class="portal-text">
          <h1 class="portal-title">DO Web Portal</h1>
          <a class="btn-login" href="<?php echo htmlspecialchars($loginUrl, ENT_QUOTES, 'UTF-8'); ?>">Login</a>
        </div>
      </div>
    </section>
  </main>
</body>
</html>
