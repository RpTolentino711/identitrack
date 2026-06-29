<?php
require_once "database/database.php";
print_r(db_all("SELECT case_id, hearing_is_open, hearing_is_paused FROM upcc_case;"));
