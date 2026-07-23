(function () {
    const root = document.getElementById('iaAnonymousTipInbox');
    if (!root) {
        return;
    }

    const apiUrl = 'api/system_API/?action=anonymous_tip';
    const statuses = ['all', 'new', 'reviewing', 'verified', 'dismissed', 'converted_to_incident'];
    const editableStatuses = statuses.filter((status) => status !== 'all');
    const state = {
        items: [],
        loading: true,
        error: '',
        selectedId: null,
        search: '',
        status: 'all',
        expanded: false,
    };

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const statusLabel = (status) => String(status || 'new').replace(/_/g, ' ');

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

    const filteredItems = () => state.items.filter((item) => {
        const statusMatch = state.status === 'all' || item.status === state.status;
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
                    status: form.elements.status.value,
                    outcome: form.elements.outcome.value.trim(),
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Unable to update anonymous tip');
            }
            await loadTips();
        } catch (error) {
            state.error = error.message || 'Unable to update anonymous tip';
            render();
        }
    };

    const renderStats = () => {
        const newCount = state.items.filter((item) => item.status === 'new').length;
        const reviewing = state.items.filter((item) => item.status === 'reviewing').length;
        const verified = state.items.filter((item) => item.status === 'verified').length;
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
                            <span class="ia-tip-chip status-${escapeHtml(item.status || 'new')}">${escapeHtml(statusLabel(item.status))}</span>
                        </span>
                        <span class="ia-tip-location">${escapeHtml(item.location || 'No location')}</span>
                        <span class="ia-tip-description">${escapeHtml(item.tip_description || 'No description')}</span>
                        <span class="ia-tip-meta">
                            <span><i class="fas fa-clock"></i> ${escapeHtml(formatDate(item.tip_datetime))}</span>
                            <span><i class="fas fa-network-wired"></i> ${escapeHtml(item.source_system || 'Group 6')}</span>
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
        const evidence = photo
            ? (isEvidenceLink(photo)
                ? `<a class="ia-tip-secondary" href="${escapeHtml(photo)}" target="_blank" rel="noopener"><i class="fas fa-image"></i> Evidence</a>`
                : `<div class="ia-tip-detail-value">${escapeHtml(photo)}</div>`)
            : '<div class="ia-tip-detail-value">None</div>';

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
                </div>
                <form data-tip-status-form>
                    <div class="ia-tip-detail-grid ia-tip-detail-form-grid">
                        <div class="ia-tip-detail-item">
                            <label class="ia-tip-detail-label" for="iaTipStatus">Status</label>
                            <select id="iaTipStatus" name="status">
                                ${editableStatuses.map((status) => `<option value="${status}" ${item.status === status ? 'selected' : ''}>${statusLabel(status)}</option>`).join('')}
                            </select>
                        </div>
                        <div class="ia-tip-detail-item">
                            <label class="ia-tip-detail-label" for="iaTipOutcome">Outcome</label>
                            <textarea id="iaTipOutcome" name="outcome">${escapeHtml(item.outcome || '')}</textarea>
                        </div>
                    </div>
                    <div class="ia-tip-detail-actions">
                        <button type="submit" class="ia-tip-primary" data-tip-save><i class="fas fa-floppy-disk"></i> Save</button>
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

        const row = event.target.closest('[data-tip-select]');
        if (row) {
            state.selectedId = Number(row.getAttribute('data-tip-select'));
            render();
        }
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
            saveStatus(event.target);
        }
    });

    render();
    loadTips();
})();
