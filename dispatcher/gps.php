<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
// Require full login (including OTP verification) before loading page
require_role('dispatcher', 'dispatcher/gps.php');

$pageTitle = 'GPS Tracking System';
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
    <link rel="stylesheet" href="css/gps.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css" />
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include $rootDir . '/includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - GPS Tracking System
       =================================== -->
    <div class="main-content">
        <div class="main-container">


            <section class="gps-hero">
                <div class="gps-hero-main">
                    <div class="gps-kicker">Dispatcher GPS Console</div>
                    <h1 class="gps-hero-title">Live GPS Tracking System</h1>
                    <p class="gps-hero-text">
                        Monitor dispatched units, incident markers, and route movement from one real-time operational map.
                    </p>

                    <div class="gps-hero-chips">
                        <span class="gps-chip gps-chip-live"><span class="gps-chip-dot"></span> Live Tracking Active</span>
                        <span class="gps-chip">Quezon City Coverage</span>
                        <span class="gps-chip">Routing Ready</span>
                    </div>
                </div>

                <div class="gps-hero-side">
                    <div class="gps-status-card">
                        <div class="gps-status-label">Tracking Scope</div>
                        <div class="gps-status-value">Dispatch units, incidents, and route movement</div>
                        <div class="gps-status-note">
                            Use the controls below to filter units and search specific locations without changing the current workflow.
                        </div>
                    </div>
                </div>
            </section>

            <section class="tracking-controls">
                <div class="tracking-controls-header">
                    <div>
                        <div class="section-eyebrow">Tracking Controls</div>
                        <h2 class="section-title">
                            <i class="fas fa-sliders-h"></i>
                            GPS Filters and Search
                        </h2>
                    </div>
                    <div class="tracking-controls-note">
                        Enter an address or coordinates, then press <strong>Enter</strong>.
                    </div>
                </div>
                <div class="control-grid">
                    <div class="control-group">
                        <label for="unit-filter">Track Unit</label>
                        <select id="unit-filter">
                            <option value="">All Units</option>
                            <option value="ambulance">Ambulance Units</option>
                            <option value="police">Police Units</option>
                            <option value="fire">Fire Units</option>
                        </select>
                    </div>
                    <div class="control-group control-group-search">
                        <label for="search-location">Search Location</label>
                        <input type="text" id="search-location" placeholder="Enter address or coordinates" autocomplete="off" style="position:relative;z-index:1100;">
                    </div>
                </div>
            </section>

            <div class="gps-grid">
                <section class="map-container tracking-map-panel">
                    <div class="map-header">
                        <div class="map-heading">
                            <div class="section-eyebrow">Live Monitoring Grid</div>
                            <h3 class="map-title">Live GPS Tracking</h3>
                        </div>
                        <div class="map-controls">
                            <button class="map-btn active" onclick="toggleLayer('unit', this)">
                                <i class="fas fa-ambulance"></i> Units
                            </button>
                            <button class="map-btn" onclick="toggleLayer('incident', this)">
                                <i class="fas fa-exclamation-triangle"></i> Incidents
                            </button>
                            <button class="map-btn" onclick="centerMap()">
                                <i class="fas fa-crosshairs"></i> Center
                            </button>
                            <button class="map-btn" onclick="refreshMap()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                    <div class="tracking-map-shell">
                        <div class="tracking-map-overlay">
                            <div class="tracking-map-overlay-title">
                                <i class="fas fa-satellite-dish"></i>
                                GPS Feed Status
                            </div>
                            <div class="tracking-map-overlay-text">
                                Watch unit movement and incident positions in real time.
                            </div>
                        </div>
                        <div class="map-viewport" id="map" style="width:100%;"></div>
                    </div>
                </section>

                <aside class="unit-panel">
                    <div class="unit-panel-header">
                        <div>
                            <div class="section-eyebrow">Tracked Units</div>
                            <h3 class="section-title">
                                <i class="fas fa-truck"></i>
                                Unit Status and Dispatch
                            </h3>
                        </div>
                        <div class="unit-panel-note">Auto-updates every few seconds during live polling.</div>
                    </div>
                    <div class="unit-scroll-container" id="unit-scroll-container"></div>
                </aside>
            </div>

        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <!-- ============================================
         COMPLETE FUNCTIONAL GPS TRACKING SYSTEM
         ============================================ -->
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.12.2/firebase-database-compat.js"></script>
<script>
    const firebaseConfig = {
        apiKey: "YOUR_WEB_API_KEY",
        authDomain: "emergencyresponseapp-f5110.firebaseapp.com",
        databaseURL: "https://emergencyresponseapp-f5110-default-rtdb.firebaseio.com",
        projectId: "emergencyresponseapp-f5110",
        storageBucket: "emergencyresponseapp-f5110.appspot.com",
        messagingSenderId: "YOUR_SENDER_ID",
        appId: "YOUR_APP_ID"
    };
    firebase.initializeApp(firebaseConfig);
    const rtdb = firebase.database();
</script>

    <script>
let map;
let markers = {};
let activeLayers = ['unit'];
let qcBoundaryLayers = { halo: null, line: null };
let routes = {};
let QC_BOUNDS_GLOBAL;
let unitFilter = '';
let dispatchedUnitsByIdentifier = {};
let unitIdentifierById = {};
let incidentGeocodeCache = {};
let searchLocationMarker = null;
let pendingTrackRequest = null;
let pendingTrackAttempts = 0;
let pendingRouteRequest = null;
let pendingRouteAttempts = 0;
let activeRouteState = null;
const MAX_PENDING_TRACK_ATTEMPTS = 20;
const MAX_PENDING_ROUTE_ATTEMPTS = 20;
const QC_VIEWBOX = '121.0000,14.7500,121.1000,14.6000';

// ===============================
// LEAFLET MAP INITIALIZATION
// ===============================
function initMap() {

  // Quezon City bounds
  QC_BOUNDS_GLOBAL = L.latLngBounds(
    [14.6000, 121.0000],
    [14.7500, 121.1000]
  );

    map = L.map("map", {
        center: [14.6760, 121.0437], // QC Hall
        zoom: 13,
        worldCopyJump: true
    });
    window.map = map;

  // OpenStreetMap tiles
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    attribution: "© OpenStreetMap contributors"
  }).addTo(map);

    setTimeout(() => {
        try { map.invalidateSize(); } catch (e) {}
    }, 180);
    window.addEventListener('resize', () => {
        try { map.invalidateSize(); } catch (e) {}
    });

    // Load and display Quezon City border from GeoJSON
    fetch('dispatcher/quezon_city.geojson')
        .then(res => res.json())
        .then(data => {
            L.geoJSON(data, {
                style: {
                    color: 'red',
                    weight: 3,
                    fill: false
                }
            }).addTo(map);
        });

    // Load units from API and render
    loadDispatchedUnits();
    loadAvailableUnits();
    initFirebaseLiveTracking();

    // Load incidents and add warning markers
    loadIncidentMarkers();
