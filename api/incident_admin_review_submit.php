<?php
declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_once __DIR__ . '/../includes/incident_admin_review.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$role = current_session_role();
if ($role !== 'dispatcher' && $role !== 'admin') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$incidentId = (int)($input['incident_id'] ?? 0);
$senderName = trim((string)($input['sender_name'] ?? ($_SESSION['user_name'] ?? $_SESSION['name'] ?? 'Dispatcher')));
$senderUserId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

if ($incidentId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Missing incident_id']);
    exit;
}
if ($senderName === '') {
    $senderName = 'Dispatcher';
}

try {
    $stmt = $pdo->prepare("
        SELECT id, reference_no, status
        FROM incidents
        WHERE id = ?
        LIMIT 1
    ");
    $stmt->execute([$incidentId]);
    $incident = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$incident) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Incident not found']);
        exit;
    }

    $status = strtolower(trim((string)($incident['status'] ?? '')));
    if ($status !== 'resolved' && $status !== 'cancelled') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Only resolved or cancelled incidents can be sent to admin']);
        exit;
    }

    $submission = ers_submit_incident_admin_review($pdo, $incidentId, $senderUserId, $senderName);
    if (!$submission['ok']) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to send review to admin']);
        exit;
    }

    if (!empty($submission['created'])) {
        $reference = trim((string)($incident['reference_no'] ?? ''));
        if ($reference === '') {
            $reference = '#' . $incidentId;
        }
        log_activity_event(
            $senderUserId,
            'incident_review_submitted',
            'incident',
            $incidentId,
            'Incident ' . $reference . ' review was sent to admin.'
        );
    }

    $row = is_array($submission['row'] ?? null) ? $submission['row'] : null;
    echo json_encode([
        'ok' => true,
        'created' => !empty($submission['created']),
        'admin_review' => [
            'submitted' => true,
            'sent_at' => $row['sent_at'] ?? null,
            'sent_by_name' => $row['sent_by_name'] ?? $senderName,
            'sent_by_user_id' => isset($row['sent_by_user_id']) && $row['sent_by_user_id'] !== null ? (int)$row['sent_by_user_id'] : $senderUserId,
        ],
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to send review to admin']);
}
