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
        // Supports APP_ENV, ENVIRONMENT, or ENVIROMENT
        $appEnv = strtolower((string)ers_env('APP_ENV', ers_env('ENVIRONMENT', ers_env('ENVIROMENT', ''))));
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
        if (
            strpos($dir, '/var/www/') !== false ||
            strpos($dir, 'emergency-response.alertaraqc.com') !== false ||
            strpos($dir, '/app') === 0 ||
            file_exists('/.dockerenv')
        ) {
            return true;
        }

        return false;
    }
}

if (!function_exists('ers_host_resolves')) {
    function ers_host_resolves(string $host): bool {
        $host = trim($host);
        if ($host === '' || $host === 'localhost' || $host === '127.0.0.1' || filter_var($host, FILTER_VALIDATE_IP)) {
            return true;
        }
        $ip = @gethostbyname($host);
        return $ip !== $host;
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

// Production-specific credentials
$prodHost = ers_env('PROD_DB_HOST');
$prodName = ers_env('PROD_DB_NAME');
$prodUser = ers_env('PROD_DB_USER');
$prodPass = ers_env('PROD_DB_PASS');
$prodPort = ers_env('PROD_DB_PORT');

// Local-specific credentials
$localHost = ers_env('LOCAL_DB_HOST');
$localName = ers_env('LOCAL_DB_NAME');
$localUser = ers_env('LOCAL_DB_USER');
$localPass = ers_env('LOCAL_DB_PASS');
$localPort = ers_env('LOCAL_DB_PORT');

// Standard fallback credentials
$stdHost  = ers_env('DB_HOST');
$stdName  = ers_env('DB_DATABASE', ers_env('DB_NAME'));
$stdUser  = ers_env('DB_USERNAME', ers_env('DB_USER'));
$stdPass  = ers_env('DB_PASSWORD', ers_env('DB_PASS'));
$stdPort  = ers_env('DB_PORT');

// Resolve effective credentials based on environment
if ($isProd) {
    $effHost = $prodHost !== '' ? $prodHost : ($stdHost !== '' ? $stdHost : 'db.alertaraqc.com');
    $effName = $prodName !== '' ? $prodName : ($stdName !== '' ? $stdName : 'emergency_response_test');
    $effUser = $prodUser !== '' ? $prodUser : ($stdUser !== '' ? $stdUser : 'root');
    $effPass = $prodPass !== '' ? $prodPass : ($stdPass !== '' ? $stdPass : '');
    $effPort = $prodPort !== '' ? $prodPort : ($stdPort !== '' ? $stdPort : '3306');
} else {
    $effHost = $localHost !== '' ? $localHost : ($stdHost !== '' ? $stdHost : 'localhost');
    $effName = $localName !== '' ? $localName : ($stdName !== '' ? $stdName : 'emergency_response_test');
    $effUser = $localUser !== '' ? $localUser : ($stdUser !== '' ? $stdUser : 'root');
    $effPass = $localPass !== '' ? $localPass : ($stdPass !== '' ? $stdPass : '');
    $effPort = $localPort !== '' ? $localPort : ($stdPort !== '' ? $stdPort : '3306');
}

// Ensure database name is never empty or incorrectly 'LGU'
if ($effName === '' || strcasecmp($effName, 'LGU') === 0) {
    $effName = 'emergency_response_test';
}

// Build candidate hosts in intelligent priority
$candidateHosts = [];
if ($isProd) {
    if ($prodHost !== '') $candidateHosts[] = $prodHost;
    if ($stdHost !== '') $candidateHosts[] = $stdHost;
    $candidateHosts[] = 'db.alertaraqc.com';
    $candidateHosts[] = '127.0.0.1';
} else {
    if ($localHost !== '') $candidateHosts[] = $localHost;
    if ($stdHost !== '') $candidateHosts[] = $stdHost;
    $candidateHosts[] = '127.0.0.1';
    $candidateHosts[] = 'localhost';
    if ($prodHost !== '') $candidateHosts[] = $prodHost;
    $candidateHosts[] = 'db.alertaraqc.com';
}
$candidateHosts = array_values(array_unique(array_filter($candidateHosts)));

$primaryHost = $candidateHosts[0] ?? ($isProd ? 'db.alertaraqc.com' : 'localhost');
$fallbackHosts = array_slice($candidateHosts, 1);

return [
    'DB_HOST' => $primaryHost,
    'FALLBACK_HOSTS' => $fallbackHosts,
    'DB_PORT' => $effPort !== '' ? $effPort : '3306',
    'DB_NAME' => $effName,
    'DB_USER' => $effUser !== '' ? $effUser : 'root',
    'DB_PASS' => $effPass,
    'PROD_PASS' => $prodPass !== '' ? $prodPass : ($stdPass !== '' ? $stdPass : ''),
    'LOCAL_PASS' => $localPass !== '' ? $localPass : ($stdPass !== '' ? $stdPass : ''),
    'IS_PROD' => $isProd,
];
