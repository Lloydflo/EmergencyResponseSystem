(() => {
    'use strict';

    const BUILD = '20260808-admin-tip-details-v2';
    const rootSelector = '#iaAnonymousTipInbox';
    const incidentLinkSelector = [
        'a[href*="dispatcher/incident.php?code="]',
        'a[href*="/incident.php?code="]',
        'a[href*="incident.php?code="]'
    ].join(',');

    let lastTrigger = null;
    let requestController = null;

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const firstText = (...values) => {
        for (const value of values) {
            const text = String(value ?? '').trim();
            if (text !== '') return text;
        }
        return '';
    };

    const humanize = (value, fallback = 'Not recorded') => {
        const text = firstText(value);
        if (text === '') return fallback;
        return text
            .replace(/[_-]+/g, ' ')
            .replace(/\b\w/g, (letter) => letter.toUpperCase());
    };

    const formatDateTime = (value) => {
        const text = firstText(value);
        if (text === '') return 'Not recorded';
        const normalized = text.includes('T') ? text : text.replace(' ', 'T');
        const hasZone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(normalized);
        const date = new Date(hasZone ? normalized : `${normalized}+08:00`);
        if (Number.isNaN(date.getTime())) return text;
        return date.toLocaleString('en-PH', {
            timeZone: 'Asia/Manila',
            year: 'numeric',
            month: 'short',
            day: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    };

    const parseReference = (anchor) => {
        try {
            const url = new URL(anchor.getAttribute('href') || '', window.location.href);
            return firstText(url.searchParams.get('code'), anchor.dataset.incidentReference, anchor.textContent);
        } catch (_) {
            return firstText(anchor.dataset.incidentReference, anchor.textContent);
        }
    };

    const ensureModal = () => {
        let modal = document.getElementById('adminTipIncidentViewer');
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'adminTipIncidentViewer';
        modal.className = 'admin-tip-incident-viewer';
        modal.hidden = true;
        modal.setAttribute('aria-hidden', 'true');
        modal.setAttribute('data-build', BUILD);
        modal.innerHTML = `
            <button type="button" class="admin-tip-incident-backdrop" data-admin-tip-incident-close aria-label="Close incident details"></button>
            <section class="admin-tip-incident-dialog" role="dialog" aria-modal="true" aria-labelledby="adminTipIncidentTitle">
                <header class="admin-tip-incident-head">
                    <div class="admin-tip-incident-heading">
                        <span class="admin-tip-incident-eyebrow"><i class="fas fa-shield-halved" aria-hidden="true"></i> Administrator read-only view</span>
                        <h2 id="adminTipIncidentTitle">Linked Incident Details</h2>
                        <p id="adminTipIncidentSubtitle">Loading incident record…</p>
                    </div>
                    <button type="button" class="admin-tip-incident-close" data-admin-tip-incident-close aria-label="Close incident details">
                        <i class="fas fa-xmark" aria-hidden="true"></i>
                    </button>
                </header>
                <div class="admin-tip-incident-body" id="adminTipIncidentBody" aria-live="polite"></div>
                <footer class="admin-tip-incident-foot">
                    <span><i class="fas fa-lock" aria-hidden="true"></i> Incident details only. Dispatch controls are not available to the admin view.</span>
                    <button type="button" class="admin-tip-incident-done" data-admin-tip-incident-close>Close</button>
                </footer>
            </section>
        `;
        document.body.appendChild(modal);
        return modal;
    };

    const modalParts = () => {
        const modal = ensureModal();
        return {
            modal,
            body: modal.querySelector('#adminTipIncidentBody'),
            subtitle: modal.querySelector('#adminTipIncidentSubtitle'),
            closeButton: modal.querySelector('.admin-tip-incident-close')
        };
    };

    const setModalOpen = (open) => {
        const { modal, closeButton } = modalParts();
        modal.hidden = !open;
        modal.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.documentElement.classList.toggle('admin-tip-incident-open', open);
        if (open) {
            window.setTimeout(() => closeButton?.focus(), 0);
        } else if (lastTrigger instanceof HTMLElement) {
            lastTrigger.focus({ preventScroll: true });
        }
    };

    const closeModal = () => {
        requestController?.abort();
        requestController = null;
        setModalOpen(false);
    };

    const statusTone = (status) => {
        const value = String(status || '').toLowerCase();
        if (['resolved', 'completed', 'closed'].includes(value)) return 'success';
        if (['cancelled', 'canceled', 'rejected'].includes(value)) return 'neutral';
        if (['critical', 'failed'].includes(value)) return 'danger';
        if (['dispatched', 'assigned', 'acknowledged', 'enroute', 'en_route', 'on_scene', 'active', 'in_progress'].includes(value)) return 'active';
        return 'pending';
    };

    const priorityTone = (priority) => {
        const value = String(priority || '').toLowerCase();
        if (value === 'critical') return 'danger';
        if (['high', 'urgent'].includes(value)) return 'high';
        if (['medium', 'moderate'].includes(value)) return 'medium';
        return 'low';
    };

    const infoCard = (label, value, icon = 'fa-circle-info', wide = false) => `
        <article class="admin-tip-info-card${wide ? ' wide' : ''}">
            <span class="admin-tip-info-label"><i class="fas ${icon}" aria-hidden="true"></i>${escapeHtml(label)}</span>
            <div class="admin-tip-info-value">${escapeHtml(firstText(value) || 'Not recorded')}</div>
        </article>
    `;

    const renderAssignedUnit = (incident) => {
        const identifier = firstText(incident.assigned_unit_identifier);
        if (!identifier) {
            return '<div class="admin-tip-empty-inline">No response unit is recorded for this incident.</div>';
        }

        const meta = [
            humanize(incident.assigned_unit_type, 'Response unit'),
            firstText(incident.vehicle_name),
            firstText(incident.plate_number) ? `Plate ${firstText(incident.plate_number)}` : '',
            firstText(incident.driver_name) ? `Responder: ${firstText(incident.driver_name)}` : ''
        ].filter(Boolean).join(' · ');

        return `
            <article class="admin-tip-unit-card">
                <span class="admin-tip-unit-icon"><i class="fas fa-truck-medical" aria-hidden="true"></i></span>
                <span class="admin-tip-unit-copy">
                    <strong>${escapeHtml(identifier)}</strong>
                    <span>${escapeHtml(meta || 'Response unit')}</span>
                </span>
            </article>
        `;
    };

    const renderIncident = (payload, requestedReference) => {
        const incident = payload?.incident || payload?.data || null;
        const { body, subtitle } = modalParts();

        if (!incident) {
            subtitle.textContent = requestedReference || 'Linked incident';
            body.innerHTML = `
                <div class="admin-tip-incident-state error">
                    <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                    <strong>Incident record not found</strong>
                    <span>The linked reference may have been removed or is not available to this account.</span>
                </div>
            `;
            return;
        }

        const reference = firstText(incident.reference_no, incident.incident_code, requestedReference, `Incident #${incident.id || ''}`);
        const status = firstText(incident.status, incident.dispatch_status, 'pending');
        const priority = firstText(incident.priority, 'medium');
        const title = firstText(incident.title, humanize(incident.type, 'Incident'));
        const type = humanize(incident.type, 'Not recorded');
        const location = firstText(incident.location_address, incident.location, 'Not recorded');
        const description = firstText(incident.description, 'No incident description was recorded.');
        const caller = firstText(incident.caller_name);
        const callerPhone = firstText(incident.caller_phone);
        const responder = firstText(incident.driver_name);
        const plate = firstText(incident.plate_number);

        subtitle.textContent = reference;
        body.innerHTML = `
            <section class="admin-tip-incident-summary">
                <div class="admin-tip-incident-summary-main">
                    <div class="admin-tip-badges">
                        <span class="admin-tip-badge status ${statusTone(status)}">${escapeHtml(humanize(status))}</span>
                        <span class="admin-tip-badge priority ${priorityTone(priority)}">${escapeHtml(humanize(priority))} priority</span>
                    </div>
                    <h3>${escapeHtml(reference)}</h3>
                    <p class="admin-tip-incident-type">${escapeHtml(title)} · ${escapeHtml(type)}</p>
                    <p class="admin-tip-incident-description">${escapeHtml(description)}</p>
                </div>
                <article class="admin-tip-location-card">
                    <span><i class="fas fa-location-dot" aria-hidden="true"></i> Location</span>
                    <strong>${escapeHtml(location)}</strong>
                </article>
            </section>

            <section class="admin-tip-incident-grid" aria-label="Incident information">
                ${infoCard('Reported', formatDateTime(firstText(incident.created_at, incident.reported_at)), 'fa-calendar-plus')}
                ${infoCard('Dispatched', formatDateTime(firstText(incident.dispatch_assigned_at, incident.assigned_at)), 'fa-truck-fast')}
                ${infoCard('On scene', formatDateTime(incident.on_scene_at), 'fa-location-crosshairs')}
                ${infoCard('Resolved / closed', formatDateTime(firstText(incident.resolved_at, incident.completed_at, incident.closed_at, incident.cleared_at)), 'fa-circle-check')}
                ${caller ? infoCard('Reported by', callerPhone ? `${caller} · ${callerPhone}` : caller, 'fa-phone', true) : ''}
                ${responder ? infoCard('Responder / driver', plate ? `${responder} · Plate ${plate}` : responder, 'fa-user-shield', true) : ''}
            </section>

            <section class="admin-tip-units-section">
                <div class="admin-tip-section-head">
                    <div>
                        <span>Response assignment</span>
                        <h3>Assigned units</h3>
                    </div>
                    <span class="admin-tip-readonly-chip"><i class="fas fa-eye" aria-hidden="true"></i> Read only</span>
                </div>
                <div class="admin-tip-unit-list">${renderAssignedUnit(incident)}</div>
            </section>
        `;
    };

    const openIncidentDetails = async (reference, trigger) => {
        const cleanReference = firstText(reference);
        if (!cleanReference) return;

        lastTrigger = trigger instanceof HTMLElement ? trigger : null;
        requestController?.abort();
        requestController = new AbortController();

        const { body, subtitle } = modalParts();
        subtitle.textContent = cleanReference;
        body.innerHTML = `
            <div class="admin-tip-incident-state loading">
                <span class="admin-tip-spinner" aria-hidden="true"></span>
                <strong>Loading linked incident</strong>
                <span>Retrieving the administrator read-only incident record.</span>
            </div>
        `;
        setModalOpen(true);

        try {
            const response = await fetch(`api/incident_details.php?code=${encodeURIComponent(cleanReference)}`, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: requestController.signal
            });
            const payload = await response.json();
            if (!response.ok || !payload?.ok) {
                throw new Error(payload?.error || 'Unable to load the linked incident.');
            }
            renderIncident(payload, cleanReference);
        } catch (error) {
            if (error?.name === 'AbortError') return;
            subtitle.textContent = cleanReference;
            body.innerHTML = `
                <div class="admin-tip-incident-state error">
                    <i class="fas fa-circle-exclamation" aria-hidden="true"></i>
                    <strong>Unable to load incident details</strong>
                    <span>${escapeHtml(error?.message || 'Please refresh the inbox and try again.')}</span>
                </div>
            `;
        } finally {
            requestController = null;
        }
    };

    const setIncidentTriggerAttributes = (anchor, reference) => {
        anchor.dataset.adminTipIncidentDetails = 'true';
        anchor.dataset.incidentReference = reference;
        anchor.removeAttribute('href');
        anchor.setAttribute('role', 'button');
        anchor.setAttribute('tabindex', '0');
        anchor.setAttribute('aria-haspopup', 'dialog');
        anchor.setAttribute('title', 'View read-only incident details');
    };

    const deduplicateIncidentActions = (scope = document) => {
        const details = [];
        if (scope instanceof Element) {
            const ownDetail = scope.closest('.ia-tip-detail');
            if (ownDetail) details.push(ownDetail);
        }
        scope.querySelectorAll?.('.ia-tip-detail').forEach((detail) => details.push(detail));

        [...new Set(details)].forEach((detail) => {
            const triggers = Array.from(detail.querySelectorAll('[data-admin-tip-incident-details="true"]'));
            if (!triggers.length) return;

            const linkedTrigger = triggers.find((trigger) => (
                trigger.closest('.ia-tip-detail-item')
                && !trigger.closest('.ia-tip-quick-actions')
            )) || triggers[0];
            const reference = firstText(linkedTrigger.dataset.incidentReference, parseReference(linkedTrigger));

            detail.classList.add('admin-tip-has-linked-incident');
            linkedTrigger.classList.add('admin-tip-linked-incident-trigger');
            linkedTrigger.setAttribute('aria-label', `View read-only details for ${reference}`);
            if (linkedTrigger.dataset.adminTipSingleAction !== 'true') {
                linkedTrigger.dataset.adminTipSingleAction = 'true';
                linkedTrigger.innerHTML = `
                    <span class="admin-tip-linked-incident-main">
                        <span class="admin-tip-linked-incident-icon" aria-hidden="true"><i class="fas fa-link"></i></span>
                        <span class="admin-tip-linked-incident-copy">
                            <strong>${escapeHtml(reference)}</strong>
                            <span>View linked incident details</span>
                        </span>
                    </span>
                    <span class="admin-tip-linked-incident-arrow" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
                `;
            }

            const linkedCard = linkedTrigger.closest('.ia-tip-detail-item');
            linkedCard?.classList.add('admin-tip-linked-incident-card');

            triggers.forEach((trigger) => {
                if (trigger === linkedTrigger) return;
                const quickActions = trigger.closest('.ia-tip-quick-actions');
                trigger.remove();
                if (quickActions) {
                    quickActions.classList.add('admin-tip-duplicate-action-removed');
                }
            });
        });
    };

    const polishIncidentLinks = (scope = document) => {
        const candidates = [];
        if (scope instanceof Element && scope.matches(incidentLinkSelector)) {
            candidates.push(scope);
        }
        scope.querySelectorAll?.(incidentLinkSelector).forEach((anchor) => candidates.push(anchor));

        candidates.forEach((anchor) => {
            if (!anchor.closest(rootSelector)) return;
            const reference = parseReference(anchor);
            setIncidentTriggerAttributes(anchor, reference);
        });

        const inboxRoot = document.querySelector(rootSelector);
        deduplicateIncidentActions(inboxRoot || scope);
    };

    document.addEventListener('click', (event) => {
        const target = event.target;
        if (!(target instanceof Element)) return;

        const close = target.closest('[data-admin-tip-incident-close]');
        if (close) {
            event.preventDefault();
            closeModal();
            return;
        }

        const root = target.closest(rootSelector);
        if (!root) return;
        const anchor = target.closest('[data-admin-tip-incident-details="true"]');
        if (!anchor || !root.contains(anchor)) return;

        event.preventDefault();
        event.stopPropagation();
        event.stopImmediatePropagation();
        openIncidentDetails(parseReference(anchor), anchor);
    }, true);

    document.addEventListener('keydown', (event) => {
        const target = event.target;
        const modal = document.getElementById('adminTipIncidentViewer');
        if (event.key === 'Escape' && modal && !modal.hidden) {
            event.preventDefault();
            closeModal();
            return;
        }

        if (!(target instanceof Element) || !['Enter', ' '].includes(event.key)) return;
        const trigger = target.closest('[data-admin-tip-incident-details="true"]');
        const root = target.closest(rootSelector);
        if (!trigger || !root || !root.contains(trigger)) return;
        event.preventDefault();
        openIncidentDetails(parseReference(trigger), trigger);
    });

    const observer = new MutationObserver((records) => {
        for (const record of records) {
            record.addedNodes.forEach((node) => {
                if (node instanceof Element) polishIncidentLinks(node);
            });
        }
    });

    document.addEventListener('DOMContentLoaded', () => {
        polishIncidentLinks();
        const root = document.querySelector(rootSelector);
        if (root) observer.observe(root, { childList: true, subtree: true });
    });
})();
