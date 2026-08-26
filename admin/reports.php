<?php
// File: C:\xampp\htdocs\identitrack\admin\reports.php
// Monthly Discipline Report (AJAX-driven charts + REAL Excel export link)
// NOTE: The export button downloads the chosen month/year from the dropdown.

require_once __DIR__ . '/../database/database.php';
require_admin();

$activeSidebar = 'reports';

$admin = admin_current();
$fullName = trim((string)($admin['full_name'] ?? ''));
if ($fullName === '') $fullName = (string)($admin['username'] ?? 'User');

// Month selector (YYYY-MM)
if (!isset($_GET['month'])) {
  $latestRow = db_one("SELECT DATE_FORMAT(date_committed, '%Y-%m') AS ym FROM offense WHERE date_committed IS NOT NULL ORDER BY date_committed DESC LIMIT 1");
  $selectedMonth = !empty($latestRow['ym']) ? $latestRow['ym'] : date('Y-m');
} else {
  $selectedMonth = trim((string)($_GET['month'] ?? ''));
}
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) $selectedMonth = date('Y-m');

$selectedAudience = strtoupper(trim((string)($_GET['audience'] ?? 'ALL')));
if (!in_array($selectedAudience, ['ALL', 'COLLEGE', 'SHS'], true)) $selectedAudience = 'ALL';

require_once __DIR__ . '/data/historical_dataset_cache.php';

// Month options (ONLY months/years that ACTUALLY contain offense or case data)
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

// Include all months that have records in the historical dataset cache up to current month
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

if (empty($monthOptions)) {
  $monthOptions[] = date('Y-m');
}

