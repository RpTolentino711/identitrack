<?php
$f = 'UPCC/upccdashboard.php';
$c = file_get_contents($f);

$target = "onclick=\"handleRowClick('<?php echo htmlspecialchars(\$href); ?>', <?php echo \$accepted ? 'true' : 'false'; ?>, <?php echo (int)\$c['case_id']; ?>, <?php echo (int)(\$c['hearing_is_open'] ?? 0); ?>, <?php echo (int)(\$c['hearing_is_paused'] ?? 0); ?>, '<?php echo htmlspecialchars(\$myPresenceStatus); ?>')\"";

$replace = "onclick=\"handleRowClick('<?php echo htmlspecialchars(\$href); ?>', <?php echo \$accepted ? 'true' : 'false'; ?>, <?php echo (int)\$c['case_id']; ?>, <?php echo (int)(\$c['hearing_is_open'] ?? 0); ?>, <?php echo (int)(\$c['hearing_is_paused'] ?? 0); ?>, '<?php echo htmlspecialchars(\$myPresenceStatus); ?>', <?php echo \$adminOff; ?>)\"";

$c = str_replace($target, $replace, $c);

// Also I noticed my previous script didn't properly replace the body of the function because of emoji discrepancies. Let's fix that.
$targetJs = "function handleRowClick(href, accepted, caseId, hearingIsOpen, hearingIsPaused, myPresenceStatus, adminOffline) {
  // Check if case is actually open
  if (hearingIsOpen === 0) {
    alert('⚠️ This case is currently locked by the administrator. You cannot access it until it is opened.');
    return;
  }
  
  if (hearingIsPaused === 1) {
    alert('⚠️ This case hearing has been paused. You cannot access it until the administrator resumes it.');
    return;
  }

  if (String(myPresenceStatus || 'ADMITTED').toUpperCase() !== 'ADMITTED') {";

$replaceJs = "function handleRowClick(href, accepted, caseId, hearingIsOpen, hearingIsPaused, myPresenceStatus, adminOffline) {
  // Check if case is actually open
  if (hearingIsOpen === 0) {
    alert('⚠️ This case is currently locked by the administrator. You cannot access it until it is opened.');
    return;
  }
  
  if (hearingIsPaused === 1 || adminOffline === 1) {
    alert('⚠️ This case hearing is currently paused or the administrator is offline. You cannot access it until the administrator resumes it.');
    return;
  }

  if (String(myPresenceStatus || 'ADMITTED').toUpperCase() !== 'ADMITTED') {";

$c = str_replace($targetJs, $replaceJs, $c);

file_put_contents($f, $c);
echo "Done";
