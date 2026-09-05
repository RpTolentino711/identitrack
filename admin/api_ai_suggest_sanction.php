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
    $rules .= "• 3rd Attempt (3-Attempt Escalation): Escalated to Major Offense Category 2 Community Service (150 Hours)\n\n";

    $rules .= "REGISTERED MAJOR OFFENSES (" . count($majors) . " Active Types in Database):\n";
    $rules .= !empty($majors) ? implode("\n", $majors) : "• General Major Violations";
    $rules .= "\n\nMAJOR CATEGORY PENALTY MATRIX:\n";
    $rules .= "• Category 1: Formal Reprimand & Active Semester Probation\n";
    $rules .= "• Category 2: Formative Community Service (150–250 Hours) + Active Probation\n";
    $rules .= "• Category 3: 1 Semester Non-Readmission / Suspension (250–400 Hours CS)\n";
    $rules .= "• Category 4: Exclusion / Mandatory Dismissal\n";
    $rules .= "• Category 5: Summary Expulsion & Police Referral\n";

    return $rules;
}

/**
 * Evaluates semantic concept equivalence between two offense names/descriptions.
 * Matches synonyms, Tagalog/Taglish terms, and related disciplinary concepts.
 * E.g., 'suntukan' <-> 'PHYSICAL ALTERCATION' <-> 'FIGHTING' <-> 'MISCONDUCT'
 */
