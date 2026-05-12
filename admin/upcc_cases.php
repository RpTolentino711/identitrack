<?php
// File: admin/upcc_cases.php
require_once __DIR__ . '/../database/database.php';
require_admin();
ensure_hearing_workflow_schema();

$admin = admin_current();
$activeSidebar = 'upcc';

function dept_norm(string $value): string {
    return preg_replace('/[^a-z0-9]+/i', '', strtolower(trim($value)));
}

function panel_bias_conflict(string $studentProgram, string $studentSchool, string $deptName): bool {
    $d = dept_norm($deptName);
    if ($d === '') return false;
    $tokens = [dept_norm($studentProgram), dept_norm($studentSchool)];
    foreach ($tokens as $t) {
        if ($t === '') continue;
        if ($d === $t || str_contains($t, $d) || str_contains($d, $t)) return true;
    }
    return false;
}

function sync_case_panel_members(int $caseId, array $panelIds): void {
    db_exec("DELETE FROM upcc_case_panel_member WHERE case_id = :case_id", [':case_id' => $caseId]);
    $seen = [];
    foreach ($panelIds as $pid) {
        $pid = (int)$pid;
        if ($pid <= 0 || isset($seen[$pid])) {
            continue;
        }
        $seen[$pid] = true;
        db_exec(
            "INSERT INTO upcc_case_panel_member (case_id, upcc_id, assigned_at) VALUES (:case_id, :upcc_id, NOW())",
            [':case_id' => $caseId, ':upcc_id' => $pid]
        );
    }
}
function panel_members_match_department(int $deptId, array $panelIds): bool {
    $panelIds = array_values(array_unique(array_filter(array_map('intval', $panelIds), static fn($id) => $id > 0)));
    if (empty($panelIds)) {
        return true;
    }

    $allowedRows = db_all(
        "SELECT upcc_id FROM upcc_user WHERE department_id = :dept AND is_active = 1",
        [':dept' => $deptId]
    );
    $allowedIds = array_fill_keys(array_map(static fn($row) => (int)$row['upcc_id'], $allowedRows), true);

    foreach ($panelIds as $panelId) {
        if (!isset($allowedIds[$panelId])) {
            return false;
        }
    }

    return true;
}

