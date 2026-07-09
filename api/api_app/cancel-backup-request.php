<?php
// cancel-backup-request.php
header('Content-Type: application/json');
require __DIR__ . "/connect.php";

$data = json_decode(file_get_contents('php://input'), true);

$requestId = $data['request_id'] ?? null;
$responderId = $data['responder_id'] ?? null;

if (!$requestId || !$responderId) {
    echo json_encode(['success' => false, 'message' => 'Missing request_id or responder_id']);
    exit;
}

// Only allow cancelling your own request, and only if it's still pending
$stmt = $conn->prepare("SELECT status FROM responder_backup_requests WHERE id = ? AND responder_id = ?");
$stmt->bind_param("ii", $requestId, $responderId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode(['success' => false, 'message' => 'Request not found']);
    exit;
}

$row = $result->fetch_assoc();
if ($row['status'] !== 'pending') {
    echo json_encode(['success' => false, 'message' => 'Only pending requests can be cancelled']);
    exit;
}

$update = $conn->prepare("UPDATE responder_backup_requests SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
$update->bind_param("i", $requestId);

if ($update->execute()) {
    echo json_encode(['success' => true, 'message' => 'Backup request cancelled']);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to cancel request']);
}

$conn->close();