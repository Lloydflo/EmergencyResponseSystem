<?php



$pageTitle = 'Inter-Agency Coordination';
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('dispatcher', 'dispatcher/interagency.php');
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?></title>
    <base href="../">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="css/cards.css">
    <link rel="stylesheet" href="css/interagency.css">
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include $rootDir . '/includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Inter-Agency Coordination Center
       =================================== -->
    <div class="main-content">
        <div class="main-container">

            <div style="height: 3.5rem;"></div>

            <!-- System Status Overview -->
            <div class="coordination-overview">
                <div class="status-card active-agencies">
                    <div class="status-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="status-content">
                        <h3></h3>
                        <p>Active Agencies</p>
                    </div>
                </div>
                <div class="status-card shared-incidents">
                    <div class="status-icon">
                        <i class="fas fa-share-alt"></i>
                    </div>
                    <div class="status-content">
                        <h3></h3>
                        <p>Shared Incidents</p>
                    </div>
                </div>
                
            </div>

            <!-- Main Coordination Grid -->
            <div class="coordination-grid">

                <!-- Agency Status Panel -->
                <div class="coordination-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <i class="fas fa-users-cog"></i>
                            Agency Status
                        </h2>
                        <div class="panel-controls">
                            <button class="btn-control" onclick="refreshAgencies()">
                                <i class="fas fa-sync"></i>
                            </button>
                            <button class="btn-control" onclick="addAgency()">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>

                    <div class="agency-list">
                        <div class="agency-card active">
                            <div class="agency-header">
                                <div class="agency-icon police">
                                    <i class="fas fa-shield-alt"></i>
                                </div>
                                <div class="agency-info">
                                    <h4>Metropolitan Police</h4>
                                    <span class="agency-status online">Online</span>
                                </div>
                            </div>
                            <div class="agency-details">
                                <div class="detail-item">
                                    <span class="label">Active Units:</span>
                                    <span class="value"></span>
                                </div>
                                <div class="detail-item">
                                    <span class="label">Response Time:</span>
                                    <span class="value"></span>
                                </div>
                            </div>
                            <div class="agency-actions">
                                <button class="btn-agency" onclick="contactAgency(this, 'police')">
                                    <i class="fas fa-phone"></i> Message
                                </button>
                                <button class="btn-agency" onclick="shareResource(this, 'police')">
                                    <i class="fas fa-share"></i> Share
                                </button>
                            </div>
                        </div>

                        <div class="agency-card active">
                            <div class="agency-header">
                                <div class="agency-icon fire">
                                    <i class="fas fa-fire-extinguisher"></i>
                                </div>
                                <div class="agency-info">
                                    <h4>City Fire Department</h4>
                                    <span class="agency-status online">Online</span>
                                </div>
                            </div>
                            <div class="agency-details">
                                <div class="detail-item">
                                    <span class="label">Active Units:</span>
                                    <span class="value"></span>
                                </div>
                                <div class="detail-item">
                                    <span class="label">Response Time:</span>
                                    <span class="value"></span>
                                </div>
                            </div>
                            <div class="agency-actions">
                                <button class="btn-agency" onclick="contactAgency(this, 'fire')">
                                    <i class="fas fa-phone"></i> Message
                                </button>
                                <button class="btn-agency" onclick="shareResource(this, 'fire')">
                                    <i class="fas fa-share"></i> Share
                                </button>
                            </div>
                        </div>

                        <div class="agency-card active">
                            <div class="agency-header">
                                <div class="agency-icon medical">
                                    <i class="fas fa-ambulance"></i>
                                </div>
                                <div class="agency-info">
                                    <h4>Regional EMS</h4>
                                    <span class="agency-status online">Online</span>
                                </div>
                            </div>
                            <div class="agency-details">
                                <div class="detail-item">
                                    <span class="label">Active Units:</span>
                                    <span class="value"></span>
                                </div>
                                <div class="detail-item">
                                    <span class="label">Response Time:</span>
                                    <span class="value"></span>
                                </div>
                            </div>
                            <div class="agency-actions">
                                <button class="btn-agency" onclick="contactAgency(this, 'medical')">
                                    <i class="fas fa-phone"></i> Message
                                </button>
                                <button class="btn-agency" onclick="shareResource(this, 'medical')">
                                    <i class="fas fa-share"></i> Share
                                </button>
                            </div>
                        </div>

                        <div class="agency-card standby">
                            <div class="agency-header">
                                <div class="agency-icon utility">
                                    <i class="fas fa-bolt"></i>
                                </div>
                                <div class="agency-info">
                                    <h4>Power Utility Co.</h4>
                                    <span class="agency-status standby">Standby</span>
                                </div>
                            </div>
                            <div class="agency-details">
                                <div class="detail-item">
                                    <span class="label">Available Crews:</span>
                                    <span class="value"></span>
                                </div>
                                <div class="detail-item">
                                    <span class="label">Equipment:</span>
                                    <span class="value">Ready</span>
                                </div>
                            </div>
                            <div class="agency-actions">
                                <button class="btn-agency" onclick="activateAgency(this, 'utility')">
                                    <i class="fas fa-play"></i> Activate
                                </button>
                                <button class="btn-agency" onclick="contactAgency(this, 'utility')">
                                    <i class="fas fa-phone"></i> Message
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Coordination Chat Panel -->
                <div class="coordination-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <i class="fas fa-comments"></i>
                            Coordination Chat
                        </h2>
                        <div class="panel-controls">
                            <button class="btn-control active" onclick="toggleChannel('all')" id="channel-all">
                                All
                            </button>
                            <button class="btn-control" onclick="toggleChannel('emergency')" id="channel-emergency">
                                Emergency
                            </button>
                            <button class="btn-control" onclick="toggleChannel('logistics')" id="channel-logistics">
                                Logistics
                            </button>
                            <select id="chat-filter-dept" class="agency-select" style="margin-left:0.5rem;">
                                <option value="all">All Departments</option>
                                <option value="police">Police</option>
                                <option value="fire">Fire</option>
                                <option value="medical">EMS</option>
                                <option value="coordinator">Coordinator</option>
                            </select>
                        </div>
                    </div>

                    <div class="chat-container">
                        <div class="chat-messages" id="chat-messages"></div>

                        <div class="chat-input">
                            <div class="input-group">
                                <select id="message-agency" class="agency-select">
                                    <option value="police">Police</option>
                                    <option value="fire">Fire</option>
                                    <option value="medical">EMS</option>
                                    <option value="coordinator">Coordinator</option>
                                </select>
                                <input type="text" id="message-input" placeholder="Type your coordination message..." class="message-field">
                                <button class="btn-send" onclick="sendMessage()">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <script>
