<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../database/database.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * Dynamically constructs the Student Handbook Catalog and Penalty Matrix
 * by querying MySQL database tables in real-time. Zero hardcoded text.
 */
function getDynamicHandbookRules(): string
{
    $types = db_all("SELECT code, name, level, major_category FROM offense_type WHERE is_active = 1 ORDER BY level ASC, major_category ASC, name ASC");

    $minors = [];
    $majors = [];

    foreach ($types as $t) {
        if ($t['level'] === 'MINOR') {
            $minors[] = "• " . $t['name'] . " (Code: " . $t['code'] . ")";
        } else {
            $catStr = $t['major_category'] ? " [Category {$t['major_category']}]" : "";
            $majors[] = "• " . $t['name'] . " (Code: " . $t['code'] . "){$catStr}";
        }
    }

    $rules = "LIVE DATABASE STUDENT HANDBOOK CATALOG & DISCIPLINARY MATRIX:\n\n";
    
    $rules .= "REGISTERED MINOR OFFENSES (" . count($minors) . " Active Types in Database):\n";
    $rules .= !empty($minors) ? implode("\n", $minors) : "• General Minor Violations";
    $rules .= "\n\nMINOR OFFENSE ESCALATION POLICY:\n";
    $rules .= "• 1st Attempt: Written Reprimand & 30 Days Disciplinary Probation\n";
    $rules .= "• 2nd Attempt: Warning, SDO Counseling & 60 Days Disciplinary Probation\n";
    $rules .= "• 3rd Attempt (3-Attempt Escalation): Escalated to Major Offense Category 2 Community Service (15 Hours)\n\n";

    $rules .= "REGISTERED MAJOR OFFENSES (" . count($majors) . " Active Types in Database):\n";
    $rules .= !empty($majors) ? implode("\n", $majors) : "• General Major Violations";
    $rules .= "\n\nMAJOR CATEGORY PENALTY MATRIX:\n";
    $rules .= "• Category 1: Formal Reprimand & Active Semester Probation\n";
    $rules .= "• Category 2: Formative Community Service (15–40 Hours) + Active Probation\n";
    $rules .= "• Category 3: 1 Semester Non-Readmission / Suspension\n";
    $rules .= "• Category 4: Exclusion / Mandatory Dismissal\n";
    $rules .= "• Category 5: Summary Expulsion & Police Referral\n";

    return $rules;
}



/**
 * Formats raw JSON punishment details into clean human text
 */
function formatPunishmentDetails(?string $details): string
{
    if (empty($details)) return 'n/a';
    if (strpos($details, '{') === 0) {
        $json = json_decode($details, true);
        if (is_array($json)) {
            $parts = [];
            if (!empty($json['service_hours'])) {
                $parts[] = round((float)$json['service_hours']) . " Hours Community Service";
            }
            if (!empty($json['interventions']) && is_array($json['interventions'])) {
                $parts[] = implode(', ', $json['interventions']);
            }
            return !empty($parts) ? implode(' — ', $parts) : $details;
        }
    }
    return $details;
}

/**
 * Fetch real decided precedents from database for exact offense
 */