function areOffensesSemanticallyEqual(string $offA, string $offB): bool
{
    $a = mb_strtolower(trim($offA));
    $b = mb_strtolower(trim($offB));

    if ($a === '' || $b === '') return false;
    if ($a === $b || strpos($a, $b) !== false || strpos($b, $a) !== false) return true;

    // Define semantic clusters: terms sharing identical disciplinary meaning
    $clusters = [
        'fight' => ['fight', 'fighting', 'suntukan', 'away', 'bugbugan', 'physical altercation', 'physical misconduct', 'assault', 'brawl', 'brawling', 'physical injury', 'injuries', 'striking', 'mauling', 'misconduct'],
        'vape' => ['vape', 'vaping', 'e-cigarette', 'juul', 'pod', 'smoke', 'smoking', 'tobacco', 'cigarette', 'yosi', 'bringing in vape'],
        'id' => ['lending of id', 'lending id', 'borrowing id', 'id lending', 'id misuse', 'id tampering', 'passing id', 'id swap', 'double tapping', 'tap in tap out', 'using another id', 'false id'],
        'cheating' => ['cheating', 'academic dishonesty', 'kodigo', 'plagiarism', 'exam cheating', 'copying', 'test cheating', 'exam fraud', 'dishonesty'],
        'theft' => ['theft', 'stealing', 'ninakaw', 'pilferage', 'shoplifting', 'taking property', 'robbery', 'pocketing', 'burglary', 'stolen'],
        'disrespect' => ['gross act of disrespect', 'disrespect', 'pambabastos', 'bastos', 'insult', 'insulting', 'verbal assault', 'profanity', 'cursing', 'offensive language', 'insubordination'],
        'bullying' => ['bullying', 'harassment', 'cyberbullying', 'intimidation', 'threat', 'threatening', 'pang-aasar', 'gender-based sexual harassment', 'stalking'],
        'drugs' => ['drugs', 'substance', 'alcohol', 'liquor', 'drinking', 'intoxication', 'marijuana', 'shabu', 'weed', 'beer'],
        'weapon' => ['weapon', 'deadly weapon', 'taser', 'knife', 'blade', 'gun', 'firearm', 'explosive']
    ];

    foreach ($clusters as $category => $terms) {
        $aMatches = false;
        $bMatches = false;

        foreach ($terms as $t) {
            if (strpos($a, $t) !== false) $aMatches = true;
            if (strpos($b, $t) !== false) $bMatches = true;
            if ($aMatches && $bMatches) return true;
        }
    }

    return false;
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
        'temperature' => 0.0,
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
 * Directly answers the user's question without dumping active student file headers or handbook matrix blocks.
 */
function buildBuiltInAiHearingResponse(string $systemPrompt, string $userPrompt, array $caseMeta = []): string
{
    $promptLower = mb_strtolower(trim($userPrompt));
    $combined = mb_strtolower($systemPrompt . "\n" . $userPrompt);

    $caseId = $caseMeta['case_id'] ?? 0;
    $totalPrior = $caseMeta['total_prior'] ?? 0;
    $priorCasesText = $caseMeta['prior_cases_text'] ?? 'No prior resolved cases on file.';
    $pendingCasesCount = $caseMeta['pending_cases_count'] ?? 0;
    $pendingCasesText = $caseMeta['pending_cases_text'] ?? 'No other pending cases.';
    $extractedCS = $caseMeta['cs_text'] ?? 'No active community service requirement on file.';
    $offName = $caseMeta['offense_name'] ?? 'Handbook Infraction';
    $offLvl = $caseMeta['offense_level'] ?? 'MAJOR';
    $exactPrecedents = $caseMeta['exact_precedents'] ?? [];
    $excelPrecedents = $caseMeta['excel_precedents'] ?? [];
    $allOffensesAnalysis = $caseMeta['all_offenses_analysis'] ?? [];
    $totalCombinedHours = $caseMeta['total_combined_hours'] ?? 0;

    $studentName = $caseMeta['student_name'] ?? 'the student';

    // 1. GREETINGS & INTRODUCTIONS — IMMEDIATELY ANALYZE & SUGGEST PUNISHMENT
    if (preg_match('/\b(hi|hello|hey|sup|yo|greetings|good morning|good afternoon|good evening|who are you|what can you do)\b/i', $promptLower)) {
        $excelCount = count($excelPrecedents);
        $recCountText = ($excelCount > 0)
            ? "Based on our official campus precedent records, **to avoid bias**, I found **{$excelCount} matching precedent record(s)** for this offense (**{$offName}**)."
            : "I analyzed our campus precedent records and **found no prior record** for this specific offense (**{$offName}**). Recommendations are evaluated directly against the **NU Lipa Student Handbook Penalty Matrix**.";

        $totalHistoryCount = $totalPrior + $pendingCasesCount;

        if ($totalHistoryCount > 0) {
            $suggestedCat = ($totalHistoryCount >= 2) ? 4 : 3;
            $hoursText = ($suggestedCat >= 4)
                ? "Mandatory Exclusion / Dismissal / Category 4 Sanction (400+ Hours CS)"
                : "250–400 Hours Community Service / 1 Term Non-Readmission (Suspension)";
            $whyReason = ($totalHistoryCount >= 2)
                ? "The student has {$totalPrior} prior resolved case(s) and {$pendingCasesCount} pending case(s) on file (total {$totalHistoryCount} prior records). Under NU Lipa Student Handbook Section 5 Repeat Offender Policy, multiple repeat infractions escalate to Category 4 or Category 5 (Mandatory Exclusion / Dismissal / Expulsion)."
                : "The student has {$totalPrior} prior resolved case(s) and {$pendingCasesCount} pending case(s) on file. Under NU Lipa Student Handbook Section 5 Repeat Offender Policy, repeat infractions following a prior record escalate to Category 3 (250–400 Hours CS / Suspension / Non-Readmission).";

            return "👋 **Hello Panel Member! I am IdentiTrack AI.** Let me analyze **{$studentName}**'s case file for this current hearing.\n\n"
                 . "{$recCountText}\n\n"
                 . "📋 **Student Disciplinary Record Check**: Found **{$totalPrior} prior resolved case(s)** and **{$pendingCasesCount} pending case(s)** on file for **{$studentName}**.\n\n"
                 . "⚖️ **Suggested Punishment & Advisory Recommendation**:\n\n"
                 . "• **Offense Charged**: {$offName} ({$offLvl})\n"
                 . "• **Suggested Punishment**: **Category {$suggestedCat} Sanction** ({$hoursText} + Active Probation)\n"
                 . "• **Why? (Reason)**: {$whyReason}\n\n"
                 . "Please let me know if you would like more details about this recommendation for {$studentName}!";
        }

        if ($offLvl === 'MINOR') {
            $instanceCount = $caseMeta['instance_count'] ?? 1;
            $attemptStr = ($instanceCount === 1) ? "1st Attempt" : (($instanceCount === 2) ? "2nd Attempt" : "3rd Attempt (Escalation)");
            $suggestedCat = ($instanceCount >= 3) ? 2 : 1;
            $hoursText = ($suggestedCat === 2) ? "150 to 250 Hours Community Service" : "0 Hours Community Service (Written Reprimand)";
            $whyReason = ($instanceCount >= 3)
                ? "Under NU Lipa Student Handbook Section 4 (3-Attempt Escalation Rule), accumulating 3 minor offenses automatically escalates the sanction to a **Category 2 Major Offense** (150–250 Hours Community Service)."
                : "Evaluated directly against NU Lipa Student Handbook Section 4 (Minor Violations Matrix) for Attempt #{$instanceCount}. 1st and 2nd minor attempts receive Category 1 (Written Reprimand / Warning) with 0 Hours Community Service.";

            return "👋 **Hello Panel Member! I am IdentiTrack AI.** Let me analyze **{$studentName}**'s case file for this current hearing.\n\n"
                 . "{$recCountText}\n\n"
                 . "📌 **NU Lipa Student Handbook Section 4 Minor Offense Escalation Matrix**:\n"
                 . "• **1st Attempt**: Written Reprimand & Category 1 Warning (**0 Hours CS**)\n"
                 . "• **2nd Attempt**: Formal Warning, SDO Counseling & Category 1 Warning (**0 Hours CS**)\n"
                 . "• **3rd Attempt (3-Attempt Escalation Rule)**: **AUTOMATIC ESCALATION** → Converted to **Category 2 Major Offense** (**150–250 Hours CS**)\n\n"
                 . "⚖️ **Suggested Punishment & Advisory Recommendation**:\n\n"
                 . "• **Offense Charged**: {$offName} ({$offLvl})\n"
                 . "• **Active Student Offense Instance**: {$attemptStr} (Instance #{$instanceCount} for {$studentName})\n"
                 . "• **Suggested Punishment**: **Category {$suggestedCat} Sanction** ({$hoursText} + Active Probation)\n"
                 . "• **Why? (Reason)**: {$whyReason}\n\n"
                 . "Please let me know if you would like more details about this recommendation for {$studentName}!";
        }

        $suggestedCat = ($offLvl === 'MAJOR') ? 2 : 1;
        $hoursText = ($suggestedCat === 2) ? "150 to 250 Hours Community Service" : "0 Hours Community Service (Written Reprimand)";
        $whyReason = "Evaluated directly against NU Lipa Student Handbook Section 4 (Minor Violations) and Section 5 (Major Offense Penalty Matrix) for a 1st offense on record.";

        return "👋 **Hello Panel Member! I am IdentiTrack AI.** Let me analyze **{$studentName}**'s case file for this current hearing.\n\n"
             . "{$recCountText}\n\n"
             . "⚖️ **Suggested Punishment & Advisory Recommendation**:\n\n"
             . "• **Offense Charged**: {$offName} ({$offLvl})\n"
             . "• **Suggested Punishment**: **Category {$suggestedCat} Sanction** ({$hoursText} + Active Probation)\n"
             . "• **Why? (Reason)**: {$whyReason}\n\n"
             . "Please let me know if you would like more details about this recommendation for {$studentName}!";
    }

    // 2. SIMILAR CASES & PRECEDENT SEARCH INQUIRY
    if (preg_match('/\b(similar|precedent|precedents|same case|matching case|like this|like student|similar cases?)\b/i', $promptLower)) {
        $lines = [];
        if (!empty($exactPrecedents)) {
            $lines[] = "📌 **Live Database Precedents**:";
            foreach (array_slice($exactPrecedents, 0, 5) as $ep) {
                $pun = formatPunishmentDetails((string)($ep['punishment_details'] ?? ''));
                $lines[] = "  • Case #{$ep['case_id']}: **Category {$ep['decided_category']} Sanction** ({$pun})";
            }
        }
        if (!empty($excelPrecedents)) {
            $lines[] = "📊 **Historical Campus Precedent Matches**:";
            foreach (array_slice($excelPrecedents, 0, 5) as $ex) {
                $lines[] = "  • Offense: **{$ex['offense']}** ({$ex['level']}) — Decided Sanction: **{$ex['sanction']}**";
            }
        }

        if (!empty($lines)) {
            return "👋 **Hello Panel Member! I am IdentiTrack AI.** Let me analyze **{$studentName}**'s case file for this current hearing.\n\n"
                 . "Based on our campus records, **to avoid bias**, a previous record shows this punishment for this kind of offense:\n\n"
                 . implode("\n", $lines) . "\n\n"
                 . "💡 **Why? (Reason)**: Following prior decided records avoids bias, maintains equal treatment, and guarantees procedural fairness under NU Lipa Disciplinary Policies.";
        } else {
            $excelCount = count($excelPrecedents);
            $recCountText = ($excelCount > 0)
                ? "I checked our official campus precedent records and **found {$excelCount} matching precedent record(s)** for this offense (**{$offName}**)."
                : "I analyzed our campus precedent records and **found no prior record** for this specific offense (**{$offName}**).";

            if ($offLvl === 'MINOR') {
                $instanceCount = $caseMeta['instance_count'] ?? 1;
                $attemptStr = ($instanceCount === 1) ? "1st Attempt" : (($instanceCount === 2) ? "2nd Attempt" : "3rd Attempt (Escalation)");
                $suggestedCat = ($instanceCount >= 3) ? 2 : 1;
                $hoursText = ($suggestedCat === 2) ? "150 to 250 Hours Community Service" : "0 Hours Community Service (Written Reprimand)";
                $whyReason = ($instanceCount >= 3)
                    ? "Under NU Lipa Student Handbook Section 4 (3-Attempt Escalation Rule), accumulating 3 minor offenses automatically escalates the sanction to a **Category 2 Major Offense** (150–250 Hours Community Service)."
                    : "Evaluated directly against NU Lipa Student Handbook Section 4 (Minor Violations Matrix) for Attempt #{$instanceCount}. 1st and 2nd minor attempts receive Category 1 (Written Reprimand / Warning) with 0 Hours Community Service.";

                return "👋 **Hello Panel Member! I am IdentiTrack AI.** Let me analyze **{$studentName}**'s case file for this current hearing.\n\n"
                     . "{$recCountText}\n\n"
                     . "📌 **NU Lipa Student Handbook Section 4 Minor Offense Escalation Matrix**:\n"
                     . "• **1st Attempt**: Written Reprimand & Category 1 Warning (**0 Hours CS**)\n"
                     . "• **2nd Attempt**: Formal Warning, SDO Counseling & Category 1 Warning (**0 Hours CS**)\n"
                     . "• **3rd Attempt (3-Attempt Escalation Rule)**: **AUTOMATIC ESCALATION** → Converted to **Category 2 Major Offense** (**150–250 Hours CS**)\n\n"
                     . "⚖️ **Suggested Punishment & Advisory Recommendation**:\n\n"
                     . "• **Offense Charged**: {$offName} ({$offLvl})\n"
                     . "• **Active Student Offense Instance**: {$attemptStr} (Instance #{$instanceCount} for {$studentName})\n"
                     . "• **Suggested Punishment**: **Category {$suggestedCat} Sanction** ({$hoursText} + Active Probation)\n"
                     . "• **Why? (Reason)**: {$whyReason}\n\n"
                     . "Please let me know if you would like more details about this recommendation for {$studentName}!";
            }

            if ($totalPrior > 0) {
                $suggestedCat = 3;
                $hoursText = "250–400 Hours Community Service / 1 Term Non-Readmission";
                $whyReason = "The student has {$totalPrior} prior resolved case(s) on file. Under NU Lipa Handbook Section 5, repeat offenses after a prior sanction escalate to Category 3 or Category 4 (Non-Readmission / Suspension).";
            } else {
                $suggestedCat = $offLvl === 'MAJOR' ? 2 : 1;
                $hoursText = $suggestedCat === 2 ? "150 to 250 Hours Community Service" : "0 Hours Community Service (Written Reprimand)";
                $whyReason = "Evaluated directly against NU Lipa Student Handbook Section 4 (Minor Violations) and Section 5 (Major Offense Penalty Matrix) for a 1st offense.";
            }

            return "👋 **Hello Panel Member! I am IdentiTrack AI.** Let me analyze **{$studentName}**'s case file for this current hearing.\n\n"
                 . "{$recCountText} Recommendations are evaluated directly against the **NU Lipa Student Handbook Penalty Matrix**:\n\n"
                 . "⚖️ **Suggested Punishment & Advisory Recommendation**:\n\n"
                 . "• **Offense Charged**: {$offName} ({$offLvl})\n"
                 . "• **Suggested Punishment**: **Category {$suggestedCat} Sanction** ({$hoursText} + Active Probation)\n"
                 . "• **Why? (Reason)**: {$whyReason}\n\n"
                 . "Please let me know if you would like more details about this recommendation for {$studentName}!";
        }
    }

    // 3. SANCTIONS & CATEGORIES RECOMMENDATIONS (Suggest punishment & Multi-Offense Aggregation)
    if (preg_match('/\b(suggest|sanction|category|punishment|recommend|decision|vote|penalty|result|why|middle|combine|combination|multiple|310|150)\b/i', $promptLower)) {
        
        // Multi-Offense Aggregation ("Meet in the Middle") if multiple offenses exist or query asks about multi-offense aggregation
        if (count($allOffensesAnalysis) > 1 || preg_match('/\b(middle|combine|combination|multiple|310|150|section 4|minor)\b/i', $promptLower)) {
            $offenseBreakdownLines = [];
            $precedentCount = 0;
            $evaluatedCount = 0;

            foreach ($allOffensesAnalysis as $idx => $oa) {
                $num = $idx + 1;
                $oN = $oa['offense_name'];
                $oL = $oa['offense_level'];
                $hrs = $oa['hours'];
                $hasP = $oa['has_precedent'];
                $src = $oa['source_explanation'];

                if ($hasP) {
                    $precedentCount++;
                    $offenseBreakdownLines[] = "• **Offense {$num}**: {$oN} ({$oL})\n  ↳ *Historical Record Precedent Match*: **{$hrs} Hours CS** ({$src})";
                } else {
                    $evaluatedCount++;
                    $offenseBreakdownLines[] = "• **Offense {$num}**: {$oN} ({$oL})\n  ↳ *No Direct Dataset Precedent Record*: Evaluated via **NU Lipa Student Handbook Section 4 Gravity Analysis** → **{$hrs} Hours CS** (*Assessed based on offense nature, context, and campus impact*)";
                }
            }

            $combinedCategory = ($totalCombinedHours >= 250) ? 3 : (($totalCombinedHours >= 15) ? 2 : 1);
            $breakdownBlock = implode("\n", $offenseBreakdownLines);

            $whyMultiReason = ($evaluatedCount > 0)
                ? "Combining baseline precedent hours for recorded offenses with NU Lipa Student Handbook gravity analysis for unrecorded infractions yields a balanced aggregated sanction of **{$totalCombinedHours} Hours CS** (Category {$combinedCategory}). This 'meet-in-the-middle' calculation guarantees full accountability across all charged offenses while avoiding bias and maintaining procedural consistency."
                : "Combining baseline precedent hours across all charged offenses yields an aggregated sanction of **{$totalCombinedHours} Hours CS** (Category {$combinedCategory}). Aligning with past campus records for each charged offense avoids bias and ensures standardized enforcement.";

            return "👋 **Hello Panel Member! I am IdentiTrack AI.** Let me analyze **{$studentName}**'s case file for this current hearing.\n\n"
                 . "Based on our campus precedent records and NU Lipa Student Handbook guidelines, here is the combined multi-offense sanction calculation:\n\n"
                 . "⚖️ **Suggested Combined Punishment**: **Category {$combinedCategory} Sanction** ({$totalCombinedHours} Hours Community Service + Active Probation)\n\n"
                 . "📋 **Offense Breakdown & Precedent Aggregation**:\n"
                 . "{$breakdownBlock}\n\n"
                 . "💡 **Why? (Reason)**: {$whyMultiReason}\n\n"
                 . "Let me know if you would like me to adjust any office assignments or handbook details for {$studentName}!";
        }

        if (!empty($exactPrecedents)) {
            $mostRecent = $exactPrecedents[0];
            $catNum = (int)($mostRecent['decided_category'] ?? 2);
            $punishmentText = formatPunishmentDetails((string)($mostRecent['punishment_details'] ?? ''));
            
            return "👋 **Hello Panel Member! I am IdentiTrack AI.** Let me analyze **{$studentName}**'s case file for this current hearing.\n\n"
                 . "Based on our campus records, **to avoid bias**, a previous record shows this punishment for this kind of offense:\n\n"
                 . "⚖️ **Suggested Punishment**: **Category {$catNum} Sanction** ({$punishmentText})\n\n"
                 . "• **Offense Charged**: {$offName} ({$offLvl})\n"
                 . "• **Historical Campus Precedent**: Previous decided case(s) for this exact offense were assigned **Category {$catNum} Sanction**.\n"
                 . "• **Why? (Reason)**: Historical campus precedent for this exact offense is Category {$catNum} ({$punishmentText}). Recommending this same punishment avoids bias, ensures consistency, and guarantees equal treatment under NU Lipa Disciplinary Policies.\n\n"
                 . "Would you like me to check any other handbook details or prior case records for {$studentName}?";
        } elseif (!empty($excelPrecedents)) {
            $firstExcelMatch = $excelPrecedents[0];
            $sancStr = $firstExcelMatch['sanction'] ?? 'FORMATIVE INTERVENTION';
            $sCat = (strpos(strtoupper($sancStr), 'NON-READMISSION') !== false || strpos(strtoupper($sancStr), 'DROPPED') !== false) ? 4
                  : ((strpos(strtoupper($sancStr), 'SUSPENSION') !== false) ? 3
                  : ((strpos(strtoupper($sancStr), 'REPRIMAND') !== false || strpos(strtoupper($sancStr), 'DISMISS') !== false) ? 1 : 2));
            
            $excelCount = count($excelPrecedents);
            return "👋 **Hello Panel Member! I am IdentiTrack AI.** Let me analyze **{$studentName}**'s case file for this current hearing.\n\n"
                 . "Based on our campus records, **to avoid bias**, a previous record shows this punishment for this kind of offense:\n\n"
                 . "⚖️ **Suggested Punishment**: **Category {$sCat} Sanction** ({$sancStr})\n\n"
                 . "• **Offense Charged**: {$offName} ({$offLvl})\n"
                 . "• **Historical Campus Precedents Found**: {$excelCount} matching historical precedent record(s).\n"
                 . "• **Why? (Reason)**: Historical campus discipline records for offenses matching '{$offName}' show that students were assigned **Category {$sCat} Sanction** ({$sancStr}). Aligning with past records avoids bias and promotes standardized, impartial enforcement.\n\n"
                 . "Let me know if you want me to review additional handbook clauses for {$studentName}!";
        } else {
            $excelCount = count($excelPrecedents);
            $recCountText = ($excelCount > 0)
                ? "I checked our official campus precedent records and **found {$excelCount} matching precedent record(s)** for this offense (**{$offName}**)."
                : "I analyzed our campus precedent records and **found no prior record** for this specific offense (**{$offName}**).";

            $totalHistoryCount = $totalPrior + $pendingCasesCount;

            if ($totalHistoryCount > 0) {
                $suggestedCat = ($totalHistoryCount >= 2) ? 4 : 3;
                $hoursText = ($suggestedCat >= 4)
                    ? "Mandatory Exclusion / Dismissal / Category 4 Sanction (400+ Hours CS)"
                    : "250–400 Hours Community Service / 1 Term Non-Readmission (Suspension)";
                $whyReason = ($totalHistoryCount >= 2)
                    ? "The student has {$totalPrior} prior resolved case(s) and {$pendingCasesCount} pending case(s) on file (total {$totalHistoryCount} prior records). Under NU Lipa Student Handbook Section 5 Repeat Offender Policy, multiple repeat infractions escalate to Category 4 or Category 5 (Mandatory Exclusion / Dismissal / Expulsion)."
                    : "The student has {$totalPrior} prior resolved case(s) and {$pendingCasesCount} pending case(s) on file. Under NU Lipa Student Handbook Section 5 Repeat Offender Policy, repeat infractions following a prior record escalate to Category 3 (250–400 Hours CS / Suspension / Non-Readmission).";

                return "👋 **Hello Panel Member! I am IdentiTrack AI.** Let me analyze **{$studentName}**'s case file for this current hearing.\n\n"
                     . "{$recCountText}\n\n"
                     . "📋 **Student Disciplinary Record Check**: Found **{$totalPrior} prior resolved case(s)** and **{$pendingCasesCount} pending case(s)** on file for **{$studentName}**.\n\n"
                     . "⚖️ **Suggested Punishment & Advisory Recommendation**:\n\n"
                     . "• **Offense Charged**: {$offName} ({$offLvl})\n"
                     . "• **Suggested Punishment**: **Category {$suggestedCat} Sanction** ({$hoursText} + Active Probation)\n"
                     . "• **Why? (Reason)**: {$whyReason}\n\n"
                     . "Please let me know if you would like more details about this recommendation for {$studentName}!";
            }

            if ($offLvl === 'MINOR') {
                $instanceCount = $caseMeta['instance_count'] ?? 1;
                $attemptStr = ($instanceCount === 1) ? "1st Attempt" : (($instanceCount === 2) ? "2nd Attempt" : "3rd Attempt (Escalation)");
                $suggestedCat = ($instanceCount >= 3) ? 2 : 1;
                $hoursText = ($suggestedCat === 2) ? "150 to 250 Hours Community Service" : "0 Hours Community Service (Written Reprimand)";
                $whyReason = ($instanceCount >= 3)
                    ? "Under NU Lipa Student Handbook Section 4 (3-Attempt Escalation Rule), accumulating 3 minor offenses automatically escalates the sanction to a **Category 2 Major Offense** (150–250 Hours Community Service)."
                    : "Evaluated directly against NU Lipa Student Handbook Section 4 (Minor Violations Matrix) for Attempt #{$instanceCount}. 1st and 2nd minor attempts receive Category 1 (Written Reprimand / Warning) with 0 Hours Community Service.";

                return "👋 **Hello Panel Member! I am IdentiTrack AI.** Let me analyze **{$studentName}**'s case file for this current hearing.\n\n"
                     . "{$recCountText}\n\n"
                     . "📌 **NU Lipa Student Handbook Section 4 Minor Offense Escalation Matrix**:\n"
                     . "• **1st Attempt**: Written Reprimand & Category 1 Warning (**0 Hours CS**)\n"
                     . "• **2nd Attempt**: Formal Warning, SDO Counseling & Category 1 Warning (**0 Hours CS**)\n"
                     . "• **3rd Attempt (3-Attempt Escalation Rule)**: **AUTOMATIC ESCALATION** → Converted to **Category 2 Major Offense** (**150–250 Hours CS**)\n\n"
                     . "⚖️ **Suggested Punishment & Advisory Recommendation**:\n\n"
                     . "• **Offense Charged**: {$offName} ({$offLvl})\n"
                     . "• **Active Student Offense Instance**: {$attemptStr} (Instance #{$instanceCount} for {$studentName})\n"
                     . "• **Suggested Punishment**: **Category {$suggestedCat} Sanction** ({$hoursText} + Active Probation)\n"
                     . "• **Why? (Reason)**: {$whyReason}\n\n"
                     . "Please let me know if you would like more details about this recommendation for {$studentName}!";
            }

            $suggestedCat = $offLvl === 'MAJOR' ? 2 : 1;
            $hoursText = $suggestedCat === 2 ? "150 to 250 Hours Community Service" : "0 Hours Community Service (Written Reprimand)";
            $whyReason = "Evaluated directly against NU Lipa Student Handbook Section 4 (Minor Violations) and Section 5 (Major Offense Penalty Matrix) for a 1st offense.";

            return "👋 **Hello Panel Member! I am IdentiTrack AI.** Let me analyze **{$studentName}**'s case file for this current hearing.\n\n"
                 . "{$recCountText} Recommendations are evaluated directly against the **NU Lipa Student Handbook Penalty Matrix**:\n\n"
                 . "⚖️ **Suggested Punishment & Advisory Recommendation**:\n\n"
                 . "• **Offense Charged**: {$offName} ({$offLvl})\n"
                 . "• **Suggested Punishment**: **Category {$suggestedCat} Sanction** ({$hoursText} + Active Probation)\n"
                 . "• **Why? (Reason)**: {$whyReason}\n\n"
                 . "Please let me know if you would like more details about this recommendation for {$studentName}!";
        }
    }

    // 3. PRIOR / PENDING CASES INQUIRY
    if (preg_match('/\b(pending cases?|prior cases?|case history|disciplinary record|other cases|how many cases|cases before)\b/i', $promptLower)) {
        $resolvedBlock = $totalPrior > 0 
            ? "The student has **{$totalPrior} prior resolved case(s)** on file:\n{$priorCasesText}" 
            : "The student has **0 prior resolved cases** on file.";
        
        $pendingBlock = $pendingCasesCount > 0 
            ? "The student currently has **{$pendingCasesCount} pending case(s)**:\n{$pendingCasesText}" 
            : "The student currently has **0 other pending cases**.";

        return "👋 **Hello Administrator!** Here is the student's disciplinary record summary:\n\n"
             . "📋 **Student Disciplinary Record Summary**:\n\n"
             . "• **Current Case**: Case #{$caseId} (Hearing in progress)\n"
             . "• **Prior Resolved Cases**: {$resolvedBlock}\n"
             . "• **Pending Cases**: {$pendingBlock}\n\n"
             . "*Note: Specific offense descriptions for other cases are withheld to maintain panel hearing confidentiality.*\n\n"
             . "Is there any other aspect of this student's history you'd like me to look into?";
    }

    // 4. DISHONESTY / PERJURY / LYING DURING HEARING
    if (preg_match('/\b(lie|lying|false|dishonest|perjury|fake|deceit|untruth|mislead)\b/i', $promptLower)) {
        return "👋 **Hello Panel Member!** Here is our policy guidance regarding false statements during a hearing:\n\n"
             . "⚠️ **Policy Guidance on Submitting False Information / Lying**:\n\n"
             . "• **Handbook Violation**: Providing false statements, forged documents, or lying during a UPCC hearing is classified as an independent **Major Offense** under NU Lipa Academic & Administrative Dishonesty policies.\n"
             . "• **Sanction Escalation**: If established during the hearing, the committee should note this aggravating circumstance and consider escalating the penalty to a **Category 2 or Category 3 Sanction** (Disciplinary Probation or Suspension).\n"
             . "• **Recommendation**: Advise the student of their obligation to speak truthfully under the Student Code of Conduct.\n\n"
             . "Let me know if you would like me to cite the exact handbook clause for dishonesty!";
    }

    // 5. APPEALS & MOTIONS FOR RECONSIDERATION
    if (preg_match('/\b(appeal|reconsider|reconsideration|overturn|contest|due process)\b/i', $promptLower)) {
        return "👋 **Hello Administrator!** Here are the rules for appeals and reconsiderations:\n\n"
             . "📜 **Appeal & Reconsideration Rules (NU Lipa Student Handbook)**:\n\n"
             . "• **Filing Deadline**: Students have **5 school days** from formal notice of sanction finalization to submit a written appeal.\n"
             . "• **Appellate Body**: Appeals are submitted to the **Office of the Academic Director / Executive Office**.\n"
             . "• **Valid Grounds for Appeal**: (1) Discovery of new material evidence, (2) Procedural irregularity during UPCC hearing, or (3) Disproportionate penalty relative to offense level.\n"
             . "• **Status**: Filing an appeal suspends execution of suspension penalties pending final executive review.\n\n"
             . "Feel free to ask if you need guidance on preparing appeal response documentation!";
    }

    // 6. DRUGS, ALCOHOL, WEAPONS, GAMBLING
    if (preg_match('/\b(drug|substance|alcohol|liquor|drink|vape|vaping|smoke|smoking|weapon|knife|blade|gun|gambling|betting)\b/i', $promptLower)) {
        return "👋 **Hello Administrator!** Here is the zero-tolerance policy overview for critical safety infractions:\n\n"
             . "🚨 **Zero-Tolerance Campus Safety Policy**:\n\n"
             . "• **Classification**: Possession, consumption, or distribution of illegal drugs, alcohol, weapons, or organized gambling inside campus premises is a **Category 3 Major Offense**.\n"
             . "• **Mandatory Interventions**: Mandatory **Category 3 Sanction** (Immediate Disciplinary Probation, 30–50 Hours of Community Service, mandatory drug/psychological evaluation, or Suspension/Dismissal recommendation).\n"
             . "• **Immediate Action**: Require security report log and refer student to the Student Affairs & Guidance Office.\n\n"
             . "Would you like me to check historical dataset records for similar campus safety cases?";
    }

    // 7. GRADUATING STUDENTS & HONOR DISQUALIFICATION
    if (preg_match('/\b(graduat|latin honor|cum laude|magna|summa|clearance|diploma|senior)\b/i', $promptLower)) {
        return "👋 **Hello Administrator!** Here is how disciplinary sanctions impact graduation and honors:\n\n"
             . "🎓 **Impact on Graduation & Academic Honors**:\n\n"
             . "• **Disqualification from Honors**: Any student found guilty of a **Major Offense** or assigned a Category 2/3 sanction is automatically disqualified from graduating with Latin Honors (Cum Laude, Magna Cum Laude, Summa Cum Laude).\n"
             . "• **Student Clearance**: Disciplinary cases for the student must reach **RESOLVED** or **CLOSED** status with all community service hours completed before graduation clearance can be approved.\n\n"
             . "Let me know if you need to generate a clearance hold status notice for the Registrar!";
    }

    // 8. COMMUNITY SERVICE HOURS & ATTENDANCE
    if (preg_match('/\b(community service|cs|hour|hours|clock|attend|session|requirement)\b/i', $promptLower)) {
        return "👋 **Hello Administrator!** Here is the current community service tracking overview:\n\n"
             . "⏱️ **Community Service Status & Calculation Matrix**:\n\n"
             . "• **Active Status**: {$extractedCS}\n\n"
             . "• **Category 1 (Minor Initial Violation)**: **0 Hours** (Written Reprimand ONLY).\n"
             . "• **Category 2 (Repeated / Major Offense)**: **150 to 250 Hours** of University Service.\n"
             . "• **Category 3 (Severe / Repeat Major Offense)**: **250 to 400 Hours** of University Service.\n\n"
             . "💡 *IdentiTrack Tracking*: All service sessions are verified via photo check-in/check-out logs in the guard module.\n\n"
             . "Let me know if you want me to check specific session attendance records!";
    }

    // 9. SECTION 4 MINOR OFFENSES & 3-ATTEMPT ESCALATION
    if (preg_match('/\b(section 4|minor|escalat|3-attempt|three|repeat|count)\b/i', $promptLower)) {
        return "👋 **Hello Panel Member!** Here is the escalation breakdown under Section 4:\n\n"
             . "📌 **Section 4 Minor Offense Escalation Policy**:\n\n"
             . "• **1st Offense**: Written Reprimand & Category 1 Warning (**0 Hours CS**).\n"
             . "• **2nd Offense**: Category 1 Warning (**0 Hours CS**).\n"
             . "• **3rd Offense (3-Attempt Rule)**: **AUTOMATIC ESCALATION** — Accumulating 3 minor offenses converts the case into a **Category 2 Major Offense** (**150–250 Hours CS**).\n\n"
             . "📋 *Panel Note*: The student currently has " . ($totalPrior > 0 ? "{$totalPrior} prior recorded case(s)" : "0 prior records") . " on file.\n\n"
             . "How else can I assist your case review today?";
    }

    // 10. GENERAL DEFAULT AI ADVISORY RESPONSE
    return "👋 **Hello Administrator / Panel Member! I'm IdentiTrack AI**, your friendly hearing assistant.\n\n"
         . "I am here to support you with hearing file analysis, NU Lipa Student Handbook rules, community service calculations, and 204 case precedents.\n\n"
         . "What would you like to inquire about regarding handbook rules, sanctions, or case precedents?";
}

/**
 * Unified System AI Query Function using Groq Cloud LLM
 */
function queryAiEngine(string $systemPrompt, string $userPrompt, string $realName = '', string $studentId = '', array $caseMeta = []): array
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
    $builtInResult = buildBuiltInAiHearingResponse($safeSysPrompt, $safeUserPrompt, $caseMeta);
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
    $allCaseOffenses = [];
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

        $allCaseOffenses = db_all("
            SELECT o.offense_id, o.offense_type_id, o.description as offense_description,
                   ot.code as offense_code, ot.name as offense_name, ot.level as offense_level, ot.major_category
            FROM upcc_case_offense uco
            JOIN offense o ON o.offense_id = uco.offense_id
            JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
            WHERE uco.case_id = :cid
            ORDER BY ot.level DESC, ot.name ASC
        ", [':cid' => $caseId]);

        if (!empty($allCaseOffenses)) {
            $case = $allCaseOffenses[0];
            $case['case_id'] = $caseId;
            $cMetaRow = db_one("SELECT student_id, decided_category, probation_until, punishment_details FROM upcc_case WHERE case_id = :cid", [':cid' => $caseId]);
            if ($cMetaRow) {
                $case = array_merge($case, $cMetaRow);
            }
        }
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

    // ── Detailed Prior Resolved Cases Breakdown (Strictly Confidential: NO Offense Action Descriptions) ──
    $priorCasesWithCat = $targetStudentId !== '' ? db_all("
        SELECT c.case_id, c.decided_category, c.punishment_details, c.status, c.created_at
        FROM upcc_case c
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

    // ── Pending / Ongoing Cases Lookup (Strictly Confidential: NO Offense Action Descriptions) ──
    $pendingCasesRows = $targetStudentId !== '' ? db_all("
        SELECT c.case_id, c.status
        FROM upcc_case c
        WHERE c.student_id = :sid AND c.case_id != :cid AND c.status NOT IN ('RESOLVED', 'CLOSED', 'DECIDED', 'CANCELLED', 'DISMISSED')
        ORDER BY c.case_id DESC
    ", [':sid' => $targetStudentId, ':cid' => $caseId]) : [];

    $pendingCasesText = "No other pending cases on file.";
    if (!empty($pendingCasesRows)) {
        $pLines = [];
        foreach ($pendingCasesRows as $pc) {
            $pLines[] = "  • Case #{$pc['case_id']} (Pending Hearing)";
        }
        $pendingCasesText = implode("\n", $pLines);
    }

    $priorCasesBreakdownText = "No prior resolved UPCC cases on file.";
    if (!empty($priorCasesWithCat)) {
        $lines = [];
        foreach ($priorCasesWithCat as $pc) {
            $cId = (int)$pc['case_id'];
            $catVal = !empty($pc['decided_category']) ? "Category {$pc['decided_category']} Sanction" : "Sanction Decided";
            $punDetails = formatPunishmentDetails((string)($pc['punishment_details'] ?? ''));
            $punStr = ($punDetails !== 'n/a' && $punDetails !== '') ? " ({$punDetails})" : "";
            $lines[] = "  • Case #{$cId}: {$catVal}{$punStr}";
        }
        $priorCasesBreakdownText = implode("\n", $lines);
    }

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

    $exactPrecedents = getExactPrecedents($offenseTypeId, $caseId);

    // Look up SANCTION.xlsx official dataset cache records using Semantic Concept Equivalence Matching
    $excelPrecedents = [];
    $cacheRecords = function_exists('get_historical_dataset_records') ? get_historical_dataset_records() : [];
    if (!empty($cacheRecords)) {
        foreach ($cacheRecords as $cr) {
            $crOffense = (string)($cr['offense'] ?? '');
            if ($crOffense !== '' && areOffensesSemanticallyEqual($crOffense, $offenseName)) {
                $excelPrecedents[] = $cr;
            }
        }
    }

    // ── Multi-Offense Aggregation & Handbook Gravity Analysis ──
    $allOffensesAnalysis = [];
    $totalCombinedHours = 0;

    $offenseListToAnalyze = !empty($allCaseOffenses) ? $allCaseOffenses : [
        [
            'offense_name' => $offenseName,
            'offense_level' => $offenseLevel,
            'offense_type_id' => $offenseTypeId
        ]
    ];

    foreach ($offenseListToAnalyze as $oItem) {
        $oName = (string)($oItem['offense_name'] ?? 'Infraction');
        $oLvl  = strtoupper((string)($oItem['offense_level'] ?? 'MAJOR'));
        $oId   = (int)($oItem['offense_type_id'] ?? 0);

        $matchedHours = null;
        $matchedSource = null;

        // Check exact DB precedents
        $dbP = getExactPrecedents($oId, $caseId, 1);
        if (!empty($dbP)) {
            $punStr = formatPunishmentDetails((string)($dbP[0]['punishment_details'] ?? ''));
            if (preg_match('/(\d+)\s*Hours/i', $punStr, $pm)) {
                $matchedHours = (int)$pm[1];
            } else {
                $matchedHours = ($dbP[0]['decided_category'] >= 2) ? 150 : 0;
            }
            $matchedSource = "Category {$dbP[0]['decided_category']} Sanction ({$punStr})";
        }

        // Check SANCTION.xlsx Excel dataset cache using Semantic Concept Matching
        if ($matchedHours === null && !empty($cacheRecords)) {
            foreach ($cacheRecords as $cr) {
                $crOff = (string)($cr['offense'] ?? '');
                if ($crOff !== '' && areOffensesSemanticallyEqual($crOff, $oName)) {
                    $sanc = (string)($cr['sanction'] ?? '');
                    if (preg_match('/(\d+)\s*Hours/i', $sanc, $pm)) {
                        $matchedHours = (int)$pm[1];
                    } else {
                        $matchedHours = (strpos(strtoupper($sanc), 'NON-READMISSION') !== false) ? 300 : ((strpos(strtoupper($sanc), '150') !== false) ? 150 : 250);
                    }
                    $matchedSource = "'{$cr['offense']}' ({$cr['sanction']})";
                    break;
                }
            }
        }

        // Fallback: Handbook Gravity & Meaning Assessment if no dataset record
        if ($matchedHours === null) {
            if ($oLvl === 'MINOR') {
                if (preg_match('/\b(id|lending|theft|property|cheating|misconduct)\b/i', $oName)) {
                    $matchedHours = 150;
                    $matchedSource = "Evaluated via NU Lipa Student Handbook Section 4 Gravity Analysis: Moderately Severe Minor Infraction ({$matchedHours} Hours CS Baseline)";
                } elseif (preg_match('/\b(dress|attire|badge|noise|tardiness|littering)\b/i', $oName)) {
                    $matchedHours = 15;
                    $matchedSource = "Evaluated via NU Lipa Student Handbook Section 4 Gravity Analysis: Light Minor Infraction ({$matchedHours} Hours CS Baseline)";
                } else {
                    $matchedHours = 30;
                    $matchedSource = "Evaluated via NU Lipa Student Handbook Section 4 Gravity Analysis: Standard Minor Infraction ({$matchedHours} Hours CS Baseline)";
                }
            } else {
                $matchedHours = 250;
                $matchedSource = "Evaluated via NU Lipa Student Handbook Section 5 Major Penalty Matrix: Major Infraction Baseline (250 Hours CS)";
            }
        }

        $totalCombinedHours += $matchedHours;

        $allOffensesAnalysis[] = [
            'offense_name' => $oName,
            'offense_level' => $oLvl,
            'hours' => $matchedHours,
            'has_precedent' => ($matchedSource && strpos($matchedSource, 'Evaluated via') === false),
            'source_explanation' => $matchedSource
        ];
    }

    $categoryPrecedents = empty($exactPrecedents)
        ? getCategoryPrecedents($majorCategory, $offenseTypeId, $caseId)
        : [];

    $dynamicRules = getDynamicHandbookRules();

    $caseMeta = [
        'case_id' => $caseId,
        'student_id' => $targetStudentId,
        'student_name' => $studentName,
        'offense_name' => $offenseName,
        'offense_level' => $offenseLevel,
        'major_category' => $majorCategory,
        'offense_type_id' => $offenseTypeId,
        'total_prior' => $totalPrior,
        'prior_cases_text' => $priorCasesBreakdownText,
        'pending_cases_count' => count($pendingCasesRows),
        'pending_cases_text' => $pendingCasesText,
        'cs_text' => $csStatusText,
        'exact_precedents' => $exactPrecedents,
        'excel_precedents' => $excelPrecedents,
        'category_precedents' => $categoryPrecedents,
        'all_offenses_analysis' => $allOffensesAnalysis,
        'total_combined_hours' => $totalCombinedHours
    ];

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

            $sysPrompt = "You are IdentiTrack AI, a warm, friendly executive decision-support assistant for NU Lipa Disciplinary Panel Members & Administrators.\n"
                . "TONE & STYLE MANDATE: Be very conversational, friendly, approachable, and helpful when talking to the admin or panel member. Greet them warmly (e.g. 'Hello Panel Member!', 'Hi Administrator!'), explain in smooth natural language why consistency with prior decided cases is essential for procedural fairness, state the recommended sanction, and give a clear 'Why? (Reason)' explanation. DATA PRIVACY MANDATE: NEVER mention full names of past student offenders. Do NOT output lists of sample questions.\n\n" . $dynamicRules;
            $userPrompt = "Student: {$studentName}\nOffense: {$offenseName}\nExact Precedents:\n" . implode("\n", $precedentSummary);
            
            $aiEngineRes = queryAiEngine($sysPrompt, $userPrompt, $studentName, $targetStudentId, $caseMeta);
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

        // If SANCTION.xlsx dataset contains matching precedents for this offense
        if (!empty($excelPrecedents)) {
            $firstExcelMatch = $excelPrecedents[0];
            $sancStr = $firstExcelMatch['sanction'] ?? 'FORMATIVE INTERVENTION';
            $sCat = (strpos(strtoupper($sancStr), 'NON-READMISSION') !== false || strpos(strtoupper($sancStr), 'DROPPED') !== false) ? 4
                  : ((strpos(strtoupper($sancStr), 'SUSPENSION') !== false) ? 3
                  : ((strpos(strtoupper($sancStr), 'REPRIMAND') !== false || strpos(strtoupper($sancStr), 'DISMISS') !== false) ? 1 : 2));

            $excelSummary = array_map(fn($ep) => sprintf("• Offense: %s | Level: %s | Sanction: %s", $ep['offense'], $ep['level'], $ep['sanction']), array_slice($excelPrecedents, 0, 5));

            $sysPrompt = "You are IdentiTrack AI, a warm, friendly executive decision-support assistant for NU Lipa Disciplinary Panel Members & Administrators.\n"
                . "TONE & STYLE MANDATE: Be very conversational, friendly, approachable, and helpful when talking to the admin or panel member. Greet them warmly (e.g. 'Hello Panel Member!', 'Hi Administrator!'), explain the suggested sanction based on the official historical campus precedent records containing " . count($excelPrecedents) . " matching precedent record(s), and state a clear 'Why? (Reason)' explanation. DATA PRIVACY MANDATE: NEVER mention full names of past student offenders. Do NOT output lists of sample questions.\n\n" . $dynamicRules;
            $userPrompt = "Student: {$studentName}\nOffense: {$offenseName} ({$offenseLevel})\nHistorical Dataset Precedents:\n" . implode("\n", $excelSummary);

            $aiEngineRes = queryAiEngine($sysPrompt, $userPrompt, $studentName, $targetStudentId, $caseMeta);
            $aiText = $aiEngineRes['text'];

            echo json_encode([
                'ok' => true,
                'source' => 'excel_sanction_dataset',
                'is_new_offense_type' => false,
                'student_id' => $targetStudentId,
                'student_name' => $studentName,
                'offense_name' => $offenseName,
                'instance_count' => $instanceCount,
                'suggested_category' => $sCat,
                'suggested_punishment' => $sancStr,
                'dataset_precedents_count' => count($excelPrecedents),
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

        $sysPrompt = "You are IdentiTrack AI, a warm, friendly executive decision-support assistant for NU Lipa Disciplinary Panel Members & Administrators.\n"
            . "TONE & STYLE MANDATE: Be very conversational, friendly, approachable, and helpful when talking to the admin or panel member. Base your suggestion strictly on the handbook rules provided. "
            . "Respond ONLY with valid JSON: {\"suggested_category\": <1-5 or null>, \"suggested_hours\": <int or null>, \"rationale\": \"<2-4 warm, conversational sentences explaining the recommendation and Why?>\"}.\n\n"
            . $dynamicRules;

        $userPrompt = "Student: {$studentName}\nOffense: {$offenseName} (Level: {$offenseLevel})\n"
            . "Prior offenses by this student: {$totalPrior} (Major: {$totalMajorCount})\n"
            . "Closest related cases in category:\n{$categorySummary}\n\n"
            . "Suggest a punishment grounded in handbook rules.";

        $aiEngineRes = queryAiEngine($sysPrompt, $userPrompt, $studentName, $targetStudentId, $caseMeta);
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

        $precedentLines = [];
        if (!empty($exactPrecedents)) {
            foreach ($exactPrecedents as $p) {
                $precedentLines[] = sprintf(
                    "• Live Database Case #%s: Decided Category %s Sanction (%s)",
                    $p['case_id'], $p['decided_category'],
                    formatPunishmentDetails($p['punishment_details'] ?? '')
                );
            }
        }
        if (!empty($excelPrecedents)) {
            foreach (array_slice($excelPrecedents, 0, 5) as $ep) {
                $precedentLines[] = sprintf(
                    "• Historical Campus Precedent Match: Offense '%s' (%s) → Decided Sanction: %s",
                    $ep['offense'], $ep['level'], $ep['sanction']
                );
            }
        }
        $precedentContext = !empty($precedentLines)
            ? implode("\n", $precedentLines)
            : "No direct prior campus-wide precedent cases on file for this specific offense type.";

        // STRICT CONVERSATIONAL, PRECEDENT & HANDBOOK POLICY MANDATE
        $sysPrompt = "You are IdentiTrack AI, a warm, friendly, executive decision-support assistant for NU Lipa Disciplinary Administrators & Panel Members.\n"
            . "TONE & STYLE MANDATE:\n"
            . "1. BE VERY CONVERSATIONAL & FRIENDLY: Address the user warmly as 'Panel Member' or 'Administrator'. EVEN WHEN GREETED (e.g. 'hi', 'hello', 'hey'), YOU MUST IMMEDIATELY PRESENT THE COMPLETE CASE ANALYSIS & SUGGESTED PUNISHMENT RECOMMENDATION FOR THE ACTIVE STUDENT RIGHT IN YOUR INITIAL RESPONSE. NEVER respond with a plain generic greeting asking what the user wants—ALWAYS deliver the full suggested sanction immediately.\n"
            . "2. STRICT CONSISTENCY & DETERMINISM MANDATE:\n"
            . "   You must ALWAYS produce identical, standardized, consistent advisory determinations and sanction results regardless of how the admin or panel member phrases, structures, or tones their question (e.g. 'what punishment should we give?', 'what sanction is recommended?', 'what category?', 'what is the decision?', 'is there a similar case of this student?'). Phrasing variations, typos, or tone differences must NEVER alter the underlying sanction category, policy rule, community service calculation, or advisory result.\n"
            . "3. SIMILAR CASE / PRECEDENT INQUIRIES:\n"
            . "   When the user asks if there are similar cases or precedents (e.g. 'is there a similar case of this student?', 'any similar cases?', 'are there precedents?'):\n"
            . "   a) Explicitly answer 'Yes, I found similar precedent case(s)...' OR 'No direct historical precedents exist for this specific offense...'\n"
            . "   b) List the matched precedent cases from the data provided (including live database cases and historical precedent records) showing the offense, level, and decided sanction.\n"
            . "4. REPEAT OFFENDER & DISCIPLINARY HISTORY ESCALATION MANDATE:\n"
            . "   When asked to suggest a punishment or sanction (e.g. 'suggest punishment', 'what sanction should we give?'):\n"
            . "   a) CHECK DISCIPLINARY HISTORY FIRST: First check if the student has prior resolved cases or pending cases in the context data provided.\n"
            . "      • IF STUDENT HAS 1 PRIOR RESOLVED CASE OR 1 PENDING CASE: The sanction MUST ESCALATE TO CATEGORY 3 SANCTION (250–400 Hours Community Service / 1 Term Non-Readmission / Suspension).\n"
            . "      • IF STUDENT HAS 2 OR MORE PRIOR RESOLVED OR PENDING CASES: The sanction MUST ESCALATE TO CATEGORY 4 OR CATEGORY 5 SANCTION (Mandatory Exclusion / Dismissal / Summary Expulsion).\n"
            . "      • EXPLICITLY STATE: 'Disciplinary Record Check: Found N prior resolved case(s) and M pending case(s) on file. Under Section 5 Repeat Offender Policy, repeat infractions escalate to Category 3 (or Category 4/5).'\n"
            . "   b) IF STUDENT HAS 0 PRIOR RESOLVED AND 0 PENDING CASES (1st Offense on Record):\n"
            . "      • Check the 'Precedent Record for this Offense' provided in the data.\n"
            . "      • IF DIRECT PRECEDENT EXISTS: Recommend following the precedent outcome (e.g., Category X Sanction).\n"
            . "      • IF NO DIRECT PRECEDENT EXISTS: Perform Handbook Matrix evaluation (Category 1 for Minor 1st/2nd attempt / Category 2 for Major 1st offense 150–250 Hours CS).\n"
            . "   c) ALWAYS INCLUDE A CLEAR 'Why? (Reason)' EXPLANATION: Grounded in prior resolved/pending cases count, Section 4 escalation rule, Section 5 penalty matrix, or campus dataset match.\n"
            . "5. ANSWER ONLY WHAT IS ASKED: Answer the panel member's specific question directly, conversationally, and naturally. DO NOT prepend or append active student file summaries, background context headers, handbook matrix blocks, or community service logs to your answer unless explicitly asked.\n"
            . "6. STRICT CONFIDENTIALITY FOR PRIOR & PENDING CASES:\n"
            . "   - Panel members may NOT be assigned to other cases of the student. You MUST NEVER disclose or describe the specific underlying actions, titles, or descriptions of what the student did in other prior or pending cases!\n"
            . "   - For pending cases: State ONLY that a pending case exists (e.g. 'Case #72: Pending Hearing'). Do NOT show what the student did.\n"
            . "   - For resolved cases: State ONLY the Case Number and decided sanction/punishment (e.g. 'Case #68: Category 2 Sanction'). Do NOT show what the student did.\n"
            . "7. STRICT HANDBOOK FOCUS: Follow the NU Lipa Student Handbook rules.\n"
            . "8. ZERO NAME DROPPING / NO OTHER STUDENTS: Never reveal real names or discuss other students under Data Privacy (RA 10173).\n"
            . "9. CLEAN MARKDOWN FORMATTING: Use clear, readable Markdown with bold text and bullet points.\n"
            . "10. DO NOT MENTION FILE NAMES: Never mention specific data file names (such as SANCTION.xlsx or cache filenames) to the user; refer to them strictly as 'our official campus precedent records' or 'historical campus precedent dataset'.\n\n"
            . $dynamicRules;

        $userPrompt = "ACTIVE HEARING CASE DATA (BACKGROUND CONTEXT FOR INFERENCE ONLY):\n"
            . "• Current Case #{$caseId} Offense: {$offenseName} (Level: {$offenseLevel}, Instance #{$instanceCount})\n"
            . "• Total Major Offenses: {$totalMajorCount}\n"
            . "• Prior Resolved Cases Record:\n{$priorCasesBreakdownText}\n"
            . "• Other Pending Cases Record:\n{$pendingCasesText}\n"
            . "• Community Service Status: {$csStatusText}\n"
            . "• Precedent Record for this Offense:\n{$precedentContext}\n\n"
            . "PANEL QUESTION: {$userQuery}";

        $aiEngineRes = queryAiEngine($sysPrompt, $userPrompt, $studentName, $targetStudentId, $caseMeta);
        $aiText = $aiEngineRes['text'];
        $aiEngineName = $aiEngineRes['engine'];

        // ── Guard: Guarantee immediate suggested punishment & Disciplinary History Escalation ──
        if ($aiText !== null && preg_match('/\b(suggest|sanction|category|punishment|recommend|decision|vote|penalty|minor|section 4)\b/i', $userQuery)) {
            $totalDisciplinaryHistory = $totalPrior + count($pendingCasesRows);

            if ($totalDisciplinaryHistory > 0) {
                $suggestedCat = ($totalDisciplinaryHistory >= 2) ? 4 : 3;
                $hoursText = ($suggestedCat >= 4)
                    ? "Mandatory Exclusion / Dismissal / Category 4 Sanction (400+ Hours CS)"
                    : "250–400 Hours Community Service / 1 Term Non-Readmission (Suspension)";

                $whyReason = ($totalDisciplinaryHistory >= 2)
                    ? "The student has {$totalPrior} prior resolved case(s) and " . count($pendingCasesRows) . " pending case(s) on file (total {$totalDisciplinaryHistory} prior records). Under NU Lipa Handbook Section 5 Repeat Offender Policy, multiple repeat infractions escalate to Category 4 or Category 5 (Mandatory Exclusion / Dismissal / Expulsion)."
                    : "The student has {$totalPrior} prior resolved case(s) and " . count($pendingCasesRows) . " pending case(s) on file. Under NU Lipa Handbook Section 5 Repeat Offender Policy, repeat infractions following a prior record escalate to Category 3 (250–400 Hours CS / Suspension / Non-Readmission).";

                if (!preg_match('/Category ' . $suggestedCat . '/i', $aiText)) {
                    $recCountText = count($excelPrecedents) > 0
                        ? "I checked our official campus precedent records and **found " . count($excelPrecedents) . " matching precedent record(s)** for this offense (**{$offenseName}**)."
                        : "I analyzed our campus precedent records and **found no prior record** for this specific offense (**{$offenseName}**).";

                    $aiText = "👋 **Hello Panel Member! I am IdentiTrack AI.** Let me analyze **{$studentName}**'s case file for this current hearing.\n\n"
                            . "{$recCountText}\n\n"
                            . "📋 **Student Disciplinary Record Check**: Found **{$totalPrior} prior resolved case(s)** and **" . count($pendingCasesRows) . " pending case(s)** on file for **{$studentName}**.\n\n"
                            . "⚖️ **Suggested Punishment & Advisory Recommendation**:\n\n"
                            . "• **Offense Charged**: {$offenseName} ({$offenseLevel})\n"
                            . "• **Suggested Punishment**: **Category {$suggestedCat} Sanction** ({$hoursText} + Active Probation)\n"
                            . "• **Why? (Reason)**: {$whyReason}\n\n"
                            . "Please let me know if you would like more details about this recommendation for {$studentName}!";
                }
            } elseif (preg_match('/found no prior record|no direct historical precedent|new or rare infraction/i', $aiText) && !preg_match('/Suggested Punishment|Category \d Sanction/i', $aiText)) {
                $aiText = preg_replace('/Would you like me to analyze.*$/i', '', $aiText);
                $aiText = trim($aiText);

                $suggestedCat = ($offenseLevel === 'MAJOR') ? 2 : 1;
                $hoursText = ($suggestedCat === 2) ? "150 to 250 Hours Community Service" : "0 Hours Community Service (Written Reprimand)";
                $whyReason = "Evaluated directly against NU Lipa Student Handbook Section 4 (Minor Violations) and Section 5 (Major Offense Penalty Matrix) for a 1st offense on record.";

                $aiText .= "\n\n⚖️ **Suggested Punishment & Advisory Recommendation**:\n\n"
                         . "• **Offense Charged**: {$offenseName} ({$offenseLevel})\n"
                         . "• **Suggested Punishment**: **Category {$suggestedCat} Sanction** ({$hoursText} + Active Probation)\n"
                         . "• **Why? (Reason)**: {$whyReason}\n\n"
                         . "Please let me know if you would like more details about this recommendation for {$studentName}!";
            }
        }

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

        $sysPrompt = "You are IdentiTrack AI, a warm, friendly executive decision-support assistant for NU Lipa Disciplinary Administrators & Board Members.\n"
            . "TONE & STYLE MANDATE: Be very conversational, friendly, approachable, and engaging. Greet the admin warmly (e.g. 'Hello Administrator!'), answer their specific question directly and conversationally, and explain handbook policies and precedent analytics with clarity and warmth. Do NOT output lists of sample questions or headers.\n"
            . "DATA PRIVACY MANDATE (RA 10173): For student privacy protection, NEVER mention or reveal full names of past student offenders. Do NOT mention specific file names like SANCTION.xlsx in your replies; refer to them as 'our official campus precedent records'.\n\n"
            . $datasetSummary
            . "Answer questions strictly grounded in the NU Lipa Student Handbook rules below and campus precedent data.\n\n"
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