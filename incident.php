<?php
require_once __DIR__ . '/includes/auth.php';
// Require full login (including OTP verification) before loading page
require_login('incident.php');
require_once __DIR__ . '/includes/db.php';

$pageTitle = 'Incident Priority Management';
$aiIncidentData = [
    'type' => 'Unknown',
    'location' => 'Unknown',
    'description' => 'No active incident data',
    'severity' => 'Unknown',
];

try {
    $pdo = get_db_connection();
    if ($pdo) {
        $incident = $pdo->query("SELECT type, location_address, description, priority
                                 FROM incidents
                                 WHERE status IN ('pending','dispatched','active','in_progress')
                                 ORDER BY FIELD(LOWER(priority),'critical','high','medium','low'), created_at DESC
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
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="CSS/cards.css">
    <link rel="stylesheet" href="css/incident.css">

</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include 'includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Incident Priority Management
       =================================== -->
    <div class="main-content">
        <div class="main-container">

            <div style="height: 3.5rem;"></div>

            <!-- Statistics Cards -->
            <div class="stats-cards">
                <div class="stats-card">
                    <div class="stats-icon high">
                        <i class="fas fa-fire"></i>
                    </div>
                    <div class="stats-content">
                        <h3>12</h3>
                        <p>High Priority Incidents</p>
                    </div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon medium">
                        <i class="fas fa-ambulance"></i>
                    </div>
                    <div class="stats-content">
                        <h3>8</h3>
                        <p>Medium Priority Incidents</p>
                    </div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon low">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div class="stats-content">
                        <h3>15</h3>
                        <p>Low Priority Incidents</p>
                    </div>
                </div>
                <div class="stats-card">
                    <div class="stats-icon resolved">
                        <i class="fas fa-check-circle"></i>
                    </div>
                    <div class="stats-content">
                        <h3>0</h3>
                        <p>Resolved Incidents</p>
                    </div>
                </div>
            </div>

            <!-- Filters Section -->
            <div class="filters-section">
                <h2 class="section-title" style="font-size: 1.2rem; margin-bottom: 1rem;">
                    <i class="fas fa-filter"></i>
                    Filter Incidents
                </h2>
                <div class="filters-grid">
                    <div class="filter-group">
                        <label for="priority-filter">Priority Level</label>
                        <select id="priority-filter">
                            <option value="">All Priorities</option>
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
                    <div class="filter-group">
                        <label for="search">Search</label>
                        <input type="text" id="search" placeholder="Search incidents...">
                    </div>
                </div>
            </div>

            <!-- Logged Incidents (Dynamic) -->
            <div class="priority-section" style="margin-top: 1.5rem;">
                <div class="section-header" style="display:flex;align-items:center;justify-content:space-between;gap:1rem;">
                    <h2 class="section-title" style="font-size: 1.2rem; margin:0;">
                        <i class="fas fa-list"></i>
                        Logged Incidents
                    </h2>
                    <button id="btn-view-resolved" class="btn-action" title="Show all resolved incidents">View Resolved</button>
                </div>
                <div id="incident-list-dynamic"></div>
                <!-- Table will be rendered here by JS -->
                <div id="incident-list-dynamic"></div>
            </div>

            <!-- AI-Powered Incident Analysis -->
            <div class="ai-analysis-section">
                <div class="ai-analysis-card">
                    <div class="ai-analysis-header">
                        <h2><i class="fas fa-brain"></i> AI Incident Analysis</h2>
                        <span class="ai-badge"><i class="fas fa-robot"></i> Powered by Gemini AI</span>
                    </div>
                    <div class="ai-analysis-content" id="ai-analysis-content">
                        <?php
                        include 'includes/gemini_helper.php';
                        $analysis = analyzeIncident($aiIncidentData);
                        if ($analysis) {
                            echo '<div class="ai-analysis-text">' . nl2br(htmlspecialchars($analysis)) . '</div>';
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
            </div>

        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php include('includes/admin-footer.php'); ?>

    <script>
        // Incident Priority Management Functionality
        let INCIDENTS = [];
        let REFRESH_TIMER = null;
        const API_LIST_URL = 'api/incidents_list.php';

        // Priority change functionality
        document.querySelectorAll('.btn-priority').forEach(button => {
            button.addEventListener('click', function() {
                const incidentCard = this.closest('.incident-card');
                const currentPriority = incidentCard.classList.contains('priority-high') ? 'high' :
                                      incidentCard.classList.contains('priority-medium') ? 'medium' : 'low';

                // Cycle through priorities: high -> medium -> low -> high
                let newPriority;
                if (currentPriority === 'high') {
                    newPriority = 'medium';
                } else if (currentPriority === 'medium') {
                    newPriority = 'low';
                } else {
                    newPriority = 'high';
                }

                // Update card styling
                incidentCard.classList.remove('priority-high', 'priority-medium', 'priority-low');
                incidentCard.classList.add(`priority-${newPriority}`);

                // Update button styling and text
                this.className = `btn-priority btn-${newPriority}`;
                this.textContent = `${newPriority.charAt(0).toUpperCase() + newPriority.slice(1)} Priority`;

                // Show confirmation
                showNotification(`Incident priority changed to ${newPriority.toUpperCase()}`, 'success');
            });
        });

        // Resolve incident functionality

        // Event delegation for all action buttons in the table
        document.addEventListener('click', function(e) {
            // Find the button and row
            const btn = e.target.closest('button');
            if (!btn) return;
            const tr = btn.closest('tr');
            if (!tr || !tr.hasAttribute('data-ref')) return;
            const ref = tr.getAttribute('data-ref');
            const incident = INCIDENTS.find(i => (i.incident_code || i.reference_no || '') == ref);
            if (!incident) return;

            // Priority button
            if (btn.classList.contains('btn-priority')) {
                // Cycle through priorities: high -> medium -> low -> high
                let current = (incident.priority || 'low').toLowerCase();
                let newPriority = current === 'high' ? 'medium' : (current === 'medium' ? 'low' : 'high');
                const prevPriority = incident.priority;
                // Optimistic UI update
                incident.priority = newPriority;
                renderDynamicIncidents();

                const incidentId = incident.id || null;
                if (incidentId) {
                    fetch('api/incident_update.php', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ id: incidentId, priority: newPriority })
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
            if (btn.querySelector('.fa-edit')) {
                showUpdateModal(incident);
                return;
            }

            // Contact button
            if (btn.querySelector('.fa-phone')) {
                // Try to get a phone number from incident (if available)
                let phone = '';
                if (incident.caller_phone) phone = incident.caller_phone;
                else if (incident.contact) phone = incident.contact;
                else if (incident.description) {
                    const match = incident.description.match(/(\+?\d{1,3}[-.\s]?\d{1,4}[-.\s]?\d{1,4}[-.\s]?\d{1,4})/);
                    if (match) phone = match[1];
                }
                if (phone) {
                    if (confirm(`Call ${phone}?`)) {
                        showNotification(`Initiating call to ${phone}`, 'info');
                    }
                } else {
                    showNotification('Phone number not found', 'error');
                }
                return;
            }

            // Resolve button
            if (btn.querySelector('.fa-check')) {
                if (confirm('Are you sure you want to resolve this incident?')) {
                    const incidentId = incident.id || null;
                    const note = `Resolved via UI at ${new Date().toLocaleString()}`;
                    if (incidentId) {
                        fetch('api/incident_resolve.php', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({ incident_id: incidentId, note })
                        })
                        .then(r => r.json())
                        .then(res => {
                            if (res && res.ok) {
                                incident.status = 'resolved';
                                renderDynamicIncidents();
                                showNotification('Incident resolved. Units released to available.', 'success');
                            } else {
                                showNotification('Failed to resolve incident', 'error');
                            }
                        })
                        .catch(() => showNotification('Network error', 'error'));
                    } else {
                        // Fallback: update UI only
                        incident.status = 'resolved';
                        renderDynamicIncidents();
                        showNotification('Incident marked as resolved (local)', 'info');
                    }
                }
                return;
            }
        });

        // Contact functionality
        document.querySelectorAll('.btn-action .fa-phone').forEach(icon => {
            icon.parentElement.addEventListener('click', function() {
                const incidentCard = this.closest('.incident-card');
                const callerInfo = incidentCard.querySelector('.detail-value').textContent;
                const phoneMatch = callerInfo.match(/(\+?\d{1,3}[-.\s]?\d{1,4}[-.\s]?\d{1,4}[-.\s]?\d{1,4})/);

                if (phoneMatch) {
                    const phoneNumber = phoneMatch[1];
                    if (confirm(`Call ${phoneNumber}?`)) {
                        // In a real system, this would initiate a phone call
                        showNotification(`Initiating call to ${phoneNumber}`, 'info');
                    }
                } else {
                    showNotification('Phone number not found', 'error');
                }
            });
        });

        // AI refresh for incident analysis
        function refreshAIAnalysis() {
            const container = document.getElementById('ai-analysis-content');
            container.innerHTML = '<div class="ai-loading"><i class="fas fa-spinner"></i> Generating analysis...</div>';
            fetch('api/ai_incident_analysis.php')
            .then(r => r.json())
            .then(json => {
                if (json.ok && json.text) {
                    container.innerHTML = '<div class="ai-analysis-text">' + String(json.text).replace(/\n/g,'<br>') + '</div>';
                } else {
                    const msg = (json && json.error) ? String(json.error) : 'Unable to generate AI analysis at this time.';
                    container.innerHTML = '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> ' + msg.replace(/\n/g,'<br>') + '</div>';
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
            if (e.key === 'ers_incidents') {
                refreshAIAnalysis();
            }
        });

        // Update incident functionality
        document.querySelectorAll('.btn-action .fa-edit').forEach(icon => {
            icon.parentElement.addEventListener('click', function() {
                const incidentCard = this.closest('.incident-card');
                const incidentTitle = incidentCard.querySelector('.incident-title').textContent;

                // Simple update dialog (in a real system, this would open a modal)
                const newDescription = prompt('Update incident description:', incidentTitle);
                if (newDescription && newDescription !== incidentTitle) {
                    incidentCard.querySelector('.incident-title').textContent = newDescription;
                    showNotification('Incident updated successfully', 'success');
                }
            });
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
        });

        // Update statistics
        function updateStats() {
            // Count based on currently filtered incidents
            const filtered = INCIDENTS.filter(passFilters);
            let highCount = 0, mediumCount = 0, lowCount = 0;
            filtered.forEach(i => {
                const p = (i.priority || 'low').toLowerCase();
                if (p === 'high') highCount++;
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

        function capitalize(s) { return (s || '').charAt(0).toUpperCase() + (s || '').slice(1); }

        function incidentCardHtml(i) {
            const priority = (i.priority || 'low').toLowerCase();
            const statusInfo = mapStatusToBadge(i.status);
            const created = new Date(i.created_at || Date.now());
            const location = i.location || i.location_address || 'Unknown location';
            const ref = i.incident_code || i.reference_no || '';
            return `
                <tr class="priority-${priority}" data-ref="${ref}">
                    <td>${ref}</td>
                    <td>${capitalize(i.type)}</td>
                    <td>${capitalize(priority)}</td>
                    <td>${(i.description || '').substring(0, 60)}${(i.description||'').length>60?'...':''}</td>
                    <td><span class="status-badge ${statusInfo.cls}">${statusInfo.label}</span></td>
                    <td>${location}</td>
                    <td>${created.toLocaleString()}</td>
                    <td>
                        <button class="btn-priority btn-${priority}">${capitalize(priority)} Priority</button>
                        <button class="btn-action"><i class="fas fa-edit"></i></button>
                        <button class="btn-action"><i class="fas fa-phone"></i></button>
                        <button class="btn-action"><i class="fas fa-check"></i></button>
                    </td>
                </tr>
            `;
        }

        function passFilters(i) {
            const priorityValue = (priorityFilter.value || '').toLowerCase();
            const statusValue = (statusFilter.value || '').toLowerCase();
            const typeValue = (typeFilter.value || '').toLowerCase();
            const searchValue = currentSearch.trim().toLowerCase();

            if (priorityValue && (i.priority || '').toLowerCase() !== priorityValue) return false;

            // Default: exclude resolved incidents from logged list unless explicitly filtered
            {
                const s = (i.status || '').toLowerCase();
                const mapped = s === 'dispatched' ? 'dispatched' : (s === 'resolved' || s === 'cancelled' ? 'resolved' : 'active');
                if (!statusValue && mapped === 'resolved') return false; // hide resolved by default
                if (statusValue && mapped !== statusValue) return false;   // respect explicit filter selection
            }

            if (typeValue && (i.type || '').toLowerCase() !== typeValue) return false;

            if (searchValue) {
                const hay = [i.reference_no, i.type, i.location, i.location_address, i.description]
                    .map(v => (v || '').toString().toLowerCase()).join(' ');
                if (!hay.includes(searchValue)) return false;
            }
            return true;
        }

        function renderDynamicIncidents() {
            const container = document.getElementById('incident-list-dynamic');
            if (!container) return;
            const filtered = INCIDENTS.filter(passFilters);
            if (!filtered.length) {
                container.innerHTML = '<div class="incident-card empty">No incidents yet. Logged calls will appear here.</div>';
            } else {
                let table = `<style>
                    .incident-table-wrapper {
                        width: 100%;
                        overflow-x: auto;
                        max-height: 420px;
                        border-radius: 10px;
                        box-shadow: 0 2px 8px rgba(0,0,0,0.04);
                        background: #fff;
                        margin-bottom: 1em;
                    }
                    .incident-table {
                        width: 100%;
                        border-collapse: collapse;
                        font-size: 1.08rem;
                        min-width: 900px;
                    }
                    .incident-table th, .incident-table td {
                        padding: 0.85em 1.1em;
                        border: 1px solid #e0e0e0;
                        text-align: left;
                    }
                    .incident-table th {
                        background: #f7f7f7;
                        font-size: 1.13rem;
                        position: sticky;
                        top: 0;
                        z-index: 2;
                    }
                    .incident-table tr:nth-child(even) {
                        background: #fafbfc;
                    }
                </style>
                <div class="incident-table-wrapper">
                  <table class=\"incident-table\">
                    <thead>
                        <tr>
                            <th>Reference No</th>
                            <th>Type</th>
                            <th>Priority</th>
                            <th>Description</th>
                            <th>Status</th>
                            <th>Location</th>
                            <th>Date/Time</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${filtered.map(incidentCardHtml).join('')}
                    </tbody>
                  </table>
                </div>`;
                container.innerHTML = table;
            }
            updateStats();
        }

        async function fetchIncidents() {
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
                } else {
                    INCIDENTS = [];
                }
            } catch (e) {
                console.warn('Failed to fetch incidents', e);
                INCIDENTS = [];
            }
            renderDynamicIncidents();
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

        // Add CSS animations
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
        });

        // Modal for updating incident description
        function showUpdateModal(incident) {
            let modal = document.getElementById('incident-update-modal');
            if (!modal) {
                modal = document.createElement('div');
                modal.id = 'incident-update-modal';
                // Ensure Leaflet CSS/JS is loaded
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
                            <lab el>Type<br>
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
                                    <option value="active">Active</option>
                                    <option value="dispatched">Dispatched</option>
                                    <option value="resolved">Resolved</option>
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
                    #incident-update-modal .modal-backdrop { position:absolute;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.35); }
                    #incident-update-modal .modal-content { position:relative;z-index:1;background:#fff;padding:2em 1.5em;border-radius:10px;box-shadow:0 8px 32px rgba(0,0,0,0.18);min-width:320px;max-width:95vw; }
                    #incident-update-modal h3 { margin-top:0; }
                    #incident-update-modal textarea, #incident-update-modal input, #incident-update-modal select { border:1px solid #ccc;border-radius:6px;padding:0.7em; font-size:1em; }
                    #incident-update-modal button { padding:0.5em 1.2em;font-size:1em;border-radius:6px;border:none;cursor:pointer; }
                    #modal-save-btn { background:#007bff;color:#fff; }
                    #modal-cancel-btn { background:#eee;color:#333; }
                    #modal-save-btn:hover { background:#0056b3; }
                    #modal-cancel-btn:hover { background:#ccc; }
                `;
                document.head.appendChild(style);
            }
            modal.style.display = 'flex';
            document.getElementById('modal-type-input').value = incident.type || '';
            document.getElementById('modal-priority-input').value = (incident.priority || 'low').toLowerCase();
            document.getElementById('modal-desc-input').value = incident.description || '';
            document.getElementById('modal-location-input').value = incident.location || incident.location_address || '';
            document.getElementById('modal-status-input').value = (incident.status || 'active').toLowerCase();
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
            // Save button (form submit)
            document.getElementById('modal-update-form').onsubmit = function(e) {
                e.preventDefault();
                incident.type = document.getElementById('modal-type-input').value;
                incident.priority = document.getElementById('modal-priority-input').value;
                incident.description = document.getElementById('modal-desc-input').value;
                let loc = document.getElementById('modal-location-input').value;
                if (incident.location !== undefined) incident.location = loc;
                else if (incident.location_address !== undefined) incident.location_address = loc;
                incident.status = document.getElementById('modal-status-input').value;
                renderDynamicIncidents();
                showNotification('Incident updated successfully', 'success');
                modal.style.display = 'none';
            };
        }
    </script>

    <script>
    // Handle URL params for deep linking from reports
    document.addEventListener('DOMContentLoaded', () => {
        try {
            const params = new URLSearchParams(window.location.search);
            const code = params.get('code');
            const period = params.get('period');
            if (code) {
                alert('Opening incident details for: ' + code);
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
                    <div class="modal-content" style="min-width:520px;max-width:860px;position:relative;">
                        <h3 style="margin:0 2.2rem 0 0;">Resolved Incidents</h3>
                        <button type="button" id="resolved-close-btn" aria-label="Close" title="Close">&times;</button>
                        <div id="resolved-controls" class="resolved-controls" style="margin-top:0.8em;display:flex;gap:0.6em;flex-wrap:wrap;align-items:center;">
                            <input id="resolved-search" type="text" placeholder="Search reference, type, location" style="flex:1;min-width:220px;padding:0.55em;border:1px solid #ddd;border-radius:6px;">
                            <input id="resolved-date" type="date" style="padding:0.5em;border:1px solid #ddd;border-radius:6px;">
                            <input id="resolved-month" type="month" style="padding:0.5em;border:1px solid #ddd;border-radius:6px;">
                            <button id="resolved-clear" title="Clear filters" style="padding:0.5em 0.9em;border:none;border-radius:6px;background:#f3f3f3;color:#333;cursor:pointer;">Clear Filters</button>
                        </div>
                        <div id="resolved-list" style="margin-top:0.8em;max-height:320px;overflow:auto;border:1px solid #eee;border-radius:8px;background:#fafafa;"></div>
                        <div id="resolved-details" style="margin-top:1em;padding:0.75em;border:1px solid #eee;border-radius:8px;background:#fff;">
                            <div style="color:#666;">Select an incident and click Details to view more info.</div>
                        </div>
                    </div>
                `;
                document.body.appendChild(modal);
                const style = document.createElement('style');
                style.textContent = `
                    #incident-resolved-modal { position:fixed;top:0;left:0;width:100vw;height:100vh;z-index:2002;display:flex;align-items:center;justify-content:center; }
                    #incident-resolved-modal .modal-backdrop { position:absolute;top:0;left:0;width:100vw;height:100vh;background:rgba(0,0,0,0.45); backdrop-filter: blur(2px); }
                    #incident-resolved-modal .modal-content { position:relative;z-index:1;background:#fff;padding:1.2em 1.2em 1.2em;border-radius:12px;box-shadow:0 12px 36px rgba(0,0,0,0.18);min-width:540px;max-width:960px; border:1px solid #eee; }
                    #incident-resolved-modal .modal-content h3 { font-size:1.25rem; font-weight:700; color:#1f2d3d; padding-bottom:0.6em; border-bottom: 1px solid #f0f0f0; }
                    #resolved-close-btn { position:absolute; top:12px; right:14px; width:34px; height:34px; line-height:32px; text-align:center; font-size:22px; border-radius:8px; border:1px solid #e5e5e5; cursor:pointer; background:#fafafa; color:#333; transition: all 0.2s ease; }
                    #resolved-close-btn:hover { background:#efefef; box-shadow:0 2px 8px rgba(0,0,0,0.08); }
                    .resolved-controls { margin-top:0.8em; display:flex; gap:0.6em; flex-wrap:wrap; align-items:center; }
                    .resolved-controls input[type="text"],
                    .resolved-controls input[type="date"],
                    .resolved-controls input[type="month"] { padding:0.55em 0.7em; border:1px solid #dcdcdc; border-radius:8px; font-size:0.95rem; outline:none; transition: border-color 0.2s ease, box-shadow 0.2s ease; }
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

                    /* Scrollbar polish */
                    #resolved-list::-webkit-scrollbar { width:10px; height:10px; }
                    #resolved-list::-webkit-scrollbar-thumb { background:#ddd; border-radius:10px; }
                    #resolved-list::-webkit-scrollbar-thumb:hover { background:#ccc; }
                `;
                document.head.appendChild(style);
                // Close handler
                document.getElementById('resolved-close-btn').onclick = function() {
                    modal.style.display = 'none';
                };
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
            detailsEl.innerHTML = '<div style="color:#666;">Select an incident and click Details to view more info.</div>';

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

            loadResolvedList();
        }

        function loadResolvedDetails(id) {
            const detailsEl = document.getElementById('resolved-details');
            detailsEl.innerHTML = '<div>Loading details...</div>';
            fetch('api/incident_details.php?id=' + encodeURIComponent(id))
                .then(r => r.json())
                .then(data => {
                    const inc = data && data.incident ? data.incident : null;
                    if (!inc) {
                        detailsEl.innerHTML = '<div>Details not available.</div>';
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
                    detailsEl.innerHTML = '<div>Network error while loading details.</div>';
                });
        }
    </script>
</body>
</html>
