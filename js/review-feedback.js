(function(){
  const qs = (sel, ctx=document) => ctx.querySelector(sel);
  const qsa = (sel, ctx=document) => Array.from(ctx.querySelectorAll(sel));

  const container = qs('#incidentsContainer');
  const statusFilter = qs('#statusFilter');
  const dayFilter = qs('#dayFilter');
  const searchInput = qs('#searchInput');
  const applyFiltersBtn = qs('#applyFiltersBtn');
  const clearFiltersBtn = qs('#clearFiltersBtn');
  const sortSelect = qs('#sortSelect');

  const modal = qs('#reviewModal');
  const modalOverlay = qs('#reviewModalOverlay');
  const modalClose = qs('#modalClose');

  const modalTitle = qs('#modalTitle');
  const summaryCode = qs('#summaryCode');
  const summaryType = qs('#summaryType');
  const summaryPriority = qs('#summaryPriority');
  const summaryStatus = qs('#summaryStatus');
  const summaryLocation = qs('#summaryLocation');
  const summaryDescription = qs('#summaryDescription');

  const feedbackList = qs('#feedbackList');
  const feedbackForm = qs('#feedbackForm');
  const feedbackIncidentId = qs('#feedbackIncidentId');
  const authorInput = qs('#authorInput');
  const noteInput = qs('#noteInput');
  const cancelFeedbackBtn = qs('#cancelFeedbackBtn');
  // Tabs
  const tabFeedback = qs('#tabFeedback');
  const tabProof = qs('#tabProof');
  const panelFeedback = qs('#panelFeedback');
  const panelProof = qs('#panelProof');

  const proofGallery = qs('#proofGallery');

  // No local capture/upload on review page; proofs come from responders.

  let currentItems = [];

  function buildQuery(){
    const params = new URLSearchParams();
    const status = statusFilter.value || '';
    // If status is 'all', omit the filter to fetch all incidents
    if (status && status !== 'all') params.set('status', status);
    const day = dayFilter.value || '';
    if (day) params.set('day', day);
    const search = searchInput.value.trim();
    if (search) params.set('search', search);
    const sort = sortSelect?.value || 'recent';
    params.set('sort', sort);
    return params.toString();
  }

  async function loadIncidents(){
    container.innerHTML = skeletonMarkup(6);
    if (applyFiltersBtn){
      applyFiltersBtn.disabled = true;
      applyFiltersBtn.setAttribute('aria-disabled','true');
      applyFiltersBtn.classList.add('is-disabled');
    }
    try {
      const res = await fetch('api/incidents_list.php?' + buildQuery(), { cache: 'no-store' });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to load');
      currentItems = sortItems(data.items || []);
      renderIncidents(currentItems);
    } catch (e) {
      container.innerHTML = `<div class="card"><div class="card-body">Error loading incidents: ${e.message}</div></div>`;
    } finally {
      if (applyFiltersBtn){
        applyFiltersBtn.disabled = false;
        applyFiltersBtn.removeAttribute('aria-disabled');
        applyFiltersBtn.classList.remove('is-disabled');
      }
    }
  }

  function priorityBadge(priority){
    const p = (priority||'').toLowerCase();
    const cls = p==='high' ? 'priority-high' : p==='medium' ? 'priority-medium' : 'priority-low';
    return `<span class="badge ${cls}">${priority||'N/A'}</span>`;
  }

  function renderIncidents(items){
    if (!items.length){
      container.innerHTML = '<div class="card"><div class="card-body">No incidents found for the selected filters.</div></div>';
      return;
    }
    container.innerHTML = items.map(item => {
      const status = (item.status||'').toLowerCase();
      const priority = (item.priority||'').toLowerCase();
      const mood = status.includes('resolved') ? 'success' : (priority==='high' ? 'critical' : (priority==='medium' ? 'warning' : 'info'));
      const iconInfo = typeIcon(item.type || item.title || '');
      const typeLabel = String(item.type||'Incident').toUpperCase();
      const code = escapeHtml(item.incident_code || String(item.id) || '—');
      const details = `${escapeHtml(item.type || '—')} • <span class="chip ${mood}">${escapeHtml(item.status || '—')}</span> ${priorityBadge(item.priority)}`;
      const canReview = status.includes('resolved') || status.includes('cancel');
      return `
        <div class="metric-card incident-card ${mood}" data-id="${item.id}">
          <div class="metric-header">
            <div>
              <div class="metric-title">${typeLabel}</div>
              <div class="metric-value">${code}</div>
            </div>
            <div class="metric-icon ${iconInfo.cls}"><i class="${iconInfo.icon}"></i></div>
          </div>
          <div class="card-text">${details}</div>
          <div class="metric-actions">
            ${canReview ? `<button class="btn-metric review-btn" data-id="${item.id}"><i class="fa fa-clipboard-check"></i> Review</button>` : ''}
          </div>
        </div>`;
    }).join('');
    qsa('.review-btn', container).forEach(btn => btn.addEventListener('click', () => openReviewModal(parseInt(btn.dataset.id, 10))));
  }

  function typeIcon(type){
    const t = String(type).toLowerCase();
    if (t.includes('fire')) return { cls: 'fire', icon: 'fa-solid fa-fire' };
    if (t.includes('medical') || t.includes('health')) return { cls: 'medical', icon: 'fa-solid fa-kit-medical' };
    if (t.includes('police') || t.includes('security')) return { cls: 'users', icon: 'fa-solid fa-shield-halved' };
    if (t.includes('traffic') || t.includes('accident')) return { cls: 'time', icon: 'fa-solid fa-car-burst' };
    if (t.includes('call') || t.includes('report')) return { cls: 'phone', icon: 'fa-solid fa-phone' };
    return { cls: 'info', icon: 'fa-solid fa-circle-info' };
  }

  async function openReviewModal(incidentId){
    try {
      // Details
      const detailsRes = await fetch('api/incident_details.php?id=' + incidentId, { cache: 'no-store' });
      const details = await detailsRes.json();
      const inc = details.incident || {};

      // Populate summary
      modalTitle.textContent = `Review Incident ${inc.reference_no || inc.id || ''}`;
      summaryCode.textContent = inc.reference_no || '—';
      summaryType.textContent = inc.type || '—';
      summaryPriority.textContent = inc.priority || '—';
      summaryStatus.textContent = inc.status || '—';
      summaryLocation.textContent = inc.location_address || '—';
      summaryDescription.textContent = inc.description || '—';

      // Set form incident id
      feedbackIncidentId.value = incidentId;
      authorInput.value = '';
      noteInput.value = '';

      // Load feedback list
      await loadFeedbackList(incidentId);

      // Load proofs
      await loadProofs(incidentId);

      showModal();
    } catch (e) {
      alert('Failed to load incident details: ' + e.message);
    }
  }

  async function loadFeedbackList(incidentId){
    feedbackList.innerHTML = '<div class="feedback-item"><div class="meta">Loading feedback…</div></div>';
    try {
      const res = await fetch('api/incident_feedback.php?incident_id=' + incidentId, { cache: 'no-store' });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to load');
      const notes = data.data || [];
      if (!notes.length){
        feedbackList.innerHTML = '<div class="feedback-item"><div class="meta">No feedback yet. Be the first to add one.</div></div>';
        return;
      }
      feedbackList.innerHTML = notes.map(n => {
        const author = String(n.author_name || 'Anonymous');
        const isResponder = author.toLowerCase().startsWith('responder:');
          const authorHtml = isResponder
            ? `<span class="chip responder">Responder</span> ${escapeHtml(author)}`
          : escapeHtml(author);
        return `
        <div class="feedback-item">
          <div class="meta">${authorHtml} • ${formatDate(n.created_at)}</div>
          <div class="text">${escapeHtml(n.note || '')}</div>
        </div>`;
      }).join('');
    } catch (e) {
      feedbackList.innerHTML = `<div class="feedback-item"><div class="meta">Error loading feedback: ${e.message}</div></div>`;
    }
  }

  function showModal(){
    modalOverlay.hidden = false;
    modal.hidden = false;
  }
  function hideModal(){
    modalOverlay.hidden = true;
    modal.hidden = true;
  }

  modalOverlay.addEventListener('click', hideModal);
  modalClose.addEventListener('click', hideModal);
  cancelFeedbackBtn.addEventListener('click', hideModal);

  // Close modal with Escape key
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && !modal.hidden){ hideModal(); }
  });

  feedbackForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const incident_id = parseInt(feedbackIncidentId.value, 10);
    const author_name = authorInput.value.trim() || 'Anonymous';
    const note = noteInput.value.trim();
    if (!note){
      alert('Please enter a feedback note.');
      return;
    }
    try {
      const res = await fetch('api/incident_feedback.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ incident_id, author_name, note })
      });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Submission failed');
      noteInput.value = '';
      await loadFeedbackList(incident_id);
    } catch (e) {
      alert('Failed to submit feedback: ' + e.message);
    }
  });

  // ----- Proofs -----
  async function loadProofs(incidentId){
    proofGallery.innerHTML = '<div class="gallery-empty">Loading proofs…</div>';
    try {
      const res = await fetch('api/incident_proofs.php?incident_id=' + incidentId, { cache: 'no-store' });
      const data = await res.json();
      if (!data.ok) throw new Error(data.error || 'Failed to load');
      const items = data.items || [];
      if (!items.length){
        proofGallery.innerHTML = '<div class="gallery-empty">No responder proofs yet.</div>';
        return;
      }
      proofGallery.innerHTML = items.map(p => `
        <div class="gallery-item">
          <img src="${escapeHtml(p.url)}" alt="Proof" />
          <div class="gallery-meta">${formatDate(p.created_at)}</div>
        </div>
      `).join('');
    } catch (e) {
      proofGallery.innerHTML = `<div class="gallery-empty">Error loading proofs: ${escapeHtml(e.message)}</div>`;
    }
  }
  // No upload or camera event bindings; review page is read-only for proofs.

  applyFiltersBtn.addEventListener('click', loadIncidents);
  // Auto-apply on filter changes and Enter in search
  statusFilter.addEventListener('change', loadIncidents);
  dayFilter.addEventListener('change', loadIncidents);
  searchInput.addEventListener('keypress', (e)=>{ if (e.key === 'Enter') loadIncidents(); });
  clearFiltersBtn.addEventListener('click', () => {
    statusFilter.value = 'dispatched';
    dayFilter.value = '';
    searchInput.value = '';
    sortSelect.value = 'recent';
    loadIncidents();
  });
  sortSelect.addEventListener('change', () => {
    currentItems = sortItems(currentItems);
    renderIncidents(currentItems);
  });

  function escapeHtml(str){
    return String(str)
      .replaceAll('&', '&amp;')
      .replaceAll('<', '&lt;')
      .replaceAll('>', '&gt;')
      .replaceAll('"', '&quot;')
      .replaceAll("'", '&#039;');
  }

  function formatDate(d){
    if (!d) return '—';
    try {
      const dt = new Date(d);
      return dt.toLocaleString();
    } catch { return String(d); }
  }

  function skeletonMarkup(n){
    return `<div class="skeleton-grid">${Array.from({length:n}).map(()=>`
      <div class="skeleton-card">
        <div style="display:flex;justify-content:space-between;align-items:center;gap:1rem;">
          <div style="flex:1;">
            <div class="skeleton-line" style="width:40%;height:10px;"></div>
            <div class="skeleton-line" style="width:60%;height:20px;"></div>
          </div>
          <div class="skeleton-circle"></div>
        </div>
        <div class="skeleton-line" style="width:90%;"></div>
        <div class="skeleton-line" style="width:70%;"></div>
        <div class="skeleton-line" style="width:50%;"></div>
      </div>`).join('')}</div>`;
  }

  function sortItems(items){
    const mode = sortSelect?.value || 'recent';
    const copy = [...items];
    if (mode === 'priority_desc'){
      const weight = (p) => ({high:3, medium:2, low:1})[(p||'').toLowerCase()] || 0;
      copy.sort((a,b) => weight(b.priority) - weight(a.priority));
    } else if (mode === 'code_asc'){
      const ta = (a.incident_code||'').toString().toLowerCase();
      const tb = (b.incident_code||'').toString().toLowerCase();
      copy.sort((a,b)=> ta.localeCompare(tb));
    } else {
      // recent: attempt by updated_at/resolved_at/id desc
      const ts = i => new Date(i.updated_at || i.resolved_at || i.created_at || 0).getTime() || 0;
      copy.sort((a,b)=> ts(b)-ts(a) || (b.id||0)-(a.id||0));
    }
    return copy;
  }

  // Tabs interactions
  function activateTab(which){
    if (which==='feedback'){
      tabFeedback.classList.add('active'); tabFeedback.setAttribute('aria-selected','true');
      tabProof.classList.remove('active'); tabProof.setAttribute('aria-selected','false');
      panelFeedback.hidden = false; panelProof.hidden = true;
    } else {
      tabProof.classList.add('active'); tabProof.setAttribute('aria-selected','true');
      tabFeedback.classList.remove('active'); tabFeedback.setAttribute('aria-selected','false');
      panelProof.hidden = false; panelFeedback.hidden = true;
    }
  }
  tabFeedback?.addEventListener('click', () => activateTab('feedback'));
  tabProof?.addEventListener('click', () => activateTab('proof'));

  // Initial load
  loadIncidents();
})();
