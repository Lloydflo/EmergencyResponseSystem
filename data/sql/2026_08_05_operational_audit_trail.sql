-- Emergency Response System
-- Structured operational audit trail for Admin > Audit Log.
-- Target: MariaDB 10.4+ (the version shown in the supplied database dump).
-- Safe to run more than once.

ALTER TABLE `activity_log`
  MODIFY COLUMN `id` INT(11) NOT NULL AUTO_INCREMENT,
  MODIFY COLUMN `action` VARCHAR(64) NOT NULL,
  MODIFY COLUMN `entity_type` VARCHAR(64) NOT NULL,
  ADD COLUMN IF NOT EXISTS `actor_name` VARCHAR(150) DEFAULT NULL AFTER `details`,
  ADD COLUMN IF NOT EXISTS `actor_email` VARCHAR(150) DEFAULT NULL AFTER `actor_name`,
  ADD COLUMN IF NOT EXISTS `actor_role` VARCHAR(32) DEFAULT NULL AFTER `actor_email`,
  ADD COLUMN IF NOT EXISTS `source_channel` VARCHAR(32) DEFAULT NULL AFTER `actor_role`,
  ADD COLUMN IF NOT EXISTS `event_category` VARCHAR(32) DEFAULT NULL AFTER `source_channel`,
  ADD COLUMN IF NOT EXISTS `event_outcome` VARCHAR(16) NOT NULL DEFAULT 'success' AFTER `event_category`,
  ADD COLUMN IF NOT EXISTS `reference_no` VARCHAR(64) DEFAULT NULL AFTER `event_outcome`,
  ADD COLUMN IF NOT EXISTS `metadata_json` LONGTEXT DEFAULT NULL AFTER `reference_no`,
  ADD COLUMN IF NOT EXISTS `ip_address` VARCHAR(45) DEFAULT NULL AFTER `metadata_json`,
  ADD COLUMN IF NOT EXISTS `user_agent` VARCHAR(255) DEFAULT NULL AFTER `ip_address`,
  ADD COLUMN IF NOT EXISTS `request_id` VARCHAR(64) DEFAULT NULL AFTER `user_agent`,
  ADD COLUMN IF NOT EXISTS `event_key` VARCHAR(160) DEFAULT NULL AFTER `request_id`;

