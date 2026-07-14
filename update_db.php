<?php
require_once __DIR__ . '/database/database.php';
header('Content-Type: application/json');
try {
    db_exec("ALTER TABLE community_service_requirement MODIFY COLUMN hours_required DECIMAL(10, 4) NOT NULL DEFAULT '0.0000'");
    echo json_encode(['success' => true, 'message' => 'Altered table successfully.']);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