// ── Auto‑migration ──────────────────────────────────────────
try {
    db_exec("CREATE TABLE IF NOT EXISTS `departments` (
        `dept_id` int(11) NOT NULL AUTO_INCREMENT,
        `dept_name` varchar(100) NOT NULL,
        `is_active` tinyint(1) NOT NULL DEFAULT 1,
        `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
        `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
        PRIMARY KEY (`dept_id`),
        UNIQUE KEY `dept_name` (`dept_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_exec("INSERT INTO `departments` (`dept_name`) VALUES ('SABM') ON DUPLICATE KEY UPDATE dept_name='SABM'");

    $col = db_one("SHOW COLUMNS FROM `upcc_user` LIKE 'department_id'");
    if (!$col) {
        db_exec("ALTER TABLE `upcc_user` ADD COLUMN `department_id` int(11) DEFAULT NULL");
        db_exec("ALTER TABLE `upcc_user` ADD CONSTRAINT `fk_upcc_user_department` FOREIGN KEY (`department_id`) REFERENCES `departments` (`dept_id`) ON DELETE SET NULL");
    }

    // Migrate case status enum to support explicit workflow states
    $statusCol = db_one("SHOW COLUMNS FROM `upcc_case` LIKE 'status'");
    $statusType = strtolower((string)($statusCol['Type'] ?? ''));
    if (strpos($statusType, 'under_investigation') === false || strpos($statusType, 'closed') === false) {
        db_exec("ALTER TABLE `upcc_case`
                 MODIFY COLUMN `status` ENUM('PENDING','UNDER_INVESTIGATION','RESOLVED','CLOSED','UNDER_APPEAL','CANCELLED')
                 NOT NULL DEFAULT 'PENDING'");
    }

    // Normalize old resolved rows into CLOSED while keeping enum backward compatible.
    db_exec("UPDATE `upcc_case` SET `status` = 'CLOSED' WHERE `status` = 'RESOLVED'");

    db_exec("CREATE TABLE IF NOT EXISTS `upcc_case_panel_member` (
        `case_id` bigint(20) NOT NULL,
        `upcc_id` int(11) NOT NULL,
        `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
        PRIMARY KEY (`case_id`, `upcc_id`),
        KEY `idx_upcc_panel_member` (`upcc_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $panelMapCount = (int)(db_one("SELECT COUNT(*) AS c FROM upcc_case_panel_member")['c'] ?? 0);
    if ($panelMapCount === 0) {
        $legacyCases = db_all("SELECT case_id, assigned_panel_members FROM upcc_case WHERE COALESCE(assigned_panel_members, '') <> ''");
        foreach ($legacyCases as $legacy) {
            $caseId = (int)($legacy['case_id'] ?? 0);
            if ($caseId <= 0) {
                continue;
            }
            $decoded = json_decode((string)$legacy['assigned_panel_members'], true);
            if (!is_array($decoded)) {
                continue;
            }
            sync_case_panel_members($caseId, $decoded);
        }
    }
} catch (Exception $e) {
    error_log("UPCC migration error: " . $e->getMessage());
}

// ── Statistics ──────────────────────────────────────────────
$totalCases    = (int)(db_one("SELECT COUNT(*) AS c FROM upcc_case")['c'] ?? 0);
$pendingCases  = (int)(db_one("SELECT COUNT(*) AS c FROM upcc_case WHERE status='PENDING'")['c'] ?? 0);
$resolvedCases = (int)(db_one("SELECT COUNT(*) AS c FROM upcc_case WHERE status IN ('CLOSED','RESOLVED')")['c'] ?? 0);
$appealCases   = (int)(db_one("SELECT COUNT(*) AS c FROM upcc_case WHERE status='UNDER_APPEAL'")['c'] ?? 0);

// ── All cases — now includes case_summary AND consensus category ───────────────────
$cases = db_all("SELECT
        uc.case_id,
        uc.status,
        uc.created_at,
        uc.resolution_date,
        uc.final_decision,
        uc.decided_category,
        uc.assigned_department_id,
        uc.assigned_panel_members,
        uc.case_kind,
        uc.case_summary,
        uc.hearing_vote_consensus_category,
        uc.hearing_date,
        uc.hearing_time,
        uc.hearing_is_open,
        uc.hearing_is_paused,
        (SELECT MAX(p.last_ping) FROM upcc_hearing_presence p WHERE p.case_id = uc.case_id AND p.user_type = 'ADMIN') as admin_last_ping,
        s.student_id,
        CONCAT(s.student_fn,' ',s.student_ln) AS student_name,
        GROUP_CONCAT(ot.name ORDER BY ot.offense_type_id SEPARATOR ' | ') AS offense_names,
        MAX(ot.level) AS offense_level,
        MAX(ot.major_category) AS major_category,
        DATEDIFF(NOW(), uc.created_at) AS days_pending
    FROM upcc_case uc
    JOIN student s ON s.student_id = uc.student_id
    LEFT JOIN upcc_case_offense uco ON uco.case_id = uc.case_id
    LEFT JOIN offense o ON o.offense_id = uco.offense_id
    LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
    GROUP BY uc.case_id
    ORDER BY uc.created_at DESC
");

// ── Members with department info ────────────────────────────
$members = db_all("SELECT u.upcc_id, u.full_name, u.role, u.email, u.photo_path, u.is_active, u.department_id, d.dept_name
                   FROM upcc_user u
                   LEFT JOIN departments d ON d.dept_id = u.department_id
                   ORDER BY u.upcc_id ASC");

$membersAssignedDept = array_values(array_filter($members, static function ($m) {
    return !empty($m['department_id']);
}));
$membersNoDept = array_values(array_filter($members, static function ($m) {
    return empty($m['department_id']);
}));

$deptMemberCounts = [];
foreach ($members as $m) {
    if (!empty($m['department_id'])) {
        $deptId = (int)$m['department_id'];
        if (!isset($deptMemberCounts[$deptId])) {
            $deptMemberCounts[$deptId] = 0;
        }
        $deptMemberCounts[$deptId]++;
    }
}

// ── All departments ─────────────────────────────────────────
$departments = db_all("SELECT dept_id, dept_name, is_active FROM departments ORDER BY dept_name ASC");

// ── Handle POST actions ─────────────────────────────────────
$regError   = '';
$regSuccess = '';
$lastAction = '';
$autoOpenDepartmentId = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $lastAction = (string)$_POST['action'];

    if ($_POST['action'] === 'add_dept') {
        $name = trim($_POST['dept_name'] ?? '');
        if ($name) {
            try {
                db_exec("INSERT INTO departments (dept_name) VALUES (:n)", [':n' => $name]);
                $autoOpenDepartmentId = (int)db_last_id();
                $regSuccess = 'dept_added';
            }
            catch (Exception $e) { $regError = 'Department already exists.'; }
        } else { $regError = 'Name required.'; }
        $departments = db_all("SELECT dept_id, dept_name, is_active FROM departments ORDER BY dept_name ASC");
    }

    if ($_POST['action'] === 'edit_dept') {
        $id = (int)($_POST['dept_id'] ?? 0); $name = trim($_POST['dept_name'] ?? '');
        if ($id && $name) {
            try {
                db_exec("UPDATE departments SET dept_name = :n WHERE dept_id = :id", [':n' => $name, ':id' => $id]);
                $autoOpenDepartmentId = $id;
                $regSuccess = 'dept_updated';
            }
            catch (Exception $e) { $regError = 'Name conflict.'; }
        }
        $departments = db_all("SELECT dept_id, dept_name, is_active FROM departments ORDER BY dept_name ASC");
    }

    if ($_POST['action'] === 'toggle_dept') {
        $id = (int)($_POST['dept_id'] ?? 0); $newActive = (int)($_POST['new_active'] ?? 0);
        if ($id && $id != 1) { db_exec("UPDATE departments SET is_active = :a WHERE dept_id = :id", [':a' => $newActive, ':id' => $id]); $regSuccess = 'dept_toggled'; }
        else { $regError = 'Cannot deactivate SABM.'; }
        $departments = db_all("SELECT dept_id, dept_name, is_active FROM departments ORDER BY dept_name ASC");
    }

    if ($_POST['action'] === 'delete_dept') {
        $id = (int)($_POST['dept_id'] ?? 0);
        if ($id && $id != 1) {
            $used = db_one("SELECT COUNT(*) as cnt FROM upcc_user WHERE department_id = :id", [':id' => $id])['cnt'] ?? 0;
            $usedCases = db_one("SELECT COUNT(*) as cnt FROM upcc_case WHERE assigned_department_id = :id", [':id' => $id])['cnt'] ?? 0;
            if ($used == 0 && $usedCases == 0) { db_exec("DELETE FROM departments WHERE dept_id = :id", [':id' => $id]); $regSuccess = 'dept_deleted'; }
            else { $regError = 'Department is in use.'; }
        } else { $regError = 'Cannot delete SABM.'; }
        $departments = db_all("SELECT dept_id, dept_name, is_active FROM departments ORDER BY dept_name ASC");
    }

    if ($_POST['action'] === 'send_member_otp') {
        $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $_SESSION['upcc_member_otp']      = $otp;
        $_SESSION['upcc_member_otp_time'] = time();
        $_SESSION['upcc_member_pending']  = [
            'full_name'     => trim($_POST['full_name'] ?? ''),
            'username'      => trim($_POST['username']  ?? ''),
            'email'         => trim($_POST['email']     ?? ''),
            'role'          => trim($_POST['role']       ?? 'user'),
            'password'      => trim($_POST['password']   ?? ''),
            'department_id' => isset($_POST['department_id']) ? (int)$_POST['department_id'] : null,
        ];
        require_once __DIR__ . '/../UPCC/class.phpmailer.php';
        require_once __DIR__ . '/../UPCC/class.smtp.php';
        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8'; $mail->isSMTP();
            $mail->Host = 'smtp.gmail.com'; $mail->Port = 587; $mail->SMTPAuth = true; $mail->SMTPSecure = 'tls';
            $mail->Username = 'romeopaolotolentino@gmail.com'; $mail->Password = 'xhgg ajje ixak ajoj'; $mail->Timeout = 30;
            $mail->setFrom('romeopaolotolentino@gmail.com', 'IdentiTrack Admin');
            $mail->addAddress($admin['email'], $admin['full_name']);
            $mail->isHTML(true);
            $mail->Subject = 'OTP — Register New UPCC Member';
            $mail->Body = "<div style='font-family:sans-serif;max-width:480px;margin:auto;background:#0b1630;color:#e8ecf7;padding:32px;border-radius:16px;'>
                <div style='font-size:13px;color:#7a8aac;letter-spacing:2px;text-transform:uppercase;margin-bottom:16px;'>IdentiTrack Admin</div>
                <div style='font-size:20px;font-weight:bold;margin-bottom:8px;'>Register UPCC Member</div>
                <div style='font-size:13px;color:#7a8aac;margin-bottom:24px;'>Use this code to confirm registering <strong style='color:#e8ecf7'>" . htmlspecialchars($_SESSION['upcc_member_pending']['full_name']) . "</strong>.</div>
                <div style='background:#16244a;border-radius:12px;padding:20px;text-align:center;margin-bottom:20px;'>
                    <div style='font-size:36px;font-weight:900;letter-spacing:12px;color:#7c9fff;'>{$otp}</div>
                    <div style='font-size:12px;color:#7a8aac;margin-top:10px;'>Expires in <b>5 minutes</b></div>
                </div>
                <div style='font-size:12px;color:#7a8aac;'>If you did not initiate this, ignore this email.</div>
            </div>";
            $mail->AltBody = "Your OTP for registering a new UPCC member is: {$otp}. Expires in 5 minutes.";
            $mail->send(); $regSuccess = 'otp_sent';
        } catch (Exception $e) { $regError = 'Failed to send OTP: ' . $e->getMessage(); }
    }

    if ($_POST['action'] === 'verify_member_otp') {
        $submitted = trim($_POST['otp'] ?? '');
        $stored    = (string)($_SESSION['upcc_member_otp'] ?? '');
        $elapsed   = time() - (int)($_SESSION['upcc_member_otp_time'] ?? 0);
        $pending   = $_SESSION['upcc_member_pending'] ?? [];
        if ($elapsed > 300) { $regError = 'OTP expired.'; unset($_SESSION['upcc_member_otp'], $_SESSION['upcc_member_otp_time'], $_SESSION['upcc_member_pending']); }
        elseif ($submitted !== $stored) { $regError = 'Incorrect OTP.'; }
        elseif (empty($pending)) { $regError = 'Session lost.'; }
        else {
            $hash = password_hash($pending['password'], PASSWORD_DEFAULT);
            try {
                db_exec("INSERT INTO upcc_user (full_name, username, email, role, department_id, password_hash, is_active, must_change_password) VALUES (:fn, :u, :e, :r, :dept, :h, 1, 1)",
                    [':fn'=>$pending['full_name'],':u'=>$pending['username'],':e'=>$pending['email'],':r'=>$pending['role'],':dept'=>$pending['department_id'],':h'=>$hash]);
                unset($_SESSION['upcc_member_otp'], $_SESSION['upcc_member_otp_time'], $_SESSION['upcc_member_pending']);
                $regSuccess = 'created';
                $members = db_all("SELECT u.upcc_id, u.full_name, u.role, u.email, u.photo_path, u.is_active, u.department_id, d.dept_name
                                   FROM upcc_user u LEFT JOIN departments d ON d.dept_id = u.department_id ORDER BY u.upcc_id ASC");
            } catch (Exception $e) { $regError = 'Database error: ' . $e->getMessage(); }
        }
    }

    if ($_POST['action'] === 'update_member') {
        $uid  = (int)($_POST['upcc_id'] ?? 0); $fn = trim($_POST['full_name'] ?? '');
        $role = trim($_POST['role'] ?? '');     $email = trim($_POST['email'] ?? '');
        $dept_id = isset($_POST['department_id']) && $_POST['department_id'] !== '' ? (int)$_POST['department_id'] : null;
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        if ($uid && $fn && $role && $email) {
            db_exec("UPDATE upcc_user SET full_name=:fn, role=:r, email=:e, department_id=:dept, is_active=:active, updated_at=NOW() WHERE upcc_id=:id",
                [':fn'=>$fn,':r'=>$role,':e'=>$email,':dept'=>$dept_id,':active'=>$isActive,':id'=>$uid]);
            $regSuccess = 'updated';
            $members = db_all("SELECT u.upcc_id, u.full_name, u.role, u.email, u.photo_path, u.is_active, u.department_id, d.dept_name
                               FROM upcc_user u LEFT JOIN departments d ON d.dept_id = u.department_id ORDER BY u.upcc_id ASC");
        }
    }

    if ($_POST['action'] === 'set_member_department') {
        $uid = (int)($_POST['upcc_id'] ?? 0);
        $deptRaw = isset($_POST['department_id']) ? trim((string)$_POST['department_id']) : '';
        $deptId = ($deptRaw === '') ? null : (int)$deptRaw;

        if ($uid <= 0) {
            $regError = 'Please select a staff member.';
        } else {
            $memberExists = db_one("SELECT upcc_id FROM upcc_user WHERE upcc_id = :id", [':id' => $uid]);
            if (!$memberExists) {
                $regError = 'Selected staff member does not exist.';
            } else {
                // Backend safety check: Prevent adding to a department if already in one
                if ($deptId !== null) {
                    $currentDept = db_one("SELECT department_id FROM upcc_user WHERE upcc_id = :id", [':id' => $uid]);
                    if ($currentDept && $currentDept['department_id'] !== null && (int)$currentDept['department_id'] !== $deptId) {
                        $regError = 'Staff is already assigned to another department. Remove them first.';
                    }
                }

                if ($regError === '') {
                    if ($deptId !== null) {
                        $deptExists = db_one("SELECT dept_id, is_active FROM departments WHERE dept_id = :id", [':id' => $deptId]);
                        if (!$deptExists) {
                            $regError = 'Selected department does not exist.';
                        } elseif ((int)$deptExists['is_active'] !== 1) {
                            $regError = 'Cannot assign to an inactive department.';
                        }
                    }

                    if ($regError === '') {
                        db_exec("UPDATE upcc_user SET department_id = :dept, updated_at = NOW() WHERE upcc_id = :id", [
                            ':dept' => $deptId,
                            ':id' => $uid,
                        ]);
                        $regSuccess = $deptId === null ? 'member_department_removed' : 'member_department_updated';
                        if ($deptId !== null) {
                            $autoOpenDepartmentId = $deptId;
                        }
                        $members = db_all("SELECT u.upcc_id, u.full_name, u.role, u.email, u.photo_path, u.is_active, u.department_id, d.dept_name
                                           FROM upcc_user u
                                           LEFT JOIN departments d ON d.dept_id = u.department_id
                                           ORDER BY u.upcc_id ASC");
                        $membersAssignedDept = array_values(array_filter($members, static function ($m) {
                            return !empty($m['department_id']);
                        }));
                        $membersNoDept = array_values(array_filter($members, static function ($m) {
                            return empty($m['department_id']);
                        }));
                    }
                }
            }
        }
    }

    if ($_POST['action'] === 'toggle_member') {
        $uid = (int)($_POST['upcc_id'] ?? 0); $newVal = (int)($_POST['new_active'] ?? 0);
        if ($uid) {
            db_exec("UPDATE upcc_user SET is_active=:v, updated_at=NOW() WHERE upcc_id=:id", [':v'=>$newVal,':id'=>$uid]);
            $regSuccess = $newVal === 1 ? 'member_reactivated' : 'member_deactivated';
            $members = db_all("SELECT u.upcc_id, u.full_name, u.role, u.email, u.photo_path, u.is_active, u.department_id, d.dept_name
                               FROM upcc_user u LEFT JOIN departments d ON d.dept_id = u.department_id ORDER BY u.upcc_id ASC");
        }
    }

    if ($_POST['action'] === 'delete_member') {
        $uid = (int)($_POST['upcc_id'] ?? 0);
        if ($uid) {
            $member = db_one("SELECT upcc_id, is_active FROM upcc_user WHERE upcc_id = :id", [':id' => $uid]);
            if (!$member) {
                $regError = 'Member not found.';
            } elseif ((int)$member['is_active'] === 1) {
                $regError = 'Deactivate the member before hard deleting them.';
            } else {
                $refs = (int)(db_one("SELECT
                        (SELECT COUNT(*) FROM upcc_case WHERE created_by = :id) +
                        (SELECT COUNT(*) FROM offense WHERE recorded_by = :id) +
                        (SELECT COUNT(*) FROM community_service_requirement WHERE assigned_by = :id) +
                        (SELECT COUNT(*) FROM community_service_session WHERE validated_by = :id)
                    AS cnt", [':id' => $uid])['cnt'] ?? 0);
                if ($refs === 0) {
                    db_exec("DELETE FROM upcc_user WHERE upcc_id = :id", [':id' => $uid]);
                    $regSuccess = 'member_deleted';
                } else {
                    $regError = 'Member cannot be deleted because records still reference them.';
                }
            }
            $members = db_all("SELECT u.upcc_id, u.full_name, u.role, u.email, u.photo_path, u.is_active, u.department_id, d.dept_name
                               FROM upcc_user u LEFT JOIN departments d ON d.dept_id = u.department_id ORDER BY u.upcc_id ASC");
        }
    }

    // Quick assign from table detail pane
    if ($_POST['action'] === 'assign_case_panel') {
        $case_id = (int)($_POST['case_id'] ?? 0);
        $dept_id = (int)($_POST['assigned_department_id'] ?? 0);
        $panel   = isset($_POST['panel_members']) && is_array($_POST['panel_members']) ? $_POST['panel_members'] : [];
        $panelIds = array_values(array_unique(array_map('intval', $panel)));
        if ($case_id && $dept_id) {
            $ctx = db_one("SELECT s.program, s.school, d.dept_name
                           FROM upcc_case uc
                           JOIN student s ON s.student_id = uc.student_id
                           JOIN departments d ON d.dept_id = :dept
                           WHERE uc.case_id = :id", [':id' => $case_id, ':dept' => $dept_id]);
            if ($ctx && panel_bias_conflict((string)$ctx['program'], (string)$ctx['school'], (string)$ctx['dept_name'])) {
                $regError = 'Cannot assign a panel from the same department/program as the student.';
            } else {
            db_exec("UPDATE upcc_case
                     SET assigned_department_id = :dept,
                         assigned_panel_members = :panel,
                         status = 'UNDER_INVESTIGATION',
                         hearing_is_open = 0,
                         hearing_opened_at = NULL,
                         hearing_closed_at = NULL,
                         hearing_opened_by_admin = NULL,
                         hearing_vote_consensus_category = NULL,
                         hearing_vote_consensus_at = NULL,
                         updated_at = NOW()
                     WHERE case_id = :id",
                [':dept' => $dept_id, ':panel' => json_encode($panelIds), ':id' => $case_id]);
            sync_case_panel_members($case_id, $panelIds);
            db_exec("DELETE FROM upcc_case_vote_round WHERE case_id = :case_id", [':case_id' => $case_id]);
            db_exec("DELETE FROM upcc_case_vote WHERE case_id = :case_id", [':case_id' => $case_id]);
            upcc_log_case_activity($case_id, 'ADMIN', (int)$admin['admin_id'], 'PANEL_ASSIGNED', [
                'department_id' => $dept_id,
                'panel_members' => $panelIds,
            ]);

            // Notify panel members
            upcc_send_panel_assignment_email($case_id, $panelIds);

            header("Location: upcc_cases.php?msg=assigned"); exit;
            }
        }
    }

    if ($_POST['action'] === 'update_hearing_config') {
        $case_id = (int)($_POST['case_id'] ?? 0);
        $dept_id = isset($_POST['assigned_department_id']) && $_POST['assigned_department_id'] !== '' ? (int)$_POST['assigned_department_id'] : 0;
        $panel   = isset($_POST['panel_members']) && is_array($_POST['panel_members']) ? $_POST['panel_members'] : [];
        $panelIds = array_values(array_unique(array_map('intval', $panel)));
        $hearing_date = trim($_POST['hearing_date'] ?? '');
        $hearing_time = trim($_POST['hearing_time'] ?? '');
        $hearing_type = trim($_POST['hearing_type'] ?? '');

        if ($case_id) {
            if (empty($panelIds)) {
                $regError = 'Please assign at least one panel member.';
            } else if ($hearing_date === '' || $hearing_time === '') {
                $regError = 'Please select both a hearing date and time.';
            } else {
                $ctx = db_one("SELECT s.program, s.school, d.dept_name
                               FROM upcc_case uc
                           JOIN student s ON s.student_id = uc.student_id
                           JOIN departments d ON d.dept_id = :dept
                           WHERE uc.case_id = :id", [':id' => $case_id, ':dept' => $dept_id]);
            if ($ctx && panel_bias_conflict((string)$ctx['program'], (string)$ctx['school'], (string)$ctx['dept_name'])) {
                $regError = 'Cannot assign a panel from the same department/program as the student.';
            } else {
                db_exec("UPDATE upcc_case
                         SET assigned_department_id = :dept,
                             assigned_panel_members = :panel,
                             hearing_date = :hearing_date,
                             hearing_time = :hearing_time,
                             hearing_type = :hearing_type,
                             status = 'UNDER_INVESTIGATION',
                             hearing_is_open = 0,
                             hearing_opened_at = NULL,
                             hearing_closed_at = NULL,
                             hearing_opened_by_admin = NULL,
                             hearing_vote_consensus_category = NULL,
                             hearing_vote_consensus_at = NULL,
                             updated_at = NOW()
                         WHERE case_id = :id",
                    [
                        ':dept' => $dept_id > 0 ? $dept_id : null,
                            ':panel' => json_encode($panelIds),
                        ':hearing_date' => $hearing_date !== '' ? $hearing_date : null,
                        ':hearing_time' => $hearing_time !== '' ? $hearing_time : null,
                        ':hearing_type' => $hearing_type !== '' ? $hearing_type : null,
                        ':id' => $case_id,
                    ]);
                    sync_case_panel_members($case_id, $panelIds);
                db_exec("DELETE FROM upcc_case_vote_round WHERE case_id = :case_id", [':case_id' => $case_id]);
                db_exec("DELETE FROM upcc_case_vote WHERE case_id = :case_id", [':case_id' => $case_id]);
                upcc_log_case_activity($case_id, 'ADMIN', (int)$admin['admin_id'], 'HEARING_UPDATED', [
                    'department_id' => $dept_id,
                    'panel_members' => $panelIds,
                    'hearing_date' => $hearing_date,
                    'hearing_time' => $hearing_time,
                    'hearing_type' => $hearing_type,
                ]);

                // Notify panel members
                upcc_send_panel_assignment_email($case_id, $panelIds);

                header("Location: upcc_cases.php?msg=assigned&filter=assigned"); exit;
                }
            }
        } else {
            $regError = 'Case ID is missing.';
        }
    }

    // Quick resolve from table detail pane
    if ($_POST['action'] === 'resolve_case') {
        $case_id  = (int)($_POST['case_id'] ?? 0);
        $category = (int)($_POST['decided_category'] ?? 0);
        $decision = trim($_POST['final_decision'] ?? '');
        if ($case_id && $category >= 1 && $category <= 5 && $decision) {
            $caseRow = db_one("SELECT hearing_vote_consensus_category FROM upcc_case WHERE case_id = :id", [':id' => $case_id]);
            $consensusCategory = (int)($caseRow['hearing_vote_consensus_category'] ?? 0);
            if ($consensusCategory <= 0) {
                $regError = 'Cannot resolve yet. UPCC panel has no consensus category.';
            } elseif ($consensusCategory !== $category) {
                $regError = 'Selected category does not match UPCC consensus category.';
            } else {
                $probationUntil = null;
                if ($category === 1) {
                    $probationUntil = date('Y-m-d H:i:s', strtotime('+9 months'));
                }
                db_exec("UPDATE upcc_case
                         SET status='CLOSED',
                             hearing_is_open = 0,
                             hearing_closed_at = NOW(),
                             decided_category=:cat,
                             final_decision=:dec,
                             resolution_date=NOW(),
                             probation_until = :probation_until,
                             updated_at=NOW()
                         WHERE case_id=:id",
                    [':cat' => $category, ':dec' => $decision, ':probation_until' => $probationUntil, ':id' => $case_id]);
                upcc_log_case_activity($case_id, 'ADMIN', (int)$admin['admin_id'], 'FINAL_DECISION_RECORDED', [
                    'category' => $category,
                    'probation_until' => $probationUntil,
                ]);
                header("Location: upcc_cases.php?msg=resolved"); exit;
            }
        } else { $regError = 'Please select a category (1-5) and write a final decision.'; }
    }
}

$committeeActions = [
    'add_dept',
    'edit_dept',
    'toggle_dept',
    'delete_dept',
    'send_member_otp',
    'verify_member_otp',
    'update_member',
    'toggle_member',
    'delete_member',
    'set_member_department',
    'update_hearing_config',
];
$committeeActionTriggered = in_array($lastAction, $committeeActions, true);
$committeeFeedbackText = '';
if ($regSuccess !== '') {
    $committeeFeedbackMap = [
        'dept_added' => 'Department added successfully.',
        'dept_updated' => 'Department updated successfully.',
        'dept_toggled' => 'Department status updated.',
        'dept_deleted' => 'Department deleted successfully.',
        'otp_sent' => 'OTP sent successfully. Continue in the Add Member tab.',
        'created' => 'Committee member registered successfully.',
        'updated' => 'Member details updated successfully.',
        'member_reactivated' => 'Member reactivated successfully.',
        'member_deactivated' => 'Member deactivated successfully.',
        'member_deleted' => 'Inactive member deleted successfully.',
        'member_department_updated' => 'Member department updated successfully.',
        'member_department_removed' => 'Member removed from department successfully.',
    ];
    $committeeFeedbackText = $committeeFeedbackMap[$regSuccess] ?? 'Committee update saved successfully.';
}
$showCommitteeFeedbackModal = $committeeActionTriggered && (($regSuccess !== '' && $committeeFeedbackText !== '') || $regError !== '');
$committeeTargetTab = 'members';
if (in_array($lastAction, ['add_dept', 'edit_dept', 'toggle_dept', 'delete_dept', 'set_member_department'], true)) {
    $committeeTargetTab = 'departments';
}
if (in_array($lastAction, ['send_member_otp', 'verify_member_otp'], true)) {
    $committeeTargetTab = 'add';
}

function fmt_date(string $dt): string { return date('n/j/Y', strtotime($dt)); }
function fmt_case_id(int $id, string $created): string {
    return 'UPCC-' . date('Y', strtotime($created)) . '-' . str_pad((string)$id, 3, '0', STR_PAD_LEFT);
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>UPCC Cases | SDO Web Portal</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f8f9fa; color: #1b2244; }
        .admin-shell { min-height: calc(100vh - 72px); display: grid; grid-template-columns: 240px 1fr; }
        .wrap { min-height: 100%; padding: 0; }
        .page-hero { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 28px 32px; }
        .page-title { font-size: 26px; font-weight: 700; color: #1a1a1a; }
        .page-sub { margin-top: 4px; font-size: 14px; color: #6c757d; }
        .content-area { padding: 20px 28px 40px; }
        .stats-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 28px; }
        .stat-card { background: #fff; border: 1px solid #e0e8f5; border-radius: 14px; padding: 22px 22px 18px; position: relative; overflow: hidden; box-shadow: 0 2px 8px rgba(20,36,74,0.06); transition: transform 0.2s, box-shadow 0.2s; }
        .stat-card:hover { transform: translateY(-3px); box-shadow: 0 8px 20px rgba(20,36,74,0.10); }
        .stat-card::before { content: ''; position: absolute; left: 0; top: 0; bottom: 0; width: 4px; }
        .stat-card.blue::before { background: #1f7adb; }
        .stat-card.yellow::before { background: #f1c232; }
        .stat-card.green::before { background: #27ae60; }
        .stat-card.red::before { background: #e74c3c; }
        .stat-label { font-size: 12px; color: #888; text-transform: uppercase; letter-spacing: .8px; font-weight: 600; margin-bottom: 10px; }
        .stat-value { font-size: 2.4rem; font-weight: 800; line-height: 1; }
        .stat-card.blue .stat-value { color: #1f7adb; }
        .stat-card.yellow .stat-value { color: #d4a017; }
        .stat-card.green .stat-value { color: #27ae60; }
        .stat-card.red .stat-value { color: #e74c3c; }

        .committee-panel { background: linear-gradient(135deg, #1b2b6b 0%, #2a3f8f 60%, #1b2b6b 100%); border-radius: 16px; padding: 28px 28px 24px; margin-bottom: 28px; color: #fff; cursor: pointer; transition: opacity 0.15s, transform 0.15s; }
        .committee-panel:hover { opacity: .93; transform: translateY(-1px); }
        .committee-panel h2 { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
        .committee-panel .staff-dir-label { font-size: 12px; color: #f1c232; font-weight: 600; letter-spacing: .5px; text-transform: uppercase; margin-bottom: 18px; }
        .committee-members { display: flex; flex-wrap: wrap; gap: 20px; align-items: flex-end; }
        .member-chip { display: flex; flex-direction: column; align-items: center; gap: 8px; text-align: center; }
        .member-avatar { width: 48px; height: 48px; border-radius: 50%; background: #f1c232; color: #1b2244; font-weight: 800; font-size: 15px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .member-avatar img { width: 100%; height: 100%; object-fit: cover; }
        .member-name { font-size: 12px; font-weight: 600; color: #e8ecf7; line-height: 1.2; max-width: 90px; }
        .member-role { font-size: 11px; color: #aab8d8; }
        .panel-click-hint { margin-left: auto; align-self: center; font-size: 12px; color: #aab8d8; display: flex; align-items: center; gap: 6px; }
        .panel-click-hint svg { width: 16px; height: 16px; opacity: .7; }

        .section-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 14px; }
        .section-title { font-size: 17px; font-weight: 700; color: #1a1a1a; }
        .filter-tabs { display: flex; gap: 6px; flex-wrap: wrap; }
        .filter-tab { padding: 7px 16px; border-radius: 8px; border: 1px solid #d0d8ea; background: #fff; font-size: 13px; font-weight: 500; color: #555; cursor: pointer; transition: all 0.15s; }
        .filter-tab:hover { border-color: #1b2b6b; color: #1b2b6b; }
        .filter-tab.active { background: #1b2b6b; color: #fff; border-color: #1b2b6b; }
        .status-legend {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 8px;
            margin: -2px 0 14px;
        }
        .status-legend-label {
            font-size: 11px;
            font-weight: 700;
            color: #7a8aac;
            text-transform: uppercase;
            letter-spacing: .5px;
            margin-right: 4px;
        }
        .legend-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 10px;
            border-radius: 999px;
            border: 1px solid #dbe2f3;
            background: #fff;
            font-size: 11px;
            font-weight: 700;
            color: #31405f;
        }
        .legend-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }
        .legend-dot.awaiting { background: #d97706; }
        .legend-dot.investigating { background: #7c3aed; }
        .legend-dot.closed { background: #27ae60; }
        .legend-dot.appeal { background: #2980b9; }

        .cases-layout { display: grid; grid-template-columns: 1fr 380px; gap: 16px; align-items: start; }
        .cases-table-wrap { background: #fff; border: 1px solid #e0e8f5; border-radius: 14px; overflow: hidden; box-shadow: 0 2px 8px rgba(20,36,74,0.05); }
        table { width: 100%; border-collapse: collapse; font-size: 13px; }
        thead tr { background: #f4f6fb; }
        th { padding: 12px 14px; text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #888; border-bottom: 1px solid #e8eef5; }
        td { padding: 13px 14px; border-bottom: 1px solid #f0f3f8; vertical-align: middle; }
        tbody tr { cursor: pointer; transition: background 0.12s; }
        tbody tr:hover { background: #f7f9fd; }
        tbody tr.selected { background: #eef3ff; }
        .case-id { font-weight: 700; font-size: 12px; color: #1b2b6b; }
        .student-name { font-weight: 600; }
        .student-id { font-size: 11px; color: #999; margin-top: 1px; }
        .offense-name { max-width: 160px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; }
        .badge-pending { background: #fff3cd; color: #856404; }
        .badge-resolved { background: #d1f0e0; color: #155724; }
        .badge-appeal { background: #cfe2ff; color: #084298; }
        .badge-cancelled { background: #f8d7da; color: #842029; }
        .badge-investigating { background: #ede9fe; color: #5b21b6; }
        .badge-live { background: #d1fae5; color: #065f46; border: 1px solid #10b981; animation: pulse 2s infinite; }
        @keyframes pulse { 0% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0.4); } 70% { box-shadow: 0 0 0 6px rgba(16, 185, 129, 0); } 100% { box-shadow: 0 0 0 0 rgba(16, 185, 129, 0); } }
        .days-text { font-weight: 600; color: #444; }
        .days-text.warn { color: #e74c3c; }
        .days-sub { font-size: 11px; color: #e74c3c; }
        .category-badge { display: inline-block; padding: 3px 8px; border-radius: 12px; font-size: 11px; font-weight: 700; background: #eef2ff; color: #1b2b6b; }
        .category-badge.resolved { background: #d1f0e0; color: #155724; }
        .detail-pane { background: #fff; border: 1px solid #e0e8f5; border-radius: 14px; box-shadow: 0 2px 8px rgba(20,36,74,0.05); padding: 0; min-height: 200px; overflow: hidden; }
        .detail-empty { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 10px; min-height: 160px; color: #bbb; padding: 40px; }
        .detail-empty svg { width: 44px; height: 44px; opacity: .4; }
        .detail-content { display: none; }
        .detail-content.active { display: block; }
        .detail-head {
            background: linear-gradient(135deg, #0d1635, #1b2b6b);
            padding: 18px 20px 14px;
            color: #fff;
        }
        .detail-case-id { font-size: 11px; font-weight: 700; color: #7a8aac; letter-spacing: .6px; text-transform: uppercase; margin-bottom: 4px; }
        .detail-student { font-size: 17px; font-weight: 800; }
        .detail-sid { font-size: 12px; color: #aab8d8; margin-top: 2px; margin-bottom: 10px; }
        .detail-badges { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 10px; }
        .detail-badge { padding: 3px 10px; border-radius: 20px; font-size: 11px; font-weight: 700; border: 1px solid; }
        .db-pending      { background: rgba(212,172,13,0.2); border-color: #d4ac0d; color: #f1c232; }
        .db-resolved     { background: rgba(39,174,96,0.2); border-color: #27ae60; color: #6ee7b7; }
        .db-appeal       { background: rgba(41,128,185,0.2); border-color: #2980b9; color: #7dd3fc; }
        .db-investigating{ background: rgba(124,58,237,0.2); border-color: #7c3aed; color: #c4b5fd; }
        .db-awaiting     { background: rgba(217,119,6,0.2); border-color: #d97706; color: #fcd34d; }
        .detail-body { padding: 16px 20px 8px; }
        .detail-row { display: flex; justify-content: space-between; font-size: 13px; padding: 8px 0; border-bottom: 1px solid #f0f3f8; gap: 8px; }
        .detail-row:last-child { border-bottom: none; }
        .detail-row-label { color: #888; font-weight: 500; white-space: nowrap; flex-shrink: 0; }
        .detail-row-value { font-weight: 600; color: #1a1a1a; text-align: right; }
        .detail-summary {
            margin: 0 20px 14px;
            background: #f8faff;
            border: 1px solid #e0e8f5;
            border-radius: 9px;
            padding: 11px 14px;
            font-size: 12px;
            color: #374151;
            line-height: 1.6;
        }
        .detail-summary-label { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #9ca3af; margin-bottom: 5px; }
        .btn-view-case {
            display: block;
            margin: 0 20px 20px;
            padding: 10px;
            background: #1b2b6b;
            color: #fff;
            text-align: center;
            font-weight: 700;
            font-size: 13px;
            border-radius: 9px;
            text-decoration: none;
            transition: background 0.15s;
        }
        .btn-view-case:hover { background: #2a3f8f; }
        .detail-forms { padding: 0 20px 20px; }
        .assign-label, .decision-label { font-size: 12px; font-weight: 700; margin-bottom: 6px; color: #374151; }
        .assign-select, .decision-select { width: 100%; padding: 8px 12px; border: 1.5px solid #d0d8ea; border-radius: 8px; margin-bottom: 12px; font-size: 13px; background: #fafbff; }
        .panel-select-wrapper { border: 1px solid #d1d5db; border-radius: 9px; padding: 6px; background: #fafbff; min-height: 48px; display: flex; flex-direction: column; gap: 6px; }
        .selected-panel-members { display: flex; flex-wrap: wrap; gap: 6px; }
        .panel-chip { display: inline-flex; align-items: center; gap: 6px; background: #e2e9ff; color: #1b2b6b; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 14px; }
        .panel-chip-remove { cursor: pointer; color: #6b7a99; font-weight: bold; }
        .panel-chip-remove:hover { color: #d93025; }
        .panel-member-search { width: 100%; border: none; background: transparent; padding: 6px; font-size: 13px; outline: none; }
        .panel-member-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #e0e8f5; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.1); max-height: 200px; overflow-y: auto; z-index: 1000; display: none; }
        .panel-member-dropdown.show { display: block; }
        .dropdown-item { padding: 8px 12px; font-size: 12px; cursor: pointer; display: flex; flex-direction: column; border-bottom: 1px solid #f0f3f8; }
        .dropdown-item:last-child { border-bottom: none; }
        .dropdown-item:hover { background: #f4f6fb; }
        .dropdown-item-title { font-weight: 700; color: #1a1a1a; display: flex; justify-content: space-between; }
        .dropdown-item-sub { font-size: 11px; color: #7a8aac; }
        .btn-assign { background: #27ae60; color: white; border: none; padding: 9px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px; width: 100%; }
        .btn-resolve { background: #1b2b6b; color: white; border: none; padding: 9px 16px; border-radius: 8px; font-weight: 600; cursor: pointer; font-size: 13px; width: 100%; }
        .btn-resolve:hover { background: #2a3f8f; }
        .decision-textarea { width: 100%; padding: 8px; border: 1.5px solid #d0d8ea; border-radius: 8px; font-family: inherit; margin-bottom: 12px; font-size: 13px; }
        .divider-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .6px; color: #9ca3af; margin-bottom: 10px; border-top: 1px solid #e8eef7; padding-top: 14px; }
        .modal-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center; }
        .modal-overlay.open { display: flex; }
        .modal { background: #fff; border-radius: 18px; padding: 32px 28px 28px; width: 100%; max-width: 440px; position: relative; animation: modalIn 0.25s ease; }
        @keyframes modalIn { from { opacity: 0; transform: translateY(20px) scale(.97); } to { opacity: 1; transform: translateY(0) scale(1); } }
        .modal-title { font-size: 18px; font-weight: 700; margin-bottom: 4px; }
        .modal-sub { font-size: 13px; color: #888; margin-bottom: 22px; }
        .modal-close { position: absolute; top: 16px; right: 16px; background: none; border: none; font-size: 20px; cursor: pointer; }
        .field-group { margin-bottom: 14px; }
        .field-group label { display: block; font-size: 12px; font-weight: 600; margin-bottom: 5px; }
        .field-group input, .field-group select { width: 100%; padding: 10px 12px; border: 1px solid #d0d8ea; border-radius: 9px; font-size: 14px; background: #fafbff; }
        .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .btn-primary { width: 100%; padding: 12px; background: linear-gradient(135deg, #1b2b6b, #2a3f8f); color: #fff; font-weight: 700; border: none; border-radius: 10px; cursor: pointer; }
        .alert-err { background: rgba(231,76,60,0.1); border: 1px solid rgba(231,76,60,0.3); color: #c0392b; border-radius: 9px; padding: 9px 13px; margin-bottom: 14px; font-size: 13px; }
        .alert-ok  { background: rgba(39,174,96,0.1); border: 1px solid rgba(39,174,96,0.3); color: #1e8449; border-radius: 9px; padding: 9px 13px; margin-bottom: 14px; font-size: 13px; }
        .otp-fields { display: flex; gap: 8px; margin-bottom: 10px; }
        .otp-digit { flex: 1; height: 52px; text-align: center; font-size: 22px; font-weight: 700; background: #f4f6fb; border: 1px solid #d0d8ea; border-radius: 9px; }
        .empty-cases { text-align: center; padding: 40px 20px; color: #aaa; }
        .cpanel-overlay { display: none; position: fixed; inset: 0; background: rgba(8,12,32,0.65); backdrop-filter: blur(5px); z-index: 1050; align-items: center; justify-content: center; padding: 20px; }
        .cpanel-overlay.open { display: flex; }
        .cpanel-modal { background: #fff; border-radius: 22px; width: 100%; max-width: 700px; max-height: 88vh; display: flex; flex-direction: column; overflow: hidden; }
        .cpanel-head { background: linear-gradient(135deg, #0d1635 0%, #1b2b6b 60%, #0d1635 100%); padding: 26px 30px 22px; position: relative; }
        .cpanel-head h2 { font-size: 19px; font-weight: 800; color: #fff; }
        .cpanel-head-close { position: absolute; top: 18px; right: 18px; width: 30px; height: 30px; border-radius: 50%; background: rgba(255,255,255,0.12); border: none; color: #fff; cursor: pointer; }
        .cpanel-stats { display: flex; gap: 22px; margin-top: 16px; }
        .cpstat .n { font-size: 20px; font-weight: 800; color: #f1c232; }
        .cpstat .l { font-size: 11px; color: #7a8aac; text-transform: uppercase; letter-spacing: .5px; }
        .cpanel-tabs { display: flex; gap: 2px; padding: 14px 30px 0; border-bottom: 1px solid #edf0f8; background: #fff; }
        .cptab { padding: 9px 16px; font-size: 13px; font-weight: 600; background: none; border: none; cursor: pointer; color: #888; border-bottom: 3px solid transparent; }
        .cptab.active { color: #1b2b6b; border-bottom-color: #1b2b6b; }
        .cpanel-body { overflow-y: auto; flex: 1; padding: 22px 30px 28px; }
        .cp-toolbar { display: flex; gap: 10px; margin-bottom: 18px; align-items: center; }
        .cp-search-wrap { flex: 1; position: relative; }
        .cp-search-wrap svg { position: absolute; left: 10px; top: 50%; transform: translateY(-50%); width: 15px; height: 15px; color: #bbb; }
        .cp-search-wrap input { width: 100%; padding: 9px 12px 9px 32px; border: 1.5px solid #e0e8f5; border-radius: 9px; font-size: 13px; background: #f8faff; }
        .btn-cp-add { display: inline-flex; align-items: center; gap: 6px; padding: 9px 16px; background: #1b2b6b; color: #fff; font-weight: 700; font-size: 13px; border: none; border-radius: 9px; cursor: pointer; }
        .cp-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; }
        .cp-card { border: 1.5px solid #e8eef7; border-radius: 14px; padding: 18px 14px 14px; background: #fff; display: flex; flex-direction: column; align-items: center; gap: 7px; text-align: center; position: relative; }
        .cp-card.inactive { border-color: #f1c6c6; background: linear-gradient(180deg, #ffffff 0%, #fffafb 100%); box-shadow: 0 2px 8px rgba(216,56,56,0.04); }
        .cp-av { width: 56px; height: 56px; border-radius: 50%; color: #fff; font-weight: 800; font-size: 17px; display: flex; align-items: center; justify-content: center; overflow: hidden; }
        .cp-av img { width: 100%; height: 100%; object-fit: cover; }
        .cp-name { font-size: 13px; font-weight: 700; color: #1a1a1a; }
        .cp-role-badge { font-size: 11px; font-weight: 700; padding: 2px 10px; border-radius: 20px; display: inline-block; }
        .rb-chair { background: #fff3cd; color: #856404; }
        .rb-vchair { background: #d1ecf1; color: #0c5460; }
        .rb-sec { background: #d4edda; color: #155724; }
        .rb-member { background: #e2e9ff; color: #1b2b6b; }
        .cp-email { font-size: 11px; color: #999; }
        .cp-status { font-size: 11px; font-weight: 600; }
        .cp-status.on { color: #27ae60; }
        .cp-status.off { color: #e74c3c; }
        .cp-actions { display: flex; gap: 5px; margin-top: 3px; }
        .btn-ic {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            border: 1px solid;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            background: #fff;
            box-shadow: 0 2px 6px rgba(26, 43, 107, 0.08);
            transition: transform 0.15s ease, box-shadow 0.15s ease, background 0.15s ease, border-color 0.15s ease;
        }
        .btn-ic:hover {
            transform: translateY(-1px);
            box-shadow: 0 6px 14px rgba(26, 43, 107, 0.14);
        }
        .btn-ic:active {
            transform: translateY(0);
            box-shadow: 0 2px 5px rgba(26, 43, 107, 0.10);
        }
        .btn-ic svg { width: 15px; height: 15px; }
        .btn-ic.edit { border-color: #9fb5e8; color: #1b2b6b; background: linear-gradient(180deg, #ffffff 0%, #f5f8ff 100%); }
        .btn-ic.edit:hover { border-color: #6f8fe0; background: linear-gradient(180deg, #ffffff 0%, #ecf3ff 100%); }
        .btn-ic.deact { border-color: #f0aeb7; color: #d83838; background: linear-gradient(180deg, #fff 0%, #fff5f6 100%); }
        .btn-ic.deact:hover { border-color: #e07d8a; background: linear-gradient(180deg, #fff 0%, #ffecef 100%); }
        .btn-ic.react { border-color: #b7e4c7; color: #27ae60; background: linear-gradient(180deg, #fff 0%, #f2fff7 100%); }
        .btn-ic.react:hover { border-color: #85d3a1; background: linear-gradient(180deg, #fff 0%, #e8fff0 100%); }
        .edit-strip, .confirm-strip { display: none; position: absolute; inset: 0; border-radius: 14px; background: rgba(255,255,255,0.98); flex-direction: column; align-items: stretch; justify-content: flex-start; gap: 10px; padding: 12px; z-index: 5; overflow: auto; box-shadow: inset 0 0 0 1px rgba(176,196,239,.22); }
        .edit-strip.show, .confirm-strip.show { display: flex; }
        .es-head { display: flex; align-items: center; gap: 10px; padding-bottom: 10px; border-bottom: 1px solid #e8eef7; }
        .es-avatar { width: 42px; height: 42px; border-radius: 12px; background: linear-gradient(135deg, #1b2b6b, #2a3f8f); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 17px; font-weight: 800; flex-shrink: 0; }
        .es-head-text { display: flex; flex-direction: column; gap: 2px; min-width: 0; text-align: left; }
        .es-title { font-size: 13px; font-weight: 800; color: #1b2244; line-height: 1.2; }
        .es-sub { font-size: 11px; color: #7b86a9; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .es-form { display: flex; flex-direction: column; gap: 10px; }
        .es-field label { display: block; font-size: 11px; font-weight: 800; color: #50607f; margin-bottom: 5px; text-transform: uppercase; letter-spacing: .5px; }
        .es-field input, .es-field select { width: 100%; padding: 9px 11px; border: 1px solid #d6def0; border-radius: 10px; background: #f8faff; font-size: 13px; }
        .es-active { display: flex; align-items: center; justify-content: space-between; gap: 10px; background: #f4f7ff; border: 1px solid #dbe4fb; border-radius: 10px; padding: 10px 12px; }
        .es-active label { margin: 0; font-size: 12px; font-weight: 700; color: #2b377f; text-transform: none; letter-spacing: 0; }
        .es-active small { display: block; margin-top: 2px; font-size: 11px; font-weight: 500; color: #6f7ca1; }
        .es-toggle { width: 18px; height: 18px; accent-color: #1b2b6b; }
        .es-btns, .confirm-strip-btns { display: flex; gap: 6px; width: 100%; }
        .es-save, .cs-yes { flex: 1; padding: 8px 10px; background: #1b2b6b; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-weight: 700; }
        .cs-delete { flex: 1; padding: 8px 10px; background: #d83838; color: #fff; border: none; border-radius: 8px; cursor: pointer; font-weight: 700; }
        .es-cancel, .cs-no { flex: 1; padding: 8px 10px; background: #eee; color: #333; border: none; border-radius: 8px; cursor: pointer; }

        .dept-card-btn {
            width: 100%;
            border: none;
            background: transparent;
            padding: 0;
            cursor: pointer;
            text-align: inherit;
        }
        .dept-card-btn:focus-visible {
            outline: 3px solid rgba(27, 43, 107, 0.25);
            outline-offset: 4px;
            border-radius: 14px;
        }
        .dept-card-btn .cp-card {
            transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
        }
        .dept-card-btn:hover .cp-card {
            transform: translateY(-2px);
            box-shadow: 0 10px 18px rgba(27, 43, 107, 0.10);
            border-color: #c9d6f4;
        }
        .dept-member-count {
            font-size: 12px;
            font-weight: 700;
            color: #1b2b6b;
            background: #eef3ff;
            border: 1px solid #d7e1fb;
            border-radius: 999px;
            padding: 4px 10px;
        }
        .dept-modal-list { display: flex; flex-direction: column; gap: 10px; margin-top: 12px; }
        .dept-member-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 12px;
            border: 1px solid #e0e8f5;
            border-radius: 12px;
            background: #f8faff;
        }
        .dept-member-meta { min-width: 0; }
        .dept-member-name { font-size: 13px; font-weight: 700; color: #1a1a1a; }
        .dept-member-sub { font-size: 11px; color: #7b86a9; margin-top: 2px; }
        .dept-modal-section { margin-top: 14px; }
        .dept-modal-section h3 { font-size: 12px; text-transform: uppercase; letter-spacing: .5px; color: #4f5b7d; margin-bottom: 8px; }
        .dept-inline-form { display: flex; gap: 8px; align-items: end; }
        .dept-inline-form .field-group { margin-bottom: 0; flex: 1; }
        .dept-inline-form button { height: 38px; }
    </style>
</head>
<body>
<?php require_once __DIR__ . '/header.php'; ?>

<div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="wrap">
        <section class="page-hero">
            <h1 class="page-title">UPCC Case Management</h1>
            <div class="page-sub">Welcome, <?= e($admin['full_name']) ?></div>
        </section>

        <div class="content-area">
            <?php if (isset($_GET['msg']) && $_GET['msg'] === 'resolved'): ?>
                <div class="alert-ok" style="margin-bottom:16px;">✓ Case resolved successfully.</div>
            <?php endif; ?>
            <?php if ($regError && $lastAction === 'update_hearing_config'): ?>
                <div class="alert-err" style="margin-bottom:16px;">❌ <?= htmlspecialchars($regError) ?></div>
            <?php endif; ?>

            <!-- Stat cards -->
            <div class="stats-row">
                <div class="stat-card blue"><div class="stat-label">Total Cases</div><div class="stat-value"><?= $totalCases ?></div></div>
                <div class="stat-card yellow"><div class="stat-label">Pending Cases</div><div class="stat-value"><?= $pendingCases ?></div></div>
                <div class="stat-card green"><div class="stat-label">Closed Cases</div><div class="stat-value"><?= $resolvedCases ?></div></div>
                <div class="stat-card red"><div class="stat-label">Under Appeal</div><div class="stat-value"><?= $appealCases ?></div></div>
            </div>

            <!-- Committee Panel Overview -->
            <div class="committee-panel" onclick="openCpanel()">
                <h2>Committee Panel Overview</h2>
                <div class="staff-dir-label">Staff Directory — click to manage</div>
                <div class="committee-members">
                    <?php foreach ($members as $m): if (!$m['is_active']) continue;
                        $initials = strtoupper(substr($m['full_name'],0,1) . (strpos($m['full_name'],' ')? substr(strrchr($m['full_name'],' '),1,1) : ''));
                        $photoSrc = (!empty($m['photo_path']) && file_exists(__DIR__ . '/' . $m['photo_path'])) ? htmlspecialchars($m['photo_path']) : null;
                    ?>
                        <div class="member-chip">
                            <div class="member-avatar"><?php if ($photoSrc): ?><img src="<?= $photoSrc ?>"><?php else: ?><?= e($initials) ?><?php endif; ?></div>
                            <div class="member-name"><?= e($m['full_name']) ?></div>
                            <div class="member-role"><?= e(ucfirst($m['role'])) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty(array_filter($members, fn($m)=>$m['is_active']))): ?><div style="color:#aab8d8;font-size:13px;">No active committee members.</div><?php endif; ?>
                    <div class="panel-click-hint"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>Manage members & departments</div>
                </div>
            </div>

            <!-- Cases table -->
            <div class="section-header">
                <div class="section-title">All Cases</div>
                <div class="filter-tabs">
                    <button class="filter-tab active" onclick="filterCases('all', this)">All</button>
                    <button class="filter-tab" onclick="filterCases('assigned', this)">Panel Assigned</button>
                    <button class="filter-tab" onclick="filterCases('unassigned', this)">Unassigned</button>
                </div>
            </div>


            <div class="cases-layout">
                <div class="cases-table-wrap">
                    <?php if (empty($cases)): ?><div class="empty-cases">No UPCC cases found.</div>
                    <?php else: ?>
                    <table id="cases-table">
                        <thead>
                            <tr>
                                <th>Case ID</th>
                                <th>Student</th>
                                <th>Offense</th>
                                <th>Category</th>
                                <th>Date Filed</th>
                                <th>Hearing</th>
                                <th>Status</th>
                                <th>Days</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($cases as $c):
                            $caseLabel = fmt_case_id((int)$c['case_id'], $c['created_at']);
                            $dateLabel = fmt_date($c['created_at']);
                            $summaryText = (string)($c['case_summary'] ?? '');
                            $isSection4 = (($c['case_kind'] ?? '') === 'SECTION4_MINOR_ESCALATION') || (stripos($summaryText, 'Section 4') !== false);

                            $inferredCategory = null;
                            if (preg_match('/Category\s*([1-5])/i', $summaryText, $mCat)) {
                                $inferredCategory = (int)$mCat[1];
                            }

                            $effectiveLevel = strtoupper((string)($c['offense_level'] ?? ''));
                            if ($isSection4) {
                                $effectiveLevel = 'MINOR';
                            } elseif ($effectiveLevel !== 'MINOR' && $effectiveLevel !== 'MAJOR') {
                                if ((($c['case_kind'] ?? '') === 'MAJOR_OFFENSE') || (stripos($summaryText, 'Major Offense') !== false)) {
                                    $effectiveLevel = 'MAJOR';
                                } else {
                                    $effectiveLevel = '';
                                }
                            }

                            $lvl = strtolower($effectiveLevel);
                            $days = (int)$c['days_pending'];
                            $isWarn = ($c['status'] === 'PENDING' && $days >= 7);
                            $hasPanel = !empty($c['assigned_department_id']);

                            $effectiveCategory = 0;
                            if (!empty($c['decided_category'])) {
                                $effectiveCategory = (int)$c['decided_category'];
                            } elseif (!empty($c['major_category'])) {
                                $effectiveCategory = (int)$c['major_category'];
                            } elseif ($inferredCategory) {
                                $effectiveCategory = $inferredCategory;
                            }

                            $statusRaw = (string)($c['status'] ?? 'PENDING');
                            $hearingScheduled = (!empty($c['hearing_date']) && !empty($c['hearing_time']));
                            $isHearingOpen = (int)($c['hearing_is_open'] ?? 0) === 1;
                            $isHearingPaused = (int)($c['hearing_is_paused'] ?? 0) === 1;
                            
                            // Dynamically determine effective pause state if admin left but sync hasn't run yet
                            if ($isHearingOpen && !$isHearingPaused && !empty($c['admin_last_ping'])) {
                                $adminAwaySecs = time() - strtotime($c['admin_last_ping']);
                                if ($adminAwaySecs > 15) {
                                    $isHearingPaused = true;
                                }
                            }
                            
                            $showInvestigating = ($statusRaw === 'UNDER_INVESTIGATION') || ($statusRaw === 'PENDING' && $hasPanel);
                            
                            if ($statusRaw === 'CLOSED' || $statusRaw === 'RESOLVED') {
                                $statusBadgeClass = 'badge-resolved';
                                $statusLabel = 'Solved - Case Closed';
                            } elseif ($isHearingPaused) {
                                $statusBadgeClass = 'badge-pending';
                                $statusLabel = 'Paused';
                            } elseif ($isHearingOpen) {
                                $statusBadgeClass = 'badge-live';
                                $statusLabel = 'Hearing Live';
                            } elseif ($showInvestigating) {
                                $statusBadgeClass = 'badge-investigating';
                                $statusLabel = 'Under Investigation';
                            } elseif ($statusRaw === 'PENDING' && $hearingScheduled) {
                                $statusBadgeClass = 'badge-pending';
                                $statusLabel = 'Hearing Scheduled';
                            } else {
                                $statusBadgeClass = match($statusRaw) { 'PENDING'=>'badge-pending', 'UNDER_APPEAL'=>'badge-appeal', default=>'badge-cancelled' };
                                $statusLabel = match($statusRaw) { 'UNDER_APPEAL'=>'Under Appeal', default=>ucfirst(strtolower($statusRaw)) };
                            }

                            if ($isSection4) {
                                $offenseShort = match(true) {
                                    ($statusRaw === 'CLOSED' || $statusRaw === 'RESOLVED') => 'Section 4 – Resolved',
                                    $hasPanel                          => 'Section 4 – Panel Assigned',
                                    default                            => 'Section 4 – Under Investigation',
                                };
                            } else {
                                $offenseShort = (string)($c['offense_names'] ?? '');
                                if ($offenseShort === '') {
                                    if ($effectiveLevel === 'MAJOR') {
                                        $offenseShort = 'Major Offense';
                                    } elseif ($effectiveLevel === 'MINOR') {
                                        $offenseShort = 'Minor Offense';
                                    } else {
                                        $offenseShort = '—';
                                    }
                                }
                            }

                            if (($statusRaw === 'CLOSED' || $statusRaw === 'RESOLVED') && $c['decided_category']) {
                                $categoryHtml = '<span class="category-badge resolved">Cat ' . $c['decided_category'] . ' (final)</span>';
                            } elseif ($isSection4) {
                                $categoryHtml = '<span class="category-badge">Section 4</span>';
                            } elseif (!$isSection4 && $effectiveLevel === 'MAJOR' && $effectiveCategory >= 1 && $effectiveCategory <= 5) {
                                $categoryHtml = '<span class="category-badge">Cat ' . $effectiveCategory . '</span>';
                            } else {
                                $categoryHtml = '<span class="category-badge">—</span>';
                            }

                            $filterCategory = $isSection4 ? 'section4' : (($effectiveCategory >= 1 && $effectiveCategory <= 5) ? ('cat' . $effectiveCategory) : '');

                            $caseSummaryEsc = htmlspecialchars($c['case_summary'] ?? '');
                        ?>
                            <tr data-level="<?= e($lvl) ?>"
                                data-filter-category="<?= e($filterCategory) ?>"
                                data-caseid="<?= e($caseLabel) ?>"
                                data-student="<?= e($c['student_name']) ?>"
                                data-sid="<?= e($c['student_id']) ?>"
                                data-offense="<?= e($offenseShort) ?>"
                                data-category="<?= e(strip_tags($categoryHtml)) ?>"
                                data-date="<?= e($dateLabel) ?>"
                                data-status="<?= e($statusLabel) ?>"
                                data-status-raw="<?= e($c['status']) ?>"
                                data-days="<?= $days ?>"
                                data-warn="<?= $isWarn ? '1' : '0' ?>"
                                data-case-id="<?= $c['case_id'] ?>"
                                data-assigned-dept="<?= $c['assigned_department_id'] ?? '' ?>"
                                data-assigned-panel="<?= e($c['assigned_panel_members'] ?? '') ?>"
                                data-hearing-scheduled="<?= (!empty($c['hearing_date']) && !empty($c['hearing_time'])) ? '1' : '0' ?>"
                                data-hearing-date="<?= e((string)($c['hearing_date'] ?? '')) ?>"
                                data-hearing-time="<?= e((string)($c['hearing_time'] ?? '')) ?>"
                                data-hearing-type="<?= e((string)($c['hearing_type'] ?? '')) ?>"
                                data-hearing-label="<?= e((!empty($c['hearing_date']) && !empty($c['hearing_time'])) ? ($c['hearing_date'] . ' ' . $c['hearing_time'] . (!empty($c['hearing_type']) ? ' · ' . $c['hearing_type'] : '')) : 'No hearing scheduled') ?>"
                                data-decided-cat="<?= $c['decided_category'] ?? '' ?>"
                                data-final-decision="<?= e($c['final_decision'] ?? '') ?>"
                                data-case-kind="<?= e($c['case_kind'] ?? '') ?>"
                                data-case-summary="<?= $caseSummaryEsc ?>"
                                data-has-panel="<?= $hasPanel ? '1' : '0' ?>"
                                data-consensus-cat="<?= (int)($c['hearing_vote_consensus_category'] ?? 0) ?>"
                                onclick="selectCase(this)">
                                <td><div class="case-id"><?= e($caseLabel) ?></div></td>
                                <td>
                                    <div class="student-name"><?= e($c['student_name']) ?></div>
                                    <div class="student-id"><?= e($c['student_id']) ?></div>
                                </td>
                                <td>
                                    <div class="offense-name" title="<?= e($offenseShort) ?>"><?= e($offenseShort) ?></div>
                                    <?php if ($showInvestigating): ?>
                                        <span class="badge badge-investigating" style="margin-top:4px;display:inline-block;">🔍 Under Investigation</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= $categoryHtml ?></td>
                                <td><?= e($dateLabel) ?></td>
                                <td>
                                    <?= e((!empty($c['hearing_date']) && !empty($c['hearing_time'])) ? ($c['hearing_date'] . ' ' . $c['hearing_time'] . (!empty($c['hearing_type']) ? ' · ' . $c['hearing_type'] : '')) : 'No hearing scheduled') ?>
                                </td>
                                <td>
                                    <span class="badge <?= $statusBadgeClass ?>"><?= e($statusLabel) ?></span>
                                </td>
                                <td>
                                    <?php if ($statusRaw === 'PENDING' || $statusRaw === 'UNDER_INVESTIGATION'): ?>
                                        <div class="days-text <?= $isWarn ? 'warn' : '' ?>"><?= $days ?>d</div>
                                        <?php if ($isWarn): ?><div class="days-sub">⚠ Soon</div><?php endif; ?>
                                    <?php else: ?>
                                        <span style="color:#bbb">—</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>

                <!-- Detail pane -->
                <div class="detail-pane" id="detail-pane">
                    <div class="detail-empty" id="detail-empty">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                        <p>Select a case to view details</p>
                    </div>
                    <div class="detail-content" id="detail-content">
                        <div class="detail-head">
                            <div class="detail-case-id" id="d-caseid"></div>
                            <div class="detail-student" id="d-student"></div>
                            <div class="detail-sid" id="d-sid"></div>
                            <div class="detail-badges" id="d-badges"></div>
                        </div>
                        <div class="detail-summary" id="d-summary-wrap">
                            <div class="detail-summary-label">Case Summary</div>
                            <div id="d-summary"></div>
                        </div>
                        <div class="detail-body">
                            <div class="detail-row"><span class="detail-row-label">Offense</span><span class="detail-row-value" id="d-offense"></span></div>
                            <div class="detail-row"><span class="detail-row-label">Category</span><span class="detail-row-value" id="d-category"></span></div>
                            <div class="detail-row"><span class="detail-row-label">Date Filed</span><span class="detail-row-value" id="d-date"></span></div>
                            <div class="detail-row"><span class="detail-row-label">Days Pending</span><span class="detail-row-value" id="d-days"></span></div>
                            <div class="detail-row" id="row-assignment"><span class="detail-row-label">Assignment</span><span class="detail-row-value" id="d-assignment"></span></div>
                            <div class="detail-row" id="row-hearing"><span class="detail-row-label">Hearing</span><span class="detail-row-value" id="d-hearing"></span></div>
                            <div class="detail-row" id="row-dept"><span class="detail-row-label">Assigned Dept</span><span class="detail-row-value" id="d-dept"></span></div>
                            <div class="detail-row" id="row-decision" style="display:none;"><span class="detail-row-label">Final Decision</span><span class="detail-row-value" id="d-decision"></span></div>
                        </div>
                        <div class="detail-forms">
                            <div id="assignment-form" style="display:none;">
                                <div class="divider-label">Assign Investigating Panel</div>
                                <div style="font-size:12px;color:#6b7280;line-height:1.5;margin-bottom:10px;">
                                    Assign a department and panel members to start the hearing process.
                                </div>
                                <a href="#" id="btn-assign-full" class="btn-assign" style="display:block;text-align:center;width:100%;">➡️ Assign Panel & Set Hearing</a>
                            </div>
                            <div id="manage-form" style="display:none; margin-top:14px;">
                                <div class="divider-label">Hearing Portal</div>
                                <div style="font-size:12px;color:#6b7280;line-height:1.5;margin-bottom:10px;">
                                    Access the live hearing portal to start voting, chat, and record decisions.
                                </div>
                                <a href="#" id="btn-manage-full" class="btn-assign" style="display:block;text-align:center;width:100%;background:#10b981;border-color:#10b981;color:#fff;">▶️ Open Hearing Portal</a>
                            </div>
                            <div id="decision-form" style="display:none; margin-top:14px;">
                                <div class="divider-label">Resolve Case</div>
                                <form method="post" action="upcc_cases.php" onsubmit="return confirm('Resolve this case? This cannot be undone.')">
                                    <input type="hidden" name="action" value="resolve_case">
                                    <input type="hidden" name="case_id" id="resolve_case_id">
                                    <label class="decision-label">Assign Category (1–5)</label>
                                    <select name="decided_category" class="decision-select" required>
                                        <option value="">-- Select Category --</option>
                                        <?php for ($i=1;$i<=5;$i++): ?><option value="<?= $i ?>">Category <?= $i ?></option><?php endfor; ?>
                                    </select>
                                    <label class="decision-label">Final Decision / Sanction</label>
                                    <textarea name="final_decision" class="decision-textarea" rows="3" placeholder="Write the final decision..." required></textarea>
                                    <button type="submit" class="btn-resolve">✓ Resolve Case</button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Manage Hearing & Panel Modal -->
<div class="cpanel-overlay" id="manage-hearing-overlay">
    <div class="cpanel-modal">
        <div class="cpanel-head">
            <button class="cpanel-head-close" onclick="closeManageHearingModal()">✕</button>
            <h2>Manage Hearing & Panel</h2>
            <div class="sub">Assign panel members, schedule hearing date/time, and save updates.</div>
        </div>
        <div class="cpanel-body" style="padding:20px;">
            <form method="post" action="upcc_cases.php" class="es-form" onsubmit="return validateHearingConfigForm()">
                <input type="hidden" name="action" value="update_hearing_config">
                <input type="hidden" name="case_id" id="manage-case-id" value="">
                <div class="es-field"><label>Lead Department</label><select name="assigned_department_id" id="manage-dept" onchange="filterPanelDropdown()"><option value="">-- Select department --</option><?php foreach ($departments as $d): ?><option value="<?= $d['dept_id'] ?>"><?= e($d['dept_name']) ?></option><?php endforeach; ?></select></div>
                <div class="es-field">
                    <label id="manage-panel-label">Panel Members (Select from lead department)</label>
                    <div class="panel-select-wrapper">
                        <div id="selected-panel-members" class="selected-panel-members"></div>
                        <div style="position:relative;">
                            <input type="text" id="panel-member-search" class="panel-member-search" placeholder="Search and click to add panel members..." oninput="filterPanelDropdown()" onfocus="showPanelDropdown()" onblur="setTimeout(hidePanelDropdown, 200)">
                            <div id="panel-member-dropdown" class="panel-member-dropdown"></div>
                        </div>
                    </div>
                    <div id="hidden-panel-inputs"></div>
                </div>
                <div class="field-row">
                    <div class="es-field"><label>Hearing Date</label><input type="date" name="hearing_date" id="manage-hearing-date"></div>
                    <div class="es-field"><label>Hearing Time</label><input type="time" name="hearing_time" id="manage-hearing-time"></div>
                </div>
                <div class="es-form-actions" style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
                    <button type="submit" class="btn-assign">Save Hearing & Panel</button>
                    <button type="button" class="btn-cancel" onclick="closeManageHearingModal()">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Committee Panel Modal -->
<div class="cpanel-overlay" id="cpanel-overlay">
    <div class="cpanel-modal">
        <div class="cpanel-head">
            <button class="cpanel-head-close" onclick="closeCpanel()">✕</button>
            <h2>Committee Panel</h2>
            <div class="sub">Staff Directory & Department Management</div>
            <div class="cpanel-stats">
                <?php $activeCount = count(array_filter($members, fn($m) => $m['is_active'])); ?>
                <div class="cpstat"><div class="n"><?= count($members) ?></div><div class="l">Total Members</div></div>
                <div class="cpstat"><div class="n"><?= $activeCount ?></div><div class="l">Active</div></div>
                <div class="cpstat"><div class="n"><?= count($departments) ?></div><div class="l">Departments</div></div>
            </div>
        </div>
        <div class="cpanel-tabs">
            <button class="cptab active" onclick="switchCpTab('members', this)">👥 Members</button>
            <button class="cptab" onclick="switchCpTab('departments', this)">🏛️ Departments</button>
            <button class="cptab" onclick="switchCpTab('add', this)">➕ Add Member</button>
        </div>
        <div class="cpanel-body">
            <!-- Members tab -->
            <div id="cptab-members">
                <?php if ($regSuccess === 'member_department_updated'): ?><div class="alert-ok" style="margin-bottom:12px;">✓ Staff member department updated.</div><?php endif; ?>
                <?php if ($regSuccess === 'member_department_removed'): ?><div class="alert-ok" style="margin-bottom:12px;">✓ Staff member removed from department.</div><?php endif; ?>
                <div class="cp-toolbar"><div class="cp-search-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg><input type="text" id="cp-search" placeholder="Search…" oninput="cpSearch()"></div></div>
                <div class="cp-grid" id="cp-member-grid">
                    <?php foreach ($members as $m):
                        $initials = strtoupper(substr($m['full_name'],0,1) . (strpos($m['full_name'],' ')? substr(strrchr($m['full_name'],' '),1,1) : ''));
                        $photoSrc = (!empty($m['photo_path']) && file_exists(__DIR__ . '/' . $m['photo_path'])) ? htmlspecialchars($m['photo_path']) : null;
                        $rb = match(strtolower($m['role'])) { 'chairperson'=>'rb-chair', 'vice chair'=>'rb-vchair', 'secretary'=>'rb-sec', default=>'rb-member' };
                        $isActive = (bool)$m['is_active'];
                    ?>
                    <div class="cp-card <?= $isActive ? '' : 'inactive' ?>" data-name="<?= e(strtolower($m['full_name'])) ?>" data-role="<?= e(strtolower($m['role'])) ?>" data-email="<?= e(strtolower($m['email'])) ?>">
                        <div class="edit-strip" id="es-<?= $m['upcc_id'] ?>">
                            <div class="es-head">
                                <div class="es-avatar"><?= e($initials) ?></div>
                                <div class="es-head-text">
                                    <div class="es-title">Edit Member</div>
                                    <div class="es-sub"><?= e($m['full_name']) ?> · <?= e($m['dept_name'] ?? 'No department') ?></div>
                                </div>
                            </div>
                            <form method="post" action="upcc_cases.php" class="es-form">
                                <input type="hidden" name="action" value="update_member">
                                <input type="hidden" name="upcc_id" value="<?= $m['upcc_id'] ?>">
                                <div class="es-field"><label>Full Name</label><input type="text" name="full_name" value="<?= e($m['full_name']) ?>"></div>
                                <div class="field-row">
                                    <div class="es-field"><label>Email</label><input type="email" name="email" value="<?= e($m['email']) ?>"></div>
                                    <div class="es-field"><label>Role</label><select name="role"><?php foreach (['Chairperson','Vice Chair','Secretary','Member'] as $r): ?><option value="<?= $r ?>" <?= $m['role']===$r?'selected':'' ?>><?= $r ?></option><?php endforeach; ?></select></div>
                                </div>
                                <div class="es-field"><label>Department</label><select name="department_id"><option value="">-- None --</option><?php foreach ($departments as $d): ?><option value="<?= $d['dept_id'] ?>" <?= ($m['department_id']==$d['dept_id'])?'selected':'' ?>><?= e($d['dept_name']) ?></option><?php endforeach; ?></select></div>
                                <div class="es-active">
                                    <div>
                                        <label for="es-active-<?= $m['upcc_id'] ?>">Active member</label>
                                        <small>Inactive members can be hard deleted.</small>
                                    </div>
                                    <input id="es-active-<?= $m['upcc_id'] ?>" class="es-toggle" type="checkbox" name="is_active" value="1" <?= $m['is_active'] ? 'checked' : '' ?>>
                                </div>
                                <div class="es-btns"><button type="submit" class="es-save">Save</button><button type="button" class="es-cancel" onclick="hideEditStrip(<?= $m['upcc_id'] ?>)">Cancel</button></div>
                            </form>
                        </div>
                        <div class="confirm-strip" id="cs-<?= $m['upcc_id'] ?>">
                            <p><?= $isActive ? 'Deactivate?' : 'Reactivate or delete?' ?></p>
                            <div class="confirm-strip-btns">
                                <form method="post" action="upcc_cases.php" style="display:inline;"><input type="hidden" name="action" value="toggle_member"><input type="hidden" name="upcc_id" value="<?= $m['upcc_id'] ?>"><input type="hidden" name="new_active" value="<?= $isActive ? '0' : '1' ?>"><button type="submit" class="cs-yes">Yes</button></form>
                                <?php if (!$isActive): ?>
                                <form method="post" action="upcc_cases.php" style="display:inline;" onsubmit="return confirm('Hard delete this inactive member? This cannot be undone.')"><input type="hidden" name="action" value="delete_member"><input type="hidden" name="upcc_id" value="<?= $m['upcc_id'] ?>"><button type="submit" class="cs-delete">Delete</button></form>
                                <?php endif; ?>
                                <button type="button" class="cs-no" onclick="hideConfirmStrip(<?= $m['upcc_id'] ?>)">Cancel</button>
                            </div>
                        </div>
                        <div class="cp-av" style="background:#1b2b6b"><?php if ($photoSrc): ?><img src="<?= $photoSrc ?>"><?php else: ?><?= e($initials) ?><?php endif; ?></div>
                        <div class="cp-name"><?= e($m['full_name']) ?></div>
                        <span class="cp-role-badge <?= $rb ?>"><?= e(ucfirst($m['role'])) ?></span>
                        <div class="cp-email"><?= e($m['email']) ?></div>
                        <div class="cp-email"><?= e($m['dept_name'] ?? 'No department') ?></div>
                        <div class="cp-status <?= $isActive ? 'on' : 'off' ?>"><?= $isActive ? '● Active' : '● Inactive' ?></div>
                        <div class="cp-actions">
                            <button
                                class="btn-ic edit"
                                type="button"
                                data-upcc-id="<?= (int)$m['upcc_id'] ?>"
                                data-full-name="<?= e($m['full_name']) ?>"
                                data-email="<?= e($m['email']) ?>"
                                data-role="<?= e($m['role']) ?>"
                                data-department-id="<?= $m['department_id'] !== null ? (int)$m['department_id'] : '' ?>"
                                data-is-active="<?= $m['is_active'] ? '1' : '0' ?>"
                                onclick="openMemberEditModal(this)">
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                            </button>
                            <?php if (!$isActive): ?>
                                <form method="post" action="upcc_cases.php" style="display:inline;" onsubmit="return confirm('Hard delete this inactive member? This cannot be undone.')">
                                    <input type="hidden" name="action" value="delete_member">
                                    <input type="hidden" name="upcc_id" value="<?= (int)$m['upcc_id'] ?>">
                                    <button type="submit" class="btn-ic deact" title="Hard delete inactive member" aria-label="Hard delete inactive member">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Departments tab -->
            <div id="cptab-departments" style="display:none">
                <?php if ($regError): ?><div class="alert-err" style="margin-bottom:12px;"><?= htmlspecialchars($regError) ?></div><?php endif; ?>
                <?php if ($regSuccess === 'member_department_updated'): ?><div class="alert-ok" style="margin-bottom:12px;">✓ Staff member was assigned to a department.</div><?php endif; ?>
                <?php if ($regSuccess === 'member_department_removed'): ?><div class="alert-ok" style="margin-bottom:12px;">✓ Staff member was removed from their department.</div><?php endif; ?>

                <div class="cp-toolbar" style="justify-content: flex-end;"><button class="btn-cp-add" onclick="openAddDeptForm()">+ New Department</button></div>
                <div class="cp-grid">
                    <?php foreach ($departments as $dept): ?>
                        <button type="button" class="dept-card-btn" onclick="openDepartmentModal(<?= (int)$dept['dept_id'] ?>)">
                            <div class="cp-card">
                                <div class="cp-av" style="background:#1b2b6b; width:48px; height:48px; font-size:22px;">🏛️</div>
                                <div class="cp-name"><?= e($dept['dept_name']) ?></div>
                                <div class="cp-status <?= $dept['is_active'] ? 'on' : 'off' ?>"><?= $dept['is_active'] ? 'Active' : 'Inactive' ?></div>
                                <div class="dept-member-count"><?= (int)($deptMemberCounts[(int)$dept['dept_id']] ?? 0) ?> member(s)</div>
                                <div class="cp-actions" style="justify-content:center;">
                                    <span class="btn-ic edit" aria-hidden="true" title="Manage department"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg></span>
                                </div>
                            </div>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <!-- Add Member tab -->
            <div id="cptab-add" style="display:none; max-width:440px;">
                <?php if ($regError && !isset($_SESSION['upcc_member_otp'])): ?><div class="alert-err"><?= htmlspecialchars($regError) ?></div><?php endif; ?>
                <?php if ($regSuccess === 'created'): ?><div class="alert-ok">✓ Member registered!</div><?php endif; ?>
                <form method="post" action="upcc_cases.php">
                    <input type="hidden" name="action" value="send_member_otp">
                    <div class="field-row"><div class="field-group"><label>Full Name</label><input type="text" name="full_name" required></div><div class="field-group"><label>Username</label><input type="text" name="username" required></div></div>
                    <div class="field-group"><label>Email</label><input type="email" name="email" required></div>
                    <div class="field-row"><div class="field-group"><label>Role</label><select name="role"><option>Chairperson</option><option>Vice Chair</option><option>Secretary</option><option selected>Member</option></select></div><div class="field-group"><label>Department</label><select name="department_id"><option value="">-- None --</option><?php foreach ($departments as $d): ?><option value="<?= $d['dept_id'] ?>"><?= e($d['dept_name']) ?></option><?php endforeach; ?></select></div></div>
                    <div class="field-group"><label>Password</label><input type="password" name="password" placeholder="Temporary password" required></div>
                    <button type="submit" class="btn-primary">Send OTP</button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- Member Edit Modal -->
<div class="modal-overlay" id="modal-member-edit" style="z-index: 2100;">
    <div class="modal" style="max-width:560px;max-height:90vh;overflow:auto;">
        <button class="modal-close" onclick="closeModal('modal-member-edit')">&times;</button>
        <div class="modal-title">Edit Member</div>
        <div class="modal-sub">Update member info and department in a larger popup form.</div>

        <form method="post" action="upcc_cases.php" id="member-edit-form">
            <input type="hidden" name="action" value="update_member">
            <input type="hidden" name="upcc_id" id="member-edit-upcc-id">

            <div class="field-group">
                <label>Full Name</label>
                <input type="text" name="full_name" id="member-edit-full-name" required>
            </div>

            <div class="field-row">
                <div class="field-group">
                    <label>Email</label>
                    <input type="email" name="email" id="member-edit-email" required>
                </div>
                <div class="field-group">
                    <label>Role</label>
                    <select name="role" id="member-edit-role" required>
                        <?php foreach (['Chairperson','Vice Chair','Secretary','Member'] as $r): ?>
                            <option value="<?= $r ?>"><?= $r ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="field-group">
                <label>Department</label>
                <select name="department_id" id="member-edit-department">
                    <option value="">-- None --</option>
                    <?php foreach ($departments as $d): ?>
                        <option value="<?= (int)$d['dept_id'] ?>"><?= e($d['dept_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field-group" style="display:flex;align-items:center;gap:10px;">
                <input type="checkbox" id="member-edit-is-active" name="is_active" value="1" style="width:auto;">
                <label for="member-edit-is-active" style="margin:0;">Active member</label>
            </div>

            <button type="submit" class="btn-primary">Save Changes</button>
        </form>
    </div>
</div>

<!-- Department Detail Modal -->
<div class="modal-overlay" id="modal-department-detail" style="z-index: 2100;">
    <div class="modal" style="max-width:640px;max-height:90vh;overflow:auto;">
        <button class="modal-close" onclick="closeModal('modal-department-detail')">&times;</button>
        <div class="modal-title" id="dept-modal-title">Department</div>
        <div class="modal-sub" id="dept-modal-subtitle">Manage members and rename the department.</div>

        <div class="dept-modal-section">
            <h3>Edit Department Name</h3>
            <form method="post" action="upcc_cases.php" class="dept-inline-form">
                <input type="hidden" name="action" value="edit_dept">
                <input type="hidden" name="dept_id" id="dept-modal-id">
                <div class="field-group">
                    <label>Department Name</label>
                    <input type="text" name="dept_name" id="dept-modal-name" required>
                </div>
                <button type="submit" class="btn-primary" style="width:auto;min-width:120px;">Save</button>
            </form>
        </div>

        <div class="dept-modal-section">
            <h3>Members in Department</h3>
            <div id="dept-modal-members" class="dept-modal-list"></div>
        </div>

        <div class="dept-modal-section">
            <h3>Add Staff to Department</h3>
            <form method="post" action="upcc_cases.php" class="dept-inline-form">
                <input type="hidden" name="action" value="set_member_department">
                <input type="hidden" name="department_id" id="dept-modal-add-id">
                <div class="field-group">
                    <label>Select Staff</label>
                    <select name="upcc_id" id="dept-modal-add-member" required></select>
                </div>
                <button type="submit" class="btn-primary" style="width:auto;min-width:120px;">Add</button>
            </form>
        </div>
    </div>
</div>

<!-- Add Department Modal -->
<div class="modal-overlay" id="modal-department-add" style="z-index: 2100;">
    <div class="modal" style="max-width:520px;max-height:90vh;overflow:auto;">
        <button class="modal-close" onclick="closeModal('modal-department-add')">&times;</button>
        <div class="modal-title">New Department</div>
        <div class="modal-sub">Create a department without leaving this page.</div>

        <form method="post" action="upcc_cases.php">
            <input type="hidden" name="action" value="add_dept">
            <div class="field-group">
                <label>Department Name</label>
                <input type="text" name="dept_name" id="dept-add-name" required>
            </div>
            <button type="submit" class="btn-primary">Create Department</button>
        </form>
    </div>
</div>

<!-- OTP Modal -->
<div class="modal-overlay <?= isset($_SESSION['upcc_member_otp']) ? 'open' : '' ?>" id="modal-otp">
    <div class="modal">
        <button class="modal-close" onclick="closeModal('modal-otp')">&times;</button>
        <div class="modal-title">Verify OTP</div>
        <div class="modal-sub">Enter the 6-digit code sent to your admin email.</div>
        <?php if ($regError && isset($_SESSION['upcc_member_otp'])): ?><div class="alert-err"><?= htmlspecialchars($regError) ?></div><?php endif; ?>
        <form method="post" action="upcc_cases.php" id="otp-form-member">
            <input type="hidden" name="action" value="verify_member_otp">
            <div class="otp-fields"><?php for ($i=0;$i<6;$i++): ?><input type="number" class="otp-digit" min="0" max="9" inputmode="numeric"><?php endfor; ?></div>
            <input type="hidden" name="otp" id="otp-hidden">
            <button type="submit" class="btn-primary">Verify & Register</button>
        </form>
    </div>
</div>

<?php if (isset($_GET['msg']) && $_GET['msg'] === 'assigned'): ?>
<div class="modal-overlay open" id="modal-assign-success" style="z-index: 2000;">
    <div class="modal">
        <div class="modal-title">Saved Successfully</div>
        <div class="modal-sub">
            The UPCC Panel members and hearing schedule have been successfully saved for this case.
        </div>
        <button class="btn-primary" onclick="closeModal('modal-assign-success')" style="margin-top:16px;width:100%;">Continue</button>
    </div>
</div>
<?php endif; ?>

<?php $showStaffSuccess = in_array($regSuccess, ['member_deleted', 'member_reactivated'], true); ?>
<?php if ($showStaffSuccess): ?>
<div class="modal-overlay open" id="modal-staff-success" style="z-index: 2000;">
    <div class="modal">
        <div class="modal-title">Staff Update Successful</div>
        <div class="modal-sub">
            <?= $regSuccess === 'member_deleted'
                ? 'The inactive staff member was permanently deleted.'
                : 'The staff member was successfully reactivated.' ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($showCommitteeFeedbackModal): ?>
<div class="modal-overlay open" id="modal-committee-feedback" style="z-index: 1990;">
    <div class="modal">
        <button class="modal-close" onclick="closeModal('modal-committee-feedback')">&times;</button>
        <div class="modal-title">Committee Update</div>
        <div class="modal-sub"><?= htmlspecialchars($regError !== '' ? $regError : $committeeFeedbackText) ?></div>
    </div>
</div>
<?php endif; ?>

<script>
// Dept name map from PHP
const deptNames = {<?php foreach ($departments as $d): ?><?= $d['dept_id'] ?>: "<?= e($d['dept_name']) ?>", <?php endforeach; ?>};
const committeeMembers = <?= json_encode($members) ?>;
let currentFilterLevel = 'all';

// Updated filterCases function to handle the new "panel" and "nopanel" filters
function filterCases(level, btn) {
    currentFilterLevel = level;
    document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
    btn.classList.add('active');
    document.querySelectorAll('#cases-table tbody tr').forEach(row => {
        if (typeof targetStudentId !== 'undefined' && targetStudentId && row.dataset.sid !== targetStudentId) {
            row.style.display = 'none';
            return;
        }
        if (typeof targetStudentId !== 'undefined' && targetStudentId && typeof activeStatuses !== 'undefined') {
            if (!activeStatuses.includes(row.dataset.statusRaw)) {
                row.style.display = 'none';
                return;
            }
        }

        const rowLevel = row.dataset.level || '';
        const rowCategory = row.dataset.filterCategory || '';
        const hasPanel = row.dataset.hasPanel === '1';
        const hearingScheduled = row.dataset.hearingScheduled === '1';
        const isAssigned = hasPanel;
        const isReady = hasPanel && hearingScheduled;
        const isUnassigned = !hasPanel;

        let show = false;
        if (level === 'all') {
            show = true;
        } else if (level === 'major') {
            show = rowLevel === level;
        } else if (level === 'assigned') {
            show = isAssigned;
        } else if (level === 'ready') {
            show = isReady;
        } else if (level === 'unassigned') {
            show = isUnassigned;
        } else if (level === 'no_hearing') {
            show = isAssigned && !isReady;
        } else if (level === 'unsolved') {
            const status = row.dataset.statusRaw;
            show = status === 'PENDING' || status === 'UNDER_INVESTIGATION' || status === 'UNDER_APPEAL';
        } else if (level === 'solved') {
            const status = row.dataset.statusRaw;
            show = status === 'CLOSED' || status === 'RESOLVED' || status === 'CANCELLED';
        } else if (/^cat[1-5]$/.test(level) || level === 'section4') {
            show = rowCategory === level;
        }

        row.style.display = show ? '' : 'none';
    });
    clearDetail();
}

function selectCase(row) {
    document.querySelectorAll('#cases-table tbody tr').forEach(r => r.classList.remove('selected'));
    row.classList.add('selected');

    const status     = row.dataset.statusRaw;
    const hasPanel   = row.dataset.hasPanel === '1';
    const hearingScheduled = row.dataset.hearingScheduled === '1';
    const consensusCat = parseInt(row.dataset.consensusCat || '0');
    const caseKind   = row.dataset.caseKind;
    const decidedCat = row.dataset.decidedCat;
    const decision   = row.dataset.finalDecision;
    const summary    = row.dataset.caseSummary;
    const assignedDeptId = row.dataset.assignedDept;
    const caseId     = row.dataset.caseId;
    const isPending  = (status === 'PENDING');
    const isInvestigating = (status === 'UNDER_INVESTIGATION');
    const isClosedStatus = (status === 'CLOSED' || status === 'RESOLVED' || status === 'CANCELLED');
    const isActiveCase = isPending || isInvestigating;

    // Header
    document.getElementById('d-caseid').textContent   = row.dataset.caseid;
    document.getElementById('d-student').textContent  = row.dataset.student;
    document.getElementById('d-sid').textContent      = row.dataset.sid;

    // Badges in header
    const badgeContainer = document.getElementById('d-badges');
    let badgesHtml = '';
    let statusClass = 'db-pending';
    let statusLabel = status.charAt(0) + status.slice(1).toLowerCase();
    if (status === 'CANCELLED') {
        statusClass = 'db-appeal'; // You can use a different class if you have an orange/red one
        statusLabel = 'Cancelled';
    } else if (isInvestigating || (isPending && hasPanel)) {
        statusClass = 'db-investigating';
        statusLabel = 'Under Investigation';
    } else if (isPending && hearingScheduled) {
        statusClass = 'db-awaiting';
        statusLabel = 'Hearing Scheduled';
    } else if (isClosedStatus) {
        statusClass = 'db-resolved';
        statusLabel = 'Solved - Case Closed';
    } else if (status === 'UNDER_APPEAL') {
        statusClass = 'db-appeal';
        statusLabel = 'Under Appeal';
    } else if (isPending && !hasPanel) {
        statusClass = 'db-awaiting';
        statusLabel = 'Awaiting Assignment';
    }
    badgesHtml += `<span class="detail-badge ${statusClass}">${statusLabel}</span>`;
    badgeContainer.innerHTML = badgesHtml;

    // Case summary
    const summaryWrap = document.getElementById('d-summary-wrap');
    const summaryEl   = document.getElementById('d-summary');
    if (summary && summary.trim()) {
        summaryEl.textContent = summary;
        summaryWrap.style.display = 'block';
    } else {
        summaryWrap.style.display = 'none';
    }

    // Detail rows
    document.getElementById('d-offense').textContent  = row.dataset.offense;
    document.getElementById('d-category').textContent = row.dataset.category || '—';
    document.getElementById('d-date').textContent     = row.dataset.date;
    document.getElementById('d-days').textContent     = isActiveCase ? row.dataset.days + ' days' + (row.dataset.warn === '1' ? ' ⚠' : '') : '—';

    const hearingDate = row.dataset.hearingDate || '';
    const hearingTime = row.dataset.hearingTime || '';
    const hearingType = row.dataset.hearingType || '';
    const hearingLabel = hearingScheduled
        ? `${hearingDate}${hearingTime ? ' ' + hearingTime : ''}${hearingType ? ' · ' + hearingType : ''}`
        : 'No hearing scheduled';
    const assignmentLabel = hasPanel ? 'Assigned to panel' : (hearingScheduled ? 'Scheduled hearing' : 'Unassigned');

    document.getElementById('d-assignment').textContent = assignmentLabel;
    document.getElementById('d-hearing').textContent = hearingLabel;

    const deptName = assignedDeptId ? (deptNames[assignedDeptId] || ('Dept #' + assignedDeptId)) : 'Not assigned';
    document.getElementById('d-dept').textContent = deptName;

    if (decidedCat && decision) {
        document.getElementById('d-decision').textContent = `Cat ${decidedCat}: ${decision.slice(0, 60)}${decision.length > 60 ? '…' : ''}`;
        document.getElementById('row-decision').style.display = 'flex';
    } else {
        document.getElementById('row-decision').style.display = 'none';
    }

    // Show assignment form if pending or investigating and no panel yet
    const showAssign = (isPending || isInvestigating) && !hasPanel;

    // Show manage form if case has a panel and is not closed/resolved
    const showManage = hasPanel && !isClosedStatus;

    // Show decision form ONLY if:
    // - Case is under investigation or pending with panel
    // - No final decision has been recorded yet
    // - A consensus category has been registered (consensusCat > 0)
    const showDecide = (isInvestigating || (isPending && hasPanel)) && !decidedCat && consensusCat > 0;

    // Hide old assignment form, show correct one
    const assignDiv = document.getElementById('assignment-form');
    const manageDiv = document.getElementById('manage-form');
    const decideDiv = document.getElementById('decision-form');
    const waitDiv = document.getElementById('decision-wait-message');

    assignDiv.style.display = 'none';
    manageDiv.style.display = 'none';
    decideDiv.style.display = 'none';
    if (waitDiv) waitDiv.style.display = 'none';

    if (showAssign) {
        assignDiv.style.display = 'block';
        const assignLink = document.getElementById('btn-assign-full');
        if (assignLink) {
            assignLink.href = '#';
            assignLink.onclick = (e) => { e.preventDefault(); openManageHearingModal(caseId, row); };
        }
    } else if (showManage) {
        manageDiv.style.display = 'block';
        const manageLink = document.getElementById('btn-manage-full');
        if (manageLink) {
            manageLink.href = 'upcc_case_view.php?id=' + caseId;
            manageLink.onclick = null;
        }
    } else if (showDecide) {
        document.getElementById('resolve_case_id').value = caseId;
        decideDiv.style.display = 'block';
    } else if (hasPanel && !isClosedStatus && !decidedCat && consensusCat === 0) {
        // Show waiting message
        let waitDiv = document.getElementById('decision-wait-message');
        if (!waitDiv) {
            waitDiv = document.createElement('div');
            waitDiv.id = 'decision-wait-message';
            waitDiv.className = 'alert-info';
            waitDiv.style.cssText = 'background:#eef2ff; border:1px solid #cbd5e1; border-radius:8px; padding:12px; margin-top:14px; font-size:13px; color:#1e293b;';
            document.querySelector('.detail-forms').appendChild(waitDiv);
        }
        waitDiv.innerHTML = '⏳ <strong>Awaiting UPCC hearing consensus</strong> — The panel has not yet reached a category decision. The case cannot be resolved until a consensus is recorded.';
        waitDiv.style.display = 'block';
    }

    document.getElementById('detail-empty').style.display    = 'none';
    document.getElementById('detail-content').classList.add('active');
}

function openManageHearingModal(caseId, row) {
    if (!row) {
        row = document.querySelector('#cases-table tbody tr.selected');
    }
    if (!caseId && row) {
        caseId = row.dataset.caseId;
    }
    if (!caseId || !row) {
        return;
    }

    const overlay = document.getElementById('manage-hearing-overlay');
    const deptSelect = document.getElementById('manage-dept');
    const dateInput = document.getElementById('manage-hearing-date');
    const timeInput = document.getElementById('manage-hearing-time');
    const caseIdInput = document.getElementById('manage-case-id');

    if (!overlay || !deptSelect || !dateInput || !timeInput || !caseIdInput) {
        return;
    }

    caseIdInput.value = caseId;
    const assignedDept = row.dataset.assignedDept || '';
    let assignedPanel = [];
    if (row.dataset.assignedPanel) {
        try {
            assignedPanel = JSON.parse(row.dataset.assignedPanel);
        } catch (_err) {
            assignedPanel = String(row.dataset.assignedPanel).split(',').map(v => v.trim()).filter(Boolean);
        }
    }
    const hearingDate = row.dataset.hearingDate || '';
    const hearingTime = row.dataset.hearingTime || '';

    deptSelect.value = assignedDept;
    dateInput.value = hearingDate;
    timeInput.value = hearingTime;

    loadDepartmentMembers(assignedDept, assignedPanel);
    overlay.classList.add('open');
}

function syncQueueRows() {
    const table = document.getElementById('cases-table');
    const manageOverlay = document.getElementById('manage-hearing-overlay');
    if (!table || (manageOverlay && manageOverlay.classList.contains('open'))) return;

    const selectedRow = document.querySelector('#cases-table tbody tr.selected');
    const selectedCaseId = selectedRow ? selectedRow.dataset.caseId : '';

    fetch(location.pathname + location.search + (location.search ? '&' : '?') + '_t=' + Date.now(), { cache: 'no-store' })
        .then(r => r.text())
        .then(html => {
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const freshTableBody = doc.querySelector('#cases-table tbody');
            const currentTableBody = document.querySelector('#cases-table tbody');
            if (!freshTableBody || !currentTableBody) return;

            const freshRows = Array.from(freshTableBody.querySelectorAll('tr[data-case-id]'));
            const rowsById = new Map(freshRows.map(row => [row.dataset.caseId, row]));

            document.querySelectorAll('#cases-table tbody tr[data-case-id]').forEach(currentRow => {
                const freshRow = rowsById.get(currentRow.dataset.caseId);
                if (!freshRow) return;
                currentRow.outerHTML = freshRow.outerHTML;
            });

            const activeFilterBtn = document.querySelector('.filter-tab.active') || document.querySelector('.filter-tab');
            if (activeFilterBtn) {
                filterCases(currentFilterLevel, activeFilterBtn);
            }

            if (selectedCaseId) {
                const refreshedSelected = document.querySelector(`#cases-table tbody tr[data-case-id="${CSS.escape(selectedCaseId)}"]`);
                if (refreshedSelected) {
                    selectCase(refreshedSelected);
                }
            }
        })
        .catch(() => {});
}

