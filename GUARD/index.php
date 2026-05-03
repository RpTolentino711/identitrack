<?php
ob_start();
session_start();

$logoutSuccess = isset($_GET['logout']) && $_GET['logout'] === '1';

// Only redirect if NOT just logged out
if (!$logoutSuccess && isset($_SESSION['guard_logged_in']) && $_SESSION['guard_logged_in'] === true) {
    header('Location: dashboard.php'); exit;
}

$error = '';
$logoutSuccess = isset($_GET['logout']) && $_GET['logout'] === '1';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        require_once __DIR__ . '/../database/database.php';
        $pdo = db();
        $stmt = $pdo->prepare("SELECT guard_id, full_name, username, password_hash, role, is_active
            FROM security_guard WHERE username = :u LIMIT 1");
        $stmt->execute([':u' => $username]);
        $guard = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($guard && $guard['is_active'] && password_verify($password, $guard['password_hash'])) {
            $_SESSION['guard_logged_in'] = true;
            $_SESSION['guard_id']        = $guard['guard_id'];
            $_SESSION['guard_name']      = $guard['full_name'];
            $_SESSION['guard_role']      = $guard['role'];
            header('Location: dashboard.php?welcome=1'); exit;
        } else {
            $error = 'Invalid username or password.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>IdentiTrack &mdash; Guard Portal</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --bg:      #f0f3fb;
    --surface: #ffffff;
    --navy:    #1b2b6b;
    --navy2:   #2a3f8f;
    --navy-lt: #eef1fb;
    --border:  #dce4f5;
    --border2: #c8d4ee;
    --gold:    #f0a500;
    --gold-lt: #fff8e6;
    --text:    #1a1f36;
    --text2:   #4a5578;
    --muted:   #8896b0;
    --red:     #e53e3e;
    --red-lt:  #fff5f5;
    --red-bd:  #fed7d7;
    --shadow:  0 2px 8px rgba(27,43,107,0.08), 0 8px 32px rgba(27,43,107,0.10);
    --shadow-lg: 0 8px 40px rgba(27,43,107,0.16), 0 2px 8px rgba(27,43,107,0.08);
}

html, body {
    height: 100%;
    background: var(--bg);
    font-family: 'DM Sans', sans-serif;
    color: var(--text);
}

body {
    background-image: url('../assets/wallpaper.png');
    background-size: cover;
    background-position: center;
    background-attachment: fixed;
    background-repeat: no-repeat;
}

body::before {
    content: '';
    position: fixed;
    inset: 0;
    background: rgba(27, 43, 107, 0.25);
    backdrop-filter: blur(2px);
    pointer-events: none;
    z-index: 0;
}

body::after {
    content: '';
    position: fixed;
    inset: 0;
    background-image:
        radial-gradient(circle at 20% 20%, rgba(27,43,107,0.07) 0%, transparent 50%),
        radial-gradient(circle at 80% 80%, rgba(240,165,0,0.06) 0%, transparent 50%);
    pointer-events: none;
    z-index: 0;
    opacity: .3;
}

.page {
    position: relative;
    z-index: 1;
    min-height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px 16px;
}

/* ── Card ── */
.card {
    width: 100%;
    max-width: 400px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 22px;
    padding: 40px 36px 34px;
    box-shadow: var(--shadow-lg);
    animation: rise .5s cubic-bezier(.16,1,.3,1) both;
    position: relative;
    overflow: hidden;
    backdrop-filter: blur(10px);
}

/* Navy top accent bar */
.card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--navy), var(--navy2), var(--gold));
}

@keyframes rise {
    from { opacity: 0; transform: translateY(24px); }
    to   { opacity: 1; transform: translateY(0); }
}

/* ── Brand row ── */
.brand-row {
    display: flex;
    align-items: center;
    gap: 11px;
    margin-bottom: 28px;
}

