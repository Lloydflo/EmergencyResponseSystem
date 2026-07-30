(function () {
    const root = document.getElementById('iaOperationsDesk');
    if (!root) return;

    const els = {
        external: document.getElementById('iaOpsExternal'),
        activeIncidents: document.getElementById('iaOpsActiveIncidents'),
        newTips: document.getElementById('iaOpsNewTips'),
        highEvents: document.getElementById('iaOpsHighEvents'),
        standbyUnits: document.getElementById('iaOpsStandbyUnits'),
        updated: document.getElementById('iaOpsUpdated'),
    };

    const setText = (el, value) => {
        if (el) el.textContent = String(value);
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
            return !['resolved', 'cancelled', 'closed', 'rejected'].includes(status);
        });
        const activeEvents = eventItems.filter((item) => ['active', 'standby'].includes(String(item.status || '').toLowerCase()));
        const highEvents = activeEvents.filter((item) => ['high', 'critical'].includes(String(item.on_site_safety_hazard_level || '').toLowerCase()));
        const standbyUnits = activeEvents.reduce((sum, item) => sum + Number(item.required_standby_responders || 0), 0);

        setText(els.external, openTransfers.length);
        setText(els.activeIncidents, incidentItems.length);
        setText(els.newTips, tipItems.filter((item) => String(item.status || 'new').toLowerCase() === 'new').length);
        setText(els.highEvents, highEvents.length);
        setText(els.standbyUnits, standbyUnits);

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
    window.setInterval(() => {
        if (document.visibilityState === 'visible') guardedUpdateOperations();
    }, 15000);
})();