function closeManageHearingModal() {
    const overlay = document.getElementById('manage-hearing-overlay');
    if (overlay) {
        overlay.classList.remove('open');
    }
}

function clearDetail() {
    document.getElementById('detail-empty').style.display = 'flex';
    document.getElementById('detail-content').classList.remove('active');
    document.querySelectorAll('#cases-table tbody tr').forEach(r => r.classList.remove('selected'));
}

let selectedPanelMembers = [];

function loadDepartmentMembers(deptId, initialSelected = []) {
    selectedPanelMembers = Array.isArray(initialSelected) ? initialSelected.map(String) : [];
    renderSelectedPanelMembers();
    filterPanelDropdown();
}

function renderSelectedPanelMembers() {
    const activeStaff = committeeMembers.filter(m => String(m.is_active) === '1');
    const container = document.getElementById('selected-panel-members');
    const hiddenContainer = document.getElementById('hidden-panel-inputs');
    if (!container || !hiddenContainer) return;
    
    let html = '';
    let hiddenHtml = '';
    
    selectedPanelMembers.forEach(id => {
        const staff = activeStaff.find(m => String(m.upcc_id) === id);
        if (staff) {
            html += `<div class="panel-chip">${escH(staff.full_name)} <span class="panel-chip-remove" onclick="removePanelMember('${id}')">×</span></div>`;
            hiddenHtml += `<input type="hidden" name="panel_members[]" value="${id}">`;
        }
    });
    
    container.innerHTML = html;
    hiddenContainer.innerHTML = hiddenHtml;
    
    if (selectedPanelMembers.length === 0) {
        container.innerHTML = '<div style="font-size:11px;color:#9ca3af;padding:4px;">No members selected.</div>';
    }
}

