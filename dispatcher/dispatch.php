<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
// Require full login (including OTP verification) before loading page
require_role('dispatcher', 'dispatcher/dispatch.php');
$pageTitle = 'Emergency Dispatch Center';
$assetUrl = static function (string $relativePath) use ($rootDir): string {
    $fullPath = $rootDir . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    $version = @filemtime($fullPath) ?: time();
    return htmlspecialchars($relativePath . '?v=' . $version, ENT_QUOTES, 'UTF-8');
};

// Initialize default values
$activeIncidents = 0;
$availableUnits = 0;
$pendingCalls = 0;
$systemStatus = 'All systems operational';
$currentIncidentSummary = 'No active incident context';

// Fetch accurate data from database
try {
    require_once $rootDir . '/includes/db.php';
    $pdo = get_db_connection();
    
    if ($pdo) {
        // Get active incidents (pending or dispatched)
        $activeIncidents = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status IN ('pending','dispatched')")->fetch()['c'];
        
        // Get available units
        $availableUnits = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status='available'")->fetch()['c'];
        
        // Get pending calls
        $pendingCalls = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status='pending'")->fetch()['c'];

        $topIncident = $pdo->query("SELECT reference_no, type, location_address, priority
                                    FROM incidents
                                    WHERE status IN ('pending','dispatched','active','in_progress')
                                    ORDER BY FIELD(LOWER(priority),'critical','high','medium','low'), created_at DESC
                                    LIMIT 1")->fetch();
        if ($topIncident) {
            $currentIncidentSummary = trim(
                (string)($topIncident['reference_no'] ?? '') . ' ' .
                (string)($topIncident['type'] ?? '') . ' ' .
                (string)($topIncident['location_address'] ?? '') . ' ' .
                strtoupper((string)($topIncident['priority'] ?? ''))
            );
        }
        
        // Determine system status based on available units and active incidents
        if ($availableUnits === 0 && $activeIncidents > 0) {
            $systemStatus = 'Warning: No available units';
        } elseif ($activeIncidents > 10) {
            $systemStatus = 'High load: Multiple active incidents';
        } elseif ($availableUnits < 3 && $activeIncidents > 0) {
            $systemStatus = 'Limited resources available';
        } else {
            $systemStatus = 'All systems operational';
        }
    }
} catch (Throwable $e) {
    // Keep default values if database query fails
    error_log('Dispatch page database error: ' . $e->getMessage());
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
    <link rel="stylesheet" href="<?php echo $assetUrl('css/dispatch.css'); ?>">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css"/>
    <link rel="stylesheet" href="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.css"/>
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include $rootDir . '/includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Emergency Dispatch Center
       =================================== -->
    <div class="main-content">
        <div class="main-container">


            <section class="dispatch-hero">
                <div class="dispatch-hero-main">
                    <div class="dispatch-kicker">Dispatcher Operations Console</div>
                    <h1 class="dispatch-hero-title">Emergency Dispatch Center</h1>
                    <p class="dispatch-hero-text">
                        Real-time command view for incoming incidents, available responders, and live GPS positioning.
                    </p>

                    <div class="dispatch-stats-grid">
                        <div class="dispatch-stat-card critical">
                            <span class="dispatch-stat-label">Active Incidents</span>
                            <strong class="dispatch-stat-value"><?php echo $activeIncidents; ?></strong>
                        </div>
                        <div class="dispatch-stat-card ready">
                            <span class="dispatch-stat-label">Units Ready</span>
                            <strong class="dispatch-stat-value"><?php echo $availableUnits; ?></strong>
                        </div>
                        <div class="dispatch-stat-card queue">
                            <span class="dispatch-stat-label">Pending Calls</span>
                            <strong class="dispatch-stat-value"><?php echo $pendingCalls; ?></strong>
                        </div>
                    </div>
                </div>

                <div class="dispatch-hero-side">
                    <div class="status-command-card">
                        <div class="status-command-label">System Status</div>
                        <div class="status-command-value"><?php echo htmlspecialchars($systemStatus); ?></div>
                        <div class="status-command-meta">Current priority context</div>
                        <div class="status-command-summary"><?php echo htmlspecialchars($currentIncidentSummary); ?></div>
                    </div>
                </div>
            </section>

            <div class="alert-panel">
                <div class="alert-icon-wrap">
                    <i class="fas fa-satellite-dish"></i>
                </div>
                <div class="alert-content">
                    <div class="alert-label">Operational Feed</div>
                    <div class="alert-message">
                        <strong>System Status:</strong> <?php echo htmlspecialchars($systemStatus); ?>
                        <span class="alert-separator">|</span>
                        Active incidents: <?php echo $activeIncidents; ?>
                        <span class="alert-separator">|</span>
                        Available units: <?php echo $availableUnits; ?>
                    </div>
                </div>
            </div>

            <section class="quick-actions dispatch-actions-bar">
                <button class="quick-action-btn" onclick="emergencyBroadcast()">
                    <i class="fas fa-bullhorn"></i>
                    <span>Emergency Broadcast</span>
                </button>
                <button class="quick-action-btn" onclick="lockdownProtocol()">
                    <i class="fas fa-lock"></i>
                    <span>Lockdown Protocol</span>
                </button>
                <button class="quick-action-btn" onclick="massCasualty()">
                    <i class="fas fa-notes-medical"></i>
                    <span>Mass Casualty</span>
                </button>
                <button class="quick-action-btn" onclick="resourceRequest()">
                    <i class="fas fa-box"></i>
                    <span>Resource Request</span>
                </button>
            </section>

            <div class="dispatch-grid">
                <section class="dispatch-panel dispatch-panel-calls">
                    <div class="panel-header">
                        <div>
                            <div class="panel-eyebrow">Incident Queue</div>
                            <h2 class="panel-title">
                                <i class="fas fa-phone"></i>
                                Active Emergency Calls
                            </h2>
                        </div>
                        <span class="panel-badge panel-badge-danger"><?php echo $activeIncidents; ?> Active</span>
                    </div>
                    <div id="active-calls-container" class="dispatch-scroll-list">
                    <?php
                    // ...existing code for active calls...
                    ?>
                    </div>
                </section>

                <section class="dispatch-panel dispatch-panel-map">
                    <div class="panel-header panel-header-map">
                        <div>
                            <div class="panel-eyebrow">Live GPS Monitor</div>
                            <h2 class="panel-title">
                                <i class="fas fa-map-marked-alt"></i>
                                Live Dispatch Map
                            </h2>
                        </div>
                        <div class="map-toolbar">
                            <span class="map-live-pill"><span class="map-live-dot"></span> Live Tracking</span>
                            <button class="btn-action-small" onclick="refreshMap()">
                                <i class="fas fa-sync-alt"></i> Refresh
                            </button>
                        </div>
                    </div>
                    <div class="map-container dispatch-map-shell">
                        <div class="map-overlay-note">
                            <i class="fas fa-broadcast-tower"></i>
                            Monitor unit movement and incident positions in real time.
                        </div>
                        <div class="map-placeholder"></div>
                        <div class="map-viewport" id="map" style="width:100%; height:100%;"></div>
                    </div>
                </section>

                <section class="dispatch-panel dispatch-panel-units">
                    <div class="panel-header">
                        <div>
                            <div class="panel-eyebrow">Response Assets</div>
                            <h2 class="panel-title">
                                <i class="fas fa-ambulance"></i>
                                Available Units
                            </h2>
                        </div>
                        <span id="available-units-count" class="panel-badge panel-badge-success"><?php echo $availableUnits; ?> Available</span>
                    </div>
                    <div id="available-units-container" class="dispatch-scroll-list">
                    <?php
                    // ...existing code for available units...
                    ?>
                    </div>
                </section>
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
                        include $rootDir . '/includes/gemini_helper.php';

                        // Real-time dispatch data from database
                        $dispatchData = [
                            'active_incidents' => $activeIncidents,
                            'available_units' => $availableUnits,
                            'pending_calls' => $pendingCalls,
                            'current_incident' => $currentIncidentSummary
                        ];

                        $recommendations = getDispatchRecommendations($dispatchData);
                        if ($recommendations) {
                            echo '<div class="ai-recommendation-text">' . nl2br(htmlspecialchars($recommendations)) . '</div>';
                        } else {
                            $aiError = function_exists('getGeminiLastError') ? trim((string) getGeminiLastError()) : '';
                            if ($aiError === '') {
                                $aiError = 'Unable to generate AI recommendations at this time.';
                            }
                            echo '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars($aiError) . '</div>';
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



<!-- Dispatch Modal (moved to end for guaranteed loading) -->
<div id="dispatch-modal" class="modal" style="display:none; position:fixed; top:0; left:0; width:100vw; height:100vh; background:rgba(0,0,0,0.4); z-index:9999; align-items:center; justify-content:center;">
    <form class="modal-content" style="background:#fff; padding:2.5rem 2.5rem 2rem 2.5rem; border-radius:16px; max-width:600px; width:98%; position:relative; box-shadow:0 8px 32px rgba(0,0,0,0.18); display:flex; flex-direction:column; gap:1.2rem; min-height:350px;">
        <span class="close" onclick="closeDispatchModal()" style="position:absolute; top:10px; right:20px; font-size:2rem; cursor:pointer;">&times;</span>
        <h2 style="margin:0 0 1.2rem 0; text-align:left; font-size:2rem; font-weight:700;">Dispatch Unit</h2>
        <div style="display:flex; flex-direction:column; gap:1.1rem;">
            <div style="display:flex; flex-direction:column; gap:0.3rem;">
                <label style="font-weight:600;">Incident Details</label>
                <div id="modal-incident-details" style="background:#f8f9fa; border-radius:7px; padding:0.75rem 1rem; font-size:1rem;"></div>
            </div>
            <div style="display:flex; flex-direction:column; gap:0.3rem;">
                <label for="unit-select" style="font-weight:600;">Available Units <span style="color:red">*</span></label>
                <select id="unit-select" style="width:100%; padding:0.7rem; border-radius:6px; border:1.5px solid #bbb; font-size:1.08rem; background:#f9f9f9;">
                    <option value="">-- Select --</option>
                </select>
            </div>
            <div style="display:flex; flex-direction:column; gap:0.3rem;">
                <label style="font-weight:600;">Unit Details</label>
                <div id="unit-details" style="background:#f1f3f4; border-radius:7px; padding:0.75rem 1rem; min-height:48px; font-size:0.98rem;"></div>
            </div>
        </div>
        <div style="display:flex; gap:1rem; justify-content:flex-end; margin-top:1.2rem;">
            <button type="button" onclick="closeDispatchModal()" style="background:#f1f1f1; color:#333; border:none; border-radius:6px; padding:0.7rem 1.5rem; font-size:1rem; font-weight:500; cursor:pointer;">Cancel</button>
            <button id="confirm-dispatch-btn" type="button" class="btn-dispatch" style="background:#007bff; color:#fff; border:none; border-radius:6px; padding:0.7rem 1.5rem; font-size:1rem; font-weight:600; cursor:pointer;">Confirm Dispatch</button>
        </div>
    </form>
</div>
<script>
// Modal logic (moved to end for guaranteed loading)
let currentIncidentId = null;
let currentIncidentLat = null;
let currentIncidentLng = null;
function toIncidentId(value) {
    if (value === null || value === undefined) return null;
    const raw = String(value).trim();
    if (raw === '') return null;
    const n = Number(raw);
    return Number.isInteger(n) ? n : null;
}
const sampleUnitProfilesByType = {
    police: { driver: 'Officer Cruz', plate: 'PN-1281' },
    fire: { driver: 'FF Santos', plate: 'FT-3482' },
    ambulance: { driver: 'EMT Dela Cruz', plate: 'AB-5523' },
    other: { driver: 'Responder Team', plate: 'TMP-0001' }
};
function getSampleUnitProfile(unitType) {
    const key = String(unitType || '').toLowerCase();
    return sampleUnitProfilesByType[key] || sampleUnitProfilesByType.other;
}
function openDispatchModal(incidentId) {
    currentIncidentId = toIncidentId(incidentId);
    document.getElementById('dispatch-modal').style.display = 'flex';
    if (currentIncidentId === null) {
        document.getElementById('modal-incident-details').innerHTML = '<span style="color:red">Incident not found.</span>';
        return;
    }
    // Fetch incident details and available units
    fetch('api/incident_details.php?id=' + encodeURIComponent(currentIncidentId))
        .then(r => r.json())
        .then(data => {
            if (data.incident) {
                const inc = data.incident;
                currentIncidentLat = inc && inc.latitude ? Number(inc.latitude) : null;
                currentIncidentLng = inc && inc.longitude ? Number(inc.longitude) : null;
                document.getElementById('modal-incident-details').innerHTML =
                    `<strong>Type:</strong> ${inc.type || ''}<br>` +
                    `<strong>Title:</strong> ${inc.title || ''}<br>` +
                    `<strong>Location:</strong> ${inc.location_address || 'N/A'}<br>` +
                    (inc.latitude && inc.longitude ? `<strong>Coordinates:</strong> ${inc.latitude}, ${inc.longitude}<br>` : '') +
                    `<strong>Priority:</strong> ${inc.priority || ''}`;
            } else {
                document.getElementById('modal-incident-details').innerHTML = '<span style="color:red">Incident not found.</span>';
            }
            // Populate units
            const select = document.getElementById('unit-select');
            select.innerHTML = '<option value="">-- Select --</option>';
            if (data.units && data.units.length) {
                data.units.forEach(u => {
                    const dist = (typeof u.distance_km === 'number' && isFinite(u.distance_km)) ? `${u.distance_km.toFixed(1)} km` : '';
                    const suffix = dist ? `${u.unit_type}, ${dist}` : `${u.unit_type}`;
                    select.innerHTML += `<option value="${u.id}" data-type="${u.unit_type}" data-identifier="${u.identifier}">${u.identifier} (${suffix})</option>`;
                });
            } else {
                // If no real units, show sample units in dropdown
                const samples = [
                    {id: 'sample-police', unit_type: 'police', identifier: 'police-unit-1'},
                    {id: 'sample-fire', unit_type: 'fire', identifier: 'fire-truck-1'},
                    {id: 'sample-ambulance', unit_type: 'ambulance', identifier: 'ambulance-1'}
                ];
                samples.forEach(u => {
                    select.innerHTML += `<option value="${u.id}" data-type="${u.unit_type}" data-identifier="${u.identifier}">${u.identifier} (${u.unit_type})</option>`;
                });
            }
        });
}
function closeDispatchModal() {
    document.getElementById('dispatch-modal').style.display = 'none';
    document.getElementById('modal-incident-details').innerHTML = '';
    document.getElementById('unit-select').innerHTML = '<option value="">-- Select --</option>';
    document.getElementById('unit-details').innerHTML = '';
}
document.addEventListener('DOMContentLoaded', function() {
    document.getElementById('unit-select').addEventListener('change', function() {
        const unitId = this.value;
        const selectedOption = this.options[this.selectedIndex];
        const selectedType = selectedOption ? selectedOption.getAttribute('data-type') : '';
        if (!unitId) {
            document.getElementById('unit-details').innerHTML = '';
            return;
        }
        // If sample unit, show static details
        if (unitId.startsWith('sample-')) {
            let details = {
                'sample-police': {
                    driver: 'Officer Cruz', plate: 'PN-1281', type: 'police', status: 'available', lat: 14.6500, lng: 121.0300
                },
                'sample-fire': {
                    driver: 'FF Santos', plate: 'FT-3482', type: 'fire', status: 'available', lat: 14.6700, lng: 121.0450
                },
                'sample-ambulance': {
                    driver: 'EMT Dela Cruz', plate: 'AB-5523', type: 'ambulance', status: 'available', lat: 14.6900, lng: 121.0600
                }
            };
            const u = details[unitId];
            let html =
                `<strong>Driver:</strong> ${u.driver}<br>` +
                `<strong>Plate #:</strong> ${u.plate}<br>` +
                `<strong>Type:</strong> ${u.type}<br>` +
                `<strong>Status:</strong> ${u.status}`;
            if (currentIncidentLat && currentIncidentLng && u.lat && u.lng) {
                const distKm = haversine(Number(u.lat), Number(u.lng), currentIncidentLat, currentIncidentLng).toFixed(2);
                html += `<br><strong>Distance to Incident:</strong> ${distKm} km`;
            }
            document.getElementById('unit-details').innerHTML = html;
        } else {
            fetch('api/unit_details.php?id=' + encodeURIComponent(unitId))
                .then(r => r.json())
                .then(data => {
                    if (data.unit) {
                        const u = data.unit;
                        const sampleProfile = getSampleUnitProfile(u.unit_type || selectedType);
                        let html =
                            `<strong>Driver:</strong> ${u.driver_name || sampleProfile.driver}<br>` +
                            `<strong>Plate #:</strong> ${u.plate_number || sampleProfile.plate}<br>` +
                            `<strong>Type:</strong> ${u.unit_type || ''}<br>` +
                            `<strong>Status:</strong> ${u.status || ''}`;
                        if (currentIncidentLat && currentIncidentLng && u.latitude && u.longitude) {
                            const distKm = haversine(Number(u.latitude), Number(u.longitude), currentIncidentLat, currentIncidentLng).toFixed(2);
                            html += `<br><strong>Distance to Incident:</strong> ${distKm} km`;
                        }
                        document.getElementById('unit-details').innerHTML = html;
                    } else {
                        const sampleProfile = getSampleUnitProfile(selectedType);
                        const typeLabel = selectedType || 'N/A';
                        document.getElementById('unit-details').innerHTML =
                            `<strong>Driver:</strong> ${sampleProfile.driver}<br>` +
                            `<strong>Plate #:</strong> ${sampleProfile.plate}<br>` +
                            `<strong>Type:</strong> ${typeLabel}<br>` +
                            `<strong>Status:</strong> unavailable`;
                    }
                });
        }
    });
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
    document.getElementById('confirm-dispatch-btn').onclick = function() {
        const btn = document.getElementById('confirm-dispatch-btn');
        btn.disabled = true;
        btn.textContent = 'Dispatching...';
        const unitSelect = document.getElementById('unit-select');
        const unitId = unitSelect.value;
        const selectedOption = unitSelect.options[unitSelect.selectedIndex];
        const unitIdentifier = selectedOption ? selectedOption.getAttribute('data-identifier') : '';
        if (!unitId || currentIncidentId === null) {
            alert('Please select a unit.');
            btn.disabled = false;
            btn.textContent = 'Confirm Dispatch';
            return;
        }
        // If sample unit, just redirect to GPS with static coordinates
        if (unitId.startsWith('sample-')) {
            let coords = {
                'sample-police': {lat: 14.6500, lng: 121.0300},
                'sample-fire': {lat: 14.6700, lng: 121.0450},
                'sample-ambulance': {lat: 14.6900, lng: 121.0600}
            };
            const u = coords[unitId];
            const qp = new URLSearchParams();
            qp.set('unit_id', unitId);
            if (unitIdentifier) qp.set('unit', unitIdentifier);
            qp.set('from_lat', String(u.lat));
            qp.set('from_lng', String(u.lng));
            // Try to get incident location for routing
            fetch('api/incident_details.php?id=' + encodeURIComponent(currentIncidentId))
                .then(r => r.json())
                .then(incRes => {
                    let toLat = null, toLng = null;
                    const inc = incRes.incident || {};
                    if (inc.latitude && inc.longitude) {
                        toLat = Number(inc.latitude);
                        toLng = Number(inc.longitude);
                    } else if (inc.location_address && inc.location_address.match(/\d+\.\d+,[ ]*\d+\.\d+/)) {
                        const parts = inc.location_address.split(',').map(Number);
                        toLat = parts[0];
                        toLng = parts[1];
                    }
                    if (toLat && toLng) {
                        qp.set('to_lat', String(toLat));
                        qp.set('to_lng', String(toLng));
                    }
                    window.location.href = 'gps.php?' + qp.toString();
                });
            return;
        }
        // Real unit: do original dispatch logic
        Promise.all([
            fetch('api/incident_details.php?id=' + encodeURIComponent(currentIncidentId)).then(r => r.json()),
            fetch('api/unit_details.php?id=' + encodeURIComponent(unitId)).then(r => r.json())
        ]).then(([incRes, unitRes]) => {
            const inc = incRes.incident || {};
            const u = unitRes.unit || {};
            // Fallbacks for incident location
            let toLat = null, toLng = null;
            if (inc.latitude && inc.longitude) {
                toLat = Number(inc.latitude);
                toLng = Number(inc.longitude);
            } else if (inc.location_address && inc.location_address.match(/\d+\.\d+,[ ]*\d+\.\d+/)) {
                const parts = inc.location_address.split(',').map(Number);
                toLat = parts[0];
                toLng = parts[1];
            } else {
                // Default fallback: QC Hall
                toLat = 14.6760;
                toLng = 121.0437;
            }
            // Fallbacks for unit location
            let fromLat = null, fromLng = null;
            if (u.latitude && u.longitude) {
                fromLat = Number(u.latitude);
                fromLng = Number(u.longitude);
            } else {
                const type = selectedOption ? selectedOption.getAttribute('data-type') : (u.unit_type || 'other');
                if (type === 'police') { fromLat = 14.6500; fromLng = 121.0300; }
                else if (type === 'fire') { fromLat = 14.6700; fromLng = 121.0450; }
                else if (type === 'ambulance') { fromLat = 14.6900; fromLng = 121.0600; }
                else { fromLat = 14.6760; fromLng = 121.0437; }
                // Optionally update unit location in DB
                fetch('api/unit_location_update.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ unit_id: unitId, latitude: fromLat, longitude: fromLng })
                });
            }
            // Plot locally if possible
            // Continue with dispatch (only update map/UI if successful)
            return fetch('api/dispatch_unit.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ incident_id: currentIncidentId, unit_id: unitId })
            }).then(r => r.json()).then(data => {
                if (data.ok) {
                    // Plot locally if possible (optional, or just redirect)
                    if (typeof addRouteToIncident === 'function' && fromLat && fromLng && toLat && toLng) {
                        addRouteToIncident(fromLat, fromLng, toLat, toLng);
                    }
                    // Redirect to GPS with routing params
                    const qp = new URLSearchParams();
                    qp.set('unit_id', unitId);
                    if (unitIdentifier) qp.set('unit', unitIdentifier);
                    if (fromLat && fromLng && toLat && toLng) {
                        qp.set('from_lat', String(fromLat));
                        qp.set('from_lng', String(fromLng));
                        qp.set('to_lat', String(toLat));
                        qp.set('to_lng', String(toLng));
                    }
                    window.location.href = 'gps.php?' + qp.toString();
                } else {
                    alert('Failed to dispatch unit: ' + (data.error || 'Unknown error'));
                    btn.disabled = false;
                    btn.textContent = 'Confirm Dispatch';
                }
            }).catch(() => {
                alert('Network error.');
                btn.disabled = false;
                btn.textContent = 'Confirm Dispatch';
            });
        });
    };
});
</script>
        </script>

        <!-- Uncomment if already have content -->
        <?php include $rootDir . '/includes/admin-footer.php'; ?>

    <script>
// Update unit status via AJAX
function unitStatus(unitId, status) {
    if (!unitId || !status) return;
    fetch('api/unit_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ unit_id: unitId, status: status })
    })
    .then(r => r.json())
    .then(data => {
        if (data.ok) {
            location.reload();
        } else {
            alert('Failed to update unit status: ' + (data.error || 'Unknown error'));
        }
    })
    .catch(() => alert('Network error.'));
}
let map;
let markers = {};
let incidentMarkers = {};
let QC_BOUNDS_GLOBAL;