usort($monthOptions, function($a, $b) { return strcmp($b, $a); });
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Monthly Discipline Report | SDO Web Portal</title>

  <style>
    *{ box-sizing:border-box; }
    body{ margin:0; font-family:'Segoe UI',Tahoma,Arial,sans-serif; background:#f8f9fa; color:#1b2244; }
    .admin-shell{ min-height: calc(100vh - 72px); display:grid; grid-template-columns: 240px 1fr; }
    .wrap{ min-height:100%; padding:0; }

    .page-header{ background:#fff; border-bottom:1px solid #e0e0e0; padding: 16px 18px; }
    .page-header h1{ margin:0; color:#1a1a1a; font-size:16px; font-weight:600; }
    .welcome{ margin-top:2px; color:#6c757d; font-size:11px; font-weight:400; }

    .content-area{ padding: 12px 16px 20px; max-width: 1280px; margin:0 auto; }

    .stats{
      display:grid;
      grid-template-columns: repeat(6, minmax(0, 1fr));
      gap: 8px;
      margin: 10px 0 12px;
    }
    @media (max-width: 1100px) {
      .stats{ grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (max-width: 640px) {
      .stats{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    .stat{
      background:#fff;
      border:1px solid #dee2e6;
      border-radius: 10px;
      padding: 12px;
      box-shadow: none;
      position: relative;
      overflow:hidden;
      min-height: 102px;
    }
    .stat::before{ content:''; position:absolute; left:0; top:0; bottom:0; width: 5px; background:#3b4a9e; }
    .stat.blue::before{ background:#0d6efd; }
    .stat.yellow::before{ background:#ffc107; }
    .stat.red::before{ background:#dc3545; }
    .stat.purple::before{ background:#6f42c1; }
    .stat.gray::before{ background:#64748b; }

    .stat .label{ color:#6c757d; font-size: 10px; font-weight: 600; }
    .stat .value{ margin-top: 12px; font-size: 32px; font-weight: 700; color:#1a1a1a; line-height: 1; }
    .stat .sub{ margin-top: 12px; color:#9aa0a6; font-weight: 500; font-size: 10px; }

    .export{
      background:#fff;
      border:1px solid #dee2e6;
      border-radius: 12px;
      padding: 12px 14px;
      box-shadow: none;
      display:flex;
      align-items:center;
      justify-content: space-between;
      gap: 12px;
      margin-bottom: 10px;
    }
    .export .title{ font-weight:600; font-size:14px; color:#1a1a1a; margin:0; }
    .export .desc{ margin-top:3px; color:#6c757d; font-weight:500; font-size: 10px; }

    .export-right{ display:flex; align-items:center; gap: 8px; }
    select{
      height: 34px;
      border-radius: 8px;
      border:1px solid #cfd4da;
      padding: 0 10px;
      font-size:12px;
      font-weight:500;
      background:#fff;
      color:#1a1a1a;
      outline:none;
    }
    .btn-excel{
      height: 34px;
      border-radius: 8px;
      padding: 0 10px;
      border: 1px solid rgba(25,135,84,.25);
      background:#22c55e;
      color:#fff;
      font-size:12px;
      font-weight:600;
      cursor:pointer;
      display:inline-flex;
      align-items:center;
      gap: 6px;
      text-decoration:none;
      white-space:nowrap;
    }
    .btn-excel:hover{ background:#16a34a; }

    .grid2{
      display:grid;
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      align-items: start;
    }
    .panel{
      background:#f3f3f3;
      border:1px solid #d7d7d7;
      border-radius: 10px;
      padding: 12px;
      box-shadow: none;
    }
    .panel h2{ margin:0 0 8px; font-size:14px; font-weight:600; color:#1a1a1a; }

    .detail-list{ margin-top: 8px; }
    .detail-item{
      display:flex;
      justify-content: space-between;
      gap: 8px;
      padding: 4px 0;
      border-bottom: 1px solid #f1f3f5;
      color:#1a1a1a;
      font-size:12px;
      font-weight:500;
    }
    .detail-item:last-child{ border-bottom:none; }
    .muted{ color:#6c757d; font-size:11px; font-weight:500; }

    .breakdown-panel{ padding: 14px 14px 12px; }
    .breakdown-chart-wrap{ padding: 2px 6px 0; max-width: 340px; margin: 0 auto; }
    .breakdown-title{ margin-top: 10px; font-weight:600; font-size:11px; color:#333; }
    .breakdown-list{ margin-top: 6px; }
    .breakdown-row{
      display:flex;
      justify-content: space-between;
      align-items:center;
      gap: 10px;
      padding: 2px 0;
      font-size:11px;
      color:#353535;
    }
    .breakdown-left{ display:flex; align-items:center; gap: 6px; min-width:0; }
    .breakdown-dot{ width:9px; height:9px; border-radius:50%; flex:0 0 auto; }
    .breakdown-name{ white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .breakdown-count{ font-weight:600; color:#333; }

    .courses-chart-wrap{ max-width: 360px; margin: 0 auto; }
    .course-item{ padding: 3px 0 5px; }
    .course-top{ display:flex; justify-content:space-between; align-items:baseline; gap:10px; }
    .course-name{ color:#2f2f2f; font-size:12px; font-weight:600; }
    .course-count{ color:#4d4d4d; font-size:12px; font-weight:600; }
    .course-sections{ margin-top:2px; color:#7b7b7b; font-size:10px; }

    .trend{ margin-top: 10px; }

    .loading{
      margin-top: 6px;
      color:#6c757d;
      font-size:11px;
      font-weight:500;
      display:none;
    }
    .loading.show{ display:block; }

    @media (max-width: 1100px){
      .grid2{ grid-template-columns: 1fr; }
      .stats{ grid-template-columns: repeat(2, minmax(0, 1fr)); }
    }
    @media (max-width: 900px){
      .admin-shell{ grid-template-columns: 1fr; }
      .content-area{ padding: 18px 16px; }
      .page-header{ padding: 14px 16px; }
      .stats{ grid-template-columns: 1fr; }
      .export{ flex-direction: column; align-items: flex-start; }
      .export-right{ width:100%; }
      select{ flex:1; width:100%; }
      .btn-excel{ width:100%; justify-content:center; }
    }
  </style>

  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
  <?php require_once __DIR__ . '/header.php'; ?>

  <div class="admin-shell">
    <?php require_once __DIR__ . '/sidebar.php'; ?>

    <main class="wrap">
      <section class="page-header">
        <h1>Monthly Discipline Report</h1>
        <div class="welcome">Welcome, <?php echo e($fullName); ?></div>
      </section>

      <div class="content-area">

        <!-- Stats cards (values filled by AJAX) -->
        <section class="stats" aria-label="Report stats">
          <div class="stat blue">
            <div class="label">Total Offenses</div>
            <div class="value" id="statTotal">—</div>
            <div class="sub">This month</div>
          </div>

          <div class="stat yellow">
            <div class="label">Total Minor</div>
            <div class="value" id="statMinor" style="color:#b38600;">—</div>
            <div class="sub" id="statMinorSub">—</div>
          </div>

          <div class="stat red">
            <div class="label">Total Major</div>
            <div class="value" id="statMajor" style="color:#dc3545;">—</div>
            <div class="sub" id="statMajorSub">—</div>
          </div>

          <div class="stat gray">
            <div class="label">Dismissed Offenses</div>
            <div class="value" id="statDismissedOffenses" style="color:#64748b;">—</div>
            <div class="sub" id="statDismissedOffensesSub">SDO Cleared / Voided</div>
          </div>

          <div class="stat purple">
            <div class="label">Active Cases</div>
            <div class="value" id="statActive" style="color:#6f42c1;">—</div>
            <div class="sub">Under review</div>
          </div>

          <div class="stat gray" style="border-left-color: #475569;">
            <div class="label">Dismissed Cases</div>
            <div class="value" id="statDismissedCases" style="color:#475569;">—</div>
            <div class="sub">UPCC Panel Cleared</div>
          </div>
        </section>

        <!-- Export -->
        <section class="export" aria-label="Export monthly report">
          <div>
            <h2 class="title">Export Monthly Report</h2>
            <div class="desc">Download comprehensive report in Excel format</div>
            <div id="loading" class="loading">Loading report data…</div>
          </div>

          <div class="export-right">
            <div class="muted" style="font-weight:600;">Select Month</div>

            <!-- month/year chooser -->
            <select id="monthSelect">
              <option value="ALL" <?php echo $selectedMonth==='ALL'?'selected':''; ?>>All-Time (All Infractions & Violations)</option>
              <?php foreach ($monthOptions as $ym): ?>
                <option value="<?php echo e($ym); ?>" <?php echo $ym===$selectedMonth?'selected':''; ?>>
                  <?php echo e(date('F Y', strtotime($ym.'-01'))); ?>
                </option>
              <?php endforeach; ?>
            </select>

            <select id="audienceSelect">
              <option value="ALL" <?php echo $selectedAudience==='ALL'?'selected':''; ?>>All Students (College + SHS)</option>
              <option value="COLLEGE" <?php echo $selectedAudience==='COLLEGE'?'selected':''; ?>>College (All Departments)</option>
              <option value="SHS" <?php echo $selectedAudience==='SHS'?'selected':''; ?>>SHS Only</option>
            </select>

            <!-- Download Excel for chosen month/year -->
            <a class="btn-excel" id="exportBtn" href="AJAX/export_monthly_report_xlsx.php?month=<?php echo urlencode($selectedMonth); ?>&audience=<?php echo urlencode($selectedAudience); ?>&category=ALL">
              <span style="font-size:18px;">⬇</span>
              Export to Excel
            </a>
          </div>
        </section>

          <!-- Offense breakdown -->
          <section class="panel breakdown-panel">
            <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; flex-wrap:wrap; gap:8px;">
              <h2 style="margin:0;">Offense Breakdown (This Month)</h2>
              <select id="categorySelect" style="padding:6px 12px; border:1px solid #cbd5e1; border-radius:6px; font-size:12px; font-weight:600; background:#ffffff; color:#1e293b; cursor:pointer; outline:none; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                <option value="ALL">All Breakdown Categories</option>
                <option value="MINOR">Minor Offenses Only</option>
                <option value="MAJOR">Major Offenses Only</option>
                <option value="DISMISSED">Dismissed Offenses & Cases Only</option>
              </select>
            </div>
            <div class="breakdown-chart-wrap">
              <canvas id="pie" height="178"></canvas>
            </div>

            <div class="detail-list">
              <div class="breakdown-title">Detailed Breakdown:</div>
              <div id="breakdownList" class="breakdown-list">—</div>
            </div>
          </section>

          <!-- Top courses -->
          <section class="panel">
            <h2>Top Courses by Offenses</h2>
            <div class="courses-chart-wrap">
              <canvas id="bar" height="178"></canvas>
            </div>

            <div style="margin-top:12px;">
              <div class="muted" style="font-weight:600;">Course Details:</div>
              <div id="courseList" class="muted" style="margin-top:10px;">—</div>
            </div>
          </section>
        </div>

        <!-- Trend -->
        <section class="panel trend">
          <h2>Monthly Trend Analysis</h2>
          <canvas id="trend" height="96"></canvas>
          <div class="muted" style="margin-top:8px; font-weight:500;">
            Showing last 6 months (Minor vs Major)
          </div>
        </section>
      </div>
    </main>
  </div>

  <script>
    const monthSelect = document.getElementById('monthSelect');
    const audienceSelect = document.getElementById('audienceSelect');
    const loading = document.getElementById('loading');

    const statTotal = document.getElementById('statTotal');
    const statMinor = document.getElementById('statMinor');
    const statMajor = document.getElementById('statMajor');
    const statDismissedOffenses = document.getElementById('statDismissedOffenses');
    const statActive = document.getElementById('statActive');
    const statDismissedCases = document.getElementById('statDismissedCases');
    const statMinorSub = document.getElementById('statMinorSub');
    const statMajorSub = document.getElementById('statMajorSub');
    const statDismissedOffensesSub = document.getElementById('statDismissedOffensesSub');

    const breakdownList = document.getElementById('breakdownList');
    const courseList = document.getElementById('courseList');
    const exportBtn = document.getElementById('exportBtn');

    let pieChart = null;
    let barChart = null;
    let trendChart = null;

    const presentationColors = [
      '#1d4ed8', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6',
      '#ec4899', '#06b6d4', '#f97316', '#64748b', '#0284c7'
    ];

    function setLoading(isLoading) {
      if (!loading) return;
      loading.classList.toggle('show', !!isLoading);
    }

    function escapeHtml(s) {
      return String(s)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;')
        .replaceAll("'", '&#039;');
    }

    function percent(part, total) {
      if (!total || total <= 0) return 0;
      return Math.round((part / total) * 100);
    }

    function wrapText(str, maxLen = 38) {
      if (!str) return [];
      const words = String(str).split(' ');
      const lines = [];
      let currentLine = '';
      words.forEach(w => {
        if ((currentLine + ' ' + w).trim().length <= maxLen) {
          currentLine = (currentLine + ' ' + w).trim();
        } else {
          if (currentLine) lines.push(currentLine);
          currentLine = w;
        }
      });
      if (currentLine) lines.push(currentLine);
      return lines;
    }

    async function loadReport(month, audience, category = 'ALL') {
      setLoading(true);
      const url = 'AJAX/reports_monthly_data.php?month=' + encodeURIComponent(month) + '&audience=' + encodeURIComponent(audience) + '&category=' + encodeURIComponent(category);
      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      if (!res.ok) throw new Error('Request failed');

      const json = await res.json();
      setLoading(false);

      if (!json || !json.ok) throw new Error('Bad response');
      return json;
    }

    function renderStats(stats) {
      if (statTotal) statTotal.textContent = stats.total;
      if (statMinor) statMinor.textContent = stats.minor;
      if (statMajor) statMajor.textContent = stats.major;
      if (statDismissedOffenses) statDismissedOffenses.textContent = stats.dismissed_offenses || 0;
      if (statActive) statActive.textContent = stats.active_cases;
      if (statDismissedCases) statDismissedCases.textContent = stats.dismissed_cases || 0;

      if (statMinorSub) statMinorSub.textContent = percent(stats.minor, stats.total) + '% of total';
      if (statMajorSub) statMajorSub.textContent = percent(stats.major, stats.total) + '% of total';
      if (statDismissedOffensesSub) statDismissedOffensesSub.textContent = percent(stats.dismissed_offenses || 0, stats.total) + '% of total';
    }

    function renderBreakdown(breakdown) {
      const pie = breakdown.pie;
      const labels = Array.isArray(pie.labels) ? pie.labels : [];
      let colors = Array.isArray(pie.colors) && pie.colors.length ? pie.colors : presentationColors;

      if (colors.length < labels.length) {
        colors = labels.map((_, i) => presentationColors[i % presentationColors.length]);
      }

      const colorByLabel = {};
      const chartColors = labels.map((label, i) => {
        const lblStr = String(label);
        if (lblStr.toLowerCase().includes('dismissed')) {
          const c = '#64748b';
          colorByLabel[lblStr] = c;
          return c;
        }
        const c = String(colors[i % colors.length] || '#3b4a9e');
        colorByLabel[lblStr] = c;
        return c;
      });

      const ctx = document.getElementById('pie');
      if (pieChart) pieChart.destroy();
      pieChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
          labels: labels,
          datasets: [{
            data: pie.counts,
            backgroundColor: chartColors,
            borderWidth: 2,
            borderColor: '#ffffff',
            hoverOffset: 6
          }]
        },
        options: {
          responsive: true,
          cutout: '60%',
          plugins: {
            legend: {
              display: true,
              position: 'bottom',
              labels: {
                boxWidth: 10,
                padding: 10,
                font: { size: 10, weight: '600' }
              }
            },
            tooltip: {
              padding: 12,
              caretPadding: 8,
              titleFont: { size: 12, weight: '700' },
              bodyFont: { size: 12, weight: '600' },
              callbacks: {
                title: function(tooltipItems) {
                  if (!tooltipItems.length) return '';
                  const rawLabel = tooltipItems[0].label || '';
                  return wrapText(rawLabel, 36);
                },
                label: function(context) {
                  const val = context.raw || 0;
                  const total = context.dataset.data.reduce((a, b) => a + b, 0);
                  const pct = total ? Math.round((val / total) * 100) : 0;
                  return `Cases: ${val} (${pct}% of total)`;
                }
              }
            }
          }
        }
      });

      if (!breakdown.detailed || breakdown.detailed.length === 0) {
        breakdownList.innerHTML = '<div class="muted">No offenses recorded for this period.</div>';
        return;
      }

      breakdownList.innerHTML = breakdown.detailed.map(d => `
        <div class="breakdown-row">
          <span class="breakdown-left">
            <span class="breakdown-dot" style="background:${escapeHtml(colorByLabel[String(d.name)] || '#64748b')}"></span>
            <span class="breakdown-name">${escapeHtml(d.name)}</span>
          </span>
          <span class="breakdown-count">${escapeHtml(d.cnt)} cases</span>
        </div>
      `).join('');
    }

    function renderCourses(courses) {
      const ctx = document.getElementById('bar');
      if (barChart) barChart.destroy();
      barChart = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: courses.labels,
          datasets: [{
            label: 'Offenses',
            data: courses.counts,
            backgroundColor: '#3b4a9e',
            borderColor: '#2d3878',
            borderWidth: 1,
            borderRadius: 6,
            borderSkipped: false,
            maxBarThickness: 48,
            categoryPercentage: 0.4,
            barPercentage: 0.7
          }]
        },
        options: {
          responsive: true,
          scales: {
            y: {
              beginAtZero: true,
              ticks: { precision: 0, font: { size: 10 } },
              grid: { color: '#e2e8f0' }
            },
            x: {
              ticks: { font: { size: 10, weight: '600' } },
              grid: { display: false }
            }
          },
          plugins: {
            legend: { display: false },
            tooltip: {
              callbacks: {
                label: (ctx) => ` ${ctx.raw} Offenses`
              }
            }
          }
        }
      });

      if (!courses.list || courses.list.length === 0) {
        courseList.innerHTML = '<div class="muted">No course data for this period.</div>';
        return;
      }

      courseList.innerHTML = courses.list.map(c => {
        const rawSecs = (c.sections || []).filter(s => s && s !== 'INF232' && s !== 'N/A');
        const secDisplay = rawSecs.length ? rawSecs.join(', ') : 'All Active Sections';
        return `
          <div class="course-item">
            <div class="course-top">
              <span class="course-name">${escapeHtml(c.program)}</span>
              <span class="course-count">${escapeHtml(c.cnt)} offenses</span>
            </div>
            <div class="course-sections">Sections: ${escapeHtml(secDisplay)}</div>
          </div>
        `;
      }).join('');
    }

    function renderTrend(trend) {
      const ctx = document.getElementById('trend');
      if (trendChart) trendChart.destroy();
      trendChart = new Chart(ctx, {
        type: 'line',
        data: {
          labels: trend.labels,
          datasets: [
            {
              label: 'Major Offenses',
              data: trend.major,
              borderColor: '#dc3545',
              backgroundColor: 'rgba(220, 53, 69, 0.08)',
              tension: 0.38,
              fill: true,
              pointRadius: 4,
              pointHoverRadius: 6,
              borderWidth: 2.2,
              pointBackgroundColor: '#dc3545',
              pointBorderColor: '#ffffff',
              pointBorderWidth: 1.5
            },
            {
              label: 'Minor Offenses',
              data: trend.minor,
              borderColor: '#f59e0b',
              backgroundColor: 'rgba(245, 158, 11, 0.08)',
              tension: 0.38,
              fill: true,
              pointRadius: 4,
              pointHoverRadius: 6,
              borderWidth: 2.2,
              pointBackgroundColor: '#f59e0b',
              pointBorderColor: '#ffffff',
              pointBorderWidth: 1.5
            }
          ]
        },
        options: {
          responsive: true,
          plugins: {
            legend: {
              position: 'bottom',
              labels: { font: { size: 11, weight: '600' } }
            }
          },
          scales: {
            y: {
              beginAtZero: true,
              ticks: { precision: 0 },
              grid: { color: '#e2e8f0' }
            },
            x: {
              grid: { display: false }
            }
          }
        }
      });
    }

    function updateMonthDropdown(availableMonths, currentMonth) {
      if (!monthSelect || !availableMonths || !Array.isArray(availableMonths)) return currentMonth;

      const items = [{ value: 'ALL', text: 'All-Time (All Infractions & Violations)' }];
      availableMonths.forEach(ym => {
        if (ym === 'ALL') return;
        try {
          const parts = ym.split('-');
          if (parts.length === 2) {
            const dateObj = new Date(parseInt(parts[0]), parseInt(parts[1]) - 1, 1);
            const label = dateObj.toLocaleString('en-US', { month: 'long', year: 'numeric' });
            items.push({ value: ym, text: label });
          }
        } catch(e){}
      });

      let selectedVal = currentMonth;
      if (!items.some(it => it.value === currentMonth)) {
        selectedVal = items[1] ? items[1].value : 'ALL';
      }

      let html = '';
      items.forEach(it => {
        const sel = (it.value === selectedVal) ? 'selected' : '';
        html += `<option value="${escapeHtml(it.value)}" ${sel}>${escapeHtml(it.text)}</option>`;
      });

      monthSelect.innerHTML = html;
      return selectedVal;
    }

    const categorySelect = document.getElementById('categorySelect');

    async function refresh(month, audience, category) {
      try {
        let activeMonth = month;
        const cat = category || (categorySelect ? categorySelect.value : 'ALL');
        const data = await loadReport(activeMonth, audience, cat);

        if (data.availableMonths) {
          const validMonth = updateMonthDropdown(data.availableMonths, activeMonth);
          if (validMonth && validMonth !== activeMonth) {
            activeMonth = validMonth;
            const newData = await loadReport(activeMonth, audience, cat);
            renderStats(newData.stats);
            renderBreakdown(newData.breakdown);
            renderCourses(newData.courses);
            renderTrend(newData.trend);
            if (exportBtn) {
              exportBtn.href = 'AJAX/export_monthly_report_xlsx.php?month=' + encodeURIComponent(activeMonth) + '&audience=' + encodeURIComponent(audience) + '&category=' + encodeURIComponent(cat);
            }
            return;
          }
        }

        renderStats(data.stats);
        renderBreakdown(data.breakdown);
        renderCourses(data.courses);
        renderTrend(data.trend);

        if (exportBtn) {
          exportBtn.href = 'AJAX/export_monthly_report_xlsx.php?month=' + encodeURIComponent(activeMonth) + '&audience=' + encodeURIComponent(audience) + '&category=' + encodeURIComponent(cat);
        }
      } catch (e) {
        setLoading(false);
        alert('Failed to load report data.');
      }
    }

    // initial load
    refresh(monthSelect.value, audienceSelect.value, categorySelect ? categorySelect.value : 'ALL');

    // change filters via AJAX (and sync export)
    monthSelect.addEventListener('change', () => refresh(monthSelect.value, audienceSelect.value, categorySelect ? categorySelect.value : 'ALL'));
    audienceSelect.addEventListener('change', () => refresh(monthSelect.value, audienceSelect.value, categorySelect ? categorySelect.value : 'ALL'));
    if (categorySelect) {
      categorySelect.addEventListener('change', () => refresh(monthSelect.value, audienceSelect.value, categorySelect.value));
    }
  </script>
</body>
</html>

