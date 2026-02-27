<?php


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

            <h1 style="font-size: 2rem; font-weight: 700; color: #333; margin-bottom: 0rem; display: flex; align-items: center;;">
                <i class="" style="margin-right: 2rem; color: #dc3545; margin-top: 9rem;"></i>
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
                        </div>

                        <!-- Simulated map markers -->
                        <div class="map-viewport" id="map" style="width:100%; height:100%;"></div>
                    </div>
                </div>
            </div>

            <!-- AI-Powered Dispatch Recommendations -->
            <div class="ai-recommendations-section">
                <div class="ai-recommendations-card">
                    <div class="ai-recommendations-header">
                        <h2><i class="fas fa-brain"></i> AI Dispatch Recommendations</h2>
                        <span class="ai-badge"><i class="fas fa-robot"></i> Powered by Gemini AI</span>
                    </div>
                    <div class="ai-recommendations-content" id="ai-recommendations-content">
                        <?php
                        include 'includes/gemini_helper.php';

                        // Sample dispatch data - replace with actual real-time data
                        $dispatchData = [
                            'active_incidents' => 3,
                            'available_units' => 8,
                            'pending_calls' => 2,
                            'current_incident' => 'Cardiac Arrest - Downtown Hospital'
                        ];

                        $recommendations = getDispatchRecommendations($dispatchData);
                        if ($recommendations) {
                            echo '<div class="ai-recommendation-text">' . nl2br(htmlspecialchars($recommendations)) . '</div>';
                        } else {
                            echo '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> Unable to generate AI recommendations at this time.</div>';
                        }
                        ?>
                    </div>
                    <div class="ai-recommendations-actions">
                        <button class="btn-ai-refresh" onclick="refreshAIRecommendations()">
                            <i class="fas fa-sync"></i> Get Recommendations
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php /* include('includes/admin-footer.php') */ ?>

    <script>
let markers = {};

function initMap() {
    map = new google.maps.Map(document.getElementById("map"), {
        center: { lat: 14.5995, lng: 120.9842 },
        zoom: 13
    });

    addMarker("ambulance-5", 14.6042, 120.9822, "Ambulance #5 - Available", "red");
    addMarker("police-8", 14.5951, 120.9895, "Police Unit #8 - En Route", "blue");
    addMarker("engine-12", 14.5902, 120.9751, "Engine #12 - Fire Emergency", "orange");
}

function addMarker(id, lat, lng, info, color) {
    const marker = new google.maps.Marker({
        position: { lat, lng },
        map,
        title: info,
        icon: `https://maps.google.com/mapfiles/ms/icons/${color}-dot.png`
    });

    const infoWindow = new google.maps.InfoWindow({
        content: `<strong>${info}</strong>`
    });

    marker.addListener("click", () => infoWindow.open(map, marker));
    markers[id] = marker;
}

/* ===== EXISTING GPS CODE CONTINUES BELOW ===== */

let activeLayers = ['units', 'incidents'];
let selectedUnit = null;
let tooltip = null;
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
            } else {
                button.innerHTML = '<i class="fas fa-play"></i> Deploy';
                button.onclick = () => unitStatus(button, 'busy');
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

        // Quick action functions
        function emergencyBroadcast() {
            if (confirm('Initiate emergency broadcast to all units?')) {
                showNotification('Emergency broadcast sent to all units', 'error');
            }
        }

        function lockdownProtocol() {
            if (confirm('Activate lockdown protocol for all facilities?')) {
                showNotification('Lockdown protocol activated', 'error');
            }
        }

        function massCasualty() {
            if (confirm('Initiate mass casualty response protocol?')) {
                showNotification('Mass casualty response initiated', 'error');
            }
        }

        function resourceRequest() {
            const resource = prompt('What resources do you need?');
            if (resource) {
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
            // Initialization complete
        });
    </script>

    <script
  src="https://maps.googleapis.com/maps/api/js?key=AIzaSyBCn_BCioOMwFS7WrPZixaTnVSW7RFgKUw&libraries=places&callback=initMap"
  async
  defer>
</script>
</body>
</html>
