<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

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

function ensure_admin_resources_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `admin_resources` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `code` VARCHAR(50) NOT NULL,
            `name` VARCHAR(200) NOT NULL,
            `category` ENUM('vehicles','personnel','equipment') NOT NULL,
            `status` ENUM('available','in_use','maintenance','offline') NOT NULL DEFAULT 'available',
            `location` VARCHAR(255) NOT NULL,
            `driver_name` VARCHAR(150) DEFAULT NULL,
            `plate_number` VARCHAR(50) DEFAULT NULL,
            `position_title` VARCHAR(150) DEFAULT NULL,
            `assignment` VARCHAR(255) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_admin_resources_code` (`code`),
            KEY `idx_admin_resources_category` (`category`),
            KEY `idx_admin_resources_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec("ALTER TABLE `admin_resources` ADD COLUMN IF NOT EXISTS `driver_name` VARCHAR(150) DEFAULT NULL AFTER `location`");
    $pdo->exec("ALTER TABLE `admin_resources` ADD COLUMN IF NOT EXISTS `plate_number` VARCHAR(50) DEFAULT NULL AFTER `driver_name`");
    $pdo->exec("ALTER TABLE `admin_resources` ADD COLUMN IF NOT EXISTS `position_title` VARCHAR(150) DEFAULT NULL AFTER `plate_number`");
}

