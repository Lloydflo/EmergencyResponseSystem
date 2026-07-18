<?php
declare(strict_types=1);

if (!function_exists('ers_ensure_incident_admin_reviews')) {
    function ers_ensure_incident_admin_reviews(PDO $pdo): bool
    {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS `incident_admin_reviews` (
                    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    `incident_id` BIGINT UNSIGNED NOT NULL,
                    `sent_by_user_id` INT NULL,
                    `sent_by_name` VARCHAR(150) NULL,
                    `sent_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `uk_incident_admin_reviews_incident` (`incident_id`),
                    KEY `idx_incident_admin_reviews_sent_at` (`sent_at`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            return true;
        } catch (Throwable $e) {
            error_log('Incident admin review table unavailable: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('ers_fetch_incident_admin_review')) {
    function ers_fetch_incident_admin_review(PDO $pdo, int $incidentId): ?array
    {
        if ($incidentId < 1 || !ers_ensure_incident_admin_reviews($pdo)) {
            return null;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT id, incident_id, sent_by_user_id, sent_by_name, sent_at, updated_at
                FROM incident_admin_reviews
                WHERE incident_id = ?
                LIMIT 1
            ");
            $stmt->execute([$incidentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            return null;
        }
    }
}

if (!function_exists('ers_submit_incident_admin_review')) {
    function ers_submit_incident_admin_review(PDO $pdo, int $incidentId, ?int $userId, string $senderName): array
    {
        if ($incidentId < 1) {
            return ['ok' => false, 'created' => false, 'row' => null];
        }
        if (!ers_ensure_incident_admin_reviews($pdo)) {
            return ['ok' => false, 'created' => false, 'row' => null];
        }

        $senderName = trim($senderName) !== '' ? trim($senderName) : 'Dispatcher';
        $userId = ($userId !== null && $userId > 0) ? $userId : null;
        $existing = ers_fetch_incident_admin_review($pdo, $incidentId);
        if ($existing) {
            return ['ok' => true, 'created' => false, 'row' => $existing];
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO incident_admin_reviews (incident_id, sent_by_user_id, sent_by_name)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$incidentId, $userId, $senderName]);
            return [
                'ok' => true,
                'created' => true,
                'row' => ers_fetch_incident_admin_review($pdo, $incidentId),
            ];
        } catch (Throwable $e) {
            $row = ers_fetch_incident_admin_review($pdo, $incidentId);
            if ($row) {
                return ['ok' => true, 'created' => false, 'row' => $row];
            }
            error_log('Incident admin review submit failed: ' . $e->getMessage());
            return ['ok' => false, 'created' => false, 'row' => null];
        }
    }
}
