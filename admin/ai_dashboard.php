<?php
/**
 * IdentiTrack Admin AI Monitoring & Decision Support Dashboard
 * Provides AI service status, decision-support analysis interface, and audit trail logs.
 */
session_start();
require_once __DIR__ . '/../database/database.php';
require_once __DIR__ . '/../includes/AIClient.php';

$aiClient = new AIClient();
$health = $aiClient->getHealthStatus();

// Handle AJAX AI Analysis Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'analyze') {
    header('Content-Type: application/json');
    $desc = trim((string)($_POST['offense_description'] ?? ''));
    $studentId = trim((string)($_POST['student_id'] ?? ''));
    
    $analysis = $aiClient->analyzeOffense($desc, $studentId);
    echo json_encode($analysis);
    exit;
}

// Handle AJAX Human Decision Logging
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_decision') {
    header('Content-Type: application/json');
    $reqId = trim((string)($_POST['request_id'] ?? ''));
    $decision = strtoupper(trim((string)($_POST['human_decision'] ?? 'ACCEPTED')));
    
    $aiClient->logAnalysis($reqId, null, [], $decision);
    echo json_encode(['success' => true]);
    exit;
}

// Fetch Audit Log Metrics
$logs = [];
$totalCount = 0;
$acceptedCount = 0;
try {
    $logs = db_all("SELECT * FROM ai_analysis_log ORDER BY id DESC LIMIT 50");
    $cntRow = db_one("SELECT COUNT(*) as total, SUM(CASE WHEN human_decision = 'ACCEPTED' THEN 1 ELSE 0 END) as accepted FROM ai_analysis_log");
    $totalCount = (int)($cntRow['total'] ?? 0);
    $acceptedCount = (int)($cntRow['accepted'] ?? 0);
} catch (\Throwable $e) {}