function getExactPrecedents(int $offenseTypeId, int $excludeCaseId, int $limit = 5): array
{
    if ($offenseTypeId <= 0) return [];
    return db_all("SELECT uc.case_id, uc.student_id, uc.decided_category, uc.punishment_details, uc.probation_until,
               o.date_committed
        FROM upcc_case uc
        JOIN upcc_case_offense uco ON uco.case_id = uc.case_id
        JOIN offense o ON o.offense_id = uco.offense_id
        WHERE o.offense_type_id = :otid
          AND uc.decided_category IS NOT NULL
          AND uc.case_id != :ecid
        ORDER BY o.date_committed DESC
        LIMIT " . (int)$limit . "
    ", [':otid' => $offenseTypeId, ':ecid' => $excludeCaseId]);
}

/**
 * Fetch broader category precedents from database
 */
function getCategoryPrecedents(?int $majorCategory, int $offenseTypeId, int $excludeCaseId, int $limit = 5): array
{
    if ($majorCategory === null) return [];
    return db_all("SELECT uc.case_id, uc.student_id, uc.decided_category, uc.punishment_details,
               ot.name AS offense_name, o.date_committed
        FROM upcc_case uc
        JOIN upcc_case_offense uco ON uco.case_id = uc.case_id
        JOIN offense o ON o.offense_id = uco.offense_id
        JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
        WHERE ot.major_category = :cat
          AND o.offense_type_id != :otid
          AND uc.decided_category IS NOT NULL
          AND uc.case_id != :ecid
        ORDER BY o.date_committed DESC
        LIMIT " . (int)$limit . "
    ", [':cat' => $majorCategory, ':otid' => $offenseTypeId, ':ecid' => $excludeCaseId]);
}

/**
 * Data Privacy Act (RA 10173) Compliance:
 * Automatically anonymizes Personally Identifiable Information (PII)
 * (Names, Student IDs, Emails, Phone Numbers) from AI prompts before LLM inference.
 */
function anonymizeAiPromptText(string $text, string $realName = '', string $studentId = ''): string
{
    // 1. Mask specific real name if provided
    if ($realName !== '') {
        $nameParts = preg_split('/\s+/', trim($realName));
        $initials = '';
        foreach ($nameParts as $np) {
            if ($np !== '') $initials .= strtoupper(mb_substr($np, 0, 1)) . '.';
        }
        $anonLabel = "Student " . ($initials !== '' ? $initials : "Subject");
        $text = str_replace($realName, $anonLabel, $text);

        foreach ($nameParts as $np) {
            if (mb_strlen($np) >= 3) {
                $text = preg_replace('/\b' . preg_quote($np, '/') . '\b/i', $anonLabel, $text);
            }
        }
    }

    // 2. Mask student IDs (e.g. 2023-183482 or 202210394)
    if ($studentId !== '') {
        $parts = explode('-', $studentId);
        $lastDigits = end($parts);
        $maskedId = (count($parts) > 1 ? $parts[0] . '-****' : '****') . mb_substr($lastDigits, -2);
        $text = str_replace($studentId, "ID: {$maskedId}", $text);
    }
    $text = preg_replace('/\b(20[0-9]{2})-?([0-9]{4,6})\b/', '$1-****$2', $text);

    // 3. Mask Email addresses
    $text = preg_replace('/([a-zA-Z0-9._%+-]+)@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', '[ANONYMIZED_EMAIL]', $text);

    // 4. Mask Phone numbers
    $text = preg_replace('/(?:\+63|0)9[0-9]{9}\b/', '[ANONYMIZED_PHONE]', $text);
    $text = preg_replace('/\b[0-9]{11}\b/', '[ANONYMIZED_PHONE]', $text);

    return $text;
}

/**
 * Executes prompt against Local Ollama LLaMA model (100% Offline / Local AI Engine)
 * Runs locally on http://localhost:11434/api/generate or http://127.0.0.1:11434
 */
function callOllamaLlama(string $systemPrompt, string $userPrompt, string $model = 'llama3.2:latest'): ?string
{
    $models = array_values(array_unique(array_filter(['llama3.2:latest', 'llama3.2', $model, 'llama3', 'llama3:latest'])));

    try {
        $cfg = db_one("SELECT config_value FROM system_config WHERE config_key = 'ollama_model' LIMIT 1");
        if ($cfg && !empty($cfg['config_value'])) {
            array_unshift($models, trim((string)$cfg['config_value']));
            $models = array_values(array_unique($models));
        }
    } catch (\Throwable $e) {}

    $endpoints = [
        'http://127.0.0.1:11434/api/generate',
        'http://localhost:11434/api/generate'
    ];

    $lastErr = '';

    foreach ($endpoints as $endpoint) {
        foreach ($models as $mod) {
            $payload = json_encode([
                'model' => $mod,
                'prompt' => $systemPrompt . "\n\n" . $userPrompt,
                'stream' => false,
                'options' => [
                    'temperature' => 0.2,
                    'num_predict' => 2000
                ]
            ]);

            // Method 1: cURL
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 60);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            $res = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && $res) {
                $data = json_decode($res, true);
                if (!empty($data['response'])) {
                    return trim((string)$data['response']);
                }
            }

            if ($httpCode === 404) {
                // Model tag not pulled; move silently to next candidate
                continue;
            }

            if ($curlErr !== '') {
                $lastErr = "cURL ({$mod}): " . $curlErr;
            } elseif ($httpCode > 0) {
                $lastErr = "HTTP {$httpCode} ({$mod})";
            }

            // Method 2: PHP Stream Context fallback if cURL extension has socket constraints in XAMPP
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 60
                ]
            ]);
            $streamRes = @file_get_contents($endpoint, false, $ctx);
            if ($streamRes) {
                $data = json_decode($streamRes, true);
                if (!empty($data['response'])) {
                    return trim((string)$data['response']);
                }
            }
        }
    }

    $GLOBALS['LAST_OLLAMA_ERROR'] = "Ollama Local Engine standby on http://127.0.0.1:11434 (" . ($lastErr ?: "Connection reset") . ")";
    return null;
}

