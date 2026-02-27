<?php
// API endpoint: /api/incident_feedback.php
// Accepts POST (add feedback) and GET (list feedback for incident)
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $incident_id = (int)($input['incident_id'] ?? 0);
    $author_name = trim((string)($input['author_name'] ?? 'Anonymous'));
    $note = trim((string)($input['note'] ?? ''));
    if ($incident_id < 1 || $note === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Missing required fields']);
        exit;
    }
    $stmt = $pdo->prepare('INSERT INTO incident_notes (incident_id, author_name, note) VALUES (?, ?, ?)');
    $stmt->execute([$incident_id, $author_name, $note]);
    echo json_encode(['ok' => true]);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $incident_id = isset($_GET['incident_id']) ? (int)$_GET['incident_id'] : 0;
    if ($incident_id < 1) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'Missing incident_id']);
        exit;
    }
    $stmt = $pdo->prepare('SELECT author_name, note, created_at FROM incident_notes WHERE incident_id = ? ORDER BY created_at DESC');
    $stmt->execute([$incident_id]);
    $notes = $stmt->fetchAll();
    echo json_encode(['ok' => true, 'data' => $notes]);
    exit;
}
echo json_encode(['ok' => false, 'error' => 'Invalid method']);
