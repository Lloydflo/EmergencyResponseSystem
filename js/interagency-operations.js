(function () {
    'use strict';

    const root = document.getElementById('iaOperationsDesk');
    if (!root) return;

    const BUILD = '20260807-incident-monitor-v1';

    const els = {
        external: document.getElementById('iaOpsExternal'),
        activeIncidents: document.getElementById('iaOpsActiveIncidents'),
        newTips: document.getElementById('iaOpsNewTips'),
        highEvents: document.getElementById('iaOpsHighEvents'),
        standbyUnits: document.getElementById('iaOpsStandbyUnits'),
        updated: document.getElementById('iaOpsUpdated'),
    };

    const monitor = {
        root: null,
        tabs: [],
        panels: [],
        count: null,
        queue: null,
        selectedHost: null,
        selectedEmpty: null,
        activeBanner: document.getElementById('activeIncidentBanner'),
        currentTab: 'all',
        observer: null,
    };

    const setText = (el, value) => {
        if (el) el.textContent = String(value);
    };

    const escapeHtml = (value) => String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const escapeAttr = escapeHtml;

    const firstText = (...values) => {
        for (const value of values) {
            const text = String(value == null ? '' : value).trim();
            if (text) return text;
        }
        return '';
    };

    const safeJson = async (url) => {
        try {
            const response = await fetch(url, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) return null;
            return await response.json();
        } catch (_) {
            return null;
        }
    };

    const incidentStatusLabel = (value) => {
        const key = String(value || '').trim().toLowerCase().replace(/[_-]+/g, ' ');
        const labels = {
            pending: 'Pending',
            dispatched: 'Dispatched',
            assigned: 'Assigned',
            acknowledged: 'Acknowledged',
            active: 'Active',
            responding: 'Responding',
            enroute: 'En Route',
            'en route': 'En Route',
            'on scene': 'On Scene',
            on_scene: 'On Scene',
        };
        return labels[key] || (key ? key.replace(/\b\w/g, (char) => char.toUpperCase()) : 'Active');
    };

    const incidentStatusTone = (value) => {
        const key = String(value || '').trim().toLowerCase().replace(/[_-]+/g, ' ');
        if (['on scene', 'acknowledged', 'responding', 'en route', 'enroute'].includes(key)) return 'responding';
        if (['dispatched', 'assigned', 'active'].includes(key)) return 'dispatched';
        return 'pending';
    };

    const incidentPriorityTone = (value) => {
        const key = String(value || '').trim().toLowerCase();
        if (key === 'critical') return 'critical';
        if (key === 'high' || key === 'urgent') return 'high';
        if (key === 'medium' || key === 'moderate') return 'medium';
        return 'routine';
    };

    const formatIncidentTime = (value) => {
        const raw = String(value || '').trim();
        if (!raw) return '';
        const normalized = raw.includes('T') ? raw : raw.replace(' ', 'T');
        const hasZone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(normalized);
        const date = new Date(hasZone ? normalized : `${normalized}+08:00`);
        if (Number.isNaN(date.getTime())) return raw;
        return date.toLocaleString('en-PH', {
            timeZone: 'Asia/Manila',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        });
    };

    const activateMonitorTab = (tabName, focusButton) => {
        const wanted = tabName === 'selected' ? 'selected' : 'all';
        monitor.currentTab = wanted;

        monitor.tabs.forEach((button) => {
            const active = button.getAttribute('data-incident-monitor-tab') === wanted;
            button.classList.toggle('active', active);
            button.setAttribute('aria-selected', active ? 'true' : 'false');
            button.tabIndex = active ? 0 : -1;
            if (active && focusButton) button.focus();
        });

        monitor.panels.forEach((panel) => {
            const active = panel.getAttribute('data-incident-monitor-panel') === wanted;
            panel.hidden = !active;
        });
    };

    const syncSelectedIncidentEmptyState = () => {
        if (!monitor.activeBanner || !monitor.selectedEmpty) return;
        const hasIncident = !monitor.activeBanner.hidden
            && String(monitor.activeBanner.innerHTML || '').trim() !== '';
        monitor.selectedEmpty.hidden = hasIncident;
    };

    const setupIncidentMonitor = () => {
        const sidePanel = root.querySelector(':scope > aside.ia-ops-panel')
            || root.querySelector('.ia-ops-panel:nth-child(2)');
        if (!sidePanel) return;

        sidePanel.classList.add('ia-incident-monitor');
        sidePanel.setAttribute('data-incident-monitor-build', BUILD);
        sidePanel.innerHTML = `
            <div class="ia-ops-panel-head ia-incident-monitor-head">
                <div>
                    <h2 class="ia-ops-title">Incident Monitor</h2>
                    <p class="ia-ops-sub">Review active incidents without covering the conversation.</p>
                </div>
                <span class="ia-incident-monitor-count" id="iaIncidentMonitorCount">0 active</span>
            </div>
            <div class="ia-incident-monitor-tabs" role="tablist" aria-label="Incident monitor views">
                <button
                    type="button"
                    class="ia-incident-monitor-tab active"
                    id="iaIncidentMonitorAllTab"
                    role="tab"
                    aria-selected="true"
                    aria-controls="iaIncidentMonitorAllPanel"
                    data-incident-monitor-tab="all"
                >
                    <i class="fas fa-list-check" aria-hidden="true"></i>
                    <span>Active Incidents</span>
                </button>
                <button
                    type="button"
                    class="ia-incident-monitor-tab"
                    id="iaIncidentMonitorSelectedTab"
                    role="tab"
                    aria-selected="false"
                    aria-controls="iaIncidentMonitorSelectedPanel"
                    data-incident-monitor-tab="selected"
                    tabindex="-1"
                >
                    <i class="fas fa-crosshairs" aria-hidden="true"></i>
                    <span>Focused Incident</span>
                </button>
            </div>
            <div
                class="ia-incident-monitor-panel"
                id="iaIncidentMonitorAllPanel"
                role="tabpanel"
                aria-labelledby="iaIncidentMonitorAllTab"
                data-incident-monitor-panel="all"
            >
                <div class="ia-incident-monitor-queue" id="iaIncidentMonitorQueue" aria-live="polite">
                    <div class="ia-incident-monitor-empty">Loading active incidents…</div>
                </div>
            </div>
            <div
                class="ia-incident-monitor-panel"
                id="iaIncidentMonitorSelectedPanel"
                role="tabpanel"
                aria-labelledby="iaIncidentMonitorSelectedTab"
                data-incident-monitor-panel="selected"
                hidden
            >
                <div class="ia-incident-monitor-selected" id="iaIncidentMonitorSelectedHost"></div>
                <div class="ia-incident-monitor-empty" id="iaIncidentMonitorSelectedEmpty">
                    No focused incident is available right now.
                </div>
            </div>
        `;

        monitor.root = sidePanel;
        monitor.tabs = Array.from(sidePanel.querySelectorAll('[data-incident-monitor-tab]'));
        monitor.panels = Array.from(sidePanel.querySelectorAll('[data-incident-monitor-panel]'));
        monitor.count = sidePanel.querySelector('#iaIncidentMonitorCount');
        monitor.queue = sidePanel.querySelector('#iaIncidentMonitorQueue');
        monitor.selectedHost = sidePanel.querySelector('#iaIncidentMonitorSelectedHost');
        monitor.selectedEmpty = sidePanel.querySelector('#iaIncidentMonitorSelectedEmpty');

        if (monitor.activeBanner && monitor.selectedHost) {
            monitor.selectedHost.appendChild(monitor.activeBanner);
            monitor.activeBanner.classList.add('ia-active-incident-in-monitor');
            monitor.observer = new MutationObserver(syncSelectedIncidentEmptyState);
            monitor.observer.observe(monitor.activeBanner, {
                attributes: true,
                attributeFilter: ['hidden'],
                childList: true,
                subtree: true,
            });
            syncSelectedIncidentEmptyState();
        }

        monitor.tabs.forEach((button, index) => {
            button.addEventListener('click', () => {
                activateMonitorTab(button.getAttribute('data-incident-monitor-tab') || 'all', false);
            });
            button.addEventListener('keydown', (event) => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                let nextIndex = index;
                if (event.key === 'ArrowRight') nextIndex = (index + 1) % monitor.tabs.length;
                if (event.key === 'ArrowLeft') nextIndex = (index - 1 + monitor.tabs.length) % monitor.tabs.length;
                if (event.key === 'Home') nextIndex = 0;
                if (event.key === 'End') nextIndex = monitor.tabs.length - 1;
                const next = monitor.tabs[nextIndex];
                activateMonitorTab(next.getAttribute('data-incident-monitor-tab') || 'all', true);
            });
        });

        activateMonitorTab('all', false);
    };

    const openIncidentUsingExistingDialog = (incidentId) => {
        const id = Number(incidentId || 0);
        if (!Number.isInteger(id) || id <= 0 || !monitor.activeBanner) return;

        const proxy = document.createElement('button');
        proxy.type = 'button';
        proxy.hidden = true;
        proxy.setAttribute('data-active-incident-open', String(id));
        monitor.activeBanner.appendChild(proxy);
        proxy.click();
        proxy.remove();
    };

    const renderIncidentQueue = (items) => {
        const incidents = Array.isArray(items) ? items : [];
        setText(monitor.count, `${incidents.length} active`);
        if (!monitor.queue) return;

        if (!incidents.length) {
            monitor.queue.innerHTML = `
                <div class="ia-incident-monitor-empty">
                    <i class="fas fa-circle-check" aria-hidden="true"></i>
                    <strong>No active incidents</strong>
                    <span>The incident queue is currently clear.</span>
                </div>
            `;
            return;
        }

        monitor.queue.innerHTML = incidents.map((incident) => {
            const id = Number(incident.id || 0);
            const reference = firstText(
                incident.incident_code,
                incident.reference_no,
                id > 0 ? `Incident #${id}` : 'Active Incident'
            );
            const title = firstText(incident.title, incident.type, 'Active incident');
            const location = firstText(incident.location, incident.location_address, incident.address);
            const priority = firstText(incident.priority, 'Routine');
            const statusRaw = firstText(
                incident.latest_dispatch_status,
                incident.dispatch_status,
                incident.status,
                'active'
            );
            const unit = firstText(
                incident.assigned_unit_identifier,
                incident.unit_identifier,
                incident.assigned_unit,
                incident.vehicle_name
            );
            const started = formatIncidentTime(firstText(
                incident.assigned_at,
                incident.acknowledged_at,
                incident.enroute_at,
                incident.created_at
            ));
            const meta = [location, unit ? `Unit ${unit}` : '', started].filter(Boolean);

            return `
                <button
                    type="button"
                    class="ia-incident-monitor-item"
                    data-incident-monitor-open="${escapeAttr(id)}"
                    aria-label="View ${escapeAttr(reference)}"
                >
                    <span class="ia-incident-monitor-icon" aria-hidden="true">
                        <i class="fas fa-triangle-exclamation"></i>
                    </span>
                    <span class="ia-incident-monitor-main">
                        <strong class="ia-incident-monitor-reference">${escapeHtml(reference)}</strong>
                        <span class="ia-incident-monitor-title">${escapeHtml(title)}</span>
                        ${meta.length ? `<span class="ia-incident-monitor-meta">${meta.map(escapeHtml).join(' · ')}</span>` : ''}
                    </span>
                    <span class="ia-incident-monitor-side">
                        <span class="ia-incident-monitor-priority ${escapeAttr(incidentPriorityTone(priority))}">${escapeHtml(priority)}</span>
                        <span class="ia-incident-monitor-status ${escapeAttr(incidentStatusTone(statusRaw))}">${escapeHtml(incidentStatusLabel(statusRaw))}</span>
                        <i class="fas fa-chevron-right ia-incident-monitor-chevron" aria-hidden="true"></i>
                    </span>
                </button>
            `;
        }).join('');
    };

    setupIncidentMonitor();

    if (monitor.root) {
        monitor.root.addEventListener('click', (event) => {
            const openButton = event.target.closest('[data-incident-monitor-open]');
            if (!openButton) return;
            openIncidentUsingExistingDialog(openButton.getAttribute('data-incident-monitor-open'));
        });
    }

    const updateOperations = async () => {
        setText(els.updated, 'Syncing');

        const [incidents, transfers, tips, events] = await Promise.all([
            safeJson('api/incidents_list.php?status=active'),
            safeJson('api/incoming_transfers.php?limit=50'),
            safeJson('api/system_API/?action=anonymous_tip&limit=120'),
            safeJson('api/system_API/?action=event_coordination&limit=120'),
        ]);

        const incidentItems = incidents && incidents.ok && Array.isArray(incidents.items) ? incidents.items : [];
        const transferItems = transfers && transfers.ok && Array.isArray(transfers.transfers) ? transfers.transfers : [];
        const tipItems = tips && tips.success && Array.isArray(tips.items) ? tips.items : [];
        const eventItems = events && events.success && Array.isArray(events.items) ? events.items : [];

        const openTransfers = transferItems.filter((item) => {
            const status = String(item.incident_status || '').trim().toLowerCase();
            return !['resolved', 'cancelled', 'canceled', 'closed', 'rejected'].includes(status);
        });
        const activeEvents = eventItems.filter((item) => ['active', 'standby'].includes(String(item.status || '').toLowerCase()));
        const highEvents = activeEvents.filter((item) => ['high', 'critical'].includes(String(item.on_site_safety_hazard_level || '').toLowerCase()));
        const standbyUnits = activeEvents.reduce((sum, item) => sum + Number(item.required_standby_responders || 0), 0);

        setText(els.external, openTransfers.length);
        setText(els.activeIncidents, incidentItems.length);
        setText(els.newTips, tipItems.filter((item) => ['pending', 'new'].includes(String(item.display_status || item.status || 'new').trim().toLowerCase())).length);
        setText(els.highEvents, highEvents.length);
        setText(els.standbyUnits, standbyUnits);
        renderIncidentQueue(incidentItems);

        if (els.updated) {
            const time = new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
            els.updated.innerHTML = `<i class="fas fa-check-circle"></i> Updated ${time}`;
        }
    };

    const openSection = (id) => {
        const launcher = document.querySelector(`[data-module-open="${id}"]`);
        if (launcher) {
            launcher.click();
            return;
        }

        const section = document.getElementById(id);
        if (!section) return;

        const toggle = section.querySelector('button[aria-controls]');
        if (toggle && toggle.getAttribute('aria-expanded') !== 'true') {
            toggle.click();
        }

        section.scrollIntoView({ behavior: 'smooth', block: 'start' });
    };

    root.addEventListener('click', (event) => {
        const button = event.target.closest('[data-ops-open]');
        if (!button) return;
        openSection(String(button.getAttribute('data-ops-open') || ''));
    });

    let updateOperationsInFlight = false;
    const guardedUpdateOperations = async () => {
        if (updateOperationsInFlight) return;
        updateOperationsInFlight = true;
        try {
            await updateOperations();
        } finally {
            updateOperationsInFlight = false;
        }
    };

    guardedUpdateOperations();
    window.addEventListener('ers:anonymous-tips-updated', guardedUpdateOperations);
    window.addEventListener('ers:incident-queue-updated', guardedUpdateOperations);
    window.addEventListener('storage', (e) => {
        if (e.key === 'ers_incidents' || e.key === 'ers_incidents_changed' || e.key === 'ers_anonymous_tips_changed') {
            guardedUpdateOperations();
        }
    });
    window.setInterval(() => {
        if (document.visibilityState === 'visible') guardedUpdateOperations();
    }, 15000);
})();
