<?php
declare(strict_types=1);

function ers_dispatch_attempt_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `dispatch_attempt_logs` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `incident_id` BIGINT UNSIGNED DEFAULT NULL,
            `unit_id` BIGINT UNSIGNED DEFAULT NULL,
            `unit_identifier` VARCHAR(80) DEFAULT NULL,
            `attempted_unit_ids` TEXT DEFAULT NULL,
            `attempted_by_user_id` BIGINT UNSIGNED DEFAULT NULL,
            `attempted_by_role` VARCHAR(50) DEFAULT NULL,
            `source` VARCHAR(80) NOT NULL DEFAULT 'dispatch_unit',
            `status` VARCHAR(40) NOT NULL DEFAULT 'failed',
            `recovery_status` VARCHAR(40) NOT NULL DEFAULT 'open',
            `recovery_action` VARCHAR(80) DEFAULT NULL,
            `recovered_dispatch_id` BIGINT UNSIGNED DEFAULT NULL,
            `recovered_at` DATETIME DEFAULT NULL,
            `recovery_notes` TEXT DEFAULT NULL,
            `failure_reason` TEXT DEFAULT NULL,
            `request_payload` LONGTEXT DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_dispatch_attempt_logs_incident_id` (`incident_id`),
            KEY `idx_dispatch_attempt_logs_status` (`status`),
            KEY `idx_dispatch_attempt_logs_recovery_status` (`recovery_status`),
            KEY `idx_dispatch_attempt_logs_created_at` (`created_at`),
            KEY `idx_dispatch_attempt_logs_source` (`source`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    $columns = [
        'recovery_status' => "`recovery_status` VARCHAR(40) NOT NULL DEFAULT 'open' AFTER `status`",
        'recovery_action' => "`recovery_action` VARCHAR(80) DEFAULT NULL AFTER `recovery_status`",
        'recovered_dispatch_id' => "`recovered_dispatch_id` BIGINT UNSIGNED DEFAULT NULL AFTER `recovery_action`",
        'recovered_at' => "`recovered_at` DATETIME DEFAULT NULL AFTER `recovered_dispatch_id`",
        'recovery_notes' => "`recovery_notes` TEXT DEFAULT NULL AFTER `recovered_at`",
    ];

    foreach ($columns as $columnName => $definition) {
        if (!ers_dispatch_attempt_column_exists($pdo, 'dispatch_attempt_logs', $columnName)) {
            $pdo->exec("ALTER TABLE `dispatch_attempt_logs` ADD COLUMN {$definition}");
        }
    }

    if (!ers_dispatch_attempt_index_exists($pdo, 'dispatch_attempt_logs', 'idx_dispatch_attempt_logs_recovery_status')) {
        $pdo->exec("ALTER TABLE `dispatch_attempt_logs` ADD KEY `idx_dispatch_attempt_logs_recovery_status` (`recovery_status`)");
    }
}

function ers_dispatch_attempt_column_exists(PDO $pdo, string $tableName, string $columnName): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$tableName, $columnName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ers_dispatch_attempt_index_exists(PDO $pdo, string $tableName, string $indexName): bool
{
    try {
        $stmt = $pdo->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND INDEX_NAME = ?
             LIMIT 1"
        );
        $stmt->execute([$tableName, $indexName]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ers_dispatch_attempt_mark_recovered(
    PDO $pdo,
    int $attemptId,
    string $action,
    ?int $dispatchId = null,
    string $status = 'recovered',
    string $notes = ''
): void {
    $allowedStatuses = ['open', 'recovered', 'closed'];
    if (!in_array($status, $allowedStatuses, true)) {
        $status = 'recovered';
    }

    $stmt = $pdo->prepare(
        "UPDATE dispatch_attempt_logs
         SET recovery_status = ?,
             recovery_action = ?,
             recovered_dispatch_id = ?,
             recovered_at = CURRENT_TIMESTAMP,
             recovery_notes = ?
         WHERE id = ?"
    );
    $stmt->execute([
        $status,
        $action,
        $dispatchId && $dispatchId > 0 ? $dispatchId : null,
        $notes !== '' ? $notes : null,
        $attemptId,
    ]);
}

function ers_dispatch_attempt_session_value(string $key): ?string
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        @session_start();
    }

    if (!isset($_SESSION[$key])) {
        return null;
    }

    $value = trim((string)$_SESSION[$key]);
    return $value !== '' ? $value : null;
}

function ers_dispatch_attempt_log_failed(
    PDO $pdo,
    ?int $incidentId,
    array $unitIds,
    string $reason,
    string $source,
    array $context = []
): void {
    try {
        ers_dispatch_attempt_ensure_table($pdo);

        $unitIds = array_values(array_unique(array_filter(array_map(static function ($value): int {
            return (int)$value;
        }, $unitIds), static function (int $value): bool {
            return $value > 0;
        })));

        $unitIdentifier = null;
        if (isset($context['unit_identifier'])) {
            $unitIdentifier = trim((string)$context['unit_identifier']);
            if ($unitIdentifier === '') {
                $unitIdentifier = null;
            }
        }

        $payload = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $attemptedUnits = json_encode($unitIds, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $userId = ers_dispatch_attempt_session_value('user_id');
        $role = ers_dispatch_attempt_session_value('login_role')
            ?? ers_dispatch_attempt_session_value('user_role');

        $stmt = $pdo->prepare(
            "INSERT INTO dispatch_attempt_logs
                (incident_id, unit_id, unit_identifier, attempted_unit_ids, attempted_by_user_id,
                 attempted_by_role, source, status, recovery_status, failure_reason, request_payload, created_at)
             VALUES
                (?, ?, ?, ?, ?, ?, ?, 'failed', 'open', ?, ?, CURRENT_TIMESTAMP)"
        );
        $stmt->execute([
            $incidentId && $incidentId > 0 ? $incidentId : null,
            count($unitIds) === 1 ? $unitIds[0] : null,
            $unitIdentifier,
            is_string($attemptedUnits) ? $attemptedUnits : '[]',
            $userId !== null ? (int)$userId : null,
            $role,
            $source,
            $reason,
            is_string($payload) ? $payload : '{}',
        ]);
    } catch (Throwable $e) {
        error_log('Failed dispatch attempt logging skipped: ' . $e->getMessage());
    }
}
?>
