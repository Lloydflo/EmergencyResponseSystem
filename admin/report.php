<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
// Require full login (including OTP verification) before loading page
require_role('admin', 'admin/report.php');
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
$defaultPeriod = 'custom';
$defaultStartDate = '';
$defaultEndDate = '';
try {
    $pdo = get_db_connection();
    if ($pdo) {
        $latestDates = [];
        foreach ([
            "SELECT MAX(created_at) AS latest_at FROM incidents",
            "SELECT MAX(assigned_at) AS latest_at FROM dispatches",
            "SELECT MAX(received_at) AS latest_at FROM calls"
        ] as $sql) {
            $row = $pdo->query($sql)->fetch();
            if (!empty($row['latest_at'])) {
                $latestDates[] = (string)$row['latest_at'];
            }
        }

        $latestActivityAt = !empty($latestDates) ? max($latestDates) : date('Y-m-d H:i:s');
        $latestActivity = new DateTime($latestActivityAt);
        $rangeStart = (clone $latestActivity)->modify('first day of this month')->format('Y-m-d');
        $rangeEnd = (clone $latestActivity)->modify('last day of this month')->format('Y-m-d');
        $prevStart = (clone $latestActivity)->modify('first day of last month')->format('Y-m-d');
        $prevEnd = (clone $latestActivity)->modify('last day of last month')->format('Y-m-d');

        $defaultStartDate = $rangeStart;
        $defaultEndDate = $rangeEnd;

        $incidentWindowSql = "
            FROM incidents i
            WHERE (
                i.created_at BETWEEN :start AND :end
                OR (i.updated_at IS NOT NULL AND i.updated_at BETWEEN :ustart AND :uend)
                OR EXISTS (
                    SELECT 1
                    FROM dispatches d_window
                    WHERE d_window.incident_id = i.id
                      AND d_window.assigned_at BETWEEN :dstart AND :dend
                )
            )
        ";

        $stmt = $pdo->prepare("SELECT COUNT(*) AS c " . $incidentWindowSql);
        $stmt->execute([
            ':start' => $rangeStart . ' 00:00:00',
            ':end' => $rangeEnd . ' 23:59:59',
            ':ustart' => $rangeStart . ' 00:00:00',
            ':uend' => $rangeEnd . ' 23:59:59',
            ':dstart' => $rangeStart . ' 00:00:00',
            ':dend' => $rangeEnd . ' 23:59:59',
        ]);
        $totalIncidentsMonth = (int)($stmt->fetch()['c'] ?? 0);

        $stmt = $pdo->prepare("SELECT COUNT(*) AS c " . $incidentWindowSql);
        $stmt->execute([
            ':start' => $prevStart . ' 00:00:00',
            ':end' => $prevEnd . ' 23:59:59',
            ':ustart' => $prevStart . ' 00:00:00',
            ':uend' => $prevEnd . ' 23:59:59',
            ':dstart' => $prevStart . ' 00:00:00',
            ':dend' => $prevEnd . ' 23:59:59',
        ]);
        $lastMonthIncidents = (int)($stmt->fetch()['c'] ?? 0);

        $stmt = $pdo->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN i.status='resolved' THEN 1 ELSE 0 END) AS resolved " . $incidentWindowSql);
        $stmt->execute([
            ':start' => $rangeStart . ' 00:00:00',
            ':end' => $rangeEnd . ' 23:59:59',
            ':ustart' => $rangeStart . ' 00:00:00',
            ':uend' => $rangeEnd . ' 23:59:59',
            ':dstart' => $rangeStart . ' 00:00:00',
            ':dend' => $rangeEnd . ' 23:59:59',
        ]);
        $rangeStats = $stmt->fetch() ?: [];
        $resolvedCountMonth = (int)($rangeStats['resolved'] ?? 0);
        $totalIncidentsInRange = (int)($rangeStats['total'] ?? 0);
        $successRate = $totalIncidentsInRange > 0 ? round(($resolvedCountMonth / $totalIncidentsInRange) * 100, 1) : 0.0;

        $totalUnits = (int)$pdo->query("SELECT COUNT(*) AS c FROM units")->fetch()['c'];
        $busyUnits = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status IN ('assigned','enroute','on_scene')")->fetch()['c'];
        $activeResponders = (int)$pdo->query("SELECT COUNT(*) AS c FROM staff WHERE status IN ('available','on_duty')")->fetch()['c'];
        $resourceUtilization = $totalUnits > 0 ? round(($busyUnits / $totalUnits) * 100, 1) : 0.0;

        $stmt = $pdo->prepare("
            SELECT AVG(TIMESTAMPDIFF(MINUTE, d.assigned_at, COALESCE(d.on_scene_at, d.cleared_at))) AS avg_min
            FROM dispatches d
            INNER JOIN incidents i ON i.id = d.incident_id
            WHERE d.assigned_at IS NOT NULL
              AND d.assigned_at BETWEEN :start AND :end
              AND COALESCE(d.on_scene_at, d.cleared_at) IS NOT NULL
        ");
        $stmt->execute([':start' => $rangeStart . ' 00:00:00', ':end' => $rangeEnd . ' 23:59:59']);
        $row = $stmt->fetch();
        if ($row && $row['avg_min'] !== null) {
            $avgResponseTime = round((float)$row['avg_min'], 1);
        }
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
    <?php include $rootDir . '/includes/theme-init.php'; ?>
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
                    <div class="metric-label">SLA Response Average</div>
                    <div class="metric-display">
                        <div class="metric-value" id="metricAvgResponse"><?php echo number_format($avgResponseTime, 1); ?></div>
                        <div class="metric-change positive">
                            <i class="fas fa-arrow-down"></i>
                            
                        </div>
                    </div>
                    <div style="color: #666; font-size: 0.9rem;">Admin target: &lt; 10m</div>
                </div>

                <div class="analytics-card incidents">
                    <div class="metric-label">Monthly Operational Load</div>
                    <div class="metric-display">
                        <div class="metric-value" id="metricIncidentsMonth"><?php echo (int)$totalIncidentsMonth; ?></div>
                        <div class="metric-change positive">
                            <i class="fas fa-arrow-up" id="metricIncidentsDelta"><?php echo max(0, (int)$totalIncidentsMonth - (int)$lastMonthIncidents); ?></i>
                            
                        </div>
                    </div>
                    <div style="color: #666; font-size: 0.9rem;">Previous month baseline: <span id="metricLastMonth"><?php echo (int)$lastMonthIncidents; ?></span></div>
                </div>

                <div class="analytics-card resources">
                    <div class="metric-label">System Utilization</div>
                    <div class="metric-display">
                        <div class="metric-value" id="metricUtilization"><?php echo number_format($resourceUtilization, 1); ?>%</div>
                        <div class="metric-change neutral">
                            <i class="fas fa-minus"></i>
                            
                        </div>
                    </div>
                    <div style="color: #666; font-size: 0.9rem;">Coverage target: 70-85%</div>
                </div>

                <div class="analytics-card performance">
                    <div class="metric-label">Resolution Performance</div>
                    <div class="metric-display">
                        <div class="metric-value" id="metricSuccess"><?php echo number_format($successRate, 1); ?>%</div>
                        <div class="metric-change positive">
                            <i class="fas fa-arrow-up"></i>
                            
                        </div>
                    </div>
                    <div style="color: #666; font-size: 0.9rem;">Admin benchmark: 92-96%</div>
                </div>
            </div>

            <!-- Report Filters -->
            <div class="report-filters">
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1.5rem; display: flex; align-items: center;">
                    <i class="fas fa-filter" style="margin-right: 0.5rem; color: #007bff;"></i>
                    Admin Report Filters
                </h2>
                <div class="filter-row">
                    <div class="filter-group">
                        <label for="report-type">Admin View</label>
                        <select id="report-type">
                            <option value="">Executive Summary</option>
                            <option value="incident">Incident Oversight</option>
                            <option value="performance">Performance Review</option>
                            <option value="resource">Resource Audit</option>
                            <option value="trend">Trend Monitoring</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="time-period">Time Period</label>
                        <select id="time-period">
                            <option value="today" <?php echo $defaultPeriod === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="week" <?php echo $defaultPeriod === 'week' ? 'selected' : ''; ?>>This Week</option>
                            <option value="month" <?php echo $defaultPeriod === 'month' ? 'selected' : ''; ?>>This Month</option>
                            <option value="quarter" <?php echo $defaultPeriod === 'quarter' ? 'selected' : ''; ?>>This Quarter</option>
                            <option value="year" <?php echo $defaultPeriod === 'year' ? 'selected' : ''; ?>>This Year</option>
                            <option value="custom" <?php echo $defaultPeriod === 'custom' ? 'selected' : ''; ?>>Custom Range</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="incident-type">Service Category</label>
                        <select id="incident-type">
                            <option value="">All Categories</option>
                            <option value="medical">Medical Emergency</option>
                            <option value="fire">Fire</option>
                            <option value="police">Police Emergency</option>
                            <option value="traffic">Traffic Accident</option>
                            <option value="other">Other</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="priority-level">Escalation Level</label>
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
                            <input type="date" id="start-date" value="<?php echo htmlspecialchars($defaultStartDate); ?>">
                        </div>
                        <div class="filter-group">
                            <label for="end-date">End Date</label>
                            <input type="date" id="end-date" value="<?php echo htmlspecialchars($defaultEndDate); ?>">
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
                    <div class="report-title">Executive Incident Summary</div>
                    <div class="report-description">Admin-ready overview of incident volume, priorities, and operational outcomes.</div>
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
                    <div class="report-title">Admin Performance Review</div>
                    <div class="report-description">Leadership snapshot of response efficiency, success rate, and service delivery trends.</div>
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
                    <div class="report-title">Resource Audit Report</div>
                    <div class="report-description">Administrative review of fleet, personnel, and utilization coverage across the system.</div>
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
                    <h3 class="chart-title">Response Performance Trend</h3>
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
                    <h3 class="chart-title">Incident Mix by Type</h3>
                    <div class="chart-controls">
                        <button class="btn-report" onclick="refreshChart()">
                            <i class="fas fa-sync"></i> Refresh
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
                    <h3 class="chart-title">Priority Level Oversight</h3>
                    <div class="chart-controls">
                        <button class="btn-report" onclick="refreshChart()">
                            <i class="fas fa-sync"></i> Refresh
                        </button>
                        <button class="btn-report" onclick="exportChart('callDurationChart')">
                            <i class="fas fa-download"></i> Export
                        </button>
                    </div>
                </div>
                <div style="position: relative; width: 100%; height: 320px;">
                    <canvas id="callDurationChart" class="chart-canvas"></canvas>
                </div>
            </div>

            <div class="chart-container">
                <div class="chart-header">
                    <h3 class="chart-title">Dispatch Load by Unit Type</h3>
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
                <div class="table-header" style="display:flex; align-items:center; justify-content:space-between; gap:1rem;">
                    <h3 class="table-title">Top Dispatched Units</h3>
                    <button class="btn-report" type="button" onclick="showAllDispatchUnitsModal()">
                        <i class="fas fa-list"></i> View All
                    </button>
                </div>
                <div class="table-container">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Service</th>
                                <th>Linked Type</th>
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
                <div style="display:flex; align-items:center; justify-content:space-between; gap:1rem; margin-bottom:1.5rem;">
                    <h2 class="alerts-section-title" style="font-size: 1.25rem; font-weight: 700; margin: 0; display: flex; align-items: center;">
                        <i class="fas fa-bell" style="margin-right: 0.5rem; color: #ffc107;"></i>
                        System Alerts & Notifications
                    </h2>
                    <button class="btn-report" type="button" onclick="exportSystemFeed()">
                        <i class="fas fa-print"></i> Export
                    </button>
                </div>
                <div id="alerts-dynamic" class="alerts-feed-scroll"></div>
            </div>
    <script>
    // --- Combined System Alerts & Activity Feed ---
    let LAST_ALERTS = [];
    let LAST_SYSTEM_FEED = [];

    function systemFeedEscape(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function systemFeedTime(value) {
        if (!value) return '';
        try {
            const date = new Date(value);
            if (!Number.isNaN(date.getTime())) {
                return date.toLocaleString();
            }
        } catch (e) {}
        return String(value);
    }

    async function fetchAlerts() {
        try {
            const res = await fetch('api/alerts_active.php?all=1');
            const data = await res.json();
            if (!data.ok) return [];
            return data.data || [];
        } catch (e) { return []; }
    }

    async function fetchSystemActivity() {
        try {
            const res = await fetch('api/activity_feed.php?all=1');
            const data = await res.json();
            if (!data.ok) return [];
            return data.data || [];
        } catch (e) { return []; }
    }

    function normalizeSystemFeed(alerts, activities) {
        const alertItems = alerts.map((alert, index) => ({
            kind: 'alert',
            key: 'alert-' + index + '-' + String(alert.title || '') + '-' + String(alert.details || ''),
            type: String(alert.type || 'info').toLowerCase(),
            title: alert.title || 'Alert',
            details: alert.details || '',
            created_at: alert.created_at || null,
        }));

        const activityItems = activities.map((activity, index) => ({
            kind: 'activity',
            key: 'activity-' + index + '-' + String(activity.id || '') + '-' + String(activity.created_at || ''),
            type: String(activity.action || 'activity').toLowerCase(),
            title: `${String(activity.action || 'Activity').toUpperCase()} Activity`,
            details: `${activity.username || ('User #' + (activity.user_id || ''))} ${activity.action || 'updated'} the system`,
            created_at: activity.created_at || null,
            entity_type: activity.entity_type || 'auth',
        }));

        return [...alertItems, ...activityItems].sort((a, b) => {
            const aTime = a.created_at ? new Date(a.created_at).getTime() : 0;
            const bTime = b.created_at ? new Date(b.created_at).getTime() : 0;
            return bTime - aTime;
        });
    }

    function systemFeedItemHtml(item) {
        const isAlert = item.kind === 'alert';
        const cardClass = isAlert
            ? (item.type === 'critical' ? 'critical' : item.type === 'warning' ? 'warning' : 'info')
            : 'info';
        const icon = isAlert
            ? (item.type === 'critical' ? 'fa-exclamation-triangle' : item.type === 'warning' ? 'fa-exclamation-circle' : 'fa-info-circle')
            : (item.type === 'login' ? 'fa-right-to-bracket' : item.type === 'logout' ? 'fa-right-from-bracket' : 'fa-clock-rotate-left');
        const badgeLabel = isAlert ? item.type.toUpperCase() : 'ACTIVITY';
        const badgeColor = isAlert
            ? (item.type === 'critical' ? '#dc2626' : item.type === 'warning' ? '#f59e0b' : '#2563eb')
            : '#475569';
        const time = systemFeedTime(item.created_at);

        return `
            <div class="alert-item ${systemFeedEscape(cardClass)}">
                <div class="alert-info">
                    <div class="alert-title">
                        <span class="alert-feed-badge" style="background:${badgeColor};">${systemFeedEscape(badgeLabel)}</span>
                        <i class="fas ${icon}"></i> ${systemFeedEscape(item.title)}
                    </div>
                    <div class="alert-details">${systemFeedEscape(item.details)}</div>
                    ${time ? `<div class="alert-time">${systemFeedEscape(time)}</div>` : ''}
                </div>
            </div>
        `;
    }

    function showAlertPopup(alert) {
        showNotification(`${alert.title}: ${alert.details}`, alert.type || 'info');
    }

    async function renderAlerts() {
        const container = document.getElementById('alerts-dynamic');
        if (!container) return;

        const [alerts, activities] = await Promise.all([
            fetchAlerts(),
            fetchSystemActivity()
        ]);

        const combinedFeed = normalizeSystemFeed(alerts, activities);
        LAST_SYSTEM_FEED = combinedFeed;

        if (!combinedFeed.length) {
            container.innerHTML = '<div class="alerts-empty-state">No system alerts or recent activity at this time.</div>';
        } else {
            container.innerHTML = combinedFeed.map(systemFeedItemHtml).join('');
        }

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

    function exportSystemFeed() {
        const printableItems = Array.isArray(LAST_SYSTEM_FEED) ? LAST_SYSTEM_FEED : [];
        const rows = printableItems.length
            ? printableItems.map(item => `
                <tr>
                    <td>${systemFeedEscape(item.kind === 'alert' ? 'Alert' : 'Activity')}</td>
                    <td>${systemFeedEscape(item.title)}</td>
                    <td>${systemFeedEscape(item.details)}</td>
                    <td>${systemFeedEscape(systemFeedTime(item.created_at) || '—')}</td>
                </tr>
            `).join('')
            : '<tr><td colspan="4">No data available.</td></tr>';

        const printWindow = window.open('', '_blank', 'width=1100,height=800');
        if (!printWindow) {
            showNotification('Unable to open print window', 'error');
            return;
        }

        printWindow.document.write(`
            <html>
            <head>
                <title>System Alerts and Activity Export</title>
                <style>
                    body { font-family: Arial, sans-serif; padding: 24px; color: #111827; }
                    h1 { margin: 0 0 8px; font-size: 24px; }
                    p { margin: 0 0 20px; color: #4b5563; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { border: 1px solid #d1d5db; padding: 10px 12px; text-align: left; vertical-align: top; }
                    th { background: #f3f4f6; }
                </style>
            </head>
            <body>
                <h1>System Alerts & Notifications</h1>
                <p>Export generated on ${systemFeedEscape(new Date().toLocaleString())}</p>
                <table>
                    <thead>
                        <tr>
                            <th>Category</th>
                            <th>Title</th>
                            <th>Details</th>
                            <th>Date / Time</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </body>
            </html>
        `);
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
    }

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
        const defaultReportFilters = {
            period: <?php echo json_encode($defaultPeriod); ?>,
            start: <?php echo json_encode($defaultStartDate); ?>,
            end: <?php echo json_encode($defaultEndDate); ?>
        };
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
            if (timePeriod) timePeriod.value = defaultReportFilters.period;
            if (incidentType) incidentType.value = '';
            if (priorityLevel) priorityLevel.value = '';
            if (startDate) startDate.value = defaultReportFilters.start;
            if (endDate) endDate.value = defaultReportFilters.end;

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

        // Add css animations
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
        let dispatchUnitBreakdown = [];

        const reportBarValueLabelsPlugin = {
            id: 'reportBarValueLabels',
            afterDatasetsDraw(chart) {
                if (chart.config.type !== 'bar') return;
                const { ctx } = chart;
                const meta = chart.getDatasetMeta(0);
                const dataset = chart.data.datasets[0];
                const color = chart.options.plugins?.reportBarValueLabels?.color || '#1f2937';

                ctx.save();
                ctx.font = '700 12px Arial';
                ctx.fillStyle = color;
                ctx.textBaseline = 'middle';

                meta.data.forEach((bar, index) => {
                    const raw = Number(dataset.data[index] || 0);
                    const x = bar.x + 10;
                    const y = bar.y;
                    ctx.fillText(String(raw), x, y);
                });

                ctx.restore();
            }
        };

        const reportDoughnutCenterPlugin = {
            id: 'reportDoughnutCenter',
            afterDraw(chart) {
                if (chart.config.type !== 'doughnut') return;
                const meta = chart.getDatasetMeta(0);
                if (!meta.data.length) return;

                const total = chart.data.datasets[0].data.reduce((sum, value) => sum + Number(value || 0), 0);
                const { x, y } = meta.data[0];
                const valueColor = chart.options.plugins?.reportDoughnutCenter?.valueColor || '#1f2937';
                const labelColor = chart.options.plugins?.reportDoughnutCenter?.labelColor || '#6b7280';

                const { ctx } = chart;
                ctx.save();
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillStyle = valueColor;
                ctx.font = '700 24px Arial';
                ctx.fillText(String(total), x, y - 8);
                ctx.fillStyle = labelColor;
                ctx.font = '600 11px Arial';
                ctx.fillText('Total', x, y + 14);
                ctx.restore();
            }
        };

        const reportDoughnutSliceLabelPlugin = {
            id: 'reportDoughnutSliceLabel',
            afterDatasetsDraw(chart) {
                if (chart.config.type !== 'doughnut') return;
                const dataset = chart.data.datasets[0];
                const meta = chart.getDatasetMeta(0);
                if (!dataset || !meta?.data?.length) return;

                const total = dataset.data.reduce((sum, value) => sum + Number(value || 0), 0);
                if (!total) return;

                const textColor = chart.options.plugins?.reportDoughnutSliceLabel?.color || '#ffffff';
                const ctx = chart.ctx;
                ctx.save();
                ctx.fillStyle = textColor;
                ctx.font = '700 12px Arial';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';

                meta.data.forEach((arc, index) => {
                    const value = Number(dataset.data[index] || 0);
                    if (!value) return;
                    const angle = (arc.startAngle + arc.endAngle) / 2;
                    const radius = arc.innerRadius + ((arc.outerRadius - arc.innerRadius) * 0.58);
                    const x = arc.x + Math.cos(angle) * radius;
                    const y = arc.y + Math.sin(angle) * radius;
                    const percentage = ((value / total) * 100).toFixed(1).replace(/\.0$/, '') + '%';
                    ctx.fillText(percentage, x, y);
                });

                ctx.restore();
            }
        };

        function getReportChartTheme() {
            const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
            return isDark ? {
                text: '#e5eef9',
                muted: '#94a3b8',
                grid: 'rgba(148, 163, 184, 0.16)',
                tooltipBg: '#020817',
                tooltipBorder: '#334155',
                tooltipText: '#f8fafc'
            } : {
                text: '#1f2937',
                muted: '#6b7280',
                grid: 'rgba(148, 163, 184, 0.2)',
                tooltipBg: '#ffffff',
                tooltipBorder: '#d1d5db',
                tooltipText: '#111827'
            };
        }

        function createChartGradient(ctx, colorTop, colorBottom) {
            const gradient = ctx.createLinearGradient(0, 0, 0, 320);
            gradient.addColorStop(0, colorTop);
            gradient.addColorStop(1, colorBottom);
            return gradient;
        }

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
                const [respRes, metricsRes, dispRes] = await Promise.all([
                    fetch('api/report_response_times_daily.php' + qs, { cache: 'no-store' }),
                    fetch('api/report_metrics.php' + qs, { cache: 'no-store' }),
                    fetch('api/reports_dispatch.php' + qs, { cache: 'no-store' })
                ]);
                const respData = await respRes.json();
                const metricsData = await metricsRes.json();
                const dispData = await dispRes.json();
                const theme = getReportChartTheme();

                if (respData.ok) {
                    const labels = respData.labels || [];
                    const data = respData.data || [];
                    const ctx = document.getElementById('responseTimeChart');
                    if (ctx) {
                        if (responseChart) responseChart.destroy();
                        const chartCtx = ctx.getContext('2d');
                        responseChart = new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels,
                                datasets: [{
                                    label: 'Avg Response Time (min)',
                                    data,
                                    borderColor: '#2563eb',
                                    backgroundColor: createChartGradient(chartCtx, 'rgba(37, 99, 235, 0.28)', 'rgba(37, 99, 235, 0.04)'),
                                    tension: 0.35,
                                    fill: true,
                                    pointRadius: 3,
                                    pointHoverRadius: 5,
                                    pointBackgroundColor: '#2563eb',
                                    pointBorderColor: '#ffffff'
                                }, {
                                    label: 'Admin Target',
                                    data: labels.map(() => 10),
                                    borderColor: '#f59e0b',
                                    borderDash: [6, 6],
                                    pointRadius: 0,
                                    pointHoverRadius: 0,
                                    fill: false
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        labels: { color: theme.text }
                                    },
                                    tooltip: {
                                        backgroundColor: theme.tooltipBg,
                                        borderColor: theme.tooltipBorder,
                                        borderWidth: 1,
                                        titleColor: theme.tooltipText,
                                        bodyColor: theme.tooltipText
                                    }
                                },
                                scales: {
                                    x: {
                                        ticks: { color: theme.muted, maxRotation: 30, minRotation: 30 },
                                        grid: { color: theme.grid, drawBorder: false }
                                    },
                                    y: {
                                        beginAtZero: true,
                                        ticks: { color: theme.muted },
                                        grid: { color: theme.grid, drawBorder: false }
                                    }
                                }
                            }
                        });
                    }
                }

                if (metricsData.ok) {
                    const typeCounts = metricsData.metrics?.incidents_by_type || {};
                    let typeLabels = ['Medical','Fire','Police','Traffic', 'Other'];
                    let typeValues = [
                        typeCounts.medical || 0,
                        typeCounts.fire || 0,
                        typeCounts.police || 0,
                        typeCounts.traffic || 0,
                        typeCounts.other || 0,
                    ];
                    // Apply incident type filter if present
                    if (filters.type) {
                        const map = { medical: 'Medical', fire: 'Fire', police: 'Police', traffic: 'Traffic', accident: 'Traffic', crime: 'Police', other: 'Other' };
                        const wanted = map[filters.type] || filters.type;
                        const idx = typeLabels.indexOf(wanted);
                        if (idx >= 0) { typeLabels = [typeLabels[idx]]; typeValues = [typeValues[idx]]; } else { typeLabels = []; typeValues = []; }
                    }
                    const ctx2 = document.getElementById('incidentsTypesChart');
                    if (ctx2) {
                        if (typesChart) typesChart.destroy();
                        typesChart = new Chart(ctx2, {
                            type: 'bar',
                            data: {
                                labels: typeLabels,
                                datasets: [{
                                    label: 'Incidents by Type',
                                    data: typeValues,
                                    backgroundColor: ['#ef4444','#f59e0b','#3b82f6','#22c55e','#94a3b8'],
                                    borderRadius: 999,
                                    borderSkipped: false,
                                    barThickness: 28,
                                    maxBarThickness: 32
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                indexAxis: 'y',
                                layout: { padding: { right: 28 } },
                                plugins: {
                                    legend: { display: false },
                                    reportBarValueLabels: { color: theme.text },
                                    tooltip: {
                                        backgroundColor: theme.tooltipBg,
                                        borderColor: theme.tooltipBorder,
                                        borderWidth: 1,
                                        titleColor: theme.tooltipText,
                                        bodyColor: theme.tooltipText,
                                        callbacks: {
                                            label(context) {
                                                return `${context.label}: ${context.raw} incident(s)`;
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    x: {
                                        beginAtZero: true,
                                        ticks: { precision: 0, stepSize: 1, color: theme.muted },
                                        grid: { color: theme.grid, drawBorder: false }
                                    },
                                    y: {
                                        ticks: { color: theme.text, font: { weight: '700' } },
                                        grid: { display: false, drawBorder: false }
                                    }
                                }
                            },
                            plugins: [reportBarValueLabelsPlugin]
                        });
                    }

                    const priorityCounts = metricsData.metrics?.incidents_by_priority || {};
                    let priorityLabels = ['Critical', 'High', 'Medium', 'Low'];
                    let priorityValues = [
                        priorityCounts.critical || 0,
                        priorityCounts.high || 0,
                        priorityCounts.medium || 0,
                        priorityCounts.low || 0
                    ];
                    if (filters.priority) {
                        const wantedPriority = String(filters.priority).toLowerCase();
                        const idx = ['critical', 'high', 'medium', 'low'].indexOf(wantedPriority);
                        if (idx >= 0) {
                            priorityLabels = [priorityLabels[idx]];
                            priorityValues = [priorityValues[idx]];
                        }
                    }
                    const ctx3 = document.getElementById('callDurationChart');
                    if (ctx3) {
                        if (callDurationChart) callDurationChart.destroy();
                        callDurationChart = new Chart(ctx3, {
                            type: 'doughnut',
                            data: {
                                labels: priorityLabels,
                                datasets: [{
                                    label: 'Priority Level Oversight',
                                    data: priorityValues,
                                    backgroundColor: ['#b91c1c', '#ef4444', '#f59e0b', '#22c55e'],
                                    borderColor: ['#fecaca', '#fee2e2', '#fde68a', '#bbf7d0'],
                                    borderWidth: 2,
                                    hoverOffset: 10,
                                    spacing: 3,
                                    borderRadius: 8
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                cutout: '56%',
                                plugins: {
                                    legend: {
                                        position: 'bottom',
                                        labels: { color: theme.text }
                                    },
                                    reportDoughnutSliceLabel: {
                                        color: '#ffffff'
                                    },
                                    reportDoughnutCenter: {
                                        valueColor: theme.text,
                                        labelColor: theme.muted
                                    },
                                    tooltip: {
                                        backgroundColor: theme.tooltipBg,
                                        borderColor: theme.tooltipBorder,
                                        borderWidth: 1,
                                        titleColor: theme.tooltipText,
                                        bodyColor: theme.tooltipText,
                                        callbacks: {
                                            label(context) {
                                                const value = Number(context.raw || 0);
                                                const total = context.dataset.data.reduce((sum, item) => sum + Number(item || 0), 0);
                                                const percentage = total ? ((value / total) * 100).toFixed(1).replace(/\.0$/, '') : '0';
                                                return `${context.label}: ${value} (${percentage}%)`;
                                            }
                                        }
                                    }
                                }
                            },
                            plugins: [reportDoughnutCenterPlugin, reportDoughnutSliceLabelPlugin]
                        });
                    }
                }

                if (dispData.ok) {
                    const byUnitType = dispData.metrics?.by_unit_type || {};
                    const labels = ['Ambulance', 'Fire', 'Police', 'Rescue', 'Other'];
                    const values = [
                        byUnitType.ambulance || 0,
                        byUnitType.fire || 0,
                        byUnitType.police || 0,
                        byUnitType.rescue || 0,
                        byUnitType.other || 0
                    ];
                    const ctx4 = document.getElementById('dispatchDailyChart');
                    if (ctx4) {
                        if (dispatchDailyChart) dispatchDailyChart.destroy();
                        dispatchDailyChart = new Chart(ctx4, {
                            type: 'polarArea',
                            data: {
                                labels,
                                datasets: [{
                                    label: 'Dispatch Load',
                                    data: values,
                                    backgroundColor: [
                                        'rgba(59, 130, 246, 0.72)',
                                        'rgba(239, 68, 68, 0.72)',
                                        'rgba(245, 158, 11, 0.72)',
                                        'rgba(34, 197, 94, 0.72)',
                                        'rgba(148, 163, 184, 0.72)'
                                    ],
                                    borderColor: ['#93c5fd','#fca5a5','#fcd34d','#86efac','#cbd5e1'],
                                    borderWidth: 2
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: {
                                        position: 'bottom',
                                        labels: { color: theme.text }
                                    },
                                    tooltip: {
                                        backgroundColor: theme.tooltipBg,
                                        borderColor: theme.tooltipBorder,
                                        borderWidth: 1,
                                        titleColor: theme.tooltipText,
                                        bodyColor: theme.tooltipText,
                                        callbacks: {
                                            label(context) {
                                                return `${context.label}: ${context.raw} dispatch(es)`;
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    r: {
                                        beginAtZero: true,
                                        ticks: {
                                            precision: 0,
                                            color: theme.muted,
                                            backdropColor: 'transparent'
                                        },
                                        grid: { color: theme.grid },
                                        angleLines: { color: theme.grid },
                                        pointLabels: { color: theme.text }
                                    }
                                }
                            }
                        });
                    }
                }
            } catch (e) {
                console.error('refreshCharts failed', e);
            }
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
            const res = await fetch('api/report_metrics.php' + qs, { cache: 'no-store' });
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
                const dispRes = await fetch('api/reports_dispatch.php' + qs, { cache: 'no-store' });
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
                    dispatchUnitBreakdown = Array.isArray(disp.all_units) ? disp.all_units : [];
                    renderDispatchTopUnits(disp.summary_by_service || {});
                }
            } catch (e) {
                console.error('refreshMetrics failed', e);
            }
        }

        async function loadRecentIncidents(filters = {}) {
            try {
                const tbody = document.getElementById('recentIncidentsBody');
                if (tbody) tbody.innerHTML = '<tr><td colspan="7" style="color:#6b7280">Loading incidents…</td></tr>';
                const qs = buildQuery(filters);
                const res = await fetch('api/incidents_list.php' + qs, { cache: 'no-store' });
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

        function formatDispatchServiceLabel(key) {
            switch ((key || '').toLowerCase()) {
                case 'ambulance': return { title: 'Ambulance', linked: 'Medical' };
                case 'fire': return { title: 'Fire', linked: 'Fire' };
                case 'police': return { title: 'Police', linked: 'Police' };
                case 'traffic': return { title: 'Traffic', linked: 'Traffic' };
                default: return { title: key || 'Other', linked: 'Other' };
            }
        }

        function renderDispatchTopUnits(summary) {
            const tbody = document.getElementById('dispatchTopUnitsBody');
            if (!tbody) return;
            const orderedKeys = ['ambulance', 'fire', 'police', 'traffic'];

            tbody.innerHTML = orderedKeys.map((key) => {
                const label = formatDispatchServiceLabel(key);
                const count = Number(summary?.[key] || 0);
                return `
                <tr>
                    <td>${label.title}</td>
                    <td>${label.linked}</td>
                    <td>${count}</td>
                </tr>`;
            }).join('');
        }

        function showAllDispatchUnitsModal() {
            const overlay = document.createElement('div');
            overlay.className = 'incident-modal-overlay';
            const modal = document.createElement('div');
            modal.className = 'incident-modal';

            const rows = dispatchUnitBreakdown.length
                ? dispatchUnitBreakdown.map((unit) => `
                    <tr>
                        <td>${unit.identifier || '—'}</td>
                        <td>${(unit.unit_type || '').charAt(0).toUpperCase() + (unit.unit_type || '').slice(1)}</td>
                        <td>${Number(unit.count || 0)}</td>
                    </tr>
                `).join('')
                : `<tr><td colspan="3" style="color:#6b7280; text-align:center;">No dispatches found for selected period</td></tr>`;

            modal.innerHTML = `
                <div class="incident-modal-header">
                    <h3>All Vehicle Dispatches</h3>
                    <button class="incident-modal-close" aria-label="Close">&times;</button>
                </div>
                <div class="incident-modal-body">
                    <div style="max-height: 420px; overflow-y: auto;">
                        <table class="analytics-table">
                            <thead>
                                <tr>
                                    <th>Vehicle</th>
                                    <th>Type</th>
                                    <th>Dispatches</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${rows}
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="incident-modal-footer">
                    <button class="btn-report" id="dispatch-modal-close-btn"><i class="fas fa-times"></i> Close</button>
                </div>
            `;

            overlay.appendChild(modal);
            document.body.appendChild(overlay);

            function closeModal() {
                overlay.remove();
            }

            overlay.addEventListener('click', (e) => {
                if (e.target === overlay) closeModal();
            });
            modal.querySelector('.incident-modal-close').addEventListener('click', closeModal);
            modal.querySelector('#dispatch-modal-close-btn').addEventListener('click', closeModal);
            document.addEventListener('keydown', function escHandler(e) {
                if (e.key === 'Escape') {
                    closeModal();
                    document.removeEventListener('keydown', escHandler);
                }
            });
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
            if (cardResp) cardResp.style.cursor='pointer', cardResp.addEventListener('click', () => window.open('api/reports_performance.php' + buildQuery(currentFilters), '_blank'));
            if (cardInc) cardInc.style.cursor='pointer', cardInc.addEventListener('click', () => navigateTo('admin/review.php', { period: currentFilters.period || defaultReportFilters.period, start: currentFilters.start, end: currentFilters.end }));
            if (cardRes) cardRes.style.cursor='pointer', cardRes.addEventListener('click', () => navigateTo('admin/resources.php'));
            if (cardPerf) cardPerf.style.cursor='pointer', cardPerf.addEventListener('click', () => window.open('api/reports_incident_summary.php' + buildQuery(currentFilters), '_blank'));
            setInterval(function () {
                if (document.hidden) return;
                try {
                    refreshMetrics(currentFilters);
                    refreshCharts(currentFilters);
                    loadRecentIncidents(currentFilters);
                } catch (e) {
                    console.error('report auto-refresh failed', e);
                }
            }, 10000);
        });

        document.addEventListener('themeChanged', function() {
            refreshCharts(currentFilters);
        });
    </script>
</body>
</html>
