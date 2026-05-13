-- ============================================================
-- IdentiTrack Database Encryption Script
-- Run this in phpMyAdmin or MySQL CLI
-- ============================================================

-- IMPORTANT: Verify your encryption key first!
-- Encryption Key: IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!
-- Store this key in database.php

-- ============================================================
-- 1. ENCRYPT STUDENT TABLE
-- ============================================================
UPDATE student SET
  student_fn = UNHEX(AES_ENCRYPT(student_fn, UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256)))),
  student_ln = UNHEX(AES_ENCRYPT(student_ln, UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256)))),
  student_email = UNHEX(AES_ENCRYPT(student_email, UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256)))),
  student_phone = UNHEX(AES_ENCRYPT(COALESCE(student_phone, ''), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256))))
WHERE 
  (student_fn NOT LIKE 0x25 OR student_ln NOT LIKE 0x25 OR student_email NOT LIKE 0x25);

-- ============================================================
-- 2. ENCRYPT OFFENSE TABLE
-- ============================================================
UPDATE offense SET
  description = UNHEX(AES_ENCRYPT(COALESCE(description, ''), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256)))),
  location = UNHEX(AES_ENCRYPT(COALESCE(location, ''), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256)))),
  details = UNHEX(AES_ENCRYPT(COALESCE(details, ''), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256)))),
  reason = UNHEX(AES_ENCRYPT(COALESCE(reason, ''), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256))))
WHERE 
  (description NOT LIKE 0x25 OR location NOT LIKE 0x25);

-- ============================================================
-- 3. ENCRYPT STUDENT_APPEAL_REQUEST TABLE
-- ============================================================
UPDATE student_appeal_request SET
  reason = UNHEX(AES_ENCRYPT(COALESCE(reason, ''), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256)))),
  admin_response = UNHEX(AES_ENCRYPT(COALESCE(admin_response, ''), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256))))
WHERE 
  (reason NOT LIKE 0x25 OR admin_response NOT LIKE 0x25);

-- ============================================================
-- 4. ENCRYPT UPCC_CASE TABLE
-- ============================================================
UPDATE upcc_case SET
  student_explanation_text = UNHEX(AES_ENCRYPT(COALESCE(student_explanation_text, ''), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256))))
WHERE 
  student_explanation_text NOT LIKE 0x25 OR student_explanation_text IS NOT NULL;

-- ============================================================
-- 5. ENCRYPT COMMUNITY_SERVICE_REQUIREMENT TABLE
-- ============================================================
UPDATE community_service_requirement SET
  reason = UNHEX(AES_ENCRYPT(COALESCE(reason, ''), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256)))),
  location = UNHEX(AES_ENCRYPT(COALESCE(location, ''), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256)))),
  contact_person = UNHEX(AES_ENCRYPT(COALESCE(contact_person, ''), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256)))),
  contact_number = UNHEX(AES_ENCRYPT(COALESCE(contact_number, ''), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256))))
WHERE 
  (reason NOT LIKE 0x25 OR location NOT LIKE 0x25);

-- ============================================================
-- VERIFY ENCRYPTION
-- ============================================================
-- Test 1: Verify student data is encrypted
SELECT 
  student_id,
  AES_DECRYPT(UNHEX(student_fn), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256))) as student_fn,
  AES_DECRYPT(UNHEX(student_ln), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256))) as student_ln,
  AES_DECRYPT(UNHEX(student_email), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256))) as student_email
FROM student 
LIMIT 5;

-- Test 2: Verify offense data is encrypted
SELECT 
  offense_id,
  student_id,
  AES_DECRYPT(UNHEX(description), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256))) as description,
  AES_DECRYPT(UNHEX(location), UNHEX(SHA2('IdentiTrack2024SecureEncryptionKeyForDatabaseFiel!', 256))) as location
FROM offense 
LIMIT 5;
