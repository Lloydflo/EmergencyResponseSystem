<?php
// Gemini AI API Configuration
if (!defined('GEMINI_API_KEY')) define('GEMINI_API_KEY', 'AIzaSyA0LID-8uE2NUmezZhK4s8BkIfVTfHeJIk');

// Other configuration settings can go here
if (!defined('GEMINI_API_URL')) define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent');

// Load .env from project root if present (useful in production where getenv may be disabled)
$rootDir = dirname(__DIR__);
$envFile = $rootDir . DIRECTORY_SEPARATOR . '.env';
if (is_file($envFile) && is_readable($envFile)) {
	$lines = @file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
	if (is_array($lines)) {
		foreach ($lines as $line) {
			$trimmed = ltrim($line);
			if ($trimmed === '' || $trimmed[0] === '#') { continue; }
			$parts = explode('=', $line, 2);
			if (count($parts) === 2) {
				$key = trim($parts[0]);
				$value = trim($parts[1]);
				// Strip quotes if present
				if (($value !== '' && $value[0] === '"' && substr($value, -1) === '"') || ($value !== '' && $value[0] === '\'' && substr($value, -1) === '\'')) {
					$value = substr($value, 1, -1);
				}
				if ($key !== '') {
					$_ENV[$key] = $value;
					$_SERVER[$key] = $value;
					@putenv($key . '=' . $value);
				}
			}
		}
	}
}

// Helpers to read environment values robustly
$__env_raw = function (string $key) {
	$val = getenv($key);
	if ($val !== false) { return $val; }
	if (array_key_exists($key, $_ENV)) { return $_ENV[$key]; }
	if (array_key_exists($key, $_SERVER)) { return $_SERVER[$key]; }
	return null;
};

$__env_with_default = function (string $key, $default, bool $allowEmpty = false) use ($__env_raw) {
	$val = $__env_raw($key);
	if ($val === null) { return $default; }
	if (!$allowEmpty && $val === '') { return $default; }
	return $val;
};

// Database configuration (supports environment overrides)
// Set via environment variables or .env file on the server for production:
// DB_HOST, DB_PORT (optional), DB_NAME, DB_USER, DB_PASS, DB_CHARSET (optional)
if (!defined('DB_HOST')) define('DB_HOST', $__env_with_default('DB_HOST', 'localhost'));
if (!defined('DB_PORT')) define('DB_PORT', $__env_with_default('DB_PORT', 3306));
if (!defined('DB_NAME')) define('DB_NAME', $__env_with_default('DB_NAME', 'ers_db'));
if (!defined('DB_USER')) define('DB_USER', $__env_with_default('DB_USER', 'root'));
// Allow empty password if explicitly provided (e.g., local dev)
if (!defined('DB_PASS')) define('DB_PASS', $__env_with_default('DB_PASS', '', true));
if (!defined('DB_CHARSET')) define('DB_CHARSET', $__env_with_default('DB_CHARSET', 'utf8mb4'));
?>
