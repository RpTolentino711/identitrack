<?php
require_once __DIR__ . '/../database/database.php';
require_admin();

function guard_has_email_column(): bool {
  try {
    return db_one("SHOW COLUMNS FROM security_guard LIKE 'email'", []) !== null;
  } catch (Exception $e) {
    return false;
  }
}

if (($_GET['action'] ?? '') === 'create_guard') {
  header('Content-Type: application/json; charset=utf-8');

  $raw  = file_get_contents('php://input');
  $data = json_decode($raw ?: '{}', true);
  if (!is_array($data)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid request body.']);
    exit;
  }

  $full_name = trim((string)($data['full_name'] ?? ''));
  $username_input = strtolower(trim((string)($data['username'] ?? '')));
  $phone = trim((string)($data['phone'] ?? ''));
  $password = (string)($data['password'] ?? '');

  if ($full_name === '') {
    echo json_encode(['ok' => false, 'message' => 'Guard name is required.']);
    exit;
  }
  if ($username_input === '') {
    echo json_encode(['ok' => false, 'message' => 'Username is required.']);
    exit;
  }
  if (!preg_match('/^[a-z0-9._-]{3,50}$/', $username_input)) {
    echo json_encode(['ok' => false, 'message' => 'Username must be 3-50 chars using only letters, numbers, dot, underscore, or hyphen.']);
    exit;
  }
  if ($phone === '') {
    echo json_encode(['ok' => false, 'message' => 'Phone number is required.']);
    exit;
  }
  if (!preg_match('/^\d{7,25}$/', $phone)) {
    echo json_encode(['ok' => false, 'message' => 'Phone number must contain numbers only.']);
    exit;
  }
  if ($password === '') {
    echo json_encode(['ok' => false, 'message' => 'Password is required.']);
    exit;
  }
  if (strlen($password) < 8) {
    echo json_encode(['ok' => false, 'message' => 'Password must be at least 8 characters.']);
    exit;
  }

  $hasGuardEmail = guard_has_email_column();
  $email = $username_input . '@nulipa.edu.ph';

  $existingByUsername = db_one(
    "SELECT guard_id FROM security_guard WHERE username = ? LIMIT 1",
    [$username_input]
  );
  if ($existingByUsername) {
    echo json_encode(['ok' => false, 'message' => 'Username is already taken.']);
    exit;
  }

  if ($hasGuardEmail) {
    $existingByEmail = db_one(
      "SELECT guard_id FROM security_guard WHERE email = ? LIMIT 1",
      [$email]
    );
    if ($existingByEmail) {
      echo json_encode(['ok' => false, 'message' => 'Email is already registered.']);
      exit;
    }
  }

  $password_hash = password_hash($password, PASSWORD_BCRYPT);

  try {
    if ($hasGuardEmail) {
      db_exec(
        "INSERT INTO security_guard
          (full_name, username, email, role, password_hash, contact_number, is_active)
         VALUES
          (:full_name, :username, :email, 'GUARD', :password_hash, :contact_number, 1)",
        [
          ':full_name' => $full_name,
          ':username' => $username_input,
          ':email' => $email,
          ':password_hash' => $password_hash,
          ':contact_number' => $phone,
        ]
      );
    } else {
      db_exec(
        "INSERT INTO security_guard
          (full_name, username, role, password_hash, contact_number, is_active)
         VALUES
          (:full_name, :username, 'GUARD', :password_hash, :contact_number, 1)",
        [
          ':full_name' => $full_name,
          ':username' => $username_input,
          ':password_hash' => $password_hash,
          ':contact_number' => $phone,
        ]
      );
    }

    $newId = (int)db_last_id();
    echo json_encode([
      'ok' => true,
      'message' => 'Guard created successfully.',
      'guard' => [
        'id' => $newId,
        'full_name' => $full_name,
        'username' => $username_input,
        'phone' => $phone,
        'status' => 'active'
      ]
    ]);
  } catch (PDOException $e) {
    if ((string)$e->getCode() === '23000') {
      $msg = $e->getMessage();
      if (stripos($msg, 'username') !== false) {
        echo json_encode(['ok' => false, 'message' => 'Username is already taken.']);
        exit;
      }
      if ($hasGuardEmail && stripos($msg, 'email') !== false) {
        echo json_encode(['ok' => false, 'message' => 'Email is already registered.']);
        exit;
      }
      echo json_encode(['ok' => false, 'message' => 'Duplicate entry.']);
      exit;
    }
    echo json_encode(['ok' => false, 'message' => 'Insert failed: ' . $e->getMessage()]);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => 'Server error: ' . $e->getMessage()]);
  }
  exit;
}

if (($_GET['action'] ?? '') === 'list_guards') {
  header('Content-Type: application/json; charset=utf-8');

  try {
    $guards = db_all(
      "SELECT
         guard_id AS id,
         full_name,
         username,
         contact_number AS phone,
         is_active,
         CASE WHEN is_active = 1 THEN 'active' ELSE 'inactive' END AS status,
         created_at
       FROM security_guard
       ORDER BY created_at DESC",
      []
    );

    echo json_encode([
      'ok' => true,
      'guards' => $guards ?: []
    ]);
  } catch (Exception $e) {
    echo json_encode([
      'ok' => false,
      'message' => 'Failed to fetch guards.',
      'guards' => []
    ]);
  }
  exit;
}

if (($_GET['action'] ?? '') === 'delete_guard') {
  header('Content-Type: application/json; charset=utf-8');

  $raw = file_get_contents('php://input');
  $data = json_decode($raw ?: '{}', true);
  if (!is_array($data)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid request body.']);
    exit;
  }

  $guard_id = (int)($data['guard_id'] ?? 0);
  if ($guard_id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid guard ID.']);
    exit;
  }

  $guard = db_one(
    "SELECT guard_id, full_name FROM security_guard WHERE guard_id = ? LIMIT 1",
    [$guard_id]
  );
  if (!$guard) {
    echo json_encode(['ok' => false, 'message' => 'Guard not found.']);
    exit;
  }

  try {
    db_exec("DELETE FROM security_guard WHERE guard_id = ?", [$guard_id]);
    echo json_encode(['ok' => true, 'message' => 'Guard deleted successfully.']);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => 'Failed to delete guard: ' . $e->getMessage()]);
  }
  exit;
}

if (($_GET['action'] ?? '') === 'guard_status') {
  header('Content-Type: application/json; charset=utf-8');

  $raw = file_get_contents('php://input');
  $data = json_decode($raw ?: '{}', true);
  if (!is_array($data)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid request body.']);
    exit;
  }

  $guard_id = (int)($data['guard_id'] ?? 0);
  $status = trim((string)($data['status'] ?? ''));
  if ($guard_id <= 0) {
    echo json_encode(['ok' => false, 'message' => 'Invalid guard ID.']);
    exit;
  }
  if (!in_array($status, ['active', 'inactive'], true)) {
    echo json_encode(['ok' => false, 'message' => 'Invalid status.']);
    exit;
  }

  $is_active = ($status === 'active') ? 1 : 0;

  try {
    $count = db_exec(
      "UPDATE security_guard SET is_active = ?, updated_at = NOW() WHERE guard_id = ?",
      [$is_active, $guard_id]
    );
    if ($count <= 0) {
      echo json_encode(['ok' => false, 'message' => 'Guard not found.']);
      exit;
    }
    echo json_encode(['ok' => true, 'message' => 'Guard status updated.']);
  } catch (Exception $e) {
    echo json_encode(['ok' => false, 'message' => 'Failed to update guard status: ' . $e->getMessage()]);
  }
  exit;
}

