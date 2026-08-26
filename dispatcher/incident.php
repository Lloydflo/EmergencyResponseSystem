<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
// Require full login (including OTP verification) before loading page
require_role('dispatcher', 'dispatcher/incident.php');
require_once $rootDir . '/includes/db.php';
header('Cache-Control: private, no-store, max-age=0');
header('Pragma: no-cache');

$pageTitle = 'Incident Priority Management';
$assetUrl = static function (string $relativePath) use ($rootDir): string {
    $fullPath = $rootDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    $version = @filemtime($fullPath) ?: time();
    return htmlspecialchars($relativePath . '?v=' . $version, ENT_QUOTES, 'UTF-8');
};
$aiIncidentData = [
    'type' => 'Unknown',
    'location' => 'Unknown',
    'description' => 'No active incident data',
    'severity' => 'Unknown',
];

function incident_ai_inline_html(string $text): string
{
    $safe = htmlspecialchars(trim($text), ENT_QUOTES, 'UTF-8');
    return preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe) ?? $safe;
}

function incident_ai_analysis_html(string $text): string
{
    $lines = preg_split('/\R/u', trim($text)) ?: [];
    $html = '<div class="ai-analysis-text">';
    $listOpen = false;

    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            if ($listOpen) {
                $html .= '</ul>';
                $listOpen = false;
            }
            continue;
        }

        if (preg_match('/^\*\*([^*:\n]+):\*\*\s*(.*)$/u', $line, $match)) {
            if ($listOpen) {
                $html .= '</ul>';
                $listOpen = false;
            }
            $label = htmlspecialchars(trim($match[1]), ENT_QUOTES, 'UTF-8');
            $value = incident_ai_inline_html((string)($match[2] ?? ''));
            $html .= '<section class="ai-analysis-item"><h3>' . $label . '</h3>';
            if ($value !== '') {
                $html .= '<p>' . $value . '</p>';
            }
            $html .= '</section>';
            continue;
        }

        if (preg_match('/^[*-]\s+(.*)$/u', $line, $match)) {
            if (!$listOpen) {
                $html .= '<ul class="ai-analysis-list">';
                $listOpen = true;
            }
            $html .= '<li>' . incident_ai_inline_html((string)$match[1]) . '</li>';
            continue;
        }

        if ($listOpen) {
            $html .= '</ul>';
            $listOpen = false;
        }
        $html .= '<p>' . incident_ai_inline_html($line) . '</p>';
    }

    if ($listOpen) {
        $html .= '</ul>';
    }

    return $html . '</div>';
}

