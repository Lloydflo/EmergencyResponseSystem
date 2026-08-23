<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('admin', 'admin/index.php');

date_default_timezone_set('Asia/Manila');

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$pageTitle = 'Admin Operations Dashboard';
$dashboardBuild = '20260807-admin-dashboard-accurate-v2';
$dashboardCssModified = is_file($rootDir . '/css/dashboard.css')
    ? (string)filemtime($rootDir . '/css/dashboard.css')
    : '0';
$dashboardJsModified = is_file($rootDir . '/js/admin-dashboard.js')
    ? (string)filemtime($rootDir . '/js/admin-dashboard.js')
    : '0';
$dashboardCssVersion = rawurlencode($dashboardBuild . '-' . $dashboardCssModified);
$dashboardJsVersion = rawurlencode($dashboardBuild . '-' . $dashboardJsModified);

$user = get_logged_in_user() ?? [];
$adminName = trim((string)($user['name'] ?? $_SESSION['user_name'] ?? 'Admin')) ?: 'Admin';
$dashboardRoleLabel = ucfirst(canonical_role((string)($user['role'] ?? $_SESSION['user_role'] ?? 'admin')));

function admin_dashboard_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo admin_dashboard_h($pageTitle); ?></title>
    <?php include $rootDir . '/includes/theme-init.php'; ?>
    <base href="../">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="css/dashboard.css?v=<?php echo admin_dashboard_h($dashboardCssVersion); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <script>
        window.ADMIN_DASHBOARD_CONFIG = <?php echo json_encode([
            'build' => $dashboardBuild,
            'timezone' => 'Asia/Manila',
            'adminName' => $adminName,
            'summaryEndpoint' => 'api/admin_dashboard_summary.php',
            'weatherEndpoint' => 'api/admin_dashboard_weather.php',
            'activityEndpoint' => 'api/activity_feed.php',
            'alertsEndpoint' => 'api/alerts_active.php',
            'trendEndpoint' => 'api/trend_data.php',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="js/admin-dashboard.js?v=<?php echo admin_dashboard_h($dashboardJsVersion); ?>" defer></script>
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <main class="main-content dashboard-main">
        <div class="main-container dashboard-shell">
            <header class="dashboard-header">
                <div class="dashboard-header-copy">
                    <span class="dashboard-kicker"><i class="fas fa-shield-halved" aria-hidden="true"></i> Operations console</span>
                    <h1 class="dashboard-title">Admin Operations Dashboard</h1>
                    <p class="dashboard-subtitle">Monitor the current incident queue, month-to-date activity, resource readiness, and operational alerts.</p>
                </div>
                <div class="dashboard-meta">
                    <span class="dashboard-chip"><i class="fas fa-user-shield" aria-hidden="true"></i><?php echo admin_dashboard_h($dashboardRoleLabel); ?></span>
                    <span class="dashboard-chip"><i class="far fa-clock" aria-hidden="true"></i><span id="dashboardLiveClock">Loading time…</span></span>
                    <button id="dashboardRefreshButton" class="dashboard-refresh-btn" type="button">
                        <i class="fas fa-rotate" aria-hidden="true"></i><span>Refresh data</span>
                    </button>
                </div>
            </header>

            <section class="dashboard-section" aria-labelledby="dashboardMetricsTitle">
                <div class="dashboard-section-heading dashboard-section-heading-compact">
                    <div>
                        <span class="dashboard-section-kicker">Live operational snapshot</span>
                        <h2 id="dashboardMetricsTitle">Current status</h2>
                    </div>
                    <span id="dashboardGeneratedAt" class="dashboard-data-time">Waiting for current data…</span>
                </div>

                <div class="metrics-grid" aria-live="polite">
                    <article class="metric-card critical">
                        <div class="metric-card-top">
                            <div>
                                <span class="metric-title">Open incidents</span>
                                <strong class="metric-value" id="metricOpenIncidents">—</strong>
                            </div>
                            <span class="metric-icon fire"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i></span>
                        </div>
                        <p class="metric-context">Active cases still requiring operational attention.</p>
                        <a class="btn-metric" href="admin/review.php"><i class="fas fa-clipboard-list" aria-hidden="true"></i>Review queue</a>
                    </article>

                    <article class="metric-card success">
                        <div class="metric-card-top">
                            <div>
                                <span class="metric-title">Active accounts</span>
                                <strong class="metric-value" id="metricActiveAccounts">—</strong>
                            </div>
                            <span class="metric-icon users"><i class="fas fa-users" aria-hidden="true"></i></span>
                        </div>
                        <p class="metric-context">Enabled admin, dispatcher, and responder accounts.</p>
                        <a class="btn-metric" href="admin/user_management.php"><i class="fas fa-user-gear" aria-hidden="true"></i>Manage accounts</a>
                    </article>

                    <article class="metric-card warning">
                        <div class="metric-card-top">
                            <div>
                                <span class="metric-title">Partner agencies</span>
                                <strong class="metric-value" id="metricPartnerAgencies">—</strong>
                            </div>
                            <span class="metric-icon partner"><i class="fas fa-handshake" aria-hidden="true"></i></span>
                        </div>
                        <p class="metric-context">Active agency records available for coordination.</p>
                        <a class="btn-metric" href="admin/interagency.php"><i class="fas fa-handshake-angle" aria-hidden="true"></i>Open desk</a>
                    </article>

                    <article class="metric-card info">
                        <div class="metric-card-top">
                            <div>
                                <span class="metric-title">Registered units</span>
                                <strong class="metric-value" id="metricRegisteredUnits">—</strong>
                            </div>
                            <span class="metric-icon units"><i class="fas fa-truck-medical" aria-hidden="true"></i></span>
                        </div>
                        <p class="metric-context">Operational unit or resource records in the system.</p>
                        <a class="btn-metric" href="admin/resources.php"><i class="fas fa-warehouse" aria-hidden="true"></i>View resources</a>
                    </article>

                    <article class="metric-card primary">
                        <div class="metric-card-top">
                            <div>
                                <span class="metric-title">Incidents this month</span>
                                <strong class="metric-value" id="metricMonthlyIncidents">—</strong>
                            </div>
                            <span class="metric-icon chart"><i class="fas fa-chart-line" aria-hidden="true"></i></span>
                        </div>
                        <p class="metric-context" id="metricMonthScope">Created during the current month.</p>
                        <div class="metric-actions">
                            <a class="btn-metric" href="admin/report.php"><i class="fas fa-file-lines" aria-hidden="true"></i>Reports</a>
                            <button class="btn-metric" type="button" data-dashboard-action="open-trends"><i class="fas fa-chart-line" aria-hidden="true"></i>Trends</button>
                        </div>
                    </article>
                </div>
            </section>

            <section class="dashboard-grid dashboard-analytics-grid" aria-label="Month-to-date analytics and operational tools">
                <div class="main-panel">
                    <article class="dashboard-card chart-container">
                        <header class="dashboard-card-head">
                            <div>
                                <span class="dashboard-section-kicker">Month to date</span>
                                <h2>Incidents by type</h2>
                                <p id="incidentTypeScope">Counts use the same period as the monthly incident metric.</p>
                            </div>
                        </header>
                        <div class="chart-stage">
                            <canvas id="incidentsTypeBar" class="chart-canvas" aria-label="Month-to-date incidents by type"></canvas>
                            <div class="chart-state" id="incidentsTypeState">Loading chart…</div>
                        </div>
                    </article>

                    <article class="dashboard-card chart-container">
                        <header class="dashboard-card-head">
                            <div>
                                <span class="dashboard-section-kicker">Month to date</span>
                                <h2>Incident priority distribution</h2>
                                <p id="incidentPriorityScope">Critical priority is tracked separately and is never grouped under low priority.</p>
                            </div>
                        </header>
                        <div class="chart-stage">
                            <canvas id="incidentsPriorityPie" class="chart-canvas" aria-label="Month-to-date incident priorities"></canvas>
                            <div class="chart-state" id="incidentsPriorityState">Loading chart…</div>
                        </div>
                    </article>
                </div>

                <aside class="side-panel">
                    <section class="dashboard-card quick-actions" aria-labelledby="quickActionsTitle">
                        <header class="dashboard-card-head">
                            <div>
                                <span class="dashboard-section-kicker">Navigation</span>
                                <h2 id="quickActionsTitle">Quick actions</h2>
                            </div>
                        </header>
                        <div class="action-grid">
                            <a class="action-btn" href="admin/user_management.php"><i class="fas fa-users-gear" aria-hidden="true"></i><span>User management</span></a>
                            <a class="action-btn" href="admin/system_settings.php"><i class="fas fa-sliders" aria-hidden="true"></i><span>System settings</span></a>
                            <a class="action-btn" href="admin/resources.php"><i class="fas fa-boxes-stacked" aria-hidden="true"></i><span>Resource oversight</span></a>
                            <a class="action-btn" href="admin/report.php"><i class="fas fa-chart-column" aria-hidden="true"></i><span>Reports center</span></a>
                        </div>
                    </section>

                    <section id="weatherWidget" class="weather-widget weather-loading" aria-labelledby="weatherLocation" aria-live="polite">
                        <div class="weather-widget-glow" aria-hidden="true"></div>
                        <header class="weather-header">
                            <div class="weather-copy">
                                <span class="weather-eyebrow"><i class="fas fa-location-dot" aria-hidden="true"></i> Exact-coordinate weather</span>
                                <h2 id="weatherLocation" class="weather-location">Quezon City Command Center</h2>
                                <p id="weatherCondition" class="weather-condition">Loading live conditions…</p>
                            </div>
                            <span class="weather-icon-shell" aria-hidden="true"><i id="weatherIcon" class="fa-solid fa-cloud"></i></span>
                        </header>

                        <div class="weather-body">
                            <div class="weather-temp-block">
                                <strong id="weatherTemperature" class="weather-temp">—°C</strong>
                                <p id="weatherHeadline" class="weather-headline">Requesting the latest observation…</p>
                                <p id="weatherUpdated" class="weather-meta-line">Observation time unavailable</p>
                            </div>
                            <span id="weatherStatus" class="weather-status-chip"><span class="weather-status-dot"></span>Connecting</span>
                        </div>

                        <div class="weather-stats">
                            <div class="weather-stat"><span class="weather-stat-label">Feels like</span><strong id="weatherFeelsLike" class="weather-stat-value">—</strong></div>
                            <div class="weather-stat"><span class="weather-stat-label">Rain chance</span><strong id="weatherRainChance" class="weather-stat-value">—</strong></div>
                            <div class="weather-stat"><span class="weather-stat-label">Today range</span><strong id="weatherRange" class="weather-stat-value">—</strong></div>
                        </div>

                        <div class="weather-detail-strip">
                            <span class="weather-detail-pill"><span class="weather-detail-label">Humidity</span><strong id="weatherHumidity">—</strong></span>
                            <span class="weather-detail-pill"><span class="weather-detail-label">Wind</span><strong id="weatherWind">—</strong></span>
                            <span class="weather-detail-pill"><span class="weather-detail-label">Visibility</span><strong id="weatherVisibility">—</strong></span>
                        </div>
                        <footer class="weather-footer">
                            <span id="weatherSource">Source: Open-Meteo</span>
                            <button id="weatherRefreshButton" type="button"><i class="fas fa-rotate" aria-hidden="true"></i>Refresh weather</button>
                        </footer>
                    </section>
                </aside>
            </section>

            <section class="dashboard-grid dashboard-feed-grid" aria-label="Recent activity and active alerts">
                <article class="dashboard-card activity-feed">
                    <header class="dashboard-card-head">
                        <div>
                            <span class="dashboard-section-kicker">Accountability</span>
                            <h2>Recent activity</h2>
                            <p>Latest authentication events recorded by the audit trail.</p>
                        </div>
                        <button class="btn-metric" type="button" data-dashboard-action="view-all-activity"><i class="fas fa-up-right-from-square" aria-hidden="true"></i>View all</button>
                    </header>
                    <div id="activityFeedList" class="scrollable-feed dashboard-feed-list" aria-live="polite">
                        <div class="dashboard-empty-state">Loading recent activity…</div>
                    </div>
                </article>

                <article class="dashboard-card alerts-panel">
                    <header class="dashboard-card-head">
                        <div>
                            <span class="dashboard-section-kicker">Attention required</span>
                            <h2>Active alerts</h2>
                            <p>Operational, resource, response-time, and weather advisories.</p>
                        </div>
                        <button class="btn-metric" type="button" data-dashboard-action="view-all-alerts"><i class="fas fa-up-right-from-square" aria-hidden="true"></i>View all</button>
                    </header>
                    <div id="alertsPanelList" class="dashboard-feed-list" aria-live="polite">
                        <div class="dashboard-empty-state">Loading active alerts…</div>
                    </div>
                </article>
            </section>
        </div>
    </main>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <div id="activityModal" class="dashboard-modal" hidden aria-hidden="true">
        <div class="dashboard-modal-card" role="dialog" aria-modal="true" aria-labelledby="activityModalTitle">
            <header class="dashboard-modal-head">
                <div><span class="dashboard-section-kicker">Audit activity</span><h2 id="activityModalTitle">All recent activity</h2></div>
                <button class="dashboard-modal-close" type="button" data-modal-close="activityModal" aria-label="Close activity dialog"><i class="fas fa-xmark" aria-hidden="true"></i></button>
            </header>
            <div id="activityModalList" class="dashboard-modal-list"><div class="dashboard-empty-state">Loading…</div></div>
        </div>
    </div>

    <div id="alertsModal" class="dashboard-modal" hidden aria-hidden="true">
        <div class="dashboard-modal-card" role="dialog" aria-modal="true" aria-labelledby="alertsModalTitle">
            <header class="dashboard-modal-head">
                <div><span class="dashboard-section-kicker">Operational advisories</span><h2 id="alertsModalTitle">All active alerts</h2></div>
                <button class="dashboard-modal-close" type="button" data-modal-close="alertsModal" aria-label="Close alerts dialog"><i class="fas fa-xmark" aria-hidden="true"></i></button>
            </header>
            <div id="alertsModalList" class="dashboard-modal-list"><div class="dashboard-empty-state">Loading…</div></div>
        </div>
    </div>

    <div id="trendModal" class="dashboard-modal" hidden aria-hidden="true">
        <div class="dashboard-modal-card dashboard-trend-modal" role="dialog" aria-modal="true" aria-labelledby="trendModalTitle">
            <header class="dashboard-modal-head">
                <div><span class="dashboard-section-kicker">Historical view</span><h2 id="trendModalTitle">Daily incident trend</h2></div>
                <button class="dashboard-modal-close" type="button" data-modal-close="trendModal" aria-label="Close trend dialog"><i class="fas fa-xmark" aria-hidden="true"></i></button>
            </header>
            <form id="trendFilterForm" class="trend-filter-form">
                <label>From<input type="date" id="trendStart" required></label>
                <label>To<input type="date" id="trendEnd" required></label>
                <button class="dashboard-refresh-btn" type="submit"><i class="fas fa-filter" aria-hidden="true"></i>Apply</button>
            </form>
            <div class="trend-chart-stage">
                <canvas id="trendChart" aria-label="Daily incident trend"></canvas>
                <div id="trendState" class="chart-state">Select a valid range.</div>
            </div>
        </div>
    </div>
</body>
</html>
