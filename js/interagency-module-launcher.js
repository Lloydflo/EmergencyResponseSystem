(function () {
    const stage = document.getElementById('iaModuleStage');
    const modal = document.getElementById('iaModuleModal');
    const modalBody = document.getElementById('iaModuleModalBody');
    const modalTitle = document.getElementById('iaModuleModalTitle');
    const modalSub = document.getElementById('iaModuleModalSub');
    const toastRoot = document.getElementById('iaModuleToast');
    const launcher = document.querySelector('.ia-module-launcher');
    if (!stage || !modal || !modalBody || !launcher) return;

    const moduleLabels = {
        iaCommandCenter: 'active command incident',
        iaEventCoordination: 'high-risk event',
        iaAnonymousTipInbox: 'anonymous tip',
        iaExternalIncidentInbox: 'external incident',
    };

    const state = {
        activeId: '',
        counts: {},
        initialized: false,
    };

    const safeJson = async (url) => {
        try {
            const response = await fetch(url, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            return await response.json();
        } catch (_) {
            return null;
        }
    };

    const countData = async () => {
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

        return {
            iaCommandCenter: incidentItems.length,
            iaExternalIncidentInbox: transferItems.filter((item) => {
                const status = String(item.incident_status || '').trim().toLowerCase();
                return !['resolved', 'cancelled', 'closed', 'rejected'].includes(status);
            }).length,
            iaAnonymousTipInbox: tipItems.filter((item) => ['pending', 'new'].includes(String(item.status || 'new').trim().toLowerCase())).length,
            iaEventCoordination: eventItems.filter((item) => {
                const status = String(item.status || '').toLowerCase();
                const hazard = String(item.on_site_safety_hazard_level || '').toLowerCase();
                return ['active', 'standby'].includes(status) && ['high', 'critical'].includes(hazard);
            }).length,
        };
    };

    const showToast = (message) => {
        if (!toastRoot) return;
        const item = document.createElement('div');
        item.className = 'ia-module-toast-item';
        item.textContent = message;
        toastRoot.prepend(item);
        window.setTimeout(() => item.remove(), 5200);
    };

    const setBadge = (id, count) => {
        const badge = launcher.querySelector(`[data-module-badge="${id}"]`);
        const button = launcher.querySelector(`[data-module-open="${id}"]`);
        if (badge) badge.textContent = String(count);
        if (button) button.classList.toggle('has-new', count > 0);
    };

    const refreshCounts = async () => {
        const nextCounts = await countData();
        Object.keys(nextCounts).forEach((id) => {
            const next = Number(nextCounts[id] || 0);
            const previous = Number(state.counts[id] || 0);
            setBadge(id, next);
            if (state.initialized && next > previous) {
                const added = next - previous;
                const label = moduleLabels[id] || 'inter-agency item';
                showToast(`${added} new ${label}${added > 1 ? 's' : ''} received.`);
            }
        });
        state.counts = nextCounts;
        state.initialized = true;
    };

    const ensureExpanded = (section) => {
        const toggle = section.querySelector('button[aria-controls]');
        if (toggle && toggle.getAttribute('aria-expanded') !== 'true') {
            toggle.click();
        }
    };

    const returnActiveToStage = () => {
        if (!state.activeId) return;
        const active = document.getElementById(state.activeId);
        if (active && active.parentElement === modalBody) {
            stage.appendChild(active);
        }
        state.activeId = '';
    };

    const openModule = (button) => {
        const targetId = String(button.getAttribute('data-module-open') || '');
        const section = document.getElementById(targetId);
        if (!section) return;

        returnActiveToStage();
        modalBody.appendChild(section);
        state.activeId = targetId;

        if (modalTitle) modalTitle.textContent = button.getAttribute('data-module-title') || 'Inter-Agency Module';
        if (modalSub) modalSub.textContent = button.getAttribute('data-module-subtitle') || 'Operational tools and inboxes.';

        modal.hidden = false;
        modal.setAttribute('aria-hidden', 'false');
        modal.classList.add('show');
        document.body.style.overflow = 'hidden';
        ensureExpanded(section);
    };

    const closeModule = () => {
        returnActiveToStage();
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        modal.hidden = true;
        document.body.style.overflow = '';
    };

    launcher.addEventListener('click', (event) => {
        const button = event.target.closest('[data-module-open]');
        if (!button) return;
        openModule(button);
    });

    modal.addEventListener('click', (event) => {
        if (event.target.closest('[data-module-close]')) {
            closeModule();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && modal.classList.contains('show')) {
            closeModule();
        }
    });

    let refreshCountsInFlight = false;
    const guardedRefreshCounts = async () => {
        if (refreshCountsInFlight) return;
        refreshCountsInFlight = true;
        try {
            await refreshCounts();
        } finally {
            refreshCountsInFlight = false;
        }
    };

    guardedRefreshCounts();
    window.addEventListener('ers:anonymous-tips-updated', guardedRefreshCounts);
    window.setInterval(() => {
        if (document.visibilityState === 'visible') guardedRefreshCounts();
    }, 12000);
})();
