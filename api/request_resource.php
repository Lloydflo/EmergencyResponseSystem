<?php
// api/request_resource.php
require_once '../includes/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database connection failed']);
    exit;
}

// Ensure the resource_requests table exists to avoid runtime errors
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `resource_requests` (
        `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        `requestor` VARCHAR(150) NOT NULL,
        `resource_name` VARCHAR(200) NOT NULL,
        `date_requested` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `status` ENUM('pending','approved','rejected','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
        `details` TEXT NOT NULL,
        PRIMARY KEY (`id`),
        KEY `idx_rr_status` (`status`),
        KEY `idx_rr_date_requested` (`date_requested`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
} catch (Throwable $e) {
    // Continue; if creation fails, insert will surface the error below
}

// Get POST data
$requestor = $_POST['requestor'] ?? '';
$resource_name = $_POST['resource_name'] ?? '';
$resource_type = $_POST['resource_type'] ?? '';
$quantity = isset($_POST['quantity']) ? (int)$_POST['quantity'] : 1;
$priority = $_POST['priority'] ?? 'medium';
$location = $_POST['location'] ?? '';
$notes = $_POST['notes'] ?? '';
$urgency = $_POST['urgency'] ?? 'normal';
$request_kind = $_POST['request_kind'] ?? 'standard';
$incident_id = isset($_POST['incident_id']) ? (int)$_POST['incident_id'] : 0;
$incident_code = $_POST['incident_code'] ?? '';
$incident_title = $_POST['incident_title'] ?? '';
$selected_resources_json = $_POST['selected_resources_json'] ?? '[]';

$selected_resources = json_decode((string)$selected_resources_json, true);
if (!is_array($selected_resources)) {
    $selected_resources = [];
}

if (!$requestor || !$resource_name || !$resource_type) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

try {
    $stmt = $pdo->prepare('INSERT INTO resource_requests (requestor, resource_name, date_requested, status, details) VALUES (?, ?, NOW(), "pending", ? )');
    $details = json_encode([
        'type' => $resource_type,
        'quantity' => $quantity,
        'priority' => $priority,
        'location' => $location,
        'notes' => $notes,
        'urgency' => $urgency,
        'request_kind' => $request_kind,
        'incident_id' => $incident_id,
        'incident_code' => $incident_code,
        'incident_title' => $incident_title,
        'selected_resources' => $selected_resources
    ]);
    $stmt->execute([$requestor, $resource_name, $details]);
    echo json_encode(['success' => true]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
