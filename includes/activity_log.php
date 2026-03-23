<?php
/**
 * Activity log helper
 */

if (!function_exists('ensure_activity_log_auto_increment')) {
    function ensure_activity_log_auto_increment(PDO $pdo): void {
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM activity_log LIKE 'id'");
            $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : null;
            $extra = strtolower((string)($row['Extra'] ?? $row['extra'] ?? ''));
            if (strpos($extra, 'auto_increment') === false) {
                $pdo->exec("ALTER TABLE activity_log MODIFY id INT(11) NOT NULL AUTO_INCREMENT");
            }
        } catch (Throwable $e) {
            // Keep inserts best-effort for legacy schemas.
        }
    }
}

if (!function_exists('activity_log_needs_manual_id_fallback')) {
    function activity_log_needs_manual_id_fallback(string $message): bool {
        return strpos($message, "Duplicate entry '0' for key 'PRIMARY'") !== false
            || strpos($message, "Field 'id' doesn't have a default value") !== false
            || strpos($message, "Field 'id' doesn't have a default") !== false;
    }
}

if (!function_exists('log_activity_event')) {
    function log_activity_event(?int $userId, string $action, string $entityType = 'system', ?int $entityId = null, string $details = ''): bool {
        require_once __DIR__ . '/db.php';

        $action = trim($action);
        $entityType = trim($entityType) !== '' ? trim($entityType) : 'system';
        $details = trim($details);
        $userId = ($userId !== null && $userId > 0) ? $userId : null;
        $entityId = ($entityId !== null && $entityId > 0) ? $entityId : null;

        if ($action === '') {
            return false;
        }

        $pdo = get_db_connection();
        if (!$pdo) {
            return false;
        }

        try {
            ensure_activity_log_auto_increment($pdo);

            $stmt = $pdo->prepare(
                "INSERT INTO activity_log (user_id, action, entity_type, entity_id, details)
                 VALUES (?, ?, ?, ?, ?)"
            );

            try {
                $stmt->execute([$userId, $action, $entityType, $entityId, $details]);
            } catch (Throwable $e) {
                $message = (string)$e->getMessage();
                if (!activity_log_needs_manual_id_fallback($message)) {
                    throw $e;
                }

                $nextId = (int)$pdo->query("SELECT COALESCE(MAX(id), 0) + 1 FROM activity_log")->fetchColumn();
                $fallback = $pdo->prepare(
                    "INSERT INTO activity_log (id, user_id, action, entity_type, entity_id, details)
                     VALUES (?, ?, ?, ?, ?, ?)"
                );
                $fallback->execute([$nextId, $userId, $action, $entityType, $entityId, $details]);
            }

            return true;
        } catch (Throwable $e) {
            error_log('Activity log write failed: ' . $e->getMessage());
            return false;
        }
    }
}
