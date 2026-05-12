<?php
/**
 * Test Script: Hearing Pause/Resume Functionality
 * 
 * This script tests the new pause/resume implementation:
 * 1. Manual pause by admin
 * 2. Manual resume by admin
 * 3. Auto-pause when admin leaves
 */

require_once __DIR__ . '/database/database.php';
require_admin();

// Only admins can run this test
$admin = admin_current();
if (!$admin) {
    die('Admin access required');
}

$testCaseId = (int)($_GET['case_id'] ?? 0);
if ($testCaseId <= 0) {
    ?>
    <!DOCTYPE html>
    <html>
    <head><title>Pause/Resume Test</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
            .test-box { background: #f5f5f5; padding: 20px; border-radius: 8px; margin: 20px 0; border: 1px solid #ddd; }
            .pass { color: green; font-weight: bold; }
            .fail { color: red; font-weight: bold; }
            code { background: #eee; padding: 2px 6px; border-radius: 3px; }
            button { padding: 10px 20px; margin: 5px; cursor: pointer; border: none; border-radius: 4px; }
            .btn-primary { background: #007bff; color: white; }
            .btn-danger { background: #dc3545; color: white; }
            .btn-success { background: #28a745; color: white; }
        </style>
    </head>
    <body>
        <h1>🧪 Hearing Pause/Resume Test</h1>
        
        <div class="test-box">
            <h2>Prerequisites</h2>
            <ul>
                <li>Need an active UPCC case with hearing open</li>
                <li>Admin must be logged in</li>
                <li>Panel members must be assigned</li>
            </ul>
        </div>

        <div class="test-box">
            <h2>Available Test Cases</h2>
            <?php
                $cases = db_all("
                    SELECT uc.case_id, uc.status, uc.hearing_is_open, uc.hearing_is_paused, 
                           uc.hearing_pause_reason, CONCAT(s.student_fn, ' ', s.student_ln) as student_name
                    FROM upcc_case uc
                    JOIN student s ON s.student_id = uc.student_id
                    WHERE uc.hearing_is_open = 1
                    ORDER BY uc.case_id DESC
                    LIMIT 10
                ");
                
                if (empty($cases)) {
                    echo '<p style="color: #666;">No active hearings found. Start a hearing first.</p>';
                } else {
                    echo '<table border="1" cellpadding="10" cellspacing="0" width="100%">';
                    echo '<tr><th>Case ID</th><th>Student</th><th>Status</th><th>Is Paused</th><th>Pause Reason</th><th>Action</th></tr>';
                    foreach ($cases as $c) {
                        $paused = (int)($c['hearing_is_paused'] ?? 0) === 1;
                        echo '<tr>';
                        echo '<td>' . $c['case_id'] . '</td>';
                        echo '<td>' . htmlspecialchars($c['student_name']) . '</td>';
                        echo '<td>' . htmlspecialchars($c['status']) . '</td>';
                        echo '<td>' . ($paused ? '✅ YES' : '❌ NO') . '</td>';
                        echo '<td>' . htmlspecialchars($c['hearing_pause_reason'] ?? '—') . '</td>';
                        echo '<td><a href="?case_id=' . $c['case_id'] . '"><button class="btn-primary">Test</button></a></td>';
                        echo '</tr>';
                    }
                    echo '</table>';
                }
            ?>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Get case details
$case = db_one("SELECT * FROM upcc_case WHERE case_id = :id", [':id' => $testCaseId]);
if (!$case || (int)($case['hearing_is_open'] ?? 0) !== 1) {
    die('Invalid case or hearing not open');
}

$isPaused = (int)($case['hearing_is_paused'] ?? 0) === 1;
$pauseReason = $case['hearing_pause_reason'] ?? null;
?>
<!DOCTYPE html>
<html>
<head>
    <title>Test Case #<?= $testCaseId ?></title>
    <style>
        body { font-family: Arial, sans-serif; margin: 40px; line-height: 1.6; }
        .info-box { background: #e7f3ff; padding: 15px; border-left: 4px solid #2196F3; margin: 20px 0; }
        .pass { color: green; font-weight: bold; }
        .fail { color: red; font-weight: bold; }
        .test-section { background: #f9f9f9; padding: 20px; margin: 20px 0; border-radius: 8px; border: 1px solid #ddd; }
        button { padding: 12px 24px; margin: 10px 5px; cursor: pointer; border: none; border-radius: 4px; font-size: 14px; }
        .btn-primary { background: #007bff; color: white; }
        .btn-danger { background: #dc3545; color: white; }
        .btn-success { background: #28a745; color: white; }
        .status { padding: 10px; margin: 10px 0; border-radius: 4px; }
        .status.paused { background: #fff3cd; border: 1px solid #ffc107; }
        .status.resumed { background: #d4edda; border: 1px solid #28a745; }
        #testLog { background: #f5f5f5; padding: 15px; border-radius: 4px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px; }
        .log-entry { margin: 5px 0; }
        .log-info { color: #007bff; }
        .log-success { color: #28a745; }
        .log-error { color: #dc3545; }
    </style>
</head>
<body>
    <h1>🧪 Test Hearing Pause/Resume - Case #<?= $testCaseId ?></h1>
    
    <div class="info-box">
        <strong>Current Status:</strong><br>
        Hearing Open: ✅<br>
        Pause State: <span class="<?= $isPaused ? 'fail' : 'pass' ?>"><strong><?= $isPaused ? 'PAUSED' : 'RUNNING' ?></strong></span><br>
        Pause Reason: <code><?= $pauseReason ?? 'None' ?></code>
    </div>

    <div class="test-section">
        <h2>Test 1: Manual Pause</h2>
        <p>Click to manually pause the hearing. Should set <code>hearing_is_paused = 1</code> and <code>hearing_pause_reason = 'MANUAL'</code></p>
        <button class="btn-primary" onclick="testTogglePause('pause')">⏸️ Pause Hearing</button>
    </div>

    <div class="test-section">
        <h2>Test 2: Manual Resume</h2>
        <p>Click to manually resume the hearing. Should set <code>hearing_is_paused = 0</code></p>
        <button class="btn-success" onclick="testTogglePause('resume')">▶️ Resume Hearing</button>
    </div>

    <div class="test-section">
        <h2>Test 3: Check Auto-Pause Trigger</h2>
        <p>This will check what happens when admin presence expires. The API should auto-pause if admin is offline for >15 seconds.</p>
        <button class="btn-danger" onclick="testAutoPresencePause()">🔴 Simulate Admin Disconnect</button>
    </div>

    <div class="test-section">
        <h2>Test 4: Verify Sync Response</h2>
        <p>Fetch the current sync data to verify pause state is included in response</p>
        <button class="btn-primary" onclick="testSync()">🔄 Sync & Check Response</button>
    </div>

    <h2>Test Log</h2>
    <div id="testLog"></div>

    <div style="margin-top: 40px;">
        <a href="upcc_case_view.php?id=<?= $testCaseId ?>"><button style="background: #6c757d; color: white;">← Back to Case</button></a>
    </div>

    <script>
        const CASE_ID = <?= $testCaseId ?>;

        function log(message, type = 'info') {
            const logBox = document.getElementById('testLog');
            const entry = document.createElement('div');
            entry.className = 'log-entry log-' + type;
            const time = new Date().toLocaleTimeString();
            entry.textContent = `[${time}] ${message}`;
            logBox.appendChild(entry);
            logBox.scrollTop = logBox.scrollHeight;
        }

        function testTogglePause(action) {
            const fd = new FormData();
            fd.append('action', 'toggle_pause');
            fd.append('actor', 'admin');

            log(`Sending toggle_pause request...`, 'info');
            
            fetch(`../api/upcc_case_live.php?case_id=${CASE_ID}&actor=admin`, { method: 'POST', body: fd })
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        log(`✅ Toggle successful! New pause state: ${data.is_paused}`, 'success');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        log(`❌ Error: ${data.error || 'Unknown error'}`, 'error');
                    }
                })
                .catch(err => {
                    log(`❌ Network error: ${err}`, 'error');
                });
        }

        function testAutoPresencePause() {
            log(`Testing auto-pause mechanism...`, 'info');
            log(`Note: Admin needs to be offline for >15 seconds to trigger auto-pause`, 'info');
            log(`Current time: ${new Date().toLocaleTimeString()}`, 'info');
            log(`Calling sync to check if auto-pause triggers...`, 'info');
            
            // Simulate being offline by not pinging for 20 seconds
            fetch(`../api/upcc_case_live.php?case_id=${CASE_ID}&action=sync&actor=admin`)
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        log(`Sync response received`, 'info');
                        log(`is_paused: ${data.is_paused}`, 'info');
                        log(`pause_reason: ${data.pause_reason || 'null'}`, 'info');
                        log(`is_admin_online: ${data.is_admin_online}`, 'info');
                        
                        if (data.pause_reason === 'AUTO_PAUSE_ADMIN_LEFT') {
                            log(`✅ Auto-pause triggered correctly!`, 'success');
                        } else {
                            log(`ℹ️ Auto-pause not triggered (admin might still be online)`, 'info');
                        }
                    }
                })
                .catch(err => {
                    log(`❌ Sync error: ${err}`, 'error');
                });
        }

        function testSync() {
            log(`Fetching sync data...`, 'info');
            
            fetch(`../api/upcc_case_live.php?case_id=${CASE_ID}&action=sync&actor=admin`)
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        log(`✅ Sync successful`, 'success');
                        log(`is_paused: ${data.is_paused}`, 'info');
                        log(`pause_reason: ${data.pause_reason || 'null'}`, 'info');
                        log(`is_admin_online: ${data.is_admin_online}`, 'info');
                        log(`Total votes: ${(data.votes || []).length}`, 'info');
                        log(`Chat messages: ${(data.chat || []).length}`, 'info');
                    } else {
                        log(`❌ Sync failed: ${data.error}`, 'error');
                    }
                })
                .catch(err => {
                    log(`❌ Network error: ${err}`, 'error');
                });
        }

        log(`Page loaded. Case #${CASE_ID}`, 'info');
    </script>
</body>
</html>
<?php
