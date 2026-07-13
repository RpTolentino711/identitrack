<?php
// File: C:\xampp\htdocs\identitrack\database\database.php
// PDO + helpers + ADMIN auth (USERNAME ONLY)

declare(strict_types=1);
date_default_timezone_set('Asia/Manila');

// ini_set('display_errors', '1');
// ini_set('display_startup_errors', '1');
// error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) {
  session_start();
}

/* =========================
   ENVIRONMENT & AUTOLOAD
   ========================= */
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('Dotenv\Dotenv')) {
        $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
        $dotenv->safeLoad();
    }
}

/**
 * Get DB connection (PDO)
 */
function db(): PDO
{
  static $pdo = null;
  if ($pdo === null) {
    // Helper to get from $_ENV, $_SERVER or getenv()
    $getEnv = function($key, $default) {
        return (string)($_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default);
    };

    $host = $getEnv('DB_HOST', 'localhost');
    $db   = $getEnv('DB_NAME', 'identitrack');
    $user = $getEnv('DB_USER', 'root');
    $pass = $getEnv('DB_PASS', '');

    $dsn = "mysql:host=$host;dbname=$db;charset=utf8mb4";
    $options = [
      PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES   => true, 
    ];

    try {
      $pdo = new PDO($dsn, $user, $pass, $options);
      $pdo->exec("SET time_zone = '+08:00'");
    } catch (PDOException $e) {
      // Fallback for local development
      try {
        $dsnLocal = "mysql:host=localhost;dbname=identitrack;charset=utf8mb4";
        $pdo = new PDO($dsnLocal, 'root', '', $options);
        $pdo->exec("SET time_zone = '+08:00'");
      } catch (PDOException $e2) {
        die("DB Connection failed: " . $e->getMessage() . " (Fallback to local 'identitrack' database also failed: " . $e2->getMessage() . ")");
      }
    }
  }
  return $pdo;
}

/**
 * Get the encryption key from environment
 */
function db_encryption_key(): string
{
  // 1. Try to find .env manually (Hostinger fix)
  static $envLoaded = false;
  static $envKey = null;

  if (!$envLoaded) {
      $paths = [__DIR__ . '/../.env', __DIR__ . '/.env'];
      foreach ($paths as $envPath) {
          if (file_exists($envPath)) {
              $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
              foreach ($lines as $line) {
                  $line = trim($line);
                  if ($line === '' || strpos($line, '#') === 0) continue;
                  if (strpos($line, '=') !== false) {
                      list($name, $value) = explode('=', $line, 2);
                      $cleanName = preg_replace('/[^a-zA-Z0-9_]/', '', $name);
                      if ($cleanName === 'DB_ENCRYPTION_KEY') {
                          $envKey = trim(trim($value), " \t\n\r\0\x0B\"'");
                          break 2;
                      }
                  }
              }
          }
      }
      $envLoaded = true;
  }

  if ($envKey !== null) return $envKey;

  $key = trim((string)($_ENV['DB_ENCRYPTION_KEY'] ?? $_SERVER['DB_ENCRYPTION_KEY'] ?? getenv('DB_ENCRYPTION_KEY') ?: ''));
  if ($key === '') {
      // Fallback for local dev if .env is missing
      return 'IdentiTrack_Secure_Key_2024_@SDO';
  }
  return $key;
}

function getConnection(): PDO
{
  return db();
}

/* =========================
   DB HELPERS
   ========================= */
function db_clean_params(string $sql, array $params): array
{
  if (array_key_exists(':__enckey', $params) && strpos($sql, ':__enckey') === false) {
    unset($params[':__enckey']);
  }
  if (array_key_exists('__enckey', $params) && strpos($sql, '__enckey') === false) {
    unset($params['__enckey']);
  }
  return $params;
}

function db_all(string $sql, array $params = []): array
{
  $params = db_clean_params($sql, $params);
  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  return $stmt->fetchAll();
}

function db_one(string $sql, array $params = []): ?array
{
  $params = db_clean_params($sql, $params);
  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  $row = $stmt->fetch();
  return ($row === false) ? null : $row;
}

function db_exec(string $sql, array $params = []): int
{
  $params = db_clean_params($sql, $params);
  $stmt = db()->prepare($sql);
  $stmt->execute($params);
  return $stmt->rowCount();
}

function db_last_id(): string
{
  return db()->lastInsertId();
}

/* =========================
   DATABASE ENCRYPTION HELPERS
   ========================= */
/**
 * Encrypt a value using MySQL's AES_ENCRYPT for storage
 * Call this in INSERT/UPDATE queries with UNHEX() wrapper
 * Example: INSERT INTO student (student_fn) VALUES (UNHEX(AES_ENCRYPT(:name, UNHEX(SHA2(:key, 256)))))
 */
function db_encrypt_sql_value(string $value, string $key = ''): string
{
  if ($key === '') {
    $key = db_encryption_key();
  }
  // This is used for binding values, but actually the encryption happens in SQL.
  // This helper is rarely used directly for SQL values; usually db_encrypt_col() is used in the query.
  return $value;
}

/**
 * Create encrypted INSERT value for PDO binding
 * Usage: db_exec("INSERT INTO student (student_fn) VALUES (UNHEX(AES_ENCRYPT(:fn, UNHEX(SHA2(:enckey, 256)))))", 
 *               [':fn' => $name, ':enckey' => db_encryption_key()])
 */
function db_encrypt_col(string $columnName, string $paramName = ''): string
{
  if ($paramName === '') {
    $paramName = ':' . $columnName;
  }
  return $paramName;
}

/**
 * Decrypt column in SELECT query (disabled, returns plaintext column)
 */
function db_decrypt_col(string $columnName, string $alias = ''): string
{
  $col = ($alias !== '') ? "$alias.$columnName" : $columnName;
  return $col;
}

/**
 * Build a SELECT with decryption for multiple encrypted columns (disabled, returns plaintext columns)
 */
function db_decrypt_cols(array $cols, string $tableAlias = ''): string
{
  $decrypted = [];
  foreach ($cols as $col) {
    $decrypted[] = db_decrypt_col($col, $tableAlias) . " AS $col";
  }
  return implode(', ', $decrypted);
}

/**
 * Apply encryption key to all query parameters (kept for backward compatibility)
 */
function db_add_encryption_key(array &$params): void
{
  $params[':__enckey'] = db_encryption_key();
}

function student_api_unauthorized(string $message = 'Unauthorized', int $status = 401): void
{
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => false, 'message' => $message, 'data' => null]);
  exit;
}

function student_api_bearer_token(): string
{
  $headers = function_exists('getallheaders') ? getallheaders() : [];
  $candidates = [
    (string)($headers['Authorization'] ?? $headers['authorization'] ?? ''),
    (string)($_SERVER['HTTP_AUTHORIZATION'] ?? ''),
    (string)($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''),
    (string)($_SERVER['HTTP_X_STUDENT_TOKEN'] ?? ''),
  ];

  foreach ($candidates as $candidate) {
    $candidate = trim($candidate);
    if ($candidate === '') {
      continue;
    }

    if (stripos($candidate, 'Bearer ') === 0) {
      return trim(substr($candidate, 7));
    }

    return $candidate;
  }

  return '';
}

function require_student_api_auth(?string $studentId = null): array
{
  $token = student_api_bearer_token();
  if ($token === '') {
    student_api_unauthorized('Unauthorized.');
  }

  $sql = "SELECT student_id, session_token_hash, expires_at
          FROM auth_session
          WHERE actor_type = 'STUDENT'
            AND expires_at > NOW()";
  $params = [];

  if ($studentId !== null && $studentId !== '') {
    $sql .= ' AND student_id = :sid';
    $params[':sid'] = $studentId;
  }

  $sessions = db_all($sql, $params);
  foreach ($sessions as $session) {
    if (password_verify($token, (string)($session['session_token_hash'] ?? ''))) {
      return $session;
    }
  }

  student_api_unauthorized('Unauthorized.');
}

