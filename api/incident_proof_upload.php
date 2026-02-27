<?php
// API: Upload resolution proof image for an incident
// Accepts multipart/form-data with fields: incident_id (int), proof (file) OR image_base64 (data URL/base64)
// Returns { ok: true, url: "/images/proofs/.." }
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/db.php';
$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// Validate incident_id
$incidentId = 0;
if (isset($_POST['incident_id'])) {
    $incidentId = (int)$_POST['incident_id'];
}
if ($incidentId < 1) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Missing incident_id']);
    exit;
}

// Ensure output directory exists
$baseDir = realpath(__DIR__ . '/../');
$proofDir = $baseDir . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'proofs';
if (!is_dir($proofDir)) {
    if (!mkdir($proofDir, 0775, true) && !is_dir($proofDir)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'Unable to create proofs directory']);
        exit;
    }
}

function sanitize_filename($name) {
    $name = preg_replace('/[^A-Za-z0-9._-]/', '_', $name);
    return trim($name, '_');
}

$filename = null;
$filePath = null;
$contentType = null;

try {
    if (!empty($_FILES['proof']) && $_FILES['proof']['error'] === UPLOAD_ERR_OK) {
        $tmp = $_FILES['proof']['tmp_name'];
        $origName = $_FILES['proof']['name'] ?? ('incident_' . $incidentId);
        $contentType = mime_content_type($tmp) ?: '';
        // Accept only common image types
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
        if (!isset($allowed[$contentType])) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Unsupported image type']);
            exit;
        }
        $ext = $allowed[$contentType];
        $filename = 'incident_' . $incidentId . '_' . date('Ymd_His') . '_' . sanitize_filename($origName) . '.' . $ext;
        $filePath = $proofDir . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($tmp, $filePath)) {
            throw new RuntimeException('Failed to move uploaded file');
        }
    } elseif (!empty($_POST['image_base64'])) {
        $data = $_POST['image_base64'];
        // Expect formats like: data:image/jpeg;base64,.... or raw base64
        if (preg_match('/^data:(image\/(jpeg|png|webp));base64,/', $data, $m)) {
            $contentType = $m[1];
            $ext = $m[2] === 'jpeg' ? 'jpg' : $m[2];
            $data = preg_replace('/^data:image\/(jpeg|png|webp);base64,/', '', $data);
        } else {
            // Default to jpg
            $contentType = 'image/jpeg';
            $ext = 'jpg';
        }
        $bin = base64_decode($data, true);
        if ($bin === false) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Invalid base64 data']);
            exit;
        }
        $filename = 'incident_' . $incidentId . '_' . date('Ymd_His') . '_capture.' . $ext;
        $filePath = $proofDir . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($filePath, $bin) === false) {
            throw new RuntimeException('Failed to write image file');
        }
    } else {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'No image provided']);
        exit;
    }

    // Build relative URL
    $relativeUrl = '/images/proofs/' . $filename;

    // Record in DB: prefer incident_proofs table if exists, else fallback to incident_notes
    $tableExists = false;
    try {
        $chk = $pdo->query("SHOW TABLES LIKE 'incident_proofs'");
        $tableExists = (bool)$chk->fetchColumn();
    } catch (Throwable $e) {
        $tableExists = false;
    }

    if ($tableExists) {
        $stmt = $pdo->prepare('INSERT INTO incident_proofs (incident_id, file_path) VALUES (?, ?)');
        $stmt->execute([$incidentId, $relativeUrl]);
    } else {
        $stmt = $pdo->prepare("INSERT INTO incident_notes (incident_id, author_name, note) VALUES (?, 'System', ?)");
        $stmt->execute([$incidentId, 'Resolution proof uploaded: ' . $relativeUrl]);
    }

    echo json_encode(['ok' => true, 'url' => $relativeUrl]);
} catch (Throwable $e) {
    error_log('Proof upload error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Upload failed']);
}