function filterPanelDropdown() {
    const input = document.getElementById('panel-member-search');
    const dropdown = document.getElementById('panel-member-dropdown');
    const deptSelect = document.getElementById('manage-dept');
    if (!input || !dropdown || !deptSelect) return;
    
    const query = input.value.toLowerCase().trim();
    const selectedDeptId = deptSelect.value;
    
    let availableStaff = committeeMembers.filter(m => String(m.is_active) === '1');
    
    // Filter by selected department (if any is selected)
    if (selectedDeptId) {
        availableStaff = availableStaff.filter(m => String(m.department_id) === String(selectedDeptId));
    }
    
    // Available staff are those not currently selected
    availableStaff = availableStaff.filter(m => !selectedPanelMembers.includes(String(m.upcc_id)));
    
    // Filter by query
    const filtered = availableStaff.filter(m => 
        (m.full_name && m.full_name.toLowerCase().includes(query)) ||
        (m.role && m.role.toLowerCase().includes(query)) ||
        (m.dept_name && m.dept_name.toLowerCase().includes(query))
    );
    
    if (filtered.length === 0) {
        dropdown.innerHTML = '<div style="padding:10px;font-size:12px;color:#888;">No members found for this department.</div>';
    } else {
        let html = '';
        filtered.slice(0, 15).forEach(m => {
            html += `<div class="dropdown-item" onmousedown="addPanelMember('${m.upcc_id}'); event.preventDefault();">
                        <div class="dropdown-item-title">${escH(m.full_name)} <span style="font-size:10px;color:#1b2b6b;background:#e2e9ff;padding:2px 6px;border-radius:10px;">${escH(m.role)}</span></div>
                        <div class="dropdown-item-sub">${escH(m.dept_name || 'No Department')}</div>
                     </div>`;
        });
        dropdown.innerHTML = html;
    }
}