// ===============================
// LEAFLET MAP INITIALIZATION
// ===============================
function initMap() {
    QC_BOUNDS_GLOBAL = L.latLngBounds(
        [14.6000, 121.0000],
        [14.7500, 121.1000]
    );
    map = L.map("map", {
        center: [14.6760, 121.0437],
        zoom: 13,
        maxBounds: QC_BOUNDS_GLOBAL,
        maxBoundsViscosity: 1.0
    });
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

    // Load and render units and incidents using gps.php logic
    loadDispatchedUnits();
    loadAvailableUnits();
    loadIncidentMarkers();
    addLegendControl();
    updateMapVisibility();
    startLivePolling();
    console.log("✅ Dispatch map loaded (Leaflet, real-time)");
}

function addIncidentMarker(id, lat, lng, info) {
    if (incidentMarkers[id]) {
        map.removeLayer(incidentMarkers[id]);
    }
    const marker = L.marker([lat, lng], { icon: getIncidentIcon() })
        .addTo(map)
        .bindPopup(`<strong>${info}</strong>`);
    incidentMarkers[id] = marker;
}


// Use the same getIcon as gps.php for all marker types

// ===============================
// ICONS (sync with gps.php)
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
// ===============================
// LEGEND CONTROL (sync with gps.php)
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
// MARKERS (sync with gps.php)
// ===============================
function addUnitMarker(id, lat, lng, label, type, speedKph) {
        const marker = L.marker([lat, lng], { icon: getIcon(type) })
                .addTo(map)
                .bindPopup(`
                        <strong>${label}</strong><br>
                        ${typeof speedKph === 'number' && isFinite(speedKph) ? `Speed: ${speedKph.toFixed(1)} km/h<br>` : ''}
                        Coords: ${lat.toFixed(5)}, ${lng.toFixed(5)}
                `);
        markers[id] = { marker, type: "unit", unitType: (type || '').toLowerCase(), speedKph: speedKph };
}

