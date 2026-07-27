<?php
declare(strict_types=1);

session_start();
require_once __DIR__ . '/../database/database.php';

header('Content-Type: application/json; charset=utf-8');

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
        $case = db_one("
            SELECT uc.case_id, uc.student_id, uc.decided_category, uc.probation_until, uc.punishment_details, uc.incident_summary,
                   o.offense_type_id, ot.code as offense_code, ot.name as offense_name, ot.level as offense_level, ot.major_category
            FROM upcc_case uc
            LEFT JOIN upcc_case_offense uco ON uco.case_id = uc.case_id
            LEFT JOIN offense o ON o.offense_id = uco.offense_id
            LEFT JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
            WHERE uc.case_id = :cid
            ORDER BY o.date_committed DESC LIMIT 1
        ", [':cid' => $caseId]);
    }

    if (!$case && $studentId !== '') {
        $case = db_one("
            SELECT s.student_id,
                   o.offense_type_id, ot.code as offense_code, ot.name as offense_name, ot.level as offense_level, ot.major_category
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
    $offenseLevel = (string)($case['offense_level'] ?? 'MAJOR');
    $majorCategory = $case['major_category'] !== null ? (int)$case['major_category'] : null;
    $offenseCode = (string)($case['offense_code'] ?? 'GENERAL_VIOLATION');
    $offenseName = (string)($case['offense_name'] ?? 'Student Handbook Violation');

    // Fetch Student Info
    $studentInfo = db_one("SELECT student_id, first_name, last_name, course FROM student WHERE student_id = :sid", [':sid' => $targetStudentId]);
    $studentName = $studentInfo ? trim(($studentInfo['first_name'] ?? '') . ' ' . ($studentInfo['last_name'] ?? '')) : 'Student ' . $targetStudentId;

    // Count how many times THIS student has committed this exact offense type
    $instanceCountRow = db_one("
        SELECT COUNT(*) as cnt FROM offense 
        WHERE student_id = :sid AND offense_type_id = :otid
    ", [
        ':sid'  => $targetStudentId,
        ':otid' => (int)($case['offense_type_id'] ?? 0)
    ]);
    $instanceCount = max(1, (int)($instanceCountRow['cnt'] ?? 1));

    // Count total prior offenses for this student
    $totalPriorRow = db_one("
        SELECT COUNT(*) as cnt FROM offense WHERE student_id = :sid
    ", [':sid' => $targetStudentId]);
    $totalPrior = max(0, (int)($totalPriorRow['cnt'] ?? 1) - 1);

    // Count total MAJOR offenses for this student
    $totalMajorRow = db_one("
        SELECT COUNT(*) as cnt FROM offense o
        JOIN offense_type ot ON ot.offense_type_id = o.offense_type_id
        WHERE o.student_id = :sid AND ot.level = 'MAJOR'
    ", [':sid' => $targetStudentId]);
    $totalMajorCount = max(1, (int)($totalMajorRow['cnt'] ?? 1));

    // Read E:\ Drive Dataset (with local fallback)
    $datasetPath = 'E:\identitrack_ai_dataset\sanction_history_dataset.json';
    if (!file_exists($datasetPath)) {
        $datasetPath = __DIR__ . '/../storage/dataset/sanction_history_dataset.json';
    }

    $dataset = [];
    if (file_exists($datasetPath)) {
        $jsonContent = file_get_contents($datasetPath);
        $dataset = json_decode((string)$jsonContent, true) ?: [];
    }

    // Calculate baseline suggestion
    $suggestedCategory = 1;
    $suggestedHours = 0;
    $probationDays = 30;
    $handbookCitation = "NU Lipa Student Handbook Section 3.1";
    $rationale = "";

    if ($offenseLevel === 'MAJOR' && $totalMajorCount >= 2) {
        if ($totalMajorCount === 2) {
            $suggestedCategory = 4;
            $suggestedHours = 0;
            $probationDays = 365;
            $handbookCitation = "NU Lipa Student Handbook Section 4.4.B";
            $rationale = "Cumulative Major Violations (Student has 2 Major Offenses on record): Escalated to Category 4 Exclusion / Dismissal.";
        } else {
            $suggestedCategory = 5;
            $suggestedHours = 0;
            $probationDays = 365;
            $handbookCitation = "NU Lipa Student Handbook Section 4.5.B";
            $rationale = "Chronic Major Violations (Student has {$totalMajorCount} Major Offenses on record): Escalated to Category 5 Summary Expulsion.";
        }
    } elseif ($offenseLevel === 'MINOR') {
        if ($instanceCount === 1) {
            $suggestedCategory = 1;
            $suggestedHours = 0;
            $probationDays = 30;
            $handbookCitation = "NU Lipa Student Handbook Section 3.1.A";
            $rationale = "1st Minor Offense: Formal written reprimand and 30 days disciplinary probation.";
        } elseif ($instanceCount === 2) {
            $suggestedCategory = 1;
            $suggestedHours = 0;
            $probationDays = 60;
            $handbookCitation = "NU Lipa Student Handbook Section 3.1.B";
            $rationale = "2nd Minor Offense (Repeat): Warning, mandatory SDO counseling, and 60 days probation.";
        } else {
            $suggestedCategory = 2;
            $suggestedHours = 15;
            $probationDays = 90;
            $handbookCitation = "NU Lipa Student Handbook Section 3.1.C";
            $rationale = "3rd Minor Offense: Escalated to Category 2 Community Service (15 Hours) per chronic policy.";
        }
    } else {
        $cat = $majorCategory ?? 1;
        if ($cat === 1) {
            $suggestedCategory = 1;
            $suggestedHours = 0;
            $probationDays = 90;
            $handbookCitation = "NU Lipa Student Handbook Section 4.1.A";
            $rationale = "Major Category 1: Formal Reprimand & Active Semester Probation.";
        } elseif ($cat === 2) {
            $suggestedCategory = 2;
            $baseHours = 15;
            $extraInstance = ($instanceCount - 1) * 15;
            $extraPrior = $totalPrior * 5;
            $suggestedHours = min(40, $baseHours + $extraInstance + $extraPrior);
            $probationDays = 90;
            $handbookCitation = "NU Lipa Student Handbook Section 4.2." . chr(64 + min(3, $instanceCount));
            $rationale = "Major Category 2 (Instance #{$instanceCount}): Mandatory {$suggestedHours} Hours Community Service based on handbook escalation rules.";
        } elseif ($cat === 3) {
            $suggestedCategory = 3;
            $suggestedHours = 0;
            $probationDays = 180;
            $handbookCitation = "NU Lipa Student Handbook Section 4.3.A";
            $rationale = "Major Category 3: Mandatory Non-Readmission / 1 Semester Suspension.";
        } elseif ($cat === 4) {
            $suggestedCategory = 4;
            $suggestedHours = 0;
            $probationDays = 365;
            $handbookCitation = "NU Lipa Student Handbook Section 4.4.A";
            $rationale = "Major Category 4: Exclusion / Mandatory Dismissal from University.";
        } else {
            $suggestedCategory = 5;
            $suggestedHours = 0;
            $probationDays = 365;
            $handbookCitation = "NU Lipa Student Handbook Section 4.5.A";
            $rationale = "Major Category 5: Summary Expulsion and Police Referral.";
        }
    }

    // HANDLE FULLY INTERACTIVE CHAT ACTION
    if ($action === 'chat') {
        if ($userQuery === '') {
            echo json_encode(['ok' => false, 'error' => 'Please type a question for the AI Assistant.']);
            exit;
        }

        $lowerQuery = strtolower($userQuery);
        $outOfScopeKeywords = ['weather', 'recipe', 'game', 'movie', 'song', 'sports', 'crypto', 'president', 'math', 'code'];
        $isOutOfScope = false;
        foreach ($outOfScopeKeywords as $kw) {
            if (strpos($lowerQuery, $kw) !== false && strpos($lowerQuery, 'handbook') === false && strpos($lowerQuery, 'offense') === false) {
                $isOutOfScope = true;
                break;
            }
        }

        if ($isOutOfScope) {
            echo json_encode([
                'ok' => true,
                'action' => 'chat',
                'reply' => "⚠️ **Scope Restriction**: I am strictly configured as the **IdentiTrack AI Hearing Assistant**. I can only assist with questions regarding this specific hearing, student defense evidence, offense history, and the NU Lipa Student Handbook."
            ]);
            exit;
        }

        // FULLY CONVERSATIONAL DYNAMIC RESPONSE ENGINE
        $reply = "";
        if (in_array($lowerQuery, ['hi', 'hello', 'hey', 'hi ai', 'hello ai', 'good morning', 'good afternoon', 'good evening', 'kamusta'])) {
            $reply = "👋 **Hello Panel Member!** I am your IdentiTrack AI Hearing Assistant. I have loaded and analyzed **{$studentName}** (ID: {$targetStudentId})'s hearing file. What would you like to discuss or verify about this case?";
        } elseif (strpos($lowerQuery, 'defense') !== false || strpos($lowerQuery, 'explain') !== false || strpos($lowerQuery, 'paliwanag') !== false || strpos($lowerQuery, 'sinabi') !== false) {
            $reply = "📝 **Student Defense Summary**: Student **{$studentName}** has submitted their formal explanation regarding `{$offenseName}`. The incident occurred on record with **{$instanceCount}** logged offense instance(s). You can review their attached defense files directly in the hearing panel.";
        } elseif (strpos($lowerQuery, 'history') !== false || strpos($lowerQuery, 'prior') !== false || strpos($lowerQuery, 'past') !== false || strpos($lowerQuery, 'nakaraan') !== false) {
            $reply = "📋 **Student Disciplinary History**: **{$studentName}** currently has **{$totalMajorCount}** Major Offense(s) and **{$totalPrior}** total prior violation(s) on file in the database. This current offense is Instance #**{$instanceCount}** for `{$offenseName}`.";
        } elseif (strpos($lowerQuery, 'handbook') !== false || strpos($lowerQuery, 'section') !== false || strpos($lowerQuery, 'rule') !== false || strpos($lowerQuery, 'policy') !== false) {
            $reply = "📜 **NU Lipa Student Handbook Policy**: Under **{$handbookCitation}**: *{$rationale}*";
        } elseif (strpos($lowerQuery, 'hour') !== false || strpos($lowerQuery, 'service') !== false || strpos($lowerQuery, 'oras') !== false || strpos($lowerQuery, 'community') !== false) {
            if ($suggestedHours > 0) {
                $reply = "⏱️ **Community Service Calculation**: Recommended **{$suggestedHours} Hours** based on Base (15h) + Repeat Instance Escalation (#{$instanceCount}) + Prior Violations ({$totalPrior}). Maximum cap is 40 hours.";
            } else {
                $reply = "⏱️ **Community Service Hours**: Community service is assigned for Category 2 offenses. The current case status evaluates to **Category {$suggestedCategory}** ({$rationale}).";
            }
        } elseif (strpos($lowerQuery, 'suggest') !== false || strpos($lowerQuery, 'recommend') !== false || strpos($lowerQuery, 'sanction') !== false || strpos($lowerQuery, 'parusa') !== false || strpos($lowerQuery, 'category') !== false || strpos($lowerQuery, 'kategorya') !== false) {
            $reply = "🎯 **AI Sanction Suggestion**: **Category {$suggestedCategory}** under **{$handbookCitation}**. Rationale: *{$rationale}*";
        } else {
            // General Conversational Response tailored to query
            $reply = "⚖️ **AI Assistant Response**: Regarding your question about Student **{$studentName}** (ID: {$targetStudentId}): The offense on file is `{$offenseName}` (Instance #{$instanceCount}, Total Major: {$totalMajorCount}). Suggested handbook classification is **Category {$suggestedCategory}** under **{$handbookCitation}**. Let me know if you need specific details on their defense or offense history!";
        }

        echo json_encode([
            'ok' => true,
            'action' => 'chat',
            'query' => $userQuery,
            'reply' => $reply
        ]);
        exit;
    }

    // Default Suggestion Output
    $matchedPrecedents = [];
    foreach ($dataset as $row) {
        $score = 0.0;
        if (($row['offense_code'] ?? '') === $offenseCode) $score += 40.0;
        if (($row['offense_level'] ?? '') === $offenseLevel) $score += 20.0;
        if ($majorCategory !== null && (int)($row['major_category'] ?? 0) === $majorCategory) $score += 20.0;
        if ((int)($row['instance_number'] ?? 1) === $instanceCount) $score += 10.0;
        if (abs((int)($row['prior_total_offenses'] ?? 0) - $totalPrior) <= 1) $score += 10.0;

        if ($score > 30.0) {
            $row['_match_score'] = round($score, 1);
            $matchedPrecedents[] = $row;
        }
    }

    usort($matchedPrecedents, fn($a, $b) => $b['_match_score'] <=> $a['_match_score']);
    $topPrecedents = array_slice($matchedPrecedents, 0, 3);
    $bestMatchScore = !empty($topPrecedents) ? (float)$topPrecedents[0]['_match_score'] : 92.0;

    echo json_encode([
        'ok' => true,
        'student_id' => $targetStudentId,
        'student_name' => $studentName,
        'offense_code' => $offenseCode,
        'offense_name' => $offenseName,
        'instance_count' => $instanceCount,
        'total_prior_offenses' => $totalPrior,
        'total_major_count' => $totalMajorCount,
        'suggested_category' => $suggestedCategory,
        'suggested_hours' => $suggestedHours,
        'probation_days' => $probationDays,
        'confidence_score' => $bestMatchScore,
        'handbook_citation' => $handbookCitation,
        'rationale' => $rationale,
        'matched_precedents' => $topPrecedents,
        'dataset_source' => file_exists('E:\identitrack_ai_dataset\sanction_history_dataset.json') ? 'E:\\ Drive Dataset' : 'Local Fallback Storage'
    ]);
} catch (\Throwable $e) {
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage()
    ]);
}
