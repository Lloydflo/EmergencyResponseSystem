<?php
require_once __DIR__ . '/includes/auth.php';
// Require full login (including OTP verification) before loading page
require_login('call.php');

$pageTitle = 'Emergency Call Center';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="css/cards.css">
    <link rel="stylesheet" href="css/call.css">
    <script src="js/place-autocomplete.js"></script>
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include 'includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Call Receiving and Logging
       =================================== -->
    <div class="main-content">
        <div class="main-container">
            <div style="height: 1rem;"></div>

            <!-- Stats Bar -->
            <div class="stats-bar">
                    <div class="stat-card active-calls">
                        <div class="stat-content-row">
                            <span class="stat-icon-box active-calls"><i class="fas fa-phone-volume"></i></span>
                            <div>
                                <div class="stat-value" id="statActiveCalls">0</div>
                                <div class="stat-label">Active Calls</div>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card pending">
                        <div class="stat-content-row">
                            <span class="stat-icon-box pending"><i class="fas fa-hourglass-half"></i></span>
                            <div>
                                <div class="stat-value" id="statPending">0</div>
                                <div class="stat-label">Pending Incidents</div>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card resolved">
                        <div class="stat-content-row">
                            <span class="stat-icon-box resolved"><i class="fas fa-check-circle"></i></span>
                            <div>
                                <div class="stat-value" id="statResolved">0</div>
                                <div class="stat-label">Resolved</div>
                            </div>
                        </div>
                    </div>
                    <div class="stat-card total">
                        <div class="stat-content-row">
                            <span class="stat-icon-box total"><i class="fas fa-list-ol"></i></span>
                            <div>
                                <div class="stat-value" id="statTotal">0</div>
                                <div class="stat-label">Total Logged</div>
                            </div>
                        </div>
                    </div>
            </div>

            <!-- Grid: Intake + Recent Incidents -->
            <div class="call-center-grid">
                <div>
                    <!-- Incoming Call Alert -->
                    <div class="incoming-call-alert" id="incomingCallAlert">
                        <div class="call-info">
                            <i class="fas fa-phone call-icon"></i>
                            <div class="caller-details">
                                <h2 id="incomingCallerName">Incoming Call</h2>
                                <p id="incomingCallerPhone"></p>
                            </div>
                        </div>
                        <div class="call-actions">
                            <button class="call-btn accept-btn" onclick="acceptCall()">
                                <i class="fas fa-check"></i> Accept
                            </button>
                            <button class="call-btn reject-btn" onclick="rejectCall()">
                                <i class="fas fa-times"></i> Reject
                            </button>
                        </div>
                    </div>

                    <!-- Active Call Panel -->
                    <div class="active-call-panel" id="activeCallPanel">
                        <div class="call-header">
                            <div class="call-status">
                                <span class="status-indicator"></span>
                                <strong id="activeCallerName">Caller:</strong>
                                <span id="activeCallerPhone" style="color:#6b7280"></span>
                            </div>
                            <div>
                                <span class="call-timer" id="callTimer">00:00</span>
                                <button class="end-call-btn" onclick="endCall()">
                                    <i class="fas fa-phone-slash"></i> End Call
                                </button>
                            </div>
                        </div>

                        <!-- Incident Form -->
                        <form class="incident-form" id="incidentForm" onsubmit="submitIncident(event)">
                            <div class="form-section">
                                <div class="section-title">
                                    <i class="fas fa-user"></i>
                                    Caller Details
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="callerName">Caller Name</label>
                                        <input type="text" id="callerName" name="callerName" placeholder="e.g., John Doe" required>
                                    </div>
                                    <div class="form-group">
                                        <label for="callerPhone">Phone Number</label>
                                        <input type="tel" id="callerPhone" name="callerPhone" placeholder="e.g., +63 917 123 4567" required>
                                    </div>
                                </div>
                            </div>

                            <div class="form-section">
                                <div class="section-title">
                                    <i class="fas fa-notes-medical"></i>
                                    Incident Details
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="incidentType">Incident Type</label>
                                        <select id="incidentType" name="incidentType" required>
                                            <option value="">Select type</option>
                                            <option value="medical">Medical Emergency</option>
                                            <option value="fire">Fire</option>
                                            <option value="police">Police Emergency</option>
                                            <option value="traffic">Traffic Accident</option>
                                            <option value="other">Other</option>
                                        </select>
                                    </div>
                                    <div class="form-group">
                                        <label for="incidentLocation">Location</label>
                                        <input type="text" id="incidentLocation" name="incidentLocation" placeholder="Enter address or coordinates" required>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="incidentDescription">Description</label>
                                    <textarea id="incidentDescription" name="incidentDescription" placeholder="Brief description of the situation" required></textarea>
                                </div>
                                    <!-- Map picker removed as requested -->
                                <div class="form-group">
                                    <label>Priority <span id="prioritySuggestion" style="margin-left:8px;font-size:12px;color:#6b7280;"></span></label>
                                    <div class="priority-select" id="prioritySelect">
                                        <div class="priority-option high" data-value="high">High</div>
                                        <div class="priority-option medium" data-value="medium">Medium</div>
                                        <div class="priority-option low" data-value="low">Low</div>
                                    </div>
                                    <input type="hidden" id="incidentPriority" name="incidentPriority" required>
                                </div>
                            </div>

                            <div class="form-section">
                                <div class="section-title">
                                    <i class="fas fa-clipboard-check"></i>
                                    Actions
                                </div>
                                <div class="form-row">
                                    <div class="form-group">
                                        <label for="callNotes">Call Notes</label>
                                        <input type="text" id="callNotes" name="callNotes" placeholder="Any additional notes">
                                    </div>
                                    <div class="form-group">
                                        <label for="status">Status</label>
                                        <select id="status" name="status" required>
                                            <option value="pending">Pending</option>
                                            <option value="dispatched">Dispatched</option>
                                            <option value="resolved">Resolved</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <button type="submit" class="submit-incident-btn">
                                <i class="fas fa-save"></i> Log Incident
                            </button>
                        </form>
                    </div>

                    <!-- Demo: Simulate Incoming Call -->
                    <div style="margin-top: 12px;">
                        <button class="submit-incident-btn" style="background:#10b981" onclick="simulateIncomingCall()">
                            <i class="fas fa-bolt"></i> Simulate Incoming Call
                        </button>
                    </div>
                </div>

                <!-- Sidebar: Recent Incidents -->
                <aside class="recent-incidents">
                    <div class="sidebar-header">
                        <h3>Recent Incidents</h3>
                    </div>
                    <div class="sidebar-controls" style="display:flex; flex-direction:column; gap:8px; margin:8px 0;">
                        <input type="search" id="incidentSearch" placeholder="Search Type of Emergency, Location..." style="padding:8px; border:1px solid #e5e7eb; border-radius:8px;">
                        <div class="date-controls" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                            <div style="display:flex; flex-direction:column; gap:4px;">
                                <label for="filterDay" style="font-size:12px; color:#6b7280;">By Day</label>
                                <input type="date" id="filterDay" style="padding:6px; border:1px solid #e5e7eb; border-radius:6px;">
                            </div>
                            <button class="filter-tab" type="button" onclick="clearIncidentFilters()" title="Clear filters">Clear</button>
                        </div>
                    </div>
                    <div class="filter-tabs">
                        <button class="filter-tab active" data-filter="all" onclick="setFilter(this)">All</button>
                        
                        <button class="filter-tab" data-filter="high" onclick="setFilter(this)">High</button>
                        <button class="filter-tab" data-filter="medium" onclick="setFilter(this)">Medium</button>
                        <button class="filter-tab" data-filter="low" onclick="setFilter(this)">Low</button>
                    </div>
                    <div class="incident-list" id="incidentList"></div>
                </aside>
            </div>
        </div>
    </div>

    <?php include 'includes/admin-footer.php'; ?>

    <script>
    // Incidents loaded from the database via API
    let incidentItems = [];
    let activeCall = null;
    let callTimerInterval = null;
    let currentFilter = 'all';
    const RECENT_INCIDENTS_ENABLED = true; // enable Recent Incidents data
    const RESET_RECENT_ON_LOAD = false; // localStorage no longer used
    const API_LIST_URL = 'api/incidents_list.php';
    const API_CREATE_CALL_URL = 'api/calls_create.php';
    let priorityAuto = true; // auto-apply suggested priority until user overrides
    let prioritySuggestTimer = null; // debounce timer for suggestion updates
    let currentSearch = '';
    let filterDay = '';
    let filterMonth = '';
    const QC_VIEWBOX = '121.0000,14.7500,121.1000,14.6000';
    const incidentGeocodeCache = {};

    document.addEventListener('DOMContentLoaded', () => {
        initPrioritySelect();
        initIncidentSidebarControls();
        if (RECENT_INCIDENTS_ENABLED) {
            loadIncidentsFromServer();
        } else {
            incidentItems = [];
            renderIncidents();
            updateStats();
        }
        // Hook suggestion on description input
        const descEl = document.getElementById('incidentDescription');
        const typeEl = document.getElementById('incidentType');
        if (descEl) {
            descEl.addEventListener('input', (e) => {
                const val = e.target.value;
                if (prioritySuggestTimer) clearTimeout(prioritySuggestTimer);
                prioritySuggestTimer = setTimeout(() => updatePrioritySuggestion(val), 250);
            });
            // Initialize suggestion only if there is content
            if ((descEl.value || '').trim().length >= 3) {
                updatePrioritySuggestion(descEl.value);
            }
        }
        if (typeEl) {
            typeEl.addEventListener('change', () => {
                const currentDescription = descEl ? descEl.value : '';
                updatePrioritySuggestion(currentDescription);
            });
        }
    });

    function simulateIncomingCall() {
        const names = ['Juan Dela Cruz','Maria Santos','Jose Reyes','Ana Garcia','Roberto Tan'];
        const name = names[Math.floor(Math.random() * names.length)];
        const prefixes = ['917','905','906','915','918','920','921','922','923','925','926','927','928','929','930','938','939','946','947','948','949','995','996','997','998','999'];
        const p = prefixes[Math.floor(Math.random() * prefixes.length)];
        const block1 = String(Math.floor(100 + Math.random()*900)); // 3 digits
        const block2 = String(Math.floor(1000 + Math.random()*9000)); // 4 digits
        const phone = `+63 ${p} ${block1} ${block2}`; // +63 9xx xxx xxxx
        document.getElementById('incomingCallerName').textContent = name;
        document.getElementById('incomingCallerPhone').textContent = phone;
        document.getElementById('incomingCallAlert').classList.add('active');
    }

    function acceptCall() {
        const alert = document.getElementById('incomingCallAlert');
        const name = document.getElementById('incomingCallerName').textContent || 'Unknown';
        const phone = document.getElementById('incomingCallerPhone').textContent || '';
        alert.classList.remove('active');
        activeCall = { name, phone, start: Date.now() };
        document.getElementById('activeCallPanel').classList.add('active');
        document.getElementById('activeCallerName').textContent = 'Caller: ' + name;
        document.getElementById('activeCallerPhone').textContent = phone;
        // Pre-fill form
        document.getElementById('callerName').value = name;
        document.getElementById('callerPhone').value = phone;
        startTimer();
        updateStats();
    }

    function rejectCall() {
        document.getElementById('incomingCallAlert').classList.remove('active');
    }

    function endCall() {
        document.getElementById('activeCallPanel').classList.remove('active');
        stopTimer();
        activeCall = null;
        updateStats();
    }

    function startTimer() {
        stopTimer();
        callTimerInterval = setInterval(() => {
            const elapsed = Math.floor((Date.now() - activeCall.start) / 1000);
            const mm = String(Math.floor(elapsed / 60)).padStart(2,'0');
            const ss = String(elapsed % 60).padStart(2,'0');
            document.getElementById('callTimer').textContent = `${mm}:${ss}`;
        }, 500);
    }

    function stopTimer() {
        if (callTimerInterval) {
            clearInterval(callTimerInterval);
            callTimerInterval = null;
            document.getElementById('callTimer').textContent = '00:00';
        }
    }

    function setPrioritySelection(value) {
        const options = document.querySelectorAll('#prioritySelect .priority-option');
        let applied = false;
        options.forEach(o => {
            if (o.dataset.value === value) {
                o.classList.add('active');
                applied = true;
            } else {
                o.classList.remove('active');
            }
        });
        if (applied) {
            document.getElementById('incidentPriority').value = value;
        }
    }

    function initPrioritySelect() {
        const options = document.querySelectorAll('#prioritySelect .priority-option');
        options.forEach(opt => {
            opt.addEventListener('click', () => {
                priorityAuto = false; // user manually chose a priority
                setPrioritySelection(opt.dataset.value);
            });
        });
    }

    function suggestPriorityFromDescription(desc) {
        const text = ` ${(desc || '').toLowerCase()} `;
        const incidentType = (document.getElementById('incidentType')?.value || '').toLowerCase();

        const high = [
            'unconscious', 'non-responsive', 'not breathing', 'difficulty breathing', 'chest pain', 'severe bleeding',
            'gunshot', 'shot', 'stab', 'stabbing', 'weapon', 'armed', 'fire', 'explosion', 'earthquake', 'flood', 'collapsed',
            'stroke', 'seizure', 'mass casualty', 'cardiac arrest', 'resuscitation', 'burns', 'critical', 'life-threatening',
            'walang malay', 'hindi humihinga', 'nahihirapang huminga', 'matinding pagdurugo', 'barilan', 'binaril', 'saksak',
            'may armas', 'sunog', 'pagsabog', 'lindol', 'baha', 'gumuho', 'kombulsyon', 'maraming nasugatan',
            'tumigil ang puso', 'hinto ang puso', 'delikado', 'malubha', 'grabe', 'seryoso'
        ];

        const medium = [
            'injury', 'fracture', 'sprain', 'minor bleeding', 'assault', 'robbery', 'burglary', 'smoke', 'collision', 'accident',
            'traffic', 'missing', 'distress', 'dizziness', 'fever', 'vomiting', 'pregnant', 'labor', 'child', 'elderly',
            'sugat', 'pilay', 'bukol', 'bahagyang pagdurugo', 'bugbog', 'aksidente', 'banggaan', 'trapiko', 'nawawala',
            'nahilo', 'lagnat', 'pagsusuka', 'buntis', 'manganganak', 'bata', 'matanda'
        ];

        const negative = [
            'minor', 'bahagya', 'walang sugat', 'hindi seryoso', 'okay na', 'stable', 'stable na', 'mild'
        ];

        const intensifiers = [
            'critical', 'life-threatening', 'delikado', 'malubha', 'grabe', 'seryoso', 'urgent', 'agarang', 'immediate'
        ];

        const manyPattern = /(\d+|multiple|many|several|marami|ilan)\s+(nasugatan|injured|pasiente|patients|tao|people|biktima|victims|sasakyan|vehicles|kotse|cars)/;
        const unconsciousPattern = /(walang malay|unconscious|not breathing|hindi humihinga)/;
        const majorFirePattern = /(sunog|fire)\s+(sa|in|with|na)?\s*(bahay|building|mall|school|hospital|warehouse)?/;

        const countHits = (keywords) => keywords.reduce((total, keyword) => total + (text.includes(keyword) ? 1 : 0), 0);
        const highHits = countHits(high);
        const mediumHits = countHits(medium);
        const negativeHits = countHits(negative);
        const intensifierHits = countHits(intensifiers);

        let score = (highHits * 3) + (mediumHits * 1.5) + (intensifierHits * 1.5) - (negativeHits * 2);

        if (manyPattern.test(text)) score += 2;
        if (unconsciousPattern.test(text)) score += 3;
        if (majorFirePattern.test(text)) score += 2;

        if (incidentType === 'fire' && (highHits > 0 || mediumHits > 0)) score += 1;
        if (incidentType === 'medical' && unconsciousPattern.test(text)) score += 2;
        if (incidentType === 'police' && /(armed|may armas|weapon|barilan|binaril)/.test(text)) score += 2;
        if (incidentType === 'traffic' && /(multi-vehicle|maramihang sasakyan|multiple|many)/.test(text)) score += 2;

        if (highHits >= 2 || score >= 6) return 'high';
        if (mediumHits >= 1 || score >= 2) return 'medium';
        return 'low';
    }

    function updatePrioritySuggestion(desc) {
        const text = (desc || '').trim();
        const badge = document.getElementById('prioritySuggestion');
        // Show suggestion only after user types some description
        if (!text || text.length < 3) {
            if (badge) badge.textContent = '';
            return;
        }
        const suggested = suggestPriorityFromDescription(text);
        if (badge) {
            const label = suggested.charAt(0).toUpperCase() + suggested.slice(1);
            badge.textContent = `(Suggested: ${label})`;
        }
        if (priorityAuto) {
            setPrioritySelection(suggested);
        }
    }

    function initIncidentSidebarControls() {
        const searchEl = document.getElementById('incidentSearch');
        const dayEl = document.getElementById('filterDay');
        const monthEl = document.getElementById('filterMonth');
        if (searchEl) {
            searchEl.addEventListener('input', (e) => {
                currentSearch = (e.target.value || '').toLowerCase().trim();
                renderIncidents();
            });
        }
        if (dayEl) {
            dayEl.addEventListener('change', (e) => {
                filterDay = e.target.value || '';
                if (filterDay) {
                    // Clear month when day is set
                    filterMonth = '';
                    if (monthEl) monthEl.value = '';
                }
                loadIncidentsFromServer();
            });
        }
        if (monthEl) {
            monthEl.addEventListener('change', (e) => {
                filterMonth = e.target.value || '';
                if (filterMonth) {
                    // Clear day when month is set
                    filterDay = '';
                    if (dayEl) dayEl.value = '';
                }
                loadIncidentsFromServer();
            });
        }
    }

    function clearIncidentFilters() {
        const searchEl = document.getElementById('incidentSearch');
        const dayEl = document.getElementById('filterDay');
        const monthEl = document.getElementById('filterMonth');
        currentSearch = '';
        filterDay = '';
        filterMonth = '';
        if (searchEl) searchEl.value = '';
        if (dayEl) dayEl.value = '';
        if (monthEl) monthEl.value = '';
        loadIncidentsFromServer();
    }

    function parseCoordinateText(value) {
        const text = String(value || '').trim();
        const match = text.match(/^\s*(?:lat(?:itude)?\s*[:=]\s*)?(-?\d+(?:\.\d+)?)\s*[, ]\s*(?:lon(?:gitude)?\s*[:=]\s*)?(-?\d+(?:\.\d+)?)\s*$/i);
        if (!match) return null;
        const lat = Number(match[1]);
        const lng = Number(match[2]);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null;
        if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
        return { lat, lng };
    }

    function getIncidentCoordsFromInput() {
        const input = document.getElementById('incidentLocation');
        if (!input) return null;
        const lat = Number(input.dataset.lat);
        const lng = Number(input.dataset.lon);
        if (Number.isFinite(lat) && Number.isFinite(lng)) {
            return { lat, lng };
        }
        return parseCoordinateText(input.value);
    }

    function hasLocationContext(text) {
        return /(quezon city|qc|metro manila|philippines)\b/i.test(String(text || ''));
    }

    async function geocodeOnce(query, strictViewbox) {
        const params = new URLSearchParams({
            format: 'jsonv2',
            countrycodes: 'PH',
            limit: '6',
            addressdetails: '1',
            q: query
        });
        params.set('viewbox', QC_VIEWBOX);
        if (strictViewbox) {
            params.set('bounded', '1');
        }
        const url = `https://nominatim.openstreetmap.org/search?${params.toString()}`;
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!res.ok) return [];
        const data = await res.json();
        return Array.isArray(data) ? data : [];
    }

    function selectBestGeocodeCandidate(items, originalQuery) {
        if (!Array.isArray(items) || items.length === 0) return null;
        const q = String(originalQuery || '').toLowerCase();
        const scored = items.map((item) => {
            const label = String(item.display_name || '').toLowerCase();
            let score = Number(item.importance || 0);
            if (label.includes('quezon city')) score += 2;
            if (q && label.includes(q)) score += 1.5;
            return { item, score };
        });
        scored.sort((a, b) => b.score - a.score);
        return scored[0].item || null;
    }

    async function geocodeIncidentLocation(locationText) {
        const raw = String(locationText || '').trim();
        if (!raw) return null;

        const direct = parseCoordinateText(raw);
        if (direct) return direct;

        const cacheKey = raw.toLowerCase();
        if (Object.prototype.hasOwnProperty.call(incidentGeocodeCache, cacheKey)) {
            return incidentGeocodeCache[cacheKey];
        }

        const normalizedQuery = hasLocationContext(raw)
            ? raw
            : `${raw}, Quezon City, Metro Manila, Philippines`;

        try {
            let candidates = await geocodeOnce(normalizedQuery, true);
            if (!candidates.length) {
                candidates = await geocodeOnce(normalizedQuery, false);
            }
            if (!candidates.length && normalizedQuery !== raw) {
                candidates = await geocodeOnce(raw, false);
            }
            const best = selectBestGeocodeCandidate(candidates, raw);
            const lat = Number(best?.lat);
            const lng = Number(best?.lon);
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                incidentGeocodeCache[cacheKey] = null;
                return null;
            }
            const result = { lat, lng };
            const input = document.getElementById('incidentLocation');
            if (input) {
                input.dataset.lat = String(lat);
                input.dataset.lon = String(lng);
                if (best && best.display_name) {
                    input.value = String(best.display_name);
                }
            }
            incidentGeocodeCache[cacheKey] = result;
            return result;
        } catch (e) {
            incidentGeocodeCache[cacheKey] = null;
            return null;
        }
    }

    async function submitIncident(e) {
        e.preventDefault();
        const locationText = document.getElementById('incidentLocation').value.trim();
        if (!locationText) {
            alert('Please provide an incident location.');
            return;
        }
        let coords = getIncidentCoordsFromInput();
        if (!coords) {
            coords = await geocodeIncidentLocation(locationText);
        }
        if (!coords) {
            alert('Unable to verify this location. Please choose from suggestions or enter valid coordinates (lat, lon).');
            document.getElementById('incidentLocation').focus();
            return;
        }
        const finalLocationText = document.getElementById('incidentLocation').value.trim() || locationText;
        const payload = {
            caller_name: document.getElementById('callerName').value.trim(),
            caller_phone: document.getElementById('callerPhone').value.trim(),
            type: document.getElementById('incidentType').value,
            location: finalLocationText,
            description: document.getElementById('incidentDescription').value.trim(),
            priority: document.getElementById('incidentPriority').value,
            status: document.getElementById('status').value
        };
        if (coords) {
            payload.latitude = coords.lat;
            payload.longitude = coords.lng;
        }

        if (!payload.priority) {
            alert('Please select a priority.');
            return;
        }
        try {
            const res = await fetch(API_CREATE_CALL_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (!data.ok) {
                if (data.error && data.error === 'Duplicate incident detected') {
                    alert('Duplicate incident detected!\nA similar incident was already reported recently.');
                } else {
                    alert('Failed to log incident.');
                }
                return;
            }
            showToast('Incident logged successfully');
            e.target.reset();
            document.querySelectorAll('#prioritySelect .priority-option').forEach(o => o.classList.remove('active'));
            document.getElementById('incidentPriority').value = '';
            const locationInput = document.getElementById('incidentLocation');
            if (locationInput) {
                delete locationInput.dataset.lat;
                delete locationInput.dataset.lon;
            }
            priorityAuto = true;
            const badge = document.getElementById('prioritySuggestion');
            if (badge) badge.textContent = '';
            await loadIncidentsFromServer();
            // Log activity event for dashboard Recent Activity
            try {
                const details = `Type: ${payload.type} | Location: ${payload.location} | Priority: ${payload.priority}`;
                await fetch('api/activity_event.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        action: 'call_logged',
                        entity_type: 'call',
                        details: details
                    })
                });
            } catch (e) {
                console.warn('Activity log failed', e);
            }
        } catch (err) {
            console.warn('Submit failed:', err);
            alert('Error while logging incident.');
        }
    }

    function renderIncidents() {
        const container = document.getElementById('incidentList');
        if (!container) return;
        const items = RECENT_INCIDENTS_ENABLED ? applyIncidentFilters(incidentItems) : [];
        if (!items.length) {
            container.innerHTML = `<div class="incident-card empty"><div class="incident-header"><div class="incident-id">No incidents</div></div><div class="incident-type">Use the form to log an incident</div></div>`;
            return;
        }
        container.innerHTML = items.map(i => incidentCardHtml(i)).join('');
    }

    function applyIncidentFilters(items) {
        return items.filter((i) => {
            if (currentFilter !== 'all' && i.priority !== currentFilter) return false;
            if (filterDay || filterMonth) {
                const d = new Date(i.created_at || i.createdAt || i.timestamp || Date.now());
                if (isNaN(d)) return false;
                const yyyy = d.getFullYear();
                const mm = String(d.getMonth() + 1).padStart(2, '0');
                const dd = String(d.getDate()).padStart(2, '0');
                const dayStr = `${yyyy}-${mm}-${dd}`;
                const monthStr = `${yyyy}-${mm}`;
                if (filterDay && dayStr !== filterDay) return false;
                if (!filterDay && filterMonth && monthStr !== filterMonth) return false;
            }
            if (currentSearch) {
                const hay = [i.incident_code, i.type, i.location, i.description, i.status]
                    .map(v => (v || '').toString().toLowerCase())
                    .join(' ');
                if (!hay.includes(currentSearch)) return false;
            }
            return true;
        });
    }

    function incidentCardHtml(i) {
        const priorityClass = i.priority || 'low';
        const created = new Date(i.created_at || Date.now());
        const code = i.incident_code || '';
        return `
            <div class="incident-card" onclick="openIncident('${code}')">
                <div class="incident-header">
                    <div class="incident-id">${code}</div>
                    <div class="incident-priority ${priorityClass}">${(priorityClass||'low').toUpperCase()}</div>
                </div>
                <div class="incident-type">${labelForType(i.type)}</div>
                <div class="incident-location"><i class="fas fa-map-marker-alt"></i> ${i.location}</div>
                <div class="incident-time">${created.toLocaleString()}</div>
            </div>
        `;
    }

    function labelForType(t) {
        switch (t) {
            case 'medical': return 'Medical Emergency';
            case 'fire': return 'Fire';
            case 'police': return 'Police Emergency';
            case 'traffic': return 'Traffic Accident';
            default: return 'Other';
        }
    }

    function setFilter(btn) {
        document.querySelectorAll('.filter-tab').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        currentFilter = btn.dataset.filter;
        renderIncidents();
    }

    function openIncident(code) {
        const item = incidentItems.find(x => x.incident_code === code);
        if (!item) return;
        const lines = [];
        lines.push(`Incident: ${item.incident_code || 'N/A'}`);
        lines.push(`Type: ${labelForType(item.type)}`);
        lines.push(`Location: ${item.location || 'N/A'}`);
        if (Number.isFinite(Number(item.latitude)) && Number.isFinite(Number(item.longitude))) {
            lines.push(`Coordinates: ${Number(item.latitude).toFixed(6)}, ${Number(item.longitude).toFixed(6)}`);
        }
        lines.push(`Priority: ${(item.priority || 'N/A').toUpperCase()}`);
        lines.push(`Status: ${item.status || 'N/A'}`);
        if (item.caller_name) lines.push(`Caller: ${item.caller_name}`);
        if (item.caller_phone) lines.push(`Phone: ${item.caller_phone}`);
        if (item.assigned_unit) {
            const unitType = item.assigned_unit_type ? ` (${item.assigned_unit_type})` : '';
            lines.push(`Assigned Unit: ${item.assigned_unit}${unitType}`);
        }
        if (item.response_time_min !== null && item.response_time_min !== undefined) {
            lines.push(`Response Time: ${item.response_time_min} min`);
        }
        if (item.description) lines.push(`Description: ${item.description}`);
        alert(lines.join('\n'));
    }

    function exportIncidents() {
        const blob = new Blob([JSON.stringify(incidentItems, null, 2)], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'incidents.json';
        a.click();
        URL.revokeObjectURL(url);
    }

    function updateStats() {
        const activeCalls = activeCall ? 1 : 0;
        const pending = incidentItems.filter(i => i.status === 'pending' || i.status === 'dispatched').length; // include dispatched as active
        const resolved = incidentItems.filter(i => i.status === 'resolved').length;
        document.getElementById('statActiveCalls').textContent = activeCalls;
        document.getElementById('statPending').textContent = pending;
        document.getElementById('statResolved').textContent = resolved;
        document.getElementById('statTotal').textContent = incidentItems.length;
    }

    function saveIncidentsToLocalStorage() {
        // No-op: using server-side storage
    }

    async function loadIncidentsFromServer() {
        try {
            const params = new URLSearchParams();
            if (filterDay) params.set('day', filterDay);
            if (!filterDay && filterMonth) params.set('month', filterMonth);
            const res = await fetch(`${API_LIST_URL}?${params.toString()}`);
            const data = await res.json();
            if (data.ok) {
                incidentItems = data.items || [];
            } else {
                incidentItems = [];
            }
        } catch (e) {
            console.warn('Failed to load incidents from server:', e);
            incidentItems = [];
        }
        renderIncidents();
        updateStats();
    }

    function showToast(msg) {
        // Simple ephemeral toast using alert for now
        // Hook this to your notification modal if desired
        console.log(msg);
    }
    </script>
        
</body>
</html>
