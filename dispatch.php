<?php
/**
 * Emergency Dispatch Center - UI Only
 * Central command center for coordinating emergency responses
 */

$pageTitle = 'Emergency Dispatch Center';
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
    <link rel="stylesheet" href="css/dispatch.css">
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include 'includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Emergency Dispatch Center
       =================================== -->
    <div class="main-content">
        <div class="main-container">

            <h1 style="font-size: 2rem; font-weight: 700; color: #333; margin-bottom: 2rem; display: flex; align-items: center;">
                <i class="fas fa-tower-broadcast" style="margin-right: 0.5rem; color: #dc3545;"></i>
                Emergency Dispatch Center
            </h1>

            <!-- System Alerts -->
            <div class="alert-panel">
                <i class="fas fa-exclamation-triangle fa-2x"></i>
                <div>
                    <strong>System Status:</strong> All systems operational | Active incidents: 3 | Available units: 8
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <button class="quick-action-btn" onclick="emergencyBroadcast()">
                    <i class="fas fa-bullhorn"></i>
                    Emergency Broadcast
                </button>
                <button class="quick-action-btn" onclick="lockdownProtocol()">
                    <i class="fas fa-shield-alt"></i>
                    Lockdown Protocol
                </button>
                <button class="quick-action-btn" onclick="massCasualty()">
                    <i class="fas fa-users"></i>
                    Mass Casualty Response
                </button>
                <button class="quick-action-btn" onclick="resourceRequest()">
                    <i class="fas fa-truck"></i>
                    Resource Request
                </button>
            </div>

            <!-- Main Dispatch Grid -->
            <div class="dispatch-grid">
                <!-- Active Calls Panel -->
                <div class="dispatch-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <i class="fas fa-phone"></i>
                            Active Emergency Calls
                        </h2>
                        <span style="background: #dc3545; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600;">3 Active</span>
                    </div>

                    <div class="call-card high">
                        <div class="call-info">
                            <div class="call-details">
                                <div class="call-title">Cardiac Arrest - Downtown Hospital</div>
                                <div class="call-meta">
                                    <span><i class="fas fa-clock"></i> 2 min ago</span>
                                    <span><i class="fas fa-user"></i> John Smith</span>
                                    <span class="status-indicator status-active"></span> High Priority
                                </div>
                            </div>
                        </div>
                        <div class="call-actions">
                            <button class="btn-dispatch" onclick="dispatchUnit(this, 'Ambulance #5')">Dispatch Ambulance</button>
                            <button class="btn-action-small" onclick="viewDetails(this)">
                                <i class="fas fa-eye"></i> Details
                            </button>
                            <button class="btn-action-small" onclick="contactCaller(this)">
                                <i class="fas fa-phone"></i> Call
                            </button>
                        </div>
                    </div>

                    <div class="call-card medium">
                        <div class="call-info">
                            <div class="call-details">
                                <div class="call-title">Multi-Vehicle Accident - Highway 101</div>
                                <div class="call-meta">
                                    <span><i class="fas fa-clock"></i> 8 min ago</span>
                                    <span><i class="fas fa-user"></i> Sarah Johnson</span>
                                    <span class="status-indicator status-busy"></span> Medium Priority
                                </div>
                            </div>
                        </div>
                        <div class="call-actions">
                            <button class="btn-dispatch" onclick="dispatchUnit(this, 'Police Unit #8')">Dispatch Police</button>
                            <button class="btn-action-small" onclick="viewDetails(this)">
                                <i class="fas fa-eye"></i> Details
                            </button>
                            <button class="btn-action-small" onclick="contactCaller(this)">
                                <i class="fas fa-phone"></i> Call
                            </button>
                        </div>
                    </div>

                    <div class="call-card low">
                        <div class="call-info">
                            <div class="call-details">
                                <div class="call-title">Suspicious Person Report</div>
                                <div class="call-meta">
                                    <span><i class="fas fa-clock"></i> 15 min ago</span>
                                    <span><i class="fas fa-user"></i> Mike Davis</span>
                                    <span class="status-indicator status-available"></span> Low Priority
                                </div>
                            </div>
                        </div>
                        <div class="call-actions">
                            <button class="btn-dispatch" onclick="dispatchUnit(this, 'Police Unit #15')">Dispatch Police</button>
                            <button class="btn-action-small" onclick="viewDetails(this)">
                                <i class="fas fa-eye"></i> Details
                            </button>
                            <button class="btn-action-small" onclick="contactCaller(this)">
                                <i class="fas fa-phone"></i> Call
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Available Units Panel -->
                <div class="dispatch-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <i class="fas fa-ambulance"></i>
                            Available Units
                        </h2>
                        <span style="background: #28a745; color: white; padding: 0.25rem 0.75rem; border-radius: 20px; font-size: 0.8rem; font-weight: 600;">8 Available</span>
                    </div>

                    <div class="unit-card available">
                        <div class="unit-info">
                            <div class="unit-details">
                                <div class="unit-name">Ambulance #5</div>
                                <div class="unit-meta">
                                    <span><i class="fas fa-map-marker-alt"></i> Station 1</span>
                                    <span><i class="fas fa-gas-pump"></i> 85% Fuel</span>
                                </div>
                            </div>
                        </div>
                        <div class="unit-actions">
                            <button class="btn-action-small" onclick="unitStatus(this, 'busy')">
                                <i class="fas fa-play"></i> Deploy
                            </button>
                            <button class="btn-action-small" onclick="unitLocation(this)">
                                <i class="fas fa-location-arrow"></i> Track
                            </button>
                        </div>
                    </div>

                    <div class="unit-card available">
                        <div class="unit-info">
                            <div class="unit-details">
                                <div class="unit-name">Police Unit #8</div>
                                <div class="unit-meta">
                                    <span><i class="fas fa-map-marker-alt"></i> Downtown</span>
                                    <span><i class="fas fa-gas-pump"></i> 92% Fuel</span>
                                </div>
                            </div>
                        </div>
                        <div class="unit-actions">
                            <button class="btn-action-small" onclick="unitStatus(this, 'busy')">
                                <i class="fas fa-play"></i> Deploy
                            </button>
                            <button class="btn-action-small" onclick="unitLocation(this)">
                                <i class="fas fa-location-arrow"></i> Track
                            </button>
                        </div>
                    </div>

                    <div class="unit-card busy">
                        <div class="unit-info">
                            <div class="unit-details">
                                <div class="unit-name">Engine #12</div>
                                <div class="unit-meta">
                                    <span><i class="fas fa-map-marker-alt"></i> En Route</span>
                                    <span><i class="fas fa-clock"></i> 5 min ETA</span>
                                </div>
                            </div>
                        </div>
                        <div class="unit-actions">
                            <button class="btn-action-small" onclick="unitStatus(this, 'available')">
                                <i class="fas fa-stop"></i> Stand Down
                            </button>
                            <button class="btn-action-small" onclick="unitLocation(this)">
                                <i class="fas fa-location-arrow"></i> Track
                            </button>
                        </div>
                    </div>

                    <div class="unit-card available">
                        <div class="unit-info">
                            <div class="unit-details">
                                <div class="unit-name">Police Unit #15</div>
                                <div class="unit-meta">
                                    <span><i class="fas fa-map-marker-alt"></i> Station 3</span>
                                    <span><i class="fas fa-gas-pump"></i> 78% Fuel</span>
                                </div>
                            </div>
                        </div>
                        <div class="unit-actions">
                            <button class="btn-action-small" onclick="unitStatus(this, 'busy')">
                                <i class="fas fa-play"></i> Deploy
                            </button>
                            <button class="btn-action-small" onclick="unitLocation(this)">
                                <i class="fas fa-location-arrow"></i> Track
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Map Panel -->
                <div class="dispatch-panel">
                    <div class="panel-header">
                        <h2 class="panel-title">
                            <i class="fas fa-map"></i>
                            Live Map
                        </h2>
                        <div>
                            <button class="btn-action-small" onclick="refreshMap()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>

                    <div class="map-container">
                        <div class="map-placeholder">
                            <i class="fas fa-map-marked-alt fa-3x" style="color: #dee2e6; margin-bottom: 1rem;"></i>
                            <div>Interactive Map View</div>
                            <small style="color: #999;">Real-time unit tracking & incident locations</small>
                        </div>

                        <!-- Simulated map markers -->
                        <div class="map-marker" style="top: 30%; left: 40%;" title="Cardiac Arrest Incident" onclick="markerClick('Cardiac Arrest - Downtown Hospital')"></div>
                        <div class="map-marker" style="top: 60%; left: 70%;" title="Traffic Accident" onclick="markerClick('Multi-Vehicle Accident - Highway 101')"></div>
                        <div class="map-marker" style="top: 80%; left: 20%;" title="Suspicious Person" onclick="markerClick('Suspicious Person Report')"></div>
                        <div class="map-marker" style="background: #28a745; top: 25%; left: 35%;" title="Ambulance #5" onclick="markerClick('Ambulance #5 - Available')"></div>
                        <div class="map-marker" style="background: #17a2b8; top: 65%; left: 75%;" title="Police Unit #8" onclick="markerClick('Police Unit #8 - Available')"></div>
                    </div>
                </div>
            </div>

            <!-- Communication Panel -->
            <div class="communication-panel">
                <div class="panel-header">
                    <h2 class="panel-title">
                        <i class="fas fa-broadcast-tower"></i>
                        Communication Center
                    </h2>
                </div>

                <div class="comm-tabs">
                    <button class="comm-tab active" onclick="switchCommTab('radio')">Radio</button>
                    <button class="comm-tab" onclick="switchCommTab('phone')">Phone</button>
                    <button class="comm-tab" onclick="switchCommTab('messages')">Messages</button>
                </div>

                <div id="radio" class="comm-content active">
                    <div class="radio-interface">
                        <div class="radio-display">
                            <div>[14:32] Dispatch: Ambulance 5, respond to cardiac arrest at Downtown Hospital</div>
                            <div>[14:31] Ambulance 5: Copy, en route ETA 6 minutes</div>
                            <div>[14:28] Police Unit 8: Traffic accident cleared, returning to patrol</div>
                            <div>[14:25] Dispatch: All units, be advised of possible severe weather</div>
                        </div>
                        <div class="radio-controls">
                            <button class="radio-btn" onclick="radioCommand('all')">All Call</button>
                            <button class="radio-btn" onclick="radioCommand('ambulance')">Ambulance</button>
                            <button class="radio-btn" onclick="radioCommand('police')">Police</button>
                            <button class="radio-btn" onclick="radioCommand('fire')">Fire</button>
                            <button class="radio-btn active" onclick="radioCommand('push')">Push to Talk</button>
                        </div>
                    </div>
                </div>

                <div id="phone" class="comm-content">
                    <div class="phone-interface">
                        <div class="phone-display">
                            <div class="phone-number">+1 (555) 123-4567</div>
                            <div class="phone-status">Ready to dial</div>
                        </div>
                        <div class="phone-keypad">
                            <button class="phone-key" onclick="dialDigit('1')">1</button>
                            <button class="phone-key" onclick="dialDigit('2')">2</button>
                            <button class="phone-key" onclick="dialDigit('3')">3</button>
                            <button class="phone-key" onclick="dialDigit('4')">4</button>
                            <button class="phone-key" onclick="dialDigit('5')">5</button>
                            <button class="phone-key" onclick="dialDigit('6')">6</button>
                            <button class="phone-key" onclick="dialDigit('7')">7</button>
                            <button class="phone-key" onclick="dialDigit('8')">8</button>
                            <button class="phone-key" onclick="dialDigit('9')">9</button>
                            <button class="phone-key" onclick="dialDigit('*')">*</button>
                            <button class="phone-key" onclick="dialDigit('0')">0</button>
                            <button class="phone-key" onclick="dialDigit('#')">#</button>
                            <button class="phone-key wide" onclick="makeCall()">Call</button>
                            <button class="phone-key wide" onclick="endCall()">End</button>
                        </div>
                    </div>
                </div>

                <div id="messages" class="comm-content">
                    <div style="padding: 1rem; text-align: center; color: #666;">
                        <i class="fas fa-comments fa-3x" style="margin-bottom: 1rem;"></i>
                        <div>Secure Messaging System</div>
                        <small>Encrypted communication for sensitive information</small>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php /* include('includes/admin-footer.php') */ ?>

    <script>
        // Emergency Dispatch Center Functionality

        // Dispatch unit to incident
        function dispatchUnit(button, unitName) {
            const callCard = button.closest('.call-card');
            const callTitle = callCard.querySelector('.call-title').textContent;

            if (confirm(`Dispatch ${unitName} to: ${callTitle}?`)) {
                // Update button state
                button.textContent = 'Dispatched';
                button.disabled = true;
                button.style.backgroundColor = '#28a745';

                // Update unit status
                const unitCards = document.querySelectorAll('.unit-card');
                unitCards.forEach(card => {
                    if (card.querySelector('.unit-name').textContent === unitName) {
                        unitStatus(card.querySelector('.btn-action-small'), 'busy');
                    }
                });

                // Add to radio log
                addRadioMessage(`Dispatch: ${unitName}, respond to ${callTitle.split(' - ')[0]}`);

                showNotification(`${unitName} dispatched successfully`, 'success');
            }
        }

        // Change unit status
        function unitStatus(button, newStatus) {
            const unitCard = button.closest('.unit-card');
            const unitName = unitCard.querySelector('.unit-name').textContent;

            // Update card styling
            unitCard.classList.remove('available', 'busy', 'unavailable');
            unitCard.classList.add(newStatus);

            // Update button
            if (newStatus === 'busy') {
                button.innerHTML = '<i class="fas fa-stop"></i> Stand Down';
                button.onclick = () => unitStatus(button, 'available');
                addRadioMessage(`${unitName}: Responding to call`);
            } else {
                button.innerHTML = '<i class="fas fa-play"></i> Deploy';
                button.onclick = () => unitStatus(button, 'busy');
                addRadioMessage(`${unitName}: Available for dispatch`);
            }

            showNotification(`Unit ${unitName} status: ${newStatus.toUpperCase()}`, 'info');
        }

        // Track unit location
        function unitLocation(button) {
            const unitCard = button.closest('.unit-card');
            const unitName = unitCard.querySelector('.unit-name').textContent;

            showNotification(`Tracking ${unitName}...`, 'info');

            // Simulate location update
            setTimeout(() => {
                showNotification(`${unitName} location updated`, 'success');
            }, 1000);
        }

        // View incident details
        function viewDetails(button) {
            const callCard = button.closest('.call-card');
            const callTitle = callCard.querySelector('.call-title').textContent;
            const callMeta = callCard.querySelector('.call-meta').textContent;

            alert(`Incident Details:\n\n${callTitle}\n${callMeta}\n\nAdditional details would be shown in a modal dialog.`);
        }

        // Contact caller
        function contactCaller(button) {
            const callCard = button.closest('.call-card');
            const callerName = callCard.querySelector('.call-meta span:nth-child(2)').textContent;

            if (confirm(`Call ${callerName}?`)) {
                // Switch to phone tab and set number
                switchCommTab('phone');
                document.querySelector('.phone-number').textContent = '+1 (555) 123-4567';
                document.querySelector('.phone-status').textContent = `Calling ${callerName}`;

                showNotification(`Calling ${callerName}...`, 'info');
            }
        }

        // Map functions
        function refreshMap() {
            showNotification('Map refreshed', 'info');

            // Animate markers
            const markers = document.querySelectorAll('.map-marker');
            markers.forEach(marker => {
                marker.style.animation = 'pulse 1s ease-in-out';
                setTimeout(() => marker.style.animation = '', 1000);
            });
        }

        function markerClick(title) {
            showNotification(`Selected: ${title}`, 'info');
        }

        // Communication tabs
        function switchCommTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.comm-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');

            // Update content
            document.querySelectorAll('.comm-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(tabName).classList.add('active');
        }

        // Radio functions
        function radioCommand(command) {
            const buttons = document.querySelectorAll('.radio-btn');
            buttons.forEach(btn => btn.classList.remove('active'));

            if (command !== 'push') {
                event.target.classList.add('active');
            }

            addRadioMessage(`Dispatch: ${command.toUpperCase()} units, attention please`);
        }

        function addRadioMessage(message) {
            const display = document.querySelector('.radio-display');
            const time = new Date().toLocaleTimeString('en-US', {hour12: false, hour: '2-digit', minute: '2-digit'});
            const messageDiv = document.createElement('div');
            messageDiv.textContent = `[${time}] ${message}`;
            display.appendChild(messageDiv);
            display.scrollTop = display.scrollHeight;
        }

        // Phone functions
        let currentNumber = '';

        function dialDigit(digit) {
            currentNumber += digit;
            document.querySelector('.phone-number').textContent = formatPhoneNumber(currentNumber);
        }

        function formatPhoneNumber(number) {
            if (number.length <= 3) return number;
            if (number.length <= 6) return `(${number.slice(0,3)}) ${number.slice(3)}`;
            return `(${number.slice(0,3)}) ${number.slice(3,6)}-${number.slice(6)}`;
        }

        function makeCall() {
            if (currentNumber.length > 0) {
                document.querySelector('.phone-status').textContent = 'Calling...';
                addRadioMessage(`Phone: Outgoing call to ${document.querySelector('.phone-number').textContent}`);
                showNotification('Call initiated', 'success');
            }
        }

        function endCall() {
            document.querySelector('.phone-status').textContent = 'Call ended';
            currentNumber = '';
            document.querySelector('.phone-number').textContent = '+1 (555) 123-4567';

            setTimeout(() => {
                document.querySelector('.phone-status').textContent = 'Ready to dial';
            }, 2000);
        }

        // Quick action functions
        function emergencyBroadcast() {
            if (confirm('Initiate emergency broadcast to all units?')) {
                addRadioMessage('EMERGENCY BROADCAST: All units, emergency situation declared');
                showNotification('Emergency broadcast sent to all units', 'error');
            }
        }

        function lockdownProtocol() {
            if (confirm('Activate lockdown protocol for all facilities?')) {
                addRadioMessage('LOCKDOWN PROTOCOL ACTIVATED: Secure all facilities immediately');
                showNotification('Lockdown protocol activated', 'error');
            }
        }

        function massCasualty() {
            if (confirm('Initiate mass casualty response protocol?')) {
                addRadioMessage('MASS CASUALTY RESPONSE: All available units respond immediately');
                showNotification('Mass casualty response initiated', 'error');
            }
        }

        function resourceRequest() {
            const resource = prompt('What resources do you need?');
            if (resource) {
                addRadioMessage(`RESOURCE REQUEST: ${resource} - requesting immediate assistance`);
                showNotification(`Resource request sent: ${resource}`, 'info');
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

        // Add CSS animations
        const style = document.createElement('style');
        style.textContent = `
            @keyframes slideIn {
                from { transform: translateX(100%); opacity: 0; }
                to { transform: translateX(0); opacity: 1; }
            }

            @keyframes slideOut {
                from { transform: translateX(0); opacity: 1; }
                to { transform: translateX(100%); opacity: 0; }
            }

            @keyframes pulse {
                0% { transform: scale(1); }
                50% { transform: scale(1.1); }
                100% { transform: scale(1); }
            }

            .notification {
                font-family: inherit;
            }

            .btn-dispatch, .btn-action-small, .radio-btn, .phone-key, .quick-action-btn {
                transition: all 0.3s ease;
            }

            .btn-dispatch:hover:not(:disabled), .btn-action-small:hover, .radio-btn:hover, .phone-key:hover, .quick-action-btn:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            }
        `;
        document.head.appendChild(style);

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Start radio simulation
            setInterval(() => {
                const messages = [
                    'Unit check: All units report status',
                    'Weather advisory: Heavy rain expected',
                    'System check: All communications nominal'
                ];
                if (Math.random() < 0.3) {
                    addRadioMessage(messages[Math.floor(Math.random() * messages.length)]);
                }
            }, 10000);
        });
    </script>
</body>
</html>