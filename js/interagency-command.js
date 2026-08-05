(function () {
    const root = document.getElementById('iaCommandCenter');
    if (!root) return;

    const API_ROOM = 'api/interagency_command_center.php';
    const API_INCIDENTS = 'api/incidents_list.php?status=active';
    const agencies = ['Dispatcher', 'Police', 'Fire', 'Medical', 'Traffic', 'Command'];
    const taskTemplates = [
        'Secure perimeter',
        'Dispatch nearest available unit',
        'Confirm en route status',
        'Establish staging area',
        'Triage victims',
        'Request additional backup',
        'Prepare evacuation route',
        'Send field situation update'
    ];
    const broadcastTemplates = [
        'Critical update: all agencies acknowledge and standby for command instructions.',
        'Requesting immediate backup at the incident location.',
        'Scene status update required from all assigned units.',
        'Command requests current resource availability.',
        'Incident location has changed. Verify route before dispatch.',
        'Scene secured. Continue monitoring until cleared.'
    ];

    const state = {
        incidents: [],
        incidentId: 0,
        room: null,
        loading: false,
        expanded: true,
        timer: null
    };

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeAttr(value) {
        return escapeHtml(value).replace(/`/g, '&#096;');
    }

    function formatDate(value) {
        const raw = String(value || '').trim();
        if (!raw) return 'Just now';
        const parsed = new Date(raw.indexOf('T') === -1 ? raw.replace(' ', 'T') : raw);
        return Number.isNaN(parsed.getTime()) ? raw : parsed.toLocaleString();
    }

    function incidentLabel(item) {
        if (!item) return 'Incident';
        const code = String(item.incident_code || item.reference_no || '').trim();
        if (code) return code;
        return item.id ? `Incident #${item.id}` : 'Incident';
    }

    function mapUrl(incident) {
        if (!incident) return '';
        const lat = Number(incident.latitude);
        const lng = Number(incident.longitude);
        if (Number.isFinite(lat) && Number.isFinite(lng)) {
            return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(`${lat},${lng}`)}`;
        }
        const location = String(incident.location || '').trim();
        return location ? `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(location)}` : '';
    }

    function statusLabel(value) {
        const raw = String(value || 'pending');
        return raw.replace(/_/g, ' ').replace(/\b\w/g, (ch) => ch.toUpperCase());
    }

    function renderShell() {
        root.innerHTML = `
            <div class="ia-command-head">
                <button type="button" class="ia-command-toggle" id="iaCommandToggle" aria-expanded="false" aria-controls="iaCommandDropdown">
                    <span class="ia-command-title-wrap">
                        <span class="ia-command-title">Inter-Agency Command Center</span>
                        <span class="ia-command-sub">Incident intelligence, agency ownership, tasking, broadcasts, acknowledgements, and audit trail.</span>
                    </span>
                    <span class="ia-command-chevron" aria-hidden="true"><i class="fas fa-chevron-down"></i></span>
                </button>
            </div>
            <div class="ia-command-dropdown" id="iaCommandDropdown" hidden>
                <div class="ia-command-controls">
                    <select class="ia-command-select" id="iaCommandIncidentSelect" aria-label="Select incident">
                        <option value="">Loading active incidents...</option>
                    </select>
                    <button type="button" class="ia-command-btn secondary" id="iaCommandRefreshBtn">
                        <i class="fas fa-rotate"></i> Refresh
                    </button>
                </div>
                <div class="ia-command-body" id="iaCommandBody">
                    <div class="ia-command-empty">Choose an active incident to open the command room.</div>
                </div>
            </div>
        `;

        const toggle = document.getElementById('iaCommandToggle');
        const select = document.getElementById('iaCommandIncidentSelect');
        const refresh = document.getElementById('iaCommandRefreshBtn');
        if (toggle) {
            toggle.addEventListener('click', () => setExpanded(!state.expanded));
        }
        if (select) {
            select.addEventListener('change', () => {
                state.incidentId = Number(select.value || 0);
                if (state.incidentId > 0) {
                    loadRoom();
                } else {
                    state.room = null;
                    renderBody();
                }
            });
        }
        if (refresh) {
            refresh.addEventListener('click', () => refreshAll());
        }

        setExpanded(state.expanded);
    }

    function setExpanded(expanded) {
        state.expanded = Boolean(expanded);
        root.classList.toggle('is-open', state.expanded);
        root.classList.toggle('is-collapsed', !state.expanded);

        const toggle = document.getElementById('iaCommandToggle');
        const dropdown = document.getElementById('iaCommandDropdown');
        if (toggle) {
            toggle.setAttribute('aria-expanded', state.expanded ? 'true' : 'false');
        }
        if (dropdown) {
            dropdown.hidden = !state.expanded;
        }
    }

    function renderIncidentOptions() {
        const select = document.getElementById('iaCommandIncidentSelect');
        if (!select) return;

        if (!state.incidents.length) {
            select.innerHTML = '<option value="">No active incidents</option>';
            return;
        }

        select.innerHTML = '<option value="">Select active incident...</option>' + state.incidents.map((item) => {
            const id = Number(item.id) || 0;
            const label = incidentLabel(item);
            const meta = [item.type, item.priority, item.location].filter(Boolean).join(' - ');
            return `<option value="${id}">${escapeHtml(label + (meta ? ' | ' + meta : ''))}</option>`;
        }).join('');

        if (state.incidentId && state.incidents.some((item) => Number(item.id) === state.incidentId)) {
            select.value = String(state.incidentId);
        }
    }

    function renderBody() {
        const body = document.getElementById('iaCommandBody');
        if (!body) return;

        if (state.loading) {
            body.innerHTML = '<div class="ia-command-empty">Loading command room...</div>';
            return;
        }

        if (!state.incidentId || !state.room || !state.room.incident) {
            body.innerHTML = '<div class="ia-command-empty">Choose an active incident to open the command room.</div>';
            return;
        }

        const room = state.room;
        const incident = room.incident || {};
        const summary = room.summary || {};
        const maps = mapUrl(incident);

        body.innerHTML = `
            <div class="ia-command-incident">
                <div class="ia-command-kpi"><span>Incident</span><strong>${escapeHtml(incident.reference_no || ('#' + incident.id))}</strong></div>
                <div class="ia-command-kpi"><span>Status</span><strong>${escapeHtml(statusLabel(incident.status))}</strong></div>
                <div class="ia-command-kpi"><span>Open Tasks</span><strong>${escapeHtml(summary.task_open || 0)} / ${escapeHtml(summary.task_total || 0)}</strong></div>
                <div class="ia-command-kpi"><span>Critical Ack</span><strong>${escapeHtml(summary.critical_unacked || 0)} pending</strong></div>
            </div>
            <div class="ia-command-room-layout">
                <aside class="ia-command-panel ia-command-intel">
                    <div class="ia-command-panel-head">
                        <h3 class="ia-command-panel-title">Incident Intelligence</h3>
                    </div>
                    <div class="ia-command-panel-body">
                        <div class="ia-command-intel-hero">
                            <span class="ia-command-priority ${escapeAttr(String(incident.priority || 'routine').toLowerCase())}">${escapeHtml(incident.priority || 'N/A')}</span>
                            <strong>${escapeHtml(incident.type || 'Emergency')}</strong>
                            <p>${escapeHtml(incident.location || 'Location not provided')}</p>
                            ${maps ? `<a class="ia-command-map-btn" href="${escapeAttr(maps)}" target="_blank" rel="noopener"><i class="fas fa-map-location-dot"></i> Open map</a>` : ''}
                        </div>
                        <div class="ia-command-readiness">
                            <div><span>Lead</span><strong>Command</strong></div>
                            <div><span>Tasks</span><strong>${escapeHtml(summary.task_open || 0)} open</strong></div>
                            <div><span>Ack</span><strong>${escapeHtml(summary.critical_unacked || 0)} pending</strong></div>
                        </div>
                        <div class="ia-command-audit">
                            <strong>${escapeHtml(incident.title || 'Operational coordination')}</strong>
                            <p>${escapeHtml(incident.description || 'No additional incident description provided.')}</p>
                        </div>
                    </div>
                </aside>
                <section class="ia-command-main-stack">
                    <div class="ia-command-panel">
                        <div class="ia-command-panel-head">
                            <h3 class="ia-command-panel-title">Agency Task Checklist</h3>
                        </div>
                        <div class="ia-command-panel-body">
                            ${renderTaskForm()}
                            <div class="ia-command-task-list">${renderTasks(room.tasks || [])}</div>
                        </div>
                    </div>
                    <div class="ia-command-panel">
                        <div class="ia-command-panel-head">
                            <h3 class="ia-command-panel-title">Broadcast and Acknowledgement</h3>
                        </div>
                        <div class="ia-command-panel-body">
                            ${renderBroadcastForm()}
                            <div class="ia-command-broadcast-list">${renderBroadcasts(room.broadcasts || [])}</div>
                        </div>
                    </div>
                </section>
                <aside class="ia-command-panel">
                    <div class="ia-command-panel-head">
                        <h3 class="ia-command-panel-title">Decision Timeline</h3>
                    </div>
                    <div class="ia-command-panel-body">
                        <div class="ia-command-audit-list">${renderAudit(room.audit || [])}</div>
                    </div>
                </aside>
            </div>
        `;

        bindBodyEvents();
    }

    function renderTaskForm() {
        return `
            <div class="ia-command-form">
                <select class="ia-command-select" id="iaTaskAgency">
                    ${agencies.map((agency) => `<option value="${escapeAttr(agency)}">${escapeHtml(agency)}</option>`).join('')}
                </select>
                <select class="ia-command-select" id="iaTaskTemplate">
                    <option value="">Custom task...</option>
                    ${taskTemplates.map((task) => `<option value="${escapeAttr(task)}">${escapeHtml(task)}</option>`).join('')}
                </select>
                <input class="ia-command-input" id="iaTaskInput" maxlength="255" placeholder="Task details">
                <button type="button" class="ia-command-btn" id="iaAddTaskBtn"><i class="fas fa-list-check"></i> Add</button>
            </div>
        `;
    }

    function renderBroadcastForm() {
        return `
            <div class="ia-command-form" style="grid-template-columns: 120px minmax(160px, 0.8fr) minmax(0, 1fr) auto;">
                <select class="ia-command-select" id="iaBroadcastPriority">
                    <option value="routine">Routine</option>
                    <option value="urgent">Urgent</option>
                    <option value="critical">Critical</option>
                </select>
                <select class="ia-command-select" id="iaBroadcastTemplate">
                    <option value="">Message template...</option>
                    ${broadcastTemplates.map((message) => `<option value="${escapeAttr(message)}">${escapeHtml(message)}</option>`).join('')}
                </select>
                <textarea class="ia-command-textarea" id="iaBroadcastMessage" maxlength="2000" placeholder="Broadcast update or emergency alert"></textarea>
                <button type="button" class="ia-command-btn" id="iaSendBroadcastBtn"><i class="fas fa-bullhorn"></i> Send</button>
            </div>
        `;
    }

    function renderTasks(tasks) {
        if (!tasks.length) {
            return '<div class="ia-command-empty">No tasks yet. Add the first agency task above.</div>';
        }

        return tasks.map((task) => `
            <article class="ia-command-task">
                <div>
                    <div class="ia-command-task-title">${escapeHtml(task.task)}</div>
                    <div class="ia-command-meta">${escapeHtml(task.agency || 'Agency')} ${task.assigned_to ? '- ' + escapeHtml(task.assigned_to) : ''} - Updated ${escapeHtml(formatDate(task.updated_at))}</div>
                </div>
                <select class="ia-command-status" data-task-status="${escapeAttr(task.id)}">
                    ${['pending', 'in_progress', 'done', 'blocked'].map((status) => `<option value="${status}" ${task.status === status ? 'selected' : ''}>${escapeHtml(statusLabel(status))}</option>`).join('')}
                </select>
            </article>
        `).join('');
    }

    function renderBroadcasts(items) {
        if (!items.length) {
            return '<div class="ia-command-empty">No broadcasts yet.</div>';
        }

        return items.map((item) => `
            <article class="ia-command-broadcast">
                <div class="ia-command-broadcast-head">
                    <span class="ia-command-priority ${escapeAttr(item.priority)}">${escapeHtml(item.priority)}</span>
                    <span class="ia-command-ack ${item.acknowledged_by_me ? '' : 'missing'}">${item.acknowledged_by_me ? 'Acknowledged' : 'Needs ack'}</span>
                </div>
                <div class="ia-command-broadcast-message">${escapeHtml(item.message)}</div>
                <div class="ia-command-meta">${escapeHtml(item.sender_name || 'System')} - ${escapeHtml(formatDate(item.created_at))} - ${escapeHtml(item.ack_count || 0)} ack(s)</div>
                ${item.acknowledged_by_me ? '' : `<button type="button" class="ia-command-btn secondary" data-ack-broadcast="${escapeAttr(item.id)}"><i class="fas fa-check"></i> Acknowledge</button>`}
            </article>
        `).join('');
    }

    function renderAudit(items) {
        if (!items.length) {
            return '<div class="ia-command-empty">No command activity yet.</div>';
        }

        return items.map((item) => `
            <article class="ia-command-audit">
                <strong>${escapeHtml(statusLabel(item.action))}</strong>
                <p>${escapeHtml(item.actor_name || 'System')} - ${escapeHtml(formatDate(item.created_at))}</p>
                ${item.details ? `<p>${escapeHtml(item.details)}</p>` : ''}
            </article>
        `).join('');
    }

    function bindBodyEvents() {
        const taskTemplate = document.getElementById('iaTaskTemplate');
        const taskInput = document.getElementById('iaTaskInput');
        const addTask = document.getElementById('iaAddTaskBtn');
        const broadcastTemplate = document.getElementById('iaBroadcastTemplate');
        const broadcastMessage = document.getElementById('iaBroadcastMessage');
        const sendBroadcast = document.getElementById('iaSendBroadcastBtn');

        if (taskTemplate && taskInput) {
            taskTemplate.addEventListener('change', () => {
                if (taskTemplate.value) taskInput.value = taskTemplate.value;
            });
        }
        if (addTask) addTask.addEventListener('click', addTaskAction);

        if (broadcastTemplate && broadcastMessage) {
            broadcastTemplate.addEventListener('change', () => {
                if (broadcastTemplate.value) broadcastMessage.value = broadcastTemplate.value;
            });
        }
        if (sendBroadcast) sendBroadcast.addEventListener('click', sendBroadcastAction);

        document.querySelectorAll('[data-task-status]').forEach((select) => {
            select.addEventListener('change', () => updateTaskStatus(Number(select.getAttribute('data-task-status') || 0), select.value));
        });
        document.querySelectorAll('[data-ack-broadcast]').forEach((btn) => {
            btn.addEventListener('click', () => acknowledgeBroadcast(Number(btn.getAttribute('data-ack-broadcast') || 0)));
        });
    }

    async function requestRoom(payload) {
        const res = await fetch(API_ROOM, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!res.ok || !data || !data.ok) {
            throw new Error((data && data.error) ? String(data.error) : 'Command center action failed');
        }
        state.room = data.room || null;
        renderBody();
    }

    async function addTaskAction() {
        if (!state.incidentId) return;
        const agency = document.getElementById('iaTaskAgency')?.value || 'Dispatcher';
        const task = document.getElementById('iaTaskInput')?.value.trim() || '';
        if (!task) {
            alert('Enter a task first.');
            return;
        }
        await requestRoom({ action: 'add_task', incident_id: state.incidentId, agency, task });
    }

    async function updateTaskStatus(taskId, status) {
        if (!state.incidentId || !taskId) return;
        await requestRoom({ action: 'update_task', incident_id: state.incidentId, task_id: taskId, status });
    }

    async function sendBroadcastAction() {
        if (!state.incidentId) return;
        const priority = document.getElementById('iaBroadcastPriority')?.value || 'routine';
        const message = document.getElementById('iaBroadcastMessage')?.value.trim() || '';
        if (!message) {
            alert('Enter a broadcast message first.');
            return;
        }
        await requestRoom({ action: 'broadcast', incident_id: state.incidentId, priority, message });
    }

    async function acknowledgeBroadcast(broadcastId) {
        if (!broadcastId) return;
        await requestRoom({ action: 'acknowledge', broadcast_id: broadcastId });
    }

    async function loadIncidents() {
        const res = await fetch(API_INCIDENTS, { cache: 'no-store', credentials: 'same-origin' });
        const data = await res.json();
        state.incidents = data && data.ok && Array.isArray(data.items) ? data.items : [];
        if (!state.incidentId && state.incidents.length) {
            state.incidentId = Number(state.incidents[0].id) || 0;
        }
        renderIncidentOptions();
    }

    async function loadRoom(silent) {
        if (!state.incidentId) {
            state.room = null;
            renderBody();
            return;
        }
        state.loading = !silent;
        if (!silent) renderBody();
        try {
            const res = await fetch(`${API_ROOM}?incident_id=${encodeURIComponent(String(state.incidentId))}`, { cache: 'no-store', credentials: 'same-origin' });
            const data = await res.json();
            state.room = data && data.ok ? data.room : null;
        } catch (error) {
            if (!silent) {
                state.room = null;
                console.warn(error);
            }
        } finally {
            state.loading = false;
            renderBody();
        }
    }

    let refreshAllInFlight = false;
    async function refreshAll(silent) {
        if (refreshAllInFlight) return;
        refreshAllInFlight = true;
        try {
            await loadIncidents();
            await loadRoom(!!silent);
        } finally {
            refreshAllInFlight = false;
        }
    }

    renderShell();
    refreshAll(false);
    state.timer = window.setInterval(() => {
        if (document.visibilityState === 'visible') refreshAll(true);
    }, 10000);
})();
