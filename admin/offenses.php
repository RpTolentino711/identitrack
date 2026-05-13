<?php
// File: admin/offenses.php
require_once __DIR__ . '/../database/database.php';
require_admin();

$activeSidebar = 'offenses';

$admin    = admin_current();
$fullName = trim((string)($admin['full_name'] ?? ''));
if ($fullName === '') $fullName = (string)($admin['username'] ?? 'User');

$q      = trim((string)($_GET['q']      ?? ''));
$filter = (string)($_GET['filter'] ?? 'all');
if (!in_array($filter, ['all', 'minor', 'major'], true)) $filter = 'all';

$selectedMonth = trim((string)($_GET['month'] ?? ''));

// Get all unique months from offenses to populate filter
$availableMonths = db_all("SELECT DISTINCT DATE_FORMAT(date_committed, '%Y-%m') as m FROM offense ORDER BY m DESC");


function scanner_hash_value(string $rawValue): string {
  // Keep this aligned with scanner hashing used in student-side lookup.
  $pepper = 'IDENTITRACK_SCANNER_PEPPER_V1_CHANGE_ME';
  $normalized = strtoupper(trim($rawValue));
  return hash('sha256', $pepper . ':' . $normalized);
}

function student_has_scanner_hash_column(): bool {
  static $hasColumn = null;
  if ($hasColumn !== null) return $hasColumn;

  $row = db_one(
    "SELECT 1 AS ok
     FROM information_schema.columns
     WHERE table_schema = DATABASE()
       AND table_name = 'student'
       AND column_name = 'scanner_id_hash'
     LIMIT 1"
  );

  $hasColumn = (bool)$row;
  return $hasColumn;
}

function find_student_by_scan_input(string $input): ?array {
  $input = trim($input);
  if ($input === '') return null;

  $student = db_one(
    "SELECT student_id
     FROM student
     WHERE student_id = :sid
     LIMIT 1",
    [':sid' => $input]
  );
  if ($student) return $student;

  if (!student_has_scanner_hash_column()) return null;

  return db_one(
    "SELECT student_id
     FROM student
     WHERE scanner_id_hash = :scanner_hash
     LIMIT 1",
    [':scanner_hash' => scanner_hash_value($input)]
  );
}

function count_pending_guard_reports_for_student(string $studentId): int {
  $row = db_one(
    "SELECT COUNT(*) AS cnt
     FROM guard_violation_report
     WHERE student_id = :sid
       AND status = 'PENDING'
       AND is_deleted = 0",
    [':sid' => $studentId]
  ) ?: [];

  return (int)($row['cnt'] ?? 0);
}

$scanInput = trim((string)($_GET['scan'] ?? ''));
if ($scanInput !== '') {
  $matchedStudent = find_student_by_scan_input($scanInput);
  if ($matchedStudent) {
    $resolvedStudentId = (string)$matchedStudent['student_id'];
    $pendingCount = count_pending_guard_reports_for_student($resolvedStudentId);
    $scanMsg = $pendingCount > 0 ? 'pending_guard_found' : 'no_offense_record';
    redirect('offenses_student_view.php?student_id=' . urlencode($resolvedStudentId) . '&scan_msg=' . urlencode($scanMsg));
  }

  redirect('offenses.php?filter=' . urlencode($filter) . '&scan_msg=' . urlencode('student_not_found'));
}

$scanFlashKey = trim((string)($_GET['scan_msg'] ?? ''));
$scanFlash = '';
if ($scanFlashKey === 'student_not_found') {
  $scanFlash = 'No student match found for the scanned ID.';
}

// ── Helper ──────────────────────────────────────────────────────────────────
function initials(string $fn, string $ln): string {
  $a = strtoupper(substr(trim($fn), 0, 1));
  $b = strtoupper(substr(trim($ln), 0, 1));
  return ($a ?: '?') . ($b ?: '?');
}

// ── Stats (Dynamic based on filters) ────────────────────────────────────────
$statsWhereParts = [];
$statsParams     = [];

if ($q !== '') {
  $decFn = db_decrypt_col('student_fn', 's');
  $decLn = db_decrypt_col('student_ln', 's');
  $statsWhereParts[] = "student_id IN (
    SELECT student_id FROM student s
    WHERE (s.student_id LIKE :q1 OR $decFn LIKE :q2 OR $decLn LIKE :q3
           OR CONCAT($decFn,' ',$decLn) LIKE :q4
           OR CONCAT($decLn,', ',$decFn) LIKE :q5)
  )";
  $like = '%' . $q . '%';
  $statsParams[':q1'] = $q . '%';
  $statsParams[':q2'] = $like;
  $statsParams[':q3'] = $like;
  $statsParams[':q4'] = $like;
  $statsParams[':q5'] = $like;
  db_add_encryption_key($statsParams);
}

if ($selectedMonth !== '') {
  $statsWhereParts[] = "DATE_FORMAT(date_committed, '%Y-%m') = :month";
  $statsParams[':month'] = $selectedMonth;
  db_add_encryption_key($statsParams);
}

$statsWhere = !empty($statsWhereParts) ? 'WHERE ' . implode(' AND ', $statsWhereParts) : '';

$totalRow = db_one("SELECT COUNT(*) AS cnt FROM offense $statsWhere", $statsParams) ?: [];
$totalCount = (int)($totalRow['cnt'] ?? 0);

$minorWhere = $statsWhere . ($statsWhere ? " AND " : " WHERE ") . "level = 'MINOR'";
$minorRow = db_one("SELECT COUNT(*) AS cnt FROM offense $minorWhere", $statsParams) ?: [];
$minorCount = (int)($minorRow['cnt'] ?? 0);

$majorWhere = $statsWhere . ($statsWhere ? " AND " : " WHERE ") . "level = 'MAJOR'";
$majorRow = db_one("SELECT COUNT(*) AS cnt FROM offense $majorWhere", $statsParams) ?: [];
$rawMajorCount = (int)($majorRow['cnt'] ?? 0);

// Students with 3+ minors are considered to have a major offense
$sec4Row = db_one(
  "SELECT COUNT(*) AS cnt FROM (
     SELECT student_id FROM offense $minorWhere
     GROUP BY student_id HAVING COUNT(*) >= 3
   ) AS sec4",
  $statsParams
) ?: [];
$section4Count = (int)($sec4Row['cnt'] ?? 0);
$majorCount    = $rawMajorCount + $section4Count;

// ── Student list ─────────────────────────────────────────────────────────────
$whereParts = [];
$params     = [];

if ($q !== '') {
  $params[':q'] = "%$q%";
  db_add_encryption_key($params);
  
  $decFn = db_decrypt_col('student_fn', 's');
  $decLn = db_decrypt_col('student_ln', 's');
  
  $whereParts[] = "(
    s.student_id LIKE :q OR 
    $decFn LIKE :q OR 
    $decLn LIKE :q OR 
    CONCAT($decFn, ' ', $decLn) LIKE :q
  )";
}

