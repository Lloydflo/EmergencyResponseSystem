<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
// Require full login (including OTP verification) before loading dashboard
require_login('index.php');

$apiKey = "225acf0f31b12ee9281d3aa19c94a57e";
$city   = "Quezon";

$url = "https://api.openweathermap.org/data/2.5/weather?q=Quezon,PH&units=metric&appid=225acf0f31b12ee9281d3aa19c94a57e";
$response = @file_get_contents($url);
$data = json_decode($response, true);

if ($data && $data['cod'] == 200) {
    $location   = $data['name'];
    $condition  = ucwords($data['weather'][0]['description']);
    $temp       = round($data['main']['temp']);
    $humidity   = $data['main']['humidity'];
    $wind       = round($data['wind']['speed'] * 3.6); // m/s → km/h
    $visibility = isset($data['visibility']) ? round($data['visibility'] / 1000) : 'N/A';
} else {
    $location = "Quezon City";
    $condition = "Unavailable";
    $temp = "--";
    $humidity = "--";
    $wind = "--";
    $visibility = "--";
}
$pageTitle = 'ERS Admin Dashboard';
$typesCounts = ['medical'=>0,'fire'=>0,'police'=>0,'traffic'=>0];
$priorityCounts = ['high'=>0,'medium'=>0,'low'=>0];
try {
    require_once $rootDir . '/includes/db.php';
    $pdo = get_db_connection();
    $q1 = $pdo->query("SELECT type, COUNT(*) AS c FROM incidents GROUP BY type");
    foreach ($q1->fetchAll() as $r) { if (isset($typesCounts[$r['type']])) { $typesCounts[$r['type']] = (int)$r['c']; } }
    $q2 = $pdo->query("SELECT priority, COUNT(*) AS c FROM incidents GROUP BY priority");
    foreach ($q2->fetchAll() as $r) { if (isset($priorityCounts[$r['priority']])) { $priorityCounts[$r['priority']] = (int)$r['c']; } }
} catch (Throwable $e) {}
$activeIncidents = 0;
$availableResponders = 0;
$avgResponseTime = 0;
$pendingCalls = 0;
$totalIncidents = 0;
$resourceUtilization = 0;
// Load dashboard metrics and chart data from DB for accuracy
try {
    require_once $rootDir . '/includes/db.php';
    $pdo = get_db_connection();
    // Metrics
    $activeIncidents = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status IN ('pending','dispatched')")->fetch()['c'];
    $pendingCalls = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status='pending'")->fetch()['c'];
    $availableResponders = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status='available'")->fetch()['c'];
    $totalIncidents = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetch()['c'];
    // Charts
    $typesCounts = ['medical'=>0,'fire'=>0,'police'=>0,'traffic'=>0];
    $priorityCounts = ['high'=>0,'medium'=>0,'low'=>0];
    $q1 = $pdo->query("SELECT type, COUNT(*) AS c FROM incidents GROUP BY type");
    foreach ($q1->fetchAll() as $r) { if (isset($typesCounts[$r['type']])) { $typesCounts[$r['type']] = (int)$r['c']; } }
    $q2 = $pdo->query("SELECT priority, COUNT(*) AS c FROM incidents GROUP BY priority");
    foreach ($q2->fetchAll() as $r) {
        $p = $r['priority'] === 'critical' ? 'low' : $r['priority'];
        if (isset($priorityCounts[$p])) { $priorityCounts[$p] = (int)$r['c']; }
    }
} catch (Throwable $e) {}
?>


