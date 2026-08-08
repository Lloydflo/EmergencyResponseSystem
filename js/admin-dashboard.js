(() => {
    'use strict';

    const config = window.ADMIN_DASHBOARD_CONFIG || {};
    const timezone = config.timezone || 'Asia/Manila';
    const state = {
        summary: null,
        weather: null,
        typeChart: null,
        priorityChart: null,
        trendChart: null,
        summaryInFlight: false,
        weatherInFlight: false,
        activityInFlight: false,
        alertsInFlight: false,
        refreshInFlight: false,
    };

    const byId = (id) => document.getElementById(id);
    const setText = (id, value) => {
        const element = byId(id);
        if (element) {
            element.textContent = value == null || value === '' ? '—' : String(value);
        }
    };

    const escapeHtml = (value) => String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const numberValue = (value, fallback = 0) => {
        const parsed = Number(value);
        return Number.isFinite(parsed) ? parsed : fallback;
    };

    const formatNumber = (value) => new Intl.NumberFormat('en-PH').format(numberValue(value));

    const parseApiDate = (value) => {
        if (!value) return null;
        const text = String(value).trim();
        const hasTimezone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(text);
        const normalized = text.includes('T') ? text : text.replace(' ', 'T');
        const date = new Date(hasTimezone ? normalized : `${normalized}+08:00`);
        return Number.isNaN(date.getTime()) ? null : date;
    };

    const dateTimeFormatter = new Intl.DateTimeFormat('en-PH', {
        timeZone: timezone,
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
    });

    const clockFormatter = new Intl.DateTimeFormat('en-PH', {
        timeZone: timezone,
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
    });

    const formatDateTime = (value) => {
        const date = value instanceof Date ? value : parseApiDate(value);
        return date ? dateTimeFormatter.format(date) : 'Unavailable';
    };

    const formatTimeAgo = (value) => {
        const date = parseApiDate(value);
        if (!date) return 'Time unavailable';
        const seconds = Math.max(0, Math.round((Date.now() - date.getTime()) / 1000));
        if (seconds < 60) return `${seconds}s ago`;
        if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`;
        if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`;
        return formatDateTime(date);
    };

    async function fetchJson(url) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { Accept: 'application/json' },
        });
        let payload = null;
        try {
            payload = await response.json();
        } catch (error) {
            throw new Error(`Invalid JSON response (${response.status})`);
        }
        if (!response.ok || !payload || payload.ok === false) {
            throw new Error(payload?.error || `Request failed (${response.status})`);
        }
        return payload;
    }

    function showToast(message, type = 'info') {
        let container = byId('dashboardToastContainer');
        if (!container) {
            container = document.createElement('div');
            container.id = 'dashboardToastContainer';
            container.className = 'dashboard-toast-container';
            document.body.appendChild(container);
        }
        const toast = document.createElement('div');
        toast.className = `dashboard-toast ${type}`;
        toast.setAttribute('role', 'status');
        toast.textContent = message;
        container.appendChild(toast);
        window.setTimeout(() => toast.classList.add('leaving'), 2800);
        window.setTimeout(() => toast.remove(), 3200);
    }

    function dashboardChartTheme() {
        const dark = document.documentElement.getAttribute('data-theme') === 'dark';
        return dark
            ? {
                text: '#e5eef9',
                muted: '#94a3b8',
                grid: 'rgba(148,163,184,.16)',
                tooltipBg: '#020817',
                tooltipBorder: '#334155',
                tooltipText: '#f8fafc',
            }
            : {
                text: '#172126',
                muted: '#64748b',
                grid: 'rgba(148,163,184,.2)',
                tooltipBg: '#ffffff',
                tooltipBorder: '#d7e0e5',
                tooltipText: '#111827',
            };
    }

    const barValueLabelsPlugin = {
        id: 'dashboardBarValueLabels',
        afterDatasetsDraw(chart) {
            if (chart.config.type !== 'bar') return;
            const dataset = chart.data.datasets[0];
            const meta = chart.getDatasetMeta(0);
            const theme = dashboardChartTheme();
            chart.ctx.save();
            chart.ctx.font = '700 12px system-ui, sans-serif';
            chart.ctx.fillStyle = theme.text;
            chart.ctx.textBaseline = 'middle';
            meta.data.forEach((bar, index) => {
                const value = numberValue(dataset.data[index]);
                chart.ctx.fillText(String(value), bar.x + 10, bar.y);
            });
            chart.ctx.restore();
        },
    };

    const doughnutCenterPlugin = {
        id: 'dashboardDoughnutCenter',
        afterDraw(chart) {
            if (chart.config.type !== 'doughnut') return;
            const dataset = chart.data.datasets[0];
            const meta = chart.getDatasetMeta(0);
            if (!meta.data.length) return;
            const total = dataset.data.reduce((sum, value) => sum + numberValue(value), 0);
            const theme = dashboardChartTheme();
            const { x, y } = meta.data[0];
            chart.ctx.save();
            chart.ctx.textAlign = 'center';
            chart.ctx.textBaseline = 'middle';
            chart.ctx.fillStyle = theme.text;
            chart.ctx.font = '750 26px system-ui, sans-serif';
            chart.ctx.fillText(String(total), x, y - 7);
            chart.ctx.fillStyle = theme.muted;
            chart.ctx.font = '650 11px system-ui, sans-serif';
            chart.ctx.fillText('Month to date', x, y + 16);
            chart.ctx.restore();
        },
    };

    function setChartState(id, message, visible) {
        const element = byId(id);
        if (!element) return;
        element.textContent = message;
        element.hidden = !visible;
    }

    function renderTypeChart(summary) {
        const canvas = byId('incidentsTypeBar');
        if (!canvas || typeof Chart === 'undefined') return;
        const counts = summary?.charts?.incidents_by_type || {};
        const labels = ['Medical', 'Fire', 'Police', 'Traffic', 'Other'];
        const values = [
            numberValue(counts.medical),
            numberValue(counts.fire),
            numberValue(counts.police),
            numberValue(counts.traffic),
            numberValue(counts.other),
        ];
        const total = values.reduce((sum, value) => sum + value, 0);
        if (state.typeChart) state.typeChart.destroy();
        state.typeChart = null;
        setChartState('incidentsTypeState', total ? '' : 'No month-to-date incidents are available.', !total);
        if (!total) return;

        const theme = dashboardChartTheme();
        state.typeChart = new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Incidents',
                    data: values,
                    backgroundColor: ['#ef4444', '#f59e0b', '#3b82f6', '#22c55e', '#94a3b8'],
                    borderRadius: 999,
                    borderSkipped: false,
                    barThickness: 28,
                    maxBarThickness: 32,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                indexAxis: 'y',
                layout: { padding: { right: 34 } },
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
                                return `${context.label}: ${context.raw} incident${Number(context.raw) === 1 ? '' : 's'}`;
                            },
                        },
                    },
                },
                scales: {
                    x: {
                        beginAtZero: true,
                        ticks: { precision: 0, stepSize: 1, color: theme.muted },
                        grid: { color: theme.grid },
                    },
                    y: {
                        ticks: { color: theme.text, font: { weight: '700' } },
                        grid: { display: false },
                    },
                },
            },
            plugins: [barValueLabelsPlugin],
        });
    }

    function renderPriorityChart(summary) {
        const canvas = byId('incidentsPriorityPie');
        if (!canvas || typeof Chart === 'undefined') return;
        const counts = summary?.charts?.incidents_by_priority || {};
        const all = [
            ['Critical', numberValue(counts.critical), '#991b1b', '#fecaca'],
            ['High', numberValue(counts.high), '#dc2626', '#fee2e2'],
            ['Medium', numberValue(counts.medium), '#d97706', '#fef3c7'],
            ['Low', numberValue(counts.low), '#15803d', '#dcfce7'],
            ['Other', numberValue(counts.other), '#64748b', '#e2e8f0'],
        ];
        const visible = all.filter((entry) => entry[1] > 0);
        const total = visible.reduce((sum, entry) => sum + entry[1], 0);
        if (state.priorityChart) state.priorityChart.destroy();
        state.priorityChart = null;
        setChartState('incidentsPriorityState', total ? '' : 'No month-to-date priority data are available.', !total);
        if (!total) return;

        const theme = dashboardChartTheme();
        state.priorityChart = new Chart(canvas, {
            type: 'doughnut',
            data: {
                labels: visible.map((entry) => entry[0]),
                datasets: [{
                    label: 'Incidents by priority',
                    data: visible.map((entry) => entry[1]),
                    backgroundColor: visible.map((entry) => entry[3]),
                    borderColor: visible.map((entry) => entry[2]),
                    borderWidth: 2,
                    hoverOffset: 9,
                    spacing: 3,
                    borderRadius: 8,
                }],
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '58%',
                plugins: {
                    legend: {
                        position: 'bottom',
                        labels: { color: theme.text, usePointStyle: true, padding: 16 },
                    },
                    tooltip: {
                        backgroundColor: theme.tooltipBg,
                        borderColor: theme.tooltipBorder,
                        borderWidth: 1,
                        titleColor: theme.tooltipText,
                        bodyColor: theme.tooltipText,
                        callbacks: {
                            label(context) {
                                const value = numberValue(context.raw);
                                const percentage = total ? ((value / total) * 100).toFixed(1).replace(/\.0$/, '') : '0';
                                return `${context.label}: ${value} (${percentage}%)`;
                            },
                        },
                    },
                },
            },
            plugins: [doughnutCenterPlugin],
        });
    }

    async function refreshSummary() {
        if (state.summaryInFlight) return;
        state.summaryInFlight = true;
        try {
            const payload = await fetchJson(config.summaryEndpoint || 'api/admin_dashboard_summary.php');
            state.summary = payload;
            const metrics = payload.metrics || {};
            setText('metricOpenIncidents', formatNumber(metrics.open_incidents));
            setText('metricActiveAccounts', formatNumber(metrics.active_accounts ?? metrics.active_users));
            setText('metricPartnerAgencies', formatNumber(metrics.partner_agencies));
            setText('metricRegisteredUnits', formatNumber(metrics.registered_units ?? metrics.resource_records));
            setText('metricMonthlyIncidents', formatNumber(metrics.monthly_incidents));

            const scopeLabel = payload.scope?.label || 'Current month';
            setText('metricMonthScope', `Incidents created during ${scopeLabel.toLowerCase()}.`);
            setText('incidentTypeScope', `${scopeLabel}; categories sum to the monthly incident count.`);
            setText('incidentPriorityScope', `${scopeLabel}; critical priority remains a separate category.`);
            setText('dashboardGeneratedAt', `Data refreshed ${formatDateTime(payload.generated_at)}`);

            renderTypeChart(payload);
            renderPriorityChart(payload);
        } catch (error) {
            console.error('Dashboard summary refresh failed', error);
            setText('dashboardGeneratedAt', 'Dashboard data unavailable');
            setChartState('incidentsTypeState', 'Unable to load incident type data.', true);
            setChartState('incidentsPriorityState', 'Unable to load incident priority data.', true);
            throw error;
        } finally {
            state.summaryInFlight = false;
        }
    }

    const weatherThemeClasses = [
        'weather-loading',
        'weather-clear',
        'weather-cloudy',
        'weather-rain',
        'weather-storm',
        'weather-mist',
        'weather-unavailable',
    ];

    const formatCelsius = (value) => Number.isFinite(Number(value)) ? `${Number(value).toFixed(1).replace(/\.0$/, '')}°C` : '—';
    const formatPercent = (value) => Number.isFinite(Number(value)) ? `${Math.round(Number(value))}%` : '—';

    function renderWeather(payload) {
        state.weather = payload;
        const widget = byId('weatherWidget');
        if (!widget) return;
        weatherThemeClasses.forEach((className) => widget.classList.remove(className));

        if (!payload || payload.ok === false || !payload.observation) {
            widget.classList.add('weather-unavailable');
            setText('weatherLocation', payload?.location || 'Quezon City Command Center');
            setText('weatherCondition', 'Live weather unavailable');
            setText('weatherTemperature', '—°C');
            setText('weatherHeadline', payload?.error || 'The weather provider could not be reached.');
            setText('weatherUpdated', 'No verified observation available');
            setText('weatherStatus', 'Unavailable');
            setText('weatherFeelsLike', '—');
            setText('weatherRainChance', '—');
            setText('weatherRange', '—');
            setText('weatherHumidity', '—');
            setText('weatherWind', '—');
            setText('weatherVisibility', '—');
            setText('weatherSource', `Source: ${payload?.provider || 'Open-Meteo'}`);
            const icon = byId('weatherIcon');
            if (icon) icon.className = 'fa-solid fa-triangle-exclamation';
            return;
        }

        const observation = payload.observation || {};
        const nextHour = payload.next_hour || {};
        const today = payload.today || {};
        const theme = observation.theme || 'weather-cloudy';
        widget.classList.add(theme);

        setText('weatherLocation', payload.location || 'Quezon City Command Center');
        setText('weatherCondition', observation.condition || 'Condition unavailable');
        setText('weatherTemperature', formatCelsius(observation.temperature_c));

        const nextRain = Number.isFinite(Number(nextHour.rain_probability_pct))
            ? ` • ${formatPercent(nextHour.rain_probability_pct)} rain chance`
            : '';
        setText(
            'weatherHeadline',
            `Next hour ${nextHour.label || ''}: ${nextHour.condition || 'forecast unavailable'}${nextRain}`.trim()
        );
        setText('weatherUpdated', `Observation: ${formatDateTime(observation.time)}`);
        setText(
            'weatherStatus',
            payload.stale ? 'Cached observation' : (payload.cache_state === 'fresh-cache' ? 'Updated within 5 min' : 'Live exact coordinates')
        );
        setText('weatherFeelsLike', formatCelsius(observation.apparent_temperature_c));
        setText('weatherRainChance', formatPercent(observation.rain_probability_pct ?? today.max_rain_probability_pct));
        const range = Number.isFinite(Number(today.high_c)) && Number.isFinite(Number(today.low_c))
            ? `${formatCelsius(today.high_c)} / ${formatCelsius(today.low_c)}`
            : '—';
        setText('weatherRange', range);
        setText('weatherHumidity', formatPercent(observation.humidity_pct));

        const windSpeed = Number.isFinite(Number(observation.wind_kmh))
            ? `${Number(observation.wind_kmh).toFixed(1).replace(/\.0$/, '')} km/h`
            : '—';
        const windDirection = observation.wind_direction ? ` ${observation.wind_direction}` : '';
        setText('weatherWind', `${windSpeed}${windDirection}`);
        setText(
            'weatherVisibility',
            Number.isFinite(Number(observation.visibility_km))
                ? `${Number(observation.visibility_km).toFixed(1).replace(/\.0$/, '')} km`
                : '—'
        );
        const coordinates = payload.coordinates || {};
        const coordinateLabel = Number.isFinite(Number(coordinates.latitude)) && Number.isFinite(Number(coordinates.longitude))
            ? ` • ${Number(coordinates.latitude).toFixed(4)}, ${Number(coordinates.longitude).toFixed(4)}`
            : '';
        setText('weatherSource', `Source: ${payload.provider || 'Open-Meteo'}${coordinateLabel}`);

        const icon = byId('weatherIcon');
        if (icon) icon.className = `fa-solid ${observation.icon || 'fa-cloud'}`;
    }

    async function refreshWeather(force = false) {
        if (state.weatherInFlight) return;
        state.weatherInFlight = true;
        const button = byId('weatherRefreshButton');
        if (button) button.disabled = true;
        try {
            const endpoint = config.weatherEndpoint || 'api/admin_dashboard_weather.php';
            const separator = endpoint.includes('?') ? '&' : '?';
            const payload = await fetchJson(force ? `${endpoint}${separator}refresh=1` : endpoint);
            renderWeather(payload);
        } catch (error) {
            console.error('Weather refresh failed', error);
            renderWeather({ ok: false, error: error.message, location: 'Quezon City Command Center', provider: 'Open-Meteo' });
            if (force) showToast('Live weather could not be refreshed.', 'error');
            throw error;
        } finally {
            state.weatherInFlight = false;
            if (button) button.disabled = false;
        }
    }

    const actionLabels = {
        login: 'Signed in',
        logout: 'Signed out',
        responder_login: 'Responder signed in',
        responder_logout: 'Responder signed out',
        incident_created: 'Incident created',
        dispatch_confirmed: 'Response units dispatched',
        incident_resolved: 'Incident completed',
        after_action_report_submitted: 'After-action report submitted',
        after_action_report_approved: 'After-action report approved',
        after_action_report_returned: 'After-action report returned',
    };

    function readableAction(value) {
        const key = String(value || '').trim().toLowerCase();
        if (actionLabels[key]) return actionLabels[key];
        return key
            ? key.replace(/[_-]+/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase())
            : 'System activity';
    }

    function activityIcon(action) {
        const key = String(action || '').toLowerCase();
        if (key.includes('login')) return 'fa-right-to-bracket';
        if (key.includes('logout')) return 'fa-right-from-bracket';
        if (key.includes('incident')) return 'fa-triangle-exclamation';
        if (key.includes('dispatch')) return 'fa-truck-medical';
        if (key.includes('report')) return 'fa-file-circle-check';
        return 'fa-clock-rotate-left';
    }

    function activityHtml(item) {
        const actor = item.username || (item.user_id ? `User #${item.user_id}` : 'System');
        const details = String(item.details || '').trim();
        return `
            <article class="activity-item">
                <span class="activity-icon"><i class="fas ${activityIcon(item.action)}" aria-hidden="true"></i></span>
                <div class="activity-content">
                    <div class="activity-title">${escapeHtml(readableAction(item.action))}</div>
                    <div class="activity-details">${escapeHtml(actor)}${details ? ` • ${escapeHtml(details)}` : ''}</div>
                    <time class="activity-time" datetime="${escapeHtml(item.created_at || '')}">${escapeHtml(formatTimeAgo(item.created_at))}</time>
                </div>
            </article>`;
    }

    function renderActivity(items, targetId) {
        const target = byId(targetId);
        if (!target) return;
        if (!Array.isArray(items) || items.length === 0) {
            target.innerHTML = '<div class="dashboard-empty-state"><i class="far fa-circle-check" aria-hidden="true"></i>No recent activity was recorded.</div>';
            return;
        }
        target.innerHTML = items.map(activityHtml).join('');
    }

    async function refreshActivity(all = false, targetId = 'activityFeedList') {
        if (state.activityInFlight) return;
        state.activityInFlight = true;
        try {
            const endpoint = config.activityEndpoint || 'api/activity_feed.php';
            const payload = await fetchJson(`${endpoint}${all ? '?all=1' : ''}`);
            renderActivity(payload.data || [], targetId);
        } catch (error) {
            const target = byId(targetId);
            if (target) target.innerHTML = '<div class="dashboard-empty-state error">Unable to load recent activity.</div>';
            throw error;
        } finally {
            state.activityInFlight = false;
        }
    }

    function alertIcon(type) {
        if (type === 'critical') return 'fa-circle-exclamation';
        if (type === 'warning') return 'fa-triangle-exclamation';
        return 'fa-circle-info';
    }

    function alertHtml(item) {
        const type = ['critical', 'warning', 'info'].includes(String(item.type || '').toLowerCase())
            ? String(item.type).toLowerCase()
            : 'info';
        return `
            <article class="alert-item ${type}">
                <span class="alert-icon"><i class="fas ${alertIcon(type)}" aria-hidden="true"></i></span>
                <div class="alert-content">
                    <div class="alert-title">${escapeHtml(item.title || 'Operational alert')}</div>
                    <div class="alert-details">${escapeHtml(item.details || '')}</div>
                    ${item.created_at ? `<time class="alert-time">${escapeHtml(formatTimeAgo(item.created_at))}</time>` : ''}
                </div>
            </article>`;
    }

    function renderAlerts(items, targetId) {
        const target = byId(targetId);
        if (!target) return;
        if (!Array.isArray(items) || items.length === 0) {
            target.innerHTML = '<div class="dashboard-empty-state success"><i class="far fa-circle-check" aria-hidden="true"></i>No active operational alerts.</div>';
            return;
        }
        target.innerHTML = items.map(alertHtml).join('');
    }

    async function refreshAlerts(all = false, targetId = 'alertsPanelList') {
        if (state.alertsInFlight) return;
        state.alertsInFlight = true;
        try {
            const endpoint = config.alertsEndpoint || 'api/alerts_active.php';
            const payload = await fetchJson(`${endpoint}${all ? '?all=1' : ''}`);
            renderAlerts(payload.data || [], targetId);
        } catch (error) {
            const target = byId(targetId);
            if (target) target.innerHTML = '<div class="dashboard-empty-state error">Unable to load active alerts.</div>';
            throw error;
        } finally {
            state.alertsInFlight = false;
        }
    }

    function openModal(id) {
        const modal = byId(id);
        if (!modal) return;
        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        document.documentElement.classList.add('dashboard-modal-open');
        const closeButton = modal.querySelector('[data-modal-close]');
        if (closeButton instanceof HTMLElement) closeButton.focus();
    }

    function closeModal(id) {
        const modal = byId(id);
        if (!modal) return;
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        if (!document.querySelector('.dashboard-modal:not([hidden])')) {
            document.documentElement.classList.remove('dashboard-modal-open');
        }
    }

    function localDateInput(date) {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    }

    function openTrends() {
        const end = new Date();
        const start = new Date();
        start.setDate(end.getDate() - 29);
        const startInput = byId('trendStart');
        const endInput = byId('trendEnd');
        if (startInput) startInput.value = localDateInput(start);
        if (endInput) endInput.value = localDateInput(end);
        openModal('trendModal');
        loadTrendData();
    }

    async function loadTrendData() {
        const start = byId('trendStart')?.value || '';
        const end = byId('trendEnd')?.value || '';
        if (!start || !end || start > end) {
            setChartState('trendState', 'Choose a valid date range.', true);
            return;
        }
        setChartState('trendState', 'Loading daily incident trend…', true);
        try {
            const endpoint = config.trendEndpoint || 'api/trend_data.php';
            const payload = await fetchJson(`${endpoint}?start=${encodeURIComponent(start)}&end=${encodeURIComponent(end)}`);
            const labels = Array.isArray(payload.labels) ? payload.labels : [];
            const values = Array.isArray(payload.values) ? payload.values.map((value) => numberValue(value)) : [];
            if (state.trendChart) state.trendChart.destroy();
            state.trendChart = null;
            if (!labels.length || !values.some((value) => value > 0)) {
                setChartState('trendState', 'No incidents were recorded in this range.', true);
                return;
            }
            setChartState('trendState', '', false);
            const canvas = byId('trendChart');
            if (!canvas || typeof Chart === 'undefined') return;
            const theme = dashboardChartTheme();
            state.trendChart = new Chart(canvas, {
                type: 'line',
                data: {
                    labels,
                    datasets: [{
                        label: 'Incidents created',
                        data: values,
                        borderColor: '#0f766e',
                        backgroundColor: 'rgba(15,118,110,.14)',
                        fill: true,
                        tension: 0.3,
                        pointRadius: 3,
                        pointHoverRadius: 5,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    plugins: {
                        legend: { labels: { color: theme.text } },
                        tooltip: {
                            backgroundColor: theme.tooltipBg,
                            borderColor: theme.tooltipBorder,
                            borderWidth: 1,
                            titleColor: theme.tooltipText,
                            bodyColor: theme.tooltipText,
                        },
                    },
                    scales: {
                        x: { ticks: { color: theme.muted }, grid: { color: theme.grid } },
                        y: { beginAtZero: true, ticks: { precision: 0, stepSize: 1, color: theme.muted }, grid: { color: theme.grid } },
                    },
                },
            });
        } catch (error) {
            setChartState('trendState', 'Unable to load trend data.', true);
        }
    }

    async function refreshAll({ forceWeather = false, notify = false } = {}) {
        if (state.refreshInFlight) return;
        state.refreshInFlight = true;
        const button = byId('dashboardRefreshButton');
        if (button) {
            button.disabled = true;
            button.classList.add('loading');
        }
        const results = await Promise.allSettled([
            refreshSummary(),
            refreshWeather(forceWeather),
            refreshActivity(false, 'activityFeedList'),
            refreshAlerts(false, 'alertsPanelList'),
        ]);
        state.refreshInFlight = false;
        if (button) {
            button.disabled = false;
            button.classList.remove('loading');
        }
        if (notify) {
            const failed = results.filter((result) => result.status === 'rejected').length;
            showToast(failed ? `${failed} dashboard source${failed === 1 ? '' : 's'} could not be refreshed.` : 'Dashboard data refreshed.', failed ? 'warning' : 'success');
        }
    }

    function updateClock() {
        setText('dashboardLiveClock', clockFormatter.format(new Date()));
    }

    function bindEvents() {
        byId('dashboardRefreshButton')?.addEventListener('click', () => refreshAll({ forceWeather: true, notify: true }));
        byId('weatherRefreshButton')?.addEventListener('click', () => refreshWeather(true));
        byId('trendFilterForm')?.addEventListener('submit', (event) => {
            event.preventDefault();
            loadTrendData();
        });

        document.addEventListener('click', (event) => {
            const target = event.target;
            if (!(target instanceof Element)) return;
            const action = target.closest('[data-dashboard-action]')?.getAttribute('data-dashboard-action');
            if (action === 'open-trends') {
                openTrends();
            } else if (action === 'view-all-activity') {
                openModal('activityModal');
                refreshActivity(true, 'activityModalList');
            } else if (action === 'view-all-alerts') {
                openModal('alertsModal');
                refreshAlerts(true, 'alertsModalList');
            }

            const closeId = target.closest('[data-modal-close]')?.getAttribute('data-modal-close');
            if (closeId) closeModal(closeId);

            if (target.classList.contains('dashboard-modal')) {
                closeModal(target.id);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') return;
            const modal = document.querySelector('.dashboard-modal:not([hidden])');
            if (modal instanceof HTMLElement) closeModal(modal.id);
        });

        document.addEventListener('themeChanged', () => {
            if (state.summary) {
                renderTypeChart(state.summary);
                renderPriorityChart(state.summary);
            }
            if (state.trendChart) loadTrendData();
        });
    }

    document.addEventListener('DOMContentLoaded', () => {
        bindEvents();
        updateClock();
        window.setInterval(updateClock, 1000);
        refreshAll();

        window.setInterval(() => {
            if (!document.hidden) refreshSummary();
        }, 30000);
        window.setInterval(() => {
            if (!document.hidden) {
                refreshActivity(false, 'activityFeedList');
                refreshAlerts(false, 'alertsPanelList');
            }
        }, 30000);
        window.setInterval(() => {
            if (!document.hidden) refreshWeather(false);
        }, 300000);
    });
})();
