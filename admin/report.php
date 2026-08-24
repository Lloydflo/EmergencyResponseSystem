<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('admin', 'admin/report.php');
require_once $rootDir . '/includes/db.php';
require_once $rootDir . '/includes/report_analytics.php';

date_default_timezone_set(ERS_REPORT_TIMEZONE);
if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

$pageTitle = 'Analytics & Reporting';
$reportUiBuild = '20260806-accurate-analytics-v2';
$reportCssVersion = rawurlencode($reportUiBuild . '-' . (is_file($rootDir . '/css/report.css') ? (string)filemtime($rootDir . '/css/report.css') : '0'));
$reportJsVersion = rawurlencode($reportUiBuild . '-' . (is_file($rootDir . '/js/report-analytics.js') ? (string)filemtime($rootDir . '/js/report-analytics.js') : '0'));

$defaultScope = ers_report_scope(['period' => 'month']);
$initialReport = [
    'meta' => [
        'period_label' => $defaultScope['period_label'],
        'start_date' => $defaultScope['start_date'],
        'end_date' => $defaultScope['end_date'],
        'timezone' => ERS_REPORT_TIMEZONE,
    ],
    'metrics' => [
        'avg_response_time_min' => null,
        'avg_response_sample_count' => 0,
        'previous_avg_response_time_min' => null,
        'total_incidents' => 0,
        'previous_total_incidents' => 0,
        'resource_utilization' => null,
        'resolution_rate' => null,
        'previous_resolution_rate' => null,
        'resolved_incidents' => 0,
        'active_responder_accounts' => null,
    ],
];
$initialLoadError = '';
try {
    $pdo = get_db_connection();
    if (!$pdo) {
        throw new RuntimeException('Database connection unavailable.');
    }
    $initialReport = ers_report_fetch_metrics($pdo, $defaultScope);
} catch (Throwable $e) {
    $initialLoadError = 'Initial analytics will load from the reporting APIs.';
    error_log('admin/report.php initial metrics failed: ' . $e->getMessage());
}

$initialMetrics = $initialReport['metrics'];
$initialMeta = $initialReport['meta'];
$defaultPeriod = (string)$defaultScope['period'];
$defaultStartDate = (string)$defaultScope['start_date'];
$defaultEndDate = (string)$defaultScope['end_date'];
$reportConfig = [
    'build' => $reportUiBuild,
    'timezone' => ERS_REPORT_TIMEZONE,
    'responseSlaMinutes' => ERS_REPORT_RESPONSE_SLA_MINUTES,
    'targets' => [
        'arrivalCompliancePercent' => ERS_REPORT_ARRIVAL_COMPLIANCE_TARGET_PERCENT,
        'resolutionPercent' => ERS_REPORT_RESOLUTION_TARGET_PERCENT,
        'acknowledgementPercent' => ERS_REPORT_ACKNOWLEDGEMENT_TARGET_PERCENT,
        'utilizationMinPercent' => ERS_REPORT_UTILIZATION_TARGET_MIN_PERCENT,
        'utilizationMaxPercent' => ERS_REPORT_UTILIZATION_TARGET_MAX_PERCENT,
    ],
    'defaultFilters' => [
        'period' => $defaultPeriod,
        'start' => $defaultStartDate,
        'end' => $defaultEndDate,
    ],
    'initialMeta' => $initialMeta,
];

