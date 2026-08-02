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


            <div style="height: 1rem;"></div>
            <!-- Key Metrics Overview -->
            <div class="analytics-grid">
                <div class="analytics-card response-time">
                    <div class="metric-label">SLA Response Average (Hours)</div>
                    <div class="metric-display">
                        <div class="metric-value" id="metricAvgResponse"><?php echo number_format($avgResponseTime / 60, 1); ?> hr</div>
                        <div class="metric-change positive">
                            <i class="fas fa-arrow-down"></i>
                            
                        </div>
                    </div>
                    <div style="color: #666; font-size: 0.9rem;">Admin target: &lt; 0.2 hr</div>
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
                            <option value="dispatch">Failed Dispatch Attempts</option>
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
            <div class="ai-insights-section" data-report-section="summary incident performance resource dispatch trend">
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
            <div class="quick-reports" data-report-section="summary trend">
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

                <div class="report-card" onclick="generateTrendReport()">
                    <div class="report-icon trend">
                        <i class="fas fa-chart-line"></i>
                    </div>
                    <div class="report-title">Trend Monitoring Report</div>
                    <div class="report-description">Daily incident trend report by service category for planning and forecasting.</div>
                    <div class="report-actions">
                        <button class="btn-report primary" onclick="generateTrendReport()">
                            <i class="fas fa-file-pdf"></i> Generate
                        </button>
                        <button class="btn-report" onclick="viewTrendReport()">
                            <i class="fas fa-eye"></i> View
                        </button>
                    </div>
                </div>
            </div>

            <div class="export-section" data-report-section="summary incident performance resource dispatch trend">
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1rem; display: flex; align-items: center;">
                    <i class="fas fa-file-export" style="margin-right: 0.5rem; color: #0f766e;"></i>
                    Export Dashboard Data
                </h2>
                <div class="export-options">
                    <button class="export-btn" type="button" onclick="exportPDF()">
                        <i class="fas fa-file-pdf"></i>
                        <span>PDF</span>
                    </button>
                    <button class="export-btn" type="button" onclick="exportExcel()">
                        <i class="fas fa-file-excel"></i>
                        <span>Excel</span>
                    </button>
                    <button class="export-btn" type="button" onclick="exportCSV()">
                        <i class="fas fa-file-csv"></i>
                        <span>CSV</span>
                    </button>
                    <button class="export-btn" type="button" onclick="exportJSON()">
                        <i class="fas fa-code"></i>
                        <span>JSON</span>
                    </button>
                </div>
            </div>

            <!-- Charts Section -->
            <div class="charts-grid">
            <div class="chart-container" data-report-section="summary performance trend">
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


            <div class="chart-container" data-report-section="summary incident trend">
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
            <div class="chart-container" data-report-section="summary incident">
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

            <div class="chart-container" data-report-section="summary resource performance dispatch">
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

            <!-- Admin Performance Review Chart -->
            <div class="chart-container" data-report-section="summary performance">
                <div class="chart-header">
                    <h3 class="chart-title">Admin Performance Review</h3>
                    <div class="chart-controls">
                        <button class="btn-report" onclick="refreshPerformanceChart()"><i class="fas fa-sync"></i> Refresh</button>
                        <button class="btn-report" onclick="exportChart('performanceChart')"><i class="fas fa-download"></i> Export</button>
                    </div>
                </div>
                <div style="position: relative; width: 100%; height: 320px;">
                    <canvas id="performanceChart" class="chart-canvas"></canvas>
                </div>
            </div>

            <!-- Resources Audit Report Chart -->
            <div class="chart-container" data-report-section="summary resource">
                <div class="chart-header">
                    <h3 class="chart-title">Resources Audit Report</h3>
                    <div class="chart-controls">
                        <button class="btn-report" onclick="refreshResourcesChart()"><i class="fas fa-sync"></i> Refresh</button>
                        <button class="btn-report" onclick="exportChart('resourcesChart')"><i class="fas fa-download"></i> Export</button>
                    </div>
                </div>
                <div style="position: relative; width: 100%; height: 320px;">
                    <canvas id="resourcesChart" class="chart-canvas"></canvas>
                </div>
            </div>
            </div>

            <!-- Dispatch Breakdown Table -->
            <div class="data-table" data-report-section="summary resource performance dispatch">
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

            <!-- Failed Dispatch Attempts Table -->
            <div class="data-table" data-report-section="summary performance dispatch">
                <div class="table-header" style="display:flex; align-items:center; justify-content:space-between; gap:1rem; flex-wrap:wrap;">
                    <div>
                        <h3 class="table-title">Failed Dispatch Attempts</h3>
                        <div style="color:#6b7280; font-size:0.85rem; margin-top:0.25rem;">
                            Recorded validation failures, cancelled dispatches, and unacknowledged assignments beyond the response threshold.
                        </div>
                    </div>
                    <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                        <span class="status-badge status-critical">Failed: <span id="failedDispatchTotal">0</span></span>
                        <span class="status-badge status-pending">Stale: <span id="failedDispatchStale">0</span></span>
                        <span class="status-badge status-pending">Cancelled: <span id="failedDispatchCancelled">0</span></span>
                    </div>
                </div>
                <div class="table-container">
                    <table class="analytics-table scrollable">
                        <thead>
                            <tr>
                                <th>Attempted At</th>
                                <th>Incident</th>
                                <th>Unit</th>
                                <th>Type</th>
                                <th>Priority</th>
                                <th>Failure</th>
                                <th>Source</th>
                                <th>Recovery</th>
                            </tr>
                        </thead>
                        <tbody id="failedDispatchAttemptsBody">
                            <tr><td colspan="8" style="color:#6b7280">Loading failed dispatch attempts...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- Recent Incidents Table -->
            <div class="data-table" data-report-section="summary incident">
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
                                <th>Response Time (Hours)</th>
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
            <div class="data-table" data-report-section="summary performance">
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
            <div class="alerts-section" data-report-section="summary">
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

    let renderAlertsInFlight = false;
    async function renderAlerts() {
        const container = document.getElementById('alerts-dynamic');
        if (!container) return;
        if (renderAlertsInFlight) return;
        renderAlertsInFlight = true;

        try {
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
        } finally {
            renderAlertsInFlight = false;
        }
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
        setInterval(() => {
            if (!document.hidden) renderAlerts();
        }, 10000);
    });
    </script>

        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <script>
        // Emergency Response Analytics & Reporting Functionality
        let currentFilters = {};
        let lastReportMetrics = {};
        let lastDispatchReport = {};
        let lastFailedDispatchAttempts = [];
        let lastRecentIncidents = [];
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
        function escapeHtml(value) {
            return String(value == null ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }
        function minutesToHours(value) {
            const minutes = Number(value || 0);
            return minutes / 60;
        }
        function formatHours(value) {
            return minutesToHours(value).toFixed(1) + ' hr';
        }
        function getReportView() {
            return document.getElementById('report-type') ? document.getElementById('report-type').value : '';
        }
        function applyReportView() {
            const view = getReportView();
            document.querySelectorAll('[data-report-section]').forEach((section) => {
                const sectionViews = String(section.getAttribute('data-report-section') || '').split(/\s+/).filter(Boolean);
                section.hidden = view !== '' && !sectionViews.includes(view);
            });

            [responseChart, typesChart, callDurationChart, dispatchDailyChart, performanceChart, resourcesChart].forEach((chart) => {
                if (chart && typeof chart.resize === 'function') {
                    chart.resize();
                }
            });
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
                const res = await fetch('api/ai_report_insights.php' + buildQuery(currentFilters), { cache: 'no-store' });
                const data = await res.json();
                if (container) {
                    if (data.ok && data.text) {
                        container.innerHTML = '<div class="ai-insight-text">' + escapeHtml(data.text || '').replace(/\n/g,'<br>') + '</div>';
                        showNotification('AI insights refreshed', 'success');
                    } else {
                        const msg = (data && data.error) ? String(data.error) : 'Unable to generate AI insights at this time.';
                        container.innerHTML = '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(msg).replace(/\n/g,'<br>') + '</div>';
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
            applyReportView();
            showNotification('Applying filters to reports...', 'info');
            refreshMetrics(currentFilters);
            refreshCharts(currentFilters);
            loadRecentIncidents(currentFilters);
            refreshAIInsights();
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
            applyReportView();
            refreshMetrics(currentFilters);
            refreshCharts(currentFilters);
            loadRecentIncidents(currentFilters);
            refreshAIInsights();
            showNotification('Filters cleared', 'success');
        }

        // Export functions
        function exportPDF() {
            showNotification('Opening print dialog for PDF export...', 'info');
            window.print();
        }

        function downloadTextFile(filename, content, mimeType) {
            const blob = new Blob([content], { type: mimeType });
            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = filename;
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
            URL.revokeObjectURL(url);
        }

        function csvCell(value) {
            const text = String(value == null ? '' : value);
            return /[",\n\r]/.test(text) ? '"' + text.replace(/"/g, '""') + '"' : text;
        }

        function reportDateStamp() {
            return new Date().toISOString().slice(0, 19).replace(/[:T]/g, '-');
        }

        async function collectReportExportData() {
            const qs = buildQuery(currentFilters);
            const [metricsRes, dispatchRes, incidentsRes] = await Promise.all([
                fetch('api/report_metrics.php' + qs, { cache: 'no-store' }),
                fetch('api/reports_dispatch.php' + qs, { cache: 'no-store' }),
                fetch('api/incidents_list.php' + qs, { cache: 'no-store' })
            ]);
            const [metricsData, dispatchData, incidentsData] = await Promise.all([
                metricsRes.json(),
                dispatchRes.json(),
                incidentsRes.json()
            ]);

            return {
                generated_at: new Date().toISOString(),
                admin_view: getReportView() || 'executive_summary',
                filters: Object.assign({}, currentFilters),
                metrics: metricsData.ok ? (metricsData.metrics || {}) : {},
                dispatch: dispatchData.ok ? {
                    metrics: dispatchData.metrics || {},
                    summary_by_service: dispatchData.summary_by_service || {},
                    top_units: dispatchData.top_units || [],
                    all_units: dispatchData.all_units || [],
                    failed_attempts: dispatchData.failed_attempts || []
                } : {},
                recent_incidents: incidentsData.ok ? (incidentsData.items || []) : []
            };
        }

        async function exportExcel() {
            try {
                showNotification('Exporting data to Excel...', 'info');
                const data = await collectReportExportData();
                const metricsRows = Object.entries(data.metrics || {}).map(([key, value]) => {
                    const displayValue = typeof value === 'object' ? JSON.stringify(value) : value;
                    return `<tr><td>${escapeHtml(key)}</td><td>${escapeHtml(displayValue)}</td></tr>`;
                }).join('');
                const incidentRows = (data.recent_incidents || []).map((item) => `
                    <tr>
                        <td>${escapeHtml(item.incident_code || item.id || '')}</td>
                        <td>${escapeHtml(item.type || '')}</td>
                        <td>${escapeHtml(item.priority || '')}</td>
                        <td>${escapeHtml(item.status || '')}</td>
                        <td>${escapeHtml(item.location || '')}</td>
                        <td>${escapeHtml(item.response_time_min != null ? formatHours(item.response_time_min) : '')}</td>
                    </tr>
                `).join('');
                const unitRows = ((data.dispatch && data.dispatch.all_units) || []).map((unit) => `
                    <tr>
                        <td>${escapeHtml(unit.identifier || '')}</td>
                        <td>${escapeHtml(unit.unit_type || '')}</td>
                        <td>${escapeHtml(unit.count || 0)}</td>
                    </tr>
                `).join('');
                const failedRows = ((data.dispatch && data.dispatch.failed_attempts) || []).map((item) => `
                    <tr>
                        <td>${escapeHtml(item.attempted_at || '')}</td>
                        <td>${escapeHtml(item.reference_no || item.incident_id || '')}</td>
                        <td>${escapeHtml(item.unit_identifier || item.unit_id || '')}</td>
                        <td>${escapeHtml(item.incident_type || '')}</td>
                        <td>${escapeHtml(item.priority || '')}</td>
                        <td>${escapeHtml(item.failure_reason || '')}</td>
                        <td>${escapeHtml(item.source || '')}</td>
                        <td>${escapeHtml(item.recovery_status || '')}</td>
                    </tr>
                `).join('');
                const html = `
                    <html><head><meta charset="UTF-8"></head><body>
                    <h1>ERS Report Analytics Export</h1>
                    <p>Generated: ${escapeHtml(data.generated_at)}</p>
                    <h2>Metrics</h2><table border="1"><tbody>${metricsRows}</tbody></table>
                    <h2>Recent Incidents</h2><table border="1"><thead><tr><th>Incident</th><th>Type</th><th>Priority</th><th>Status</th><th>Location</th><th>Response Time (Hours)</th></tr></thead><tbody>${incidentRows}</tbody></table>
                    <h2>Dispatch Units</h2><table border="1"><thead><tr><th>Unit</th><th>Type</th><th>Dispatches</th></tr></thead><tbody>${unitRows}</tbody></table>
                    <h2>Failed Dispatch Attempts</h2><table border="1"><thead><tr><th>Attempted At</th><th>Incident</th><th>Unit</th><th>Type</th><th>Priority</th><th>Failure</th><th>Source</th><th>Recovery Status</th></tr></thead><tbody>${failedRows}</tbody></table>
                    </body></html>
                `;
                downloadTextFile('ers-report-' + reportDateStamp() + '.xls', html, 'application/vnd.ms-excel;charset=utf-8');
                showNotification('Excel export downloaded', 'success');
            } catch (e) {
                showNotification('Failed to export Excel file', 'error');
            }
        }

        async function exportCSV() {
            try {
                showNotification('Exporting data to CSV...', 'info');
                const data = await collectReportExportData();
                const rows = [
                    ['Section', 'Field', 'Value'],
                    ...Object.entries(data.metrics || {}).map(([key, value]) => ['Metrics', key, typeof value === 'object' ? JSON.stringify(value) : value]),
                    [],
                    ['Incident ID', 'Type', 'Priority', 'Status', 'Location', 'Response Time (Hours)'],
                    ...(data.recent_incidents || []).map((item) => [
                        item.incident_code || item.id || '',
                        item.type || '',
                        item.priority || '',
                        item.status || '',
                        item.location || '',
                        item.response_time_min != null ? formatHours(item.response_time_min) : ''
                    ]),
                    [],
                    ['Unit', 'Unit Type', 'Dispatches'],
                    ...(((data.dispatch && data.dispatch.all_units) || []).map((unit) => [
                        unit.identifier || '',
                        unit.unit_type || '',
                        unit.count || 0
                    ])),
                    [],
                    ['Failed Dispatch Attempted At', 'Incident', 'Unit', 'Type', 'Priority', 'Failure', 'Source', 'Recovery Status'],
                    ...(((data.dispatch && data.dispatch.failed_attempts) || []).map((item) => [
                        item.attempted_at || '',
                        item.reference_no || item.incident_id || '',
                        item.unit_identifier || item.unit_id || '',
                        item.incident_type || '',
                        item.priority || '',
                        item.failure_reason || '',
                        item.source || '',
                        item.recovery_status || ''
                    ]))
                ];
                const csv = rows.map((row) => row.map(csvCell).join(',')).join('\r\n');
                downloadTextFile('ers-report-' + reportDateStamp() + '.csv', csv, 'text/csv;charset=utf-8');
                showNotification('CSV export downloaded', 'success');
            } catch (e) {
                showNotification('Failed to export CSV file', 'error');
            }
        }

        async function exportJSON() {
            try {
                showNotification('Exporting data to JSON...', 'info');
                const data = await collectReportExportData();
                downloadTextFile('ers-report-' + reportDateStamp() + '.json', JSON.stringify(data, null, 2), 'application/json;charset=utf-8');
                showNotification('JSON export downloaded', 'success');
            } catch (e) {
                showNotification('Failed to export JSON file', 'error');
            }
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
            .dispatch-recovery-actions { display:flex; flex-wrap:wrap; gap:0.35rem; align-items:center; }
            .dispatch-recovery-btn { border:0; border-radius:6px; padding:0.35rem 0.55rem; font-size:0.78rem; font-weight:700; cursor:pointer; color:#fff; background:#2563eb; }
            .dispatch-recovery-btn.cancel { background:#dc2626; }
            .dispatch-recovery-btn.close { background:#4b5563; }
            .dispatch-recovery-btn:disabled { opacity:0.6; cursor:progress; transform:none; }
            .chart-canvas { width: 100% !important; height: 100% !important; display: block; }
            .chart-loading { position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; background: rgba(255,255,255,0.7); color:#374151; font-weight:600; gap:8px; z-index:5; border-radius:10px; }
            .chart-spinner { width:22px; height:22px; border:3px solid #cfe0ff; border-top-color:#3b82f6; border-radius:50%; animation: spin 0.8s linear infinite; }
            @keyframes spin { to { transform: rotate(360deg); } }
            /* Scrollable Recent Incidents table with fixed header */
            .analytics-table.scrollable thead, .analytics-table.scrollable tbody { display: block; }
            .analytics-table.scrollable tbody { max-height: 360px; overflow-y: auto; overflow-x: hidden; scrollbar-gutter: stable; }
            .analytics-table.scrollable thead tr, .analytics-table.scrollable tbody tr { display: table; width: 100%; table-layout: fixed; }
        `;
        document.head.appendChild(style);
        // Charts
        let responseChart = null;
        let typesChart = null;
        let callDurationChart = null;
        let dispatchDailyChart = null;
        let performanceChart = null;
        let resourcesChart = null;
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
                    const data = (respData.data || []).map((value) => minutesToHours(value));
                    const ctx = document.getElementById('responseTimeChart');
                    if (ctx) {
                        if (responseChart) responseChart.destroy();
                        const chartCtx = ctx.getContext('2d');
                        responseChart = new Chart(ctx, {
                            type: 'line',
                            data: {
                                labels,
                                datasets: [{
                                    label: 'Avg Response Time (hr)',
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
                                    label: 'Admin Target (<0.2 hr)',
                                    data: labels.map(() => minutesToHours(10)),
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

                // Admin Performance Review Chart
                if (metricsData.ok) {
                    const performanceMetrics = metricsData.metrics || {};
                    const perfLabels = ['Response Time', 'Success Rate', 'Utilization', 'Availability'];
                    const perfValues = [
                        Math.min(100, Math.max(0, 100 - ((performanceMetrics.avg_response_time_min || 10) / 10 * 20))),
                        performanceMetrics.success_rate || 0,
                        performanceMetrics.resource_utilization || 0,
                        performanceMetrics.uptime_percentage || 95
                    ];
                    const ctx5 = document.getElementById('performanceChart');
                    if (ctx5) {
                        if (performanceChart) performanceChart.destroy();
                        performanceChart = new Chart(ctx5, {
                            type: 'radar',
                            data: {
                                labels: perfLabels,
                                datasets: [{
                                    label: 'Admin Performance Metrics',
                                    data: perfValues,
                                    borderColor: '#3b82f6',
                                    backgroundColor: 'rgba(59, 130, 246, 0.15)',
                                    pointBackgroundColor: '#3b82f6',
                                    pointBorderColor: '#fff',
                                    pointHoverBackgroundColor: '#fff',
                                    pointHoverBorderColor: '#3b82f6',
                                    pointRadius: 5,
                                    pointHoverRadius: 7,
                                    fill: true,
                                    tension: 0.4,
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
                                                return `${context.label}: ${Number(context.raw || 0).toFixed(1)}%`;
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    r: {
                                        min: 0,
                                        max: 100,
                                        beginAtZero: true,
                                        ticks: {
                                            precision: 0,
                                            color: theme.muted,
                                            backdropColor: 'transparent',
                                            stepSize: 20
                                        },
                                        grid: { color: theme.grid },
                                        angleLines: { color: theme.grid },
                                        pointLabels: { color: theme.text, font: { weight: '600' } }
                                    }
                                }
                            }
                        });
                    }
                }

                // Resources Audit Report Chart
                if (dispData.ok) {
                    const resAudit = dispData.metrics || {};
                    const auditLabels = ['Available', 'In Use', 'Maintenance', 'Out of Service'];
                    const auditValues = [
                        resAudit.available_units || 0,
                        resAudit.in_use_units || 0,
                        resAudit.maintenance_units || 0,
                        resAudit.unavailable_units || 0
                    ];
                    const ctx6 = document.getElementById('resourcesChart');
                    if (ctx6) {
                        if (resourcesChart) resourcesChart.destroy();
                        resourcesChart = new Chart(ctx6, {
                            type: 'bar',
                            data: {
                                labels: auditLabels,
                                datasets: [{
                                    label: 'Resource Status',
                                    data: auditValues,
                                    backgroundColor: [
                                        'rgba(34, 197, 94, 0.8)',
                                        'rgba(59, 130, 246, 0.8)',
                                        'rgba(245, 158, 11, 0.8)',
                                        'rgba(239, 68, 68, 0.8)'
                                    ],
                                    borderColor: ['#22c55e', '#3b82f6', '#f59e0b', '#ef4444'],
                                    borderRadius: 8,
                                    borderSkipped: false,
                                    barThickness: 32,
                                    maxBarThickness: 40
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                indexAxis: 'x',
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        backgroundColor: theme.tooltipBg,
                                        borderColor: theme.tooltipBorder,
                                        borderWidth: 1,
                                        titleColor: theme.tooltipText,
                                        bodyColor: theme.tooltipText,
                                        callbacks: {
                                            label(context) {
                                                return `${context.label}: ${context.raw} unit(s)`;
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    x: {
                                        ticks: { color: theme.text, font: { weight: '600' } },
                                        grid: { color: theme.grid, drawBorder: false }
                                    },
                                    y: {
                                        beginAtZero: true,
                                        ticks: { precision: 0, stepSize: 1, color: theme.muted },
                                        grid: { color: theme.grid, drawBorder: false }
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

        function performanceTrend(current, previous, lowerIsBetter, tolerance = 0.1) {
            if (previous === null || previous === undefined || Number.isNaN(Number(previous))) {
                return { className: 'trend-neutral', icon: 'fa-minus', label: 'No baseline' };
            }
            const delta = Number(current) - Number(previous);
            if (Math.abs(delta) <= tolerance) {
                return { className: 'trend-neutral', icon: 'fa-minus', label: 'Stable' };
            }
            const improved = lowerIsBetter ? delta < 0 : delta > 0;
            return improved
                ? { className: 'trend-up', icon: 'fa-arrow-up', label: 'Improving' }
                : { className: 'trend-down', icon: 'fa-arrow-down', label: 'Needs attention' };
        }

        function performanceTargetTrend(value, min, max) {
            const current = Number(value);
            if (current >= min && current <= max) {
                return { className: 'trend-up', icon: 'fa-check', label: 'Within target' };
            }
            if (current < min) {
                return { className: 'trend-neutral', icon: 'fa-arrow-down', label: 'Below target' };
            }
            return { className: 'trend-down', icon: 'fa-arrow-up', label: 'Above target' };
        }

        function performanceStatus(label, tone) {
            const statusClass = tone === 'good' ? 'status-resolved' : (tone === 'warn' ? 'status-pending' : 'status-critical');
            return `<span class="status-badge ${statusClass}">${label}</span>`;
        }

        function performanceTrendHtml(trend) {
            return `<div class="trend-indicator ${trend.className}"><i class="fas ${trend.icon}"></i> ${trend.label}</div>`;
        }

        function updatePerformanceRow(row, value, target, trend, statusHtml) {
            if (!row) return;
            const cells = row.querySelectorAll('td');
            if (cells[1]) cells[1].textContent = value;
            if (cells[2]) cells[2].textContent = target;
            if (cells[3]) cells[3].innerHTML = performanceTrendHtml(trend);
            if (cells[4]) cells[4].innerHTML = statusHtml;
        }

        function renderPerformanceMetrics(m) {
            const sections = Array.from(document.querySelectorAll('.data-table'));
            const section = sections.find((item) => item.querySelector('.table-title')?.textContent?.trim() === 'Performance Metrics');
            const perfTable = section ? section.querySelector('table tbody') : null;
            if (!perfTable) return;

            const rows = perfTable.querySelectorAll('tr');
            const avgResponseSamples = Number(m.avg_response_sample_count || 0);
            const avgResponse = Number(m.avg_response_time_min || 0);
            const previousAvgResponse = m.previous_avg_response_time_min;
            const totalIncidents = Number(m.total_incidents_month || 0);
            const successRate = Number(m.success_rate || 0);
            const previousSuccessRate = m.previous_success_rate;
            const utilization = Number(m.resource_utilization || 0);

            if (avgResponseSamples > 0) {
                const responseTone = avgResponse <= 10 ? 'good' : (avgResponse <= 15 ? 'warn' : 'bad');
                const responseLabel = avgResponse <= 10 ? 'On Target' : (avgResponse <= 15 ? 'Watch' : 'Delayed');
                updatePerformanceRow(rows[0], formatHours(avgResponse), '< 0.2 hr', performanceTrend(avgResponse, previousAvgResponse, true), performanceStatus(responseLabel, responseTone));
            } else {
                updatePerformanceRow(rows[0], 'No completed dispatches', '< 0.2 hr', { className: 'trend-neutral', icon: 'fa-minus', label: 'No data' }, performanceStatus('Unavailable', 'warn'));
            }

            if (totalIncidents > 0) {
                const resolutionTone = successRate >= 95 ? 'good' : (successRate >= 80 ? 'warn' : 'bad');
                const resolutionLabel = successRate >= 95 ? 'Excellent' : (successRate >= 80 ? 'Below Target' : 'Needs Action');
                updatePerformanceRow(rows[1], `${successRate.toFixed(1)}%`, '>= 95%', performanceTrend(successRate, previousSuccessRate, false), performanceStatus(resolutionLabel, resolutionTone));
            } else {
                updatePerformanceRow(rows[1], 'No incidents', '>= 95%', { className: 'trend-neutral', icon: 'fa-minus', label: 'No data' }, performanceStatus('Unavailable', 'warn'));
            }

            const utilizationTone = utilization >= 70 && utilization <= 85 ? 'good' : (utilization >= 55 && utilization <= 95 ? 'warn' : 'bad');
            const utilizationLabel = utilization >= 70 && utilization <= 85 ? 'Optimal' : (utilization < 70 ? 'Underused' : 'Overloaded');
            updatePerformanceRow(rows[2], `${utilization.toFixed(1)}%`, '70-85%', performanceTargetTrend(utilization, 70, 85), performanceStatus(utilizationLabel, utilizationTone));

            updatePerformanceRow(rows[3], 'Not tracked', 'Minimize', { className: 'trend-neutral', icon: 'fa-minus', label: 'No data' }, performanceStatus('Unavailable', 'warn'));
            updatePerformanceRow(rows[4], 'Not tracked', '<= 10%', { className: 'trend-neutral', icon: 'fa-minus', label: 'No data' }, performanceStatus('Unavailable', 'warn'));
        }

        async function refreshMetrics(filters = {}) {
            try {
            const qs = buildQuery(filters);
            const res = await fetch('api/report_metrics.php' + qs, { cache: 'no-store' });
                const data = await res.json();
                if (!data.ok) return;
                const m = data.metrics || {};
                lastReportMetrics = m;
                const avgEl = document.getElementById('metricAvgResponse');
                const monthEl = document.getElementById('metricIncidentsMonth');
                const lastEl = document.getElementById('metricLastMonth');
                const deltaEl = document.getElementById('metricIncidentsDelta');
                const utilEl = document.getElementById('metricUtilization');
                const successEl = document.getElementById('metricSuccess');
                if (avgEl) avgEl.textContent = formatHours(m.avg_response_time_min ?? 0);
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
                        if (cells[1]) cells[1].textContent = formatHours(m.avg_response_time_min ?? 0);
                        if (cells[2]) cells[2].textContent = '< 0.2 hr';
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
                renderPerformanceMetrics(m);

                // Dispatch metrics
                const dispRes = await fetch('api/reports_dispatch.php' + qs, { cache: 'no-store' });
                const disp = await dispRes.json();
                if (disp.ok) {
                    lastDispatchReport = disp;
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
                    lastFailedDispatchAttempts = Array.isArray(disp.failed_attempts) ? disp.failed_attempts : [];
                    renderDispatchTopUnits(disp.summary_by_service || {});
                    renderFailedDispatchAttempts(lastFailedDispatchAttempts, dm);
                }
            } catch (e) {
                console.error('refreshMetrics failed', e);
            }
        }

        async function loadRecentIncidents(filters = {}, options = {}) {
            try {
                const tbody = document.getElementById('recentIncidentsBody');
                const showLoading = options.showLoading !== false;
                if (tbody && showLoading) tbody.innerHTML = '<tr><td colspan="7" style="color:#6b7280">Loading incidents...</td></tr>';
                const res = await fetch('api/incidents_list.php' + buildQuery(filters), { cache: 'no-store' });
                const data = await res.json();
                const items = data.ok ? (data.items || []) : [];
                lastRecentIncidents = items;
                renderRecentIncidents(items);
            } catch (e) {
                lastRecentIncidents = [];
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
                tbody.innerHTML = `<tr><td colspan="7" style="color:#6b7280">No incidents found</td></tr>`;
                return;
            }
            function unitNameToParam(name){
                return (name||'').toLowerCase().replace(/\s+/g,'-').replace(/#/g,'').replace(/[^a-z0-9\-]/g,'');
            }
            tbody.innerHTML = items.map(i => {
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
                    <td>${i.response_time_min != null ? formatHours(i.response_time_min) : ''}</td>
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

        function formatReportDateTime(value) {
            if (!value) return '';
            const date = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(date.getTime())) {
                return String(value);
            }
            return date.toLocaleString([], {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: 'numeric',
                minute: '2-digit',
            });
        }

        function formatFailureKind(value) {
            const kind = String(value || '').replace(/_/g, ' ');
            return kind ? kind.replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Failed';
        }

        function formatRecoveryStatus(value) {
            const status = String(value || 'open').replace(/_/g, ' ');
            return status ? status.replace(/\b\w/g, (letter) => letter.toUpperCase()) : 'Open';
        }

        function recoveryStatusClass(value) {
            const status = String(value || 'open').toLowerCase();
            if (status === 'recovered') return 'status-resolved';
            if (status === 'closed') return 'status-pending';
            return 'status-critical';
        }

        function renderDispatchRecoveryActions(item) {
            const actions = Array.isArray(item.recovery_actions) ? item.recovery_actions : [];
            const status = item.recovery_status || 'open';
            if (!actions.length) {
                return `<span class="status-badge ${recoveryStatusClass(status)}">${escapeHtml(formatRecoveryStatus(status))}</span>`;
            }

            const labels = {
                retry_same_unit: { label: 'Retry', icon: 'fa-redo', className: '' },
                cancel_dispatch: { label: 'Cancel', icon: 'fa-ban', className: 'cancel' },
                close_failure: { label: 'Close', icon: 'fa-check', className: 'close' }
            };
            const buttons = actions.map((action) => {
                const meta = labels[action] || { label: action, icon: 'fa-tools', className: '' };
                return `
                    <button
                        type="button"
                        class="dispatch-recovery-btn ${escapeHtml(meta.className)}"
                        data-recover-dispatch
                        data-recovery-action="${escapeHtml(action)}"
                        data-attempt-id="${escapeHtml(item.id || '')}"
                        data-failure-kind="${escapeHtml(item.failure_kind || '')}"
                        data-incident-id="${escapeHtml(item.incident_id || '')}"
                        data-unit-id="${escapeHtml(item.unit_id || '')}">
                        <i class="fas ${escapeHtml(meta.icon)}"></i> ${escapeHtml(meta.label)}
                    </button>
                `;
            }).join('');

            return `
                <div class="dispatch-recovery-actions">
                    ${buttons}
                    <span class="status-badge ${recoveryStatusClass(status)}">${escapeHtml(formatRecoveryStatus(status))}</span>
                </div>
            `;
        }

        async function recoverDispatchAttempt(button) {
            const action = button?.dataset?.recoveryAction || '';
            const attemptId = Number(button?.dataset?.attemptId || 0);
            if (!action || !attemptId) return;

            const confirmMessages = {
                retry_same_unit: 'Retry this failed dispatch with the same unit?',
                cancel_dispatch: 'Cancel this stale dispatch assignment and free the unit?',
                close_failure: 'Close this failed dispatch attempt as handled?'
            };
            if (!confirm(confirmMessages[action] || 'Apply this recovery action?')) {
                return;
            }

            button.disabled = true;
            showNotification('Applying dispatch recovery action...', 'info');
            try {
                const res = await fetch('api/dispatch_recovery.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action,
                        attempt_id: attemptId,
                        failure_kind: button.dataset.failureKind || '',
                        incident_id: Number(button.dataset.incidentId || 0),
                        unit_id: Number(button.dataset.unitId || 0)
                    })
                });
                const data = await res.json();
                if (!data.ok) {
                    showNotification(data.error || 'Failed to recover dispatch', 'error');
                    return;
                }
                showNotification(data.message || 'Dispatch recovery applied', 'success');
                await refreshMetrics(currentFilters);
            } catch (e) {
                console.error('recoverDispatchAttempt failed', e);
                showNotification('Failed to recover dispatch', 'error');
            } finally {
                button.disabled = false;
            }
        }

        function renderFailedDispatchAttempts(items, metrics = {}) {
            const tbody = document.getElementById('failedDispatchAttemptsBody');
            const totalEl = document.getElementById('failedDispatchTotal');
            const staleEl = document.getElementById('failedDispatchStale');
            const cancelledEl = document.getElementById('failedDispatchCancelled');
            if (totalEl) totalEl.textContent = String(metrics.failed_attempts_total ?? 0);
            if (staleEl) staleEl.textContent = String(metrics.stale_unacknowledged_dispatches ?? 0);
            if (cancelledEl) cancelledEl.textContent = String(metrics.cancelled_dispatches ?? 0);
            if (!tbody) return;

            const rows = Array.isArray(items) ? items.slice(0, 12) : [];
            if (rows.length === 0) {
                tbody.innerHTML = '<tr><td colspan="8" style="color:#6b7280">No failed dispatch attempts found for the selected filters.</td></tr>';
                return;
            }

            tbody.innerHTML = rows.map((item) => {
                const reference = item.reference_no || (item.incident_id ? `#${item.incident_id}` : 'No incident');
                const unit = item.unit_identifier || (item.unit_id ? `Unit #${item.unit_id}` : 'No unit');
                const priority = String(item.priority || '').toUpperCase();
                const failureKind = formatFailureKind(item.failure_kind);
                return `
                    <tr>
                        <td>${escapeHtml(formatReportDateTime(item.attempted_at || ''))}</td>
                        <td>${escapeHtml(reference)}</td>
                        <td>${escapeHtml(unit)}</td>
                        <td>${escapeHtml(item.incident_type || 'Other')}</td>
                        <td>${escapeHtml(priority || 'N/A')}</td>
                        <td>
                            <span class="status-badge status-critical">${escapeHtml(failureKind)}</span>
                            <div style="margin-top:0.35rem; color:#6b7280; font-size:0.85rem;">${escapeHtml(item.failure_reason || 'No reason recorded')}</div>
                        </td>
                        <td>${escapeHtml(String(item.source || '').replace(/_/g, ' '))}</td>
                        <td>${renderDispatchRecoveryActions(item)}</td>
                    </tr>
                `;
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

        async function refreshPerformanceChart() {
            await refreshCharts(currentFilters);
            showNotification('Performance chart refreshed', 'success');
        }

        async function refreshResourcesChart() {
            await refreshCharts(currentFilters);
            showNotification('Resources chart refreshed', 'success');
        }

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            currentFilters = getFilters();
            const reportTypeSelect = document.getElementById('report-type');
            if (reportTypeSelect) {
                reportTypeSelect.addEventListener('change', applyReportView);
            }
            applyReportView();
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
            const failedDispatchBody = document.getElementById('failedDispatchAttemptsBody');
            if (failedDispatchBody) {
                failedDispatchBody.addEventListener('click', (event) => {
                    const button = event.target.closest('[data-recover-dispatch]');
                    if (button) recoverDispatchAttempt(button);
                });
            }
            setInterval(function () {
                if (document.hidden) return;
                try {
                    refreshMetrics(currentFilters);
                    refreshCharts(currentFilters);
                    loadRecentIncidents(currentFilters, { showLoading: false });
                } catch (e) {
                    console.error('report auto-refresh failed', e);
                }
            }, 60000);
        });

        document.addEventListener('themeChanged', function() {
            refreshCharts(currentFilters);
        });
    </script>
</body>
</html>
