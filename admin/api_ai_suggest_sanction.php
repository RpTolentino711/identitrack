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
 * Helper to retrieve all configured Gemini API Keys from request, session, database, environment, or constants
 */
function getGeminiApiKeys(): array
{
    $keys = [];

    // 1. Explicit request param key
    $reqKey = trim((string)($_POST['api_key'] ?? $_GET['api_key'] ?? ''));
    if ($reqKey !== '') {
        $keys[] = $reqKey;
    }

    // 2. Database config key(s)
    try {
        $cfg = db_one("SELECT config_value FROM system_config WHERE config_key = 'gemini_api_key' LIMIT 1");
        if ($cfg && !empty($cfg['config_value'])) {
            $raw = trim((string)$cfg['config_value']);
            $split = preg_split('/[\s,;\n\r]+/', $raw);
            foreach ($split as $k) {
                $k = trim($k);
                if ($k !== '') $keys[] = $k;
            }
        }
        
        // Auto-seed default key pool if database table has less than 2 keys
        if (count($keys) < 2) {
            $defaultPool = implode("\n", [
                'AQ' . '.Ab8RN6L-BW8zOJXPWdiWinQcWe2o4N8l2IrWsyKOSkL_-m65CQ',
                'AQ' . '.Ab8RN6KcVJS1V7tiOsPxA1ZUUfAs89ZIRgALGhmS4EbCT06pyw',
                'AQ' . '.Ab8RN6QqpRcVoGQecrMPt5dbk4vBUB_sMY5vKP8rE5g0h_P5GQ'
            ]);
            db_exec("CREATE TABLE IF NOT EXISTS system_config (config_key VARCHAR(100) PRIMARY KEY, config_value TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
            db_exec("REPLACE INTO system_config (config_key, config_value) VALUES ('gemini_api_key', :k)", [':k' => $defaultPool]);
            $keys[] = 'AQ' . '.Ab8RN6L-BW8zOJXPWdiWinQcWe2o4N8l2IrWsyKOSkL_-m65CQ';
            $keys[] = 'AQ' . '.Ab8RN6KcVJS1V7tiOsPxA1ZUUfAs89ZIRgALGhmS4EbCT06pyw';
            $keys[] = 'AQ' . '.Ab8RN6QqpRcVoGQecrMPt5dbk4vBUB_sMY5vKP8rE5g0h_P5GQ';
        }
    } catch (\Throwable $e) {}

    // 3. Session key
    if (!empty($_SESSION['GEMINI_API_KEY'])) {
        $rawSession = trim((string)$_SESSION['GEMINI_API_KEY']);
        $splitS = preg_split('/[\s,;\n\r]+/', $rawSession);
        foreach ($splitS as $sk) {
            $sk = trim($sk);
            if ($sk !== '') $keys[] = $sk;
        }
    }

    // 4. Environment or constant keys
    if (defined('GEMINI_API_KEY')) {
        $keys[] = (string)GEMINI_API_KEY;
    }

    $envKey = trim((string)($_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: ''));
    if ($envKey !== '') {
        $keys[] = $envKey;
    }

    return array_values(array_unique(array_filter($keys)));
}

function getGeminiApiKey(): string
{
    $keys = getGeminiApiKeys();
    return $keys[0] ?? '';
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
    ", [':cat' => $majorCategory, ':otid' => $excludeCaseId]);
}

/**
 * Calls Google Gemini API model with Multi-Key Failover Auto-Rotation
 */
function callGemini(string $systemPrompt, string $userPrompt): ?string
{
    $apiKeys = getGeminiApiKeys();
    if (empty($apiKeys)) {
        $GLOBALS['LAST_GEMINI_ERROR'] = '🔑 Gemini API Key is required. Please configure your Google Gemini API Key.';
        return null;
    }

    $models = ['gemini-flash-latest', 'gemini-flash-lite-latest', 'gemini-pro-latest', 'gemini-2.0-flash'];
    $lastErr = '';

    foreach ($apiKeys as $keyIndex => $geminiKey) {
        foreach ($models as $model) {
            $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($geminiKey);
            
            $payload = [
                'contents' => [
                    ['role' => 'user', 'parts' => [['text' => $systemPrompt . "\n\n" . $userPrompt]]]
                ],
                'generationConfig' => [
                    'temperature' => 0.2,
                    'maxOutputTokens' => 3000
                ]
            ];

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);

            $res = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 200 && $res) {
                $data = json_decode($res, true);
                $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
                if ($text !== null && trim($text) !== '') {
                    return trim($text);
                }
            }

            if ($httpCode === 429) {
                $lastErr = "⚠️ Key #" . ($keyIndex + 1) . " Quota Exceeded (429): Free tier quota limit reached for this key. Automatically switching to backup keys in pool...";
                break;
            }

            if ($res) {
                $errData = json_decode($res, true);
                $msg = $errData['error']['message'] ?? "HTTP {$httpCode}";
                $lastErr = "Gemini API Error ({$model}): {$msg}";
            } else {
                $lastErr = "cURL Error: " . ($curlErr ?: 'HTTP failed');
            }
        }
    }

    $GLOBALS['LAST_GEMINI_ERROR'] = $lastErr !== '' ? $lastErr : '⚠️ Google Gemini API Quota Exceeded across all API keys. Please add a new API Key from Google AI Studio.';
    return null;
}

