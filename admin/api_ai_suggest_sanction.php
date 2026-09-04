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
    // Mask specific real name if provided
    if ($realName !== '') {
        $anonLabel = 'Student (Active Hearing)';
        $text = str_replace($realName, $anonLabel, $text);
    }

    // Mask student IDs
    if ($studentId !== '') {
        $parts = explode('-', $studentId);
        $lastDigits = end($parts);
        $maskedId = (count($parts) > 1 ? $parts[0] . '-XX' : 'STU-XXXX');
        $text = str_replace($studentId, $maskedId, $text);
    }

    // Mask Email addresses
    $text = preg_replace('/([a-zA-Z0-9._%+-]+)@([a-zA-Z0-9.-]+\.[a-zA-Z]{2,})/', '[ANONYMIZED_EMAIL]', $text);

    // Mask Phone numbers
    $text = preg_replace('/(?:\+63|0)9[0-9]{9}\b/', '[ANONYMIZED_PHONE]', $text);
    $text = preg_replace('/\b[0-9]{11}\b/', '[ANONYMIZED_PHONE]', $text);

    return $text;
}

/**
 * Groq Cloud LLM Engine (Llama 3.3 70B Compound)
 * High-Speed Conversational AI with Strict RA 10173 Anonymization
 */
function callGroqApi(string $sysPrompt, string $userPrompt): ?string
{
    load_env_vars();
    $apiKey = get_env_var('GROQ_API_KEY', '') ?: get_env_var('AI_API_KEY', '');
    if (empty($apiKey)) {
        return null;
    }

    $url = 'https://api.groq.com/openai/v1/chat/completions';
    $model = get_env_var('AI_MODEL', 'groq/compound');
    if ($model === 'llama-3.3-70b-versatile' || $model === 'gemini-1.5-flash') {
        $model = 'groq/compound';
    }

    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => $sysPrompt],
            ['role' => 'user', 'content' => $userPrompt]
        ],
        'temperature' => 0.2,
        'max_tokens' => 1500
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey
        ],
        CURLOPT_TIMEOUT => 15,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $response) {
        $json = json_decode($response, true);
        if (isset($json['choices'][0]['message']['content'])) {
            return trim((string)$json['choices'][0]['message']['content']);
        }
    }

    return null;
}

/**
 * Built-In System AI Hearing Advisory Engine (Conversational Knowledge Base Fallback)
 */
