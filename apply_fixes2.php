<?php
$f = 'admin/upcc_case_view.php';
$c = file_get_contents($f);

$c = str_replace(
    "fetch('../api/upcc_case_live.php', { method: 'POST', body: fd })\n    .then(() => {\n       skipUnloadWarn = true;\n       window.location.href = href;\n    })",
    "fetch(`../api/upcc_case_live.php?case_id=\${CASE_ID}&actor=admin`, { method: 'POST', body: fd })\n    .then(() => {\n       skipUnloadWarn = true;\n       window.location.href = href;\n    })",
    $c
);

$search = "if (!rejoinSigInit) { lastRejoinSig = sig; rejoinSigInit = true; }";
$replace = "if (!rejoinSigInit) {\n                    lastRejoinSig = sig; rejoinSigInit = true;\n                    if (data.waiting_users && data.waiting_users.length > 0) {\n                      try { showRejoinModal(data.waiting_users); } catch(e) {}\n                    }\n                  }";
$c = str_replace($search, $replace, $c);

file_put_contents($f, $c);
echo "Replaced.";
