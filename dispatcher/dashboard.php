<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('dispatcher', 'dispatcher/dashboard.php');

$current_user = get_logged_in_user();
$current_role = strtolower((string)($current_user['role'] ?? ''));

$pageTitle = 'Dispatcher Dashboard';
$dashboardUrl = 'dispatcher/dashboard.php';
$incidentUrl = 'dispatcher/incident.php';
$dispatchUrl = 'dispatcher/dispatch.php';
$resourcesUrl = 'dispatcher/resources.php';
$gpsUrl = 'dispatcher/gps.php';
$callUrl = 'dispatcher/call.php';
$reviewUrl = 'dispatcher/review.php';
$pending_incidents = 0;
$active_dispatches = 0;
$available_units = 0;
$units_in_field = 0;
$today_calls = 0;
$avg_response_min = 0.0;
$queue_items = [];
$unit_items = [];
$activity_items = [];
$type_counts = [
    'medical' => 0,
    'fire' => 0,
    'police' => 0,
    'traffic' => 0
];

try {
    require_once $rootDir . '/includes/db.php';
    require_once $rootDir . '/includes/vehicle_resource_units.php';
    $pdo = get_db_connection();

    if ($pdo) {
        $vehicleResourceTable = ers_vehicle_resource_units_table($pdo);

        $pending_incidents = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status = 'pending'")->fetch()['c'];
        $active_dispatches = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status IN ('dispatched', 'active', 'in_progress')")->fetch()['c'];
        $available_units = ers_count_available_vehicle_resource_units($pdo, $vehicleResourceTable ?? null);
        $units_in_field = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status IN ('assigned', 'enroute', 'on_scene')")->fetch()['c'];
        $today_calls = (int)$pdo->query("SELECT COUNT(*) AS c FROM calls WHERE DATE(created_at) = CURDATE()")->fetch()['c'];

        $avg_row = $pdo->query("
            SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, responded_at)) AS avg_rt
            FROM incidents
            WHERE responded_at IS NOT NULL
              AND created_at >= NOW() - INTERVAL 7 DAY
        ")->fetch();
        $avg_response_min = (float)($avg_row['avg_rt'] ?? 0);

        $queue_stmt = $pdo->query("
            SELECT
                i.id,
                i.reference_no,
                i.title,
                i.type,
                i.priority,
                i.status,
                i.location_address,
                i.created_at,
                c.caller_name,
                c.caller_phone,
                u.identifier AS unit_identifier
            FROM incidents i
            LEFT JOIN calls c ON c.id = i.reported_by_call_id
            LEFT JOIN dispatches d ON d.id = (
                SELECT d2.id
                FROM dispatches d2
                WHERE d2.incident_id = i.id
                ORDER BY d2.assigned_at DESC
                LIMIT 1
            )
            LEFT JOIN units u ON u.id = d.unit_id
            WHERE i.status IN ('pending', 'dispatched', 'active', 'in_progress')
            ORDER BY CASE LOWER(i.priority)
                WHEN 'critical' THEN 1
                WHEN 'high' THEN 2
                WHEN 'urgent' THEN 2
                WHEN 'medium' THEN 3
                WHEN 'moderate' THEN 3
                WHEN 'low' THEN 4
                ELSE 6
            END, i.created_at ASC
            LIMIT 10
        ");
        $queue_items = $queue_stmt ? $queue_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        if ($vehicleResourceTable !== null) {
            $unit_stmt = $pdo->query("
                SELECT
                    rr.id,
                    rr.code AS identifier,
                    rr.name AS unit_type,
                    rr.status,
                    COALESCE(responder.name, 'Unassigned') AS responder_name,
                    i.reference_no AS incident_code
                FROM `" . $vehicleResourceTable . "` rr
                LEFT JOIN units u ON u.identifier = rr.code
                LEFT JOIN incidents i ON i.id = u.current_incident_id
                LEFT JOIN users responder ON responder.unit_code = rr.code AND LOWER(COALESCE(responder.role, '')) = 'responder'
                WHERE LOWER(rr.category) = 'vehicles'
                ORDER BY FIELD(LOWER(rr.status), 'available', 'in_use', 'busy', 'assigned', 'enroute', 'on_scene', 'maintenance', 'offline', 'unavailable'), rr.code ASC
            ");
        } else {
            $unit_stmt = $pdo->query("
                SELECT
                    u.id,
                    u.identifier,
                    u.unit_type,
                    u.status,
                    COALESCE(responder.name, 'Unassigned') AS responder_name,
                    i.reference_no AS incident_code
                FROM units u
                LEFT JOIN incidents i ON i.id = u.current_incident_id
                LEFT JOIN users responder ON responder.unit_code = u.identifier AND LOWER(COALESCE(responder.role, '')) = 'responder'
                ORDER BY FIELD(u.status, 'available', 'assigned', 'busy', 'in_use', 'enroute', 'on_scene', 'maintenance', 'offline', 'unavailable'), u.identifier ASC
            ");
        }
        $unit_items = $unit_stmt ? $unit_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $activity_stmt = $pdo->query("
            SELECT a.action, a.entity_type, a.details, a.created_at, u.name AS username
            FROM activity_log a
            LEFT JOIN users u ON u.id = a.user_id
            ORDER BY a.created_at DESC
            LIMIT 8
        ");
        $activity_items = $activity_stmt ? $activity_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $type_stmt = $pdo->query("
            SELECT LOWER(type) AS type_name, COUNT(*) AS c
            FROM incidents
            WHERE status IN ('pending', 'dispatched', 'active', 'in_progress')
            GROUP BY LOWER(type)
        ");
        if ($type_stmt) {
            foreach ($type_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $k = (string)($row['type_name'] ?? '');
                if (isset($type_counts[$k])) {
                    $type_counts[$k] = (int)$row['c'];
                }
            }
        }
    }
} catch (Throwable $e) {
    error_log('Dispatcher dashboard load error: ' . $e->getMessage());
}

function dispatcher_time_ago(string $datetime): string {
    $ts = strtotime($datetime);
    if ($ts === false) {
        return 'Unknown time';
    }

    $diff = time() - $ts;
    if ($diff < 60) {
        return 'Just now';
    }
    if ($diff < 3600) {
        return floor($diff / 60) . 'm ago';
    }
    if ($diff < 86400) {
        return floor($diff / 3600) . 'h ago';
    }

    return date('M d, g:i A', $ts);
}

function dispatcher_priority_class(string $priority): string {
    $p = strtolower(trim($priority));
    if ($p === 'critical') {
        return 'priority-critical';
    }
    if ($p === 'high') {
        return 'priority-high';
    }
    if ($p === 'urgent') {
        return 'priority-high';
    }
    if ($p === 'moderate' || $p === 'medium') {
        return 'priority-medium';
    }
    return 'priority-low';
}

function dispatcher_status_class(string $status): string {
    $s = strtolower(trim($status));
    if ($s === 'available') {
        return 'status-available';
    }
    if ($s === 'assigned' || $s === 'dispatched') {
        return 'status-assigned';
    }
    if ($s === 'enroute' || $s === 'in_progress' || $s === 'active' || $s === 'on_scene') {
        return 'status-enroute';
    }
    return 'status-other';
}

$type_total = array_sum($type_counts);
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
    <link rel="stylesheet" href="css/dispatcher-dashboard.css">
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <div class="main-content">
        <div class="main-container dispatcher-shell">
            <section class="dispatcher-hero">
                <div class="hero-copy">
                    <div class="hero-kicker">Operations Console</div>
                    <h1>Dispatcher Command Dashboard</h1>
                    <p>Live queue monitoring, unit availability, and response coordination in one view.</p>
                </div>
                <div class="hero-meta">
                    <div class="meta-chip">
                        <i class="fas fa-user-shield"></i>
                        <span><?php echo htmlspecialchars(ucfirst($current_role)); ?></span>
                    </div>
                    <div class="meta-chip">
                        <i class="fas fa-clock"></i>
                        <span id="liveClock"><?php echo date('M d, Y h:i:s A'); ?></span>
                    </div>
                    <button class="hero-btn" type="button" onclick="window.location.href='<?php echo htmlspecialchars($dashboardUrl, ENT_QUOTES); ?>'">
                        <i class="fas fa-rotate"></i>
                        Refresh Panel
                    </button>
                </div>
            </section>

            <section class="dispatcher-metrics">
                <article class="metric-block pending">
                    <div class="metric-label">Pending Incidents</div>
                    <div class="metric-value" id="metricPendingIncidents"><?php echo (int)$pending_incidents; ?></div>
                    <a href="<?php echo htmlspecialchars($incidentUrl); ?>" class="metric-link">Review queue</a>
                </article>
                <article class="metric-block active">
                    <div class="metric-label">Active Dispatches</div>
                    <div class="metric-value" id="metricActiveDispatches"><?php echo (int)$active_dispatches; ?></div>
                    <a href="<?php echo htmlspecialchars($dispatchUrl); ?>" class="metric-link">Open dispatch center</a>
                </article>
                <article class="metric-block available">
                    <div class="metric-label">Available Units</div>
                    <div class="metric-value" id="metricAvailableUnits"><?php echo (int)$available_units; ?></div>
                    <a href="<?php echo htmlspecialchars($resourcesUrl); ?>" class="metric-link">View resources</a>
                </article>
                <article class="metric-block field">
                    <div class="metric-label">Units In Field</div>
                    <div class="metric-value" id="metricUnitsInField"><?php echo (int)$units_in_field; ?></div>
                    <a href="<?php echo htmlspecialchars($gpsUrl); ?>" class="metric-link">Track positions</a>
                </article>
                <article class="metric-block calls">
                    <div class="metric-label">Calls Today</div>
                    <div class="metric-value" id="metricTodayCalls"><?php echo (int)$today_calls; ?></div>
                    <a href="<?php echo htmlspecialchars($callUrl); ?>" class="metric-link">Open call logs</a>
                </article>
                <article class="metric-block response">
                    <div class="metric-label">Avg Response (7d)</div>
                    <div class="metric-value" id="metricAvgResponse"><?php echo number_format($avg_response_min, 1); ?>m</div>
                    <a href="<?php echo htmlspecialchars($reviewUrl); ?>" class="metric-link">View reviews</a>
                </article>
            </section>

            <section class="dispatcher-main-grid">
                <div class="panel queue-panel">
                    <div class="panel-header-row">
                        <h2><i class="fas fa-triangle-exclamation"></i> Priority Incident Queue</h2>
                        <div class="queue-filters">
                            <button type="button" class="queue-filter active" data-priority="all">All</button>
                            <button type="button" class="queue-filter" data-priority="critical">Critical</button>
                            <button type="button" class="queue-filter" data-priority="high">High</button>
                            <button type="button" class="queue-filter" data-priority="medium">Medium</button>
                            <button type="button" class="queue-filter" data-priority="low">Low</button>
                        </div>
                    </div>
                    <div id="queueList" class="queue-list">
                        <?php if (empty($queue_items)): ?>
                            <div class="empty-state">No active queue items right now.</div>
                        <?php else: ?>
                            <?php foreach ($queue_items as $item): ?>
                                <?php
                                $priority = strtolower((string)($item['priority'] ?? 'low'));
                                $status = (string)($item['status'] ?? 'pending');
                                $incidentId = (int)($item['id'] ?? 0);
                                $queueDispatchUrl = $dispatchUrl . ($incidentId > 0 ? '?incident_id=' . urlencode((string)$incidentId) : '');
                                $queueDetailsUrl = $incidentUrl . ($incidentId > 0 ? '?incident_id=' . urlencode((string)$incidentId) : '');
                                ?>
                                <article class="queue-item" data-priority="<?php echo htmlspecialchars($priority); ?>">
                                    <div class="queue-item-top">
                                        <div class="queue-title-wrap">
                                            <div class="queue-title"><?php echo htmlspecialchars((string)($item['reference_no'] ?? 'INC-NA')); ?> - <?php echo htmlspecialchars((string)($item['title'] ?? $item['type'] ?? 'Untitled incident')); ?></div>
                                            <div class="queue-sub">
                                                <span><i class="fas fa-location-dot"></i> <?php echo htmlspecialchars((string)($item['location_address'] ?? 'Location unavailable')); ?></span>
                                                <span><i class="fas fa-user"></i> <?php echo htmlspecialchars((string)($item['caller_name'] ?? 'Unknown caller')); ?></span>
                                            </div>
                                        </div>
                                        <div class="queue-tags">
                                            <span class="pill <?php echo dispatcher_priority_class($priority); ?>"><?php echo htmlspecialchars(ucfirst($priority)); ?></span>
                                            <span class="pill status <?php echo dispatcher_status_class($status); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $status))); ?></span>
                                        </div>
                                    </div>
                                    <div class="queue-item-bottom">
                                        <div class="queue-meta">
                                            <span><i class="fas fa-clock"></i> <?php echo htmlspecialchars(dispatcher_time_ago((string)($item['created_at'] ?? ''))); ?></span>
                                            <span><i class="fas fa-truck-medical"></i> <?php echo htmlspecialchars((string)($item['unit_identifier'] ?? 'Unassigned')); ?></span>
                                            <span><i class="fas fa-phone"></i> <?php echo htmlspecialchars((string)($item['caller_phone'] ?? 'No phone')); ?></span>
                                        </div>
                                        <div class="queue-actions">
                                            <a href="<?php echo htmlspecialchars($queueDispatchUrl); ?>" class="btn-queue primary">Dispatch</a>
                                            <a href="<?php echo htmlspecialchars($queueDetailsUrl); ?>" class="btn-queue">Details</a>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <aside class="panel unit-panel">
                    <div class="panel-header-row">
                        <h2><i class="fas fa-ambulance"></i> Unit Availability</h2>
                    </div>
                    <div class="unit-list" id="unitList">
                        <?php if (empty($unit_items)): ?>
                            <div class="empty-state">No units found.</div>
                        <?php else: ?>
                            <?php foreach ($unit_items as $unit): ?>
                                <?php $status = strtolower((string)($unit['status'] ?? 'unknown')); ?>
                                <?php $unitType = ucfirst((string)($unit['unit_type'] ?? 'Responder')); ?>
                                <?php $responderName = trim((string)($unit['responder_name'] ?? '')); ?>
                                <?php $displayType = $responderName !== '' && $responderName !== 'Unassigned' ? $unitType . ' / ' . $responderName : $unitType; ?>
                                <article class="unit-item">
                                    <div class="unit-main">
                                        <div class="unit-name"><?php echo htmlspecialchars((string)($unit['identifier'] ?? 'Unit')); ?></div>
                                        <div class="unit-type"><?php echo htmlspecialchars($displayType); ?></div>
                                    </div>
                                    <div class="unit-side">
                                        <span class="pill status <?php echo dispatcher_status_class($status); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $status))); ?></span>
                                        <?php if (!empty($unit['incident_code'])): ?>
                                            <div class="unit-incident"><?php echo htmlspecialchars((string)$unit['incident_code']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                    <div class="unit-actions">
                        <a href="<?php echo htmlspecialchars($dispatchUrl); ?>" class="btn-queue primary">Open Dispatch Board</a>
                        <a href="<?php echo htmlspecialchars($gpsUrl); ?>" class="btn-queue">Live GPS</a>
                    </div>
                </aside>
            </section>

            <section class="dispatcher-bottom-grid">
                <div class="panel mix-panel">
                    <div class="panel-header-row">
                        <h2><i class="fas fa-chart-simple"></i> Active Mix By Type</h2>
                    </div>
                    <div class="mix-list" id="mixList">
                        <?php foreach ($type_counts as $type => $count): ?>
                            <?php
                            $pct = $type_total > 0 ? (int)round(($count / $type_total) * 100) : 0;
                            ?>
                            <div class="mix-item">
                                <div class="mix-top">
                                    <span class="mix-type"><?php echo htmlspecialchars(ucfirst($type)); ?></span>
                                    <span class="mix-value"><?php echo (int)$count; ?> (<?php echo $pct; ?>%)</span>
                                </div>
                                <div class="mix-bar">
                                    <span style="width: <?php echo $pct; ?>%"></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="panel activity-panel">
                    <div class="panel-header-row">
                        <h2><i class="fas fa-wave-square"></i> Recent Operations Activity</h2>
                    </div>
                    <div class="activity-list" id="activityList">
                        <?php if (empty($activity_items)): ?>
                            <div class="empty-state">No recent activity.</div>
                        <?php else: ?>
                            <?php foreach ($activity_items as $log): ?>
                                <article class="activity-item">
                                    <div class="activity-main">
                                        <div class="activity-title">
                                            <?php echo htmlspecialchars((string)($log['action'] ?? 'action')); ?>
                                            <?php if (!empty($log['entity_type'])): ?>
                                                on <?php echo htmlspecialchars((string)$log['entity_type']); ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="activity-sub">
                                            <?php echo htmlspecialchars((string)($log['details'] ?? 'No details')); ?>
                                        </div>
                                    </div>
                                    <div class="activity-side">
                                        <div class="activity-user"><?php echo htmlspecialchars((string)($log['username'] ?? 'System')); ?></div>
                                        <div class="activity-time"><?php echo htmlspecialchars(dispatcher_time_ago((string)($log['created_at'] ?? ''))); ?></div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>
    <script>
    function dispatcherEscapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function dispatcherTimeAgo(value) {
        const raw = String(value || '').trim();
        if (!raw) return 'Unknown time';
        const date = new Date(raw.indexOf('T') === -1 ? raw.replace(' ', 'T') : raw);
        if (Number.isNaN(date.getTime())) return raw;
        const diff = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000));
        if (diff < 60) return 'Just now';
        if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
        if (diff < 86400) return Math.floor(diff / 3600) + 'h ago';
        return date.toLocaleString();
    }

    function dispatcherPriorityClass(priority) {
        const p = String(priority || '').trim().toLowerCase();
        if (p === 'critical') return 'priority-critical';
        if (p === 'high') return 'priority-high';
        if (p === 'urgent') return 'priority-high';
        if (p === 'moderate' || p === 'medium') return 'priority-medium';
        return 'priority-low';
    }

    function dispatcherStatusClass(status) {
        const s = String(status || '').trim().toLowerCase();
        if (s === 'available') return 'status-available';
        if (s === 'assigned' || s === 'dispatched') return 'status-assigned';
        if (s === 'enroute' || s === 'in_progress' || s === 'active' || s === 'on_scene') return 'status-enroute';
        return 'status-other';
    }

    function applyQueuePriorityFilter() {
        const activeFilter = document.querySelector('.queue-filter.active');
        const wanted = activeFilter ? activeFilter.getAttribute('data-priority') : 'all';
        document.querySelectorAll('.queue-item').forEach(function (item) {
            if (wanted === 'all' || item.getAttribute('data-priority') === wanted) {
                item.style.display = '';
            } else {
                item.style.display = 'none';
            }
        });
    }

    function renderDispatcherQueue(items) {
        const container = document.getElementById('queueList');
        if (!container) return;
        if (!Array.isArray(items) || !items.length) {
            container.innerHTML = '<div class="empty-state">No active queue items right now.</div>';
            return;
        }
        container.innerHTML = items.map(function (item) {
            const priority = String(item.priority || 'low').toLowerCase();
            const status = String(item.status || 'pending');
            const incidentId = Number(item.id || 0);
            const dispatchUrl = 'dispatcher/dispatch.php' + (incidentId > 0 ? ('?incident_id=' + encodeURIComponent(String(incidentId))) : '');
            const detailsUrl = 'dispatcher/incident.php' + (incidentId > 0 ? ('?incident_id=' + encodeURIComponent(String(incidentId))) : '');
            return `
                <article class="queue-item" data-priority="${dispatcherEscapeHtml(priority)}">
                    <div class="queue-item-top">
                        <div class="queue-title-wrap">
                            <div class="queue-title">${dispatcherEscapeHtml(item.reference_no || 'INC-NA')} - ${dispatcherEscapeHtml(item.title || item.type || 'Untitled incident')}</div>
                            <div class="queue-sub">
                                <span><i class="fas fa-location-dot"></i> ${dispatcherEscapeHtml(item.location_address || 'Location unavailable')}</span>
                                <span><i class="fas fa-user"></i> ${dispatcherEscapeHtml(item.caller_name || 'Unknown caller')}</span>
                            </div>
                        </div>
                        <div class="queue-tags">
                            <span class="pill ${dispatcherPriorityClass(priority)}">${dispatcherEscapeHtml(priority.charAt(0).toUpperCase() + priority.slice(1))}</span>
                            <span class="pill status ${dispatcherStatusClass(status)}">${dispatcherEscapeHtml(status.replace(/_/g, ' '))}</span>
                        </div>
                    </div>
                    <div class="queue-item-bottom">
                        <div class="queue-meta">
                            <span><i class="fas fa-clock"></i> ${dispatcherEscapeHtml(dispatcherTimeAgo(item.created_at || ''))}</span>
                            <span><i class="fas fa-truck-medical"></i> ${dispatcherEscapeHtml(item.unit_identifier || 'Unassigned')}</span>
                            <span><i class="fas fa-phone"></i> ${dispatcherEscapeHtml(item.caller_phone || 'No phone')}</span>
                        </div>
                        <div class="queue-actions">
                            <a href="${dispatcherEscapeHtml(dispatchUrl)}" class="btn-queue primary">Dispatch</a>
                            <a href="${dispatcherEscapeHtml(detailsUrl)}" class="btn-queue">Details</a>
                        </div>
                    </div>
                </article>
            `;
        }).join('');
        applyQueuePriorityFilter();
    }

    function renderDispatcherUnits(items) {
        const container = document.getElementById('unitList');
        if (!container) return;
        if (!Array.isArray(items) || !items.length) {
            container.innerHTML = '<div class="empty-state">No units found.</div>';
            return;
        }
        container.innerHTML = items.map(function (unit) {
            const status = String(unit.status || 'unknown').toLowerCase();
            const typeLabel = String(unit.unit_type || 'Responder');
            const responderName = String(unit.responder_name || '').trim();
            const displayType = responderName && responderName.toLowerCase() !== 'unassigned'
                ? `${typeLabel.charAt(0).toUpperCase() + typeLabel.slice(1)} / ${responderName}`
                : (typeLabel.charAt(0).toUpperCase() + typeLabel.slice(1));
            return `
                <article class="unit-item">
                    <div class="unit-main">
                        <div class="unit-name">${dispatcherEscapeHtml(unit.identifier || 'Unit')}</div>
                        <div class="unit-type">${dispatcherEscapeHtml(displayType)}</div>
                    </div>
                    <div class="unit-side">
                        <span class="pill status ${dispatcherStatusClass(status)}">${dispatcherEscapeHtml(status.replace(/_/g, ' '))}</span>
                        ${unit.incident_code ? `<div class="unit-incident">${dispatcherEscapeHtml(unit.incident_code)}</div>` : ''}
                    </div>
                </article>
            `;
        }).join('');
    }

    function renderDispatcherMix(typeCounts) {
        const container = document.getElementById('mixList');
        if (!container) return;
        const counts = typeCounts || {};
        const entries = [
            ['medical', Number(counts.medical || 0)],
            ['fire', Number(counts.fire || 0)],
            ['police', Number(counts.police || 0)],
            ['traffic', Number(counts.traffic || 0)]
        ];
        const total = entries.reduce(function (sum, entry) { return sum + entry[1]; }, 0);
        container.innerHTML = entries.map(function (entry) {
            const type = entry[0];
            const count = entry[1];
            const pct = total > 0 ? Math.round((count / total) * 100) : 0;
            return `
                <div class="mix-item">
                    <div class="mix-top">
                        <span class="mix-type">${dispatcherEscapeHtml(type.charAt(0).toUpperCase() + type.slice(1))}</span>
                        <span class="mix-value">${count} (${pct}%)</span>
                    </div>
                    <div class="mix-bar">
                        <span style="width: ${pct}%"></span>
                    </div>
                </div>
            `;
        }).join('');
    }

    function renderDispatcherActivity(items) {
        const container = document.getElementById('activityList');
        if (!container) return;
        if (!Array.isArray(items) || !items.length) {
            container.innerHTML = '<div class="empty-state">No recent activity.</div>';
            return;
        }
        container.innerHTML = items.map(function (log) {
            return `
                <article class="activity-item">
                    <div class="activity-main">
                        <div class="activity-title">
                            ${dispatcherEscapeHtml(log.action || 'action')}
                            ${log.entity_type ? ` on ${dispatcherEscapeHtml(log.entity_type)}` : ''}
                        </div>
                        <div class="activity-sub">${dispatcherEscapeHtml(log.details || 'No details')}</div>
                    </div>
                    <div class="activity-side">
                        <div class="activity-user">${dispatcherEscapeHtml(log.username || 'System')}</div>
                        <div class="activity-time">${dispatcherEscapeHtml(dispatcherTimeAgo(log.created_at || ''))}</div>
                    </div>
                </article>
            `;
        }).join('');
    }

    let dispatcherDashboardInFlight = false;
    async function refreshDispatcherDashboard() {
        if (dispatcherDashboardInFlight) return;
        dispatcherDashboardInFlight = true;
        try {
            const response = await fetch('api/dispatcher_dashboard_summary.php', { cache: 'no-store' });
            const data = await response.json();
            if (!data || !data.ok) throw new Error('Invalid dispatcher summary');

            const metrics = data.metrics || {};
            const setMetric = function (id, value, suffix) {
                const el = document.getElementById(id);
                if (el) {
                    el.textContent = suffix ? `${value}${suffix}` : String(value);
                }
            };

            setMetric('metricPendingIncidents', Number(metrics.pending_incidents || 0));
            setMetric('metricActiveDispatches', Number(metrics.active_dispatches || 0));
            setMetric('metricAvailableUnits', Number(metrics.available_units || 0));
            setMetric('metricUnitsInField', Number(metrics.units_in_field || 0));
            setMetric('metricTodayCalls', Number(metrics.today_calls || 0));
            setMetric('metricAvgResponse', Number(metrics.avg_response_min || 0).toFixed(1), 'm');

            renderDispatcherQueue(data.queue_items || []);
            renderDispatcherUnits(data.unit_items || []);
            renderDispatcherMix(data.type_counts || {});
            renderDispatcherActivity(data.activity_items || []);
        } catch (e) {
            console.error('refreshDispatcherDashboard failed', e);
        } finally {
            dispatcherDashboardInFlight = false;
        }
    }

    document.addEventListener('DOMContentLoaded', function () {
        const clockEl = document.getElementById('liveClock');
        if (clockEl) {
            setInterval(function () {
                const now = new Date();
                clockEl.textContent = now.toLocaleString();
            }, 1000);
        }

        const filters = document.querySelectorAll('.queue-filter');
        filters.forEach(function (btn) {
            btn.addEventListener('click', function () {
                filters.forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                applyQueuePriorityFilter();
            });
        });

        refreshDispatcherDashboard();
        setInterval(function () {
            if (!document.hidden) {
                try { refreshDispatcherDashboard(); } catch (e) {}
            }
        }, 10000);
    });
    </script>
</body>
</html>
