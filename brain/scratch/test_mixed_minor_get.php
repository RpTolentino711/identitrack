<?php
session_start();
$_SESSION['admin_user'] = ['admin_id' => 1, 'username' => 'admin'];

// Mock $_GET parameters as if arriving from POST redirect for 4 mixed minor Section 4 escalation
$_GET['level'] = 'MINOR';
$_GET['student_id'] = '2024-01002';
$_GET['letter'] = '1';
$_GET['offense_id'] = '57'; // 4th mixed minor offense
$_GET['type'] = 'escalation';
$_GET['success'] = '1';

require_once 'c:/xampp/htdocs/identitrack/database/database.php';

// Include variable definitions from offense_new.php GET block
$studentIdPrefill = trim((string)($_GET['student_id'] ?? ''));
$letterOffenseId = (int)($_GET['offense_id'] ?? 0);
$letterType      = (string)($_GET['type'] ?? '');
$successMode     = ((int)($_GET['success'] ?? 0) === 1);
$letterParam     = ((int)($_GET['letter'] ?? 0) === 1);

$hasActiveWorkflowRequest = $letterParam && (int)($_GET['offense_id'] ?? 0) > 0;
$letterMode = false;
$ntePendingMode = false;
$evidencePendingMode = false;
$isSection4EscalationOffense = false;

$targetOffenseId = $hasActiveWorkflowRequest ? (int)($_GET['offense_id'] ?? $letterOffenseId) : 0;

if ($targetOffenseId > 0) {
    $offCheck = db_one(
        "SELECT o.offense_id, o.level, o.student_id, o.guardian_notified_at, 
                (SELECT COUNT(*) FROM upcc_case_offense uco JOIN upcc_case uc ON uc.case_id = uco.case_id WHERE uco.offense_id = o.offense_id AND uc.case_kind = 'SECTION4_MINOR_ESCALATION') AS is_section4_case 
         FROM offense o WHERE o.offense_id = :oid", 
        [':oid' => $targetOffenseId]
    );
    if ($offCheck) {
        $mCountRow = db_one("SELECT COUNT(*) as cnt FROM offense WHERE student_id = :sid AND level = 'MINOR' AND offense_id <= :oid", [':sid' => $offCheck['student_id'], ':oid' => $targetOffenseId]);
        $mCount = (int)($mCountRow['cnt'] ?? 0);

        $isSection4Linked = ((int)($offCheck['is_section4_case'] ?? 0) > 0);
        $urlTypeIsEsc = ((string)($_GET['type'] ?? '') === 'escalation');

        $isEsc = (strtoupper((string)$offCheck['level']) === 'MAJOR') || $isSection4Linked || $urlTypeIsEsc || ($mCount % 3 === 0 && $mCount >= 3);
        $isTriggerOffense = (strtoupper((string)$offCheck['level']) === 'MAJOR') || $isSection4Linked || $urlTypeIsEsc || ($mCount % 3 === 2) || ($mCount % 3 === 0 && $mCount >= 3);
        
        if ($isEsc) {
            $isSection4EscalationOffense = true;
        }

        if ((empty($offCheck['guardian_notified_at']) || $offCheck['guardian_notified_at'] === '0000-00-00 00:00:00') && $isTriggerOffense) {
            $letterMode = true;
            $letterOffenseId = $targetOffenseId;
            $letterType = (strtoupper((string)$offCheck['level']) === 'MAJOR') ? 'major' : (($isSection4Linked || $urlTypeIsEsc || ($mCount % 3 === 0 && $mCount >= 3)) ? 'escalation' : 'letter');
        } 
    }
}

echo "=== GET TEST FOR 4 MIXED MINOR ESCALATION ===\n";
echo "letterMode: " . ($letterMode ? 'TRUE (Modal 1 Pops Up Instantly!)' : 'FALSE') . "\n";
echo "letterType: " . $letterType . "\n";
echo "letterOffenseId: " . $letterOffenseId . "\n";
echo "isSection4EscalationOffense: " . ($isSection4EscalationOffense ? 'TRUE' : 'FALSE') . "\n";
