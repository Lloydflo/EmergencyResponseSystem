<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
// Require full login (including OTP verification) before loading page
require_login('report.php');
require_once $rootDir . '/includes/db.php';
$pageTitle = 'Analytics & Reporting';

// Initial metrics (server-side for first render)
$avgResponseTime = 0.0;
$totalIncidentsMonth = 0;
$lastMonthIncidents = 0;
$resourceUtilization = 0.0;
$successRate = 0.0;
$resolvedCountMonth = 0;
$activeResponders = 0;
try {
    $pdo = get_db_connection();
    if ($pdo) {
        $totalIncidentsMonth = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetch()['c'];
        $lastMonthIncidents = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE YEAR(created_at)=YEAR(DATE_SUB(CURDATE(), INTERVAL 1 MONTH)) AND MONTH(created_at)=MONTH(DATE_SUB(CURDATE(), INTERVAL 1 MONTH))")->fetch()['c'];
        $resolvedCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status='resolved'")->fetch()['c'];
        $resolvedCountMonth = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status='resolved' AND YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())")->fetch()['c'];
        $totalIncidentsAll = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents")->fetch()['c'];
        $successRate = $totalIncidentsAll > 0 ? round(($resolvedCount / $totalIncidentsAll) * 100, 1) : 0.0;
        $totalUnits = (int)$pdo->query("SELECT COUNT(*) AS c FROM units")->fetch()['c'];
        $busyUnits = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status IN ('assigned','enroute','on_scene')")->fetch()['c'];
        $activeResponders = (int)$pdo->query("SELECT COUNT(*) AS c FROM staff WHERE status IN ('available','on_duty')")->fetch()['c'];
        $resourceUtilization = $totalUnits > 0 ? round(($busyUnits / $totalUnits) * 100, 1) : 0.0;
        $row = $pdo->query("SELECT AVG(TIMESTAMPDIFF(MINUTE, assigned_at, on_scene_at)) AS avg_min FROM dispatches WHERE assigned_at IS NOT NULL AND on_scene_at IS NOT NULL AND YEAR(assigned_at)=YEAR(CURDATE()) AND MONTH(assigned_at)=MONTH(CURDATE())")->fetch();
        if ($row && $row['avg_min'] !== null) { $avgResponseTime = round((float)$row['avg_min'], 1); }
    }
} catch (Throwable $e) {
    // keep defaults if any error
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <base href="../">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="css/report.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include $rootDir . '/includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Emergency Response Analytics & Reporting
       =================================== -->
    <div class="main-content">
        <div class="main-container">


            <div style="height: 3.5rem;"></div>
            <!-- Key Metrics Overview -->
            <div class="analytics-grid">
                <div class="analytics-card response-time">
                    <div class="metric-label">Average Response Time</div>
                    <div class="metric-display">
                        <div class="metric-value" id="metricAvgResponse"><?php echo number_format($avgResponseTime, 1); ?></div>
                        <div class="metric-change positive">
                            <i class="fas fa-arrow-down"></i>
                            
                        </div>
                    </div>
                    <div style="color: #666; font-size: 0.9rem;">Target: &lt; 10m</div>
                </div>

                <div class="analytics-card incidents">
                    <div class="metric-label">Total Incidents (This Month)</div>
                    <div class="metric-display">
                        <div class="metric-value" id="metricIncidentsMonth"><?php echo (int)$totalIncidentsMonth; ?></div>
                        <div class="metric-change positive">
                            <i class="fas fa-arrow-up" id="metricIncidentsDelta"><?php echo max(0, (int)$totalIncidentsMonth - (int)$lastMonthIncidents); ?></i>
                            
                        </div>
                    </div>
                    <div style="color: #666; font-size: 0.9rem;">Last month: <span id="metricLastMonth"><?php echo (int)$lastMonthIncidents; ?></span></div>
                </div>

                <div class="analytics-card resources">
                    <div class="metric-label">Resource Utilization</div>
                    <div class="metric-display">
                        <div class="metric-value" id="metricUtilization"><?php echo number_format($resourceUtilization, 1); ?>%</div>
                        <div class="metric-change neutral">
                            <i class="fas fa-minus"></i>
                            
                        </div>
                    </div>
                    <div style="color: #666; font-size: 0.9rem;">Target: 70–85%</div>
                </div>

                <div class="analytics-card performance">
                    <div class="metric-label">Success Rate</div>
                    <div class="metric-display">
                        <div class="metric-value" id="metricSuccess"><?php echo number_format($successRate, 1); ?>%</div>
                        <div class="metric-change positive">
                            <i class="fas fa-arrow-up"></i>
                            
                        </div>
                    </div>
                    <div style="color: #666; font-size: 0.9rem;">Industry average: 92–96%</div>
                </div>
            </div>

            <!-- Report Filters -->
            <div class="report-filters">
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1.5rem; display: flex; align-items: center;">
                    <i class="fas fa-filter" style="margin-right: 0.5rem; color: #007bff;"></i>
                    Report Filters
                </h2>
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="report-type">Report Type</label>
                        <select id="report-type">
                            <option value="">All Reports</option>
                            <option value="incident">Incident Reports</option>
                            <option value="performance">Performance Reports</option>
                            <option value="resource">Resource Reports</option>
                            <option value="trend">Trend Analysis</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="time-period">Time Period</label>
                        <select id="time-period">
                            <option value="today">Today</option>
                            <option value="week">This Week</option>
                            <option value="month" selected>This Month</option>
                            <option value="quarter">This Quarter</option>
                            <option value="year">This Year</option>
                            <option value="custom">Custom Range</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="incident-type">Incident Type</label>
                        <select id="incident-type">
                            <option value="">All Types</option>
                            <option value="medical">Medical Emergency</option>
                            <option value="fire">Fire</option>
                            <option value="accident">Traffic Accident</option>
                            <option value="crime">Crime</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="priority-level">Priority Level</label>
                        <select id="priority-level">
                            <option value="">All Priorities</option>
                            <option value="high">High</option>
                            <option value="medium">Medium</option>
                            <option value="low">Low</option>
                        </select>
                    </div>
                </div>
                <div class="filter-row">
                    <div class="date-range">
                        <div class="filter-group">
                            <label for="start-date">Start Date</label>
                            <input type="date" id="start-date" value="">
                        </div>
                        <div class="filter-group">
                            <label for="end-date">End Date</label>
                            <input type="date" id="end-date" value="">
                        </div>
                        <button class="btn-report primary" onclick="applyFilters()">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <button class="btn-report" onclick="clearFilters()">
                            <i class="fas fa-eraser"></i> Clear Filters
                        </button>
                    </div>
                </div>
            </div>

            <!-- AI-Powered Insights -->
            <div class="ai-insights-section">
                <div class="ai-insights-card">
                    <div class="ai-insights-header">
                        <h2><i class="fas fa-brain"></i> AI-Powered Insights</h2>
                        <span class="ai-badge"><i class="fas fa-robot"></i> Powered by Gemini AI</span>
                    </div>
                    <div class="ai-insights-content" id="ai-insights-content">
                        <?php
                        include $rootDir . '/includes/gemini_helper.php';
                        $reportData = [
                            'total_incidents' => $totalIncidentsMonth,
                            'avg_response_time' => number_format($avgResponseTime, 1) . ' minutes',
                            'resource_utilization' => number_format($resourceUtilization, 1) . '%',
                            'active_responders' => $activeResponders,
                            'resolved_incidents' => $resolvedCountMonth,
                            'success_rate' => number_format($successRate, 1) . '%',
                        ];

                        $insights = generateReportInsights($reportData);
                        if ($insights) {
                            echo '<div class="ai-insight-text">' . nl2br(htmlspecialchars($insights)) . '</div>';
                        } else {
                            $aiError = function_exists('getGeminiLastError') ? trim((string) getGeminiLastError()) : '';
                            if ($aiError === '') {
                                $aiError = 'Unable to generate AI insights at this time.';
                            }
                            echo '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars($aiError) . '</div>';
                        }
                        ?>
                    </div>
                    <div class="ai-insights-actions">
                        <button class="btn-ai-refresh" onclick="refreshAIInsights()">
                            <i class="fas fa-sync"></i> Refresh Insights
                        </button>
                    </div>
                </div>
            </div>

            <!-- Quick Reports -->
            <div class="quick-reports">
                <div class="report-card" onclick="generateIncidentReport()">
                    <div class="report-icon incident">
                        <i class="fas fa-exclamation-triangle"></i>
                    </div>
                    <div class="report-title">Incident Summary Report</div>
                    <div class="report-description">Comprehensive overview of all incidents with response times and outcomes</div>
                    <div class="report-actions">
                        <button class="btn-report primary" onclick="generateIncidentReport()">
                            <i class="fas fa-file-pdf"></i> Generate
                        </button>
                        <button class="btn-report" onclick="viewIncidentReport()">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </div>
                </div>

                <div class="report-card" onclick="generatePerformanceReport()">
                    <div class="report-icon performance">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="report-title">Performance Analytics</div>
                    <div class="report-description">Team performance metrics, response times, and success rates</div>
                    <div class="report-actions">
                        <button class="btn-report primary" onclick="generatePerformanceReport()">
                            <i class="fas fa-file-pdf"></i> Generate
                        </button>
                        <button class="btn-report" onclick="viewPerformanceReport()">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </div>
                </div>

                <div class="report-card" onclick="generateResourceReport()">
                    <div class="report-icon resource">
                        <i class="fas fa-truck"></i>
                    </div>
                    <div class="report-title">Resource Utilization Report</div>
                    <div class="report-description">Equipment and personnel usage statistics and efficiency metrics</div>
                    <div class="report-actions">
                        <button class="btn-report primary" onclick="generateResourceReport()">
                            <i class="fas fa-file-pdf"></i> Generate
                        </button>
                        <button class="btn-report" onclick="viewResourceReport()">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </div>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-grid">
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Incident Response Times</h3>
                    <div class="chart-controls">
                        <button class="btn-report" onclick="refreshChart()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <button class="btn-report" onclick="exportChart('responseTimeChart')">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                <div style="position: relative; width: 100%; height: 320px;">
                    <canvas id="responseTimeChart" class="chart-canvas"></canvas>
                </div>
            </div>


            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Incident Types Distribution</h3>
                    <div class="chart-controls">
                        <button class="btn-report" onclick="toggleChartView()">
                            <i class="fas fa-pie-chart"></i> Toggle View
                        </button>
                        <button class="btn-report" onclick="exportChart('incidentsTypesChart')">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                <div style="position: relative; width: 100%; height: 320px;">
                    <canvas id="incidentsTypesChart" class="chart-canvas"></canvas>
                </div>
            </div>

            <!-- Call Duration Graph -->
            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Call Duration by Incident Type</h3>
                </div>
                <div style="position: relative; width: 100%; height: 320px;">
                    <canvas id="callDurationChart" class="chart-canvas"></canvas>
                </div>
            </div>

            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Dispatch Report</h3>
                    <div class="chart-controls">
                        <button class="btn-report" onclick="refreshDispatchReport()"><i class="fas fa-sync"></i> Refresh</button>
                        <button class="btn-report" onclick="window.open('api/reports_dispatch.php' + buildQuery(currentFilters), '_blank')"><i class="fas fa-file-pdf"></i> Open Full</button>
                    </div>
                </div>
                <div style="position: relative; width: 100%; height: 320px;">
                    <canvas id="dispatchDailyChart" class="chart-canvas"></canvas>
                </div>
            </div>
            </div>

            <!-- Dispatch Breakdown Table -->
            <div class="data-table">
                <div class="table-header">
                    <h3 class="table-title">Dispatch Breakdown</h3>
                </div>
                <div class="table-container">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Unit</th>
                                <th>Type</th>
                                <th>Dispatches</th>
                            </tr>
                        </thead>
                        <tbody id="dispatchTopUnitsBody">
                            <tr><td colspan="3" style="color:#6b7280">Loading dispatches…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Incidents Table -->
            <div class="data-table">
                <div class="table-header">
                    <h3 class="table-title">Recent Incidents</h3>
                </div>
                <div class="table-container">
                    <table class="analytics-table scrollable">
                        <thead>
                            <tr>
                                <th>Incident ID</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Priority</th>
                                <th>Response Time</th>
                                <th>Status</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody id="recentIncidentsBody">
                            <tr><td colspan="7" style="color:#6b7280">Loading incidents…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Performance Metrics Table -->
            <div class="data-table">
                <div class="table-header">
                    <h3 class="table-title">Performance Metrics</h3>
                </div>
                <div class="table-container">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Metric</th>
                                <th>Current Value</th>
                                <th>Target</th>
                                <th>Trend</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Average Response Time</td>
                                <td></td>
                                <td></td>
                                <td><div class="trend-indicator trend-up"><i class="fas fa-arrow-up"></i> Improving</div></td>
                                <td><span class="status-badge status-resolved">On Target</span></td>
                            </tr>
                            <tr>
                                <td>Incident Resolution Rate</td>
                                <td></td>
                                <td></td>
                                <td><div class="trend-indicator trend-up"><i class="fas fa-arrow-up"></i> Improving</div></td>
                                <td><span class="status-badge status-resolved">Excellent</span></td>
                            </tr>
                            <tr>
                                <td>Resource Utilization</td>
                                <td></td>
                                <td></td>
                                <td><div class="trend-indicator trend-neutral"><i class="fas fa-minus"></i> Stable</div></td>
                                <td><span class="status-badge status-resolved">Optimal</span></td>
                            </tr>
                            <tr>
                                <td>Equipment Downtime</td>
                                <td></td>
                                <td></td>
                                <td><div class="trend-indicator trend-down"><i class="fas fa-arrow-down"></i> Improving</div></td>
                                <td><span class="status-badge status-resolved">Excellent</span></td>
                            </tr>
                            <tr>
                                <td>Personnel Overtime</td>
                                <td></td>
                                <td></td>
                                <td><div class="trend-indicator trend-neutral"><i class="fas fa-minus"></i> Stable</div></td>
                                <td><span class="status-badge status-resolved">Acceptable</span></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- System Alerts -->
            <div class="alerts-section">
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1.5rem; display: flex; align-items: center;">
                    <i class="fas fa-bell" style="margin-right: 0.5rem; color: #ffc107;"></i>
                    System Alerts & Notifications
                </h2>
                <div id="alerts-dynamic"></div>
            </div>
    <script>
    // --- Dynamic Alerts & Notifications ---
    let LAST_ALERTS = [];
    async function fetchAlerts() {
        try {
            const res = await fetch('api/alerts_active.php');
            const data = await res.json();
            if (!data.ok) return [];
            return data.data || [];
        } catch (e) { return []; }
    }

    function alertHtml(alert, idx) {
        let icon = '<i class="fas fa-info-circle"></i>';
        let cls = '';
        if (alert.type === 'critical') { icon = '<i class="fas fa-exclamation-triangle"></i>'; cls = 'critical'; }
        else if (alert.type === 'warning') { icon = '<i class="fas fa-exclamation-circle"></i>'; cls = 'warning'; }
        else if (alert.type === 'info') { icon = '<i class="fas fa-info-circle"></i>'; cls = 'info'; }
        return `
            <div class="alert-item ${cls}" data-alert-idx="${idx}">
                <div class="alert-info">
                    <div class="alert-title">${icon} ${alert.title || 'Alert'}</div>
                    <div class="alert-details">${alert.details || ''}</div>
                </div>
                <div class="alert-actions">
                    <button class="btn-report" onclick="dismissAlert(event)"><i class="fas fa-times"></i> Dismiss</button>
                </div>
            </div>
        `;
    }

    function showAlertPopup(alert) {
        showNotification(`${alert.title}: ${alert.details}`, alert.type || 'info');
    }

    async function renderAlerts() {
        const alerts = await fetchAlerts();
        const container = document.getElementById('alerts-dynamic');
        if (!container) return;
        if (!alerts.length) {
            container.innerHTML = '<div style="color:#888;padding:1em;">No active alerts at this time.</div>';
        } else {
            container.innerHTML = alerts.map(alertHtml).join('');
        }
        // Show popup for new alerts
        if (LAST_ALERTS.length) {
            alerts.forEach(a => {
                if (!LAST_ALERTS.find(b => b.title === a.title && b.details === a.details)) {
                    showAlertPopup(a);
                }
            });
        } else {
            alerts.forEach(showAlertPopup);
        }
        LAST_ALERTS = alerts;
    }

    // Dismiss alert (removes from UI only)
    function dismissAlert(e) {
        const item = e.target.closest('.alert-item');
        if (item) item.style.display = 'none';
        showNotification('Alert dismissed', 'info');
    }

    // Poll for new alerts every 10s
    document.addEventListener('DOMContentLoaded', function() {
        renderAlerts();
        setInterval(renderAlerts, 10000);
    });
    </script>

        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <script>
        // Emergency Response Analytics & Reporting Functionality
        let currentFilters = {};
        function navigateTo(page, params = {}) {
            const qs = new URLSearchParams(Object.entries(params).filter(([k,v]) => v !== undefined && v !== null && v !== '')).toString();
            window.location.href = qs ? `${page}?${qs}` : page;
        }
        function buildQuery(params) {
            const entries = Object.entries(params).filter(function(p){ return p[1] !== undefined && p[1] !== null && p[1] !== ''; });
            const qs = new URLSearchParams(entries).toString();
            return qs ? ('?' + qs) : '';
        }
        function getFilters() {
            const reportType = document.getElementById('report-type') ? document.getElementById('report-type').value : '';
            const timePeriod = document.getElementById('time-period') ? document.getElementById('time-period').value : '';
            const incidentType = document.getElementById('incident-type') ? document.getElementById('incident-type').value : '';
            const priorityLevel = document.getElementById('priority-level') ? document.getElementById('priority-level').value : '';
            const startDate = document.getElementById('start-date') ? document.getElementById('start-date').value : '';
            const endDate = document.getElementById('end-date') ? document.getElementById('end-date').value : '';
            // Use API param names: period, type, priority, start, end
            const filters = {};
            if (timePeriod) filters.period = timePeriod;
            if (incidentType) filters.type = incidentType;
            if (priorityLevel) filters.priority = priorityLevel;
            if (startDate) filters.start = startDate;
            if (endDate) filters.end = endDate;
            return filters;
        }

        function isValidDateRange(start, end) {
            if (!start || !end) return true;
            const s = new Date(start);
            const e = new Date(end);
            return s <= e;
        }

        // Report generation functions
        function generateIncidentReport() {
            const qs = buildQuery(currentFilters);
            window.open('api/reports_incident_summary.php' + qs, '_blank');
        }

        function generatePerformanceReport() {
            const qs = buildQuery(currentFilters);
            window.open('api/reports_performance.php' + qs, '_blank');
        }

        function generateResourceReport() {
            const qs = buildQuery(currentFilters);
            window.open('api/reports_resources.php' + qs, '_blank');
        }

        function generateTrendReport() {
            const qs = buildQuery(currentFilters);
            window.open('api/reports_trends.php' + qs, '_blank');
        }

        // View report functions
        function viewIncidentReport() {
            const qs = buildQuery(currentFilters);
            window.open('api/reports_incident_summary.php' + qs, '_blank');
        }

        function viewPerformanceReport() {
            const qs = buildQuery(currentFilters);
            window.open('api/reports_performance.php' + qs, '_blank');
        }

        function viewResourceReport() {
            const qs = buildQuery(currentFilters);
            window.open('api/reports_resources.php' + qs, '_blank');
        }

        function viewTrendReport() {
            const qs = buildQuery(currentFilters);
            window.open('api/reports_trends.php' + qs, '_blank');
        }

        // AI Insights
        async function refreshAIInsights() {
            try {
                const container = document.getElementById('ai-insights-content');
                if (container) {
                    container.innerHTML = '<div class="ai-loading"><i class="fas fa-spinner"></i> Refreshing insights…</div>';
                }
                const res = await fetch('api/ai_report_insights.php');
                const data = await res.json();
                if (container) {
                    if (data.ok && data.text) {
                        container.innerHTML = '<div class="ai-insight-text">' + (data.text || '').replace(/\n/g,'<br>') + '</div>';
                        showNotification('AI insights refreshed', 'success');
                    } else {
                        const msg = (data && data.error) ? String(data.error) : 'Unable to generate AI insights at this time.';
                        container.innerHTML = '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> ' + msg.replace(/\n/g,'<br>') + '</div>';
                        showNotification('AI insights unavailable: ' + msg, 'error');
                    }
                }
            } catch (e) {
                showNotification('Failed to refresh AI insights', 'error');
            }
        }

        // Chart functions
        async function refreshChart() {
            try {
                showNotification('Refreshing chart data...', 'info');
                await Promise.all([refreshMetrics(currentFilters), refreshCharts(currentFilters)]);
                showNotification('Chart data updated', 'success');
            } catch (e) {
                showNotification('Failed to refresh charts', 'error');
            }
        }

        function exportChart(chartId) {
            try {
                const canvas = document.getElementById(chartId);
                if (!canvas) { showNotification('Chart not found', 'error'); return; }
                const dataUrl = canvas.toDataURL('image/png');
                const a = document.createElement('a');
                const titleEl = canvas.closest('.chart-container')?.querySelector('.chart-title');
                const title = titleEl ? titleEl.textContent.trim().replace(/\s+/g,'_').toLowerCase() : 'chart';
                a.href = dataUrl;
                a.download = `${title}.png`;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                showNotification('Chart exported successfully', 'success');
            } catch (e) {
                showNotification('Failed to export chart', 'error');
            }
        }

        function toggleChartView() {
            try {
                if (!typesChart) { showNotification('No chart to toggle', 'warning'); return; }
                const currentType = typesChart.config.type;
                const newType = currentType === 'doughnut' ? 'bar' : 'doughnut';
                const labels = typesChart.data.labels.slice();
                const data = typesChart.data.datasets[0].data.slice();
                typesChart.destroy();
                const ctx2 = document.getElementById('incidentsTypesChart');
                typesChart = new Chart(ctx2, {
                    type: newType,
                    data: {
                        labels,
                        datasets: [{
                            label: 'Incidents by Type',
                            data,
                            backgroundColor: ['#ef4444','#f59e0b','#3b82f6','#22c55e'],
                        }]
                    },
                    options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: newType !== 'bar' } }, scales: newType === 'bar' ? { y: { beginAtZero: true } } : {} }
                });
                showNotification('Chart view updated', 'success');
            } catch (e) {
                showNotification('Failed to toggle chart view', 'error');
            }
        }

        // Incident details (fetch + modal)
        async function viewIncidentDetails(id) {
            try {
                showNotification('Loading incident details...', 'info');
                const res = await fetch('api/incident_details.php?id=' + encodeURIComponent(id));
                const data = await res.json();
                if (data && (data.incident || data.data)) {
                    const inc = data.incident || data.data;
                    showIncidentDetailsModal(inc);
                } else {
                    showNotification('Incident details not found', 'error');
                }
            } catch (e) {
                showNotification('Failed to load incident details', 'error');
            }
        }

        function showIncidentDetailsModal(inc) {
            const overlay = document.createElement('div');
            overlay.className = 'incident-modal-overlay';
            const modal = document.createElement('div');
            modal.className = 'incident-modal';
            const reference = (inc.reference_no || '').toString();
            const title = (inc.title || '').toString();
            const type = (inc.type || '').toString();
            const location = (inc.location_address || '').toString();
            const lat = inc.latitude != null ? inc.latitude : '';
            const lng = inc.longitude != null ? inc.longitude : '';
            const priority = (inc.priority || '').toString();
            const status = (inc.status || '').toString();
            const description = (inc.description || '') || '';
            const created = (inc.created_at || '') || '';
            const updated = (inc.updated_at || '') || '';
            const resolved = (inc.resolved_at || '') || '';
            const units = (inc.units || inc.dispatched_units || []);
            const unitList = Array.isArray(units) ? units.map(u => (u.identifier || u.name || u)).join(', ') : (units || '');
            modal.innerHTML = `
                <div class="incident-modal-header">
                    <h3>Incident Details</h3>
                    <button class="incident-modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="incident-modal-body">
                    <div class="detail-row"><strong>Reference:</strong> <span>${reference || '—'}</span></div>
                    <div class="detail-row"><strong>Title:</strong> <span>${title || '—'}</span></div>
                    <div class="detail-row"><strong>Type:</strong> <span>${type || '—'}</span></div>
                    <div class="detail-row"><strong>Location:</strong> <span>${location || '—'}${(lat!=='' && lng!=='') ? ` (lat: ${lat}, lng: ${lng})` : ''}</span></div>
                    <div class="detail-row"><strong>Priority:</strong> <span>${priority ? priority.toUpperCase() : '—'}</span></div>
                    <div class="detail-row"><strong>Status:</strong> <span class="status-badge ${status.includes('resolve') ? 'status-resolved' : (status.includes('dispatch')||status.includes('pending')) ? 'status-pending' : ''}">${status || '—'}</span></div>
                    <div class="detail-row"><strong>Description:</strong> <span>${description || '—'}</span></div>
                    <div class="detail-grid">
                        <div><strong>Created:</strong><br><span>${created || '—'}</span></div>
                        <div><strong>Updated:</strong><br><span>${updated || '—'}</span></div>
                        <div><strong>Resolved:</strong><br><span>${resolved || '—'}</span></div>
                    </div>
                    <div class="detail-row"><strong>Units:</strong> <span>${unitList || '—'}</span></div>
                </div>
                <div class="incident-modal-footer">
                    <button class="btn-report" id="incident-modal-close-btn"><i class="fas fa-times"></i> Close</button>
                </div>
            `;
            overlay.appendChild(modal);
            document.body.appendChild(overlay);
            function closeModal(){ overlay.remove(); }
            overlay.addEventListener('click', (e) => { if (e.target === overlay) closeModal(); });
            modal.querySelector('.incident-modal-close').addEventListener('click', closeModal);
            modal.querySelector('#incident-modal-close-btn').addEventListener('click', closeModal);
            document.addEventListener('keydown', function escHandler(e){ if (e.key === 'Escape'){ closeModal(); document.removeEventListener('keydown', escHandler); } });
            // Styles
            const style = document.createElement('style');
            style.textContent = `
                .incident-modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 2000; }
                .incident-modal { background: var(--card-bg-1); border-radius: 12px; width: 90%; max-width: 640px; box-shadow: 0 10px 30px rgba(0,0,0,0.2); }
                .incident-modal-header { display:flex; justify-content: space-between; align-items:center; padding: 1rem 1.25rem; border-bottom: 1px solid #eee; }
                .incident-modal-header h3 { margin:0; font-size:1.1rem; font-weight:700; color:#333; }
                .incident-modal-close { background: transparent; border: none; font-size: 1.5rem; line-height: 1; cursor: pointer; color: #666; }
                .incident-modal-body { padding: 1rem 1.25rem; display: flex; flex-direction: column; gap: 0.5rem; }
                .detail-row { display:flex; gap:0.5rem; }
                .detail-row strong { width: 120px; color:#555; }
                .detail-grid { display:grid; grid-template-columns: repeat(2, minmax(0,1fr)); gap:0.75rem; margin-top:0.5rem; }
                .incident-modal-footer { padding: 1rem 1.25rem; border-top: 1px solid #eee; display:flex; justify-content:flex-end; }
                @media (max-width: 480px) { .detail-row strong { width: 90px; } .detail-grid { grid-template-columns: 1fr; } }
            `;
            document.head.appendChild(style);
        }

        // Filter functions
        function applyFilters() {
            const startDate = document.getElementById('start-date')?.value || '';
            const endDate = document.getElementById('end-date')?.value || '';
            if (!isValidDateRange(startDate, endDate)) {
                showNotification('End date must be on/after start date', 'error');
                return;
            }
            currentFilters = getFilters();
            showNotification('Applying filters to reports...', 'info');
            refreshMetrics(currentFilters);
            refreshCharts(currentFilters);
            loadRecentIncidents(currentFilters);
            setTimeout(() => {
                showNotification('Filters applied', 'success');
            }, 500);
        }

        function clearFilters() {
            const reportType = document.getElementById('report-type');
            const timePeriod = document.getElementById('time-period');
            const incidentType = document.getElementById('incident-type');
            const priorityLevel = document.getElementById('priority-level');
            const startDate = document.getElementById('start-date');
            const endDate = document.getElementById('end-date');

            if (reportType) reportType.value = '';
            if (timePeriod) timePeriod.value = 'month';
            if (incidentType) incidentType.value = '';
            if (priorityLevel) priorityLevel.value = '';
            if (startDate) startDate.value = '';
            if (endDate) endDate.value = '';

            currentFilters = getFilters();
            refreshMetrics(currentFilters);
            refreshCharts(currentFilters);
            loadRecentIncidents(currentFilters);
            showNotification('Filters cleared', 'success');
        }

        // Export functions
        function exportPDF() {
            showNotification('Generating PDF report...', 'info');
            setTimeout(() => {
                showNotification('PDF report downloaded successfully', 'success');
            }, 3000);
        }

        function exportExcel() {
            showNotification('Exporting data to Excel...', 'info');
            setTimeout(() => {
                showNotification('Excel file downloaded successfully', 'success');
            }, 2000);
        }

        function exportCSV() {
            showNotification('Exporting data to CSV...', 'info');
            setTimeout(() => {
                showNotification('CSV file downloaded successfully', 'success');
            }, 1500);
        }

        function exportJSON() {
            showNotification('Exporting data to JSON...', 'info');
            setTimeout(() => {
                showNotification('JSON file downloaded successfully', 'success');
            }, 1000);
        }

        function scheduleReport() {
            const frequency = prompt('How often should this report be generated?\n• Daily\n• Weekly\n• Monthly\n• Quarterly');
            if (frequency) {
                const email = prompt('Enter email address for report delivery:');
                if (email) {
                    showNotification(`Report scheduled for ${frequency.toLowerCase()} delivery to ${email}`, 'success');
                }
            }
        }

        function emailReport() {
            const email = prompt('Enter email address to send report to:');
            if (email) {
                showNotification(`Report sent to ${email}`, 'success');
            }
        }

        // Alert functions
        function investigateAlert() {
            showNotification('Opening investigation dashboard...', 'info');
            setTimeout(() => {
                showNotification('Investigation dashboard loaded', 'success');
            }, 1000);
        }

        function dismissAlert() {
            if (confirm('Dismiss this alert?')) {
                event.target.closest('.alert-item').style.display = 'none';
                showNotification('Alert dismissed', 'info');
            }
        }

        function viewResourceDetails() {
            showNotification('Opening resource utilization details...', 'info');
            setTimeout(() => {
                showNotification('Resource details loaded', 'success');
            }, 800);
        }

        function viewMonthlyReport() {
            showNotification('Opening monthly performance report...', 'info');
            setTimeout(() => {
                showNotification('Monthly report loaded', 'success');
            }, 1200);
        }

        // Notification system
        function showNotification(message, type) {
            // Remove existing notifications
            const existingNotifications = document.querySelectorAll('.notification');
            existingNotifications.forEach(notification => notification.remove());

            // Create notification element
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                border-radius: 8px;
                color: white;
                font-weight: 600;
                z-index: 1000;
                animation: slideIn 0.3s ease-out;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            `;

            // Set background color based on type
            if (type === 'success') {
                notification.style.backgroundColor = '#28a745';
            } else if (type === 'error') {
                notification.style.backgroundColor = '#dc3545';
            } else if (type === 'info') {
                notification.style.backgroundColor = '#17a2b8';
            } else if (type === 'warning') {
                notification.style.backgroundColor = '#ffc107';
            }

            notification.textContent = message;
            document.body.appendChild(notification);

            // Auto remove after 3 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease-in';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }

            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }

            .report-card, .btn-report, .export-btn {
                transition: all 0.3s ease;
            }

            .report-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            }

            .btn-report:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            }

            .export-btn:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(102,126,234,0.3);
            }

            .analytics-table tr:hover {
                background-color: #f8f9fa;
            }
            .chart-canvas { width: 100% !important; height: 100% !important; display: block; }
            .chart-loading { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; background: rgba(255,255,255,0.7); color:#374151; font-weight:600; gap:8px; z-index:5; border-radius:10px; }
            .chart-spinner { width:22px; height:22px; border:3px solid #cfe0ff; border-top-color:#3b82f6; border-radius:50%; animation: spin 0.8s linear infinite; }
            @keyframes spin { to { transform: rotate(360deg); } }
            /* Scrollable Recent Incidents table with fixed header */
            .analytics-table.scrollable thead, .analytics-table.scrollable tbody { display: block; }
            .analytics-table.scrollable tbody { max-height: 360px; overflow-y: auto; }
            .analytics-table.scrollable thead tr, .analytics-table.scrollable tbody tr { display: table; width: 100%; table-layout: fixed; }
        `;
        document.head.appendChild(style);
        // Charts
        let responseChart = null;
        let typesChart = null;
        let callDurationChart = null;
        let dispatchDailyChart = null;

        function setChartLoading(chartId, isLoading) {
            const canvas = document.getElementById(chartId);
            const container = canvas ? canvas.closest('.chart-container') : null;
            if (!container) return;
            let overlay = container.querySelector('.chart-loading');
            if (isLoading) {
                if (!overlay) {
                    overlay = document.createElement('div');
                    overlay.className = 'chart-loading';
                    overlay.innerHTML = '<div class="chart-spinner"></div><div>Loading…</div>';
                    container.appendChild(overlay);
                }
            } else if (overlay) {
                overlay.remove();
            }
        }

        async function refreshCharts(filters = {}) {
            try {
                setChartLoading('responseTimeChart', true);
                setChartLoading('incidentsTypesChart', true);
                setChartLoading('callDurationChart', true);
                setChartLoading('dispatchDailyChart', true);
                const qs = buildQuery(filters);
                const [respRes, metricsRes] = await Promise.all([
                    fetch('api/report_response_times_daily.php' + qs),
                    fetch('api/report_metrics.php' + qs)
                ]);
                const respData = await respRes.json();
                const metricsData = await metricsRes.json();
                if (respData.ok) {
                    const labels = respData.labels || [];
                    const data = respData.data || [];
                    const ctx = document.getElementById('responseTimeChart');
                    if (ctx) {
                        if (!responseChart) {
                            responseChart = new Chart(ctx, {
                                type: 'line',
                                data: { labels, datasets: [{
                                    label: 'Avg Response Time (min)',
                                    data,
                                    borderColor: '#3b82f6',
                                    backgroundColor: 'rgba(59,130,246,0.15)',
                                    tension: 0.3,
                                    fill: true,
                                }]},
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    scales: { y: { beginAtZero: true } },
                                    plugins: { legend: { display: true } }
                                }
                            });
                        } else {
                            responseChart.data.labels = labels;
                            responseChart.data.datasets[0].data = data;
                            responseChart.update();
                        }
                    }
                }
                if (metricsData.ok) {
                    const typeCounts = metricsData.metrics?.incidents_by_type || {};
                    let labels = ['Medical','Fire','Police','Traffic'];
                    let values = [
                        typeCounts.medical || 0,
                        typeCounts.fire || 0,
                        typeCounts.police || 0,
                        typeCounts.traffic || 0,
                    ];
                    // Apply incident type filter if present
                    if (filters.type) {
                        const map = { medical: 'Medical', fire: 'Fire', police: 'Police', traffic: 'Traffic', accident: 'Traffic', crime: 'Police' };
                        const wanted = map[filters.type] || filters.type;
                        const idx = labels.indexOf(wanted);
                        if (idx >= 0) { labels = [labels[idx]]; values = [values[idx]]; } else { labels = []; values = []; }
                    }
                    const ctx2 = document.getElementById('incidentsTypesChart');
                    if (ctx2) {
                        if (!typesChart) {
                            typesChart = new Chart(ctx2, {
                                type: 'doughnut',
                                data: {
                                    labels,
                                    datasets: [{
                                        label: 'Incidents by Type',
                                        data: values,
                                        backgroundColor: ['#ef4444','#f59e0b','#3b82f6','#22c55e'],
                                    }]
                                },
                                options: { responsive: true, maintainAspectRatio: false }
                            });
                        } else {
                            typesChart.data.labels = labels;
                            typesChart.data.datasets[0].data = values;
                            typesChart.update();
                        }
                    }
                }
                // Call Duration Chart
                const callRes = await fetch('api/report_call_duration.php');
                const callData = await callRes.json();
                if (callData.ok) {
                    const raw = Array.isArray(callData.data) ? callData.data : [];
                    const filtered = raw.filter(x => ((x.type || '').toLowerCase() !== 'other'));
                    const labels = filtered.map(x => x.type);
                    const values = filtered.map(x => x.avg_duration);
                    const ctx3 = document.getElementById('callDurationChart');
                    if (ctx3) {
                        if (!callDurationChart) {
                            callDurationChart = new Chart(ctx3, {
                                type: 'bar',
                                data: {
                                    labels,
                                    datasets: [{
                                        label: 'Avg Call Duration (min)',
                                        data: values,
                                        backgroundColor: '#6366f1',
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    scales: { y: { beginAtZero: true } },
                                    plugins: { legend: { display: true } }
                                }
                            });
                        } else {
                            callDurationChart.data.labels = labels;
                            callDurationChart.data.datasets[0].data = values;
                            callDurationChart.update();
                        }
                    }
                }
                // Dispatch daily chart
                const dispRes = await fetch('api/reports_dispatch.php' + qs);
                const dispData = await dispRes.json();
                if (dispData.ok) {
                    const labels = dispData.daily?.labels || [];
                    const values = dispData.daily?.data || [];
                    const ctx4 = document.getElementById('dispatchDailyChart');
                    const maxVal = values.length ? Math.max(...values) : 0;
                    if (ctx4) {
                        if (!dispatchDailyChart) {
                            dispatchDailyChart = new Chart(ctx4, {
                                type: 'line',
                                data: {
                                    labels,
                                    datasets: [{
                                        label: 'Dispatches per Day',
                                        data: values,
                                        borderColor: '#3b82f6',
                                        backgroundColor: 'rgba(59,130,246,0.15)',
                                        tension: 0.3,
                                        fill: false,
                                        pointRadius: 3,
                                        pointHoverRadius: 5,
                                        spanGaps: true
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: { legend: { display: false } },
                                    scales: {
                                        x: {
                                            grid: { display: true },
                                            ticks: { maxRotation: 45, minRotation: 45 }
                                        },
                                        y: {
                                            beginAtZero: true,
                                            suggestedMax: Math.max(4, Math.ceil(maxVal * 1.2)),
                                            grid: { display: true }
                                        }
                                    }
                                }
                            });
                        } else {
                            dispatchDailyChart.data.labels = labels;
                            dispatchDailyChart.data.datasets[0].data = values;
                            // Update suggestedMax dynamically on refresh
                            dispatchDailyChart.options.scales.y.suggestedMax = Math.max(4, Math.ceil(maxVal * 1.2));
                            dispatchDailyChart.update();
                        }
                    }
                }
            } catch (e) { /* silent */ }
            finally {
                setChartLoading('responseTimeChart', false);
                setChartLoading('incidentsTypesChart', false);
                setChartLoading('callDurationChart', false);
                setChartLoading('dispatchDailyChart', false);
            }
        }

        async function refreshMetrics(filters = {}) {
            try {
            const qs = buildQuery(filters);
            const res = await fetch('api/report_metrics.php' + qs);
                const data = await res.json();
                if (!data.ok) return;
                const m = data.metrics || {};
                const avgEl = document.getElementById('metricAvgResponse');
                const monthEl = document.getElementById('metricIncidentsMonth');
                const lastEl = document.getElementById('metricLastMonth');
                const deltaEl = document.getElementById('metricIncidentsDelta');
                const utilEl = document.getElementById('metricUtilization');
                const successEl = document.getElementById('metricSuccess');
                if (avgEl) avgEl.textContent = (m.avg_response_time_min ?? 0).toFixed(1);
                if (monthEl) monthEl.textContent = m.total_incidents_month ?? 0;
                if (lastEl) lastEl.textContent = m.total_incidents_last_month ?? 0;
                if (deltaEl) deltaEl.textContent = Math.max(0, (m.total_incidents_month ?? 0) - (m.total_incidents_last_month ?? 0));
                if (utilEl) utilEl.textContent = ((m.resource_utilization ?? 0)).toFixed(1) + '%';
                if (successEl) successEl.textContent = ((m.success_rate ?? 0)).toFixed(1) + '%';

                // Populate Performance Metrics table current values and targets
                const perfTable = document.querySelector('.data-table .table-title')?.textContent?.includes('Performance Metrics')
                    ? document.querySelector('.data-table:nth-of-type(3) table tbody')
                    : document.querySelectorAll('.data-table table tbody')[2];
                if (perfTable) {
                    const rows = perfTable.querySelectorAll('tr');
                    if (rows[0]) { // Average Response Time
                        const cells = rows[0].querySelectorAll('td');
                        if (cells[1]) cells[1].textContent = `${(m.avg_response_time_min ?? 0).toFixed(1)} min`;
                        if (cells[2]) cells[2].textContent = '< 10 min';
                    }
                    if (rows[1]) { // Incident Resolution Rate
                        const cells = rows[1].querySelectorAll('td');
                        if (cells[1]) cells[1].textContent = `${(m.success_rate ?? 0).toFixed(1)}%`;
                        if (cells[2]) cells[2].textContent = '≥ 95%';
                    }
                    if (rows[2]) { // Resource Utilization
                        const cells = rows[2].querySelectorAll('td');
                        if (cells[1]) cells[1].textContent = `${(m.resource_utilization ?? 0).toFixed(1)}%`;
                        if (cells[2]) cells[2].textContent = '70–85%';
                    }
                    // Rows 3-4: Equipment Downtime & Personnel Overtime not tracked
                    if (rows[3]) {
                        const cells = rows[3].querySelectorAll('td');
                        if (cells[1]) cells[1].textContent = '—';
                        if (cells[2]) cells[2].textContent = 'Minimize';
                    }
                    if (rows[4]) {
                        const cells = rows[4].querySelectorAll('td');
                        if (cells[1]) cells[1].textContent = '—';
                        if (cells[2]) cells[2].textContent = '≤ 10%';
                    }
                }
                // Dispatch metrics
                const dispRes = await fetch('api/reports_dispatch.php' + qs);
                const disp = await dispRes.json();
                if (disp.ok) {
                    const dm = disp.metrics || {};
                    const totalEl = document.getElementById('dispTotal');
                    const ackEl = document.getElementById('dispAck');
                    const onSceneEl = document.getElementById('dispOnScene');
                    const breachEl = document.getElementById('dispBreach');
                    if (totalEl) totalEl.textContent = dm.total_dispatches ?? 0;
                    if (ackEl) ackEl.textContent = (dm.avg_ack_min ?? 0).toFixed(1);
                    if (onSceneEl) onSceneEl.textContent = (dm.avg_on_scene_min ?? 0).toFixed(1);
                    if (breachEl) breachEl.textContent = ((dm.sla_breach_rate ?? 0)).toFixed(1) + '%';
                    // Render top units
                    renderDispatchTopUnits(disp.top_units || []);
                }
            } catch (e) {
                // silent fail
            }
        }

        // Recent Incidents loader
        function periodToParams(period) {
            const now = new Date();
            const yyyy = now.getFullYear();
            const mm = String(now.getMonth() + 1).padStart(2, '0');
            const dd = String(now.getDate()).padStart(2, '0');
            if (period === 'today') return { day: `${yyyy}-${mm}-${dd}` };
            if (period === 'month') return { month: `${yyyy}-${mm}` };
            return {};
        }

        async function loadRecentIncidents(filters = {}) {
            try {
                const tbody = document.getElementById('recentIncidentsBody');
                if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="color:#6b7280">Loading incidents…</td></tr>';
                const extra = periodToParams(filters.period || '');
                const qs = buildQuery(extra);
                const res = await fetch('api/incidents_list.php' + qs);
                const data = await res.json();
                const items = data.ok ? (data.items || []) : [];
                renderRecentIncidents(items);
            } catch (e) {
                renderRecentIncidents([]);
            }
        }

        function labelForType(t) {
            switch (t) {
                case 'medical': return 'Medical Emergency';
                case 'fire': return 'Fire';
                case 'police': return 'Police Emergency';
                case 'traffic':
                case 'accident': return 'Traffic Accident';
                default: return 'Other';
            }
        }

        function renderRecentIncidents(items) {
            const tbody = document.getElementById('recentIncidentsBody');
            if (!tbody) return;
            if (!items.length) {
                tbody.innerHTML = `<tr><td colspan="7" style="color:#6b7280">No incidents found for selected period</td></tr>`;
                return;
            }
            function unitNameToParam(name){
                return (name||'').toLowerCase().replace(/\s+/g,'-').replace(/#/g,'').replace(/[^a-z0-9\-]/g,'');
            }
            tbody.innerHTML = items.slice(0, 10).map(i => {
                const id = i.id;
                const code = i.incident_code || '';
                const type = labelForType(i.type);
                const loc = i.location || '';
                const pr = (i.priority || '').toUpperCase();
                const status = (i.status || '').replace('_',' ');
                const badgeClass = status.includes('resolve') ? 'status-resolved' : (status.includes('progress')||status.includes('dispatch')) ? 'status-pending' : (pr === 'CRITICAL' ? 'status-critical' : '');
                return `
                <tr>
                    <td>${code}</td>
                    <td>${type}</td>
                    <td>${loc}</td>
                    <td>${pr || ''}</td>
                    <td>${i.response_time_min != null ? i.response_time_min + ' min' : ''}</td>
                    <td><span class="status-badge ${badgeClass}">${status || ''}</span></td>
                    <td>
                        <button class="btn-report" onclick="viewIncidentDetails(${id})"><i class="fas fa-eye"></i> Details</button>
                    </td>
                </tr>`;
            }).join('');
        }

        function renderDispatchTopUnits(items) {
            const tbody = document.getElementById('dispatchTopUnitsBody');
            if (!tbody) return;
            if (!items.length) {
                tbody.innerHTML = `<tr><td colspan="3" style="color:#6b7280">No dispatches found for selected period</td></tr>`;
                return;
            }
            tbody.innerHTML = items.map(u => `
                <tr>
                    <td>${u.identifier}</td>
                    <td>${(u.unit_type || '').charAt(0).toUpperCase() + (u.unit_type || '').slice(1)}</td>
                    <td>${u.count}</td>
                </tr>
            `).join('');
        }

        async function refreshDispatchReport() {
            await Promise.all([refreshMetrics(currentFilters), refreshCharts(currentFilters)]);
            showNotification('Dispatch report refreshed', 'success');
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            currentFilters = getFilters();
            refreshMetrics(currentFilters);
            refreshCharts(currentFilters);
            loadRecentIncidents(currentFilters);
            // KPI deep links
            const cardResp = document.querySelector('.analytics-card.response-time');
            const cardInc = document.querySelector('.analytics-card.incidents');
            const cardRes = document.querySelector('.analytics-card.resources');
            const cardPerf = document.querySelector('.analytics-card.performance');
            if (cardResp) cardResp.style.cursor='pointer', cardResp.addEventListener('click', () => navigateTo('dispatch.php', { period: currentFilters.period || 'month' }));
            if (cardInc) cardInc.style.cursor='pointer', cardInc.addEventListener('click', () => navigateTo('incident.php', { period: currentFilters.period || 'month' }));
            if (cardRes) cardRes.style.cursor='pointer', cardRes.addEventListener('click', () => window.open('api/reports_resources.php' + buildQuery(currentFilters), '_blank'));
            if (cardPerf) cardPerf.style.cursor='pointer', cardPerf.addEventListener('click', () => navigateTo('incident.php', { period: currentFilters.period || 'month', status: 'resolved' }));
            setInterval(() => { refreshMetrics(currentFilters); refreshCharts(currentFilters); }, 15000);
        });
    </script>
</body>
</html>
