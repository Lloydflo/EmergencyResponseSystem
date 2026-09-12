<?php
/**
 * Emergency Response System - Configuration Loader
 * 
 * Automatically loads .env files and resolves database credentials
 * for both local development and production environments.
 */

if (!function_exists('ers_load_env_file')) {
    function ers_load_env_file(string $path): void {
        if ($path === '' || !file_exists($path) || !is_readable($path)) {
            return;
        }

        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, ';') === 0 || strpos($line, '=') === false) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            if ($name === '') {
                continue;
            }

            $value = trim($value);
            $len = strlen($value);
            if ($len >= 2) {
                $first = $value[0];
                $last = $value[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            if (!array_key_exists($name, $_ENV)) {
                $_ENV[$name] = $value;
            }
            if (!array_key_exists($name, $_SERVER)) {
                $_SERVER[$name] = $value;
            }
            if (function_exists('putenv')) {
                putenv("{$name}={$value}");
            }
        }
    }
}

// Search and load .env from standard application paths
$ersEnvPaths = [
    dirname(__DIR__) . '/.env',
    __DIR__ . '/.env',
    __DIR__ . '/../.env',
    dirname(__DIR__, 2) . '/.env',
];

foreach ($ersEnvPaths as $envPath) {
    if (file_exists($envPath)) {
        ers_load_env_file($envPath);
        break;
    }
}

if (!function_exists('ers_env')) {
    function ers_env(string $key, $default = '') {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? (function_exists('getenv') ? getenv($key) : false);
        if ($value === false || $value === null || $value === '') {
            return $default;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            $len = strlen($trimmed);
            if ($len >= 2) {
                $first = $trimmed[0];
                $last = $trimmed[$len - 1];
                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $trimmed = substr($trimmed, 1, -1);
                }
            }
            return $trimmed;
        }
        return $value;
    }
}

if (!function_exists('ers_is_production')) {
    function ers_is_production(): bool {
        $appEnv = strtolower((string)ers_env('APP_ENV', ers_env('ENVIRONMENT', '')));
        if (in_array($appEnv, ['prod', 'production', 'live'], true)) {
            return true;
        }
        if (in_array($appEnv, ['local', 'dev', 'development', 'staging', 'test'], true)) {
            return false;
        }

        $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? ''));
        if (strpos($host, 'alertaraqc.com') !== false) {
            return true;
        }

        $dir = str_replace('\\', '/', __DIR__);
        if (strpos($dir, '/var/www/') !== false || strpos($dir, 'emergency-response.alertaraqc.com') !== false) {
            return true;
        }

        return false;
    }
}

// Gemini AI fallback configuration
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', (string) ers_env('GEMINI_API_KEY', ers_env('GOOGLE_API_KEY', '')));
}

if (!defined('GEMINI_API_URL')) {
    define(
        'GEMINI_API_URL',
        (string) ers_env(
            'GEMINI_API_URL',
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent'
        )
    );
}

// Resolve Database Configuration
$isProd = ers_is_production();

if ($isProd) {
    // In production, prioritize PROD_DB_* credentials, fall back to DB_*
    $dbHost = ers_env('PROD_DB_HOST', ers_env('DB_HOST', '127.0.0.1'));
    $dbPort = ers_env('PROD_DB_PORT', ers_env('DB_PORT', '3306'));
    $dbName = ers_env('PROD_DB_NAME', ers_env('DB_DATABASE', ers_env('DB_NAME', 'LGU')));
    $dbUser = ers_env('PROD_DB_USER', ers_env('DB_USERNAME', ers_env('DB_USER', 'root')));
    $dbPass = ers_env('PROD_DB_PASS', ers_env('DB_PASSWORD', ers_env('DB_PASS', '')));
} else {
    // In local / development, prioritize DB_* credentials, fall back to PROD_DB_*
    $dbHost = ers_env('DB_HOST', ers_env('PROD_DB_HOST', '127.0.0.1'));
    $dbPort = ers_env('DB_PORT', ers_env('PROD_DB_PORT', '3306'));
    $dbName = ers_env('DB_DATABASE', ers_env('DB_NAME', ers_env('PROD_DB_NAME', 'LGU')));
    $dbUser = ers_env('DB_USERNAME', ers_env('DB_USER', ers_env('PROD_DB_USER', 'root')));
    $dbPass = ers_env('DB_PASSWORD', ers_env('DB_PASS', ers_env('PROD_DB_PASS', '')));
}

return [
    'DB_HOST' => $dbHost,
    'DB_PORT' => $dbPort !== '' ? $dbPort : '3306',
    'DB_NAME' => $dbName,
    'DB_USER' => $dbUser,
    'DB_PASS' => $dbPass,
    'IS_PROD' => $isProd,
];