function addPanelMember(id) {
    id = String(id);
    if (!selectedPanelMembers.includes(id)) {
        selectedPanelMembers.push(id);
        renderSelectedPanelMembers();
        
        const input = document.getElementById('panel-member-search');
        if (input) {
            input.value = '';
            input.focus();
        }
        filterPanelDropdown();
    }
}

function removePanelMember(id) {
    selectedPanelMembers = selectedPanelMembers.filter(m => m !== String(id));
    renderSelectedPanelMembers();
    filterPanelDropdown();
}

function showPanelDropdown() {
    const dropdown = document.getElementById('panel-member-dropdown');
    if (dropdown) {
        dropdown.classList.add('show');
        filterPanelDropdown();
    }
}

function hidePanelDropdown() {
    const dropdown = document.getElementById('panel-member-dropdown');
    if (dropdown) dropdown.classList.remove('show');
}

function validateHearingConfigForm() {
    // Lead Department is now optional
    if (selectedPanelMembers.length === 0) {
        alert('Please assign at least one panel member from the dropdown.');
        return false;
    }
    const hDate = document.getElementById('manage-hearing-date').value;
    if (!hDate) {
        alert('Please select a hearing date.');
        return false;
    }
    const hTime = document.getElementById('manage-hearing-time').value;
    if (!hTime) {
        alert('Please select a hearing time.');
        return false;
    }
    return true;
}

