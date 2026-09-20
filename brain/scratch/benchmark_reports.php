<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');

require_once 'c:/xampp/htdocs/identitrack/database/database.php';
require_once 'c:/xampp/htdocs/identitrack/admin/data/historical_dataset_cache.php';

$t0 = microtime(true);

$monthOptions = [];

$distinctOffenseMonths = db_all(
  "SELECT DISTINCT DATE_FORMAT(date_committed, '%Y-%m') AS ym
   FROM offense
   WHERE date_committed IS NOT NULL
   ORDER BY ym DESC"
);

$distinctCaseMonths = db_all(
  "SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m') AS ym
   FROM upcc_case
   WHERE created_at IS NOT NULL
   ORDER BY ym DESC"
);

if (!empty($distinctOffenseMonths)) {
  foreach ($distinctOffenseMonths as $dm) {
    if (!empty($dm['ym']) && !in_array($dm['ym'], $monthOptions, true)) {
      $monthOptions[] = $dm['ym'];
    }
  }
}

if (!empty($distinctCaseMonths)) {
  foreach ($distinctCaseMonths as $dm) {
    if (!empty($dm['ym']) && !in_array($dm['ym'], $monthOptions, true)) {
      $monthOptions[] = $dm['ym'];
    }
  }
}

$t1 = microtime(true);
echo "DB months fetch time: " . round(($t1 - $t0) * 1000, 2) . " ms\n";

$t2 = microtime(true);
$maxYM = date('Y-m');
if (function_exists('get_historical_dataset_records')) {
  foreach (get_historical_dataset_records() as $hr) {
    if (!empty($hr['date'])) {
      $ym = date('Y-m', strtotime($hr['date']));
      if ($ym <= $maxYM && !in_array($ym, $monthOptions, true)) {
        $monthOptions[] = $ym;
      }
    }
  }
}
$t3 = microtime(true);
echo "Historical dataset loop time: " . round(($t3 - $t2) * 1000, 2) . " ms\n";
