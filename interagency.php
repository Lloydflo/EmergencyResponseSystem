<?php



$pageTitle = 'Inter-Agency Coordination';
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
    <link rel="stylesheet" href="css/buttons.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="CSS/cards.css">
    <link rel="stylesheet" href="css/interagency.css">
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include 'includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Inter-Agency Coordination Center
       =================================== -->
    <div class="main-content">
        <div class="main-container">

            <h1 style="font-size: 2rem; font-weight: 700; color: #333; margin-bottom: 2rem; display: flex; align-items: center;">
                <i class="fas fa-handshake" style="margin-right: 0.5rem; color: #6f42c1;"></i>
                Inter-Agency Coordination Center
            </h1>

            <!-- System Status Overview -->
            <div class="coordination-overview">
                <div class="status-card active-agencies">
                    <div class="status-icon">
                        <i class="fas fa-building"></i>
                    </div>
                    <div class="status-content">
                        <h3>8</h3>
                        <p>Active Agencies</p>
                    </div>
                </div>
                <div class="status-card shared-incidents">
                    <div class="status-icon">
                        <i class="fas fa-share-alt"></i>
                    </div>
                    <div class="status-content">
                        <h3>12</h3>
                        <p>Shared Incidents</p>
                    </div>
                </div>
                <div class="status-card coordination-channels">
                    <div class="status-icon">
                        <i class="fas fa-comments"></i>
                    </div>
                    <div class="status-content">
                        <h3>5</h3>
                        <p>Active Channels</p>
                    </div>
                </div>
                <div class="status-card resource-pool">
                    <div class="status-icon">
                        <i class="fas fa-boxes"></i>
                    </div>
                    <div class="status-content">
                        <h3>47</h3>
                        <p>Shared Resources</p>
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
                                    <span class="value">24</span>
                                </div>
                                <div class="detail-item">
                                    <span class="label">Response Time:</span>
                                    <span class="value">4.2 min</span>
                                </div>
                            </div>
                            <div class="agency-actions">
                                <button class="btn-agency" onclick="contactAgency('police')">
                                    <i class="fas fa-phone"></i> Contact
                                </button>
                                <button class="btn-agency" onclick="shareResource('police')">
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
                                    <span class="value">18</span>
                                </div>
                                <div class="detail-item">
                                    <span class="label">Response Time:</span>
                                    <span class="value">6.8 min</span>
                                </div>
                            </div>
                            <div class="agency-actions">
                                <button class="btn-agency" onclick="contactAgency('fire')">
                                    <i class="fas fa-phone"></i> Contact
                                </button>
                                <button class="btn-agency" onclick="shareResource('fire')">
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
                                    <span class="value">15</span>
                                </div>
                                <div class="detail-item">
                                    <span class="label">Response Time:</span>
                                    <span class="value">3.9 min</span>
                                </div>
                            </div>
                            <div class="agency-actions">
                                <button class="btn-agency" onclick="contactAgency('medical')">
                                    <i class="fas fa-phone"></i> Contact
                                </button>
                                <button class="btn-agency" onclick="shareResource('medical')">
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
                                    <span class="value">8</span>
                                </div>
                                <div class="detail-item">
                                    <span class="label">Equipment:</span>
                                    <span class="value">Ready</span>
                                </div>
                            </div>
                            <div class="agency-actions">
                                <button class="btn-agency" onclick="activateAgency('utility')">
                                    <i class="fas fa-play"></i> Activate
                                </button>
                                <button class="btn-agency" onclick="contactAgency('utility')">
                                    <i class="fas fa-phone"></i> Contact
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
                        </div>
                    </div>

                    <div class="chat-container">
                        <div class="chat-messages" id="chat-messages">
                            <div class="message-group">
                                <div class="message police">
                                    <div class="message-header">
                                        <span class="agency-tag police">Police</span>
                                        <span class="timestamp">10:32 AM</span>
                                    </div>
                                    <div class="message-content">
                                        Multiple vehicle accident on Highway 101. Requesting fire department assistance for extrication.
                                    </div>
                                </div>
                            </div>

                            <div class="message-group">
                                <div class="message fire">
                                    <div class="message-header">
                                        <span class="agency-tag fire">Fire</span>
                                        <span class="timestamp">10:33 AM</span>
                                    </div>
                                    <div class="message-content">
                                        Fire Department responding. ETA 8 minutes. Medical units standing by.
                                    </div>
                                </div>
                            </div>

                            <div class="message-group">
                                <div class="message medical">
                                    <div class="message-header">
                                        <span class="agency-tag medical">EMS</span>
                                        <span class="timestamp">10:34 AM</span>
                                    </div>
                                    <div class="message-content">
                                        Trauma team activated. Preparing for multiple casualties. Requesting helicopter if needed.
                                    </div>
                                </div>
                            </div>

                            <div class="message-group">
                                <div class="message system">
                                    <div class="message-header">
                                        <span class="agency-tag system">System</span>
                                        <span class="timestamp">10:35 AM</span>
                                    </div>
                                    <div class="message-content">
                                        <i class="fas fa-info-circle"></i> Incident #2025-0123 escalated to Level 2. All agencies notified.
                                    </div>
                                </div>
                            </div>
                        </div>

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

                <!-- Shared Resources Panel -->
                <div class="coordination-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <i class="fas fa-boxes"></i>
                            Shared Resources
                        </h2>
                        <div class="panel-controls">
                            <button class="btn-control" onclick="requestResource()">
                                <i class="fas fa-plus"></i> Request
                            </button>
                        </div>
                    </div>

                    <div class="resource-pool">
                        <div class="resource-category">
                            <h4>Emergency Vehicles</h4>
                            <div class="resource-items">
                                <div class="resource-item available">
                                    <div class="resource-icon">
                                        <i class="fas fa-ambulance"></i>
                                    </div>
                                    <div class="resource-info">
                                        <span class="resource-name">Ambulance #12</span>
                                        <span class="resource-agency">EMS</span>
                                    </div>
                                    <button class="btn-resource-request" onclick="requestResourceItem('ambulance-12')">
                                        Request
                                    </button>
                                </div>
                                <div class="resource-item in-use">
                                    <div class="resource-icon">
                                        <i class="fas fa-truck"></i>
                                    </div>
                                    <div class="resource-info">
                                        <span class="resource-name">Ladder Truck #7</span>
                                        <span class="resource-agency">Fire Dept</span>
                                    </div>
                                    <span class="resource-status">In Use</span>
                                </div>
                            </div>
                        </div>

                        <div class="resource-category">
                            <h4>Specialized Equipment</h4>
                            <div class="resource-items">
                                <div class="resource-item available">
                                    <div class="resource-icon">
                                        <i class="fas fa-tools"></i>
                                    </div>
                                    <div class="resource-info">
                                        <span class="resource-name">Jaws of Life</span>
                                        <span class="resource-agency">Fire Dept</span>
                                    </div>
                                    <button class="btn-resource-request" onclick="requestResourceItem('jaws-of-life')">
                                        Request
                                    </button>
                                </div>
                                <div class="resource-item available">
                                    <div class="resource-icon">
                                        <i class="fas fa-medkit"></i>
                                    </div>
                                    <div class="resource-info">
                                        <span class="resource-name">Trauma Kit</span>
                                        <span class="resource-agency">EMS</span>
                                    </div>
                                    <button class="btn-resource-request" onclick="requestResourceItem('trauma-kit')">
                                        Request
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Incident Coordination Panel -->
                <div class="coordination-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <i class="fas fa-tasks"></i>
                            Active Coordination
                        </h2>
                        <div class="panel-controls">
                            <button class="btn-control" onclick="createTask()">
                                <i class="fas fa-plus"></i> New Task
                            </button>
                        </div>
                    </div>

                    <div class="coordination-tasks">
                        <div class="task-card high-priority">
                            <div class="task-header">
                                <h4>Highway 101 Accident Response</h4>
                                <span class="task-priority high">High</span>
                            </div>
                            <div class="task-details">
                                <div class="task-assignment">
                                    <span class="label">Lead Agency:</span>
                                    <span class="value">Police Department</span>
                                </div>
                                <div class="task-agencies">
                                    <span class="agency-involved police">Police</span>
                                    <span class="agency-involved fire">Fire</span>
                                    <span class="agency-involved medical">EMS</span>
                                </div>
                            </div>
                            <div class="task-actions">
                                <button class="btn-task" onclick="updateTask('accident-101')">
                                    <i class="fas fa-edit"></i> Update
                                </button>
                                <button class="btn-task" onclick="viewTaskDetails('accident-101')">
                                    <i class="fas fa-eye"></i> Details
                                </button>
                            </div>
                        </div>

                        <div class="task-card medium-priority">
                            <div class="task-header">
                                <h4>Building Fire - Downtown</h4>
                                <span class="task-priority medium">Medium</span>
                            </div>
                            <div class="task-details">
                                <div class="task-assignment">
                                    <span class="label">Lead Agency:</span>
                                    <span class="value">Fire Department</span>
                                </div>
                                <div class="task-agencies">
                                    <span class="agency-involved fire">Fire</span>
                                    <span class="agency-involved medical">EMS</span>
                                    <span class="agency-involved utility">Utility</span>
                                </div>
                            </div>
                            <div class="task-actions">
                                <button class="btn-task" onclick="updateTask('building-fire')">
                                    <i class="fas fa-edit"></i> Update
                                </button>
                                <button class="btn-task" onclick="viewTaskDetails('building-fire')">
                                    <i class="fas fa-eye"></i> Details
                                </button>
                            </div>
                        </div>

                        <div class="task-card low-priority">
                            <div class="task-header">
                                <h4>Utility Line Repair</h4>
                                <span class="task-priority low">Low</span>
                            </div>
                            <div class="task-details">
                                <div class="task-assignment">
                                    <span class="label">Lead Agency:</span>
                                    <span class="value">Power Utility Co.</span>
                                </div>
                                <div class="task-agencies">
                                    <span class="agency-involved utility">Utility</span>
                                    <span class="agency-involved police">Police</span>
                                </div>
                            </div>
                            <div class="task-actions">
                                <button class="btn-task" onclick="updateTask('utility-repair')">
                                    <i class="fas fa-edit"></i> Update
                                </button>
                                <button class="btn-task" onclick="viewTaskDetails('utility-repair')">
                                    <i class="fas fa-eye"></i> Details
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php /* include('includes/admin-footer.php') */ ?>

    <script>
        // Inter-Agency Coordination Functionality

        // Send coordination message
        function sendMessage() {
            const agency = document.getElementById('message-agency').value;
            const message = document.getElementById('message-input').value.trim();

            if (message) {
                const chatMessages = document.getElementById('chat-messages');
                const messageGroup = document.createElement('div');
                messageGroup.className = 'message-group';

                const timestamp = new Date().toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});

                messageGroup.innerHTML = `
                    <div class="message ${agency}">
                        <div class="message-header">
                            <span class="agency-tag ${agency}">${agency.charAt(0).toUpperCase() + agency.slice(1)}</span>
                            <span class="timestamp">${timestamp}</span>
                        </div>
                        <div class="message-content">${message}</div>
                    </div>
                `;

                chatMessages.appendChild(messageGroup);
                chatMessages.scrollTop = chatMessages.scrollHeight;

                document.getElementById('message-input').value = '';

                showNotification('Message sent to coordination channel', 'success');
            }
        }

        // Toggle coordination channels
        function toggleChannel(channel) {
            // Update button states
            document.querySelectorAll('.btn-control').forEach(btn => btn.classList.remove('active'));
            document.getElementById(`channel-${channel}`).classList.add('active');

            showNotification(`Switched to ${channel} channel`, 'info');
        }

        // Contact agency
        function contactAgency(agency) {
            const agencyNames = {
                'police': 'Metropolitan Police',
                'fire': 'City Fire Department',
                'medical': 'Regional EMS',
                'utility': 'Power Utility Co.'
            };

            if (confirm(`Call ${agencyNames[agency]}?`)) {
                showNotification(`Calling ${agencyNames[agency]}...`, 'info');
            }
        }

        // Share resource with agency
        function shareResource(agency) {
            const agencyNames = {
                'police': 'Metropolitan Police',
                'fire': 'City Fire Department',
                'medical': 'Regional EMS'
            };

            showNotification(`Resource sharing initiated with ${agencyNames[agency]}`, 'success');
        }

        // Activate standby agency
        function activateAgency(agency) {
            if (confirm('Activate this agency for emergency response?')) {
                showNotification('Agency activated successfully', 'success');
                // Update agency status visually
                event.target.closest('.agency-card').classList.remove('standby');
                event.target.closest('.agency-card').classList.add('active');
                event.target.querySelector('.agency-status').textContent = 'Online';
                event.target.querySelector('.agency-status').className = 'agency-status online';
            }
        }

        // Request resource item
        function requestResourceItem(item) {
            const itemNames = {
                'ambulance-12': 'Ambulance #12',
                'jaws-of-life': 'Jaws of Life',
                'trauma-kit': 'Trauma Kit'
            };

            if (confirm(`Request ${itemNames[item]} from sharing pool?`)) {
                showNotification(`Request sent for ${itemNames[item]}`, 'info');
            }
        }

        // Create new coordination task
        function createTask() {
            const taskName = prompt('Enter task/incident name:');
            if (taskName) {
                showNotification(`Task "${taskName}" created successfully`, 'success');
            }
        }

        // Update task
        function updateTask(taskId) {
            showNotification('Task update interface would open here', 'info');
        }

        // View task details
        function viewTaskDetails(taskId) {
            showNotification('Task details modal would open here', 'info');
        }

        // Add new agency
        function addAgency() {
            const agencyName = prompt('Enter agency name:');
            if (agencyName) {
                showNotification(`Agency "${agencyName}" added to coordination network`, 'success');
            }
        }

        // Request resource
        function requestResource() {
            const resourceType = prompt('What type of resource do you need?\n• Vehicles\n• Equipment\n• Personnel\n• Other');
            if (resourceType) {
                showNotification(`Resource request submitted: ${resourceType}`, 'info');
            }
        }

        // Refresh agencies
        function refreshAgencies() {
            showNotification('Agency status refreshed', 'success');
        }

        // Enter key support for chat
        document.getElementById('message-input').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                sendMessage();
            }
        });

        // Notification system
        function showNotification(message, type) {
            // Create notification element
            const notification = document.createElement('div');
            notification.className = `notification ${type}`;
            notification.innerHTML = `
                <i class="fas ${type === 'success' ? 'fa-check-circle' : type === 'error' ? 'fa-exclamation-circle' : 'fa-info-circle'}"></i>
                ${message}
            `;

            // Add to page
            document.body.appendChild(notification);

            // Auto remove after 3 seconds
            setTimeout(() => {
                notification.remove();
            }, 3000);
        }
    </script>

    <style>
        .notification {
            position: fixed;
            top: 20px;
            right: 20px;
            background: white;
            border-radius: 8px;
            padding: 1rem;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            display: flex;
            align-items: center;
            gap: 0.5rem;
            animation: slideIn 0.3s ease;
        }

        .notification.success { border-left: 4px solid #28a745; }
        .notification.error { border-left: 4px solid #dc3545; }
        .notification.info { border-left: 4px solid #17a2b8; }

        @keyframes slideIn {
            from { transform: translateX(100%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
    </style>
</body>
</html>