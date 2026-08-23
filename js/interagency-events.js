(function () {
    const root = document.getElementById('iaEventCoordination');
    if (!root) {
        return;
    }

    const apiUrl = 'api/system_API/?action=event_coordination';
    const state = {
        items: [],
        loading: true,
        error: '',
        editingId: null,
        detailId: null,
        dispatchId: null,
        availableUnits: [],
        assignments: [],
        dispatchStatusEventId: null,
        dispatchStatusRows: [],
        dispatchStatusLoading: false,
        dispatchLoading: false,
        knownEventIds: new Set(),
        notificationsInitialized: false,
        search: '',
        status: 'all',
        expanded: true,
    };

    const hazardOrder = {
        low: 1,
        medium: 2,
        high: 3,
        critical: 4,
    };

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const formatDate = (value) => {
        if (!value) {
            return 'No schedule';
        }
        const date = new Date(String(value).replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) {
            return value;
        }
        return date.toLocaleString([], {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit',
        });
    };

    const toDateInput = (value) => {
        if (!value) {
            return '';
        }
        const normalized = String(value).replace(' ', 'T');
        return normalized.slice(0, 16);
    };

    const getFilteredItems = () => state.items.filter((item) => {
        const statusMatch = state.status === 'all' || item.status === state.status;
        const haystack = [
            item.coordination_id,
            item.event_profile,
            item.source_system,
            item.emergency_contact_persons,
        ].join(' ').toLowerCase();
        return statusMatch && haystack.includes(state.search.toLowerCase());
    });

    const getEditingItem = () => state.items.find((item) => Number(item.id) === Number(state.editingId)) || null;
    const getSelectedEvent = () => state.items.find((item) => Number(item.id) === Number(state.detailId || state.dispatchId)) || null;

    const buildPayloadFromForm = (form) => ({
        action: 'save',
        id: form.elements.id.value,
        coordination_id: form.elements.coordination_id.value.trim(),
        event_profile: form.elements.event_profile.value.trim(),
        event_schedule: form.elements.event_schedule.value,
        on_site_safety_hazard_level: form.elements.on_site_safety_hazard_level.value,
        required_standby_responders: form.elements.required_standby_responders.value,
        emergency_contact_persons: form.elements.emergency_contact_persons.value.trim(),
        status: form.elements.status.value,
        source_system: form.elements.source_system.value.trim() || 'ERS',
    });

    const eventNotificationKey = (kind, item) => `ers_event_${kind}_${Number(item.id)}_${String(item.event_schedule || '')}`;

    const wasEventNotified = (kind, item) => {
        try { return sessionStorage.getItem(eventNotificationKey(kind, item)) === '1'; } catch (_) { return false; }
    };

    const markEventNotified = (kind, item) => {
        try { sessionStorage.setItem(eventNotificationKey(kind, item), '1'); } catch (_) {}
    };

    const openEventFromNotification = (item) => {
        const launcher = document.querySelector('[data-module-open="iaEventCoordination"]');
        if (launcher) launcher.click();
        state.detailId = Number(item.id);
        state.expanded = true;
        render();
    };

    const showEventNotification = (title, message, item, kind) => {
        if (!item || wasEventNotified(kind, item)) return;
        markEventNotified(kind, item);
        let toastRoot = document.getElementById('iaModuleToast');
        if (!toastRoot) {
            toastRoot = document.createElement('div');
            toastRoot.className = 'ia-module-toast';
            toastRoot.id = 'iaEventToastRoot';
            toastRoot.setAttribute('aria-live', 'polite');
            document.body.appendChild(toastRoot);
        }
        const toast = document.createElement('button');
        toast.type = 'button';
        toast.className = 'ia-module-toast-item';
        toast.textContent = `${title}: ${message}`;
        toast.addEventListener('click', () => {
            openEventFromNotification(item);
            toast.remove();
        });
        toastRoot.prepend(toast);
        window.setTimeout(() => toast.remove(), 10000);
        if ('Notification' in window && window.Notification.permission === 'granted') {
            try { new window.Notification(title, { body: message }); } catch (_) {}
        }
    };

    const checkEventNotifications = (items) => {
        const previousIds = state.knownEventIds;
        const now = Date.now();
        const alertWindowMs = 30 * 60 * 1000;
        items.forEach((item) => {
            const id = Number(item.id);
            if (state.notificationsInitialized && !previousIds.has(id)) {
                showEventNotification('New interagency event received', `${item.event_profile || item.coordination_id || 'Event'} needs review and dispatch planning.`, item, 'received');
            }
            const schedule = new Date(String(item.event_schedule || '').replace(' ', 'T')).getTime();
            const status = String(item.status || '').toLowerCase();
            if (Number.isFinite(schedule) && schedule >= now && schedule - now <= alertWindowMs && ['active', 'standby'].includes(status)) {
                const minutes = Math.max(0, Math.ceil((schedule - now) / 60000));
                showEventNotification('Event dispatch reminder', `${item.event_profile || item.coordination_id || 'Event'} starts in ${minutes} minute${minutes === 1 ? '' : 's'}. Review and dispatch responders.`, item, 'near');
            }
        });
        state.knownEventIds = new Set(items.map((item) => Number(item.id)));
        state.notificationsInitialized = true;
    };

    const loadEvents = async () => {
        state.loading = true;
        state.error = '';
        render();

        try {
            const response = await fetch(`${apiUrl}&limit=80`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Unable to load event coordination records');
            }
            state.items = Array.isArray(data.items) ? data.items : [];
            checkEventNotifications(state.items);
        } catch (error) {
            state.error = error.message || 'Unable to load event coordination records';
        } finally {
            state.loading = false;
            render();
        }
    };

    const saveEvent = async (form) => {
        const submit = form.querySelector('[data-event-submit]');
        if (submit) {
            submit.disabled = true;
        }

        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(buildPayloadFromForm(form)),
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Unable to save event coordination data');
            }
            state.editingId = null;
            await loadEvents();
        } catch (error) {
            state.error = error.message || 'Unable to save event coordination data';
            render();
        }
    };

    const sendEvent = async (id) => {
        const endpoint = window.prompt('Group 6 endpoint URL');
        if (!endpoint) {
            return;
        }

        state.error = '';
        render();

        try {
            const response = await fetch(apiUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    action: 'send',
                    id,
                    group6_endpoint: endpoint,
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Unable to send event coordination');
            }
            await loadEvents();
        } catch (error) {
            state.error = error.message || 'Unable to send event coordination';
            render();
        }
    };

    const openDispatch = async (id) => {
        state.dispatchId = Number(id);
        state.detailId = Number(id);
        state.dispatchLoading = true;
        state.error = '';
        render();
        try {
            const [unitsResponse, assignmentsResponse] = await Promise.all([
                fetch('api/units_list.php?status=available', { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
                fetch(`api/event_dispatch.php?event_id=${encodeURIComponent(id)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } }),
            ]);
            const units = await unitsResponse.json();
            const assignments = await assignmentsResponse.json();
            if (!unitsResponse.ok || !units.ok) throw new Error(units.error || 'Unable to load available units.');
            if (!assignmentsResponse.ok || !assignments.ok) throw new Error(assignments.error || 'Unable to load event assignments.');
            state.availableUnits = Array.isArray(units.items) ? units.items : [];
            state.assignments = Array.isArray(assignments.assignments) ? assignments.assignments : [];
        } catch (error) {
            state.error = error.message || 'Unable to load event dispatch information.';
        } finally {
            state.dispatchLoading = false;
            render();
        }
    };

    const submitDispatch = async (form) => {
        const selected = Array.from(form.querySelectorAll('input[name="event_unit_ids[]"]:checked')).map((input) => Number(input.value));
        if (!selected.length) {
            state.error = 'Select at least one available responder unit.';
            render();
            return;
        }
        state.dispatchLoading = true;
        render();
        try {
            const response = await fetch('api/event_dispatch.php', {
                method: 'POST', credentials: 'same-origin',
                headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ event_id: state.dispatchId, unit_ids: selected }),
            });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to assign responder units.');
            state.assignments = Array.isArray(data.assignments) ? data.assignments : [];
            state.availableUnits = state.availableUnits.filter((unit) => !selected.includes(Number(unit.id)));
            if (Number(data.incident_id) > 0) {
                const tracking = new URLSearchParams({
                    incident_id: String(data.incident_id),
                    unit_id: String(selected[0]),
                });
                window.location.href = `dispatcher/gps.php?${tracking.toString()}`;
                return;
            }
        } catch (error) {
            state.error = error.message || 'Unable to assign responder units.';
        } finally {
            state.dispatchLoading = false;
            render();
        }
    };

    const toggleDispatchStatus = async (eventId) => {
        const id = Number(eventId);
        if (state.dispatchStatusEventId === id) {
            state.dispatchStatusEventId = null;
            render();
            return;
        }
        state.dispatchStatusEventId = id;
        state.dispatchStatusLoading = true;
        render();
        try {
            const response = await fetch(`api/event_dispatch.php?event_id=${encodeURIComponent(id)}`, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (!response.ok || !data.ok) throw new Error(data.error || 'Unable to load dispatch status.');
            state.dispatchStatusRows = Array.isArray(data.assignments) ? data.assignments : [];
        } catch (error) {
            state.error = error.message || 'Unable to load dispatch status.';
            state.dispatchStatusEventId = null;
        } finally {
            state.dispatchStatusLoading = false;
            render();
        }
    };

    const renderStats = () => {
        const active = state.items.filter((item) => ['active', 'standby'].includes(item.status)).length;
        const highHazards = state.items.filter((item) => ['high', 'critical'].includes(item.on_site_safety_hazard_level)).length;
        const responders = state.items.reduce((sum, item) => sum + Number(item.required_standby_responders || 0), 0);
        const nextEvent = state.items
            .filter((item) => item.event_schedule)
            .sort((a, b) => new Date(String(a.event_schedule).replace(' ', 'T')) - new Date(String(b.event_schedule).replace(' ', 'T')))[0];

        return `
            <section class="ia-event-stats" aria-label="Event coordination summary">
                <article class="ia-event-stat">
                    <div class="ia-event-stat-label">Active Events</div>
                    <div class="ia-event-stat-value">${active}</div>
                </article>
                <article class="ia-event-stat">
                    <div class="ia-event-stat-label">High Hazards</div>
                    <div class="ia-event-stat-value">${highHazards}</div>
                </article>
                <article class="ia-event-stat">
                    <div class="ia-event-stat-label">Standby Units</div>
                    <div class="ia-event-stat-value">${responders}</div>
                </article>
                <article class="ia-event-stat">
                    <div class="ia-event-stat-label">Next Event</div>
                    <div class="ia-event-stat-value">${escapeHtml(nextEvent ? formatDate(nextEvent.event_schedule) : 'None')}</div>
                </article>
            </section>
        `;
    };

    const renderRows = () => {
        if (state.loading) {
            return '<div class="ia-event-loading">Loading events...</div>';
        }

        const items = getFilteredItems().sort((a, b) => {
            const hazardDiff = (hazardOrder[b.on_site_safety_hazard_level] || 0) - (hazardOrder[a.on_site_safety_hazard_level] || 0);
            if (hazardDiff !== 0) {
                return hazardDiff;
            }
            return new Date(String(b.updated_at || b.received_at || 0).replace(' ', 'T')) - new Date(String(a.updated_at || a.received_at || 0).replace(' ', 'T'));
        });

        if (items.length === 0) {
            return '<div class="ia-event-empty">No event coordination records found.</div>';
        }

        const renderStatusTable = (item) => {
            if (state.dispatchStatusEventId !== Number(item.id)) return '';
            if (state.dispatchStatusLoading) return '<div class="ia-event-dispatch-status">Loading dispatch status...</div>';
            if (!state.dispatchStatusRows.length) return '<div class="ia-event-dispatch-status">No responder units have been dispatched to this event.</div>';
            return `<div class="ia-event-dispatch-status"><div class="ia-event-dispatch-table-wrap"><table class="ia-event-dispatch-table"><thead><tr><th>Unit</th><th>Type</th><th>Dispatch Status</th><th>Unit Status</th><th>Assigned</th></tr></thead><tbody>${state.dispatchStatusRows.map((unit) => `<tr><td>${escapeHtml(unit.identifier || `Unit ${unit.unit_id}`)}</td><td>${escapeHtml(unit.unit_type || 'Responder')}</td><td>${escapeHtml(unit.dispatch_status || 'assigned')}</td><td>${escapeHtml(unit.unit_status || 'unknown')}</td><td>${escapeHtml(formatDate(unit.assigned_at))}</td></tr>`).join('')}</tbody></table></div></div>`;
        };

        return items.map((item) => `
            <div class="ia-event-row-wrap">
            <article class="ia-event-row" data-event-details="${Number(item.id)}" tabindex="0">
                <div class="ia-event-main">
                    <div class="ia-event-kicker">
                        <span>${escapeHtml(item.coordination_id)}</span>
                        <span class="ia-event-chip hazard-${escapeHtml(item.on_site_safety_hazard_level)}">${escapeHtml(item.on_site_safety_hazard_level)}</span>
                        <span class="ia-event-chip">${escapeHtml(item.status)}</span>
                    </div>
                    <div class="ia-event-name">${escapeHtml(item.event_profile || 'Untitled event')}</div>
                    <div class="ia-event-meta">
                        <span><i class="fas fa-calendar-days"></i> ${escapeHtml(formatDate(item.event_schedule))}</span>
                        <span><i class="fas fa-user-shield"></i> ${Number(item.required_standby_responders || 0)} standby</span>
                        <span><i class="fas fa-network-wired"></i> ${escapeHtml(item.source_system || 'ERS')}</span>
                    </div>
                    ${item.event_location ? `<div class="ia-event-meta"><span><i class="fas fa-location-dot"></i> ${escapeHtml(item.event_location)}</span></div>` : ''}
                    ${item.emergency_contact_persons ? `<div class="ia-event-meta"><span><i class="fas fa-address-book"></i> ${escapeHtml(item.emergency_contact_persons)}</span></div>` : ''}
                    <div class="ia-event-flow">
                        <span class="${['active', 'standby', 'completed'].includes(item.status) ? 'is-done' : ''}">Profile</span>
                        <span class="${['standby', 'active', 'completed'].includes(item.status) ? 'is-done' : ''}">Standby</span>
                        <span class="${['active', 'completed'].includes(item.status) ? 'is-done' : ''}">Active</span>
                        <span class="${item.status === 'completed' ? 'is-done' : ''}">Closeout</span>
                    </div>
                    <button type="button" class="ia-event-dispatch-toggle" data-event-dispatch-status="${Number(item.id)}" aria-expanded="${state.dispatchStatusEventId === Number(item.id) ? 'true' : 'false'}"><i class="fas fa-chevron-${state.dispatchStatusEventId === Number(item.id) ? 'up' : 'down'}"></i> Dispatch Status</button>
                </div>
            </article>
            ${renderStatusTable(item)}
            </div>
        `).join('');
    };

    const renderForm = () => {
        const item = getEditingItem();
        const isEditing = Boolean(item);
        const defaults = {
            id: '',
            coordination_id: `COORD-${Date.now().toString().slice(-6)}`,
            event_profile: '',
            event_schedule: '',
            on_site_safety_hazard_level: 'medium',
            required_standby_responders: 0,
            emergency_contact_persons: '',
            status: 'active',
            source_system: 'ERS',
            ...item,
        };

        return `
            <aside class="ia-event-form-panel">
                <h3>${isEditing ? 'Update Event' : 'New Event'}</h3>
                <form class="ia-event-form" data-event-form>
                    <input type="hidden" name="id" value="${escapeHtml(defaults.id)}">
                    <div class="ia-event-field">
                        <label for="iaEventCoordinationId">Coordination ID</label>
                        <input id="iaEventCoordinationId" name="coordination_id" type="text" value="${escapeHtml(defaults.coordination_id)}" required>
                    </div>
                    <div class="ia-event-field">
                        <label for="iaEventProfile">Event Profile</label>
                        <input id="iaEventProfile" name="event_profile" type="text" value="${escapeHtml(defaults.event_profile)}" required>
                    </div>
                    <div class="ia-event-field">
                        <label for="iaEventSchedule">Event Schedule</label>
                        <input id="iaEventSchedule" name="event_schedule" type="datetime-local" value="${escapeHtml(toDateInput(defaults.event_schedule))}">
                    </div>
                    <div class="ia-event-form-row">
                        <div class="ia-event-field">
                            <label for="iaEventHazard">Hazard Level</label>
                            <select id="iaEventHazard" name="on_site_safety_hazard_level">
                                ${['low', 'medium', 'high', 'critical'].map((value) => `<option value="${value}" ${defaults.on_site_safety_hazard_level === value ? 'selected' : ''}>${value}</option>`).join('')}
                            </select>
                        </div>
                        <div class="ia-event-field">
                            <label for="iaEventResponders">Standby Responders</label>
                            <input id="iaEventResponders" name="required_standby_responders" type="number" min="0" value="${Number(defaults.required_standby_responders || 0)}">
                        </div>
                    </div>
                    <div class="ia-event-field">
                        <label for="iaEventContacts">Emergency Contacts</label>
                        <textarea id="iaEventContacts" name="emergency_contact_persons">${escapeHtml(defaults.emergency_contact_persons)}</textarea>
                    </div>
                    <div class="ia-event-form-row">
                        <div class="ia-event-field">
                            <label for="iaEventStatus">Status</label>
                            <select id="iaEventStatus" name="status">
                                ${['draft', 'active', 'standby', 'completed', 'cancelled'].map((value) => `<option value="${value}" ${defaults.status === value ? 'selected' : ''}>${value}</option>`).join('')}
                            </select>
                        </div>
                        <div class="ia-event-field">
                            <label for="iaEventSource">Source System</label>
                            <input id="iaEventSource" name="source_system" type="text" value="${escapeHtml(defaults.source_system || 'ERS')}">
                        </div>
                    </div>
                    <div class="ia-event-form-actions">
                        ${isEditing ? '<button type="button" class="ia-event-secondary" data-event-new><i class="fas fa-plus"></i> New</button>' : ''}
                        <button type="submit" class="ia-event-primary" data-event-submit><i class="fas fa-floppy-disk"></i> Save</button>
                    </div>
                </form>
                <div class="ia-event-readiness" aria-label="Event readiness checklist">
                    <div class="${defaults.event_schedule ? 'is-ready' : ''}"><i class="fas fa-calendar-check"></i><span>Schedule confirmed</span></div>
                    <div class="${Number(defaults.required_standby_responders || 0) > 0 ? 'is-ready' : ''}"><i class="fas fa-user-shield"></i><span>Standby responders set</span></div>
                    <div class="${defaults.emergency_contact_persons ? 'is-ready' : ''}"><i class="fas fa-address-book"></i><span>Emergency contacts ready</span></div>
                    <div class="${['high', 'critical'].includes(defaults.on_site_safety_hazard_level) ? 'is-alert' : 'is-ready'}"><i class="fas fa-triangle-exclamation"></i><span>Hazard reviewed</span></div>
                </div>
            </aside>
        `;
    };

    const renderEventDetailsPanel = () => {
        const item = state.items.find((event) => Number(event.id) === Number(state.detailId));
        if (!item) {
            return `<aside class="ia-event-form-panel ia-event-details-panel"><h3>Event Details</h3><p class="ia-event-details-empty">Select an event from the list to view its details and dispatch responders.</p></aside>`;
        }
        return `<aside class="ia-event-form-panel ia-event-details-panel">
            <h3>Event Details</h3>
            <p class="ia-event-details-sub">${escapeHtml(item.event_profile || 'Untitled event')}</p>
            <dl class="ia-event-detail-list">
                <dt>Coordination ID</dt><dd>${escapeHtml(item.coordination_id)}</dd>
                <dt>Event date &amp; time</dt><dd>${escapeHtml(formatDate(item.event_schedule))}</dd>
                <dt>Location</dt><dd>${escapeHtml(item.event_location || 'Not provided')}</dd>
                <dt>Hazard level</dt><dd>${escapeHtml(item.on_site_safety_hazard_level)}</dd>
                <dt>Standby responders</dt><dd>${Number(item.required_standby_responders || 0)}</dd>
                <dt>Status</dt><dd>${escapeHtml(item.status)}</dd>
                <dt>Source system</dt><dd>${escapeHtml(item.source_system || 'ERS')}</dd>
                <dt>Emergency contacts</dt><dd>${escapeHtml(item.emergency_contact_persons || 'Not provided')}</dd>
            </dl>
            <button type="button" class="ia-event-primary" data-event-dispatch="${Number(item.id)}"><i class="fas fa-ambulance"></i> Dispatch</button>
        </aside>`;
    };

    const renderEventDialog = () => {
        if (!state.dispatchId) return '';
        const item = getSelectedEvent();
        if (!item) return '';
        const assigned = state.assignments.filter((assignment) => assignment.dispatch_status === 'assigned');
        const units = state.availableUnits.map((unit) => `<label class="ia-event-unit-option"><input type="checkbox" name="event_unit_ids[]" value="${Number(unit.id)}"><span><strong>${escapeHtml(unit.identifier || `Unit ${unit.id}`)}</strong><small>${escapeHtml(unit.unit_type || 'Responder unit')} ${unit.driver_name ? `• ${escapeHtml(unit.driver_name)}` : ''}</small></span></label>`).join('');
        return `<div class="ia-event-dialog-backdrop"><section class="ia-event-dialog ia-event-dispatch-dialog" role="dialog" aria-modal="true" aria-label="Dispatch responders">
            <button type="button" class="ia-event-dialog-close" data-event-dialog-close aria-label="Close">&times;</button>
            <h3>Dispatch Responders</h3><p>${escapeHtml(item.event_profile || item.coordination_id)}</p>
            <div class="ia-event-assigned"><strong>Assigned to this event</strong>${assigned.length ? assigned.map((assignment) => `<span>${escapeHtml(assignment.identifier || `Unit ${assignment.unit_id}`)} • ${escapeHtml(assignment.unit_type || 'Responder')}</span>`).join('') : '<span>No units assigned yet.</span>'}</div>
            <form data-event-dispatch-form><div class="ia-event-unit-list">${state.dispatchLoading ? '<span>Loading units...</span>' : (units || '<span>No available responder units.</span>')}</div>
            <div class="ia-event-form-actions"><button type="button" class="ia-event-secondary" data-event-dialog-close>Cancel</button><button type="submit" class="ia-event-primary" ${state.dispatchLoading || !units ? 'disabled' : ''}>Dispatch</button></div></form>
        </section></div>`;
    };

    const render = () => {
        root.innerHTML = `
            <div class="ia-event-shell ${state.expanded ? 'is-open' : 'is-collapsed'}">
                <header class="ia-event-head">
                    <button type="button" class="ia-event-toggle" data-event-toggle aria-expanded="${state.expanded ? 'true' : 'false'}" aria-controls="iaEventDropdown">
                        <span class="ia-event-title-wrap">
                            <span class="ia-event-title">Event Coordination</span>
                            <span class="ia-event-sub">Shared event profiles, standby needs, hazards, and emergency contacts.</span>
                        </span>
                        <span class="ia-event-chevron" aria-hidden="true"><i class="fas fa-chevron-down"></i></span>
                    </button>
                </header>
                <div class="ia-event-dropdown" id="iaEventDropdown" ${state.expanded ? '' : 'hidden'}>
                    <div class="ia-event-toolbar">
                        <div class="ia-event-actions">
                            <button type="button" class="ia-event-icon" data-event-refresh title="Refresh events" aria-label="Refresh events">
                                <i class="fas fa-rotate-right"></i>
                            </button>
                        </div>
                    </div>
                    ${renderStats()}
                    ${state.error ? `<div class="ia-event-error">${escapeHtml(state.error)}</div>` : ''}
                    <div class="ia-event-body">
                        <section class="ia-event-list" aria-label="Event coordination records">
                            <div class="ia-event-list-head">
                                <div class="ia-event-search">
                                    <i class="fas fa-magnifying-glass"></i>
                                    <input type="search" value="${escapeHtml(state.search)}" placeholder="Search events" data-event-search>
                                </div>
                                <select class="ia-event-filter" data-event-status aria-label="Filter events by status">
                                    ${['all', 'active', 'standby', 'draft', 'completed', 'cancelled'].map((value) => `<option value="${value}" ${state.status === value ? 'selected' : ''}>${value}</option>`).join('')}
                                </select>
                            </div>
                            <div class="ia-event-rows">${renderRows()}</div>
                        </section>
                        ${renderEventDetailsPanel()}
                    </div>
                </div>
            </div>
            ${renderEventDialog()}
        `;
    };

    root.addEventListener('click', (event) => {
        const target = event.target.closest('button');
        if (!target) {
            const eventRow = event.target.closest('[data-event-details]');
            if (eventRow) {
                state.detailId = Number(eventRow.getAttribute('data-event-details'));
                state.dispatchId = null;
                render();
            }
            return;
        }

        if (target.matches('[data-event-toggle]')) {
            state.expanded = !state.expanded;
            render();
            return;
        }

        if (target.matches('[data-event-refresh]')) {
            loadEvents();
            return;
        }

        if (target.matches('[data-event-dialog-close]')) {
            state.dispatchId = null;
            state.assignments = [];
            render();
            return;
        }

        const dispatchId = target.getAttribute('data-event-dispatch');
        if (dispatchId) {
            openDispatch(Number(dispatchId));
            return;
        }

        const dispatchStatusId = target.getAttribute('data-event-dispatch-status');
        if (dispatchStatusId) {
            toggleDispatchStatus(Number(dispatchStatusId));
            return;
        }

    });

    root.addEventListener('input', (event) => {
        if (event.target.matches('[data-event-search]')) {
            state.search = event.target.value;
            render();
            const search = root.querySelector('[data-event-search]');
            if (search) {
                search.focus();
                search.setSelectionRange(state.search.length, state.search.length);
            }
        }
    });

    root.addEventListener('change', (event) => {
        if (event.target.matches('[data-event-status]')) {
            state.status = event.target.value;
            render();
        }
    });

    root.addEventListener('submit', (event) => {
        if (event.target.matches('[data-event-form]')) {
            event.preventDefault();
            saveEvent(event.target);
            return;
        }
        if (event.target.matches('[data-event-dispatch-form]')) {
            event.preventDefault();
            submitDispatch(event.target);
        }
    });

    render();
    loadEvents();
    window.setInterval(() => {
        if (document.visibilityState === 'visible') loadEvents();
    }, 30000);
})();