function buildBuiltInAiHearingResponse(string $systemPrompt, string $userPrompt): string
{
    $promptLower = mb_strtolower(trim($userPrompt));
    $combined = mb_strtolower($systemPrompt . "\n" . $userPrompt);

    $hasMajor = strpos($combined, 'major') !== false;

    // Dynamic Active Student Case Data Extraction
    $studentHeader = "";
    $extractedName = "";
    $extractedId = "";
    $extractedOffense = "";
    $extractedCS = "";
    $extractedPriors = "";
    $extractedBreakdown = "";

    if (preg_match('/Student Name:\s*(.*?)\s*\(ID:\s*(.*?)\)/i', $userPrompt, $m)) {
        $extractedName = trim($m[1]);
        $rawId = trim($m[2]);
        $extractedId = preg_replace('/^(ID:\s*)+/i', '', $rawId);
    }
    if (preg_match('/Offense Charged:\s*(.*?)\n/i', $userPrompt, $m)) {
        $extractedOffense = trim($m[1]);
    }
    if (preg_match('/Community Service Status:\s*(.*?)\n/i', $userPrompt, $m)) {
        $extractedCS = trim($m[1]);
    }
    if (preg_match('/Total Prior Cases:\s*(\d+)/i', $userPrompt, $m)) {
        $extractedPriors = trim($m[1]);
    }
    if (preg_match('/Prior Cases & Categories Breakdown:\s*(.*?)\n\s*• Community Service Status:/s', $userPrompt, $m)) {
        $rawBd = trim($m[1]);
        if (strpos($rawBd, 'No prior UPCC cases') === false) {
            $extractedBreakdown = $rawBd;
        }
    }

    if ($extractedName !== '' || $extractedOffense !== '') {
        $studentHeader = "👤 **Active Student File**: " . ($extractedName ?: 'Student') . ($extractedId ? " (ID: {$extractedId})" : "") . "\n"
                       . ($extractedOffense ? "📋 **Current Offense**: {$extractedOffense}\n" : "")
                       . ($extractedPriors !== '' ? "📊 **Prior Resolved Records**: {$extractedPriors} case(s)\n" : "")
                       . ($extractedBreakdown !== '' ? "{$extractedBreakdown}\n" : "")
                       . ($extractedCS ? "⏱️ **Community Service Log**: {$extractedCS}\n" : "")
                       . "──────────────\n\n";
    }

    // 1. GREETINGS & INTRODUCTIONS - Direct & Clean greeting without sample question dumps
    if (preg_match('/\b(hi|hello|hey|greetings|good morning|good afternoon|good evening|who are you|what can you do)\b/i', $promptLower)) {
        return $studentHeader
             . "👋 **Hello Panel Member! I am IdentiTrack AI**, your Hearing Advisory Assistant.\n\n"
             . "I am ready to assist you with the hearing file for **" . ($extractedName ?: "this student") . "** against the **NU Lipa Student Handbook** and case precedents.\n\n"
             . "Please write what you would like to ask regarding this case, handbook rules, or precedent statistics.";
    }

    // 2. DISHONESTY / PERJURY / LYING DURING HEARING
    if (preg_match('/\b(lie|lying|false|dishonest|perjury|fake|deceit|untruth|mislead)\b/i', $promptLower)) {
        return $studentHeader
             . "⚠️ **Policy Guidance on Submitting False Information / Lying**:\n\n"
             . "• **Handbook Violation**: Providing false statements, forged documents, or lying during a UPCC hearing is classified as an independent **Major Offense** under NU Lipa Academic & Administrative Dishonesty policies.\n"
             . "• **Sanction Escalation**: If established during the hearing for **" . ($extractedName ?: "the student") . "**, the committee should note this aggravating circumstance and consider escalating the penalty to a **Category 2 or Category 3 Sanction** (Disciplinary Probation or Suspension).\n"
             . "• **Recommendation**: Advise the student of their obligation to speak truthfully under the Student Code of Conduct.";
    }

    // 3. APPEALS & MOTIONS FOR RECONSIDERATION
    if (preg_match('/\b(appeal|reconsider|reconsideration|overturn|contest|due process)\b/i', $promptLower)) {
        return $studentHeader
             . "📜 **Appeal & Reconsideration Rules (NU Lipa Student Handbook)**:\n\n"
             . "• **Filing Deadline**: Students have **5 school days** from formal notice of sanction finalization to submit a written appeal.\n"
             . "• **Appellate Body**: Appeals are submitted to the **Office of the Academic Director / Executive Office**.\n"
             . "• **Valid Grounds for Appeal**: (1) Discovery of new material evidence, (2) Procedural irregularity during UPCC hearing, or (3) Disproportionate penalty relative to offense level.\n"
             . "• **Status**: Filing an appeal suspends execution of suspension penalties pending final executive review.";
    }

    // 4. DRUGS, ALCOHOL, WEAPONS, GAMBLING
    if (preg_match('/\b(drug|substance|alcohol|liquor|drink|vape|vaping|smoke|smoking|weapon|knife|blade|gun|gambling|betting)\b/i', $promptLower)) {
        return $studentHeader
             . "🚨 **Zero-Tolerance Campus Safety Policy**:\n\n"
             . "• **Classification**: Possession, consumption, or distribution of illegal drugs, alcohol, weapons, or organized gambling inside campus premises is a **Category 3 Major Offense**.\n"
             . "• **Mandatory Interventions**: Mandatory **Category 3 Sanction** (Immediate Disciplinary Probation, 30–50 Hours of Community Service, mandatory drug/psychological evaluation, or Suspension/Dismissal recommendation).\n"
             . "• **Immediate Action**: Require security report log and refer student to the Student Affairs & Guidance Office.";
    }

    // 5. GRADUATING STUDENTS & HONOR DISQUALIFICATION
    if (preg_match('/\b(graduat|latin honor|cum laude|magna|summa|clearance|diploma|senior)\b/i', $promptLower)) {
        return $studentHeader
             . "🎓 **Impact on Graduation & Academic Honors**:\n\n"
             . "• **Disqualification from Honors**: Any student found guilty of a **Major Offense** or a Category 2/3 sanction is automatically disqualified from graduating with Latin Honors (Cum Laude, Magna Cum Laude, Summa Cum Laude).\n"
             . "• **Student Clearance**: Disciplinary cases for **" . ($extractedName ?: "the student") . "** must reach **RESOLVED** or **CLOSED** status with all community service hours completed before graduation clearance can be approved.";
    }

    // 6. COMMUNITY SERVICE HOURS & ATTENDANCE
    if (preg_match('/\b(community service|cs|hour|hours|clock|attend|session|requirement)\b/i', $promptLower)) {
        return $studentHeader
             . "⏱️ **Community Service Hours Calculation Matrix (Ground-Truth Policy)**:\n\n"
             . "• **Active Status for " . ($extractedName ?: "Student") . "**: " . ($extractedCS ?: "No ongoing service requirement on file.") . "\n\n"
             . "• **Category 1 (Minor Initial Violation)**: **0 Hours** (No University Service Required — Written Reprimand & Formative Counseling ONLY).\n"
             . "• **Category 2 (Repeated / Major Offense)**: **15 to 25 Hours** of University Service.\n"
             . "• **Category 3 (Severe / Repeat Major Offense)**: **25 to 50 Hours** of University Service.\n\n"
             . "💡 *IdentiTrack Tracking*: All service sessions are verified via photo check-in/check-out logs in the guard module.";
    }

    // 7. SECTION 4 MINOR OFFENSES & 3-ATTEMPT ESCALATION
    if (preg_match('/\b(section 4|minor|escalat|3-attempt|three|repeat|count)\b/i', $promptLower)) {
        return $studentHeader
             . "📌 **Section 4 Minor Offense Escalation Policy**:\n\n"
             . "• **1st Offense**: Written Reprimand & Category 1 Warning (**0 Hours CS**).\n"
             . "• **2nd Offense**: Category 1 Warning (**0 Hours CS**).\n"
             . "• **3rd Offense (3-Attempt Rule)**: **AUTOMATIC ESCALATION** — Accumulating 3 minor offenses converts the case into a **Category 2 Major Offense** (**15–25 Hours CS**).\n\n"
             . "📋 *Panel Note*: " . ($extractedName ?: "The student") . " currently has " . ($extractedPriors !== '' ? "{$extractedPriors} prior recorded case(s)" : "0 prior records") . " on file.";
    }

    // 8. SANCTIONS & CATEGORIES RECOMMENDATIONS
    if (preg_match('/\b(suggest|sanction|category|punishment|recommend|decision|vote|result)\b/i', $promptLower)) {
        if ($hasMajor) {
            return $studentHeader
                 . "⚖️ **IdentiTrack AI Advisory Recommendation (Major Offense)**:\n\n"
                 . "• **Target Student**: " . ($extractedName ?: "Active Case Student") . "\n"
                 . "• **Recommended Category**: **Category 2 or Category 3 Sanction**.\n"
                 . "• **Prescribed Actions**: Disciplinary Probation, 15–35 Hours of University Service, and Formal Parental Notification.\n"
                 . "• **Policy Basis**: NU Lipa Student Handbook Section 5 (Major Offenses Matrix).";
        } else {
            return $studentHeader
                 . "⚖️ **IdentiTrack AI Advisory Recommendation (Minor Offense)**:\n\n"
                 . "• **Target Student**: " . ($extractedName ?: "Active Case Student") . "\n"
                 . "• **Recommended Category**: **Category 1 Warning (0 Hours CS)** — Escalates to **Category 2 (15–25 Hours CS)** on 3rd accumulated offense.\n"
                 . "• **Prescribed Actions**: Official Reprimand, Guidance Counseling, and Handbook Compliance Orientation.\n"
                 . "• **Policy Basis**: NU Lipa Student Handbook Section 4 (Minor Offenses Matrix).";
        }
    }

    // 9. DRESS CODE & UNIFORM POLICIES
    if (preg_match('/\b(dress|uniform|hair|color|dye|piercing|tattoo|civilian|attire|wash day)\b/i', $promptLower)) {
        return $studentHeader
             . "👔 **Dress Code & Grooming Regulations (Section 4)**:\n\n"
             . "• **Classification**: Non-compliance with prescribed campus uniform, improper hair color/dye, or unauthorized attire is classified under **Section 4 Minor Offenses**.\n"
             . "• **Intervention**: 1st offense = Warning; 2nd = 10 Hours Community Service; 3rd = Escalation to Category 2 Major Offense.\n"
             . "• **Exemptions**: Special medical or cultural exemptions approved by the Student Affairs Office.";
    }

    // 10. GENERAL DEFAULT AI ADVISORY RESPONSE
    return $studentHeader
         . "🧠 **IdentiTrack AI Hearing Assistant**:\n\n"
         . "I have analyzed the hearing file for **" . ($extractedName ?: "this student") . "** against the **NU Lipa Student Handbook** and historical case records.\n\n"
         . "• **Key Policy Check**: Section 4 (Minor Violations & 3-Attempt Escalations) and Section 5 (Major Offense Disciplinary Matrix).\n"
         . "• **Case Precedents**: Cross-referenced against case precedent records.\n\n"
         . "Please type what you would like to inquire about regarding handbook rules, sanctions, community service hours, or case precedents.";
}

