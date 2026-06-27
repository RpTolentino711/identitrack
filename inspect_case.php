<?php
require_once __DIR__ . '/database/database.php';

try {
    $columns = db_all("SHOW COLUMNS FROM upcc_case");
    echo "<h3>Columns in upcc_case:</h3><ul>";
    foreach ($columns as $c) {
        echo "<li><b>{$c['Field']}</b>: {$c['Type']} (Null: {$c['Null']}, Key: {$c['Key']}, Default: {$c['Default']})</li>";
    }
    echo "</ul>";

    echo "<h3>Latest upcc_case rows:</h3><pre>";
    $rows = db_all("SELECT case_id, student_id, assigned_department_id, assigned_panel_members, status, hearing_date, hearing_time, hearing_type, hearing_link_or_location FROM upcc_case ORDER BY case_id DESC LIMIT 5");
    print_r($rows);
    echo "</pre>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
