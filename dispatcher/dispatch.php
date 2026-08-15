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

function dispatch_ai_inline_html(string $text): string
{
    $safe = htmlspecialchars(trim($text), ENT_QUOTES, 'UTF-8');
    return preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $safe) ?? $safe;
}

function dispatch_ai_text_html(string $text, string $wrapperClass): string
{
    $lines = preg_split('/\R/u', trim($text)) ?: [];
    $html = '<div class="' . htmlspecialchars($wrapperClass, ENT_QUOTES, 'UTF-8') . ' ai-formatted-text">';
    $listOpen = false;
    foreach ($lines as $line) {
        $line = trim((string)$line);
        if ($line === '') {
            if ($listOpen) {
                $html .= '</ul>';
                $listOpen = false;
            }
            continue;
        }
        if (preg_match('/^\*\*([^*:\n]+):\*\*\s*(.*)$/u', $line, $match)) {
            if ($listOpen) {
                $html .= '</ul>';
                $listOpen = false;
            }
            $html .= '<section class="ai-text-item"><h3>' . htmlspecialchars(trim($match[1]), ENT_QUOTES, 'UTF-8') . '</h3>';
            if (trim((string)($match[2] ?? '')) !== '') {
                $html .= '<p>' . dispatch_ai_inline_html((string)$match[2]) . '</p>';
            }
            $html .= '</section>';
            continue;
        }
        if (preg_match('/^[*-]\s+(.*)$/u', $line, $match)) {
            if (!$listOpen) {
                $html .= '<ul class="ai-text-list">';
                $listOpen = true;
            }
            $html .= '<li>' . dispatch_ai_inline_html((string)$match[1]) . '</li>';
            continue;
        }
        if ($listOpen) {
            $html .= '</ul>';
            $listOpen = false;
        }
        $html .= '<p>' . dispatch_ai_inline_html($line) . '</p>';
    }
    if ($listOpen) {
        $html .= '</ul>';
    }
    return $html . '</div>';
}

// Initialize default values
$activeIncidents = 0;
$availableUnits = 0;
$pendingCalls = 0;
$systemStatus = 'All systems operational';
$currentIncidentSummary = 'No active incident context';