<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - <?php echo date('M d, Y'); ?></title>
    <base href="../">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="css/cards.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <!-- Include Admin Header Component -->
    <?php include $rootDir . '/includes/admin-header.php'; ?>
    <!-- ===================================
       MAIN CONTENT - Emergency Response System Dashboard
       =================================== -->
    <div class="main-content">
        <div class="main-container">
            <!-- Dashboard Header -->
            <div class="dashboard-header">
                <div>
                    <h1 class="dashboard-title">Emergency Response Dashboard</h1>
                    <p class="dashboard-subtitle">Real-time monitoring and system overview • <?php echo date('F j, Y g:i:s a'); ?></p>
                </div>
            </div>
            <!-- Key Metrics -->
            <div class="metrics-grid">
                <div class="metric-card critical">
                    <div class="metric-header">
                        <div>
                            <h3 class="metric-title">Active Incidents</h3>
                            <div class="metric-value"><?php echo $activeIncidents; ?></div>
                            <div class="metric-change positive">
                                <i class="fas fa-arrow-down"></i>
                            </div>
                        </div>
                        <div class="metric-icon fire">
                            <i class="fas fa-fire"></i>
                        </div>
                    </div>
                    <div class="metric-actions">
                        <button class="btn-metric" onclick="viewIncidents()">
                            <i class="fas fa-eye"></i> View All
                        </button>
                    </div>
                </div>
                <div class="metric-card success">
                    <div class="metric-header">
                        <div>
                            <h3 class="metric-title">Available Responders</h3>
                            <div class="metric-value"><?php echo $availableResponders; ?></div>
                            <div class="metric-change positive">
                                <i class="fas fa-arrow-up"></i>
                            </div>
                        </div>
                        <div class="metric-icon medical">
                            <i class="fas fa-truck-medical"></i>
                        </div>
                    </div>
                    <div class="metric-actions">
                        <button class="btn-metric" onclick="viewResponders()">
                            <i class="fas fa-users"></i> Manage
                        </button>
                    </div>
                </div>
                <div class="metric-card warning">
                    <div class="metric-header">
                        <div>
                            <h3 class="metric-title">Avg Response Time</h3>
                            <div class="metric-value"><?php echo number_format($avgResponseTime, 1); ?>m</div>
                            <div class="metric-change negative">
                                <i class="fas fa-arrow-up"></i>
                            </div>
                        </div>
                        <div class="metric-icon time">
                            <i class="fas fa-clock"></i>
                        </div>
                    </div>
                    <div class="metric-actions">
                        <button class="btn-metric" onclick="viewResponseTimes()">
                            <i class="fas fa-chart-line"></i> Analytics
                        </button>
                    </div>
                </div>
                <div class="metric-card info">
                    <div class="metric-header">
                        <div>
                            <h3 class="metric-title">Pending Calls</h3>
                            <div class="metric-value"><?php echo $pendingCalls; ?></div>
                            <div class="metric-change neutral">
                                <i class="fas fa-minus"></i>
                            </div>
                        </div>
                        <div class="metric-icon phone">
                            <i class="fas fa-phone-volume"></i>
                        </div>
                    </div>
                    <div class="metric-actions">
                        <button class="btn-metric" onclick="viewCalls()">
                            <i class="fas fa-phone"></i> Answer
                        </button>
                    </div>
                </div>
                <div class="metric-card success">
                    <div class="metric-header">
                        <div>
                            <h3 class="metric-title">Total Incidents (Month)</h3>
                            <div class="metric-value"><?php echo $totalIncidents; ?></div>
                            <div class="metric-change positive">
                                <i class="fas fa-arrow-up"></i>
                            </div>
                        </div>
                        <div class="metric-icon chart">
                            <i class="fas fa-chart-bar"></i>
                        </div>
                    </div>
                    <div class="metric-actions">
                        <button class="btn-metric" onclick="monthlyReport()">
                            <i class="fas fa-file-pdf"></i> Report
                        </button>
                        <button class="btn-metric" onclick="trendAnalysis()">
                            <i class="fas fa-chart-line"></i> Trends
                        </button>
                    </div>
                </div>
            </div>
            <!-- Main Dashboard Grid -->
            <div class="dashboard-grid">
                <!-- Main Panel -->
                <div class="main-panel">
                    <!-- Response Time Chart -->
                    <div class="chart-container">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #333;">Incidents by Type</h3>
                        </div>
                        <div style="position: relative; width: 100%; height: 320px;">
                            <canvas id="incidentsTypeBar" class="chart-canvas"></canvas>
                        </div>
                    </div>
                    <!-- Incident Distribution Chart -->
                    <div class="chart-container">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 1rem;">
                            <h3 style="margin: 0; font-size: 1.25rem; font-weight: 700; color: #333;">Incident Priority Distribution</h3>
                        </div>
                        <div style="position: relative; width: 100%; height: 320px;">
                            <canvas id="incidentsPriorityPie" class="chart-canvas"></canvas>
                        </div>
                    </div>
                </div>
                <!-- Side Panel -->
                <div class="side-panel">
                    <!-- Quick Actions -->
                    <div class="quick-actions">
                        <h3 class="quick-actions-title">Quick Actions</h3>
                        <div class="action-grid">
                            <button class="action-btn" onclick="emergencyCall()">
                                <i class="fas fa-phone-volume"></i>
                                <span>Emergency Call</span>
                            </button>
                            <button class="action-btn" onclick="dispatchUnit()">
                                <i class="fas fa-truck-medical"></i>
                                <span>Dispatch Unit</span>
                            </button>
                            <button class="action-btn" onclick="resourceCheck()">
                                <i class="fas fa-clipboard-check"></i>
                                <span>Resource Check</span>
                            </button>
                            <button class="action-btn" onclick="generateReport()">
                                <i class="fas fa-file-pdf"></i>
                                <span>Generate Report</span>
                            </button>
                        </div>
                    </div>
                    <!-- Weather Widget -->
<div class="weather-widget">
    <div class="weather-header">
        <div>
            <div class="weather-location"><?php echo $location; ?></div>
            <div class="weather-condition"><?php echo $condition; ?></div>
        </div>
        <i class="fa-solid fa-cloud"></i>
        <div class="weather-temp"><?php echo $temp; ?>°C</div>
    </div>

    <div style="display: flex; justify-content: space-between; margin-top: 1rem;">
        <div style="text-align: center;">
            <div style="font-weight: 600; color: #333;">Humidity</div>
            <div style="color: #666;"><?php echo $humidity; ?>%</div>
        </div>

        <div style="text-align: center;">
            <div style="font-weight: 600; color: #333;">Wind</div>
            <div style="color: #666;"><?php echo $wind; ?> km/h</div>
        </div>

        <div style="text-align: center;">
            <div style="font-weight: 600; color: #333;">Visibility</div>
            <div style="color: #666;"><?php echo $visibility; ?> km</div>
        </div>
    </div>
