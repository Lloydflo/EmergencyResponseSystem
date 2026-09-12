<?php
declare(strict_types=1);

$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('admin', 'admin/analytics.php');
require_once $rootDir . '/includes/db.php';
require_once $rootDir . '/includes/predictive_analytics_helper.php';

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$pageTitle = 'Predictive Analytics';
$analyticsBuild = '20260817-predictive-operations-v1';
$analyticsCssVersion = rawurlencode($analyticsBuild . '-' . (is_file($rootDir . '/css/analytics.css') ? (string)filemtime($rootDir . '/css/analytics.css') : '0'));
$initialError = '';
$snapshot = ers_predictive_default_snapshot();

try {
    $pdo = get_db_connection();
    if (!$pdo) {
        throw new RuntimeException('Database connection unavailable.');
    }
    $snapshot = ers_predictive_build_snapshot($pdo);
} catch (Throwable $e) {
    $initialError = 'Predictive snapshot is using fallback values until the database is available.';
    error_log('admin/analytics.php initial snapshot failed: ' . $e->getMessage());
}

function pa_h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function pa_json($value): string
{
    return json_encode(
        $value,
        JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE
        | JSON_HEX_TAG
        | JSON_HEX_AMP
        | JSON_HEX_APOS
        | JSON_HEX_QUOT
        | JSON_INVALID_UTF8_SUBSTITUTE
    ) ?: 'null';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo pa_h($pageTitle); ?></title>
    <?php include $rootDir . '/includes/theme-init.php'; ?>
    <base href="../">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="css/analytics.css?v=<?php echo pa_h($analyticsCssVersion); ?>">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
</head>
<body class="admin-predictive-page" data-analytics-build="<?php echo pa_h($analyticsBuild); ?>">
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <main class="main-content predictive-analytics-page">
        <div class="main-container predictive-shell">
            <section class="pa-hero" aria-labelledby="predictiveTitle">
                <div>
                    <span class="pa-eyebrow">Predictive operations</span>
                    <h1 id="predictiveTitle">Predictive Analytics</h1>
                    <p>Forecast incident pressure, resource strain, hotspot risk, and readiness actions from current system activity.</p>
                </div>
                <div class="pa-hero-meta">
                    <span class="pa-pill online"><i class="fas fa-signal" aria-hidden="true"></i> Live Snapshot</span>
                    <span class="pa-pill"><i class="fas fa-clock" aria-hidden="true"></i> <span id="paGeneratedAt">--</span></span>
                    <button type="button" class="pa-btn secondary" id="paRefreshBtn"><i class="fas fa-rotate" aria-hidden="true"></i> Refresh</button>
                </div>
            </section>

            <?php if ($initialError !== ''): ?>
                <div class="pa-ai-error" style="margin-top:1rem;"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i><?php echo pa_h($initialError); ?></div>
            <?php endif; ?>

            <section class="pa-kpi-grid" aria-label="Predictive summary">
                <article class="pa-kpi-card">
                    <div class="pa-kpi-head"><p class="pa-kpi-label">Next 7 Days</p><span class="pa-kpi-icon"><i class="fas fa-calendar-week"></i></span></div>
                    <p class="pa-kpi-value" id="paNext7Total">0</p>
                    <div class="pa-kpi-foot"><span>Forecast incidents</span><strong class="pa-delta" id="paTrendLabel">Stable</strong></div>
                </article>
                <article class="pa-kpi-card">
                    <div class="pa-kpi-head"><p class="pa-kpi-label">Resource Strain</p><span class="pa-kpi-icon"><i class="fas fa-gauge-high"></i></span></div>
                    <p class="pa-kpi-value"><span id="paStrainIndex">0</span>%</p>
                    <div class="pa-kpi-foot"><span>Load index</span><strong id="paStandbyRatio">0% standby</strong></div>
                </article>
                <article class="pa-kpi-card">
                    <div class="pa-kpi-head"><p class="pa-kpi-label">Active Incidents</p><span class="pa-kpi-icon"><i class="fas fa-triangle-exclamation"></i></span></div>
                    <p class="pa-kpi-value" id="paActiveIncidents">0</p>
                    <div class="pa-kpi-foot"><span>Current load</span><strong id="paCarryover">0 carryover</strong></div>
                </article>
                <article class="pa-kpi-card">
                    <div class="pa-kpi-head"><p class="pa-kpi-label">Peak Window</p><span class="pa-kpi-icon"><i class="fas fa-business-time"></i></span></div>
                    <p class="pa-kpi-value" style="font-size:1.25rem;" id="paPeakWindow">Unavailable</p>
                    <div class="pa-kpi-foot"><span>Historical pressure</span><strong id="paPeakCount">0 hits</strong></div>
                </article>
            </section>

            <section class="pa-grid" aria-label="Forecast charts and recommendations">
                <article class="pa-panel">
                    <div class="pa-panel-head">
                        <div>
                            <p class="pa-panel-kicker">Incident forecast</p>
                            <h2>Actual Volume and 7-Day Prediction</h2>
                            <p class="pa-panel-copy">Daily incident history with the computed forecast continuation.</p>
                        </div>
                        <div class="pa-chip-row">
                            <span class="pa-chip"><i class="fas fa-circle" style="color:#0f766e"></i> Actual</span>
                            <span class="pa-chip"><i class="fas fa-circle" style="color:#f59e0b"></i> Forecast</span>
                        </div>
                    </div>
                    <div class="pa-chart-wrap"><canvas id="paTimelineChart"></canvas></div>
                </article>

                <article class="pa-panel pa-ai-card">
                    <div class="pa-panel-head">
                        <div>
                            <p class="pa-panel-kicker">Action brief</p>
                            <h2>Recommended Actions</h2>
                            <p class="pa-panel-copy">Operational recommendations generated from incident trend and resource state.</p>
                        </div>
                    </div>
                    <div class="pa-ai-content">
                        <div class="pa-rec-list" id="paRecommendationList"></div>
                    </div>
                    <div class="pa-ai-meta" id="paAiMeta">Decision support only. Dispatch approval remains with operations staff.</div>
                </article>
            </section>

            <section class="pa-grid pa-grid-secondary" aria-label="Risk distribution">
                <article class="pa-panel">
                    <div class="pa-panel-head">
                        <div>
                            <p class="pa-panel-kicker">Incident type mix</p>
                            <h2>Predicted Demand by Type</h2>
                        </div>
                    </div>
                    <div class="pa-type-list" id="paTypeList"></div>
                </article>

                <article class="pa-panel">
                    <div class="pa-panel-head">
                        <div>
                            <p class="pa-panel-kicker">Priority mix</p>
                            <h2>Recent Priority Distribution</h2>
                        </div>
                    </div>
                    <div class="pa-chart-wrap compact"><canvas id="paPriorityChart"></canvas></div>
                </article>
            </section>

            <section class="pa-grid" aria-label="System process">
                <article class="pa-panel">
                    <div class="pa-panel-head">
                        <div>
                            <p class="pa-panel-kicker">System process</p>
                            <h2>Prediction Workflow</h2>
                        </div>
                    </div>
                    <div class="pa-note-grid">
                        <div class="pa-note-card"><strong>Collect</strong><span>Incidents, dispatches, responders, units</span></div>
                        <div class="pa-note-card"><strong>Analyze</strong><span>Trend, type mix, peak hour</span></div>
                        <div class="pa-note-card"><strong>Predict</strong><span>7-day volume and resource pressure</span></div>
                        <div class="pa-note-card"><strong>Recommend</strong><span>Standby units and escalation focus</span></div>
                    </div>
                    <div class="pa-ready-meter">
                        <div class="pa-chip-row">
                            <span class="pa-chip">Available units: <strong id="paAvailableUnits">0</strong></span>
                            <span class="pa-chip">Busy units: <strong id="paBusyUnits">0</strong></span>
                            <span class="pa-chip">Total units: <strong id="paTotalUnits">0</strong></span>
                        </div>
                        <div class="pa-meter-track" aria-hidden="true"><div class="pa-meter-fill" id="paStrainMeter" style="width:0%"></div></div>
                    </div>
                </article>
            </section>
        </div>
    </main>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <script>
        const predictiveInitialSnapshot = <?php echo pa_json($snapshot); ?>;
        const predictiveEndpoint = 'api/predictive_analytics.php';
        const predictiveCharts = {};

        function paNumber(value, fallback) {
            const number = Number(value);
            return Number.isFinite(number) ? number : (fallback || 0);
        }

        function paText(value, fallback) {
            const text = String(value === undefined || value === null ? '' : value).trim();
            return text || fallback || '';
        }

        function paEscape(value) {
            return String(value === undefined || value === null ? '' : value).replace(/[&<>"']/g, (char) => ({
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;',
                "'": '&#39;'
            }[char] || char));
        }

        function paSet(id, value) {
            const el = document.getElementById(id);
            if (el) el.textContent = String(value);
        }

        function paRiskClass(value) {
            const risk = paText(value, 'Low').toLowerCase();
            if (risk === 'high') return 'high';
            if (risk === 'medium') return 'medium';
            return 'low';
        }

        function paTrendClass(label) {
            const value = paText(label, 'Stable').toLowerCase();
            if (value === 'rising') return 'up';
            if (value === 'cooling') return 'down';
            return 'steady';
        }

        function paRenderRecommendations(items) {
            const list = document.getElementById('paRecommendationList');
            if (!list) return;
            const recommendations = Array.isArray(items) ? items : [];
            if (!recommendations.length) {
                list.innerHTML = '<div class="pa-rec-item"><p class="pa-rec-title">No recommendation available</p><p class="pa-rec-copy">Refresh after new incident and resource activity is recorded.</p></div>';
                return;
            }
            list.innerHTML = recommendations.map((item, index) => `
                <div class="pa-rec-item">
                    <p class="pa-rec-title">${index + 1}. Action Signal</p>
                    <p class="pa-rec-copy">${paEscape(item)}</p>
                </div>
            `).join('');
        }

        function paRenderTypes(items) {
            const list = document.getElementById('paTypeList');
            if (!list) return;
            const rows = (Array.isArray(items) ? items : []).filter((row) => {
                const type = String(row.type || '').toLowerCase();
                return type === 'medical' || type === 'fire' || type === 'flood';
            });
            list.innerHTML = rows.map((row) => `
                <div class="pa-type-row">
                    <div class="pa-type-top">
                        <p class="pa-type-name">${paEscape(row.label || 'Other')}</p>
                        <span class="pa-status ${paRiskClass(row.risk)}">${paEscape(row.risk || 'Low')}</span>
                    </div>
                    <p class="pa-type-meta">${paNumber(row.forecast)} forecast next week | ${paNumber(row.share).toFixed(1)}% recent share | ${paEscape(row.trend || 'Stable')}</p>
                </div>
            `).join('');
        }



        function paChartOptions() {
            return {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    x: { grid: { display: false }, ticks: { maxRotation: 0, autoSkip: true, maxTicksLimit: 9 } },
                    y: { beginAtZero: true, ticks: { precision: 0 } }
                }
            };
        }

        function paRenderCharts(snapshot) {
            if (!window.Chart) return;
            const charts = snapshot.charts || {};
            const timelineCanvas = document.getElementById('paTimelineChart');
            if (timelineCanvas) {
                if (predictiveCharts.timeline) predictiveCharts.timeline.destroy();
                predictiveCharts.timeline = new Chart(timelineCanvas, {
                    type: 'line',
                    data: {
                        labels: Array.isArray(charts.timeline_labels) ? charts.timeline_labels : [],
                        datasets: [
                            {
                                label: 'Actual',
                                data: Array.isArray(charts.actual_series) ? charts.actual_series : [],
                                borderColor: '#0f766e',
                                backgroundColor: 'rgba(15, 118, 110, 0.1)',
                                tension: 0.35,
                                pointRadius: 2,
                                fill: true
                            },
                            {
                                label: 'Forecast',
                                data: Array.isArray(charts.forecast_series) ? charts.forecast_series : [],
                                borderColor: '#f59e0b',
                                backgroundColor: 'rgba(245, 158, 11, 0.12)',
                                borderDash: [6, 5],
                                tension: 0.35,
                                pointRadius: 2,
                                fill: true
                            }
                        ]
                    },
                    options: paChartOptions()
                });
            }

            const priorityCanvas = document.getElementById('paPriorityChart');
            if (priorityCanvas) {
                if (predictiveCharts.priority) predictiveCharts.priority.destroy();
                predictiveCharts.priority = new Chart(priorityCanvas, {
                    type: 'doughnut',
                    data: {
                        labels: Array.isArray(charts.priority_labels) ? charts.priority_labels : ['High', 'Medium', 'Low'],
                        datasets: [{
                            data: Array.isArray(charts.priority_values) ? charts.priority_values : [0, 0, 0],
                            backgroundColor: ['#ef4444', '#f59e0b', '#22c55e'],
                            borderWidth: 0
                        }]
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: { position: 'bottom' }
                        },
                        cutout: '62%'
                    }
                });
            }
        }

        function paRenderSnapshot(snapshot) {
            snapshot = snapshot || {};
            const forecast = snapshot.forecast || {};
            const resource = snapshot.resource || {};
            const current = snapshot.current || {};
            const resolution = snapshot.resolution || {};
            const peak = snapshot.peak_hour || {};

            paSet('paGeneratedAt', paText(snapshot.generated_at, '--'));
            paSet('paNext7Total', paNumber(forecast.next_7_total));
            paSet('paStrainIndex', paNumber(resource.strain_index).toFixed(1));
            paSet('paActiveIncidents', paNumber(current.active_incidents));
            paSet('paCarryover', `${paNumber(resolution.projected_carryover)} carryover`);
            paSet('paPeakWindow', paText(forecast.peak_window || peak.label, 'Unavailable'));
            paSet('paPeakCount', `${paNumber(peak.count)} hits`);
            paSet('paStandbyRatio', `${paNumber(resource.standby_ratio).toFixed(1)}% standby`);
            paSet('paAvailableUnits', paNumber(resource.available_units));
            paSet('paBusyUnits', paNumber(resource.busy_units));
            paSet('paTotalUnits', paNumber(resource.total_units));

            const trendEl = document.getElementById('paTrendLabel');
            if (trendEl) {
                trendEl.textContent = `${paText(forecast.delta_label, 'Stable')} ${paNumber(forecast.delta_percent).toFixed(1)}%`;
                trendEl.className = `pa-delta ${paTrendClass(forecast.delta_label)}`;
            }

            const meter = document.getElementById('paStrainMeter');
            if (meter) meter.style.width = `${Math.max(0, Math.min(100, paNumber(resource.strain_index)))}%`;

            paRenderRecommendations(snapshot.recommendations);
            paRenderTypes(snapshot.type_forecast);
            paRenderCharts(snapshot);
        }

        async function paRefresh() {
            const button = document.getElementById('paRefreshBtn');
            if (button) {
                button.disabled = true;
                button.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Refreshing';
            }
            try {
                const response = await fetch(predictiveEndpoint, { cache: 'no-store' });
                const data = await response.json();
                if (!response.ok || !data || !data.ok) {
                    throw new Error((data && data.error) || 'Unable to refresh predictive analytics');
                }
                paRenderSnapshot(data.snapshot || {});
                const meta = document.getElementById('paAiMeta');
                if (meta) meta.textContent = 'Decision support only. Dispatch approval remains with operations staff.';
            } catch (error) {
                const meta = document.getElementById('paAiMeta');
                if (meta) meta.textContent = error.message || 'Unable to refresh predictive analytics.';
            } finally {
                if (button) {
                    button.disabled = false;
                    button.innerHTML = '<i class="fas fa-rotate" aria-hidden="true"></i> Refresh';
                }
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            paRenderSnapshot(predictiveInitialSnapshot || {});
            const button = document.getElementById('paRefreshBtn');
            if (button) button.addEventListener('click', paRefresh);
        });
    </script>
</body>
</html>