function addIncidentMarker(id, lat, lng, label) {
    const marker = L.marker([lat, lng], { icon: getIcon("incident") })
        .addTo(map)
        .bindPopup(`<strong>${label}</strong><br>🚨 Active Incident`);
    markers[id] = { marker, type: "incident" };
}

function updateMapVisibility() {
        Object.values(markers).forEach(item => {
            let visible = true;
            // Filtering logic placeholder (e.g., by unit type, status)
            // Example: if (unitFilter && item.type === 'unit' && item.unitType !== unitFilter) visible = false;
            visible ? map.addLayer(item.marker) : map.removeLayer(item.marker);
        });
}
// ===============================
// LOADERS (sync with gps.php)
// ===============================
function loadDispatchedUnits() {
    fetch('api/units_list.php?status=dispatched')
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            const items = res.items || [];
            syncUnitMarkers(items);
        })
        .catch(() => {});
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
            } else {
                addUnitMarker(id, lat, lng, label, type, speed);
            }
        }
    });
}

function loadAvailableUnits() {
    fetch('api/units_list.php?status=available')
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            const items = res.items || [];
            items.forEach(u => {
                const id = u.identifier;
                const type = u.unit_type || 'other';
                const lat = parseFloat(u.latitude);
                const lng = parseFloat(u.longitude);
                const speed = (u.speed_kph !== undefined && u.speed_kph !== null) ? parseFloat(u.speed_kph) : null;
                if (!isNaN(lat) && !isNaN(lng)) {
                    addUnitMarker(id, lat, lng, `${id}`, type, speed);
                }
            });
        })
        .catch(() => {});
}

