<?php
$diff = shell_exec('git diff 4724461^ 4724461 -- UPCC/case_view.php');
echo $diff;
