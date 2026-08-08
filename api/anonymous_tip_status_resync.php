<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/anonymous_tip_status_sync.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!is_logged_in() || !in_array(current_session_role(), ['admin', 'dispatcher'], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Admin or dispatcher login required']);
    exit;
}

$input = $_POST;
if ($input === []) {
    $raw = file_get_contents('php://input');
    $decoded = json_decode(is_string($raw) ? $raw : '', true);
    if (is_array($decoded)) {
        $input = $decoded;
    }
}

$incidentId = (int)($input['incident_id'] ?? $input['incidentId'] ?? 0);
$tipId = trim((string)($input['tip_id'] ?? $input['tipId'] ?? $input['tipID'] ?? ''));
$requestedStatus = strtolower(trim((string)($input['status'] ?? '')));

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Database connection unavailable']);
    exit;
}

try {
    if ($incidentId <= 0 && $tipId !== '') {
        if (!ers_anonymous_tip_sync_table_exists($pdo, 'external_incident_links')) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Anonymous tip link table is not available']);
            exit;
        }
        $stmt = $pdo->prepare(
            "SELECT eil.incident_id
             FROM external_incident_links eil
             LEFT JOIN anonymous_tips at
               ON at.tip_id = eil.external_incident_id
               OR eil.external_incident_id = CONCAT('anonymous-tip-', at.id)
             WHERE eil.source_system = 'Anonymous Tip Inbox'
               AND (eil.external_incident_id = ? OR at.tip_id = ? OR at.id = ?)
             LIMIT 1"
        );
        $stmt->execute([$tipId, $tipId, ctype_digit($tipId) ? (int)$tipId : 0]);
        $incidentId = (int)$stmt->fetchColumn();
    }

    if ($incidentId <= 0) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Provide a linked incident_id or tip_id']);
        exit;
    }

    if ($requestedStatus === '') {
        $stmt = $pdo->prepare('SELECT status FROM incidents WHERE id = ? LIMIT 1');
        $stmt->execute([$incidentId]);
        $incidentStatus = strtolower(trim((string)$stmt->fetchColumn()));
        $requestedStatus = in_array($incidentStatus, ['resolved', 'complete', 'completed', 'closed'], true)
            ? 'completed'
            : 'dispatched';
    }

    $sent = ers_notify_anonymous_tip_status(
        $pdo,
        $incidentId,
        $requestedStatus,
        'Manual anonymous tip status resync.'
    );

    $logs = [];
    if (
        ers_anonymous_tip_sync_table_exists($pdo, 'api_sync_logs')
        && ers_anonymous_tip_sync_column_exists($pdo, 'api_sync_logs', 'endpoint_name')
    ) {
        $stmt = $pdo->prepare(
            "SELECT id, status, response_payload, error_message, created_at, updated_at
             FROM api_sync_logs
             WHERE endpoint_name = 'anonymous_tip_status_callback'
               AND entity_type = 'incident'
               AND entity_id = ?
             ORDER BY id DESC
             LIMIT 5"
        );
        $stmt->execute([$incidentId]);
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    echo json_encode([
        'ok' => $sent,
        'incident_id' => $incidentId,
        'status_sent' => $requestedStatus,
        'logs' => $logs,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
