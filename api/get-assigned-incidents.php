<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

if ($_SERVER["REQUEST_METHOD"] !== "GET" && $_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode([
        "success" => false,
        "message" => "Method not allowed",
        "dispatches" => []
    ]);
    exit;
}

$raw = file_get_contents("php://input");
$input = json_decode($raw, true);
if (!is_array($input)) {
    $input = [];
}

$department = "";
if (isset($input["department"])) {
    $department = strtolower(trim((string)$input["department"]));
} elseif (isset($_GET["department"])) {
    $department = strtolower(trim((string)$_GET["department"]));
}

$sinceId = 0;
if (isset($input["since_id"])) {
    $sinceId = (int)$input["since_id"];
} elseif (isset($_GET["since_id"])) {
    $sinceId = (int)$_GET["since_id"];
}
if ($sinceId < 0) {
    $sinceId = 0;
}

$limit = 20;
if (isset($input["limit"])) {
    $limit = (int)$input["limit"];
} elseif (isset($_GET["limit"])) {
    $limit = (int)$_GET["limit"];
}
if ($limit < 1) {
    $limit = 20;
}
if ($limit > 100) {
    $limit = 100;
}

$deptToUnitType = [
    "medical" => "ambulance",
    "ambulance" => "ambulance",
    "fire" => "fire",
    "police" => "police",
    "rescue" => "rescue",
    "other" => "other"
];
$unitTypeFilter = array_key_exists($department, $deptToUnitType) ? $deptToUnitType[$department] : "";

try {
    $pdo = db();

    $sql = "
        SELECT
            d.id AS dispatch_id,
            d.status AS dispatch_status,
            d.assigned_at,
            d.acknowledged_at,
            d.enroute_at,
            d.on_scene_at,
            d.cleared_at,
            d.notes AS dispatch_notes,
            i.id AS incident_id,
            i.reference_no,
            i.type AS incident_type,
            i.priority,
            i.status AS incident_status,
            i.title,
            i.description,
            i.location_address,
            i.latitude AS incident_latitude,
            i.longitude AS incident_longitude,
            i.created_at AS incident_created_at,
            i.updated_at AS incident_updated_at,
            i.responded_at,
            i.resolved_at,
            u.id AS unit_id,
            u.identifier AS unit_identifier,
            u.unit_type,
            u.status AS unit_status,
            u.latitude AS unit_latitude,
            u.longitude AS unit_longitude
        FROM dispatches d
        INNER JOIN incidents i ON i.id = d.incident_id
        INNER JOIN units u ON u.id = d.unit_id
        WHERE d.id > ?
          AND d.status IN ('assigned', 'acknowledged', 'enroute', 'on_scene')
    ";

    $params = [$sinceId];
    if ($unitTypeFilter !== "") {
        $sql .= " AND u.unit_type = ? ";
        $params[] = $unitTypeFilter;
    }

    $sql .= " ORDER BY d.id ASC LIMIT " . (int)$limit;

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $dispatches = [];
    $lastDispatchId = $sinceId;

    foreach ($rows as $row) {
        $dispatchId = isset($row["dispatch_id"]) ? (int)$row["dispatch_id"] : 0;
        if ($dispatchId > $lastDispatchId) {
            $lastDispatchId = $dispatchId;
        }

        $dispatches[] = [
            "dispatch_id" => $dispatchId,
            "dispatch" => [
                "status" => $row["dispatch_status"] ?? null,
                "assigned_at" => $row["assigned_at"] ?? null,
                "acknowledged_at" => $row["acknowledged_at"] ?? null,
                "enroute_at" => $row["enroute_at"] ?? null,
                "on_scene_at" => $row["on_scene_at"] ?? null,
                "cleared_at" => $row["cleared_at"] ?? null,
                "notes" => $row["dispatch_notes"] ?? null
            ],
            "incident" => [
                "id" => isset($row["incident_id"]) ? (int)$row["incident_id"] : null,
                "reference_no" => $row["reference_no"] ?? null,
                "type" => $row["incident_type"] ?? null,
                "priority" => $row["priority"] ?? null,
                "status" => $row["incident_status"] ?? null,
                "title" => $row["title"] ?? null,
                "description" => $row["description"] ?? null,
                "location_address" => $row["location_address"] ?? null,
                "latitude" => $row["incident_latitude"] ?? null,
                "longitude" => $row["incident_longitude"] ?? null,
                "created_at" => $row["incident_created_at"] ?? null,
                "updated_at" => $row["incident_updated_at"] ?? null,
                "responded_at" => $row["responded_at"] ?? null,
                "resolved_at" => $row["resolved_at"] ?? null
            ],
            "unit" => [
                "id" => isset($row["unit_id"]) ? (int)$row["unit_id"] : null,
                "identifier" => $row["unit_identifier"] ?? null,
                "type" => $row["unit_type"] ?? null,
                "status" => $row["unit_status"] ?? null,
                "latitude" => $row["unit_latitude"] ?? null,
                "longitude" => $row["unit_longitude"] ?? null
            ]
        ];
    }

    echo json_encode([
        "success" => true,
        "message" => "OK",
        "department" => $department !== "" ? $department : null,
        "since_id" => $sinceId,
        "last_dispatch_id" => $lastDispatchId,
        "count" => count($dispatches),
        "dispatches" => $dispatches
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Server error: " . $e->getMessage(),
        "dispatches" => []
    ]);
}