function loadIncidentMarkers() {
    fetch('api/incidents_list.php?status=active')
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            const items = res.items || [];
            items.forEach(inc => {
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

function startLivePolling() {
    setInterval(() => {
        loadDispatchedUnits();
        loadAvailableUnits();
        loadIncidentMarkers();
    }, 10000); // 10 seconds
}

// ===============================
// MAP ACTIONS
// ===============================
function refreshMap() {
  Object.values(markers).forEach(marker => {
    const pos = marker.getLatLng();
    const newLat = pos.lat + (Math.random() - 0.5) * 0.001;
    const newLng = pos.lng + (Math.random() - 0.5) * 0.001;
    const clamped = clampToBounds(newLat, newLng);
    marker.setLatLng(clamped);
  });
  showNotification("Live map refreshed", "info");
}

function clampToBounds(lat, lng) {
  const sw = QC_BOUNDS_GLOBAL.getSouthWest();
  const ne = QC_BOUNDS_GLOBAL.getNorthEast();
  return [
    Math.min(Math.max(lat, sw.lat), ne.lat),
    Math.min(Math.max(lng, sw.lng), ne.lng)
  ];
}
</script>


    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://unpkg.com/leaflet-routing-machine@latest/dist/leaflet-routing-machine.js"></script>
    <script src="js/routing.js"></script>
<script>
// Fallback: populate Available Units panel from API if server-side rendering produced none
document.addEventListener('DOMContentLoaded', () => {
    try {
        const container = document.getElementById('available-units-container');
        if (!container) return;
        const hasCards = container.querySelector('.unit-card');
        if (hasCards) {
            const badge = document.querySelector('#available-units-count');
            const count = container.querySelectorAll('.unit-card.available').length;
            if (badge && count > 0) badge.textContent = String(count) + ' Available';
            return; // already rendered from PHP
        }
        fetch('api/units_list.php?status=available')
            .then(r => r.json())
            .then(res => {
                if (!res.ok) return;
                const items = res.items || [];
                if (!items.length) {
                    const badge = document.querySelector('#available-units-count');
                    if (badge) badge.textContent = '3 Available';
                    container.innerHTML = `
                        <div class="unit-card available">
                            <div class="unit-info">
                                <div class="unit-details">
                                    <div class="unit-name">police-unit-1</div>
                                    <div class="unit-meta">
                                        <span><i class="fas fa-map-marker-alt"></i> Police</span>
                                        <span>Station 1</span>
                                    </div>
                                </div>
                            </div>
                            <div class="unit-actions">
                                <button class="btn-action-small" onclick="unitLocation(this)" data-identifier="police-unit-1"><i class="fas fa-location-arrow"></i> Track</button>
                            </div>
                        </div>
                        <div class="unit-card available">
                            <div class="unit-info">
                                <div class="unit-details">
                                    <div class="unit-name">fire-truck-1</div>
                                    <div class="unit-meta">
                                        <span><i class="fas fa-map-marker-alt"></i> Fire</span>
                                        <span>Station 2</span>
                                    </div>
                                </div>
                            </div>
                            <div class="unit-actions">
                                <button class="btn-action-small" onclick="unitLocation(this)" data-identifier="fire-truck-1"><i class="fas fa-location-arrow"></i> Track</button>
                            </div>
                        </div>
                        <div class="unit-card available">
                            <div class="unit-info">
                                <div class="unit-details">
                                    <div class="unit-name">ambulance-1</div>
                                    <div class="unit-meta">
                                        <span><i class="fas fa-map-marker-alt"></i> Ambulance</span>
                                        <span>Station 3</span>
                                    </div>
                                </div>
                            </div>
                            <div class="unit-actions">
                                <button class="btn-action-small" onclick="unitLocation(this)" data-identifier="ambulance-1"><i class="fas fa-location-arrow"></i> Track</button>
                            </div>
                        </div>`;
                    return;
                }
                container.innerHTML = '';
                items.forEach(u => {
                    const meta = [];
                    if (u.unit_type) meta.push(u.unit_type.charAt(0).toUpperCase() + u.unit_type.slice(1));
                    const card = document.createElement('div');
                    card.className = 'unit-card available';
                    card.innerHTML = `
                        <div class="unit-info">
                            <div class="unit-details">
                                <div class="unit-name">${escapeHtml(u.identifier)}</div>
                                <div class="unit-meta">
                                    <span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(u.unit_type || '')}</span>
                                    ${meta.length ? '<span>' + meta.join(' | ') + '</span>' : ''}
                                </div>
                            </div>
                        </div>
                        <div class="unit-actions">
                            <button class="btn-action-small" onclick="unitLocation(this)" data-unit-id="${u.id}" data-identifier="${escapeAttr(u.identifier)}"><i class="fas fa-location-arrow"></i> Track</button>
                        </div>
                    `;
                    container.appendChild(card);
                });
            })
            .catch(() => {});
    } catch (e) {}

    function escapeHtml(s) {
        return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;','\'':'&#39;'})[c] || c);
    }
    function escapeAttr(s) {
        return String(s || '').replace(/['"]/g, '_');
    }
});
</script>
<script>
// --------- UI Handlers for Quick Actions and Cards ---------
function postJSON(url, payload) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload || {})
    }).then(r => r.json());
}