function escH(str) { return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.classList.remove('open');
}

// Committee panel
function openCpanel()  { document.getElementById('cpanel-overlay').classList.add('open'); }
function closeCpanel() { document.getElementById('cpanel-overlay').classList.remove('open'); }
function switchCpTab(tab, btn) {
    ['members','departments','add'].forEach(t => document.getElementById('cptab-'+t).style.display = t === tab ? '' : 'none');
    document.querySelectorAll('.cptab').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
}
function cpSearch() {
    const q = document.getElementById('cp-search').value.toLowerCase();
    document.querySelectorAll('#cp-member-grid .cp-card').forEach(card => {
        card.style.display = (card.dataset.name.includes(q) || card.dataset.role.includes(q) || card.dataset.email.includes(q)) ? '' : 'none';
    });
}
function showEditStrip(id)    { document.getElementById('es-'+id).classList.add('show'); }
function hideEditStrip(id)    { document.getElementById('es-'+id).classList.remove('show'); }
function showConfirmStrip(id) { document.getElementById('cs-'+id).classList.add('show'); }
function hideConfirmStrip(id) { document.getElementById('cs-'+id).classList.remove('show'); }

function openMemberEditModal(btn) {
    document.getElementById('member-edit-upcc-id').value = btn.dataset.upccId || '';
    document.getElementById('member-edit-full-name').value = btn.dataset.fullName || '';
    document.getElementById('member-edit-email').value = btn.dataset.email || '';

    const roleSelect = document.getElementById('member-edit-role');
    const roleValue = btn.dataset.role || 'Member';
    roleSelect.value = roleValue;

    const deptSelect = document.getElementById('member-edit-department');
    deptSelect.value = btn.dataset.departmentId || '';

    document.getElementById('member-edit-is-active').checked = (btn.dataset.isActive === '1');
    document.getElementById('modal-member-edit').classList.add('open');
}

