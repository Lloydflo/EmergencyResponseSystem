<?php
header('Content-Type: application/json');

// Secure, idempotent DB initializer for first-time deployments
// Usage (once): /api/setup_database.php?token=YOUR_SETUP_TOKEN
// The token must match SETUP_TOKEN from environment or .env

require_once __DIR__ . '/../includes/config.php';

$out = [
    'status' => 'failed',
    'steps' => [],
    'errors' => [],
];

$tokenProvided = isset($_GET['token']) ? trim((string)$_GET['token']) : '';
$tokenExpected = getenv('SETUP_TOKEN') ?: ($_ENV['SETUP_TOKEN'] ?? ($_SERVER['SETUP_TOKEN'] ?? ''));
if ($tokenExpected === '' || $tokenProvided === '' || !hash_equals((string)$tokenExpected, (string)$tokenProvided)) {
    http_response_code(403);
    echo json_encode([
        'status' => 'forbidden',
        'error' => 'Invalid or missing setup token. Set SETUP_TOKEN in .env and pass ?token=... once.',
    ]);
    exit;
}

// Build a server-level PDO (no DB selected) so we can CREATE DATABASE if needed
$host = defined('DB_HOST') ? DB_HOST : 'localhost';
$port = defined('DB_PORT') ? DB_PORT : 3306;
$charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
$dbName = defined('DB_NAME') ? DB_NAME : 'ers_db';
$user = defined('DB_USER') ? DB_USER : 'root';
$pass = defined('DB_PASS') ? DB_PASS : '';

// Handle host:port style (DB_PORT wins if set)
$hostOnly = $host;
if (strpos($hostOnly, ':') !== false) {
    $parts = explode(':', $hostOnly, 2);
    $hostOnly = $parts[0];
    if (!empty($parts[1]) && ctype_digit($parts[1]) && empty($port)) {
        $port = (int)$parts[1];
    }
}

