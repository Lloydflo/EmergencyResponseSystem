-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Feb 12, 2026 at 12:11 AM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `emergency_response_test`
--

-- --------------------------------------------------------

--
-- Table structure for table `activity_log`
--

CREATE TABLE `activity_log` (
  `id` int(11) NOT NULL,
  `user_id` int(11) DEFAULT NULL,
  `action` varchar(32) NOT NULL,
  `entity_type` varchar(32) NOT NULL,
  `entity_id` int(11) DEFAULT NULL,
  `details` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `activity_log`
--

INSERT INTO `activity_log` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `details`, `created_at`) VALUES
(1, NULL, 'call_logged', 'call', NULL, 'Type: traffic | Location: bagong silang caloocan city | Priority: low', '2026-02-04 10:51:54'),
(2, NULL, 'call_logged', 'call', NULL, 'Type: medical | Location: Novaliches, Liliw, Laguna, Calabarzon, 4004, Philippines | Priority: high', '2026-02-04 11:04:30'),
(3, NULL, 'call_logged', 'call', NULL, 'Type: medical | Location: novaliches | Priority: high', '2026-02-04 11:09:16'),
(4, NULL, 'call_logged', 'call', NULL, 'Type: police | Location: City Hall | Priority: low', '2026-02-04 15:00:42'),
(5, NULL, 'call_logged', 'call', NULL, 'Type: fire | Location: City Hall | Priority: medium', '2026-02-06 09:22:25'),
(6, NULL, 'call_logged', 'call', NULL, 'Type: medical | Location: novaliches | Priority: low', '2026-02-07 21:27:51'),
(7, NULL, 'lockdown', 'system', NULL, 'City-wide lockdown activated from Dispatch Center', '2026-02-11 12:25:30'),
(8, NULL, 'chat', 'agency_chat', 1, 'Hi', '2026-02-11 14:44:23'),
(9, NULL, 'call_logged', 'call', NULL, 'Type: fire | Location: novaliches, quezon city | Priority: medium', '2026-02-11 14:53:30'),
(10, NULL, 'call_logged', 'call', NULL, 'Type: medical | Location: Nicanor Padilla Street, San Miguel, Sixth District, Manila, Capital District, Metro Manila, 1005, Philippines | Priority: low', '2026-02-11 14:57:07'),
(11, NULL, 'call_logged', 'call', NULL, 'Type: medical | Location: Circle | Priority: low', '2026-02-11 15:51:07'),
(12, NULL, 'call_logged', 'call', NULL, 'Type: fire | Location: Quezon City Hall | Priority: low', '2026-02-11 18:26:10');

-- --------------------------------------------------------

--
-- Interagency chat migration additions
--

ALTER TABLE `activity_log`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

