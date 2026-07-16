<?php

require_once __DIR__ . '/vehicle_resource_units.php';

const USER_PRESENCE_ONLINE_WINDOW_SECONDS = 180;

function ensure_user_presence_table(PDO $pdo): void {
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `user_presence` (
            `user_id` INT UNSIGNED NOT NULL,
            `session_id` VARCHAR(128) DEFAULT NULL,
            `is_online` TINYINT(1) NOT NULL DEFAULT 0,
            `last_seen_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `logged_in_at` DATETIME DEFAULT NULL,
            `logged_out_at` DATETIME DEFAULT NULL,
            PRIMARY KEY (`user_id`),
            KEY `idx_user_presence_online_seen` (`is_online`, `last_seen_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function user_presence_session_id(): ?string {
    if (session_status() === PHP_SESSION_NONE) {
        return null;
    }
    $id = session_id();
    return $id !== '' ? substr(hash('sha256', $id), 0, 128) : null;
}

function mark_user_online(PDO $pdo, int $userId): void {
    if ($userId <= 0) {
        return;
    }
    ensure_user_presence_table($pdo);
    $stmt = $pdo->prepare(
        "INSERT INTO user_presence (user_id, session_id, is_online, last_seen_at, logged_in_at, logged_out_at)
         VALUES (?, ?, 1, NOW(), NOW(), NULL)
         ON DUPLICATE KEY UPDATE
            session_id = VALUES(session_id),
            is_online = 1,
            last_seen_at = NOW(),
            logged_in_at = VALUES(logged_in_at),
            logged_out_at = NULL"
    );
    $stmt->execute([$userId, user_presence_session_id()]);
    ers_sync_online_vehicle_resource_status_for_responder($pdo, $userId);
}

function touch_user_presence(PDO $pdo, int $userId): void {
    if ($userId <= 0) {
        return;
    }
    ensure_user_presence_table($pdo);
    $stmt = $pdo->prepare(
        "INSERT INTO user_presence (user_id, session_id, is_online, last_seen_at, logged_in_at, logged_out_at)
         VALUES (?, ?, 1, NOW(), NOW(), NULL)
         ON DUPLICATE KEY UPDATE
            session_id = COALESCE(VALUES(session_id), session_id),
            is_online = 1,
            last_seen_at = NOW(),
            logged_out_at = NULL"
    );
    $stmt->execute([$userId, user_presence_session_id()]);
    ers_sync_vehicle_resource_record_status_for_responder($pdo, $userId);
}

function mark_user_offline(PDO $pdo, int $userId): void {
    if ($userId <= 0) {
        return;
    }
    ensure_user_presence_table($pdo);
    $stmt = $pdo->prepare(
        "INSERT INTO user_presence (user_id, session_id, is_online, last_seen_at, logged_in_at, logged_out_at)
         VALUES (?, ?, 0, NOW(), NULL, NOW())
         ON DUPLICATE KEY UPDATE
            is_online = 0,
            last_seen_at = NOW(),
            logged_out_at = NOW()"
    );
    $stmt->execute([$userId, user_presence_session_id()]);
    ers_sync_vehicle_resource_status_for_responder($pdo, $userId, 'offline');
}

function user_presence_status_sql(string $alias = 'up'): string {
    $alias = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'up';
    return "CASE
        WHEN {$alias}.is_online = 1
         AND {$alias}.last_seen_at >= DATE_SUB(NOW(), INTERVAL " . USER_PRESENCE_ONLINE_WINDOW_SECONDS . " SECOND)
        THEN 'online'
        ELSE 'offline'
    END";
}
