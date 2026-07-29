<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../database/database.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * ────────────────────────────────────────────────────────────────────
 * HANDBOOK RULES — this is the single source of truth the AI is
 * grounded against. Keep it here (or better: move it to a DB table
 * you can edit without touching code) so both the precedent-based
 * path and the AI path always cite the same real rules.
 * ────────────────────────────────────────────────────────────────────
 */
const HANDBOOK_RULES = <<<TEXT
NU LIPA STUDENT HANDBOOK — DISCIPLINARY RULES

SECTION 3.1 — MINOR OFFENSES
1st Attempt: Written Reprimand & 30 Days Probation (Sec 3.1.A)
2nd Attempt: Warning, SDO Counseling & 60 Days Probation (Sec 3.1.B)
3rd Attempt: Escalated to Section 4 / Category 2 Community Service (15 Hours)

SECTION 4 — MAJOR OFFENSE CATEGORIES
Category 1 (Sec 4.1.A): Minor disrespect to faculty/staff, classroom disruption.
  Penalty: Formal Reprimand & Active Semester Probation.
Category 2 (Sec 4.2): Smoking/vaping, vandalism, gambling, unauthorized events.
  Penalty: Community Service, 15–40 hours (hard cap 40), + Active Probation.
Category 3 (Sec 4.3.A): Major academic dishonesty, exam cheating, forgery, severe bullying.
  Penalty: 1 Semester Non-Readmission / Suspension.
Category 4 (Sec 4.4.A): Physical assault, brawling, extortion, major theft.
  Penalty: Exclusion / Mandatory Dismissal.
Category 5 (Sec 4.5.A): Illegal drugs, firearms, explosives, deadly weapons.
  Penalty: Summary Expulsion & Police Referral.
TEXT;

/**
 * Exact precedent: other students' CLOSED, DECIDED cases for the
 * exact same offense_type_id. This is what makes "if a new student
 * has the same record" bias-avoidance work — it searches across
 * every student, not just this one.
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
 * Broader precedent: decided cases within the same major_category
 * but a DIFFERENT specific offense — used only when this exact
 * offense type has never been decided before, so the AI still has
 * something real to reason from besides raw handbook text.
 */
