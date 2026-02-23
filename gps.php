<?php
require_once __DIR__ . '/includes/auth.php';
// Require full login (including OTP verification) before loading page
require_login('gps.php');

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
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="CSS/cards.css">
    <link rel="stylesheet" href="css/gps.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
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


            <!-- Tracking Controls -->
            <div style="height: 3.5rem;"></div>
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
                        <input type="text" id="search-location" placeholder="Enter address or coordinates" autocomplete="off" style="position:relative;z-index:1100;">
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
                            <button class="map-btn active" onclick="toggleLayer('unit', this)">
                                <i class="fas fa-ambulance"></i> Units
                            </button>
                            <button class="map-btn" onclick="toggleLayer('incident', this)">
                                <i class="fas fa-exclamation-triangle"></i> Incidents
                            </button>
                            <button class="map-btn" onclick="toggleHeatmap(this)">
                                <i class="fas fa-fire-alt"></i> Heatmap
                            </button>
                            
                            <button class="map-btn" onclick="centerMap()">
                                <i class="fas fa-crosshairs"></i> Center
                            </button>
                            <button class="map-btn" onclick="refreshMap()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                    <div class="map-viewport" id="map" style="width:100%;">
                        
                    </div>
                </div>

                <!-- Units Panel -->
                <div class="unit-panel">
                    <h3 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1.5rem; display: flex; align-items: center;">
                        <i class="fas fa-truck" style="margin-right: 0.5rem; color: #28a745;"></i>
                        Unit Status & Dispatched
                    </h3>
                    <!-- Scrollable container for units -->
                    <div class="unit-scroll-container" id="unit-scroll-container"></div>
                </div>
            </div>

        </div>
    </div>

    <!-- Uncomment if already have content -->
    <?php include('includes/admin-footer.php'); ?>

    <!-- ============================================
         COMPLETE FUNCTIONAL GPS TRACKING SYSTEM
         ============================================ -->
    <script>
let map;
let markers = {};
let activeLayers = ['unit'];
let qcBoundaryLayers = { halo: null, line: null };
let routes = {};
let QC_BOUNDS_GLOBAL;
let unitFilter = '';
let heatLayer = null;
let heatActive = false;
let heatHotspotMarker = null;
let heatLegendControl = null;
let dispatchedUnitsByIdentifier = {};
let unitIdentifierById = {};
let incidentGeocodeCache = {};
let searchLocationMarker = null;
let pendingTrackRequest = null;
let pendingTrackAttempts = 0;
const MAX_PENDING_TRACK_ATTEMPTS = 20;
const QC_VIEWBOX = '121.0000,14.7500,121.1000,14.6000';
const HEATMAP_GRADIENT = {
    0.18: '#2d5be3',
    0.35: '#18b7ff',
    0.52: '#4ef542',
    0.68: '#ddff40',
    0.82: '#ffbb2f',
    0.94: '#ff7043',
    1.0: '#f44336'
};

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

  // OpenStreetMap tiles
  L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
    attribution: "© OpenStreetMap contributors"
  }).addTo(map);

    // Load and display Quezon City border from GeoJSON
    fetch('quezon_city.geojson')
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
    map.on('zoomend', () => {
        if (!heatLayer || !heatActive) return;
        const z = map.getZoom();
        const radius = z >= 16 ? 32 : (z >= 14 ? 40 : 52);
        heatLayer.setOptions({ radius: radius, blur: radius * 0.9 });
    });

    // Plot route if parameters provided
    try {
        const params = new URLSearchParams(window.location.search);
        const fromLat = parseFloat(params.get('from_lat'));
        const fromLng = parseFloat(params.get('from_lng'));
        const toLat = parseFloat(params.get('to_lat'));
        const toLng = parseFloat(params.get('to_lng'));
        if (!isNaN(fromLat) && !isNaN(fromLng) && !isNaN(toLat) && !isNaN(toLng)) {
            // Remove any existing route before plotting new one
            if (window.currentRoutingControl && typeof window.currentRoutingControl.remove === 'function') {
                try { window.currentRoutingControl.remove(); } catch (e) {}
                window.currentRoutingControl = null;
            }
            if (typeof addRouteToIncident === 'function') {
                addRouteToIncident(fromLat, fromLng, toLat, toLng);
                showNotification('Route loaded for dispatched unit', 'success');
            }
        }
    } catch (e) {}
}

