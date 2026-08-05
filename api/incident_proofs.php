<?php
// API: List resolution proofs for an incident
// GET: incident_id
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}
$incidentId = isset($_GET['incident_id']) ? (int)$_GET['incident_id'] : 0;
if ($incidentId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Missing incident_id']);
    exit;
}

function incident_proof_table_exists(PDO $pdo, string $table): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1"
        );
        $stmt->execute([$table]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function incident_proof_column_exists(PDO $pdo, string $table, string $column): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1"
        );
        $stmt->execute([$table, $column]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function incident_proof_add_item(array &$items, array &$seen, string $url, ?string $createdAt, string $source): void
{
    $url = trim($url);
    if ($url === '') {
        return;
    }
    $key = strtolower($url);
    if (isset($seen[$key])) {
        return;
    }
    $seen[$key] = true;
    $items[] = [
        'url' => $url,
        'created_at' => $createdAt,
        'source' => $source,
    ];
}

try {
    $items = [];
    $seen = [];

    if (incident_proof_table_exists($pdo, 'incident_proofs')) {
        $stmt = $pdo->prepare('SELECT id, file_path, created_at FROM incident_proofs WHERE incident_id = ? ORDER BY created_at DESC');
        $stmt->execute([$incidentId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            incident_proof_add_item($items, $seen, (string)($r['file_path'] ?? ''), $r['created_at'] ?? null, 'incident_proofs');
        }
    }

    if (incident_proof_table_exists($pdo, 'incident_notes')) {
        $stmt = $pdo->prepare("SELECT note, created_at FROM incident_notes WHERE incident_id = ? AND note LIKE 'Resolution proof uploaded:%' ORDER BY created_at DESC");
        $stmt->execute([$incidentId]);
        $rows = $stmt->fetchAll();
        foreach ($rows as $r) {
            $note = (string)$r['note'];
            $pos = strpos($note, ':');
            $url = $pos !== false ? trim(substr($note, $pos + 1)) : '';
            incident_proof_add_item($items, $seen, $url, $r['created_at'] ?? null, 'incident_notes');
        }
    }

    if (
        incident_proof_table_exists($pdo, 'incidents') &&
        incident_proof_column_exists($pdo, 'incidents', 'completion_image_path')
    ) {
        $createdColumn = incident_proof_column_exists($pdo, 'incidents', 'completed_at')
            ? 'completed_at'
            : (incident_proof_column_exists($pdo, 'incidents', 'updated_at') ? 'updated_at' : 'created_at');
        $stmt = $pdo->prepare("SELECT completion_image_path, {$createdColumn} AS proof_created_at FROM incidents WHERE id = ? LIMIT 1");
        $stmt->execute([$incidentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            incident_proof_add_item($items, $seen, (string)($row['completion_image_path'] ?? ''), $row['proof_created_at'] ?? null, 'responder_completion');
        }
    }

    usort($items, static function (array $a, array $b): int {
        return strtotime((string)($b['created_at'] ?? '')) <=> strtotime((string)($a['created_at'] ?? ''));
    });

    echo json_encode(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
    error_log('Proof list error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to load proofs']);
}
