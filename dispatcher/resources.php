<?php
$pageTitle = 'Resources Status Management';
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
// Require full login (including OTP verification) before loading page
require_role('dispatcher', 'dispatcher/resources.php');
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
$dispatcherBackupRequests = [];
$dispatcherAvailableUnits = [];

if (!function_exists('dispatcher_resources_table_exists')) {
    function dispatcher_resources_table_exists(PDO $pdo, string $tableName): bool {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    }
}

if (!function_exists('dispatcher_shared_resources_table')) {
    function dispatcher_shared_resources_table(PDO $pdo): ?string {
        if (dispatcher_resources_table_exists($pdo, 'resource_records')) {
            return 'resource_records';
        }
        if (dispatcher_resources_table_exists($pdo, 'admin_resources')) {
            return 'admin_resources';
        }
        return null;
    }
}

if (!function_exists('dispatcher_count_shared_resources')) {
    function dispatcher_count_shared_resources(PDO $pdo, string $tableName, string $category, array $statuses = []): int {
        $sql = "SELECT COUNT(*) AS c FROM `" . $tableName . "` WHERE category = ?";
        $params = [$category];

        if ($statuses !== []) {
            $placeholders = implode(',', array_fill(0, count($statuses), '?'));
            $sql .= " AND status IN ($placeholders)";
            foreach ($statuses as $status) {
                $params[] = $status;
            }
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int)($row['c'] ?? 0);
    }
}

