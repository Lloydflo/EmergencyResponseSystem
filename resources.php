<?php
$pageTitle = 'Resources Status Management';
require_once __DIR__ . '/includes/auth.php';
// Require full login (including OTP verification) before loading page
require_login('resources.php');
$current_user = function_exists('get_logged_in_user') ? get_logged_in_user() : null;
$requestor_name = $current_user ? ($current_user['name'] ?? ($current_user['email'] ?? '')) : '';

// Initialize default values
$totalVehicles = 0;
$activePersonnel = 0;
$equipmentItems = 0;
$resourceAiData = [
    'vehicles_total' => 0,
    'vehicles_available' => 0,
    'vehicles_inuse' => 0,
    'vehicles_offline' => 0,
    'personnel_total' => 0,
    'personnel_available' => 0,
    'personnel_inuse' => 0,
    'personnel_offline' => 0,
    'equipment_total' => 0,
    'equipment_available' => 0,
    'equipment_inuse' => 0,
    'equipment_offline' => 0,
    'active_incidents' => 0,
    'pending_request_summary' => 'vehicle=0, personnel=0, equipment=0, other=0',
];

// Fetch resource data from database
try {
    require_once __DIR__ . '/includes/db.php';
    $pdo = get_db_connection();
    
    if ($pdo) {
        // Get total vehicles (units)
        $totalVehicles = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status != 'maintenance'")->fetch()['c'];
        
        // Get active personnel (staff on duty or available)
        $activePersonnel = (int)$pdo->query("SELECT COUNT(*) AS c FROM staff WHERE status IN ('available','on_duty')")->fetch()['c'];
        
        // Get equipment items (resources of type equipment)
        $equipmentItems = (int)$pdo->query("SELECT COUNT(*) AS c FROM resources WHERE type = 'equipment' AND status != 'maintenance'")->fetch()['c'];

        $vehiclesTotal = (int)$pdo->query("SELECT COUNT(*) AS c FROM units")->fetch()['c'];
        $vehiclesAvailable = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status='available'")->fetch()['c'];
        $vehiclesInUse = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status IN ('assigned','acknowledged','enroute','on_scene')")->fetch()['c'];
        $vehiclesOffline = max(0, $vehiclesTotal - $vehiclesAvailable - $vehiclesInUse);

        $personnelTotal = (int)$pdo->query("SELECT COUNT(*) AS c FROM staff")->fetch()['c'];
        $personnelAvailable = (int)$pdo->query("SELECT COUNT(*) AS c FROM staff WHERE status IN ('available','on_duty')")->fetch()['c'];
        $personnelOffline = (int)$pdo->query("SELECT COUNT(*) AS c FROM staff WHERE status IN ('off_duty','leave')")->fetch()['c'];
        $personnelInUse = max(0, $personnelTotal - $personnelAvailable - $personnelOffline);

        $equipmentTotal = (int)$pdo->query("SELECT COUNT(*) AS c FROM resources WHERE type='equipment'")->fetch()['c'];
        $equipmentAvailable = (int)$pdo->query("SELECT COUNT(*) AS c FROM resources WHERE type='equipment' AND status IN ('available','ready')")->fetch()['c'];
        $equipmentInUse = (int)$pdo->query("SELECT COUNT(*) AS c FROM resources WHERE type='equipment' AND status IN ('deployed','in_use','assigned')")->fetch()['c'];
        $equipmentOffline = max(0, $equipmentTotal - $equipmentAvailable - $equipmentInUse);

        $activeIncidents = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status IN ('pending','dispatched','active','in_progress')")->fetch()['c'];

        $pendingRows = [];
        try {
            $pendingRows = $pdo->query("SELECT details FROM resource_requests WHERE status='pending' ORDER BY date_requested DESC LIMIT 100")->fetchAll();
        } catch (Throwable $e) {
            $pendingRows = [];
        }
        $byType = ['vehicle' => 0, 'personnel' => 0, 'equipment' => 0, 'other' => 0];
        foreach ($pendingRows as $row) {
            $details = json_decode((string)($row['details'] ?? ''), true);
            $type = strtolower((string)($details['type'] ?? 'other'));
            $qty = (int)($details['quantity'] ?? 1);
            if ($qty < 1) $qty = 1;
            if (!isset($byType[$type])) $type = 'other';
            $byType[$type] += $qty;
        }
        $pendingSummary = 'vehicle=' . $byType['vehicle']
            . ', personnel=' . $byType['personnel']
            . ', equipment=' . $byType['equipment']
            . ', other=' . $byType['other'];

        $resourceAiData = [
            'vehicles_total' => $vehiclesTotal,
            'vehicles_available' => $vehiclesAvailable,
            'vehicles_inuse' => $vehiclesInUse,
            'vehicles_offline' => $vehiclesOffline,
            'personnel_total' => $personnelTotal,
            'personnel_available' => $personnelAvailable,
            'personnel_inuse' => $personnelInUse,
            'personnel_offline' => $personnelOffline,
            'equipment_total' => $equipmentTotal,
            'equipment_available' => $equipmentAvailable,
            'equipment_inuse' => $equipmentInUse,
            'equipment_offline' => $equipmentOffline,
            'active_incidents' => $activeIncidents,
            'pending_request_summary' => $pendingSummary,
        ];
    }
} catch (Throwable $e) {
    // Keep default values if database query fails
    error_log('Resources page database error: ' . $e->getMessage());
}
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
    <link rel="stylesheet" href="css/resources.css">
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include 'includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include 'includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Emergency Resources Status
       =================================== -->
    <div class="main-content">
        <div class="main-container">
            <div style="height: 3.5rem;"></div>
                        </div>

                        
            <!-- Resource Overview -->
            <div class="resource-overview">
                <div class="overview-card">
                    <div class="overview-icon vehicles">
                        <i class="fas fa-ambulance"></i>
                    </div>
                    <div class="overview-value"><?php echo $totalVehicles; ?></div>
                    <div class="overview-label">Total Vehicles</div>
                </div>
                <div class="overview-card">
                    <div class="overview-icon personnel">
                        <i class="fas fa-users"></i>
                    </div>
                    <div class="overview-value"><?php echo $activePersonnel; ?></div>
                    <div class="overview-label">Active Personnel</div>
                </div>
                <div class="overview-card">
                    <div class="overview-icon equipment">
                        <i class="fas fa-toolbox"></i>
                    </div>
                    <div class="overview-value"><?php echo $equipmentItems; ?></div>
                    <div class="overview-label">Equipment Items</div>
                </div>
            </div>

            <!-- Quick Actions -->
            <div class="quick-actions">
                <!-- Removed Request Resource button: Only responders can send requests, admin receives -->
                <button class="quick-action-btn" onclick="emergencyAllocation()">
                    <i class="fas fa-exclamation-triangle"></i>
                    Emergency Allocation
                </button>
                <button class="quick-action-btn" onclick="resourceReport()">
                    <i class="fas fa-chart-bar"></i>
                    Generate Report
                </button>
            </div>

            <!-- Resource Filters -->
            <div class="resource-filters">
                <h2 style="font-size: 1.25rem; font-weight: 700; color: #333; margin-bottom: 1.5rem; display: flex; align-items: center;">
                    <i class="fas fa-filter" style="margin-right: 0.5rem; color: #007bff;"></i>
                    Resource Filters
                </h2>
                <div class="filter-grid">
                    <div class="filter-group">
                        <label for="resource-type">Resource Type</label>
                        <select id="resource-type">
                            <option value="">All Resources</option>
                            <option value="vehicles">Vehicles</option>
                            <option value="personnel">Personnel</option>
                            <option value="equipment">Equipment</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="status-filter">Status</label>
                        <select id="status-filter">
                            <option value="">All Status</option>
                            <option value="available">Available</option>
                            <option value="inuse">In Use</option>
                            <option value="offline">Offline</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="location-filter">Location</label>
                        <select id="location-filter">
                            <option value="">All Locations</option>
                            <option value="station-1">Station 1</option>
                            <option value="station-2">Station 2</option>
                            <option value="station-3">Station 3</option>
                            <option value="enroute">En Route</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="search-resource">Search</label>
                        <input type="text" id="search-resource" placeholder="Search resources...">
                    </div>
                </div>
            </div>

            <!-- Resource Tabs -->
            <!-- Combined Resources Table -->
            <div class="resources-table-section" style="margin-top:2rem;">
                <h2 style="font-size: 1.2rem; font-weight: 700; color: #333; margin-bottom: 1rem; display: flex; align-items: center;">
                    <i class="fas fa-table" style="margin-right: 0.5rem; color: #007bff;"></i>
                    All Resources
                </h2>
                <div style="overflow-x:auto;">
                <style>
                .resource-table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 1.08rem;
                }
                .resource-table th, .resource-table td {
                    padding: 0.85em 1.1em;
                    border: 1px solid #e0e0e0;
                    text-align: left;
                }
                .resource-table th {
                    background: #f7f7f7;
                    font-size: 1.13rem;
                }
                /* Make All Resources table vertically scrollable with fixed header */
                .resource-table.scrollable thead, .resource-table.scrollable tbody { display: block; }
                .resource-table.scrollable tbody { max-height: 380px; overflow-y: auto; }
                .resource-table.scrollable thead tr, .resource-table.scrollable tbody tr { display: table; width: 100%; table-layout: fixed; }
                /* Column widths: Type, Name/Description, Status, Location, Actions */
                .resource-table.scrollable thead th:nth-child(1),
                .resource-table.scrollable tbody td:nth-child(1) { width: 12%; }
                .resource-table.scrollable thead th:nth-child(2),
                .resource-table.scrollable tbody td:nth-child(2) { width: 36%; }
                .resource-table.scrollable thead th:nth-child(3),
                .resource-table.scrollable tbody td:nth-child(3) { width: 12%; }
                .resource-table.scrollable thead th:nth-child(4),
                .resource-table.scrollable tbody td:nth-child(4) { width: 20%; }
                .resource-table.scrollable thead th:nth-child(5),
                .resource-table.scrollable tbody td:nth-child(5) { width: 20%; }
                .resource-table tr.resource-row-vehicle { background: #eafaf1; }
                .resource-table tr.resource-row-personnel { background: #fffbe7; }
                .resource-table tr.resource-row-equipment { background: #f9eaf6; }
                .resource-status-available {
                    background: #d4edda;
                    color: #218838;
                    font-weight: 600;
                    border-radius: 12px;
                    padding: 0.2em 0.6em;
                    font-size: 0.9em;
                    white-space: nowrap;
                    display: inline-block;
                }
                .resource-status-inuse {
                    background: #fff3cd;
                    color: #856404;
                    font-weight: 600;
                    border-radius: 12px;
                    padding: 0.2em 0.6em;
                    font-size: 0.9em;
                    white-space: nowrap;
                    display: inline-block;
                }
                .resource-status-offline {
                    background: #f8d7da;
                    color: #721c24;
                    font-weight: 600;
                    border-radius: 12px;
                    padding: 0.2em 0.6em;
                    font-size: 0.9em;
                    white-space: nowrap;
                    display: inline-block;
                }
                /* Truncate long text in Name/Description and Location */
                td.resource-title, td.detail-value { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
                .resource-action-btn {
                    border: none;
                    border-radius: 6px;
                    width: 36px;
                    height: 36px;
                    padding: 0;
                    font-weight: 600;
                    margin-right: 0.3em;
                    font-size: 16px; /* icon size */
                    cursor: pointer;
                    transition: background 0.2s, color 0.2s;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    background: #fff;
                    color: #111;
                }
                .resource-action-btn i { margin: 0; }
                .resource-action-btn:hover {
                    background: #111;
                    color: #fff;
                }
                /* Inline actions: show three buttons in a row */
                .actions-inline { display: flex; gap: 8px; align-items: center; flex-wrap: nowrap; }
                </style>
                <table class="resource-table scrollable">
                    <thead>
                        <tr>
                            <th>Type</th>
                            <th>Name/Description</th>
                            <th>Status</th>
                            <th>Location</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="resource-list-dynamic">
                        <!-- Table will be rendered here by JS -->
                    </tbody>
                </table>
                <script>
                // Resource data loaded from backend
                let RESOURCES = [];
                const SAMPLE_RESOURCES = [
                    { id: 910001, type: 'personnel', name: 'Responder Ana Reyes', role: 'Paramedic', status: 'available', location: 'station-1', actions: ['deploy', 'contact', 'schedule', 'details'] },
                    { id: 910002, type: 'personnel', name: 'Responder Mark Santos', role: 'EMT', status: 'available', location: 'station-2', actions: ['deploy', 'contact', 'schedule', 'details'] },
                    { id: 910003, type: 'personnel', name: 'Responder Leo Cruz', role: 'Nurse', status: 'available', location: 'station-3', actions: ['deploy', 'contact', 'schedule', 'details'] },
                    { id: 920001, type: 'equipment', name: 'Portable Defibrillator', role: 'Medical Equipment', status: 'available', location: 'station-1', actions: ['deploy', 'assign', 'check', 'details'] },
                    { id: 920002, type: 'equipment', name: 'Trauma Kit', role: 'Medical Equipment', status: 'available', location: 'station-2', actions: ['deploy', 'assign', 'check', 'details'] },
                    { id: 920003, type: 'equipment', name: 'Oxygen Tank', role: 'Medical Equipment', status: 'available', location: 'station-3', actions: ['deploy', 'assign', 'check', 'details'] }
                ];

                function mergeSampleResources(items) {
                    if (Array.isArray(items) && items.length > 0) {
                        return items;
                    }
                    return SAMPLE_RESOURCES.map(sample => ({ ...sample, actions: sample.actions.slice() }));
                }

                async function loadResources() {
                    const container = document.getElementById('resource-list-dynamic');
                    if (container) container.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888;">Loading resources…</td></tr>';
                    try {
                        const res = await fetch('api/resources_combined.php');
                        const data = await res.json();
                        const fetchedItems = (data.ok && Array.isArray(data.items)) ? data.items : [];
                        RESOURCES = mergeSampleResources(fetchedItems);
                        renderDynamicResources();
                    } catch (e) {
                        RESOURCES = mergeSampleResources([]);
                        renderDynamicResources();
                    }
                }

                // Filter elements
                const typeFilter = document.getElementById('resource-type');
                const statusFilter = document.getElementById('status-filter');
                const locationFilter = document.getElementById('location-filter');
                const searchInput = document.getElementById('search-resource');

                function passFilters(r) {
                    const typeValue = (typeFilter.value || '').toLowerCase();
                    const statusValue = (statusFilter.value || '').toLowerCase();
                    const locationValue = (locationFilter.value || '').toLowerCase();
                    const searchValue = (searchInput.value || '').toLowerCase();

                    if (typeValue && (r.type || '').toLowerCase() !== typeValue) return false;
                    if (statusValue && (r.status || '').toLowerCase() !== statusValue) return false;
                    if (locationValue && (r.location || '').toLowerCase() !== locationValue) return false;
                    if (searchValue) {
                        const hay = [r.name, r.location, r.role]
                            .map(v => (v || '').toString().toLowerCase()).join(' ');
                        if (!hay.includes(searchValue)) return false;
                    }
                    return true;
                }

                function resourceRowHtml(r) {
                    let rowClass = r.type === 'vehicles' ? 'resource-row-vehicle' : (r.type === 'personnel' ? 'resource-row-personnel' : 'resource-row-equipment');
                    let statusClass = r.status === 'available' ? 'resource-status-available' : (r.status === 'inuse' ? 'resource-status-inuse' : 'resource-status-offline');
                    let statusLabel = r.status === 'available' ? 'Avail' : (r.status === 'inuse' ? 'Busy' : 'Offline');
                    const resourceName = String(r.name || '');
                    const unitIdentifier = r.type === 'vehicles'
                        ? String(r.identifier || r.name || '')
                        : '';
                    // Build actions and render inline (up to 4)
                    const btns = [];
                    const resourceActions = Array.isArray(r.actions) ? r.actions.slice() : [];
                    if (!resourceActions.includes('deploy')) resourceActions.unshift('deploy');
                    const actionSet = new Set(resourceActions);
                    if (actionSet.has('deploy')) btns.push(`<button class=\"resource-action-btn deploy\" title=\"Deploy\" aria-label=\"Deploy\" onclick=\"deployResource(this)\"><i class=\"fas fa-play\"></i></button>`);
                    if (actionSet.has('track')) btns.push(`<button class=\"resource-action-btn track\" title=\"Track\" aria-label=\"Track\" onclick=\"trackResource(this)\"><i class=\"fas fa-location-arrow\"></i></button>`);
                    if (actionSet.has('service')) btns.push(`<button class=\"resource-action-btn service\" title=\"Service\" aria-label=\"Service\" onclick=\"serviceResource(this)\"><i class=\"fas fa-wrench\"></i></button>`);
                    if (actionSet.has('details')) btns.push(`<button class=\"resource-action-btn details\" title=\"Details\" aria-label=\"Details\" onclick=\"resourceDetails(this)\"><i class=\"fas fa-info-circle\"></i></button>`);
                    if (actionSet.has('contact')) btns.push(`<button class=\"resource-action-btn contact\" title=\"Contact\" aria-label=\"Contact\" onclick=\"contactPersonnel(this)\"><i class=\"fas fa-phone\"></i></button>`);
                    if (actionSet.has('schedule')) btns.push(`<form style=\"display:inline;\" onsubmit=\"event.preventDefault(); openScheduleModal('${r.name}');\"><button type=\"submit\" class=\"resource-action-btn schedule\" title=\"Schedule\" aria-label=\"Schedule\"><i class=\"fas fa-calendar\"></i></button></form>`);
                    if (actionSet.has('assign')) btns.push(`<button class=\"resource-action-btn assign\" title=\"Assign\" aria-label=\"Assign\" onclick=\"assignEquipment(this)\"><i class=\"fas fa-link\"></i></button>`);
                    if (actionSet.has('check')) btns.push(`<button class=\"resource-action-btn check\" title=\"Check\" aria-label=\"Check\" onclick=\"checkEquipment(this)\"><i class=\"fas fa-check-circle\"></i></button>`);
                    if (actionSet.has('calibrate')) btns.push(`<button class=\"resource-action-btn calibrate\" title=\"Calibrate\" aria-label=\"Calibrate\" onclick=\"calibrateEquipment(this)\"><i class=\"fas fa-tools\"></i></button>`);
                    const visibleBtns = btns.slice(0, Math.min(btns.length, 4));
                    const actionsHtml = `<div class=\"actions-inline\">${visibleBtns.join('')}</div>`;
                    // Icon by type
                    let iconHtml = '';
                    if (r.type === 'vehicles') iconHtml = '<i class="fas fa-truck-medical" style="color:#dc3545;"></i>';
                    else if (r.type === 'personnel') iconHtml = '<i class="fas fa-user" style="color:#28a745;"></i>';
                    else iconHtml = '<i class="fas fa-toolbox" style="color:#e83e8c;"></i>';
                    return `<tr class=\"${rowClass}\" data-type=\"${r.type}\" data-status=\"${r.status}\" data-location=\"${escapeAttrValue(r.location || '')}\" data-resource-id=\"${escapeAttrValue(r.id)}\" data-resource-name=\"${escapeAttrValue(resourceName)}\" data-unit-identifier=\"${escapeAttrValue(unitIdentifier)}\">\n`+
                        `<td>${iconHtml} ${r.type.charAt(0).toUpperCase() + r.type.slice(1)}</td>`+
                        `<td class=\"resource-title\">${r.name}${r.role ? ' <br><span style=\\"font-size:0.95em;color:#888;\\">'+r.role+'</span>' : ''}</td>`+
                        `<td><span class=\"${statusClass}\">${statusLabel}</span></td>`+
                        `<td class=\"detail-value\">${r.location || ''}</td>`+
                        `<td>${actionsHtml}</td>`+
                    `</tr>`;
                }

                function renderDynamicResources() {
                    const container = document.getElementById('resource-list-dynamic');
                    if (!container) return;
                    const filtered = RESOURCES.filter(passFilters);
                    if (!filtered.length) {
                        container.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888;">No resources found.</td></tr>';
                    } else {
                        container.innerHTML = filtered.map(resourceRowHtml).join('');
                    }
                }

                function applyTableFilters() {
                    renderDynamicResources();
                }

                // Add event listeners to filters
                typeFilter.addEventListener('change', applyTableFilters);
                statusFilter.addEventListener('change', applyTableFilters);
                locationFilter.addEventListener('change', applyTableFilters);
                searchInput.addEventListener('input', applyTableFilters);

                // Initial render
                document.addEventListener('DOMContentLoaded', function() {
                    loadResources();
                });
                </script>
            </div>

            <!-- Resource Requests Table (placed under All Resources) -->
            <div class="resource-requests-table-section" style="margin-top:2rem;">
                <h2 style="font-size: 1.2rem; font-weight: 700; color: #333; margin-bottom: 1rem; display: flex; align-items: center;">
                    <i class="fas fa-clipboard-list" style="margin-right: 0.5rem; color: #007bff;"></i>
                    Request
                </h2>
                <div style="overflow-x:auto;">
                <style>
                .request-table {
                    width: 100%;
                    border-collapse: collapse;
                    font-size: 1.08rem;
                }
                .request-table th, .request-table td {
                    padding: 0.75em 1em;
                    border: 1px solid #e0e0e0;
                    text-align: left;
                }
                .request-table th {
                    background: #f7f7f7;
                    font-size: 1.13rem;
                }
                .request-action-btn {
                    border: none;
                    border-radius: 6px;
                    padding: 0.45em 1em;
                    font-weight: 600;
                    font-size: 0.95em;
                    cursor: pointer;
                    transition: background 0.2s, color 0.2s;
                    display: inline-flex;
                    align-items: center;
                    gap: 0.4em;
                    background: #007bff;
                    color: #fff;
                }
                .request-action-btn:hover { background: #0056b3; }
                .status-badge {
                    display: inline-block;
                    padding: 0.35em 0.8em;
                    border-radius: 14px;
                    font-weight: 600;
                    font-size: 0.9em;
                    color: #fff;
                }
                .status-pending { background: #6c757d; }
                .status-approved { background: #28a745; }
                .status-rejected { background: #dc3545; }
                .btn-approve { background: #28a745; }
                .btn-approve:hover { background: #1e7e34; }
                .btn-reject { background: #dc3545; }
                .btn-reject:hover { background: #bd2130; }
                </style>
                <table class="request-table">
                    <thead>
                        <tr>
                            <th>Resource Name</th>
                            <th>Type</th>
                            <th>Quantity</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody id="request-list">
                        <?php
                        // Fetch resource requests from DB
                        try {
                            if ($pdo) {
                                $stmt = $pdo->query("SELECT * FROM resource_requests ORDER BY date_requested DESC LIMIT 50");
                                while ($row = $stmt->fetch()) {
                                    $details = json_decode($row['details'], true);
                                    $type = $details['type'] ?? '';
                                    $quantity = $details['quantity'] ?? '';
                                    $location = $details['location'] ?? '';
                                    $notes = $details['notes'] ?? '';
                                    $status = $row['status'] ?? 'pending';
                                    $decision = is_array($details) && isset($details['decision_reason']) ? $details['decision_reason'] : '';
                                    $priority = $details['priority'] ?? '';
                                    $urgency = $details['urgency'] ?? '';
                                    $statusClass = 'status-badge status-' . htmlspecialchars($status);
                                    echo '<tr data-id="' . (int)$row['id'] . '" data-notes="' . htmlspecialchars($notes) . '" data-decision-reason="' . htmlspecialchars($decision) . '" data-status="' . htmlspecialchars($status) . '">';
                                    echo '<td>' . htmlspecialchars($row['resource_name']) . '</td>';
                                    echo '<td>' . htmlspecialchars(ucfirst($type)) . '<br><span style="font-size:0.95em;color:#888;">Priority: ' . htmlspecialchars($priority) . ', Urgency: ' . htmlspecialchars($urgency) . '</span></td>';
                                    echo '<td>' . htmlspecialchars($quantity) . '</td>';
                                    echo '<td>' . htmlspecialchars($location) . '<br><span style="font-size:0.95em;color:#888;">' . (!empty($notes) ? 'Notes: ' . htmlspecialchars($notes) : '') . '</span></td>';
                                    echo '<td><span class="' . $statusClass . '">' . htmlspecialchars(ucfirst($status)) . '</span></td>';
                                    echo '<td>';
                                    echo '<button class="request-action-btn" onclick="viewRequestNotes(this)"><i class=\'fas fa-sticky-note\'></i> View Details</button>';
                                    if ($status === 'pending') {
                                        echo ' <button class="request-action-btn btn-approve" onclick="approveRequest(this)"><i class=\'fas fa-check\'></i> Approve</button>';
                                        echo ' <button class="request-action-btn btn-reject" onclick="rejectRequest(this)"><i class=\'fas fa-times\'></i> Reject</button>';
                                    }
                                    echo '</td>';
                                    echo '</tr>';
                                }
                            } else {
                                echo '<tr><td colspan="6" style="text-align:center;color:#888;">Unable to connect to database.</td></tr>';
                            }
                        } catch (Throwable $e) {
                            echo '<tr><td colspan="6" style="text-align:center;color:#888;">Error loading requests.</td></tr>';
                        }
                        ?>
                    </tbody>
                </table>
                </div>
            </div>

            <!-- AI-Powered Resource Gap Recommendations -->
            <div class="ai-predictive-section">
                <div class="ai-predictive-card">
                    <div class="ai-predictive-header">
                        <h2><i class="fas fa-brain"></i> AI Resource Gap Recommendations</h2>
                        <span class="ai-badge"><i class="fas fa-robot"></i> Powered by Gemini AI</span>
                    </div>
                    <div class="ai-snapshot" id="ai-resource-snapshot">
                        <span class="ai-chip"><strong>Vehicles Avail:</strong> <?php echo (int)$resourceAiData['vehicles_available']; ?></span>
                        <span class="ai-chip"><strong>Personnel Avail:</strong> <?php echo (int)$resourceAiData['personnel_available']; ?></span>
                        <span class="ai-chip"><strong>Equipment Avail:</strong> <?php echo (int)$resourceAiData['equipment_available']; ?></span>
                        <span class="ai-chip"><strong>Active Incidents:</strong> <?php echo (int)$resourceAiData['active_incidents']; ?></span>
                    </div>
                    <div class="ai-predictive-content" id="ai-predictive-content">
                        <?php
                        include 'includes/gemini_helper.php';
                        $recommendations = getResourceGapRecommendations($resourceAiData);
                        if ($recommendations) {
                            echo '<div class="ai-predictive-text">' . nl2br(htmlspecialchars($recommendations)) . '</div>';
                        } else {
                            $aiError = function_exists('getGeminiLastError') ? trim((string)getGeminiLastError()) : '';
                            if ($aiError === '') {
                                $aiError = 'Unable to generate AI resource recommendations at this time.';
                            }
                            echo '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> ' . htmlspecialchars($aiError) . '</div>';
                        }
                        ?>
                    </div>
                    <div class="ai-predictive-actions">
                        <button class="btn-ai-refresh" onclick="refreshAIPredictions()">
                            <i class="fas fa-sync"></i> Refresh Recommendations
                        </button>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <!-- Request Resource Modal -->
    <!-- Removed Request Resource Modal: Only responders can send requests, admin receives -->

    <!-- Request Notes Modal -->
    <div class="resource-request-modal" id="requestNotesModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Request Notes</h3>
                <button class="modal-close" onclick="(function(){document.getElementById('requestNotesModal').classList.remove('show'); document.body.style.overflow='';})()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <div id="requestNotesContent" style="line-height:1.6; color:#333;"></div>
            </div>
            <div class="modal-footer">
                <button class="btn-cancel" onclick="(function(){document.getElementById('requestNotesModal').classList.remove('show'); document.body.style.overflow='';})()">Close</button>
            </div>
        </div>
    </div>


    <!-- Emergency Personnel Scheduling Modal -->
    <div class="resource-request-modal" id="scheduleModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Schedule Emergency Personnel</h3>
                <button class="modal-close" onclick="closeScheduleModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="scheduleForm" onsubmit="submitScheduleForm(event)">
                    <div class="form-group">
                        <label for="schedule-personnel-name">Personnel Name</label>
                        <input type="text" id="schedule-personnel-name" name="personnel_name" readonly>
                    </div>
                    <div class="form-group">
                        <label for="schedule-date">Date <span class="required">*</span></label>
                        <input type="date" id="schedule-date" name="date" required>
                    </div>
                    <div class="form-group">
                        <label for="schedule-shift">Shift <span class="required">*</span></label>
                        <select id="schedule-shift" name="shift" required>
                            <option value="">Select Shift</option>
                            <option value="day">Day</option>
                            <option value="night">Night</option>
                            <option value="on-call">On-call</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="schedule-notes">Notes</label>
                        <textarea id="schedule-notes" name="notes" rows="2" placeholder="Special instructions or emergency details..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-cancel" onclick="closeScheduleModal()">Cancel</button>
                        <button type="submit" class="btn-submit">Schedule</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Deploy Resource Modal -->
    <div class="resource-request-modal" id="deployModal">
        <div class="modal-content">
            <div class="modal-header">
                <h3>Deploy Resource</h3>
                <button class="modal-close" onclick="closeDeployModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="modal-body">
                <form id="deployForm" onsubmit="submitDeployForm(event)">
                    <div class="form-group">
                        <label for="deploy-resource-name">Resource</label>
                        <input type="text" id="deploy-resource-name" readonly>
                    </div>
                    <div class="form-group">
                        <label for="deploy-location">Location <span class="required">*</span></label>
                        <input type="text" id="deploy-location" name="location" placeholder="Enter deployment location" required>
                    </div>
                    <div class="form-group">
                        <label for="deploy-quantity">Quantity <span class="required">*</span></label>
                        <input type="number" id="deploy-quantity" name="quantity" min="1" value="1" required>
                    </div>
                    <div class="form-group">
                        <label for="deploy-notes">Notes</label>
                        <textarea id="deploy-notes" name="notes" rows="3" placeholder="Add deployment notes..."></textarea>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn-cancel" onclick="closeDeployModal()">Cancel</button>
                        <button type="submit" class="btn-submit">Deploy</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        function refreshAIPredictions() {
            const container = document.getElementById('ai-predictive-content');
            if (container) {
                container.innerHTML = '<div class="ai-loading"><i class="fas fa-spinner"></i> Generating recommendations...</div>';
            }
            fetch('api/ai_resource_recommendations.php')
                .then(r => r.json())
                .then(data => {
                    if (!container) return;
                    if (data && data.ok && data.text) {
                        container.innerHTML = '<div class="ai-predictive-text">' + String(data.text).replace(/\n/g, '<br>') + '</div>';
                        if (data.snapshot) {
                            const snap = document.getElementById('ai-resource-snapshot');
                            if (snap) {
                                snap.innerHTML =
                                    '<span class="ai-chip"><strong>Vehicles Avail:</strong> ' + Number(data.snapshot.vehicles_available || 0) + '</span>' +
                                    '<span class="ai-chip"><strong>Personnel Avail:</strong> ' + Number(data.snapshot.personnel_available || 0) + '</span>' +
                                    '<span class="ai-chip"><strong>Equipment Avail:</strong> ' + Number(data.snapshot.equipment_available || 0) + '</span>' +
                                    '<span class="ai-chip"><strong>Active Incidents:</strong> ' + Number(data.snapshot.active_incidents || 0) + '</span>';
                            }
                        }
                        showNotification('AI resource recommendations updated', 'success');
                    } else {
                        const msg = (data && data.error) ? String(data.error) : 'Unable to generate AI resource recommendations at this time.';
                        container.innerHTML = '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> ' + msg.replace(/\n/g, '<br>') + '</div>';
                        showNotification(msg, 'error');
                    }
                })
                .catch(() => {
                    if (container) {
                        container.innerHTML = '<div class="ai-error"><i class="fas fa-exclamation-triangle"></i> Network error while generating recommendations.</div>';
                    }
                    showNotification('Network error', 'error');
                });
        }

        // Scheduling Modal Logic
        function openScheduleModal(personnelName) {
            document.getElementById('schedule-personnel-name').value = personnelName;
            document.getElementById('scheduleModal').classList.add('show');
            document.body.style.overflow = 'hidden';
        }
        function closeScheduleModal() {
            document.getElementById('scheduleModal').classList.remove('show');
            document.body.style.overflow = '';
            document.getElementById('scheduleForm').reset();
        }
        function submitScheduleForm(event) {
            event.preventDefault();
            const formData = new FormData(event.target);
            const data = Object.fromEntries(formData);
            showNotification(`Scheduled ${data.personnel_name} for ${data.shift} shift on ${data.date}`, 'success');
            setTimeout(() => { closeScheduleModal(); }, 1500);
            // In production, send data to backend here
        }
        // Close modal on outside click or Escape
        document.addEventListener('DOMContentLoaded', function() {
            const modal = document.getElementById('scheduleModal');
            if (modal) {
                modal.addEventListener('click', function(e) {
                    if (e.target === modal) closeScheduleModal();
                });
            }
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape' && modal.classList.contains('show')) closeScheduleModal();
            });
        });
        // Close notes modal with Escape
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                const notesModal = document.getElementById('requestNotesModal');
                if (notesModal && notesModal.classList.contains('show')) {
                    notesModal.classList.remove('show');
                    document.body.style.overflow = '';
                }
            }
        });
                // Update form fields based on resource type and adjust required inputs
                function updateResourceFormFields() {
                    var type = document.getElementById('request-resource-type').value;
                    var vehicleFields = document.getElementById('vehicle-fields');
                    var personnelFields = document.getElementById('personnel-fields');
                    var equipmentFields = document.getElementById('equipment-fields');
                    var vehicleName = document.getElementById('request-resource-name');
                    var personnelName = document.getElementById('personnel-name');
                    var equipmentType = document.getElementById('equipment-type');

                    vehicleFields.style.display = (type === 'vehicle' || type === 'facility' || type === '') ? '' : 'none';
                    personnelFields.style.display = (type === 'personnel') ? '' : 'none';
                    equipmentFields.style.display = (type === 'equipment') ? '' : 'none';

                    // Toggle required attributes so hidden fields don't block submission
                    if (vehicleName) vehicleName.required = (type === 'vehicle' || type === 'facility' || type === '');
                    if (personnelName) personnelName.required = (type === 'personnel');
                    if (equipmentType) equipmentType.required = (type === 'equipment');
                }
                // Initialize on modal open
                document.addEventListener('DOMContentLoaded', function() {
                    var typeSelect = document.getElementById('request-resource-type');
                    if (typeSelect) typeSelect.addEventListener('change', updateResourceFormFields);
                });
        // Emergency Resources Management Functionality

        let currentTab = 'vehicles';

        // Tab switching functionality
        function switchResourceTab(tabName) {
            // Update tab buttons
            document.querySelectorAll('.resource-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            event.target.classList.add('active');

            // Update content
            document.querySelectorAll('.resource-tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById(tabName).classList.add('active');

            currentTab = tabName;
            showNotification(`${tabName.charAt(0).toUpperCase() + tabName.slice(1)} resources loaded`, 'info');
        }

        // Resource deployment functionality
        let pendingDeployContext = null;

        function deployResource(button) {
            const row = button.closest('tr');
            if (!row) return;
            const resourceName = row.querySelector('.resource-title') ? row.querySelector('.resource-title').textContent : 'Resource';
            const resourceId = row.getAttribute('data-resource-id');
            const resourceType = row.getAttribute('data-type');
            const resourceLocation = row.querySelector('.detail-value') ? row.querySelector('.detail-value').textContent.trim() : '';
            if (!resourceId) { showNotification('Missing resource id', 'error'); return; }
            openDeployModal({
                id: Number(resourceId),
                type: resourceType || '',
                name: resourceName.trim(),
                location: resourceLocation
            });
        }

        function openDeployModal(context) {
            const modal = document.getElementById('deployModal');
            const form = document.getElementById('deployForm');
            if (!modal || !form) return;
            pendingDeployContext = context;
            document.getElementById('deploy-resource-name').value = context.name || 'Resource';
            document.getElementById('deploy-location').value = context.location || '';
            document.getElementById('deploy-quantity').value = '1';
            document.getElementById('deploy-notes').value = '';
            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeDeployModal() {
            const modal = document.getElementById('deployModal');
            const form = document.getElementById('deployForm');
            if (modal) modal.classList.remove('show');
            if (form) form.reset();
            document.body.style.overflow = '';
            pendingDeployContext = null;
        }

        function submitDeployForm(event) {
            event.preventDefault();
            if (!pendingDeployContext) {
                showNotification('No resource selected for deployment', 'error');
                return;
            }
            const formData = new FormData(event.target);
            const location = String(formData.get('location') || '').trim();
            const quantity = Number(formData.get('quantity') || 0);
            const notes = String(formData.get('notes') || '').trim();

            if (!location) {
                showNotification('Location is required', 'error');
                return;
            }
            if (!Number.isFinite(quantity) || quantity < 1) {
                showNotification('Quantity must be at least 1', 'error');
                return;
            }

            const payload = {
                id: Number(pendingDeployContext.id),
                type: pendingDeployContext.type,
                action: 'deploy',
                location: location,
                quantity: quantity,
                notes: notes
            };

            fetch('api/deploy_resource.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload)
            })
            .then(r => r.json())
            .then(data => {
                if (data && data.ok) {
                    showNotification(`${pendingDeployContext.name} deployed to ${location}`, 'success');
                    closeDeployModal();
                    loadResources();
                } else {
                    showNotification('Failed to deploy resource' + (data && data.error ? ': ' + data.error : ''), 'error');
                }
            })
            .catch(() => showNotification('Network error', 'error'));
        }

        // Resource tracking functionality
        function trackResource(button) {
            const row = button.closest('tr');
            if (!row) return;
            const resourceName = row.getAttribute('data-resource-name') || (row.querySelector('.resource-title') ? row.querySelector('.resource-title').textContent : 'Resource');
            const unitIdentifier = row.getAttribute('data-unit-identifier') || resourceName;
            const resourceId = row.getAttribute('data-resource-id');
            const resourceType = row.getAttribute('data-type');
            if (!resourceId) { showNotification('Missing resource id', 'error'); return; }
            if (resourceType === 'vehicles') {
                fetch('api/get_resource_location.php?id=' + encodeURIComponent(resourceId))
                    .then(r => r.json())
                    .then(data => {
                        const lat = data && data.ok ? Number(data.latitude) : NaN;
                        const lng = data && data.ok ? Number(data.longitude) : NaN;
                        if (data && data.ok && Number.isFinite(lat) && Number.isFinite(lng)) {
                            window.location.href = 'gps.php?unit_id=' + encodeURIComponent(resourceId) + '&unit=' + encodeURIComponent(unitIdentifier) + '&from_lat=' + encodeURIComponent(lat) + '&from_lng=' + encodeURIComponent(lng);
                        } else {
                            showNotification('Location data unavailable', 'info');
                            window.location.href = 'gps.php?unit_id=' + encodeURIComponent(resourceId) + '&unit=' + encodeURIComponent(unitIdentifier);
                        }
                    }).catch(() => {
                        window.location.href = 'gps.php?unit_id=' + encodeURIComponent(resourceId) + '&unit=' + encodeURIComponent(unitIdentifier);
                    });
            } else {
                showNotification(`Tracking ${resourceName}...`, 'info');
                window.location.href = 'gps.php';
            }
        }

        // Resource service functionality - removed (maintenance removed per requirements)
        function serviceResource(button) {
            const resourceCard = button.closest('.resource-card');
            const resourceName = resourceCard.querySelector('.resource-title').textContent;
            
            showNotification(`Service information for ${resourceName} - Feature removed`, 'info');
        }

        // Complete maintenance functionality - removed (maintenance feature removed)

        // Resource details functionality
        function resourceDetails(button) {
            const row = button.closest('tr');
            if (!row) return;
            const resourceName = row.querySelector('.resource-title') ? row.querySelector('.resource-title').textContent : 'Resource';
            const resourceType = row.getAttribute('data-type');

            let details = `Resource: ${resourceName}\nType: ${resourceType}\n\n`;

            if (resourceType === 'vehicles') {
                details += '• Vehicle specifications\n• Maintenance history\n• Fuel efficiency\n• Usage statistics\n• GPS tracking data';
            } else if (resourceType === 'personnel') {
                details += '• Certification details\n• Training records\n• Performance metrics\n• Shift schedule\n• Contact information';
            } else if (resourceType === 'equipment') {
                details += '• Equipment specifications\n• Calibration records\n• Usage history\n• Maintenance schedule\n• Storage location';
            }

            alert(details);
        }

        // Personnel management functions
        function assignPersonnel(button) {
            const row = button.closest('tr');
            const personnelName = row && row.querySelector('.resource-title') ? row.querySelector('.resource-title').textContent : 'Personnel';
            const assignment = prompt(`Assign ${personnelName} to which incident/unit?`);
            if (assignment) {
                button.classList.add('active');
                showNotification(`${personnelName} assigned to ${assignment}`, 'success');
            }
        }

        function contactPersonnel(button) {
            const row = button.closest('tr');
            const personnelName = row && row.querySelector('.resource-title') ? row.querySelector('.resource-title').textContent : 'Personnel';
            if (confirm(`Call ${personnelName}?`)) {
                showNotification(`Calling ${personnelName}...`, 'info');
            }
        }

        function personnelSchedule(button) {
            const row = button.closest('tr');
            const personnelName = row && row.querySelector('.resource-title') ? row.querySelector('.resource-title').textContent : 'Personnel';
            alert(`${personnelName} Schedule:\n\n• Monday-Friday: Day Shift\n• Weekends: On-call rotation\n• Next shift: Tomorrow\n• Vacation: Pending`);
        }

        // Equipment management functions
        function assignEquipment(button) {
            const row = button.closest('tr');
            const equipmentName = row && row.querySelector('.resource-title') ? row.querySelector('.resource-title').textContent : 'Equipment';
            const assignment = prompt(`Assign ${equipmentName} to which unit/personnel?`);
            if (assignment) {
                showNotification(`${equipmentName} assigned to ${assignment}`, 'success');
            }
        }

        function checkEquipment(button) {
            const row = button.closest('tr');
            const equipmentName = row && row.querySelector('.resource-title') ? row.querySelector('.resource-title').textContent : 'Equipment';
            showNotification(`${equipmentName} status check: All systems operational`, 'success');
        }

        function calibrateEquipment(button) {
            const row = button.closest('tr');
            const equipmentName = row && row.querySelector('.resource-title') ? row.querySelector('.resource-title').textContent : 'Equipment';
            if (confirm(`Calibrate ${equipmentName}? This may take several minutes.`)) {
                showNotification(`Calibration started for ${equipmentName}`, 'info');
            }
        }

        // Quick action functions
        function requestResource() {
            const modal = document.getElementById('resourceRequestModal');
            if (modal) {
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeResourceModal() {
            const modal = document.getElementById('resourceRequestModal');
            if (modal) {
                modal.classList.remove('show');
                document.body.style.overflow = '';
                document.getElementById('resourceRequestForm').reset();
            }
        }

        function submitResourceRequest(event) {
            event.preventDefault();
            const formEl = event.target;
            const formData = new FormData(formEl);
            // Ensure requestor present (fallback if session missing)
            if (!formData.get('requestor') || String(formData.get('requestor')).trim() === '') {
                formData.set('requestor', 'Admin');
            }
            // Normalize resource_name for non-vehicle types
            const selectedType = formData.get('resource_type');
            if (!formData.get('resource_name')) {
                if (selectedType === 'personnel') {
                    const pn = formEl.querySelector('#personnel-name')?.value || '';
                    if (pn) formData.set('resource_name', pn);
                } else if (selectedType === 'equipment') {
                    const et = formEl.querySelector('#equipment-type')?.value || '';
                    if (et) formData.set('resource_name', et);
                }
            }
            const data = Object.fromEntries(formData);

            fetch('api/request_resource.php', {
                method: 'POST',
                body: formData
            })
            .then(resp => resp.json())
            .then(res => {
                if (res && res.success) {
                    showNotification(`Resource request submitted: ${data.quantity}x ${data.resource_name} (${data.resource_type})`, 'success');
                    appendRequestRow({
                        resource_name: data.resource_name || '',
                        resource_type: data.resource_type || '',
                        quantity: data.quantity || 1,
                        location: data.location || '',
                        notes: data.notes || ''
                    });
                    closeResourceModal();
                    formEl.reset();
                } else {
                    showNotification(`Failed to submit request: ${res && res.error ? res.error : 'Unknown error'}`, 'error');
                }
            })
            .catch(err => {
                console.error('request_resource error', err);
                showNotification('Network error submitting request', 'error');
            });
        }

        function appendRequestRow(row) {
            const tbody = document.getElementById('request-list');
            if (!tbody) return;
            const tr = document.createElement('tr');
            const typeLabel = (row.resource_type || '').charAt(0).toUpperCase() + (row.resource_type || '').slice(1);
            tr.setAttribute('data-notes', row.notes || '');
            tr.setAttribute('data-decision-reason', row.decision_reason || '');
            tr.setAttribute('data-status', (row.status || 'pending'));
            tr.innerHTML = `
                <td>${escapeHtml(row.resource_name || '')}</td>
                <td>${escapeHtml(typeLabel)}</td>
                <td>${escapeHtml(row.quantity || '')}</td>
                <td>${escapeHtml(row.location || '')}</td>
                <td><span class="status-badge status-${escapeHtml((row.status||'pending'))}">${escapeHtml(((row.status||'pending')).charAt(0).toUpperCase() + (row.status||'pending').slice(1))}</span></td>
                <td>
                    <button class="request-action-btn" onclick="viewRequestNotes(this)"><i class='fas fa-sticky-note'></i> View Notes</button>
                    <button class="request-action-btn btn-approve" onclick="approveRequest(this)"><i class='fas fa-check'></i> Approve</button>
                    <button class="request-action-btn btn-reject" onclick="rejectRequest(this)"><i class='fas fa-times'></i> Reject</button>
                </td>
            `;
            tbody.prepend(tr);
        }

        function viewRequestNotes(btn) {
            const tr = btn.closest('tr');
            const notes = tr ? tr.getAttribute('data-notes') : '';
            const status = tr ? tr.getAttribute('data-status') : '';
            const decision = tr ? tr.getAttribute('data-decision-reason') : '';
            const name = tr ? tr.children[0].textContent : 'Request';
            const modal = document.getElementById('requestNotesModal');
            const content = document.getElementById('requestNotesContent');
            if (modal && content) {
                let html = `<strong>${escapeHtml(name)}</strong>`;
                if (status) { html += `<br>Status: ${escapeHtml(status.charAt(0).toUpperCase() + status.slice(1))}`; }
                html += `<br>${notes ? 'Notes: ' + escapeHtml(notes) : 'No notes provided.'}`;
                if (decision) { html += `<br>Decision Reason: ${escapeHtml(decision)}`; }
                content.innerHTML = html;
                modal.classList.add('show');
                document.body.style.overflow = 'hidden';
            }
        }


        function approveRequest(btn) {
            const tr = btn.closest('tr');
            if (!tr) return;
            const id = tr.getAttribute('data-id');
            const reason = prompt('Enter approval notes/reason (optional):', '');
            const fd = new FormData();
            fd.append('id', id || '');
            fd.append('status', 'approved');
            fd.append('reason', reason || '');
            fetch('api/resource_request_update.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res && res.success) {
                        updateRequestRowUI(tr, 'approved', reason || '');
                        showNotification('Request approved', 'success');
                        // Refresh overview cards and tables so DB-backed counts reflect newly provisioned resources.
                        setTimeout(() => { window.location.reload(); }, 700);
                    } else {
                        showNotification('Failed to approve: ' + (res && res.error ? res.error : 'Unknown error'), 'error');
                    }
                })
                .catch(err => { console.error(err); showNotification('Network error', 'error'); });
        }

        function rejectRequest(btn) {
            const tr = btn.closest('tr');
            if (!tr) return;
            const id = tr.getAttribute('data-id');
            let reason = '';
            while (true) {
                reason = prompt('Enter rejection reason (required):', '');
                if (reason === null) return; // cancelled
                if (reason.trim() !== '') break;
                alert('Rejection reason is required.');
            }
            const fd = new FormData();
            fd.append('id', id || '');
            fd.append('status', 'rejected');
            fd.append('reason', reason);
            fetch('api/resource_request_update.php', { method: 'POST', body: fd })
                .then(r => r.json())
                .then(res => {
                    if (res && res.success) {
                        updateRequestRowUI(tr, 'rejected', reason);
                        showNotification('Request rejected', 'error');
                    } else {
                        showNotification('Failed to reject: ' + (res && res.error ? res.error : 'Unknown error'), 'error');
                    }
                })
                .catch(err => { console.error(err); showNotification('Network error', 'error'); });
        }

        function updateRequestRowUI(tr, status, reason) {
            tr.setAttribute('data-status', status);
            tr.setAttribute('data-decision-reason', reason || '');
            const statusCell = tr.children[4];
            if (statusCell) {
                statusCell.innerHTML = `<span class="status-badge status-${escapeHtml(status)}">${escapeHtml(status.charAt(0).toUpperCase() + status.slice(1))}</span>`;
            }
            // Remove approve/reject buttons
            const actionCell = tr.children[5];
            if (actionCell) {
                const approveBtn = actionCell.querySelector('.btn-approve');
                const rejectBtn = actionCell.querySelector('.btn-reject');
                if (approveBtn) approveBtn.remove();
                if (rejectBtn) rejectBtn.remove();
            }
        }

        function escapeHtml(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function escapeAttrValue(str) {
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }


        function emergencyAllocation() {
            if (confirm('Activate emergency resource allocation protocol? This will override normal procedures and prioritize all available resources.')) {
                // In production, this would trigger an API call
                fetch('api/emergency_allocation.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ action: 'activate' })
                }).then(response => response.json())
                  .then(data => {
                      if (data.success) {
                          showNotification('Emergency allocation protocol activated. All available resources prioritized.', 'error');
                          // Refresh resource list
                          setTimeout(() => location.reload(), 2000);
                      } else {
                          showNotification('Failed to activate emergency protocol: ' + (data.error || 'Unknown error'), 'error');
                      }
                  }).catch(error => {
                      console.error('Error:', error);
                      showNotification('Emergency protocol activation initiated (simulated)', 'error');
                  });
            }
        }

        function resourceReport() {
            showNotification('Generating resource report, please wait...', 'info');
            fetch('api/reports_resources.php')
                .then(response => {
                    if (!response.ok) throw new Error('Network response was not ok');
                    return response.text();
                })
                .then(html => {
                    const reportWindow = window.open('', '_blank');
                    reportWindow.document.write(html);
                    reportWindow.document.close();
                    showNotification('Resource report generated and opened in new window', 'success');
                })
                .catch(error => {
                    console.error('Error generating report:', error);
                    showNotification('Failed to generate resource report. Please try again.', 'error');
                });
        }

        // Filter functionality
        document.getElementById('resource-type').addEventListener('change', applyFilters);
        document.getElementById('status-filter').addEventListener('change', applyFilters);
        document.getElementById('location-filter').addEventListener('change', applyFilters);
        document.getElementById('search-resource').addEventListener('input', applyFilters);

        function applyFilters() {
            const typeFilter = document.getElementById('resource-type').value;
            const statusFilter = document.getElementById('status-filter').value;
            const locationFilter = document.getElementById('location-filter').value;
            const searchFilter = document.getElementById('search-resource').value.toLowerCase();

            document.querySelectorAll('.resource-card').forEach(card => {
                let showCard = true;

                // Type filter
                if (typeFilter && card.dataset.type !== typeFilter) {
                    showCard = false;
                }

                // Status filter (exclude maintenance)
                if (statusFilter) {
                    if (card.dataset.status === 'maintenance') {
                        showCard = false; // Always hide maintenance items
                    } else if (card.dataset.status !== statusFilter) {
                        showCard = false;
                    }
                } else {
                    // If no filter, still hide maintenance items
                    if (card.dataset.status === 'maintenance') {
                        showCard = false;
                    }
                }

                // Location filter (simplified - would need more complex logic in real system)
                if (locationFilter) {
                    const location = card.querySelector('.detail-value').textContent.toLowerCase();
                    if (!location.includes(locationFilter.replace('-', ' '))) {
                        showCard = false;
                    }
                }

                // Search filter
                if (searchFilter) {
                    const title = card.querySelector('.resource-title').textContent.toLowerCase();
                    const details = card.textContent.toLowerCase();
                    if (!title.includes(searchFilter) && !details.includes(searchFilter)) {
                        showCard = false;
                    }
                }

                card.style.display = showCard ? 'block' : 'none';
            });

            showNotification('Filters applied', 'info');
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

        // Add CSS animations and modal styles
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

            @keyframes fadeIn {
                from { opacity: 0; }
                to { opacity: 1; }
            }

            .resource-card, .btn-resource, .resource-tab {
                transition: all 0.3s ease;
            }

            .resource-card:hover {
                transform: translateY(-2px);
                box-shadow: 0 4px 16px rgba(0,0,0,0.15);
            }

            .btn-resource:hover {
                transform: translateY(-1px);
                box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            }

            .resource-tab:hover {
                background-color: #f8f9fa;
            }

            /* Resource Request Modal Styles */
            .resource-request-modal {
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                display: flex;
                align-items: center;
                justify-content: center;
                z-index: 2000;
                opacity: 0;
                visibility: hidden;
                transition: all 0.3s ease;
            }

            .resource-request-modal.show {
                opacity: 1;
                visibility: visible;
            }

            .resource-request-modal .modal-content {
                background: white;
                border-radius: 12px;
                width: 90%;
                max-width: 600px;
                max-height: 90vh;
                overflow-y: auto;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                transform: scale(0.9);
                transition: transform 0.3s ease;
            }

            .resource-request-modal.show .modal-content {
                transform: scale(1);
            }

            .resource-request-modal .modal-header {
                display: flex;
                justify-content: space-between;
                align-items: center;
                padding: 1.5rem;
                border-bottom: 1px solid #e5e7eb;
            }

            .resource-request-modal .modal-header h3 {
                margin: 0;
                font-size: 1.5rem;
                font-weight: 700;
                color: #333;
            }

            .resource-request-modal .modal-close {
                background: none;
                border: none;
                font-size: 1.5rem;
                color: #666;
                cursor: pointer;
                padding: 0.5rem;
                border-radius: 4px;
                transition: all 0.2s ease;
            }

            .resource-request-modal .modal-close:hover {
                background-color: #f3f4f6;
                color: #333;
            }

            .resource-request-modal .modal-body {
                padding: 1.5rem;
            }

            .resource-request-modal .form-group {
                margin-bottom: 1.5rem;
            }

            .resource-request-modal .form-group label {
                display: block;
                margin-bottom: 0.5rem;
                font-weight: 600;
                color: #333;
                font-size: 0.9rem;
            }

            .resource-request-modal .form-group .required {
                color: #dc3545;
            }

            .resource-request-modal .form-group input,
            .resource-request-modal .form-group select,
            .resource-request-modal .form-group textarea {
                width: 100%;
                padding: 0.75rem;
                border: 1px solid #d1d5db;
                border-radius: 6px;
                font-size: 0.95rem;
                transition: border-color 0.2s ease;
            }

            .resource-request-modal .form-group input:focus,
            .resource-request-modal .form-group select:focus,
            .resource-request-modal .form-group textarea:focus {
                outline: none;
                border-color: #007bff;
                box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
            }

            .resource-request-modal .form-group textarea {
                resize: vertical;
                min-height: 80px;
            }

            .resource-request-modal .modal-footer {
                display: flex;
                justify-content: flex-end;
                gap: 1rem;
                padding: 1.5rem;
                border-top: 1px solid #e5e7eb;
            }

            .resource-request-modal .btn-cancel,
            .resource-request-modal .btn-submit {
                padding: 0.75rem 1.5rem;
                border: none;
                border-radius: 6px;
                font-weight: 600;
                cursor: pointer;
                transition: all 0.2s ease;
            }

            .resource-request-modal .btn-cancel {
                background-color: #f3f4f6;
                color: #333;
            }

            .resource-request-modal .btn-cancel:hover {
                background-color: #e5e7eb;
            }

            .resource-request-modal .btn-submit {
                background-color: #007bff;
                color: white;
            }

            .resource-request-modal .btn-submit:hover {
                background-color: #0056b3;
                transform: translateY(-1px);
            }
        `;
        document.head.appendChild(style);

        // Initialize
        document.addEventListener('DOMContentLoaded', function() {
            // Close modal when clicking outside
            const requestModal = document.getElementById('resourceRequestModal');
            if (requestModal) {
                requestModal.addEventListener('click', function(e) {
                    if (e.target === requestModal) {
                        closeResourceModal();
                    }
                });
            }

            const deployModal = document.getElementById('deployModal');
            if (deployModal) {
                deployModal.addEventListener('click', function(e) {
                    if (e.target === deployModal) {
                        closeDeployModal();
                    }
                });
            }

            // Close modal on Escape key
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    const deployModalEl = document.getElementById('deployModal');
                    if (deployModalEl && deployModalEl.classList.contains('show')) {
                        closeDeployModal();
                        return;
                    }
                    const requestModalEl = document.getElementById('resourceRequestModal');
                    if (requestModalEl && requestModalEl.classList.contains('show')) {
                        closeResourceModal();
                    }
                }
            });

            // Auto-refresh resource status simulation (optional - can be removed in production)
            // setInterval(() => {
            //     // Simulate random status updates
            //     if (Math.random() < 0.1) {
            //         const availableCards = document.querySelectorAll('.resource-card.available');
            //         if (availableCards.length > 0) {
            //             const randomCard = availableCards[Math.floor(Math.random() * availableCards.length)];
            //             const resourceName = randomCard.querySelector('.resource-title').textContent;
            //             // Simulate deployment
            //             deployResource(randomCard.querySelector('.btn-resource'));
            //         }
            //     }
            // }, 30000); // Every 30 seconds
        });
    </script>
</body>
</html>
