(() => {
    'use strict';

    const configElement = document.getElementById('reportAnalyticsConfig');
    let config = {};
    try {
        config = configElement ? JSON.parse(configElement.textContent || '{}') : {};
    } catch (error) {
        console.error('Invalid report analytics configuration', error);
    }

    const DEFAULT_FILTERS = Object.assign({ period: 'month', start: '', end: '' }, config.defaultFilters || {});
    const TIMEZONE = config.timezone || 'Asia/Manila';
    const RESPONSE_SLA_MINUTES = Number(config.responseSlaMinutes || 10);
    const TARGETS = Object.assign({
        arrivalCompliancePercent: 90,
        resolutionPercent: 95,
        acknowledgementPercent: 95,
        utilizationMinPercent: 70,
        utilizationMaxPercent: 85,
    }, config.targets || {});

    const state = {
        filters: {},
        meta: config.initialMeta || {},
        metrics: {},
        dispatch: {},
        responseDaily: {},
        incidents: [],
        charts: {},
        refreshController: null,
        refreshSequence: 0,
        refreshInFlight: false,
        autoRefreshTimer: null,
    };

    window.currentFilters = state.filters;

    function byId(id) {
        return document.getElementById(id);
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function finiteNumber(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        const number = Number(value);
        return Number.isFinite(number) ? number : null;
    }

    function formatNumber(value, decimals = 0) {
        const number = finiteNumber(value);
        return number === null ? '—' : number.toLocaleString(undefined, {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
    }

    function formatPercent(value, decimals = 1) {
        const number = finiteNumber(value);
        return number === null ? '—' : `${formatNumber(number, decimals)}%`;
    }

    function formatMinutes(value, decimals = 1) {
        const number = finiteNumber(value);
        return number === null ? '—' : `${formatNumber(number, decimals)} min`;
    }

    function formatDateTime(value) {
        if (!value) return '—';
        const raw = String(value).trim();
        const looksLikeLocalSqlDateTime = /^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2})?$/.test(raw);
        const hasExplicitTimezone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(raw);
        const normalized = looksLikeLocalSqlDateTime && !hasExplicitTimezone
            ? `${raw.replace(' ', 'T')}+08:00`
            : raw;
        const date = new Date(normalized);
        if (Number.isNaN(date.getTime())) return raw;
        return new Intl.DateTimeFormat(undefined, {
            timeZone: TIMEZONE,
            year: 'numeric',
            month: 'short',
            day: '2-digit',
            hour: 'numeric',
            minute: '2-digit',
        }).format(date);
    }

    function buildQuery(params = {}) {
        const entries = Object.entries(params).filter(([, value]) => value !== undefined && value !== null && value !== '');
        const query = new URLSearchParams(entries).toString();
        return query ? `?${query}` : '';
    }
    window.buildQuery = buildQuery;

    function showNotification(message, type = 'info', duration = 3600) {
        document.querySelectorAll('.report-notification').forEach((item) => item.remove());
        const notification = document.createElement('div');
        notification.className = `report-notification ${type}`;
        notification.setAttribute('role', type === 'error' ? 'alert' : 'status');
        notification.textContent = String(message || '');
        document.body.appendChild(notification);
        window.setTimeout(() => {
            notification.classList.add('leaving');
            window.setTimeout(() => notification.remove(), 220);
        }, duration);
    }
    window.showNotification = showNotification;

    async function fetchJson(url, options = {}) {
        const response = await fetch(url, Object.assign({ cache: 'no-store' }, options));
        let data;
        try {
            data = await response.json();
        } catch (error) {
            throw new Error(`Invalid server response (${response.status})`);
        }
        if (!response.ok || !data || data.ok !== true) {
            throw new Error((data && data.error) ? String(data.error) : `Request failed (${response.status})`);
        }
        return data;
    }

    function manilaTodayParts() {
        const parts = new Intl.DateTimeFormat('en-CA', {
            timeZone: TIMEZONE,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        }).formatToParts(new Date());
        const values = Object.fromEntries(parts.map((part) => [part.type, part.value]));
        return {
            year: Number(values.year),
            month: Number(values.month),
            day: Number(values.day),
        };
    }

    function utcDate(year, month, day) {
        return new Date(Date.UTC(year, month - 1, day));
    }

    function dateToIso(date) {
        return date.toISOString().slice(0, 10);
    }

    function periodDates(period) {
        const todayParts = manilaTodayParts();
        const today = utcDate(todayParts.year, todayParts.month, todayParts.day);
        let start = new Date(today.getTime());
        switch (period) {
            case 'today':
                break;
            case 'week': {
                const day = today.getUTCDay() || 7;
                start.setUTCDate(start.getUTCDate() - (day - 1));
                break;
            }
            case 'quarter': {
                const quarterMonth = Math.floor((todayParts.month - 1) / 3) * 3 + 1;
                start = utcDate(todayParts.year, quarterMonth, 1);
                break;
            }
            case 'year':
                start = utcDate(todayParts.year, 1, 1);
                break;
            case 'month':
            default:
                start = utcDate(todayParts.year, todayParts.month, 1);
                break;
        }
        return { start: dateToIso(start), end: dateToIso(today) };
    }

    function syncDateInputsForPeriod() {
        const periodSelect = byId('time-period');
        const startInput = byId('start-date');
        const endInput = byId('end-date');
        if (!periodSelect || !startInput || !endInput) return;
        const custom = periodSelect.value === 'custom';
        startInput.disabled = !custom;
        endInput.disabled = !custom;
        startInput.closest('.filter-group')?.classList.toggle('is-disabled', !custom);
        endInput.closest('.filter-group')?.classList.toggle('is-disabled', !custom);
        if (!custom) {
            const dates = periodDates(periodSelect.value || 'month');
            startInput.value = dates.start;
            endInput.value = dates.end;
        } else {
            if (!startInput.value) startInput.value = DEFAULT_FILTERS.start || periodDates('month').start;
            if (!endInput.value) endInput.value = DEFAULT_FILTERS.end || periodDates('month').end;
        }
    }

    function getFilters() {
        const period = byId('time-period')?.value || 'month';
        const filters = { period };
        const type = byId('incident-type')?.value || '';
        const priority = byId('priority-level')?.value || '';
        if (type) filters.type = type;
        if (priority) filters.priority = priority;
        if (period === 'custom') {
            filters.start = byId('start-date')?.value || '';
            filters.end = byId('end-date')?.value || '';
        }
        return filters;
    }

    function validCustomRange(filters) {
        if (filters.period !== 'custom') return true;
        if (!filters.start || !filters.end) {
            showNotification('Select both a start and end date.', 'error');
            return false;
        }
        if (filters.start > filters.end) {
            showNotification('End date must be on or after the start date.', 'error');
            return false;
        }
        return true;
    }

    function getReportView() {
        return byId('report-type')?.value || '';
    }

    function applyReportView() {
        const view = getReportView();
        document.querySelectorAll('[data-report-section]').forEach((section) => {
            const allowed = String(section.getAttribute('data-report-section') || '').split(/\s+/).filter(Boolean);
            section.hidden = view !== '' && !allowed.includes(view);
        });
        Object.values(state.charts).forEach((chart) => {
            if (chart && typeof chart.resize === 'function') chart.resize();
        });
    }

    function setLoading(loading) {
        document.documentElement.classList.toggle('report-is-loading', loading);
        const applyButton = byId('applyReportFilters');
        const clearButton = byId('clearReportFilters');
        if (applyButton) applyButton.disabled = loading;
        if (clearButton) clearButton.disabled = loading;
        document.querySelectorAll('.chart-container').forEach((container) => {
            let overlay = container.querySelector('.chart-loading');
            if (loading) {
                if (!overlay) {
                    overlay = document.createElement('div');
                    overlay.className = 'chart-loading';
                    overlay.innerHTML = '<span class="chart-spinner" aria-hidden="true"></span><span>Updating verified data…</span>';
                    container.appendChild(overlay);
                }
            } else if (overlay) {
                overlay.remove();
            }
        });
    }

    function trendDescriptor(current, previous, lowerIsBetter = false, unit = '') {
        const currentValue = finiteNumber(current);
        const previousValue = finiteNumber(previous);
        if (currentValue === null || previousValue === null) {
            return { className: 'neutral', icon: 'fa-minus', label: 'No baseline' };
        }
        const delta = currentValue - previousValue;
        if (Math.abs(delta) < 0.05) {
            return { className: 'neutral', icon: 'fa-minus', label: 'Stable' };
        }
        const improved = lowerIsBetter ? delta < 0 : delta > 0;
        return {
            className: improved ? 'positive' : 'negative',
            icon: delta > 0 ? 'fa-arrow-up' : 'fa-arrow-down',
            label: `${delta > 0 ? '+' : ''}${formatNumber(delta, 1)}${unit}`,
        };
    }

    function setTrendElement(element, trend) {
        if (!element) return;
        element.classList.remove('positive', 'negative', 'neutral');
        element.classList.add(trend.className || 'neutral');
        element.innerHTML = `<i class="fas ${escapeHtml(trend.icon || 'fa-minus')}" aria-hidden="true"></i><span>${escapeHtml(trend.label || '')}</span>`;
    }

    function renderRangeMeta(meta = {}) {
        state.meta = meta;
        const rangeLabel = byId('reportRangeLabel');
        const rangeDates = byId('reportRangeDates');
        if (rangeLabel) rangeLabel.textContent = meta.period_label || 'Selected range';
        if (rangeDates) rangeDates.textContent = `${meta.start_date || '—'} to ${meta.end_date || '—'}`;
    }

    function renderMetricCards(metrics = {}) {
        state.metrics = metrics;
        const avg = finiteNumber(metrics.avg_response_time_min);
        const previousAvg = finiteNumber(metrics.previous_avg_response_time_min);
        const incidents = Number(metrics.total_incidents ?? metrics.total_incidents_month ?? 0);
        const previousIncidents = Number(metrics.previous_total_incidents ?? metrics.total_incidents_last_month ?? 0);
        const resolution = finiteNumber(metrics.resolution_rate ?? metrics.success_rate);
        const previousResolution = finiteNumber(metrics.previous_resolution_rate ?? metrics.previous_success_rate);

        if (byId('metricAvgResponse')) byId('metricAvgResponse').textContent = formatMinutes(avg);
        if (byId('metricResponseSamples')) byId('metricResponseSamples').textContent = formatNumber(metrics.avg_response_sample_count ?? 0, 0);
        setTrendElement(byId('metricResponseTrend'), trendDescriptor(avg, previousAvg, true, ' min'));

        if (byId('metricIncidentsMonth')) byId('metricIncidentsMonth').textContent = formatNumber(incidents, 0);
        if (byId('metricLastMonth')) byId('metricLastMonth').textContent = formatNumber(previousIncidents, 0);
        const incidentDelta = incidents - previousIncidents;
        setTrendElement(byId('metricIncidentsTrend'), {
            className: 'neutral',
            icon: incidentDelta > 0 ? 'fa-arrow-up' : (incidentDelta < 0 ? 'fa-arrow-down' : 'fa-minus'),
            label: `${incidentDelta > 0 ? '+' : ''}${formatNumber(incidentDelta, 0)}`,
        });
        const deltaSpan = byId('metricIncidentsDelta');
        if (deltaSpan) deltaSpan.textContent = `${incidentDelta > 0 ? '+' : ''}${formatNumber(incidentDelta, 0)}`;

        if (byId('metricUtilization')) byId('metricUtilization').textContent = formatPercent(metrics.resource_utilization);
        if (byId('metricSuccess')) byId('metricSuccess').textContent = formatPercent(resolution);
        if (byId('metricResolvedCount')) byId('metricResolvedCount').textContent = formatNumber(metrics.resolved_incidents ?? 0, 0);
        setTrendElement(byId('metricResolutionTrend'), trendDescriptor(resolution, previousResolution, false, ' pp'));

        renderPerformanceTable(metrics);
    }

    function statusBadge(label, tone) {
        const className = tone === 'good' ? 'status-resolved' : (tone === 'bad' ? 'status-critical' : 'status-pending');
        return `<span class="status-badge ${className}">${escapeHtml(label)}</span>`;
    }

    function trendHtml(trend) {
        const classMap = { positive: 'trend-up', negative: 'trend-down', neutral: 'trend-neutral' };
        return `<div class="trend-indicator ${classMap[trend.className] || 'trend-neutral'}"><i class="fas ${escapeHtml(trend.icon)}"></i> ${escapeHtml(trend.label)}</div>`;
    }

    function performanceStatus(value, goodTest, warningTest, labels) {
        const number = finiteNumber(value);
        if (number === null) return statusBadge('Unavailable', 'warn');
        if (goodTest(number)) return statusBadge(labels[0], 'good');
        if (warningTest(number)) return statusBadge(labels[1], 'warn');
        return statusBadge(labels[2], 'bad');
    }

    function renderPerformanceTable(metrics = {}) {
        const tbody = byId('performanceMetricsBody');
        if (!tbody) return;
        const response = finiteNumber(metrics.avg_response_time_min);
        const responseSamples = Number(metrics.avg_response_sample_count || 0);
        const sla = finiteNumber(metrics.response_sla_compliance_rate);
        const resolution = finiteNumber(metrics.resolution_rate ?? metrics.success_rate);
        const ack = finiteNumber(metrics.dispatch_acknowledgement_rate);
        const utilization = finiteNumber(metrics.resource_utilization);

        const rows = [
            {
                name: 'Average Dispatch-to-Scene',
                value: responseSamples > 0 ? formatMinutes(response) : 'No valid on-scene samples',
                target: `≤ ${RESPONSE_SLA_MINUTES} min`,
                trend: trendDescriptor(response, metrics.previous_avg_response_time_min, true, ' min'),
                status: responseSamples === 0 ? statusBadge('Unavailable', 'warn') : performanceStatus(response, (v) => v <= RESPONSE_SLA_MINUTES, (v) => v <= RESPONSE_SLA_MINUTES * 1.5, ['On target', 'Watch', 'Delayed']),
            },
            {
                name: 'Arrival SLA Compliance',
                value: formatPercent(sla),
                target: `≥ ${formatNumber(TARGETS.arrivalCompliancePercent, 0)}%`,
                trend: { className: 'neutral', icon: 'fa-circle-info', label: `${formatNumber(metrics.avg_response_sample_count ?? 0, 0)} sample(s)` },
                status: performanceStatus(sla, (v) => v >= TARGETS.arrivalCompliancePercent, (v) => v >= Math.max(0, TARGETS.arrivalCompliancePercent - 15), ['On target', 'Watch', 'Needs action']),
            },
            {
                name: 'Incident Resolution Rate',
                value: formatPercent(resolution),
                target: `≥ ${formatNumber(TARGETS.resolutionPercent, 0)}%`,
                trend: trendDescriptor(resolution, metrics.previous_resolution_rate ?? metrics.previous_success_rate, false, ' pp'),
                status: performanceStatus(resolution, (v) => v >= TARGETS.resolutionPercent, (v) => v >= Math.max(0, TARGETS.resolutionPercent - 15), ['On target', 'Below target', 'Needs action']),
            },
            {
                name: 'Dispatch Acknowledgement Rate',
                value: formatPercent(ack),
                target: `≥ ${formatNumber(TARGETS.acknowledgementPercent, 0)}%`,
                trend: { className: 'neutral', icon: 'fa-circle-info', label: `${formatNumber(metrics.total_dispatches ?? 0, 0)} dispatch(es)` },
                status: performanceStatus(ack, (v) => v >= TARGETS.acknowledgementPercent, (v) => v >= Math.max(0, TARGETS.acknowledgementPercent - 15), ['On target', 'Below target', 'Needs action']),
            },
            {
                name: 'Current Unit Utilization',
                value: formatPercent(utilization),
                target: `${formatNumber(TARGETS.utilizationMinPercent, 0)}–${formatNumber(TARGETS.utilizationMaxPercent, 0)}%`,
                trend: { className: 'neutral', icon: 'fa-satellite-dish', label: 'Live snapshot' },
                status: performanceStatus(utilization, (v) => v >= TARGETS.utilizationMinPercent && v <= TARGETS.utilizationMaxPercent, (v) => v >= Math.max(0, TARGETS.utilizationMinPercent - 20) && v <= Math.min(100, TARGETS.utilizationMaxPercent + 10), ['Within target', 'Outside target', 'Capacity risk']),
            },
        ];

        tbody.innerHTML = rows.map((row) => `
            <tr>
                <td><strong>${escapeHtml(row.name)}</strong></td>
                <td>${escapeHtml(row.value)}</td>
                <td>${escapeHtml(row.target)}</td>
                <td>${trendHtml(row.trend)}</td>
                <td>${row.status}</td>
            </tr>
        `).join('');
    }

    function chartTheme() {
        const dark = document.documentElement.getAttribute('data-theme') === 'dark';
        return dark ? {
            text: '#e5eef9', muted: '#94a3b8', grid: 'rgba(148,163,184,.16)',
            tooltipBg: '#020817', tooltipText: '#f8fafc', tooltipBorder: '#334155',
        } : {
            text: '#1f2937', muted: '#6b7280', grid: 'rgba(148,163,184,.22)',
            tooltipBg: '#ffffff', tooltipText: '#111827', tooltipBorder: '#d1d5db',
        };
    }

    function replaceChart(key, canvasId, configValue) {
        const canvas = byId(canvasId);
        if (!canvas || typeof window.Chart !== 'function') return;
        if (state.charts[key]) state.charts[key].destroy();
        state.charts[key] = new Chart(canvas, configValue);
    }

    function commonTooltip(theme) {
        return {
            backgroundColor: theme.tooltipBg,
            titleColor: theme.tooltipText,
            bodyColor: theme.tooltipText,
            borderColor: theme.tooltipBorder,
            borderWidth: 1,
        };
    }

    function renderCharts(metricsData, responseData, dispatchData) {
        const metrics = metricsData.metrics || {};
        const theme = chartTheme();
        const responseLabels = responseData.labels || [];
        const responseValues = (responseData.data || []).map((value) => finiteNumber(value));
        const sampleCounts = responseData.sample_counts || [];

        replaceChart('response', 'responseTimeChart', {
            type: 'line',
            data: {
                labels: responseLabels,
                datasets: [
                    {
                        label: 'Avg dispatch-to-scene (min)',
                        data: responseValues,
                        borderColor: '#2563eb',
                        backgroundColor: 'rgba(37,99,235,.14)',
                        fill: true,
                        tension: 0.28,
                        spanGaps: false,
                        pointRadius: 3,
                    },
                    {
                        label: `SLA target (${RESPONSE_SLA_MINUTES} min)`,
                        data: responseLabels.map(() => RESPONSE_SLA_MINUTES),
                        borderColor: '#f59e0b',
                        borderDash: [6, 5],
                        pointRadius: 0,
                        fill: false,
                    },
                ],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { intersect: false, mode: 'index' },
                plugins: {
                    legend: { labels: { color: theme.text } },
                    tooltip: Object.assign(commonTooltip(theme), {
                        callbacks: {
                            label(context) {
                                if (context.datasetIndex === 0) {
                                    const value = context.raw;
                                    const samples = Number(sampleCounts[context.dataIndex] || 0);
                                    return value === null ? 'No valid on-scene samples' : `${Number(value).toFixed(1)} min (${samples} sample${samples === 1 ? '' : 's'})`;
                                }
                                return `${RESPONSE_SLA_MINUTES} min target`;
                            },
                        },
                    }),
                },
                scales: {
                    x: { ticks: { color: theme.muted, maxRotation: 35, minRotation: 0 }, grid: { color: theme.grid } },
                    y: { beginAtZero: true, title: { display: true, text: 'Minutes', color: theme.text }, ticks: { color: theme.muted }, grid: { color: theme.grid } },
                },
            },
        });

        const typeCounts = metrics.incidents_by_type || {};
        const typeLabels = ['Medical', 'Fire', 'Police', 'Traffic', 'Other'];
        const typeKeys = ['medical', 'fire', 'police', 'traffic', 'other'];
        replaceChart('types', 'incidentsTypesChart', {
            type: 'bar',
            data: { labels: typeLabels, datasets: [{ label: 'Incidents created', data: typeKeys.map((key) => Number(typeCounts[key] || 0)), backgroundColor: ['#22c55e', '#ef4444', '#3b82f6', '#f59e0b', '#94a3b8'], borderRadius: 7 }] },
            options: {
                responsive: true, maintainAspectRatio: false, indexAxis: 'y',
                plugins: { legend: { display: false }, tooltip: commonTooltip(theme) },
                scales: { x: { beginAtZero: true, ticks: { precision: 0, color: theme.muted }, grid: { color: theme.grid } }, y: { ticks: { color: theme.text }, grid: { display: false } } },
            },
        });

        const priorityCounts = metrics.incidents_by_priority || {};
        const priorityLabels = ['Critical', 'High', 'Medium', 'Low', 'Other'];
        const priorityKeys = ['critical', 'high', 'medium', 'low', 'other'];
        replaceChart('priorities', 'callDurationChart', {
            type: 'doughnut',
            data: { labels: priorityLabels, datasets: [{ data: priorityKeys.map((key) => Number(priorityCounts[key] || 0)), backgroundColor: ['#991b1b', '#ef4444', '#f59e0b', '#22c55e', '#94a3b8'], borderWidth: 2 }] },
            options: { responsive: true, maintainAspectRatio: false, cutout: '55%', plugins: { legend: { position: 'bottom', labels: { color: theme.text } }, tooltip: commonTooltip(theme) } },
        });

        const dispatchMetrics = dispatchData.metrics || {};
        const byUnitType = dispatchMetrics.by_unit_type || {};
        const unitTypeLabels = ['Ambulance', 'Fire', 'Police', 'Rescue', 'Other'];
        const unitTypeKeys = ['ambulance', 'fire', 'police', 'rescue', 'other'];
        replaceChart('dispatch', 'dispatchDailyChart', {
            type: 'polarArea',
            data: { labels: unitTypeLabels, datasets: [{ data: unitTypeKeys.map((key) => Number(byUnitType[key] || 0)), backgroundColor: ['rgba(59,130,246,.72)', 'rgba(239,68,68,.72)', 'rgba(245,158,11,.72)', 'rgba(34,197,94,.72)', 'rgba(148,163,184,.72)'] }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom', labels: { color: theme.text } }, tooltip: commonTooltip(theme) }, scales: { r: { beginAtZero: true, ticks: { precision: 0, color: theme.muted, backdropColor: 'transparent' }, grid: { color: theme.grid }, angleLines: { color: theme.grid }, pointLabels: { color: theme.text } } } },
        });

        const performanceLabels = ['Arrival SLA', 'Resolution', 'Acknowledgement', 'Unit utilization'];
        const performanceValues = [
            finiteNumber(metrics.response_sla_compliance_rate),
            finiteNumber(metrics.resolution_rate ?? metrics.success_rate),
            finiteNumber(metrics.dispatch_acknowledgement_rate),
            finiteNumber(metrics.resource_utilization),
        ];
        replaceChart('performance', 'performanceChart', {
            type: 'bar',
            data: { labels: performanceLabels, datasets: [{ label: 'Percentage', data: performanceValues, backgroundColor: ['#2563eb', '#16a34a', '#7c3aed', '#f59e0b'], borderRadius: 8 }] },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: Object.assign(commonTooltip(theme), { callbacks: { label: (context) => context.raw === null ? 'Unavailable' : `${Number(context.raw).toFixed(1)}%` } }) }, scales: { y: { min: 0, max: 100, ticks: { color: theme.muted, callback: (value) => `${value}%` }, grid: { color: theme.grid } }, x: { ticks: { color: theme.text }, grid: { display: false } } } },
        });

        const snapshot = dispatchData.unit_snapshot || metrics.unit_snapshot || {};
        replaceChart('resources', 'resourcesChart', {
            type: 'bar',
            data: {
                labels: ['Available', 'In use', 'Maintenance', 'Unavailable', 'Other'],
                datasets: [{
                    label: 'Current units',
                    data: [snapshot.available_units ?? null, snapshot.in_use_units ?? null, snapshot.maintenance_units ?? null, snapshot.unavailable_units ?? null, snapshot.other_units ?? null],
                    backgroundColor: ['#22c55e', '#3b82f6', '#f59e0b', '#ef4444', '#94a3b8'],
                    borderRadius: 8,
                }],
            },
            options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, tooltip: commonTooltip(theme) }, scales: { y: { beginAtZero: true, ticks: { precision: 0, color: theme.muted }, grid: { color: theme.grid } }, x: { ticks: { color: theme.text }, grid: { display: false } } } },
        });
    }

    function renderDispatchTopUnits(items) {
        const tbody = byId('dispatchTopUnitsBody');
        if (!tbody) return;
        const rows = Array.isArray(items) ? items.slice(0, 10) : [];
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="3" class="report-empty-cell">No dispatches found for this range.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map((unit) => `
            <tr>
                <td><strong>${escapeHtml(unit.identifier || (unit.unit_id ? `Unit #${unit.unit_id}` : 'Unknown unit'))}</strong></td>
                <td>${escapeHtml(String(unit.unit_type || 'other').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()))}</td>
                <td>${escapeHtml(formatNumber(unit.count || 0, 0))}</td>
            </tr>
        `).join('');
    }

    function typeLabel(value) {
        const type = String(value || '').toLowerCase();
        const labels = { medical: 'Medical Emergency', fire: 'Fire', police: 'Police Emergency', crime: 'Police Emergency', traffic: 'Traffic Accident', accident: 'Traffic Accident' };
        return labels[type] || 'Other';
    }

    function statusClass(status, priority = '') {
        const normalized = String(status || '').toLowerCase();
        if (normalized === 'resolved') return 'status-resolved';
        if (normalized.includes('dispatch') || normalized.includes('progress') || normalized === 'active') return 'status-pending';
        if (normalized.includes('cancel') || String(priority).toLowerCase() === 'critical') return 'status-critical';
        return 'status-pending';
    }

    function renderRecentIncidents(items) {
        state.incidents = Array.isArray(items) ? items : [];
        const tbody = byId('recentIncidentsBody');
        if (!tbody) return;
        if (!state.incidents.length) {
            tbody.innerHTML = '<tr><td colspan="7" class="report-empty-cell">No incidents were created in the selected range.</td></tr>';
            return;
        }
        tbody.innerHTML = state.incidents.map((item) => {
            const response = finiteNumber(item.response_time_min);
            return `
                <tr>
                    <td><strong>${escapeHtml(item.incident_code || item.reference_no || item.id || '')}</strong><div class="table-subline">${escapeHtml(formatDateTime(item.created_at))}</div></td>
                    <td>${escapeHtml(typeLabel(item.type))}</td>
                    <td>${escapeHtml(item.location || item.location_address || '—')}</td>
                    <td>${escapeHtml(String(item.priority || '—').toUpperCase())}</td>
                    <td>${response === null ? '<span class="report-data-missing">No on-scene timestamp</span>' : escapeHtml(formatMinutes(response))}</td>
                    <td><span class="status-badge ${statusClass(item.status, item.priority)}">${escapeHtml(String(item.status || 'unknown').replace(/_/g, ' '))}</span></td>
                    <td><button type="button" class="btn-report" data-incident-details="${escapeHtml(item.id)}"><i class="fas fa-eye"></i> Details</button></td>
                </tr>
            `;
        }).join('');
    }

    function formatFailureKind(value) {
        return String(value || 'failure').replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
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
            return `<span class="status-badge ${recoveryStatusClass(status)}">${escapeHtml(String(status).replace(/_/g, ' '))}</span>`;
        }
        const labels = {
            retry_same_unit: { label: 'Retry', icon: 'fa-redo', className: '' },
            cancel_dispatch: { label: 'Cancel', icon: 'fa-ban', className: 'cancel' },
            close_failure: { label: 'Close', icon: 'fa-check', className: 'close' },
        };
        const buttons = actions.map((action) => {
            const meta = labels[action] || { label: action, icon: 'fa-tools', className: '' };
            return `<button type="button" class="dispatch-recovery-btn ${escapeHtml(meta.className)}" data-recover-dispatch data-recovery-action="${escapeHtml(action)}" data-attempt-id="${escapeHtml(item.id || '')}" data-failure-kind="${escapeHtml(item.failure_kind || '')}" data-incident-id="${escapeHtml(item.incident_id || '')}" data-unit-id="${escapeHtml(item.unit_id || '')}"><i class="fas ${escapeHtml(meta.icon)}"></i> ${escapeHtml(meta.label)}</button>`;
        }).join('');
        return `<div class="dispatch-recovery-actions">${buttons}<span class="status-badge ${recoveryStatusClass(status)}">${escapeHtml(String(status).replace(/_/g, ' '))}</span></div>`;
    }

    function renderFailedDispatchAttempts(items, metrics = {}) {
        const tbody = byId('failedDispatchAttemptsBody');
        if (byId('failedDispatchTotal')) byId('failedDispatchTotal').textContent = formatNumber(metrics.failure_signals_total ?? metrics.failed_attempts_total ?? 0, 0);
        if (byId('failedDispatchStale')) byId('failedDispatchStale').textContent = formatNumber(metrics.stale_unacknowledged_dispatches ?? 0, 0);
        if (byId('failedDispatchCancelled')) byId('failedDispatchCancelled').textContent = formatNumber(metrics.cancelled_dispatches ?? 0, 0);
        if (!tbody) return;
        const rows = Array.isArray(items) ? items.slice(0, 20) : [];
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="8" class="report-empty-cell">No failure signals found for the selected filters.</td></tr>';
            return;
        }
        tbody.innerHTML = rows.map((item) => `
            <tr>
                <td>${escapeHtml(formatDateTime(item.attempted_at))}</td>
                <td><strong>${escapeHtml(item.reference_no || (item.incident_id ? `#${item.incident_id}` : 'No incident'))}</strong></td>
                <td>${escapeHtml(item.unit_identifier || (item.unit_id ? `Unit #${item.unit_id}` : 'No unit'))}</td>
                <td>${escapeHtml(typeLabel(item.incident_type))}</td>
                <td>${escapeHtml(String(item.priority || '—').toUpperCase())}</td>
                <td><strong>${escapeHtml(formatFailureKind(item.failure_kind))}</strong><div class="table-subline">${escapeHtml(item.failure_reason || 'No reason recorded')}</div></td>
                <td>${escapeHtml(item.source || '—')}</td>
                <td>${renderDispatchRecoveryActions(item)}</td>
            </tr>
        `).join('');
    }

    function closeModal(overlay) {
        if (!overlay) return;
        overlay.remove();
        document.documentElement.classList.remove('report-modal-open');
    }

    function openModal(title, contentHtml, footerHtml = '') {
        const overlay = document.createElement('div');
        overlay.className = 'incident-modal-overlay';
        overlay.innerHTML = `
            <section class="incident-modal" role="dialog" aria-modal="true" aria-label="${escapeHtml(title)}">
                <header class="incident-modal-header"><h3>${escapeHtml(title)}</h3><button type="button" class="incident-modal-close" aria-label="Close">&times;</button></header>
                <div class="incident-modal-body">${contentHtml}</div>
                <footer class="incident-modal-footer">${footerHtml || '<button type="button" class="btn-report" data-modal-close>Close</button>'}</footer>
            </section>
        `;
        document.body.appendChild(overlay);
        document.documentElement.classList.add('report-modal-open');
        let closeCurrentModal;
        const escapeHandler = (event) => {
            if (event.key === 'Escape' && typeof closeCurrentModal === 'function') {
                closeCurrentModal();
            }
        };
        closeCurrentModal = () => {
            document.removeEventListener('keydown', escapeHandler);
            closeModal(overlay);
        };
        overlay.addEventListener('click', (event) => {
            if (event.target === overlay || event.target.closest('[data-modal-close], .incident-modal-close')) {
                closeCurrentModal();
            }
        });
        document.addEventListener('keydown', escapeHandler);
        overlay.querySelector('.incident-modal-close')?.focus();
        return overlay;
    }

    async function viewIncidentDetails(id) {
        try {
            const data = await fetchJson(`api/incident_details.php?id=${encodeURIComponent(id)}`);
            const item = data.incident || data.data || {};
            const unitsRaw = data.units || item.units || item.dispatched_units || [];
            const units = Array.isArray(unitsRaw) ? unitsRaw.map((unit) => unit.identifier || unit.name || String(unit)).join(', ') : String(unitsRaw || '');
            const fields = [
                ['Reference', item.reference_no || item.incident_code || `#${id}`],
                ['Title', item.title || '—'],
                ['Type', typeLabel(item.type)],
                ['Priority', String(item.priority || '—').toUpperCase()],
                ['Status', String(item.status || '—').replace(/_/g, ' ')],
                ['Location', item.location_address || item.location || '—'],
                ['Description', item.description || '—'],
                ['Created', formatDateTime(item.created_at)],
                ['Updated', formatDateTime(item.updated_at)],
                ['Resolved', formatDateTime(item.resolved_at)],
                ['Units', units || '—'],
            ];
            openModal('Incident Details', `<dl class="report-detail-grid">${fields.map(([label, value]) => `<div><dt>${escapeHtml(label)}</dt><dd>${escapeHtml(value)}</dd></div>`).join('')}</dl>`);
        } catch (error) {
            showNotification(error.message || 'Unable to load incident details.', 'error');
        }
    }
    window.viewIncidentDetails = viewIncidentDetails;

    async function recoverDispatchAttempt(button) {
        const action = button?.dataset?.recoveryAction || '';
        const attemptId = Number(button?.dataset?.attemptId || 0);
        if (!action || !attemptId) return;
        const prompts = {
            retry_same_unit: 'Retry this failed dispatch with the same unit?',
            cancel_dispatch: 'Cancel this stale assignment and free the unit?',
            close_failure: 'Mark this failure as handled?',
        };
        if (!window.confirm(prompts[action] || 'Apply this recovery action?')) return;
        button.disabled = true;
        try {
            const data = await fetchJson('api/dispatch_recovery.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    action,
                    attempt_id: attemptId,
                    failure_kind: button.dataset.failureKind || '',
                    incident_id: Number(button.dataset.incidentId || 0),
                    unit_id: Number(button.dataset.unitId || 0),
                }),
            });
            showNotification(data.message || 'Dispatch recovery completed.', 'success');
            await refreshDashboard({ notify: false, refreshAI: false });
        } catch (error) {
            showNotification(error.message || 'Dispatch recovery failed.', 'error');
        } finally {
            button.disabled = false;
        }
    }

    function formatAiInsightText(value) {
        const text = String(value || '')
            .replace(/\*\*(.+?)\*\*/g, '$1')
            .replace(/\*(.+?)\*/g, '$1')
            .replace(/^\s*#{1,6}\s*/gm, '')
            .replace(/^\s*[-*]\s*/gm, '')
            .trim();

        if (!text) return 'No insight text was returned.';

        return text
            .split(/\r?\n/)
            .map((line) => escapeHtml(line.trim()))
            .join('<br>');
    }

    async function refreshAIInsights() {
        const container = byId('ai-insights-content');
        if (!container) return;
        container.innerHTML = '<div class="ai-loading"><i class="fas fa-spinner fa-spin"></i> Analyzing the verified dataset…</div>';
        try {
            const data = await fetchJson(`api/ai_report_insights.php${buildQuery(state.filters)}`);
            container.innerHTML = formatAiInsightText(data.text || 'No insight text was returned.');
            container.classList.add('ai-insight-text');
        } catch (error) {
            container.classList.remove('ai-insight-text');
            container.innerHTML = `<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> ${escapeHtml(error.message || 'AI insights unavailable.')}</div>`;
        }
    }
    window.refreshAIInsights = refreshAIInsights;

    async function refreshDashboard(options = {}) {
        const sequence = ++state.refreshSequence;
        if (state.refreshController) state.refreshController.abort();
        const controller = new AbortController();
        state.refreshController = controller;
        state.refreshInFlight = true;
        setLoading(options.showLoading !== false);

        const filters = getFilters();
        if (!validCustomRange(filters)) {
            setLoading(false);
            state.refreshInFlight = false;
            return false;
        }
        state.filters = filters;
        window.currentFilters = state.filters;
        const query = buildQuery(filters);

        try {
            const [metricsData, responseData, dispatchData, incidentData] = await Promise.all([
                fetchJson(`api/report_metrics.php${query}`, { signal: controller.signal }),
                fetchJson(`api/report_response_times_daily.php${query}`, { signal: controller.signal }),
                fetchJson(`api/reports_dispatch.php${query}`, { signal: controller.signal }),
                fetchJson(`api/report_incidents.php${buildQuery(Object.assign({}, filters, { limit: 150 }))}`, { signal: controller.signal }),
            ]);
            if (sequence !== state.refreshSequence) return false;

            state.meta = metricsData.meta || {};
            state.metrics = metricsData.metrics || {};
            state.dispatch = dispatchData;
            state.responseDaily = responseData;
            state.incidents = incidentData.items || [];

            renderRangeMeta(metricsData.meta || {});
            renderMetricCards(metricsData.metrics || {});
            renderCharts(metricsData, responseData, dispatchData);
            renderDispatchTopUnits(dispatchData.top_units || []);
            renderFailedDispatchAttempts(dispatchData.failed_attempts || [], dispatchData.metrics || {});
            renderRecentIncidents(incidentData.items || []);
            applyReportView();

            if (options.refreshAI) await refreshAIInsights();
            if (options.notify) showNotification('Report analytics updated from verified source records.', 'success');
            return true;
        } catch (error) {
            if (error.name === 'AbortError') return false;
            console.error('Report analytics refresh failed', error);
            if (options.notify !== false) showNotification(error.message || 'Unable to refresh report analytics.', 'error', 5200);
            return false;
        } finally {
            if (sequence === state.refreshSequence) {
                state.refreshInFlight = false;
                setLoading(false);
            }
        }
    }

    async function applyFilters() {
        applyReportView();
        await refreshDashboard({ notify: true, refreshAI: true });
    }
    window.applyFilters = applyFilters;

    async function clearFilters() {
        if (byId('report-type')) byId('report-type').value = '';
        if (byId('time-period')) byId('time-period').value = DEFAULT_FILTERS.period || 'month';
        if (byId('incident-type')) byId('incident-type').value = '';
        if (byId('priority-level')) byId('priority-level').value = '';
        if (byId('start-date')) byId('start-date').value = DEFAULT_FILTERS.start || '';
        if (byId('end-date')) byId('end-date').value = DEFAULT_FILTERS.end || '';
        syncDateInputsForPeriod();
        applyReportView();
        await refreshDashboard({ notify: true, refreshAI: true });
    }
    window.clearFilters = clearFilters;

    function openReport(path) {
        window.open(`${path}${buildQuery(state.filters)}`, '_blank', 'noopener');
    }
    window.generateIncidentReport = () => openReport('api/reports_incident_summary.php');
    window.viewIncidentReport = () => openReport('api/reports_incident_summary.php');
    window.generatePerformanceReport = () => openReport('api/reports_performance.php');
    window.viewPerformanceReport = () => openReport('api/reports_performance.php');
    window.generateResourceReport = () => openReport('api/reports_resources.php');
    window.viewResourceReport = () => openReport('api/reports_resources.php');
    window.generateTrendReport = () => openReport('api/reports_trends.php');
    window.viewTrendReport = () => openReport('api/reports_trends.php');

    function exportPDF() {
        window.print();
    }
    window.exportPDF = exportPDF;

    function downloadFile(filename, content, mimeType) {
        const blob = new Blob([content], { type: mimeType });
        const url = URL.createObjectURL(blob);
        const link = document.createElement('a');
        link.href = url;
        link.download = filename;
        document.body.appendChild(link);
        link.click();
        link.remove();
        URL.revokeObjectURL(url);
    }

    function exportStamp() {
        return new Date().toISOString().replace(/[:T]/g, '-').slice(0, 19);
    }

    function safeSpreadsheetText(value) {
        const text = String(value == null ? '' : value);
        return /^[=+\-@\t\r]/.test(text) ? `'${text}` : text;
    }

    function csvCell(value) {
        const text = safeSpreadsheetText(value);
        return /[",\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
    }

    function collectCurrentExportData() {
        return {
            generated_at: new Date().toISOString(),
            meta: state.meta,
            filters: state.filters,
            definitions: state.meta.definitions || {},
            metrics: state.metrics,
            dispatch: {
                meta: state.dispatch.meta || {},
                metrics: state.dispatch.metrics || {},
                top_units: state.dispatch.top_units || [],
                all_units: state.dispatch.all_units || [],
                failed_attempts: state.dispatch.failed_attempts || [],
            },
            incidents: state.incidents,
            response_daily: state.responseDaily,
        };
    }

    async function ensureExportData() {
        if (!Object.keys(state.metrics).length) await refreshDashboard({ notify: false, refreshAI: false });
        return collectCurrentExportData();
    }

    async function exportJSON() {
        const data = await ensureExportData();
        downloadFile(`ers-report-${exportStamp()}.json`, JSON.stringify(data, null, 2), 'application/json;charset=utf-8');
        showNotification('JSON export downloaded.', 'success');
    }
    window.exportJSON = exportJSON;

    async function exportCSV() {
        const data = await ensureExportData();
        const rows = [
            ['Report Metadata'],
            ['Field', 'Value'],
            ...Object.entries(data.meta || {}).filter(([, value]) => typeof value !== 'object').map(([key, value]) => [key, value]),
            [],
            ['Metrics'],
            ['Metric', 'Value'],
            ...Object.entries(data.metrics || {}).filter(([, value]) => typeof value !== 'object').map(([key, value]) => [key, value]),
            [],
            ['Incidents Created in Selected Range'],
            ['Reference', 'Created At', 'Type', 'Priority', 'Status', 'Location', 'Unit', 'Dispatch-to-Scene Minutes'],
            ...(data.incidents || []).map((item) => [item.incident_code || item.reference_no || item.id, item.created_at, item.type, item.priority, item.status, item.location || item.location_address, item.unit_identifier, item.response_time_min]),
            [],
            ['Dispatched Units'],
            ['Unit', 'Unit Type', 'Dispatches'],
            ...((data.dispatch.all_units || []).map((unit) => [unit.identifier, unit.unit_type, unit.count])),
            [],
            ['Failure Signals'],
            ['Attempted At', 'Incident', 'Unit', 'Kind', 'Reason', 'Recovery Status'],
            ...((data.dispatch.failed_attempts || []).map((item) => [item.attempted_at, item.reference_no || item.incident_id, item.unit_identifier || item.unit_id, item.failure_kind, item.failure_reason, item.recovery_status])),
        ];
        const csv = `\uFEFF${rows.map((row) => row.map(csvCell).join(',')).join('\r\n')}`;
        downloadFile(`ers-report-${exportStamp()}.csv`, csv, 'text/csv;charset=utf-8');
        showNotification('CSV export downloaded.', 'success');
    }
    window.exportCSV = exportCSV;

    function xmlEscape(value) {
        return escapeHtml(safeSpreadsheetText(value));
    }

    async function exportExcel() {
        const data = await ensureExportData();
        const metricRows = Object.entries(data.metrics || {}).filter(([, value]) => typeof value !== 'object').map(([key, value]) => `<Row><Cell><Data ss:Type="String">${xmlEscape(key)}</Data></Cell><Cell><Data ss:Type="String">${xmlEscape(value)}</Data></Cell></Row>`).join('');
        const incidentRows = (data.incidents || []).map((item) => `<Row><Cell><Data ss:Type="String">${xmlEscape(item.incident_code || item.reference_no || item.id)}</Data></Cell><Cell><Data ss:Type="String">${xmlEscape(item.created_at)}</Data></Cell><Cell><Data ss:Type="String">${xmlEscape(item.type)}</Data></Cell><Cell><Data ss:Type="String">${xmlEscape(item.priority)}</Data></Cell><Cell><Data ss:Type="String">${xmlEscape(item.status)}</Data></Cell><Cell><Data ss:Type="String">${xmlEscape(item.location || item.location_address)}</Data></Cell><Cell><Data ss:Type="String">${xmlEscape(item.response_time_min)}</Data></Cell></Row>`).join('');
        const xml = `<?xml version="1.0"?><?mso-application progid="Excel.Sheet"?><Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Worksheet ss:Name="Metrics"><Table><Row><Cell><Data ss:Type="String">Metric</Data></Cell><Cell><Data ss:Type="String">Value</Data></Cell></Row>${metricRows}</Table></Worksheet><Worksheet ss:Name="Incidents"><Table><Row><Cell><Data ss:Type="String">Reference</Data></Cell><Cell><Data ss:Type="String">Created</Data></Cell><Cell><Data ss:Type="String">Type</Data></Cell><Cell><Data ss:Type="String">Priority</Data></Cell><Cell><Data ss:Type="String">Status</Data></Cell><Cell><Data ss:Type="String">Location</Data></Cell><Cell><Data ss:Type="String">Dispatch-to-Scene Minutes</Data></Cell></Row>${incidentRows}</Table></Worksheet></Workbook>`;
        downloadFile(`ers-report-${exportStamp()}.xml`, xml, 'application/vnd.ms-excel;charset=utf-8');
        showNotification('Excel-compatible workbook downloaded.', 'success');
    }
    window.exportExcel = exportExcel;

    function exportChart(chartId) {
        const canvas = byId(chartId);
        if (!canvas) {
            showNotification('Chart not found.', 'error');
            return;
        }
        const link = document.createElement('a');
        const title = canvas.closest('.chart-container')?.querySelector('.chart-title')?.textContent?.trim() || 'chart';
        link.href = canvas.toDataURL('image/png');
        link.download = `${title.toLowerCase().replace(/[^a-z0-9]+/g, '-')}.png`;
        link.click();
    }
    window.exportChart = exportChart;

    function showAllDispatchUnitsModal() {
        const units = Array.isArray(state.dispatch.all_units) ? state.dispatch.all_units : [];
        const rows = units.length ? units.map((unit) => `<tr><td>${escapeHtml(unit.identifier || `Unit #${unit.unit_id || ''}`)}</td><td>${escapeHtml(unit.unit_type || 'other')}</td><td>${escapeHtml(formatNumber(unit.count || 0, 0))}</td></tr>`).join('') : '<tr><td colspan="3">No dispatched units in this range.</td></tr>';
        openModal('All Dispatched Units', `<div class="report-modal-table"><table class="analytics-table"><thead><tr><th>Unit</th><th>Type</th><th>Dispatches</th></tr></thead><tbody>${rows}</tbody></table></div>`);
    }
    window.showAllDispatchUnitsModal = showAllDispatchUnitsModal;

    async function refreshChart() {
        const ok = await refreshDashboard({ notify: false, refreshAI: false });
        if (ok) showNotification('Charts updated.', 'success');
    }
    window.refreshChart = refreshChart;
    window.refreshDispatchReport = refreshChart;
    window.refreshPerformanceChart = refreshChart;
    window.refreshResourcesChart = refreshChart;

    function wireEvents() {
        byId('time-period')?.addEventListener('change', syncDateInputsForPeriod);
        byId('report-type')?.addEventListener('change', applyReportView);
        byId('recentIncidentsBody')?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-incident-details]');
            if (button) viewIncidentDetails(button.dataset.incidentDetails);
        });
        byId('failedDispatchAttemptsBody')?.addEventListener('click', (event) => {
            const button = event.target.closest('[data-recover-dispatch]');
            if (button) recoverDispatchAttempt(button);
        });
        document.addEventListener('themeChanged', () => {
            if (Object.keys(state.metrics).length) renderCharts({ metrics: state.metrics }, state.responseDaily, state.dispatch);
        });
    }

    document.addEventListener('DOMContentLoaded', async () => {
        syncDateInputsForPeriod();
        applyReportView();
        wireEvents();
        await refreshDashboard({ notify: false, refreshAI: true });
        state.autoRefreshTimer = window.setInterval(() => {
            if (!document.hidden && !state.refreshInFlight) {
                refreshDashboard({ notify: false, refreshAI: false, showLoading: false });
            }
        }, 60000);
    });
})();