/* ================================
   GLOBAL STATE (SIMULATION)
================================ */
const state = {
    agenciesOnline: 3,
        sharedIncidents: 3,
        chat: {
            dept: 'all',
            lastId: 0,
            polling: null
        }
};

updateOverview();

/* ================================
   DASHBOARD COUNTERS
================================ */
function updateOverview() {
    document.querySelector('.active-agencies h3').textContent = state.agenciesOnline;
    document.querySelector('.shared-incidents h3').textContent = state.sharedIncidents;
}

/* ================================
   CHAT SYSTEM
================================ */
const DEPT_LABELS = { police: 'Police', fire: 'Fire', medical: 'EMS', coordinator: 'Coordinator' };

document.addEventListener('DOMContentLoaded', () => {
    const filter = document.getElementById('chat-filter-dept');
    if (filter) {
        filter.addEventListener('change', () => {
            state.chat.dept = filter.value || 'all';
            document.getElementById('chat-messages').innerHTML = '';
            state.chat.lastId = 0; // reset to force full load
            loadChat(true);
        });
    }
    // Initial load + start polling
    loadChat(true);
    if (state.chat.polling) clearInterval(state.chat.polling);
    state.chat.polling = setInterval(() => loadChat(false), 5000);
});

function renderMessage(item) {
    const time = new Date(item.created_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
    const agency = item.department;
    const container = document.createElement('div');
    container.className = 'message-group';
    container.innerHTML = `
        <div class="message ${agency}">
            <div class="message-header">
                <span class="agency-tag ${agency}">${(DEPT_LABELS[agency] || agency).toUpperCase()}</span>
                <span class="timestamp">${time}</span>
            </div>
            <div class="message-content">${escapeHtml(item.text)}</div>
        </div>`;
    return container;
}

function loadChat(initial) {
    const params = new URLSearchParams();
    params.set('department', state.chat.dept);
    if (state.chat.lastId > 0) params.set('since_id', String(state.chat.lastId));
    fetch('api/interagency_chat_feed.php?' + params.toString())
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            const items = res.items || [];
            if (!items.length) {
                if (initial && !document.getElementById('chat-messages').children.length) {
                    document.getElementById('chat-messages').innerHTML = '<div class="message-group"><div class="message system"><div class="message-header"><span class="agency-tag system">System</span><span class="timestamp">Now</span></div><div class="message-content">No messages yet.</div></div></div>';
                }
                return;
            }
            // If initial and we got latest in DESC, reverse to chronological
            const isInitialSince = state.chat.lastId === 0;
            const list = isInitialSince ? items.reverse() : items; // initial returns DESC
            const container = document.getElementById('chat-messages');
            let newCount = 0;
            list.forEach(item => {
                if (item.id > state.chat.lastId) {
                    state.chat.lastId = item.id;
                    newCount++;
                }
                container.appendChild(renderMessage(item));
            });
            if (!isInitialSince && newCount > 0) {
                const deptText = state.chat.dept === 'all' ? 'Interagency' : (DEPT_LABELS[state.chat.dept] || state.chat.dept);
                showNotification(`${deptText}: New chat message`, 'info');
            }
            container.scrollTop = container.scrollHeight;
        })
        .catch(() => {});
}