</div>
                </div>
            </div>
            <!-- Bottom Section -->
            <div class="dashboard-grid">
                <!-- Activity Feed -->
                <div class="activity-feed">
                    <div class="activity-header">
                        <h3 class="activity-title">Recent Activity</h3>
                        <button class="btn-metric" onclick="viewAllActivity()">
                            <i class="fas fa-external-link-alt"></i> View All
                        </button>
                    </div>
                    <div id="activity-feed-list" class="scrollable-feed">
                        <div class="activity-item"><div class="activity-content">Loading...</div></div>
                    </div>
                </div>
                <!-- Alerts Panel -->
                <div class="alerts-panel">
                    <div class="alerts-header">
                        <h3 class="alerts-title">Active Alerts</h3>
                        <button class="btn-metric" onclick="viewAllAlerts()">
                            <i class="fas fa-external-link-alt"></i> View All
                        </button>
                    </div>
                    <div id="alerts-panel-list">
                        <div class="alert-item info"><div class="alert-content">Loading...</div></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    <!-- Uncomment if already have content -->
    <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <script>
        // Lightweight notification helper used across dashboard
        function showNotification(message, type) {
            const kinds = { success: '#16a34a', info: '#2563eb', warning: '#f59e0b', error: '#ef4444' };
            const color = kinds[type] || kinds.info;
            let container = document.getElementById('ers-toast-container');
            if (!container) {
                container = document.createElement('div');
                container.id = 'ers-toast-container';
                container.style.position = 'fixed';
                container.style.top = '16px';
                container.style.right = '16px';
                container.style.zIndex = '10000';
                container.style.display = 'flex';
                container.style.flexDirection = 'column';
                container.style.gap = '8px';
                document.body.appendChild(container);
            }
            const toast = document.createElement('div');
            toast.textContent = message;
            toast.style.background = '#fff';
            toast.style.border = '1px solid ' + color;
            toast.style.boxShadow = '0 4px 12px rgba(0,0,0,0.12)';
            toast.style.color = '#111';
            toast.style.padding = '10px 12px';
            toast.style.borderRadius = '8px';
            toast.style.minWidth = '220px';
            toast.style.fontSize = '14px';
            toast.style.fontWeight = '600';
            toast.style.borderLeft = '6px solid ' + color;
            toast.style.pointerEvents = 'auto';
            toast.style.transition = 'opacity 0.25s ease';
            container.appendChild(toast);
            setTimeout(() => { toast.style.opacity = '0'; }, 2500);
            setTimeout(() => { if (toast.parentNode) toast.parentNode.removeChild(toast); }, 2800);
        }
        // Charts data from PHP
        const typesLabels = ['Medical','Fire','Police','Traffic'];
        const typesValues = <?php echo json_encode([
            $typesCounts['medical'] ?? 0,
            $typesCounts['fire'] ?? 0,
            $typesCounts['police'] ?? 0,
            $typesCounts['traffic'] ?? 0,
        ]); ?>;
        const priorityLabels = ['High','Medium','Low'];
        const priorityValues = <?php echo json_encode(array_values($priorityCounts)); ?>;

        document.addEventListener('DOMContentLoaded', () => {
            // Bar: incidents per type
            const barCtx = document.getElementById('incidentsTypeBar');
            if (barCtx) {
                new Chart(barCtx, {
                    type: 'bar',
                    data: {
                        labels: typesLabels,
                        datasets: [{
                            label: 'Incidents by Type',
                            data: typesValues,
                            backgroundColor: ['#ef4444','#f59e0b','#3b82f6','#22c55e'],
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: { legend: { display: false } },
                        scales: { y: { beginAtZero: true, ticks: { precision: 0 } } }
                    }
                });
            }

            // Pie: incidents by priority
            const pieCtx = document.getElementById('incidentsPriorityPie');
            if (pieCtx) {
                new Chart(pieCtx, {
                    type: 'pie',
                    data: {
                        labels: priorityLabels,
                        datasets: [{
                            label: 'Incidents by Priority',
                            data: priorityValues,
                            backgroundColor: [
                                '#fed7aa', // High (bg)
                                '#bfdbfe', // Medium (bg)
                                '#d1fae5'  // Low (bg)
                            ],
                            borderColor: [
                                '#92400e', // High (text)
                                '#1e40af', // Medium (text)
                                '#065f46'  // Low (text)
                            ],
                            borderWidth: 2
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: false }
                });
            }
        });

        // Dashboard: Recent Activity and Active Alerts
        function escapeHtml(str){
            return String(str || '')
                .replace(/&/g,'&amp;')
                .replace(/</g,'&lt;')
                .replace(/>/g,'&gt;')
                .replace(/"/g,'&quot;')
                .replace(/'/g,'&#039;');
        }

        function formatTime(ts){
            try {
                const d = new Date(ts);
                if (!isNaN(d.getTime())) return d.toLocaleString();
            } catch(e){}
            return String(ts || '');
        }

        function timeAgo(ts){
            try {
                const d = new Date(ts);
                const now = new Date();
                const diff = Math.max(0, (now - d) / 1000);
                if (diff < 60) return `${Math.floor(diff)}s ago`;
                if (diff < 3600) return `${Math.floor(diff/60)}m ago`;
                if (diff < 86400) return `${Math.floor(diff/3600)}h ago`;
                return `${Math.floor(diff/86400)}d ago`;
            } catch(e){ return String(ts || ''); }
        }

        async function loadActivityFeed(){
            const container = document.getElementById('activity-feed-list');
            if (!container) return;
            try {
                const r = await fetch('api/activity_feed.php');
                const j = await r.json();
                if (!j || !j.ok || !Array.isArray(j.data)) throw new Error('Invalid feed');
                if (!j.data.length){
                    container.innerHTML = '<div class="activity-item"><div class="activity-content" style="color:#777;">No recent activity.</div></div>';
                    return;
                }
                const items = j.data.map(it => {
                    const user = escapeHtml(it.username || ('User #' + (it.user_id || '')));
                    const action = escapeHtml(it.action || 'Activity');
                    const entity = escapeHtml(it.entity_type || 'system');
                    const details = escapeHtml(it.details || '');
                    const when = timeAgo(it.created_at || '');
                    return `<div class=\"activity-item\"><div class=\"activity-content\"><strong>${action}</strong> <span style=\"color:#666;\">(${entity})</span>${details?': '+details:''}<br><span style=\"color:#888;\">by ${user} • ${when}</span></div></div>`;
                });
                container.innerHTML = items.join('');
            } catch(e){
                container.innerHTML = '<div class="activity-item"><div class="activity-content" style="color:#b91c1c;">Failed to load activity.</div></div>';
            }
        }

        async function loadAlertsPanel(){
            const container = document.getElementById('alerts-panel-list');
            if (!container) return;
            try {
                const condition = <?php echo json_encode($condition ?? null); ?>;
                const url = 'api/alerts_active.php' + (condition? ('?condition=' + encodeURIComponent(condition)) : '');
                const r = await fetch(url);
                const j = await r.json();
                if (!j || !j.ok || !Array.isArray(j.data)) throw new Error('Invalid alerts');
                if (!j.data.length){
                    container.innerHTML = '<div class="alert-item info"><div class="alert-content" style="color:#777;">No active alerts.</div></div>';
                    return;
                }
                const items = j.data.map(a => {
                    const type = (a.type || 'info').toLowerCase();
                    const title = escapeHtml(a.title || 'Alert');
                    const details = escapeHtml(a.details || '');
                    const badgeColor = type==='critical' ? '#dc2626' : (type==='warning' ? '#f59e0b' : '#2563eb');
                    const badge = `<span style=\"display:inline-block;margin-right:8px;padding:2px 6px;border-radius:10px;background:${badgeColor};color:#fff;font-size:11px;font-weight:700;\">${type.toUpperCase()}</span>`;
                    return `<div class=\"alert-item ${type}\"><div class=\"alert-content\">${badge}<strong>${title}</strong>${details?': '+details:''}</div></div>`;
                });
                container.innerHTML = items.join('');
            } catch(e){
                container.innerHTML = '<div class="alert-item error"><div class="alert-content" style="color:#b91c1c;">Failed to load alerts.</div></div>';
            }
        }

        function viewAllActivity(){
            window.open('api/activity_feed.php?all=1', '_blank');
        }

        function viewAllAlerts(){
            const condition = <?php echo json_encode($condition ?? null); ?>;
            const url = 'api/alerts_active.php?all=1' + (condition? ('&condition=' + encodeURIComponent(condition)) : '');
            window.open(url, '_blank');
        }

        // Initial load + auto-refresh every 15s
        document.addEventListener('DOMContentLoaded', () => {
            loadActivityFeed();
            loadAlertsPanel();
            setInterval(loadActivityFeed, 15000);
            setInterval(loadAlertsPanel, 15000);
        });
        // Emergency Response System Dashboard Functionality
        // Dashboard action functions
        function refreshDashboard() {
            showNotification('Refreshing dashboard data...', 'info');
            setTimeout(() => {
                // Simulate data refresh with random updates
                const metrics = document.querySelectorAll('.metric-value');
                metrics.forEach(metric => {
                    const currentValue = parseFloat(metric.textContent.replace(/[^\d.]/g, ''));
                    if (!isNaN(currentValue)) {
                        const change = (Math.random() - 0.5) * 0.05; // ±2.5% change
                        const newValue = Math.max(0, currentValue * (1 + change));
                        if (metric.textContent.includes('m')) {
                            metric.textContent = newValue.toFixed(1) + 'm';
                        } else if (metric.textContent.includes('%')) {
                            metric.textContent = newValue.toFixed(1) + '%';
                        } else {
                            metric.textContent = Math.round(newValue);
                        }
                    }
                });
                showNotification('Dashboard refreshed successfully', 'success');
            }, 1500);
        }
        function exportDashboard() {
            showNotification('Generating dashboard report...', 'info');
            setTimeout(() => {
                showNotification('Dashboard report downloaded successfully', 'success');
            }, 2000);
        }
        function systemSettings() {
            showNotification('Opening system settings...', 'info');
            setTimeout(() => {
                showNotification('System settings panel loaded', 'success');
            }, 800);
        }
        // Metric action functions
        function viewIncidents() {
            window.location.href = 'incident.php';
        }
        function createIncident() {
            showNotification('Opening incident creation form...', 'info');
            setTimeout(() => {
                showNotification('Incident creation form loaded', 'success');
            }, 500);
        }
        function viewResponders() {
            window.location.href = 'resources.php';
        }
        function deployResponder() {
            showNotification('Opening deployment interface...', 'info');
            setTimeout(() => {
                showNotification('Deployment interface loaded', 'success');
            }, 600);
        }
        function viewResponseTimes() {
            window.location.href = 'report.php';
        }
        function optimizeRoutes() {
            window.location.href = 'gps.php';
        }
        function viewCalls() {
            window.location.href = 'call.php';
        }
        function callHistory() {
            showNotification('Opening call history...', 'info');
            setTimeout(() => {
                showNotification('Call history loaded', 'success');
            }, 700);
        }
        function monthlyReport() {
            window.location.href = 'report.php';
        }
        function trendAnalysis() {
            var modal = document.getElementById('trendModal');
            if (modal) modal.style.display = 'flex';
            // Set default date range (last 30 days)
            const end = new Date();
            const start = new Date();
            start.setDate(end.getDate() - 29);
            document.getElementById('trendStart').value = start.toISOString().slice(0,10);
            document.getElementById('trendEnd').value = end.toISOString().slice(0,10);
            loadTrendData();
        }
        function closeTrendModal() {
            var modal = document.getElementById('trendModal');
            if (modal) modal.style.display = 'none';
        }
        function loadTrendData() {
            const start = document.getElementById('trendStart').value;
            const end = document.getElementById('trendEnd').value;
            document.getElementById('trendLoading').style.display = '';
            document.getElementById('trendNoData').style.display = 'none';
            fetch(`api/trend_data.php?start=${start}&end=${end}`)
                .then(r=>r.json())
                .then(data => {
                    document.getElementById('trendLoading').style.display = 'none';
                    if (!data.ok || !data.labels.length) {
                        document.getElementById('trendNoData').style.display = '';
                        if(window._trendChartInstance) window._trendChartInstance.destroy();
                        return;
                    }
                    document.getElementById('trendNoData').style.display = 'none';
                    if(window._trendChartInstance) window._trendChartInstance.destroy();
                    const ctx = document.getElementById('trendChart').getContext('2d');
                    window._trendChartInstance = new Chart(ctx, {
                        type: 'line',
                        data: {
                            labels: data.labels,
                            datasets: [{
                                label: 'Total Incidents',
                                data: data.values,
                                borderColor: '#007bff',
                                backgroundColor: 'rgba(0,123,255,0.08)',
                                fill: true,
                                tension: 0.3,
                                pointRadius: 4,
                                pointBackgroundColor: '#007bff',
                                pointBorderColor: '#fff',
                                pointHoverRadius: 6
                            }]
                        },
                        options: {
                            responsive: false,
                            plugins: { legend: { display: false } },
                            scales: {
                                y: { beginAtZero: true, ticks: { stepSize: 1 } }
                            }
                        }
                    });
                });
        }
        (function(){
            const trendForm = document.getElementById('trendFilterForm');
            if (trendForm) {
                trendForm.addEventListener('submit', function(e){
                    e.preventDefault();
                    loadTrendData();
                });
            }
        })();
        function systemHealth() {
            showNotification('Running system health check...', 'info');
            setTimeout(() => {
                showNotification('All systems operational', 'success');
            }, 1000);
        }
        function systemLogs() {
            showNotification('Opening system logs...', 'info');
            setTimeout(() => {
                showNotification('System logs loaded', 'success');
            }, 600);
        }
        // Chart functions
        function refreshChart() {
            showNotification('Refreshing chart data...', 'info');
            setTimeout(() => {
                showNotification('Chart data updated', 'success');
            }, 1000);
        }
        function exportChart() {
            // Gather dashboard metrics from DOM
            const getMetric = (selector) => {
                const el = document.querySelector(selector);
                return el ? el.textContent.trim() : '--';
            };
            const activeIncidents = getMetric('.metric-card.critical .metric-value');
            const pendingCalls = getMetric('.metric-card.warning .metric-value');
            const availableResponders = getMetric('.metric-card.success .metric-value');
            const totalIncidents = getMetric('.metric-card.info .metric-value');

            // Chart data
            const labels = ['Medical','Fire','Police','Traffic'];
            const values = (typeof typesValues !== 'undefined') ? typesValues : [0,0,0,0];

            // Build summary HTML
            let printContent = `
                <h2 style="margin-bottom:0.5em;">ERS Dashboard Summary</h2>
                <table border="1" cellpadding="8" style="border-collapse:collapse;width:100%;margin-bottom:1.5em;">
                    <tr><th>Metric</th><th>Value</th></tr>
                    <tr><td>Active Incidents</td><td>${activeIncidents}</td></tr>
                    <tr><td>Pending Calls</td><td>${pendingCalls}</td></tr>
                    <tr><td>Available Responders</td><td>${availableResponders}</td></tr>
                    <tr><td>Total Incidents (This Month)</td><td>${totalIncidents}</td></tr>
                </table>
                <h3 style="margin-bottom:0.5em;">Incidents by Type</h3>
                <table border="1" cellpadding="8" style="border-collapse:collapse;width:100%;">
                    <tr><th>Type</th><th>Count</th></tr>
            `;
            for (let i = 0; i < labels.length; i++) {
                printContent += `<tr><td>${labels[i]}</td><td>${values[i]}</td></tr>`;
            }
            printContent += '</table>';

            // Open print window
            const printWindow = window.open('', '', 'width=800,height=600');
            printWindow.document.write('<html><head><title>ERS Dashboard Summary</title></head><body style="font-family:sans-serif;">' + printContent + '</body></html>');
            printWindow.document.close();
            printWindow.focus();
            printWindow.print();
            // Optionally, do not auto-close so user can save as PDF
        }
        function toggleChartView() {
            showNotification('Switching chart view...', 'info');
            setTimeout(() => {
                showNotification('Chart view updated', 'success');
            }, 500);
        }
        function filterChart() {
            showNotification('Opening chart filters...', 'info');
            setTimeout(() => {
                showNotification('Chart filters applied', 'success');
            }, 400);
        }
        // Quick action functions
        function emergencyCall() {
            window.location.href = 'call.php';
        }
        function dispatchUnit() {
            window.location.href = 'dispatch.php';
        }
        function alertAllUnits() {
            if (confirm('Send emergency alert to all units? This will interrupt current operations.')) {
                showNotification('Emergency alert sent to all units', 'error');
            }
        }
        function systemTest() {
            showNotification('Running system diagnostic test...', 'info');
            setTimeout(() => {
                showNotification('System test completed successfully', 'success');
            }, 3000);
        }
        function resourceCheck() {
            window.location.href = 'resources.php';
        }
        function generateReport() {
            window.location.href = 'report.php';
        }
        // Activity and alert functions
        function viewAllActivity() {
            var modal = document.getElementById('activityModal');
            if (modal) modal.style.display = 'flex';
            document.getElementById('activityModalLoading').style.display = '';
            document.getElementById('activityModalNoData').style.display = 'none';
            document.getElementById('activityModalList').innerHTML = '';
            fetch('api/activity_feed.php?all=1')
                .then(r=>r.json())
                .then(data => {
                    document.getElementById('activityModalLoading').style.display = 'none';
                    if (!data.ok || !data.data.length) {
                        document.getElementById('activityModalNoData').style.display = '';
                        return;
                    }
                    document.getElementById('activityModalNoData').style.display = 'none';
                    document.getElementById('activityModalList').innerHTML = data.data.map(renderActivityItem).join('');
                });
        }
        function closeActivityModal() {
            var modal = document.getElementById('activityModal');
            if (modal) modal.style.display = 'none';
        }
        // Helper: time ago
        function timeAgo(dateStr) {
            const now = new Date();
            const then = new Date(dateStr);
            const diff = Math.floor((now - then) / 1000);
            if (diff < 60) return diff + ' seconds ago';
            if (diff < 3600) return Math.floor(diff/60) + ' minutes ago';
            if (diff < 86400) return Math.floor(diff/3600) + ' hours ago';
            return then.toLocaleString();
        }

        // Render a single activity item
        function renderActivityItem(a) {
            const action = (a.action || '').toLowerCase();
            const icon = action.includes('call') ? 'fa-phone' : action.includes('incident') ? 'fa-exclamation-triangle' : action.includes('unit') ? 'fa-truck' : 'fa-info-circle';
            const actor = a.username ? ` by ${a.username}` : '';
            const when = a.created_at ? timeAgo(a.created_at) : '';
            const details = a.details || '';
            const entity = a.entity_type ? a.entity_type : 'system';
            return `
                <div class="activity-item">
                    <div class="activity-icon"><i class="fas ${icon}"></i></div>
                    <div class="activity-content">
                        <div class="activity-title">${escapeHtml(action)} ${escapeHtml(entity)}${escapeHtml(actor)}</div>
                        <div class="activity-details">${escapeHtml(details)}</div>
                        <div class="activity-time">${escapeHtml(when)}</div>
                    </div>
                </div>
            `;
        }

        // Load recent activity
        function loadActivityFeed() {
            fetch('api/activity_feed.php')
                .then(r => r.json())
                .then(data => {
                    const el = document.getElementById('activity-feed-list');
                    if (!el) return;
                    if (!data.ok || !data.data || !data.data.length) {
                        el.innerHTML = '<div class="activity-item"><div class="activity-content">No recent activity.</div></div>';
                        return;
                    }
                    el.innerHTML = data.data.map(renderActivityItem).join('');
                })
                .catch(() => {
                    const el = document.getElementById('activity-feed-list');
                    if (el) el.innerHTML = '<div class="activity-item"><div class="activity-content">Failed to load activity.</div></div>';
                });
        }

        // Render a single alert item
        function renderAlertItem(a) {
            const type = (a.type || 'info').toLowerCase();
            const icon = type === 'critical' ? 'fa-exclamation-circle' : type === 'warning' ? 'fa-exclamation-triangle' : 'fa-info-circle';
            const title = a.title || 'Alert';
            const details = a.details || '';
            return `
                <div class="alert-item ${escapeHtml(type)}">
                    <div class="alert-icon"><i class="fas ${icon}"></i></div>
                    <div class="alert-content">
                        <div class="alert-title">${escapeHtml(title)}</div>
                        <div class="alert-details">${escapeHtml(details)}</div>
                    </div>
                </div>
            `;
        }

        // Load active alerts
        function loadAlertsPanel() {
            const condition = encodeURIComponent('<?php echo $condition; ?>');
            fetch('api/alerts_active.php?condition=' + condition)
                .then(r => r.json())
                .then(data => {
                    const el = document.getElementById('alerts-panel-list');
                    if (!el) return;
                    if (!data.ok || !data.data || !data.data.length) {
                        el.innerHTML = '<div class="alert-item info"><div class="alert-content">No active alerts.</div></div>';
                        return;
                    }
                    el.innerHTML = data.data.map(renderAlertItem).join('');
                })
                .catch(() => {
                    const el = document.getElementById('alerts-panel-list');
                    if (el) el.innerHTML = '<div class="alert-item info"><div class="alert-content">Failed to load alerts.</div></div>';
                });
        }
        // Initial load
        document.addEventListener('DOMContentLoaded', function() {
            showNotification('Dashboard loaded successfully', 'success');
            loadActivityFeed();
            loadAlertsPanel();
            // Auto-refresh panels periodically
            setInterval(() => { try { loadActivityFeed(); } catch(e){} }, 15000);
            setInterval(() => { try { loadAlertsPanel(); } catch(e){} }, 15000);
        });
        </script>
</body>
</html>
<!-- Trend Analysis Modal -->
<div id="trendModal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.45);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;padding:2rem 2.5rem 1.5rem 2.5rem;border-radius:12px;max-width:540px;width:98vw;box-shadow:0 8px 32px rgba(0,0,0,0.18);position:relative;">
    <button onclick="closeTrendModal()" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:1.5rem;cursor:pointer;color:#888;">&times;</button>
    <h2 style="margin-top:0;margin-bottom:1.2rem;font-size:1.3rem;color:#222;text-align:center;">Incident Trends (Daily)</h2>
    <form id="trendFilterForm" style="display:flex;gap:1em;align-items:center;justify-content:center;margin-bottom:1.2em;flex-wrap:wrap;">
      <label style="font-size:1em;color:#333;">From: <input type="date" id="trendStart" required></label>
      <label style="font-size:1em;color:#333;">To: <input type="date" id="trendEnd" required></label>
      <button type="submit" style="background:#007bff;color:#fff;border:none;border-radius:6px;padding:0.4em 1.2em;font-size:1em;cursor:pointer;">Show</button>
    </form>
    <canvas id="trendChart" width="440" height="220"></canvas>
    <div id="trendLoading" style="display:none;text-align:center;color:#888;margin-top:1em;">Loading...</div>
    <div id="trendNoData" style="display:none;text-align:center;color:#888;margin-top:1em;">No data for selected range.</div>
  </div>
</div>
<style>
#trendFilterForm label {
  font-size: 1em;
  color: #333;
  display: flex;
  align-items: center;
  gap: 0.4em;
  margin-bottom: 0;
}
#trendFilterForm input[type="date"] {
  padding: 0.35em 0.7em;
  border: 1px solid #bbb;
  border-radius: 6px;
  font-size: 1em;
  background: #f8f9fa;
  color: #222;
  outline: none;
  transition: border 0.2s;
}
#trendFilterForm input[type="date"]:focus {
  border: 1.5px solid #007bff;
  background: #fff;
}
#trendFilterForm button[type="submit"] {
  background: #007bff;
  color: #fff;
  border: none;
  border-radius: 6px;
  padding: 0.45em 1.3em;
  font-size: 1em;
  font-weight: 600;
  cursor: pointer;
  margin-left: 0.5em;
  transition: background 0.2s;
}
#trendFilterForm button[type="submit"]:hover {
  background: #0056b3;
}
@media (max-width: 600px) {
  #trendFilterForm { flex-direction: column; gap: 0.7em; }
  #trendFilterForm button[type="submit"] { margin-left: 0; width: 100%; }
}
</style>
<script>
function trendAnalysis() {
    var modal = document.getElementById('trendModal');
    if (modal) modal.style.display = 'flex';
    // Set default date range (last 30 days)
    const end = new Date();
    const start = new Date();
    start.setDate(end.getDate() - 29);
    document.getElementById('trendStart').value = start.toISOString().slice(0,10);
    document.getElementById('trendEnd').value = end.toISOString().slice(0,10);
    loadTrendData();
}
function closeTrendModal() {
    var modal = document.getElementById('trendModal');
    if (modal) modal.style.display = 'none';
}
function loadTrendData() {
    const start = document.getElementById('trendStart').value;
    const end = document.getElementById('trendEnd').value;
    document.getElementById('trendLoading').style.display = '';
    document.getElementById('trendNoData').style.display = 'none';
    fetch(`api/trend_data.php?start=${start}&end=${end}`)
        .then(r=>r.json())
        .then(data => {
            document.getElementById('trendLoading').style.display = 'none';
            if (!data.ok || !data.labels.length) {
                document.getElementById('trendNoData').style.display = '';
                if(window._trendChartInstance) window._trendChartInstance.destroy();
                return;
            }
            document.getElementById('trendNoData').style.display = 'none';
            if(window._trendChartInstance) window._trendChartInstance.destroy();
            const ctx = document.getElementById('trendChart').getContext('2d');
            window._trendChartInstance = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: [{
                        label: 'Total Incidents',
                        data: data.values,
                        borderColor: '#007bff',
                        backgroundColor: 'rgba(0,123,255,0.08)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 4,
                        pointBackgroundColor: '#007bff',
                        pointBorderColor: '#fff',
                        pointHoverRadius: 6
                    }]
                },
                options: {
                    responsive: false,
                    plugins: { legend: { display: false } },
                    scales: {
                        y: { beginAtZero: true, ticks: { stepSize: 1 } }
                    }
                }
            });
        });
}
(function(){
    const trendForm = document.getElementById('trendFilterForm');
    if (trendForm) {
        trendForm.addEventListener('submit', function(e){
            e.preventDefault();
            loadTrendData();
        });
    }
})();
</script>
</body>
</html>
<!-- Activity Feed Modal -->
<div id="activityModal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.45);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;padding:2rem 2.5rem 1.5rem 2.5rem;border-radius:12px;max-width:700px;width:98vw;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18);position:relative;">
    <button onclick="closeActivityModal()" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:1.5rem;cursor:pointer;color:#888;">&times;</button>
    <h2 style="margin-top:0;margin-bottom:1.2rem;font-size:1.3rem;color:#222;text-align:center;">All System Activity</h2>
    <div id="activityModalList"></div>
    <div id="activityModalLoading" style="display:none;text-align:center;color:#888;margin-top:1em;">Loading...</div>
    <div id="activityModalNoData" style="display:none;text-align:center;color:#888;margin-top:1em;">No activity found.</div>
  </div>
