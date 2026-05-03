<?php
require 'database/database.php';
$r1 = db_exec("UPDATE community_service_session SET login_method = 'NFC' WHERE login_method = '' OR login_method IS NULL");
$r2 = db_exec("UPDATE community_service_session SET logout_method = 'NFC' WHERE (logout_method = '' OR logout_method IS NULL) AND time_out IS NOT NULL");
echo "Updated $r1 login methods and $r2 logout methods.\n";
