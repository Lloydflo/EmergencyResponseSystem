<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';

$pdo = get_db_connection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$columns = [];
foreach ($pdo->query('SHOW COLUMNS FROM `dispatches`') as $column) {
    $columns[] = [
        'field' => $column['Field'],
        'type' => $column['Type'],
        'null' => $column['Null'],
        'key' => $column['Key'],
    ];
}

$triggers = [];
$stmt = $pdo->query(
    "SELECT TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION
     FROM INFORMATION_SCHEMA.TRIGGERS
     WHERE TRIGGER_SCHEMA = DATABASE()
       AND EVENT_OBJECT_TABLE = 'dispatches'
     ORDER BY TRIGGER_NAME"
);
foreach ($stmt ?: [] as $trigger) {
    $triggers[] = [
        'name' => $trigger['TRIGGER_NAME'],
        'timing' => $trigger['ACTION_TIMING'],
        'event' => $trigger['EVENT_MANIPULATION'],
    ];
}

$unmatched = [];
$stmt = $pdo->query(
    "SELECT d.id, d.reference_no, d.unit_id, d.status, d.assigned_at, d.cleared_at
     FROM dispatches d
     LEFT JOIN incidents i ON i.reference_no = d.reference_no
     WHERE i.id IS NULL
     ORDER BY d.id"
);
foreach ($stmt ?: [] as $row) {
    $candidateStmt = $pdo->prepare(
        "SELECT id, reference_no, created_at, type, status
         FROM incidents
         ORDER BY ABS(TIMESTAMPDIFF(SECOND, created_at, ?)) ASC
         LIMIT 3"
    );
    $candidateStmt->execute([(string)$row['assigned_at']]);
    $candidates = [];
    foreach ($candidateStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $candidate) {
        $candidates[] = [
            'id' => (int)$candidate['id'],
            'reference_no' => (string)$candidate['reference_no'],
            'created_at' => (string)$candidate['created_at'],
            'type' => (string)$candidate['type'],
            'status' => (string)$candidate['status'],
        ];
    }

    $unmatched[] = [
        'id' => (int)$row['id'],
        'reference_no' => (string)$row['reference_no'],
        'unit_id' => (int)$row['unit_id'],
        'status' => (string)$row['status'],
        'assigned_at' => (string)$row['assigned_at'],
        'cleared_at' => $row['cleared_at'] === null ? null : (string)$row['cleared_at'],
        'nearest_incidents' => $candidates,
    ];
}

$samples = [];
$stmt = $pdo->query(
    "SELECT d.id, d.reference_no, i.id AS incident_id
     FROM dispatches d
     LEFT JOIN incidents i ON i.reference_no = d.reference_no
     ORDER BY d.id DESC
     LIMIT 5"
);
foreach ($stmt ?: [] as $row) {
    $samples[] = [
        'id' => (int)$row['id'],
        'reference_no' => (string)$row['reference_no'],
        'incident_id' => $row['incident_id'] === null ? null : (int)$row['incident_id'],
    ];
}

echo json_encode([
    'database' => (string)$pdo->query('SELECT DATABASE()')->fetchColumn(),
    'columns' => $columns,
    'triggers' => $triggers,
    'unmatched_count' => count($unmatched),
    'unmatched' => $unmatched,
    'samples' => $samples,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
