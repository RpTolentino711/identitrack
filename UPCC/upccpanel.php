<?php
session_start();
require_once __DIR__ . '/../database/database.php';

try {
    $col = db_one("SHOW COLUMNS FROM upcc_user LIKE 'must_change_password'");
    if (!$col) {
        // Existing accounts should continue logging in; only newly created members are forced to reset.
        db_exec("ALTER TABLE upcc_user ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
    } else {
        db_exec("ALTER TABLE upcc_user MODIFY COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
    }
    db_exec("UPDATE upcc_user SET must_change_password = 0 WHERE must_change_password IS NULL");
} catch (Exception $e) {
    error_log('UPCC must_change_password migration failed: ' . $e->getMessage());
}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    upcc_logout();
    unset(
        $_SESSION['upcc_authenticated'],
        $_SESSION['upcc_pending_otp'],
        $_SESSION['upcc_otp_val'],
        $_SESSION['upcc_otp_user'],
        $_SESSION['upcc_otp_time']
    );
    header('Location: upccpanel.php');
    exit;
}

// Already fully authenticated -> go straight to dashboard
if (isset($_SESSION['upcc_authenticated']) && upcc_current()) {
    $u = upcc_current();
    $need = db_one("SELECT must_change_password FROM upcc_user WHERE upcc_id = :id", [':id' => (int)($u['upcc_id'] ?? 0)]);
    if ((int)($need['must_change_password'] ?? 0) === 1) {
        header('Location: upcc_change_password.php');
    } else {
        header('Location: upccdashboard.php');
    }
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    $result = upcc_login($username, $password);

    if ($result['ok']) {
        $_SESSION['upcc_pending_otp'] = $result['user']['username'];
        header('Location: send_otp.php');
        exit;
    } else {
        $error = $result['error'];
    }
}

$error = $error ?: ($_SESSION['login_error'] ?? '');
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>UPCC Panel &mdash; Login</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --bg-base: #0f172a;
            --card-bg: rgba(15, 23, 42, 0.6);
            --border: rgba(255, 255, 255, 0.08);
            --accent: #3b82f6;
            --accent-glow: rgba(59, 130, 246, 0.4);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --danger: #ef4444;
        }

        body {
            background-color: var(--bg-base);
            background-image: 
                radial-gradient(ellipse at top right, rgba(59, 130, 246, 0.15), transparent 50%),
                radial-gradient(ellipse at bottom left, rgba(147, 51, 234, 0.1), transparent 50%);
            color: var(--text-main);
            font-family: 'DM Sans', sans-serif;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .wrapper {
            width: 100%;
            max-width: 440px;
            padding: 24px;
            animation: fadeUp 0.6s cubic-bezier(0.16, 1, 0.3, 1) both;
        }

        .logo-row {
            display: flex;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 32px;
        }
        .logo-mark {
            width: 56px; height: 56px;
            border-radius: 14px;
            object-fit: cover;
            box-shadow: 0 4px 20px rgba(0,0,0,0.3);
        }
        .logo-text {
            font-family: 'Syne', sans-serif;
            font-weight: 700;
            font-size: 16px;
            color: var(--text-main);
            letter-spacing: 2.5px;
            text-transform: uppercase;
        }

        .card {
            background: var(--card-bg);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px 32px;
            position: relative;
            overflow: hidden;
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            box-shadow: 0 24px 48px rgba(0,0,0,0.4), inset 0 1px 1px rgba(255,255,255,0.05);
        }

        .card-title {
            font-family: 'Syne', sans-serif;
            font-size: 28px;
            font-weight: 700;
            line-height: 1.2;
            text-align: center;
            margin-bottom: 8px;
        }
        .card-sub {
            font-size: 14px;
            text-align: center;
            color: var(--text-muted);
            margin-bottom: 32px;
        }

        .alert-err {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.2);
            color: #fca5a5;
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 14px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-err svg { width: 18px; height: 18px; flex-shrink: 0; }

        .field { margin-bottom: 20px; }
        .field label {
            display: block;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .field input {
            width: 100%;
            padding: 14px 16px;
            background: rgba(0, 0, 0, 0.2);
            border: 1px solid var(--border);
            border-radius: 12px;
            color: var(--text-main);
            font-family: 'DM Sans', sans-serif;
            font-size: 15px;
            outline: none;
            transition: all 0.3s ease;
        }
        .field input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 4px var(--accent-glow);
            background: rgba(0, 0, 0, 0.4);
        }
        .field input::placeholder { color: rgba(148, 163, 184, 0.5); }

        .input-wrapper { position: relative; }
        .input-wrapper input { padding-right: 50px; }

        .eye-toggle {
            position: absolute;
            right: 14px; top: 50%;
            transform: translateY(-50%);
            background: none; border: none;
            color: var(--text-muted); cursor: pointer;
            padding: 4px; border-radius: 4px;
            transition: color 0.2s;
        }
        .eye-toggle:hover { color: var(--text-main); }
        .eye-icon { width: 20px; height: 20px; outline: none; }

        .field-footer {
            display: flex;
            justify-content: flex-end;
            margin-top: -8px;
            margin-bottom: 24px;
        }
        .forgot-link {
            font-size: 13px;
            color: var(--text-muted);
            text-decoration: none;
            transition: color 0.2s;
        }
        .forgot-link:hover { color: var(--text-main); text-decoration: underline; }

        .btn-login {
            width: 100%;
            padding: 16px;
            border-radius: 14px;
            border: 1px solid rgba(255,255,255,0.1);
            background: var(--accent);
            color: #fff;
            font-family: 'DM Sans', sans-serif;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px var(--accent-glow);
        }
        .btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 16px var(--accent-glow); filter: brightness(1.1); }
        .btn-login:active { transform: translateY(0); box-shadow: 0 2px 8px var(--accent-glow); }

        .secure-note {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 24px;
            font-size: 13px;
            color: var(--text-muted);
        }
        .secure-note svg { width: 14px; height: 14px; }

        .back-link { text-align: center; margin-top: 24px; }
        .back-link a {
            color: var(--text-muted); text-decoration: none;
            font-size: 14px; transition: color 0.2s;
        }
        .back-link a:hover { color: var(--text-main); }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(20px); filter: blur(4px); }
            to   { opacity: 1; transform: translateY(0); filter: blur(0); }
        }
    </style>