// Load incidents from API and add warning markers
function loadIncidentMarkers() {
    fetch('api/incidents_list.php?status=active')
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            const items = res.items || [];
            items.forEach(inc => {
                // If incident has coordinates, add marker
                if (inc.latitude && inc.longitude) {
                    addIncidentMarker(
                        'incident-' + inc.id,
                        parseFloat(inc.latitude),
                        parseFloat(inc.longitude),
                        inc.title || inc.type || 'Incident'
                    );
                }
            });
        })
        .catch(() => {});
}

    initRoutes();

    // Incidents markers disabled: remove sample incidents

    // Add legend for marker colors
    addLegendControl();

    // Ensure visibility respects current activeLayers on load
    updateMapVisibility();

    console.log("✅ Leaflet map initialized");
    // Restore dispatch route immediately when coordinates are already present in the URL.
    try {
        const params = new URLSearchParams(window.location.search);
        const fromLat = toNum(params.get('from_lat'));
        const fromLng = toNum(params.get('from_lng'));
        const toLat = toNum(params.get('to_lat'));
        const toLng = toNum(params.get('to_lng'));
        const unit = params.get('unit') || '';
        const unitId = params.get('unit_id') || '';
        const incidentId = params.get('incident_id') || '';
        const incidentLabel = params.get('incident') || '';
        if (fromLat !== null && fromLng !== null && toLat !== null && toLng !== null && typeof addRouteToIncident === 'function') {
            addRouteToIncident(fromLat, fromLng, toLat, toLng, { silent: true });
            activeRouteState = {
                unit,
                unitId,
                incidentId,
                incidentLocation: incidentLabel,
                toLat: String(toLat),
                toLng: String(toLng)
            };
        }
    } catch (e) {}
}

// ===============================
// MARKERS
// ===============================
function getIcon(type) {
    const meta = getMarkerIconMeta(type);
    return L.divIcon({
        className: 'ers-unit-div-icon',
        html: `
            <div style="width:38px;height:38px;border-radius:50% 50% 50% 8px;transform:rotate(-45deg);background:${meta.color};border:2px solid #fff;box-shadow:0 8px 18px rgba(15,23,42,.35);display:flex;align-items:center;justify-content:center;">
                <i class="fas ${meta.icon}" style="transform:rotate(45deg);color:#fff;font-size:17px;line-height:1;"></i>
            </div>
        `,
        iconSize: [38, 38],
        iconAnchor: [19, 38],
        popupAnchor: [0, -34]
    });
}

function getMarkerIconMeta(type) {
    const key = String(type || '').trim().toLowerCase();
    const icons = {
        ambulance: { icon: 'fa-ambulance', color: '#16a34a' },
        medical: { icon: 'fa-ambulance', color: '#16a34a' },
        police: { icon: 'fa-shield-alt', color: '#2563eb' },
        crime: { icon: 'fa-shield-alt', color: '#2563eb' },
        fire: { icon: 'fa-truck', color: '#dc2626' },
        rescue: { icon: 'fa-life-ring', color: '#ea580c' },
        incident: { icon: 'fa-exclamation-triangle', color: '#f59e0b' },
        other: { icon: 'fa-truck-medical', color: '#64748b' },
        idle: { icon: 'fa-circle-dot', color: '#94a3b8' }, // muted gray, distinct from active dispatch colors
    };
    return icons[key] || icons.other;
}

function markerLegendSwatch(type) {
    const meta = getMarkerIconMeta(type);
    return `<span style="width:22px;height:22px;border-radius:50%;background:${meta.color};display:inline-flex;align-items:center;justify-content:center;margin-right:7px;box-shadow:0 1px 3px rgba(0,0,0,.2);"><i class="fas ${meta.icon}" style="color:#fff;font-size:11px;"></i></span>`;
}

function addUnitMarker(id, lat, lng, label, type, speedKph, unitDbId) {
    const marker = L.marker([lat, lng], { icon: getIcon(type) })
        .addTo(map)
        .bindPopup(`
            <strong>${label}</strong><br>
            ${typeof speedKph === 'number' && isFinite(speedKph) ? `Speed: ${speedKph.toFixed(1)} km/h<br>` : ''}
            Coords: ${lat.toFixed(5)}, ${lng.toFixed(5)}
        `);

    markers[id] = {
        marker,
        type: "unit",
        unitType: (type || '').toLowerCase(),
        speedKph: speedKph,
        unitDbId: unitDbId !== undefined && unitDbId !== null ? String(unitDbId) : ''
    };
}

function addIncidentMarker(id, lat, lng, label) {
  const marker = L.marker([lat, lng], { icon: getIcon("incident") })
    .addTo(map)
    .bindPopup(`<strong>${label}</strong><br>🚨 Active Incident`);

  markers[id] = { marker, type: "incident" };
}

// ===============================
// ROUTES (POLYLINES)
// ===============================
function initRoutes() {
  routes["route-1"] = L.polyline(
    [
      [14.6825, 121.0505],
      [14.6760, 121.0437],
      [14.6690, 121.0380]
    ],
    { color: "red", weight: 4 }
  );

  routes["route-2"] = L.polyline(
    [
      [14.6672, 121.0603],
      [14.6720, 121.0650],
      [14.6900, 121.0600]
    ],
    { color: "blue", weight: 4 }
  );

    // Additional routes to match the route list items
    routes["route-3"] = L.polyline(
        [
            [14.6954, 121.0321],
            [14.6900, 121.0400],
            [14.6800, 121.0450]
        ],
        { color: "orange", weight: 4 }
    );

    routes["route-4"] = L.polyline(
        [
            [14.6600, 121.0300],
            [14.6700, 121.0350],
            [14.6800, 121.0400]
        ],
        { color: "green", weight: 4 }
    );
}

// ===============================
// MAP CONTROLS
// ===============================
function toggleLayer(layer, el) {
    if (activeLayers.includes(layer)) {
        activeLayers.splice(activeLayers.indexOf(layer), 1);
        if (el) el.classList.remove('active');
    } else {
        activeLayers.push(layer);
        if (el) el.classList.add('active');
    }
    updateMapVisibility();
    showNotification(`${layer.charAt(0).toUpperCase() + layer.slice(1)} layer ${activeLayers.includes(layer) ? 'enabled' : 'disabled'}`, 'info');
}

function updateMapVisibility() {
    Object.values(markers).forEach(item => {
        let visible = activeLayers.includes(item.type);
        if (visible && item.type === 'unit' && unitFilter) {
            visible = (item.unitType === unitFilter);
        }
        visible ? map.addLayer(item.marker) : map.removeLayer(item.marker);
    });

  Object.values(routes).forEach(route => {
    activeLayers.includes("routes")
      ? route.addTo(map)
      : map.removeLayer(route);
  });
}

function centerMap() {
    // Quezon City Hall coordinates
    map.setView([14.6760, 121.0437], 13);
}

function refreshMap() {
    Object.values(markers).forEach(item => {
        const pos = item.marker.getLatLng();
        const newLat = pos.lat + (Math.random() - 0.5) * 0.001;
        const newLng = pos.lng + (Math.random() - 0.5) * 0.001;
        item.marker.setLatLng([newLat, newLng]);
    });
}