if ($filter === 'minor') {
  $whereParts[] = "EXISTS (
    SELECT 1 FROM offense o2
    WHERE o2.student_id = s.student_id AND o2.level = 'MINOR'
  )";
} elseif ($filter === 'major') {
  $whereParts[] = "(
    EXISTS (
      SELECT 1 FROM offense o2
      WHERE o2.student_id = s.student_id AND o2.level = 'MAJOR'
    )
    OR (
      SELECT COUNT(*) FROM offense o3
      WHERE o3.student_id = s.student_id AND o3.level = 'MINOR'
    ) >= 3
  )";
}

if ($selectedMonth !== '') {
  $whereParts[] = "EXISTS (
    SELECT 1 FROM offense o4
    WHERE o4.student_id = s.student_id
      AND DATE_FORMAT(o4.date_committed, '%Y-%m') = :month
  )";
  $params[':month'] = $selectedMonth;
  db_add_encryption_key($params);
}

// Only show students who have at least one offense
$whereParts[] = "EXISTS (
  SELECT 1 FROM offense ox WHERE ox.student_id = s.student_id
)";

$where = !empty($whereParts)
  ? 'WHERE ' . implode(' AND ', $whereParts)
  : '';

$sql = "
  SELECT
    s.student_id,
    " . db_decrypt_cols(['student_fn', 'student_ln'], 's') . ",
    s.year_level,
    s.program,
    s.school,
    s.section,

    COALESCE(COUNT(o.offense_id), 0)                                            AS total_offenses,
    COALESCE(SUM(CASE WHEN o.level = 'MINOR' THEN 1 ELSE 0 END), 0)            AS minor_offenses,
    COALESCE(SUM(CASE WHEN o.level = 'MAJOR' THEN 1 ELSE 0 END), 0)            AS major_offenses_explicit,

    CASE
      WHEN COALESCE(SUM(CASE WHEN o.level = 'MINOR' THEN 1 ELSE 0 END), 0) >= 3
      THEN 1 ELSE 0
    END AS has_escalated_major,  -- internal flag, not displayed

    MAX(o.date_committed) AS last_offense_date,

    -- Latest offense details
    (SELECT ot2.name FROM offense o2
     JOIN offense_type ot2 ON ot2.offense_type_id = o2.offense_type_id
     WHERE o2.student_id = s.student_id
     ORDER BY o2.date_committed DESC LIMIT 1)  AS last_offense_name,

    (SELECT ot2.code FROM offense o2
     JOIN offense_type ot2 ON ot2.offense_type_id = o2.offense_type_id
     WHERE o2.student_id = s.student_id
     ORDER BY o2.date_committed DESC LIMIT 1)  AS last_offense_code,

    (SELECT o2.level FROM offense o2
     WHERE o2.student_id = s.student_id
     ORDER BY o2.date_committed DESC LIMIT 1)  AS last_offense_level,

    (SELECT " . db_decrypt_col('description', 'o2') . " FROM offense o2
     WHERE o2.student_id = s.student_id
     ORDER BY o2.date_committed DESC LIMIT 1)  AS last_description,
     
     -- Debug: Is decryption actually working?
     (SELECT CASE WHEN " . db_decrypt_col('student_fn', 's') . " IS NULL THEN 1 ELSE 0 END) AS _dec_fail

  FROM student s
  LEFT JOIN offense o ON o.student_id = s.student_id

  $where

  GROUP BY s.student_id

  ORDER BY last_offense_date DESC, s.student_ln ASC, s.student_fn ASC
";

