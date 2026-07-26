<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin_api_auth.php';
require_admin_api_access(true);
require_once __DIR__ . '/../includes/db.php';

header('Content-Type: application/json');

$pdo = get_db_connection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'DB connection unavailable']);
    exit;
}

function ers_settings_defaults(): array
{
    return [
        'systemName' => 'Emergency Response System',
        'dispatchCenter' => 'LGU #4 Command Center',
        'timezone' => 'Asia/Manila',
        'defaultLanguage' => 'en',
        'themeMode' => 'system',
        'maintenanceMessage' => 'System is available and running normally.',
        'maintenanceMode' => false,
    ];
}

function ers_settings_ensure_table(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `system_settings` (
            `setting_key` VARCHAR(120) NOT NULL,
            `setting_value` LONGTEXT DEFAULT NULL,
            `updated_by` INT UNSIGNED DEFAULT NULL,
            `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
}

function ers_settings_clean_payload(array $input): array
{
    $defaults = ers_settings_defaults();
    $payload = [];
    foreach ($defaults as $key => $default) {
        if ($key === 'maintenanceMode') {
            $value = $input[$key] ?? false;
            $payload[$key] = is_bool($value)
                ? $value
                : in_array(strtolower(trim((string)$value)), ['1', 'true', 'yes', 'on'], true);
            continue;
        }

        $value = trim((string)($input[$key] ?? $default));
        if ($key === 'systemName' || $key === 'dispatchCenter') {
            $value = substr($value !== '' ? $value : (string)$default, 0, 160);
        } elseif ($key === 'maintenanceMessage') {
            $value = substr($value !== '' ? $value : (string)$default, 0, 1000);
        } elseif ($key === 'timezone') {
            $allowed = ['Asia/Manila', 'UTC', 'America/New_York'];
            $value = in_array($value, $allowed, true) ? $value : (string)$default;
        } elseif ($key === 'defaultLanguage') {
            $allowed = ['en', 'fil', 'en-fil'];
            $value = in_array($value, $allowed, true) ? $value : (string)$default;
        } elseif ($key === 'themeMode') {
            $allowed = ['light', 'dark', 'system'];
            $value = in_array($value, $allowed, true) ? $value : (string)$default;
        }
        $payload[$key] = $value;
    }
    return $payload;
}

try {
    ers_settings_ensure_table($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $settings = ers_settings_defaults();
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $key = (string)($row['setting_key'] ?? '');
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            $value = (string)($row['setting_value'] ?? '');
            $settings[$key] = $key === 'maintenanceMode'
                ? in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true)
                : $value;
        }
        echo json_encode(['ok' => true, 'settings' => $settings]);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
        exit;
    }

    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    $payload = ers_settings_clean_payload($input);
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $stmt = $pdo->prepare(
        "INSERT INTO system_settings (setting_key, setting_value, updated_by, updated_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
            setting_value = VALUES(setting_value),
            updated_by = VALUES(updated_by),
            updated_at = NOW()"
    );
    foreach ($payload as $key => $value) {
        $stmt->execute([$key, $key === 'maintenanceMode' ? ($value ? '1' : '0') : (string)$value, $userId > 0 ? $userId : null]);
    }

    echo json_encode(['ok' => true, 'settings' => $payload]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Unable to save settings']);
}
