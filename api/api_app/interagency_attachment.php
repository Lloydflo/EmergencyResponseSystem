<?php
require_once __DIR__ . "/connect.php";

try {
    $pdo = db();

    $attachmentId = isset($_GET["id"]) ? intval($_GET["id"]) : 0;

    if ($attachmentId <= 0) {
        http_response_code(400);
        exit;
    }

    $stmt = $pdo->prepare("
        SELECT 
            id,
            file_name,
            mime_type,
            file_size,
            file_blob,
            file_url
        FROM interagency_message_attachments
        WHERE id = ?
        LIMIT 1
    ");

    $stmt->execute([$attachmentId]);
    $row = $stmt->fetch();

    if (!$row) {
        http_response_code(404);
        exit;
    }

    if (!empty($row["file_blob"])) {
        $mimeType = trim($row["mime_type"] ?? "application/octet-stream");
        $fileName = trim($row["file_name"] ?? "attachment");
        $blob = $row["file_blob"];

        header("Content-Type: " . $mimeType);
        header("Content-Length: " . strlen($blob));
        header("Cache-Control: public, max-age=300");
        header("Content-Disposition: inline; filename=\"" . basename($fileName) . "\"");

        echo $blob;
        exit;
    }

    if (!empty($row["file_url"])) {
        $url = $row["file_url"];

        if (!str_starts_with($url, "http")) {
            $url = "https://emergency-response.alertaraqc.com/" . ltrim($url, "/");
        }

        header("Location: " . $url);
        exit;
    }

    http_response_code(404);

} catch (Throwable $e) {
    http_response_code(500);
}