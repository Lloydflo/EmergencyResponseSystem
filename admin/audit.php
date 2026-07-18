<?php
$rootDir = dirname(__DIR__);
require_once $rootDir . '/includes/auth.php';
require_role('admin', 'admin/audit.php');
require_once $rootDir . '/includes/db.php';

$pageTitle = 'Audit Log';
$adminName = $_SESSION['user_name'] ?? 'Admin';

function audit_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function audit_label(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return 'System';
    }
    return ucwords(str_replace(['_', '-'], ' ', $value));
}

function audit_format_date(?string $value): string {
    $value = trim((string)$value);
    if ($value === '') {
        return 'N/A';
    }

    try {
        return (new DateTime($value))->format('M d, Y h:i A');
    } catch (Throwable $e) {
        return $value;
    }
}

$search = trim((string)($_GET['q'] ?? ''));
$entityFilter = trim((string)($_GET['entity'] ?? ''));
$actionFilter = trim((string)($_GET['action'] ?? ''));
$dateFilter = trim((string)($_GET['date'] ?? ''));
$allowedDateFilters = ['today', '7days', '30days'];
if (!in_array($dateFilter, $allowedDateFilters, true)) {
    $dateFilter = '';
}

$auditRows = [];
$entityOptions = [];
$actionOptions = [];
$stats = [
    'total' => 0,
    'today' => 0,
    'users' => 0,
    'latest' => null,
];
$matchingCount = 0;
$loadError = '';

