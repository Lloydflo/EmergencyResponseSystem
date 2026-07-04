<?php
header("Content-Type: application/json");
require __DIR__ . "/connect.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    http_response_code(405);
    echo json_encode(["success" => false, "message" => "Method not allowed"]);
    exit;
}

$userId = intval($_POST["user_id"] ?? 0);
if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Missing or invalid user_id"]);
    exit;
}

if (!isset($_FILES["profile_image"])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "No profile_image file uploaded"]);
    exit;
}

$file = $_FILES["profile_image"];
if (($file["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Upload failed with error code: " . (int)$file["error"]]);
    exit;
}

$maxSizeBytes = 5 * 1024 * 1024; // 5 MB
if (($file["size"] ?? 0) <= 0 || $file["size"] > $maxSizeBytes) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "File must be greater than 0 bytes and up to 5 MB"]);
    exit;
}

$allowedMimeToExt = [
    "image/jpeg" => "jpg",
    "image/png" => "png",
    "image/webp" => "webp"
];

$detectedMime = mime_content_type($file["tmp_name"]);
if (!isset($allowedMimeToExt[$detectedMime])) {
    http_response_code(400);
    echo json_encode(["success" => false, "message" => "Unsupported image type"]);
    exit;
}

$ext = $allowedMimeToExt[$detectedMime];
$uploadDir = __DIR__ . "/../../uploads/profile/";
if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true)) {
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Failed to create upload directory"]);
    exit;
}

$newName = "profile_" . $userId . "_" . time() . "_" . bin2hex(random_bytes(4)) . "." . $ext;
$targetPath = $uploadDir . $newName;
$relativePath = "/uploads/profile/" . $newName;

try {
    $pdo = db();

    $checkStmt = $pdo->prepare("SELECT id, role, profile_image_path FROM users WHERE id = ? LIMIT 1");
    $checkStmt->execute([$userId]);
    $user = $checkStmt->fetch();

    if (!$user || (string)($user["role"] ?? "") !== "responder") {
        http_response_code(404);
        echo json_encode([
            "success" => false,
            "message" => "Responder account not found",
            "error_code" => "RESPONDER_NOT_FOUND"
        ]);
        exit;
    }

    if (!move_uploaded_file($file["tmp_name"], $targetPath)) {
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Failed to store uploaded file"]);
        exit;
    }

    $upd = $pdo->prepare("UPDATE users SET profile_image_path = ?, updated_at = NOW() WHERE id = ?");
    $ok = $upd->execute([$relativePath, $userId]);

    if (!$ok) {
        @unlink($targetPath);
        http_response_code(500);
        echo json_encode(["success" => false, "message" => "Failed to update profile image path"]);
        exit;
    }

    $scheme = (!empty($_SERVER["HTTPS"]) && $_SERVER["HTTPS"] !== "off") ? "https" : "http";
    $host = $_SERVER["HTTP_HOST"] ?? "";
    $fileUrl = $host !== "" ? ($scheme . "://" . $host . $relativePath) : $relativePath;

    echo json_encode([
        "success" => true,
        "message" => "Profile image uploaded successfully",
        "user_id" => $userId,
        "profile_image_path" => $relativePath,
        "profile_image_url" => $fileUrl
    ]);
} catch (Throwable $e) {
    if (file_exists($targetPath)) {
        @unlink($targetPath);
    }
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
}

