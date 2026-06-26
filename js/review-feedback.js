(function () {
  const qs = (selector, ctx = document) => ctx.querySelector(selector);
  const qsa = (selector, ctx = document) => Array.from(ctx.querySelectorAll(selector));

  const dashboard = qs('.review-dashboard');
  const reviewerName = dashboard?.dataset?.reviewerName || 'Dispatcher';

  const container = qs('#incidentsContainer');
  const statusFilter = qs('#statusFilter');
  const dayFilter = qs('#dayFilter');
  const searchInput = qs('#searchInput');
  const sortSelect = qs('#sortSelect');
  const applyFiltersBtn = qs('#applyFiltersBtn');
  const clearFiltersBtn = qs('#clearFiltersBtn');
  const resultsMeta = qs('#resultsMeta');

  const statClosedIncidents = qs('#statClosedIncidents');
  const statAverageResponse = qs('#statAverageResponse');
  const statAverageRating = qs('#statAverageRating');
  const statUnitsTracked = qs('#statUnitsTracked');

  const modal = qs('#reviewModal');
  const modalOverlay = qs('#reviewModalOverlay');
  const modalClose = qs('#modalClose');
  const closeFeedbackBtn = qs('#closeFeedbackBtn');
  const saveFeedbackBtn = qs('#saveFeedbackBtn');

  const modalTitle = qs('#modalTitle');
  const modalStatusBadge = qs('#modalStatusBadge');
  const modalPriorityBadge = qs('#modalPriorityBadge');
  const summaryCode = qs('#summaryCode');
  const summaryType = qs('#summaryType');
  const summaryDescription = qs('#summaryDescription');
  const summaryLocation = qs('#summaryLocation');
  const summaryClosedTime = qs('#summaryClosedTime');
  const summaryDispatchTime = qs('#summaryDispatchTime');
  const summaryOnSceneTime = qs('#summaryOnSceneTime');
  const summaryResponseTime = qs('#summaryResponseTime');
  const summaryResolutionTime = qs('#summaryResolutionTime');
  const summaryUnit = qs('#summaryUnit');
  const summaryDriver = qs('#summaryDriver');
  const summaryVehicle = qs('#summaryVehicle');
  const summaryPlate = qs('#summaryPlate');
  const summaryAverageRating = qs('#summaryAverageRating');
  const summaryRatingCount = qs('#summaryRatingCount');
  const summaryFeedbackCount = qs('#summaryFeedbackCount');
  const summaryLastUpdated = qs('#summaryLastUpdated');

  const feedbackIncidentId = qs('#feedbackIncidentId');
  const ratingInput = qs('#ratingInput');
  const ratingHelper = qs('#ratingHelper');
  const feedbackNoteInput = qs('#feedbackNoteInput');
  const feedbackSummary = qs('#feedbackSummary');
  const feedbackList = qs('#feedbackList');
  const proofGallery = qs('#proofGallery');

  let currentItems = [];
  let currentIncident = null;
  let selectedRating = 0;
  let searchDebounceTimer = null;
  let loadRequestSeq = 0;

  function buildQuery() {
    const params = new URLSearchParams();
    const status = statusFilter?.value || 'closed';
    params.set('status', status);

    const day = dayFilter?.value || '';
    if (day) params.set('day', day);

    const search = (searchInput?.value || '').trim();
    if (search) params.set('search', search);

    return params.toString();
  }

  async function loadIncidents() {
    const requestSeq = ++loadRequestSeq;
    container.innerHTML = loadingMarkup('Loading closed incidents...');
    setApplyLoading(true);

    try {
      const response = await fetch('api/incidents_list.php?' + buildQuery(), { cache: 'no-store' });
      const data = await response.json();
      if (requestSeq !== loadRequestSeq) return;
      if (!data.ok) throw new Error(data.error || 'Failed to load incidents');

      currentItems = sortItems(data.items || []);
      renderStats(currentItems);
      renderIncidents(currentItems);
    } catch (error) {
      if (requestSeq !== loadRequestSeq) return;
      container.innerHTML = emptyMarkup('fas fa-triangle-exclamation', 'Failed to load closed incidents.', escapeHtml(error.message));
      resultsMeta.textContent = 'Unable to load results.';
    } finally {
      if (requestSeq === loadRequestSeq) {
        setApplyLoading(false);
      }
    }
  }

  function renderStats(items) {
    const responseValues = items
      .map(item => toNumber(item.response_time_min))
      .filter(value => value !== null);

    const ratingValues = items
      .map(item => toNumber(item.avg_rating))
      .filter(value => value !== null);

    const trackedUnits = items.filter(item => {
      return Boolean(
        clean(item.assigned_unit) ||
        clean(item.driver_name) ||
        clean(item.plate_number) ||
        clean(item.vehicle_name)
      );
    }).length;

    statClosedIncidents.textContent = String(items.length);
    statAverageResponse.textContent = responseValues.length ? formatMinutes(avg(responseValues)) : '--';
    statAverageRating.textContent = ratingValues.length ? formatRating(avg(ratingValues)) : '--';
    statUnitsTracked.textContent = String(trackedUnits);

    if (!items.length) {
      resultsMeta.textContent = 'No closed incidents matched the current filters.';
      return;
    }

    const resolvedCount = items.filter(item => normalizeStatus(item.status) === 'resolved').length;
    const cancelledCount = items.filter(item => normalizeStatus(item.status) === 'cancelled').length;
    resultsMeta.textContent = `${items.length} closed incidents loaded. Resolved: ${resolvedCount}, Cancelled: ${cancelledCount}.`;
  }

  function renderIncidents(items) {
    if (!items.length) {
      container.innerHTML = emptyMarkup('fas fa-inbox', 'No closed incidents found.', 'Try a different date, search term, or status filter.');
      return;
    }

    const rows = items.map(item => {
      const status = normalizeStatus(item.status);
      const priority = normalizePriority(item.priority);
      const ratingText = toNumber(item.avg_rating) !== null
        ? `${formatRating(item.avg_rating)} (${Number(item.rating_count || 0)})`
        : 'No ratings';
      const driver = clean(item.driver_name) || 'Not recorded';
      const plate = clean(item.plate_number) || 'Not recorded';
      const vehicle = clean(item.vehicle_name) || clean(item.assigned_unit) || 'Not recorded';
      const unit = clean(item.assigned_unit) || 'Unassigned';
      const code = item.incident_code || item.reference_no || 'No reference';
      const closedAt = item.resolved_at || item.cleared_at;
      const incidentId = escapeAttribute(item.id || '');

      return `
        <tr class="priority-${priority}">
          <td class="review-table-incident">
            <strong>${escapeHtml(code)}</strong>
            <span>${escapeHtml(item.type || 'Incident')}</span>
            <small>${escapeHtml(item.description || 'No incident description provided.')}</small>
          </td>
          <td>
            <div class="review-table-chips">
              <span class="status-chip status-${status}">${escapeHtml(statusLabel(status))}</span>
              <span class="priority-chip priority-${priority}">${escapeHtml(priorityLabel(priority))}</span>
            </div>
          </td>
          <td class="review-table-location">${escapeHtml(item.location || 'No location recorded')}</td>
          <td>
            <div class="review-table-stack">
              <span><strong>Response:</strong> ${escapeHtml(formatMinutes(item.response_time_min))}</span>
              <span><strong>Resolution:</strong> ${escapeHtml(formatMinutes(item.resolution_time_min))}</span>
            </div>
          </td>
          <td>
            <div class="review-table-stack">
              <span><strong>Unit:</strong> ${escapeHtml(unit)}</span>
              <span><strong>Vehicle:</strong> ${escapeHtml(vehicle)}</span>
              <span><strong>Driver:</strong> ${escapeHtml(driver)}</span>
              <span><strong>Plate:</strong> ${escapeHtml(plate)}</span>
            </div>
          </td>
          <td>${escapeHtml(ratingText)}</td>
          <td>
            <div class="review-table-stack">
              <span><strong>Reported:</strong> ${escapeHtml(formatDate(item.created_at))}</span>
              <span><strong>Closed:</strong> ${escapeHtml(formatDate(closedAt))}</span>
            </div>
          </td>
          <td class="review-table-actions">
            <button type="button" class="btn-card-action" data-open-review="${incidentId}">
              <i class="fas fa-eye"></i> View
            </button>
            <button type="button" class="btn-card-action primary" data-open-review="${incidentId}">
              <i class="fas fa-star"></i> Feedback
            </button>
          </td>
        </tr>
      `;
    }).join('');

    container.innerHTML = `
      <div class="review-table-shell">
        <table class="review-incidents-table">
          <thead>
            <tr>
              <th>Incident</th>
              <th>Status</th>
              <th>Location</th>
              <th>Timeline</th>
              <th>Responder / Vehicle</th>
              <th>Rating</th>
              <th>Dates</th>
              <th>Action</th>
            </tr>
          </thead>
          <tbody>${rows}</tbody>
        </table>
      </div>
    `;

    qsa('[data-open-review]', container).forEach(button => {
      button.addEventListener('click', () => {
        const incidentId = parseInt(button.getAttribute('data-open-review') || '', 10);
        if (Number.isInteger(incidentId)) {
          openReviewModal(incidentId);
        }
      });
    });
  }

  async function openReviewModal(incidentId) {
    resetModalState();
    feedbackIncidentId.value = String(incidentId);
    showModal();

    try {
      const [detailsRes, feedbackRes, proofsRes] = await Promise.all([
        fetch('api/incident_details.php?id=' + encodeURIComponent(incidentId), { cache: 'no-store' }),
        fetch('api/incident_feedback.php?incident_id=' + encodeURIComponent(incidentId), { cache: 'no-store' }),
        fetch('api/incident_proofs.php?incident_id=' + encodeURIComponent(incidentId), { cache: 'no-store' })
      ]);

      const detailsData = await detailsRes.json();
      const feedbackData = await feedbackRes.json();
      const proofsData = await proofsRes.json();

      if (!detailsData.ok || !detailsData.incident) {
        throw new Error(detailsData.error || 'Incident details not available');
      }

      currentIncident = detailsData.incident;
      populateIncidentSummary(detailsData.incident);
      renderFeedback(feedbackData);
      renderProofs(proofsData);
    } catch (error) {
      feedbackList.innerHTML = `<div class="feedback-empty">${escapeHtml(error.message || 'Unable to load incident review.')}</div>`;
      proofGallery.innerHTML = '<div class="proof-empty">Unable to load proof gallery.</div>';
    }
  }

  function populateIncidentSummary(incident) {
    const status = normalizeStatus(incident.status);
    const priority = normalizePriority(incident.priority);
    const closedAt = incident.resolved_at || incident.cleared_at || incident.updated_at || '';

    modalTitle.textContent = `Closed Incident ${incident.reference_no || incident.id || ''}`;
    modalStatusBadge.className = `status-chip status-${status}`;
    modalStatusBadge.textContent = statusLabel(status);
    modalPriorityBadge.className = `priority-chip priority-${priority}`;
    modalPriorityBadge.textContent = priorityLabel(priority);

    summaryCode.textContent = incident.reference_no || `Incident #${incident.id || '--'}`;
    summaryType.textContent = incident.type || 'Incident';
    summaryDescription.textContent = incident.description || 'No incident description provided.';
    summaryLocation.textContent = incident.location_address || 'No location recorded';
    summaryClosedTime.textContent = `Closed: ${formatDate(closedAt)}`;

    summaryDispatchTime.textContent = formatDate(incident.dispatch_assigned_at || incident.assigned_at || incident.created_at);
    summaryOnSceneTime.textContent = formatDate(incident.on_scene_at);
    summaryResponseTime.textContent = formatMinutes(incident.response_time_min);
    summaryResolutionTime.textContent = formatMinutes(incident.resolution_time_min);

    summaryUnit.textContent = clean(incident.assigned_unit_identifier) || 'Unassigned';
    summaryDriver.textContent = clean(incident.driver_name) || 'Not recorded';
    summaryVehicle.textContent = clean(incident.vehicle_name) || clean(incident.assigned_unit_identifier) || 'Not recorded';
    summaryPlate.textContent = clean(incident.plate_number) || 'Not recorded';

    summaryAverageRating.textContent = toNumber(incident.avg_rating) !== null ? formatRating(incident.avg_rating) : '--';
    summaryRatingCount.textContent = String(Number(incident.rating_count || 0));
    summaryFeedbackCount.textContent = String(Number(incident.feedback_count || 0));
    summaryLastUpdated.textContent = formatDate(incident.updated_at || closedAt);
  }

  function renderFeedback(payload) {
    if (!payload || !payload.ok) {
      feedbackSummary.innerHTML = '';
      feedbackList.innerHTML = '<div class="feedback-empty">Unable to load feedback history.</div>';
      return;
    }

    const summary = payload.summary || {};
    const notes = Array.isArray(payload.data) ? payload.data : [];

    const chips = [];
    chips.push(`<span class="feedback-summary-chip"><i class="fas fa-comments"></i> ${Number(summary.feedback_count || notes.length || 0)} feedback</span>`);
    if (toNumber(summary.avg_rating) !== null) {
      chips.push(`<span class="feedback-summary-chip"><i class="fas fa-star"></i> ${escapeHtml(formatRating(summary.avg_rating))} average</span>`);
    }
    if (Number(summary.rating_count || 0) > 0) {
      chips.push(`<span class="feedback-summary-chip"><i class="fas fa-chart-simple"></i> ${Number(summary.rating_count)} rated</span>`);
    }
    feedbackSummary.innerHTML = chips.join('');

    if (!notes.length) {
      feedbackList.innerHTML = '<div class="feedback-empty">No feedback yet. Add the first dispatcher review for this incident.</div>';
      return;
    }

    feedbackList.innerHTML = notes.map(note => {
      const rating = clampRating(note.rating);
      return `
        <div class="feedback-item">
          <div class="feedback-item-header">
            <div>
              <div class="feedback-author">${escapeHtml(note.author_name || 'Anonymous')}</div>
              <div class="feedback-date">${escapeHtml(formatDate(note.created_at))}</div>
            </div>
            <div class="feedback-stars">${renderStars(rating)}</div>
          </div>
          <p class="feedback-note">${escapeHtml(note.note || (rating ? 'Submitted a rating without additional notes.' : 'No note provided.'))}</p>
        </div>
      `;
    }).join('');
  }

  function renderProofs(payload) {
    if (!payload || !payload.ok) {
      proofGallery.innerHTML = '<div class="proof-empty">Unable to load proof images.</div>';
      return;
    }

    const items = Array.isArray(payload.items) ? payload.items : [];
    if (!items.length) {
      proofGallery.innerHTML = '<div class="proof-empty">No resolution proof uploaded for this incident yet.</div>';
      return;
    }

    proofGallery.innerHTML = items.map(item => `
      <figure class="proof-card">
        <img src="${escapeAttribute(item.url || '')}" alt="Incident resolution proof">
        <figcaption class="proof-meta">${escapeHtml(formatDate(item.created_at))}</figcaption>
      </figure>
    `).join('');
  }

  async function saveFeedback() {
    const incidentId = parseInt(feedbackIncidentId.value || '', 10);
    const note = (feedbackNoteInput?.value || '').trim();
    const rating = selectedRating > 0 ? selectedRating : null;

    if (!Number.isInteger(incidentId) || incidentId < 1) {
      alert('Missing incident reference for feedback.');
      return;
    }

    if (!note && rating === null) {
      alert('Maglagay ng rating o feedback note bago i-save.');
      return;
    }

    saveFeedbackBtn.disabled = true;

    try {
      const response = await fetch('api/incident_feedback.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          incident_id: incidentId,
          author_name: reviewerName,
          note,
          rating
        })
      });

      const data = await response.json();
      if (!data.ok) {
        throw new Error(data.error || 'Unable to save feedback');
      }

      feedbackNoteInput.value = '';
      setSelectedRating(0);

      await Promise.all([
        refreshFeedbackInModal(incidentId),
        refreshIncidentSummary(incidentId),
        loadIncidents()
      ]);
    } catch (error) {
      alert('Failed to save feedback: ' + error.message);
    } finally {
      saveFeedbackBtn.disabled = false;
    }
  }

  async function refreshFeedbackInModal(incidentId) {
    const response = await fetch('api/incident_feedback.php?incident_id=' + encodeURIComponent(incidentId), { cache: 'no-store' });
    const payload = await response.json();
    renderFeedback(payload);
  }

  async function refreshIncidentSummary(incidentId) {
    const response = await fetch('api/incident_details.php?id=' + encodeURIComponent(incidentId), { cache: 'no-store' });
    const payload = await response.json();
    if (payload.ok && payload.incident) {
      currentIncident = payload.incident;
      populateIncidentSummary(payload.incident);
    }
  }

  function showModal() {
    modalOverlay.hidden = false;
    modal.hidden = false;
    document.body.classList.add('review-modal-open');
  }

  function hideModal() {
    modalOverlay.hidden = true;
    modal.hidden = true;
    document.body.classList.remove('review-modal-open');
  }

  function resetModalState() {
    currentIncident = null;
    setSelectedRating(0);
    feedbackNoteInput.value = '';
    modalTitle.textContent = 'Closed Incident Details';
    summaryCode.textContent = '--';
    summaryType.textContent = '--';
    summaryDescription.textContent = '--';
    summaryLocation.textContent = '--';
    summaryClosedTime.textContent = 'Closed: --';
    summaryDispatchTime.textContent = '--';
    summaryOnSceneTime.textContent = '--';
    summaryResponseTime.textContent = '--';
    summaryResolutionTime.textContent = '--';
    summaryUnit.textContent = '--';
    summaryDriver.textContent = '--';
    summaryVehicle.textContent = '--';
    summaryPlate.textContent = '--';
    summaryAverageRating.textContent = '--';
    summaryRatingCount.textContent = '0';
    summaryFeedbackCount.textContent = '0';
    summaryLastUpdated.textContent = '--';
    modalStatusBadge.className = 'status-chip';
    modalPriorityBadge.className = 'priority-chip';
    feedbackSummary.innerHTML = '';
    feedbackList.innerHTML = '<div class="feedback-empty">Loading feedback...</div>';
    proofGallery.innerHTML = '<div class="proof-empty">Loading proof gallery...</div>';
  }

  function setSelectedRating(value) {
    selectedRating = clampRating(value);
    qsa('.rating-star', ratingInput).forEach(button => {
      const starValue = clampRating(button.dataset.rating);
      button.classList.toggle('is-active', starValue !== null && starValue <= selectedRating);
    });

    ratingHelper.textContent = selectedRating > 0
      ? `${selectedRating} out of 5 selected.`
      : 'Select a rating from 1 to 5.';
  }

  function sortItems(items) {
    const mode = sortSelect?.value || 'recent';
    const copy = [...items];

    if (mode === 'rating_desc') {
      copy.sort((a, b) => {
        const ra = toNumber(a.avg_rating) ?? -1;
        const rb = toNumber(b.avg_rating) ?? -1;
        return rb - ra || compareRecent(b, a);
      });
      return copy;
    }

    if (mode === 'response_asc') {
      copy.sort((a, b) => {
        const ra = toNumber(a.response_time_min);
        const rb = toNumber(b.response_time_min);
        if (ra === null && rb === null) return compareRecent(b, a);
        if (ra === null) return 1;
        if (rb === null) return -1;
        return ra - rb || compareRecent(b, a);
      });
      return copy;
    }

    if (mode === 'priority_desc') {
      const weight = value => ({ high: 3, medium: 2, low: 1 })[normalizePriority(value)] || 0;
      copy.sort((a, b) => weight(b.priority) - weight(a.priority) || compareRecent(b, a));
      return copy;
    }

    if (mode === 'code_asc') {
      copy.sort((a, b) => String(a.incident_code || '').localeCompare(String(b.incident_code || '')));
      return copy;
    }

    copy.sort((a, b) => compareRecent(a, b));
    return copy;
  }

  function compareRecent(a, b) {
    const aTime = timestampOf(a);
    const bTime = timestampOf(b);
    return bTime - aTime || (Number(b.id || 0) - Number(a.id || 0));
  }

  function timestampOf(item) {
    const value = item?.resolved_at || item?.cleared_at || item?.updated_at || item?.created_at || 0;
    const stamp = new Date(value).getTime();
    return Number.isFinite(stamp) ? stamp : 0;
  }

  function normalizeStatus(value) {
    const raw = clean(value).toLowerCase();
    if (raw === 'cancelled' || raw === 'closed') return 'cancelled';
    return 'resolved';
  }

  function normalizePriority(value) {
    const raw = clean(value).toLowerCase();
    if (raw === 'high') return 'high';
    if (raw === 'medium') return 'medium';
    return 'low';
  }

  function statusLabel(status) {
    return status === 'cancelled' ? 'Closed / Cancelled' : 'Resolved';
  }

  function priorityLabel(priority) {
    if (priority === 'high') return 'High Priority';
    if (priority === 'medium') return 'Medium Priority';
    return 'Low Priority';
  }

  function renderStars(rating) {
    if (!rating) return '<span class="feedback-date">No rating</span>';
    return Array.from({ length: 5 }, (_, index) => {
      const filled = index < rating;
      return `<i class="${filled ? 'fas' : 'far'} fa-star"></i>`;
    }).join('');
  }

  function formatDate(value) {
    if (!value) return '--';
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return date.toLocaleString();
  }

  function formatMinutes(value) {
    const minutes = toNumber(value);
    if (minutes === null) return '--';
    if (minutes < 60) return `${Math.round(minutes)} min`;
    const hours = Math.floor(minutes / 60);
    const remaining = Math.round(minutes % 60);
    return remaining ? `${hours}h ${remaining}m` : `${hours}h`;
  }

  function formatRating(value) {
    const rating = toNumber(value);
    if (rating === null) return '--';
    return `${rating.toFixed(1)} / 5`;
  }

  function clampRating(value) {
    const rating = Number(value);
    if (!Number.isFinite(rating)) return 0;
    if (rating < 1 || rating > 5) return 0;
    return Math.round(rating);
  }

  function toNumber(value) {
    if (value === null || value === undefined || value === '') return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
  }

  function avg(values) {
    if (!values.length) return null;
    return values.reduce((sum, value) => sum + value, 0) / values.length;
  }

  function clean(value) {
    return String(value || '').trim();
  }

  function loadingMarkup(message) {
    return `<div class="loading-state"><i class="fas fa-spinner fa-spin"></i>${escapeHtml(message)}</div>`;
  }

  function emptyMarkup(icon, title, description) {
    return `
      <div class="empty-state">
        <i class="${escapeHtml(icon)}"></i>
        <strong>${escapeHtml(title)}</strong>
        <p>${escapeHtml(description)}</p>
      </div>
    `;
  }

  function setApplyLoading(isLoading) {
    if (!applyFiltersBtn) return;
    applyFiltersBtn.disabled = isLoading;
    applyFiltersBtn.setAttribute('aria-disabled', isLoading ? 'true' : 'false');
  }

  function scheduleSearchLoad(delay = 350) {
    if (searchDebounceTimer) {
      window.clearTimeout(searchDebounceTimer);
    }
    searchDebounceTimer = window.setTimeout(() => {
      searchDebounceTimer = null;
      loadIncidents();
    }, delay);
  }

  function cancelSearchLoad() {
    if (!searchDebounceTimer) return;
    window.clearTimeout(searchDebounceTimer);
    searchDebounceTimer = null;
  }

  function escapeHtml(value) {
    return String(value)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function escapeAttribute(value) {
    return escapeHtml(value);
  }

  ratingInput?.addEventListener('click', event => {
    const button = event.target.closest('.rating-star');
    if (!button) return;
    setSelectedRating(button.dataset.rating);
  });

  applyFiltersBtn?.addEventListener('click', () => {
    cancelSearchLoad();
    loadIncidents();
  });
  clearFiltersBtn?.addEventListener('click', () => {
    cancelSearchLoad();
    if (statusFilter) statusFilter.value = 'closed';
    if (dayFilter) dayFilter.value = '';
    if (searchInput) searchInput.value = '';
    if (sortSelect) sortSelect.value = 'recent';
    loadIncidents();
  });

  statusFilter?.addEventListener('change', loadIncidents);
  dayFilter?.addEventListener('change', loadIncidents);
  sortSelect?.addEventListener('change', () => {
    currentItems = sortItems(currentItems);
    renderStats(currentItems);
    renderIncidents(currentItems);
  });
  searchInput?.addEventListener('keydown', event => {
    if (event.key === 'Enter') {
      event.preventDefault();
      cancelSearchLoad();
      loadIncidents();
    }
  });
  searchInput?.addEventListener('input', () => scheduleSearchLoad());

  modalOverlay?.addEventListener('click', hideModal);
  modalClose?.addEventListener('click', hideModal);
  closeFeedbackBtn?.addEventListener('click', hideModal);
  saveFeedbackBtn?.addEventListener('click', saveFeedback);

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && modal && !modal.hidden) {
      hideModal();
    }
  });

  hideModal();
  loadIncidents();
})();