try {
    $pdo = get_db_connection();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $entityOptions = $pdo->query("SELECT DISTINCT entity_type FROM activity_log WHERE entity_type <> '' ORDER BY entity_type ASC")->fetchAll(PDO::FETCH_COLUMN);
    $actionOptions = $pdo->query("SELECT DISTINCT action FROM activity_log WHERE action <> '' ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

    $statsRow = $pdo->query(
        "SELECT
            COUNT(*) AS total,
            SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today,
            COUNT(DISTINCT user_id) AS users,
            MAX(created_at) AS latest
         FROM activity_log"
    )->fetch(PDO::FETCH_ASSOC) ?: [];

    $stats['total'] = (int)($statsRow['total'] ?? 0);
    $stats['today'] = (int)($statsRow['today'] ?? 0);
    $stats['users'] = (int)($statsRow['users'] ?? 0);
    $stats['latest'] = $statsRow['latest'] ?? null;

    $where = [];
    $params = [];

    if ($search !== '') {
        $searchColumns = ['a.action', 'a.entity_type', 'a.details', 'u.name', 'u.email'];
        $searchParts = [];
        foreach ($searchColumns as $index => $column) {
            $paramName = ':search' . $index;
            $searchParts[] = $column . ' LIKE ' . $paramName;
            $params[$paramName] = '%' . $search . '%';
        }
        $where[] = '(' . implode(' OR ', $searchParts) . ')';
    }

    if ($entityFilter !== '') {
        $where[] = "a.entity_type = :entity";
        $params[':entity'] = $entityFilter;
    }

    if ($actionFilter !== '') {
        $where[] = "a.action = :action";
        $params[':action'] = $actionFilter;
    }

    if ($dateFilter === 'today') {
        $where[] = "DATE(a.created_at) = CURDATE()";
    } elseif ($dateFilter === '7days') {
        $where[] = "a.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    } elseif ($dateFilter === '30days') {
        $where[] = "a.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $pdo->prepare(
        "SELECT COUNT(*)
         FROM activity_log a
         LEFT JOIN users u ON u.id = a.user_id
         $whereSql"
    );
    $countStmt->execute($params);
    $matchingCount = (int)$countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT
            a.id,
            a.user_id,
            a.action,
            a.entity_type,
            a.entity_id,
            a.details,
            a.created_at,
            u.name AS user_name,
            u.email AS user_email,
            u.role AS user_role
         FROM activity_log a
         LEFT JOIN users u ON u.id = a.user_id
         $whereSql
         ORDER BY a.created_at DESC, a.id DESC
         LIMIT 200"
    );
    $stmt->execute($params);
    $auditRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $loadError = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo audit_h($pageTitle); ?></title>
    <?php include $rootDir . '/includes/theme-init.php'; ?>
    <base href="../">
    <link rel="icon" type="image/x-icon" href="images/favicon.ico">
    <link rel="stylesheet" href="css/global.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="css/sidebar.css">
    <link rel="stylesheet" href="css/admin-header.css">
    <link rel="stylesheet" href="css/sidebar-footer.css">
    <style>
        :root {
            --audit-bg: #f4f7fb;
            --audit-card: #ffffff;
            --audit-text: #172033;
            --audit-muted: #64748b;
            --audit-border: #dbe4ee;
            --audit-primary: #0f766e;
            --audit-primary-dark: #115e59;
            --audit-soft: #eef6f5;
            --audit-chip: #e2e8f0;
            --audit-danger: #b91c1c;
        }

        html[data-theme="dark"] {
            --audit-bg: #0f172a;
            --audit-card: #111827;
            --audit-text: #f8fafc;
            --audit-muted: #94a3b8;
            --audit-border: #334155;
            --audit-primary: #2dd4bf;
            --audit-primary-dark: #14b8a6;
            --audit-soft: #0f2525;
            --audit-chip: #1e293b;
            --audit-danger: #fca5a5;
        }

        .main-content {
            background:
                radial-gradient(circle at 100% 0%, rgba(20, 184, 166, 0.12), transparent 34%),
                var(--audit-bg);
            padding: 4rem 1.5rem 3rem;
            flex: 1 0 auto;
        }

        .audit-shell {
            display: grid;
            gap: 1rem;
        }

        .audit-head {
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 1rem;
        }

        .audit-head h1 {
            margin: 0;
            color: var(--audit-text);
            font-size: 1.72rem;
            line-height: 1.2;
        }

        .audit-head p {
            margin: 0.35rem 0 0;
            color: var(--audit-muted);
            font-size: 0.94rem;
        }

        .audit-updated {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            border: 1px solid var(--audit-border);
            background: var(--audit-card);
            color: var(--audit-muted);
            border-radius: 999px;
            padding: 0.52rem 0.72rem;
            font-size: 0.82rem;
            font-weight: 700;
            white-space: nowrap;
        }

        .audit-stats {
            display: grid;
            grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 0.75rem;
        }

        .audit-stat {
            background: var(--audit-card);
            border: 1px solid var(--audit-border);
            border-radius: 12px;
            padding: 0.95rem;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.05);
        }

        .audit-stat span {
            display: block;
            color: var(--audit-muted);
            font-size: 0.76rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .audit-stat strong {
            display: block;
            margin-top: 0.34rem;
            color: var(--audit-text);
            font-size: 1.45rem;
            line-height: 1;
        }

        .audit-card {
            background: var(--audit-card);
            border: 1px solid var(--audit-border);
            border-radius: 12px;
            box-shadow: 0 6px 18px rgba(15, 23, 42, 0.06);
            overflow: hidden;
        }

        .audit-toolbar {
            display: grid;
            grid-template-columns: minmax(220px, 1.4fr) repeat(3, minmax(150px, 0.7fr)) auto;
            gap: 0.65rem;
            align-items: center;
            padding: 0.85rem;
            background: var(--audit-soft);
            border-bottom: 1px solid var(--audit-border);
        }

        .audit-input,
        .audit-select {
            width: 100%;
            min-height: 42px;
            border: 1px solid #cbd5e1;
            border-radius: 9px;
            background: #fff;
            color: #172033;
            font: inherit;
            font-size: 0.88rem;
            padding: 0.62rem 0.72rem;
        }

        html[data-theme="dark"] .audit-input,
        html[data-theme="dark"] .audit-select {
            background: #0f172a;
            border-color: #334155;
            color: #f8fafc;
        }

        .audit-input:focus,
        .audit-select:focus {
            outline: none;
            border-color: var(--audit-primary);
            box-shadow: 0 0 0 3px rgba(20, 184, 166, 0.14);
        }

        .audit-actions {
            display: flex;
            gap: 0.45rem;
        }

        .audit-btn {
            min-height: 42px;
            border: 1px solid var(--audit-border);
            border-radius: 9px;
            background: #fff;
            color: #172033;
            padding: 0 0.78rem;
            font: inherit;
            font-size: 0.86rem;
            font-weight: 800;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.42rem;
            white-space: nowrap;
            text-decoration: none;
        }

        .audit-btn.primary {
            border-color: var(--audit-primary);
            background: var(--audit-primary);
            color: #fff;
        }

        .audit-btn.primary:hover {
            background: var(--audit-primary-dark);
        }

        html[data-theme="dark"] .audit-btn {
            background: #0f172a;
            border-color: #334155;
            color: #e5e7eb;
        }

        html[data-theme="dark"] .audit-btn.primary {
            background: #0f766e;
            border-color: #0f766e;
            color: #fff;
        }

        .audit-summary {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 0.78rem 0.9rem;
            border-bottom: 1px solid var(--audit-border);
            color: var(--audit-muted);
            font-size: 0.84rem;
        }

        .audit-summary strong {
            color: var(--audit-text);
        }

        .audit-table-wrap {
            overflow: auto;
            max-height: 640px;
        }

        .audit-table {
            width: 100%;
            min-width: 1040px;
            border-collapse: collapse;
        }

        .audit-table th,
        .audit-table td {
            padding: 0.78rem 0.75rem;
            border-bottom: 1px solid #eef2f7;
            text-align: left;
            vertical-align: top;
            font-size: 0.86rem;
        }

        html[data-theme="dark"] .audit-table th,
        html[data-theme="dark"] .audit-table td {
            border-bottom-color: #1f2937;
        }

        .audit-table th {
            position: sticky;
            top: 0;
            z-index: 1;
            background: #f8fafc;
            color: #334155;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-size: 0.73rem;
        }

        html[data-theme="dark"] .audit-table th {
            background: #111827;
            color: #cbd5e1;
        }

        .audit-table tbody tr:hover td {
            background: #f8fbff;
        }

        html[data-theme="dark"] .audit-table tbody tr:hover td {
            background: #172033;
        }

        .audit-user {
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }

        .audit-user-icon {
            width: 20px;
            height: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex: 0 0 auto;
            color: var(--audit-primary);
            font-size: 0.92rem;
        }

        .audit-user strong,
        .audit-entity strong {
            display: block;
            color: var(--audit-text);
            font-size: 0.86rem;
        }

        .audit-user span,
        .audit-entity span {
            display: block;
            margin-top: 0.16rem;
            color: var(--audit-muted);
            font-size: 0.76rem;
        }

        .audit-chip {
            display: inline-flex;
            align-items: center;
            border-radius: 999px;
            background: var(--audit-chip);
            color: var(--audit-text);
            padding: 0.25rem 0.56rem;
            font-size: 0.74rem;
            font-weight: 800;
            white-space: nowrap;
        }

        .audit-details {
            color: var(--audit-text);
            line-height: 1.45;
            max-width: 520px;
            overflow-wrap: anywhere;
        }

        .audit-id {
            color: var(--audit-muted);
            font-weight: 800;
        }

        .audit-empty,
        .audit-error {
            padding: 2rem 1rem;
            text-align: center;
            color: var(--audit-muted);
        }

        .audit-error {
            color: var(--audit-danger);
            font-weight: 700;
        }

        @media (max-width: 1100px) {
            .audit-toolbar,
            .audit-stats {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }

            .audit-actions {
                grid-column: 1 / -1;
            }
        }

        @media (max-width: 720px) {
            .main-content {
                padding: 1rem 0.75rem 2rem;
            }

            .audit-head {
                align-items: flex-start;
                flex-direction: column;
            }

            .audit-toolbar,
            .audit-stats {
                grid-template-columns: 1fr;
            }

            .audit-actions {
                flex-direction: column;
            }

            .audit-btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <?php include $rootDir . '/includes/sidebar.php'; ?>
    <?php include $rootDir . '/includes/admin-header.php'; ?>

    <main class="main-content">
        <div class="main-container audit-shell">
            <section class="audit-head">
                <div>
                    <h1>Audit Log</h1>
                    <p>Hi <?php echo audit_h($adminName); ?>. Review recent system, user, dispatch, resource, and inter-agency activity.</p>
                </div>
                <div class="audit-updated">
                    <i class="fas fa-clock"></i>
                    <span>Latest: <?php echo audit_h(audit_format_date($stats['latest'])); ?></span>
                </div>
            </section>

            <section class="audit-stats" aria-label="Audit summary">
                <div class="audit-stat">
                    <span>Total events</span>
                    <strong><?php echo number_format($stats['total']); ?></strong>
                </div>
                <div class="audit-stat">
                    <span>Today</span>
                    <strong><?php echo number_format($stats['today']); ?></strong>
                </div>
                <div class="audit-stat">
                    <span>Known users</span>
                    <strong><?php echo number_format($stats['users']); ?></strong>
                </div>
                <div class="audit-stat">
                    <span>Shown</span>
                    <strong><?php echo number_format(count($auditRows)); ?></strong>
                </div>
            </section>

            <section class="audit-card">
                <form class="audit-toolbar" method="get" action="admin/audit.php">
                    <input
                        class="audit-input"
                        type="search"
                        name="q"
                        value="<?php echo audit_h($search); ?>"
                        placeholder="Search action, details, user, or email...">

                    <select class="audit-select" name="entity" aria-label="Filter by entity">
                        <option value="">All entities</option>
                        <?php foreach ($entityOptions as $entity): ?>
                            <option value="<?php echo audit_h($entity); ?>" <?php echo $entityFilter === $entity ? 'selected' : ''; ?>>
                                <?php echo audit_h(audit_label((string)$entity)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select class="audit-select" name="action" aria-label="Filter by action">
                        <option value="">All actions</option>
                        <?php foreach ($actionOptions as $action): ?>
                            <option value="<?php echo audit_h($action); ?>" <?php echo $actionFilter === $action ? 'selected' : ''; ?>>
                                <?php echo audit_h(audit_label((string)$action)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select class="audit-select" name="date" aria-label="Filter by date">
                        <option value="">Any date</option>
                        <option value="today" <?php echo $dateFilter === 'today' ? 'selected' : ''; ?>>Today</option>
                        <option value="7days" <?php echo $dateFilter === '7days' ? 'selected' : ''; ?>>Last 7 days</option>
                        <option value="30days" <?php echo $dateFilter === '30days' ? 'selected' : ''; ?>>Last 30 days</option>
                    </select>

                    <div class="audit-actions">
                        <button class="audit-btn primary" type="submit">
                            <i class="fas fa-filter"></i>
                            <span>Apply</span>
                        </button>
                        <a class="audit-btn" href="admin/audit.php">
                            <i class="fas fa-rotate-left"></i>
                            <span>Reset</span>
                        </a>
                    </div>
                </form>

                <div class="audit-summary">
                    <span><strong><?php echo number_format($matchingCount); ?></strong> matching event(s)</span>
                    <span>Showing latest 200 records</span>
                </div>

                <?php if ($loadError !== ''): ?>
                    <div class="audit-error">
                        Unable to load audit records: <?php echo audit_h($loadError); ?>
                    </div>
                <?php elseif (!$auditRows): ?>
                    <div class="audit-empty">
                        No audit events found.
                    </div>
                <?php else: ?>
                    <div class="audit-table-wrap">
                        <table class="audit-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Date &amp; Time</th>
                                    <th>User</th>
                                    <th>Action</th>
                                    <th>Entity</th>
                                    <th>Details</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($auditRows as $row): ?>
                                    <?php
                                    $userName = trim((string)($row['user_name'] ?? ''));
                                    $userEmail = trim((string)($row['user_email'] ?? ''));
                                    $userRole = trim((string)($row['user_role'] ?? ''));
                                    $displayUser = $userName !== '' ? $userName : 'System';
                                    $displayMeta = $userEmail !== ''
                                        ? $userEmail
                                        : ($userRole !== '' ? audit_label($userRole) : 'Automated event');
                                    $entityId = (int)($row['entity_id'] ?? 0);
                                    ?>
                                    <tr>
                                        <td class="audit-id">#<?php echo (int)($row['id'] ?? 0); ?></td>
                                        <td><?php echo audit_h(audit_format_date($row['created_at'] ?? null)); ?></td>
                                        <td>
                                            <div class="audit-user">
                                                <span class="audit-user-icon" aria-hidden="true">
                                                    <i class="fas <?php echo $displayUser === 'System' ? 'fa-gear' : 'fa-user'; ?>"></i>
                                                </span>
                                                <div>
                                                    <strong><?php echo audit_h($displayUser); ?></strong>
                                                    <span><?php echo audit_h($displayMeta); ?></span>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <span class="audit-chip"><?php echo audit_h(audit_label((string)($row['action'] ?? ''))); ?></span>
                                        </td>
                                        <td>
                                            <div class="audit-entity">
                                                <strong><?php echo audit_h(audit_label((string)($row['entity_type'] ?? ''))); ?></strong>
                                                <span><?php echo $entityId > 0 ? ('Record #' . $entityId) : 'No linked record'; ?></span>
                                            </div>
                                        </td>
                                        <td class="audit-details">
                                            <?php echo audit_h(trim((string)($row['details'] ?? '')) !== '' ? (string)$row['details'] : 'No details provided.'); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <?php include $rootDir . '/includes/admin-footer.php'; ?>
</body>
</html>