function sendMessage() {
    const agency = document.getElementById('message-agency').value;
    const input = document.getElementById('message-input');
    const text = input.value.trim();
    if (!text) return;

    const time = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    const msg = document.createElement('div');
    msg.className = `message-group channel-all`;
    msg.innerHTML = `
        <div class="message ${agency}">
            <div class="message-header">
                <span class="agency-tag ${agency}">${agency.toUpperCase()}</span>
                <span class="timestamp">${time}</span>
            </div>
            <div class="message-content">${text}</div>
        </div>
    `;

    document.getElementById('chat-messages').appendChild(msg);
    input.value = '';
    // Persist to activity_log as agency_chat
    try {
        fetch('api/activity_event.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'chat', entity_type: 'agency_chat', entity_id: deptToEntityId(agency), details: text })
        }).then(() => {
            // Force reload to ensure we pick up server-stamped message with id
            loadChat(false);
        }).catch(() => {});
    } catch (e) {}
    // Notify locally
    showNotification('Message sent', 'success');
}

/* ================================
   CHAT FILTERING
================================ */
function toggleChannel(channel) {
    document.querySelectorAll('.btn-control').forEach(b => b.classList.remove('active'));
    const activeBtn = document.getElementById(`channel-${channel}`);
    if (activeBtn) activeBtn.classList.add('active');

    // Filter messages based on agency type
    const groups = document.querySelectorAll('.message-group');
    groups.forEach(group => {
        const msg = group.querySelector('.message');
        if (!msg) { group.style.display = 'none'; return; }
        const isEmergency = msg.classList.contains('police') || msg.classList.contains('fire') || msg.classList.contains('medical');
        const isLogistics = msg.classList.contains('system') || msg.classList.contains('utility');

        if (channel === 'all') {
            group.style.display = 'block';
        } else if (channel === 'emergency') {
            group.style.display = isEmergency ? 'block' : 'none';
        } else if (channel === 'logistics') {
            group.style.display = isLogistics ? 'block' : 'none';
        } else {
            group.style.display = 'block';
        }
    });

    if (channel === 'all' || channel === 'emergency' || channel === 'logistics') {
        showNotification(`Viewing ${channel.charAt(0).toUpperCase() + channel.slice(1)} channel`, 'info');
    }
}

/* ================================
   AGENCY ACTIONS
================================ */
// Navigation helper
function navigateTo(page, params = {}) {
    const qs = new URLSearchParams(params).toString();
    window.location.href = qs ? `${page}?${qs}` : page;
}

