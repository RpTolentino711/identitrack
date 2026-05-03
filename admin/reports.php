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
$selectedMonth = trim((string)($_GET['month'] ?? date('Y-m')));
if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) $selectedMonth = date('Y-m');

$selectedAudience = strtoupper(trim((string)($_GET['audience'] ?? 'ALL')));
if (!in_array($selectedAudience, ['ALL', 'COLLEGE', 'SHS'], true)) $selectedAudience = 'ALL';

// Month options (last 12 months)
$monthOptions = [];
for ($i = 0; $i < 12; $i++) {
  $ym = date('Y-m', strtotime(date('Y-m-01') . " -$i months"));
  $monthOptions[] = $ym;
}
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
      grid-template-columns: repeat(4, minmax(0, 1fr));
      gap: 10px;
      margin: 10px 0 12px;
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

          <div class="stat purple">
            <div class="label">Active Cases</div>
            <div class="value" id="statActive" style="color:#6f42c1;">—</div>
            <div class="sub">Under review</div>
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
            <a class="btn-excel" id="exportBtn" href="AJAX/export_monthly_report_xlsx.php?month=<?php echo urlencode($selectedMonth); ?>&audience=<?php echo urlencode($selectedAudience); ?>">
              <span style="font-size:18px;">⬇</span>
              Export to Excel
            </a>
          </div>
        </section>

        <div class="grid2">
          <!-- Offense breakdown -->
          <section class="panel breakdown-panel">
            <h2>Offense Breakdown (This Month)</h2>
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
    const statActive = document.getElementById('statActive');
    const statMinorSub = document.getElementById('statMinorSub');
    const statMajorSub = document.getElementById('statMajorSub');

    const breakdownList = document.getElementById('breakdownList');
    const courseList = document.getElementById('courseList');
    const exportBtn = document.getElementById('exportBtn');

    let pieChart = null;
    let barChart = null;
    let trendChart = null;

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

    async function loadReport(month, audience) {
      setLoading(true);

      const url = 'AJAX/reports_monthly_data.php?month=' + encodeURIComponent(month) + '&audience=' + encodeURIComponent(audience);
      const res = await fetch(url, { headers: { 'Accept': 'application/json' } });
      if (!res.ok) throw new Error('Request failed');

      const json = await res.json();
      setLoading(false);

      if (!json || !json.ok) throw new Error('Bad response');
      return json;
    }

    function renderStats(stats) {
      statTotal.textContent = stats.total;
      statMinor.textContent = stats.minor;
      statMajor.textContent = stats.major;
      statActive.textContent = stats.active_cases;

      statMinorSub.textContent = percent(stats.minor, stats.total) + '% of total';
      statMajorSub.textContent = percent(stats.major, stats.total) + '% of total';
    }

    function renderBreakdown(breakdown) {
      const pie = breakdown.pie;
      const labels = Array.isArray(pie.labels) ? pie.labels : [];
      const colors = Array.isArray(pie.colors) ? pie.colors : [];

      const colorByLabel = {};
      labels.forEach((label, i) => {
        colorByLabel[String(label)] = String(colors[i] || '#6c757d');
      });

      const ctx = document.getElementById('pie');
      if (pieChart) pieChart.destroy();
      pieChart = new Chart(ctx, {
        type: 'pie',
        data: {
          labels: labels,
          datasets: [{ data: pie.counts, backgroundColor: colors }]
        },
        options: {
          responsive: true,
          plugins: {
            legend: { display: false }
          }
        }
      });

      if (!breakdown.detailed || breakdown.detailed.length === 0) {
        breakdownList.innerHTML = '<div class="muted">No offenses recorded for this month.</div>';
        return;
      }

      breakdownList.innerHTML = breakdown.detailed.map(d => `
        <div class="breakdown-row">
          <span class="breakdown-left">
            <span class="breakdown-dot" style="background:${escapeHtml(colorByLabel[String(d.name)] || '#6c757d')}"></span>
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
            backgroundColor: 'rgba(160,160,160,.55)',
            borderColor: 'rgba(160,160,160,.85)',
            borderWidth: 1.2
          }]
        },
        options: {
          responsive: true,
          scales: { y: { beginAtZero: true, ticks: { precision: 0 } } },
          plugins: { legend: { display: false } }
        }
      });

      if (!courses.list || courses.list.length === 0) {
        courseList.innerHTML = '<div class="muted">No data for this month.</div>';
        return;
      }

      courseList.innerHTML = courses.list.map(c => `
        <div class="course-item">
          <div class="course-top">
            <span class="course-name">${escapeHtml(c.program)}</span>
            <span class="course-count">${escapeHtml(c.cnt)} offenses</span>
          </div>
          <div class="course-sections">Sections: ${escapeHtml((c.sections || []).join(', ') || 'N/A')}</div>
        </div>
      `).join('');
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
              tension: 0.35,
              fill: false,
              pointRadius: 3,
              borderWidth: 1.8,
              pointBackgroundColor: '#dc3545'
            },
            {
              label: 'Minor Offenses',
              data: trend.minor,
              borderColor: '#ffc107',
              tension: 0.35,
              fill: false,
              pointRadius: 3,
              borderWidth: 1.8,
              pointBackgroundColor: '#ffc107'
            }
          ]
        },
        options: {
          responsive: true,
          plugins: { legend: { position: 'bottom' } },
          scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
        }
      });
    }

    async function refresh(month, audience) {
      try {
        const data = await loadReport(month, audience);
        renderStats(data.stats);
        renderBreakdown(data.breakdown);
        renderCourses(data.courses);
        renderTrend(data.trend);

        // IMPORTANT: export should download the SAME chosen month/year
        if (exportBtn) {
          exportBtn.href = 'AJAX/export_monthly_report_xlsx.php?month=' + encodeURIComponent(month) + '&audience=' + encodeURIComponent(audience);
        }
      } catch (e) {
        setLoading(false);
        alert('Failed to load report data.');
      }
    }

    // initial load
    refresh(monthSelect.value, audienceSelect.value);

    // change month via AJAX (and sync export)
    monthSelect.addEventListener('change', () => refresh(monthSelect.value, audienceSelect.value));
    audienceSelect.addEventListener('change', () => refresh(monthSelect.value, audienceSelect.value));
  </script>
</body>
</html>

