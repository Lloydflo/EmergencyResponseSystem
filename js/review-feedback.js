(function () {
  const qs = (selector, ctx = document) => ctx ? ctx.querySelector(selector) : null;
  const qsa = (selector, ctx = document) => ctx ? Array.from(ctx.querySelectorAll(selector)) : [];

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
  const modalDialog = qs('.review-modal-dialog');
  const modalClose = qs('#modalClose');
  const closeFeedbackBtn = qs('#closeFeedbackBtn');
  const saveFeedbackBtn = qs('#saveFeedbackBtn');
  const incidentDetailPanel = qs('#incidentDetailPanel');
  const feedbackReviewPanel = qs('#feedbackReviewPanel');

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
  const adminSubmissionStatus = qs('#adminSubmissionStatus');
  const feedbackSummary = qs('#feedbackSummary');
  const feedbackList = qs('#feedbackList');
  const proofGallery = qs('#proofGallery');

  let currentItems = [];
  let currentIncident = null;
  let currentAdminSubmission = null;
  let selectedRating = 0;
  let searchDebounceTimer = null;
  let loadRequestSeq = 0;
  const REVIEW_PAGE_SIZE = 8;
  let visibleReviewLimit = REVIEW_PAGE_SIZE;
  const PH_TIME_ZONE = 'Asia/Manila';
  const PH_DATE_FORMATTER = new Intl.DateTimeFormat('en-PH', {
    timeZone: PH_TIME_ZONE,
    year: 'numeric',
    month: 'short',
    day: '2-digit',
    hour: 'numeric',
    minute: '2-digit',
    second: '2-digit',
    hour12: true,
    timeZoneName: 'short'
  });

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
      visibleReviewLimit = REVIEW_PAGE_SIZE;
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

    const visibleItems = items.slice(0, visibleReviewLimit);
    const cards = visibleItems.map(item => {
      const status = normalizeStatus(item.status);
      const priority = normalizePriority(item.priority);
      const ratingText = toNumber(item.avg_rating) !== null
        ? `${formatRating(item.avg_rating)} (${Number(item.rating_count || 0)})`
        : 'No ratings';
      const adminReviewSent = Boolean(item.submitted_to_admin);
      const adminReviewText = adminReviewSent
        ? `Sent to admin${item.admin_review_sent_at ? ' ' + formatDate(item.admin_review_sent_at) : ''}`
        : 'Not sent to admin';
      const driver = clean(item.driver_name) || 'Not recorded';
      const plate = clean(item.plate_number) || 'Not recorded';
      const vehicle = clean(item.vehicle_name) || clean(item.assigned_unit) || 'Not recorded';
      const unit = clean(item.assigned_unit) || 'Unassigned';
      const code = item.incident_code || item.reference_no || 'No reference';
      const closedAt = item.resolved_at || item.cleared_at;
      const incidentId = escapeAttribute(item.id || '');

      return `
        <article class="review-card priority-${priority}" data-review-incident="${incidentId}">
          <header class="review-card-header">
            <div>
              <p class="review-card-ref">${escapeHtml(code)}</p>
              <p class="review-card-type">${escapeHtml(item.type || 'Incident')}</p>
            </div>
            <div class="review-card-badges">
              <span class="status-chip status-${status}">${escapeHtml(statusLabel(status))}</span>
              <span class="priority-chip priority-${priority}">${escapeHtml(priorityLabel(priority))}</span>
            </div>
          </header>
          <div class="review-card-body">
            <div class="review-card-copy">
              <span class="field-label"><i class="fas fa-location-dot"></i> Location</span>
              <p class="review-card-description">${escapeHtml(item.location || 'No location recorded')}</p>
              <div class="review-card-assignment">
                <span class="field-label"><i class="fas fa-truck-medical"></i> Response assignment</span>
                <strong>${escapeHtml(unit)}</strong>
                <span>${escapeHtml(driver)} · ${escapeHtml(vehicle)} · ${escapeHtml(plate)}</span>
              </div>
            </div>
            <div class="review-card-metrics">
              <div class="metric-pill"><span class="label">Response</span><strong>${escapeHtml(formatMinutes(item.response_time_min))}</strong></div>
              <div class="metric-pill"><span class="label">Resolution</span><strong>${escapeHtml(formatMinutes(item.resolution_time_min))}</strong></div>
              <div class="metric-pill"><span class="label">Rating</span><strong>${escapeHtml(ratingText)}</strong></div>
              <div class="metric-pill ${adminReviewSent ? 'is-sent' : 'is-pending'}"><span class="label">Admin review</span><strong>${escapeHtml(adminReviewText)}</strong></div>
            </div>
          </div>
          <footer class="review-card-footer">
            <div class="review-card-times">
              <span><i class="far fa-calendar-plus"></i> Reported ${escapeHtml(formatDate(item.created_at))}</span>
              <span><i class="fas fa-circle-check"></i> Closed ${escapeHtml(formatDate(closedAt))}</span>
            </div>
            <div class="review-card-actions">
              <button type="button" class="btn-card-action primary" data-open-review="${incidentId}" data-review-mode="details">
                <i class="fas fa-eye"></i> View Review
              </button>
            </div>
          </footer>
        </article>
      `;
    }).join('');

    const remaining = Math.max(0, items.length - visibleItems.length);
    const reveal = remaining > 0
      ? `<div class="review-reveal"><span>Showing ${visibleItems.length} of ${items.length} incidents</span><button type="button" data-show-more-reviews><i class="fas fa-chevron-down"></i> Show ${Math.min(REVIEW_PAGE_SIZE, remaining)} more</button></div>`
      : `<div class="review-reveal is-complete"><span>Showing all ${items.length} incidents</span></div>`;

    container.innerHTML = cards + reveal;

  }

  async function openReviewModal(incidentId, mode = 'details') {
    resetModalState();
    if (feedbackIncidentId) {
      feedbackIncidentId.value = String(incidentId);
    }
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
      focusReviewModalSection(mode);
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
    summaryDescription.textContent = displayNarrative(incident.description);
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
    currentAdminSubmission = payload.admin_review || null;
    renderAdminSubmissionStatus(currentAdminSubmission);

    const chips = [];
    chips.push(`<span class="feedback-summary-chip"><i class="fas fa-comments"></i> ${Number(summary.feedback_count || notes.length || 0)} feedback</span>`);
    if (toNumber(summary.avg_rating) !== null) {
      chips.push(`<span class="feedback-summary-chip"><i class="fas fa-star"></i> ${escapeHtml(formatRating(summary.avg_rating))} average</span>`);
    }
    if (Number(summary.rating_count || 0) > 0) {
      chips.push(`<span class="feedback-summary-chip"><i class="fas fa-chart-bar"></i> ${Number(summary.rating_count)} rated</span>`);
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

  function renderAdminSubmissionStatus(submission) {
    const submitted = Boolean(submission && submission.submitted);
    if (adminSubmissionStatus) {
      if (submitted) {
        const sentAt = formatDate(submission.sent_at);
        const sentBy = clean(submission.sent_by_name) || 'Dispatcher';
        adminSubmissionStatus.innerHTML = `
          <span class="admin-submission-chip sent"><i class="fas fa-circle-check"></i> Sent to Admin</span>
          <span>${escapeHtml(sentBy)}${sentAt !== '--' ? ' - ' + escapeHtml(sentAt) : ''}</span>
        `;
      } else {
        adminSubmissionStatus.innerHTML = `
          <span class="admin-submission-chip pending"><i class="fas fa-clock"></i> Dispatcher Review Only</span>
          <span>Hindi pa makikita ng admin hanggang hindi ito naipapadala.</span>
        `;
      }
    }

    if (saveFeedbackBtn) {
      saveFeedbackBtn.innerHTML = submitted
        ? '<i class="fas fa-rotate"></i> Update Admin Review'
        : '<i class="fas fa-paper-plane"></i> Send to Admin';
    }
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

    proofGallery.innerHTML = items.map(item => {
      const proofUrl = escapeAttribute(normalizeProofUrl(item.url || ''));
      return `
        <figure class="proof-card">
          <a href="${proofUrl}" target="_blank" rel="noopener" title="Open full proof image">
            <img src="${proofUrl}" alt="Incident resolution proof">
          </a>
          <figcaption class="proof-meta">${escapeHtml(formatDate(item.created_at))}</figcaption>
        </figure>
      `;
    }).join('');
  }

  async function submitReviewToAdmin() {
    const incidentId = parseInt(feedbackIncidentId.value || '', 10);
    const note = (feedbackNoteInput?.value || '').trim();
    const rating = selectedRating > 0 ? selectedRating : null;

    if (!Number.isInteger(incidentId) || incidentId < 1) {
      alert('Missing incident reference for feedback.');
      return;
    }

    saveFeedbackBtn.disabled = true;
    const originalButtonHtml = saveFeedbackBtn.innerHTML;
    saveFeedbackBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Sending...';

    try {
      if (note || rating !== null) {
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

        if (feedbackNoteInput) {
          feedbackNoteInput.value = '';
        }
        setSelectedRating(0);
      }

      const submitResponse = await fetch('api/incident_admin_review_submit.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
          incident_id: incidentId,
          sender_name: reviewerName
        })
      });
      const submitData = await submitResponse.json();
      if (!submitData.ok) {
        throw new Error(submitData.error || 'Unable to send review to admin');
      }

      currentAdminSubmission = submitData.admin_review || currentAdminSubmission;
      renderAdminSubmissionStatus(currentAdminSubmission);

      await Promise.all([
        refreshFeedbackInModal(incidentId),
        refreshIncidentSummary(incidentId),
        loadIncidents()
      ]);
    } catch (error) {
      alert('Failed to send review: ' + error.message);
    } finally {
      saveFeedbackBtn.disabled = false;
      if (!currentAdminSubmission || !currentAdminSubmission.submitted) {
        saveFeedbackBtn.innerHTML = originalButtonHtml;
      } else {
        renderAdminSubmissionStatus(currentAdminSubmission);
      }
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
    if (modalDialog) {
      modalDialog.scrollTop = 0;
    }
  }

  function hideModal() {
    modalOverlay.hidden = true;
    modal.hidden = true;
    document.body.classList.remove('review-modal-open');
  }

  function resetModalState() {
    currentIncident = null;
    currentAdminSubmission = null;
    setSelectedRating(0);
    if (feedbackNoteInput) {
      feedbackNoteInput.value = '';
    }
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
    renderAdminSubmissionStatus(null);
    feedbackList.innerHTML = '<div class="feedback-empty">Loading feedback...</div>';
    proofGallery.innerHTML = '<div class="proof-empty">Loading proof gallery...</div>';
  }

  function focusReviewModalSection(mode) {
    if (!modalDialog) return;

    const isFeedbackMode = mode === 'feedback';
    const target = isFeedbackMode ? feedbackReviewPanel : incidentDetailPanel;

    window.requestAnimationFrame(() => {
      if (!target) {
        modalDialog.scrollTo({ top: 0, behavior: 'auto' });
        return;
      }

      if (isFeedbackMode) {
        const dialogBox = modalDialog.getBoundingClientRect();
        const targetBox = target.getBoundingClientRect();
        const nextTop = modalDialog.scrollTop + targetBox.top - dialogBox.top - 16;
        modalDialog.scrollTo({ top: Math.max(0, nextTop), behavior: 'smooth' });
        target.classList.add('review-panel-focus');
        window.setTimeout(() => target.classList.remove('review-panel-focus'), 1200);
        const focusTarget = qs('.rating-star', ratingInput) || feedbackNoteInput;
        if (focusTarget) {
          window.setTimeout(() => focusTarget.focus({ preventScroll: true }), 260);
        }
        return;
      }

      modalDialog.scrollTo({ top: 0, behavior: 'auto' });
    });
  }

  function setSelectedRating(value) {
    selectedRating = clampRating(value);
    qsa('.rating-star', ratingInput).forEach(button => {
      const starValue = clampRating(button.dataset.rating);
      button.classList.toggle('is-active', starValue !== null && starValue <= selectedRating);
    });

    if (ratingHelper) {
      ratingHelper.textContent = selectedRating > 0
        ? `${selectedRating} out of 5 selected.`
        : 'Select a rating from 1 to 5.';
    }
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
    const stamp = parseDateValue(value).getTime();
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
    const date = parseDateValue(value);
    if (Number.isNaN(date.getTime())) return String(value);
    return PH_DATE_FORMATTER.format(date);
  }

  function parseDateValue(value) {
    const raw = String(value || '').trim();
    if (!raw) return new Date(NaN);

    // MySQL DATETIME values in this system are Philippine wall-clock values.
    // Treating a zone-less value as UTC (the previous trailing `Z`) adds eight
    // hours and can move a record to the following day.
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

    // Explicit Z/offset values keep their source timezone and are converted by
    // PH_DATE_FORMATTER to Asia/Manila.
    return new Date(raw);
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

  function displayNarrative(value) {
    const raw = clean(value);
    if (!raw) return 'No incident description provided.';

    const withoutInlineImages = raw.replace(
      /data:image\/[a-z0-9.+-]+;base64,[a-z0-9+/=]+/gi,
      '[Image evidence attached]'
    );

    return withoutInlineImages
      .replace(/(?:Evidence|Photo(?: of evidence)?):\s*\[Image evidence attached\]/gi, 'Evidence: Image attached')
      .replace(/\n{3,}/g, '\n\n')
      .trim();
  }

  function normalizeProofUrl(value) {
    const raw = clean(value);
    if (!raw) return '';
    if (/^(https?:|data:|blob:)/i.test(raw)) return raw;
    return raw.replace(/^\/+/, '');
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
    visibleReviewLimit = REVIEW_PAGE_SIZE;
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

  container?.addEventListener('click', event => {
    const showMoreButton = event.target.closest('[data-show-more-reviews]');
    if (showMoreButton && container.contains(showMoreButton)) {
      visibleReviewLimit += REVIEW_PAGE_SIZE;
      renderIncidents(currentItems);
      return;
    }

    const button = event.target.closest('[data-open-review]');
    if (!button || !container.contains(button)) return;

    event.preventDefault();
    event.stopPropagation();

    const incidentId = parseInt(button.getAttribute('data-open-review') || '', 10);
    const mode = button.getAttribute('data-review-mode') || 'details';
    if (Number.isInteger(incidentId) && incidentId > 0) {
      openReviewModal(incidentId, mode);
    }
  });

  modalOverlay?.addEventListener('click', hideModal);
  modalClose?.addEventListener('click', hideModal);
  closeFeedbackBtn?.addEventListener('click', hideModal);
  saveFeedbackBtn?.addEventListener('click', submitReviewToAdmin);

  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && modal && !modal.hidden) {
      hideModal();
    }
  });

  hideModal();
  loadIncidents();
})();
