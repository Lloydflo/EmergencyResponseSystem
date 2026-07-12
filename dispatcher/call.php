<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
// Require full login (including OTP verification) before loading page
require_role('dispatcher', 'dispatcher/call.php');

$pageTitle = 'Emergency Call Center';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <?php include $rootDir . '/includes/theme-init.php'; ?>
    <base href="../">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="css/cards.css">
    <link rel="stylesheet" href="css/call.css?v=<?php echo filemtime($rootDir . '/css/call.css'); ?>">
    <script src="js/place-autocomplete.js"></script>
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include $rootDir . '/includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Call Receiving and Logging
       =================================== -->
    <div class="main-content">
        <div class="main-container">
            <section class="call-hero">
                <div class="call-hero-main">
                    <div class="call-kicker">Emergency Communications Console</div>
                    <h1 class="call-hero-title">Emergency Call Center</h1>
                    <p class="call-hero-text">
                        Receive incoming calls, document caller details, assess urgency, and push incidents into the response pipeline without changing the existing call intake process.
                    </p>

                    <div class="call-hero-chips">
                        <span class="call-chip call-chip-live"><span class="call-chip-dot"></span> Call Queue Live</span>
                        <span class="call-chip">Priority Suggestion Active</span>
                        <span class="call-chip">Incident Logging Ready</span>
                    </div>
                </div>

                <div class="call-hero-side">
                    <div class="call-focus-card">
                        <div class="call-focus-label">Operator Focus</div>
                        <div class="call-focus-value">Receive, assess, and log incidents fast.</div>
                        <div class="call-focus-note">
                            Keep caller details complete, confirm location, choose urgency, and let the rest of the dispatcher flow continue as usual.
                        </div>
                    </div>
                </div>
            </section>

            <section class="stats-bar">
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
            </section>

            <div class="call-center-grid">
                <section class="call-intake-column">
                    <div class="incoming-call-modal" id="incomingCallModal" aria-hidden="true">
                        <div class="incoming-call-alert" id="incomingCallAlert" role="dialog" aria-modal="true" aria-labelledby="incomingCallerName">
                            <div class="call-info">
                                <i class="fas fa-phone call-icon"></i>
                                <div class="caller-details">
                                    <div class="panel-eyebrow call-alert-eyebrow">Incoming Call</div>
                                    <h2 id="incomingCallerName">Incoming Call</h2>
                                    <p id="incomingCallerPhone"></p>
                                </div>
                            </div>
                            <div class="call-actions">
                                <button class="call-btn accept-btn" id="acceptIncomingCallBtn" onclick="acceptCall()">
                                    <i class="fas fa-check"></i> Accept
                                </button>
                                <button class="call-btn reject-btn" onclick="rejectCall()">
                                    <i class="fas fa-times"></i> Decline
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="active-call-panel" id="activeCallPanel">
                        <div class="call-header">
                            <div class="call-status-wrap">
                                <div class="panel-eyebrow">Active Call Session</div>
                                <div class="call-status">
                                    <span class="status-indicator"></span>
                                    <strong id="activeCallerName">Caller:</strong>
                                    <span id="activeCallerPhone" class="call-secondary"></span>
                                </div>
                            </div>
                            <div class="call-session-tools">
                                <span class="call-timer" id="callTimer">00:00</span>
                                <button class="end-call-btn" onclick="endCall()">
                                    <i class="fas fa-phone-slash"></i> End Call
                                </button>
                            </div>
                        </div>

                        <div class="voice-call-console" id="voiceCallConsole">
                            <div class="voice-call-main">
                                <div class="voice-meter" id="voiceMeter" aria-hidden="true">
                                    <span></span><span></span><span></span><span></span>
                                </div>
                                <div>
                                    <div class="voice-title">Voice Call Tools</div>
                                    <div class="voice-state" id="voiceCallState">Connected. Ready for caller audio and dictation.</div>
                                </div>
                            </div>
                            <div class="voice-actions">
                                <button type="button" class="voice-btn" id="speechToTextBtn" onclick="toggleSpeechToText()">
                                    <i class="fas fa-microphone"></i> Speak to Text
                                </button>
                                <button type="button" class="voice-btn" id="stopVoiceBtn" onclick="stopVoiceTools()">
                                    <i class="fas fa-stop"></i> Stop
                                </button>
                            </div>
                            <div class="transcript-panel">
                                <div class="transcript-label">Live Transcript</div>
                                <div class="transcript-output" id="speechTranscript">No transcript yet.</div>
                            </div>
                        </div>

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
                                        <label>Vehicle Type</label>
                                        <div class="incident-type-dropdown" id="incidentTypeDropdown">
                                            <button type="button" class="incident-type-trigger" id="incidentTypeTrigger" aria-expanded="false" aria-controls="incidentTypeMenu">
                                                <span id="incidentTypeTriggerText">Select type</span>
                                                <i class="fas fa-chevron-down"></i>
                                            </button>
                                            <div class="incident-type-menu" id="incidentTypeMenu" role="group" aria-label="Incident Type">
                                                <label class="incident-type-option">
                                                    <input type="checkbox" name="incidentTypes" value="ambulance">
                                                    <span><i class="fas fa-ambulance"></i> Ambulance</span>
                                                </label>
                                                <label class="incident-type-option">
                                                    <input type="checkbox" name="incidentTypes" value="fire">
                                                    <span><i class="fas fa-fire-extinguisher"></i> Fire Truck</span>
                                                </label>
                                                <label class="incident-type-option">
                                                    <input type="checkbox" name="incidentTypes" value="police">
                                                    <span><i class="fas fa-shield-alt"></i> Police Emergency</span>
                                                </label>
                                            </div>
                                        </div>
                                        <input type="hidden" id="incidentType" name="incidentType">
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
                                <div class="form-group">
                                    <label>Priority <span id="prioritySuggestion" class="priority-suggestion"></span></label>
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
                                    Logging Actions
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

                            <div class="intake-actions">
                                <button type="submit" class="submit-incident-btn">
                                    <i class="fas fa-save"></i> Log Incident
                                </button>
                            </div>
                        </form>
                    </div>
                </section>

                <aside class="recent-incidents">
                    <div class="sidebar-header">
                        <div>
                            <div class="panel-eyebrow">Incident Queue</div>
                            <h3>Recent Incidents</h3>
                        </div>
                    </div>

                    <div class="sidebar-controls">
                        <input type="search" id="incidentSearch" placeholder="Search Type of Emergency, Location...">
                        <div class="date-controls">
                            <div class="date-group">
                                <label for="filterDay">By Day</label>
                                <input type="date" id="filterDay">
                            </div>
                            <div class="date-group">
                                <label for="filterMonth">By Month</label>
                                <input type="month" id="filterMonth">
                            </div>
                            <button class="filter-tab filter-clear-btn" type="button" onclick="clearIncidentFilters()" title="Clear filters">Clear</button>
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

    <?php include $rootDir . '/includes/admin-footer.php'; ?>

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
    const incidentGeocodeCache = {};
    let speechRecognition = null;
    let speechListening = false;
    let finalTranscriptText = '';
    let pendingIncomingCall = null;
    const SpeechRecognitionApi = window.SpeechRecognition || window.webkitSpeechRecognition || null;

    function getSharedCallSessionApi() {
        return window.ersCallSession && typeof window.ersCallSession.getState === 'function'
            ? window.ersCallSession
            : null;
    }

    function getSharedCallSession() {
        const api = getSharedCallSessionApi();
        return api ? api.getState() : null;
    }

    function renderActiveCallPanel(session) {
        const panel = document.getElementById('activeCallPanel');
        if (!panel) return;

        if (!session || session.active !== true) {
            panel.classList.remove('active');
            stopTimer();
            stopVoiceTools();
            activeCall = null;
            updateStats();
            return;
        }

        const start = Number(session.start);
        activeCall = {
            name: session.name || 'Unknown',
            phone: session.phone || '',
            start: Number.isFinite(start) && start > 0 ? start : Date.now()
        };

        panel.classList.add('active');
        document.getElementById('activeCallerName').textContent = 'Caller: ' + activeCall.name;
        document.getElementById('activeCallerPhone').textContent = activeCall.phone;
        document.getElementById('callerName').value = activeCall.name;
        document.getElementById('callerPhone').value = activeCall.phone;
        setVoiceState('Connected. Ready for caller audio and dictation.');
        startTimer();
        updateStats();
    }

    function redirectToDispatchCenter(data) {
        const params = new URLSearchParams();
        if (data && data.incident_id) {
            params.set('incident_id', String(data.incident_id));
        }
        if (data && (data.incident_reference_no || data.reference_no)) {
            params.set('code', String(data.incident_reference_no || data.reference_no));
        }
        params.set('from_call', '1');
        window.location.href = 'dispatcher/dispatch.php?' + params.toString();
    }

    document.addEventListener('DOMContentLoaded', () => {
        initPrioritySelect();
        initIncidentTypeChecklist();
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
        renderActiveCallPanel(getSharedCallSession());
        document.addEventListener('ers:call-session-change', (event) => {
            renderActiveCallPanel(event.detail ? event.detail.session : getSharedCallSession());
        });
        document.addEventListener('ers:incoming-call', (event) => {
            showIncomingCallModal(event.detail || {});
        });
    });

    function showIncomingCallModal(call) {
        const modal = document.getElementById('incomingCallModal');
        const alert = document.getElementById('incomingCallAlert');
        if (!modal || !alert) return;

        const nameValue = call && (call.name || call.caller_name || call.callerName);
        const phoneValue = call && (call.phone || call.caller_phone || call.callerPhone);
        const startValue = call && (call.start || call.received_at || call.created_at);
        const parsedStart = startValue ? Date.parse(startValue) : NaN;
        const name = String(nameValue || 'Incoming Call').trim();
        const phone = String(phoneValue || '').trim();
        pendingIncomingCall = {
            name: name || 'Incoming Call',
            phone: phone,
            start: Number(call && call.start) > 0 ? Number(call.start) : (Number.isFinite(parsedStart) ? parsedStart : Date.now())
        };

        document.getElementById('incomingCallerName').textContent = pendingIncomingCall.name;
        document.getElementById('incomingCallerPhone').textContent = pendingIncomingCall.phone || 'Unknown number';
        modal.classList.add('active');
        alert.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('incoming-call-modal-open');
        document.getElementById('acceptIncomingCallBtn')?.focus();
    }

    window.showIncomingCallModal = showIncomingCallModal;

    function hideIncomingCallModal() {
        const modal = document.getElementById('incomingCallModal');
        const alert = document.getElementById('incomingCallAlert');
        if (modal) {
            modal.classList.remove('active');
            modal.setAttribute('aria-hidden', 'true');
        }
        if (alert) {
            alert.classList.remove('active');
        }
        document.body.classList.remove('incoming-call-modal-open');
    }

    function acceptCall() {
        const incomingCall = pendingIncomingCall || {};
        const name = incomingCall.name || document.getElementById('incomingCallerName').textContent || 'Unknown';
        const phone = incomingCall.phone || '';
        const start = incomingCall.start || Date.now();
        hideIncomingCallModal();
        pendingIncomingCall = null;
        const sessionApi = getSharedCallSessionApi();
        if (sessionApi) {
            sessionApi.start({
                name: name,
                phone: phone,
                start: start,
                muted: false,
                speaker: false
            });
        } else {
            activeCall = { name, phone, start };
        }
        renderActiveCallPanel(getSharedCallSession() || activeCall);
    }

    function rejectCall() {
        pendingIncomingCall = null;
        hideIncomingCallModal();
    }

    function endCall() {
        stopVoiceTools();
        const sessionApi = getSharedCallSessionApi();
        if (sessionApi) {
            sessionApi.end();
        }
        renderActiveCallPanel(null);
    }

    function startTimer() {
        stopTimer();
        callTimerInterval = setInterval(() => {
            if (!activeCall) return;
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

    function setVoiceState(text) {
        const el = document.getElementById('voiceCallState');
        if (el) el.textContent = text;
    }

    function setTranscript(text) {
        const el = document.getElementById('speechTranscript');
        if (el) el.textContent = text || 'No transcript yet.';
    }

    function getSpeechRecognition() {
        if (!SpeechRecognitionApi) return null;
        if (speechRecognition) return speechRecognition;
        speechRecognition = new SpeechRecognitionApi();
        speechRecognition.lang = 'en-PH';
        speechRecognition.continuous = true;
        speechRecognition.interimResults = true;
        speechRecognition.onresult = (event) => {
            let interim = '';
            for (let i = event.resultIndex; i < event.results.length; i += 1) {
                const transcript = event.results[i][0]?.transcript || '';
                if (event.results[i].isFinal) {
                    finalTranscriptText = `${finalTranscriptText} ${transcript}`.trim();
                } else {
                    interim = `${interim} ${transcript}`.trim();
                }
            }
            const combined = [finalTranscriptText, interim].filter(Boolean).join(' ');
            setTranscript(combined);
            applyTranscriptToForm(finalTranscriptText || combined);
        };
        speechRecognition.onerror = (event) => {
            speechListening = false;
            updateSpeechButton();
            if (event.error === 'not-allowed' || event.error === 'service-not-allowed') {
                setVoiceState('Microphone permission was blocked.');
            } else {
                setVoiceState('Speech-to-text stopped.');
            }
            document.getElementById('voiceMeter')?.classList.remove('active');
        };
        speechRecognition.onend = () => {
            speechListening = false;
            updateSpeechButton();
            document.getElementById('voiceMeter')?.classList.remove('active');
        };
        return speechRecognition;
    }

    function inferIncidentTypesFromText(text) {
        const value = String(text || '').toLowerCase();
        const types = [];
        if (/(medical|ambulance|injur|cardiac|stroke|unconscious|not breathing|pregnan|health|nahihirapang huminga|walang malay|sugat|lagnat|buntis)/.test(value)) {
            types.push('ambulance');
        }
        if (/(fire|smoke|blaze|burn|explosion|sunog|usok|pagsabog)/.test(value)) {
            types.push('fire');
        }
        if (/(police|crime|robbery|assault|theft|armed|weapon|shoot|barilan|binaril|saksak|may armas)/.test(value)) {
            types.push('police');
        }
        if (/(traffic|accident|collision|crash|vehicle|banggaan|aksidente|trapiko)/.test(value)) {
            types.push('traffic');
        }
        return Array.from(new Set(types));
    }

    function applyTranscriptToForm(text) {
        const clean = String(text || '').trim();
        if (!clean) return;
        const inferredTypes = inferIncidentTypesFromText(clean);
        if (inferredTypes.length) {
            document.querySelectorAll('input[name="incidentTypes"]').forEach((input) => {
                input.checked = inferredTypes.includes(input.value);
            });
            syncIncidentTypeHiddenInput();
        }
        const desc = document.getElementById('incidentDescription');
        if (desc) {
            desc.value = clean;
            desc.dispatchEvent(new Event('input', { bubbles: true }));
        }
        const notes = document.getElementById('callNotes');
        if (notes) {
            notes.value = `Speech transcript: ${clean}`;
        }
    }

    function updateSpeechButton() {
        const btn = document.getElementById('speechToTextBtn');
        if (!btn) return;
        btn.classList.toggle('active', speechListening);
        btn.innerHTML = speechListening
            ? '<i class="fas fa-microphone-slash"></i> Stop Dictation'
            : '<i class="fas fa-microphone"></i> Speak to Text';
    }

    function toggleSpeechToText() {
        if (!activeCall) {
            alert('Accept a call first.');
            return;
        }
        const recognizer = getSpeechRecognition();
        if (!recognizer) {
            setVoiceState('Speech-to-text is not supported in this browser.');
            return;
        }
        if (speechListening) {
            recognizer.stop();
            speechListening = false;
            updateSpeechButton();
            setVoiceState('Speech-to-text stopped.');
            return;
        }
        finalTranscriptText = '';
        setTranscript('');
        try {
            recognizer.start();
            speechListening = true;
            updateSpeechButton();
            setVoiceState('Listening... Speak clearly into the microphone.');
            document.getElementById('voiceMeter')?.classList.add('active');
        } catch (e) {
            setVoiceState('Speech-to-text could not start.');
        }
    }

    function stopVoiceTools() {
        if (speechRecognition && speechListening) {
            try { speechRecognition.stop(); } catch (e) {}
        }
        speechListening = false;
        updateSpeechButton();
        document.getElementById('voiceMeter')?.classList.remove('active');
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

    function getSelectedIncidentTypes() {
        return Array.from(document.querySelectorAll('input[name="incidentTypes"]:checked'))
            .map((input) => String(input.value || '').trim())
            .filter(Boolean);
    }

    function incidentTypeLabel(value) {
        const labels = {
            medical: 'Ambulance',
            ambulance: 'Ambulance',
            fire: 'Fire Truck',
            police: 'Police Emergency',
            traffic: 'Traffic Accident'
        };
        return labels[value] || value;
    }

    function syncIncidentTypeHiddenInput() {
        const selectedTypes = getSelectedIncidentTypes();
        const hidden = document.getElementById('incidentType');
        if (hidden) {
            hidden.value = selectedTypes.join(', ');
        }
        const triggerText = document.getElementById('incidentTypeTriggerText');
        if (triggerText) {
            if (!selectedTypes.length) {
                triggerText.textContent = 'Select type';
            } else if (selectedTypes.length <= 2) {
                triggerText.textContent = selectedTypes.map(incidentTypeLabel).join(', ');
            } else {
                triggerText.textContent = `${selectedTypes.length} types selected`;
            }
        }
    }

    function setIncidentTypeDropdownOpen(open) {
        const dropdown = document.getElementById('incidentTypeDropdown');
        const trigger = document.getElementById('incidentTypeTrigger');
        if (!dropdown || !trigger) return;
        dropdown.classList.toggle('open', open);
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    function initIncidentTypeChecklist() {
        const dropdown = document.getElementById('incidentTypeDropdown');
        const trigger = document.getElementById('incidentTypeTrigger');
        if (trigger) {
            trigger.addEventListener('click', () => {
                setIncidentTypeDropdownOpen(!(dropdown && dropdown.classList.contains('open')));
            });
        }
        document.querySelectorAll('input[name="incidentTypes"]').forEach((input) => {
            input.addEventListener('change', () => {
                syncIncidentTypeHiddenInput();
                const descEl = document.getElementById('incidentDescription');
                updatePrioritySuggestion(descEl ? descEl.value : '');
            });
        });
        document.addEventListener('click', (event) => {
            if (!dropdown || dropdown.contains(event.target)) return;
            setIncidentTypeDropdownOpen(false);
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                setIncidentTypeDropdownOpen(false);
            }
        });
        syncIncidentTypeHiddenInput();
    }

    function resetIncidentTypeChecklist() {
        document.querySelectorAll('input[name="incidentTypes"]').forEach((input) => {
            input.checked = false;
        });
        syncIncidentTypeHiddenInput();
        setIncidentTypeDropdownOpen(false);
    }

    function suggestPriorityFromDescription(desc) {
        const text = ` ${(desc || '').toLowerCase()} `;
        const incidentTypes = getSelectedIncidentTypes();

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

        if (incidentTypes.includes('fire') && (highHits > 0 || mediumHits > 0)) score += 1;
        if ((incidentTypes.includes('medical') || incidentTypes.includes('ambulance')) && unconsciousPattern.test(text)) score += 2;
        if (incidentTypes.includes('police') && /(armed|may armas|weapon|barilan|binaril)/.test(text)) score += 2;
        if (incidentTypes.includes('traffic') && /(multi-vehicle|maramihang sasakyan|multiple|many)/.test(text)) score += 2;

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
            q: query,
            limit: '6',
            strict: strictViewbox ? '1' : '0'
        });
        const url = `api/geocode_proxy.php?${params.toString()}`;
        const res = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!res.ok) return [];
        const payload = await res.json();
        if (!payload || !payload.ok || !Array.isArray(payload.items)) {
            return [];
        }
        return payload.items;
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
            // Allow logging even when autocomplete/geocoding is unavailable.
            console.warn('Proceeding without verified coordinates for location:', locationText);
        }
        const finalLocationText = document.getElementById('incidentLocation').value.trim() || locationText;
        const selectedTypes = getSelectedIncidentTypes();
        const payload = {
            caller_name: document.getElementById('callerName').value.trim(),
            caller_phone: document.getElementById('callerPhone').value.trim(),
            type: selectedTypes,
            location: finalLocationText,
            description: document.getElementById('incidentDescription').value.trim(),
            priority: document.getElementById('incidentPriority').value,
            status: document.getElementById('status').value
        };
        if (coords) {
            payload.latitude = coords.lat;
            payload.longitude = coords.lng;
        }

        if (!selectedTypes.length) {
            alert('Please select at least one incident type.');
            return;
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
            let data = null;
            try {
                data = await res.json();
            } catch (parseErr) {
                data = null;
            }
            if (!res.ok || !data || !data.ok) {
                const errorText = data && data.error ? String(data.error) : '';
                if (errorText === 'Duplicate incident detected') {
                    alert('Duplicate incident detected!\nA similar incident was already reported recently.');
                } else {
                    alert(errorText ? `Failed to log incident: ${errorText}` : 'Failed to log incident.');
                }
                return;
            }
            const sessionApi = getSharedCallSessionApi();
            if (sessionApi && activeCall) {
                sessionApi.update({
                    incidentId: data.incident_id || null,
                    incidentReferenceNo: data.incident_reference_no || data.reference_no || '',
                    incidentStatus: data.incident_status || payload.status,
                    incidentType: selectedTypes.join(', '),
                    location: payload.location
                });
            }
            showToast('Incident logged successfully. Redirecting to Dispatch Center...');
            e.target.reset();
            resetIncidentTypeChecklist();
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
                const details = `Type: ${selectedTypes.join(', ')} | Location: ${payload.location} | Priority: ${payload.priority}`;
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
            redirectToDispatchCenter(data);
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
        const labels = {
            medical: 'Ambulance',
            ambulance: 'Ambulance',
            fire: 'Fire Truck',
            police: 'Police Emergency',
            traffic: 'Traffic Accident'
        };
        const values = String(t || '')
            .split(',')
            .map((part) => part.trim().toLowerCase())
            .filter(Boolean);
        if (!values.length) return 'Unspecified';
        return values.map((value) => labels[value] || value.replace(/\b\w/g, (c) => c.toUpperCase())).join(', ');
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
