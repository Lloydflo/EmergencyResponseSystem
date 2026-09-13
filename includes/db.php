<?php
date_default_timezone_set('Asia/Manila');
if (file_exists(__DIR__ . '/config.php')) {
    require_once __DIR__ . '/config.php';
}

function get_db_connection(): ?PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $config = [];
    if (file_exists(__DIR__ . '/config.php')) {
        $config = require __DIR__ . '/config.php';
    }

    if (!is_array($config) || empty($config['DB_NAME']) || empty($config['DB_USER'])) {
        error_log('Database connection failed: missing DB_NAME or DB_USER in configuration.');
        return null;
    }

    $port = isset($config['DB_PORT']) && $config['DB_PORT'] !== '' ? (string)$config['DB_PORT'] : '3306';

    // Compile candidate hosts in priority order
    $candidateHosts = [];
    if (!empty($config['DB_HOST'])) {
        $candidateHosts[] = $config['DB_HOST'];
    }
    if (!empty($config['FALLBACK_HOSTS']) && is_array($config['FALLBACK_HOSTS'])) {
        foreach ($config['FALLBACK_HOSTS'] as $fh) {
            if (!empty($fh)) {
                $candidateHosts[] = $fh;
            }
        }
    }
    // Always include canonical hosts
    $candidateHosts[] = 'db.alertaraqc.com';
    $candidateHosts[] = '127.0.0.1';
    $candidateHosts = array_values(array_unique(array_filter($candidateHosts)));

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 4,
    ];

    $lastException = null;
    foreach ($candidateHosts as $host) {
        $dsn = 'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $config['DB_NAME'] . ';charset=utf8mb4';
        try {
            $candidatePdo = new PDO($dsn, $config['DB_USER'], $config['DB_PASS'] ?? '', $options);
            try {
                // Keep NOW(), CURRENT_TIMESTAMP, TIMESTAMP conversion, and API epoch
                // values aligned with the system's authoritative Philippine timezone.
                $candidatePdo->exec("SET time_zone = '+08:00'");
            } catch (Throwable $timezoneError) {
                error_log('Database session timezone setup skipped: ' . $timezoneError->getMessage());
            }

            // Verify if the connected database contains ERS tables.
            // If the database was misconfigured or missing calls table, auto-switch to 'emergency_response_test'.
            try {
                $hasCalls = (bool)$candidatePdo->query("SHOW TABLES LIKE 'calls'")->fetchColumn();
                if (!$hasCalls) {
                    $hasErsDb = (bool)$candidatePdo->query("SHOW DATABASES LIKE 'emergency_response_test'")->fetchColumn();
                    if ($hasErsDb) {
                        $candidatePdo->exec("USE `emergency_response_test`");
                    }
                }
            } catch (Throwable $schemaCheckErr) {
                // Keep candidatePdo if schema check fails
            }

            $pdo = $candidatePdo;
            return $pdo;
        } catch (PDOException $e) {
            $lastException = $e;
            error_log("Database connection attempt to {$host}:{$port} failed: " . $e->getMessage());
        }
    }

    error_log('Database connection failed for all candidate hosts. Last error: ' . ($lastException ? $lastException->getMessage() : 'unknown'));
    return null;
}

function fetch_all_from_table($table) {
    $pdo = get_db_connection();
    if (!$pdo) return [];
    $stmt = $pdo->prepare("SELECT * FROM `" . $table . "`");
    $stmt->execute();
    return $stmt->fetchAll();
}
?>