</div>
<!-- Alerts Feed Modal -->
<div id="alertsModal" style="display:none;position:fixed;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.45);z-index:9999;align-items:center;justify-content:center;">
  <div style="background:#fff;padding:2rem 2.5rem 1.5rem 2.5rem;border-radius:12px;max-width:700px;width:98vw;max-height:90vh;overflow-y:auto;box-shadow:0 8px 32px rgba(0,0,0,0.18);position:relative;">
    <button onclick="closeAlertsModal()" style="position:absolute;top:12px;right:16px;background:none;border:none;font-size:1.5rem;cursor:pointer;color:#888;">&times;</button>
    <h2 style="margin-top:0;margin-bottom:1.2rem;font-size:1.3rem;color:#222;text-align:center;">All Active Alerts</h2>
    <div id="alertsModalList"></div>
    <div id="alertsModalLoading" style="display:none;text-align:center;color:#888;margin-top:1em;">Loading...</div>
    <div id="alertsModalNoData" style="display:none;text-align:center;color:#888;margin-top:1em;">No active alerts found.</div>
  </div>
</div>
<script>
function viewAllAlerts() {
    var modal = document.getElementById('alertsModal');
    if (modal) modal.style.display = 'flex';
    document.getElementById('alertsModalLoading').style.display = '';
    document.getElementById('alertsModalNoData').style.display = 'none';
    document.getElementById('alertsModalList').innerHTML = '';
    fetch('api/alerts_active.php?all=1')
        .then(r=>r.json())
        .then(data => {
            document.getElementById('alertsModalLoading').style.display = 'none';
            if (!data.ok || !data.data.length) {
                document.getElementById('alertsModalNoData').style.display = '';
                return;
            }
            document.getElementById('alertsModalNoData').style.display = 'none';
            document.getElementById('alertsModalList').innerHTML = data.data.map(renderAlertItem).join('');
        });
}
function closeAlertsModal() {
    var modal = document.getElementById('alertsModal');
    if (modal) modal.style.display = 'none';
}
</script>