function emergencyBroadcast() {
    const msg = prompt('Broadcast message:');
    if (!msg) return;
    postJSON('api/activity_event.php', { action: 'broadcast', entity_type: 'system', details: msg })
        .then(() => showNotification('Emergency broadcast sent', 'success'))
        .catch(() => showNotification('Broadcast failed', 'error'));
}
function lockdownProtocol() {
    if (!confirm('Activate lockdown protocol?')) return;
    postJSON('api/activity_event.php', { action: 'lockdown', entity_type: 'system', details: 'Lockdown initiated by dispatch' })
        .then(() => showNotification('Lockdown protocol activated', 'warning'))
        .catch(() => showNotification('Lockdown failed', 'error'));
}

function massCasualty() {
    const info = prompt('Mass casualty details (location/resources):');
    if (!info) return;
    postJSON('api/activity_event.php', { action: 'mci', entity_type: 'incident', details: info })
        .then(() => showNotification('MCI protocol logged', 'info'))
        .catch(() => showNotification('MCI log failed', 'error'));
}

function resourceRequest() {
    const name = prompt('Resource name (e.g., Ventilator, Ambulance)');
    if (!name) return;
    const qty = prompt('Quantity', '1');
    const form = new FormData();
    form.append('requestor', 'Dispatch Center');
    form.append('resource_name', name);
    form.append('resource_type', 'other');
    form.append('quantity', qty || '1');
    form.append('priority', 'high');
    form.append('location', 'Dispatch HQ');
    form.append('notes', 'Auto-request via dispatch UI');
    form.append('urgency', 'urgent');
    fetch('api/request_resource.php', { method: 'POST', body: form })
        .then(r => r.json())
        .then(res => {
            if (res && (res.ok || res.success)) {
                showNotification('Resource request submitted', 'success');
            } else {
                showNotification('Resource request failed', 'error');
            }
        })
        .catch(() => showNotification('Network error', 'error'));
}

