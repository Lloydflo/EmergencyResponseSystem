<?php

function ers_user_account_has_column(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function ers_user_account_has_index(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetchColumn();
}

function ers_ensure_user_inactive_cleanup_schema(PDO $pdo): void
{
    if (!ers_user_account_has_column($pdo, 'users', 'inactive_at')) {
        $pdo->exec("ALTER TABLE `users` ADD COLUMN `inactive_at` TIMESTAMP NULL DEFAULT NULL AFTER `status`");
    }

    if (!ers_user_account_has_index($pdo, 'users', 'idx_users_inactive_at')) {
        $pdo->exec("ALTER TABLE `users` ADD KEY `idx_users_inactive_at` (`inactive_at`)");
    }
}

function ers_backfill_inactive_user_dates(PDO $pdo): void
{
    $pdo->exec(
        "UPDATE `users`
         SET `inactive_at` = COALESCE(`updated_at`, `last_login`, `created_at`, NOW())
         WHERE LOWER(`status`) = 'inactive'
           AND `inactive_at` IS NULL"
    );

    $pdo->exec(
        "UPDATE `users`
         SET `inactive_at` = NULL
         WHERE LOWER(`status`) = 'active'
           AND `inactive_at` IS NOT NULL"
    );
}

function ers_purge_inactive_user_accounts(PDO $pdo): int
{
    ers_ensure_user_inactive_cleanup_schema($pdo);
    ers_backfill_inactive_user_dates($pdo);

    $stmt = $pdo->prepare(
        "DELETE FROM `users`
         WHERE LOWER(`status`) = 'inactive'
           AND `inactive_at` IS NOT NULL
           AND `inactive_at` <= DATE_SUB(NOW(), INTERVAL 30 DAY)"
    );
    $stmt->execute();

    return $stmt->rowCount();
}