function contactAgency(button, agency) {
    // Open the header's message content modal with prefilled agency info
    try {
        const nameElement = document.getElementById('messageUserName');
        const avatarElement = document.getElementById('messageUserAvatar');
        const contentElement = document.getElementById('messageContent');
        const statusElement = document.getElementById('messageUserStatus');

        if (nameElement && avatarElement && contentElement && statusElement && typeof window.openModal === 'function') {
            const displayName = agency.toUpperCase();
            nameElement.textContent = displayName;
            avatarElement.src = `https://ui-avatars.com/api/?name=${encodeURIComponent(displayName)}&background=6f42c1&color=fff&size=128`;
            avatarElement.alt = displayName;
            statusElement.textContent = 'Active now';
            contentElement.innerHTML = `
                <div class=\"chat-message received\">
                    <div class=\"message-bubble\">Hi ${displayName}, we need coordination on an incident.</div>
                    <div class=\"message-time\">Just now</div>
                </div>
            `;
            window.openModal('messageContentModal');
            showNotification(`Opening chat with ${displayName}`, 'info');
        } else {
            // Fallback: navigate to dispatch for direct contact
            navigateTo('dispatch.php', { contactAgency: agency });
        }
    } catch (e) {
        navigateTo('dispatch.php', { contactAgency: agency });
    }
}

function activateAgency(button, agency) {
    const card = button.closest('.agency-card');
    card.classList.remove('standby');
    card.classList.add('active');

    const status = card.querySelector('.agency-status');
    status.textContent = 'Online';
    status.className = 'agency-status online';

    button.remove();
    state.agenciesOnline++;
    updateOverview();

    showNotification(`${agency.toUpperCase()} activated`, 'success');
    // Redirect to Dispatch Center for operational activation
    navigateTo('dispatch.php', { activateAgency: agency });
}

/* ================================
   RESOURCES
================================ */
function requestResource() {
    // Open Resources module for creating a new request
    navigateTo('resources.php', { action: 'request' });
}

function shareResource(button, agency) {
    // Navigate to resources module to share from selected agency
    navigateTo('resources.php', { action: 'share', agency });
}

function requestResourceItem(id) {
    showNotification(`Resource ${id} requested`, 'info');
    navigateTo('resources.php', { action: 'request', resource: id });
}

/* ================================
   TASK MANAGEMENT
================================ */
function createTask() {
    const name = prompt('Incident name:');
    if (!name) return;
    // Redirect to Incident module to create/manage task
    navigateTo('incident.php', { newTask: 1, name });
}

function updateTask(idOrBtn) {
    // Support both explicit id and button usage
    const id = typeof idOrBtn === 'string' ? idOrBtn : (idOrBtn && idOrBtn.closest('.task-card') ? idOrBtn.closest('.task-card').querySelector('h4').textContent : 'task');
    navigateTo('incident.php', { updateTask: id });
}

function viewTaskDetails(id) {
    navigateTo('incident.php', { viewTask: id || 'task' });
}

// Utility actions
function refreshAgencies() { window.location.reload(); }
function addAgency() { navigateTo('resources.php', { tab: 'agencies' }); }

/* ================================
   NOTIFICATIONS
================================ */
function showNotification(message, type) {
    // Prevent empty or generic notifications
    if (!message || message === 'Message sent' || message === 'Viewing undefined channel') return;
    // Remove existing notification if present
    const existing = document.querySelector('.notification');
    if (existing) existing.remove();
    const note = document.createElement('div');
    note.className = `notification ${type}`;
    note.textContent = message;
    note.style.position = 'fixed';
    note.style.top = '30px';
    note.style.right = '30px';
    note.style.zIndex = 9999;
    note.style.padding = '1rem 2rem';
    note.style.borderRadius = '8px';
    note.style.fontWeight = '600';
    note.style.fontSize = '1.1rem';
    note.style.boxShadow = '0 2px 16px rgba(0,0,0,0.12)';
    note.style.background = type === 'success' ? '#4caf50' : type === 'error' ? '#e53935' : '#2196f3';
    note.style.color = '#fff';
    document.body.appendChild(note);
    setTimeout(() => note.remove(), 3500);
}

function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;','\'':'&#39;'})[c] || c);
}
function deptToEntityId(dept) {
    switch (String(dept).toLowerCase()) {
        case 'police': return 1;
        case 'fire': return 2;
        case 'medical': return 3;
        case 'coordinator': return 4;
        default: return null;
    }
}
</script>

</body>
</html>
