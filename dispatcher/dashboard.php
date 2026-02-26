<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('dispatcher', 'dispatcher/dashboard.php');

$current_user = get_logged_in_user();
$current_role = strtolower((string)($current_user['role'] ?? ''));

$pageTitle = 'Dispatcher Dashboard';
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
    $pdo = get_db_connection();

    if ($pdo) {
        $pending_incidents = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status = 'pending'")->fetch()['c'];
        $active_dispatches = (int)$pdo->query("SELECT COUNT(*) AS c FROM incidents WHERE status IN ('dispatched', 'active', 'in_progress')")->fetch()['c'];
        $available_units = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status = 'available'")->fetch()['c'];
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
            ORDER BY FIELD(LOWER(i.priority), 'critical', 'high', 'medium', 'low'), i.created_at ASC
            LIMIT 10
        ");
        $queue_items = $queue_stmt ? $queue_stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $unit_stmt = $pdo->query("
            SELECT
                u.id,
                u.identifier,
                u.unit_type,
                u.status,
                i.reference_no AS incident_code
            FROM units u
            LEFT JOIN incidents i ON i.id = u.current_incident_id
            ORDER BY FIELD(u.status, 'available', 'assigned', 'enroute', 'on_scene', 'maintenance', 'offline'), u.identifier ASC
            LIMIT 12
        ");
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
    if ($p === 'medium') {
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
    <base href="../">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <link rel="stylesheet" href="css/cards.css">
    <link rel="stylesheet" href="css/dispatcher-dashboard.css">
    <link rel="stylesheet" href="css/dispatcher-module-dark.css?v=20260226h">
    <script>document.documentElement.setAttribute('data-theme','dark'); localStorage.setItem('ers-theme','dark');</script>
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
                    <button class="hero-btn" onclick="window.location.reload()">
                        <i class="fas fa-rotate"></i>
                        Refresh Panel
                    </button>
                </div>
            </section>

            <section class="dispatcher-metrics">
                <article class="metric-block pending">
                    <div class="metric-label">Pending Incidents</div>
                    <div class="metric-value"><?php echo (int)$pending_incidents; ?></div>
                    <a href="incident.php" class="metric-link">Review queue</a>
                </article>
                <article class="metric-block active">
                    <div class="metric-label">Active Dispatches</div>
                    <div class="metric-value"><?php echo (int)$active_dispatches; ?></div>
                    <a href="dispatch.php" class="metric-link">Open dispatch center</a>
                </article>
                <article class="metric-block available">
                    <div class="metric-label">Available Units</div>
                    <div class="metric-value"><?php echo (int)$available_units; ?></div>
                    <a href="resources.php" class="metric-link">View resources</a>
                </article>
                <article class="metric-block field">
                    <div class="metric-label">Units In Field</div>
                    <div class="metric-value"><?php echo (int)$units_in_field; ?></div>
                    <a href="gps.php" class="metric-link">Track positions</a>
                </article>
                <article class="metric-block calls">
                    <div class="metric-label">Calls Today</div>
                    <div class="metric-value"><?php echo (int)$today_calls; ?></div>
                    <a href="call.php" class="metric-link">Open call logs</a>
                </article>
                <article class="metric-block response">
                    <div class="metric-label">Avg Response (7d)</div>
                    <div class="metric-value"><?php echo number_format($avg_response_min, 1); ?>m</div>
                    <a href="review.php" class="metric-link">View reviews</a>
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
                                            <a href="dispatch.php" class="btn-queue primary">Dispatch</a>
                                            <a href="incident.php" class="btn-queue">Details</a>
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
                    <div class="unit-list">
                        <?php if (empty($unit_items)): ?>
                            <div class="empty-state">No units found.</div>
                        <?php else: ?>
                            <?php foreach ($unit_items as $unit): ?>
                                <?php $status = strtolower((string)($unit['status'] ?? 'unknown')); ?>
                                <article class="unit-item">
                                    <div class="unit-main">
                                        <div class="unit-name"><?php echo htmlspecialchars((string)($unit['identifier'] ?? 'Unit')); ?></div>
                                        <div class="unit-type"><?php echo htmlspecialchars(ucfirst((string)($unit['unit_type'] ?? 'Responder'))); ?></div>
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
                        <a href="dispatch.php" class="btn-queue primary">Open Dispatch Board</a>
                        <a href="gps.php" class="btn-queue">Live GPS</a>
                    </div>
                </aside>
            </section>

            <section class="dispatcher-bottom-grid">
                <div class="panel mix-panel">
                    <div class="panel-header-row">
                        <h2><i class="fas fa-chart-simple"></i> Active Mix By Type</h2>
                    </div>
                    <div class="mix-list">
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
                    <div class="activity-list">
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
    document.addEventListener('DOMContentLoaded', function () {
        const clockEl = document.getElementById('liveClock');
        if (clockEl) {
            setInterval(function () {
                const now = new Date();
                clockEl.textContent = now.toLocaleString();
            }, 1000);
        }

        const filters = document.querySelectorAll('.queue-filter');
        const items = document.querySelectorAll('.queue-item');
        filters.forEach(function (btn) {
            btn.addEventListener('click', function () {
                const wanted = btn.getAttribute('data-priority');
                filters.forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                items.forEach(function (item) {
                    if (wanted === 'all' || item.getAttribute('data-priority') === wanted) {
                        item.style.display = '';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        });
    });
    </script>
</body>
</html>