function selectRoute(routeId) {
    // Hide all routes first
    Object.values(routes).forEach(r => map.removeLayer(r));
    activeRouteState = null;
    pendingRouteRequest = null;
    pendingRouteAttempts = 0;
    if (routes[routeId]) {
        routes[routeId].addTo(map);
        if (!activeLayers.includes('routes')) activeLayers.push('routes');
        showNotification('Route selected', 'info');
    }
}

function normalizeUnitIdentifier(value) {
    const text = String(value || '').trim();
    if (!text) return '';
    const firstToken = text.split(/\s+/)[0];
    return firstToken.trim();
}

function rememberUnitIdentity(unit) {
    const identifier = String(unit && unit.identifier ? unit.identifier : '').trim();
    const unitId = String(unit && unit.id !== undefined && unit.id !== null ? unit.id : '').trim();
    if (identifier && unitId) {
        unitIdentifierById[unitId] = identifier;
    }
}

function resolveTrackTargetFromRequest() {
    if (!pendingTrackRequest) return '';

    const rawUnit = normalizeUnitIdentifier(pendingTrackRequest.unit || '');
    if (rawUnit) {
        if (markers[rawUnit]) return rawUnit;
        const upperRaw = rawUnit.toUpperCase();
        const markerKey = Object.keys(markers).find((key) => String(key).toUpperCase() === upperRaw);
        if (markerKey) return markerKey;
    }

    const rawUnitId = String(pendingTrackRequest.unitId || '').trim();
    if (rawUnitId) {
        if (unitIdentifierById[rawUnitId]) {
            return unitIdentifierById[rawUnitId];
        }
        const markerByDbId = Object.keys(markers).find((key) => String(markers[key]?.unitDbId || '') === rawUnitId);
        if (markerByDbId) return markerByDbId;
    }

    return rawUnit;
}

function trackUnit(unitId, options) {
    options = options || {};
    const silent = !!(options && options.silent);
    const normalized = normalizeUnitIdentifier(unitId);
    const explicitUnitId = String(options.unitId || '').trim();
    if (!normalized && !explicitUnitId) {
        if (!silent) showNotification('Unit not found', 'error');
        return false;
    }

    let resolvedKey = normalized;
    let entry = markers[resolvedKey];
    if (!entry) {
        const upperNormalized = normalized.toUpperCase();
        const matchedKey = Object.keys(markers).find((key) => String(key).toUpperCase() === upperNormalized);
        if (matchedKey) {
            resolvedKey = matchedKey;
            entry = markers[resolvedKey];
        }
    }

    if (!entry && explicitUnitId) {
        const markerByDbId = Object.keys(markers).find((key) => String(markers[key]?.unitDbId || '') === explicitUnitId);
        if (markerByDbId) {
            resolvedKey = markerByDbId;
            entry = markers[markerByDbId];
        }
    }

    if (!entry) {
        const fallbackLat = toNum(options.fromLat || options.latitude);
        const fallbackLng = toNum(options.fromLng || options.longitude);
        if (fallbackLat !== null && fallbackLng !== null) {
            focusMapToLocation(fallbackLat, fallbackLng);
            if (!silent) showNotification('Showing last known unit location', 'info');
            return true;
        }
        if (!silent) showNotification('Unit not found', 'error');
        return false;
    }

    if (entry.marker && !map.hasLayer(entry.marker)) {
        entry.marker.addTo(map);
    }
    const pos = entry.marker.getLatLng();
    map.setView(pos, 15);
    entry.marker.openPopup();
    if (!silent) showNotification(`Tracking ${resolvedKey.toUpperCase()}`, 'success');
    return true;
}

function tryTrackPendingUnit() {
    if (!pendingTrackRequest) return;
    const target = resolveTrackTargetFromRequest();
    if (!target) return;

    const tracked = trackUnit(target, { silent: true });
    if (tracked) {
        const displayName = normalizeUnitIdentifier(target) || 'unit';
        showNotification(`Tracking ${displayName.toUpperCase()}`, 'success');
        pendingTrackRequest = null;
        pendingTrackAttempts = 0;
        return;
    }

    pendingTrackAttempts += 1;
    if (pendingTrackAttempts >= MAX_PENDING_TRACK_ATTEMPTS) {
        const fallbackLat = Number(pendingTrackRequest.fromLat);
        const fallbackLng = Number(pendingTrackRequest.fromLng);
        if (Number.isFinite(fallbackLat) && Number.isFinite(fallbackLng)) {
            focusMapToLocation(fallbackLat, fallbackLng);
            showNotification('Unit marker unavailable. Showing last known location.', 'info');
        } else {
            showNotification('Unit not found on map', 'error');
        }
        pendingTrackRequest = null;
        pendingTrackAttempts = 0;
        return;
    }
    setTimeout(tryTrackPendingUnit, 400);
}

function resolveDispatchedUnitEntry(unitRef, explicitUnitId) {
    const rawUnit = normalizeUnitIdentifier(unitRef || '');
    if (rawUnit && dispatchedUnitsByIdentifier[rawUnit]) {
        return { key: rawUnit, unit: dispatchedUnitsByIdentifier[rawUnit] };
    }

    if (rawUnit) {
        const upperRaw = rawUnit.toUpperCase();
        const matchedKey = Object.keys(dispatchedUnitsByIdentifier).find((key) => String(key).toUpperCase() === upperRaw);
        if (matchedKey) {
            return { key: matchedKey, unit: dispatchedUnitsByIdentifier[matchedKey] };
        }
    }

    const rawUnitId = String(explicitUnitId || '').trim();
    if (rawUnitId) {
        const mappedIdentifier = unitIdentifierById[rawUnitId];
        if (mappedIdentifier && dispatchedUnitsByIdentifier[mappedIdentifier]) {
            return { key: mappedIdentifier, unit: dispatchedUnitsByIdentifier[mappedIdentifier] };
        }
        const matchedKey = Object.keys(dispatchedUnitsByIdentifier).find((key) => {
            const unit = dispatchedUnitsByIdentifier[key];
            return String(unit && unit.id !== undefined && unit.id !== null ? unit.id : '') === rawUnitId;
        });
        if (matchedKey) {
            return { key: matchedKey, unit: dispatchedUnitsByIdentifier[matchedKey] };
        }
    }

    return { key: rawUnit, unit: null };
}

function plotKnownDispatchRoute(fromLat, fromLng, toLat, toLng, options) {
    const opts = options || {};
    const resolvedFromLat = toNum(fromLat);
    const resolvedFromLng = toNum(fromLng);
    const resolvedToLat = toNum(toLat);
    const resolvedToLng = toNum(toLng);
    if (resolvedFromLat === null || resolvedFromLng === null || resolvedToLat === null || resolvedToLng === null) {
        return false;
    }
    if (typeof addRouteToIncident !== 'function') {
        if (!opts.deferIfUnavailable && !opts.suppressErrors) {
            showNotification('Routing is not ready yet', 'error');
        }
        return false;
    }
    addRouteToIncident(resolvedFromLat, resolvedFromLng, resolvedToLat, resolvedToLng, { silent: true });
    if (!opts.silent) {
        showNotification(opts.successMessage || 'Route loaded for dispatched unit', 'success');
    }
    return true;
}

