<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit;
}

if (!in_array(current_session_role(), ['admin', 'dispatcher'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Access denied']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

function ers_resource_action_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1");
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ers_resource_action_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ers_resource_action_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `resource_action_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `resource_source` VARCHAR(120) NOT NULL,
            `resource_id` BIGINT UNSIGNED NOT NULL,
            `resource_name` VARCHAR(200) DEFAULT NULL,
            `action` VARCHAR(80) NOT NULL,
            `status` VARCHAR(80) DEFAULT NULL,
            `details` LONGTEXT DEFAULT NULL,
            `created_by` INT UNSIGNED DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_resource_action_resource` (`resource_source`, `resource_id`),
            KEY `idx_resource_action_action` (`action`),
            KEY `idx_resource_action_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ers_resource_action_clean_source(string $source): string
{
    $source = strtolower(trim($source));
    return in_array($source, ['resource_records', 'admin_resources', 'units', 'staff', 'resources'], true) ? $source : '';
}

function ers_resource_action_log(PDO $pdo, string $source, int $resourceId, string $name, string $action, string $status, array $details): int
{
    ers_resource_action_ensure_table($pdo);
    $stmt = $pdo->prepare(
        "INSERT INTO resource_action_logs
            (resource_source, resource_id, resource_name, action, status, details, created_by, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW())"
    );
    $stmt->execute([
        $source,
        $resourceId,
        $name !== '' ? $name : null,
        $action,
        $status,
        json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        (int)($_SESSION['user_id'] ?? 0) ?: null,
    ]);
    return (int)$pdo->lastInsertId();
}

function ers_resource_action_update_assignment(PDO $pdo, string $source, int $resourceId, string $assignment): void
{
    if ($assignment === '') {
        return;
    }

    if (in_array($source, ['resource_records', 'admin_resources'], true)
        && ers_resource_action_table_exists($pdo, $source)
        && ers_resource_action_column_exists($pdo, $source, 'assignment')) {
        $sets = ['assignment = ?'];
        $params = [$assignment];
        if (ers_resource_action_column_exists($pdo, $source, 'status')) {
            $sets[] = "status = 'in_use'";
        }
        if (ers_resource_action_column_exists($pdo, $source, 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }
        $params[] = $resourceId;
        $stmt = $pdo->prepare("UPDATE `{$source}` SET " . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
        return;
    }

    if ($source === 'resources' && ers_resource_action_table_exists($pdo, 'resources')) {
        $sets = ["status = 'deployed'"];
        $params = [];
        if (ers_resource_action_column_exists($pdo, 'resources', 'notes')) {
            $sets[] = "notes = TRIM(CONCAT(COALESCE(notes, ''), CASE WHEN COALESCE(notes, '') = '' THEN '' ELSE '\n' END, ?))";
            $params[] = 'Assigned to: ' . $assignment;
        }
        if (ers_resource_action_column_exists($pdo, 'resources', 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }
        $params[] = $resourceId;
        $stmt = $pdo->prepare('UPDATE resources SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $stmt->execute($params);
        return;
    }

    if ($source === 'staff' && ers_resource_action_table_exists($pdo, 'staff')) {
        $sets = [];
        $params = [];
        if (ers_resource_action_column_exists($pdo, 'staff', 'status')) {
            $sets[] = "status = 'on_duty'";
        }
        if (ers_resource_action_column_exists($pdo, 'staff', 'updated_at')) {
            $sets[] = 'updated_at = NOW()';
        }
        if ($sets !== []) {
            $params[] = $resourceId;
            $stmt = $pdo->prepare('UPDATE staff SET ' . implode(', ', $sets) . ' WHERE id = ?');
            $stmt->execute($params);
        }
    }
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$action = strtolower(trim((string)($input['action'] ?? '')));
$source = ers_resource_action_clean_source((string)($input['resource_source'] ?? $input['source'] ?? ''));
$resourceId = (int)($input['resource_id'] ?? 0);
$resourceName = substr(trim((string)($input['resource_name'] ?? '')), 0, 200);

$allowed = ['schedule', 'assign', 'check', 'calibrate', 'service', 'contact'];
if (!in_array($action, $allowed, true) || $source === '' || $resourceId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Invalid resource action']);
    exit;
}

try {
    $details = [
        'date' => trim((string)($input['date'] ?? '')),
        'shift' => trim((string)($input['shift'] ?? '')),
        'assignment' => trim((string)($input['assignment'] ?? '')),
        'notes' => trim((string)($input['notes'] ?? '')),
        'phone' => trim((string)($input['phone'] ?? '')),
    ];

    if ($action === 'assign') {
        if ($details['assignment'] === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Assignment is required']);
            exit;
        }
        ers_resource_action_update_assignment($pdo, $source, $resourceId, $details['assignment']);
    }

    if ($action === 'schedule' && ($details['date'] === '' || $details['shift'] === '')) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Schedule date and shift are required']);
        exit;
    }

    $status = match ($action) {
        'schedule' => 'scheduled',
        'assign' => 'assigned',
        'check' => 'checked',
        'calibrate' => 'calibration_started',
        'service' => 'service_logged',
        'contact' => 'contact_logged',
        default => 'logged',
    };

    $logId = ers_resource_action_log($pdo, $source, $resourceId, $resourceName, $action, $status, $details);

    echo json_encode([
        'ok' => true,
        'log_id' => $logId,
        'status' => $status,
        'message' => ucfirst(str_replace('_', ' ', $status)),
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to record resource action']);
}