// Fetch resource data from database
try {
    require_once $rootDir . '/includes/db.php';
    $pdo = get_db_connection();

    if ($pdo) {
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
            if ($qty < 1) {
                $qty = 1;
            }
            if (!isset($byType[$type])) {
                $type = 'other';
            }
            $byType[$type] += $qty;
        }

        $pendingSummary = 'vehicle=' . $byType['vehicle']
            . ', personnel=' . $byType['personnel']
            . ', equipment=' . $byType['equipment']
            . ', other=' . $byType['other'];

        $sharedResourcesTable = dispatcher_shared_resources_table($pdo);
        if ($sharedResourcesTable !== null) {
            $totalVehicles = dispatcher_count_shared_resources($pdo, $sharedResourcesTable, 'vehicles');
            $activePersonnel = dispatcher_count_shared_resources($pdo, $sharedResourcesTable, 'personnel');
            $equipmentItems = dispatcher_count_shared_resources($pdo, $sharedResourcesTable, 'equipment');

            $vehiclesAvailable = dispatcher_count_shared_resources($pdo, $sharedResourcesTable, 'vehicles', ['available']);
            $vehiclesInUse = dispatcher_count_shared_resources($pdo, $sharedResourcesTable, 'vehicles', ['in_use']);
            $vehiclesOffline = dispatcher_count_shared_resources($pdo, $sharedResourcesTable, 'vehicles', ['offline', 'maintenance']);

            $personnelAvailable = dispatcher_count_shared_resources($pdo, $sharedResourcesTable, 'personnel', ['available']);
            $personnelInUse = dispatcher_count_shared_resources($pdo, $sharedResourcesTable, 'personnel', ['in_use']);
            $personnelOffline = dispatcher_count_shared_resources($pdo, $sharedResourcesTable, 'personnel', ['offline', 'maintenance']);

            $equipmentAvailable = dispatcher_count_shared_resources($pdo, $sharedResourcesTable, 'equipment', ['available']);
            $equipmentInUse = dispatcher_count_shared_resources($pdo, $sharedResourcesTable, 'equipment', ['in_use']);
            $equipmentOffline = dispatcher_count_shared_resources($pdo, $sharedResourcesTable, 'equipment', ['offline', 'maintenance']);

            $resourceAiData = [
                'vehicles_total' => $totalVehicles,
                'vehicles_available' => $vehiclesAvailable,
                'vehicles_inuse' => $vehiclesInUse,
                'vehicles_offline' => $vehiclesOffline,
                'personnel_total' => $activePersonnel,
                'personnel_available' => $personnelAvailable,
                'personnel_inuse' => $personnelInUse,
                'personnel_offline' => $personnelOffline,
                'equipment_total' => $equipmentItems,
                'equipment_available' => $equipmentAvailable,
                'equipment_inuse' => $equipmentInUse,
                'equipment_offline' => $equipmentOffline,
                'active_incidents' => $activeIncidents,
                'pending_request_summary' => $pendingSummary,
            ];
        } else {
            $totalVehicles = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status != 'maintenance'")->fetch()['c'];
            $activePersonnel = (int)$pdo->query("SELECT COUNT(*) AS c FROM staff WHERE status IN ('available','on_duty')")->fetch()['c'];
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

        try {
            $requestStmt = $pdo->query("SELECT id, requestor, resource_name, date_requested, status, details FROM resource_requests ORDER BY date_requested DESC LIMIT 100");
            while ($requestRow = $requestStmt->fetch(PDO::FETCH_ASSOC)) {
                $details = json_decode((string)($requestRow['details'] ?? ''), true);
                if (!is_array($details) || (($details['request_kind'] ?? '') !== 'backup') || (int)($details['incident_id'] ?? 0) <= 0) {
                    continue;
                }
                if ((string)($requestRow['status'] ?? 'pending') !== 'pending') {
                    continue;
                }

                $selectedResources = [];
                if (!empty($details['selected_resources']) && is_array($details['selected_resources'])) {
                    foreach ($details['selected_resources'] as $item) {
                        if (!is_array($item)) {
                            continue;
                        }
                        $selectedResources[] = [
                            'id' => (int)($item['id'] ?? 0),
                            'code' => (string)($item['code'] ?? ''),
                            'name' => (string)($item['name'] ?? ''),
                            'category' => (string)($item['category'] ?? ''),
                            'location' => (string)($item['location'] ?? ''),
                            'assignment' => (string)($item['assignment'] ?? ''),
                            'notes' => (string)($item['notes'] ?? ''),
                        ];
                    }
                }

                $dispatcherBackupRequests[] = [
                    'id' => (int)($requestRow['id'] ?? 0),
                    'requestor' => (string)($requestRow['requestor'] ?? ''),
                    'resource_name' => (string)($requestRow['resource_name'] ?? ''),
                    'date_requested' => (string)($requestRow['date_requested'] ?? ''),
                    'status' => (string)($requestRow['status'] ?? 'pending'),
                    'type' => (string)($details['type'] ?? ''),
                    'quantity' => max(1, (int)($details['quantity'] ?? 1)),
                    'priority' => (string)($details['priority'] ?? ''),
                    'location' => (string)($details['location'] ?? ''),
                    'notes' => (string)($details['notes'] ?? ''),
                    'urgency' => (string)($details['urgency'] ?? ''),
                    'request_kind' => (string)($details['request_kind'] ?? ''),
                    'incident_id' => (int)($details['incident_id'] ?? 0),
                    'incident_code' => (string)($details['incident_code'] ?? ''),
                    'incident_title' => (string)($details['incident_title'] ?? ''),
                    'decision_reason' => (string)($details['decision_reason'] ?? ''),
                    'selected_resources' => $selectedResources,
                    'dispatched_units' => is_array($details['dispatched_units'] ?? null) ? $details['dispatched_units'] : [],
                    'dispatcher_name' => (string)($details['dispatcher_name'] ?? ''),
                    'dispatched_at' => (string)($details['dispatched_at'] ?? ''),
                ];
            }
        } catch (Throwable $e) {
            $dispatcherBackupRequests = [];
        }

        try {
            $unitsStmt = $pdo->query("SELECT id, identifier, unit_type, status, current_incident_id, last_status_at FROM units WHERE status = 'available' ORDER BY unit_type, identifier");
            $dispatcherAvailableUnits = $unitsStmt ? $unitsStmt->fetchAll(PDO::FETCH_ASSOC) : [];
        } catch (Throwable $e) {
            $dispatcherAvailableUnits = [];
        }
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
    <?php include $rootDir . '/includes/theme-init.php'; ?>
    <base href="../">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="css/cards.css">
    <link rel="stylesheet" href="css/resources.css">
</head>
<body>
    <!-- Include Sidebar Component -->
    <?php include $rootDir . '/includes/sidebar.php'; ?>

    <!-- Include Admin Header Component -->
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <!-- ===================================
       MAIN CONTENT - Emergency Resources Status
       =================================== -->
    <div class="main-content">
        <div class="main-container dispatcher-resources-page">
            <div class="resources-page-spacer"></div>

            <div class="resources-summary-grid">
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
                    <div class="overview-label">Total Personnel</div>
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
                <button class="quick-action-btn" onclick="openDispatcherRequestModal()">
                    <i class="fas fa-paper-plane"></i>
                    Request
                </button>
                <button class="quick-action-btn" onclick="emergencyAllocation()">
                    <i class="fas fa-exclamation-triangle"></i>
                    Emergency Allocation
                </button>
                <button class="quick-action-btn" onclick="resourceReport()">
                    <i class="fas fa-chart-bar"></i>
                    Generate Report
                </button>
            </div>
            </div>

            <!-- Resource Filters -->
            <div class="resource-filters resources-panel">
                <h2 class="section-heading">
                    <i class="fas fa-filter"></i>
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
                            <option value="inuse">Busy</option>
                            <option value="offline">Offline</option>
                        </select>
                    </div>
                    <div class="filter-group">
                        <label for="location-filter">Location</label>
                        <select id="location-filter">
                            <option value="">All Locations</option>
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
            <div class="resources-table-section resources-panel" style="margin-top:2rem;">
                <div class="panel-heading-row">
                    <h2 class="section-heading">
                        <i class="fas fa-table"></i>
                        All Resources
                    </h2>
                    <p class="section-heading-note">Unified live table for vehicles, personnel, and equipment.</p>
                </div>
                <div class="table-shell">
                <div style="overflow-x:auto;">
                <style>
                .resource-table {
                    width: 100%;
                    border-collapse: collapse;
                    min-width: 980px;
                }
                .resource-table th,
                .resource-table td {
                    padding: 0.78rem 0.75rem;
                    border-bottom: 1px solid #eef2f7;
                    text-align: left;
                    vertical-align: middle;
                }
                .resource-table th {
                    background: #f8fafc;
                    color: #334155;
                    font-size: 0.82rem;
                    text-transform: uppercase;
                    letter-spacing: 0.04em;
                    border-bottom: 1px solid #dfe4ea;
                }
                .resource-table td {
                    color: #1f2937;
                    font-size: 0.93rem;
                }
                .resource-table tbody tr:hover {
                    background: #f8fbff;
                }
                .resource-table.scrollable thead, .resource-table.scrollable tbody { display: block; }
                .resource-table.scrollable tbody { max-height: 380px; overflow-y: auto; }
                .resource-table.scrollable thead tr, .resource-table.scrollable tbody tr { display: table; width: 100%; table-layout: fixed; }
                .resource-table.scrollable thead th:nth-child(1),
                .resource-table.scrollable tbody td:nth-child(1) { width: 12%; }
                .resource-table.scrollable thead th:nth-child(2),
                .resource-table.scrollable tbody td:nth-child(2) { width: 20%; }
                .resource-table.scrollable thead th:nth-child(3),
                .resource-table.scrollable tbody td:nth-child(3) { width: 12%; }
                .resource-table.scrollable thead th:nth-child(4),
                .resource-table.scrollable tbody td:nth-child(4) { width: 12%; }
                .resource-table.scrollable thead th:nth-child(5),
                .resource-table.scrollable tbody td:nth-child(5) { width: 20%; }
                .resource-table.scrollable thead th:nth-child(6),
                .resource-table.scrollable tbody td:nth-child(6) { width: 12%; }
                .resource-table.scrollable thead th:nth-child(7),
                .resource-table.scrollable tbody td:nth-child(7) { width: 12%; }
                .name-cell strong {
                    display: block;
                    font-size: 0.93rem;
                    line-height: 1.35;
                }
                .name-cell span {
                    display: block;
                    color: #64748b;
                    font-size: 0.8rem;
                    margin-top: 2px;
                    line-height: 1.35;
                }
                .resource-meta-note {
                    display: inline-block;
                    color: #64748b;
                    font-size: 0.8rem;
                    margin-top: 2px;
                    line-height: 1.35;
                }
                .resource-status-available {
                    background: #dcfce7;
                    color: #166534;
                    font-weight: 700;
                    border-radius: 999px;
                    padding: 0.3rem 0.55rem;
                    font-size: 0.76rem;
                    text-transform: uppercase;
                    letter-spacing: 0.03em;
                    white-space: nowrap;
                    display: inline-block;
                }
                .resource-status-inuse {
                    background: #fef3c7;
                    color: #92400e;
                    font-weight: 700;
                    border-radius: 999px;
                    padding: 0.3rem 0.55rem;
                    font-size: 0.76rem;
                    text-transform: uppercase;
                    letter-spacing: 0.03em;
                    white-space: nowrap;
                    display: inline-block;
                }
                .resource-status-offline {
                    background: #e5e7eb;
                    color: #374151;
                    font-weight: 700;
                    border-radius: 999px;
                    padding: 0.3rem 0.55rem;
                    font-size: 0.76rem;
                    text-transform: uppercase;
                    letter-spacing: 0.03em;
                    white-space: nowrap;
                    display: inline-block;
                }
                .resource-action-btn {
                    border: 1px solid #d1d5db;
                    border-radius: 8px;
                    width: 32px;
                    height: 32px;
                    padding: 0;
                    font-weight: 600;
                    font-size: 14px;
                    cursor: pointer;
                    transition: all 0.2s ease;
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    background: #fff;
                    color: #111827;
                }
                .resource-action-btn i { margin: 0; }
                .resource-action-btn:hover {
                    transform: translateY(-1px);
                    background: #f8fafc;
                }
                .resource-action-btn.details:hover {
                    background: #0f766e;
                    border-color: #0f766e;
                    color: #fff;
                }
                .actions-inline {
                    display: flex;
                    gap: 0.38rem;
                    align-items: center;
                    flex-wrap: wrap;
                }
                </style>
                <table class="resource-table scrollable">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Resource</th>
                            <th>Category</th>
                            <th>Status</th>
                            <th>Location / Assignment</th>
                            <th>Last Updated</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody id="resource-list-dynamic">
                        <!-- Table will be rendered here by JS -->
                    </tbody>
                </table>
                </div>
                </div>
                <script>
                // Resource data loaded from backend
                let RESOURCES = [];

                async function loadResources() {
                    const container = document.getElementById('resource-list-dynamic');
                    if (container) container.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888;">Loading resources…</td></tr>';
                    try {
                        const res = await fetch('api/resources_combined.php');
                        const data = await res.json();
                        RESOURCES = (data.ok && Array.isArray(data.items)) ? data.items : [];
                        refreshLocationFilterOptions();
                        renderDynamicResources();
                    } catch (e) {
                        RESOURCES = [];
                        refreshLocationFilterOptions();
                        renderDynamicResources();
                    }
                }

                // Filter elements
                const typeFilter = document.getElementById('resource-type');
                const statusFilter = document.getElementById('status-filter');
                const locationFilter = document.getElementById('location-filter');
                const searchInput = document.getElementById('search-resource');

                function refreshLocationFilterOptions() {
                    if (!locationFilter) return;

                    const previousValue = locationFilter.value || '';
                    const locations = Array.from(new Set(
                        RESOURCES
                            .map((item) => String(item.location || '').trim())
                            .filter(Boolean)
                    )).sort((a, b) => a.localeCompare(b));

                    locationFilter.innerHTML = '<option value="">All Locations</option>' +
                        locations.map((location) => `<option value="${escapeAttrValue(location)}">${escapeHtml(location)}</option>`).join('');

                    if (previousValue && locations.includes(previousValue)) {
                        locationFilter.value = previousValue;
                    }
                }

                function passFilters(r) {
                    const typeValue = (typeFilter.value || '').toLowerCase();
                    const statusValue = (statusFilter.value || '').toLowerCase();
                    const locationValue = (locationFilter.value || '').toLowerCase();
                    const searchValue = (searchInput.value || '').toLowerCase();

                    if (typeValue && (r.type || '').toLowerCase() !== typeValue) return false;
                    if (statusValue && (r.status || '').toLowerCase() !== statusValue) return false;
                    if (locationValue && (r.location || '').toLowerCase() !== locationValue) return false;
                    if (searchValue) {
                        const hay = [r.code, r.name, r.location, r.role, r.details, r.notes, r.assignment]
                            .map(v => (v || '').toString().toLowerCase()).join(' ');
                        if (!hay.includes(searchValue)) return false;
                    }
                    return true;
                }

                function formatResourceType(type) {
                    if (type === 'vehicles') return 'Vehicles';
                    if (type === 'personnel') return 'Personnel';
                    if (type === 'equipment') return 'Equipment';
                    return type || 'Resource';
                }

                function formatResourceStatus(status) {
                    if (status === 'inuse') return 'Busy';
                    if (status === 'offline') return 'Offline';
                    return 'Available';
                }

                function formatUpdatedAt(value) {
                    const text = String(value || '').trim();
                    if (!text) return 'N/A';
                    const date = new Date(text.replace(' ', 'T'));
                    if (Number.isNaN(date.getTime())) return text;
                    return date.toLocaleString();
                }

                function formatRowSubtitle(resource) {
                    const subtitle = String(resource.role || '').trim();
                    if (subtitle) return subtitle;
                    const details = String(resource.details || '').trim();
                    return details || 'No details';
                }

                function formatAssignmentDisplay(resource) {
                    const details = String(resource.details || '').trim();
                    if (details) return details;
                    const assignment = String(resource.assignment || '').trim();
                    return assignment || 'N/A';
                }

                function resourceRowHtml(r) {
                    let statusClass = r.status === 'available' ? 'resource-status-available' : (r.status === 'inuse' ? 'resource-status-inuse' : 'resource-status-offline');
                    const resourceName = String(r.name || '');
                    const resourceCode = String(r.code || r.identifier || `RESOURCE-${r.id || ''}`).trim();
                    const subtitle = formatRowSubtitle(r);
                    const assignmentLine = formatAssignmentDisplay(r);
                    const safeResourceName = escapeHtml(resourceName);
                    const safeResourceCode = escapeHtml(resourceCode);
                    const safeSubtitle = escapeHtml(subtitle);
                    const safeAssignmentLine = escapeHtml(assignmentLine);
                    const safeLocation = escapeHtml(String(r.location || ''));
                    const safeTypeLabel = escapeHtml(formatResourceType(r.type));
                    const safeStatusLabel = escapeHtml(formatResourceStatus(r.status));
                    const safeUpdatedAt = escapeHtml(formatUpdatedAt(r.updatedAt));
                    const unitIdentifier = r.type === 'vehicles'
                        ? String(r.identifier || r.code || r.name || '')
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
                    return `<tr data-type=\"${r.type}\" data-status=\"${r.status}\" data-location=\"${escapeAttrValue(r.location || '')}\" data-resource-id=\"${escapeAttrValue(r.id)}\" data-resource-name=\"${escapeAttrValue(resourceName)}\" data-resource-code=\"${escapeAttrValue(resourceCode)}\" data-unit-identifier=\"${escapeAttrValue(unitIdentifier)}\" data-resource-source=\"${escapeAttrValue(r.source || '')}\" data-resource-details=\"${escapeAttrValue(r.details || '')}\" data-resource-role=\"${escapeAttrValue(r.role || '')}\" data-resource-updated=\"${escapeAttrValue(r.updatedAt || '')}\" data-resource-assignment=\"${escapeAttrValue(r.assignment || '')}\" data-resource-notes=\"${escapeAttrValue(r.notes || '')}\">\n`+
                        `<td>${safeResourceCode}</td>`+
                        `<td class=\"name-cell resource-title\"><strong>${safeResourceName}</strong><span>${safeSubtitle}</span></td>`+
                        `<td>${safeTypeLabel}</td>`+
                        `<td><span class=\"${statusClass}\">${safeStatusLabel}</span></td>`+
                        `<td class=\"detail-value\">${safeLocation}<br><span class=\"resource-meta-note\">${safeAssignmentLine}</span></td>`+
                        `<td>${safeUpdatedAt}</td>`+
                        `<td>${actionsHtml}</td>`+
                    `</tr>`;
                }

                function renderDynamicResources() {
                    const container = document.getElementById('resource-list-dynamic');
                    if (!container) return;
                    const filtered = RESOURCES.filter(passFilters);
                    if (!filtered.length) {
                        container.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#888;">No resources found.</td></tr>';
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
            <div class="resource-requests-table-section resources-panel" style="margin-top:2rem;">
                <div class="panel-heading-row">
                    <h2 class="section-heading">
                        <i class="fas fa-clipboard-list"></i>
                        Request Queue
                    </h2>
                    <p class="section-heading-note">Review responder requests and approve or reject without changing the workflow.</p>
                </div>
                <div class="table-shell">
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
                .status-fulfilled { background: #0f766e; }
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
                                    $requestKind = is_array($details) ? (string)($details['request_kind'] ?? '') : '';
                                    $incidentId = is_array($details) ? (int)($details['incident_id'] ?? 0) : 0;
                                    $incidentCode = is_array($details) ? (string)($details['incident_code'] ?? '') : '';
                                    $incidentTitle = is_array($details) ? (string)($details['incident_title'] ?? '') : '';
                                    $selectedResources = is_array($details) && isset($details['selected_resources']) && is_array($details['selected_resources'])
                                        ? $details['selected_resources']
                                        : [];
                                    $dispatcherName = is_array($details) ? (string)($details['dispatcher_name'] ?? '') : '';
                                    $dispatchedAt = is_array($details) ? (string)($details['dispatched_at'] ?? '') : '';
                                    $dispatchedUnits = is_array($details) && isset($details['dispatched_units']) && is_array($details['dispatched_units'])
                                        ? $details['dispatched_units']
                                        : [];
                                    $statusClass = 'status-badge status-' . htmlspecialchars($status);
                                    echo '<tr data-id="' . (int)$row['id'] . '"'
                                        . ' data-requestor="' . htmlspecialchars((string)($row['requestor'] ?? ''), ENT_QUOTES) . '"'
                                        . ' data-resource-name="' . htmlspecialchars((string)($row['resource_name'] ?? ''), ENT_QUOTES) . '"'
                                        . ' data-notes="' . htmlspecialchars($notes, ENT_QUOTES) . '"'
                                        . ' data-decision-reason="' . htmlspecialchars($decision, ENT_QUOTES) . '"'
                                        . ' data-status="' . htmlspecialchars($status, ENT_QUOTES) . '"'
                                        . ' data-priority="' . htmlspecialchars((string)$priority, ENT_QUOTES) . '"'
                                        . ' data-urgency="' . htmlspecialchars((string)$urgency, ENT_QUOTES) . '"'
                                        . ' data-location="' . htmlspecialchars((string)$location, ENT_QUOTES) . '"'
                                        . ' data-request-kind="' . htmlspecialchars($requestKind, ENT_QUOTES) . '"'
                                        . ' data-incident-id="' . $incidentId . '"'
                                        . ' data-incident-code="' . htmlspecialchars($incidentCode, ENT_QUOTES) . '"'
                                        . ' data-incident-title="' . htmlspecialchars($incidentTitle, ENT_QUOTES) . '"'
                                        . ' data-selected-resources="' . htmlspecialchars(json_encode($selectedResources, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '"'
                                        . ' data-dispatched-units="' . htmlspecialchars(json_encode($dispatchedUnits, JSON_UNESCAPED_UNICODE), ENT_QUOTES) . '"'
                                        . ' data-dispatcher-name="' . htmlspecialchars($dispatcherName, ENT_QUOTES) . '"'
                                        . ' data-dispatched-at="' . htmlspecialchars($dispatchedAt, ENT_QUOTES) . '">';
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
            </div>

            <!-- AI-Powered Resource Gap Recommendations -->
            <div class="ai-predictive-section resources-panel">
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
                        include $rootDir . '/includes/gemini_helper.php';
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

    <div class="resource-request-modal" id="dispatcherRequestModal">
        <div class="modal-content dispatcher-request-modal-content">
            <div class="modal-header">
                <h3>Admin Backup Requests</h3>
                <button class="modal-close" onclick="closeDispatcherRequestModal()">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <form id="dispatcherRequestForm" onsubmit="submitDispatcherRequest(event)">
                <div class="modal-body dispatcher-request-modal-body">
                    <div class="dispatcher-request-layout">
                        <section class="dispatcher-request-section">
                            <div class="dispatcher-request-section-head">
                                <div>
                                    <strong>Pending Backup Requests</strong>
                                    <span>Select the request sent from admin that needs responder backup.</span>
                                </div>
                                <span class="dispatcher-request-chip"><span id="dispatcherPendingRequestCount"><?php echo count($dispatcherBackupRequests); ?></span> request(s)</span>
                            </div>
                            <div class="dispatcher-request-table-wrap">
                                <table class="dispatcher-request-table">
                                    <thead>
                                        <tr>
                                            <th>Pick</th>
                                            <th>Request</th>
                                            <th>Incident</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dispatcherBackupRequestList">
                                        <?php if ($dispatcherBackupRequests === []): ?>
                                            <tr>
                                                <td colspan="4" class="dispatcher-request-empty">No admin backup requests available.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($dispatcherBackupRequests as $request): ?>
                                                <?php
                                                $requestPayload = htmlspecialchars(json_encode($request, JSON_UNESCAPED_UNICODE), ENT_QUOTES);
                                                $requestStatusClass = 'status-badge status-' . htmlspecialchars((string)$request['status']);
                                                $incidentLabel = trim((string)($request['incident_code'] ?: ('Incident #' . (int)$request['incident_id'])));
                                                ?>
                                                <tr data-dispatcher-request-row data-request-id="<?php echo (int)$request['id']; ?>" data-request-json="<?php echo $requestPayload; ?>">
                                                    <td>
                                                        <button type="button" class="request-action-btn dispatcher-select-btn" data-select-dispatcher-request="<?php echo (int)$request['id']; ?>">
                                                            <i class="fas fa-check"></i>
                                                        </button>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars((string)$request['resource_name']); ?></strong><br>
                                                        <span class="dispatcher-request-subtle"><?php echo htmlspecialchars((string)$request['requestor']); ?> | Qty: <?php echo (int)$request['quantity']; ?></span>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars($incidentLabel); ?></strong><br>
                                                        <span class="dispatcher-request-subtle"><?php echo htmlspecialchars((string)$request['incident_title']); ?></span>
                                                    </td>
                                                    <td><span class="<?php echo $requestStatusClass; ?>"><?php echo htmlspecialchars(ucfirst((string)$request['status'])); ?></span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </section>

                        <section class="dispatcher-request-section">
                            <div class="dispatcher-request-section-head">
                                <div>
                                    <strong>Request Details</strong>
                                    <span>Incident information and admin notes will appear here.</span>
                                </div>
                            </div>
                            <div class="dispatcher-request-details" id="dispatcherRequestDetails">
                                Select an admin backup request to review its incident details and requested support.
                            </div>
                        </section>

                        <section class="dispatcher-request-section">
                            <div class="dispatcher-request-section-head">
                                <div>
                                    <strong>Available Units</strong>
                                    <span>Choose one or more available units to send as responder backup.</span>
                                </div>
                                <span class="dispatcher-request-chip"><span id="dispatcherSelectedUnitCount">0</span> selected</span>
                            </div>
                            <div class="dispatcher-request-table-wrap dispatcher-request-unit-wrap">
                                <table class="dispatcher-request-table">
                                    <thead>
                                        <tr>
                                            <th>Add</th>
                                            <th>Unit</th>
                                            <th>Type</th>
                                            <th>Status</th>
                                        </tr>
                                    </thead>
                                    <tbody id="dispatcherAvailableUnitsBody">
                                        <?php if ($dispatcherAvailableUnits === []): ?>
                                            <tr>
                                                <td colspan="4" class="dispatcher-request-empty">No available units found.</td>
                                            </tr>
                                        <?php else: ?>
                                            <?php foreach ($dispatcherAvailableUnits as $unit): ?>
                                                <tr data-unit-id="<?php echo (int)$unit['id']; ?>">
                                                    <td>
                                                        <button
                                                            type="button"
                                                            class="request-action-btn dispatcher-unit-btn"
                                                            data-toggle-dispatcher-unit="<?php echo (int)$unit['id']; ?>"
                                                            data-unit-label="<?php echo htmlspecialchars((string)$unit['identifier'], ENT_QUOTES); ?>"
                                                        >
                                                            <i class="fas fa-plus"></i>
                                                        </button>
                                                    </td>
                                                    <td>
                                                        <strong><?php echo htmlspecialchars((string)$unit['identifier']); ?></strong><br>
                                                        <span class="dispatcher-request-subtle">Unit ID: <?php echo (int)$unit['id']; ?></span>
                                                    </td>
                                                    <td><?php echo htmlspecialchars(ucfirst((string)$unit['unit_type'])); ?></td>
                                                    <td><span class="status-badge status-approved">Available</span></td>
                                                </tr>
                                            <?php endforeach; ?>
                                        <?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="dispatcher-selected-units" id="dispatcherSelectedUnits">
                                Select available units using the + button.
                            </div>
                        </section>

                        <section class="dispatcher-request-section">
                            <div class="dispatcher-request-section-head">
                                <div>
                                    <strong>Dispatch Form</strong>
                                    <span>The incident ID is filled automatically from the selected admin request.</span>
                                </div>
                            </div>
                            <input type="hidden" id="dispatcherRequestIdInput" name="request_id" value="">
                            <div class="dispatcher-request-form-grid">
                                <div class="form-group">
                                    <label for="dispatcherIncidentIdInput">Incident ID</label>
                                    <input type="text" id="dispatcherIncidentIdInput" name="incident_id" readonly>
                                </div>
                                <div class="form-group">
                                    <label for="dispatcherIncidentCodeInput">Incident Reference</label>
                                    <input type="text" id="dispatcherIncidentCodeInput" readonly>
                                </div>
                                <div class="form-group full">
                                    <label for="dispatcherDispatchNotesInput">Dispatcher Notes</label>
                                    <textarea id="dispatcherDispatchNotesInput" name="notes" placeholder="Optional note for the responders being sent to this backup incident."></textarea>
                                </div>
                            </div>
                        </section>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" onclick="closeDispatcherRequestModal()">Cancel</button>
                    <button type="submit" class="btn-submit" id="dispatcherRequestSubmitBtn">Send Request to Responders</button>
                </div>
            </form>
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
            const resourceName = row.getAttribute('data-resource-name') || 'Resource';
            const resourceId = row.getAttribute('data-resource-id');
            const resourceType = row.getAttribute('data-type');
            const resourceSource = row.getAttribute('data-resource-source') || '';
            const resourceLocation = row.querySelector('.detail-value') ? row.querySelector('.detail-value').textContent.trim() : '';
            if (!resourceId) { showNotification('Missing resource id', 'error'); return; }
            openDeployModal({
                id: Number(resourceId),
                type: resourceType || '',
                source: resourceSource,
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
                source: pendingDeployContext.source || '',
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
                const params = new URLSearchParams();
                params.set('track_unit', unitIdentifier);
                params.set('resource_id', resourceId);
                params.set('from_resources', '1');
                window.location.href = 'dispatcher/dispatch.php?' + params.toString();
            } else {
                showNotification(`Tracking ${resourceName}...`, 'info');
                window.location.href = 'dispatcher/dispatch.php';
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
            const resourceName = row.getAttribute('data-resource-name') || 'Resource';
            const resourceCode = row.getAttribute('data-resource-code') || 'N/A';
            const resourceType = row.getAttribute('data-type');
            const resourceStatus = row.getAttribute('data-status') || '';
            const resourceLocation = row.getAttribute('data-location') || 'N/A';
            const resourceRole = row.getAttribute('data-resource-role') || '';
            const resourceDetailsText = row.getAttribute('data-resource-details') || row.getAttribute('data-resource-notes') || 'No details provided.';
            const resourceUpdatedAt = row.getAttribute('data-resource-updated') || '';

            const lines = [
                `Resource ID: ${resourceCode}`,
                `Resource: ${resourceName}`,
                `Category: ${formatResourceType(resourceType)}`,
                `Status: ${formatResourceStatus(resourceStatus)}`,
                `Location: ${resourceLocation || 'N/A'}`
            ];

            if (resourceRole) {
                lines.push(`Summary: ${resourceRole}`);
            }

            if (resourceUpdatedAt) {
                lines.push(`Last Updated: ${formatUpdatedAt(resourceUpdatedAt)}`);
            }

            lines.push('');
            lines.push('Details:');
            lines.push(resourceDetailsText);

            alert(lines.join('\n'));
        }

        // Personnel management functions
        function assignPersonnel(button) {
            const row = button.closest('tr');
            const personnelName = row ? (row.getAttribute('data-resource-name') || 'Personnel') : 'Personnel';
            const assignment = prompt(`Assign ${personnelName} to which incident/unit?`);
            if (assignment) {
                button.classList.add('active');
                showNotification(`${personnelName} assigned to ${assignment}`, 'success');
            }
        }

        function contactPersonnel(button) {
            const row = button.closest('tr');
            const personnelName = row ? (row.getAttribute('data-resource-name') || 'Personnel') : 'Personnel';
            if (confirm(`Call ${personnelName}?`)) {
                showNotification(`Calling ${personnelName}...`, 'info');
            }
        }

        function personnelSchedule(button) {
            const row = button.closest('tr');
            const personnelName = row ? (row.getAttribute('data-resource-name') || 'Personnel') : 'Personnel';
            alert(`${personnelName} Schedule:\n\n• Monday-Friday: Day Shift\n• Weekends: On-call rotation\n• Next shift: Tomorrow\n• Vacation: Pending`);
        }

        // Equipment management functions
        function assignEquipment(button) {
            const row = button.closest('tr');
            const equipmentName = row ? (row.getAttribute('data-resource-name') || 'Equipment') : 'Equipment';
            const assignment = prompt(`Assign ${equipmentName} to which unit/personnel?`);
            if (assignment) {
                showNotification(`${equipmentName} assigned to ${assignment}`, 'success');
            }
        }

        function checkEquipment(button) {
            const row = button.closest('tr');
            const equipmentName = row ? (row.getAttribute('data-resource-name') || 'Equipment') : 'Equipment';
            showNotification(`${equipmentName} status check: All systems operational`, 'success');
        }

        function calibrateEquipment(button) {
            const row = button.closest('tr');
            const equipmentName = row ? (row.getAttribute('data-resource-name') || 'Equipment') : 'Equipment';
            if (confirm(`Calibrate ${equipmentName}? This may take several minutes.`)) {
                showNotification(`Calibration started for ${equipmentName}`, 'info');
            }
        }

        const DISPATCHER_REQUEST_ENDPOINT = 'api/resource_request_dispatch.php';
        const DISPATCHER_REQUEST_FEED_ENDPOINT = 'api/dispatcher_backup_requests.php';
        const DISPATCHER_REQUESTOR = <?php echo json_encode($requestor_name ?: 'Dispatcher', JSON_UNESCAPED_UNICODE); ?>;
        let dispatcherBackupRequestsData = <?php echo json_encode(array_values($dispatcherBackupRequests), JSON_UNESCAPED_UNICODE); ?>;
        let dispatcherAvailableUnitsData = <?php echo json_encode(array_values($dispatcherAvailableUnits), JSON_UNESCAPED_UNICODE); ?>;
        let selectedDispatcherRequest = null;
        const selectedDispatcherUnitIds = new Set();

        function parseJsonAttribute(value, fallback) {
            try {
                const parsed = JSON.parse(value || '');
                return parsed ?? fallback;
            } catch (err) {
                return fallback;
            }
        }

        function getDispatcherRequestRows() {
            return Array.from(document.querySelectorAll('[data-dispatcher-request-row]'));
        }

        function normalizeDispatcherRequest(raw) {
            if (!raw || typeof raw !== 'object') {
                return null;
            }
            return {
                id: Number(raw.id) || 0,
                requestor: String(raw.requestor || ''),
                resource_name: String(raw.resource_name || ''),
                date_requested: String(raw.date_requested || ''),
                status: String(raw.status || 'pending'),
                type: String(raw.type || ''),
                quantity: Math.max(1, Number(raw.quantity) || 1),
                priority: String(raw.priority || ''),
                location: String(raw.location || ''),
                notes: String(raw.notes || ''),
                urgency: String(raw.urgency || ''),
                request_kind: String(raw.request_kind || 'backup'),
                incident_id: Number(raw.incident_id) || 0,
                incident_code: String(raw.incident_code || ''),
                incident_title: String(raw.incident_title || ''),
                decision_reason: String(raw.decision_reason || ''),
                selected_resources: Array.isArray(raw.selected_resources) ? raw.selected_resources : [],
                dispatched_units: Array.isArray(raw.dispatched_units) ? raw.dispatched_units : [],
                dispatcher_name: String(raw.dispatcher_name || ''),
                dispatched_at: String(raw.dispatched_at || '')
            };
        }

        function normalizeDispatcherUnit(raw) {
            if (!raw || typeof raw !== 'object') {
                return null;
            }
            return {
                id: Number(raw.id) || 0,
                identifier: String(raw.identifier || ''),
                unit_type: String(raw.unit_type || ''),
                status: String(raw.status || ''),
                current_incident_id: raw.current_incident_id == null ? null : (Number(raw.current_incident_id) || null),
                last_status_at: String(raw.last_status_at || '')
            };
        }

        function findDispatcherRequestPayload(requestId) {
            return dispatcherBackupRequestsData.find((item) => item && Number(item.id) === Number(requestId)) || null;
        }

        function renderDispatcherRequestList() {
            const tbody = document.getElementById('dispatcherBackupRequestList');
            const countEl = document.getElementById('dispatcherPendingRequestCount');
            if (!tbody) return;
            if (countEl) {
                countEl.textContent = String(Array.isArray(dispatcherBackupRequestsData) ? dispatcherBackupRequestsData.length : 0);
            }

            if (!Array.isArray(dispatcherBackupRequestsData) || dispatcherBackupRequestsData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="dispatcher-request-empty">No admin backup requests available.</td></tr>';
                return;
            }

            tbody.innerHTML = dispatcherBackupRequestsData.map((request) => {
                const payload = escapeAttrValue(JSON.stringify(request));
                const incidentLabel = request.incident_code
                    ? request.incident_code
                    : `Incident #${request.incident_id || ''}`;
                const statusText = (request.status || 'pending').charAt(0).toUpperCase() + (request.status || 'pending').slice(1);
                return `
                    <tr data-dispatcher-request-row data-request-id="${request.id}" data-request-json="${payload}">
                        <td>
                            <button type="button" class="request-action-btn dispatcher-select-btn" data-select-dispatcher-request="${request.id}">
                                <i class="fas fa-check"></i>
                            </button>
                        </td>
                        <td>
                            <strong>${escapeHtml(request.resource_name || 'Backup Request')}</strong><br>
                            <span class="dispatcher-request-subtle">${escapeHtml(request.requestor || 'Admin')} | Qty: ${escapeHtml(request.quantity || 1)}</span>
                        </td>
                        <td>
                            <strong>${escapeHtml(incidentLabel)}</strong><br>
                            <span class="dispatcher-request-subtle">${escapeHtml(request.incident_title || '')}</span>
                        </td>
                        <td><span class="status-badge status-${escapeHtml(request.status || 'pending')}">${escapeHtml(statusText)}</span></td>
                    </tr>
                `;
            }).join('');
        }

        function renderDispatcherUnitList() {
            const tbody = document.getElementById('dispatcherAvailableUnitsBody');
            if (!tbody) return;

            if (!Array.isArray(dispatcherAvailableUnitsData) || dispatcherAvailableUnitsData.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" class="dispatcher-request-empty">No available units found.</td></tr>';
                return;
            }

            tbody.innerHTML = dispatcherAvailableUnitsData.map((unit) => `
                <tr data-unit-id="${unit.id}">
                    <td>
                        <button
                            type="button"
                            class="request-action-btn dispatcher-unit-btn"
                            data-toggle-dispatcher-unit="${unit.id}"
                            data-unit-label="${escapeAttrValue(unit.identifier || `Unit #${unit.id}`)}"
                        >
                            <i class="fas fa-plus"></i>
                        </button>
                    </td>
                    <td>
                        <strong>${escapeHtml(unit.identifier || `Unit #${unit.id}`)}</strong><br>
                        <span class="dispatcher-request-subtle">Unit ID: ${escapeHtml(unit.id || '')}</span>
                    </td>
                    <td>${escapeHtml((unit.unit_type || '').charAt(0).toUpperCase() + (unit.unit_type || '').slice(1))}</td>
                    <td><span class="status-badge status-approved">Available</span></td>
                </tr>
            `).join('');
        }

        async function loadDispatcherBackupData() {
            const response = await fetch(DISPATCHER_REQUEST_FEED_ENDPOINT, {
                cache: 'no-store',
                credentials: 'same-origin'
            });
            const data = await response.json();
            if (!response.ok || !data || !data.success) {
                throw new Error((data && data.error) ? String(data.error) : 'Unable to load backup requests');
            }

            dispatcherBackupRequestsData = Array.isArray(data.requests)
                ? data.requests.map(normalizeDispatcherRequest).filter(Boolean)
                : [];
            dispatcherAvailableUnitsData = Array.isArray(data.units)
                ? data.units.map(normalizeDispatcherUnit).filter(Boolean)
                : [];

            renderDispatcherRequestList();
            renderDispatcherUnitList();
        }

        function formatRequestDate(value) {
            if (!value) return 'Not recorded';
            const parsed = new Date(String(value).replace(' ', 'T'));
            if (Number.isNaN(parsed.getTime())) return String(value);
            return parsed.toLocaleString();
        }

        function renderDispatcherRequestDetails() {
            const detailsEl = document.getElementById('dispatcherRequestDetails');
            const incidentIdInput = document.getElementById('dispatcherIncidentIdInput');
            const incidentCodeInput = document.getElementById('dispatcherIncidentCodeInput');
            const requestIdInput = document.getElementById('dispatcherRequestIdInput');
            const submitBtn = document.getElementById('dispatcherRequestSubmitBtn');
            if (!detailsEl || !incidentIdInput || !incidentCodeInput || !requestIdInput || !submitBtn) {
                return;
            }

            if (!selectedDispatcherRequest) {
                detailsEl.innerHTML = 'Select an admin backup request to review its incident details and requested support.';
                incidentIdInput.value = '';
                incidentCodeInput.value = '';
                requestIdInput.value = '';
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.65';
                return;
            }

            const selectedResources = Array.isArray(selectedDispatcherRequest.selected_resources)
                ? selectedDispatcherRequest.selected_resources
                : [];
            const dispatchedUnits = Array.isArray(selectedDispatcherRequest.dispatched_units)
                ? selectedDispatcherRequest.dispatched_units
                : [];
            const incidentLabel = selectedDispatcherRequest.incident_code
                ? `${selectedDispatcherRequest.incident_code} (#${selectedDispatcherRequest.incident_id || ''})`
                : `Incident #${selectedDispatcherRequest.incident_id || ''}`;
            const selectedResourceHtml = selectedResources.length
                ? selectedResources.map((item) => `
                    <li>
                        <strong>${escapeHtml(item.code || `#${item.id || ''}`)}</strong> - ${escapeHtml(item.name || 'Resource')}
                        <span>${escapeHtml(item.category || 'Uncategorized')} | ${escapeHtml(item.location || 'No location')}</span>
                    </li>
                `).join('')
                : '<li><strong>No resource list</strong><span>Admin request does not include selected resources.</span></li>';
            const dispatchedUnitHtml = dispatchedUnits.length
                ? dispatchedUnits.map((item) => `
                    <li>
                        <strong>${escapeHtml(item.identifier || `Unit #${item.id || ''}`)}</strong>
                        <span>${escapeHtml(item.unit_type || 'Unit')}</span>
                    </li>
                `).join('')
                : '';

            detailsEl.innerHTML = `
                <div class="dispatcher-request-details-grid">
                    <div><strong>Requestor</strong><span>${escapeHtml(selectedDispatcherRequest.requestor || 'Admin')}</span></div>
                    <div><strong>Status</strong><span>${escapeHtml((selectedDispatcherRequest.status || 'pending').replace(/^\w/, (c) => c.toUpperCase()))}</span></div>
                    <div><strong>Incident</strong><span>${escapeHtml(incidentLabel)}</span></div>
                    <div><strong>Requested At</strong><span>${escapeHtml(formatRequestDate(selectedDispatcherRequest.date_requested || ''))}</span></div>
                    <div><strong>Priority</strong><span>${escapeHtml(selectedDispatcherRequest.priority || 'N/A')}</span></div>
                    <div><strong>Urgency</strong><span>${escapeHtml(selectedDispatcherRequest.urgency || 'N/A')}</span></div>
                    <div class="full"><strong>Incident Title</strong><span>${escapeHtml(selectedDispatcherRequest.incident_title || 'No title')}</span></div>
                    <div class="full"><strong>Location</strong><span>${escapeHtml(selectedDispatcherRequest.location || 'No location')}</span></div>
                    <div class="full"><strong>Admin Notes</strong><span>${escapeHtml(selectedDispatcherRequest.notes || 'No notes provided.')}</span></div>
                    <div class="full"><strong>Requested Resources</strong><ul class="dispatcher-request-resource-list">${selectedResourceHtml}</ul></div>
                    ${selectedDispatcherRequest.decision_reason ? `<div class="full"><strong>Decision Reason</strong><span>${escapeHtml(selectedDispatcherRequest.decision_reason)}</span></div>` : ''}
                    ${dispatchedUnitHtml ? `<div class="full"><strong>Already Sent Units</strong><ul class="dispatcher-request-resource-list">${dispatchedUnitHtml}</ul></div>` : ''}
                </div>
            `;

            incidentIdInput.value = selectedDispatcherRequest.incident_id ? String(selectedDispatcherRequest.incident_id) : '';
            incidentCodeInput.value = selectedDispatcherRequest.incident_code || '';
            requestIdInput.value = selectedDispatcherRequest.id ? String(selectedDispatcherRequest.id) : '';
            syncDispatcherSubmitState();
        }

        function renderDispatcherSelectedUnits() {
            const selectedUnitsEl = document.getElementById('dispatcherSelectedUnits');
            const countEl = document.getElementById('dispatcherSelectedUnitCount');
            const buttons = Array.from(document.querySelectorAll('[data-toggle-dispatcher-unit]'));

            buttons.forEach((button) => {
                const unitId = Number(button.getAttribute('data-toggle-dispatcher-unit') || '0');
                const isSelected = selectedDispatcherUnitIds.has(unitId);
                button.classList.toggle('dispatcher-unit-btn-selected', isSelected);
                button.innerHTML = isSelected
                    ? "<i class='fas fa-check'></i>"
                    : "<i class='fas fa-plus'></i>";
            });

            if (countEl) {
                countEl.textContent = String(selectedDispatcherUnitIds.size);
            }

            if (!selectedUnitsEl) {
                syncDispatcherSubmitState();
                return;
            }

            if (selectedDispatcherUnitIds.size === 0) {
                selectedUnitsEl.innerHTML = 'Select available units using the + button.';
                syncDispatcherSubmitState();
                return;
            }

            const items = buttons
                .filter((button) => selectedDispatcherUnitIds.has(Number(button.getAttribute('data-toggle-dispatcher-unit') || '0')))
                .map((button) => {
                    const unitId = Number(button.getAttribute('data-toggle-dispatcher-unit') || '0');
                    const label = button.getAttribute('data-unit-label') || `Unit #${unitId}`;
                    return `
                        <span class="dispatcher-selected-chip">
                            <span>${escapeHtml(label)}</span>
                            <button type="button" data-toggle-dispatcher-unit="${unitId}" aria-label="Remove ${escapeHtml(label)}">
                                <i class="fas fa-times"></i>
                            </button>
                        </span>
                    `;
                });

            selectedUnitsEl.innerHTML = items.join('');
            syncDispatcherSubmitState();
        }

        function syncDispatcherSubmitState() {
            const submitBtn = document.getElementById('dispatcherRequestSubmitBtn');
            if (!submitBtn) return;
            const isFulfilled = selectedDispatcherRequest && String(selectedDispatcherRequest.status || '') === 'fulfilled';
            const canSubmit = !!selectedDispatcherRequest
                && !isFulfilled
                && !!selectedDispatcherRequest.incident_id
                && selectedDispatcherUnitIds.size > 0;
            submitBtn.disabled = !canSubmit;
            submitBtn.style.opacity = canSubmit ? '1' : '0.65';
        }

        function selectDispatcherRequest(requestId) {
            selectedDispatcherRequest = findDispatcherRequestPayload(requestId);
            selectedDispatcherUnitIds.clear();

            getDispatcherRequestRows().forEach((row) => {
                row.classList.toggle('dispatcher-request-row-active', Number(row.getAttribute('data-request-id') || '0') === Number(requestId));
            });

            renderDispatcherRequestDetails();
            renderDispatcherSelectedUnits();
        }

        async function openDispatcherRequestModal() {
            const modal = document.getElementById('dispatcherRequestModal');
            const notesInput = document.getElementById('dispatcherDispatchNotesInput');
            if (!modal) return;

            selectedDispatcherRequest = null;
            selectedDispatcherUnitIds.clear();
            if (notesInput) {
                notesInput.value = '';
            }
            try {
                await loadDispatcherBackupData();
            } catch (err) {
                console.error(err);
                showNotification((err && err.message) ? err.message : 'Unable to refresh backup requests.', 'error');
            }
            renderDispatcherRequestDetails();
            renderDispatcherSelectedUnits();

            const requestRows = getDispatcherRequestRows();
            if (requestRows.length) {
                const preferredRow = requestRows.find((row) => {
                    const payload = parseJsonAttribute(row.getAttribute('data-request-json'), null);
                    return payload && String(payload.status || '') !== 'fulfilled';
                }) || requestRows[0];
                const preferredRequestId = Number(preferredRow.getAttribute('data-request-id') || '0');
                if (preferredRequestId > 0) {
                    selectDispatcherRequest(preferredRequestId);
                }
            }

            modal.classList.add('show');
            document.body.style.overflow = 'hidden';
        }

        function closeDispatcherRequestModal() {
            const modal = document.getElementById('dispatcherRequestModal');
            const notesInput = document.getElementById('dispatcherDispatchNotesInput');
            if (!modal) return;
            modal.classList.remove('show');
            document.body.style.overflow = '';
            selectedDispatcherRequest = null;
            selectedDispatcherUnitIds.clear();
            if (notesInput) {
                notesInput.value = '';
            }
            getDispatcherRequestRows().forEach((row) => row.classList.remove('dispatcher-request-row-active'));
            renderDispatcherRequestDetails();
            renderDispatcherSelectedUnits();
        }

        function toggleDispatcherUnit(unitId) {
            if (!unitId) return;
            if (selectedDispatcherUnitIds.has(unitId)) {
                selectedDispatcherUnitIds.delete(unitId);
            } else {
                selectedDispatcherUnitIds.add(unitId);
            }
            renderDispatcherSelectedUnits();
        }

        function requestResource() {
            openDispatcherRequestModal();
        }

        function closeResourceModal() {
            closeDispatcherRequestModal();
        }

        function submitResourceRequest(event) {
            submitDispatcherRequest(event);
        }

        function submitDispatcherRequest(event) {
            event.preventDefault();

            if (!selectedDispatcherRequest) {
                showNotification('Select an admin backup request first.', 'error');
                return;
            }
            if (!selectedDispatcherRequest.incident_id) {
                showNotification('The selected request has no incident ID to dispatch.', 'error');
                return;
            }
            if (String(selectedDispatcherRequest.status || '') === 'fulfilled') {
                showNotification('This backup request was already sent to responders.', 'info');
                return;
            }
            if (selectedDispatcherUnitIds.size === 0) {
                showNotification('Choose at least one available unit to send.', 'error');
                return;
            }

            const submitBtn = document.getElementById('dispatcherRequestSubmitBtn');
            const notesInput = document.getElementById('dispatcherDispatchNotesInput');
            const formData = new FormData();
            formData.append('request_id', String(selectedDispatcherRequest.id || ''));
            formData.append('incident_id', String(selectedDispatcherRequest.incident_id || ''));
            formData.append('dispatcher_name', DISPATCHER_REQUESTOR);
            formData.append('notes', notesInput ? notesInput.value.trim() : '');
            Array.from(selectedDispatcherUnitIds).forEach((unitId) => {
                formData.append('unit_ids[]', String(unitId));
            });

            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.style.opacity = '0.75';
                submitBtn.textContent = 'Sending...';
            }

            fetch(DISPATCHER_REQUEST_ENDPOINT, {
                method: 'POST',
                body: formData
            })
                .then((response) => response.json())
                .then((res) => {
                    if (res && (res.ok || res.success)) {
                        showNotification('Backup request sent to responders successfully.', 'success');
                        closeDispatcherRequestModal();
                        setTimeout(() => { window.location.reload(); }, 700);
                    } else {
                        showNotification('Failed to send request: ' + (res && res.error ? res.error : 'Unknown error'), 'error');
                    }
                })
                .catch((err) => {
                    console.error(err);
                    showNotification('Network error while sending request.', 'error');
                })
                .finally(() => {
                    if (submitBtn) {
                        submitBtn.textContent = 'Send Request to Responders';
                        syncDispatcherSubmitState();
                    }
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
            const requestor = tr ? tr.getAttribute('data-requestor') : '';
            const priority = tr ? tr.getAttribute('data-priority') : '';
            const urgency = tr ? tr.getAttribute('data-urgency') : '';
            const location = tr ? tr.getAttribute('data-location') : '';
            const requestKind = tr ? tr.getAttribute('data-request-kind') : '';
            const incidentId = tr ? tr.getAttribute('data-incident-id') : '';
            const incidentCode = tr ? tr.getAttribute('data-incident-code') : '';
            const incidentTitle = tr ? tr.getAttribute('data-incident-title') : '';
            const dispatcherName = tr ? tr.getAttribute('data-dispatcher-name') : '';
            const dispatchedAt = tr ? tr.getAttribute('data-dispatched-at') : '';
            const selectedResources = tr ? parseJsonAttribute(tr.getAttribute('data-selected-resources'), []) : [];
            const dispatchedUnits = tr ? parseJsonAttribute(tr.getAttribute('data-dispatched-units'), []) : [];
            const modal = document.getElementById('requestNotesModal');
            const content = document.getElementById('requestNotesContent');
            if (modal && content) {
                let html = `<strong>${escapeHtml(name)}</strong>`;
                if (requestor) { html += `<br>Requestor: ${escapeHtml(requestor)}`; }
                if (status) { html += `<br>Status: ${escapeHtml(status.charAt(0).toUpperCase() + status.slice(1))}`; }
                if (requestKind) { html += `<br>Request Type: ${escapeHtml(requestKind)}`; }
                if (incidentId || incidentCode) {
                    const incidentLabel = incidentCode ? `${incidentCode} (#${incidentId || ''})` : `Incident #${incidentId || ''}`;
                    html += `<br>Incident: ${escapeHtml(incidentLabel)}`;
                }
                if (incidentTitle) { html += `<br>Incident Title: ${escapeHtml(incidentTitle)}`; }
                if (priority || urgency) {
                    html += `<br>Priority / Urgency: ${escapeHtml(priority || 'N/A')} / ${escapeHtml(urgency || 'N/A')}`;
                }
                if (location) { html += `<br>Location: ${escapeHtml(location)}`; }
                html += `<br>${notes ? 'Notes: ' + escapeHtml(notes) : 'No notes provided.'}`;
                if (Array.isArray(selectedResources) && selectedResources.length) {
                    html += '<br>Requested Resources:<ul class="request-notes-list">';
                    html += selectedResources.map((item) => `<li><strong>${escapeHtml(item.code || `#${item.id || ''}`)}</strong> - ${escapeHtml(item.name || 'Resource')}</li>`).join('');
                    html += '</ul>';
                }
                if (decision) { html += `<br>Decision Reason: ${escapeHtml(decision)}`; }
                if (Array.isArray(dispatchedUnits) && dispatchedUnits.length) {
                    html += '<br>Dispatched Units:<ul class="request-notes-list">';
                    html += dispatchedUnits.map((item) => `<li><strong>${escapeHtml(item.identifier || `Unit #${item.id || ''}`)}</strong> - ${escapeHtml(item.unit_type || 'Unit')}</li>`).join('');
                    html += '</ul>';
                }
                if (dispatcherName) { html += `<br>Dispatcher: ${escapeHtml(dispatcherName)}`; }
                if (dispatchedAt) { html += `<br>Sent At: ${escapeHtml(formatRequestDate(dispatchedAt))}`; }
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

        // Add css animations and modal styles
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

            .dispatcher-request-modal-content {
                max-width: 1080px !important;
                width: min(1080px, 96vw) !important;
            }

            .dispatcher-request-modal-body {
                max-height: calc(90vh - 150px);
                overflow-y: auto;
                padding: 1rem !important;
            }

            .dispatcher-request-layout {
                display: grid;
                gap: 0.9rem;
            }

            .dispatcher-request-section {
                border: 1px solid #e5e7eb;
                border-radius: 12px;
                background: #f8fafc;
                padding: 0.85rem 0.95rem;
            }

            .dispatcher-request-section-head {
                display: flex;
                justify-content: space-between;
                align-items: flex-start;
                gap: 0.75rem;
                margin-bottom: 0.7rem;
            }

            .dispatcher-request-section-head strong {
                display: block;
                color: #0f172a;
                font-size: 1rem;
                margin-bottom: 0.2rem;
            }

            .dispatcher-request-section-head span,
            .dispatcher-request-subtle {
                color: #64748b;
                font-size: 0.85rem;
            }

            .dispatcher-request-chip {
                display: inline-flex;
                align-items: center;
                gap: 0.35rem;
                padding: 0.3rem 0.7rem;
                border-radius: 999px;
                background: #dbeafe;
                color: #1d4ed8;
                font-size: 0.8rem;
                font-weight: 700;
                white-space: nowrap;
            }

            .dispatcher-request-table-wrap {
                overflow: auto;
                border: 1px solid #dbe3ee;
                border-radius: 10px;
                background: #fff;
                max-height: 260px;
            }

            .dispatcher-request-unit-wrap {
                max-height: 280px;
            }

            .dispatcher-request-table {
                width: 100%;
                border-collapse: collapse;
                min-width: 720px;
            }

            .dispatcher-request-table th,
            .dispatcher-request-table td {
                padding: 0.78rem 0.75rem;
                border-bottom: 1px solid #e5e7eb;
                text-align: left;
                vertical-align: middle;
            }

            .dispatcher-request-table th {
                background: #eff6ff;
                color: #1e3a8a;
                font-size: 0.82rem;
                text-transform: uppercase;
                letter-spacing: 0.04em;
                position: sticky;
                top: 0;
                z-index: 1;
            }

            .dispatcher-request-row-active {
                background: #eff6ff;
            }

            .dispatcher-select-btn,
            .dispatcher-unit-btn {
                min-width: 38px;
                justify-content: center;
            }

            .dispatcher-unit-btn-selected {
                background: #16a34a;
            }

            .dispatcher-unit-btn-selected:hover {
                background: #15803d;
            }

            .dispatcher-request-empty {
                text-align: center;
                color: #64748b;
                padding: 1.25rem !important;
            }

            .dispatcher-request-details {
                background: #fff;
                border: 1px solid #dbe3ee;
                border-radius: 10px;
                padding: 0.85rem 0.95rem;
                color: #334155;
                line-height: 1.6;
            }

            .dispatcher-request-details-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0.85rem 1rem;
            }

            .dispatcher-request-details-grid > div {
                display: flex;
                flex-direction: column;
                gap: 0.2rem;
            }

            .dispatcher-request-details-grid > div.full {
                grid-column: 1 / -1;
            }

            .dispatcher-request-details-grid strong {
                color: #0f172a;
                font-size: 0.86rem;
            }

            .dispatcher-request-details-grid span {
                color: #475569;
                font-size: 0.93rem;
            }

            .dispatcher-request-resource-list,
            .request-notes-list {
                margin: 0.5rem 0 0;
                padding-left: 1.1rem;
            }

            .dispatcher-request-resource-list li,
            .request-notes-list li {
                margin-bottom: 0.45rem;
                color: #334155;
            }

            .dispatcher-request-resource-list li span {
                display: block;
                color: #64748b;
                font-size: 0.84rem;
            }

            .dispatcher-selected-units {
                display: flex;
                flex-wrap: wrap;
                gap: 0.55rem;
                margin-top: 0.75rem;
            }

            .dispatcher-selected-chip {
                display: inline-flex;
                align-items: center;
                gap: 0.45rem;
                padding: 0.45rem 0.7rem;
                border-radius: 999px;
                background: #dbeafe;
                color: #1e3a8a;
                font-size: 0.86rem;
                font-weight: 600;
            }

            .dispatcher-selected-chip button {
                border: none;
                background: transparent;
                color: inherit;
                cursor: pointer;
                padding: 0;
            }

            .dispatcher-request-form-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0.75rem;
            }

            .dispatcher-request-form-grid .full {
                grid-column: 1 / -1;
            }

            #dispatcherRequestModal .modal-header {
                padding: 0.9rem 1rem;
            }

            #dispatcherRequestModal .modal-header h3 {
                font-size: 1.07rem;
            }

            #dispatcherRequestModal .modal-footer {
                gap: 0.55rem;
                padding: 0.9rem 1rem;
            }

            #dispatcherRequestModal .form-group {
                margin-bottom: 1rem;
            }

            #dispatcherRequestModal .form-group label {
                margin-bottom: 0.4rem;
            }

            html[data-theme="dark"] .resource-request-modal {
                background-color: rgba(2, 6, 23, 0.78);
            }

            html[data-theme="dark"] .resource-request-modal .modal-content {
                background: #111827;
                border: 1px solid #334155;
                box-shadow: 0 18px 48px rgba(2, 6, 23, 0.55);
            }

            html[data-theme="dark"] .resource-request-modal .modal-header,
            html[data-theme="dark"] .resource-request-modal .modal-footer {
                background: #0f172a;
                border-color: #334155;
            }

            html[data-theme="dark"] .resource-request-modal .modal-header h3,
            html[data-theme="dark"] .resource-request-modal .form-group label {
                color: #f8fafc;
            }

            html[data-theme="dark"] .resource-request-modal .modal-close {
                color: #cbd5e1;
            }

            html[data-theme="dark"] .resource-request-modal .modal-close:hover {
                background: #1e293b;
                color: #ffffff;
            }

            html[data-theme="dark"] .resource-request-modal .modal-body {
                background: #111827;
                color: #e5eef9;
            }

            html[data-theme="dark"] .resource-request-modal .form-group input,
            html[data-theme="dark"] .resource-request-modal .form-group select,
            html[data-theme="dark"] .resource-request-modal .form-group textarea {
                background: #0f172a;
                border-color: #334155;
                color: #e5eef9;
            }

            html[data-theme="dark"] .resource-request-modal .form-group input:focus,
            html[data-theme="dark"] .resource-request-modal .form-group select:focus,
            html[data-theme="dark"] .resource-request-modal .form-group textarea:focus {
                border-color: #60a5fa;
                box-shadow: 0 0 0 3px rgba(96, 165, 250, 0.18);
            }

            html[data-theme="dark"] .resource-request-modal .form-group input::placeholder,
            html[data-theme="dark"] .resource-request-modal .form-group textarea::placeholder {
                color: #94a3b8;
            }

            html[data-theme="dark"] .resource-request-modal .btn-cancel {
                background: #1e293b;
                color: #e5eef9;
                border: 1px solid #334155;
            }

            html[data-theme="dark"] .resource-request-modal .btn-cancel:hover {
                background: #334155;
            }

            html[data-theme="dark"] .resource-request-modal .btn-submit {
                background: #2563eb;
                color: #ffffff;
            }

            html[data-theme="dark"] .resource-request-modal .btn-submit:hover {
                background: #1d4ed8;
            }

            html[data-theme="dark"] #requestNotesContent,
            html[data-theme="dark"] .request-notes-list li {
                color: #e5eef9 !important;
            }

            html[data-theme="dark"] .dispatcher-request-section {
                background: #0f172a;
                border-color: #334155;
            }

            html[data-theme="dark"] .dispatcher-request-section-head strong {
                color: #f8fafc;
            }

            html[data-theme="dark"] .dispatcher-request-section-head span,
            html[data-theme="dark"] .dispatcher-request-subtle,
            html[data-theme="dark"] .dispatcher-request-empty {
                color: #94a3b8;
            }

            html[data-theme="dark"] .dispatcher-request-chip {
                background: rgba(37, 99, 235, 0.16);
                color: #bfdbfe;
                border: 1px solid rgba(96, 165, 250, 0.28);
            }

            html[data-theme="dark"] .dispatcher-request-table-wrap,
            html[data-theme="dark"] .dispatcher-request-details {
                background: #111827;
                border-color: #334155;
            }

            html[data-theme="dark"] .dispatcher-request-table th {
                background: #0b1220;
                color: #cbd5e1;
                border-bottom-color: #334155;
            }

            html[data-theme="dark"] .dispatcher-request-table td {
                color: #e5eef9;
                border-bottom-color: #1f2937;
            }

            html[data-theme="dark"] .dispatcher-request-row-active {
                background: rgba(30, 64, 175, 0.18);
            }

            html[data-theme="dark"] .dispatcher-request-details,
            html[data-theme="dark"] .dispatcher-request-details-grid span,
            html[data-theme="dark"] .dispatcher-request-resource-list li {
                color: #cbd5e1;
            }

            html[data-theme="dark"] .dispatcher-request-details-grid strong {
                color: #f8fafc;
            }

            html[data-theme="dark"] .dispatcher-request-resource-list li span {
                color: #94a3b8;
            }

            html[data-theme="dark"] .dispatcher-selected-chip {
                background: rgba(30, 64, 175, 0.2);
                color: #bfdbfe;
                border: 1px solid rgba(96, 165, 250, 0.28);
            }

            @media (max-width: 720px) {
                .dispatcher-request-details-grid,
                .dispatcher-request-form-grid {
                    grid-template-columns: 1fr;
                }
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

            const dispatcherRequestModal = document.getElementById('dispatcherRequestModal');
            if (dispatcherRequestModal) {
                dispatcherRequestModal.addEventListener('click', function(e) {
                    if (e.target === dispatcherRequestModal) {
                        closeDispatcherRequestModal();
                    }
                });
            }

            const dispatcherRequestList = document.getElementById('dispatcherBackupRequestList');
            if (dispatcherRequestList) {
                dispatcherRequestList.addEventListener('click', function(e) {
                    const btn = e.target.closest('[data-select-dispatcher-request]');
                    if (!btn) return;
                    const requestId = Number(btn.getAttribute('data-select-dispatcher-request') || '0');
                    if (requestId > 0) {
                        selectDispatcherRequest(requestId);
                    }
                });
            }

            const dispatcherUnitsBody = document.getElementById('dispatcherAvailableUnitsBody');
            if (dispatcherUnitsBody) {
                dispatcherUnitsBody.addEventListener('click', function(e) {
                    const btn = e.target.closest('[data-toggle-dispatcher-unit]');
                    if (!btn) return;
                    const unitId = Number(btn.getAttribute('data-toggle-dispatcher-unit') || '0');
                    if (unitId > 0) {
                        toggleDispatcherUnit(unitId);
                    }
                });
            }

            const selectedUnitsEl = document.getElementById('dispatcherSelectedUnits');
            if (selectedUnitsEl) {
                selectedUnitsEl.addEventListener('click', function(e) {
                    const btn = e.target.closest('[data-toggle-dispatcher-unit]');
                    if (!btn) return;
                    const unitId = Number(btn.getAttribute('data-toggle-dispatcher-unit') || '0');
                    if (unitId > 0) {
                        toggleDispatcherUnit(unitId);
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
                        return;
                    }
                    const dispatcherRequestModalEl = document.getElementById('dispatcherRequestModal');
                    if (dispatcherRequestModalEl && dispatcherRequestModalEl.classList.contains('show')) {
                        closeDispatcherRequestModal();
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