/* =========================
   UTILS
   ========================= */
function e(string $v): string
{
  return htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): void
{
  header('Location: ' . $url);
  exit;
}

/* =========================
   ADMIN AUTH (USERNAME ONLY)
   ========================= */
function admin_find_by_username(string $username): ?array
{
  $username = trim(strtolower($username));

  return db_one(
    "SELECT admin_id, full_name, username, email, role, is_active, password_hash, photo_path
     FROM admin_user
     WHERE username = :username
     LIMIT 1",
    [':username' => $username]
  );
}

function admin_login(string $username, string $password): array
{
  $username = trim($username);

  if ($username === '') {
    return ['ok' => false, 'error' => 'Please enter your username.'];
  }
  if ($password === '') {
    return ['ok' => false, 'error' => 'Please enter your password.'];
  }

  $admin = admin_find_by_username($username);

  if (!$admin) {
    return ['ok' => false, 'error' => 'Account not found.'];
  }
  if ((int)$admin['is_active'] !== 1) {
    return ['ok' => false, 'error' => 'Account is inactive.'];
  }

  if (!array_key_exists('password_hash', $admin)) {
    return ['ok' => false, 'error' => 'Database missing admin_user.password_hash column.'];
  }

  // IMPORTANT: password_hash must be a bcrypt hash ($2y$...)
  if (empty($admin['password_hash']) || strpos((string)$admin['password_hash'], '$2y$') !== 0) {
    return ['ok' => false, 'error' => 'Password is not set correctly. Ask admin to reset password.' ];
  }

  if (!password_verify($password, (string)$admin['password_hash'])) {
    return ['ok' => false, 'error' => 'Incorrect password.'];
  }

  $_SESSION['admin'] = [
    'admin_id' => (int)$admin['admin_id'],
    'full_name' => (string)$admin['full_name'],
    'username' => (string)$admin['username'],
    'email' => (string)$admin['email'],
    'role' => (string)$admin['role'],
    'photo_path' => (string)($admin['photo_path'] ?? ''),
  ];

  return ['ok' => true];
}

function admin_logout(): void
{
  $admin = admin_current();
  if ($admin) {
    db_exec("UPDATE admin_user SET last_active = NULL WHERE admin_id = :id", [':id' => (int)$admin['admin_id']]);
  }
  unset($_SESSION['admin']);
}

function admin_current(): ?array
{
  return (isset($_SESSION['admin']) && is_array($_SESSION['admin']))
    ? $_SESSION['admin']
    : null;
}

function require_admin(): void
{
  $admin = admin_current();
  if (!$admin) {
    redirect('login.php');
  }

  static $updatedActive = false;
  if (!$updatedActive) {
    db_exec("UPDATE admin_user SET last_active = NOW() WHERE admin_id = :id", [':id' => (int)$admin['admin_id']]);
    $updatedActive = true;
  }
}

/* =========================
   PASSWORD HELPERS
   ========================= */
function admin_set_password(int $adminId, string $plainPassword): void
{
  $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

  db_exec(
    "UPDATE admin_user
     SET password_hash = :hash, updated_at = NOW()
     WHERE admin_id = :id",
    [':hash' => $hash, ':id' => $adminId]
  );
}

function admin_set_password_by_username(string $username, string $plainPassword): bool
{
  $admin = admin_find_by_username($username);
  if (!$admin) return false;

  admin_set_password((int)$admin['admin_id'], $plainPassword);
  return true;
}

function admin_find_by_id(int $adminId): ?array
{
  return db_one(
    "SELECT admin_id, full_name, username, email, role, is_active, password_hash, photo_path
     FROM admin_user
     WHERE admin_id = :id
     LIMIT 1",
    [':id' => $adminId]
  );
}

function admin_verify_password(int $adminId, string $plainPassword): bool
{
  if ($adminId <= 0 || $plainPassword === '') return false;

  $admin = admin_find_by_id($adminId);
  if (!$admin) return false;
  if ((int)$admin['is_active'] !== 1) return false;

  $hash = (string)($admin['password_hash'] ?? '');
  if ($hash === '') return false;

  return password_verify($plainPassword, $hash);
}

/* =========================
   UPCC AUTH (USERNAME ONLY)
   ========================= */
function upcc_find_by_username(string $username): ?array
{
  $username = trim(strtolower($username));

  return db_one(
    "SELECT upcc_id, full_name, username, email, role, is_active, password_hash, photo_path
     FROM upcc_user
     WHERE username = :username
     LIMIT 1",
    [':username' => $username]
  );
}

function upcc_login(string $username, string $password): array
{
  $username = trim($username);

  if ($username === '') {
    return ['ok' => false, 'error' => 'Please enter your username.'];
  }
  if ($password === '') {
    return ['ok' => false, 'error' => 'Please enter your password.'];
  }

  $upcc = upcc_find_by_username($username);

  if (!$upcc) {
    return ['ok' => false, 'error' => 'Account not found.'];
  }
  if ((int)$upcc['is_active'] !== 1) {
    return ['ok' => false, 'error' => 'Account is inactive.'];
  }

  if (!array_key_exists('password_hash', $upcc)) {
    return ['ok' => false, 'error' => 'Database missing upcc_user.password_hash column.'];
  }

  // IMPORTANT: password_hash must be a bcrypt hash ($2y$...)
  if (empty($upcc['password_hash']) || strpos((string)$upcc['password_hash'], '$2y$') !== 0) {
    return ['ok' => false, 'error' => 'Password is not set correctly. Ask admin to reset password.' ];
  }

  if (!password_verify($password, (string)$upcc['password_hash'])) {
    return ['ok' => false, 'error' => 'Incorrect password.'];
  }

  $user = [
    'upcc_id' => (int)$upcc['upcc_id'],
    'full_name' => (string)$upcc['full_name'],
    'username' => (string)$upcc['username'],
    'email' => (string)$upcc['email'],
    'role' => (string)$upcc['role'],
    'photo_path' => (string)($upcc['photo_path'] ?? ''),
  ];

  return ['ok' => true, 'user' => $user];
}

function upcc_logout(): void
{
  unset($_SESSION['upcc_user']);
}

function upcc_current(): ?array
{
  return (isset($_SESSION['upcc_user']) && is_array($_SESSION['upcc_user']))
    ? $_SESSION['upcc_user']
    : null;
}

function require_upcc(): void
{
  if (!upcc_current()) {
    redirect('../UPCC/upccpanel.php');
  }
}

/* =========================
   UPCC PASSWORD HELPERS
   ========================= */
function upcc_set_password(int $upccId, string $plainPassword): void
{
  $hash = password_hash($plainPassword, PASSWORD_DEFAULT);

  db_exec(
    "UPDATE upcc_user
     SET password_hash = :hash, updated_at = NOW()
     WHERE upcc_id = :id",
    [':hash' => $hash, ':id' => $upccId]
  );
}

function upcc_set_password_by_username(string $username, string $plainPassword): bool
{
  $upcc = upcc_find_by_username($username);
  if (!$upcc) return false;

  upcc_set_password((int)$upcc['upcc_id'], $plainPassword);
  return true;
}

function upcc_find_by_id(int $upccId): ?array
{
  return db_one(
    "SELECT upcc_id, full_name, username, email, role, is_active, password_hash, photo_path
     FROM upcc_user
     WHERE upcc_id = :id
     LIMIT 1",
    [':id' => $upccId]
  );
}