try {
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('pdo_mysql extension not loaded. Enable it in php.ini.');
    }

    $serverDsn = "mysql:host={$hostOnly};port={$port};charset={$charset}";
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => true, // allow multi-statement exec
    ];
    $serverPdo = new PDO($serverDsn, $user, $pass, $options);
    $out['steps'][] = 'Connected to MySQL server';

    // Ensure database exists and select it
    $serverPdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET {$charset} COLLATE {$charset}_unicode_ci");
    $out['steps'][] = "Database ensured: {$dbName}";
    $serverPdo->exec("USE `{$dbName}`");
    $out['steps'][] = "Database selected: {$dbName}";

    // Execute schema from data/sql/ers_db.sql with DELIMITER support
    $schemaPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'sql' . DIRECTORY_SEPARATOR . 'ers_db.sql';
    if (!is_file($schemaPath) || !is_readable($schemaPath)) {
        throw new RuntimeException('Schema file not found or unreadable at data/sql/ers_db.sql');
    }
    $sql = file($schemaPath, FILE_IGNORE_NEW_LINES);
    if ($sql === false) {
        throw new RuntimeException('Failed to read schema file');
    }

    // Simple MySQL script runner honoring DELIMITER changes
    $delimiter = ';';
    $buffer = '';
    $executed = 0;
    $errors = 0;

    $runStatement = function(PDO $pdo, string $stmt) use (&$executed, &$errors, &$out) {
        $trim = trim($stmt);
        if ($trim === '' || str_starts_with($trim, '--') || str_starts_with($trim, '#')) {
            return; // skip comments/empties
        }
        try {
            $pdo->exec($stmt);
            $executed++;
        } catch (Throwable $e) {
            // Some statements may fail on re-run (idempotency) or due to FK order; capture and continue
            $errors++;
            $out['errors'][] = substr($e->getMessage(), 0, 500);
        }
    };

    foreach ($sql as $lineRaw) {
        $line = rtrim($lineRaw, "\r\n");
        // Handle DELIMITER directive
        if (preg_match('/^\s*DELIMITER\s+(.+)\s*$/i', $line, $m)) {
            // Flush any buffered statement before switching delimiter
            if (trim($buffer) !== '') {
                $runStatement($serverPdo, $buffer);
                $buffer = '';
            }
            $delimiter = $m[1];
            continue;
        }

        $buffer .= $line . "\n";
        // If buffer ends with current delimiter (on its own), execute
        if ($delimiter !== '' && str_ends_with(rtrim($buffer), $delimiter)) {
            // Strip the trailing delimiter
            $stmt = preg_replace('/' . preg_quote($delimiter, '/') . '\s*$/', '', rtrim($buffer));
            $runStatement($serverPdo, $stmt);
            $buffer = '';
        }
    }
    // Final flush
    if (trim($buffer) !== '') {
        $runStatement($serverPdo, $buffer);
        $buffer = '';
    }

    $out['steps'][] = "Schema applied: {$executed} statements ({$errors} errors captured)";

    // Ensure key tables exist; create incident_proofs last to satisfy FKs if earlier pass failed
    try {
        $chk = $serverPdo->query("SHOW TABLES LIKE 'incidents'");
        $hasInc = $chk && $chk->fetch() ? true : false;
        $chk2 = $serverPdo->query("SHOW TABLES LIKE 'incident_proofs'");
        $hasProofs = $chk2 && $chk2->fetch() ? true : false;
        if ($hasInc && !$hasProofs) {
            $serverPdo->exec(
                "CREATE TABLE IF NOT EXISTS `incident_proofs` (
                  `id` INT NOT NULL AUTO_INCREMENT,
                  `incident_id` INT NOT NULL,
                  `file_path` VARCHAR(255) NOT NULL,
                  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                  PRIMARY KEY (`id`),
                  KEY `idx_incident_proofs_incident` (`incident_id`),
                  CONSTRAINT `fk_incident_proofs_incident` FOREIGN KEY (`incident_id`) REFERENCES `incidents`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET={$charset}"
            );
            $out['steps'][] = 'Table ensured: incident_proofs';
        }
    } catch (Throwable $e) {
        $out['errors'][] = 'Ensure incident_proofs failed: ' . substr($e->getMessage(), 0, 500);
    }

    // Optional admin bootstrap
    $adminEmail = getenv('ADMIN_EMAIL') ?: ($_ENV['ADMIN_EMAIL'] ?? ($_SERVER['ADMIN_EMAIL'] ?? ''));
    $adminPass = getenv('ADMIN_PASSWORD') ?: ($_ENV['ADMIN_PASSWORD'] ?? ($_SERVER['ADMIN_PASSWORD'] ?? ''));
    if ($adminEmail && $adminPass) {
        try {
            $serverPdo->exec("CREATE TABLE IF NOT EXISTS `users` (
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
            ) ENGINE=InnoDB DEFAULT CHARSET={$charset} COLLATE={$charset}_unicode_ci");

            $stmt = $serverPdo->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            $stmt->execute([$adminEmail]);
            if (!$stmt->fetch()) {
                $hash = password_hash($adminPass, PASSWORD_DEFAULT);
                $ins = $serverPdo->prepare('INSERT INTO users (email, password, name, role, status) VALUES (?, ?, ?, "admin", "active")');
                $ins->execute([$adminEmail, $hash, 'Administrator']);
                $out['steps'][] = 'Admin user created';
            } else {
                $out['steps'][] = 'Admin user already exists';
            }
        } catch (Throwable $e) {
            $out['errors'][] = 'Admin bootstrap failed: ' . substr($e->getMessage(), 0, 500);
        }
    } else {
        $out['steps'][] = 'Admin bootstrap skipped (set ADMIN_EMAIL and ADMIN_PASSWORD to enable)';
    }

    $out['status'] = 'ok';
} catch (Throwable $e) {
    $out['errors'][] = substr($e->getMessage(), 0, 1000);
    $out['status'] = 'failed';
}

echo json_encode($out);
