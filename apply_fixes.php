<?php
$file = 'admin/upcc_case_view.php';
$content = file_get_contents($file);

// 1. Fix the case_id interpolation in confirmLeavePage
$target1 = "  fd.append('pause_reason', 'AUTO_PAUSE_ADMIN_LEFT');\n    \n    fetch('../api/upcc_case_live.php', { method: 'POST', body: fd })";
$replace1 = "  fd.append('pause_reason', 'AUTO_PAUSE_ADMIN_LEFT');\n    \n    fetch(`../api/upcc_case_live.php?case_id=\${CASE_ID}&actor=admin`, { method: 'POST', body: fd })";
$content = str_replace($target1, $replace1, $content);

// 2. Fix the initial modal popup for rejoin requests
$target2 = "                  const sig = data.latest_rejoin_request_at || '';
                  if (!rejoinSigInit) { lastRejoinSig = sig; rejoinSigInit = true; }
                  else if (sig && sig !== lastRejoinSig) {
                    lastRejoinSig = sig;
                    if (data.waiting_users.length > 0) {
                      showToast('Rejoin Request', data.waiting_users.map(u => u.name).join(', ') + ' requesting to rejoin.', 'warning');
                      try { showRejoinModal(data.waiting_users); } catch(e) { /* ignore */ }
                    }
                  }";
$replace2 = "                  const sig = data.latest_rejoin_request_at || '';
                  if (!rejoinSigInit) {
                      lastRejoinSig = sig;
                      rejoinSigInit = true;
                      if (data.waiting_users && data.waiting_users.length > 0) {
                          try { showRejoinModal(data.waiting_users); } catch(e) {}
                      }
                  }
                  else if (sig && sig !== lastRejoinSig) {
                    lastRejoinSig = sig;
                    if (data.waiting_users && data.waiting_users.length > 0) {
                      showToast('Rejoin Request', data.waiting_users.map(u => u.name).join(', ') + ' requesting to rejoin.', 'warning');
                      try { showRejoinModal(data.waiting_users); } catch(e) { /* ignore */ }
                    }
                  }";
$content = str_replace($target2, $replace2, $content);

file_put_contents($file, $content);