function viewDetails(btn) {
    const card = btn.closest('.call-card');
    // Try to extract incident id
    const idAttr = btn.getAttribute('data-incident-id');
    const incidentId = idAttr !== null ? toIncidentId(idAttr) : currentIncidentId;
    if (incidentId === null) { alert('Incident not found'); return; }
    fetch('api/incident_details.php?id=' + encodeURIComponent(incidentId))
        .then(r => r.json())
        .then(data => {
            const inc = data.incident || {};
            alert(
                'Incident Details\n\n' +
                'Type: ' + (inc.type || '-') + '\n' +
                'Title: ' + (inc.title || '-') + '\n' +
                'Location: ' + (inc.location_address || '-') + '\n' +
                'Priority: ' + (inc.priority || '-')
            );
        });
}

function contactCaller(btn) {
    const phone = btn.getAttribute('data-phone');
    if (!phone) { alert('No phone number'); return; }
    window.location.href = 'tel:' + encodeURIComponent(phone);
}

function unitLocation(btn) {
    const unitId = btn.getAttribute('data-unit-id');
    const unitName = btn.getAttribute('data-identifier');
    const qp = new URLSearchParams();
    if (unitId) qp.set('unit_id', unitId);
    if (unitName) qp.set('unit', unitName);
    window.location.href = 'gps.php?' + qp.toString();
}

function refreshAIRecommendations() {
    fetch('api/ai_recommendations.php')
        .then(r => r.json())
        .then(res => {
            const el = document.getElementById('ai-recommendations-content');
            if (res.ok && res.text) {
                el.innerHTML = '<div class="ai-recommendation-text">' +
                    res.text.replace(/\n/g, '<br>') + '</div>';
                showNotification('AI recommendations updated', 'success');
            } else {
                const msg = (res && res.error) ? String(res.error) : 'AI service unavailable';
                el.innerHTML = '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> ' + msg.replace(/\n/g, '<br>') + '</div>';
                showNotification(msg, 'error');
            }
        })
        .catch(() => showNotification('Network error', 'error'));
}

