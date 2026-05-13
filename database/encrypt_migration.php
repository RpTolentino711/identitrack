<?php
/**
 * Database Encryption Migration Script (Corrected)
 */

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/database.php';

$key = db_encryption_key();
$enckey_param = "UNHEX(SHA2(:__enckey, 256))";

echo "=== IdentiTrack Database Encryption Migration ===\n";
echo "Key: " . substr($key, 0, 10) . "****...\n";
echo "Database: " . db_config()['db'] . "\n\n";

// 1. ENCRYPT STUDENT TABLE
echo "[1/5] Encrypting student table...\n";
try {
  db_exec("
    UPDATE student SET
      student_fn = UNHEX(AES_ENCRYPT(student_fn, $enckey_param)),
      student_ln = UNHEX(AES_ENCRYPT(student_ln, $enckey_param)),
      student_email = UNHEX(AES_ENCRYPT(student_email, $enckey_param)),
      phone_number = UNHEX(AES_ENCRYPT(COALESCE(phone_number, ''), $enckey_param))
    WHERE 
      (student_fn NOT LIKE 0x25 OR student_ln NOT LIKE 0x25 OR student_email NOT LIKE 0x25)
  ", [':__enckey' => $key]);
  echo "✓ student table encrypted\n";
} catch (Exception $e) {
  echo "✗ Error: " . $e->getMessage() . "\n";
}

// 2. ENCRYPT OFFENSE TABLE
echo "[2/5] Encrypting offense table...\n";
try {
  db_exec("
    UPDATE offense SET
      description = UNHEX(AES_ENCRYPT(COALESCE(description, ''), $enckey_param))
    WHERE 
      (description NOT LIKE 0x25 AND description IS NOT NULL AND description != '')
  ", [':__enckey' => $key]);
  echo "✓ offense table encrypted\n";
} catch (Exception $e) {
  echo "✗ Error: " . $e->getMessage() . "\n";
}

// 3. ENCRYPT STUDENT_APPEAL_REQUEST TABLE
echo "[3/5] Encrypting student_appeal_request table...\n";
try {
  db_exec("
    UPDATE student_appeal_request SET
      reason = UNHEX(AES_ENCRYPT(COALESCE(reason, ''), $enckey_param)),
      admin_response = UNHEX(AES_ENCRYPT(COALESCE(admin_response, ''), $enckey_param))
    WHERE 
      (reason NOT LIKE 0x25 OR (admin_response IS NOT NULL AND admin_response NOT LIKE 0x25))
  ", [':__enckey' => $key]);
  echo "✓ student_appeal_request table encrypted\n";
} catch (Exception $e) {
  echo "✗ Error: " . $e->getMessage() . "\n";
}

// 4. ENCRYPT UPCC_CASE TABLE
echo "[4/5] Encrypting upcc_case table...\n";
try {
  db_exec("
    UPDATE upcc_case SET
      student_explanation_text = UNHEX(AES_ENCRYPT(COALESCE(student_explanation_text, ''), $enckey_param))
    WHERE 
      student_explanation_text NOT LIKE 0x25 AND student_explanation_text IS NOT NULL AND student_explanation_text != ''
  ", [':__enckey' => $key]);
  echo "✓ upcc_case table encrypted\n";
} catch (Exception $e) {
  echo "✗ Error: " . $e->getMessage() . "\n";
}

// 5. ENCRYPT COMMUNITY_SERVICE_REQUIREMENT TABLE
echo "[5/5] Encrypting community_service_requirement table...\n";
try {
  db_exec("
    UPDATE community_service_requirement SET
      task_name = UNHEX(AES_ENCRYPT(COALESCE(task_name, ''), $enckey_param)),
      location = UNHEX(AES_ENCRYPT(COALESCE(location, ''), $enckey_param)),
      notes = UNHEX(AES_ENCRYPT(COALESCE(notes, ''), $enckey_param))
    WHERE 
      (task_name NOT LIKE 0x25 OR (location IS NOT NULL AND location NOT LIKE 0x25))
  ", [':__enckey' => $key]);
  echo "✓ community_service_requirement table encrypted\n";
} catch (Exception $e) {
  echo "✗ Error: " . $e->getMessage() . "\n";
}

echo "\n=== Migration Complete ===\n";
