# Reverting Database Encryption - IdentiTrack

Because the database encryption model caused critical issues across the system (such as breaking search queries and causing errors in third-party integrations), the encryption has been **completely disabled at the application layer**.

All database queries will now read and write data in **plaintext**.

---

## Steps Taken

1. **Disabled Encryption Helpers**:
   The core functions in `database/database.php` (`db_encrypt_col`, `db_decrypt_col`, and `db_decrypt_cols`) have been updated to return direct column/parameter names, effectively bypassing the `AES_ENCRYPT` and `AES_DECRYPT` wrapping.
   
2. **Added Decryption Script**:
   A decryption migration script has been created at `database/decrypt_migration.php` to restore any existing encrypted data in the database back to plaintext.

---

## How to Decrypt Existing Data

If you have already run the encryption migration script (`database/encrypt_migration.php` or `migrate_to_encryption.php`) and some of your database rows contain encrypted binary data, you must run the decryption migration script to convert them back to readable plaintext.

### Running via Command Line:
Navigate to the project root directory and run:
```bash
php database/decrypt_migration.php
```

### Expected Output:
```
=== IdentiTrack Database Decryption Migration ===
Key: IdentiTra****...

Processing table: student...
  ✓ Table 'student' processed. Decrypted 15 rows.
Processing table: offense...
  ✓ Table 'offense' processed. Decrypted 45 rows.
...
=== Decryption Migration Complete ===
```

---

## Verifying plaintext status
You can check if the data has been restored to plaintext by querying your database directly (e.g. via phpMyAdmin) or by logging in to the admin dashboard. If names and descriptions display properly, the reversion was successful.
