<?php
// Emergency allocation: logs activation and returns a summary
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/gemini_helper.php';
require_once __DIR__ . '/../includes/auth.php';
$pdo = get_db_connection();
if (!$pdo) { http_response_code(500); echo json_encode(['ok'=>false,'error'=>'DB unavailable']); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok'=>false,'error'=>'Method not allowed']); exit; }
try {
    // Log activity with a valid integer user/entity id
    $userId = is_logged_in() ? (int)($_SESSION['user_id'] ?? 0) : 0;
    log_activity($pdo, $userId, 'emergency_allocation', 'system', 0, 'Emergency allocation protocol activated');
    // Summary counts
    $availableUnits = (int)$pdo->query("SELECT COUNT(*) AS c FROM units WHERE status='available'")->fetch()['c'];
    $onDutyStaff = (int)$pdo->query("SELECT COUNT(*) AS c FROM staff WHERE status IN ('available','on_duty')")->fetch()['c'];
    $readyEquipment = (int)$pdo->query("SELECT COUNT(*) AS c FROM resources WHERE type='equipment' AND status='available'")->fetch()['c'];
    echo json_encode(['ok'=>true,'summary'=>['units_available'=>$availableUnits,'staff_ready'=>$onDutyStaff,'equipment_ready'=>$readyEquipment]]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'Activation failed']);
}