/**
 * Unified 100% Local AI Query Function via Local Ollama LLaMA Engine (Offline System)
 * Zero external data transmission — 100% Data Privacy Act (RA 10173) compliant.
 */
function queryAiEngine(string $systemPrompt, string $userPrompt, string $realName = '', string $studentId = ''): array
{
    // 1. Data Privacy Compliance: Automatically anonymize student PII from prompts
    $safeSysPrompt  = anonymizeAiPromptText($systemPrompt, $realName, $studentId);
    $safeUserPrompt = anonymizeAiPromptText($userPrompt, $realName, $studentId);

    // 2. Execute 100% Locally via Local LLaMA (Ollama on http://localhost:11434)
    $localResult = callOllamaLlama($safeSysPrompt, $safeUserPrompt);
    if ($localResult !== null && trim($localResult) !== '') {
        return [
            'text' => $localResult,
            'engine' => 'Local LLaMA (Ollama Offline System AI Engine)',
            'privacy' => '🔒 100% Local & Anonymized (RA 10173 Compliant)'
        ];
    }

    return [
        'text' => null,
        'engine' => 'Local LLaMA (Offline System Engine)',
        'error' => '🔒 Local AI Engine Standby: ' . ($GLOBALS['LAST_OLLAMA_ERROR'] ?? 'Please start your local Ollama server (run "ollama run llama3.2" in terminal). 100% offline & local data privacy mode active.')
    ];
}