function report_page_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function report_page_metric($value, string $suffix = '', int $decimals = 1): string
{
    return $value === null ? '—' : number_format((float)$value, $decimals) . $suffix;
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
    <link rel="stylesheet" href="css/report.css?v=<?php echo report_page_h($reportCssVersion); ?>">
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

            <section class="report-page-heading" aria-labelledby="reportPageTitle">
                <div>
                    <span class="report-eyebrow"><i class="fas fa-chart-line" aria-hidden="true"></i> Verified operational reporting</span>
                    <h1 id="reportPageTitle">Report Analytics</h1>
                    <p>Metrics use one shared set of date, incident, dispatch, response-time, and utilization definitions.</p>
                </div>
                <div class="report-range-status" aria-live="polite">
                    <strong id="reportRangeLabel"><?php echo report_page_h((string)$initialMeta['period_label']); ?></strong>
                    <span id="reportRangeDates"><?php echo report_page_h((string)$initialMeta['start_date'] . ' to ' . (string)$initialMeta['end_date']); ?></span>
                    <span><i class="far fa-clock" aria-hidden="true"></i> Asia/Manila</span>
                </div>
            </section>
            <?php if ($initialLoadError !== ''): ?><div class="report-inline-warning"><?php echo report_page_h($initialLoadError); ?></div><?php endif; ?>

            <!-- Key Metrics Overview -->
            <div class="analytics-grid">
                <article class="analytics-card response-time">
                    <div class="metric-label">Average Dispatch-to-Scene</div>
                    <div class="metric-display">
                        <div class="metric-value" id="metricAvgResponse"><?php echo report_page_metric($initialMetrics['avg_response_time_min'] ?? null, ' min'); ?></div>
                        <div class="metric-change neutral" id="metricResponseTrend"><i class="fas fa-minus" aria-hidden="true"></i><span>No baseline</span></div>
                    </div>
                    <div class="metric-note"><span id="metricResponseSamples"><?php echo number_format((int)($initialMetrics['avg_response_sample_count'] ?? 0)); ?></span> valid on-scene sample(s) · target ≤ <?php echo ERS_REPORT_RESPONSE_SLA_MINUTES; ?> min</div>
                </article>

                <article class="analytics-card incidents">
                    <div class="metric-label">Incidents Created</div>
                    <div class="metric-display">
                        <div class="metric-value" id="metricIncidentsMonth"><?php echo number_format((int)($initialMetrics['total_incidents'] ?? 0)); ?></div>
                        <div class="metric-change neutral" id="metricIncidentsTrend"><i class="fas fa-minus" aria-hidden="true"></i><span id="metricIncidentsDelta">0</span></div>
                    </div>
                    <div class="metric-note">Previous equal-duration period: <span id="metricLastMonth"><?php echo number_format((int)($initialMetrics['previous_total_incidents'] ?? 0)); ?></span></div>
                </article>

                <article class="analytics-card resources">
                    <div class="metric-label">Current Unit Utilization</div>
                    <div class="metric-display">
                        <div class="metric-value" id="metricUtilization"><?php echo report_page_metric($initialMetrics['resource_utilization'] ?? null, '%'); ?></div>
                        <div class="metric-change neutral"><i class="fas fa-satellite-dish" aria-hidden="true"></i><span>Live</span></div>
                    </div>
                    <div class="metric-note">In-use units ÷ currently operational units; not historical</div>
                </article>

                <article class="analytics-card performance">
                    <div class="metric-label">Incident Resolution Rate</div>
                    <div class="metric-display">
                        <div class="metric-value" id="metricSuccess"><?php echo report_page_metric($initialMetrics['resolution_rate'] ?? null, '%'); ?></div>
                        <div class="metric-change neutral" id="metricResolutionTrend"><i class="fas fa-minus" aria-hidden="true"></i><span>No baseline</span></div>
                    </div>
                    <div class="metric-note"><span id="metricResolvedCount"><?php echo number_format((int)($initialMetrics['resolved_incidents'] ?? 0)); ?></span> resolved from the selected incident cohort</div>
                </article>
            </div>

            <div class="report-definition-strip">
                <span><strong>Incident volume:</strong> created in range</span>
                <span><strong>Response:</strong> assigned → on scene</span>
                <span><strong>Comparison:</strong> previous equal-duration range</span>
                <span><strong>Unit status:</strong> live snapshot</span>
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
                            <option value="dispatch">Dispatch Failure Signals</option>
                            <option value="trend">Trend Monitoring</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="time-period">Time Period</label>
                        <select id="time-period">
                            <option value="today" <?php echo $defaultPeriod === 'today' ? 'selected' : ''; ?>>Today</option>
                            <option value="week" <?php echo $defaultPeriod === 'week' ? 'selected' : ''; ?>>Week to Date</option>
                            <option value="month" <?php echo $defaultPeriod === 'month' ? 'selected' : ''; ?>>Month to Date</option>
                            <option value="quarter" <?php echo $defaultPeriod === 'quarter' ? 'selected' : ''; ?>>Quarter to Date</option>
                            <option value="year" <?php echo $defaultPeriod === 'year' ? 'selected' : ''; ?>>Year to Date</option>
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
                            <option value="critical">Critical</option>
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
                            <input type="date" id="start-date" value="<?php echo report_page_h($defaultStartDate); ?>" disabled>
                        </div>
                        <div class="filter-group">
                            <label for="end-date">End Date</label>
                            <input type="date" id="end-date" value="<?php echo report_page_h($defaultEndDate); ?>" disabled>
                        </div>
                        <button class="btn-report primary" type="button" id="applyReportFilters" onclick="applyFilters()">
                            <i class="fas fa-search"></i> Apply Filters
                        </button>
                        <button class="btn-report" type="button" id="clearReportFilters" onclick="clearFilters()">
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
                        <div class="ai-loading"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Loading insights from the verified report dataset…</div>
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
                <div class="report-card">
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

                <div class="report-card">
                    <div class="report-icon performance">
                        <i class="fas fa-trophy"></i>
                    </div>
                    <div class="report-title">Verified Performance Indicators</div>
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

                <div class="report-card">
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

                <div class="report-card">
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
                    <h3 class="chart-title">Dispatch-to-Scene Trend (Minutes)</h3>
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
                    <h3 class="chart-title">Incidents Created by Type</h3>
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
                    <h3 class="chart-title">Incidents Created by Priority</h3>
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
                    <h3 class="chart-title">Dispatches Assigned by Unit Type</h3>
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

            <!-- Current Unit Status Snapshot Chart -->
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
                    <h3 class="table-title">Top Dispatched Units</h3><div class="table-subtitle">Actual units ranked by assignments in the selected date range.</div>
                    <button class="btn-report" type="button" onclick="showAllDispatchUnitsModal()">
                        <i class="fas fa-list"></i> View All
                    </button>
                </div>
                <div class="table-container">
                    <table class="analytics-table">
                        <thead>
                            <tr>
                                <th>Unit</th>
                                <th>Unit Type</th>
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
                        <h3 class="table-title">Dispatch Failure Signals</h3>
                        <div style="color:#6b7280; font-size:0.85rem; margin-top:0.25rem;">
                            Recorded validation failures, cancelled dispatches, and unacknowledged assignments beyond the response threshold.
                        </div>
                    </div>
                    <div style="display:flex; gap:0.5rem; flex-wrap:wrap;">
                        <span class="status-badge status-critical">Signals: <span id="failedDispatchTotal">0</span></span>
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
                <div class="table-container recent-incidents-scroll">
                    <table class="analytics-table scrollable">
                        <thead>
                            <tr>
                                <th>Incident ID</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Priority</th>
                                <th>Dispatch-to-Scene (Minutes)</th>
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
                        <tbody id="performanceMetricsBody">
                            <tr><td>Average Dispatch-to-Scene</td><td>—</td><td>≤ 10 min</td><td><div class="trend-indicator trend-neutral"><i class="fas fa-minus"></i> No data</div></td><td><span class="status-badge status-pending">Unavailable</span></td></tr>
                            <tr><td>Arrival SLA Compliance</td><td>—</td><td>≥ <?php echo number_format(ERS_REPORT_ARRIVAL_COMPLIANCE_TARGET_PERCENT, 0); ?>%</td><td><div class="trend-indicator trend-neutral"><i class="fas fa-minus"></i> No data</div></td><td><span class="status-badge status-pending">Unavailable</span></td></tr>
                            <tr><td>Incident Resolution Rate</td><td>—</td><td>≥ <?php echo number_format(ERS_REPORT_RESOLUTION_TARGET_PERCENT, 0); ?>%</td><td><div class="trend-indicator trend-neutral"><i class="fas fa-minus"></i> No data</div></td><td><span class="status-badge status-pending">Unavailable</span></td></tr>
                            <tr><td>Dispatch Acknowledgement Rate</td><td>—</td><td>≥ <?php echo number_format(ERS_REPORT_ACKNOWLEDGEMENT_TARGET_PERCENT, 0); ?>%</td><td><div class="trend-indicator trend-neutral"><i class="fas fa-minus"></i> No data</div></td><td><span class="status-badge status-pending">Unavailable</span></td></tr>
                            <tr><td>Current Unit Utilization</td><td>—</td><td><?php echo number_format(ERS_REPORT_UTILIZATION_TARGET_MIN_PERCENT, 0); ?>–<?php echo number_format(ERS_REPORT_UTILIZATION_TARGET_MAX_PERCENT, 0); ?>%</td><td><div class="trend-indicator trend-neutral"><i class="fas fa-satellite-dish"></i> Live snapshot</div></td><td><span class="status-badge status-pending">Unavailable</span></td></tr>
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

    <script id="reportAnalyticsConfig" type="application/json"><?php echo json_encode($reportConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES); ?></script>
    <script src="js/report-analytics.js?v=<?php echo report_page_h($reportJsVersion); ?>" defer></script>
</body>
</html>