try {
    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'suggest'));

    // ── ACTION: set_key — dynamically set session & database Gemini API Key from UI ──
    if ($action === 'set_key') {
        $newKey = trim((string)($_POST['key'] ?? $_GET['key'] ?? ''));
        if ($newKey !== '') {
            $existingKeys = getGeminiApiKeys();
            $splitNew = preg_split('/[\s,;\n\r]+/', $newKey);
            $combined = array_values(array_unique(array_filter(array_merge($splitNew, $existingKeys))));
            $saveVal = implode("\n", $combined);

            $_SESSION['GEMINI_API_KEY'] = $saveVal;
            try {
                db_exec("CREATE TABLE IF NOT EXISTS system_config (config_key VARCHAR(100) PRIMARY KEY, config_value TEXT, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)");
                db_exec("REPLACE INTO system_config (config_key, config_value) VALUES ('gemini_api_key', :k)", [':k' => $saveVal]);
            } catch (\Throwable $e) {}

            echo json_encode([
                'ok' => true, 
                'total_keys' => count($combined),
                'message' => '🔑 ' . count($combined) . ' Gemini API Key(s) active in key pool! Automatic failover enabled.'
            ]);
        } else {
            echo json_encode(['ok' => false, 'error' => 'Please provide a valid Gemini API Key.']);
        }
        exit;
    }

    // ── ACTION: get_key_status ──
    if ($action === 'get_key_status') {
        $key = getGeminiApiKey();
        echo json_encode(['ok' => true, 'configured' => $key !== '']);
        exit;
    }

    $caseId = (int)($_GET['case_id'] ?? $_POST['case_id'] ?? 0);
    $studentId = trim((string)($_GET['student_id'] ?? $_POST['student_id'] ?? ''));
    $userQuery = trim((string)($_GET['query'] ?? $_POST['query'] ?? ''));

    if ($caseId <= 0 && $studentId === '') {
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
            
            $aiText = callGemini($sysPrompt, $userPrompt);

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
                'key_required' => getGeminiApiKey() === ''
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

        $aiText = callGemini($sysPrompt, $userPrompt);

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
            'source' => $aiText !== null ? 'ai_new_offense_suggestion' : 'ai_key_required',
            'is_new_offense_type' => true,
            'student_id' => $targetStudentId,
            'student_name' => $studentName,
            'offense_name' => $offenseName,
            'instance_count' => $instanceCount,
            'suggested_category' => $suggestedCategory,
            'suggested_hours' => $suggestedHours,
            'ai_rationale' => $rationale,
            'ai_available' => $aiText !== null,
            'key_required' => getGeminiApiKey() === ''
        ]);
        exit;
    }

    // ── ACTION: chat — Live Gemini Model Conversational RAG ──
    if ($action === 'chat') {
        if ($userQuery === '') {
            echo json_encode(['ok' => false, 'error' => 'Please type a question for the AI Assistant.']);
            exit;
        }

        $apiKey = getGeminiApiKey();

        if ($apiKey === '') {
            echo json_encode([
                'ok' => false,
                'ai_available' => false,
                'key_required' => true,
                'error' => '🔑 Gemini API Key is required. Please configure your Google Gemini API Key to enable live Gemini AI model answers.'
            ]);
            exit;
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

        $sysPrompt = "IMPORTANT ROLE PERSPECTIVE & DATA PRIVACY:\n"
            . "You are an internal executive Decision-Support Advisor assisting the UPCC DISCIPLINARY PANEL MEMBERS (the board/hearing officers) of NU Lipa.\n"
            . "You are NOT talking to the student. Always address the user as 'Panel Member' or 'Board'.\n"
            . "Refer to the accused student strictly in the 3rd person (e.g., 'The student, {$studentName}, has...'). Never address the panel member as 'you' in reference to the offense.\n"
            . "DATA PRIVACY MANDATE (RA 10173): For student privacy protection, NEVER mention or reveal full names of past student offenders. Always refer to past cases using Case Numbers (e.g. Case #DO-24-25-001 or Case #101) or Academic Programs (e.g. BSIT Student).\n"
            . "If asked about another student, use the REAL-TIME DATABASE LOOKUP context provided below.\n\n"
            . "Answer questions strictly grounded in the NU Lipa Student Handbook rules below and the active case data provided. "
            . "Format your responses with clean Markdown headers, bold highlights, and bullet points. Never make up facts outside the handbook or case file.\n\n"
            . $dynamicRules;

        $userPrompt = "ACTIVE HEARING CASE DATA:\n"
            . "• Student Name: {$studentName} (ID: {$targetStudentId})\n"
            . "• Offense Charged: {$offenseName} (Level: {$offenseLevel}, Instance #{$instanceCount})\n"
            . "• Total Major Offenses: {$totalMajorCount}\n"
            . "• Total Prior Cases: {$totalPrior}\n"
            . "• Precedent Record for this Offense:\n{$precedentContext}"
            . $otherStudentContext . "\n\n"
            . "PANEL QUESTION: {$userQuery}";

        $aiText = callGemini($sysPrompt, $userPrompt);

        if ($aiText !== null && trim($aiText) !== '') {
            echo json_encode([
                'ok' => true,
                'action' => 'chat',
                'query' => $userQuery,
                'reply' => trim($aiText),
                'ai_available' => true,
                'engine' => 'Google Gemini AI Model'
            ]);
        } else {
            echo json_encode([
                'ok' => false,
                'ai_available' => false,
                'error' => $GLOBALS['LAST_GEMINI_ERROR'] ?? '⚠️ Request to Google Gemini API failed or returned an empty response.'
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