// ===============================
// MARKERS
// ===============================
function getIcon(type) {
    const icons = {
        ambulance: "https://maps.google.com/mapfiles/ms/icons/green-dot.png",
        police: "https://maps.google.com/mapfiles/ms/icons/blue-dot.png",
        fire: "https://maps.google.com/mapfiles/ms/icons/red-dot.png",
        incident: "https://maps.google.com/mapfiles/ms/icons/yellow-dot.png"
    };

    return L.icon({
        iconUrl: icons[type] || icons.incident,
        iconSize: [32, 32],
        iconAnchor: [16, 32]
    });
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
    if (heatActive) {
        loadHeatmap();
    }
}

function selectRoute(routeId) {
    // Hide all routes first
    Object.values(routes).forEach(r => map.removeLayer(r));
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
    const silent = !!(options && options.silent);
    const normalized = normalizeUnitIdentifier(unitId);
    if (!normalized) {
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

    if (!entry) {
        if (!silent) showNotification('Unit not found', 'error');
        return false;
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

async function showUnitRoute(unitId) {
    const unit = dispatchedUnitsByIdentifier[unitId];
    if (!unit) {
        showNotification('Unable to find dispatched unit details', 'error');
        return;
    }

    const fromLat = toNum(unit.latitude);
    const fromLng = toNum(unit.longitude);
    let toLat = toNum(unit.incident_latitude);
    let toLng = toNum(unit.incident_longitude);

    if (fromLat === null || fromLng === null) {
        showNotification('Route unavailable: missing unit coordinates', 'error');
        return;
    }

    if (toLat === null || toLng === null) {
        const parsed = parseCoordsFromText(unit.incident_location);
        if (parsed) {
            toLat = parsed.lat;
            toLng = parsed.lng;
        }
    }

    if ((toLat === null || toLng === null) && unit.incident_location) {
        showNotification('Locating incident address...', 'info');
        const geocoded = await geocodeIncidentLocation(unit.incident_location);
        if (geocoded) {
            toLat = geocoded.lat;
            toLng = geocoded.lng;
            unit.incident_latitude = String(toLat);
            unit.incident_longitude = String(toLng);
        }
    }

    if (toLat === null || toLng === null) {
        showNotification('Route unavailable: unable to locate incident address', 'error');
        return;
    }
    if (typeof addRouteToIncident !== 'function') {
        showNotification('Routing is not ready yet', 'error');
        return;
    }

    addRouteToIncident(fromLat, fromLng, toLat, toLng);
}

async function unitHistory(unitId) {
    try {
        const r = await fetch('api/unit_history.php?identifier=' + encodeURIComponent(unitId));
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
            <div style="display:flex;align-items:center;margin-bottom:4px"><img src="https://maps.google.com/mapfiles/ms/icons/green-dot.png" width="14" height="14" style="margin-right:6px">Ambulance</div>
            <div style="display:flex;align-items:center;margin-bottom:4px"><img src="https://maps.google.com/mapfiles/ms/icons/blue-dot.png" width="14" height="14" style="margin-right:6px">Police</div>
            <div style="display:flex;align-items:center;margin-bottom:4px"><img src="https://maps.google.com/mapfiles/ms/icons/red-dot.png" width="14" height="14" style="margin-right:6px">Fire</div>
            <div style="display:flex;align-items:center"><img src="https://maps.google.com/mapfiles/ms/icons/yellow-dot.png" width="14" height="14" style="margin-right:6px">Incident</div>
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
        const fromLat = params.get('from_lat');
        const fromLng = params.get('from_lng');
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
// Auto-reload heatmap when time range changes
document.addEventListener('DOMContentLoaded', () => {
    const tr = document.getElementById('time-range');
    if (tr) {
        tr.addEventListener('change', () => {
            if (heatActive) loadHeatmap(true);
        });
    }
});
</script>


<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<script src="https://unpkg.com/leaflet.heat/dist/leaflet-heat.js"></script>
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
}

function renderUnitCards(items) {
    const container = document.getElementById('unit-scroll-container');
    if (!container) return;
    container.innerHTML = '';
    if (!items.length) {
        container.innerHTML = '<div class="unit-card"><div class="unit-header"><div><h4 class="unit-name">No dispatched units</h4><span class="unit-status">—</span></div></div></div>';
        return;
    }
    const statusClass = s => (
        s === 'enroute' ? 'enroute' : s === 'on_scene' ? 'emergency' : 'active'
    );
    items.forEach(u => {
        const cls = statusClass(u.status || 'assigned');
        const title = (u.incident_title || u.incident_type || 'Dispatched Incident');
        const loc = (u.incident_location || 'Unknown location');
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
        card.className = `unit-card ${cls}`;
        card.setAttribute('data-unit', u.identifier);
        card.innerHTML = `
            <div class="unit-header">
                <div>
                    <h4 class="unit-name">${escapeHtml(u.identifier)}</h4>
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
                <button class="btn-unit" onclick="trackUnit('${escapeAttr(u.identifier)}')"><i class="fas fa-location-arrow"></i> Track</button>
                <button class="btn-unit" onclick="showUnitRoute('${escapeAttr(u.identifier)}')"><i class="fas fa-route"></i> Route</button>
                <button class="btn-unit" onclick="unitHistory('${escapeAttr(u.identifier)}')"><i class="fas fa-history"></i> History</button>
            </div>
        `;
        container.appendChild(card);
    });
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
                const popupHtml = `
                    <strong>${label}</strong><br>
                    ${typeof speed === 'number' && isFinite(speed) ? `Speed: ${speed.toFixed(1)} km/h<br>` : ''}
                    Coords: ${lat.toFixed(5)}, ${lng.toFixed(5)}
                `;
                markers[id].marker.bindPopup(popupHtml);
                markers[id].speedKph = speed;
                markers[id].unitDbId = u.id !== undefined && u.id !== null ? String(u.id) : markers[id].unitDbId;
            } else {
                addUnitMarker(id, lat, lng, label, type, speed, u.id);
            }
        }
        rememberUnitIdentity(u);
    });
    tryTrackPendingUnit();
}

