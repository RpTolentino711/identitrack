# Database Encryption Guide - IdentiTrack

## Overview

All sensitive student data is now encrypted at rest using MySQL's **AES_ENCRYPT/AES_DECRYPT** with SHA-256 key derivation. Data is automatically decrypted when accessed by authenticated users through the 4 web systems:

1. **Student Flutter App** - Displays alerts, offenses, appeals, community service
2. **Admin Panel** - Manages all records and updates
3. **GUARD Dashboard** - Reports and incident tracking
4. **Reports System** - Analytics and exports

## Encrypted Columns

| Table | Columns |
|-------|---------|
| `student` | student_fn, student_ln, student_email, student_phone |
| `offense` | description, location, details, reason |
| `student_appeal_request` | reason, admin_response |
| `upcc_case` | student_explanation_text |
| `community_service_requirement` | reason, location, contact_person, contact_number |
| `upcc_case_discussion` | message (future migration) |

## Setup Instructions

### Step 1: Run the Migration Script

This encrypts all existing data in the database:

```bash
php database/encrypt_migration.php
```

Expected output:
```
=== IdentiTrack Database Encryption Migration ===
Key: IdentiTrack2*****
Database: u321173822_track

[1/6] Encrypting student table...
✓ student table encrypted
[2/6] Encrypting offense table...
✓ offense table encrypted
...
=== Migration Complete ===
```

### Step 2: Update Your Queries

All queries that access encrypted columns must:
1. **Decrypt on SELECT** using `db_decrypt_cols()`
2. **Encrypt on INSERT/UPDATE** using `db_encrypt_col()`
3. **Always pass the encryption key** in parameters as `:__enckey`

## Query Examples

### Example 1: SELECT Encrypted Data (Student Alert)

**OLD (insecure):**
```php
$student = db_one(
  "SELECT student_fn, student_ln, student_email FROM student WHERE student_id = :id",
  [':id' => $studentId]
);
```

**NEW (decrypted):**
```php
$decrypted_cols = db_decrypt_cols(['student_fn', 'student_ln', 'student_email']);
$student = db_one(
  "SELECT student_id, $decrypted_cols FROM student WHERE student_id = :id",
  [':id' => $studentId, ':__enckey' => db_encryption_key()]
);
// $student['student_fn'] is now readable
```

### Example 2: INSERT Encrypted Data (Admin Create Offense)

**OLD (insecure):**
```php
db_exec(
  "INSERT INTO offense (student_id, description, location) VALUES (:sid, :desc, :loc)",
  [':sid' => $sid, ':desc' => $desc, ':loc' => $loc]
);
```

**NEW (encrypted):**
```php
$params = [
  ':sid' => $sid,
  ':desc' => $desc,
  ':loc' => $loc,
];
db_add_encryption_key($params);  // Adds ':__enckey'

db_exec(
  "INSERT INTO offense (student_id, description, location) 
   VALUES (:sid, " . db_encrypt_col('description', ':desc') . ", " . db_encrypt_col('location', ':loc') . ")",
  $params
);
```

### Example 3: UPDATE with Encryption (Admin Update Offense)

```php
$params = [
  ':oid' => $offenseId,
  ':desc' => $newDescription,
];
db_add_encryption_key($params);

db_exec(
  "UPDATE offense 
   SET description = " . db_encrypt_col('description', ':desc') . "
   WHERE offense_id = :oid",
  $params
);
```

### Example 4: Filtered Query (Get Student by Email)

```php
$email = 'student@example.com';
$params = [':email' => $email];
db_add_encryption_key($params);

$decrypted_cols = db_decrypt_cols(['student_fn', 'student_ln', 'student_id']);
$student = db_one(
  "SELECT $decrypted_cols 
   FROM student 
   WHERE AES_DECRYPT(UNHEX(student_email), UNHEX(SHA2(:__enckey, 256))) = :email",
  $params
);
```

## Integration by Web System

### 1. **Student Flutter App** (`api/student/*.php`)

All 12 protected endpoints already use `StudentApiAuth`. Update each to decrypt:

