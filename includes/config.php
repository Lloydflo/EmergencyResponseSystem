<?php
// Helper function to get environment variables with defaults
if (!function_exists('ers_env')) {
    function ers_env($key, $default = null) {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }
}

// Gemini AI API Configuration
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', 'AIzaSyA0LID-8uE2NUmezZhK4s8BkIfVTfHeJIk');

// Other configuration settings can go here
if (!defined('GEMINI_API_URL')) define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent');

// Database/environment config
/**
 * Shared application configuration.
 * Loads optional .env values before defining runtime constants.
 */
if (!function_exists('ers_load_env_file')) {
    function ers_load_env_file($path) {
        if (!is_string($path) || $path === '' || !file_exists($path)) {
            return;
        }
        $lines = @file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
                continue;
            }
            list($name, $value) = explode('=', $line, 2);
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
        }
    }
}

if (!function_exists('ers_env')) {
    function ers_env($key, $default = null) {
        $value = $_ENV[$key] ?? getenv($key) ?? $_SERVER[$key] ?? $default;
        if (!is_string($value)) {
            return $value;
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
        return trim($value);
    }
}

// Try multiple .env locations because some deployments place env files in different paths
$ersEnvPaths = [
    dirname(__DIR__) . '/.env',
    __DIR__ . '/.env',
    __DIR__ . '/../.env',
    dirname(__DIR__, 2) . '/.env',
];
foreach ($ersEnvPaths as $ersEnvPath) {
    ers_load_env_file($ersEnvPath);
}

if (!defined('GEMINI_API_KEY')) {
    $resolvedGeminiKey = (string) ers_env('GEMINI_API_KEY', ers_env('GOOGLE_API_KEY', ''));
    define('GEMINI_API_KEY', trim($resolvedGeminiKey));
}

if (!defined('GEMINI_API_URL')) {
    define(
        'GEMINI_API_URL',
        (string) ers_env(
            'GEMINI_API_URL',
            'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent'
        )
    );
}

return require __DIR__ . '/db.config.php';