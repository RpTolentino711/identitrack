<?php
require_once __DIR__ . '/../database/database.php';
require_admin();
ensure_hearing_workflow_schema();

$activeSidebar = 'appeals';
$admin = admin_current();
$fullName = trim((string)($admin['full_name'] ?? ''));
if ($fullName === '') {
    $fullName = (string)($admin['username'] ?? 'User');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'review_appeal') {
    $appealId = (int)($_POST['appeal_id'] ?? 0);
    $decision = strtoupper(trim((string)($_POST['decision'] ?? '')));
    $notes = trim((string)($_POST['admin_response'] ?? ''));

    $appeal = db_one(
        "SELECT appeal_id, student_id, offense_id, case_id, appeal_kind, status
         FROM student_appeal_request
         WHERE appeal_id = :id
         LIMIT 1",
        [':id' => $appealId]
    );

    if ($appeal && in_array($decision, ['APPROVED', 'REJECTED'], true)) {
        db_exec(
            "UPDATE student_appeal_request
             SET status = :status,
                 admin_response = :response,
                 decided_by = :admin_id,
                 decided_at = NOW()
             WHERE appeal_id = :id",
            [
                ':status' => $decision,
                ':response' => $notes,
                ':admin_id' => (int)$admin['admin_id'],
                ':id' => $appealId,
            ]
        );

        $studentId = (string)$appeal['student_id'];
        $offenseId = (int)$appeal['offense_id'];
        $caseId = (int)$appeal['case_id'];

        if ($decision === 'APPROVED') {
            if ($appeal['appeal_kind'] === 'UPCC_CASE' && $caseId > 0) {
                db_exec("UPDATE upcc_case SET status = 'CANCELLED' WHERE case_id = :cid AND student_id = :sid", [':cid' => $caseId, ':sid' => $studentId]);
                db_exec("UPDATE community_service_requirement SET status = 'CANCELLED' WHERE related_case_id = :cid AND student_id = :sid", [':cid' => $caseId, ':sid' => $studentId]);
                // Void associated offenses so they don't count towards the student's major/minor offense tally anymore
                db_exec("UPDATE offense SET status = 'VOID' WHERE offense_id IN (SELECT offense_id FROM upcc_case_offense WHERE case_id = :cid)", [':cid' => $caseId]);
            } else if ($appeal['appeal_kind'] === 'OFFENSE' && $offenseId > 0) {
                db_exec("UPDATE offense SET status = 'VOID' WHERE offense_id = :oid AND student_id = :sid", [':oid' => $offenseId, ':sid' => $studentId]);
            }
        } else if ($decision === 'REJECTED') {
            if ($appeal['appeal_kind'] === 'UPCC_CASE' && $caseId > 0) {
                db_exec("UPDATE upcc_case SET status = 'RESOLVED' WHERE case_id = :cid AND student_id = :sid", [':cid' => $caseId, ':sid' => $studentId]);
                
                // If Category 4 or 5, freeze account immediately upon rejection
                $caseRow = db_one("SELECT decided_category FROM upcc_case WHERE case_id = :cid", [':cid' => $caseId]);
                if ($caseRow && in_array((int)$caseRow['decided_category'], [4, 5], true)) {
                    db_exec("UPDATE student SET is_active = 0 WHERE student_id = :sid", [':sid' => $studentId]);
                }

                db_exec("UPDATE community_service_requirement SET status = 'ACTIVE' WHERE related_case_id = :cid AND student_id = :sid AND status = 'PENDING_ACCEPTANCE'", [':cid' => $caseId, ':sid' => $studentId]);
            } else if ($appeal['appeal_kind'] === 'OFFENSE' && $offenseId > 0) {
                db_exec("UPDATE offense SET status = 'RESOLVED' WHERE offense_id = :oid AND student_id = :sid", [':oid' => $offenseId, ':sid' => $studentId]);
            }
        }

        redirect('appeals.php?msg=' . strtolower($decision));
    }

    redirect('appeals.php?msg=error');
}

$filter = strtoupper(trim((string)($_GET['filter'] ?? 'PENDING')));
if (!in_array($filter, ['PENDING', 'REVIEWING', 'APPROVED', 'REJECTED', 'ALL'], true)) {
    $filter = 'PENDING';
}

$pendingCount = (int)(db_one("SELECT COUNT(*) AS c FROM student_appeal_request WHERE status IN ('PENDING','REVIEWING')")['c'] ?? 0);
$approvedCount = (int)(db_one("SELECT COUNT(*) AS c FROM student_appeal_request WHERE status = 'APPROVED'")['c'] ?? 0);
$rejectedCount = (int)(db_one("SELECT COUNT(*) AS c FROM student_appeal_request WHERE status = 'REJECTED'")['c'] ?? 0);

$where = '';
$params = [];
if ($filter !== 'ALL') {
    $where = 'WHERE sar.status = :status';
    $params[':status'] = $filter;
}

