-- Backup of ers_db.sql (created by Copilot on 2026-02-10)

-- Original contents below

-- Resolution proof storage
CREATE TABLE IF NOT EXISTS `incident_proofs` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `incident_id` INT NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_incident_proofs_incident` (`incident_id`),
  CONSTRAINT `fk_incident_proofs_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS `users` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `email` VARCHAR(150) NOT NULL,
  `password` VARCHAR(255) NOT NULL,
  `name` VARCHAR(150) NOT NULL,
  `role` ENUM('admin','operator','viewer') NOT NULL DEFAULT 'viewer',
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  `last_login` TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_users_email` (`email`),
  KEY `idx_users_status` (`status`),
  KEY `idx_users_role` (`role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;



-- Insert default admin account
-- Email: admin@example.com
-- Password: admin123
-- NOTE: Run setup_admin.php to create the admin user with proper password hashing
-- The password hash should be generated using PHP's password_hash() function
-- Example: password_hash('admin123', PASSWORD_DEFAULT)
-- =====================
-- OTP Codes Table
-- =====================
CREATE TABLE IF NOT EXISTS otp_codes (
  id INT AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(255) NOT NULL,
  otp_code VARCHAR(10) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at DATETIME NOT NULL,
  status ENUM('active','used','expired') DEFAULT 'active'
);
-- ERS Unified Database Schema with FKs and Triggers
-- MySQL 8.0+ / InnoDB / utf8mb4

