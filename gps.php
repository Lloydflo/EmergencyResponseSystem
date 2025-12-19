<?php
/**
 * GPS Tracking System - UI Only
 * Real-time location tracking for emergency response units
 */

$pageTitle = 'GPS Tracking System';
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
    <link rel="stylesheet" href="css/gps.css">
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include 'includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - GPS Tracking System
       =================================== -->
    <div class="main-content">
        <div class="main-container">

            <h1 style="font-size: 2rem; font-weight: 700; color: #333; margin-bottom: 2rem; display: flex; align-items: center;">
                <i class="fas fa-map-marked-alt" style="margin-right: 0.5rem; color: #dc3545;"></i>
                GPS Tracking System
            </h1>

            <!-- Tracking Controls -->
            <div class="tracking-controls">
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1.5rem; display: flex; align-items: center;">
                    <i class="fas fa-sliders-h" style="margin-right: 0.5rem; color: #007bff;"></i>
                    Tracking Controls
                </h2>
                <div class="control-grid">
                    <div class="control-group">
                        <label for="unit-filter">Track Unit</label>
                        <select id="unit-filter">
                            <option value="">All Units</option>
                            <option value="ambulance">Ambulance Units</option>
                            <option value="police">Police Units</option>
                            <option value="fire">Fire Units</option>
                            <option value="ambulance-5">Ambulance #5</option>
                            <option value="police-8">Police Unit #8</option>
                        </select>
                    </div>
                    <div class="control-group">
                        <label for="time-range">Time Range</label>
                        <select id="time-range">
                            <option value="live">Live Tracking</option>
                            <option value="1hour">Last Hour</option>
                            <option value="24hours">Last 24 Hours</option>
                            <option value="7days">Last 7 Days</option>
                        </select>
                    </div>
                    <div class="control-group">
                        <label for="map-layer">Map Layer</label>
                        <select id="map-layer">
                            <option value="standard">Standard</option>
                            <option value="satellite">Satellite</option>
                            <option value="terrain">Terrain</option>
                            <option value="traffic">Traffic</option>
                        </select>
                    </div>
                    <div class="control-group">
                        <label for="search-location">Search Location</label>
                        <input type="text" id="search-location" placeholder="Enter address or coordinates">
                    </div>
                </div>
            </div>

            <!-- GPS Grid -->
            <div class="gps-grid">
                <!-- Map Panel -->
                <div class="map-container">
                    <div class="map-header">
                        <h3 style="margin: 0; color: #333;">Live GPS Tracking</h3>
                        <div class="map-controls">
                            <button class="map-btn active" onclick="toggleLayer('units')">
                                <i class="fas fa-ambulance"></i> Units
                            </button>
                            <button class="map-btn active" onclick="toggleLayer('incidents')">
                                <i class="fas fa-exclamation-triangle"></i> Incidents
                            </button>
                            <button class="map-btn" onclick="toggleLayer('routes')">
                                <i class="fas fa-route"></i> Routes
                            </button>
                            <button class="map-btn" onclick="centerMap()">
                                <i class="fas fa-crosshairs"></i> Center
                            </button>
                            <button class="map-btn" onclick="refreshMap()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                    <div class="map-viewport" id="map-viewport">
                        <!-- Map Markers -->
                        <div class="map-marker" style="background-color: #28a745; top: 25%; left: 35%;"
                             data-type="ambulance" data-id="ambulance-5" data-info="Ambulance #5 - Station 1 - Available"
                             onclick="showMarkerInfo(this)">
                            <i class="fas fa-ambulance"></i>
                        </div>
                        <div class="map-marker" style="background-color: #17a2b8; top: 65%; left: 75%;"
                             data-type="police" data-id="police-8" data-info="Police Unit #8 - Downtown - En Route"
                             onclick="showMarkerInfo(this)">
                            <i class="fas fa-shield-alt"></i>
                        </div>
                        <div class="map-marker" style="background-color: #dc3545; top: 80%; left: 20%;"
                             data-type="incident" data-id="incident-1" data-info="Medical Emergency - Suspicious Person"
                             onclick="showMarkerInfo(this)">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div class="map-marker" style="background-color: #ffc107; top: 45%; left: 60%;"
                             data-type="fire" data-id="engine-12" data-info="Engine #12 - Residential Fire"
                             onclick="showMarkerInfo(this)">
                            <i class="fas fa-fire"></i>
                        </div>
                        <div class="map-marker" style="background-color: #6f42c1; top: 15%; left: 80%;"
                             data-type="ambulance" data-id="ambulance-3" data-info="Ambulance #3 - Hospital Transport"
                             onclick="showMarkerInfo(this)">
                            <i class="fas fa-ambulance"></i>
                        </div>

                        <!-- Route Lines (hidden by default) -->
                        <div class="marker-route" id="route-ambulance-5" style="display: none; top: 25%; left: 35%; width: 200px; transform: rotate(45deg);"></div>
                        <div class="marker-route" id="route-police-8" style="display: none; top: 65%; left: 75%; width: 150px; transform: rotate(-30deg);"></div>
                    </div>
                </div>

                <!-- Units Panel -->
                <div class="unit-panel">
                    <h3 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1.5rem; display: flex; align-items: center;">
                        <i class="fas fa-truck" style="margin-right: 0.5rem; color: #28a745;"></i>
                        Unit Status
                    </h3>

                    <div class="unit-card active" data-unit="ambulance-5">
                        <div class="unit-header">
                            <div>
                                <h4 class="unit-name">Ambulance #5</h4>
                                <span class="unit-status status-active">Available</span>
                            </div>
                        </div>
                        <div class="unit-details">
                            <div><i class="fas fa-map-marker-alt"></i> Station 1</div>
                            <div><i class="fas fa-tachometer-alt"></i> 0 mph</div>
                            <div><i class="fas fa-gas-pump"></i> 85% Fuel</div>
                            <div><i class="fas fa-clock"></i> 15 min idle</div>
                        </div>
                        <div class="unit-metrics">
                            <div class="metric">
                                <div class="metric-value">12</div>
                                <div class="metric-label">Calls</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">98%</div>
                                <div class="metric-label">Uptime</div>
                            </div>
                        </div>
                        <div class="unit-actions">
                            <button class="btn-unit" onclick="trackUnit('ambulance-5')">
                                <i class="fas fa-location-arrow"></i> Track
                            </button>
                            <button class="btn-unit" onclick="contactUnit('ambulance-5')">
                                <i class="fas fa-radio"></i> Radio
                            </button>
                            <button class="btn-unit" onclick="unitHistory('ambulance-5')">
                                <i class="fas fa-history"></i> History
                            </button>
                        </div>
                    </div>

                    <div class="unit-card enroute" data-unit="police-8">
                        <div class="unit-header">
                            <div>
                                <h4 class="unit-name">Police Unit #8</h4>
                                <span class="unit-status status-enroute">En Route</span>
                            </div>
                        </div>
                        <div class="unit-details">
                            <div><i class="fas fa-map-marker-alt"></i> Downtown</div>
                            <div><i class="fas fa-tachometer-alt"></i> 35 mph</div>
                            <div><i class="fas fa-gas-pump"></i> 92% Fuel</div>
                            <div><i class="fas fa-clock"></i> ETA 8 min</div>
                        </div>
                        <div class="unit-metrics">
                            <div class="metric">
                                <div class="metric-value">8</div>
                                <div class="metric-label">Calls</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">95%</div>
                                <div class="metric-label">Uptime</div>
                            </div>
                        </div>
                        <div class="unit-actions">
                            <button class="btn-unit active" onclick="trackUnit('police-8')">
                                <i class="fas fa-location-arrow"></i> Track
                            </button>
                            <button class="btn-unit" onclick="contactUnit('police-8')">
                                <i class="fas fa-radio"></i> Radio
                            </button>
                            <button class="btn-unit" onclick="unitHistory('police-8')">
                                <i class="fas fa-history"></i> History
                            </button>
                        </div>
                    </div>

                    <div class="unit-card emergency" data-unit="engine-12">
                        <div class="unit-header">
                            <div>
                                <h4 class="unit-name">Engine #12</h4>
                                <span class="unit-status status-emergency">Emergency</span>
                            </div>
                        </div>
                        <div class="unit-details">
                            <div><i class="fas fa-map-marker-alt"></i> Residential Area</div>
                            <div><i class="fas fa-tachometer-alt"></i> 45 mph</div>
                            <div><i class="fas fa-gas-pump"></i> 67% Fuel</div>
                            <div><i class="fas fa-clock"></i> On Scene</div>
                        </div>
                        <div class="unit-metrics">
                            <div class="metric">
                                <div class="metric-value">15</div>
                                <div class="metric-label">Calls</div>
                            </div>
                            <div class="metric">
                                <div class="metric-value">89%</div>
                                <div class="metric-label">Uptime</div>
                            </div>
                        </div>
                        <div class="unit-actions">
                            <button class="btn-unit" onclick="trackUnit('engine-12')">
                                <i class="fas fa-location-arrow"></i> Track
                            </button>
                            <button class="btn-unit" onclick="contactUnit('engine-12')">
                                <i class="fas fa-radio"></i> Radio
                            </button>
                            <button class="btn-unit" onclick="unitHistory('engine-12')">
                                <i class="fas fa-history"></i> History
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Alerts and Routes Row -->
            <div class="gps-grid">
                <!-- Alerts Panel -->
                <div class="alerts-panel">
                    <h3 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1.5rem; display: flex; align-items: center;">
                        <i class="fas fa-bell" style="margin-right: 0.5rem; color: #ffc107;"></i>
                        GPS Alerts & Notifications
                    </h3>

                    <div class="alert-item warning">
                        <div class="alert-icon warning">
                            <i class="fas fa-exclamation-triangle"></i>
                        </div>
                        <div>
                            <strong>Speed Alert:</strong> Police Unit #8 exceeded speed limit (45 mph in 30 zone)
                            <br><small>2 minutes ago • Downtown District</small>
                        </div>
                    </div>

                    <div class="alert-item danger">
                        <div class="alert-icon danger">
                            <i class="fas fa-times-circle"></i>
                        </div>
                        <div>
                            <strong>GPS Signal Lost:</strong> Ambulance #3 lost GPS signal for 30 seconds
                            <br><small>5 minutes ago • Rural Route 45</small>
                        </div>
                    </div>

                    <div class="alert-item">
                        <div class="alert-icon info">
                            <i class="fas fa-info-circle"></i>
                        </div>
                        <div>
                            <strong>Geofence Alert:</strong> Engine #12 entered restricted zone
                            <br><small>8 minutes ago • Industrial Park</small>
                        </div>
                    </div>

                    <div class="alert-item">
                        <div class="alert-icon info">
                            <i class="fas fa-route"></i>
                        </div>
                        <div>
                            <strong>Route Deviation:</strong> Ambulance #5 took alternate route due to traffic
                            <br><small>12 minutes ago • Highway 101</small>
                        </div>
                    </div>
                </div>

                <!-- Routes Panel -->
                <div class="route-panel">
                    <h3 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1.5rem; display: flex; align-items: center;">
                        <i class="fas fa-route" style="margin-right: 0.5rem; color: #2196f3;"></i>
                        Active Routes
                    </h3>

                    <div class="route-list">
                        <div class="route-item active" onclick="selectRoute('route-1')">
                            <div class="route-number">1</div>
                            <div class="route-details">
                                <div class="route-title">Ambulance #5 → Cardiac Emergency</div>
                                <div class="route-meta">Station 1 → Downtown Hospital • 8 min ETA • 3.2 miles</div>
                            </div>
                        </div>

                        <div class="route-item" onclick="selectRoute('route-2')">
                            <div class="route-number">2</div>
                            <div class="route-details">
                                <div class="route-title">Police Unit #8 → Traffic Accident</div>
                                <div class="route-meta">Downtown → Highway 101 • 6 min ETA • 4.1 miles</div>
                            </div>
                        </div>

                        <div class="route-item" onclick="selectRoute('route-3')">
                            <div class="route-number">3</div>
                            <div class="route-details">
                                <div class="route-title">Engine #12 → Structure Fire</div>
                                <div class="route-meta">Fire Station → Residential Area • On Scene • 2.8 miles</div>
                            </div>
                        </div>

                        <div class="route-item" onclick="selectRoute('route-4')">
                            <div class="route-number">4</div>
                            <div class="route-details">
                                <div class="route-title">Ambulance #3 → Hospital Transport</div>
                                <div class="route-meta">General Hospital → City Hospital • 12 min ETA • 5.5 miles</div>
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
        // GPS Tracking System Functionality

        let activeLayers = ['units', 'incidents'];
        let selectedUnit = null;
        let tooltip = null;

        // Layer toggle functionality
        function toggleLayer(layer) {
            const button = event.target.closest('.map-btn');
            const index = activeLayers.indexOf(layer);

            if (index > -1) {
                activeLayers.splice(index, 1);
                button.classList.remove('active');
            } else {
                activeLayers.push(layer);
                button.classList.add('active');
            }

            updateMapVisibility();
            showNotification(`${layer.charAt(0).toUpperCase() + layer.slice(1)} layer ${button.classList.contains('active') ? 'enabled' : 'disabled'}`, 'info');
        }

        function updateMapVisibility() {
            // Show/hide markers based on active layers
            document.querySelectorAll('.map-marker').forEach(marker => {
                const type = marker.dataset.type;
                if (activeLayers.includes(type === 'incident' ? 'incidents' : type + 's')) {
                    marker.style.display = 'flex';
                } else {
                    marker.style.display = 'none';
                }
            });

            // Show/hide routes
            document.querySelectorAll('.marker-route').forEach(route => {
                route.style.display = activeLayers.includes('routes') ? 'block' : 'none';
            });
        }

        // Map marker interaction
        function showMarkerInfo(marker) {
            // Remove existing tooltip
            if (tooltip) {
                tooltip.remove();
            }

            // Create new tooltip
            tooltip = document.createElement('div');
            tooltip.className = 'marker-tooltip';
            tooltip.textContent = marker.dataset.info;
            document.body.appendChild(tooltip);

            // Position tooltip
            const rect = marker.getBoundingClientRect();
            tooltip.style.left = rect.left + rect.width / 2 + 'px';
            tooltip.style.top = rect.top - 40 + 'px';
            tooltip.style.opacity = '1';

            // Highlight marker
            document.querySelectorAll('.map-marker').forEach(m => m.style.zIndex = '5');
            marker.style.zIndex = '15';

            // Auto hide after 3 seconds
            setTimeout(() => {
                if (tooltip) {
                    tooltip.style.opacity = '0';
                    setTimeout(() => {
                        if (tooltip) {
                            tooltip.remove();
                            tooltip = null;
                        }
                    }, 300);
                }
            }, 3000);
        }

        // Center map functionality
        function centerMap() {
            const viewport = document.getElementById('map-viewport');
            viewport.scrollIntoView({ behavior: 'smooth', block: 'center' });
            showNotification('Map centered', 'info');
        }

        // Refresh map functionality
        function refreshMap() {
            const button = event.target.closest('.map-btn');
            button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Refreshing';

            // Simulate refresh
            setTimeout(() => {
                button.innerHTML = '<i class="fas fa-sync-alt"></i> Refresh';
                showNotification('Map data refreshed', 'success');

                // Animate markers to simulate movement
                document.querySelectorAll('.map-marker').forEach(marker => {
                    const currentTop = parseFloat(marker.style.top);
                    const currentLeft = parseFloat(marker.style.left);
                    const newTop = Math.max(10, Math.min(90, currentTop + (Math.random() - 0.5) * 5));
                    const newLeft = Math.max(10, Math.min(90, currentLeft + (Math.random() - 0.5) * 5));

                    marker.style.top = newTop + '%';
                    marker.style.left = newLeft + '%';
                });
            }, 1500);
        }

        // Unit tracking functionality
        function trackUnit(unitId) {
            selectedUnit = unitId;
            const unitCard = document.querySelector(`[data-unit="${unitId}"]`);
            const marker = document.querySelector(`[data-id="${unitId}"]`);

            // Update UI
            document.querySelectorAll('.btn-unit.active').forEach(btn => btn.classList.remove('active'));
            event.target.classList.add('active');

            // Center on unit
            if (marker) {
                marker.scrollIntoView({ behavior: 'smooth', block: 'center' });
                showMarkerInfo(marker);
            }

            showNotification(`Now tracking ${unitId.replace('-', ' ').toUpperCase()}`, 'success');
        }

        // Contact unit functionality
        function contactUnit(unitId) {
            const unitName = unitId.replace('-', ' ').toUpperCase();
            if (confirm(`Open radio communication with ${unitName}?`)) {
                showNotification(`Radio channel opened to ${unitName}`, 'success');
                // In a real system, this would open a radio interface
            }
        }

        // Unit history functionality
        function unitHistory(unitId) {
            const unitName = unitId.replace('-', ' ').toUpperCase();
            alert(`Unit History for ${unitName}:\n\n• 15 calls this month\n• 98% uptime\n• Last service: 2 weeks ago\n• Total mileage: 12,450 miles\n\nDetailed history would be shown in a modal dialog.`);
        }

        // Route selection functionality
        function selectRoute(routeId) {
            // Update active route
            document.querySelectorAll('.route-item').forEach(item => item.classList.remove('active'));
            event.currentTarget.classList.add('active');

            // Show route on map
            document.querySelectorAll('.marker-route').forEach(route => route.style.display = 'none');
            const routeElement = document.getElementById(routeId);
            if (routeElement) {
                routeElement.style.display = 'block';
            }

            showNotification('Route displayed on map', 'info');
        }

        // Filter functionality
        document.getElementById('unit-filter').addEventListener('change', function() {
            const filter = this.value;
            const unitCards = document.querySelectorAll('.unit-card');
            const markers = document.querySelectorAll('.map-marker');

            unitCards.forEach(card => {
                if (!filter || card.dataset.unit.includes(filter)) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });

            markers.forEach(marker => {
                if (!filter || marker.dataset.type.includes(filter.replace('-', ''))) {
                    marker.style.display = activeLayers.includes(marker.dataset.type === 'incident' ? 'incidents' : marker.dataset.type + 's') ? 'flex' : 'none';
                } else {
                    marker.style.display = 'none';
                }
            });

            showNotification(`Filtered to ${filter || 'all units'}`, 'info');
        });

        document.getElementById('time-range').addEventListener('change', function() {
            showNotification(`Time range changed to ${this.value}`, 'info');
            // In a real system, this would update the map data
        });

        document.getElementById('map-layer').addEventListener('change', function() {
            showNotification(`Map layer changed to ${this.value}`, 'info');
            // In a real system, this would change the map tiles
        });

        document.getElementById('search-location').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                showNotification(`Searching for: ${this.value}`, 'info');
                // In a real system, this would geocode and center the map
                this.value = '';
            }
        });

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

            .map-btn, .btn-unit, .route-item {
                transition: all 0.3s ease;
            }

            .map-btn:hover, .btn-unit:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            }

            .route-item:hover {
                background-color: #f8f9fa;
            }

            .unit-card {
                transition: all 0.3s ease;
            }

            .unit-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            }
        `;
        document.head.appendChild(style);

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            updateMapVisibility();

            // Simulate real-time updates
            setInterval(() => {
                // Randomly update unit positions slightly
                if (Math.random() < 0.3) {
                    const markers = document.querySelectorAll('.map-marker[data-type]:not([data-type="incident"])');
                    if (markers.length > 0) {
                        const randomMarker = markers[Math.floor(Math.random() * markers.length)];
                        const currentTop = parseFloat(randomMarker.style.top);
                        const currentLeft = parseFloat(randomMarker.style.left);
                        const newTop = Math.max(10, Math.min(90, currentTop + (Math.random() - 0.5) * 2));
                        const newLeft = Math.max(10, Math.min(90, currentLeft + (Math.random() - 0.5) * 2));

                        randomMarker.style.top = newTop + '%';
                        randomMarker.style.left = newLeft + '%';
                    }
                }
            }, 5000);
        });
    </script>
</body>
</html>