$activeSidebar = 'profile';
$reauthOk = !empty($_SESSION['profile_reauth_ok']);

$admin = admin_current();
$adminId = (int)($admin['admin_id'] ?? 0);

if ($adminId > 0) {
  $adminDb = db_one(
    "SELECT full_name, username, email, role, photo_path, updated_at
     FROM admin_user
     WHERE admin_id = ?
     LIMIT 1",
    [$adminId]
  );
  if ($adminDb) $admin = array_merge($admin, $adminDb);
}

$fullName = trim((string)($admin['full_name'] ?? ''));
if ($fullName === '') $fullName = (string)($admin['username'] ?? 'User');

$role      = (string)($admin['role']     ?? 'Admin');
$email     = (string)($admin['email']    ?? '');
$username  = (string)($admin['username'] ?? '');

$initials = '';
foreach (explode(' ', $fullName) as $w) {
  $initials .= strtoupper(mb_substr(trim($w), 0, 1));
  if (strlen($initials) >= 2) break;
}
if ($initials === '') $initials = strtoupper(mb_substr($fullName, 0, 2));

$defaultProfilePhoto = '../assets/logo.png';
$profilePhotoRaw = trim((string)($admin['photo_path'] ?? $admin['photo'] ?? $admin['profile_photo'] ?? ''));
if ($profilePhotoRaw !== '') {
  $profilePhotoRaw = str_replace('\\', '/', $profilePhotoRaw);
  if (!preg_match('/^(https?:\/\/|data:|\.\.\/|\/)/i', $profilePhotoRaw)) {
    $profilePhotoRaw = '../' . ltrim($profilePhotoRaw, '/');
  }
}
$hasCustomPhoto  = ($profilePhotoRaw !== '');
$profilePhoto    = $hasCustomPhoto ? $profilePhotoRaw : $defaultProfilePhoto;
$profilePhotoSrc = $profilePhoto . ($hasCustomPhoto ? ('?v=' . urlencode((string)($admin['updated_at'] ?? time()))) : '');
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Profile Settings | IdentiTrack</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet" />

  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    :root {
      --ink:        #0a0f1e;
      --ink-2:      #1e2742;
      --ink-3:      #334166;
      --slate:      #64748b;
      --mist:       #94a3b8;
      --fog:        #e2e8f4;
      --cloud:      #f1f5fd;
      --snow:       #fafbff;
      --white:      #ffffff;

      --primary:    #2563eb;
      --primary-d:  #1d4ed8;
      --primary-l:  #eff6ff;
      --primary-m:  #dbeafe;

      --emerald:    #059669;
      --emerald-l:  #ecfdf5;
      --ruby:       #dc2626;
      --ruby-l:     #fef2f2;
      --amber:      #d97706;
      --amber-l:    #fffbeb;

      --r-xs: 8px;
      --r-sm: 12px;
      --r-md: 16px;
      --r-lg: 20px;
      --r-xl: 24px;

      --shadow-1: 0 1px 3px rgba(10,15,30,.06), 0 1px 2px rgba(10,15,30,.04);
      --shadow-2: 0 4px 12px rgba(10,15,30,.08), 0 2px 4px rgba(10,15,30,.05);
      --shadow-3: 0 8px 32px rgba(10,15,30,.12), 0 4px 8px rgba(10,15,30,.06);
      --shadow-pop: 0 20px 60px rgba(10,15,30,.18), 0 8px 16px rgba(10,15,30,.08);
    }

    html, body { height: 100%; }
    body {
      font-family: 'Sora', sans-serif;
      background: var(--cloud);
      color: var(--ink);
      font-size: 14px;
      line-height: 1.6;
    }

    /* ── Shell ── */
    .shell {
      min-height: calc(100vh - 64px);
      display: grid;
      grid-template-columns: 240px 1fr;
    }
    .main { display: flex; flex-direction: column; min-height: 100%; }

    /* ── Top bar ── */
    .topbar {
      background: var(--white);
      border-bottom: 1px solid var(--fog);
      padding: 20px 32px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
    }
    .topbar-left { display: flex; flex-direction: column; gap: 2px; }
    .topbar-left h1 {
      font-size: 20px;
      font-weight: 800;
      letter-spacing: -.5px;
      color: var(--ink);
    }
    .topbar-left p { font-size: 13px; color: var(--slate); }
    .topbar-welcome {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      margin-top: 6px;
      font-size: 12px;
      color: var(--ink-3);
      background: var(--primary-l);
      border: 1px solid var(--primary-m);
      border-radius: 999px;
      padding: 4px 10px;
      width: fit-content;
      font-weight: 600;
    }
    .topbar-badge {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--primary-m);
      color: var(--primary);
      font-size: 11px;
      font-weight: 700;
      padding: 5px 12px;
      border-radius: 999px;
      letter-spacing: .5px;
      text-transform: uppercase;
    }
    .topbar-badge::before {
      content: '';
      width: 5px; height: 5px;
      border-radius: 50%;
      background: var(--primary);
    }

    /* ── Content grid ── */
    .content {
      padding: 24px 32px;
      flex: 1;
      display: grid;
      grid-template-columns: 280px 1fr;
      gap: 20px;
      align-items: start;
    }
    .col-left  {
      display: flex;
      flex-direction: column;
      gap: 16px;
    }
    .col-right { display: flex; flex-direction: column; gap: 16px; }
    .col-full  { grid-column: 1 / -1; }

    /* ── Card ── */
    .card {
      background: var(--white);
      border: 1px solid var(--fog);
      border-radius: var(--r-lg);
      box-shadow: var(--shadow-1);
      overflow: hidden;
    }
    .card-top {
      padding: 16px 20px;
      border-bottom: 1px solid var(--fog);
      display: flex;
      align-items: center;
      gap: 12px;
      background: linear-gradient(to bottom, var(--snow), var(--white));
    }
    .card-ico {
      width: 36px; height: 36px;
      border-radius: var(--r-sm);
      display: grid;
      place-items: center;
      flex-shrink: 0;
    }
    .card-ico svg { width: 17px; height: 17px; }
    .card-ico--blue   { background: var(--primary-m); color: var(--primary); }
    .card-ico--red    { background: var(--ruby-l);    color: var(--ruby); }
    .card-ico--green  { background: var(--emerald-l); color: var(--emerald); }
    .card-ico--amber  { background: var(--amber-l);   color: var(--amber); }
    .card-ico--ink    { background: var(--cloud);     color: var(--ink-3); }

    .card-meta h2 { font-size: 14px; font-weight: 700; letter-spacing: -.2px; }
    .card-meta p  { font-size: 12px; color: var(--slate); margin-top: 1px; }
    .card-body  { padding: 20px; }
    .card-foot  {
      padding: 14px 20px;
      border-top: 1px solid var(--fog);
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 10px;
      background: var(--snow);
    }

    /* ── Profile hero ── */
    .profile-hero {
      padding: 28px 20px 20px;
      display: flex;
      flex-direction: column;
      align-items: center;
      text-align: center;
      background: linear-gradient(160deg, var(--primary-m) 0%, var(--snow) 50%, var(--white) 100%);
      border-bottom: 1px solid var(--fog);
      position: relative;
      overflow: hidden;
    }
    .profile-hero::before {
      content: '';
      position: absolute;
      top: -40px; right: -40px;
      width: 180px; height: 180px;
      border-radius: 50%;
      background: radial-gradient(circle, rgba(37,99,235,.08) 0%, transparent 70%);
      pointer-events: none;
    }

    .avatar-wrap {
      position: relative;
      width: 90px; height: 90px;
      cursor: pointer;
    }
    .avatar-ring-outer {
      width: 90px; height: 90px;
      border-radius: 50%;
      padding: 3px;
      background: linear-gradient(135deg, var(--primary) 0%, #60a5fa 100%);
      box-shadow: 0 0 0 3px var(--white), var(--shadow-2);
    }
    .avatar-img, .avatar-initials {
      width: 100%; height: 100%;
      border-radius: 50%;
      object-fit: cover;
      display: block;
    }
    .avatar-initials {
      background: var(--ink-2);
      color: var(--white);
      font-size: 28px;
      font-weight: 800;
      display: grid;
      place-items: center;
    }
    .avatar-overlay {
      position: absolute;
      inset: 3px;
      border-radius: 50%;
      background: rgba(10,15,30,.5);
      display: grid;
      place-items: center;
      opacity: 0;
      transition: opacity .2s;
      pointer-events: none;
    }
    .avatar-overlay svg { width: 18px; height: 18px; color: var(--white); }
    .avatar-wrap:hover .avatar-overlay { opacity: 1; }

    .hero-name {
      margin-top: 14px;
      font-size: 17px;
      font-weight: 800;
      letter-spacing: -.4px;
    }
    .hero-role {
      margin-top: 5px;
      font-size: 11px;
      font-weight: 700;
      letter-spacing: .8px;
      text-transform: uppercase;
      color: var(--primary);
      background: var(--primary-m);
      padding: 3px 10px;
      border-radius: 999px;
    }
    .hero-email {
      margin-top: 7px;
      font-family: 'JetBrains Mono', monospace;
      font-size: 11.5px;
      color: var(--slate);
    }

    .hero-stats {
      display: grid;
      grid-template-columns: 1fr;
      gap: 8px;
      width: 100%;
      margin-top: 16px;
    }
    .stat-box {
      background: var(--white);
      border: 1px solid var(--fog);
      border-radius: var(--r-sm);
      padding: 10px;
      text-align: center;
    }
    .stat-label { font-size: 10px; font-weight: 600; color: var(--mist); text-transform: uppercase; letter-spacing: .5px; }
    .stat-value { font-size: 13px; font-weight: 700; color: var(--ink); margin-top: 2px; }

    .hero-actions {
      display: flex;
      gap: 8px;
      width: 100%;
      margin-top: 14px;
    }

    /* ── Form ── */
    .field { display: flex; flex-direction: column; gap: 5px; }
    .field + .field { margin-top: 14px; }
    .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }

    label {
      font-size: 11px;
      font-weight: 700;
      color: var(--slate);
      letter-spacing: .4px;
      text-transform: uppercase;
    }

    .inp-wrap { position: relative; display: flex; align-items: center; }
    .inp-icon {
      position: absolute;
      left: 11px;
      color: var(--mist);
      display: flex;
      align-items: center;
      pointer-events: none;
    }
    .inp-icon svg { width: 14px; height: 14px; }

    input[type="text"],
    input[type="email"],
    input[type="password"],
    input[type="number"] {
      width: 100%;
      height: 40px;
      border: 1.5px solid var(--fog);
      border-radius: var(--r-sm);
      padding: 0 12px;
      font-family: 'Sora', sans-serif;
      font-size: 13.5px;
      font-weight: 500;
      color: var(--ink);
      background: var(--white);
      outline: none;
      transition: border-color .15s, box-shadow .15s;
    }
    .has-icon input { padding-left: 34px; }
    input:focus {
      border-color: var(--primary);
      box-shadow: 0 0 0 3px rgba(37,99,235,.1);
    }
    input:disabled {
      background: var(--cloud);
      color: var(--mist);
      cursor: not-allowed;
    }
    input.with-eye { padding-right: 40px; }

    .eye-btn {
      position: absolute;
      right: 7px;
      width: 28px; height: 28px;
      border-radius: 7px;
      border: 1px solid var(--fog);
      background: var(--cloud);
      cursor: pointer;
      display: grid;
      place-items: center;
      color: var(--mist);
      transition: all .15s;
    }
    .eye-btn svg { width: 13px; height: 13px; }
    .eye-btn:hover { border-color: var(--primary); color: var(--primary); background: var(--primary-l); }

    .hint { font-size: 11.5px; color: var(--mist); margin-top: 3px; }

    /* ── Buttons ── */
    .btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      height: 38px;
      padding: 0 16px;
      border-radius: var(--r-sm);
      font-family: 'Sora', sans-serif;
      font-size: 13px;
      font-weight: 600;
      cursor: pointer;
      transition: all .15s;
      white-space: nowrap;
      border: 1.5px solid var(--fog);
      background: var(--white);
      color: var(--ink-3);
    }
    .btn svg { width: 14px; height: 14px; flex-shrink: 0; }
    .btn:hover { background: var(--cloud); border-color: #c8d2e8; }

    .btn-primary {
      background: var(--primary);
      border-color: var(--primary);
      color: var(--white);
    }
    .btn-primary:hover { background: var(--primary-d); border-color: var(--primary-d); }

    .btn-danger {
      background: var(--ruby-l);
      border-color: #fecaca;
      color: var(--ruby);
    }
    .btn-danger:hover { background: #fee2e2; border-color: #fca5a5; }

    .btn-ghost-blue {
      background: var(--primary-l);
      border-color: var(--primary-m);
      color: var(--primary);
    }
    .btn-ghost-blue:hover { background: var(--primary-m); }

    .btn-sm { height: 32px; padding: 0 12px; font-size: 12px; border-radius: 8px; }
    .btn:disabled { opacity: .4; cursor: not-allowed; pointer-events: none; }

    /* ── Password strength ── */
    .pw-rules {
      margin-top: 12px;
      border: 1.5px solid var(--fog);
      border-radius: var(--r-sm);
      padding: 12px 14px;
      background: var(--snow);
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 6px 10px;
    }
    .rule {
      display: flex;
      align-items: center;
      gap: 7px;
      font-size: 12px;
      color: var(--slate);
    }
    .rule-dot {
      width: 7px; height: 7px;
      border-radius: 50%;
      border: 1.5px solid var(--mist);
      flex-shrink: 0;
      transition: all .2s;
    }
    .rule.ok .rule-dot { background: var(--emerald); border-color: var(--emerald); }
    .rule.ok .rule-txt { color: var(--emerald); font-weight: 600; }
    .rule.bad .rule-dot { border-color: #fca5a5; }

    .strength-track {
      margin-top: 10px;
      height: 4px;
      background: var(--fog);
      border-radius: 99px;
      overflow: hidden;
    }
    .strength-fill {
      height: 100%;
      border-radius: 99px;
      transition: width .3s, background .3s;
      width: 0%;
    }
    .strength-text {
      font-size: 11.5px;
      font-weight: 600;
      margin-top: 5px;
      min-height: 16px;
    }

    /* ── Guard table ── */
    .guard-table { width: 100%; border-collapse: collapse; font-size: 13px; }
    .guard-table thead th {
      padding: 10px 14px;
      text-align: left;
      font-size: 10.5px;
      font-weight: 700;
      letter-spacing: .6px;
      text-transform: uppercase;
      color: var(--mist);
      border-bottom: 1px solid var(--fog);
      background: var(--snow);
    }
    .guard-table tbody tr { border-bottom: 1px solid var(--fog); transition: background .12s; }
    .guard-table tbody tr:last-child { border-bottom: none; }
    .guard-table tbody tr:hover { background: var(--snow); }
    .guard-table td { padding: 13px 14px; }

    .g-name { font-weight: 600; color: var(--ink); }
    .g-user {
      font-family: 'JetBrains Mono', monospace;
      font-size: 11.5px;
      color: var(--slate);
      margin-top: 2px;
    }
    .g-phone { color: var(--slate); font-size: 13px; }
    .guard-toggle-btn {
      margin-left: 8px;
    }
    .guard-toggle-btn svg {
      transition: transform .18s ease;
    }
    .guard-toggle-btn.collapsed svg {
      transform: rotate(-90deg);
    }
    .guard-panel {
      overflow: hidden;
    }
    .guard-panel.hidden {
      display: none;
    }
    .pill {
      display: inline-flex;
      align-items: center;
      gap: 5px;
      padding: 3px 9px;
      border-radius: 99px;
      font-size: 11.5px;
      font-weight: 600;
    }
    .pill::before { content: ''; width: 5px; height: 5px; border-radius: 50%; }
    .pill-active  { background: var(--emerald-l); color: var(--emerald); }
    .pill-active::before { background: var(--emerald); }
    .pill-inactive { background: var(--ruby-l); color: var(--ruby); }
    .pill-inactive::before { background: var(--ruby); }

    .guard-empty {
      padding: 40px;
      text-align: center;
      color: var(--mist);
    }
    .guard-empty svg { width: 36px; height: 36px; margin-bottom: 10px; opacity: .35; display: block; margin-left: auto; margin-right: auto; }
    .guard-empty p { font-size: 13px; }

    /* ── Alert ── */
    .alert {
      border-radius: var(--r-sm);
      padding: 10px 14px;
      font-size: 13px;
      font-weight: 500;
      display: none;
      margin-top: 10px;
    }
    .alert.show { display: block; }
    .alert-danger  { background: var(--ruby-l);    border: 1px solid #fecaca; color: var(--ruby); }
    .alert-success { background: var(--emerald-l); border: 1px solid #a7f3d0; color: var(--emerald); }
    .alert-info    { background: var(--primary-l); border: 1px solid var(--primary-m); color: var(--primary); }

    /* ── Modal ── */
    .modal-bg {
      position: fixed;
      inset: 0;
      background: rgba(5,10,30,.6);
      backdrop-filter: blur(6px);
      display: none;
      align-items: center;
      justify-content: center;
      z-index: 200;
      padding: 20px;
    }
    .modal-bg.show { display: flex; }
    .modal {
      width: min(520px, 100%);
      background: var(--white);
      border: 1px solid var(--fog);
      border-radius: var(--r-xl);
      box-shadow: var(--shadow-pop);
      overflow: hidden;
      animation: modal-pop .22s cubic-bezier(.34,1.56,.64,1);
    }
    @keyframes modal-pop {
      from { opacity: 0; transform: scale(.92) translateY(10px); }
      to   { opacity: 1; transform: none; }
    }

    .modal-head {
      padding: 18px 20px;
      border-bottom: 1px solid var(--fog);
      display: flex;
      align-items: center;
      gap: 12px;
      background: var(--snow);
    }
    .modal-head-ico {
      width: 38px; height: 38px;
      border-radius: var(--r-sm);
      display: grid;
      place-items: center;
      flex-shrink: 0;
    }
    .modal-head-ico svg { width: 18px; height: 18px; }
    .modal-head-logo {
      width: 24px;
      height: 24px;
      object-fit: contain;
      display: block;
    }
    .modal-title h3 { font-size: 15px; font-weight: 800; letter-spacing: -.3px; }
    .modal-title p  { font-size: 12px; color: var(--slate); margin-top: 1px; }
    .modal-xbtn {
      margin-left: auto;
      width: 30px; height: 30px;
      border-radius: 8px;
      border: 1px solid var(--fog);
      background: var(--cloud);
      cursor: pointer;
      display: grid;
      place-items: center;
      color: var(--slate);
      font-size: 18px;
      transition: all .15s;
    }
    .modal-xbtn:hover { background: var(--ruby-l); border-color: #fecaca; color: var(--ruby); }

    .modal-body { padding: 20px; }
    .modal-foot {
      padding: 14px 20px;
      border-top: 1px solid var(--fog);
      display: flex;
      justify-content: flex-end;
      gap: 10px;
      background: var(--snow);
    }

    .otp-input {
      font-family: 'JetBrains Mono', monospace !important;
      font-size: 26px !important;
      letter-spacing: 10px !important;
      text-align: center !important;
    }

    /* ── Section divider ── */
    .sep {
      display: flex;
      align-items: center;
      gap: 10px;
      margin: 18px 0 14px;
    }
    .sep-line { flex: 1; height: 1px; background: var(--fog); }
    .sep-label { font-size: 10px; font-weight: 700; color: var(--mist); text-transform: uppercase; letter-spacing: .6px; }

    /* ── Spinner ── */
    .spinner {
      width: 16px; height: 16px;
      border: 2px solid rgba(255,255,255,.3);
      border-top-color: #fff;
      border-radius: 50%;
      animation: spin .7s linear infinite;
      display: none;
    }
    .spinner.show { display: block; }
    @keyframes spin { to { transform: rotate(360deg); } }

    /* ── Toast ── */
    #toast-root {
      position: fixed;
      bottom: 24px;
      right: 24px;
      z-index: 999;
      display: flex;
      flex-direction: column;
      gap: 8px;
    }
    .toast {
      display: flex;
      align-items: center;
      gap: 10px;
      padding: 12px 16px;
      border-radius: var(--r-md);
      background: var(--ink-2);
      color: var(--white);
      font-size: 13px;
      font-weight: 500;
      box-shadow: var(--shadow-3);
      min-width: 240px;
      max-width: 380px;
      animation: toast-in .25s cubic-bezier(.34,1.56,.64,1);
    }
    @keyframes toast-in {
      from { opacity: 0; transform: translateX(24px) scale(.95); }
      to   { opacity: 1; transform: none; }
    }
    .toast-dot {
      width: 8px; height: 8px; border-radius: 50%; flex-shrink: 0;
    }
    .toast-success .toast-dot { background: #4ade80; }
    .toast-error   .toast-dot { background: #f87171; }
    .toast-info    .toast-dot { background: #60a5fa; }

    .hidden { display: none !important; }

    /* ── Scrollbar ── */
    ::-webkit-scrollbar { width: 6px; height: 6px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: var(--fog); border-radius: 99px; }

    @media (max-width: 1100px) {
      .content { grid-template-columns: 280px 1fr; padding: 20px 24px; }
    }
    @media (max-width: 900px) {
      .content { grid-template-columns: 1fr; }
      .shell { grid-template-columns: 1fr; }
    }
    @media (max-width: 600px) {
      .topbar, .content { padding: 16px; }
      .grid-2 { grid-template-columns: 1fr; }
      .pw-rules { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>
  <div id="toast-root"></div>

  <div class="shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="main">
      <!-- Top Bar -->
      <div class="topbar">
        <div class="topbar-left">
          <h1>Profile & Settings</h1>
          <p>Manage your account, security, and guard access</p>
          <div class="topbar-welcome">Welcome, <?php echo htmlspecialchars($fullName); ?></div>
        </div>
        <div class="topbar-badge"><?php echo htmlspecialchars($role); ?></div>
      </div>

      <!-- Main Content -->
      <div class="content" id="pageContent" style="<?= $reauthOk ? '' : 'display:none;' ?>">

        <!-- Left Column -->
        <div class="col-left">
          <!-- Profile Hero Card -->
          <div class="card">
            <div class="profile-hero">
              <div class="avatar-wrap" id="avatarBtn" title="Change photo">
                <div class="avatar-ring-outer">
                  <?php if ($hasCustomPhoto): ?>
                    <img id="avatarImg" class="avatar-img" src="<?php echo htmlspecialchars($profilePhotoSrc); ?>" alt="<?php echo htmlspecialchars($fullName); ?>" />
                  <?php else: ?>
                    <div class="avatar-initials" id="avatarInitials"><?php echo htmlspecialchars($initials); ?></div>
                    <img id="avatarImg" class="avatar-img hidden" src="" alt="" />
                  <?php endif; ?>
                </div>
                <div class="avatar-overlay">
                  <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
                </div>
              </div>

              <div class="hero-name"><?php echo htmlspecialchars($fullName); ?></div>
              <div class="hero-role"><?php echo htmlspecialchars($role); ?></div>
              <div class="hero-email"><?php echo htmlspecialchars($email); ?></div>

              <div class="hero-stats">
                <div class="stat-box">
                  <div class="stat-label">Username</div>
                  <div class="stat-value">@<?php echo htmlspecialchars($username ?: 'admin'); ?></div>
                </div>
              </div>

              <input id="photoFile" type="file" accept="image/*" class="hidden" />
              <div class="hero-actions">
                <button class="btn btn-sm btn-primary" type="button" id="btnUploadPhoto" style="flex:1.2;">
                  <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="16 16 12 12 8 16"/><line x1="12" y1="12" x2="12" y2="21"/><path d="M20.39 18.39A5 5 0 0 0 18 9h-1.26A8 8 0 1 0 3 16.3"/></svg>
                  Upload
                </button>
              </div>
            </div>
          </div>

        </div>

        <!-- Right Column -->
        <div class="col-right">

          <!-- Personal Info -->
          <div class="card">
            <div class="card-top">
              <div class="card-ico card-ico--blue">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
              </div>
              <div class="card-meta">
                <h2>Personal Information</h2>
                <p>Update your name and email</p>
              </div>
            </div>
            <div class="card-body">
              <div class="grid-2">
                <div class="field">
                  <label>Full Name</label>
                  <input type="text" id="inpFullName" value="<?php echo htmlspecialchars($fullName); ?>" placeholder="Your full name" />
                </div>
                <div class="field">
                  <label>Role</label>
                  <input type="text" value="<?php echo htmlspecialchars($role); ?>" disabled />
                </div>
              </div>
              <div class="grid-2" style="margin-top:14px;">
                <div class="field">
                  <label>Email Address</label>
                  <input type="email" id="inpEmail" value="<?php echo htmlspecialchars($email); ?>" placeholder="you@example.com" />
                  <div class="hint">Changing email requires OTP verification.</div>
                </div>
              </div>
            </div>
            <div class="card-foot">
              <button class="btn" type="button" id="btnCancelProfile">Discard</button>
              <button class="btn btn-primary" type="button" id="btnSaveProfile">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2z"/><polyline points="17 21 17 13 7 13 7 21"/><polyline points="7 3 7 8 15 8"/></svg>
                Save Changes
              </button>
            </div>
          </div>

          <!-- Change Password -->
          <div class="card">
            <div class="card-top">
              <div class="card-ico card-ico--red">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
              </div>
              <div class="card-meta">
                <h2>Change Password</h2>
                <p>Requires OTP verification on save</p>
              </div>
            </div>
            <div class="card-body">
              <div class="grid-2" style="margin-top:14px;">
                <div class="field">
                  <label>New Password</label>
                  <div class="inp-wrap">
                    <input type="password" id="pwNew" class="with-eye" placeholder="New password" />
                    <button class="eye-btn" type="button" id="toggleNew">
                      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                  </div>
                </div>
                <div class="field">
                  <label>Confirm Password</label>
                  <div class="inp-wrap">
                    <input type="password" id="pwConfirm" class="with-eye" placeholder="Confirm password" />
                    <button class="eye-btn" type="button" id="toggleConfirm">
                      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
                    </button>
                  </div>
                </div>
              </div>

              <div class="pw-rules" id="pwRules">
                <div class="rule bad" id="rLen"><span class="rule-dot"></span><span class="rule-txt">8+ characters</span></div>
                <div class="rule bad" id="rUp"><span class="rule-dot"></span><span class="rule-txt">Uppercase letter</span></div>
                <div class="rule bad" id="rLow"><span class="rule-dot"></span><span class="rule-txt">Lowercase letter</span></div>
                <div class="rule bad" id="rNum"><span class="rule-dot"></span><span class="rule-txt">Number</span></div>
                <div class="rule bad" id="rSpc"><span class="rule-dot"></span><span class="rule-txt">Special character</span></div>
                <div class="rule bad" id="rMatch"><span class="rule-dot"></span><span class="rule-txt">Passwords match</span></div>
              </div>

              <div class="strength-track"><div class="strength-fill" id="strengthFill"></div></div>
              <div class="strength-text" id="strengthText"></div>
            </div>
            <div class="card-foot">
              <button class="btn btn-primary" type="button" id="btnChangePw" disabled>
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
                Change Password
              </button>
            </div>
          </div>

        </div><!-- /col-right -->

        <!-- Guards Table — full width -->
        <div class="col-full">
          <div class="card">
            <div class="card-top">
              <div class="card-ico card-ico--amber">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
              </div>
              <div class="card-meta">
                <h2>Guard Management</h2>
                <p>Create and manage security guard accounts</p>
              </div>
              <button class="btn btn-primary btn-sm" type="button" id="btnAddGuard" style="margin-left:auto;">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                Add Guard
              </button>
              <button class="btn btn-sm guard-toggle-btn" type="button" id="btnToggleGuards" aria-expanded="true" title="Collapse/Expand guard management">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="6 9 12 15 18 9"/></svg>
                Toggle
              </button>
            </div>
            <div class="guard-panel" id="guardPanel" style="overflow-x:auto;">
              <table class="guard-table">
                <thead>
                  <tr>
                    <th>Guard</th>
                    <th>Phone</th>
                    <th>Status</th>
                    <th style="text-align:right;">Actions</th>
                  </tr>
                </thead>
                <tbody id="guardsBody">
                  <tr><td colspan="4">
                    <div class="guard-empty">
                      <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                      <p>No guards yet. Click "Add Guard" to create one.</p>
                    </div>
                  </td></tr>
                </tbody>
              </table>
            </div>
          </div>
        </div>

      </div><!-- /content -->
    </main>
  </div>

  <!-- ── Modal: Re-auth ── -->
  <div class="modal-bg <?= $reauthOk ? '' : 'show' ?>" id="modalReauth">
    <div class="modal">
      <div class="modal-head">
        <div class="modal-head-ico card-ico--blue">
          <img src="../assets/logo.png" alt="IdentiTrack" class="modal-head-logo" />
        </div>
        <div class="modal-title">
          <h3>Verify Identity</h3>
          <p>Enter your password to access Profile Settings</p>
        </div>
      </div>
      <div class="modal-body">
        <div class="field">
          <label>Admin Password</label>
          <div class="inp-wrap">
            <input type="password" id="reauthPw" class="with-eye" placeholder="Enter your password" />
            <button class="eye-btn" type="button" id="toggleReauthPw">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
            </button>
          </div>
        </div>
        <div class="alert alert-danger" id="reauthMsg"></div>
      </div>
      <div class="modal-foot">
        <button class="btn btn-danger" type="button" id="reauthCancel">Cancel</button>
        <button class="btn btn-primary" type="button" id="btnReauth">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="9 18 15 12 9 6"/></svg>
          Continue
        </button>
      </div>
    </div>
  </div>

  <!-- ── Modal: Create Guard ── -->
  <div class="modal-bg" id="modalCreate">
    <div class="modal">
      <div class="modal-head">
        <div class="modal-head-ico card-ico--amber">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
        </div>
        <div class="modal-title">
          <h3>New Security Guard</h3>
          <p>Create a guard account for campus access</p>
        </div>
        <button class="modal-xbtn" data-close="#modalCreate">×</button>
      </div>
      <div class="modal-body">
        <div class="field">
          <label>Full Name</label>
          <input type="text" id="gName" placeholder="e.g. Juan dela Cruz" />
        </div>
        <div class="grid-2" style="margin-top:14px;">
          <div class="field">
            <label>Username</label>
            <input type="text" id="gUser" placeholder="e.g. juandelacruz" />
          </div>
          <div class="field">
            <label>Phone Number</label>
            <input type="text" id="gPhone" placeholder="09XXXXXXXXX" inputmode="numeric" maxlength="25" />
          </div>
        </div>
        <div class="grid-2" style="margin-top:14px;">
          <div class="field">
            <label>Password</label>
            <div class="inp-wrap">
              <input type="password" id="gPw" class="with-eye" placeholder="Set password" />
              <button class="eye-btn" type="button" id="toggleGPw">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
              </button>
            </div>
          </div>
          <div class="field">
            <label>Confirm Password</label>
            <input type="password" id="gPwConfirm" placeholder="Confirm password" />
          </div>
        </div>
        <div class="alert alert-danger" id="createMsg"></div>
      </div>
      <div class="modal-foot">
        <button class="btn" data-close="#modalCreate">Cancel</button>
        <button class="btn btn-primary" type="button" id="btnCreate">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
          Create Guard
        </button>
      </div>
    </div>
  </div>

  <!-- ── Modal: Delete Guard ── -->
  <div class="modal-bg" id="modalDelete">
    <div class="modal">
      <div class="modal-head">
        <div class="modal-head-ico card-ico--red">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
        </div>
        <div class="modal-title">
          <h3>Delete Guard</h3>
          <p id="delGuardSub">This action cannot be undone.</p>
        </div>
        <button class="modal-xbtn" data-close="#modalDelete">×</button>
      </div>
      <div class="modal-body">
        <div style="background:var(--ruby-l);border:1px solid #fecaca;border-radius:10px;padding:12px 14px;color:var(--ruby);font-size:13px;line-height:1.5;">
          Are you sure you want to permanently delete this guard account? All associated records will remain but the login will be revoked.
        </div>
        <div class="alert alert-danger" id="deleteMsg"></div>
      </div>
      <div class="modal-foot">
        <button class="btn" data-close="#modalDelete">Cancel</button>
        <button class="btn btn-danger" type="button" id="btnConfirmDelete">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg>
          Delete Guard
        </button>
      </div>
    </div>
  </div>

  <!-- ── Modal: OTP ── -->
  <div class="modal-bg" id="modalOtp">
    <div class="modal">
      <div class="modal-head">
        <div class="modal-head-ico card-ico--green">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07A19.5 19.5 0 0 1 4.99 13a19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 3.9 2.19h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.08-1.08a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7a2 2 0 0 1 1.72 2.03z"/></svg>
        </div>
        <div class="modal-title">
          <h3>OTP Verification</h3>
          <p>Check your email inbox for the 6-digit code</p>
        </div>
        <button class="modal-xbtn" data-close="#modalOtp">×</button>
      </div>
      <div class="modal-body">
        <div class="field">
          <label>One-Time Password</label>
          <input type="text" id="otpInput" class="otp-input" placeholder="• • • • • •" inputmode="numeric" maxlength="8" />
        </div>
        <div class="alert alert-danger" id="otpMsg"></div>
        <div class="resend-cooldown" style="text-align: center; margin-top: 15px; font-size: 13px; color: #64748b;">
          Didn't get the code? <br>
          <span id="resendWrap" style="display:none;">Resend in <span id="otpTimerText" style="font-weight:700; color:#3b429a;">180</span>s</span>
          <a href="#" id="resendLink" style="display:none; color:#3b429a; font-weight:700; text-decoration:none;">Request New Code</a>
        </div>
      </div>
      <div class="modal-foot">
        <button class="btn" data-close="#modalOtp">Cancel</button>
        <button class="btn btn-primary" type="button" id="btnVerifyOtp">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Verify & Update
        </button>
      </div>
    </div>
  </div>

  <!-- ── Modal: Success ── -->
  <div class="modal-bg" id="modalSuccess">
    <div class="modal">
      <div class="modal-head">
        <div class="modal-head-ico card-ico--green">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
        </div>
        <div class="modal-title">
          <h3 id="successTitle">Success!</h3>
          <p id="successMsg">Your profile has been updated successfully.</p>
        </div>
        <button class="modal-xbtn" data-close="#modalSuccess">×</button>
      </div>
      <div class="modal-foot">
        <button class="btn btn-primary" style="width:100%" data-close="#modalSuccess">Great, thanks!</button>
      </div>
    </div>
  </div>

  <script>
  (function(){
    // ── Utilities ──
    const $ = id => document.getElementById(id);
    const showModal = sel => document.querySelector(sel)?.classList.add('show');
    const hideModal = sel => document.querySelector(sel)?.classList.remove('show');

    document.addEventListener('click', e => {
      const t = e.target.closest('[data-close]');
      if (t) hideModal(t.getAttribute('data-close'));
    });

    function showAlert(el, msg, type = 'danger') {
      el.className = `alert alert-${type} show`;
      el.textContent = msg;
    }
    function hideAlert(el) { el.classList.remove('show'); }

    // Toast
    function toast(msg, type = 'success') {
      const r = document.getElementById('toast-root');
      const t = document.createElement('div');
      t.className = `toast toast-${type}`;
      t.innerHTML = `<span class="toast-dot"></span><span>${msg}</span>`;
      r.appendChild(t);
      setTimeout(() => t.remove(), 3800);
    }

    async function postJSON(url, body) {
      const r = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
        body: JSON.stringify(body), cache: 'no-store'
      });
      return { ok: r.ok, data: await r.json().catch(() => ({})), status: r.status };
    }
    async function postForm(url, body) {
      const r = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams(body), cache: 'no-store'
      });
      return { ok: r.ok, data: await r.json().catch(() => ({})) };
    }

    // ── Reauth ──
    const pageContent = $('pageContent');

    $('btnReauth').addEventListener('click', async () => {
      hideAlert($('reauthMsg'));
      const pw = $('reauthPw').value.trim();
      if (!pw) { showAlert($('reauthMsg'), 'Password is required.'); return; }
      const { ok, data } = await postJSON('AJAX/profile_reauth.php', { password: pw });
      if (ok && data?.ok) {
        hideModal('#modalReauth');
        pageContent.style.display = '';
        loadGuards();
        checkPw();
      } else {
        showAlert($('reauthMsg'), data?.message || 'Incorrect password.');
      }
    });
    $('reauthPw').addEventListener('keydown', e => { if (e.key === 'Enter') $('btnReauth').click(); });
    $('reauthCancel').addEventListener('click', () => location.href = 'dashboard.php');

    // ── Photo ──
    const photoFile = $('photoFile');
    const avatarImg = $('avatarImg');
    const avatarInit = $('avatarInitials');

    $('avatarBtn').addEventListener('click', () => photoFile.click());

    photoFile.addEventListener('change', () => {
      const f = photoFile.files?.[0];
      if (!f) return;
      avatarImg.src = URL.createObjectURL(f);
      avatarImg.classList.remove('hidden');
      avatarInit?.classList.add('hidden');
    });

    $('btnUploadPhoto').addEventListener('click', async () => {
      const f = photoFile.files?.[0];
      if (!f) { toast('Choose a photo first.', 'error'); return; }
      const fd = new FormData(); fd.append('photo', f);
      const res = await fetch('AJAX/profile_upload_photo.php', { method:'POST', body:fd, cache:'no-store' });
      const json = await res.json().catch(() => null);
      if (res.ok && json?.ok) {
        if (json.photo_url) avatarImg.src = json.photo_url;
        photoFile.value = '';
        toast('Profile photo updated.', 'success');
      } else {
        toast(json?.message || 'Upload failed.', 'error');
      }
    });

    // ── OTP Timer ──
    let otpTimer = 0;
    let otpInterval = null;
    function startOtpCooldown(sec = 180) {
      otpTimer = sec;
      const wrap = $('resendWrap'), link = $('resendLink'), txt = $('otpTimerText');
      if (!wrap || !link || !txt) return;
      wrap.style.display = 'inline'; link.style.display = 'none';
      txt.textContent = otpTimer;
      clearInterval(otpInterval);
      otpInterval = setInterval(() => {
        otpTimer--; txt.textContent = otpTimer;
        if (otpTimer <= 0) {
          clearInterval(otpInterval);
          wrap.style.display = 'none'; link.style.display = 'inline';
        }
      }, 1000);
    }

    $('resendLink').addEventListener('click', async (e) => {
      e.preventDefault();
      const { ok, data } = await postForm('send_otp_mail.php', { action: profileAction });
      if (ok && data?.success) {
        startOtpCooldown(180);
        toast('New OTP sent.');
      } else {
        toast(data?.message || 'Resend failed.', 'error');
      }
    });

    // ── Profile save / cancel ──
    let initialEmail = <?php echo json_encode($email); ?>;

    $('btnCancelProfile').addEventListener('click', () => {
      $('inpFullName').value = <?php echo json_encode($fullName); ?>;
      $('inpEmail').value    = initialEmail;
    });

    let profileAction = '';

    $('btnSaveProfile').addEventListener('click', async () => {
      const newEmail = $('inpEmail').value.trim();
      const newName  = $('inpFullName').value.trim();

      if (newEmail.toLowerCase() !== initialEmail.toLowerCase()) {
        // Email changed -> Need OTP
        profileAction = 'change_email';
        const { ok, data } = await postForm('send_otp_mail.php', { action: 'change_email' });
        if (!ok || !data?.success) {
          toast(data?.message || 'Failed to send OTP for email change.', 'error');
          return;
        }
        $('otpInput').value = '';
        hideAlert($('otpMsg'));
        showModal('#modalOtp');
      } else {
        // No email change -> Save immediately
        const { ok, data } = await postJSON('AJAX/profile_save.php', {
          full_name: newName,
          email:     newEmail,
        });
        if (ok && data?.ok) {
          toast(data?.message || 'Profile saved.', 'success');
        } else {
          toast(data?.message || 'Save failed.', 'error');
        }
      }
    });

    // ── Eye toggles ──
    function toggleEye(btnId, inpId) {
      const btn = $(btnId), inp = $(inpId);
      if (!btn || !inp) return;
      const isPw = inp.type === 'password';
      inp.type = isPw ? 'text' : 'password';
      // Switch icon
      if (isPw) {
        btn.innerHTML = '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M13.875 18.825A10.05 10.05 0 0 1 12 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 0 1 1.563-3.029m5.858.908a3 3 0 1 1 4.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/></svg>';
      } else {
        btn.innerHTML = '<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M2 12s3-7 10-7 10 7 10 7-3 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>';
      }
    }
    $('toggleNew').addEventListener('click',     () => toggleEye('toggleNew', 'pwNew'));
    $('toggleConfirm').addEventListener('click', () => toggleEye('toggleConfirm', 'pwConfirm'));
    $('toggleReauthPw').addEventListener('click',() => toggleEye('toggleReauthPw', 'reauthPw'));
    $('toggleGPw').addEventListener('click',     () => toggleEye('toggleGPw', 'gPw'));

    $('gPhone').addEventListener('input', () => {
      $('gPhone').value = $('gPhone').value.replace(/\D/g, '');
    });

    // ── Password strength ──
    const pwNew     = $('pwNew');
    const pwConfirm = $('pwConfirm');
    const btnChPw   = $('btnChangePw');
    const rules     = { len:$('rLen'),up:$('rUp'),low:$('rLow'),num:$('rNum'),spc:$('rSpc'),match:$('rMatch') };
    const fillBar   = $('strengthFill');
    const fillText  = $('strengthText');

    let pwTimer = null;

    function checkPw() {
      const np = pwNew.value, cp = pwConfirm.value;
      const ok = {
        len:   np.length >= 8,
        up:    /[A-Z]/.test(np),
        low:   /[a-z]/.test(np),
        num:   /[0-9]/.test(np),
        spc:   /[^A-Za-z0-9]/.test(np),
        match: np !== '' && np === cp,
      };
      console.log('Password check:', ok);
      Object.keys(ok).forEach(k => {
        if (rules[k]) {
          rules[k].classList.toggle('ok', ok[k]);
          rules[k].classList.toggle('bad', !ok[k]);
        }
      });

      const score = [ok.len,ok.up,ok.low,ok.num,ok.spc].filter(Boolean).length;
      const palette = ['','#ef4444','#f59e0b','#3b82f6','#84cc16','#059669'];
      const labels  = [
        ['',''],
        ['Very Weak','color:#ef4444'],
        ['Weak','color:#f59e0b'],
        ['Fair','color:#3b82f6'],
        ['Good','color:#84cc16'],
        ['Strong','color:#059669']
      ];

      if (np) {
        fillBar.style.width = (score / 5 * 100) + '%';
        fillBar.style.background = palette[score] || '#cbd5e1';
        fillText.textContent = labels[score] ? labels[score][0] : '';
        fillText.style.cssText = labels[score] ? labels[score][1] : '';
      } else {
        fillBar.style.width = '0%';
        fillText.textContent = '';
      }

      const allOk = ok.len && ok.up && ok.low && ok.num && ok.spc && ok.match;
      console.log('Password Strength Policy:', ok, 'All requirements met:', allOk);
      
      btnChPw.disabled = !allOk;
    }

    pwNew.addEventListener('input',     () => { clearTimeout(pwTimer); pwTimer = setTimeout(checkPw, 220); });
    pwConfirm.addEventListener('input', () => { clearTimeout(pwTimer); pwTimer = setTimeout(checkPw, 220); });

    // ── Change PW OTP flow ──
    let pendingPw = null;

    btnChPw.addEventListener('click', async () => {
      const np = pwNew.value, cp = pwConfirm.value;
      if (np === '' || cp === '') { toast('Please fill in both password fields.', 'error'); return; }
      if (np !== cp) { toast('Passwords do not match.', 'error'); return; }
      if (np.length < 8) { toast('Password must be at least 8 characters.', 'error'); return; }

      const btn = btnChPw;
      btn.disabled = true;
      const oldHtml = btn.innerHTML;
      btn.textContent = 'Sending OTP…';

      pendingPw = { new_password: np, confirm_password: cp };
      profileAction = 'change_password';
      const { ok, data } = await postForm('send_otp_mail.php', { action:'change_password' });
      
      btn.disabled = false;
      btn.innerHTML = oldHtml;

      if (!ok || !data?.success) {
        toast(data?.message || 'Failed to send OTP.', 'error');
        pendingPw = null; return;
      }
      startOtpCooldown(180);
      $('otpInput').value = '';
      hideAlert($('otpMsg'));
      showModal('#modalOtp');
    });

    $('btnVerifyOtp').addEventListener('click', async () => {
      const btn = $('btnVerifyOtp');
      const otp = $('otpInput').value.trim();
      hideAlert($('otpMsg'));

      if (!otp) { showAlert($('otpMsg'), 'Please enter the verification code.'); return; }

      if (profileAction === 'change_password') {
        if (!pendingPw) { 
          showAlert($('otpMsg'), 'No pending password change. Please try clicking "Change Password" again.'); 
          return; 
        }
        
        btn.disabled = true;
        btn.textContent = 'Verifying…';

        const vr = await postForm('verify_otp.php', { action:'change_password', otp });
        if (!vr.ok || !vr.data?.success) { 
          btn.disabled = false;
          btn.textContent = 'Verify & Update';
          showAlert($('otpMsg'), vr.data?.message || 'Invalid OTP.'); 
          return; 
        }
        
        const { ok, data } = await postJSON('AJAX/profile_change_password.php', pendingPw);
        if (ok && data?.ok) {
          hideModal('#modalOtp');
          pendingPw = null;
          $('pwNew').value = ''; $('pwConfirm').value = '';
          checkPw();
          
          $('successTitle').textContent = 'Password Updated!';
          $('successMsg').textContent = 'Your administrative password has been changed successfully.';
          showModal('#modalSuccess');
        } else {
          showAlert($('otpMsg'), data?.message || 'Failed to change password.');
        }
        btn.disabled = false;
        btn.textContent = 'Verify & Update';
      } 
      else if (profileAction === 'change_email') {
        const vr = await postForm('verify_otp.php', { action:'change_email', otp });
        if (!vr.ok || !vr.data?.success) { showAlert($('otpMsg'), vr.data?.message || 'Invalid OTP.'); return; }

        const { ok, data } = await postJSON('AJAX/profile_save.php', {
          full_name: $('inpFullName').value,
          email:     $('inpEmail').value,
        });
        if (ok && data?.ok) {
          hideModal('#modalOtp');
          initialEmail = $('inpEmail').value;
          
          $('successTitle').textContent = 'Profile Updated!';
          $('successMsg').textContent = 'Your profile information and email have been saved.';
          showModal('#modalSuccess');
        } else {
          showAlert($('otpMsg'), data?.message || 'Failed to update profile.');
        }
      }
    });
    $('otpInput').addEventListener('keydown', e => { if (e.key === 'Enter') $('btnVerifyOtp').click(); });

    // ── Guard Management ──
    let pendingDeleteId = null;

    const guardPanel = $('guardPanel');
    const btnToggleGuards = $('btnToggleGuards');
    function setGuardPanel(open) {
      guardPanel.classList.toggle('hidden', !open);
      btnToggleGuards.classList.toggle('collapsed', !open);
      btnToggleGuards.setAttribute('aria-expanded', open ? 'true' : 'false');
    }
    btnToggleGuards.addEventListener('click', () => {
      const isOpen = btnToggleGuards.getAttribute('aria-expanded') === 'true';
      setGuardPanel(!isOpen);
    });

    async function loadGuards() {
      try {
        const res = await fetch('profile.php?action=list_guards', { method:'POST', cache:'no-store' });
        const json = await res.json().catch(() => ({ guards:[] }));
        renderGuards(json.guards || []);
      } catch(e) {
        renderGuards([]);
      }
    }

    function esc(s) {
      return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
    }

    function renderGuards(guards) {
      const tb = $('guardsBody');
      if (!guards.length) {
        tb.innerHTML = `<tr><td colspan="4"><div class="guard-empty">
          <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
          <p>No guards yet. Click "Add Guard" to create the first one.</p>
        </div></td></tr>`;
        return;
      }
      tb.innerHTML = guards.map(g => `
        <tr>
          <td>
            <div class="g-name">${esc(g.full_name)}</div>
            <div class="g-user">@${esc(g.username || '')}</div>
          </td>
          <td class="g-phone">${esc(g.phone || '—')}</td>
          <td><span class="pill ${g.status === 'active' ? 'pill-active' : 'pill-inactive'}">${g.status === 'active' ? 'Active' : 'Inactive'}</span></td>
          <td style="text-align:right;">
            <button class="btn btn-sm btn-danger" onclick="openDelete(${Number(g.id)}, '${esc(g.full_name)}')">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:13px;height:13px;"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>
              Delete
            </button>
          </td>
        </tr>
      `).join('');
    }

    // Open create modal
    $('btnAddGuard').addEventListener('click', () => {
      ['gName','gUser','gPhone','gPw','gPwConfirm'].forEach(id => $(id).value = '');
      hideAlert($('createMsg'));
      showModal('#modalCreate');
    });

    // Create guard
    $('btnCreate').addEventListener('click', async () => {
      const name    = $('gName').value.trim();
      const user    = $('gUser').value.trim().toLowerCase();
      const phone   = $('gPhone').value.trim();
      const pw      = $('gPw').value;
      const pwc     = $('gPwConfirm').value;
      const msgEl   = $('createMsg');
      hideAlert(msgEl);

      if (!name)  { showAlert(msgEl, 'Full name is required.');       return; }
      if (!user)  { showAlert(msgEl, 'Username is required.');         return; }
      if (!/^[a-z0-9._-]{3,50}$/.test(user)) {
        showAlert(msgEl, 'Username: 3–50 chars, letters/numbers/._- only.'); return;
      }
      if (!phone) { showAlert(msgEl, 'Phone number is required.');    return; }
      if (!/^\d{7,25}$/.test(phone)) { showAlert(msgEl, 'Phone number must contain numbers only.'); return; }
      if (!pw)    { showAlert(msgEl, 'Password is required.');         return; }
      if (pw.length < 8) { showAlert(msgEl, 'Password must be at least 8 characters.'); return; }
      if (pw !== pwc)    { showAlert(msgEl, 'Passwords do not match.');               return; }

      const btn = $('btnCreate');
      btn.disabled = true;
      btn.textContent = 'Creating…';

      const { ok, data } = await postJSON('profile.php?action=create_guard', {
        full_name: name,
        username:  user,
        phone:     phone,
        password:  pw
      });

      btn.disabled = false;
      btn.innerHTML = `<svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="width:14px;height:14px;"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg> Create Guard`;

      if (ok && data?.ok) {
        hideModal('#modalCreate');
        loadGuards();
        toast(`Guard "${name}" created.`, 'success');
      } else {
        showAlert(msgEl, data?.message || 'Failed to create guard. Check if username already exists.');
      }
    });

    // Delete guard
    window.openDelete = function(id, name) {
      pendingDeleteId = id;
      $('delGuardSub').textContent = `"${name}" will be permanently removed.`;
      hideAlert($('deleteMsg'));
      showModal('#modalDelete');
    };

    $('btnConfirmDelete').addEventListener('click', async () => {
      if (!pendingDeleteId) return;
      hideAlert($('deleteMsg'));
      const { ok, data } = await postJSON('profile.php?action=delete_guard', { guard_id: pendingDeleteId });
      if (ok && data?.ok) {
        hideModal('#modalDelete');
        pendingDeleteId = null;
        loadGuards();
        toast('Guard deleted.', 'success');
      } else {
        showAlert($('deleteMsg'), data?.message || 'Failed to delete guard.');
      }
    });

    loadGuards();
    checkPw();
  })();
  </script>
</body>
</html>

