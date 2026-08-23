<?php
declare(strict_types=1);

require_once __DIR__ . '/_assignment.php';
require_once __DIR__ . '/../../includes/anonymous_tip_status_sync.php';

op_require_method('POST');
$assignmentId = op_post_int('assignment_id');
$responderId = op_post_int('responder_id');
$notes = op_post_string('notes', '', 10000);
op_require_positive($assignmentId, 'assignment_id');
op_require_positive($responderId, 'responder_id');

if (!isset($_FILES['proof_image']) || !is_array($_FILES['proof_image'])) {
    op_error('No proof_image file uploaded.', 400);
}
$file = $_FILES['proof_image'];
if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    op_error('Proof image upload failed.', 400);
}
$size = (int)($file['size'] ?? 0);
if ($size < 1 || $size > 5 * 1024 * 1024) {
    op_error('Proof image must be greater than 0 bytes and up to 5 MB.', 413);
}
$tmp = (string)($file['tmp_name'] ?? '');
if ($tmp === '' || !is_uploaded_file($tmp)) {
    op_error('Invalid proof image upload.', 400);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = (string)$finfo->file($tmp);
$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
$imageInfo = @getimagesize($tmp);
if (!isset($allowed[$mime]) || !is_array($imageInfo)) {
    op_error('Unsupported or invalid proof image.', 422);
}
$width = (int)($imageInfo[0] ?? 0);
$height = (int)($imageInfo[1] ?? 0);
if ($width < 1 || $height < 1 || $width > 12000 || $height > 12000 || $width * $height > 40000000) {
    op_error('Proof image dimensions are too large.', 422);
}

$directory = __DIR__ . '/../../uploads/completion_proof';
if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
    op_error('Unable to prepare proof-image storage.', 500);
}
$name = 'proof_' . $assignmentId . '_' . bin2hex(random_bytes(12)) . '.' . $allowed[$mime];
$target = $directory . '/' . $name;
$relative = '/uploads/completion_proof/' . $name;

try {
    $pdo = db();
    op_require_active_responder($pdo, $responderId);
    app_assignment_require_schema($pdo);

    $pdo->beginTransaction();
    $assignment = app_assignment_row($pdo, $assignmentId, $responderId, true);
    if ($assignment === null) {
        $pdo->rollBack();
        op_error('Assignment not found.', 404);
    }

    if (!move_uploaded_file($tmp, $target)) {
        $pdo->rollBack();
        op_error('Failed to store the proof image.', 500);
    }
    @chmod($target, 0640);

    $result = app_assignment_complete_incident(
        $pdo,
        $assignment,
        $responderId,
        $notes,
        $relative
    );

    if (!empty($result['already_completed'])) {
        @unlink($target);
        $relative = (string)($result['completion_image_path'] ?? '');
    }
    $pdo->commit();
    $anonymousTipStatusSync = ers_notify_anonymous_tip_status_result(
        $pdo,
        (int)$result['incident_id'],
        'completed',
        'Responder completed the incident.'
    );

    op_success([
        'incident_id' => (int)$result['incident_id'],
        'completion_image_path' => $relative,
        'already_completed' => (bool)($result['already_completed'] ?? false),
        'anonymous_tip_status_sync' => $anonymousTipStatusSync,
    ]);
} catch (AppAssignmentException $error) {
    if (is_file($target)) {
        @unlink($target);
    }
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    op_error($error->getMessage(), $error->httpStatus);
} catch (Throwable $error) {
    if (is_file($target)) {
        @unlink($target);
    }
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[mark-incident-complete] ' . $error->getMessage());
    op_error('Unable to complete the incident.', 500);
}