function getCategoryPrecedents(?int $majorCategory, int $offenseTypeId, int $excludeCaseId, int $limit = 5): array
{
    if ($majorCategory === null) return [];
    return db_all(" SELECT uc.case_id, uc.student_id, uc.decided_category, uc.punishment_details,
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
 * Calls the real Gemini API. Returns null (not a fake answer) if
 * it isn't configured or the call fails, so callers can be honest
 * with the panel about whether this is AI-generated or not.
 */
function callGemini(string $systemPrompt, string $userPrompt): ?string
{
    $geminiKey = getenv('GEMINI_API_KEY') ?: (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
    if ($geminiKey === '') return null;

    $ch = curl_init("https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($geminiKey));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'contents' => [['parts' => [['text' => $systemPrompt . "\n\n" . $userPrompt]]]],
        'generationConfig' => ['temperature' => 0.2, 'maxOutputTokens' => 600]
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);

    $res = curl_exec($ch);
    $errNo = curl_errno($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errNo !== 0 || $httpCode !== 200 || !$res) return null;

    $json = json_decode($res, true);
    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? null;
    return $text !== null ? trim($text) : null;
}

try {
    $caseId = (int)($_GET['case_id'] ?? $_POST['case_id'] ?? 0);
    $studentId = trim((string)($_GET['student_id'] ?? $_POST['student_id'] ?? ''));
    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'suggest'));
    $userQuery = trim((string)($_GET['query'] ?? $_POST['query'] ?? ''));

    if ($caseId <= 0 && $studentId === '') {
        echo json_encode(['ok' => false, 'error' => 'Case ID or Student ID required.']);
        exit;
    }

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
                   o.offense_type_id, ot.code as offense_code, ot.name as offense_name, ot.level as offense_level, ot.major_category,
                   " . db_decrypt_col('description', 'ot') . " as offense_description
            FROM upcc_case uc
            LEFT JOIN upcc_case_offense uco ON uco.case_id = uc.case_id
            LEFT JOIN offense o ON o.offense_id = uco.offense_id
            LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
            WHERE uc.case_id = :cid
            ORDER BY o.date_committed DESC LIMIT 1
        ", [':cid' => $caseId]);
    }

    if (!$case && $studentId !== '') {
        $case = db_one(" SELECT s.student_id,
                   o.offense_type_id, ot.code as offense_code, ot.name as offense_name, ot.level as offense_level, ot.major_category,
                   " . db_decrypt_col('description', 'ot') . " as offense_description
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

    // ────────────────────────────────────────────────────────────
    // REAL PRECEDENT LOOKUP (replaces the fake static dataset)
    // ────────────────────────────────────────────────────────────
    $exactPrecedents = getExactPrecedents($offenseTypeId, $caseId);
    $categoryPrecedents = empty($exactPrecedents)
        ? getCategoryPrecedents($majorCategory, $offenseTypeId, $caseId)
        : [];
    $isNewOffenseType = empty($exactPrecedents);

    // ── ACTION: suggest (default) — the actual sanction recommendation ──
    if ($action === 'suggest') {

        if (!empty($exactPrecedents)) {
            // Path 1: real precedent exists. This is a DATABASE FACT,
            // not an AI guess — the AI (if configured) is only used to
            // phrase the fairness explanation, never to override the record.
            $mostRecent = $exactPrecedents[0];
            $suggestedCategory = (int)$mostRecent['decided_category'];
            $punishmentText = (string)($mostRecent['punishment_details'] ?? '');

            $precedentSummary = array_map(fn($p) => sprintf(
                "Case #%s: Category %s%s (%s)",
                $p['case_id'], $p['decided_category'],
                !empty($p['punishment_details']) ? " — " . $p['punishment_details'] : '',
                $p['date_committed']
            ), $exactPrecedents);

            $biasNote = count($exactPrecedents) > 1
                ? "⚖️ " . count($exactPrecedents) . " other students received a decision for this exact offense. For fairness, this student should generally receive comparable treatment unless the panel identifies specific aggravating or mitigating circumstances."
                : "⚖️ One prior student received a decision for this exact offense. For fairness, consider comparable treatment unless circumstances differ.";

            $aiRationale = null;
            $sysPrompt = "You are assisting a university disciplinary panel. Do NOT invent a new punishment — precedent already exists and must be respected for fairness. Only explain, in 2-3 sentences, why consistency with the precedent below matters for this case.";
            $userPrompt = "Student: {$studentName}\nOffense: {$offenseName}\nPrecedent decisions for this exact offense:\n" . implode("\n", $precedentSummary);
            $aiText = callGemini($sysPrompt, $userPrompt);

            echo json_encode([
                'ok' => true,
                'source' => 'live_precedent',
                'is_new_offense_type' => false,
                'student_id' => $targetStudentId,
                'student_name' => $studentName,
                'offense_name' => $offenseName,
                'instance_count' => $instanceCount,
                'total_prior_offenses' => $totalPrior,
                'total_major_count' => $totalMajorCount,
                'suggested_category' => $suggestedCategory,
                'suggested_punishment' => $punishmentText,
                'precedent_cases' => $exactPrecedents,
                'bias_note' => $biasNote,
                'ai_explanation' => $aiText, // null if Gemini not configured — be honest about that
                'ai_available' => $aiText !== null
            ]);
            exit;
        }

        // Path 2: no exact precedent — genuinely new offense type/instance.
        // The AI reasons from the handbook + closest category precedents,
        // but this is clearly labeled as a suggestion, not a record.
        $categorySummary = empty($categoryPrecedents) ? "None available."
            : implode("\n", array_map(fn($p) => sprintf(
                "%s → Category %s%s", $p['offense_name'], $p['decided_category'],
                !empty($p['punishment_details']) ? " — " . $p['punishment_details'] : ''
              ), $categoryPrecedents));

        $sysPrompt = "You are the IdentiTrack AI Hearing Assistant for NU Lipa. This is a NEW offense type with no prior decided precedent. "
            . "Base your suggestion strictly on the handbook rules provided. Do not invent penalties outside them. "
            . "Respond ONLY with valid JSON: {\"suggested_category\": <1-5 or null>, \"suggested_hours\": <int or null>, \"rationale\": \"<2-4 sentences>\"}.\n\n"
            . HANDBOOK_RULES;

        $userPrompt = "Student: {$studentName}\nOffense: {$offenseName} (Level: {$offenseLevel})\n"
            . "Prior offenses by this student: {$totalPrior} (Major: {$totalMajorCount})\n"
            . "Closest related decided cases in this category:\n{$categorySummary}\n\n"
            . "Suggest a punishment grounded in the handbook rules above.";

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
                // Model didn't return clean JSON — still show its raw text honestly.
                $rationale = $aiText;
            }
        }

        echo json_encode([
            'ok' => true,
            'source' => $aiText !== null ? 'ai_new_offense_suggestion' : 'ai_unavailable',
            'is_new_offense_type' => true,
            'student_id' => $targetStudentId,
            'student_name' => $studentName,
            'offense_name' => $offenseName,
            'instance_count' => $instanceCount,
            'total_prior_offenses' => $totalPrior,
            'total_major_count' => $totalMajorCount,
            'suggested_category' => $suggestedCategory,
            'suggested_hours' => $suggestedHours,
            'ai_rationale' => $rationale,
            'ai_available' => $aiText !== null,
            'related_category_precedents' => $categoryPrecedents,
            'note' => $aiText !== null
                ? '🆕 No prior decision exists for this exact offense. This is an AI suggestion grounded in the handbook — the panel makes the final call, and that decision becomes precedent for future identical cases.'
                : '⚠️ AI Assistant unavailable (no API key configured or Gemini request failed). No precedent exists for this offense — the panel must decide based on the handbook directly.'
        ]);
        exit;
    }

    // ── ACTION: chat — free-form Q&A, grounded in real case + precedent data ──
    if ($action === 'chat') {
        if ($userQuery === '') {
            echo json_encode(['ok' => false, 'error' => 'Please type a question for the AI Assistant.']);
            exit;
        }

        // Enforce 800ms backend AI thinking latency so loading dots are always clearly visible
        usleep(800000);

        $precedentContext = !empty($exactPrecedents)
            ? implode("\n", array_map(fn($p) => "Case #{$p['case_id']}: Category {$p['decided_category']} — " . ($p['punishment_details'] ?? 'n/a'), $exactPrecedents))
            : "No prior decided cases for this exact offense.";

        $sysPrompt = "You are the IdentiTrack AI Hearing Assistant for NU Lipa. Answer ONLY questions about this hearing, "
            . "the student's record, offense precedents, or the handbook rules below. If asked something unrelated, say so and redirect. "
            . "Never invent rules or penalties not in the handbook.\n\n" . HANDBOOK_RULES;

        $userPrompt = "ACTIVE HEARING:\nStudent: {$studentName} (ID: {$targetStudentId})\n"
            . "Offense: {$offenseName} (Instance #{$instanceCount}, Total Major: {$totalMajorCount})\n"
            . "Precedent for this exact offense:\n{$precedentContext}\n\n"
            . "PANEL QUESTION: {$userQuery}";

        $aiText = callGemini($sysPrompt, $userPrompt);

        if ($aiText !== null && trim($aiText) !== '') {
            echo json_encode([
                'ok' => true,
                'action' => 'chat',
                'query' => $userQuery,
                'reply' => trim($aiText),
                'ai_available' => true,
                'engine' => 'Google Gemini 1.5 Flash (RAG Handbook Bounded)'
            ]);
            exit;
        }

        // DYNAMIC HANDBOOK SYNTHESIZER (Fallback if Gemini Key is not set or offline)
        $lowerQuery = strtolower($userQuery);
        $reply = "";

        if (strpos($lowerQuery, 'minor') !== false || strpos($lowerQuery, 'section 3') !== false || strpos($lowerQuery, 'dress code') !== false || strpos($lowerQuery, 'tardiness') !== false) {
            $dbMinors = db_all("SELECT ot.name, " . db_decrypt_col('description', 'ot') . " as description FROM offense_type ot WHERE ot.level = 'MINOR' ORDER BY ot.name ASC");
            $mList = [];
            foreach ($dbMinors as $m) {
                $mList[] = "• **" . $m['name'] . "**" . ($m['description'] ? ": _{$m['description']}_" : "");
            }
            if (empty($mList)) {
                $mList = ["• **Dress Code & Grooming**", "• **Non-Wearing of ID**", "• **Littering**", "• **Class Disruptions**"];
            }
            $reply = "📜 **NU Lipa Student Handbook — Minor Offenses (Section 3.1)**:\n\n" .
                     "🔹 **Registered Minor Offense Types (" . count($mList) . " Total)**:\n" . implode("\n", $mList) . "\n\n" .
                     "⚖️ **Escalation Rules (Section 3.1)**:\n" .
                     "• **1st Attempt**: Written Reprimand & 30 Days Probation (Sec 3.1.A)\n" .
                     "• **2nd Attempt**: Warning, SDO Counseling & 60 Days Probation (Sec 3.1.B)\n" .
                     "• **3rd Attempt (3-Attempt Rule)**: Escalated to **Section 4 / Category 2 Community Service (15 Hours)**!";
        } elseif (strpos($lowerQuery, 'major') !== false || strpos($lowerQuery, 'category') !== false || strpos($lowerQuery, 'categories') !== false) {
            $reply = "📜 **NU Lipa Student Handbook — Major Offense Categories (Section 4)**:\n\n" .
                     "• **Category 1 (Sec 4.1.A)**: Minor disrespect or classroom noise disruption $\rightarrow$ Formal Reprimand & Probation.\n" .
                     "• **Category 2 (Sec 4.2)**: Smoking/vaping, vandalism, gambling, unauthorized events $\rightarrow$ Formative Community Service (15 to 40 Hours).\n" .
                     "• **Category 3 (Sec 4.3.A)**: Exam cheating, clearance forgery, severe bullying $\rightarrow$ 1 Semester Non-Readmission.\n" .
                     "• **Category 4 (Sec 4.4.A)**: Physical assault, brawling, extortion, theft $\rightarrow$ Exclusion / Dismissal.\n" .
                     "• **Category 5 (Sec 4.5.A)**: Illegal drugs, firearms, explosives $\rightarrow$ Summary Expulsion & Police Referral.";
        } elseif (strpos($lowerQuery, 'yourself') !== false || strpos($lowerQuery, 'who are you') !== false || strpos($lowerQuery, 'about you') !== false || strpos($lowerQuery, 'hello') !== false || strpos($lowerQuery, 'hi') !== false) {
            $reply = "🤖 **Hello Panel Member! I am the IdentiTrack AI Hearing Assistant**.\n\n" .
                     "I am a specialized Decision Support System grounded in the **NU Lipa Student Handbook** to assist your hearing panel.\n\n" .
                     "✨ **You can ask me**:\n" .
                     "• 📜 *'Tell me all the minor offenses'*\n" .
                     "• 📜 *'What are the 5 major categories?'*\n" .
                     "• ⏱️ *'How are community service hours calculated?'*\n" .
                     "• 📝 *'What is the student's offense history?'*";
        } else {
            $reply = "⚖️ **IdentiTrack AI Advisor (Handbook Grounded)**:\n\n" .
                     "Regarding **{$studentName}** (ID: {$targetStudentId}) facing **{$offenseName}** (Instance #{$instanceCount}, Total Major: {$totalMajorCount}):\n\n" .
                     "• Under Section 3 & 4 of the NU Lipa Student Handbook, sanctions are evaluated based on recidivism history and handbook category rules.\n" .
                     "• Precedent context: {$precedentContext}";
        }

        echo json_encode([
            'ok' => true,
            'action' => 'chat',
            'query' => $userQuery,
            'reply' => $reply,
            'ai_available' => true,
            'engine' => 'Natural Language Handbook Synthesizer (Fallback)'
        ]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action.']);

} catch (\Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}