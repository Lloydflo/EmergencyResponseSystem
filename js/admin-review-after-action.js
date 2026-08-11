(() => {
    'use strict';

    const qs = (selector, context = document) => context.querySelector(selector);
    const qsa = (selector, context = document) => Array.from(context.querySelectorAll(selector));

    const configNode = qs('#adminReviewConfig');
    const adminUserId = Number(configNode?.dataset.adminUserId || 0);

    const tableBody = qs('#incidentTableBody');
    const countBadge = qs('#incidentCountBadge');
    const tableSubtitle = qs('#tableSubtitle');
    const searchFilterInput = qs('#searchFilterInput');
    const categoryFilterSelect = qs('#categoryFilterSelect');
    const statusFilterSelect = qs('#statusFilterSelect');
    const resetFilterBtn = qs('#resetFilterBtn');
    const refreshReviewBtn = qs('#refreshReviewBtn');
    const queueShortcuts = qsa('[data-queue-shortcut]');
    const modal = qs('#adminFeedbackModal');
    const modalOverlay = qs('#adminFeedbackOverlay');
    const modalClose = qs('#adminFeedbackClose');
    const modalDialog = qs('.ar-modal-dialog', modal);
    const modalExpand = qs('#adminFeedbackExpand');
    const afterActionList = qs('#adminAfterActionList');

    if (
        !tableBody
        || !countBadge
        || !tableSubtitle
        || !searchFilterInput
        || !categoryFilterSelect
        || !statusFilterSelect
        || !resetFilterBtn
        || !refreshReviewBtn
        || !modal
        || !modalOverlay
        || !modalClose
        || !afterActionList
    ) {
        return;
    }

    let incidentRows = [];
    let allReports = [];
    let reportById = new Map();
    let currentIncidentId = null;
    let queueRequestSerial = 0;
    let modalRequestSerial = 0;
    let modalReturnTarget = null;
    const modalBackgroundState = new Map();

    const PH_TIME_ZONE = 'Asia/Manila';
    const PH_DATE_FORMATTER = new Intl.DateTimeFormat('en-PH', {
        timeZone: PH_TIME_ZONE,
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
        timeZoneName: 'short',
    });

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function toNumber(value) {
        if (value === null || value === undefined || value === '') {
            return null;
        }
        const number = Number(value);
        return Number.isFinite(number) ? number : null;
    }

    function parseDateValue(value) {
        const raw = String(value || '').trim();
        if (!raw) {
            return new Date(NaN);
        }

        // Database DATETIME fields are stored as Philippine local wall time.
        // A zone-less value must therefore use +08:00, not UTC (`Z`).
        const localDateTime = raw.match(
            /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?$/
        );
        if (localDateTime) {
            const [, year, month, day, hour, minute, second = '00', fraction = ''] = localDateTime;
            const milliseconds = (fraction + '000').slice(0, 3);
            return new Date(`${year}-${month}-${day}T${hour}:${minute}:${second}.${milliseconds}+08:00`);
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
            return new Date(`${raw}T00:00:00.000+08:00`);
        }

        return new Date(raw);
    }

    function formatDate(value, millisecondValue = 0) {
        const milliseconds = Number(millisecondValue || 0);
        const date = milliseconds > 0 ? new Date(milliseconds) : parseDateValue(value);
        return Number.isNaN(date.getTime()) ? (String(value || '--')) : PH_DATE_FORMATTER.format(date);
    }

    function formatMinutes(value) {
        const minutes = toNumber(value);
        if (minutes === null) {
            return '--';
        }
        if (minutes < 60) {
            return Math.round(minutes) + ' min';
        }
        const hours = Math.floor(minutes / 60);
        const remaining = Math.round(minutes % 60);
        return remaining ? (hours + 'h ' + remaining + 'm') : (hours + 'h');
    }

    function normalizeIncidentStatus(status) {
        const value = String(status || '').toLowerCase();
        return value === 'cancelled' || value === 'canceled' ? 'cancelled' : 'resolved';
    }

    function normalizePriority(priority) {
        const value = String(priority || '').toLowerCase();
        if (value === 'critical') return 'critical';
        if (value === 'high') return 'high';
        if (value === 'medium') return 'medium';
        return 'low';
    }

    function incidentTypeTokens(value) {
        const aliases = {
            ambulance: 'medical',
            medic: 'medical',
            accident: 'traffic',
            collision: 'traffic',
            crime: 'police',
        };
        return [...new Set(String(value || '')
            .toLowerCase()
            .split(/[,|\/]+/)
            .map((item) => item.trim())
            .filter(Boolean)
            .map((item) => aliases[item] || item))];
    }

    function workflowStatus(report) {
        const workflow = String(report?.workflow_status || '').toLowerCase();
        if (['submitted', 'approved', 'revision_required', 'pending'].includes(workflow)) {
            return workflow;
        }

        const legacy = String(report?.status || '').toLowerCase();
        if (legacy === 'submitted') return 'submitted';
        if (legacy === 'verified' || legacy === 'approved') return 'approved';
        if (legacy === 'returned' || legacy === 'rejected') return 'revision_required';
        return 'pending';
    }

    function reportStatusLabel(reportOrStatus) {
        const status = typeof reportOrStatus === 'string'
            ? reportOrStatus
            : workflowStatus(reportOrStatus);
        switch (status) {
            case 'submitted': return 'Awaiting Review';
            case 'approved': return 'Approved';
            case 'revision_required': return 'Needs Revision';
            default: return 'Draft';
        }
    }

    function reportStatusClass(reportOrStatus) {
        const status = typeof reportOrStatus === 'string'
            ? reportOrStatus
            : workflowStatus(reportOrStatus);
        switch (status) {
            case 'submitted': return 'submitted';
            case 'approved': return 'approved';
            case 'revision_required': return 'revision';
            default: return 'pending';
        }
    }

    function reportStatusPill(reportOrStatus, icon = true) {
        const status = typeof reportOrStatus === 'string'
            ? reportOrStatus
            : workflowStatus(reportOrStatus);
        const iconClass = status === 'submitted'
            ? 'fa-hourglass-half'
            : status === 'approved'
                ? 'fa-circle-check'
                : status === 'revision_required'
                    ? 'fa-rotate-left'
                    : 'fa-file-pen';
        return '<span class="ar-report-status ' + reportStatusClass(status) + '">'
            + (icon ? '<i class="fas ' + iconClass + '" aria-hidden="true"></i>' : '')
            + escapeHtml(reportStatusLabel(status))
            + '</span>';
    }

    function statusChip(status) {
        const safe = normalizeIncidentStatus(status);
        const label = safe === 'cancelled' ? 'Cancelled' : 'Resolved';
        return '<span class="ar-chip ' + safe + '">' + escapeHtml(label) + '</span>';
    }

    function priorityChip(priority) {
        const safe = normalizePriority(priority);
        const label = safe.charAt(0).toUpperCase() + safe.slice(1) + ' Priority';
        return '<span class="ar-pill ' + safe + '">' + escapeHtml(label) + '</span>';
    }

    function reportDateValue(report) {
        const status = workflowStatus(report);
        if (status === 'approved' || status === 'revision_required') {
            return {
                value: report.reviewed_at || report.updated_at || report.submitted_at,
                milliseconds: report.reviewed_at_ms || report.updated_at_ms || 0,
                label: status === 'approved' ? 'Reviewed' : 'Returned',
            };
        }
        return {
            value: report.submitted_at || report.updated_at || report.created_at,
            milliseconds: report.updated_at_ms || report.created_at_ms || 0,
            label: 'Submitted',
        };
    }

    function reportTimestamp(report) {
        const value = reportDateValue(report);
        const milliseconds = Number(value.milliseconds || 0);
        if (milliseconds > 0) {
            return milliseconds;
        }
        const parsed = parseDateValue(value.value);
        return Number.isNaN(parsed.getTime()) ? 0 : parsed.getTime();
    }

    function latestReport(reports) {
        return [...reports].sort((left, right) => reportTimestamp(right) - reportTimestamp(left))[0] || null;
    }

    function isCrimeAnalyticsCandidate(row) {
        if (normalizeIncidentStatus(row.status) !== 'resolved') return false;
        const haystack = [row.type, row.title, row.description].join(' ').toLowerCase();
        return /\b(police|crime|robbery|theft|fraud|assault|homicide|violence|weapon|gun|knife|patalim|riot)\b/.test(haystack);
    }

    function crimeAnalyticsAction(row) {
        if (!isCrimeAnalyticsCandidate(row)) return '';
        const status = String(row.crime_analytics_status || '').toLowerCase();
        if (status === 'sent') {
            const sentAt = row.crime_analytics_synced_at
                ? ' title="Sent ' + escapeHtml(formatDate(row.crime_analytics_synced_at)) + '"'
                : '';
            return '<button type="button" class="ar-action sent" disabled' + sentAt + '><i class="fas fa-circle-check"></i> Crime Sent</button>';
        }
        if (status === 'failed') {
            return '<button type="button" class="ar-action danger" data-action="send-crime-analytics" data-id="' + escapeHtml(row.id) + '"><i class="fas fa-rotate"></i> Retry Crime</button>';
        }
        return '<button type="button" class="ar-action sync" data-action="send-crime-analytics" data-id="' + escapeHtml(row.id) + '"><i class="fas fa-share-from-square"></i> Send Crime</button>';
    }

    async function readJsonResponse(response) {
        const raw = await response.text();
        let payload = {};
        if (raw.trim() !== '') {
            try {
                payload = JSON.parse(raw);
            } catch (error) {
                throw new Error('The server returned an invalid response.');
            }
        }
        if (!response.ok) {
            throw new Error(payload.error || payload.message || ('Request failed with HTTP ' + response.status + '.'));
        }
        return payload;
    }

    function payloadSucceeded(payload) {
        return payload?.ok === true || payload?.success === true;
    }

    async function readOptionalJsonResponse(response) {
        try {
            const raw = await response.text();
            if (raw.trim() === '') {
                return { ok: false, error: 'Empty response' };
            }
            const payload = JSON.parse(raw);
            return response.ok ? payload : { ...payload, ok: false, success: false };
        } catch (error) {
            return { ok: false, success: false, error: 'Optional data is unavailable.' };
        }
    }

    function notify(message, tone = 'info') {
        const existing = qs('.ar-toast');
        if (existing) existing.remove();
        const toast = document.createElement('div');
        toast.className = 'ar-toast ' + tone;
        toast.setAttribute('role', tone === 'error' ? 'alert' : 'status');
        toast.textContent = message;
        document.body.appendChild(toast);
        window.setTimeout(() => {
            toast.classList.add('leaving');
            window.setTimeout(() => toast.remove(), 220);
        }, 3200);
    }

    function buildReportMap(reports) {
        const map = new Map();
        reports.forEach((report) => {
            const incidentId = Number(report.incident_id || report.incident?.id || 0);
            if (!Number.isInteger(incidentId) || incidentId < 1) return;
            if (!map.has(incidentId)) map.set(incidentId, []);
            map.get(incidentId).push(report);
        });
        map.forEach((items) => items.sort((left, right) => reportTimestamp(right) - reportTimestamp(left)));
        return map;
    }

    function syntheticIncidentFromReport(report) {
        const incident = report.incident || {};
        return {
            id: Number(report.incident_id || incident.id || 0),
            incident_code: incident.reference_no || report.reference_no || ('Incident #' + (report.incident_id || '')),
            reference_no: incident.reference_no || report.reference_no || '',
            type: incident.type || report.incident_type || 'general',
            title: incident.title || '',
            description: incident.description || report.incident_summary || '',
            priority: incident.priority || 'low',
            status: incident.status || 'resolved',
            location: incident.location_address || report.location_address || '',
            location_address: incident.location_address || report.location_address || '',
            resolved_at: incident.completed_at || null,
            completed_at: incident.completed_at || null,
            updated_at: report.reviewed_at || report.submitted_at || null,
            feedback_count: 0,
            submitted_to_admin: true,
            assigned_unit: '',
            assigned_unit_identifier: '',
            driver_name: report.responder_name || '',
            vehicle_name: '',
            plate_number: '',
            response_time_min: null,
            resolution_time_min: null,
        };
    }

    function mergeIncidentsAndReports(incidents, reports) {
        const reportMap = buildReportMap(reports);
        const incidentMap = new Map();

        incidents.forEach((row) => {
            const incidentId = Number(row.id || 0);
            if (!Number.isInteger(incidentId) || incidentId < 1) return;
            incidentMap.set(incidentId, {
                ...row,
                after_action_reports: reportMap.get(incidentId) || [],
            });
        });

        reports.forEach((report) => {
            const incidentId = Number(report.incident_id || report.incident?.id || 0);
            if (!Number.isInteger(incidentId) || incidentId < 1 || incidentMap.has(incidentId)) return;
            incidentMap.set(incidentId, {
                ...syntheticIncidentFromReport(report),
                after_action_reports: reportMap.get(incidentId) || [report],
            });
        });

        const rows = Array.from(incidentMap.values()).filter((row) => {
            const reportsForIncident = row.after_action_reports || [];
            return reportsForIncident.length > 0
                || Boolean(row.submitted_to_admin)
                || Number(row.feedback_count || 0) > 0;
        });

        rows.sort((left, right) => {
            const leftPending = (left.after_action_reports || []).some((report) => workflowStatus(report) === 'submitted');
            const rightPending = (right.after_action_reports || []).some((report) => workflowStatus(report) === 'submitted');
            if (leftPending !== rightPending) return leftPending ? -1 : 1;

            const leftLatest = latestReport(left.after_action_reports || []);
            const rightLatest = latestReport(right.after_action_reports || []);
            const leftTime = leftLatest ? reportTimestamp(leftLatest) : parseDateValue(left.resolved_at || left.updated_at).getTime() || 0;
            const rightTime = rightLatest ? reportTimestamp(rightLatest) : parseDateValue(right.resolved_at || right.updated_at).getTime() || 0;
            return rightTime - leftTime;
        });

        return rows;
    }

    async function loadRows(options = {}) {
        const serial = ++queueRequestSerial;
        const showLoading = options.showLoading !== false;
        if (showLoading) {
            tableBody.setAttribute('aria-busy', 'true');
            tableBody.innerHTML = '<div class="ar-empty ar-queue-message"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i><strong>Preparing the review queue</strong><span>Loading responder reports and closed incidents...</span></div>';
            tableSubtitle.textContent = 'Loading responder after-action reports and closed incidents...';
        }

        if (!Number.isInteger(adminUserId) || adminUserId < 1) {
            tableBody.setAttribute('aria-busy', 'false');
            tableBody.innerHTML = '<div class="ar-empty ar-queue-message error"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i><strong>Review queue unavailable</strong><span>Admin account ID is unavailable. Sign in again before reviewing reports.</span></div>';
            return;
        }

        try {
            const reportUrl = 'api/api_app/get-after-action-reports.php?reviewer_id='
                + encodeURIComponent(adminUserId)
                + '&status=all&limit=200';
            const [incidentResponse, reportResponse] = await Promise.all([
                fetch('api/incidents_list.php?status=closed', { cache: 'no-store', credentials: 'same-origin' }),
                fetch(reportUrl, { cache: 'no-store', credentials: 'same-origin' }),
            ]);
            const [incidentPayload, reportPayload] = await Promise.all([
                readJsonResponse(incidentResponse),
                readJsonResponse(reportResponse),
            ]);
            if (!payloadSucceeded(incidentPayload)) {
                throw new Error(incidentPayload.error || incidentPayload.message || 'Unable to load incidents.');
            }
            if (!payloadSucceeded(reportPayload)) {
                throw new Error(reportPayload.error || reportPayload.message || 'Unable to load after-action reports.');
            }
            if (serial !== queueRequestSerial) return;

            allReports = (Array.isArray(reportPayload.reports) ? reportPayload.reports : [])
                .filter((report) => workflowStatus(report) !== 'pending');
            reportById = new Map(allReports.map((report) => [Number(report.id), report]));
            incidentRows = mergeIncidentsAndReports(
                Array.isArray(incidentPayload.items) ? incidentPayload.items : [],
                allReports,
            );
            renderStats();
            renderTable();
        } catch (error) {
            if (serial !== queueRequestSerial) return;
            tableBody.setAttribute('aria-busy', 'false');
            tableBody.innerHTML = '<div class="ar-empty ar-queue-message error"><i class="fas fa-cloud-arrow-down" aria-hidden="true"></i><strong>Unable to load the review queue</strong><span>' + escapeHtml(error.message || 'Failed to load after-action review queue.') + '</span><button type="button" class="ar-action primary" data-action="retry-queue"><i class="fas fa-rotate"></i> Try again</button></div>';
            tableSubtitle.textContent = 'Unable to load after-action reports at the moment.';
            notify(error.message || 'Failed to load review queue.', 'error');
        }
    }

    function renderStats() {
        const submitted = allReports.filter((report) => workflowStatus(report) === 'submitted').length;
        const approved = allReports.filter((report) => workflowStatus(report) === 'approved').length;
        const revision = allReports.filter((report) => workflowStatus(report) === 'revision_required').length;
        qs('#statReports').textContent = String(allReports.length);
        qs('#statPending').textContent = String(submitted);
        qs('#statApproved').textContent = String(approved);
        qs('#statRevision').textContent = String(revision);
    }

    function getFilteredRows() {
        const searchNeedle = String(searchFilterInput.value || '').trim().toLowerCase();
        const categoryNeedle = String(categoryFilterSelect.value || '').trim().toLowerCase();
        const queueNeedle = String(statusFilterSelect.value || '').trim().toLowerCase();

        return incidentRows.filter((row) => {
            const reports = Array.isArray(row.after_action_reports) ? row.after_action_reports : [];
            if (categoryNeedle && !incidentTypeTokens(row.type).includes(categoryNeedle)) return false;

            if (queueNeedle === 'submitted' && !reports.some((report) => workflowStatus(report) === 'submitted')) return false;
            if (queueNeedle === 'approved' && !reports.some((report) => workflowStatus(report) === 'approved')) return false;
            if (queueNeedle === 'revision_required' && !reports.some((report) => workflowStatus(report) === 'revision_required')) return false;
            if (queueNeedle === 'no_report' && reports.length > 0) return false;

            if (searchNeedle) {
                const reportText = reports.map((report) => [
                    report.responder_name,
                    report.operational_outcome,
                    report.incident_summary,
                    report.actions_taken,
                    report.reviewer_notes,
                ].join(' ')).join(' ');
                const haystack = [
                    row.incident_code,
                    row.reference_no,
                    row.type,
                    row.location,
                    row.location_address,
                    row.driver_name,
                    row.plate_number,
                    row.vehicle_name,
                    row.assigned_unit,
                    reportText,
                ].join(' ').toLowerCase();
                if (!haystack.includes(searchNeedle)) return false;
            }
            return true;
        });
    }

    function rowReviewState(row) {
        const reports = Array.isArray(row.after_action_reports) ? row.after_action_reports : [];
        if (reports.some((report) => workflowStatus(report) === 'submitted')) {
            return {
                key: 'submitted',
                label: 'Needs decision',
                icon: 'fa-inbox',
                description: 'Open the report, verify the evidence, then approve or return it.',
            };
        }
        if (reports.some((report) => workflowStatus(report) === 'revision_required')) {
            return {
                key: 'revision_required',
                label: 'Waiting on revision',
                icon: 'fa-rotate-left',
                description: 'The responder has been asked to correct and resubmit this report.',
            };
        }
        if (reports.some((report) => workflowStatus(report) === 'approved')) {
            return {
                key: 'approved',
                label: 'Review complete',
                icon: 'fa-circle-check',
                description: 'The submitted report has an approved admin decision.',
            };
        }
        return {
            key: 'no_report',
            label: 'No report submitted',
            icon: 'fa-file-circle-question',
            description: 'This closed incident has feedback or notes but no after-action report.',
        };
    }

    function reportActivity(row) {
        const reports = Array.isArray(row.after_action_reports) ? row.after_action_reports : [];
        const report = latestReport(reports);
        if (!report) {
            return { label: 'Report activity', value: 'Not submitted' };
        }
        const date = reportDateValue(report);
        return {
            label: date.label,
            value: formatDate(date.value, date.milliseconds),
        };
    }

    function renderCaseCard(row) {
        const reports = Array.isArray(row.after_action_reports) ? row.after_action_reports : [];
        const state = rowReviewState(row);
        const latest = latestReport(reports);
        const responders = [...new Set(reports
            .map((report) => String(report.responder_name || '').trim())
            .filter(Boolean))];
        const responderText = responders.length ? responders.join(', ') : (row.driver_name || 'Not recorded');
        const activity = reportActivity(row);
        const primaryLabel = state.key === 'submitted' ? 'Review now' : 'View case';
        const reportTitle = latest
            ? (latest.operational_outcome || latest.incident_summary || 'After-action report')
            : 'Incident feedback and operational notes';
        const reportPreview = latest
            ? (latest.incident_summary || latest.actions_taken || state.description)
            : state.description;
        const reference = row.incident_code || row.reference_no || ('Incident #' + row.id);
        const location = row.location || row.location_address || 'Location not recorded';
        const assignedUnit = row.assigned_unit || row.assigned_unit_identifier || 'No assigned unit';

        return `
            <article class="ar-case-card state-${escapeHtml(state.key)}">
                <div class="ar-case-status-bar" aria-hidden="true"></div>
                <header class="ar-case-head">
                    <div class="ar-case-identity">
                        <span class="ar-case-kicker"><i class="fas fa-hashtag" aria-hidden="true"></i> Incident</span>
                        <h3>${escapeHtml(reference)}</h3>
                        <p>${escapeHtml(row.type || 'General incident')} <span aria-hidden="true">&middot;</span> ${escapeHtml(assignedUnit)}</p>
                    </div>
                    <span class="ar-queue-state ${escapeHtml(state.key)}"><i class="fas ${escapeHtml(state.icon)}" aria-hidden="true"></i>${escapeHtml(state.label)}</span>
                </header>
                <div class="ar-case-content">
                    <div class="ar-case-summary">
                        <div class="ar-badges">${priorityChip(row.priority)} ${statusChip(row.status)}</div>
                        <h4>${escapeHtml(reportTitle)}</h4>
                        <p>${escapeHtml(reportPreview)}</p>
                        <span class="ar-case-location"><i class="fas fa-location-dot" aria-hidden="true"></i>${escapeHtml(location)}</span>
                    </div>
                    <dl class="ar-case-facts">
                        <div><dt><i class="fas fa-user-shield" aria-hidden="true"></i> Responder</dt><dd>${escapeHtml(responderText)}</dd></div>
                        <div><dt><i class="fas fa-file-lines" aria-hidden="true"></i> After-action</dt><dd>${escapeHtml(reports.length + (reports.length === 1 ? ' report' : ' reports'))}</dd></div>
                        <div><dt><i class="fas fa-stopwatch" aria-hidden="true"></i> Response time</dt><dd>${escapeHtml(formatMinutes(row.response_time_min))}</dd></div>
                        <div><dt><i class="fas fa-clock" aria-hidden="true"></i> ${escapeHtml(activity.label)}</dt><dd>${escapeHtml(activity.value)}</dd></div>
                    </dl>
                </div>
                <footer class="ar-case-footer">
                    <p><i class="fas ${escapeHtml(state.icon)}" aria-hidden="true"></i>${escapeHtml(state.description)}</p>
                    <div class="ar-row-actions">
                        <button type="button" class="ar-action primary" data-action="view-feedback" data-id="${escapeHtml(row.id)}"><i class="fas fa-arrow-right" aria-hidden="true"></i> ${escapeHtml(primaryLabel)}</button>
                        ${crimeAnalyticsAction(row)}
                    </div>
                </footer>
            </article>
        `;
    }

    function renderQueueSection(config, rows) {
        if (!rows.length) return '';
        return `
            <section class="ar-queue-section queue-${escapeHtml(config.key)}" aria-labelledby="queue-${escapeHtml(config.key)}-title">
                <div class="ar-queue-section-head">
                    <div>
                        <span class="ar-section-kicker"><i class="fas ${escapeHtml(config.icon)}" aria-hidden="true"></i>${escapeHtml(config.kicker)}</span>
                        <h3 id="queue-${escapeHtml(config.key)}-title">${escapeHtml(config.title)}</h3>
                        <p>${escapeHtml(config.description)}</p>
                    </div>
                    <span class="ar-queue-section-count">${escapeHtml(rows.length)}</span>
                </div>
                <div class="ar-case-grid">${rows.map(renderCaseCard).join('')}</div>
            </section>
        `;
    }

    function updateQueueShortcuts() {
        const activeQueue = String(statusFilterSelect.value || '');
        queueShortcuts.forEach((button) => {
            const isActive = String(button.dataset.queueShortcut || '') === activeQueue;
            button.classList.toggle('is-active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });
    }

    function renderTable() {
        const rows = getFilteredRows();
        tableBody.setAttribute('aria-busy', 'false');
        countBadge.textContent = rows.length + (rows.length === 1 ? ' case' : ' cases');
        tableSubtitle.textContent = rows.length
            ? 'Cases are grouped by the next action. Open a card to review the full report, notes, and proof.'
            : 'No after-action review case matched the current filter.';
        updateQueueShortcuts();

        if (!rows.length) {
            tableBody.innerHTML = '<div class="ar-empty ar-queue-message"><i class="fas fa-magnifying-glass" aria-hidden="true"></i><strong>No matching review cases</strong><span>Try a different search, category, or work queue.</span><button type="button" class="ar-action" data-action="clear-filters"><i class="fas fa-filter-circle-xmark"></i> Clear filters</button></div>';
            return;
        }

        const queueGroups = [
            {
                key: 'submitted',
                kicker: 'Action required',
                title: 'Needs your decision',
                description: 'Verify these new responder submissions and record an admin decision.',
                icon: 'fa-inbox',
            },
            {
                key: 'revision_required',
                kicker: 'Follow-up',
                title: 'Waiting on responder revision',
                description: 'These reports were returned with guidance and are waiting for resubmission.',
                icon: 'fa-rotate-left',
            },
            {
                key: 'approved',
                kicker: 'Completed',
                title: 'Approved reviews',
                description: 'Review decisions completed by the admin team.',
                icon: 'fa-circle-check',
            },
            {
                key: 'no_report',
                kicker: 'For visibility',
                title: 'Feedback without a report',
                description: 'Closed incidents with notes or feedback but no submitted after-action report.',
                icon: 'fa-file-circle-question',
            },
        ];

        tableBody.innerHTML = queueGroups
            .map((group) => renderQueueSection(
                group,
                rows.filter((row) => rowReviewState(row).key === group.key),
            ))
            .join('');
    }

    function setModalLoading() {
        qs('#adminFeedbackTitle').textContent = 'After-Action Review Details';
        qs('#adminModalCode').textContent = '--';
        qs('#adminModalType').textContent = '--';
        qs('#adminModalDescription').textContent = '--';
        qs('#adminModalLocation').textContent = '--';
        qs('#adminModalClosed').textContent = 'Closed: --';
        qs('#adminModalDispatch').textContent = '--';
        qs('#adminModalOnScene').textContent = '--';
        qs('#adminModalResponse').textContent = '--';
        qs('#adminModalResolution').textContent = '--';
        qs('#adminModalUnit').textContent = '--';
        qs('#adminModalDriver').textContent = '--';
        qs('#adminModalVehicle').textContent = '--';
        qs('#adminModalPlate').textContent = '--';
        qs('#adminModalReportCount').textContent = '0';
        qs('#adminModalPendingCount').textContent = '0';
        qs('#adminModalApprovedCount').textContent = '0';
        qs('#adminModalLastUpdated').textContent = '--';
        qs('#adminModalBadges').innerHTML = '';
        afterActionList.innerHTML = '<div class="ar-feedback-empty">Loading responder after-action reports...</div>';
        qs('#adminFeedbackList').innerHTML = '<div class="ar-feedback-empty">Loading operational notes...</div>';
        qs('#adminProofGallery').innerHTML = '<div class="ar-feedback-empty">Loading responder proof images...</div>';
    }

    function normalizeProofUrl(value) {
        const raw = String(value || '').trim();
        if (!raw) return '';
        if (/[\u0000-\u001f\u007f\\]/.test(raw) || /^\/\//.test(raw)) return '';

        if (/^https?:\/\//i.test(raw)) {
            try {
                const absoluteUrl = new URL(raw);
                return ['http:', 'https:'].includes(absoluteUrl.protocol) ? absoluteUrl.href : '';
            } catch (error) {
                return '';
            }
        }

        // Reject executable/opaque schemes and only accept proof locations created by this app.
        if (/^[a-z][a-z0-9+.-]*:/i.test(raw)) return '';
        const relativeUrl = raw.replace(/^\/+/, '');
        const pathOnly = relativeUrl.split(/[?#]/, 1)[0];
        if (pathOnly.split('/').some((segment) => segment === '..')) return '';
        if (!/^(?:images\/proofs|uploads\/completion_proof)\/[A-Za-z0-9._~!$&'()+,;=@%/-]+$/.test(pathOnly)) {
            return '';
        }
        return relativeUrl;
    }

    function renderProofs(proofPayload) {
        const gallery = qs('#adminProofGallery');
        const items = payloadSucceeded(proofPayload) && Array.isArray(proofPayload.items) ? proofPayload.items : [];
        if (!payloadSucceeded(proofPayload)) {
            gallery.innerHTML = '<div class="ar-feedback-empty">Unable to load responder proof images.</div>';
            return;
        }
        if (!items.length) {
            gallery.innerHTML = '<div class="ar-feedback-empty">No responder proof image was uploaded for this incident.</div>';
            return;
        }
        gallery.innerHTML = items.map((item) => {
            const source = item.source === 'responder_completion'
                ? 'Responder completion upload'
                : 'Resolution proof upload';
            const normalizedUrl = normalizeProofUrl(item.url || '');
            if (!normalizedUrl) {
                return `
                    <figure class="ar-proof-card ar-proof-unavailable">
                        <div class="ar-proof-placeholder"><i class="fas fa-image" aria-hidden="true"></i><span>Proof image unavailable</span></div>
                        <figcaption>
                            <strong>${escapeHtml(source)}</strong>
                            <span>The stored image link is not valid.</span>
                        </figcaption>
                    </figure>
                `;
            }
            const proofUrl = escapeHtml(normalizedUrl);
            return `
                <figure class="ar-proof-card">
                    <a href="${proofUrl}" target="_blank" rel="noopener" title="Open full proof image">
                        <img src="${proofUrl}" alt="Responder resolution proof">
                    </a>
                    <figcaption>
                        <strong>${escapeHtml(source)}</strong>
                        <span>${escapeHtml(formatDate(item.created_at))}</span>
                    </figcaption>
                </figure>
            `;
        }).join('');
    }

    function stripRatingFragments(value) {
        return String(value || '')
            .replace(/(?:^|\s*\|\s*)(?:response rating|rating submitted|response|communication|professionalism):\s*[1-5](?:\.\d+)?\s*(?:\/\s*5)?/gi, '')
            .replace(/(?:^|\s*\|\s*)score:\s*\d+(?:\.\d+)?/gi, '')
            .replace(/^\s*\|\s*|\s*\|\s*$/g, '')
            .replace(/\s*\|\s*\|\s*/g, ' | ')
            .trim();
    }

    function renderOperationalNotes(feedbackPayload) {
        const list = qs('#adminFeedbackList');
        const notes = payloadSucceeded(feedbackPayload) && Array.isArray(feedbackPayload.data)
            ? feedbackPayload.data
            : [];

        const visibleNotes = notes
            .filter((note) => String(note.source || '') !== 'after_action_report')
            .map((note) => ({ ...note, clean_note: stripRatingFragments(note.note) }))
            .filter((note) => note.clean_note !== '');

        if (!visibleNotes.length) {
            list.innerHTML = '<div class="ar-feedback-empty">No separate operational note was recorded for this incident.</div>';
            return;
        }

        list.innerHTML = visibleNotes.map((note) => `
            <div class="ar-feedback">
                <div class="ar-feedback-head">
                    <div><strong>${escapeHtml(note.author_name || 'Operations')}</strong><span>${escapeHtml(formatDate(note.created_at))}</span></div>
                    <span class="ar-note-source">${escapeHtml(noteSourceLabel(note.source))}</span>
                </div>
                <p class="ar-note">${escapeHtml(note.clean_note)}</p>
            </div>
        `).join('');
    }

    function noteSourceLabel(source) {
        switch (String(source || '')) {
            case 'dispatcher_feedback': return 'Dispatcher note';
            case 'responder_completion': return 'Completion note';
            case 'responder_review': return 'Responder note';
            case 'survey': return 'Survey entry';
            default: return 'Operational note';
        }
    }

    function reportField(label, value, options = {}) {
        const content = String(value ?? '').trim();
        const cssClass = options.wide ? ' ar-report-field-wide' : '';
        return '<div class="ar-report-field' + cssClass + '"><span>' + escapeHtml(label) + '</span><strong>'
            + escapeHtml(content !== '' ? content : 'Not recorded')
            + '</strong></div>';
    }

    function reportReviewOutcome(report) {
        const status = workflowStatus(report);
        if (status === 'submitted') return '';
        const reviewedAt = formatDate(report.reviewed_at, report.reviewed_at_ms);
        const notes = String(report.reviewer_notes || '').trim();
        const message = status === 'approved'
            ? 'This report is approved and is available in the responder Approved tab.'
            : 'This report was returned to the responder for revision.';
        return `
            <div class="ar-review-outcome ${reportStatusClass(status)}">
                <div>${reportStatusPill(status)}<span>${escapeHtml(reviewedAt)}</span></div>
                <p>${escapeHtml(message)}</p>
                ${notes ? '<blockquote><strong>Admin note</strong><span>' + escapeHtml(notes) + '</span></blockquote>' : ''}
            </div>
        `;
    }

    function reportReviewControls(report) {
        if (workflowStatus(report) !== 'submitted') {
            return reportReviewOutcome(report);
        }
        if (adminUserId < 1) {
            return '<div class="ar-review-outcome revision"><p>Sign in again to review this report.</p></div>';
        }
        return `
            <div class="ar-review-box" data-report-review-box="${Number(report.id)}">
                <label for="reviewNotes-${Number(report.id)}">Admin review note</label>
                <textarea id="reviewNotes-${Number(report.id)}" data-review-notes-for="${Number(report.id)}" rows="3" placeholder="Optional for approval; required when returning for revision."></textarea>
                <div class="ar-review-actions">
                    <button type="button" class="ar-review-btn approve" data-report-action="approve" data-report-id="${Number(report.id)}"><i class="fas fa-check"></i> Approve Report</button>
                    <button type="button" class="ar-review-btn return" data-report-action="return" data-report-id="${Number(report.id)}"><i class="fas fa-rotate-left"></i> Return for Revision</button>
                </div>
                <p class="ar-review-help">Approval updates the database immediately and moves this report to the responder's Approved tab.</p>
                <div class="ar-review-message" data-review-message-for="${Number(report.id)}" aria-live="polite"></div>
            </div>
        `;
    }

    function renderReportCard(report) {
        const reportDate = reportDateValue(report);
        const followUp = Number(report.follow_up_required || 0) === 1
            ? (String(report.follow_up_details || '').trim() || 'Follow-up is required.')
            : 'No follow-up required';
        return `
            <article class="ar-report-card" data-report-card="${Number(report.id)}">
                <header class="ar-report-head">
                    <div>
                        <span class="ar-report-kicker">Report no. ${Number(report.id)} · ${escapeHtml(report.responder_name || 'Responder')}</span>
                        <h5>${escapeHtml(report.operational_outcome || 'After-Action Report')}</h5>
                        <p>${escapeHtml(reportDate.label)} ${escapeHtml(formatDate(reportDate.value, reportDate.milliseconds))}</p>
                    </div>
                    ${reportStatusPill(report)}
                </header>
                <div class="ar-report-grid">
                    ${reportField('Incident type', report.incident_type)}
                    ${reportField('Operational outcome', report.operational_outcome)}
                    ${reportField('Persons assisted', report.persons_assisted)}
                    ${reportField('Injuries', report.injuries)}
                    ${reportField('Fatalities', report.fatalities)}
                    ${reportField('Follow-up', followUp)}
                    ${reportField('Incident summary', report.incident_summary, { wide: true })}
                    ${reportField('Actions taken', report.actions_taken, { wide: true })}
                    ${reportField('Resources used', report.resources_used)}
                    ${reportField('Agencies involved', report.agencies_involved)}
                    ${reportField('Handoff details', report.handoff_details)}
                    ${reportField('Safety issues', report.safety_issues)}
                    ${reportField('Lessons learned', report.lessons_learned, { wide: true })}
                </div>
                ${reportReviewControls(report)}
            </article>
        `;
    }

    function renderAfterActionReports(reports) {
        if (!reports.length) {
            afterActionList.innerHTML = '<div class="ar-feedback-empty">No responder after-action report has been submitted for this incident.</div>';
            return;
        }
        afterActionList.innerHTML = reports
            .slice()
            .sort((left, right) => reportTimestamp(right) - reportTimestamp(left))
            .map(renderReportCard)
            .join('');
    }

    function populateModal(incident, feedbackPayload, proofPayload, reports) {
        qs('#adminFeedbackTitle').textContent = 'Incident ' + (incident.reference_no || incident.id || '');
        qs('#adminModalCode').textContent = incident.reference_no || ('Incident #' + (incident.id || '--'));
        qs('#adminModalType').textContent = incident.type || '--';
        qs('#adminModalDescription').textContent = incident.description || 'No incident description provided.';
        qs('#adminModalLocation').textContent = incident.location_address || '--';
        qs('#adminModalClosed').textContent = 'Closed: ' + formatDate(incident.resolved_at || incident.cleared_at || incident.completed_at || incident.updated_at);
        qs('#adminModalDispatch').textContent = formatDate(incident.dispatch_assigned_at || incident.assigned_at || incident.created_at);
        qs('#adminModalOnScene').textContent = formatDate(incident.on_scene_at);
        qs('#adminModalResponse').textContent = formatMinutes(incident.response_time_min);
        qs('#adminModalResolution').textContent = formatMinutes(incident.resolution_time_min);
        qs('#adminModalUnit').textContent = incident.assigned_unit_identifier || 'Unassigned';
        qs('#adminModalDriver').textContent = incident.driver_name || 'Not recorded';
        qs('#adminModalVehicle').textContent = incident.vehicle_name || incident.assigned_unit_identifier || 'Not recorded';
        qs('#adminModalPlate').textContent = incident.plate_number || 'Not recorded';

        const pendingCount = reports.filter((report) => workflowStatus(report) === 'submitted').length;
        const approvedCount = reports.filter((report) => workflowStatus(report) === 'approved').length;
        const latest = latestReport(reports);
        qs('#adminModalReportCount').textContent = String(reports.length);
        qs('#adminModalPendingCount').textContent = String(pendingCount);
        qs('#adminModalApprovedCount').textContent = String(approvedCount);
        qs('#adminModalLastUpdated').textContent = latest
            ? formatDate(reportDateValue(latest).value, reportDateValue(latest).milliseconds)
            : '--';
        qs('#adminModalBadges').innerHTML = statusChip(incident.status) + ' ' + priorityChip(incident.priority);

        renderAfterActionReports(reports);
        renderOperationalNotes(feedbackPayload);
        renderProofs(proofPayload);
    }

    function setModalExpanded(expanded) {
        const isExpanded = Boolean(expanded);
        modalDialog?.classList.toggle('is-expanded', isExpanded);
        if (!modalExpand) return;
        modalExpand.setAttribute('aria-pressed', isExpanded ? 'true' : 'false');
        modalExpand.setAttribute('aria-label', isExpanded ? 'Restore review workspace' : 'Maximize review workspace');
        modalExpand.setAttribute('title', isExpanded ? 'Restore review workspace' : 'Maximize review workspace');
        const icon = qs('i', modalExpand);
        if (icon) icon.className = isExpanded ? 'fas fa-compress' : 'fas fa-expand';
    }

    function resetWorkspaceScroll() {
        qsa('.ar-review-scroll-region, .ar-after-action-list', modal).forEach((region) => {
            region.scrollTop = 0;
            region.scrollLeft = 0;
        });
    }

    function setModalBackgroundInert(active) {
        if (active) {
            Array.from(document.body.children).forEach((element) => {
                if (element === modal || element === modalOverlay || element.matches('.ar-toast, script')) return;
                if (!modalBackgroundState.has(element)) {
                    modalBackgroundState.set(element, Boolean(element.inert));
                }
                element.inert = true;
            });
            return;
        }
        modalBackgroundState.forEach((wasInert, element) => {
            if (element.isConnected) element.inert = wasInert;
        });
        modalBackgroundState.clear();
    }

    async function openModal(incidentId) {
        const requestedIncidentId = Number(incidentId);
        if (!Number.isInteger(requestedIncidentId) || requestedIncidentId < 1) return;
        const requestSerial = ++modalRequestSerial;
        if (modal.hidden) {
            modalReturnTarget = document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
        }
        currentIncidentId = requestedIncidentId;
        setModalLoading();
        setModalExpanded(false);
        modalOverlay.hidden = false;
        modal.hidden = false;
        resetWorkspaceScroll();
        document.documentElement.classList.add('ar-modal-open');
        setModalBackgroundInert(true);
        window.requestAnimationFrame(() => modalClose.focus());

        try {
            const [detailsResponse, feedbackResponse, proofsResponse] = await Promise.all([
                fetch('api/incident_details.php?id=' + encodeURIComponent(incidentId), { cache: 'no-store', credentials: 'same-origin' }),
                fetch('api/incident_feedback.php?incident_id=' + encodeURIComponent(incidentId), { cache: 'no-store', credentials: 'same-origin' }),
                fetch('api/incident_proofs.php?incident_id=' + encodeURIComponent(incidentId), { cache: 'no-store', credentials: 'same-origin' }),
            ]);
            const [detailsPayload, feedbackPayload, proofsPayload] = await Promise.all([
                readJsonResponse(detailsResponse),
                readOptionalJsonResponse(feedbackResponse),
                readOptionalJsonResponse(proofsResponse),
            ]);
            if (
                requestSerial !== modalRequestSerial ||
                currentIncidentId !== requestedIncidentId ||
                modal.hidden
            ) {
                return;
            }
            if (!payloadSucceeded(detailsPayload) || !detailsPayload.incident) {
                throw new Error(detailsPayload.error || detailsPayload.message || 'Incident details are not available.');
            }
            const row = incidentRows.find((item) => Number(item.id) === requestedIncidentId);
            const reports = row?.after_action_reports || [];
            populateModal(detailsPayload.incident, feedbackPayload, proofsPayload, reports);
        } catch (error) {
            if (
                requestSerial !== modalRequestSerial ||
                currentIncidentId !== requestedIncidentId ||
                modal.hidden
            ) {
                return;
            }
            afterActionList.innerHTML = '<div class="ar-feedback-empty">' + escapeHtml(error.message || 'Unable to load after-action reports.') + '</div>';
            qs('#adminFeedbackList').innerHTML = '<div class="ar-feedback-empty">Unable to load operational notes.</div>';
            qs('#adminProofGallery').innerHTML = '<div class="ar-feedback-empty">Unable to load responder proof images.</div>';
            notify(error.message || 'Unable to load review details.', 'error');
        }
    }

    async function reviewAfterActionReport(button) {
        const reportId = Number(button.dataset.reportId || 0);
        const action = String(button.dataset.reportAction || '');
        const report = reportById.get(reportId);
        if (!report || !['approve', 'return'].includes(action)) return;

        const noteInput = qs('[data-review-notes-for="' + reportId + '"]');
        const messageNode = qs('[data-review-message-for="' + reportId + '"]');
        const notes = String(noteInput?.value || '').trim();
        if (action === 'return' && notes === '') {
            if (messageNode) {
                messageNode.textContent = 'Enter a reason before returning the report for revision.';
                messageNode.className = 'ar-review-message error';
            }
            noteInput?.focus();
            return;
        }

        const actionLabel = action === 'approve' ? 'approve' : 'return for revision';
        const responderName = report.responder_name || 'this responder';
        if (!window.confirm('Confirm that you want to ' + actionLabel + ' the after-action report from ' + responderName + '?')) {
            return;
        }

        const card = qs('[data-report-card="' + reportId + '"]');
        const actionButtons = qsa('[data-report-action]', card || document);
        actionButtons.forEach((item) => { item.disabled = true; });
        if (messageNode) {
            messageNode.textContent = action === 'approve' ? 'Approving report...' : 'Returning report...';
            messageNode.className = 'ar-review-message';
        }

        try {
            const response = await fetch('api/api_app/review-after-action-report.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({
                    reviewer_id: adminUserId,
                    report_id: reportId,
                    action,
                    notes,
                }),
            });
            const payload = await readJsonResponse(response);
            if (!payloadSucceeded(payload)) {
                throw new Error(payload.message || payload.error || 'The report could not be reviewed.');
            }

            notify(
                action === 'approve'
                    ? 'After-action report approved. It is now available in the responder Approved tab.'
                    : 'After-action report returned to the responder for revision.',
                'success',
            );
            const incidentId = currentIncidentId;
            await loadRows({ showLoading: false });
            if (incidentId) {
                await openModal(incidentId);
            }
        } catch (error) {
            actionButtons.forEach((item) => { item.disabled = false; });
            if (messageNode) {
                messageNode.textContent = error.message || 'Unable to review the report.';
                messageNode.className = 'ar-review-message error';
            }
            notify(error.message || 'Unable to review the report.', 'error');
        }
    }

    async function sendCrimeAnalytics(button, incidentId) {
        const row = incidentRows.find((item) => Number(item.id) === Number(incidentId));
        const incidentCode = row ? (row.incident_code || ('#' + incidentId)) : ('#' + incidentId);
        if (!window.confirm('Send resolved police/crime incident ' + incidentCode + ' to Crime Analytics?')) {
            return;
        }

        const originalHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending';
        try {
            const response = await fetch('api/send_crime_analytics.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ incident_id: incidentId, confirm_send: true }),
            });
            const payload = await readJsonResponse(response);
            if (!payloadSucceeded(payload)) {
                throw new Error(payload.error || payload.message || 'Unable to send incident.');
            }
            if (payload.dry_run) {
                notify('Payload prepared but live Crime Analytics sending is disabled.', 'info');
            } else if (payload.already_sent) {
                notify('This incident was already sent to Crime Analytics.', 'info');
            } else {
                notify('Incident sent to Crime Analytics.', 'success');
            }
            await loadRows({ showLoading: false });
        } catch (error) {
            notify('Crime Analytics send failed: ' + (error.message || 'Unknown error'), 'error');
            button.disabled = false;
            button.innerHTML = originalHtml;
        }
    }

    function closeModal() {
        modalRequestSerial += 1;
        modalOverlay.hidden = true;
        modal.hidden = true;
        currentIncidentId = null;
        setModalExpanded(false);
        document.documentElement.classList.remove('ar-modal-open');
        setModalBackgroundInert(false);
        const returnTarget = modalReturnTarget;
        modalReturnTarget = null;
        if (returnTarget && returnTarget.isConnected) {
            window.requestAnimationFrame(() => returnTarget.focus());
        }
    }

    function hasUnsavedReviewNote() {
        return !modal.hidden && qsa('[data-review-notes-for]', modal)
            .some((input) => String(input.value || '').trim() !== '');
    }

    function requestModalClose() {
        if (hasUnsavedReviewNote() && !window.confirm('Close this review? The admin note you entered has not been submitted and will be discarded.')) {
            return;
        }
        closeModal();
    }

    function keepModalFocus(event) {
        if (event.key !== 'Tab' || modal.hidden) return;
        const focusable = qsa(
            'button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), a[href]',
            modal,
        ).filter((element) => element.offsetParent !== null);
        if (!focusable.length) {
            event.preventDefault();
            modalClose.focus();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    tableBody.addEventListener('click', (event) => {
        const button = event.target.closest('[data-action]');
        if (!button) return;
        const action = button.getAttribute('data-action');
        if (action === 'retry-queue') {
            loadRows();
            return;
        }
        if (action === 'clear-filters') {
            clearFilters();
            return;
        }
        const incidentId = Number(button.getAttribute('data-id') || 0);
        if (!Number.isInteger(incidentId) || incidentId < 1) return;
        if (action === 'send-crime-analytics') {
            sendCrimeAnalytics(button, incidentId);
            return;
        }
        openModal(incidentId);
    });

    afterActionList.addEventListener('click', (event) => {
        const button = event.target.closest('[data-report-action]');
        if (button) reviewAfterActionReport(button);
    });

    function clearFilters() {
        searchFilterInput.value = '';
        categoryFilterSelect.value = '';
        statusFilterSelect.value = '';
        renderTable();
    }

    resetFilterBtn.addEventListener('click', clearFilters);
    queueShortcuts.forEach((button) => {
        button.addEventListener('click', () => {
            statusFilterSelect.value = String(button.dataset.queueShortcut || '');
            renderTable();
            const reduceMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches === true;
            qs('.ar-card')?.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'start' });
        });
    });
    refreshReviewBtn.addEventListener('click', () => loadRows());
    searchFilterInput.addEventListener('input', renderTable);
    categoryFilterSelect.addEventListener('change', renderTable);
    statusFilterSelect.addEventListener('change', renderTable);
    modalOverlay.addEventListener('click', requestModalClose);
    modalClose.addEventListener('click', requestModalClose);
    modalExpand?.addEventListener('click', () => {
        setModalExpanded(!modalDialog?.classList.contains('is-expanded'));
    });
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.hidden) {
            event.preventDefault();
            requestModalClose();
            return;
        }
        keepModalFocus(event);
    });
    window.addEventListener('beforeunload', (event) => {
        if (!hasUnsavedReviewNote()) return;
        event.preventDefault();
        event.returnValue = '';
    });

    closeModal();
    loadRows();
    window.setInterval(() => {
        if (!document.hidden && modal.hidden) {
            loadRows({ showLoading: false });
        }
    }, 30000);
})();