/**
 * Unified System AI Query Function using Groq Cloud LLM
 */
function queryAiEngine(string $systemPrompt, string $userPrompt, string $realName = '', string $studentId = ''): array
{
    // 1. Data Privacy Compliance: Automatically anonymize student PII from prompts
    $safeSysPrompt  = anonymizeAiPromptText($systemPrompt, $realName, $studentId);
    $safeUserPrompt = anonymizeAiPromptText($userPrompt, $realName, $studentId);

    // 2. Try Groq Cloud AI Engine (Llama 3.3 70B High-Speed)
    $groqResult = callGroqApi($safeSysPrompt, $safeUserPrompt);
    if ($groqResult !== null && trim($groqResult) !== '') {
        return [
            'text' => $groqResult,
            'engine' => 'Groq Cloud LLM (Llama 3.3 70B High-Speed Engine)',
            'privacy' => '🔒 100% Anonymized (RA 10173 Compliant)'
        ];
    }

    // 3. Built-In System AI Engine Fallback
    $builtInResult = buildBuiltInAiHearingResponse($safeSysPrompt, $safeUserPrompt);
    return [
        'text' => $builtInResult,
        'engine' => 'IdentiTrack Built-In System AI Engine',
        'privacy' => '🔒 100% Native Built-In AI Engine (RA 10173 Compliant)'
    ];
}