```php
// api/student/alerts.php
require_student_api_auth($studentId);

$decrypted = db_decrypt_cols(['student_fn', 'student_ln']);
$student = db_one(
  "SELECT student_id, $decrypted FROM student WHERE student_id = :sid",
  [':sid' => $studentId, ':__enckey' => db_encryption_key()]
);

// Decrypt offense records for alerts
$decrypted_offense = db_decrypt_cols(['description', 'location', 'reason']);
$offenses = db_all(
  "SELECT offense_id, level, status, $decrypted_offense 
   FROM offense WHERE student_id = :sid",
  [':sid' => $studentId, ':__enckey' => db_encryption_key()]
);
```

### 2. **Admin Panel** (`admin/*.php`)

Update all record views and edits:

```php
// admin/offense_view.php (view)
$decrypted = db_decrypt_cols(['description', 'location', 'details', 'reason']);
$offense = db_one(
  "SELECT offense_id, student_id, level, status, $decrypted 
   FROM offense WHERE offense_id = :oid",
  [':oid' => $offenseId, ':__enckey' => db_encryption_key()]
);

// admin/offense_view.php (edit)
$params = [':oid' => $offenseId, ':desc' => $newDesc];
db_add_encryption_key($params);
db_exec(
  "UPDATE offense SET description = " . db_encrypt_col('description', ':desc') . " WHERE offense_id = :oid",
  $params
);
```

### 3. **GUARD Dashboard** (`GUARD/api_*.php`)

Decrypt guard reports:

```php
// GUARD/api_search_student.php
$decrypted = db_decrypt_cols(['student_fn', 'student_ln', 'student_email']);
$students = db_all(
  "SELECT student_id, $decrypted 
   FROM student 
   WHERE AES_DECRYPT(UNHEX(student_fn), UNHEX(SHA2(:key, 256))) LIKE :search",
  [':search' => $search, ':key' => db_encryption_key()]
);
```

### 4. **Reports System** (`admin/reports.php`)

Decrypt analytics data:

```php
// admin/reports.php
$decrypted_student = db_decrypt_cols(['student_fn', 'student_ln']);
$decrypted_offense = db_decrypt_cols(['description', 'reason']);
$report = db_all(
  "SELECT s.student_id, $decrypted_student, 
          o.offense_id, o.level, $decrypted_offense
   FROM offense o
   JOIN student s ON o.student_id = s.student_id
   WHERE o.date_committed BETWEEN :start AND :end",
  [
    ':start' => $startDate,
    ':end' => $endDate,
    ':__enckey' => db_encryption_key()
  ]
);
```

## Best Practices

### ✅ DO:
- Always call `db_add_encryption_key($params)` before executing queries with encrypted data
- Use `db_decrypt_cols()` for SELECT statements on multiple columns
- Use `db_encrypt_col()` only in INSERT/UPDATE statements
- Test queries in development with real student data

### ❌ DON'T:
- Store the encryption key in code (move to `.env` in production)
- Use encryption key in logs or error messages
- Mix encrypted and plaintext data in the same field
- Forget `:__enckey` parameter - queries will fail

## Troubleshooting

### Query returns NULL or garbled text
```php
// Missing encryption key parameter
$params = [':id' => $id];
db_add_encryption_key($params);  // Add this line
```

### "Error: Undefined function db_decrypt_cols"
```php
require_once __DIR__ . '/database.php';  // Make sure this is included
```

### Performance concerns
- Encryption adds ~2-5ms per query
- Index searches on encrypted columns require decryption (slower)
- Consider caching frequently accessed student names

## Security Checklist

- [x] Database encryption at rest ✓
- [x] API authentication (Bearer tokens) ✓
- [x] CORS headers with Authorization ✓
- [ ] HTTPS/SSL enforcement (setup in production)
- [ ] Encryption key management in `.env` (not hardcoded)
- [ ] Database backups with encrypted keys stored separately
- [ ] Audit logging for data access

## Questions?

For issues or questions about encryption:
1. Check migration output for errors: `php database/encrypt_migration.php`
2. Verify a test query: `php -r "require 'database/database.php'; var_dump(db_one('SELECT 1'))"`
3. Review the encryption key: `php -r "require 'database/database.php'; echo db_encryption_key()"`
