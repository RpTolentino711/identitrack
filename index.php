<?php

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>IDENTITRACK | Landing</title>

  <style>
    :root{
      --bg: #0b1020;
      --card: rgba(255, 255, 255, 0.12);
      --card-border: rgba(255, 255, 255, 0.22);
      --text: #ffffff;
      --muted: rgba(255, 255, 255, 0.75);
      --shadow: 0 12px 35px rgba(0,0,0,.35);
      --radius: 18px;
    }

    * { box-sizing: border-box; }

    body{
      margin: 0;
      font-family: system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
      color: var(--text);
      background: var(--bg);
    }

    /* Full-screen wallpaper */
    .landing-main{
      min-height: 100vh;
      display: grid;
      place-items: center;
      padding: 18px;
      background:
        linear-gradient(180deg, rgba(8,10,18,.55), rgba(8,10,18,.72)),
        url("assets/wallpaper.png");
      background-size: cover;
      background-position: center;
      background-repeat: no-repeat;
    }

    /* Center overlay container */
    .overlay{
      width: min(980px, 92vw);
      padding: 32px 22px 22px;
      border-radius: var(--radius);
      background: rgba(0,0,0,.25);
      backdrop-filter: blur(8px);
      box-shadow: var(--shadow);
      border: 1px solid rgba(255,255,255,.12);
    }

    header{
      text-align: center;
      margin-bottom: 22px;
    }

    h1{
      margin: 0;
      font-size: clamp(28px, 5vw, 44px);
      letter-spacing: 1px;
      font-weight: 800;
    }

    .subtitle{
      margin: 10px 0 0;
      color: var(--muted);
      line-height: 1.4;
    }

    /* 4 boxes */
    .grid{
      display: grid;
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 16px;
      margin-top: 18px;
    }

    .card{
      display: block;
      text-decoration: none;
      color: var(--text);
      padding: 22px 18px;
      border-radius: var(--radius);
      background: var(--card);
      border: 1px solid var(--card-border);
      box-shadow: 0 10px 24px rgba(0,0,0,.25);
      transition: transform .15s ease, background .15s ease, border-color .15s ease, box-shadow .15s ease;
      min-height: 100px;
    }

    /* SDAO → yellow */
    .card.sdao:hover{
      transform: translateY(-3px);
      background: rgba(255, 213, 0, 0.22);
      border-color: rgba(255, 213, 0, 0.65);
      box-shadow: 0 10px 28px rgba(255, 213, 0, 0.20);
    }

    /* Community Service → blue */
    .card.community:hover{
      transform: translateY(-3px);
      background: rgba(41, 149, 255, 0.22);
      border-color: rgba(41, 149, 255, 0.65);
      box-shadow: 0 10px 28px rgba(41, 149, 255, 0.20);
    }

    /* UPCC → orange */
    .card.upcc:hover{
      transform: translateY(-3px);
      background: rgba(255, 120, 30, 0.22);
      border-color: rgba(255, 120, 30, 0.65);
      box-shadow: 0 10px 28px rgba(255, 120, 30, 0.20);
    }

    /* Guard → white hover */
    .card.guard:hover{
      transform: translateY(-3px);
      background: rgba(255,255,255,0.22);
      border-color: rgba(255,255,255,0.45);
      box-shadow: 0 10px 28px rgba(255,255,255,0.20);
    }

    .card-title{
      font-size: 20px;
      font-weight: 800;
      letter-spacing: .2px;
    }

    .card-desc{
      margin-top: 8px;
      color: var(--muted);
    }

    .card.disabled{
      opacity: .55;
      cursor: not-allowed;
      pointer-events: none;
    }

    footer{
      margin-top: 20px;
      text-align: center;
      color: rgba(255,255,255,.65);
    }

    /* Responsive: stack cards on small screens */
    @media (max-width: 820px){
      .grid{ grid-template-columns: repeat(2, 1fr); }
    }

    @media (max-width: 480px){
      .grid{ grid-template-columns: 1fr; }
    }
  </style>
</head>

<body>
  <main class="landing-main">
    <section class="overlay">
      <header>
        <h1>IDENTITRACK</h1>
        <p class="subtitle">
          Digital Infraction &amp; Progressive Offense Management System
        </p>
      </header>

      <section class="grid" aria-label="Portals">

        <!-- SDAO: yellow hover -->
        <a class="card sdao" href="admin/index.php" aria-label="Open SDAO Web Portal">
          <div class="card-title">Displine Office</div>
          <div class="card-desc">Web Portal (Admin)</div>
        </a>

        <!-- Community Service: blue hover -->
        <a class="card community" href="comstudent/land.php" aria-label="Open Community Service Portal">
          <div class="card-title">Community Service</div>
          <div class="card-desc">Student Service Login</div>
        </a>

        <!-- UPCC: orange hover -->
        <a class="card upcc" href="UPCC/upccpanel.php" aria-label="Open UPCC Panel">
          <div class="card-title">UPCC</div>
          <div class="card-desc">Universal Panel Case Conference Portal</div>
        </a>

        <!-- Guard: default hover -->
        <a class="card guard" href="GUARD/index.php" aria-label="Open Guard Portal">
          <div class="card-title">Guard</div>
          <div class="card-desc">Report Student Offense</div>
        </a>

      </section>

      <footer>
        <small>National University Lipa • Student Discipline Office</small>
      </footer>
    </section>
  </main>
</body>
</html>