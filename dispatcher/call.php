<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/config.php';
require_once $rootDir . '/includes/auth.php';
// Require full login (including OTP verification) before loading page
require_role('dispatcher', 'dispatcher/call.php');
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

$pageTitle = 'Emergency Call Center';
$turnUrl = trim((string) ers_env('WEBRTC_TURN_URL', ''));
$turnUsername = trim((string) ers_env('WEBRTC_TURN_USERNAME', ''));
$turnCredential = trim((string) ers_env('WEBRTC_TURN_CREDENTIAL', ''));
// A malformed TURN value makes the browser throw while constructing
// RTCPeerConnection. That also prevents the ERS page from joining the
// Socket.IO room, so chat and hang-up incorrectly appear to be local only.
// Keep STUN available, but only publish TURN when the complete credential
// triplet is valid enough for a browser to parse.
$turnIsConfigured = preg_match('/^turns?:/i', $turnUrl) === 1
    && $turnUsername !== ''
    && $turnCredential !== '';
$turnUrls = [$turnUrl];
if ($turnIsConfigured && preg_match('/^turns?:(?:\/\/)?([^:\/?]+)/i', $turnUrl, $turnHostMatch)) {
    $turnHost = strtolower($turnHostMatch[1]);
    if ($turnHost === 'global.relay.metered.ca') {
        $turnUrls = [
            'turn:' . $turnHost . ':80',
            'turn:' . $turnHost . ':80?transport=tcp',
            'turn:' . $turnHost . ':443',
            'turns:' . $turnHost . ':443?transport=tcp',
        ];
    }
}
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
    <script>
        window.__loadSocketIoFallback = function () {
            if (typeof window.io === 'function' || document.getElementById('socket-io-fallback')) return;
            var script = document.createElement('script');
            script.id = 'socket-io-fallback';
            script.src = 'https://cdn.socket.io/4.7.5/socket.io.min.js';
            script.async = true;
            script.onload = function () { window.dispatchEvent(new Event('ers:socket-io-ready')); };
            document.head.appendChild(script);
        };
    </script>
    <script defer src="node_modules/socket.io-client/dist/socket.io.min.js" onerror="window.__loadSocketIoFallback()"></script>
    <script defer src="js/place-autocomplete.js"></script>
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
                                    <div class="transfer-meta" id="incomingTransferMeta" hidden>
                                        <span id="incomingTransferSource">AlertaraQC Emergency Communication</span>
                                        <span id="incomingTransferRoom"></span>
                                    </div>
                                </div>
                            </div>
                            <div class="transfer-summary" id="incomingTransferSummary" hidden>
                                <div><strong>Emergency:</strong> <span id="incomingTransferType"></span></div>
                                <div><strong>Location:</strong> <span id="incomingTransferLocation"></span></div>
                                <div><strong>Priority:</strong> <span id="incomingTransferPriority"></span></div>
                                <div><strong>Description:</strong> <span id="incomingTransferDescription"></span></div>
                                <div><strong>Socket:</strong> <span id="incomingTransferSocket"></span></div>
                            </div>
                            <div class="call-actions">
                                <button class="call-btn accept-btn" id="acceptIncomingCallBtn" onclick="acceptCall()">
                                    <i class="fas fa-check"></i> Accept
                                </button>
                                <button class="call-btn reject-btn" onclick="rejectCall()">
                                    <i class="fas fa-times"></i> Reject
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="transfer-report-stack" id="transferReportNotifications" aria-live="assertive" aria-label="Incoming transferred reports"></div>

                    <div class="create-incident-panel">
                        <div>
                            <div class="panel-eyebrow">Manual Incident</div>
                            <strong>Create a new incident log</strong>
                        </div>
                        <button type="button" class="create-incident-btn" id="createIncidentBtn" onclick="openCreateIncidentForm()">
                            <i class="fas fa-plus"></i> Create
                        </button>
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

                        <div class="call-chat-panel" id="transferCallChatPanel">
                            <div class="call-chat-head">
                                <div>
                                    <div class="panel-eyebrow">Live Chat</div>
                                    <strong>Caller Messages</strong>
                                </div>
                                <span id="transferCallChatStatus">Socket ready</span>
                            </div>
                            <div class="call-chat-messages" id="transferCallMessages" aria-live="polite">
                                <div class="call-chat-empty">Messages between dispatcher and caller will appear here.</div>
                            </div>
                            <div class="call-chat-input-row">
                                <input type="text" id="transferCallMessageInput" placeholder="Type a message to the caller..." autocomplete="off">
                                <button type="button" id="transferCallMessageSend" onclick="sendTransferCallMessage()">
                                    <i class="fas fa-paper-plane"></i> Send
                                </button>
                            </div>
                        </div>

                        <form class="incident-form" id="incidentForm" onsubmit="submitIncident(event)">
                            <div class="manual-form-head">
                                <div>
                                    <div class="panel-eyebrow">Manual Incident</div>
                                    <strong>Create Incident Log</strong>
                                </div>
                                <button type="button" class="manual-form-close" onclick="closeCreateIncidentForm()" aria-label="Close create incident form">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
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
                                        <label>Response Unit Type</label>
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
                                    <div class="form-group location-form-group">
                                        <div class="location-label-row">
                                            <label for="incidentLocation">Location</label>
                                            <span class="location-coverage-tag"><i class="fas fa-map-marker-alt"></i> San Agustin Directory</span>
                                        </div>
                                        <div class="location-input-wrapper">
                                            <input type="text" id="incidentLocation" name="incidentLocation" placeholder="Enter street, subdivision, or landmark (e.g. Patnubay, Susano)" required autocomplete="off">
                                            <span class="location-status-icon" id="locationStatusIcon" title="San Agustin street matching"><i class="fas fa-search-location"></i></span>
                                        </div>
                                        <div class="location-coords-badge" id="locationCoordsBadge" style="display: none;"></div>
                                    </div>
                                </div>
                                <div class="form-group">
                                    <label for="incidentDescription">Description</label>
                                    <textarea id="incidentDescription" name="incidentDescription" placeholder="Brief description of the situation" required></textarea>
                                </div>
                                <div class="priority-section">
                                    <div class="section-title priority-title">
                                        <i class="fas fa-triangle-exclamation"></i>
                                        Priority
                                    </div>
                                    <div class="priority-select" id="prioritySelect" role="radiogroup" aria-label="Incident priority">
                                        <button type="button" class="priority-option low active" data-value="low" role="radio" aria-checked="true">Low</button>
                                        <button type="button" class="priority-option medium" data-value="medium" role="radio" aria-checked="false">Medium</button>
                                        <button type="button" class="priority-option high" data-value="high" role="radio" aria-checked="false">High</button>
                                        <button type="button" class="priority-option critical" data-value="critical" role="radio" aria-checked="false">Critical</button>
                                    </div>
                                    <div class="priority-ai-suggestion" id="priorityAiSuggestion" aria-live="polite">
                                        AI priority suggestion will appear after the incident details are entered.
                                    </div>
                                    <input type="hidden" id="incidentPriority" name="incidentPriority" value="low" required>
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

                    <div class="transfer-queue-panel" id="transferQueuePanel">
                        <div class="transfer-queue-head">
                            <div>
                                <div class="panel-eyebrow">Transferred Queue</div>
                                <h4>Calls & Reports</h4>
                            </div>
                            <span class="transfer-queue-count" id="transferQueueCount">0</span>
                        </div>
                        <div class="transfer-queue-status" id="transferQueueStatus">Listening for transferred calls and reports...</div>
                        <div class="transfer-queue-list" id="transferQueueList" aria-live="polite"></div>
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
                        <button class="filter-tab" data-filter="critical" onclick="setFilter(this)">Critical</button>
                        <button class="filter-tab" data-filter="high" onclick="setFilter(this)">High</button>
                        <button class="filter-tab" data-filter="medium" onclick="setFilter(this)">Medium</button>
                        <button class="filter-tab" data-filter="low" onclick="setFilter(this)">Low</button>
                    </div>

                    <div class="incident-list" id="incidentList"></div>
                </aside>
            </div>
        </div>
    </div>

    <div class="incident-details-modal" id="incidentDetailsModal" aria-hidden="true">
        <div class="incident-details-dialog" role="dialog" aria-modal="true" aria-labelledby="incidentDetailsTitle">
            <div class="incident-details-head">
                <div>
                    <div class="panel-eyebrow">Incident Details</div>
                    <h2 id="incidentDetailsTitle">Incident</h2>
                </div>
                <button type="button" class="incident-details-close" onclick="closeIncidentModal()" aria-label="Close incident details">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="incident-details-body" id="incidentDetailsBody"></div>
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
    const API_CALL_AUDIT_URL = 'api/call_audit_event.php';
    const API_AI_PRIORITY_URL = 'api/ai_priority_suggestion.php';
    const API_INCOMING_TRANSFERS_URL = 'api/incoming_transfers.php';
    const ALERTARA_SOCKET_URL = 'https://emergency-comm.alertaraqc.com';
    const ALERTARA_SOCKET_PATH = '/socket.io';
    const TRANSFER_STUN_SERVERS = [
        { urls: 'stun:stun.l.google.com:19302' },
        { urls: 'stun:global.stun.twilio.com:3478' }
    ];
    const TRANSFER_ICE_SERVERS = [
        ...TRANSFER_STUN_SERVERS
        <?php if ($turnIsConfigured): ?>,
        {
            urls: <?php echo json_encode($turnUrls, JSON_UNESCAPED_SLASHES); ?>,
            username: <?php echo json_encode($turnUsername); ?>,
            credential: <?php echo json_encode($turnCredential); ?>
        }
        <?php endif; ?>
    ];
    const TRANSFER_INBOX_ROOM = 'ers-transfer-inbox';
    let priorityAuto = true; // keeps transferred/manual fallback priority until the user chooses one
    let prioritySuggestTimer = null; // debounce timer for suggestion updates
    let prioritySuggestRequestSeq = 0;
    let currentSearch = '';
    let filterDay = '';
    let filterMonth = '';
    const incidentGeocodeCache = {};
    let speechRecognition = null;
    let speechListening = false;
    let finalTranscriptText = '';
    let pendingIncomingCall = null;
    let latestTransferLogId = Number(window.localStorage.getItem('ersLatestTransferLogId') || '0') || 0;
    let incomingTransferBaselineReady = latestTransferLogId > 0;
    let incomingTransferPollInFlight = false;
    const shownIncomingTransferIds = new Set();
    const notifiedIncomingTransferKeys = new Set();
    const liveIncomingTransferKeys = new Set();
    const DISMISSED_TRANSFER_QUEUE_STORAGE_KEY = 'ersDismissedTransferQueueKeys';
    const DISMISSED_TRANSFER_QUEUE_LIMIT = 200;
    let transferQueueItems = [];
    let activeTransferCall = null;
    let endingTransferCall = false;
    let incomingCallQueue = [];
    let incomingCallSequence = 0;
    let incomingTransferPollTimer = null;
    let transferInboxSocketRetryTimer = null;
    let transferInboxSocketRetryCount = 0;
    let transferCallMessages = [];
    let transferCallMessageIds = new Set();
    const INCOMING_TRANSFER_POLL_MS = 5000;
    const PRIORITY_ORDER = { critical: 0, high: 1, medium: 2, low: 3 };
    function normalizePriority(value, fallback = 'medium') {
        const priority = String(value || '').trim().toLowerCase();
        if (priority === 'urgent') return 'high';
        if (priority === 'moderate') return 'medium';
        if (Object.prototype.hasOwnProperty.call(PRIORITY_ORDER, priority)) return priority;
        if (fallback === 'urgent') return 'high';
        return fallback === 'moderate' ? 'medium' : fallback;
    }

    function priorityRank(value) {
        return PRIORITY_ORDER[normalizePriority(value)];
    }

    function parseQueueTime(value) {
        const parsed = Date.parse(String(value || '').replace(' ', 'T'));
        return Number.isFinite(parsed) ? parsed : 0;
    }

    function comparePriorityThenOldest(a, b) {
        const byPriority = priorityRank(a.priority) - priorityRank(b.priority);
        if (byPriority !== 0) return byPriority;
        const byTime = parseQueueTime(a.transferred_at || a.start) - parseQueueTime(b.transferred_at || b.start);
        if (byTime !== 0) return byTime;
        return Number(a.queueSequence || 0) - Number(b.queueSequence || 0);
    }

    function comparePriorityThenNewest(a, b) {
        const byPriority = priorityRank(a.priority) - priorityRank(b.priority);
        if (byPriority !== 0) return byPriority;
        const byLog = Number(b.transfer_log_id || 0) - Number(a.transfer_log_id || 0);
        if (byLog !== 0) return byLog;
        return parseQueueTime(b.transferred_at || b.created_at) - parseQueueTime(a.transferred_at || a.created_at);
    }

    function loadDismissedTransferQueueKeys() {
        try {
            const stored = JSON.parse(window.localStorage.getItem(DISMISSED_TRANSFER_QUEUE_STORAGE_KEY) || '[]');
            if (!Array.isArray(stored)) return [];
            return stored
                .map((value) => String(value || '').trim())
                .filter(Boolean)
                .slice(-DISMISSED_TRANSFER_QUEUE_LIMIT);
        } catch (error) {
            return [];
        }
    }

    const dismissedTransferQueueKeys = new Set(loadDismissedTransferQueueKeys());

    function saveDismissedTransferQueueKeys() {
        try {
            const keys = Array.from(dismissedTransferQueueKeys).slice(-DISMISSED_TRANSFER_QUEUE_LIMIT);
            window.localStorage.setItem(DISMISSED_TRANSFER_QUEUE_STORAGE_KEY, JSON.stringify(keys));
        } catch (error) {
            console.warn('Unable to save dismissed transfer queue keys:', error);
        }
    }

    function isTransferQueueDismissed(key) {
        return key && dismissedTransferQueueKeys.has(String(key));
    }

    function markTransferQueueKeysDismissed(keys) {
        let changed = false;
        (Array.isArray(keys) ? keys : []).forEach((value) => {
            const key = String(value || '').trim();
            if (!key || dismissedTransferQueueKeys.has(key)) return;
            dismissedTransferQueueKeys.add(key);
            changed = true;
        });
        if (changed) {
            saveDismissedTransferQueueKeys();
        }
    }

    function dismissTransferredQueueItem(key) {
        const normalizedKey = String(key || '').trim();
        if (!normalizedKey) return;
        markTransferQueueKeysDismissed([normalizedKey]);
        transferQueueItems = transferQueueItems.filter((item) => item.queue_key !== normalizedKey);
        renderTransferredQueue();
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function formatDateTime(value) {
        const raw = String(value || '').trim();
        if (!raw) return 'N/A';
        const parsed = new Date(raw.indexOf('T') === -1 ? raw.replace(' ', 'T') : raw);
        return Number.isNaN(parsed.getTime()) ? raw : parsed.toLocaleString();
    }

    let alertAudioContext = null;
    let transferSocket = null;
    let transferSocketUsesInbox = false;
    let transferSocketRoom = '';
    let transferSocketCallHandlers = [];
    let transferPeerConnection = null;
    let transferLocalStream = null;
    let transferLocalStreamPromise = null;
    let transferRemoteAudio = null;
    let transferInboxSocket = null;
    let transferOfferRequestTimer = null;
    let pendingTransferIceCandidates = [];
    let pendingTransferCallMessages = new Map();
    let transferNegotiationId = '';
    const SpeechRecognitionApi = window.SpeechRecognition || window.webkitSpeechRecognition || null;

    function bindTransferCallSocketHandler(eventName, handler) {
        if (!transferSocket || typeof handler !== 'function') return;
        transferSocket.on(eventName, handler);
        transferSocketCallHandlers.push({ eventName, handler });
    }

    function clearTransferCallSocketHandlers(socketInstance = transferSocket) {
        if (socketInstance && typeof socketInstance.off === 'function') {
            transferSocketCallHandlers.forEach(({ eventName, handler }) => {
                socketInstance.off(eventName, handler);
            });
        }
        transferSocketCallHandlers = [];
    }

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
            panel.classList.remove('manual-create');
            stopTimer();
            stopVoiceTools();
            activeCall = null;
            activeTransferCall = null;
            clearTransferFormContext();
            clearCallAuditContext();
            updateStats();
            return;
        }

        const start = Number(session.start);
        const acceptedAt = Number(session.acceptedAt);
        activeCall = {
            name: session.name || 'Unknown',
            phone: session.phone || '',
            start: Number.isFinite(start) && start > 0 ? start : Date.now(),
            acceptedAt: Number.isFinite(acceptedAt) && acceptedAt > 0 ? acceptedAt : null,
            auditSessionId: String(session.auditSessionId || '')
        };
        if (session.isTransfer === true) {
            activeTransferCall = session;
            storeTransferFormContext(session);
        }

        panel.classList.add('active');
        panel.classList.remove('manual-create');
        const endButton = panel.querySelector('.end-call-btn');
        if (endButton) {
            endButton.disabled = false;
            endButton.innerHTML = '<i class="fas fa-phone-slash"></i> End Call';
        }
        document.getElementById('activeCallerName').textContent = 'Caller: ' + activeCall.name;
        document.getElementById('activeCallerPhone').textContent = activeCall.phone;
        document.getElementById('callerName').value = activeCall.name;
        document.getElementById('callerPhone').value = activeCall.phone;
        setVoiceState('Connected. Ready for caller audio and dictation.');
        startTimer();
        updateStats();
    }

    function openCreateIncidentForm() {
        const panel = document.getElementById('activeCallPanel');
        if (!panel) return;
        panel.classList.add('active', 'manual-create');
        stopTimer();
        stopVoiceTools();
        activeCall = null;
        activeTransferCall = null;
        clearTransferFormContext();
        clearCallAuditContext();

        const timerEl = document.getElementById('callTimer');
        if (timerEl) timerEl.textContent = 'Manual';
        const nameEl = document.getElementById('activeCallerName');
        const phoneEl = document.getElementById('activeCallerPhone');
        if (nameEl) nameEl.textContent = 'Manual Incident';
        if (phoneEl) phoneEl.textContent = '';

        const form = document.getElementById('incidentForm');
        if (form) {
            form.reset();
        }
        resetIncidentTypeChecklist();
        setPrioritySelection('low');
        setPrioritySuggestionMessage('AI priority suggestion will appear after the incident details are entered.', 'idle');
        priorityAuto = true;
        clearTransferFormContext();
        const locationInput = document.getElementById('incidentLocation');
        if (locationInput) {
            delete locationInput.dataset.lat;
            delete locationInput.dataset.lon;
        }

        focusAcceptedCallForm();
    }

    function closeCreateIncidentForm() {
        const panel = document.getElementById('activeCallPanel');
        if (!panel || !panel.classList.contains('manual-create')) return;

        panel.classList.remove('active', 'manual-create');

        const form = document.getElementById('incidentForm');
        if (form) {
            form.reset();
        }
        resetIncidentTypeChecklist();
        setPrioritySelection('low');
        setPrioritySuggestionMessage('AI priority suggestion will appear after the incident details are entered.', 'idle');
        priorityAuto = true;
        const locationInput = document.getElementById('incidentLocation');
        if (locationInput) {
            delete locationInput.dataset.lat;
            delete locationInput.dataset.lon;
        }
        clearCallAuditContext();
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
        params.set('open_dispatch', '1');
        const dispatchUrl = new URL('dispatcher/dispatch.php', document.baseURI || window.location.href);
        dispatchUrl.search = params.toString();
        window.location.href = dispatchUrl.href;
    }

    function broadcastLoggedIncident(data) {
        const now = Date.now();
        const incident = {
            id: Number(data && data.incident_id ? data.incident_id : 0) || null,
            reference_no: String((data && (data.incident_reference_no || data.reference_no)) || ''),
            status: String((data && data.incident_status) || 'pending'),
            logged_at: now
        };
        try {
            window.localStorage.setItem('ers_last_logged_incident', JSON.stringify(incident));
            window.localStorage.setItem('ers_incidents_changed', String(now));
        } catch (e) {}
        try {
            window.dispatchEvent(new CustomEvent('ers:incident-logged', { detail: incident }));
        } catch (e) {}
    }

    function runAfterFirstPaint(callback, delay = 250) {
        if (typeof window.requestIdleCallback === 'function') {
            window.requestIdleCallback(callback, { timeout: 1200 });
            return;
        }
        window.setTimeout(callback, delay);
    }

    document.addEventListener('DOMContentLoaded', () => {
        initPrioritySelect();
        initIncidentTypeChecklist();
        initIncidentSidebarControls();
        // Hook suggestion on description input
        const descEl = document.getElementById('incidentDescription');
        const schedulePrioritySuggestion = () => {
            const val = descEl ? descEl.value : '';
            if (prioritySuggestTimer) clearTimeout(prioritySuggestTimer);
            prioritySuggestTimer = setTimeout(() => updatePrioritySuggestion(val), 450);
        };
        if (descEl) {
            descEl.addEventListener('input', schedulePrioritySuggestion);
            // Initialize suggestion only if there is content
            if ((descEl.value || '').trim().length >= 3) {
                updatePrioritySuggestion(descEl.value);
            }
        }
        document.getElementById('incidentLocation')?.addEventListener('input', schedulePrioritySuggestion);
        document.getElementById('callNotes')?.addEventListener('input', schedulePrioritySuggestion);
        renderActiveCallPanel(getSharedCallSession());
        document.addEventListener('ers:call-session-change', (event) => {
            renderActiveCallPanel(event.detail ? event.detail.session : getSharedCallSession());
        });
        document.addEventListener('ers:incoming-call', (event) => {
            showIncomingCallModal(event.detail || {});
        });
        window.addEventListener('ers:socket-io-ready', startTransferInboxSocket);
        renderTransferredQueue();
        startTransferInboxSocket();
        window.setTimeout(startIncomingTransferPolling, 350);
        if (RECENT_INCIDENTS_ENABLED) {
            runAfterFirstPaint(loadIncidentsFromServer, 450);
        } else {
            incidentItems = [];
            renderIncidents();
            updateStats();
        }
    });

    function normalizeIncomingCallDetail(call) {
        const raw = call && typeof call === 'object' ? call : {};
        const nameValue = raw.name || raw.caller_name || raw.callerName;
        const phoneValue = raw.phone || raw.caller_phone || raw.callerPhone;
        const startValue = raw.start || raw.received_at || raw.created_at;
        const parsedStart = startValue ? Date.parse(startValue) : NaN;
        const name = String(nameValue || 'Incoming Call').trim();
        const phone = String(phoneValue || '').trim();
        const isTransfer = !!(raw.is_transfer || raw.room || raw.transfer_id || raw.transfer_type);
        return {
            name: name || 'Incoming Call',
            phone: phone,
            start: Number(raw.start) > 0 ? Number(raw.start) : (Number.isFinite(parsedStart) ? parsedStart : Date.now()),
            auditSessionId: String(raw.audit_session_id || raw.auditSessionId || '').trim(),
            isTransfer,
            transferId: raw.transfer_id || raw.transferId || '',
            callId: raw.call_id_external || raw.callId || raw.call_id || raw.transfer_id || raw.transferId || '',
            conversationId: raw.conversation_id || raw.conversationId || '',
            room: raw.room || '',
            transferType: raw.transfer_type || raw.transferType || (raw.room ? 'live_call' : 'report'),
            socketUrl: raw.socket_url || raw.socketUrl || ALERTARA_SOCKET_URL,
            socketPath: raw.socket_path || raw.socketPath || ALERTARA_SOCKET_PATH,
            sourceSystem: raw.source_system || raw.sourceSystem || 'AlertaraQC Emergency Communication',
            incidentId: raw.incident_id || raw.incidentId || null,
            incidentReferenceNo: raw.reference_no || raw.incidentReferenceNo || '',
            incidentStatus: raw.incident_status || raw.incidentStatus || '',
            incidentType: raw.type || raw.incidentType || '',
            priority: normalizePriority(raw.priority, ''),
            location: raw.location || '',
            latitude: raw.latitude || raw.lat || null,
            longitude: raw.longitude || raw.lng || raw.lon || null,
            description: raw.description || '',
            queueSequence: ++incomingCallSequence
        };
    }

    function createCallAuditSessionId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }
        const randomPart = Math.random().toString(36).slice(2, 14);
        return 'call:' + Date.now().toString(36) + ':' + randomPart;
    }

    function ensureCallAuditSession(call) {
        if (!call || typeof call !== 'object') return '';
        let auditSessionId = String(call.auditSessionId || call.audit_session_id || '').trim();
        if (!/^[A-Za-z0-9.:-]{8,96}$/.test(auditSessionId)) {
            auditSessionId = createCallAuditSessionId();
        }
        call.auditSessionId = auditSessionId;
        return auditSessionId;
    }

    function recordCallAuditMilestone(call, milestone, reason = '') {
        if (!call || typeof call !== 'object') return;
        const auditSessionId = ensureCallAuditSession(call);
        if (!auditSessionId) return;

        const event = String(milestone || '').trim().toLowerCase();
        let eventTime = Date.now();
        if (event === 'received') {
            eventTime = Number(call.start || eventTime);
        } else if (event === 'accepted') {
            eventTime = Number(call.acceptedAt || eventTime);
        }
        if (!Number.isFinite(eventTime) || eventTime <= 0) {
            eventTime = Date.now();
        }

        const payload = {
            audit_session_id: auditSessionId,
            event,
            occurred_at: new Date(eventTime).toISOString(),
            reference_no: String(call.incidentReferenceNo || call.reference_no || '').trim(),
            is_transfer: call.isTransfer === true,
            source_system: String(call.sourceSystem || call.source_system || '').trim(),
            reason: String(reason || '').trim()
        };

        fetch(API_CALL_AUDIT_URL, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive: event === 'rejected' || event === 'ended',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then((response) => {
            if (!response.ok) {
                throw new Error('Call audit endpoint returned HTTP ' + response.status);
            }
            return response.json();
        }).then((result) => {
            if (!result || result.ok !== true) {
                throw new Error(result && result.error ? result.error : 'Call audit event was not recorded');
            }
        }).catch((error) => {
            // The canonical calls_create endpoint records the same timestamps as
            // a fallback when the live audit request is temporarily unavailable.
            console.warn('Call audit milestone failed:', error);
        });
    }

    function incomingCallKey(call) {
        return String(call && (call.transferId || call.callId || call.incidentId || '') || '').trim();
    }

    function displayIncomingCallModal(call) {
        const modal = document.getElementById('incomingCallModal');
        const alert = document.getElementById('incomingCallAlert');
        if (!modal || !alert) return;

        pendingIncomingCall = call;
        ensureCallAuditSession(pendingIncomingCall);
        recordCallAuditMilestone(pendingIncomingCall, 'received');

        const eyebrow = document.querySelector('.call-alert-eyebrow');
        if (eyebrow) {
            eyebrow.textContent = call.isTransfer ? 'Incoming Transferred Call' : 'Incoming Call';
        }
        document.getElementById('incomingCallerName').textContent = pendingIncomingCall.name;
        document.getElementById('incomingCallerPhone').textContent = pendingIncomingCall.phone || 'Unknown number';
        renderIncomingTransferDetails(pendingIncomingCall);
        modal.classList.add('active');
        alert.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('incoming-call-modal-open');
        if (call.isTransfer) {
            playIncomingTransferAlert();
        }
        document.getElementById('acceptIncomingCallBtn')?.focus();
    }

    function showNextQueuedIncomingCall() {
        if (activeCall || pendingIncomingCall || !incomingCallQueue.length) return false;
        incomingCallQueue.sort(comparePriorityThenOldest);
        displayIncomingCallModal(incomingCallQueue.shift());
        return true;
    }

    function enqueueIncomingCall(call) {
        const incoming = normalizeIncomingCallDetail(call);
        const key = incomingCallKey(incoming);
        if (key) {
            const existingIndex = incomingCallQueue.findIndex((item) => incomingCallKey(item) === key);
            if (existingIndex >= 0) {
                incomingCallQueue[existingIndex] = { ...incomingCallQueue[existingIndex], ...incoming };
            } else if (!pendingIncomingCall || incomingCallKey(pendingIncomingCall) !== key) {
                incomingCallQueue.push(incoming);
            }
        } else {
            incomingCallQueue.push(incoming);
        }
        incomingCallQueue.sort(comparePriorityThenOldest);

        if (!activeCall && pendingIncomingCall && incomingCallQueue.length && comparePriorityThenOldest(incomingCallQueue[0], pendingIncomingCall) < 0) {
            incomingCallQueue.push(pendingIncomingCall);
            incomingCallQueue.sort(comparePriorityThenOldest);
            displayIncomingCallModal(incomingCallQueue.shift());
            return;
        }

        showNextQueuedIncomingCall();
    }

    function showIncomingCallModal(call) {
        enqueueIncomingCall(call);
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
        const acceptedAt = Date.now();
        incomingCall.acceptedAt = acceptedAt;
        ensureCallAuditSession(incomingCall);
        storeCallAuditContext(incomingCall);
        recordCallAuditMilestone(incomingCall, 'accepted');
        hideIncomingCallModal();
        pendingIncomingCall = null;
        removeTransferredQueueItemForIncomingCall(incomingCall);
        activeTransferCall = incomingCall.isTransfer ? incomingCall : null;
        const sessionApi = getSharedCallSessionApi();
        if (sessionApi) {
            sessionApi.start({
                name: name,
                phone: phone,
                start: start,
                acceptedAt: acceptedAt,
                auditSessionId: incomingCall.auditSessionId || '',
                muted: false,
                speaker: false,
                incidentId: incomingCall.incidentId || null,
                incidentReferenceNo: incomingCall.incidentReferenceNo || '',
                incidentStatus: incomingCall.incidentStatus || '',
                incidentType: incomingCall.incidentType || '',
                location: incomingCall.location || '',
                priority: incomingCall.priority || '',
                latitude: incomingCall.latitude ?? null,
                longitude: incomingCall.longitude ?? null,
                description: incomingCall.description || '',
                isTransfer: incomingCall.isTransfer === true,
                transferId: incomingCall.transferId || '',
                callId: incomingCall.callId || '',
                conversationId: incomingCall.conversationId || '',
                room: incomingCall.room || '',
                transferType: incomingCall.transferType || '',
                socketUrl: incomingCall.socketUrl || '',
                socketPath: incomingCall.socketPath || '',
                sourceSystem: incomingCall.sourceSystem || ''
            });
        } else {
            activeCall = {
                active: true,
                name,
                phone,
                start,
                acceptedAt,
                auditSessionId: incomingCall.auditSessionId || '',
                isTransfer: incomingCall.isTransfer === true
            };
        }
        applyIncomingCallToForm(incomingCall);
        resetTransferCallChat(incomingCall);
        connectTransferSocket(incomingCall);
        prepareTransferLocalAudio(incomingCall);
        renderActiveCallPanel(getSharedCallSession() || activeCall);
        if (incomingCall.isTransfer && !incomingCall.room) {
            setVoiceState('Transferred call opened, but no live room was included by Emergency-Com. Ask them to retry the transfer after deploying the live payload fix.');
        }
        focusAcceptedCallForm();
    }

    function rejectCall() {
        const rejectedCall = pendingIncomingCall || {};
        if (rejectedCall && (rejectedCall.start || rejectedCall.auditSessionId)) {
            recordCallAuditMilestone(rejectedCall, 'rejected', 'dispatcher-rejected');
        }
        pendingIncomingCall = null;
        hideIncomingCallModal();
        showNextQueuedIncomingCall();
    }

    function transferHangupPayload(call, reason) {
        const callIdValue = String((call && (call.callId || call.call_id || call.transferId || call.transfer_id)) || '').trim();
        return {
            callId: callIdValue,
            call_id: callIdValue,
            transferId: String((call && (call.transferId || call.transfer_id)) || '').trim(),
            transfer_id: String((call && (call.transferId || call.transfer_id)) || '').trim(),
            conversationId: String((call && (call.conversationId || call.conversation_id)) || '').trim(),
            conversation_id: String((call && (call.conversationId || call.conversation_id)) || '').trim(),
            room: String((call && call.room) || '').trim(),
            endedBy: 'response_team',
            reason: reason || 'response-team-ended',
            endedAt: new Date().toISOString()
        };
    }

    function notifyTransferredCallerHangup(call, reason = 'response-team-ended') {
        const payload = transferHangupPayload(call, reason);
        if (!transferSocket || !payload.room) return;
        transferSocket.emit('hangup', payload, payload.room);
    }

    function showEndedCallForm(call, reason = 'call-ended') {
        const panel = document.getElementById('activeCallPanel');
        if (!panel) return;
        panel.classList.add('active');
        const name = String((call && call.name) || 'Caller').trim();
        const phone = String((call && call.phone) || '').trim();
        const nameEl = document.getElementById('activeCallerName');
        const phoneEl = document.getElementById('activeCallerPhone');
        const timerEl = document.getElementById('callTimer');
        const endButton = panel.querySelector('.end-call-btn');
        if (nameEl) nameEl.textContent = 'Caller: ' + name;
        if (phoneEl) phoneEl.textContent = phone;
        if (timerEl) timerEl.textContent = 'Ended';
        if (endButton) {
            endButton.disabled = true;
            endButton.innerHTML = '<i class="fas fa-phone-slash"></i> Call Ended';
        }
        setVoiceState(reason === 'caller-ended'
            ? 'Caller ended the live audio. Finish the incident form when ready.'
            : 'Live audio ended. Finish the incident form when ready.');
    }

    function closeTransferVoiceSession(options = {}) {
        if (endingTransferCall) return;
        endingTransferCall = true;
        const keepForm = options.keepForm !== false;
        const reason = options.reason || 'call-ended';
        const call = activeTransferCall || getSharedCallSession() || activeCall || {};
        dismissTransferredQueueItemForIncomingCall(call);

        if (options.notifyPeer === true) {
            notifyTransferredCallerHangup(call, reason);
        }
        if (call && (call.start || call.auditSessionId)) {
            recordCallAuditMilestone(call, 'ended', reason);
        }

        stopVoiceTools();
        disconnectTransferCall();
        const sessionApi = getSharedCallSessionApi();
        if (sessionApi) {
            sessionApi.end();
        }
        activeCall = null;
        activeTransferCall = keepForm && call && call.isTransfer === true ? call : null;
        if (keepForm) {
            storeCallAuditContext(call);
        } else {
            clearCallAuditContext();
        }
        if (activeTransferCall) {
            storeTransferFormContext(activeTransferCall);
        } else {
            clearTransferFormContext();
        }
        stopTimer();
        updateStats();

        if (keepForm) {
            showEndedCallForm(call, reason);
        } else {
            renderActiveCallPanel(null);
        }

        window.setTimeout(() => {
            endingTransferCall = false;
        }, 250);
        if (!showNextQueuedIncomingCall()) {
            showNextTransferredQueueCall();
        }
    }

    function endCall() {
        closeTransferVoiceSession({ notifyPeer: true, reason: 'response-team-ended', keepForm: true });
    }

    function renderIncomingTransferDetails(call) {
        const meta = document.getElementById('incomingTransferMeta');
        const summary = document.getElementById('incomingTransferSummary');
        const source = document.getElementById('incomingTransferSource');
        const room = document.getElementById('incomingTransferRoom');
        const type = document.getElementById('incomingTransferType');
        const location = document.getElementById('incomingTransferLocation');
        const priority = document.getElementById('incomingTransferPriority');
        const description = document.getElementById('incomingTransferDescription');
        const socket = document.getElementById('incomingTransferSocket');
        const isTransfer = !!(call && call.isTransfer);

        if (meta) meta.hidden = !isTransfer;
        if (summary) summary.hidden = !isTransfer;
        if (!isTransfer) return;

        if (source) source.textContent = call.sourceSystem || 'AlertaraQC Emergency Communication';
        if (room) room.textContent = call.room ? 'Room ' + call.room : 'No live room - report transfer';
        if (type) type.textContent = call.incidentType || 'Emergency';
        if (location) location.textContent = call.location || 'Not provided';
        if (priority) priority.textContent = call.priority || 'medium';
        if (description) description.textContent = call.description || 'No description provided';
        if (socket) socket.textContent = (call.socketUrl || ALERTARA_SOCKET_URL) + (call.socketPath || ALERTARA_SOCKET_PATH);
    }

    function transferredIncidentItem(report) {
        return {
            id: Number(report.incident_id || 0),
            incident_code: report.reference_no || ('Transferred report ' + (report.transfer_id || '')),
            status: report.incident_status || 'pending',
            type: report.type || 'other',
            priority: report.priority || 'medium',
            location: report.location || 'Not provided',
            latitude: report.latitude ?? null,
            longitude: report.longitude ?? null,
            caller_name: report.caller_name || 'Transferred reporter',
            caller_phone: report.caller_phone || '',
            description: report.description || 'No description provided',
            created_at: report.transferred_at || new Date().toISOString()
        };
    }

    function showIncomingReportNotification(report) {
        const stack = document.getElementById('transferReportNotifications');
        if (!stack || !report) return;

        const notificationId = String(report.transfer_log_id || report.transfer_id || report.incident_id || '');
        if (notificationId && stack.querySelector('[data-transfer-notification="' + CSS.escape(notificationId) + '"]')) {
            return;
        }

        const priority = normalizePriority(report.priority);
        const card = document.createElement('article');
        card.className = 'transfer-report-notification priority-' + priority;
        if (notificationId) card.dataset.transferNotification = notificationId;
        card.innerHTML = [
            '<div class="transfer-report-head">',
                '<span class="transfer-report-icon" aria-hidden="true">' + transferSourceIconSvg(false) + '</span>',
                '<div>',
                    '<div class="transfer-report-eyebrow">Incoming Transferred Report</div>',
                    '<strong class="transfer-report-title"></strong>',
                '</div>',
                '<button type="button" class="transfer-report-dismiss" title="Dismiss notification" aria-label="Dismiss report notification">',
                    '<i class="fas fa-times"></i>',
                '</button>',
            '</div>',
            '<div class="transfer-report-body">',
                '<span class="transfer-report-priority"></span>',
                '<p class="transfer-report-description"></p>',
                '<div class="transfer-report-location"><i class="fas fa-map-marker-alt"></i><span></span></div>',
            '</div>',
            '<div class="transfer-report-actions">',
                '<button type="button" class="transfer-report-open">',
                    '<i class="fas fa-eye"></i> Open report',
                '</button>',
            '</div>'
        ].join('');

        card.querySelector('.transfer-report-title').textContent =
            report.reference_no || report.type || 'Emergency report';
        card.querySelector('.transfer-report-priority').textContent =
            priority.toUpperCase() + ' PRIORITY';
        card.querySelector('.transfer-report-description').textContent =
            report.description || 'No description provided';
        card.querySelector('.transfer-report-location span').textContent =
            report.location || 'Location not provided';
        card.querySelector('.transfer-report-dismiss').addEventListener('click', () => card.remove());
        card.querySelector('.transfer-report-open').addEventListener('click', () => {
            openTransferredReportForm(report);
            card.remove();
        });

        stack.prepend(card);
        while (stack.children.length > 4) {
            stack.lastElementChild.remove();
        }
        playIncomingTransferAlert();
        openTransferredReportForm(report);
    }

    function transferQueueKey(transfer) {
        return String(
            transfer.transfer_id
            || transfer.call_id_external
            || transfer.callId
            || transfer.call_id
            || transfer.room
            || transfer.transfer_log_id
            || transfer.incident_id
            || ''
        ).trim();
    }

    function normalizeTransferQueueItem(transfer) {
        const key = transferQueueKey(transfer);
        if (!key) return null;
        const room = String(transfer.room || '').trim();
        const transferType = transferLooksLikeCall(transfer) ? 'live_call' : 'report';
        return {
            queue_key: key,
            transfer_log_id: Number(transfer.transfer_log_id || 0),
            transfer_id: transfer.transfer_id || '',
            call_id_external: transfer.call_id_external || transfer.callId || transfer.call_id || transfer.transfer_id || '',
            conversation_id: transfer.conversation_id || transfer.conversationId || '',
            transfer_type: transferType,
            room,
            socket_url: transfer.socket_url || transfer.socketUrl || ALERTARA_SOCKET_URL,
            socket_path: transfer.socket_path || transfer.socketPath || ALERTARA_SOCKET_PATH,
            source_system: transfer.source_system || 'AlertaraQC Emergency Communication',
            incident_id: transfer.incident_id || null,
            reference_no: transfer.reference_no || '',
            incident_status: transfer.incident_status || 'pending',
            type: transfer.type || 'Emergency',
            priority: transfer.priority || 'medium',
            location: transfer.location || 'Location not provided',
            latitude: transfer.latitude ?? null,
            longitude: transfer.longitude ?? null,
            description: transfer.description || 'No description provided',
            caller_name: transfer.caller_name || transfer.name || 'Transferred Caller',
            caller_phone: transfer.caller_phone || transfer.phone || '',
            transferred_at: transfer.transferred_at || transfer.created_at || new Date().toISOString(),
            fallback_notice: transfer.fallback_notice || ''
        };
    }

    function transferLooksLikeCall(transfer) {
        if (!transfer) return false;
        const explicitType = String(transfer.transfer_type || '').toLowerCase();
        if (explicitType === 'live_call') return true;
        if (explicitType === 'report' || explicitType === 'message' || explicitType === 'message_report') return false;
        if (transfer.room) return true;
        const text = [
            transfer.event,
            transfer.description,
            transfer.title,
            transfer.reference_no,
            transfer.fallback_notice
        ].map((value) => String(value || '').toLowerCase()).join(' ');
        return text.includes('transferred call') || text.includes('emergency_call_transfer') || text.includes('call transfer');
    }

    function upsertTransferredQueueItem(transfer) {
        const item = normalizeTransferQueueItem(transfer);
        if (!item) return;
        if (isTransferQueueDismissed(item.queue_key)) {
            transferQueueItems = transferQueueItems.filter((existing) => existing.queue_key !== item.queue_key);
            renderTransferredQueue();
            return;
        }
        const terminalStatus = String(item.incident_status || '').toLowerCase();
        if (['resolved', 'cancelled', 'closed', 'rejected'].includes(terminalStatus)) {
            transferQueueItems = transferQueueItems.filter((existing) => existing.queue_key !== item.queue_key);
            renderTransferredQueue();
            return;
        }
        const existingIndex = transferQueueItems.findIndex((existing) => existing.queue_key === item.queue_key);
        if (existingIndex >= 0) {
            const existing = transferQueueItems[existingIndex];
            const merged = { ...existing, ...item };
            // Polling may return the database copy before its live signaling
            // fields are populated. Never erase the room/call identity from
            // the realtime notification, otherwise Answer opens a form but
            // cannot join the caller's Socket.IO room.
            ['room', 'socket_url', 'socket_path', 'call_id_external', 'conversation_id', 'transfer_id'].forEach((field) => {
                if (!String(item[field] || '').trim() && String(existing[field] || '').trim()) {
                    merged[field] = existing[field];
                }
            });
            if (existing.transfer_type === 'live_call') merged.transfer_type = 'live_call';
            transferQueueItems[existingIndex] = merged;
        } else {
            transferQueueItems.unshift(item);
        }
        transferQueueItems.sort(comparePriorityThenNewest);
        transferQueueItems = transferQueueItems.slice(0, 20);
        renderTransferredQueue();
    }

    function renderTransferredQueue() {
        const panel = document.getElementById('transferQueuePanel');
        const list = document.getElementById('transferQueueList');
        const count = document.getElementById('transferQueueCount');
        const status = document.getElementById('transferQueueStatus');
        if (!panel || !list || !count) return;
        count.textContent = String(transferQueueItems.length);
        if (!transferQueueItems.length) {
            if (status) {
                status.textContent = 'Live queue is ready. No transferred calls or reports waiting right now.';
                status.classList.remove('is-error', 'is-active');
            }
            list.innerHTML = `
                <div class="transfer-queue-empty">
                    <i class="fas fa-satellite-dish"></i>
                    <span>Waiting for incoming transfers</span>
                </div>
            `;
            return;
        }
        if (status) {
            status.textContent = 'Incoming transfers available in Call Receiving & Logs.';
            status.classList.add('is-active');
            status.classList.remove('is-error');
        }
        list.innerHTML = transferQueueItems.map(transferredQueueCardHtml).join('');
        list.querySelectorAll('[data-transfer-action]').forEach((button) => {
            button.addEventListener('click', (event) => {
                event.stopPropagation();
                const action = button.dataset.transferAction || '';
                const key = button.dataset.transferKey || '';
                if (action === 'answer') {
                    answerTransferredQueueItem(key);
                } else if (action === 'open') {
                    openTransferredReportQueueItem(key);
                } else if (action === 'dismiss') {
                    dismissTransferredQueueItem(key);
                }
            });
        });
    }

    function transferredQueueCardHtml(item) {
        const isLiveCall = item.transfer_type === 'live_call';
        const canAnswerAudio = !!item.room;
        const priorityClass = normalizePriority(item.priority);
        const title = item.reference_no || (isLiveCall ? 'Transferred live call' : 'Transferred report');
        const actionText = isLiveCall ? (canAnswerAudio ? 'Answer call' : 'Open call') : 'Open report';
        const actionIcon = isLiveCall ? 'fa-phone-volume' : 'fa-eye';
        const actionName = isLiveCall ? 'answer' : 'open';
        return `
            <article class="transfer-queue-card ${isLiveCall ? 'is-live' : 'is-report'} priority-${priorityClass}">
                <div class="transfer-queue-card-head">
                    <span class="transfer-queue-icon" aria-hidden="true">${transferSourceIconSvg(isLiveCall)}</span>
                    <div class="transfer-queue-copy">
                        <strong>${escapeHtml(title)}</strong>
                        <span>${escapeHtml(isLiveCall ? 'Live call waiting' : 'Message/report waiting')}</span>
                    </div>
                    <button type="button" class="transfer-queue-dismiss" data-transfer-action="dismiss" data-transfer-key="${escapeHtml(item.queue_key)}" title="Dismiss from this queue" aria-label="Dismiss transfer">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="transfer-queue-meta">
                    <span class="transfer-queue-priority">${escapeHtml(priorityClass.toUpperCase())}</span>
                    <span>${escapeHtml(labelForType(item.type))}</span>
                    <span>${escapeHtml(formatDateTime(item.transferred_at))}</span>
                </div>
                <p>${escapeHtml(item.description)}</p>
                ${item.fallback_notice ? `<div class="transfer-queue-warning"><i class="fas fa-triangle-exclamation"></i> ${escapeHtml(item.fallback_notice)}</div>` : ''}
                <div class="transfer-queue-location"><i class="fas fa-map-marker-alt"></i> ${escapeHtml(item.location)}</div>
                <button type="button" class="transfer-queue-action" data-transfer-action="${actionName}" data-transfer-key="${escapeHtml(item.queue_key)}">
                    <i class="fas ${actionIcon}"></i> ${actionText}
                </button>
            </article>
        `;
    }

    function transferSourceIconSvg(isLiveCall) {
        if (isLiveCall) {
            return '<svg class="transfer-source-glyph" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.79 19.79 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.69 2.8a2 2 0 0 1-.45 2.11L8.08 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.91.33 1.85.56 2.81.69A2 2 0 0 1 22 16.92Z"/></svg>';
        }

        return '<svg class="transfer-source-glyph" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" focusable="false" aria-hidden="true"><path d="M6 3h8l4 4v14H6Z"/><path d="M14 3v5h5"/><path d="M9 13h6"/><path d="M9 17h6"/></svg>';
    }

    function findTransferredQueueItem(key) {
        return transferQueueItems.find((item) => item.queue_key === String(key));
    }

    function removeTransferredQueueItemForIncomingCall(call) {
        const keys = new Set([
            call && call.transferId,
            call && call.transfer_id,
            call && call.callId,
            call && call.call_id,
            call && call.call_id_external,
            call && call.incidentId,
            call && call.incident_id,
            call && call.room,
            call && call.referenceNo,
            call && call.reference_no
        ].map((value) => String(value || '').trim()).filter(Boolean));
        if (!keys.size) return;
        markTransferQueueKeysDismissed(Array.from(keys));
        const before = transferQueueItems.length;
        transferQueueItems = transferQueueItems.filter((item) => {
            return ![
                item.queue_key,
                item.transfer_id,
                item.call_id_external,
                item.incident_id,
                item.room
            ].some((value) => keys.has(String(value || '').trim()));
        });
        incomingCallQueue = incomingCallQueue.filter((item) => !keys.has(incomingCallKey(item)));
        if (transferQueueItems.length !== before) {
            renderTransferredQueue();
        }
    }

    function dismissTransferredQueueItemForIncomingCall(call) {
        removeTransferredQueueItemForIncomingCall(call || {});
    }

    function incomingCallDetailFromTransfer(item) {
        return {
            is_transfer: true,
            name: item.caller_name || 'Transferred Caller',
            phone: item.caller_phone || '',
            start: Date.parse(item.transferred_at || '') || Date.now(),
            transfer_id: item.transfer_id || '',
            call_id_external: item.call_id_external || item.transfer_id || '',
            conversation_id: item.conversation_id || '',
            room: item.room || '',
            transfer_type: 'live_call',
            socket_url: item.socket_url || ALERTARA_SOCKET_URL,
            socket_path: item.socket_path || ALERTARA_SOCKET_PATH,
            source_system: item.source_system || 'AlertaraQC Emergency Communication',
            incident_id: item.incident_id || null,
            reference_no: item.reference_no || '',
            incident_status: item.incident_status || '',
            type: item.type || '',
            priority: item.priority || '',
            location: item.location || '',
            latitude: item.latitude ?? null,
            longitude: item.longitude ?? null,
            description: item.description || ''
        };
    }

    async function showNextTransferredQueueCall() {
        if (activeCall || pendingIncomingCall || !transferQueueItems.length) return false;
        const liveQueueItems = transferQueueItems
            .filter((item) => item && item.transfer_type === 'live_call' && !isTransferQueueDismissed(item.queue_key))
            .sort(comparePriorityThenNewest);

        for (const item of liveQueueItems) {
            const resolvedCall = await resolveOnlineTransferredCall(item);
            applyResolvedLiveCallToQueueItem(item, resolvedCall);
            if (!String(item.room || '').trim()) {
                continue;
            }
            if (activeCall || pendingIncomingCall) return false;
            displayIncomingCallModal(normalizeIncomingCallDetail(incomingCallDetailFromTransfer(item)));
            return true;
        }

        if (liveQueueItems.length) {
            setTransferQueueStatus('Transferred calls are queued, but no online caller room is available yet.', 'error');
        }
        return false;
    }

    function resolveOnlineTransferredCall(item) {
        return new Promise((resolve) => {
            if (!transferInboxSocket || !transferInboxSocket.connected) {
                resolve(null);
                return;
            }
            transferInboxSocket.timeout(6000).emit('resolve-live-call', {
                callId: item.call_id_external || item.callId || '',
                transferId: item.transfer_id || '',
                conversationId: item.conversation_id || '',
                room: item.room || ''
            }, (error, response) => {
                if (error || !response || !response.ok || !response.call) {
                    resolve(null);
                    return;
                }
                resolve(response.call);
            });
        });
    }

    function applyResolvedLiveCallToQueueItem(item, resolvedCall) {
        if (!item || !resolvedCall || !resolvedCall.room) return false;
        item.call_id_external = resolvedCall.callId || item.call_id_external;
        item.transfer_id = item.transfer_id || resolvedCall.callId || '';
        item.room = resolvedCall.room || item.room;
        item.socket_url = resolvedCall.socketUrl || item.socket_url || ALERTARA_SOCKET_URL;
        item.socket_path = resolvedCall.socketPath || item.socket_path || ALERTARA_SOCKET_PATH;
        item.conversation_id = resolvedCall.conversationId || item.conversation_id;
        item.transfer_type = 'live_call';
        return true;
    }

    async function answerTransferredQueueItem(key) {
        const item = findTransferredQueueItem(key);
        if (!item) return;
        const resolvedCall = await resolveOnlineTransferredCall(item);
        applyResolvedLiveCallToQueueItem(item, resolvedCall);
        if (!String(item.room || '').trim()) {
            setTransferQueueStatus('This stored item has no online caller room. Ask the caller to start a new call.', 'error');
            return;
        }
        const activeRoom = String(activeTransferCall?.room || '').trim();
        const activeCallId = String(activeTransferCall?.callId || activeTransferCall?.transferId || '').trim();
        const itemCallId = String(item.call_id_external || item.transfer_id || '').trim();
        if (
            activeTransferCall
            && ((activeRoom && activeRoom === String(item.room || '').trim())
                || (activeCallId && itemCallId && activeCallId === itemCallId))
        ) {
            focusAcceptedCallForm();
            return;
        }
        displayIncomingCallModal(normalizeIncomingCallDetail(incomingCallDetailFromTransfer(item)));
        acceptCall();
    }

    async function openTransferredReportQueueItem(key) {
        const item = findTransferredQueueItem(key);
        if (!item) return;
        const resolvedCall = await resolveOnlineTransferredCall(item);
        if (applyResolvedLiveCallToQueueItem(item, resolvedCall)) {
            displayIncomingCallModal(normalizeIncomingCallDetail(incomingCallDetailFromTransfer(item)));
            acceptCall();
            return;
        }
        openTransferredReportForm(item);
    }

    function openTransferredReportForm(report) {
        if (!report) return;
        openCreateIncidentForm();
        applyIncomingCallToForm({
            isTransfer: true,
            name: report.caller_name || 'Transferred reporter',
            phone: report.caller_phone || '',
            transferId: report.transfer_id || '',
            incidentId: report.incident_id || null,
            incidentReferenceNo: report.reference_no || '',
            incidentStatus: report.incident_status || 'pending',
            incidentType: report.type || 'other',
            priority: report.priority || 'medium',
            location: report.location || '',
            latitude: report.latitude ?? null,
            longitude: report.longitude ?? null,
            description: report.description || '',
            sourceSystem: report.source_system || 'AlertaraQC Emergency Communication'
        });
        focusAcceptedCallForm();
    }

    function applyIncomingCallToForm(call) {
        if (!call) return;
        storeTransferFormContext(call);
        const nameEl = document.getElementById('callerName');
        const phoneEl = document.getElementById('callerPhone');
        const locationEl = document.getElementById('incidentLocation');
        const descriptionEl = document.getElementById('incidentDescription');
        const priorityEl = document.getElementById('incidentPriority');
        const statusEl = document.getElementById('status');
        const notesEl = document.getElementById('callNotes');
        if (nameEl && call.name) nameEl.value = call.name;
        if (phoneEl && call.phone) phoneEl.value = call.phone;
        if (locationEl) {
            if (call.location) {
                locationEl.value = call.location;
            }
            if (Number.isFinite(Number(call.latitude)) && Number.isFinite(Number(call.longitude))) {
                locationEl.dataset.lat = String(call.latitude);
                locationEl.dataset.lon = String(call.longitude);
            }
        }
        if (descriptionEl && call.description) descriptionEl.value = call.description;
        if (priorityEl && call.priority) {
            setPrioritySelection(call.priority);
        }
        if (statusEl && call.incidentStatus) {
            const status = String(call.incidentStatus).toLowerCase();
            if (Array.from(statusEl.options).some((option) => option.value === status)) {
                statusEl.value = status;
            }
        }
        if (notesEl && !notesEl.value && call.sourceSystem) {
            notesEl.value = 'Transferred from ' + call.sourceSystem;
        }
        if (call.incidentType) {
            setIncidentTypesFromTransfer(call.incidentType);
        }
    }

    function storeCallAuditContext(call) {
        const form = document.getElementById('incidentForm');
        if (!form || !call) return;
        const receivedAtMs = Number(call.start || 0);
        const acceptedAtMs = Number(call.acceptedAt || 0);
        const auditSessionId = ensureCallAuditSession(call);
        if (Number.isFinite(receivedAtMs) && receivedAtMs > 0) {
            form.dataset.callReceivedAtMs = String(receivedAtMs);
        }
        if (Number.isFinite(acceptedAtMs) && acceptedAtMs > 0) {
            form.dataset.callAcceptedAtMs = String(acceptedAtMs);
        }
        if (auditSessionId) {
            form.dataset.callAuditSessionId = auditSessionId;
        }
    }

    function clearCallAuditContext() {
        const form = document.getElementById('incidentForm');
        if (!form) return;
        delete form.dataset.callReceivedAtMs;
        delete form.dataset.callAcceptedAtMs;
        delete form.dataset.callAuditSessionId;
    }

    function storeTransferFormContext(call) {
        const form = document.getElementById('incidentForm');
        if (!form || !call || call.isTransfer !== true) return;
        const incidentId = call.incidentId || call.incident_id || '';
        form.dataset.transferIncidentId = String(incidentId || '');
        form.dataset.transferId = String(call.transferId || call.transfer_id || '');
        form.dataset.transferCallId = String(call.callId || call.call_id_external || call.call_id || '');
        form.dataset.transferReferenceNo = String(call.incidentReferenceNo || call.reference_no || '');
        form.dataset.transferSourceSystem = String(call.sourceSystem || call.source_system || '');
    }

    function clearTransferFormContext() {
        const form = document.getElementById('incidentForm');
        if (!form) return;
        delete form.dataset.transferIncidentId;
        delete form.dataset.transferId;
        delete form.dataset.transferCallId;
        delete form.dataset.transferReferenceNo;
        delete form.dataset.transferSourceSystem;
    }

    function transferSubmitContext(form) {
        const sharedSession = getSharedCallSession() || {};
        const session = sharedSession.isTransfer === true ? sharedSession : {};
        const call = activeTransferCall || {};
        const incidentId = Number(
            (form && form.dataset ? form.dataset.transferIncidentId : '')
            || call.incidentId
            || call.incident_id
            || session.incidentId
            || session.incident_id
        );
        return {
            incidentId: Number.isFinite(incidentId) && incidentId > 0 ? incidentId : 0,
            transferId: (form && form.dataset ? form.dataset.transferId : '') || call.transferId || call.transfer_id || session.transferId || '',
            callId: (form && form.dataset ? form.dataset.transferCallId : '') || call.callId || call.call_id_external || call.call_id || session.callId || '',
            referenceNo: (form && form.dataset ? form.dataset.transferReferenceNo : '') || call.incidentReferenceNo || call.reference_no || session.incidentReferenceNo || '',
            sourceSystem: (form && form.dataset ? form.dataset.transferSourceSystem : '') || call.sourceSystem || call.source_system || session.sourceSystem || ''
        };
    }

    function focusAcceptedCallForm() {
        const panel = document.getElementById('activeCallPanel');
        const typeTrigger = document.getElementById('incidentTypeTrigger');
        const locationEl = document.getElementById('incidentLocation');
        if (panel) {
            panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
        window.setTimeout(() => {
            const typeInput = document.getElementById('incidentType');
            if (typeTrigger && !(typeInput && typeInput.value)) {
                typeTrigger.focus();
                return;
            }
            if (locationEl) {
                locationEl.focus();
            }
        }, 180);
    }

    function setIncidentTypesFromTransfer(typeText) {
        const values = String(typeText || '').split(/[,|]+/).map((item) => {
            const normalized = item.trim().toLowerCase();
            return normalized === 'medical' ? 'ambulance' : normalized;
        }).filter(Boolean);
        if (!values.length) return;
        document.querySelectorAll('.incident-type-option input[type="checkbox"]').forEach((checkbox) => {
            checkbox.checked = values.includes(String(checkbox.value || '').toLowerCase());
            checkbox.closest('.incident-type-option')?.classList.toggle('active', checkbox.checked);
        });
        syncIncidentTypeHiddenInput();
    }

    function playIncomingTransferAlert() {
        try {
            const AudioContextApi = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextApi) return;
            alertAudioContext = alertAudioContext || new AudioContextApi();
            if (alertAudioContext.state === 'suspended') {
                alertAudioContext.resume().catch(() => {});
            }
            const now = alertAudioContext.currentTime;
            [0, 0.32, 0.64, 1.15, 1.47, 1.79].forEach((offset) => {
                const oscillator = alertAudioContext.createOscillator();
                const gain = alertAudioContext.createGain();
                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(920, now + offset);
                oscillator.frequency.setValueAtTime(680, now + offset + 0.12);
                gain.gain.setValueAtTime(0.0001, now + offset);
                gain.gain.exponentialRampToValueAtTime(0.32, now + offset + 0.02);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + offset + 0.22);
                oscillator.connect(gain);
                gain.connect(alertAudioContext.destination);
                oscillator.start(now + offset);
                oscillator.stop(now + offset + 0.24);
            });
        } catch (error) {
            console.warn('Transfer alert sound unavailable:', error);
        }
    }

    function connectTransferSocket(call) {
        disconnectTransferCall();
        if (!call || !call.isTransfer || !call.room) {
            return;
        }
        if (typeof window.io !== 'function') {
            setVoiceState('Socket.IO client is not loaded. The call cannot be answered on this page yet.');
            return;
        }
        try {
            const callId = String(call.callId || call.transferId || '');
            transferPeerConnection = createTransferPeerConnection(call);
            const requestedSocketUrl = String(call.socketUrl || ALERTARA_SOCKET_URL).replace(/\/$/, '');
            const inboxSocketUrl = String(ALERTARA_SOCKET_URL).replace(/\/$/, '');
            transferSocketUsesInbox = !!transferInboxSocket && requestedSocketUrl === inboxSocketUrl;
            transferSocketRoom = String(call.room || '');
            transferSocket = transferSocketUsesInbox
                ? transferInboxSocket
                : window.io(requestedSocketUrl, {
                    path: call.socketPath || ALERTARA_SOCKET_PATH,
                    transports: ['websocket', 'polling'],
                    query: { role: 'ers-response-team', room: call.room, callId }
                });
            const handleTransferSocketConnected = () => {
                const activeSocket = transferSocket;
                const joinCallerRoom = () => {
                    if (!activeSocket || transferSocket !== activeSocket || !activeSocket.connected) return;
                    activeSocket.emit('join', call.room, (response) => {
                        if (transferSocket !== activeSocket) return;
                        if (response && response.ok) {
                            setTransferCallChatStatus('Connected to caller room (' + String(response.members || 1) + ' participant(s))');
                            emitTransferAccepted(call);
                            scheduleTransferOfferRequest(call);
                            flushPendingTransferCallMessages();
                            setVoiceState('Emergency call accepted. Waiting for the caller audio offer.');
                            return;
                        }
                        setTransferCallChatStatus('Could not join the caller room. Retrying...');
                        window.setTimeout(joinCallerRoom, 600);
                    });
                };
                joinCallerRoom();
            };
            bindTransferCallSocketHandler('connect', handleTransferSocketConnected);
            bindTransferCallSocketHandler('offer', async (offerPayload) => {
                if (!transferPayloadMatchesCall(offerPayload, callId, call.room)) return;
                // The signaling server may replay the caller's original lobby
                // offer when ERS joins the private room. Request and answer a
                // fresh transfer offer so old ICE and the ERS media leg never
                // become mixed together.
                const isTransferOffer = offerPayload
                    && (offerPayload.transferred === true || offerPayload.target === 'ers');
                if (!isTransferOffer) {
                    transferSocket.emit(
                        'request-transfer-offer',
                        transferAcceptedPayload(call, 'ers-requires-fresh-media-offer'),
                        call.room
                    );
                    setVoiceState('Requesting a fresh caller audio connection...');
                    return;
                }
                clearTransferOfferRequestTimer();
                try {
                    await answerTransferOffer(call, offerPayload);
                } catch (error) {
                    console.warn('Unable to answer transfer offer:', error);
                    setVoiceState('Could not connect the live caller audio.');
                }
            });
            bindTransferCallSocketHandler('candidate', async (data) => {
                if (!transferPayloadMatchesCall(data, callId, call.room)) return;
                // Ignore ICE from the original Emergency-Com/admin discovery
                // peer. ERS must only add candidates belonging to the fresh
                // mobile-to-ERS transfer offer answered above.
                const isTransferCandidate = data
                    && (data.transferred === true || data.target === 'ers');
                if (!isTransferCandidate) return;
                if (
                    transferNegotiationId
                    && data.negotiationId
                    && String(data.negotiationId) !== transferNegotiationId
                ) return;
                if (data && data.candidate && transferPeerConnection) {
                    addTransferIceCandidate(data.candidate);
                }
            });
            bindTransferCallSocketHandler('call-message', (payload) => {
                if (!transferPayloadMatchesCall(payload, callId, call.room)) return;
                if (!payload || payload.sender === 'response_team') return;
                addTransferCallMessage(
                    payload.text || payload.message || '',
                    'caller',
                    payload.timestamp,
                    payload.senderName || call.name || 'Caller',
                    payload.messageId || ''
                );
            });
            bindTransferCallSocketHandler('call-message-history', (history) => {
                if (!Array.isArray(history)) return;
                history.forEach((payload) => {
                    if (!transferPayloadMatchesCall(payload, callId, call.room)) return;
                    if (!payload || payload.sender === 'response_team') return;
                    addTransferCallMessage(
                        payload.text || payload.message || '',
                        'caller',
                        payload.timestamp || payload.serverTimestamp,
                        payload.senderName || call.name || 'Caller',
                        payload.messageId || ''
                    );
                });
            });
            const handleTransferHangup = (payload) => {
                if (!transferPayloadMatchesCall(payload, callId, call.room)) return;
                closeTransferVoiceSession({ notifyPeer: false, reason: 'caller-ended', keepForm: true });
            };
            bindTransferCallSocketHandler('hangup', handleTransferHangup);
            bindTransferCallSocketHandler('call-ended', handleTransferHangup);
            bindTransferCallSocketHandler('call_ended', handleTransferHangup);
            bindTransferCallSocketHandler('disconnect', () => {
                if (endingTransferCall) return;
                setVoiceState('AlertaraQC transfer socket disconnected.');
            });
            bindTransferCallSocketHandler('connect_error', (error) => {
                console.warn('Transfer socket connection failed:', error);
                setVoiceState('Could not reach the AlertaraQC live call socket.');
            });
            if (transferSocket.connected) {
                handleTransferSocketConnected();
            } else if (typeof transferSocket.connect === 'function') {
                // The active call must never remain tied to an idle inbox
                // socket. Explicitly resume its public production connection.
                setTransferCallChatStatus('Connecting to caller room...');
                transferSocket.connect();
            }
        } catch (error) {
            console.warn('Unable to connect transfer socket:', error);
            setVoiceState('Unable to connect to the transferred live call.');
        }
    }

    function createTransferPeerConnection(call) {
        let pc;
        try {
            pc = new RTCPeerConnection({ iceServers: TRANSFER_ICE_SERVERS });
        } catch (error) {
            // Never let a bad deployment-time TURN value stop the ERS from
            // joining its Socket.IO call room. STUN keeps signaling, chat and
            // both-side hang-up available while TURN is corrected.
            console.warn('Invalid TURN configuration; continuing with STUN only.', error);
            pc = new RTCPeerConnection({ iceServers: TRANSFER_STUN_SERVERS });
        }
        pc.onicecandidate = (event) => {
            if (!event.candidate || !transferSocket) return;
            transferSocket.emit('candidate', {
                candidate: typeof event.candidate.toJSON === 'function'
                    ? event.candidate.toJSON()
                    : event.candidate,
                callId: call.callId || call.transferId || '',
                room: call.room,
                transferred: true,
                target: 'ers',
                negotiationId: transferNegotiationId
            }, call.room);
        };
        pc.ontrack = (event) => {
            if (event.track && event.track.kind === 'audio') {
                event.track.enabled = true;
            }
            const stream = event.streams && event.streams[0]
                ? event.streams[0]
                : (event.track ? new MediaStream([event.track]) : null);
            if (!stream) return;
            transferRemoteAudio = transferRemoteAudio || createTransferRemoteAudio();
            transferRemoteAudio.srcObject = stream;
            transferRemoteAudio.muted = false;
            transferRemoteAudio.volume = 1;
            playTransferRemoteAudio();
        };
        pc.onconnectionstatechange = () => {
            if (!transferPeerConnection) return;
            if (transferPeerConnection.connectionState === 'connected') {
                clearTransferOfferRequestTimer();
            }
            setVoiceState('AlertaraQC voice connection: ' + transferPeerConnection.connectionState + '.');
        };
        return pc;
    }

    function clearTransferOfferRequestTimer() {
        if (transferOfferRequestTimer) {
            window.clearTimeout(transferOfferRequestTimer);
            transferOfferRequestTimer = null;
        }
    }

    function transferAcceptedPayload(call, reason = 'response-team-ready') {
        return {
            callId: call.callId || call.transferId || '',
            call_id: call.callId || call.transferId || '',
            transferId: call.transferId || '',
            transfer_id: call.transferId || '',
            conversationId: call.conversationId || '',
            conversation_id: call.conversationId || '',
            room: call.room,
            role: 'dispatcher',
            reason
        };
    }

    function scheduleTransferOfferRequest(call) {
        clearTransferOfferRequestTimer();
        if (!transferSocket || !call || !call.room) return;
        transferOfferRequestTimer = window.setTimeout(() => {
            if (!transferSocket || !call.room || !transferPeerConnection) return;
            if (transferPeerConnection.connectionState === 'connected') return;
            if (transferPeerConnection.remoteDescription) return;
            transferSocket.emit('request-transfer-offer', transferAcceptedPayload(call, 'response-team-offer-timeout'), call.room);
            setVoiceState('Requesting fresh caller audio connection...');
        }, 700);
    }

    async function addTransferIceCandidate(candidate) {
        if (!transferPeerConnection || !candidate) return;
        if (!transferPeerConnection.remoteDescription) {
            pendingTransferIceCandidates.push(candidate);
            return;
        }
        try {
            await transferPeerConnection.addIceCandidate(candidate);
        } catch (error) {
            console.warn('Unable to add transfer ICE candidate:', error);
        }
    }

    async function flushPendingTransferIceCandidates() {
        if (!transferPeerConnection || !transferPeerConnection.remoteDescription || !pendingTransferIceCandidates.length) return;
        const queued = pendingTransferIceCandidates.splice(0);
        for (const candidate of queued) {
            await addTransferIceCandidate(candidate);
        }
    }

    function createTransferRemoteAudio() {
        const audio = document.createElement('audio');
        audio.autoplay = true;
        audio.playsInline = true;
        audio.setAttribute('playsinline', '');
        audio.hidden = true;
        document.body.appendChild(audio);
        return audio;
    }

    function playTransferRemoteAudio() {
        if (!transferRemoteAudio || !transferRemoteAudio.srcObject) return;
        transferRemoteAudio.muted = false;
        transferRemoteAudio.volume = 1;
        transferRemoteAudio.play()
            .then(() => setVoiceState('Caller audio connected. Your microphone is sending.'))
            .catch(() => setVoiceState('Caller audio is ready. Tap anywhere once to enable sound.'));
    }

    // Browsers can block remote audio if the WebRTC track arrives after the
    // Answer button's user-activation window. Any later tap safely retries it.
    document.addEventListener('pointerdown', playTransferRemoteAudio, { passive: true });

    function setTransferCallChatStatus(text) {
        const el = document.getElementById('transferCallChatStatus');
        if (el) el.textContent = text;
    }

    function resetTransferCallChat(call = null) {
        transferCallMessages = [];
        transferCallMessageIds = new Set();
        pendingTransferCallMessages = new Map();
        const messages = document.getElementById('transferCallMessages');
        const input = document.getElementById('transferCallMessageInput');
        if (messages) {
            messages.innerHTML = '<div class="call-chat-empty">Messages between dispatcher and caller will appear here.</div>';
        }
        if (input) input.value = '';
        setTransferCallChatStatus(call && call.room ? 'Connected to room' : 'Waiting for call');
    }

    function addTransferCallMessage(text, sender = 'caller', timestamp = Date.now(), senderName = '', messageId = '') {
        const cleanText = String(text || '').trim();
        if (!cleanText) return;
        const stableMessageId = String(messageId || '').trim();
        if (stableMessageId && transferCallMessageIds.has(stableMessageId)) return;
        if (stableMessageId) transferCallMessageIds.add(stableMessageId);
        const messages = document.getElementById('transferCallMessages');
        if (!messages) return;
        if (!transferCallMessages.length) messages.innerHTML = '';
        const time = new Date(timestamp || Date.now()).toLocaleTimeString('en-US', {
            hour: 'numeric',
            minute: '2-digit',
            hour12: true
        });
        const item = document.createElement('div');
        item.className = 'call-chat-bubble ' + (sender === 'dispatcher' ? 'is-dispatcher' : 'is-caller');
        item.innerHTML = `
            <div class="call-chat-meta">${escapeHtml(senderName || (sender === 'dispatcher' ? 'Response Team' : 'Caller'))} &bull; ${escapeHtml(time)}</div>
            <div>${escapeHtml(cleanText)}</div>
        `;
        messages.appendChild(item);
        messages.scrollTop = messages.scrollHeight;
        transferCallMessages.push({ text: cleanText, sender, timestamp, senderName, messageId: stableMessageId });
    }

    function flushPendingTransferCallMessages() {
        const call = activeTransferCall || getSharedCallSession();
        if (!transferSocket || !transferSocket.connected || !call || !call.room) return false;
        pendingTransferCallMessages.forEach((payload, messageId) => {
            if (!payload || String(payload.room || '') !== String(call.room)) return;
            transferSocket.timeout(8000).emit('call-message', payload, call.room, (error, response) => {
                if (!error && response && response.ok) {
                    pendingTransferCallMessages.delete(messageId);
                    setTransferCallChatStatus(response.recipients > 0 ? 'Delivered' : 'Queued until caller reconnects');
                } else {
                    setTransferCallChatStatus('Message queued while the call socket reconnects');
                }
            });
        });
        return true;
    }

    function sendTransferCallMessage() {
        const input = document.getElementById('transferCallMessageInput');
        const text = String(input && input.value ? input.value : '').trim();
        const call = activeTransferCall || getSharedCallSession();
        if (!text || !call || !call.room) return;
        if (input) input.value = '';
        const payload = {
            text,
            callId: call.callId || call.transferId || '',
            call_id: call.callId || call.transferId || '',
            transferId: call.transferId || '',
            transfer_id: call.transferId || '',
            room: call.room,
            sender: 'response_team',
            senderName: 'Response Team',
            messageId: 'ers-' + String(call.callId || call.transferId || '') + '-' + String(Date.now()) + '-' + Math.random().toString(16).slice(2),
            timestamp: Date.now()
        };
        addTransferCallMessage(text, 'dispatcher', payload.timestamp, payload.senderName, payload.messageId);
        pendingTransferCallMessages.set(payload.messageId, payload);
        if (!flushPendingTransferCallMessages()) {
            if (transferSocket && typeof transferSocket.connect === 'function') transferSocket.connect();
            setTransferCallChatStatus('Message queued while the call socket reconnects');
        }
    }

    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' || event.shiftKey) return;
        const target = event.target;
        if (target && target.id === 'transferCallMessageInput') {
            event.preventDefault();
            sendTransferCallMessage();
        }
    });

    function transferPayloadMatchesCall(payload, callId, room) {
        const payloadRoom = String((payload && payload.room) || '');
        if (payloadRoom && room && payloadRoom === String(room)) {
            return true;
        }
        if (!callId) return true;
        const payloadCallId = String(
            (payload && (payload.callId || payload.call_id || payload.transferId || payload.transfer_id))
            || ''
        );
        if (payloadCallId) {
            return payloadCallId === String(callId);
        }
        return !payloadRoom;
    }

    async function prepareTransferLocalAudio(call) {
        if (!call || !call.isTransfer || transferLocalStream) return transferLocalStream;
        if (transferLocalStreamPromise) return transferLocalStreamPromise;
        if (!navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
            setVoiceState('Microphone access is not available in this browser.');
            return null;
        }
        transferLocalStreamPromise = navigator.mediaDevices.getUserMedia({
            audio: {
                echoCancellation: true,
                noiseSuppression: true,
                autoGainControl: true
            }
        }).then((stream) => {
            transferLocalStream = stream;
            setVoiceState('Microphone connected. Waiting for caller audio.');
            if (transferPeerConnection) {
                addLocalAudioTracksToPeerConnection();
            }
            return transferLocalStream;
        }).catch((error) => {
            console.warn('Transfer microphone unavailable:', error);
            setVoiceState('Microphone permission is required so the caller can hear you.');
            return null;
        }).finally(() => {
            transferLocalStreamPromise = null;
        });
        return transferLocalStreamPromise;
    }

    function addLocalAudioTracksToPeerConnection() {
        if (!transferPeerConnection || !transferLocalStream) return;
        const existingTrackIds = transferPeerConnection.getSenders()
            .map((sender) => sender.track ? sender.track.id : '')
            .filter(Boolean);
        transferLocalStream.getAudioTracks().forEach((track) => {
            track.enabled = true;
            if (!existingTrackIds.includes(track.id)) {
                transferPeerConnection.addTrack(track, transferLocalStream);
            }
        });
    }

    function emitTransferAccepted(call) {
        if (!transferSocket || !call || !call.room) return;
        const payload = transferAcceptedPayload(call);
        ['dispatcher-ready', 'call-accepted', 'accepted'].forEach((eventName) => {
            transferSocket.emit(eventName, payload, call.room);
        });
    }

    async function answerTransferOffer(call, offerPayload) {
        if (!transferPeerConnection) {
            transferPeerConnection = createTransferPeerConnection(call);
        }
        if (transferPeerConnection.signalingState !== 'stable') {
            console.warn('Ignoring duplicate transfer offer while peer is', transferPeerConnection.signalingState);
            return;
        }
        const remoteDescription = typeof offerPayload.sdp === 'string'
            ? { type: 'offer', sdp: offerPayload.sdp }
            : offerPayload.sdp;
        transferNegotiationId = String(offerPayload.negotiationId || '');
        await transferPeerConnection.setRemoteDescription(remoteDescription);
        await flushPendingTransferIceCandidates();
        await prepareTransferLocalAudio(call);
        addLocalAudioTracksToPeerConnection();
        const answer = await transferPeerConnection.createAnswer();
        await transferPeerConnection.setLocalDescription(answer);
        transferSocket.emit('answer', {
            sdp: answer,
            type: answer.type,
            callId: call.callId || call.transferId || '',
            call_id: call.callId || call.transferId || '',
            transferId: call.transferId || '',
            transfer_id: call.transferId || '',
            room: call.room,
            transferred: true,
            target: 'ers',
            negotiationId: transferNegotiationId
        }, call.room);
        setVoiceState('Answered AlertaraQC live call. Two-way audio is connecting.');
    }

    function disconnectTransferCall() {
        clearTransferOfferRequestTimer();
        pendingTransferIceCandidates = [];
        transferNegotiationId = '';
        const socketToRelease = transferSocket;
        const sharedInboxSocket = transferSocketUsesInbox;
        const joinedRoom = transferSocketRoom;
        clearTransferCallSocketHandlers(socketToRelease);
        if (socketToRelease && sharedInboxSocket) {
            if (joinedRoom && socketToRelease.connected) {
                socketToRelease.emit('leave', joinedRoom);
            }
        } else if (socketToRelease && typeof socketToRelease.disconnect === 'function') {
            socketToRelease.disconnect();
        }
        transferSocket = null;
        transferSocketUsesInbox = false;
        transferSocketRoom = '';
        transferLocalStreamPromise = null;
        if (transferPeerConnection) {
            transferPeerConnection.close();
        }
        transferPeerConnection = null;
        if (transferLocalStream) {
            transferLocalStream.getTracks().forEach((track) => track.stop());
        }
        transferLocalStream = null;
        if (transferRemoteAudio) {
            transferRemoteAudio.srcObject = null;
            transferRemoteAudio.remove();
        }
        transferRemoteAudio = null;
    }

    function transferScalar(value) {
        if (value === null || value === undefined || typeof value === 'object') return '';
        return String(value).trim();
    }

    function pickTransferScalar(...values) {
        for (const value of values) {
            const scalar = transferScalar(value);
            if (scalar !== '') return scalar;
        }
        return '';
    }

    function pickTransferObject(...values) {
        for (const value of values) {
            if (value && typeof value === 'object' && !Array.isArray(value)) return value;
        }
        return {};
    }

    function normalizeRealtimeTransferPayload(payload) {
        const raw = payload && typeof payload === 'object' ? payload : {};
        const transferEnvelope = raw.transfer && typeof raw.transfer === 'object' ? raw.transfer : {};
        const transfer = transferEnvelope.data && typeof transferEnvelope.data === 'object'
            ? transferEnvelope.data
            : transferEnvelope;
        const caller = pickTransferObject(transfer.caller, raw.caller);
        const locationObj = pickTransferObject(transfer.locationData, transfer.location, raw.locationData, raw.location);
        const priorityObj = pickTransferObject(transfer.incidentPriority, raw.incidentPriority);
        const explicitTransferType = pickTransferScalar(raw.transfer_type, raw.transferType, transfer.transfer_type, transfer.transferType).toLowerCase();
        const callId = pickTransferScalar(
            raw.callId,
            raw.call_id,
            transfer.callId,
            transfer.call_id,
            transfer.call_id_external
        );
        const room = pickTransferScalar(raw.room, transfer.room);
        const isLiveCall = explicitTransferType === 'live_call'
            || (explicitTransferType !== 'report' && room !== '' && callId !== '');
        let location = pickTransferScalar(
            transfer.location,
            raw.location,
            transfer.location_address,
            raw.location_address,
            locationObj.address,
            caller.address
        );
        if (!location && (locationObj.lat || locationObj.latitude) && (locationObj.lng || locationObj.longitude)) {
            location = pickTransferScalar(locationObj.lat, locationObj.latitude) + ', ' + pickTransferScalar(locationObj.lng, locationObj.longitude);
        }
        if (!location) location = 'Location pending from transferred call';
        const transferId = pickTransferScalar(
            raw.transferId,
            raw.transfer_id,
            transfer.transferId,
            transfer.transfer_id,
            callId,
            raw.conversationId,
            transfer.conversationId
        );
        return {
            transfer_log_id: 0,
            source_system: pickTransferScalar(raw.source_system, transfer.source_system) || 'AlertaraQC Emergency Communication',
            event: pickTransferScalar(raw.event, transfer.event) || (isLiveCall ? 'emergency_call_transfer' : 'emergency_report_transfer'),
            transfer_id: transferId,
            call_id_external: isLiveCall ? callId : '',
            conversation_id: pickTransferScalar(raw.conversationId, raw.conversation_id, transfer.conversationId, transfer.conversation_id),
            transfer_type: isLiveCall ? 'live_call' : 'report',
            room: isLiveCall ? room : '',
            socket_url: isLiveCall ? (pickTransferScalar(raw.socketUrl, raw.socket_url, transfer.socketUrl, transfer.socket_url) || ALERTARA_SOCKET_URL) : '',
            socket_path: isLiveCall ? (pickTransferScalar(raw.socketPath, raw.socket_path, transfer.socketPath, transfer.socket_path) || ALERTARA_SOCKET_PATH) : '',
            transport: 'websocket',
            call_id: 0,
            caller_name: pickTransferScalar(transfer.caller_name, raw.caller_name, caller.name) || 'Transferred Caller',
            caller_phone: pickTransferScalar(transfer.caller_phone, raw.caller_phone, caller.phone),
            incident_id: transfer.incident_id || null,
            reference_no: pickTransferScalar(transfer.reference_no, raw.reference_no),
            incident_status: pickTransferScalar(transfer.incident_status, raw.incident_status) || 'pending',
            type: pickTransferScalar(transfer.emergencyType, transfer.emergency_type, transfer.type, raw.emergencyType, raw.type) || 'emergency',
            priority: pickTransferScalar(transfer.priority, raw.priority, priorityObj.priority, priorityObj.level) || 'medium',
            location,
            latitude: locationObj.lat ?? locationObj.latitude ?? transfer.latitude ?? raw.latitude ?? null,
            longitude: locationObj.lng ?? locationObj.longitude ?? transfer.longitude ?? raw.longitude ?? null,
            description: pickTransferScalar(transfer.description, raw.description, transfer.latestMessage, raw.latestMessage) || (isLiveCall ? 'Incoming transferred live call from Emergency-Com.' : 'Incoming transferred report from Emergency-Com.'),
            transferred_at: pickTransferScalar(raw.transferredAt, raw.transferred_at, transfer.transferredAt, transfer.transferred_at) || new Date().toISOString(),
            fallback_notice: isLiveCall && !room ? 'Emergency-Com sent a live transfer notice without a room, so audio cannot connect until the transfer is retried with room data.' : ''
        };
    }

    function showRealtimeTransferNotice(payload) {
        const transfer = normalizeRealtimeTransferPayload(payload);
        const key = transferQueueKey(transfer) || ('live-' + Date.now());
        if (isTransferQueueDismissed(key)) return;
        if (liveIncomingTransferKeys.has(key)) return;
        liveIncomingTransferKeys.add(key);
        notifiedIncomingTransferKeys.add(key);
        const item = normalizeTransferQueueItem(transfer) || transfer;
        upsertTransferredQueueItem(item);
        if (transferLooksLikeCall(item)) {
            document.dispatchEvent(new CustomEvent('ers:incoming-call', {
                detail: incomingCallDetailFromTransfer(item)
            }));
            return;
        }
        showIncomingReportNotification(item);
    }

    function startTransferInboxSocket() {
        if (transferInboxSocket) {
            if (!transferInboxSocket.connected && typeof transferInboxSocket.connect === 'function') {
                transferInboxSocket.connect();
            }
            return;
        }
        if (typeof window.io !== 'function') {
            setTransferQueueStatus('Live socket client is loading. Backup feed polling is available.', 'active');
            if (typeof window.__loadSocketIoFallback === 'function') {
                window.__loadSocketIoFallback();
            }
            if (transferInboxSocketRetryCount < 8) {
                if (transferInboxSocketRetryTimer) window.clearTimeout(transferInboxSocketRetryTimer);
                transferInboxSocketRetryTimer = window.setTimeout(() => {
                    transferInboxSocketRetryCount += 1;
                    startTransferInboxSocket();
                }, 600);
            }
            return;
        }
        try {
            transferInboxSocket = window.io(ALERTARA_SOCKET_URL, {
                path: ALERTARA_SOCKET_PATH,
                transports: ['websocket', 'polling'],
                query: { role: 'ers-dispatcher', inbox: TRANSFER_INBOX_ROOM }
            });
            transferInboxSocket.on('connect', () => {
                transferInboxSocketRetryCount = 0;
                transferInboxSocket.emit('join', TRANSFER_INBOX_ROOM);
                setTransferQueueStatus('Live transfer socket connected. Waiting for transferred calls and reports...', 'active');
            });
            transferInboxSocket.on('disconnect', () => {
                setTransferQueueStatus('Live transfer socket reconnecting...', 'active');
            });
            transferInboxSocket.on('incoming-transfer', showRealtimeTransferNotice);
            transferInboxSocket.on('ers-transfer-notify', showRealtimeTransferNotice);
            transferInboxSocket.on('connect_error', (error) => {
                console.warn('ERS transfer inbox socket failed:', error);
                setTransferQueueStatus('Live socket not reachable. Backup feed polling is still running.', 'error');
            });
        } catch (error) {
            console.warn('Unable to start ERS transfer inbox socket:', error);
            setTransferQueueStatus('Live socket could not start. Backup feed polling is still running.', 'error');
        }
    }

    function startIncomingTransferPolling() {
        setTransferQueueStatus('Listening for transferred calls and reports...', 'active');
        pollIncomingTransfers(true, true);
        if (incomingTransferPollTimer) {
            window.clearInterval(incomingTransferPollTimer);
        }
        incomingTransferPollTimer = window.setInterval(() => pollIncomingTransfers(false, false), INCOMING_TRANSFER_POLL_MS);
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                pollIncomingTransfers(false, true);
            }
        });
        window.addEventListener('focus', () => pollIncomingTransfers(false, true));
    }

    async function pollIncomingTransfers(loadLatest = false, force = false) {
        if (!force && !loadLatest && (document.hidden || (transferInboxSocket && transferInboxSocket.connected))) return;
        if (incomingTransferPollInFlight) return;
        incomingTransferPollInFlight = true;
        try {
            const url = loadLatest
                ? API_INCOMING_TRANSFERS_URL + '?latest=1&limit=25'
                : API_INCOMING_TRANSFERS_URL + '?after_id=' + encodeURIComponent(String(latestTransferLogId)) + '&limit=25';
            const response = await fetch(url, { cache: 'no-store' });
            const data = await response.json();
            if (!data || !data.ok || !Array.isArray(data.transfers)) {
                setTransferQueueStatus('Transfer feed returned an invalid response.', 'error');
                return;
            }
            const transfers = data.transfers.slice().sort((a, b) =>
                Number(a.transfer_log_id || 0) - Number(b.transfer_log_id || 0)
            );
            if (loadLatest) {
                transfers.forEach((transfer) => {
                    const transferLogId = Number(transfer.transfer_log_id || 0);
                    const transferKey = transferQueueKey(transfer) || String(transferLogId || '');
                    if (transferLogId > 0) {
                        shownIncomingTransferIds.add(transferLogId);
                        latestTransferLogId = Math.max(latestTransferLogId, transferLogId);
                    }
                    if (transferKey) {
                        notifiedIncomingTransferKeys.add(transferKey);
                    }
                    if (isTransferQueueDismissed(transferKey)) {
                        return;
                    }
                    if (!(transfer.call_id_external || transfer.transfer_id)) {
                        return;
                    }
                    const incidentStatus = String(transfer.incident_status || '').toLowerCase();
                    if (['resolved', 'cancelled', 'closed', 'rejected'].includes(incidentStatus)) {
                        return;
                    }
                    upsertTransferredQueueItem(transfer);
                });
                incomingTransferBaselineReady = true;
                window.localStorage.setItem('ersLatestTransferLogId', String(latestTransferLogId));
                if (!transferQueueItems.length) {
                    renderTransferredQueue();
                }
                return;
            }
            transfers.forEach((transfer) => {
                const transferLogId = Number(transfer.transfer_log_id || 0);
                const transferKey = transferQueueKey(transfer) || String(transferLogId || '');
                if (transferLogId > 0 && shownIncomingTransferIds.has(transferLogId)) {
                    return;
                }
                if (transferKey && notifiedIncomingTransferKeys.has(transferKey)) {
                    return;
                }
                if (transferLogId > 0) {
                    shownIncomingTransferIds.add(transferLogId);
                    latestTransferLogId = Math.max(latestTransferLogId, transferLogId);
                }
                if (transferKey) {
                    notifiedIncomingTransferKeys.add(transferKey);
                }
                if (isTransferQueueDismissed(transferKey)) {
                    return;
                }
                if (!(transfer.call_id_external || transfer.transfer_id)) {
                    return;
                }
                const incidentStatus = String(transfer.incident_status || '').toLowerCase();
                if (['resolved', 'cancelled', 'closed', 'rejected'].includes(incidentStatus)) {
                    return;
                }
                upsertTransferredQueueItem(transfer);
                if (!transferLooksLikeCall(transfer)) {
                    showIncomingReportNotification(transfer);
                    return;
                }
                document.dispatchEvent(new CustomEvent('ers:incoming-call', {
                    detail: {
                        is_transfer: true,
                        name: transfer.caller_name || 'Transferred Caller',
                        phone: transfer.caller_phone || '',
                        start: Date.parse(transfer.transferred_at || '') || Date.now(),
                        transfer_id: transfer.transfer_id || '',
                        call_id_external: transfer.call_id_external || transfer.transfer_id || '',
                        conversation_id: transfer.conversation_id || '',
                        room: transfer.room || '',
                        transfer_type: 'live_call',
                        socket_url: transfer.socket_url || ALERTARA_SOCKET_URL,
                        socket_path: transfer.socket_path || ALERTARA_SOCKET_PATH,
                        source_system: transfer.source_system || 'AlertaraQC Emergency Communication',
                        incident_id: transfer.incident_id || null,
                        reference_no: transfer.reference_no || '',
                        incident_status: transfer.incident_status || '',
                        type: transfer.type || '',
                        priority: transfer.priority || '',
                        location: transfer.location || '',
                        latitude: transfer.latitude ?? null,
                        longitude: transfer.longitude ?? null,
                        description: transfer.description || ''
                    }
                }));
            });
            incomingTransferBaselineReady = true;
            window.localStorage.setItem('ersLatestTransferLogId', String(latestTransferLogId));
            if (!transferQueueItems.length) {
                renderTransferredQueue();
            }
        } catch (error) {
            console.warn('Incoming transfer polling failed:', error);
            setTransferQueueStatus('Transfer feed is not reachable. Check ../api/incoming_transfers.php.', 'error');
        } finally {
            incomingTransferPollInFlight = false;
        }
    }

    function setTransferQueueStatus(message, state = '') {
        const status = document.getElementById('transferQueueStatus');
        if (!status) return;
        status.textContent = message;
        status.classList.toggle('is-active', state === 'active');
        status.classList.toggle('is-error', state === 'error');
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
        const normalized = normalizePriority(value, 'medium');
        const options = document.querySelectorAll('#prioritySelect .priority-option');
        let applied = false;
        let selectedValue = normalized;
        options.forEach(o => {
            if (normalizePriority(o.dataset.value, '') === normalized) {
                o.classList.add('active');
                o.setAttribute('aria-checked', 'true');
                selectedValue = o.dataset.value || normalized;
                applied = true;
            } else {
                o.classList.remove('active');
                o.setAttribute('aria-checked', 'false');
            }
        });
        const priorityEl = document.getElementById('incidentPriority');
        if (priorityEl) {
            priorityEl.value = applied ? selectedValue : normalized;
        }
    }

    function initPrioritySelect() {
        const options = document.querySelectorAll('#prioritySelect .priority-option');
        options.forEach(opt => {
            opt.addEventListener('click', () => {
                priorityAuto = false; // user manually chose a priority
                setPrioritySelection(opt.dataset.value);
                setPrioritySuggestionMessage('Manual priority selected by dispatcher.', 'manual');
            });
        });
        setPrioritySelection(document.getElementById('incidentPriority')?.value || 'low');
    }

    function setPrioritySuggestionMessage(message, state = 'idle') {
        const suggestionEl = document.getElementById('priorityAiSuggestion');
        if (!suggestionEl) return;
        suggestionEl.textContent = message || '';
        suggestionEl.dataset.state = state;
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

        const criticalHigh = [
            'unconscious', 'non-responsive', 'not breathing', 'difficulty breathing', 'chest pain', 'severe bleeding',
            'gunshot', 'shot', 'stab', 'stabbing', 'weapon', 'armed', 'fire', 'explosion', 'blast', 'earthquake', 'flood', 'collapsed',
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

        const low = [
            'minor', 'bahagya', 'walang sugat', 'hindi seryoso', 'okay na', 'stable', 'stable na', 'mild'
        ];

        const countHits = (keywords) => keywords.reduce((total, keyword) => total + (text.includes(keyword) ? 1 : 0), 0);
        const criticalHits = countHits(criticalHigh);
        const mediumHits = countHits(medium);
        const lowHits = countHits(low);

        if (criticalHits > 0) {
            // Immediate life threat, armed, fire, explosion, or major hazard -> Critical (80-100 pts)
            return 'critical';
        }
        if (mediumHits > 0) {
            return 'medium';
        }
        if (lowHits > 0) {
            return 'low';
        }
        return 'low';
    }

    function buildAiPriorityPayload(text) {
        return {
            description: text,
            location: document.getElementById('incidentLocation')?.value.trim() || '',
            call_notes: document.getElementById('callNotes')?.value.trim() || '',
            incident_types: getSelectedIncidentTypes()
        };
    }

    function applyLocalPrioritySuggestion(text, reason = '') {
        const suggested = suggestPriorityFromDescription(text);
        if (priorityAuto) {
            setPrioritySelection(suggested);
        }
        if (reason) {
            setPrioritySuggestionMessage(`Suggested ${suggested.toUpperCase()}: ${reason}`, 'fallback');
        }
    }

    async function updatePrioritySuggestion(desc) {
        const text = (desc || '').trim();
        if (!text || text.length < 3) {
            setPrioritySuggestionMessage('AI priority suggestion will appear after the incident details are entered.', 'idle');
            return;
        }

        const requestSeq = ++prioritySuggestRequestSeq;
        const suggested = suggestPriorityFromDescription(text);
        if (priorityAuto) {
            setPrioritySelection(suggested);
        }
        setPrioritySuggestionMessage(`Assessing priority... Current recommendation: ${suggested.toUpperCase()}.`, 'loading');

        try {
            const res = await fetch(API_AI_PRIORITY_URL, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(buildAiPriorityPayload(text))
            });
            const data = await res.json().catch(() => null);
            if (requestSeq !== prioritySuggestRequestSeq) return;

            if (!res.ok || !data || data.ok !== true) {
                applyLocalPrioritySuggestion(text, 'Local safety rules based on the incident details.');
                return;
            }

            const aiSuggested = normalizePriority(data.priority, suggested);
            if (priorityAuto) {
                setPrioritySelection(aiSuggested);
            }

            const isLocalSuggestion = data.source === 'local_rules';
            let message = `${isLocalSuggestion ? 'Suggested' : 'Gemini suggests'} ${aiSuggested.toUpperCase()}: ${data.reason || 'based on the incident details.'}`;
            if (Array.isArray(data.needs_more_info) && data.needs_more_info.length) {
                message += ` Ask: ${data.needs_more_info.join(' ')}`;
            }
            setPrioritySuggestionMessage(message, 'ai');
        } catch (e) {
            if (requestSeq !== prioritySuggestRequestSeq) return;
            applyLocalPrioritySuggestion(text, 'Local safety rules based on the incident details.');
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

    async function geocodeOnce(query) {
        const params = new URLSearchParams({
            q: query,
            limit: '10'
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

        try {
            const candidates = await geocodeOnce(raw);
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
        const form = e.target;
        const submitButton = form ? form.querySelector('.submit-incident-btn') : null;
        if (submitButton && submitButton.disabled) {
            return;
        }
        const isManualEntry = document.getElementById('activeCallPanel')?.classList.contains('manual-create') === true;
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
        const priorityValue = document.getElementById('incidentPriority')?.value.trim() || '';
        const payload = {
            caller_name: document.getElementById('callerName').value.trim(),
            caller_phone: document.getElementById('callerPhone').value.trim(),
            type: selectedTypes,
            location: finalLocationText,
            description: document.getElementById('incidentDescription').value.trim(),
            priority: priorityValue,
            status: document.getElementById('status').value,
            // Persist provenance at creation time. Both live calls and manual
            // entries use the same endpoint, so reported_by_call_id alone is
            // not enough to classify them later in Dispatch Center.
            intake_source: isManualEntry ? 'manual' : 'call'
        };
        const callContext = isManualEntry
            ? {}
            : (activeTransferCall || getSharedCallSession() || activeCall || {});
        const receivedAtMs = isManualEntry
            ? 0
            : Number(callContext.start || form?.dataset?.callReceivedAtMs || 0);
        const acceptedAtMs = isManualEntry
            ? 0
            : Number(callContext.acceptedAt || form?.dataset?.callAcceptedAtMs || 0);
        if (Number.isFinite(receivedAtMs) && receivedAtMs > 0) {
            payload.received_at = new Date(receivedAtMs).toISOString();
        }
        if (Number.isFinite(acceptedAtMs) && acceptedAtMs > 0) {
            payload.accepted_at = new Date(acceptedAtMs).toISOString();
        }
        const auditSessionId = isManualEntry
            ? ''
            : String(callContext.auditSessionId || form?.dataset?.callAuditSessionId || '').trim();
        if (/^[A-Za-z0-9.:-]{8,96}$/.test(auditSessionId)) {
            payload.audit_session_id = auditSessionId;
        }

        const transferContext = isManualEntry
            ? { incidentId: 0, transferId: '', callId: '', referenceNo: '', sourceSystem: '' }
            : transferSubmitContext(form);
        if (transferContext.incidentId > 0 || transferContext.transferId || transferContext.callId || transferContext.referenceNo) {
            payload.intake_source = 'interagency';
            if (transferContext.incidentId > 0) {
                payload.transfer_incident_id = transferContext.incidentId;
            }
            payload.transfer_id = transferContext.transferId;
            payload.transfer_call_id = transferContext.callId;
            payload.transfer_reference_no = transferContext.referenceNo;
            payload.transfer_source_system = transferContext.sourceSystem;
        }
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
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Logging...';
            }
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
            const wasTransferredCall = !!activeTransferCall;
            if (transferContext.incidentId > 0 || transferContext.transferId || transferContext.callId || transferContext.referenceNo) {
                dismissTransferredQueueItemForIncomingCall({
                    incidentId: transferContext.incidentId,
                    transferId: transferContext.transferId,
                    callId: transferContext.callId,
                    referenceNo: transferContext.referenceNo
                });
            }
            broadcastLoggedIncident(data);
            showToast(wasTransferredCall ? 'Transferred incident logged successfully.' : 'Incident logged successfully.');
            e.target.reset();
            resetIncidentTypeChecklist();
            setPrioritySelection('low');
            setPrioritySuggestionMessage('AI priority suggestion will appear after the incident details are entered.', 'idle');
            const locationInput = document.getElementById('incidentLocation');
            if (locationInput) {
                delete locationInput.dataset.lat;
                delete locationInput.dataset.lon;
            }
            clearTransferFormContext();
            clearCallAuditContext();
            priorityAuto = true;
            await loadIncidentsFromServer();
            redirectToDispatchCenter(data);
        } catch (err) {
            console.warn('Submit failed:', err);
            alert('Error while logging incident.');
        } finally {
            if (submitButton) {
                submitButton.disabled = false;
                submitButton.innerHTML = '<i class="fas fa-save"></i> Log Incident';
            }
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
            if (currentFilter !== 'all' && normalizePriority(i.priority, 'low') !== currentFilter) return false;
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
        }).sort((a, b) => {
            const byPriority = priorityRank(a.priority) - priorityRank(b.priority);
            if (byPriority !== 0) return byPriority;
            const byTime = parseQueueTime(b.updated_at || b.created_at || b.timestamp) - parseQueueTime(a.updated_at || a.created_at || a.timestamp);
            if (byTime !== 0) return byTime;
            return Number(b.id || 0) - Number(a.id || 0);
        });
    }

    function incidentCardHtml(i) {
        const priorityClass = normalizePriority(i.priority, 'low');
        const created = new Date(i.created_at || Date.now());
        const code = i.incident_code || '';
        const id = Number(i.id) || 0;
        return `
            <div class="incident-card" role="button" tabindex="0" onclick="openIncidentById(${id})" onkeydown="handleIncidentCardKey(event, ${id})">
                <div class="incident-header">
                    <div class="incident-id">${escapeHtml(code || ('#' + id))}</div>
                    <div class="incident-priority ${escapeHtml(priorityClass)}">${escapeHtml((priorityClass||'low').toUpperCase())}</div>
                </div>
                <div class="incident-type">${escapeHtml(labelForType(i.type))}</div>
                <div class="incident-location"><i class="fas fa-map-marker-alt"></i> ${escapeHtml(i.location || 'No location')}</div>
                <div class="incident-time">${escapeHtml(Number.isNaN(created.getTime()) ? 'N/A' : created.toLocaleString())}</div>
            </div>
        `;
    }

    function handleIncidentCardKey(event, id) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            openIncidentById(id);
        }
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

    function incidentDetailRow(label, value) {
        const display = value === null || value === undefined || String(value).trim() === '' ? 'N/A' : value;
        return `
            <div class="incident-detail-row">
                <strong>${escapeHtml(label)}</strong>
                <span>${escapeHtml(display)}</span>
            </div>
        `;
    }

    function openIncidentById(id) {
        const item = incidentItems.find(x => Number(x.id) === Number(id));
        if (!item) return;
        openIncidentModal(item);
    }

    function openIncident(code) {
        const item = incidentItems.find(x => x.incident_code === code);
        if (!item) return;
        openIncidentModal(item);
    }

    function openIncidentModal(item) {
        const modal = document.getElementById('incidentDetailsModal');
        const title = document.getElementById('incidentDetailsTitle');
        const body = document.getElementById('incidentDetailsBody');
        if (!modal || !title || !body || !item) return;

        const coordinates = Number.isFinite(Number(item.latitude)) && Number.isFinite(Number(item.longitude))
            ? `${Number(item.latitude).toFixed(6)}, ${Number(item.longitude).toFixed(6)}`
            : 'N/A';
        const unitType = item.assigned_unit_type ? ` (${item.assigned_unit_type})` : '';
        const assignedUnit = item.assigned_unit ? `${item.assigned_unit}${unitType}` : '';
        const vehicleParts = [item.vehicle_name, item.driver_name ? `Driver: ${item.driver_name}` : '', item.plate_number ? `Plate: ${item.plate_number}` : '']
            .filter(Boolean)
            .join(' | ');
        const priorityClass = normalizePriority(item.priority, 'low');

        title.textContent = item.incident_code || `Incident #${item.id || ''}`;
        body.innerHTML = `
            <div class="incident-details-summary">
                <span class="incident-priority ${priorityClass}">${escapeHtml(priorityClass.toUpperCase())}</span>
                <span class="incident-status-pill">${escapeHtml(item.status || 'N/A')}</span>
                <span>${escapeHtml(formatDateTime(item.created_at))}</span>
            </div>
            <div class="incident-details-grid">
                ${incidentDetailRow('Type', labelForType(item.type))}
                ${incidentDetailRow('Location', item.location)}
                ${incidentDetailRow('Coordinates', coordinates)}
                ${incidentDetailRow('Caller', item.caller_name)}
                ${incidentDetailRow('Phone', item.caller_phone)}
                ${incidentDetailRow('Assigned Unit', assignedUnit)}
                ${incidentDetailRow('Vehicle / Driver', vehicleParts)}
                ${incidentDetailRow('Dispatch Status', item.latest_dispatch_status)}
                ${incidentDetailRow('Assigned At', formatDateTime(item.assigned_at))}
                ${incidentDetailRow('On Scene At', formatDateTime(item.on_scene_at))}
                ${incidentDetailRow('Resolved At', formatDateTime(item.resolved_at || item.cleared_at))}
                ${incidentDetailRow('Response Time', item.response_time_min !== null && item.response_time_min !== undefined ? item.response_time_min + ' min' : '')}
            </div>
            <div class="incident-description-panel">
                <strong>Description</strong>
                <p>${escapeHtml(item.description || 'No description provided.')}</p>
            </div>
        `;

        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('incident-details-modal-open');
    }

    function closeIncidentModal() {
        const modal = document.getElementById('incidentDetailsModal');
        if (!modal) return;
        modal.classList.remove('active');
        modal.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('incident-details-modal-open');
    }

    document.addEventListener('click', (event) => {
        const modal = document.getElementById('incidentDetailsModal');
        if (modal && modal.classList.contains('active') && event.target === modal) {
            closeIncidentModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeIncidentModal();
        }
    });

    window.closeIncidentModal = closeIncidentModal;

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
