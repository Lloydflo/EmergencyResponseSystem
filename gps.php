<?php

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
                    <div class="map-viewport" id="map" style="width:100%; height:100%;">
                        
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
                            <button class="btn-unit" onclick="trackUnit('police-8')">
                                <i class="fas fa-location-arrow"></i> Track
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

    <!-- ============================================
         COMPLETE FUNCTIONAL GPS TRACKING SYSTEM
         ============================================ -->
    <script>
        // ===========================================
        // GLOBAL VARIABLES
        // ===========================================
        let map;
        let markers = {};
        let activeLayers = ['units', 'incidents'];
        let selectedUnit = null;
        let liveTrackingInterval = null;
        let isLiveTracking = false;
        let activePolylines = [];

        // Unit Data with simulated positions
        const unitsData = {
            'ambulance-5': {
                name: 'Ambulance #5',
                type: 'ambulance',
                status: 'available',
                lat: 14.6042,
                lng: 120.9822,
                speed: 0,
                fuel: 85,
                calls: 12,
                uptime: 98,
                location: 'Station 1',
                idleTime: '15 min'
            },
            'police-8': {
                name: 'Police Unit #8',
                type: 'police',
                status: 'enroute',
                lat: 14.5951,
                lng: 120.9895,
                speed: 35,
                fuel: 92,
                calls: 8,
                uptime: 95,
                location: 'Downtown',
                eta: '8 min'
            },
            'engine-12': {
                name: 'Engine #12',
                type: 'fire',
                status: 'emergency',
                lat: 14.5902,
                lng: 120.9751,
                speed: 45,
                fuel: 67,
                calls: 15,
                uptime: 89,
                location: 'Residential Area',
                onScene: true
            }
        };

        // ===========================================
        // MAP INITIALIZATION
        // ===========================================
        function initMap() {
            map = new google.maps.Map(document.getElementById("map"), {
                center: { lat: 14.5995, lng: 120.9842 }, // Manila
                zoom: 13,
                mapTypeControl: true,
                streetViewControl: false,
                fullscreenControl: true,
                styles: [
                    {
                        featureType: "poi",
                        elementType: "labels",
                        stylers: [{ visibility: "off" }]
                    }
                ]
            });

            // Add markers for all units
            Object.keys(unitsData).forEach(unitId => {
                const unit = unitsData[unitId];
                addMarker(unitId, unit.lat, unit.lng, unit.name, getMarkerColor(unit.status));
            });

            // Add incident markers
            addIncidentMarker('incident-1', 14.5980, 120.9870, 'Cardiac Emergency', 'red');
            addIncidentMarker('incident-2', 14.5920, 120.9800, 'Traffic Accident', 'orange');

            console.log('✅ Map initialized successfully');
            
            // Start live tracking after map loads
            setTimeout(() => {
                startLiveTracking();
            }, 1000);
            // Google Places Autocomplete
const input = document.getElementById("search-location");
const autocomplete = new google.maps.places.Autocomplete(input, {
    fields: ["geometry", "name"],
    componentRestrictions: { country: "ph" } // Philippines
});

autocomplete.addListener("place_changed", () => {
    const place = autocomplete.getPlace();

    if (!place.geometry) {
        showNotification("No details available for that location", "error");
        return;
    }

    map.panTo(place.geometry.location);
    map.setZoom(15);

    showNotification(`Centered on ${place.name}`, "success");
});

        }

        // ===========================================
        // MARKER FUNCTIONS
        // ===========================================
        function getIcon(type) {
    const icons = {
        ambulance: "https://maps.google.com/mapfiles/kml/shapes/hospitals.png",
        police: "https://maps.google.com/mapfiles/kml/shapes/police.png",
        fire: "https://maps.google.com/mapfiles/kml/shapes/firedept.png",
        incident: "https://maps.google.com/mapfiles/ms/icons/yellow-dot.png"
    };
    return icons[type] || icons.incident;
}