async function openDepartmentModal(deptId) {
    const dept = departmentData[deptId];
    if (!dept) return;

    document.getElementById('dept-modal-id').value = deptId;
    document.getElementById('dept-modal-add-id').value = deptId;
    document.getElementById('dept-modal-title').textContent = dept.name;
    document.getElementById('dept-modal-subtitle').textContent = `${dept.count} member(s) in this department`;
    document.getElementById('dept-modal-name').value = dept.name;

    const memberWrap = document.getElementById('dept-modal-members');
    memberWrap.innerHTML = '<div style="color:#999;font-size:12px;padding:.3rem;">Loading members...</div>';
    try {
        const response = await fetch('AJAX/get_department_members.php?dept_id=' + encodeURIComponent(deptId));
        const data = await response.json();
        const members = Array.isArray(data.members) ? data.members : [];
        if (!members.length) {
            memberWrap.innerHTML = '<div class="dept-member-row"><div class="dept-member-meta"><div class="dept-member-name">This department has no staff.</div><div class="dept-member-sub">Add staff from the dropdown below.</div></div></div>';
        } else {
            memberWrap.innerHTML = members.map(m => `
                <div class="dept-member-row">
                    <div class="dept-member-meta">
                        <div class="dept-member-name">${escH(m.full_name)}</div>
                        <div class="dept-member-sub">${escH(m.role)} · ${String(m.is_active) === '1' ? 'Active' : 'Inactive'}</div>
                    </div>
                    <form method="post" action="upcc_cases.php" onsubmit="return confirm('Remove this staff member from the department?')">
                        <input type="hidden" name="action" value="set_member_department">
                        <input type="hidden" name="upcc_id" value="${m.upcc_id}">
                        <input type="hidden" name="department_id" value="">
                        <button type="submit" class="btn-ic deact" title="Remove from department" aria-label="Remove from department"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg></button>
                    </form>
                </div>
            `).join('');
        }
    } catch (_err) {
        const members = (departmentMembers[deptId] || []);
        if (!members.length) {
            memberWrap.innerHTML = '<div class="dept-member-row"><div class="dept-member-meta"><div class="dept-member-name">This department has no staff.</div><div class="dept-member-sub">Add staff from the dropdown below.</div></div></div>';
        } else {
            memberWrap.innerHTML = members.map(m => `
                <div class="dept-member-row">
                    <div class="dept-member-meta">
                        <div class="dept-member-name">${escH(m.full_name)}</div>
                        <div class="dept-member-sub">${escH(m.role)} · ${m.is_active ? 'Active' : 'Inactive'}</div>
                    </div>
                    <form method="post" action="upcc_cases.php" onsubmit="return confirm('Remove this staff member from the department?')">
                        <input type="hidden" name="action" value="set_member_department">
                        <input type="hidden" name="upcc_id" value="${m.upcc_id}">
                        <input type="hidden" name="department_id" value="">
                        <button type="submit" class="btn-ic deact" title="Remove from department" aria-label="Remove from department"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18"/><path d="M8 6V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6"/></svg></button>
                    </form>
                </div>
            `).join('');
        }
    }

    const addSelect = document.getElementById('dept-modal-add-member');
    // FIX: Only show staff who are active AND have NO department currently assigned
    const eligibleStaff = (committeeMembers || []).filter(m => String(m.is_active) === '1' && !m.department_id);
    
    addSelect.innerHTML = eligibleStaff.length
        ? '<option value="">-- Select staff --</option>' + eligibleStaff.map(m => {
            return `<option value="${m.upcc_id}">${escH(m.full_name)} (${escH(m.role)})</option>`;
        }).join('')
        : '<option value="">No unassigned staff available</option>';

    document.getElementById('modal-department-detail').classList.add('open');
}