$acceptanceRate = $totalCount > 0 ? round(($acceptedCount / $totalCount) * 100, 1) : 100.0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Decision Support & Monitoring | IdentiTrack</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root {
            --bg-dark: #0f172a;
            --panel-bg: rgba(30, 41, 59, 0.7);
            --border-glass: rgba(255, 255, 255, 0.1);
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
            --accent-blue: #3b82f6;
            --accent-emerald: #10b981;
            --accent-amber: #f59e0b;
            --accent-rose: #f43f5e;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg-dark); color: var(--text-main); min-height: 100vh; padding: 24px; }
        .container { max-width: 1200px; margin: 0 auto; display: flex; flex-direction: column; gap: 24px; }
        
        .header { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 16px; background: var(--panel-bg); padding: 20px 24px; border-radius: 16px; border: 1px solid var(--border-glass); backdrop-filter: blur(12px); }
        .title-block { display: flex; align-items: center; gap: 12px; }
        .title-block h1 { font-size: 20px; font-weight: 800; }
        
        .status-badge { display: inline-flex; align-items: center; gap: 8px; padding: 6px 16px; border-radius: 20px; font-size: 13px; font-weight: 700; }
        .status-online { background: rgba(16, 185, 129, 0.15); color: #6ee7b7; border: 1px solid rgba(16, 185, 129, 0.3); }
        .status-offline { background: rgba(244, 63, 94, 0.15); color: #fca5a5; border: 1px solid rgba(244, 63, 94, 0.3); }
        
        .capstone-banner { background: linear-gradient(135deg, rgba(59, 130, 246, 0.1), rgba(16, 185, 129, 0.1)); border: 1px solid rgba(59, 130, 246, 0.3); border-radius: 14px; padding: 16px 20px; font-size: 13px; line-height: 1.6; color: #cbd5e1; }
        .capstone-banner strong { color: #93c5fd; }

        .metrics-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 16px; }
        .metric-card { background: var(--panel-bg); border: 1px solid var(--border-glass); padding: 20px; border-radius: 14px; }
        .metric-label { font-size: 12px; color: var(--text-muted); font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; }
        .metric-value { font-size: 24px; font-weight: 800; margin-top: 8px; color: #ffffff; }

        .grid-layout { display: grid; grid-template-columns: 1fr 1fr; gap: 24px; }
        @media (max-width: 900px) { .grid-layout { grid-template-columns: 1fr; } }

        .card { background: var(--panel-bg); border: 1px solid var(--border-glass); border-radius: 16px; padding: 24px; }
        .card-title { font-size: 16px; font-weight: 700; margin-bottom: 16px; display: flex; align-items: center; gap: 8px; }

        textarea { width: 100%; height: 110px; background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border-glass); border-radius: 10px; padding: 12px; color: #fff; font-size: 13px; resize: vertical; outline: none; }
        textarea:focus { border-color: var(--accent-blue); }

        .btn { padding: 10px 20px; border-radius: 10px; font-weight: 700; font-size: 13px; border: none; cursor: pointer; transition: all 0.2s; }
        .btn-primary { background: var(--accent-blue); color: #fff; }
        .btn-primary:hover { background: #2563eb; }
        .btn-success { background: var(--accent-emerald); color: #fff; }
        .btn-danger { background: var(--accent-rose); color: #fff; }

        .result-card { background: rgba(15, 23, 42, 0.6); border: 1px solid var(--border-glass); border-radius: 12px; padding: 18px; display: none; flex-direction: column; gap: 14px; margin-top: 16px; }
        .result-row { display: flex; flex-direction: column; gap: 4px; }
        .result-label { font-size: 11px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; }
        .result-val { font-size: 13px; color: var(--text-main); font-weight: 600; line-height: 1.5; }
        .warning-tag { background: rgba(245, 158, 11, 0.15); color: #fcd34d; border: 1px solid rgba(245, 158, 11, 0.3); padding: 8px 12px; border-radius: 8px; font-size: 12px; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; }

        table { width: 100%; border-collapse: collapse; margin-top: 12px; font-size: 12px; }
        th, td { padding: 12px; text-align: left; border-bottom: 1px solid var(--border-glass); }
        th { color: var(--text-muted); font-weight: 700; text-transform: uppercase; }
    </style>
</head>
<body>

<div class="container">
    <!-- HEADER -->
    <div class="header">
        <div class="title-block">
            <span style="font-size:24px;">🧠</span>
            <div>
                <h1>AI Decision Support & Monitoring Dashboard</h1>
                <div style="font-size:12px; color:var(--text-muted);">NU Lipa Student Handbook Disciplinary Intelligence System</div>
            </div>
        </div>
        <div class="status-badge <?= $health['online'] ? 'status-online' : 'status-offline' ?>">
            <span><?= $health['online'] ? '●' : '○' ?></span>
            <span><?= htmlspecialchars($health['provider']) ?></span>
        </div>
    </div>

    <!-- CAPSTONE MANDATORY DISCLAIMER -->
    <div class="capstone-banner">
        <strong>📌 Capstone System Notice:</strong> The AI component functions strictly as a <strong>decision-support mechanism</strong>. It analyzes offense descriptions and provides recommendations based on the Student Handbook, but it does <strong>not</strong> independently impose disciplinary sanctions. Final decisions remain under the sole authority of authorized university personnel (SDO / UPCC Committee).
    </div>

    <!-- METRICS GRID -->
    <div class="metrics-grid">
        <div class="metric-card">
            <div class="metric-label">AI Engine Mode</div>
            <div class="metric-value" style="font-size:16px; color:#60a5fa;"><?= htmlspecialchars($health['provider']) ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Total AI Analyses</div>
            <div class="metric-value"><?= number_format($totalCount) ?></div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Human Acceptance Rate</div>
            <div class="metric-value" style="color:#34d399;"><?= $acceptanceRate ?>%</div>
        </div>
        <div class="metric-card">
            <div class="metric-label">Human Oversight Status</div>
            <div class="metric-value" style="font-size:16px; color:#fcd34d;">100% Human In The Loop</div>
        </div>
    </div>

    <!-- INTERACTIVE AI OFFENSE ANALYZER & AUDIT TRAIL -->
    <div class="grid-layout">
        <!-- ANALYZER WIDGET -->
        <div class="card">
            <div class="card-title"><span>⚡</span> Test AI Offense Analyzer</div>
            <div style="margin-bottom:12px; font-size:12px; color:var(--text-muted);">Enter an offense description to test decision support suggestions:</div>
            
            <textarea id="offenseInput" placeholder="e.g. Student was caught using a mobile phone during an examination."></textarea>
            
            <div style="margin-top:12px; display:flex; justify-content:flex-end;">
                <button class="btn btn-primary" onclick="runAnalysis()">Analyze with AI</button>
            </div>

            <!-- RESULT DISPLAY -->
            <div id="resultCard" class="result-card">
                <div class="warning-tag">
                    <span>⚠️</span> <span>HUMAN REVIEW REQUIRED — Decision Support Only</span>
                </div>

                <div class="result-row">
                    <div class="result-label">Possible Classification</div>
                    <div class="result-val" id="resClass">-</div>
                </div>

                <div class="result-row">
                    <div class="result-label">Confidence Score</div>
                    <div class="result-val" id="resConf" style="color:#60a5fa;">-</div>
                </div>

                <div class="result-row">
                    <div class="result-label">Handbook Basis</div>
                    <div class="result-val" id="resHandbook">-</div>
                </div>

                <div class="result-row">
                    <div class="result-label">Recommended Disciplinary Intervention</div>
                    <div class="result-val" id="resIntervention" style="color:#34d399;">-</div>
                </div>

                <div class="result-row">
                    <div class="result-label">AI Explanation & Reasoning</div>
                    <div class="result-val" id="resExplanation" style="font-size:12px; line-height:1.5;">-</div>
                </div>

                <div style="display:flex; gap:10px; margin-top:8px; flex-wrap:wrap;">
                    <button class="btn btn-success" onclick="recordDecision('ACCEPTED')">Accept Recommendation</button>
                    <button class="btn btn-danger" onclick="recordDecision('REJECTED')">Reject Recommendation</button>
                </div>
            </div>
        </div>

        <!-- AUDIT TRAIL LOG TABLE -->
        <div class="card">
            <div class="card-title"><span>📜</span> AI Audit Trail & Decision Log</div>
            <div style="font-size:12px; color:var(--text-muted); margin-bottom:12px;">Human-in-the-loop audit history tracking AI recommendations vs Administrator decisions:</div>
            
            <div style="overflow-x:auto;">
                <table>
                    <thead>
                        <tr>
                            <th>Request ID</th>
                            <th>Classification</th>
                            <th>Conf.</th>
                            <th>Human Decision</th>
                            <th>Timestamp</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($logs)): ?>
                            <tr><td colspan="5" style="text-align:center; color:var(--text-muted);">No AI audit logs recorded yet.</td></tr>
                        <?php else: foreach ($logs as $l): ?>
                            <tr>
                                <td style="font-family:monospace; color:#93c5fd;"><?= htmlspecialchars(substr((string)$l['request_id'], 0, 14)) ?>...</td>
                                <td><?= htmlspecialchars((string)$l['classification']) ?></td>
                                <td><?= round(((float)$l['confidence']) * 100) ?>%</td>
                                <td>
                                    <?php $dec = (string)$l['human_decision']; ?>
                                    <span style="font-weight:700; color: <?= $dec === 'ACCEPTED' ? '#34d399' : ($dec === 'REJECTED' ? '#f87171' : '#fcd34d') ?>;">
                                        <?= htmlspecialchars($dec) ?>
                                    </span>
                                </td>
                                <td style="color:var(--text-muted);"><?= date('M j, g:i A', strtotime((string)$l['created_at'])) ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
let currentRequestId = '';

function runAnalysis() {
    const input = document.getElementById('offenseInput').value.trim();
    if (!input) {
        alert('Please enter an offense description.');
        return;
    }

    const card = document.getElementById('resultCard');
    card.style.display = 'none';

    fetch('ai_dashboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'analyze',
            offense_description: input
        })
    })
    .then(r => r.json())
    .then(data => {
        if (data && data.classification) {
            currentRequestId = data.request_id || '';
            document.getElementById('resClass').innerText = data.classification.type + ' (' + data.classification.category + ')';
            document.getElementById('resConf').innerText = Math.round(data.classification.confidence * 100) + '% Certainty';
            document.getElementById('resHandbook').innerText = data.handbook.rule + ' — ' + data.handbook.section;
            document.getElementById('resIntervention').innerText = data.recommendation.intervention;
            document.getElementById('resExplanation').innerText = data.ai_explanation;
            card.style.display = 'flex';
        } else {
            alert('Analysis failed or returned empty payload.');
        }
    })
    .catch(err => {
        alert('Connection error: ' + err.message);
    });
}

function recordDecision(decision) {
    if (!currentRequestId) return;
    
    fetch('ai_dashboard.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            action: 'record_decision',
            request_id: currentRequestId,
            human_decision: decision
        })
    })
    .then(r => r.json())
    .then(() => {
        alert('Human Decision [' + decision + '] recorded in audit log!');
        window.location.reload();
    });
}
</script>
</body>
</html>
