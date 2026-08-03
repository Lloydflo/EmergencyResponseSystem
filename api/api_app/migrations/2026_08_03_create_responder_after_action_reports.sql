-- Emergency Response System
-- Installs the responder after-action report table used by:
--   upsert-after-action-report.php
--   get-my-after-action-reports.php
--   get-after-action-reports.php
--   review-after-action-report.php
--
-- Run this once against the same database configured by includes/db.php.

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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