CREATE TABLE IF NOT EXISTS `interagency_user_thread_pairs` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `owner_user_id` INT UNSIGNED NOT NULL,
  `target_user_id` INT UNSIGNED NOT NULL,
  `created_by` INT UNSIGNED DEFAULT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_interagency_user_threads_pair` (`owner_user_id`, `target_user_id`),
  KEY `idx_interagency_user_threads_owner` (`owner_user_id`),
  KEY `idx_interagency_user_threads_target` (`target_user_id`),
  KEY `idx_interagency_user_threads_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `interagency_user_thread_reads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `target_user_id` INT UNSIGNED NOT NULL,
  `last_read_id` INT NOT NULL DEFAULT 0,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_interagency_user_reads_pair` (`user_id`, `target_user_id`),
  KEY `idx_interagency_user_reads_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `interagency_message_attachments` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `message_id` INT NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `file_url` VARCHAR(500) NOT NULL,
  `file_path` VARCHAR(500) DEFAULT NULL,
  `mime_type` VARCHAR(150) DEFAULT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `file_blob` LONGBLOB DEFAULT NULL,
  `is_image` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_interagency_msg_attach_message` (`message_id`),
  KEY `idx_interagency_msg_attach_image` (`is_image`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_resources`
--

CREATE TABLE `admin_resources` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `category` enum('vehicles','personnel','equipment') NOT NULL,
  `status` enum('available','in_use','maintenance','offline') NOT NULL DEFAULT 'available',
  `location` varchar(255) NOT NULL,
  `driver_name` varchar(150) DEFAULT NULL,
  `plate_number` varchar(50) DEFAULT NULL,
  `position_title` varchar(150) DEFAULT NULL,
  `assignment` varchar(255) DEFAULT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `admin_resources_archive`
--

CREATE TABLE `admin_resources_archive` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resource_id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `category` enum('vehicles','personnel','equipment') NOT NULL,
  `status` enum('available','in_use','maintenance','offline') NOT NULL DEFAULT 'available',
  `location` varchar(255) NOT NULL,
  `driver_name` varchar(150) DEFAULT NULL,
  `plate_number` varchar(50) DEFAULT NULL,
  `position_title` varchar(150) DEFAULT NULL,
  `assignment` varchar(255) DEFAULT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `deleted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `resource_records`
--

CREATE TABLE `resource_records` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `category` enum('vehicles','personnel','equipment') NOT NULL,
  `status` enum('available','in_use','maintenance','offline') NOT NULL DEFAULT 'available',
  `location` varchar(255) NOT NULL,
  `driver_name` varchar(150) DEFAULT NULL,
  `plate_number` varchar(50) DEFAULT NULL,
  `position_title` varchar(150) DEFAULT NULL,
  `assignment` varchar(255) DEFAULT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `resource_records_archive`
--

CREATE TABLE `resource_records_archive` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `resource_id` bigint(20) UNSIGNED NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(200) NOT NULL,
  `category` enum('vehicles','personnel','equipment') NOT NULL,
  `status` enum('available','in_use','maintenance','offline') NOT NULL DEFAULT 'available',
  `location` varchar(255) NOT NULL,
  `driver_name` varchar(150) DEFAULT NULL,
  `plate_number` varchar(50) DEFAULT NULL,
  `position_title` varchar(150) DEFAULT NULL,
  `assignment` varchar(255) DEFAULT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `deleted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `agencies`
--

CREATE TABLE `agencies` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(200) NOT NULL,
  `contact_name` varchar(150) DEFAULT NULL,
  `contact_phone` varchar(50) DEFAULT NULL,
  `contact_email` varchar(150) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `calls`
--

CREATE TABLE `calls` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `reference_no` varchar(50) NOT NULL,
  `caller_name` varchar(150) DEFAULT NULL,
  `caller_phone` varchar(50) DEFAULT NULL,
  `caller_email` varchar(150) DEFAULT NULL,
  `location_address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `incident_type` varchar(100) NOT NULL,
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `status` enum('new','triaged','closed') NOT NULL DEFAULT 'new',
  `description` text DEFAULT NULL,
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `calls`
--

INSERT INTO `calls` (`id`, `reference_no`, `caller_name`, `caller_phone`, `caller_email`, `location_address`, `latitude`, `longitude`, `incident_type`, `priority`, `status`, `description`, `received_at`, `created_at`, `updated_at`) VALUES
(1, 'REF-20260202152404-8436', 'Maria Santos', '+63 922 562 3944', NULL, 'Diliman', NULL, NULL, 'police', 'medium', 'new', 'nakaw', '2026-02-02 22:24:04', '2026-02-02 22:24:04', NULL),
(2, 'REF-20260203042136-4230', 'Jose Reyes', '+63 997 542 3898', NULL, 'Circle', NULL, NULL, 'fire', 'low', 'new', 'bilog', '2026-02-03 11:21:36', '2026-02-03 11:21:36', NULL),
(3, 'REF-20260204035154-9212', 'Jose Reyes', '+63 930 876 4704', NULL, 'bagong silang caloocan city', NULL, NULL, 'traffic', 'low', 'new', 'hfffgdxffdf', '2026-02-04 10:51:54', '2026-02-04 10:51:54', NULL),
(4, 'REF-20260204040430-8022', 'Juan Dela Cruz', '+63 948 559 5076', NULL, 'Novaliches, Liliw, Laguna, Calabarzon, 4004, Philippines', NULL, NULL, 'medical', 'high', 'new', 'stroke', '2026-02-04 11:04:30', '2026-02-04 11:04:30', NULL),
(5, 'REF-20260204040916-4898', 'Juan Dela Cruz', '+63 906 953 1176', NULL, 'novaliches', NULL, NULL, 'medical', 'high', 'new', 'heart attack', '2026-02-04 11:09:16', '2026-02-04 11:09:16', NULL),
(6, 'REF-20260204080042-4397', 'Maria Santos', '+63 926 832 9363', NULL, 'City Hall', NULL, NULL, 'police', 'low', 'new', 'nakaw', '2026-02-04 15:00:42', '2026-02-04 15:00:42', NULL),
(7, 'REF-20260206022224-2140', 'Jose Reyes', '+63 905 251 6033', NULL, 'City Hall', NULL, NULL, 'fire', 'medium', 'new', 'sunog', '2026-02-06 09:22:24', '2026-02-06 09:22:24', NULL),
(8, 'REF-20260207142751-7153', 'Jose Reyes', '+63 999 740 8340', NULL, 'novaliches', NULL, NULL, 'medical', 'low', 'new', 'heart attack', '2026-02-07 21:27:51', '2026-02-07 21:27:51', NULL),
(9, 'REF-20260211075330-5287', 'Ana Garcia', '+63 925 143 7728', NULL, 'novaliches, quezon city', NULL, NULL, 'fire', 'medium', 'new', 'nasusunog na pagawaan ng sapatos', '2026-02-11 14:53:30', '2026-02-11 14:53:30', NULL),
(10, 'REF-20260211075707-4064', 'ana garcia', '09251437728', NULL, 'Nicanor Padilla Street, San Miguel, Sixth District, Manila, Capital District, Metro Manila, 1005, Philippines', NULL, NULL, 'medical', 'low', 'new', 'fall from 2nd floor', '2026-02-11 14:57:07', '2026-02-11 14:57:07', NULL),
(11, 'REF-20260211085107-1710', 'Ana Garcia', '+63 947 363 8243', NULL, 'Circle', NULL, NULL, 'medical', 'low', 'new', 'cardiac', '2026-02-11 15:51:07', '2026-02-11 15:51:07', NULL),
(12, 'REF-20260211112610-3531', 'Jose Reyes', '+63 995 614 3488', NULL, 'Quezon City Hall', NULL, NULL, 'fire', 'low', 'new', 'burning stove', '2026-02-11 18:26:10', '2026-02-11 18:26:10', NULL);

--
-- Triggers `calls`
--
DELIMITER $$
CREATE TRIGGER `trg_calls_ai_create_incident` AFTER INSERT ON `calls` FOR EACH ROW BEGIN
  INSERT INTO `incidents` (
    `reference_no`, `type`, `priority`, `status`, `title`, `description`,
    `location_address`, `latitude`, `longitude`, `reported_by_call_id`
  ) VALUES (
    NEW.`reference_no`, NEW.`incident_type`, NEW.`priority`, 'pending',
    CONCAT('Incident from call ', NEW.`reference_no`), NEW.`description`,
    NEW.`location_address`, NEW.`latitude`, NEW.`longitude`, NEW.`id`
  );
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `dispatches`
--

CREATE TABLE `dispatches` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) UNSIGNED NOT NULL,
  `unit_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('assigned','acknowledged','enroute','on_scene','cleared','cancelled') NOT NULL DEFAULT 'assigned',
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp(),
  `acknowledged_at` datetime DEFAULT NULL,
  `enroute_at` datetime DEFAULT NULL,
  `on_scene_at` datetime DEFAULT NULL,
  `cleared_at` datetime DEFAULT NULL,
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `dispatch_operator_records`
--

CREATE TABLE IF NOT EXISTS `dispatch_operator_records` (
  `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  `incident_id` bigint(20) UNSIGNED DEFAULT NULL,
  `name` varchar(150) NOT NULL,
  `vehicle` varchar(100) NOT NULL,
  `location` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `priority` varchar(20) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `status` varchar(50) DEFAULT 'pending',
  `assigned_to` int(11) DEFAULT NULL,
  `assigned_responder_name` varchar(150) DEFAULT NULL,
  `assigned_unit_code` varchar(50) DEFAULT NULL,
  `assigned_unit_type` varchar(50) DEFAULT NULL,
  `assigned_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_dispatch_operator_records_incident_id` (`incident_id`),
  KEY `idx_dispatch_operator_records_priority` (`priority`),
  KEY `idx_dispatch_operator_records_created_at` (`created_at`),
  KEY `idx_dispatch_operator_records_status` (`status`),
  KEY `idx_dispatch_operator_records_assigned_to` (`assigned_to`),
  KEY `idx_dispatch_operator_records_assigned_at` (`assigned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `dispatches`
--

INSERT INTO `dispatches` (`id`, `incident_id`, `unit_id`, `status`, `assigned_at`, `acknowledged_at`, `enroute_at`, `on_scene_at`, `cleared_at`, `notes`) VALUES
(1, 4, 2, 'cleared', '2026-02-04 11:04:58', NULL, NULL, NULL, '2026-02-04 11:34:57', NULL),
(2, 7, 7, 'cleared', '2026-02-06 09:23:10', NULL, NULL, NULL, '2026-02-06 16:15:05', NULL),
(3, 8, 5, 'cleared', '2026-02-07 21:28:03', NULL, NULL, NULL, '2026-02-11 09:54:47', NULL),
(4, 6, 11, 'cleared', '2026-02-11 09:56:43', NULL, NULL, NULL, '2026-02-11 15:00:40', NULL),
(5, 11, 1, 'cleared', '2026-02-11 15:01:38', NULL, NULL, NULL, '2026-02-11 15:22:18', NULL),
(6, 12, 1, 'cleared', '2026-02-11 15:58:21', NULL, NULL, NULL, '2026-02-11 15:59:21', NULL),
(7, 13, 6, 'assigned', '2026-02-11 18:34:09', NULL, NULL, NULL, NULL, NULL),
(8, 13, 7, 'assigned', '2026-02-11 21:47:58', NULL, NULL, NULL, NULL, NULL),
(9, 13, 9, 'assigned', '2026-02-11 21:56:05', NULL, NULL, NULL, NULL, NULL),
(10, 13, 8, 'assigned', '2026-02-11 21:58:06', NULL, NULL, NULL, NULL, NULL),
(11, 13, 10, 'assigned', '2026-02-11 21:58:31', NULL, NULL, NULL, NULL, NULL);

--
-- Triggers `dispatches`
--
DELIMITER $$
CREATE TRIGGER `trg_dispatches_ai_update_status` AFTER INSERT ON `dispatches` FOR EACH ROW BEGIN
  UPDATE `units`
    SET `status` = 'assigned', `current_incident_id` = NEW.`incident_id`, `last_status_at` = CURRENT_TIMESTAMP
    WHERE `id` = NEW.`unit_id`;
  UPDATE `incidents`
    SET `status` = 'dispatched', `updated_at` = CURRENT_TIMESTAMP
    WHERE `id` = NEW.`incident_id` AND `status` IN ('pending','cancelled');
END
$$
DELIMITER ;
DELIMITER $$
CREATE TRIGGER `trg_dispatches_au_propagate` AFTER UPDATE ON `dispatches` FOR EACH ROW BEGIN
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
END
$$
DELIMITER ;

DELIMITER $$
CREATE TRIGGER `trg_dispatch_operator_records_au_complete` AFTER UPDATE ON `dispatch_operator_records` FOR EACH ROW BEGIN
  DECLARE next_activity_log_id INT DEFAULT NULL;

  IF LOWER(COALESCE(NEW.`status`, '')) = 'completed'
     AND LOWER(COALESCE(OLD.`status`, '')) <> 'completed'
     AND NEW.`incident_id` IS NOT NULL
     AND NEW.`incident_id` > 0 THEN
    UPDATE `dispatches`
      SET `status` = 'cleared',
          `cleared_at` = COALESCE(`cleared_at`, CURRENT_TIMESTAMP)
      WHERE `incident_id` = NEW.`incident_id`
        AND `status` IN ('assigned','acknowledged','enroute','on_scene');

    UPDATE `units` u
      INNER JOIN `dispatches` d ON d.`unit_id` = u.`id`
      SET u.`status` = 'available',
          u.`current_incident_id` = NULL,
          u.`last_status_at` = CURRENT_TIMESTAMP
      WHERE d.`incident_id` = NEW.`incident_id`;

    UPDATE `incidents`
      SET `status` = 'resolved',
          `resolved_at` = COALESCE(`resolved_at`, CURRENT_TIMESTAMP),
          `updated_at` = CURRENT_TIMESTAMP
      WHERE `id` = NEW.`incident_id`;

    SELECT COALESCE(MAX(`id`), 0) + 1 INTO next_activity_log_id FROM `activity_log`;

    INSERT INTO `activity_log` (`id`, `user_id`, `action`, `entity_type`, `entity_id`, `details`, `created_at`)
      SELECT
        next_activity_log_id,
        NULL,
        'incident_resolved',
        'incident',
        i.`id`,
        CONCAT('Incident ', COALESCE(NULLIF(i.`reference_no`, ''), CONCAT('#', i.`id`)), ' has been resolved.'),
        CURRENT_TIMESTAMP
      FROM `incidents` i
      WHERE i.`id` = NEW.`incident_id`
        AND NOT EXISTS (
          SELECT 1
          FROM `activity_log` a
          WHERE a.`action` = 'incident_resolved'
            AND a.`entity_type` = 'incident'
            AND a.`entity_id` = i.`id`
          LIMIT 1
        )
      LIMIT 1;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `incidents`
--

CREATE TABLE `incidents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `reference_no` varchar(50) NOT NULL,
  `type` varchar(100) NOT NULL,
  `priority` enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `status` enum('pending','dispatched','resolved','cancelled') NOT NULL DEFAULT 'pending',
  `title` varchar(200) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `location_address` varchar(255) DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `reported_by_call_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `responded_at` datetime DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `incidents`
--

INSERT INTO `incidents` (`id`, `reference_no`, `type`, `priority`, `status`, `title`, `description`, `location_address`, `latitude`, `longitude`, `reported_by_call_id`, `created_at`, `updated_at`, `responded_at`, `resolved_at`) VALUES
(1, 'REF-20260202152404-8436', 'police', 'medium', 'pending', 'Incident from call REF-20260202152404-8436', 'nakaw', 'Diliman', NULL, NULL, 1, '2026-02-11 16:07:39', NULL, NULL, NULL),
(2, 'REF-20260203042136-4230', 'fire', 'low', 'pending', 'Incident from call REF-20260203042136-4230', 'bilog', 'Circle', NULL, NULL, 2, '2026-02-11 16:07:39', NULL, NULL, NULL),
(3, 'REF-20260204035154-9212', 'traffic', 'low', 'pending', 'Incident from call REF-20260204035154-9212', 'hfffgdxffdf', 'bagong silang caloocan city', NULL, NULL, 3, '2026-02-11 16:07:39', NULL, NULL, NULL),
(4, 'REF-20260204040430-8022', 'medical', 'high', 'pending', 'Incident from call REF-20260204040430-8022', 'stroke', 'Novaliches, Liliw, Laguna, Calabarzon, 4004, Philippines', NULL, NULL, 4, '2026-02-11 16:07:39', NULL, NULL, NULL),
(5, 'REF-20260204040916-4898', 'medical', 'high', 'pending', 'Incident from call REF-20260204040916-4898', 'heart attack', 'novaliches', NULL, NULL, 5, '2026-02-11 16:07:39', NULL, NULL, NULL),
(6, 'REF-20260204080042-4397', 'police', 'low', 'pending', 'Incident from call REF-20260204080042-4397', 'nakaw', 'City Hall', NULL, NULL, 6, '2026-02-11 16:07:39', NULL, NULL, NULL),
(7, 'REF-20260206022224-2140', 'fire', 'medium', 'pending', 'Incident from call REF-20260206022224-2140', 'sunog', 'City Hall', NULL, NULL, 7, '2026-02-11 16:07:39', NULL, NULL, NULL),
(8, 'REF-20260207142751-7153', 'medical', 'low', 'pending', 'Incident from call REF-20260207142751-7153', 'heart attack', 'novaliches', NULL, NULL, 8, '2026-02-11 16:07:39', NULL, NULL, NULL),
(9, 'REF-20260211075330-5287', 'fire', 'medium', 'pending', 'Incident from call REF-20260211075330-5287', 'nasusunog na pagawaan ng sapatos', 'novaliches, quezon city', NULL, NULL, 9, '2026-02-11 16:07:39', NULL, NULL, NULL),
(10, 'REF-20260211075707-4064', 'medical', 'low', 'pending', 'Incident from call REF-20260211075707-4064', 'fall from 2nd floor', 'Nicanor Padilla Street, San Miguel, Sixth District, Manila, Capital District, Metro Manila, 1005, Philippines', NULL, NULL, 10, '2026-02-11 16:07:39', NULL, NULL, NULL),
(11, 'REF-20260211085107-1710', 'medical', 'low', 'pending', 'Incident from call REF-20260211085107-1710', 'cardiac', 'Circle', NULL, NULL, 11, '2026-02-11 16:07:39', NULL, NULL, NULL),
(12, 'REF-20260211112610-3531', 'fire', 'low', 'pending', 'Incident from call REF-20260211112610-3531', 'burning stove', 'Quezon City Hall', NULL, NULL, 12, '2026-02-11 16:07:39', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `incident_notes`
--

CREATE TABLE `incident_notes` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `incident_id` bigint(20) UNSIGNED NOT NULL,
  `author_name` varchar(150) DEFAULT NULL,
  `note` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `incident_notes`
--

INSERT INTO `incident_notes` (`id`, `incident_id`, `author_name`, `note`, `created_at`) VALUES
(1, 3, 'System', 'Resolved via UI at 2/4/2026, 10:53:41 AM', '2026-02-04 10:53:41'),
(2, 3, 'System', 'Resolved via UI at 2/4/2026, 10:54:14 AM', '2026-02-04 10:54:14'),
(3, 2, 'System', 'Resolved via UI at 2/4/2026, 10:57:22 AM', '2026-02-04 10:57:22'),
(4, 5, 'System', 'Resolved via UI at 2/4/2026, 11:12:58 AM', '2026-02-04 11:12:58'),
(5, 5, 'System', 'Resolved via UI at 2/4/2026, 11:13:37 AM', '2026-02-04 11:13:37'),
(6, 4, 'System', 'Resolved via Dispatch UI at 2/4/2026, 11:34:57 AM', '2026-02-04 11:34:57'),
(7, 1, 'System', 'Resolved via UI at 2/4/2026, 2:55:15 PM', '2026-02-04 14:55:15'),
(8, 4, 'System', 'Resolution proof uploaded: /images/proofs/incident_4_20260206_064229_capture.jpg', '2026-02-06 13:42:29'),
(9, 7, 'System', 'Resolved via UI at 2/6/2026, 4:15:05 PM', '2026-02-06 16:15:05'),
(10, 8, 'System', 'Resolved via UI at 2/11/2026, 9:54:47 AM', '2026-02-11 09:54:47'),
(11, 9, 'System', 'Resolution proof uploaded: /images/proofs/incident_9_20260211_052425_capture.jpg.jpg', '2026-02-11 12:24:25'),
(12, 10, 'System', 'Resolved via UI at 2/11/2026, 3:00:08 PM', '2026-02-11 15:00:08'),
(13, 6, 'System', 'Resolved via UI at 2/11/2026, 3:00:40 PM', '2026-02-11 15:00:40'),
(14, 11, 'System', 'Resolved via UI at 2/11/2026, 3:22:18 PM', '2026-02-11 15:22:18'),
(15, 12, 'System', 'Resolved via UI at 2/11/2026, 3:59:21 PM', '2026-02-11 15:59:21');

-- --------------------------------------------------------

--
-- Table structure for table `interagency_requests`
--

CREATE TABLE `interagency_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `from_agency_id` bigint(20) UNSIGNED NOT NULL,
  `to_agency_id` bigint(20) UNSIGNED NOT NULL,
  `resource_type` varchar(100) NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `status` enum('pending','approved','rejected','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `otp_codes`
--

CREATE TABLE `otp_codes` (
  `id` int(11) NOT NULL,
  `email` varchar(255) NOT NULL,
  `otp_code` varchar(10) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `status` enum('active','used','expired') DEFAULT 'active'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `otp_codes`
--

INSERT INTO `otp_codes` (`id`, `email`, `otp_code`, `created_at`, `expires_at`, `status`) VALUES
(1, 'aldrinisidro6@gmail.com', '868800', '2026-02-02 22:08:10', '2026-02-02 15:11:10', 'active'),
(2, 'aldrinisidro6@gmail.com', '807644', '2026-02-02 22:40:53', '2026-02-02 15:43:53', 'active'),
(3, 'aldrinisidro6@gmail.com', '618574', '2026-02-02 22:47:41', '2026-02-02 15:50:41', 'active'),
(4, 'aldrinisidro6@gmail.com', '247431', '2026-02-02 22:50:30', '2026-02-02 15:53:30', 'active'),
(5, 'aldrinisidro6@gmail.com', '275462', '2026-02-02 23:01:52', '2026-02-02 16:04:52', 'active'),
(6, 'aldrinisidro6@gmail.com', '497930', '2026-02-04 13:42:17', '2026-02-04 06:45:17', 'active'),
(7, 'aldrinisidro6@gmail.com', '328071', '2026-02-04 13:52:38', '2026-02-04 06:55:38', 'active'),
(8, 'aldrinisidro6@gmail.com', '874186', '2026-02-04 13:57:19', '2026-02-04 07:00:19', 'active'),
(9, 'aldrinisidro6@gmail.com', '720502', '2026-02-05 00:56:45', '2026-02-04 17:59:45', 'active'),
(10, 'aldrinisidro6@gmail.com', '439167', '2026-02-06 09:00:06', '2026-02-06 02:03:06', 'active'),
(11, 'aldrinisidro6@gmail.com', '156548', '2026-02-06 13:04:32', '2026-02-06 06:07:32', 'active'),
(12, 'aldrinisidro6@gmail.com', '568830', '2026-02-06 18:39:21', '2026-02-06 11:42:21', 'active'),
(13, 'aldrinisidro6@gmail.com', '292495', '2026-02-07 19:47:46', '2026-02-07 12:50:46', 'active'),
(14, 'aldrinisidro6@gmail.com', '794837', '2026-02-08 15:59:44', '2026-02-08 09:02:44', 'active'),
(15, 'aldrinisidro6@gmail.com', '475494', '2026-02-09 22:50:43', '2026-02-09 15:53:43', 'active'),
(16, 'aldrinisidro6@gmail.com', '714467', '2026-02-09 23:27:33', '2026-02-09 16:30:33', 'active'),
(17, 'aldrinisidro6@gmail.com', '110948', '2026-02-10 23:08:48', '2026-02-10 16:11:48', 'active'),
(18, 'aldrinisidro6@gmail.com', '817542', '2026-02-11 09:39:57', '2026-02-11 02:42:57', 'active'),
(19, 'aldrinisidro6@gmail.com', '682425', '2026-02-11 09:45:38', '2026-02-11 02:48:38', 'active'),
(20, 'aldrinisidro6@gmail.com', '635086', '2026-02-11 10:47:08', '2026-02-11 03:50:08', 'active'),
(21, 'aldrinisidro6@gmail.com', '854504', '2026-02-11 11:17:57', '2026-02-11 04:20:57', 'active'),
(22, 'aldrinisidro6@gmail.com', '179078', '2026-02-11 12:22:37', '2026-02-11 05:25:37', 'active'),
(23, 'aldrinisidro6@gmail.com', '770427', '2026-02-11 13:41:56', '2026-02-11 06:44:56', 'active'),
(24, 'aldrinisidro6@gmail.com', '144404', '2026-02-11 17:37:32', '2026-02-11 10:40:32', 'active'),
(25, 'aldrinisidro6@gmail.com', '555544', '2026-02-11 18:10:14', '2026-02-11 11:13:14', 'active'),
(26, 'aldrinisidro6@gmail.com', '831973', '2026-02-11 21:46:26', '2026-02-11 14:49:25', 'active'),
(27, 'aldrinisidro6@gmail.com', '902054', '2026-02-12 06:40:57', '2026-02-11 23:43:57', 'active');

-- --------------------------------------------------------

--
-- Table structure for table `reports`
--

CREATE TABLE `reports` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `report_type` enum('daily','weekly','monthly','incident') NOT NULL,
  `period_start` date DEFAULT NULL,
  `period_end` date DEFAULT NULL,
  `generated_at` datetime NOT NULL DEFAULT current_timestamp(),
  `generated_by` varchar(150) DEFAULT NULL,
  `status` enum('pending','ready','failed') NOT NULL DEFAULT 'ready',
  `summary_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`summary_json`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `report_metrics`
--

CREATE TABLE `report_metrics` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `report_id` bigint(20) UNSIGNED NOT NULL,
  `metric_name` varchar(150) NOT NULL,
  `metric_value` decimal(18,4) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

CREATE TABLE responders (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  department ENUM('fire','police','medical','barangay','other') NOT NULL DEFAULT 'other',
  email VARCHAR(255) NOT NULL,
  contact_number VARCHAR(50) NOT NULL DEFAULT '',
  assigned_unit_id BIGINT UNSIGNED DEFAULT NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_responders_assigned_unit_id (assigned_unit_id),
  UNIQUE KEY uq_responders_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE responder_otps (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  responder_email VARCHAR(255) NOT NULL,
  otp VARCHAR(10) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME DEFAULT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  INDEX idx_responder_email (responder_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Table structure for table `resources`
--

CREATE TABLE `resources` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `type` enum('vehicle','equipment','facility','other') NOT NULL DEFAULT 'other',
  `name` varchar(200) NOT NULL,
  `code` varchar(50) DEFAULT NULL,
  `status` enum('available','deployed','maintenance','out_of_service') NOT NULL DEFAULT 'available',
  `location` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `resources`
--

INSERT INTO `resources` (`id`, `type`, `name`, `code`, `status`, `location`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'equipment', 'Portable Defibrillator', 'EQ-SEED-001', 'available', 'Station 1', 'Seeded resource data', '2026-02-19 00:00:00', NULL),
(2, 'equipment', 'Trauma Kit', 'EQ-SEED-002', 'available', 'Station 2', 'Seeded resource data', '2026-02-19 00:00:00', NULL),
(3, 'equipment', 'Oxygen Tank', 'EQ-SEED-003', 'available', 'Station 3', 'Seeded resource data', '2026-02-19 00:00:00', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `resource_requests`
--

CREATE TABLE `resource_requests` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `requestor` varchar(150) NOT NULL,
  `resource_name` varchar(200) NOT NULL,
  `date_requested` datetime NOT NULL DEFAULT current_timestamp(),
  `status` enum('pending','approved','rejected','fulfilled','cancelled') NOT NULL DEFAULT 'pending',
  `details` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `resource_requests`
--

INSERT INTO `resource_requests` (`id`, `requestor`, `resource_name`, `date_requested`, `status`, `details`) VALUES
(1, 'Aldrin', 'Fire Truck', '2026-02-05 01:01:42', 'rejected', '{\"type\":\"vehicle\",\"quantity\":1,\"priority\":\"medium\",\"location\":\"quezon city hall\",\"notes\":\"need now\",\"urgency\":\"normal\",\"decision_reason\":\"I don\'t wanna\"}'),
(2, 'Dispatch Center', 'Police', '2026-02-11 18:49:11', 'approved', '{\"type\":\"other\",\"quantity\":1,\"priority\":\"high\",\"location\":\"Dispatch HQ\",\"notes\":\"Requested via quick action\",\"urgency\":\"urgent\",\"decision_reason\":\"\"}');

-- --------------------------------------------------------

--
-- Table structure for table `shared_resources`
--

CREATE TABLE `shared_resources` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `agency_id` bigint(20) UNSIGNED NOT NULL,
  `resource_type` varchar(100) NOT NULL,
  `name` varchar(200) NOT NULL,
  `quantity_total` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `quantity_available` int(10) UNSIGNED NOT NULL DEFAULT 0,
  `status` enum('available','unavailable','maintenance') NOT NULL DEFAULT 'available',
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `shared_resources`
--
DELIMITER $$
CREATE TRIGGER `trg_shared_resources_bu_bounds` BEFORE UPDATE ON `shared_resources` FOR EACH ROW BEGIN
  IF NEW.`quantity_available` < 0 THEN SET NEW.`quantity_available` = 0; END IF;
  IF NEW.`quantity_available` > NEW.`quantity_total` THEN SET NEW.`quantity_available` = NEW.`quantity_total`; END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `staff`
--

CREATE TABLE `staff` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `role` varchar(100) DEFAULT NULL,
  `phone` varchar(50) DEFAULT NULL,
  `email` varchar(150) DEFAULT NULL,
  `status` enum('available','on_duty','off_duty','leave') NOT NULL DEFAULT 'available',
  `assigned_resource_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `staff`
--

INSERT INTO `staff` (`id`, `name`, `role`, `phone`, `email`, `status`, `assigned_resource_id`, `created_at`, `updated_at`) VALUES
(1, 'Responder Ana Reyes', 'Paramedic', NULL, NULL, 'available', NULL, '2026-02-19 00:00:00', NULL),
(2, 'Responder Mark Santos', 'EMT', NULL, NULL, 'available', NULL, '2026-02-19 00:00:00', NULL),
(3, 'Responder Leo Cruz', 'Nurse', NULL, NULL, 'available', NULL, '2026-02-19 00:00:00', NULL);

--
-- Triggers `staff`
--
DELIMITER $$
CREATE TRIGGER `trg_staff_au_toggle_resource` AFTER UPDATE ON `staff` FOR EACH ROW BEGIN
  IF NEW.`assigned_resource_id` IS NOT NULL THEN
    UPDATE `resources` SET `status` = 'deployed', `updated_at` = CURRENT_TIMESTAMP WHERE `id` = NEW.`assigned_resource_id`;
  END IF;
  IF OLD.`assigned_resource_id` IS NOT NULL AND NEW.`assigned_resource_id` IS NULL THEN
    UPDATE `resources` SET `status` = 'available', `updated_at` = CURRENT_TIMESTAMP WHERE `id` = OLD.`assigned_resource_id`;
  END IF;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `units`
--

CREATE TABLE `units` (
  `id` int(11) NOT NULL,
  `identifier` varchar(50) NOT NULL,
  `unit_type` enum('ambulance','fire','police','rescue','other') NOT NULL DEFAULT 'other',
  `status` enum('available','assigned','enroute','on_scene','unavailable','maintenance') NOT NULL DEFAULT 'available',
  `current_incident_id` bigint(20) UNSIGNED DEFAULT NULL,
  `latitude` decimal(10,7) DEFAULT NULL,
  `longitude` decimal(10,7) DEFAULT NULL,
  `last_status_at` datetime NOT NULL DEFAULT current_timestamp(),
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `units`
--

INSERT INTO `units` (`id`, `identifier`, `unit_type`, `status`, `current_incident_id`, `latitude`, `longitude`, `last_status_at`, `created_at`, `updated_at`) VALUES
(1, 'AMB-01', 'ambulance', 'available', NULL, 14.5995000, 120.9842000, '2026-02-12 07:05:56', '2026-02-12 07:05:56', '2026-02-12 07:05:56'),
(2, 'AMB-02', 'ambulance', 'available', NULL, 14.6010000, 120.9890000, '2026-02-12 07:05:56', '2026-02-12 07:05:56', '2026-02-12 07:05:56'),
(3, 'AMB-03', 'ambulance', 'available', NULL, 14.5822000, 121.0122000, '2026-02-12 07:05:56', '2026-02-12 07:05:56', '2026-02-12 07:05:56'),
(4, 'POL-01', 'police', 'available', NULL, 14.6200000, 121.0500000, '2026-02-12 07:05:56', '2026-02-12 07:05:56', '2026-02-12 07:05:56'),
(5, 'POL-02', 'police', 'available', NULL, 14.5547000, 121.0244000, '2026-02-12 07:05:56', '2026-02-12 07:05:56', '2026-02-12 07:05:56'),
(6, 'POL-03', 'police', 'available', NULL, 14.6500000, 121.0300000, '2026-02-12 07:05:56', '2026-02-12 07:05:56', '2026-02-12 07:05:56'),
(7, 'FIR-01', 'fire', 'available', NULL, 14.5700000, 121.0400000, '2026-02-12 07:05:56', '2026-02-12 07:05:56', '2026-02-12 07:05:56'),
(8, 'FIR-02', 'fire', 'available', NULL, 14.5900000, 120.9700000, '2026-02-12 07:05:56', '2026-02-12 07:05:56', '2026-02-12 07:05:56'),
(9, 'FIR-03', 'fire', 'available', NULL, 14.6100000, 121.0200000, '2026-02-12 07:05:56', '2026-02-12 07:05:56', '2026-02-12 07:05:56');

-- --------------------------------------------------------

--
-- Table structure for table `unit_locations`
--

CREATE TABLE `unit_locations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `unit_id` bigint(20) UNSIGNED NOT NULL,
  `latitude` decimal(10,7) NOT NULL,
  `longitude` decimal(10,7) NOT NULL,
  `speed_kph` decimal(6,2) DEFAULT NULL,
  `heading_deg` decimal(5,2) DEFAULT NULL,
  `recorded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Triggers `unit_locations`
--
DELIMITER $$
CREATE TRIGGER `trg_unit_locations_ai_update_unit` AFTER INSERT ON `unit_locations` FOR EACH ROW BEGIN
  UPDATE `units` SET `latitude` = NEW.`latitude`, `longitude` = NEW.`longitude`, `last_status_at` = CURRENT_TIMESTAMP
  WHERE `id` = NEW.`unit_id`;
END
$$
DELIMITER ;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(150) NOT NULL,
  `password` varchar(255) NOT NULL,
  `name` varchar(150) NOT NULL,
  `department` varchar(150) DEFAULT NULL,
  `unit_code` varchar(50) DEFAULT NULL,
  `unit_type` varchar(50) DEFAULT NULL,
  `vehicle_plate` varchar(50) DEFAULT NULL,
  `unit_status` varchar(50) DEFAULT NULL,
  `role` enum('admin','operator','viewer','dispatcher','responder') NOT NULL DEFAULT 'viewer',
  `status` enum('active','inactive') NOT NULL DEFAULT 'active',
  `inactive_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NULL DEFAULT NULL ON UPDATE current_timestamp(),
  `last_login` timestamp NULL DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `email`, `password`, `name`, `department`, `role`, `status`, `inactive_at`, `created_at`, `updated_at`, `last_login`) VALUES
(1, 'aldrinisidro6@gmail.com', '$2y$10$XaD//IFx/8UDuAraZmWPG.O9T8TI3dC3U8HzyrNookjpMUXumSu8G', 'Aldrin', 'Administration', 'admin', 'active', NULL, '2026-02-10 16:33:47', '2026-02-11 22:40:57', '2026-02-11 22:40:57'),
(1, 'aldrinisidro6@gmail.com', '$2y$10$uijKapQWHxkPLPRxIHW34OoTWNaXnJlaxWVQeboSSdM1Kat33Sk6q', 'Aldrin', 'Administration', 'admin', 'active', NULL, '2026-02-02 14:05:18', '2026-02-11 22:40:57', '2026-02-11 22:40:57'),
(2, 'admin@example.com', '$2y$10$/KMzrUa6seIiuUY2tDCALOwnudXUgXu02z9OYL33tvmjUUWx3jCT6', 'Administrator', 'Administration', 'admin', 'active', NULL, '2026-02-09 16:25:22', NULL, NULL),
(1, 'aldrinisidro6@gmail.com', '$2y$10$uijKapQWHxkPLPRxIHW34OoTWNaXnJlaxWVQeboSSdM1Kat33Sk6q', 'Aldrin', 'Administration', 'admin', 'active', NULL, '2026-02-02 14:05:18', '2026-02-11 13:46:25', '2026-02-11 13:46:25'),
(2, 'admin@example.com', '$2y$10$/KMzrUa6seIiuUY2tDCALOwnudXUgXu02z9OYL33tvmjUUWx3jCT6', 'Administrator', 'Administration', 'admin', 'active', NULL, '2026-02-09 16:25:22', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `activity_log`
--
ALTER TABLE `activity_log`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `admin_resources`
--
ALTER TABLE `admin_resources`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_admin_resources_code` (`code`),
  ADD KEY `idx_admin_resources_category` (`category`),
  ADD KEY `idx_admin_resources_status` (`status`);

--
-- Indexes for table `admin_resources_archive`
--
ALTER TABLE `admin_resources_archive`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_admin_resources_archive_resource_id` (`resource_id`),
  ADD KEY `idx_admin_resources_archive_deleted_at` (`deleted_at`),
  ADD KEY `idx_admin_resources_archive_category` (`category`);

--
-- Indexes for table `resource_records`
--
ALTER TABLE `resource_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_resource_records_code` (`code`),
  ADD KEY `idx_resource_records_category` (`category`),
  ADD KEY `idx_resource_records_status` (`status`);

--
-- Indexes for table `resource_records_archive`
--
ALTER TABLE `resource_records_archive`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_resource_records_archive_resource_id` (`resource_id`),
  ADD KEY `idx_resource_records_archive_deleted_at` (`deleted_at`),
  ADD KEY `idx_resource_records_archive_category` (`category`);

--
-- Indexes for table `agencies`
--
ALTER TABLE `agencies`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_agencies_name` (`name`),
  ADD KEY `idx_agencies_status` (`status`);

--
-- Indexes for table `calls`
--
ALTER TABLE `calls`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_calls_reference_no` (`reference_no`),
  ADD KEY `idx_calls_status` (`status`),
  ADD KEY `idx_calls_priority` (`priority`),
  ADD KEY `idx_calls_received_at` (`received_at`);

--
-- Indexes for table `dispatches`
--
ALTER TABLE `dispatches`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_dispatches_incident_id` (`incident_id`),
  ADD KEY `idx_dispatches_unit_id` (`unit_id`),
  ADD KEY `idx_dispatches_status` (`status`),
  ADD KEY `idx_dispatches_assigned_at` (`assigned_at`),
  ADD KEY `idx_dispatches_on_scene_at` (`on_scene_at`);

--
-- Indexes for table `incidents`
--
ALTER TABLE `incidents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_incidents_reference_no` (`reference_no`),
  ADD KEY `idx_incidents_type` (`type`),
  ADD KEY `idx_incidents_priority` (`priority`),
  ADD KEY `idx_incidents_status` (`status`),
  ADD KEY `idx_incidents_created_at` (`created_at`),
  ADD KEY `idx_incidents_responded_at` (`responded_at`),
  ADD KEY `fk_incidents_call` (`reported_by_call_id`);

--
-- Indexes for table `incident_notes`
--
ALTER TABLE `incident_notes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_incident_notes_incident_id` (`incident_id`),
  ADD KEY `idx_incident_notes_created_at` (`created_at`);

--
-- Indexes for table `interagency_requests`
--
ALTER TABLE `interagency_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_iar_from_agency_id` (`from_agency_id`),
  ADD KEY `idx_iar_to_agency_id` (`to_agency_id`),
  ADD KEY `idx_iar_status` (`status`);

--
-- Indexes for table `otp_codes`
--
ALTER TABLE `otp_codes`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `reports`
--
ALTER TABLE `reports`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reports_type` (`report_type`),
  ADD KEY `idx_reports_generated_at` (`generated_at`);

--
-- Indexes for table `report_metrics`
--
ALTER TABLE `report_metrics`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_report_metrics_report_id` (`report_id`),
  ADD KEY `idx_report_metrics_name` (`metric_name`);

--
-- Indexes for table `resources`
--
ALTER TABLE `resources`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_resources_code` (`code`),
  ADD KEY `idx_resources_type` (`type`),
  ADD KEY `idx_resources_status` (`status`);

--
-- Indexes for table `resource_requests`
--
ALTER TABLE `resource_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_rr_status` (`status`),
  ADD KEY `idx_rr_date_requested` (`date_requested`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uk_users_email` (`email`),
  ADD KEY `idx_users_role` (`role`),
  ADD KEY `idx_users_status` (`status`),
  ADD KEY `idx_users_inactive_at` (`inactive_at`);

--
-- Indexes for table `staff`
--
ALTER TABLE `staff`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `units`
--
ALTER TABLE `units`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `calls`
--
ALTER TABLE `calls`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `admin_resources`
--
ALTER TABLE `admin_resources`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `admin_resources_archive`
--
ALTER TABLE `admin_resources_archive`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `resource_records`
--
ALTER TABLE `resource_records`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `resource_records_archive`
--
ALTER TABLE `resource_records_archive`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `incidents`
--
ALTER TABLE `incidents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT for table `otp_codes`
--
ALTER TABLE `otp_codes`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `resource_requests`
--
ALTER TABLE `resource_requests`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- Media storage tables
--
CREATE TABLE IF NOT EXISTS `user_profile_images` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(150) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `image_blob` LONGBLOB NOT NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_user_profile_images_user` (`user_id`),
  KEY `idx_user_profile_images_active` (`user_id`, `is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `interagency_attachment_uploads` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `message_id` INT DEFAULT NULL,
  `file_name` VARCHAR(255) NOT NULL,
  `mime_type` VARCHAR(150) NOT NULL,
  `file_size` BIGINT UNSIGNED NOT NULL DEFAULT 0,
  `file_blob` LONGBLOB NOT NULL,
  `is_image` TINYINT(1) NOT NULL DEFAULT 0,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at` DATETIME NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_interagency_attachment_uploads_user` (`user_id`),
  KEY `idx_interagency_attachment_uploads_message` (`message_id`),
  KEY `idx_interagency_attachment_uploads_exp` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- AUTO_INCREMENT for table `resources`
--
ALTER TABLE `resources`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `staff`
--
ALTER TABLE `staff`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `units`
--
ALTER TABLE `units`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=10;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
