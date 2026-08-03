<?php
declare(strict_types=1);

/**
 * Database bootstrap for responder after-action reports.
 *
 * The normal deployment path is the SQL migration in
 * migrations/2026_08_03_create_responder_after_action_reports.sql. The API
 * also performs a safe CREATE TABLE IF NOT EXISTS so an installation whose
 * database user has DDL permission can recover automatically.
 */

function op_after_action_table_exists(PDO $pdo): bool
{
    $statement = $pdo->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES '
        . 'WHERE TABLE_SCHEMA = DATABASE() '
        . 'AND TABLE_NAME = \'responder_after_action_reports\' LIMIT 1'
    );
    $statement->execute();
    return (bool)$statement->fetchColumn();
}

function op_after_action_create_table_sql(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS `responder_after_action_reports` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `incident_id` BIGINT UNSIGNED NOT NULL,
    `responder_id` BIGINT UNSIGNED NOT NULL,
    `incident_type` VARCHAR(100) NOT NULL DEFAULT 'general',
    `responder_name` VARCHAR(150) NOT NULL DEFAULT 'Responder',
    `operational_outcome` VARCHAR(80) NOT NULL DEFAULT 'Resolved',
    `incident_summary` MEDIUMTEXT NOT NULL,
    `actions_taken` MEDIUMTEXT NOT NULL,
    `persons_assisted` INT UNSIGNED NOT NULL DEFAULT 0,
    `injuries` INT UNSIGNED NOT NULL DEFAULT 0,
    `fatalities` INT UNSIGNED NOT NULL DEFAULT 0,
    `resources_used` MEDIUMTEXT NOT NULL,
    `agencies_involved` MEDIUMTEXT NOT NULL,
    `handoff_details` MEDIUMTEXT NOT NULL,
    `safety_issues` MEDIUMTEXT NOT NULL,
    `follow_up_required` TINYINT(1) NOT NULL DEFAULT 0,
    `follow_up_details` MEDIUMTEXT NOT NULL,
    `lessons_learned` MEDIUMTEXT NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
    `reviewer_user_id` BIGINT UNSIGNED DEFAULT NULL,
    `reviewer_notes` MEDIUMTEXT NULL,
    `submitted_at` DATETIME DEFAULT NULL,
    `reviewed_at` DATETIME DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_aar_incident_responder` (`incident_id`, `responder_id`),
    KEY `idx_aar_responder_updated` (`responder_id`, `updated_at`),
    KEY `idx_aar_status_updated` (`status`, `updated_at`),
    KEY `idx_aar_incident` (`incident_id`),
    KEY `idx_aar_reviewer` (`reviewer_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
}

function op_require_after_action_schema(PDO $pdo): void
{
    if (op_after_action_table_exists($pdo)) {
        return;
    }

    try {
        $pdo->exec(op_after_action_create_table_sql());
    } catch (Throwable $error) {
        error_log(
            '[api_app] after-action schema bootstrap failed: '
            . $error->getMessage()
        );
        op_error(
            'After-action reporting is not installed. Run the supplied database migration.',
            503,
            [
                'migration' => 'migrations/2026_08_03_create_responder_after_action_reports.sql',
            ]
        );
    }

    if (!op_after_action_table_exists($pdo)) {
        op_error(
            'After-action reporting is not installed. Run the supplied database migration.',
            503,
            [
                'migration' => 'migrations/2026_08_03_create_responder_after_action_reports.sql',
            ]
        );
    }
}
