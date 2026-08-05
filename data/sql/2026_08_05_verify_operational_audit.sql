-- Read-only post-deployment checks for the Operational Audit Trail.
-- Run after 2026_08_05_operational_audit_trail.sql.

SELECT
  CASE
    WHEN COUNT(*) = 12 THEN 'PASS'
    ELSE CONCAT('CHECK: ', COUNT(*), ' of 12 structured columns found')
  END AS structured_column_check
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'activity_log'
  AND COLUMN_NAME IN (
    'actor_name', 'actor_email', 'actor_role', 'source_channel',
    'event_category', 'event_outcome', 'reference_no', 'metadata_json',
    'ip_address', 'user_agent', 'request_id', 'event_key'
  );

SELECT
  INDEX_NAME,
  NON_UNIQUE,
  GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS indexed_columns
FROM INFORMATION_SCHEMA.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'activity_log'
  AND INDEX_NAME IN (
    'PRIMARY', 'uk_activity_log_event_key', 'idx_activity_log_created',
    'idx_activity_log_actor_created', 'idx_activity_log_source_created',
    'idx_activity_log_category_created', 'idx_activity_log_outcome_created',
    'idx_activity_log_reference_created'
  )
GROUP BY INDEX_NAME, NON_UNIQUE
ORDER BY INDEX_NAME;

SELECT
  COALESCE(NULLIF(actor_role, ''), 'unclassified') AS actor_role,
  COALESCE(NULLIF(source_channel, ''), 'unclassified') AS source_channel,
  COALESCE(NULLIF(event_category, ''), 'unclassified') AS event_category,
  COALESCE(NULLIF(event_outcome, ''), 'unclassified') AS event_outcome,
  COUNT(*) AS log_count
FROM activity_log
GROUP BY actor_role, source_channel, event_category, event_outcome
ORDER BY log_count DESC, actor_role, source_channel;

SELECT
  id AS internal_record_key,
  created_at,
  actor_name,
  actor_role,
  source_channel,
  action,
  event_category,
  reference_no,
  event_outcome
FROM activity_log
ORDER BY created_at DESC, id DESC
LIMIT 20;