.brand-logo {
    width: 42px;
    height: 42px;
    border-radius: 11px;
    object-fit: contain;
    border: 1px solid var(--border);
    background: #fff;
    padding: 4px;
    flex-shrink: 0;
}

.brand-text {
    display: flex;
    flex-direction: column;
}

.brand-name {
    font-family: 'Syne', sans-serif;
    font-size: 18px;
    font-weight: 800;
    color: var(--text);
    letter-spacing: -.02em;
    line-height: 1.1;
}
.brand-name em { font-style: normal; color: var(--gold); }

.brand-badge {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    margin-top: 4px;
    background: var(--navy-lt);
    border: 1px solid #c7d2ee;
    border-radius: 100px;
    padding: 2px 9px;
    font-family: 'Syne', sans-serif;
    font-size: 9px;
    font-weight: 700;
    letter-spacing: .14em;
    text-transform: uppercase;
    color: var(--navy);
    width: fit-content;
}
.badge-dot {
    width: 5px; height: 5px;
    border-radius: 50%;
    background: var(--navy);
    animation: blink 2s ease-in-out infinite;
}
@keyframes blink { 0%,100%{opacity:1} 50%{opacity:.2} }

/* ── Headings ── */
.card-title {
    font-family: 'Syne', sans-serif;
    font-size: 22px;
    font-weight: 800;
    color: var(--text);
    margin-bottom: 4px;
    letter-spacing: -.02em;
}
.card-sub {
    font-size: 13.5px;
    color: var(--muted);
    margin-bottom: 26px;
    line-height: 1.5;
}

/* ── Error ── */
.err {
    background: var(--red-lt);
    border: 1px solid var(--red-bd);
    border-radius: 10px;
    padding: 11px 14px;
    font-size: 13px;
    color: var(--red);
    display: flex;
    align-items: center;
    gap: 9px;
    margin-bottom: 20px;
    animation: shake .3s ease;
}
@keyframes shake {
    0%,100% { transform: translateX(0); }
    25%      { transform: translateX(-5px); }
    75%      { transform: translateX(5px); }
}
.err svg { flex-shrink: 0; }

/* ── Fields ── */
.field { margin-bottom: 16px; }

.field label {
    display: block;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: var(--text2);
    margin-bottom: 6px;
}

.field input {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border2);
    border-radius: 10px;
    padding: 11px 14px;
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    color: var(--text);
    outline: none;
    transition: border-color .2s, box-shadow .2s, background .2s;
}
.field input:focus {
    border-color: var(--navy);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(27,43,107,0.1);
}
.field input::placeholder { color: var(--muted); }

/* Password wrapper */
.pw-wrap { position: relative; }
.pw-wrap input { padding-right: 42px; }
.pw-toggle {
    position: absolute;
    top: 50%; right: 12px;
    transform: translateY(-50%);
    background: none;
    border: none;
    cursor: pointer;
    color: var(--muted);
    display: flex;
    align-items: center;
    padding: 0;
    transition: color .2s;
}
.pw-toggle:hover { color: var(--navy); }
.pw-toggle svg { width: 17px; height: 17px; }

/* ── Submit ── */
.btn-submit {
    width: 100%;
    margin-top: 8px;
    padding: 13px;
    background: var(--navy);
    color: #fff;
    border: none;
    border-radius: 11px;
    font-family: 'Syne', sans-serif;
    font-size: 14.5px;
    font-weight: 700;
    letter-spacing: .02em;
    cursor: pointer;
    box-shadow: 0 4px 18px rgba(27,43,107,0.28);
    transition: background .2s, transform .15s, box-shadow .2s;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
}
.btn-submit:hover {
    background: var(--navy2);
    transform: translateY(-1px);
    box-shadow: 0 8px 28px rgba(27,43,107,0.35);
}
.btn-submit:active { transform: translateY(0); }

/* ── Footer ── */
.card-foot {
    text-align: center;
    font-size: 11.5px;
    color: var(--muted);
    margin-top: 22px;
}