async function fetchIncidentRouteContext(incidentId) {
    const normalizedId = String(incidentId || '').trim();
    if (!normalizedId) return null;
    try {
        const response = await fetch('api/incident_details.php?id=' + encodeURIComponent(normalizedId));
        const data = await response.json();
        if (!data || !data.incident) return null;
        const inc = data.incident;
        return {
            label: inc.reference_no || inc.title || inc.location_address || '',
            location: inc.location_address || '',
            lat: toNum(inc.latitude),
            lng: toNum(inc.longitude)
        };
    } catch (e) {
        return null;
    }
}

async function tryShowPendingRoute() {
    if (!pendingRouteRequest) return;

    const routed = await showUnitRoute(pendingRouteRequest.unit || '', {
        unitId: pendingRouteRequest.unitId || '',
        incidentId: pendingRouteRequest.incidentId || '',
        incidentLocation: pendingRouteRequest.incidentLocation || '',
        fromLat: pendingRouteRequest.fromLat || '',
        fromLng: pendingRouteRequest.fromLng || '',
        toLat: pendingRouteRequest.toLat || '',
        toLng: pendingRouteRequest.toLng || '',
        silent: true,
        suppressErrors: true,
        deferIfUnavailable: true
    });

    if (routed) {
        showNotification('Live route ready for dispatched unit', 'success');
        pendingRouteRequest = null;
        pendingRouteAttempts = 0;
        return;
    }

    pendingRouteAttempts += 1;
    if (pendingRouteAttempts >= MAX_PENDING_ROUTE_ATTEMPTS) {
        const fallbackShown = plotKnownDispatchRoute(
            pendingRouteRequest.fromLat,
            pendingRouteRequest.fromLng,
            pendingRouteRequest.toLat,
            pendingRouteRequest.toLng,
            { silent: true, suppressErrors: true, deferIfUnavailable: true }
        );
        showNotification(
            fallbackShown ? 'Showing last known dispatch route' : 'Route not available yet',
            fallbackShown ? 'info' : 'error'
        );
        if (fallbackShown) {
            activeRouteState = {
                unit: pendingRouteRequest.unit || '',
                unitId: pendingRouteRequest.unitId || '',
                incidentId: pendingRouteRequest.incidentId || '',
                incidentLocation: pendingRouteRequest.incidentLocation || '',
                toLat: String(pendingRouteRequest.toLat || ''),
                toLng: String(pendingRouteRequest.toLng || '')
            };
        }
        pendingRouteRequest = null;
        pendingRouteAttempts = 0;
        return;
    }

    setTimeout(tryShowPendingRoute, 500);
}

function refreshActiveRoute() {
    if (!activeRouteState) return;
    showUnitRoute(activeRouteState.unit || '', {
        unitId: activeRouteState.unitId || '',
        incidentId: activeRouteState.incidentId || '',
        incidentLocation: activeRouteState.incidentLocation || '',
        toLat: activeRouteState.toLat || '',
        toLng: activeRouteState.toLng || '',
        silent: true,
        suppressErrors: true,
        deferIfUnavailable: true
    }).catch(() => {});
}

function getFocusedUnitRequest() {
    if (activeRouteState && (activeRouteState.unit || activeRouteState.unitId)) {
        return {
            unit: String(activeRouteState.unit || ''),
            unitId: String(activeRouteState.unitId || ''),
            incidentLocation: String(activeRouteState.incidentLocation || ''),
            incidentId: String(activeRouteState.incidentId || '')
        };
    }
    if (pendingTrackRequest && (pendingTrackRequest.unit || pendingTrackRequest.unitId)) {
        return {
            unit: String(pendingTrackRequest.unit || ''),
            unitId: String(pendingTrackRequest.unitId || ''),
            incidentLocation: '',
            incidentId: ''
        };
    }
    if (pendingRouteRequest && (pendingRouteRequest.unit || pendingRouteRequest.unitId)) {
        return {
            unit: String(pendingRouteRequest.unit || ''),
            unitId: String(pendingRouteRequest.unitId || ''),
            incidentLocation: String(pendingRouteRequest.incidentLocation || ''),
            incidentId: String(pendingRouteRequest.incidentId || '')
        };
    }
    return null;
}

function isUnitFocused(unit) {
    const request = getFocusedUnitRequest();
    if (!request || !unit) return false;
    const unitIdentifier = normalizeUnitIdentifier(unit.identifier || '');
    const focusedIdentifier = normalizeUnitIdentifier(request.unit || '');
    const unitId = String(unit.id !== undefined && unit.id !== null ? unit.id : '').trim();
    const focusedUnitId = String(request.unitId || '').trim();

    if (focusedIdentifier && unitIdentifier && focusedIdentifier.toUpperCase() === unitIdentifier.toUpperCase()) {
        return true;
    }
    if (focusedUnitId && unitId && focusedUnitId === unitId) {
        return true;
    }
    return false;
}

function buildFocusedUnitPlaceholder() {
    const request = getFocusedUnitRequest();
    if (!request || (!request.unit && !request.unitId)) return '';
    const identifier = escapeHtml(request.unit || `Unit #${request.unitId}`);
    const incidentLabel = escapeHtml(request.incidentLocation || `Incident #${request.incidentId || '?'}`);
    return `
        <div class="unit-card active focused syncing" data-unit="${identifier}">
            <div class="unit-header">
                <div>
                    <h4 class="unit-name">${identifier}</h4>
                    <span class="unit-status">assigned</span>
                </div>
            </div>
            <div class="unit-details">
                <div><i class="fas fa-satellite-dish"></i> Syncing dispatched unit to live GPS...</div>
                <div><i class="fas fa-map-marker-alt"></i> ${incidentLabel}</div>
            </div>
            <div class="unit-actions">
                <button class="btn-unit active" type="button" disabled><i class="fas fa-location-arrow"></i> Tracking</button>
                <button class="btn-unit active" type="button" disabled><i class="fas fa-route"></i> Routing</button>
            </div>
        </div>
    `;
}

function revealFocusedUnitCard() {
    const request = getFocusedUnitRequest();
    if (!request || !request.unit) return;
    const normalized = normalizeUnitIdentifier(request.unit);
    if (!normalized) return;
    const cards = document.querySelectorAll('#unit-scroll-container .unit-card[data-unit]');
    for (const card of cards) {
        const cardUnit = normalizeUnitIdentifier(card.getAttribute('data-unit') || '');
        if (cardUnit && cardUnit.toUpperCase() === normalized.toUpperCase()) {
            card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            return;
        }
    }
}

    // Quezon City bounds
function toNum(v) {
    const n = parseFloat(v);
    return Number.isFinite(n) ? n : null;
}

