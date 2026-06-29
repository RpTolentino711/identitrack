<?php
$f = 'UPCC/upccdashboard.php';
$c = file_get_contents($f);

// Fix the onclick call
$c = preg_replace(
    '/(onclick="handleRowClick\(\'<\?php echo htmlspecialchars\(\$href\); \?>\', <\?php echo \$accepted \? \'true\' : \'false\'; \?>, <\?php echo \(int\)\$c\[\'case_id\'\]; \?>, <\?php echo \(int\)\(\$c\[\'hearing_is_open\'\] \?\? 0\); \?>, <\?php echo \(int\)\(\$c\[\'hearing_is_paused\'\] \?\? 0\); \?>, \'<\?php echo htmlspecialchars\(\$myPresenceStatus\); \?>\')(")/',
    '$1, <?php echo $adminOff; ?>$2',
    $c
);

// Fix the javascript function signature
$c = str_replace(
    "function handleRowClick(href, accepted, caseId, hearingIsOpen, hearingIsPaused, myPresenceStatus) {",
    "function handleRowClick(href, accepted, caseId, hearingIsOpen, hearingIsPaused, myPresenceStatus, adminOffline) {",
    $c
);

// Fix the logic
$c = str_replace(
    "if (hearingIsPaused === 1) {\n      alert('🔒 This case hearing has been paused. You cannot access it until the administrator resumes it.');",
    "if (hearingIsPaused === 1 || adminOffline === 1) {\n      alert('🔒 This case hearing is currently paused or the administrator is offline. You cannot access it until the administrator resumes it.');",
    $c
);

file_put_contents($f, $c);
echo "Replaced.";
