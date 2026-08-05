-- Emergency Response System
-- Adds the index used by the 24-hour approved-report history view.
-- Safe to run repeatedly against MySQL/MariaDB.

SET @history_index_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'responder_after_action_reports'
      AND INDEX_NAME = 'idx_aar_status_reviewed'
);

SET @history_index_sql := IF(
    @history_index_exists = 0,
    'ALTER TABLE `responder_after_action_reports` ADD INDEX `idx_aar_status_reviewed` (`status`, `reviewed_at`)',
    'SELECT 1'
);

PREPARE history_index_statement FROM @history_index_sql;
EXECUTE history_index_statement;
DEALLOCATE PREPARE history_index_statement;
