<?php
header("Content-Type: application/json");

$baseUrl = "https://emergency-response.alertaraqc.com"; // Adjust this to your actual base URL
$uploadDir = __DIR__ . "/../../uploads/chat/";

if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

if (!isset($_FILES["file"])) {
    echo json_encode([
        "success" => false,
        "message" => "No file uploaded"
    ]);
    exit;
}

$file = $_FILES["file"];
$originalName = basename($file["name"]);
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

$mime = mime_content_type($file["tmp_name"]);

$mimeMap = [
    "image/jpeg" => "jpg",
    "image/png"  => "png",
    "image/webp" => "webp",
    "application/pdf" => "pdf",
    "application/msword" => "doc",
    "application/vnd.openxmlformats-officedocument.wordprocessingml.document" => "docx"
];

if (empty($extension) || !in_array($extension, ["jpg", "jpeg", "png", "webp", "pdf", "doc", "docx"])) {
    if (isset($mimeMap[$mime])) {
        $extension = $mimeMap[$mime];
    } else {
        echo json_encode([
            "success" => false,
            "message" => "File type not allowed: " . $mime
        ]);
        exit;
    }
}

$newName = "chat_" . time() . "_" . uniqid() . "." . $extension;
$targetPath = $uploadDir . $newName;

if (move_uploaded_file($file["tmp_name"], $targetPath)) {
    echo json_encode([
        "success" => true,
        "file_url" => $baseUrl . "/uploads/chat/" . $newName,
        "file_name" => $originalName,
        "file_type" => $extension
    ]);
} else {
    echo json_encode([
        "success" => false,
        "message" => "Upload failed"
    ]);
}
?>