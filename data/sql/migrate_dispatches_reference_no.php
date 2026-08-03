<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/db.php';

$pdo = get_db_connection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$database = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();

function column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$table, $column]);
    return (bool)$stmt->fetchColumn();
}

function index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$table, $index]);
    return (bool)$stmt->fetchColumn();
}

function table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1'
    );
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function quote_identifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

if (!table_exists($pdo, 'dispatches') || !table_exists($pdo, 'incidents')) {
    fwrite(STDERR, "Required tables dispatches/incidents were not found in {$database}.\n");
    exit(1);
}

$backupTable = 'dispatches_backup_reference_no_' . date('Ymd_His');
$pdo->exec('CREATE TABLE ' . quote_identifier($backupTable) . ' LIKE `dispatches`');
$pdo->exec('INSERT INTO ' . quote_identifier($backupTable) . ' SELECT * FROM `dispatches`');

$pdo->exec('DROP TRIGGER IF EXISTS `trg_dispatches_bi_reference_no`');
$pdo->exec('DROP TRIGGER IF EXISTS `trg_dispatches_bu_reference_no`');
$pdo->exec('DROP TRIGGER IF EXISTS `trg_dispatches_ai_update_status`');
$pdo->exec('DROP TRIGGER IF EXISTS `trg_dispatches_au_propagate`');
if (table_exists($pdo, 'dispatch_operator_records')) {
    $pdo->exec('DROP TRIGGER IF EXISTS `trg_dispatch_operator_records_au_complete`');
}

if (!column_exists($pdo, 'dispatches', 'reference_no')) {
    $pdo->exec('ALTER TABLE `dispatches` ADD COLUMN `reference_no` VARCHAR(50) DEFAULT NULL AFTER `id`');
}

$pdo->exec('ALTER TABLE `dispatches` MODIFY COLUMN `reference_no` VARCHAR(50) DEFAULT NULL');

$hasDispatchIncidentId = column_exists($pdo, 'dispatches', 'incident_id');

if ($hasDispatchIncidentId) {
    $pdo->exec('ALTER TABLE `dispatches` MODIFY COLUMN `incident_id` BIGINT(20) UNSIGNED DEFAULT NULL');

    $pdo->exec(
        "UPDATE `dispatches` d
         INNER JOIN `incidents` i ON i.`id` = d.`incident_id`
         SET d.`reference_no` = i.`reference_no`
         WHERE (d.`reference_no` IS NULL OR TRIM(d.`reference_no`) = '')"
    );

    $pdo->exec(
        "UPDATE `dispatches` d
         INNER JOIN `incidents` i ON i.`reference_no` = d.`reference_no`
         SET d.`incident_id` = i.`id`
         WHERE (d.`incident_id` IS NULL OR d.`incident_id` = 0)
           AND d.`reference_no` IS NOT NULL
           AND TRIM(d.`reference_no`) <> ''"
    );
}

$pdo->exec(
    "UPDATE `dispatches` d
     INNER JOIN `incidents` i ON i.`id` = CAST(d.`reference_no` AS UNSIGNED)
     SET d.`reference_no` = i.`reference_no`
     WHERE d.`reference_no` REGEXP '^[0-9]+$'"
);

$pdo->exec(
    "UPDATE `dispatches` d
     LEFT JOIN `incidents` i ON i.`reference_no` = d.`reference_no`
     SET d.`reference_no` = NULL
     WHERE i.`id` IS NULL
       AND (d.`reference_no` = '0' OR d.`reference_no` REGEXP '^[0-9]+$')"
);

if (!index_exists($pdo, 'dispatches', 'idx_dispatches_reference_no')) {
    $pdo->exec('ALTER TABLE `dispatches` ADD KEY `idx_dispatches_reference_no` (`reference_no`)');
}

if ($hasDispatchIncidentId) {
    $pdo->exec(
        "CREATE TRIGGER `trg_dispatches_bi_reference_no`
         BEFORE INSERT ON `dispatches`
         FOR EACH ROW
         BEGIN
           IF (NEW.`reference_no` IS NULL OR TRIM(NEW.`reference_no`) = '')
              AND NEW.`incident_id` IS NOT NULL THEN
             SET NEW.`reference_no` = (
               SELECT `reference_no`
               FROM `incidents`
               WHERE `id` = NEW.`incident_id`
               LIMIT 1
             );
           END IF;

           IF (NEW.`incident_id` IS NULL OR NEW.`incident_id` = 0)
              AND NEW.`reference_no` IS NOT NULL
              AND TRIM(NEW.`reference_no`) <> '' THEN
             SET NEW.`incident_id` = (
               SELECT `id`
               FROM `incidents`
               WHERE `reference_no` = NEW.`reference_no`
               LIMIT 1
             );
           END IF;
         END"
    );

    $pdo->exec(
        "CREATE TRIGGER `trg_dispatches_bu_reference_no`
         BEFORE UPDATE ON `dispatches`
         FOR EACH ROW
         BEGIN
           IF (NEW.`reference_no` IS NULL OR TRIM(NEW.`reference_no`) = '')
              AND NEW.`incident_id` IS NOT NULL THEN
             SET NEW.`reference_no` = (
               SELECT `reference_no`
               FROM `incidents`
               WHERE `id` = NEW.`incident_id`
               LIMIT 1
             );
           END IF;

           IF (NEW.`incident_id` IS NULL OR NEW.`incident_id` = 0)
              AND NEW.`reference_no` IS NOT NULL
              AND TRIM(NEW.`reference_no`) <> '' THEN
             SET NEW.`incident_id` = (
               SELECT `id`
               FROM `incidents`
               WHERE `reference_no` = NEW.`reference_no`
               LIMIT 1
             );
           END IF;
         END"
    );
}

