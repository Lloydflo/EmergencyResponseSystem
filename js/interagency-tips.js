(function () {
    const root = document.getElementById('iaAnonymousTipInbox');
    if (!root) {
        return;
    }

    const apiUrl = 'api/system_API/?action=anonymous_tip';
    const statuses = ['all', 'pending', 'dispatched', 'resolved', 'new', 'reviewing', 'verified', 'dismissed'];
    const editableStatuses = ['new', 'reviewing', 'verified', 'dismissed', 'pending', 'dispatched', 'resolved'];
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
        evidenceViewer: null,
        knownTipKeys: new Set(),
        hasLoadedOnce: false,
    };

    const escapeHtml = (value) => String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const statusLabel = (status) => {
        const raw = String(status || 'new').toLowerCase();
        if (raw === 'converted_to_incident') return 'Pending';
        return raw.replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase());
    };

    const rawStatusOf = (item) => String(item?.raw_status || item?.status || 'new').trim().toLowerCase();
    const statusOf = (item) => String(item?.display_status || item?.status || 'new').trim().toLowerCase();
    const isConvertedStatus = (item) => ['converted_to_incident', 'pending', 'dispatched', 'resolved'].includes(statusOf(item))
        || ['converted_to_incident', 'pending', 'dispatched', 'resolved'].includes(rawStatusOf(item))
        || Number(item?.converted_incident_id || 0) > 0;
    const tipKey = (item) => String(item?.tip_id || item?.id || '').trim();
    const isOpenTip = (item) => ['pending', 'new'].includes(rawStatusOf(item));

    const actionLabel = {
        reviewing: 'Marked for review.',
        verified: 'Verified as actionable.',
        dismissed: 'Dismissed after review.',
    };

    const priorities = ['medium', 'low', 'high', 'critical'];

    const PH_TIME_ZONE = 'Asia/Manila';
    const PH_DATE_FORMATTER = new Intl.DateTimeFormat('en-PH', {
        timeZone: PH_TIME_ZONE,
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true,
    });

    const parsePhilippineDate = (value) => {
        const raw = String(value || '').trim();
        if (!raw) return new Date(NaN);

        const localDateTime = raw.match(
            /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::(\d{2})(?:\.(\d+))?)?$/
        );
        if (localDateTime) {
            const [, year, month, day, hour, minute, second = '00', fraction = ''] = localDateTime;
            const milliseconds = (fraction + '000').slice(0, 3);
            return new Date(`${year}-${month}-${day}T${hour}:${minute}:${second}.${milliseconds}+08:00`);
        }
        if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
            return new Date(`${raw}T00:00:00.000+08:00`);
        }
        return new Date(raw);
    };

    const formatDate = (value, millisecondValue = 0) => {
        const milliseconds = Number(millisecondValue || 0);
        const date = milliseconds > 0 ? new Date(milliseconds) : parsePhilippineDate(value);
        if (Number.isNaN(date.getTime())) {
            return String(value || 'No date');
        }
        return `${PH_DATE_FORMATTER.format(date)} PHT`;
    };

    // The inbox timestamp means "received by this emergency-response system".
    // Use the server-authored receipt time first; source-provided event time can
    // be inaccurate when the sending system uses a different local timezone.
    const tipDisplayDate = (item) => ({
        value: item?.display_datetime || item?.received_at || item?.tip_datetime || item?.updated_at || '',
        milliseconds: Number(
            item?.display_datetime_ms
            || item?.received_at_ms
            || item?.tip_datetime_ms
            || item?.updated_at_ms
            || 0
        ),
    });

    const appBasePath = () => {
        const path = String(window.location.pathname || '');
        const match = path.match(/^(.*)\/(?:admin|dispatcher)\//i);
        return match ? match[1] : '';
    };

    const normalizeEvidenceUrl = (value) => {
        const text = String(value || '').trim();
        if (text === '') {
            return '';
        }

        if (/^https?:\/\//i.test(text) || /^data:image\//i.test(text) || /^blob:/i.test(text)) {
            return text;
        }

        if (/^\/9j\//.test(text)) {
            return `data:image/jpeg;base64,${text}`;
        }
        if (/^iVBOR/i.test(text)) {
            return `data:image/png;base64,${text}`;
        }
        if (/^R0lG/i.test(text)) {
            return `data:image/gif;base64,${text}`;
        }
        if (/^UklGR/i.test(text)) {
            return `data:image/webp;base64,${text}`;
        }

        const normalized = text.replace(/\\/g, '/');
        const base = appBasePath();
        if (normalized.startsWith('/') && base !== '' && !normalized.startsWith(`${base}/`)) {
            return `${base}${normalized}`;
        }
        if (/^\.\.\//.test(normalized)) {
            return normalized.replace(/^(\.\.\/)+/, '');
        }
        return normalized;
    };

    const isEvidenceLink = (value) => {
        const text = String(value || '').trim();
        return /^https?:\/\//i.test(text)
            || /^data:image\//i.test(text)
            || /^blob:/i.test(text)
            || /\.(png|jpe?g|gif|webp|bmp|svg)(?:[?#].*)?$/i.test(text);
    };

    const evidenceCandidates = (value, depth = 0) => {
        if (value === null || value === undefined || depth > 3) {
            return [];
        }

        if (Array.isArray(value)) {
            return value.flatMap((entry) => evidenceCandidates(entry, depth + 1));
        }

        if (typeof value === 'object') {
            const keys = [
                'url', 'path', 'src', 'href', 'image', 'photo', 'photo_url',
                'photoOfEvidence', 'photo_of_evidence', 'evidence_photo',
                'evidence', 'file', 'file_url', 'filePath', 'filename',
                'base64', 'data',
            ];
            const direct = keys
                .filter((key) => Object.prototype.hasOwnProperty.call(value, key))
                .flatMap((key) => evidenceCandidates(value[key], depth + 1));
            const nested = Object.keys(value)
                .filter((key) => !keys.includes(key))
                .flatMap((key) => evidenceCandidates(value[key], depth + 1));
            return [...direct, ...nested];
        }

        const text = String(value || '').trim();
        if (text === '') {
            return [];
        }
        if (/^[\[{]/.test(text)) {
            try {
                return evidenceCandidates(JSON.parse(text), depth + 1);
            } catch (_) {}
        }
        return [text];
    };

    const evidenceInfo = (value) => {
        const raw = String(value || '').trim();
        const candidates = evidenceCandidates(value)
            .map(normalizeEvidenceUrl)
            .filter(Boolean);
        const url = candidates.find(isEvidenceLink) || '';
        return {
            raw,
            url,
            hasEvidence: raw !== '' || candidates.length > 0,
        };
    };

    const incidentUrl = (referenceNo) => {
        const ref = encodeURIComponent(String(referenceNo || '').trim());
        const base = appBasePath();
        return `${base}/dispatcher/incident.php?code=${ref}`;
    };

    const dispatchUrl = (referenceNo, incidentId) => {
        const ref = String(referenceNo || '').trim();
        const id = Number(incidentId || 0);
        const base = appBasePath();
        const params = new URLSearchParams({
            source: 'tip',
            open_dispatch: '1',
        });
        if (ref) params.append('code', ref);
        if (id > 0) params.append('incident_id', String(id));
        return `${base}/dispatcher/dispatch.php?${params.toString()}`;
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
        window.dispatchEvent(new CustomEvent('ers:anonymous-tips-updated', { detail }));
        try {
            window.localStorage.setItem('ers_incidents_changed', JSON.stringify(detail));
            window.localStorage.setItem('ers_incidents', Date.now().toString());
            window.localStorage.setItem('ers_anonymous_tips_changed', JSON.stringify(detail));
        } catch (_) {}
    };

    const playNewTipCue = () => {
        try {
            const AudioContextApi = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextApi) return;
            const ctx = new AudioContextApi();
            const now = ctx.currentTime;
            [780, 980].forEach((frequency, index) => {
                const oscillator = ctx.createOscillator();
                const gain = ctx.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(frequency, now + (index * 0.12));
                gain.gain.setValueAtTime(0.0001, now + (index * 0.12));
                gain.gain.exponentialRampToValueAtTime(0.08, now + 0.02 + (index * 0.12));
                gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.16 + (index * 0.12));
                oscillator.connect(gain);
                gain.connect(ctx.destination);
                oscillator.start(now + (index * 0.12));
                oscillator.stop(now + 0.18 + (index * 0.12));
            });
            window.setTimeout(() => ctx.close().catch(() => {}), 650);
        } catch (_) {}
    };

    const showNewTipNotification = (items) => {
        const count = items.length;
        if (count < 1) return;
        const first = items[0] || {};
        const location = String(first.location || '').trim();
        const message = count === 1
            ? `New anonymous tip received${location ? `: ${location}` : '.'}`
            : `${count} new anonymous tips received.`;

        const toastRoot = document.getElementById('iaModuleToast') || (() => {
            let fallback = document.getElementById('iaTipToastRoot');
            if (!fallback) {
                fallback = document.createElement('div');
                fallback.id = 'iaTipToastRoot';
                fallback.className = 'ia-tip-toast-root';
                fallback.setAttribute('aria-live', 'polite');
                document.body.appendChild(fallback);
            }
            return fallback;
        })();

        const toast = document.createElement('button');
        toast.type = 'button';
        toast.className = toastRoot.id === 'iaModuleToast' ? 'ia-module-toast-item ia-tip-new-toast' : 'ia-tip-toast';
        toast.textContent = message;
        toast.addEventListener('click', () => {
            state.expanded = true;
            state.selectedId = Number(first.id || state.selectedId || 0) || state.selectedId;
            render();
            root.scrollIntoView({ behavior: 'smooth', block: 'start' });
            toast.remove();
        });
        toastRoot.prepend(toast);
        window.setTimeout(() => toast.remove(), 7000);

        if ('Notification' in window && window.Notification.permission === 'granted') {
            try {
                new window.Notification('Anonymous Tip Inbox', { body: message });
            } catch (_) {}
        }
        playNewTipCue();
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

    const loadTips = async (options = {}) => {
        const notifyNew = Boolean(options.notifyNew);
        const silent = Boolean(options.silent);
        if (!silent) {
            state.loading = true;
        }
        state.error = '';
        if (!silent) {
            render();
        }

        try {
            const response = await fetch(`${apiUrl}&limit=80`, {
                cache: 'no-store',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Unable to load anonymous tips');
            }
            const nextItems = Array.isArray(data.items) ? data.items : [];
            const newOpenItems = notifyNew && state.hasLoadedOnce
                ? nextItems.filter((item) => {
                    const key = tipKey(item);
                    return key !== '' && !state.knownTipKeys.has(key) && isOpenTip(item);
                })
                : [];
            state.items = nextItems;
            state.knownTipKeys = new Set(nextItems.map(tipKey).filter(Boolean));
            state.hasLoadedOnce = true;
            if (!state.items.some((item) => Number(item.id) === Number(state.selectedId))) {
                state.selectedId = state.items.length > 0 ? Number(state.items[0].id) : null;
            }
            if (newOpenItems.length > 0) {
                state.notice = newOpenItems.length === 1
                    ? `New anonymous tip received: ${newOpenItems[0].location || newOpenItems[0].tip_id || 'Open inbox'}.`
                    : `${newOpenItems.length} new anonymous tips received.`;
                notifyCountsChanged();
                showNewTipNotification(newOpenItems);
            }
        } catch (error) {
            state.error = error.message || 'Unable to load anonymous tips';
        } finally {
            if (!silent) {
                state.loading = false;
            }
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
        if (isConvertedStatus(item)) {
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
        if (!item) {
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
                    incident_type: 'medical, police, fire',
                    priority: form?.elements.priority?.value || 'medium',
                    outcome: form?.elements.outcome?.value.trim() || '',
                }),
            });
            const data = await response.json();
            if (!response.ok || !data.success) {
                throw new Error(data.error || 'Unable to convert anonymous tip');
            }
            const reference = data.incident && data.incident.reference_no ? data.incident.reference_no : 'incident';
            const hasCoordinates = data.incident
                && data.incident.latitude !== null
                && data.incident.latitude !== undefined
                && data.incident.latitude !== ''
                && data.incident.longitude !== null
                && data.incident.longitude !== undefined
                && data.incident.longitude !== '';
            await loadTips();
            state.notice = `${item.tip_id || 'Tip'} converted to ${reference}${hasCoordinates ? ' with coordinates.' : '. Location needs manual pin.'}`;
            render();
            notifyCountsChanged();
            notifyIncidentQueueChanged(data.incident || null);
            const incidentId = Number(data.incident?.id || 0);
            if (incidentId > 0 || reference) {
                window.location.href = dispatchUrl(reference, incidentId);
            }
        } catch (error) {
            state.error = error.message || 'Unable to convert anonymous tip';
            render();
        } finally {
            state.action = '';
            render();
        }
    };

    const renderStats = () => {
        const pendingCount = state.items.filter((item) => ['pending', 'new', 'converted_to_incident'].includes(statusOf(item))).length;
        const dispatchedCount = state.items.filter((item) => statusOf(item) === 'dispatched').length;
        const resolvedCount = state.items.filter((item) => statusOf(item) === 'resolved').length;
        const evidence = state.items.filter((item) => String(item.photo_of_evidence || '').trim() !== '').length;

        return `
            <section class="ia-tip-stats" aria-label="Anonymous tip summary">
                <article class="ia-tip-stat">
                    <div class="ia-tip-stat-label">Pending / Intake</div>
                    <div class="ia-tip-stat-value">${pendingCount}</div>
                </article>
                <article class="ia-tip-stat">
                    <div class="ia-tip-stat-label">Dispatched</div>
                    <div class="ia-tip-stat-value">${dispatchedCount}</div>
                </article>
                <article class="ia-tip-stat">
                    <div class="ia-tip-stat-label">Resolved</div>
                    <div class="ia-tip-stat-value">${resolvedCount}</div>
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
            const displayDate = tipDisplayDate(item);
            const evidencePhoto = evidenceInfo(item.photo_of_evidence);
            const itemStatus = statusOf(item);
            const isConverted = isConvertedStatus(item);
            const evidenceButton = evidencePhoto.hasEvidence
                ? (evidencePhoto.url
                    ? `<a class="ia-tip-evidence ia-tip-evidence-thumb" href="${escapeHtml(evidencePhoto.url)}" data-tip-evidence-url="${escapeHtml(evidencePhoto.url)}" data-tip-evidence-title="${escapeHtml(item.tip_id || 'Evidence')}" title="View evidence" aria-label="View evidence"><img src="${escapeHtml(evidencePhoto.url)}" alt="Evidence for ${escapeHtml(item.tip_id || 'anonymous tip')}" loading="lazy"></a>`
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
                            <span><i class="fas fa-clock"></i> ${escapeHtml(formatDate(displayDate.value, displayDate.milliseconds))}</span>
                            <span><i class="fas fa-network-wired"></i> ${escapeHtml(item.source_system || 'Group 6')}</span>
                        </span>
                        <span class="ia-tip-flow">
                            <span class="${['reviewing', 'verified', 'pending', 'dispatched', 'resolved'].includes(itemStatus) || isConverted ? 'is-done' : ''}">Review</span>
                            <span class="${['verified', 'pending', 'dispatched', 'resolved'].includes(itemStatus) || isConverted ? 'is-done' : ''}">Verify</span>
                            <span class="${['pending', 'dispatched', 'resolved'].includes(itemStatus) || isConverted ? 'is-done' : ''}">Pending</span>
                            <span class="${['dispatched', 'resolved'].includes(itemStatus) ? 'is-done' : ''}">Dispatched</span>
                            <span class="${itemStatus === 'resolved' ? 'is-done' : ''}">Resolved</span>
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

        const displayDate = tipDisplayDate(item);
        const evidencePhoto = evidenceInfo(item.photo_of_evidence);
        const itemStatus = statusOf(item);
        const rawItemStatus = rawStatusOf(item);
        const convertedReference = String(item.converted_reference_no || '').trim();
        const convertedId = Number(item.converted_incident_id || 0);
        const isConverted = isConvertedStatus(item);
        const evidence = evidencePhoto.hasEvidence
            ? (evidencePhoto.url
                ? `<a class="ia-tip-evidence ia-tip-evidence-preview" href="${escapeHtml(evidencePhoto.url)}" data-tip-evidence-url="${escapeHtml(evidencePhoto.url)}" data-tip-evidence-title="${escapeHtml(item.tip_id || 'Evidence')}">
                        <img class="ia-tip-evidence-image" src="${escapeHtml(evidencePhoto.url)}" alt="Evidence for ${escapeHtml(item.tip_id || 'anonymous tip')}" loading="lazy">
                        <span><i class="fas fa-arrow-up-right-from-square"></i> Open full image</span>
                   </a>`
                : '<div class="ia-tip-detail-value">Evidence saved but no image URL was provided.</div>')
            : '<div class="ia-tip-detail-value">None</div>';
        const incidentLink = convertedReference || convertedId > 0
            ? `<div class="ia-tip-link-group" style="display:flex;flex-wrap:wrap;gap:0.4rem;align-items:center;">
                 <a class="ia-tip-secondary" href="${escapeHtml(dispatchUrl(convertedReference, convertedId))}"><i class="fas fa-truck-fast"></i> Dispatch Queue</a>
                 <a class="ia-tip-secondary" href="${escapeHtml(incidentUrl(convertedReference))}"><i class="fas fa-list-check"></i> ${escapeHtml(convertedReference || ('Incident #' + convertedId))}</a>
               </div>`
            : '<div class="ia-tip-detail-value">Not converted</div>';
        const actionDisabled = (status) => state.action !== '' || itemStatus === status;
        const actionButtonText = (status, label) => state.action === status
            ? '<i class="fas fa-spinner fa-spin"></i> Working'
            : label;
        const statusOptions = editableStatuses
            .filter((status) => status !== 'converted_to_incident' || rawItemStatus === 'converted_to_incident')
            .map((status) => `<option value="${status}" ${rawItemStatus === status ? 'selected' : ''}>${statusLabel(status)}</option>`)
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
                        <div class="ia-tip-detail-label">Received (PHT)</div>
                        <div class="ia-tip-detail-value">${escapeHtml(formatDate(displayDate.value, displayDate.milliseconds))}</div>
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
                        ${convertedId > 0 ? `<div class="ia-tip-detail-note">Incident #${convertedId}${itemStatus === 'dispatched' ? ', Dispatched' : (item.converted_incident_status ? `, ${escapeHtml(statusLabel(item.converted_incident_status))}` : '')}</div>` : ''}
                    </div>
                </div>
                <div class="ia-tip-quick-actions" aria-label="Tip quick actions">
                    <button type="button" class="ia-tip-secondary" data-tip-quick-status="reviewing" ${actionDisabled('reviewing') ? 'disabled' : ''}>${actionButtonText('reviewing', '<i class="fas fa-magnifying-glass"></i> Review')}</button>
                    <button type="button" class="ia-tip-secondary" data-tip-quick-status="verified" ${actionDisabled('verified') ? 'disabled' : ''}>${actionButtonText('verified', '<i class="fas fa-check-circle"></i> Verify')}</button>
                    <button type="button" class="ia-tip-secondary" data-tip-convert ${state.action !== '' ? 'disabled' : ''}>${actionButtonText('converted_to_incident', `<i class="fas ${isConverted ? 'fa-arrows-rotate' : 'fa-file-circle-plus'}"></i> ${isConverted ? 'Re-Send to Queue' : 'Convert to Incident'}`)}</button>
                    ${isConverted ? `<a class="ia-tip-secondary" href="${escapeHtml(dispatchUrl(convertedReference, convertedId))}"><i class="fas fa-truck-fast"></i> Open in Dispatch</a>` : ''}
                    <button type="button" class="ia-tip-secondary" data-tip-quick-status="dismissed" ${actionDisabled('dismissed') ? 'disabled' : ''}>${actionButtonText('dismissed', '<i class="fas fa-ban"></i> Dismiss')}</button>
                </div>
                ${isConverted ? '<div class="ia-tip-lock-note">This tip is already linked to an incident, so review buttons are locked.</div>' : ''}
                <form data-tip-status-form>
                    <div class="ia-tip-detail-grid ia-tip-detail-form-grid">
                        <div class="ia-tip-detail-item">
                            <label class="ia-tip-detail-label" for="iaTipStatus">Status</label>
                            <select id="iaTipStatus" name="status" ${isConverted ? 'disabled' : ''}>
                                ${statusOptions}
                            </select>
                        </div>
                        <div class="ia-tip-detail-item">
                            <div class="ia-tip-detail-label">Incident Type</div>
                            <div class="ia-tip-detail-value">Emergency, Police, Fire</div>
                            <input type="hidden" name="incident_type" value="medical, police, fire">
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

    const renderEvidenceViewer = () => {
        if (!state.evidenceViewer || !state.evidenceViewer.url) {
            return '';
        }

        const url = state.evidenceViewer.url;
        const title = state.evidenceViewer.title || 'Evidence';
        return `
            <div class="ia-tip-viewer" data-tip-viewer>
                <div class="ia-tip-viewer-backdrop" data-tip-close-viewer></div>
                <div class="ia-tip-viewer-panel" role="dialog" aria-modal="true" aria-label="Evidence image">
                    <div class="ia-tip-viewer-head">
                        <strong>${escapeHtml(title)}</strong>
                        <button type="button" class="ia-tip-icon" data-tip-close-viewer aria-label="Close evidence preview">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <img class="ia-tip-viewer-image" src="${escapeHtml(url)}" alt="Full evidence for ${escapeHtml(title)}">
                    <div class="ia-tip-viewer-actions">
                        <a class="ia-tip-secondary" href="${escapeHtml(url)}" target="_blank" rel="noopener">
                            <i class="fas fa-arrow-up-right-from-square"></i> New tab
                        </a>
                    </div>
                </div>
            </div>
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
            ${renderEvidenceViewer()}
        `;
    };

    root.addEventListener('click', (event) => {
        const evidence = event.target.closest('.ia-tip-evidence');
        if (evidence) {
            event.preventDefault();
            event.stopPropagation();
            state.evidenceViewer = {
                url: evidence.getAttribute('data-tip-evidence-url') || evidence.getAttribute('href') || '',
                title: evidence.getAttribute('data-tip-evidence-title') || 'Evidence',
            };
            render();
            return;
        }

        if (event.target.closest('[data-tip-close-viewer]')) {
            state.evidenceViewer = null;
            render();
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
        if (event.key === 'Escape' && state.evidenceViewer) {
            state.evidenceViewer = null;
            render();
            return;
        }

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

    window.addEventListener('ers:anonymous-tips-updated', () => {
        loadTips({ notifyNew: true, silent: true });
    });

    render();
    loadTips();
    window.setInterval(() => {
        if (document.visibilityState === 'visible') {
            loadTips({ notifyNew: true, silent: true });
        }
    }, 10000);
})();