function parseCoordsFromText(value) {
    const text = String(value || '').trim();
    if (!text) return null;
    const match = text.match(/(-?\d+(?:\.\d+)?)\s*,\s*(-?\d+(?:\.\d+)?)/);
    if (!match) return null;
    const lat = toNum(match[1]);
    const lng = toNum(match[2]);
    if (lat === null || lng === null) return null;
    if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null;
    return { lat, lng };
}

function hasLocationContext(text) {
    return /(quezon city|qc|metro manila|philippines)\b/i.test(String(text || ''));
}

async function geocodeOnce(query, strictViewbox) {
    const params = new URLSearchParams({
        format: 'jsonv2',
        limit: '6',
        countrycodes: 'ph',
        addressdetails: '1',
        q: query
    });
    params.set('viewbox', QC_VIEWBOX);
    if (strictViewbox) {
        params.set('bounded', '1');
    }
    const response = await fetch(`https://nominatim.openstreetmap.org/search?${params.toString()}`, {
        headers: { Accept: 'application/json' }
    });
    if (!response.ok) return [];
    const data = await response.json();
    return Array.isArray(data) ? data : [];
}

function selectBestGeocodeCandidate(items, originalQuery) {
    if (!Array.isArray(items) || !items.length) return null;
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

    const directCoords = parseCoordsFromText(raw);
    if (directCoords) return directCoords;

    const cacheKey = raw.toLowerCase();
    if (Object.prototype.hasOwnProperty.call(incidentGeocodeCache, cacheKey)) {
        return incidentGeocodeCache[cacheKey];
    }

    const query = hasLocationContext(raw)
        ? raw
        : `${raw}, Quezon City, Metro Manila, Philippines`;

    try {
        let candidates = await geocodeOnce(query, true);
        if (!candidates.length) {
            candidates = await geocodeOnce(query, false);
        }
        if (!candidates.length && query !== raw) {
            candidates = await geocodeOnce(raw, false);
        }
        const best = selectBestGeocodeCandidate(candidates, raw);
        const lat = best ? toNum(best.lat) : null;
        const lng = best ? toNum(best.lon) : null;
        const result = (lat !== null && lng !== null) ? { lat, lng } : null;
        incidentGeocodeCache[cacheKey] = result;
        return result;
    } catch (e) {
        incidentGeocodeCache[cacheKey] = null;
        return null;
    }
}

function formatDateTime(value) {
    if (!value) return 'N/A';
    const d = new Date(String(value).replace(' ', 'T'));
    if (Number.isNaN(d.getTime())) return String(value);
    return d.toLocaleString();
}

async function showUnitRoute(unitId, options) {
    options = options || {};
    const resolvedEntry = resolveDispatchedUnitEntry(unitId, options.unitId || '');
    const unit = resolvedEntry.unit;

    if (!unit) {
        const directRouteShown = plotKnownDispatchRoute(
            options.fromLat,
            options.fromLng,
            options.toLat,
            options.toLng,
            options
        );
        if (directRouteShown) {
            activeRouteState = {
                unit: resolvedEntry.key || normalizeUnitIdentifier(unitId),
                unitId: String(options.unitId || ''),
                incidentId: String(options.incidentId || ''),
                incidentLocation: String(options.incidentLocation || ''),
                toLat: String(options.toLat || ''),
                toLng: String(options.toLng || '')
            };
            return true;
        }
        if (!options.deferIfUnavailable && !options.suppressErrors) {
            showNotification('Unable to find dispatched unit details', 'error');
        }
        return false;
    }

    const fromLat = toNum(unit.latitude) ?? toNum(options.fromLat);
    const fromLng = toNum(unit.longitude) ?? toNum(options.fromLng);
    let toLat = toNum(options.toLat) ?? toNum(unit.incident_latitude);
    let toLng = toNum(options.toLng) ?? toNum(unit.incident_longitude);
    let incidentLocation = String(options.incidentLocation || unit.incident_location || '').trim();

    if (fromLat === null || fromLng === null) {
        if (!options.deferIfUnavailable && !options.suppressErrors) {
            showNotification('Route unavailable: missing unit coordinates', 'error');
        }
        return false;
    }

    if ((toLat === null || toLng === null) && incidentLocation) {
        const parsed = parseCoordsFromText(incidentLocation);
        if (parsed) {
            toLat = parsed.lat;
            toLng = parsed.lng;
        }
    }

    if (toLat === null || toLng === null) {
        const incidentContext = await fetchIncidentRouteContext(options.incidentId || unit.current_incident_id || '');
        if (incidentContext) {
            incidentLocation = incidentLocation || incidentContext.location || incidentContext.label || '';
            if (incidentContext.lat !== null && incidentContext.lng !== null) {
                toLat = incidentContext.lat;
                toLng = incidentContext.lng;
            }
        }
    }

    if ((toLat === null || toLng === null) && incidentLocation) {
        if (!options.silent && !options.deferIfUnavailable) {
            showNotification('Locating incident address...', 'info');
        }
        const geocoded = await geocodeIncidentLocation(incidentLocation);
        if (geocoded) {
            toLat = geocoded.lat;
            toLng = geocoded.lng;
            unit.incident_latitude = String(toLat);
            unit.incident_longitude = String(toLng);
        }
    }

    if (toLat === null || toLng === null) {
        if (!options.deferIfUnavailable && !options.suppressErrors) {
            showNotification('Route unavailable: unable to locate incident address', 'error');
        }
        return false;
    }

    const routed = plotKnownDispatchRoute(fromLat, fromLng, toLat, toLng, options);
    if (!routed) return false;

    activeRouteState = {
        unit: resolvedEntry.key || normalizeUnitIdentifier(unit.identifier || unitId),
        unitId: String(unit.id !== undefined && unit.id !== null ? unit.id : (options.unitId || '')),
        incidentId: String(options.incidentId || unit.current_incident_id || ''),
        incidentLocation: incidentLocation,
        toLat: String(toLat),
        toLng: String(toLng)
    };

    return true;
}

