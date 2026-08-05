<?php
declare(strict_types=1);

require_once __DIR__ . '/_operational_api.php';
require_once __DIR__ . '/_assignment.php';

// Run from cron every five minutes, or call over HTTPS with X-Presence-Cron-Key.
$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    op_require_method('POST');
    $configuredKey = trim((string)(
        getenv('APP_PRESENCE_CRON_KEY')
        ?: ($_ENV['APP_PRESENCE_CRON_KEY'] ?? '')
    ));
    $providedKey = trim((string)(
        $_SERVER['HTTP_X_PRESENCE_CRON_KEY']
        ?? op_post_string('presence_cron_key', '', 512)
    ));
    if (
        $configuredKey === ''
        || $providedKey === ''
        || !hash_equals($configuredKey, $providedKey)
    ) {
        op_error('Presence maintenance authorization failed.', 403);
    }
}

try {
    $pdo = db();
    op_require_tables($pdo, ['user_presence', 'users']);
    op_require_columns($pdo, 'user_presence', [
        'user_id', 'is_online', 'last_seen_at', 'logged_out_at'
    ]);
    op_require_columns($pdo, 'users', ['id', 'unit_status', 'updated_at']);

    $pdo->beginTransaction();

    $staleStatement = $pdo->query(
        "SELECT user_id FROM user_presence "
        . "WHERE is_online = 1 "
        . "AND last_seen_at < NOW() - INTERVAL 60 MINUTE "
        . "FOR UPDATE"
    );
    $staleIds = array_values(array_filter(array_map(
        static fn(array $row): int => (int)($row['user_id'] ?? 0),
        $staleStatement->fetchAll(PDO::FETCH_ASSOC) ?: []
    ), static fn(int $id): bool => $id > 0));

    if ($staleIds === []) {
        $pdo->commit();
        op_success([
            'message' => 'No stale responder presence records.',
            'expired' => 0,
            'idle_units_marked_offline' => 0,
        ]);
    }

    $placeholders = implode(',', array_fill(0, count($staleIds), '?'));
    $offlinePresence = $pdo->prepare(
        "UPDATE user_presence SET is_online = 0, logged_out_at = NOW() "
        . "WHERE user_id IN ($placeholders)"
    );
    $offlinePresence->execute($staleIds);

    $idleUnitsMarkedOffline = 0;
    $activeAssignmentsPreserved = 0;
    foreach ($staleIds as $responderId) {
        $operationalStatus = app_assignment_current_unit_status($pdo, $responderId);
        if ($operationalStatus === 'available') {
            app_assignment_set_unit_status($pdo, $responderId, '', 'offline');
            $idleUnitsMarkedOffline++;
        } else {
            // Presence expires, but an assigned/en-route/on-scene unit stays busy.
            app_assignment_set_unit_status($pdo, $responderId, '', $operationalStatus);
            $activeAssignmentsPreserved++;
        }
    }

    $pdo->commit();
    op_success([
        'message' => 'Stale responder presence expired.',
        'expired' => count($staleIds),
        'idle_units_marked_offline' => $idleUnitsMarkedOffline,
        'active_assignments_preserved' => $activeAssignmentsPreserved,
        'timeout_minutes' => 60,
    ]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[expire-stale-presence] ' . $error->getMessage());
    op_error('Unable to expire stale responder presence.', 500);
}