</head>
<body>
<div class="wrapper">
    <div class="logo-row">
        <img src="../assets/logo.png" alt="UPCC Logo" class="logo-mark">
        <div class="logo-text">UPCC Panel</div>
    </div>

    <div class="card">
        <div class="card-title">Sign in</div>
        <div class="card-sub">University Promotion &amp; Conduct Committee</div>

        <?php if (!empty($error)): ?>
            <div class="alert-err">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                </svg>
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="upccpanel.php" autocomplete="off">
            <div class="field">
                <label for="username">Username</label>
                <input type="text" id="username" name="username"
                       value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
                       placeholder="Enter username" autofocus required>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <div class="input-wrapper">
                    <input type="password" id="password" name="password"
                           placeholder="Enter password" required>
                    <button type="button" class="eye-toggle" id="eye-toggle">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" class="eye-icon">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
            </div>

            <!-- Forgot password link -->
            <div class="field-footer">
                <a href="reset_password.php" class="forgot-link">Forgot / Reset password?</a>
            </div>

            <button type="submit" class="btn-login">Continue &rarr;</button>
        </form>

        <div class="secure-note">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
            </svg>
            Secured with 2-factor authentication
        </div>

        <div class="back-link">
            <a href="../index.php">&larr; Back to Home</a>
        </div>
    </div>
</div>

<script>
document.getElementById('eye-toggle').addEventListener('click', function() {
    const input = document.getElementById('password');
    const icon  = this.querySelector('.eye-icon');
    if (input.type === 'password') {
        input.type = 'text';
        icon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>';
    } else {
        input.type = 'password';
        icon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
    }
});
</script>
</body>
</html>