async function unitHistory(unitId, options) {
    options = options || {};
    try {
        const params = new URLSearchParams();
        const unitIdentifier = normalizeUnitIdentifier(unitId);
        const unitDbId = String(options.unitId || '').trim();
        if (unitDbId) {
            params.set('id', unitDbId);
        }
        if (unitIdentifier) {
            params.set('identifier', unitIdentifier);
        }
        const r = await fetch('api/unit_history.php?' + params.toString(), { cache: 'no-store' });
        const res = await r.json();
        if (!res.ok || !res.unit) {
            showNotification('Unable to load unit history', 'error');
            return;
        }

        const unit = res.unit || {};
        const stats = res.stats || {};
        const latest = res.latest_location || null;
        const recent = Array.isArray(res.recent_dispatches) ? res.recent_dispatches : [];
        const lines = [];

        lines.push(`History for ${String(unit.identifier || unitId).toUpperCase()}`);
        lines.push('');
        lines.push(`Type: ${unit.unit_type || 'N/A'}`);
        lines.push(`Current Status: ${String(unit.status || 'N/A').replace('_', ' ')}`);
        lines.push(`Last Status Update: ${formatDateTime(unit.last_status_at)}`);
        if (unit.current_incident_id) {
            const currentTitle = unit.incident_title || unit.incident_type || 'Incident';
            const currentCode = unit.incident_code ? ` (${unit.incident_code})` : '';
            lines.push(`Current Incident: ${currentTitle}${currentCode}`);
            lines.push(`Incident Location: ${unit.incident_location || 'N/A'}`);
        } else {
            lines.push('Current Incident: none');
        }

        lines.push('');
        lines.push(`Total Dispatches: ${Number(stats.total_dispatches || 0)}`);
        lines.push(`Dispatches Today: ${Number(stats.dispatches_today || 0)}`);
        lines.push(`GPS Logs Today: ${Number(stats.location_points_today || 0)}`);

        if (latest) {
            const speed = toNum(latest.speed_kph);
            const heading = toNum(latest.heading_deg);
            lines.push(`Last GPS Ping: ${formatDateTime(latest.recorded_at)}`);
            if (speed !== null) lines.push(`Latest Speed: ${speed.toFixed(1)} km/h`);
            if (heading !== null) lines.push(`Latest Heading: ${heading.toFixed(0)}°`);
        }

        if (recent.length) {
            lines.push('');
            lines.push('Recent Dispatches:');
            recent.forEach((d, i) => {
                const inc = d.incident_title || d.incident_type || d.incident_code || `Incident #${d.incident_id || '?'}`;
                const status = String(d.status || 'assigned').replace('_', ' ');
                lines.push(`${i + 1}. ${formatDateTime(d.assigned_at)} | ${status} | ${inc}`);
            });
        }

        alert(lines.join('\n'));
    } catch (e) {
        showNotification('Unable to load unit history', 'error');
    }
}

// Lightweight notification helper
function showNotification(msg, type) {
    const n = document.createElement('div');
    n.textContent = msg;
    n.style.cssText = 'position:fixed;top:20px;right:20px;padding:10px 14px;border-radius:8px;color:#fff;font-weight:600;z-index:9999;';
    n.style.background = type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#17a2b8';
    document.body.appendChild(n);
    setTimeout(() => n.remove(), 2500);
}

function focusMapToLocation(lat, lng) {
    if (!map || !Number.isFinite(lat) || !Number.isFinite(lng)) return;
    map.setView([lat, lng], 16, { animate: true });
    if (!searchLocationMarker) {
        searchLocationMarker = L.marker([lat, lng], {
            icon: getIcon('incident')
        }).addTo(map);
    } else {
        searchLocationMarker.setLatLng([lat, lng]);
    }
    searchLocationMarker.bindPopup(`<strong>Search Result</strong><br>Coords: ${lat.toFixed(6)}, ${lng.toFixed(6)}`);
}

function initSearchLocationControls() {
    const input = document.getElementById('search-location');
    if (!input) return;

    const handleSearch = async () => {
        const raw = String(input.value || '').trim();
        if (!raw) return;
        let coords = parseCoordsFromText(raw);
        if (!coords) {
            showNotification('Searching location...', 'info');
            coords = await geocodeIncidentLocation(raw);
        }
        if (!coords) {
            showNotification('Unable to locate this search in Quezon City', 'error');
            return;
        }
        input.dataset.lat = String(coords.lat);
        input.dataset.lon = String(coords.lng);
        focusMapToLocation(coords.lat, coords.lng);
    };

    input.addEventListener('keydown', (e) => {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        handleSearch();
    });
}

// ===============================
// LEGEND CONTROL
// ===============================
function addLegendControl() {
    const legend = L.control({ position: 'bottomright' });
    legend.onAdd = function () {
        const div = L.DomUtil.create('div', 'map-legend');
        div.style.background = '#fff';
        div.style.border = '1px solid #dadce0';
        div.style.padding = '10px';
        div.style.borderRadius = '8px';
        div.style.boxShadow = '0 1px 3px rgba(0,0,0,0.2)';
        div.style.fontSize = '12px';
        div.innerHTML = `
            <div style="font-weight:600;margin-bottom:6px">Legend</div>
            <div style="display:flex;align-items:center;margin-bottom:4px">${markerLegendSwatch('ambulance')}Ambulance</div>
            <div style="display:flex;align-items:center;margin-bottom:4px">${markerLegendSwatch('police')}Police</div>
            <div style="display:flex;align-items:center;margin-bottom:4px">${markerLegendSwatch('fire')}Fire</div>
            <div style="display:flex;align-items:center;margin-bottom:4px">${markerLegendSwatch('rescue')}Rescue</div>
            <div style="display:flex;align-items:center">${markerLegendSwatch('incident')}Incident</div>
            <div style="margin-top:6px;font-size:11px;color:#666">Heatmap shows recent hotspots</div>
        `;
        return div;
    };
    legend.addTo(map);
}

// ===============================
// INIT MAP
// ===============================
document.addEventListener("DOMContentLoaded", initMap);
document.addEventListener("DOMContentLoaded", initSearchLocationControls);
// Focus a unit from URL after init
document.addEventListener("DOMContentLoaded", () => {
    try {
        const params = new URLSearchParams(window.location.search);
        const unit = params.get('unit');
        const unitId = params.get('unit_id');
        const incidentId = params.get('incident_id');
        const incidentLabel = params.get('incident');
        const fromLat = params.get('from_lat');
        const fromLng = params.get('from_lng');
        const toLat = params.get('to_lat');
        const toLng = params.get('to_lng');
        if (unit || unitId || (fromLat && fromLng)) {
            pendingTrackRequest = {
                unit: unit || '',
                unitId: unitId || '',
                fromLat: fromLat || '',
                fromLng: fromLng || ''
            };
            pendingTrackAttempts = 0;
            setTimeout(tryTrackPendingUnit, 300);
        }
        if (unit || unitId || incidentId || ((fromLat && fromLng) && (toLat && toLng))) {
            activeRouteState = activeRouteState || {
                unit: unit || '',
                unitId: unitId || '',
                incidentId: incidentId || '',
                incidentLocation: incidentLabel || '',
                toLat: toLat || '',
                toLng: toLng || ''
            };
        }
        if ((unit || unitId || incidentId) && !(toLat && toLng)) {
            pendingRouteRequest = {
                unit: unit || '',
                unitId: unitId || '',
                incidentId: incidentId || '',
                incidentLocation: incidentLabel || '',
                fromLat: fromLat || '',
                fromLng: fromLng || '',
                toLat: toLat || '',
                toLng: toLng || ''
            };
            pendingRouteAttempts = 0;
            setTimeout(() => {
                tryShowPendingRoute().catch(() => {});
            }, 350);
        }
    } catch (e) {}
});

// Apply unit-type filter from the UI
document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('unit-filter');
    if (!sel) return;
    sel.addEventListener('change', (e) => {
        unitFilter = (e.target.value || '').toLowerCase();
        updateMapVisibility();
        showNotification(unitFilter ? `Showing ${unitFilter} units` : 'Showing all units', 'info');
    });
});
</script>


