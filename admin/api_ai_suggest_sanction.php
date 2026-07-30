<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../database/database.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * ────────────────────────────────────────────────────────────────────
 * NU LIPA STUDENT HANDBOOK RULES — GROUNDING CONTEXT FOR GEMINI RAG
 * ────────────────────────────────────────────────────────────────────
 */
const HANDBOOK_RULES = <<<TEXT
NU LIPA STUDENT HANDBOOK — DISCIPLINARY RULES & SANCTION GUIDELINES

SECTION 3.1 — MINOR OFFENSES
• 1st Attempt: Written Reprimand & 30 Days Disciplinary Probation (Sec 3.1.A)
• 2nd Attempt: Warning, SDO Counseling & 60 Days Disciplinary Probation (Sec 3.1.B)
• 3rd Attempt (3-Attempt Rule): Escalated to Section 4 / Category 2 Community Service (15 Hours)

SECTION 4 — MAJOR OFFENSE CATEGORIES
• Category 1 (Sec 4.1.A): Minor disrespect to faculty/staff, classroom noise disruption.
  Penalty: Formal Reprimand & Active Semester Probation.
• Category 2 (Sec 4.2): Smoking/vaping, vandalism, gambling, unauthorized events.
  Penalty: Formative Community Service (15–40 hours, hard cap 40), + Active Probation.
• Category 3 (Sec 4.3.A): Major academic dishonesty, exam cheating, clearance forgery, severe bullying.
  Penalty: 1 Semester Non-Readmission / Suspension.
• Category 4 (Sec 4.4.A): Physical assault, brawling, extortion, major theft.
  Penalty: Exclusion / Mandatory Dismissal.
• Category 5 (Sec 4.5.A): Illegal drugs, firearms, explosives, deadly weapons.
  Penalty: Summary Expulsion & Police Referral.
TEXT;

/**
 * Helper to retrieve Gemini API Key from session, environment, or constant
 */
function getGeminiApiKey(): string
{
    $key = trim((string)($_POST['api_key'] ?? $_GET['api_key'] ?? $_SESSION['GEMINI_API_KEY'] ?? $_ENV['GEMINI_API_KEY'] ?? $_SERVER['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?: ''));
    if ($key === '' && defined('GEMINI_API_KEY')) {
        $key = (string)GEMINI_API_KEY;
    }
    return $key;
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
 * Calls Google Gemini API model (gemini-1.5-flash or gemini-2.0-flash)
 */
function callGemini(string $systemPrompt, string $userPrompt): ?string
{
    $geminiKey = getGeminiApiKey();
    if ($geminiKey === '') return null;

    $models = ['gemini-1.5-flash', 'gemini-2.0-flash', 'gemini-1.5-pro'];
    
    foreach ($models as $model) {
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key=" . urlencode($geminiKey);
        
        $payload = [
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => $systemPrompt . "\n\n" . $userPrompt]]]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 800
            ]
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $res = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && $res) {
            $json = json_decode($res, true);
            $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
            if ($text !== null && trim($text) !== '') {
                return trim($text);
            }
        }
    }

    return null;
}

try {
    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'suggest'));

    // ── ACTION: set_key — dynamically set session Gemini API Key from UI ──
    if ($action === 'set_key') {
        $newKey = trim((string)($_POST['key'] ?? $_GET['key'] ?? ''));
        if ($newKey !== '') {
            $_SESSION['GEMINI_API_KEY'] = $newKey;
            echo json_encode(['ok' => true, 'message' => '🔑 Gemini API Key configured for this session!']);
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

            $sysPrompt = "You are the IdentiTrack AI Hearing Assistant for NU Lipa. Precedent already exists in the database for this exact offense. Explain in 2-3 concise sentences why consistency with prior decisions is important for fairness.\n\n" . HANDBOOK_RULES;
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
            . HANDBOOK_RULES;

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

        $precedentContext = !empty($exactPrecedents)
            ? implode("\n", array_map(fn($p) => "Case #{$p['case_id']}: Category {$p['decided_category']} — " . formatPunishmentDetails($p['punishment_details']), $exactPrecedents))
            : "No prior decided cases for this exact offense.";

        $dbOffenses = db_all("SELECT code, name, level, major_category FROM offense_type WHERE is_active = 1 ORDER BY level ASC, name ASC");
        $offenseCatalog = implode("\n", array_map(fn($o) => "• {$o['name']} (Level: {$o['level']}" . ($o['major_category'] ? ", Category {$o['major_category']}" : "") . ")", $dbOffenses));

        $sysPrompt = "You are the IdentiTrack AI Hearing Assistant for National University (NU) Lipa. "
            . "Answer questions strictly grounded in the NU Lipa Student Handbook rules below and the active case data provided. "
            . "Format your responses with clean Markdown headers, bold highlights, and bullet points. Never make up facts outside the handbook or case file.\n\n"
            . "CATALOG OF REGISTERED OFFENSE TYPES IN SYSTEM:\n" . $offenseCatalog . "\n\n"
            . HANDBOOK_RULES;

        $userPrompt = "ACTIVE HEARING CASE DATA:\n"
            . "• Student Name: {$studentName} (ID: {$targetStudentId})\n"
            . "• Offense Charged: {$offenseName} (Level: {$offenseLevel}, Instance #{$instanceCount})\n"
            . "• Total Major Offenses: {$totalMajorCount}\n"
            . "• Total Prior Cases: {$totalPrior}\n"
            . "• Precedent Record for this Offense:\n{$precedentContext}\n\n"
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
                'error' => '⚠️ Request to Google Gemini API failed or returned an empty response. Please verify your Gemini API key.'
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