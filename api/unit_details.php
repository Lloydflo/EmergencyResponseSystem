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

    $resourceJoin = '';
    $resourceSelect = 'NULL AS driver_name, NULL AS plate_number, NULL AS resource_name, NULL AS assignment, NULL AS resource_notes';
    if ($resourceTable !== null) {
        $resourceJoin = ' LEFT JOIN `' . $resourceTable . '` rr ON rr.code = u.identifier ';
        $resourceSelect = 'rr.driver_name AS driver_name, rr.plate_number AS plate_number, rr.name AS resource_name, rr.assignment AS assignment, rr.notes AS resource_notes';
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