function addMarker(id, lat, lng, info, color, type) {
    const marker = new google.maps.Marker({
        position: { lat, lng },
        map,
        title: info,
        icon: {
            url: getIcon(
                id.includes("ambulance") ? "ambulance" :
                id.includes("police") ? "police" :
                id.includes("engine") ? "fire" :
                "incident"
            ),
            scaledSize: new google.maps.Size(40, 40)
        }
    });

    const infoWindow = new google.maps.InfoWindow({
        content: `<strong>${info}</strong>`
    });

    marker.addListener("click", () => {
        infoWindow.open(map, marker);
    });

    markers[id] = { marker, type };
}


        function addIncidentMarker(id, lat, lng, info, color) {
            const marker = new google.maps.Marker({
                position: { lat, lng },
                map: map,
                title: info,
                icon: {
                    url: `https://maps.google.com/mapfiles/ms/icons/${color}-dot.png`,
                    scaledSize: new google.maps.Size(30, 30)
                }
            });

            const infoWindow = new google.maps.InfoWindow({
                content: `<div style="padding: 10px;"><strong>${info}</strong><br><small>Active Incident</small></div>`
            });

            marker.addListener("click", () => {
                infoWindow.open(map, marker);
            });

            markers[id] = { marker, infoWindow, type: 'incident' };
        }

        function createMarkerInfoContent(unitId) {
            const unit = unitsData[unitId];
            if (!unit) return '<div>Unit information unavailable</div>';

            return `
                <div style="padding: 12px; min-width: 200px;">
                    <h4 style="margin: 0 0 10px 0; color: #333; font-size: 16px;">${unit.name}</h4>
                    <p style="margin: 5px 0;"><strong>Status:</strong> <span style="color: ${getStatusColor(unit.status)};">${unit.status.toUpperCase()}</span></p>
                    <p style="margin: 5px 0;"><strong>Speed:</strong> ${Math.round(unit.speed)} mph</p>
                    <p style="margin: 5px 0;"><strong>Fuel:</strong> ${unit.fuel}%</p>
                    <p style="margin: 5px 0;"><strong>Location:</strong> ${unit.location}</p>
                </div>
            `;
        }

        function getMarkerColor(status) {
            switch(status) {
                case 'available': return 'green';
                case 'enroute': return 'yellow';
                case 'emergency': return 'red';
                default: return 'blue';
            }
        }

        function getStatusColor(status) {
            switch(status) {
                case 'available': return '#28a745';
                case 'enroute': return '#ffc107';
                case 'emergency': return '#dc3545';
                default: return '#007bff';
            }
        }

        // ===========================================
        // MAP CONTROL FUNCTIONS
        // ===========================================
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
        // Toggle markers
        Object.values(markers).forEach(item => {
            if (
                (item.type === 'unit' && activeLayers.includes('units')) ||
                (item.type === 'incident' && activeLayers.includes('incidents'))
            ) {
                item.marker.setMap(map);
            } else {
                item.marker.setMap(null);
            }
        });

        // Toggle routes
        Object.values(routes).forEach(route => {
            route.setMap(activeLayers.includes('routes') ? map : null);
        });
    }

    function centerMap() {
        map.setCenter({ lat: 14.6760, lng: 121.0437 });
        map.setZoom(13);
        showNotification("Centered on Quezon City Hall", "info");
    }

    function refreshMap() {
        Object.values(markers).forEach(item => {
            if (item.type === "unit") {
                const pos = item.marker.getPosition();
                item.marker.setPosition({
                    lat: pos.lat() + (Math.random() - 0.5) * 0.001,
                    lng: pos.lng() + (Math.random() - 0.5) * 0.001
                });
            }
        });
        showNotification("Live GPS refreshed", "success");
    }

    function trackUnit(unitId) {
        selectedUnit = unitId;
        if (markers[unitId]) {
            map.panTo(markers[unitId].marker.getPosition());
            map.setZoom(15);
        }
        showNotification(`Tracking ${unitId.toUpperCase()}`, "success");
    }

    function contactUnit(unitId) {
        alert(`Radio contact opened with ${unitId.toUpperCase()}`);
    }

    function unitHistory(unitId) {
        alert(
            `History for ${unitId.toUpperCase()}:\n\n` +
            `• Calls Today: 5\n` +
            `• GPS Uptime: 98%\n` +
            `• Last Service: 2 weeks ago`
        );
    }

    function selectRoute(routeId) {
        Object.values(routes).forEach(route => route.setMap(null));
        if (routes[routeId]) routes[routeId].setMap(map);
        showNotification("Route selected", "info");
    }

    document.getElementById("search-location").addEventListener("keypress", e => {
        if (e.key === "Enter") {
            geocoder.geocode({ address: e.target.value }, (results, status) => {
                if (status === "OK") {
                    map.setCenter(results[0].geometry.location);
                    map.setZoom(15);
                    showNotification("Location found", "success");
                } else {
                    showNotification("Location not found", "error");
                }
            });
            e.target.value = "";
        }
    });

    document.getElementById("unit-filter").addEventListener("change", function () {
        Object.keys(markers).forEach(id => {
            if (markers[id].type === "unit") {
                markers[id].marker.setMap(
                    !this.value || id.includes(this.value) ? map : null
                );
            }
        });
    });

    function showNotification(msg, type) {
        const n = document.createElement("div");
        n.textContent = msg;
        n.style.cssText = `
            position:fixed;top:20px;right:20px;
            padding:12px 18px;border-radius:8px;
            background:${type === "success" ? "#28a745" : type === "error" ? "#dc3545" : "#17a2b8"};
            color:#fff;font-weight:600;z-index:9999;
        `;
        document.body.appendChild(n);
        setTimeout(() => n.remove(), 3000);
    }

    // Simulated live tracking
    setInterval(() => {
        Object.values(markers).forEach(item => {
            if (item.type === "unit") {
                const p = item.marker.getPosition();
                item.marker.setPosition({
                    lat: p.lat() + (Math.random() - 0.5) * 0.0005,
                    lng: p.lng() + (Math.random() - 0.5) * 0.0005
                });
            }
        });
    }, 5000);
</script>

<script
  src="https://maps.googleapis.com/maps/api/js?key=AIzaSyBCn_BCioOMwFS7WrPZixaTnVSW7RFgKUw&libraries=places&callback=initMap"
  async
  defer>
</script>


</body>
</html>
