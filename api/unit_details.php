<?php
// Returns details for a single unit
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';

function unit_details_table_exists(PDO $pdo, string $tableName): bool {
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$tableName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function unit_details_column_exists(PDO $pdo, string $tableName, string $columnName): bool {
    try {
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
    } catch (Throwable $e) {
        return false;
    }
}

$pdo = get_db_connection();
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$out = ["unit" => null];
if ($pdo && $id) {
    $resourceTable = null;
    if (unit_details_table_exists($pdo, 'resource_records')) {
        $resourceTable = 'resource_records';
    } elseif (unit_details_table_exists($pdo, 'admin_resources')) {
        $resourceTable = 'admin_resources';
    }

    $responderDriverExpr = 'NULL';
    if (
        unit_details_table_exists($pdo, 'users') &&
        unit_details_column_exists($pdo, 'users', 'unit_code') &&
        unit_details_column_exists($pdo, 'users', 'name') &&
        unit_details_column_exists($pdo, 'users', 'role')
    ) {
        $responderDriverExpr = "(SELECT usr.name
                                FROM users usr
                                WHERE LOWER(COALESCE(usr.role, '')) = 'responder'
                                  AND UPPER(TRIM(usr.unit_code)) = UPPER(TRIM(u.identifier))
                                  AND TRIM(COALESCE(usr.name, '')) <> ''
                                ORDER BY usr.id DESC
                                LIMIT 1)";
    }

    $resourceJoin = '';
    $resourceSelect = $responderDriverExpr . ' AS driver_name, NULL AS plate_number, NULL AS resource_name, NULL AS assignment, NULL AS resource_notes';
    if ($resourceTable !== null) {
        $resourceJoin = ' LEFT JOIN `' . $resourceTable . '` rr ON rr.code = u.identifier ';
        $resourceSelect = 'COALESCE(NULLIF(TRIM(rr.driver_name), \'\'), ' . $responderDriverExpr . ') AS driver_name, rr.plate_number AS plate_number, rr.name AS resource_name, rr.assignment AS assignment, rr.notes AS resource_notes';
    }

    $stmt = $pdo->prepare(
        "SELECT u.*, {$resourceSelect}
         FROM units u
         {$resourceJoin}
         WHERE u.id = ?
         LIMIT 1"
    );
    $stmt->execute([$id]);
    $out['unit'] = $stmt->fetch(PDO::FETCH_ASSOC);
}
echo json_encode($out);