db_add_encryption_key($params);
$students = db_all($sql, $params) ?: [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1"/>
  <title>Offense Management | SDO Web Portal</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Sora:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet"/>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html, body { height: 100%; }
    body {
      font-family: 'Sora', sans-serif;
      background: #f1f5f9;
      color: #0f172a;
      font-size: 14px;
      line-height: 1.6;
      font-weight: 400;
    }

    :root {
      --blue:       #1d4ed8;
      --blue-h:     #1e40af;
      --blue-soft:  #eff6ff;
      --blue-mid:   #dbeafe;
      --red:        #dc2626;
      --red-soft:   #fef2f2;
      --red-mid:    #fee2e2;
      --amber:      #b45309;
      --amber-soft: #fffbeb;
      --amber-mid:  #fde68a;
      --green:      #15803d;
      --green-soft: #f0fdf4;
      --green-mid:  #bbf7d0;
      --navy:       #0a1628;
      --border:     #e2e8f0;
      --border-mid: #cbd5e1;
      --bg:         #f1f5f9;
      --surface:    #ffffff;
      --surface-2:  #f8fafc;
      --text-1:     #0f172a;
      --text-2:     #334155;
      --text-3:     #64748b;
      --text-4:     #94a3b8;
      --radius:     14px;
      --radius-sm:  8px;
      --shadow:     0 4px 16px rgba(15,27,61,.08), 0 2px 6px rgba(15,27,61,.05);
      --shadow-sm:  0 1px 3px rgba(15,27,61,.06);
    }

    /* ── SHELL ── */
    .admin-shell {
      min-height: calc(100vh - 72px);
      display: grid;
      grid-template-columns: 240px 1fr;
    }
    .main-wrap { min-height: 100%; display: flex; flex-direction: column; }

    /* ── PAGE HEADER ── */
    .page-header {
      background: var(--surface);
      border-bottom: 1px solid var(--border);
      padding: 16px 32px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 16px;
      position: sticky;
      top: 0;
      z-index: 10;
    }
    .page-header-left { display: flex; flex-direction: column; gap: 1px; }
    .page-title { font-size: 17px; font-weight: 700; letter-spacing: -.3px; }
    .page-welcome { font-size: 12px; color: var(--text-4); font-weight: 500; }

    .btn-register {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      background: linear-gradient(135deg, var(--blue) 0%, #2563eb 100%);
      color: #fff;
      padding: 10px 18px;
      border-radius: var(--radius-sm);
      text-decoration: none;
      font-weight: 600;
      font-size: 13.5px;
      white-space: nowrap;
      flex-shrink: 0;
      box-shadow: 0 2px 8px rgba(29,78,216,.3);
      transition: all .18s;
    }
    .btn-register svg { width: 15px; height: 15px; }
    .btn-register:hover {
      background: linear-gradient(135deg, var(--blue-h) 0%, var(--blue) 100%);
      box-shadow: 0 4px 16px rgba(29,78,216,.4);
      transform: translateY(-1px);
    }

    /* ── CONTENT ── */
    .content-area { padding: 24px 32px; flex: 1; }

    /* ── STATS ── */
    .stats {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 14px;
      margin-bottom: 22px;
    }
    .stat {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      padding: 20px 22px;
      box-shadow: var(--shadow-sm);
      position: relative;
      overflow: hidden;
      transition: box-shadow .15s;
    }
    .stat:hover { box-shadow: var(--shadow); }
    .stat::before {
      content: '';
      position: absolute;
      left: 0; top: 0; bottom: 0;
      width: 4px;
      border-radius: var(--radius) 0 0 var(--radius);
    }
    .stat.total::before { background: var(--blue); }
    .stat.minor::before { background: var(--amber); }
    .stat.major::before { background: var(--red); }

    .stat-icon {
      width: 38px; height: 38px;
      border-radius: 10px;
      display: grid;
      place-items: center;
      margin-bottom: 14px;
    }
    .stat-icon svg { width: 18px; height: 18px; }
    .stat.total .stat-icon { background: var(--blue-soft); color: var(--blue); }
    .stat.minor .stat-icon { background: var(--amber-soft); color: var(--amber); }
    .stat.major .stat-icon { background: var(--red-soft); color: var(--red); }

    .stat-val {
      font-size: 32px;
      font-weight: 700;
      letter-spacing: -1.5px;
      line-height: 1;
      margin-bottom: 5px;
    }
    .stat.total .stat-val { color: var(--text-1); }
    .stat.minor .stat-val { color: var(--amber); }
    .stat.major .stat-val { color: var(--red); }

    .stat-lbl {
      font-size: 11px;
      font-weight: 700;
      color: var(--text-4);
      text-transform: uppercase;
      letter-spacing: .6px;
    }

    /* ── MAIN BODY ── */
    .main-body {
      display: grid;
      grid-template-columns: 1fr 340px;
      gap: 20px;
      align-items: start;
    }

    /* ── LEFT PANEL ── */
    .left-panel {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
    }
    .left-panel-header {
      padding: 18px 22px 16px;
      border-bottom: 1px solid var(--border);
      background: linear-gradient(180deg, #fafcff 0%, var(--surface) 100%);
    }
    .left-panel-title { font-size: 15px; font-weight: 600; letter-spacing: -.2px; }
    .left-panel-sub   { font-size: 12px; color: var(--text-4); margin-top: 2px; font-weight: 500; }
    .left-panel-body  { padding: 18px 22px; }

    /* ── SEARCH ── */
    .search-wrap { position: relative; margin-bottom: 14px; }
    .search-controls { display: flex; align-items: center; gap: 8px; }
    .search-input-wrap { position: relative; flex: 1; }
    .search-icon {
      position: absolute;
      left: 13px; top: 50%;
      transform: translateY(-50%);
      color: var(--text-4);
      pointer-events: none;
      display: flex;
    }
    .search-icon svg { width: 15px; height: 15px; }
    .search-input {
      width: 100%;
      height: 42px;
      border: 1.5px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 0 38px 0 40px;
      font-size: 13.5px;
      font-family: 'Sora', sans-serif;
      color: var(--text-1);
      background: var(--surface-2);
      outline: none;
      transition: border-color .18s, box-shadow .18s, background .18s;
    }
    .search-input:focus {
      border-color: var(--blue);
      background: var(--surface);
      box-shadow: 0 0 0 3px rgba(29,78,216,.1);
    }
    .search-clear {
      position: absolute;
      right: 11px; top: 50%;
      transform: translateY(-50%);
      background: none; border: none;
      cursor: pointer; color: var(--text-4);
      padding: 2px; display: flex; align-items: center;
      border-radius: 4px;
      transition: color .15s;
    }
    .search-clear:hover { color: var(--text-2); }
    .search-clear svg { width: 14px; height: 14px; }

    .search-btn {
      height: 42px;
      border: none;
      border-radius: var(--radius-sm);
      background: var(--blue);
      color: #fff;
      padding: 0 16px;
      font-size: 13px;
      font-weight: 600;
      font-family: 'Sora', sans-serif;
      cursor: pointer;
      white-space: nowrap;
      transition: background .18s;
    }
    .search-btn:hover { background: var(--blue-h); }

    /* ── AJAX DROPDOWN ── */
    .ajax-results {
      display: none;
      position: absolute;
      left: 0; right: 0;
      top: calc(42px + 6px);
      z-index: 50;
      background: var(--surface);
      border: 1.5px solid var(--border);
      border-radius: var(--radius);
      box-shadow: 0 12px 40px rgba(15,27,61,.14);
      overflow: hidden;
      max-height: 420px;
      overflow-y: auto;
    }
    .ajax-results.open { display: block; }
    .ajax-results::-webkit-scrollbar { width: 5px; }
    .ajax-results::-webkit-scrollbar-thumb { background: var(--border-mid); border-radius: 3px; }

    .ajax-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      padding: 11px 14px;
      cursor: pointer;
      border-bottom: 1px solid var(--border);
      transition: background .12s;
    }
    .ajax-item:last-child { border-bottom: none; }
    .ajax-item:hover, .ajax-item.active { background: var(--blue-soft); }

    .ajax-left { display: flex; align-items: center; gap: 10px; }
    .ajax-avatar {
      width: 34px; height: 34px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--navy) 0%, #1e3a6e 100%);
      color: #fff;
      display: grid;
      place-items: center;
      font-weight: 600;
      font-size: 11px;
      flex-shrink: 0;
      letter-spacing: -.5px;
    }
    .ajax-name { font-weight: 700; font-size: 13.5px; color: var(--text-1); }
    .ajax-sid  { font-size: 11px; color: var(--text-4); margin-top: 1px; font-family: 'JetBrains Mono', monospace; }
    .ajax-right { font-size: 11.5px; color: var(--text-3); text-align: right; line-height: 1.6; }
    .ajax-right .minor { color: var(--amber); font-weight: 700; }
    .ajax-right .major { color: var(--red);   font-weight: 700; }

    /* ── FILTERS ── */
    .filters { display: flex; gap: 6px; margin-bottom: 16px; }
    .filter-btn {
      padding: 6px 14px;
      border-radius: var(--radius-sm);
      border: 1.5px solid var(--border);
      background: var(--surface);
      color: var(--text-3);
      font-weight: 700;
      font-size: 12px;
      text-decoration: none;
      cursor: pointer;
      transition: all .15s;
      font-family: 'Sora', sans-serif;
    }
    .filter-btn:hover { border-color: var(--blue); color: var(--blue); background: var(--blue-soft); }
    .filter-btn.active {
      background: var(--blue);
      border-color: var(--blue);
      color: #fff;
      box-shadow: 0 2px 8px rgba(29,78,216,.25);
    }
    .filter-btn.active-minor {
      background: var(--amber);
      border-color: var(--amber);
      box-shadow: 0 2px 8px rgba(180,83,9,.25);
    }
    .filter-btn.active-major {
      background: var(--red);
      border-color: var(--red);
      box-shadow: 0 2px 8px rgba(220,38,38,.25);
    }

    /* ── STUDENT CARDS ── */
    .student-list { display: flex; flex-direction: column; gap: 10px; }

    .student-card {
      border: 1.5px solid var(--border);
      border-radius: var(--radius);
      padding: 15px 16px;
      background: var(--surface);
      transition: all .2s cubic-bezier(.4,0,.2,1);
      cursor: default;
      position: relative;
      overflow: hidden;
    }
    .student-card::before {
      content: '';
      position: absolute;
      left: 0; top: 0; bottom: 0;
      width: 3px;
      background: transparent;
      transition: background .2s;
    }
    .student-card:hover { border-color: var(--border-mid); box-shadow: var(--shadow); transform: translateY(-1px); }
    .student-card:hover::before { background: var(--blue); }
    .student-card.selected { border-color: var(--blue); background: var(--blue-soft); }
    .student-card.selected::before { background: var(--blue); }

    .card-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 10px;
    }
    .card-left { display: flex; align-items: center; gap: 11px; flex: 1; min-width: 0; }

    .avatar {
      width: 42px; height: 42px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--navy) 0%, #1e3a6e 100%);
      color: #fff;
      display: grid;
      place-items: center;
      font-weight: 800;
      font-size: 13px;
      flex-shrink: 0;
      letter-spacing: -.5px;
    }
    .student-name { font-weight: 600; font-size: 14px; color: var(--text-1); letter-spacing: -.1px; }
    .student-sid  {
      font-size: 11px;
      color: var(--text-4);
      margin-top: 2px;
      font-family: 'JetBrains Mono', monospace;
    }

    .btn-view {
      padding: 7px 14px;
      border-radius: var(--radius-sm);
      border: 1.5px solid var(--border);
      background: var(--surface);
      color: var(--text-2);
      font-weight: 600;
      font-size: 12.5px;
      text-decoration: none;
      white-space: nowrap;
      transition: all .15s;
      cursor: pointer;
      font-family: 'Sora', sans-serif;
      flex-shrink: 0;
    }
    .btn-view:hover { border-color: var(--blue); color: var(--blue); background: var(--blue-soft); }

    .card-meta {
      display: flex;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
      font-size: 12.5px;
      color: var(--text-3);
      padding-top: 10px;
      border-top: 1px solid #f1f5f9;
    }
    .meta-badge {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      padding: 2px 8px;
      border-radius: 6px;
      font-size: 11px;
      font-weight: 600;
    }
    .meta-total  { background: var(--surface-2); color: var(--text-2); border: 1px solid var(--border); }
    .meta-minor  { background: var(--amber-soft); color: var(--amber); border: 1px solid var(--amber-mid); }
    .meta-major  { background: var(--red-soft); color: var(--red); border: 1px solid var(--red-mid); }

    .card-last {
      margin-left: auto;
      font-size: 11px;
      color: var(--text-4);
      font-weight: 500;
      display: flex;
      align-items: center;
      gap: 4px;
      flex-shrink: 0;
    }
    .card-last svg { width: 11px; height: 11px; }

    .empty {
      padding: 56px 20px;
      text-align: center;
      color: var(--text-4);
    }
    .empty-icon {
      width: 52px; height: 52px;
      border-radius: 14px;
      background: var(--surface-2);
      border: 1px solid var(--border);
      display: grid;
      place-items: center;
      margin: 0 auto 14px;
    }
    .empty-icon svg { width: 24px; height: 24px; }
    .empty h3 { font-size: 14px; font-weight: 700; color: var(--text-2); margin-bottom: 4px; }
    .empty p  { font-size: 13px; color: var(--text-4); font-weight: 500; }

    /* ── DETAIL PANEL (RIGHT) ── */
    .detail-panel {
      background: var(--surface);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
      overflow: hidden;
      position: sticky;
      top: 90px;
      display: none;
      max-height: calc(100vh - 120px);
      overflow-y: auto;
    }
    .detail-panel::-webkit-scrollbar { width: 5px; }
    .detail-panel::-webkit-scrollbar-thumb { background: var(--border-mid); border-radius: 3px; }
    .detail-panel.visible { display: block; }

    .detail-panel-header {
      padding: 16px 18px;
      border-bottom: 1px solid var(--border);
      display: flex;
      align-items: center;
      justify-content: space-between;
      background: linear-gradient(180deg, #fafcff 0%, var(--surface) 100%);
    }
    .detail-panel-title { font-size: 14px; font-weight: 600; }
    .detail-close {
      background: none; border: none; cursor: pointer;
      color: var(--text-4); padding: 4px;
      display: flex; align-items: center;
      border-radius: 6px; transition: color .15s;
    }
    .detail-close:hover { color: var(--text-2); }
    .detail-close svg { width: 16px; height: 16px; }

    /* Profile hero */
    .detail-hero {
      padding: 24px 18px 18px;
      text-align: center;
      background: linear-gradient(160deg, #eef4ff 0%, #f5f8ff 50%, #fafcff 100%);
      border-bottom: 1px solid var(--border);
    }
    .detail-avatar {
      width: 72px; height: 72px;
      border-radius: 50%;
      background: linear-gradient(135deg, var(--navy) 0%, #1e3a6e 100%);
      color: #fff;
      display: grid;
      place-items: center;
      font-size: 22px;
      font-weight: 800;
      margin: 0 auto 12px;
      box-shadow: 0 0 0 3px #fff, 0 4px 12px rgba(15,27,61,.15);
      letter-spacing: -.5px;
    }
    .detail-name { font-size: 16px; font-weight: 700; letter-spacing: -.3px; }
    .detail-sid  {
      font-size: 11px;
      color: var(--text-4);
      margin-top: 4px;
      font-family: 'JetBrains Mono', monospace;
    }
    .detail-year {
      display: inline-block;
      margin-top: 8px;
      font-size: 11px;
      font-weight: 700;
      color: var(--blue);
      background: var(--blue-soft);
      border: 1px solid var(--blue-mid);
      padding: 3px 10px;
      border-radius: 999px;
    }

    /* Stats row */
    .detail-stats {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      border-bottom: 1px solid var(--border);
    }
    .detail-stat {
      padding: 13px 8px;
      text-align: center;
      border-right: 1px solid var(--border);
    }
    .detail-stat:last-child { border-right: none; }
    .detail-stat-val {
      font-size: 22px;
      font-weight: 700;
      letter-spacing: -1px;
      line-height: 1;
    }
    .detail-stat-lbl {
      font-size: 9.5px;
      font-weight: 600;
      color: var(--text-4);
      text-transform: uppercase;
      letter-spacing: .5px;
      margin-top: 4px;
    }
    .dsv-total { color: var(--text-1); }
    .dsv-minor { color: var(--amber); }
    .dsv-major { color: var(--red); }

    /* Info rows */
    .detail-info { padding: 14px 18px; display: flex; flex-direction: column; gap: 10px; border-bottom: 1px solid var(--border); }
    .detail-info-row { display: flex; align-items: flex-start; gap: 9px; font-size: 12.5px; }
    .detail-info-icon {
      width: 28px; height: 28px;
      border-radius: 7px;
      background: var(--surface-2);
      border: 1px solid var(--border);
      display: grid;
      place-items: center;
      flex-shrink: 0;
      color: var(--text-4);
    }
    .detail-info-icon svg { width: 13px; height: 13px; }
    .detail-info-lbl { font-size: 10px; font-weight: 700; color: var(--text-4); text-transform: uppercase; letter-spacing: .5px; line-height: 1; }
    .detail-info-val { font-size: 12.5px; font-weight: 600; color: var(--text-1); margin-top: 2px; }

    /* View full button */
    .detail-cta { padding: 14px 18px; border-bottom: 1px solid var(--border); }
    .btn-view-full {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      width: 100%;
      padding: 10px;
      background: linear-gradient(135deg, var(--blue) 0%, #2563eb 100%);
      color: #fff;
      border: none;
      border-radius: var(--radius-sm);
      text-decoration: none;
      font-weight: 600;
      font-size: 13px;
      font-family: 'Sora', sans-serif;
      box-shadow: 0 2px 8px rgba(29,78,216,.25);
      transition: all .18s;
      cursor: pointer;
    }
    .btn-view-full svg { width: 14px; height: 14px; }
    .btn-view-full:hover {
      background: linear-gradient(135deg, var(--blue-h) 0%, var(--blue) 100%);
      box-shadow: 0 4px 14px rgba(29,78,216,.35);
      transform: translateY(-1px);
    }

    /* Offense history */
    .detail-history { padding: 14px 18px; }
    .history-title { font-size: 13px; font-weight: 600; margin-bottom: 12px; letter-spacing: -.1px; }

    .offense-card {
      border: 1.5px solid var(--border);
      border-radius: var(--radius-sm);
      padding: 12px 13px;
      margin-bottom: 8px;
      background: var(--surface);
      position: relative;
      overflow: hidden;
    }
    .offense-card::before {
      content: '';
      position: absolute;
      top: 0; left: 0; bottom: 0;
      width: 3px;
    }
    .offense-card.level-MAJOR::before { background: var(--red); }
    .offense-card.level-MINOR::before { background: var(--amber); }

    .offense-card-top {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 7px;
    }
    .badge {
      display: inline-flex;
      align-items: center;
      gap: 3px;
      padding: 2px 8px;
      border-radius: 5px;
      font-size: 10.5px;
      font-weight: 700;
      letter-spacing: .2px;
    }
    .badge-minor { background: var(--amber-soft); color: var(--amber); border: 1px solid var(--amber-mid); }
    .badge-major { background: var(--red-soft); color: var(--red); border: 1px solid var(--red-mid); }

    .status-badge {
      font-size: 10px;
      font-weight: 700;
      padding: 2px 7px;
      border-radius: 5px;
    }
    .status-OPEN     { background: var(--blue-soft); color: var(--blue); border: 1px solid var(--blue-mid); }
    .status-RESOLVED { background: var(--green-soft); color: var(--green); border: 1px solid var(--green-mid); }
    .status-VOID     { background: var(--surface-2); color: var(--text-4); border: 1px solid var(--border); text-decoration: line-through; }

    .offense-name { font-size: 12.5px; font-weight: 700; color: var(--text-1); line-height: 1.3; }
    .offense-code {
      font-size: 10px;
      color: var(--text-4);
      font-family: 'JetBrains Mono', monospace;
      margin-bottom: 3px;
    }
    .offense-desc { font-size: 11.5px; color: var(--text-3); line-height: 1.4; margin-top: 5px; }
    .offense-date {
      font-size: 11px;
      color: var(--text-4);
      margin-top: 6px;
      display: flex;
      align-items: center;
      gap: 4px;
    }
    .offense-date svg { width: 11px; height: 11px; }

    .dp-loading {
      padding: 28px;
      text-align: center;
      color: var(--text-4);
      font-size: 13px;
      font-weight: 600;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 8px;
    }
    .dp-error {
      padding: 12px 14px;
      background: var(--red-soft);
      border: 1px solid var(--red-mid);
      border-radius: var(--radius-sm);
      color: var(--red);
      font-size: 12px;
      font-weight: 600;
      margin-top: 8px;
    }

    @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    .spin { animation: spin 1s linear infinite; }

    /* ── RESPONSIVE ── */
    @media (max-width: 1100px) {
      .main-body { grid-template-columns: 1fr; }
      .detail-panel { position: static; max-height: none; }
    }
    @media (max-width: 1024px) { .admin-shell { grid-template-columns: 1fr; } }
    @media (max-width: 900px)  {
      .content-area { padding: 16px; }
      .page-header  { padding: 12px 16px; flex-wrap: wrap; }
      .stats        { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px)  { .stats { grid-template-columns: 1fr; } }
  </style>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>

  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="main-wrap">

      <!-- PAGE HEADER -->
      <section class="page-header">
        <div class="page-header-left">
          <div class="page-title">Offense Management</div>
          <div class="page-welcome">Welcome back, <?php echo e($fullName); ?></div>
        </div>
        <a class="btn-register" href="offense_new.php">
          <svg fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24">
            <line x1="12" y1="5" x2="12" y2="19"/>
            <line x1="5" y1="12" x2="19" y2="12"/>
          </svg>
          Register New Offense
        </a>
      </section>

      <div class="content-area">

        <!-- STATS -->
        <div class="stats">
          <div class="stat total">
            <div class="stat-icon">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                <rect x="9" y="3" width="6" height="4" rx="1"/>
              </svg>
            </div>
            <div class="stat-val"><?php echo $totalCount; ?></div>
            <div class="stat-lbl">Total Offenses</div>
          </div>
          <div class="stat minor">
            <div class="stat-icon">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
                <line x1="12" y1="9" x2="12" y2="13"/>
                <line x1="12" y1="17" x2="12.01" y2="17"/>
              </svg>
            </div>
            <div class="stat-val"><?php echo $minorCount; ?></div>
            <div class="stat-lbl">Minor Offenses</div>
          </div>
          <div class="stat major">
            <div class="stat-icon">
              <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"/>
              </svg>
            </div>
            <div class="stat-val"><?php echo $majorCount; ?></div>
            <div class="stat-lbl">Major Offenses</div>
          </div>
        </div>

        <!-- MAIN BODY -->
        <div class="main-body" id="mainBody">

          <?php if ($scanFlash !== ''): ?>
            <div class="empty" style="margin-bottom:14px;border-style:solid;background:#fff7ed;border-color:#fdba74;">
              <h3 style="margin:0;color:#9a3412;">Scan Result</h3>
              <p style="margin-top:4px;color:#9a3412;"><?php echo e($scanFlash); ?></p>
            </div>
          <?php endif; ?>

          <!-- LEFT: SEARCH + CARDS -->
          <div class="left-panel">
            <div class="left-panel-header">
              <div class="left-panel-title">Student Offenses</div>
              <div class="left-panel-sub">
                <?php echo count($students); ?> student<?php echo count($students) !== 1 ? 's' : ''; ?> found
              </div>
            </div>
            <div class="left-panel-body">

              <!-- Search -->
              <div class="search-wrap">
                <div class="search-controls">
                  <div class="search-input-wrap">
                    <span class="search-icon">
                      <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="11" cy="11" r="8"/>
                        <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                      </svg>
                    </span>
                    <input id="searchInput"
                           class="search-input"
                           type="text"
                           value="<?php echo e($q); ?>"
                           placeholder="Search by name or student ID…"
                           autocomplete="off"/>
                    <?php if ($q !== ''): ?>
                      <button class="search-clear"
                              onclick="window.location='offenses.php?filter=<?php echo e($filter); ?><?php echo $selectedMonth !== '' ? '&month=' . urlencode($selectedMonth) : ''; ?>'">
                        <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                          <line x1="18" y1="6" x2="6" y2="18"/>
                          <line x1="6" y1="6" x2="18" y2="18"/>
                        </svg>
                      </button>
                    <?php endif; ?>
                  </div>
                  <button id="searchBtn" class="search-btn" type="button">Search</button>
                </div>
                <div id="ajaxResults" class="ajax-results"></div>
              </div>

              <!-- Filters -->
              <div class="filters">
                <a class="filter-btn <?php echo $filter === 'all'   ? 'active'       : ''; ?>"
                   href="offenses.php?filter=all<?php   echo $q !== '' ? '&q=' . urlencode($q) : ''; ?><?php echo $selectedMonth !== '' ? '&month=' . urlencode($selectedMonth) : ''; ?>">All</a>
                <a class="filter-btn <?php echo $filter === 'minor' ? 'active active-minor' : ''; ?>"
                   href="offenses.php?filter=minor<?php echo $q !== '' ? '&q=' . urlencode($q) : ''; ?><?php echo $selectedMonth !== '' ? '&month=' . urlencode($selectedMonth) : ''; ?>">Minor Only</a>
                <a class="filter-btn <?php echo $filter === 'major' ? 'active active-major' : ''; ?>"
                   href="offenses.php?filter=major<?php echo $q !== '' ? '&q=' . urlencode($q) : ''; ?><?php echo $selectedMonth !== '' ? '&month=' . urlencode($selectedMonth) : ''; ?>">Major Only</a>

                <select id="monthFilter" class="filter-btn" onchange="window.location='offenses.php?filter=<?php echo $filter; ?>&q=<?php echo urlencode($q); ?>&month=' + this.value">
                  <option value="">All Months</option>
                  <?php foreach ($availableMonths as $am): ?>
                    <option value="<?php echo $am['m']; ?>" <?php echo $selectedMonth === $am['m'] ? 'selected' : ''; ?>>
                      <?php echo date('F Y', strtotime($am['m'] . '-01')); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>

              <!-- Student cards -->
              <?php if (empty($students)): ?>
                <div class="empty">
                  <div class="empty-icon">
                    <svg fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                      <path d="M9 5H7a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-2"/>
                      <rect x="9" y="3" width="6" height="4" rx="1"/>
                    </svg>
                  </div>
                  <h3>No Records Found</h3>
                  <p>No student offenses match your current filters.</p>
                </div>
              <?php else: ?>
                <div class="student-list">
                  <?php foreach ($students as $s): ?>
                    <?php
                      $fn        = (string)($s['student_fn'] ?? '');
                      $ln        = (string)($s['student_ln'] ?? '');
                      $init      = initials($fn, $ln);
                      $name      = trim($fn . ' ' . $ln);
                      $sid       = (string)($s['student_id'] ?? '');
                      $total     = (int)($s['total_offenses']         ?? 0);
                      $min       = (int)($s['minor_offenses']         ?? 0);
                      $majExp    = (int)($s['major_offenses_explicit'] ?? 0);
                      $hasEsc    = (int)($s['has_escalated_major']    ?? 0); // not displayed, only for counting
                      $totalMaj  = $majExp + $hasEsc;
                      $last      = (string)($s['last_offense_date']   ?? '');
                      $lastName  = (string)($s['last_offense_name']   ?? '');
                      $lastCode  = (string)($s['last_offense_code']   ?? '');
                      $lastLevel = (string)($s['last_offense_level']  ?? '');
                      $lastDesc  = (string)($s['last_description']    ?? '');
                      $yearLvl   = (int)($s['year_level'] ?? 0);
                      $suffix    = match($yearLvl) { 1=>'st', 2=>'nd', 3=>'rd', default=>'th' };
                    ?>
                    <div class="student-card"
                         id="card-<?php echo e($sid); ?>"
                         tabindex="0"
                         data-id="<?php         echo e($sid);   ?>"
                         data-name="<?php       echo e($name);  ?>"
                         data-init="<?php       echo e($init);  ?>"
                         data-total="<?php      echo $total;    ?>"
                         data-minor="<?php      echo $min;      ?>"
                         data-major="<?php      echo $totalMaj; ?>"
                         data-last="<?php       echo $last ? e(date('n/j/Y', strtotime($last))) : ''; ?>"
                         data-last-type="<?php  echo e($lastName); ?>"
                         data-last-code="<?php  echo e($lastCode); ?>"
                         data-last-level="<?php echo e($lastLevel); ?>"
                         data-last-desc="<?php  echo e($lastDesc); ?>"
                         data-year="<?php echo $yearLvl ? $yearLvl . $suffix . ' Year' : ''; ?>"
                         data-program="<?php echo e((string)($s['program'] ?? '')); ?>"
                         data-school="<?php  echo e((string)($s['school']  ?? '')); ?>"
                         data-section="<?php echo e((string)($s['section'] ?? '')); ?>"
                    >
                      <div class="card-top">
                        <div class="card-left">
                          <div class="avatar"><?php echo e($init); ?></div>
                          <div>
                            <div class="student-name">
                              <?php echo e($name); ?>
                              <?php if ((int)($s['_dec_fail'] ?? 0) === 1): ?>
                                <span style="color:red;font-size:10px;font-weight:800;">[DECRYPT FAIL]</span>
                              <?php endif; ?>
                            </div>
                            <div class="student-sid"><?php  echo e($sid);  ?></div>
                          </div>
                        </div>
                        <button class="btn-view"
                          type="button"
                          onclick="event.stopPropagation(); openDetail(this.closest('.student-card'))">
                          View Details
                        </button>
                      </div>

                      <div class="card-meta">
                        <span class="meta-badge meta-total">
                          Total: <?php echo $total; ?>
                        </span>
                        <span class="meta-badge meta-minor">
                          Minor: <?php echo $min; ?>
                        </span>
                        <span class="meta-badge meta-major">
                          Major: <?php echo $totalMaj; ?>
                        </span>
                        <?php if ($last): ?>
                          <span class="card-last">
                            <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                              <rect x="3" y="4" width="18" height="18" rx="2"/>
                              <line x1="16" y1="2" x2="16" y2="6"/>
                              <line x1="8" y1="2" x2="8" y2="6"/>
                              <line x1="3" y1="10" x2="21" y2="10"/>
                            </svg>
                            <?php echo e(date('M j, Y', strtotime($last))); ?>
                          </span>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>

            </div><!-- /.left-panel-body -->
          </div><!-- /.left-panel -->

          <!-- RIGHT: DETAIL PANEL -->
          <aside class="detail-panel" id="detailPanel">

            <div class="detail-panel-header">
              <span class="detail-panel-title">Student Details</span>
              <button class="detail-close" onclick="closeDetail()">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <line x1="18" y1="6" x2="6" y2="18"/>
                  <line x1="6" y1="6" x2="18" y2="18"/>
                </svg>
              </button>
            </div>

            <div class="detail-hero">
              <div class="detail-avatar" id="dpAvatar">??</div>
              <div class="detail-name"   id="dpName">—</div>
              <div class="detail-sid"    id="dpSid">—</div>
              <div class="detail-year"   id="dpYear" style="display:none"></div>
            </div>

            <div class="detail-stats">
              <div class="detail-stat">
                <div class="detail-stat-val dsv-total" id="dpTotal">0</div>
                <div class="detail-stat-lbl">Total</div>
              </div>
              <div class="detail-stat">
                <div class="detail-stat-val dsv-minor" id="dpMinor">0</div>
                <div class="detail-stat-lbl">Minor</div>
              </div>
              <div class="detail-stat">
                <div class="detail-stat-val dsv-major" id="dpMajor">0</div>
                <div class="detail-stat-lbl">Major</div>
              </div>
            </div>

            <div class="detail-info" id="dpInfoArea"></div>

            <div class="detail-cta">
              <a class="btn-view-full" id="dpViewLink" href="#">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                  <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                  <circle cx="12" cy="12" r="3"/>
                </svg>
                View Full Profile
              </a>
            </div>

            <div class="detail-history">
              <div class="history-title">Recent Offenses</div>
              <div id="dpHistory">
                <div class="dp-loading">Select a student to view their offense history.</div>
              </div>
            </div>

          </aside>

        </div><!-- /.main-body -->
      </div><!-- /.content-area -->
    </main>
  </div>

  <script>
  // ── Helpers ─────────────────────────────────────────────────────────────────
  function esc(s) {
    return String(s)
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
  }
  function inits(name) {
    const p = String(name || '').trim().split(/\s+/);
    return ((p[0]||'?')[0] + (p[p.length-1]||'?')[0]).toUpperCase();
  }
  function escRegExp(s) { return String(s).replace(/[.*+?^${}()|[\]\\]/g,'\\$&'); }
  function highlightMatch(text, q) {
    const rawText = String(text || '');
    if (!q) return esc(rawText);
    const re = new RegExp('(' + escRegExp(q) + ')','ig');
    return esc(rawText).replace(re,'<mark style="background:#fef08a;padding:0 1px;border-radius:2px;">$1</mark>');
  }

  // ── Detail Panel ────────────────────────────────────────────────────────────
  function openDetail(card) {
    document.querySelectorAll('.student-card').forEach(c => c.classList.remove('selected'));
    card.classList.add('selected');

    const id       = card.dataset.id      || '';
    const name     = card.dataset.name    || '';
    const init     = card.dataset.init    || '??';
    const total    = card.dataset.total   || '0';
    const minor    = card.dataset.minor   || '0';
    const major    = card.dataset.major   || '0';
    const year     = card.dataset.year    || '';
    const program  = card.dataset.program || '';
    const school   = card.dataset.school  || '';
    const section  = card.dataset.section || '';

    document.getElementById('dpAvatar').textContent = init;
    document.getElementById('dpName').textContent   = name;
    document.getElementById('dpSid').textContent    = id;
    document.getElementById('dpTotal').textContent  = total;
    document.getElementById('dpMinor').textContent  = minor;
    document.getElementById('dpMajor').textContent  = major;

    const yearEl = document.getElementById('dpYear');
    if (year) { yearEl.textContent = year; yearEl.style.display = 'inline-block'; }
    else { yearEl.style.display = 'none'; }

    // Info rows
    const infoArea = document.getElementById('dpInfoArea');
    const infoRows = [];
    if (school)  infoRows.push(infoRow('🏫', 'School',  school));
    if (program) infoRows.push(infoRow('🎓', 'Program', program));
    if (section) infoRows.push(infoRow('📋', 'Section', section));
    infoArea.innerHTML = infoRows.join('');

    document.getElementById('dpViewLink').href =
      'offenses_student_view.php?student_id=' + encodeURIComponent(id);

    document.getElementById('dpHistory').innerHTML =
      '<div class="dp-loading">' +
      '<svg class="spin" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 12a9 9 0 1 1-6.219-8.56"/></svg>' +
      ' Loading…</div>';

    document.getElementById('detailPanel').classList.add('visible');
    loadStudentDetail(id);
  }

  function infoRow(icon, label, val) {
    return `
    <div class="detail-info-row">
      <div class="detail-info-icon">${icon}</div>
      <div>
        <div class="detail-info-lbl">${esc(label)}</div>
        <div class="detail-info-val">${esc(val)}</div>
      </div>
    </div>`;
  }

  function closeDetail() {
    document.getElementById('detailPanel').classList.remove('visible');
    document.querySelectorAll('.student-card').forEach(c => c.classList.remove('selected'));
  }

  async function loadStudentDetail(studentId) {
    try {
      const res  = await fetch(
        'AJAX/get_student_detail.php?student_id=' + encodeURIComponent(studentId),
        { headers: { 'Accept': 'application/json' } }
      );
      const text = await res.text();
      let json;
      try { json = JSON.parse(text); }
      catch (_) { throw new Error('Non-JSON response: ' + text.substring(0,120)); }

      if (!json.ok) throw new Error(json.message || 'Server error');

      const d = json.data;
      if (d.email || d.phone || d.address) {
        const infoArea = document.getElementById('dpInfoArea');
        const existing = infoArea.innerHTML;
        const extra = [];
        if (d.email)   extra.push(infoRow('✉️', 'Email',   d.email));
        if (d.phone)   extra.push(infoRow('📞', 'Phone',   d.phone));
        if (d.address) extra.push(infoRow('📍', 'Address', d.address));
        infoArea.innerHTML = existing + extra.join('');
      }

      renderHistory(d.offenses || []);

    } catch (err) {
      document.getElementById('dpHistory').innerHTML =
        '<div class="dp-error">Could not load offense history.<br><small>' + esc(err.message) + '</small></div>';
    }
  }

  function renderHistory(offenses) {
    if (!offenses.length) {
      document.getElementById('dpHistory').innerHTML =
        '<div class="dp-loading">No offenses recorded.</div>';
      return;
    }
    document.getElementById('dpHistory').innerHTML = offenses.map(o => {
      const level  = (o.level  || 'MINOR').toUpperCase();
      const status = (o.status || 'OPEN').toUpperCase();

      const levelBadge  = level === 'MAJOR'
        ? '<span class="badge badge-major">Major</span>'
        : '<span class="badge badge-minor">Minor</span>';

      const statusCls   = ['OPEN','RESOLVED','VOID'].includes(status) ? status : 'OPEN';
      const statusBadge = `<span class="status-badge status-${statusCls}">${esc(status)}</span>`;

      const date = o.date_committed
        ? new Date(o.date_committed).toLocaleDateString('en-US',{ month:'short', day:'numeric', year:'numeric' })
        : '';

      return `
      <div class="offense-card level-${esc(level)}">
        <div class="offense-card-top">${levelBadge}${statusBadge}</div>
        ${o.offense_code ? `<div class="offense-code">${esc(o.offense_code)}</div>` : ''}
        <div class="offense-name">${esc(o.offense_name || '—')}</div>
        ${o.description ? `<div class="offense-desc">${esc(o.description)}</div>` : ''}
        ${date ? `<div class="offense-date">
          <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
          ${esc(date)}
        </div>` : ''}
      </div>`;
    }).join('');
  }

  // ── AJAX Search ─────────────────────────────────────────────────────────────
  const searchInput   = document.getElementById('searchInput');
  const ajaxBox       = document.getElementById('ajaxResults');
  const searchBtn     = document.getElementById('searchBtn');
  const studentCards  = Array.from(document.querySelectorAll('.student-card'));
  let searchTimer     = null;
  let lastQuery       = '';
  let currentQuery    = '';
  let activeIndex     = -1;

  function closeBox() { ajaxBox.classList.remove('open'); ajaxBox.innerHTML = ''; activeIndex = -1; }

  function getItems() { return Array.from(ajaxBox.querySelectorAll('.ajax-item')); }

  function setActive(index) {
    const items = getItems();
    items.forEach(i => i.classList.remove('active'));
    if (!items.length) { activeIndex = -1; return; }
    const next = Math.max(0, Math.min(index, items.length - 1));
    activeIndex = next;
    items[next].classList.add('active');
    items[next].scrollIntoView({ block: 'nearest' });
  }

  function renderDropdown(items) {
    if (!items || !items.length) { closeBox(); return; }
    ajaxBox.innerHTML = items.map(it => {
      const sid   = it.student_id   || it.id   || '';
      const sname = it.student_name || it.name || '';
      const last  = it.last_offense_date
        ? new Date(it.last_offense_date).toLocaleDateString('en-US',{ month:'short', day:'numeric', year:'numeric' })
        : '—';
      return `
      <div class="ajax-item" tabindex="0"
           data-id="${esc(sid)}"
           data-name="${esc(sname)}"
           data-init="${esc(inits(sname || sid))}">
        <div class="ajax-left">
          <div class="ajax-avatar">${esc(inits(sname || sid))}</div>
          <div>
            <div class="ajax-name">${highlightMatch(sname, currentQuery)}</div>
            <div class="ajax-sid">${highlightMatch(sid, currentQuery)}</div>
          </div>
        </div>
        <div class="ajax-right">
          <div>Total: ${esc(String(it.total ?? 0))}</div>
          <div>
            <span class="minor">Minor: ${esc(String(it.minor ?? 0))}</span>
            &nbsp;·&nbsp;
            <span class="major">Major: ${esc(String(it.major ?? 0))}</span>
          </div>
          <div style="font-size:10.5px;">Last: ${esc(last)}</div>
        </div>
      </div>`;
    }).join('');
    ajaxBox.classList.add('open');
    setActive(0);
  }

  function getLocalMatches(q) {
    const lq = q.toLowerCase();
    return studentCards
      .filter(c => {
        const id   = (c.dataset.id   || '').toLowerCase();
        const name = (c.dataset.name || '').toLowerCase();
        return id.startsWith(lq) || name.includes(lq);
      })
      .slice(0, 8)
      .map(c => ({
        student_id:        c.dataset.id    || '',
        student_name:      c.dataset.name  || '',
        total:             Number(c.dataset.total || 0),
        minor:             Number(c.dataset.minor || 0),
        major:             Number(c.dataset.major || 0),
        last_offense_date: c.dataset.last  || '',
      }));
  }

  function applySearch() {
    const q = (searchInput?.value || '').trim();
    const month = document.getElementById('monthFilter')?.value || '';
    const params = new URLSearchParams({ filter: '<?php echo e($filter); ?>' });
    if (q) {
      // For scanner-style input and student IDs, resolve directly to student offense view.
      if (/^[0-9-]{6,}$/.test(q)) params.set('scan', q);
      else params.set('q', q);
    }
    if (month) params.set('month', month);
    window.location.href = 'offenses.php?' + params.toString();
  }

  if (searchInput) {
    searchInput.addEventListener('input', () => {
      const q = searchInput.value.trim();
      currentQuery = q;
      if (q.length < 1) { closeBox(); return; }

      renderDropdown(getLocalMatches(q));

      clearTimeout(searchTimer);
      searchTimer = setTimeout(async () => {
        if (q === lastQuery) return;
        lastQuery = q;
        try {
          const r    = await fetch('AJAX/search_students_offenses.php?q=' + encodeURIComponent(q) + '&limit=8', { headers:{ 'Accept':'application/json' } });
          const json = await r.json();
          if (json.ok && Array.isArray(json.data) && json.data.length) renderDropdown(json.data);
        } catch { /* keep local results */ }
      }, 280);
    });

    searchInput.addEventListener('keydown', e => {
      if (e.key === 'Escape') { closeBox(); return; }
      const items = getItems();
      if (e.key === 'ArrowDown' && items.length) { e.preventDefault(); setActive(activeIndex + 1); return; }
      if (e.key === 'ArrowUp'   && items.length) { e.preventDefault(); setActive(activeIndex - 1); return; }
      if (e.key === 'Enter') {
        e.preventDefault();
        if (ajaxBox.classList.contains('open') && items.length && activeIndex >= 0) {
          items[activeIndex].click(); return;
        }
        applySearch();
      }
    });
  }

  if (searchBtn) searchBtn.addEventListener('click', applySearch);

  // Open details by clicking anywhere on the student card (except interactive controls).
  studentCards.forEach(card => {
    card.addEventListener('click', e => {
      if (e.target.closest('button, a, input, select, textarea')) return;
      openDetail(card);
    });

    card.addEventListener('keydown', e => {
      if (e.key === 'Enter' || e.key === ' ') {
        e.preventDefault();
        openDetail(card);
      }
    });
  });

  document.addEventListener('click', e => {
    if (!ajaxBox.contains(e.target) && e.target !== searchInput) closeBox();
  });

  ajaxBox.addEventListener('click', e => {
    const el = e.target.closest('.ajax-item');
    if (!el) return;
    const sid  = el.dataset.id || '';
    const card = sid ? document.getElementById('card-' + sid) : null;
    if (card) { openDetail(card); closeBox(); return; }
    if (sid) {
      // Card not on page (AJAX-only result) — navigate
      window.location.href = 'offenses_student_view.php?student_id=' + encodeURIComponent(sid);
    }
  });

  ajaxBox.addEventListener('keydown', e => {
    if (e.key === 'Enter') { const el = e.target.closest('.ajax-item'); if (el) el.click(); }
  });
  </script>
</body>
</html>