// Fetch accurate data from database
try {
    require_once $rootDir . '/includes/db.php';
    require_once $rootDir . '/includes/vehicle_resource_units.php';
    $pdo = get_db_connection();
    
    if ($pdo) {
        $vehicleResourceTable = ers_vehicle_resource_units_table($pdo);
        if ($vehicleResourceTable !== null) {
            ers_sync_responder_vehicle_resources($pdo);
        }

        // Get active incidents (pending or dispatched)
        $activeIncidents = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status IN ('pending','new','dispatched')")->fetch()['c'];
        
        // Get available units
        $availableUnits = ers_count_available_vehicle_resource_units($pdo, $vehicleResourceTable ?? null);
        
        // Get pending calls that do not already have responders assigned.
        $pendingCallsSql = "SELECT COUNT(*) AS c FROM incidents i WHERE i.status IN ('pending','new')";
        if (function_exists('ers_vehicle_resource_table_exists') && ers_vehicle_resource_table_exists($pdo, 'dispatches')) {
            $pendingCallsSql .= " AND NOT EXISTS (
                SELECT 1
                FROM dispatches d_pending
                WHERE d_pending.incident_id = i.id
                  AND d_pending.status IN ('assigned','acknowledged','enroute','on_scene')
            )";
        }
        $pendingCalls = (int)$pdo->query($pendingCallsSql)->fetch()['c'];

        $topIncident = $pdo->query("SELECT reference_no, type, location_address, priority
                                    FROM incidents
                                    WHERE status IN ('pending','new','dispatched','active','in_progress')
                                    ORDER BY CASE LOWER(priority)
                                        WHEN 'critical' THEN 1
                                        WHEN 'high' THEN 2
                                        WHEN 'urgent' THEN 2
                                        WHEN 'medium' THEN 3
                                        WHEN 'moderate' THEN 3
                                        WHEN 'low' THEN 4
                                        ELSE 6
                                    END, created_at DESC
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
                        <span id="active-calls-badge" class="panel-badge panel-badge-danger"><?php echo $pendingCalls; ?> Pending</span>
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
                            echo dispatch_ai_text_html((string)$recommendations, 'ai-recommendation-text');
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
    <form class="modal-content" style="background:#fff; color:#0f172a; padding:2.5rem 2.5rem 2rem 2.5rem; border-radius:16px; max-width:600px; width:98%; position:relative; box-shadow:0 8px 32px rgba(0,0,0,0.18); display:flex; flex-direction:column; gap:1.2rem; min-height:350px;">
        <span class="close" onclick="closeDispatchModal()" style="position:absolute; top:10px; right:20px; font-size:2rem; cursor:pointer; color:#475569;">&times;</span>
        <h2 style="margin:0 0 1.2rem 0; text-align:left; font-size:2rem; font-weight:700; color:#0f172a;">Dispatch Unit</h2>
        <div style="display:flex; flex-direction:column; gap:1.1rem;">
            <div style="display:flex; flex-direction:column; gap:0.3rem;">
                <label style="font-weight:600; color:#334155;">Incident Details</label>
                <div id="modal-incident-details" style="background:#f8f9fa; border-radius:7px; padding:0.75rem 1rem; font-size:1rem; color:#334155; line-height:1.6;"></div>
            </div>
            <div style="display:flex; flex-direction:column; gap:0.3rem;">
                <label style="font-weight:600; color:#334155;">Available Units <span style="color:red">*</span></label>
                <div style="position:relative;">
                    <button id="unit-dropdown-toggle" type="button" style="width:100%; padding:0.7rem; border-radius:6px; border:1.5px solid #bbb; font-size:1.08rem; background:#f9f9f9; color:#111827; text-align:left; cursor:pointer; display:flex; justify-content:space-between; align-items:center;">
                        <span id="unit-dropdown-label">Select available units</span>
                        <span aria-hidden="true" style="font-size:0.9rem;">v</span>
                    </button>
                    <div id="unit-select" style="display:none; position:absolute; top:calc(100% + 4px); left:0; right:0; z-index:10000; max-height:210px; overflow-y:auto; padding:0.55rem; border-radius:6px; border:1.5px solid #bbb; font-size:1rem; background:#fff; color:#111827; flex-direction:column; gap:0.45rem; box-shadow:0 10px 24px rgba(15,23,42,0.18);"></div>
                </div>
            </div>
            <div style="display:flex; flex-direction:column; gap:0.3rem;">
                <label style="font-weight:600; color:#334155;">Unit Details</label>
                <div id="unit-details" style="background:#f1f3f4; border-radius:7px; padding:0.75rem 1rem; min-height:48px; font-size:0.98rem; color:#334155; line-height:1.6;"></div>
            </div>
        </div>
        <div style="display:flex; gap:1rem; justify-content:flex-end; margin-top:1.2rem;">
            <button type="button" onclick="closeDispatchModal()" style="background:#f1f1f1; color:#333; border:none; border-radius:6px; padding:0.7rem 1.5rem; font-size:1rem; font-weight:500; cursor:pointer;">Cancel</button>
            <button id="confirm-dispatch-btn" type="button" class="btn-dispatch" style="background:#007bff; color:#fff; border:none; border-radius:6px; padding:0.7rem 1.5rem; font-size:1rem; font-weight:600; cursor:pointer;">Confirm Dispatch</button>
        </div>
    </form>
</div>

<div id="dispatch-protocol-modal" class="protocol-modal" aria-hidden="true">
    <form id="dispatch-protocol-form" class="protocol-modal-content">
        <button type="button" class="protocol-modal-close" onclick="closeDispatchProtocolModal()" aria-label="Close">&times;</button>
        <div class="protocol-modal-header">
            <div class="protocol-modal-icon" id="protocol-modal-icon"><i class="fas fa-bullhorn"></i></div>
            <div>
                <div class="protocol-modal-eyebrow">Dispatch Protocol</div>
                <h2 id="protocol-modal-title">Emergency Broadcast</h2>
                <p id="protocol-modal-subtitle">Send an urgent advisory to response teams.</p>
            </div>
        </div>

        <div id="protocol-modal-fields" class="protocol-modal-fields"></div>

        <div class="protocol-preview-block">
            <label>Message Preview</label>
            <pre id="protocol-message-preview"></pre>
        </div>

        <div class="protocol-modal-actions">
            <button type="button" class="protocol-btn-secondary" onclick="closeDispatchProtocolModal()">Cancel</button>
            <button id="protocol-submit-btn" type="submit" class="protocol-btn-primary">
                <i class="fas fa-paper-plane"></i>
                <span>Send Protocol</span>
            </button>
        </div>
    </form>
</div>
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
// Modal logic (moved to end for guaranteed loading)
let currentIncidentId = null;
let currentIncidentLat = null;
let currentIncidentLng = null;
let currentAvailableUnitsById = {};
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
function getSelectedUnitOptions(container) {
    return Array.from(container ? container.querySelectorAll('input[name="unit_ids[]"]:checked') : []);
}
function getUnitVehicleName(unit) {
    return String((unit && (unit.vehicle_name || unit.resource_name || unit.identifier)) || '').trim();
}
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
function numberOrNull(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
}
function normalizeLatLngPair(latValue, lngValue) {
    let lat = numberOrNull(latValue);
    let lng = numberOrNull(lngValue);
    if (lat === null || lng === null) return null;

    const inPhilippines = (candidateLat, candidateLng) => {
        return candidateLat >= 4 && candidateLat <= 22 && candidateLng >= 116 && candidateLng <= 127;
    };

    if (!inPhilippines(lat, lng) && inPhilippines(lng, lat)) {
        [lat, lng] = [lng, lat];
    }

    if (!inPhilippines(lat, lng)) return null;
    return { lat, lng };
}
function isInvalidResponderCoordinate(lat, lng) {
    if (lat === null || lng === null) return true;
    if (Math.abs(lat) < 0.000001 && Math.abs(lng) < 0.000001) return true;
    const ignoredPoints = [
        [14.7338, 121.0368],
        [14.7295, 121.0342],
        [14.7351, 121.0380],
        [14.7320, 121.0351]
    ];
    return ignoredPoints.some(([ignoredLat, ignoredLng]) => {
        return Math.abs(lat - ignoredLat) < 0.000001 && Math.abs(lng - ignoredLng) < 0.000001;
    });
}
function unitResponderLatLng(unit) {
    const identifier = normalizeUnitIdentifier(unit && unit.identifier);
    const livePoint = identifier
        ? (liveUnitLocationsByIdentifier[identifier] || liveUnitLocationsByIdentifier[identifier.toUpperCase()] || null)
        : null;
    const candidates = [
        [livePoint && livePoint.lat, livePoint && livePoint.lng],
        [unit && unit.latest_latitude, unit && unit.latest_longitude],
        [unit && unit.latitude, unit && unit.longitude],
        [unit && unit.stored_latitude, unit && unit.stored_longitude]
    ];
    for (const pair of candidates) {
        const point = normalizeLatLngPair(pair[0], pair[1]);
        if (point && !isInvalidResponderCoordinate(point.lat, point.lng)) {
            return point;
        }
    }
    return null;
}
function selectedUnitDistanceKm(unit) {
    const incidentPoint = normalizeLatLngPair(currentIncidentLat, currentIncidentLng);
    const unitPoint = unitResponderLatLng(unit);
    if (!incidentPoint || !unitPoint) return null;
    return haversine(unitPoint.lat, unitPoint.lng, incidentPoint.lat, incidentPoint.lng);
}
function formatDistanceKm(distanceKm) {
    if (!Number.isFinite(distanceKm)) return '';
    return distanceKm < 1
        ? `${Math.round(distanceKm * 1000)} m`
        : `${distanceKm.toFixed(2)} km`;
}
const routeDistanceCache = {};
function selectedUnitDistanceKey(unit) {
    return String((unit && (unit.id || unit.identifier)) || '').replace(/[^A-Za-z0-9_-]/g, '_');
}
function routeDistanceKm(fromPoint, toPoint) {
    if (!fromPoint || !toPoint) return Promise.resolve(null);
    const cacheKey = [
        fromPoint.lat.toFixed(6), fromPoint.lng.toFixed(6),
        toPoint.lat.toFixed(6), toPoint.lng.toFixed(6)
    ].join(',');
    if (Object.prototype.hasOwnProperty.call(routeDistanceCache, cacheKey)) {
        return Promise.resolve(routeDistanceCache[cacheKey]);
    }
    const url = `https://router.project-osrm.org/route/v1/driving/${fromPoint.lng},${fromPoint.lat};${toPoint.lng},${toPoint.lat}?overview=false&alternatives=false&steps=false`;
    return fetch(url)
        .then(r => r.ok ? r.json() : null)
        .then(data => {
            const meters = data && data.routes && data.routes[0] && Number(data.routes[0].distance);
            const km = Number.isFinite(meters) ? meters / 1000 : null;
            routeDistanceCache[cacheKey] = km;
            return km;
        })
        .catch(() => null);
}
function readJsonResponse(response) {
    return response.text().then(text => {
        let data = null;
        if (text.trim() !== '') {
            try {
                data = JSON.parse(text);
            } catch (error) {
                throw new Error(text.replace(/\s+/g, ' ').trim() || 'Invalid server response');
            }
        }
        if (!response.ok) {
            throw new Error((data && data.error) || `Request failed (${response.status})`);
        }
        return data || {};
    });
}
function updateSelectedUnitRouteDistances(units) {
    const incidentPoint = normalizeLatLngPair(currentIncidentLat, currentIncidentLng);
    if (!incidentPoint) return;
    (units || []).forEach(unit => {
        const unitPoint = unitResponderLatLng(unit);
        const key = selectedUnitDistanceKey(unit);
        if (!unitPoint || !key) return;
        routeDistanceKm(unitPoint, incidentPoint).then(distanceKm => {
            if (distanceKm === null) return;
            document.querySelectorAll(`[data-distance-key="${key}"]`).forEach(el => {
                el.textContent = formatDistanceKm(distanceKm);
            });
        });
    });
}
function formatSelectedUnitDetails(unit) {
    const sampleProfile = getSampleUnitProfile(unit.unit_type);
    const vehicleName = getUnitVehicleName(unit) || 'Selected Vehicle';
    const unitCode = String(unit.identifier || '').trim();
    const unitPoint = unitResponderLatLng(unit);
    const distanceKm = selectedUnitDistanceKm(unit);
    const distanceKey = selectedUnitDistanceKey(unit);
    const incidentPoint = normalizeLatLngPair(currentIncidentLat, currentIncidentLng);
    const distanceUnavailableText = !incidentPoint
        ? 'Incident coordinates are not available'
        : 'Responder GPS is not available';
    const lines = [
        `<strong>${escapeHtml(vehicleName)}</strong>`,
        unitCode && unitCode !== vehicleName ? `<strong>Unit Code:</strong> ${escapeHtml(unitCode)}` : '',
        `<strong>Operator:</strong> ${escapeHtml(unit.driver_name || sampleProfile.driver)}`,
        `<strong>Plate #:</strong> ${escapeHtml(unit.plate_number || sampleProfile.plate)}`,
        `<strong>Type:</strong> ${escapeHtml(unit.unit_type || '')}`,
        `<strong>Status:</strong> ${escapeHtml(unit.status || '')}`,
        unitPoint ? `<strong>Responder GPS:</strong> ${unitPoint.lat.toFixed(6)}, ${unitPoint.lng.toFixed(6)}` : '<strong>Responder GPS:</strong> Pending',
        distanceKm !== null
            ? `<strong>Distance to Incident:</strong> <span data-distance-key="${escapeAttr(distanceKey)}">${escapeHtml(formatDistanceKm(distanceKm))}</span>`
            : `<strong>Distance to Incident:</strong> ${escapeHtml(distanceUnavailableText)}`
    ].filter(Boolean);
    return `<div style="padding:0.55rem 0; border-bottom:1px solid #dbe3ea;">${lines.join('<br>')}</div>`;
}
function isDispatchableModalUnit(unit) {
    if (!unit) return false;
    const vehicleStatus = normalizedUnitStatus(unit.status);
    const responderStatus = normalizedUnitStatus(unit.responder_unit_status);
    const presenceStatus = normalizedUnitStatus(unit.presence_status);
    const hasPresenceField = Object.prototype.hasOwnProperty.call(unit, 'presence_status');
    const hasResponder = String(unit.driver_name || unit.responder_user_id || '').trim() !== '';
    const responderReady = responderStatus === '' || responderStatus === 'available' || responderStatus === 'ready' || responderStatus === 'on_duty';
    return vehicleStatus === 'available'
        && hasResponder
        && responderReady
        && (!hasPresenceField || presenceStatus === 'online');
}
function renderSelectedUnitDetails(select) {
    const detailsEl = document.getElementById('unit-details');
    const btn = document.getElementById('confirm-dispatch-btn');
    const label = document.getElementById('unit-dropdown-label');
    const selectedOptions = getSelectedUnitOptions(select);

    if (!selectedOptions.length) {
        if (label) label.textContent = 'Select available units';
        detailsEl.innerHTML = 'Select one or more available vehicles to confirm dispatch.';
        if (btn) btn.disabled = true;
        return;
    }

    const selectedUnits = selectedOptions.map(option => currentAvailableUnitsById[option.value] || {
        id: option.value,
        identifier: option.getAttribute('data-identifier') || option.textContent,
        vehicle_name: option.getAttribute('data-vehicle-name') || '',
        unit_type: option.getAttribute('data-type') || '',
        status: 'available'
    });
    if (label) {
        label.textContent = selectedOptions.length === 1
            ? (getUnitVehicleName(selectedUnits[0]) || '1 unit selected')
            : `${selectedOptions.length} units selected`;
    }
    detailsEl.innerHTML = selectedUnits.map(formatSelectedUnitDetails).join('');
    updateSelectedUnitRouteDistances(selectedUnits);
    if (btn) btn.disabled = false;
}
function formatIncidentTypeLabel(value) {
    const labels = {
        medical: 'Medical Emergency',
        ambulance: 'Medical Emergency',
        fire: 'Fire',
        police: 'Police Emergency',
        traffic: 'Traffic Accident',
        rescue: 'Rescue',
        other: 'Other'
    };
    const parts = String(value || '')
        .split(',')
        .map((part) => part.trim().toLowerCase())
        .filter(Boolean);
    if (!parts.length) return '';
    if (parts.includes('medical') && parts.includes('police') && parts.includes('fire')) {
        return 'Emergency, Police, Fire';
    }
    return parts.map((part) => labels[part] || part.replace(/\b\w/g, (c) => c.toUpperCase())).join(', ');
}
function cleanAnonymousTipDispatchDescription(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (!/(anonymous tip converted to (?:an )?incident|tip id\s*:|date and time\s*:|evidence\s*:)/i.test(raw)) {
        return raw;
    }

    const compact = raw.replace(/\s+/g, ' ').trim();
    const descriptionMatch = compact.match(/\bDescription\s*:\s*([\s\S]*?)(?:\s+\bEvidence\s*:|$)/i);
    if (descriptionMatch && descriptionMatch[1]) {
        return descriptionMatch[1].trim();
    }

    return compact
        .replace(/anonymous tip converted to (?:an )?incident\.?/ig, '')
        .replace(/\bTip ID\s*:\s*.*?(?=\s+\b(?:Date and time|Location|Description|Evidence)\s*:|$)/ig, '')
        .replace(/\bDate and time\s*:\s*.*?(?=\s+\b(?:Location|Description|Evidence)\s*:|$)/ig, '')
        .replace(/\bLocation\s*:\s*.*?(?=\s+\b(?:Description|Evidence)\s*:|$)/ig, '')
        .replace(/\bEvidence\s*:\s*[\s\S]*$/ig, '')
        .replace(/\bDescription\s*:\s*/ig, '')
        .trim();
}
function parseLatLngFromText(text) {
    const raw = String(text || '');
    const match = raw.match(/(?:lat(?:itude)?\s*[:=]?\s*)?(-?\d{1,3}(?:\.\d+)?)\s*[, ]\s*(?:lon(?:gitude)?|lng)?\s*[:=]?\s*(-?\d{1,3}(?:\.\d+)?)/i);
    if (!match) return null;
    return normalizeLatLngPair(match[1], match[2]);
}
function renderIncidentDetails(inc) {
    const hasPoint = currentIncidentLat !== null && currentIncidentLng !== null;
    const callerName = inc.caller_name || 'N/A';
    const callerPhone = inc.caller_phone || 'N/A';
    const description = cleanAnonymousTipDispatchDescription(inc.description) || 'No description provided.';
    document.getElementById('modal-incident-details').innerHTML =
        `<strong>Type:</strong> ${escapeHtml(formatIncidentTypeLabel(inc.type) || inc.type || '')}<br>` +
        `<strong>Title:</strong> ${escapeHtml(inc.title || '')}<br>` +
        `<strong>Location:</strong> ${escapeHtml(inc.location_address || 'N/A')}<br>` +
        (hasPoint ? `<strong>Coordinates:</strong> ${escapeHtml(currentIncidentLat + ', ' + currentIncidentLng)}<br>` : '<strong>Coordinates:</strong> Not available<br>') +
        `<strong>Priority:</strong> ${escapeHtml(inc.priority || '')}<br>` +
        `<strong>Caller:</strong> ${escapeHtml(callerName)}<br>` +
        `<strong>Phone:</strong> ${escapeHtml(callerPhone)}<br>` +
        `<strong>Description:</strong> ${escapeHtml(description)}`;
}
function resolveIncidentPoint(inc) {
    const savedPoint = normalizeLatLngPair(inc && inc.latitude, inc && inc.longitude);
    if (savedPoint) return Promise.resolve(savedPoint);

    const textPoint = parseLatLngFromText((inc && inc.location_address) || '');
    if (textPoint) return Promise.resolve(textPoint);

    return Promise.resolve(null);
}
function openDispatchModal(incidentId) {
    currentIncidentId = toIncidentId(incidentId);
    document.getElementById('dispatch-modal').style.display = 'flex';
    const unitDropdownLabel = document.getElementById('unit-dropdown-label');
    const unitDropdownMenu = document.getElementById('unit-select');
    const confirmDispatchBtn = document.getElementById('confirm-dispatch-btn');
    if (unitDropdownLabel) unitDropdownLabel.textContent = 'Select available units';
    if (unitDropdownMenu) unitDropdownMenu.style.display = 'none';
    if (confirmDispatchBtn) {
        confirmDispatchBtn.disabled = false;
        confirmDispatchBtn.textContent = 'Confirm Dispatch';
    }
    if (currentIncidentId === null) {
        document.getElementById('modal-incident-details').innerHTML = '<span style="color:red">Incident not found.</span>';
        return;
    }
    // Fetch incident details and available units
    fetch('api/incident_details.php?id=' + encodeURIComponent(currentIncidentId))
        .then(r => r.json())
        .then(data => {
            const incidentReady = data.incident
                ? resolveIncidentPoint(data.incident)
                : Promise.resolve(null);
            return incidentReady.then(incidentPoint => {
            if (data.incident) {
                const inc = data.incident;
                currentIncidentLat = incidentPoint ? incidentPoint.lat : null;
                currentIncidentLng = incidentPoint ? incidentPoint.lng : null;
                renderIncidentDetails(inc);
            } else {
                document.getElementById('modal-incident-details').innerHTML = '<span style="color:red">Incident not found.</span>';
            }
            // Populate units
            const select = document.getElementById('unit-select');
            select.innerHTML = '';
            currentAvailableUnitsById = {};
            const dispatchableUnits = Array.isArray(data.units) ? data.units.filter(isDispatchableModalUnit) : [];
            if (dispatchableUnits.length) {
                dispatchableUnits.forEach(u => {
                    currentAvailableUnitsById[String(u.id)] = u;
                    const distKm = selectedUnitDistanceKm(u);
                    const incidentPoint = normalizeLatLngPair(currentIncidentLat, currentIncidentLng);
                    const dist = distKm !== null
                        ? `Distance: ${formatDistanceKm(distKm)}`
                        : (incidentPoint ? 'Responder GPS pending' : 'Incident coordinates missing');
                    const vehicleName = getUnitVehicleName(u);
                    const unitCode = String(u.identifier || '').trim();
                    const detailParts = [];
                    if (unitCode && unitCode !== vehicleName) detailParts.push(unitCode);
                    if (u.unit_type) detailParts.push(u.unit_type);
                    detailParts.push(dist);
                    const suffix = detailParts.join(', ');
                    select.innerHTML += `
                        <label style="display:flex; align-items:flex-start; gap:0.65rem; padding:0.55rem 0.65rem; border:1px solid #e2e8f0; border-radius:6px; background:#fff; cursor:pointer;">
                            <input type="checkbox" name="unit_ids[]" value="${escapeAttr(String(u.id))}" data-type="${escapeAttr(u.unit_type || '')}" data-identifier="${escapeAttr(u.identifier || '')}" data-vehicle-name="${escapeAttr(vehicleName)}" style="margin-top:0.2rem; width:1rem; height:1rem;">
                            <span style="display:flex; flex-direction:column; gap:0.12rem;">
                                <strong style="font-size:0.98rem; color:#0f172a;">${escapeHtml(vehicleName || unitCode || 'Unnamed vehicle')}</strong>
                                <small style="font-size:0.82rem; color:#64748b;">${escapeHtml(suffix)}</small>
                            </span>
                        </label>`;
                });
                renderSelectedUnitDetails(select);
            } else {
                select.innerHTML = '<div style="padding:0.55rem 0.65rem; color:#64748b;">No available units</div>';
                const label = document.getElementById('unit-dropdown-label');
                if (label) label.textContent = 'No available units';
                document.getElementById('unit-details').innerHTML = 'No online available responder vehicles are ready for dispatch.';
                document.getElementById('confirm-dispatch-btn').disabled = true;
            }
            });
        });
}
function closeDispatchModal() {
    document.getElementById('dispatch-modal').style.display = 'none';
    document.getElementById('modal-incident-details').innerHTML = '';
    document.getElementById('unit-select').innerHTML = '';
    document.getElementById('unit-select').style.display = 'none';
    const label = document.getElementById('unit-dropdown-label');
    if (label) label.textContent = 'Select available units';
    document.getElementById('unit-details').innerHTML = '';
    currentAvailableUnitsById = {};
    currentIncidentLat = null;
    currentIncidentLng = null;
    const btn = document.getElementById('confirm-dispatch-btn');
    if (btn) {
        btn.disabled = false;
        btn.textContent = 'Confirm Dispatch';
    }
}
document.addEventListener('DOMContentLoaded', function() {
    const unitDropdownToggle = document.getElementById('unit-dropdown-toggle');
    const unitDropdownMenu = document.getElementById('unit-select');
    if (unitDropdownToggle && unitDropdownMenu) {
        unitDropdownToggle.addEventListener('click', function(e) {
            e.stopPropagation();
            unitDropdownMenu.style.display = unitDropdownMenu.style.display === 'flex' ? 'none' : 'flex';
        });
        unitDropdownMenu.addEventListener('click', function(e) {
            e.stopPropagation();
        });
        document.addEventListener('click', function(e) {
            const modal = document.getElementById('dispatch-modal');
            if (modal && modal.style.display !== 'none') {
                unitDropdownMenu.style.display = 'none';
            }
        });
    }
    document.getElementById('unit-select').addEventListener('change', function(e) {
        if (!e.target || !e.target.matches('input[name="unit_ids[]"]')) {
            return;
        }
        renderSelectedUnitDetails(this);
    });
    function redirectToGpsContext(route) {
        const qp = new URLSearchParams();
        if (route.dispatchId) qp.set('dispatch_id', String(route.dispatchId));
        if (route.incidentId) qp.set('incident_id', String(route.incidentId));
        if (route.unitId) qp.set('unit_id', String(route.unitId));
        if (route.unitIdentifier) qp.set('unit', String(route.unitIdentifier));
        if (route.incidentLabel) qp.set('incident', String(route.incidentLabel));
        if (route.fromLat !== null && route.fromLat !== undefined && route.fromLng !== null && route.fromLng !== undefined) {
            qp.set('from_lat', String(route.fromLat));
            qp.set('from_lng', String(route.fromLng));
        }
        if (route.toLat !== null && route.toLat !== undefined && route.toLng !== null && route.toLng !== undefined) {
            qp.set('to_lat', String(route.toLat));
            qp.set('to_lng', String(route.toLng));
        }
        window.location.href = 'dispatcher/gps.php?' + qp.toString();
    }
    function getUnitRoutePoint(unit, selectedOption) {
        const candidates = [
            [unit.latest_latitude, unit.latest_longitude],
            [unit.latitude, unit.longitude],
            [unit.stored_latitude, unit.stored_longitude]
        ];
        for (const pair of candidates) {
            const point = normalizeLatLngPair(pair[0], pair[1]);
            if (point && !isInvalidResponderCoordinate(point.lat, point.lng)) {
                return point;
            }
        }
        return null;
    }
    document.getElementById('confirm-dispatch-btn').onclick = function() {
        const btn = document.getElementById('confirm-dispatch-btn');
        btn.disabled = true;
        btn.textContent = 'Dispatching...';
        const unitSelect = document.getElementById('unit-select');
        const selectedOptions = getSelectedUnitOptions(unitSelect);
        const unitIds = selectedOptions.map(option => option.value);
        if (!unitIds.length || currentIncidentId === null) {
            alert('Please select a unit.');
            btn.disabled = false;
            btn.textContent = 'Confirm Dispatch';
            return;
        }

        Promise.all([
            fetch('api/incident_details.php?id=' + encodeURIComponent(currentIncidentId)).then(readJsonResponse),
            Promise.all(unitIds.map(unitId => fetch('api/unit_details.php?id=' + encodeURIComponent(unitId)).then(readJsonResponse)))
        ]).then(([incRes, unitResponses]) => {
            const inc = incRes.incident || {};
            let toLat = null, toLng = null;
            const incidentPoint = normalizeLatLngPair(inc.latitude, inc.longitude);
            if (incidentPoint) {
                toLat = incidentPoint.lat;
                toLng = incidentPoint.lng;
            } else if (inc.location_address && inc.location_address.match(/\d+\.\d+,[ ]*\d+\.\d+/)) {
                const parts = inc.location_address.split(',').map(Number);
                const addressPoint = normalizeLatLngPair(parts[0], parts[1]);
                toLat = addressPoint ? addressPoint.lat : null;
                toLng = addressPoint ? addressPoint.lng : null;
            }

            const routeUnits = unitResponses.map((unitRes, index) => {
                const selectedOption = selectedOptions[index];
                const unit = unitRes.unit || currentAvailableUnitsById[unitIds[index]] || {};
                const point = getUnitRoutePoint(unit, selectedOption);
                return {
                    id: unitIds[index],
                    identifier: selectedOption ? selectedOption.getAttribute('data-identifier') : (unit.identifier || ''),
                    fromLat: point ? point.lat : null,
                    fromLng: point ? point.lng : null
                };
            });

            return fetch('api/dispatch_unit.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ incident_id: currentIncidentId, unit_ids: unitIds })
                }).then(readJsonResponse).then(data => {
                    if (data.ok) {
                        if (typeof addRouteToIncident === 'function' && toLat && toLng) {
                            routeUnits.forEach(routeUnit => {
                                if (routeUnit.fromLat && routeUnit.fromLng) {
                                    addRouteToIncident(routeUnit.fromLat, routeUnit.fromLng, toLat, toLng, { silent: true });
                                }
                            });
                        }
                        closeDispatchModal();
                        window.dispatchEvent(new CustomEvent('ers:anonymous-tips-updated', {
                            detail: {
                                incidentId: currentIncidentId,
                                changedAt: Date.now()
                            }
                        }));
                        const firstRouteUnit = routeUnits[0] || {};
                        const routeContext = {
                            dispatchId: data.dispatch_id || '',
                            incidentId: currentIncidentId,
                            unitId: firstRouteUnit.id || '',
                            unitIdentifier: firstRouteUnit.identifier || '',
                            incidentLabel: inc.reference_no || inc.title || '',
                            fromLat: firstRouteUnit.fromLat,
                            fromLng: firstRouteUnit.fromLng,
                            toLat: toLat,
                            toLng: toLng
                        };
                        removeIncidentFromActiveCalls(currentIncidentId);
                        Promise.allSettled([
                            refreshActiveCalls(),
                            refreshAvailableUnits()
                        ]).finally(() => {
                            redirectToGpsContext(routeContext);
                        });
                    } else {
                        alert('Failed to dispatch unit: ' + (data.error || 'Unknown error'));
                        btn.disabled = false;
                        btn.textContent = 'Confirm Dispatch';
                    }
                });
        }).catch(error => {
            alert(error && error.message ? error.message : 'Network error.');
            btn.disabled = false;
            btn.textContent = 'Confirm Dispatch';
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
let authoritativeOnlineUnitKeys = new Set();
let authoritativeOnlineUnitKeysReady = false;
let availableUnitsByIdentifier = {};
let liveUnitLocationsByIdentifier = {};
let unitIdentifierById = {};
let unitIdentifierByResponderId = {};
let incidentMarkers = {};
let QC_BOUNDS_GLOBAL;
let pendingDispatchTrackUnit = '';
let pendingDispatchTrackAttempts = 0;
const MAX_DISPATCH_TRACK_ATTEMPTS = 20;
const SAN_AGUSTIN_CENTER = [14.7320, 121.0351];
const SAN_AGUSTIN_BOUNDS = [
    [14.7225, 121.0290],
    [14.7415, 121.0415]
];
const SAN_AGUSTIN_GEOJSON = 'dispatcher/san_agustin.geojson';
const MARKER_SMOOTHING_MS = 900;
const MAX_ACCEPTED_ACCURACY_M = 180;
const MAX_REASONABLE_SPEED_KPH = 160;
const MAX_FIRST_JUMP_M = 800;

// ===============================
// LEAFLET MAP INITIALIZATION
// ===============================
function initMap() {
    QC_BOUNDS_GLOBAL = L.latLngBounds(SAN_AGUSTIN_BOUNDS);
    map = L.map("map", {
        center: SAN_AGUSTIN_CENTER,
        zoom: 15
    });
    window.map = map;
    L.tileLayer("https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png", {
        attribution: "© OpenStreetMap contributors"
    }).addTo(map);

    setTimeout(() => {
        try { map.invalidateSize(); } catch (e) {}
    }, 180);
    window.addEventListener('resize', () => {
        try { map.invalidateSize(); } catch (e) {}
    });

    // Load and display Barangay San Agustin border from GeoJSON.
    fetch(SAN_AGUSTIN_GEOJSON)
        .then(res => res.json())
        .then(data => {
            L.geoJSON(data, {
                style: {
                    color: 'red',
                    weight: 3,
                    opacity: 1,
                    fillColor: '#ef4444',
                    fillOpacity: 0.08
                }
            }).addTo(map);
        });

    // Load and render units and incidents using gps.php logic
    loadDispatchedUnits();
    loadAvailableUnits();
    loadIncidentMarkers();
    pruneOfflineUnitMarkers().finally(() => initFirebaseLiveTracking());
    addLegendControl();
    updateMapVisibility();
    startLivePolling();
    console.log("✅ Dispatch map loaded (Leaflet, real-time)");
}

// Use the same getIcon as gps.php for all marker types

// ===============================
// ICONS (sync with gps.php)
// ===============================
function getIcon(type) {
    const meta = getMarkerIconMeta(type);
    return L.divIcon({
        className: 'ers-unit-div-icon',
        html: `
            <div style="width:38px;height:38px;border-radius:50% 50% 50% 8px;transform:rotate(-45deg);background:${meta.color};border:2px solid #fff;display:flex;align-items:center;justify-content:center;">
                <i class="fas ${meta.icon}" style="transform:rotate(45deg);color:#fff;font-size:17px;line-height:1;"></i>
            </div>
        `,
        iconSize: [38, 38],
        iconAnchor: [19, 38],
        popupAnchor: [0, -34]
    });
}

function initFirebaseLiveTracking() {
    rtdb.ref('live_locations').on('value', (snapshot) => {
        const data = snapshot.val() || {};
        const seenKeys = new Set();

        Object.values(data).forEach((r) => {
            const lat = parseFloat(r.lat);
            const lng = parseFloat(r.lng);
            if (isNaN(lat) || isNaN(lng)) return;
            if (isInvalidResponderCoordinate(lat, lng)) return;
            const accuracyM = parseFiniteNumber(r.accuracy ?? r.accuracy_m);

            const rawKey = String(r.unitCode || r.responderId || '').trim();
            const key = resolveLiveUnitMarkerKey(rawKey);
            if (!key) return;
            if (rawKey && rawKey !== key) {
                removeUnitMarkerByIdentifier(rawKey);
            }
            applyLiveResponderLocationToUnit(key, lat, lng);
            const status = String(r.status || 'available').trim().toLowerCase();
            if (['offline', 'logged_out', 'inactive'].includes(status)) {
                removeUnitMarkerByIdentifier(key);
                return;
            }
            if (!canRenderLiveUnitMarker(key)) {
                removeUnitMarkerByIdentifier(key);
                return;
            }
            seenKeys.add(key);

            const label = `${key} — ${r.responderName || 'Responder'}`;
            const rawSpeedKph = parseFiniteNumber(r.speed_kph ?? r.speedKph);
            const speedMetersPerSecond = parseFiniteNumber(r.speed);
            const speedKph = rawSpeedKph !== null ? rawSpeedKph : (speedMetersPerSecond !== null ? speedMetersPerSecond * 3.6 : null);

            const isEnRoute = status === 'en_route';
            const dept = String(r.department || r.unitType || 'other').toLowerCase();
            const type = isEnRoute ? dept : `idle_${dept}`;
            const cachedUnitPoint = unitResponderLatLng(findCachedUnitByReference(key, ''));
            const markerLat = cachedUnitPoint ? cachedUnitPoint.lat : lat;
            const markerLng = cachedUnitPoint ? cachedUnitPoint.lng : lng;
            const livePopupHtml = `
                <strong>${label}</strong><br>
                Status: ${r.status || 'unknown'}<br>
                ${accuracyM !== null ? `Accuracy: ${accuracyM.toFixed(0)} m<br>` : ''}
                Speed: ${speedKph !== null ? speedKph.toFixed(1) + ' km/h' : 'pending'}<br>
                Coords: ${markerLat.toFixed(5)}, ${markerLng.toFixed(5)}<br>
                <em>${cachedUnitPoint ? 'Responder GPS' : 'Live GPS'}</em>
            `;

            if (markers[key]) {
                const accepted = moveUnitMarker(key, markerLat, markerLng, { speedKph, accuracyM, animate: true });
                if (!accepted) return;
                markers[key].marker.setIcon(getIcon(type));
                markers[key].marker.bindPopup(livePopupHtml);
                markers[key].isLive = true;
            } else {
                addUnitMarker(key, markerLat, markerLng, label, type, speedKph);
                markers[key].marker.bindPopup(livePopupHtml);
                markers[key].isLive = true;
            }
        });

        Object.keys(markers).forEach((key) => {
            if (markers[key].isLive && !seenKeys.has(key) && !hasLiveUnitLocation(key)) {
                map.removeLayer(markers[key].marker);
                delete markers[key];
            }
        });
    }, (error) => {
        console.error('Firebase live_locations read failed:', error);
        showNotification('Live GPS feed disconnected', 'error');
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
        idle: { icon: 'fa-circle-dot', color: '#94a3b8' },
        // Idle-but-colored-by-department variants — same accent color as the
        // active icon, but a plain dot instead of the department's vehicle
        // icon, so dispatchers can distinguish "idle fire" from "en-route fire"
        // at a glance without opening the popup.
        idle_fire: { icon: 'fa-circle-dot', color: '#dc2626' },
        idle_medical: { icon: 'fa-circle-dot', color: '#16a34a' },
        idle_police: { icon: 'fa-circle-dot', color: '#2563eb' },
        idle_crime: { icon: 'fa-circle-dot', color: '#2563eb' },
        idle_rescue: { icon: 'fa-circle-dot', color: '#ea580c' }
    };
    return icons[key] || icons.other;
}

function markerLegendSwatch(type) {
    const meta = getMarkerIconMeta(type);
    return `<span style="width:22px;height:22px;border-radius:50%;background:${meta.color};display:inline-flex;align-items:center;justify-content:center;margin-right:7px;box-shadow:0 1px 3px rgba(0,0,0,.2);"><i class="fas ${meta.icon}" style="color:#fff;font-size:11px;"></i></span>`;
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
        div.style.color = '#1f2937';
        div.style.lineHeight = '1.4';
        div.innerHTML = `
        <div style="font-weight:600;margin-bottom:6px">Legend</div>
        <div style="display:flex;align-items:center;margin-bottom:4px">${markerLegendSwatch('ambulance')}Ambulance (En Route)</div>
        <div style="display:flex;align-items:center;margin-bottom:4px">${markerLegendSwatch('police')}Police (En Route)</div>
        <div style="display:flex;align-items:center;margin-bottom:4px">${markerLegendSwatch('fire')}Fire (En Route)</div>
        <div style="display:flex;align-items:center;margin-bottom:4px">${markerLegendSwatch('rescue')}Rescue (En Route)</div>
        <div style="display:flex;align-items:center;margin-bottom:8px">${markerLegendSwatch('incident')}Incident</div>
        <div style="font-weight:600;margin-bottom:6px;border-top:1px solid #eee;padding-top:6px">Idle / Standby</div>
        <div style="display:flex;align-items:center;margin-bottom:4px">${markerLegendSwatch('idle_fire')}Fire</div>
        <div style="display:flex;align-items:center;margin-bottom:4px">${markerLegendSwatch('idle_medical')}Medical</div>
        <div style="display:flex;align-items:center">${markerLegendSwatch('idle_police')}Police</div>
        <div style="margin-top:6px;font-size:11px;color:#666">Heatmap shows recent hotspots</div>
    `;
        return div;
    };
    legend.addTo(map);
}

// ===============================
// MARKERS (sync with gps.php)
// ===============================
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
            lastAcceptedLatLng: L.latLng(lat, lng),
            lastLocationAt: Date.now(),
            ignoredGpsSpikes: 0,
            unitDbId: unitDbId !== undefined && unitDbId !== null ? String(unitDbId) : ''
        };
}

function addIncidentMarker(id, lat, lng, label) {
    const marker = L.marker([lat, lng], { icon: getIcon("incident") })
        .addTo(map)
        .bindPopup(`<strong>${label}</strong><br>🚨 Active Incident`);
    markers[id] = { marker, type: "incident" };
}

function removeUnitMarkerByIdentifier(identifier) {
    const id = String(identifier || '').trim();
    if (!id || !markers[id] || markers[id].type !== 'unit') return;
    try { map.removeLayer(markers[id].marker); } catch (e) {}
    delete markers[id];
}

function hasLiveUnitLocation(identifier) {
    const id = normalizeUnitIdentifier(identifier);
    if (!id) return false;
    const livePoint = liveUnitLocationsByIdentifier[id] || liveUnitLocationsByIdentifier[id.toUpperCase()] || null;
    return !!livePoint && !isInvalidResponderCoordinate(livePoint.lat, livePoint.lng);
}

function removeUnitMarkerIfNotLive(identifier) {
    const id = normalizeUnitIdentifier(identifier);
    if (!id) return;
    const entry = markers[id] || markers[Object.keys(markers).find((key) => String(key).toUpperCase() === id.toUpperCase())];
    if (entry && entry.isLive && hasLiveUnitLocation(id)) return;
    removeUnitMarkerByIdentifier(id);
}

function isResponderUnitOnline(unit) {
    const online = String(unit && unit.presence_status ? unit.presence_status : '').trim().toLowerCase() === 'online';
    return online && isRenderableResponderUnit(unit);
}

function hasCurrentResponderLocation(unit) {
    return String(unit && unit.location_current !== undefined && unit.location_current !== null ? unit.location_current : '').trim() === '1';
}

function normalizedUnitStatus(value) {
    return String(value === undefined || value === null ? '' : value).trim().toLowerCase();
}

function isRenderableResponderUnit(unit) {
    const vehicleStatus = normalizedUnitStatus(unit && unit.status);
    const responderStatus = normalizedUnitStatus(unit && unit.responder_unit_status);
    const vehicleAvailable = vehicleStatus === 'available';
    const responderAvailable = responderStatus === '' || responderStatus === 'available';
    const vehicleDispatched = ['assigned', 'enroute', 'en_route', 'on_scene'].includes(vehicleStatus);
    const responderActive = ['assigned', 'accepted', 'received', 'busy', 'in_use', 'enroute', 'en_route', 'on_scene'].includes(responderStatus);
    return (vehicleAvailable && responderAvailable) || (vehicleDispatched && responderActive);
}

function onlineResponderUnits(items) {
    return (items || []).filter(u => {
        if (isResponderUnitOnline(u)) return true;
        removeUnitMarkerIfNotLive(u && u.identifier);
        return false;
    });
}

function addAuthoritativeOnlineUnitKeys(unit, keys) {
    ['identifier', 'id', 'responder_user_id'].forEach(field => {
        const value = String(unit && unit[field] !== undefined && unit[field] !== null ? unit[field] : '').trim();
        if (value) {
            keys.add(value);
            keys.add(value.toUpperCase());
        }
    });
}

function pruneOfflineUnitMarkers() {
    return fetch('api/units_list.php', { cache: 'no-store' })
        .then(r => r.json())
        .then(res => {
            if (!res || !res.ok || !Array.isArray(res.items)) return;
            const onlineKeys = new Set();
            res.items.forEach(u => {
                const id = String(u && u.identifier ? u.identifier : '').trim();
                if (!id) return;
                if (isResponderUnitOnline(u)) {
                    addAuthoritativeOnlineUnitKeys(u, onlineKeys);
                }
            });
            authoritativeOnlineUnitKeys = onlineKeys;
            authoritativeOnlineUnitKeysReady = true;
            Object.entries(markers).forEach(([key, entry]) => {
                if (!entry || entry.type !== 'unit') return;
                if (!onlineKeys.has(String(key)) && !onlineKeys.has(String(key).toUpperCase())) {
                    if (entry.isLive && hasLiveUnitLocation(key)) return;
                    try { map.removeLayer(entry.marker); } catch (e) {}
                    delete markers[key];
                }
            });
        })
        .catch(() => {});
}

function canRenderLiveUnitMarker(identifier) {
    const id = String(identifier || '').trim();
    if (hasLiveUnitLocation(id)) return true;
    return !!id
        && authoritativeOnlineUnitKeysReady
        && (authoritativeOnlineUnitKeys.has(id) || authoritativeOnlineUnitKeys.has(id.toUpperCase()));
}

function parseFiniteNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
}

function distanceMeters(a, b) {
    if (!a || !b) return 0;
    if (map && typeof map.distance === 'function') {
        return map.distance(a, b);
    }
    return haversine(a.lat, a.lng, b.lat, b.lng) * 1000;
}

function isPlausibleUnitMove(entry, nextLatLng, options) {
    if (!entry || !entry.marker) return true;
    const now = Date.now();
    const previous = entry.lastAcceptedLatLng || entry.marker.getLatLng();
    const distance = distanceMeters(previous, nextLatLng);
    const accuracyM = parseFiniteNumber(options.accuracyM);

    if (accuracyM !== null && accuracyM > MAX_ACCEPTED_ACCURACY_M && distance > 20) {
        return false;
    }

    const elapsedSeconds = entry.lastLocationAt ? Math.max((now - entry.lastLocationAt) / 1000, 1) : null;
    if (elapsedSeconds) {
        const impliedSpeedKph = (distance / elapsedSeconds) * 3.6;
        if (distance > 80 && impliedSpeedKph > MAX_REASONABLE_SPEED_KPH) {
            return false;
        }
    } else if (distance > MAX_FIRST_JUMP_M) {
        return false;
    }

    return true;
}

function moveUnitMarker(id, lat, lng, options) {
    const entry = markers[id];
    if (!entry || !entry.marker || !Number.isFinite(lat) || !Number.isFinite(lng)) return false;

    const nextLatLng = L.latLng(lat, lng);
    if (!isPlausibleUnitMove(entry, nextLatLng, options || {})) {
        const sameRejectedArea = entry.lastRejectedLatLng && distanceMeters(entry.lastRejectedLatLng, nextLatLng) < 60;
        entry.ignoredGpsSpikes = sameRejectedArea ? ((entry.ignoredGpsSpikes || 0) + 1) : 1;
        entry.lastRejectedLatLng = nextLatLng;
        if (entry.ignoredGpsSpikes < 3) {
            return false;
        }
    }

    const previous = entry.marker.getLatLng();
    entry.ignoredGpsSpikes = 0;
    entry.lastRejectedLatLng = null;
    entry.lastAcceptedLatLng = nextLatLng;
    entry.lastLocationAt = Date.now();

    if (entry.moveAnimationFrame) {
        cancelAnimationFrame(entry.moveAnimationFrame);
        entry.moveAnimationFrame = null;
    }

    if (!(options && options.animate) || distanceMeters(previous, nextLatLng) < 3) {
        entry.marker.setLatLng(nextLatLng);
        return true;
    }

    const startTime = performance.now();
    const duration = MARKER_SMOOTHING_MS;
    const animateMove = (time) => {
        const progress = Math.min((time - startTime) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        const latNow = previous.lat + (nextLatLng.lat - previous.lat) * eased;
        const lngNow = previous.lng + (nextLatLng.lng - previous.lng) * eased;
        entry.marker.setLatLng([latNow, lngNow]);
        if (progress < 1) {
            entry.moveAnimationFrame = requestAnimationFrame(animateMove);
        } else {
            entry.moveAnimationFrame = null;
        }
    };
    entry.moveAnimationFrame = requestAnimationFrame(animateMove);
    return true;
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
    return fetch('api/units_list.php?status=dispatched')
        .then(r => r.json())
        .then(res => {
            if (!res.ok) return;
            const items = onlineResponderUnits(res.items || []);
            syncUnitMarkers(items);
        })
        .catch(() => {});
}

function syncUnitMarkers(items) {
    items.forEach(u => {
        const id = u.identifier;
        rememberUnitIdentity(u);
        if (!isResponderUnitOnline(u)) {
            removeUnitMarkerIfNotLive(id);
            return;
        }
        const type = u.unit_type || 'other';
        const point = unitResponderLatLng(u);
        const speed = (u.speed_kph !== undefined && u.speed_kph !== null) ? parseFloat(u.speed_kph) : null;
        if (point) {
            const lat = point.lat;
            const lng = point.lng;
            const label = `${id}`;
            if (markers[id]) {
                moveUnitMarker(id, lat, lng, { speedKph: speed, animate: true });
                markers[id].marker.setIcon(getIcon(type));
                if (!markers[id].isLive) {
                    const popupHtml = `
                        <strong>${label}</strong><br>
                        ${typeof speed === 'number' && isFinite(speed) ? `Speed: ${speed.toFixed(1)} km/h<br>` : ''}
                        Coords: ${lat.toFixed(5)}, ${lng.toFixed(5)}
                    `;
                    markers[id].marker.bindPopup(popupHtml);
                }
                markers[id].speedKph = speed;
                markers[id].unitType = String(type || '').toLowerCase();
                markers[id].unitDbId = u.id !== undefined && u.id !== null ? String(u.id) : markers[id].unitDbId;
            } else {
                addUnitMarker(id, lat, lng, label, type, speed, u.id);
            }
        } else {
            removeUnitMarkerIfNotLive(id);
        }
    });
}

function fetchAvailableUnitsData() {
    return fetch('api/units_list.php?status=available', { cache: 'no-store' })
        .then(r => r.json())
        .then(res => (res && res.ok && Array.isArray(res.items)) ? onlineResponderUnits(res.items) : []);
}

function syncAvailableUnitMarkers(items) {
    (items || []).forEach(u => {
        const id = u.identifier;
        rememberUnitIdentity(u);
        if (!isResponderUnitOnline(u)) {
            removeUnitMarkerIfNotLive(id);
            return;
        }
        const type = u.unit_type || 'other';
        const point = unitResponderLatLng(u);
        const speed = (u.speed_kph !== undefined && u.speed_kph !== null) ? parseFloat(u.speed_kph) : null;
        if (point) {
            const lat = point.lat;
            const lng = point.lng;
            if (markers[id]) {
                moveUnitMarker(id, lat, lng, { speedKph: speed, animate: true });
                markers[id].marker.setIcon(getIcon(type));
                if (!markers[id].isLive) {
                    markers[id].marker.bindPopup(`
                        <strong>${id}</strong><br>
                        ${typeof speed === 'number' && isFinite(speed) ? `Speed: ${speed.toFixed(1)} km/h<br>` : ''}
                        Coords: ${lat.toFixed(5)}, ${lng.toFixed(5)}
                    `);
                }
                markers[id].speedKph = speed;
                markers[id].unitType = String(type || '').toLowerCase();
                markers[id].unitDbId = u.id !== undefined && u.id !== null ? String(u.id) : markers[id].unitDbId;
            } else {
                addUnitMarker(id, lat, lng, `${id}`, type, speed, u.id);
            }
        } else {
            removeUnitMarkerIfNotLive(id);
        }
    });
}

function loadAvailableUnits() {
    return fetchAvailableUnitsData()
        .then(items => {
            syncAvailableUnitMarkers(items);
            return items;
        })
        .catch(() => []);
}

function loadIncidentMarkers() {
    return fetch('api/incidents_list.php?status=active')
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
    pruneOfflineUnitMarkers();
    let livePollInFlight = false;
    setInterval(() => {
        if (document.hidden || livePollInFlight) return;
        livePollInFlight = true;
        Promise.all([
            pruneOfflineUnitMarkers(),
            loadDispatchedUnits(),
            refreshActiveCalls(),
            refreshAvailableUnits(),
            loadIncidentMarkers()
        ]).finally(() => {
            livePollInFlight = false;
        });
    }, 10000); // 10 seconds
}

// ===============================
// MAP ACTIONS
// ===============================
function refreshMap() {
  Promise.allSettled([
    pruneOfflineUnitMarkers(),
    loadDispatchedUnits(),
    loadAvailableUnits(),
    loadIncidentMarkers()
  ]).finally(() => {
    updateMapVisibility();
    showNotification("Live map refreshed", "info");
  });
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
function renderAvailableUnits(items) {
    const container = document.getElementById('available-units-container');
    if (!container) return;
    const badge = document.getElementById('available-units-count');
    const readyCount = Array.isArray(items) ? items.length : 0;
    if (badge) badge.textContent = `${readyCount} Available`;

    if (!readyCount) {
        container.innerHTML = '<div class="unit-card"><div class="unit-info"><div class="unit-details"><div class="unit-name">No available units.</div><div class="unit-meta"><span>All vehicles are currently busy or unavailable.</span></div></div></div></div>';
        return;
    }

    container.innerHTML = '';
    items.forEach(u => {
        u = mergeLiveLocationIntoUnit(u);
        const meta = [];
        if (u.unit_type) meta.push(u.unit_type.charAt(0).toUpperCase() + u.unit_type.slice(1));
        const displayName = u.resource_name || u.identifier;
        const unitPoint = unitResponderLatLng(u);
        const gpsLocationText = unitPoint
            ? `Responder GPS: ${unitPoint.lat.toFixed(6)}, ${unitPoint.lng.toFixed(6)}`
            : '';
        const locationText = gpsLocationText || 'Responder GPS pending';
        const assignmentText = u.assignment || u.plate_number || u.driver_name || '';
        const card = document.createElement('div');
        card.className = 'unit-card available';
        card.setAttribute('data-unit-identifier', u.identifier || '');
        card.innerHTML = `
            <div class="unit-info">
                <div class="unit-details">
                    <div class="unit-name">${escapeHtml(displayName)}</div>
                    <div class="unit-meta">
                        <span><i class="fas fa-map-marker-alt"></i> ${escapeHtml(locationText)}</span>
                        ${meta.length ? '<span>' + meta.join(' | ') + '</span>' : ''}
                        ${assignmentText ? '<span>' + escapeHtml(assignmentText) + '</span>' : ''}
                    </div>
                </div>
            </div>
            <div class="unit-actions">
                <button class="btn-action-small" onclick="focusUnitOnMap('${escapeJs(u.identifier)}', '${escapeJs(u.id)}')" data-unit-id="${escapeAttr(u.id)}" data-identifier="${escapeAttr(u.identifier)}"><i class="fas fa-location-arrow"></i> Track</button>
            </div>
        `;
        container.appendChild(card);
    });
}

function refreshAvailableUnits() {
    const container = document.getElementById('available-units-container');
    return fetchAvailableUnitsData()
        .then(items => {
            availableUnitsByIdentifier = {};
            indexUnitsByIdentifier(items);
            syncAvailableUnitMarkers(items);
            if (container) {
                renderAvailableUnits(items);
            }
            return items;
        })
        .catch(() => {
            availableUnitsByIdentifier = {};
            if (container) {
                renderAvailableUnits([]);
            }
            return [];
        });
}

function escapeHtml(s) {
    return String(s || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','\"':'&quot;','\'':'&#39;'})[c] || c);
}

function aiInlineHtml(value) {
    return escapeHtml(value).replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
}

function renderAiText(value, wrapperClass) {
    const lines = String(value || '').trim().split(/\r?\n/);
    let html = `<div class="${escapeHtml(wrapperClass)} ai-formatted-text">`;
    let listOpen = false;
    const closeList = () => {
        if (listOpen) {
            html += '</ul>';
            listOpen = false;
        }
    };

    lines.forEach((rawLine) => {
        const line = rawLine.trim();
        if (!line) {
            closeList();
            return;
        }
        const headingMatch = line.match(/^\*\*([^*:\n]+):\*\*\s*(.*)$/);
        if (headingMatch) {
            closeList();
            html += `<section class="ai-text-item"><h3>${escapeHtml(headingMatch[1])}</h3>`;
            if (headingMatch[2]) html += `<p>${aiInlineHtml(headingMatch[2])}</p>`;
            html += '</section>';
            return;
        }
        const bulletMatch = line.match(/^[*-]\s+(.*)$/);
        if (bulletMatch) {
            if (!listOpen) {
                html += '<ul class="ai-text-list">';
                listOpen = true;
            }
            html += `<li>${aiInlineHtml(bulletMatch[1])}</li>`;
            return;
        }
        closeList();
        html += `<p>${aiInlineHtml(line)}</p>`;
    });
    closeList();
    return html + '</div>';
}

function escapeAttr(s) {
    return String(s || '').replace(/['"]/g, '_');
}

function escapeJs(s) {
    return String(s || '').replace(/\\/g, '\\\\').replace(/'/g, "\\'").replace(/\r?\n/g, ' ');
}

function normalizeUnitIdentifier(value) {
    const text = String(value || '').trim();
    if (!text) return '';
    return text.split(/\s+/)[0].trim();
}

function rememberUnitIdentity(unit) {
    const identifier = normalizeUnitIdentifier(unit && unit.identifier);
    const unitId = String(unit && unit.id !== undefined && unit.id !== null ? unit.id : '').trim();
    const responderId = String(unit && unit.responder_user_id !== undefined && unit.responder_user_id !== null ? unit.responder_user_id : '').trim();
    if (identifier) {
        mergeLiveLocationIntoUnit(unit);
        availableUnitsByIdentifier[identifier] = unit;
        availableUnitsByIdentifier[identifier.toUpperCase()] = unit;
    }
    if (identifier && unitId) {
        unitIdentifierById[unitId] = identifier;
    }
    if (identifier && responderId) {
        unitIdentifierByResponderId[responderId] = identifier;
    }
}

function resolveLiveUnitMarkerKey(rawKey) {
    const key = normalizeUnitIdentifier(rawKey);
    return unitIdentifierByResponderId[key] || unitIdentifierById[key] || key;
}

function mergeLiveLocationIntoUnit(unit) {
    const identifier = normalizeUnitIdentifier(unit && unit.identifier);
    if (!identifier || !unit) return unit;
    const livePoint = liveUnitLocationsByIdentifier[identifier] || liveUnitLocationsByIdentifier[identifier.toUpperCase()] || null;
    if (!livePoint || isInvalidResponderCoordinate(livePoint.lat, livePoint.lng)) return unit;
    unit.latest_latitude = livePoint.lat;
    unit.latest_longitude = livePoint.lng;
    unit.latitude = livePoint.lat;
    unit.longitude = livePoint.lng;
    unit.location_current = '1';
    return unit;
}

function updateUnitCardGpsText(unitIdentifier, lat, lng) {
    const normalized = normalizeUnitIdentifier(unitIdentifier);
    if (!normalized || isInvalidResponderCoordinate(lat, lng)) return;
    const text = `Responder GPS: ${lat.toFixed(6)}, ${lng.toFixed(6)}`;
    document.querySelectorAll('#available-units-container .unit-card').forEach(card => {
        const cardIdentifier = normalizeUnitIdentifier(card.getAttribute('data-unit-identifier') || '');
        if (cardIdentifier.toUpperCase() !== normalized.toUpperCase()) return;
        const locationSpan = card.querySelector('.unit-meta span:first-child');
        if (locationSpan) {
            locationSpan.innerHTML = `<i class="fas fa-map-marker-alt"></i> ${escapeHtml(text)}`;
        }
    });
}

function applyLiveResponderLocationToUnit(unitIdentifier, lat, lng) {
    const normalized = normalizeUnitIdentifier(unitIdentifier);
    if (!normalized || isInvalidResponderCoordinate(lat, lng)) return;
    const livePoint = { lat, lng, seenAt: Date.now() };
    liveUnitLocationsByIdentifier[normalized] = livePoint;
    liveUnitLocationsByIdentifier[normalized.toUpperCase()] = livePoint;
    const unit = findCachedUnitByReference(normalized, '') || { identifier: normalized };
    mergeLiveLocationIntoUnit(unit);
    availableUnitsByIdentifier[normalized] = unit;
    availableUnitsByIdentifier[normalized.toUpperCase()] = unit;
    updateUnitCardGpsText(normalized, lat, lng);
}

function indexUnitsByIdentifier(items) {
    (items || []).forEach(u => {
        if (!u || !u.identifier) return;
        const id = normalizeUnitIdentifier(u.identifier);
        if (!id) return;
        availableUnitsByIdentifier[id] = u;
        availableUnitsByIdentifier[id.toUpperCase()] = u;
        rememberUnitIdentity(u);
    });
}

function findUnitMarkerByReference(unitIdentifier, unitId) {
    const normalized = normalizeUnitIdentifier(unitIdentifier);
    const explicitUnitId = String(unitId || '').trim();

    if (normalized && markers[normalized]) {
        return { key: normalized, markerObj: markers[normalized] };
    }
    if (normalized) {
        const upperIdentifier = normalized.toUpperCase();
        const matchedKey = Object.keys(markers).find((key) => String(key).toUpperCase() === upperIdentifier);
        if (matchedKey) {
            return { key: matchedKey, markerObj: markers[matchedKey] };
        }
    }
    if (explicitUnitId) {
        const mappedIdentifier = unitIdentifierById[explicitUnitId];
        if (mappedIdentifier && markers[mappedIdentifier]) {
            return { key: mappedIdentifier, markerObj: markers[mappedIdentifier] };
        }
        const markerByDbId = Object.keys(markers).find((key) => String(markers[key]?.unitDbId || '') === explicitUnitId);
        if (markerByDbId) {
            return { key: markerByDbId, markerObj: markers[markerByDbId] };
        }
    }

    return { key: normalized, markerObj: null };
}

function findCachedUnitByReference(unitIdentifier, unitId) {
    const normalized = normalizeUnitIdentifier(unitIdentifier);
    const explicitUnitId = String(unitId || '').trim();
    if (normalized) {
        return availableUnitsByIdentifier[normalized]
            || availableUnitsByIdentifier[normalized.toUpperCase()]
            || null;
    }
    if (explicitUnitId && unitIdentifierById[explicitUnitId]) {
        const mappedIdentifier = unitIdentifierById[explicitUnitId];
        return availableUnitsByIdentifier[mappedIdentifier]
            || availableUnitsByIdentifier[mappedIdentifier.toUpperCase()]
            || null;
    }
    if (explicitUnitId) {
        return Object.values(availableUnitsByIdentifier).find((unit) => {
            return String(unit && unit.id !== undefined && unit.id !== null ? unit.id : '') === explicitUnitId;
        }) || null;
    }
    return null;
}

function ensureUnitMarkerFromApiUnit(unit) {
    if (!unit || !unit.identifier || !isResponderUnitOnline(unit)) return null;
    const id = String(unit.identifier);
    const point = unitResponderLatLng(unit);
    if (!point) return null;
    const lat = point.lat;
    const lng = point.lng;

    const type = unit.unit_type || 'other';
    const speed = (unit.speed_kph !== undefined && unit.speed_kph !== null) ? parseFloat(unit.speed_kph) : null;
    if (markers[id] && markers[id].marker) {
        moveUnitMarker(id, lat, lng, { speedKph: speed, animate: true });
        markers[id].marker.setIcon(getIcon(type));
        markers[id].unitDbId = unit.id !== undefined && unit.id !== null ? String(unit.id) : markers[id].unitDbId;
    } else {
        addUnitMarker(id, lat, lng, id, type, speed, unit.id);
    }
    rememberUnitIdentity(unit);
    return markers[id] || null;
}

function focusMarkerObject(markerObj) {
    if (!markerObj || !markerObj.marker || !map) return false;
    map.setView(markerObj.marker.getLatLng(), 17, { animate: true });
    markerObj.marker.openPopup();
    return true;
}

document.addEventListener('DOMContentLoaded', () => {
    refreshAvailableUnits();
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

function formatTimeAgo(createdAt) {
    const timestamp = new Date(String(createdAt || '').replace(' ', 'T')).getTime();
    if (!Number.isFinite(timestamp)) return '';
    const minsAgo = Math.max(0, Math.floor((Date.now() - timestamp) / 60000));
    if (minsAgo < 1) return 'Just now';
    if (minsAgo < 60) return `${minsAgo} min ago`;

    const hours = Math.floor(minsAgo / 60);
    const minutes = minsAgo % 60;
    if (hours < 24) {
        return minutes > 0 ? `${hours} hr ${minutes} min ago` : `${hours} hr ago`;
    }

    const days = Math.floor(hours / 24);
    const remHours = hours % 24;
    return remHours > 0 ? `${days} day ${remHours} hr ago` : `${days} day ago`;
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


// Focus the map on the selected unit marker
function focusUnitOnMap(unitIdentifier, unitId) {
    const normalizedIdentifier = normalizeUnitIdentifier(unitIdentifier);
    const explicitUnitId = String(unitId || '').trim();
    if (!normalizedIdentifier && !explicitUnitId) return;

    let found = findUnitMarkerByReference(normalizedIdentifier, explicitUnitId);
    let markerObj = found.markerObj;
    if (!markerObj) {
        const unit = findCachedUnitByReference(normalizedIdentifier, explicitUnitId);
        markerObj = ensureUnitMarkerFromApiUnit(unit);
    }
    if (focusMarkerObject(markerObj)) {
        return;
    }

    Promise.allSettled([
        fetchAvailableUnitsData(),
        fetch('api/units_list.php?status=dispatched', { cache: 'no-store' })
            .then(r => r.json())
            .then(res => (res && res.ok && Array.isArray(res.items)) ? onlineResponderUnits(res.items) : [])
    ])
        .then(results => {
            const refreshedItems = [];
            results.forEach(result => {
                if (result.status === 'fulfilled' && Array.isArray(result.value)) {
                    refreshedItems.push(...result.value);
                }
            });
            availableUnitsByIdentifier = {};
            indexUnitsByIdentifier(refreshedItems);
            syncAvailableUnitMarkers(refreshedItems);
            syncUnitMarkers(refreshedItems);
            const unit = findCachedUnitByReference(normalizedIdentifier, explicitUnitId);
            const refreshedMarkerObj = ensureUnitMarkerFromApiUnit(unit);
            if (!focusMarkerObject(refreshedMarkerObj)) {
                alert('Unit location not available on map.');
            }
        })
        .catch(() => alert('Unit location not available on map.'));
}

function tryFocusPendingDispatchUnit() {
    if (!pendingDispatchTrackUnit) return;
    if (!map) {
        window.setTimeout(tryFocusPendingDispatchUnit, 300);
        return;
    }

    const found = findUnitMarkerByReference(pendingDispatchTrackUnit, '');
    const markerObj = found.markerObj;

    if (markerObj && markerObj.marker) {
        const label = found.key || pendingDispatchTrackUnit;
        pendingDispatchTrackUnit = '';
        pendingDispatchTrackAttempts = 0;
        map.setView(markerObj.marker.getLatLng(), 17, { animate: true });
        markerObj.marker.openPopup();
        showNotification('Tracking ' + label, 'success');
        return;
    }

    pendingDispatchTrackAttempts += 1;
    if (pendingDispatchTrackAttempts >= MAX_DISPATCH_TRACK_ATTEMPTS) {
        showNotification('Vehicle location is not available on the dispatch map.', 'info');
        pendingDispatchTrackUnit = '';
        pendingDispatchTrackAttempts = 0;
        return;
    }

    window.setTimeout(tryFocusPendingDispatchUnit, 400);
}

function requestDispatchUnitTracking(unitIdentifier) {
    pendingDispatchTrackUnit = normalizeUnitIdentifier(unitIdentifier);
    pendingDispatchTrackAttempts = 0;
    if (!pendingDispatchTrackUnit) return;
    Promise.allSettled([
        pruneOfflineUnitMarkers(),
        loadDispatchedUnits(),
        refreshAvailableUnits()
    ]).finally(() => {
        tryFocusPendingDispatchUnit();
    });
}

function refreshAIRecommendations() {
    fetch('api/ai_recommendations.php')
        .then(r => r.json())
        .then(res => {
            const el = document.getElementById('ai-recommendations-content');
            if (res.ok && res.text) {
                el.innerHTML = renderAiText(res.text, 'ai-recommendation-text');
                showNotification('AI recommendations updated', 'success');
            } else {
                const msg = (res && res.error) ? String(res.error) : 'AI service unavailable';
                el.innerHTML = '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(msg).replace(/\n/g, '<br>') + '</div>';
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
                window.dispatchEvent(new CustomEvent('ers:anonymous-tips-updated', {
                    detail: {
                        incidentId,
                        changedAt: Date.now()
                    }
                }));
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
        const incidentId = toIncidentId(params.get('incident_id'));
        const trackUnit = params.get('track_unit') || params.get('unit') || '';
        const fromCall = params.get('from_call') === '1';
        const openDispatch = fromCall || params.get('open_dispatch') === '1';
        const period = params.get('period');
        if (openDispatch) {
            if (window.ersCallSession && typeof window.ersCallSession.update === 'function') {
                window.ersCallSession.update({
                    incidentId: incidentId,
                    incidentReferenceNo: code || ''
                });
            }
            showNotification(code ? `Incident ${code} logged. Select a dispatch unit.` : 'Incident logged. Select a dispatch unit.', 'info');
            refreshActiveCalls().finally(() => {
                if (incidentId !== null) {
                    window.setTimeout(() => openDispatchModal(incidentId), 220);
                }
            });
        } else if (code) {
            showNotification('Viewing incident context: ' + code, 'info');
        }
        if (trackUnit) {
            window.setTimeout(() => requestDispatchUnitTracking(trackUnit), 300);
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
        fetch('api/incidents_list.php?status=pending', { cache: 'no-store' })
            .then(r => r.json())
            .then(res => {
                if (!res.ok) return;
                const items = res.items || [];
                const badge = document.getElementById('active-calls-badge');
                if (badge) badge.textContent = `${items.length} Pending`;
                if (!items.length) {
                    container.innerHTML = '<div class="call-card"><div class="call-info"><div class="call-details"><div class="call-title">No pending emergency calls.</div></div></div></div>';
                    return;
                }
                container.innerHTML = '';
                items.forEach(it => {
                    const prio = (it.priority || 'medium').toLowerCase();
                    const prioClass = prio === 'critical'
                        ? 'critical'
                        : (prio === 'high' || prio === 'urgent'
                            ? 'high'
                            : (prio === 'low' ? 'low' : 'medium'));
                    const timeAgo = formatTimeAgo(it.created_at) || 'Just now';
                    const title = it.title || it.type || 'Incident';
                    const caller = it.caller_name || 'Unknown';
                    const phone = it.caller_phone || '';
                    const card = document.createElement('div');
                    card.className = 'call-card ' + prioClass;
                    card.setAttribute('data-incident-id', String(it.id));
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
                            ${phone ? `<button class="btn-action-small call-phone-btn" onclick="contactCaller(this)" data-phone="${escapeAttr(phone)}"><i class="fas fa-phone"></i> Call</button>` : ''}
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
        headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
        credentials: 'same-origin',
        body: JSON.stringify(payload || {})
    }).then(async r => {
        const data = await r.json().catch(() => ({}));
        if (!r.ok) {
            throw new Error(data.error || data.message || 'Request failed');
        }
        return data;
    });
}

const DISPATCH_PROTOCOLS = {
    broadcast: {
        action: 'emergency_broadcast',
        title: 'Emergency Broadcast',
        subtitle: 'Send an urgent advisory to responders, selected departments, or public channels.',
        icon: 'fa-bullhorn',
        iconText: '🚨',
        label: 'EMERGENCY BROADCAST',
        tone: 'success',
        submitLabel: 'Send Broadcast',
        fields: [
            { name: 'headline', label: 'Alert Title', type: 'text', required: true, value: 'Major Fire Alert' },
            { name: 'location', label: 'Location', type: 'text', required: true, value: 'Commonwealth Avenue, Quezon City' },
            { name: 'status', label: 'Status', type: 'select', required: true, value: 'Active', options: ['Active', 'Monitoring', 'Contained', 'Resolved'] },
            { name: 'audience', label: 'Audience', type: 'select', required: true, value: 'All Responders', options: ['All Responders', 'Fire Department', 'Police Department', 'EMS / Medical', 'Public Advisory', 'All Departments'] },
            { name: 'advisory', label: 'Public Advisory', type: 'textarea', required: true, full: true, value: 'Avoid the area.' },
            { name: 'response', label: 'Responder Instruction', type: 'textarea', required: true, full: true, value: 'Fire Department and EMS respond immediately.' }
        ],
        preview(values) {
            return [
                `${this.iconText} ${this.label}`,
                '',
                values.headline,
                '',
                `Location: ${values.location}`,
                '',
                `Status: ${values.status}`,
                '',
                values.advisory,
                '',
                values.response
            ].filter(line => line !== undefined && line !== null).join('\n');
        }
    },
    lockdown: {
        action: 'lockdown_protocol',
        title: 'Lockdown Protocol',
        subtitle: 'Restrict movement and alert response teams when an area becomes unsafe.',
        icon: 'fa-lock',
        iconText: '🔴',
        label: 'LOCKDOWN',
        tone: 'warning',
        submitLabel: 'Activate Lockdown',
        fields: [
            { name: 'area', label: 'Area', type: 'text', required: true, value: 'Barangay Holy Spirit' },
            { name: 'reason', label: 'Reason', type: 'select', required: true, value: 'Armed suspect', options: ['Active shooter', 'Armed suspect', 'Bomb threat', 'Terrorist attack', 'Jail escape', 'Hostage situation'] },
            { name: 'audience', label: 'Notify', type: 'select', required: true, value: 'All Responders', options: ['All Responders', 'Police Department', 'Fire Department', 'EMS / Medical', 'Public Advisory', 'All Departments'] },
            { name: 'status', label: 'Status', type: 'select', required: true, value: 'Active', options: ['Active', 'Monitoring', 'Lifted'] },
            { name: 'instructions', label: 'Lockdown Instructions', type: 'textarea', required: true, full: true, value: 'Secure the area. Restrict movement until cleared by command.' }
        ],
        preview(values) {
            return [
                `${this.iconText} ${this.label}`,
                '',
                'Area:',
                '',
                values.area,
                '',
                'Reason:',
                '',
                values.reason,
                '',
                'Status:',
                '',
                values.status,
                '',
                values.instructions
            ].filter(line => line !== undefined && line !== null).join('\n');
        }
    },
    mci: {
        action: 'mass_casualty_incident',
        title: 'Mass Casualty Incident',
        subtitle: 'Declare a major incident when victims exceed immediately available resources.',
        icon: 'fa-notes-medical',
        iconText: '🚨',
        label: 'MASS CASUALTY INCIDENT',
        tone: 'error',
        submitLabel: 'Declare MCI',
        fields: [
            { name: 'location', label: 'Location', type: 'text', required: true, value: 'EDSA' },
            { name: 'victims', label: 'Victims', type: 'number', required: true, value: '42', min: '1' },
            { name: 'priority', label: 'Priority', type: 'select', required: true, value: 'Critical', options: ['Critical', 'High', 'Medium'] },
            { name: 'audience', label: 'Notify', type: 'select', required: true, value: 'All Departments', options: ['All Departments', 'All Responders', 'EMS / Medical', 'Fire Department', 'Police Department', 'Public Advisory'] },
            { name: 'resources', label: 'Resources Needed', type: 'textarea', required: true, full: true, value: '12 Ambulances\nFire Units\nPolice\nHospitals' },
            { name: 'notes', label: 'Command Notes', type: 'textarea', required: false, full: true, value: 'Prepare triage and hospital coordination.' }
        ],
        preview(values) {
            const resources = String(values.resources || '')
                .split(/\r?\n/)
                .map(line => line.trim())
                .filter(Boolean)
                .map(line => `• ${line}`)
                .join('\n');
            return [
                `${this.iconText} ${this.label}`,
                '',
                'Location:',
                '',
                values.location,
                '',
                'Victims:',
                '',
                values.victims,
                '',
                'Priority:',
                '',
                values.priority,
                '',
                'Resources Needed:',
                '',
                resources,
                values.notes ? '\n' + values.notes : ''
            ].filter(line => line !== undefined && line !== null).join('\n');
        }
    }
};

function emergencyBroadcast() {
    openDispatchProtocolModal('broadcast');
}

function lockdownProtocol() {
    openDispatchProtocolModal('lockdown');
}

function massCasualty() {
    openDispatchProtocolModal('mci');
}

function openDispatchProtocolModal(kind) {
    const config = DISPATCH_PROTOCOLS[kind];
    const modal = document.getElementById('dispatch-protocol-modal');
    const form = document.getElementById('dispatch-protocol-form');
    const fields = document.getElementById('protocol-modal-fields');
    const title = document.getElementById('protocol-modal-title');
    const subtitle = document.getElementById('protocol-modal-subtitle');
    const icon = document.getElementById('protocol-modal-icon');
    const submitText = document.querySelector('#protocol-submit-btn span');
    if (!config || !modal || !form || !fields) return;

    form.dataset.kind = kind;
    title.textContent = config.title;
    subtitle.textContent = config.subtitle;
    icon.innerHTML = `<i class="fas ${config.icon}"></i>`;
    if (submitText) submitText.textContent = config.submitLabel;
    fields.innerHTML = config.fields.map(renderProtocolField).join('');
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    updateDispatchProtocolPreview();

    const firstField = fields.querySelector('input, select, textarea');
    if (firstField) {
        window.setTimeout(() => firstField.focus(), 80);
    }
}

function closeDispatchProtocolModal() {
    const modal = document.getElementById('dispatch-protocol-modal');
    const form = document.getElementById('dispatch-protocol-form');
    if (modal) {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }
    if (form) {
        form.removeAttribute('data-kind');
    }
}

function renderProtocolField(field) {
    const requiredMark = field.required ? ' <span style="color:#dc2626">*</span>' : '';
    const fullClass = field.full ? ' protocol-field-full' : '';
    const attrs = [
        `name="${escapeHtml(field.name)}"`,
        `id="protocol-field-${escapeHtml(field.name)}"`,
        field.required ? 'required' : '',
        field.min ? `min="${escapeHtml(field.min)}"` : ''
    ].filter(Boolean).join(' ');

    let control = '';
    if (field.type === 'select') {
        control = `<select ${attrs}>${field.options.map(option => {
            const selected = option === field.value ? ' selected' : '';
            return `<option value="${escapeHtml(option)}"${selected}>${escapeHtml(option)}</option>`;
        }).join('')}</select>`;
    } else if (field.type === 'textarea') {
        control = `<textarea ${attrs}>${escapeHtml(field.value || '')}</textarea>`;
    } else {
        control = `<input type="${escapeHtml(field.type || 'text')}" value="${escapeHtml(field.value || '')}" ${attrs}>`;
    }

    return `
        <div class="protocol-field${fullClass}">
            <label for="protocol-field-${escapeHtml(field.name)}">${escapeHtml(field.label)}${requiredMark}</label>
            ${control}
        </div>`;
}

function collectDispatchProtocolValues() {
    const form = document.getElementById('dispatch-protocol-form');
    const values = {};
    if (!form) return values;
    new FormData(form).forEach((value, key) => {
        values[key] = String(value || '').trim();
    });
    return values;
}

function updateDispatchProtocolPreview() {
    const form = document.getElementById('dispatch-protocol-form');
    const preview = document.getElementById('protocol-message-preview');
    if (!form || !preview) return;
    const config = DISPATCH_PROTOCOLS[form.dataset.kind];
    if (!config) return;
    preview.textContent = config.preview(collectDispatchProtocolValues());
}

function submitDispatchProtocol(event) {
    event.preventDefault();
    const form = document.getElementById('dispatch-protocol-form');
    const submitBtn = document.getElementById('protocol-submit-btn');
    if (!form) return;
    const kind = form.dataset.kind;
    const config = DISPATCH_PROTOCOLS[kind];
    if (!config) return;

    const values = collectDispatchProtocolValues();
    const missing = config.fields.find(field => field.required && !values[field.name]);
    if (missing) {
        showNotification(`${missing.label} is required`, 'warning');
        const input = form.querySelector(`[name="${missing.name}"]`);
        if (input) input.focus();
        return;
    }

    const formattedMessage = config.preview(values);
    const details = {
        protocol: kind,
        protocol_label: config.label,
        audience: values.audience || '',
        location: values.location || values.area || '',
        status: values.status || '',
        priority: values.priority || '',
        fields: values,
        formatted_message: formattedMessage,
        created_at: new Date().toISOString()
    };

    if (submitBtn) submitBtn.disabled = true;
    postJSON('api/activity_event.php', {
        action: config.action,
        entity_type: 'dispatch_protocol',
        details: JSON.stringify(details)
    }).then(res => {
        if (res && res.ok) {
            showNotification(`${config.title} sent`, config.tone);
            closeDispatchProtocolModal();
        } else {
            showNotification('Protocol alert failed', 'error');
        }
    }).catch(() => {
        showNotification('Network error', 'error');
    }).finally(() => {
        if (submitBtn) submitBtn.disabled = false;
    });
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

document.addEventListener('DOMContentLoaded', () => {
    const form = document.getElementById('dispatch-protocol-form');
    const modal = document.getElementById('dispatch-protocol-modal');
    if (form) {
        form.addEventListener('input', updateDispatchProtocolPreview);
        form.addEventListener('change', updateDispatchProtocolPreview);
        form.addEventListener('submit', submitDispatchProtocol);
    }
    if (modal) {
        modal.addEventListener('click', event => {
            if (event.target === modal) {
                closeDispatchProtocolModal();
            }
        });
    }
});

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
    window.location.href = 'dispatcher/gps.php?unit_id=' + encodeURIComponent(unitId) + (identifier ? ('&unit=' + encodeURIComponent(identifier)) : '');
}

function refreshAIRecommendations() {
    fetch('api/ai_recommendations.php')
      .then(r => r.json())
      .then(data => {
        const el = document.getElementById('ai-recommendations-content');
        if (data.ok && data.text) {
            el.innerHTML = renderAiText(data.text, 'ai-recommendation-text');
        } else {
            const msg = (data && data.error) ? String(data.error) : 'Unable to generate AI recommendations at this time.';
            el.innerHTML = '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> ' + escapeHtml(msg).replace(/\n/g, '<br>') + '</div>';
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
    if (!container) return Promise.resolve([]);
    return fetch('api/incidents_list.php?status=pending', { cache: 'no-store' })
      .then(r => r.json())
      .then(res => {
        if (!res.ok) return;
        const items = res.items || [];
        const badge = document.getElementById('active-calls-badge');
        if (badge) badge.textContent = `${items.length} Pending`;
        if (!items.length) {
            container.innerHTML = '<div class="call-card"><div class="call-info"><div class="call-details"><div class="call-title">No pending emergency calls.</div></div></div></div>';
            return items;
        }
        container.innerHTML = '';
        items.forEach(it => {
            const prio = (it.priority || 'medium').toLowerCase();
            const prioClass = prio === 'critical'
                ? 'critical'
                : (prio === 'high' || prio === 'urgent'
                    ? 'high'
                    : (prio === 'low' ? 'low' : 'medium'));
            const timeAgo = formatTimeAgo(it.created_at) || 'Just now';
            const title = it.title || it.type || 'Incident';
            const caller = it.caller_name || 'Unknown';
            const phone = it.caller_phone || '';
            const card = document.createElement('div');
            card.className = 'call-card ' + prioClass;
            card.setAttribute('data-incident-id', String(it.id));
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
                    ${phone ? `<button class="btn-action-small call-phone-btn" onclick="contactCaller(this)" data-phone="${escapeAttr(phone)}"><i class="fas fa-phone"></i> Call</button>` : ''}
                </div>`;
            container.appendChild(card);
        });
        return items;
      }).catch(() => []);
}

function removeIncidentFromActiveCalls(incidentId) {
    const id = toIncidentId(incidentId);
    if (id === null) return;
    const container = document.getElementById('active-calls-container');
    if (!container) return;
    const card = container.querySelector(`[data-incident-id="${id}"]`);
    if (card) {
        card.remove();
    }
    const remaining = container.querySelectorAll('.call-card[data-incident-id]').length;
    const badge = document.getElementById('active-calls-badge');
    if (badge) badge.textContent = `${remaining} Pending`;
    if (remaining === 0) {
        container.innerHTML = '<div class="call-card"><div class="call-info"><div class="call-details"><div class="call-title">No pending emergency calls.</div></div></div></div>';
    }
}

window.addEventListener('storage', function(e) {
    if (e.key === 'ers_incidents' || e.key === 'ers_incidents_changed' || e.key === 'ers_last_logged_incident') {
        refreshActiveCalls();
        loadIncidentMarkers();
    }
});

window.addEventListener('ers:incident-queue-updated', function() {
    refreshActiveCalls();
    loadIncidentMarkers();
});




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