function upcc_verify_password(int $upccId, string $plainPassword): bool
{
  if ($upccId <= 0 || $plainPassword === '') return false;

  $upcc = upcc_find_by_id($upccId);
  if (!$upcc) return false;
  if ((int)$upcc['is_active'] !== 1) return false;

  $hash = (string)($upcc['password_hash'] ?? '');
  if ($hash === '') return false;

  return password_verify($plainPassword, $hash);
}

/* =========================
   OPTIONAL: INSERT DEFAULT UPCC USER (for testing)
   ========================= */
function upcc_create_default_user(): void {
  $default_username = 'upcc_admin';
  $default_password = 'upcc123';
  
  $existing = upcc_find_by_username($default_username);
  if (!$existing) {
    $hash = password_hash($default_password, PASSWORD_DEFAULT);
    db_exec(
      "INSERT INTO upcc_user (full_name, username, email, role, password_hash, is_active) 
       VALUES ('UPCC Administrator', :username, 'upcc@nu-lipa.edu.ph', 'admin', :hash, 1)",
      [':username' => $default_username, ':hash' => $hash]
    );
  }
}

/* =========================
   HEARING WORKFLOW HELPERS
   ========================= */
function ensure_hearing_workflow_schema(): void
{
  static $ready = false;
  if ($ready) {
    return;
  }

  // Extend the UPCC case table with hearing lifecycle fields.
  $columns = [
    'hearing_date' => "ALTER TABLE upcc_case ADD COLUMN hearing_date DATE DEFAULT NULL",
    'hearing_time' => "ALTER TABLE upcc_case ADD COLUMN hearing_time TIME DEFAULT NULL",
    'hearing_type' => "ALTER TABLE upcc_case ADD COLUMN hearing_type ENUM('ONLINE','FACE_TO_FACE') DEFAULT NULL",
    'hearing_link_or_location' => "ALTER TABLE upcc_case ADD COLUMN hearing_link_or_location VARCHAR(255) DEFAULT NULL",
    'hearing_is_open' => "ALTER TABLE upcc_case ADD COLUMN hearing_is_open TINYINT(1) NOT NULL DEFAULT 0",
    'hearing_is_paused' => "ALTER TABLE upcc_case ADD COLUMN hearing_is_paused TINYINT(1) NOT NULL DEFAULT 0",
    'hearing_opened_at' => "ALTER TABLE upcc_case ADD COLUMN hearing_opened_at DATETIME DEFAULT NULL",
    'hearing_closed_at' => "ALTER TABLE upcc_case ADD COLUMN hearing_closed_at DATETIME DEFAULT NULL",
    'hearing_opened_by_admin' => "ALTER TABLE upcc_case ADD COLUMN hearing_opened_by_admin INT DEFAULT NULL",
    'hearing_vote_consensus_category' => "ALTER TABLE upcc_case ADD COLUMN hearing_vote_consensus_category TINYINT DEFAULT NULL",
    'hearing_vote_consensus_at' => "ALTER TABLE upcc_case ADD COLUMN hearing_vote_consensus_at DATETIME DEFAULT NULL",
    'hearing_vote_suggester_id' => "ALTER TABLE upcc_case ADD COLUMN hearing_vote_suggester_id INT DEFAULT NULL",
    'hearing_vote_suggested_details' => "ALTER TABLE upcc_case ADD COLUMN hearing_vote_suggested_details JSON DEFAULT NULL",
    'probation_until' => "ALTER TABLE upcc_case ADD COLUMN probation_until DATETIME DEFAULT NULL",
    'punishment_details' => "ALTER TABLE upcc_case ADD COLUMN punishment_details JSON DEFAULT NULL",
    'student_explanation_text' => "ALTER TABLE upcc_case ADD COLUMN student_explanation_text TEXT DEFAULT NULL",
    'student_explanation_image' => "ALTER TABLE upcc_case ADD COLUMN student_explanation_image VARCHAR(255) DEFAULT NULL",
    'student_explanation_pdf' => "ALTER TABLE upcc_case ADD COLUMN student_explanation_pdf VARCHAR(255) DEFAULT NULL",
    'student_explanation_at' => "ALTER TABLE upcc_case ADD COLUMN student_explanation_at DATETIME DEFAULT NULL",
    'student_hearing_response' => "ALTER TABLE upcc_case ADD COLUMN student_hearing_response ENUM('PENDING','ACCEPTED','DECLINED') NOT NULL DEFAULT 'PENDING'",
  ];

  foreach ($columns as $column => $sql) {
    $exists = db_one(
      "SELECT 1
       FROM information_schema.COLUMNS
       WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = 'upcc_case'
         AND COLUMN_NAME = :col
       LIMIT 1",
      [':col' => $column]
    );
    if (!$exists) {
      db_exec($sql);
    }
  }

  $statusCol = db_one(
    "SELECT COLUMN_TYPE
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'upcc_case'
       AND COLUMN_NAME = 'status'
     LIMIT 1"
  );

  if ($statusCol && strpos((string)$statusCol['COLUMN_TYPE'], 'AWAITING_ADMIN_FINALIZATION') === false) {
    db_exec("ALTER TABLE upcc_case MODIFY status ENUM('PENDING','UNDER_INVESTIGATION','RESOLVED','CLOSED','UNDER_APPEAL','CANCELLED','AWAITING_ADMIN_FINALIZATION') NOT NULL DEFAULT 'PENDING'");
  }

  $hasReminderCol = db_one("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'upcc_case_panel_member' AND COLUMN_NAME = 'last_reminder_sent_at'");
  if (!$hasReminderCol && db_one("SHOW TABLES LIKE 'upcc_case_panel_member'")) {
      db_exec("ALTER TABLE upcc_case_panel_member ADD COLUMN last_reminder_sent_at DATETIME DEFAULT NULL");
  }

  db_exec("CREATE TABLE IF NOT EXISTS upcc_case_activity (
      activity_id BIGINT NOT NULL AUTO_INCREMENT,
      case_id BIGINT NOT NULL,
      actor_type ENUM('ADMIN','UPCC','SYSTEM') NOT NULL,
      actor_id INT NOT NULL DEFAULT 0,
      action VARCHAR(80) NOT NULL,
      payload_json LONGTEXT DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (activity_id),
      KEY idx_case_activity_case (case_id),
      KEY idx_case_activity_created (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

      db_exec("CREATE TABLE IF NOT EXISTS upcc_case_discussion (
        message_id BIGINT NOT NULL AUTO_INCREMENT,
        case_id BIGINT NOT NULL,
        upcc_id INT DEFAULT NULL,
        admin_id INT DEFAULT NULL,
        reply_to_message_id BIGINT DEFAULT NULL,
        message TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (message_id),
        KEY idx_case_discussion_case (case_id),
        KEY idx_case_discussion_panel (upcc_id),
        KEY idx_case_discussion_admin (admin_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

      // Auto-migrate in case the table was already created
      $hasAdminId = db_one("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'upcc_case_discussion' AND COLUMN_NAME = 'admin_id'");
      if (!$hasAdminId) {
          db_exec("ALTER TABLE upcc_case_discussion MODIFY upcc_id INT DEFAULT NULL");
          db_exec("ALTER TABLE upcc_case_discussion ADD COLUMN admin_id INT DEFAULT NULL AFTER upcc_id");
          db_exec("ALTER TABLE upcc_case_discussion ADD COLUMN reply_to_message_id BIGINT DEFAULT NULL AFTER admin_id");
          db_exec("ALTER TABLE upcc_case_discussion ADD INDEX idx_case_discussion_admin (admin_id)");
      }

  $csrStatusCol = db_one("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'community_service_requirement' AND COLUMN_NAME = 'status'");
  if ($csrStatusCol && strpos((string)$csrStatusCol['COLUMN_TYPE'], 'PENDING_ACCEPTANCE') === false) {
      db_exec("ALTER TABLE community_service_requirement MODIFY status ENUM('ACTIVE','COMPLETED','CANCELLED','PENDING_ACCEPTANCE') NOT NULL DEFAULT 'ACTIVE'");
  }

  db_exec("CREATE TABLE IF NOT EXISTS upcc_hearing_presence (
      session_id BIGINT NOT NULL AUTO_INCREMENT,
      case_id BIGINT NOT NULL,
      user_type ENUM('ADMIN', 'UPCC') NOT NULL,
      user_id INT NOT NULL,
      status ENUM('WAITING', 'ADMITTED', 'LEFT') NOT NULL DEFAULT 'WAITING',
      last_ping DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (session_id),
      UNIQUE KEY uq_hearing_user (case_id, user_type, user_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

  db_exec("CREATE TABLE IF NOT EXISTS upcc_case_vote_round (
      case_id BIGINT NOT NULL,
      round_no INT NOT NULL DEFAULT 1,
      started_at DATETIME NOT NULL,
      ends_at DATETIME NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      PRIMARY KEY (case_id, round_no),
      KEY idx_vote_round_ends (ends_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

  $voteRoundPrimaryCols = db_all(
    "SELECT k.COLUMN_NAME
     FROM information_schema.TABLE_CONSTRAINTS t
     JOIN information_schema.KEY_COLUMN_USAGE k
       ON k.CONSTRAINT_NAME = t.CONSTRAINT_NAME
      AND k.TABLE_SCHEMA = t.TABLE_SCHEMA
      AND k.TABLE_NAME = t.TABLE_NAME
     WHERE t.TABLE_SCHEMA = DATABASE()
       AND t.TABLE_NAME = 'upcc_case_vote_round'
       AND t.CONSTRAINT_TYPE = 'PRIMARY KEY'
     ORDER BY k.ORDINAL_POSITION"
  );
  $primaryColNames = array_map(static fn($r) => (string)($r['COLUMN_NAME'] ?? ''), $voteRoundPrimaryCols);
  if ($primaryColNames !== ['case_id', 'round_no']) {
    db_exec("ALTER TABLE upcc_case_vote_round DROP PRIMARY KEY, ADD PRIMARY KEY (case_id, round_no)");
  }

  $hasVoteRoundSuggestedBy = db_one(
    "SELECT 1
     FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE()
       AND TABLE_NAME = 'upcc_case_vote_round'
       AND COLUMN_NAME = 'suggested_by'
     LIMIT 1"
  );
  if (!$hasVoteRoundSuggestedBy) {
    db_exec("ALTER TABLE upcc_case_vote_round ADD COLUMN suggested_by INT DEFAULT NULL AFTER is_active");
  }

  db_exec("CREATE TABLE IF NOT EXISTS upcc_case_vote (
      case_id BIGINT NOT NULL,
      upcc_id INT NOT NULL,
      round_no INT NOT NULL,
      vote_category TINYINT NOT NULL,
      vote_details LONGTEXT DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (case_id, upcc_id, round_no),
      KEY idx_case_vote_case_round (case_id, round_no),
      KEY idx_case_vote_member (upcc_id)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

  $hasVoteDetails = db_one("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'upcc_case_vote' AND COLUMN_NAME = 'vote_details'");
  if (!$hasVoteDetails) {
    db_exec("ALTER TABLE upcc_case_vote ADD COLUMN vote_details LONGTEXT DEFAULT NULL AFTER vote_category");
  }

  db_exec("CREATE TABLE IF NOT EXISTS student_appeal_request (
      appeal_id BIGINT NOT NULL AUTO_INCREMENT,
      student_id VARCHAR(30) NOT NULL,
      offense_id BIGINT DEFAULT NULL,
      case_id BIGINT DEFAULT NULL,
      appeal_kind ENUM('OFFENSE','UPCC_CASE') NOT NULL DEFAULT 'OFFENSE',
      reason TEXT NOT NULL,
      status ENUM('PENDING','REVIEWING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING',
      admin_response TEXT DEFAULT NULL,
      decided_by INT DEFAULT NULL,
      decided_at DATETIME DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (appeal_id),
      KEY idx_student_appeal_student (student_id),
      KEY idx_student_appeal_status (status),
      KEY idx_student_appeal_case (case_id),
      KEY idx_student_appeal_offense (offense_id),
      KEY idx_student_appeal_created (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

  $hasLastActiveCol = db_one("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_user' AND COLUMN_NAME = 'last_active'");
  if (!$hasLastActiveCol) {
      db_exec("ALTER TABLE admin_user ADD COLUMN last_active DATETIME DEFAULT NULL");
  }

  $ready = true;
}

function upcc_case_panel_ids(int $caseId): array
{
  if ($caseId <= 0) {
    return [];
  }
  $rows = db_all("SELECT upcc_id FROM upcc_case_panel_member WHERE case_id = :case_id", [':case_id' => $caseId]);
  $ids = [];
  foreach ($rows as $row) {
    $id = (int)($row['upcc_id'] ?? 0);
    if ($id > 0) {
      $ids[] = $id;
    }
  }
  return array_values(array_unique($ids));
}

function upcc_log_case_activity(int $caseId, string $actorType, int $actorId, string $action, array $payload = []): void
{
  if ($caseId <= 0 || $action === '') {
    return;
  }
  $type = strtoupper(trim($actorType));
  if (!in_array($type, ['ADMIN', 'UPCC', 'SYSTEM'], true)) {
    $type = 'SYSTEM';
  }
  db_exec(
    "INSERT INTO upcc_case_activity (case_id, actor_type, actor_id, action, payload_json, created_at)
     VALUES (:case_id, :actor_type, :actor_id, :action, :payload_json, NOW())",
    [
      ':case_id' => $caseId,
      ':actor_type' => $type,
      ':actor_id' => max(0, $actorId),
      ':action' => $action,
      ':payload_json' => $payload ? json_encode($payload) : null,
    ]
  );
}

function upcc_staff_case_access_block_reason(array $case): ?string
{
  $status = strtoupper((string)($case['status'] ?? ''));
  if ($status === 'CLOSED' || $status === 'RESOLVED') {
    return 'This hearing is already resolved and permanently closed for UPCC staff.';
  }

  $hearingDate = trim((string)($case['hearing_date'] ?? ''));
  if ($hearingDate === '') {
    return 'This hearing has not been scheduled by Admin yet.';
  }


  if ((int)($case['hearing_is_open'] ?? 0) !== 1) {
    return 'Admin has not opened this hearing yet.';
  }

  return null;
}

/**
 * Sends an email notification to assigned panel members.
 */
function upcc_send_panel_assignment_email(int $caseId, array $panelIds): array {
    if ($caseId <= 0 || empty($panelIds)) {
        return ['ok' => false, 'error' => 'Invalid parameters'];
    }

    $case = db_one("SELECT uc.case_id, uc.hearing_date, uc.hearing_time, uc.hearing_type, uc.hearing_link_or_location, uc.created_at,
                           CONCAT(s.student_fn, ' ', s.student_ln) as student_name
                    FROM upcc_case uc
                    JOIN student s ON s.student_id = uc.student_id
                    WHERE uc.case_id = :id", [':id' => $caseId]);
    if (!$case) return ['ok' => false, 'error' => 'Case not found'];

    $ids = array_map('intval', $panelIds);
    $in = implode(',', $ids);
    $members = db_all("SELECT full_name, email FROM upcc_user WHERE upcc_id IN ($in) AND is_active = 1");
    if (empty($members)) return ['ok' => false, 'error' => 'No active members found'];

    require_once __DIR__ . '/../UPCC/class.phpmailer.php';
    require_once __DIR__ . '/../UPCC/class.smtp.php';

    $results = [];
    $caseLabel = 'UPCC-' . date('Y', strtotime($case['created_at'])) . '-' . str_pad((string)$caseId, 3, '0', STR_PAD_LEFT);
    $hearingAt = $case['hearing_date'] ? date('M j, Y', strtotime($case['hearing_date'])) : 'TBD';
    if ($case['hearing_time']) $hearingAt .= ' at ' . date('g:i A', strtotime($case['hearing_time']));

    $hearingTypeLabel = ($case['hearing_type'] === 'FACE_TO_FACE') ? 'Face-to-Face' : 'Online / Virtual';
    $hearingLoc = htmlspecialchars($case['hearing_link_or_location'] ?? 'Not provided');
    if ($case['hearing_type'] === 'ONLINE' && filter_var($case['hearing_link_or_location'], FILTER_VALIDATE_URL)) {
        $hearingLoc = "<a href='{$case['hearing_link_or_location']}' target='_blank'>{$case['hearing_link_or_location']}</a>";
    }

    foreach ($members as $m) {
        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.hostinger.com';
            $mail->Port = 587;
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = 'tls';
            $mail->Username = $_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site';
            $mail->Password = $_ENV['SMTP_PASS'] ?? '';
            $mail->Timeout = 30;

            $mail->setFrom($_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site', 'IdentiTrack UPCC');
            $mail->addAddress($m['email'], $m['full_name']);
            $mail->Subject = "Panel Assignment: Case $caseLabel";

            $loginUrl = "https://identitrack.site/UPCC/upccpanel.php";
            $mail->isHTML(true);
            $mail->Body = "
                <div style='font-family:sans-serif; max-width:600px; line-height:1.6; color:#333; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px;'>
                    <h2 style='color:#1e3a8a; margin-top: 0;'>Panel Hearing Assignment</h2>
                    <p>Hello <b>" . htmlspecialchars($m['full_name']) . "</b>,</p>
                    <p>You have been assigned as a panel member for the following UPCC hearing:</p>
                    <div style='background:#f8fafc; padding:20px; border-radius:10px; border: 1px solid #f1f5f9; margin:20px 0;'>
                        <p style='margin:5px 0;'><b>Case ID:</b> <span style='color:#1e3a8a;'>$caseLabel</span></p>
                        <p style='margin:5px 0;'><b>Student:</b> " . htmlspecialchars($case['student_name']) . "</p>
                        <p style='margin:5px 0;'><b>Hearing Schedule:</b> <span style='color:#b91c1c; font-weight: bold;'>$hearingAt</span></p>
                        <p style='margin:5px 0;'><b>Hearing Mode:</b> $hearingTypeLabel</p>
                        <p style='margin:5px 0;'><b>Location / Link:</b> $hearingLoc</p>
                    </div>
                    <p>Please log in to the UPCC Panel portal to review the case details and any submitted student explanations.</p>
                    <div style='margin: 25px 0;'>
                        <a href='$loginUrl' style='background:#1e3a8a; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold; display:inline-block;'>Go to Panel Portal</a>
                    </div>
                    <p style='margin-top:30px; font-size:12px; color:#94a3b8; border-top: 1px solid #f1f5f9; padding-top: 15px;'>This is an automated notification from IdentiTrack. Please do not reply.</p>
                </div>
            ";
            $mail->send();
            $results[] = ['email' => $m['email'], 'ok' => true];
        } catch (Exception $e) {
            $results[] = ['email' => $m['email'], 'ok' => false, 'error' => $e->getMessage()];
        }
    }

    return ['ok' => true, 'results' => $results];
}

/**
 * Periodically reminds assigned panelists who haven't accepted or declined.
 */
function upcc_process_panel_reminders(): void {
    // Look for panel assignments where the case is still pending/investigating
    // and the panelist has not accepted (not in upcc_case_panel_acceptance)
    // and we haven't sent a reminder in the last 24 hours.
    $pending = db_all("
        SELECT pm.case_id, pm.upcc_id, u.email, u.full_name, uc.created_at, uc.hearing_date, uc.hearing_time
        FROM upcc_case_panel_member pm
        JOIN upcc_case uc ON uc.case_id = pm.case_id
        JOIN upcc_user u ON u.upcc_id = pm.upcc_id
        LEFT JOIN upcc_case_panel_acceptance pa ON pa.case_id = pm.case_id AND pa.upcc_id = pm.upcc_id
        WHERE uc.status IN ('PENDING', 'UNDER_INVESTIGATION')
          AND pa.acceptance_id IS NULL
          AND u.is_active = 1
          AND (pm.last_reminder_sent_at IS NULL OR pm.last_reminder_sent_at < DATE_SUB(NOW(), INTERVAL 24 HOUR))
    ");

    if (empty($pending)) return;

    require_once __DIR__ . '/../UPCC/class.phpmailer.php';
    require_once __DIR__ . '/../UPCC/class.smtp.php';

    $loginUrl = "https://identitrack.site/UPCC/upccpanel.php";

    foreach ($pending as $row) {
        $caseLabel = 'UPCC-' . date('Y', strtotime($row['created_at'])) . '-' . str_pad((string)$row['case_id'], 3, '0', STR_PAD_LEFT);
        $hearingAt = $row['hearing_date'] ? date('M j, Y', strtotime($row['hearing_date'])) : 'TBD';
        if ($row['hearing_time']) $hearingAt .= ' at ' . date('g:i A', strtotime($row['hearing_time']));

        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.hostinger.com';
            $mail->Port = 587;
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = 'tls';
            $mail->Username = $_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site';
            $mail->Password = $_ENV['SMTP_PASS'] ?? '';
            $mail->Timeout = 30;

            $mail->setFrom($_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site', 'IdentiTrack UPCC');
            $mail->addAddress($row['email'], $row['full_name']);
            $mail->Subject = "REMINDER: Pending Panel Assignment for $caseLabel";

            $mail->isHTML(true);
            $mail->Body = "
                <div style='font-family:sans-serif; max-width:600px; line-height:1.6; color:#333; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px;'>
                    <h2 style='color:#f59e0b; margin-top: 0;'>Action Required: Pending Hearing Assignment</h2>
                    <p>Hello <b>" . htmlspecialchars($row['full_name']) . "</b>,</p>
                    <p>This is a reminder that you have been assigned to a UPCC hearing panel, but you have not yet confirmed or declined your participation.</p>
                    <div style='background:#f8fafc; padding:20px; border-radius:10px; border: 1px solid #f1f5f9; margin:20px 0;'>
                        <p style='margin:5px 0;'><b>Case ID:</b> <span style='color:#1e3a8a;'>$caseLabel</span></p>
                        <p style='margin:5px 0;'><b>Hearing Schedule:</b> <span style='color:#b91c1c; font-weight: bold;'>$hearingAt</span></p>
                    </div>
                    <p>Please log in to the UPCC Panel portal to either <b>Unlock Case Access</b> (Accept) or <b>Decline</b> the assignment.</p>
                    <div style='margin: 25px 0;'>
                        <a href='$loginUrl' style='background:#1e3a8a; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold; display:inline-block;'>Go to Panel Portal</a>
                    </div>
                    <p style='margin-top:30px; font-size:12px; color:#94a3b8; border-top: 1px solid #f1f5f9; padding-top: 15px;'>This is an automated notification from IdentiTrack. Please do not reply.</p>
                </div>
            ";
            $mail->send();

            db_exec("UPDATE upcc_case_panel_member SET last_reminder_sent_at = NOW() WHERE case_id = :c AND upcc_id = :u", [
                ':c' => $row['case_id'],
                ':u' => $row['upcc_id']
            ]);
        } catch (Exception $e) {
            error_log("Failed to send panel reminder to {$row['email']}: " . $e->getMessage());
        }
    }
}


/**
 * Notifies the panel that the student has submitted their explanation.
 */
function upcc_send_explanation_notification(int $caseId): array {
    if ($caseId <= 0) return ['ok' => false, 'error' => 'Invalid ID'];
    
    $case = db_one("SELECT uc.case_id, uc.created_at, CONCAT(s.student_fn, ' ', s.student_ln) as student_name
                    FROM upcc_case uc
                    JOIN student s ON s.student_id = uc.student_id
                    WHERE uc.case_id = :id", [':id' => $caseId]);
    if (!$case) return ['ok' => false, 'error' => 'Case not found'];
    
    $members = db_all("
        SELECT u.full_name, u.email 
        FROM upcc_user u
        JOIN upcc_case_panel_member pm ON pm.upcc_id = u.upcc_id
        WHERE pm.case_id = :cid AND u.is_active = 1
    ", [':cid' => $caseId]);
    
    if (empty($members)) return ['ok' => true, 'results' => [], 'info' => 'No panel assigned yet'];
    
    require_once __DIR__ . '/../UPCC/class.phpmailer.php';
    require_once __DIR__ . '/../UPCC/class.smtp.php';
    
    $results = [];
    $caseLabel = 'UPCC-' . date('Y', strtotime($case['created_at'])) . '-' . str_pad((string)$caseId, 3, '0', STR_PAD_LEFT);
    $loginUrl = "https://identitrack.site/UPCC/upccpanel.php";

    foreach ($members as $m) {
        try {
            $mail = new PHPMailer(true);
            $mail->CharSet = 'UTF-8';
            $mail->isSMTP();
            $mail->Host = $_ENV['SMTP_HOST'] ?? 'smtp.hostinger.com';
            $mail->Port = 587;
            $mail->SMTPAuth = true;
            $mail->SMTPSecure = 'tls';
            $mail->Username = $_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site';
            $mail->Password = $_ENV['SMTP_PASS'] ?? '';
            $mail->Timeout = 30;

            $mail->setFrom($_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site', 'IdentiTrack UPCC');
            $mail->addAddress($m['email'], $m['full_name']);
            $mail->Subject = "Student Explanation Submitted: Case $caseLabel";

            $mail->isHTML(true);
            $mail->Body = "
                <div style='font-family:sans-serif; max-width:600px; line-height:1.6; color:#333; border: 1px solid #e2e8f0; border-radius: 12px; padding: 24px;'>
                    <h2 style='color:#1e3a8a; margin-top: 0;'>New Student Explanation</h2>
                    <p>Hello <b>" . htmlspecialchars($m['full_name']) . "</b>,</p>
                    <p>The student for Case <b>$caseLabel</b> (" . htmlspecialchars($case['student_name']) . ") has submitted their formal explanation and evidence.</p>
                    <p>You can now log in to review the submission before the hearing starts.</p>
                    <div style='margin: 25px 0;'>
                        <a href='$loginUrl' style='background:#1e3a8a; color:white; padding:12px 24px; text-decoration:none; border-radius:6px; font-weight:bold; display:inline-block;'>Go to Panel Portal</a>
                    </div>
                    <p style='margin-top:30px; font-size:12px; color:#94a3b8; border-top: 1px solid #f1f5f9; padding-top: 15px;'>This is an automated notification from IdentiTrack. Please do not reply.</p>
                </div>
            ";
            $mail->send();
            $results[] = ['email' => $m['email'], 'ok' => true];
        } catch (Exception $e) {
            $results[] = ['email' => $m['email'], 'ok' => false, 'error' => $e->getMessage()];
        }
    }
    return ['ok' => true, 'results' => $results];
}

function send_student_community_service_email(string $studentId, string $taskName, string $timeIn): bool {
    $params = [':sid' => $studentId];
    db_add_encryption_key($params);
    $student = db_one(
      "SELECT " . db_decrypt_cols(['student_fn', 'student_ln', 'student_email']) . "
       FROM student
       WHERE student_id = :sid
       LIMIT 1",
      $params
    );

    if (!$student || empty($student['student_email'])) {
        return false;
    }

    $email = trim((string)$student['student_email']);
    $fullName = trim((string)$student['student_fn'] . ' ' . (string)$student['student_ln']);

    require_once __DIR__ . '/../UPCC/class.phpmailer.php';
    require_once __DIR__ . '/../UPCC/class.smtp.php';

    try {
        $mail = new PHPMailer(true);
        $mail->CharSet   = 'UTF-8';
        $mail->isSMTP();
        $mail->Host      = $_ENV['SMTP_HOST'] ?? 'smtp.hostinger.com';
        $mail->Port      = 587;
        $mail->SMTPAuth  = true;
        $mail->SMTPSecure = 'tls';
        $mail->Username  = $_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site';
        $mail->Password  = $_ENV['SMTP_PASS'] ?? '';
        $mail->Timeout   = 30;

        $mail->setFrom($_ENV['SMTP_USER'] ?? 'identitrack@identitrack.site', 'IdentiTrack Community Service');
        $mail->addAddress($email, $fullName);
        $mail->isHTML(true);

        $mail->Subject = 'Community Service Session Started';
        
        $logoPath = realpath(__DIR__ . '/../assets/logo.png');
        $cid = 'cslologo';
        if ($logoPath && is_readable($logoPath)) {
            $mail->addEmbeddedImage($logoPath, $cid, 'logo.png');
            $logoHtml = "<img src=\"cid:$cid\" width=\"42\" height=\"42\" alt=\"IdentiTrack\" style=\"display:block;border-radius:12px;\" />";
        } else {
            $logoHtml = "<div style=\"width:42px;height:42px;border-radius:12px;background:#1e3b8a;color:#fff;font-weight:800;font-size:14px;text-align:center;line-height:42px;\">IT</div>";
        }

        $formattedTime = date('F j, Y g:i A', strtotime($timeIn));

        $mail->Body = "
        <!doctype html>
        <html>
        <head><meta charset='utf-8'><meta name='viewport' content='width=device-width,initial-scale=1'></head>
        <body style='margin:0;padding:0;background:#f3f4f6;'>
          <div style='padding:24px 12px;'>
            <div style='max-width:560px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;border-radius:18px;overflow:hidden;box-shadow:0 12px 30px rgba(17,24,39,.10);font-family:Segoe UI,Tahoma,Arial,sans-serif;'>

              <div style='background:#0b1630;padding:20px 24px;'>
                <div style='display:flex;align-items:center;gap:12px;'>
                  {$logoHtml}
                  <div>
                    <div style='font-size:17px;font-weight:900;color:#e8ecf7;line-height:1.1;'>IdentiTrack</div>
                    <div style='font-size:12px;color:#7a8aac;margin-top:3px;'>Community Service System</div>
                  </div>
                </div>
              </div>

              <div style='padding:28px 24px;'>
                <p style='margin:0 0 10px;color:#111827;font-size:14px;'>Hi <b>" . htmlspecialchars($fullName) . "</b>,</p>
                <p style='margin:0 0 20px;color:#374151;font-size:14px;line-height:1.6;'>
                  Your community service session request has been <b>APPROVED</b> by SDO. The timer is now running.
                </p>

                <div style='background:#f8fafc;border:1px solid #c7d2fe;border-radius:16px;padding:20px;margin-bottom:20px;'>
                  <div style='font-size:11px;color:#64748b;letter-spacing:.12em;text-transform:uppercase;font-weight:700;margin-bottom:8px;'>Session Details</div>
                  <div style='font-size:14px;color:#1e3a8a;margin-top:6px;'><b>Task Assigned:</b> " . htmlspecialchars($taskName) . "</div>
                  <div style='font-size:14px;color:#1e3a8a;margin-top:6px;'><b>Time In:</b> {$formattedTime}</div>
                  <div style='font-size:14px;color:#16a34a;margin-top:6px;'><b>Status:</b> Active (Timer Running)</div>
                </div>

                <p style='margin:18px 0 0;color:#9ca3af;font-size:12px;'>You can view your remaining community service hours and live countdown in the IdentiTrack mobile app.</p>
              </div>

              <div style='padding:14px 24px;background:#111827;color:#6b7280;font-size:11px;text-align:center;'>
                © " . date('Y') . " IdentiTrack &bull; Automated message, do not reply.
              </div>
            </div>
          </div>
        </body>
        </html>";

        return (bool)$mail->send();
    } catch (Exception $e) {
        error_log("Failed to send community service approval email to student $studentId: " . $e->getMessage());
        return false;
    }
}

function student_account_mode(string $studentId): array
{
  $mode = 'FULL_ACCESS';
  $message = 'Account access is normal.';
  $studentId = trim($studentId);
  if ($studentId === '') {
    return ['mode' => $mode, 'message' => $message];
  }

  $row = db_one(
    "SELECT case_id, decided_category, probation_until, punishment_details, resolution_date, status
     FROM upcc_case
     WHERE student_id = :sid
       AND status IN ('CLOSED','RESOLVED','UNDER_APPEAL')
       AND decided_category IS NOT NULL
     ORDER BY resolution_date DESC, created_at DESC, case_id DESC
     LIMIT 1",
    [':sid' => $studentId]
  );

  if (!$row) {
    return ['mode' => $mode, 'message' => $message];
  }

  $appealTableExists = db_one("SHOW TABLES LIKE 'student_appeal_request'");
  $activeAppeal = null;
  if ($appealTableExists) {
    $activeAppeal = db_one(
      "SELECT appeal_id, appeal_kind, created_at
       FROM student_appeal_request
       WHERE student_id = :sid
         AND status IN ('PENDING','REVIEWING')
       ORDER BY created_at DESC, appeal_id DESC
       LIMIT 1",
      [':sid' => $studentId]
    );
  }

  if ($activeAppeal || $row['status'] === 'UNDER_APPEAL') {
    return [
      'mode' => 'APPEAL_GRACE_PERIOD',
      'message' => 'Your appeal is under review. Account restrictions are paused until the appeal is resolved.',
    ];
  }

  // Check if the student has already had an appeal for this specific case (even if rejected)
  $hasHadAppeal = false;
  if ($appealTableExists) {
    $anyAppeal = db_one(
      "SELECT status FROM student_appeal_request
       WHERE student_id = :sid AND case_id = :cid
       LIMIT 1",
      [':sid' => $studentId, ':cid' => (int)($row['case_id'] ?? 0)]
    );
    if ($anyAppeal) {
        $hasHadAppeal = true;
    }
  }

  $category = (int)($row['decided_category'] ?? 0);
  $resolutionDate = (string)($row['resolution_date'] ?? date('Y-m-d H:i:s'));
  $gracePeriodEnds = strtotime($resolutionDate) + (5 * 86400); // 5 days
  $isInGracePeriod = time() <= $gracePeriodEnds;
  $details = [];
  try {
    $details = json_decode((string)($row['punishment_details'] ?? ''), true) ?: [];
  } catch (Throwable $e) {
    $details = [];
  }

  if (!empty($details['completed'])) {
    return [
      'mode' => 'FULL_ACCESS',
      'message' => 'Sanction has been completed. Access restored.'
    ];
  }

  // For Category 2: if student already has ACTIVE/COMPLETED community service,
  // they accepted the decision — skip the grace period banner.
  $hasAcceptedViaService = false;
  if ((int)($row['decided_category'] ?? 0) === 2) {
    $csrCheck = db_one(
      "SELECT requirement_id FROM community_service_requirement
       WHERE student_id = :sid AND related_case_id = :cid
         AND status IN ('ACTIVE', 'COMPLETED')
       LIMIT 1",
      [':sid' => $studentId, ':cid' => (int)($row['case_id'] ?? 0)]
    );
    $hasAcceptedViaService = !empty($csrCheck);
  }

  // AUTO-RESOLVE IF GRACE PERIOD EXPIRED WITHOUT ACTION
  if ($row['status'] === 'CLOSED' && !$isInGracePeriod && !$hasHadAppeal && !$hasAcceptedViaService) {
      $caseId = (int)$row['case_id'];

      // 1. Update case status to RESOLVED
      db_exec("UPDATE upcc_case SET status = 'RESOLVED', updated_at = NOW() WHERE case_id = :cid", [':cid' => $caseId]);

      // 2. If Category 2, activate the pending community service requirement
      if ($category === 2) {
          db_exec(
              "UPDATE community_service_requirement 
               SET status = 'ACTIVE' 
               WHERE student_id = :sid AND related_case_id = :cid AND status = 'PENDING_ACCEPTANCE'",
              [':sid' => $studentId, ':cid' => $caseId]
          );
      }

      // 3. If Category 4 or 5, freeze/deactivate the student
      if ($category === 4 || $category === 5) {
          db_exec("UPDATE student SET is_active = 0, updated_at = NOW() WHERE student_id = :sid", [':sid' => $studentId]);
      }

      // 4. Log case activity
      upcc_log_case_activity($caseId, 'SYSTEM', 0, 'AUTO_RESOLVED_WINDOW_EXPIRED', [
          'student_id' => $studentId,
          'notes' => 'Student failed to accept or appeal within the grace period. Punishment automatically applied.'
      ]);

      // 5. Notify the admin about the student's lack of action/responsibility
      $studentName = 'Student';
      try {
          $decrypted_student = db_decrypt_cols(['student_fn', 'student_ln']);
          $params = [':sid' => $studentId];
          db_add_encryption_key($params);
          $sInfo = db_one("SELECT $decrypted_student FROM student WHERE student_id = :sid LIMIT 1", $params);
          if ($sInfo) {
              $studentName = trim(((string)$sInfo['student_fn'] . ' ' . (string)$sInfo['student_ln']));
          }
      } catch (Throwable $e) {}

      db_exec(
          "INSERT INTO notification (type, title, message, student_id, admin_id, related_table, related_id, is_read, is_deleted, created_at)
           VALUES ('UPCC_AUTO_RESOLVE', 'Case Auto-Resolved (No Action)', :msg, :sid, NULL, 'upcc_case', :cid, 0, 0, NOW())",
          [
              ':msg' => "Student {$studentName} ({$studentId}) failed to accept or appeal Case #{$caseId} within the grace period. The punishment was automatically applied.",
              ':sid' => $studentId,
              ':cid' => (string)$caseId
          ]
      );

      // Reload the row to reflect the new RESOLVED status
      $row = db_one(
        "SELECT case_id, decided_category, probation_until, punishment_details, resolution_date, status
         FROM upcc_case
         WHERE case_id = :cid
         LIMIT 1",
        [':cid' => $caseId]
      );

      if ($row) {
          $category = (int)($row['decided_category'] ?? 0);
          $resolutionDate = (string)($row['resolution_date'] ?? date('Y-m-d H:i:s'));
          $gracePeriodEnds = strtotime($resolutionDate) + (5 * 86400);
          $isInGracePeriod = time() <= $gracePeriodEnds;
      }
  }

  if ($row['status'] === 'CLOSED' && $isInGracePeriod && !$hasHadAppeal && !$hasAcceptedViaService) {
    $daysLeft = max(0, ceil(($gracePeriodEnds - time()) / 86400));
    return [
      'mode' => 'APPEAL_GRACE_PERIOD',
      'message' => "You have {$daysLeft} day(s) left to accept or appeal your Category {$category} UPCC decision.",
    ];
  }

  if ($category === 1) {
    $until = (string)($row['probation_until'] ?? '');
    $semester = trim((string)($details['semester'] ?? ''));
    if ($until !== '' && strtotime($until) > time()) {
      $suffix = $semester !== '' ? ' Suspension term: ' . $semester . '.' : '';
      return [
        'mode' => 'PROBATION_FREEZE',
        'message' => 'Category 1 probation is active until ' . date('M d, Y', strtotime($until)) . '.' . $suffix,
      ];
    }
    return ['mode' => 'FULL_ACCESS', 'message' => 'Probation period completed. Access restored.'];
  }

  if ($category === 2) {
    // Check if the student still has active service requirements for this case
    $activeReq = db_one(
        "SELECT 1 FROM community_service_requirement 
         WHERE student_id = :sid AND related_case_id = :cid AND status = 'ACTIVE' LIMIT 1",
        [':sid' => $studentId, ':cid' => (int)($row['case_id'] ?? 0)]
    );

    // Only congratulate if there's a COMPLETED requirement (officially done)
    $completedReq = db_one(
        "SELECT 1 FROM community_service_requirement 
         WHERE student_id = :sid AND status = 'COMPLETED' LIMIT 1",
        [':sid' => $studentId]
    );
    if ($completedReq) {
      return [
        'mode' => 'FULL_ACCESS',
        'message' => 'You have completed your community service requirement.',
      ];
    }

    $interventions = [];
    if (!empty($details['interventions']) && is_array($details['interventions'])) {
      $interventions = array_map('strval', $details['interventions']);
    }
    $serviceHours = (int)($details['service_hours'] ?? 0);
    $messageParts = ['Complete your formative intervention requirements.'];
    if ($interventions) {
      $messageParts[] = 'Selected interventions: ' . implode(', ', $interventions) . '.';
    }
    if ($serviceHours > 0 && in_array('University Service', $interventions, true)) {
      $messageParts[] = 'University service hours required: ' . $serviceHours . '.';
    }
    return [
      'mode' => 'SERVICE_TRACKING',
      'message' => implode(' ', $messageParts),
    ];
  }

  // Categories 3, 4, 5
  if ($category >= 3 && $category <= 5) {
      // If the case is already RESOLVED, it means the student accepted it and the grace period is waived.
      if ($isInGracePeriod && $row['status'] !== 'RESOLVED') {
          $daysLeft = ceil(($gracePeriodEnds - time()) / 86400);
          $warning = $category === 3 ? "this decision becomes permanent." : "your account is fully frozen.";
          return [
              'mode' => 'APPEAL_GRACE_PERIOD',
              'message' => "You have {$daysLeft} day(s) left to appeal this decision before {$warning}",
          ];
      }
      
      if ($category === 3) {
        return [
          'mode' => 'WARNING_NO_FREEZE',
          'message' => 'Category 3 Non-Readmission: You are allowed to finish the current term, but your account will be frozen for the next semester.',
        ];
      }
      if ($category === 4) {
        return [
          'mode' => 'WARNING_FREEZE_LOGOUT_ONLY',
          'message' => 'Account Frozen: Category 4 - Exclusion. You have been removed from the university rolls. Please log out now.',
        ];
      }
      if ($category === 5) {
        return [
          'mode' => 'AUTO_LOGOUT_FREEZE',
          'message' => 'Account Frozen: Category 5 - Expulsion. You are disqualified from all HEIs in the Philippines. The app will log you out automatically.',
        ];
      }
  }

  return ['mode' => $mode, 'message' => $message];
}

/**
 * Checks if a community service requirement has reached its required hours
 * and marks it as COMPLETED if so.
 */
function check_requirement_completion(int $requirementId): bool {
    $req = db_one("SELECT hours_required, student_id, task_name, status FROM community_service_requirement WHERE requirement_id = :id", [':id' => $requirementId]);
    if (!$req || $req['status'] === 'COMPLETED') return false;

    // We only count validated sessions (or all sessions if we trust the logout)
    // For now, let's count all sessions that have a time_out.
    $totalSeconds = (int)(db_one("
        SELECT SUM(TIMESTAMPDIFF(SECOND, time_in, time_out)) as total 
        FROM community_service_session 
        WHERE requirement_id = :id AND time_out IS NOT NULL
    ", [':id' => $requirementId])['total'] ?? 0);

    $totalHours = $totalSeconds / 3600.0;

    if ($totalHours >= ((float)$req['hours_required'] - 0.0001)) {
        db_exec("UPDATE community_service_requirement SET status = 'COMPLETED', completed_at = NOW() WHERE requirement_id = :id", [':id' => $requirementId]);
        
        // Notify student
        db_exec("
            INSERT INTO notification (type, title, message, student_id, admin_id, related_table, related_id, is_read, is_deleted, created_at)
            VALUES ('COMMUNITY_COMPLETE', 'Service Completed!', :msg, :sid, 0, 'community_service_requirement', :rid, 0, 0, NOW())
        ", [
            ':msg' => "Congratulations! You have completed the required hours for your community service",
            ':sid' => $req['student_id'],
            ':rid' => (string)$requirementId
        ]);

        // If this was tied to a case, maybe we should check if the case is now fully resolved?
        // Usually, one case = one requirement.
        
        return true;
    }
    return false;
}

function auto_complete_all_active_sessions(): void {
    // Get all active sessions
    $activeSessions = db_all("
        SELECT css.session_id, css.requirement_id, css.time_in, csr.hours_required, csr.student_id
        FROM community_service_session css
        JOIN community_service_requirement csr ON csr.requirement_id = css.requirement_id
        WHERE css.time_out IS NULL
    ");

    foreach ($activeSessions as $session) {
        $requirementId = (int)$session['requirement_id'];
        $hoursRequired = (float)$session['hours_required'];
        $timeIn = $session['time_in'];
        $studentId = $session['student_id'];
        
        // Sum completed hours from previous sessions
        $prevSeconds = (int)(db_one("
            SELECT SUM(TIMESTAMPDIFF(SECOND, time_in, time_out)) as total 
            FROM community_service_session 
            WHERE requirement_id = :id AND time_out IS NOT NULL
        ", [':id' => $requirementId])['total'] ?? 0);
        $prevHours = $prevSeconds / 3600.0;
        
        $remainingHours = $hoursRequired - $prevHours;
        if ($remainingHours <= 0.0001) {
            db_exec("
                UPDATE community_service_session 
                SET time_out = :time_in, logout_method = 'AUTO_COMPLETE'
                WHERE session_id = :sid
            ", [':time_in' => $timeIn, ':sid' => $session['session_id']]);
            check_requirement_completion($requirementId);
            continue;
        }

        // Calculate elapsed seconds in active session using PHP time (timezone-safe)
        $elapsedSeconds = time() - strtotime($timeIn);
        $elapsedHours = $elapsedSeconds / 3600.0;

        if ($elapsedHours >= ($remainingHours - 0.0001)) {
            // Completed during this session!
            $neededSeconds = max(0, (int)round($remainingHours * 3600.0));
            db_exec("
                UPDATE community_service_session 
                SET time_out = DATE_ADD(time_in, INTERVAL :needed SECOND), logout_method = 'AUTO_COMPLETE'
                WHERE session_id = :sid
            ", [':needed' => $neededSeconds, ':sid' => $session['session_id']]);
            
            check_requirement_completion($requirementId);
        }
    }
}

if (!function_exists('scanner_hash_value')) {
  function scanner_hash_value(string $rawValue): string
  {
    $pepper = 'IDENTITRACK_SCANNER_PEPPER_V1_CHANGE_ME';
    $normalized = strtoupper(trim($rawValue));
    return hash('sha256', $pepper . ':' . $normalized);
  }
}
?>