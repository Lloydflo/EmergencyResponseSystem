<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/geocode_helper.php';
require_once __DIR__ . '/../includes/vehicle_resource_units.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activity_log.php';

if (!defined('RESOURCE_RECORDS_TABLE')) {
    define('RESOURCE_RECORDS_TABLE', 'resource_records');
}
if (!defined('RESOURCE_RECORDS_ARCHIVE_TABLE')) {
    define('RESOURCE_RECORDS_ARCHIVE_TABLE', 'resource_records_archive');
}
if (!defined('LEGACY_ADMIN_RESOURCES_TABLE')) {
    define('LEGACY_ADMIN_RESOURCES_TABLE', 'admin_resources');
}
if (!defined('LEGACY_ADMIN_RESOURCES_ARCHIVE_TABLE')) {
    define('LEGACY_ADMIN_RESOURCES_ARCHIVE_TABLE', 'admin_resources_archive');
}

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$actor = get_logged_in_user();
if (!$actor || canonical_role((string)($actor['role'] ?? '')) !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

function table_column_exists(PDO $pdo, string $tableName, string $columnName): bool {
    $stmt = $pdo->prepare(
        "SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    $stmt->execute([$tableName, $columnName]);
    return (bool)$stmt->fetchColumn();
}

function table_exists(PDO $pdo, string $tableName): bool {
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

function add_column_if_missing(PDO $pdo, string $tableName, string $columnName, string $definition): void {
    if (table_column_exists($pdo, $tableName, $columnName)) {
        return;
    }

    $pdo->exec("ALTER TABLE `$tableName` ADD COLUMN `$columnName` $definition");
}

function ensure_resource_records_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `" . RESOURCE_RECORDS_TABLE . "` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(50) NOT NULL,
            `name` VARCHAR(200) NOT NULL,
            `category` ENUM('vehicles','personnel','equipment') NOT NULL,
            `status` ENUM('available','in_use','maintenance','offline') NOT NULL DEFAULT 'available',
            `location` VARCHAR(255) NOT NULL,
            `latitude` DECIMAL(10,7) DEFAULT NULL,
            `longitude` DECIMAL(10,7) DEFAULT NULL,
            `driver_name` VARCHAR(150) DEFAULT NULL,
            `plate_number` VARCHAR(50) DEFAULT NULL,
            `position_title` VARCHAR(150) DEFAULT NULL,
            `assignment` VARCHAR(255) DEFAULT NULL,
            `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
            `notes` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_resource_records_code` (`code`),
            KEY `idx_resource_records_category` (`category`),
            KEY `idx_resource_records_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    add_column_if_missing($pdo, RESOURCE_RECORDS_TABLE, 'driver_name', "VARCHAR(150) DEFAULT NULL AFTER `location`");
    add_column_if_missing($pdo, RESOURCE_RECORDS_TABLE, 'latitude', "DECIMAL(10,7) DEFAULT NULL AFTER `location`");
    add_column_if_missing($pdo, RESOURCE_RECORDS_TABLE, 'longitude', "DECIMAL(10,7) DEFAULT NULL AFTER `latitude`");
    add_column_if_missing($pdo, RESOURCE_RECORDS_TABLE, 'plate_number', "VARCHAR(50) DEFAULT NULL AFTER `driver_name`");
    add_column_if_missing($pdo, RESOURCE_RECORDS_TABLE, 'position_title', "VARCHAR(150) DEFAULT NULL AFTER `plate_number`");
    add_column_if_missing($pdo, RESOURCE_RECORDS_TABLE, 'quantity', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `assignment`");
}

function ensure_resource_records_archive_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `" . RESOURCE_RECORDS_ARCHIVE_TABLE . "` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `resource_id` BIGINT UNSIGNED NOT NULL,
            `code` VARCHAR(50) NOT NULL,
            `name` VARCHAR(200) NOT NULL,
            `category` ENUM('vehicles','personnel','equipment') NOT NULL,
            `status` ENUM('available','in_use','maintenance','offline') NOT NULL DEFAULT 'available',
            `location` VARCHAR(255) NOT NULL,
            `latitude` DECIMAL(10,7) DEFAULT NULL,
            `longitude` DECIMAL(10,7) DEFAULT NULL,
            `driver_name` VARCHAR(150) DEFAULT NULL,
            `plate_number` VARCHAR(50) DEFAULT NULL,
            `position_title` VARCHAR(150) DEFAULT NULL,
            `assignment` VARCHAR(255) DEFAULT NULL,
            `quantity` INT UNSIGNED NOT NULL DEFAULT 1,
            `notes` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            `deleted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_resource_records_archive_resource_id` (`resource_id`),
            KEY `idx_resource_records_archive_deleted_at` (`deleted_at`),
            KEY `idx_resource_records_archive_category` (`category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    add_column_if_missing($pdo, RESOURCE_RECORDS_ARCHIVE_TABLE, 'driver_name', "VARCHAR(150) DEFAULT NULL AFTER `location`");
    add_column_if_missing($pdo, RESOURCE_RECORDS_ARCHIVE_TABLE, 'latitude', "DECIMAL(10,7) DEFAULT NULL AFTER `location`");
    add_column_if_missing($pdo, RESOURCE_RECORDS_ARCHIVE_TABLE, 'longitude', "DECIMAL(10,7) DEFAULT NULL AFTER `latitude`");
    add_column_if_missing($pdo, RESOURCE_RECORDS_ARCHIVE_TABLE, 'plate_number', "VARCHAR(50) DEFAULT NULL AFTER `driver_name`");
    add_column_if_missing($pdo, RESOURCE_RECORDS_ARCHIVE_TABLE, 'position_title', "VARCHAR(150) DEFAULT NULL AFTER `plate_number`");
    add_column_if_missing($pdo, RESOURCE_RECORDS_ARCHIVE_TABLE, 'quantity', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `assignment`");
}

function ensure_legacy_resource_quantity_columns(PDO $pdo): void {
    if (table_exists($pdo, LEGACY_ADMIN_RESOURCES_TABLE)) {
        add_column_if_missing($pdo, LEGACY_ADMIN_RESOURCES_TABLE, 'quantity', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `assignment`");
    }
    if (table_exists($pdo, LEGACY_ADMIN_RESOURCES_ARCHIVE_TABLE)) {
        add_column_if_missing($pdo, LEGACY_ADMIN_RESOURCES_ARCHIVE_TABLE, 'quantity', "INT UNSIGNED NOT NULL DEFAULT 1 AFTER `assignment`");
    }
}

function migrate_legacy_admin_resource_tables(PDO $pdo): void {
    if (!table_exists($pdo, LEGACY_ADMIN_RESOURCES_TABLE) || !table_exists($pdo, RESOURCE_RECORDS_TABLE)) {
        return;
    }

    $newCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM `" . RESOURCE_RECORDS_TABLE . "`")->fetch()['c'];
    $legacyCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM `" . LEGACY_ADMIN_RESOURCES_TABLE . "`")->fetch()['c'];

    if ($newCount === 0 && $legacyCount > 0) {
        $pdo->exec(
            "INSERT INTO `" . RESOURCE_RECORDS_TABLE . "` (
                id, code, name, category, status, location, driver_name, plate_number, position_title, assignment, quantity, notes, created_at, updated_at
             )
             SELECT
                id, code, name, category, status, location, driver_name, plate_number, position_title, assignment, quantity, notes, created_at, updated_at
             FROM `" . LEGACY_ADMIN_RESOURCES_TABLE . "`"
        );
    }

    if (!table_exists($pdo, LEGACY_ADMIN_RESOURCES_ARCHIVE_TABLE) || !table_exists($pdo, RESOURCE_RECORDS_ARCHIVE_TABLE)) {
        return;
    }

    $newArchiveCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM `" . RESOURCE_RECORDS_ARCHIVE_TABLE . "`")->fetch()['c'];
    $legacyArchiveCount = (int)$pdo->query("SELECT COUNT(*) AS c FROM `" . LEGACY_ADMIN_RESOURCES_ARCHIVE_TABLE . "`")->fetch()['c'];

    if ($newArchiveCount === 0 && $legacyArchiveCount > 0) {
        $pdo->exec(
            "INSERT INTO `" . RESOURCE_RECORDS_ARCHIVE_TABLE . "` (
                id, resource_id, code, name, category, status, location, driver_name, plate_number, position_title, assignment, quantity, notes, created_at, updated_at, deleted_at
             )
             SELECT
                id, resource_id, code, name, category, status, location, driver_name, plate_number, position_title, assignment, quantity, notes, created_at, updated_at, deleted_at
             FROM `" . LEGACY_ADMIN_RESOURCES_ARCHIVE_TABLE . "`"
        );
    }
}

function parse_payload(): array {
    $raw = file_get_contents('php://input');
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) return $decoded;
    return $_POST;
}

function clean_text($value): string {
    return trim((string)$value);
}

function normalize_payload(array $payload): array {
    $category = strtolower(clean_text($payload['category'] ?? ''));
    $status = strtolower(clean_text($payload['status'] ?? ''));
    $code = strtoupper(clean_text($payload['code'] ?? ''));
    $name = clean_text($payload['name'] ?? '');
    $location = clean_text($payload['location'] ?? '');
    $latitude = normalize_coordinate($payload['latitude'] ?? ($payload['lat'] ?? null), -90, 90);
    $longitude = normalize_coordinate($payload['longitude'] ?? ($payload['lng'] ?? ($payload['lon'] ?? null)), -180, 180);
    $driverName = clean_text($payload['driverName'] ?? '');
    $plateNumber = strtoupper(clean_text($payload['plateNumber'] ?? ''));
    $positionTitle = clean_text($payload['positionTitle'] ?? '');
    $assignment = clean_text($payload['assignment'] ?? '');
    $quantity = isset($payload['quantity']) ? (int)$payload['quantity'] : 1;
    $notes = clean_text($payload['notes'] ?? '');

    $allowedCategories = ['vehicles', 'personnel', 'equipment'];
    if (!in_array($category, $allowedCategories, true)) {
        throw new InvalidArgumentException('Invalid category');
    }

    $allowedStatuses = ['available', 'in_use', 'maintenance', 'offline'];
    if (!in_array($status, $allowedStatuses, true)) {
        throw new InvalidArgumentException('Invalid status');
    }

    if ($code === '' || strlen($code) > 50) {
        throw new InvalidArgumentException('Resource ID is required and must be 50 chars or less');
    }
    if ($name === '' || strlen($name) > 200) {
        throw new InvalidArgumentException('Resource name is required and must be 200 chars or less');
    }
    if ($category === 'vehicles') {
        $location = '';
        $latitude = null;
        $longitude = null;
    } elseif ($location === '' || strlen($location) > 255) {
        throw new InvalidArgumentException('Location is required and must be 255 chars or less');
    }
    if (strlen($location) > 255) {
        throw new InvalidArgumentException('Location must be 255 chars or less');
    }
    if (strlen($assignment) > 255) {
        throw new InvalidArgumentException('Assignment must be 255 chars or less');
    }
    if ($quantity < 1 || $quantity > 9999) {
        throw new InvalidArgumentException('Equipment quantity must be between 1 and 9999');
    }
    if (strlen($driverName) > 150) {
        throw new InvalidArgumentException('Driver name must be 150 chars or less');
    }
    if (strlen($plateNumber) > 50) {
        throw new InvalidArgumentException('Plate number must be 50 chars or less');
    }
    if (strlen($positionTitle) > 150) {
        throw new InvalidArgumentException('Position must be 150 chars or less');
    }
    if (strlen($notes) > 2000) {
        throw new InvalidArgumentException('Notes must be 2000 chars or less');
    }

    if ($category !== 'vehicles' && ($latitude === null || $longitude === null) && $location !== '') {
        $geocoded = ers_geocode_location_to_coordinates($location);
        if ($geocoded !== null) {
            $latitude = (float)$geocoded[0];
            $longitude = (float)$geocoded[1];
        }
    }

    return [
        'code' => $code,
        'name' => $name,
        'category' => $category,
        'status' => $status,
        'location' => $location,
        'latitude' => $latitude,
        'longitude' => $longitude,
        'driverName' => $driverName,
        'plateNumber' => $plateNumber,
        'positionTitle' => $positionTitle,
        'assignment' => $assignment,
        'quantity' => $category === 'equipment' ? $quantity : 1,
        'notes' => $notes
    ];
}

function normalize_coordinate($value, float $min, float $max): ?float {
    if ($value === null || $value === '') {
        return null;
    }
    if (!is_numeric((string)$value)) {
        return null;
    }
    $number = (float)$value;
    if ($number < $min || $number > $max) {
        return null;
    }
    return $number;
}

function log_resource_added_notification(array $payload, int $resourceId, array $actor): void {
    $actorName = trim((string)($actor['name'] ?? $actor['username'] ?? $actor['email'] ?? 'Admin'));
    $details = [
        'code' => (string)($payload['code'] ?? ''),
        'name' => (string)($payload['name'] ?? ''),
        'category' => (string)($payload['category'] ?? ''),
        'status' => (string)($payload['status'] ?? ''),
        'quantity' => max(1, (int)($payload['quantity'] ?? 1)),
        'added_by' => $actorName,
        'message' => trim(($actorName !== '' ? $actorName : 'Admin') . ' added ' . (string)($payload['name'] ?? 'a resource')),
    ];

    log_activity_event(
        isset($actor['id']) ? (int)$actor['id'] : null,
        'resource_added',
        'resource',
        $resourceId,
        json_encode($details, JSON_UNESCAPED_UNICODE)
    );
}

function build_incident_assignment_details(array $row): string {
    $incidentId = (int)($row['incident_id'] ?? 0);
    $dispatchId = (int)($row['dispatch_id'] ?? $row['id'] ?? 0);
    $referenceNo = trim((string)($row['reference_no'] ?? ''));
    $title = trim((string)($row['incident_title'] ?? $row['dispatch_name'] ?? ''));
    $type = trim((string)($row['incident_type'] ?? $row['vehicle'] ?? ''));
    $priority = trim((string)($row['incident_priority'] ?? $row['dispatch_priority'] ?? ''));
    $location = trim((string)($row['incident_location'] ?? $row['dispatch_location'] ?? ''));

    if ($referenceNo !== '') {
        $label = 'Incident ' . $referenceNo;
    } elseif ($incidentId > 0) {
        $label = 'Incident #' . $incidentId;
    } elseif ($dispatchId > 0) {
        $label = 'Dispatch #' . $dispatchId;
    } else {
        $label = 'Assigned incident';
    }

    if ($title !== '') {
        $label .= ' - ' . $title;
    }

    $parts = [$label];
    if ($type !== '') {
        $parts[] = 'Type: ' . $type;
    }
    if ($priority !== '') {
        $parts[] = 'Priority: ' . ucfirst(strtolower($priority));
    }
    if ($location !== '') {
        $parts[] = 'Location: ' . $location;
    }

    return implode(' | ', $parts);
}

function load_active_unit_incident_assignment_map(PDO $pdo): array {
    if (
        !table_exists($pdo, 'dispatch_operator_records')
        || !table_column_exists($pdo, 'dispatch_operator_records', 'status')
    ) {
        return [];
    }

    $usersJoin = table_exists($pdo, 'users') && table_column_exists($pdo, 'dispatch_operator_records', 'assigned_to')
        ? 'LEFT JOIN `users` u ON u.id = d.assigned_to'
        : '';
    $responderUnitCodeSelect = $usersJoin !== '' && table_column_exists($pdo, 'users', 'unit_code')
        ? 'u.unit_code'
        : 'NULL';
    $hasIncidentId = table_column_exists($pdo, 'dispatch_operator_records', 'incident_id');
    $incidentsJoin = table_exists($pdo, 'incidents') && $hasIncidentId
        ? 'LEFT JOIN `incidents` i ON i.id = d.incident_id'
        : '';
    $incidentIdSelect = $hasIncidentId ? 'd.incident_id' : 'NULL';
    $referenceNoSelect = $incidentsJoin !== '' ? 'i.reference_no' : 'NULL';
    $incidentTitleSelect = $incidentsJoin !== '' ? 'i.title' : 'NULL';
    $incidentTypeSelect = $incidentsJoin !== '' ? 'i.type' : 'NULL';
    $incidentPrioritySelect = $incidentsJoin !== '' ? 'i.priority' : 'NULL';
    $incidentLocationSelect = $incidentsJoin !== '' ? 'i.location_address' : 'NULL';
    $assignedUnitCodeSelect = table_column_exists($pdo, 'dispatch_operator_records', 'assigned_unit_code')
        ? 'd.assigned_unit_code'
        : 'NULL';
    $dispatchNameSelect = table_column_exists($pdo, 'dispatch_operator_records', 'name') ? 'd.name' : 'NULL';
    $vehicleSelect = table_column_exists($pdo, 'dispatch_operator_records', 'vehicle') ? 'd.vehicle' : 'NULL';
    $locationSelect = table_column_exists($pdo, 'dispatch_operator_records', 'location') ? 'd.location' : 'NULL';
    $prioritySelect = table_column_exists($pdo, 'dispatch_operator_records', 'priority') ? 'd.priority' : 'NULL';
    $assignedAtOrder = table_column_exists($pdo, 'dispatch_operator_records', 'assigned_at')
        ? 'd.assigned_at DESC, '
        : '';

    try {
        $stmt = $pdo->query(
            "SELECT
                d.id AS dispatch_id,
                {$incidentIdSelect} AS incident_id,
                {$dispatchNameSelect} AS dispatch_name,
                {$vehicleSelect} AS vehicle,
                {$locationSelect} AS dispatch_location,
                {$prioritySelect} AS dispatch_priority,
                d.status AS dispatch_status,
                {$assignedUnitCodeSelect} AS assigned_unit_code,
                {$responderUnitCodeSelect} AS responder_unit_code,
                {$referenceNoSelect} AS reference_no,
                {$incidentTitleSelect} AS incident_title,
                {$incidentTypeSelect} AS incident_type,
                {$incidentPrioritySelect} AS incident_priority,
                {$incidentLocationSelect} AS incident_location
             FROM `dispatch_operator_records` d
             {$usersJoin}
             {$incidentsJoin}
             WHERE LOWER(d.status) IN ('pending','assigned','received','accepted','acknowledged','busy','in_use','enroute','en_route','on_scene')
             ORDER BY {$assignedAtOrder}d.id DESC"
        );
    } catch (Throwable $e) {
        error_log('admin_resources active unit assignment map skipped: ' . $e->getMessage());
        return [];
    }

    $assignments = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $details = build_incident_assignment_details($row);
        $unitCodes = [
            strtoupper(trim((string)($row['assigned_unit_code'] ?? ''))),
            strtoupper(trim((string)($row['responder_unit_code'] ?? ''))),
        ];
        foreach ($unitCodes as $unitCode) {
            if ($unitCode !== '' && !isset($assignments[$unitCode])) {
                $assignments[$unitCode] = [
                    'details' => $details,
                    'incident_id' => (int)($row['incident_id'] ?? 0),
                    'incident_code' => trim((string)($row['reference_no'] ?? '')),
                ];
            }
        }
    }

    return $assignments;
}