<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>
<script src="js/routing.js"></script>
<script src="js/place-autocomplete.js"></script>
<script>
// Load dispatched units and render list + map markers
function loadDispatchedUnits() {
    fetch('api/units_list.php?status=dispatched')
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            const items = res.items || [];
            indexDispatchedUnits(items);
            renderUnitCards(items);
            syncUnitMarkers(items);
            startLivePolling();
        })
        .catch(() => {});
}

function indexDispatchedUnits(items) {
    dispatchedUnitsByIdentifier = {};
    (items || []).forEach(u => {
        if (u && u.identifier) {
            dispatchedUnitsByIdentifier[String(u.identifier)] = u;
            rememberUnitIdentity(u);
        }
    });
    tryTrackPendingUnit();
    tryShowPendingRoute().catch(() => {});
    refreshActiveRoute();
}

function renderUnitCards(items) {
    const container = document.getElementById('unit-scroll-container');
    if (!container) return;
    container.innerHTML = '';
    const focusRequest = getFocusedUnitRequest();
    const sortedItems = Array.isArray(items) ? items.slice() : [];
    sortedItems.sort((a, b) => {
        const aFocused = isUnitFocused(a) ? 1 : 0;
        const bFocused = isUnitFocused(b) ? 1 : 0;
        return bFocused - aFocused;
    });

    const hasFocusedUnit = sortedItems.some((u) => isUnitFocused(u));
    if (!sortedItems.length) {
        const placeholder = buildFocusedUnitPlaceholder();
        container.innerHTML = placeholder || '<div class="unit-card"><div class="unit-header"><div><h4 class="unit-name">No dispatched units</h4><span class="unit-status">—</span></div></div></div>';
        return;
    }
    const statusClass = s => (
        s === 'enroute' ? 'enroute' : s === 'on_scene' ? 'emergency' : 'active'
    );
    if (!hasFocusedUnit && focusRequest) {
        container.insertAdjacentHTML('beforeend', buildFocusedUnitPlaceholder());
    }
    sortedItems.forEach(u => {
        const cls = statusClass(u.status || 'assigned');
        const title = (u.incident_title || u.incident_type || 'Dispatched Incident');
        const loc = (u.incident_location || 'Unknown location');
        const focused = isUnitFocused(u);
        const unitIdentifier = String(u.identifier || '');
        const unitId = String(u.id !== undefined && u.id !== null ? u.id : '');
        const currentIncidentId = String(u.current_incident_id || '');
        const unitLat = String(u.latitude || '');
        const unitLng = String(u.longitude || '');
        const incidentLat = String(u.incident_latitude || '');
        const incidentLng = String(u.incident_longitude || '');
        let distanceLine = '';
        let speedLine = '';
        if (u.latitude && u.longitude && u.incident_latitude && u.incident_longitude) {
            const dkm = haversine(parseFloat(u.latitude), parseFloat(u.longitude), parseFloat(u.incident_latitude), parseFloat(u.incident_longitude));
            if (!isNaN(dkm)) distanceLine = `<div><i class=\"fas fa-ruler\"></i> Distance: ${dkm.toFixed(2)} km</div>`;
        }
        if (u.speed_kph !== undefined && u.speed_kph !== null) {
            const v = parseFloat(u.speed_kph);
            if (!isNaN(v)) speedLine = `<div><i class=\"fas fa-tachometer-alt\"></i> Speed: ${v.toFixed(1)} km/h</div>`;
        }
        const card = document.createElement('div');
        card.className = `unit-card ${cls}${focused ? ' focused' : ''}`;
        card.setAttribute('data-unit', unitIdentifier);
        card.innerHTML = `
            <div class="unit-header">
                <div>
                    <h4 class="unit-name">${escapeHtml(unitIdentifier)}</h4>
                    <span class="unit-status">${escapeHtml((u.status || '').replace('_',' '))}</span>
                </div>
            </div>
            <div class="unit-details">
                <div><i class="fas fa-exclamation-triangle"></i> ${escapeHtml(title)}</div>
                <div><i class="fas fa-map-marker-alt"></i> ${escapeHtml(loc)}</div>
                ${distanceLine}
                ${speedLine}
            </div>
            <div class="unit-actions">
                <button class="btn-unit" type="button" data-gps-action="track" data-unit="${escapeAttr(unitIdentifier)}" data-unit-id="${escapeAttr(unitId)}" data-from-lat="${escapeAttr(unitLat)}" data-from-lng="${escapeAttr(unitLng)}">
                    <i class="fas fa-location-arrow"></i> Track
                </button>
                <button class="btn-unit" type="button" data-gps-action="route" data-unit="${escapeAttr(unitIdentifier)}" data-unit-id="${escapeAttr(unitId)}" data-incident-id="${escapeAttr(currentIncidentId)}" data-incident-location="${escapeAttr(loc)}" data-from-lat="${escapeAttr(unitLat)}" data-from-lng="${escapeAttr(unitLng)}" data-to-lat="${escapeAttr(incidentLat)}" data-to-lng="${escapeAttr(incidentLng)}">
                    <i class="fas fa-route"></i> Route
                </button>
                <button class="btn-unit" type="button" data-gps-action="history" data-unit="${escapeAttr(unitIdentifier)}" data-unit-id="${escapeAttr(unitId)}">
                    <i class="fas fa-history"></i> History
                </button>
            </div>
        `;
        container.appendChild(card);
    });
    if (hasFocusedUnit) {
        revealFocusedUnitCard();
    }
}

function syncUnitMarkers(items) {
    items.forEach(u => {
        const id = u.identifier;
        const type = u.unit_type || 'other';
        const lat = parseFloat(u.latitude);
        const lng = parseFloat(u.longitude);
        const speed = (u.speed_kph !== undefined && u.speed_kph !== null) ? parseFloat(u.speed_kph) : null;
        if (!isNaN(lat) && !isNaN(lng)) {
            const label = `${id}`;
            if (markers[id]) {
                markers[id].marker.setLatLng([lat, lng]);
                markers[id].marker.setIcon(getIcon(type));
                const popupHtml = `
                    <strong>${label}</strong><br>
                    ${typeof speed === 'number' && isFinite(speed) ? `Speed: ${speed.toFixed(1)} km/h<br>` : ''}
                    Coords: ${lat.toFixed(5)}, ${lng.toFixed(5)}
                `;
                markers[id].marker.bindPopup(popupHtml);
                markers[id].speedKph = speed;
                markers[id].unitType = String(type || '').toLowerCase();
                markers[id].unitDbId = u.id !== undefined && u.id !== null ? String(u.id) : markers[id].unitDbId;
            } else {
                addUnitMarker(id, lat, lng, label, type, speed, u.id);
            }
        }
        rememberUnitIdentity(u);
    });
    tryTrackPendingUnit();
    tryShowPendingRoute().catch(() => {});
    refreshActiveRoute();
}