CREATE DATABASE IF NOT EXISTS `ers_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `ers_db`;

-- =====================
-- Core: Calls & Incidents
-- =====================
CREATE TABLE IF NOT EXISTS `calls` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference_no` VARCHAR(50) NOT NULL,
  `caller_name` VARCHAR(150) NULL,
  `caller_phone` VARCHAR(50) NULL,
  `caller_email` VARCHAR(150) NULL,
  `location_address` VARCHAR(255) NULL,
  `latitude` DECIMAL(10,7) NULL,
  `longitude` DECIMAL(10,7) NULL,
  `incident_type` VARCHAR(100) NOT NULL,
  `priority` ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  `status` ENUM('new','triaged','closed') NOT NULL DEFAULT 'new',
  `description` TEXT NULL,
  `received_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_calls_reference_no` (`reference_no`),
  KEY `idx_calls_status` (`status`),
  KEY `idx_calls_priority` (`priority`),
  KEY `idx_calls_received_at` (`received_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `incidents` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `reference_no` VARCHAR(50) NOT NULL,
  `type` VARCHAR(100) NOT NULL,
  `priority` ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
  `status` ENUM('pending','dispatched','resolved','cancelled') NOT NULL DEFAULT 'pending',
  `title` VARCHAR(200) NULL,
  `description` TEXT NULL,
  `location_address` VARCHAR(255) NULL,
  `latitude` DECIMAL(10,7) NULL,
  `longitude` DECIMAL(10,7) NULL,
  `reported_by_call_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  `resolved_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_incidents_reference_no` (`reference_no`),
  KEY `idx_incidents_type` (`type`),
  KEY `idx_incidents_priority` (`priority`),
  KEY `idx_incidents_status` (`status`),
  KEY `idx_incidents_created_at` (`created_at`),
  CONSTRAINT `fk_incidents_call`
    FOREIGN KEY (`reported_by_call_id`)
    REFERENCES `calls` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `incident_notes` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `incident_id` BIGINT UNSIGNED NOT NULL,
  `author_name` VARCHAR(150) NULL,
  `note` TEXT NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_incident_notes_incident_id` (`incident_id`),
  KEY `idx_incident_notes_created_at` (`created_at`),
  CONSTRAINT `fk_incident_notes_incident`
    FOREIGN KEY (`incident_id`)
    REFERENCES `incidents` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================
-- Dispatch & Units
-- =====================
CREATE TABLE IF NOT EXISTS `units` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier` VARCHAR(50) NOT NULL,
  `unit_type` ENUM('ambulance','fire','police','rescue','other') NOT NULL DEFAULT 'other',
  `status` ENUM('available','assigned','enroute','on_scene','unavailable','maintenance') NOT NULL DEFAULT 'available',
  `current_incident_id` BIGINT UNSIGNED NULL,
  `latitude` DECIMAL(10,7) NULL,
  `longitude` DECIMAL(10,7) NULL,
  `last_status_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_units_identifier` (`identifier`),
  KEY `idx_units_status` (`status`),
  KEY `idx_units_current_incident_id` (`current_incident_id`),
  CONSTRAINT `fk_units_incident`
    FOREIGN KEY (`current_incident_id`)
    REFERENCES `incidents` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `dispatches` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `incident_id` BIGINT UNSIGNED NOT NULL,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `status` ENUM('assigned','acknowledged','enroute','on_scene','cleared','cancelled') NOT NULL DEFAULT 'assigned',
  `assigned_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `acknowledged_at` DATETIME NULL,
  `enroute_at` DATETIME NULL,
  `on_scene_at` DATETIME NULL,
  `cleared_at` DATETIME NULL,
  `notes` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dispatches_incident_id` (`incident_id`),
  KEY `idx_dispatches_unit_id` (`unit_id`),
  KEY `idx_dispatches_status` (`status`),
  CONSTRAINT `fk_dispatches_incident`
    FOREIGN KEY (`incident_id`)
    REFERENCES `incidents` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_dispatches_unit`
    FOREIGN KEY (`unit_id`)
    REFERENCES `units` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================
-- GPS
-- =====================
CREATE TABLE IF NOT EXISTS `unit_locations` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `unit_id` BIGINT UNSIGNED NOT NULL,
  `latitude` DECIMAL(10,7) NOT NULL,
  `longitude` DECIMAL(10,7) NOT NULL,
  `speed_kph` DECIMAL(6,2) NULL,
  `heading_deg` DECIMAL(5,2) NULL,
  `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_unit_locations_unit_id` (`unit_id`),
  KEY `idx_unit_locations_recorded_at` (`recorded_at`),
  CONSTRAINT `fk_unit_locations_unit`
    FOREIGN KEY (`unit_id`)
    REFERENCES `units` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================
-- Resources & Staff
-- =====================
CREATE TABLE IF NOT EXISTS `resources` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `type` ENUM('vehicle','equipment','facility','other') NOT NULL DEFAULT 'other',
  `name` VARCHAR(200) NOT NULL,
  `code` VARCHAR(50) NULL,
  `status` ENUM('available','deployed','maintenance','out_of_service') NOT NULL DEFAULT 'available',
  `location` VARCHAR(255) NULL,
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_resources_code` (`code`),
  KEY `idx_resources_type` (`type`),
  KEY `idx_resources_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Resource Requests (for ad-hoc resource needs captured from UI)
CREATE TABLE IF NOT EXISTS `resource_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `requestor` VARCHAR(150) NOT NULL,
  `resource_name` VARCHAR(200) NOT NULL,
  `date_requested` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` ENUM('pending','approved','rejected','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
  `details` TEXT NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_rr_status` (`status`),
  KEY `idx_rr_date_requested` (`date_requested`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `staff` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(150) NOT NULL,
  `role` VARCHAR(100) NULL,
  `phone` VARCHAR(50) NULL,
  `email` VARCHAR(150) NULL,
  `status` ENUM('available','on_duty','off_duty','leave') NOT NULL DEFAULT 'available',
  `assigned_resource_id` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_staff_status` (`status`),
  KEY `idx_staff_assigned_resource_id` (`assigned_resource_id`),
  CONSTRAINT `fk_staff_resource`
    FOREIGN KEY (`assigned_resource_id`)
    REFERENCES `resources` (`id`)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================
-- Interagency
-- =====================
CREATE TABLE IF NOT EXISTS `agencies` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(200) NOT NULL,
  `contact_name` VARCHAR(150) NULL,
  `contact_phone` VARCHAR(50) NULL,
  `contact_email` VARCHAR(150) NULL,
  `address` VARCHAR(255) NULL,
  `status` ENUM('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_agencies_name` (`name`),
  KEY `idx_agencies_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `shared_resources` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `agency_id` BIGINT UNSIGNED NOT NULL,
  `resource_type` VARCHAR(100) NOT NULL,
  `name` VARCHAR(200) NOT NULL,
  `quantity_total` INT UNSIGNED NOT NULL DEFAULT 0,
  `quantity_available` INT UNSIGNED NOT NULL DEFAULT 0,
  `status` ENUM('available','unavailable','maintenance') NOT NULL DEFAULT 'available',
  `notes` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_shared_resources_agency_id` (`agency_id`),
  KEY `idx_shared_resources_type` (`resource_type`),
  KEY `idx_shared_resources_status` (`status`),
  CONSTRAINT `fk_shared_resources_agency`
    FOREIGN KEY (`agency_id`)
    REFERENCES `agencies` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `interagency_requests` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `from_agency_id` BIGINT UNSIGNED NOT NULL,
  `to_agency_id` BIGINT UNSIGNED NOT NULL,
  `resource_type` VARCHAR(100) NOT NULL,
  `quantity` INT UNSIGNED NOT NULL,
  `status` ENUM('pending','approved','rejected','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
  `requested_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NULL ON UPDATE CURRENT_TIMESTAMP,
  `notes` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_iar_from_agency_id` (`from_agency_id`),
  KEY `idx_iar_to_agency_id` (`to_agency_id`),
  KEY `idx_iar_status` (`status`),
  CONSTRAINT `fk_iar_from_agency`
    FOREIGN KEY (`from_agency_id`)
    REFERENCES `agencies` (`id`)
    ON DELETE CASCADE,
  CONSTRAINT `fk_iar_to_agency`
    FOREIGN KEY (`to_agency_id`)
    REFERENCES `agencies` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================
-- Reports
-- =====================
CREATE TABLE IF NOT EXISTS `reports` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_type` ENUM('daily','weekly','monthly','incident') NOT NULL,
  `period_start` DATE NULL,
  `period_end` DATE NULL,
  `generated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `generated_by` VARCHAR(150) NULL,
  `status` ENUM('pending','ready','failed') NOT NULL DEFAULT 'ready',
  `summary_json` JSON NULL,
  PRIMARY KEY (`id`),
  KEY `idx_reports_type` (`report_type`),
  KEY `idx_reports_generated_at` (`generated_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `report_metrics` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `report_id` BIGINT UNSIGNED NOT NULL,
  `metric_name` VARCHAR(150) NOT NULL,
  `metric_value` DECIMAL(18,4) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_report_metrics_report_id` (`report_id`),
  KEY `idx_report_metrics_name` (`metric_name`),
  CONSTRAINT `fk_report_metrics_report`
    FOREIGN KEY (`report_id`)
    REFERENCES `reports` (`id`)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =====================
-- Triggers to propagate updates across modules
-- =====================
DELIMITER $$

-- When a call is created, create a pending incident linked to it
CREATE TRIGGER trg_calls_ai_create_incident
AFTER INSERT ON `calls` FOR EACH ROW
BEGIN
  INSERT INTO `incidents` (
    `reference_no`, `type`, `priority`, `status`, `title`, `description`,
    `location_address`, `latitude`, `longitude`, `reported_by_call_id`
  ) VALUES (
    NEW.`reference_no`, NEW.`incident_type`, NEW.`priority`, 'pending',
    CONCAT('Incident from call ', NEW.`reference_no`), NEW.`description`,
    NEW.`location_address`, NEW.`latitude`, NEW.`longitude`, NEW.`id`
  );
END$$

-- When a dispatch is inserted, mark unit assigned and incident dispatched
CREATE TRIGGER trg_dispatches_ai_update_status
AFTER INSERT ON `dispatches` FOR EACH ROW
BEGIN
  UPDATE `units`
    SET `status` = 'assigned', `current_incident_id` = NEW.`incident_id`, `last_status_at` = CURRENT_TIMESTAMP
    WHERE `id` = NEW.`unit_id`;
  UPDATE `incidents`
    SET `status` = 'dispatched', `updated_at` = CURRENT_TIMESTAMP
    WHERE `id` = NEW.`incident_id` AND `status` IN ('pending','cancelled');
END$$

-- When a dispatch status changes, propagate to unit and incident
CREATE TRIGGER trg_dispatches_au_propagate
AFTER UPDATE ON `dispatches` FOR EACH ROW
BEGIN
  IF NEW.`status` = 'enroute' THEN
    UPDATE `units` SET `status` = 'enroute', `last_status_at` = CURRENT_TIMESTAMP WHERE `id` = NEW.`unit_id`;
  ELSEIF NEW.`status` = 'on_scene' THEN
    UPDATE `units` SET `status` = 'on_scene', `last_status_at` = CURRENT_TIMESTAMP WHERE `id` = NEW.`unit_id`;
  ELSEIF NEW.`status` IN ('cleared','cancelled') THEN
    UPDATE `units` SET `status` = 'available', `current_incident_id` = NULL, `last_status_at` = CURRENT_TIMESTAMP WHERE `id` = NEW.`unit_id`;
  END IF;

  IF NEW.`status` = 'cleared' THEN
    UPDATE `incidents` SET `status` = 'resolved', `resolved_at` = CURRENT_TIMESTAMP WHERE `id` = NEW.`incident_id`;
  ELSEIF NEW.`status` = 'cancelled' THEN
    UPDATE `incidents` SET `status` = 'cancelled' WHERE `id` = NEW.`incident_id`;
  END IF;
END$$

-- When a unit location is inserted, update unit lat/long
CREATE TRIGGER trg_unit_locations_ai_update_unit
AFTER INSERT ON `unit_locations` FOR EACH ROW
BEGIN
  UPDATE `units` SET `latitude` = NEW.`latitude`, `longitude` = NEW.`longitude`, `last_status_at` = CURRENT_TIMESTAMP
  WHERE `id` = NEW.`unit_id`;
END$$

-- When staff assignment changes, toggle resource status
CREATE TRIGGER trg_staff_au_toggle_resource
AFTER UPDATE ON `staff` FOR EACH ROW
BEGIN
  IF NEW.`assigned_resource_id` IS NOT NULL THEN
    UPDATE `resources` SET `status` = 'deployed', `updated_at` = CURRENT_TIMESTAMP WHERE `id` = NEW.`assigned_resource_id`;
  END IF;
  IF OLD.`assigned_resource_id` IS NOT NULL AND NEW.`assigned_resource_id` IS NULL THEN
    UPDATE `resources` SET `status` = 'available', `updated_at` = CURRENT_TIMESTAMP WHERE `id` = OLD.`assigned_resource_id`;
  END IF;
END$$

-- Ensure shared_resources availability stays within bounds
CREATE TRIGGER trg_shared_resources_bu_bounds
BEFORE UPDATE ON `shared_resources` FOR EACH ROW
BEGIN
  IF NEW.`quantity_available` < 0 THEN SET NEW.`quantity_available` = 0; END IF;
  IF NEW.`quantity_available` > NEW.`quantity_total` THEN SET NEW.`quantity_available` = NEW.`quantity_total`; END IF;
END$$

DELIMITER ;

-- =====================
-- Activity Log Table (System-wide Audit Trail)
-- =====================
CREATE TABLE IF NOT EXISTS activity_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    action VARCHAR(32) NOT NULL, -- e.g. created, updated, deleted
    entity_type VARCHAR(32) NOT NULL, -- e.g. incident, unit, user
    entity_id INT NULL,
    details TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);