CREATE INDEX IF NOT EXISTS `idx_activity_log_created` ON `activity_log` (`created_at`, `id`);
CREATE INDEX IF NOT EXISTS `idx_activity_log_user_created` ON `activity_log` (`user_id`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_activity_log_actor_created` ON `activity_log` (`actor_role`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_activity_log_source_created` ON `activity_log` (`source_channel`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_activity_log_category_created` ON `activity_log` (`event_category`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_activity_log_outcome_created` ON `activity_log` (`event_outcome`, `created_at`);
CREATE INDEX IF NOT EXISTS `idx_activity_log_reference_created` ON `activity_log` (`reference_no`, `created_at`);
CREATE UNIQUE INDEX IF NOT EXISTS `uk_activity_log_event_key` ON `activity_log` (`event_key`);

-- Best-effort classification for legacy records. Existing non-empty values are
-- retained. Actor snapshots are filled from the current users table where the
-- account still exists.
UPDATE `activity_log` a
LEFT JOIN `users` u ON u.id = a.user_id
SET
  a.actor_name = COALESCE(NULLIF(a.actor_name, ''), NULLIF(u.name, ''), CASE WHEN a.user_id IS NULL THEN 'System' ELSE NULL END),
  a.actor_email = COALESCE(NULLIF(a.actor_email, ''), NULLIF(u.email, '')),
  a.actor_role = COALESCE(NULLIF(a.actor_role, ''), NULLIF(LOWER(u.role), ''), CASE WHEN a.user_id IS NULL THEN 'system' ELSE 'user' END),
  a.source_channel = COALESCE(
    NULLIF(a.source_channel, ''),
    CASE
      WHEN LOWER(COALESCE(u.role, '')) = 'responder' THEN 'responder_app'
      WHEN LOWER(COALESCE(u.role, '')) IN ('dispatcher', 'operator') THEN 'dispatcher_web'
      WHEN LOWER(COALESCE(u.role, '')) = 'admin' THEN 'admin_web'
      ELSE 'system'
    END
  ),
  a.event_category = COALESCE(
    NULLIF(a.event_category, ''),
    CASE
      WHEN LOWER(CONCAT(a.action, ' ', a.entity_type)) REGEXP 'login|logout|auth|otp' THEN 'authentication'
      WHEN LOWER(CONCAT(a.action, ' ', a.entity_type)) REGEXP 'call|intake|hotline' THEN 'call_intake'
      WHEN LOWER(CONCAT(a.action, ' ', a.entity_type)) REGEXP 'dispatch|allocation' THEN 'dispatch'
      WHEN LOWER(CONCAT(a.action, ' ', a.entity_type)) REGEXP 'navigate|navigation|enroute|en_route|route' THEN 'navigation'
      WHEN LOWER(CONCAT(a.action, ' ', a.entity_type)) REGEXP 'arriv|on_scene' THEN 'arrival'
      WHEN LOWER(CONCAT(a.action, ' ', a.entity_type)) REGEXP 'complete|resolved|cleared' THEN 'completion'
      WHEN LOWER(CONCAT(a.action, ' ', a.entity_type)) REGEXP 'report|review|approval|verified' THEN 'report_review'
      WHEN LOWER(CONCAT(a.action, ' ', a.entity_type)) REGEXP 'resource|backup|equipment|supply' THEN 'resource'
      WHEN LOWER(CONCAT(a.action, ' ', a.entity_type)) REGEXP 'chat|message|broadcast|interagency|coordination' THEN 'coordination'
      WHEN LOWER(CONCAT(a.action, ' ', a.entity_type)) REGEXP 'presence|online|offline|location' THEN 'presence'
      WHEN LOWER(CONCAT(a.action, ' ', a.entity_type)) REGEXP 'assignment|acknowledg|received' THEN 'assignment'
      WHEN LOWER(CONCAT(a.action, ' ', a.entity_type)) REGEXP 'incident|priority|triage' THEN 'incident'
      ELSE 'system'
    END
  ),
  a.event_outcome = CASE
    WHEN LOWER(a.action) REGEXP 'failed|failure|error|rejected|declined|denied' THEN 'failed'
    WHEN LOWER(a.action) REGEXP 'cancelled|canceled|returned|warning' THEN 'warning'
    ELSE COALESCE(NULLIF(a.event_outcome, ''), 'success')
  END;

-- Normalize the legacy operator role so Admin filters show one Dispatcher group.
UPDATE `activity_log`
SET `actor_role` = 'dispatcher'
WHERE LOWER(COALESCE(`actor_role`, '')) = 'operator';

-- Recover incident references for legacy logs whose entity relationship is
-- still available. Rows that already have a reference are not overwritten.
UPDATE `activity_log` a
INNER JOIN `incidents` i
  ON a.entity_id = i.id
 AND LOWER(a.entity_type) IN ('incident', 'navigation', 'route', 'arrival')
SET a.reference_no = i.reference_no
WHERE COALESCE(NULLIF(a.reference_no, ''), '') = '';

UPDATE `activity_log` a
INNER JOIN `calls` c
  ON a.entity_id = c.id
 AND LOWER(a.entity_type) = 'call'
SET a.reference_no = c.reference_no
WHERE COALESCE(NULLIF(a.reference_no, ''), '') = '';

UPDATE `activity_log` a
INNER JOIN `dispatches` d
  ON a.entity_id = d.id
 AND LOWER(a.entity_type) = 'dispatch'
SET a.reference_no = d.reference_no
WHERE COALESCE(NULLIF(a.reference_no, ''), '') = '';

UPDATE `activity_log` a
INNER JOIN `dispatch_operator_records` dor
  ON a.entity_id = dor.id
 AND LOWER(a.entity_type) IN ('assignment', 'dispatch_assignment')
INNER JOIN `incidents` i ON i.id = dor.incident_id
SET a.reference_no = i.reference_no
WHERE COALESCE(NULLIF(a.reference_no, ''), '') = '';