function initFirebaseLiveTracking() {
    rtdb.ref('live_locations').on('value', (snapshot) => {
        const data = snapshot.val() || {};
        const seenKeys = new Set();

        Object.values(data).forEach((r) => {
            const lat = parseFloat(r.lat);
            const lng = parseFloat(r.lng);
            if (isNaN(lat) || isNaN(lng)) return;

            const key = String(r.unitCode || r.responderId || '').trim();
            if (!key) return;
            seenKeys.add(key);

            const label = `${key} — ${r.responderName || 'Responder'}`;
            const speedKph = typeof r.speed === 'number' ? r.speed * 3.6 : null;

            const status = String(r.status || 'available');
            const isEnRoute = status === 'en_route';
            const type = isEnRoute
                ? String(r.department || r.unitType || 'other').toLowerCase()
                : 'idle';

            if (markers[key]) {
                markers[key].marker.setLatLng([lat, lng]);
                markers[key].marker.setIcon(getIcon(type));
                markers[key].marker.bindPopup(`
                    <strong>${label}</strong><br>
                    Status: ${r.status || 'unknown'}<br>
                    ${speedKph !== null ? `Speed: ${speedKph.toFixed(1)} km/h<br>` : ''}
                    Coords: ${lat.toFixed(5)}, ${lng.toFixed(5)}<br>
                    <em>Live GPS</em>
                `);
                markers[key].isLive = true;
            } else {
                addUnitMarker(key, lat, lng, label, type, speedKph, r.responderId);
                markers[key].isLive = true;
            }
        });

        Object.keys(markers).forEach((key) => {
            if (markers[key].isLive && !seenKeys.has(key)) {
                map.removeLayer(markers[key].marker);
                delete markers[key];
            }
        });
    }, (error) => {
        console.error('Firebase live_locations read failed:', error);
        showNotification('Live GPS feed disconnected', 'error');
    });
}

function syncUnitMarkers(items) {
    items.forEach(u => {
        const id = u.identifier;
        const type = u.unit_type || 'other';
        const lat = parseFloat(u.latitude);
        const lng = parseFloat(u.longitude);
        const speed = (u.speed_kph !== undefined && u.speed_kph !== null) ? parseFloat(u.speed_kph) : null;

        // If Firebase live GPS already owns this marker, don't let the
        // MySQL poll clobber its position — Firebase is the higher-frequency,
        // more current source while the unit is actively en route. Still
        // update non-position fields (incident title/status) via the popup.
        if (markers[id] && markers[id].isLive) {
            rememberUnitIdentity(u);
            return;
        }

        if (!isNaN(lat) && !isNaN(lng)) {
            const label = `${id}`;
            if (markers[id]) {
                markers[id].marker.setLatLng([lat, lng]);
                markers[id].marker.setIcon(getIcon(type));
                const popupHtml = `
                    <strong>${label}</strong><br>
                    ${typeof speed === 'number' && isFinite(speed) ? `Speed: ${speed.toFixed(1)} km/h<br>` : ''}
                    Coords: ${lat.toFixed(5)}, ${lng.toFixed(5)}
                `;
                markers[id].marker.bindPopup(popupHtml);
                markers[id].speedKph = speed;
                markers[id].unitType = String(type || '').toLowerCase();
                markers[id].unitDbId = u.id !== undefined && u.id !== null ? String(u.id) : markers[id].unitDbId;
            } else {
                addUnitMarker(id, lat, lng, label, type, speed, u.id);
            }
        }
        rememberUnitIdentity(u);
    });
    tryTrackPendingUnit();
    tryShowPendingRoute().catch(() => {});
    refreshActiveRoute();
}

function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c] || c);
}
function escapeAttr(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c] || c);
}

document.addEventListener('click', function(e) {
    const btn = e.target.closest('[data-gps-action]');
    if (!btn) return;
    const action = btn.getAttribute('data-gps-action') || '';
    const unit = btn.getAttribute('data-unit') || '';
    const options = {
        unitId: btn.getAttribute('data-unit-id') || '',
        incidentId: btn.getAttribute('data-incident-id') || '',
        incidentLocation: btn.getAttribute('data-incident-location') || '',
        fromLat: btn.getAttribute('data-from-lat') || '',
        fromLng: btn.getAttribute('data-from-lng') || '',
        toLat: btn.getAttribute('data-to-lat') || '',
        toLng: btn.getAttribute('data-to-lng') || ''
    };

    if (action === 'track') {
        trackUnit(unit, options);
        return;
    }
    if (action === 'route') {
        showUnitRoute(unit, options);
        return;
    }
    if (action === 'history') {
        unitHistory(unit, options);
    }
});

// Haversine distance in km
function haversine(lat1, lon1, lat2, lon2) {
    const R = 6371; // km
    const toRad = d => d * Math.PI / 180;
    const dLat = toRad(lat2 - lat1);
    const dLon = toRad(lon2 - lon1);
    const a = Math.sin(dLat/2) * Math.sin(dLat/2) +
                        Math.cos(toRad(lat1)) * Math.cos(toRad(lat2)) *
                        Math.sin(dLon/2) * Math.sin(dLon/2);
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    return R * c;
}

function loadAvailableUnits() {
    fetch('api/units_list.php?status=available')
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            const items = res.items || [];
            // Only add markers for real available units from the database
            if (!items.length) {
                // No fallback sample markers
                return;
            }
            items.forEach(u => {
                const id = u.identifier;
                const type = u.unit_type || 'other';
                const lat = parseFloat(u.latitude);
                const lng = parseFloat(u.longitude);
                const speed = (u.speed_kph !== undefined && u.speed_kph !== null) ? parseFloat(u.speed_kph) : null;
                if (!isNaN(lat) && !isNaN(lng)) {
                    if (markers[id]) {
                        markers[id].marker.setLatLng([lat, lng]);
                        markers[id].marker.setIcon(getIcon(type));
                        markers[id].speedKph = speed;
                        markers[id].unitType = String(type || '').toLowerCase();
                        markers[id].unitDbId = u.id !== undefined && u.id !== null ? String(u.id) : markers[id].unitDbId;
                    } else {
                        addUnitMarker(id, lat, lng, `${id}`, type, speed, u.id);
                    }
                }
                rememberUnitIdentity(u);
            });
            tryTrackPendingUnit();
            tryShowPendingRoute().catch(() => {});
            refreshActiveRoute();
        })
        .catch(() => {});
}

// Live polling to update unit positions/speeds every 5s
let livePollTimer = null;
function startLivePolling() {
    if (livePollTimer) return;
    livePollTimer = setInterval(() => {
        fetch('api/units_list.php?status=dispatched')
            .then(r => r.json())
            .then(res => {
                if (!res.ok) return;
                const items = res.items || [];
                indexDispatchedUnits(items);
                syncUnitMarkers(items);
            })
            .catch(() => {});
    }, 5000);
}
</script>


</body>
<style>
/* Style for autocomplete dropdown */
.autocomplete-dropdown {
    position: absolute;
    background: #fff;
    border: 1px solid #e5e7eb;
    z-index: 2000;
    width: 100%;
    max-height: 180px;
    overflow-y: auto;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
.autocomplete-dropdown div {
    padding: 8px 12px;
    cursor: pointer;
}
.autocomplete-dropdown div:hover {
    background: #f0f0f0;
}
</style>
</html>
