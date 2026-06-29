<?php
$f = 'admin/upcc_case_view.php';
$c = file_get_contents($f);

$old = 'function pingPresence() {
    const elapsed = Date.now() - lastActivityTime;

    // ── AUTO-PAUSE at 5 minutes of idle ──────────────────────────────────
    if (elapsed >= 300000 && !autoPauseFired) {';

$new = 'function pingPresence() {
    const elapsed = Date.now() - lastActivityTime;

    // ── Only track idle when hearing is LIVE (not already paused) ────────
    if (_currentPauseState || !IS_HEARING_OPEN) {
        // Hearing is already paused — hide modal if it was open, reset timer
        if (warningModalShown) { hideIdleWarningModal(); warningModalShown = false; }
        autoPauseFired = false;
        // Still ping presence so admin shows as online
        const fdp = new FormData();
        fdp.append(\'action\', \'ping_presence\');
        fdp.append(\'status\', \'ADMITTED\');
        fdp.append(\'actor\', \'admin\');
        fetch(`../api/upcc_case_live.php?case_id=${CASE_ID}&actor=admin`, { method:\'POST\', body:fdp }).catch(() => {});
        return;
    }

    // ── AUTO-PAUSE at 5 minutes of idle ──────────────────────────────────
    if (elapsed >= 300000 && !autoPauseFired) {';

if (strpos($c, $old) !== false) {
    $c = str_replace($old, $new, $c);
    file_put_contents($f, $c);
    echo "OK";
} else {
    echo "NOT FOUND";
}
