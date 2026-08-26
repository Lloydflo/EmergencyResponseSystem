<?php
/**
 * Syncs incident status changes back to the TFTR (lgu-traffic) accident_cases
 * table when an ERS incident is linked to a TFTR accident report.
 *
 * Same MySQL server/user as ERS, so we reach the other database with a
 * fully-qualified table name on the same PDO connection — no second
 * connection needed. Every function here is best-effort: it never throws
 * and never blocks the caller's main flow if TFTR is unreachable or the
 * incident isn't linked to a TFTR accident.
 */

if (!function_exists('ers_tftr_column_exists')) {
    function ers_tftr_column_exists(PDO $pdo, string $table, string $column): bool
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
            $stmt->execute([$table, $column]);
            return (bool)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return false;
        }
    }
}

/**
 * Looks up the TFTR public_accident_id linked to an ERS incident, if any.
 */
if (!function_exists('ers_tftr_linked_accident_id')) {
    function ers_tftr_linked_accident_id(PDO $pdo, int $incidentId): ?string
    {
        if ($incidentId <= 0 || !ers_tftr_column_exists($pdo, 'incidents', 'tftr_accident_id')) {
            return null;
        }
        try {
            $stmt = $pdo->prepare('SELECT tftr_accident_id FROM incidents WHERE id = ? LIMIT 1');
            $stmt->execute([$incidentId]);
            $value = trim((string)$stmt->fetchColumn());
            return $value !== '' ? $value : null;
        } catch (Throwable $e) {
            error_log('[tftr_sync] lookup failed: ' . $e->getMessage());
            return null;
        }
    }
}

/**
 * Updates the status of a linked TFTR accident case. $status must be one of
 * the enum values TFTR accepts: Reported, Dispatched, On Scene, Cleared.
 * Safe to call even if the incident has no TFTR link — it just does nothing.
 */
if (!function_exists('ers_tftr_sync_accident_status')) {
    function ers_tftr_sync_accident_status(PDO $pdo, int $incidentId, string $status): bool
    {
        $allowed = ['Reported', 'Dispatched', 'On Scene', 'Cleared'];
        if (!in_array($status, $allowed, true)) {
            error_log('[tftr_sync] Rejected unknown status: ' . $status);
            return false;
        }

        $accidentId = ers_tftr_linked_accident_id($pdo, $incidentId);
        if ($accidentId === null) {
            // Incident isn't linked to a TFTR accident — nothing to sync.
            return false;
        }

        try {
            $stmt = $pdo->prepare(
                "UPDATE `lgu-traffic`.`accident_cases`
                 SET status = ?, updated_at = CURRENT_TIMESTAMP
                 WHERE public_accident_id = ?"
            );
            $stmt->execute([$status, $accidentId]);
            if ($stmt->rowCount() === 0) {
                error_log('[tftr_sync] No matching accident_cases row for ' . $accidentId);
                return false;
            }
            return true;
        } catch (Throwable $e) {
            error_log('[tftr_sync] Update failed for ' . $accidentId . ': ' . $e->getMessage());
            return false;
        }
    }
}
