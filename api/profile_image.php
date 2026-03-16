<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/media_storage.php';

if (!is_logged_in()) {
    http_response_code(401);
    exit;
}

$imageId = isset($_GET['image_id']) ? (int)$_GET['image_id'] : 0;
$userId = isset($_GET['user_id']) ? (int)$_GET['user_id'] : 0;

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    exit;
}

ensure_profile_images_table($pdo);

try {
    if ($imageId > 0) {
        $stmt = $pdo->prepare(
            "SELECT id, file_name, mime_type, file_size, image_blob
             FROM user_profile_images
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->execute([$imageId]);
    } else {
        if ($userId <= 0) {
            $userId = (int)($_SESSION['user_id'] ?? 0);
        }
        $stmt = $pdo->prepare(
            "SELECT id, file_name, mime_type, file_size, image_blob
             FROM user_profile_images
             WHERE user_id = ? AND is_active = 1
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([$userId]);
    }

    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row) || empty($row) || empty($row['image_blob'])) {
        http_response_code(404);
        exit;
    }

    $mimeType = trim((string)($row['mime_type'] ?? 'application/octet-stream'));
    $fileName = trim((string)($row['file_name'] ?? 'profile-image'));
    $blob = (string)$row['image_blob'];

    header('Content-Type: ' . $mimeType);
    header('Content-Length: ' . strlen($blob));
    header('Content-Disposition: inline; filename="' . rawurlencode($fileName) . '"');
    header('Cache-Control: private, max-age=300');
    echo $blob;
} catch (Throwable $e) {
    http_response_code(500);
}