function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c] || c);
}
function escapeAttr(s) {
    return String(s || '').replace(/['"]/g, '_');
}

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
                    addUnitMarker(id, lat, lng, `${id}`, type, speed, u.id);
                }
                rememberUnitIdentity(u);
            });
            tryTrackPendingUnit();
        })
        .catch(() => {});
}

// ===============================
// HEATMAP
// ===============================
function toggleHeatmap(el) {
    heatActive = !heatActive;
    if (el) {
        if (heatActive) el.classList.add('active'); else el.classList.remove('active');
    }
    if (heatActive) {
        loadHeatmap(true);
        showNotification('Heatmap enabled', 'info');
    } else {
        if (heatLayer) { map.removeLayer(heatLayer); heatLayer = null; }
        if (heatHotspotMarker) { map.removeLayer(heatHotspotMarker); heatHotspotMarker = null; }
        showNotification('Heatmap disabled', 'info');
    }
}

function clearHeatmapOverlays() {
    if (heatLayer) {
        map.removeLayer(heatLayer);
        heatLayer = null;
    }
    if (heatHotspotMarker) {
        map.removeLayer(heatHotspotMarker);
        heatHotspotMarker = null;
    }
    if (heatLegendControl) {
        map.removeControl(heatLegendControl);
        heatLegendControl = null;
    }
}