function openAddDeptForm() {
    const input = document.getElementById('dept-add-name');
    if (input) input.value = '';
    document.getElementById('modal-department-add').classList.add('open');
    input?.focus();
}
function editDept(id, cur) {
    openDepartmentModal(id);
}
function toggleDept(id, newActive) { let f = document.createElement('form'); f.method='post'; f.action='upcc_cases.php'; f.innerHTML=`<input type="hidden" name="action" value="toggle_dept"><input type="hidden" name="dept_id" value="${id}"><input type="hidden" name="new_active" value="${newActive}">`; document.body.appendChild(f); f.submit(); }
function deleteDept(id) { if (confirm('Delete this department?')) { let f = document.createElement('form'); f.method='post'; f.action='upcc_cases.php'; f.innerHTML=`<input type="hidden" name="action" value="delete_dept"><input type="hidden" name="dept_id" value="${id}">`; document.body.appendChild(f); f.submit(); } }

// OTP
const digits = document.querySelectorAll('#otp-form-member .otp-digit');
const hidden  = document.getElementById('otp-hidden');
digits.forEach((input, i) => {
    input.addEventListener('input', () => { if (input.value.length > 1) input.value = input.value.slice(-1); if (input.value && i < digits.length-1) digits[i+1].focus(); hidden.value = [...digits].map(d => d.value).join(''); });
    input.addEventListener('keydown', e => { if (e.key === 'Backspace' && !input.value && i > 0) digits[i-1].focus(); });
});
document.getElementById('otp-form-member')?.addEventListener('submit', e => { if (hidden.value.length < 6) e.preventDefault(); });
<?php if (isset($_SESSION['upcc_member_otp'])): ?>document.getElementById('modal-otp').classList.add('open');<?php endif; ?>

// Auto-open add tab if OTP is pending
<?php if ($regSuccess === 'otp_sent' || isset($_SESSION['upcc_member_otp'])): ?>
openCpanel(); switchCpTab('add', document.querySelectorAll('.cptab')[2]);
<?php endif; ?>

const departmentMembers = <?= json_encode(array_reduce($members, function ($carry, $m) {
    if (!empty($m['department_id'])) {
        $deptId = (int)$m['department_id'];
        if (!isset($carry[$deptId])) $carry[$deptId] = [];
        $carry[$deptId][] = [
            'upcc_id' => (int)$m['upcc_id'],
            'full_name' => $m['full_name'],
            'role' => $m['role'],
            'is_active' => (bool)$m['is_active'],
        ];
    }
    return $carry;
}, []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const departmentData = <?= json_encode(array_reduce($departments, function ($carry, $d) use ($deptMemberCounts) {
    $carry[(int)$d['dept_id']] = [
        'name' => $d['dept_name'],
        'count' => (int)($deptMemberCounts[(int)$d['dept_id']] ?? 0),
    ];
    return $carry;
}, []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
const autoOpenDepartmentId = <?= (int)$autoOpenDepartmentId ?>;

<?php if ($committeeActionTriggered): ?>
openCpanel(); switchCpTab('<?= $committeeTargetTab ?>', document.querySelectorAll('.cptab')[<?= $committeeTargetTab === 'members' ? '0' : ($committeeTargetTab === 'departments' ? '1' : '2') ?>]);
<?php endif; ?>

if (autoOpenDepartmentId > 0) {
    openCpanel();
    switchCpTab('departments', document.querySelectorAll('.cptab')[1]);
    openDepartmentModal(autoOpenDepartmentId);
}

<?php if ($showStaffSuccess): ?>
let staffSuccessDone = false;
const finishStaffSuccess = () => {
    if (staffSuccessDone) return;
    staffSuccessDone = true;
    closeModal('modal-staff-success');
    openCpanel();
    switchCpTab('members', document.querySelectorAll('.cptab')[0]);
};

const staffSuccessModal = document.getElementById('modal-staff-success');
if (staffSuccessModal) {
    staffSuccessModal.addEventListener('click', finishStaffSuccess);
}

setTimeout(finishStaffSuccess, 1200);
<?php endif; ?>
<?php if (!empty($_GET['student_id'])): ?>
const targetStudentId = <?= json_encode($_GET['student_id']) ?>;
const activeStatuses = ['PENDING', 'UNDER_INVESTIGATION', 'UNDER_APPEAL'];
document.addEventListener('DOMContentLoaded', () => {
    let found = false;
    document.querySelectorAll('#cases-table tbody tr').forEach(row => {
        if (row.dataset.sid === targetStudentId && activeStatuses.includes(row.dataset.statusRaw)) {
            row.style.display = '';
            if (!found) {
                setTimeout(() => selectCase(row), 100);
                found = true;
            }
        } else {
            row.style.display = 'none';
        }
    });
});
<?php endif; ?>

<?php if (isset($_GET['filter'])): ?>
document.addEventListener('DOMContentLoaded', () => {
    const filter = <?= json_encode($_GET['filter']) ?>;
    const tab = document.querySelector(`.filter-tab[onclick*="'${filter}'"]`) || document.querySelector(`.filter-tab[onclick*='"${filter}"']`);
    if (tab) {
        filterCases(filter, tab);
    } else {
        const dummyBtn = document.createElement('button');
        dummyBtn.classList.add('filter-tab');
        document.querySelector('.filter-tabs').appendChild(dummyBtn);
        dummyBtn.style.display = 'none';
        filterCases(filter, dummyBtn);
    }
});
<?php endif; ?>

setInterval(syncQueueRows, 15000);
</script>
</body>
</html>