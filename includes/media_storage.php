<?php

if (!function_exists('media_app_base_path')) {
    function media_app_base_path(): string {
        $scriptName = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
        $dir = str_replace('\\', '/', dirname($scriptName));
        if ($dir === '/' || $dir === '\\' || $dir === '.' || $dir === '') {
            return '';
        }

        $segments = array_values(array_filter(explode('/', trim($dir, '/')), 'strlen'));
        if (!$segments) {
            return '';
        }

        $appSubfolders = ['api', 'admin', 'dispatcher', 'includes'];
        $last = strtolower((string)end($segments));
        if (in_array($last, $appSubfolders, true)) {
            array_pop($segments);
        }

        if (!$segments) {
            return '';
        }

        return '/' . implode('/', $segments);
    }
}

if (!function_exists('media_endpoint_url')) {
    function media_endpoint_url(string $path): string {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        $base = media_app_base_path();
        return ($base !== '' ? $base . '/' : '') . $normalized;
    }
}

if (!function_exists('media_table_has_column')) {
    function media_table_has_column(PDO $pdo, string $table, string $column): bool {
        $safeTable = str_replace('`', '``', $table);
        $quotedColumn = $pdo->quote($column);
        if ($quotedColumn === false) {
            return false;
        }

        $stmt = $pdo->query("SHOW COLUMNS FROM `{$safeTable}` LIKE {$quotedColumn}");
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('media_table_id_is_auto_increment')) {
    function media_table_id_is_auto_increment(PDO $pdo, string $table): bool {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `$table` LIKE 'id'");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            $extra = strtolower((string)($row['Extra'] ?? $row['extra'] ?? ''));
            return strpos($extra, 'auto_increment') !== false;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('media_insert_needs_manual_id_fallback')) {
    function media_insert_needs_manual_id_fallback(string $message): bool {
        return strpos($message, "Duplicate entry '0' for key 'PRIMARY'") !== false
            || strpos($message, "Field 'id' doesn't have a default value") !== false
            || strpos($message, "Field 'id' doesn't have a default") !== false;
    }
}

if (!function_exists('ensure_profile_images_table')) {
    function ensure_profile_images_table(PDO $pdo): void {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `user_profile_images` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `file_name` VARCHAR(255) NOT NULL,
                `mime_type` VARCHAR(150) NOT NULL,
                `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `image_blob` LONGBLOB NOT NULL,
                `is_active` TINYINT(1) NOT NULL DEFAULT 1,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_user_profile_images_user` (`user_id`),
                KEY `idx_user_profile_images_active` (`user_id`, `is_active`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
    }
}

if (!function_exists('profile_image_url')) {
    function profile_image_url(int $imageId, ?string $updatedAt = null): string {
        $url = media_endpoint_url('api/profile_image.php?image_id=' . max(0, $imageId));
        if ($updatedAt !== null && trim($updatedAt) !== '') {
            $url .= '&v=' . rawurlencode(trim($updatedAt));
        }
        return $url;
    }
}

if (!function_exists('get_active_profile_image')) {
    function get_active_profile_image(PDO $pdo, int $userId): ?array {
        if ($userId <= 0) {
            return null;
        }

        ensure_profile_images_table($pdo);

        $stmt = $pdo->prepare(
            "SELECT id, file_name, mime_type, file_size, created_at, updated_at
             FROM user_profile_images
             WHERE user_id = ? AND is_active = 1
             ORDER BY id DESC
             LIMIT 1"
        );
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!is_array($row) || empty($row)) {
            return null;
        }

        $row['id'] = (int)$row['id'];
        $row['file_size'] = (int)$row['file_size'];
        $row['url'] = profile_image_url((int)$row['id'], (string)($row['updated_at'] ?? ''));
        return $row;
    }
}

if (!function_exists('store_profile_image')) {
    function store_profile_image(PDO $pdo, int $userId, string $fileName, string $mimeType, int $fileSize, string $blob): ?array {
        if ($userId <= 0 || $fileName === '' || $mimeType === '' || $fileSize <= 0 || $blob === '') {
            return null;
        }

        ensure_profile_images_table($pdo);

        $startedTransaction = !$pdo->inTransaction();
        if ($startedTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $deactivate = $pdo->prepare("UPDATE user_profile_images SET is_active = 0 WHERE user_id = ? AND is_active = 1");
            $deactivate->execute([$userId]);

            $insert = $pdo->prepare(
                "INSERT INTO user_profile_images (user_id, file_name, mime_type, file_size, image_blob, is_active)
                 VALUES (?, ?, ?, ?, ?, 1)"
            );
            $insert->bindValue(1, $userId, PDO::PARAM_INT);
            $insert->bindValue(2, substr($fileName, 0, 255), PDO::PARAM_STR);
            $insert->bindValue(3, substr($mimeType, 0, 150), PDO::PARAM_STR);
            $insert->bindValue(4, max(0, $fileSize), PDO::PARAM_INT);
            $insert->bindValue(5, $blob, PDO::PARAM_LOB);
            $insert->execute();

            $imageId = (int)$pdo->lastInsertId();
            $row = get_active_profile_image($pdo, $userId);

            if ($startedTransaction) {
                $pdo->commit();
            }

            if (is_array($row) && !empty($row)) {
                return $row;
            }

            return [
                'id' => $imageId,
                'url' => profile_image_url($imageId),
                'file_name' => substr($fileName, 0, 255),
                'mime_type' => substr($mimeType, 0, 150),
                'file_size' => max(0, $fileSize),
                'created_at' => null,
                'updated_at' => null,
            ];
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}

if (!function_exists('ensure_interagency_attachment_uploads_table')) {
    function ensure_interagency_attachment_uploads_table(PDO $pdo): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `interagency_attachment_uploads` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT UNSIGNED NOT NULL,
                `message_id` INT DEFAULT NULL,
                `file_name` VARCHAR(255) NOT NULL,
                `mime_type` VARCHAR(150) NOT NULL,
                `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `file_blob` LONGBLOB NOT NULL,
                `is_image` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `expires_at` DATETIME NOT NULL,
                PRIMARY KEY (`id`),
                KEY `idx_interagency_attachment_uploads_user` (`user_id`),
                KEY `idx_interagency_attachment_uploads_message` (`message_id`),
                KEY `idx_interagency_attachment_uploads_exp` (`expires_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        if (!media_table_has_column($pdo, 'interagency_attachment_uploads', 'message_id')) {
            try {
                $pdo->exec("ALTER TABLE interagency_attachment_uploads ADD COLUMN message_id INT DEFAULT NULL AFTER user_id");
            } catch (Throwable $e) {
                // Ignore when production DB users lack ALTER privileges.
            }
        }

        if (!media_table_id_is_auto_increment($pdo, 'interagency_attachment_uploads')) {
            try {
                $pdo->exec("ALTER TABLE interagency_attachment_uploads MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT");
            } catch (Throwable $e) {
                // Inserts below can still fall back to manual IDs.
            }
        }
    }
}

if (!function_exists('ensure_interagency_attachments_table')) {
    function ensure_interagency_attachments_table(PDO $pdo): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS `interagency_message_attachments` (
                `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `message_id` INT NOT NULL,
                `file_name` VARCHAR(255) NOT NULL,
                `file_url` VARCHAR(500) NOT NULL DEFAULT '',
                `file_path` VARCHAR(500) DEFAULT NULL,
                `mime_type` VARCHAR(150) DEFAULT NULL,
                `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
                `file_blob` LONGBLOB DEFAULT NULL,
                `is_image` TINYINT(1) NOT NULL DEFAULT 0,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                KEY `idx_interagency_msg_attach_message` (`message_id`),
                KEY `idx_interagency_msg_attach_image` (`is_image`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        if (!media_table_has_column($pdo, 'interagency_message_attachments', 'file_path')) {
            $pdo->exec("ALTER TABLE interagency_message_attachments ADD COLUMN file_path VARCHAR(500) DEFAULT NULL AFTER file_url");
        }
        if (!media_table_has_column($pdo, 'interagency_message_attachments', 'file_blob')) {
            $pdo->exec("ALTER TABLE interagency_message_attachments ADD COLUMN file_blob LONGBLOB DEFAULT NULL AFTER file_size");
        }
        if (!media_table_id_is_auto_increment($pdo, 'interagency_message_attachments')) {
            try {
                $pdo->exec("ALTER TABLE interagency_message_attachments MODIFY id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT");
            } catch (Throwable $e) {
                // Inserts below can still fall back to manual IDs.
            }
        }
    }
}

if (!function_exists('interagency_attachment_url')) {
    function interagency_attachment_url(int $attachmentId): string {
        return media_endpoint_url('api/interagency_attachment.php?id=' . max(0, $attachmentId));
    }
}

if (!function_exists('interagency_upload_temp_url')) {
    function interagency_upload_temp_url(int $uploadId): string {
        return media_endpoint_url('api/interagency_attachment.php?temp_id=' . max(0, $uploadId));
    }
}

if (!function_exists('cleanup_expired_interagency_attachment_uploads')) {
    function cleanup_expired_interagency_attachment_uploads(PDO $pdo): void {
        ensure_interagency_attachment_uploads_table($pdo);
        $pdo->exec("DELETE FROM interagency_attachment_uploads WHERE expires_at < NOW()");
    }
}

if (!function_exists('create_interagency_attachment_upload')) {
    function create_interagency_attachment_upload(PDO $pdo, int $userId, string $fileName, string $mimeType, int $fileSize, string $blob, bool $isImage): ?array {
        if ($userId <= 0 || $fileName === '' || $mimeType === '' || $fileSize <= 0 || $blob === '') {
            return null;
        }

        ensure_interagency_attachment_uploads_table($pdo);
        cleanup_expired_interagency_attachment_uploads($pdo);

        $stmt = $pdo->prepare(
            "INSERT INTO interagency_attachment_uploads
                (user_id, file_name, mime_type, file_size, file_blob, is_image, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))"
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, substr($fileName, 0, 255), PDO::PARAM_STR);
        $stmt->bindValue(3, substr($mimeType, 0, 150), PDO::PARAM_STR);
        $stmt->bindValue(4, max(0, $fileSize), PDO::PARAM_INT);
        $stmt->bindValue(5, $blob, PDO::PARAM_LOB);
        $stmt->bindValue(6, $isImage ? 1 : 0, PDO::PARAM_INT);
        try {
            $stmt->execute();
        } catch (Throwable $e) {
            $message = (string)$e->getMessage();
            if (!media_insert_needs_manual_id_fallback($message)) {
                throw $e;
            }

            $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM interagency_attachment_uploads")->fetchColumn();
            $fallback = $pdo->prepare(
                "INSERT INTO interagency_attachment_uploads
                    (id, user_id, file_name, mime_type, file_size, file_blob, is_image, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 1 DAY))"
            );
            $fallback->bindValue(1, $nextId, PDO::PARAM_INT);
            $fallback->bindValue(2, $userId, PDO::PARAM_INT);
            $fallback->bindValue(3, substr($fileName, 0, 255), PDO::PARAM_STR);
            $fallback->bindValue(4, substr($mimeType, 0, 150), PDO::PARAM_STR);
            $fallback->bindValue(5, max(0, $fileSize), PDO::PARAM_INT);
            $fallback->bindValue(6, $blob, PDO::PARAM_LOB);
            $fallback->bindValue(7, $isImage ? 1 : 0, PDO::PARAM_INT);
            $fallback->execute();
        }

        $uploadId = (int)$pdo->lastInsertId();
        if ($uploadId <= 0) {
            $uploadId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM interagency_attachment_uploads")->fetchColumn();
        }
        return [
            'temp_id' => $uploadId,
            'name' => substr($fileName, 0, 255),
            'url' => interagency_upload_temp_url($uploadId),
            'mime_type' => substr($mimeType, 0, 150),
            'size' => max(0, $fileSize),
            'is_image' => $isImage,
        ];
    }
}

if (!function_exists('get_interagency_attachment_upload')) {
    function get_interagency_attachment_upload(PDO $pdo, int $uploadId): ?array {
        if ($uploadId <= 0) {
            return null;
        }

        ensure_interagency_attachment_uploads_table($pdo);
        cleanup_expired_interagency_attachment_uploads($pdo);

        $stmt = $pdo->prepare(
            "SELECT id, user_id, message_id, file_name, mime_type, file_size, file_blob, is_image, created_at, expires_at
             FROM interagency_attachment_uploads
             WHERE id = ?
             LIMIT 1"
        );
        $stmt->execute([$uploadId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) && !empty($row) ? $row : null;
    }
}

if (!function_exists('finalize_interagency_attachment_upload')) {
    function finalize_interagency_attachment_upload(PDO $pdo, int $messageId, int $uploadId): ?array {
        if ($messageId <= 0 || $uploadId <= 0) {
            return null;
        }

        ensure_interagency_attachments_table($pdo);
        $upload = get_interagency_attachment_upload($pdo, $uploadId);
        if (!is_array($upload) || empty($upload)) {
            return null;
        }

        $insert = $pdo->prepare(
            "INSERT INTO interagency_message_attachments
                (message_id, file_name, file_url, file_path, mime_type, file_size, file_blob, is_image)
             VALUES (?, ?, '', NULL, ?, ?, ?, ?)"
        );
        $insert->bindValue(1, $messageId, PDO::PARAM_INT);
        $insert->bindValue(2, substr((string)$upload['file_name'], 0, 255), PDO::PARAM_STR);
        $insert->bindValue(3, substr((string)$upload['mime_type'], 0, 150), PDO::PARAM_STR);
        $insert->bindValue(4, (int)$upload['file_size'], PDO::PARAM_INT);
        $insert->bindValue(5, (string)$upload['file_blob'], PDO::PARAM_LOB);
        $insert->bindValue(6, ((int)$upload['is_image'] === 1) ? 1 : 0, PDO::PARAM_INT);
        try {
            $insert->execute();
        } catch (Throwable $e) {
            $message = (string)$e->getMessage();
            if (!media_insert_needs_manual_id_fallback($message)) {
                throw $e;
            }

            $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM interagency_message_attachments")->fetchColumn();
            $fallback = $pdo->prepare(
                "INSERT INTO interagency_message_attachments
                    (id, message_id, file_name, file_url, file_path, mime_type, file_size, file_blob, is_image)
                 VALUES (?, ?, ?, '', NULL, ?, ?, ?, ?)"
            );
            $fallback->bindValue(1, $nextId, PDO::PARAM_INT);
            $fallback->bindValue(2, $messageId, PDO::PARAM_INT);
            $fallback->bindValue(3, substr((string)$upload['file_name'], 0, 255), PDO::PARAM_STR);
            $fallback->bindValue(4, substr((string)$upload['mime_type'], 0, 150), PDO::PARAM_STR);
            $fallback->bindValue(5, (int)$upload['file_size'], PDO::PARAM_INT);
            $fallback->bindValue(6, (string)$upload['file_blob'], PDO::PARAM_LOB);
            $fallback->bindValue(7, ((int)$upload['is_image'] === 1) ? 1 : 0, PDO::PARAM_INT);
            $fallback->execute();
        }

        $attachmentId = (int)$pdo->lastInsertId();
        if ($attachmentId <= 0) {
            $attachmentId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) FROM interagency_message_attachments")->fetchColumn();
        }
        $downloadUrl = interagency_attachment_url($attachmentId);
        $update = $pdo->prepare("UPDATE interagency_message_attachments SET file_url = ? WHERE id = ?");
        $update->execute([$downloadUrl, $attachmentId]);

        if (media_table_has_column($pdo, 'interagency_attachment_uploads', 'message_id')) {
            $keepUpload = $pdo->prepare(
                "UPDATE interagency_attachment_uploads
                 SET message_id = ?, expires_at = DATE_ADD(NOW(), INTERVAL 10 YEAR)
                 WHERE id = ?"
            );
            $keepUpload->execute([$messageId, $uploadId]);
        }

        return [
            'id' => $attachmentId,
            'message_id' => $messageId,
            'name' => (string)$upload['file_name'],
            'url' => $downloadUrl,
            'mime_type' => (string)$upload['mime_type'],
            'size' => (int)$upload['file_size'],
            'is_image' => ((int)$upload['is_image'] === 1),
        ];
    }
}
