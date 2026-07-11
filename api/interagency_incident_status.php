<?php
// Records accept/decline decision for an incident card shared in a conversation
header('Content-Type: application/json');

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/auth.php';

function ensure_interagency_incident_cards_table_api(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_incident_cards` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `message_id` INT NOT NULL,
            `incident_id` INT NOT NULL,
            `reference_no` VARCHAR(120) NOT NULL DEFAULT '',
            `status` ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
            `decided_by` INT UNSIGNED DEFAULT NULL,
            `decided_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_incident_card_message` (`message_id`),
            KEY `idx_interagency_incident_card_incident` (`incident_id`),
            KEY `idx_interagency_incident_card_status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode($raw, true);
$input = is_array($input) ? $input : [];
if (!$input && !empty($_POST)) {
    $input = $_POST;
}

$messageId = isset($input['message_id']) ? (int)$input['message_id'] : 0;
$incidentId = isset($input['incident_id']) ? (int)$input['incident_id'] : 0;
$status = isset($input['status']) ? strtolower(trim((string)$input['status'])) : '';

if ($messageId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing message_id']);
    exit;
}

if (!in_array($status, ['pending', 'accepted', 'declined'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid status']);
    exit;
}

$userId = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : 0;

try {
    ensure_interagency_incident_cards_table_api($pdo);

    if ($status === 'pending') {
        $stmt = $pdo->prepare(
            "INSERT IGNORE INTO interagency_incident_cards (message_id, incident_id, status, created_at)
             VALUES (?, ?, 'pending', NOW())"
        );
        $stmt->execute([$messageId, $incidentId]);
        $statusValue = 'pending';
        $decidedBy = null;
        $decidedAt = null;
    } else {
        $stmt = $pdo->prepare(
            "INSERT INTO interagency_incident_cards (message_id, incident_id, status, decided_by, decided_at, created_at)
             VALUES (?, ?, ?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                status = VALUES(status),
                decided_by = VALUES(decided_by),
                decided_at = NOW()"
        );
        $stmt->execute([$messageId, $incidentId, $status, $userId > 0 ? $userId : null]);
        $statusValue = $status;
        $decidedBy = $userId > 0 ? $userId : null;
        $decidedAt = date('Y-m-d H:i:s');
    }

    echo json_encode([
        'ok' => true,
        'message_id' => $messageId,
        'incident_id' => $incidentId,
        'status' => $statusValue,
        'decided_by' => $decidedBy,
        'decided_at' => $decidedAt
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Update failed', 'detail' => $e->getMessage()]);
}
