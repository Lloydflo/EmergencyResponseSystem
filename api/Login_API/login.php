<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Method not allowed. Use POST.',
        'user' => null,
    ]);
    exit;
}

require_once dirname(__DIR__, 2) . '/includes/db.php';

function login_api_respond(int $statusCode, array $payload): void
{
    http_response_code($statusCode);
    echo json_encode($payload);
    exit;
}

function login_api_normalize_role(?string $role): string
{
    $value = strtolower(trim((string)$role));
    $value = str_replace(['-', '_'], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value ?? '');
    $value = trim((string)$value);

    if ($value === 'responder') {
        return 'responder';
    }

    if ($value === 'dispatch' || $value === 'dispatch operator' || $value === 'operator') {
        return 'dispatcher';
    }

    if ($value === 'admin' || $value === 'administrator') {
        return 'admin';
    }

    return $value;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$email = trim((string)($input['email'] ?? ''));

if ($email === '') {
    login_api_respond(422, [
        'success' => false,
        'message' => 'Email is required.',
        'user' => null,
    ]);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    login_api_respond(422, [
        'success' => false,
        'message' => 'Please enter a valid email address.',
        'user' => null,
    ]);
}

$pdo = get_db_connection();
if (!$pdo) {
    login_api_respond(500, [
        'success' => false,
        'message' => 'Database connection failed.',
        'user' => null,
    ]);
}

try {
    $stmt = $pdo->prepare(
        'SELECT `id`, `email`, `password`, `name`, `department`, `role`, `status`, `last_login`
         FROM `users`
         WHERE LOWER(`email`) = LOWER(?)
         ORDER BY `id` DESC
         LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        login_api_respond(401, [
            'success' => false,
            'message' => 'Invalid email or password.',
            'user' => null,
        ]);
    }

    if (login_api_normalize_role((string)($user['role'] ?? '')) !== 'responder') {
        login_api_respond(403, [
            'success' => false,
            'message' => 'This login API is for responder accounts only.',
            'user' => null,
        ]);
    }

    if (strtolower((string)($user['status'] ?? 'inactive')) !== 'active') {
        login_api_respond(403, [
            'success' => false,
            'message' => 'Account is inactive.',
            'user' => null,
        ]);
    }

    $updateStmt = $pdo->prepare('UPDATE `users` SET `last_login` = NOW() WHERE `id` = ?');
    $updateStmt->execute([(int)$user['id']]);

    login_api_respond(200, [
        'success' => true,
        'message' => 'Login successful.',
        'user' => [
            'id' => (int)($user['id'] ?? 0),
            'name' => (string)($user['name'] ?? ''),
            'email' => (string)($user['email'] ?? ''),
            'department' => (string)($user['department'] ?? ''),
            'role' => 'responder',
            'status' => (string)($user['status'] ?? ''),
            'last_login' => $user['last_login'] ?: null,
        ],
    ]);
} catch (Throwable $e) {
    error_log('Login_API error: ' . $e->getMessage());
    login_api_respond(500, [
        'success' => false,
        'message' => 'Server error.',
        'user' => null,
    ]);
}