function ensure_admin_resources_archive_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `admin_resources_archive` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `resource_id` BIGINT UNSIGNED NOT NULL,
            `code` VARCHAR(50) NOT NULL,
            `name` VARCHAR(200) NOT NULL,
            `category` ENUM('vehicles','personnel','equipment') NOT NULL,
            `status` ENUM('available','in_use','maintenance','offline') NOT NULL DEFAULT 'available',
            `location` VARCHAR(255) NOT NULL,
            `driver_name` VARCHAR(150) DEFAULT NULL,
            `plate_number` VARCHAR(50) DEFAULT NULL,
            `position_title` VARCHAR(150) DEFAULT NULL,
            `assignment` VARCHAR(255) DEFAULT NULL,
            `notes` TEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            `deleted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_admin_resources_archive_resource_id` (`resource_id`),
            KEY `idx_admin_resources_archive_deleted_at` (`deleted_at`),
            KEY `idx_admin_resources_archive_category` (`category`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $pdo->exec("ALTER TABLE `admin_resources_archive` ADD COLUMN IF NOT EXISTS `driver_name` VARCHAR(150) DEFAULT NULL AFTER `location`");
    $pdo->exec("ALTER TABLE `admin_resources_archive` ADD COLUMN IF NOT EXISTS `plate_number` VARCHAR(50) DEFAULT NULL AFTER `driver_name`");
    $pdo->exec("ALTER TABLE `admin_resources_archive` ADD COLUMN IF NOT EXISTS `position_title` VARCHAR(150) DEFAULT NULL AFTER `plate_number`");
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
    $driverName = clean_text($payload['driverName'] ?? '');
    $plateNumber = strtoupper(clean_text($payload['plateNumber'] ?? ''));
    $positionTitle = clean_text($payload['positionTitle'] ?? '');
    $assignment = clean_text($payload['assignment'] ?? '');
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
    if ($location === '' || strlen($location) > 255) {
        throw new InvalidArgumentException('Location is required and must be 255 chars or less');
    }
    if (strlen($assignment) > 255) {
        throw new InvalidArgumentException('Assignment must be 255 chars or less');
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

    return [
        'code' => $code,
        'name' => $name,
        'category' => $category,
        'status' => $status,
        'location' => $location,
        'driverName' => $driverName,
        'plateNumber' => $plateNumber,
        'positionTitle' => $positionTitle,
        'assignment' => $assignment,
        'notes' => $notes
    ];
}

function row_to_item(array $row): array {
    return [
        'id' => (int)($row['id'] ?? 0),
        'code' => (string)($row['code'] ?? ''),
        'name' => (string)($row['name'] ?? ''),
        'category' => (string)($row['category'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'location' => (string)($row['location'] ?? ''),
        'driverName' => (string)($row['driver_name'] ?? ''),
        'plateNumber' => (string)($row['plate_number'] ?? ''),
        'positionTitle' => (string)($row['position_title'] ?? ''),
        'assignment' => (string)($row['assignment'] ?? ''),
        'notes' => (string)($row['notes'] ?? ''),
        'updatedAt' => (string)($row['updated_at'] ?? '')
    ];
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
        'driverName' => (string)($row['driver_name'] ?? ''),
        'plateNumber' => (string)($row['plate_number'] ?? ''),
        'positionTitle' => (string)($row['position_title'] ?? ''),
        'assignment' => (string)($row['assignment'] ?? ''),
        'notes' => (string)($row['notes'] ?? ''),
        'updatedAt' => (string)($row['updated_at'] ?? ''),
        'deletedAt' => (string)($row['deleted_at'] ?? ''),
        'purgeAt' => isset($row['purge_at']) ? (string)$row['purge_at'] : ''
    ];
}

function fetch_item(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, code, name, category, status, location, driver_name, plate_number, position_title, assignment, notes, updated_at
         FROM admin_resources
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ? row_to_item($row) : null;
}

function fetch_active_resource_row(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, code, name, category, status, location, driver_name, plate_number, position_title, assignment, notes, created_at, updated_at
         FROM admin_resources
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function fetch_archived_resource_row(PDO $pdo, int $archiveId): ?array {
    $stmt = $pdo->prepare(
        "SELECT id, resource_id, code, name, category, status, location, driver_name, plate_number, position_title, assignment, notes, created_at, updated_at, deleted_at
         FROM admin_resources_archive
         WHERE id = ?
         LIMIT 1"
    );
    $stmt->execute([$archiveId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function purge_expired_archived_resources(PDO $pdo): void {
    $stmt = $pdo->prepare(
        "DELETE FROM admin_resources_archive
         WHERE deleted_at <= DATE_SUB(NOW(), INTERVAL 60 DAY)"
    );
    $stmt->execute();
}

try {
    ensure_admin_resources_table($pdo);
    ensure_admin_resources_archive_table($pdo);
    purge_expired_archived_resources($pdo);

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        $archived = isset($_GET['archived']) && (string)$_GET['archived'] === '1';
        if ($archived) {
            $stmt = $pdo->query(
                "SELECT id, resource_id, code, name, category, status, location, assignment, notes, updated_at, deleted_at,
                        driver_name, plate_number, position_title,
                        DATE_ADD(deleted_at, INTERVAL 60 DAY) AS purge_at
                 FROM admin_resources_archive
                 ORDER BY deleted_at DESC, id DESC"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map('archive_row_to_item', $rows);
        } else {
            $stmt = $pdo->query(
                "SELECT id, code, name, category, status, location, assignment, notes, updated_at
                 , driver_name, plate_number, position_title
                 FROM admin_resources
                 ORDER BY updated_at DESC, id DESC"
            );
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $items = array_map('row_to_item', $rows);
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
                 FROM admin_resources
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
                    "INSERT INTO admin_resources (code, name, category, status, location, driver_name, plate_number, position_title, assignment, notes, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
                );
                $restoreStmt->execute([
                    (string)$archivedResource['code'],
                    (string)$archivedResource['name'],
                    (string)$archivedResource['category'],
                    (string)$archivedResource['status'],
                    (string)$archivedResource['location'],
                    $archivedResource['driver_name'] !== null && $archivedResource['driver_name'] !== '' ? (string)$archivedResource['driver_name'] : null,
                    $archivedResource['plate_number'] !== null && $archivedResource['plate_number'] !== '' ? (string)$archivedResource['plate_number'] : null,
                    $archivedResource['position_title'] !== null && $archivedResource['position_title'] !== '' ? (string)$archivedResource['position_title'] : null,
                    $archivedResource['assignment'] !== null && $archivedResource['assignment'] !== '' ? (string)$archivedResource['assignment'] : null,
                    $archivedResource['notes'] !== null && $archivedResource['notes'] !== '' ? (string)$archivedResource['notes'] : null,
                    (string)$archivedResource['created_at']
                ]);
                $restoredId = (int)$pdo->lastInsertId();

                $deleteArchiveStmt = $pdo->prepare("DELETE FROM admin_resources_archive WHERE id = ?");
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
            "INSERT INTO admin_resources (code, name, category, status, location, driver_name, plate_number, position_title, assignment, notes, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())"
        );
        $stmt->execute([
            $payload['code'],
            $payload['name'],
            $payload['category'],
            $payload['status'],
            $payload['location'],
            $payload['driverName'] !== '' ? $payload['driverName'] : null,
            $payload['plateNumber'] !== '' ? $payload['plateNumber'] : null,
            $payload['positionTitle'] !== '' ? $payload['positionTitle'] : null,
            $payload['assignment'] !== '' ? $payload['assignment'] : null,
            $payload['notes'] !== '' ? $payload['notes'] : null
        ]);
        $id = (int)$pdo->lastInsertId();
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

        $payload = normalize_payload($rawPayload);
        $stmt = $pdo->prepare(
            "UPDATE admin_resources
             SET code = ?, name = ?, category = ?, status = ?, location = ?, driver_name = ?, plate_number = ?, position_title = ?, assignment = ?, notes = ?, updated_at = NOW()
             WHERE id = ?"
        );
        $stmt->execute([
            $payload['code'],
            $payload['name'],
            $payload['category'],
            $payload['status'],
            $payload['location'],
            $payload['driverName'] !== '' ? $payload['driverName'] : null,
            $payload['plateNumber'] !== '' ? $payload['plateNumber'] : null,
            $payload['positionTitle'] !== '' ? $payload['positionTitle'] : null,
            $payload['assignment'] !== '' ? $payload['assignment'] : null,
            $payload['notes'] !== '' ? $payload['notes'] : null,
            $id
        ]);
        if ($stmt->rowCount() === 0 && fetch_item($pdo, $id) === null) {
            http_response_code(404);
            echo json_encode(['ok' => false, 'error' => 'Resource not found']);
            exit;
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
                "INSERT INTO admin_resources_archive (
                    resource_id, code, name, category, status, location, driver_name, plate_number, position_title, assignment, notes, created_at, updated_at, deleted_at
                 ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())"
            );
            $archiveStmt->execute([
                (int)$resource['id'],
                (string)$resource['code'],
                (string)$resource['name'],
                (string)$resource['category'],
                (string)$resource['status'],
                (string)$resource['location'],
                $resource['driver_name'] !== null && $resource['driver_name'] !== '' ? (string)$resource['driver_name'] : null,
                $resource['plate_number'] !== null && $resource['plate_number'] !== '' ? (string)$resource['plate_number'] : null,
                $resource['position_title'] !== null && $resource['position_title'] !== '' ? (string)$resource['position_title'] : null,
                $resource['assignment'] !== null && $resource['assignment'] !== '' ? (string)$resource['assignment'] : null,
                $resource['notes'] !== null && $resource['notes'] !== '' ? (string)$resource['notes'] : null,
                (string)$resource['created_at'],
                (string)$resource['updated_at']
            ]);
            $archiveId = (int)$pdo->lastInsertId();

            $deleteStmt = $pdo->prepare("DELETE FROM admin_resources WHERE id = ?");
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
    if ((string)$e->getCode() === '23000') {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'Resource ID already exists']);
        exit;
    }
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database operation failed']);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unexpected server error']);
}