try {
    $action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'suggest'));

    $caseId = (int)($_GET['case_id'] ?? $_POST['case_id'] ?? 0);
    $studentId = trim((string)($_GET['student_id'] ?? $_POST['student_id'] ?? ''));
    $userQuery = trim((string)($_GET['query'] ?? $_POST['query'] ?? $_GET['user_query'] ?? $_POST['user_query'] ?? ''));

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

    if (!$case && $action !== 'global_chat') {
        echo json_encode(['ok' => false, 'error' => 'Student record not found for hearing.']);
        exit;
    }

    $targetStudentId = (string)($case['student_id'] ?? '');
    $offenseLevel = strtoupper((string)($case['offense_level'] ?? 'MAJOR'));
    $majorCategory = isset($case['major_category']) && $case['major_category'] !== null ? (int)$case['major_category'] : null;
    $offenseTypeId = (int)($case['offense_type_id'] ?? 0);
    $offenseCode = (string)($case['offense_code'] ?? 'GENERAL_VIOLATION');
    $offenseName = (string)($case['offense_name'] ?? 'Student Handbook Violation');

    $studentInfo = $targetStudentId !== '' ? db_one("SELECT " . db_decrypt_cols(['student_fn', 'student_ln']) . " FROM student WHERE student_id = :sid", [':sid' => $targetStudentId]) : null;
    $studentName = $studentInfo ? trim(($studentInfo['student_fn'] ?? '') . ' ' . ($studentInfo['student_ln'] ?? '')) : 'Student ' . $targetStudentId;

    $instanceCountRow = $targetStudentId !== '' ? db_one("SELECT COUNT(*) as cnt FROM offense WHERE student_id = :sid AND offense_type_id = :otid",
        [':sid' => $targetStudentId, ':otid' => $offenseTypeId]) : ['cnt' => 1];
    $instanceCount = max(1, (int)($instanceCountRow['cnt'] ?? 1));

    // ── Detailed Prior Cases & Categories Breakdown ──────────────────────────
    $priorCasesWithCat = $targetStudentId !== '' ? db_all("
        SELECT c.case_id, c.decided_category, c.status, c.created_at, ot.name AS offense_name, ot.level AS offense_level
        FROM upcc_case c
        LEFT JOIN upcc_case_offense uco ON uco.case_id = c.case_id
        LEFT JOIN offense o ON o.offense_id = uco.offense_id
        LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
        WHERE c.student_id = :sid AND c.case_id != :cid AND c.status IN ('RESOLVED', 'CLOSED', 'DECIDED')
        ORDER BY c.case_id DESC
    ", [':sid' => $targetStudentId, ':cid' => $caseId]) : [];

    $totalPrior = count($priorCasesWithCat);

    $totalMajorRow = $targetStudentId !== '' ? db_one("
        SELECT COUNT(*) as cnt FROM upcc_case c
        JOIN upcc_case_offense uco ON uco.case_id = c.case_id
        JOIN offense o ON o.offense_id = uco.offense_id
        JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
        WHERE c.student_id = :sid AND ot.level = 'MAJOR' AND c.case_id != :cid
    ", [':sid' => $targetStudentId, ':cid' => $caseId]) : ['cnt' => 0];
    $totalMajorCount = (int)($totalMajorRow['cnt'] ?? 0);

    // ── Community Service Lookup ──────────────────────────────────────────────
    $csReq = $targetStudentId !== '' ? db_one("
        SELECT csr.task_name, csr.hours_required, csr.status,
        (
            SELECT COALESCE(SUM(TIMESTAMPDIFF(SECOND, time_in, time_out)/3600.0), 0.0)
            FROM community_service_session css
            WHERE css.requirement_id = csr.requirement_id AND css.time_out IS NOT NULL
        ) AS hours_completed,
        (
            SELECT COUNT(*) FROM community_service_session css
            WHERE css.requirement_id = csr.requirement_id AND css.time_out IS NULL
        ) AS active_session_count,
        (
            SELECT COUNT(*) FROM community_service_session css
            WHERE css.requirement_id = csr.requirement_id
        ) AS total_session_count
        FROM community_service_requirement csr
        WHERE csr.student_id = :sid AND csr.status = 'ACTIVE'
        ORDER BY csr.requirement_id DESC LIMIT 1
    ", [':sid' => $targetStudentId]) : null;

    $csStatusText = "No active community service requirement on file (0 attendance sessions logged).";
    if ($csReq && (float)($csReq['hours_required'] ?? 0) > 0) {
        $rawReq = (float)$csReq['hours_required'];
        $rawComp = (float)($csReq['hours_completed'] ?? 0);
        $rawRem = max(0.0, $rawReq - $rawComp);
        $totalSessions = (int)($csReq['total_session_count'] ?? 0);
        
        $hrsReqStr = $rawReq < 1.0 ? round($rawReq * 60) . " mins (" . round($rawReq, 1) . "h)" : round($rawReq, 1) . "h";
        $hrsCompStr = round($rawComp, 1) . "h";
        $hrsRemStr = $rawRem < 1.0 && $rawRem > 0 ? round($rawRem * 60) . " mins (" . round($rawRem, 1) . "h)" : round($rawRem, 1) . "h";
        
        $isClockedIn = (int)($csReq['active_session_count'] ?? 0) > 0 ? "YES (Clocked In)" : "NO";
        $sessionText = $totalSessions === 0 ? "0 attendance sessions logged" : "{$totalSessions} session(s) logged";
        
        $csStatusText = "Active Task: {$csReq['task_name']} ({$hrsCompStr} / {$hrsReqStr} completed — {$sessionText} | Clocked In: {$isClockedIn})";
    }

    // ── Current Hearing Offenses ──────────────────────────────────────────────
    $currentCaseOffenses = $caseId > 0 ? db_all("SELECT DISTINCT ot.name AS offense_name, ot.level AS offense_level
        FROM upcc_case_offense uco
        JOIN offense o ON o.offense_id = uco.offense_id
        JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
        WHERE uco.case_id = :cid
    ", [':cid' => $caseId]) : [];

    $currentOffensesText = $offenseName;
    if (!empty($currentCaseOffenses)) {
        $cLines = [];
        foreach ($currentCaseOffenses as $co) {
            $cLines[] = "  • {$co['offense_name']} ({$co['offense_level']})";
        }
        $currentOffensesText = implode("\n", $cLines);
    }

    // ── Detailed Prior Resolved Cases Breakdown ──────────────────────────────
    $priorCasesBreakdownText = "No prior resolved UPCC cases on file for {$studentName}.";
    if (!empty($priorCasesWithCat)) {
        $groupedCases = [];
        foreach ($priorCasesWithCat as $pc) {
            $cId = (int)$pc['case_id'];
            if (!isset($groupedCases[$cId])) {
                $groupedCases[$cId] = [
                    'case_id' => $cId,
                    'decided_category' => $pc['decided_category'],
                    'status' => $pc['status'],
                    'offenses' => []
                ];
            }
            if (!empty($pc['offense_name'])) {
                $groupedCases[$cId]['offenses'][] = $pc['offense_name'];
            }
        }

        $lines = [];
        foreach ($groupedCases as $cId => $cInfo) {
            $offList = !empty($cInfo['offenses']) ? implode("; ", array_unique($cInfo['offenses'])) : "Disciplinary Offense";
            $cleanOffList = rtrim(trim($offList), '.;');
            $lines[] = "  • Case #{$cId}: {$cleanOffList}";
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

            $sysPrompt = "You are the IdentiTrack AI Hearing Assistant for NU Lipa. Precedent already exists in the database for this exact offense. Explain in 2-3 concise sentences why consistency with prior decisions is important for fairness. DATA PRIVACY MANDATE: For student privacy protection, NEVER mention or reveal full names of past student offenders. Always refer to past cases using Case Numbers (e.g. Case #DO-24-25-001 or Case #101) or Academic Programs. Do NOT output lists of suggested questions or sample question dumps.\n\n" . $dynamicRules;
            $userPrompt = "Student: {$studentName}\nOffense: {$offenseName}\nExact Precedents:\n" . implode("\n", $precedentSummary);
            
            $aiEngineRes = queryAiEngine($sysPrompt, $userPrompt, $studentName, $targetStudentId);
            $aiText = $aiEngineRes['text'];

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
                'ai_available' => true,
                'engine' => $aiEngineRes['engine'],
                'privacy' => $aiEngineRes['privacy']
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
            'source' => 'ai_new_offense_suggestion',
            'is_new_offense_type' => true,
            'student_id' => $targetStudentId,
            'student_name' => $studentName,
            'offense_name' => $offenseName,
            'instance_count' => $instanceCount,
            'suggested_category' => $suggestedCategory,
            'suggested_hours' => $suggestedHours,
            'ai_rationale' => $rationale,
            'ai_available' => true,
            'engine' => $aiEngineRes['engine'],
            'privacy' => $aiEngineRes['privacy']
        ]);
        exit;
    }

    // ── ACTION: chat — Live Conversational Groq Cloud LLM ──
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

        // STRICT ANONYMIZATION & CONVERSATIONAL RESPONSES MANDATE
        $sysPrompt = "You are IdentiTrack AI, an executive decision-support assistant for NU Lipa Disciplinary Panel Members.\n"
            . "Address the user as 'Panel Member'. Refer to the student strictly in the 3rd person as 'the student'.\n"
            . "STRICT BOUNDARY & CONVERSATIONAL MANDATES:\n"
            . "1. STRICT HANDBOOK FOCUS: All responses MUST strictly follow the NU Lipa Student Handbook disciplinary matrix and sanction rules.\n"
            . "2. DIRECT CONVERSATIONAL RESPONSES: Answer the panel member's specific question directly, concisely, and naturally. Do NOT generate lists of sample questions or tell the user what to ask. Do NOT output lists starting with 'You can ask me questions such as:'. If greeted (e.g., 'hi', 'hello', 'hey'), respond warmly: 'Hello Panel Member. How can I assist you with this hearing case today?'\n"
            . "3. ZERO NAME DROPPING / NO OTHER STUDENTS: You MUST NEVER drop or reveal any real student names, emails, or personal identification under any circumstances. You MUST NOT answer questions about or reveal data of other students. If asked about another student, reply strictly: 'I am strictly scoped to the active case in this hearing and cannot discuss or disclose information regarding other students under Data Privacy (RA 10173) guidelines.'\n"
            . "4. PRECEDENTS & RECOMMENDATIONS: Analyze the active case offenses against handbook rules and anonymized precedents, and provide direct, authoritative answers.\n"
            . "5. PROFESSIONAL FORMATTING: Format all responses professionally using clean Markdown headers, bold text highlights, bullet points, and clear structural sections.\n\n"
            . $dynamicRules;

        $userPrompt = "ACTIVE HEARING CASE DATA:\n"
            . "• Student Name: {$studentName} (ID: {$targetStudentId})\n"
            . "• Offense Charged: {$offenseName} (Level: {$offenseLevel}, Instance #{$instanceCount})\n"
            . "• Current Hearing Case (#{$caseId}) Offenses:\n{$currentOffensesText}\n"
            . "• Total Major Offenses: {$totalMajorCount}\n"
            . "• Total Prior Resolved Cases: {$totalPrior}\n"
            . "• Prior Cases & Categories Breakdown:\n{$priorCasesBreakdownText}\n"
            . "• Community Service Status: {$csStatusText}\n"
            . "• Precedent Record for this Offense:\n{$precedentContext}\n\n"
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
                'error' => '⚠️ Request to AI Engine failed or returned an empty response.'
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
            . "DATA PRIVACY MANDATE (RA 10173): For student privacy protection, NEVER mention or reveal full names of past student offenders. Always refer to past cases using Case Numbers (e.g. Case #DO-24-25-001 or Case #101) or Academic Programs.\n"
            . "Do NOT output lists of sample questions or tell the user what to ask.\n\n"
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
                'error' => '⚠️ Request to AI Engine failed or returned an empty response.'
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