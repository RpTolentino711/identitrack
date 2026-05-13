<?php
session_start();
if (!isset($_SESSION['guard_logged_in']) || $_SESSION['guard_logged_in'] !== true) {
    header('Location: index.php'); exit;
}

require_once __DIR__ . '/../database/database.php';
$pdo = db();

// Fetch offense types grouped by level
$offenseTypes = db_all("SELECT offense_type_id, code, name, level, major_category
    FROM offense_type WHERE is_active = 1
    ORDER BY level ASC, code ASC
");

$guardName = htmlspecialchars($_SESSION['guard_name']);
$initial   = strtoupper(substr($_SESSION['guard_name'], 0, 1));
$showWelcomeModal = isset($_GET['welcome']) && $_GET['welcome'] === '1';
$guardReportCount = (int)(db_one(
  "SELECT COUNT(*) AS cnt FROM guard_violation_report WHERE submitted_by = :gid",
  [':gid' => $_SESSION['guard_id']]
)['cnt'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<title>Guard Dashboard &mdash; IdentiTrack</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}

:root {
    /* Core palette — matches login page */
    --bg:       #f0f3fb;
    --surface:  #ffffff;
    --surface2: #f8faff;
    --navy:     #1b2b6b;
    --navy2:    #2a3f8f;
    --navy-lt:  #eef1fb;
    --navy-dim: rgba(27,43,107,0.08);
    --border:   #dce4f5;
    --border2:  #c8d4ee;
    --gold:     #f0a500;
    --gold2:    #ffcc5c;
    --gold-lt:  #fff8e6;
    --gold-dim: rgba(240,165,0,0.10);
    --gold-glow:rgba(240,165,0,0.22);
    --text:     #1a1f36;
    --text2:    #4a5578;
    --muted:    #8896b0;

    /* Status */
    --red:      #e53e3e;
    --red-lt:   #fff5f5;
    --red-bd:   #fed7d7;
    --blue:     #3b72d9;
    --blue-lt:  #ebf2ff;
    --blue-bd:  #c3d8ff;
    --green:    #22863a;
    --green-lt: #f0fff4;
    --green-bd: #c6f6d5;

    /* Shadows */
    --shadow-sm: 0 1px 4px rgba(27,43,107,0.06), 0 1px 2px rgba(27,43,107,0.04);
    --shadow:    0 2px 8px rgba(27,43,107,0.08), 0 8px 32px rgba(27,43,107,0.10);
    --shadow-lg: 0 8px 40px rgba(27,43,107,0.14), 0 2px 8px rgba(27,43,107,0.06);
    --shadow-gold: 0 4px 20px rgba(240,165,0,0.28);

    /* Radii */
    --r-sm: 8px;
    --r-md: 12px;
    --r-lg: 18px;
    --r-xl: 24px;
}

*{scrollbar-width:thin;scrollbar-color:var(--border2) transparent}

html, body {
    min-height: 100%;
    background: var(--bg);
    font-family: 'DM Sans', sans-serif;
    color: var(--text);
    -webkit-font-smoothing: antialiased;
    overflow-x: hidden;
}

/* ─── AMBIENT BACKGROUND ─── */
body::before {
    content: '';
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background:
        radial-gradient(ellipse 70% 40% at 60% -10%, rgba(27,43,107,0.07) 0%, transparent 60%),
        radial-gradient(ellipse 50% 40% at 5% 80%,  rgba(240,165,0,0.06) 0%, transparent 55%),
        linear-gradient(160deg, #f0f3fb 0%, #e8edf8 100%);
}

body::after {
    content: '';
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background-image:
        repeating-linear-gradient(0deg,  transparent, transparent 39px, rgba(27,43,107,0.018) 39px, rgba(27,43,107,0.018) 40px),
        repeating-linear-gradient(90deg, transparent, transparent 39px, rgba(27,43,107,0.018) 39px, rgba(27,43,107,0.018) 40px);
    mask-image: radial-gradient(ellipse 100% 70% at 50% 0%, black 20%, transparent 75%);
    opacity: .55;
}

/* ─── NAVIGATION ─── */
nav {
    position: sticky; top: 0; z-index: 100;
    background: rgba(255,255,255,0.90);
    backdrop-filter: blur(20px) saturate(180%);
    -webkit-backdrop-filter: blur(20px) saturate(180%);
    border-bottom: 1px solid var(--border);
    height: 56px;
    padding: 0 16px;
    display: flex; align-items: center; justify-content: space-between;
    padding-top: env(safe-area-inset-top, 0);
    box-shadow: 0 1px 0 var(--border), 0 2px 12px rgba(27,43,107,0.06);
}

/* Navy top accent bar */
nav::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 3px;
    background: linear-gradient(90deg, var(--navy), var(--navy2), var(--gold));
}

.nav-brand {
    display: flex; align-items: center; gap: 10px;
    font-family: 'Syne', sans-serif;
    font-size: 18px; font-weight: 800; letter-spacing: -.03em;
    color: var(--text);
}
.nav-brand em { font-style: normal; color: var(--gold); }

.brand-badge {
    display: inline-flex; align-items: center; gap: 5px;
    background: var(--navy-lt);
    border: 1px solid #c7d2ee;
    border-radius: 100px; padding: 2px 8px 2px 6px;
    font-family: 'Syne', sans-serif;
    font-size: 9px; font-weight: 700; letter-spacing: .12em; text-transform: uppercase;
    color: var(--navy);
}
.badge-dot {
    width: 5px; height: 5px;
    background: var(--navy); border-radius: 50%;
    animation: pulse-dot 2s ease-in-out infinite;
}
@keyframes pulse-dot { 0%,100%{opacity:1} 50%{opacity:.25} }

.nav-right { display: flex; align-items: center; gap: 10px; }

.nav-avatar {
    width: 34px; height: 34px; border-radius: 10px;
    background: linear-gradient(135deg, var(--gold), #b07800);
    display: flex; align-items: center; justify-content: center;
    font-family: 'Syne', sans-serif;
    font-weight: 800; font-size: 14px; color: #000;
    box-shadow: 0 2px 8px rgba(240,165,0,0.30);
    flex-shrink: 0;
    border: 1px solid rgba(240,165,0,0.25);
}

.nav-name {
    font-size: 13px; font-weight: 500; color: var(--text2);
    max-width: 120px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

.btn-logout {
    display: inline-flex; align-items: center; justify-content: center;
    width: 36px; height: 36px;
    background: var(--surface);
    border: 1px solid var(--border2);
    border-radius: 10px;
    color: var(--muted);
    cursor: pointer;
    transition: all .2s;
    text-decoration: none;
    flex-shrink: 0;
    box-shadow: var(--shadow-sm);
}
.btn-logout:hover { background: var(--red-lt); border-color: var(--red-bd); color: var(--red); }
.btn-logout svg { width: 15px; height: 15px; }

/* ─── WRAP ─── */
.wrap {
    position: relative; z-index: 1;
    max-width: 560px; margin: 0 auto;
    padding: 20px 16px calc(env(safe-area-inset-bottom, 0px) + 48px);
}

/* ─── HERO CARD ─── */
.hero {
    background: linear-gradient(135deg, var(--navy) 0%, var(--navy2) 100%);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: var(--r-xl);
    padding: 22px;
    margin-bottom: 16px;
    position: relative; overflow: hidden;
    box-shadow: var(--shadow-lg), inset 0 1px 0 rgba(255,255,255,0.12);
    animation: slideUp .5s cubic-bezier(.16,1,.3,1) both;
}
.hero::before {
    content: '';
    position: absolute;
    top: -80px; right: -60px;
    width: 220px; height: 220px;
    background: radial-gradient(circle, rgba(240,165,0,0.20), transparent 70%);
    pointer-events: none;
}
.hero::after {
    content: '';
    position: absolute;
    bottom: -40px; left: -40px;
    width: 160px; height: 160px;
    background: radial-gradient(circle, rgba(255,255,255,0.05), transparent 70%);
    pointer-events: none;
}

.hero-top { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
.hero-greeting { font-size: 11px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: rgba(255,255,255,0.5); margin-bottom: 4px; }
.hero-name { font-family: 'Syne', sans-serif; font-size: 22px; font-weight: 800; letter-spacing: -.03em; color: #fff; line-height: 1.1; }
.hero-name em { font-style: normal; color: var(--gold2); }
.hero-sub { font-size: 13px; color: rgba(255,255,255,0.55); margin-top: 5px; }

.hero-icon {
    width: 52px; height: 52px; border-radius: 16px; flex-shrink: 0;
    background: linear-gradient(135deg, var(--gold), #b07800);
    display: flex; align-items: center; justify-content: center;
    font-family: 'Syne', sans-serif;
    font-size: 22px; font-weight: 900; color: #000;
    box-shadow: 0 4px 16px rgba(240,165,0,0.40);
    border: 1px solid rgba(240,165,0,0.30);
}

.kpi-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.kpi {
    background: rgba(255,255,255,0.08);
    border: 1px solid rgba(255,255,255,0.12);
    border-radius: var(--r-md);
    padding: 13px 15px;
    backdrop-filter: blur(8px);
}
.kpi-label { font-size: 10px; font-weight: 600; letter-spacing: .12em; text-transform: uppercase; color: rgba(255,255,255,0.45); }
.kpi-val {
    font-family: 'Syne', sans-serif;
    font-size: 24px; font-weight: 800; color: #fff;
    margin-top: 4px; letter-spacing: -.02em; line-height: 1;
}
.kpi-val.clock {
    font-family: 'DM Sans', sans-serif;
    font-size: 19px; font-weight: 600; color: var(--gold2);
    letter-spacing: .02em;
}

/* ─── SECTION LABEL ─── */
.section-label {
    font-family: 'Syne', sans-serif;
    font-size: 10px; font-weight: 700; letter-spacing: .14em; text-transform: uppercase;
    color: var(--muted); margin-bottom: 10px; padding-left: 2px;
    display: flex; align-items: center; gap: 8px;
}
.section-label::after {
    content: ''; flex: 1; height: 1px;
    background: linear-gradient(90deg, var(--border2), transparent);
}

/* ─── CARD ─── */
.card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--r-lg);
    overflow: visible;
    box-shadow: var(--shadow);
    margin-bottom: 14px;
    animation: slideUp .5s cubic-bezier(.16,1,.3,1) .1s both;
    position: relative;
}
/* card top accent */
.card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, var(--navy), var(--navy2), transparent);
    border-radius: var(--r-lg) var(--r-lg) 0 0;
    opacity: .5;
}

.card-head {
    padding: 14px 18px;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 10px;
}
.card-head-icon {
    width: 32px; height: 32px; border-radius: 9px;
    display: flex; align-items: center; justify-content: center;
    background: var(--navy-lt); border: 1px solid #c7d2ee;
    flex-shrink: 0;
}
.card-head-icon svg { color: var(--navy); width: 15px; height: 15px; }
.card-head h3 { font-family: 'Syne', sans-serif; font-size: 14px; font-weight: 700; letter-spacing: -.01em; color: var(--text); }
.card-body { padding: 16px 18px; }

/* ─── SEARCH ─── */
.search-wrapper { position: relative; z-index: 30; }
.search-box {
    display: flex; align-items: center; gap: 10px;
    background: var(--bg);
    border: 1px solid var(--border2);
    border-radius: var(--r-md);
    padding: 0 14px;
    transition: border-color .2s, box-shadow .2s;
}
.search-box:focus-within {
    border-color: var(--navy);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(27,43,107,0.10);
}
.search-box svg { color: var(--muted); flex-shrink: 0; width: 16px; height: 16px; }
.search-inp {
    flex: 1; background: transparent; border: none; outline: none;
    padding: 13px 0;
    font-family: 'DM Sans', sans-serif; font-size: 15px;
    color: var(--text);
    min-height: 48px;
}
.search-inp::placeholder { color: var(--muted); }

.search-dropdown {
    position: absolute; top: calc(100% + 6px); left: 0; right: 0;
    background: var(--surface);
    border: 1px solid var(--border2);
    border-radius: var(--r-md);
    max-height: 380px; overflow-y: auto;
    z-index: 90;
    box-shadow: var(--shadow-lg);
    display: none;
}
.search-dropdown.show { display: block; animation: dropIn .2s cubic-bezier(.16,1,.3,1); }
@keyframes dropIn { from{opacity:0;transform:translateY(-8px)} to{opacity:1;transform:translateY(0)} }

.student-result {
    padding: 13px 16px; cursor: pointer;
    border-bottom: 1px solid var(--border);
    display: flex; align-items: center; gap: 12px;
    transition: background .15s;
    -webkit-tap-highlight-color: transparent;
}
.student-result:last-child { border-bottom: none; }
.student-result:hover, .student-result:active { background: var(--navy-lt); }

.result-ava {
    width: 40px; height: 40px; border-radius: 10px; flex-shrink: 0;
    background: linear-gradient(135deg, var(--navy-lt), var(--border));
    border: 1px solid var(--border2);
    display: flex; align-items: center; justify-content: center;
    font-family: 'Syne', sans-serif;
    font-weight: 800; font-size: 15px; color: var(--navy);
}
.result-info { flex: 1; min-width: 0; }
.result-name { font-size: 14px; font-weight: 600; color: var(--text); line-height: 1.3; }
.result-meta { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 3px; }
.result-tag {
    font-size: 10.5px; font-family: 'DM Sans', monospace;
    color: var(--text2);
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: 5px; padding: 1px 6px;
}

.search-empty {
    padding: 28px 16px; text-align: center;
    color: var(--muted); font-size: 14px;
}
.search-empty svg {
    width: 36px; height: 36px; color: var(--border2);
    display: block; margin: 0 auto 10px;
}

/* ─── SELECTED STUDENT PILL ─── */
.student-pill {
    display: flex; align-items: center; gap: 12px;
    background: linear-gradient(135deg, var(--navy-lt), rgba(240,165,0,0.04));
    border: 1px solid var(--border2);
    border-radius: var(--r-md);
    padding: 13px 16px;
    margin-bottom: 16px;
    animation: slideUp .3s cubic-bezier(.16,1,.3,1);
    position: relative; overflow: hidden;
}
.student-pill::before {
    content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 3px;
    background: linear-gradient(180deg, var(--navy), var(--gold));
    border-radius: 0 2px 2px 0;
}

.pill-ava {
    width: 42px; height: 42px; border-radius: 12px; flex-shrink: 0;
    background: linear-gradient(135deg, var(--navy), var(--navy2));
    display: flex; align-items: center; justify-content: center;
    font-family: 'Syne', sans-serif;
    font-weight: 900; font-size: 18px; color: #fff;
    box-shadow: 0 3px 10px rgba(27,43,107,0.25);
}
.pill-info { flex: 1; min-width: 0; }
.pill-name { font-family: 'Syne', sans-serif; font-size: 15px; font-weight: 700; color: var(--text); letter-spacing: -.01em; }
.pill-meta { display: flex; gap: 5px; flex-wrap: wrap; margin-top: 4px; }
.pill-tag {
    font-size: 10px;
    color: var(--text2);
    background: rgba(27,43,107,0.06);
    border: 1px solid var(--border);
    border-radius: 5px; padding: 2px 7px;
}
.pill-clear {
    width: 28px; height: 28px; border-radius: 8px; flex-shrink: 0;
    background: var(--red-lt); border: 1px solid var(--red-bd);
    display: flex; align-items: center; justify-content: center;
    cursor: pointer; color: var(--red);
    transition: all .15s;
}
.pill-clear:hover { background: #fed7d7; }
.pill-clear svg { width: 12px; height: 12px; }

/* ─── FORM FIELDS ─── */
.field { margin-bottom: 14px; }
.field-label {
    display: flex; align-items: center; gap: 6px;
    font-size: 11px; font-weight: 600; letter-spacing: .08em; text-transform: uppercase;
    color: var(--text2); margin-bottom: 7px;
}
.field-label svg { width: 11px; height: 11px; }

.form-control {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border2);
    border-radius: var(--r-md);
    padding: 13px 14px;
    font-family: 'DM Sans', sans-serif; font-size: 15px;
    color: var(--text);
    outline: none;
    transition: border-color .2s, box-shadow .2s, background .2s;
    appearance: none; -webkit-appearance: none;
    min-height: 48px;
}
.form-control:focus {
    border-color: var(--navy);
    background: #fff;
    box-shadow: 0 0 0 3px rgba(27,43,107,0.10);
}
.form-control::placeholder { color: var(--muted); }
select.form-control {
    background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%238896b0' stroke-width='2.5'%3E%3Cpolyline points='6 9 12 15 18 9'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 14px center; padding-right: 38px;
    cursor: pointer;
}
select.form-control option { background: #fff; color: var(--text); }
select.form-control optgroup { background: var(--bg); color: var(--muted); font-size: 12px; }
textarea.form-control { resize: vertical; min-height: 88px; line-height: 1.5; }
input[type=datetime-local].form-control { color-scheme: light; }

/* ─── LEVEL INDICATOR ─── */
.level-indicator {
    display: none; align-items: center; gap: 8px;
    padding: 10px 14px; border-radius: var(--r-sm);
    font-size: 12.5px; font-weight: 600; margin-top: 8px;
    border: 1px solid;
}
.level-indicator.minor { background: var(--blue-lt); border-color: var(--blue-bd); color: var(--blue); }
.level-indicator.major { background: var(--red-lt); border-color: var(--red-bd); color: var(--red); }
.level-indicator.show { display: flex; }
.level-indicator svg { width: 14px; height: 14px; flex-shrink: 0; }

.form-divider {
    height: 1px;
    background: linear-gradient(90deg, transparent, var(--border2), transparent);
    margin: 18px 0;
}

/* ─── SUBMIT BUTTON ─── */
.btn-submit {
    width: 100%; padding: 15px;
    background: var(--navy);
    color: #fff; border: none; border-radius: var(--r-md);
    font-family: 'Syne', sans-serif; font-size: 15px; font-weight: 700;
    letter-spacing: .02em;
    cursor: pointer;
    box-shadow: 0 4px 18px rgba(27,43,107,0.28);
    transition: all .2s;
    display: flex; align-items: center; justify-content: center; gap: 8px;
    min-height: 52px;
    -webkit-tap-highlight-color: transparent;
}
.btn-submit:hover {
    background: var(--navy2);
    box-shadow: 0 8px 28px rgba(27,43,107,0.35);
    transform: translateY(-1px);
}
.btn-submit:active { transform: translateY(0); box-shadow: 0 2px 10px rgba(27,43,107,0.20); }
.btn-submit:disabled { opacity: .45; cursor: not-allowed; transform: none !important; }
.btn-submit svg { width: 17px; height: 17px; }

.form-hint {
    text-align: center; font-size: 11.5px; color: var(--muted);
    margin-top: 10px; line-height: 1.5;
}
.form-hint svg { width: 11px; height: 11px; vertical-align: middle; margin-right: 3px; }

/* ─── TOAST ─── */
.toast {
    position: fixed;
    left: 16px; right: 16px;
    bottom: calc(env(safe-area-inset-bottom, 0px) + 16px);
    z-index: 999;
    background: var(--surface);
    border: 1px solid var(--border2);
    border-radius: var(--r-md);
    padding: 14px 16px;
    display: flex; align-items: center; gap: 12px;
    font-size: 14px;
    box-shadow: var(--shadow-lg);
    transform: translateY(80px); opacity: 0;
    transition: all .4s cubic-bezier(.16,1,.3,1);
    pointer-events: none;
    max-width: 560px; margin: 0 auto;
}
.toast.show { transform: translateY(0); opacity: 1; }
.toast.success { border-color: var(--green-bd); }
.toast.error   { border-color: var(--red-bd); }
.toast-dot {
    width: 32px; height: 32px; border-radius: 9px; flex-shrink: 0;
    display: flex; align-items: center; justify-content: center;
}
.toast.success .toast-dot { background: var(--green-lt); }
.toast.success .toast-dot svg { color: var(--green); }
.toast.error .toast-dot { background: var(--red-lt); }
.toast.error .toast-dot svg { color: var(--red); }
.toast-dot svg { width: 16px; height: 16px; }
.toast-msg { flex: 1; font-size: 13.5px; font-weight: 500; color: var(--text); line-height: 1.4; }

/* ─── MODALS ─── */
.modal-overlay {
    position: fixed; inset: 0; z-index: 1200;
    background: rgba(27,43,107,0.35);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    display: none; align-items: flex-end; justify-content: center;
    padding: 0 0 env(safe-area-inset-bottom, 0px);
}
.modal-overlay.show { display: flex; animation: fadeIn .2s ease; }
@keyframes fadeIn { from{opacity:0} to{opacity:1} }

.modal-sheet {
    width: 100%; max-width: 560px;
    background: var(--surface);
    border: 1px solid var(--border2);
    border-bottom: none;
    border-radius: var(--r-xl) var(--r-xl) 0 0;
    padding: 20px 20px calc(env(safe-area-inset-bottom, 16px) + 16px);
    animation: sheetUp .35s cubic-bezier(.16,1,.3,1);
    box-shadow: var(--shadow-lg);
}
@keyframes sheetUp { from{transform:translateY(100%)} to{transform:translateY(0)} }

.sheet-handle {
    width: 40px; height: 4px; border-radius: 2px;
    background: var(--border2);
    margin: 0 auto 20px;
}
.sheet-title { font-family: 'Syne', sans-serif; font-size: 19px; font-weight: 800; letter-spacing: -.02em; color: var(--text); margin-bottom: 6px; }
.sheet-sub { font-size: 13px; color: var(--text2); line-height: 1.5; margin-bottom: 20px; }
.sheet-actions { display: flex; gap: 10px; }
.sheet-btn {
    flex: 1; padding: 14px; border-radius: var(--r-md);
    font-family: 'DM Sans', sans-serif; font-size: 14px; font-weight: 600;
    cursor: pointer;
    border: 1px solid var(--border2);
    background: var(--bg); color: var(--text2);
    transition: all .2s; min-height: 48px;
    -webkit-tap-highlight-color: transparent;
}
.sheet-btn:hover { background: var(--border); color: var(--text); }
.sheet-btn.danger { background: var(--red-lt); border-color: var(--red-bd); color: var(--red); }
.sheet-btn.danger:hover { background: #fed7d7; }
.sheet-btn.primary { background: var(--navy); border-color: transparent; color: #fff; font-family: 'Syne', sans-serif; font-weight: 700; }
.sheet-btn.primary:hover { background: var(--navy2); }

/* ─── LOADING SPINNER SHEET ─── */
.loading-sheet { text-align: center; padding: 32px 20px calc(env(safe-area-inset-bottom, 24px) + 20px); }
.spinner {
    width: 44px; height: 44px;
    border: 3px solid var(--navy-lt);
    border-top-color: var(--navy);
    border-radius: 50%;
    margin: 0 auto 16px;
    animation: spin .7s linear infinite;
}
@keyframes spin { to{transform:rotate(360deg)} }
.loading-title { font-family: 'Syne', sans-serif; font-size: 19px; font-weight: 800; letter-spacing: -.02em; color: var(--text); margin-bottom: 6px; }
.loading-sub { font-size: 13px; color: var(--text2); }

/* ─── WELCOME MODAL CENTER ─── */
.welcome-overlay {
    position: fixed; inset: 0; z-index: 1250;
    background: rgba(27,43,107,0.45);
    backdrop-filter: blur(12px); -webkit-backdrop-filter: blur(12px);
    display: none; align-items: center; justify-content: center;
    padding: 20px;
}
.welcome-overlay.show { display: flex; animation: fadeIn .2s ease; }

.welcome-card {
    width: min(380px, 95vw);
    background: var(--surface);
    border: 1px solid var(--border2);
    border-radius: var(--r-xl);
    padding: 30px 26px;
    text-align: center;
    box-shadow: var(--shadow-lg);
    animation: scaleIn .35s cubic-bezier(.16,1,.3,1);
    position: relative; overflow: hidden;
}
.welcome-card::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, var(--navy), var(--navy2), var(--gold));
}
@keyframes scaleIn { from{opacity:0;transform:scale(.92)} to{opacity:1;transform:scale(1)} }

.welcome-icon {
    width: 64px; height: 64px; border-radius: 20px;
    background: linear-gradient(135deg, var(--navy), var(--navy2));
    display: flex; align-items: center; justify-content: center;
    font-size: 28px; margin: 6px auto 18px;
    box-shadow: 0 8px 24px rgba(27,43,107,0.25);
}
.welcome-title { font-family: 'Syne', sans-serif; font-size: 22px; font-weight: 800; letter-spacing: -.03em; color: var(--text); margin-bottom: 8px; }
.welcome-sub { font-size: 14px; color: var(--text2); line-height: 1.5; margin-bottom: 24px; }
.welcome-cta {
    width: 100%; padding: 14px;
    background: var(--navy);
    color: #fff; border: none; border-radius: 11px;
    font-family: 'Syne', sans-serif; font-size: 15px; font-weight: 700;
    cursor: pointer;
    box-shadow: 0 4px 16px rgba(27,43,107,0.25);
    transition: all .2s; min-height: 50px;
    letter-spacing: .02em;
}
.welcome-cta:hover { background: var(--navy2); transform: translateY(-1px); box-shadow: 0 8px 24px rgba(27,43,107,0.30); }

/* ─── SECURE FOOTER ─── */
.page-foot {
    display: flex; align-items: center; justify-content: center; gap: 6px;
    font-size: 11.5px; color: var(--muted);
    padding: 4px 0 8px;
}
.page-foot svg { width: 12px; height: 12px; color: var(--navy); opacity: .4; }

/* ─── UTILITY ─── */
.hidden { display: none !important; }
@keyframes slideUp { from{opacity:0;transform:translateY(20px)} to{opacity:1;transform:translateY(0)} }

/* ─── DESKTOP ADJUSTMENTS ─── */
@media(min-width: 560px) {
    nav { height: 60px; padding: 0 24px; }
    .wrap { padding: 28px 24px 64px; }
    .modal-overlay { align-items: center; padding: 20px; }
    .modal-sheet { border-radius: var(--r-xl); border-bottom: 1px solid var(--border2); }
    .toast { left: auto; right: 24px; bottom: 24px; max-width: 360px; margin: 0; }
}
</style>
</head>
<body>

<!-- ─── NAV ─── -->
<nav>
  <div style="display:flex;align-items:center;gap:10px">
    <span class="nav-brand">Identi<em>Track</em></span>
    <span class="brand-badge"><span class="badge-dot"></span>Guard</span>
  </div>
  <div class="nav-right">
    <span class="nav-name"><?= $guardName ?></span>
    <div class="nav-avatar"><?= $initial ?></div>
    <a href="logout.php" class="btn-logout" id="guardLogoutLink" aria-label="Logout">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/>
      </svg>
    </a>
  </div>
</nav>

<div class="wrap">

  <!-- ─── HERO ─── -->
  <div class="hero">
    <div class="hero-top">
      <div>
        <div class="hero-greeting">Guard Portal</div>
        <div class="hero-name">Hey, <em><?= $guardName ?>!</em></div>
        <div class="hero-sub">File a student violation report</div>
      </div>
      <div class="hero-icon"><?= $initial ?></div>
    </div>
    <div class="kpi-row">
      <div class="kpi">
        <div class="kpi-label">Live Time</div>
        <div class="kpi-val clock" id="guardNowTime">--:--</div>
      </div>
      <div class="kpi">
        <div class="kpi-label">Reports Filed</div>
        <div class="kpi-val"><?= (int)$guardReportCount ?></div>
      </div>
    </div>
  </div>

  <!-- ─── SEARCH CARD ─── -->
  <div class="section-label">Find Student</div>
  <div class="card" style="z-index:30;position:relative">
    <div class="card-head">
      <div class="card-head-icon">
        <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
          <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
        </svg>
      </div>
      <h3>Search Student</h3>
    </div>
    <div class="card-body">
      <div class="search-wrapper">
        <div class="search-box">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
            <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
          </svg>
          <input type="text" id="searchInput" class="search-inp"
            placeholder="Search by ID or name…" autocomplete="off" autocapitalize="words">
        </div>
        <div class="search-dropdown" id="searchDropdown"></div>
      </div>
    </div>
  </div>

  <!-- ─── REPORT FORM (hidden until student selected) ─── -->
  <div id="reportFormWrap" class="hidden">
    <div class="section-label">Violation Details</div>
    <div class="card">
      <div class="card-head">
        <div class="card-head-icon">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
          </svg>
        </div>
        <h3>File Violation Report</h3>
      </div>
      <div class="card-body">
        <input type="hidden" id="selectedStudentId">

        <!-- Selected student -->
        <div id="selectedStudentInfo" class="student-pill"></div>

        <div class="field">
          <div class="field-label">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
            </svg>
            Offense Type
          </div>
          <select id="offenseSelect" class="form-control" onchange="updateLevelBadge()">
            <option value="">— Select offense type —</option>
            <optgroup label="MINOR OFFENSES">
              <?php foreach ($offenseTypes as $ot): if ($ot['level'] !== 'MINOR') continue; ?>
              <option value="<?= $ot['offense_type_id'] ?>" data-level="MINOR">
                <?= htmlspecialchars($ot['code']) ?> — <?= htmlspecialchars($ot['name']) ?>
              </option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="MAJOR OFFENSES - CATEGORY 1">
              <?php foreach ($offenseTypes as $ot): if ($ot['level'] !== 'MAJOR' || (int)$ot['major_category'] !== 1) continue; ?>
              <option value="<?= $ot['offense_type_id'] ?>" data-level="MAJOR">
                <?= htmlspecialchars($ot['code']) ?> — <?= htmlspecialchars($ot['name']) ?>
              </option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="MAJOR OFFENSES - CATEGORY 2">
              <?php foreach ($offenseTypes as $ot): if ($ot['level'] !== 'MAJOR' || (int)$ot['major_category'] !== 2) continue; ?>
              <option value="<?= $ot['offense_type_id'] ?>" data-level="MAJOR">
                <?= htmlspecialchars($ot['code']) ?> — <?= htmlspecialchars($ot['name']) ?>
              </option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="MAJOR OFFENSES - CATEGORY 3">
              <?php foreach ($offenseTypes as $ot): if ($ot['level'] !== 'MAJOR' || (int)$ot['major_category'] !== 3) continue; ?>
              <option value="<?= $ot['offense_type_id'] ?>" data-level="MAJOR">
                <?= htmlspecialchars($ot['code']) ?> — <?= htmlspecialchars($ot['name']) ?>
              </option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="MAJOR OFFENSES - CATEGORY 4">
              <?php foreach ($offenseTypes as $ot): if ($ot['level'] !== 'MAJOR' || (int)$ot['major_category'] !== 4) continue; ?>
              <option value="<?= $ot['offense_type_id'] ?>" data-level="MAJOR">
                <?= htmlspecialchars($ot['code']) ?> — <?= htmlspecialchars($ot['name']) ?>
              </option>
              <?php endforeach; ?>
            </optgroup>
            <optgroup label="MAJOR OFFENSES - CATEGORY 5">
              <?php foreach ($offenseTypes as $ot): if ($ot['level'] !== 'MAJOR' || (int)$ot['major_category'] !== 5) continue; ?>
              <option value="<?= $ot['offense_type_id'] ?>" data-level="MAJOR">
                <?= htmlspecialchars($ot['code']) ?> — <?= htmlspecialchars($ot['name']) ?>
              </option>
              <?php endforeach; ?>
            </optgroup>
          </select>
          <div id="levelBadge" class="level-indicator">
            <svg id="levelBadgeIcon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"></svg>
            <span id="levelBadgeText"></span>
          </div>
        </div>

        <div class="field">
          <div class="field-label">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
              <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/>
            </svg>
            Date &amp; Time of Incident
          </div>
          <input type="datetime-local" id="dateCommitted" class="form-control">
        </div>

        <div class="form-divider"></div>

        <button class="btn-submit" id="submitBtn" onclick="submitReport()">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/>
          </svg>
          Submit Violation Report
        </button>
        <p class="form-hint">
          <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r=".5" fill="currentColor"/>
          </svg>
          Report only &mdash; admin will review &amp; confirm
        </p>
      </div>
    </div>
  </div>

  <div class="page-foot">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
      <rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
    </svg>
    Secured portal &middot; NU Lipa IdentiTrack &copy; <?= date('Y') ?>
  </div>

</div><!-- /wrap -->

<!-- ─── TOAST ─── -->
<div class="toast" id="toast">
  <div class="toast-dot"><svg id="toastIcon" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"></svg></div>
  <div class="toast-msg" id="toastMsg"></div>
</div>

<!-- ─── LOGIN LOADING (shows on welcome=1) ─── -->
<?php if ($showWelcomeModal): ?>
<div class="modal-overlay show" id="guardLoginLoadingModal">
  <div class="modal-sheet loading-sheet">
    <div class="sheet-handle"></div>
    <div class="spinner"></div>
    <div class="loading-title">Login Successful</div>
    <div class="loading-sub">Loading your dashboard, <?= $guardName ?>…</div>
  </div>
</div>

<!-- ─── WELCOME CARD ─── -->
<div class="welcome-overlay" id="guardWelcomeModal">
  <div class="welcome-card">
    <div class="welcome-icon">👋</div>
    <div class="welcome-title">Welcome back, <?= $guardName ?>!</div>
    <div class="welcome-sub">You're now logged in to the Guard Portal. You can search for students and file violation reports.</div>
    <button class="welcome-cta" id="guardWelcomeOk">Let's Go →</button>
  </div>
</div>
<?php endif; ?>

<!-- ─── LOGOUT CONFIRM ─── -->
<div class="modal-overlay" id="guardLogoutModal">
  <div class="modal-sheet">
    <div class="sheet-handle"></div>
    <div class="sheet-title">Log out?</div>
    <div class="sheet-sub">You'll need to sign back in to access the Guard Portal.</div>
    <div class="sheet-actions">
      <button class="sheet-btn" id="guardLogoutCancel">Cancel</button>
      <button class="sheet-btn danger" id="guardLogoutConfirm">Yes, Logout</button>
    </div>
  </div>
</div>

<!-- ─── LOGOUT LOADING ─── -->
<div class="modal-overlay" id="guardLogoutLoadingModal">
  <div class="modal-sheet loading-sheet">
    <div class="sheet-handle"></div>
    <div class="spinner"></div>
    <div class="loading-title">Logging Out</div>
    <div class="loading-sub">Ending your session safely…</div>
  </div>
</div>

<script>
// ─── CLOCK ───
function updateClock() {
  const el = document.getElementById('guardNowTime');
  if (!el) return;
  const now = new Date();
  el.textContent = now.toLocaleTimeString([], { hour:'2-digit', minute:'2-digit' });
}
updateClock();
setInterval(updateClock, 1000);

// ─── WELCOME / LOGIN LOADING ───
const guardLoginLoadingModal = document.getElementById('guardLoginLoadingModal');
const guardWelcomeModal      = document.getElementById('guardWelcomeModal');
const guardWelcomeOk         = document.getElementById('guardWelcomeOk');

function closeWelcomeModal() {
  if (!guardWelcomeModal) return;
  guardWelcomeModal.classList.remove('show');
  if (window.history?.replaceState) {
    const url = new URL(window.location.href);
    url.searchParams.delete('welcome');
    window.history.replaceState({}, document.title, url.pathname + (url.search || ''));
  }
}

if (guardWelcomeModal && guardWelcomeOk) {
  if (guardLoginLoadingModal) {
    setTimeout(() => {
      guardLoginLoadingModal.classList.remove('show');
      guardWelcomeModal.classList.add('show');
      guardWelcomeOk.focus();
    }, 1200);
  } else {
    guardWelcomeModal.classList.add('show');
    guardWelcomeOk.focus();
  }
  guardWelcomeOk.addEventListener('click', closeWelcomeModal);
  guardWelcomeModal.addEventListener('click', e => { if (e.target === guardWelcomeModal) closeWelcomeModal(); });
}

// ─── LOGOUT ───
const guardLogoutLink         = document.getElementById('guardLogoutLink');
const guardLogoutModal        = document.getElementById('guardLogoutModal');
const guardLogoutCancel       = document.getElementById('guardLogoutCancel');
const guardLogoutConfirm      = document.getElementById('guardLogoutConfirm');
const guardLogoutLoadingModal = document.getElementById('guardLogoutLoadingModal');

function openLogoutModal()  { guardLogoutModal?.classList.add('show'); guardLogoutConfirm?.focus(); }
function closeLogoutModal() { guardLogoutModal?.classList.remove('show'); guardLogoutLink?.focus(); }

guardLogoutLink?.addEventListener('click', e => { e.preventDefault(); openLogoutModal(); });
guardLogoutCancel?.addEventListener('click', closeLogoutModal);
guardLogoutModal?.addEventListener('click', e => { if (e.target === guardLogoutModal) closeLogoutModal(); });

guardLogoutConfirm?.addEventListener('click', () => {
  closeLogoutModal();
  if (guardLogoutLoadingModal) guardLogoutLoadingModal.classList.add('show');
  // Trigger immediate redirect to prevent other background scripts from interfering
  window.location.href = 'logout.php';
});

document.addEventListener('keydown', e => {
  if (guardWelcomeModal?.classList.contains('show') && (e.key === 'Escape' || e.key === 'Enter')) {
    e.preventDefault(); closeWelcomeModal(); return;
  }
  if (!guardLogoutModal?.classList.contains('show')) return;
  if (e.key === 'Escape') { e.preventDefault(); closeLogoutModal(); }
  if (e.key === 'Enter' && !e.ctrlKey && !e.altKey && !e.metaKey && !e.shiftKey) {
    e.preventDefault(); guardLogoutConfirm?.click();
  }
});

// ─── SEARCH ───
const searchInput    = document.getElementById('searchInput');
const searchDropdown = document.getElementById('searchDropdown');
let searchTimeout;

searchInput.addEventListener('input', () => {
  clearTimeout(searchTimeout);
  const q = searchInput.value.trim();
  if (!q) { searchDropdown.classList.remove('show'); return; }

  searchTimeout = setTimeout(() => {
    fetch('api_search_student.php?q=' + encodeURIComponent(q))
      .then(r => r.json())
      .then(data => {
        if (!data || data.length === 0) {
          searchDropdown.innerHTML = `
            <div class="search-empty">
              <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
              </svg>
              No student found
            </div>`;
          searchDropdown.classList.add('show'); return;
        }
        searchDropdown.innerHTML = data.map(s => `
          <div class="student-result" onclick="selectStudent(
            '${esc(s.student_id)}','${esc(s.student_fn)}','${esc(s.student_ln)}',
            '${esc(s.year_level)}','${esc(s.program)}','${esc(s.section||'')}'
          )">
            <div class="result-ava">${s.student_fn.charAt(0).toUpperCase()}</div>
            <div class="result-info">
              <div class="result-name">${esc(s.student_fn)} ${esc(s.student_ln)}</div>
              <div class="result-meta">
                <span class="result-tag">${esc(s.student_id)}</span>
                <span class="result-tag">${esc(s.program)}</span>
                <span class="result-tag">Yr ${s.year_level}</span>
              </div>
            </div>
          </div>`).join('');
        searchDropdown.classList.add('show');
      })
      .catch(() => {
        searchDropdown.innerHTML = '<div class="search-empty">Search failed. Retry.</div>';
        searchDropdown.classList.add('show');
      });
  }, 280);
});

document.addEventListener('click', e => {
  if (!e.target.closest('.search-wrapper')) searchDropdown.classList.remove('show');
});

// ─── SELECT STUDENT ───
function selectStudent(id, fn, ln, yr, prog, sec) {
  document.getElementById('selectedStudentId').value = id;
  document.getElementById('selectedStudentInfo').innerHTML = `
    <div class="pill-ava">${fn.charAt(0).toUpperCase()}</div>
    <div class="pill-info">
      <div class="pill-name">${esc(fn)} ${esc(ln)}</div>
      <div class="pill-meta">
        <span class="pill-tag">${esc(id)}</span>
        <span class="pill-tag">${esc(prog)}</span>
        <span class="pill-tag">Year ${yr}</span>
      </div>
    </div>
    <div class="pill-clear" onclick="clearStudent()" title="Clear">
      <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
        <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
      </svg>
    </div>`;

  const now = new Date();
  now.setMinutes(now.getMinutes() - now.getTimezoneOffset());
  document.getElementById('dateCommitted').value = now.toISOString().slice(0,16);

  const wrap = document.getElementById('reportFormWrap');
  wrap.classList.remove('hidden');
  setTimeout(() => wrap.scrollIntoView({ behavior:'smooth', block:'start' }), 80);

  searchDropdown.classList.remove('show');
  searchInput.value = fn + ' ' + ln;
}

function clearStudent() {
  document.getElementById('reportFormWrap').classList.add('hidden');
  document.getElementById('selectedStudentId').value = '';
  document.getElementById('searchInput').value = '';
  document.getElementById('offenseSelect').value = '';
  updateLevelBadge();
  searchInput.focus();
}

// ─── LEVEL BADGE ───
function updateLevelBadge() {
  const sel   = document.getElementById('offenseSelect');
  const opt   = sel.options[sel.selectedIndex];
  const badge = document.getElementById('levelBadge');
  const icon  = document.getElementById('levelBadgeIcon');
  const text  = document.getElementById('levelBadgeText');

  if (!opt.value) { badge.className = 'level-indicator'; return; }

  const lvl = opt.dataset.level;
  if (lvl === 'MINOR') {
    badge.className = 'level-indicator minor show';
    icon.innerHTML = '<circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><circle cx="12" cy="16" r=".5" fill="currentColor"/>';
    text.textContent = 'Minor Offense';
  } else {
    badge.className = 'level-indicator major show';
    icon.innerHTML = '<path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>';
    text.textContent = 'Major Offense';
  }
}

// ─── SUBMIT ───
function submitReport() {
  const studentId  = document.getElementById('selectedStudentId').value;
  const offenseId  = document.getElementById('offenseSelect').value;
  const dateCommit = document.getElementById('dateCommitted').value;

  const desc       = document.getElementById('description').value;
  
  if (!studentId)  { showToast('No student selected.', 'error'); return; }
  if (!offenseId)  { showToast('Please select an offense type.', 'error'); return; }
  if (!dateCommit) { showToast('Please enter the incident date & time.', 'error'); return; }

  const btn = document.getElementById('submitBtn');
  btn.disabled = true;
  btn.innerHTML = `
    <svg style="animation:spin .7s linear infinite" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
      <path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/>
    </svg>
    Submitting…`;

  const fd = new FormData();
  fd.append('student_id',      studentId);
  fd.append('offense_type_id', offenseId);
  fd.append('date_committed',  dateCommit);
  fd.append('description',     desc);

  fetch('api_submit_report.php', { method:'POST', body:fd })
    .then(r => r.json())
    .then(data => {
      btn.disabled = false;
      btn.innerHTML = `<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg> Submit Violation Report`;
      if (data.success) {
        showToast('Report submitted! Pending admin review.', 'success');
        document.getElementById('reportFormWrap').classList.add('hidden');
        document.getElementById('offenseSelect').value = '';
        document.getElementById('description').value = '';
        updateLevelBadge();
        searchInput.value = '';
        document.getElementById('selectedStudentId').value = '';
      } else {
        showToast(data.message || 'Submission failed.', 'error');
      }
    })
    .catch(() => {
      btn.disabled = false;
      btn.innerHTML = `<svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg> Submit Violation Report`;
      showToast('Network error. Please try again.', 'error');
    });
}

// ─── TOAST ───
function showToast(msg, type='success') {
  const t = document.getElementById('toast');
  const i = document.getElementById('toastIcon');
  const m = document.getElementById('toastMsg');
  t.className = 'toast ' + type;
  m.textContent = msg;
  i.innerHTML = type === 'success'
    ? '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>'
    : '<path stroke-linecap="round" stroke-linejoin="round" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"/>';
  t.classList.add('show');
  setTimeout(() => t.classList.remove('show'), 3500);
}

// ─── ESCAPE HTML ───
function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

// ─── SPIN KEYFRAME ───
const s = document.createElement('style');
s.textContent = '@keyframes spin{to{transform:rotate(360deg)}}';
document.head.appendChild(s);
</script>
</body>
</html>