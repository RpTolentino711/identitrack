<?php
$f = 'api/upcc_case_live.php';
$c = file_get_contents($f);

$target = "    \$canNotify = true;
    if (\$latestRequest) {
        \$elapsed = max(0, (int)(\$latestRequest['elapsed_seconds'] ?? 0));
        if (\$elapsed < 30) {
            \$canNotify = false;
        }
    }

    if (\$canNotify) {
        db_exec(\"INSERT INTO upcc_panel_rejoin_requests (case_id, upcc_id, requested_at) VALUES (:c, :u, NOW())\", [':c' => \$caseId, ':u' => \$actorId]);
        upcc_log_case_activity(\$caseId, 'UPCC', \$actorId, 'REJOIN_REQUESTED');
    }";

$replace = "    if (\$latestRequest) {
        \$elapsed = max(0, (int)(\$latestRequest['elapsed_seconds'] ?? 0));
        if (\$elapsed < 30) {
            echo json_encode(['ok' => false, 'error' => 'Please wait ' . (30 - \$elapsed) . ' seconds before sending another request.']);
            exit;
        }
    }

    db_exec(\"INSERT INTO upcc_panel_rejoin_requests (case_id, upcc_id, requested_at) VALUES (:c, :u, NOW())\", [':c' => \$caseId, ':u' => \$actorId]);
    upcc_log_case_activity(\$caseId, 'UPCC', \$actorId, 'REJOIN_REQUESTED');";

$c = str_replace($target, $replace, $c);
file_put_contents($f, $c);
echo "Replaced.";