$appeals = db_all(
    "SELECT
        sar.appeal_id,
        sar.student_id,
        sar.offense_id,
        sar.case_id,
        sar.appeal_kind,
        sar.reason,
        sar.status,
        sar.admin_response,
        sar.attachment_path,
        sar.attachment_name,
        sar.created_at,
        sar.decided_at,
        CONCAT(s.student_fn, ' ', s.student_ln) AS student_name,
        ot.code AS offense_code,
        ot.name AS offense_name,
        uc.decided_category,
        uc.final_decision
     FROM student_appeal_request sar
     JOIN student s ON s.student_id = sar.student_id
     LEFT JOIN offense o ON o.offense_id = sar.offense_id
     LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
     LEFT JOIN upcc_case uc ON uc.case_id = sar.case_id
     $where
     ORDER BY sar.created_at DESC, sar.appeal_id DESC
     LIMIT 100",
    $params
);

function appeal_kind_label(string $kind): string
{
    return $kind === 'UPCC_CASE' ? 'UPCC Case' : 'Offense';
}

function appeal_status_class(string $status): string
{
    return match ($status) {
        'APPROVED' => 'status-approved',
        'REJECTED' => 'status-rejected',
        default => 'status-pending',
    };
}

function fmt_dt(?string $value): string
{
    if (!$value) {
        return '—';
    }
    return date('M d, Y g:i A', strtotime($value));
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Appeals Review | SDO Web Portal</title>
  <style>
    * { box-sizing: border-box; }
    body { margin: 0; font-family: 'Segoe UI', Tahoma, Arial, sans-serif; background: #f8f9fa; color: #1b2244; }
    .admin-shell { min-height: calc(100vh - 72px); display: grid; grid-template-columns: 240px 1fr; }
    .wrap { min-height: 100%; }
    .page-header { background: #fff; border-bottom: 1px solid #e0e0e0; padding: 28px 32px; display: flex; justify-content: space-between; gap: 16px; align-items: center; }
    .page-header h1 { margin: 0; font-size: 28px; font-weight: 700; color: #1a1a1a; }
    .welcome { margin-top: 4px; color: #6c757d; font-size: 15px; }
    .content-area { padding: 24px 32px; }
    .stats { display: grid; grid-template-columns: repeat(3, minmax(160px, 1fr)); gap: 12px; margin-bottom: 18px; }
    .stat { background: #fff; border: 1px solid #dee2e6; border-radius: 12px; padding: 16px; }
    .stat .label { font-size: 12px; color: #6c757d; text-transform: uppercase; letter-spacing: .05em; }
    .stat .value { font-size: 26px; font-weight: 800; margin-top: 6px; color: #1a1a1a; }
    .tabs { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
    .tab { display: inline-flex; align-items: center; gap: 8px; padding: 10px 16px; border-radius: 999px; border: 1px solid #dee2e6; text-decoration: none; color: #495057; font-weight: 700; background: #fff; }
    .tab.active { background: #3b4a9e; border-color: #3b4a9e; color: #fff; }
    .badge { display: inline-flex; align-items: center; justify-content: center; min-width: 22px; padding: 0 7px; height: 22px; border-radius: 999px; background: #dc3545; color: #fff; font-size: 11px; font-weight: 800; }
    .card { background: #fff; border: 1px solid #dee2e6; border-radius: 14px; padding: 18px; margin-bottom: 14px; box-shadow: 0 2px 4px rgba(0,0,0,.04); }
    .card-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 10px; }
    .student { font-size: 18px; font-weight: 800; color: #1a1a1a; }
    .meta { color: #6c757d; font-size: 13px; margin-top: 4px; line-height: 1.45; }
    .status { display: inline-flex; padding: 4px 10px; border-radius: 999px; font-size: 11px; font-weight: 800; text-transform: uppercase; }
    .status-pending { background: #fff3cd; color: #856404; }
    .status-approved { background: #d1e7dd; color: #0a3622; }
    .status-rejected { background: #f8d7da; color: #842029; }
    .reason, .response { margin-top: 12px; padding: 12px; border-radius: 10px; background: #f8f9fa; border: 1px solid #e9ecef; color: #344054; line-height: 1.5; }
    .response { background: #eff6ff; border-color: #dbeafe; }
    .actions { margin-top: 14px; display: flex; flex-wrap: wrap; gap: 10px; }
    .btn { border: 0; border-radius: 10px; padding: 10px 16px; font-weight: 800; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; }
    .btn-approve { background: #198754; color: #fff; }
    .btn-reject { background: #dc3545; color: #fff; }
    .btn-neutral { background: #e9ecef; color: #1a1a1a; }
    .empty { background: #fff; border: 1px dashed #cbd5e1; border-radius: 14px; padding: 28px; text-align: center; color: #64748b; }
    @media (max-width: 900px) {
      .admin-shell { grid-template-columns: 1fr; }
      .page-header, .content-area { padding-left: 16px; padding-right: 16px; }
      .stats { grid-template-columns: 1fr; }
    }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>
  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>
    <main class="wrap">
      <section class="page-header">
        <div>
          <h1>Appeals Review</h1>
          <div class="welcome">Welcome, <?php echo e($fullName); ?></div>
        </div>
      </section>

      <div class="content-area">
        <div class="stats">
          <div class="stat"><div class="label">Pending</div><div class="value"><?php echo $pendingCount; ?></div></div>
          <div class="stat"><div class="label">Approved</div><div class="value"><?php echo $approvedCount; ?></div></div>
          <div class="stat"><div class="label">Rejected</div><div class="value"><?php echo $rejectedCount; ?></div></div>
        </div>

        <div class="tabs">
          <a class="tab <?php echo $filter === 'PENDING' ? 'active' : ''; ?>" href="?filter=PENDING">Pending <span class="badge"><?php echo $pendingCount; ?></span></a>
          <a class="tab <?php echo $filter === 'REVIEWING' ? 'active' : ''; ?>" href="?filter=REVIEWING">Reviewing</a>
          <a class="tab <?php echo $filter === 'APPROVED' ? 'active' : ''; ?>" href="?filter=APPROVED">Approved</a>
          <a class="tab <?php echo $filter === 'REJECTED' ? 'active' : ''; ?>" href="?filter=REJECTED">Rejected</a>
          <a class="tab <?php echo $filter === 'ALL' ? 'active' : ''; ?>" href="?filter=ALL">All</a>
        </div>

        <?php if (empty($appeals)): ?>
          <div class="empty">No appeal requests found for this filter.</div>
        <?php else: ?>
          <?php foreach ($appeals as $appeal): ?>
            <?php
              $status = strtoupper((string)($appeal['status'] ?? 'PENDING'));
              $kind = strtoupper((string)($appeal['appeal_kind'] ?? 'OFFENSE'));
              $statusClass = appeal_status_class($status);
            ?>
            <div class="card">
              <div class="card-head">
                <div>
                  <div class="student"><?php echo e((string)($appeal['student_name'] ?? 'Student')); ?></div>
                  <div class="meta">
                    <?php echo e(appeal_kind_label($kind)); ?> Appeal • Student ID: <?php echo e((string)$appeal['student_id']); ?> • Submitted <?php echo e(fmt_dt((string)($appeal['created_at'] ?? ''))); ?>
                  </div>
                  <div class="meta">
                    <?php if ($kind === 'UPCC_CASE'): ?>
                      UPCC Case #<?php echo (int)($appeal['case_id'] ?? 0); ?> • Category <?php echo (int)($appeal['decided_category'] ?? 0); ?>
                    <?php else: ?>
                      Offense #<?php echo (int)($appeal['offense_id'] ?? 0); ?> <?php echo trim((string)($appeal['offense_code'] ?? '') . ' ' . (string)($appeal['offense_name'] ?? '')); ?>
                    <?php endif; ?>
                  </div>
                </div>
                <span class="status <?php echo $statusClass; ?>"><?php echo e($status); ?></span>
              </div>

              <div class="reason"><?php echo nl2br(e((string)($appeal['reason'] ?? ''))); ?></div>

              <?php if (!empty($appeal['attachment_path'])): ?>
                <div style="margin-top: 12px; display: flex; gap: 8px; flex-wrap: wrap;">
                  <a href="../<?php echo e((string)$appeal['attachment_path']); ?>" target="_blank" class="btn btn-neutral" style="font-size: 13px;">
                    📄 View Attachment: <?php echo e((string)$appeal['attachment_name']); ?>
                  </a>
                  <a href="print_appeal.php?id=<?php echo (int)$appeal['appeal_id']; ?>" target="_blank" class="btn btn-neutral" style="font-size: 13px;">
                    🖨️ Print Appeal
                  </a>
                </div>
              <?php else: ?>
                <div style="margin-top: 12px;">
                  <a href="print_appeal.php?id=<?php echo (int)$appeal['appeal_id']; ?>" target="_blank" class="btn btn-neutral" style="font-size: 13px;">
                    🖨️ Print Appeal
                  </a>
                </div>
              <?php endif; ?>

              <?php if (!empty($appeal['admin_response'])): ?>
                <div class="response">
                  <strong>Admin Response</strong><br />
                  <?php echo nl2br(e((string)$appeal['admin_response'])); ?>
                </div>
              <?php endif; ?>

              <?php if (in_array($status, ['PENDING', 'REVIEWING'], true)): ?>
                <form method="post" class="actions">
                  <input type="hidden" name="action" value="review_appeal" />
                  <input type="hidden" name="appeal_id" value="<?php echo (int)$appeal['appeal_id']; ?>" />
                  <textarea name="admin_response" rows="3" placeholder="Admin response / appeal notes" style="width:100%; padding:12px; border-radius:10px; border:1px solid #d1d5db; font:inherit; resize:vertical;"></textarea>
                  <div class="actions" style="width:100%; margin-top:0;">
                    <button class="btn btn-approve" name="decision" value="APPROVED" type="submit">Approve Appeal</button>
                    <button class="btn btn-reject" name="decision" value="REJECTED" type="submit">Reject Appeal</button>
                  </div>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </main>
  </div>
</body>
</html>