function findHeatHotspot(points) {
    const gridSize = 0.003; // ~300m buckets in Metro Manila latitude
    const buckets = new Map();
    (points || []).forEach((point) => {
        const lat = parseFloat(point[0]);
        const lng = parseFloat(point[1]);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        const intensity = parseFloat(point[2]);
        const weight = Number.isFinite(intensity) ? intensity : 1;
        const bucketLat = Math.round(lat / gridSize) * gridSize;
        const bucketLng = Math.round(lng / gridSize) * gridSize;
        const key = `${bucketLat.toFixed(6)},${bucketLng.toFixed(6)}`;
        if (!buckets.has(key)) {
            buckets.set(key, { latSum: 0, lngSum: 0, count: 0, weight: 0 });
        }
        const bucket = buckets.get(key);
        bucket.latSum += lat;
        bucket.lngSum += lng;
        bucket.count += 1;
        bucket.weight += weight;
    });

    let best = null;
    buckets.forEach((bucket) => {
        if (!best || bucket.weight > best.weight || (bucket.weight === best.weight && bucket.count > best.count)) {
            best = bucket;
        }
    });

    if (!best || !best.count) return null;
    return {
        lat: best.latSum / best.count,
        lng: best.lngSum / best.count,
        count: best.count,
        weight: best.weight
    };
}

function loadHeatmap(initial) {
    const params = new URLSearchParams();
    const range = String(document.getElementById('time-range')?.value || 'live');
    if (range === 'live' || range === '1hour') {
        params.set('hours', '1');
    } else if (range === '24hours') {
        params.set('hours', '24');
    } else if (range === '7days') {
        params.set('days', '7');
    } else {
        params.set('days', '90');
    }
    fetchHeatmap(params, initial, true);
}

function fetchHeatmap(params, initial, allowFallbackToAll) {
    fetch('api/incidents_heatmap.php?' + params.toString())
        .then(r => r.json())
        .then(res => {
            if (!heatActive) return;
            if (!res.ok) {
                if (allowFallbackToAll) {
                    const allParams = new URLSearchParams();
                    allParams.set('all', '1');
                    fetchHeatmap(allParams, initial, false);
                    return;
                }
                if (initial) showNotification('Unable to load incident heatmap', 'error');
                return;
            }
            const points = res.points || [];
            clearHeatmapOverlays();
            if (!points.length) {
                if (allowFallbackToAll) {
                    const allParams = new URLSearchParams();
                    allParams.set('all', '1');
                    fetchHeatmap(allParams, initial, false);
                    return;
                }
                if (initial) showNotification('No hotspot data found for incidents with coordinates', 'info');
                return;
            }
            const zoom = map ? map.getZoom() : 13;
            const radius = zoom >= 16 ? 34 : (zoom >= 14 ? 44 : 58);
            const blobPoints = buildHeatBlobPoints(points, res.hotspots);
            heatLayer = L.heatLayer(blobPoints, {
                radius: radius,
                blur: radius * 1.05,
                maxZoom: 18,
                minOpacity: 0.32,
                max: 1.0,
                gradient: HEATMAP_GRADIENT
            });
            heatLayer.addTo(map);
            addHeatLegendControl();
            if (initial) {
                const count = Number.isFinite(Number(res.count)) ? Number(res.count) : points.length;
                const clusters = Number.isFinite(Number(res.cluster_count)) ? Number(res.cluster_count) : points.length;
                showNotification(`Heatmap loaded (${count} incidents, ${clusters} hotspot zones)`, 'success');
            }

            let hotspot = null;
            if (Array.isArray(res.hotspots) && res.hotspots.length) {
                const top = res.hotspots[0];
                const hLat = parseFloat(top.latitude);
                const hLng = parseFloat(top.longitude);
                if (Number.isFinite(hLat) && Number.isFinite(hLng)) {
                    hotspot = {
                        lat: hLat,
                        lng: hLng,
                        count: parseInt(top.incident_count, 10) || 0,
                        weight: parseFloat(top.score) || 0
                    };
                }
            }
            if (!hotspot) {
                hotspot = findHeatHotspot(points);
            }
            if (hotspot) {
                heatHotspotMarker = L.circleMarker([hotspot.lat, hotspot.lng], {
                    radius: 10,
                    color: '#ef4444',
                    weight: 2,
                    fillColor: '#ef4444',
                    fillOpacity: 0.35
                }).addTo(map).bindPopup(
                    `<strong>Top Incident Hotspot</strong><br>Incidents in area: ${hotspot.count}`
                );
                if (initial) {
                    map.flyTo([hotspot.lat, hotspot.lng], Math.max(map.getZoom(), 14), { duration: 0.6 });
                    heatHotspotMarker.openPopup();
                }
            }
        })
        .catch(() => {
            if (initial) showNotification('Unable to load incident heatmap', 'error');
        });
}

