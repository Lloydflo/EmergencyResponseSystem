<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/responder_app_invitation.php';

function responder_invite_respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload);
    exit;
}

if (!is_logged_in()) {
    responder_invite_respond(401, [
        'success' => false,
        'message' => 'Login required.',
    ]);
}

if (current_session_role() !== 'admin') {
    responder_invite_respond(403, [
        'success' => false,
        'message' => 'Admin access required.',
    ]);
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    responder_invite_respond(405, [
        'success' => false,
        'message' => 'Method not allowed.',
    ]);
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$userId = (int)($input['user_id'] ?? $input['id'] ?? 0);
if ($userId <= 0) {
    responder_invite_respond(422, [
        'success' => false,
        'message' => 'A valid responder id is required.',
    ]);
}

$pdo = get_db_connection();
if (!$pdo instanceof PDO) {
    responder_invite_respond(503, [
        'success' => false,
        'message' => 'Database connection failed.',
    ]);
}

$stmt = $pdo->prepare(
    "SELECT `id`, `email`, `name`, COALESCE(`department`, '') AS `department`
     FROM `users`
     WHERE `id` = ?
       AND LOWER(`role`) = 'responder'
       AND LOWER(`status`) = 'active'
     LIMIT 1"
);
$stmt->execute([$userId]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!is_array($user)) {
    responder_invite_respond(404, [
        'success' => false,
        'message' => 'Active responder account not found.',
    ]);
}

$result = ers_send_responder_app_invitation_email(
    (int)$user['id'],
    (string)$user['email'],
    (string)$user['name'],
    (string)$user['department']
);

responder_invite_respond($result['sent'] ? 200 : 502, [
    'success' => (bool)$result['sent'],
    'message' => (string)$result['message'],
    'invitation_sent' => (bool)$result['sent'],
]);
