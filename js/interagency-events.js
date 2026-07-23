(function () {
    const root = document.getElementById('iaEventCoordination');
    if (!root) {
        return;
    }

    const apiUrl = 'api/system_API/event_coordination.php';
    const state = {
        items: [],
        loading: true,
        error: '',
        editingId: null,
        search: '',
        status: 'all',
        expanded: false,
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

    const loadEvents = async () => {
        state.loading = true;
        state.error = '';
        render();

        try {
            const response = await fetch(`${apiUrl}?limit=80`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Unable to load event coordination records');
            }
            state.items = Array.isArray(data.items) ? data.items : [];
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

        return items.map((item) => `
            <article class="ia-event-row">
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
                    ${item.emergency_contact_persons ? `<div class="ia-event-meta"><span><i class="fas fa-address-book"></i> ${escapeHtml(item.emergency_contact_persons)}</span></div>` : ''}
                </div>
                <div class="ia-event-row-actions">
                    <button type="button" class="ia-event-icon" data-event-edit="${Number(item.id)}" title="Edit event" aria-label="Edit event">
                        <i class="fas fa-pen"></i>
                    </button>
                    <button type="button" class="ia-event-icon" data-event-send="${Number(item.id)}" title="Send" aria-label="Send">
                        <i class="fas fa-paper-plane"></i>
                    </button>
                </div>
            </article>
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
            </aside>
        `;
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
                            <button type="button" class="ia-event-primary" data-event-new>
                                <i class="fas fa-plus"></i> New Event
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
                        ${renderForm()}
                    </div>
                </div>
            </div>
        `;
    };

    root.addEventListener('click', (event) => {
        const target = event.target.closest('button');
        if (!target) {
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

        if (target.matches('[data-event-new]')) {
            state.editingId = null;
            render();
            return;
        }

        const editId = target.getAttribute('data-event-edit');
        if (editId) {
            state.editingId = Number(editId);
            render();
            return;
        }

        const sendId = target.getAttribute('data-event-send');
        if (sendId) {
            sendEvent(Number(sendId));
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
        }
    });

    render();
    loadEvents();
})();
