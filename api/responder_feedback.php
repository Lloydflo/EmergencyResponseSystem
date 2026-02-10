<?php
// API: Submit feedback from dispatched responders
// POST JSON: { incident_id: number, note: string, responder_name?: string, unit_id?: number, unit_identifier?: string }
// Returns: { ok: true }
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$inputRaw = file_get_contents('php://input');
$input = json_decode($inputRaw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
    exit;
}

$incidentId = (int)($input['incident_id'] ?? 0);
$note = trim((string)($input['note'] ?? ''));
$responderName = trim((string)($input['responder_name'] ?? ''));
$unitIdentifier = trim((string)($input['unit_identifier'] ?? ''));

if ($incidentId < 1 || $note === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
    exit;
}

// Build author_name to clearly mark as responder feedback
$author = 'Responder: ' . ($responderName !== '' ? $responderName : ($unitIdentifier !== '' ? $unitIdentifier : 'Unknown'));

try {
    $stmt = $pdo->prepare('INSERT INTO incident_notes (incident_id, author_name, note) VALUES (?, ?, ?)');
    $stmt->execute([$incidentId, $author, $note]);
    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('Responder feedback error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Failed to save feedback']);
}