$pdo->exec(
    "CREATE TRIGGER `trg_dispatches_ai_update_status`
     AFTER INSERT ON `dispatches`
     FOR EACH ROW
     BEGIN
       UPDATE `units`
          SET `status` = 'assigned',
              `current_incident_id` = (SELECT `id` FROM `incidents` WHERE `reference_no` = NEW.`reference_no` LIMIT 1),
              `last_status_at` = CURRENT_TIMESTAMP
        WHERE `id` = NEW.`unit_id`;

       UPDATE `incidents`
          SET `status` = 'dispatched',
              `updated_at` = CURRENT_TIMESTAMP
        WHERE `reference_no` = NEW.`reference_no`
          AND `status` IN ('pending','cancelled');
     END"
);

$pdo->exec(
    "CREATE TRIGGER `trg_dispatches_au_propagate`
     AFTER UPDATE ON `dispatches`
     FOR EACH ROW
     BEGIN
       IF NEW.`status` = 'enroute' THEN
         UPDATE `units` SET `status` = 'enroute', `last_status_at` = CURRENT_TIMESTAMP WHERE `id` = NEW.`unit_id`;
       ELSEIF NEW.`status` = 'on_scene' THEN
         UPDATE `units` SET `status` = 'on_scene', `last_status_at` = CURRENT_TIMESTAMP WHERE `id` = NEW.`unit_id`;
       ELSEIF NEW.`status` IN ('cleared','cancelled') THEN
         UPDATE `units` SET `status` = 'available', `current_incident_id` = NULL, `last_status_at` = CURRENT_TIMESTAMP WHERE `id` = NEW.`unit_id`;
       END IF;

       IF NEW.`status` = 'cleared' THEN
         UPDATE `incidents` SET `status` = 'resolved', `resolved_at` = CURRENT_TIMESTAMP WHERE `reference_no` = NEW.`reference_no`;
       ELSEIF NEW.`status` = 'cancelled' THEN
         UPDATE `incidents` SET `status` = 'cancelled' WHERE `reference_no` = NEW.`reference_no`;
       END IF;
     END"
);

if (table_exists($pdo, 'dispatch_operator_records') && table_exists($pdo, 'activity_log')) {
    $pdo->exec(
        "CREATE TRIGGER `trg_dispatch_operator_records_au_complete`
         AFTER UPDATE ON `dispatch_operator_records`
         FOR EACH ROW
         BEGIN
           DECLARE next_activity_log_id INT DEFAULT NULL;

           IF LOWER(COALESCE(NEW.`status`, '')) = 'completed'
              AND LOWER(COALESCE(OLD.`status`, '')) <> 'completed'
              AND NEW.`incident_id` IS NOT NULL
              AND NEW.`incident_id` > 0 THEN
             UPDATE `dispatches`
                SET `status` = 'cleared',
                    `cleared_at` = COALESCE(`cleared_at`, CURRENT_TIMESTAMP)
              WHERE `reference_no` = (SELECT `reference_no` FROM `incidents` WHERE `id` = NEW.`incident_id` LIMIT 1)
                AND `status` IN ('assigned','acknowledged','enroute','on_scene');

             UPDATE `units` u
             INNER JOIN `dispatches` d ON d.`unit_id` = u.`id`
                SET u.`status` = 'available',
                    u.`current_incident_id` = NULL,
                    u.`last_status_at` = CURRENT_TIMESTAMP
              WHERE d.`reference_no` = (SELECT `reference_no` FROM `incidents` WHERE `id` = NEW.`incident_id` LIMIT 1);

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
         END"
    );
}

$summary = [
    'database' => $database,
    'backup_table' => $backupTable,
    'dispatches_total' => (int)$pdo->query('SELECT COUNT(*) FROM `dispatches`')->fetchColumn(),
    'reference_no_filled' => (int)$pdo->query("SELECT COUNT(*) FROM `dispatches` WHERE `reference_no` IS NOT NULL AND TRIM(`reference_no`) <> ''")->fetchColumn(),
    'reference_no_missing' => (int)$pdo->query("SELECT COUNT(*) FROM `dispatches` WHERE `reference_no` IS NULL OR TRIM(`reference_no`) = ''")->fetchColumn(),
    'reference_no_matched_incidents' => (int)$pdo->query(
        "SELECT COUNT(*)
         FROM `dispatches` d
         INNER JOIN `incidents` i ON i.`reference_no` = d.`reference_no`"
    )->fetchColumn(),
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
