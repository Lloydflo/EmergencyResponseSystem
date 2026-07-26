(function () {
    const root = document.getElementById('iaExternalIncidentInbox');
    if (!root) return;

    const listEl = document.getElementById('iaExternalInboxList');
    const badgeEl = document.getElementById('iaExternalInboxBadge');
    const countEl = document.getElementById('iaExternalInboxCount');
    const refreshBtn = document.getElementById('iaExternalInboxRefresh');
    const toggleBtn = document.getElementById('iaExternalInboxToggle');
    const dropdownEl = document.getElementById('iaExternalInboxDropdown');
    const detailModal = document.getElementById('incidentDetailModal');
    const detailTitle = document.getElementById('incidentDetailModalTitle');
    const detailSubtitle = document.getElementById('incidentDetailModalSubtitle');
    const detailBody = document.getElementById('incidentDetailModalBody');
    const DISMISSED_KEY = 'ers.interagencyExternalInbox.dismissed.v1';
    const CLOSED_STATUSES = new Set(['resolved', 'cancelled', 'closed', 'rejected']);
    const state = {
        items: [],
        loading: false,
        expanded: true,
        dismissed: loadDismissedKeys()
    };

    function escapeHtml(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function escapeAttr(value) {
        return String(value || '')
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function loadDismissedKeys() {
        try {
            const raw = window.localStorage.getItem(DISMISSED_KEY);
            const list = JSON.parse(raw || '[]');
            return new Set(Array.isArray(list) ? list.map((item) => String(item)) : []);
        } catch (_) {
            return new Set();
        }
    }

    function saveDismissedKeys() {
        try {
            window.localStorage.setItem(DISMISSED_KEY, JSON.stringify(Array.from(state.dismissed).slice(-100)));
        } catch (_) {}
    }

    function itemKey(item) {
        return String(
            item.transfer_log_id ||
            item.transfer_id ||
            item.call_id_external ||
            item.reference_no ||
            item.incident_id ||
            ''
        ).trim();
    }

    function normalizePriority(value) {
        const priority = String(value || 'medium').trim().toLowerCase();
        if (priority === 'critical') return 'critical';
        if (priority === 'urgent') return 'urgent';
        if (priority === 'high') return 'high';
        if (priority === 'low') return 'low';
        return 'medium';
    }

    function isLiveCall(item) {
        const transferType = String(item.transfer_type || '').trim().toLowerCase();
        return transferType === 'live_call' || !!String(item.room || '').trim();
    }

    function displayType(value) {
        const raw = String(value || 'Emergency').replace(/_/g, ' ').trim();
        return raw ? raw.charAt(0).toUpperCase() + raw.slice(1) : 'Emergency';
    }

    function displayTime(value) {
        const date = value ? new Date(value) : null;
        if (!date || Number.isNaN(date.getTime())) return 'Time not provided';
        return date.toLocaleString([], {
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    }

    function visibleItems(items) {
        const seen = new Set();
        return (Array.isArray(items) ? items : [])
            .filter((item) => item && typeof item === 'object')
            .filter((item) => {
                const key = itemKey(item);
                if (!key || seen.has(key) || state.dismissed.has(key)) return false;
                seen.add(key);
                const status = String(item.incident_status || '').trim().toLowerCase();
                return !CLOSED_STATUSES.has(status);
            })
            .sort((a, b) => {
                const bLog = Number(b.transfer_log_id || 0);
                const aLog = Number(a.transfer_log_id || 0);
                if (bLog !== aLog) return bLog - aLog;
                return Date.parse(b.transferred_at || '') - Date.parse(a.transferred_at || '');
            })
            .slice(0, 12);
    }

    function setCounts(count) {
        if (countEl) countEl.textContent = String(count);
        if (badgeEl) {
            badgeEl.textContent = state.loading
                ? 'Loading'
                : (count === 1 ? '1 Pending' : count + ' Pending');
        }
    }

    function render() {
        if (!listEl) return;
        const items = visibleItems(state.items);
        setCounts(items.length);

        if (state.loading && !items.length) {
            listEl.innerHTML = '<div class="ia-external-inbox-empty">Loading external incidents...</div>';
            return;
        }

        if (!items.length) {
            listEl.innerHTML = '<div class="ia-external-inbox-empty">No incoming external incidents waiting right now.</div>';
            return;
        }

        listEl.innerHTML = items.map(renderCard).join('');
    }

    function setExpanded(expanded) {
        state.expanded = Boolean(expanded);
        root.classList.toggle('is-open', state.expanded);
        root.classList.toggle('is-collapsed', !state.expanded);
        if (toggleBtn) {
            toggleBtn.setAttribute('aria-expanded', state.expanded ? 'true' : 'false');
        }
        if (dropdownEl) {
            dropdownEl.hidden = !state.expanded;
        }
    }

    function renderCard(item) {
        const key = itemKey(item);
        const incidentId = Number(item.incident_id || 0);
        const live = isLiveCall(item);
        const priority = normalizePriority(item.priority);
        const label = live ? 'Live call' : 'Report';
        const source = String(item.source_system || 'External System').trim();
        const reference = String(item.reference_no || item.transfer_id || ('Incident #' + incidentId)).trim();
        const type = displayType(item.type);
        const location = String(item.location || 'Location not provided').trim();
        const description = String(item.description || 'No description provided.').trim();
        const status = String(item.incident_status || 'pending').trim();

        return `
            <article class="ia-external-card ${live ? 'is-live' : 'is-report'} priority-${escapeAttr(priority)}" data-external-key="${escapeAttr(key)}">
                <div class="ia-external-card-head">
                    <div>
                        <p class="ia-external-card-title">${escapeHtml(reference)}</p>
                        <p class="ia-external-card-source">${escapeHtml(source)} - ${escapeHtml(displayTime(item.transferred_at))}</p>
                    </div>
                    <span class="ia-external-pill ${live ? 'live' : ''}">${escapeHtml(label)}</span>
                </div>
                <div class="ia-external-card-meta">
                    <span class="ia-external-pill priority-${escapeAttr(priority)}">${escapeHtml(priority)}</span>
                    <span class="ia-external-pill">${escapeHtml(type)}</span>
                    <span class="ia-external-pill">${escapeHtml(status || 'pending')}</span>
                </div>
                <p class="ia-external-card-location"><i class="fas fa-location-dot"></i> ${escapeHtml(location)}</p>
                <p class="ia-external-card-desc">${escapeHtml(description.length > 150 ? description.slice(0, 147) + '...' : description)}</p>
                <div class="ia-external-next">
                    <span><i class="fas fa-inbox"></i> Received</span>
                    <span><i class="fas fa-magnifying-glass-location"></i> Review match</span>
                    <span><i class="fas fa-link"></i> Link or convert</span>
                </div>
                <div class="ia-external-card-actions">
                    <button type="button" class="ia-external-inbox-btn primary" data-external-action="view" data-incident-id="${escapeAttr(incidentId)}">
                        <i class="fas fa-eye"></i> View
                    </button>
                    ${live ? `
                        <button type="button" class="ia-external-inbox-btn" data-external-action="call">
                            <i class="fas fa-headset"></i> Call Desk
                        </button>
                    ` : ''}
                    <button type="button" class="ia-external-inbox-btn" data-external-action="dismiss">
                        <i class="fas fa-check"></i> Hide
                    </button>
                </div>
            </article>
        `;
    }

    async function loadInbox() {
        state.loading = true;
        render();
        if (refreshBtn) refreshBtn.disabled = true;
        try {
            const response = await fetch('api/incoming_transfers.php?limit=20', {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const data = await response.json();
            state.items = data && data.ok && Array.isArray(data.transfers) ? data.transfers : [];
        } catch (_) {
            state.items = [];
            if (listEl) {
                listEl.innerHTML = '<div class="ia-external-inbox-empty">Unable to load external inbox.</div>';
            }
        } finally {
            state.loading = false;
            if (refreshBtn) refreshBtn.disabled = false;
            render();
        }
    }

    function closeDetailModal() {
        if (!detailModal) return;
        detailModal.classList.remove('show');
        detailModal.setAttribute('aria-hidden', 'true');
        detailModal.hidden = true;
        document.body.style.overflow = '';
    }

    function openDetailModal() {
        if (!detailModal) return;
        detailModal.hidden = false;
        detailModal.setAttribute('aria-hidden', 'false');
        detailModal.classList.add('show');
        document.body.style.overflow = 'hidden';
    }

    function renderIncidentDetail(incident) {
        if (!detailBody) return;
        const rows = [];
        const addRow = (label, value) => {
            if (value === null || value === undefined || String(value).trim() === '') return;
            rows.push([label, String(value)]);
        };
        addRow('Reference', incident.reference_no);
        addRow('Type', incident.type);
        addRow('Priority', incident.priority);
        addRow('Status', incident.status);
        addRow('Title', incident.title);
        addRow('Location', incident.location_address);
        addRow('Description', incident.description);
        addRow('Caller', incident.caller_name);
        addRow('Assigned unit', incident.assigned_unit_identifier);
        addRow('Created at', incident.created_at);

        detailBody.innerHTML = `
            <div class="ia-incident-detail-grid">
                <div class="ia-incident-detail-row">
                    <span class="ia-incident-detail-key">Incident ID</span>
                    <p class="ia-incident-detail-value">#${escapeHtml(incident.id)}</p>
                </div>
                ${rows.map(([label, value]) => `
                    <div class="ia-incident-detail-row">
                        <span class="ia-incident-detail-key">${escapeHtml(label)}</span>
                        <p class="ia-incident-detail-value">${escapeHtml(value)}</p>
                    </div>
                `).join('')}
            </div>
        `;
    }

    async function viewIncident(incidentId) {
        if (!incidentId || !detailModal || !detailBody) return;
        if (detailTitle) detailTitle.textContent = 'External Incident Details';
        if (detailSubtitle) detailSubtitle.textContent = 'Incident #' + incidentId;
        detailBody.innerHTML = '<div class="ia-media-empty">Loading incident details...</div>';
        openDetailModal();
        try {
            const response = await fetch('api/incident_details.php?id=' + encodeURIComponent(String(incidentId)), {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const data = await response.json();
            if (!data || !data.ok || !data.incident) {
                detailBody.innerHTML = '<div class="ia-media-empty">Incident not found.</div>';
                return;
            }
            renderIncidentDetail(data.incident);
        } catch (_) {
            detailBody.innerHTML = '<div class="ia-media-empty">Unable to load incident details.</div>';
        }
    }

    function dismissCard(card) {
        const key = card ? String(card.dataset.externalKey || '').trim() : '';
        if (!key) return;
        state.dismissed.add(key);
        saveDismissedKeys();
        render();
    }

    function bindEvents() {
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => setExpanded(!state.expanded));
        }
        if (refreshBtn) {
            refreshBtn.addEventListener('click', loadInbox);
        }
        if (listEl) {
            listEl.addEventListener('click', (event) => {
                const button = event.target.closest('[data-external-action]');
                if (!button) return;
                const card = button.closest('[data-external-key]');
                const action = button.dataset.externalAction || '';
                if (action === 'view') {
                    viewIncident(Number(button.dataset.incidentId || 0));
                    return;
                }
                if (action === 'call') {
                    window.location.href = 'dispatcher/call.php';
                    return;
                }
                if (action === 'dismiss') {
                    dismissCard(card);
                }
            });
        }
        document.querySelectorAll('[data-close-incident-detail], #incidentDetailModalCloseBtn').forEach((button) => {
            button.addEventListener('click', closeDetailModal);
        });
        if (detailModal) {
            detailModal.addEventListener('click', (event) => {
                if (event.target === detailModal || event.target.hasAttribute('data-close-incident-detail')) {
                    closeDetailModal();
                }
            });
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        bindEvents();
        setExpanded(state.expanded);
        loadInbox();
        window.setInterval(loadInbox, 30000);
    });
})();