try {
    $pdo = get_db_connection();
    if ($pdo) {
        $incident = $pdo->query("SELECT type, location_address, description, priority
                                 FROM incidents
                                 WHERE status IN ('pending','dispatched','active','in_progress')
                                 ORDER BY CASE LOWER(priority)
                                     WHEN 'critical' THEN 1
                                     WHEN 'high' THEN 2
                                     WHEN 'urgent' THEN 2
                                     WHEN 'medium' THEN 3
                                     WHEN 'moderate' THEN 3
                                     WHEN 'low' THEN 4
                                     ELSE 6
                                 END, created_at DESC
                                 LIMIT 1")->fetch();
        if (!$incident) {
            $incident = $pdo->query("SELECT type, location_address, description, priority
                                     FROM incidents
                                     ORDER BY created_at DESC
                                     LIMIT 1")->fetch();
        }
        if ($incident) {
            $aiIncidentData = [
                'type' => (string)($incident['type'] ?? 'Unknown'),
                'location' => (string)($incident['location_address'] ?? 'Unknown'),
                'description' => (string)($incident['description'] ?? 'No description'),
                'severity' => strtoupper((string)($incident['priority'] ?? 'Unknown')),
            ];
        }
    }
} catch (Throwable $e) {
    // Keep fallback AI incident data
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
    <link rel="stylesheet" href="<?php echo $assetUrl('css/global.css'); ?>">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="<?php echo $assetUrl('css/sidebar.css'); ?>">
    <link rel="stylesheet" href="<?php echo $assetUrl('css/admin-header.css'); ?>">
    <link rel="stylesheet" href="<?php echo $assetUrl('css/sidebar-footer.css'); ?>">
    <link rel="stylesheet" href="<?php echo $assetUrl('css/cards.css'); ?>">
    <link rel="stylesheet" href="<?php echo $assetUrl('css/incident.css'); ?>">
    <script defer src="<?php echo $assetUrl('js/place-autocomplete.js'); ?>"></script>

</head>
<body class="dispatcher-incident-page">
    <!-- Include Sidebar Component -->
    <?php include $rootDir . '/includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Incident Priority Management
       =================================== -->
    <div class="main-content">
        <div class="main-container">

            <section class="incident-hero">
                <div class="incident-hero-main">
                    <div class="incident-kicker">Emergency Response Incident Console</div>
                    <h1 class="incident-hero-title">Incident Priority Management</h1>
                    <p class="incident-hero-text">
                        Review active cases, adjust urgency levels, and keep dispatch decisions aligned with real-time field conditions.
                    </p>

                    <div class="incident-hero-chips">
                        <span class="incident-chip incident-chip-live"><span class="incident-chip-dot"></span> Priority Queue Live</span>
                    </div>
                </div>

                <div class="incident-hero-side">
                    <div class="incident-focus-card">
                        <div class="incident-focus-label">Current Escalation Focus</div>
                        <div class="incident-focus-value"><?php echo htmlspecialchars($aiIncidentData['type']); ?></div>
                        <div class="incident-focus-meta"><?php echo htmlspecialchars($aiIncidentData['location']); ?></div>
                        <div class="incident-focus-severity">Priority: <?php echo htmlspecialchars($aiIncidentData['severity']); ?></div>
                        <div class="incident-focus-desc" title="<?php echo htmlspecialchars($aiIncidentData['description']); ?>"><?php echo htmlspecialchars($aiIncidentData['description']); ?></div>
                    </div>
                </div>
            </section>

            <section class="stats-cards">
                <div class="stats-card priority-high-card">
                    <div class="stats-icon high">
                        <i class="fas fa-fire"></i>
                    </div>
                    <div class="stats-content">
                        <h3>0</h3>
                        <p>Critical / High Priority Incidents</p>
                    </div>
                </div>
                <div class="stats-card priority-medium-card">
                    <div class="stats-icon medium">
                        <i class="fas fa-ambulance"></i>
                    </div>
                    <div class="stats-content">
                        <h3>0</h3>
                        <p>Medium Priority Incidents</p>
                    </div>
                </div>
                <div class="stats-card priority-low-card">
                    <div class="stats-icon low">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div class="stats-content">
                        <h3>0</h3>
                        <p>Low Priority Incidents</p>
                    </div>
                </div>
                <div class="stats-card resolved-card">
                    <div class="stats-icon resolved">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stats-content">
                        <h3>0</h3>
                        <p>Resolved Incidents</p>
                    </div>
                </div>
            </section>

            <section class="filters-section">
                <div class="section-header filters-header">
                    <div>
                        <div class="section-eyebrow">Incident Filters</div>
                        <h2 class="section-title">
                            <i class="fas fa-filter"></i>
                            Priority and Response Filters
                        </h2>
                    </div>
                    <div class="filters-support-text">
                        Narrow the queue by urgency, status, incident type, or free-text search.
                    </div>
                </div>
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="priority-filter">Priority Level</label>
                        <select id="priority-filter">
                            <option value="">All Priorities</option>
                            <option value="critical">Critical Priority</option>
                            <option value="high">High Priority</option>
                            <option value="medium">Medium Priority</option>
                            <option value="low">Low Priority</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="status-filter">Status</label>
                        <select id="status-filter">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="dispatched">Dispatched</option>
                            <option value="resolved">Resolved</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="type-filter">Incident Type</label>
                        <select id="type-filter">
                            <option value="">All Types</option>
                            <option value="medical">Medical Emergency</option>
                            <option value="fire">Fire</option>
                            <option value="police">Police Emergency</option>
                            <option value="traffic">Traffic Accident</option>
                        </select>
                    </div>
                    <div class="filter-group filter-group-search">
                        <label for="search">Search</label>
                        <input type="text" id="search" placeholder="Search reference, type, location, description...">
                    </div>
                </div>
            </section>

            <div class="incident-console-grid">
                <section class="priority-section">
                    <div class="section-header incident-log-header">
                        <div>
                            <div class="section-eyebrow">Incident Queue</div>
                            <h2 class="section-title">
                                <i class="fas fa-list"></i>
                                Logged Incidents
                            </h2>
                            <p id="incident-result-count" class="incident-result-count" aria-live="polite">Loading incidents...</p>
                        </div>
                        <button id="btn-view-resolved" class="btn-action" title="Show all resolved incidents">View Resolved</button>
                    </div>
                    <div class="incident-list-panel">
                        <div id="incident-list-dynamic" aria-busy="true">
                            <div class="incident-queue-state is-loading" role="status">
                                <i class="fas fa-spinner fa-spin" aria-hidden="true"></i>
                                <div>
                                    <strong>Loading incident queue</strong>
                                    <span>Retrieving the latest operational records...</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </section>

                <aside class="ai-analysis-section">
                    <div class="ai-analysis-card">
                        <div class="ai-analysis-header">
                            <h2><i class="fas fa-brain"></i> AI Incident Analysis</h2>
                            <span class="ai-badge"><i class="fas fa-robot"></i> Powered by Gemini AI</span>
                        </div>
                        <div class="ai-analysis-content" id="ai-analysis-content">
                            <?php
                            include $rootDir . '/includes/gemini_helper.php';
                            $analysis = analyzeIncident($aiIncidentData);
                            if ($analysis) {
                                echo incident_ai_analysis_html((string)$analysis);
                            } else {
                                $aiError = function_exists('getGeminiLastError') ? trim((string) getGeminiLastError()) : '';
                                if ($aiError === '') {
                                    $aiError = 'Unable to generate AI analysis at this time.';
                                }
                                echo '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars($aiError) . '</div>';
                            }
                            ?>
                        </div>
                        <div class="ai-analysis-actions">
                            <button class="btn-ai-refresh" onclick="refreshAIAnalysis()">
                                <i class="fas fa-sync"></i> Analyze Incidents
                            </button>
                        </div>
                    </div>
                </aside>
            </div>

        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <script>
        // Incident Priority Management Functionality
        let INCIDENTS = [];
        let REFRESH_TIMER = null;
        let RESOLVED_REFRESH_TIMER = null;
        let RESOLVED_LIST_RELOAD = null;
        let INCIDENTS_FETCH_ERROR = '';
        let LAST_RESOLVED_NOTIFICATION_ID = Number(sessionStorage.getItem('ers_last_resolved_notice_id') || '0') || 0;
        const API_LIST_URL = 'api/incidents_list.php';
        const API_UPDATE_URL = 'api/incident_update.php';
        const API_RESOLVE_URL = 'api/incident_resolve.php';
        const API_RESOLVED_NOTIFICATIONS_URL = 'api/resolved_incident_notifications.php';

        // Event delegation for all actions in the dynamic incident queue.
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('button');
            if (!btn) return;
            const action = btn.getAttribute('data-action') || '';

            if (action === 'retry-incidents') {
                fetchIncidents();
                return;
            }

            const incidentRow = btn.closest('[data-incident-row]');
            if (!incidentRow) return;
            const rowIncidentId = Number(incidentRow.getAttribute('data-id') || '0');
            const rowRef = (incidentRow.getAttribute('data-ref') || '').trim();
            const incident = INCIDENTS.find(i => Number(i.id || 0) === rowIncidentId)
                || INCIDENTS.find(i => String(i.incident_code || i.reference_no || '') === rowRef);
            if (!incident) return;

            // Priority button
            if (action === 'priority' || btn.classList.contains('btn-priority')) {
                // Cycle through priorities: high -> medium -> low -> high
                let current = (incident.priority || 'low').toLowerCase();
                let newPriority = current === 'high' ? 'medium' : (current === 'medium' ? 'low' : 'high');
                const prevPriority = incident.priority;
                // Optimistic UI update
                incident.priority = newPriority;
                renderDynamicIncidents();

                const incidentId = Number(incident.id || 0);
                const incidentCode = String(incident.incident_code || incident.reference_no || '').trim();
                if (incidentId > 0 || incidentCode) {
                    const body = { priority: newPriority };
                    if (incidentId > 0) body.id = incidentId;
                    if (incidentCode) body.incident_code = incidentCode;
                    fetch(API_UPDATE_URL, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify(body)
                    })
                    .then(r => r.json())
                    .then(res => {
                        if (res && res.ok) {
                            showNotification(`Incident priority changed to ${newPriority.toUpperCase()}`, 'success');
                            try { localStorage.setItem('ers_incidents_changed', String(Date.now())); } catch (e) {}
                        } else {
                            incident.priority = prevPriority;
                            renderDynamicIncidents();
                            showNotification('Failed to update priority on server', 'error');
                        }
                    })
                    .catch(() => {
                        incident.priority = prevPriority;
                        renderDynamicIncidents();
                        showNotification('Network error while updating priority', 'error');
                    });
                } else {
                    showNotification(`Incident priority changed to ${newPriority.toUpperCase()} (local)`, 'info');
                }
                return;
            }

            // Update button
            if (action === 'edit' || btn.querySelector('.fa-edit')) {
                showUpdateModal(incident);
                return;
            }

            // Contact button
            if (action === 'call' || btn.querySelector('.fa-phone')) {
                const phone = getIncidentPhone(incident);
                if (phone) {
                    if (confirm(`Call ${phone}?`)) {
                        window.location.href = 'tel:' + encodeURIComponent(phone);
                        showNotification(`Initiating call to ${phone}`, 'info');
                    }
                } else {
                    showNotification('Phone number not found', 'error');
                }
                return;
            }

            // Resolve button
            if (action === 'resolve') {
                resolveIncident(incident, btn);
                return;
            }
        });

        async function resolveIncident(incident, button) {
            if (!incident) return;
            if (normalizeIncidentStatus(incident.status) === 'resolved') {
                showNotification('Incident is already resolved.', 'info');
                return;
            }
            if (!confirm('Are you sure you want to resolve this incident?')) {
                return;
            }

            const incidentId = Number(incident.id || 0);
            const incidentCode = String(incident.incident_code || incident.reference_no || '').trim();
            if (incidentId <= 0 && !incidentCode) {
                showNotification('Unable to resolve: missing incident identifier. Please refresh the page.', 'error');
                return;
            }

            const body = {
                note: `Resolved via Incident page at ${new Date().toLocaleString()}`
            };
            if (incidentId > 0) body.incident_id = incidentId;
            if (incidentCode) body.incident_code = incidentCode;

            const previousDisabled = button ? button.disabled : false;
            if (button) {
                button.disabled = true;
                button.setAttribute('aria-busy', 'true');
            }

            try {
                const response = await fetch(API_RESOLVE_URL, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(body)
                });
                const result = await response.json().catch(() => ({}));
                if (!response.ok || !(result && result.ok)) {
                    throw new Error((result && result.error) ? String(result.error) : 'Failed to resolve incident');
                }

                const now = new Date().toISOString();
                incident.status = 'resolved';
                incident.resolved_at = now;
                incident.updated_at = now;
                renderDynamicIncidents();
                showNotification('Incident resolved. Units released to available.', 'success');
                try {
                    const changedStamp = String(Date.now());
                    const detail = {
                        source: 'incident_priority',
                        incidentId,
                        referenceNo: incidentCode,
                        changedAt: Date.now()
                    };
                    localStorage.setItem('ers_incidents_changed', JSON.stringify(detail));
                    localStorage.setItem('ers_incidents', changedStamp);
                    localStorage.setItem('ers_anonymous_tips_changed', JSON.stringify(detail));
                } catch (e) {}
                window.dispatchEvent(new CustomEvent('ers:incident-queue-updated', { detail: { incidentId, referenceNo: incidentCode } }));
                window.dispatchEvent(new CustomEvent('ers:anonymous-tips-updated', { detail: { incidentId, referenceNo: incidentCode } }));
                try { await fetchIncidents(); } catch (e) {}
            } catch (err) {
                const message = err.message || 'Network error';
                showNotification(message.toLowerCase().startsWith('resolve failed') ? message : `Resolve failed: ${message}`, 'error');
                if (button) {
                    button.disabled = previousDisabled;
                    button.removeAttribute('aria-busy');
                }
            }
        }

        // AI refresh for incident analysis
        function refreshAIAnalysis() {
            const container = document.getElementById('ai-analysis-content');
            container.innerHTML = '<div class="ai-loading"><i class="fas fa-spinner"></i> Generating analysis...</div>';
            fetch('api/ai_incident_analysis.php')
            .then(r => r.json())
            .then(json => {
                if (json.ok && json.text) {
                    container.innerHTML = renderAiAnalysisText(json.text);
                } else {
                    const msg = (json && json.error) ? String(json.error) : 'Unable to generate AI analysis at this time.';
                    container.innerHTML = '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(msg).replace(/\n/g,'<br>') + '</div>';
                }
            })
            .catch(() => {
                container.innerHTML = '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> AI request failed.</div>';
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            refreshAIAnalysis();
        });

        window.addEventListener('storage', function(e) {
            if (e.key === 'ers_incidents' || e.key === 'ers_incidents_changed' || e.key === 'ers_anonymous_tips_changed' || e.key === 'ers_last_logged_incident') {
                refreshAIAnalysis();
                fetchIncidents();
            }
        });

        window.addEventListener('ers:incident-queue-updated', function() {
            refreshAIAnalysis();
            fetchIncidents();
        });

        window.addEventListener('ers:anonymous-tips-updated', function() {
            refreshAIAnalysis();
            fetchIncidents();
        });

        // Filter functionality
        const priorityFilter = document.getElementById('priority-filter');
        const statusFilter = document.getElementById('status-filter');
        const typeFilter = document.getElementById('type-filter');
        const searchInput = document.getElementById('search');
        let currentSearch = '';

        function applyFilters() {
            renderDynamicIncidents();
        }

        // Add event listeners to filters
        priorityFilter.addEventListener('change', fetchIncidents);
        statusFilter.addEventListener('change', fetchIncidents);
        typeFilter.addEventListener('change', fetchIncidents);
        searchInput.addEventListener('input', function(e) {
            currentSearch = e.target.value;
            renderDynamicIncidents();
        });

        // Header button: View Resolved (opens modal)
        document.addEventListener('DOMContentLoaded', function() {
            const btnResolved = document.getElementById('btn-view-resolved');
            if (btnResolved) {
                btnResolved.addEventListener('click', function() {
                    openResolvedModal();
                });
            }

            try {
                const params = new URLSearchParams(window.location.search);
                const requestedView = String(params.get('view') || params.get('status') || '').toLowerCase();
                if (requestedView === 'resolved') {
                    window.setTimeout(openResolvedModal, 150);
                }
            } catch (e) {}
        });

        function isResolvedModalOpen() {
            const modal = document.getElementById('incident-resolved-modal');
            return !!(modal && modal.style.display !== 'none');
        }

        let resolvedNoticePollInFlight = false;
        async function pollResolvedIncidentNotifications() {
            if (document.hidden || resolvedNoticePollInFlight) return;
            resolvedNoticePollInFlight = true;
            try {
                const res = await fetch(API_RESOLVED_NOTIFICATIONS_URL + '?limit=5', {
                    cache: 'no-store',
                    credentials: 'same-origin'
                });
                const data = await res.json().catch(() => ({}));
                if (!data || !data.ok || !Array.isArray(data.notifications)) return;

                const latestId = Number(data.latest_id || 0);
                if (latestId <= 0) return;

                if (LAST_RESOLVED_NOTIFICATION_ID <= 0) {
                    LAST_RESOLVED_NOTIFICATION_ID = latestId;
                    sessionStorage.setItem('ers_last_resolved_notice_id', String(latestId));
                    return;
                }

                if (latestId > LAST_RESOLVED_NOTIFICATION_ID) {
                    const newItems = data.notifications.filter(item => Number(item.notification_id || 0) > LAST_RESOLVED_NOTIFICATION_ID);
                    LAST_RESOLVED_NOTIFICATION_ID = latestId;
                    sessionStorage.setItem('ers_last_resolved_notice_id', String(latestId));

                    await fetchIncidents();
                    if (isResolvedModalOpen() && typeof RESOLVED_LIST_RELOAD === 'function') {
                        RESOLVED_LIST_RELOAD();
                    }

                    const latest = newItems[0] || {};
                    const incident = latest.incident || {};
                    const label = incident.label || incident.reference_no || (incident.id ? ('#' + incident.id) : 'Incident');
                    showNotification(`${label} moved to View Resolved.`, 'success');
                }
            } catch (e) {
            } finally {
                resolvedNoticePollInFlight = false;
            }
        }

        // Update statistics
        function updateStats() {
            // Count based on currently filtered incidents
            const filtered = INCIDENTS.filter(passFilters);
            let highCount = 0, mediumCount = 0, lowCount = 0;
            filtered.forEach(i => {
                const p = normalizePriority(i.priority);
                if (p === 'critical' || p === 'high') highCount++;
                else if (p === 'medium') mediumCount++;
                else lowCount++;
            });
            const h3s = document.querySelectorAll('.stats-content h3');
            if (h3s[0]) h3s[0].textContent = highCount;
            if (h3s[1]) h3s[1].textContent = mediumCount;
            if (h3s[2]) h3s[2].textContent = lowCount;

            // Resolved count from overall incidents (not hidden by default filter)
            let resolvedCount = 0;
            (INCIDENTS || []).forEach(i => {
                const s = (i.status || '').toLowerCase();
                if (s === 'resolved' || s === 'cancelled') resolvedCount++;
            });
            if (h3s[3]) h3s[3].textContent = resolvedCount;
        }

        function mapStatusToBadge(status) {
            const s = (status || '').toLowerCase();
            if (s === 'dispatched') return { cls: 'status-dispatched', label: 'Dispatched' };
            if (s === 'resolved' || s === 'cancelled') return { cls: 'status-resolved', label: 'Resolved' };
            return { cls: 'status-active', label: 'Active' }; // pending / default
        }

        function normalizePriority(priority) {
            const value = String(priority || 'low').trim().toLowerCase();
            if (value === 'critical') return 'critical';
            if (value === 'high' || value === 'urgent') return 'high';
            if (value === 'medium' || value === 'moderate') return 'medium';
            return 'low';
        }

        function capitalize(s) { return (s || '').charAt(0).toUpperCase() + (s || '').slice(1); }

        function normalizeIncidentStatus(value) {
            const raw = String(value || '').trim().toLowerCase();
            if (raw === 'active') return 'pending';
            if (raw === 'pending' || raw === 'dispatched' || raw === 'resolved' || raw === 'cancelled') return raw;
            return 'pending';
        }

        function getIncidentPhone(incident) {
            if (!incident) return '';
            if (incident.caller_phone) return String(incident.caller_phone).trim();
            if (incident.contact) return String(incident.contact).trim();
            const text = [incident.description, incident.notes].map(v => String(v || '')).join(' ');
            const match = text.match(/(\+?\d{1,3}[-.\s]?\d{1,4}[-.\s]?\d{1,4}[-.\s]?\d{1,4})/);
            return match ? String(match[1]).trim() : '';
        }

        function incidentDisplaySummary(incident) {
            const raw = String(incident && incident.description ? incident.description : '').replace(/\s+/g, ' ').trim();
            if (!raw) return 'No description recorded.';
            if (/emergency report conversation summary/i.test(raw)) {
                const caller = raw.match(/Citizen:\s*([^]+?)\s+Phone:/i);
                const reportedType = raw.match(/Incident Type:\s*([^]+?)\s+Location:/i)
                    || raw.match(/Emergency type:\s*([^]+?)\s+Location:/i);
                const typeLabel = reportedType && reportedType[1]
                    ? reportedType[1].trim()
                    : String(incident.type || 'Emergency').trim();
                const callerLabel = caller && caller[1] ? ` reported by ${caller[1].trim()}` : '';
                return `${typeLabel}${callerLabel}. Open details for the complete call narrative.`;
            }
            const withoutLinks = raw.replace(/https?:\/\/\S+/gi, '').replace(/\s+/g, ' ').trim();
            return withoutLinks.length > 220 ? `${withoutLinks.slice(0, 217).trim()}…` : withoutLinks;
        }

        function escapeHtml(value) {
            return String(value === null || value === undefined ? '' : value)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function aiInlineHtml(value) {
            return escapeHtml(value).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
        }

        function renderAiAnalysisText(value) {
            const lines = String(value || '').trim().split(/\r?\n/);
            let html = '<div class="ai-analysis-text">';
            let listOpen = false;
            const closeList = () => {
                if (listOpen) {
                    html += '</ul>';
                    listOpen = false;
                }
            };

            lines.forEach((rawLine) => {
                const line = rawLine.trim();
                if (!line) {
                    closeList();
                    return;
                }

                const headingMatch = line.match(/^\*\*([^*:\n]+):\*\*\s*(.*)$/);
                if (headingMatch) {
                    closeList();
                    html += `<section class="ai-analysis-item"><h3>${escapeHtml(headingMatch[1])}</h3>`;
                    if (headingMatch[2]) {
                        html += `<p>${aiInlineHtml(headingMatch[2])}</p>`;
                    }
                    html += '</section>';
                    return;
                }

                const bulletMatch = line.match(/^[*-]\s+(.*)$/);
                if (bulletMatch) {
                    if (!listOpen) {
                        html += '<ul class="ai-analysis-list">';
                        listOpen = true;
                    }
                    html += `<li>${aiInlineHtml(bulletMatch[1])}</li>`;
                    return;
                }

                closeList();
                html += `<p>${aiInlineHtml(line)}</p>`;
            });

            closeList();
            return html + '</div>';
        }

        function incidentCardHtml(i) {
            const priority = normalizePriority(i.priority);
            const statusInfo = mapStatusToBadge(i.status);
            const created = new Date(i.created_at || Date.now());
            const location = i.location || i.location_address || 'Unknown location';
            const ref = i.incident_code || i.reference_no || '';
            const type = capitalize(i.type || 'Unknown');
            const priorityLabel = capitalize(priority);
            const description = incidentDisplaySummary(i);
            const id = Number(i.id || 0);
            const phone = getIncidentPhone(i);
            const phoneDisabled = phone ? '' : ' disabled aria-disabled="true"';
            const phoneTitle = phone ? `Call ${phone}` : 'No contact number available';
            return `
                <article class="incident-queue-card priority-${escapeHtml(priority)}" data-incident-row data-id="${id}" data-ref="${escapeHtml(ref)}" role="listitem">
                    <div class="incident-card-header">
                        <div class="incident-card-identity">
                            <span class="incident-card-label">Reference number</span>
                            <h3 class="incident-card-reference">${escapeHtml(ref || 'N/A')}</h3>
                            <span class="incident-type-label"><i class="fas fa-triangle-exclamation" aria-hidden="true"></i> ${escapeHtml(type)}</span>
                        </div>
                        <div class="incident-card-badges" aria-label="Incident priority and status">
                            <span class="priority-badge priority-${escapeHtml(priority)}">${escapeHtml(priorityLabel)}</span>
                            <span class="status-badge ${escapeHtml(statusInfo.cls)}">${escapeHtml(statusInfo.label)}</span>
                        </div>
                    </div>

                    <p class="incident-card-description" title="${escapeHtml(description || 'No description')}">
                        ${escapeHtml(description || 'No description')}
                    </p>

                    <div class="incident-card-meta">
                        <div class="incident-meta-item">
                            <i class="fas fa-location-dot" aria-hidden="true"></i>
                            <div><span>Location</span><strong title="${escapeHtml(location)}">${escapeHtml(location)}</strong></div>
                        </div>
                        <div class="incident-meta-item">
                            <i class="fas fa-clock" aria-hidden="true"></i>
                            <div><span>Date and time</span><strong>${escapeHtml(created.toLocaleString())}</strong></div>
                        </div>
                    </div>

                    <div class="incident-card-actions" aria-label="Actions for incident ${escapeHtml(ref || type)}">
                        <button class="btn-incident-action action-priority btn-priority priority-${escapeHtml(priority)}" type="button" data-action="priority" title="Change the current ${escapeHtml(priorityLabel)} priority" aria-label="Change priority for ${escapeHtml(ref || type)}">
                            <i class="fas fa-flag" aria-hidden="true"></i>
                            <span>Set Priority</span>
                        </button>
                        <button class="btn-incident-action action-edit" type="button" data-action="edit" title="Edit incident details" aria-label="Edit incident ${escapeHtml(ref || type)}">
                            <i class="fas fa-pen-to-square" aria-hidden="true"></i>
                            <span>Edit</span>
                        </button>
                        <button class="btn-incident-action action-call" type="button" data-action="call" title="${escapeHtml(phoneTitle)}" aria-label="Call contact for incident ${escapeHtml(ref || type)}"${phoneDisabled}>
                            <i class="fas fa-phone" aria-hidden="true"></i>
                            <span>${phone ? 'Call' : 'No Phone'}</span>
                        </button>
                        <button class="btn-incident-action action-resolve" type="button" data-action="resolve" title="Resolve incident" aria-label="Resolve incident ${escapeHtml(ref || type)}">
                            <i class="fas fa-circle-check" aria-hidden="true"></i>
                            <span>Resolve</span>
                        </button>
                    </div>
                </article>
            `;
        }

        function passFilters(i) {
            const priorityValue = (priorityFilter.value || '').toLowerCase();
            const statusValue = (statusFilter.value || '').toLowerCase();
            const typeValue = (typeFilter.value || '').toLowerCase();
            const searchValue = currentSearch.trim().toLowerCase();

            if (priorityValue && normalizePriority(i.priority) !== priorityValue) return false;

            // Default: exclude resolved incidents from logged list unless explicitly filtered
            {
                const s = (i.status || '').toLowerCase();
                const mapped = s === 'dispatched' ? 'dispatched' : (s === 'resolved' || s === 'cancelled' ? 'resolved' : 'active');
                if (!statusValue && mapped === 'resolved') return false; // hide resolved by default
                if (statusValue && mapped !== statusValue) return false;   // respect explicit filter selection
            }

            if (typeValue) {
                const incidentType = (i.type || '').toLowerCase();
                const types = incidentType.split(',').map(t => t.trim());
                if (!types.includes(typeValue) && !incidentType.includes(typeValue)) return false;
            }

            if (searchValue) {
                const hay = [i.reference_no, i.incident_code, i.type, i.title, i.location, i.location_address, i.description, i.caller_name]
                    .map(v => (v || '').toString().toLowerCase()).join(' ');
                if (!hay.includes(searchValue)) return false;
            }
            return true;
        }

        function renderDynamicIncidents() {
            const container = document.getElementById('incident-list-dynamic');
            if (!container) return;
            container.setAttribute('aria-busy', 'false');
            const countEl = document.getElementById('incident-result-count');

            if (INCIDENTS_FETCH_ERROR) {
                container.innerHTML = `
                    <div class="incident-queue-state is-error" role="alert">
                        <i class="fas fa-cloud-arrow-down" aria-hidden="true"></i>
                        <div>
                            <strong>Could not load incidents</strong>
                            <span>${escapeHtml(INCIDENTS_FETCH_ERROR)}</span>
                        </div>
                        <button type="button" data-action="retry-incidents"><i class="fas fa-rotate-right" aria-hidden="true"></i> Retry</button>
                    </div>`;
                if (countEl) countEl.textContent = 'Queue unavailable';
                updateStats();
                return;
            }

            const filtered = INCIDENTS.filter(passFilters);
            if (!filtered.length) {
                container.innerHTML = `
                    <div class="incident-queue-state is-empty" role="status">
                        <i class="fas fa-inbox" aria-hidden="true"></i>
                        <div>
                            <strong>No matching incidents</strong>
                            <span>Try adjusting the priority, status, type, or search filters.</span>
                        </div>
                    </div>`;
            } else {
                container.innerHTML = `<div class="incident-queue-list" role="list">${filtered.map(incidentCardHtml).join('')}</div>`;
            }
            if (countEl) countEl.textContent = `${filtered.length} ${filtered.length === 1 ? 'incident' : 'incidents'} shown`;
            updateStats();
        }

        let fetchIncidentsInFlight = false;
        let fetchIncidentsQueued = false;
        async function fetchIncidents() {
            if (document.hidden) return;
            if (fetchIncidentsInFlight) {
                fetchIncidentsQueued = true;
                return;
            }
            fetchIncidentsInFlight = true;
            const listContainer = document.getElementById('incident-list-dynamic');
            if (listContainer) listContainer.setAttribute('aria-busy', 'true');
            // Gather filter values
            const params = new URLSearchParams();
            const priorityValue = (priorityFilter.value || '').toLowerCase();
            const statusValue = (statusFilter.value || '').toLowerCase();
            const typeValue = (typeFilter.value || '').toLowerCase();
            const searchValue = (searchInput.value || '').toLowerCase();
            if (priorityValue) params.append('priority', priorityValue);
            if (statusValue) params.append('status', statusValue);
            if (typeValue) params.append('type', typeValue);
            if (searchValue) params.append('search', searchValue);
            try {
                const res = await fetch(API_LIST_URL + '?' + params.toString());
                const data = await res.json();
                if (data && data.ok) {
                    INCIDENTS = data.items || [];
                    INCIDENTS_FETCH_ERROR = '';
                } else {
                    INCIDENTS = [];
                    INCIDENTS_FETCH_ERROR = (data && data.error) ? String(data.error) : 'The incident service returned an unexpected response.';
                }
            } catch (e) {
                console.warn('Failed to fetch incidents', e);
                INCIDENTS = [];
                INCIDENTS_FETCH_ERROR = 'Check the network connection, then try again.';
            } finally {
                fetchIncidentsInFlight = false;
            }
            renderDynamicIncidents();
            if (fetchIncidentsQueued) {
                fetchIncidentsQueued = false;
                fetchIncidents();
            }
        }

        // Notification system
        function showNotification(message, type) {
            // Remove existing notifications
            const existingNotifications = document.querySelectorAll('.notification');
            existingNotifications.forEach(notification => notification.remove());

            // Create notification element
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                padding: 1rem 1.5rem;
                border-radius: 8px;
                color: white;
                font-weight: 600;
                z-index: 1000;
                animation: slideIn 0.3s ease-out;
                box-shadow: 0 4px 12px rgba(0,0,0,0.3);
            `;

            // Set background color based on type
            if (type === 'success') {
                notification.style.backgroundColor = '#28a745';
            } else if (type === 'error') {
                notification.style.backgroundColor = '#dc3545';
            } else if (type === 'info') {
                notification.style.backgroundColor = '#17a2b8';
            }

            notification.textContent = message;
            document.body.appendChild(notification);

            // Auto remove after 3 seconds
            setTimeout(() => {
                notification.style.animation = 'slideOut 0.3s ease-in';
                setTimeout(() => {
                    if (notification.parentNode) {
                        notification.parentNode.removeChild(notification);
                    }
                }, 300);
            }, 3000);
        }

        // Add css animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeIn {
                from { opacity: 0; transform: translateY(-10px); }
                to { opacity: 1; transform: translateY(0); }
            }

            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }

            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }

            .notification {
                font-family: inherit;
            }

            .btn-priority, .btn-action {
                transition: all 0.3s ease;
            }

            .btn-priority:hover, .btn-action:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            }
        `;
        document.head.appendChild(style);

        // Initialize stats on page load
        document.addEventListener('DOMContentLoaded', function() {
            fetchIncidents();
            if (REFRESH_TIMER) clearInterval(REFRESH_TIMER);
            REFRESH_TIMER = setInterval(fetchIncidents, 10000); // refresh every 10s
            pollResolvedIncidentNotifications();
            if (RESOLVED_REFRESH_TIMER) clearInterval(RESOLVED_REFRESH_TIMER);
            RESOLVED_REFRESH_TIMER = setInterval(pollResolvedIncidentNotifications, 5000);
        });

        // Modal for updating incident description
        function showUpdateModal(incident) {
            let modal = document.getElementById('incident-update-modal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'incident-update-modal';
                // Ensure Leaflet css/JS is loaded
                if (!document.getElementById('leaflet-css')) {
                    var lcss = document.createElement('link');
                    lcss.rel = 'stylesheet';
                    lcss.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                    lcss.id = 'leaflet-css';
                    document.head.appendChild(lcss);
                }
                if (!window.L) {
                    var ljs = document.createElement('script');
                    ljs.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                    ljs.onload = function() { setTimeout(initIncidentMap, 200); };
                    document.body.appendChild(ljs);
                }
                modal.innerHTML = `
                    <div class="modal-backdrop"></div>
                    <div class="modal-content" style="min-width:480px;max-width:700px;">
                        <h3>Update Incident Details</h3>
                        <form id="modal-update-form">
                            <input id="modal-incident-id" type="hidden">
                            <input id="modal-incident-code" type="hidden">
                            <label>Type<br>
                                <input id="modal-type-input" type="text" required style="width:100%">
                            </label><br><br>
                            <label>Priority<br>
                                <select id="modal-priority-input" required style="width:100%">
                                    <option value="high">High</option>
                                    <option value="medium">Medium</option>
                                    <option value="low">Low</option>
                                </select>
                            </label><br><br>
                            <label>Description<br>
                                <textarea id="modal-desc-input" rows="4" required style="width:100%"></textarea>
                            </label><br><br>
                            <label>Location<br>
                                <input id="modal-location-input" type="text" style="width:100%" placeholder="Enter coordinates or address">
                            </label><br>
                            <!-- Map picker removed: now in call.php only -->
                            <label>Status<br>
                                <select id="modal-status-input" style="width:100%">
                                    <option value="pending">Active</option>
                                    <option value="dispatched">Dispatched</option>
                                    <option value="resolved">Resolved</option>
                                    <option value="cancelled">Cancelled</option>
                                </select>
                            </label><br><br>
                            <div style="margin-top:1em;text-align:right;">
                                <button type="button" id="modal-cancel-btn" style="margin-right:0.5em;">Cancel</button>
                                <button type="submit" id="modal-save-btn">Save</button>
                            </div>
                        </form>
                    </div>
                `;
                document.body.appendChild(modal);
                // Add styles
                const style = document.createElement('style');
                style.textContent = `
                    #incident-update-modal { position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:2000;display:flex;align-items:center;justify-content:center; }
                    #incident-update-modal .modal-backdrop { position:absolute;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.45);backdrop-filter:blur(2px); }
                    #incident-update-modal .modal-content { position:relative;z-index:1;background:#ffffff;padding:2em 1.5em;border-radius:12px;box-shadow:0 12px 36px rgba(0,0,0,0.18);min-width:320px;max-width:95vw;border:1px solid #e5e7eb;color:#0f172a; }
                    #incident-update-modal h3 { margin-top:0;margin-bottom:1rem;color:#0f172a;border-bottom:1px solid #e5e7eb;padding-bottom:0.65rem; }
                    #incident-update-modal label { display:block;color:#334155;font-weight:600; }
                    #incident-update-modal textarea,
                    #incident-update-modal input,
                    #incident-update-modal select { border:1px solid #cbd5e1;border-radius:8px;padding:0.7em; font-size:1em;background:#ffffff;color:#0f172a; }
                    #incident-update-modal textarea::placeholder,
                    #incident-update-modal input::placeholder { color:#64748b; }
                    #incident-update-modal textarea:focus,
                    #incident-update-modal input:focus,
                    #incident-update-modal select:focus { outline:none;border-color:#60a5fa;box-shadow:0 0 0 3px rgba(96,165,250,0.15); }
                    #incident-update-modal button { padding:0.6em 1.2em;font-size:1em;border-radius:8px;border:1px solid transparent;cursor:pointer;font-weight:700; }
                    #modal-save-btn { background:#2563eb;color:#fff;border-color:#2563eb; }
                    #modal-cancel-btn { background:#f8fafc;color:#111827;border-color:#cbd5e1; }
                    #modal-save-btn:hover { background:#1d4ed8; }
                    #modal-cancel-btn:hover { background:#e2e8f0; }

                    html[data-theme="dark"] #incident-update-modal .modal-content { background:#000000 !important;border-color:#334155 !important;color:#f8fafc !important;box-shadow:0 18px 42px rgba(2,6,23,0.55) !important; }
                    html[data-theme="dark"] #incident-update-modal h3 { color:#f8fafc !important;border-bottom-color:#334155 !important; }
                    html[data-theme="dark"] #incident-update-modal label { color:#cbd5e1 !important; }
                    html[data-theme="dark"] #incident-update-modal textarea,
                    html[data-theme="dark"] #incident-update-modal input,
                    html[data-theme="dark"] #incident-update-modal select { background:#0f172a !important;color:#f8fafc !important;border-color:#475569 !important; }
                    html[data-theme="dark"] #incident-update-modal textarea::placeholder,
                    html[data-theme="dark"] #incident-update-modal input::placeholder { color:#94a3b8 !important; }
                    html[data-theme="dark"] #incident-update-modal textarea:focus,
                    html[data-theme="dark"] #incident-update-modal input:focus,
                    html[data-theme="dark"] #incident-update-modal select:focus { border-color:#60a5fa !important;box-shadow:0 0 0 3px rgba(96,165,250,0.18) !important; }
                    html[data-theme="dark"] #modal-save-btn { background:#2563eb !important;color:#eff6ff !important;border-color:#2563eb !important; }
                    html[data-theme="dark"] #modal-save-btn:hover { background:#1d4ed8 !important; }
                    html[data-theme="dark"] #modal-cancel-btn { background:#111827 !important;color:#f8fafc !important;border-color:#475569 !important; }
                    html[data-theme="dark"] #modal-cancel-btn:hover { background:#1f2937 !important;color:#ffffff !important;border-color:#64748b !important; }
                `;
                document.head.appendChild(style);
            }
            modal.style.display = 'flex';
            const resolvedIncidentCode = String(incident.incident_code || incident.reference_no || '').trim();
            document.getElementById('modal-incident-id').value = String(Number(incident.id || 0));
            document.getElementById('modal-incident-code').value = resolvedIncidentCode;
            document.getElementById('modal-type-input').value = incident.type || '';
            document.getElementById('modal-priority-input').value = (incident.priority || 'low').toLowerCase();
            document.getElementById('modal-desc-input').value = incident.description || '';
            document.getElementById('modal-location-input').value = incident.location || incident.location_address || '';
            if (typeof window.attachPlaceAutocomplete === 'function') {
                window.attachPlaceAutocomplete('modal-location-input');
            }
            document.getElementById('modal-status-input').value = normalizeIncidentStatus(incident.status || 'pending');
            // Add Leaflet map picker for location
            function initIncidentMap() {
                if (window.L && document.getElementById('incident-location-map')) {
                    var map = L.map('incident-location-map').setView([14.6760, 121.0437], 13);
                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '© OpenStreetMap contributors'
                    }).addTo(map);
                    var marker;
                    // If existing location, show marker
                    var locVal = document.getElementById('modal-location-input').value;
                    if (locVal && locVal.match(/\d+\.\d+,[ ]*\d+\.\d+/)) {
                        var coords = locVal.split(',');
                        marker = L.marker([parseFloat(coords[0]), parseFloat(coords[1])]).addTo(map);
                        map.setView([parseFloat(coords[0]), parseFloat(coords[1])], 15);
                    }
                    map.on('click', function(e) {
                        var latlng = e.latlng.lat.toFixed(6) + ',' + e.latlng.lng.toFixed(6);
                        document.getElementById('modal-location-input').value = latlng;
                        if (marker) {
                            marker.setLatLng(e.latlng);
                        } else {
                            marker = L.marker(e.latlng).addTo(map);
                        }
                    });
                }
            }
            setTimeout(function() {
                if (window.L) initIncidentMap();
            }, 400);

            // Cancel button
            document.getElementById('modal-cancel-btn').onclick = function() {
                modal.style.display = 'none';
            };
            const modalBackdrop = modal.querySelector('.modal-backdrop');
            if (modalBackdrop) {
                modalBackdrop.onclick = function() {
                    modal.style.display = 'none';
                };
            }
            // Save button (form submit)
            document.getElementById('modal-update-form').onsubmit = async function(e) {
                e.preventDefault();
                const saveBtn = document.getElementById('modal-save-btn');
                const modalIncidentId = Number(document.getElementById('modal-incident-id').value || '0');
                const modalIncidentCode = String(document.getElementById('modal-incident-code').value || '').trim();
                const payload = {
                    id: modalIncidentId,
                    incident_code: modalIncidentCode,
                    type: document.getElementById('modal-type-input').value.trim(),
                    priority: document.getElementById('modal-priority-input').value.trim().toLowerCase(),
                    description: document.getElementById('modal-desc-input').value.trim(),
                    location_address: document.getElementById('modal-location-input').value.trim(),
                    status: normalizeIncidentStatus(document.getElementById('modal-status-input').value)
                };
                if (payload.id <= 0 && !payload.incident_code) {
                    showNotification('Unable to update: missing incident identifier. Please refresh the page.', 'error');
                    return;
                }
                if (!payload.type || !payload.priority || !payload.description) {
                    showNotification('Type, priority, and description are required', 'error');
                    return;
                }

                if (saveBtn) saveBtn.disabled = true;
                try {
                    const wasResolved = normalizeIncidentStatus(incident.status) === 'resolved';
                    if (payload.status === 'resolved' && !wasResolved) {
                        const updateBody = {
                            incident_code: payload.incident_code,
                            type: payload.type,
                            priority: payload.priority,
                            description: payload.description,
                            location_address: payload.location_address
                        };
                        if (payload.id > 0) updateBody.id = payload.id;
                        const updateRes = await fetch(API_UPDATE_URL, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(updateBody)
                        });
                        const updateJson = await updateRes.json();
                        if (!(updateJson && updateJson.ok)) {
                            throw new Error((updateJson && updateJson.error) ? String(updateJson.error) : 'Update failed');
                        }

                        const resolveBody = {
                            incident_code: payload.incident_code,
                            note: `Resolved via Incident modal at ${new Date().toLocaleString()}`
                        };
                        if (payload.id > 0) resolveBody.incident_id = payload.id;
                        const resolveRes = await fetch(API_RESOLVE_URL, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(resolveBody)
                        });
                        const resolveJson = await resolveRes.json();
                        if (!(resolveJson && resolveJson.ok)) {
                            throw new Error((resolveJson && resolveJson.error) ? String(resolveJson.error) : 'Resolve failed');
                        }
                    } else {
                        const updateRes = await fetch(API_UPDATE_URL, {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify(payload)
                        });
                        const updateJson = await updateRes.json();
                        if (!(updateJson && updateJson.ok)) {
                            throw new Error((updateJson && updateJson.error) ? String(updateJson.error) : 'Update failed');
                        }
                    }

                    incident.type = payload.type;
                    incident.priority = payload.priority;
                    incident.description = payload.description;
                    incident.location = payload.location_address;
                    incident.location_address = payload.location_address;
                    incident.status = payload.status;
                    renderDynamicIncidents();
                    showNotification('Incident updated successfully', 'success');
                    try { localStorage.setItem('ers_incidents_changed', String(Date.now())); } catch (e) {}
                    try { await fetchIncidents(); } catch (e) {}
                    modal.style.display = 'none';
                } catch (err) {
                    showNotification(`Update failed: ${err.message || 'Unknown error'}`, 'error');
                } finally {
                    if (saveBtn) saveBtn.disabled = false;
                }
            };
        }
    </script>

    <script>
    // Handle URL params for deep linking from reports or anonymous tips
    document.addEventListener('DOMContentLoaded', () => {
        try {
            const params = new URLSearchParams(window.location.search);
            const code = params.get('code') || params.get('reference_no') || '';
            const incidentId = Number(params.get('incident_id') || params.get('id') || 0);
            const period = params.get('period');
            if (code || incidentId > 0) {
                const targetRef = code.trim().toLowerCase();
                window.setTimeout(() => {
                    const matchedCard = (incidentId > 0 ? document.querySelector(`[data-id="${incidentId}"]`) : null)
                        || (targetRef ? document.querySelector(`[data-ref="${targetRef}"]`) : null)
                        || Array.from(document.querySelectorAll('[data-incident-row]')).find(row => {
                            const ref = (row.getAttribute('data-ref') || '').toLowerCase();
                            return targetRef && ref.includes(targetRef);
                        });
                    if (matchedCard) {
                        matchedCard.scrollIntoView({ behavior: 'smooth', block: 'center' });
                        matchedCard.style.outline = '2px solid #2563eb';
                        matchedCard.style.outlineOffset = '3px';
                        window.setTimeout(() => {
                            matchedCard.style.outline = '';
                            matchedCard.style.outlineOffset = '';
                        }, 4000);
                    }
                    showNotification(
                        code ? `Viewing incident ${code}` : 'Incident loaded in queue.',
                        'info'
                    );
                }, 350);
            }
            if (period) {
                console.log('Incident view period:', period);
            }
        } catch (e) {}
    });
    </script>

    <script>
        // Resolved incidents modal: list + per-incident details
        function openResolvedModal() {
            let modal = document.getElementById('incident-resolved-modal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'incident-resolved-modal';
                modal.innerHTML = `
                    <div class="modal-backdrop"></div>
                    <div class="modal-content">
                        <h3 class="resolved-modal-title">Resolved Incidents</h3>
                        <button type="button" id="resolved-close-btn" aria-label="Close" title="Close">&times;</button>
                        <div id="resolved-controls" class="resolved-controls">
                            <input id="resolved-search" type="text" placeholder="Search reference, type, location">
                            <input id="resolved-date" type="date">
                            <input id="resolved-month" type="month">
                            <button id="resolved-clear" title="Clear filters">Clear Filters</button>
                        </div>
                        <div id="resolved-list"></div>
                        <div id="resolved-details">
                            <div class="resolved-helper">Select an incident and click Details to view more info.</div>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);
                const style = document.createElement('style');
                style.textContent = `
                    #incident-resolved-modal { position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:2002;display:flex;align-items:center;justify-content:center; }
                    #incident-resolved-modal .modal-backdrop { position:absolute;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.45); backdrop-filter: blur(2px); }
                    #incident-resolved-modal .modal-content { position:relative;z-index:1;background:#ffffff;padding:1.2em 1.2em 1.2em;border-radius:12px;box-shadow:0 12px 36px rgba(0,0,0,0.18);min-width:540px;max-width:960px; border:1px solid #e5e7eb; color:#0f172a; }
                    #incident-resolved-modal .modal-content h3 { margin:0 2.2rem 0 0; font-size:1.25rem; font-weight:700; color:#1f2d3d; padding-bottom:0.6em; border-bottom: 1px solid #f0f0f0; }
                    #resolved-close-btn { position:absolute; top:12px; right:14px; width:34px; height:34px; line-height:32px; text-align:center; font-size:22px; border-radius:8px; border:1px solid #e5e5e5; cursor:pointer; background:#fafafa; color:#333; transition: all 0.2s ease; }
                    #resolved-close-btn:hover { background:#efefef; box-shadow:0 2px 8px rgba(0,0,0,0.08); }
                    .resolved-controls { margin-top:0.8em; display:flex; gap:0.6em; flex-wrap:wrap; align-items:center; }
                    .resolved-controls input[type="text"],
                    .resolved-controls input[type="date"],
                    .resolved-controls input[type="month"] { padding:0.55em 0.7em; border:1px solid #dcdcdc; border-radius:8px; font-size:0.95rem; outline:none; transition: border-color 0.2s ease, box-shadow 0.2s ease; background:#ffffff; color:#0f172a; }
                    .resolved-controls input[type="text"] { flex:1; min-width:220px; }
                    .resolved-controls input:focus { border-color:#3399ff; box-shadow:0 0 0 3px rgba(51,153,255,0.12); }
                    #resolved-clear { padding:0.55em 0.9em; border:1px solid #e5e5e5; border-radius:8px; background:#f7f7f7; color:#333; cursor:pointer; font-weight:600; }
                    #resolved-clear:hover { background:#efefef; }

                    #resolved-list { margin-top:0.8em; max-height:340px; overflow:auto; border:1px solid #eee; border-radius:10px; background:#fbfbfb; }
                    .resolved-item { display:flex; align-items:center; justify-content:space-between; gap:0.75em; padding:0.8em 1.0em; border-bottom:1px solid #eaeaea; transition: background 0.15s ease; }
                    .resolved-item:hover { background:#f7faff; }
                    .resolved-item:last-child { border-bottom:none; }
                    .resolved-main { display:flex; flex-wrap:wrap; gap:0.6em; color:#2b2b2b; align-items:center; }
                    .resolved-main .ref { font-weight:700; color:#1a1a1a; }
                    .resolved-main .type { color:#555; }
                    .resolved-main .meta { color:#777; font-size:0.92rem; }
                    .badge { display:inline-flex; align-items:center; gap:0.35em; padding:0.25em 0.55em; border-radius:999px; font-size:0.86rem; font-weight:600; }
                    .badge-resolved { background:#e9f7ef; color:#1e7e34; border:1px solid #d4edda; }
                    .badge-type { background:#eef2ff; color:#3730a3; border:1px solid #e0e7ff; }
                    .resolved-actions .btn-resolved-details { padding:0.5em 0.95em; font-size:0.95em; border-radius:8px; border:1px solid #0a64d2; cursor:pointer; background:#0b74ff; color:#fff; font-weight:600; transition: all 0.2s ease; }
                    .resolved-actions .btn-resolved-details:hover { background:#085fd1; box-shadow:0 2px 8px rgba(11,116,255,0.25); }

                    #resolved-details { margin-top:1em; padding:0.9em; border:1px solid #eee; border-radius:10px; background:#fff; }
                    .details-header { display:flex; align-items:center; justify-content:space-between; gap:0.75em; padding-bottom:0.5em; border-bottom:1px solid #f0f0f0; }
                    .details-header .title { font-weight:700; color:#1f2d3d; }
                    .details-grid { display:grid; grid-template-columns: 1fr 1fr; gap:0.75em 1.2em; margin-top:0.8em; }
                    .details-grid .detail { background:#fafafa; border:1px solid #f0f0f0; border-radius:8px; padding:0.6em 0.7em; }
                    .details-grid .label { font-size:0.85rem; color:#666; margin-bottom:0.2em; }
                    .details-grid .value { color:#222; font-weight:600; }
                    .resolved-helper { color:#64748b; }

                    /* Scrollbar polish */
                    #resolved-list::-webkit-scrollbar { width:10px; height:10px; }
                    #resolved-list::-webkit-scrollbar-thumb { background:#ddd; border-radius:10px; }
                    #resolved-list::-webkit-scrollbar-thumb:hover { background:#ccc; }

                    html[data-theme="dark"] #incident-resolved-modal .modal-content { background:#000000 !important; border-color:#334155 !important; color:#f8fafc !important; box-shadow:0 18px 42px rgba(2,6,23,0.55) !important; }
                    html[data-theme="dark"] #incident-resolved-modal .modal-content h3,
                    html[data-theme="dark"] #incident-resolved-modal .details-header .title,
                    html[data-theme="dark"] #incident-resolved-modal .resolved-main,
                    html[data-theme="dark"] #incident-resolved-modal .resolved-main .ref,
                    html[data-theme="dark"] #incident-resolved-modal .details-grid .value { color:#f8fafc !important; border-color:#334155 !important; }
                    html[data-theme="dark"] #incident-resolved-modal .modal-content h3,
                    html[data-theme="dark"] #incident-resolved-modal .details-header { border-color:#334155 !important; }
                    html[data-theme="dark"] #resolved-close-btn { background:#0f172a !important; color:#f8fafc !important; border-color:#334155 !important; }
                    html[data-theme="dark"] #resolved-close-btn:hover { background:#172033 !important; }
                    html[data-theme="dark"] .resolved-controls input[type="text"],
                    html[data-theme="dark"] .resolved-controls input[type="date"],
                    html[data-theme="dark"] .resolved-controls input[type="month"] { background:#0f172a !important; color:#f8fafc !important; border-color:#475569 !important; }
                    html[data-theme="dark"] .resolved-controls input::placeholder { color:#94a3b8 !important; }
                    html[data-theme="dark"] #resolved-clear { background:#111827 !important; color:#f8fafc !important; border-color:#334155 !important; }
                    html[data-theme="dark"] #resolved-clear:hover { background:#172033 !important; }
                    html[data-theme="dark"] #resolved-list,
                    html[data-theme="dark"] #resolved-details { background:#000000 !important; border-color:#334155 !important; color:#f8fafc !important; }
                    html[data-theme="dark"] .resolved-item { border-bottom-color:#1f2937 !important; }
                    html[data-theme="dark"] .resolved-item:hover { background:#111827 !important; }
                    html[data-theme="dark"] .resolved-main .type,
                    html[data-theme="dark"] .resolved-main .meta,
                    html[data-theme="dark"] .details-grid .label,
                    html[data-theme="dark"] .resolved-helper { color:#94a3b8 !important; }
                    html[data-theme="dark"] .details-grid .detail { background:#0f172a !important; border-color:#1f2937 !important; }
                    html[data-theme="dark"] .badge-type { background:#172554 !important; color:#dbeafe !important; border-color:#1d4ed8 !important; }
                    html[data-theme="dark"] .badge-resolved { background:#052e16 !important; color:#bbf7d0 !important; border-color:#166534 !important; }
                    html[data-theme="dark"] #resolved-list::-webkit-scrollbar-thumb { background:#475569 !important; }
                    html[data-theme="dark"] #resolved-list::-webkit-scrollbar-thumb:hover { background:#64748b !important; }
                `;
                document.head.appendChild(style);
                // Close handler
                document.getElementById('resolved-close-btn').onclick = function() {
                    modal.style.display = 'none';
                };
                const backdrop = modal.querySelector('.modal-backdrop');
                if (backdrop) {
                    backdrop.addEventListener('click', function() {
                        modal.style.display = 'none';
                    });
                }
                // Details click delegation
                modal.addEventListener('click', function(e) {
                    const btn = e.target.closest('button');
                    if (!btn) return;
                    if (btn.classList.contains('btn-resolved-details')) {
                        const id = Number(btn.getAttribute('data-id') || '0');
                        if (id > 0) loadResolvedDetails(id);
                    }
                });
            }
            modal.style.display = 'flex';
            const listEl = document.getElementById('resolved-list');
            const detailsEl = document.getElementById('resolved-details');
            const controlsEl = document.getElementById('resolved-controls');
            listEl.innerHTML = '<div class="resolved-item"><div>Loading resolved incidents...</div></div>';
            detailsEl.innerHTML = '<div class="resolved-helper">Select an incident and click Details to view more info.</div>';

            // Wire filters once
            if (controlsEl && !controlsEl.dataset.wired) {
                const searchInput = document.getElementById('resolved-search');
                const dateInput = document.getElementById('resolved-date');
                const monthInput = document.getElementById('resolved-month');
                let searchTimer = null;
                const scheduleSearch = () => {
                    if (searchTimer) clearTimeout(searchTimer);
                    searchTimer = setTimeout(loadResolvedList, 250);
                };
                if (searchInput) searchInput.addEventListener('input', scheduleSearch);
                if (dateInput) dateInput.addEventListener('change', () => { if (dateInput.value) { if (monthInput) monthInput.value = ''; } loadResolvedList(); });
                if (monthInput) monthInput.addEventListener('change', () => { if (monthInput.value) { if (dateInput) dateInput.value = ''; } loadResolvedList(); });
                const clearBtn = document.getElementById('resolved-clear');
                if (clearBtn) clearBtn.addEventListener('click', () => {
                    if (searchInput) searchInput.value = '';
                    if (dateInput) dateInput.value = '';
                    if (monthInput) monthInput.value = '';
                    loadResolvedList();
                });
                controlsEl.dataset.wired = '1';
            }

            function loadResolvedList() {
                const searchVal = (document.getElementById('resolved-search')?.value || '').trim();
                const dayVal = document.getElementById('resolved-date')?.value || '';
                const monthVal = document.getElementById('resolved-month')?.value || '';
                const params = new URLSearchParams();
                params.append('status', 'resolved');
                if (searchVal) params.append('search', searchVal);
                if (dayVal) params.append('day', dayVal); else if (monthVal) params.append('month', monthVal);
                fetch(API_LIST_URL + '?' + params.toString())
                    .then(r => r.json())
                    .then(data => {
                        if (data && data.ok) {
                            const items = data.items || [];
                            if (!items.length) {
                                listEl.innerHTML = '<div class="resolved-item"><div>No resolved incidents.</div></div>';
                            } else {
                                listEl.innerHTML = items.map(i => {
                                    const ref = (i.incident_code || i.reference_no || '').toString();
                                    const type = (i.type || '').toString();
                                    const created = new Date(i.created_at || Date.now()).toLocaleString();
                                    return `
                                        <div class="resolved-item">
                                            <div class="resolved-main">
                                                <span class="ref">${ref}</span>
                                                <span class="badge badge-type">${type || '—'}</span>
                                                <span class="badge badge-resolved"><i class="fas fa-check-circle"></i> Resolved</span>
                                                <span class="meta"><i class="fas fa-clock"></i> Created: ${created}</span>
                                            </div>
                                            <div class="resolved-actions">
                                                <button class="btn-resolved-details" data-id="${i.id}"><i class="fas fa-info-circle"></i> Details</button>
                                            </div>
                                        </div>
                                    `;
                                }).join('');
                            }
                        } else {
                            listEl.innerHTML = '<div class="resolved-item"><div>Failed to load resolved incidents.</div></div>';
                        }
                    })
                    .catch(() => {
                        listEl.innerHTML = '<div class="resolved-item"><div>Network error while loading resolved incidents.</div></div>';
                    });
            }

            RESOLVED_LIST_RELOAD = loadResolvedList;
            loadResolvedList();
        }

        function loadResolvedDetails(id) {
            const detailsEl = document.getElementById('resolved-details');
            detailsEl.innerHTML = '<div class="resolved-helper">Loading details...</div>';
            fetch('api/incident_details.php?id=' + encodeURIComponent(id))
                .then(r => r.json())
                .then(data => {
                    const inc = data && data.incident ? data.incident : null;
                    if (!inc) {
                        detailsEl.innerHTML = '<div class="resolved-helper">Details not available.</div>';
                        return;
                    }
                    const safe = v => (v === null || v === undefined) ? '' : String(v).replace(/</g,'&lt;');
                    const resolvedAt = inc.resolved_at ? new Date(inc.resolved_at).toLocaleString() : '—';
                    const createdAt = inc.created_at ? new Date(inc.created_at).toLocaleString() : '—';
                    const updatedAt = inc.updated_at ? new Date(inc.updated_at).toLocaleString() : '—';
                    detailsEl.innerHTML = `
                        <div class="details-header">
                            <div class="title"><i class="fas fa-hashtag"></i> ${safe(inc.reference_no)} — ${safe(inc.type)}</div>
                            <span class="badge badge-resolved"><i class="fas fa-check-circle"></i> Resolved</span>
                        </div>
                        <div class="details-grid">
                            <div class="detail"><div class="label">Priority</div><div class="value">${safe(inc.priority)}</div></div>
                            <div class="detail"><div class="label">Status</div><div class="value">${safe(inc.status)}</div></div>
                            <div class="detail"><div class="label">Created At</div><div class="value">${createdAt}</div></div>
                            <div class="detail"><div class="label">Resolved At</div><div class="value">${resolvedAt}</div></div>
                            <div class="detail"><div class="label">Last Updated</div><div class="value">${updatedAt}</div></div>
                            <div class="detail"><div class="label">Location</div><div class="value">${safe(inc.location_address)}</div></div>
                            <div class="detail" style="grid-column:1 / -1"><div class="label">Description</div><div class="value">${safe(inc.description)}</div></div>
                        </div>
                    `;
                })
                .catch(() => {
                    detailsEl.innerHTML = '<div class="resolved-helper">Network error while loading details.</div>';
                });
        }
    </script>
</body>
</html>