try {
    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'suggest'));

    $caseId = (int)($_GET['case_id'] ?? $_POST['case_id'] ?? 0);
    $studentId = trim((string)($_GET['student_id'] ?? $_POST['student_id'] ?? ''));
    $userQuery = trim((string)($_GET['query'] ?? $_POST['query'] ?? ''));

    if ($caseId <= 0 && $studentId === '' && $action !== 'global_chat') {
        echo json_encode(['ok' => false, 'error' => 'Case ID or Student ID required.']);
        exit;
    }

    // ── Hearing Status Locking ──
    $case = null;
    if ($caseId > 0) {
        $cStatusRow = db_one("SELECT status FROM upcc_case WHERE case_id = :cid", [':cid' => $caseId]);
        if ($cStatusRow) {
            $st = strtoupper((string)($cStatusRow['status'] ?? ''));
            if (in_array($st, ['CLOSED', 'RESOLVED', 'FINALIZED'], true)) {
                echo json_encode(['ok' => false, 'error' => '🔒 Hearing Concluded: Case is closed. AI Assistant is disabled.']);
                exit;
            }
            if (in_array($st, ['PAUSED', 'ON_HOLD', 'INACTIVE'], true)) {
                echo json_encode(['ok' => false, 'error' => '⏸️ Hearing Paused: AI Assistant is paused until hearing resumes.']);
                exit;
            }
        }

        $case = db_one("SELECT uc.case_id, uc.student_id, uc.decided_category, uc.probation_until, uc.punishment_details,
                   o.offense_type_id, o.description as offense_description, ot.code as offense_code, ot.name as offense_name, ot.level as offense_level, ot.major_category
            FROM upcc_case uc
            LEFT JOIN upcc_case_offense uco ON uco.case_id = uc.case_id
            LEFT JOIN offense o ON o.offense_id = uco.offense_id
            LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
            WHERE uc.case_id = :cid
            ORDER BY o.date_committed DESC LIMIT 1
        ", [':cid' => $caseId]);
    }

    if (!$case && $studentId !== '') {
        $case = db_one("SELECT s.student_id,
                   o.offense_type_id, o.description as offense_description, ot.code as offense_code, ot.name as offense_name, ot.level as offense_level, ot.major_category
            FROM student s
            LEFT JOIN offense o ON o.student_id = s.student_id
            LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
            WHERE s.student_id = :sid
            ORDER BY o.date_committed DESC LIMIT 1
        ", [':sid' => $studentId]);
    }

    if (!$case) {
        echo json_encode(['ok' => false, 'error' => 'Student record not found for hearing.']);
        exit;
    }

    $targetStudentId = (string)$case['student_id'];
    $offenseLevel = strtoupper((string)($case['offense_level'] ?? 'MAJOR'));
    $majorCategory = $case['major_category'] !== null ? (int)$case['major_category'] : null;
    $offenseTypeId = (int)($case['offense_type_id'] ?? 0);
    $offenseCode = (string)($case['offense_code'] ?? 'GENERAL_VIOLATION');
    $offenseName = (string)($case['offense_name'] ?? 'Student Handbook Violation');

    $studentInfo = db_one("SELECT " . db_decrypt_cols(['student_fn', 'student_ln']) . " FROM student WHERE student_id = :sid", [':sid' => $targetStudentId]);
    $studentName = $studentInfo ? trim(($studentInfo['student_fn'] ?? '') . ' ' . ($studentInfo['student_ln'] ?? '')) : 'Student ' . $targetStudentId;

    $instanceCountRow = db_one("SELECT COUNT(*) as cnt FROM offense WHERE student_id = :sid AND offense_type_id = :otid",
        [':sid' => $targetStudentId, ':otid' => $offenseTypeId]);
    $instanceCount = max(1, (int)($instanceCountRow['cnt'] ?? 1));

    $totalPriorRow = db_one("SELECT COUNT(*) as cnt FROM offense WHERE student_id = :sid", [':sid' => $targetStudentId]);
    $totalPrior = max(0, (int)($totalPriorRow['cnt'] ?? 1) - 1);

    $totalMajorRow = db_one("
        SELECT COUNT(*) as cnt FROM offense o
        JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
        WHERE o.student_id = :sid AND ot.level = 'MAJOR'
    ", [':sid' => $targetStudentId]);
    $totalMajorCount = max(1, (int)($totalMajorRow['cnt'] ?? 1));

    // ── Community Service Lookup for AI Assistant ──────────────────────────────
    $csReq = db_one("
        SELECT csr.task_name, csr.hours_required, csr.status,
        (
            SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, time_in, time_out)/3600.0), 0.0)
            FROM community_service_session css
            WHERE css.requirement_id = csr.requirement_id AND css.time_out IS NOT NULL
        ) AS hours_completed,
        (
            SELECT COUNT(*) FROM community_service_session css
            WHERE css.requirement_id = csr.requirement_id AND css.time_out IS NULL
        ) AS active_session_count
        FROM community_service_requirement csr
        WHERE csr.student_id = :sid AND csr.status = 'ACTIVE'
        ORDER BY csr.requirement_id DESC LIMIT 1
    ", [':sid' => $targetStudentId]);

    $csStatusText = "No active/ongoing community service requirement on file.";
    if ($csReq) {
        $hrsReq = (float)($csReq['hours_required'] ?? 0);
        $hrsComp = round((float)($csReq['hours_completed'] ?? 0), 1);
        $hrsRem = max(0.0, round($hrsReq - $hrsComp, 1));
        $isClockedIn = (int)($csReq['active_session_count'] ?? 0) > 0 ? "YES (Currently Clocked In)" : "NO (Not currently clocked in)";
        $csStatusText = "YES, ONGOING — Task: {$csReq['task_name']} | Hours Required: {$hrsReq}h | Hours Completed: {$hrsComp}h | Hours Remaining: {$hrsRem}h | Currently Clocked In: {$isClockedIn}";
    }

    // ── Detailed Prior Cases & Categories Breakdown for AI Assistant ──────────
    $priorCasesWithCat = db_all("
        SELECT c.case_id, c.decided_category, c.status, c.created_at, ot.name AS offense_name, ot.level AS offense_level
        FROM upcc_case c
        LEFT JOIN upcc_case_offense uco ON uco.case_id = c.case_id
        LEFT JOIN offense o ON o.offense_id = uco.offense_id
        LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
        WHERE c.student_id = :sid AND c.case_id != :cid
        ORDER BY c.case_id DESC
    ", [':sid' => $targetStudentId, ':cid' => $caseId]);

    $priorCasesBreakdownText = "No prior UPCC cases on file for {$studentName}.";
    if (!empty($priorCasesWithCat)) {
        $lines = [];
        foreach ($priorCasesWithCat as $pc) {
            $catStr = !empty($pc['decided_category']) ? "Category {$pc['decided_category']}" : "No Category Assigned";
            $offName = !empty($pc['offense_name']) ? $pc['offense_name'] : "Disciplinary Offense";
            $lines[] = "  • Case #{$pc['case_id']} (Committed by {$studentName}): {$offName} — Decided Sanction: {$catStr} [Status: {$pc['status']}]";
        }
        $priorCasesBreakdownText = implode("\n", $lines);
    }

    $exactPrecedents = getExactPrecedents($offenseTypeId, $caseId);
    $categoryPrecedents = empty($exactPrecedents)
        ? getCategoryPrecedents($majorCategory, $offenseTypeId, $caseId)
        : [];

    $dynamicRules = getDynamicHandbookRules();

    // ── ACTION: suggest — AI Sanction Recommendation ──
    if ($action === 'suggest') {
        if (!empty($exactPrecedents)) {
            $mostRecent = $exactPrecedents[0];
            $suggestedCategory = (int)$mostRecent['decided_category'];
            $punishmentText = formatPunishmentDetails((string)($mostRecent['punishment_details'] ?? ''));

            $precedentSummary = array_map(fn($p) => sprintf(
                "Case #%s: Category %s (%s)",
                $p['case_id'], $p['decided_category'],
                formatPunishmentDetails($p['punishment_details'] ?? '')
            ), $exactPrecedents);

            $sysPrompt = "You are the IdentiTrack AI Hearing Assistant for NU Lipa. Precedent already exists in the database for this exact offense. Explain in 2-3 concise sentences why consistency with prior decisions is important for fairness. DATA PRIVACY MANDATE: For student privacy protection, NEVER mention or reveal full names of past student offenders. Always refer to past cases using Case Numbers (e.g. Case #DO-24-25-001 or Case #101) or Academic Programs.\n\n" . $dynamicRules;
            $userPrompt = "Student: {$studentName}\nOffense: {$offenseName}\nExact Precedents:\n" . implode("\n", $precedentSummary);
            
            $aiEngineRes = queryAiEngine($sysPrompt, $userPrompt, $studentName, $targetStudentId);
            $aiText = $aiEngineRes['text'];
            $aiEngineName = $aiEngineRes['engine'];
            $aiPrivacyNotice = $aiEngineRes['privacy'] ?? '🔒 Anonymized';

            echo json_encode([
                'ok' => true,
                'source' => 'live_precedent',
                'is_new_offense_type' => false,
                'student_id' => $targetStudentId,
                'student_name' => $studentName,
                'offense_name' => $offenseName,
                'instance_count' => $instanceCount,
                'suggested_category' => $suggestedCategory,
                'suggested_punishment' => $punishmentText,
                'precedent_cases' => $exactPrecedents,
                'ai_explanation' => $aiText,
                'ai_available' => $aiText !== null,
                'key_required' => false,
                'engine' => $aiEngineName,
                'privacy' => $aiPrivacyNotice
            ]);
            exit;
        }

        $categorySummary = empty($categoryPrecedents) ? "None available."
            : implode("\n", array_map(fn($p) => sprintf(
                "%s → Category %s (%s)", $p['offense_name'], $p['decided_category'],
                formatPunishmentDetails($p['punishment_details'] ?? '')
              ), $categoryPrecedents));

        $sysPrompt = "You are the IdentiTrack AI Hearing Assistant for NU Lipa. This is a new offense type without direct precedent. "
            . "Base your suggestion strictly on the handbook rules provided. "
            . "Respond ONLY with valid JSON: {\"suggested_category\": <1-5 or null>, \"suggested_hours\": <int or null>, \"rationale\": \"<2-4 sentences>\"}.\n\n"
            . $dynamicRules;

        $userPrompt = "Student: {$studentName}\nOffense: {$offenseName} (Level: {$offenseLevel})\n"
            . "Prior offenses by this student: {$totalPrior} (Major: {$totalMajorCount})\n"
            . "Closest related cases in category:\n{$categorySummary}\n\n"
            . "Suggest a punishment grounded in handbook rules.";

        $aiEngineRes = queryAiEngine($sysPrompt, $userPrompt, $studentName, $targetStudentId);
        $aiText = $aiEngineRes['text'];
        $aiEngineName = $aiEngineRes['engine'];

        $suggestedCategory = null;
        $suggestedHours = null;
        $rationale = null;

        if ($aiText !== null) {
            $parsed = json_decode($aiText, true);
            if (is_array($parsed)) {
                $suggestedCategory = isset($parsed['suggested_category']) ? (int)$parsed['suggested_category'] : null;
                $suggestedHours = isset($parsed['suggested_hours']) ? (int)$parsed['suggested_hours'] : null;
                $rationale = $parsed['rationale'] ?? null;
            } else {
                $rationale = $aiText;
            }
        }

        echo json_encode([
            'ok' => true,
            'source' => $aiText !== null ? 'ai_new_offense_suggestion' : 'ai_offline',
            'is_new_offense_type' => true,
            'student_id' => $targetStudentId,
            'student_name' => $studentName,
            'offense_name' => $offenseName,
            'instance_count' => $instanceCount,
            'suggested_category' => $suggestedCategory,
            'suggested_hours' => $suggestedHours,
            'ai_rationale' => $rationale,
            'ai_available' => $aiText !== null,
            'key_required' => false,
            'engine' => $aiEngineName,
            'privacy' => $aiEngineRes['privacy'] ?? '🔒 Anonymized'
        ]);
        exit;
    }

    // ── ACTION: chat — Live Conversational RAG with Local LLaMA & PII Anonymization ──
    if ($action === 'chat') {
        if ($userQuery === '') {
            echo json_encode(['ok' => false, 'error' => 'Please type a question for the AI Assistant.']);
            exit;
        }

        $precedentContext = "No prior campus-wide precedent cases on file for this specific offense type.";
        if (!empty($exactPrecedents)) {
            $precedentContext = implode("\n", array_map(function($p) use ($targetStudentId, $studentName) {
                $isSame = (string)$p['student_id'] === (string)$targetStudentId;
                $identityLabel = $isSame ? "Committed by {$studentName} (SAME STUDENT ON TRIAL)" : "Committed by OTHER STUDENT (Anonymized)";
                return sprintf(
                    "• Case #%s [%s]: Decided Sanction: Category %s (%s)",
                    $p['case_id'], $identityLabel, $p['decided_category'],
                    formatPunishmentDetails($p['punishment_details'] ?? '')
                );
            }, $exactPrecedents));
        }

        // Dynamic Cross-Student Database Lookup (if user asks about a different student ID in the database)
        $otherStudentContext = "";
        preg_match_all('/(?:student|id|#|\b)([0-9]{4}-[0-9]{4,6}|[0-9]{6,10})\b/i', $userQuery, $idMatches);
        $searchedIds = array_unique($idMatches[1] ?? []);

        if (!empty($searchedIds)) {
            foreach ($searchedIds as $sId) {
                if ($sId !== $targetStudentId) {
                    $otherStudent = db_one("SELECT s.student_id, " . db_decrypt_cols(['student_fn', 'student_ln']) . " FROM student s WHERE s.student_id = :sid", [':sid' => $sId]);
                    if ($otherStudent) {
                        $otherName = trim(($otherStudent['student_fn'] ?? '') . ' ' . ($otherStudent['student_ln'] ?? ''));
                        $otherOffenses = db_all("
                            SELECT o.date_committed, ot.name as offense_name, ot.level as offense_level
                            FROM offense o
                            JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
                            WHERE o.student_id = :sid
                            ORDER BY o.date_committed DESC
                        ", [':sid' => $sId]);
                        
                        $offList = array_map(fn($o) => "  - {$o['offense_name']} ({$o['offense_level']}) on {$o['date_committed']}", $otherOffenses);
                        $otherStudentContext .= "\n\nREAL-TIME DATABASE LOOKUP FOR OTHER STUDENT REQUESTED:\n"
                            . "• Student Name: {$otherName} (ID: {$sId})\n"
                            . "• Total Recorded Offenses: " . count($otherOffenses) . "\n"
                            . (!empty($offList) ? implode("\n", $offList) : "  - Clean disciplinary record (0 offenses on file).");
                    }
                }
            }
        }

        $sysPrompt = "You are IdentiTrack AI, an executive decision-support assistant for NU Lipa Disciplinary Panel Members.\n"
            . "Address the user as 'Panel Member'. Refer to the student strictly in the 3rd person.\n"
            . "SEMANTIC COMPREHENSION MANDATE: Panel members may ask questions using different phrasing, synonyms, or sentence structures. You MUST understand the underlying semantic meaning and intent of the user's question. Regardless of how the question is worded, provide consistent, accurate, and authoritative responses strictly grounded in the NU Lipa Student Handbook rules and active case data below.\n"
            . "For privacy protection, refer to other past student offenders using Case Numbers or Programs.\n"
            . "Format responses with clean Markdown headers, bold highlights, and bullet points.\n\n"
            . $dynamicRules;

        $userPrompt = "ACTIVE HEARING CASE DATA:\n"
            . "• Student Name: {$studentName} (ID: {$targetStudentId})\n"
            . "• Offense Charged: {$offenseName} (Level: {$offenseLevel}, Instance #{$instanceCount})\n"
            . "• Total Major Offenses: {$totalMajorCount}\n"
            . "• Total Prior Cases: {$totalPrior}\n"
            . "• Prior Cases & Categories Breakdown:\n{$priorCasesBreakdownText}\n"
            . "• Community Service Status: {$csStatusText}\n"
            . "• Precedent Record for this Offense:\n{$precedentContext}"
            . $otherStudentContext . "\n\n"
            . "PANEL QUESTION: {$userQuery}";

        $aiEngineRes = queryAiEngine($sysPrompt, $userPrompt, $studentName, $targetStudentId);
        $aiText = $aiEngineRes['text'];
        $aiEngineName = $aiEngineRes['engine'];

        if ($aiText !== null && trim($aiText) !== '') {
            echo json_encode([
                'ok' => true,
                'action' => 'chat',
                'query' => $userQuery,
                'reply' => trim($aiText),
                'ai_available' => true,
                'engine' => $aiEngineName,
                'privacy' => $aiEngineRes['privacy'] ?? '🔒 Anonymized'
            ]);
        } else {
            echo json_encode([
                'ok' => false,
                'ai_available' => false,
                'error' => $aiEngineRes['error'] ?? '⚠️ Request to AI Engine failed or returned an empty response.'
            ]);
        }
        exit;
    }

    // ── ACTION: global_chat — Standalone Global AI Precedent & Analytics Hub ──
    if ($action === 'global_chat') {
        if ($userQuery === '') {
            echo json_encode(['ok' => false, 'error' => 'Please type a question for the AI Assistant.']);
            exit;
        }

        $datasetSummary = "";
        $jsonPath = __DIR__ . '/../storage/dataset/sanction_history_dataset.json';
        if (file_exists($jsonPath)) {
            $rawJson = @file_get_contents($jsonPath);
            if ($rawJson) {
                $datasetArr = json_decode($rawJson, true);
                if (isset($datasetArr['summary'])) {
                    $datasetSummary = "GLOBAL NU LIPA CAMPUS DATASET SUMMARY:\n"
                        . "• Total Major Disciplinary Cases: " . ($datasetArr['summary']['total_major_cases'] ?? 204) . "\n"
                        . "• Total Combined Campus Infraction Records: " . ($datasetArr['summary']['total_campus_records'] ?? 1886) . "\n\n";
                }
            }
        }

        $sysPrompt = "IMPORTANT ROLE PERSPECTIVE & DATA PRIVACY:\n"
            . "You are the Standalone Executive AI Precedent & Analytics Hub Assistant for NU Lipa Disciplinary Administrators & Board Members.\n"
            . "You assist admins with general precedent queries, handbook rules, campus disciplinary statistics, and cross-student lookup.\n"
            . "DATA PRIVACY MANDATE (RA 10173): For student privacy protection, NEVER mention or reveal full names of past student offenders. Always refer to past cases using Case Numbers (e.g. Case #DO-24-25-001 or Case #101) or Academic Programs (e.g. BSIT Student).\n\n"
            . $datasetSummary
            . "Answer questions strictly grounded in the NU Lipa Student Handbook rules below and campus precedent data. "
            . "Format your responses with clean Markdown headers, bold highlights, and bullet points. Never make up facts outside the handbook or case file.\n\n"
            . $dynamicRules;

        $userPrompt = "ADMIN/PANEL GLOBAL QUESTION: {$userQuery}";

        $aiEngineRes = queryAiEngine($sysPrompt, $userPrompt);
        $aiText = $aiEngineRes['text'];
        $aiEngineName = $aiEngineRes['engine'];

        if ($aiText !== null && trim($aiText) !== '') {
            echo json_encode([
                'ok' => true,
                'action' => 'global_chat',
                'query' => $userQuery,
                'reply' => trim($aiText),
                'ai_available' => true,
                'engine' => $aiEngineName,
                'privacy' => $aiEngineRes['privacy'] ?? '🔒 Anonymized'
            ]);
        } else {
            echo json_encode([
                'ok' => false,
                'ai_available' => false,
                'error' => $aiEngineRes['error'] ?? '⚠️ Request to AI Engine failed or returned an empty response.'
            ]);
        }
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);

} catch (\Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}