function buildHeatBlobPoints(points, hotspots) {
    const sourcePoints = Array.isArray(points) ? points : [];
    const sourceHotspots = Array.isArray(hotspots) ? hotspots : [];
    const expanded = [];

    // Keep base points so exact event locations still contribute.
    sourcePoints.forEach((p) => {
        const lat = parseFloat(p[0]);
        const lng = parseFloat(p[1]);
        const intensity = parseFloat(p[2]);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;
        const w = Number.isFinite(intensity) ? Math.max(0.08, Math.min(1, intensity)) : 0.25;
        expanded.push([lat, lng, w]);
    });

    const rings = [
        { meters: 140, points: 8, weight: 0.92 },
        { meters: 280, points: 10, weight: 0.76 },
        { meters: 460, points: 14, weight: 0.56 },
        { meters: 700, points: 18, weight: 0.36 },
        { meters: 940, points: 22, weight: 0.2 }
    ];

    sourceHotspots.slice(0, 50).forEach((h) => {
        const lat = parseFloat(h.latitude);
        const lng = parseFloat(h.longitude);
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return;

        const baseIntensityRaw = parseFloat(h.intensity);
        const baseIntensity = Number.isFinite(baseIntensityRaw) ? baseIntensityRaw : 0.5;
        const count = parseFloat(h.incident_count);
        const densityBoost = Number.isFinite(count) ? Math.min(1.8, 1 + (Math.log(count + 1) * 0.42)) : 1;
        const centerWeight = Math.max(0.12, Math.min(1, baseIntensity * densityBoost));

        expanded.push([lat, lng, centerWeight]);

        rings.forEach((ring) => {
            const meters = ring.meters * densityBoost;
            const latDelta = meters / 111320;
            const cosLat = Math.cos((lat * Math.PI) / 180) || 1;
            const lngDelta = meters / (111320 * cosLat);
            for (let i = 0; i < ring.points; i += 1) {
                const angle = (Math.PI * 2 * i) / ring.points;
                const lat2 = lat + (latDelta * Math.sin(angle));
                const lng2 = lng + (lngDelta * Math.cos(angle));
                const weight = Math.max(0.05, Math.min(1, centerWeight * ring.weight));
                expanded.push([lat2, lng2, weight]);
            }
        });
    });

    return expanded;
}

function addHeatLegendControl() {
    if (!map) return;
    if (heatLegendControl) {
        map.removeControl(heatLegendControl);
    }
    heatLegendControl = L.control({ position: 'topright' });
    heatLegendControl.onAdd = function () {
        const div = L.DomUtil.create('div', 'heatmap-legend');
        div.style.background = 'rgba(255,255,255,0.95)';
        div.style.border = '1px solid #d1d5db';
        div.style.borderRadius = '8px';
        div.style.padding = '8px 10px';
        div.style.boxShadow = '0 4px 12px rgba(0,0,0,0.12)';
        div.style.fontSize = '11px';
        div.style.lineHeight = '1.2';
        div.innerHTML = `
            <div style="font-weight:700; margin-bottom:6px; color:#111827;">Heat Intensity</div>
            <div style="
                width: 140px;
                height: 10px;
                border-radius: 999px;
                border: 1px solid rgba(0,0,0,0.12);
                background: linear-gradient(90deg,
                    #2d5be3 0%,
                    #00b5ff 20%,
                    #39ff6a 40%,
                    #d9ff2f 60%,
                    #ffb300 78%,
                    #ff4b3e 92%,
                    #ff2da1 100%);
            "></div>
            <div style="display:flex; justify-content:space-between; margin-top:4px; color:#374151;">
                <span>Low</span><span>High</span>
            </div>
        `;
        L.DomEvent.disableClickPropagation(div);
        L.DomEvent.disableScrollPropagation(div);
        return div;
    };
    heatLegendControl.addTo(map);
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
