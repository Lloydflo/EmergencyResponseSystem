<?php
// cancel-backup-request.php
header('Content-Type: application/json');
require __DIR__ . "/connect.php";

$request_id  = intval($_POST['request_id'] ?? 0);
$responder_id = intval($_POST['responder_id'] ?? 0);

if ($request_id <= 0 || $responder_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Missing request_id or responder_id']);
    exit;
}

try {
    $pdo = db();

    // Only allow cancelling your own request, and only if it's still pending
    $stmt = $pdo->prepare("SELECT status FROM responder_backup_requests WHERE id = ? AND responder_id = ?");
    $stmt->execute([$request_id, $responder_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Request not found']);
        exit;
    }

    if ($row['status'] !== 'pending') {
        echo json_encode(['success' => false, 'message' => 'Only pending requests can be cancelled']);
        exit;
    }

    $update = $pdo->prepare("UPDATE responder_backup_requests SET status = 'cancelled', updated_at = NOW() WHERE id = ?");
    $update->execute([$request_id]);

    echo json_encode(['success' => true, 'message' => 'Backup request cancelled']);

} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
}