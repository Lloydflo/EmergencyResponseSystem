(function () {
    const root = document.getElementById('iaAnonymousTipInbox');
    if (!root) {
        return;
    }

    const apiUrl = 'api/system_API/?action=anonymous_tip';
    const statuses = ['all', 'pending', 'new', 'reviewing', 'verified', 'dismissed', 'converted_to_incident'];
    const editableStatuses = statuses.filter((status) => !['all', 'pending'].includes(status));
    const state = {
        items: [],
        loading: true,
        error: '',
        notice: '',
        action: '',
        selectedId: null,
        search: '',
        status: 'all',
        expanded: true,
    };

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const statusLabel = (status) => String(status || 'new')
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (letter) => letter.toUpperCase());

    const statusOf = (item) => String(item?.status || 'new').trim().toLowerCase();

    const actionLabel = {
        reviewing: 'Marked for review.',
        verified: 'Verified as actionable.',
        dismissed: 'Dismissed after review.',
    };

    const incidentTypes = ['other', 'medical', 'fire', 'police', 'traffic', 'rescue'];
    const priorities = ['medium', 'low', 'high', 'critical'];

    const formatDate = (value) => {
        if (!value) {
            return 'No date';
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

    const isEvidenceLink = (value) => {
        const text = String(value || '').trim();
        return /^https?:\/\//i.test(text) || /^data:image\//i.test(text) || /\.(png|jpe?g|gif|webp)$/i.test(text);
    };

    const incidentUrl = (referenceNo) => {
        const ref = encodeURIComponent(String(referenceNo || '').trim());
        const path = String(window.location.pathname || '').toLowerCase();
        if (path.includes('/admin/')) {
            return `../dispatcher/incident.php?code=${ref}`;
        }
        if (path.includes('/dispatcher/')) {
            return `incident.php?code=${ref}`;
        }
        return `dispatcher/incident.php?code=${ref}`;
    };

    const notifyCountsChanged = () => {
        window.dispatchEvent(new CustomEvent('ers:anonymous-tips-updated'));
    };

    const notifyIncidentQueueChanged = (incident) => {
        const detail = {
            source: 'anonymous_tip',
            incidentId: Number(incident?.id || 0),
            referenceNo: String(incident?.reference_no || ''),
            changedAt: Date.now(),
        };
        window.dispatchEvent(new CustomEvent('ers:incident-queue-updated', { detail }));
        try {
            window.localStorage.setItem('ers_incidents_changed', JSON.stringify(detail));
        } catch (_) {}
    };

    const filteredItems = () => state.items.filter((item) => {
        const itemStatus = statusOf(item);
        const statusMatch = state.status === 'all' || itemStatus === state.status;
        const haystack = [
            item.tip_id,
            item.location,
            item.tip_description,
            item.source_system,
            item.outcome,
        ].join(' ').toLowerCase();
        return statusMatch && haystack.includes(state.search.toLowerCase());
    });

    const selectedItem = () => {
        if (!state.selectedId && state.items.length > 0) {
            state.selectedId = Number(state.items[0].id);
        }
        return state.items.find((item) => Number(item.id) === Number(state.selectedId)) || null;
    };

    const loadTips = async () => {
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
                throw new Error(data.error || 'Unable to load anonymous tips');
            }
            state.items = Array.isArray(data.items) ? data.items : [];
            if (!state.items.some((item) => Number(item.id) === Number(state.selectedId))) {
                state.selectedId = state.items.length > 0 ? Number(state.items[0].id) : null;
            }
        } catch (error) {
            state.error = error.message || 'Unable to load anonymous tips';
        } finally {
            state.loading = false;
            render();
        }
    };

    const saveStatus = async (form) => {
        const item = selectedItem();
        if (!item) {
            return;
        }
        const submit = form.querySelector('[data-tip-save]');
        const selectedStatus = form.elements.status.value;
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
                body: JSON.stringify({
                    action: 'update_status',
                    id: item.id,
                    status: selectedStatus,
                    outcome: form.elements.outcome.value.trim(),
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Unable to update anonymous tip');
            }
            await loadTips();
            state.notice = `${item.tip_id || 'Tip'} saved as ${statusLabel(selectedStatus)}.`;
            render();
            notifyCountsChanged();
        } catch (error) {
            state.error = error.message || 'Unable to update anonymous tip';
            render();
        }
    };

    const quickStatus = async (status, outcome) => {
        const item = selectedItem();
        if (!item) {
            return;
        }
        if (statusOf(item) === 'converted_to_incident') {
            state.error = 'Converted tips are locked to their linked incident.';
            render();
            return;
        }

        state.action = status;
        state.error = '';
        state.notice = '';
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
                    action: 'update_status',
                    id: item.id,
                    status,
                    outcome: outcome || actionLabel[status] || item.outcome || '',
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Unable to update anonymous tip');
            }
            await loadTips();
            state.notice = `${item.tip_id || 'Tip'} ${String(actionLabel[status] || 'updated.').toLowerCase()}`;
            render();
            notifyCountsChanged();
        } catch (error) {
            state.error = error.message || 'Unable to update anonymous tip';
            render();
        } finally {
            state.action = '';
            render();
        }
    };

    const convertTip = async () => {
        const item = selectedItem();
        if (!item || statusOf(item) === 'converted_to_incident') {
            return;
        }

        const form = root.querySelector('[data-tip-status-form]');
        state.action = 'converted_to_incident';
        state.error = '';
        state.notice = '';
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
                    action: 'convert_to_incident',
                    id: item.id,
                    incident_type: form?.elements.incident_type?.value || 'other',
                    priority: form?.elements.priority?.value || 'medium',
                    outcome: form?.elements.outcome?.value.trim() || '',
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Unable to convert anonymous tip');
            }
            const reference = data.incident && data.incident.reference_no ? data.incident.reference_no : 'incident';
            await loadTips();
            state.notice = `${item.tip_id || 'Tip'} converted to ${reference}.`;
            render();
            notifyCountsChanged();
            notifyIncidentQueueChanged(data.incident || null);
        } catch (error) {
            state.error = error.message || 'Unable to convert anonymous tip';
            render();
        } finally {
            state.action = '';
            render();
        }
    };

    const renderStats = () => {
        const newCount = state.items.filter((item) => ['pending', 'new'].includes(statusOf(item))).length;
        const reviewing = state.items.filter((item) => statusOf(item) === 'reviewing').length;
        const verified = state.items.filter((item) => statusOf(item) === 'verified').length;
        const evidence = state.items.filter((item) => String(item.photo_of_evidence || '').trim() !== '').length;

        return `
            <section class="ia-tip-stats" aria-label="Anonymous tip summary">
                <article class="ia-tip-stat">
                    <div class="ia-tip-stat-label">New Tips</div>
                    <div class="ia-tip-stat-value">${newCount}</div>
                </article>
                <article class="ia-tip-stat">
                    <div class="ia-tip-stat-label">Reviewing</div>
                    <div class="ia-tip-stat-value">${reviewing}</div>
                </article>
                <article class="ia-tip-stat">
                    <div class="ia-tip-stat-label">Verified</div>
                    <div class="ia-tip-stat-value">${verified}</div>
                </article>
                <article class="ia-tip-stat">
                    <div class="ia-tip-stat-label">With Evidence</div>
                    <div class="ia-tip-stat-value">${evidence}</div>
                </article>
            </section>
        `;
    };

    const renderRows = () => {
        if (state.loading) {
            return '<div class="ia-tip-loading">Loading anonymous tips...</div>';
        }

        const items = filteredItems();
        if (items.length === 0) {
            return '<div class="ia-tip-empty">No anonymous tips found.</div>';
        }

        return items.map((item) => {
            const photo = String(item.photo_of_evidence || '').trim();
            const itemStatus = statusOf(item);
            const evidenceButton = photo
                ? (isEvidenceLink(photo)
                    ? `<a class="ia-tip-icon ia-tip-evidence" href="${escapeHtml(photo)}" target="_blank" rel="noopener" title="View evidence" aria-label="View evidence"><i class="fas fa-image"></i></a>`
                    : '<span class="ia-tip-chip">Evidence saved</span>')
                : '';

            return `
                <article class="ia-tip-row ${Number(item.id) === Number(state.selectedId) ? 'is-active' : ''}" data-tip-select="${Number(item.id)}" role="button" tabindex="0">
                    <span class="ia-tip-main">
                        <span class="ia-tip-kicker">
                            <span>${escapeHtml(item.tip_id)}</span>
                            <span class="ia-tip-chip status-${escapeHtml(itemStatus)}">${escapeHtml(statusLabel(itemStatus))}</span>
                        </span>
                        <span class="ia-tip-location">${escapeHtml(item.location || 'No location')}</span>
                        <span class="ia-tip-description">${escapeHtml(item.tip_description || 'No description')}</span>
                        <span class="ia-tip-meta">
                            <span><i class="fas fa-clock"></i> ${escapeHtml(formatDate(item.tip_datetime))}</span>
                            <span><i class="fas fa-network-wired"></i> ${escapeHtml(item.source_system || 'Group 6')}</span>
                        </span>
                        <span class="ia-tip-flow">
                            <span class="${['reviewing', 'verified', 'converted_to_incident'].includes(itemStatus) ? 'is-done' : ''}">Review</span>
                            <span class="${['verified', 'converted_to_incident'].includes(itemStatus) ? 'is-done' : ''}">Verify</span>
                            <span class="${itemStatus === 'converted_to_incident' ? 'is-done' : ''}">Convert</span>
                        </span>
                    </span>
                    <span>${evidenceButton}</span>
                </article>
            `;
        }).join('');
    };

    const renderDetail = () => {
        const item = selectedItem();
        if (!item) {
            return `
                <aside class="ia-tip-detail">
                    <h3>Tip Details</h3>
                    <div class="ia-tip-empty">Select a tip to review.</div>
                </aside>
            `;
        }

        const photo = String(item.photo_of_evidence || '').trim();
        const itemStatus = statusOf(item);
        const convertedReference = String(item.converted_reference_no || '').trim();
        const convertedId = Number(item.converted_incident_id || 0);
        const isConverted = itemStatus === 'converted_to_incident';
        const evidence = photo
            ? (isEvidenceLink(photo)
                ? `<a class="ia-tip-secondary" href="${escapeHtml(photo)}" target="_blank" rel="noopener"><i class="fas fa-image"></i> Evidence</a>`
                : `<div class="ia-tip-detail-value">${escapeHtml(photo)}</div>`)
            : '<div class="ia-tip-detail-value">None</div>';
        const incidentLink = convertedReference
            ? `<a class="ia-tip-secondary" href="${escapeHtml(incidentUrl(convertedReference))}"><i class="fas fa-arrow-up-right-from-square"></i> ${escapeHtml(convertedReference)}</a>`
            : '<div class="ia-tip-detail-value">Not converted</div>';
        const actionDisabled = (status) => state.action !== '' || isConverted || itemStatus === status;
        const actionButtonText = (status, label) => state.action === status
            ? '<i class="fas fa-spinner fa-spin"></i> Working'
            : label;
        const statusOptions = editableStatuses
            .filter((status) => status !== 'converted_to_incident' || itemStatus === 'converted_to_incident')
            .map((status) => `<option value="${status}" ${itemStatus === status ? 'selected' : ''}>${statusLabel(status)}</option>`)
            .join('');

        return `
            <aside class="ia-tip-detail">
                <h3>Tip Details</h3>
                <div class="ia-tip-detail-grid">
                    <div class="ia-tip-detail-item">
                        <div class="ia-tip-detail-label">Tip ID</div>
                        <div class="ia-tip-detail-value">${escapeHtml(item.tip_id)}</div>
                    </div>
                    <div class="ia-tip-detail-item">
                        <div class="ia-tip-detail-label">Date & Time</div>
                        <div class="ia-tip-detail-value">${escapeHtml(formatDate(item.tip_datetime))}</div>
                    </div>
                    <div class="ia-tip-detail-item">
                        <div class="ia-tip-detail-label">Location</div>
                        <div class="ia-tip-detail-value">${escapeHtml(item.location || 'No location')}</div>
                    </div>
                    <div class="ia-tip-detail-item">
                        <div class="ia-tip-detail-label">Description</div>
                        <div class="ia-tip-detail-value">${escapeHtml(item.tip_description || 'No description')}</div>
                    </div>
                    <div class="ia-tip-detail-item">
                        <div class="ia-tip-detail-label">Photo of Evidence</div>
                        ${evidence}
                    </div>
                    <div class="ia-tip-detail-item">
                        <div class="ia-tip-detail-label">Linked Incident</div>
                        ${incidentLink}
                        ${convertedId > 0 ? `<div class="ia-tip-detail-note">Incident #${convertedId}${item.converted_incident_status ? `, ${escapeHtml(statusLabel(item.converted_incident_status))}` : ''}</div>` : ''}
                    </div>
                </div>
                <div class="ia-tip-quick-actions" aria-label="Tip quick actions">
                    <button type="button" class="ia-tip-secondary" data-tip-quick-status="reviewing" ${actionDisabled('reviewing') ? 'disabled' : ''}>${actionButtonText('reviewing', '<i class="fas fa-magnifying-glass"></i> Review')}</button>
                    <button type="button" class="ia-tip-secondary" data-tip-quick-status="verified" ${actionDisabled('verified') ? 'disabled' : ''}>${actionButtonText('verified', '<i class="fas fa-check-circle"></i> Verify')}</button>
                    ${isConverted && convertedReference
                        ? `<a class="ia-tip-secondary" href="${escapeHtml(incidentUrl(convertedReference))}"><i class="fas fa-arrow-up-right-from-square"></i> Open Incident</a>`
                        : `<button type="button" class="ia-tip-secondary" data-tip-convert ${state.action !== '' ? 'disabled' : ''}>${actionButtonText('converted_to_incident', '<i class="fas fa-file-circle-plus"></i> Convert')}</button>`}
                    <button type="button" class="ia-tip-secondary" data-tip-quick-status="dismissed" ${actionDisabled('dismissed') ? 'disabled' : ''}>${actionButtonText('dismissed', '<i class="fas fa-ban"></i> Dismiss')}</button>
                </div>
                ${isConverted ? '<div class="ia-tip-lock-note">This tip is already converted, so review buttons are locked.</div>' : ''}
                <form data-tip-status-form>
                    <div class="ia-tip-detail-grid ia-tip-detail-form-grid">
                        <div class="ia-tip-detail-item">
                            <label class="ia-tip-detail-label" for="iaTipStatus">Status</label>
                            <select id="iaTipStatus" name="status" ${isConverted ? 'disabled' : ''}>
                                ${statusOptions}
                            </select>
                        </div>
                        <div class="ia-tip-detail-item">
                            <label class="ia-tip-detail-label" for="iaTipIncidentType">Incident Type</label>
                            <select id="iaTipIncidentType" name="incident_type" ${isConverted ? 'disabled' : ''}>
                                ${incidentTypes.map((type) => `<option value="${type}">${statusLabel(type)}</option>`).join('')}
                            </select>
                        </div>
                        <div class="ia-tip-detail-item">
                            <label class="ia-tip-detail-label" for="iaTipPriority">Priority</label>
                            <select id="iaTipPriority" name="priority" ${isConverted ? 'disabled' : ''}>
                                ${priorities.map((priority) => `<option value="${priority}" ${priority === 'medium' ? 'selected' : ''}>${statusLabel(priority)}</option>`).join('')}
                            </select>
                        </div>
                        <div class="ia-tip-detail-item">
                            <label class="ia-tip-detail-label" for="iaTipOutcome">Outcome</label>
                            <textarea id="iaTipOutcome" name="outcome" ${isConverted ? 'readonly' : ''}>${escapeHtml(item.outcome || '')}</textarea>
                        </div>
                    </div>
                    <div class="ia-tip-detail-actions">
                        <button type="submit" class="ia-tip-primary" data-tip-save ${state.action !== '' || isConverted ? 'disabled' : ''}><i class="fas fa-floppy-disk"></i> Save</button>
                    </div>
                </form>
            </aside>
        `;
    };

    const render = () => {
        root.innerHTML = `
            <div class="ia-tip-shell ${state.expanded ? 'is-open' : 'is-collapsed'}">
                <header class="ia-tip-head">
                    <button type="button" class="ia-tip-toggle" data-tip-toggle aria-expanded="${state.expanded ? 'true' : 'false'}" aria-controls="iaTipDropdown">
                        <span class="ia-tip-title">
                            <span class="ia-tip-title-text">Anonymous Tip Inbox</span>
                            <span class="ia-tip-sub">Incoming anonymous tips, evidence, review status, and outcomes.</span>
                        </span>
                        <span class="ia-tip-chevron" aria-hidden="true"><i class="fas fa-chevron-down"></i></span>
                    </button>
                </header>
                <div class="ia-tip-dropdown" id="iaTipDropdown" ${state.expanded ? '' : 'hidden'}>
                    <div class="ia-tip-toolbar">
                        <button type="button" class="ia-tip-icon" data-tip-refresh title="Refresh tips" aria-label="Refresh tips">
                            <i class="fas fa-rotate-right"></i>
                        </button>
                    </div>
                    ${renderStats()}
                    ${state.notice ? `<div class="ia-tip-notice">${escapeHtml(state.notice)}</div>` : ''}
                    ${state.error ? `<div class="ia-tip-error">${escapeHtml(state.error)}</div>` : ''}
                    <div class="ia-tip-body">
                        <section class="ia-tip-list" aria-label="Anonymous tip records">
                            <div class="ia-tip-list-head">
                                <div class="ia-tip-search">
                                    <i class="fas fa-magnifying-glass"></i>
                                    <input type="search" value="${escapeHtml(state.search)}" placeholder="Search tips" data-tip-search>
                                </div>
                                <select class="ia-tip-filter" data-tip-filter aria-label="Filter tips by status">
                                    ${statuses.map((status) => `<option value="${status}" ${state.status === status ? 'selected' : ''}>${statusLabel(status)}</option>`).join('')}
                                </select>
                            </div>
                            <div class="ia-tip-rows">${renderRows()}</div>
                        </section>
                        ${renderDetail()}
                    </div>
                </div>
            </div>
        `;
    };

    root.addEventListener('click', (event) => {
        const evidence = event.target.closest('.ia-tip-evidence');
        if (evidence) {
            event.stopPropagation();
            return;
        }

        const toggle = event.target.closest('[data-tip-toggle]');
        if (toggle) {
            state.expanded = !state.expanded;
            render();
            return;
        }

        const refresh = event.target.closest('[data-tip-refresh]');
        if (refresh) {
            loadTips();
            return;
        }

        const convert = event.target.closest('[data-tip-convert]');
        if (convert) {
            convertTip();
            return;
        }

        const quick = event.target.closest('[data-tip-quick-status]');
        if (quick) {
            const status = String(quick.getAttribute('data-tip-quick-status') || 'reviewing');
            quickStatus(status, actionLabel[status]);
            return;
        }

        const row = event.target.closest('[data-tip-select]');
        if (row) {
            state.selectedId = Number(row.getAttribute('data-tip-select'));
            render();
        }
    });

    root.addEventListener('keydown', (event) => {
        const row = event.target.closest('[data-tip-select]');
        if (!row || !['Enter', ' '].includes(event.key)) {
            return;
        }

        event.preventDefault();
        state.selectedId = Number(row.getAttribute('data-tip-select'));
        render();
    });

    root.addEventListener('input', (event) => {
        if (event.target.matches('[data-tip-search]')) {
            state.search = event.target.value;
            render();
            const search = root.querySelector('[data-tip-search]');
            if (search) {
                search.focus();
                search.setSelectionRange(state.search.length, state.search.length);
            }
        }
    });

    root.addEventListener('change', (event) => {
        if (event.target.matches('[data-tip-filter]')) {
            state.status = event.target.value;
            render();
        }
    });

    root.addEventListener('submit', (event) => {
        if (event.target.matches('[data-tip-status-form]')) {
            event.preventDefault();
            if (event.target.elements.status.disabled) {
                return;
            }
            saveStatus(event.target);
        }
    });

    render();
    loadTips();
})();