// Incident-aware deploy: prompt for incident ID and dispatch
function deployUnitToIncident(unitId) {
    if (!unitId) { alert('Unit ID missing'); return; }
    const incidentIdStr = prompt('Enter Incident ID to dispatch this unit (leave blank to just mark Assigned):');
    const incidentId = (incidentIdStr && incidentIdStr.trim() !== '') ? toIncidentId(incidentIdStr) : null;
    if (incidentId !== null) {
        fetch('api/dispatch_unit.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ incident_id: incidentId, unit_id: unitId })
        }).then(r => r.json())
        .then(res => {
            if (res && res.ok) {
                showNotification('Unit dispatched to incident', 'success');
                refreshActiveCalls();
                refreshAvailableUnits();
            } else {
                showNotification('Failed to dispatch unit', 'error');
            }
        }).catch(() => showNotification('Network error', 'error'));
    } else {
        // Fallback: just mark unit assigned without incident linkage
        unitStatus(unitId, 'assigned');
    }
}
</script>
<script>
// Lightweight URL param handling for context
document.addEventListener('DOMContentLoaded', () => {
    try {
        if (typeof initMap === 'function') initMap();
    } catch (e) {}
    try {
        const params = new URLSearchParams(window.location.search);
        const code = params.get('code');
        const period = params.get('period');
        if (code) {
            alert('Viewing incident context: ' + code);
        }
        if (period) {
            console.log('Dispatch period:', period);
        }
    } catch (e) {}
});
</script>
<script>
// Fallback: populate Active Emergency Calls from API when server-side list is empty
document.addEventListener('DOMContentLoaded', () => {
    try {
        const container = document.getElementById('active-calls-container');
        if (!container) return;
        const hasCards = container.querySelector('.call-card');
        if (hasCards) return; // server-side rendered
        fetch('api/incidents_list.php?status=active')
            .then(r => r.json())
            .then(res => {
                if (!res.ok) return;
                const items = res.items || [];
                if (!items.length) {
                    container.innerHTML = '<div class="call-card"><div class="call-info"><div class="call-details"><div class="call-title">No active emergency calls.</div></div></div></div>';
                    return;
                }
                container.innerHTML = '';
                items.forEach(it => {
                    const prio = (it.priority || 'medium').toLowerCase();
                    const prioClass = prio === 'high' ? 'high' : (prio === 'low' ? 'low' : 'medium');
                    const minsAgo = (() => { try { return Math.max(0, Math.floor((Date.now() - new Date(it.created_at).getTime()) / 60000)); } catch(e) { return 0; } })();
                    const timeAgo = minsAgo < 1 ? 'Just now' : (minsAgo + ' min ago');
                    const title = it.title || it.type || 'Incident';
                    const caller = it.caller_name || 'Unknown';
                    const phone = it.caller_phone || '';
                    const card = document.createElement('div');
                    card.className = 'call-card ' + prioClass;
                    card.innerHTML = `
                        <div class="call-info">
                            <div class="call-details">
                                <div class="call-title">${escapeHtml(title)}</div>
                                <div class="call-meta">
                                    <span><i class="fas fa-clock"></i> ${escapeHtml(timeAgo)}</span>
                                    <span><i class="fas fa-user"></i> ${escapeHtml(caller)}</span>
                                    <span class="status-indicator status-${prioClass}"></span> ${prio.charAt(0).toUpperCase() + prio.slice(1)} Priority
                                </div>
                            </div>
                        </div>
                        <div class="call-actions">
                            <button class="btn-dispatch" onclick="openDispatchModal(${it.id})">Dispatch Unit</button>
                            <button class="btn-action-small" onclick="viewDetails(this)" data-incident-id="${it.id}"><i class="fas fa-eye"></i> Details</button>
                            ${phone ? `<button class=\"btn-action-small\" onclick=\"contactCaller(this)\" data-phone=\"${escapeAttr(phone)}\"><i class=\"fas fa-phone\"></i> Call</button>` : ''}
                        </div>`;
                    container.appendChild(card);
                });
            })
            .catch(() => {});
    } catch (e) {}

    function escapeHtml(s) { return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;','\'':'&#39;'})[c] || c); }
    function escapeAttr(s) { return String(s || '').replace(/['"]/g, '_'); }
});
// Quick Action Handlers
function postJSON(url, payload) {
    return fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload || {})
    }).then(r => r.json());
}

function emergencyBroadcast() {
    const msg = prompt('Broadcast message to all units:');
    if (!msg) return;
    postJSON('api/activity_event.php', {
        action: 'broadcast',
        entity_type: 'system',
        details: msg
    }).then(res => {
        if (res.ok) {
            showNotification('Emergency broadcast sent', 'success');
        } else {
            alert('Failed to send broadcast');
        }
    }).catch(() => alert('Network error'));
}

function lockdownProtocol() {
    if (!confirm('Activate lockdown protocol?')) return;
    postJSON('api/activity_event.php', {
        action: 'lockdown',
        entity_type: 'system',
        details: 'City-wide lockdown activated from Dispatch Center'
    }).then(res => {
        if (res.ok) {
            showNotification('Lockdown protocol activated', 'warning');
        } else {
            alert('Failed to activate protocol');
        }
    }).catch(() => alert('Network error'));
}

function massCasualty() {
    const info = prompt('Describe mass casualty incident (location/details):');
    if (!info) return;
    postJSON('api/activity_event.php', {
        action: 'mci_alert',
        entity_type: 'incident',
        details: info
    }).then(res => {
        if (res.ok) {
            showNotification('Mass casualty alert recorded', 'error');
        } else {
            alert('Failed to record alert');
        }
    }).catch(() => alert('Network error'));
}

function resourceRequest() {
    const name = prompt('Resource name (e.g., Ambulance, Ventilator):');
    if (!name) return;
    const qty = parseInt(prompt('Quantity:'), 10) || 1;
    const fd = new FormData();
    fd.append('requestor', 'Dispatch Center');
    fd.append('resource_name', name);
    fd.append('resource_type', 'other');
    fd.append('quantity', String(qty));
    fd.append('priority', 'high');
    fd.append('location', 'Dispatch HQ');
    fd.append('notes', 'Requested via quick action');
    fd.append('urgency', 'urgent');
    fetch('api/request_resource.php', { method: 'POST', body: fd })
      .then(r => r.json())
      .then(res => {
        if (res.success) {
          showNotification('Resource request submitted', 'success');
        } else {
          // Fallback: log activity
          postJSON('api/activity_event.php', {
            action: 'resource_request',
            entity_type: 'resource',
            details: JSON.stringify({ name, qty })
          }).then(() => showNotification('Request logged', 'info'));
        }
      })
      .catch(() => alert('Network error'));
}

// Card action handlers
function viewDetails(btn) {
    const id = btn && btn.dataset ? btn.dataset.incidentId : null;
    if (!id) { alert('Incident ID missing'); return; }
    fetch('api/incident_details.php?id=' + encodeURIComponent(id))
      .then(r => r.json())
      .then(data => {
        if (!data.incident) { alert('Incident not found'); return; }
        const inc = data.incident;
        const lines = [
          'Type: ' + (inc.type || ''),
          'Title: ' + (inc.title || ''),
          'Priority: ' + (inc.priority || ''),
          'Location: ' + (inc.location_address || 'N/A')
        ];
        alert(lines.join('\n'));
      });
}

function contactCaller(btn) {
    const phone = btn && btn.dataset ? btn.dataset.phone : '';
    if (!phone) { alert('No phone number available'); return; }
    window.location.href = 'tel:' + phone;
}

function unitLocation(btn) {
    const unitId = btn && btn.dataset ? btn.dataset.unitId : '';
    const identifier = btn && btn.dataset ? btn.dataset.identifier : '';
    if (!unitId) { alert('Unit ID missing'); return; }
    window.location.href = 'gps.php?unit_id=' + encodeURIComponent(unitId) + (identifier ? ('&unit=' + encodeURIComponent(identifier)) : '');
}

function refreshAIRecommendations() {
    fetch('api/ai_recommendations.php')
      .then(r => r.json())
      .then(data => {
        const el = document.getElementById('ai-recommendations-content');
        if (data.ok && data.text) {
            el.innerHTML = '<div class="ai-recommendation-text">' + (data.text || '').replace(/\n/g, '<br>') + '</div>';
        } else {
            const msg = (data && data.error) ? String(data.error) : 'Unable to generate AI recommendations at this time.';
            el.innerHTML = '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> ' + msg.replace(/\n/g, '<br>') + '</div>';
        }
      })
      .catch(() => alert('Network error'));
}

// Resolve incident and refresh panels
function resolveIncident(btn) {
    const id = btn && btn.dataset ? toIncidentId(btn.dataset.incidentId) : null;
    if (id === null) { alert('Incident ID missing'); return; }
    const note = `Resolved via Dispatch UI at ${new Date().toLocaleString()}`;
    fetch('api/incident_resolve.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ incident_id: id, note })
    }).then(r => r.json())
    .then(res => {
        if (res && res.ok) {
            showNotification('Incident resolved. Units released to available.', 'success');
            refreshActiveCalls();
            refreshAvailableUnits();
        } else {
            showNotification('Failed to resolve incident', 'error');
        }
    }).catch(() => showNotification('Network error', 'error'));
}

function refreshActiveCalls() {
    const container = document.getElementById('active-calls-container');
    if (!container) return;
    fetch('api/incidents_list.php?status=active')
      .then(r => r.json())
      .then(res => {
        if (!res.ok) return;
        const items = res.items || [];
        if (!items.length) {
            container.innerHTML = '<div class="call-card"><div class="call-info"><div class="call-details"><div class="call-title">No active emergency calls.</div></div></div></div>';
            return;
        }
        container.innerHTML = '';
        items.forEach(it => {
            const prio = (it.priority || 'medium').toLowerCase();
            const prioClass = prio === 'high' ? 'high' : (prio === 'low' ? 'low' : 'medium');
            const minsAgo = (() => { try { return Math.max(0, Math.floor((Date.now() - new Date(it.created_at).getTime()) / 60000)); } catch(e) { return 0; } })();
            const timeAgo = minsAgo < 1 ? 'Just now' : (minsAgo + ' min ago');
            const title = it.title || it.type || 'Incident';
            const caller = it.caller_name || 'Unknown';
            const phone = it.caller_phone || '';
            const card = document.createElement('div');
            card.className = 'call-card ' + prioClass;
            card.innerHTML = `
                <div class=\"call-info\">
                    <div class=\"call-details\">
                        <div class=\"call-title\">${escapeHtml(title)}</div>
                        <div class=\"call-meta\">
                            <span><i class=\"fas fa-clock\"></i> ${escapeHtml(timeAgo)}</span>
                            <span><i class=\"fas fa-user\"></i> ${escapeHtml(caller)}</span>
                            <span class=\"status-indicator status-${prioClass}\"></span> ${prio.charAt(0).toUpperCase() + prio.slice(1)} Priority
                        </div>
                    </div>
                </div>
                <div class=\"call-actions\">
                    <button class=\"btn-dispatch\" onclick=\"openDispatchModal(${it.id})\">Dispatch Unit</button>
                    <button class=\"btn-action-small\" onclick=\"viewDetails(this)\" data-incident-id=\"${it.id}\"><i class=\"fas fa-eye\"></i> Details</button>
                    ${phone ? `<button class=\\\"btn-action-small\\\" onclick=\\\"contactCaller(this)\\\" data-phone=\\\"${escapeAttr(phone)}\\\"><i class=\\\"fas fa-phone\\\"></i> Call</button>` : ''}
                </div>`;
            container.appendChild(card);
        });
      }).catch(() => {});
}




function resetLastUnits() {
    fetch('api/reset_units.php', { method: 'POST' })
      .then(r => r.json())
      .then(res => {
        if (res && res.ok) {
            showNotification('Reset complete: last 2 units set to available', 'success');
            refreshAvailableUnits();
        } else {
            showNotification('Failed to reset units', 'error');
        }
      })
      .catch(() => showNotification('Network error', 'error'));
}
</script>
</body>
</html>