function row_to_item(array $row): array {
    return [
        'id' => (int)($row['id'] ?? 0),
        'code' => (string)($row['code'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'category' => (string)($row['category'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'location' => (string)($row['location'] ?? ''),
        'latitude' => isset($row['latitude']) && $row['latitude'] !== null ? (float)$row['latitude'] : null,
        'longitude' => isset($row['longitude']) && $row['longitude'] !== null ? (float)$row['longitude'] : null,
        'driverName' => (string)($row['driver_name'] ?? ''),
        'plateNumber' => (string)($row['plate_number'] ?? ''),
        'positionTitle' => (string)($row['position_title'] ?? ''),
        'assignment' => (string)($row['assignment'] ?? ''),
        'assignmentDetails' => (string)($row['assignmentDetails'] ?? ''),
        'assignmentIncidentId' => (int)($row['assignmentIncidentId'] ?? 0),
        'assignmentIncidentCode' => (string)($row['assignmentIncidentCode'] ?? ''),
        'quantity' => max(1, (int)($row['quantity'] ?? 1)),
        'notes' => (string)($row['notes'] ?? ''),
        'updatedAt' => (string)($row['updated_at'] ?? '')
    ];
}

function apply_active_assignment_to_item(array $item, array $activeIncidentAssignments): array {
    $unitCode = strtoupper(trim((string)($item['code'] ?? '')));
    $incidentAssignment = strtolower((string)($item['category'] ?? '')) === 'vehicles'
        ? ($activeIncidentAssignments[$unitCode] ?? null)
        : null;
    if (is_array($incidentAssignment)) {
        $item['status'] = 'in_use';
        $item['assignmentDetails'] = (string)($incidentAssignment['details'] ?? '');
        $item['assignmentIncidentId'] = (int)($incidentAssignment['incident_id'] ?? 0);
        $item['assignmentIncidentCode'] = (string)($incidentAssignment['incident_code'] ?? '');
    }
    return $item;
}

function archive_row_to_item(array $row): array {
    return [
        'id' => (int)($row['id'] ?? 0),
        'resourceId' => (int)($row['resource_id'] ?? 0),
        'code' => (string)($row['code'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'category' => (string)($row['category'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'location' => (string)($row['location'] ?? ''),
        'latitude' => isset($row['latitude']) && $row['latitude'] !== null ? (float)$row['latitude'] : null,
        'longitude' => isset($row['longitude']) && $row['longitude'] !== null ? (float)$row['longitude'] : null,
        'driverName' => (string)($row['driver_name'] ?? ''),
        'plateNumber' => (string)($row['plate_number'] ?? ''),
        'positionTitle' => (string)($row['position_title'] ?? ''),
        'assignment' => (string)($row['assignment'] ?? ''),
        'quantity' => max(1, (int)($row['quantity'] ?? 1)),
        'notes' => (string)($row['notes'] ?? ''),
        'updatedAt' => (string)($row['updated_at'] ?? ''),
        'deletedAt' => (string)($row['deleted_at'] ?? ''),
        'purgeAt' => isset($row['purge_at']) ? (string)$row['purge_at'] : ''
    ];
}

function fetch_item(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, code, name, category, status, location, latitude, longitude, driver_name, plate_number, position_title, assignment, quantity, notes, updated_at
         FROM `" . RESOURCE_RECORDS_TABLE . "`
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? apply_active_assignment_to_item(row_to_item($row), load_active_unit_incident_assignment_map($pdo)) : null;
}

function fetch_active_resource_row(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, code, name, category, status, location, latitude, longitude, driver_name, plate_number, position_title, assignment, quantity, notes, created_at, updated_at
         FROM `" . RESOURCE_RECORDS_TABLE . "`
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetch_archived_resource_row(PDO $pdo, int $archiveId): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, resource_id, code, name, category, status, location, latitude, longitude, driver_name, plate_number, position_title, assignment, quantity, notes, created_at, updated_at, deleted_at
         FROM `" . RESOURCE_RECORDS_ARCHIVE_TABLE . "`
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$archiveId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function purge_expired_archived_resources(PDO $pdo): void {
    $stmt = $pdo->prepare(
        "DELETE FROM `" . RESOURCE_RECORDS_ARCHIVE_TABLE . "`
         WHERE deleted_at <= DATE_SUB(NOW(), INTERVAL 60 DAY)"
    );
    $stmt->execute();
}

function units_table_available(PDO $pdo): bool {
    return table_exists($pdo, 'units');
}

function unit_column_exists(PDO $pdo, string $columnName): bool {
    return table_column_exists($pdo, 'units', $columnName);
}

function infer_vehicle_unit_type(array $resource): string {
    $haystack = strtolower(trim(implode(' ', [
        (string)($resource['code'] ?? ''),
        (string)($resource['name'] ?? ''),
        (string)($resource['assignment'] ?? ''),
        (string)($resource['notes'] ?? ''),
        (string)($resource['driverName'] ?? ''),
        (string)($resource['plateNumber'] ?? ''),
        (string)($resource['driver_name'] ?? ''),
        (string)($resource['plate_number'] ?? '')
    ])));

    if ($haystack !== '') {
        if (preg_match('/ambulance|medical|emt|medic|clinic|hospital/', $haystack)) {
            return 'ambulance';
        }
        if (preg_match('/fire|truck|blaze|engine/', $haystack)) {
            return 'fire';
        }
        if (preg_match('/police|patrol|crime|law/', $haystack)) {
            return 'police';
        }
        if (preg_match('/rescue|search|retrieval|sar/', $haystack)) {
            return 'rescue';
        }
    }

    return 'other';
}

function map_vehicle_resource_status_to_unit_status(string $status): string {
    $status = strtolower(trim($status));
    if ($status === 'in_use') {
        return 'assigned';
    }
    if ($status === 'maintenance') {
        return 'maintenance';
    }
    if ($status === 'offline') {
        return 'unavailable';
    }
    return 'available';
}

function vehicle_resource_default_coordinates(string $unitType): array {
    $defaults = [
        'police' => [14.6500, 121.0300],
        'fire' => [14.6700, 121.0450],
        'ambulance' => [14.6900, 121.0600],
        'rescue' => [14.6760, 121.0437],
        'other' => [14.6760, 121.0437]
    ];

    return $defaults[strtolower(trim($unitType))] ?? $defaults['other'];
}

function vehicle_resource_coordinates(array $resource, string $unitType): array {
    $storedLat = $resource['latitude'] ?? null;
    $storedLng = $resource['longitude'] ?? null;
    if ($storedLat !== null && $storedLng !== null && $storedLat !== '' && $storedLng !== '') {
        $lat = (float)$storedLat;
        $lng = (float)$storedLng;
        if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
            return [$lat, $lng, 'explicit'];
        }
    }

    $location = trim((string)($resource['location'] ?? ''));
    if ($location !== '' && preg_match('/(-?\d{1,2}(?:\.\d+)?)\s*,\s*(-?\d{1,3}(?:\.\d+)?)/', $location, $matches)) {
        $lat = (float)$matches[1];
        $lng = (float)$matches[2];
        if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
            return [$lat, $lng, 'explicit'];
        }
    }

    $geocoded = ers_geocode_location_to_coordinates($location);
    if ($geocoded !== null) {
        return [$geocoded[0], $geocoded[1], 'geocoded'];
    }

    [$lat, $lng] = vehicle_resource_default_coordinates($unitType);
    return [$lat, $lng, 'default'];
}

function vehicle_resource_is_default_coordinate($latitude, $longitude): bool {
    if ($latitude === null || $longitude === null || $latitude === '' || $longitude === '') {
        return false;
    }

    $lat = (float)$latitude;
    $lng = (float)$longitude;
    foreach (['police', 'fire', 'ambulance', 'rescue', 'other'] as $unitType) {
        [$defaultLat, $defaultLng] = vehicle_resource_default_coordinates($unitType);
        if (abs($lat - $defaultLat) < 0.000001 && abs($lng - $defaultLng) < 0.000001) {
            return true;
        }
    }

    return false;
}

function find_unit_by_identifiers(PDO $pdo, array $identifiers): ?array {
    $identifiers = array_values(array_filter(array_map(
        static fn($value): string => trim((string)$value),
        $identifiers
    )));
    if ($identifiers === []) {
        return null;
    }

    $placeholders = implode(',', array_fill(0, count($identifiers), '?'));
    $stmt = $pdo->prepare("SELECT * FROM `units` WHERE `identifier` IN ($placeholders) ORDER BY id ASC LIMIT 1");
    $stmt->execute($identifiers);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function sync_vehicle_resource_unit(PDO $pdo, array $resource, ?string $previousCode = null): void {
    if (!units_table_available($pdo)) {
        return;
    }
    $category = strtolower(trim((string)($resource['category'] ?? '')));
    if ($category !== 'vehicles') {
        return;
    }

    $identifier = strtoupper(trim((string)($resource['code'] ?? '')));
    if ($identifier === '') {
        return;
    }

    $existingUnit = find_unit_by_identifiers($pdo, [$identifier, $previousCode]);
    $unitType = infer_vehicle_unit_type($resource);
    $unitStatus = map_vehicle_resource_status_to_unit_status((string)($resource['status'] ?? 'available'));
    $hasLastStatusAt = unit_column_exists($pdo, 'last_status_at');
    $hasCurrentIncidentId = unit_column_exists($pdo, 'current_incident_id');

    if ($existingUnit) {
        $fields = ['identifier = ?', 'unit_type = ?', 'status = ?'];
        $params = [$identifier, $unitType, $unitStatus];
        if ($hasLastStatusAt) {
            $fields[] = 'last_status_at = NOW()';
        }
        if ($hasCurrentIncidentId && $unitStatus === 'available') {
            $fields[] = 'current_incident_id = NULL';
        }
        $params[] = (int)$existingUnit['id'];
        $stmt = $pdo->prepare("UPDATE `units` SET " . implode(', ', $fields) . " WHERE id = ?");
        $stmt->execute($params);
        return;
    }

    $columns = ['identifier', 'unit_type', 'status'];
    $values = ['?', '?', '?'];
    $params = [$identifier, $unitType, $unitStatus];
    if ($hasCurrentIncidentId) {
        $columns[] = 'current_incident_id';
        $values[] = 'NULL';
    }
    if ($hasLastStatusAt) {
        $columns[] = 'last_status_at';
        $values[] = 'NOW()';
    }
    if (unit_column_exists($pdo, 'latitude') && unit_column_exists($pdo, 'longitude')) {
        $columns[] = 'latitude';
        $columns[] = 'longitude';
        $values[] = 'NULL';
        $values[] = 'NULL';
    }

    $stmt = $pdo->prepare(
        "INSERT INTO `units` (" . implode(', ', $columns) . ")
         VALUES (" . implode(', ', $values) . ")"
    );
    $stmt->execute($params);
}

function deactivate_vehicle_resource_unit(PDO $pdo, string $identifier): void {
    if (!units_table_available($pdo)) {
        return;
    }
    $identifier = strtoupper(trim($identifier));
    if ($identifier === '') {
        return;
    }

    $existingUnit = find_unit_by_identifiers($pdo, [$identifier]);
    if (!$existingUnit) {
        return;
    }

    $fields = ['status = ?'];
    $params = ['offline'];
    if (unit_column_exists($pdo, 'last_status_at')) {
        $fields[] = 'last_status_at = NOW()';
    }
    $params[] = (int)$existingUnit['id'];
    $stmt = $pdo->prepare("UPDATE `units` SET " . implode(', ', $fields) . " WHERE id = ?");
    $stmt->execute($params);
}

try {
    ensure_resource_records_table($pdo);
    ensure_resource_records_archive_table($pdo);
    ensure_legacy_resource_quantity_columns($pdo);
    migrate_legacy_admin_resource_tables($pdo);
    purge_expired_archived_resources($pdo);

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        ers_sync_responder_vehicle_resources($pdo);
        $archived = isset($_GET['archived']) && (string)$_GET['archived'] === '1';
        if ($archived) {
            $stmt = $pdo->query(
                "SELECT id, resource_id, code, name, category, status, location, latitude, longitude, assignment, quantity, notes, updated_at, deleted_at,
                        driver_name, plate_number, position_title,
                        DATE_ADD(deleted_at, INTERVAL 60 DAY) AS purge_at
                 FROM `" . RESOURCE_RECORDS_ARCHIVE_TABLE . "`
                 ORDER BY deleted_at DESC, id DESC"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map('archive_row_to_item', $rows);
        } else {
            $stmt = $pdo->query(
                "SELECT id, code, name, category, status, location, latitude, longitude, assignment, quantity, notes, updated_at
                 , driver_name, plate_number, position_title
                 FROM `" . RESOURCE_RECORDS_TABLE . "`
                 ORDER BY updated_at DESC, id DESC"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $presenceMap = ers_vehicle_resource_responder_presence_map($pdo);
            $rows = array_map(
                static fn(array $row): array => ers_apply_responder_presence_to_vehicle_resource_row($row, $presenceMap),
                $rows
            );
            $activeIncidentAssignments = load_active_unit_incident_assignment_map($pdo);
            $items = array_map(
                static fn(array $row): array => apply_active_assignment_to_item(row_to_item($row), $activeIncidentAssignments),
                $rows
            );
        }
        echo json_encode(['ok' => true, 'items' => $items]);
        exit;
    }

    if ($method === 'POST') {
        $rawPayload = parse_payload();
        $action = strtolower(clean_text($rawPayload['action'] ?? ''));

        if ($action === 'restore') {
            $archiveId = isset($rawPayload['archive_id']) ? (int)$rawPayload['archive_id'] : 0;
            if ($archiveId <= 0) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'Missing archive id']);
                exit;
            }

            $archivedResource = fetch_archived_resource_row($pdo, $archiveId);
            if ($archivedResource === null) {
                http_response_code(404);
                echo json_encode(['ok' => false, 'error' => 'Archived resource not found']);
                exit;
            }

            $codeCheckStmt = $pdo->prepare(
                "SELECT id
                 FROM `" . RESOURCE_RECORDS_TABLE . "`
                 WHERE code = ?
                 LIMIT 1"
            );
            $codeCheckStmt->execute([(string)$archivedResource['code']]);
            if ($codeCheckStmt->fetch(PDO::FETCH_ASSOC)) {
                http_response_code(409);
                echo json_encode(['ok' => false, 'error' => 'Resource ID already exists in active resources']);
                exit;
            }

            $pdo->beginTransaction();
            try {
                $restoreStmt = $pdo->prepare(
                    "INSERT INTO `" . RESOURCE_RECORDS_TABLE . "` (code, name, category, status, location, latitude, longitude, driver_name, plate_number, position_title, assignment, quantity, notes, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                );
                $restoreStmt->execute([
                    (string)$archivedResource['code'],
                    (string)$archivedResource['name'],
                    (string)$archivedResource['category'],
                    (string)$archivedResource['status'],
                    (string)$archivedResource['location'],
                    $archivedResource['latitude'] !== null && $archivedResource['latitude'] !== '' ? (float)$archivedResource['latitude'] : null,
                    $archivedResource['longitude'] !== null && $archivedResource['longitude'] !== '' ? (float)$archivedResource['longitude'] : null,
                    $archivedResource['driver_name'] !== null && $archivedResource['driver_name'] !== '' ? (string)$archivedResource['driver_name'] : null,
                    $archivedResource['plate_number'] !== null && $archivedResource['plate_number'] !== '' ? (string)$archivedResource['plate_number'] : null,
                    $archivedResource['position_title'] !== null && $archivedResource['position_title'] !== '' ? (string)$archivedResource['position_title'] : null,
                    $archivedResource['assignment'] !== null && $archivedResource['assignment'] !== '' ? (string)$archivedResource['assignment'] : null,
                    max(1, (int)($archivedResource['quantity'] ?? 1)),
                    $archivedResource['notes'] !== null && $archivedResource['notes'] !== '' ? (string)$archivedResource['notes'] : null,
                    (string)$archivedResource['created_at']
                ]);
                $restoredId = (int)$pdo->lastInsertId();

                $deleteArchiveStmt = $pdo->prepare("DELETE FROM `" . RESOURCE_RECORDS_ARCHIVE_TABLE . "` WHERE id = ?");
                $deleteArchiveStmt->execute([$archiveId]);
                if ($deleteArchiveStmt->rowCount() === 0) {
                    throw new RuntimeException('Archived resource not found during restore');
                }

                $pdo->commit();
            } catch (Throwable $transactionError) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                    throw $transactionError;
                }

                sync_vehicle_resource_unit($pdo, [
                    'code' => (string)$archivedResource['code'],
                    'name' => (string)$archivedResource['name'],
                    'category' => (string)$archivedResource['category'],
                    'status' => (string)$archivedResource['status'],
                    'location' => (string)($archivedResource['location'] ?? ''),
                    'latitude' => $archivedResource['latitude'] ?? null,
                    'longitude' => $archivedResource['longitude'] ?? null,
                    'assignment' => (string)($archivedResource['assignment'] ?? ''),
                    'notes' => (string)($archivedResource['notes'] ?? ''),
                    'driver_name' => (string)($archivedResource['driver_name'] ?? ''),
                    'plate_number' => (string)($archivedResource['plate_number'] ?? '')
                ]);

                $item = fetch_item($pdo, $restoredId);
                echo json_encode([
                    'ok' => true,
                'item' => $item,
                'restored_archive_id' => $archiveId
            ]);
            exit;
        }

        $payload = normalize_payload($rawPayload);
        $stmt = $pdo->prepare(
            "INSERT INTO `" . RESOURCE_RECORDS_TABLE . "` (code, name, category, status, location, latitude, longitude, driver_name, plate_number, position_title, assignment, quantity, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([
            $payload['code'],
            $payload['name'],
            $payload['category'],
            $payload['status'],
            $payload['location'],
            $payload['latitude'],
            $payload['longitude'],
            $payload['driverName'] !== '' ? $payload['driverName'] : null,
            $payload['plateNumber'] !== '' ? $payload['plateNumber'] : null,
            $payload['positionTitle'] !== '' ? $payload['positionTitle'] : null,
            $payload['assignment'] !== '' ? $payload['assignment'] : null,
            $payload['quantity'],
            $payload['notes'] !== '' ? $payload['notes'] : null
        ]);
        $id = (int)$pdo->lastInsertId();
        sync_vehicle_resource_unit($pdo, $payload);
        log_resource_added_notification($payload, $id, is_array($actor) ? $actor : []);
        $item = fetch_item($pdo, $id);
        echo json_encode(['ok' => true, 'item' => $item]);
        exit;
    }

    if ($method === 'PUT') {
        $rawPayload = parse_payload();
        $id = isset($rawPayload['id']) ? (int)$rawPayload['id'] : 0;
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing resource id']);
            exit;
        }

        $existingItem = fetch_item($pdo, $id);
        if ($existingItem === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Resource not found']);
            exit;
        }

        $payload = normalize_payload($rawPayload);
        $stmt = $pdo->prepare(
            "UPDATE `" . RESOURCE_RECORDS_TABLE . "`
             SET code = ?, name = ?, category = ?, status = ?, location = ?, latitude = ?, longitude = ?, driver_name = ?, plate_number = ?, position_title = ?, assignment = ?, quantity = ?, notes = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([
            $payload['code'],
            $payload['name'],
            $payload['category'],
            $payload['status'],
            $payload['location'],
            $payload['latitude'],
            $payload['longitude'],
            $payload['driverName'] !== '' ? $payload['driverName'] : null,
            $payload['plateNumber'] !== '' ? $payload['plateNumber'] : null,
            $payload['positionTitle'] !== '' ? $payload['positionTitle'] : null,
            $payload['assignment'] !== '' ? $payload['assignment'] : null,
            $payload['quantity'],
            $payload['notes'] !== '' ? $payload['notes'] : null,
            $id
        ]);
        $previousCategory = strtolower(trim((string)($existingItem['category'] ?? '')));
        if ($previousCategory === 'vehicles' && $payload['category'] !== 'vehicles') {
            deactivate_vehicle_resource_unit($pdo, (string)($existingItem['code'] ?? ''));
        } else {
            sync_vehicle_resource_unit($pdo, $payload, (string)($existingItem['code'] ?? ''));
        }
        $item = fetch_item($pdo, $id);
        echo json_encode(['ok' => true, 'item' => $item]);
        exit;
    }

    if ($method === 'DELETE') {
        $rawPayload = parse_payload();
        $id = isset($rawPayload['id']) ? (int)$rawPayload['id'] : (isset($_GET['id']) ? (int)$_GET['id'] : 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Missing resource id']);
            exit;
        }

        $resource = fetch_active_resource_row($pdo, $id);
        if ($resource === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Resource not found']);
            exit;
        }

        $pdo->beginTransaction();
        try {
            $archiveStmt = $pdo->prepare(
                "INSERT INTO `" . RESOURCE_RECORDS_ARCHIVE_TABLE . "` (
                    resource_id, code, name, category, status, location, latitude, longitude, driver_name, plate_number, position_title, assignment, quantity, notes, created_at, updated_at, deleted_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $archiveStmt->execute([
                (int)$resource['id'],
                (string)$resource['code'],
                (string)$resource['name'],
                (string)$resource['category'],
                (string)$resource['status'],
                (string)$resource['location'],
                $resource['latitude'] !== null && $resource['latitude'] !== '' ? (float)$resource['latitude'] : null,
                $resource['longitude'] !== null && $resource['longitude'] !== '' ? (float)$resource['longitude'] : null,
                $resource['driver_name'] !== null && $resource['driver_name'] !== '' ? (string)$resource['driver_name'] : null,
                $resource['plate_number'] !== null && $resource['plate_number'] !== '' ? (string)$resource['plate_number'] : null,
                $resource['position_title'] !== null && $resource['position_title'] !== '' ? (string)$resource['position_title'] : null,
                $resource['assignment'] !== null && $resource['assignment'] !== '' ? (string)$resource['assignment'] : null,
                max(1, (int)($resource['quantity'] ?? 1)),
                $resource['notes'] !== null && $resource['notes'] !== '' ? (string)$resource['notes'] : null,
                (string)$resource['created_at'],
                (string)$resource['updated_at']
            ]);
            $archiveId = (int)$pdo->lastInsertId();

            $deleteStmt = $pdo->prepare("DELETE FROM `" . RESOURCE_RECORDS_TABLE . "` WHERE id = ?");
            $deleteStmt->execute([$id]);
            if ($deleteStmt->rowCount() === 0) {
                throw new RuntimeException('Resource not found during delete');
            }
            $pdo->commit();
        } catch (Throwable $transactionError) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $transactionError;
        }

        if (strtolower(trim((string)($resource['category'] ?? ''))) === 'vehicles') {
            deactivate_vehicle_resource_unit($pdo, (string)$resource['code']);
        }

        echo json_encode([
            'ok' => true,
            'deleted_id' => $id,
            'archived_id' => $archiveId
        ]);
        exit;
    }

    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
} catch (InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (PDOException $e) {
    error_log('resource_records PDO error: ' . $e->getMessage());
    if ((string)$e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Resource ID already exists']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database operation failed']);
} catch (Throwable $e) {
    error_log('resource_records unexpected error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unexpected server error']);
}
