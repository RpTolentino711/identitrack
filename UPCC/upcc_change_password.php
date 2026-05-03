<?php
session_start();
require_once __DIR__ . '/../database/database.php';

if (!isset($_SESSION['upcc_authenticated']) || !upcc_current()) {
    header('Location: upccpanel.php');
    exit;
}

$user = upcc_current();
$upccId = (int)($user['upcc_id'] ?? 0);

try {
    $col = db_one("SHOW COLUMNS FROM upcc_user LIKE 'must_change_password'");
    if (!$col) {
        db_exec("ALTER TABLE upcc_user ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 1");
    }
} catch (Exception $e) {
    error_log('UPCC must_change_password migration failed: ' . $e->getMessage());
}

$current = db_one("SELECT must_change_password, password_hash FROM upcc_user WHERE upcc_id = :id", [':id' => $upccId]);
if (!$current) {
    header('Location: upccpanel.php?action=logout');
    exit;
}

if ((int)($current['must_change_password'] ?? 0) === 0 && ($_GET['force'] ?? '') !== '1') {
    header('Location: upccdashboard.php');
    exit;
}

$error = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $currentPw = trim((string)($_POST['current_password'] ?? ''));
    $newPw = trim((string)($_POST['new_password'] ?? ''));
    $confirmPw = trim((string)($_POST['confirm_password'] ?? ''));

    if ($currentPw === '' || $newPw === '' || $confirmPw === '') {
        $error = 'All fields are required.';
    } elseif ($newPw !== $confirmPw) {
        $error = 'New passwords do not match.';
    } elseif (strlen($newPw) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif (!upcc_verify_password($upccId, $currentPw)) {
        $error = 'Current password is incorrect.';
    } elseif ($currentPw === $newPw) {
        $error = 'New password must be different from current password.';
    } else {
        $newHash = password_hash($newPw, PASSWORD_DEFAULT);
        db_exec(
            "UPDATE upcc_user
             SET password_hash = :hash,
                 must_change_password = 0,
                 updated_at = NOW()
             WHERE upcc_id = :id",
            [':hash' => $newHash, ':id' => $upccId]
        );
        $ok = 'Password updated successfully.';
    }

    if ($ok !== '') {
        header('Location: upccdashboard.php');
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>UPCC Change Password</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Arial, sans-serif;
            background: #0b1630;
            color: #e8ecf7;
            display: grid;
            place-items: center;
            padding: 20px;
        }
        .card {
            width: 100%;
            max-width: 460px;
            background: #16244a;
            border: 1px solid rgba(255,255,255,.1);
            border-radius: 14px;
            padding: 22px;
        }
        h1 { margin: 0; font-size: 22px; }
        .sub { margin-top: 6px; color: #aab8d8; font-size: 13px; }
        .alert { margin-top: 14px; padding: 10px 12px; border-radius: 8px; font-size: 13px; }
        .err { background: rgba(255,91,91,.12); border: 1px solid rgba(255,91,91,.35); color: #ff9e9e; }
        .ok { background: rgba(74,222,128,.12); border: 1px solid rgba(74,222,128,.35); color: #93f8b4; }
        label { display: block; margin: 14px 0 6px; font-size: 12px; color: #c7d2fe; }
        input {
            width: 100%;
            padding: 11px 12px;
            border-radius: 8px;
            border: 1px solid rgba(255,255,255,.15);
            background: #111d3a;
            color: #fff;
            font-size: 14px;
        }
        .actions { margin-top: 18px; display: flex; gap: 10px; }
        .btn {
            border: 0;
            border-radius: 8px;
            padding: 10px 14px;
            font-weight: 700;
            cursor: pointer;
        }
        .btn-primary { background: #4f7bff; color: #fff; flex: 1; }
        .btn-logout { background: #2f3d68; color: #c7d2fe; text-decoration: none; display: inline-flex; align-items: center; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Change Password</h1>
        <div class="sub">First login security policy: you must set a new password before entering the UPCC dashboard.</div>

        <?php if ($error !== ''): ?><div class="alert err"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
        <?php if ($ok !== ''): ?><div class="alert ok"><?php echo htmlspecialchars($ok); ?></div><?php endif; ?>

        <form method="post" action="upcc_change_password.php" autocomplete="off">
            <label for="current_password">Current Password</label>
            <input id="current_password" type="password" name="current_password" required>

            <label for="new_password">New Password</label>
            <input id="new_password" type="password" name="new_password" minlength="8" required>

            <label for="confirm_password">Confirm New Password</label>
            <input id="confirm_password" type="password" name="confirm_password" minlength="8" required>

            <div class="actions">
                <button class="btn btn-primary" type="submit">Update Password</button>
                <a class="btn btn-logout" href="upccpanel.php?action=logout">Sign Out</a>
            </div>
        </form>
    </div>
</body>
</html>