/* ── Divider line ── */
.divider {
    height: 1px;
    background: var(--border);
    margin: 22px 0;
}

.back-btn {
    position: absolute;
    top: 16px;
    left: 16px;
    background: rgba(255,255,255,0.85);
    border: 1px solid var(--border);
    border-radius: 9px;
    color: var(--navy);
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    font-weight: 600;
    padding: 8px 12px;
    cursor: pointer;
    box-shadow: 0 2px 10px rgba(0,0,0,0.12);
    transition: background .2s, transform .2s, box-shadow .2s;
}
.back-btn:hover {
    background: #fff;
    transform: translateY(-1px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.secure-note {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 6px;
    font-size: 12px;
    color: var(--muted);
}
.secure-note svg { width: 13px; height: 13px; color: var(--navy); opacity: .5; }

.logout-success-overlay {
    position: fixed;
    inset: 0;
    background: rgba(27, 43, 107, 0.25);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 50;
    padding: 16px;
}
.logout-success-overlay.show { display: flex; }
.logout-success-box {
    width: min(380px, 95vw);
    background: #fff;
    border: 1px solid var(--border2);
    border-radius: 14px;
    box-shadow: var(--shadow-lg);
    padding: 18px;
}
.logout-success-box h3 {
    margin: 0;
    font-family: 'Syne', sans-serif;
    font-size: 18px;
    color: var(--navy);
}
.logout-success-box p {
    margin: 10px 0 0;
    font-size: 13px;
    color: var(--text2);
}
.logout-success-actions {
    margin-top: 16px;
    display: flex;
    justify-content: flex-end;
}
.logout-success-ok {
    min-height: 36px;
    padding: 0 14px;
    border-radius: 10px;
    border: 1px solid var(--navy);
    background: var(--navy);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
}

.loading-overlay {
    position: fixed;
    inset: 0;
    background: rgba(27, 43, 107, 0.35);
    display: none;
    align-items: center;
    justify-content: center;
    z-index: 60;
    padding: 16px;
}
.loading-overlay.show { display: flex; }
.loading-box {
    width: min(360px, 95vw);
    background: #fff;
    border: 1px solid var(--border2);
    border-radius: 14px;
    box-shadow: var(--shadow-lg);
    padding: 18px;
    text-align: center;
}
.loading-spinner {
    width: 38px;
    height: 38px;
    border-radius: 999px;
    border: 3px solid rgba(27,43,107,0.15);
    border-top-color: var(--navy);
    margin: 2px auto 10px;
    animation: guardSpin 0.8s linear infinite;
}
.loading-box h3 {
    margin: 0;
    font-family: 'Syne', sans-serif;
    font-size: 18px;
    color: var(--navy);
}
.loading-box p {
    margin: 8px 0 0;
    font-size: 13px;
    color: var(--text2);
}
@keyframes guardSpin { to { transform: rotate(360deg); } }
</style>
</head>
<body>
<?php if ($logoutSuccess): ?>
<div class="logout-success-overlay show" id="logoutSuccessModal" aria-hidden="false">
    <div class="logout-success-box" role="dialog" aria-modal="true" aria-labelledby="logoutSuccessTitle">
        <h3 id="logoutSuccessTitle">Logged Out</h3>
        <p>Successfully logged out.</p>
        <div class="logout-success-actions">
            <button type="button" class="logout-success-ok" id="logoutSuccessOk">OK</button>
        </div>
    </div>
</div>
<?php endif; ?>
<div class="loading-overlay" id="guardLoginLoading" aria-hidden="true">
    <div class="loading-box" role="status" aria-live="polite">
        <div class="loading-spinner" aria-hidden="true"></div>
        <h3>Signing In</h3>
        <p>Please wait... preparing your Guard dashboard.</p>
    </div>
</div>
<div class="page">
    <button type="button" class="back-btn" onclick="window.history.back();" aria-label="Go back">&larr; Back</button>
    <div class="card">

        <!-- Brand -->
        <div class="brand-row">
            <img src="../assets/logo.png" alt="IdentiTrack Logo" class="brand-logo">
            <div class="brand-text">
                <div class="brand-name">Identi<em>Track</em></div>
                <div class="brand-badge">
                    <span class="badge-dot"></span>
                    Guard Portal
                </div>
            </div>
        </div>

        <div class="card-title">Welcome back</div>
        <div class="card-sub">Sign in to file a student violation report.</div>

        <?php if ($error): ?>
        <div class="err">
            <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" autocomplete="off" id="guardLoginForm">
            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                    placeholder="Enter your username"
                    value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                    autocomplete="username" autofocus>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="pw-wrap">
                    <input type="password" id="password" name="password"
                        placeholder="Enter your password"
                        autocomplete="current-password">
                    <button type="button" class="pw-toggle" id="pwToggle" aria-label="Toggle password">
                        <!-- Eye open -->
                        <svg id="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                        <!-- Eye closed (hidden by default) -->
                        <svg id="eye-closed" style="display:none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/>
                            <path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/>
                            <line x1="1" y1="1" x2="23" y2="23"/>
                        </svg>
                    </button>
                </div>
            </div>

            <button type="submit" class="btn-submit">
                <svg width="15" height="15" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/>
                    <polyline points="10 17 15 12 10 7"/>
                    <line x1="15" y1="12" x2="3" y2="12"/>
                </svg>
                Sign In
            </button>
        </form>

        <div class="divider"></div>

        <div class="secure-note">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2"/>
                <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            Secured portal &middot; NU Lipa IdentiTrack &copy; <?= date('Y') ?>
        </div>

    </div>
</div>

<script>
const pwToggle  = document.getElementById('pwToggle');
const pwInput   = document.getElementById('password');
const eyeOpen   = document.getElementById('eye-open');
const eyeClosed = document.getElementById('eye-closed');
const guardLoginForm = document.getElementById('guardLoginForm');
const guardLoginLoading = document.getElementById('guardLoginLoading');

pwToggle.addEventListener('click', () => {
    const isHidden = pwInput.type === 'password';
    pwInput.type       = isHidden ? 'text' : 'password';
    eyeOpen.style.display   = isHidden ? 'none'  : '';
    eyeClosed.style.display = isHidden ? ''      : 'none';
    pwToggle.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
});

const logoutSuccessModal = document.getElementById('logoutSuccessModal');
const logoutSuccessOk = document.getElementById('logoutSuccessOk');

if (logoutSuccessModal && logoutSuccessOk) {
    logoutSuccessOk.focus();
    logoutSuccessOk.addEventListener('click', () => {
        logoutSuccessModal.classList.remove('show');
        logoutSuccessModal.setAttribute('aria-hidden', 'true');
    });

    logoutSuccessModal.addEventListener('click', (ev) => {
        if (ev.target === logoutSuccessModal) {
            logoutSuccessModal.classList.remove('show');
            logoutSuccessModal.setAttribute('aria-hidden', 'true');
        }
    });

    document.addEventListener('keydown', (ev) => {
        if (!logoutSuccessModal.classList.contains('show')) return;
        if (ev.key === 'Escape' || ev.key === 'Enter') {
            ev.preventDefault();
            logoutSuccessOk.click();
        }
    });
}

if (guardLoginForm && guardLoginLoading) {
    guardLoginForm.addEventListener('submit', () => {
        const username = document.getElementById('username');
        if (!username || !pwInput) return;
        if (!username.value.trim() || !pwInput.value) return;

        const submitBtn = guardLoginForm.querySelector('button[type="submit"]');
        if (submitBtn) submitBtn.disabled = true;
        guardLoginLoading.classList.add('show');
        guardLoginLoading.setAttribute('aria-hidden', 'false');
    });
}
</script>
</body>
</html>
