<?php
require_once __DIR__ . '/../database/database.php';

session_start();
if (!isset($_SESSION['guard_logged_in']) || $_SESSION['guard_logged_in'] !== true) {
    header('Location: index.php');
    exit;
}

$guardId = (int)($_SESSION['guard_id'] ?? 0);
$guardName = htmlspecialchars($_SESSION['guard_name'] ?? 'Guard');

if ($guardId > 0) {
  $guard = db_one(
    "SELECT guard_id, full_name, username, email, contact_number, created_at
     FROM security_guard
     WHERE guard_id = ?
     LIMIT 1",
    [$guardId]
  );
  if ($guard) {
    $guardName = $guard['full_name'] ?? $guardName;
  }
}

$initials = strtoupper(substr($guardName, 0, 2));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Profile Settings | Guard Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700;1,9..40,400&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet" />

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --navy:     #0f1b3d;
      --navy-2:   #1a2d5a;
      --blue:     #2c4ecb;
      --blue-h:   #2240b0;
      --blue-soft:#e8edff;
      --accent:   #4f8ef7;
      --green:    #16a34a;
      --red:      #dc2626;
      --amber:    #d97706;
      --border:   #e2e8f0;
      --bg:       #f4f6fb;
      --surface:  #ffffff;
      --text-1:   #0f172a;
      --text-2:   #475569;
      --text-3:   #94a3b8;
      --radius:   14px;
      --shadow-sm: 0 1px 3px rgba(15,27,61,.06), 0 1px 2px rgba(15,27,61,.04);
      --shadow:    0 4px 16px rgba(15,27,61,.08), 0 1px 4px rgba(15,27,61,.05);
      --shadow-lg: 0 12px 40px rgba(15,27,61,.14), 0 4px 12px rgba(15,27,61,.06);
    }

    html, body { height: 100%; }
    body {
      font-family: 'DM Sans', sans-serif;
      background: var(--bg);
      color: var(--text-1);
      font-size: 14px;
      line-height: 1.6;
    }

    .container {
      max-width: 600px;
      margin: 0 auto;
      padding: 20px;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
    }

    .header {
      text-align: center;
      margin-bottom: 32px;
    }

    .logo {
      font-size: 28px;
      font-weight: 700;
      color: var(--blue);
      margin-bottom: 8px;
    }

    .header-title { font-size: 20px; font-weight: 700; margin-bottom: 4px; }
    .header-desc { font-size: 13px; color: var(--text-2); }

    /* ── Card ── */
    .card {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
      margin-bottom: 20px;
    }

    .card-header {
      padding: 18px 22px 14px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      gap: 10px;
    }

    .card-icon {
      width: 34px; height: 34px;
      border-radius: 10px;
      background: var(--blue-soft);
      color: var(--blue);
      display: grid;
      place-items: center;
      flex-shrink: 0;
    }

    .card-icon svg { width: 17px; height: 17px; }
    .card-header__text h2 { font-size: 15px; font-weight: 700; letter-spacing: -.2px; }
    .card-header__text p  { font-size: 12px; color: var(--text-2); margin-top: 1px; }

    .card-body { padding: 20px 22px; }
    .card-footer {
      padding: 14px 22px;
      border-top: 1px solid var(--border);
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      background: #fafbfc;
    }

    /* ── Form ── */
    .field { display: flex; flex-direction: column; gap: 5px; margin-bottom: 14px; }
    .field:last-child { margin-bottom: 0; }

    label {
      font-size: 12px;
      font-weight: 600;
      color: var(--text-2);
      text-transform: uppercase;
      letter-spacing: .2px;
    }

    input[type="password"],
    input[type="text"] {
      width: 100%;
      height: 42px;
      border: 1.5px solid var(--border);
      border-radius: 10px;
      padding: 0 12px;
      font-family: inherit;
      font-size: 14px;
      color: var(--text-1);
      outline: none;
      transition: border-color .15s;
    }

    input:focus {
      border-color: var(--blue);
      box-shadow: 0 0 0 3px rgba(44,78,203,.1);
    }

    .input-wrap {
      position: relative;
      display: flex;
      align-items: center;
    }

    .eye-btn {
      position: absolute;
      right: 8px;
      width: 30px; height: 30px;
      border-radius: 8px;
      border: 1px solid var(--border);
      background: #f8fafc;
      cursor: pointer;
      display: grid;
      place-items: center;
      color: var(--text-3);
      transition: all .15s;
    }

    .eye-btn:hover { border-color: var(--blue); color: var(--blue); background: var(--blue-soft); }
    .eye-btn svg { width: 14px; height: 14px; }

    input.has-eye { padding-right: 42px; }

    /* ── Password Rules ── */
    .pw-rules {
      margin-top: 12px;
      border: 1.5px solid var(--border);
      border-radius: 10px;
      padding: 12px 14px;
      background: #fafbfc;
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 6px 10px;
    }

    .rule {
      display: flex;
      align-items: center;
      gap: 7px;
      font-size: 12.5px;
      color: var(--text-2);
    }

    .rule-icon {
      width: 16px; height: 16px;
      border-radius: 50%;
      border: 1.5px solid var(--border);
      display: grid;
      place-items: center;
      flex-shrink: 0;
      transition: all .2s;
    }

    .rule-icon svg { width: 9px; height: 9px; display: none; }
    .rule.ok .rule-icon { border-color: var(--green); background: #dcfce7; }
    .rule.ok .rule-icon svg { display: block; color: var(--green); }
    .rule.ok .rule-txt { color: var(--green); }
    .rule.bad .rule-icon { border-color: #fca5a5; background: #fff5f5; }

    /* ── Buttons ── */
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      height: 40px;
      padding: 0 16px;
      border-radius: 10px;
      font-family: inherit;
      font-size: 13.5px;
      font-weight: 600;
      cursor: pointer;
      transition: all .15s;
      border: 1.5px solid var(--border);
      background: var(--surface);
      color: var(--text-1);
    }

    .btn svg { width: 15px; height: 15px; flex-shrink: 0; }
    .btn:hover { border-color: #bcc5d6; background: #f1f5f9; }

    .btn-primary {
      background: var(--blue);
      border-color: var(--blue);
      color: #fff;
    }

    .btn-primary:hover { background: var(--blue-h); border-color: var(--blue-h); }
    .btn:disabled { opacity: .45; cursor: not-allowed; pointer-events: none; }

    .alert {
      border-radius: 10px;
      padding: 10px 14px;
      font-size: 13px;
      font-weight: 500;
      display: none;
      margin-top: 10px;
    }

    .alert-danger { background: #fff5f5; border: 1px solid #fecaca; color: var(--red); }
    .alert-success { background: #f0fdf4; border: 1px solid #bbf7d0; color: var(--green); }
    .alert.show { display: block; }

    .back-link {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      margin-bottom: 24px;
      color: var(--blue);
      text-decoration: none;
      font-size: 13px;
      font-weight: 600;
    }

    .back-link:hover { text-decoration: underline; }
  </style>
</head>
<body>
  <div class="container">
    <a href="dashboard.php" class="back-link">
      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:16px;height:16px;"><polyline points="15 18 9 12 15 6"/></svg>
      Back to Dashboard
    </a>

    <div class="header">
      <div class="logo">Guard Portal</div>
      <h1 class="header-title">Profile Settings</h1>
      <p class="header-desc">Manage your account and security</p>
    </div>

    <!-- Change Password Card -->
    <div class="card">
      <div class="card-header">
        <div class="card-icon" style="background:#fef2f2;color:var(--red);">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
        </div>
        <div class="card-header__text">
          <h2>Change Password</h2>
          <p>Update your account password</p>
        </div>
      </div>
      <div class="card-body">
        <div class="field">
          <label>Current Password</label>
          <div class="input-wrap">
            <input type="password" id="pwCurrent" placeholder="Enter current password" class="has-eye" />
            <button class="eye-btn" type="button" id="togglePwCurrent">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>

        <div style="margin-top:16px;">
          <div class="field">
            <label>New Password</label>
            <div class="input-wrap">
              <input type="password" id="pwNew" placeholder="New password" class="has-eye" />
              <button class="eye-btn" type="button" id="togglePwNew">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>
          <div class="field" style="margin-top:14px;">
            <label>Confirm Password</label>
            <div class="input-wrap">
              <input type="password" id="pwConfirm" placeholder="Confirm password" />
            </div>
          </div>
        </div>

        <!-- Password Rules -->
        <div class="pw-rules" id="pwRules">
          <div class="rule bad" id="ruleLen">
            <span class="rule-icon"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></span>
            <span class="rule-txt">8+ characters</span>
          </div>
          <div class="rule bad" id="ruleUpper">
            <span class="rule-icon"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></span>
            <span class="rule-txt">Uppercase letter</span>
          </div>
          <div class="rule bad" id="ruleLower">
            <span class="rule-icon"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></span>
            <span class="rule-txt">Lowercase letter</span>
          </div>
          <div class="rule bad" id="ruleNumber">
            <span class="rule-icon"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></span>
            <span class="rule-txt">Number</span>
          </div>
          <div class="rule bad" id="ruleSpecial">
            <span class="rule-icon"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></span>
            <span class="rule-txt">Special character</span>
          </div>
          <div class="rule bad" id="ruleMatch">
            <span class="rule-icon"><svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><polyline points="20 6 9 17 4 12"/></svg></span>
            <span class="rule-txt">Passwords match</span>
          </div>
        </div>

        <div class="alert alert-danger" id="pwMsg"></div>
      </div>
      <div class="card-footer">
        <button class="btn" type="button" id="btnCancel">Cancel</button>
        <button class="btn btn-primary" type="button" id="btnChangePassword" disabled>
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Change Password
        </button>
      </div>
    </div>
  </div>

  <script>
  (function() {
    function showAlert(el, msg) { el.textContent = msg; el.classList.add('show'); }
    function hideAlert(el) { el.classList.remove('show'); }

    async function postJSON(url, body) {
      const r = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body), cache: 'no-store'
      });
      return { ok: r.ok, json: await r.json().catch(() => null) };
    }

    // Toggle password visibility
    function toggleEye(inputId) {
      const inp = document.getElementById(inputId);
      inp.type = inp.type === 'password' ? 'text' : 'password';
    }

    document.getElementById('togglePwCurrent').addEventListener('click', () => toggleEye('pwCurrent'));
    document.getElementById('togglePwNew').addEventListener('click', () => toggleEye('pwNew'));

    // Password policy
    const pwNew = document.getElementById('pwNew');
    const pwConfirm = document.getElementById('pwConfirm');
    const btnChangePw = document.getElementById('btnChangePassword');
    const pwMsg = document.getElementById('pwMsg');

    const rules = {
      len: document.getElementById('ruleLen'),
      upper: document.getElementById('ruleUpper'),
      lower: document.getElementById('ruleLower'),
      number: document.getElementById('ruleNumber'),
      special: document.getElementById('ruleSpecial'),
      match: document.getElementById('ruleMatch'),
    };

    function setRule(el, ok) {
      el.classList.toggle('ok', ok);
      el.classList.toggle('bad', !ok);
    }

    let policyTimer = null;
    function runPolicyCheck() {
      const np = pwNew.value, cp = pwConfirm.value;
      const ok = {
        len: np.length >= 8,
        upper: /[A-Z]/.test(np),
        lower: /[a-z]/.test(np),
        number: /[0-9]/.test(np),
        special: /[^A-Za-z0-9]/.test(np),
        match: np !== '' && np === cp,
      };
      Object.entries(ok).forEach(([k, v]) => setRule(rules[k], v));
      btnChangePw.disabled = !Object.values(ok).every(Boolean);
    }

    pwNew.addEventListener('input', () => {
      clearTimeout(policyTimer);
      policyTimer = setTimeout(runPolicyCheck, 220);
    });
    pwConfirm.addEventListener('input', () => {
      clearTimeout(policyTimer);
      policyTimer = setTimeout(runPolicyCheck, 220);
    });

    // Change password
    document.getElementById('btnChangePassword').addEventListener('click', async () => {
      const current = document.getElementById('pwCurrent').value;
      const newPw = pwNew.value;
      const confirm = pwConfirm.value;

      hideAlert(pwMsg);

      if (!current) {
        showAlert(pwMsg, 'Current password is required.');
        return;
      }

      if (current === newPw) {
        showAlert(pwMsg, 'New password must be different from current password.');
        return;
      }

      const r = await postJSON('AJAX/guard_change_password.php', {
        current_password: current,
        new_password: newPw,
        confirm_password: confirm
      });

      if (r.ok && r.json?.ok) {
        document.getElementById('pwCurrent').value = '';
        pwNew.value = '';
        pwConfirm.value = '';
        runPolicyCheck();
        showAlert(pwMsg, 'Password changed successfully!');
        pwMsg.classList.remove('alert-danger');
        pwMsg.classList.add('alert-success');
        setTimeout(() => {
          showAlert(pwMsg, '');
          pwMsg.classList.remove('alert-success');
          pwMsg.classList.add('alert-danger');
        }, 3000);
      } else {
        showAlert(pwMsg, r.json?.message || 'Failed to change password.');
      }
    });

    // Cancel
    document.getElementById('btnCancel').addEventListener('click', () => {
      document.getElementById('pwCurrent').value = '';
      pwNew.value = '';
      pwConfirm.value = '';
      runPolicyCheck();
    });
  })();
  </script>
</body>
</html>


