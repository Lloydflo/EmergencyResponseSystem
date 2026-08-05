<?php

function ensure_interagency_group_member_requests_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `interagency_group_member_requests` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `group_id` BIGINT UNSIGNED NOT NULL,
            `requested_user_id` INT UNSIGNED NOT NULL,
            `requested_by_user_id` INT UNSIGNED NOT NULL,
            `status` ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
            `reviewed_by_user_id` INT UNSIGNED DEFAULT NULL,
            `reviewed_at` DATETIME DEFAULT NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_interagency_group_member_request` (`group_id`, `requested_user_id`),
            KEY `idx_interagency_group_member_request_status` (`group_id`, `status`),
            KEY `idx_interagency_group_member_request_requester` (`requested_by_user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}
