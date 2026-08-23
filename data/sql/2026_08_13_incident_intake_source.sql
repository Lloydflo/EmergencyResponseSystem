-- Durable Dispatcher intake provenance
-- Existing rows intentionally remain NULL because the former schema used the
-- same calls/incident path for live calls and manual entries. Guessing would
-- put operational cases in the wrong queue.

ALTER TABLE `incidents`
    ADD COLUMN IF NOT EXISTS `intake_source` VARCHAR(24) NULL AFTER `reported_by_call_id`;

CREATE INDEX IF NOT EXISTS `idx_incidents_intake_source`
    ON `incidents` (`intake_source`);

CREATE INDEX IF NOT EXISTS `idx_activity_log_entity_action`
    ON `activity_log` (`entity_type`, `action`, `entity_id`);
